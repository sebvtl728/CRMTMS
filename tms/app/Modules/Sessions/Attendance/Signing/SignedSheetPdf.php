<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Modules\Core\Companies\Brand;
use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Documents\PdfRenderer;
use App\Modules\Core\Documents\PdfRenderingFailed;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\AttendanceClosure;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Planning\PlanningRows;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\SeanceRows;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * The signed attendance sheet of a session for a Paris month (spec § 6.5):
 * per day with live séances, the half-days, their modules and trainers, a
 * line per trainee expected with the drawing and hour of each half-day
 * signed (« Absent », « Non validé », or « Émargement papier ou saisie
 * manuelle » without online signing), the final attendance of the grid, the
 * validator's signature; the SHA-256 of every drawing in an annex. Rendered
 * by Gotenberg, archived (never overwritten) and sealed with its SHA-256.
 */
final class SignedSheetPdf
{
    public function __construct(private readonly PdfRenderer $renderer, private readonly CompanyBranding $branding) {}

    /**
     * @throws PdfRenderingFailed
     */
    public function generate(TrainingSession $session, CarbonImmutable $first, User $by): SignedSheet
    {
        $bytes = $this->renderer->fromHtml($this->html($session, $first));
        $path = TenantStorage::path('attendance_sheets', $session->id.'/signed-'.$first->format('Y-m').'-'.CarbonImmutable::now()->format('YmdHisu').'.pdf');
        Storage::disk(TenantStorage::DISK)->put($path, $bytes);
        try {
            return SignedSheet::query()->create([
                'training_session_id' => $session->id, 'month' => $first->toDateString(), 'path' => $path,
                'pdf_sha256' => hash('sha256', $bytes), 'generated_by' => $by->id,
            ]);
        } catch (\Throwable $e) {
            Storage::disk(TenantStorage::DISK)->delete($path);

            throw $e;
        }
    }

