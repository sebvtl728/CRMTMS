# Journal des modifications

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) ; versions selon
[SemVer](https://semver.org/lang/fr/).

## [Non publié]

## [0.15.12] - 2026-11-23

### Modifié

- **Import** : un export Entreprises ou Personnes se **réimporte tel quel**. Toutes les colonnes sont reconnues : rôles, formations d'intérêt, base légale, type particulier ou interlocuteur. Les entreprises, la fonction et les coordonnées pro d'une personne créent son rattachement, l'entreprise étant retrouvée par son nom exact. Dans l'export Personnes, les entreprises sont maintenant séparées par « ; ».
- **Menu Administration** rangé en quatre sections : Organisme, Listes et réglages, Modèles, Technique et sécurité.

## [0.15.11] - 2026-11-23

### Ajouté

- **Livret d'apprentissage (PDF)** sur la carte Alternance d'une candidature : déjà rempli (partenaires, équipe, parcours avec dispenses, planning, annexes de l'organisme, pages de suivi datées mois par mois, grilles de compétences, visites). Réglages dans Administration › Livret d'apprentissage.
- **Grille de compétences** d'une formation (activités types, compétences, savoir-faire), saisie en texte dans la fiche.

## [0.15.10] - 2026-11-23

### Ajouté

- **Fiche formation** : **Objectif (BPF)** et **Spécialité NSF** (liste officielle des 93 groupes), préparation du bilan pédagogique et financier ; colonnes de l'export.
- **Programme (PDF)** de chaque formation, mis en page depuis sa fiche et **daté de sa dernière mise à jour** (Qualiopi, indicateur 1).

## [0.15.9] - 2026-11-23

### Ajouté

- **Prochaine action** des opportunités : colonne de la liste et ligne des cartes du pipeline (date, type, objet ; en rouge si en retard), comme le demande le cahier des charges.

### Modifié

- **Fiche entreprise** : « Types » devient **Rôle(s)** (avec une aide) ; le **statut commercial** (prospect, client, inactif) ne s'affiche que pour une entreprise cliente ou sans rôle, plus pour un OPCO, un financeur ou un sous-traitant. Le rôle qui l'affiche se règle dans Administration › Référentiels (« Rôle client »).

## [0.15.8] - 2026-11-23

### Ajouté

- **Rattrapage de plusieurs demi-journées** (émargement) : un stagiaire, ses demi-journées passées encore à signer (jamais une absence notée ou excusée), un seul e-mail, un seul code, une seule signature pour toutes ; **Tout confirmer** par stagiaire.

## [0.15.7] - 2026-11-23

### Modifié

- **Questionnaire à chaud** : part tout seul **le dernier jour à 13 h 30** (avec les attestations, commande `tms:automation:last-day`) ; l'envoi du lendemain 7 h ne fait plus que rattraper.
- **Bloc Questionnaires de la session** : chaque questionnaire dit s'il est envoyé (date, réponses) ou quand il part tout seul (badge « Automatique ») ; « Lancer », « Inviter les nouveaux » et le QR code sont repliés sous « Envoyer à la main ».

## [0.15.6] - 2026-11-23

### Modifié

- **Attestations et certificats de réalisation automatiques** : envoyés **le dernier jour de formation à 13 h 30** aux stagiaires présents (l'après-midi comptée comme réalisée ; les absents du jour et les sortis exclus) ; la clôture du mois reste le rattrapage.

- **Documents des stagiaires** (session) : chaque type dit s'il part tout seul (badge « Automatique », date prévue ou « pensez à confirmer la session ») ou s'il est à faire à la main, avec le nombre émis et envoyés ; les boutons manuels sont repliés quand l'envoi est automatique, les cases vides disent « Partira seul ».
- **Inscrits** : « Inscrit » suffit (convocations, présences, attestations, factures) ; **« Confirmer » disparaît** (il ne déclenchait rien) et les inscriptions « Confirmé » redeviennent « Inscrit » (migration) ; « Promouvoir » devient **« Passer en inscrit »**.

## [0.15.5] - 2026-11-23

### Modifié

- **Générer les séances** : un créneau peut être limité à certains jours de la semaine (« un autre horaire le vendredi ») : ces jours-là, il remplace les créneaux « tous les jours » (plus de faux « Les créneaux ne doivent pas se chevaucher ») ; deux créneaux peuvent se chevaucher s'ils n'ont aucun jour en commun.
- **Suivi d'un module de session** : « Intervalle de dates » devient **« Période, heures saisies chaque mois »**, « Séances » devient **« Séances planifiées »**, chacun expliqué à l'écran ; « Passer en intervalle / en séances » devient **« Changer le suivi… »**, une fenêtre qui décrit le nouveau suivi (et pourquoi le bouton est grisé quand il y a déjà des séances ou des heures).

## [0.15.4] - 2026-11-23

### Modifié

- **Carte « Étapes » du dossier** : chaque étape dit si elle est faite (date), laquelle vient ensuite et comment elle avance seule (bloc Documents, signature en ligne, convocations automatiques, dates des sessions, factures) ; les corrections à la main (document envoyé hors du TMS, « Forcer En cours / Réalisé ») sont repliées sous « Corriger une étape à la main », avec un avertissement.

## [0.15.3] - 2026-11-23

### Ajouté

- **Acompte demandé à un financeur** : sur un financement accordé qui paie directement l'organisme, un pourcentage et une date ; dès cette date et jusqu'à la fin de la formation, le financeur reçoit une **facture d'acompte** ; la facture finale, au réalisé, la déduit (avoir si l'acompte dépassait le réalisé) et rappelle les acomptes déjà facturés.
- **« Facture d'acompte »** sur le PDF des échéances intermédiaires d'un échéancier et des acomptes de financeur.
- **Automatisme « Convention de formation »** (désactivé par défaut) : à la création d'un dossier depuis un devis signé, la convention de l'entreprise est émise et envoyée en signature en ligne au contact du dossier.
- **Financement sur la convention et le contrat** : financeurs (accord, montant, règlement direct) et reste à la charge du contractant (« sous réserve des accords en cours » tant qu'un financement n'est pas accordé).

## [0.15.2] - 2026-11-23

### Ajouté

- **Lieu « Dans nos locaux »** pour une session (fiche session et **Créer le dossier** depuis un devis) : on choisit la société ; son nom et son adresse s'impriment sur la convention, les convocations, le rappel de la veille et les calendriers.

### Modifié

- Page d'un dossier : le bloc **Documents** passe avant **Facturation** (la convention précède la facture) ; les blocs des autres modules ont une position (`DossierBlocks::register(..., $position)`).

## [0.15.1] - 2026-11-23

### Ajouté

- **OPCO officiel d'une convention collective** : table IDCC → OPCO tirée de la table SIRET-OPCO de France compétences (data.gouv.fr, mise à jour d'août 2026, 953 conventions, OPCO majoritaire de chaque IDCC). À l'ajout d'un financement, l'OPCO proposé suit cette table quand aucune fiche ne liste l'IDCC dans ses conventions couvertes (fiche retrouvée par son nom, sinon nom de l'OPCO indiqué). Sur la fiche d'un OPCO, **Reprendre les IDCC officiels de…** remplit les conventions couvertes.

## [0.15.0] - 2026-11-23

### Ajouté

- **Registres Qualiopi** (menu Qualité) : **Réclamations** (indicateur 31 : canal, réponse obligatoire pour clore) et
  **Amélioration continue** (indicateur 32 : origine, réclamation liée, responsable, échéance, résultat), avec leurs
  mesures automatiques sur 12 mois sur la fiche de l'indicateur.
- **Types d'organisation** (référentiel paramétrable) : Entreprise cliente, OPCO, Financeur public, Sous-traitant,
  Co-traitant, Prescripteur, plusieurs par fiche ; filtre de la liste, export, réunis à la fusion.
- **OPCO proposé depuis l'IDCC** : la fiche d'un OPCO liste les conventions collectives qu'il couvre ; à l'ajout d'un
  financement, l'OPCO de la convention du contractant est proposé d'office.
- **Convention de formation** : le SIRET et l'IDCC de l'entreprise contractante s'impriment sous son nom.
- **Coordonnées bancaires** de la société (Administration › Sociétés) : banque, IBAN (clé vérifiée) et BIC, imprimés
  sur chaque facture (« Règlement par virement »), jamais sur un avoir.

### Modifié

- **Financement par le contractant** : cocher « Paie directement l'organisme » n'est plus refusé ; la case est sans
  effet pour lui (il reçoit déjà la facture du reste à charge).

## [0.14.5] - 2026-11-22

### Modifié

- **Créer le dossier de formation** : le contractant se choisit (une entreprise ou le candidat comme particulier, au
  prix correspondant) ; pour une session intra visée, son entreprise est proposée d'office.
- **Créer le dossier de formation** (candidature admise) : un refus de l'inscription (session intra d'une autre
  entreprise, session complète…) s'affiche sous « Session » ; toute autre erreur s'affiche dans la fenêtre, plus de clic
  sans effet.
- **Fiche candidat** : le DAS vient avant la formation visée, qui ne propose que les formations de ce DAS ; la session
  visée doit appartenir au DAS de la candidature.

## [0.14.4] - 2026-11-22

### Ajouté

- **Justificatifs d'absence** : dans la saisie des présences, sous un stagiaire absent, partiel ou excusé, dépôt d'un PDF
  ou d'une image (10 Mo au plus) ; réservés aux gestionnaires (jamais au formateur), supprimés à l'anonymisation.
- Type de champ personnalisé **N° de sécurité sociale** (fiche candidat) : 15 caractères et clé vérifiés (Corse comprise),
  affichage par groupes, réservé aux personnes ayant le droit de voir les données sensibles.
- **IDCC** (convention collective) sur la fiche entreprise : saisie à 4 chiffres, affichage, export et import ;
  **Compléter depuis le SIRET** le remplit depuis l'annuaire officiel (Recherche d'entreprises).

