# Authentification — choix techniques

Ce document explique **comment** l'authentification est construite et
**pourquoi**, pour que les décisions restent traçables au fil des évolutions
du projet. Il complète le `README.md` racine (socle technique) et sera suivi
d'autres documents du même type (`docs/teams.md`, …) au fur et à mesure des
fonctionnalités.

Portée : inscription, connexion, `GET /api/me`, désactivation d'un compte,
**rate limiting**, **access token court + refresh token rotatif en cookie
HttpOnly**, logout. **Pas encore** : vérification d'email, mot de passe
oublié, rôles d'équipe.

---

## 1. Modèle de données

### Entité `User` (`backend/src/Entity/User.php`)

| Champ | Type | Notes |
|---|---|---|
| `id` | int, auto-increment | Pas d'UUID : pas de besoin identifié à ce stade (voir §9). |
| `email` | string(180), unique (insensible à la casse depuis le 2026-09-20, index `LOWER(email)` — §15) | Identifiant de connexion (`getUserIdentifier()`). Normalisé en minuscules à l'inscription. |
| `firstName` / `lastName` | string(100) | `NotBlank`. |
| `phoneE164` (`phone_e164`) | `?string(20)` | Numéro normalisé E.164, exposé sous la clé JSON `phone`. Nullable en base (comptes antérieurs) ; obligatoire à l'inscription (§15). **Aucune donnée d'établissement** : l'hôpital n'est pas une propriété du `User` (§15.10, D115). |
| `passwordHash` | string(255) | Jamais sérialisé (pas de `#[Groups]` dessus) — voir §7. |
| `active` | bool, défaut `true` | Désactivation = `active = false`, jamais de suppression. |
| `emailVerifiedAt` | `?DateTimeImmutable` | **Réservé** pour la vérification d'email future (voir §9) ; aucun endpoint ne le renseigne encore. |
| `createdAt` / `updatedAt` | `DateTimeImmutable` | `updatedAt` est mis à jour par chaque setter métier (`touch()`). |

Table nommée `users` (et non `user`, mot réservé en PostgreSQL).

**Rôles** : `getRoles()` retourne `['ROLE_USER']` en dur, calculé en code —
pas de colonne `roles` en base. Les rôles par équipe (OWNER/ADMIN/MEMBER)
viendront de `TeamMember` quand les équipes existeront ; ils ne remplacent
pas mais s'ajoutent à ce rôle de base.

### Entité `RefreshToken` (`backend/src/Entity/RefreshToken.php`)

Une ligne par refresh token **émis** (une ligne existe même une fois le
token consommé/révoqué — rien n'est supprimé, c'est l'historique de la
session). Migration : `Version20260915084715`.

| Champ | Type | Notes |
|---|---|---|
| `id` | int, auto-increment | |
| `user` | `ManyToOne User`, `ON DELETE CASCADE` | Si un `User` était un jour supprimé, ses tokens le seraient aussi — mais `User` n'est de toute façon jamais supprimé (désactivation seulement, voir §1 de `User`). |
| `tokenHash` | string(64), **unique**, indexé | SHA-256 hex du token brut. **Jamais le token en clair.** |
| `familyId` | string(64), indexé | Identifie une lignée de rotations = une session de connexion. Voir §4. |
| `createdAt` | `DateTimeImmutable` | |
| `expiresAt` | `DateTimeImmutable` | `createdAt + REFRESH_TOKEN_TTL`. |
| `revokedAt` | `?DateTimeImmutable`, nullable | Non-null = ce token ne peut plus servir, quelle qu'en soit la raison (logout, rotation, réutilisation détectée, compte désactivé). |
| `replacedByTokenId` | `?int`, nullable, auto-référence | Renseigné quand ce token a été consommé par une rotation ; sa présence (indépendamment de `revokedAt`) signale une **réutilisation** si le token brut est présenté à nouveau. |
| `createdByIp` / `userAgent` | nullable | Métadonnées de contexte, pas exploitées activement pour l'instant (pas de détection d'anomalie au-delà de la réutilisation) — utile pour un futur audit ou une UI "sessions actives". |

### Pourquoi pas d'entité `User` (ni `RefreshToken`) exposée en CRUD API Platform ?

Volontairement **aucune** ressource API Platform sur `User` ou
`RefreshToken`. Inscription, connexion, refresh, logout et lecture du
profil passent par des contrôleurs Symfony classiques. C'est le moyen le
plus direct de garantir qu'aucun endpoint générique ne peut apparaître par
accident et modifier `passwordHash`/`active`, ou lister/falsifier des
refresh tokens, sans passer par une règle métier explicite.

---

## 2. Flux d'inscription

```
POST /api/register
{ "email", "plainPassword", "firstName", "lastName", "phone", "invitationToken"? }
```

> **Mis à jour le 2026-09-20** (§15) : téléphone obligatoire (stocké en E.164), email
> normalisé en minuscules, `invitationToken` optionnel. **Aucun hôpital** : ce
> n'est pas une propriété de l'utilisateur (§15.10, D115). Les étapes ci-dessous
> décrivent le flux d'origine ; le détail des ajouts est en §15.

1. Rate limiting (§6) : `429` si l'IP a dépassé le quota.
2. `RegistrationController` désérialise le JSON en `RegisterUserRequest`
   (DTO dans `src/Dto/`, jamais l'entité directement).
3. Validation Symfony Validator sur le DTO : email non vide + format,
   mot de passe ≥ 8 caractères, prénom/nom non vides.
   → `422` avec le détail des violations si invalide.
4. `UserRegistrationService::register()` (logique métier, hors contrôleur) :
   - vérifie l'unicité de l'email via `UserRepository` → `409` sinon
     (l'index unique en base est un filet de sécurité, pas le mécanisme
     principal de détection du conflit) ;
   - hash le mot de passe avec `UserPasswordHasherInterface` (algorithme
     `auto`, laissé au choix de Symfony) ;
   - persiste l'utilisateur.
5. Réponse `201` avec l'utilisateur sérialisé (groupe `user:read`, donc
   sans `passwordHash`). **L'inscription ne connecte pas automatiquement**
   côté backend — le frontend enchaîne explicitement sur `/api/login`
   (voir §8).

