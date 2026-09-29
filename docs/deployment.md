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
- Conteneurs : `medvue-database`, `medvue-backend`, `medvue-worker` (D149), `medvue-frontend`

## 1. Séquence de déploiement (premier déploiement)

1. Snapshot "avant" des 3 autres apps du serveur (§10 de
   `server-production.md`).
2. Archive **depuis Git uniquement, jamais depuis le working tree**, en LF :
   `git -c core.autocrlf=false archive --format=tar.gz -o /tmp/medvue_deploy_<timestamp>.tar.gz <COMMIT>`.
   Sous Windows, le `core.autocrlf=true` du gitconfig *système* de Git for
   Windows est appliqué par `git archive` et livre des fichiers en CRLF
   (incident du 2026-09-20, §8) : l'override par commande suffit, ne pas
   modifier la configuration globale. Avant envoi, vérifier que chaque
   fichier extrait est identique à son blob
   (`git hash-object --no-filters <fichier>` == SHA de `git ls-tree -r <COMMIT>`)
   et qu'aucun octet CR n'est présent (`docker-compose.prod.yml`,
   `.env.prod.example`, `backend/bin/*.py`, `scripts/**/*.sh`). Puis `scp`
   vers `<SERVER_HOST>:/tmp/` et comparer le `sha256sum` des deux côtés.
3. Sur le serveur : `mkdir -p /opt/stack/apps/medvue`, extraction de
   l'archive dedans.
4. Créer `.env` réel (à partir de `.env.prod.example`, valeurs générées
   sur le serveur, jamais réutilisées depuis `backend/.env` de dev),
   `chmod 600 .env`. Remplacer aussi `SYMFONY_TRUSTED_PROXIES=<TRAEFIK_PROXY_IP>`
   par l'adresse **exacte** de Traefik sur le réseau `proxy`
   (`docker inspect traefik --format '{{(index .NetworkSettings.Networks "proxy").IPAddress}}'`),
   jamais un sous-réseau (D108, §2).
5. `docker compose -f docker-compose.prod.yml build --no-cache` puis
   `up -d`.
6. Migrations — **jamais d'exécution avant relecture du SQL** :
   1. `docker exec medvue-backend php bin/console doctrine:migrations:status`
   2. produire le SQL **sans l'exécuter** :
      `docker exec medvue-backend php bin/console doctrine:migrations:migrate --dry-run --write-sql=/tmp/medvue_migrations.sql --no-interaction`.
      Attention : `--dry-run` **seul** n'imprime pas le SQL (il n'affiche que
      « N migrations, M requêtes »), et `--write-sql` **seul** l'écrit
      **et l'exécute** (incident n°2, §8). Les deux variantes créent la
      table vide d'historique `doctrine_migration_versions`, sans danger.
   3. relire `docker exec medvue-backend cat /tmp/medvue_migrations.sql` et
      signaler explicitement toute instruction `DROP`, `DELETE`,
      `TRUNCATE`, `UPDATE` ou `ALTER … DROP`. Sur une base **non vide**,
      s'arrêter et faire valider avant d'aller plus loin (sur la base
      vierge du premier déploiement, ceux de `Version20260917091305`
      portaient sur des tables encore vides).
   4. seulement ensuite : `docker exec medvue-backend php bin/console doctrine:migrations:migrate --no-interaction`,
      puis `doctrine:migrations:up-to-date` (doit répondre « Up-to-date »).
   5. `doctrine:schema:validate` répondra « not in sync » : attendu (D051,
      contraintes SQL écrites à la main). Ne **jamais** appliquer
      `schema:update`, cela annulerait le durcissement.
7. `docker exec medvue-backend php bin/console lexik:jwt:generate-keypair --skip-if-exists`
   (clés RSA prod, jamais celles de dev). Les clés vivent dans le volume
   nommé `medvue_jwt_keys` monté sur `/app/config/jwt` : elles survivent à
   un `restart`, un recreate ou un rebuild du backend, et cette commande
   reste idempotente (`--skip-if-exists`) à chaque déploiement suivant
   (D107). Lexik crée `private.pem` en `644` : la resserrer aussitôt
   (`docker exec medvue-backend chmod 600 /app/config/jwt/private.pem`,
   le process est root), puis `lexik:jwt:check-config`. Utiliser
   `docker exec` (sans `-i`) dans un script `ssh ... <<EOF` : un
   `docker compose exec -T` y consomme le reste du script via stdin.
8. `<CACHE_CLEAR_CMD>` puis `docker compose -f docker-compose.prod.yml restart backend worker`
   (le worker, §5 ter, a pu redémarrer en boucle tant que la table
   `messenger_messages` n'existait pas : c'est attendu avant les migrations).
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
- Clés JWT persistantes (D107) : empreinte de `public.pem` identique avant
  et après `docker compose -f docker-compose.prod.yml up -d --force-recreate backend`
  (`docker exec medvue-backend sha256sum /app/config/jwt/public.pem`, sans
  jamais afficher la clé), `lexik:jwt:check-config` OK, et un `login` réel
  fonctionne toujours après la recréation.
- `medvue-database`, `medvue-backend`, `medvue-worker`, `medvue-frontend` tous `Up`
  (le worker **stable**, pas en boucle de redémarrage :
  `docker inspect medvue-worker --format '{{.RestartCount}}'` ne doit pas
  augmenter entre deux lectures).
- Worker (D149) : `docker logs --tail 50 medvue-worker` montre
  `Consuming messages from transport "planning_jobs"` ; une génération
  réelle lancée depuis l'interface passe `QUEUED → RUNNING → SUCCEEDED`
  (`GET /api/plannings/{id}/jobs/latest`), et
  `docker exec medvue-backend php bin/console messenger:failed:show` est vide.
- Cron du samedi installé (§5 bis) : `crontab -l | grep duty-reminders`.
- `medvue-database` injoignable depuis un conteneur d'une autre app
  (`docker exec surgicalhub-php sh -c "getent hosts medvue-database"`
  doit échouer).
- IP client réelle derrière Traefik (D108) : `scripts/deploy/check-trusted-proxy.sh`
  → `OK` (`SYMFONY_TRUSTED_PROXIES` du `.env` et du backend en marche == IP
  actuelle de Traefik). Puis une vraie connexion via le domaine public doit
  enregistrer l'IP publique du client dans `refresh_tokens.created_by_ip`
  (jamais celle de Traefik), et deux clients distincts ne partagent plus les
  compteurs d'inscription/de login. À refaire après tout recreate de Traefik
  ou reboot du serveur : une valeur périmée échoue en sécurité (pas
  d'usurpation possible, seulement des compteurs de nouveau partagés).
- Sauvegardes (D109) : `scripts/backup/medvue-backup.sh` réussit, puis
  `scripts/backup/medvue-restore-test.sh --compare-live` répond
  `RESTORE TEST: PASS`, et l'entrée cron quotidienne est installée
  (`docs/backup.md`).
- Logs backend sans erreur critique liée au déploiement.

## 3. Déploiement applicatif courant (mises à jour suivantes)

```bash
# Artefact LF depuis un HEAD local propre (§1 étape 2), jamais depuis le serveur.
# Extraction PAR-DESSUS /opt/stack/apps/medvue : le `.env` et les volumes ne
# sont jamais supprimés (n'effacer que les fichiers suivis absents de la
# nouvelle archive).
cd /opt/stack/apps/medvue
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d --no-build
# Migrations : séquence complète du §1 étape 6 (status, dry-run + write-sql,
# relecture, puis migrate). Jamais `migrate` sans relecture préalable.
docker exec medvue-backend php bin/console cache:clear
docker exec medvue-backend php bin/console lexik:jwt:generate-keypair --skip-if-exists   # idempotent
docker compose -f docker-compose.prod.yml restart backend worker
scripts/deploy/check-trusted-proxy.sh
```

`up -d` recrée aussi `medvue-worker` avec la nouvelle image : Docker lui
envoie SIGTERM, il **termine le calcul en cours** puis s'arrête (au plus
`stop_grace_period: 15m`), et le nouveau démarre. Pour éviter d'attendre,
déployer quand `GET /api/plannings/{id}/jobs/latest` ne montre aucun job
actif — sinon, rien n'est perdu : un calcul coupé au-delà du délai devient
`FAILED` (`worker_lost`) et le gestionnaire le relance (§5 ter).

