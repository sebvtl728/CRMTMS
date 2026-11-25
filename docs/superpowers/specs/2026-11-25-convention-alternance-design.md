# Convention de formation en alternance — conception

Statut : **validée** (25/11/2026).

## Intention

À l'étape **Admis**, « Enregistrer le contrat » doit produire tout de suite la **convention de formation en alternance** entre le CFA (la société émettrice) et l'employeur, avec un texte propre au type de contrat (apprentissage ou professionnalisation). Le texte doit être modifiable dans **Administration › Modèles de documents**, comme la convention et le contrat de formation. But : ne plus rédiger cette convention à la main, avant le test réel de décembre.

## Décisions de l'utilisateur

1. Le document est la **convention de formation en alternance** CFA ↔ employeur (pas le Cerfa officiel).
2. **Aperçu au clic, émission au dossier** :
   - « Enregistrer le contrat » génère un PDF d'aperçu non numéroté ;
   - la vraie convention (numérotée, archivée, envoyable et signable en ligne) est émise à la création du dossier ;
   - pour un dossier en alternance, elle **remplace** la convention de formation classique.

## Approches (choisie : 1)

1. **Aperçu au clic, émission au dossier** (choisie) : réutilise tout le module Documents (numérotation, archive, envoi, signature en ligne, textes).
2. Émission seulement au dossier : rien au clic, écarté (ne répond pas à la demande).
3. Émission dès le clic, sur la candidature : écarté, second circuit de numérotation, d'archive et de signature hors dossier, trop lourd avant le 30/11.

## Ce que voit l'utilisateur

- **Carte Alternance** (étape Admis ou après) : « Enregistrer le contrat » enregistre puis ouvre l'aperçu PDF de la convention dans un nouvel onglet. Un bouton **« Convention d'alternance (aperçu PDF) »** à côté de « Fiche Cerfa (PDF) » la régénère à tout moment. Le bandeau de l'aperçu porte « Aperçu – non émis ». Avant l'étape Admis (Entretien), le contrat s'enregistre sans aperçu, puis la candidature passe à Admis (règle du 25/11) et l'aperçu s'ouvre.
- **Création du dossier** d'un candidat en alternance : la convention d'alternance du bon type est **émise automatiquement** (comme si l'utilisateur avait cliqué « Émettre »). En cas d'échec (Gotenberg en panne, pas de société émettrice), le dossier est quand même créé et le message dit « Convention non émise : … ; émettez-la depuis le dossier ».
- **Bloc Documents du dossier** : pour un dossier en alternance, le bouton propose « Convention d'apprentissage » ou « Convention de professionnalisation » (selon le contrat) au lieu de « Convention de formation » ; envoi, signature en ligne et annulation comme la convention actuelle.
- **Modèles de documents** : deux nouveaux modèles, **« Convention de formation – apprentissage »** et **« Convention de formation – professionnalisation »**, avec leurs blocs modifiables (organisme, puis société).

## Règles

- Deux nouveaux types `DocumentType::ApprenticeshipConvention` (`apprenticeship_convention`) et `ProfessionalisationConvention` (`professionalisation_convention`) : document du dossier, `signable()`, `editable()`, destinataire = le contractant entreprise (l'employeur, contractant par défaut d'un dossier en alternance) et son contact. Une seule valide par dossier et type, comme la convention.
- Le type est choisi par le `contract_type` du contrat d'alternance (`apprenticeship` → apprentissage, `professionalisation` → professionnalisation). Un dossier sans contrat d'alternance ne peut pas émettre ces types (409 « Ce dossier n'a pas de contrat d'alternance »). Un dossier **avec** contrat refuse la convention classique (409 « Ce dossier est en alternance : émettez la convention d'apprentissage / de professionnalisation »). Le contractant doit être une entreprise (sinon 409, message de la convention).
- Un contrat modifié après émission ne change pas la convention émise (archive figée) : il faut l'**annuler** (motif) et en émettre une nouvelle, comme aujourd'hui.
- **Champs** de texte, en plus des champs actuels : `{apprenti}`, `{employeur}`, `{tuteur}`, `{type_contrat}`, `{debut_contrat}`, `{fin_contrat}`, `{heures_centre}`, `{code_rncp}`, `{opco}`. Remplacés littéralement, jamais de balise (règle actuelle).
- **Textes par défaut** (blocs, à vérifier par l'organisme ; avertissement « à vérifier » affiché sur le modèle comme pour Qualiopi) :
  - apprentissage : parties, objet (formation « {formation} », titre RNCP {code_rncp}, contrat d'apprentissage du {debut_contrat} au {fin_contrat}), organisation de la formation en alternance ({heures_centre} en centre, planning annexé), obligations du CFA, obligations de l'employeur (temps de formation, maître d'apprentissage {tuteur}, liaison), suivi et évaluation (livret d'apprentissage, visites), financement (prise en charge par l'OPCO {opco} selon le niveau de prise en charge, reste à charge éventuel de l'employeur : {prix}), rupture du contrat, litiges ;
  - professionnalisation : même trame, références au contrat de professionnalisation, tuteur, financement par l'OPCO {opco}.
  - Blocs `required` : parties, objet, financement.
- **Aperçu** : mêmes gabarit et textes (société par défaut, ou la société choisie si le dossier existe), données de la candidature et du contrat ; ce que le dossier n'a pas encore (prix, numéro de dossier, dates de la session si aucune session visée) est imprimé « à compléter ». Jamais archivé, jamais numéroté ; droit « Consulter les candidatures ».

## Frontières (exceptions d'architecture, à inscrire dans `ModuleBoundariesArchTest` et `CLAUDE.md`)

- Documents ne lit pas Admissions : le contrat d'alternance lui parvient par un **registre de Sessions** : `Sessions\Public\Paperwork::registerAlternance(fn (string $dossierId): ?array)`, déclaré par Admissions dans son `boot`. `Paperwork::dossier()` expose `alternance` (type, dates, employeur, tuteur, OPCO, apprenti, code RNCP), null sinon.
- Admissions utilise **`Documents\Public\AlternanceConventions`** (`preview(array $data): string` PDF, `issueForDossier(string $dossierId, ?User $by)`), seule entrée publique du module Documents. C'est la seule exception à « aucun module n'importe Documents ».
- L'émission automatique à la création du dossier est appelée par `Admissions\Candidatures\DossierCreation` **après** la transaction du dossier (un échec d'émission n'annule jamais le dossier).

## RGPD

Rien de nouveau : la convention émise suit `DocumentAnonymisation` (destinataire figé effacé, PDF gardé) ; l'aperçu n'est jamais stocké.

## Non-buts

Cerfa officiel FA13 / EJ20 ; calcul du NPEC ; envoi à l'OPCO ; convention tripartite signée par l'apprenti (l'apprenti signe son contrat de travail, pas cette convention) ; régénérer la convention émise quand le contrat change.

## Laissé au constructeur

Mise en page (reprise du gabarit `training-convention`), libellés exacts des blocs, rédaction fine des textes par défaut (à faire relire par CAP), ordre des tâches.
