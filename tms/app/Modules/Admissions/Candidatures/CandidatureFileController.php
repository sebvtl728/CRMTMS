<?php

namespace App\Modules\Admissions\Candidatures;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files of a candidature: upload and delete (update right on it), download
 * (seen with it, never cached). A file of another candidature is a 404.
 */
class CandidatureFileController extends Controller
{
    public function store(Request $request, Candidature $candidature, CandidatureFiles $files): RedirectResponse
    {
        $user = CandidatureController::user($request);
        CandidatureController::authorizeOn($user, Permissions::CANDIDATURES_UPDATE, $candidature);
        $upload = $request->file('file');
        if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
            throw ValidationException::withMessages(['file' => 'Choisissez un fichier (20 Mo au plus).']);
        }
        $files->store($candidature, (string) $request->input('kind', ''), $upload, $user);

        return back()->with('status', 'Fichier ajouté.');
    }

    public function show(Candidature $candidature, string $file): StreamedResponse
    {
        $stored = self::find($candidature, $file);
        $disk = Storage::disk(TenantStorage::DISK);
        $extension = pathinfo($stored->path, PATHINFO_EXTENSION);
        $mime = CandidatureFiles::SERVED[$extension] ?? null;
        abort_if($mime === null || ! CandidatureFiles::isOwnPath($candidature, $stored->path) || ! $disk->exists($stored->path), 404);

        return response()->stream(function () use ($disk, $stored): void {
            $stream = $disk->readStream($stored->path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $disk->size($stored->path),
            // Named after the candidature, not after the uploaded file (personal).
            'Content-Disposition' => 'inline; filename="'.$candidature->number.'-'.str_replace(':', '-', explode(':', $stored->kind)[0]).'.'.$extension.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            ...($mime === 'application/pdf' ? [] : ['Content-Security-Policy' => 'sandbox']),
        ]);
    }

    public function destroy(Request $request, Candidature $candidature, string $file, CandidatureFiles $files): RedirectResponse
    {
        CandidatureController::authorizeOn(CandidatureController::user($request), Permissions::CANDIDATURES_UPDATE, $candidature);
        $files->delete(self::find($candidature, $file));

        return back()->with('status', 'Fichier supprimé.');
    }

    private static function find(Candidature $candidature, string $id): CandidatureFile
    {
        $file = Str::isUuid($id) ? CandidatureFile::query()->where('candidature_id', $candidature->id)->find($id) : null;
        abort_if($file === null, 404);

        return $file;
    }
}
