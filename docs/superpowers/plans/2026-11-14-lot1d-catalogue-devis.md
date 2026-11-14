# Lot 1d – Catalogue de formations et devis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Donner à CAP FORMATION un catalogue de formations rattachées aux DAS, des opportunités qui portent leurs formations, et des devis complets : créés depuis une opportunité, numérotés par société, PDF au nom de la société, envoyés par e-mail depuis le TMS, suivis jusqu'à l'acceptation ou au refus et reliés au pipeline.

**Architecture:** Nouveau module `app/Modules/Catalogue` (formations) exposant un service public lu par le Crm ; sous-module `app/Modules/Crm/Quotes` (devis, lignes, numérotation, statuts, envoi). Génération PDF par un service socle `Core/Documents/PdfRenderer` (Gotenberg, service interne de la stack, pas un connecteur externe). Envoi par `Connectors\Smtp\TenantMailer::for($company)`. Branchements existants : `CrmVisibility`, `Timeline::TAG`, `LastContact`, `LinkedData`, `Merger`, `Anonymiser`, `PersonDataExport`, `ExportResource`, `ImportTarget`, `OpportunityStage::move`.

**Tech Stack:** Laravel 12, Inertia/React 19/TS, PostgreSQL 17 (RLS), Redis/Horizon, Gotenberg 8 (HTML → PDF), Pest, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-11-tms-design.md` (§7 Formation, Devis, Société ; §8 « 1d – Catalogue et devis » ; §9.1 import du catalogue ; §10 SMTP) ; `Cdc/cdc-activité-commercial.md` (« Offre de formation », « Devis et propositions ») ; règles `CLAUDE.md` ; reports non bloquants : `docs/superpowers/plans/2026-11-14-lot1d-carryover.md` (hors périmètre sauf mention).

## Global Constraints

- Toutes les règles de `CLAUDE.md` (RLS + `BelongsToTenant` + `Auditable`, morph aliases, référentiels par id + `ReferenceUsage::register`, clés de cache préfixées, jobs tenant-aware, `UserFacingHttpException`, limiteurs nommés, `TenantStorage` pour tout fichier, aucun module ne lit les tables d'un autre).
- Montants en euros, `numeric(12,2)`, calculés en **centimes entiers** (jamais de flottant), saisis et affichés au format français (`12 500,00 €`).
- TVA : société `vat_regime = exempt` → toutes les lignes à 0 % et mention « TVA non applicable, art. 261-4-4° a du CGI » (`LegalMentions::vatExemptionNotice()`) sur le PDF ; `subject` → taux par ligne parmi 20 ; 10 ; 5,5 ; 2,1 ; 0 (défaut 20).
- Statuts de devis : `draft` (Brouillon), `sent` (Envoyé), `reminded` (Relancé), `accepted` (Accepté), `refused` (Refusé), `expired` (Expiré). Seul un brouillon se modifie ; un devis envoyé est figé (PDF archivé, destinataire et société copiés).
- Dates en `Europe/Paris` : un devis valable jusqu'au 30/11 l'est jusqu'au 30/11 23:59:59 Paris.
- Numérotation par société et par année civile Paris : `<préfixe>-<AAAA>-<NNNN>` (préfixe société, défaut `DEV`), attribuée **à la création**, sans doublon même en concurrence.
- Branche : `lot1d-catalogue-devis` créée depuis `main`.

## Review Focus

1. **Totaux au centime** : lignes avec remise en %, quantités décimales et plusieurs taux de TVA → HT = Σ lignes HT arrondies, TVA = Σ par taux de (base du taux × taux) arrondie, TTC = HT + TVA ; aucun écart d'un centime entre écran, PDF et export. Test : Task 5 `quote totals are exact to the cent`.
2. **Deux devis créés au même instant pour la même société** → numéros distincts et consécutifs ; changement d'année au 1er janvier minuit Paris → `0001`. Test : Task 4 `numbering is gapless under concurrency and resets per paris year`.
3. **Société exonérée, puis passée assujettie** → les devis déjà envoyés gardent 0 % et la mention ; seuls les nouveaux brouillons changent. Test : Task 6 `sent quote is frozen against company changes`.
4. **Expiration** : devis valable jusqu'à aujourd'hui → encore « Envoyé » à 23 h 50 Paris, « Expiré » après le passage de 00 h 05. Test : Task 7 `expiry uses europe/paris end of day`.
5. **Responsable de DAS** → ne voit que les devis des opportunités de ses DAS ; le PDF d'un devis hors périmètre par URL directe → 404. Test : Task 5 `das visibility on quotes and pdf`.

---

## Décisions de ce plan (à valider par Sébastien en relisant)

- **Formation** = table `trainings` du module `Catalogue` ; elle deviendra le **Programme** du lot 2 (versions, Qualiopi) sans changer de table. **[choix Claude, conforme à la conception]**
- **Opportunité ↔ formations** : 0..n formations (la conception dit 1..n ; les opportunités déjà saisies en 1c n'en ont pas, donc la formation reste facultative sur l'opportunité mais un devis exige au moins une ligne) avec quantité (nombre de stagiaires ou de sessions) ; bouton « Calculer le montant » (tarif entreprise ou particulier selon la cible × quantité), montant toujours modifiable. **[choix Claude]**
- **Modèle PDF par société** = un seul gabarit soigné, personnalisé par société : logo, coordonnées, mentions légales, conditions générales (texte), pied de page. Pas d'éditeur de modèles en 1d. **[choix Claude]**
- **Numéro attribué à la création** du brouillon (un brouillon abandonné laisse un trou, sans conséquence : les devis ne sont pas des factures). **[choix Claude]**
- **Modifier un devis envoyé** = « Créer une nouvelle version » : copie en brouillon avec un nouveau numéro, l'ancien passe « Refusé » avec la mention « Remplacé par … ». **[choix Claude]**
- **Lien pipeline** : envoi → l'opportunité passe automatiquement à « Proposition » si elle est à une étape antérieure ouverte ; accepté → fenêtre proposant « Gagné » (date = date d'acceptation, montant = total HT du devis) ; refusé → fenêtre proposant « Perdu » (motif obligatoire). L'utilisateur confirme ou refuse. **[conception]**
- **Anonymisation d'un particulier** : ses devis restent (montants, statistiques) mais nom, adresse et e-mail copiés sont effacés et les PDF supprimés. **[choix Claude]**
- **Droits** : catalogue — lecture pour tous les rôles CRM, gestion pour administrateur et direction (`catalogue.manage`) ; devis — mêmes droits que les opportunités (`crm.create/update/delete`), envoi par e-mail inclus. **[choix Claude]**

---

## File Structure

```
tms/app/Modules/Catalogue/
  CatalogueServiceProvider.php, Permissions.php
  Training.php, TrainingController.php, TrainingRequest.php, TrainingPolicy.php,
  TrainingListQuery.php, TrainingExport.php, Trainings.php (service public lu par le Crm)