## 3. Flux de connexion

```
POST /api/login
{ "email", "password" }
→ 200 { "token": "<jwt d'accès>" }
   Set-Cookie: medvue_refresh_token=<opaque>; HttpOnly; ...
```

- Le firewall `login` (`config/packages/security.yaml`) utilise
  l'authenticator natif `json_login` de Symfony Security : il n'y a **pas**
  de contrôleur métier pour la logique d'authentification elle-même.
  `SecurityController::__invoke()` existe uniquement comme filet de
  sécurité (il lève une exception s'il est jamais atteint) — pattern
  documenté officiellement par LexikJWTAuthenticationBundle.
- En cas de succès, `json_login` délègue à `App\Security\LoginSuccessHandler`
  (remplace le handler par défaut de Lexik) qui :
  1. crée le JWT d'accès (`JWTTokenManagerInterface::create()`) ;
  2. démarre une **nouvelle famille** de refresh token
     (`RefreshTokenService::issueNewFamily()`) ;
  3. pose le cookie refresh sur la réponse (`RefreshTokenCookieFactory`) ;
  4. renvoie `{"token": "<jwt>"}` — **le refresh token brut n'apparaît
     jamais dans le corps JSON**, uniquement dans le cookie `HttpOnly`.
- Rate limiting : `login_throttling` natif de Symfony (§6).
- Le firewall `api` protège `/api/*` (hors `/api/login`, `/api/register`,
  `/api/health`, `/api/token/refresh`, `/api/token/logout` — ces deux
  derniers ont leur propre mécanisme d'authentification, voir §5).

### Désactivation d'un compte (`App\Security\UserChecker`)

Enregistré comme `user_checker` sur les firewalls `login` et `api` :
- Sur `/api/login` : bloque l'authentification si `active = false`.
- Sur `/api/*` (JWT) : re-vérifié à **chaque requête authentifiée** — un
  compte désactivé pendant qu'un access token est encore valide perd
  l'accès à la requête suivante (l'access token étant court, ce délai est
  de toute façon borné à 15 minutes maximum).
- Sur `/api/token/refresh` : voir §5, le refresh revalide aussi l'état du
  compte, indépendamment du firewall JWT.

**Comportement volontaire côté message d'erreur** : que le mot de passe
soit faux ou que le compte soit désactivé, la réponse est identique —
`401 {"code":401,"message":"Invalid credentials."}` — comportement par
défaut du handler d'échec de Lexik/Symfony Security, conservé pour tout ce
qui n'est pas du rate limiting (voir `App\Security\LoginFailureHandler`,
§6). Ça évite de révéler si un email correspond à un compte désactivé
(énumération de comptes).

## 4. Access token + refresh token : architecture et durées de vie

| Token | Nature | Durée de vie | Où | Renouvelable |
|---|---|---|---|---|
| **Access token** | JWT RS256 (clé privée/publique, pas de secret partagé) | **15 min** (`JWT_TOKEN_TTL=900`) | Mémoire/`localStorage` frontend, envoyé en `Authorization: Bearer` | Via le refresh token |
| **Refresh token** | Chaîne opaque aléatoire (`bin2hex(random_bytes(32))`, 256 bits d'entropie) — **pas un JWT** | **30 jours glissants** (`REFRESH_TOKEN_TTL=2592000`), renouvelés à chaque rotation | Cookie `HttpOnly` `medvue_refresh_token`, jamais lu par JavaScript | Rotation à chaque usage (§4.1) |

Claims du JWT d'accès : `iat`, `exp`, `roles`, `username` (= l'email). Pas
de claim custom (`sub`, `teamIds`, etc.) pour l'instant.

**Pourquoi un refresh token opaque plutôt qu'un second JWT longue durée ?**
Un JWT est auto-porteur et stateless par construction — il ne peut donc
**jamais être révoqué avant son expiration** sans registre externe (ce qui
annule l'intérêt d'être stateless). Un refresh token JWT longue durée volé
resterait valide jusqu'à expiration, sans aucun moyen de le couper. Le
refresh token ici est au contraire **une clé opaque qui ne veut rien dire
par elle-même** : toute sa validité est vérifiée côté serveur contre la
table `refresh_tokens` à chaque utilisation, ce qui permet la révocation
immédiate (logout, réutilisation détectée, compte désactivé) — c'est le
point central de la demande de renforcement de sécurité de cette étape.

**Pourquoi SHA-256 pour le hash du refresh token, et pas le password
hasher (bcrypt/argon2) utilisé pour les mots de passe ?** Un mot de passe
est un secret à faible entropie choisi par un humain : un hash lent et
adaptatif (bcrypt/argon2) est nécessaire pour ralentir le brute-force
hors-ligne en cas de fuite de la base. Un refresh token est à l'opposé
**256 bits générés par un CSPRNG** — le brute-force est déjà
computationnellement impossible indépendamment de la vitesse du hash. Un
hash rapide et déterministe (SHA-256) est donc le bon outil ici : il
permet une recherche indexée en `O(log n)` sur `tokenHash`, ce qu'un hash
adaptatif interdirait (bcrypt génère un salt aléatoire par appel — deux
hashs du même mot de passe diffèrent, donc impossible à chercher par
égalité en base).

### 4.1 Rotation

```
POST /api/token/refresh   (cookie medvue_refresh_token requis)
→ 200 { "token": "<nouveau jwt>" }
   Set-Cookie: medvue_refresh_token=<nouveau opaque>; HttpOnly; ...
```

`RefreshTokenController` (public, voir §5) :

