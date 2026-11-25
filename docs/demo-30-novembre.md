# Démonstration du TMS — 30 novembre 2026

Fil rouge : **une entreprise cliente demande une formation pour trois salariés**. On suit l'affaire du premier contact
à l'encaissement, puis on montre le parcours d'une personne qui postule et la qualité (Qualiopi). Durée visée :
**50 minutes** + questions.

> Version à installer sur le serveur avant la démo : **v0.15.24** (`sudo /opt/tms/ops/update.sh`).

**Deux parcours, à nommer dès le début** (ils répondent d'avance à « où sont les candidats après un devis ? ») :

| | L'entreprise achète | La personne postule |
|---|---|---|
| Point de départ | Devis signé | Candidature |
| Qui sont les stagiaires | Salariés désignés par l'entreprise | Alternant, particulier, demandeur d'emploi |
| Admission | Aucune : inscription directe | Entretien, test de positionnement, « Admis » |
| Chemin | Devis → **Créer le dossier** → inscriptions | Candidature → **Créer le dossier** |
| Contractant | L'entreprise | L'employeur du contrat, ou la personne (particulier) |
| Sections de la démo | 2 à 8 | 8 bis |

Un devis ne crée pas de candidatures : quand l'entreprise achète, il n'y a personne à admettre.

---

## 0. Préparation (la veille, 30 minutes)

Sur le Mac ou le serveur de démonstration :

1. **Bac à sable** : Administration › Bac à sable › « Repartir de zéro » (organisme et société fictifs). Sur le serveur
   réel, ne rien effacer : créer seulement les fiches ci-dessous avec des noms fictifs.
2. **`APP_URL`** = `http://MacBook-Pro-de-Sebvtl.local:8080` (Mac) pour que liens et QR codes s'ouvrent depuis un
   téléphone. Vérifier sur le téléphone : la page de connexion s'affiche (pas de page blanche).
3. **E-mails** : un serveur SMTP configuré (Administration › Connecteurs) avec **ta propre adresse** comme
   destinataire de tous les envois de la démo (contact de l'entreprise, stagiaires) : tu montres les e-mails reçus en
   direct.
4. **Société** (Administration › Sociétés) : logo, signature et cachet, déclaration d'activité, préfixes devis et
   factures.
5. **Catalogue** : une formation complète, par exemple « Hygiène alimentaire HACCP — 14 h », tarif entreprise
   **450 € HT par stagiaire**, DAS renseigné, une plaquette PDF jointe. Pour une formation certifiante : saisir le
   **code RNCP** (encadré vert de la fiche officielle) puis **Reprendre du RNCP** (objectif BPF, NSF, grille de
   compétences). Sur le serveur, lancer une fois `tms:rncp:sync` (voir `tms/docs/admin.md` § 24).
6. **Fiches** : une entreprise « Restaurant Le Bon Goût » (SIRET fictif, **IDCC 1979**) avec un interlocuteur (toi)
   et trois salariés (Léa Durand, Marc Petit, Sami Benali). Une fiche **AKTO** (type OPCO, conventions couvertes
   reprises par « Reprendre les IDCC officiels de… »). Pour le parcours individuel : une personne « Inès Martin »
   (ton adresse e-mail), une session inter à venir du même DAS que la formation, un test de positionnement publié.
7. **Automatismes** (Administration › Automatismes) : convocations à **7 jours**, rappel de la veille coché,
   relance des signatures à 3 jours (2 relances au plus), **attestations automatiques cochées** pour la démo.
8. **Comptes** : un compte formateur fictif (rôle Formateur) relié à une fiche formateur, pour montrer « Mon planning ».
9. Ouvrir à l'avance, dans des onglets : Accueil, Pipeline, la fiche entreprise.

Pour gagner du temps le jour J, **préparer deux exemplaires** de l'affaire : un « vierge » pour le déroulé en direct,
un « terminé » (session passée, mois clôturé, facture validée) pour montrer la fin sans attendre.

---

## 1. Accueil et fiches (5 min)

- **Accueil** : relances du jour, pipeline pondéré du mois, taux de réussite des devis, prochains rendez-vous, sessions
  qui démarrent dans les 7 jours. Message : « chacun voit d'abord ce qu'il a à faire aujourd'hui ».
- **À traiter** (menu, avec son total) : une seule page regroupe ce qui attend quelqu'un : devis sans réponse,
  actions commerciales, sessions proches avec des étapes à faire, rattrapages à confirmer, demi-journées non validées,
  candidatures, montants à facturer, factures à relancer. Chaque ligne ouvre
  l'écran filtré. Message : « rien ne tombe entre deux chaises ».
- **Ctrl K** : rechercher « Bon Goût » → fiche entreprise : interlocuteurs, historique, formations d'intérêt.
- Point fort à dire : **droits par périmètre** (ses fiches, son DAS, tout) et double authentification pour la
  direction.

## 2. Opportunité et devis (8 min)

1. Sur la fiche : **nouvelle opportunité** « HACCP — 3 salariés », formation HACCP, montant estimé.
2. **Pipeline** : glisser la carte d'une étape à l'autre (motif demandé pour « Perdu »).
3. Depuis l'opportunité : **Nouveau devis** → lignes préremplies depuis le catalogue (3 × 450 € HT), TVA (ou
   exonération selon la société), totaux au centime.
4. **Aperçu PDF** (filigrane BROUILLON), puis **Envoyer** avec la plaquette cochée et **Joindre un lien de signature
   en ligne** (cochée par défaut) → un **seul e-mail** porte le devis, la plaquette et le lien « bon pour accord » ;
   l'opportunité passe d'elle-même à « Proposition ». Le bandeau en bas de l'écran confirme l'envoi.
5. Sur le téléphone : ouvrir l'e-mail, le lien, **recevoir le code** puis **dessiner la signature**, accepter, signer.
   Revenir : le devis est **Accepté**, PDF signé avec page de preuve. Dire : sans réponse, le client est relancé seul
   (nouveau lien à chaque relance).
6. Passer l'opportunité en **Gagné** (montant repris du devis).

## 3. Du devis au dossier (5 min)

1. Sur le devis accepté : **Créer le dossier** → assistant en trois écrans : récapitulatif, **nouvelle session** intra
   pour l'entreprise (intitulé « HACCP — Le Bon Goût — décembre »), les **trois stagiaires** choisis parmi les salariés.
2. Tout est créé d'un coup : dossier `D-2026-…`, session `S-2026-…`, inscriptions, financement initial. La session
   reçoit **sa couleur** (pastille dans la liste, bord de sa carte dans les colonnes, en tête de sa page).
3. Montrer : un devis ne donne qu'un dossier (le bouton devient **Voir le dossier**).

## 4. Préparer la session (7 min)

Page de la session, barre d'étapes :

1. **Synthèse** : cartes d'avancement (heures planifiées, formateurs, salles, inscriptions, financements…), toutes « À
   faire » au départ. Message : « la session dit elle-même ce qui manque ».
2. **Planning** : l'assistant de séances génère 2 jours de 9 h–12 h 30 / 13 h 30–17 h, formateur et salle → les cartes
   passent au vert ; un conflit (formateur pris ailleurs) est signalé. **Planning des séances** : chaque séance porte
   la couleur de sa session, on repère d'un coup d'œil ce qui appartient à quoi.
3. **Financements** (page du dossier) : **Ajouter un financement** → **AKTO est déjà proposé**, d'après l'IDCC de
   l'entreprise (table officielle de France compétences). Montant, « Paie directement l'organisme », état « Accordé »
   → « Reste à financer » se met à jour.
4. **Documents** (page du dossier) : **Émettre : convention de formation** → PDF numéroté, archivé ; **Demander la
   signature** (même parcours que le devis).
5. **Documents des stagiaires** (étape Stagiaires) : chaque document dit s'il part **« Automatique »** (convocations
   7 jours avant le début, session confirmée : « Partira seul » avec sa date) ou **« À faire à la main »**. Pour la
   démo, ouvrir **Émettre ou envoyer à la main** et envoyer les convocations tout de suite. Dire : la veille de chaque
   journée, stagiaires et formateurs reçoivent un rappel seuls.
