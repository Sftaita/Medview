# MedVue

Application de gestion des gardes médicales (remplacement de Lifen Planning).

Ce dépôt contient uniquement le **socle technique** du projet : aucune entité
métier, aucun moteur de planning, aucune logique d'authentification n'est
implémentée à ce stade. L'objectif de cette étape est d'avoir un frontend, un
backend et une base de données qui démarrent proprement ensemble, avec les
outils de qualité (lint, tests, CI) déjà en place.

## Arborescence

```
medvue/
├── docker-compose.yml        # orchestration des 3 services (db, backend, frontend)
├── .env / .env.example        # ports et identifiants Postgres pour docker-compose
├── .github/workflows/ci.yml   # CI GitHub Actions (backend + frontend)
│
├── backend/                   # API Symfony 7.4 (PHP 8.3 en conteneur)
│   ├── Dockerfile
│   ├── src/
│   │   └── Controller/
│   │       └── HealthController.php   # GET /api/health (infra, pas de métier)
│   ├── tests/
│   │   └── Controller/HealthControllerTest.php
│   ├── config/packages/        # api_platform, doctrine, security, lexik_jwt, nelmio_cors...
│   ├── migrations/             # vide pour l'instant, prêt pour doctrine:migrations:diff
│   └── composer.json           # scripts: cs-check, cs-fix, test
│
└── frontend/                   # React 19 + TypeScript + Vite
    ├── Dockerfile
    └── src/
        ├── App.tsx             # routing + layout de base
        ├── features/           # auth/ teams/ availability/ planning/ duties/
        │                       # swaps/ notifications/ fairness/ system/ (vides,
        │                       # structure prête pour la suite)
        ├── pages/               # Dashboard, MyAvailability, MyDuties, Teams,
        │                       # TeamDetail, PlanningPeriod, AvailabilityCampaign,
        │                       # TeamAvailabilityCalendar (placeholders)
        └── lib/apiClient.ts     # client fetch vers l'API
```

## Choix techniques

| Domaine | Choix |
|---|---|
| Backend | Symfony 7.4, PHP 8.3 |
| API | API Platform 4 (installé, aucune ressource exposée pour l'instant) |
| ORM / DB | Doctrine ORM + Doctrine Migrations, PostgreSQL 16 |
| Auth (préparé, non branché) | LexikJWTAuthenticationBundle + Symfony Security |
| CORS | NelmioCorsBundle, autorisé sur `/api/` pour les origines localhost |
| Frontend | React 19 + TypeScript + Vite 8 |
| Routing frontend | react-router-dom |
| Tests backend | PHPUnit (symfony/test-pack) |
| Tests frontend | Vitest + Testing Library |
| Lint backend | PHP CS Fixer (jeu de règles `@Symfony`) |
| Lint frontend | oxlint (fourni par le template Vite) |
| Conteneurisation | Docker Compose : `database` (postgres), `backend` (FrankenPHP), `frontend` (node dev server) |
| CI | GitHub Actions : job `backend` (composer, cs-check, phpunit avec postgres en service) et job `frontend` (npm ci, lint, tsc, vitest) |

**Pourquoi FrankenPHP plutôt que PHP-FPM + Nginx ?** Un seul conteneur sert le
code PHP et le HTTP (image officiellement recommandée par Symfony pour
Docker), donc moins de configuration à maintenir qu'une paire Nginx/PHP-FPM
pour un gain équivalent en dev.

**Point d'architecture important pour Windows/WSL** : `vendor/` (backend) et
`node_modules/` (frontend) ne sont **pas** servis depuis le bind mount
Windows, mais depuis des volumes Docker nommés (`backend_vendor`,
`backend_var`, `frontend_node_modules`). Le bind mount Windows→Linux est trop
lent pour des arbres de milliers de petits fichiers : la première requête
dépassait le `max_execution_time` de 30s de PHP avant cette correction. Le
code applicatif (`src/`, `config/`, `public/`, etc.) reste bind-monté pour le
rechargement à chaud.

## Démarrer le projet

Prérequis : Docker Desktop.

```bash
cp .env.example .env        # ajuster les ports si 8010/5183/5432 sont déjà pris
docker compose up -d --build
```

Au premier démarrage, Symfony n'a pas encore créé la base applicative :

```bash
docker compose exec backend php bin/console doctrine:database:create --if-not-exists
```

### URLs

| Service | URL |
|---|---|
| Frontend (Vite dev server) | http://localhost:5183 |
| Backend (API) | http://localhost:8010 |
| Health check | http://localhost:8010/api/health |
| PostgreSQL | localhost:5432 (user/db: `app`, mot de passe dans `.env`) |

Ces ports par défaut (`8010`/`5183`) ont été choisis pour éviter un conflit
avec d'autres projets déjà lancés sur cette machine (des services utilisaient
déjà `8000` et `5173`). Ajustez `BACKEND_PORT` / `FRONTEND_PORT` /
`POSTGRES_PORT` dans `.env` si besoin.

