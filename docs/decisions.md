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
| [D014](#d014--jwt-rs256-via-json_login--lexik-sans-refresh-token) | 2026-09-15 | JWT RS256 via `json_login` + Lexik, sans refresh token | 🔴 → D019 |
| [D015](#d015--message-derreur-générique-pour-mauvais-mot-de-passe-et-compte-désactivé) | 2026-09-15 | Message d'erreur générique pour mauvais mot de passe et compte désactivé | 🟢 |
| [D016](#d016--token-jwt-stocké-en-localstorage-plutôt-quen-cookie-httponly) | 2026-09-15 | Token JWT stocké en `localStorage` plutôt qu'en cookie `httpOnly` | 🔴 → D019 (partiel — l'access token reste en `localStorage`) |
| [D017](#d017--damadoctrine-test-bundle-pour-lisolation-des-tests) | 2026-09-15 | `dama/doctrine-test-bundle` pour l'isolation des tests | 🟢 |
| [D018](#d018--composer-embarqué-dans-limage-docker-du-backend) | 2026-09-15 | Composer embarqué dans l'image Docker du backend | 🟢 |
| [D019](#d019--access-token-court--refresh-token-opaque-rotatif-en-cookie-httponly) | 2026-09-15 | Access token court + refresh token opaque rotatif en cookie `HttpOnly` | 🟢 |
| [D020](#d020--rate-limiting--login_throttling-natif--limiter-dédié-pour-register) | 2026-09-15 | Rate limiting : `login_throttling` natif + limiter dédié pour `/register` | 🟢 |
| [D021](#d021--samesitelax-suffisant-pour-refreshlogout-pas-de-token-csrf-séparé) | 2026-09-15 | `SameSite=Lax` suffisant pour refresh/logout, pas de token CSRF séparé | 🟡 |
| [D022](#d022--cookie-refresh-scopé-à-apitoken) | 2026-09-15 | Cookie refresh scopé à `/api/token` | 🟢 |
| [D023](#d023--révocation-de-famille-par-mutation-dentités-pas-update-dql-en-masse) | 2026-09-15 | Révocation de famille par mutation d'entités, pas `UPDATE` DQL en masse | 🟢 |
| [D024](#d024--tests-de-rate-limiting-isolés-par-ip-simulée--cache-nettoyé) | 2026-09-15 | Tests de rate limiting isolés par IP simulée + cache nettoyé | 🟢 |
| [D025](#d025--pas-de-syntaxe-php-83-malgré-le-runtime-docker-en-83) | 2026-09-15 | Pas de syntaxe PHP 8.3 malgré le runtime Docker en 8.3 | 🟢 |
| [D026](#d026--listener-dexception-global-sur-api-plutôt-que-corriger-au-cas-par-cas) | 2026-09-15 | Listener d'exception global sur `/api/*` plutôt que corriger au cas par cas | 🟢 |
| [D027](#d027--exposer-retry-after-explicitement-dans-le-cors) | 2026-09-15 | Exposer `Retry-After` explicitement dans le CORS | 🟢 |
| [D028](#d028--synchronisation-multi-onglets-par-lévénement-storage-plutôt-quun-broadcastchannel) | 2026-09-15 | Synchronisation multi-onglets par l'événement `storage` plutôt qu'un `BroadcastChannel` | 🟢 |
| [D029](#d029--publiconlyroute-pour-rediriger-loinlogin-et-register-si-déjà-connecté) | 2026-09-15 | `PublicOnlyRoute` pour rediriger loin de `/login`/`/register` si déjà connecté | 🟢 |
| [D030](#d030--garde-anti-double-soumission-par-ref-synchrone-plutôt-que-state-react-seul) | 2026-09-15 | Garde anti-double-soumission par `ref` synchrone plutôt que state React seul | 🟢 |
| [D031](#d031--optimisation-globale-par-contraintes-derrière-planningsolver-cp-sator-tools-en-première-implémentation) | 2026-09-15 | Optimisation globale par contraintes derrière `PlanningSolver`, CP-SAT/OR-Tools en première implémentation | 🟢 |
| [D032](#d032--optimisation-lexicographique-par-phases-pas-de-somme-pondérée) | 2026-09-15 | Optimisation lexicographique par phases, pas de somme pondérée | 🟢 |
| [D033](#d033--fairness-multidimensionnelle-avec-charge-forcée-exclue-du-calcul-discrétionnaire) | 2026-09-15 | Fairness multidimensionnelle avec charge forcée exclue du calcul discrétionnaire | 🟢 |
| [D034](#d034--exposition-structurelle-par-garde-et-requireddemand-fixé-avant-solve) | 2026-09-15 | Exposition structurelle par garde et `requiredDemand` fixé avant solve | 🟢 |
| [D035](#d035--participationfactor-historisé-dans-le-temps-jamais-recalculé-rétroactivement) | 2026-09-15 | `participationFactor` historisé dans le temps, jamais recalculé rétroactivement | 🟢 |
| [D036](#d036--taxonomie-hard--policy_hard--soft-avec-séparation-legal_min_rest--team_min_rest) | 2026-09-15 | Taxonomie HARD / POLICY_HARD / SOFT, avec séparation `LEGAL_MIN_REST` / `TEAM_MIN_REST` | 🟡 |
| [D037](#d037--strict-solve-puis-partial-diagnostic-solve--separation-solverstatus--coveragestatus) | 2026-09-15 | Strict solve puis partial diagnostic solve ; séparation `solverStatus` / `coverageStatus` | 🟢 |
| [D038](#d038--structurally_forced--globally_forced-comme-deux-notions-distinctes-de-charge-imposée) | 2026-09-15 | `STRUCTURALLY_FORCED` / `GLOBALLY_FORCED` comme deux notions distinctes de charge imposée | 🟢 |
| [D039](#d039--snapshot-hybride-immuable-avec-hash-canonique-versioning-des-ruleset-et-des-paramètres-solveur) | 2026-09-15 | Snapshot hybride immuable avec hash canonique, versioning des RuleSet et des paramètres solveur | 🟢 |
| [D040](#d040--dutyassignment-état-courant--dutyassignmentevent-append-only-distinction-assignedperformedreplacedcancelled) | 2026-09-15 | `DutyAssignment` (état courant) + `DutyAssignmentEvent` (append-only), distinction assigned/performed/replaced/cancelled | 🟢 |
| [D041](#d041--generate--repair--simulate-comme-un-seul-pipeline-paramétré-avec-ordres-lexicographiques-distincts) | 2026-09-15 | GENERATE / REPAIR / SIMULATE comme un seul pipeline paramétré avec ordres lexicographiques distincts | 🟢 |
| [D042](#d042--tie-break-déterministe-stable-indépendant-des-id-runtime-duty-à-identité-stable-entre-régénérations) | 2026-09-15 | Tie-break déterministe stable, indépendant des ID runtime, `Duty` à identité stable entre régénérations | 🟢 |
| [D043](#d043--reproductibilité-scopée-à-un-environnement-solveur-explicitement-versionné) | 2026-09-15 | Reproductibilité scopée à un environnement solveur explicitement versionné | 🟢 |
| [D044](#d044--diagnostic-unsat-multi-couches-relaxations-proposées-uniquement-sur-policy_hard) | 2026-09-15 | Diagnostic UNSAT multi-couches, relaxations proposées uniquement sur POLICY_HARD | 🟢 |
| [D045](#d045--explication-honnête-dune-décision-doptimisation-globale) | 2026-09-15 | Explication honnête d'une décision d'optimisation globale | 🟢 |
| [D046](#d046--uuidv7-comme-identifiant-stable-en-plus-dune-pk-auto-incrémentée-jamais-à-sa-place) | 2026-09-15 | UUIDv7 comme identifiant stable, en plus d'une PK auto-incrémentée, jamais à sa place | 🟢 |
| [D047](#d047--membership-comme-succession-de-stints-teammember-jamais-un-flag-mutable) | 2026-09-15 | Membership comme succession de stints `TeamMember`, jamais un flag mutable | 🟢 |
| [D048](#d048--participationfactor-historisé-immutable-et-non-chevauchant-en-base) | 2026-09-15 | `participationFactor` historisé, immutable et non chevauchant en base | 🟢 |
| [D049](#d049--fairnessperiod-non-chevauchante-par-équipe-au-niveau-base) | 2026-09-15 | `FairnessPeriod` non chevauchante par équipe, au niveau base | 🟢 |
| [D050](#d050--planningperiodstatus--machine-à-états-explicite-portée-par-lenum) | 2026-09-15 | `PlanningPeriodStatus` : machine à états explicite portée par l'enum | 🟢 |
| [D051](#d051--clés-étrangères-composites-idteamid-pour-la-cohérence-cross-table-déquipe) | 2026-09-15 | Clés étrangères composites `(id, team_id)` pour la cohérence cross-table d'équipe | 🟢 |
| [D052](#d052--dutypatterndutypatterncomponent-séparés-dutygroupinstance-comme-unité-atomique-matérialisée) | 2026-09-15 | `DutyPattern`/`DutyPatternComponent` séparés, `DutyGroupInstance` comme unité atomique matérialisée | 🟢 |
| [D053](#d053--dutytypeworkloadvalue-en-decimal-jamais-un-float-ni-un-entier-mis-à-léchelle-stocké) | 2026-09-15 | `DutyType.workloadValue` en `decimal`, jamais un float ni un entier mis à l'échelle stocké | 🟢 |
| [D054](#d054--planningruleset--colonnes-structurantes--json-validé-par-dto-versionné-et-immuable-hors-draft) | 2026-09-15 | `PlanningRuleSet` : colonnes structurantes + JSON validé par DTO, versionné et immuable hors DRAFT | 🟢 |
| [D055](#d055--services-de-domaine-sans-consommateur-temporairement-publics) | 2026-09-15 | Services de domaine sans consommateur, temporairement publics | 🟡 |
| [D056](#d056--teammember-gagne-un-stableid-en-revenant-sur-le-choix-yagni-initial) | 2026-09-16 | `TeamMember` gagne un `stableId`, en revenant sur le choix YAGNI initial | 🟢 |
| [D057](#d057--pas-de-voter-pour-apimecalendar--ownership-imposé-structurellement) | 2026-09-16 | Pas de Voter pour `/api/me/calendar` : ownership imposé structurellement | 🟢 |
| [D058](#d058--politique-de-chevauchement-et-contact-avec-bornes-exclude-inclusives) | 2026-09-16 | Politique de chevauchement "et contact" avec bornes `EXCLUDE` inclusives | 🟢 |
| [D059](#d059--get-apiteamsteamstableidmembers-minimal-pas-une-fonctionnalité-déquipe-complète) | 2026-09-16 | `GET /api/teams/{teamStableId}/members` minimal, pas une fonctionnalité d'équipe complète | 🟢 |
| [D060](#d060--planninggenerationstatus-réduit-à-draftsnapshotted) | 2026-09-16 | `PlanningGenerationStatus` réduit à `DRAFT`/`SNAPSHOTTED` | 🟢 |
| [D061](#d061--snapshot--identité-des-membres-par-valeur-de-stableid-jamais-par-fk-vivante) | 2026-09-16 | Snapshot : identité des membres par valeur de `stableId`, jamais par FK vivante | 🟢 |
| [D062](#d062--dutyassignment-porte-teammember-et-snapshotmember) | 2026-09-16 | `DutyAssignment` porte `teamMember` **et** `snapshotMember` | 🟢 |
| [D063](#d063--concurrence-du-snapshotassignment-par-statut--contrainte-unique-pas-par-hash) | 2026-09-16 | Concurrence du snapshot/assignment par statut + contrainte unique, pas par hash | 🟢 |
| [D064](#d064--duty-non-dupliquée-dans-le-snapshot-confirmé-par-lecture-du-code) | 2026-09-16 | `Duty` non dupliquée dans le snapshot, confirmé par lecture du code | 🟢 |
| [D065](#d065--pas-de-fk-composite-pour-les-invariants-teamplanningperiod-de-dutyassignment) | 2026-09-16 | Pas de FK composite pour les invariants Team/PlanningPeriod de `DutyAssignment` | 🟡 |

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
- **Statut** : 🔴 **Remplacé par [D019](#d019--access-token-court--refresh-token-opaque-rotatif-en-cookie-httponly)**.
  Le TTL de 1h et l'absence de refresh token ne tenaient que le temps du
  premier vertical slice ; conservé ici pour l'historique du raisonnement
  initial (pourquoi `json_login` + Lexik plutôt qu'autre chose reste
  valable et n'a pas changé).

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
- **Statut** : 🔴 **Remplacé partiellement par [D019](#d019--access-token-court--refresh-token-opaque-rotatif-en-cookie-httponly)** :
  le *refresh token* est désormais en cookie `HttpOnly`, ce qui couvre le
  risque le plus sensible (une session prolongée volée par XSS).
  L'*access token* (courte durée, 15 min) reste en `localStorage` — voir
  `docs/authentication.md` §13 pour ce qui reste un compromis assumé sur ce
  point précis.

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

## D019 — Access token court + refresh token opaque rotatif en cookie `HttpOnly`

- **Contexte** : demande explicite de renforcer l'authentification
  (D014/D016) au-delà d'un JWT unique 1h en `localStorage` : protection
  contre le vol de session longue durée, révocation possible avant
  expiration.
- **Décision** :
  - Access token : JWT RS256 inchangé dans sa mécanique, TTL réduit à
    **15 min** (`JWT_TOKEN_TTL`).
  - Refresh token : chaîne **opaque** aléatoire (`random_bytes(32)`, 256
    bits), **pas un JWT** — toute sa validité est vérifiée côté serveur
    (table `refresh_tokens`), ce qui le rend révocable à tout moment,
    contrairement à un JWT longue durée qui resterait valide jusqu'à
    expiration quoi qu'il arrive.
  - Hashé en **SHA-256** avant stockage (jamais en clair) — pas le
    password hasher (bcrypt/argon2) : le token est déjà 256 bits
    d'entropie générés par CSPRNG, pas un secret humain à faible entropie,
    donc un hash rapide et déterministe est le bon outil (permet en plus
    une recherche indexée par égalité, ce qu'un hash salé interdirait).
  - **Rotation** : chaque refresh valide invalide l'ancien token et en
    émet un nouveau dans la même `familyId`.
  - **Détection de réutilisation** : présenter un token déjà consommé
    (rotation ou logout) révoque toute la famille — traité comme un signal
    de compromission (voir D023 pour un piège Doctrine rencontré en
    implémentant ça).
  - Transporté en cookie `HttpOnly` (jamais en JSON, jamais lisible par
    JavaScript) — voir D021 (CSRF) et D022 (scope du cookie).
- **Conséquences** : remplace D014 (TTL 1h, pas de refresh) et une partie
  de D016 (stockage du token en `localStorage` — seul l'access token y
  reste désormais, le refresh token n'y est jamais présent). Détail complet
  du modèle de données et des flux : `docs/authentication.md` §1-§5.

## D020 — Rate limiting : `login_throttling` natif + limiter dédié pour `/register`

- **Contexte** : besoin de protéger `/api/login` (brute-force) et
  `/api/register` (abus d'inscription) côté backend.
- **Décision** : deux mécanismes différents, pas un seul générique :
  - `/api/login` n'a pas de contrôleur métier exécuté (`json_login`
    intercepte la requête avant, voir D014) — utilise donc l'option
    native `login_throttling` de Symfony Security, qui applique
    nativement une double limite (par username+IP **et** par IP seule,
    cette dernière avec un plafond plus haut) sans code applicatif à
    écrire.
  - `/api/register` est un contrôleur classique — un `RateLimiterFactory`
    (`symfony/rate-limiter`) y est injecté directement, plus simple qu'un
    mécanisme équivalent à `login_throttling` pour un seul endpoint sans
    authenticator.
- **Conséquences** : `App\Security\LoginFailureHandler` doit intercepter
  spécifiquement `TooManyLoginAttemptsAuthenticationException` pour
  renvoyer `429` (le handler par défaut de Lexik le réduirait sinon à un
  `401` générique comme n'importe quelle autre erreur d'authentification).
  Stockage du compteur : cache local par instance (`cache.rate_limiter` →
  `cache.app`, filesystem par défaut) — **pas partagé entre plusieurs
  instances du backend**, à corriger (Redis) avant un déploiement
  multi-instances. Détail : `docs/authentication.md` §6.

## D021 — `SameSite=Lax` suffisant pour refresh/logout, pas de token CSRF séparé

- **Contexte** : le refresh token étant dans un cookie envoyé
  automatiquement par le navigateur, `/api/token/refresh` et
  `/api/token/logout` sont potentiellement exposés au CSRF.
- **Analyse** : frontend (`:5183`) et backend (`:8010`) sont deux
  *origines* différentes mais le même **site** au sens `SameSite` (même
  domaine enregistrable `localhost`, port ignoré par cette classification).
  Un cookie `SameSite=Lax` (ou `Strict` — équivalents pour notre cas, les
  deux endpoints étant exclusivement `POST`) est donc envoyé entre
  frontend et backend malgré le port différent, mais **jamais** envoyé sur
  une requête `POST` initiée par un site réellement tiers — exactement le
  vecteur CSRF à bloquer.
- **Décision** : `SameSite=Lax`, pas de jeton CSRF synchronisé ni de
  double-submit-cookie ajouté par-dessus.
- **Statut** : 🟡 **conditionné à la topologie actuelle** (même site). Si
  frontend et backend finissent sur des domaines enregistrables
  réellement distincts en production, le cookie devrait passer en
  `SameSite=None` + `Secure`, ce qui **annule** cette protection — il
  faudrait alors ajouter un vrai mécanisme CSRF à ce moment-là, pas
  après coup. Détail : `docs/authentication.md` §10.

## D022 — Cookie refresh scopé à `/api/token`

- **Contexte** : un cookie envoyé à tous les endpoints `/api/*` élargit
  inutilement sa surface d'exposition (il n'a de sens que pour le
  refresh/logout).
- **Décision** : `POST /api/token/refresh` et `POST /api/token/logout`
  sont regroupés sous le préfixe `/api/token/`, et le cookie
  `medvue_refresh_token` a `Path=/api/token` — il ne part donc jamais vers
  `/api/me`, `/api/register`, etc.
- **Conséquences** : ces deux routes sont marquées `PUBLIC_ACCESS` dans
  `access_control`, en dehors du firewall JWT (`api`) — leur
  authentification est le cookie, vérifié manuellement dans le
  contrôleur, pas `Authorization: Bearer`. Ce n'est pas un trou de
  sécurité : c'est un mécanisme d'authentification différent et
  complémentaire pour ces deux endpoints précis. Détail :
  `docs/authentication.md` §5 et §9.

## D023 — Révocation de famille par mutation d'entités, pas `UPDATE` DQL en masse

- **Contexte** : bug rencontré en écrivant les tests de logout — un test
  vérifiant qu'un token est marqué révoqué après logout échouait
  (`isRevoked()` retournait `false`) alors que la ligne en base était bien
  mise à jour.
- **Cause** : `RefreshTokenRepository::revokeFamily()` utilisait un
  `UPDATE` DQL en masse (`->getQuery()->execute()`), qui modifie la base
  **directement**, sans jamais rafraîchir l'état d'un objet déjà hydraté
  en mémoire dans l'identity map de Doctrine — y compris l'objet qui,
  dans le même appel, venait de déclencher cette révocation
  (`RefreshTokenController` lit d'abord le token puis appelle
  `revokeFamily()` ; l'objet lu reste en mémoire avec `revokedAt = null`
  malgré la mise à jour SQL).
- **Décision** : `revokeFamily()` charge les entités concernées et appelle
  leur méthode `revoke()` une par une (le flush normal du Unit of Work
  s'en charge), plutôt qu'un `UPDATE` en masse. Les familles concernées
  restent petites (quelques rotations par session), donc pas de coût de
  performance significatif.
- **Conséquences** : évite cette classe de bug pour tout futur appelant,
  pas seulement pour les tests qui l'ont révélé — le piège existait aussi
  en production, juste sans jamais être observé puisque le code ne relit
  jamais l'entité juste après.

## D024 — Tests de rate limiting isolés par IP simulée + cache nettoyé

- **Contexte** : `symfony/rate-limiter` stocke ses compteurs dans le cache
  (`cache.rate_limiter`), **pas** dans la base de données — donc
  `dama/doctrine-test-bundle` (qui annule une transaction DB par test, voir
  D017) ne réinitialise rien entre deux tests. Avec une limite register de
  5/heure par IP et plus de 15 appels à `/api/register` cumulés dans la
  suite de tests d'authentification, les tests se seraient auto-bloqués
  les uns les autres dès la première exécution.
- **Décision** : les helpers de test d'inscription/connexion
  (`AuthenticationTestHelpers`) utilisent une IP simulée **aléatoire** par
  défaut à chaque appel (isolant chaque test des autres), sauf pour les
  tests dédiés au rate limiting lui-même qui passent une IP fixe explicite
  (pour pouvoir déclencher le `429` de façon déterministe) et nettoient
  explicitement `cache.rate_limiter` en début de test — nécessaire aussi
  pour rester stable d'une exécution de la suite à l'autre, pas seulement
  entre tests d'une même exécution.
- **Point annexe retenu** : injecter un cookie "ancien"/fabriqué dans le
  `CookieJar` de BrowserKit (`Symfony\Component\BrowserKit\CookieJar`)
  pour simuler un refresh token périmé exige de réutiliser le **même
  domaine** que le cookie réel posé par le serveur — le jar indexe par
  `[domaine][path][nom]`, et un domaine différent (y compris vide) crée
  une entrée concurrente plutôt que de remplacer la bonne, faisant
  silencieusement échouer l'injection.

## D025 — Pas de syntaxe PHP 8.3 malgré le runtime Docker en 8.3

- **Contexte** : `composer.json` déclare `"php": ">=8.2"` (D002), mais le
  conteneur backend exécute PHP 8.3 (D002 aussi). `RefreshTokenCookieFactory`
  utilisait des constantes de classe typées (`public const string
  COOKIE_NAME = ...`), une syntaxe qui n'existe qu'à partir de PHP 8.3.
  Aucune erreur en conteneur (8.3) — mais `composer install` côté hôte
  (PHP 8.2, utilisé pour resynchroniser `vendor/`, voir D018) a échoué avec
  une `ParseError` lors du warm-up du cache.
- **Décision** : retirer le typage des constantes (`const COOKIE_NAME =
  ...` sans `string`), en gardant le commentaire expliquant pourquoi. Plus
  largement : toute syntaxe PHP doit rester compatible **8.2**, même si le
  runtime Docker est en 8.3, tant que `composer.json` l'annonce et que des
  outils tournent aussi côté hôte (D018).
- **Conséquences** : le conteneur backend, qui tourne bien en PHP 8.3,
  n'aurait **jamais** détecté ce problème par lui-même — seul le contrôle
  croisé via l'hôte (PHP 8.2) l'a révélé. Utile de garder ce contrôle
  croisé en tête plutôt que de le voir comme une gêne : c'est lui qui
  attrape ce genre d'écart entre version déclarée et version réellement
  utilisée pendant le développement.

## D026 — Listener d'exception global sur `/api/*` plutôt que corriger au cas par cas

- **Contexte** : UAT navigateur complète du 2026-09-15 (avant de démarrer
  les équipes/planning). Deux façons **indépendantes** de déclencher une
  fuite de trace de debug Symfony (page HTML complète) sur des endpoints
  publics non authentifiés : (1) deux inscriptions concurrentes sur le
  même email (course entre le pré-check d'unicité et le `flush()`) ; (2)
  `Content-Type` non-JSON sur `/api/login`, qui fait décliner
  `json_login` et retombe sur le contrôleur sentinelle de
  `SecurityController`, conçu en supposant (à tort) ne jamais être atteint.
- **Décision** : corriger les deux causes précises (capture de
  `UniqueConstraintViolationException` dans `UserRegistrationService`,
  réponse `400` explicite dans `SecurityController`) **et**, en plus,
  ajouter `App\EventListener\ApiExceptionListener`
  (`kernel.exception`, priorité -10) qui reformate en JSON propre
  **toute** exception non interceptée sur `/api/*`, quel que soit son
  type — connu ou pas encore rencontré.
- **Justification** : corriger uniquement les deux cas trouvés aurait
  laissé la classe de bug entière ouverte pour la prochaine exception non
  anticipée (et il y en aura d'autres, notamment une fois les équipes/le
  planning en place). Le filet de sécurité déplace la question de
  "avons-nous pensé à tous les cas ?" vers "le pire cas possible reste-t-il
  sûr ?" — plus robuste face à l'inconnu. L'exception reste entièrement
  loguée côté serveur ; seule la réponse HTTP est assainie.
- **Conséquences** : tout endroit qui renvoyait déjà une réponse d'erreur
  explicite (nos propres `JsonResponse`) n'est pas concerné — le listener
  ne s'active que sur `kernel.exception`, donc uniquement pour ce qui
  n'était pas déjà géré. Détail : `docs/authentication.md` §14.

## D027 — Exposer `Retry-After` explicitement dans le CORS

- **Contexte** : le backend envoyait déjà l'en-tête `Retry-After` sur les
  réponses `429` (vérifié via `curl`), mais `response.headers.get('Retry-After')`
  renvoyait toujours `null` côté frontend. Cause : les navigateurs ne
  laissent le JavaScript lire que les en-têtes listés dans
  `Access-Control-Expose-Headers` sur une réponse cross-origin — peu
  importe que l'en-tête soit réellement présent sur le fil.
  `nelmio_cors.yaml` n'exposait que `Link`.
- **Décision** : ajouter `Retry-After` à `expose_headers`.
- **Conséquences** : `ApiError` (frontend) porte désormais
  `retryAfterSeconds`, ce qui a permis le message dédié de D030-adjacent
  (rate limiting) — sans ce changement CORS, aucune amélioration du
  message frontend n'aurait été possible quel que soit le code React
  écrit côté client.

## D028 — Synchronisation multi-onglets par l'événement `storage` plutôt qu'un `BroadcastChannel`

- **Contexte** : UAT multi-onglets — se déconnecter dans un onglet
  laissait les autres onglets ouverts de la même session affichés comme
  "connectés" jusqu'à leur prochain rechargement ou appel API (pas de
  mécanisme de synchronisation entre onglets).
- **Décision** : `AuthProvider` écoute l'événement navigateur `storage`
  (déclenché automatiquement dans tout onglet *autre* que celui qui a
  modifié `localStorage`) et vide son état `user` dès que la clé du token
  d'accès disparaît.
- **Alternative écartée** : `BroadcastChannel` API — plus explicite/
  flexible pour des messages structurés, mais `storage` suffit ici
  (l'information nécessaire, "y a-t-il encore un token ?", est déjà portée
  par `localStorage` lui-même) et ne demande aucune infrastructure de
  canal supplémentaire à créer/nettoyer.
- **Conséquences** : ne couvre que le cas déclenché par un changement de
  `localStorage` (logout, échec de refresh) — une désactivation de compte
  décidée côté serveur pendant qu'un onglet reste inactif ne sera
  détectée qu'à son prochain appel API, pas immédiatement (cohérent avec
  le reste de l'architecture, qui n'a pas de push serveur→client).

## D029 — `PublicOnlyRoute` pour rediriger loin de `/login`/`/register` si déjà connecté

- **Contexte** : UAT navigation — un utilisateur déjà authentifié pouvait
  ouvrir `/login` ou `/register` et y soumettre à nouveau le formulaire
  (pas d'erreur, juste une incohérence d'UX : pourquoi se reconnecter en
  étant déjà connecté ?).
- **Décision** : `PublicOnlyRoute`, miroir de `ProtectedRoute`, enveloppe
  ces deux routes et redirige vers `/` si `user` est déjà renseigné.
- **Statut** : 🟢 — pas un problème de sécurité (aucune donnée exposée
  différemment), une incohérence d'UX corrigée simplement.

## D030 — Garde anti-double-soumission par `ref` synchrone plutôt que state React seul

- **Contexte** : UAT double-clic — `disabled={isSubmitting}` (state React)
  n'empêchait pas deux soumissions déclenchées assez vite l'une après
  l'autre (double-clic rapide, `Enter` maintenu, ou deux
  `form.requestSubmit()` synchrones) : les deux atteignaient
  `handleSubmit` avant que le re-rendu désactivant le bouton n'ait eu lieu
  côté DOM. Conséquence concrète observée : deux connexions réussies (peu
  grave) et, sur l'inscription, une course exposant le bug de D026 avant
  sa correction.
- **Décision** : un `useRef<boolean>` vérifié et positionné de façon
  strictement synchrone en toute première ligne de `handleSubmit` (avant
  tout `await` ou mise à jour de state), dans `LoginPage` et
  `RegisterPage`. `disabled={isSubmitting}` est conservé pour le retour
  visuel (curseur, style), mais n'est plus le seul mécanisme de garde.
- **Justification** : une mise à jour de state React n'est pas garantie
  d'être reflétée dans le DOM avant qu'un second événement synchrone (issu
  du même tick) ne soit traité — un `ref` muté directement, lui, est visible
  immédiatement par tout code qui le lit ensuite dans le même tick.
- **Conséquences** : la correction de D026 (course d'inscription → 409
  propre au lieu de 500) reste nécessaire indépendamment de ce garde
  frontend — un client HTTP qui n'est pas le frontend React (script, autre
  app) peut toujours déclencher la même course, donc les deux corrections
  sont complémentaires, pas redondantes.

## D031 — Optimisation globale par contraintes derrière `PlanningSolver`, CP-SAT/OR-Tools en première implémentation

- **Contexte** : conception du moteur d'attribution des gardes, avant toute
  implémentation (`src/Service/` ne contient que `UserRegistrationService`
  et `RefreshTokenService`). Une première exploration du design
  (`docs/allocation-algorithm.md` v0.1) envisageait de démarrer par une
  heuristique gloutonne priorisée, avec bascule vers un solveur de
  contraintes "plus tard si besoin".
- **Décision** : le moteur est architecturé dès le départ en trois
  couches — modèle métier (domaine, ignore tout solveur) → `OptimizationProblem`
  (contrat abstrait) → interface `PlanningSolver` → `OrToolsPlanningSolver`
  (adapter CP-SAT). Le métier ne dépend jamais directement d'OR-Tools.
- **Alternative écartée** : démarrer par un algorithme glouton et basculer
  plus tard — écarté parce que le coût de bascule *a posteriori* est plus
  élevé que le coût de démarrer directement avec le solveur (un historique
  produit par un greedy imparfait devient difficile à faire cohabiter avec
  un nouvel algorithme, notamment pour l'explicabilité et la garantie
  d'absence d'optimum local que `CLAUDE.md` exige explicitement).
- **Conséquences** : dépendance opérationnelle à un solveur externe
  (subprocess, pas un microservice HTTP au démarrage) ; complexité de
  déploiement acceptée en échange de la complétude et de la robustesse
  UNSAT. Détail complet : `docs/allocation-algorithm.md` §21-22.

## D032 — Optimisation lexicographique par phases, pas de somme pondérée

- **Contexte** : le design initial mélangeait un ordre de priorité
  (lexicographique) et des poids configurables par équipe
  (`docs/allocation-algorithm.md` v0.1 §9-§10) sans trancher, ce qui aurait
  fini en pratique par une somme pondérée avec des constantes arbitraires
  (`weight = 1000`) — exactement ce que le projet veut éviter.
- **Décision** : le moteur résout une suite de phases strictement
  ordonnées (couverture, fairness pire-cas, fairness secondaire, historique
  fériés, espacement, préférences, tie-break), chaque phase figeant sa
  valeur optimale comme contrainte pour la suivante. Aucune pondération
  numérique arbitraire entre dimensions.
- **Conséquences** : les seules valeurs configurables par équipe sont des
  tolérances nommées, exprimées en unités métier réelles sur une dimension
  précise (ex. "+1 week-end de tolérance"), jamais un epsilon ou un poids
  abstrait. Détail : `docs/allocation-algorithm.md` §11-12.

## D033 — Fairness multidimensionnelle avec charge forcée exclue du calcul discrétionnaire

- **Contexte** : un candidat seul habilité (ou rendu nécessaire par les
  contraintes globales) pour certaines gardes ne doit pas être pénalisé en
  équité comme s'il avait librement "gagné" ces gardes, sans pour autant
  neutraliser artificiellement une part trop large du planning et détruire
  le signal d'équité réel.
- **Décision** : au moins onze dimensions suivies indépendamment (jamais
  fusionnées en un score global unique, principe déjà posé dans
  `CLAUDE.md`), avec une charge forcée calculée à deux niveaux temporels —
  `structurallyForcedLoad` (connue avant résolution, exclue de l'objectif
  du solve en cours) et `globallyForcedLoad` (connue après résolution,
  exclue seulement du registre historique alimentant les cibles des
  périodes futures). Voir D038 pour le détail de cette distinction.
- **Conséquences** : la fairness d'un solve en cours reste toujours
  calculable sans attendre une analyse globale coûteuse ; le registre
  historique reste honnête sur ce qui relevait réellement d'un choix.
  Détail : `docs/allocation-algorithm.md` §4.4, §6.

## D034 — Exposition structurelle par garde et `requiredDemand` fixé avant solve

- **Contexte** : une première formule d'exposition proportionnait les
  cibles d'équité au temps de présence global (ex. "présent 8 mois sur
  12 → exposure = 8/12"), ce qui ignore la structure réelle de qui pouvait
  concrètement prétendre à quelle garde. Par ailleurs, ancrer les cibles
  sur le nombre de gardes *effectivement* attribuées les ferait baisser
  artificiellement dès qu'un solve partiel laisse des gardes non pourvues,
  donnant une fausse impression d'amélioration de l'équité.
- **Décision** : l'exposition (`structuralOpportunity` × `participationFactor`,
  ce dernier évalué à la date de chaque garde) est calculée garde par garde,
  jamais par un ratio de présence global. Les cibles d'équité sont ancrées
  sur `requiredDemand` — la demande métier théorique fixée à la
  construction du snapshot — jamais recalculée après un solve partiel.
- **Conséquences** : `participationFactor` n'intervient qu'une seule fois
  dans le calcul (jamais de double comptage) ; une dimension à faible
  échantillon (ex. un seul Noël dans la période) est suivie mais rétrogradée
  en critère non déterminant plutôt que de produire une cible instable.
  Détail avec formules exactes : `docs/allocation-algorithm.md` §5.

## D035 — `participationFactor` historisé dans le temps, jamais recalculé rétroactivement

- **Contexte** : un changement de facteur de participation en cours de
  période (ex. `1.0 → 0.5` pour un passage à temps partiel) ne doit jamais
  réinterpréter rétroactivement l'exposition théorique passée avec la
  nouvelle valeur — sinon l'équité passée devient silencieusement faussée.
- **Décision** : `participationFactor` est porté par une timeline de
  segments (`{value, effectiveFrom, effectiveTo, changeReason}`),
  append-only — un changement ajoute un segment, n'en modifie jamais un
  passé. Toute lecture d'exposition à une date donnée utilise le segment en
  vigueur à cette date précise.
- **Conséquences** : ce même mécanisme sert aussi à modéliser les absences
  administratives longues (congé, suspension) sans créer une seconde voie
  de calcul — voir la taxonomie des absences,
  `docs/allocation-algorithm.md` §20.

## D036 — Taxonomie HARD / POLICY_HARD / SOFT, avec séparation `LEGAL_MIN_REST` / `TEAM_MIN_REST`

- **Contexte** : une contrainte de repos minimum peut recouvrir deux
  réalités différentes — un plancher réglementaire non négociable, et une
  règle interne d'équipe plus protectrice mais configurable. Un seul code
  d'exclusion dont le tier (HARD/POLICY_HARD) dépendrait du paramétrage
  créerait une ambiguïté dangereuse (une contrainte présentée comme
  "jamais négociable" pourrait silencieusement devenir relaxable selon la
  configuration de l'équipe).
- **Décision** : trois tiers de contraintes (HARD jamais violée/jamais
  relaxable en diagnostic ; POLICY_HARD bloquante mais relaxable en
  diagnostic UNSAT à destination de l'admin ; SOFT optimisée). `MIN_REST`
  est scindé en deux codes à tier fixe : `LEGAL_MIN_REST` (HARD) et
  `TEAM_MIN_REST` (POLICY_HARD, jamais inférieure au minimum légal).
- **Statut** : 🟡 la séparation structurelle est actée, mais la valeur
  exacte du seuil légal applicable aux équipes médicales ciblées reste à
  confirmer par un référent métier avant implémentation — non déduite dans
  cette spécification. `MAX_CONSECUTIVE_NIGHTS` pourrait nécessiter le même
  traitement si un plafond légal existe dans certaines juridictions, à
  vérifier au moment de l'implémentation.
- **Conséquences** : détail de la taxonomie complète (codes d'exclusion,
  tiers fixes) : `docs/allocation-algorithm.md` §3.

## D037 — Strict solve puis partial diagnostic solve ; séparation `solverStatus` / `coverageStatus`

- **Contexte** : ni "le planning entier échoue parce qu'une seule garde est
  impossible" ni "une garde non pourvue devient une simple variable soft
  parmi d'autres" n'est acceptable. Le premier cas empêche de produire un
  planning utile dès qu'un cas limite existe quelque part ; le second
  risque de masquer un déficit de couverture réel derrière un score
  d'optimisation qui semble bon.
- **Décision** : le moteur résout d'abord un problème STRICT (couverture
  stricte, `Σ x[d][c] = 1` pour chaque garde obligatoire). S'il est
  infaisable, un second problème PARTIAL est résolu, autorisant
  explicitement des gardes `unassigned`, avec pour priorité absolue de
  minimiser d'abord le nombre de gardes critiques non pourvues, puis le
  nombre total, avant d'appliquer les phases de fairness normales.
  `solverStatus` (résultat technique du solve) et `coverageStatus`
  (`COMPLETE`/`INCOMPLETE`, réalité métier) sont deux notions séparées :
  un solveur peut prouver l'optimalité (`OPTIMAL`) d'un planning qui reste
  métier-incomplet (`INCOMPLETE`).
- **Conséquences** : `UNASSIGNED` n'existe jamais dans le modèle
  d'optimisation de base — c'est une transformation contrôlée du problème
  strict, jamais une notion métier de premier ordre. Détail :
  `docs/allocation-algorithm.md` §10.

## D038 — `STRUCTURALLY_FORCED` / `GLOBALLY_FORCED` comme deux notions distinctes de charge imposée

- **Contexte** : une première définition de "garde forcée" limitée au cas
  "un seul candidat éligible" ignore les contraintes globales du problème
  réel (`MAX_DUTIES`, `MIN_REST`, `DutyPattern`, nuits consécutives) qui
  peuvent rendre une affectation nécessaire sans qu'aucun candidat ne soit
  localement unique. Une approximation par décomposition de couplage
  biparti (type Dulmage-Mendelsohn) ne suffit pas à capturer ces
  contraintes globales.
- **Décision** : deux notions distinctes, toutes deux indépendantes de
  l'ordre de résolution du solveur — `STRUCTURALLY_FORCED` (candidat
  HARD-éligible unique, calcul local et systématique avant résolution) et
  `GLOBALLY_FORCED` (`Feasible(P) ∧ ¬Feasible(P ∧ x[d,u]=0)`, calculé via
  le solveur de faisabilité, jamais exhaustivement avant résolution pour
  des raisons de coût — pré-filtré, mis en cache, disponible à la demande
  pour l'explication même si non calculé pour toutes les affectations).
- **Conséquences** : la charge structurellement forcée exclut la cible du
  solve en cours ; la charge globalement forcée corrige seulement le
  registre historique utilisé pour les périodes futures (voir D033).
  Détail avec stratégie de coût complète : `docs/allocation-algorithm.md`
  §4.

## D039 — Snapshot hybride immuable avec hash canonique, versioning des RuleSet et des paramètres solveur

- **Contexte** : besoin de détecter qu'une génération a été calculée sur
  des données devenues obsolètes avant de persister (concurrence), et de
  garantir qu'un changement de règles ou de paramètres solveur après une
  génération passée n'en modifie jamais silencieusement l'explication.
- **Décision** : snapshot hybride (références vers les objets structurants
  + copie immuable des données qui influencent réellement le calcul),
  haché selon un format canonique explicite (clés triées, précision
  numérique fixe, timestamps techniques exclus du hash). `PlanningRuleSet`
  et `SolverParameterSet` sont tous deux versionnés en append-only, jamais
  modifiés en place.
- **Conséquences** : la détection de concurrence compare le hash au moment
  du persist à celui du snapshot utilisé pour le solve — tout écart rejette
  la persistance avec un rapport de conflit structuré, jamais un
  écrasement silencieux. Détail : `docs/allocation-algorithm.md` §14-15.

## D040 — `DutyAssignment` (état courant) + `DutyAssignmentEvent` (append-only), distinction assigned/performed/replaced/cancelled

- **Contexte** : un pur event-sourcing rendrait l'état courant coûteux à
  interroger ; une simple ligne mutable sans historique violerait le
  principe déjà posé dans `CLAUDE.md` selon lequel l'historique des
  affectations n'est jamais recalculé depuis l'état courant.
- **Décision** : `DutyAssignment` porte l'état courant directement
  interrogeable ; `DutyAssignmentEvent` porte l'historique append-only.
  Toute mutation de `DutyAssignment` est obligatoirement accompagnée, dans
  la même transaction, d'un nouvel événement — jamais d'`UPDATE` silencieux
  (cohérent avec D023). Les statuts `PLANNED/PERFORMED/CANCELLED/REPLACED`
  distinguent l'affectation prévue de la réalité effectivement survenue.
- **Conséquences** : la fairness historique (dates passées) utilise l'issue
  réelle, jamais l'assignation initiale une fois remplacée ; l'assignation
  initiale reste pleinement auditable même après remplacement. Détail :
  `docs/allocation-algorithm.md` §19.

## D041 — GENERATE / REPAIR / SIMULATE comme un seul pipeline paramétré avec ordres lexicographiques distincts

- **Contexte** : réparer un planning déjà publié n'a pas le même objectif
  que le générer pour la première fois — ré-optimiser l'équité depuis zéro
  à chaque réparation contredirait l'exigence de stabilité d'un planning
  publié (`CLAUDE.md`).
- **Décision** : un seul pipeline interne, paramétré par mode et par
  `persist`, mais avec des ordres de phases différents. En `GENERATE`,
  l'équité est optimisée activement. En `REPAIR`, l'équité devient une
  contrainte de non-régression (borne sur la dégradation acceptée) tandis
  que la minimisation du nombre de changements et du coût de changement
  pondéré (`changeCost`, distinct du simple compte) passe en priorité
  quasi maximale. `SIMULATE` réutilise l'ordre du mode simulé, sans
  persistance ni notification.
- **Conséquences** : détail des deux ordres complets et de la formule de
  `changeCost` : `docs/allocation-algorithm.md` §11.

## D042 — Tie-break déterministe stable, indépendant des ID runtime, `Duty` à identité stable entre régénérations

- **Contexte** : un tie-break basé sur un ID auto-incrémenté ou un ordre
  d'itération non stable favoriserait silencieusement toujours le même
  utilisateur ; `planningGenerationId` généré à chaque exécution ne peut
  pas non plus servir de matière de seed sans casser la reproductibilité
  d'une exécution à l'autre.
- **Décision** : `tieBreakKey = StableHash(seedMaterial + dutyStableKey +
  candidateStableKey)`, où `seedMaterial` est composé exclusivement
  d'identifiants stables (équipe, période, version des règles, hash du
  snapshot, version de l'algorithme, version des paramètres solveur, seed
  explicite). Utiliser un ID comme matière première de hash reste sûr (la
  propriété d'avalanche du hash ne corrèle pas avec l'ordre des entrées) —
  c'est trier/comparer directement par ID qui reste interdit.
- **Conséquences** : condition dure associée — `Duty` doit conserver une
  identité stable à travers les régénérations d'une même `PlanningPeriod`
  (créée une fois, jamais recréée), sinon `dutyStableKey` change d'une
  génération à l'autre et casse la reproductibilité du tie-break. Un
  compteur de "victoires de tie-break" a été envisagé puis écarté en v1,
  faute de preuve empirique de biais structurel. Détail :
  `docs/allocation-algorithm.md` §13.

## D043 — Reproductibilité scopée à un environnement solveur explicitement versionné

- **Contexte** : promettre une reproductibilité bit-à-bit universelle entre
  toutes les machines et toutes les versions d'OR-Tools serait une garantie
  intenable en pratique (float, threading, versions de bibliothèques).
- **Décision** : MedVue garantit la reproductibilité dans un environnement
  de solveur explicitement versionné et configuré de manière déterministe
  — l'identité reproductible d'une génération est le tuple `snapshotHash +
  rulesVersion + algorithmVersion + solverType + solverVersion +
  solverParameterSetVersion + seed + mode`. Deux générations partageant ce
  tuple exact sont attendues identiques ; rien n'est promis au-delà.
- **Conséquences** : `SolverParameterSet` (workers, deterministic mode,
  search strategy, timeout, preprocessing) est versionné séparément et
  référencé par version, jamais dupliqué en clair. Détail :
  `docs/allocation-algorithm.md` §14.

## D044 — Diagnostic UNSAT multi-couches, relaxations proposées uniquement sur POLICY_HARD

- **Contexte** : présenter "retirer la contrainte X rend le planning
  faisable" comme "X est LA cause" serait trompeur dès que plusieurs
  relaxations indépendantes mèneraient au même résultat ; proposer de
  relaxer une contrainte HARD serait en plus une contradiction logique du
  modèle (HARD signifie par définition jamais négociable).
- **Décision** : diagnostic UNSAT en quatre couches — précalcul de capacité
  déterministe, matrice locale d'exclusions par garde/groupe, analyse
  native du solveur (cœur d'infaisabilité) si disponible, relaxations
  diagnostiques formulées au conditionnel. Les relaxations ne portent
  jamais sur une contrainte HARD, uniquement sur des `POLICY_HARD`. Un cas
  distinct (`existingDataConflict`) signale quand le solve PARTIAL
  lui-même est infaisable — un conflit dans les données déjà présentes
  (verrouillages existants), pas un déficit de couverture normal.
- **Conséquences** : format JSON complet du rapport UNSAT :
  `docs/allocation-algorithm.md` §16.

## D045 — Explication honnête d'une décision d'optimisation globale

- **Contexte** : un solveur d'optimisation globale multi-variable ne
  "raconte" pas nativement pourquoi il a choisi A plutôt que B — présenter
  cette sélection comme une comparaison locale simple ("A choisi car
  meilleure déviation que B") serait une causalité fabriquée pour une
  décision qui dépend en réalité de l'ensemble du problème.
- **Décision** : les exclusions (candidat non éligible) restent formulées
  comme de vraies causalités locales. La sélection parmi des candidats
  éligibles est toujours formulée par référence à la phase lexicographique
  décisive ("cette affectation fait partie d'une solution globalement
  optimale pour les phases 1 à N ; remplacer A par B dégraderait la
  phase N+1"), jamais par comparaison locale directe. Si l'analyse de
  `GLOBALLY_FORCED` n'a pas été calculée pour une affectation donnée (voir
  D038, stratégie de coût), l'explication le dit explicitement plutôt que
  de laisser croire à tort à une discrétion non vérifiée.
- **Conséquences** : structure de données complète de l'explication (pas
  seulement une phrase) : `docs/allocation-algorithm.md` §17.

## D046 — UUIDv7 comme identifiant stable, en plus d'une PK auto-incrémentée, jamais à sa place

- **Contexte** : implémentation du premier lot du domaine de planification
  (Team, membership, périodes, gardes, RuleSet). Le moteur d'attribution
  (D031-D045) interdit explicitement d'utiliser un ID auto-incrémenté
  comme clé métier dans un calcul reproductible (tie-break, snapshot).
- **Décision** : la clé primaire technique reste un entier auto-incrémenté
  partout (cohérent avec `User`/`RefreshToken`) ; une colonne `stableId`
  (UUIDv7 via `symfony/uid`, type Doctrine `uuid` mappé sur le type natif
  PostgreSQL `uuid`) est ajoutée en plus sur `Team`, `PlanningPeriod`,
  `DutyType`, `DutyPattern`, `DutyGroupInstance`, `Duty`, `PlanningRuleSet`
  — et sur `User` (ajout minimal, sans référence à une équipe, voir plus
  bas), pour porter `candidateStableKey`. UUIDv7 plutôt qu'ULID : type
  natif PostgreSQL compact, même localité d'index temporelle, format RFC
  9562 plus standard pour l'export/l'interopérabilité.
- **Alternative écartée** : faire de l'UUID la clé primaire elle-même —
  écartée pour rester cohérente avec la convention déjà établie ailleurs
  dans le projet (PK entière partout), et parce que le besoin réel n'est
  qu'un identifiant *stable*, pas une PK différente.
- **Conséquences** : `User` gagne un `stableId` — changement volontairement
  minimal (aucune référence à `Team` ajoutée sur `User`, conformément à la
  demande explicite ; un utilisateur appartient à zéro, une ou plusieurs
  équipes via `TeamMember`). `FairnessPeriod`, `TeamMember`,
  `TeamMemberParticipationPeriod` n'en ont volontairement pas reçu — aucun
  besoin identifié dans le seed material du tie-break, ajouté seulement si
  un besoin réel apparaît. Détail : `docs/planning-domain.md` §1.

## D047 — Membership comme succession de stints `TeamMember`, jamais un flag mutable

- **Contexte** : un utilisateur doit pouvoir quitter puis réintégrer une
  équipe sans perdre l'historique de ses appartenances passées
  (`CLAUDE.md` : jamais de suppression destructrice de ce qui a une
  histoire).
- **Décision** : `TeamMember` représente un stint continu
  (`membershipStart`/`membershipEnd` nullable). Quitter ferme le stint
  (`close()`, utilisable une seule fois) ; revenir crée une **nouvelle**
  ligne `TeamMember`, jamais une réouverture de l'ancienne. Au plus un
  stint ouvert par `(team, user)`, garanti par un index unique **partiel**
  PostgreSQL (`WHERE membership_end IS NULL`), pas seulement une
  vérification applicative.
- **Conséquences** : `TeamMembershipService::addMember()` ouvre
  atomiquement le membership et son premier segment de participation
  (voir D048) — un `TeamMember` sans aucun historique de participation
  n'est jamais un état atteignable. `role` (OWNER/ADMIN/MEMBER) reste
  strictement hors de `User::getRoles()` (rappel D012). Détail :
  `docs/planning-domain.md` §4.

## D048 — `participationFactor` historisé, immutable et non chevauchant en base

- **Contexte** : D035 exige que `participationFactor` soit historisé dans
  le temps et jamais recalculé rétroactivement.
- **Décision** : `TeamMemberParticipationPeriod` porte des segments
  `[validFrom, validTo)` (borne haute exclusive), sans setter pour
  `validFrom`/`participationFactor`/`teamMember` — seul `close(validTo)`
  existe, utilisable une fois. Stocké en `decimal(6,4)`, jamais un float
  binaire ; `> 0` obligatoire, **sans plafond** (aucune raison
  mathématique de limiter à `≤ 1` dans le modèle d'équité proportionnelle
  — plafonner aurait été une constante arbitraire injustifiée). Le
  non-chevauchement est garanti par une contrainte d'exclusion PostgreSQL
  (`EXCLUDE USING gist`, extension `btree_gist`), pas seulement une
  vérification applicative.
- **Incident lié** : la contrainte d'exclusion, vérifiée par défaut
  immédiatement après chaque instruction SQL (pas à la validation de la
  transaction), rejetait à tort un changement de facteur légitime — le
  service ferme l'ancien segment (`UPDATE`) et en ouvre un nouveau
  (`INSERT`) dans le même `flush()`, et selon l'ordre choisi par l'Unit of
  Work de Doctrine, l'état intermédiaire pouvait momentanément chevaucher.
  Corrigé en rendant la contrainte `DEFERRABLE INITIALLY DEFERRED`
  (vérifiée à la validation de la transaction) et en forçant explicitement
  sa vérification juste après le `flush()` du service
  (`SET CONSTRAINTS ... IMMEDIATE`) — un vrai chevauchement remonte donc
  quand même immédiatement, sans dépendre d'un `COMMIT` qui pourrait
  tarder ou, en test sous `dama/doctrine-test-bundle` (D017), ne jamais
  survenir.
- **Conséquences** : `participationFactorAt(date)` (`ParticipationPeriodService::factorAt()`)
  reste la seule porte d'accès — aucune logique temporelle dispersée
  ailleurs. Détail : `docs/planning-domain.md` §5 et "Portée exacte du
  contrôle d'intégrité".

## D049 — `FairnessPeriod` non chevauchante par équipe, au niveau base

- **Contexte** : une garde doit appartenir à exactement un ledger
  d'équité — deux `FairnessPeriod` en chevauchement pour une même équipe
  rendraient `requiredDemand`/`target` ambigus.
- **Décision** : même technique que D048 (`EXCLUDE USING gist` sur
  `(team_id, daterange(starts_at, ends_at))`), mais **sans** rendre la
  contrainte différée : `FairnessPeriodService::create()` ne fait qu'un
  seul `INSERT` isolé, sans séquence fermeture+ouverture — la vérification
  immédiate par défaut est donc correcte et plus simple ici.
- **Conséquences** : `FairnessPeriod` reste volontairement sans
  `carryOverPolicy` ni `status` dans ce lot — l'historique des fériés
  nommés survit déjà à toute frontière via la décroissance exponentielle
  (`docs/allocation-algorithm.md` §7), et aucune autre règle de report n'a
  encore de moteur pour la lire. Détail : `docs/planning-domain.md` §6.

## D050 — `PlanningPeriodStatus` : machine à états explicite portée par l'enum

- **Contexte** : `docs/allocation-algorithm.md` §18 fixe le lifecycle
  DRAFT/GENERATED/VALIDATED/PUBLISHED/ARCHIVED et interdit les
  transitions arbitraires.
- **Décision** : le graphe de transitions légales vit directement sur
  l'enum `PlanningPeriodStatus` (`canTransitionTo()`), appliqué uniquement
  via `PlanningPeriod::transitionTo()` / `PlanningPeriodLifecycleService::transition()`
  — jamais un `setStatus()` public permettant de contourner le graphe.
- **Statut** : 🟡 seule la forme de la machine à états est enforced dans ce
  lot. La précondition `PUBLISHED ⇒ coverageStatus = COMPLETE` exigée par
  la spécification ne peut être branchée qu'une fois `PlanningGeneration`
  (hors périmètre) existe — lacune assumée, pas un oubli.
- **Conséquences** : détail complet du graphe de transitions :
  `docs/planning-domain.md` §7.

## D051 — Clés étrangères composites `(id, team_id)` pour la cohérence cross-table d'équipe

- **Contexte** : plusieurs invariants demandés explicitement
  ("DutyGroupInstance cohérent avec PlanningPeriod", "Duty appartenant au
  même PlanningPeriod que son groupe", composant de pattern utilisant un
  DutyType de la même équipe que le pattern) sont des invariants
  **cross-table** qu'un simple `CHECK` ne peut pas exprimer (un `CHECK`
  PostgreSQL ne peut référencer que les colonnes de sa propre ligne).
- **Décision** : chaque entité concernée porte une colonne `team_id`
  dénormalisée, et une clé étrangère **composite** référence `(id,
  team_id)` du parent — qui porte lui-même une contrainte `UNIQUE(id,
  team_id)` en plus de sa clé primaire. Appliqué à `planning_periods` (vs
  `fairness_periods`), `duty_group_instances` (vs `planning_periods` et
  `duty_patterns`), `duty_pattern_components` (vs `duty_patterns` et
  `duty_types`), `duties` (vs `planning_periods`, `duty_types`, et vs
  `duty_group_instances` sur `(group_instance_id, planning_period_id)`).
- **Alternative écartée** : un trigger PostgreSQL — écarté comme outil
  plus lourd, à réserver aux invariants qu'une contrainte déclarative ne
  peut vraiment pas exprimer (voir D052 pour un cas où un trigger a bien
  été écarté au profit d'une vérification applicative).
- **Conséquences** : ces contraintes composites ne sont pas représentées
  dans le mapping Doctrine (l'ORM n'en a pas besoin pour l'hydratation) —
  `doctrine:schema:validate`/`doctrine:migrations:diff` afficheront donc
  en permanence ce diff spécifique comme "non synchronisé" ; **attendu**,
  ne jamais l'appliquer (cela annulerait le durcissement). Détail :
  `docs/planning-domain.md` "Portée exacte du contrôle d'intégrité".

## D052 — `DutyPattern`/`DutyPatternComponent` séparés, `DutyGroupInstance` comme unité atomique matérialisée

- **Contexte** : un `DutyPattern` (ex. "vendredi+samedi+dimanche") a un
  nombre variable de composants — 2 pour un week-end, 2 pour 24+25
  décembre, potentiellement plus.
- **Décision** : deux entités, pas une seule fusionnée. `DutyPattern`
  porte la définition (règle), `DutyPatternComponent` chaque jour du
  pattern (offset + `DutyType`) en relation one-to-many — une collection
  relationnelle permet des contraintes réelles (offset unique par pattern)
  qu'un JSON non typé ou un jeu de colonnes fixe ne permettrait pas.
  `DutyGroupInstance` matérialise l'instance datée d'un pattern au sein
  d'une `PlanningPeriod`, traitée comme un seul nœud pour l'optimisation
  future tout en laissant chaque `Duty` constituante comptée séparément
  pour les compteurs analytiques (`docs/allocation-algorithm.md` §9/§14).
- **Alternative écartée** : vérifier la cohérence pattern↔duties
  matérialisées via un trigger — écartée : c'est un invariant de
  comparaison d'ensembles (les offsets fournis doivent correspondre
  exactement à ceux du pattern), impraticable en `CHECK` simple ; un seul
  point d'entrée (`DutyMaterializationService::materializeGroup()`, qui
  lève `DutyPatternMismatchException` en cas d'écart) est jugé suffisant
  ici, contrairement à la cohérence d'équipe (D051) qui, elle, se prêtait
  naturellement à une clé étrangère composite.
- **Conséquences** : détail : `docs/planning-domain.md` §9-§10.

## D053 — `DutyType.workloadValue` en `decimal`, jamais un float ni un entier mis à l'échelle stocké

- **Contexte** : `workloadValue` alimentera la dimension
  `WEIGHTED_WORKLOAD` du futur moteur, qui aura besoin d'entiers mis à
  l'échelle pour son modèle CP-SAT (`docs/allocation-algorithm.md` §22).
- **Décision** : stocké en `decimal(6,2)` Doctrine (type `NUMERIC`
  PostgreSQL, exact), jamais un float binaire ni un entier pré-mis à
  l'échelle.
- **Justification** : la mise à l'échelle est explicitement une
  responsabilité d'**adapter** (`OptimizationModelBuilder`, déjà actée en
  D031/§22 de l'algorithme), jamais du stockage — stocker directement des
  entiers mis à l'échelle ferait fuiter un détail d'implémentation du
  solveur (le facteur d'échelle) dans le domaine et dans toute vue
  d'administration, contredisant directement le principe "le métier ne
  dépend jamais du solveur".
- **Conséquences** : détail : `docs/planning-domain.md` §8.

## D054 — `PlanningRuleSet` : colonnes structurantes + JSON validé par DTO, versionné et immuable hors DRAFT

- **Contexte** : D039 exige qu'un RuleSet utilisé par une génération ne
  soit jamais modifié rétroactivement ; les règles à porter restent en
  partie conceptuelles/évolutives (`docs/allocation-algorithm.md` §3/§11).
- **Décision** : quelques colonnes typées pour l'identité et le cycle de
  vie (`version` entier séquentiel par équipe, `stableId` UUID global —
  c'est cette dernière valeur, pas `version`, que l'algorithme désigne par
  `rulesVersion`, `status`, `effectiveFrom`), et une colonne `configuration`
  JSON dont le seul point d'entrée est `PlanningRuleSetConfiguration` (DTO
  validé par Symfony Validator) — jamais un tableau brut persisté sans
  passer par lui. Immutabilité réellement appliquée par l'entité
  (`updateConfiguration()`/`activate()` lèvent `ImmutableRuleSetException`
  dès que le statut n'est plus `DRAFT`), pas seulement documentée.
  `activate()` rétrograde automatiquement l'ancien `ACTIVE` de l'équipe en
  `RETIRED` (au plus un `ACTIVE` par équipe, index unique partiel).
- **Alternative écartée** : 40 colonnes typées pour chaque règle — écartée
  (migration à chaque nouvelle règle, alors que plusieurs dimensions
  restent conceptuelles) ; un JSON entièrement libre sans DTO de
  validation — écarté aussi (risque de JSON incontrôlé explicitement
  signalé par la demande).
- **Incident lié** : `activate()` doit effectuer **deux `flush()`
  séparés** (retirer l'ancien `ACTIVE`, puis activer le nouveau) — l'index
  unique partiel garantissant "au plus un `ACTIVE` par équipe" ne peut
  jamais être différé (contrairement à une contrainte `EXCLUDE`, voir
  D048) ; l'exécuter en une seule transition SQL risquerait un état
  intermédiaire à deux lignes `ACTIVE` simultanées selon l'ordre choisi
  par l'Unit of Work de Doctrine.
- **Conséquences** : `LEGAL_MIN_REST` volontairement absent du DTO — voir
  D036, aucune valeur réglementaire n'est inventée ici. Détail :
  `docs/planning-domain.md` §12.

## D055 — Services de domaine sans consommateur, temporairement publics

- **Contexte** : ce lot construit délibérément le socle de données sans
  aucun contrôleur/endpoint (demande explicite — "ne pas encore
  implémenter... endpoints"). Les six nouveaux services
  (`TeamMembershipService`, `ParticipationPeriodService`,
  `FairnessPeriodService`, `PlanningPeriodLifecycleService`,
  `PlanningRuleSetService`, `DutyMaterializationService`) n'ont donc
  aucun consommateur réel — le compilateur de conteneur Symfony les
  retirait entièrement comme code mort, les rendant inaccessibles même
  aux tests d'intégration via `self::getContainer()->get(...)` (constaté
  : `framework.test: true` ne suffit pas à empêcher cette suppression pour
  un service à zéro référence, contrairement à l'attente initiale).
- **Décision** : les six services sont déclarés `public: true` dans
  `config/services.yaml`, avec un commentaire explicite expliquant que
  c'est temporaire.
- **Statut** : 🟡 dette technique documentée, pas une préférence
  d'architecture — à reconsidérer (retour à `private`, implicite) dès
  qu'un contrôleur réel consomme chacun de ces services dans un lot
  suivant.
- **Conséquences** : aucune sur le comportement métier ; purement une
  question de visibilité du conteneur de service. Détail :
  `docs/planning-domain.md` §15.

## D056 — `TeamMember` gagne un `stableId`, en revenant sur le choix YAGNI initial

- **Contexte** : `docs/planning-domain.md` §1 avait explicitement exclu
  `TeamMember` de la liste des entités avec `stableId` ("aucun besoin
  identifié... ajouté seulement si un besoin réel apparaît"). Le Lot 2
  introduit les premiers endpoints qui adressent un membre depuis une URL
  publique (`/api/teams/{teamStableId}/members/{memberStableId}/...`).
- **Décision** : `TeamMember` gagne un `stableId` UUIDv7, même mécanisme
  que `User`/`Team` (colonne nullable puis backfillée
  `gen_random_uuid()` puis `NOT NULL` + index unique — migration
  `Version20260916071843`, même méthode que `Version20260915212445` pour
  `users.stable_id`).
- **Justification** : le besoin réel anticipé par le YAGNI initial est
  désormais concret — pas une anticipation. Continuer à utiliser l'`id`
  auto-incrémenté dans une URL publique violerait la convention déjà en
  place pour `User`/`Team`/`PlanningPeriod`/etc.
- **Conséquences** : `docs/planning-domain.md` §1 doit être lu avec cette
  correction ; `TeamMemberParticipationPeriod` et `FairnessPeriod` restent
  sans `stableId`, aucun besoin réel n'étant apparu pour elles dans ce
  lot. Détail : `docs/availability.md` §1.

## D057 — Pas de Voter pour `/api/me/calendar` : ownership imposé structurellement

- **Contexte** : le calendrier personnel doit être strictement limité à
  l'utilisateur courant (cahier des charges : "un utilisateur ne doit pas
  pouvoir modifier directement le calendrier personnel d'un autre
  utilisateur").
- **Décision** : pas de Voter dédié. `PersonalCalendarController`
  n'accepte jamais un `User` en paramètre de route — uniquement
  `#[CurrentUser] User $user` — et toute lecture/écriture passe par
  `UserAvailabilityPeriodRepository::findOneByStableId()` suivi d'une
  comparaison `->getUser() !== $user` → `404` (jamais `403`, pour ne
  jamais confirmer l'existence de l'entrée d'un tiers).
- **Alternative écartée** : un `UserOwnershipVoter` générique — écarté
  (confirmé par la personne à l'origine de la demande) : aucune règle
  métier à exprimer au-delà de "c'est bien le même `User`", qu'un Voter
  n'apporterait pas au-delà de ce que la contrainte de signature du
  contrôleur garantit déjà.
- **Conséquences** : détail et contraste avec `TeamRoleVoter` (qui, lui,
  exprime une vraie règle de rôle) : `docs/availability.md` §6.

## D058 — Politique de chevauchement "et contact" avec bornes `EXCLUDE` inclusives

- **Contexte** : `UserAvailabilityPeriod`/`TeamMemberNonParticipationPeriod`
  doivent refuser deux périodes qui se touchent exactement (fin de l'une =
  début de l'autre), contrairement à `FairnessPeriod`/
  `TeamMemberParticipationPeriod` qui autorisent des segments contigus par
  construction (append-only, un segment ferme exactement où le suivant
  commence).
- **Décision** : la contrainte `EXCLUDE USING gist` utilise
  `tstzrange(starts_at, ends_at, '[]')` (bornes inclusives des deux côtés)
  au lieu de `'[)'` — deux périodes qui se touchent partagent alors un
  point commun que l'opérateur `&&` détecte. Même logique côté application
  (`startsAt <= endsAt AND endsAt >= startsAt` plutôt que des comparaisons
  strictes). Pas `DEFERRABLE` : chaque écriture ici est un `INSERT`/
  `UPDATE` unique, jamais une paire fermeture-puis-ouverture comme pour
  `TeamMemberParticipationPeriod` (D048) — pas d'état intermédiaire à
  couvrir.
- **Conséquences** : détail, y compris la justification métier du choix
  ("et contact" plutôt que chevauchement strict) : `docs/availability.md`
  §4.

## D059 — `GET /api/teams/{teamStableId}/members` minimal, pas une fonctionnalité d'équipe complète

- **Contexte** : aucun contrôleur Team/TeamMember n'existait avant ce lot
  (le socle "équipes" livré précédemment n'avait aucun endpoint HTTP — voir
  D055). La non-participation administrative doit pourtant être "accessible
  depuis l'administration des membres d'une équipe" (cahier des charges),
  ce qui suppose de pouvoir lister les membres.
- **Décision** : un unique endpoint en lecture, volontairement minimal
  (`stableId`, `firstName`, `lastName`, `role`, `active`), protégé par
  `TeamRoleVoter::VIEW_TEAM` (tout membre actuel, n'importe quel rôle).
  Rien côté invitation, ajout/suppression de membre, édition de rôle ou
  création d'équipe.
- **Justification** : confirmé explicitement par la personne à l'origine
  de la demande — fournir uniquement le minimum nécessaire pour rendre le
  CRUD de non-participation utilisable depuis `TeamDetailPage`, sans
  élargir ce lot à la gestion d'équipe complète (qui reste un lot futur,
  cf. tableau d'avancement de `CLAUDE.md`).
- **Conséquences** : `TeamsPage`/`TeamDetailPage` restent sans moyen de
  découvrir un `teamStableId` par la navigation (pas de liste d'équipes) —
  limitation connue, acceptée pour ce lot. Détail : `docs/availability.md`
  §7-§8.

## D060 — `PlanningGenerationStatus` réduit à `DRAFT`/`SNAPSHOTTED`

- **Contexte** : le cahier des charges du Lot 3 proposait au minimum
  `DRAFT/SNAPSHOTTED/COMPLETED/FAILED`, mais `COMPLETED`/`FAILED`
  représentent l'issue d'un solve — aucun solveur n'existe encore
  (`docs/allocation-algorithm.md` reste entièrement non implémenté).
- **Décision** : l'enum ne porte que les deux statuts que ce lot peut
  réellement atteindre, avec le même mécanisme de graphe de transitions
  explicite que `PlanningPeriodStatus` (D050) — `canTransitionTo()` sur
  l'enum, appliqué uniquement via `PlanningGeneration::transitionTo()`.
- **Justification** : inventer des statuts qu'aucun code ne peut
  légitimement atteindre serait une anticipation non fondée du futur
  moteur — le même principe que celui qui a écarté un pseudo-algorithme
  provisoire pour ce lot.
- **Conséquences** : `PlanningGenerationStatus` devra être étendu (pas
  remplacé) quand le solveur existera — l'infrastructure de graphe de
  transitions est déjà en place pour ça. Détail : `docs/planning-generation.md`
  §2.

## D061 — Snapshot : identité des membres par valeur de `stableId`, jamais par FK vivante

- **Contexte** : `PlanningSnapshotMember` doit rester interprétable même
  si le `TeamMember`/`User` source change de rôle, est renommé, ou quitte
  l'équipe après le snapshot.
- **Décision** : `sourceTeamMemberStableId`/`sourceUserStableId` sont des
  colonnes `uuid` brutes (copie de valeur), jamais une relation Doctrine
  vers l'entité vivante. Les enfants du snapshot
  (`PlanningSnapshotParticipationPeriod`, etc.) référencent en revanche
  leur `PlanningSnapshotMember` par une vraie FK (`onDelete: CASCADE`) —
  ce ne sont que la décomposition du même snapshot, sans vie propre.
- **Alternative écartée** : une FK vers `TeamMember` avec lecture différée
  du `participationFactor`/rôle courant — explicitement l'exemple
  "incorrect" donné par la demande de ce lot : ça ne constitue pas un vrai
  snapshot, puisqu'une lecture future suivrait silencieusement l'état
  courant.
- **Conséquences** : `DutyAssignment` porte à la fois `teamMember` (ligne
  vivante, fonctionnement courant) et `snapshotMember` (ligne figée,
  interprétation historique) — voir D062. Détail : `docs/planning-generation.md`
  §3.

## D062 — `DutyAssignment` porte `teamMember` **et** `snapshotMember`

- **Contexte** : une affectation doit rester fonctionnellement utile
  aujourd'hui (lister les gardes d'un membre courant) et rester
  interprétable historiquement même si ce membre change ensuite (D061).
- **Décision** : `DutyAssignment` référence les deux. `snapshotMember`
  est résolu par `DutyAssignmentService` à partir de `teamMember` via
  `PlanningSnapshotMemberRepository::findOneBySnapshotAndTeamMemberStableId()`
  — absent, l'affectation est refusée (`InvalidDutyAssignmentException`,
  422) : un membre non présent au moment du snapshot ne peut pas recevoir
  d'affectation dans cette génération.
- **Conséquences** : une affectation ne peut jamais être créée avant que
  la génération soit snapshottée (`PlanningGenerationNotSnapshottedException`,
  409) — le pipeline `snapshot → generation → assignments` du cahier des
  charges est donc appliqué strictement, pas seulement documenté. Détail :
  `docs/planning-generation.md` §5.

## D063 — Concurrence du snapshot/assignment par statut + contrainte unique, pas par hash

- **Contexte** : `docs/allocation-algorithm.md` §15 décrit une détection
  de concurrence par `snapshotHash` recalculé au moment du persist — ce
  mécanisme suppose un solve asynchrone qui n'existe pas dans ce lot (le
  snapshot est construit et persisté de façon synchrone, en un seul appel).
- **Décision** : `PlanningSnapshotService::createSnapshot()` vérifie
  `generation.status === DRAFT` en premier (chemin rapide, cas séquentiel),
  puis s'appuie sur la contrainte unique de `planning_snapshots.generation_id`
  pour le cas concurrent réel — une violation au `flush()` est convertie en
  `PlanningGenerationAlreadySnapshottedException` (409), même schéma que
  `UserRegistrationService`/`EmailAlreadyUsedException` (D026). Même
  principe pour `DutyAssignment` via `UNIQUE(generation_id, duty_id)` →
  `DuplicateDutyAssignmentException`.
- **Conséquences** : le snapshot entier (racine + membres + enfants + rule
  set + transition de statut) est construit dans un seul `flush()` Doctrine
  — une seule transaction, donc pas d'écriture partielle en cas d'échec
  concurrent. Le mécanisme par hash de §15 reste à construire quand un
  solve asynchrone existera réellement. Détail : `docs/planning-generation.md`
  §6.

## D064 — `Duty` non dupliquée dans le snapshot, confirmé par lecture du code

- **Contexte** : le cahier des charges du Lot 3 demandait explicitement de
  ne pas supposer silencieusement l'immuabilité de `Duty` et d'inspecter le
  modèle réel avant de trancher.
- **Décision** : `Duty` (`backend/src/Entity/Duty.php`) ne porte aucune
  méthode mutante — toutes ses propriétés ne sont écrites que dans le
  constructeur, vérifié ligne par ligne. `DutyAssignment` référence donc
  `Duty` directement (FK simple), sans `PlanningSnapshotDuty`.
- **Conséquences** : si `Duty` gagnait un jour une méthode mutante (ex.
  changement d'horaire après création), cette décision devrait être
  révisée — jusque-là, dupliquer une donnée déjà immuable n'apporterait
  aucune garantie, seulement une source de divergence à maintenir. Détail :
  `docs/planning-generation.md` §4.

## D065 — Pas de FK composite pour les invariants Team/PlanningPeriod de `DutyAssignment`

- **Contexte** : `DutyAssignment` a deux invariants cross-table du même
  type que ceux résolus par FK composite `(id, team_id)` ailleurs dans le
  domaine (D051) : `duty.planningPeriod === generation.planningPeriod` et
  `teamMember.team === generation.team`.
- **Décision** : ces invariants sont vérifiés en application
  (`DutyAssignmentService`, avec redondance `\InvalidArgumentException`
  dans le constructeur de `DutyAssignment`, même style que `Duty`), pas via
  FK composite en base.
- **Alternative écartée** : reproduire D051 — écartée parce qu'elle
  demanderait d'ajouter `UNIQUE(id, planning_period_id)` sur `duties` et
  `UNIQUE(id, team_id)` sur `team_members`, deux tables déjà livrées dans
  un lot précédent, pour un unique nouveau consommateur — une extension de
  schéma disproportionnée pour ce lot (`CLAUDE.md` : "pas de refonte
  massive non justifiée").
- **Conséquences** : ces deux invariants ne sont garantis qu'au niveau
  application, pas au niveau base — à reconsidérer si un second
  consommateur a besoin de la même garantie au niveau base. Détail :
  `docs/planning-generation.md` §8.