6. Se connecter en **formateur** (autre navigateur) : « Mon planning » ne montre que ses séances et ses stagiaires,
   jamais les prix ni les financements.

## 5. Présences et clôture (5 min)

Sur l'exemplaire « terminé » :

1. Étape **Suivi**, onglet **Présences** : **Tous présents**, puis une exception (Sami : partiel 5 h).
2. **Clôturer le mois** → plus rien ne bouge ; la session passe **Terminée**.
3. **Documents des stagiaires** : attestations et certificats de réalisation (heures réalisées reprises des présences).
   Dire : avec l'automatisme coché, ils sont **émis et envoyés seuls le dernier jour à 13 h 30** (l'après-midi compté),
   jamais pour un stagiaire absent ce jour-là ni à 0 h. Pour le montrer sans attendre : Administration › Automatismes ›
   **Exécuter maintenant**.

## 6. Émargement en ligne et rattrapage (7 min)

1. Le jour de la séance, onglet **Présences** : **Ouvrir l'émargement** du matin → QR code plein écran.
2. Sur le téléphone : scanner, choisir son nom, **signer au doigt**. Le nom disparaît de la liste ; l'écran du
   formateur se met à jour.
3. Le formateur signe et **valide** → la grille des présences se remplit seule ; feuille d'émargement PDF signée et
   scellée, téléchargeable (preuve Qualiopi et financeur).
