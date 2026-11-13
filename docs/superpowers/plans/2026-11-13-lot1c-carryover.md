# Reports du lot 1b vers le lot suivant (1c)

Issus de la revue finale de la branche `lot1b-fiches` (fiches, import, RGPD). Les points bloquants ont été corrigés sur la branche ; restent ceux-ci, à traiter au lot suivant.

## 1. Données et cohérence

- Curseur de la timeline sur l'heure seule : passer à un curseur composite `(at, type, id)` (deux évènements à la même microseconde peuvent être sautés ou répétés).
- `UndoImport::hasForeignData` : remplacer la liste en dur (rattachements, notes, pièces jointes, consentements) par un registre où chaque module déclare ses données liées / son veto (opportunités, devis, sessions du lot 1c).
- `last_contact_at` : recalcul après annulation d'un import ; refuser ou signaler les dates futures importées.
- Imports bloqués : surveillance automatique (watchdog) des lots sans avancement et reprise depuis `cursor_line`, au lieu du seul « Marquer comme échoué » manuel.
- Une erreur de base non liée à l'unicité fait échouer tout l'import : isoler la ligne fautive (savepoint par ligne ou par paquet) et continuer.
- Export : ni `source_system` / `source_id`, ni l'historique des bases légales et consentements.
- `original_name` d'un import peut contenir des noms de personnes : le masquer ou le purger avec le fichier.

## 2. Visibilité Responsable DAS

- Anciens interlocuteurs et entreprises en corbeille dans la règle de visibilité DAS (cas limites non couverts).
- Entreprise en corbeille lisible par son URL directe : aligner sur la politique (corbeille réservée à `crm.delete`).
- `user_das` conservé quand l'utilisateur change de rôle : purger ou ignorer hors rôle Responsable DAS.

## 3. Recherche

- Paramètre `q` sans longueur maximale côté serveur ; paramètres passés en tableau (`q[]=`) → erreur 500 : valider en chaîne bornée partout.
- Test de volume de la recherche sous RLS (~50 000 fiches) : temps de réponse et usage des index trigrammes.

## 4. Architecture et administration

- Couplage Core → Crm dans `InvitationController` (DAS suivis) : passer par un évènement ou un service public du module Crm.
- `permissions:sync` : remonter clairement l'échec par organisme (code retour, alerte), pas seulement le journal.
- Autres chemins de retrait du dernier administrateur (suspension, suppression, changement de rôle hors écran Utilisateurs) : même garde verrouillée.

## 5. Sécurité

- Connecteurs HTTP : épingler l'IP résolue pour la requête (protection contre le DNS rebinding entre contrôle et appel).
- Import : vérifier le type réel du fichier (MIME sniffing) en plus de l'extension.
- Pièces jointes des entreprises en corbeille jamais purgées : les inclure dans la purge de rétention.
