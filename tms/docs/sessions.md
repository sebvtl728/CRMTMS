# Guide utilisateur : dossiers, sessions, inscriptions et financements

Ce guide couvre la partie **Formation** du TMS (lot 2a) : les **sessions** de formation, les **dossiers** de formation, l'**inscription** des stagiaires et les **financements**. Il complète `crm.md` (fiches, devis) ; les droits sont décrits dans `admin.md` (§ 10 et § 15).

Le menu de gauche, section **Formation**, donne accès à **Dossiers** (droit « Consulter les dossiers ») et **Sessions** (droit « Consulter les sessions »). Ce que vous voyez suit le **périmètre** de votre droit : *Ses fiches* (vous en êtes le responsable ou le créateur), *Ses DAS* (le DAS de la formation de la session, le DAS du dossier) ou *Tout l'organisme*. Un dossier ou une session hors de votre périmètre est introuvable, même par son adresse.

## 1. Les sessions

Une **session** est une formation du catalogue donnée à des dates précises. Écran **Sessions** : la liste (recherche par numéro, intitulé, lieu ou formation, filtres par état, type, modalité, DAS, formation, responsable et dates) et **Calendrier**, une vue par mois : une session qui a des **séances** n'apparaît que les jours de ses séances ; sinon (modules en intervalle) elle apparaît les **jours ouvrés** de ses dates, du lundi au vendredi hors jours fériés et périodes de fermeture. Les week-ends, jours fériés (« Férié : … ») et fermetures (« Fermeture : … ») sont grisés et nommés.

**Nouvelle session** :

- **Intitulé** (facultatif, 150 caractères au plus) : un nom pour la session, par exemple « Commis de cuisine – entrée du 30 novembre 2026 ». Il est affiché partout où la session apparaît (liste, calendrier, page, fiches, dossiers, assistant, accueil, historique, export RGPD) et la recherche de la liste le trouve ; sans intitulé, la session se lit « <formation> (S-2026-0001) » ;
- **Formation** du catalogue : son DAS devient celui de la session et ses **modules** sont copiés sur la session (voir « Modules et onglet Planning ») ; **Heures prévues** (reprises de la formation, modifiables à la création) ne sert qu'à une formation sans module : pour une formation qui a des modules, le champ est grisé et indique leur somme, qui devient les heures de la session (d'autres heures saisies sont refusées, jamais remplacées en silence ; de même pour une session créée depuis un devis accepté) ;
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

### Modules et onglet Planning (0.8.0)

À sa création, une session reçoit une copie des **modules** de sa formation (intitulé, heures prévues, modalité, ordre) ; une formation sans module donne un module unique portant son intitulé et les heures prévues de la session. Ensuite, les modules de la session se modifient **sans toucher au catalogue**, dans l'onglet **Planning** de la page de la session, section **Modules** (droit « Créer et modifier les sessions et inscriptions » sur la session) : **Ajouter un module**, le **modifier** (intitulé, heures prévues — « 7 » ou « 3,5 », plus de zéro —, modalité, dates), le **supprimer**, **changer de mode**.

- Les heures prévues d'un module sont gardées en **minutes** (« 3,5 » h = 3 h 30) ; les **heures prévues de la session** sont la **somme de ses modules**, recalculées à chaque changement : elles ne se saisissent plus dans le formulaire de modification de la session. Si des stagiaires étaient prévus au-delà de la nouvelle somme, un **avertissement** le signale ; leurs inscriptions ne changent pas (à vous de les revoir).
- **Mode de datation** : **Intervalle de dates** (début, fin, heures ; sans séances : les dates du module restent dans celles de la session, celles de la session par défaut) ou **Séances** (séances datées). Le mode ne change plus, et le module ne se supprime plus, dès qu'il a des séances ou des heures saisies. Une session peut mélanger les deux modes.
- Si les dates de la session changent ensuite, un module en intervalle qui en sort est **signalé** (« Hors des dates de la session »), jamais déplacé.
- Changer la formation d'une session (possible tant qu'elle n'a pas d'inscrits) remplace ses modules par ceux de la nouvelle formation.
- Les sessions créées avant la 0.8.0 ont reçu un module unique en intervalle : intitulé de la formation, heures et dates de la session.
- **Reprendre les modules de la formation** : remplace les modules de la session par ceux de la fiche formation du catalogue, après un aperçu (modules actuels et modules de la formation) et une confirmation ; refusé dès qu'un module a des séances ou des heures saisies.
- Un module **sans heures prévues** (venu du catalogue sans durée) est affiché « à définir », compte pour 0 dans les totaux et fait l'objet d'un avertissement. La colonne **Planifiées** donne la durée de ses séances (séances annulées exclues) ; un écart avec les heures prévues est signalé, jamais bloqué.

