# Livret d'apprentissage — conception courte

Statut : **partie A faite** (validée le 23/11/2026) ; partie B décrite pour mémoire, conçue en détail après A.

## Intention

Aujourd'hui, les formateurs de CAP FORMATION remplissent à la main un livret d'apprentissage de 60 pages par apprenti (exemple : « livret TP EPR - apprenti.docx »). Il contient les identités, les partenaires, le parcours, le planning, des textes fixes, un suivi mensuel, des grilles de compétences et les visites en entreprise. Presque toutes les données d'en-tête existent déjà dans le TMS. **But : gagner du temps** sans changer d'abord les habitudes. Le TMS produit le livret déjà rempli (A), puis le suivi passe en ligne (B).

## Approches (choisie : A puis B)

- **A. Livret pré-rempli en PDF** : tout ce que le TMS sait est imprimé, et le reste se remplit à la main comme aujourd'hui. Rapide, sans changement d'usage.
- **B. Suivi et grilles dans le TMS** : le formateur saisit, le tuteur et l'apprenti répondent par lien sécurisé, signature en ligne, livret complet régénéré.
- C. Tout d'un bloc : écarté, trop gros avant le test de décembre.

## Partie A

**Où** : module Admissions (il porte le contrat d'alternance), dossier `Admissions/Booklet/`. Bouton **« Livret d'apprentissage (PDF) »** sur la carte Alternance de la candidature, dès qu'un contrat existe. Droit : `admissions.candidatures.view` sur la candidature. Rendu à la demande par `PdfRenderer` (jamais archivé).

**Contenu, dans l'ordre du modèle CAP** :

1. **Couverture** : entreprise, apprenti, période du contrat, titre visé.
2. **Partenaires** :
   - apprenti : identité, naissance, adresse, contact ;
   - représentant légal (candidature) ;
   - employeur : raison sociale, activité (secteur), adresse, contact ;
   - maître d'apprentissage : le tuteur du contrat ;
   - CFA : la société par défaut ;
   - équipe : voir réglages ;
   - OPCO du contrat.
3. **Équipe pédagogique** :
   - rôles et noms tirés des réglages ;
   - formateurs et domaines tirés des séances de la session (formateur et intitulé du module).
4. **Parcours individualisé** :
   - modules de la formation et leurs heures (ou modules de la session si elle en a) ;
   - case **Dispensé** cochée selon la candidature ;
   - totaux : heures en centre = séances prévues de la session ; heures en entreprise = jours des périodes en entreprise × 7 h, sans quoi « à compléter ».
5. **Planning prévisionnel** : mois par mois du contrat, « Centre » (mois avec séances), « Entreprise » (périodes en entreprise) ou les deux.
6. **Annexes PDF** fusionnées telles quelles : statut de l'apprenti, règlement intérieur, règlement du CFA, engagements. L'organisme les dépose une fois dans les réglages.
7. **Pages de suivi mensuel**, vierges mais **déjà titrées** :
   - mois et année ;
   - « Travaux effectués en entreprise » ou « en centre de formation » selon le planning ;
   - semaines 1 à 5, cadres apprenti / tuteur ou formateur, quatre signatures.
8. **Grilles d'évaluation** : compétences de la formation (voir ci-dessous), colonnes A / ECA / NA centre et entreprise, vides.
9. **Suivis en entreprise** : tableau vide (date, points abordés, émargements).

**Données nouvelles** :

- **Grille de compétences d'une formation** (Catalogue, `training_competences`) : arbre à trois niveaux.
  - Niveaux : activité type, puis compétence, puis savoir-faire ou connaissance.
  - Saisie : un onglet « Compétences » de la fiche formation, dans une zone de texte où chaque niveau est marqué par un préfixe (`AT`, `CP`, `-`). C'est simple à coller depuis un Word.
  - Je reprends une fois la grille EPR de l'exemple, dans la base de dev.
  - Sert aussi plus tard à l'indicateur Qualiopi 7 et à la partie B.
- **Modules dispensés** d'une candidature (`candidatures.dispensed_module_ids`, jsonb) : cases sur la carte Alternance.
- **Réglages du livret** (Administration › Admissions) :
  - équipe : lignes rôle, nom, téléphone, e-mail ;
  - annexes PDF déposées (20 Mo max, contenu vérifié, `TenantStorage` zone `booklet`) ;
  - mot du directeur : texte libre.

**Hors A** : aucune saisie du suivi, aucune signature en ligne, aucun envoi par e-mail.

**Frontières** : Admissions lit la formation et sa grille par `Catalogue\Trainings` (service public), et les séances et périodes par `Sessions\Public\Admission`, avec de nouvelles méthodes en lecture seule. Aucune table d'un autre module n'est lue directement.

**RGPD** : le livret n'est jamais stocké. Les annexes sont des documents de l'organisme, sans donnée personnelle. Le jsonb des dispenses n'en contient pas.

## Partie B (pour mémoire)

- Le formateur saisit les grilles A / ECA / NA (centre) et les visites en entreprise.
- Le tuteur et l'apprenti reçoivent chaque mois un lien sécurisé, comme pour l'émargement en ligne :
  - ils remplissent leur page de suivi et la colonne « entreprise » ;
  - ils signent en ligne (dessin, code par e-mail).
- Rappels automatiques.
- Le livret PDF reprend tout ce qui a été saisi.
- Mesures Qualiopi (indicateurs 11 et 28).

## Laissé au constructeur

Mise en page exacte, ordre fin des sections, libellés des boutons.
