<?php

namespace App\Modules\Sessions\Attendance;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Companies\Brand;
use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Documents\PdfRenderer;
use App\Modules\Core\Documents\PdfRenderingFailed;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Planning\PlanningRows;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\SeanceRows;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Support\Collection;

/**
 * The printable attendance sheet (spec § 4.8) of one séance, or of every
 * séance of a session on a day: organisme, session, modules, date, slots,
 * trainers, one line per trainee expected that day (the rule of
 * Attendances::expected: late entries and abandons respected) with a box to
 * sign in the morning and in the afternoon, and a box for each trainer.
 * Rendered on the fly (no archive) from the Blade template
 * documents/attendance-sheet: every typed text escaped, no external
 * resource; converted by PdfRenderer.
 */
final class AttendanceSheetPdf
{
    public const CANCELLED = 'Cette séance est annulée : pas de feuille d\'émargement.';

    public const NOTHING = 'Aucune séance à émarger ce jour.';

    public function __construct(private readonly PdfRenderer $renderer, private readonly CompanyBranding $branding) {}

    /**
     * @param  Collection<int, Seance>  $seances  the séances of the sheet: one session, one day
     *
     * @throws PdfRenderingFailed
     * @throws UserFacingHttpException
     */
    public function render(Collection $seances): string
    {
        return $this->renderer->fromHtml($this->html($seances));
    }

    /**
     * @param  Collection<int, Seance>  $seances
     */
    public function html(Collection $seances): string
    {
        $live = $seances->reject(fn (Seance $s) => $s->isCancelled())->sortBy(fn (Seance $s) => $s->starts_at.$s->id)->values();
        if ($live->isEmpty()) {
            throw new UserFacingHttpException(409, $seances->isEmpty() ? self::NOTHING : self::CANCELLED);
        }
        $first = $live->first();
        $session = TrainingSession::withTrashed()->findOrFail($first->training_session_id);
        $labels = PlanningRows::sessionLabels([$session]);
        $modules = SessionModule::withTrashed()->whereKey($live->pluck('session_module_id')->all())->pluck('title', 'id');
        $trainers = SeanceRows::trainerLabels($live->pluck('trainer_id')->filter()->unique()->values()->all());

        $enrolments = Attendances::expected($first)->get();
        $people = app(Fiches::class)->personLabels($enrolments->pluck('person_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
        $trainees = $enrolments->map(fn (Enrolment $e) => $people[$e->person_id]['label'] ?? 'Stagiaire')
            ->sortBy(fn (string $name) => mb_strtolower($name))->values()->all();

        $company = Brand::company();

        return view('documents.attendance-sheet', [
            'company' => [
                'name' => app(Brand::class)->current()['name'],
                'logo' => $company === null ? null : $this->branding->logoDataUri($company),
            ],
            'session' => $labels[$session->id] ?? $session->number,
            'date' => $first->date->format('d/m/Y'),
            'single' => $live->count() === 1,
            'slots' => $live->map(fn (Seance $s) => [
                'start' => $s->start(),
                'end' => $s->end(),
                'module' => $modules[$s->session_module_id] ?? '-',
                'trainer' => $s->trainer_id === null ? null : ($trainers[$s->trainer_id] ?? null),
            ])->all(),
            'trainers' => array_values(array_unique(array_filter($live->map(fn (Seance $s) => $s->trainer_id === null ? null : ($trainers[$s->trainer_id] ?? null))->all()))),
            'morning' => $live->contains(fn (Seance $s) => $s->start() < '13:00'),
            'afternoon' => $live->contains(fn (Seance $s) => $s->end() > '13:00'),
            'trainees' => $trainees,
        ])->render();
    }

    public static function fileName(Seance $seance, bool $day): string
    {
        return 'emargement-'.$seance->date->toDateString().($day ? '' : '-'.substr($seance->start(), 0, 2).'h'.substr($seance->start(), 3, 2)).'.pdf';
    }
}