### Modifié

- Un long numéro tapé dans un champ **Nombre** explique qu'il faut le type Texte (ou N° de sécurité sociale).
- **Test de positionnement** : la page utilise la largeur de l'écran au lieu d'une colonne étroite au centre.

## [0.14.3] - 2026-11-22

### Ajouté

- Commande `tms:platform-admin <e-mail>` : désigne l'opérateur de la plateforme, qui ouvre **Horizon** (suivi des
  tâches en échec), depuis le serveur seulement.
- La vérification d'intégrité des factures (`tms:finance:verify`) tourne chaque nuit à 4 h 10 ; une rupture alerte
  l'administrateur de l'instance.
- **Attestations et certificats de réalisation automatiques** (Administration › Automatismes, désactivé par
  défaut) : émis et envoyés le matin qui suit la clôture du mois du dernier jour de la session ; un document annulé
  n'est jamais refait.

### Modifié

- **Signature en ligne** : on demande d'abord son code, puis on dessine ; sous « Signer », ce qu'il reste à faire.
- **Clôture du mois** des présences : confirmation avant de clôturer.
- Bloc **Documents** : une ligne explique « Émettre » (numéro définitif) et « Envoyer » (e-mail).
- **Page du dossier** : les étapes indiquent qu'elles se mettent à jour seules depuis le bloc Documents ; les boutons de
  date à la main deviennent discrets (« Indiquer à la main… »).

## [0.14.2] - 2026-11-22

La 0.14.1 n'a pas été publiée (formatage refusé par la CI) : son contenu est livré ici.

### Ajouté

- **Planning des séances aux couleurs des sessions** : chaque session reçoit une couleur à son enregistrement (12
  couleurs à tour de rôle, les sessions existantes colorées par ordre de création) ; ses séances la portent dans le
  planning et « Mon planning », et la session l'affiche aussi (pastille dans la liste, bord de sa carte dans les
  colonnes, en tête de sa page).
- **Devis en un seul e-mail** : à l'envoi, la case **Joindre un lien de signature en ligne** (cochée par défaut) met le
  lien « bon pour accord » dans l'e-mail du devis, au lieu d'un second e-mail.
- **Bandeau des messages** : le résultat d'une action (enregistré, envoyé, refusé) s'affiche en bas de l'écran, là où
  l'on regarde ; un refus métier (document annulé, moteur PDF indisponible…) devient un message, plus une page
  d'erreur.
- **Confirmation des envois groupés** : « Envoyer à tous » et « Lancer le questionnaire » indiquent le nombre de
  stagiaires avant d'envoyer.
- Bloc **Documents** d'une session : sous « Convocation », la date de l'envoi automatique (ou « trop tard », ou « une
  fois la session confirmée ») ; **Émettre pour tous** les convocations propose alors de **laisser l'envoi automatique**
  ou d'**émettre et envoyer maintenant**, pour que les deux ne se croisent jamais.

### Modifié

- Logo neutre (plus de logo du kit de départ) sur la connexion et les pages publiques.
- Menu : « À faire » devient **Mes actions commerciales**, « Planning » (commercial) **Agenda commercial**,
  « Questionnaires » **Résultats des questionnaires** (Qualité) et **Modèles de questionnaires** (Administration).

## [0.14.0] - 2026-11-22

### Ajouté

- **Automatismes** (Administration › Automatismes) : chaque matin, **convocations** des sessions confirmées à J-7
  (ou le lendemain d'une confirmation tardive), **rappel la veille** aux stagiaires et aux formateurs, **relance des
  signatures en ligne** en attente (nouveau lien, 2 fois au plus) ; délais réglables, bouton **Exécuter maintenant**.
  Trois nouveaux modèles d'e-mails. Rien ne part deux fois.
- **À traiter** (menu, avec compteur) : ce qui attend chacun selon ses droits — sessions à préparer, rattrapages à
  confirmer, demi-journées non validées, devis sans réponse, actions à faire, candidatures, montants à facturer,
  factures à relancer.

## [0.13.0] - 2026-11-22

### Ajouté

- **Rattrapage d'émargement** : un stagiaire présent qui n'a pas signé rattrape sa signature après coup, **sur place**
  (lien de rattrapage d'une demi-journée passée, jusqu'à minuit) ou **à distance** (lien personnel par e-mail de 7 jours
  et code à 6 chiffres par e-mail). Chaque rattrapage est **confirmé ou refusé** par un gestionnaire des présences
  (onglet Présences) avant de compter ; la feuille signée l'indique. Deux nouveaux modèles d'e-mails.
- Émargement papier replié sous « Émargement papier (secours) » dans la feuille de présences d'une séance.
- Émargement en ligne : **Faire entrer ce jour-là** avance la date d'entrée d'un inscrit qui entre en formation plus
  tard, pour qu'il signe la demi-journée (nouveau droit « Faire entrer un stagiaire en formation » : administrateur,
  direction et Responsable de DAS par défaut ; mois clôturé refusé ; noté dans l'historique du dossier).
- Feuille d'émargement signée : bouton **Aperçu** (ouvre le PDF archivé dans un nouvel onglet) à côté de
  **Télécharger**.
- Rattrapage d'un jour passé **jamais ouvert** : bouton **Faire un rattrapage** dans les présences de la séance
  (gestionnaires seulement). L'ouverture d'un rattrapage est réservée aux gestionnaires des présences (jamais « Ses
  séances »).
- Onglet Suivi, **Séances du mois** : tableau qui défile, ouvert sur la semaine en cours (surlignée) ; voyant sur le
  bouton **Présences** : **vert** quand toutes les demi-journées du jour sont validées, **orange** quand une seule
  l'est.

### Corrigé

- Émargement : le badge d'un rattrapage suit sa confirmation (« à confirmer », « confirmé » avec voyant vert,
  « refusé ») au lieu de rester « à confirmer ».

## [0.12.0] - 2026-11-21

### Ajouté

- **Émargement en ligne** : par demi-journée (matin / après-midi, coupure à 13 h), le formateur affiche un **QR code** ;
  les stagiaires signent au doigt sur leur téléphone, sans compte (prénom et initiale seulement sur la page publique) ;
  le formateur (ou un gestionnaire des présences) signe et **valide** : les présences se remplissent (présent, partiel au
  prorata, absent), sans jamais écraser une saisie manuelle. **Feuille d'émargement signée** par session et par mois :
  PDF archivé avec les dessins, le valideur et les empreintes SHA-256. Service public prêt pour un futur connecteur
  (SoWeSign). Dessins effacés par l'anonymisation.
- **Questionnaires de satisfaction** (Qualiopi, indicateurs 30 et 2) : questionnaire **à chaud** en fin de session
  (e-mail avec lien personnel et **QR code** à afficher en salle, où le stagiaire choisit son nom) et **à froid** envoyé
  automatiquement N mois après (3 par défaut), questionnaire entreprise facultatif ; réponses sans compte, une seule par
  personne. Modèles paramétrables (notes de 1 à 5, choix, texte libre). Résultats sur la session, carte
  « Satisfaction », satisfaction sur la fiche de l'apprenant, page Qualité › Questionnaires avec export CSV, mesures
  Qualiopi automatiques. Droits « Consulter les résultats des questionnaires » et « Paramétrer les questionnaires ».
- Facture libre (équivalent des « factures annexes » de Formevol) : facture sans dossier pour un client, N° d'engagement et
  code service (Chorus Pro), lignes désignant un co-traitant du groupement (mention « mandataire du groupement » sur le
  PDF) ; écran Finance › À reverser pour suivre les reversements aux co-traitants (dû, reversé, reste, export CSV).

### Corrigé

- Émargement en ligne : une séance qui n'a pas lieu aujourd'hui indique pourquoi le bouton « Ouvrir l'émargement »
  manque (« S'ouvre le jour de la séance » ou « Pas d'émargement en ligne ce jour-là »).
- Émargement en ligne : quand aucun stagiaire n'est attendu à la demi-journée (date d'entrée en formation plus tardive,
  pas d'inscription), la page du stagiaire ne dit plus « Tout le monde a signé » et l'écran du formateur le signale.
- Sessions : une session passe « Terminée » dès son dernier jour quand son dernier mois est clôturé (Suivi › Clôture
  des mois), au lieu d'attendre le lendemain.
- Sessions : la carte « Clôture des mois » de la synthèse compte aussi le dernier mois d'une session à partir de son
  dernier jour, et affiche « Tous les mois sont clôturés » quand un mois a été clôturé avant la fin du mois.
- Local : page blanche sur les autres postes du réseau (lien de test de positionnement…). Le serveur Vite de
  développement ne démarre plus avec `docker compose up -d` (profil `dev`) ; les pages utilisent les fichiers construits.

## [0.11.0] - 2026-11-20

### Ajouté

- Bac à sable local (démonstration) : Administration › Bac à sable pour effacer les données de travail ou repartir de
  zéro, organisme et société fictifs ; uniquement avec `TMS_SANDBOX=true` et `APP_ENV=local`, jamais sur un serveur.
