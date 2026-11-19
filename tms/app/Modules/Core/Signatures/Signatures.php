<?php

namespace App\Modules\Core\Signatures;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Documents\PdfRenderer;
use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Signatures\Events\SignatureCompleted;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Online signature of a PDF (simple electronic signature): a personal link
 * (only its SHA-256 is kept, valid 30 days), a 6-digit code sent by e-mail
 * (hashed, 10 minutes, 5 tries, one sending a minute), then the signed PDF =
 * the original (never overwritten) + a proof page, and the event
 * SignatureCompleted after commit for the module owning the subject. The
 * Core knows no subject: an alias and an id, and the PDF to sign.
 */
final class Signatures
{
    public const VALID_DAYS = 30;

    public const CODE_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const MAIL_FAILED = 'L\'e-mail n\'est pas parti : le serveur d\'envoi est injoignable ou a refusé le message.';

    public function __construct(private readonly PdfRenderer $renderer, private readonly MailTemplates $templates) {}

    /**
     * Creates the request (cancelling a pending one of the same subject) and
     * e-mails the link to the signer.
     *
     * @return array{request: SignatureRequest, token: string}
     */
    public function request(string $subjectType, string $subjectId, string $pdfPath, string $pdfSha256, string $signerName, string $signerEmail, ?string $signerPersonId, string $title, User $by): array
    {
        $token = Str::random(40);
        $request = DB::transaction(function () use ($subjectType, $subjectId, $pdfPath, $pdfSha256, $signerName, $signerEmail, $signerPersonId, $title, $by, $token): SignatureRequest {
            self::cancelFor($subjectType, $subjectId);

            return SignatureRequest::query()->create([
                'subject_type' => $subjectType, 'subject_id' => $subjectId, 'title' => mb_substr($title, 0, 200),
                'pdf_path' => $pdfPath, 'pdf_sha256' => $pdfSha256,
                'signer_name' => $signerName, 'signer_email' => $signerEmail, 'signer_person_id' => $signerPersonId,
                'token_hash' => hash('sha256', $token),
                'expires_at' => CarbonImmutable::now('Europe/Paris')->addDays(self::VALID_DAYS)->endOfDay(),
                'status' => 'pending', 'created_by' => $by->id,
            ]);
        });
        $text = $this->templates->render(SignatureMailTemplates::REQUEST, [
            'document' => $request->title, 'destinataire' => $signerName, 'lien' => url('/signature/'.$token),
            'echeance' => $request->expires_at->setTimezone('Europe/Paris')->format('d/m/Y'), 'organisme' => self::organisme(),
        ]);
        $this->mail($signerEmail, new SignatureMail($text['subject'], $text['body']));

        return ['request' => $request, 'token' => $token];
    }

    /** Cancels the pending request of a subject (a new request, a cancelled document, a refused quote…). */
    public static function cancelFor(string $subjectType, string $subjectId): void
    {
        SignatureRequest::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->where('status', 'pending')
            ->get()->each(fn (SignatureRequest $r) => $r->forceFill(['status' => 'cancelled'])->save());
    }

