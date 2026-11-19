<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierTransitions;

/**
 * Public service of the invoicing states of a dossier (0.9): the Finance
 * module sets « Facturé » once everything is invoiced and takes it back
 * (a credit note) - through DossierTransitions, on the locked row, journaled
 * by the audit trail of the dossier like any other change of state. A
 * dossier in the trash is found too: its invoices remain.
 */
final class DossierStates
{
    public function __construct(private readonly DossierTransitions $transitions) {}

    /**
     * « Facturé », only from « Réalisé » (stored, or computed from the dates
     * of its sessions); already invoiced: nothing changes; otherwise 409.
     */
    public function markInvoiced(string $dossierId): void
    {
        $this->transitions->markInvoiced(Dossier::withTrashed()->findOrFail($dossierId));
    }

    /**
     * Back to « Réalisé » from « Facturé »; any other state: nothing changes.
     */
    public function unmarkInvoiced(string $dossierId): void
    {
        $this->transitions->unmarkInvoiced(Dossier::withTrashed()->findOrFail($dossierId));
    }
}
