# Guide d'administration

Ce guide s'adresse à l'**administrateur de l'organisme** (rôle « Administrateur de l'organisme »), qui dispose de toutes les permissions d'administration. Chaque entrée du menu **Administration** n'apparaît que si votre rôle porte la permission correspondante.

| Écran | Chemin | Permission |
|---|---|---|
| Sociétés | `/admin/companies` | `companies.manage` |
| Utilisateurs | `/admin/users` | `users.manage` |
| Référentiels | `/admin/references` | `references.manage` |
| Connecteurs | `/admin/connectors` | `connectors.manage` |
| Journal d'audit | `/admin/audit` | `audit.view` |
| Sécurité | `/admin/security` | `settings.manage` |

Par défaut, l'administrateur détient toutes les permissions ; la direction a seulement `audit.view` et `exports.run` ; les autres rôles n'ont aucune permission d'administration.

## 1. Première connexion

1. L'installation (`php artisan tms:install`, voir `install.md`) affiche un **lien d'activation** et l'envoie par e-mail si la messagerie est configurée. Il est à **usage unique** et valable **24 heures**. S'il a expiré, un autre administrateur peut renvoyer une invitation ; sinon l'exploitant en émet un nouveau en ligne de commande (voir *Récupérer l'accès administrateur*).
2. Ouvrez le lien : choisissez un mot de passe d'au moins **12 caractères** (les mots de passe connus pour avoir fuité sont refusés).
3. Connectez-vous avec votre adresse e-mail et ce mot de passe. La double authentification vous est alors imposée (voir ci-dessous).
4. Après la connexion, vous arrivez sur **Accueil**. Le menu de gauche liste les écrans d'administration.

Aucun compte par défaut n'existe : chaque compte est créé par invitation.

## 2. Double authentification (2FA)

La 2FA par code à usage unique (TOTP, 6 chiffres, renouvelé toutes les 30 secondes) est **obligatoire** pour les rôles *Administrateur de l'organisme* et *Direction*, et pour tous les comptes si l'option « Imposer la double authentification » est cochée (voir *Sécurité*).

Activation, à la première connexion :

1. Installez une application d'authentification (Microsoft Authenticator, Google Authenticator, FreeOTP…).
2. Scannez le QR code, ou saisissez la clé affichée sous le QR code.
3. Saisissez le code à 6 chiffres affiché par l'application, puis **Activer**.
4. **Conservez les 8 codes de récupération** affichés : chacun permet une seule connexion si vous perdez votre application. Ils ne sont plus jamais réaffichés ; vous pouvez en générer de nouveaux (l'opération demande votre mot de passe et invalide les anciens).

Tant que la 2FA n'est pas activée, toutes les pages (sauf la page d'activation et la déconnexion) redirigent vers la page d'activation. La 2FA ne peut pas être désactivée par un compte pour lequel elle est obligatoire.

## 3. Sociétés

Une **société** est une entité juridique émettrice de vos devis et factures. Un organisme peut en avoir plusieurs. À l'installation, une société par défaut est créée au nom de l'organisme (forme « Autre », SIRET à compléter).

Pour chaque société : raison sociale, forme juridique, capital (montant et « capital variable »), SIRET (14 chiffres, contrôle de la clé), ville du RCS, régime de TVA (assujetti / exonéré), n° de TVA intracommunautaire, n° de déclaration d'activité (NDA), adresse, e-mail, téléphone.

- **SCOP / SCIC** : la forme sous-jacente (SARL, SA ou SAS) est obligatoire.
- Pour SARL, SAS, SASU, SA, EURL, SCOP et SCIC, le capital (montant strictement positif) est obligatoire, sauf si « Capital variable » est coché.
- Les **mentions légales** se construisent automatiquement et s'affichent en aperçu pendant la saisie, par exemple : « CAP FORMATION – SCOP SARL à capital variable – RCS Lyon 732 829 320 – SIRET 732 829 320 00074 ». Une société exonérée de TVA porte en plus la mention d'exonération.
- Le SIRET est obligatoire à l'enregistrement.
- Exactement **une société par défaut** par organisme ; pour en changer, cochez « Société par défaut » sur une autre société.

