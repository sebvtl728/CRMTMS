# Convention de formation en alternance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** à « Enregistrer le contrat » (étape Admis), aperçu PDF de la convention d'alternance du bon type ; émission automatique à la création du dossier ; deux modèles de documents modifiables.

**Architecture:** deux `DocumentType` (`apprenticeship_convention`, `professionalisation_convention`) dans Documents ; le contrat parvient à Documents par un registre `Sessions\Public\Paperwork::registerAlternance` déclaré par Admissions ; émission par un nouveau crochet `Sessions\Public\DossierHooks::onCreatedFromCandidature` (appelé par `Admission::createDossier`), écouté par Documents ; aperçu par un registre Core `Core\Documents\Previews` (Documents y déclare `alternance_convention`, Admissions l'appelle). Aucune exception d'architecture.

**Spec:** `docs/superpowers/specs/2026-11-25-convention-alternance-design.md`.

## Global Constraints
- Français ; jours de Paris ; `{ }` littéraux remplacés par `DocumentTexts::fill`, jamais de balise.
- Admissions n'importe jamais Documents, Documents n'importe jamais Admissions (`ModuleBoundariesArchTest`).
- Tests : `docker compose exec app php artisan test --filter=…` (checkout principal, `local-current`).

## Rulings (écarts à la spec, décidés ici)
- Pas d'exception d'architecture : registres Sessions et Core à la place de `Documents\Public` (spec § Frontières).
- Échec de l'émission automatique : journalisé par `DossierHooks` ; pas de message à l'écran, le bloc Documents du dossier garde « Émettre » (spec : message « Convention non émise »).

## Review Focus
- Contrat de professionnalisation → texte professionnalisation (jamais l'autre).
- Dossier en alternance : convention classique refusée (409), et l'inverse.
- Contractant particulier sur un dossier en alternance : 409 clair.
- Aperçu sans session visée ni prix : « à compléter », jamais « 0,00 € ».
- Dossier créé par devis (pas de candidature) : crochet candidature non appelé.

### Task 1: le contrat d'alternance dans `Paperwork::dossier`
Files: `Sessions/Public/Paperwork.php` (`registerAlternance(Closure(string): ?array)`, clé `alternance`), `Admissions/AdmissionsServiceProvider.php`, `Admissions/Alternance/AlternanceFacts.php` (`forDossier(string): ?array{contract_type, starts_on, ends_on, apprentice_person_id, employer_organisation_id, tutor_person_id, opco_organisation_id, rncp_code}`). Test `tests/Feature/Admissions/AlternanceConventionTest.php` : `it('exposes the alternance contract of a dossier to the paperwork')`.

### Task 2: types, textes, règles d'émission, PDF
Files: `Documents/Texts/DocumentType.php`, `DocumentTexts.php` (blocs + champs), `DocumentIssuer.php` (`check`, `recipient`), `DocumentPdf.php` (valeurs alternance, gabarit `training-convention`), `DossierDocumentsBlock.php`, `DocumentController.php`, `SendDocument.php`, `DocumentMailTemplates.php`. Tests : émission apprentissage (texte, apprenti, tuteur, OPCO), professionnalisation, convention classique refusée en alternance, type d'alternance refusé sans contrat, modèles listés dans Modèles de documents.

### Task 3: émission à la création du dossier
Files: `Sessions/Public/DossierHooks.php` (`onCreatedFromCandidature`, `createdFromCandidature`), `Sessions/Public/Admission.php::createDossier` (appel), `Documents/DocumentsServiceProvider.php` (crochet). Test : créer le dossier d'une candidature en alternance → convention d'apprentissage émise ; sans contrat → rien.

### Task 4: aperçu au clic
Files: `Core/Documents/Previews.php` (registre `register(string, Closure(array): string)`, `render(string, array): string`), `Documents/AlternancePreview.php`, `Admissions/Alternance/AlternanceConventionController.php` (GET `admissions/candidatures/{candidature}/convention-alternance.pdf`, droit consulter), `AlternanceController` (flash `preview_url` à l'étape Admis), `resources/js/pages/admissions/candidatures/show.tsx` (bouton + ouverture). Tests : aperçu PDF (Gotenberg simulé) avec « Aperçu - non émis », « à compléter » ; 409 sans contrat.

### Task 5: documentation
`tms/docs/documents.md`, `tms/docs/admissions.md`, `tms/docs/data-dictionary.md` (types), `CLAUDE.md` (Documents), `tms/CHANGELOG.md`.
