# Admissions : candidats, apprenants, alternants

Le module **Admissions** suit le recrutement des candidats, de leur identification à leur entrée en formation. Menu **Formation › Candidats** (au-dessus de Dossiers). Réglages : `admin.md` § 19 ; tables : `data-dictionary.md`.

## 1. Le parcours

Une **candidature** relie une personne du CRM à une formation visée. Elle avance par étapes :

1. **Identifié** — la candidature est ouverte ;
2. **Test envoyé** — le test de positionnement est parti (par **Envoyer le test**, ou hors du TMS) ;
3. **Test reçu** — le candidat l'a renvoyé ;
4. **Entretien** — le **test est obligatoire** : on ne passe à Entretien (ni à Admis) qu'une fois sa date de réception enregistrée (à la main, ou par la copie / les réponses du candidat) ;
5. **Admis** — ou **Non retenu** (motif obligatoire, référentiel « Motifs de non-admission ») ou **Désisté**.

On peut sauter une étape (admettre sans test) et revenir en arrière tant que le dossier de formation n'existe pas. Saisir une date (envoi ou retour du test, entretien) **propose** de passer à l'étape correspondante, sans le faire tout seul.

Une fois admis, **Créer le dossier** fait du candidat un **stagiaire** de la formation continue (dossier de formation, inscription à la session visée) ou, avec un contrat d'alternance, un **alternant**. Ces deux mots ne sont pas des étapes : ils se lisent dans le dossier et le contrat.

## 2. Ouvrir une candidature

- **Depuis le CRM** : sur la fiche d'un interlocuteur, onglet **Candidatures**, bouton **Ouvrir une candidature** : l'interlocuteur devient candidat, sans ressaisie.
- **Depuis Candidats › Nouvelle candidature** : choisissez une personne du CRM ou créez-la (elle est créée comme **particulier** ; une personne semblable est signalée avant création).

Une personne peut avoir plusieurs candidatures (autre formation, autre année) : chacune garde son historique.

## 3. La fiche candidature

