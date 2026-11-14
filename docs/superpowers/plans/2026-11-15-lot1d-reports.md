# Reports du lot 1d vers les lots suivants

Issus des revues de la branche `lot1d-catalogue-devis` (catalogue, devis). Les points bloquants ont été corrigés ;
restent ceux-ci, non bloquants. Les reports du lot 1c (`2026-11-14-lot1d-carryover.md`) restent valables.

## 1. Catalogue et import

- Modifications des financements d'une formation non journalisées (table pivot hors audit).
- Lien d'export limité (429) : page JSON brute au lieu d'un message.
- Aides `Amount::parse` / référentiels dupliquées dans Catalogue : à déplacer dans Core.
- Routes `POST /catalogue/create` et `PUT /catalogue/{training}/edit` non REST.
- Responsable DAS : voit tout le catalogue et peut ajouter à une opportunité une formation d'un autre DAS (question produit).
- Import : réimport après annulation = nouvel identifiant ; détection « aucune modification » à la seconde ; tests manquants (non-gestionnaire qui lance/annule un lot, variante .xlsx).
- Assistant d'annulation : la phrase « fiches modifiées depuis l'import » inclut maintenant les formations utilisées (« modifiées ou utilisées »).
- Formations d'opportunité : une recherche par ligne (`Trainings::findMany`) ; pas d'estimation à la création (opportunité non enregistrée).

## 2. Société et numérotation

- Remplacement du logo sous le même nom : fichier écrasé avant la validation en base (nom temporaire + renommage).
- Envoi du logo sans limiteur nommé ; logo complet en base64 dans le formulaire.
- Création simultanée de deux sociétés : course possible sur le suffixe de préfixe (erreur base au lieu d'un message).
- Restauration d'une société supprimée dont le préfixe a été repris : violation d'index (aucune route de restauration aujourd'hui).
- Année du numéro = date de création, pas `issued_on` (documenté).

## 3. Devis

- Livraison « au moins une fois » : un plantage du worker entre l'envoi SMTP et l'écriture du statut renvoie l'e-mail.
- `SendQuote` : `archived()` hors du `try` ; tableau de délais à 3 valeurs pour 2 nouvelles tentatives.
- Fichier PDF orphelin si la transaction de l'appelant est annulée après `freeze()`.
- Un devis expiré ne peut pas être accepté (signature tardive) : à documenter ou à permettre.
- Anonymisation d'un interlocuteur : `notes` et `refusal_reason` des devis d'entreprise conservés (peuvent la nommer).
- Commande `ExpireQuotes` dans `app/Console/Commands` (les autres sont dans le module).
- Bouton « Envoyer » désactivé : le motif n'est visible que dans le bandeau (pas d'info-bulle sur un bouton désactivé).

## 4. Infrastructure et tests

- CI : le service Gotenberg tourne sans les options de durcissement (impossible pour un service GitHub ; le lancer en étape).
- Chromium (Gotenberg) peut lire `file:///tmp` (défense en profondeur).
- La pile de développement démarre Gotenberg avec app/worker/scheduler.
- `personalTraces()` défini dans `AnonymiserTest` : le dossier Quotes lancé seul échoue (déplacer dans `tests/Pest.php`).
- Retour arrière de la migration élargissant les cibles d'import impossible si un lot « trainings » existe.
- `SecurityHeadersTest` échoue parfois en dev (dossier de cache appartenant à root) : environnement.
- Installation derrière un proxy : mode dédié à prévoir (adresse IP réelle des visiteurs, `TRUSTED_PROXIES`).
