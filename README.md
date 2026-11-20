# TMS sur mesure

Logiciel de gestion d'organisme de formation (CRM, catalogue, devis, sessions, dossiers, facturation, admissions,
Qualiopi, documents). Application dans `tms/`. Documentation : `tms/docs/` (installation, administration, guides
utilisateur).

## Lancer le TMS en local (Mac)

Le TMS local tourne dans Docker, avec le moteur **Colima**. C'est un **bac à sable** : ce qu'on y saisit reste sur le Mac
et ne part jamais sur le serveur de production.

### Démarrer (chaque jour, ou après une mise en veille ou un redémarrage du Mac)

1. Démarrer le moteur Docker :

   ```bash
   colima start
   ```

2. Démarrer l'application :

   ```bash
   cd ~/Desktop/CRM/tms && docker compose up -d
   ```

3. Vérifier qu'elle répond (doit afficher `200`) :

   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/health
   ```

4. Ouvrir le TMS :
   - sur le Mac : <http://localhost:8080>
   - depuis un autre poste du réseau local : <http://MacBook-Pro-de-Sebvtl.local:8080>

### Redémarrer

```bash
cd ~/Desktop/CRM/tms && docker compose restart
```

Si une commande `docker` répond « failed to connect to the docker API », le moteur est arrêté : relancer
`colima start`, puis `docker compose up -d`.

### Arrêter

```bash
cd ~/Desktop/CRM/tms && docker compose stop
```

```bash
colima stop
```

### Après une nouvelle version du code (branche récupérée)

Appliquer les migrations, accorder les nouveaux droits et reconstruire les écrans :

```bash
cd ~/Desktop/CRM/tms && docker compose exec app php artisan migrate --database=pgsql_owner
```

```bash
cd ~/Desktop/CRM/tms && docker compose exec app php artisan tms:permissions:sync
```

```bash
cd ~/Desktop/CRM/tms && docker compose run --rm vite sh -c "npm run build"
```

```bash
cd ~/Desktop/CRM/tms && docker compose exec app php artisan optimize:clear
```

### Bac à sable et démonstration

- **Remettre à zéro** : Administration › Bac à sable (effacer les données de travail, ou repartir de zéro ; organisme et
  société deviennent fictifs). Disponible seulement sur le Mac (`TMS_SANDBOX=true` et `APP_ENV=local` dans
  `tms/.env`), jamais sur le serveur.
- **Réseau local** : `APP_URL=http://MacBook-Pro-de-Sebvtl.local:8080` dans `tms/.env`, pour que les liens envoyés par
  e-mail (signature en ligne, tests de positionnement) s'ouvrent depuis les autres postes.

### Première installation sur un nouveau poste

Voir `tms/docs/install.md`, section « Développement » (fichier `.env`, clés, base de données, comptes).

## Serveur de production

Installation et mises à jour : `tms/docs/install.md`. Mise à jour d'un serveur installé :

```bash
sudo /opt/tms/ops/update.sh
```