4. **Suivi › Séances du mois** : le bouton **Présences** porte un voyant **vert** (journée validée) ou **orange**
   (une seule demi-journée validée) ; le tableau s'ouvre sur la semaine en cours.
5. **Rattrapage** (sur l'exemplaire « terminé », une demi-journée passée où Sami n'a pas signé) : sur l'écran de la
   demi-journée, **Envoyer un lien** à Sami → sur le téléphone, ouvrir l'e-mail, demander le code, signer. Revenir :
   « Rattrapage à confirmer » ; onglet **Présences** de la session → **Confirmer** → badge « Rattrapage confirmé »
   (voyant vert), présence écrite, mention sur la feuille signée. Variante : **Ouvrir le rattrapage sur place**
   (QR jusqu'à minuit). Jour jamais ouvert : **Faire un rattrapage** depuis les présences de la séance.
6. **Plusieurs demi-journées à rattraper** pour un même stagiaire : onglet **Présences**, **Rattrapage de plusieurs
   demi-journées** → choisir le stagiaire, cocher les demi-journées (jamais une absence notée ou excusée) → un seul
   e-mail, un seul code, **une seule signature** pour toutes ; au retour, **Tout confirmer pour …**.
7. Inscrit qui entre plus tard : **Faire entrer ce jour-là** sur l'écran de la demi-journée.

## 7. Questionnaires de satisfaction (4 min)

1. Étape **Stagiaires**, bloc **Questionnaires** : le questionnaire à chaud est **« Automatique »** : il part seul
   **le dernier jour à 13 h 30** ; le questionnaire à froid part seul 3 mois après. Pour la démo, ouvrir **Envoyer à la
   main** → **Lancer maintenant** (e-mail à chaque stagiaire) et **Afficher le QR code**.
2. Sur le téléphone : répondre (notes sur 5, commentaire).
3. Résultats sur la session (taux de réponse, moyenne, satisfaits), carte « Satisfaction » de la synthèse. Le
   questionnaire à froid s'envoie aussi à la main une fois la session terminée (bouton grisé avant, avec la raison).

## 8. Facturer et encaisser (7 min)

1. **Finance › À facturer** : le dossier apparaît avec le montant dû par destinataire (entreprise et OPCO), détail
   dépliable (heures réalisées, part de chaque financeur). **Préparer les brouillons**.
2. Ouvrir un brouillon, ajouter une ligne libre (frais), **Valider** → numéro définitif `F-2026-…`, PDF archivé,
   facture **immuable** (une correction = un avoir). Dire : journal chaîné vérifiable (Finance › Factures ›
   intégrité).
3. **Envoyer** la facture par e-mail.
4. **Enregistrer un règlement** depuis la facture (virement) → facture **Soldée**.
5. Montrer **À relancer** (relances à 7, 30, 60 jours, automatiques si on le veut).
6. Si le public est concerné par les groupements : **Facture libre** comme mandataire, ligne « Réalisé par [co-traitant] », puis
   **À reverser** (dû, reversé, reste).

## 8 bis. Parcours individuel : la personne postule (5 min)

Dire : « autre porte d'entrée, sans devis : une personne qui veut se former ».

1. **Admissions › Nouvelle candidature** pour Inès Martin : formation, puis **DAS** puis **session visée** (seules les
   sessions du DAS sont proposées), parcours (alternance ou stagiaire). Informations complémentaires : N° de sécurité
   sociale contrôlé (clé), visible seulement avec le droit « données sensibles ».
2. **Envoyer le test de positionnement** → sur le téléphone, passer le test (plein écran) ; revenir : « Reçu le », note.
3. Passer l'étape à **Admis**.
4. **Créer le dossier** : choisir le **contractant** (l'employeur pour une alternance, le candidat pour un
   particulier), inscription dans la session visée, financement. Le dossier rejoint ensuite le même circuit que
   l'entreprise (convention ou contrat, présences, facture).
5. Alternance : carte **Alternance** de la candidature → **Livret d'apprentissage (PDF)** déjà rempli (partenaires,
   parcours avec dispenses, planning, pages de suivi datées mois par mois, grilles de compétences) et **Fiche Cerfa
   (PDF)** : toutes les rubriques du Cerfa remplies, le reste en « à compléter », à recopier sur le portail de l'OPCO.