- **Identité** : lue dans le CRM (nom, naissance, coordonnées). **Modifier dans le CRM** n'apparaît que si vos droits sur le CRM le permettent. Un candidat **mineur** (à la date de début du contrat, sinon de la session visée, sinon aujourd'hui) affiche un avertissement : renseignez le **représentant légal**.
- **Orientation** : **parcours** (*À définir*, *Alternance* ou *Stagiaire* — formation continue sans contrat), **DAS** d'abord, puis la formation visée (la liste ne montre que les formations du DAS choisi ; changer de DAS retire une formation d'un autre DAS) et son **code RNCP** (repris de la fiche formation du catalogue, modifiable), puis la **session visée**, qui doit appartenir à la formation et au DAS de la candidature (enregistrez d'abord un changement de DAS ou de formation pour voir ses sessions), responsable, origine, identifiant France Travail, situation avant la formation et niveau de diplôme (enregistrés **sur la fiche du CRM** de la personne), dernier diplôme.
- **Test de positionnement** : les **tests de la bibliothèque** rattachés à la formation visée ou à son DAS (Catalogue › Plaquettes et tests, type « Test de positionnement »), téléchargeables ; **Générer le lien** (droit de modifier) : crée le **lien personnel** du candidat sans e-mail ; il s'affiche sur la carte avec **Copier**, à transmettre comme vous voulez (SMS, e-mail…), tant qu'il est valable. **Envoyer par e-mail** : tests à joindre (cochés), destinataire (adresse de la fiche, modifiable), objet et message du modèle « Envoi d'un test de positionnement », qui doit garder **{lien}** — le **lien personnel** du candidat, valable **14 jours** ; un nouvel envoi remplace le lien précédent. L'envoi remplit « Envoyé le » s'il est vide et passe « Identifié » à « Test envoyé ». Le candidat ouvre son lien **sans compte**, télécharge le test et **dépose sa copie une seule fois** (1 à 5 fichiers PDF, JPG ou PNG, 20 Mo chacun, vérifiés par leur contenu) : les fichiers arrivent dans la carte, « Reçu le » se remplit s'il est vide et l'étape passe à « Test reçu » si elle était avant (jamais en arrière). Un lien inconnu, expiré, remplacé, déjà utilisé, ou d'une candidature non retenue, désistée ou à la corbeille affiche « Lien invalide ou expiré ». La page du candidat ne montre que son prénom, la formation et les tests. L'historique des envois (état de l'e-mail et du lien) suit la liste des tests. Aussi : dates d'envoi et de retour, score ou niveau ; les copies déposées par le candidat s'affichent sous la carte (pas d'ajout de fichier à la main : pour un test papier fait sur place, saisissez la date de réception et le score).
- **Entretien** : date, personne qui l'a mené ; **compte rendu, RQTH et besoins d'adaptation** sont réservés au droit « Voir les données sensibles des candidats » (ils ne sont même pas envoyés à l'écran des autres).
- **Alternance** (parcours *Alternance*, à partir de l'entretien ; carte masquée pour un stagiaire) : contrat d'apprentissage ou de professionnalisation, entreprise d'accueil, maître d'apprentissage ou tuteur, dates, OPCO, contrat signé (fichier). Le contrat ne se retire plus une fois le dossier créé. Enregistrer un contrat met le parcours à *Alternance* ; il ne change plus tant que le contrat existe, et un contrat est refusé à un candidat au parcours *Stagiaire*.
- **Informations complémentaires** : les champs personnalisés de votre organisme (`admin.md` § 19), affichés dès l'ouverture de la candidature, quelle que soit l'étape. Un champ « requis pour l'admission » vide empêche de passer à **Admis**.
- **Historique** : ouverture et changements d'étape.

Le CERFA, le calcul NPEC et les échanges avec les OPCO ne sont pas gérés par le TMS.

## 3 bis. Tests en ligne

Menu **Formation › Tests en ligne** (droit « Gérer le catalogue ») : des tests de positionnement passés **à l'écran** par le candidat.

- **Nouveau test** (titre), ou **Créer depuis un PDF** : l'intelligence artificielle de l'organisme (Administration › Connecteurs) lit un test papier et prépare les sections et les questions en **brouillon**, **en tâche de fond** (jusqu'à 10 minutes avec une IA locale : la page affiche « Import en cours… » et se met à jour seule ; en cas d'échec, le motif s'affiche et le test reste saisissable à la main) — jamais les bonnes réponses, absentes du papier. Relisez, complétez, puis publiez.
- **Éditeur** : sections (titre, consigne), questions — réponse courte, réponse rédigée, choix unique, choix multiple, vrai / faux, nombre à compléter, oui / non —, texte d'appui (texte à corriger, article), **bonne réponse** et **points** ; DAS et formations concernés ; durée limite (5 à 240 minutes, ou libre). Une question à bonne réponse se corrige seule (choix multiple : tout ou rien ; nombre : à la tolérance près, 0,01 par défaut) ; une réponse rédigée ou une question sans bonne réponse mais avec des points se note à la main ; sans points, c'est une question de profil.
- **Publier** fige une **version** : un candidat répond toujours à la version qu'il a ouverte ; vos modifications suivantes restent en brouillon jusqu'à la publication suivante.

Sur la candidature, la carte « Test de positionnement » propose les tests en ligne publiés de la formation ou du DAS : choisissez « En ligne : … » puis **Générer le lien** (ou **Envoyer par e-mail**). Le candidat :
- ouvre le lien **sur un ordinateur** (écran d'au moins 1024 px et souris) ; sur un téléphone ou une tablette, la page dit « Ouvrez ce lien sur un ordinateur » ;
- clique **Commencer** (le chronomètre démarre), répond partie par partie ; ses réponses sont **enregistrées au fur et à mesure** (il peut rouvrir le lien après une coupure) ; **Envoyer mes réponses** termine, la fin du temps envoie seule ;
- ne peut ni coller, ni copier le texte des questions, ni ouvrir le menu du clic droit ; chaque **sortie de la page** (autre onglet, autre fenêtre) et chaque collage refusé sont relevés. Rien n'empêche une IA sur un second appareil : ces mesures rendent la triche plus difficile et visible.

À l'envoi : « Reçu le » se remplit, l'étape passe à « Test reçu » (jamais en arrière), « Score ou niveau » affiche la note automatique (« 8 / 20 (réponses rédigées à corriger) »). La carte reste courte : test et version, note, date d'envoi, temps passé, sorties de la page et collages refusés. **Aperçu du PDF** ouvre la copie du candidat dans un nouvel onglet (réponses à côté des bonnes, points, temps, traces, note, toujours à jour de la dernière correction) ; le bouton **Télécharger** apparaît ensuite. **Corriger** (s'il y a des réponses rédigées) ouvre la notation : notez chaque réponse de 0 au barème de la question, puis **Enregistrer la correction** ; la note finale (« 17 / 20 ») remplace la note provisoire.

## 4. Créer le dossier de formation

Sur une candidature **admise**, **Créer le dossier** (droits : modifier la candidature **et** créer des dossiers dans son DAS) propose :

- le **contractant** : l'entreprise d'accueil du contrat, sinon l'entreprise de la session visée si elle est **intra** (seuls ses stagiaires y entrent), sinon la personne si c'est un particulier, sinon une entreprise à choisir. Vous pouvez toujours basculer entre **Une entreprise** et **Le candidat (particulier)** : le prix suit (tarif entreprise ou particulier) ;
- un refus de l'inscription (session intra d'une autre entreprise, session complète…) s'affiche sous **Session** ; toute autre erreur s'affiche dans la fenêtre ;
- une ligne de formation au **tarif** du catalogue (entreprise ou particulier), modifiable ;
- l'**inscription** à la session visée (facultative : sans session, le dossier est créé sans inscription) ;
- avec un OPCO renseigné, un **financement** par l'OPCO (type et montant à indiquer), payé directement à l'organisme.

Une candidature n'a qu'un dossier ; mis à la corbeille, il peut être recréé. La page du dossier affiche le bloc **Candidature**.

## 4 bis. Livret d'apprentissage

Dès qu'un contrat d'alternance est saisi, la carte **Alternance** propose **Livret d'apprentissage (PDF)**, avec le droit « Consulter les candidatures ». Le livret sort **déjà rempli** avec ce que le TMS sait :

1. **Couverture** : entreprise, apprenti, période du contrat, titre visé, mot de la direction.
2. **Partenaires** : apprenti, représentant légal, employeur (activité = secteur de la fiche), maître d'apprentissage (= tuteur du contrat), OPCO, centre de formation (société par défaut).
3. **Équipe** : les rôles d'Administration › Livret d'apprentissage et les formateurs des séances avec les modules qu'ils animent.
4. **Parcours individualisé** : modules de la formation avec leur durée et la case **Dispensé**, plus la durée en centre (séances prévues de la session du dossier).
5. **Planning** mois par mois du contrat (heures en centre), puis les périodes en entreprise.
6. **Annexes** de l'organisme (PDF déposés une fois : statut de l'apprenti, règlements…), insérées telles quelles.
7. **Pages de suivi mensuel** déjà titrées : une page « entreprise » chaque mois, plus une page « centre de formation » les mois où il y a des séances.
8. **Grilles d'évaluation** : la grille de compétences de la formation (catalogue, onglet Compétences), colonnes A / ECA / NA centre et entreprise.
9. **Suivis en entreprise** : le tableau des visites.

Les pages de suivi, les grilles et les visites se remplissent encore **à la main**. Le livret est généré à chaque clic, jamais stocké, et suit donc les dernières données.

**Modules dispensés** : sous le bouton, cochez les modules dont l'apprenti est dispensé, puis **Enregistrer les dispenses** (droit « Modifier les candidatures »).

## 4 ter. Fiche Cerfa du contrat d'apprentissage

À côté du livret, **Fiche Cerfa (PDF)** (droit « Consulter les candidatures ») reprend les rubriques du Cerfa du contrat d'apprentissage, dans son ordre, pour les recopier sur le portail de l'OPCO :

- **employeur** : dénomination, SIRET, NAF, IDCC, adresse, contacts, OPCO ;
- **apprenti** : identité, date de naissance, adresse, contacts, situation, diplômes, RQTH (montrée seulement à qui voit les données sensibles), représentant légal ;
- **maître d'apprentissage** ;
- **contrat** : type et dates ;
- **formation et CFA** : société par défaut (SIRET, n° de déclaration d'activité, adresse), titre visé, **code RNCP**, heures en centre (sinon durée totale), dates de la session.

Ce que le TMS ne connaît pas (NIR, nationalité, effectif, salaire, durée hebdomadaire, UAI…) est marqué **« à compléter »**. La fiche est générée à chaque clic, jamais stockée.

## 5. Liste « Candidats »

Onglets **Candidats** (sans dossier en cours ; non retenus et désistés seulement avec le filtre d'étape), **Stagiaires** (dossier sans contrat), **Alternants** (dossier et contrat), **Tous** ; filtres étape, formation, DAS, responsable, **parcours** (dont *À définir*), type de contrat ; recherche par nom ou numéro. **Exporter (.csv)** (droit « Exporter ») : numéro, nom, prénom, étape, parcours, formation, code RNCP, DAS, responsable, contrat, employeur, dates, dossier — jamais de donnée sensible.

## 6. Données personnelles

- Les données personnelles et sensibles ne figurent jamais en clair dans le journal d'audit.
- L'**export RGPD** d'une personne contient ses candidatures (données sensibles et champs personnalisés compris) : il est réservé à qui voit tout l'organisme, candidatures et données sensibles comprises.
- L'**anonymisation** d'une personne efface aussi les données personnelles, sensibles, personnalisées et les fichiers de ses candidatures (numéro, étape, formation et dates restent).
- **Conservation** : une candidature **non retenue** ou **désistée** est anonymisée automatiquement **2 ans** après sa dernière modification (commande `tms:admissions:purge`, chaque nuit à 3 h 50). La fiche du CRM suit sa propre règle.

## Pastille « Candidats » du responsable de DAS

Un utilisateur dont le droit « Candidats » est sur **Ses DAS** (le responsable de DAS) voit dans le menu, à côté de
« Candidats », un **compteur rouge** : le nombre de candidatures encore à l'étape **Identifié** dans ses DAS. Une
candidature sort du compteur dès qu'elle quitte cette étape (test envoyé, entretien, non retenu…). Le compteur se met à
jour en une minute au plus ; les utilisateurs qui voient toutes les candidatures (ou seulement les leurs) n'en ont pas.