- Signature en ligne : le signataire **dessine sa signature** (obligatoire) ; elle apparaît dans l'encadré signature du
  document (convention, contrat, « Bon pour accord » du devis) et sur la page de preuve, avec son empreinte. Le document
  signé reprend exactement la page du document envoyé (gardée à l'émission) ; un document émis avant cette version porte
  le dessin sur la page de preuve seulement.
- Factures : un brouillon de facture sans ligne calculée explique pourquoi rien n'est encore dû (« Formation non
  terminée »…), ou invite à cliquer « Recalculer » quand un montant est devenu dû.
- CRM (lot 1e) : **tableaux de bord** (vues Semaine et Mois / direction, 12 indicateurs du cahier des charges avec leur
  formule, période précédente, filtres DAS / cible / commercial, exports CSV et PDF) ; **rappels de réactivation**
  automatiques 6 et 12 mois après un dossier, pour le responsable de la fiche ; **suivi formateur** 3 mois après la
  formation de chaque apprenant ; **besoins futurs** sur les fiches ; réglages du CRM (délais, étape des leads, fenêtre
  de rétention) ; résultats d'action « joint ». Le rôle Formateur voit et complète ses propres actions. Droit « Consulter les tableaux de bord », réglable par rôle.
- Admissions : compteur rouge sur « Candidats » pour le responsable de DAS, nombre de candidatures encore « Identifié »
  dans ses DAS.

### Modifié

- Sessions : un formateur peut saisir les présences de ses propres séances depuis le « Suivi » de la session.

- Documents : l'attestation de fin de formation et le certificat de réalisation s'émettent dès le dernier jour de la
  formation (et non plus le lendemain).

### Corrigé

- Dossiers : « Mettre à la corbeille » n'est plus proposé sur un dossier facturé (l'action était déjà refusée).

## [0.10.0] - 2026-11-20

### Ajouté

- Documents (0.10, livraison 1) : **convention de formation** (contractant entreprise) et **contrat de formation**
  (particulier) émis depuis le dossier avec ses données, numérotés `DOC-<année>-<NNNNN>`, archivés et figés, envoyés par
  e-mail, annulables avec motif ; textes modifiables par organisme ou par société (Administration › Modèles de
  documents) ; signature et cachet de la société ; droits `documents.view` et `documents.manage` ; le dossier passe à
  « Convention envoyée » à l'envoi.
- Documents (0.10, livraison 2) : **convocation**, **attestation de fin de formation** et **certificat de réalisation**
  (modèle du ministère) par stagiaire, émis et envoyés un par un ou pour toute la session (page de la session), cartes
  « Convocations » et « Attestations » de la synthèse ; le dossier passe à « Convocations envoyées ».
- Signature en ligne (0.10, livraison 3) : convention, contrat et devis (« Bon pour accord ») signés par un lien
  personnel et un code reçu par e-mail, PDF signé avec page de preuve (signature électronique simple) ; le dossier passe
  à « Convention signée », le devis à « Accepté » avec son exemplaire signé.
- Qualité : module **Qualiopi** (menu Qualité › Qualiopi, droits `qualite.view` et `qualite.manage`) sur le référentiel
  2026 (décret n° 2026-728, 33 indicateurs) : suivi par indicateur (applicabilité justifiée, statut, responsable, date
  de revue, notes), preuves (fichier, lien, note), mesures automatiques (indicateurs 1, 5, 8, 12 et 13) et export du
  dossier d'audit (ZIP : sommaire PDF et fichiers par indicateur, journalisé).

### Corrigé

- Admissions : le bloc « Informations complémentaires » (champs personnalisés) s'affiche dès l'ouverture de la
  candidature, et non plus seulement à partir de l'entretien.

## [0.9.2] - 2026-11-19

