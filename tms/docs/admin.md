# Guide d'administration

Ce guide s'adresse à l'**administrateur de l'organisme** (rôle « Administrateur de l'organisme »), qui dispose de toutes les permissions d'administration. Chaque entrée du menu **Administration** n'apparaît que si votre rôle porte la permission correspondante.

| Écran | Chemin | Permission |
|---|---|---|
| Sociétés | `/admin/companies` | `companies.manage` |
| Rôles et droits | `/admin/roles` | `roles.manage` |
| Modèles d'e-mails | `/admin/mail-templates` | `settings.manage` |
| Utilisateurs | `/admin/users` | `users.manage` |
| Référentiels | `/admin/references` | `references.manage` |
| Connecteurs | `/admin/connectors` | `connectors.manage` |
| Journal d'audit | `/admin/audit` | `audit.view` |
| Sécurité | `/admin/security` | `settings.manage` |

Par défaut, l'administrateur détient toutes les permissions d'administration ; la direction a seulement `audit.view` et `exports.run` ; les autres rôles n'ont aucune permission d'administration. Les droits propres au CRM (fiches, import, RGPD, devis) et au catalogue font l'objet de la section 10 ci-dessous.

## 1. Première connexion

1. L'installation (`php artisan tms:install`, voir `install.md`) affiche un **lien d'activation** et l'envoie par e-mail si la messagerie est configurée. Il est à **usage unique** et valable **24 heures**. S'il a expiré, un autre administrateur peut renvoyer une invitation ; sinon l'exploitant en émet un nouveau en ligne de commande (voir *Récupérer l'accès administrateur*).
2. Ouvrez le lien : choisissez un mot de passe d'au moins **12 caractères** (les mots de passe connus pour avoir fuité sont refusés).
3. Connectez-vous avec votre adresse e-mail et ce mot de passe. La double authentification vous est alors imposée (voir ci-dessous).
4. Après la connexion, vous arrivez sur **Accueil**. Le menu de gauche liste les écrans d'administration.

Aucun compte par défaut n'existe : chaque compte est créé par invitation.

## 2. Double authentification (2FA)

La 2FA par code à usage unique (TOTP, 6 chiffres, renouvelé toutes les 30 secondes) est **obligatoire** pour les rôles *Administrateur de l'organisme* et *Direction* (toujours), pour les autres rôles dont l'interrupteur « Double authentification obligatoire » est actif (§ 10), et pour tous les comptes si l'option « Imposer la double authentification » est cochée (voir *Sécurité*).

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
- **Identité dans l'application** : la barre latérale (et l'en-tête sur téléphone) affiche le **nom** et le **logo** de la société par défaut, et le titre de l'onglet du navigateur se termine par ce nom. Sans logo, l'icône générique s'affiche ; sans société par défaut, le nom de l'application. Un changement de nom ou de logo se voit dès la page suivante. Les pages de connexion n'affichent ni ce nom ni ce logo (rien n'est révélé avant la connexion). Le logo est servi aux seuls utilisateurs connectés (`/branding/logo`, mis en cache une heure par le navigateur).

**Section « Devis »** de la société :

- **Logo** : PNG ou JPEG, 1 Mo au maximum (un SVG ou un fichier renommé est refusé) ; enregistrez d'abord la société, puis ajoutez-le. Il est intégré au PDF des devis et, pour la société par défaut, affiché dans la barre latérale. **Supprimer le logo** le retire. Chaque changement est journalisé (nom, taille et empreinte du fichier, jamais son contenu).
- **Préfixe de numérotation** : lettres majuscules et chiffres, 10 caractères au plus (`DEV` par défaut). Les numéros sont `<préfixe>-<année>-<numéro sur 4 chiffres>` (`CF-2026-0001`), sans trou, avec un compteur par préfixe et par année civile (heure de Paris) qui repart à 1 le 1er janvier. **Chaque société a son propre préfixe** dans l'organisme : un préfixe déjà pris est refusé (« Ce préfixe est déjà celui d'une autre société »). Pour une nouvelle société, remplacez donc le `DEV` proposé, déjà porté par la société créée à l'installation. Changer de préfixe ne renumérote pas les devis existants.
- **Durée de validité (jours)** : 1 à 365, 30 par défaut ; date « Valable jusqu'au » proposée sur un nouveau devis.
- **Conditions générales** et **Pied de page** (5 000 caractères chacun) : imprimés sur chaque devis, sous les lignes et en bas du document.

**Section « Factures »** de la société (0.9) :

- **Préfixe des factures** (`F` par défaut) et **préfixe des avoirs** (`AV` par défaut) : lettres majuscules et chiffres, 10 caractères au plus, **différents l'un de l'autre**. Les numéros sont `<préfixe>-<année>-<numéro sur 5 chiffres>` (`F-2026-00001`), sans trou, avec un compteur par société, par type (facture, avoir) et par année civile (heure de Paris) qui repart à 1 le 1er janvier. Changer de préfixe ne renumérote pas les pièces existantes. Un préfixe qui a déjà numéroté l'autre type de pièce de la société est refusé (« Ce préfixe a déjà servi pour un autre type de pièce. ») : les factures et les avoirs ne se croisent jamais.
- **Délai de paiement** : 0 à 60 jours (30 par défaut) ; l'échéance d'une facture = date d'émission + ce délai.
- **Pied de facture** (2 000 caractères) : texte libre imprimé sur chaque facture (RIB, précisions de règlement…). Les pénalités de retard et l'indemnité de 40 € sont ajoutées automatiquement aux factures destinées à un professionnel.
- Le **régime de TVA** de la société commande aussi les factures : exonérée, toutes les lignes sont à 0 % avec la mention d'exonération ; assujettie, taux par ligne (20 % proposé). Une facture validée garde le régime de sa validation.

**Régime de TVA : à décider à l'installation.** Le **régime de TVA** de la société (« Assujetti à la TVA » ou « Exonéré de TVA ») commande les devis : exonérée, toutes les lignes sont à 0 % et le devis porte la mention « TVA non applicable, art. 261-4-4° a du CGI » ; assujettie, chaque ligne a son taux (20 %, 10 %, 5,5 %, 2,1 % ou 0 %, 20 % proposé). Un organisme de formation peut relever de l'exonération de l'article 261-4-4° a du CGI ou être assujetti : **pour CAP FORMATION, faire confirmer le régime par l'expert-comptable et le régler sur chaque société avant le premier devis.** Un brouillon suit le régime actuel de sa société ; un devis envoyé garde celui de son envoi (PDF archivé).

