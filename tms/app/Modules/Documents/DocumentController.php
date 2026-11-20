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
        $values = $request->validate(['type' => ['required', Rule::in([DocumentType::Convention->value, DocumentType::Contract->value])]], ['type.*' => 'Type de document inconnu.']);

        $type = DocumentType::from($values['type']);
        $document = $issuer->issue($user, $type, $dossierId);

        return back()->with('status', $type->label().' '.$document->number.' '.($type === DocumentType::Convention ? 'émise' : 'émis').'.');
    }

    /** « Émettre » for one trainee (convocation, attestation, certificate). */
    public function storeForEnrolment(Request $request, string $enrolmentId, DocumentIssuer $issuer): RedirectResponse
    {
        $user = self::user($request);
        $dossierId = Str::isUuid($enrolmentId) ? (Paperwork::enrolment($enrolmentId)['dossier_id'] ?? null) : null;
        abort_unless($dossierId !== null && Paperwork::allows($user, Permissions::VIEW, $dossierId), 404);
        abort_unless(Paperwork::allows($user, Permissions::MANAGE, $dossierId), 403);
        $type = self::perEnrolmentType($request);
        $document = $issuer->issueForEnrolment($user, $type, $enrolmentId);

        return back()->with('status', $type->label().' '.$document->number.' '.($type === DocumentType::RealisationCertificate ? 'émis' : 'émise').'.');
    }

    /** « Émettre pour tous » on a session. */
    public function storeForSession(Request $request, string $sessionId, DocumentBatch $batch): RedirectResponse
    {
        $user = self::sessionManager($request, $sessionId);
        $count = $batch->issue($user, self::perEnrolmentType($request), $sessionId);

        return back()->with('status', $count === 0 ? 'Aucun document à émettre : chaque stagiaire a déjà le sien.' : ($count > 1 ? "{$count} documents émis." : '1 document émis.'));
    }

    /** « Envoyer à tous » on a session. */
    public function sendForSession(Request $request, string $sessionId, DocumentBatch $batch): RedirectResponse
    {
        $user = self::sessionManager($request, $sessionId);
        $result = $batch->send($user, self::perEnrolmentType($request), $sessionId);
        $text = $result['queued'] === 0 ? 'Aucun envoi.' : ($result['queued'] > 1 ? "{$result['queued']} envois en cours" : '1 envoi en cours');
        if ($result['without_email'] > 0) {
            $text = rtrim($text, '.').' ; '.$result['without_email'].' stagiaire'.($result['without_email'] > 1 ? 's' : '').' sans adresse e-mail.';
        } elseif ($result['queued'] > 0) {
            $text .= '.';
        }

        return back()->with('status', $text);
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

    private static function perEnrolmentType(Request $request): DocumentType
    {
        $values = $request->validate(['type' => ['required', Rule::in(array_map(fn (DocumentType $t) => $t->value, SessionDocumentsBlock::TYPES))]],
            ['type.*' => 'Type de document inconnu.']);

        return DocumentType::from($values['type']);
    }

    /** Someone who may manage the documents of at least one dossier of the session (404 when none is even visible). */
    private static function sessionManager(Request $request, string $sessionId): User
    {
        $user = self::user($request);
        $dossiers = array_unique(array_filter(array_map(fn (string $id) => Paperwork::enrolment($id)['dossier_id'] ?? null,
            Str::isUuid($sessionId) ? Paperwork::sessionEnrolments($sessionId) : [])));
        abort_if($dossiers === [] || ! collect($dossiers)->contains(fn (string $d) => Paperwork::allows($user, Permissions::VIEW, $d)), 404);
        abort_unless(collect($dossiers)->contains(fn (string $d) => Paperwork::allows($user, Permissions::MANAGE, $d)), 403);

        return $user;
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
