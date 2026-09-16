# MedVue

Application de gestion des gardes médicales (remplacement de Lifen Planning).

Quatre étapes livrées à ce jour :

1. **Socle technique** : frontend, backend et base de données qui démarrent
   proprement ensemble, outils de qualité (lint, tests, CI) en place.
2. **Authentification** (vertical slice complet) : inscription, connexion,
   `GET /api/me`, compte désactivable.
3. **Authentification renforcée** : rate limiting (`/login`, `/register`),
   access token JWT court (15 min) + refresh token opaque rotatif en cookie
   `HttpOnly`, révocation/détection de réutilisation, logout.
4. **UAT complète en navigateur réel** (2026-09-15) : validation
   fonctionnelle bout-en-bout (Network, cookies, storage, multi-onglets,
   responsive, indisponibilité backend), 2 failles **BLOCKER** de fuite de
   stack trace trouvées et corrigées avec filet de sécurité systémique, 5
   failles MEDIUM/HIGH corrigées (redirection si déjà connecté, sync
   multi-onglets, anti-double-soumission, message de rate limiting).

Détails et choix documentés dans
[`docs/authentication.md`](docs/authentication.md) (§14 pour cette
dernière étape).

Pas encore implémenté : équipes, plannings, indisponibilités, moteur
d'équité, vérification d'email, mot de passe oublié. Le design du moteur de
répartition des gardes est en revanche déjà écrit (document vivant, à
affiner au fil de l'implémentation) :
[`docs/allocation-algorithm.md`](docs/allocation-algorithm.md).

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
│   ├── authentication.md      # modèle de données, stratégie JWT, décisions ouvertes
│   └── allocation-algorithm.md # design du moteur de répartition des gardes (vivant, non implémenté)
│
├── backend/                   # API Symfony 7.4 (PHP 8.3 en conteneur, Composer inclus)
│   ├── Dockerfile
│   ├── src/
│   │   ├── Controller/
│   │   │   ├── HealthController.php       # GET /api/health (infra)
│   │   │   ├── RegistrationController.php # POST /api/register (+ rate limit)
│   │   │   ├── SecurityController.php     # route /api/login (sentinelle, cf. docs/authentication.md)
│   │   │   ├── AccountController.php      # GET /api/me
│   │   │   ├── RefreshTokenController.php # POST /api/token/refresh
│   │   │   └── LogoutController.php       # POST /api/token/logout
│   │   ├── Entity/{User,RefreshToken}.php
│   │   ├── Repository/{UserRepository,RefreshTokenRepository}.php
│   │   ├── Security/
│   │   │   ├── UserChecker.php               # bloque les comptes désactivés
│   │   │   ├── LoginSuccessHandler.php        # JWT + cookie refresh
│   │   │   ├── LoginFailureHandler.php        # 401 générique ou 429 (throttling)
│   │   │   ├── RefreshTokenCookieFactory.php  # attributs du cookie centralisés
│   │   │   └── RefreshTokenFailureReason.php  # enum interne (logs/tests, jamais exposé)
│   │   ├── Service/{UserRegistrationService,RefreshTokenService}.php
│   │   ├── Dto/RegisterUserRequest.php
│   │   ├── Exception/{EmailAlreadyUsedException,InvalidRefreshTokenException}.php
│   │   └── EventListener/ApiExceptionListener.php  # filet JSON pour /api/* (UAT, cf. §14)
│   ├── tests/
│   │   ├── AuthenticationTestHelpers.php  # trait partagé (register/login/cookie helpers)
│   │   ├── Security/RefreshTokenCookieFactoryTest.php   # unitaire, sans kernel
│   │   ├── Service/UserRegistrationServiceTest.php      # non-régression : course d'inscription
│   │   ├── EventListener/ApiExceptionListenerTest.php   # non-régression : pas de fuite HTML
│   │   └── Controller/
│   │       ├── HealthControllerTest.php
│   │       ├── RegistrationControllerTest.php
│   │       ├── AuthenticationTest.php
│   │       ├── RefreshTokenControllerTest.php
│   │       ├── LogoutControllerTest.php
│   │       ├── RateLimitingTest.php
│   │       └── CorsTest.php
│   ├── config/packages/        # api_platform, doctrine, security, lexik_jwt, nelmio_cors,
│   │                            # rate_limiter...
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
        │   │                    # ProtectedRoute.tsx, PublicOnlyRoute.tsx, types.ts
        │   └── teams/ availability/ planning/ duties/ swaps/ notifications/
        │       fairness/ system/  (vides ou minimales, structure prête pour la suite)
        ├── pages/               # Dashboard, MyAvailability, MyDuties, Teams,
        │                        # TeamDetail, PlanningPeriod, AvailabilityCampaign,
        │                        # TeamAvailabilityCalendar (placeholders),
        │                        # LoginPage, RegisterPage, AccountPage (fonctionnelles)
        └── lib/
            ├── apiClient.ts      # apiFetch(): JWT auto, refresh silencieux sur 401
            │                     # (une seule tentative, dédupliquée), gestion 401 finale
            └── formatWaitTime.ts # affichage du délai Retry-After (rate limiting)
```

## Choix techniques

| Domaine | Choix |
|---|---|
| Backend | Symfony 7.4, PHP 8.3 |
| API | API Platform 4 (installé ; aucune ressource `ApiResource` pour l'instant — voir `docs/authentication.md` §1 pour le choix de ne pas exposer `User` en CRUD) |
| ORM / DB | Doctrine ORM + Doctrine Migrations, PostgreSQL 16 |
| Auth | LexikJWTAuthenticationBundle + Symfony Security, access token JWT RS256 (15 min) + refresh token opaque rotatif en cookie `HttpOnly` (30 j). Détails : `docs/authentication.md` |
| Rate limiting | `symfony/rate-limiter` + `login_throttling` natif de Symfony Security sur `/login` et `/register` |
| CORS | NelmioCorsBundle, autorisé sur `/api/` pour les origines localhost, `allow_credentials: true` (cookie refresh) |
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
| Renouveler l'access token | `POST` http://localhost:8010/api/token/refresh (cookie refresh requis) |
| Déconnexion | `POST` http://localhost:8010/api/token/logout |
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

### Authentification — vertical slice initial

Suite complète (backend + frontend), exécutée dans les conteneurs :

- `HealthControllerTest` (1, hérité du socle) ;
- `RegistrationControllerTest` (3) : inscription valide → `201` sans
  `passwordHash` ; email déjà utilisé → `409` ; mot de passe < 8
  caractères → `422` avec le détail de la violation ;
- `AuthenticationTest` : connexion valide, mauvais mot de passe,
  utilisateur désactivé, `GET /api/me` avec/sans token.
- `docker compose exec frontend npm run test` (Vitest) : rendu de `App`,
  flux `LoginPage`.
- Vérification manuelle bout-en-bout via `curl` avant l'écriture des tests
  automatisés, pour valider le comportement réel avant de le figer dans
  des assertions.

### Authentification renforcée — rate limiting + access/refresh token (cette étape)

- `docker compose exec backend php vendor/bin/phpunit` → **31 tests, 141
  assertions**, tous verts, stable sur deux exécutions consécutives (le
  cache du rate limiter, qui persiste entre les runs, est explicitement
  nettoyé par les tests qui en dépendent — voir `docs/decisions.md` D024) :
  - `AuthenticationTest` (7, étendu) : + cookie refresh posé au login
    (`HttpOnly`, `SameSite=Lax`, `Path=/api/token`), + refresh token
    persisté en base et retrouvable par son hash.
  - `RefreshTokenControllerTest` (8) : refresh valide, rotation (ancien
    token marqué révoqué + `replacedByTokenId`, nouveau token même
    famille), refus d'un token déjà consommé, réutilisation → révocation
    de toute la famille (le token qui était encore valide un instant plus
    tôt est aussi rejeté ensuite), token expiré, token révoqué, refresh
    pour un compte désactivé (+ révocation), refresh sans cookie.
  - `LogoutControllerTest` (3) : logout révoque le token et efface le
    cookie, refresh impossible après logout, logout sans session est
    idempotent (toujours `200`).
  - `RateLimitingTest` (3) : `/api/login` bloque au 6ᵉ échec
    (`429` + `Retry-After`), le throttling est bien scopé par IP+compte
    (une autre IP peut toujours se connecter au même compte), `/api/register`
    bloque au 6ᵉ appel depuis la même IP.
  - `RefreshTokenCookieFactoryTest` (4, nouveau — unitaire, sans kernel) :
    tous les attributs de sécurité du cookie (`HttpOnly`, `SameSite=Lax`,
    `Path=/api/token`, `Secure` piloté par le paramètre, expiration alignée
    sur le TTL), et le cookie d'effacement (`clear()`) porte les mêmes
    attributs que le vrai — sinon le navigateur garderait deux entrées.
  - `CorsTest` (3, nouveau) : une origine autorisée reçoit
    `Access-Control-Allow-Origin` + `Access-Control-Allow-Credentials: true`
    (nécessaire pour le cookie refresh cross-origin) ; une origine non
    autorisée ne reçoit aucun en-tête CORS ; le préflight `OPTIONS` sur
    `/api/token/refresh` est bien credentialed. Couvre le `allow_credentials:
    true` ajouté cette étape, qui n'avait sinon aucun test dédié.
  - `RegistrationControllerTest`, `HealthControllerTest` : inchangés,
    toujours verts.
- `docker compose exec backend php vendor/bin/php-cs-fixer fix --dry-run --diff`
  → 0 fichier à corriger.
- `docker compose exec frontend npm run test` (Vitest) → **8 tests**, tous
  verts :
  - `apiClient.test.ts` (4, nouveau) : 401 → refresh silencieux → requête
    d'origine rejouée une fois ; pas de boucle si le rejeu échoue aussi
    (un seul appel refresh) ; déconnexion propre si le refresh lui-même
    échoue ; plusieurs 401 simultanés ne déclenchent qu'un seul refresh
    partagé.
  - `App.test.tsx`, `LoginPage.test.tsx` (4, mis à jour) : le bootstrap au
    montage tente désormais toujours un refresh silencieux (source de
    vérité = cookie, plus `localStorage`).
- `docker compose exec frontend npm run lint` (oxlint) → 0 erreur.
- `npx tsc -b --noEmit` → aucune erreur de typage.
- `docker compose exec frontend npm run format:check` → tous les fichiers
  conformes.
- Vérification manuelle bout-en-bout via `curl` avant l'écriture des tests
  automatisés : inscription → connexion (cookie posé) → refresh → rotation
  → réutilisation de l'ancien token rejetée **et** famille révoquée →
  refresh pour compte désactivé rejeté → logout → cookie effacé → refresh
  post-logout rejeté → logout sans cookie idempotent → rate limiting
  login (`429` au 6ᵉ essai) → rate limiting register (`429` au 6ᵉ essai).

### UAT complète en navigateur réel — 2026-09-15 (cette étape)

23 scénarios testés en conditions réelles (Chrome piloté, Network/cookies/
storage/console inspectés, DB manipulée pour simuler expiration/révocation/
désactivation) avant tout correctif. Résultat initial : 14 PASS, 5 PARTIAL,
4 FAIL — dont **2 BLOCKER** (fuite de stack trace Symfony complète sur
`/api/register` en cas de double inscription concurrente, et sur
`/api/login` avec un `Content-Type` non-JSON — une seule requête suffisait
pour ce second cas). Diagnostic, tableau complet et preuves : historique de
la conversation ; correctifs et leur justification :
[`docs/authentication.md` §14](docs/authentication.md#14-uat-du-2026-09-15--fiabilisation)
et `docs/decisions.md` D026-D030.

Après correctifs, suite complète relancée sur environnement Docker
entièrement neuf (`docker compose down -v && up`) :

- Backend : **36 tests, 153 assertions** (dont 5 nouveaux tests de
  non-régression), PHP-CS-Fixer propre, compatible PHP 8.2.
- Frontend : **12 tests** (dont 4 nouveaux), lint/typecheck/format propres,
  build de production réussi.
- Re-vérification live des 5 correctifs UX/sécurité directement dans le
  navigateur (pas seulement via les tests automatisés) : redirection
  `/login`→`/` si déjà connecté, déconnexion immédiate d'un autre onglet
  sans reload, message de rate limiting avec délai réel affiché, une seule
  requête réseau sur double-soumission (login et register).
- Corrigé au passage : `frontend/index.html` déclarait `lang="en"` alors
  que toute l'interface est en français, ce qui déclenchait une
  traduction automatique du navigateur corrompant l'affichage — trouvé en
  préparant l'UAT, corrigé (`lang="fr"`, `<title>MedVue</title>`).

## Points restant à décider

- **Responsive à affiner** (~390-500px) : la navigation se replie sans
  menu mobile dédié et un léger débordement horizontal a été observé sur
  `/login`/`/register`/`/account`. Sévérité LOW, volontairement **pas**
  corrigé pendant cette UAT (pas de refonte graphique hors périmètre) — à
  traiter avec le reste du design quand cette étape viendra.
- **Rate limiting non partagé entre plusieurs instances** (cache local par
  instance) — nécessite Redis avant un déploiement multi-instances.
- **`SameSite=Lax` suffisant tant que frontend/backend restent le même
  site** — si le déploiement évolue vers des domaines enregistrables
  réellement distincts, il faudra `SameSite=None` + `Secure` et une vraie
  protection CSRF en remplacement (voir `docs/decisions.md` D021).
- **`COOKIE_SECURE` doit passer à `true`** dès que l'app est servie en
  HTTPS — actuellement `false` par défaut pour le dev en `http://localhost`.
- **Vérification d'email**, **mot de passe oublié**, **UUID vs id
  auto-increment**, **pas d'UI "sessions actives"** : détaillés avec leurs
  implications dans
  [`docs/authentication.md` §13](docs/authentication.md#13-décisions-ouvertes--dette-assumée).
- **Ports par défaut** : `8010`/`5183` évitent les conflits observés sur
  cette machine de dev, mais ne sont pas des conventions figées — à aligner
  si l'équipe préfère d'autres valeurs.
- **CI** : le workflow suppose un dépôt GitHub avec une branche `main` ou
  `master` ; aucun remote n'est configuré pour l'instant (dépôt Git local
  uniquement).
