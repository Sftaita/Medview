# Sauvegardes et restauration MedVue

> Mécanisme réel, versionné dans `scripts/backup/`, déployé avec l'application
> (`/opt/stack/apps/medvue/scripts/backup/`). Décision : `docs/decisions.md`
> D109. Contexte de déploiement : [`docs/deployment.md`](deployment.md) §5.

## 1. Ce qui est sauvegardé — et ce qui ne l'est pas

| Élément | Sauvegardé ? | Comment / pourquoi |
|---|---|---|
| Base PostgreSQL `medvue` | **Oui** | `pg_dump --format=custom --no-owner --no-acl`, exécuté **dans** `medvue-database` par son socket local : aucun mot de passe n'est lu, transmis ni journalisé. Un dump qui ne se laisse pas lister (`pg_restore --list`) est rejeté. |
| Clés JWT (volume `medvue_jwt_keys`) | **Oui** | Archive `tar.gz` du volume monté en lecture seule, sans réseau. La clé privée y est **chiffrée** par `JWT_PASSPHRASE`. |
| `.env` (dont `JWT_PASSPHRASE`, mot de passe PostgreSQL, `APP_SECRET`) | **Non, volontairement** | Ne jamais ranger la passphrase à côté de la clé qu'elle protège. À conserver **hors du serveur** (gestionnaire de secrets), séparément des sauvegardes. |
| Code, images Docker | Non | Reconstructibles depuis Git (`git archive` du tag) : voir `docs/deployment.md`. |
| Traefik, certificats, autres applications | Non | Hors périmètre MedVue (`server-production.md` §15). |

## 2. Mécanisme

- **Sauvegarde** : `scripts/backup/medvue-backup.sh`, lancé chaque nuit par
  cron (`03:45 UTC`) sous l'utilisateur `deploy`.
- **Destination** : `/home/deploy/backups/medvue/` (répertoire propre à
  MedVue, droits `700`, fichiers `600`) :
  `postgres/medvue_<horodatage>.dump`, `jwt-keys/jwt-keys_<horodatage>.tar.gz`,
  chacun avec son `.sha256`.
- **Journal** : `/home/deploy/backups/medvue/backup.log` (horodaté, jamais un
  secret), et `cron.log` pour la sortie brute de cron.
- **Rétention locale** : 30 jours (`MEDVUE_BACKUP_RETENTION_DAYS`), en gardant
  **toujours** les 7 plus récentes quel que soit leur âge
  (`MEDVUE_BACKUP_MIN_KEEP`). La rotation ne s'exécute qu'après le succès de la
  sauvegarde du même type : un échec ne vide jamais le stock.
- **Robustesse** : un seul run à la fois (`flock`), écriture dans un fichier
  `.partial` renommé seulement une fois validé, code retour ≠ 0 au moindre
  échec.
- **Isolation** : le script ne lit et n'écrit que des ressources MedVue. Il ne
  touche ni `/home/deploy/scripts/*`, ni les journaux, la rotation ou la
  synchronisation des sauvegardes de SurgicalHub.

### Installation du cron (une seule fois par serveur)

Le crontab de `deploy` contient déjà les tâches de SurgicalHub : ne rien
modifier, seulement **ajouter** un bloc, après une copie de sécurité.

```bash
crontab -l > ~/crontab.backup.$(date +%Y%m%d_%H%M%S)      # copie de sécurité
( crontab -l
  echo ''
  echo '# MedVue — sauvegarde quotidienne PostgreSQL + clés JWT (docs/backup.md)'
  echo 'CRON_TZ=UTC'
  echo '45 3 * * * /opt/stack/apps/medvue/scripts/backup/medvue-backup.sh >> /home/deploy/backups/medvue/cron.log 2>&1'
) | crontab -
crontab -l | tail -5                                       # vérifier : seules ces lignes sont nouvelles
```

`CRON_TZ=UTC` est nécessaire : le crontab existant définit `CRON_TZ=Europe/Brussels`
avant sa dernière tâche, et ce réglage s'appliquerait sinon à la ligne ajoutée.

## 2 bis. Conservation réservée (`reserved/`, 2026-10-23)

La rotation du §2 supprime une sauvegarde au bout de 30 jours, **y compris**
celles que `docs/deployment.md` §6 et `docs/decisions.md` citent comme point de
restauration d'un déploiement. Pour qu'une sauvegarde citée reste restaurable,
une **copie** en est rangée dans un répertoire que la rotation ne lit pas.