Toujours précédé d'un rapport d'écart si le serveur a plus d'un commit de
retard (jamais de déploiement partiel), et des mêmes checks de santé
qu'au premier déploiement.

Les volumes `medvue_db_data` et `medvue_jwt_keys` ne sont **jamais**
supprimés par un déploiement : pas de `docker compose down -v`, pas de
`docker volume rm`. `JWT_PASSPHRASE` (`.env`) et le contenu de
`medvue_jwt_keys` forment un couple : ne jamais régénérer la passphrase sans
régénérer aussi les clés (supprimer `private.pem`/`public.pem` du volume puis
`lexik:jwt:generate-keypair`), sinon la signature des tokens échoue.

## 4. Rollback

Restaurer le dump Postgres le plus récent (`medvue_db_data`), redéployer
l'archive du commit précédemment taggé, rebuild `--no-cache` + `up -d`.
Le volume `medvue_jwt_keys` est conservé tel quel (les sessions restent
valides). Ne jamais taguer un état de rollback.

## 5. Sauvegardes

Mécanisme, rétention, procédures de restauration et limites :
[`docs/backup.md`](backup.md) (D109). En bref : `scripts/backup/medvue-backup.sh`
(cron quotidien) sauvegarde la base PostgreSQL et le volume `medvue_jwt_keys`
sous `/home/deploy/backups/medvue/` ; `scripts/backup/medvue-restore-test.sh`
prouve qu'une sauvegarde se restaure, dans une cible jetable. Le `.env`
(dont `JWT_PASSPHRASE`) n'est **pas** sauvegardé avec les clés : il doit être
conservé séparément, hors serveur. Les sauvegardes des autres applications
(`/home/deploy/scripts/*`, crontab existant) ne sont pas modifiées.

## 5 bis. Rappel hebdomadaire des gardes (samedi, D146)

`app:duty-reminders:weekly` envoie à chaque personne **ayant au moins une
garde** la semaine suivante (lundi → dimanche dans le fuseau de chaque
planning, lignes publiées uniquement) le récapitulatif de ses gardes. Aucun
email pour quelqu'un sans garde. Idempotent (`weekly_duty_reminders`) : le
relancer le même samedi n'envoie rien deux fois ; un envoi échoué n'écrit
rien et sera retenté au passage suivant (code de sortie ≠ 0 si un envoi a
échoué).

À ajouter **une seule fois** au crontab de `deploy` (même précautions que
les sauvegardes, `docs/backup.md` : copie de sécurité du crontab, ne rien
toucher d'autre). Le crontab existant change plusieurs fois de `CRON_TZ` (la dernière
valeur, celle de la sauvegarde MedVue, est `UTC`) : l'entrée est donc précédée de son
propre `CRON_TZ=Europe/Brussels`, placé en fin de crontab. L'heure exacte importe peu (la semaine est calculée dans le fuseau du
planning, jamais celui du serveur), il suffit que ce soit un samedi :

```bash
CRON_TZ=Europe/Brussels
0 8 * * 6 cd /opt/stack/apps/medvue && docker compose -f docker-compose.prod.yml exec -T backend php bin/console app:duty-reminders:weekly >> /home/deploy/backups/medvue/weekly-reminders.log 2>&1
```

Rejouer un samedi manqué : `... app:duty-reminders:weekly --now="<samedi> 08:00 Europe/Brussels"`.

## 5 ter. Worker des calculs de planning (D149)

« Générer le planning » et « Compléter automatiquement » ne sont **plus
exécutés dans la requête HTTP** : la requête enregistre un `PlanningJob`
(`QUEUED`) et un message, répond `202`, et le conteneur `medvue-worker`
exécute le calcul OR-Tools, aussi long soit-il.

| Sujet | Mise en œuvre |
|---|---|
| Transport | Symfony Messenger, transport Doctrine sur la **même base PostgreSQL** (`MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`), file `planning_jobs` ; table `messenger_messages` créée par migration (`Version20260927170000`), jamais par le transport. Aucun service supplémentaire (ni Redis, ni RabbitMQ). |
| Processus | Service `worker` de `docker-compose.prod.yml` (conteneur `medvue-worker`, même image que le backend) : `php bin/console messenger:consume planning_jobs --time-limit=3600 --memory-limit=512M -v`. Jamais lancé à la main dans un terminal. |
| Redémarrage automatique | `restart: unless-stopped` : relancé par Docker après un crash, après `--time-limit` (recyclage horaire volontaire) et **après un reboot du serveur** (le démon Docker redémarre les conteneurs `unless-stopped`). |
| Healthcheck | `php bin/console messenger:stats planning_jobs` toutes les 60 s (le conteneur atteint PostgreSQL et lit sa file) ; `docker ps` doit montrer `medvue-worker` `(healthy)`. Le consommateur est le PID 1 : s'il meurt, Docker redémarre le conteneur. |
| Arrêt propre | `pcntl` est installé dans l'image : sur SIGTERM (`up -d`, `stop`, `restart`), le worker termine le message en cours puis s'arrête ; `stop_grace_period: 15m`. |
| Retry | **Aucun** (`max_retries: 0`) : un calcul qui échoue est enregistré `FAILED` par le handler lui-même, avec un code stable, et le gestionnaire le relance explicitement. Le transport `failed` ne reçoit qu'un message dont le handler aurait levé une exception avant de pouvoir l'enregistrer (bug) : `messenger:failed:show` / `messenger:failed:retry`. |
| Jobs abandonnés | Un job `RUNNING` dont le battement (`heartbeat_at`, rafraîchi toutes les 10 s même pendant la résolution CP-SAT) date de plus de 5 min, ou un job `QUEUED` depuis plus de 30 min, est passé `FAILED` (`worker_lost` / `never_started`) et ses générations `SOLVING` deviennent `FAILED` — au démarrage du worker, à chaque lecture de l'état du job et avant toute nouvelle demande. Manuel : `docker exec medvue-backend php bin/console app:planning-jobs:recover`. La durée d'un calcul n'est **jamais** un critère d'échec. Une génération `SOLVING` orpheline (plus de 15 min sans job `RUNNING` sur son planning — requête synchrone technique coupée) est aussi passée `FAILED`. |
| Horloge | Tous les horodatages de jobs viennent de PostgreSQL (`LOCALTIMESTAMP`, session UTC), jamais de PHP : API et worker comparent la même horloge. |
| Logs | `docker logs medvue-worker` (sortie de `messenger:consume -v` + erreurs `PlanningJob … failed`). Le détail technique d'un échec est aussi conservé en base (`planning_jobs.failure_detail`), jamais exposé à l'API. |
| Code | Un process PHP long garde le code avec lequel il a démarré : après tout déploiement, `restart backend worker` (§3) — le recyclage horaire le garantit de toute façon. |

Vérifier après chaque déploiement (§2) : worker stable, une génération réelle
aboutit, `messenger:failed:show` vide.

## 6. Historique des déploiements

| Date | Tag | Commit | Notes |
|---|---|---|---|
| 2026-09-20 | `v2026.09.20-prod` | `e15dda4` | Premier déploiement (incidents : §8). **Dérogation à la règle « tag uniquement si tout est vert » : ce tag a été poussé alors que le job backend de GitHub Actions était encore rouge** (cause alors non identifiée, voir §8 points 8 et 9). Le tag est conservé tel quel : il désigne fidèlement le commit réellement mis en production (arbre du serveur vérifié fichier par fichier). Il ne doit être ni supprimé, ni déplacé, ni recréé. |
| 2026-09-20 | `v2026.09.20-prod-2` | `6a1d389` | **Production validée.** CI GitHub Actions : backend + frontend verts (run `35499282274`). Correctif CI JWT (paire de clés générée avant les tests, §8 point 9), redéploiement du HEAD complet (archive vérifiée fichier par fichier, backend reconstruit et recréé, clés JWT inchangées), 66/66 checks publics. Aucun changement de code applicatif par rapport à `v2026.09.20-prod`. Ce tag remplace, comme référence de production, le précédent, qui reste en place avec sa dérogation. |

