# Guide utilisateur : dossiers, sessions, inscriptions et financements

Ce guide couvre la partie **Formation** du TMS (lot 2a) : les **sessions** de formation, les **dossiers** de formation, l'**inscription** des stagiaires et les **financements**. Il complète `crm.md` (fiches, devis) ; les droits sont décrits dans `admin.md` (§ 10 et § 15).

Le menu de gauche, section **Formation**, donne accès à **Dossiers** (droit « Consulter les dossiers ») et **Sessions** (droit « Consulter les sessions »). Ce que vous voyez suit le **périmètre** de votre droit : *Ses fiches* (vous en êtes le responsable ou le créateur), *Ses DAS* (le DAS de la formation de la session, le DAS du dossier) ou *Tout l'organisme*. Un dossier ou une session hors de votre périmètre est introuvable, même par son adresse.

## 1. Les sessions

Une **session** est une formation du catalogue donnée à des dates précises. Écran **Sessions** : la liste (recherche par numéro ou formation, filtres par état, type, modalité, DAS, formation, responsable et dates) et **Calendrier**, une vue par mois où chaque session s'étend de sa date de début à sa date de fin.

**Nouvelle session** :

- **Formation** du catalogue : ses heures sont reprises dans **Heures prévues** (modifiables) et son DAS devient celui de la session ;
- **Type** :
  - **Intra (une entreprise)** : la session est réservée à une **entreprise** (obligatoire) ; seuls les dossiers dont elle est le contractant y inscrivent des stagiaires ; pas de nombre de places ;
  - **Inter (plusieurs entreprises ou particuliers)** : ouverte à plusieurs dossiers ; **Places** obligatoire ; au-delà, les inscriptions vont sur la **liste d'attente** ;
  - **Individuel (un seul stagiaire)** : un seul stagiaire (inscription non annulée), sans liste d'attente ; **Entreprise (facultatif)** ;
- **Modalité** : Présentiel, Distanciel (classe virtuelle), FOAD / e-learning, Mixte (liste paramétrable, *Administration › Référentiels*) ;
- **Dates** de début et de fin (jours de Paris ; la fin n'est pas avant le début) ;
- **Lieu** : une adresse (obligatoire), **chez le client** ou **distanciel**, avec une précision ou un lien ;
- **Responsable** (vous par défaut), **État** (*Planifiée* ou *Confirmée*) et notes.

Chaque session reçoit un numéro `S-2026-0001` (séquence par organisme et par année de Paris).

**États** : *Planifiée*, *Confirmée*, *En cours*, *Terminée*, *Annulée*. « En cours » et « Terminée » se calculent d'après les dates (du premier au dernier jour inclus) ; ils ne se posent pas à la main. **Annuler la session** demande un **motif** (liste *Motifs d'annulation*) et annule ses inscriptions encore actives (places libérées, parts de prix recalculées) ; une session annulée ne se rouvre pas : créez-en une nouvelle.

Dès qu'une session a des inscriptions, sa formation, son type et son entreprise ne changent plus, et elle ne va pas à la corbeille.

La **page d'une session** montre ses informations, les **places restantes** d'une session inter, les inscrits regroupés par dossier et la liste d'attente dans son ordre. Les noms des inscrits de tous les dossiers sont visibles ; le prix, les financements et le contractant d'un dossier ne le sont qu'avec le droit de consulter ce dossier.

## 2. Les dossiers

Un **dossier** de formation porte la vente : qui achète, quoi, à quel prix, et qui finance. Écran **Dossiers** : la liste (numéro, contractant, formations, prix HT, **reste à financer**, responsable, état), ses filtres, son export CSV, bouton « Exporter (.csv) » (droit « Exporter »).

La colonne **Reste à financer** donne le prix du dossier moins ses financements non refusés, au centime : en orange s'il reste à financer, en rouge « Financements supérieurs au prix : … » si les financements dépassent le prix.

**Nouveau dossier** (ou depuis un devis, § 6) :

- **Société émettrice**, **Contractant** : une entreprise **ou** un particulier, **Contact**, **Responsable** ;
- **DAS du dossier** : obligatoire, proposé d'après la formation de la première ligne ;
- **Lignes** : une **formation** du catalogue (libellé, quantité, unité, prix unitaire HT, remise) ou des **frais** (déplacement, repas, forfait) sans formation ni inscription. Le **prix du dossier** est la somme des lignes HT ;
- **Mode de prix** d'une ligne de formation : *prix par stagiaire* (le montant de la ligne est réparti à parts égales entre les inscrits) ou *prix de groupe* (le prix reste sur la ligne). Il est proposé d'après l'unité (« stagiaire » → par stagiaire ; forfait, jour, heure, session → de groupe) et reste modifiable. Une ligne qui a (ou a eu) des inscriptions, même annulées, ne se supprime pas : les inscriptions et leur historique sont conservés.

