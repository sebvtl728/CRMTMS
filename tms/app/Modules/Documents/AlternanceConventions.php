<?php

namespace App\Modules\Documents;

use App\Modules\Core\Users\User;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Public\Paperwork;

/**
 * The convention of an alternance dossier (spec 2026-11-25): issued at the
 * creation of the dossier of an admitted candidate, of the type of its
 * contract, unless one (even cancelled) already exists.
 */
final class AlternanceConventions
{
    public function __construct(private readonly DocumentIssuer $issuer) {}

    /** The issued convention, or null when the dossier has no alternance contract or already one. */
    public function issueForDossier(string $dossierId, User $by): ?Document
    {
        $alternance = Paperwork::alternance($dossierId);
        if ($alternance === null) {
            return null;
        }
        $type = DocumentType::forContract((string) $alternance['contract_type']);
        if (Document::query()->where('dossier_id', $dossierId)->where('type', $type->value)->exists()) {
            return null;
        }

        return $this->issuer->issue($by, $type, $dossierId);
    }
}
