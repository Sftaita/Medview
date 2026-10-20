# Mise en production des périodes successives — plan (lot L7)

> **Statut : étape A exécutée le 2026-10-20** (`v2026.10.20-prod`,
> `0948c0f`, création des périodes désactivée — détail et écarts à la
> procédure dans [`deployment.md`](deployment.md) §6). **Étape B non
> autorisée.** Pour une prochaine migration non additive : le `--dry-run`
> ne couvre pas une migration qui lit des colonnes créées par une
> précédente (capturer le SQL sur une restauration jetable), et la bascule
> se fait backend et worker arrêtés (migration depuis un conteneur jetable
> de la nouvelle image), jamais `up` avant `migrate`. Chantier D185 → D205, branche
> `feature/periods-components`. Procédures générales :
> [`deployment.md`](deployment.md) (§1 étape 6, §2, §3, §4),
> [`backup.md`](backup.md), spécification
> [`planning-cycles.md`](planning-cycles.md) §6.

## 0. Préalables bloquants

| # | Préalable | État au 2026-10-19 |
|---|---|---|
| P1 | Arbitrage sur la lecture du calendrier par un simple membre : `/result` et `/assignments` renvoyaient les affectations d'une ligne générée non publiée (règle D148 antérieure au chantier ; avec la publication par ligne, un brouillon côtoie des lignes publiées). | **Levé** : décision de l'utilisateur, accès fermé côté serveur (D204) |
| P2 | Répétition des migrations et de la lecture applicative sur une restauration jetable du **dernier** dump de production (sur le serveur, rien téléchargé) avec le code final (L4d → L7 n'ajoutent aucune migration, mais lisent les données autrement). | **Levé** : dump du 2026-10-19, tout identique, retour arrière démontré (D205) |
| P3 | CI complète verte sur la branche poussée, dont les 18 tests `tests/Deployment` ignorés en développement (lancés en local dans un conteneur jetable : 27/27 après correction de `.env.prod.example`). | À faire après le push autorisé |
| P4 | Les tags et le serveur au même commit que `master` (rapport d'écart si le serveur a du retard, `deployment.md` §3). | À vérifier le jour J |

## 1. Commits proposés (non créés)

Branche `feature/periods-components`, depuis `8c26306`. Un commit par
ensemble cohérent, messages en anglais comme l'historique :

1. `docs(periods): specification, mockup and decisions D185-D205` —
   `docs/planning-cycles.md`, `docs/deployment-periods.md`,
   `docs/Design/medvue-periodes/**`, `docs/decisions.md`, et les documents
   mis à jour (`availability.md`, `calendar-subscription.md`,
   `duty-swaps.md`, `planning-export.md`, `planning-generation.md`).
2. `feat(periods): planning cycles domain and migrations (L1-L4c)` —
   `backend/migrations/Version2026101018…`, `…1012…`, `…1013…`, `…1014…`,
   `…1016…`, entités, dépôts, `PlanningCycle*`, `PeriodBoundaryGuard`,
   `LineApplicability*`, commande d'audit, exceptions.
3. `feat(periods): period-aware generation, publication, reads and statistics (L2-L5, L7)`
   — services, contrôleurs, templates d'email, `config/`, `.env`
   (`MEDVUE_PERIOD_CREATION_ENABLED=0`), `.env.test`,
   **`.env.prod.example`** (clé ajoutée en L7).
4. `test(periods): backend coverage of the period lots` — `backend/tests/**`.
5. `feat(periods): successive periods in the interface (L6-L7)` —
   `frontend/**` (dont la suppression de `pages/dev/PeriodsPreviewPage.tsx`
   et des vues sur données fictives).

La politique de lecture D204 (`CalendarReadPolicy`, filtres de `/result` et
`/assignments`, réponses des échanges) touche des fichiers déjà modifiés par
les lots précédents : elle suit ce découpage (3 : service et contrôleurs ;
4 : `PlanningReadConfidentialityTest` et tests ajustés ; 5 :
`hasReadableCalendar`), chaque message citant D204.

L'index contient un état partiel ancien (fichiers `A`/`AM`/`AD`) : chaque
commit est préparé par `git add -A -- <chemins>`, qui aligne l'index sur la
copie de travail ; les fichiers `AD` (aperçu `pages/dev/PeriodsPreviewPage.tsx`,
`PeriodPlanningsView.tsx`, `PeriodPlanningDetailView.tsx`,
`PeriodViews.test.tsx`) ne sont alors jamais commités.

Exclus : `backend/config/reference.php` (régénéré par Symfony, fins de ligne
seulement), `backend/.env.local` (ignoré), fichiers de travail.
Suppressions incluses : `backend/src/Dto/CreateDutyAssignmentRequest.php`,
`backend/src/Exception/DuplicateDutyAssignmentException.php` (endpoint
technique fermé, D197).

## 2. Étape A — nouveau code, création des périodes désactivée

**But** : faire tourner le code multipériode en production sans qu'aucun
planning puisse recevoir une deuxième période.

1. **Sauvegarde** : `medvue-backup.sh`, puis
   `medvue-restore-test.sh --compare-live` → PASS (`backup.md`). Noter le
   nom du dump : c'est le point de retour.
2. **Contrôles préalables, lecture seule** (`planning-cycles.md` §6.3) :
   périodes orphelines, dates des périodes ≠ dates du planning, collectes
   hors dates, jobs actifs (attendu 0), propriétaire des tables append-only.
3. **`.env` de production** : ajouter `MEDVUE_PERIOD_CREATION_ENABLED=0`
   explicitement (le test `ProdComposeTest` exige que chaque clé de
   `backend/.env` y soit redéfinie).
4. **Archive LF** du commit tagué, extraite par-dessus
   `/opt/stack/apps/medvue` (`deployment.md` §3) ; build
   `APP_VERSION=v<date>-prod`, `up -d --no-build` quand aucun job n'est
   actif.
5. **Migrations**, séquence complète de `deployment.md` §1 étape 6 :
   `doctrine:migrations:status`, `migrate --dry-run --write-sql`, relecture
   (seules écritures attendues : remplissage du cycle 1, rattachements,
   reconstruction des `planning_publication_lines` marquées
   `reconstructed`), puis `migrate`. Cinq migrations, dans l'ordre :
   `Version20261010180000` (L1), `…1012090000` (L2), `…1013090000` (L3),
   `…1014090000` (L4b), `…1016090000` (L4c).
6. `cache:clear`, `restart backend worker`, `check-trusted-proxy.sh`.
7. **Preuve de conservation** : `app:planning-cycles:audit` avant (sur la
   restauration de l'étape 1) et après : empreintes identiques hors colonnes
   ajoutées.
8. **Contrôles post-déploiement** (lecture, sans écrire de donnée métier) :
   santé `deployment.md` §2 ; `GET /api/plannings` (`periodCount: 1`,
   `currentPeriod`/`nextPeriod`/`latestPeriod`) ; détail
   (`canCreatePeriod: false`, `canOpenPeriodCollection: false`) ; anciens
   PDF de publication servis à l'octet près ; statistiques sans `?period`
   (une seule période) ; « Mes gardes », flux iCal ; `POST …/periods` →
   409 `period_creation_disabled` ; tâche du samedi et reprises cron
   inchangées ; `/admin` affiche la nouvelle version.
9. **Sauvegarde** après déploiement + `restore-test` PASS.

**Comportement avec `MEDVUE_PERIOD_CREATION_ENABLED=0`** : chaque planning a
exactement une période ; créer, raccourcir, allonger ou supprimer une
période, et ouvrir une collecte par période sont refusés (409) ; les écrans
n'offrent aucune de ces actions ; tout le reste se comporte comme avant
(prolongation D122, collecte de création, génération, publication — y
compris par ligne, D196 —, échanges, exports, statistiques).

**Conditions d'arrêt (étape A)** : un `--dry-run` qui contient une écriture
non attendue ; une assertion de migration qui échoue (la migration s'annule
seule, transactionnelle) ; une empreinte d'audit différente ; un contrôle de
santé ou post-déploiement en échec ; un ancien PDF qui ne se rend plus.

**Retour arrière (étape A)** : tant qu'aucun deuxième cycle n'existe (garanti
par le flag), chaque `down()` est réversible sans perte — L4c → L4b → L3 → L2
→ L1 puis redéploiement du tag précédent (`planning-cycles.md` §6.1,
D189/D195/D196) — **sauf** :
- `down()` de L4c refuse dès la **première publication ou republication**
  après le déploiement : toute publication enregistre ses lignes (D196,
  démontré par la répétition D205) ;
- `down()` de L4b refuse si un job a été demandé avec le périmètre
  `unlocked_lines` (D195) — possible dès qu'une période a une ligne
  publiée et une autre non ;
- `down()` de L3 refuse si un engagement d'une autre période a été figé
  (D189) — impossible avec le flag désactivé.
Le code précédent se remet en reconstruisant l'archive du tag
`v2026.10.10-prod` (l'image `medvue-backend:local` est remplacée au build,
`deployment.md` §4).
Dans ces cas : correctif en avant, ou restauration du dump de l'étape 1
**seulement** si aucune publication, réaffectation, échange ou saisie n'a eu
lieu depuis ; sinon la restauration perd ces données et doit être décidée
explicitement (jamais présentée comme sans perte).

## 3. Étape B — activation des périodes successives (séparée)

**Seulement après** : étape A en production validée explicitement,
période d'observation sans incident (P1 et P2 levés : D204, D205).

1. Sauvegarde + `restore-test` PASS (nouveau point de retour).
2. `MEDVUE_PERIOD_CREATION_ENABLED=1` dans le `.env` de production,
   `restart backend worker` (lu au démarrage du conteneur).
3. Activation progressive : un seul planning pilote, par son créateur —
   créer la période 2, ouvrir sa collecte, générer, publier ; vérifier la
   période 1 inchangée (`app:planning-cycles:audit`), « Mes gardes », iCal,
   rappel du samedi, statistiques (période / cumul publié / en préparation).
4. Élargissement aux autres plannings après validation du pilote.

**Retour arrière (étape B)** : avant la première période 2 créée, remettre
`0` suffit. **Dès qu'une période 2 existe, aucun `down()` n'est
réversible** (`planning-cycles.md` §6.1) : correctif en avant (cas
normal) ; remettre le flag à `0` bloque toute nouvelle création mais laisse
les périodes existantes lisibles et utilisables ; la restauration du dump de
l'étape B.1 perd tout ce qui a été saisi depuis (décision explicite
uniquement).

**Conditions d'arrêt (étape B)** : une empreinte de la période 1 qui change
après la création de la période 2 ; une réponse 5xx sur un parcours par
période ; un membre qui verrait un brouillon (contraire à D204) ; un
email ou un rappel qui mélangerait deux périodes.

## 4. Protection de SurgicalHub et des autres applications

- Aucun fichier, conteneur, réseau, volume ni crontab d'une autre
  application n'est touché ; seules les commandes `docker compose
  -f docker-compose.prod.yml` du répertoire `/opt/stack/apps/medvue`.
- Les endpoints SurgicalHub (D182-D184) ne changent pas ; la synchronisation
  des congés continue (cron 30 min) ; à vérifier : `app:surgicalhub:sync`
  sans erreur après redémarrage.
- Jamais `docker compose down -v`, jamais `docker volume rm`, jamais de
  prune global (`deployment.md` §7).
- En développement, le Mailpit sur le port 8025 est celui d'une autre
  application ; celui de MedVue est sur 8026.
