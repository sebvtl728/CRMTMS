# Qualité — dossier Qualiopi

Le menu **Qualité › Qualiopi** rassemble le dossier de certification de l'organisme sur le référentiel national qualité
**2026** (annexe du décret n° 2026-728 du 1er août 2026, en vigueur le 1er novembre 2026 : 7 critères, 33 indicateurs).

> Le texte des indicateurs a été transcrit depuis un document d'information. Vérifiez-le sur Légifrance avant l'audit ;
> l'écran le rappelle. Les seuils des indicateurs 19 et 20 attendent un arrêté.

## 1. Droits

| Droit | Permet | Par défaut |
|---|---|---|
| `qualite.view` — Consulter le dossier Qualiopi | voir les indicateurs, les preuves et exporter le dossier d'audit | administrateur, direction |
| `qualite.manage` — Tenir le dossier Qualiopi | modifier le suivi d'un indicateur, ajouter et supprimer des preuves | administrateur, direction |

Les autres rôles n'ont rien par défaut : l'administrateur accorde ces droits dans **Rôles et droits** (par exemple à la
personne référente qualité). Un organisme qui n'utilise pas le module ne les accorde pas.

## 2. Les 33 indicateurs

La page liste les indicateurs par critère, avec l'avancement (indicateurs **Prêts** sur les indicateurs **applicables**),
les badges « Nouveau », « Renforcé » ou « Précisé » (évolutions de 2026) et le champ d'application (CFA, certifiantes,
alternance, sous-traitance…). Les boutons filtrent par statut ou n'affichent que les indicateurs non applicables.

## 3. Une fiche indicateur

- **Texte officiel** de l'indicateur.
- **Mesures automatiques** : calculées par le TMS à chaque affichage, sans saisie :
  - 1 et 5 : fiches formation actives complètes (objectifs, public, prérequis, méthodes, évaluation, accessibilité) ;
  - 8 : candidatures des 12 derniers mois avec test de positionnement reçu (désistements exclus) ;
  - 12 : taux d'abandon des inscriptions des sessions commencées depuis 12 mois (liste d'attente et annulations exclues) ;
  - 13 : contrats d'alternance avec tuteur désigné ;
  - 30 et 2 : taux de réponse et de satisfaction des questionnaires à chaud et à froid des stagiaires (sessions
    terminées depuis 12 mois ; satisfait = moyenne d'au moins 4/5), et satisfaction des entreprises si le questionnaire
    entreprise est utilisé (`questionnaires.md`).
- **Suivi** : non applicable (justification obligatoire, présentée à l'auditeur), statut (À faire, En cours, Prêt,
  À revoir), responsable, date de revue, notes internes.
- **Preuves** : fichier (PDF, images, documents bureautiques, 20 Mo au plus, type vérifié par le contenu), lien
  (http ou https) ou note. Un fichier s'ouvre dans un nouvel onglet.

## 4. Dossier d'audit

**Exporter le dossier d'audit** télécharge une archive ZIP :

- `sommaire.pdf` : les 33 indicateurs avec leur texte, applicabilité et justification, statut, responsable, date de
  revue, notes, mesures automatiques et liste des preuves (liens et notes en clair, fichiers avec leur chemin dans
  l'archive) ;
- un dossier `indicateur-NN/` par indicateur avec ses fichiers (deux fichiers du même nom sont numérotés).

Chaque export est inscrit au journal d'audit. Si le moteur PDF est indisponible, l'export répond « service
indisponible » et rien n'est téléchargé.