| 2026-09-21 | `v2026.09.21-prod` | `2f17438` | Refonte de l'interface + calendrier d'indisponibilités en jour entier (D117-D119). **Déploiement frontend seul** : aucun diff sur `backend/`, `docker-compose.prod.yml`, `scripts/`, `.github/` depuis `81e0434` (`v2026.09.20-prod-4`) → aucune migration, backend et base non recréés (clés JWT inchangées). Écart vérifié avant : le serveur était identique à `81e0434` (sha256 de 307 fichiers suivis). Archive LF vérifiée blob par blob, sha256 identique des deux côtés, extraite par-dessus (`.env` intact), 2 fichiers supprimés retirés (`dateUtils*`), seule l'image `medvue-frontend` reconstruite et recréée. CI verte avant le déploiement (run `35562767581`, backend + frontend). Snapshots avant/après des autres applications, réseaux, volumes et Traefik : aucune différence. Checks : `www.medvue.be` (`/`, `/login`, `/my-availability`, `/invitations/…`) 200, `api…/api/health` ok, `/.env` et `/config/jwt/public.pem` 404, bundle et police Inter servis. Non refaits (backend inchangé) : inscription/connexion réelles, IP client, sauvegarde/restauration. Les tags `-3` et `-4` du 2026-09-20 ne figurent pas dans ce tableau. |
| 2026-09-21 | `v2026.09.21-prod-2` | `dbde422` | Collecte des disponibilités par fenêtre, prolongation d'un planning, participation du créateur, planning par personne, calendrier optimiste (D120-D126). **Backend + frontend + 1 migration** (`Version20260921053604`, uniquement `CREATE TABLE`/`CREATE INDEX`/`ADD CONSTRAINT` : SQL relu avant exécution, aucun `DROP`/`DELETE`/`TRUNCATE`/`UPDATE`). Écart vérifié avant : serveur identique à `2f17438` (sha256 de 501 fichiers suivis, seuls `.env` et `.env.bak_pre_invitations` en plus). CI verte avant le déploiement (run `35632931782`). Sauvegarde PostgreSQL + clés JWT faite juste avant la migration. Archive LF vérifiée blob par blob, sha256 identique des deux côtés, extraite par-dessus (`.env` intact), aucun fichier supprimé, images `medvue-backend`/`medvue-frontend` reconstruites et recréées, base et clés JWT non recréées (empreinte de `public.pem` identique avant/après). Checks : `www.medvue.be` (`/`, `/my-availability`) 200, `api…/api/health` ok, `/.env` et `/config/jwt/public.pem` 404, nouveaux endpoints 401 sans jeton, inscription + connexion réelles (cookie refresh `Secure`, `/api/me` expose `stableId`), scénario complet sur un compte jetable (planning `includeMe`, collecte initiale, prolongation limitée à la nouvelle tranche, `422` sur plage identique) puis suppression ciblée de ces seules données (production revenue à 5 comptes, 1 planning, 0 collecte), `check-trusted-proxy.sh` OK, sauvegarde + `restore-test --compare-live` PASS. Snapshots avant/après des autres applications, réseaux, volumes et Traefik : aucune différence. Un `404` transitoire de `api.medvue.be` juste après le redémarrage du backend (Traefik n'expose le conteneur qu'une fois `healthy`, ~40 s) : attendu, à prévoir avant de lancer les checks. |
| 2026-09-23 | `v2026.09.23-prod` | `f5e6820` | Pilotage OWNER/ADMIN d'un planning : statut de collecte par membre, rappels email individuels/groupés (audit append-only), `availabilityDeadline` informative, préflight + lancement de génération au niveau du planning (façade sur le pipeline existant, D127-D129). **Backend + frontend + 1 migration** (`Version20260921140000`, `planning_availability_reminders` — `CREATE TABLE`/`CREATE INDEX`/`ADD CONSTRAINT`/`CREATE FUNCTION`/`CREATE TRIGGER` (append-only) uniquement, SQL relu avant exécution, aucun `DROP`/`DELETE`/`TRUNCATE`/`UPDATE`). Écart vérifié avant, avec certitude (SSH accessible, contrairement à une première tentative bloquée par le pare-feu réseau du poste de déploiement — non un problème serveur) : serveur identique à `dbde422` (sha256 de 553 fichiers suivis). Sauvegarde PostgreSQL + clés JWT faite avant la migration ; archive du code alors déployé conservée séparément (`/home/deploy/backups/medvue/code-pre-deploy/medvue_deployed_dbde422_20260923_081226.tar.gz`). Artefact LF depuis `git archive f5e6820` vérifié blob par blob (601 fichiers, sha256 identique des deux côtés) — jamais depuis le working tree local, qui portait deux éléments hors lot (`backend/config/reference.php`, `.deploy-snapshots/`) délibérément exclus. Aucun fichier supprimé entre `dbde422` et `f5e6820`. Images `medvue-backend`/`medvue-frontend` reconstruites (`build`, sans `--no-cache` : mise à jour applicative courante, pas un premier déploiement) et recréées, base et clés JWT non recréées (empreinte de `public.pem` identique avant/après restart backend). Checks : `www.medvue.be` (`/`, `/login`, `/my-availability`) 200, `api…/api/health` ok, `/.env` et `/config/jwt/public.pem` 404, inscription + connexion réelles (cookie refresh `Secure`, `/api/me` correct), refresh de session OK, `check-trusted-proxy.sh` OK, sauvegarde + `restore-test --compare-live` PASS (après une seconde sauvegarde post-migration ; la première comparaison avait légitimement échoué, la sauvegarde datant d'avant la migration). Smoke test ciblé du lot sur un compte jetable via le domaine public : ouverture d'un planning OWNER (`includeMe`), panneau de pilotage et statut de collecte, drawer d'un membre, paramètres (`availabilityDeadline`), préflight de génération — tous corrects ; génération complète non testée (aucune règle active ni garde pour une équipe neuve, non créables via API publique, cf. limite ci-dessous) ; aucun rappel réel envoyé (aucun mode de capture/safe-mode documenté pour cette fonctionnalité en production, cf. limite ci-dessous). Suppression ciblée de ces seules données de test (production revenue à 1 planning, 13 comptes). Logs backend/frontend/Traefik propres. Aucun worker/consumer dans ce projet. Snapshots des 8 conteneurs des 3 autres applications du serveur : tous `Up`, aucun redémarré ; `medvue-database` toujours injoignable depuis une autre application. **Limites assumées** : (1) la génération complète du lot n'a pas été testée en production faute de pouvoir créer un `PlanningRuleSet`/`Duty` via l'API publique sans toucher directement à la base de production — seul le préflight (bloquants `NO_ACTIVE_RULE_SET`/`NO_DUTIES` corrects) a été validé ; (2) l'envoi de rappel n'a pas été testé en production, aucun mécanisme de capture/safe-mode documenté n'existant pour les emails de ce lot — validé uniquement par les tests automatisés et l'UAT de l'environnement de développement. |
| *(non taguée, découverte le 2026-09-25)* | — | `2f5663c` | **Déploiement rétroactivement constaté, jamais documenté ni taggé.** Kit PWA et marque officielle (D135). Découvert au tout début du déploiement du 2026-09-25 : la vérification d'écart habituelle (sha256 des fichiers suivis) contre `f5e6820` (dernière ligne taguée de ce tableau) a échoué sur exactement les fichiers du lot PWA (`frontend/index.html`, `nginx.conf`, `favicon.svg`, `Logo.tsx`, `main.tsx`, `CLAUDE.md`, `docs/decisions.md`, `docs/deployment.md`) ; une seconde vérification contre l'arbre complet de `2f5663c` (660 fichiers) a donné une correspondance sha256 exacte à 100 %, confirmant sans ambiguïté que le serveur exécutait ce commit. Cause probable : un déploiement réel a eu lieu après `v2026.09.23-prod` sans suivre jusqu'au bout la procédure de ce document (pas de tag, pas de ligne ajoutée ici). Aucun incident fonctionnel constaté par ailleurs (les checks du 2026-09-25 ci-dessous, faits juste après, sont tous verts). Traité comme un simple oubli de documentation, pas comme un incident de sécurité ou de données — mais un rappel que **taguer et documenter font partie du déploiement, pas une étape optionnelle après coup**. |
| 2026-09-25 | `v2026.09.25-prod` | `2ec9f5a` | Calendrier réaffectable, publication et vue de résultat par personne (D130-D133) ; structure hebdomadaire configurable et familles d'équité génériques (D134/D136) ; configuration opérationnelle de la génération de bout en bout (D138) ; objectifs `SPACING_SCORE`/`PREFERENCE_SATISFACTION` réels (D139) ; page d'accueil publique (D137) ; rattrapage du design source du kit PWA (D135, catch-up documentaire). **Backend + frontend + 4 migrations additives** (`Version20260923090000` `planning_generations.diagnostics` ; `Version20260923120000` `duty_assignments.current` + `duty_assignment_events` append-only ; `Version20260924090000` `allocation_families` + `duty_patterns.family_id` + `duties.pattern_id` ; `Version20260924100000` `duty_patterns.recurring` — uniquement `CREATE TABLE`/`ADD COLUMN`/`CREATE INDEX`/`ADD CONSTRAINT`/`CREATE FUNCTION`/`CREATE TRIGGER`, un seul `DROP INDEX` immédiatement remplacé par son successeur partiel ; SQL relu en entier avant exécution, aucun `DELETE`/`TRUNCATE`/`UPDATE`/`ALTER … DROP`). **Écart vérifié avant, avec une base différente de la dernière ligne documentée** : le serveur ne correspondait pas à `f5e6820` mais à `2f5663c` (non tagué — voir la ligne ci-dessus, découverte pendant cette vérification) ; sha256 de 660 fichiers suivis, correspondance exacte. Travail de la session organisé en 13 commits thématiques (D130-D139, un catch-up D135, un correctif de style `cs-fixer` trouvé par la CI) plutôt qu'un seul commit fourre-tout — voir le journal Git pour le détail. CI GitHub Actions vérifiée verte avant tag (run `36097105816`, backend + frontend ; un premier push, run `36096906869`, avait échoué sur `composer cs-check` — 6 fichiers de PHPDoc mal alignés, corrigés par `composer cs-fix` et repoussés). Artefact LF depuis `git archive HEAD` vérifié blob par blob (833 fichiers, sha256 identique des deux côtés), aucun fichier supprimé entre `2f5663c` et `2ec9f5a`. Images `medvue-backend`/`medvue-frontend` reconstruites (`build`, sans `--no-cache`) et recréées, base non recréée, clés JWT non recréées (empreinte de `public.pem` identique avant/après restart backend). Checks : `www.medvue.be` 200, `api…/api/health` ok, `/.env` et `/config/jwt/public.pem` 404, inscription + connexion réelles (cookie refresh `Secure`/`HttpOnly`/`SameSite=lax`, `/api/me` correct, IP client réelle enregistrée dans `refresh_tokens.created_by_ip` — jamais celle de Traefik), `check-trusted-proxy.sh` OK, sauvegarde + `restore-test --compare-live` PASS (33 tables, empreintes sha256 identiques). Compte de test jetable créé puis entièrement supprimé après vérification. Snapshots avant/après des 8 conteneurs des 3 autres applications du serveur : seuls les deux conteneurs MedVue recréés, aucune différence ailleurs ; `medvue-database` toujours injoignable depuis une autre application. **Note de méthode** : suite biaisée localement — une poignée de tests `GenerationModal` (frontend) flakent de façon non déterministe sous ce conteneur Docker local (contention CPU documentée, voir `.deploy-snapshots`/journal de session), chaque test passant de façon fiable isolément ; confirmé sans rapport avec le code en observant le job `frontend` de GitHub Actions (runner dédié) systématiquement vert aux deux runs. Timeout `findBy*`/`waitFor` relevé de 1000 ms à 5000 ms par prudence (`frontend/src/setupTests.ts`), sans que cela élimine la flakiness locale — à revérifier si elle réapparaît en CI. |
| 2026-09-25 | `v2026.09.25-prod-2` | `e3ea761` | Refonte du détail d'un planning (`/plannings/:id`, maquette `react_planning_detail`, D140) + correctif de tests instables. **Déploiement frontend seul** : entre `2ec9f5a` et `e3ea761`, seuls `frontend/`, `CLAUDE.md`, `docs/decisions.md`, `docs/deployment.md` changent — aucun diff backend, `docker-compose.prod.yml`, `scripts/`, `.github/`, aucune migration, aucun fichier supprimé. **Écart vérifié avant** : serveur identique à `2ec9f5a` (sha256 de 833 fichiers suivis). Validation navigateur avant commit (Playwright sur Chrome, compte de dev, desktop 1440 px + mobile 390 px, tous onglets/menus/panneaux, console) : deux défauts trouvés et corrigés avant commit (onglets sans nom accessible sur téléphone ; barre d'onglets de l'app au-dessus des panneaux — z-index remplacés par `--z-dropdown`/`--z-sheet`). **CI** : premier push (`bc5a228`, run `36133050708`) rouge sur 3 tests `GenerationModal` en timeout ; reproduit sous Node 20/Linux, **y compris sur `1de2a63` déjà en production** (non une régression) ; cause réelle : les tests cliquaient « Générer quand même » dès son apparition, encore désactivé pendant le préflight — clic ignoré sur un runner lent. Tests corrigés (attente du bouton actif, `e3ea761`), 3/3 runs stables sous Node 20 ; ceci clôt la flakiness signalée dans la ligne précédente (le relèvement à 5000 ms n'en était pas la cause). CI verte (run `36137103292`, backend + frontend). Sauvegarde PostgreSQL + clés JWT juste avant (`medvue_20260925_125642.dump`) et archive du code déployé (`code-pre-deploy/medvue_deployed_2ec9f5a_20260925_125644.tar.gz`). Artefact LF depuis `git archive HEAD` vérifié blob par blob (843 fichiers, sha256 identique des deux côtés), extrait par-dessus (`.env` intact, empreinte inchangée), serveur ensuite identique à `e3ea761` (843 fichiers). Seule l'image `medvue-frontend` reconstruite et recréée ; backend et base non recréés. Checks : `www.medvue.be` (`/`, `/login`, `/register`, `/plannings`, `/plannings/:id`, `/my-availability`, `/my-duties`) 200, `api…/api/health` ok, `/.env` et `/config/jwt/*.pem` 404, `sw.js`/manifest `no-cache` avec bons types MIME, icônes/`offline.html`/`logo/mark.svg` 200, bundle servi contenant la nouvelle page et CSS `pd-`. `restore-test --compare-live` PASS, `check-trusted-proxy.sh` OK. Snapshots avant/après (`.deploy-snapshots/*_20260925_d140.txt`) : seul `medvue-frontend` a changé ; `medvue-database` toujours injoignable depuis une autre application ; logs frontend propres. Non refaits (backend inchangé) : inscription/connexion réelles en production. |
| 2026-09-26 | `v2026.09.26-prod` | `f841f08` | Mot de passe oublié / réinitialisation (`PasswordResetToken`, `credentialsVersion`, D141/D142). **Backend + frontend + 1 migration additive** (`Version20260925090000` : `ALTER TABLE users ADD credentials_version` + `CREATE TABLE password_reset_tokens`/index/FK/CHECK — uniquement additif, SQL relu en entier avant exécution, aucun `DROP`/`DELETE`/`TRUNCATE`/`UPDATE`). **Écart vérifié avant** : serveur identique à `e3ea761` (sha256 de 836 fichiers suivis, hors `.env*`). CI GitHub Actions vérifiée verte avant tag (run `36228361417`, backend + frontend). Sauvegarde PostgreSQL + clés JWT juste avant (`medvue_20260926_081308.dump`) et archive du code déployé (`code-pre-deploy/medvue_deployed_e3ea761_20260926_081308.tar.gz`). Artefact LF depuis `git -c core.autocrlf=false archive f841f08` vérifié blob par blob (874 fichiers, `git hash-object --no-filters` == SHA de `git ls-tree`, aucun octet CR), transféré et sha256 identique des deux côtés, extrait par-dessus (`.env` intact), aucun fichier supprimé entre `e3ea761` et `f841f08`. Images `medvue-backend`/`medvue-frontend` reconstruites (`build`, sans `--no-cache`) et recréées, base non recréée, clés JWT non recréées (empreinte de `public.pem` identique avant/après restart backend, `lexik:jwt:check-config` OK). Checks : `www.medvue.be` 200, `api…/api/health` ok, `/.env` et `/config/jwt/public.pem` 404, inscription + connexion réelles (cookie refresh `Secure`/`HttpOnly`/`SameSite=lax`, `/api/me` correct, JWT contient bien le claim `credentialsVersion`), `check-trusted-proxy.sh` OK, sauvegarde + `restore-test --compare-live` PASS (34 tables, empreintes sha256 identiques). `POST /api/password-reset/request` testé en production avec une adresse **inconnue** uniquement (`200`, message générique) — **aucun envoi réel de `POST .../confirm` testé** : contrairement à l'environnement de dev (Mailpit), la production utilise un relais SMTP réel sans mode de capture documenté pour ce lot, même limite assumée que pour les rappels de disponibilité (`v2026.09.23-prod`). Compte de test jetable (inscription/connexion) créé puis entièrement supprimé après vérification (18 `users` avant et après, confirmé par le `restore-test` suivant). Snapshots avant/après des 8 conteneurs des 3 autres applications du serveur, réseaux et volumes : aucune différence ; seuls les deux conteneurs MedVue recréés ; `medvue-database` toujours injoignable depuis une autre application. Logs backend/Traefik propres (seules les deux 404 volontaires `/.env`/`/config/jwt/public.pem` apparaissent). |
| 2026-09-27 | `v2026.09.27-prod` | `6b7ef96` | Workflow complet du planning (calendrier multi-lignes, remplacement/retrait, complétion automatique, statistiques, publication + email/PDF, republication ciblée, rappel du samedi, rôle Gestionnaire, D143-D148) et **génération/complétion asynchrones** (`PlanningJob`, Messenger Doctrine, conteneur `medvue-worker`, D149). **Backend + frontend + nouveau service `worker` + 2 migrations** (`Version20260927090000` publications/rappels + `duty_assignment_events.new_assignment_id` nullable ; `Version20260927170000` `planning_jobs` + `messenger_messages`). SQL relu en entier avant exécution ; instructions non purement additives évaluées sur la base réelle **avant** migration : `ALTER … DROP NOT NULL` (0 ligne dans `duty_assignment_events`), `UPDATE planning_generations … WHERE status = 'SOLVING'` (0 ligne), backfill des publications (0 période `PUBLISHED` → 0 insertion) — recompté juste avant `migrate`. **Écart vérifié avant** : serveur identique à `f841f08` (sha256 de 871 fichiers suivis, aucun fichier en plus hors `.env*`). Seul écart documentaire : le crontab se terminait par `CRON_TZ=UTC` (et non Europe/Brussels comme l'écrivait §5 bis) — corrigé (§5 bis), sans impact. Audit final avant commit : 4 corrections (healthcheck propre au worker — l'hérité le déclarait `unhealthy` ; `/solve` technique refusé pendant un job actif ; génération `SOLVING` orpheline récupérée ; `outcome` d'un job réservé aux gestionnaires), cf. D149. Tests : backend 925 OK (14 skips préexistants), frontend 387 OK, lint/tsc/prettier/cs-fixer propres, build prod OK ; CI verte (run `36345696910`, backend + frontend). Sauvegarde avant migration : `/home/deploy/backups/medvue/postgres/medvue_20260927_194946.dump` (161 Ko, `pg_restore --list` : 34 tables) + `jwt-keys/jwt-keys_20260927_194946.tar.gz` ; code déployé `code-pre-deploy/medvue_deployed_f841f08_20260927_194954.tar.gz` (sans `.env`) ; `.env` copié en `code-pre-deploy/env_pre_d149_20260927_194954` (600) ; crontab `crontab_pre_d146_20260927_195941.txt`. Artefact LF `git -c core.autocrlf=false archive 6b7ef96` vérifié blob par blob (947 fichiers, aucun CR), sha256 identique des deux côtés, extrait par-dessus, aucun fichier supprimé. `.env` : ajout de `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0` (seule modification). Images reconstruites (`build`), `backend`/`frontend` recréés, migrations, puis seulement `worker` démarré (pas de boucle de redémarrage sur table absente) : `Up (healthy)`, `RestartCount=0`, `unless-stopped`, `StopTimeout=900`, `pcntl` présent, OR-Tools 9.15 dans `/opt/ortools-venv` du worker, `messenger:failed:show` vide, `app:planning-jobs:recover` : 0. Cron du samedi installé pour `deploy` (une seule entrée, `CRON_TZ=Europe/Brussels`), exécution simulée dans l'environnement cron avec une commande inoffensive — **la commande de rappel n'a pas été lancée** (aucun mode sans envoi). Clés JWT inchangées (empreinte `public.pem` identique avant/après), `lexik:jwt:check-config` OK. Checks : `www` 200, `api/health` ok, `/.env` et `/config/jwt/public.pem` 404, nouveaux endpoints 401 sans jeton, inscription + connexion réelles (cookie `Secure`/`HttpOnly`/`SameSite=lax`, `/api/me` correct) ; **job asynchrone réel** sur un planning jetable : `POST …/complete` → `202` en 268 ms, second appel → `409 job_in_progress`, worker `QUEUED → RUNNING → SUCCEEDED` en ~1 s (ligne `not_generated`, solveur non sollicité), `publication-state`/`result`/`statistics` 200. Données jetables (2 comptes `@example.test`, 1 planning, 1 job) supprimées par une transaction ciblée prévisualisée en `ROLLBACK` puis validée — 18 comptes, 1 planning, 0 job après. `check-trusted-proxy.sh` OK, `medvue-database` injoignable depuis une autre application, sauvegarde post-migration + `restore-test --compare-live` PASS. Snapshots avant/après des autres applications, réseaux, volumes et Traefik : identiques. **Limites assumées** : génération OR-Tools réelle non lancée en production (aucun planning jetable avec règles et gardes sans écrire dans des tables append-only impossibles à nettoyer, et jamais sur le planning opérationnel) — validée par les tests et l'UAT de dev ; emails de publication/republication et rappel du samedi non déclenchés en production (vrais destinataires, aucun mode de capture). |
| 2026-09-28 | `v2026.09.28-prod` | `a65891c` | Export du planning publié en PDF / Excel (lot `planning-export`, D150, `docs/planning-export.md`) : calendrier **courant** (`CurrentCalendarReader`), lignes/ordre/noms/titre/période choisis, aperçu PDF, « PDF de la dernière diffusion » (figé) distinct de « Exporter », PDF limité à 800 rangées (`422 size`), protection contre l'injection de formule. **Backend + frontend + 1 dépendance, aucune migration** : `openspout/openspout` v5.3.0 (seul ajout au `composer.lock` ; exige PHP ~8.3 et `dom`/`filter`/`libxml`/`xmlreader`/`zip`, **pas `gd`** — image prod `frankenphp:1-php8.3`, PHP 8.3.35, extensions présentes, aucune modification Docker). Fusion en avance rapide de `feature/planning-export` (4 commits) + 2 correctifs de tests (`ae95f84`, `a65891c` : deux tests existants depuis D149 vérifiaient « Republier » avant l'arrivée de `publication-state` — reproduit en retardant la réponse de 800 ms, le second trouvé par la CI, run `36397156892`, frontend rouge ; aucun déploiement tant qu'elle l'était). Tests : backend 946 OK (14 skips préexistants), frontend 412 OK (3 exécutions complètes consécutives), lint/tsc/prettier/cs-fixer propres, étape `vendor` du Dockerfile de prod construite localement depuis le lock ; CI verte avant tout changement en production (run `36398454292`, backend + frontend). **Écart vérifié avant** : serveur identique à `6b7ef96` (sha256 de 947 fichiers suivis, seuls `.env*` en plus) ; 22/22 migrations, 0 nouvelle ; aucun job de planning actif. Sauvegarde avant déploiement `postgres/medvue_20260928_084454.dump` (sha256 OK, `pg_restore --list` lisible) + `jwt-keys/jwt-keys_20260928_084454.tar.gz`, `restore-test --compare-live` **PASS avant** toute modification ; code déployé archivé `code-pre-deploy/medvue_deployed_6b7ef96_20260928_084456.tar.gz` (sans `.env`). Artefact LF `git -c core.autocrlf=false archive a65891c` vérifié blob par blob (973 fichiers, aucun CR), sha256 `4ab20d2a…c90a` identique des deux côtés, extrait par-dessus (`.env` inchangé, empreinte identique), serveur ensuite identique à `a65891c` (973 fichiers), aucun fichier supprimé. `build` (openspout installé depuis le lock), `up -d --no-build`, `migrations:up-to-date` OK, `cache:clear`, `restart backend worker`. Clés JWT inchangées (empreinte `public.pem` identique avant/après), `lexik:jwt:check-config` OK, `check-trusted-proxy.sh` OK. Checks : `www` (`/`, `/login`, `/plannings`) 200, `api/health` ok, `/.env` et `/config/jwt/*.pem` 404, `Content-Disposition` exposé par CORS, bundle servi contenant le lot, worker stable (0 redémarrage) et consommant, `messenger:failed:show` vide, `medvue-database` injoignable depuis SurgicalHub, logs propres. Inscription + connexion réelles (cookie `Secure`/`HttpOnly`/`SameSite=lax`, `/api/me` correct) sur un compte jetable ; export : `401` sans jeton, `403` pour ce compte sur le planning opérationnel, `404` sur un planning inconnu ; compte supprimé par transaction ciblée prévisualisée en `ROLLBACK` (18 comptes, 1 planning après). Moteur de rendu vérifié **dans l'image de production** avec des données synthétiques, conteneur jetable sans réseau ni base, 1 CPU : PDF 64 rangées/6 pages en 0,7 s, 330 rangées/29 pages en 3,0 s (≈ 0,009 s/rangée, soit ≈ 7 s à la limite de 800), XLSX valide (`Planning` + `Par personne`, vraies dates, 0 formule), garde-fou actif (20 lignes × 1 an = 1 302 rangées > 800). Sauvegarde post-déploiement + `restore-test --compare-live` PASS. Snapshots avant/après (`.deploy-snapshots/*_20260928_export.txt`) : seuls `medvue-backend`/`medvue-frontend`/`medvue-worker` recréés ; SurgicalHub, MySQL, Redis, phpMyAdmin, Portainer, Traefik, réseaux, volumes et membres du réseau `proxy` identiques. **Limites assumées** : la production ne contient **aucun planning publié** (1 planning, 0 publication) — l'export de bout en bout sur données réelles, les boutons « PDF de la dernière diffusion »/« Exporter » à l'écran et le refus `422 size` par HTTP n'ont donc pas pu être exercés en production (publier un planning de test exigerait une génération réelle et enverrait des emails à de vrais participants) ; ils sont couverts par les tests, l'UAT de dev (téléchargements réels dans Chrome) et le rendu dans l'image de prod ci-dessus. À vérifier au premier planning publié. IP client dans `refresh_tokens.created_by_ip` non revérifiée (compte jetable supprimé ; réseau et proxy de confiance inchangés, `check-trusted-proxy.sh` OK). |
| 2026-09-28 | `v2026.09.28-prod-2` | `b1e121f` | Tableau de bord refondu (maquette `react_dashboard`) et cadre applicatif aligné — menu latéral dès 760 px, barre du bas à 4 entrées, avatar « Mon compte » (D151) ; `GET /api/plannings` expose `myLineName`/`memberCount`/`published`. Embarque aussi `95b08dc` (mise en page stable de la modale de semaine type), fusionné sur `master` après `v2026.09.28-prod` et jamais déployé jusque-là. **Backend + frontend, aucune migration, aucune dépendance, aucun fichier supprimé** (backend : `PlanningController` + son test uniquement). Branche `feature/dashboard-redesign` (3 commits) fusionnée en avance rapide. Tests : backend 947 OK (14 skips préexistants), frontend 427 OK, lint/tsc/cs-fixer propres ; CI verte avant tout changement en production (run `36484569949`, backend + frontend — le run précédent sur `master`, `36399957415` sur `2d21d44`, commit de documentation seule, était rouge sur les tests frontend : instabilité, non reproduite). **Écart vérifié avant** : serveur identique à `a65891c` (sha256 des 973 fichiers suivis) ; aucun job de planning actif (2 `FAILED` anciens). Sauvegarde `postgres/medvue_20260928_211928.dump` + `jwt-keys_20260928_211928.tar.gz`, `restore-test --compare-live` **PASS avant** ; code déployé archivé `code-pre-deploy/medvue_deployed_a65891c_20260928_211928.tar.gz` (sans `.env`). Artefact LF `git -c core.autocrlf=false archive b1e121f` vérifié blob par blob (989 fichiers, aucun CR), sha256 `a80bc8be…2565` identique des deux côtés, extrait par-dessus (`.env` inchangé, empreinte identique). `build`, `up -d --no-build`, `migrations:up-to-date` OK, `cache:clear`, `restart backend worker`. Clés JWT inchangées (empreinte `public.pem` identique), `lexik:jwt:check-config` OK, `check-trusted-proxy.sh` OK. Checks : `www` (`/`, `/login`, `/plannings`) 200, `api/health` ok, `/.env` et `/config/jwt/public.pem` 404, bundle servi contenant la nouvelle page et le point de rupture `width>=760px` ; inscription + connexion réelles (cookie `Secure`/`HttpOnly`/`SameSite=lax`, `/api/me` correct), `GET /api/plannings` `401` sans jeton et, sur un planning jetable, `myLineName`/`memberCount`/`published` corrects. Compte et planning jetables supprimés par une transaction ciblée (clés étrangères relues en base) prévisualisée en `ROLLBACK` puis validée : 18 comptes, 1 planning après. Worker stable (0 redémarrage) et consommant, `messenger:failed:show` vide, `medvue-database` injoignable depuis SurgicalHub, logs propres (seules les deux 404 volontaires). Sauvegarde post-déploiement + `restore-test --compare-live` PASS. Snapshots avant/après : seuls les conteneurs MedVue recréés ; autres applications, réseaux et volumes identiques (membres du réseau `proxy` identiques, listés dans un autre ordre). **Limite** : rendu du nouveau tableau de bord vérifié au pixel près en développement (Chrome, 1 600 et 390 px), pas avec une session réelle en production. |
| 2026-09-29 | `v2026.09.29-prod` | `609c7bf` | Ligne secondaire **conditionnelle** (« renfort selon le chirurgien de garde », D160-D167) : multi-appartenance d'un `User` à plusieurs lignes, contraintes inter-lignes, politique de demande versionnée, gardes `CONDITIONAL` et `DemandView`, génération source → renfort, calendrier live, sorties, frontend (« Paramètres de la ligne », matrice chirurgiens × jours) ; branche `feature/conditional-secondary-line` (L1 → L9, SHA conservés) intégrant `master` (`eb17bc3`, tableau de bord D151 : `participating`/`myLineName` reposent sur toutes les adhésions ouvertes, plusieurs lignes listées dans l'ordre du planning), fusionnée en avance rapide. **Backend + frontend + 5 migrations, aucune dépendance, aucun fichier supprimé, aucun changement d'infrastructure** (compose, Dockerfiles, locks, scripts, CI inchangés). Tests : backend 1 131 OK (14 skips préexistants), frontend 474 OK, lint/tsc/oxlint/cs-fixer propres, 27 migrations sur base vierge ; CI verte avant tout changement en production (run `36543832594`, backend + frontend). **Écart vérifié avant** : serveur identique à `b1e121f` (sha256 des 989 fichiers suivis, seuls `.env*` en plus) ; aucun job actif (2 `FAILED` anciens). Prérequis des migrations vérifiés en lecture seule sur la base réelle : 120 gardes toutes `REQUIRED` (nouveaux CHECK de `duties` satisfaits), 0 doublon d'adhésion ouverte par (équipe, utilisateur). Sauvegarde `postgres/medvue_20260929_084449.dump` + `jwt-keys_20260929_084449.tar.gz`, `restore-test --compare-live` **PASS avant** ; code déployé archivé `code-pre-deploy/medvue_deployed_b1e121f_20260929_084455.tar.gz` (sans `.env`). Artefact LF `git -c core.autocrlf=false archive 609c7bf` vérifié blob par blob (1 079 fichiers, aucun CR), sha256 `ebb6feaa…4535` identique des deux côtés, extrait par-dessus (`.env` inchangé, empreinte identique), serveur ensuite identique à `609c7bf` (1 079 fichiers). `build`, `up -d --no-build`, migrations : `status` (22 → 5 nouvelles), SQL produit par `--dry-run --write-sql` relu en entier (68 instructions : un seul `DROP INDEX` `uniq_planning_team_members_open_membership`, immédiatement remplacé par son successeur moins strict par équipe ; sinon `CREATE TABLE`/`CREATE INDEX`/`ADD` colonne nullable `duties.coverage_source_id`/`ADD CONSTRAINT` ; aucun `DELETE`/`TRUNCATE`/`UPDATE`), puis `migrate` (5 migrations, 58 requêtes), `up-to-date`, `cache:clear`, `restart backend worker`. Clés JWT inchangées (empreinte `public.pem` identique), `lexik:jwt:check-config` OK, `check-trusted-proxy.sh` OK. Checks : `www` (`/`, `/login`, `/plannings`) 200, `api/health` ok, `/.env` et `/config/jwt/public.pem` 404, `GET /api/plannings` 401 sans jeton ; sur un compte jetable (`@example.test`) : inscription + connexion réelles (cookie `Secure`/`HttpOnly`/`SameSite=lax`, `/api/me` correct), planning jetable à deux lignes, résumé du tableau de bord correct, politique de demande lue (`INDEPENDENT`) puis passée en `CONDITIONAL_ON_SOURCE_ASSIGNMENT`, préflight annonçant la ligne de renfort par sa source (`demandSourceLineName`). Données jetables supprimées par une transaction ciblée (clés étrangères relues en base) prévisualisée en `ROLLBACK` puis validée : 18 comptes, 1 planning, 0 politique de demande après. Worker stable (0 redémarrage) et consommant, `messenger:failed:show` vide, `medvue-database` injoignable depuis SurgicalHub, logs propres (seul le 404 volontaire). Sauvegarde post-déploiement + `restore-test --compare-live` PASS. Snapshots (`.deploy-snapshots/*_20260929_conditional.txt`) : seuls `medvue-backend`/`medvue-frontend`/`medvue-worker` recréés ; autres applications, réseaux, volumes et membres du réseau `proxy` identiques. **Limites** : aucune ligne conditionnelle réelle en production (le planning opérationnel n'a qu'une ligne indépendante) — génération OR-Tools d'une ligne de renfort et calendrier live non exercés en production (tests + recettes navigateur de dev L8/L9) ; une instabilité frontend locale observée une fois (`LineSettingsDialog`, premier passage après redémarrage du conteneur, non reproduite en 3 passages ni en CI). |
| 2026-09-29 | `v2026.09.29-prod-2` | `4d1ca85` | **Correctif : toute génération échouait en production** (`PlanningJob` 2, 3, 4 `FAILED` : `Class "Symfony\Component\Process\Process" not found`). `OrToolsPlanningSolver` utilise `symfony/process`, que `composer.json` ne déclarait pas : le paquet n'arrivait que comme dépendance transitive de paquets de dev, donc présent en dev/test/CI mais absent de l'image de production (`composer install --no-dev`). Correctif : `symfony/process` 7.4.* ajouté à `require` ; garde-fou `tests/Deployment/ProductionDependenciesTest` (tout namespace vendor importé par `src/` doit appartenir à un paquet non-dev de `composer.lock`, rouge sans le correctif). **Backend seul, aucune migration, aucun changement d'infrastructure.** Écart vérifié avant : serveur identique à `609c7bf` (1 079 fichiers, blob par blob) ; aucun job actif. Sauvegarde `postgres/medvue_20260929_101234.dump` + `jwt-keys_20260929_101234.tar.gz`. Artefact LF `git -c core.autocrlf=false archive 4d1ca85` vérifié blob par blob (1 080 fichiers, aucun CR), sha256 `d7b1bd59…c78b` identique des deux côtés, extrait par-dessus (`.env` inchangé). `build`, `up -d --no-build`, migrations `up-to-date`, `cache:clear`, `restart backend worker`, `check-trusted-proxy.sh` OK. Checks : `Symfony\Component\Process\Process` chargeable dans `medvue-backend` et `medvue-worker`, OR-Tools 9.15 présent dans `/opt/ortools-venv` du worker, `api/health` ok, `GET /api/plannings` 401, `/.env` 404, `www` 200, `messenger:failed:show` vide, 0 redémarrage, clés JWT inchangées. **Limite** : génération réelle non relancée par l'agent (planning opérationnel de l'utilisateur) — à vérifier à la prochaine génération. |
| 2026-09-29 | `v2026.09.29-prod-3` | `2ce61ba` | « Mes gardes » (D168) + **abonnement agenda** Google / Apple / Outlook (D170, `CalendarFeed`, flux `GET /api/calendar-feeds/{token}.ics` public par adresse secrète). PR #1 (`feature/calendar-subscription`) fusionnée par commit de fusion ; arbre de `2ce61ba` identique à `fe82a4b`. **Backend + frontend + 1 migration additive** (`Version20260929140000` : `CREATE TABLE calendar_feeds` + index dont l'unique partiel, FK `ON DELETE CASCADE`, CHECK — SQL relu en entier avant exécution, aucun `DROP`/`DELETE`/`TRUNCATE`/`UPDATE`). **Écart vérifié avant** : serveur identique à `4d1ca85` (sha256 de 1073 fichiers suivis ; seul `docs/deployment.md` diffère de `ba90546`, commit de documentation) ; `4d1ca85 → 2ce61ba` : 40 fichiers, aucune suppression, aucun changement de `docker-compose.prod.yml`, `scripts/`, `.github/`, dépendances ni Dockerfile. **CI** : premier run de la PR (`36565413457`) rouge, 2 tentatives, sur `LineSettingsDialog.test.tsx` (D167) — course du test (l'éditeur de semaine type chargé apporte son propre « Annuler »), reproduite en local en forçant le chargement, corrigée par `fe82a4b` (même requête que `WeekStructureModal.test`) ; CI verte ensuite (run `36566417718`, backend + frontend). Aucun job de planning actif avant. Sauvegarde PostgreSQL + clés JWT juste avant (`medvue_20260929_121936.dump`) et archive du code déployé (`code-pre-deploy/medvue_deployed_4d1ca85_20260929_121938.tar.gz`). Artefact LF `git -c core.autocrlf=false archive 2ce61ba` vérifié blob par blob (1109 fichiers, aucun CR), sha256 identique des deux côtés, extrait par-dessus (`.env` intact, empreinte inchangée). Images `build` (sans `--no-cache`), `up -d --no-build`, empreinte de `public.pem` identique avant/après, migration (status → dry-run + write-sql → relecture → migrate → `up-to-date`), `cache:clear`, `restart backend worker`, `check-trusted-proxy.sh` OK. Checks : `www.medvue.be` et `/my-duties` 200, `api…/api/health` ok, `/.env` et `/config/jwt/public.pem` 404, jeton d'agenda inconnu 404, `/api/me/calendar-feed` sans JWT 401, worker `healthy` (0 redémarrage, consomme `planning_jobs`), `messenger:failed:show` vide, `lexik:jwt:check-config` OK, cron du samedi présent, `medvue-database` injoignable depuis `surgicalhub-php`, logs backend : seules les 404 volontaires. **Recette réelle en production** avec un compte jetable : inscription 201, connexion (cookie refresh `Secure`/`HttpOnly`), `/api/me` correct, IP publique réelle dans `refresh_tokens.created_by_ip`, lien d'agenda créé une seule fois sur deux `POST`, flux `text/calendar` valide (`VCALENDAR` vide, `Cache-Control: private`), `HEAD` 200, régénération → ancien 404 / nouveau 200, `lastFetchedAt` enregistré, désactivation 204 → 404 ; compte supprimé ensuite (18 `users` avant et après, 0 `calendar_feeds`). Sauvegarde + `restore-test --compare-live` PASS. Snapshots avant/après (`.deploy-snapshots/*_20260929_d170.txt`) : identiques. **Non vérifiés** : abonnement réel depuis Google Agenda / Apple Calendrier / Outlook (aucun planning publié avec des gardes pour un compte de test en production), génération réelle (code de génération inchangé). |
| 2026-09-29 | `v2026.09.29-prod-4` | `68cd9a9` | « Mes plannings » refondu (maquette `react_mes_plannings`, D169) : cartes groupées En cours / À venir / Terminés, statut et compteur, frise par mois, fin inclusive, étape déduite ; `GET /api/plannings` expose `lineCount`/`collecting`. **Backend + frontend, aucune migration, aucune dépendance, aucun fichier supprimé, aucun changement d'infrastructure** (backend : `PlanningController` + son test). CI verte avant tout changement en production (run `36608445446`, backend + frontend). **Écart vérifié avant** : serveur identique à `2ce61ba` (sha256 des 1 109 fichiers suivis) ; aucun job de planning actif (3 `SUCCEEDED`, 3 `FAILED` anciens). Sauvegarde `postgres/medvue_20260929_182936.dump` + `jwt-keys_20260929_182936.tar.gz`, `restore-test --compare-live` **PASS avant** ; code déployé archivé `code-pre-deploy/medvue_deployed_2ce61ba_20260929_182936.tar.gz` (sans `.env`). Artefact LF `git -c core.autocrlf=false archive 68cd9a9` vérifié blob par blob (1 123 fichiers, aucun CR), sha256 `b013b5e3…3b4c` identique des deux côtés, extrait par-dessus (`.env` inchangé, empreinte identique). `build`, `up -d --no-build`, `migrations:up-to-date` OK, `cache:clear`, `restart backend worker`. Clés JWT inchangées (empreinte `public.pem` identique), `lexik:jwt:check-config` OK, `check-trusted-proxy.sh` OK. Checks : `www` (`/`, `/plannings`) 200, `api/health` ok, `/.env` et `/config/jwt/public.pem` 404, `GET /api/plannings` 401 sans jeton, bundle servi contenant la nouvelle page (`mp-card`) ; inscription + connexion réelles sur un compte jetable (`@example.test`), planning jetable : `lineCount` 1, `collecting` true, `memberCount` 1, `published` false. Données jetables supprimées par une transaction ciblée (clés étrangères relues en base, aucune adhésion hors du planning jetable) prévisualisée en `ROLLBACK` puis validée : 18 comptes, 1 planning après. Worker stable (0 redémarrage) et consommant, `messenger:failed:show` vide, `medvue-database` injoignable depuis `surgicalhub-php`, logs propres (seules les deux 404 volontaires). Sauvegarde post-déploiement + `restore-test --compare-live` PASS. Snapshots avant/après (`.deploy-snapshots/*_20260929_d169.txt`) : identiques. **Limite** : rendu de la nouvelle page non vérifié dans un navigateur (ni en développement — connexion de l'outil navigateur en échec — ni en production) ; couvert par les tests Vitest et le contenu du bundle servi. |

