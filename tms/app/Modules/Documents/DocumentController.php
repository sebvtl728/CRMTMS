<?php

namespace App\Modules\Documents;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Public\Paperwork;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The documents of a dossier: issue, PDF (preview inline or download, always
 * the archive), send, cancel. A dossier or document out of the viewer's
 * documents.view scope is a 404; documents.manage is required to change
 * anything (403).
 */
class DocumentController extends Controller
{
    public function store(Request $request, string $dossierId, DocumentIssuer $issuer): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Str::isUuid($dossierId) && Paperwork::allows($user, Permissions::VIEW, $dossierId), 404);
        abort_unless(Paperwork::allows($user, Permissions::MANAGE, $dossierId), 403);
        $values = $request->validate(['type' => ['required', Rule::enum(DocumentType::class)]], ['type.*' => 'Type de document inconnu.']);

        $type = DocumentType::from($values['type']);
        $document = $issuer->issue($user, $type, $dossierId);

        return back()->with('status', $type->label().' '.$document->number.' '.($type === DocumentType::Convention ? 'émise' : 'émis').'.');
    }

    public function pdf(Request $request, Document $document): Response
    {
        $inline = $request->boolean('apercu');

        return response(DocumentPdf::archived($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$document->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function send(Request $request, Document $document, DocumentSender $sender): RedirectResponse
    {
        $user = self::manager($request, $document);
        $sender->send($user, $document, $request->only(['to', 'subject', 'body']));

        return back()->with('status', 'Envoi de '.$document->number.' en cours.');
    }

    public function cancel(Request $request, Document $document, DocumentIssuer $issuer): RedirectResponse
    {
        $user = self::manager($request, $document);
        $issuer->cancel($user, $document, (string) $request->input('reason', ''));

        return back()->with('status', $document->number.' annulé.');
    }

    private static function manager(Request $request, Document $document): User
    {
        $user = self::user($request);
        abort_unless(Paperwork::allows($user, Permissions::MANAGE, $document->dossier_id), 403);

        return $user;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