1. lit le cookie `medvue_refresh_token` ;
2. `RefreshTokenService::rotate()` :
   - hash le token présenté, cherche la ligne correspondante ;
   - **absent** → rejet générique ;
   - **déjà révoqué** (`revokedAt` non-null, qu'il ait été remplacé par
     rotation ou révoqué explicitement) → rejet **+ révocation défensive
     de toute la famille** (§4.2) ;
   - **expiré** → rejet ;
   - **compte du titulaire désactivé** → rejet + révocation de la famille
     (§5) ;
   - sinon : émet un nouveau token **dans la même famille**, marque
     l'ancien `revokedAt` + `replacedByTokenId` pointant vers le nouveau ;
3. émet un nouveau JWT d'accès et un nouveau cookie refresh.

**Chaque refresh valide invalide l'ancien token et en émet un nouveau** —
un raw token de refresh ne peut donc servir qu'une seule fois.

### 4.2 Détection de réutilisation et révocation de famille

Si un refresh token déjà consommé (par une rotation *ou* un logout) est
présenté à nouveau, c'est un signal de compromission : quelqu'un d'autre
que le porteur légitime du cookie courant a ce token en main (copie volée,
rejeu, cookie non supprimé côté client après logout, etc.). Dans ce cas,
`revokeFamily()` révoque **tous** les tokens de la `familyId` concernée —
y compris celui qui, une seconde plus tôt, était encore parfaitement
valide. Toute la lignée de session est coupée ; le prochain refresh, même
avec le token "actuel" légitime, échoue et force une reconnexion complète.

C'est un compromis assumé : en cas de faux positif (perte de connexion
réseau faisant qu'un client réessaie avec un token déjà consommé côté
serveur, par exemple), l'utilisateur légitime est aussi déconnecté. C'est
préférable à laisser un token potentiellement volé continuer à fonctionner.

**Implémentation notable** : `RefreshTokenRepository::revokeFamily()`
charge et mute les entités une par une plutôt qu'un `UPDATE` DQL en masse.
Un `UPDATE` en masse modifierait la base directement sans jamais rafraîchir
l'état d'un éventuel objet déjà chargé en mémoire (ex. le token qui vient
de déclencher cette révocation) — piège classique de Doctrine, découvert en
écrivant les tests (`docs/decisions.md` D020).

## 5. `POST /api/token/refresh` et `POST /api/token/logout` : routes publiques, authentification par cookie

Ces deux endpoints sont marqués `PUBLIC_ACCESS` dans `access_control` —
**volontairement en dehors** du firewall `api` (`jwt: ~`). Ils
n'authentifient jamais via `Authorization: Bearer` : leur identité vient
uniquement du cookie `HttpOnly`, vérifié manuellement dans le contrôleur
contre la table `refresh_tokens`. C'est un mécanisme d'authentification
différent et complémentaire à celui du reste de l'API, pas un trou dans la
protection JWT.

`POST /api/token/logout` :
- lit le cookie, révoque la famille entière si un token valide est trouvé ;
- **idempotent** : appelé sans cookie, ou avec un cookie déjà invalide,
  répond quand même `200 {"success": true}` — l'état désiré ("pas de
  session active") est de toute façon atteint ;
