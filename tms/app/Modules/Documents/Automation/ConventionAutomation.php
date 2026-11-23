<?php

namespace App\Modules\Documents\Automation;

use App\Modules\Core\Automation\AutomationSends;
use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Core\Users\User;
use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\DocumentMail;
use App\Modules\Documents\DocumentSignatures;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Public\Paperwork;
use Illuminate\Support\Facades\DB;

/**
 * The automatic convention (off by default, Administration › Automatismes):
 * a dossier created from a signed quote for an entreprise gets its
 * convention issued in the name of the user who created it, then its
 * signature asked of the contact of the dossier (without a valid address:
 * issued only). Never a second convention (a cancelled one counts), never
 * for a particulier (his contract is issued by hand), never twice
 * (AutomationSends). The convention lists the fundings, « sous réserve »
 * while one is not agreed: the OPCO usually asks the signed convention
 * before agreeing.
 */
final class ConventionAutomation
{
    public function __construct(private readonly DocumentIssuer $issuer, private readonly DocumentSignatures $signatures) {}

    /** Whether the convention was issued. */
    public function forDossier(string $dossierId, User $by): bool
    {
        if (! AutomationSettings::conventionOnQuote()) {
            return false;
        }
        $dossier = Paperwork::dossier($dossierId);
        if ($dossier === null || $dossier['contractor']['type'] !== 'organisation' || $dossier['status'] === 'cancelled'
            || Document::query()->where('dossier_id', $dossierId)->where('type', DocumentType::Convention->value)->exists()) {
            return false;
        }
        $document = DB::transaction(fn (): ?Document => AutomationSends::claim(AutomationSends::CONVENTION, $dossierId, 'quote')
            ? $this->issuer->issue($by, DocumentType::Convention, $dossierId)
            : null);
        if ($document === null) {
            return false;
        }
        $email = DocumentMail::defaults($document)['to'];
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $this->signatures->request($by, $document, $email);
        }

        return true;
    }
}