Admissions : parcours Alternance / Stagiaire, tests de positionnement (PDF par DAS ou formation, lien personnel, test en
ligne corrigé dans le TMS, test obligatoire avant l'entretien) ; CRM : cartes Actions et Rendez-vous de l'accueil,
« Attribuer à » plusieurs personnes ; mention d'exonération de TVA datée. L'export comptable et la facture électronique
passent à une version suivante.

### Ajouté

- **Tests de positionnement en ligne** (Formation › Tests en ligne) : éditeur de sections et de questions (réponse courte
  ou rédigée, choix unique ou multiple, vrai / faux, nombre à compléter, oui / non) avec bonnes réponses, points et durée
  limite ; **Créer depuis un PDF** par l'intelligence artificielle (brouillon à relire) ; versions figées à la
  publication. Le candidat passe le test **sur ordinateur** par son lien (réponses enregistrées au fil de l'eau,
  chronomètre, copier-coller bloqué, sorties de la page relevées) ; correction automatique des questions fermées, notation
  à la main des réponses rédigées sur la candidature, note dans « Score ou niveau » ; **copie du candidat en PDF**.
- **Test de positionnement — Générer le lien** : le lien personnel du candidat s'affiche sur la candidature avec
  **Copier**, sans e-mail obligatoire ; « Envoyer par e-mail » reste possible.
- **Accueil (CRM)** : cartes **Actions** (créées et réalisées dans le mois) et **Rendez-vous pris** (fixés dans le mois),
  comparées au mois précédent, avec les 12 derniers mois, dans le périmètre de chacun.
- **Attribuer à plusieurs personnes** une action ou un rendez-vous (champ « Attribuer à », le premier choisi est le
  responsable) : chacune la voit dans son « À faire » et son planning et peut la modifier et la terminer.
- **Tests de positionnement** : la bibliothèque (Catalogue › Plaquettes et tests) accueille les tests PDF, rattachés aux
  DAS et/ou aux formations ; la carte « Test de positionnement » d'une candidature propose ceux de sa formation ou de son
  DAS, **Envoyer le test** les envoie par e-mail avec un **lien personnel** (14 jours, sans compte) où le candidat dépose
  sa copie (PDF ou photos) : elle arrive sur la candidature, « Reçu le » et l'étape suivent.
- **Admissions — parcours du candidat** (carte Orientation) : *À définir*, *Alternance* ou *Stagiaire* (formation
  continue sans contrat). La carte Alternance n'apparaît que pour le parcours Alternance ; un contrat met le parcours à
  Alternance et le garde ; filtre **Parcours** et colonne de l'export.

### Modifié

- Admissions : le **test de positionnement est obligatoire** — Entretien et Admis exigent sa date de réception ; le
  bouton « Passer le test » est retiré, comme « Joindre le test » (les copies déposées par le candidat restent affichées).
- Menu : **Candidats** passe dans **Formation**, au-dessus de Dossiers.
- Admissions : l'onglet **Apprenants** devient **Stagiaires**, le badge « Apprenant » devient « Stagiaire ».
- **Mention d'exonération de TVA** des devis et factures, alignée sur l'expert-comptable : « Exonération de TVA, art. 261,
  4-4° a du CGI » jusqu'au 31 décembre 2026, « Exonération de TVA, art. L. 213-93 du CIBS » à partir du 1er janvier 2027
  (selon la date du devis ou de validation de la facture ; un avoir garde l'exonération de la facture qu'il corrige).

## [0.9.1] - 2026-11-19

Admissions (candidats, alternance) et suivi des paiements : règlements, factures soldées, relances, protection du
particulier. Export comptable et facture électronique en 0.9.2.

### Ajouté

- **Module Admissions** (menu **Admissions**, guide `docs/admissions.md`) : candidatures (étapes, motif de non-retenue,
  formation, code RNCP repris de la formation), pièces jointes, **contrat d'alternance** (employeur, tuteur, dates),
  **champs personnalisés** paramétrables par l'organisme ; données sensibles (RQTH, besoins d'adaptation, compte rendu
  d'entretien) réservées au droit `admissions.sensitive.view` ; liste filtrée et export CSV ; bloc Candidatures sur la
  fiche personne.
- **Création du dossier** depuis une candidature admise (inscription à la session, financement, contractant par défaut =
  employeur du contrat) ; « Apprenant » / « Alternant » se lisent du dossier et du contrat.
- **Règlements** (menu Finance › **Règlements**, droit `finance.payments.manage`) : date, montant, mode (nouveau
  référentiel « Modes de règlement »), référence ; un règlement couvre plusieurs factures du même payeur et de la même
  société (**Répartir** : plus anciennes d'abord), reste non affecté réaffectable ; **Enregistrer un règlement** depuis
  une facture.
- **État de paiement** des factures : non réglée, partiellement réglée, **soldée**, en retard, trop-perçu ; dans la liste
  (colonne **Reste dû**, filtre Paiement), sur la facture, dans le bloc Facturation du dossier et l'export CSV.
- **Relances** (menu Finance › **À relancer**) : 1 à 3 niveaux (par défaut 7, 30 et 60 jours après l'échéance), modèles
  d'e-mail `invoice_reminder_1` à `3`, PDF archivé joint, envoi groupé ou facture par facture, **Suspendre les
  relances** ; relance automatique facultative chaque matin à 8 h 30 (`tms:finance:remind`, désactivée par défaut).
- **Particulier** : aucune facture au contractant particulier pendant le délai de rétractation de 14 jours suivant la
  signature du contrat ; première échéance d'un échéancier plafonnée à 30 %.

### Modifié

- Menu CRM : **Personnes** devient **Interlocuteurs**.

### Corrigé

- Le message de confirmation (« Facture validée »…) s'affiche à nouveau sur les pages Factures, À facturer et sur la
  page d'une facture.

## [0.9.0] - 2026-11-18

Factures : le TMS émet les factures et avoirs officiels depuis le dossier, au réel des heures faites, partagés entre le
contractant et les financeurs qui paient directement l'organisme. Règlements et relances viendront en 0.9.1, export
comptable et facture électronique en 0.9.2.

### Ajouté

- **Module Finance** : menu **Finance** (À facturer, Factures, Réglages de facturation) ; guide `docs/finance.md`.
- **Rythme de facturation par dossier** (bloc Facturation) : fin de formation, mensuel au réalisé (mois clôturés
  seulement) ou échéancier (financeurs facturés à la dernière échéance seulement) ; base au réalisé ou au prévu ;
  absences non justifiées non facturées ou facturées au contractant (réglage de l'organisme, modifiable par dossier).
- **Financement « Paie directement l'organisme »** (`fundings.pays_directly`) : le financeur accordé reçoit sa facture
  pour sa part, avec son numéro d'accord ; type de financeur **« Facturé hors du TMS »** (CPF…) : part retirée de la
  facture du contractant, mention « Part réglée par … », aucune facture TMS.
- **Calcul du dû en centimes entiers** (`BillingCalculator`, service pur) : prorata des heures réalisées, abandon et
  dédit (au seul contractant, ligne distincte hors champ de la TVA, jamais en force majeure), parts des financeurs
  plafonnées, déjà facturé par destinataire.
- **Page « À facturer »** : dû par dossier et par destinataire avec détail du calcul, « Bientôt à facturer »,
  « Brouillon en cours », « À régulariser » (avoir proposé, reste à régulariser) ; filtres société, DAS, responsable,
  mois ; **Préparer les brouillons**.
- **Brouillons** de factures et d'avoirs : lignes calculées et lignes libres, **Recalculer**, un seul brouillon ouvert
  par destinataire, dossier et type.
- **Validation** (droit `finance.invoices.validate`) : numéro `<préfixe>-<année>-<NNNNN>` continu par société, type et
  année de Paris, échéance (délai de 0 à 60 jours), destinataire figé, PDF archivé, nouveau contrôle du dû ; dossier
  « Facturé » quand tout est facturé.
- **Avoirs** : corrigent toujours une facture, plafonnés à ce qui en reste, au taux de TVA de la facture corrigée,
  propositions itératives.
- **PDF** des factures et avoirs (Gotenberg) avec les mentions obligatoires : exonération de TVA, NDA, pénalités de
  retard et indemnité de 40 € pour un destinataire professionnel seulement.
- **Envoi par e-mail** (modèle « Facture », PDF archivé en pièce jointe, historique, **Renvoyer**), **export CSV** de la
  liste, bouton **Vérifier l'intégrité** et commande `tms:finance:verify`.
- **Sociétés** : préfixes de factures (`F`) et d'avoirs (`AV`), délai de paiement, pied de facture.
- **Droits** `finance.invoices.view`, `.manage`, `.validate`, à périmètre ; défauts accordés par `tms:permissions:sync`.
- **Tables** `billing_plans`, `finance_settings`, `invoices`, `invoice_lines`, `invoice_sequences`, `invoice_journal`,
  `invoice_sendings` ; colonnes `fundings.pays_directly` et `companies.invoice_prefix` / `credit_note_prefix` /
  `payment_terms_days` / `invoice_footer`.
- **Surface publique de Sessions** pour Finance : `Billing`, `DossierBlocks`, `BillingGuards`, `DossierStates`.

### Modifié

- Rythme, base, échéancier et « Paie directement » se verrouillent à la première facture validée du dossier ; un
  dossier qui porte des factures validées ne va plus à la corbeille.
- L'anonymisation d'une personne supprime ses brouillons de factures et conserve les pièces validées (10 ans).
- La page du dossier affiche des blocs déclarés par d'autres modules (`DossierBlocks`).

### Sécurité

- **Factures inaltérables** : déclencheurs PostgreSQL refusant toute modification d'une facture validée (hors passage à
  « envoyée » et suivi de l'envoi), de ses lignes et toute suppression ; journal des pièces en ajout seul.
- **Journal chaîné** : chaque pièce porte l'empreinte SHA-256 de son contenu et celle de la précédente, avec l'empreinte
  de son PDF archivé ; contrôle par `tms:finance:verify` (code de sortie 1 en cas de rupture).
- Numérotation sous verrou, sans trou ni doublon ; PDF archivé servi tel quel, jamais re-généré ; envoi refusé si
  l'archive a été altérée.
- Aucun texte du serveur de messagerie dans les erreurs d'envoi ; adresses absentes du journal d'audit.

## [0.8.2] - 2026-11-17

Sauvegardes : suivi dans l'application, sauvegarde à la demande, et export / import en un fichier chiffré pour
déménager vers un autre serveur sans perte.

### Ajouté

- **Page Administration › Sauvegardes** (droit `settings.manage`, absente en mode multi-organismes) : dernière
  sauvegarde, copie hors site, sauvegardes disponibles ; **pastille rouge** sur « Sauvegardes » dans le menu de
  l'administrateur si la dernière sauvegarde a échoué, date de plus de 36 heures ou est inconnue.
- **Sauvegarder maintenant** : demande déposée par l'application, exécutée aussitôt sur le serveur par
  `tms-backup-request.path` (`ops/backup.sh --tag manuel`) ; demande inscrite au journal d'audit, remise en attente si
  une autre opération de maintenance empêche la sauvegarde de démarrer, signalée sur la page au bout de 10 minutes sans
  prise en charge ; la page se met à jour d'elle-même tant qu'une sauvegarde est demandée ou en cours.
- **Fichier d'état** `ops/backup-status.json` (et `backup-running.json`, `backup-request.json`) dans le volume
  `storage`, hors de `storage/app` : écrit par `ops/backup.sh` à chaque fin, réussie ou non.
- **Export / import pour déménager** : `ops/export.sh` (un seul fichier chiffré par mot de passe d'export, empreinte
  SHA-256), `ops/import.sh <fichier>` et `install.sh --import <fichier>` (base, fichiers, `APP_KEY`, connecteurs et 2FA
  repris ; sauvegarde de sécurité `pre-import` ; récapitulatif de contrôle ; refus d'un export plus récent que la
  version installée).
- **Test de déménagement** (bats, piles jetables) : mêmes nombres de lignes par table, mêmes fichiers, secret de
  connecteur et 2FA valides, refus sans modification (mauvais mot de passe, fichier tronqué, version trop récente).
- **Copie hors site en SFTP** : `RESTIC_REMOTE_SSH_KEY` (clé SSH dédiée) et `RESTIC_REMOTE_SSH_KNOWN_HOSTS`
  (empreintes du NAS), obligatoires pour une adresse `sftp:` ; montés en lecture seule pour restic, empreinte vérifiée
  strictement (`StrictHostKeyChecking=yes`), clé jamais affichée.
- **`ops/export.sh --stop`** : arrête l'application de l'ancien serveur avant la sauvegarde de l'export (rien de saisi
  après l'export ne peut manquer, aucune tâche ne tourne en double), désactive ses sauvegardes automatiques après un
  export réussi et affiche la commande pour le remettre en service ; redémarre l'application si l'export échoue.
- **Guide** : copie hors site (NAS en SFTP, stockage compatible S3), vérification sur la page et procédure de
  déménagement (`docs/install.md`, `docs/admin.md` § 17).

### Modifié

- `ops/restore.sh` accepte un dépôt autre que le dépôt local (`TMS_RESTORE_REPOSITORY`, `TMS_RESTORE_PASSWORD`, par
  l'environnement uniquement) ; `ops/update.sh` et l'installateur créent `storage/ops` et activent la sauvegarde à la
  demande.

### Sécurité

- Aucune donnée téléchargeable depuis le navigateur : un compte administrateur volé ne peut pas emporter la base.
- Le conteneur `app` n'obtient ni accès Docker ni secret restic ; mots de passe d'export et d'import transmis par
  l'environnement ou saisis, jamais en argument de commande ni dans un journal.

## [0.8.1] - 2026-11-17

Écrans de la formation : la page d'une session se lit en étapes avec une Synthèse d'avancement, les sessions se suivent
en colonnes par état, la fiche d'une formation montre ses sessions, et les séances partent dans l'agenda Outlook du
formateur.

### Ajouté

- **Page de la session en étapes** (Synthèse, Configuration, Planning, Stagiaires, Suivi), l'étape figurant dans
  l'adresse (`?etape=`) ; les anciennes adresses `?tab=` sont comprises.
- **Synthèse** : cartes d'avancement (heures planifiées, formateurs, salles, conflits, inscriptions, financements,
  stages, présences, clôture des mois), chacune avec un état en texte et en icône et un lien vers la section à traiter,
  selon le périmètre de l'utilisateur (`Summary\SessionChecklist`).
- **Tâches et notes d'une session** (tables `session_tasks` et `session_notes`) : tâches avec échéance et responsable,
  notes de 5 000 caractères, journalisées sans leur texte.
- **Sessions en colonnes** : vue kanban par état (planifiée, confirmée, en cours, terminée, annulée à la demande),
  bascule Liste / Colonnes mémorisée, glisser-déposer ou menu au clavier entre planifiée et confirmée
  (`POST sessions/{session}/status`), heures et points à régler sur chaque carte.
- **Séances dans Outlook** : les séances planifiées sont créées, déplacées et retirées dans l'agenda Outlook du
  formateur connecté, sans stagiaire, note ni prix (`Connectors\Calendar\OutlookSources`, `PushSourceToOutlook`).
- **Fiche formation** (`catalogue/{formation}`) : informations, plaquettes et bloc Sessions (indicateurs, liste,
  **Créer une session** avec la formation préremplie), par le registre `Catalogue\TrainingBlocks`.

### Modifié

- `calendar_link_events` : `activity_id` devient `source_id` et reçoit une colonne `source` (`crm.activity` pour les
  actions du CRM, `sessions.seance` pour les séances) ; migration réversible.
- Dans la liste du catalogue, l'intitulé d'une formation mène à sa page ; **Modifier** reste une action.

## [0.8.0] - 2026-11-17

Planning des séances, formateurs, présences et heures réalisées : une session se découpe en modules, se planifie en
séances (assistant, stages, jours fériés, conflits), se suit par les présences et les heures du mois, et se signe sur
une feuille d'émargement imprimable.

### Ajouté

- **Modules de session** : une session est la somme de ses modules (intitulé, heures prévues en minutes entières, mode
  de planification en **séances** ou en **intervalle** de dates) ; **Reprendre les modules de la formation** copie ceux
  du catalogue (aperçu, puis remplacement refusé dès qu'un module a une séance, une présence ou des heures) ; les
  heures prévues de la session deviennent la somme des modules ; les sessions existantes reçoivent un module
  « intervalle » unique (migration).
- **Salles et fermetures** (Administration › Salles et fermetures, droit `sessions.rooms.manage`) : salles (capacité,
  adresse, actif) et périodes de fermeture exclues de la planification.
- **Formateurs** (menu Formation › Formateurs, droit `sessions.trainers.manage`) : issus des **utilisateurs du TMS** ou
  sans compte, statut, indisponibilités, compte lié (lien automatique à l'invitation si une seule fiche a l'e-mail),
  bloc « Formateur » sur la fiche Personne ; **liés à des DAS** (les formateurs du DAS de la session sont proposés en
  premier).
- **Rôle Formateur** (septième rôle système) et périmètre **« Ses séances »** (`AccessScope::Trainer`, proposé
  seulement pour « Consulter les séances » et « Saisir les présences ») : le formateur ne voit que ses séances, par
  **Mon planning** ; jamais besoins d'adaptation, notes, financements ni prix ; aucun droit CRM, devis, catalogue ni
  export. Nouveaux droits : `sessions.seances.view|manage`, `sessions.attendance.manage|close`,
  `sessions.trainers.manage`, `sessions.rooms.manage` (accordés une fois par `tms:permissions:sync`).
- **Séances** (table `seances`, heures de Paris, durée = fin − début − pause) avec salle, formateur, lieu libre, note ;
  **assistant « Générer les séances »** (aperçu, sans doublon ni modification des séances existantes, jours ouvrés,
  fermetures) ; **périodes de stage** par session avec dates et entreprise d'accueil propres à chaque stagiaire ;
  **jours fériés nationaux** (les 11, lundi de Pentecôte compris, calcul local) ; **alertes de conflits** (formateur,
  salle, stagiaire, indisponibilité, stage, fermeture) qui avertissent sans jamais bloquer ; calendrier de la session
  (mois et semaine).
- **Modification en masse des séances** : cases à cocher dans la liste ; **Modifier la sélection** (formateur, salle
  ou lieu, horaires), **Annuler la sélection** (motif commun) et **Supprimer la sélection** (séances planifiées par
  erreur, sans présences), tout ou rien.
- **Fin estimée dans l'assistant** : on choisit la date de début, et la fin se calcule d'après les heures prévues du
  module (heures déjà planifiées déduites, fériés, fermetures et stages sautés) ; ou on garde une date de fin choisie.
- **Présences** par séance (présent, partiel, absent, absence justifiée, « Tous présents »), **heures du mois** des
  modules en intervalle, **clôture mensuelle** d'une session (réouverture avec motif obligatoire, journalisée) et
  **heures réalisées** en minutes entières, base de la facturation au réalisé ; séance modifiée ou annulée après
  présences : « à revoir », recalcul atomique ; une séance avec présences ne se supprime pas.
- **Plannings** : **Planning des séances** (général, mois et semaine, filtres formateur / salle / session / DAS),
  **Mon planning** du formateur (saisie directe des présences), planning du stagiaire sur sa fiche (« Formations
  suivies »), et séances publiées dans le **flux iCal** du formateur et du responsable de session (fuseau
  `Europe/Paris` ; sans le CRM, « Mon agenda » reste ouvert au formateur). Outlook : en 0.8.1.
- **Feuille d'émargement** : PDF par séance ou par journée (organisme, session, module, date, créneaux, formateur, une
  ligne par stagiaire attendu avec case matin / après-midi, case formateur) et **dépôt du scan signé** (PDF, PNG ou
  JPEG, 10 Mo, type vérifié sur le contenu) rattaché à la séance ou à la journée, téléchargeable selon le périmètre de
  la séance, supprimable par les gestionnaires. Nouvelle zone de stockage privée `attendance_sheets`.
- **RGPD** : l'anonymisation d'un stagiaire garde ses présences et ses heures (preuve, facturation) et efface leurs
  motifs ; les scans sont gardés, sauf sur une session individuelle ; l'export RGPD de la personne contient ses
  présences et ses heures du mois.

### Mise à jour

- Un organisme qui a déjà un rôle **personnalisé** nommé « Formateur » verra deux rôles de ce libellé : renommez le rôle personnalisé.

### Modifié

- **Mois clôturé** : changer la date d'entrée d'un stagiaire, ou enregistrer son abandon, est refusé (409) quand cela
  touche un mois clôturé de la session ; les heures réalisées exposent aussi `enrolment_planned_minutes`. Supprimer un
  scan d'un mois clôturé, ou en déposer un sur une séance annulée, est refusé. Les scans portent un nom neutre
  (`emargement-<date>.<ext>`), jamais le nom envoyé.
- Les heures prévues d'un stagiaire restent plafonnées par celles de la session (somme des modules) ; un module qui
  ferait passer la session sous une inscription existante avertit sans rien modifier.
- Les alertes et le registre de calendrier du module Connecteurs acceptent désormais plusieurs sources (le CRM et les
  séances) ; le flux iCal est ouvert à tout utilisateur dont une source publie des évènements.

## [0.7.1] - 2026-11-16

L'import devine mieux les colonnes, se prépare avec un modèle Excel et s'abandonne avant exécution ; une action se
confie à un collègue, prévenu par e-mail.

### Ajouté

- **Correspondance des colonnes plus intelligente** (import, écran « Associer les colonnes ») : par ordre de priorité,
  le **choix précédent** de l'organisme pour le même en-tête (table `import_header_choices`, en-têtes seulement,
  jamais de valeur de cellule), l'**en-tête exact**, un **en-tête approchant** (abréviations « CP », « Tél », « RS »…,
  mots vides ignorés) puis le **contenu** (50 premières cellules remplies, au moins 80 % reconnues : e-mail, mobile,
  téléphone, code postal, SIRET / SIREN avec clé de Luhn, date, site web, civilité). Chaque colonne proposée affiche
  sa raison (« choix précédent », « d'après l'en-tête », « d'après le contenu ») ; une colonne ambiguë reste sur
  « Ignorer ».
- **Modèles Excel d'import** : lien « Télécharger le modèle Excel » pour le type choisi ; feuille « Données » aux
  en-têtes exacts des champs, avec une ligne d'exemple fictive (ignorée si elle est réimportée telle quelle), feuille
  « Listes » avec les valeurs actives des référentiels de l'organisme.
- **Abandonner un import** déposé, en simulation ou simulé (avec confirmation) : le lot, ses lignes et son fichier
  sont supprimés définitivement, l'import disparaît de la liste ; refusé pendant et après l'exécution (409) ; réservé
  à l'auteur ou à qui voit tous les imports ; journalisé sans contenu.
- **Confier une action** : champ « Attribuer à » dès la création d'une action, bouton « Confier à… » (À faire,
  historique des fiches, actions d'une opportunité) avec un message facultatif (500 caractères). Droit : celui de
  modifier l'action (`crm.activities.update` et son périmètre). Le nouveau responsable reçoit un e-mail (nouveau
  modèle paramétrable « Action confiée », `activity_assigned` : type, objet, échéance, motif, message, lien ; jamais le
  compte rendu ni la suite à donner), sauf s'il confie l'action à lui-même ; il voit toujours l'action (À faire,
  compteur, planning) et peut la réaliser, même sur une fiche hors de son périmètre (dont le nom s'affiche alors sans
  lien). L'historique de la fiche indique « Action confiée à X par Y » ; Outlook suit le changement de responsable.

### Modifié

- **Périmètre « Ses DAS »** des actions : inclut désormais les actions dont l'utilisateur est responsable.
- **Système source** d'un import : exigé seulement quand une colonne « Identifiant source » associée contient une
  valeur.
- Le lien de l'e-mail « Action confiée » (`/crm/todo?action=…`) ouvre directement l'action dans « À faire ».

## [0.7.0] - 2026-11-16

La fiche formation devient la **formation type** (complète au sens de Qualiopi), se duplique, et se remplit avec une
intelligence artificielle qui propose sans jamais enregistrer.

### Ajouté

- **Fiche formation complète** : description courte, objectifs pédagogiques (20 au plus, ordonnés), public et
  prérequis, **modules** (30 au plus : intitulé, durée, modalité du référentiel « Modalités de formation », contenu,
  ordonnés) avec le total des modules et un avertissement s'il diffère de la durée de la formation, méthodes et moyens
  pédagogiques, modalités d'évaluation, sanction, accessibilité, modalités et délais d'accès, indicateurs de résultats.
- **Dupliquer une formation** : copie indépendante et désactivée (fiche, modules, DAS, tarifs, financements), sans
  code ni plaquette, journalisée avec la formation d'origine.
- **Connecteur « Intelligence artificielle »** (Administration › Connecteurs, organisme entier) : Anthropic ou serveur
  compatible OpenAI (OpenAI, Mistral, IA locale Ollama / vLLM…) ; clés chiffrées ; « Serveur sur le réseau local »
  limité à l'hôte et au port enregistrés (jamais le TMS lui-même ni une adresse lien-local, aucune redirection
  suivie) ; délai de 120 s ; bouton « Tester la connexion » ; messages d'erreur fixes en français (un modèle refusé par le
  serveur est nommé : vérifier son nom, majuscules comprises) ; journal sans
  contenu.