## 4. Utilisateurs et rôles

*Utilisateurs* liste les comptes (statut : actif, en attente d'activation, désactivé, dernière connexion) et les **invitations en attente**.

Inviter : **Inviter un utilisateur** → nom, adresse e-mail, rôle → **Envoyer l'invitation**. Un e-mail contenant le lien d'activation (usage unique, 24 h) est envoyé par le connecteur SMTP de l'organisme (voir *Connecteurs*), à défaut par la messagerie de l'instance ; l'invitation apparaît dans « Invitations en attente » avec sa date d'expiration. Si l'e-mail ne peut pas partir (serveur refusé, erreur SMTP) ou si aucune messagerie n'est configurée, l'invitation est conservée et le lien d'activation s'affiche une seule fois à l'écran, avec un bouton « Copier le lien » : transmettez-le vous-même à la personne invitée (journal : « Échec d'envoi de l'invitation », avec la raison). Il est impossible d'inviter une adresse qui a déjà un compte actif dans l'organisme. L'envoi est consigné dans le journal d'audit (« Invitation envoyée »).

Rôles disponibles : Administrateur de l'organisme, Direction, Commercial, Chargé de relations, Assistant, Responsable DAS. Les changements de rôle sont consignés (« Changement de rôle »).

## 5. Référentiels

Les listes de valeurs (DAS, étapes du pipeline, types et résultats d'action, origines de contact, motifs de perte, types d'établissement, secteurs, tailles d'entreprise, priorités, modes de financement, situations professionnelles, formes juridiques des organisations clientes, rôles dans la décision) sont **propres à votre organisme**. Pour chaque liste vous pouvez ajouter, renommer, réordonner, désactiver / réactiver et supprimer des valeurs.

- Les étapes du pipeline portent une **probabilité de closing** (0 à 100) ; les étapes « gagné » et « perdu » ont une probabilité fixe.
- Une valeur **système** ne peut être ni désactivée ni supprimée.
- Une valeur **déjà utilisée** ne peut pas être supprimée : désactivez-la, elle n'est plus proposée mais l'historique reste lisible.
- Les libellés sont uniques au sein d'une liste.

## 6. Connecteurs (messagerie SMTP)

*Connecteurs* regroupe les liens avec les logiciels externes. Le connecteur **SMTP** fait envoyer les e-mails (devis, invitations) par votre propre serveur de messagerie.

1. Ouvrez le connecteur **SMTP**.
2. Renseignez le serveur (hôte), le port, le chiffrement (TLS, SSL ou aucun), l'adresse et le nom d'expédition, puis l'identifiant et le mot de passe.
3. **Tester la connexion** : le résultat s'affiche sans bloquer l'écran (10 secondes maximum).
4. Cochez **Activé** et enregistrez.

Un réglage peut s'appliquer à tout l'organisme ou à une société précise (la société prend alors le pas). Les identifiants sont stockés **chiffrés** et ne sont jamais réaffichés : laisser un champ vide conserve la valeur actuelle ; l'effacement est une action explicite. Les adresses locales ou de réseau interne sont refusées selon la politique de l'installation (`TMS_ALLOW_INTERNAL_SMTP_HOSTS`, `TMS_ALLOW_PRIVATE_SMTP_HOSTS`). Chaque envoi ou test est tracé dans le journal d'exécution du connecteur, et une exécution en échec peut être rejouée. Pour ajouter un autre connecteur, voir `connectors.md`.

## 7. Journal d'audit

Historique **en lecture seule et non modifiable** (même par l'administrateur ou en base : un déclencheur PostgreSQL refuse toute modification ou suppression) des évènements de l'organisme : création, modification, suppression et restauration des fiches (avec anciennes et nouvelles valeurs, les secrets étant remplacés par « [masqué] »), connexions et échecs, déconnexions, activation / désactivation de la 2FA, changements de rôle, invitations, changements des paramètres de sécurité, exports.

Filtres : utilisateur, évènement, type d'objet, période (du / au). Chaque ligne indique la date (heure de Paris), l'utilisateur, l'évènement, l'objet concerné, le détail et l'adresse IP. La liste est paginée par 50.

## 8. Sécurité

*Sécurité* (permission `settings.manage`) règle deux paramètres, propres à l'organisme :

| Paramètre | Valeurs | Défaut |
|---|---|---|
| Imposer la double authentification à tous les comptes | oui / non | non |
| Déconnexion après inactivité | 15 à 480 minutes | 120 |

- **Double authentification imposée** : chaque compte non encore enrôlé est redirigé vers la page d'activation à sa prochaine action.
- **Inactivité** : une session sans aucune requête pendant la durée choisie est fermée ; l'utilisateur est renvoyé vers la page de connexion avec le message « Votre session a expiré après une période d'inactivité ». Le contrôle ne concerne ni la page de santé `/health`, ni les visiteurs non connectés. La nouvelle durée s'applique dès la requête suivante.
- La durée de vie de session du framework (`SESSION_LIFETIME`, 480 minutes par défaut) est un plafond d'inactivité lui aussi : elle doit rester **au moins égale** à la plus grande durée que vous réglez ici (480), sinon la session expire plus tôt que prévu. C'est ce réglage par organisme qui fait foi en dessous.
- Il n'y a pas de « Se souvenir de moi » : un cookie de reconnexion survivrait à l'expiration. Les anciens cookies de ce type sont refusés et la personne est renvoyée vers la connexion.
- Chaque modification est consignée dans le journal d'audit (« Paramètres de sécurité modifiés », valeurs avant / après).

Autres garanties (non paramétrables) : mots de passe Argon2id d'au moins 12 caractères, limitation du nombre d'essais de code 2FA, codes de récupération à usage unique, journal d'audit non modifiable, isolation stricte des données entre organismes.

## 9. Récupérer l'accès administrateur

Deux commandes d'exploitation, lancées sur le serveur, rétablissent l'accès sans jamais afficher ni fixer de mot de passe. Elles agissent dans un seul organisme (`--tenant=<identifiant>`, par défaut celui de l'installation) et sont consignées dans le journal d'audit.

```bash
cd /opt/tms
# Nouveau lien d'activation (24 h, usage unique) pour un compte qui n'a jamais choisi son mot de passe :
docker compose -f compose.prod.yaml --env-file .env exec app php artisan tms:user:activation-link admin@exemple.fr
# … ou invitation d'une nouvelle adresse, avec son rôle :
docker compose -f compose.prod.yaml --env-file .env exec app php artisan tms:user:activation-link secours@exemple.fr --role=admin_organisme --name="Admin de secours"
# Téléphone et codes de récupération perdus : réinitialise la 2FA et ferme toutes les sessions du compte.
docker compose -f compose.prod.yaml --env-file .env exec app php artisan tms:user:reset-2fa admin@exemple.fr
```

- `tms:user:activation-link` affiche le lien à transmettre au seul titulaire de l'adresse ; tout lien précédent est annulé. Un compte déjà activé est refusé : utilisez « Mot de passe oublié » sur la page de connexion. Journal : « Lien d'activation réémis » (`users.activation_reissued`).
- `tms:user:reset-2fa` efface le secret et les codes de récupération ; un administrateur ou un membre de la direction devra enrôler un nouvel appareil à sa prochaine connexion. Journal : `auth.2fa_reset_by_operator`.
- Rôles possibles pour `--role` : `admin_organisme`, `direction`, `commercial`, `charge_relations`, `assistant`, `responsable_das`.
