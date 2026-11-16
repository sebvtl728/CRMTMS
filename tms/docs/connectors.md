# Connecteurs

Tout logiciel externe (Formevol, Sage, SoWeSign, Outlook, serveur SMTP…) passe par un **connecteur** : un adaptateur isolé dans `app/Modules/Connectors/`, jamais codé en dur dans le métier. Le métier appelle le connecteur ; le connecteur seul connaît le protocole du logiciel.

## Briques fournies (`app/Modules/Connectors/Core`)

| Élément | Rôle |
|---|---|
| `Connector` | Contrat à implémenter (clé, libellé, type, règles des paramètres, secrets, test de connexion). |
| `ConnectorKind` | `import`, `export`, `sync`, `delivery`, `assistant`. |
| `ConnectorRegistry` | Liste des connecteurs connus (`register`, `all`, `make`). |
| `ConnectorConfig` | Configuration d'un connecteur pour un organisme, ou une société (`company_id`). Secrets chiffrés. |
| `ConnectorRun` | Journal d'exécution (`ConnectorRun::track`). |
| `Retryable` (optionnel) | Le connecteur sait rejouer une exécution en échec. |
| `HasFormLabels` (optionnel) | Libellés français des champs du formulaire d'administration. |
| `HasFormFields` (optionnel) | Présentation de certains champs : liste de choix (`select`), case à cocher avec avertissement (`checkbox`), suggestions, aide, champ affiché seulement pour certaines valeurs d'un autre (`visible_when`). |

L'écran d'administration (`/admin/connectors`, permission `connectors.manage`, réservée à l'admin d'organisme) est **générique** : il se construit à partir du connecteur, sans code d'interface à écrire.

## Ajouter un connecteur

1. **Créer la classe** dans `app/Modules/Connectors/<Nom>/<Nom>Connector.php` (ou dans le module métier concerné) :

   ```php
   final class SageConnector implements Connector, HasFormLabels
   {
       public static function key(): string { return 'sage'; }          // stable, minuscules, jamais renommée
       public static function label(): string { return 'Sage (export comptable)'; }
       public static function kind(): ConnectorKind { return ConnectorKind::Export; }

       public function settingsRules(): array
       {
           return ['endpoint' => ['required', 'url', 'max:255'], 'journal' => ['required', 'string', 'max:10']];
       }

       public function secretKeys(): array { return ['api_token']; }

       public function test(ConnectorConfig $c): ConnectionTestResult { /* voir règles ci-dessous */ }
   }
   ```

2. **L'enregistrer** : ajouter la classe à `CONNECTORS` dans `ConnectorsServiceProvider`, ou, depuis un autre module, appeler `app(ConnectorRegistry::class)->register(SageConnector::class)` dans son service provider. Les dépendances du constructeur sont résolues par le conteneur.

3. **Écrire les tests** (TDD) : règles de paramètres, `test()` (échec ET succès, sans réseau réel), chiffrement des secrets, journal d'exécution. Modèle : `tests/Feature/Connectors/`.

## Règles

### Paramètres (`settingsRules`)
- Clés = noms des paramètres, valeurs = règles de validation Laravel (non préfixées). Seules les clés déclarées sont enregistrées, les autres sont ignorées.
- Messages d'erreur en français. Toute valeur qui désigne une adresse réseau doit être validée (pas de schéma, de chemin ni d'adresse locale : voir `SmtpHost`).

### Secrets (`secretKeys`)
- Mots de passe, jetons, clés d'API : **uniquement** dans les secrets, jamais dans `settings`.
- Stockés chiffrés (`encrypted:array`, clé `APP_KEY`), masqués dans le journal d'audit (`[masqué]`), exclus de la sérialisation.
- L'interface ne reçoit que `secrets_set` (`{"api_token": true}`), jamais les valeurs. Un champ laissé vide **conserve** la valeur ; l'effacement est une action explicite (`DELETE /admin/connectors/{clé}/secrets/{secret}`).
- Ne jamais écrire un secret dans un log, une exception ou un message : `ConnectorRun::track` retire de l'erreur les secrets de la configuration, mais ne comptez pas dessus.

### Test de connexion (`test`)
- Ne doit **jamais bloquer** (délai de connexion ≤ 10 s) ni lever d'exception : toute erreur devient `new ConnectionTestResult(false, 'message en français')`.
- Le message ne contient aucun secret et ne relaie pas le texte brut du serveur distant.
- Tests automatisés : hôte injoignable en `.invalid` (échec DNS immédiat) ; chemin de succès avec un faux transport injecté dans le conteneur. Aucun appel réseau réel.

