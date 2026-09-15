# Authentification — choix techniques

Ce document explique **comment** l'authentification est construite et
**pourquoi**, pour que les décisions restent traçables au fil des évolutions
du projet. Il complète le `README.md` racine (socle technique) et sera suivi
d'autres documents du même type (`docs/teams.md`, `docs/planning-engine.md`,
…) au fur et à mesure des fonctionnalités.

Portée de cette étape : inscription, connexion, `GET /api/me`, désactivation
d'un compte. **Pas encore** : vérification d'email, mot de passe oublié,
rôles d'équipe, refresh token.

---

## 1. Modèle de données

### Entité `User` (`backend/src/Entity/User.php`)

| Champ | Type | Notes |
|---|---|---|
| `id` | int, auto-increment | Pas d'UUID : pas de besoin identifié à ce stade (voir §7). |
| `email` | string(180), unique | Identifiant de connexion (`getUserIdentifier()`). |
| `firstName` / `lastName` | string(100) | `NotBlank`. |
| `passwordHash` | string(255) | Jamais sérialisé (pas de `#[Groups]` dessus) — voir §5. |
| `active` | bool, défaut `true` | Désactivation = `active = false`, jamais de suppression. |
| `emailVerifiedAt` | `?DateTimeImmutable` | **Réservé** pour la vérification d'email future (voir §7) ; aucun endpoint ne le renseigne encore. |
| `createdAt` / `updatedAt` | `DateTimeImmutable` | `updatedAt` est mis à jour par chaque setter métier (`touch()`). |

Table nommée `users` (et non `user`, mot réservé en PostgreSQL).

**Rôles** : `getRoles()` retourne `['ROLE_USER']` en dur, calculé en code —
pas de colonne `roles` en base. Les rôles par équipe (OWNER/ADMIN/MEMBER)
viendront de `TeamMember` quand les équipes existeront ; ils ne remplacent
pas mais s'ajoutent à ce rôle de base.

### Pourquoi pas d'entité `User` exposée en CRUD API Platform ?

Volontairement **aucune** ressource API Platform sur `User`. Inscription,
connexion et lecture du profil passent par trois contrôleurs Symfony
classiques (`RegistrationController`, la route `login` interceptée par le
firewall, `AccountController`). C'est le moyen le plus direct de garantir
qu'aucun endpoint de type `PATCH /users/{id}` ne peut apparaître par accident
et modifier `passwordHash` ou `active` sans passer par une règle métier
explicite. Quand un vrai besoin de gestion (liste des membres d'une équipe,
etc.) apparaîtra, on exposera des vues dédiées et contrôlées — jamais
l'entité brute.

---

## 2. Flux d'inscription

```
POST /api/register
{ "email", "plainPassword", "firstName", "lastName" }
```

