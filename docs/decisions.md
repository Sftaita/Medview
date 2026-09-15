# Journal des décisions techniques

Historique chronologique des choix structurants du projet MedVue, avec leur
contexte et leurs compromis. Objectif : que personne (nous inclus, dans six
mois) n'ait à se demander *pourquoi* une chose a été faite ainsi.

**Portée** : ce fichier trace les décisions elles-mêmes (quoi, pourquoi,
quand, quelles alternatives écartées). Le détail d'implémentation d'une
fonctionnalité vit dans son propre document (`docs/authentication.md`,
puis `docs/teams.md`, `docs/planning-engine.md`, … au fil de l'avancement)
et ce journal y renvoie plutôt que de le dupliquer.

**Convention pour la suite** : chaque décision technique non triviale
(choix de dépendance, arbitrage d'architecture, compromis de sécurité ou de
performance, changement de cap) obtient une nouvelle entrée ici, ajoutée au
moment où elle est prise — pas reconstituée a posteriori. Une décision
remise en cause plus tard n'est pas supprimée : on ajoute une nouvelle
entrée qui **remplace** la précédente et on met à jour le statut de
l'ancienne (voir légende).

**Statuts** : 🟢 Adopté · 🟡 Adopté avec réserve documentée · 🔴 Remplacé
(la nouvelle entrée est indiquée) · ⚪ En discussion.

---

## Sommaire

| # | Date | Décision | Statut |
|---|---|---|---|
| [D001](#d001--environnement-de-dev-en-docker-compose-plutôt-que-wamp) | 2026-09-15 | Environnement de dev en Docker Compose plutôt que WAMP | 🟢 |
| [D002](#d002--symfony-7--php-83--api-platform) | 2026-09-15 | Symfony 7 + PHP 8.3 + API Platform | 🟢 |
| [D003](#d003--react--typescript--vite-avec-structure-par-feature) | 2026-09-15 | React + TypeScript + Vite, structure par feature | 🟢 |
| [D004](#d004--frankenphp-plutôt-que-nginx--php-fpm) | 2026-09-15 | FrankenPHP plutôt que Nginx + PHP-FPM | 🟢 |
| [D005](#d005--vendornode_modules-hors-du-bind-mount-windows) | 2026-09-15 | `vendor/`/`node_modules/` hors du bind mount Windows | 🟢 |
| [D006](#d006--ports-non-standards-8010--5183) | 2026-09-15 | Ports non standards (8010 / 5183) | 🟡 |
| [D007](#d007--app_env-jamais-en-variable-denvironnement-docker) | 2026-09-15 | `APP_ENV` jamais en variable d'environnement Docker | 🟢 |
| [D008](#d008--endpoint-de-santé-en-contrôleur-simple-pas-en-ressource-api-platform) | 2026-09-15 | Endpoint de santé en contrôleur simple, pas en ressource API Platform | 🟢 |
| [D009](#d009--user-jamais-exposé-en-crud-api-platform) | 2026-09-15 | `User` jamais exposé en CRUD API Platform | 🟢 |
| [D010](#d010--dto-dédié-pour-linscription-jamais-lentité-directement) | 2026-09-15 | DTO dédié pour l'inscription, jamais l'entité directement | 🟢 |
| [D011](#d011--table-users-et-non-user) | 2026-09-15 | Table `users` et non `user` | 🟢 |
| [D012](#d012--rôles-globaux-en-dur-rôles-déquipe-via-voters-plus-tard) | 2026-09-15 | Rôles globaux en dur, rôles d'équipe via Voters plus tard | 🟢 |
| [D013](#d013--champs-réservés-pour-vérification-demail-et-mot-de-passe-oublié) | 2026-09-15 | Champs réservés pour vérification d'email et mot de passe oublié | 🟡 |
| [D014](#d014--jwt-rs256-via-json_login--lexik-sans-refresh-token) | 2026-09-15 | JWT RS256 via `json_login` + Lexik, sans refresh token | 🟡 |
| [D015](#d015--message-derreur-générique-pour-mauvais-mot-de-passe-et-compte-désactivé) | 2026-09-15 | Message d'erreur générique pour mauvais mot de passe et compte désactivé | 🟢 |
| [D016](#d016--token-jwt-stocké-en-localstorage-plutôt-quen-cookie-httponly) | 2026-09-15 | Token JWT stocké en `localStorage` plutôt qu'en cookie `httpOnly` | 🟡 |
| [D017](#d017--damadoctrine-test-bundle-pour-lisolation-des-tests) | 2026-09-15 | `dama/doctrine-test-bundle` pour l'isolation des tests | 🟢 |
| [D018](#d018--composer-embarqué-dans-limage-docker-du-backend) | 2026-09-15 | Composer embarqué dans l'image Docker du backend | 🟢 |

---

## D001 — Environnement de dev en Docker Compose plutôt que WAMP

- **Contexte** : la machine de dev a déjà WAMP (PHP 8.2, Apache) installé
  pour d'autres projets. MedVue vise une base reproductible et proche de la
  production.
- **Décision** : tout l'environnement applicatif (PostgreSQL, backend,
  frontend) tourne dans Docker Compose, isolé de WAMP. WAMP n'est utilisé
  que ponctuellement côté hôte pour du scaffolding (Composer/PHP locaux
  quand `docker pull` est indisponible — voir D018).
- **Conséquences** : un seul point d'entrée (`docker compose up`), pas de
  conflit avec la config Apache/PHP existante. Contrepartie : dépendance à
  Docker Desktop et à sa stabilité réseau (voir D005, D018 pour les
  incidents rencontrés).

## D002 — Symfony 7 + PHP 8.3 + API Platform

- **Contexte** : besoin d'une API REST claire, versionnée, avec ORM mature
  pour un modèle de données qui va devenir complexe (équipes, plannings,
  équité multi-dimensionnelle).
- **Décision** : Symfony 7.4 (dernière LTS-track stable), PHP 8.3 en
  runtime Docker (le host a PHP 8.2, suffisant pour résoudre les
  dépendances Composer côté hôte). API Platform installé dès le socle mais
  **utilisé sélectivement** — voir D009.
- **Alternative écartée** : Laravel — écarté sans discussion approfondie,
  le cahier des charges imposait Symfony.

## D003 — React + TypeScript + Vite, structure par feature

- **Contexte** : cahier des charges impose React ; besoin d'un typage
  fort vu la complexité métier à venir (contraintes dures/souples, équité
  multi-dimensionnelle).
- **Décision** : Vite (build rapide, template officiel `react-ts`),
  react-router pour le routing, structure `src/features/<domaine>/` +
  `src/pages/` dès le départ (dossiers vides pour `teams/`,
  `availability/`, `planning/`, etc. créés au socle, remplis au fil des
  étapes).
- **Conséquences** : oxlint comme linter (fourni par le template Vite
  récent) plutôt qu'ESLint — pas de choix actif, gardé par défaut faute de
  raison de dévier.

## D004 — FrankenPHP plutôt que Nginx + PHP-FPM

- **Contexte** : besoin de servir l'API Symfony en conteneur pour le dev.
- **Décision** : image `dunglas/frankenphp` (un seul conteneur sert le PHP
  et le HTTP), recommandée officiellement par la documentation Symfony pour
  Docker.
- **Conséquences** : moins de configuration qu'une paire Nginx/PHP-FPM pour
  un résultat équivalent en dev. Pas encore évalué pour un déploiement de
  production (HTTPS, worker mode, tuning) — à revisiter le moment venu.

## D005 — `vendor/`/`node_modules/` hors du bind mount Windows

- **Contexte** : incident constaté au premier `docker compose up` — la
  première requête HTTP dépassait le `max_execution_time` de 30s de PHP.
  Cause : le bind mount Windows→conteneur est trop lent pour synchroniser
  des arbres de milliers de petits fichiers (`vendor/`), chaque accès
  fichier traversant la frontière Windows/Linux.
- **Décision** : `vendor/` (backend) et `node_modules/` (frontend) vivent
  dans des volumes Docker nommés (`backend_vendor`, `frontend_node_modules`),
  jamais sur le bind mount. Le code applicatif (`src/`, `config/`, etc.)
  reste bind-monté pour le rechargement à chaud.
- **Conséquences** : après un `composer require`/`npm install`, le volume
  nommé est mis à jour immédiatement dans le conteneur en cours
  d'exécution ; il faut reconstruire l'image (`docker compose build`) pour
  qu'un environnement reparti de zéro retrouve le même contenu sans
  réinstaller. Documenté dans le README.

## D006 — Ports non standards (8010 / 5183)

- **Contexte** : `docker compose up` a échoué au premier essai —
  `8000` et `5173` étaient déjà occupés par d'autres projets tournant sur
  la même machine (`medatwork`, `surgicalhub`).
- **Décision** : ports par défaut `BACKEND_PORT=8010`,
  `FRONTEND_PORT=5183` dans `.env.example`.
- **Statut** : 🟡 adopté par nécessité locale, pas par préférence
  d'équipe. À aligner si quelqu'un d'autre rejoint le projet et a ses
  propres conflits de ports, ou si l'équipe préfère revenir aux valeurs
  standards.

## D007 — `APP_ENV` jamais en variable d'environnement Docker

- **Contexte** : bug rencontré en ajoutant les tests d'authentification —
  `docker compose exec backend php vendor/bin/phpunit` échouait avec
  `"framework.test" config is not set to true` alors que la config était
  correcte. Cause : `docker-compose.yml` définissait `APP_ENV: dev` comme
  variable d'environnement réelle du conteneur, ce qui peuple `$_ENV`.
  `Symfony\Bundle\FrameworkBundle\Test\KernelTestCase` lit
  `$_ENV['APP_ENV']` **avant** `$_SERVER['APP_ENV']` — or c'est justement
  `$_SERVER['APP_ENV']` que PHPUnit force à `test` via
  `phpunit.dist.xml`. Résultat : le kernel de test bootait quand même en
  `dev`.
- **Décision** : ne jamais définir `APP_ENV` comme variable
  d'environnement réelle du conteneur backend. Symfony gère `APP_ENV` via
  ses propres fichiers `.env`/`.env.test`, et PHPUnit le force pour les
  tests — les real env vars ne doivent jamais entrer en concurrence avec
  ce mécanisme.
- **Conséquences** : toute variable d'environnement Docker candidate doit
  être vérifiée contre ce piège avant d'être ajoutée (pas seulement
  `APP_ENV` — tout ce que Symfony résout aussi via `.env` est concerné).

## D008 — Endpoint de santé en contrôleur simple, pas en ressource API Platform

- **Contexte** : besoin de valider la chaîne frontend → backend → DB dès
  le socle.
- **Décision** : `GET /api/health` est un contrôleur Symfony classique
  (`HealthController`), pas une `ApiResource`. Ce n'est pas une ressource
  métier, ça n'a pas vocation à apparaître dans la doc API Platform.
- **Conséquences** : cohérent avec D009 — API Platform n'est mobilisé que
  pour de vraies ressources CRUD métier, pas pour de l'infra.

## D009 — `User` jamais exposé en CRUD API Platform

- **Contexte** : risque qu'une ressource API Platform générée
  automatiquement sur `User` (ex. `PATCH /users/{id}`) permette de
  modifier `passwordHash` ou `active` sans passer par une règle métier.
- **Décision** : inscription, connexion et lecture du profil passent par
  trois contrôleurs Symfony dédiés (`RegistrationController`, firewall
  `login`, `AccountController`) — **aucune** ressource API Platform sur
  `User`.
- **Conséquences** : chaque nouvel usage (liste des membres d'une équipe,
  etc.) demandera une vue/contrôleur dédié explicite ; c'est le compromis
  accepté pour ne jamais exposer l'entité brute. Détail :
  `docs/authentication.md` §1.

## D010 — DTO dédié pour l'inscription, jamais l'entité directement

- **Décision** : `RegisterUserRequest` (dans `src/Dto/`) porte la
  validation d'entrée (email, mot de passe en clair, prénom, nom) ;
  l'entité `User` n'est construite qu'après validation, avec le mot de
  passe déjà hashé.
- **Conséquences** : sépare clairement "ce qu'un client peut envoyer" de
  "ce que l'entité représente en base" — l'entité n'a jamais de champ
  `plainPassword`.

## D011 — Table `users` et non `user`

- **Contexte** : `user` est un mot réservé en PostgreSQL.
- **Décision** : `#[ORM\Table(name: 'users')]` sur l'entité `User`.
- **Conséquences** : aucune, décision purement préventive.

## D012 — Rôles globaux en dur, rôles d'équipe via Voters plus tard

- **Contexte** : pas encore de notion d'équipe ; `UserInterface` exige une
  méthode `getRoles()`.
- **Décision** : `getRoles()` retourne `['ROLE_USER']` en dur (calculé,
  pas stocké en base). Quand `TeamMember` existera, les rôles
  `OWNER`/`ADMIN`/`MEMBER` seront vérifiés via des Voters Symfony dédiés à
  chaque équipe, **pas** ajoutés à `getRoles()`.
- **Justification** : `getRoles()` répond à "qui est l'utilisateur", pas à
  "que peut-il faire dans telle équipe précise" — mélanger les deux rendrait
  l'autorisation illisible dès qu'un utilisateur appartient à plusieurs
  équipes avec des rôles différents (cas nominal du cahier des charges).

## D013 — Champs réservés pour vérification d'email et mot de passe oublié

- **Contexte** : demande explicite de prévoir une structure compatible
  avec ces deux fonctionnalités sans les implémenter maintenant.
- **Décision** : `User::$emailVerifiedAt` (nullable) existe déjà, sans
  endpoint pour le renseigner. **Pas** de colonnes de reset de mot de passe
  sur `User` — l'extension prévue est une entité séparée
  `PasswordResetToken` (token à usage unique, expirant), pas des colonnes
  supplémentaires sur l'utilisateur.
- **Statut** : 🟡 hook minimal posé, fonctionnalités non construites.
  Détail : `docs/authentication.md` §7.

## D014 — JWT RS256 via `json_login` + Lexik, sans refresh token

- **Décision** : authentification par JWT signé RS256 (paire de clés
  générée par `lexik:jwt:generate-keypair`), émis via l'authenticator
  natif `json_login` de Symfony Security déléguant à
  LexikJWTAuthenticationBundle. TTL fixé à 1h. Pas de refresh token.
- **Conséquences** : implémentation simple, mais l'utilisateur doit se
  reconnecter après une heure d'inactivité — aucun renouvellement
  silencieux. Un contrôleur `SecurityController::login()` existe comme
  simple filet de sécurité (jamais exécuté en pratique, le firewall
  intercepte la requête avant) — pattern documenté officiellement par
  Lexik, pas une improvisation.
- **Statut** : 🟡 suffisant pour ce vertical slice ; refresh token
  (`gesdinet/jwt-refresh-token-bundle`) identifié comme extension standard
  si l'UX l'exige. Détail : `docs/authentication.md` §3.

## D015 — Message d'erreur générique pour mauvais mot de passe et compte désactivé

- **Contexte** : `UserChecker` lève une exception dédiée
  (`DisabledException`) quand un compte est désactivé.
- **Décision constatée** (comportement par défaut de Lexik/Symfony
  Security, gardé tel quel) : que l'échec vienne d'un mauvais mot de passe
  ou d'un compte désactivé, la réponse est identique —
  `401 {"message":"Invalid credentials."}`.
- **Justification** : évite l'énumération de comptes (savoir qu'un email
  correspond à un compte désactivé). Contrepartie assumée : l'utilisateur
  désactivé n'apprend pas *pourquoi* sa connexion échoue depuis ce seul
  message — à compenser par un canal séparé (email, contact admin) si
  besoin.

## D016 — Token JWT stocké en `localStorage` plutôt qu'en cookie `httpOnly`

- **Contexte** : frontend (`:5183`) et backend (`:8010`) sont deux
  origines différentes même en local ; un cookie cross-origin correctement
  sécurisé demanderait `SameSite=None` + `Secure` (donc HTTPS, y compris en
  dev), `credentials: 'include'` côté fetch, `allow_credentials` côté CORS,
  et une protection CSRF puisque le cookie partirait automatiquement à
  chaque requête.
- **Décision** : le token est stocké en `localStorage` côté frontend pour
  ce vertical slice — fonctionne immédiatement sans configuration
  supplémentaire.
- **Statut** : 🟡 compromis de sécurité **assumé et documenté**, pas un
  oubli. `localStorage` est plus exposé au XSS qu'un cookie `httpOnly`. À
  reconsidérer avant toute mise en production réelle — c'est l'amélioration
  de sécurité la plus importante identifiée à ce stade. Détail :
  `docs/authentication.md` §7.

## D017 — `dama/doctrine-test-bundle` pour l'isolation des tests

- **Contexte** : les tests fonctionnels d'authentification touchent une
  vraie base PostgreSQL (`app_test`) ; sans isolation, des emails fixes
  réutilisés d'une exécution à l'autre provoqueraient des conflits
  d'unicité.
- **Décision** : ajout de `dama/doctrine-test-bundle`, qui enveloppe
  chaque test dans une transaction annulée automatiquement à la fin.
  Activation manuelle (bundle en `config/bundles.php` + extension PHPUnit
  dans `phpunit.dist.xml`) car la recipe Symfony Flex est une recipe
  "contrib" non auto-appliquée (`allow-contrib: false`).
- **Conséquences** : suite de tests reproductible à l'infini sans jamais
  nettoyer la base manuellement — vérifié (`SELECT count(*) FROM users`
  revient à `0` après la suite). Décision prise tôt plutôt que d'attendre
  que la suite grossisse et que le problème devienne pénible à corriger a
  posteriori.

## D018 — Composer embarqué dans l'image Docker du backend

- **Contexte** : au socle, Composer avait été volontairement exclu de
  l'image (le conteneur embarquait `vendor/` déjà installé côté hôte) pour
  garder l'image minimale. À l'usage (étape authentification), ça obligeait
  à faire chaque `composer require` sur l'hôte — contraignant pour un
  développement qui vit surtout dans Docker.
- **Décision** : `COPY --from=composer:2 /usr/bin/composer
  /usr/bin/composer` dans le Dockerfile backend (copie multi-stage légère,
  pas de script d'installation). `composer require` fonctionne désormais
  directement dans le conteneur.
- **Incident lié** : après un `composer require dama/doctrine-test-bundle`
  exécuté *dans* le conteneur, l'image reconstruite ne contenait pas le
  paquet — parce que `vendor/` vit dans le volume nommé `backend_vendor`
  (D005), invisible du `COPY . .` au build. Correction : `composer
  install` relancé côté hôte pour resynchroniser `vendor/` avec
  `composer.lock` avant de reconstruire l'image. **Point de vigilance
  retenu** : après un `composer require` exécuté dans le conteneur, un
  `composer install` côté hôte (ou équivalent) est nécessaire avant de
  reconstruire l'image pour un environnement reparti de zéro.
