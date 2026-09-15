# MedVue

Application de gestion des gardes médicales (remplacement de Lifen Planning).

Deux étapes livrées à ce jour :

1. **Socle technique** : frontend, backend et base de données qui démarrent
   proprement ensemble, outils de qualité (lint, tests, CI) en place.
2. **Authentification** (vertical slice complet) : inscription, connexion,
   `GET /api/me`, JWT, compte désactivable. Détails et choix documentés dans
   [`docs/authentication.md`](docs/authentication.md).

Pas encore implémenté : équipes, plannings, indisponibilités, moteur
d'équité, vérification d'email, mot de passe oublié.

**[`docs/decisions.md`](docs/decisions.md)** trace, dans l'ordre, chaque
choix technique structurant (pourquoi tel outil, tel compromis, tel
contournement) — c'est le premier document à consulter avant de remettre en
cause une décision existante.

## Arborescence

```
medvue/
├── docker-compose.yml        # orchestration des 3 services (db, backend, frontend)
├── .env / .env.example        # ports et identifiants Postgres pour docker-compose
├── .github/workflows/ci.yml   # CI GitHub Actions (backend + frontend)
│
├── docs/
│   ├── decisions.md            # journal chronologique des choix techniques
│   └── authentication.md      # modèle de données, stratégie JWT, décisions ouvertes
│
├── backend/                   # API Symfony 7.4 (PHP 8.3 en conteneur, Composer inclus)
│   ├── Dockerfile
│   ├── src/
│   │   ├── Controller/
│   │   │   ├── HealthController.php       # GET /api/health (infra)
│   │   │   ├── RegistrationController.php # POST /api/register
│   │   │   ├── SecurityController.php     # route /api/login (sentinelle, cf. docs/authentication.md)
│   │   │   └── AccountController.php      # GET /api/me
│   │   ├── Entity/User.php
│   │   ├── Repository/UserRepository.php
│   │   ├── Security/UserChecker.php       # bloque les comptes désactivés
│   │   ├── Service/UserRegistrationService.php
│   │   ├── Dto/RegisterUserRequest.php
│   │   └── Exception/EmailAlreadyUsedException.php
│   ├── tests/Controller/
│   │   ├── HealthControllerTest.php
│   │   ├── RegistrationControllerTest.php
│   │   └── AuthenticationTest.php
│   ├── config/packages/        # api_platform, doctrine, security, lexik_jwt, nelmio_cors...
│   ├── config/jwt/              # clés RS256 générées (gitignorées)
│   ├── migrations/
│   └── composer.json           # scripts: cs-check, cs-fix, test
│
└── frontend/                   # React 19 + TypeScript + Vite
    ├── Dockerfile
    └── src/
        ├── App.tsx             # routing + layout + nav conditionnelle (connecté/non)
        ├── features/
        │   ├── auth/            # context.ts, AuthContext.tsx, useAuth.ts, api.ts,
        │   │                    # ProtectedRoute.tsx, types.ts
        │   └── teams/ availability/ planning/ duties/ swaps/ notifications/
        │       fairness/ system/  (vides ou minimales, structure prête pour la suite)
        ├── pages/               # Dashboard, MyAvailability, MyDuties, Teams,
        │                        # TeamDetail, PlanningPeriod, AvailabilityCampaign,
        │                        # TeamAvailabilityCalendar (placeholders),
        │                        # LoginPage, RegisterPage, AccountPage (fonctionnelles)
        └── lib/apiClient.ts     # apiFetch(): JWT auto, parsing JSON, gestion 401
```

## Choix techniques

| Domaine | Choix |
|---|---|
| Backend | Symfony 7.4, PHP 8.3 |
| API | API Platform 4 (installé ; aucune ressource `ApiResource` pour l'instant — voir `docs/authentication.md` §1 pour le choix de ne pas exposer `User` en CRUD) |
| ORM / DB | Doctrine ORM + Doctrine Migrations, PostgreSQL 16 |
| Auth | LexikJWTAuthenticationBundle + Symfony Security, JWT RS256, `json_login`. Détails : `docs/authentication.md` |
| CORS | NelmioCorsBundle, autorisé sur `/api/` pour les origines localhost |
| Isolation des tests DB | dama/doctrine-test-bundle (chaque test dans une transaction annulée) |
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

Au premier démarrage, Symfony n'a pas encore créé la base applicative ni les
clés JWT :

```bash
docker compose exec backend php bin/console doctrine:database:create --if-not-exists
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console lexik:jwt:generate-keypair --skip-if-exists
```

### URLs

| Service | URL |
|---|---|
| Frontend (Vite dev server) | http://localhost:5183 |
| Backend (API) | http://localhost:8010 |
| Health check | http://localhost:8010/api/health |
| Inscription | `POST` http://localhost:8010/api/register |
| Connexion | `POST` http://localhost:8010/api/login |
| Profil courant | `GET` http://localhost:8010/api/me (JWT requis) |
| PostgreSQL | localhost:5432 (user/db: `app`, mot de passe dans `.env`) |

Détail des endpoints, formats de requête/réponse et codes d'erreur :
[`docs/authentication.md`](docs/authentication.md).

Ces ports par défaut (`8010`/`5183`) ont été choisis pour éviter un conflit
avec d'autres projets déjà lancés sur cette machine (des services utilisaient
déjà `8000` et `5173`). Ajustez `BACKEND_PORT` / `FRONTEND_PORT` /
`POSTGRES_PORT` dans `.env` si besoin.

