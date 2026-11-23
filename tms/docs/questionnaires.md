# Questionnaires de satisfaction (0.11)

Recueillir l'avis des stagiaires (et, si vous le souhaitez, de l'entreprise) à la fin de chaque session et quelques
mois après : c'est le « recueil des appréciations » de l'indicateur Qualiopi 30, dont les taux alimentent aussi
l'indicateur 2. Les réponses se font **sans compte**, sur téléphone ou ordinateur.

## 1. Les deux questionnaires

- **À chaud** : à la fin de la formation. Chaque stagiaire reçoit un e-mail avec son **lien personnel** ; un **QR code**
  peut être affiché en salle le dernier jour.
- **À froid** : envoyé automatiquement quelques mois après la fin de la session (3 mois par défaut, réglable dans
  Administration › Questionnaires ; 0 = jamais).
- **Entreprise** (facultatif) : quand le modèle « À chaud — entreprise » est actif, le contact du dossier de chaque
  entreprise reçoit son questionnaire en même temps que les stagiaires.

Un lien reste ouvert **30 jours** après l'envoi. Chaque destinataire ne répond qu'**une fois**.

## 2. Lancer le questionnaire à chaud

Page de la session, étape « Stagiaires », bloc **Questionnaires** (droit de modifier la session) :

- **Lancer le questionnaire à chaud** : une invitation par stagiaire suivi (inscrit ou confirmé, sorties exclues) ; un
  e-mail part à ceux qui ont une adresse. Les autres répondent par le QR code.
- **Afficher le QR code** : page plein écran à projeter ou imprimer. Le stagiaire scanne, choisit son nom (« Léa D. »)
  parmi ceux qui n'ont pas encore répondu, puis répond. Sa réponse ferme aussi son lien e-mail.
- **Inviter les nouveaux stagiaires** : un stagiaire ajouté après le lancement reçoit à son tour son invitation (les
  autres ne reçoivent rien de plus).
- Si personne ne l'a lancé, le questionnaire à chaud part **automatiquement le dernier jour à 13 h 30**
  (`tms:automation:last-day`) ; le lendemain matin et jusqu'à 30 jours après la fin, l'envoi de 7 h rattrape ceux qui
  auraient manqué, jamais au-delà.
- **Le bloc Questionnaires** de la session dit pour chacun (à chaud, à froid) s'il est **envoyé** (date, réponses) ou
  quand il **part tout seul** (badge « Automatique ») ; lancer à la main, inviter les nouveaux et le QR code (pour le
  faire remplir en salle) sont repliés sous « Envoyer à la main ».
- **Envoyer le questionnaire à froid maintenant** : possible une fois la session terminée, sans attendre le délai.

Une session annulée ne reçoit aucun questionnaire et ses liens ne fonctionnent plus.

## 3. Lire les résultats

- **Sur la session** : réponses / invités, taux de réponse, satisfaction moyenne (sur 5), part de **satisfaits**
  (moyenne des notes d'au moins 4/5), puis question par question : moyenne et répartition des notes, décompte des
  choix, commentaires (**sans nom**). La liste des destinataires indique l'envoi de l'e-mail et « Répondu » ou « En
  attente », jamais le contenu de la réponse.
- **Carte « Satisfaction »** de la synthèse (page de la session et colonnes) : à faire après le dernier jour tant
  qu'aucun stagiaire n'a répondu, puis la moyenne et le nombre de réponses.
- **Fiche de l'apprenant** : « Formations suivies » indique la satisfaction de sa réponse à chaud (« 4,5/5 »).
- **Qualité › Questionnaires** (droit « Consulter les résultats des questionnaires ») : toutes les sessions terminées sur
  une période (12 derniers mois par défaut), filtres type, destinataires et formation, totaux et **export CSV**
  (séparateur « ; », journalisé).
- **Qualité › Qualiopi**, indicateurs 30 et 2 : taux de réponse et de satisfaction à chaud et à froid des 12 derniers
  mois, et satisfaction des entreprises si le questionnaire entreprise est utilisé.

## 4. Modèles de questionnaires

Administration › **Questionnaires** (droit « Paramétrer les questionnaires ») :

- trois modèles sont créés au premier usage : « À chaud — stagiaires », « À froid — stagiaires » (actifs) et « À chaud —
  entreprise » (inactif) ; vous pouvez les modifier, en créer d'autres et choisir celui **par défaut** de chaque type et
  destinataires ;
- types de questions : **note de 1 à 5**, **choix** (2 à 10 réponses, une seule à cocher), **texte libre** ; chaque
  question peut être obligatoire ; 30 questions au plus ;
- une session garde la **copie** des questions envoyées : modifier un modèle ne change jamais les questionnaires déjà
  partis ni leurs résultats ;
- les textes des e-mails se règlent dans Administration › Modèles d'e-mails (« Questionnaire de satisfaction à chaud /
  à froid / entreprise ») ; `{lien}` y est obligatoire.

## 5. Données personnelles

- Les réponses sont rattachées au destinataire en base (taux de réponse, satisfaction sur la fiche) mais affichées
  sans nom ; aucun texte de réponse ni aucune adresse n'entre dans le journal d'audit.
- L'anonymisation d'une personne efface son adresse et ses **réponses en texte libre**, garde ses notes et ses choix
  (non identifiants) et ferme ses liens. La fusion de deux fiches garde, pour un même questionnaire, la réponse donnée.
- L'export RGPD d'une personne contient ses questionnaires (envoi, date de réponse, réponses) ; il exige aussi le droit
  « Consulter les résultats des questionnaires » sur tout l'organisme.
