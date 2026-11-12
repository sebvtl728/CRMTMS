<?php

namespace App\Modules\Crm\Timeline;

use App\Http\Controllers\Controller;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files attached to fiches. Bytes sit on the private disk under
 * tenants/<tenant_id>/attachments/<uuid> and are only reachable through the
 * download action (policy checked, forced download, no sniffing).
 */
class AttachmentController extends Controller
{
    public function storeForOrganisation(AttachmentRequest $request, Organisation $organisation): RedirectResponse
    {
        return $this->store($request, $organisation);
    }

    public function storeForPerson(AttachmentRequest $request, Person $person): RedirectResponse
    {
        return $this->store($request, $person);
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        $subject = $attachment->notable;
        abort_unless($subject instanceof Model, 404);
        Gate::authorize('view', $subject);

        $disk = Storage::disk(TenantStorage::DISK);
        abort_unless($disk->exists($attachment->path), 404);

        $name = $attachment->original_name;
        $fallback = Str::of(Str::ascii($name))->replaceMatches('/[^A-Za-z0-9._ -]/', '_')->toString();

        return $disk->response($attachment->path, $name, [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => 'attachment; filename="'.$fallback."\"; filename*=UTF-8''".rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The row goes to the trash; the file stays on disk until the GDPR purge.
     */
    public function destroy(Attachment $attachment): RedirectResponse
    {
        $subject = $attachment->notable;
        abort_unless($subject instanceof Model, 404);
        Gate::authorize('update', $subject);

        $attachment->delete();

        return back()->with('status', 'Fichier retiré.');
    }

    private function store(AttachmentRequest $request, Model $subject): RedirectResponse
    {
        Gate::authorize('update', $subject);
        abort_if(method_exists($subject, 'trashed') && $subject->trashed(), 404);

        $file = $request->file('file');
        assert($file instanceof UploadedFile);

        $mime = AttachmentRequest::detectedMime($file);
        abort_if($mime === null, 422);

        $directory = TenantStorage::path('attachments');
        $stored = Str::uuid()->toString();
        $path = TenantStorage::path('attachments', $stored);
        Storage::disk(TenantStorage::DISK)->putFileAs($directory, $file, $stored);

        try {
            $attachment = new Attachment([
                'original_name' => $this->cleanName($file->getClientOriginalName()),
                'mime' => $mime,
                'size' => $file->getSize(),
                'path' => $path,
            ]);
            $attachment->notable()->associate($subject);
            $attachment->save();
        } catch (\Throwable $e) {
            Storage::disk(TenantStorage::DISK)->delete($path);

            throw $e;
        }

        return back()->with('status', 'Fichier ajouté.');
    }

    /**
     * The name as shown and downloaded: no path, no control characters.
     */
    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name);

        if (mb_strlen($name) > 200) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 190).($extension === '' ? '' : '.'.$extension);
        }

        return $name === '' ? 'fichier' : $name;
    }
}