- **Assistant IA de la fiche formation** (Nouvelle formation et Modifier) : « Depuis un PDF » (programme de 10 Mo au
  plus, texte lu sur le serveur et coupé à 60 000 caractères, fichier non conservé) ou « Depuis une demande »
  (2 000 caractères) ; proposition validée côté serveur (types, longueurs, 20 objectifs, 30 modules, durées), DAS et
  modalités ramenés aux valeurs de l'organisme ; écran de proposition champ par champ à côté de la valeur actuelle,
  case « Garder la proposition » décochée pour les champs déjà remplis ; « Appliquer » remplit le formulaire,
  l'enregistrement reste le bouton habituel. 20 demandes par heure et par utilisateur.

### Modifié

- **Image applicative** : ajout de `poppler-utils` (`pdftotext`) pour lire le texte des programmes PDF.

## [0.6.2] - 2026-11-16

### Ajouté

- Motif et suite à donner sur les actions.

## [0.6.1] - 2026-11-16

### Corrigé

- Une action se modifie depuis À faire, le planning et les fiches : **Modifier** ouvre la fenêtre de l'action
  (type, objet, échéance avec l'heure, durée, lieu, responsable ; date, résultat et compte rendu d'une action faite),
  dans le périmètre du droit « Modifier » des actions.