### Journal d'exécution
Toute opération (import, export, envoi) s'enveloppe dans `ConnectorRun::track` :

```php
$run = ConnectorRun::track($config, 'out', function () use ($payload) {
    // appel du logiciel externe
    return '12 écritures exportées';        // devient le résumé
}, payloadRef: 'export:2026-11');           // optionnel : de quoi rejouer
```

- `direction` : `in` ou `out`. L'exception est capturée (statut `failed`, message tronqué à 2 000 caractères) et **non relancée** : inspectez le `ConnectorRun` retourné.
- **Relancer** : l'écran propose l'action pour une exécution `failed` dont `payload_ref` est défini et dont le connecteur implémente `Retryable::retry(ConnectorRun $run)`. La relance crée une nouvelle exécution.
- Les jobs de connecteur utilisent `TenantAwareJob` (le tenant voyage avec le job).

### Portée
Une configuration par `(organisme, connecteur, société)`. `company_id` vide = tout l'organisme ; une configuration de société prévaut sur celle de l'organisme (exemple : `TenantMailer::for($company)`).

## Connecteur SMTP

`SmtpConnector` (clé `smtp`, type envoi). Paramètres : `host`, `port`, `encryption` (`tls`, `ssl`, `none`), `from_address`, `from_name` ; secrets : `username`, `password`.

`TenantMailer::for(?Company $company)` renvoie le mailer à utiliser, par priorité : SMTP propre à la société, puis **Microsoft 365 (envoi des e-mails)** de l'organisme, puis SMTP de l'organisme, puis mailer par défaut de l'instance (`MAIL_*`). 
### Politique réseau des hôtes SMTP (`SmtpHostPolicy` → `Core\HostPolicy`)

La politique est généralisée dans `App\Modules\Connectors\Core\HostPolicy` (résolution A/AAAA, classification, `violation($ips, strict: false)`) ; `SmtpHostPolicy` n'en est plus qu'une façade (comportement SMTP inchangé). Avec `strict: true`, les réglages `allow_*` sont ignorés : seules les adresses publiques passent (utilisé par Sirene).

Appliquée à l'enregistrement (règle `SmtpHost`, qui résout l'hôte en A/AAAA) **et** à chaque envoi (`SmtpTransportFactory::make`, donc aussi `TenantMailer`) :