## 9. Pilotage et qualité (4 min)

1. **Tableaux de bord** (Commercial) : semaine / mois, export CSV ou PDF.
2. **Chiffre d'affaires** : par DAS, objectifs, taux atteint.
3. **Qualité › Qualiopi** : 33 indicateurs, mesures automatiques (fiches formation complètes, taux d'abandon,
   satisfaction si livrée), preuves jointes, **dossier d'audit** en ZIP. **Réclamations** (indicateur 31) et
   **Amélioration continue** (indicateur 32) : registres tenus dans l'outil, comptés dans les mesures.

## 10. Conclusion (2 min)

- Hébergé chez CAP FORMATION (Proxmox), sauvegarde chiffrée chaque nuit, mise à jour en une commande avec retour
  arrière automatique.
- **Test réel début décembre** avec l'équipe : comptes, rôles, double authentification, e-mails.
- **Déroulement automatique** : convocations, rappel de la veille, relance des signatures, attestations ; chaque
  envoi ne part qu'une fois, réglable dans Administration › Automatismes.
- Déjà prévu ensuite : import Formevol, acomptes par financeur, dépôt Chorus, export Sage.

---

## Plan B si quelque chose casse

| Problème | Que faire |
|---|---|
| E-mail qui ne part pas | Montrer le bandeau « L'e-mail n'est pas parti » et **Renvoyer** ; c'est aussi une fonction. |
| Rattrapage impossible (« Pas d'émargement en ligne ce jour-là ») | Se connecter en gestionnaire (pas formateur) : bouton **Faire un rattrapage**. |
| Lien ou QR code qui ne s'ouvre pas sur le téléphone | Vérifier le Wi-Fi commun et `APP_URL` ; sinon ouvrir le lien sur le Mac. |
| PDF qui ne se génère pas | Gotenberg arrêté : `docker compose up -d` dans `tms/`, réessayer (aucun numéro n'est perdu). |
| Page blanche | Le serveur Vite tourne : `docker compose stop vite && rm -f public/hot`. |
| Un automatisme n'a rien envoyé | Session **confirmée** ? Première séance dans la fenêtre (J+2 à J+7) ? Puis **Exécuter maintenant** (Administration › Automatismes). |
| « Créer le dossier » d'une candidature refusé | Lire le message sous le bouton : contractant (entreprise ou particulier) ou session visée à revoir. |
| Lenteur ou erreur au milieu | Basculer sur l'exemplaire « terminé » préparé la veille. |
