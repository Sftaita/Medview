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
- Clés JWT persistantes (D107) : empreinte de `public.pem` identique avant
  et après `docker compose -f docker-compose.prod.yml up -d --force-recreate backend`
  (`docker exec medvue-backend sha256sum /app/config/jwt/public.pem`, sans
  jamais afficher la clé), `lexik:jwt:check-config` OK, et un `login` réel
  fonctionne toujours après la recréation.
- `medvue-database`, `medvue-backend`, `medvue-frontend` tous `Up`.
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
docker compose -f docker-compose.prod.yml restart backend
scripts/deploy/check-trusted-proxy.sh
```

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