## [0.6.0] - 2026-11-16

Dossiers de formation, sessions, inscriptions et financements (lot 2a) : le devis signé devient un dossier.

### Ajouté

- **Sessions** (menu Formation › Sessions) : sessions **intra** (une entreprise), **inter** (plusieurs entreprises ou
  particuliers, nombre de places) et **individuelles** (un stagiaire), modalité (présentiel, distanciel, FOAD, mixte),
  dates, lieu, heures prévues, responsable, numéro `S-2026-0001`, annulation avec motif ; liste, filtres et
  **calendrier** mensuel ; « En cours » et « Terminée » d'après les dates ; **intitulé** facultatif (« Commis de
  cuisine – entrée du 30 novembre 2026 ») affiché partout où la session apparaît et cherché dans la liste.
- **Dossiers de formation** (menu Formation › Dossiers) : contractant (entreprise ou particulier), contact, société
  émettrice, DAS, lignes de formation (prix par stagiaire ou de groupe) et de frais, numéro `D-2026-0001`, étapes
  (convention envoyée, signée, convocations envoyées, en cours, réalisé) et annulation avec motif ; liste avec
  **reste à financer**, filtres, export CSV (« Exporter (.csv) ») ; **historique** du dossier (étapes, inscriptions, financements).
- **Inscriptions** des stagiaires : recherche dans toutes les personnes de l'organisme (sans doublon) ou création sur
  place ; **inscription groupée** (« Inscrire des stagiaires », depuis la session ou le dossier : plusieurs personnes
  d'un coup, date d'entrée commune modifiable par stagiaire, vagues suivantes, tout ou rien avec les erreurs par
  stagiaire) ; **liste d'attente** d'une session inter complète (promotion à la main) ; confirmation, **abandon** (date
  de sortie, motif, force majeure), désinscription ; **heures prévues par stagiaire** (celles de la session par
  défaut, jamais au-delà, journalisées) ; part de prix par stagiaire répartie au centime ; besoins d'adaptation
  (donnée personnelle).
- **Financements** par financeur (entreprise ou personne, type, montant, état, n° de prise en charge, date d'accord,
  ligne ou inscription visée) avec l'alerte « Reste à financer » / « Financements supérieurs au prix ».
- **Créer le dossier** depuis un devis accepté : assistant en trois écrans (récapitulatif, sessions, stagiaires), un
  seul dossier par devis, financement initial du prix total.
- **Règle d'abandon** de l'organisme (prorata, ou prorata et dédit de X %), remplaçable par dossier.
- **Fiches** : blocs « Formations suivies » (personne) et « Dossiers » (entreprise) ; dossiers et inscriptions dans
  l'historique des fiches, selon les droits.
- **Accueil** : carte « Sessions qui démarrent dans les 7 jours ».
- **Droits** : domaines « Dossiers » et « Sessions et inscriptions » (consulter, créer et modifier, supprimer), à
  périmètre ; accordés une fois aux rôles existants par la mise à jour.
- **Référentiels** : types de financeur, motifs d'abandon (force majeure), motifs d'annulation, catégories de stagiaires
  (BPF), niveaux de diplôme, modalités de formation ; niveau de diplôme sur la fiche d'une personne.
- Guide utilisateur `docs/sessions.md`.

### Modifié

- **RGPD** : l'export des données d'une personne contient ses inscriptions et ses dossiers, et exige aussi les droits
  « Consulter les dossiers » et « Consulter les sessions » sur « Tout l'organisme » ; l'anonymisation efface les besoins
  d'adaptation et notes de ses inscriptions et les notes des financements et dossiers qui la concernent.
- **Fusion** : sessions, dossiers, inscriptions et financements suivent la fiche conservée ; deux personnes inscrites
  sur la même session ne se fusionnent pas.
- **Annulation d'un import** : une fiche nommée par une session, un dossier, une inscription ou un financement est
  conservée.

## [0.5.3] - 2026-11-16

Envoi des e-mails par Microsoft 365 et mobile des interlocuteurs.

### Ajouté

- **Connecteur Microsoft 365 (envoi des e-mails)** : devis, plaquettes et invitations partent par Microsoft Graph depuis une boîte Microsoft 365 (permission d'application `Mail.Send`, secret chiffré, jeton en cache), y compris les e-mails volumineux (brouillon et sessions d'envoi), avec e-mail de test et procédure d'installation dans `docs/connectors.md`.
- **Mobile d'un interlocuteur** : la fenêtre « Ajouter un interlocuteur » d'une nouvelle personne demande aussi son mobile (format français ou international, enregistré sur la personne) ; l'onglet Interlocuteurs affiche e-mail, téléphone professionnels et mobile en liens cliquables, le mobile seulement pour les personnes que vous voyez.

## [0.5.2] - 2026-11-15

Plaquettes (PDF) de l'organisme : bibliothèque, pièces jointes des devis, envoi depuis une fiche.

### Ajouté

- **Bibliothèque de plaquettes** (Catalogue › Plaquettes) : dépôt de PDF (10 Mo au plus, contenu vérifié), rattachement à des formations et/ou des DAS, versions successives conservées, corbeille ; plaquettes listées sur la page d'une formation.
- **Plaquettes jointes aux devis** : case « Joindre les plaquettes » à l'envoi (plaquettes des formations du devis, 20 Mo au plus avec le PDF) ; la relance et « Renvoyer » rejoignent les mêmes versions.
- **Envoyer une plaquette** depuis une fiche entreprise, une personne ou une opportunité, avec le modèle d'e-mail « Envoi d'une plaquette » ; envoi consigné dans l'historique (date, auteur, destinataire, état), versions envoyées téléchargeables, mise à jour du dernier contact de la fiche et du destinataire, effacé par l'anonymisation et repris dans l'export RGPD.

## [0.5.1] - 2026-11-15

Publication de la 0.5.0 (même contenu) : la vérification automatique de la 0.5.0 avait échoué sur un test de la
file de traitement, sans défaut de l'application.

### Corrigé

- Test du traitement en arrière-plan : il ne dépend plus de la mémoire occupée par la suite de tests.

## [0.5.0] - 2026-11-15

Droits par domaine et par périmètre, rôles personnalisés, chiffre d'affaires par DAS.

### Ajouté

- **Droits par domaine** : Consulter, Créer et modifier, Supprimer pour les entreprises et personnes, les opportunités,
  les devis et les actions ; nouveaux droits « Voir les actions de l'équipe », « Consulter le chiffre d'affaires »,
  « Gérer les objectifs de chiffre d'affaires » et « Supprimer des formations » ; menus et pages suivent ces droits.