1. `RegistrationController` désérialise le JSON en `RegisterUserRequest`
   (DTO dans `src/Dto/`, jamais l'entité directement).
2. Validation Symfony Validator sur le DTO : email non vide + format,
   mot de passe ≥ 8 caractères, prénom/nom non vides.
   → `422` avec le détail des violations si invalide.
3. `UserRegistrationService::register()` (logique métier, hors contrôleur) :
   - vérifie l'unicité de l'email via `UserRepository` → `409` sinon
     (l'index unique en base est un filet de sécurité, pas le mécanisme
     principal de détection du conflit) ;
   - hash le mot de passe avec `UserPasswordHasherInterface` (algorithme
     `auto`, laissé au choix de Symfony — actuellement bcrypt/argon selon
     l'environnement) ;
   - persiste l'utilisateur.
4. Réponse `201` avec l'utilisateur sérialisé (groupe `user:read`, donc
   sans `passwordHash`).

## 3. Flux de connexion et stratégie JWT

```
POST /api/login
{ "email", "password" }
→ 200 { "token": "<jwt>" }
```

- Le firewall `login` (`config/packages/security.yaml`) utilise
  l'authenticator natif `json_login` de Symfony Security : il n'y a **pas**
  de contrôleur métier pour `/api/login`. `SecurityController::__invoke()`
  existe uniquement comme filet de sécurité (il lève une exception s'il est
  jamais atteint) — c'est le pattern documenté officiellement par
  LexikJWTAuthenticationBundle.
- En cas de succès, `json_login` délègue à
  `lexik_jwt_authentication.handler.authentication_success`, qui génère le
  JWT et le renvoie sous la forme `{"token": "..."}`.
- Le token est un JWT **RS256** (clé privée/publique, pas de secret
  partagé) signé avec la paire générée par
  `bin/console lexik:jwt:generate-keypair` dans `config/jwt/` (fichiers
  gitignorés, à régénérer sur chaque environnement).
- Claims du token : `iat`, `exp`, `roles`, `username` (= l'email). Pas de
  claim custom (`sub`, `teamIds`, etc.) pour l'instant.
- **Durée de vie : 1h** (`token_ttl: 3600` dans
  `config/packages/lexik_jwt_authentication.yaml`). **Aucun refresh token**
  n'est implémenté : passé une heure, l'utilisateur doit se reconnecter.
  C'est une limitation assumée pour ce vertical slice — voir §7.
- Chaque requête vers `/api/*` (hors `/api/login`, `/api/register`,
  `/api/health`) passe par le firewall `api` (`jwt: ~`, `stateless: true`) :
  le token est lu depuis l'en-tête `Authorization: Bearer <token>`, vérifié
  (signature + expiration), et l'utilisateur est rechargé depuis la base à
  **chaque requête** (pas de session) via `app_user_provider` (email comme
  identifiant).

### Désactivation d'un compte (`App\Security\UserChecker`)

Enregistré comme `user_checker` sur les deux firewalls (`login` et `api`) :
- Sur `/api/login` : bloque l'authentification si `active = false`.
- Sur `/api/*` : re-vérifié à **chaque requête authentifiée**, pas
  seulement à la connexion — un compte désactivé pendant qu'un token est
  encore valide perd l'accès à la requête suivante.

**Comportement volontaire côté message d'erreur** : que le mot de passe
soit faux ou que le compte soit désactivé, la réponse est identique —
`401 {"code":401,"message":"Invalid credentials."}` — comportement par
défaut du handler d'échec de Lexik/Symfony Security, conservé tel quel.
Ça évite de révéler si un email correspond à un compte désactivé
(énumération de comptes). Contrepartie assumée : un utilisateur désactivé
ne sait pas *pourquoi* sa connexion échoue depuis ce seul message ; à
traiter via un canal séparé (email, contact admin) si besoin.

## 4. `GET /api/me`

Protégé par le firewall `api`. Utilise l'attribut Symfony
`#[CurrentUser] User $user` pour récupérer l'utilisateur authentifié sans
appeler manuellement le token storage. Réponse : l'utilisateur sérialisé
avec le groupe `user:read` (jamais `passwordHash`).

- Sans token / token invalide / expiré → `401`.
- Token valide → `200` avec `id, email, firstName, lastName, active,
  createdAt, updatedAt`.

## 5. Ce qui n'est jamais exposé

`passwordHash` n'a **aucun** attribut `#[Groups]` sur l'entité `User` : même
en cas d'erreur de configuration ailleurs (mauvais groupe passé à un futur
endpoint), le Serializer ne peut pas l'inclure tant que le groupe
`user:read` (ou tout autre) n'est pas explicitement ajouté sur cette
propriété — ce qui n'arrivera pas par accident.

## 6. Endpoints exacts

| Méthode | Route | Auth requise | Codes de retour |
|---|---|---|---|
| `POST` | `/api/register` | Non | `201`, `400` (JSON invalide), `422` (validation), `409` (email déjà utilisé) |
| `POST` | `/api/login` | Non | `200` (`{"token"}`), `401` (identifiants invalides ou compte désactivé) |
| `GET` | `/api/me` | Oui (Bearer JWT) | `200`, `401` (absent/invalide/expiré) |
| `GET` | `/api/health` | Non | `200`/`503` (inchangé depuis le socle) |

## 7. Décisions ouvertes / dette assumée

- **Stockage du token côté frontend : `localStorage`.** Choix pragmatique
  pour ce vertical slice (fonctionne immédiatement en cross-port sur
  `localhost`, pas de configuration CORS/cookies supplémentaire). **Plus
  vulnérable au XSS** qu'un cookie `httpOnly` + `SameSite`. Migrer vers un
  cookie `httpOnly` est l'amélioration de sécurité la plus importante à
  considérer avant une mise en production réelle — ça demanderait : le
  backend pose le cookie à la connexion, CORS avec `allow_credentials`,
  `fetch` avec `credentials: 'include'`, et probablement une protection
  CSRF puisque le navigateur enverrait le cookie automatiquement.
- **Pas de refresh token.** Session expire après 1h sans possibilité de
  renouvellement silencieux. À ajouter (`gesdinet/jwt-refresh-token-bundle`
  est l'option standard avec Lexik) si l'expérience utilisateur l'exige.
- **Pas de rate limiting** sur `/api/login` ni `/api/register` (pas de
  protection anti-bruteforce/anti-spam pour l'instant).
- **Vérification d'email** : le champ `emailVerifiedAt` existe mais rien ne
  le renseigne. Extension prévue : `EmailVerificationToken` (entité à part,
  à usage unique, avec expiration) + endpoint `POST
  /api/verify-email/{token}` + email envoyé via Symfony Mailer à
  l'inscription.
- **Mot de passe oublié** : aucune colonne dédiée sur `User` (délibéré,
  voir le README du socle) — l'extension naturelle est une entité
  `PasswordResetToken` séparée (token à usage unique, expirant), pas des
  colonnes supplémentaires sur `User`.
- **UUID vs id auto-increment** : gardé simple (int) faute de besoin
  identifié ; à revisiter si les identifiants utilisateurs doivent devenir
  non-devinables côté API publique.
- **`ROLE_USER` en dur** : suffisant tant qu'il n'y a pas de notion
  d'équipe. Le jour où `TeamMember` existe, `getRoles()` restera
  `['ROLE_USER']` (rôle global) et les rôles par équipe seront vérifiés via
  des Voters dédiés plutôt qu'ajoutés à `getRoles()`, pour ne pas mélanger
  "qui est l'utilisateur" et "que peut-il faire dans TELLE équipe".

## 8. Frontend

- `src/features/auth/context.ts` : le `React.Context` seul (fichier séparé
  pour que le Fast Refresh de Vite fonctionne correctement — un fichier qui
  exporte à la fois un composant et un contexte casse le HMR).
- `src/features/auth/AuthContext.tsx` : `AuthProvider`, charge
  `/api/me` au montage si un token est déjà stocké, expose
  `login/register/logout`.
- `src/features/auth/useAuth.ts` : hook de consommation.
- `src/features/auth/ProtectedRoute.tsx` : redirige vers `/login` (avec
  l'URL d'origine en `state`) si non authentifié ; affiche un état de
  chargement le temps de vérifier une session existante.
- `src/lib/apiClient.ts` : `apiFetch()` central — attache
  `Authorization: Bearer <token>`, sérialise/désérialise le JSON, et sur un
  `401` : vide le token stocké et émet un `window` event
  (`medvue:unauthorized`) écouté par `AuthProvider` pour vider l'état
  utilisateur (pas d'import circulaire entre le client HTTP et le contexte
  React).
- Toutes les pages métier (`/`, `/account`, `/my-availability`, `/teams`,
  …) sont enveloppées dans `<ProtectedRoute>` ; seules `/login` et
  `/register` sont publiques.
