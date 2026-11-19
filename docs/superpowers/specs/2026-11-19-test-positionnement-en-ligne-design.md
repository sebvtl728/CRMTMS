# Test de positionnement en ligne — conception

Date : 2026-11-19. Demande : « reprendre le test PDF et le remplir en ligne » ; le candidat répond **sur un ordinateur** (pas de téléphone) ; **éviter le copier-coller** pour limiter l'usage de l'IA. Exemple de référence : « Livret de recrutement et positionnement — métiers des services administratifs » (12 pages, CAP FORMATION).

## Intention

Le chargé d'admission transmet un lien ; le candidat passe le test à l'écran ; les questions fermées sont corrigées seules, les questions ouvertes se notent à la main sur la candidature ; la note arrive dans la carte « Test de positionnement ». Le PDF existant sert de point de départ : l'IA du TMS en propose la version en ligne, relue par l'organisme.

Non-objectifs : surveillance par caméra, verrouillage du navigateur, détection d'IA dans les réponses, correction automatique des réponses rédigées.
Anti-objectifs : un test qui se passe sur téléphone ; un candidat qui perd ses réponses (coupure, onglet fermé) ; une note calculée sur une version du test modifiée après coup.

## Ce que montre le livret (types de questions nécessaires)

| Type | Exemple du livret | Correction |
|---|---|---|
| Texte court | « Que signifie TVA ? », définitions | à la main |
| Texte long | avis sur le CPF (20 lignes), corriger un texte | à la main |
| Choix unique | métier visé, 30 jours ouvrables / ouvrés, diagramme 1-2-3 | automatique si une bonne réponse est donnée, sinon profil |
| Choix multiple | ateliers suivis (Français, Maths…) | automatique ou profil |
| Vrai / faux | pourcentages | automatique |
| Nombre à compléter | fractions, facture, salaire, primes | automatique (valeur attendue, tolérance) |
| Oui / non + précision | « accompagné(e) ? si oui, par qui ? » | profil |

Une question peut porter un **texte d'appui** (texte à corriger, article) et une **image** (diagrammes, tableaux de la page du PDF).

## Approches

1. **Moteur de questionnaires dans Admissions** (tables à lui, lien du candidat existant) — retenue : livre le besoin sans attendre la 0.11 ; conçu pour être déplacé plus tard dans un module « Questionnaires » commun (quiz, satisfaction).
2. Formulaire externe (Google Forms, Microsoft Forms) : pas de blocage du copier-coller ni de note sur la candidature, données hors du TMS ; écartée.
3. Remplir le PDF en ligne (champs de formulaire PDF) : pas de correction, pas de blocage, mauvais rendu ; écartée.

## Décisions

- **Test en ligne** (`positioning_forms`) rattaché à des DAS et/ou des formations (comme les tests PDF), avec sections, questions, bonnes réponses, barème, **durée limite** facultative, états brouillon / publié. Publier **fige une version** : un candidat répond toujours à la version figée qu'il a ouverte ; modifier un test publié crée une nouvelle version.
- **« Créer depuis un PDF »** : l'IA du connecteur existant (Anthropic ou IA locale) lit le PDF et propose sections, questions, types et choix — jamais les bonnes réponses (absentes du PDF). Tout arrive en **brouillon**, relu et complété par l'utilisateur ; rien n'est publié sans lui. Les pages contenant des schémas peuvent être jointes en image à une question.
- **Lien** : le même « Générer le lien » / « Envoyer par e-mail » ; le chargé choisit le test en ligne ou des PDF. Lien valable 14 jours, un seul passage.
- **Page du candidat (ordinateur seulement)** : sur un écran étroit ou tactile, message « Ouvrez ce lien sur un ordinateur », le test ne démarre pas. Bouton **Commencer** (le chronomètre part, durée affichée), une section à la fois, **réponses enregistrées au fil de l'eau** sur le serveur (reprise possible sur le même lien tant que le test n'est pas envoyé), **Envoyer mes réponses** (confirmation), envoi automatique à la fin du temps.
- **Anti-triche** : coller, copier, couper, glisser-déposer et clic droit bloqués ; texte des questions non sélectionnable ; correcteur et saisie automatique désactivés. **Traces** : chaque sortie de la page (autre onglet, autre fenêtre) et sa durée, temps total, collages refusés ; affichées avec les réponses. Limite assumée : rien n'empêche une IA sur un second appareil.
- **Correction** : questions fermées notées seules ; questions ouvertes notées à la main sur la candidature (« À corriger ») ; note par section et note globale ; la note écrit « Score ou niveau » et « Reçu le », l'étape passe à « Test reçu » (jamais en arrière).
- **Droits** : créer et modifier les tests en ligne = `catalogue.manage` (comme la bibliothèque) ; corriger = modifier la candidature ; voir les réponses = consulter la candidature.
- **RGPD** : réponses et traces = données personnelles de la candidature (`$auditPersonal`), effacées par l'anonymisation et la purge des 2 ans.

## Découpage

1. **Moteur** : éditeur de test, versions, lien, passage à l'écran avec anti-triche et sauvegarde, correction automatique et manuelle, note sur la candidature.
2. **Import du PDF par l'IA** dans l'éditeur.

## Laissé au constructeur

Mise en page de l'éditeur et de la page du candidat, seuil de largeur « ordinateur » (≥ 1024 px et pointeur fin), tolérance par défaut des nombres (0,01), libellés exacts.
