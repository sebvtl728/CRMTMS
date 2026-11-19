<?php

namespace App\Modules\Documents;

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\FicheLinks;
use App\Modules\Documents\Gdpr\DocumentAnonymisation;
use App\Modules\Documents\Texts\DocumentText;
use App\Modules\Sessions\Public\DossierBlocks;
use App\Modules\Sessions\Public\Paperwork;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('document-mail', fn (Request $request) => Limit::perMinute(20)->by('document-mail:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        FicheLinks::registerAnonymisation('documents', fn (array $personIds) => DocumentAnonymisation::clear($personIds));
        FicheLinks::registerExport('documents', fn (string $personId): array => DocumentAnonymisation::export($personId), [Permissions::VIEW]);
        DossierBlocks::register('documents.dossier', fn (string $dossierId, User $viewer) => DossierDocumentsBlock::for($dossierId, $viewer));

        // A document out of the viewer's documents.view scope (read on its dossier) is a 404.
        Route::bind('document', function (string $value): Document {
            $user = auth()->user();
            $document = Str::isUuid($value) ? Document::query()->find($value) : null;
            abort_if($document === null || ! $user instanceof User || ! Paperwork::allows($user, Permissions::VIEW, $document->dossier_id), 404);

            return $document;
        });
    }
}
