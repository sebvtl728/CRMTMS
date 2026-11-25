# Fiche candidature : présentation claire — conception courte

Statut : validée le 25/11/2026 (approche A).

## Intention
La fiche empile une dizaine de cartes de même poids : on ne voit ni où en est le candidat ni quoi faire ensuite. But : lire le parcours dans l'ordre et trouver l'action suivante d'un coup d'œil, sans changer les données ni les formulaires.

## Approches
- **A. Bandeau d'étapes + deux colonnes** (choisie) : bandeau Identifié → Test envoyé → Test reçu → Entretien → Admis → Dossier, étape courante surlignée, « Prochaine action » à droite ; colonne large = le parcours dans l'ordre (Orientation, Test, Entretien, Alternance et livret, Dossier de formation, Suivi de formation) ; colonne latérale = Identité, Représentant légal, Informations complémentaires, Historique.
- B. Onglets : écarté, perd la vue d'ensemble.
- C. Simple remise en ordre : écarté, gain trop faible.

## Design
- Bandeau `components/admissions/candidature-steps.tsx` : étapes du parcours, faites / courante / à venir ; une candidature non retenue ou désistée affiche son étape de sortie en rouge ; chaque étape mène à sa carte (ancre).
- « Prochaine action » (calculée à l'écran, sans nouvelle donnée) : Identifié → « Envoyer le test de positionnement » ; Test envoyé → « Noter la réception du test » ; Test reçu → « Planifier l'entretien » ; Entretien → « Saisir le contrat d'alternance » (parcours Alternance sans contrat) sinon « Passer à l'étape Admis » ; Admis sans dossier → « Créer le dossier » (bouton si le droit) ; dossier créé → « Suivre la formation ». Sortie : rien.
- Représentant légal : sort de la carte Orientation vers sa propre carte de la colonne latérale (même enregistrement que l'orientation).
- Sous `lg`, une seule colonne : bandeau, colonne latérale (Identité en premier), puis parcours.
- Aucun changement serveur ; tests : lint, types, build et `--filter=Admissions` ; vérification à l'écran.

## Laissé au constructeur
Libellés exacts, couleurs (tokens existants), espacements.
