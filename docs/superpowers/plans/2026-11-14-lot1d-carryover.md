# Reports du lot 1c vers les lots suivants

Issus des revues de la branche `lot1c-pipeline` (pipeline, actions, relances, agenda, Outlook, durcissement). Les points bloquants ont été corrigés sur la branche ; restent ceux-ci, non bloquants, à traiter quand l'occasion se présente.

## 1. Outlook / Microsoft 365

- Déconnexion : si Microsoft reste indisponible plus de 24 h, le nettoyage (`RemoveOutlookEvents`) échoue avant d'effacer les jetons ; ajouter `failed()` → `dropTokens()`.
- Rafraîchissement refusé par un 4xx autre que `invalid_grant` : les copies sont comptées « conservées » puis les jetons effacés ; les évènements restent dans Outlook.
- Exécutions `calendar_link:` non relançables depuis l'écran d'administration.
- Renommer une fiche ou une opportunité ne met pas à jour Outlook avant la modification suivante de l'action.
- Reconnexion pendant le nettoyage : un évènement peut manquer jusqu'à la prochaine modification de son action (pas de doublon).
- Limitation Graph (429/5xx) : chaque relâche écrit une exécution « échouée » ; palier d'attente qui monte trop vite.
- Écouteur `QueueOutlookPush` : requêtes à chaque écriture d'action (mémoriser la configuration par requête).
- Logo des mails construit sur `APP_URL` (les liens d'action utilisent déjà l'adresse de l'organisme).

## 2. Opportunités et pipeline

- Fusion : `is_individual` forcé sans avertissement dans l'aperçu ; base légale non recalculée.
- Onglet personne : n'affiche pas les opportunités où la personne n'est qu'interlocutrice (`opportunity_contacts`).
- `stage-dialog` : date par défaut calculée dans le fuseau du navigateur (le serveur valide en Europe/Paris).
- Responsable DAS : lien vers un compte qu'il ne peut pas ouvrir (404) — afficher le nom sans lien.
- Kanban : une requête par colonne (requête fenêtrée si les étapes se multiplient).

## 3. Actions, « À faire », planning

- `ActivityRequest` trop gros (analyse des dates à extraire) ; requête opportunité dupliquée (`Activity` / `ActivityRecorder`).
- « À faire » : props contenant `report`/`location` (minimisation) ; cache du badge vidé avant le commit (`afterCommit`) ; limite silencieuse de 200 lignes par section ; récapitulatif `$tries = 1`.
- `HandleInertiaRequests` importe `Crm\Activities\TodoQuery` (passer par un crochet de props partagées).
- Tableau de bord « Prochains RDV » : n'affiche pas les RDV passés du jour non saisis (noter dans la doc).
- `WeekPlanning::types()` recharge les types à chaque appel.
- Tests manquants : compte rendu sur un particulier ; utilisateur sans `crm.create`.

## 4. Import, recherche, connecteurs

- Recherche : index trigramme inutilisable sous FORCE RLS (`textlike` non leakproof) ; parcours séquentiel < 100 ms sur 50 000 fiches. À revoir au-delà de ~500 000 fiches (opérateur leakproof créé par un superutilisateur).
- Watchdog d'import : faux positif si la file est saturée plus de 15 min ; remettre `resumes` à zéro après progression.
- SMTP épinglé sur la première adresse seulement (pas de bascule).
- Fichier importé lu jusqu'à 4 fois au dépôt.
- Flux iCal : plafond de 2 000 évènements sans journalisation.

## 5. Architecture et tests

- Autres commandes `ForEachTenant` : code retour en cas d'échec d'un organisme.
- Test d'architecture : règle « Crm n'utilise que les adaptateurs publics de Connectors » non vérifiée.
- `personalTraces()` défini dans un autre fichier de test : `ActivityMergeGdprTest` / `OpportunityMergeGdprTest` échouent lancés seuls.
- Nouvelles tables : préférer `timestampsTz` (`opportunities` utilise `timestamps()`).
- e2e : branche du dimanche de `pipeline.spec.ts` jamais exécutée en conditions réelles.