- **Périmètres** « Ses fiches », « Ses DAS », « Tout l'organisme » réglables par droit et par rôle.
- **Rôles personnalisés** (Administration › Rôles et droits › Nouveau rôle) : création, renommage, suppression (refusée
  tant qu'un utilisateur ou une invitation en cours porte le rôle), double authentification réglable par rôle
  (toujours obligatoire pour l'administrateur et la direction).
- **Chiffre d'affaires par DAS** (Commercial › Chiffre d'affaires) : devis acceptés et opportunités gagnées sans devis,
  par mois, trimestre ou année, avec détail, objectifs par DAS et carte « CA signé » sur l'accueil.

### Modifié

- Les droits `crm.view`, `crm.create`, `crm.update`, `crm.delete` sont remplacés par les droits par domaine. La
  migration convertit **automatiquement** les droits de chaque organisme : chaque utilisateur garde exactement ses
  accès, un droit retiré reste retiré, le Responsable DAS reste limité à ses DAS. Elle crée aussi de nouveaux index
  (verrou bref lors de la mise à jour).
- Le Responsable DAS n'est plus reconnu par son nom de rôle : la visibilité suit le périmètre « Ses DAS » du droit.
- **Import** : seules les fiches du périmètre « Créer et modifier » de l'utilisateur qui lance l'import sont complétées ;
  une ligne qui correspond à une fiche existante hors de ce périmètre est ignorée (« Fiche existante hors de votre
  périmètre »), sans doublon.
- Le pourcentage « Atteint » du total du chiffre d'affaires ne compte que les DAS qui ont un objectif.

## [0.4.2] - 2026-11-14

Ajustements après les premiers retours.

### Ajouté

- **Identité de l'organisme** : la barre latérale (et l'en-tête sur téléphone) affiche le **logo** et le **nom de la
  société par défaut** (Administration › Sociétés) à la place de l'icône et du nom génériques ; le titre de l'onglet du
  navigateur se termine par ce nom. Sans logo : icône générique ; sans société par défaut : nom de l'application.
  Le logo est servi aux seuls utilisateurs connectés, mis en cache par le navigateur ; les pages de connexion sont
  inchangées.
- **Formations d'intérêt** sur les fiches entreprise et personne : choix parmi les formations actives du catalogue,
  regroupées par DAS et filtrées par défaut sur les DAS de la fiche (« Afficher toutes les formations » pour élargir) ;
  une formation désactivée reste affichée, grisée. Même droit que la modification de la fiche, 50 formations au plus.
- Listes **Entreprises** et **Personnes** : filtre **Formation d'intérêt** et colonne d'export **Formations
  d'intérêt**.
- **Nouvelle opportunité** depuis une fiche : ses formations d'intérêt actives sont proposées dans les formations de
  l'opportunité (modifiables).
- Historique de la fiche : « Formations d'intérêt modifiées ».
- **Devis signé** : l'exemplaire signé renvoyé par le client (PDF, JPEG ou PNG, 10 Mo au maximum, type réel vérifié)
  se joint dans la fenêtre « Accepté » ou plus tard sur un devis accepté (**Joindre le devis signé**, **Remplacer**) ;
  ouverture depuis le devis, mention « Devis signé ajouté » dans l'historique. Supprimé par l'anonymisation du
  destinataire ; signalé dans l'export de ses données.

- **Rôles et droits** (Administration) : tableau des droits de chaque rôle, regroupés par thème avec une explication,
  case par case, droits différents du défaut surlignés, **Rétablir les droits par défaut** par rôle ; journalisé.
  Nouveau droit `roles.manage` (administrateur seulement). Le rôle administrateur, « Gérer les utilisateurs »,
  « Gérer les rôles et droits » et « Gérer les connecteurs » ne sont pas modifiables ; « Lancer les exports système »
  et « Gérer les paramètres » sont signalés comme droits de niveau administrateur.

- **Modèles d'e-mails** (Administration) : objet et texte de l'envoi et de la relance d'un devis, de l'invitation d'un
  utilisateur et du récapitulatif quotidien, avec variables à insérer, aperçu, e-mail de test et retour au modèle par
  défaut ; variables inconnues, caractères de contrôle dans l'objet et Markdown dans l'invitation neutralisés, aucun
  code interprété. Les e-mails de sécurité restent des modèles système.

### Modifié

- Une formation d'intérêt d'une fiche empêche sa suppression du catalogue (désactivation possible).
- Remplacer le logo d'une société ou le devis signé ne fait plus perdre l'ancien fichier si l'enregistrement échoue.
- Récapitulatif quotidien : « 0 action » au singulier.
- Devis signé : une image s'ouvre dans un bac à sable (`Content-Security-Policy: sandbox`, ajouté en second en-tête
  à la politique globale : il ne peut que la durcir) ; un PDF s'ouvre normalement (type vérifié à l'envoi). Objet
  proposé pour l'envoi d'un devis limité à 200 caractères.
- Workers : les droits sont relus à chaque tâche (plus de droits périmés pour un même organisme).
- Formations d'intérêt : une seule entrée de journal par modification.
- Fusion de fiches : les formations d'intérêt sont réunies (sans doublon). Anonymisation d'une personne : ses
  formations d'intérêt sont supprimées. Export RGPD : section « Formations d'intérêt ».

## [0.4.1] - 2026-11-14

