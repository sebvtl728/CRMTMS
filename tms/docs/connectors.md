# Connecteurs

Tout logiciel externe (Formevol, Sage, SoWeSign, Outlook, serveur SMTP…) passe par un **connecteur** : un adaptateur isolé dans `app/Modules/Connectors/`, jamais codé en dur dans le métier. Le métier appelle le connecteur ; le connecteur seul connaît le protocole du logiciel.

## Briques fournies (`app/Modules/Connectors/Core`)

| Élément | Rôle |
|---|---|
| `Connector` | Contrat à implémenter (clé, libellé, type, règles des paramètres, secrets, test de connexion). |
| `ConnectorKind` | `import`, `export`, `sync`, `delivery`. |
| `ConnectorRegistry` | Liste des connecteurs connus (`register`, `all`, `make`). |
| `ConnectorConfig` | Configuration d'un connecteur pour un organisme, ou une société (`company_id`). Secrets chiffrés. |
| `ConnectorRun` | Journal d'exécution (`ConnectorRun::track`). |
| `Retryable` (optionnel) | Le connecteur sait rejouer une exécution en échec. |
| `HasFormLabels` (optionnel) | Libellés français des champs du formulaire d'administration. |

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

`TenantMailer::for(?Company $company)` renvoie le mailer à utiliser : configuration active de la société, sinon de l'organisme, sinon mailer par défaut de l'instance (`MAIL_*`). 
### Politique réseau des hôtes SMTP (`SmtpHostPolicy` → `Core\HostPolicy`)

La politique est généralisée dans `App\Modules\Connectors\Core\HostPolicy` (résolution A/AAAA, classification, `violation($ips, strict: false)`) ; `SmtpHostPolicy` n'en est plus qu'une façade (comportement SMTP inchangé). Avec `strict: true`, les réglages `allow_*` sont ignorés : seules les adresses publiques passent (utilisé par Sirene).

Appliquée à l'enregistrement (règle `SmtpHost`, qui résout l'hôte en A/AAAA) **et** à chaque envoi (`SmtpTransportFactory::make`, donc aussi `TenantMailer`) :

- Format : nom ou adresse seuls, sans schéma, port, chemin ni raccourci numérique (`127.1`, `2130706433`).
- **Toujours refusés** : boucle locale, lien local (dont `169.254.169.254`, métadonnées cloud), adresse non spécifiée, multicast / réservées, espace partagé `100.64.0.0/10`, et leurs formes IPv4 intégrées en IPv6 (`::ffff:127.0.0.1`, `::127.0.0.1`, NAT64) ; `localhost` et tout nom qui résout vers l'une d'elles. Exception : `TMS_ALLOW_INTERNAL_SMTP_HOSTS=true` (capteur de mails en développement uniquement).
- **Réseaux privés** (RFC 1918, IPv6 ULA) : autorisés en mode `single` (relais sur le réseau local d'un client auto-hébergé), refusés en mode `multi`. Réglable par `TMS_ALLOW_PRIVATE_SMTP_HOSTS`.
- Un nom qui ne résout pas est accepté à l'enregistrement mais refusé au test et à l'envoi (message français).
- Les messages d'erreur du test sont toujours rédigés par l'application ; le texte brut du serveur (qui peut contenir l'identifiant) n'est jamais relayé.
- Risque résiduel connu : le « DNS rebinding » (un nom qui change d'adresse entre la vérification et la connexion).

## Connecteur Sirene (préremplissage par SIRET)

`SireneConnector` (clé `sirene`, type import, aucun secret). Appelle l'API publique « Recherche d'entreprises » (`GET {base_url}/search?q=<siret>&per_page=1`, sans clé, vérifiée en novembre 2026). Réglage unique : `base_url` (vide = `https://recherche-entreprises.api.gouv.fr`). **Aucune configuration n'est nécessaire** : la première recherche d'un organisme crée sa configuration, activée ; un administrateur peut la désactiver ou changer l'adresse dans `/admin/connectors`.

- Route : `GET /crm/sirene/{siret}` (droit `crm.create` ou `crm.update`, 30 requêtes/minute/utilisateur). 422 si le SIRET n'a pas 14 chiffres ou échoue au contrôle Luhn ; 404 « Aucune entreprise trouvée pour ce SIRET. » ; 503 « Service de l'annuaire indisponible, saisissez les informations manuellement. » (API en panne, délai de 5 s dépassé, réponse illisible, connecteur désactivé).
- Le formulaire d'entreprise propose « Compléter depuis le SIRET » (SIRET valide) : seuls les champs **vides** sont remplis, la liste des champs complétés est affichée, l'utilisateur valide en enregistrant.
- Adresse appelée : `base_url` doit être `https://` + hôte seul (ni identifiant, port, chemin) résolvant **uniquement vers des adresses publiques** (`HostPolicy` strict : aucun réglage ne permet un hôte local ou privé). Revérifié à chaque appel ; redirections non suivies.
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
