# Facture libre et co-traitance — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** facture créée librement (sans dossier), lignes pouvant désigner un co-traitant, et suivi des reversements aux co-traitants.

**Architecture:** une facture porte une origine (`dossier` | `free`). Une facture libre réutilise toute la chaîne existante (numérotation, validation, PDF, journal chaîné, inaltérabilité, envoi, règlements, relances, avoirs) ; seuls les contrôles propres au dossier sont sautés. Visibilité centralisée dans `InvoiceAccess`. Reversements dans une table à part (`cotraitant_payouts`), jamais sur la facture figée.

**Tech Stack:** Laravel 12 / PHP 8.4, PostgreSQL 17 (RLS), Inertia React 19 TS, Pest.

**Spec:** `docs/superpowers/specs/2026-11-20-facture-libre-mandataire-design.md`

## Global Constraints

- Montants en centimes entiers (`brick/math`), jamais de flottant ; `Money::parse` / `Money` pour la saisie et l'affichage.
- Toute nouvelle table : `tenant_id uuid not null`, `Rls::enable`, modèle `BelongsToTenant`, UUID v7.
- Finance ne nomme aucune table du CRM ni de Sessions (`ModuleBoundariesArchTest`) : co-traitant et destinataire = id de fiche, lu par `Crm\Public\Fiches`, sans clé étrangère.
- Hash des pièces existantes **inchangé** : `Integrity::canonical` n'ajoute les nouvelles clés que si elles ont une valeur autre que la valeur par défaut.
- Interface et messages en français ; CSV `;` ; erreurs utilisateur par `UserFacingHttpException` ou validation.
- Migrations via `--database=pgsql_owner` ; tests locaux ciblés + `tests/Arch` ; jamais deux suites Pest en même temps.
- `notes`, `description` déjà dans `$auditPersonal` ; ajouter `notes` de `CotraitantPayout`.

## Review Focus

1. Utilisateur au périmètre « Ses fiches » / « Ses DAS » : ne voit jamais une facture libre (liste, page, PDF, relances, À reverser) — 404.
2. Avoir d'une facture libre : même destinataire et société, plafonné au restant de la facture, lignes co-traitées reprises en négatif (le dû au co-traitant baisse).
3. Reversement supérieur au reste à reverser (y compris deux saisies concurrentes) : refusé sous verrou.
4. Hash et vérification d'intégrité des factures de dossier déjà validées : identiques après migration.
5. Destinataire ou co-traitant inexistant / anonymisé / supprimé : refusé à la création et à l'enregistrement des lignes ; une pièce validée garde son snapshot.

---

### Task 1: Schéma, modèle, hash

**Files:**
- Create: `tms/database/migrations/2027_01_25_000001_add_free_invoices.php`
- Modify: `tms/app/Modules/Finance/Invoices/Invoice.php`, `InvoiceLine.php`, `tms/app/Modules/Finance/Integrity.php`
- Test: `tms/tests/Feature/Finance/FreeInvoicesTest.php`

**Interfaces:**
- Produces: `Invoice::ORIGIN_DOSSIER = 'dossier'`, `Invoice::ORIGIN_FREE = 'free'`, `Invoice::isFree(): bool` ; colonnes `invoices.origin` (string 10, défaut `dossier`, CHECK `in ('dossier','free')`), `invoices.dossier_id` nullable + CHECK `invoices_origin_dossier_check` `(origin = 'dossier') = (dossier_id is not null)`, `invoices.buyer_reference` (string 60 null), `invoices.service_code` (string 60 null), `invoice_lines.cotraitant_id` (uuid null, sans FK). Une facture libre a `rhythm = 'free'`, `basis = 'free'`.