Chaque dossier reçoit un numéro `D-2026-0001`.

### Étapes du dossier

*Créé* → *Convention envoyée* → *Convention signée* → *Convocations envoyées* → *En cours* → *Réalisé* ; *Annulé*.

- Les trois premières étapes se posent à la main, avec leur **date** (chacune pas avant la précédente).
- *En cours* et *Réalisé* se calculent d'après les sessions de ses inscriptions (du premier jour de la première au dernier jour de la dernière) ; vous pouvez aussi les poser à la main, ils ne sont alors plus recalculés.
- **Annuler le dossier** demande un motif, est possible jusqu'à *Réalisé* et annule ses inscriptions encore actives.
- Un dossier qui a des inscriptions actives ne va pas à la corbeille : annulez-le d'abord.

La page du dossier montre l'en-tête (état et actions), les étapes, les lignes, les **financements** avec leur alerte, les **inscriptions** par ligne de formation et l'**Historique** : création et étapes (avec le motif d'une annulation), inscriptions et abandons (stagiaire nommé), financements ajoutés, modifiés, supprimés, chacun avec son auteur et son heure. Les mêmes évènements figurent, selon vos droits, dans l'historique des fiches du CRM : les dossiers d'une entreprise ou d'une personne (contractant ou contact) et les inscriptions d'une personne.

## 3. Inscrire des stagiaires

**Inscrire un stagiaire** (page du dossier ou de la session ; droit « Créer et modifier les sessions et inscriptions » sur la session et droit de consulter le dossier) :

1. choisissez la ligne de formation et la session (seules les sessions de cette formation ouvertes au dossier sont proposées) ;
2. **Rechercher une personne** : la recherche porte sur toutes les personnes de l'organisme (nom, prénom, date de naissance, entreprise actuelle), même hors de votre périmètre, pour ne pas créer de doublon ; les salariés du contractant apparaissent en premier ;
3. sinon, créez la personne sur place : c'est une personne ordinaire du CRM (rattachée au contractant si vous l'indiquez, avec le DAS de la formation). Si des **personnes similaires** existent, le formulaire les montre avant de créer.

Une personne n'est inscrite qu'**une fois** sur une même session (sauf inscription annulée). Une session intra refuse un dossier d'un autre contractant.

Sur l'inscription : dates d'entrée (début de la session par défaut) et de sortie, heures prévues (celles de la session), catégorie de stagiaire (BPF), **besoins d'adaptation / situation de handicap** et notes. Ces deux derniers champs sont des **données personnelles** : ils ne sont visibles que de qui peut modifier l'inscription, ne figurent jamais en clair dans le journal et sont effacés par l'anonymisation.

### Liste d'attente

Quand une session inter est complète (inscrits et confirmés au nombre de places), l'inscription suivante passe **En attente**, avec son rang d'arrivée. Une désinscription ne fait monter **personne** automatiquement : **Promouvoir** fait passer un stagiaire en attente sur une place libre (refusé si la session est complète).

### États d'une inscription

*Inscrit*, *En attente*, *Confirmé* (**Confirmer**), *Abandon* (**Abandon**), *Annulé* (**Désinscrire** : libère la place, sans promouvoir personne). **Modifier** change les dates, la catégorie BPF, les besoins d'adaptation et les notes.

### Prix par stagiaire

Sur une ligne au prix par stagiaire, chaque inscription porte sa **part** : le montant de la ligne divisé entre les inscrits, confirmés et abandons, au centime ; le reste de la division va aux premières inscriptions (1 000 € pour 3 stagiaires : 333,34 + 333,33 + 333,33). Les parts sont recalculées à chaque inscription et désinscription ; un stagiaire en attente n'a pas de part ; un abandon garde la sienne.

## 4. Abandon et règle d'abandon

**Abandon** sur une inscription (**Enregistrer l'abandon**) : la **date de sortie** et le **motif** (liste *Motifs d'abandon*). Un motif peut porter le drapeau **Force majeure** (par défaut : Maladie, Accident).