### Commandes utiles

```bash
# Backend
docker compose exec backend php bin/console <commande>
docker compose exec backend php vendor/bin/phpunit
docker compose exec backend php vendor/bin/php-cs-fixer fix --dry-run --diff

# Frontend
docker compose exec frontend npm run lint
docker compose exec frontend npm run test
docker compose exec frontend npm run format

# Arrêter la stack
docker compose down          # ajouter -v pour supprimer aussi les volumes (⚠️ perte des données Postgres)
```

## Tests effectués pour valider ce socle

- `GET /api/health` répond `{"status":"ok","database":"ok"}` en interrogeant
  réellement PostgreSQL (`SELECT 1`), depuis le conteneur backend comme
  depuis l'hôte via le port publié.
- `docker compose exec backend php vendor/bin/phpunit` → 1 test passe
  (`HealthControllerTest`, test fonctionnel `WebTestCase`).
- `docker compose exec backend php vendor/bin/php-cs-fixer fix --dry-run --diff`
  → 0 fichier à corriger.
- `docker compose exec frontend npm run lint` → 0 erreur (oxlint).
- `docker compose exec frontend npm run test` → 1 test passe (rendu de
  `App`, health check mocké).
- `npx tsc -b --noEmit` → aucune erreur de typage.
- CORS vérifié : une requête avec `Origin: http://localhost:5183` vers
  `http://localhost:8010/api/health` reçoit bien l'en-tête
  `Access-Control-Allow-Origin`.
- Les trois conteneurs démarrent proprement via `docker compose up -d`, dans
  l'ordre attendu (`database` doit être *healthy* avant que `backend`
  démarre).

## Points restant à décider avant la suite (authentification)

- **Génération des clés JWT** : `lexik/jwt-authentication-bundle` est
  installé et configuré (`JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`,
  `JWT_PASSPHRASE` dans `.env`), mais la paire de clés n'a pas été générée
  (`bin/console lexik:jwt:generate-keypair`) et aucun firewall ne l'utilise
  encore — `config/packages/security.yaml` est toujours au provider
  `in_memory` par défaut du skeleton. À faire à l'étape auth.
- **Composer dans le conteneur backend** : volontairement absent de l'image
  (le conteneur embarque `vendor/` déjà installé côté hôte). Pour ajouter un
  package, lancer `composer require ...` sur l'hôte puis reconstruire
  l'image (`docker compose build backend`). Si ce flux est gênant, on peut
  installer Composer dans le Dockerfile.
- **Ports par défaut** : `8010`/`5183` évitent les conflits observés sur
  cette machine de dev, mais ne sont pas des conventions figées — à aligner
  si l'équipe préfère d'autres valeurs.
- **CI** : le workflow suppose un dépôt GitHub avec une branche `main` ou
  `master` ; aucun remote n'est configuré pour l'instant (dépôt Git local
  uniquement, `git init` fait mais pas de premier commit avant validation).
