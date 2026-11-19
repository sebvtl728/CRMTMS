<?php

namespace App\Modules\Admissions\Positioning;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Catalogue\Brochures;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Public\Fiches;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The candidate's personal link (no account; the host gives the organisme).
 * Unknown, malformed, expired, revoked token, candidature in the trash or
 * out of the path: the same « lien invalide » page (404), nothing tells them
 * apart. The page shows the first name, the training and the tests only.
 */
class PositioningUploadController extends Controller
{
    public function show(Request $request, string $token): SymfonyResponse
    {
        $sending = PositioningUploads::usable($token, allowSubmitted: true);
        if ($sending === null) {
            return Inertia::render('public/positioning-test', ['state' => 'invalid'])->toResponse($request)->setStatusCode(404);
        }
        $candidature = Candidature::query()->findOrFail($sending->candidature_id);
        $person = app(Fiches::class)->personLabels([$candidature->person_id])[$candidature->person_id] ?? null;
        $training = $candidature->training_id !== null ? app(Trainings::class)->find($candidature->training_id) : null;
        $titles = app(Brochures::class)->sent($sending->versionIds());

        return Inertia::render('public/positioning-test', [
            'state' => $sending->submitted_at !== null ? 'submitted' : 'open',
            'organisme' => (string) (app(TenantContext::class)->current()->name ?? ''),
            'first_name' => (string) ($person['first_name'] ?? ''),
            'training' => $training?->title,
            'deadline' => $sending->expires_at->setTimezone('Europe/Paris')->format('d/m/Y'),
            'submitted_on' => $sending->submitted_at?->setTimezone('Europe/Paris')->format('d/m/Y'),
            'tests' => array_map(fn (array $v) => [
                'title' => $titles[$v['version_id']]['title'] ?? $v['original_name'],
                'size' => $v['size'],
                'href' => route('positioning.download', [$token, $v['version_id']]),
            ], app(Brochures::class)->versions($sending->versionIds())),
            'max_files' => PositioningUploads::MAX_FILES,
            'status' => $request->session()->get('status'),
        ])->toResponse($request);
    }

    public function download(string $token, string $version): StreamedResponse
    {
        $sending = PositioningUploads::usable($token, allowSubmitted: true);
        abort_if($sending === null || ! in_array($version, $sending->versionIds(), true), 404);
        $file = app(Brochures::class)->versions([$version])[0] ?? null;
        $disk = Storage::disk(TenantStorage::DISK);
        abort_if($file === null || ! Brochures::isOwnPath($file['path']) || ! $disk->exists($file['path']), 404);

        return response()->stream(function () use ($disk, $file): void {
            $stream = $disk->readStream($file['path']);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="test.pdf"; filename*=UTF-8\'\''.rawurlencode($file['original_name']),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function store(Request $request, string $token, PositioningUploads $uploads): RedirectResponse
    {
        $sending = PositioningUploads::usable($token);
        abort_if($sending === null, 404);
        $uploads->submit($sending, ['files' => $request->file('files', []), 'done' => $request->input('done')]);

        return redirect()->route('positioning.show', $token)->with('status', 'Merci, votre copie a bien été reçue.');
    }
}