### Commandes utiles

```bash
# Backend
docker compose exec backend php bin/console <commande>
docker compose exec backend composer require <package>   # Composer est dans l'image
docker compose exec backend php bin/console doctrine:migrations:diff
docker compose exec backend php vendor/bin/phpunit
docker compose exec backend php vendor/bin/php-cs-fixer fix --dry-run --diff

# Frontend
docker compose exec frontend npm run lint
docker compose exec frontend npx tsc -b --noEmit
docker compose exec frontend npm run test
docker compose exec frontend npm run format

# Arrêter la stack
docker compose down          # ajouter -v pour supprimer aussi les volumes (⚠️ perte des données Postgres)
```

> `vendor/` vit dans le volume nommé `backend_vendor` (voir plus haut) : un
> `composer require` exécuté dans le conteneur en cours d'exécution prend
> effet immédiatement. Reconstruire l'image (`docker compose build backend`)
> seulement pour qu'un autre poste qui repart de zéro (`docker compose up
> --build`) retrouve le même `vendor/` sans avoir à relancer `composer
> install`.

## Tests effectués

### Socle (étape précédente, toujours vrai)

- `GET /api/health` répond `{"status":"ok","database":"ok"}` en interrogeant
  réellement PostgreSQL, depuis le conteneur backend comme depuis l'hôte.
- CORS vérifié : une requête avec `Origin: http://localhost:5183` vers
  l'API reçoit bien l'en-tête `Access-Control-Allow-Origin`.
- Les trois conteneurs démarrent proprement via `docker compose up -d`, dans
  l'ordre attendu (`database` doit être *healthy* avant que `backend`
  démarre).

### Authentification (cette étape)

Suite complète (backend + frontend), exécutée dans les conteneurs :

- `docker compose exec backend php vendor/bin/phpunit` → **9 tests, 29
  assertions**, tous verts :
  - `HealthControllerTest` (1, hérité du socle) ;
  - `RegistrationControllerTest` (3) : inscription valide → `201` sans
    `passwordHash` ; email déjà utilisé → `409` ; mot de passe < 8
    caractères → `422` avec le détail de la violation ;
  - `AuthenticationTest` (5) : connexion valide → `200` + token non vide ;
    mauvais mot de passe → `401` ; utilisateur désactivé → `401` (message
    générique, voir `docs/authentication.md` §3) ; `GET /api/me` avec token
    valide → `200` avec les données utilisateur ; `GET /api/me` sans token
    → `401`.
  - Chaque test s'exécute dans une transaction annulée automatiquement
    (`dama/doctrine-test-bundle`) : `SELECT count(*) FROM users` en base de
    test revient à `0` après la suite, aucune pollution entre exécutions.
- `docker compose exec backend php vendor/bin/php-cs-fixer fix --dry-run --diff`
  → 0 fichier à corriger.
- `docker compose exec frontend npm run test` (Vitest) → **4 tests**, tous
  verts : rendu de `App` (redirige vers `/login` si non authentifié, affiche
  le tableau de bord si une session valide est déjà stockée) et flux complet
  de `LoginPage` (connexion réussie → redirection + token en
  `localStorage` ; identifiants invalides → message d'erreur affiché, aucun
  token stocké).
- `docker compose exec frontend npm run lint` (oxlint) → 0 erreur, 0
  avertissement.
- `npx tsc -b --noEmit` → aucune erreur de typage.
- `docker compose exec frontend npm run format:check` (Prettier, config
  ajoutée dans `frontend/.prettierrc.json`) → tous les fichiers conformes.
- Vérification manuelle bout-en-bout via `curl` (inscription → connexion →
  `/api/me` avec et sans token → désactivation en base → nouvelle tentative
  de connexion refusée) avant l'écriture des tests automatisés, pour
  valider le comportement réel avant de le figer dans des assertions.

## Points restant à décider

- **Stockage du token (`localStorage` vs cookie `httpOnly`)**, **absence de
  refresh token**, **rate limiting sur `/api/login`**, **vérification
  d'email**, **mot de passe oublié** : détaillés avec leurs implications
  dans [`docs/authentication.md` §7](docs/authentication.md#7-décisions-ouvertes--dette-assumée).
- **Ports par défaut** : `8010`/`5183` évitent les conflits observés sur
  cette machine de dev, mais ne sont pas des conventions figées — à aligner
  si l'équipe préfère d'autres valeurs.
- **CI** : le workflow suppose un dépôt GitHub avec une branche `main` ou
  `master` ; aucun remote n'est configuré pour l'instant (dépôt Git local
  uniquement).
