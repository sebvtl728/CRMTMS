<?php

namespace App\Modules\Documents;

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\Automation\DailyAutomations;
use App\Modules\Core\Documents\Previews;
use App\Modules\Core\Signatures\Events\SignatureCompleted;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\FicheLinks;
use App\Modules\Documents\Automation\CertificateAutomation;
use App\Modules\Documents\Automation\ConventionAutomation;
use App\Modules\Documents\Automation\ConvocationAutomation;
use App\Modules\Documents\Gdpr\DocumentAnonymisation;
use App\Modules\Documents\Texts\DocumentText;
use App\Modules\Sessions\Public\DossierBlocks;
use App\Modules\Sessions\Public\DossierHooks;
use App\Modules\Sessions\Public\Paperwork;
use App\Modules\Sessions\Public\SessionBlocks;
use App\Modules\Sessions\Public\SessionCards;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        DailyAutomations::register('convocations', fn (CarbonImmutable $today): int => app(ConvocationAutomation::class)->run($today));
        DailyAutomations::register('certificates', fn (CarbonImmutable $today): int => app(CertificateAutomation::class)->run($today));
        DossierHooks::onCreatedFromQuote('documents.convention', function (string $dossierId, User $by): void {
            app(ConventionAutomation::class)->forDossier($dossierId, $by);
        });
        // The dossier of an alternance candidate: its convention d'apprentissage / de professionnalisation, issued at once.
        DossierHooks::onCreatedFromCandidature('documents.alternance_convention', function (string $dossierId, User $by): void {
            app(AlternanceConventions::class)->issueForDossier($dossierId, $by);
        });
        DocumentMailTemplates::register();
        Previews::register(AlternancePreview::KEY, fn (array $data): string => app(AlternancePreview::class)->render($data));
        Event::listen(SignatureCompleted::class, fn (SignatureCompleted $e) => DocumentSignatures::completed($e, app(Paperwork::class)));
        RateLimiter::for('document-mail', fn (Request $request) => Limit::perMinute(20)->by('document-mail:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        FicheLinks::registerAnonymisation('documents', fn (array $personIds) => DocumentAnonymisation::clear($personIds));
        FicheLinks::registerExport('documents', fn (string $personId): array => DocumentAnonymisation::export($personId), [Permissions::VIEW]);
        SessionBlocks::register('documents.session', fn (string $sessionId, User $viewer) => SessionDocumentsBlock::for($sessionId, $viewer));
        SessionCards::register('documents', fn (array $sessionIds, User $viewer) => SessionDocumentsBlock::cards($sessionIds, $viewer));
        DossierBlocks::register('documents.dossier', fn (string $dossierId, User $viewer) => DossierDocumentsBlock::for($dossierId, $viewer), 20);

        // A document out of the viewer's documents.view scope (read on its dossier) is a 404.
        Route::bind('document', function (string $value): Document {
            $user = auth()->user();
            $document = Str::isUuid($value) ? Document::query()->find($value) : null;
            abort_if($document === null || ! $user instanceof User || ! Paperwork::allows($user, Permissions::VIEW, $document->dossier_id), 404);

            return $document;
        });
    }
}
