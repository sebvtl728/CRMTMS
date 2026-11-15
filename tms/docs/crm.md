# Guide utilisateur : fiches, pipeline, relances, catalogue, devis, import et RGPD

Ce guide s'adresse aux personnes qui utilisent le TMS au quotidien (commerciaux, chargés de relations, assistants, direction, responsables de DAS). Les écrans sont désignés par leur libellé à l'écran. Ce que vous voyez dépend de votre rôle : les boutons auxquels vous n'avez pas droit n'apparaissent pas (voir `admin.md` pour la liste des droits par rôle).

Le menu de gauche, section **Commercial**, donne accès, dans cet ordre, à **Pipeline**, **Opportunités**, **Devis**, **À faire** (avec le nombre d'actions en retard ou du jour), **Planning**, **Compte rendu de RDV**, **Catalogue**, **Entreprises**, **Personnes**, **Imports** et **RGPD**. Le mot « entreprise » désigne ici toute organisation cliente ou prospect : entreprise, association, collectivité.

## 1. L'écran d'accueil

**Accueil** rassemble vos raccourcis :

- **Nouvelle entreprise** et **Nouveau particulier** ouvrent directement le formulaire de saisie ;
- **Rechercher** (ou les touches **Ctrl K**) ouvre la recherche (voir plus bas) ;
- le nombre d'entreprises et de personnes que vous pouvez voir ;
- **Mes relances** : le nombre de vos actions **en retard** et à faire **aujourd'hui** (les mêmes que l'écran **À faire**) ; un clic ouvre **À faire** ;
- **Pipeline pondéré** du mois : la somme des montants pondérés de vos opportunités **en cours** (ni gagnées, ni perdues) dont la **clôture prévue** tombe dans le mois en cours ; un clic ouvre la liste **Opportunités** filtrée sur ce mois ;
- **Devis** du mois : le **taux de réussite** des devis envoyés dans le mois en cours (heure de Paris), avec le nombre d'envoyés et d'acceptés (« — » si aucun devis n'est parti) ; un clic ouvre la liste **Devis** ;
- **Prochains rendez-vous** : vos 5 prochains rendez-vous à faire, avec le jour, l'heure et le lieu ; un clic ouvre la fiche ;
- **Dernières fiches consultées** : les 5 dernières fiches que vous avez ouvertes, les plus récentes en premier. Cette liste est personnelle ; elle ne garde que le fait que vous avez ouvert la fiche, pas son contenu. Une fiche supprimée, fusionnée ou hors de votre périmètre n'y figure plus.

Tous ces chiffres respectent votre périmètre : un Responsable de DAS n'y compte que ses DAS.

## 2. Les fiches

### Entreprises

Écran **Entreprises**. Une fiche entreprise porte : raison sociale, SIRET, code NAF, forme juridique, secteur, type d'établissement, taille, origine, statut (**Prospect**, **Client** ou **Inactif**), responsable, coordonnées, DAS concernés et un résumé libre.

- **Saisie rapide** : seule la **raison sociale** est obligatoire. Complétez le reste quand vous l'avez.
- **Compléter depuis le SIRET** : saisissez les 14 chiffres, puis ce bouton va chercher l'annuaire officiel des entreprises et remplit **uniquement les champs encore vides**. Rien n'est enregistré tant que vous n'avez pas cliqué sur **Enregistrer**. Si l'annuaire ne répond pas, saisissez les informations à la main.
- Un même SIRET ne peut pas servir à deux fiches : le formulaire vous propose d'ouvrir la fiche existante.
- Sur une fiche, les onglets sont **Informations**, **Interlocuteurs**, **Opportunités**, **Devis** et **Historique**.

### Personnes

Écran **Personnes**. Une personne peut être :

- un **particulier** (stagiaire à titre individuel, financement personnel…) : case « Particulier » cochée ;
- un **interlocuteur** : personne rattachée à une ou plusieurs entreprises, avec sa fonction et son **rôle dans la décision** ;
- les deux à la fois.

Seul le **nom** est obligatoire. Pour rattacher quelqu'un à une entreprise, ouvrez la fiche de l'entreprise, onglet **Interlocuteurs**, puis **Ajouter un interlocuteur** : **Nouvelle personne** ou **Personne existante** (recherche par nom, e-mail ou téléphone). Vous pouvez désigner un **référent**. **Mettre fin au rattachement** (avec une date) conserve la trace dans **Anciens rattachements** ; **Supprimer le rattachement** l'efface.

### Formations d'intérêt

Sur l'onglet **Informations** d'une entreprise ou d'une personne, le bloc **Formations d'intérêt** liste les formations du catalogue qui l'intéressent, regroupées par DAS. **Modifier** (même droit que pour modifier la fiche) ouvre la liste des formations **actives** du catalogue, regroupées par DAS et filtrées par défaut sur les **DAS de la fiche** : cochez **Afficher toutes les formations** pour élargir, ou tapez quelques lettres dans **Rechercher une formation**. 50 formations au plus par fiche.

- Une formation **désactivée** dans le catalogue reste affichée (grisée, « inactive ») sur les fiches qui l'avaient ; elle ne peut plus être ajoutée ailleurs. Une formation d'intérêt d'une fiche ne peut pas être supprimée du catalogue (seulement désactivée).
- **Nouvelle opportunité** depuis la fiche : ses formations d'intérêt actives sont proposées dans les **Formations** de l'opportunité (quantité 1), à garder, retirer ou compléter avant d'enregistrer.
- Les listes **Entreprises** et **Personnes** ont un filtre **Formation d'intérêt** (enregistrable dans un filtre) et une colonne d'export **Formations d'intérêt** (intitulés séparés par « ; »).
- Chaque changement apparaît dans l'**Historique** de la fiche : « Formations d'intérêt modifiées ».
- Une fiche dans la corbeille ne se modifie pas : restaurez-la d'abord.

### Historique, notes et pièces jointes

L'onglet **Historique** d'une fiche liste, du plus récent au plus ancien, les **notes**, les **pièces jointes**, les **actions** (appels, rendez-vous… voir plus bas), les **étapes de ses opportunités**, les **devis** (« Devis CF-2026-0001 créé / envoyé / relancé / accepté / refusé / expiré ») et les **modifications** de la fiche (qui a changé quoi et quand ; les données personnelles modifiées sont signalées sans afficher leur valeur).

- **Ajouter une note** : écrivez dans la zone « Ajouter une note » (appel, rendez-vous, information utile), puis **Ajouter la note**. Une note met à jour la date de **dernier contact** de la fiche.
- **Joindre un fichier** : PDF, images, Word, Excel, OpenDocument, texte, CSV, 20 Mo maximum.
- Vous pouvez supprimer une note ou retirer un fichier ; la suppression est tracée dans l'historique.

### Suppression et restauration

**Supprimer** une fiche la place dans la corbeille : elle disparaît des listes mais peut être restaurée (case **Corbeille** de la liste, puis **Restaurer** sur la fiche). Les rattachements, notes et pièces jointes suivent la fiche. Au-delà de **12 mois** en corbeille, les fichiers joints sont effacés : l'historique indique alors « Fichier supprimé ».

### Mobile

Les écrans fonctionnent sur téléphone (listes en cartes, fiche en une colonne, saisie d'une note sur place, pipeline en liste par étape, planning jour par jour, **Compte rendu de RDV** en un seul formulaire). L'export et l'import sont réservés à l'ordinateur.

## 3. Pipeline et opportunités

Une **opportunité** est une affaire en cours avec une entreprise ou un **particulier**. Une personne qui n'est pas particulier (simple interlocuteur) ne peut pas porter d'opportunité : on la crée sur son entreprise et on la coche comme **interlocuteur** de l'affaire.

### Créer une opportunité

Depuis la fiche, onglet **Opportunités**, **Nouvelle opportunité** (ou depuis l'écran **Opportunités**, qui demande d'abord l'entreprise ou le particulier). Renseignez l'**objet**, le **DAS**, l'**étape**, le **montant estimé**, la **clôture prévue**, la priorité, le **mode de financement** (prérempli depuis la fiche d'un particulier) et le responsable. Pour une entreprise, cochez ses interlocuteurs concernés.

**Formations** : ajoutez les formations du catalogue concernées (formations actives uniquement) et leur **quantité** (par défaut 1 ; « 2,5 » accepté). Sans DAS choisi, celui de la première formation est repris. Sur une opportunité enregistrée, **Calculer le montant proposé** fait la somme tarif × quantité (tarif entreprise ou particulier selon le compte) ; une formation sans tarif compte 0 et est signalée. Le montant proposé n'est jamais enregistré seul : **Reporter dans le montant estimé** le recopie dans le champ, puis **Enregistrer**. Une formation désactivée depuis reste sur les opportunités qui l'ont (« (inactive) ») mais ne peut plus être ajoutée ; une formation utilisée par une opportunité ne peut pas être supprimée du catalogue.

> Pour un particulier, ne mettez pas son nom dans l'**objet** (« Formation HACCP 3 jours », pas « Formation de M. Dupont ») : l'objet est conservé pour les statistiques même après l'anonymisation de la personne (voir RGPD).

### Étapes et probabilité

Les étapes (Nouveau, Contacté, Étude, Proposition, Négociation, Gagné, Perdu chez CAP FORMATION) sont paramétrées par l'administrateur, chacune avec une **probabilité** par défaut. Le **montant pondéré** = montant × probabilité.

- À chaque changement d'étape, la probabilité reprend celle de la nouvelle étape. Une probabilité saisie à la main est conservée au prochain changement d'étape, puis l'étape reprend la main (Gagné et Perdu imposent toujours la leur).
- **Gagné** fixe la probabilité à 100 % et demande la **date de gain** (aujourd'hui par défaut, jamais dans le futur).
- **Perdu** fixe la probabilité à 0 % et **exige un motif de perte** (liste paramétrable : Prix, Financement refusé, Concurrent…), avec un commentaire facultatif. Sans motif, le changement est refusé.
- Rouvrir une affaire gagnée ou perdue efface la date, le motif et le commentaire ; l'historique garde le motif donné à l'époque.
- Chaque changement d'étape est tracé dans l'**Historique** de la fiche (« Opportunité « X » : Étude → Proposition », « Opportunité perdue », avec le motif ; le commentaire n'y est pas affiché).

Sur la page d'une opportunité, **Changer d'étape** ouvre la même fenêtre.

### Pipeline (kanban)

**Pipeline** affiche une colonne par étape avec, en tête, le nombre d'opportunités, le montant et le montant pondéré. **Glissez** une carte par sa poignée vers une autre colonne (souris, ou clavier : Espace pour saisir, flèches, Espace pour déposer). Vers **Gagné** ou **Perdu**, la fenêtre demande la date ou le motif ; **Annuler** remet la carte en place. Si le serveur refuse le déplacement, la carte revient et le message s'affiche. Une étape désactivée par l'administrateur qui contient encore des opportunités reste affichée, sous le nom « <étape> (inactif) » : on peut en sortir ses cartes, pas y en déposer.

- Les colonnes **Gagné** et **Perdu** ne montrent que les affaires closes depuis **90 jours**.
- Une colonne affiche au plus 100 cartes (les totaux comptent tout) : affinez les filtres.
- Sur téléphone, le pipeline devient une liste groupée par étape, avec **Changer d'étape** sur chaque carte.

### Liste, filtres et export

**Opportunités** liste les affaires avec les totaux (montant et pondéré). Filtres : recherche (objet ou nom de la fiche), DAS, **formation**, entreprises ou particuliers, responsable, priorité, étape, clôture prévue du / au, **Mes opportunités** ; tri par clôture prévue, montant, montant pondéré ou création. **Vue pipeline** / **Vue liste** passent de l'une à l'autre en gardant les filtres. **Exporter (.xlsx)** exporte la liste filtrée (mêmes règles que les autres exports ; la colonne **Formations** donne leurs intitulés séparés par « ; »).

## 4. Catalogue de formations

**Catalogue** liste les formations proposées par l'organisme : intitulé, code (facultatif, unique), **DAS**, durées en heures et en jours, public visé, prérequis, **tarif entreprise** et **tarif particulier** HT par stagiaire, modes de financement possibles (CPF, OPCO…), état (active ou désactivée).

- Tout le monde voit le catalogue ; seuls l'administrateur et la direction le modifient (droit `catalogue.manage`, voir `admin.md`) : **Nouvelle formation**, **Modifier**, **Désactiver** / **Activer**, **Supprimer**.
- Montants et durées se saisissent à la française (« 1 250,50 », « 7,5 »).
- Filtres : recherche (intitulé ou code, sans souci d'accents), **DAS**, **Financement**, **État** ; tri par intitulé, code ou tarif entreprise.
- Une formation **désactivée** n'est plus proposée sur les nouvelles opportunités ni les nouveaux devis, mais reste sur ceux qui l'ont déjà. Une formation **utilisée** (par une opportunité ou un devis, même supprimés) ne peut pas être supprimée : désactivez-la.
- **Exporter (.xlsx)** (avec le droit d'export) : la liste filtrée, tarifs et durées en vrais nombres.
- **Importer un catalogue** : menu **Imports**, type **Catalogue de formations** (gestionnaires du catalogue ayant aussi le droit d'import), voir § 11.

**Plaquettes** (bouton **Plaquettes** du catalogue) : la bibliothèque des plaquettes PDF de l'organisme. Une plaquette a un **titre**, un fichier **PDF de 10 Mo au plus** (le contenu est vérifié : un fichier renommé en .pdf est refusé) et se rattache à des **formations** et/ou à des **DAS** (toutes les formations du DAS). Tout le monde les voit et les télécharge (`catalogue.view`) ; **Nouvelle plaquette** et **Modifier** demandent `catalogue.manage`, **Corbeille** `catalogue.delete`. **Remplacer le fichier** crée une nouvelle **version** : les anciennes restent téléchargeables depuis **Modifier** et les envois déjà faits gardent la version envoyée. Une plaquette à la corbeille n'est plus proposée mais son fichier n'est jamais effacé. La page de modification d'une formation liste ses plaquettes (rattachées directement ou par son DAS) ; supprimer la formation la détache simplement de ses plaquettes.

## 5. Devis

Un **devis** se crée depuis une **opportunité** : il en reprend le compte (entreprise ou particulier), les formations et le DAS. Écran **Devis** pour la liste, section **Devis** de la page d'une opportunité, onglet **Devis** des fiches.

### Créer et modifier un brouillon

Sur la page de l'opportunité, **Nouveau devis** ouvre un formulaire prérempli :

- **Société émettrice** (la société par défaut ; elle donne le **numéro** et ne change plus ensuite), **Destinataire** (le particulier, ou le premier interlocuteur de l'opportunité), **Date du devis** (aujourd'hui), **Valable jusqu'au** (date + durée de validité de la société, 30 jours par défaut), **Mentions particulières** ;
- une **ligne par formation** de l'opportunité : intitulé, quantité, unité « stagiaire », tarif entreprise ou particulier selon le compte, TVA 20 %. Une formation sans tarif est signalée : indiquez le prix de la ligne.

Chaque ligne porte un libellé, une description, une **quantité** (« 2,5 » accepté), une **unité** (stagiaire, jour, heure, forfait, session), un **prix unitaire HT**, une **remise** en % et un **taux de TVA** (20 %, 10 %, 5,5 %, 2,1 % ou 0 %). On peut ajouter une ligne libre ou une formation du catalogue, déplacer et retirer des lignes (1 à 100 lignes). Les totaux s'affichent pendant la saisie et sont recalculés au centime près à l'enregistrement : total HT de chaque ligne, TVA **par taux** (sur la somme des lignes du même taux), total TTC.

Si la société est **exonérée de TVA** (régime choisi par l'administrateur), toutes les lignes sont à 0 % et le devis porte la mention « TVA non applicable, art. 261-4-4° a du CGI » ; un brouillon suit le régime actuel de sa société, un devis envoyé garde celui du jour de l'envoi.

**Enregistrer le brouillon** attribue le numéro `<préfixe>-<année>-<numéro>` (par exemple `CF-2026-0001`) : un compteur par préfixe et par année civile (heure de Paris, l'année du jour de création), sans trou ; le numéro d'un brouillon supprimé n'est pas réattribué. Tant qu'il est **brouillon**, le devis se **modifie** et se **supprime** ; une fois envoyé, il ne se modifie plus.

### PDF

**Aperçu PDF** ouvre le devis dans un nouvel onglet, marqué **BROUILLON** en filigrane. Le document porte le logo, l'adresse et les mentions légales de la société, le destinataire, l'objet (celui de l'opportunité), les lignes, la TVA par taux, les totaux, les mentions particulières, les conditions générales et le pied de page de la société, et une case « Bon pour accord ». À l'**envoi**, le PDF est **figé et archivé** (sans filigrane) : le bouton **PDF** d'un devis envoyé rouvre toujours ce même document, même si la société ou le client changent ensuite.

### Envoyer, relancer

- **Envoyer** : destinataire (prérempli avec l'e-mail du destinataire, ou à défaut de l'entreprise), jusqu'à 5 adresses en copie, objet et message préremplis, modifiables. Le message part en texte simple avec le PDF joint, par la messagerie de l'organisme (connecteur SMTP de la société, sinon de l'organisme) ; sans serveur d'envoi configuré, la fenêtre prévient que le message ne partira pas réellement. Le devis passe **Envoyé** ; l'opportunité avance d'elle-même à l'étape du devis envoyé (« Proposition » chez CAP FORMATION, réglage de l'administrateur) si elle était à une étape antérieure ; la date de **dernier contact** des fiches est mise à jour.
- L'envoi se fait en arrière-plan : « E-mail en cours d'envoi… » s'affiche quelques instants. S'il échoue (serveur refusé…), le bandeau **L'e-mail n'est pas parti** donne la raison et **Renvoyer** réessaie le même message. Le devis reste envoyé.
- **Relancer** (devis envoyé ou déjà relancé) : nouveau message, même PDF ; le devis passe **Relancé**. Impossible tant qu'un envoi est en cours ou en échec (utilisez **Renvoyer**).
- **Envoyer** est indisponible (la raison s'affiche) quand la **date de validité est dépassée** (modifiez les dates) ou quand le **régime de TVA de la société a changé** depuis l'enregistrement du brouillon : ouvrez-le, vérifiez les taux (une société devenue assujettie laisse les lignes à 0 % : choisissez le taux de chaque ligne) et enregistrez-le.
- **Envoyer une plaquette sans devis** : le bouton **Envoyer une plaquette** des fiches entreprise et particulier/interlocuteur et des opportunités (droit `crm.activities.update` sur une fiche que vous voyez). Choisissez le destinataire (les interlocuteurs actifs de l'entreprise, la personne elle-même, les contacts d'une opportunité ; une personne sans adresse e-mail est grisée), une ou plusieurs plaquettes (versions actuelles seulement, 20 Mo au plus en tout), puis ajustez l'objet et le message proposés par le modèle « Envoi d'une plaquette ». L'envoi apparaît dans l'**Historique** de la fiche (et la section **Plaquettes envoyées** de l'opportunité) avec la date, l'auteur, le destinataire, les titres et l'état **En attente**, **Envoyée** ou **Échec** (jamais le texte du serveur). 20 envois par minute au plus par utilisateur. L'adresse, l'objet et le message sont des données personnelles : absents du journal d'audit, effacés par l'anonymisation du destinataire.
- **Plaquettes** : la case **Joindre les plaquettes** est cochée par défaut ; la liste reprend les plaquettes rattachées aux formations du devis (ou à leur DAS), chacune une seule fois. Décochez une plaquette pour ne pas la joindre, ou cherchez-en une autre par son titre. Le total des pièces jointes (PDF du devis compris) est limité à 20 Mo (« Les pièces jointes dépassent 20 Mo : retirez une plaquette. »). Les versions choisies sont enregistrées à l'envoi : **Relancer** et **Renvoyer** joignent exactement les mêmes fichiers, même si la plaquette a été remplacée ou mise à la corbeille depuis.
- Si le PDF archivé d'un devis envoyé est introuvable, **PDF**, **Relancer** et **Renvoyer** sont indisponibles (« Le PDF archivé est introuvable : contactez l'administrateur. ») : le devis n'est jamais régénéré avec les données du jour.

### Réponse du client

Les boutons **Accepté** et **Refusé** apparaissent sur un devis envoyé ou relancé (pour un devis expiré, faites une nouvelle version).

- **Accepté** (date d'acceptation : aujourd'hui par défaut, jamais dans le futur ni avant la date du devis) : le TMS propose ensuite de **passer l'opportunité en « Gagné »**, à la date d'acceptation, avec la case **Mettre le montant de l'opportunité à jour** (total HT du devis) cochée par défaut. **Ne pas changer** laisse l'opportunité telle quelle.
- **Refusé** (date et motif facultatif) : le TMS propose de **passer l'opportunité en « Perdu »**, motif de perte obligatoire.
- Rien n'est proposé si l'opportunité est déjà gagnée ou perdue.
- **Devis signé** : la fenêtre **Accepté** permet de joindre, si vous l'avez, l'exemplaire signé renvoyé par le client (PDF, JPEG ou PNG, 10 Mo au maximum ; un fichier renommé ou un SVG est refusé). Sur un devis accepté, **Joindre le devis signé** (ou **Remplacer le devis signé**) l'ajoute plus tard ; le nouveau fichier remplace l'ancien. Il s'ouvre depuis la ligne **Devis signé** du devis. L'historique indique « Devis signé ajouté (CF-2026-0001) », sans le nom du fichier. L'anonymisation du particulier ou de l'interlocuteur destinataire supprime l'exemplaire signé ; son export de données indique pour chaque devis s'il y en a un.

### Expiration

Chaque nuit à **0 h 05** (heure de Paris), un devis **envoyé** ou **relancé** dont la date de validité est dépassée passe **Expiré** : un devis valable jusqu'au 14 l'est encore toute la journée du 14. Les brouillons et les devis acceptés ou refusés ne changent pas.

### Nouvelle version

**Nouvelle version** crée un nouveau brouillon (nouveau numéro, daté du jour, mêmes lignes sauf les formations désactivées depuis ; le destinataire est repris s'il est toujours interlocuteur) à modifier puis envoyer. Le devis d'origine, s'il était envoyé, relancé ou expiré, passe **Refusé** avec la mention **Remplacé par** le nouveau numéro ; un devis déjà accepté ou refusé garde sa réponse.

### Liste, taux de réussite et export

**Devis** liste les devis visibles (un Responsable DAS ne voit que ceux des opportunités de ses DAS). Filtres : recherche (numéro, objet de l'opportunité, nom du compte), **Statut**, **Société**, **DAS**, **Responsable**, date du devis du / au ; tri par date, numéro ou total.

Le bandeau **Taux de réussite** donne, pour la période choisie (**Mois**, **Trimestre**, **Année**, heure de Paris), le nombre de devis **envoyés** dans la période et la part de ceux qui ont été **acceptés** (quelle que soit la date d'acceptation), dans les filtres de la liste. Un devis remplacé par une **nouvelle version** n'est pas compté : seule la nouvelle version compte, une fois envoyée. **Exporter (.xlsx)** exporte la liste filtrée : numéro, dates, statut, société, compte, opportunité, DAS, responsable, totaux HT / TVA / TTC, dates d'envoi et de réponse (ni texte libre, ni coordonnées).

### Fusion et RGPD

Les devis suivent la fiche conservée lors d'une fusion. L'anonymisation d'une personne efface de ses devis le destinataire figé, les mentions, le motif de refus, le message envoyé et **supprime le PDF archivé** (il n'est plus jamais régénéré : le bouton **PDF** affiche « Document supprimé (données anonymisées) ») ; numéro, lignes, montants, statut et dates restent pour les statistiques. Pour un interlocuteur d'une entreprise, seule sa part est effacée (son nom et son e-mail dans le destinataire figé, le message envoyé, le PDF archivé ; ses adresses en copie d'autres devis) ; le nom et l'adresse de l'entreprise, les mentions et le motif de refus du devis sont conservés. L'export des données d'une personne contient ses devis.

## 6. Actions, relances et « À faire »

Une **action** est un échange ou une tâche commerciale : appel, rendez-vous, e-mail, rappel… (types et résultats paramétrables). Elle concerne une entreprise, une personne et/ou une opportunité, et figure dans l'**Historique** de la fiche.

### Saisie rapide : « + Action »

Le bouton **+ Action** (en haut de chaque écran du CRM, et sur les fiches et opportunités) ouvre une fenêtre préremplie avec la fiche ouverte :

- choisissez le type en un clic (**Appel**, **RDV**, **E-mail**, **Rappel**, ou **Autre type…**) ;
- l'**objet** est facultatif (par défaut « Type – fiche ») ;
- **Fait maintenant** (coché par défaut) enregistre un échange qui vient d'avoir lieu, avec son **résultat** et son **compte rendu** ; décoché, l'action est **à faire** le jour et à l'heure choisis (une date sans heure = 9 h) ;
- **Prochaine relance** : **Demain**, **Dans 1 semaine**, **Dans 1 mois** ou une date, et un type (Rappel par défaut) : une action à faire est créée sur les mêmes fiches, pour le même responsable.

Une action faite met à jour la date de **dernier contact** de la fiche. Une action ne peut pas être datée « faite » dans le futur.

### L'écran « À faire »

**À faire** liste vos actions à faire, triées par échéance, en trois parties : **En retard** (en rouge : échéance avant aujourd'hui), **Aujourd'hui**, **Cette semaine** (jusqu'à dimanche soir). Les jours sont ceux de l'heure de Paris : une relance prévue aujourd'hui n'est pas en retard avant minuit.

- **Fait** ouvre la fenêtre de compte rendu (résultat, compte rendu, prochaine relance) ;
- **Reporter** : **Demain**, **Dans 1 semaine** ou une date (une date seule = 9 h) ;
- **Ouvrir la fiche** ;
- **Équipe** (direction et administrateur) affiche les actions de toute l'équipe.

Le nombre affiché à côté de **À faire** dans le menu (en retard + aujourd'hui) se met à jour dans la minute.

### Récapitulatif quotidien par e-mail

Dans **Paramètres › Profil**, cochez **Recevoir le récapitulatif quotidien** : chaque matin à **7 h 30**, un e-mail liste vos actions en retard et du jour (titres, fiches, heures ; jamais de compte rendu ni de lieu). Pas d'e-mail les jours où il n'y a rien. L'option est désactivée par défaut.

## 7. Planning et compte rendu de rendez-vous

### Planning de la semaine

**Planning** montre vos **rendez-vous** (à faire et faits) et vos **appels planifiés** de la semaine, du lundi au dimanche, à leur heure. **Semaine précédente** / **Semaine suivante** / **Cette semaine** ; sur téléphone, un jour à la fois. La direction et l'administrateur peuvent choisir un **Collègue**.

Les types d'action qui comptent comme rendez-vous ou comme appels sont réglés par l'administrateur (**Administration › Référentiels**, types d'action, champ **Planning commercial**). Chez CAP FORMATION : « Prise de rendez-vous » et « Rendez-vous physique » (rendez-vous), « Appel » (appel, s'il a une échéance).

### Compte rendu de RDV (téléphone)

**Compte rendu de RDV** (menu, ou bouton **Compte rendu** sur un rendez-vous du jour dans le planning) est un formulaire unique pensé pour le téléphone :

1. choisissez le rendez-vous du jour (présélectionné s'il n'y en a qu'un), ou **Sans rendez-vous prévu : choisir une fiche** ;
2. le **résultat** et le **compte rendu** ;
3. si besoin, l'**étape** de l'opportunité concernée (motif obligatoire pour **Perdu**) ;
4. la **prochaine relance** ;
5. **Enregistrer le compte rendu**.

Tout est enregistré ensemble ou rien ne l'est (par exemple si le motif de perte manque).

## 8. Votre agenda : abonnement iCal et Outlook

**Paramètres › Mon agenda** envoie vos rendez-vous et appels planifiés vers votre agenda habituel.

- **Lien d'abonnement (iCal)** : **Créer mon lien d'abonnement** donne une adresse privée, **affichée une seule fois** (copiez-la). Abonnez-y Outlook (web ou nouvel Outlook : Calendrier › Ajouter un calendrier › S'abonner à partir du web ; Outlook classique : Accueil › Ajouter un calendrier › À partir d'Internet), Google Agenda ou Apple Calendrier (instructions sur la page). L'agenda montre les 30 derniers jours et les 6 prochains mois, sans compte rendu, et se met à jour selon le rythme de votre agenda (souvent plusieurs heures chez Google et Outlook). **Ce lien donne accès à votre agenda sans mot de passe** : ne le partagez pas ; **Créer un nouveau lien** annule l'ancien, **Révoquer le lien** coupe l'accès.
- **Connecter Outlook** (Microsoft 365, si l'administrateur l'a activé) : vos rendez-vous et appels sont créés, modifiés et supprimés directement dans votre agenda Outlook, en quelques instants. Si Microsoft demande de vous reconnecter, un message l'indique : **Reconnecter Outlook**. **Déconnecter Outlook** arrête l'envoi (les évènements déjà créés restent). Mise en place par l'administrateur : voir `connectors.md` (section Microsoft 365).

## 9. Rechercher

- **Recherche globale** : **Ctrl K** (ou **Cmd K** sur Mac, ou la touche **/** hors d'un champ) ouvre une fenêtre de recherche. Tapez au moins 2 caractères : nom, SIRET, e-mail ou téléphone, sans souci d'accents ni de majuscules ni d'espaces dans le SIRET ou le téléphone. Les résultats sont groupés **Entreprises** et **Personnes** ; **flèches** pour choisir, **Entrée** pour ouvrir.
- **Recherche dans une liste** : le champ en haut de **Entreprises** ou **Personnes** filtre la liste en tapant. Les filtres (statut, DAS, secteur, responsable, origine…) se combinent.
- **Mes fiches** : n'affiche que les fiches dont vous êtes le responsable.
- **Filtres enregistrés** : **Enregistrer ce filtre** mémorise vos critères et les colonnes choisies sous un nom. Un filtre peut rester **privé** ou être **partagé** avec tout l'organisme (seul son auteur peut le modifier).
- **Colonnes** : choisissez les colonnes de la liste et de l'export.

> Les **Responsables de DAS** ne voient que les fiches rattachées aux DAS qui leur sont attribués (par l'administrateur). Les autres rôles voient toutes les fiches de l'organisme.

## 10. Doublons et fusion

### Avertissement à la saisie

Quand vous créez ou modifiez une fiche qui ressemble à une autre (même SIRET, même e-mail, même téléphone, nom très proche), le formulaire affiche **Fiches similaires** avec la raison de la ressemblance. Pour chaque fiche, vous pouvez :

- **Ouvrir** la fiche existante dans un nouvel onglet ;
- **Créer puis fusionner** (ou **Fusionner** sur une fiche déjà enregistrée) ;
- **Créer quand même** si ce n'est pas un doublon.

Les doublons ne sont jamais cherchés dans les données d'un autre organisme.

### Fusionner deux fiches

L'écran **Fusionner deux fiches** montre la **fiche conservée** et la **fiche absorbée**. Pour chaque champ qui diffère, choisissez la valeur à garder. Les champs identiques sont regroupés. Au clic sur **Fusionner**, une confirmation rappelle que **la fusion est définitive** :

- l'historique, les notes, les pièces jointes, les rattachements, les **opportunités** et les **actions** de la fiche absorbée passent sur la fiche conservée ; si les deux fiches avaient le même rattachement, il n'en reste qu'un ;
- les DAS et les **formations d'intérêt** sont réunis (une formation présente sur les deux fiches n'apparaît qu'une fois) ;
- l'adresse de la fiche absorbée renvoie vers la fiche conservée ;
- la fiche absorbée ne peut pas être restaurée et ses données personnelles sont effacées.

## 11. Importer un fichier

L'import (menu **Imports**) charge des fiches depuis un fichier **Excel (.xlsx)** ou **CSV**. Il se fait depuis un ordinateur. Vous pouvez aussi y accéder par le bouton **Importer** de la liste **Entreprises**.

### Étape 1 : Déposer le fichier

Choisissez ce que contient votre fichier :

- **Entreprises** : une ligne par entreprise ;
- **Personnes** : une ligne par personne (particuliers ou contacts sans entreprise) ;
- **Interlocuteurs (personne et entreprise)** : une ligne par interlocuteur, la personne et son entreprise sont créées ensemble et reliées ;
- **Historique d'échanges (notes)** : échanges passés (appels, rendez-vous…) rattachés à une fiche déjà présente, retrouvée par SIRET, e-mail ou identifiant source ;
- **Catalogue de formations** (gestionnaires du catalogue) : une ligne par formation (intitulé et DAS obligatoires ; code, durées, public, prérequis, tarifs, financements séparés par `,` ou `;`, active, identifiant source). Une formation est retrouvée par son identifiant source, puis par son code ; **Mettre à jour la formation existante** remplace ses valeurs par celles du fichier (cellules vides exceptées) et ses financements par ceux du fichier.

Déposez le fichier (CSV avec séparateur `;`, `,` ou tabulation, en UTF-8 ou en Windows-1252 comme l'export Excel français : l'encodage et le séparateur sont reconnus tout seuls ; si le fichier est mal lu, ouvrez « Le fichier est mal lu ? Options avancées »). Si vous avez déjà enregistré un **profil** pour ce type de fichier, choisissez-le : les colonnes seront associées d'avance. Puis **Continuer : associer les colonnes**.

### Étape 2 : Associer les colonnes

Pour chaque colonne du fichier, indiquez le champ du TMS correspondant, ou **Ignorer**. L'écran devine les associations d'après les titres de colonnes ; il indique les **champs obligatoires** manquants et prévient si une colonne est vide dans les 5 premières lignes. Vous pouvez enregistrer l'association sous un nom de **profil** : au prochain fichier du même format, les colonnes seront déjà associées. Puis **Continuer : analyser le fichier**.

### Étape 3 : Associer les valeurs (si besoin)

Quand le fichier contient des valeurs inconnues (un secteur, un DAS, un statut, un responsable qui n'existent pas encore), l'écran **Associer les valeurs** vous demande pour chacune de :

- **l'associer à une valeur existante** de la liste, ou à un statut, ou à un utilisateur ;
- **la créer** comme nouvelle valeur de la liste (**Créer la valeur**, ou **Tout créer**) ;
- **laisser vide**.

### Étape 4 : Vérifier la simulation

Rien n'est encore écrit. La **simulation** annonce, pour votre fichier : les fiches qui seraient **créées** (Fiches créées), **mises à jour** (Fiches mises à jour), les lignes **ignorées** (Lignes ignorées) et les lignes **en erreur** (Lignes en erreur), avec le détail ligne par ligne. Lignes vides en fin de fichier : ignorées. SIRET abîmé par Excel (notation scientifique du type `7,32829E+13`) : la ligne est refusée avec un message clair, jamais devinée.

Choisissez **Que faire quand la fiche existe déjà ?** :

- **Ignorer la ligne** : la fiche existante n'est pas touchée ;
- **Compléter la fiche existante** : si la ligne correspond **à coup sûr** à une fiche (même SIRET, même e-mail ou téléphone, ou même nom dans la même ville), seuls ses champs **vides** sont remplis avec les valeurs du fichier ; rien de ce qui est déjà saisi n'est écrasé. Une ressemblance seulement possible est ignorée ;
- **Créer quand même** : une nouvelle fiche est créée (impossible si le SIRET existe déjà).

Quand une ligne porte un **identifiant source** déjà importé, c'est cette fiche qui est mise à jour, quelle que soit la stratégie.

L'import ne modifie que les fiches que vous pouvez vous-même modifier (votre périmètre « Créer et modifier » des fiches, par exemple « Ses fiches ») : une ligne qui correspond à une fiche existante hors de ce périmètre, ou une note destinée à une telle fiche, est **ignorée** avec le motif « Fiche existante hors de votre périmètre » ; la fiche n'est pas touchée et aucun doublon n'est créé.

### Étape 5 : Lancer l'import

**Lancer l'import**, puis confirmez. Une barre de progression suit l'avancement ; vous pouvez fermer la page et revenir plus tard dans **Imports**.

### Étape 6 : Rapport

Le **Rapport d'import** donne les totaux (fiches créées, mises à jour, ignorées, lignes en erreur) et un fichier **Télécharger le rapport (Excel)** avec le sort de chaque ligne et la raison des erreurs. Corrigez les lignes en erreur dans votre fichier et réimportez-le : les fiches déjà créées ne sont pas dupliquées.

### Annuler un import

Dans **Imports**, **Annuler l'import** ouvre une confirmation qui annonce combien de fiches seront supprimées. L'annulation :

- **supprime** (met à la corbeille) les fiches **créées** par cet import ;
- **conserve** une fiche créée par l'import si quelqu'un l'a **modifiée depuis**, si une note, un rattachement ou une pièce jointe y a été ajouté par ailleurs, ou si elle a été fusionnée. Ces fiches sont signalées dans le rapport ;
- **ne défait pas** les mises à jour : les fiches existantes que l'import avait complétées restent telles quelles ;
- pour un catalogue : supprime les formations créées, sauf celles modifiées depuis ou **utilisées** depuis par une opportunité ou un devis (signalées dans le rapport ; l'aperçu de la confirmation peut encore les compter). Réimporter ensuite le fichier crée de nouvelles formations.

Réimporter plus tard le même fichier peut restaurer les fiches mises à la corbeille par l'annulation, mais jamais celles que quelqu'un a supprimées volontairement.

L'historique des imports est conservé ; le fichier d'origine (et les valeurs brutes de ses lignes) est supprimé automatiquement au bout de 30 jours.

## 12. Exporter

Dans les listes **Entreprises**, **Personnes**, **Opportunités**, **Devis** et **Catalogue** (sur ordinateur), **Exporter (.xlsx)** télécharge un fichier Excel de **la liste telle qu'elle est filtrée**, avec les colonnes choisies dans **Colonnes**. Un contrôle préalable refuse l'export, avec un message, s'il dépasse la limite de lignes (50 000 par défaut) ou si vous exportez trop souvent. Chaque export est tracé dans le journal d'audit. Les valeurs commençant par `=`, `+`, `-` ou `@` sont neutralisées pour ne pas être exécutées par Excel. Les montants, probabilités et dates sont de vrais nombres et de vraies dates (affichés « 1 234,50 € » et « 20/12/2026 ») : Excel peut les additionner, les trier et les filtrer. Les autres colonnes (SIRET, téléphones…) restent du texte. Les exports des entreprises et des personnes proposent la colonne **Formations d'intérêt**. L'export des personnes propose aussi les colonnes **Système source**, **Identifiant source** (fiches importées) et **Base légale** (RGPD).

## 13. RGPD

Les données de personnes sont des données personnelles. Le TMS aide à respecter la durée de conservation, le droit d'opposition et le droit d'accès.

### Sur la fiche d'une personne

La section **RGPD** de la fiche affiche :

- la **Base légale** du traitement (intérêt légitime par défaut ; consentement, contrat ou obligation légale) ;
- les **Consentements** par canal (e-mail, SMS, téléphone, prospection) : **Enregistrer un consentement** ajoute un choix daté (**Accordé** ou **Refusé ou retiré**) avec sa source. L'historique est conservé : un nouveau choix s'ajoute sans effacer les précédents ;
- **Opposition à la prospection** : enregistre que la personne ne veut plus être sollicitée ; une mention **Opposé à la prospection** apparaît sur la fiche, dans les listes et la recherche ;
- **Exporter ses données** : fichier contenant toutes les données que le TMS détient sur la personne, y compris ses opportunités, ses actions, ses devis et ses **formations d'intérêt** (droit d'accès) ;
- **Anonymiser maintenant** : efface définitivement les données personnelles de la fiche (nom, coordonnées, notes, pièces jointes, formations d'intérêt, comptes rendus et lieux des actions, commentaire de perte des opportunités, destinataire et PDF archivé des devis…). Pour confirmer, tapez le nom de la personne. Seules des données statistiques (statut, DAS, rattachements, montants et étapes des opportunités, types et dates des actions) restent. **Cette opération est irréversible.**

> **Objet des opportunités d'un particulier** : il est **conservé** après l'anonymisation, pour les statistiques. S'il contient le nom de la personne, ce nom reste : ne mettez jamais de nom dans l'objet d'une opportunité.

### Durée de conservation

Une personne **sans relation contractuelle** (ni client, ni rattachée à une entreprise cliente) dont le **dernier contact** date de plus de **3 ans** (réglable de 1 à 10 ans, voir ci-dessous) est inscrite chaque nuit sur la liste **À anonymiser**. Elle y reste **30 jours**, puis est anonymisée automatiquement. Un nouveau contact (une note ou une action faite, par exemple) la retire de la liste.

### L'écran RGPD

L'écran **RGPD** (menu Commercial, réservé aux personnes ayant ce droit) présente :

- **Anonymisation prévue** : les personnes listées, leur dernier contact, la date d'inscription et la date d'anonymisation prévue. **Conserver** suspend l'anonymisation jusqu'à une date, avec un **motif** (formation en cours, litige…) ;
- **Durée de conservation en cours** : les personnes que vous avez choisi de conserver ;
- **Années après le dernier contact** : la durée de conservation de votre organisme, **Enregistrer** pour la modifier.

Chaque nuit où des personnes sont nouvellement listées, les responsables RGPD reçoivent un e-mail avec le nombre de personnes concernées (sans leurs noms).

## 13 bis. Chiffre d'affaires par DAS

Menu **Commercial > Chiffre d'affaires** (droit « Consulter le chiffre d'affaires »). Le tableau donne, pour la période choisie (**Mois**, **Trimestre** ou **Année**, flèches pour changer de période), le chiffre d'affaires HT de chaque DAS :

- **CA devis acceptés** : lignes des devis acceptés dans la période (date d'acceptation), par DAS de la ligne ;
- **CA opportunités gagnées sans devis** : montant des opportunités gagnées dans la période qui n'ont aucun devis accepté (une opportunité gagnée avec un devis accepté n'est jamais comptée deux fois) ;
- **Total**, **Objectif** et **Atteint** (barre et pourcentage). Sur la ligne Total, « Atteint » compare l'objectif au seul chiffre d'affaires des DAS **qui ont un objectif** (un DAS sans objectif ne gonfle pas le pourcentage) ; une phrase sous le tableau le rappelle quand certains DAS n'en ont pas. La carte « CA signé » de l'accueil fait de même.

Règles de calcul :

- une ligne de devis sans DAS compte pour le DAS de l'opportunité du devis ;
- les devis et les opportunités en corbeille ne comptent pas ;
- une opportunité gagnée ne compte que si elle n'a **aucun devis accepté** hors corbeille (sinon c'est le devis qui compte).

Cliquez sur un DAS pour ouvrir le détail (devis et opportunités, au centime, avec un lien vers chacun). Avec le droit « Gérer les objectifs de chiffre d'affaires », le bouton **Objectifs** fixe l'objectif de chaque DAS pour la période affichée (montant en euros, par exemple « 10 000 » ou « 10 000,50 » ; champ vide pour retirer l'objectif). L'objectif d'un trimestre n'est pas la somme de ses mois : chaque type de période a ses propres objectifs.

Avec le périmètre « Ses fiches », le tableau ne compte que vos devis et vos opportunités, sans objectif. L'accueil affiche la carte « CA signé » du mois dans votre périmètre.

## 14. Utilisateurs et droits (administrateur)

Le menu **Administration › Utilisateurs** permet à l'administrateur de l'organisme d'**inviter** des collègues, de **changer leur rôle**, de **désactiver** / **réactiver** un compte, de **renvoyer** ou **révoquer** une invitation et d'attribuer les **DAS suivis** à un Responsable de DAS. Un organisme garde toujours au moins un administrateur actif. L'administrateur règle aussi les **droits par domaine et leur périmètre** (« Ses fiches », « Ses DAS », « Tout l'organisme ») et crée des **rôles personnalisés** dans **Administration › Rôles et droits** ; tout est détaillé dans `admin.md` (§ 10). Avec « Ses fiches », vous ne voyez et ne modifiez que les fiches, opportunités et actions dont vous êtes le responsable ou le créateur.