### Dérogations à la règle « tag uniquement si tout est vert »

La règle : aucun tag de production tant que la CI et les checks de déploiement
ne sont pas entièrement verts. Dérogations consenties (jamais silencieuses) :

- **`v2026.09.20-prod`** (2026-09-20) : poussé avec le job backend GitHub Actions
  rouge. Les checks de déploiement (66/66 fonctionnels, restauration, clés,
  IP client, autres applications) étaient verts ; la CI ne l'était pas, pour
  une cause non identifiée au moment du tag. Diagnostic et correction
  ultérieurs : §8 point 9.

## 7. Interdits en production

- **`docker compose down -v` (ou `--volumes`) est interdit.** Il supprime
  `medvue_db_data` (toutes les données) et `medvue_jwt_keys` (les clés de
  signature). Même règle pour `docker volume rm`, `docker volume prune` et
  `docker system prune --volumes`. Un arrêt se fait avec `stop`, jamais avec
  une commande qui supprime des volumes.
- Vider `/opt/stack/apps/medvue` sans préserver `.env` : `POSTGRES_PASSWORD`
  n'est appliqué qu'à la *création* du volume de la base ; régénérer le `.env`
  après coup laisse la base avec l'ancien mot de passe et le backend ne peut
  plus s'y connecter. Extraire l'archive par-dessus, ne pas effacer.