| | Rotation normale | Conservation réservée |
|---|---|---|
| Emplacement | `postgres/`, `jwt-keys/` (premier niveau) | `reserved/<horodatage>/` |
| Contenu | une sauvegarde par nuit + celles prises à la main | les sauvegardes citées par la documentation |
| Durée | 30 jours, au moins les 7 plus récentes | **indéfinie** : rien ne la vide automatiquement |
| Qui y écrit | `medvue-backup.sh` (cron) | à la main, par copie (jamais un déplacement) |

`medvue-backup.sh` ne cherche qu'au premier niveau de `postgres/` et
`jwt-keys/` (`find -maxdepth 1`) ; la rotation générique du serveur
(`/home/deploy/scripts/rotate_backups.sh`) ne lit que `mysql/` et `uploads/`.
Aucune des deux n'atteint `reserved/`.

**Contenu au 2026-10-23** : 33 dossiers `reserved/<horodatage>/` (droits `700`),
chacun avec le dump `medvue_<horodatage>.dump` et l'archive
`jwt-keys_<horodatage>.tar.gz` de même horodatage, leurs `.sha256` (fichiers
`600`) ; à la racine, `SHA256SUMS` (tous les fichiers), `INDEX.tsv` (fichier,
ligne de documentation qui le cite, contexte du déploiement) et `README.txt`.
Environ 10 Mo.

- Les 31 dumps cités **par leur nom** dans `docs/` : sauvegardes d'avant et
  d'après les déploiements du §6 de `docs/deployment.md`, de
  `v2026.09.25-prod-2` à `v2026.10.23-prod` ; celles de l'activation des
  périodes et de la création de la période 2 (2026-10-21) ; les dumps de
  production sur lesquels des migrations ont été répétées
  (`docs/surgicalhub-integration.md` §12 pour le 2026-10-10,
  `docs/decisions.md` pour le 2026-10-17 et le 2026-10-19, D205).
- 2 dumps du 2026-09-23 (`081157` avant migration, `082323` après), cités sans
  nom par la ligne `v2026.09.23-prod` et identifiés par leur horodatage.
- **Introuvables** : les sauvegardes des déploiements du 2026-09-20 et du
  2026-09-21 (`v2026.09.20-prod` et `-2`, `v2026.09.21-prod` et `-2`), citées
  sans nom et déjà supprimées par la rotation avant la création de
  `reserved/`, ainsi que `medvue_20260920_073515`.

Chaque original a été vérifié contre son `.sha256` avant la copie, chaque copie
comparée à l'original ensuite ; les originaux sont restés en place (ils
suivent la rotation normale). Deux ensembles réservés ont été restaurés dans la
cible jetable du §3 : `20260925_125642` et `20261021_115609`, **PASS** tous les
deux.

**Ajouter une sauvegarde** (après un déploiement qui la cite), sans jamais
déplacer ni écraser :

```bash
B=/home/deploy/backups/medvue; S=<HORODATAGE>; D="$B/reserved/$S"
(cd "$B/postgres" && sha256sum -c "medvue_$S.dump.sha256") &&
(cd "$B/jwt-keys" && sha256sum -c "jwt-keys_$S.tar.gz.sha256") &&
mkdir -m 700 "$D" &&
cp -n -p "$B/postgres/medvue_$S.dump"{,.sha256} "$B/jwt-keys/jwt-keys_$S.tar.gz"{,.sha256} "$D/" &&
chmod 600 "$D"/* &&
(cd "$D" && sha256sum -c ./*.sha256)
# puis : ajouter les deux fichiers à reserved/SHA256SUMS et une ligne à reserved/INDEX.tsv
```

**Vérifier** l'ensemble : `cd /home/deploy/backups/medvue/reserved && sha256sum -c --quiet SHA256SUMS`.