### Séances, assistant et stages (0.8.0)

Toujours dans l'onglet **Planning**, droit **Planifier les séances** (`sessions.seances.manage`, avec son périmètre sur la session) :

- **Calendrier de la session** (vue mois ou semaine) : les séances (heure, module, formateur ; barrées si annulées ; ⚠ s'il y a des alertes), les modules en intervalle sur leurs jours ouvrés, les stages, et en grisé les week-ends, jours fériés et fermetures. Un clic sur une séance l'ouvre. La liste des séances suit.
- **Ajouter une séance** : module (en mode « Séances »), date (dans celles de la session), début, fin, pause en minutes, formateur, salle (ou lieu libre hors centre / distanciel), note interne. La **durée** = fin − début − pause, en minutes, en **heure de Paris** : une séance de 9 h à 17 h reste 9 h–17 h au changement d'heure. Une seule séance par module, jour et heure de début.
- **Générer les séances** : jours de la semaine, un ou plusieurs créneaux (par défaut 9 h–12 h 30 et 13 h 30–17 h), période (par défaut celle du module, sinon de la session), formateur, salle, lieu. **Aperçu** d'abord : nombre de séances, total d'heures comparé aux heures prévues du module, créneaux exclus (jours **fériés** — les 11 fériés nationaux, lundi de Pentecôte compris —, **fermetures**, dates communes des **stages**, séances **déjà planifiées**) et alertes ; puis **Créer**. Relancé sur une période qui chevauche, l'assistant n'ajoute que les créneaux manquants : il ne recrée ni ne modifie jamais une séance existante. Un rythme qui change en cours de formation se génère période par période.
- **Modifier**, **Annuler** (motif obligatoire : la séance reste visible, barrée) ou **Supprimer** une séance. Une séance qui a des présences saisies ne se supprime pas : annulez-la.
- **Alertes** (elles préviennent, ne bloquent jamais) : formateur déjà sur une autre séance qui chevauche, formateur indisponible, salle déjà occupée, salle trop petite pour les inscrits, séance un jour férié, pendant une fermeture ou un stage, stagiaire inscrit à une autre séance qui chevauche. Elles s'affichent dans le formulaire pendant la saisie, dans l'aperçu de l'assistant, sur le calendrier et dans la liste.
- **Stages en entreprise** : une période (intitulé, dates communes dans celles de la session) ; pour chaque stagiaire, une **entreprise d'accueil** (fiche du CRM) et, au besoin, ses **propres dates**, qui remplacent les dates communes pour lui. Les dates communes sont exclues par l'assistant ; une séance pendant le stage individuel d'un stagiaire donne une alerte (il n'y est pas attendu), pas une exclusion. Les stages ne comptent dans aucune heure. Une entreprise d'accueil mise à la corbeille reste affichée « (supprimée) ».
- Une salle ou un formateur utilisé par une séance ne se supprime plus : désactivez-le.

### Présences, heures du mois et clôture (0.8.0)

Les **heures réalisées** font foi pour la facturation au réalisé (0.9) : elles se comptent en **minutes entières**, par jour et par mois de **Paris**, et chaque saisie, modification ou suppression est **journalisée** (identifiants et minutes seulement, jamais un motif ni un nom) et apparaît dans l'**historique du dossier** du stagiaire.

Onglet **Présences** de la session (droit **Saisir les présences** `sessions.attendance.manage` ou **Clôturer les présences** `sessions.attendance.close`, avec son périmètre sur la session), un **mois** à la fois :