    /** The pending, unexpired request of a link, else null. */
    public static function find(string $token): ?SignatureRequest
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }
        $request = SignatureRequest::query()->where('token_hash', hash('sha256', $token))->first();

        return $request !== null && $request->status === 'pending' && $request->expires_at->isFuture() ? $request : null;
    }

    /** The last request of a subject (any state), for its page. */
    public static function latestFor(string $subjectType, string $subjectId): ?SignatureRequest
    {
        return SignatureRequest::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    public function sendCode(SignatureRequest $request): void
    {
        $code = DB::transaction(function () use ($request): string {
            $locked = SignatureRequest::query()->lockForUpdate()->findOrFail($request->id);
            self::pending($locked);
            if ($locked->code_sent_at !== null && $locked->code_sent_at->addMinute()->isFuture()) {
                throw new UserFacingHttpException(429, 'Patientez une minute avant de demander un nouveau code.');
            }
            $code = sprintf('%06d', random_int(0, 999999));
            $locked->forceFill([
                'code_hash' => self::codeHash($locked, $code),
                'code_expires_at' => CarbonImmutable::now()->addMinutes(self::CODE_MINUTES),
                'code_attempts' => 0,
                'code_sent_at' => CarbonImmutable::now(),
            ])->save();

            return $code;
        });
        $text = $this->templates->render(SignatureMailTemplates::CODE, ['code' => $code, 'document' => $request->title, 'organisme' => self::organisme()]);
        $this->mail((string) $request->signer_email, new SignatureMail($text['subject'], $text['body']));
    }

    /**
     * Checks the code and signs: proof page rendered and merged after the
     * original (checked against its SHA-256: 410 when altered), archived next
     * to it; a Gotenberg failure signs nothing.
     *
     * @throws ValidationException a wrong, expired or exhausted code
     */
    public function sign(SignatureRequest $request, string $code, string $ip, string $agent): SignatureRequest
    {
        $signed = DB::transaction(function () use ($request, $code, $ip, $agent): SignatureRequest {
            $locked = SignatureRequest::query()->lockForUpdate()->findOrFail($request->id);
            self::pending($locked);
            if ($locked->code_hash === null) {
                throw ValidationException::withMessages(['code' => 'Demandez d\'abord votre code.']);
            }
            if ($locked->code_attempts >= self::MAX_ATTEMPTS) {
                throw ValidationException::withMessages(['code' => 'Trop d\'essais : demandez un nouveau code.']);
            }
            if ($locked->code_expires_at === null || ! $locked->code_expires_at->isFuture()) {
                throw ValidationException::withMessages(['code' => 'Ce code a expiré : demandez-en un nouveau.']);
            }
            if (! hash_equals((string) $locked->code_hash, self::codeHash($locked, trim($code)))) {
                $locked->forceFill(['code_attempts' => $locked->code_attempts + 1])->save();

                // Committed (the attempt counts), then refused below.
                return $locked;
            }

            $disk = Storage::disk(TenantStorage::DISK);
            $original = $disk->exists($locked->pdf_path) ? (string) $disk->get($locked->pdf_path) : null;
            if ($original === null || ! hash_equals($locked->pdf_sha256, hash('sha256', $original))) {
                throw new UserFacingHttpException(410, 'Le document à signer est introuvable ou a été modifié : il ne peut plus être signé.');
            }
            $now = CarbonImmutable::now();
            $proof = $this->renderer->fromHtml(view('documents.signature-proof', [
                'title' => $locked->title,
                'signer' => (string) $locked->signer_name,
                'email' => (string) $locked->signer_email,
                'signed_at' => $now->setTimezone('Europe/Paris')->format('d/m/Y à H:i:s'),
                'ip' => $ip,
                'agent' => mb_substr($agent, 0, 300),
                'sha256' => $locked->pdf_sha256,
                'request' => $locked->id,
                'organisme' => self::organisme(),
            ])->render());
            $content = $this->renderer->merge([$original, $proof]);
            $path = dirname($locked->pdf_path).'/'.Str::uuid7().'-signe.pdf';
            $disk->put($path, $content);
            try {
                $locked->forceFill([
                    'status' => 'signed', 'signed_at' => $now, 'signed_ip' => $ip, 'signed_user_agent' => mb_substr($agent, 0, 300),
                    'signed_pdf_path' => $path, 'signed_pdf_sha256' => hash('sha256', $content), 'code_hash' => null,
                ])->save();
            } catch (Throwable $e) {
                $disk->delete($path);

                throw $e;
            }
            SignatureCompleted::dispatch($locked->subject_type, $locked->subject_id, $locked->id, $now);

            return $locked;
        });

        if ($signed->status !== 'signed') {
            throw ValidationException::withMessages(['code' => 'Code incorrect.']);
        }
        $text = $this->templates->render(SignatureMailTemplates::DONE, ['document' => $signed->title, 'destinataire' => (string) $signed->signer_name, 'organisme' => self::organisme()]);
        try {
            $this->mail((string) $signed->signer_email, new SignatureMail($text['subject'], $text['body'],
                ['content' => self::signedPdf($signed), 'name' => Str::slug($signed->title).'-signe.pdf']));
        } catch (UserFacingHttpException) {
            // Signed all the same: the confirmation is a courtesy, the PDF stays downloadable from the link.
        }

        return $signed;
    }

    /** The signed PDF, checked against its SHA-256 (410 when lost or altered). */
    public static function signedPdf(SignatureRequest $request): string
    {
        $disk = Storage::disk(TenantStorage::DISK);
        $content = $request->signed_pdf_path !== null && $disk->exists($request->signed_pdf_path) ? (string) $disk->get($request->signed_pdf_path) : null;
        if ($content === null || ! hash_equals((string) $request->signed_pdf_sha256, hash('sha256', $content))) {
            throw new UserFacingHttpException(410, 'Le PDF signé est introuvable ou a été modifié.');
        }

        return $content;
    }

    private static function pending(SignatureRequest $request): void
    {
        if ($request->status !== 'pending' || ! $request->expires_at->isFuture()) {
            throw new UserFacingHttpException(409, 'Ce lien de signature n\'est plus valable.');
        }
    }

    private static function codeHash(SignatureRequest $request, string $code): string
    {
        return hash_hmac('sha256', $request->id.'|'.$code, (string) config('app.key'));
    }

    private static function organisme(): string
    {
        return app(TenantContext::class)->current()->name ?? '';
    }

    private function mail(string $to, SignatureMail $mail): void
    {
        try {
            TenantMailer::for(null)->to($to)->send($mail);
        } catch (Throwable $e) {
            Log::warning('Signature e-mail failed.', ['error' => $e::class]);

            throw new UserFacingHttpException(503, self::MAIL_FAILED);
        }
    }
}