- efface le cookie côté navigateur (`Set-Cookie` avec expiration passée,
  même nom/path/attributs — sinon le navigateur garderait l'ancien).

## 6. Rate limiting

| Endpoint | Mécanisme | Limite | Clé |
|---|---|---|---|
| `POST /api/login` | `login_throttling` natif de Symfony Security (`security.yaml`) | 5 tentatives / 15 min (local), 25 / 15 min (global) | Local : hash(username + IP). Global : hash(IP) seule — empêche qu'une attaque distribuée sur beaucoup de comptes depuis une IP échappe à toute limite. |
| `POST /api/register` | `symfony/rate-limiter` direct (`RateLimiterFactory`, `config/packages/rate_limiter.yaml`) | 5 tentatives / heure | IP (`$request->getClientIp()`) |
| `GET /api/invitations/{token}`, `POST /api/invitations/{token}/accept` | `RateLimiterFactory` `invitation_lookup` | 30 / minute | IP |
| `POST /api/plannings/{p}/teams/{t}/invitations` | `RateLimiterFactory` `team_invitation` | 100 / heure | `stableId` de l'utilisateur authentifié |

Dépassement → `429 Too Many Requests` avec un en-tête `Retry-After`
(secondes) et un corps `{"error":"too_many_attempts","message":"..."}`.

- **Pourquoi le mécanisme natif pour login et pas pour register ?**
  `/api/login` n'a pas de contrôleur métier exécuté (§3) — le rate
  limiting doit donc s'accrocher au firewall lui-même, ce que
  `login_throttling` fait nativement, avec la double limite locale/globale
  déjà pensée pour ce cas précis (voir `DefaultLoginRateLimiter` dans
  Symfony). `/api/register` est un contrôleur normal : y injecter un
  `RateLimiterFactory` directement est plus simple qu'un mécanisme
  équivalent pour un seul endpoint sans authenticator.
- **`App\Security\LoginFailureHandler`** enveloppe le handler par défaut de
  Lexik : si l'exception est
  `Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException`,
  renvoie `429` + `Retry-After` ; sinon délègue au handler de Lexik
  (comportement générique `401` inchangé, §3).
- **Stockage** : pool de cache `cache.rate_limiter`, adossé à `cache.app`
  (filesystem par défaut, voir `config/packages/cache.yaml`). **Ce
  stockage est local à chaque instance.** En cas de déploiement de
  plusieurs instances du backend derrière un load balancer, chaque
  instance aurait son propre compteur — un attaquant distribué sur
  plusieurs requêtes pourrait multiplier son quota effectif par le nombre
  d'instances. À corriger avant un déploiement multi-instances en pointant
  `cache.rate_limiter` vers un backend partagé (Redis typiquement).
- **`Retry-After` exposé au frontend** : `config/packages/nelmio_cors.yaml`
  liste explicitement `Retry-After` dans `expose_headers`. Sans ça, le
  navigateur bloque la lecture JS du header même s'il est bien envoyé sur
  le fil — trouvé lors de l'UAT du 2026-09-15 (`docs/decisions.md` D027) :
  le frontend affichait un message générique au lieu du délai réel.

## 7. Ce qui n'est jamais exposé

`passwordHash` n'a **aucun** attribut `#[Groups]` sur l'entité `User`, et
`RefreshToken` n'est sérialisé nulle part : même en cas d'erreur de
configuration ailleurs, le Serializer ne peut pas les inclure par accident.
Le refresh token brut ne transite **jamais** en JSON, dans aucun sens
(requête ou réponse) — uniquement via le cookie `HttpOnly`, invisible à
JavaScript.

## 8. Frontend

- `src/features/auth/context.ts` : le `React.Context` seul (fichier séparé
  pour que le Fast Refresh de Vite fonctionne correctement).
- `src/features/auth/AuthContext.tsx` : `AuthProvider`. Au montage, tente
  **toujours** un refresh silencieux (`refreshAccessToken()`) plutôt que de
  se fier à un token en `localStorage` — c'est le cookie `HttpOnly`, pas le
  `localStorage`, qui est la vraie source de vérité d'une session existante
  (un nouvel onglet n'a pas d'access token en mémoire mais peut avoir un
  cookie valide). Expose `login/register/logout` (logout est maintenant
  asynchrone : il appelle `POST /api/token/logout` avant de vider l'état
  local, en best-effort — un échec réseau ne bloque jamais la déconnexion
  côté client).
- `src/features/auth/useAuth.ts` : hook de consommation.
- `src/features/auth/ProtectedRoute.tsx` : redirige vers `/login` (avec
  l'URL d'origine en `state`) si non authentifié ; affiche un état de
  chargement le temps de vérifier une session existante.
- `src/features/auth/PublicOnlyRoute.tsx` : l'inverse — enveloppe `/login`
  et `/register`, redirige vers `/` si un utilisateur déjà authentifié y
  accède. Ajouté suite à l'UAT du 2026-09-15 (§14) : ces deux pages
  restaient accessibles et soumettables même connecté.
- `src/lib/apiClient.ts` : `apiFetch()` central.
  - Attache `Authorization: Bearer <token>` sauf `skipAuth: true`.
  - `credentials: 'include'` sur **toutes** les requêtes (nécessaire pour
    login/refresh/logout ; sans effet pour les autres endpoints qui
    n'utilisent pas de cookie).
  - Sur un `401` (hors `skipAuth`) : tente **un seul** refresh
    (`refreshAccessToken()`), puis **rejoue une seule fois** la requête
    d'origine avec le nouveau token (`_isRetry` interne empêche toute
    boucle : un deuxième `401` après le rejeu n'est plus retenté).
  - Plusieurs `401` simultanés (plusieurs appels API en parallèle dont
    l'access token vient d'expirer) **partagent un seul refresh en vol** :
    `refreshAccessToken()` mémorise sa promesse en cours
    (`refreshPromise`) et la réutilise pour tout appelant concurrent,
    au lieu de déclencher un refresh par requête.
  - Si le refresh échoue (cookie absent/expiré/révoqué) : vide le token
    stocké et émet l'event `medvue:unauthorized`, écouté par
    `AuthProvider` pour déconnecter proprement l'utilisateur côté UI.
- **Synchronisation multi-onglets** (`AuthProvider`, §14) : écoute
  l'événement `storage` du navigateur (déclenché dans les *autres* onglets
  du même origin quand `localStorage` change, jamais dans l'onglet qui a
  fait le changement). Si la clé du token d'accès disparaît (logout dans
  un autre onglet), l'état `user` de cet onglet est vidé immédiatement,
  sans attendre un rechargement ou un prochain appel API.
- **Garde anti-double-soumission** (`LoginPage`/`RegisterPage`, §14) : un
  `useRef` vérifié et positionné de façon strictement synchrone en tête de
  `handleSubmit`, en plus de (et non à la place de) `disabled={isSubmitting}`
  — l'état React seul ne bloque pas deux soumissions déclenchées assez
  vite l'une après l'autre pour arriver avant le prochain rendu.
- Toutes les pages métier (`/`, `/account`, `/my-availability`, `/teams`,
  …) sont enveloppées dans `<ProtectedRoute>` ; `/login` et `/register`
  sont publiques mais enveloppées dans `<PublicOnlyRoute>`.

## 9. Cookies : attributs retenus et pourquoi

| Attribut | Valeur | Pourquoi |
|---|---|---|
| Nom | `medvue_refresh_token` | Namespacé, ne collisionne pas avec un cookie générique. |
| `HttpOnly` | toujours | Le seul moyen de garantir que JavaScript (donc une XSS) ne peut jamais lire le refresh token — c'est la raison d'être du cookie plutôt que du `localStorage` pour ce token précis. |
| `Secure` | piloté par `COOKIE_SECURE` (`false` en dev, **doit être `true`** dès que servi en HTTPS) | En dev, le frontend et le backend tournent en `http://localhost` : un cookie `Secure` serait silencieusement refusé par le navigateur. Pas d'auto-détection depuis `APP_ENV` : explicite et vérifiable en un coup d'œil par environnement plutôt qu'implicite. |
| `SameSite` | `Lax` | Voir §10 (analyse CSRF). |
| `Path` | `/api/token` | Regroupe volontairement `POST /api/token/refresh` et `POST /api/token/logout` sous ce préfixe **pour que le cookie ne parte jamais** vers `/api/me`, `/api/register`, etc. — surface d'exposition minimale. |
| `Domain` | non défini (cookie host-only) | Pas de topologie de sous-domaines de prod encore décidée. Un `Domain=.medvue.example` explicite deviendrait pertinent si frontend et backend prod partagent un domaine parent avec des sous-domaines distincts — à trancher à ce moment-là, pas avant (voir §12). |
| Durée de vie | = `REFRESH_TOKEN_TTL` (30 jours), alignée sur la ligne DB via `config/services.yaml` (`bind: $ttlSeconds`) | Le cookie et la ligne serveur doivent expirer ensemble ; les deux sont dérivés de la même variable d'environnement pour ne jamais diverger. |

## 10. CSRF : analyse et décision retenue