**Restaurer** depuis `reserved/` : mêmes procédures que le §4, avec les chemins
du dossier réservé (le test jetable d'abord, toujours) :

```bash
R=/home/deploy/backups/medvue/reserved/<HORODATAGE>
/opt/stack/apps/medvue/scripts/backup/medvue-restore-test.sh \
  --dump "$R/medvue_<HORODATAGE>.dump" --keys "$R/jwt-keys_<HORODATAGE>.tar.gz"
```

Sans `--compare-live` : une sauvegarde ancienne diffère forcément de la base en
service. Ce test réécrit `status/restore-test.json` (§4 bis).

> **`reserved/` n'est pas une sauvegarde hors site.** Les copies sont sur le
> même serveur et le même disque que les originaux et que la base : une perte du
> disque ou du serveur les emporte toutes. Aucune n'est copiée vers Google
> Drive (§5).

## 3. Vérifier

```bash
# Lancer une sauvegarde à la main (mêmes chemins que le cron)
/opt/stack/apps/medvue/scripts/backup/medvue-backup.sh
tail -n 8 /home/deploy/backups/medvue/backup.log

# Prouver qu'elle se restaure (cible jetable, aucune écriture en production)
/opt/stack/apps/medvue/scripts/backup/medvue-restore-test.sh --compare-live
# → doit se terminer par : RESTORE TEST: PASS
```

`--compare-live` compare la base restaurée à la base en service (tables,
nombre de lignes exact, contraintes par type, index, migrations) et les
fichiers de clés restaurés à ceux du volume : à n'utiliser que juste après une
sauvegarde. Sans cette option, le script vérifie l'intégrité (sha256), la
restauration réelle (`pg_restore --exit-on-error`), l'historique de migrations
et le contenu de l'archive de clés. La cible jetable est un conteneur
PostgreSQL **sans réseau**, dont le répertoire de données est en mémoire
(`--tmpfs`), supprimé à la fin même en cas d'échec ; son authentification
locale « trust » n'est possible que parce qu'il est injoignable : aucun mot de
passe n'existe dans ces scripts.

## 4. Restauration

> Toute restauration **sur la production** est destructive pour l'état courant.
> Elle se décide explicitement, après avoir pris un dump de sécurité (étape 1) et
> validé la sauvegarde visée par le test jetable (étape 0).

### 4.0 Choisir et valider la sauvegarde

```bash
ls -lt /home/deploy/backups/medvue/postgres | head
DUMP=/home/deploy/backups/medvue/postgres/medvue_<HORODATAGE>.dump
KEYS=/home/deploy/backups/medvue/jwt-keys/jwt-keys_<HORODATAGE>.tar.gz
/opt/stack/apps/medvue/scripts/backup/medvue-restore-test.sh --dump "$DUMP" --keys "$KEYS"
```

### 4.1 Restaurer la base (données erronées, corruption)

```bash
cd /opt/stack/apps/medvue
# 1. Dump de sécurité de l'état ACTUEL, dans un autre répertoire (jamais écrasé)
MEDVUE_BACKUP_ROOT=/home/deploy/backups/medvue-safety ./scripts/backup/medvue-backup.sh
# 2. Couper les écritures
docker compose -f docker-compose.prod.yml stop backend
# 3. Recréer une base vide (le conteneur et son volume ne sont PAS touchés)
docker exec medvue-database psql -U medvue -d postgres \
  -c 'DROP DATABASE medvue WITH (FORCE)' -c 'CREATE DATABASE medvue'
# 4. Restaurer
docker cp "$DUMP" medvue-database:/tmp/restore.dump
docker exec medvue-database pg_restore -U medvue -d medvue --no-owner --no-acl --exit-on-error /tmp/restore.dump
docker exec medvue-database rm -f /tmp/restore.dump
# 5. Redémarrer et vérifier
docker compose -f docker-compose.prod.yml start backend
docker exec medvue-backend php bin/console doctrine:migrations:up-to-date
curl -fsS https://api.medvue.be/api/health          # {"status":"ok","database":"ok"}
```

Si l'étape 4 échoue, la base est vide : restaurer le dump de sécurité de
l'étape 1 de la même façon. Ne jamais `docker compose down -v`.

### 4.2 Restaurer les clés JWT

Nécessite le `.env` d'origine (même `JWT_PASSPHRASE`).

```bash
docker compose -f docker-compose.prod.yml stop backend
docker run --rm --pull never --network none -i -v medvue_jwt_keys:/data alpine \
  sh -c 'rm -f /data/private.pem /data/public.pem && tar xzf - -C /data && chmod 600 /data/private.pem' < "$KEYS"
docker compose -f docker-compose.prod.yml start backend
docker exec medvue-backend php bin/console lexik:jwt:check-config
```

Puis un `login` réel doit réussir. Si la passphrase d'origine est perdue, les
clés sauvegardées sont inutilisables : supprimer `private.pem`/`public.pem` du
volume, relancer `lexik:jwt:generate-keypair`. Les access tokens en cours
(15 min) deviennent invalides, mais les refresh tokens (base de données) restent
valides : les clients obtiennent de nouveaux access tokens sans se reconnecter.

### 4.3 Perte totale du serveur

1. Reconstruire l'hôte selon `server-production.md` (Docker, réseau `proxy`,
   Traefik, DNS).