tms/app/Modules/Core/Documents/ PdfRenderer.php (client Gotenberg), PdfRenderingFailed.php
tms/app/Modules/Core/Companies/ CompanyBranding.php (logo, préfixe, validité, conditions) + migration
tms/app/Modules/Crm/Opportunities/ OpportunityTrainings.php (lignes formation de l'opportunité)
tms/app/Modules/Crm/Quotes/
  Quote.php, QuoteLine.php, QuoteNumbering.php, QuoteTotals.php (calcul en centimes),
  QuoteController.php, QuoteRequest.php, QuotePolicy.php, QuoteListQuery.php, QuoteExport.php,
  QuoteStatus.php, QuoteTransitions.php, QuotePdf.php, SendQuote.php, QuoteMail.php,
  ExpireQuotes.php (planifié), QuotesProvider.php (timeline), QuoteSuccessRate.php
tms/resources/views/documents/quote.blade.php
tms/routes/catalogue.php, tms/routes/crm-quotes.php
tms/resources/js/pages/catalogue/{index,form}.tsx, crm/quotes/{index,show,form}.tsx,
  components/crm/{quote-lines,quote-send-dialog,quote-outcome-dialog,opportunity-trainings}.tsx
tms/tests/Feature/Catalogue/…, tests/Feature/Crm/Quotes/…, tests/e2e/quotes.spec.ts
```

---

### Task 1: Catalogue de formations

**Files:**
- Create: module `app/Modules/Catalogue/*` (voir File Structure), migrations `create_trainings_table`, `create_training_funding_modes_table`, `routes/catalogue.php`, pages `catalogue/{index,form}.tsx`
- Modify: `bootstrap/providers.php`, `app-sidebar.tsx` (« Catalogue » dans le groupe Commercial, après « Planning »), `ModuleBoundariesArchTest` (Crm ↛ tables Catalogue)
- Test: `tests/Feature/Catalogue/{TrainingsTest,TrainingVisibilityTest,TrainingExportTest}.php`

**Interfaces:**
- Produces:
  - Table `trainings` : `id`, `tenant_id`, `code` (20, unique par organisme parmi les non supprimées), `title` (200, requis), `das_id` (FK `reference_items` DAS, requis), `duration_hours` numeric(6,2) null, `duration_days` numeric(5,2) null, `audience` text null (public visé), `prerequisites` text null, `price_company` numeric(12,2) null (HT, par stagiaire), `price_individual` numeric(12,2) null (HT par stagiaire, comme le tarif entreprise ; identique au TTC pour une société exonérée), `is_active` bool défaut true, timestamps, soft deletes, created_by/updated_by. `training_funding_modes(tenant_id, training_id, funding_mode_id)`.
  - `Catalogue\Trainings` (service public) : `find(string $id): ?TrainingData`, `search(string $q, ?string $dasId, int $limit = 20): list<TrainingData>`, `options(): list<TrainingData>` ; `TrainingData` (id, code, title, das_id, das_label, duration_hours, duration_days, price_company_cents, price_individual_cents, is_active).
  - Permissions `catalogue.view` (tous les rôles CRM), `catalogue.manage` (AdminOrganisme, Direction) via `Permissions::registerModule()`.
  - Routes `GET /catalogue` (liste 25, filtres texte / DAS / actives / financement, tri titre / code / tarif), `GET|POST /catalogue/create`, `GET|PUT /catalogue/{training}/edit`, `DELETE /catalogue/{training}` (refus si utilisée : `LinkedData`), `POST /catalogue/{training}/toggle` (activer/désactiver), `GET /catalogue/export` (xlsx, `crm.export`, mécanisme `ExportResource`).
  - `ReferenceUsage::register` pour `das_id` et `training_funding_modes.funding_mode_id` ; morph alias `training`.
- [ ] **Step 1: Tests** — CRUD par admin ; commercial lit mais ne crée pas (403) ; code unique par organisme, réutilisable après suppression ; tarif `1 250,50` saisi en français accepté ; formation utilisée par une opportunité → suppression refusée avec message ; désactivée → absente des choix mais visible sur les opportunités existantes ; export identique à la liste filtrée ; isolation : formation d'un autre organisme → 404.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — `docker compose exec -T app php artisan test --filter=Training`
- [ ] **Step 5: Commit** — `feat(catalogue): training catalogue linked to das with funding modes`

### Task 2: Import du catalogue

**Files:**
- Modify: `app/Modules/Crm/Import/{ImportTarget,FieldMap,RowImporter,ImportReport,UndoImport}.php` (nouvelle cible), `Catalogue\Trainings` (méthode d'écriture publique `upsertFromImport(array $values, ?string $sourceSystem, ?string $sourceId): TrainingData`)
- Create: migration ajoutant `source_system`, `source_id` à `trainings` (clé unique partielle par organisme)
- Test: `tests/Feature/Crm/Import/TrainingImportTest.php`

**Interfaces:**
- Produces: `ImportTarget::Trainings` (« Catalogue de formations ») ; colonnes importables : code, intitulé, DAS (correspondance de valeurs), durée heures, durée jours, public, prérequis, tarif entreprise, tarif particulier, financements (liste séparée par `,` ou `;`, correspondance de valeurs), active. Doublon = même code ou même `source_system/source_id` ; stratégies existantes (ignorer / mettre à jour / créer). Annulation par `import_stamps` (une formation utilisée depuis n'est pas supprimée).
- [ ] **Step 1: Tests** — import CSV `;` Windows-1252 de 3 formations ; DAS inconnu → ligne en erreur ; tarif `1 200,00` → 1200.00 ; second import même code → mise à jour ; annulation supprime ce qui a été créé sauf une formation entre-temps liée à une opportunité.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — `--filter=TrainingImport`
- [ ] **Step 5: Commit** — `feat(crm): catalogue import target`

### Task 3: Formations d'une opportunité

**Files:**
- Create: migration `create_opportunity_trainings_table`, `app/Modules/Crm/Opportunities/OpportunityTrainings.php`, `components/crm/opportunity-trainings.tsx`
- Modify: `OpportunityController`, `OpportunityRequest`, `crm/opportunities/{form,show,index}.tsx`, `OpportunityExport` (colonne « Formations »), `OpportunityListQuery` (filtre « formation »), `Merger` (rien à déplacer : suit l'opportunité), `docs`
- Test: `tests/Feature/Crm/Opportunities/OpportunityTrainingsTest.php`

**Interfaces:**
- Produces: `opportunity_trainings(id, tenant_id, opportunity_id, training_id, quantity numeric(8,2) défaut 1, position smallint)` unique (opportunity_id, training_id) ; `OpportunityTrainings::sync(Opportunity $o, list<array{training_id:string, quantity:string}> $lines): void` ; `OpportunityTrainings::estimate(Opportunity $o): int` (centimes : Σ tarif selon la cible × quantité, tarif manquant compté 0 et signalé) ; route `POST /crm/opportunities/{opportunity}/estimate` (renvoie le montant proposé, ne l'enregistre pas). Le DAS de l'opportunité reste son DAS principal (prérempli depuis la première formation s'il est vide). Lecture des formations via `Catalogue\Trainings` uniquement.
- [ ] **Step 1: Tests** — entreprise : 2 formations (1 200 € × 3, 800 € × 1) → 4 400,00 € proposé ; particulier → tarif particulier ; tarif manquant → avertissement ; formation désactivée conservée sur l'opportunité existante mais refusée à l'ajout ; opportunité d'un autre organisme → 404 ; filtre « formation » de la liste et export « Formations ».
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — `--filter=OpportunityTrainings`
- [ ] **Step 5: Commit** — `feat(crm): opportunities carry catalogue trainings and estimate their amount`

### Task 4: Société émettrice et numérotation

**Files:**
- Create: migration `add_quote_settings_to_companies_table` (`logo_path` null, `quote_prefix` varchar(10) défaut `DEV`, `quote_validity_days` smallint défaut 30, `quote_terms` text null, `quote_footer` text null), migration `create_quote_sequences_table`, `app/Modules/Core/Companies/CompanyBranding.php`, `app/Modules/Crm/Quotes/QuoteNumbering.php`
- Modify: `CompanyController` + page admin « Sociétés » (section « Devis » : logo PNG/JPEG ≤ 1 Mo vérifié par signature, préfixe `^[A-Z0-9]{1,10}$`, validité 1–365 jours, conditions, pied de page)
- Test: `tests/Feature/Core/CompanyBrandingTest.php`, `tests/Feature/Crm/Quotes/QuoteNumberingTest.php`

**Interfaces:**
- Produces: `quote_sequences(tenant_id, company_id, year smallint, last_number int)` PK (company_id, year) ; `QuoteNumbering::next(Company $c, CarbonImmutable $at): string` — `insert … on conflict do update set last_number = last_number + 1 returning last_number` dans la transaction appelante, année = année Paris de `$at`, format `sprintf('%s-%d-%04d')` ; `CompanyBranding::logoDataUri(Company $c): ?string` (lu via `TenantStorage`, pour le PDF).
- [ ] **Step 1: Tests** — `numbering is gapless under concurrency and resets per paris year` (Review Focus 2 : deux connexions PDO parallèles ; 31/12 23:59 Paris → année N, 01/01 00:00 Paris → N+1 `0001`) ; préfixe par société ; logo SVG ou fichier renommé refusé ; logo stocké sous `tenants/<id>/companies/` ; numérotation d'un autre organisme indépendante.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — `--filter='CompanyBranding|QuoteNumbering'`
- [ ] **Step 5: Commit** — `feat(crm): company quote settings and per-company quote numbering`

### Task 5: Devis et lignes (brouillon)

**Files:**
- Create: migrations `create_quotes_table`, `create_quote_lines_table` ; `Quote`, `QuoteLine`, `QuoteTotals`, `QuoteStatus`, `QuoteController`, `QuoteRequest`, `QuotePolicy`, `QuoteListQuery`, `QuotesProvider` ; `routes/crm-quotes.php` ; pages `crm/quotes/{index,show,form}.tsx`, `components/crm/quote-lines.tsx` ; onglet « Devis » sur l'opportunité et les fiches
- Modify: `CrmVisibility` (`quotes(Builder, User)` = par l'opportunité), `CrmServiceProvider` (bind, tag timeline, `LinkedData::register('quotes', …)`, morph alias `quote`), `app-sidebar.tsx` (« Devis » après « Opportunités »)
- Test: `tests/Feature/Crm/Quotes/{QuotesTest,QuoteTotalsTest,QuoteVisibilityTest}.php`

**Interfaces:**
- Produces:
  - `quotes` : `id`, `tenant_id`, `number` (unique par organisme), `company_id`, `opportunity_id` (requis), `organisation_id` null / `person_id` null (le compte de l'opportunité, copié), `contact_person_id` null (destinataire, interlocuteur ou le particulier), `status` (CHECK liste globale), `issued_on` date, `valid_until` date, `sent_at` timestamptz null, `reminded_at` null, `accepted_on` null, `refused_on` null, `refusal_reason` text null, `replaced_by_id` null, `vat_exempt` bool (copié de la société), `total_ht` / `total_vat` / `total_ttc` numeric(12,2), `recipient_snapshot` jsonb null (nom, adresse, e-mail figés à l'envoi), `pdf_path` null, `notes` text null (mentions particulières), timestamps, soft deletes, created_by/updated_by.
  - `quote_lines` : `id`, `tenant_id`, `quote_id`, `position`, `training_id` null, `das_id` null (copié de la formation), `label` (300), `description` text null, `quantity` numeric(8,2), `unit` (`stagiaire`, `jour`, `heure`, `forfait`, `session`), `unit_price` numeric(12,2) HT, `discount_percent` numeric(5,2) défaut 0, `vat_rate` numeric(4,2), `total_ht` numeric(12,2).
  - `QuoteTotals::compute(list<LineInput>, bool $vatExempt): Totals` — par ligne : `round_half_up(qty × prix × (1 − remise/100))` en centimes ; TVA par taux sur la base cumulée du taux ; tout en entiers (`brick/math` ou bcmath).
  - Routes : `GET /crm/quotes` (liste 25, filtres statut / société / DAS / période d'émission / responsable de l'opportunité / texte ; tri numéro, date, total), `GET /crm/opportunities/{opportunity}/quotes/create` (prérempli : société par défaut, lignes depuis les formations de l'opportunité au tarif selon la cible, validité = société), `POST /crm/quotes`, `GET /crm/quotes/{quote}`, `GET|PUT /crm/quotes/{quote}/edit` (brouillon seulement, sinon 409 « Un devis envoyé ne se modifie plus. »), `DELETE` (brouillon seulement), `POST /crm/quotes/{quote}/duplicate` (nouvelle version).
  - Visibilité : un devis est visible ssi son opportunité l'est ; bind `Str::isUuid` + visibilité ; opportunité d'une fiche en corbeille → mêmes règles que l'opportunité.
  - Timeline : « Devis DEV-2026-0012 créé / envoyé / relancé / accepté / refusé / expiré » sur l'opportunité et la fiche.
- [ ] **Step 1: Tests** — `quote totals are exact to the cent` (Review Focus 1 : 3 lignes, remises 10 % et 7,5 %, quantités 2,5 et 3, taux 20 et 5,5 → valeurs attendues écrites en dur) ; société exonérée → TVA 0 et aucun taux modifiable ; création préremplie depuis l'opportunité ; devis non brouillon → modification 409 ; `das visibility on quotes and pdf` (Review Focus 5, la partie PDF est complétée en Task 6) ; assistant sans `crm.delete` ne supprime pas ; montant `12 500,00` accepté.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — `--filter=Quote`
- [ ] **Step 5: Commit** — `feat(crm): quotes with lines, exact totals, visibility and timeline`

### Task 6: PDF du devis

**Files:**
- Create: `app/Modules/Core/Documents/{PdfRenderer,PdfRenderingFailed}.php`, `config/documents.php` (`gotenberg_url` = env `GOTENBERG_URL`, défaut `http://gotenberg:3000`, timeout 30 s), `resources/views/documents/quote.blade.php` (+ CSS d'impression A4 intégrée), `app/Modules/Crm/Quotes/QuotePdf.php`
- Modify: `compose.yaml`/`compose.prod.yaml`/`compose.e2e.yaml` (service gotenberg joignable par app et worker, sans port publié), `ops` (vérification santé), `tms/docs/install.md`
- Test: `tests/Feature/Core/PdfRendererTest.php`, `tests/Feature/Crm/Quotes/QuotePdfTest.php`

**Interfaces:**
- Produces: `PdfRenderer::fromHtml(string $html): string` (octets PDF ; `POST /forms/chromium/convert/html`, fichier `index.html`, `paperWidth 8.27`, `paperHeight 11.7`, marges 0,6 in ; réseau sortant désactivé dans le HTML : aucune ressource externe, logo en data URI ; erreur → `PdfRenderingFailed` avec message français, sans contenu du devis dans les journaux) ; `QuotePdf::render(Quote $q): string` (brouillon : filigrane « BROUILLON ») ; `QuotePdf::archive(Quote $q): string` (chemin `TenantStorage` zone `quotes`) ; routes `GET /crm/quotes/{quote}/pdf` (envoyé → PDF archivé ; brouillon → aperçu à la volée ; `Content-Disposition: inline; filename="DEV-2026-0012.pdf"`).
  - Contenu : logo, coordonnées et mentions légales de la société (`LegalMentions::for`), numéro, date, validité, destinataire (copie figée si envoyé), objet (titre de l'opportunité), tableau des lignes, récapitulatif TVA par taux, totaux HT/TVA/TTC, mention d'exonération si `vat_exempt`, conditions, pied de page, numéro de page.
  - Le HTML est rendu par Blade avec échappement ; aucun champ saisi n'est interprété comme HTML.
- [ ] **Step 1: Tests** — `PdfRenderer` : requête multipart attendue (`Http::fake`) et erreur 5xx → `PdfRenderingFailed` ; HTML du devis : contient numéro, totaux au format français, mention d'exonération si société exonérée, filigrane brouillon ; `<script>` saisi dans une ligne rendu échappé ; `sent quote is frozen against company changes` (Review Focus 3, PDF archivé inchangé et `vat_exempt` conservé après changement de régime de la société) ; PDF hors périmètre DAS → 404 ; test d'intégration réel contre le conteneur gotenberg marqué `->group('slow')` (le PDF commence par `%PDF-`).
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — `--filter='PdfRenderer|QuotePdf'` puis `--group=slow --filter=QuotePdf`
- [ ] **Step 5: Commit** — `feat(crm): quote pdf rendered by gotenberg from a per-company template`

### Task 7: Envoi, relance, issue et lien pipeline

**Files:**
- Create: `QuoteTransitions.php`, `SendQuote.php` (job `TenantAwareJob`), `QuoteMail.php` (mailable, PDF joint), `ExpireQuotes.php` (commande planifiée), `components/crm/{quote-send-dialog,quote-outcome-dialog}.tsx`
- Modify: `routes/console.php` (00:05 Europe/Paris via `ForEachTenant`), `LastContact::registerSource` (envoi et relance d'un devis), `OpportunityStage` (lecture de l'ordre des étapes), `quotes/show.tsx`
- Test: `tests/Feature/Crm/Quotes/{QuoteSendTest,QuoteOutcomeTest,QuoteExpiryTest}.php`

**Interfaces:**
- Produces:
  - `POST /crm/quotes/{quote}/send` (`to` prérempli avec l'e-mail du destinataire, `cc` facultatif, `subject` et `body` préremplis et modifiables) : dans une transaction, fige `recipient_snapshot` et `vat_exempt`, archive le PDF, passe `sent`, `sent_at`, puis met en file `SendQuote` après commit ; envoi par `TenantMailer::for($quote->company)` ; échec SMTP → devis reste « Envoyé » avec bannière « L'e-mail n'est pas parti » et bouton « Renvoyer », erreur dans `connector_runs` sans donnée personnelle. Destinataire sans e-mail → 422 « Le destinataire n'a pas d'adresse e-mail. ». `TenantMailer::isBlackHole()` → avertissement avant l'envoi.
  - Pipeline à l'envoi : si l'étape de l'opportunité est ouverte et d'ordre inférieur à « Proposition » (repérée par `meta.quote_stage = true` sur l'étape, seedée sur « Proposition », modifiable dans les référentiels — pas de code en dur), `OpportunityStage::move` vers cette étape.
  - `POST …/remind` (envoyé ou relancé → `reminded`, e-mail de relance avec le même PDF) ; `POST …/accept` (`accepted_on`, défaut aujourd'hui Paris, pas dans le futur) → réponse avec `proposeWon: {amount_ht}` ; `POST …/refuse` (`refusal_reason` facultatif) → `proposeLost: true` ; la fenêtre `quote-outcome-dialog` appelle `POST /crm/opportunities/{o}/stage` existant (Gagné avec `won_on` = date d'acceptation et montant = total HT ; Perdu avec motif obligatoire) ; l'utilisateur peut refuser.
  - `duplicate` d'un devis envoyé → nouveau brouillon, l'ancien passe `refused` avec `replaced_by_id` et la raison « Remplacé par DEV-… ».
  - `ExpireQuotes` : `sent`/`reminded` avec `valid_until` < aujourd'hui Paris → `expired` ; entrée de timeline.
- [ ] **Step 1: Tests** — envoi : mail capturé (`Mail::fake` sur le mailer d'organisme) avec PDF joint, statut, timeline, `last_contact_at` mis à jour, opportunité « Étude » → « Proposition », « Négociation » inchangée ; destinataire sans e-mail → 422 ; relance ; accepté → proposition Gagné avec montant ; refusé → proposition Perdu ; acceptation datée du futur refusée ; `expiry uses europe/paris end of day` (Review Focus 4) ; nouvelle version ; échec SMTP → bannière et renvoi ; jobs portent le tenant.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — `--filter='QuoteSend|QuoteOutcome|QuoteExpiry'`
- [ ] **Step 5: Commit** — `feat(crm): send quotes by email, reminders, outcomes linked to the pipeline and expiry`

### Task 8: Fusion, RGPD, export et taux de réussite

**Files:**
- Create: `QuoteExport.php`, `QuoteSuccessRate.php`
- Modify: `Merger` (devis des fiches absorbées : `organisation_id`, `person_id`, `contact_person_id`), `Anonymiser` (particulier anonymisé : `recipient_snapshot` vidé, `contact_person_id` null, PDF supprimés, `notes` effacées ; interlocuteur anonymisé : retiré des devis), `PersonDataExport` (section « Devis » : numéro, date, statut, total), `quotes/index.tsx` (bandeau taux de réussite), `CommercialSummary`/`dashboard.tsx` (tuile « Devis » : envoyés, acceptés, taux du mois)
- Test: `tests/Feature/Crm/Quotes/{QuoteMergeGdprTest,QuoteExportTest,QuoteSuccessRateTest}.php`

**Interfaces:**
- Produces: `QuoteSuccessRate::for(User $u, CarbonImmutable $from, CarbonImmutable $to, array $filters): array{sent:int, accepted:int, rate:?float}` — devis dont `sent_at` est dans la période (Paris), acceptés parmi eux, `rate` null si aucun envoyé ; visibilité appliquée ; périodes mois / trimestre / année. Export xlsx des devis (cellules typées : montants, dates), `crm.export`, audit.
- [ ] **Step 1: Tests** — `merge moves quotes` ; anonymisation d'un particulier : PDF supprimés du stockage, copie destinataire vide, montants conservés, `personalTraces` vide ; export filtré identique à la liste ; taux : 4 envoyés en novembre dont 1 accepté en décembre → novembre 25 % ; aucun envoyé → « — » ; Responsable DAS : taux limité à ses DAS.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — `--filter='QuoteMergeGdpr|QuoteExport|QuoteSuccessRate'`
- [ ] **Step 5: Commit** — `feat(crm): quotes in merge, gdpr, export and success rate`

### Task 9: Navigation, documentation, e2e, version

**Files:** `app-sidebar.tsx` (groupe Commercial : Pipeline, Opportunités, Devis, À faire, Planning, Compte rendu de RDV, Catalogue, Entreprises, Personnes, Imports, RGPD), `tms/docs/crm.md` (catalogue, formations d'une opportunité, devis : création, PDF, envoi, relance, issue, expiration, nouvelle version, taux), `tms/docs/admin.md` (société : logo, préfixe, validité, conditions ; TVA exonérée/assujettie ; étape « devis » des référentiels ; droits catalogue), `tms/docs/data-dictionary.md`, `tms/docs/install.md` (Gotenberg), `CLAUDE.md` (module Catalogue, Quotes, PdfRenderer), `CHANGELOG.md` 0.4.0, `VERSION`, `tests/e2e/quotes.spec.ts`
- [ ] **Step 1: e2e** — l'admin crée une formation ; depuis une opportunité « Étude », ajout de la formation, « Calculer le montant », création du devis, aperçu PDF (réponse `application/pdf`), envoi (mailer de test), l'opportunité est en « Proposition » ; « Accepter » puis confirmation « Gagné » ; le devis apparaît dans la liste et le taux du mois est 100 %.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — Pest complet, pint, phpstan, lint/types/build, `npm run e2e`, groupe `slow`
- [ ] **Step 5: Commit** — `docs(crm): catalogue and quotes guide, navigation and lot 1d finishing touches`

---

## Ordre d'exécution

1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9. Point d'essai suggéré après la Task 7 (devis envoyés de bout en bout).

## Hors 1d (rappel)

Tableaux de bord, objectifs, CA signé / CA par DAS et fidélisation (1e, reporté après le démarrage du TMS) ; transformation devis accepté → dossier (lot 2) ; facturation (lot 3) ; éditeur de modèles de documents (moteur de documents du lot 2) ; signature électronique du devis (lot 2 à 5).
