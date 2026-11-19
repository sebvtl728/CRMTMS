<?php

namespace App\Modules\Documents;

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Documents\Texts\DocumentText;
use Illuminate\Support\ServiceProvider;

/**
 * Module Documents (0.10): training documents of a dossier, issued from the
 * TMS data with the organisme's texts, frozen once issued, sent by e-mail.
 * Reads Sessions, the CRM and the catalogue through their public services.
 */
class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        CorePermissions::registerModule('documents', Permissions::all(), Permissions::defaultsFor(...), Permissions::labels(), Permissions::GROUP,
            Permissions::scoped(), Permissions::defaultScopesFor(...));

        MorphAliases::register([DocumentText::MORPH_ALIAS => DocumentText::class, Document::MORPH_ALIAS => Document::class]);
    }

    public function boot(): void
    {
        DocumentMailTemplates::register();
    }
}