La **règle d'abandon** de l'organisme (*Administration › Règle d'abandon*) est *prorata seulement* (par défaut) ou *prorata et dédit de X %* ; un dossier peut la remplacer par la sienne (formulaire du dossier). La page du dossier affiche la règle qui s'applique. **Force majeure : jamais de dédit.** Le lot 2a enregistre et affiche la règle ; les heures réalisées (émargement) et la facturation l'appliqueront.

## 5. Financements

Section **Financements** de la page du dossier (droit de modifier le dossier) : **Ajouter un financement** :

- **Financeur** : une entreprise du CRM (OPCO, l'entreprise elle-même, France Travail, Région…) **ou** une personne (stagiaire qui paie lui-même, CPF) ; cliquez sur un résultat de la recherche pour le choisir ;
- **Type** (liste *Types de financeur* : OPCO, Entreprise, CPF, France Travail, Région, Personnel…) ;
- **Montant HT** (au format français), **État** : *À demander*, *Demandé*, *Accordé*, *Refusé* ; **N° de prise en charge**, **date d'accord** ;
- cible facultative : une **ligne** du dossier ou une **inscription** (sa part, pour une ligne au prix par stagiaire).

L'alerte compare le prix et les financements **non refusés** : « Reste à financer : 900,00 € » ou « Financements supérieurs au prix : 100,00 € », pour le dossier et pour chaque ligne ou inscription visée. Un financement **refusé** ne compte plus : reportez son montant sur un autre financeur ou laissez-le à financer (aucun report automatique). Le financement d'une inscription annulée reste, signalé « Financement à reporter ».

## 6. Créer un dossier depuis un devis accepté

Sur un devis **accepté**, **Créer le dossier** (droit « Créer et modifier les dossiers ») ouvre un assistant en trois écrans :

1. **Récapitulatif** : contractant, contact, lignes et montant repris du devis (le devis lui-même n'est jamais modifié) ; le mode de prix de chaque ligne de formation ;
2. **Sessions** : pour chaque ligne de formation, une **session existante** de cette formation, une **nouvelle** session (intra ou individuelle pré-remplie pour l'entreprise) ou **Plus tard** ;
3. **Stagiaires** : cherchés dans le CRM (les salariés du contractant en premier) ou créés sur place, puis inscrits ; une même personne nouvelle n'est créée qu'une fois, même inscrite sur plusieurs lignes.

Tout est créé d'un seul coup : le dossier (DAS de l'opportunité), ses lignes, les sessions nouvelles, les inscriptions et un financement initial du prix total, *À demander*, payé par le contractant, du type de financeur marqué « par défaut » pour une entreprise ou pour un particulier (*Référentiels* › *Types de financeur*, champ **Financement initial d'un dossier** ; « Entreprise » et « Personnel » à l'installation). Si aucun type n'est marqué pour ce cas, le dossier est créé sans financement. Un devis ne donne **qu'un dossier** : ensuite le bouton devient **Voir le dossier** (un double clic ou un second onglet ne crée pas de doublon). Un dossier mis à la corbeille bloque toujours la création d'un nouveau dossier pour ce devis : restaurez-le.

## 7. Accueil

La carte **Sessions qui démarrent dans les 7 jours** de l'**Accueil** liste les sessions de votre périmètre qui commencent d'aujourd'hui à dans 7 jours (jours de Paris), ni annulées ni à la corbeille, avec leur formation, leur numéro, leur type, les places prises d'une session inter et leurs dates ; un clic ouvre la session.

## 8. Les stagiaires dans le CRM

Un stagiaire est une personne du CRM :

- sa fiche montre le bloc **Formations suivies** (ses inscriptions) et, dans l'**Historique**, ses inscriptions et les dossiers dont elle est contractante ou contact ; une entreprise montre le bloc **Dossiers** ;
- **corbeille** : une personne inscrite peut être mise à la corbeille ; ses inscriptions restent (badge « Fiche à la corbeille ») et reviennent avec elle à la restauration ; elle ne peut plus être inscrite tant qu'elle est à la corbeille ;
- **fusion** : sessions, dossiers, inscriptions et financements suivent la fiche conservée ; deux personnes inscrites chacune sur la même session ne se fusionnent pas tant que l'une des deux inscriptions n'est pas annulée ; cas rare : si l'une des deux est inscrite sur la session de l'autre pendant la fusion elle-même (deux utilisateurs au même instant), la base refuse le doublon et la fusion est annulée sans rien écrire, avec un message d'erreur générique ; relancez-la : le refus habituel s'affiche alors. De même, **Annuler la session** peut répondre « Une inscription vient d'être ajoutée : réessayez. » si un stagiaire est inscrit au même instant ;
- **annulation d'un import** : une entreprise ou une personne qui porte une session, un dossier, une inscription ou un financement est conservée ;
- **RGPD** : l'export des données d'une personne liste ses inscriptions (avec catégorie BPF et part du prix), ses dossiers (lignes et prix de ceux dont elle est la contractante particulier) et les financements qu'elle paie (type, montant, état, n° et date d'accord, notes) ; une personne inscrite, contractante, contact ou financeur dans la durée de conservation n'est jamais proposée à l'anonymisation (`admin.md` § 11) ; l'anonymisation efface ses besoins d'adaptation, les notes de ses inscriptions, des financements qui la concernent et de ses dossiers de particulier (voir `crm.md` § 13).
