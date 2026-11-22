<?php

namespace App\Modules\Qualite;

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Qualite\Qualiopi\QualityIndicator;
use App\Modules\Qualite\Qualiopi\QualityProof;
use App\Modules\Qualite\Registers\Complaint;
use App\Modules\Qualite\Registers\ImprovementAction;
use App\Modules\Qualite\Registers\RegisterMeasures;
use Illuminate\Support\ServiceProvider;

/**
 * Module Qualité: the Qualiopi file (referential 2026, the organisme's work
 * on each indicator, proofs, automatic measures declared by the other
 * modules through Qualite\Public\Measures, audit export).
 */
class QualiteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        CorePermissions::registerModule('qualite', Permissions::all(), Permissions::defaultsFor(...), Permissions::labels(), Permissions::GROUP);

        MorphAliases::register([
            QualityIndicator::MORPH_ALIAS => QualityIndicator::class,
            QualityProof::MORPH_ALIAS => QualityProof::class,
            Complaint::MORPH_ALIAS => Complaint::class,
            ImprovementAction::MORPH_ALIAS => ImprovementAction::class,
        ]);
    }

    public function boot(): void
    {
        ReferenceUsage::register(ReferenceList::ComplaintChannel, 'quality_complaints', 'channel_id');
        ReferenceUsage::register(ReferenceList::ImprovementSource, 'quality_actions', 'source_id');
        RegisterMeasures::register();
    }
}
