# Déploiement MedVue — production

> Décrit comment **MedVue spécifiquement** est déployé sur le serveur
> partagé documenté dans [`docs/server-production.md`](server-production.md)
> (règles d'isolation, conventions Traefik/DNS, applications à ne jamais
> toucher). Adapté de
> [`docs/deployment-instructions-template.md`](deployment-instructions-template.md).

## 0. Constantes

- `<APP_NAME>` = `medvue`
- `<DOMAIN>` = `medvue.be` / `www.medvue.be` (frontend), `api.medvue.be` (backend)
- `<SERVER_HOST>` / `<SERVER_USER>` = alias SSH `surgicalhub-prod` (pointe
  vers le VPS partagé, pas seulement SurgicalHub), user `deploy`
- `<DEPLOY_PATH>` = `/opt/stack/apps/medvue`
- `<BACKEND_DIR>` / `<FRONTEND_DIR>` = `backend/`, `frontend/`
- `<MIGRATION_CMD>` = `php bin/console doctrine:migrations:migrate --no-interaction`
- `<CACHE_CLEAR_CMD>` = `php bin/console cache:clear`
- Conteneurs : `medvue-database`, `medvue-backend`, `medvue-frontend`

## 1. Séquence de déploiement (premier déploiement)

1. Snapshot "avant" des 3 autres apps du serveur (§10 de
   `server-production.md`).
2. `git archive --format=tar.gz HEAD > /tmp/medvue_deploy_<timestamp>.tar.gz`
   puis `scp` vers `<SERVER_HOST>:/tmp/`.
3. Sur le serveur : `mkdir -p /opt/stack/apps/medvue`, extraction de
   l'archive dedans.
4. Créer `.env` réel (à partir de `.env.prod.example`, valeurs générées
   sur le serveur, jamais réutilisées depuis `backend/.env` de dev),
   `chmod 600 .env`.
5. `docker compose -f docker-compose.prod.yml build --no-cache` puis
   `up -d`.
6. `docker compose -f docker-compose.prod.yml exec -T backend php bin/console doctrine:migrations:migrate --dry-run`
   → relecture manuelle du SQL → puis la commande réelle
   (`--no-interaction`).
7. `docker compose -f docker-compose.prod.yml exec -T backend php bin/console lexik:jwt:generate-keypair --skip-if-exists`
   (clés RSA prod, jamais celles de dev).
8. `<CACHE_CLEAR_CMD>` puis `docker compose -f docker-compose.prod.yml restart backend`.
9. Vérifier les logs Traefik pour la génération des certificats
   Let's Encrypt des deux routeurs (`medvue-web`, `medvue-api`).
10. Checks de santé (§2).
11. Snapshot "après" des 3 autres apps, comparé au "avant" — zéro
    changement attendu (§10 de `server-production.md`).
12. Tag annoté `v<YYYY.MM.DD>-prod`, push sur `origin`.

## 2. Checks de santé obligatoires

- `https://www.medvue.be` → `200`.
- `https://api.medvue.be/api/health` → `{"status":"ok","database":"ok"}`.
- `https://api.medvue.be/.env`, `/config/jwt/*` → non exposés (`404`).
- Inscription + connexion réelles via `curl` contre le domaine public →
  token valide, cookie refresh `Secure` (vérifier `COOKIE_SECURE=true`
  effectif).
- `GET /api/me` avec ce token → utilisateur correct.
- `medvue-database`, `medvue-backend`, `medvue-frontend` tous `Up`.
- `medvue-database` injoignable depuis un conteneur d'une autre app
  (`docker exec surgicalhub-php sh -c "getent hosts medvue-database"`
  doit échouer).
- Logs backend sans erreur critique liée au déploiement.

## 3. Déploiement applicatif courant (mises à jour suivantes)

```bash
cd /opt/stack/apps/medvue
git archive ... # reconstruire l'artefact depuis un HEAD local propre, jamais depuis le serveur
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec -T backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f docker-compose.prod.yml exec -T backend php bin/console cache:clear
docker compose -f docker-compose.prod.yml restart backend
```

Toujours précédé d'un rapport d'écart si le serveur a plus d'un commit de
retard (jamais de déploiement partiel), et des mêmes checks de santé
qu'au premier déploiement.

## 4. Rollback

Restaurer le dump Postgres le plus récent (`medvue_db_data`), redéployer
l'archive du commit précédemment taggé, rebuild `--no-cache` + `up -d`.
Ne jamais taguer un état de rollback.

## 5. Backups

Script sibling `/opt/stack/backups/scripts/backup-postgres.sh` (créé
directement sur le serveur, même convention que `backup-mysql.sh` :
dump compressé, rotation locale, copie `rclone` vers le même remote sous
un préfixe `medvue/`) — sans toucher aux scripts/crontab MySQL existants.

## 6. Historique des déploiements

| Date | Tag | Commit | Notes |
|---|---|---|---|
| _(à compléter après le premier déploiement)_ | | | |