- [ ] Test `free invoice columns and checks` : insertion d'une facture `origin=free` sans `dossier_id` acceptée ; `origin=dossier` sans dossier → `QueryException` ; `origin=free` avec dossier → `QueryException`.
- [ ] Test `hash of a dossier invoice is unchanged` : `Integrity::canonical` d'une facture de dossier validée ne contient ni `origin`, ni `buyer_reference`, ni `service_code`, ni `cotraitant_id` ; pour une facture libre avec `buyer_reference = 'EJ-123'` et une ligne co-traitée, le canonique contient `"origin":"free"`, `"buyer_reference":"EJ-123"` et `cotraitant_id`.
- [ ] Lancer : `docker compose exec app php artisan test --filter=FreeInvoicesTest` → FAIL.
- [ ] Migration (down : refuse s'il existe une facture libre) ; modèle ; `canonical` : ajout conditionnel.
- [ ] `migrate --database=pgsql_owner` ; test → PASS ; `--filter=InvoiceIntegrityTest` → PASS.
- [ ] Commit `feat(finance): free invoice origin, buyer references and co-contractor lines`.

### Task 2: Visibilité centralisée

**Files:**
- Create: `tms/app/Modules/Finance/Invoices/InvoiceAccess.php`
- Modify: `InvoiceController.php` (`find`, `manages`, `validates`, `listQuery`), `Reminders/ReminderController.php`, `Reminders/Reminders.php`, `Payments/Payments.php` (liste des factures ouvertes d'un payeur)
- Test: `tms/tests/Feature/Finance/FreeInvoicesTest.php`

**Interfaces:**
- Produces: `InvoiceAccess::allows(User $user, string $permission, Invoice $invoice, bool $withTrashed = false): bool` (dossier : `Billing::allows` ; libre : `Scopes::of($user, $permission) === AccessScope::All`) ; `InvoiceAccess::scope(Builder $query, User $user, string $permission, array $options = []): Builder` (dossiers de `Billing::dossiersFor` **ou** `origin = free` si périmètre « Tout »).

- [ ] Tests : admin voit la facture libre (liste `/finance/invoices`, page, PDF) ; utilisateur `finance.invoices.view` sur « Ses DAS » → absente de la liste, 404 sur page et PDF ; relances : une facture libre en retard apparaît pour l'admin.
- [ ] FAIL → implémenter → PASS ; `--filter="InvoiceDraftsTest|InvoiceValidationTest|RemindersTest|PaymentsTest"` → PASS.
- [ ] Commit `feat(finance): invoice access covers free invoices (scope « Tout » only)`.

### Task 3: Création et édition d'un brouillon libre

**Files:**
- Create: `tms/app/Modules/Finance/Invoices/FreeInvoices.php`
- Modify: `InvoiceDrafts.php` (`update` : `lines.*.cotraitant_id`, en-tête libre ; `recompute` : 409), `InvoiceRecipient.php` (`dossier_number` null sans dossier), `InvoiceController.php` (`storeFree`, `update`), `tms/routes/finance.php`
- Test: `tms/tests/Feature/Finance/FreeInvoicesTest.php`

**Interfaces:**
- Produces: `FreeInvoices::create(User $user, array $data): Invoice` — `$data` : `company_id`, `recipient_type` (`organisation`|`person`), `recipient_id`, `buyer_reference?`, `service_code?`, `period_from?`, `period_to?` ; brouillon `kind=invoice`, `origin=free`, sans ligne. Route `POST finance/invoices/free` (`can:finance.invoices.manage`, périmètre « Tout » sinon 403). `InvoiceDrafts::update` accepte en plus, pour une facture libre, `buyer_reference`, `service_code`, `period_from`, `period_to` et `lines.*.cotraitant_id` (organisation existante, non supprimée, via `Fiches::organisation`) ; une facture de dossier refuse `cotraitant_id` (erreur de validation).
- Messages : `FreeInvoices::NO_RECIPIENT = 'Choisissez le client à facturer.'`, `FreeInvoices::NOT_ALL = 'Une facture libre exige de voir toutes les factures (périmètre « Tout »).'`, `InvoiceDrafts::FREE_NO_RECOMPUTE = 'Une facture libre ne se recalcule pas : modifiez ses lignes.'`, `InvoiceDrafts::NO_COTRAITANT = 'Choisissez un co-traitant existant.'`.

- [ ] Tests : création → 302 vers la page du brouillon, `origin=free` ; destinataire inconnu → erreur `recipient_id` ; société inexistante → erreur `company_id` ; périmètre « Ses DAS » → 403 ; mise à jour avec deux lignes dont une co-traitée → totaux recalculés, `cotraitant_id` enregistré ; co-traitant inconnu → erreur `lines.1.cotraitant_id` ; recalcul → 409 `FREE_NO_RECOMPUTE` ; `cotraitant_id` sur une facture de dossier → erreur.
- [ ] FAIL → implémenter → PASS.
- [ ] Commit `feat(finance): create and edit free invoice drafts`.

### Task 4: Validation, avoir, PDF

**Files:**
- Modify: `InvoiceValidator.php`, `CreditNotes.php`, `InvoicePdf.php`, `tms/resources/views/documents/invoice.blade.php`
- Test: `tms/tests/Feature/Finance/FreeInvoicesTest.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces: `validate()` sur une facture libre : ni `lockDossier`, ni plan, ni `followDossier` ; contrôles : total > 0, au moins une ligne, destinataire présent (`Fiches::billingIdentity` non null), société active. Avoir d'une facture libre : verrou de la facture corrigée (`lockForUpdate`), un seul brouillon d'avoir ouvert par facture corrigée, reprend `origin`, `buyer_reference`, `service_code` et `cotraitant_id` des lignes ; contrôle au restant de la facture corrigée seulement. PDF : « N° d'engagement » (`buyer_reference`), « Code service » (`service_code`), mention « Facture émise par <société> , mandataire du groupement » si une ligne est co-traitée, et « Réalisé par <nom du co-traitant> » sous la ligne (nom lu par `Fiches::organisationLabels`, figé dans `recipient_snapshot['cotraitants']` à la validation).

- [ ] Tests : validation d'une facture libre → numéro de la séquence des factures, journal + hash, PDF archivé (Gotenberg simulé : le HTML contient `N° d'engagement`, `EJ-123`, `mandataire du groupement`, `Réalisé par`) ; `Integrity::verify()` OK ; facture libre sans ligne → 409 ; avoir partiel → lignes négatives co-traitées, plafonné (`OVER_CORRECTED`) ; facture validée : `update` sur `buyer_reference` → exception du déclencheur.
- [ ] FAIL → implémenter → PASS ; `--filter="InvoiceValidationTest|InvoiceIntegrityTest|InvoiceSendTest"` → PASS.
- [ ] Commit `feat(finance): validate, credit and print free invoices`.

### Task 5: Reversements aux co-traitants

**Files:**
- Create: `tms/database/migrations/2027_01_25_000002_create_cotraitant_payouts.php`, `tms/app/Modules/Finance/Payouts/{CotraitantPayout,CotraitantPayouts,PayoutController,PayoutExport}.php`, `tms/resources/js/pages/finance/payouts.tsx`
- Modify: `tms/routes/finance.php`, `tms/resources/js/components/app-sidebar.tsx` (« À reverser », icône `HandCoins`, droit `finance.payments.manage`), `FinanceServiceProvider` (audit, `$auditPersonal = ['notes']`)
- Test: `tms/tests/Feature/Finance/CotraitantPayoutsTest.php`

**Interfaces:**
- Produces: table `cotraitant_payouts` (`id`, `tenant_id`, `invoice_id` FK `invoices`, `cotraitant_id` uuid, `amount_cents` bigint CHECK > 0, `paid_on` date, `reference` string 100 null, `notes` text null, `created_by`, timestamps) ; `CotraitantPayouts::owed(User $user, array $filters): list<array{invoice_id, number, issued_on, cotraitant_id, cotraitant, owed_cents, paid_out_cents, left_cents, buyer_paid: bool, buyer_left_cents}>` — par (facture validée, co-traitant) : somme TTC des lignes co-traitées de la facture et de ses avoirs validés ; `CotraitantPayouts::record(User $user, string $invoiceId, string $cotraitantId, array $data): CotraitantPayout` (transaction, verrou de la facture, refus si montant > reste : `TOO_MUCH = 'Ce reversement dépasse le reste à reverser (%s).'`) ; `delete(CotraitantPayout $payout): void`. Routes `GET finance/payouts`, `POST finance/payouts`, `DELETE finance/payouts/{payoutId}`, `GET finance/payouts-export` (CSV `;`), toutes `can:finance.payments.manage` et périmètre « Tout ». Filtres : `state` (`open` par défaut, `all`), `cotraitant`.

- [ ] Tests : ligne co-traitée 1 000,00 € HT exonérée → reste 1 000,00 € ; avoir de 200 € sur cette ligne → reste 800 € ; reversement 500 € → reste 300 € ; 400 € → erreur `TOO_MUCH` ; suppression → reste 800 € ; `buyer_paid` suit `Balances` ; export CSV : en-tête français, séparateur `;`, montants `1 000,00` ; 403 sans `finance.payments.manage`.
- [ ] FAIL → implémenter (page : tableau, filtre, dialogue « Marquer reversé » montant / date / référence / note, suppression) → PASS.
- [ ] Commit `feat(finance): track payouts to co-contractors`.

### Task 6: Écrans de la facture libre

**Files:**
- Modify: `tms/resources/js/pages/finance/invoices/index.tsx` (bouton « Nouvelle facture libre » + dialogue : société, client — recherche `Fiches::searchOrganisations` par une route `GET finance/clients?q=` `throttle:finance-lookup` —, références acheteur), `.../invoices/show.tsx` (champs N° d'engagement, Code service, période ; colonne « Co-traitant » sur les lignes d'un brouillon libre ; libellé « Facture libre » à la place du dossier), `InvoiceRows.php` (`dossier` null pour une facture libre), `InvoiceExport.php` (colonne Dossier = « Libre »)
- Test: `tms/tests/Feature/Finance/FreeInvoicesTest.php` (props Inertia)

- [ ] Tests : page d'une facture libre → props `invoice.origin = 'free'`, `invoice.dossier = null`, `cotraitants` (options) ; liste → ligne « Libre » ; `GET finance/clients?q=Rég` → organisations correspondantes (nom, id), 403 sans droit.
- [ ] FAIL → implémenter → PASS ; `docker compose run --rm vite sh -c "npm run lint && npm run types && npm run build"` → OK.
- [ ] Vérification manuelle dans le navigateur : créer une facture libre « Région » avec deux lignes (une co-traitée), valider, voir le PDF, marquer un reversement.
- [ ] Commit `feat(finance): free invoice screens`.

### Task 7: Documentation et livraison

**Files:**
- Modify: `tms/docs/finance.md` (nouvelle section « Facture libre et co-traitance » : création, références Chorus, co-traitants, À reverser, financement « facturé hors TMS » pour éviter la double facturation, visibilité « Tout »), `tms/docs/data-dictionary.md`, `CLAUDE.md` (puce « Facture libre » dans « Module Finance »), `tms/CHANGELOG.md` (« Non publié › Ajouté »)

- [ ] `vendor/bin/pint`, `phpstan analyse --memory-limit=1G`, `php artisan test tests/Arch` → verts.
- [ ] Commit `docs(finance): free invoices and co-contractor payouts`.