- Régénérer `JWT_PASSPHRASE` sans régénérer les clés (et inversement).
- `doctrine:migrations:migrate --write-sql` sans `--dry-run`, ou
  `doctrine:schema:update --force` (§1 étape 6, D051).
- Restaurer un dump sur la base de production sans dump de sécurité préalable
  (`docs/backup.md`).
- Toute intervention sur SurgicalHub, MedAtWork, MedClick, MySQL, Redis,
  Portainer, phpMyAdmin ou Traefik (`server-production.md` §15).

## 8. Incidents de déploiement

Journal factuel du premier déploiement (2026-09-19/20). Aucun historique
n'est réécrit : chaque correction est un commit distinct.

1. **Déploiement interrompu** (arrêt du poste de déploiement). Archive
   extraite, `.env` créé et images construites, mais `docker compose up`
   jamais exécuté : aucun conteneur, réseau ni volume MedVue n'existait.
2. **Archive livrée en CRLF.** Cause : `core.autocrlf=true` du gitconfig
   système de Git for Windows, appliqué par `git archive`. Correction :
   redéploiement d'une archive `git -c core.autocrlf=false archive` vérifiée
   blob par blob, nouveau `.env` (nouveaux secrets), images reconstruites ;
   `.gitattributes` force LF pour `*.sh` (§1 étape 2).