    public function html(TrainingSession $session, CarbonImmutable $first): string
    {
        $last = $first->endOfMonth();
        $seances = Seance::query()->where('training_session_id', $session->id)->where('status', Seance::PLANNED)
            ->whereBetween('date', [$first->toDateString(), $last->toDateString()])->orderBy('date')->orderBy('starts_at')->orderBy('id')->get();
        $slots = SignSlot::query()->where('training_session_id', $session->id)
            ->whereBetween('date', [$first->toDateString(), $last->toDateString()])->get()
            ->keyBy(fn (SignSlot $s) => $s->date->toDateString().'|'.$s->half);
        $signatures = AttendanceSignature::query()->whereIn('slot_id', $slots->pluck('id')->all())->get()
            ->keyBy(fn (AttendanceSignature $s) => $s->slot_id.'|'.$s->enrolment_id);
        $attendance = Attendance::query()->whereIn('seance_id', $seances->pluck('id')->all())->get()
            ->groupBy(fn (Attendance $a) => $a->enrolment_id);
        $modules = SessionModule::withTrashed()->where('training_session_id', $session->id)->pluck('title', 'id');
        $trainers = SeanceRows::trainerLabels($seances->pluck('trainer_id')->filter()->unique()->values()->all());
        $enrolments = Enrolment::query()->where('training_session_id', $session->id)->get();
        $people = app(Fiches::class)->personLabels($enrolments->pluck('person_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
        $names = $enrolments->mapWithKeys(fn (Enrolment $e) => [$e->id => $people[$e->person_id]['label'] ?? 'Stagiaire']);
        $disk = Storage::disk(TenantStorage::DISK);
        $image = fn (?string $path) => $path !== null && $disk->exists($path) ? 'data:image/png;base64,'.base64_encode((string) $disk->get($path)) : null;
        $hour = fn ($at) => $at === null ? null : CarbonImmutable::parse($at)->setTimezone('Europe/Paris')->format('H:i');
        $annex = [];
        $reviewers = User::query()->whereKey($signatures->pluck('confirmed_by')->filter()->unique()->values()->all())->pluck('name', 'id');
        $catchUps = false;

        $days = [];
        foreach ($seances->groupBy(fn (Seance $s) => $s->date->toDateString()) as $date => $daySeances) {
            $halves = [];
            foreach (SignSlot::HALVES as $half) {
                $of = $daySeances->filter(fn (Seance $s) => in_array($half, Halves::of($s->start(), $s->end()), true));
                if ($of->isEmpty()) {
                    continue;
                }
                $slot = $slots[$date.'|'.$half] ?? null;
                $halves[$half] = [
                    'label' => SignSlot::label($half),
                    'slots' => $of->map(fn (Seance $s) => $s->start().' – '.$s->end().' : '.($modules[$s->session_module_id] ?? '-')
                        .($s->trainer_id !== null && isset($trainers[$s->trainer_id]) ? ' ('.$trainers[$s->trainer_id].')' : ''))->values()->all(),
                    'slot' => $slot,
                    'state' => $slot === null ? 'none' : ($slot->isValidated() ? 'validated' : 'pending'),
                    'validated_by' => $slot?->trainer_name,
                    'validator_role' => $slot?->validator_role === SignSlot::ROLE_TRAINER ? 'formateur' : 'gestionnaire des présences',
                    'validated_at' => $slot?->validated_at?->setTimezone('Europe/Paris')->format('d/m/Y à H:i'),
                    'validator_signature' => $image($slot?->trainer_signature_path),
                ];
            }
            $rows = [];
            // Every trainee expected at one séance of the day (an individual planning may leave him out of some).
            $dayTrainees = $daySeances->flatMap(fn ($seance) => Attendances::expected($seance)->get())->unique('id')->sortBy('id')->values();
            foreach ($dayTrainees as $e) {
                $cells = [];
                foreach ($halves as $half => $h) {
                    $sig = $h['slot'] === null ? null : ($signatures[$h['slot']->id.'|'.$e->id] ?? null);
                    $catchUp = $sig !== null && $sig->kind !== AttendanceSignature::KIND_ON_TIME;
                    $catchUps = $catchUps || $catchUp;
                    if ($catchUp && $sig->refused_at !== null) {
                        $sig = null;
                        $cells[$half] = ['state' => 'catch_up_refused', 'image' => null, 'hour' => null, 'source' => null, 'mention' => 'Rattrapage refusé'];

                        continue;
                    }
                    if ($sig?->drawing_sha256 !== null) {
                        $annex[] = ['name' => $names[$e->id] ?? 'Stagiaire', 'when' => $date.' '.$h['label'], 'sha256' => $sig->drawing_sha256];
                    }
                    $cells[$half] = [
                        'state' => match (true) {
                            $sig !== null => 'signed',
                            $h['state'] === 'none' => 'paper',
                            $h['state'] === 'pending' => 'pending',
                            default => 'absent',
                        },
                        'image' => $image($sig?->drawing_path),
                        'hour' => $hour($sig?->signed_at),
                        'source' => $sig?->source,
                        'mention' => ! $catchUp ? null : ($sig->confirmed_at === null ? 'Rattrapage à confirmer'
                            : 'Signé en rattrapage le '.$sig->signed_at->setTimezone('Europe/Paris')->format('d/m/Y à H:i')
                                .($sig->kind === AttendanceSignature::KIND_ONSITE ? ' (sur place)' : ' (à distance, code e-mail)')
                                .', confirmé par '.($reviewers[$sig->confirmed_by] ?? '-').' le '.$sig->confirmed_at->setTimezone('Europe/Paris')->format('d/m/Y')),
                    ];
                }
                $mine = ($attendance[$e->id] ?? collect())->whereIn('seance_id', $daySeances->pluck('id')->all());
                $rows[] = [
                    'name' => $names[$e->id] ?? 'Stagiaire',
                    'cells' => $cells,
                    'final' => $mine->isEmpty() ? 'Non saisie'
                        : ucfirst(implode(', ', $mine->map(fn (Attendance $a) => Attendance::label($a->status))->unique()->all())).' – '.SessionModules::label((int) $mine->sum('minutes')),
                ];
            }
            usort($rows, fn (array $a, array $b) => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));
            $days[] = [
                'label' => ucfirst(CarbonImmutable::parse($date)->locale('fr')->isoFormat('dddd D MMMM YYYY')),
                'halves' => $halves,
                'rows' => $rows,
            ];
        }
        $closure = AttendanceClosure::query()->where('training_session_id', $session->id)->where('month', $first->toDateString())->first();
        $company = Brand::company();

        return view('documents.attendance-signed-sheet', [
            'company' => [
                'name' => app(Brand::class)->current()['name'],
                'logo' => $company === null ? null : $this->branding->logoDataUri($company),
            ],
            'session' => PlanningRows::sessionLabels([$session])[$session->id] ?? $session->number,
            'month' => ucfirst($first->locale('fr')->isoFormat('MMMM YYYY')),
            'closed' => $closure !== null && $closure->isClosed() ? 'Mois clôturé le '.$closure->closed_at?->setTimezone('Europe/Paris')->format('d/m/Y') : 'Mois non clôturé',
            'days' => $days,
            'annex' => $annex,
            'catch_ups' => $catchUps,
            'generated' => CarbonImmutable::now('Europe/Paris')->format('d/m/Y à H:i'),
        ])->render();
    }
}
