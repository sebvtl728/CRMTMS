# BPF (bilan pédagogique et financier) — conception courte

Statut : **à valider** par Sébastien avant tout code (questions en fin de document).

## Intention

Chaque organisme déclare chaque année son BPF (Cerfa n° 10443) sur la plateforme Mon Activité Formation, avant le 30 avril, pour l'exercice précédent. Aujourd'hui, CAP FORMATION le remplit à la main à partir de tableaux. Le TMS a déjà presque toutes les données : factures, financements, inscriptions, présences, formateurs et catalogue.

**But** : produire les cadres du BPF d'une société et d'un exercice, chiffres prêts à recopier, avec des contrôles de cohérence. Chaque chiffre renvoie à la liste qui le compose, pour qu'on puisse le vérifier.

**Hors périmètre** :
- pas de dépôt automatique sur la plateforme, qui n'a pas d'API ;
- pas de comptabilité des charges ;
- pas de BPF consolidé multi-sociétés.

À ne pas faire : un chiffre qu'on ne sait pas expliquer. Tout montant ou toute heure doit avoir son détail.

## Où sont les données

| Cadre du BPF | Source dans le TMS | Ce qui manque |
| --- | --- | --- |
| A, B : identification | Société : raison sociale, SIRET, NDA, adresse | — |
| C : origine des produits (HT) | Factures **validées** de l'exercice et leurs avoirs, par destinataire. Un financement prend la ligne de son **type de financeur** (référentiel `funding_type`). Le contractant prend « Entreprises pour leurs salariés » s'il est une entreprise, « Particuliers » s'il est une personne. Les factures libres de co-traitance vont en ligne « Autres organismes de formation ». | Chaque type de financeur doit dire sa ligne C : nouvelle méta `bpf_product` dans le référentiel, réglée par l'administrateur, avec des valeurs proposées par défaut (OPCO → C2, CPF → C2 CPF, France Travail et Région → C3 à C9). |
| D : charges | Absentes du TMS | Saisie à la main par exercice : salaires des formateurs, achats de prestations… (quelques montants). |
| E : formateurs | Formateurs (`trainers.status` : `employee` / `freelance`) ayant au moins une séance dans l'exercice ; heures = durée de leurs séances non annulées | — |
| F-1 : stagiaires par type | Inscriptions avec des heures **réalisées** dans l'exercice (présences, mois clôturés ou non), par catégorie BPF (`enrolments.bpf_category_id`). Nombre de stagiaires distincts et heures réalisées. | Les catégories du référentiel doivent dire leur ligne F-1 (méta `bpf_line`). L'apprenti est à ajouter : un contrat d'apprentissage de l'admission le désigne. |
| F-2 : formations confiées ou reçues en sous-traitance | Lignes co-traitées (`invoice_lines.cotraitant_id`) et heures associées | Une heure « reçue en sous-traitance » n'est pas connue : saisie à la main. |
| F-3 : objectif de la formation | Formation du catalogue : code RNCP, et son niveau (à saisir) | Nouveaux champs sur la formation : **objectif BPF** (RNCP niveaux 6-8 / 5 / 4 / 3 / 2, CQP, RS, autre formation, bilan de compétences, VAE) et code **NSF**. |
| F-4 : spécialités | Code NSF de la formation, heures réalisées par code, les 5 premiers + « autres » | Code NSF (voir F-3). |
| G : formations confiées à d'autres organismes | Factures fournisseurs, absentes du TMS | Saisie à la main. |

## Approches envisagées

1. **Calcul à la volée** (recommandé) : une page « Pilotage › BPF » (société, exercice) calcule les cadres à chaque ouverture. Les seules valeurs stockées sont les saisies à la main (charges, sous-traitance reçue, G) dans `bpf_manual_values`. Une fois le BPF déposé, l'utilisateur peut **figer** l'exercice : un instantané JSON horodaté, gardé tel quel, qu'une correction ultérieure des données ne change plus.
2. **Table de faits remplie par une tâche nocturne** : plus rapide sur de gros volumes, mais les chiffres seraient décalés d'un jour et le code à maintenir double. Inutile à l'échelle de CAP FORMATION.
3. **Export brut des données à traiter dans un tableur** : rapide à faire, mais ne répond pas au besoin. C'est le système actuel.

## Conception (approche 1)

- **Module** `app/Modules/Bpf/`. Il lit Finance par un service public `Finance\Public\BpfProducts` (produits HT par ligne C, avec le détail des pièces), Sessions par `Sessions\Public\BpfActivity` (stagiaires, heures, formateurs, avec le détail) et le catalogue par `Trainings`. Aucun module n'importe Bpf.
- **Droit** : `pilotage.bpf.view` (voir, exporter), `pilotage.bpf.manage` (saisir et figer), sans périmètre ; par défaut, administrateur et direction.
- **Exercice** : par défaut l'année civile, jours de Paris. Option : dates d'exercice de la société (voir question 1).
- **Montants** en centimes entiers (`brick/math`). Les avoirs viennent en déduction. Les produits sont comptés à la **date d'émission** de la facture.
- **Contrôles** affichés en tête de page, chacun avec sa liste à corriger :
  - inscriptions sans catégorie BPF ;
  - formations sans objectif ou sans code NSF ;
  - types de financeur sans ligne C ;
  - heures réalisées d'un stagiaire au-delà des heures prévues ;
  - écart entre total facturé HT et total des lignes C ;
  - formateurs sans statut.
- **Export** : PDF « prêt à recopier » (ordre des cadres du Cerfa) et CSV `;` ; export journalisé `bpf.exported`.
- **Figer** : `bpf_snapshots` (société, exercice, JSON, empreinte sha256, auteur, date). Un exercice figé s'affiche depuis l'instantané, avec la mention « figé le … ». On peut le défiger avec un motif, journalisé.
- **Référentiels** :
  - `funding_type.meta.bpf_product` ;
  - `bpf_category.meta.bpf_line` ;
  - valeurs par défaut posées par migration pour chaque organisme existant ;
  - réglables dans Administration › Référentiels.
- **Catalogue** : `trainings.bpf_objective` (enum) et `trainings.nsf_code` (groupe de spécialités à 3 chiffres, liste officielle INSEE niveau 100 embarquée dans `Catalogue\Nsf` — fait en 0.15.10), saisis sur la fiche formation.

## Questions pour Sébastien

1. **Exercice** : l'exercice comptable de CAP FORMATION est-il l'année civile ? Sinon, je règle les dates d'exercice par société.
2. **Charges (cadre D) et sous-traitance (F-2 reçue, G)** : d'accord pour une saisie à la main par exercice, puisque le TMS ne gère pas les achats ?
3. **Apprentis** : CAP FORMATION a-t-elle des apprentis à déclarer, donc un CFA ? Le contrat d'alternance de l'admission suffirait à les compter.
4. **Répartition des produits** : faut-il pouvoir corriger à la main une ligne C (par exemple un financement mal typé), ou corrige-t-on toujours la donnée source ? Je recommande la donnée source, pour garder la traçabilité.

## Ce qui reste au constructeur

- Les libellés exacts des lignes du Cerfa 10443, à vérifier sur la notice en vigueur de l'exercice déclaré.
- La table NSF, à reprendre de la nomenclature officielle.
- La mise en page du PDF.