3. **DNS `api.medvue.be` absent** au premier essai : détecté par les
   vérifications préalables (résolveurs publics + serveurs faisant
   autorité), démarrage différé jusqu'à propagation.
4. **Migrations exécutées avant la relecture du SQL.** `migrate --write-sql`
   (sans `--dry-run`) a écrit *et* exécuté les 12 migrations. La base était
   vierge, les instructions destructives (`DELETE FROM planning_*`,
   `DROP TABLE teams/team_members`) visaient des tables créées dans la même
   exécution et encore vides : aucune donnée détruite, `up-to-date` vert,
   écart de schéma = celui, attendu, de D051. Décision : continuer sans
   rollback. Correction : séquence documentée au §1 étape 6.
5. **Clés JWT non persistantes** (couche inscriptible du conteneur) :
   volume `medvue_jwt_keys`, D107 (commit « Persist the production JWT key
   pair… »).
6. **`getClientIp()` = adresse de Traefik** pour tous les visiteurs
   (limiteurs partagés) : proxy de confiance exact, D108 (commit « Trust
   Traefik's exact address… »).
7. **Aucune sauvegarde MedVue** : mécanisme et test de restauration réels,
   D109 (`docs/backup.md`).
8. **CI GitHub rouge à chaque push** (constaté le 2026-09-20, déjà rouge le
   2026-09-16) : `ci.yml` ne créait jamais la base de test `app_test` (le
   noyau de test suffixe le nom de base par `_test`), donc tous les tests qui
   touchent la base échouaient (code de sortie 2). Ce n'était pas une
   régression du déploiement ; reproduit en local (base absente : 205 erreurs
   et 79 échecs) puis corrigé par une étape « Prepare the test database »
   (463 tests verts depuis une base absente).
9. **CI GitHub encore rouge après le point 8 : aucune clé JWT sur le runner.**
   Diagnostic (run `35497692440`, job `backend`, commit `e15dda4`) : l'étape
   « Run tests » échouait avec `Tests: 467, Assertions: 1913, Failures: 67`,
   tous de la même nature : `JWTEncodeFailureException: An error occurred
   while trying to encode the JWT token. Please verify your configuration
   (private key/passphrase)` (`LcobucciJWTEncoder.php` ligne 31), premier
   échec `AuthenticationTest::testLoginWithValidCredentialsReturnsTokenAndRefreshCookie`.
   Cause : `backend/config/jwt/*.pem` est ignoré par Git (à raison, D107) ; un
   checkout propre n'a donc aucune paire de clés et le workflow n'en générait
   pas. Sur toute machine où des clés de dev existaient (poste de
   développement, conteneur de dev, et mes premières reproductions « fidèles »
   qui copiaient le dossier de travail), les tests passaient : c'est ce qui a
   masqué le problème. Classement : **configuration GitHub Actions**
   (prérequis manquant), pas un bug du code ni un test instable. Reproduit à
   l'identique depuis une archive `git archive` (mêmes 467/1913/67), puis
   corrigé par l'étape « Generate the JWT test key pair »
   (`lexik:jwt:generate-keypair --skip-if-exists --env=test`, passphrase de
   dev de `backend/.env`, jamais utilisée en production) : 467 tests, 2174
   assertions verts. Non-régression : `tests/Deployment/CiWorkflowTest.php`
   impose que la base de test, son schéma et la paire de clés soient préparés
   avant les tests. Environnement du runner relevé : Ubuntu 24.04.5,
   noyau 6.17 azure, 4 CPU, ~16 Go, PHP 8.3.33, Composer 2.10.3,
   Python 3.12.3, OR-Tools 9.15.6755 (numpy 2.5.3, pandas 3.0.6), image
   `postgres:16-alpine`, `LANG=C.UTF-8`, fuseau UTC ; rien d'autre ne différait.