Le refresh token vit dans un cookie envoyé automatiquement par le
navigateur — toute requête `POST /api/token/refresh` ou
`POST /api/token/logout`, y compris déclenchée par un site tiers
malveillant, verrait ce cookie attaché **si** le navigateur la considère
comme same-site.

**Analyse de l'architecture actuelle** : frontend (`:5183`) et backend
(`:8010`) sont deux *origines* différentes mais le même **site** au sens
`SameSite` (le "site" se définit par domaine enregistrable + schéma, en
ignorant le port — `localhost` des deux côtés). Un cookie `SameSite=Lax`
ou `SameSite=Strict` est donc envoyé sur les requêtes entre frontend et
backend malgré le port différent — mais **ni l'un ni l'autre n'est envoyé
sur une requête initiée par un site tiers réellement cross-site**
(`evil.example` par exemple), qui est précisément le scénario CSRF à
bloquer.

**Décision : `SameSite=Lax`, pas de token CSRF séparé.**

- `Lax` et `Strict` se comportent **de façon identique** pour notre cas
  d'usage : les deux endpoints concernés sont exclusivement `POST`, jamais
  atteignables par une navigation top-level (ce que `Lax` autorise en plus
  de `Strict` : les requêtes `GET` de navigation top-level cross-site).
  `Lax` est choisi comme réglage par défaut le plus courant et le moins
  susceptible de surprendre un jour un flux légitime (ex. un lien de
  navigation), sans rien perdre en protection ici.
- Un site tiers ne peut donc **jamais** faire attacher ce cookie à une
  requête `POST` vers `/api/token/refresh` ou `/api/token/logout` — le
  vecteur CSRF classique (formulaire ou `fetch` cross-site) est neutralisé
  par `SameSite` seul, sans en-tête ni token CSRF supplémentaire à gérer.
- **Défense en profondeur additionnelle, non comptée dans la décision
  ci-dessus** : `RefreshTokenController`/`LogoutController` n'acceptent que
  `POST`, et CORS (`allow_credentials: true`, origine restreinte par regex,
  jamais `*`) empêcherait de toute façon un site non autorisé de *lire* la
  réponse même s'il parvenait à déclencher la requête.

**Point de bascule explicite à surveiller** : cette décision tient tant
que frontend et backend restent le même *site* au sens navigateur (même
domaine enregistrable, ports différents ou sous-domaines du même domaine).
**Si le déploiement évolue vers des domaines réellement distincts**
(ex. `app.exemple.com` et `api.exemple-different.com`, deux domaines
enregistrables séparés), le cookie devrait passer en `SameSite=None` +
`Secure`, ce qui **supprime la protection CSRF apportée par `SameSite`** —
il faudrait alors ajouter une vraie protection (jeton CSRF synchronisé ou
motif double-submit-cookie). Ne pas repousser cette réévaluation au moment
où la topologie change effectivement.

## 11. Variables d'environnement

| Variable | Où | Valeur dev (`.env`) | Rôle |
|---|---|---|---|
| `JWT_TOKEN_TTL` | `backend/.env` | `900` (15 min) | Durée de vie de l'access token JWT. |
| `REFRESH_TOKEN_TTL` | `backend/.env` | `2592000` (30 jours) | Durée de vie glissante du refresh token, côté ligne DB **et** cookie (même valeur, liée dans `config/services.yaml`). |
| `COOKIE_SECURE` | `backend/.env` | `false` | Attribut `Secure` du cookie refresh. **`true` obligatoire dès que le site est servi en HTTPS** — voir §9. |
| `CORS_ALLOW_ORIGIN` | `backend/.env` (déjà existante, socle) | regex `localhost`/`127.0.0.1` | Doit rester une regex explicite (jamais `*`) tant que `allow_credentials: true` est actif (`config/packages/nelmio_cors.yaml`) — les deux sont incompatibles côté spec CORS. |

Clés JWT (`JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`) et
`APP_SECRET` inchangés depuis le socle/l'étape précédente.

## 12. Endpoints exacts

| Méthode | Route | Auth requise | Codes de retour |
|---|---|---|---|
| `POST` | `/api/register` | Non | `201` (+ `joinedTeams`), `400` (JSON invalide), `422` (validation, email ≠ invitation), `409` (`email_already_used` / `account_exists_for_invitation`), `404`/`410` (invitation inutilisable), `429` (rate limit) — voir §15 |
| `POST` | `/api/login` | Non | `200` (`{"token"}` + cookie refresh), `401` (identifiants invalides ou compte désactivé), `429` (rate limit) |
| `GET` | `/api/me` | Oui (Bearer JWT) | `200`, `401` (absent/invalide/expiré) |
| `POST` | `/api/token/refresh` | Non (cookie refresh) | `200` (`{"token"}` + nouveau cookie refresh), `401` (absent/expiré/révoqué/réutilisé/compte désactivé — message générique) |
| `POST` | `/api/token/logout` | Non (cookie refresh, optionnel) | `200` (`{"success":true}`, toujours — idempotent), cookie effacé |
| `GET` | `/api/health` | Non | `200`/`503` (inchangé depuis le socle) |

## 13. Décisions ouvertes / dette assumée

- **`localStorage` pour l'access token.** Toujours vrai pour ce token
  court (15 min) — contrairement au refresh token, il n'est *pas* dans un
  cookie `HttpOnly`. Une fuite XSS reste possible pendant sa courte durée
  de vie ; le refresh token, lui, est protégé (§9). Un access token
  entièrement en mémoire (jamais persisté, perdu au rechargement de page)
  serait plus strict mais moins pratique — non retenu pour l'instant.
- **Pas de rate limiting distribué.** Voir §6 — nécessite un cache partagé
  (Redis) avant un déploiement multi-instances.
- **Pas de limite sur `/api/token/refresh` lui-même.** Un attaquant en
  possession d'un cookie refresh valide pourrait le "consommer" en boucle
  (chaque appel réussit et fait tourner la rotation) sans qu'aucun rate
  limiting ne s'y oppose. Peu exploitable en pratique (il faudrait déjà
  posséder le cookie, ce que `HttpOnly` + `SameSite` rend difficile), mais
  à noter comme point de durcissement possible.