- **Séances du mois** : présences saisies / stagiaires attendus, « à revoir ». Le bouton **Présences** ouvre la feuille de la séance : **Tous présents** marque présents les stagiaires attendus qui n'ont pas encore de présence, puis on note les exceptions — **Présent** (la durée de la séance), **Partiel** (des minutes, plus de 0 et moins que la durée), **Absent** ou **Absence justifiée** (0 minute), un **motif** facultatif (300 caractères). Une présence se corrige ou s'efface tant que le mois est ouvert.
- **Stagiaires attendus** : inscrits, confirmés, ou sortis en cours de formation, à partir de leur **date d'entrée** (inscription tardive) et jusqu'à leur **date de sortie** (abandon) ; jamais avant ni après. Liste d'attente et inscriptions annulées : aucune présence.
- **Séance modifiée après présences** : si son jour ou ses horaires changent, ses présences passent « **à revoir** » ; « présent » prend la nouvelle durée, un « partiel » qui l'atteint ou la dépasse devient « présent » à la nouvelle durée. **Annuler** une séance passe ses présences à 0 minute, « à revoir » (gardées, journalisées) ; une séance annulée ne compte jamais dans les heures réalisées. La revue se fait dans la même opération que le changement de la séance : l'un ne se fait jamais sans l'autre. Une séance avec présences (même absentes) ne se **supprime** pas : annulez-la, ou effacez d'abord ses présences.
- **Heures du mois** (modules en **intervalle**, sans séances) : pour chaque stagiaire et chaque module, les heures réalisées dans le mois (format 12:30 ; une case vidée efface ses heures), seulement dans les mois du module, à partir du mois d'entrée et jusqu'au mois de sortie du stagiaire. Saisie par les gestionnaires (pas par un formateur).
- **Heures réalisées** : par stagiaire, les heures réalisées du mois (séances + heures du mois) et les heures prévues de la session (somme des modules ; un module sans heures prévues compte 0).
- **Clôturer le mois** (droit **Clôturer les présences**) : ensuite plus aucune modification du mois — présences, heures du mois, et **création, suppression, annulation ou changement d'horaires des séances** de ce mois (l'assistant saute ce mois : « Mois clôturé ») — sans **réouverture**. **Rouvrir le mois** exige un **motif** ; la réouverture est journalisée et la dernière (qui, quand, motif) reste affichée. Changer le formateur, la salle ou la note d'une séance reste possible.

**Le formateur** (rôle Formateur, « Ses séances ») saisit les présences de **ses** séances seulement (la feuille d'une séance d'un autre formateur répond « introuvable ») ; il n'y voit que les noms des stagiaires, jamais leurs besoins d'adaptation, notes, financements ni prix. Une séance sans formateur est saisie par les gestionnaires.

La **page d'une session** (onglet **Session**) montre ses informations, les **places restantes** d'une session inter, les inscrits regroupés par dossier et la liste d'attente dans son ordre. Les noms des inscrits de tous les dossiers sont visibles ; le prix, les financements et le contractant d'un dossier ne le sont qu'avec le droit de consulter ce dossier.

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

**Inscrire des stagiaires** (page du dossier ou de la session ; droit « Créer et modifier les sessions et inscriptions » sur la session et droit de consulter le dossier) inscrit **un ou plusieurs** stagiaires d'un même dossier en une fois — tout un groupe, ou une vague arrivée plus tard :

1. choisissez le dossier (depuis la session) ou la ligne de formation et la session (depuis le dossier ; seules les sessions de cette formation ouvertes au dossier sont proposées, avec leur intitulé) ;
2. **Date d'entrée commune** : vide, le début de la session ; chaque stagiaire peut avoir la sienne (un stagiaire arrivé une semaine après) et ses **heures prévues** ;
3. ajoutez les stagiaires : **recherchez une personne** — la recherche porte sur toutes les personnes de l'organisme (nom, prénom, date de naissance, entreprise actuelle), même hors de votre périmètre, pour ne pas créer de doublon ; les salariés du contractant apparaissent en premier ; un clic l'ajoute à la liste —, ou **Créer un stagiaire** : une personne ordinaire du CRM, créée à l'enregistrement (salariée du contractant si la case est cochée, avec le DAS de la formation), une seule fois même ajoutée deux fois. Si des **personnes similaires** existent, l'erreur les nomme : choisissez-la dans la recherche ou cochez « Créer quand même » ;
4. **Inscrire** : tout le groupe est inscrit d'un seul coup, ou **personne** si un stagiaire est refusé (déjà inscrit, date ou heures invalides, personnes similaires) : l'erreur s'affiche sous ce stagiaire, corrigez-le ou retirez-le (✕) et inscrivez de nouveau. Une session inter complète place les suivants en **liste d'attente**, dans l'ordre de la liste ; une session individuelle n'accepte qu'un stagiaire.

Les besoins d'adaptation, la catégorie BPF et les notes se saisissent ensuite sur chaque inscription (**Modifier**).

Une personne n'est inscrite qu'**une fois** sur une même session (sauf inscription annulée). Une session intra refuse un dossier d'un autre contractant.

