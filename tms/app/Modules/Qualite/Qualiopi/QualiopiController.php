<?php

namespace App\Modules\Qualite\Qualiopi;

use App\Http\Controllers\Controller;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Qualite\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Qualité › Qualiopi: the 33 indicators by criterion, and one indicator. */
class QualiopiController extends Controller
{
    public function index(Request $request): Response
    {
        $states = QualiopiFile::states();
        $criteria = [];
        foreach (QualiopiReferential::CRITERIA as $n => $title) {
            $criteria[] = [
                'number' => $n,
                'title' => $title,
                'indicators' => array_values(array_map(fn (array $i) => self::row($i, $states[$i['number']] ?? null),
                    array_filter(QualiopiReferential::indicators(), fn (array $i) => $i['criterion'] === $n))),
            ];
        }

        return Inertia::render('qualite/qualiopi/index', [
            'criteria' => $criteria,
            'progress' => QualiopiFile::progress(),
            'decree' => QualiopiReferential::DECREE,
            'verify_notice' => QualiopiReferential::VERIFY_NOTICE,
            'statuses' => QualityIndicator::STATUSES,
            'can' => ['manage' => self::user($request)->can(Permissions::MANAGE)],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function show(Request $request, int $number): Response
    {
        $indicator = QualiopiReferential::indicator($number) ?? abort(404);
        $state = QualiopiFile::states()[$number] ?? null;

        return Inertia::render('qualite/qualiopi/show', [
            'indicator' => self::row($indicator, $state),
            'criterion' => ['number' => $indicator['criterion'], 'title' => QualiopiReferential::CRITERIA[$indicator['criterion']]],
            'proofs' => QualityProof::query()->where('referential_version', QualiopiReferential::VERSION)->where('number', $number)
                ->orderBy('created_at')->orderBy('id')->get()->map(fn (QualityProof $p) => [
                    'id' => $p->id, 'kind' => $p->kind, 'title' => $p->title, 'url' => $p->url, 'note' => $p->note,
                    'original_name' => $p->original_name, 'size' => $p->size, 'created_at' => $p->created_at?->toIso8601String(),
                ])->values()->all(),
            'users' => User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->values()->all(),
            'statuses' => QualityIndicator::STATUSES,
            'verify_notice' => QualiopiReferential::VERIFY_NOTICE,
            'can' => ['manage' => self::user($request)->can(Permissions::MANAGE)],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function addProof(Request $request, int $number, QualityProofs $proofs): RedirectResponse
    {
        abort_if(QualiopiReferential::indicator($number) === null, 404);
        $proofs->add($number, $request->only(['kind', 'title', 'url', 'note', 'file']));

        return back()->with('status', 'Preuve ajoutée.');
    }

    public function downloadProof(string $proof): StreamedResponse
    {
        $model = self::proof($proof);
        $disk = Storage::disk(TenantStorage::DISK);
        abort_if($model->path === null || ! QualityProofs::isOwnPath($model->path) || ! $disk->exists($model->path), 404);
        $extension = pathinfo($model->path, PATHINFO_EXTENSION);

        return response()->stream(function () use ($disk, $model): void {
            $stream = $disk->readStream((string) $model->path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => QualityProofs::SERVED[$extension] ?? 'application/octet-stream',
            'Content-Disposition' => (isset(QualityProofs::SERVED[$extension]) ? 'inline' : 'attachment').'; filename="preuve.'.$extension.'"; filename*=UTF-8\'\''.rawurlencode((string) $model->original_name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function deleteProof(string $proof, QualityProofs $proofs): RedirectResponse
    {
        $proofs->delete(self::proof($proof));

        return back()->with('status', 'Preuve retirée.');
    }

    private static function proof(string $id): QualityProof
    {
        abort_unless(Str::isUuid($id), 404);

        return QualityProof::query()->findOrFail($id);
    }

    public function update(Request $request, int $number, QualiopiFile $file): RedirectResponse
    {
        abort_if(QualiopiReferential::indicator($number) === null, 404);
        $file->update($number, $request->only(['applicable', 'not_applicable_reason', 'status', 'responsible_user_id', 'review_on', 'notes']), self::user($request));

        return back()->with('status', "Indicateur {$number} enregistré.");
    }

    /**
     * @param  array<string, mixed>  $indicator
     * @return array<string, mixed>
     */
    public static function row(array $indicator, ?QualityIndicator $state): array
    {
        return [
            ...$indicator,
            'applicable' => $state === null || $state->applicable,
            'not_applicable_reason' => $state?->not_applicable_reason,
            'status' => $state->status ?? 'todo',
            'responsible_user_id' => $state?->responsible_user_id,
            'review_on' => $state?->review_on?->toDateString(),
            'notes' => $state?->notes,
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