2. Redéployer MedVue depuis le tag (`docs/deployment.md` §1 étapes 2 à 5), en
   recréant le `.env` **d'origine** (conservé hors serveur : mêmes mots de
   passe PostgreSQL et passphrase JWT) ; `SYMFONY_TRUSTED_PROXIES` = nouvelle
   adresse de Traefik.
3. `docker compose -f docker-compose.prod.yml up -d --no-build database`, puis
   restaurer le dump (4.1, sans l'étape 1) et les clés (4.2).
4. Démarrer le reste, puis les checks de santé (`docs/deployment.md` §2).

## 4 bis. Statut lisible par l'administration (D177)

En fin de run, chaque script écrit un petit JSON dans
`/home/deploy/backups/medvue/status/` (droits de `deploy`, écriture atomique,
best-effort : un échec d'écriture n'échoue jamais la sauvegarde) :

- `backup.json` : `finishedAt` (UTC), `result` (`success`/`failure`),
  `failures`, `postgresDumpBytes`, `latestMigration` ;
- `restore-test.json` : `finishedAt`, `result`, `compareLive`.

`restore-test.json` décrit **le dernier test lancé, quel qu'il soit** : aucun
cron ne lance `medvue-restore-test.sh`, il ne change qu'à un lancement manuel.
`result` est la seule issue (`success` = `RESTORE TEST: PASS`). `compareLive`
dit seulement si l'option `--compare-live` était passée : `false` veut dire
« pas de comparaison avec la base en service demandée », **jamais** « la
comparaison a échoué » (un écart avec la base en service donne
`result: failure`). Le fichier ne dit pas quelle sauvegarde a été testée : un
test sur un dump ancien (§2 bis) remplace le statut d'un test sur la dernière
sauvegarde. La page « Infrastructure » n'affiche que `finishedAt` et `result`.

Aucun chemin, aucun secret. Ce répertoire est monté **en lecture seule** dans
`medvue-backend` (`/app/var/ops-status`) : la page « Infrastructure » de
`/admin` lit ces fichiers, ne déclenche jamais rien et ne voit pas les dumps
(`docs/admin.md` §7). Fichier absent → « Non vérifiable ».

## 5. Limites connues (à connaître, pas à cacher)

- **Aucune copie hors du serveur.** Les sauvegardes sont locales : une perte du
  disque ou du serveur les emporte avec la base. Constaté le 2026-09-20 :
  `rclone` n'est pas installé sur le serveur, donc le `sync_gdrive.sh` existant
  (SurgicalHub, non modifié ici) échoue chaque nuit ; MedVue ne l'utilise pas.
  Une copie hors serveur chiffrée est la prochaine amélioration à décider.
  *Mise à jour 2026-10-23* : `rclone` est désormais installé
  (`/home/deploy/bin`) et `sync_gdrive.sh` réussit chaque nuit, mais il ne
  copie que `/home/deploy/backups/mysql` et `/home/deploy/backups/uploads`.
  **Aucune sauvegarde MedVue n'est hors du serveur**, ni celles de la rotation
  normale ni celles de `reserved/` (§2 bis).
- **Objectif de point de reprise : 24 h** (une sauvegarde par nuit).
- **`.env` non sauvegardé** (voir §1) : sa conservation hors serveur est une
  obligation manuelle. Sans lui, la restauration des clés est impossible (elles
  se régénèrent, §4.2) et le mot de passe du rôle PostgreSQL est à
  reconstruire.
- Les sauvegardes ne sont pas chiffrées au repos : elles sont protégées par
  les droits Unix (`700`/`600`, utilisateur `deploy`). À compléter avant toute
  copie hors serveur.

## 6. Interdits

Voir `docs/deployment.md` §7. En particulier : **`docker compose down -v`
est interdit en production** (il supprime `medvue_db_data` et
`medvue_jwt_keys`), de même que `docker volume rm/prune` et
`docker system prune --volumes`. Aucun de ces scripts n'en contient, et un test
(`backend/tests/Deployment`) le garantit.