Sur l'inscription : dates d'entrée (début de la session par défaut) et de sortie, **heures prévues** (celles de la session par défaut ; modifiables par **Modifier**, plus de zéro et jamais au-delà des heures de la session — si les modules de la session font baisser ses heures sous celles d'un stagiaire, un avertissement le signale sans modifier l'inscription ; vide : celles de la session ; chaque changement figure dans l'historique du dossier, « Heures prévues modifiées », avec l'ancienne et la nouvelle valeur ; elles serviront aux attestations et au prorata, sans effet sur la facturation dans ce lot), catégorie de stagiaire (BPF), **besoins d'adaptation / situation de handicap** et notes. Ces deux derniers champs sont des **données personnelles** : ils ne sont visibles que de qui peut modifier l'inscription, ne figurent jamais en clair dans le journal et sont effacés par l'anonymisation.

### Liste d'attente

Quand une session inter est complète (inscrits et confirmés au nombre de places), l'inscription suivante passe **En attente**, avec son rang d'arrivée. Une désinscription ne fait monter **personne** automatiquement : **Promouvoir** fait passer un stagiaire en attente sur une place libre (refusé si la session est complète).

### États d'une inscription

*Inscrit*, *En attente*, *Confirmé* (**Confirmer**), *Abandon* (**Abandon**), *Annulé* (**Désinscrire** : retire le stagiaire du groupe et libère la place, sans promouvoir personne). **Modifier** change la date d'entrée, les heures prévues, la catégorie BPF, les besoins d'adaptation et les notes.

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
2. **Sessions** : pour chaque ligne de formation, une **session existante** de cette formation, une **nouvelle** session (intra ou individuelle pré-remplie pour l'entreprise, avec un intitulé facultatif) ou **Plus tard** ;
3. **Stagiaires** : cherchés dans le CRM (les salariés du contractant en premier) ou créés sur place, puis inscrits ; une même personne nouvelle n'est créée qu'une fois, même inscrite sur plusieurs lignes.

Tout est créé d'un seul coup : le dossier (DAS de l'opportunité), ses lignes, les sessions nouvelles, les inscriptions et un financement initial du prix total, *À demander*, payé par le contractant, du type de financeur marqué « par défaut » pour une entreprise ou pour un particulier (*Référentiels* › *Types de financeur*, champ **Financement initial d'un dossier** ; « Entreprise » et « Personnel » à l'installation). Si aucun type n'est marqué pour ce cas, le dossier est créé sans financement. Un devis ne donne **qu'un dossier** : ensuite le bouton devient **Voir le dossier** (un double clic ou un second onglet ne crée pas de doublon). Un dossier mis à la corbeille bloque toujours la création d'un nouveau dossier pour ce devis : restaurez-le.

## 7. Accueil

La carte **Sessions qui démarrent dans les 7 jours** de l'**Accueil** liste les sessions de votre périmètre qui commencent d'aujourd'hui à dans 7 jours (jours de Paris), ni annulées ni à la corbeille, avec leur formation, leur numéro, leur type, les places prises d'une session inter et leurs dates ; un clic ouvre la session.

## 8. Les stagiaires dans le CRM

Un stagiaire est une personne du CRM :

- sa fiche montre le bloc **Formations suivies** (ses inscriptions, chacune avec **son planning** : ses 10 prochaines séances à partir d'aujourd'hui, entre ses dates d'entrée et de sortie, annulées exclues, puis le nombre des suivantes, et ses stages pas encore terminés, à ses dates propres s'il en a, avec son entreprise d'accueil) et, dans l'**Historique**, ses inscriptions et les dossiers dont elle est contractante ou contact ; une entreprise, et un particulier, montrent le bloc **Dossiers** (les dossiers dont ils sont contractants, selon le droit « Consulter » des dossiers et son périmètre) ;
- **corbeille** : une personne inscrite peut être mise à la corbeille ; ses inscriptions restent (badge « Fiche à la corbeille ») et reviennent avec elle à la restauration ; elle ne peut plus être inscrite tant qu'elle est à la corbeille ;
- **fusion** : sessions, dossiers, inscriptions et financements suivent la fiche conservée ; deux personnes inscrites chacune sur la même session ne se fusionnent pas tant que l'une des deux inscriptions n'est pas annulée ; cas rare : si l'une des deux est inscrite sur la session de l'autre pendant la fusion elle-même (deux utilisateurs au même instant), la base refuse le doublon et la fusion est annulée sans rien écrire, avec un message d'erreur générique ; relancez-la : le refus habituel s'affiche alors. De même, **Annuler la session** peut répondre « Une inscription vient d'être ajoutée : réessayez. » si un stagiaire est inscrit au même instant ;
- **annulation d'un import** : une entreprise ou une personne qui porte une session, un dossier, une inscription ou un financement est conservée ;
- **RGPD** : l'export des données d'une personne liste ses inscriptions (avec catégorie BPF et part du prix), ses dossiers (lignes et prix de ceux dont elle est la contractante particulier) et les financements qu'elle paie (type, montant, état, n° et date d'accord, notes) ; une personne inscrite, contractante, contact ou financeur dans la durée de conservation n'est jamais proposée à l'anonymisation (`admin.md` § 11) ; l'anonymisation efface ses besoins d'adaptation, les notes de ses inscriptions, des financements qui la concernent et de ses dossiers de particulier (voir `crm.md` § 13).

