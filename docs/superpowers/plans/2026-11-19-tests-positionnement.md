# Tests de positionnement — plan

**Goal:** bibliothèque de tests par DAS/formation, envoi au candidat, lien de dépôt public.
**Spec:** `docs/superpowers/specs/2026-11-19-tests-positionnement-design.md`
**Architecture:** `brochures.kind` dans Catalogue (service public `Brochures`), envois et lien dans `Admissions\Positioning`.

## Global Constraints
- Français partout ; RLS + `BelongsToTenant` sur toute table ; jamais de nom de table Catalogue dans Admissions.
- Token 64 hex, seul le sha256 stocké, comparaison `hash_equals`, même 404/page pour tout lien invalide.
- Fichiers : PDF/JPG/PNG vérifiés par contenu, 20 Mo, `TenantStorage`.

## Review Focus
1. Lien d'un autre organisme (hôte A, token de B) → page invalide.
2. Second dépôt après remise, ou après un nouvel envoi (lien révoqué) → refusé.
3. Fichier déguisé (exe renommé .pdf) → refusé.
4. Un test n'apparaît jamais dans les plaquettes d'un devis ni d'un envoi depuis une fiche.
5. Étape jamais ramenée en arrière par un envoi ou une remise.

### Task 1: type de document dans la bibliothèque
Migration `brochures.kind` (`brochure` | `positioning_test`, défaut `brochure`, CHECK) ; `Brochure::KIND_*` ; `forTrainings`, `forTrainingPage`, `options`, `offered`, liste et formulaire filtrent/portent le type ; `positioningTests(?trainingId, ?dasId)`. Tests : `PositioningTestsLibraryTest`.

### Task 2: envoi du test
Tables `positioning_sendings`, `positioning_sending_versions` ; `PositioningSending`, `PositioningSendings::send()`, `SendPositioningTest`, modèle `positioning_test`, route `POST admissions/candidatures/{c}/positioning/send`, props de la carte. Tests : `PositioningSendTest`.

### Task 3: lien de dépôt
Routes publiques, `PositioningUploadController` (show, download, store), page `public/positioning-test.tsx`, `CandidatureFiles::store(?User)`, limiteur `positioning-upload`. Tests : `PositioningUploadTest`.

### Task 4: RGPD, docs, e2e
Anonymisation des envois, historique, `ModuleBoundariesArchTest`, docs (`admissions.md`, `crm.md` § 4, `data-dictionary.md`, CHANGELOG, CLAUDE.md), e2e `admissions.spec.ts` (envoi puis dépôt par le lien).