- Format : nom ou adresse seuls, sans schéma, port, chemin ni raccourci numérique (`127.1`, `2130706433`).
- **Toujours refusés** : boucle locale, lien local (dont `169.254.169.254`, métadonnées cloud), adresse non spécifiée, multicast / réservées, espace partagé `100.64.0.0/10`, et leurs formes IPv4 intégrées en IPv6 (`::ffff:127.0.0.1`, `::127.0.0.1`, NAT64) ; `localhost` et tout nom qui résout vers l'une d'elles. Exception : `TMS_ALLOW_INTERNAL_SMTP_HOSTS=true` (capteur de mails en développement uniquement).
- **Réseaux privés** (RFC 1918, IPv6 ULA) : autorisés en mode `single` (relais sur le réseau local d'un client auto-hébergé), refusés en mode `multi`. Réglable par `TMS_ALLOW_PRIVATE_SMTP_HOSTS`.
- Un nom qui ne résout pas est accepté à l'enregistrement mais refusé au test et à l'envoi (message français).
- Les messages d'erreur du test sont toujours rédigés par l'application ; le texte brut du serveur (qui peut contenir l'identifiant) n'est jamais relayé.
- « DNS rebinding » (un nom qui change d'adresse entre la vérification et la connexion) : la connexion se fait sur l'**adresse vérifiée** (le nom n'est pas résolu une seconde fois) ; avec TLS, le certificat reste vérifié par rapport au nom du serveur (`peer_name`, SNI).

## Connecteur Microsoft 365 (envoi des e-mails)

`Mail365\Microsoft365MailConnector` (clé `microsoft365_mail`, type envoi). Les e-mails du TMS envoyés par l'application (devis, plaquettes, invitations, récapitulatifs, e-mails de test) partent par **Microsoft Graph**, depuis **une seule boîte** Microsoft 365, avec une application Azure autorisée en **application** (`Mail.Send`, flux « client credentials », sans mot de passe de boîte). Pas de configuration par société (interface `OrganismeWide`) : une seule configuration pour l'organisme. Restent sur la messagerie de l'instance (`MAIL_*` du `.env`) : la réinitialisation du mot de passe et l'alerte d'exploitation `tms:alert`.

Paramètres : `tenant` (annuaire précis : son ID ou un de ses domaines, par ex. `organisme.fr`, `organisme.onmicrosoft.com` ; `common`, `organizations` et `consumers` sont refusés), `client_id`, `from_address` (la boîte d'expédition), `from_name` (facultatif). Secret : `client_secret`. Changer `tenant` ou `client_id` oblige à ressaisir le secret.

### Procédure pour l'administrateur Microsoft 365

1. Portail Azure › **Microsoft Entra ID** › **Inscriptions d'applications** › **Nouvelle inscription**. Nom : « TMS – envoi des e-mails ». Types de comptes : « Comptes dans cet annuaire organisationnel uniquement ». Aucune URI de redirection. Notez l'**ID d'application (client)** et l'**ID de l'annuaire (locataire)**.
2. **Autorisations des API** › Ajouter une autorisation › Microsoft Graph › **Autorisations de l'application** (et non « déléguées ») › `Mail.Send`.
3. **Accorder le consentement administrateur** pour l'annuaire (le bouton doit afficher une coche verte « Accordé »).
4. **Certificats et secrets** › Nouveau secret client. Copiez la **Valeur** (pas l'ID du secret) et **notez sa date d'expiration** : passé cette date, les envois échouent (« secret expiré ? ») jusqu'au remplacement du secret dans le TMS.
5. **Restreindre l'application à la seule boîte d'expédition.** Par défaut, `Mail.Send` en application permet d'envoyer au nom de **toutes** les boîtes de l'annuaire : limitez-la dans Exchange Online PowerShell, avec une stratégie d'accès d'application (ou, équivalent récent, RBAC pour les applications) :

   ```powershell
   # Groupe de sécurité à extension messagerie (ou boîte) contenant la seule boîte d'expédition
   New-ApplicationAccessPolicy -AccessRight RestrictAccess -AppId <ID d'application> `
     -PolicyScopeGroupId envoi-tms@organisme.fr -Description "TMS : envoi depuis la boîte d'expédition uniquement"

   # Vérification : AccessCheckResult doit être « Granted » pour la boîte d'expédition, « Denied » pour une autre
   Test-ApplicationAccessPolicy -Identity envoi@organisme.fr -AppId <ID d'application>
   Test-ApplicationAccessPolicy -Identity autre.personne@organisme.fr -AppId <ID d'application>
   ```

   La stratégie peut mettre une heure à s'appliquer.
6. Dans le TMS : `/admin/connectors` › **Microsoft 365 (envoi des e-mails)** : annuaire (domaine ou ID), ID d'application, secret client, adresse d'expédition (la boîte), nom d'expéditeur. Enregistrez, activez, puis **Tester la connexion** : le TMS obtient un jeton puis envoie un e-mail de test à **votre** adresse (« E-mail de test envoyé à … »). En cas de refus, vérifiez la permission `Mail.Send`, le consentement et la stratégie d'accès.
7. Avant l'expiration du secret : créez-en un nouveau dans Azure et remplacez-le dans le TMS.

### À savoir

- **Domaine** : publiez SPF, **DKIM** (activé pour le domaine dans Microsoft 365) et **DMARC** pour le domaine de la boîte d'expédition, sinon les devis risquent le dossier des indésirables.
- **Expéditeur** : le message part toujours depuis la boîte configurée (Graph l'impose). Le nom affiché est `from_name`, à défaut celui du message ; l'adresse de réponse (`Reply-To`) est conservée. Les e-mails apparaissent dans les **éléments envoyés** de la boîte.
- **Priorité** : SMTP de la société > Microsoft 365 de l'organisme > SMTP de l'organisme > messagerie de l'instance. Désactiver le connecteur rétablit le SMTP de l'organisme.
- **Gros e-mails** : jusqu'à 3 Mo (encodé), le message part en un appel (`/sendMail`, MIME). Au-delà (plaquettes jointes, jusqu'à 20 Mo), le TMS crée un brouillon (corps HTML conservé ; la partie texte brut est abandonnée ; destinataires), ajoute les pièces jointes (celles d'au moins 3 000 000 octets par **sessions d'envoi**, morceaux de 3,75 Mo) puis l'envoie ; en cas d'échec le brouillon est supprimé.
- **Erreurs** : refus (401/403), ressource introuvable (404) et toute autre erreur 4xx = échec définitif du devis ou de la plaquette (« Échec », message français fixe, sans texte de Microsoft) ; limitation (429), indisponibilité (5xx) ou délai dépassé = nouvelle tentative du job. Un délai dépassé *après* acceptation par Microsoft peut, rarement, produire un doublon à la nouvelle tentative (même limite qu'en SMTP).
- **Sécurité** : le secret est chiffré, jamais réaffiché ni journalisé ; le jeton d'application est mis en cache chiffré (clé préfixée par l'organisme et l'id de la configuration), jamais en base ; seuls `login.microsoftonline.com`, `graph.microsoft.com` et les adresses de dépôt Outlook sont appelés, en https, sans redirection (`HostPolicy` strict).

## Connecteur Sirene (préremplissage par SIRET)

`SireneConnector` (clé `sirene`, type import, aucun secret). Appelle l'API publique « Recherche d'entreprises » (`GET {base_url}/search?q=<siret>&per_page=1`, sans clé, vérifiée en novembre 2026). Réglage unique : `base_url` (vide = `https://recherche-entreprises.api.gouv.fr`). **Aucune configuration n'est nécessaire** : la première recherche d'un organisme crée sa configuration, activée ; un administrateur peut la désactiver ou changer l'adresse dans `/admin/connectors`.

- Route : `GET /crm/sirene/{siret}` (droit `crm.fiches.update`, 30 requêtes/minute/utilisateur). 422 si le SIRET n'a pas 14 chiffres ou échoue au contrôle Luhn ; 404 « Aucune entreprise trouvée pour ce SIRET. » ; 503 « Service de l'annuaire indisponible, saisissez les informations manuellement. » (API en panne, délai de 5 s dépassé, réponse illisible, connecteur désactivé).
- Le formulaire d'entreprise propose « Compléter depuis le SIRET » (SIRET valide) : seuls les champs **vides** sont remplis, la liste des champs complétés est affichée, l'utilisateur valide en enregistrant.
- Adresse appelée : `base_url` doit être `https://` + hôte seul (ni identifiant, port, chemin) résolvant **uniquement vers des adresses publiques** (`HostPolicy` strict : aucun réglage ne permet un hôte local ou privé). Revérifié à chaque appel ; redirections non suivies ; la requête est épinglée sur les adresses vérifiées (`HostPolicy::pinned`, `CURLOPT_RESOLVE` : aucune seconde résolution, protection contre le « DNS rebinding »). Même règle pour le connecteur Microsoft 365 (`login.microsoftonline.com`, `graph.microsoft.com`).
- Cache (par organisme) : clé `tenant:<tenant_id>:sirene:<siret>`, 24 h pour un résultat, 1 h pour « introuvable » ; les erreurs ne sont jamais mises en cache, ni les entrepreneurs individuels (nom = personne physique).
- Journal : seul un appel distant réel est journalisé (`connector_runs`, sens `in`), résumé « SIRET … : entreprise trouvée / aucune entreprise trouvée ». Ni raison sociale, ni adresse, ni dirigeants ne sont conservés (les dirigeants de l'API ne sont jamais lus).

### Correspondance de la réponse

| Champ du TMS | Champ de l'API |
|---|---|
| raison sociale | `nom_raison_sociale` (sinon `nom_complet`) |
| SIRET | `siret` de l'établissement trouvé (parmi `siege` et `matching_etablissements`) |
| code NAF | `activite_principale` de l'établissement (sinon de l'entreprise), sans le point (`58.29C` → `5829C`) |
| adresse (ligne 1) | `adresse` de l'établissement, sans le « code postal + commune » final |
| code postal / ville | `code_postal` / `libelle_commune` |
| forme juridique | `nature_juridique` (code INSEE) traduite par `InseeCodes::legalFormLabel` (EI, EURL, SARL, SAS, SASU, SA, SCOP, Association, Collectivité territoriale, Établissement public ; sinon vide), puis rapprochée par libellé (sans accents ni casse) du référentiel `legal_form_org` |
| taille | `tranche_effectif_salarie` (code INSEE → « 20 à 49 salariés »), rapprochée du référentiel `company_size` par libellé identique, sinon par la première tranche (« 10-49 », « 250 et plus ») qui contient toute la fourchette ; sinon vide |

L'effectif est celui de l'établissement (non diffusé ou inconnu : vide). Les adresses sont reprises telles que l'API les donne (majuscules).

## Connecteur Microsoft 365 (envoi vers l'agenda Outlook)

`Microsoft365Connector` (clé `microsoft365`, type synchronisation). Chaque utilisateur qui le souhaite connecte **son** compte Microsoft 365. Ses rendez-vous et appels datés du CRM sont alors créés dans son agenda Outlook, mis à jour et supprimés avec eux. C'est un envoi : rien n'est relu depuis Outlook. Ce sont les mêmes actions que le flux iCal : types marqués « rendez-vous » ou « appel » (`meta.planning`), action datée, fiche non supprimée, visible de son responsable.

Paramètres : `tenant` (annuaire Azure : son ID, un de ses domaines `xxx.onmicrosoft.com`, ou vide = `common`), `client_id` (ID d'application). Secret : `client_secret`.

### Déclarer l'application dans Azure (une fois par organisme)

1. Portail Azure › **Microsoft Entra ID** › **Inscriptions d'applications** › **Nouvelle inscription**.
   - Nom : « TMS – agenda ».
   - Types de comptes : « Comptes dans cet annuaire organisationnel uniquement » (recommandé ; renseignez alors `tenant` avec l'ID de l'annuaire).
   - **URI de redirection** : plateforme **Web**, `https://<adresse de l'organisme>/settings/calendar/outlook/callback`. Exemple : `https://tms.example.fr/settings/calendar/outlook/callback`. Ajoutez une URI par adresse si l'organisme en a plusieurs.
2. **Autorisations des API** › Ajouter › Microsoft Graph › **Autorisations déléguées** : `Calendars.ReadWrite` et `offline_access`. Ne choisissez pas d'autorisation « Application ». Facultatif : « Accorder le consentement administrateur » évite à chaque utilisateur de consentir lui-même.
3. **Certificats et secrets** › Nouveau secret client. Copiez la **Valeur** (et non l'ID du secret) et notez sa date d'expiration.
4. Dans le TMS : `/admin/connectors` › Microsoft 365. Renseignez :
   - `tenant` : ID de l'annuaire ;
   - `client_id` : ID d'application (client) ;
   - `client_secret` : la valeur copiée.

   Activez puis lancez **Tester**. Avec un annuaire précis, le test vérifie l'identifiant et le secret auprès de Microsoft. Avec `common`, il vérifie seulement que Microsoft est joignable.
5. Avant l'expiration du secret : créez-en un nouveau dans Azure et remplacez-le dans le TMS. Changer `tenant` ou `client_id` oblige à ressaisir le secret.

### Côté utilisateur

« Mon agenda » (`/settings/calendar`) › **Connecter Outlook** : connexion Microsoft, consentement, retour au TMS. Les actions déjà planifiées des 180 prochains jours sont alors envoyées. **Déconnecter Outlook** arrête l'envoi, retire de l'agenda Outlook les rendez-vous et appels que le TMS y avait créés (job `RemoveOutlookEvents`, par lots de 200, tant que les jetons sont encore valides), puis supprime les jetons. Le retrait est « au mieux » : un évènement que Microsoft refuse de supprimer reste dans l'agenda, mais le TMS garde sa trace. Une reconnexion le met alors à jour au lieu d'en créer un second : déconnecter puis reconnecter ne crée jamais de doublon.

### Fonctionnement et sécurité

- **OAuth 2.0 « code d'autorisation » + PKCE (S256).**
  - Le `state` est signé (HMAC dérivé d'`APP_KEY`). Il est lié à l'organisme, à l'utilisateur et à sa session, valable 10 minutes et à usage unique.
  - L'URI de redirection est calculée sur l'adresse de la requête, donc celle de l'organisme.
- **Jetons.** Jetons d'accès et de rafraîchissement par utilisateur dans `calendar_links`, chiffrés (`APP_KEY`), jamais sérialisés ni journalisés.
- **Renouvellement.**
  - Le jeton d'accès est renouvelé avant son expiration, puis une fois de plus si Graph répond 401.
  - Si Microsoft refuse le renouvellement (`invalid_grant` : consentement retiré, mot de passe changé…), le lien passe « À reconnecter ». L'utilisateur voit un message dans « Mon agenda » et le bouton « Reconnecter Outlook ». Rien n'est envoyé d'ici là.
- **Déclenchement.**
  - Le CRM émet `Crm\Activities\Events\ActivityChanged` après validation de la transaction (création, modification du type, de la date, de la durée, du lieu, des fiches ou du responsable, suppression, restauration, anonymisation). Il l'émet aussi pour les rendez-vous et appels concernés (à faire, ou prévus depuis la veille) quand leur fiche ou leur opportunité part à la corbeille ou en revient, quand deux fiches sont fusionnées, ou quand l'opportunité change de DAS ou de compte (`Crm\Activities\CalendarAnnouncements`). Un simple renommage de fiche n'est repris qu'à la prochaine modification de l'action.
  - L'écouteur `QueueOutlookPush` met en file `PushActivityToOutlook` (`TenantAwareJob`, après commit). Il le fait seulement si le connecteur est actif et que le responsable (nouveau ou précédent) a un lien actif, ou qu'un évènement Outlook existe déjà pour l'action.
- **Ce que fait le job.**
  - Il relit l'état **actuel** de l'action (`CalendarEvents::find`), jamais une photo : les rejeux convergent.
  - Il s'exécute une fois à la fois par action (`WithoutOverlapping`, clé préfixée par l'organisme).
  - Il crée (`POST /me/events`), modifie (`PATCH`) ou supprime (`DELETE`).
  - En cas de réaffectation, il supprime l'évènement de l'agenda de l'ancien responsable et le crée chez le nouveau.
  - Un 404 en modification recrée l'évènement ; un 404 en suppression vaut « déjà supprimé ».
  - Doublons : l'identifiant d'évènement est conservé dans `calendar_link_events`. La création porte un `transactionId` enregistré avant l'appel, qu'un rejeu réutilise.
  - 429 / 5xx / délai dépassé : nouvel essai plus tard. Le job respecte `Retry-After` (au plus 1 h), sinon attend 30 s, 2 min, 10 min, 30 min puis 1 h. Il abandonne après 24 h.
- **Contenu envoyé.**
  - Titre : « type – fiche ».
  - Corps : fiche, opportunité, lieu et lien vers la fiche.
  - Heures locales avec `timeZone: Europe/Paris` ; la fin = début + durée exacte (60 min pour un rendez-vous, 15 min pour un appel par défaut).
  - **Jamais** le compte rendu ni l'objet saisi.
- **Réseau.** Seuls `login.microsoftonline.com` et `graph.microsoft.com` sont appelés, en https, sans suivre de redirection. `HostPolicy` strict est revérifié à chaque appel.
- **Journal.** `connector_runs` (sens `out`) contient « Action <id> : évènement Outlook créé / mis à jour / supprimé » et des erreurs rédigées par l'application (code HTTP). Il ne contient ni titre, ni fiche, ni adresse e-mail, ni texte de Microsoft. Une exécution en échec peut être relancée (`payload_ref` = `activity:<id>`).
- **Audit.** `calendar.outlook_connected`, `calendar.outlook_disconnected`, `calendar.outlook_reconnect_required`.

## Connecteur « Intelligence artificielle »

`AiConnector` (clé `ai`, type assistant, **organisme entier**, un seul actif). Sert à l'assistant de la fiche formation (proposition depuis un PDF ou une demande écrite) : l'IA **propose**, l'utilisateur relit et enregistre ; rien n'est enregistré par l'IA. Écran réservé à l'administrateur (`connectors.manage`).

| Réglage | Anthropic | Compatible OpenAI |
|---|---|---|
| Fournisseur (`provider`) | `anthropic` | `openai_compatible` (OpenAI, Mistral, serveur local Ollama / vLLM / LM Studio…) |
| Modèle (`model`) | facultatif : vide = `claude-opus-5-5` (`AnthropicClient::DEFAULT_MODEL`) ; liste proposée modifiable (`SUGGESTED_MODELS`) | **obligatoire** (ex. `gpt-4.1`, `mistral-large-latest`, `llama3.1:8b`) |
| Adresse (`base_url`) | — (`https://api.anthropic.com`, fixe ; adresse et case réseau local ni affichées, ni validées, ni enregistrées) | **obligatoire**, `…/v1` (ex. `https://api.openai.com/v1`) |
| Clé d'API (secret `api_key`) | obligatoire | facultative |
| « Serveur sur le réseau local » (`local_network`) | — | voir ci-dessous |

Les identifiants de modèle évoluent : l'administrateur peut saisir tout identifiant accepté par son fournisseur. Changer de fournisseur ou d'adresse impose de ressaisir la clé (`SERVER_SETTINGS`) : une clé enregistrée n'est jamais envoyée à un autre serveur.

### Service public `Connectors\Ai\Ai`

Les modules métier n'utilisent **que** ce service :

- `configured(): bool` : connecteur activé et complet (Anthropic : une clé ; compatible OpenAI : adresse valide et modèle).
- `structured(string $system, string $user, array $jsonSchema, string $purpose): array` : une requête dont la réponse doit être un objet JSON conforme au schéma. Le schéma complet est vérifié sur la réponse (`AiSchema::conform` : types — un nombre entier écrit `7.0` vaut l'entier `7` —, champs obligatoires, `enum`, longueurs, bornes, nombre d'éléments) et les clés non déclarées sont retirées ; les contraintes que les fournisseurs n'acceptent pas toutes (`maxLength`, `minimum`, `maxItems`…) ne sont pas envoyées (`AiSchema::sendable`) mais vérifiées ici. `$purpose` est un nom court (`[a-z0-9_.-]`, ex. `training.from_pdf`), journalisé.
- Erreurs (messages **fixes en français**, jamais le texte du fournisseur, affichables tels quels : `UserFacingHttpException`) :
  - `AiUnavailable` (503) : non configuré, clé refusée (401/403), demande refusée (autre 4xx : modèle inconnu…), adresse refusée, saturée (429), injoignable ou trop lente (5xx, 529, délai dépassé) ; `->retryable` vrai pour 429, 5xx et délai ;
  - `AiInvalidResponse` (502) : JSON cassé, pas un objet, non conforme au schéma, réponse tronquée (`max_tokens`, `length`) ou refusée (`refusal`). Rien n'est alors proposé.
- Limite : limiteur nommé **`ai`**, 20 demandes par heure, par utilisateur et par organisme (`RateLimitServiceProvider::AI_PER_HOUR`), à poser sur les routes qui appellent l'IA (`throttle:ai`, réponse 429 en JSON français).

### Protocoles

- **Anthropic** : `POST https://api.anthropic.com/v1/messages`, en-têtes `x-api-key` et `anthropic-version: 2026-10-21` ; sortie structurée par `output_config.format` (`json_schema`), lue dans les blocs `text`. Un appel d'outil forcé (`tool_choice`) n'est pas utilisé : les modèles actuels le refusent. Aucun paramètre d'échantillonnage (`temperature`…) n'est envoyé.
- **Compatible OpenAI** : `POST {base_url}/chat/completions`, `Authorization: Bearer` seulement si une clé est enregistrée ; `response_format` `json_schema` (`strict`) ; si le serveur le refuse (400/422), une seconde demande en `json_object`, le schéma étant alors donné dans le message système. Le contenu du message est décodé en JSON (bloc Markdown « ```json » toléré).
- Délai : **120 s** au plus (`AiClient::TIMEOUT_SECONDS`, une IA locale peut être lente), connexion 10 s ; réponse bornée à `MAX_OUTPUT_TOKENS` (16 000 ; Anthropic 32 000, raisonnement compris, avec `output_config.effort` = `low` : extraction structurée, plus rapide et moins coûteuse — un modèle saisi par l'administrateur doit accepter `effort`, comme les modèles proposés).

### Réseau : « Serveur sur le réseau local »

- **Réglage de la plateforme** `tms.connectors.allow_local_ai` (variable `TMS_ALLOW_LOCAL_AI`) : vrai par défaut en mode `single` (installation propre à un organisme), **faux en mode `multi`** (plateforme partagée : un administrateur d'organisme ne doit pas atteindre le réseau interne de l'hébergeur). Faux : la case n'est pas proposée, son enregistrement est refusé (« Les serveurs d'IA sur le réseau local ne sont pas autorisés sur cette plateforme. ») et un appel vers une configuration déjà cochée est refusé.
- **Case décochée** (par défaut) : `https://` obligatoire et l'hôte doit résoudre vers des adresses **publiques** uniquement (`HostPolicy` strict), à l'enregistrement et à chaque appel.
- **Case cochée** (avertissement affiché, plateforme qui l'autorise) : l'adresse peut être **privée** (RFC 1918, ULA) — **seulement** l'hôte et le port enregistrés (`AiEndpoint::guard` : tout autre hôte, port ou schéma est refusé), pour ce connecteur seulement (aucun réglage global). `http` n'est permis que si **toutes** les adresses de l'hôte sont privées (`https` vers une adresse publique reste possible).
- **Jamais**, même case cochée (`AiEndpoint::localViolation`) : le TMS lui-même — le serveur d'IA doit être une **autre machine** : `localhost`, boucle locale (`127.0.0.0/8`, `::1`), les services de la pile (`app`, `caddy`, `postgres`, `redis`, `gotenberg`, `worker`, `scheduler`, `vite`, `AiEndpoint::STACK_HOSTS`) par leur nom **et** par leurs adresses ; ni les adresses lien-local (dont `169.254.169.254`, métadonnées du cloud), non spécifiées, multicast et réservées (`HostPolicy::localNetworkViolation`).
- Dans tous les cas : redirections **non suivies** (une redirection = « adresse non autorisée »), connexion épinglée sur les adresses vérifiées (`HostPolicy::pinned`), réponse limitée à **2 Mo** (`AiClient::MAX_RESPONSE_BYTES` : transfert interrompu au-delà, `AiInvalidResponse`).

### Test et journal

- « Tester la connexion » : petite demande structurée (`{"ok": boolean}`) ; « Intelligence artificielle joignable (modèle …). » ou le message fixe de l'erreur.
- Journal (`connector_runs`, sens `out`) : une ligne par appel, résumé « objet · fournisseur · modèle · durée en ms · utilisateur <id> », statut succès/échec, erreur = message fixe. **Jamais** le contenu envoyé (texte du programme, demande) ni la réponse.

### Utilisation : assistant de la fiche formation

`Catalogue\Assistant` (le Catalogue n'importe que `Ai`, `AiUnavailable` et `AiInvalidResponse`, vérifié par `ModuleBoundariesArchTest`) :

- Routes `POST /catalogue/assistant/pdf` et `POST /catalogue/assistant/prompt` (`catalogue.manage`, `throttle:ai`), réponse JSON `{proposal, truncated}` ; rien n'est enregistré.
- Sans connecteur actif : 503 « Configurez l'intelligence artificielle dans Administration → Connecteurs. » (`TrainingAssistant::NOT_CONFIGURED`), avant toute lecture du fichier.
- `PdfText` : PDF de 10 Mo au plus, vérifié par son contenu (extension, type MIME lu dans les octets, signature `%PDF-`) ; texte lu par **`pdftotext`** (paquet `poppler-utils` de l'image, étape `base` du `docker/app.Dockerfile`, donc en développement comme en production), 300 pages et 30 s au plus ; texte nettoyé puis coupé à **60 000 caractères** (`PdfText::MAX_CHARS`) ; le fichier téléversé est supprimé après lecture, quoi qu'il arrive. Un PDF sans texte (scan) ou illisible est refusé (422, message sur `file`).
- Demande écrite : 2 000 caractères au plus (`TrainingAssistant::MAX_PROMPT`).
- `TrainingDraft::schema()` : schéma imposé (textes 5 000 caractères, intitulé 200, objectifs ≤ 20 de 500 caractères, modules ≤ 30, durées 0 < h ≤ 2 000) ; une réponse hors schéma est refusée entière (`AiInvalidResponse`). `TrainingDraft::proposal()` ramène le DAS et les modalités à l'id d'une valeur **active** du référentiel de l'organisme (même libellé aux accents et à la casse près, ou libellé avant la parenthèse), sinon vide. Le texte de l'utilisateur est balisé et présenté comme une donnée, jamais comme des instructions.
- Objets journalisés : `training.from_pdf`, `training.from_prompt`.

### Tests de bout en bout : IA hors ligne

La pile e2e (`compose.e2e.yaml`) pose `TMS_E2E_FAKE_AI=true` (`tms.connectors.e2e_fake_ai`) : `ConnectorsServiceProvider::e2eAiClient()` remplace alors le client Anthropic par `tests/Support/E2eAiClient` (réponse fixe, aucun appel réseau ; le journal et la vérification du schéma restent ceux du vrai service). Ignoré en production, et la classe n'existe pas dans l'image de production (`tests/` est en `autoload-dev`). Ne jamais poser cette variable sur une installation.