## 9. Formateurs, salles et fermetures (0.8.0)

### Formateurs

Menu **Formation › Formateurs** (droit « Gérer les formateurs »). Un formateur est un utilisateur du TMS ou un formateur sans compte ; une fiche Personne du CRM le porte en interne (lien discret depuis sa page pour qui peut la consulter) :

- **Ajouter un formateur** : choisissez un **utilisateur du TMS** (actifs, ceux qui ont le rôle Formateur en tête), ou saisissez un **formateur sans compte** (nom, prénom, e-mail), puis le statut **Salarié** ou **Indépendant**. Aucune recherche dans le CRM : la fiche Personne qui porte le formateur est reprise seulement si **une seule** personne a cet e-mail **et le même nom** (accents et majuscules ignorés) — un contact client qui partagerait l'adresse sous un autre nom n'est jamais repris ; une personne à la corbeille avec cet e-mail et ce nom est restaurée ; sinon une personne est créée, au statut « Inactif » (pas un prospect), sans créateur ni responsable : elle n'apparaît dans les « Ses fiches » de personne. Un formateur sans compte dont l'e-mail est celui d'un seul utilisateur actif (pas encore formateur) est lié à ce compte aussitôt. Un compte déjà formateur, ou un e-mail déjà porté par un formateur, est refusé ; un formateur supprimé revient si on le déclare de nouveau.
- **Automatiquement** : un utilisateur dont « Consulter les séances » passe sur « Ses séances » (rôle **Formateur** reçu à l'acceptation de son invitation ou par un changement de rôle, rôle modifié dans « Rôles et droits », ou à défaut à l'ouverture de « Mon planning ») obtient sa fiche formateur, liée à son compte (statut Salarié, modifiable) ; s'il existait déjà un formateur sans compte avec son e-mail (un seul), c'est celui-ci qui est lié.
- **Page du formateur** : statut, **actif** (seuls les formateurs actifs seront proposés au planning), **compte TMS lié**, **indisponibilités** (du … au …, en heure de Paris, motif facultatif), suppression (impossible s'il a des séances : désactivez-le). La personne reste dans le CRM.
- **DAS du formateur** : sur sa page, cochez un ou plusieurs DAS (actifs). Dans le formulaire de séance et l'assistant « Générer les séances », les formateurs du DAS de la session sont proposés d'abord, puis les autres (toujours choisissables), par nom. La liste des formateurs se filtre par DAS. Un formateur sans DAS reste visible de tous ceux qui gèrent les formateurs ; hors de son périmètre, un formateur est introuvable (404).
- **Fiche Personne** : un bloc « Formateur » (statut, compte, indisponibilités à venir) apparaît pour qui gère les formateurs.

### Le formateur lui-même

Avec le rôle **Formateur**, il ne voit que **Mon planning** : ses séances (voir § 10) et ses indisponibilités, qu'il saisit et supprime lui-même. Aucun droit particulier n'est nécessaire pour cela : être lié à la fiche formateur suffit. Il n'a accès à aucune fiche du CRM, aucun devis, dossier, financement, catalogue ni export ; une session ou une inscription qui ne porte pas une de ses séances est introuvable. Un compte non encore lié à une fiche formateur voit un message l'invitant à demander le lien.

### Salles et fermetures

Administration › **Salles et fermetures** (droit « Gérer les salles et fermetures », qui couvre aussi les périodes de fermeture : pas de droit séparé) : les salles (nom, capacité, adresse, active) et les périodes de fermeture de l'organisme (voir `admin.md` § 16).

## 10. Plannings et agenda (0.8.0)

### Planning des séances

Menu **Formation › Planning des séances** (`/sessions/planning`, droit « Consulter les séances ») : toutes les séances de votre périmètre (« Ses fiches », « Ses DAS », « Tout l'organisme » ; « Ses séances » : les vôtres) des sessions qui ne sont pas à la corbeille, en vue **Mois** ou **Semaine** (lundi à dimanche). Les week-ends, jours fériés et fermetures sont grisés et nommés ; une séance annulée est barrée.

- **Filtres** : **DAS** (de la session ; la liste des formateurs se limite alors aux formateurs de ce DAS), **Formateur**, **Salle**, **Session** (celles qui ont une séance sur la période).
- **Une séance** s'ouvre : module, formateur, lieu, alertes (formateur ou salle déjà pris, indisponibilité, férié, fermeture, stage, capacité…), note interne ; **Ouvrir la session** (si vous pouvez la consulter) et **Saisir les présences** (droit « Saisir les présences » sur cette séance).
- Sur un téléphone, les jours qui ont une séance s'affichent l'un sous l'autre.
- Un formateur qui l'ouvre n'y voit que ses séances, sans alertes ni note interne (une alerte peut nommer des stagiaires d'autres sessions).

### Feuille d'émargement et scans signés (0.8.0)

Depuis la feuille de présences d'une séance (bouton **Présences**), section **Feuille d'émargement** :

- **Imprimer cette séance** ou **Imprimer la journée** (toutes les séances de la session ce jour-là que vous pouvez voir) : un PDF A4 généré à la demande (aucune archive) avec l'organisme (nom et logo de la société par défaut), la session, la date, les créneaux et leur module, le formateur, **une ligne par stagiaire attendu ce jour** (mêmes règles que les présences : entrée tardive et abandon respectés, liste d'attente et annulés absents), une case de signature **matin** et **après-midi** et une case **formateur**. Une séance annulée n'a pas de feuille (409). Si le service PDF est indisponible, un message en français invite à réessayer (503).
- **Déposer la feuille signée** : un PDF, une image PNG ou JPEG de **10 Mo au plus**, dont le type est vérifié sur le **contenu** du fichier (un fichier renommé est refusé). Le scan se rattache à la séance, ou à **toute la journée** de la session (case à cocher). Il se télécharge par quiconque voit la séance ; le formateur dépose et lit les scans de **ses** séances. Seuls les gestionnaires de présences (droit **Saisir les présences** avec un autre périmètre que « Ses séances ») les **suppriment** (définitif, journalisé sans nom de fichier). Les scans sont rangés dans l'espace privé de l'organisme (zone `attendance_sheets`).
- **RGPD** : l'anonymisation d'un stagiaire **garde** ses présences et ses heures (preuve et facturation) et efface leurs **motifs** ; les scans, pièces de preuve collectives, sont gardés, sauf sur une **session individuelle** (un seul stagiaire), où ils sont supprimés. L'export RGPD de la personne contient ses **présences** (séance, état, minutes, motif) et ses **heures du mois**.

### Mon planning

**Mon planning** (`/mon-planning`) montre au formateur lié à votre compte **ses** séances (celles dont il est le formateur, même si vos droits vont au-delà), en mois ou en semaine. Un clic sur une séance ouvre directement la **saisie des présences** (« Tous présents », puis les exceptions) quand il peut la faire ; sinon (séance annulée) ses détails. **Abonner mon agenda** mène à « Mon agenda ».

### Agenda (iCal)

**Paramètres › Mon agenda** donne un lien d'abonnement privé (voir `crm.md` § 8). Il contient, en plus des rendez-vous et appels du CRM pour qui y a accès :

- les séances dont vous êtes le **formateur** ;
- les séances des sessions dont vous êtes **responsable**, dans le périmètre de votre droit « Consulter les séances » (une session hors de vos DAS n'y figure pas ; « Ses séances » : celles que vous animez).

Chaque séance : « <session> — <module> », de l'heure de début à l'heure de fin en **heure de Paris** (fuseau `Europe/Paris` déclaré : 9 h–17 h reste 9 h–17 h après un changement d'heure), lieu = la salle (et son adresse), sinon le lieu libre, sinon l'adresse de la session (jamais celle d'une session individuelle, qui peut être le domicile du stagiaire). **Jamais** le nom d'un stagiaire, la note interne ni le motif d'annulation. Une séance annulée (ou d'une session annulée) apparaît comme annulée ; une séance ou une session à la corbeille disparaît. Un formateur sans accès au CRM obtient lui aussi « Mon agenda » (sans « Connecter Outlook » : l'envoi vers Outlook des séances viendra en 0.8.1).
