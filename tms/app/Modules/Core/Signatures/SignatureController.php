<?php

namespace App\Modules\Core\Signatures;

use App\Http\Controllers\Controller;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The signer's page (public link, no account): read the document, receive
 * a code, sign. Any invalid link (unknown, expired, cancelled) shows the
 * same page (404); a signed one shows its confirmation and signed PDF.
 */
class SignatureController extends Controller
{
    public function show(Request $request, string $token): SymfonyResponse
    {
        $pending = Signatures::find($token);
        $signed = $pending === null ? Signatures::findSigned($token) : null;
        $signature = $pending ?? $signed;
        if ($signature === null) {
            return Inertia::render('public/signature', ['state' => 'invalid'])->toResponse($request)->setStatusCode(404);
        }

        return Inertia::render('public/signature', [
            'state' => $signed !== null ? 'signed' : 'open',
            'organisme' => (string) (app(TenantContext::class)->current()->name ?? ''),
            'title' => $signature->title,
            'signer' => (string) $signature->signer_name,
            'email' => self::masked((string) $signature->signer_email),
            'deadline' => $signature->expires_at->setTimezone('Europe/Paris')->format('d/m/Y'),
            'code_sent' => $signature->code_sent_at !== null && $signature->code_expires_at?->isFuture() === true,
            'signed_at' => $signature->signed_at?->setTimezone('Europe/Paris')->format('d/m/Y à H:i'),
            'status' => $request->session()->get('status'),
        ])->toResponse($request);
    }

    /** The document to sign (pending) or signed (once signed), inline. */
    public function pdf(string $token): Response
    {
        $pending = Signatures::find($token);
        $signed = $pending === null ? Signatures::findSigned($token) : null;
        abort_if($pending === null && $signed === null, 404);
        $content = $signed !== null ? Signatures::signedPdf($signed) : Signatures::original($pending);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="document'.($signed !== null ? '-signe' : '').'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function code(string $token, Signatures $signatures): RedirectResponse
    {
        $signature = Signatures::find($token) ?? abort(404);
        $signatures->sendCode($signature);

        return back()->with('status', 'Code envoyé à '.self::masked((string) $signature->signer_email).' : il est valable 10 minutes.');
    }

    public function sign(Request $request, string $token, Signatures $signatures): RedirectResponse
    {
        $signature = Signatures::find($token) ?? abort(404);
        $request->validate(
            ['accept' => ['accepted'], 'code' => ['required', 'string', 'max:12']],
            ['accept.accepted' => 'Cochez la case pour confirmer que vous avez lu et acceptez le document.', 'code.required' => 'Saisissez le code reçu par e-mail.'],
        );
        $signatures->sign($signature, (string) $request->input('code'), (string) $request->ip(), (string) $request->userAgent());

        return redirect('/signature/'.$token)->with('status', 'Merci, votre signature est enregistrée. Une copie du document signé vous a été envoyée.');
    }

    /** c***@exemple.fr: enough to recognise one's address, not to give it away. */
    private static function masked(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