- **Vérification d'email** : le champ `emailVerifiedAt` existe mais rien ne
  le renseigne. Extension prévue : `EmailVerificationToken` (entité à part,
  à usage unique, avec expiration) + endpoint `POST
  /api/verify-email/{token}` + email envoyé via Symfony Mailer à
  l'inscription.
- **Mot de passe oublié** : aucune colonne dédiée sur `User` (délibéré) —
  l'extension naturelle est une entité `PasswordResetToken` séparée (token
  à usage unique, expirant), pas des colonnes supplémentaires sur `User`.
- **UUID vs id auto-increment** : gardé simple (int) faute de besoin
  identifié ; à revisiter si les identifiants utilisateurs doivent devenir
  non-devinables côté API publique.
- **`ROLE_USER` en dur** : suffisant tant qu'il n'y a pas de notion
  d'équipe. Le jour où `TeamMember` existe, `getRoles()` restera
  `['ROLE_USER']` (rôle global) et les rôles par équipe seront vérifiés via
  des Voters dédiés plutôt qu'ajoutés à `getRoles()`.
- **Pas d'UI "sessions actives" / révocation manuelle par l'utilisateur.**
  Le modèle de données (`familyId`, `createdByIp`, `userAgent`) le
  permettrait (lister les familles actives d'un utilisateur, bouton
  "déconnecter cet appareil"), mais rien n'est exposé côté API/frontend
  pour l'instant.
- **Domaine de cookie non défini** — voir §9, à trancher avec la topologie
  de déploiement réelle.

## 14. UAT du 2026-09-15 : fiabilisation

Une UAT complète en conditions navigateur réelles (`docs/decisions.md`
D026-D030) a trouvé deux failles **BLOCKER** de divulgation
d'information — toutes deux corrigées et couvertes par des tests de
non-régression automatisés.

### 14.1 `App\EventListener\ApiExceptionListener` — filet de sécurité systémique

Avant cette UAT, aucun mécanisme ne garantissait qu'une exception non
prévue reste en JSON propre sur `/api/*` : par défaut, Symfony rend une
page HTML de debug complète (trace incluse) dès que `APP_ENV=dev`.
Découvert via deux chemins distincts :

- **Course entre deux inscriptions concurrentes avec le même email**
  (double-clic sur "Créer mon compte", ou deux requêtes simultanées) : les
  deux passaient la vérification préalable `findOneByEmail()` avant que
  l'une ou l'autre ne committe, et le second `flush()` levait une
  `Doctrine\DBAL\Exception\UniqueConstraintViolationException` non
  interceptée → `500` avec trace complète sur un endpoint public non
  authentifié.
