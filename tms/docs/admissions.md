# Admissions : candidats, apprenants, alternants

Le module **Admissions** suit le recrutement des candidats, de leur identification à leur entrée en formation. Menu **Commercial › Candidats**. Réglages : `admin.md` § 19 ; tables : `data-dictionary.md`.

## 1. Le parcours

Une **candidature** relie une personne du CRM à une formation visée. Elle avance par étapes :

1. **Identifié** — la candidature est ouverte ;
2. **Test envoyé** — le test de positionnement (fait hors du TMS) est parti ;
3. **Test reçu** — le candidat l'a renvoyé ;
4. **Entretien** ;
5. **Admis** — ou **Non retenu** (motif obligatoire, référentiel « Motifs de non-admission ») ou **Désisté**.

On peut sauter une étape (admettre sans test) et revenir en arrière tant que le dossier de formation n'existe pas. Saisir une date (envoi ou retour du test, entretien) **propose** de passer à l'étape correspondante, sans le faire tout seul.

Une fois admis, **Créer le dossier** fait du candidat un **stagiaire** de la formation continue (dossier de formation, inscription à la session visée) ou, avec un contrat d'alternance, un **alternant**. Ces deux mots ne sont pas des étapes : ils se lisent dans le dossier et le contrat.

## 2. Ouvrir une candidature

- **Depuis le CRM** : sur la fiche d'un interlocuteur, onglet **Candidatures**, bouton **Ouvrir une candidature** : l'interlocuteur devient candidat, sans ressaisie.
- **Depuis Candidats › Nouvelle candidature** : choisissez une personne du CRM ou créez-la (elle est créée comme **particulier** ; une personne semblable est signalée avant création).

Une personne peut avoir plusieurs candidatures (autre formation, autre année) : chacune garde son historique.

## 3. La fiche candidature

- **Identité** : lue dans le CRM (nom, naissance, coordonnées). **Modifier dans le CRM** n'apparaît que si vos droits sur le CRM le permettent. Un candidat **mineur** (à la date de début du contrat, sinon de la session visée, sinon aujourd'hui) affiche un avertissement : renseignez le **représentant légal**.
- **Orientation** : **parcours** (*À définir*, *Alternance* ou *Stagiaire* — formation continue sans contrat), formation visée et son **code RNCP** (repris de la fiche formation du catalogue, modifiable), session visée, DAS, responsable, origine, identifiant France Travail, situation avant la formation et niveau de diplôme (enregistrés **sur la fiche du CRM** de la personne), dernier diplôme.
- **Test de positionnement** : dates d'envoi et de retour, score ou niveau, fichier joint (PDF, JPG ou PNG, 20 Mo au plus).
- **Entretien** : date, personne qui l'a mené ; **compte rendu, RQTH et besoins d'adaptation** sont réservés au droit « Voir les données sensibles des candidats » (ils ne sont même pas envoyés à l'écran des autres).
- **Alternance** (parcours *Alternance*, à partir de l'entretien ; carte masquée pour un stagiaire) : contrat d'apprentissage ou de professionnalisation, entreprise d'accueil, maître d'apprentissage ou tuteur, dates, OPCO, contrat signé (fichier). Le contrat ne se retire plus une fois le dossier créé. Enregistrer un contrat met le parcours à *Alternance* ; il ne change plus tant que le contrat existe, et un contrat est refusé à un candidat au parcours *Stagiaire*.
- **Informations complémentaires** : les champs personnalisés de votre organisme (`admin.md` § 19). Un champ « requis pour l'admission » vide empêche de passer à **Admis**.
- **Historique** : ouverture et changements d'étape.

Le CERFA, le calcul NPEC et les échanges avec les OPCO ne sont pas gérés par le TMS.

## 4. Créer le dossier de formation

Sur une candidature **admise**, **Créer le dossier** (droits : modifier la candidature **et** créer des dossiers dans son DAS) propose :

- le **contractant** : l'entreprise d'accueil du contrat, sinon la personne si c'est un particulier, sinon une entreprise à choisir ;
- une ligne de formation au **tarif** du catalogue (entreprise ou particulier), modifiable ;
- l'**inscription** à la session visée (facultative : sans session, le dossier est créé sans inscription) ;
- avec un OPCO renseigné, un **financement** par l'OPCO (type et montant à indiquer), payé directement à l'organisme.

Une candidature n'a qu'un dossier ; mis à la corbeille, il peut être recréé. La page du dossier affiche le bloc **Candidature**.

## 5. Liste « Candidats »

Onglets **Candidats** (sans dossier en cours ; non retenus et désistés seulement avec le filtre d'étape), **Stagiaires** (dossier sans contrat), **Alternants** (dossier et contrat), **Tous** ; filtres étape, formation, DAS, responsable, **parcours** (dont *À définir*), type de contrat ; recherche par nom ou numéro. **Exporter (.csv)** (droit « Exporter ») : numéro, nom, prénom, étape, parcours, formation, code RNCP, DAS, responsable, contrat, employeur, dates, dossier — jamais de donnée sensible.

## 6. Données personnelles

- Les données personnelles et sensibles ne figurent jamais en clair dans le journal d'audit.
- L'**export RGPD** d'une personne contient ses candidatures (données sensibles et champs personnalisés compris) : il est réservé à qui voit tout l'organisme, candidatures et données sensibles comprises.
- L'**anonymisation** d'une personne efface aussi les données personnelles, sensibles, personnalisées et les fichiers de ses candidatures (numéro, étape, formation et dates restent).
- **Conservation** : une candidature **non retenue** ou **désistée** est anonymisée automatiquement **2 ans** après sa dernière modification (commande `tms:admissions:purge`, chaque nuit à 3 h 50). La fiche du CRM suit sa propre règle.