## 4. Utilisateurs et rôles

*Utilisateurs* liste les comptes (statut : actif, en attente d'activation, désactivé, dernière connexion) et les **invitations en attente**.

Inviter : **Inviter un utilisateur** → nom, adresse e-mail, rôle → **Envoyer l'invitation**. Un e-mail contenant le lien d'activation (usage unique, 24 h) est envoyé par le connecteur SMTP de l'organisme (voir *Connecteurs*), à défaut par la messagerie de l'instance ; l'invitation apparaît dans « Invitations en attente » avec sa date d'expiration. Si l'e-mail ne peut pas partir (serveur refusé, erreur SMTP) ou si aucune messagerie n'est configurée, l'invitation est conservée et le lien d'activation s'affiche une seule fois à l'écran, avec un bouton « Copier le lien » : transmettez-le vous-même à la personne invitée (journal : « Échec d'envoi de l'invitation », avec la raison). Il est impossible d'inviter une adresse qui a déjà un compte actif dans l'organisme. L'envoi est consigné dans le journal d'audit (« Invitation envoyée »).

Rôles disponibles : les sept rôles système (Administrateur de l'organisme, Direction, Commercial, Chargé de relations, Assistant, Responsable DAS, Formateur) et les rôles personnalisés de l'organisme (voir § 10). Les changements de rôle sont consignés (« Changement de rôle »).

Autres actions de la liste :

- **Changer le rôle** : la personne est déconnectée de ses autres sessions pour que ses nouveaux droits s'appliquent aussitôt.
- **Désactiver** / **Réactiver** un compte (sessions révoquées à la désactivation). Vous ne pouvez pas désactiver votre propre compte, et **il doit toujours rester au moins un administrateur actif** : l'application refuse sinon, avec un message.
- **Renvoyer** une invitation (le lien précédent est annulé, nouveau délai de 24 h) ou la **Révoquer**.
- **DAS suivis** : choisissez les DAS d'un utilisateur dont un droit a le périmètre « Ses DAS » (par défaut le *Responsable DAS*). Il ne voit alors dans le CRM que les entreprises rattachées à ces DAS, et les personnes liées à ces entreprises ou, sans entreprise, rattachées à l'un de ces DAS. Sans DAS suivi, il ne voit aucune fiche pour ce droit. Les droits à un autre périmètre ne dépendent pas des DAS suivis. Un DAS utilisé comme DAS suivi ne peut pas être supprimé des référentiels (désactivez-le).

## 5. Référentiels

Les listes de valeurs (DAS, étapes du pipeline, types et résultats d'action, origines de contact, motifs de perte, types d'établissement, secteurs, tailles d'entreprise, priorités, modes de financement, situations professionnelles, formes juridiques des organisations clientes, rôles dans la décision ; pour les dossiers et sessions : types de financeur, motifs d'abandon, motifs d'annulation, catégories de stagiaires (BPF), niveaux de diplôme, modalités de formation — voir § 15) sont **propres à votre organisme**. Pour chaque liste vous pouvez ajouter, renommer, réordonner, désactiver / réactiver et supprimer des valeurs.

- Les étapes du pipeline portent une **probabilité de closing** (0 à 100), reprise par une opportunité à chaque changement d'étape ; les étapes « gagné » et « perdu » ont une probabilité fixe (100 / 0) et un **motif de perte** est exigé pour « perdu » (liste **Motifs de perte**). Une étape désactivée disparaît des colonnes du pipeline ; les opportunités qui y sont gardent leur étape.
- Les **types d'action** ont un champ **Planning commercial** : *Rendez-vous* (planning de la semaine, **Compte rendu de RDV**, accueil « Prochains rendez-vous », agenda iCal / Outlook) ou *Appel* (planning et agenda, s'il a une échéance) ; vide, le type n'y apparaît pas. Chez CAP FORMATION : « Prise de rendez-vous » et « Rendez-vous physique » (rendez-vous), « Appel » (appel). Le réglage s'applique aussitôt, y compris aux actions existantes.
- **Étape d'un devis envoyé** (étapes du pipeline) : une seule étape ouverte porte cette case (badge « Étape du devis envoyé ») ; à l'envoi d'un devis, l'opportunité y avance si elle est à une étape antérieure (jamais en arrière, jamais depuis une affaire close). Cocher une autre étape retire la case de la précédente ; une étape gagnée ou perdue ne peut pas la porter ; sans étape cochée, l'envoi ne déplace pas l'opportunité. Chez CAP FORMATION : « Proposition » (posée à l'installation et par la mise à jour, sans écraser un choix déjà fait).
- Une valeur **système** ne peut être ni désactivée ni supprimée.
- Une valeur **déjà utilisée** ne peut pas être supprimée : désactivez-la, elle n'est plus proposée mais l'historique reste lisible.
- Les libellés sont uniques au sein d'une liste.

## 6. Connecteurs (messagerie SMTP, Microsoft 365)

*Connecteurs* regroupe les liens avec les logiciels externes. Le connecteur **SMTP** fait envoyer les e-mails (devis, invitations) par votre propre serveur de messagerie.

1. Ouvrez le connecteur **SMTP**.
2. Renseignez le serveur (hôte), le port, le chiffrement (TLS, SSL ou aucun), l'adresse et le nom d'expédition, puis l'identifiant et le mot de passe.
3. **Tester la connexion** : le résultat s'affiche sans bloquer l'écran (10 secondes maximum).
4. Cochez **Activé** et enregistrez.

Un réglage peut s'appliquer à tout l'organisme ou à une société précise (la société prend alors le pas). Les identifiants sont stockés **chiffrés** et ne sont jamais réaffichés : laisser un champ vide conserve la valeur actuelle ; l'effacement est une action explicite. Les adresses locales ou de réseau interne sont refusées selon la politique de l'installation (`TMS_ALLOW_INTERNAL_SMTP_HOSTS`, `TMS_ALLOW_PRIVATE_SMTP_HOSTS` ; pour l'intelligence artificielle, `TMS_ALLOW_LOCAL_AI`, voir `connectors.md`). Chaque envoi ou test est tracé dans le journal d'exécution du connecteur, et une exécution en échec peut être rejouée. Pour ajouter un autre connecteur, voir `connectors.md`.

Le connecteur **Microsoft 365 (envoi des e-mails)** fait partir tous les e-mails du TMS (devis, plaquettes, invitations) depuis **une boîte Microsoft 365** de l'organisme, au lieu d'un serveur SMTP. Il se règle pour tout l'organisme (pas par société), demande une application déclarée dans Azure avec la permission **d'application** `Mail.Send` limitée à cette boîte, et **Tester la connexion** envoie un e-mail de test à votre adresse. Il prime sur le SMTP de l'organisme (non sur celui d'une société). Procédure pas à pas : `connectors.md`, section « Connecteur Microsoft 365 (envoi des e-mails) ».

Le connecteur **Microsoft 365** envoie les rendez-vous et appels planifiés des utilisateurs qui ont **connecté Outlook** (**Paramètres › Mon agenda**) dans leur agenda Outlook. Il demande une application déclarée dans Azure (identifiant de l'annuaire, identifiant client, secret) : la procédure pas à pas est dans `connectors.md`, section « Connecteur Microsoft 365 ». Sans ce connecteur, chacun peut toujours s'abonner à son **lien iCal** privé (même écran), qui ne demande aucun réglage.

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

### Modèles d'e-mails

Administration › **Modèles d'e-mails** (droit `settings.manage`, celui de l'écran Sécurité : l'administrateur par défaut). Six e-mails sont modifiables (objet de 200 caractères au plus, texte brut de 10 000 caractères au plus) :

- **Envoi d'un devis** et **Relance d'un devis** : proposés dans la fenêtre d'envoi, toujours modifiables au cas par cas avant l'envoi ; le PDF est joint par le système.
- **Envoi d'une plaquette** (modèle `brochure`) : proposé dans la fenêtre « Envoyer une plaquette » (variables `{destinataire}`, `{commercial}`, `{organisme}`), toujours modifiable avant l'envoi ; les plaquettes choisies sont jointes par le système (20 Mo au plus en tout), le message part en texte simple. Droits : envoyer demande `crm.activities.update`, télécharger une version depuis l'historique `catalogue.view`.
- **Invitation d'un utilisateur** : votre texte (la première ligne sert de formule d'appel) ; le bouton d'activation, la durée de validité du lien et la mention « Si vous n'attendiez pas cette invitation » sont toujours ajoutés.
- **Récapitulatif quotidien des relances** : le texte avant `{actions}` sert d'introduction, celui après de signature ; la liste des actions et le lien vers « À faire » restent générés. `{actions}` est obligatoire.
- **Action confiée** (modèle `activity_assigned`) : envoyé à l'utilisateur à qui une action à faire est confiée (« Attribuer à » à la création, « Confier à… » ou « Modifier »), jamais à celui qui la confie. Variables `{auteur}`, `{type}`, `{objet}`, `{echeance}` (« 20/11/2026 à 09:00 », heure de Paris), `{motif}` (« — » s'il est vide), `{message}` (message facultatif de « Confier à… », vide sinon) et `{lien}` (obligatoire : ouvre l'action dans « À faire »). Le compte rendu et la suite à donner n'y figurent jamais.

Chaque modèle liste ses **variables** (`{numero_devis}`, `{societe}`, `{destinataire}`, `{montant_ttc}`, `{date_validite}`, `{commercial}`, `{organisme}`, `{prenom_utilisateur}`…) : un clic les insère à l'endroit du curseur. Une variable inconnue ou une accolade mal formée est refusée à l'enregistrement. Rien n'est interprété comme du code : chaque `{variable}` est simplement remplacée par sa valeur, en texte (échappée dans les e-mails HTML). **Aperçu** montre le résultat avec des données d'exemple ; **Envoyer un e-mail de test** l'envoie à votre adresse par la messagerie de l'organisme (objet préfixé « [Test] », 10 par minute au plus) ; **Rétablir le modèle par défaut** reprend le texte du TMS. Les e-mails de sécurité (réinitialisation du mot de passe, double authentification, alertes d'exploitation) sont affichés en lecture seule (« modèle système »). Les modèles sont propres à chaque organisme et journalisés.

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

## 10. Rôles et droits

### Les droits, par domaine

Chaque domaine du CRM a ses droits **Consulter**, **Créer et modifier** (créer est inclus dans « modifier ») et **Supprimer**. Un rôle peut donc, par exemple, voir les devis sans pouvoir les modifier. L'écran regroupe les droits en huit thèmes : *Entreprises et personnes*, *Opportunités*, *Devis*, *Actions et agenda*, *Chiffre d'affaires*, *Catalogue*, *Imports, exports et RGPD*, *Administration*.

| Droit | Ce qu'il permet |
|---|---|
| `crm.fiches.view` / `.update` / `.delete` | Voir les entreprises et personnes (rattachements, notes, pièces jointes) ; les créer et les modifier ; les mettre à la corbeille et les restaurer |
| `crm.merge` | Fusionner deux fiches en double |
| `crm.opportunities.view` / `.update` / `.delete` | Voir les opportunités et le pipeline ; les créer, les modifier, changer leur étape ; les mettre à la corbeille |
| `crm.quotes.view` / `.update` / `.delete` | Voir les devis et leur PDF ; créer, modifier, envoyer, relancer, enregistrer la réponse du client, **Nouvelle version** ; supprimer un brouillon |
| `crm.activities.view` / `.update` / `.delete` | Voir les actions, **À faire**, **Planning**, **Mon agenda** (iCal, Outlook) ; les créer, les modifier, les terminer, les reporter, saisir un compte rendu de RDV ; les supprimer |
| `crm.activities.team` | **À faire › Équipe** et planning d'un collègue (remplace l'ancien test « administrateur ou direction ») |
| `crm.revenue.view` | Consulter le chiffre d'affaires par DAS (à périmètre) |
| `crm.revenue.manage` | Fixer les objectifs de chiffre d'affaires |
| `crm.import`, `crm.export`, `crm.gdpr` | Imports ; exports Excel des listes ; écran RGPD, export des données d'une personne, anonymisation, durée de conservation |
| `catalogue.view` | Menu **Catalogue** : voir les formations (tout le catalogue, quel que soit le DAS) ; voir et télécharger les plaquettes |
| `catalogue.manage` (« Modifier le catalogue ») | Créer, modifier, désactiver une formation ; importer un catalogue (avec `crm.import`) ; déposer et modifier des plaquettes |
| `catalogue.delete` | Supprimer une formation ; mettre une plaquette à la corbeille |

Les dossiers et sessions (lot 2a) ont deux domaines de plus, eux aussi à périmètre, dans les thèmes *Dossiers* et *Sessions et inscriptions* :

| Droit | Ce qu'il permet |
|---|---|
| `sessions.dossiers.view` / `.update` / `.delete` | Voir les dossiers de formation, leurs lignes, leur prix et leurs financements ; créer un dossier (depuis un devis accepté ou à la main), modifier ses lignes et ses financements, changer son état ; le mettre à la corbeille et le restaurer |
| `sessions.sessions.view` / `.update` / `.delete` | Voir les sessions, leur calendrier et leurs inscrits ; planifier et modifier les sessions, inscrire et désinscrire des stagiaires, enregistrer un abandon ; mettre une session à la corbeille |

Inscrire un stagiaire depuis un dossier demande les deux : modifier la session et consulter le dossier. « Ses fiches » = responsable ou créateur du dossier ou de la session ; « Ses DAS » = DAS du dossier, DAS de la formation de la session.

Les factures (0.9) ajoutent le thème *Factures*, à périmètre (celui du dossier : « Ses fiches », « Ses DAS », tout l'organisme) :

| Droit | Ce qu'il permet |
|---|---|
| `finance.invoices.view` (« Consulter les factures ») | Menu **Finance** : À facturer, Factures ; voir factures, avoirs, PDF, export CSV ; bloc Facturation d'un dossier |
| `finance.invoices.manage` (« Préparer les factures ») | Régler le rythme d'un dossier ; préparer et modifier les brouillons ; créer un brouillon d'avoir ; envoyer et renvoyer |
| `finance.invoices.validate` (« Valider les factures ») | Valider une facture ou un avoir ; **Vérifier l'intégrité** |

**Défauts** (accordés une fois par `tms:permissions:sync` lors de la mise à jour vers la 0.9.0) : administrateur de l'organisme et direction, les trois droits ; commercial, chargé de relations et assistant, *Consulter* et *Préparer* ; Responsable DAS et Formateur, aucun. Seuls l'administrateur et la direction valident par défaut. Le menu **Finance › Réglages de facturation** exige `settings.manage`.

Le planning (0.8.0) ajoute les thèmes *Planning et présences* (droits à périmètre) et *Formateurs et salles* (organisme entier) :

| Droit | Ce qu'il permet |
|---|---|
| `sessions.seances.view` / `.manage` | Voir le planning des séances, leurs formateurs et leurs salles ; créer, déplacer, annuler, supprimer des séances, générer les séances d'un module |
| `sessions.attendance.manage` | Saisir les présences des séances et les heures réalisées par mois |
| `sessions.attendance.close` | Clôturer un mois de présences d'une session et le rouvrir (motif obligatoire) |
| `sessions.trainers.manage` | Menu **Formateurs** (droit à périmètre : « Ses fiches » = formateurs créés par l'utilisateur ou liés à son compte ; « Ses DAS » = formateurs d'un DAS suivi **ou sans DAS** ; tout l'organisme ; Responsable de DAS : Ses DAS par défaut) : déclarer des formateurs, leur statut, leurs DAS, leurs indisponibilités, leur compte ; bloc « Formateur » des fiches Personne |
| `sessions.rooms.manage` | Administration › **Salles et fermetures** |

Les séances et présences suivent le périmètre de leur session (« Ses fiches », « Ses DAS »). « Consulter les séances » et « Saisir les présences » proposent aussi le périmètre **Ses séances** : les séances dont l'utilisateur est le formateur (fiche formateur liée à son compte), et les sessions qui les portent, en lecture. Ce périmètre n'est proposé, ni appliqué, pour aucun autre droit.

Les droits d'**administration** (`users.manage`, `roles.manage`, `companies.manage`, `references.manage`, `connectors.manage`, `audit.view`, `settings.manage`, `exports.run`) sont inchangés.

### Périmètres

Les droits **Consulter**, **Créer et modifier**, **Supprimer** des quatre domaines, ainsi que « Consulter le chiffre d'affaires », ont un **périmètre**, réglé par une liste à côté de la case :

- **Ses fiches** : uniquement ce dont l'utilisateur est le **responsable** ou le **créateur** (fiche, opportunité ou action). Un devis est « à soi » quand son opportunité l'est ou qu'il en est le créateur. Une fiche sans responsable créée par un collègue n'est pas visible ; celle qu'il vient de créer lui-même l'est.
- **Ses DAS** : ce qui touche les DAS qu'il suit (**DAS suivis** de la page Utilisateurs). Sans DAS suivi, rien n'est visible. Pour les actions, « Ses DAS » couvre aussi celles dont il est le **responsable** (consulter et modifier, jamais supprimer), même sur une fiche hors de ses DAS.
- **Tout l'organisme** : tout.
- **Ses séances** (« Consulter les séances » et « Saisir les présences » seulement) : les séances dont il est le formateur.

Le périmètre d'un droit sans réglage enregistré vaut « Tout l'organisme ». Les périmètres de consultation et de modification sont indépendants : avec « Consulter : Ses DAS » et « Modifier : Tout l'organisme », une fiche hors de ses DAS reste introuvable (404), même par son adresse directe. Créer un devis (ou une nouvelle version) exige de voir son opportunité ; modifier ou supprimer un devis existant suit les périmètres des droits « Devis » eux-mêmes. Un droit détenu par une permission accordée directement à un utilisateur (hors rôle, sans écran pour le faire) vaut « Tout l'organisme ».

- **Import** (`crm.import`) : l'import ne complète que les fiches du périmètre « Créer et modifier » des fiches de l'utilisateur qui le lance. Une ligne qui correspond (SIRET, e-mail, identifiant source, fiche d'une note) à une fiche existante hors de ce périmètre est **ignorée** avec le motif « Fiche existante hors de votre périmètre », sans modifier la fiche ni créer de doublon ; le rapport et la simulation l'indiquent. L'écran « Rôles et droits » le rappelle sous le droit.
- **Chiffre d'affaires sous « Ses DAS »** : le détail d'un DAS liste les devis dont une ligne porte ce DAS, même si l'opportunité du devis est rattachée à un DAS que l'utilisateur ne suit pas ; le lien vers ce devis aboutit alors à une page introuvable (404).

### Droits par défaut

Un nouvel organisme reçoit ces droits (identiques au comportement de la version 0.4, plus les nouveautés) :

| Rôle | Fiches, opportunités, devis, actions | Autres droits |
|---|---|---|
| Administrateur de l'organisme | Consulter, modifier, supprimer, périmètre « Tout » | `crm.activities.team`, chiffre d'affaires (consulter « Tout », objectifs), `catalogue.delete`, fusion, import, export, RGPD, catalogue |
| Direction | Consulter, « Tout » | `crm.activities.team`, chiffre d'affaires (consulter « Tout », objectifs), `catalogue.delete`, export, RGPD, catalogue |
| Commercial, Chargé de relations | Consulter, modifier, supprimer, « Tout » | Chiffre d'affaires : consulter « Ses fiches » ; fusion, import, export, consulter le catalogue |
| Assistant | Consulter, modifier, « Tout » ; supprimer des actions (`crm.activities.delete`) | Consulter le catalogue |
| Responsable DAS | Consulter, « Ses DAS » | Chiffre d'affaires : consulter « Ses DAS » ; export, consulter le catalogue |
| Formateur | Aucun | Aucun droit CRM, devis, catalogue, export, dossier ni financement |

Dossiers et sessions (`tms:permissions:sync` les accorde une fois aux rôles existants lors de la mise à jour) : l'administrateur a tous les droits ; la direction consulte ; le commercial, le chargé de relations et l'assistant consultent, créent et modifient ; le Responsable DAS consulte « Ses DAS ».

Planning (0.8.0, accordé une fois de la même façon, rôle Formateur créé dans chaque organisme) : l'administrateur et la direction ont les six droits ; le commercial, le chargé de relations et l'assistant ont tout sauf « Clôturer les présences » ; le Responsable DAS consulte les séances et gère les formateurs (`sessions.trainers.manage`, droit à périmètre) sur « Ses DAS » ; le **Formateur** a « Consulter les séances » et « Saisir les présences » sur « Ses séances », rien d'autre. Un utilisateur qui n'a que des droits « Ses séances » voit un menu réduit à **Mon planning** (l'accueil l'y renvoie).

**Mise à jour vers la 0.8.0** : le septième rôle système « Formateur » est créé dans chaque organisme. Si un rôle **personnalisé** s'appelle déjà « Formateur », deux rôles porteront ce libellé : renommez le rôle personnalisé (Rôles et droits › Renommer).

Le comportement de la 0.4 est conservé : le Commercial modifie toutes les fiches. Pour qu'il ne modifie que les siennes, réglez le périmètre de « Créer et modifier » sur « Ses fiches ».

### Correspondance avec les anciens droits (mise à jour vers la 0.5.0)

La migration convertit **automatiquement** les droits de chaque organisme, rôle par rôle : chaque utilisateur garde exactement ses accès, un droit que vous aviez retiré reste retiré, et un Responsable DAS reste limité à ses DAS (périmètre « Ses DAS »).

| Ancien droit | Nouveaux droits |
|---|---|
| `crm.view` | `crm.fiches.view`, `crm.opportunities.view`, `crm.quotes.view`, `crm.activities.view` |
| `crm.update` | `crm.fiches.update`, `crm.opportunities.update`, `crm.quotes.update`, `crm.activities.update`, et `crm.activities.delete` (retirer une action faisait partie de « modifier ») |
| `crm.create` | aucun droit propre : absorbé par les droits « Créer et modifier » |
| `crm.delete` | `crm.fiches.delete`, `crm.opportunities.delete`, `crm.quotes.delete`, `crm.activities.delete` |
| `catalogue.manage` | `catalogue.manage` (renommé « Modifier le catalogue ») et `catalogue.delete` |
| (nouveaux) | `crm.activities.team`, `crm.revenue.view`, `crm.revenue.manage` : accordés une fois selon le tableau des droits par défaut |

Un rôle qui n'avait que `crm.create` sans `crm.update` ne reçoit aucun droit de modification (aucun rôle par défaut n'est dans ce cas). La migration crée aussi de nouveaux index sur les tables des fiches : le verrou est bref lors de la mise à jour.

### Mises à jour

À chaque installation ou mise à jour (`ops/migrate.sh` lance `php artisan tms:permissions:sync`), la synchronisation accorde à chaque organisme les droits par défaut **nouveaux** de chaque rôle, une seule fois : un droit que vous avez retiré n'est pas rétabli. La matrice rôle → droit est propre à chaque organisme.

### L'écran « Rôles et droits »

Administration › **Rôles et droits** (droit `roles.manage`, accordé au seul administrateur). Un tableau croise les rôles (en colonnes) et les droits (en lignes, regroupés par thème, avec une phrase d'explication). Cochez ou décochez une case : elle est enregistrée aussitôt, et les utilisateurs du rôle ont leurs nouveaux droits dès leur page suivante, sans être déconnectés. Une case surlignée diffère des droits ou du périmètre par défaut du rôle (rôles système). Un rôle **sans** double authentification obligatoire qui détient « Gérer les paramètres », « Consulter le journal d'audit » ou « Lancer les exports système » affiche un avertissement sous son en-tête (non bloquant) : activez la double authentification pour ce rôle. La liste de périmètre est grisée tant que la case est décochée. L'en-tête de chaque colonne indique le nombre d'utilisateurs, l'interrupteur « Double authentification obligatoire » et un menu **Renommer** / **Revenir aux droits par défaut** (avec confirmation : remet exactement le tableau ci-dessus pour un rôle système, retire tous les droits d'un rôle personnalisé) / **Supprimer**.

### Rôles personnalisés

**Nouveau rôle** crée un rôle propre à l'organisme : libellé (unique, 80 caractères au plus), rôle dont reprendre les droits et périmètres (ou aucun) et double authentification (cochée par défaut). Le rôle apparaît ensuite dans la liste des rôles des invitations et du changement de rôle. Un rôle personnalisé se renomme et se supprime.

- **Suppression** : impossible tant qu'un utilisateur (actif **ou** désactivé) porte le rôle ou qu'une invitation non expirée le vise ; réattribuez-les d'abord. Les sept rôles système (Administrateur de l'organisme, Direction, Commercial, Chargé de relations, Assistant, Responsable DAS, Formateur) ne se suppriment jamais ; leurs libellés se renomment.
- **DAS suivis** : un utilisateur dont un droit a le périmètre « Ses DAS » se voit attribuer ses DAS suivis sur la page Utilisateurs, quel que soit son rôle ; sans DAS suivi, il ne voit aucune fiche pour ce droit. Un DAS suivi ne peut pas être supprimé des référentiels (désactivez-le).

### Double authentification par rôle

La double authentification est **toujours obligatoire** pour l'Administrateur et la Direction (interrupteur verrouillé). Pour les autres rôles, système ou personnalisés, l'interrupteur est libre ; elle est désactivée par défaut pour le Formateur. Quand elle devient obligatoire pour un rôle, ses utilisateurs déjà connectés sont envoyés vers l'activation à leur prochaine page, sans attendre une nouvelle connexion.

### Règles qui ne changent pas

- Le rôle **Administrateur de l'organisme** n'est pas modifiable (droits, périmètres, libellé, double authentification, suppression) : il garde tous les droits.
- **Gérer les utilisateurs** (`users.manage`), **Gérer les rôles et droits** (`roles.manage`) et **Gérer les connecteurs** (`connectors.manage`) ne s'accordent à aucun autre rôle, système ou personnalisé : qui les détiendrait pourrait se donner tous les droits (pour les connecteurs : faire passer la messagerie de l'organisme par son propre serveur et intercepter le lien d'invitation suivant).
- **Lancer les exports système** (`exports.run`) et **Gérer les paramètres** (`settings.manage`) sont des droits de niveau administrateur, signalés sur l'écran : les exports système portent sur tout l'organisme sans tenir compte des périmètres ; les paramètres couvrent la sécurité des sessions, les modèles d'e-mails (dont le texte de l'invitation) et la durée de conservation RGPD.
- Chaque changement est journalisé (`roles.permission_granted`, `roles.permission_revoked`, `roles.scope_changed`, `roles.reset`, création, renommage, suppression d'un rôle, double authentification). Les modifications n'ont aucun effet sur un autre organisme, et une mise à jour ne rétablit jamais un droit retiré.

## 11. RGPD : durée de conservation et anonymisation

Depuis l'écran **RGPD** (permission `crm.gdpr`), le champ **Années après le dernier contact** fixe la durée de conservation des personnes **sans relation contractuelle** :

- de **1 à 10 ans**, **3 ans** par défaut ; la valeur est propre à l'organisme (réglage `gdpr.retention_years`) ;
- chaque modification est consignée dans le journal d'audit (« settings.gdpr_changed », valeurs avant / après) ;
- chaque nuit à 3 h 30 (heure de Paris), le traitement `tms:crm:gdpr-retention` inscrit les personnes dont le dernier contact (ou, à défaut, la création) dépasse la durée sur la liste **À anonymiser**, puis anonymise celles qui y figurent depuis **30 jours** et restent dépassées. Il envoie aux détenteurs de `crm.gdpr` un e-mail avec le nombre de personnes nouvellement listées (sans noms) ;
- sont **exclues** : les clients (particuliers clients, interlocuteurs rattachés à une entreprise cliente), les personnes déjà anonymisées ou fusionnées, celles marquées **Conserver** jusqu'à une date, et celles que le module Sessions retient pendant la même durée : stagiaire d'une inscription non annulée dont la session se termine dans la période (ou plus tard), particulier contractant ou contact d'un dossier créé dans la période, financeur (personne) d'un financement créé dans la période. Une personne déjà listée qui entre dans l'un de ces cas est retirée de la liste à la vérification suivante ;
- une anonymisation est reportée à la nuit suivante si un import est en cours ;
- l'anonymisation est **irréversible** : elle efface les données personnelles de la fiche, de son historique, des pièces jointes et des lignes d'import, et, dans les dossiers et sessions, les besoins d'adaptation et notes de ses inscriptions, les notes des financements qui la concernent et de ses dossiers de particulier (inscriptions, dossiers et montants restent) ; le journal d'audit ne contenant jamais de valeur personnelle en clair, il n'a rien à effacer. Un import peut encore être annulé pour ses autres lignes.

- **Factures (0.9)** : une facture ou un avoir **validé** est une pièce comptable à conserver **10 ans** (obligation légale, prioritaire sur l'effacement) : l'anonymisation d'une personne ne le modifie pas, même quand il la nomme (stagiaire d'une facture de financeur) ; elle **supprime ses brouillons** et ceux des dossiers où elle est ou a été stagiaire (ils se préparent à nouveau depuis « À facturer »). Les e-mails d'envoi conservent l'adresse et le message de chaque envoi, comme la pièce qu'ils accompagnent.

Ce traitement repose sur le **planificateur** (service `scheduler` de `compose.prod.yaml`) et sur le *worker* ; vérifiez qu'ils tournent (`docker compose ps`).

## 12. Imports : file d'attente et conservation

Les imports de fichiers (CRM) s'exécutent en arrière-plan.

- **Simulation** : file `imports` (superviseur Horizon `supervisor-imports`, connexion `redis-long`, 1 processus en développement, 2 en production, délai maximal de 15 minutes par fichier). L'**exécution** et l'**annulation** d'un import passent par la file par défaut, par tranches, et reprennent là où elles se sont arrêtées en cas d'interruption.
- **Suivre l'activité** : le tableau de bord **Horizon** de l'installation montre les files `default` et `imports` et les tâches en échec. Un import qui n'avance plus affiche un message d'arrêt et peut être relancé ou marqué en échec depuis son rapport.
- **Surveillance** : toutes les 10 minutes, le traitement `tms:crm:import-watchdog` reprend **une fois**, depuis la dernière tranche enregistrée, un import en cours sans avancement depuis **15 minutes** (processus arrêté, serveur redémarré) ; s'il se bloque de nouveau, l'import est marqué **échoué** avec la raison (ce qu'il a créé reste annulable). Les deux évènements figurent dans le journal d'audit. Un import encore traité par son processus n'est jamais repris en double.
- **Lignes refusées par la base** : une ligne qu'une contrainte de la base refuse passe **en erreur** (code de l'erreur dans le rapport, aucune valeur dans les journaux techniques) ; l'import continue.
- **Type de fichier** : le contenu réel est vérifié au dépôt (un `.xlsx` doit être un classeur Office, un `.csv` du texte UTF-8 ou Windows-1252) ; un fichier renommé est refusé avec un message.
- **Taille** : le fichier déposé est limité en poids (le message de l'écran **Déposer le fichier** donne la limite) ; les gros fichiers sont lus en continu.
- **Conservation** : le traitement quotidien `tms:crm:purge-imports` (3 h 30) supprime le **fichier importé et les valeurs brutes** des lignes de plus de **30 jours**, et remplace le nom du fichier par `fichier-import.<ext>` (il peut contenir un nom de personne) ; les totaux, le rapport et l'historique restent. Le rapport Excel téléchargeable est conservé.
- **Pièces jointes des fiches en corbeille** : le traitement quotidien `tms:crm:purge-attachments` (3 h 45) efface du stockage les fichiers joints des entreprises et personnes **en corbeille depuis plus de 12 mois** ; la ligne reste dans l'historique avec la mention « Fichier supprimé » (même si la fiche est restaurée ensuite).
- **Stockage** : les pièces jointes et fichiers d'import sont rangés par organisme dans le stockage privé de l'application (hors dossier public) et sont inclus dans la sauvegarde restic.

## 13. Pipeline et relances : traitements planifiés

- **Récapitulatif quotidien** : chaque jour à **7 h 30** (heure de Paris), `tms:crm:daily-digest` envoie à chaque utilisateur qui l'a demandé (**Paramètres › Profil › Recevoir le récapitulatif quotidien**, désactivé par défaut) la liste de ses actions en retard et du jour. Aucun e-mail aux comptes désactivés, sans droit « Consulter les actions » (`crm.activities.view`), ni les jours sans action. L'envoi passe par la messagerie de l'organisme (connecteur SMTP).
- **Envoi vers Outlook** : file par défaut, en arrière-plan, quelques instants après chaque modification d'une action ; une indisponibilité de Microsoft est retentée pendant 24 h (journal d'exécution du connecteur, sans donnée personnelle).
- **Lien iCal** : l'adresse est privée (jeton aléatoire dont seule l'empreinte est stockée) et limitée en nombre d'appels ; un utilisateur désactivé, ou qui n'a ni « Consulter les actions » ni séance à publier (formateur lié, responsable de session avec « Consulter les séances »), n'obtient plus rien. Révoquer un lien : l'utilisateur le fait lui-même dans **Mon agenda** ; désactiver son compte coupe aussi le flux.
- Ces traitements reposent, comme les autres, sur le **planificateur** et le *worker* (voir § 11).

## 14. Devis : envoi, expiration et archives

- **Envoi des e-mails** : file par défaut, en arrière-plan, juste après l'enregistrement. Un envoi qui échoue est tenté 3 fois en tout (nouvel essai après 1 puis 5 minutes) ; ensuite le devis affiche « L'e-mail n'est pas parti » avec une raison fixe en français (code SMTP au plus, jamais le texte du serveur ni les adresses) et **Renvoyer**. Chaque essai figure dans le journal d'exécution du connecteur SMTP.
- **Expiration** : chaque nuit à **0 h 05** (heure de Paris), `tms:crm:expire-quotes` passe **Expiré** les devis envoyés ou relancés dont la validité est dépassée, dans chaque organisme (journalisé, visible dans l'historique).
- **PDF** : rendus par le service **Gotenberg** de la pile (voir `install.md`). Le PDF d'un devis envoyé est archivé dans le stockage privé (`tenants/<organisme>/quotes/<devis>/<numéro>.pdf`, inclus dans la sauvegarde) et n'est plus jamais régénéré. L'anonymisation d'une personne supprime les PDF de ses devis ; leur page affiche alors « Document supprimé (données anonymisées) ».
- Comme les autres traitements, l'envoi et l'expiration reposent sur le **planificateur** et le *worker* (voir § 11).

## 15. Dossiers et sessions : listes, droits et règle d'abandon (lot 2a)

Guide utilisateur : `sessions.md`.


- **Référentiels** (Administration > Référentiels) : *Types de financeur*, *Motifs d'abandon*, *Motifs d'annulation*, *Catégories de stagiaires (BPF)*, *Niveaux de diplôme* et *Modalités de formation* (Présentiel, Distanciel (classe virtuelle), FOAD / e-learning, Mixte ; obligatoire sur une session). Les valeurs par défaut sont créées à la mise à jour pour les organismes existants (migration `seed_sessions_reference_lists`, sans toucher à ce qui existe ni recréer une valeur supprimée) et à l'installation par le jeu de valeurs CAP FORMATION. Un motif d'abandon porte la case **Force majeure** (par défaut : Maladie, Accident) : un abandon pour ce motif n'entraîne jamais de dédit. Un type de financeur porte le champ **Financement initial d'un dossier** (une valeur au plus pour une entreprise contractante, une pour un particulier ; par défaut « Entreprise » et « Personnel », posés une fois pour les organismes existants par la migration `flag_default_funding_types`) : c'est le type du financement créé avec un dossier issu d'un devis ; sans valeur marquée, pas de financement initial. La situation professionnelle d'un stagiaire réutilise la liste existante *Situations professionnelles*.
- **Droits** : domaines *Dossiers* (`sessions.dossiers.*`) et *Sessions et inscriptions* (`sessions.sessions.*`), voir § 10.
- **Export RGPD d'une personne** : il contient aussi ses inscriptions et ses dossiers ; toujours complet, il exige désormais, en plus des quatre droits « Consulter » du CRM, « Consulter les dossiers » et « Consulter les sessions » sur « Tout l'organisme ».
- **Corbeille et fusion** : une personne inscrite peut aller à la corbeille (ses inscriptions restent et reviennent avec elle) ; une fusion déplace sessions, dossiers, inscriptions et financements, et est refusée pour deux personnes inscrites sur la même session. L'annulation d'un import conserve les fiches qu'un dossier, une session, une inscription ou un financement nomme.
- **Règle d'abandon** (Administration > Règle d'abandon, droit `settings.manage`) : *prorata seulement* (défaut) ou *prorata et dédit* de 1 à 100 %. Réglage `sessions.abandon_rule` de l'organisme (changement journalisé) ; un dossier pourra la remplacer par la sienne. Le lot 2a l'enregistre et l'affiche ; les heures suivies (2d) et la facturation (lot 3) l'appliqueront.

## 16. Planning : salles, fermetures et formateurs (0.8.0)

Guide utilisateur : `sessions.md` (§ 9).

- **Salles et fermetures** (Administration › Salles et fermetures, droit `sessions.rooms.manage`, qui couvre les salles **et** les périodes de fermeture) : une salle a un nom (120 caractères), une capacité facultative (places), une adresse facultative et un état actif / inactif ; seules les salles actives sont proposées au planning. Une salle utilisée par des séances ne se supprime pas : désactivez-la. Une **période de fermeture** (intitulé, du … au …, bornes incluses) est exclue de la génération des séances ; les jours fériés nationaux le sont d'office.
- **Formateurs** (menu Formation › Formateurs, droit `sessions.trainers.manage`) : voir `sessions.md`. Pour donner un accès au formateur, **Inviter à se connecter** sur sa page (visible avec `users.manage`) ouvre l'invitation habituelle préremplie (nom, e-mail, rôle Formateur). À l'activation du compte, il est lié automatiquement à la fiche formateur **si une seule** fiche formateur a cet e-mail (e-mail principal de la personne, sans tenir compte des majuscules) ; sinon, liez-le à la main (« Compte lié » sur la page du formateur). Un utilisateur qui reçoit le rôle Formateur (invitation ou changement de rôle) sans fiche formateur en reçoit une, créée avec une fiche Personne reprise par e-mail (une seule correspondance) ou nouvelle ; plusieurs formateurs avec son e-mail : rien n'est créé, liez à la main. Un compte n'est lié qu'à un formateur. Il n'y a pas de droit « indisponibilités » : un formateur lié à son compte saisit lui-même ses indisponibilités dans « Mon planning » ; les gestionnaires (`sessions.trainers.manage`) les saisissent pour tous.
- **RGPD** : une personne formateur est conservée par l'annulation d'un import ; la fusion de deux personnes formateurs est refusée (supprimez d'abord l'une des deux fiches formateur) ; l'anonymisation efface les motifs de ses indisponibilités. Le motif d'une indisponibilité n'apparaît jamais en clair dans le journal d'audit.
- **Feuilles d'émargement et scans signés** (voir `sessions.md` § 10) : le PDF est généré par le service Gotenberg de la pile (comme les devis), à partir du nom et du logo de la **société par défaut** ; les scans signés (PDF, PNG, JPEG, 10 Mo) sont rangés dans l'espace privé de l'organisme (`tenants/<id>/attendance_sheets/`), inclus dans les sauvegardes de l'espace de fichiers. Ils ne se suppriment que par un gestionnaire de présences ; l'anonymisation d'un stagiaire les garde (preuve collective) sauf sur une session individuelle, et garde aussi présences et heures (elle n'en efface que les motifs).

## 17. Sauvegardes (0.8.2)

Guide d'installation et d'exploitation : `install.md` (« Sauvegardes » et « Déménager vers un autre serveur »).

- **Qui la voit** : Administration › Sauvegardes, droit `settings.manage` (le rôle administrateur de l'organisme, avec 2FA obligatoire). En mode multi-organismes, la page et la pastille n'existent pas : l'hébergeur gère le serveur.
- **Contenu** : dernière sauvegarde (date en heure de Paris, résultat, message d'erreur en français en cas d'échec, taille, version, identifiant court), **copie hors site** (« Non configurée », « Configurée, dernière copie réussie », « Configurée, dernière copie échouée » ou « résultat inconnu »), liste des sauvegardes disponibles (au plus 60, avec leurs étiquettes : `manuel`, `export`, `pre-import`, `pre-update-…`, `version:…`) et un rappel de conserver hors du serveur le mot de passe des sauvegardes et la clé de l'application.
- **États** : *Réussie* ; *Échouée* (alerte rouge avec le message) ; *Réussie depuis plus de 36 heures* (alerte) ; *Aucune sauvegarde connue* (alerte : le serveur n'a jamais écrit son état, ou le fichier est illisible ; la page n'affiche jamais « réussie » sans preuve).
- **Pastille** : point rouge sur « Sauvegardes » dans le menu des détenteurs de `settings.manage` (son libellé accessible donne le message « La dernière sauvegarde… ») quand la dernière sauvegarde a échoué, date de plus de **36 heures** ou est inconnue ; aucune alerte sur l'accueil. Le détail s'affiche en haut de la page.
- **Sauvegarder maintenant** : dépose une demande que le serveur lance aussitôt (étiquette `manuel`) ; le bouton est désactivé (« Sauvegarde demandée… », « Sauvegarde en cours… ») tant qu'une demande est en attente ou qu'une sauvegarde tourne, la page se met alors à jour d'elle-même toutes les 15 secondes, et la demande est inscrite au journal d'audit (« Sauvegarde demandée », qui et quand). Une demande que le serveur n'a pas prise en charge au bout de 10 minutes est signalée (« La demande n'a pas été prise en charge par le serveur. ») et peut être renouvelée. L'application ne lance rien elle-même et ne détient aucun secret de sauvegarde.
- **Ce que la page ne fait pas** : ni téléchargement des données, ni restauration (commandes sur le serveur : `ops/restore.sh`, `ops/export.sh`, `ops/import.sh`).

## 18. Factures : réglages et exploitation (0.9)

Guide utilisateur : `finance.md`.

- **Finance › Réglages de facturation** (droit `settings.manage`) : **absences non justifiées** de l'organisme, « Non facturées » (défaut) ou « Facturées au contractant » ; chaque dossier peut remplacer ce réglage. Il ne s'applique qu'aux **dossiers pas encore facturés** : la première facture validée fige la règle du dossier, qu'un changement ultérieur du réglage de l'organisme ne touche plus. Changement journalisé.
- **Sociétés** : préfixes de factures et d'avoirs, délai de paiement (0 à 60 jours), pied de facture ; voir § 3.
- **Référentiel « Types de financeur »** : case **Facturé hors du TMS** (par exemple CPF). Un financement de ce type retire sa part de la facture du contractant et n'émet aucune facture TMS. Cochée d'origine sur « CPF » ; la mise à jour 0.9 la coche, dans chaque organisme existant, sur le seul type nommé exactement « CPF » (s'il ne porte pas déjà ce réglage). Vérifiez les autres types, et décochez « CPF » si l'organisme le facture lui-même.
- **Modèle d'e-mail « Facture »** : Administration › Modèles d'e-mails (variables : numéro, pièce, type, société, destinataire, montant TTC, échéance, règlement, organisme).
- **PDF** : mêmes exigences que les devis (Gotenberg démarré, `GOTENBERG_URL`) ; une facture ne se valide pas si le PDF ne peut pas être produit. Les PDF validés sont archivés par organisme (zone `invoices`) et **font partie de la sauvegarde** : ne les excluez pas.
- **Intégrité** : `docker compose exec app php artisan tms:finance:verify` (tous les organismes ; code de sortie 1 et numéro de la première pièce en cause en cas de rupture). À lancer après une restauration ou un déménagement (`ops/import.sh`), et à intervalles réguliers.
- **Conservation** : une facture validée est une pièce comptable conservée **10 ans** ; elle n'est ni supprimée ni modifiée, même par l'anonymisation RGPD d'une personne (voir § 11).