- **`Content-Type` non-JSON sur `POST /api/login`** (header absent,
  `text/plain`, formulaire encodé…) : l'authenticator `json_login` décline
  alors la requête (il n'intercepte que `application/json`), qui retombe
  sur `SecurityController` — dont le corps supposait être "jamais atteint"
  et levait une `LogicException` non interceptée → même fuite, en une
  seule requête, sans concurrence nécessaire.

**Corrections** :

1. `UserRegistrationService::register()` capture désormais
   `UniqueConstraintViolationException` autour du `flush()` et la convertit
   en `EmailAlreadyUsedException` (même `409` propre qu'un doublon
   séquentiel).
2. `SecurityController::__invoke()` répond `400
   {"error":"invalid_content_type"}` au lieu de lever une exception.
3. `ApiExceptionListener` (nouveau, `kernel.exception`, priorité -10) :
   filet de sécurité pour **tout** le reste — toute exception non prévue
   sur `/api/*` est reformatée en JSON propre (`HttpExceptionInterface` →
   son vrai code + message déjà sûr ; sinon `500` générique, message fixe,
   **jamais** le message/la trace réels). L'exception complète reste
   loguée côté serveur (`docker logs`), seule la réponse HTTP est
   assainie. Corrige la classe de bug entière, pas seulement les deux cas
   trouvés — voir `docs/decisions.md` D026.

### 14.2 Autres correctifs issus de cette UAT

| Trouvaille | Sévérité | Correction |
|---|---|---|
| `/login` et `/register` accessibles et soumettables déjà connecté | MEDIUM | `PublicOnlyRoute` (§8) |
| Logout dans un onglet laissait les autres onglets visuellement connectés jusqu'au prochain appel | MEDIUM | Écoute de l'événement `storage` dans `AuthProvider` (§8) |
| `Retry-After` envoyé par le serveur mais illisible en JS (absent de `Access-Control-Expose-Headers`) | MEDIUM | Ajouté à `expose_headers` (§6) |
| Message générique affiché sur `429`, indistinguable d'une vraie erreur serveur | MEDIUM | `LoginPage`/`RegisterPage` affichent désormais un message dédié avec le délai (`ApiError.retryAfterSeconds`) |
| Double-clic/double-Enter déclenchait deux requêtes réseau réelles | HIGH | Garde `useRef` synchrone dans `handleSubmit` (§8) |

Tous ces correctifs sont couverts par des tests de non-régression
(`tests/Service/UserRegistrationServiceTest.php`,
`tests/EventListener/ApiExceptionListenerTest.php`,
`tests/Controller/AuthenticationTest.php` côté backend ;
`App.test.tsx`, `LoginPage.test.tsx` côté frontend). Détail complet du
diagnostic, des sévérités et des preuves : rapport d'UAT du 2026-09-15
(conservé dans l'historique de conversation du projet) et
`docs/decisions.md` D026-D030.

---

## 15. Inscription enrichie et invitations d'équipe (2026-09-20)

Décisions : `docs/decisions.md` D110 (hôpitaux), D111 (`User` ≠
`TeamInvitation`), D112 (téléphone), D113 (sécurité, multi-invitations,
compte créé entre-temps), D114 (emails). Côté équipes/planning :
`docs/planning.md` §14.

### 15.1 Deux flux, un seul endroit où un `User` naît

`UserRegistrationService::register()` est le **seul** créateur de `User`.

- **Flux A — classique** (`/register`, sans `invitationToken`) : prénom, nom,
  email, mot de passe, **téléphone** (obligatoire). Le comportement d'authentification est inchangé :
  le frontend enchaîne sur `/api/login`. Une inscription classique **ne
  consomme jamais** d'invitations en attente pour son email (emails non
  vérifiés : s'inscrire avec l'adresse de quelqu'un d'autre ne doit pas donner
  accès à ses équipes — seule la possession du lien reçu par email prouve
  l'accès à la boîte).
- **Flux B — invitation** (`/invitations/:token`) : le créateur du planning ou un
  OWNER/ADMIN de l'équipe fait « Ajouter une personne » (email, prénom, nom).
  - **B1** l'email correspond à un `User` → membership immédiat (mécanisme
    normal `PlanningTeamMembershipService::addMember`, rôle `MEMBER`, début =
    aujourd'hui), email « Vous avez été ajouté à une équipe ». Pas d'acceptation.
  - **B2** aucun `User` → `TeamInvitation` (jamais de `User` incomplet) + email
    « Vous êtes invité… » avec le lien personnel.

### 15.2 Modèle de données

| Table | Points clés |
|---|---|
| `users` (+) | `phone_e164` (CHECK `^\+[1-9][0-9]{6,14}$`). **Nullable en base** : les comptes existants (production comprise) n'ont pas de téléphone ; l'obligation est portée par `RegisterUserRequest`, jamais par un `NOT NULL` qui ferait échouer la migration. Nouvel index unique `LOWER(email)`. |
| `team_invitations` | `stable_id`, `planning_team_id`, `email` (CHECK minuscules/trim), `proposed_first_name`/`last_name`, `invited_by_id`, `role` (toujours `MEMBER` en v1), `token_hash` (CHECK SHA-256 hex, unique), `status` (CHECK `PENDING/ACCEPTED/EXPIRED/REVOKED`), `expires_at`, `accepted_at`/`accepted_by_id` (CHECK : renseignés **si et seulement si** `ACCEPTED`), `created_at`, `updated_at` (a un sens : change avec le statut). **Unique partiel `(planning_team_id, email) WHERE status='PENDING'`** : au plus une invitation vivante par équipe et adresse. |

Migration : `Version20260920111015` (réversible ; refuse de s'appliquer si deux
comptes existants ne diffèrent que par la casse de l'email).

### 15.3 Token d'invitation

- 32 octets de `random_bytes` (64 hex), **jamais stocké** : seule son empreinte
  SHA-256 l'est. Pas de hash lent/salé : un token de 256 bits n'est pas
  attaquable par force brute, et l'empreinte permet une recherche indexée.
- Il n'apparaît que dans le lien de l'email (`APP_FRONTEND_URL/invitations/<token>`),
  jamais dans une réponse API, un log ou un email d'un autre type.
- Durée de vie : `INVITATION_TTL_HOURS` (défaut 168 h = 7 jours). L'expiration est
  **vérifiée par le serveur à chaque usage** (`isUsableAt`) ; le statut
  `EXPIRED` n'est écrit que paresseusement (à la prochaine invitation pour la
  même équipe+adresse, ou à la consommation) — ne jamais se fier au statut
  persisté seul.
- Usage unique : `ACCEPTED` n'est pas rejouable ; `REVOKED`/expiré inutilisable.

### 15.4 Inscription par invitation — atomicité et concurrence

Dans **une seule transaction** (`wrapInTransaction`) : verrou de ligne
(`SELECT … FOR UPDATE`) sur l'invitation → vérification (utilisable, email
**exactement** celui de l'invitation, aucun `User` existant) → création du
`User` → memberships de **toutes** les invitations `PENDING` utilisables de cette
adresse (le token prouve l'accès à la boîte) → `ACCEPTED`. Toute erreur annule
l'ensemble : jamais « compte sans membership » ni « invitation acceptée sans
compte ». Un seul email « Bienvenue » liste les équipes rejointes.

| Situation | Résultat |
|---|---|
| Plusieurs invitations (équipes A, B, C) pour la même adresse | 1 `User`, 3 memberships, 3 × `ACCEPTED`, **1** email |
| Deux invitations de deux équipes **du même planning** | La première est consommée ; l'autre reste `PENDING` (une seule adhésion ouverte par planning, D080) |
| Deux soumissions simultanées du même lien | L'une `201`, l'autre `410 invitation_already_used` (vérifié en vrai parallèle, UAT) |
| Inscription classique concurrente sur la même adresse | Index unique ; la perdante reçoit `409` ; invitation intacte |
| Email différent de celui de l'invitation | `422` (`email`), invitation **non** consommée |
| **Compte créé entre l'envoi et l'acceptation** | `409 account_exists_for_invitation`, **aucun** second `User`. `GET /api/invitations/{token}` renvoie `accountExists: true` → l'UI demande de se connecter, puis `POST /api/invitations/{token}/accept` (JWT requis, email du compte = email de l'invitation, sinon `403`) crée le(s) membership(s). |
| Échec d'envoi d'email | Best-effort : la base est déjà validée, l'API répond `emailSent:false` (l'UI l'affiche), l'erreur est loguée sans token |

### 15.5 Endpoints

| Méthode | Route | Auth | Codes |
|---|---|---|---|
| `GET` | `/api/invitations/{token}` | Non (le token est le secret) | `200` (email, noms proposés, équipe, planning, invitant, `accountExists`), `404` `invitation_not_found`, `410` `invitation_expired`/`_revoked`/`_already_used`, `429` |
| `POST` | `/api/invitations/{token}/accept` | JWT | `200`, `403` email ≠ compte, `404`/`410`, `429` |
| `POST` | `/api/register` | Non | voir §12 ; `invitationToken` optionnel |
| `GET`/`POST` | `/api/plannings/{p}/teams/{t}/invitations` | JWT, créateur du planning ou OWNER/ADMIN de l'équipe | liste des invitations en attente / « Ajouter une personne » → `201` `USER_ADDED` ou `INVITATION_CREATED`, `200` `ALREADY_MEMBER` ou `INVITATION_ALREADY_PENDING`, `409` `membership_conflict`, `403`, `404`, `422`, `429` |
| `POST` | `…/invitations/{id}/revoke` | idem | `200`, `409` si non `PENDING`, `404` |

**Corps JSON** : `POST /api/register` et `POST …/invitations` rejettent tout champ
inconnu par un `422 validation_failed` (une violation par champ, rien n'est
créé) — ni `primaryHospitalStableId`, ni `role`, ni `active`/`roles` ne sont
ignorés en silence (D116).

Aucune ressource API Platform sur `User` ni `TeamInvitation`
(D009) : contrôleurs fins, DTO explicites, logique dans `TeamInvitationService`,
`UserRegistrationService`, `InvitationMailer`.

### 15.6 Sécurité — synthèse

- Email d'une invitation jamais choisi par le client ; token unique, haché,
  expirant, révocable ; verrous de ligne + index uniques + CHECK en base.
- Rate limiting : `register` (5/h/IP, inchangé),
  `invitation_lookup` (30/min/IP, protège l'oracle de token),
  `team_invitation` (100/h/utilisateur). Toutes les erreurs sont du JSON
  (`ApiExceptionListener`), jamais de trace.
- **Fuites d'existence de compte** : `POST /api/register` renvoie toujours
  `409` pour un email pris (comportement historique, protégé par le rate
  limit, **non modifié**). Le nouveau flux n'ajoute de fuite qu'à des
  personnes autorisées : un OWNER/ADMIN apprend « utilisateur existant ajouté »
  (exigence produit) et le détenteur d'un lien apprend `accountExists` pour
  **sa propre** adresse. Un tiers ne peut ni énumérer d'invitations ni
  d'équipes (`404` identique pour « inexistant » et « autre équipe »).
- Email et connexion insensibles à la casse (`UserRepository::findOneByEmail`,
  `loadUserByIdentifier`, index `LOWER(email)`).

### 15.7 Emails

Templates `backend/templates/email/` repris de
`docs/Design/emails_medvue` (`_base`/`_components` **inchangés**). Deux écarts,
volontaires : (1) les noms saisis par un tiers passent par `|e` avant
`ui.p()` — les maquettes le rendent tel quel (`|raw`), ce qui aurait permis
d'injecter du HTML dans la boîte d'un tiers ; (2) l'email « Bienvenue » accepte
une **liste** d'équipes. Une version texte `.txt.twig` accompagne chaque email.
Configuration : `MAILER_DSN`, `MAILER_FROM`, `SUPPORT_EMAIL`, `APP_FRONTEND_URL`,
`INVITATION_TTL_HOURS`, `DEFAULT_PHONE_REGION` — **toutes** requises dans le
`.env` de production (`.env.prod.example`, garde `ProdComposeTest`) : sans
`MAILER_DSN` réel, les emails seraient silencieusement perdus.

**Valeurs de démonstration de la maquette neutralisées** (elles atteindraient de
vrais emails) : les liens du pied de page (« Centre d'aide », « Nous contacter »)
viennent de `SUPPORT_EMAIL` (le lien d'aide est un `mailto:` tant qu'aucun centre
d'aide n'existe) au lieu des défauts `medvue.app` de la maquette, et le bloc
`postal` (adresse « Rue de la Loi 1 », inventée) est vidé dans les trois
templates. **À décider avant la mise en production** : une vraie adresse de
contact dans `SUPPORT_EMAIL` (le `.env.prod.example` impose `<CHANGE_ME…>`) et,
si une mention légale est voulue, l'adresse postale réelle dans le bloc `postal`.
Un test (`InvitationMailerTest`) garantit qu'aucune valeur de démonstration ni
syntaxe non rendue n'apparaît dans les quatre variantes d'email. En dev, Mailpit
(`docker-compose.yml`, UI `http://localhost:8026`) intercepte tout.

### 15.8 Jeu de données

Aucun : le lot n'embarque ni référentiel ni import (voir §15.10). L'inscription
ne dépend d'aucune table de référence.

### 15.9 Dette / hors périmètre

- Pas de vérification d'email (d'où la règle « classique ≠ consomme »).
- Envoi d'email synchrone dans la requête (pas de Messenger/file d'attente) ;
  pas de « renvoyer l'invitation » (révoquer puis réinviter).
- Statut `EXPIRED` jamais écrit par une tâche planifiée (paresseux).
- Pas d'email lors d'un `accept` par un compte déjà existant.
- Rôle d'invitation fixé à `MEMBER` (le champ existe en base).
- Le formulaire historique « ajout par identifiant » reste (seul moyen de
  donner `ADMIN`/`OWNER`, créateur uniquement).
- Téléphone : pas de vérification par SMS.

### 15.10 L'établissement n'est pas une donnée du profil (D115)

> L'établissement n'est pas une propriété durable de l'utilisateur. Les
> médecins/assistants pouvant changer de site ou d'hôpital dans le temps,
> l'affiliation institutionnelle sera modélisée ultérieurement dans un contexte
> temporel approprié, et non dans `User`.

Une première version de ce lot demandait l'hôpital à l'inscription
(`User.primaryHospital`, référentiel `Hospital`, `GET /api/hospitals`,
`app:hospitals:import`, D110). Tout cela a été **supprimé avant le premier
commit** (D115) : `User` ne porte que l'identité durable (nom, email,
téléphone, mot de passe), et un `POST /api/register` qui enverrait encore
`primaryHospitalStableId` est **rejeté** (`422 validation_failed`, violation
`primaryHospitalStableId: "This field is not accepted."`, rien n'est créé) plutôt
que d'être ignoré en silence (D116). Où portera
l'affiliation plus tard — `PlanningTeamMember` (déjà daté par
`membershipStart`/`membershipEnd`) ou une entité `Institution`/`Site`/`Affiliation` —
reste à décider quand le besoin sera confirmé ; rien n'est préconstruit.
