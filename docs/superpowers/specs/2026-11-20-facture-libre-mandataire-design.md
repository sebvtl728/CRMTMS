# Facture libre et co-traitance (mandataire d'un groupement) — conception

Date : 2026-11-20. Statut : à valider. Échéance : présentation 30 novembre 2026, test réel début décembre.

## Intention

CAP FORMATION est **mandataire d'un groupement** sur des lots de marchés publics (ex. Région, « Lot 55 »). Chaque
mois, une **facture globale** part à l'acheteur (via Chorus Pro) : une ligne par session (« composante ») de CAP et
des **co-traitants**. L'acheteur paie le mandataire, qui **reverse** à chaque co-traitant sa part. Formevol le fait
avec des « factures annexes » (facture créée librement) : il faut l'équivalent.

## Approches

1. **Facture libre + suivi des reversements** (retenue) : facture sans dossier, lignes saisies, une ligne peut désigner
   un co-traitant ; écran « À reverser ». Rapide, remplace Formevol tout de suite ; montants saisis à la main.
2. Objet « Marché » + facture mensuelle préremplie depuis les heures réalisées : plus juste, mais la règle de calcul
   n'est pas encore connue. **Étape suivante**, bâtie sur la 1.
3. Facture de dossier étendue à plusieurs dossiers : écartée (casse dû, avoirs et verrouillage d'une pièce
   inaltérable).

Choix : approche 1, tranchée par Claude pour tenir l'échéance (l'utilisateur a demandé d'accélérer).

## Conception

- **Origine d'une facture** : `invoices.origin` = `dossier` (existant) ou `free`. `dossier_id` devient nullable, CHECK
  `(origin = 'dossier') = (dossier_id is not null)`. Une facture libre n'a ni financement, ni plan, ni dû calculé :
  seulement des lignes libres (quantité × prix unitaire, taux de TVA par ligne). Destinataire = une fiche entreprise
  ou personne (`Crm\Public\Fiches::billingIdentity`), société émettrice choisie.
- **Tout le reste est commun** : numérotation (même séquence que les factures), validation avec PDF dans la
  transaction, journal chaîné, déclencheurs d'inaltérabilité, envoi, règlements, relances, avoir (verrou de la facture
  au lieu du dossier). La validation saute les contrôles de dossier (dû, plan, corbeille, état « Facturé »).
- **Références acheteur** (pour Chorus Pro, dépôt manuel en attendant 0.9.3) : `buyer_reference` (n° d'engagement /
  bon de commande) et `service_code` (code service), facultatifs, imprimés sur le PDF ; en-tête « Mandataire du
  groupement » si au moins une ligne est co-traitée.
- **Co-traitance** : une ligne libre peut porter `cotraitant_id` (fiche entreprise du co-traitant), affiché sur la
  ligne du PDF. Table `cotraitant_payouts` (ligne de facture, co-traitant, montant en centimes, date, référence,
  note) : le reversement ne modifie jamais la facture figée. Écran **Finance › À reverser** : lignes co-traitées des
  factures validées, montant, état de paiement de la facture par l'acheteur (`Balances`), reste à reverser ; action
  « Marquer reversé » (total ou partiel). Export CSV `;`.
- **Pas de double facturation** : sur les dossiers des stagiaires du marché, le financement de l'acheteur utilise un
  type de financement « facturé hors TMS » (`meta.external_billing`, déjà existant) ; documenté dans `finance.md`.
- **Droits et visibilité** : création / validation avec `finance.invoices.manage`, reversements avec
  `finance.payments.manage`. Une facture libre n'a pas de dossier : visible seulement des utilisateurs dont le
  périmètre de `finance.invoices.view` est « Tout ».
- **Écrans** : « Factures » › bouton « Nouvelle facture libre » ; liste avec filtre origine ; page facture identique
  (colonne co-traitant sur les lignes).
- **RGPD** : comme les autres pièces (conservées 10 ans) ; `notes` et `description` dans `$auditPersonal`.

## Hors périmètre (après la présentation)

Préremplissage depuis les heures réalisées (approche 2), dépôt Chorus Pro automatique / Factur-X (0.9.3), acomptes
propres à chaque financeur (Agefiph, CPAM, MSA), export Sage (en dernier).

## Laissé au constructeur

Noms exacts des classes, découpage des écrans, libellés secondaires.
