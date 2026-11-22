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

**États** : *Planifiée*, *Confirmée*, *En cours*, *Terminée*, *Annulée*. « En cours » et « Terminée » se calculent d'après les dates (du premier au dernier jour inclus ; « Terminée » dès le dernier jour quand son dernier mois est clôturé dans *Suivi › Clôture des mois*) ; ils ne se posent pas à la main. **Annuler la session** demande un **motif** (liste *Motifs d'annulation*) et annule ses inscriptions encore actives (places libérées, parts de prix recalculées) ; une session annulée ne se rouvre pas : créez-en une nouvelle.

Dès qu'une session a des inscriptions, sa formation, son type et son entreprise ne changent plus, et elle ne va pas à la corbeille.

### Page de la session en étapes et Synthèse (0.8.1)

La page d'une session se lit en **étapes**, dans une barre numérotée en haut : **Synthèse**, **Configuration**, **Planning**, **Stagiaires** et, pour qui saisit ou clôt les présences, **Suivi**. L'étape ouverte figure dans l'adresse (`?etape=planning`) : copiez-la pour renvoyer un collègue à la bonne étape. Les anciennes adresses `?tab=` restent comprises.

La **Synthèse** (étape d'ouverture) est un tableau d'**avancement** : une carte par point à régler, avec un état en texte et en icône (**Fait**, **À faire**, **Alerte**, **Sans objet**), une valeur et un lien **Ouvrir** qui mène à l'étape et à la section concernées :

- **Heures planifiées** : heures planifiées sur heures prévues (en alerte si l'on planifie plus que prévu) ;
- **Formateurs** : séances à venir sans formateur ; **Salles ou lieux** : séances à venir sans salle ni lieu ;
- **Conflits** : alertes de conflit sur les séances à venir (formateur, salle, stagiaire…) ;
- **Inscriptions** : places prises sur la capacité (alerte en cas de liste d'attente ou de dépassement) ;
- **Financements** (avec le droit « Consulter les dossiers ») : dossiers dont le prix n'est pas encore couvert ; « Sans objet » sans dossier ;
- **Stages** : stagiaires sans entreprise d'accueil sur une période de stage ;
- **Présences** et **Clôture des mois** (avec le droit de saisir ou de clôturer les présences) : séances passées sans présences, mois écoulés non clôturés.

Chaque carte suit **votre périmètre** : une carte dont vous n'avez pas le droit ne s'affiche pas. Une session **annulée** montre son motif à la place des cartes.

À côté des cartes, le panneau **Tâches et notes** :

- **Tâches** : un intitulé, une échéance facultative et un responsable facultatif (un utilisateur actif de l'organisme) ; cochez pour terminer ou rouvrir, modifiez ou supprimez ;
- **Notes** : texte libre de 5 000 caractères au plus, avec l'auteur et la date ; seul l'auteur modifie sa note, qui peut être supprimée par toute personne ayant le droit de modifier la session ;
- tâches et notes se créent et se modifient avec le droit « Créer et modifier les sessions et inscriptions » sur la session, et pas sur une session annulée ; elles sont journalisées **sans leur texte**. Elles sont internes à l'organisme : jamais envoyées aux stagiaires ni aux formateurs sans droit de consultation.

### Liste et colonnes (0.8.1)

L'écran **Sessions** se lit en **Liste** ou en **Colonnes** (bouton de bascule ; votre dernier choix est retenu sur cet appareil). La vue **Colonnes** est un kanban par **état** : *Planifiée*, *Confirmée*, *En cours*, *Terminée* (les 90 derniers jours) et, avec la case **Afficher les annulées**, *Annulée*. Les filtres de la liste (recherche, DAS, formation, responsable, dates…) s'y appliquent, sauf **État**, que les colonnes remplacent.

- Chaque carte donne le libellé de la session, ses dates, les inscrits sur les places, une barre d'**heures planifiées / prévues**, un badge du nombre de points **à régler** (ceux de la Synthèse) et le responsable. Une colonne montre 50 cartes au plus et un lien « Voir les N autres » mène à la liste filtrée sur cet état.
- **Glisser une carte** de *Planifiée* vers *Confirmée* (ou l'inverse) change l'état, avec le droit de modifier la session. Au clavier : menu de la carte, « Passer en confirmée » / « Repasser en planifiée ». *En cours* et *Terminée* se calculent d'après les dates et n'acceptent pas de dépôt ; une session déjà en cours ou terminée ne change plus d'état ainsi, et une annulation passe toujours par **Annuler la session** (motif obligatoire).

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

- **Calendrier de la session** (vue mois ou semaine) : les séances (heure, module, formateur ; barrées si annulées ; ⚠ s'il y a des alertes), les modules en intervalle sur leurs jours ouvrés, les stages sur leurs jours ouvrés (dates communes ; jamais un week-end, un jour férié ni une fermeture), et en grisé les week-ends, jours fériés et fermetures. Un clic sur une séance l'ouvre. En vue mois, plusieurs modules en intervalle d'un même jour tiennent sur une ligne, et un jour trop chargé affiche « + N autres » (ouvre la semaine). La liste des séances suit.
- **Ajouter une séance** : module (en mode « Séances »), date (dans celles de la session), début, fin, pause en minutes, formateur, salle (ou lieu libre hors centre / distanciel), note interne. La **durée** = fin − début − pause, en minutes, en **heure de Paris** : une séance de 9 h à 17 h reste 9 h–17 h au changement d'heure. Une seule séance par module, jour et heure de début.
- **Générer les séances** : jours de la semaine, un ou plusieurs créneaux (par défaut 9 h–12 h 30 et 13 h 30–17 h), date de **début**, puis la **fin** : **calculée d'après les heures prévues du module** (choix par défaut quand le module a des heures prévues : les heures déjà planifiées sont déduites, l'assistant avance jour après jour en sautant fériés, fermetures et stages, s'arrête dès que les heures sont atteintes et affiche la **fin estimée** ; au plus tard la fin de la session, sinon « heures prévues non atteintes ») ou **date de fin choisie** (par défaut celle du module, sinon de la session), formateur, salle, lieu. L'aperçu se met à jour à chaque changement. **Aperçu** d'abord : nombre de séances, total d'heures comparé aux heures prévues du module, créneaux exclus (jours **fériés** — les 11 fériés nationaux, lundi de Pentecôte compris —, **fermetures**, dates communes des **stages**, séances **déjà planifiées**) et alertes ; puis **Créer**. Relancé sur une période qui chevauche, l'assistant n'ajoute que les créneaux manquants : il ne recrée ni ne modifie jamais une séance existante. Un rythme qui change en cours de formation se génère période par période.
- **Modifier**, **Annuler** (motif obligatoire : la séance reste visible, barrée) ou **Supprimer** une séance. Une séance qui a des présences saisies ne se supprime pas : annulez-la.
- **Modification en masse** : cochez des séances prévues dans la liste (ou toutes, case de l'en-tête), puis **Modifier la sélection** : changez le **formateur** (ou retirez-le), la **salle ou le lieu**, et/ou les **horaires** (le jour ne change pas) : une ligne par **créneau actuel** des séances cochées (par exemple 9 h–12 h 30 et 13 h 30–17 h), chacune avec son nouveau début, sa nouvelle fin et sa pause ; chaque séance prend les horaires de son créneau. Seuls les blocs cochés changent. **Annuler la sélection** annule les séances cochées avec un motif commun ; **Supprimer la sélection** efface des séances planifiées par erreur (aucune ne doit avoir de présences). Un module dont toutes les séances sont supprimées peut repasser en « Intervalle ». Tout ou rien : si une séance est refusée (doublon, mois clôturé…), aucune n'est modifiée et le message nomme son jour.
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

**Heures réalisées et facturation (0.9)** : `RealisedHours` donne, par stagiaire, `realised_minutes` (ce qui est dû, seul montant facturé au réalisé), `planned_minutes` (total des modules de la session) et `enrolment_planned_minutes` (les heures prévues de l'inscription, à défaut celles de la session). Changer la date d'entrée d'un stagiaire ou enregistrer son abandon est refusé (409) dès qu'un mois concerné est clôturé : les heures d'un mois clôturé ne bougent plus.

Un formateur **désactivé** mais toujours lié à son compte garde « Mon planning » et son flux iCal tant que le lien existe : déliez-le (page du formateur) pour couper l'accès.

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

### Bloc Documents (0.10)

Sur la page de la **session** (étape Stagiaires), un bloc **Documents** émet et envoie convocations, attestations et certificats de réalisation, un par un ou pour tous ; la synthèse porte les cartes **Convocations** et **Attestations**. Les modules ajoutent leurs blocs et cartes par `Sessions\Public\SessionBlocks` et `SessionCards`.

La page du dossier porte un bloc **Documents** (droit « Consulter les documents ») : convention (contractant entreprise) ou contrat (particulier), émis, archivés, envoyés et annulés depuis le dossier. Voir `documents.md`.

### Bloc Facturation (0.9)

La page du dossier porte un bloc **Facturation** (droit « Consulter les factures ») : **rythme** (fin de formation, mensuel au réalisé, échéancier), **base** (au réalisé, au prévu), échéancier (dates et pourcentages, 100 % au total), règle des **absences non justifiées**, financeurs payant directement, **factures et avoirs du dossier** avec leur état, ce qui reste à facturer et « Bientôt à facturer ». Les réglages demandent « Préparer les factures » et se verrouillent à la première facture validée. Quand tout est facturé, le dossier passe à l'état **Facturé** ; un avoir qui fait revenir un dû le ramène à **Réalisé**. Un dossier qui a des factures validées ne va pas à la corbeille. Voir `finance.md`.

### Étapes du dossier

*Créé* → *Convention envoyée* → *Convention signée* → *Convocations envoyées* → *En cours* → *Réalisé* ; *Annulé*. Avec la facturation (0.9), le dossier réalisé passe à *Facturé* quand tout est facturé.

- Les trois premières étapes se posent à la main, avec leur **date** (chacune pas avant la précédente).
- *En cours* et *Réalisé* se calculent d'après les sessions de ses inscriptions (du premier jour de la première au dernier jour de la dernière) ; vous pouvez aussi les poser à la main, ils ne sont alors plus recalculés.
- **Annuler le dossier** demande un motif, est possible jusqu'à *Réalisé* et annule ses inscriptions encore actives.
- Un dossier qui a des inscriptions actives ne va pas à la corbeille : annulez-le d'abord.

La page du dossier montre l'en-tête (état et actions), les étapes, les lignes, les **financements** avec leur alerte, les **inscriptions** par ligne de formation et l'**Historique** : création et étapes (avec le motif d'une annulation), inscriptions et abandons (stagiaire nommé), financements ajoutés, modifiés, supprimés, chacun avec son auteur et son heure. Les mêmes évènements figurent, selon vos droits, dans l'historique des fiches du CRM : les dossiers d'une entreprise ou d'une personne (contractant ou contact) et les inscriptions d'une personne.

## 3. Inscrire des stagiaires

**Inscrire des stagiaires** (page du dossier ou de la session ; droit « Créer et modifier les sessions et inscriptions » sur la session et droit de consulter le dossier) inscrit **un ou plusieurs** stagiaires d'un même dossier en une fois (un dossier de **particulier** n'inscrit que ce particulier : il est présélectionné, sans recherche ni création ; toute autre personne est refusée, « Un dossier de particulier n'inscrit que ce particulier. », y compris à la création d'un dossier depuis un devis) — tout un groupe, ou une vague arrivée plus tard :

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

- **Paie directement l'organisme** (0.9) : case à cocher. Le financeur, **si le financement est Accordé**, reçoit sa propre facture pour sa part (voir `finance.md`) tant qu'il n'est pas accordé, sa part reste sur la facture du contractant. Refusé quand le financeur est le contractant lui-même ; verrouillé dès la première facture validée du dossier (faites d'abord un avoir). Un type de financeur marqué **Facturé hors du TMS** (CPF…) retire sa part de la facture du contractant sans émettre de facture.

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
- **Déposer la feuille signée** : un PDF, une image PNG ou JPEG de **10 Mo au plus**, dont le type est vérifié sur le **contenu** du fichier (un fichier renommé est refusé). Le scan se rattache à la séance, ou à **toute la journée** de la session (case à cocher). Il se télécharge par quiconque voit la séance ; le formateur dépose et lit les scans de **ses** séances. Seuls les gestionnaires de présences (droit **Saisir les présences** avec un autre périmètre que « Ses séances ») les **suppriment** (définitif, journalisé sans nom de fichier). Le nom du fichier envoyé n'est pas conservé (`emargement-<date>.<extension>`) ; on ne dépose pas de scan sur une séance annulée, et on ne supprime pas celui d'un mois clôturé (409). Un scan de **journée** est visible par quiconque voit **une** séance de la session ce jour-là. Les scans sont rangés dans l'espace privé de l'organisme (zone `attendance_sheets`).
- **RGPD** : l'anonymisation d'un stagiaire **garde** ses présences et ses heures (preuve et facturation) et efface leurs **motifs** ; les scans, pièces de preuve collectives, sont gardés, sauf sur une **session individuelle** (un seul stagiaire), où ils sont supprimés. L'export RGPD de la personne contient ses **présences** (séance, état, minutes, motif) et ses **heures du mois**.

### Émargement en ligne (0.11)

Les stagiaires signent leur présence **sur leur téléphone**, sans compte, une fois par **demi-journée** (matin, après-midi : coupure à **13 h**, comme sur la feuille papier). Le formateur valide ensuite la demi-journée : les **présences** de la séance se remplissent seules, et restent modifiables dans la feuille de présences comme avant.

1. **Ouvrir** : dans la feuille de présences d'une séance (depuis **Mon planning**, le planning ou l'onglet Présences), section **Émargement en ligne**, bouton **Ouvrir l'émargement** (Matin ou Après-midi), **le jour même** seulement : avant, la ligne indique « S'ouvre le jour de la séance » ; après, sans émargement ouvert ce jour-là, « Pas d'émargement en ligne ce jour-là ». Un stagiaire inscrit dont la **date d'entrée** tombe après ce jour n'est pas attendu (l'écran le liste sous « Inscrits qui entrent en formation plus tard ») : avec le droit **Faire entrer un stagiaire en formation** (administrateur, direction, Responsable de DAS par défaut), **Faire entrer ce jour-là** avance sa date d'entrée au jour de la demi-journée (refusé si un mois concerné est clôturé ; noté dans l'historique du dossier), et il peut signer. Une demi-journée couvre toutes les séances de la session ce jour-là qui la touchent (une séance 9 h–17 h touche les deux). Il faut le droit **Saisir les présences** sur une de ces séances (« Ses séances » : le formateur de la séance).
2. **Afficher le QR code** : la page d'émargement montre un **QR code** et le lien en clair, à projeter ou à montrer. **Ouvrir la page de signature ici** permet aussi de faire passer l'appareil du formateur (mode tablette). **Fermer le lien** le rend inutilisable (les signatures restent) ; **Rouvrir** donne un nouveau lien (l'ancien ne marche plus).
3. **Signer** (stagiaire) : il scanne, choisit son nom (affiché « Léa D. » : prénom et initiale ; « Léa D. (2) » pour des homonymes ; jamais le nom complet ni aucune autre donnée), dessine sa signature et touche **Je signe ma présence**. Un nom signé disparaît de la liste. Le lien ne marche que le jour même, tant que la demi-journée est ouverte ; tout lien invalide (fermé, validé, d'un autre jour, inconnu) affiche la même page « Ce lien d'émargement n'est plus valide. Demandez au formateur. ».
4. **Suivre** : la page du formateur se met à jour toutes les 5 secondes (signé à telle heure / en attente). Quelqu'un a signé sous le mauvais nom ? **Effacer** sa signature : le stagiaire peut signer à nouveau.
5. **Signer et valider** : le formateur dessine sa signature et valide. Les stagiaires qui n'ont pas signé sont notés **absents**. Calcul, séance par séance : signé sur toutes les demi-journées de la séance → **présent** ; sur une partie → **partiel** (durée au prorata des heures de la demi-journée signée ; une séance 9 h–17 h validée seulement le matin apparaît « partiel » jusqu'à la validation de l'après-midi) ; aucune → **absent**. Une présence **saisie à la main** avant (absence justifiée, par exemple) n'est **jamais écrasée** ; une présence corrigée à la main ensuite le reste.

**Qui valide** : le formateur de la séance, ou un gestionnaire des présences (droit « Saisir les présences » sur un autre périmètre que « Ses séances ») ; la feuille indique le nom et la qualité du valideur. Si une demi-journée réunit les séances de deux formateurs, seul un gestionnaire la valide. La validation reste possible **les jours suivants** tant que le mois n'est pas clôturé ; l'onglet Présences de la session liste les demi-journées du mois et signale en rouge celles **à valider**. Un mois clôturé refuse ouverture et validation (409).

**Rattrapage** (un stagiaire présent n'a pas signé) : sur l'écran d'une demi-journée **passée** (mois non clôturé ; jamais ouverte : bouton **Faire un rattrapage** dans les présences de la séance, qui la prépare sans lien d'émargement), le bloc **Rattrapage** propose, aux seuls gestionnaires des présences (jamais « Ses séances » : un formateur ne lance pas de rattrapage) :
- **Rattrapage sur place** : un lien propre au rattrapage, valable jusqu'à minuit, à ouvrir sur l'appareil qu'on passe au stagiaire (**Ouvrir la page de signature ici**) ; la page ne liste que les stagiaires attendus qui n'ont pas signé ; **Fermer le rattrapage** le coupe ;
- **Envoyer un lien** (sur la ligne du stagiaire, adresse de sa fiche préremplie) : un lien personnel par e-mail, valable 7 jours et utilisable une fois (un nouvel envoi remplace le précédent). Le stagiaire coche « Je confirme avoir été présent(e) à cette demi-journée. », reçoit un **code à 6 chiffres** par e-mail (10 minutes, 5 essais, un nouveau code au plus par minute), dessine sa signature et signe.

Une signature de rattrapage **ne compte pas** tant qu'elle n'est pas confirmée : l'onglet Présences de la session liste les **Rattrapages à confirmer** (gestionnaires des présences, jamais « Ses séances »). **Confirmer** écrit la présence de la demi-journée validée (une présence saisie à la main n'est jamais modifiée) ; **Refuser** (motif) garde la signature, sans présence. Mois clôturé : ni rattrapage ni confirmation. La feuille signée indique « Signé en rattrapage le … (sur place | à distance, code e-mail), confirmé par … », « Rattrapage à confirmer » ou « Rattrapage refusé ».

**Feuille d'émargement signée** (onglet Présences, mois choisi) : **Générer la feuille signée** (gestionnaires des présences) produit un PDF archivé : organisme, session, mois (clôturé ou non), par jour les créneaux, chaque stagiaire avec le **dessin** et l'heure de sa signature (ou « Absent », « Non validé », « Émargement papier ou saisie manuelle »), la présence retenue, la signature et le nom du valideur, la méthode, et en annexe l'empreinte SHA-256 de chaque dessin. Chaque génération est une nouvelle version (jamais écrasée), avec son empreinte SHA-256 ; **Aperçu** l'ouvre dans un nouvel onglet, **Télécharger** l'enregistre ; tous deux servent toujours l'archive vérifiée (410 si elle a été perdue ou modifiée). Elle se télécharge par quiconque peut saisir les présences d'une séance de la session. Si le service PDF est indisponible, un message invite à réessayer (503).

**Plus tard (SoWeSign)** : un connecteur pourra importer des signatures faites dans un autre outil par le service public `Sessions\Public\AttendanceSigning` (mêmes règles ; la source est notée sur la feuille).

**RGPD** : l'anonymisation d'un stagiaire efface ses dessins, son adresse IP et son navigateur (l'heure de signature reste) ; les feuilles signées d'une **session individuelle** sont supprimées, celles d'une session collective gardées (preuve collective, comme les scans). L'export RGPD de la personne contient ses **émargements** (date, demi-journée, heure, source).

### Mon planning

**Mon planning** (`/mon-planning`) montre au formateur lié à votre compte **ses** séances (celles dont il est le formateur, même si vos droits vont au-delà), en mois ou en semaine. Un clic sur une séance ouvre directement la **saisie des présences** (« Tous présents », puis les exceptions) quand il peut la faire ; sinon (séance annulée) ses détails. **Abonner mon agenda** mène à « Mon agenda ».

### Agenda (iCal)

**Paramètres › Mon agenda** donne un lien d'abonnement privé (voir `crm.md` § 8). Il contient, en plus des rendez-vous et appels du CRM pour qui y a accès :

- les séances dont vous êtes le **formateur** ;
- les séances des sessions dont vous êtes **responsable**, dans le périmètre de votre droit « Consulter les séances » (une session hors de vos DAS n'y figure pas ; « Ses séances » : celles que vous animez).

Chaque séance : « <session> — <module> », de l'heure de début à l'heure de fin en **heure de Paris** (fuseau `Europe/Paris` déclaré : 9 h–17 h reste 9 h–17 h après un changement d'heure), lieu = la salle (et son adresse), sinon le lieu libre, sinon l'adresse de la session (jamais celle d'une session individuelle, qui peut être le domicile du stagiaire). **Jamais** le nom d'un stagiaire, la note interne ni le motif d'annulation. Une séance annulée (ou d'une session annulée) apparaît comme annulée ; une séance ou une session à la corbeille disparaît. Un formateur sans accès au CRM obtient lui aussi « Mon agenda » (la connexion à Outlook y est proposée dès qu'il a un compte : voir « Séances dans Outlook » ci-dessous).

### Séances dans Outlook (0.8.1)

Quand le connecteur Microsoft 365 « agenda » est actif (`connectors.md`) et que le formateur a **connecté son Outlook** dans « Mon agenda », ses séances **planifiées** y sont créées, déplacées et retirées automatiquement. Seul le **formateur de la séance** les reçoit, et seulement s'il a un compte lié à sa fiche formateur et actif. Chaque évènement s'intitule « <session> — <module> », avec les heures de Paris, le lieu (salle et adresse, sinon lieu libre, sinon adresse de la session) et un lien vers **Mon planning** ; il ne contient **jamais** de stagiaire, de note interne ni de prix.

La copie suit la séance : création, changement d'horaire, de salle, de formateur (retirée chez l'ancien, créée chez le nouveau), annulation de la séance ou de la session, suppression, mise à la corbeille de la session, et renommage de la session, du module ou de la salle. Délier ou désactiver un formateur retire ses séances à venir de son agenda ; le relier ou le réactiver les y remet. Un formateur sans accès au CRM voit lui aussi « Connecter Outlook » dans « Mon agenda » ; à la connexion, ses séances des 180 prochains jours y sont envoyées, et une déconnexion suivie d'une reconnexion les y remet. C'est un envoi à sens unique : une modification faite dans Outlook n'est pas relue.

## 11. Suivi des apprenants par le formateur (lot 1e)

Chaque matin, **3 mois** (réglable, Administration › Règle d'abandon et suivi ; 0 = aucun suivi) après la fin de la
formation d'un apprenant (sa sortie, sinon la fin de la session), une action « Appel » est créée sur sa fiche :
« Suivi à 3 mois : Léa Durand, HACCP (S-2026-0005) ». Elle va au **formateur de la session ayant un compte** qui a assuré
le plus d'heures de séances (égalité : ordre alphabétique) ; sans formateur avec compte, au **responsable de la session**,
sinon à celui du dossier. Seules les inscriptions suivies (inscrites ou confirmées) d'une session non annulée comptent ;
un apprenant anonymisé n'en reçoit pas. Une échéance manquée de quelques jours est rattrapée ; jamais une échéance de
plus de 30 jours (aucune rafale à la mise en service). Le formateur voit ses actions dans « À faire » (droits « Consulter »
et « Modifier » les actions sur ses fiches, accordés par défaut au rôle Formateur) et y saisit son compte rendu. Sur la
fiche de l'apprenant, « Formations suivies » indique la date de fin et la satisfaction de sa réponse au questionnaire à chaud (« - » sans réponse ;
voir `questionnaires.md`).

**Émarger depuis le « Suivi »** : un formateur qui ouvre le « Suivi » d'une session (s'il voit la session) peut y saisir les
présences de **ses propres séances** (bouton « Présences »). Les autres séances, les heures mensuelles et la clôture du
mois restent réservées aux gestionnaires.

## 12. Convocations et rappels automatiques

Chaque matin, le TMS envoie seul les **convocations** des sessions confirmées qui démarrent dans 7 jours (ou le
lendemain de leur confirmation si elle est tardive) et un **rappel la veille** aux stagiaires et aux formateurs.
Réglages et règles : Administration › Automatismes (`admin.md`, § 23). Ce qui reste à faire apparaît dans **À
traiter** (menu).