Première publication installable du lot 1d (la 0.4.0 n'a pas pu être publiée).

### Corrigé

- Test d'intégration de la mise à jour adapté aux préfixes de devis uniques par société.

## [0.4.0] - 2026-11-14

Lot 1d : catalogue de formations et devis.

### Ajouté

- **Catalogue de formations** (menu Commercial › **Catalogue**) : intitulé, code, DAS, durées, public visé,
  prérequis, **tarifs entreprise et particulier**, modes de financement possibles ; filtres, tri, **export Excel** ;
  désactivation (la formation reste sur les opportunités et devis qui l'ont) ; suppression refusée pour une formation
  utilisée. Consultation pour tous, gestion par l'administrateur et la direction.
- **Import du catalogue** (type « Catalogue de formations ») : formations retrouvées par identifiant source puis par
  code, financements séparés par `,` ou `;`, simulation et annulation (une formation utilisée depuis est conservée).
- **Formations d'une opportunité** avec leur quantité, filtre et colonne d'export « Formations » ; **Calculer le
  montant proposé** (tarif × quantité selon le compte) et **Reporter dans le montant estimé**.
- **Sociétés** : logo, **préfixe de numérotation** propre à chaque société, durée de validité, conditions générales
  et pied de page des devis.
- **Devis** créés depuis une opportunité, préremplis avec ses formations : lignes avec unité, remise et TVA (20 %,
  10 %, 5,5 %, 2,1 %, 0 %, ou société exonérée avec la mention légale), totaux exacts au centime avec TVA par taux,
  numéro `<préfixe>-<année>-<numéro>` sans trou, par préfixe et par année (heure de Paris).
- **PDF du devis** : aperçu marqué « BROUILLON », PDF **figé et archivé** à l'envoi (il ne change plus même si la
  société ou le client changent).
- **Envoi par e-mail** avec le PDF joint (messagerie de l'organisme), suivi de l'envoi et **Renvoyer** en cas
  d'échec, **Relancer** ; l'opportunité avance d'elle-même à l'étape du devis envoyé (« Proposition » chez CAP
  FORMATION, réglable dans les référentiels).
- **Réponse du client** : « Accepté » propose de passer l'opportunité en **Gagné** avec le montant du devis, « Refusé »
  propose **Perdu** avec un motif ; **expiration** automatique chaque nuit (heure de Paris) ; **Nouvelle version**
  (l'ancien devis est marqué « Remplacé par »).
- Liste **Devis** filtrable avec **taux de réussite** du mois, du trimestre ou de l'année et **export Excel** ; onglet
  **Devis** des fiches et section **Devis** des opportunités ; devis dans l'historique ; tuile **Devis** du mois sur
  l'accueil.
- Menu **Commercial** : Pipeline, Opportunités, Devis, À faire, Planning, Compte rendu de RDV, Catalogue,
  Entreprises, Personnes, Imports, RGPD.
- Fusion, export des données d'une personne et anonymisation couvrent les devis (le PDF archivé d'une personne
  anonymisée est supprimé).
- Documentation : guide utilisateur (catalogue, devis), guide d'administration (réglages des devis, **régime de
  TVA à choisir à l'installation**, étape du devis envoyé, droits du catalogue), dictionnaire de données, installation
  derrière un proxy (Nginx Proxy Manager). Test de bout en bout du parcours devis.

### Sécurité et fiabilité

- Service **Gotenberg** (conversion des devis en PDF) ajouté à la pile, sans JavaScript ni accès réseau, non exposé ;
  documents autonomes (logo intégré), textes saisis toujours échappés.
- Logo des sociétés contrôlé (PNG ou JPEG réels, 1 Mo au plus), rangé dans le stockage privé de l'organisme.
- Échecs d'envoi d'e-mail enregistrés sans adresse ni texte du serveur ; aucune donnée personnelle des devis en clair
  dans le journal d'audit.
- Numérotation des devis sûre en cas d'envois simultanés (aucun doublon, aucun trou).
- Un brouillon ne part pas si le **régime de TVA de la société a changé** depuis son enregistrement (le brouillon
  et son formulaire le signalent) ni si sa **date de validité est dépassée** ; un devis dont le PDF archivé est
  introuvable ne se relance ni ne se renvoie (« Le PDF archivé est introuvable : contactez l'administrateur. »).
- Le taux de réussite ne compte pas les devis remplacés par une nouvelle version ; une nouvelle société reçoit
  d'emblée le premier préfixe de numérotation libre.

## [0.3.1] - 2026-11-14

Première publication installable (Proxmox / serveur) ; la 0.3.0 n'a pas pu être publiée.

### Corrigé

- Interface en français même sans `APP_LOCALE` dans la configuration.
- Pages trouvées sur les systèmes sensibles à la casse (serveurs Linux).
- Ordre des exécutions de connecteurs stable quand deux exécutions démarrent à la même seconde.
- Intégration continue : tests, vérification des types et audit des dépendances fiables sur GitHub.

## [0.3.0] - 2026-11-14

Lot 1c : pipeline commercial, actions et relances, planning, agenda.

### Ajouté

- **Opportunités** sur une entreprise ou un particulier : objet, DAS, étape, montant estimé et **montant pondéré**,
  clôture prévue, priorité, mode de financement, interlocuteurs, responsable. Liste filtrable avec totaux et
  **export Excel**.
- **Étapes du pipeline** paramétrables avec leur probabilité : la probabilité suit l'étape (une saisie manuelle est
  conservée au prochain changement d'étape, puis l'étape reprend la main), « Gagné » demande la date de gain, « Perdu » **exige un motif de perte**.
  Chaque changement est tracé dans l'historique de la fiche.
- **Pipeline (kanban)** : une colonne par étape avec nombre, montant et pondéré ; glisser-déposer à la souris ou au
  clavier ; affaires gagnées et perdues des 90 derniers jours ; liste par étape sur téléphone.
- **Actions** (appel, rendez-vous, e-mail, rappel…) et **saisie rapide « + Action »** depuis n'importe quel écran du
  CRM, préremplie avec la fiche ouverte : fait maintenant ou à faire, résultat, compte rendu, **prochaine relance**
  en un clic (demain, dans une semaine, dans un mois). Les actions faites mettent à jour le dernier contact.
- **« À faire »** : vos actions en retard, du jour et de la semaine (jours à l'heure de Paris), « Fait »,
  « Reporter », vue **Équipe** pour la direction ; compteur dans le menu.
- **Récapitulatif quotidien** par e-mail à 7 h 30, sur demande (profil).
- **Planning de la semaine** (rendez-vous et appels planifiés, jour par jour sur téléphone, planning d'un collègue
  pour la direction) ; les types d'action concernés se règlent dans les référentiels (« Planning commercial »).
- **Compte rendu de RDV** au téléphone en un seul formulaire : résultat, compte rendu, étape de l'opportunité et
  prochaine relance enregistrés ensemble.
- **Mon agenda** : lien d'abonnement **iCal** privé (Outlook, Google Agenda, Apple Calendrier) et, avec Microsoft
  365, **envoi direct des rendez-vous dans Outlook** (connecteur à déclarer dans Azure, voir `docs/connectors.md`).
  Outlook suit aussi la fiche et l'opportunité (corbeille, restauration, fusion) ; « Déconnecter Outlook » retire
  de l'agenda les rendez-vous envoyés, et une reconnexion ne crée pas de doublon.
- **Accueil** : « Mes relances » (en retard / aujourd'hui), **pipeline pondéré du mois**, **prochains
  rendez-vous**, dans le périmètre de chacun.
- Menu **Commercial** réorganisé : Pipeline, Opportunités, À faire, Planning, Compte rendu de RDV, Entreprises,
  Personnes, Imports, RGPD.
- Fusion, export RGPD et anonymisation couvrent les opportunités et les actions.
- Documentation : guide utilisateur (pipeline, relances, planning, compte rendu, agenda), guide d'administration,
  dictionnaire de données. Tests de bout en bout du parcours pipeline et du compte rendu sur téléphone.

### Modifié

- L'historique d'une fiche se pagine sans perte ni doublon, même pour des évènements à la même microseconde.
- Les fiches sont lisibles sur un téléphone de 375 px sans défilement horizontal (fil d'Ariane raccourci).
- L'export des personnes propose aussi le système source, l'identifiant source et la base légale.
- Les exports Excel écrivent montants, probabilités et dates comme de vrais nombres et de vraies dates (format
  français) : ils s'additionnent, se trient et se filtrent dans Excel.

### Sécurité et fiabilité

- **Imports** : surveillance des imports bloqués (reprise automatique une fois, puis échec signalé), une ligne
  refusée par la base passe en erreur sans arrêter l'import, contrôle du contenu réel des fichiers déposés, nom du
  fichier d'origine masqué au journal et remplacé au bout de 30 jours.
- **Connecteurs** : les connexions sortantes (Sirene, Microsoft, SMTP) sont attachées aux adresses vérifiées
  (protection contre la redirection DNS).
- **Pièces jointes** des fiches en corbeille depuis plus de 12 mois effacées du stockage (« Fichier supprimé »).
- Lien iCal : jeton aléatoire dont seule l'empreinte est stockée, limité en nombre d'appels ; jetons Microsoft
  chiffrés, jamais journalisés.
- Responsable DAS : opportunités, actions et chiffres de l'accueil limités à ses DAS.
- Test de volume : recherche sur 50 000 fiches en moins d'une seconde sous isolation des organismes.

## [0.2.0] - à publier

Lot 1b : fiches commerciales, import, export et RGPD.

### Ajouté

- **Entreprises** et **Personnes** : saisie rapide (un seul champ obligatoire), particuliers et interlocuteurs,
  rattachements avec fonction et rôle dans la décision, DAS, responsable, corbeille et restauration, interface
  utilisable sur téléphone.
- **Historique** de chaque fiche : notes, pièces jointes (20 Mo) et modifications, avec dernier contact.
- **Recherche** : recherche globale (Ctrl K) insensible aux accents, filtres de liste, filtres enregistrés
  (privés ou partagés), choix des colonnes.
- **Doublons et fusion** : avertissement à la saisie, fusion champ par champ avec conservation de l'historique
  et des rattachements ; jamais de comparaison entre organismes.
- **Complétion par SIRET** depuis l'annuaire officiel des entreprises (connecteur Sirene) ; contrôle de la clé
  du SIRET.
- **Import** CSV / Excel pas à pas (entreprises, personnes, interlocuteurs, notes) : détection de l'encodage
  et du séparateur, association des colonnes et des valeurs, simulation sans écriture, stratégie face aux
  doublons, rapport Excel, profils réutilisables, **annulation** qui préserve le travail des utilisateurs.
- **Export Excel** des listes filtrées, tracé au journal d'audit, protégé contre l'injection de formules.
- **RGPD** : base légale, consentements datés, opposition à la prospection, export des données d'une personne,
  durée de conservation paramétrable (3 ans par défaut), liste « À anonymiser », anonymisation irréversible
  automatique après 30 jours, e-mail de synthèse aux responsables RGPD.
- **Droits CRM** par rôle (huit permissions), visibilité limitée aux DAS suivis pour le Responsable DAS.
- **Gestion des utilisateurs** : changement de rôle, désactivation / réactivation, renvoi et révocation
  d'invitation, garde-fou « au moins un administrateur actif ».
- **Accueil** : raccourcis (nouvelle entreprise, nouveau particulier, recherche), compteurs et cinq dernières
  fiches consultées.
- Documentation : guide utilisateur `docs/crm.md`, dictionnaire de données et guide d'administration mis à jour.
- Tests de bout en bout du parcours de fiches (création, interlocuteur, note, doublon et fusion, recherche,
  export, affichage mobile).

### Modifié

- Le fuseau horaire de la session PostgreSQL suit celui de l'application (Europe/Paris).
- Les messages d'erreur affichés à l'utilisateur sont tous en français ; seuls les messages prévus pour lui
  sont montrés, les autres erreurs affichent un texte fixe.

### Sécurité

- Les valeurs des données personnelles n'apparaissent jamais en clair dans le journal d'audit.
- Fichiers des organismes rangés par organisme dans un stockage privé ; adresses appelées par les connecteurs
  filtrées (adresses internes refusées).

## [0.1.0] - à publier

Lot 1a : socle de l'application.

### Ajouté

- Socle Laravel 12 / Inertia / React : authentification, rôles et permissions, double authentification
  obligatoire pour l'administrateur et la direction, mots de passe Argon2id.
- Multi-organismes : isolation par `tenant_id` et sécurité au niveau des lignes PostgreSQL (rôle applicatif
  non propriétaire), mode « un organisme » ou plateforme partagée.
- Journal d'audit en ajout seul, référentiels paramétrables par organisme, interface en français.
- Socle des connecteurs externes (adaptateurs isolés, secrets chiffrés).
- Exploitation : image de production, `compose.prod.yaml` (Caddy, PostgreSQL, Redis, Horizon, Gotenberg),
  installateur en une ligne (Proxmox LXC et Debian), sauvegardes restic quotidiennes, restauration,
  mise à jour avec retour arrière automatique.
- Publication : image multi-architecture signée (cosign), archive de version, sommes de contrôle.

[Non publié]: https://github.com/sebvtl728/CRMTMS/compare/v0.12.0...HEAD
[0.12.0]: https://github.com/sebvtl728/CRMTMS/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/sebvtl728/CRMTMS/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/sebvtl728/CRMTMS/compare/v0.9.2...v0.10.0
[0.9.2]: https://github.com/sebvtl728/CRMTMS/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/sebvtl728/CRMTMS/compare/v0.9.0...v0.9.1
[0.8.0]: https://github.com/sebvtl728/CRMTMS/compare/v0.7.1...v0.8.0
[0.7.1]: https://github.com/sebvtl728/CRMTMS/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/sebvtl728/CRMTMS/compare/v0.6.2...v0.7.0
[0.6.2]: https://github.com/sebvtl728/CRMTMS/compare/v0.6.1...v0.6.2
[0.6.1]: https://github.com/sebvtl728/CRMTMS/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/sebvtl728/CRMTMS/compare/v0.5.3...v0.6.0
[0.5.3]: https://github.com/sebvtl728/CRMTMS/compare/v0.5.2...v0.5.3
[0.5.2]: https://github.com/sebvtl728/CRMTMS/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/sebvtl728/CRMTMS/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/sebvtl728/CRMTMS/compare/v0.4.2...v0.5.0
[0.4.1]: https://github.com/sebvtl728/CRMTMS/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/sebvtl728/CRMTMS/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/sebvtl728/CRMTMS/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/sebvtl728/CRMTMS/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/sebvtl728/CRMTMS/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/sebvtl728/CRMTMS/releases/tag/v0.1.0
