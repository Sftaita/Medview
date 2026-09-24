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
| [D066](#d066--nouveau-namespace-srceligibility-pour-le-modèle-métier-déligibilité) | 2026-09-16 | Nouveau namespace `src/Eligibility/` pour le modèle métier d'éligibilité | 🟢 |
| [D067](#d067--planningsnapshotmemberactive--extension-du-snapshot-pour-user_inactive) | 2026-09-16 | `PlanningSnapshotMember.active` : extension du snapshot pour `USER_INACTIVE` | 🟢 |
| [D068](#d068--eligibilityresulteligibilityexclusion--champs-dérivés-jamais-indépendants) | 2026-09-16 | `EligibilityResult`/`EligibilityExclusion` : champs dérivés, jamais indépendants | 🟢 |
| [D069](#d069--group_unavailable-nemballe-que-unavailablenon_participation-jamais-membership_out_of_range) | 2026-09-16 | `GROUP_UNAVAILABLE` n'emballe que `UNAVAILABLE`/`NON_PARTICIPATION`, jamais `MEMBERSHIP_OUT_OF_RANGE` | 🟢 |
| [D070](#d070--conflict-reporté-malgré-lexistence-de-dutyassignment) | 2026-09-16 | `CONFLICT` reporté malgré l'existence de `DutyAssignment` | 🟡 |
| [D071](#d071--planningcreator-seul-manager-en-v1-via-planningvoter-séparé) | 2026-09-16 | `Planning.creator` seul manager en v1, via `PlanningVoter` séparé | 🟢 |
| [D072](#d072--teammember--au-plus-une-team-active-par-user-plus-par-team-user) | 2026-09-16 | `TeamMember` : au plus une Team active par User (plus par `(Team, User)`) | 🔴 (D080) |
| [D073](#d073--planningline-reste-une-entité-séparée-de-team-malgré-la-relation-11-imposée-en-v1) | 2026-09-16 | `PlanningLine` reste une entité séparée de `Team`, malgré la relation 1:1 imposée en v1 | 🟡 |
| [D074](#d074--ligne-primary-jamais-supprimable--création-planningligne-primary-atomique) | 2026-09-16 | Ligne PRIMARY jamais supprimable ; création `Planning`+ligne PRIMARY atomique | 🟢 |
| [D075](#d075--le-planningperiod-dune-planningline-doit-correspondre-exactement-aux-dates-du-planning) | 2026-09-16 | Le `PlanningPeriod` d'une `PlanningLine` doit correspondre exactement aux dates du `Planning` | 🟢 |
| [D076](#d076--visibilité-de-get-apiplannings--creator--membres-des-teams-associées-rien-de-plus) | 2026-09-16 | Visibilité de `GET /api/plannings` : creator + membres des Teams associées, rien de plus | 🟡 |
| [D077](#d077--patch-apiplanningsstableid-ne-modifie-que-name-en-v1) | 2026-09-16 | `PATCH /api/plannings/{stableId}` ne modifie que `name` en v1 | 🟡 |
| [D078](#d078--canmanage-calculé-côté-serveur-plutôt-que-dexposer-userstableid-sur-apime) | 2026-09-17 | `canManage` calculé côté serveur plutôt que d'exposer `User.stableId` sur `/api/me` | 🟡 |
| [D079](#d079--team-devient-planningteam--propriété-exclusive-dun-planning-créée-inline-par-planningline) | 2026-09-18 | `Team` devient `PlanningTeam` : propriété exclusive d'un Planning, créée inline par `PlanningLine` | 🟢 |
| [D080](#d080--adhésion-unique-par-planning-et-non-par-application-remplace-d072) | 2026-09-18 | Adhésion unique par Planning (et non par application), remplace D072 | 🟢 |
| [D081](#d081--planningteammemberplanning-dénormalisé--fk-composite-plutôt-quune-jointure-via-planningteam) | 2026-09-18 | `PlanningTeamMember.planning` dénormalisé + FK composite, plutôt qu'une jointure via `PlanningTeam` | 🟢 |
| [D082](#d082--candidat-de-fairness--sourceuserstableid-jamais-le-stint-sourceteammemberstableid) | 2026-09-18 | Candidat de fairness = `sourceUserStableId`, jamais le stint `sourceTeamMemberStableId` | 🟢 |
| [D083](#d083--dutygroupinstance-ne-peut-jamais-mélanger-required-et-optional) | 2026-09-18 | `DutyGroupInstance` ne peut jamais mélanger `REQUIRED` et `OPTIONAL` | 🟢 |
| [D084](#d084--effectiveexposure-porte-sur-toutes-les-duties-jamais-restreinte-aux-required) | 2026-09-18 | `effectiveExposure` porte sur toutes les Duties, jamais restreinte aux REQUIRED | 🟢 |
| [D085](#d085--discretionarytargetatsolve--max0-grosstarget--structurallyforcedload) | 2026-09-18 | `discretionaryTargetAtSolve = max(0, grossTarget − structurallyForcedLoad)` | 🟢 |
| [D086](#d086--classification-primarysecondary-des-dimensions-composant-dédié-swappable-primary-vide-aujourdhui) | 2026-09-18 | Classification PRIMARY/SECONDARY des dimensions : composant dédié swappable, PRIMARY vide aujourd'hui | 🟢 |
| [D087](#d087--8-phases-generate-explicites-secondary-jamais-fusionnée-maxsum-en-une-seule-phase) | 2026-09-18 | 8 phases GENERATE explicites, SECONDARY jamais fusionnée max/sum en une seule phase | 🟢 |
| [D088](#d088--tie-break-direction-minimize-conventionnelle-et-absence-de-matière-de-seed-réelle) | 2026-09-18 | Tie-break : direction MINIMIZE conventionnelle, pas de matière de seed réelle encore | 🟢 |
| [D089](#d089--feasible-sur-une-phase-non-finale--arrêt-de-la-chaîne-lexicographique) | 2026-09-18 | `FEASIBLE` (non prouvé optimal) sur une phase non finale → arrêt de la chaîne lexicographique | 🟢 |
| [D090](#d090--fixedassignments-confirmé-absent-doptimizationproblem-jamais-fabriqué-par-ladapter) | 2026-09-18 | `fixedAssignments` confirmé absent d'`OptimizationProblem` — jamais fabriqué par l'adapter | 🟢 |
| [D091](#d091--planningsolvercheckfeasibility-retourne-solverstatus-jamais-bool) | 2026-09-18 | `PlanningSolver::checkFeasibility()` retourne `SolverStatus`, jamais `bool` | 🟢 |
| [D092](#d092--échelle-entière-cp-sat--scale--10-000-auditée-sur-la-précision-réelle-de-workloadvalue) | 2026-09-18 | Échelle entière CP-SAT `SCALE = 10 000`, auditée sur la précision réelle de `workloadValue` | 🟢 |
| [D093](#d093--aucun-timeout-cp-sat-par-défaut--gap-documenté-plutôt-quune-constante-cachée) | 2026-09-18 | Aucun timeout CP-SAT par défaut — gap documenté plutôt qu'une constante cachée | 🟢 |
| [D094](#d094--orchestration-strict--partial-dans-ortoolsplanningsolversolve-jamais-un-orchestrateur-séparé) | 2026-09-18 | Orchestration STRICT → PARTIAL dans `OrToolsPlanningSolver::solve()`, jamais un orchestrateur séparé | 🟢 |
| [D095](#d095--un-candidat-éligible-mais-non-sélectionné-ne-reçoit-jamais-de-fausse-raison-dexclusion) | 2026-09-18 | Un candidat éligible mais non sélectionné ne reçoit jamais de fausse raison d'exclusion | 🟢 |
| [D096](#d096--solveranalysisavailable--false-dans-ce-lot--aucune-littérale-dassumption-câblée) | 2026-09-18 | `solverAnalysis.available = false` dans ce lot — aucune assumption literal câblée | 🟢 |
| [D097](#d097--diagnosticrelaxations-toujours-vide--aucune-policy_hard-produite-aujourdhui) | 2026-09-18 | `diagnosticRelaxations` toujours vide — aucune POLICY_HARD produite aujourd'hui | 🟢 |
| [D098](#d098--existingdataconflict-contrat-prêt-scénario-inatteignable-avec-le-modèle-actuel) | 2026-09-18 | `existingDataConflict` : contrat prêt, scénario inatteignable avec le modèle actuel | 🟢 |
| [D099](#d099--priorité-critical-structurellement-inerte-avec-le-jeu-de-contraintes-actuel) | 2026-09-18 | Priorité CRITICAL structurellement inerte avec le jeu de contraintes actuel (aucune vraie contention possible) | 🟢 |
| [D100](#d100--assignmentconflict--première-contrainte-globale-reliant-deux-dutyunit-calculée-dans-le-domaine) | 2026-09-19 | `AssignmentConflict` : première contrainte globale reliant deux `DutyUnit`, calculée dans le domaine | 🟢 |
| [D101](#d101--conflict-hard-et-team_min_rest-policy_hard-implémentés-legal_min_rest-toujours-non-implémentée) | 2026-09-19 | `CONFLICT` (HARD) et `TEAM_MIN_REST` (POLICY_HARD) implémentées ; `LEGAL_MIN_REST` toujours non implémentée | 🟡 (portée révisée par D105) |
| [D102](#d102--insufficient_eligible_capacity--cas-exact-unique-un-seul-candidat-partagé-entre-deux-unités-en-conflit) | 2026-09-19 | `INSUFFICIENT_ELIGIBLE_CAPACITY` : cas exact unique (un seul candidat partagé entre deux unités en conflit) | 🟢 |
| [D103](#d103--diagnosticrelaxations-réellement-calculé-par-un-vrai-re-solve-jamais-inféré) | 2026-09-19 | `diagnosticRelaxations` réellement calculé par un vrai re-solve, jamais inféré | 🟢 |
| [D104](#d104--max_duties-max_weekends-max_consecutive_nights-non-implémentées-malgré-une-configuration-réelle) | 2026-09-19 | `MAX_DUTIES`/`MAX_WEEKENDS`/`MAX_CONSECUTIVE_NIGHTS` non implémentées malgré une configuration réelle existante | 🟢 |
| [D105](#d105--legal_min_rest-et-team_min_rest-sont-des-politiques-activables-par-génération--aucune-durée-réglementaire-nest-déduite-automatiquement) | 2026-09-19 | `LEGAL_MIN_REST`/`TEAM_MIN_REST` deviennent des politiques activables par génération ; remplace la portée équipe-globale de D101 | 🟢 |
| [D106](#d106--orchestration-réelle-planninggeneration--solve--dutyassignment-auto-solverparameterset-seed-snapshothash-concurrence) | 2026-09-19 | Orchestration réelle `PlanningGeneration → solve → DutyAssignment AUTO` : `SolverParameterSet`, seed/snapshotHash réels, timeout CP-SAT réel, concurrence par verrou optimiste, atomicité, PUBLISHED ⇒ coverage COMPLETE | 🟢 |
| [D110](#d110--les-hôpitaux-sont-un-référentiel-structuré-hospital-alimenté-par-import-jamais-un-texte-libre-ni-un-jeu-de-données-livré) | 2026-09-20 | Hôpitaux : référentiel `Hospital` + `User.primaryHospital` — **remplacé par D115** | 🔴 |
| [D111](#d111--une-invitation-nest-jamais-un-faux-user--teaminvitation-et-un-seul-créateur-de-user) | 2026-09-20 | Une invitation n'est jamais un faux `User` : `TeamInvitation`, un seul créateur de `User` | 🟢 |
| [D112](#d112--téléphone--libphonenumber-stocké-en-e164-validé-côté-serveur) | 2026-09-20 | Téléphone : libphonenumber, E.164, validé côté serveur | 🟢 |
| [D113](#d113--sécurité-des-invitations--token-haché-transaction-verrouillée-inscription-classique--consommation) | 2026-09-20 | Sécurité des invitations : token haché, transaction verrouillée, inscription classique ≠ consommation | 🟢 |
| [D114](#d114--emails-transactionnels--symfony-mailer--twig-templates-de-la-maquette-envoi-best-effort) | 2026-09-20 | Emails transactionnels : Symfony Mailer + Twig, envoi best-effort | 🟢 |
| [D115](#d115--létablissement-nest-pas-une-propriété-du-user--le-référentiel-hospital-est-supprimé) | 2026-09-20 | L'établissement n'est pas une propriété du `User` ; `Hospital` supprimé (remplace D110) | 🟢 |
| [D116](#d116--les-corps-json-désérialisés-en-dto-rejettent-les-champs-inconnus-422-au-lieu-de-les-ignorer) | 2026-09-20 | Les corps JSON désérialisés en DTO rejettent les champs inconnus (`422`) au lieu de les ignorer | 🟢 |
| [D120](#d120--availabilitycollectionresponse-revue-dune-fenêtre--useravailabilityperiod-vérité-de-disponibilité) | 2026-09-21 | `AvailabilityCollectionResponse` (revue d'une fenêtre) ≠ `UserAvailabilityPeriod` (vérité de disponibilité) | 🟢 |
| [D121](#d121--une-modification-après-confirmation-ne-rouvre-pas-la-réponse) | 2026-09-21 | Une modification après confirmation ne rouvre pas la réponse | 🟢 |
| [D122](#d122--prolonger-un-planning--agrandissement-en-place-collecte-de-la-seule-tranche-nouvelle) | 2026-09-21 | Prolonger un planning : agrandissement en place, collecte de la seule tranche nouvelle | 🟡 |
| [D123](#d123--le-créateur-participe-par-une-adhésion-pas-par-un-booléen) | 2026-09-21 | Le créateur participe par une adhésion, pas par un booléen | 🟢 |
| [D124](#d124--planning_manage_availability--créateur-ou-owneradmin-plus-large-que-manage) | 2026-09-21 | `PLANNING_MANAGE_AVAILABILITY` : créateur ou OWNER/ADMIN | 🟡 |
| [D125](#d125--lecture-des-affectations--dernière-génération-completed-de-chaque-ligne) | 2026-09-21 | Lecture des affectations : dernière génération `COMPLETED` de chaque ligne | 🟢 |
| [D126](#d126--frontend--magasin-partagé-sauvegarde-optimiste-par-diff-retour-à-la-vérité-serveur) | 2026-09-21 | Frontend : magasin partagé, sauvegarde optimiste par diff | 🟢 |
| [D134](#d134--semaine-type--composant-weekstructureeditor-intégré-tel-quel-boutons-de-lapp-pas-de-mui-pas-encore-branché) | 2026-09-23 | Semaine type : `WeekStructureEditor` intégré tel quel, boutons `.btn` (pas de MUI), pas encore branché | 🟡 |

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

## D066 — Nouveau namespace `src/Eligibility/` pour le modèle métier d'éligibilité

- **Contexte** : `ExclusionReason`, `ConstraintTier`, `EligibilityResult`,
  `EligibilityExclusion`, `DutyUnit`/`SingleDutyUnit`/`DutyGroupUnit`,
  `EligibilityMatrix` ne sont ni des entités Doctrine (`src/Entity/`,
  persistées) ni des DTO de frontière HTTP (`src/Dto/`, désérialisés
  depuis une requête) — un objet de domaine pur, construit à la volée
  depuis le snapshot, jamais persisté.
- **Décision** : nouveau namespace `src/Eligibility/`, distinct des deux
  précédents. `EligibilityService`/`EligibilityMatrixBuilder` (les
  services qui orchestrent ce modèle) restent dans `src/Service/`, comme
  tout le reste de la logique métier du projet — seul le modèle de
  données lui-même change d'emplacement.
- **Justification** : forcer ces objets dans `src/Dto/` aurait mélangé
  deux notions différentes ("forme acceptée/renvoyée par une requête HTTP"
  vs "modèle de domaine interne") ; les forcer en entités Doctrine aurait
  fait porter une notion de persistance à des objets qui n'en ont
  explicitement pas besoin (§eligibility.md "Pas de persistance de la
  matrice"). `docs/allocation-algorithm.md` D031 avait déjà anticipé une
  architecture en couches (domaine → `OptimizationProblem` → solveur) —
  ce namespace en est la première couche concrète.
- **Conséquences** : tout futur objet de domaine du moteur sans besoin de
  persistance (ex. un futur `OptimizationProblem`) a désormais un
  emplacement cohérent où vivre. Détail : `docs/eligibility.md` §1.

## D067 — `PlanningSnapshotMember.active` : extension du snapshot pour `USER_INACTIVE`

- **Contexte** : la spécification du Lot 4 demandait un choix argumenté
  entre étendre `PlanningSnapshotMember` pour figer `User::isActive()`, ou
  différer `USER_INACTIVE` faute de donnée snapshotée.
- **Décision** : étendre. Colonne `active` `NOT NULL` ajoutée directement
  (`Version20260916095723`) — zéro ligne existante dans
  `planning_snapshot_members` au moment du lot, donc pas de backfill
  nécessaire (contrairement à `users.stable_id`, D046).
  `structuralOpportunity` traite `USER_INACTIVE` comme un fait structurel
  (met l'exposition à zéro), pas comme une déclaration personnelle
  circonstancielle comme `UNAVAILABLE` — un compte désactivé retire
  réellement la personne de la capacité de l'équipe, ce n'est pas un choix
  temporaire.
- **Justification** : coût minimal (une seule colonne booléenne) ; la
  désactivation est un cas limite déjà explicitement exigé par
  `CLAUDE.md` ("cas limites couverts explicitement... désactivation") ;
  laisser `EligibilityService` lire `$member->getUser()->isActive()` en
  direct aurait violé le principe central du Lot 3 ("une génération
  historique doit toujours être interprétée à partir de l'état figé au
  moment du snapshot").
- **Conséquences** : `PlanningSnapshotService::createSnapshot()` capture
  désormais aussi ce champ à chaque génération. Détail :
  `docs/eligibility.md` §5.

## D068 — `EligibilityResult`/`EligibilityExclusion` : champs dérivés, jamais indépendants

- **Contexte** : la spécification du Lot 4 proposait `EligibilityResult`
  avec `$eligible` comme premier paramètre du constructeur, indépendant de
  `$exclusions`, et `EligibilityExclusion` avec `$tier` accepté au même
  titre que `$reason`.
- **Décision** : `$eligible` est dérivé de `[] === $exclusions` dans le
  constructeur de `EligibilityResult`, jamais accepté en paramètre
  indépendant. `EligibilityExclusion::$tier` est de la même façon dérivé
  de `$reason->tier()`, jamais accepté indépendamment. Écart volontaire
  par rapport à la signature donnée en exemple par la spécification (qui
  autorisait explicitement "une structure équivalente mieux adaptée au
  code réel").
- **Justification** : un résultat prétendant "éligible" avec une liste
  d'exclusions non vide, ou une exclusion dont le tier contredirait
  `ExclusionReason::tier()`, seraient des bugs représentables par le type
  — exactement la classe d'erreur que la spécification elle-même interdit
  ("un reason ne doit pas changer de tier selon le contexte"). Rendre ces
  états impossibles à construire est une garantie plus forte qu'une
  simple convention documentée.
- **Conséquences** : aucun appelant ne peut jamais désynchroniser ces
  deux paires de champs, y compris par erreur future. Détail :
  `docs/eligibility.md` §1.

## D069 — `GROUP_UNAVAILABLE` n'emballe que `UNAVAILABLE`/`NON_PARTICIPATION`, jamais `MEMBERSHIP_OUT_OF_RANGE`

- **Contexte** : pour un `DutyGroupUnit`, plusieurs composants peuvent
  produire des causes d'exclusion différentes (une garde du groupe hors
  membership, une autre couverte par une indisponibilité) — la
  spécification demande un emballage `GROUP_UNAVAILABLE` "si la cause
  vient d'une indisponibilité/non-participation sur un composant du
  groupe", sans mentionner `MEMBERSHIP_OUT_OF_RANGE`.
- **Décision** : seules les causes composant-par-composant `UNAVAILABLE`
  et `NON_PARTICIPATION` sont emballées dans une unique exclusion
  `GROUP_UNAVAILABLE` (dont `context.rootCauses` liste chaque cause
  racine réelle). `MEMBERSHIP_OUT_OF_RANGE` reste toujours reportée
  directement, avec `context.affectedDutyStableIds` listant les gardes du
  groupe concernées.
- **Justification** : suit le texte de la spécification à la lettre
  plutôt que d'étendre l'emballage par analogie — `MEMBERSHIP_OUT_OF_RANGE`
  reste une notion structurelle de premier ordre pour laquelle masquer la
  raison exacte derrière une raison dérivée n'apporterait rien d'utile à
  l'audit.
- **Conséquences** : testé explicitement
  (`EligibilityServiceTest::testGroupMembershipCoveringOnlyOneDutyExcludesTheWholeGroup`).
  Détail : `docs/eligibility.md` §6.

## D070 — `CONFLICT` reporté malgré l'existence de `DutyAssignment`

- **Contexte** : la spécification autorise `CONFLICT` "si le service
  évalue un candidat en tenant compte d'affectations fixes/existantes
  réellement connues de la génération" — et `DutyAssignment` (Lot 3)
  existe bel et bien comme source potentielle.
- **Décision** : `CONFLICT` reste dans le contrat de `ExclusionReason`
  mais n'est calculé nulle part dans ce lot.
- **Justification** : aucun `DutyAssignment` `AUTO` n'existe (pas de
  solveur), seules des affectations `MANUAL` isolées pourraient exister à
  ce stade — bâtir la logique de détection de conflit maintenant
  reviendrait à concevoir contre un usage qui n'existe pas encore
  réellement, avec le risque de devoir la refaire une fois le vrai
  pipeline de génération en place. La spécification elle-même autorise
  explicitement ce report ("documenter ce point comme reporté").
- **Conséquences** : à réévaluer dès qu'un flux réel d'affectations
  (manuel en volume, ou automatique) existe. Détail :
  `docs/eligibility.md` §3.

## D071 — `Planning.creator` seul manager en v1, via `PlanningVoter` séparé

- **Contexte** : `Planning` est un nouvel agrégat visible utilisateur,
  potentiellement associé à plusieurs Teams via ses `PlanningLine`. Un
  OWNER/ADMIN d'une de ces Teams ne doit **pas** hériter automatiquement
  d'un droit de gestion sur le Planning.
- **Décision** : `PlanningVoter` (`PLANNING_VIEW`/`PLANNING_MANAGE`),
  séparé de `TeamRoleVoter`. `PLANNING_MANAGE` est vrai uniquement pour
  `user === planning.creator` — aucune combinaison de rôles d'équipe ne
  peut jamais satisfaire cette condition. Aucun concept de
  collaborateur/co-owner en v1.
- **Justification** : un Planning combine plusieurs Teams ; lui donner un
  modèle d'autorisation dérivé des rôles d'équipe aurait immédiatement
  posé la question "quelle Team fait autorité ?", sans réponse évidente.
  Un propriétaire unique et explicite évite ce problème sans le trancher
  prématurément — un vrai modèle de collaboration pourra être ajouté plus
  tard sans revenir sur ce choix (`PLANNING_MANAGE` resterait vrai pour le
  creator, une future règle s'y ajouterait).
- **Conséquences** : `PlanningController`/`PlanningLineController`
  réservent systématiquement les écritures à `PLANNING_MANAGE`. Détail :
  `docs/planning.md` §3, §10.

## D072 — `TeamMember` : au plus une Team active par User (plus par `(Team, User)`) 🔴 Remplacé par [D080](#d080--adhésion-unique-par-planning-et-non-par-application-remplace-d072)

> **🔴 Remplacé (2026-09-18)** : cette règle app-wide ("une seule Team
> active dans toute l'application") est abandonnée dès que `Team` cesse
> d'être une entité globale (D079) — elle n'a plus de sens une fois
> qu'une Team appartient à exactement un Planning. Voir D080 pour la
> règle qui la remplace ("une seule adhésion ouverte par Planning").
> Entrée conservée intacte ci-dessous pour l'historique, jamais
> supprimée (convention du fichier).

- **Contexte** : le domaine autorisait jusqu'ici un User à avoir des
  memberships ouverts simultanés dans plusieurs Teams. Le lot Planning
  introduit une règle métier explicite : un User n'est candidat que pour
  exactement une Team à la fois.
- **Décision** : l'index unique partiel `uniq_team_members_open_membership`
  passe de `(team_id, user_id) WHERE membership_end IS NULL` à `(user_id)
  WHERE membership_end IS NULL` (migration `Version20260916123301`).
  `TeamMembershipService::addMember()` vérifie désormais
  `TeamMemberRepository::findOpenMembershipForUser()` (toute Team) au lieu
  de `findOpenMembership($team, $user)` (une Team précise). Même
  exception métier (`TeamMembershipConflictException`), message généralisé
  plutôt qu'une nouvelle classe — c'est le même concept ("un membership
  ouvert entre en conflit"), seule sa portée change. L'historique par
  stints (D047) est inchangé : un User peut avoir appartenu à différentes
  Teams à des périodes différentes, jamais simultanément.
- **Alternative écartée** : garder l'ancienne contrainte et n'ajouter la
  nouvelle règle qu'au niveau applicatif (`TeamMembershipService` seul) —
  écartée : la spécification demande explicitement "ajouter une contrainte
  DB forte", cohérent avec la philosophie déjà établie du projet (la base
  rend les états impossibles impossibles, jamais seulement PHP).
- **Conséquences** : zéro ligne dans `team_members` au moment de ce lot
  (vérifié), donc aucun conflit de données existantes — migration directe,
  sans étape de backfill. Quatre tests pré-existants du Lot 2/3/4
  supposaient un User dans deux Teams simultanément ; adaptés (deux Users
  distincts, ou stints séquentiels) plutôt que supprimés — voir
  `TeamMembershipServiceTest`, `TeamMemberNonParticipationServiceTest`,
  `UserAvailabilityServiceTest`, `EligibilityMatrixBuilderTest`. Détail :
  `docs/planning.md` §6.

## D073 — `PlanningLine` reste une entité séparée de `Team`, malgré la relation 1:1 imposée en v1

- **Contexte** : v1 impose `UNIQUE(planning_id, team_id)` — une Team ne
  peut alimenter qu'une seule ligne d'un même Planning (§7 de la
  spécification).
- **Décision** : `PlanningLine` reste une entité à part entière (pas une
  simple relation `Planning ↔ Team`) malgré cette contrainte 1:1 actuelle.
- **Justification** : explicitement demandé — conserver deux entités
  séparées permet de lever cette restriction plus tard (plusieurs lignes
  sur la même Team dans un même Planning) sans refonte du domaine. Une
  fusion `Planning.teams` (ManyToMany) aurait rendu ce changement futur
  bien plus coûteux.
- **Conséquences** : `PlanningLine` porte son propre `name`/`position`/
  `type`/`active`, indépendants de ceux de la Team. Détail :
  `docs/planning.md` §4.

## D074 — Ligne PRIMARY jamais supprimable ; création `Planning`+ligne PRIMARY atomique

- **Contexte** : un Planning doit toujours avoir exactement une ligne
  PRIMARY (§3) ; v1 n'a pas de mécanisme de promotion/rétrogradation.
  Séparément : la création de la ligne PRIMARY peut échouer (ex. Team déjà
  programmée sur cette fenêtre ailleurs, `OverlappingFairnessPeriodException`)
  après que le `Planning` lui-même a déjà été persisté.
- **Décision** : la ligne PRIMARY n'est jamais supprimable
  (`PrimaryPlanningLineNotDeletableException`, 409) — règle la plus simple
  possible pour cette version, explicitement documentée plutôt que de
  construire un mécanisme de promotion non demandé. `PlanningService::create()`
  enveloppe la création du `Planning` et de sa ligne PRIMARY dans
  `EntityManager::wrapInTransaction()` — si la ligne échoue, le `Planning`
  est annulé avec elle, jamais laissé orphelin sans aucune ligne.
- **Alternative écartée** (pour la suppression) : autoriser la suppression
  de la ligne PRIMARY si c'est la dernière ligne du Planning (suppression
  du Planning entier implicite) — écartée : aucune suppression de
  `Planning` n'est demandée dans ce lot, et mélanger les deux aurait été
  une fonctionnalité non demandée construite par anticipation.
- **Conséquences** : testé explicitement
  (`PlanningServiceTest::testCreationIsAtomicWhenThePrimaryLineCannotBeCreated`,
  `PlanningLineServiceTest::testSecondaryLineCanBeDeletedButPrimaryCannot`).
  Détail : `docs/planning.md` §3.

## D075 — Le `PlanningPeriod` d'une `PlanningLine` doit correspondre exactement aux dates du `Planning`

- **Contexte** : la spécification propose, pour cette v1, que
  `Planning.startsAt == PlanningPeriod.startsAt` et `Planning.endsAt ==
  PlanningPeriod.endsAt` pour toutes les lignes, plutôt que des périodes
  différenciées par ligne.
- **Décision** : vérifié directement dans le constructeur de
  `PlanningLine` (`\InvalidArgumentException` sinon), même style que les
  autres invariants cross-entité du domaine (`Duty`, `PlanningPeriod`,
  `DutyGroupInstance`) — jamais seulement documenté.
- **Conséquences** : `PlanningLineService::addLine()` crée toujours un
  nouveau `FairnessPeriod` + `PlanningPeriod` bornés exactement sur la
  fenêtre du Planning pour la Team de la ligne — jamais de réutilisation
  d'une période existante. Si cette Team a déjà un `FairnessPeriod`
  chevauchant cette fenêtre (ex. déjà utilisée par un **autre** Planning
  sur la même période), l'invariant D049 déjà existant s'applique tel
  quel : `OverlappingFairnessPeriodException`, pas une règle nouvelle.
  Détail : `docs/planning.md` §5.

## D076 — Visibilité de `GET /api/plannings` : creator + membres des Teams associées, rien de plus

- **Contexte** : la règle de visibilité n'était pas entièrement définie
  par le besoin fonctionnel ; la spécification demande de choisir la
  variante minimale et de la documenter.
- **Décision** : `creator` → VIEW + MANAGE ; membre courant (n'importe
  quel rôle) d'une Team alimentant une des lignes du Planning → VIEW
  uniquement ; tout autre utilisateur → aucun accès.
  `PlanningRepository::findVisibleTo()` implémente cette règle par une
  jointure DQL vers `PlanningLine`/`TeamMember` plutôt qu'un filtrage en
  PHP après coup.
- **Conséquences** : à réévaluer explicitement dès que le besoin
  fonctionnel précise une politique de partage plus riche (ex.
  collaborateurs, D071) — cette règle n'est pas présentée comme
  définitive. Détail : `docs/planning.md` §10.

## D077 — `PATCH /api/plannings/{stableId}` ne modifie que `name` en v1

- **Contexte** : `Planning.startsAt`/`endsAt` sont utilisés comme source
  de vérité par chaque `PlanningLine` de ce Planning (D075) — les changer
  demanderait de faire cascader la modification sur le `PlanningPeriod` de
  chaque ligne et de revalider chaque `FairnessPeriod` de Team concernée.
- **Décision** : `Planning::rename()` est la seule méthode de mutation
  exposée au PATCH ; `UpdatePlanningRequest` ne porte qu'un champ `name`.
- **Justification** : implémenter la cascade de dates n'est demandé nulle
  part dans ce lot, et le faire "à moitié" (changer le Planning sans
  toucher aux lignes) laisserait immédiatement l'invariant D075 violé pour
  toutes les lignes existantes — pire que ne pas l'implémenter du tout.
- **Conséquences** : dette explicite, pas un oubli — à construire quand le
  besoin réel de replanifier un Planning existant (dates comprises)
  apparaîtra. Détail : `docs/planning.md` §9.

## D078 — `canManage` calculé côté serveur plutôt que d'exposer `User.stableId` sur `/api/me`

- **Contexte** : le frontend minimal (§13) doit savoir si l'utilisateur
  courant peut gérer un Planning (afficher ou non les actions
  creator-only). La comparaison naturelle serait
  `planning.creatorStableId === currentUser.stableId` — sauf que `/api/me`
  (lot authentification) n'expose actuellement que `id` (auto-incrémenté)
  dans le groupe de sérialisation `user:read`, jamais `stableId`.
- **Décision** : plutôt que de modifier `User`/`AccountController` (lot
  authentification, hors périmètre de celui-ci), chaque réponse
  `Planning` porte un booléen `canManage`, calculé côté serveur via le
  même `PlanningVoter::MANAGE` qui protège déjà chaque endpoint
  d'écriture. Le frontend n'a donc jamais besoin de connaître son propre
  `stableId`.
- **Justification** : corriger l'absence de `stableId` sur `/api/me`
  toucherait un lot déjà livré et audité (authentification) pour un
  besoin strictement local à ce lot — une extension ciblée de la réponse
  `Planning` est un changement plus petit, plus sûr, et réutilisable pour
  toute future UI qui a besoin de savoir "que puis-je faire ici", pas
  seulement "qui suis-je".
- **Conséquences** : l'absence de `stableId` sur `/api/me` reste une dette
  connue, indépendante de ce lot — à corriger le jour où un vrai besoin
  frontend de comparaison d'identité apparaît ailleurs qu'ici. Détail :
  `docs/planning.md` §12.

## D079 — `Team` devient `PlanningTeam` : propriété exclusive d'un Planning, créée inline par `PlanningLine`

- **Contexte** : le lot Planning (D071-D078) avait gardé `Team` comme
  entité globale/partagée — une Team préexistante était référencée par
  `stableId` à la création d'un Planning ou d'une PlanningLine. À
  l'usage, cela s'est révélé être la conséquence d'un mauvais modèle :
  l'UI devait faire coller à l'utilisateur un identifiant technique
  (`stableId` de la Team), et rien n'empêchait qu'une même Team soit
  candidate pour plusieurs Plannings simultanément — ce qui n'a aucun
  sens métier (un Planning est un exercice de planification
  autonome ; ses équipes n'ont pas vocation à exister en dehors de lui).
- **Décision** : `Team` est renommée `PlanningTeam` et gagne une relation
  obligatoire `ManyToOne` vers `Planning`. Une `PlanningTeam` n'est plus
  jamais créée de façon autonome par un client : elle est systématiquement
  créée *inline*, dans la même transaction, par
  `PlanningLineService::addLine()` — que ce soit pour la ligne PRIMARY
  (via `PlanningService::create()`) ou une ligne SECONDARY. `POST
  /api/plannings` prend désormais `primaryTeam.name` (un nom, pas un
  identifiant) et `POST /api/plannings/{id}/lines` prend juste `name` —
  aucun des deux n'accepte plus de `teamStableId`/`primaryTeamStableId`.
  Conséquence directe : le conflit "cette Team alimente déjà une ligne de
  ce Planning" (`PlanningTeamAlreadyInUseException`, introduit dans le lot
  précédent) devient structurellement impossible — un client ne peut plus
  jamais référencer une Team existante — et la classe est supprimée.
  `Team.slug` est également supprimé (plus de raison d'être une fois
  qu'une Team n'est plus navigable/adressable en dehors de son Planning),
  et `Team.timezone` est retiré au profit d'une délégation
  `PlanningTeam::getTimezone()` → `$this->planning->getTimezone()` (une
  seule source de vérité, jamais deux colonnes qui peuvent diverger).
- **Alternative écartée** : garder `Team` globale et se contenter
  d'ajouter la contrainte "au plus un Planning à la fois" en base —
  écartée : cela n'aurait pas résolu le vrai problème (l'UI qui demande un
  identifiant technique à l'utilisateur, et la possibilité qu'une Team
  survive sans aucun Planning l'utilisant). La demande explicite était de
  rendre la portée Planning *structurellement vraie en base*, pas
  seulement vérifiée en PHP.
- **Conséquences** : `PlanningLine`, `DutyType`, `DutyPattern`,
  `DutyPatternComponent`, `DutyGroupInstance`, `Duty`, `FairnessPeriod`,
  `PlanningPeriod`, `PlanningRuleSet` gardent tous une propriété `$team`
  (nom inchangé) mais retypée `PlanningTeam` — seul `PlanningLine` change
  aussi le nom de sa propriété (`$team` → `$planningTeam`,
  `getTeam()` → `getPlanningTeam()`), par cohérence avec le fait que son
  invariant de construction compare désormais explicitement
  `$planningTeam->getPlanning() === $planning`. `PlanningSnapshotMember`
  et les entités de snapshot sœurs n'ont nécessité **aucun** changement
  structurel : elles ne référencent déjà l'ancien `TeamMember` que par
  UUID copié (`sourceTeamMemberStableId`), jamais par FK vivante (D061) —
  confirmé par relecture de code avant de commencer ce lot. Nouveaux
  contrôleurs `PlanningTeamController` (liste des PlanningTeams d'un
  Planning) et `PlanningTeamMemberController` (liste/ajout/fin
  d'adhésion) ; `TeamMembersController` (ancien
  `GET /api/teams/{stableId}/members`) est supprimé, remplacé.
  `TeamMemberNonParticipationController` garde son nom mais ses routes
  sont renestées sous `/api/plannings/{planningId}/teams/{teamId}/...`.
  Migration `Version20260917091305` : les 2 Teams et 1
  Planning/PlanningLine de fixtures QA manuelles (aucune donnée réelle)
  n'ont pas pu être portées (une Team de fixture n'avait pas de Planning
  propriétaire à lui attribuer, désormais obligatoire) et ont été
  supprimées par la migration elle-même — voir le rapport de ce lot pour
  la liste exacte à recréer manuellement. Détail : `docs/planning.md`.

## D080 — Adhésion unique par Planning (et non par application), remplace D072

- **Contexte** : D072 imposait qu'un User n'ait au plus qu'une seule
  adhésion (`TeamMember`) ouverte dans **toute l'application**. Une fois
  `Team` scopée à un Planning (D079), cette règle n'a plus de sens : deux
  Plannings sont deux exercices de planification indépendants, et rien ne
  justifie qu'un User ne puisse pas être membre d'une équipe du Planning A
  *et* d'une équipe du Planning B en même temps (par exemple, deux
  services différents qui utilisent chacun leur propre Planning).
- **Décision** : la règle devient "au plus une adhésion ouverte par
  **Planning**" — un User peut tenir des adhésions ouvertes simultanées
  dans des PlanningTeams de Plannings *différents*, mais jamais deux
  adhésions ouvertes dans deux PlanningTeams du *même* Planning à la fois.
  Implémentation : l'index unique partiel devient `(planning_id, user_id)
  WHERE membership_end IS NULL` sur `planning_team_members` (au lieu de
  `(user_id)` seul pour D072, lui-même au lieu de `(team_id, user_id)`
  avant D072). `PlanningTeamMembershipService::addMember()` vérifie
  `PlanningTeamMemberRepository::findOpenMembershipForUserInPlanning(Planning,
  User)`. Même exception métier renommée
  `PlanningTeamMembershipConflictException`, message adapté à la nouvelle
  portée.
- **Alternative écartée** : revenir purement à la règle pré-D072 ("au
  plus une adhésion par Team") — écartée explicitement : elle permettrait
  à un User d'avoir deux adhésions ouvertes simultanées dans deux
  PlanningTeams du *même* Planning, ce qui n'a pas de sens (un membre ne
  peut pas occuper deux lignes de garde différentes du même exercice de
  planification à la fois).
- **Conséquences** : nécessite que la contrainte DB puisse lire
  `planning_id` directement sur `planning_team_members`, alors que la
  seule route naturelle vers cette information passe par
  `planning_team_id` → `planning_teams.planning_id` — voir D081 pour la
  dénormalisation qui rend cela possible sans jointure. 11 scénarios de
  test dédiés (dont les D072-era `TeamMembershipServiceTest`,
  `TeamMemberNonParticipationServiceTest`, `UserAvailabilityServiceTest`,
  `EligibilityMatrixBuilderTest` adaptés pour exercer la nouvelle
  simultanéité inter-Planning plutôt que l'ancien contournement séquentiel
  D072). Détail : `docs/planning.md`.

## D081 — `PlanningTeamMember.planning` dénormalisé + FK composite, plutôt qu'une jointure via `PlanningTeam`

- **Contexte** : l'invariant D080 ("au plus une adhésion ouverte par
  Planning") doit être une contrainte DB forte (philosophie déjà établie
  du projet, cf. D072), pas seulement une vérification applicative. Or un
  simple index partiel sur `planning_team_members` ne peut porter que sur
  ses propres colonnes — et `planning_id` n'est naturellement accessible
  que via une jointure `planning_team_id → planning_teams.planning_id`,
  qu'un index ne peut pas traverser.
- **Décision** : `PlanningTeamMember` porte une colonne `planning_id`
  dénormalisée, en plus de `planning_team_id` — redondante avec
  `planning_team_id → planning_teams.planning_id`, mais dont la
  cohérence est garantie par une clé étrangère composite
  `(planning_team_id, planning_id) RÉFÉRENCE planning_teams(id,
  planning_id)`, laquelle nécessite elle-même une contrainte
  `UNIQUE(id, planning_id)` sur `planning_teams`. Exactement la même
  technique que D051 (déjà établie dans ce domaine pour des invariants
  cross-table équivalents), donc pas une nouveauté architecturale — une
  application de plus du même principe.
- **Alternative écartée** : n'enforcer l'unicité qu'au niveau applicatif
  (`PlanningTeamMembershipService::addMember()` seul) — écartée pour la
  même raison que D072 : la spécification de ce lot demande explicitement
  une contrainte DB forte, et une race condition entre deux requêtes
  concurrentes resterait possible sans elle.
- **Conséquences** : comme pour toutes les FK composites de ce domaine
  (D051), `doctrine:migrations:diff` propose systématiquement de la
  supprimer/recréer à chaque exécution future (elle n'est pas
  représentable dans le mapping ORM) — bruit connu et documenté, à élaguer
  manuellement à chaque nouvelle migration touchant une table adjacente,
  jamais à appliquer tel quel. `PlanningRepository::findVisibleTo()`
  bénéficie de cette dénormalisation : la vérification "User membre d'une
  PlanningTeam de ce Planning" devient une jointure directe sur
  `tm.planning = p`, sans plus jamais devoir passer par `PlanningLine`.
  Détail : `docs/planning.md`.

## D082 — Candidat de fairness = `sourceUserStableId`, jamais le stint `sourceTeamMemberStableId`

- **Contexte** : Lot 5 (fairness), Audit A obligatoire avant implémentation.
  `EligibilityMatrix`/`EligibilityResult` sont clés par
  `sourceTeamMemberStableId` — un **stint** de membership
  (`PlanningTeamMember`), pas une personne. Or
  `PlanningTeamMemberRepository::findIntersecting()` capture tout stint
  chevauchant la fenêtre du `PlanningPeriod` : un même `User` parti puis
  revenu dans la même équipe pendant cette fenêtre produit **deux**
  `PlanningSnapshotMember` distincts dans un même snapshot (deux
  `sourceTeamMemberStableId` différents, un seul `sourceUserStableId`
  commun).
- **Décision** : `FairnessContext`/`FairnessContextBuilder` regroupent les
  membres du snapshot par `sourceUserStableId` — `FairnessCandidate` porte
  un `sourceUserStableId` et la **liste** de tous les stints
  (`PlanningSnapshotMember`) de cette personne dans ce snapshot. Toutes les
  quantités par candidat (`effectiveExposure`, `grossTargets`,
  `discretionaryTargets`, `structurallyForcedLoad`) sont indexées par
  `sourceUserStableId`, jamais par stint. `EffectiveExposureService` lit,
  pour chaque Duty, le stint (au plus un, les stints d'un même
  `PlanningTeamMember` ne se chevauchant jamais dans le temps) qui couvre
  sa `localDate` via `FairnessCandidate::stintCovering()`.
- **Cas particulier — `StructurallyForcedAnalyzer`** : opère lui à la
  granularité native de la matrice (le stint), parce que c'est la
  granularité réelle d'une future affectation (`DutyAssignment.teamMember`
  référence un `PlanningTeamMember`, jamais un `User` directement) — mais
  `StructurallyForcedUnit` ne retient que le `sourceUserStableId` du stint
  gagnant, pour que `structurallyForcedLoad` puisse ensuite s'agréger à la
  même granularité User que le reste du `FairnessContext`.
- **Alternative écartée** : garder le candidat au niveau stint (comme
  `EligibilityMatrix`) — écartée : un même User apparaîtrait comme deux
  "personnes" distinctes dans la distribution des targets, recevant chacun
  une part disproportionnée au lieu d'une part cohérente par personne
  réelle — exactement le double-comptage que l'audit devait empêcher.
- **Conséquences** : `FairnessContextBuilderTest::testMultipleHistoricalStintsOfTheSameUserAreCountedAsOneCandidate`
  vérifie explicitement l'absence de double-comptage. Détail :
  `docs/fairness.md`.

## D083 — `DutyGroupInstance` ne peut jamais mélanger `REQUIRED` et `OPTIONAL`

- **Contexte** : Lot 5, Audit B obligatoire avant implémentation. Un
  `DutyGroupUnit` doit être classifiable de façon cohérente comme
  `requiredDutyUnit` ou `optionalDutyUnit` (atomique pour l'affectation,
  docs/allocation-algorithm.md §9). Lecture du code :
  `DutyMaterializationService::materializeGroup()` applique un seul
  `$demandType` à toutes les Duties d'un groupe — via le seul chemin
  applicatif réel, un groupe mixte est aujourd'hui impossible. Mais rien
  n'empêchait `Duty` d'être construite directement (hors service) avec un
  `demandType` différent de ses frères déjà dans le même
  `DutyGroupInstance`.
- **Décision** : ajout d'une garde dans le constructeur de `Duty` —
  si `$groupInstance` est fourni et contient déjà des Duties, le nouveau
  `$demandType` doit être identique à celui du premier ; sinon
  `\InvalidArgumentException`, même style que les deux invariants
  cross-entité déjà présents dans ce même constructeur.
  `DutyGroupUnit::isRequired()` vérifie la même homogénéité par défense en
  profondeur (jamais un pick arbitraire) et lève `\LogicException` si
  violée — jamais atteint en pratique grâce à la garde ci-dessus, mais na
  masque pas le problème si l'invariant venait à être contourné plus tard.
- **Alternative écartée** : ignorer le problème puisqu'il n'est pas
  atteignable aujourd'hui par l'application réelle — écartée : la
  spécification demande explicitement de ne pas masquer un problème
  structurel par une convention arbitraire, même quand le chemin
  applicatif actuel le rend improbable.
- **Conséquences** : aucune migration — invariant PHP uniquement, aucune
  contrainte DB ajoutée (`demandType` par Duty individuelle reste la
  source de vérité stockée, la cohérence de groupe est une règle de
  construction, pas une contrainte de colonne). Testé directement dans
  `tests/Entity/DutyTest.php` (construction directe, bypassant le
  service). Détail : `docs/fairness.md`.

## D084 — `effectiveExposure` porte sur toutes les Duties, jamais restreinte aux REQUIRED

- **Contexte** : Lot 5. `docs/allocation-algorithm.md` §5 qualifie
  explicitement `requiredDemand` de "depuis les Duty REQUIRED", mais ne
  qualifie **pas** la formule d'`effectiveExposure`
  (`Σ_{duty ∈ d} structuralOpportunity(user, duty) × participationFactor(...)`)
  de la même restriction — asymétrie textuelle potentiellement
  intentionnelle ou potentiellement un oubli, jamais tranchée
  explicitement avant ce lot.
- **Décision** : `EffectiveExposureService` somme sur **toutes** les
  Duties de la `PlanningPeriod` (REQUIRED et OPTIONAL), lecture littérale
  de la formule du §5 — `structuralOpportunity(user, duty)` elle-même est
  définie comme "candidat à `duty`", jamais "candidat à une duty requise".
  L'exposition structurelle mesure la présence structurelle d'une personne
  dans le planning, indépendante de la classification REQUIRED/OPTIONAL
  d'un exercice de staffing donné.
- **Alternative écartée** : restreindre `effectiveExposure` aux seules
  Duties REQUIRED, par symétrie avec `requiredDemand` — séduisante car
  elle évite qu'un fort volume de Duties OPTIONAL ne fasse gonfler
  artificiellement la part d'exposition d'un candidat par rapport à un
  autre ayant une exposition REQUIRED identique mais moins de Duties
  OPTIONAL disponibles. Écartée pour ce lot car ce serait une restriction
  que le texte de la spécification n'énonce pas explicitement — mais
  **explicitement signalée ici comme un point à revalider** avec un
  référent métier avant que le futur solveur ne consomme réellement
  `discretionaryTargetAtSolve` en pratique : l'invariant
  `Σ target = requiredDemand` reste vrai mathématiquement quel que soit ce
  choix (c'est une pure répartition proportionnelle), mais la
  *proportionnalité elle-même* peut se retrouver faussée par des Duties
  OPTIONAL très inégalement réparties entre candidats.
- **Conséquences** : dette de clarification explicite, pas un oubli — à
  trancher avec un référent métier avant le lot solveur. Détail :
  `docs/fairness.md`.

## D085 — `discretionaryTargetAtSolve = max(0, grossTarget − structurallyForcedLoad)`

- **Contexte** : Lot 5. `docs/allocation-algorithm.md` §4.4 définit
  `discretionaryLoadAtSolve(user,d) = raw(user,d) − structurallyForcedLoad(user,d)`
  (côté **charge**, post-solve) et §6 utilise
  `deviation(user,d) = discretionaryLoadAtSolve(user,d) − discretionaryTarget(user,d)`
  — mais aucune formule explicite pour `discretionaryTarget`/
  `discretionaryTargetAtSolve` (côté **cible**, pré-solve) n'apparaît nulle
  part dans le document, alors que §21 l'utilise déjà comme champ du
  contrat abstrait `OptimizationProblem.fairnessTargets`.
- **Décision** : `discretionaryTargetAtSolve(user, d) = max(0, grossTarget(user, d) − structurallyForcedLoad(user, d))`,
  implémentée dans `FairnessTargetService::buildDiscretionaryTargets()`.
- **Justification** : c'est la seule lecture qui garde la formule de
  `deviation` du §6 interne cohérente. Sans cette soustraction, un
  candidat dont toute la charge requise serait structurellement forcée
  (`raw = structurallyForcedLoad` ⇒ `discretionaryLoadAtSolve = 0`) tout
  en gardant un `grossTarget` intact afficherait une `deviation`
  fortement négative purement artificielle — un signal de
  "sous-servi" fallacieux que le solveur cherckerait à corriger en lui
  donnant *plus* de gardes discrétionnaires, alors qu'il a déjà reçu sa
  part équitable (juste toute forcée). Le raisonnement du §4.4
  ("une garde forcée cette année n'est jamais comptée comme un choix qui
  justifierait d'en donner moins") s'applique symétriquement côté cible :
  une garde forcée ne doit pas non plus laisser intacte toute la cible
  discrétionnaire qu'elle a déjà en partie remplie.
- **Alternative écartée** : ne pas soustraire (`discretionaryTargetAtSolve
  = grossTarget`) — écartée pour la raison ci-dessus (déviation faussée).
  Une autre alternative, plafonner à `min(grossTarget, structurallyForcedLoad)`
  plutôt que soustraire, a été écartée car elle perdrait l'information de
  surplus/déficit au-delà du forcé — `max(0, ...)` est la formulation la
  plus simple qui préserve exactement la sémantique "ce qui reste dû, au
  minimum zéro".
- **Conséquences** : `docs/allocation-algorithm.md` reste tel quel (la
  formule manquante y sera ajoutée à l'occasion d'une prochaine révision
  de ce document vivant, jamais silencieusement laissée diverger du code —
  voir CLAUDE.md) ; ce journal fait foi entre-temps. Testé explicitement
  (`FairnessTargetServiceTest::testDiscretionaryTargetSubtractsStructurallyForcedLoadAndNeverGoesNegative`).
  Détail : `docs/fairness.md`.

## D086 — Classification PRIMARY/SECONDARY des dimensions : composant dédié swappable, PRIMARY vide aujourd'hui

- **Contexte** : Lot 6A (`docs/planning-solver.md`), audit avant
  implémentation. Les phases 1-4 de GENERATE (`docs/allocation-algorithm.md`
  §11) ont besoin de savoir, pour un ensemble de dimensions données,
  lesquelles sont PRIMARY et lesquelles sont SECONDARY. Recherche dans le
  code existant : ni `FairnessContext` (Lot 5) ni `PlanningRuleSet`
  (`configuration: array<string, mixed>` JSON libre, sans schéma documenté)
  ne portent aujourd'hui une telle classification — aucune source de
  vérité à réutiliser, contrairement à ce que l'instruction du lot
  espérait trouver.
- **Audit de suivi** : une fois le lot livré, l'écart a été re-questionné
  explicitement : "l'absence de schéma `PlanningRuleSet` ne doit pas
  entraîner un PRIMARY vide si la spécification a déjà un défaut métier
  explicite". Vérification faite : **la spécification définit bien, à
  terme, `WEEKEND_GROUPS` et `NAMED_HOLIDAY` comme dimensions PRIMARY par
  défaut** (`docs/allocation-algorithm.md` §6) — ce n'est pas une lacune de
  configuration par équipe qui empêche de l'appliquer aujourd'hui, c'est
  que **`WEEKEND_GROUPS` et `NAMED_HOLIDAY` ne sont pas implémentées dans
  le modèle réel de fairness** : ni `FairnessDimensionType`, ni
  `FairnessDimensionKey`, ni `requiredDemand`, ni `effectiveExposure`, ni
  `dimensionMembership` ne les couvrent (`docs/fairness.md` §3 — aucun
  `HolidayDefinition`, aucune classification de groupe week-end). Question
  posée explicitement à l'utilisateur (deux options : garder PRIMARY vide,
  ou ajouter les deux dimensions comme identités de classification pures
  sans donnée réelle derrière) — réponse : **garder PRIMARY vide**, ne
  jamais ajouter une dimension de classification sans sa chaîne de calcul
  réelle derrière.
- **Décision (confirmée)** : `DefaultFairnessDimensionClassifier::primaryDimensions()`
  continue de renvoyer une liste vide, pour tout ensemble de dimensions en
  entrée, tant que `WEEKEND_GROUPS`/`NAMED_HOLIDAY` n'existent pas comme
  dimensions réellement calculées. `secondaryDimensions()` continue de
  classer SECONDARY toutes les dimensions actuellement supportées
  (`TOTAL_DUTIES`, `WEIGHTED_WORKLOAD`, `FRIDAY`, `SATURDAY`, `SUNDAY`,
  `DUTY_TYPE`). Résultat assumé et testé
  (`ObjectivePhaseFactoryTest`, `DefaultFairnessDimensionClassifierTest`),
  jamais un bug : `weightedWorkload`/`totalDuties` ne peuvent
  structurellement jamais devenir PRIMARY par accident.
- **Ce que ça signifie concrètement** :
  - La classification par équipe (lire un futur `PlanningRuleSet.configuration`
    structuré) **n'est pas encore implémentée** — `FairnessDimensionClassifier`
    reste une interface à une seule implémentation aujourd'hui.
  - Le défaut métier réel est bien `WEEKEND_GROUPS` + `NAMED_HOLIDAY`
    (`docs/allocation-algorithm.md` §6) — **ce n'est pas une constante
    magique inventée pour ce lot**, c'est la spécification elle-même ;
    seule son *application* est bloquée par l'absence des deux dimensions
    dans le modèle réel.
  - `DefaultFairnessDimensionClassifier::primaryDimensions()` retourne
    donc volontairement un ensemble vide **pour l'instant**, pas par choix
    de conception indépendant de la spécification.
  - Toutes les dimensions réellement supportées aujourd'hui restent
    classées SECONDARY.
- **Alternative écartée** (les deux, revues explicitement) :
  1. Lire une clé dédiée dans `PlanningRuleSet.configuration` — écartée,
     cette clé n'a aucun schéma documenté ni lecteur aujourd'hui.
  2. Ajouter `WEEKEND_GROUPS`/`NAMED_HOLIDAY` comme cas de
     `FairnessDimensionType` **seulement** pour la classification, sans
     `requiredDemand`/`effectiveExposure`/`dimensionMembership` réels
     derrière — écartée explicitement par l'utilisateur : une dimension de
     classification sans chaîne de calcul réelle romprait l'invariant
     "jamais une dimension sans source de donnée réelle" tenu depuis le
     Lot 4/5, et ne pourrait de toute façon jamais apparaître dans un
     `OptimizationProblem` réel (`getSupportedDimensions()` ne la
     produirait jamais).
- **Chemin pour un futur lot qui implémenterait réellement `WEEKEND_GROUPS`/
  `NAMED_HOLIDAY`** — dans cet ordre, jamais en commençant par le
  classifier : (1) `FairnessDimensionType` (nouveaux cas) ; (2)
  `FairnessDimensionKey` (nouvelles fabriques statiques) ; (3)
  `RequiredDemandBuilder` ; (4) `EffectiveExposureService` si la dimension
  a un poids par Duty distinct à sommer ; (5)
  `DimensionMembershipCalculator` ; (6)
  `FairnessContextBuilder::deriveSupportedDimensions()` ; (7) **seulement
  alors** `DefaultFairnessDimensionClassifier::primaryDimensions()`. Le
  classifier est la dernière pièce à toucher, jamais la première.
- **Conséquences** : PRIMARY réellement vide dans toute construction
  actuelle d'`OptimizationProblem` — les phases 1-2 (max/sum PRIMARY)
  existent structurellement (8 phases toujours) mais portent une liste de
  dimensions vide tant qu'aucune dimension PRIMARY réelle n'existe. Tests
  explicites ajoutés pour verrouiller ce comportement : PRIMARY vide
  aujourd'hui, toutes les dimensions supportées classées SECONDARY,
  PRIMARY et SECONDARY disjoints, aucune dimension absente de l'entrée
  n'est jamais inventée par le classifier. Détail : `docs/planning-solver.md`.

## D087 — 8 phases GENERATE explicites, SECONDARY jamais fusionnée max/sum en une seule phase

- **Contexte** : `docs/allocation-algorithm.md` §11 liste, dans sa forme
  actuelle, "Phase 4 : minimize maxDeviation(SECONDARY) puis
  sumDeviation(SECONDARY)" comme une seule ligne — une écriture compacte
  ambiguë sur le nombre réel de phases lexicographiques distinctes,
  d'autant que le paragraphe qui suit immédiatement précise explicitement
  "le passage min-max-puis-somme s'applique à PRIMARY **et** SECONDARY
  (pas seulement PRIMARY)", ce qui n'a de sens que si ce sont deux phases
  strictement ordonnées, pas une seule.
- **Décision** : `ObjectivePhaseFactory` matérialise sans ambiguïté 8
  phases pour GENERATE : `maxDeviationPrimary`, `sumDeviationPrimary`,
  `maxDeviationSecondary`, `sumDeviationSecondary`,
  `namedHolidayRepetitionPenalty`, `spacingScore`,
  `preferenceSatisfaction`, `deterministicTieBreak` — jamais fusionnées,
  chacune une entrée distincte et ordonnée de la liste retournée par
  `ObjectivePhaseFactory::forMode()`. C'est la lecture qui rend le
  paragraphe explicatif du §11 cohérent avec sa propre liste numérotée.
- **Alternative écartée** : une seule phase "SECONDARY" combinant max et
  somme en interne (deux sous-étapes non observables depuis le contrat
  abstrait) — écartée car elle rendrait impossible pour un futur solveur
  de figer la valeur optimale de `maxDeviation(SECONDARY)` comme contrainte
  avant d'attaquer `sumDeviation(SECONDARY)` (la préservation lexicographique
  du §8 de l'instruction du lot l'exige explicitement), et contredirait le
  texte même du §11 cité ci-dessus.
- **Conséquences** : `docs/allocation-algorithm.md` §11 n'a pas été
  réécrit (pas de refonte de la spécification existante) — une note de
  statut d'implémentation y renvoie vers cette décision pour la
  clarification. Détail : `docs/planning-solver.md`.

## D088 — Tie-break : direction MINIMIZE conventionnelle, pas de matière de seed réelle

- **Contexte** : `docs/allocation-algorithm.md` §13 définit
  `tieBreakKey = StableHash(seedMaterial + dutyStableKey + candidateStableKey)`
  mais ne précise aucune direction MINIMIZE/MAXIMIZE — un tie-break n'a pas
  de sens "plus grand est meilleur" intrinsèque, seulement un ordre total
  arbitraire mais fixe. Par ailleurs, `seedMaterial` requiert
  `snapshotHash`/`algorithmVersion`/`solverParameterSetVersion` —
  `docs/allocation-algorithm.md` §14 confirme qu'aucun de ces trois
  n'existe encore nulle part dans le code (pas de `SolverParameterSet`, pas
  de hash canonique de snapshot).
- **Décision** : `ObjectivePhase::deterministicTieBreak()` fixe la
  direction à `MINIMIZE` — convention documentée ("préférer le plus petit
  `tieBreakKey`"), jamais présentée comme une exigence de la spécification
  elle-même. Aucune matière de seed réelle n'est construite dans ce lot :
  la phase reste une identité structurelle pure (`ObjectivePhaseId::DETERMINISTIC_TIE_BREAK`,
  aucune dimension, aucun paramètre), en attendant que
  `snapshotHash`/`SolverParameterSet` existent réellement.
- **Alternative écartée** : inventer un `seedMaterial` provisoire à partir
  des seuls identifiants aujourd'hui disponibles (ex. `PlanningPeriod.stableId`
  seul) — écartée explicitement : la spécification du lot demande de
  documenter le manque plutôt que de fabriquer une valeur, et un seed
  partiel non conforme à la formule du §13 serait pire qu'une absence
  claire (risque qu'un futur lot le prenne par erreur pour la vraie
  formule).
- **Conséquences** : dette explicite pour un futur lot —
  `seedMaterial`/`tieBreakKey` restent à implémenter une fois
  `snapshotHash` et `SolverParameterSet` réellement modélisés. Détail :
  `docs/planning-solver.md`.

## D089 — `FEASIBLE` sur une phase non finale → arrêt de la chaîne lexicographique

- **Contexte** : Lot 6B, exécution lexicographique réelle
  (`OrToolsPlanningSolver`). Aucun document existant (`docs/allocation-algorithm.md`
  §10, §11, §16) ne tranche explicitement le comportement à adopter quand
  CP-SAT retourne `FEASIBLE` (solution trouvée, optimalité **non**
  prouvée) sur une phase qui n'est pas la dernière de la chaîne. Le §10 ne
  couvre que la politique produit sur le résultat **final** d'un solve
  strict/partial (`FEASIBLE + COMPLETE` → validation admin explicite) —
  pas le cas intermédiaire d'une phase lexicographique.
- **Décision** : dès qu'une phase non neutre retourne `FEASIBLE`, la
  chaîne lexicographique s'arrête immédiatement. Le statut global du
  solve devient `FEASIBLE`, les phases restantes ne sont jamais
  attentées (absentes de `objectiveValues`/`optimality`, jamais une
  valeur `0.0`/`false` fabriquée), et surtout **aucune contrainte de
  verrouillage n'est ajoutée** pour la valeur non prouvée de cette
  phase — verrouiller une valeur non prouvée optimale aurait pu exclure
  de meilleures solutions qu'un temps de calcul plus long aurait trouvées.
- **Alternative écartée** : traiter `FEASIBLE` comme `OPTIMAL` et
  continuer la chaîne (verrouiller la valeur trouvée et enchaîner) —
  écartée explicitement : dans une optimisation lexicographique stricte,
  prétendre figer un optimum non prouvé contredit la sémantique même de
  "lexicographique" (chaque phase doit céder à la suivante l'espace de
  solutions *réellement* optimal, pas une approximation).
- **Conséquences** : ce lot ne configure aucun budget de temps
  (`docs/decisions.md` D093), donc `FEASIBLE` ne devrait apparaître que
  sur des problèmes de taille significative — non exercé par les tests de
  ce lot (tous de petite taille, toujours `OPTIMAL`/`UNSATISFIABLE`), mais
  le chemin de code existe et est explicitement documenté ici. Détail :
  `docs/planning-solver.md` §Lexicographique.

## D090 — `fixedAssignments` confirmé absent d'`OptimizationProblem`, jamais fabriqué par l'adapter

- **Contexte** : Lot 6B, audit obligatoire avant code. La spécification du
  lot demande d'implémenter la prise en compte de `fixedAssignments` (§7)
  en présumant que ce champ existe sur `OptimizationProblem`. Audit du
  code réel (Lot 5, `docs/fairness.md` §10) : **il n'existe pas** —
  `DutyAssignment.locked` existe bien en persistance depuis le Lot 3, mais
  aucun flux applicatif ne le renseigne jamais (toujours `false`), et le
  Lot 5 avait explicitement choisi de ne pas ajouter `fixedAssignments`
  tant qu'aucun concept de verrouillage non ambigu n'existe pour une
  génération.
- **Décision** : Lot 6B **n'ajoute pas** `fixedAssignments` à
  `OptimizationProblem`. `CpSatPayloadBuilder` envoie systématiquement
  `excludedEdges: []` vide dans le payload de solve (jamais utilisé pour
  des verrouillages — ce nom de clé JSON est réutilisé uniquement par
  `checkFeasibility()` pour les edges explicitement exclues par
  l'appelant, un concept différent). `OrToolsPlanningSolver` ne contient
  donc aucune logique de verrouillage `x[d][c]=1` dans ce lot — la
  section 7 de la spécification du lot reste non implémentée,
  explicitement, faute de donnée réelle.
- **Alternative écartée** : dériver `fixedAssignments` depuis les lignes
  `DutyAssignment` où `locked=true` pour la génération courante — écartée
  car aucun flux applicatif ne peut aujourd'hui produire une telle ligne
  (toujours un ensemble vide en pratique), et l'ajouter maintenant
  élargirait le scope du Lot 6B (nouvelle requête repository, nouvelle
  logique d'agrégation dans `OptimizationProblemBuilder`, gestion de la
  cohérence groupe/lock comme D083) sans besoin démontré. Le mécanisme
  d'exclusion de la spécification du lot ("comportement explicite et
  testé si le fixedAssignment référence une paire absente/impossible")
  est donc sans objet — il n'existe littéralement aucune façon de
  construire un `fixedAssignment` dans ce lot.
- **Conséquences** : le futur adapter/lot qui ajoutera un vrai concept de
  verrouillage devra étendre `OptimizationProblem` (nouveau champ),
  `OptimizationProblemBuilder` (le peupler depuis une vraie source), et
  seulement alors `CpSatPayloadBuilder`/`cp_sat_solver.py`
  (`model.Add(x[u][c] == 1)` + interdiction des autres candidats sur cette
  unité). Détail : `docs/planning-solver.md` §Hors périmètre.

## D091 — `PlanningSolver::checkFeasibility()` retourne `SolverStatus`, jamais `bool`

- **Contexte** : Lot 6B §22. Le contrat `PlanningSolver` du Lot 6A
  (`docs/planning-solver.md`) déclarait `checkFeasibility(): bool`. Une
  vérification de faisabilité réelle via CP-SAT peut légitimement
  retourner `UNKNOWN` (budget épuisé avant preuve) ou échouer
  techniquement (`ERROR`) — deux résultats qu'un simple `bool` ne peut
  représenter sans mentir : les coder en `false` laisserait croire à une
  infaisabilité *prouvée*, ce que le principe déjà posé par `SolverStatus`
  (`docs/decisions.md`, Lot 6A) interdit explicitement ailleurs dans ce
  même contrat.
- **Décision** : élargissement du contrat — `checkFeasibility(OptimizationProblem,
  array $excludedEdges): SolverStatus`, réutilisant l'enum déjà existant
  plutôt que d'en créer un nouveau. `OPTIMAL`/`FEASIBLE` signifient tous
  deux "une solution existe" (cet appel ne porte aucun objectif, donc la
  distinction OPTIMAL/FEASIBLE de CP-SAT est sans objet ici) ;
  `UNSATISFIABLE` signifie prouvé infaisable ; `UNKNOWN`/`ERROR` gardent
  exactement leur sens habituel. Un appelant qui veut un booléen peut
  toujours écrire `in_array($status, [OPTIMAL, FEASIBLE], true)`
  lui-même — jamais cette interface.
- **Alternative écartée** : garder `bool` et lever une exception pour
  `UNKNOWN`/`ERROR` — écartée : forcerait tout appelant à un `try/catch`
  pour un résultat parfaitement normal et attendu (un budget de calcul
  épuisé n'est pas une erreur de programmation), et casserait le
  parallélisme avec `solve()` qui traite déjà ces cas comme des valeurs de
  retour normales, jamais des exceptions.
- **Conséquences** : `FakePlanningSolver`/`FakePlanningSolverTest` (Lot
  6A) mis à jour en conséquence — c'est un élargissement de contrat fait
  avant toute release réelle du Lot 6A (aucun consommateur externe
  n'existait), donc sans code mort ni compatibilité descendante à
  maintenir. Détail : `docs/planning-solver.md` §`checkFeasibility()`.

## D092 — Échelle entière CP-SAT `SCALE = 10 000`, auditée sur la précision réelle de `workloadValue`

- **Contexte** : Lot 6B §10. CP-SAT n'accepte que des coefficients
  entiers ; les targets/déviations de fairness sont fractionnaires
  (`docs/allocation-algorithm.md` §5/§6). La spécification interdit
  explicitement de choisir arbitrairement un facteur d'échelle sans
  auditer la précision réelle des données existantes.
- **Décision** : `CpSatScale::SCALE = 10_000`, dérivé de
  `DutyType.workloadValue`, la seule quantité fractionnaire réelle du
  domaine aujourd'hui — stockée `decimal(6,2)` (`src/Entity/DutyType.php`),
  donc de précision réelle `0.01`. 10 000 est le plus petit multiple
  rond de 100 (`0.01 × 10 000 = 100`, un entier exact, jamais un artefact
  d'arrondi). Cette valeur a été dérivée indépendamment de la donnée
  réelle, et coïncide avec l'exemple illustratif "×10 000" de
  `docs/allocation-algorithm.md` §22 sans avoir été copiée dessus.
  `smallestUnit(d)` (§5) vaut `1.0` pour les dimensions comptées en
  gardes entières, `0.01` pour `WEIGHTED_WORKLOAD`.
- **Alternative écartée** : reprendre tel quel l'exemple "×10 000" du §22
  sans audit — explicitement interdit par la spécification du lot ; fait
  ici avec un audit réel qui confirme indépendamment la même valeur.
  Une échelle plus fine (ex. ×1 000 000) a aussi été envisagée puis
  écartée : aucune donnée du domaine n'a de précision plus fine que 0.01,
  une échelle plus fine n'ajouterait aucune précision réelle, seulement un
  risque accru de dépassement sur de grands problèmes.
- **Conséquences** : tout coefficient CP-SAT est arrondi une seule fois,
  dans `CpSatPayloadBuilder` (PHP), jamais dans `cp_sat_solver.py` — testé
  explicitement (`CpSatScaleTest`). Détail : `docs/planning-solver.md`
  §Scaling.

## D093 — Aucun timeout CP-SAT par défaut — gap documenté plutôt qu'une constante cachée

- **Contexte** : Lot 6B §23. Un vrai budget de temps
  (`OptimizationProblem.timeoutBudget`) n'existe pas — Lot 5 l'a
  explicitement exclu (`docs/fairness.md` §10), faute de source réelle.
  La spécification du lot interdit explicitement de choisir
  silencieusement une constante (5s/30s/60s) dans `OrToolsPlanningSolver`.
- **Décision** : `OrToolsPlanningSolver` ne configure **aucun** timeout —
  ni sur le `Symfony\Process` (`setTimeout(null)` explicite, pour éviter
  le timeout par défaut de 60s de Symfony, qui serait tout aussi arbitraire
  que les valeurs interdites), ni sur les paramètres CP-SAT
  (`max_time_in_seconds` jamais renseigné). Un solve tourne donc jusqu'à
  une conclusion réelle (`OPTIMAL`/`UNSATISFIABLE`) ou jusqu'à ce que
  CP-SAT lui-même conclue `UNKNOWN` pour une autre raison.
- **Alternative écartée** : choisir une valeur par défaut "raisonnable"
  (ex. 30s) — explicitement écartée par la spécification elle-même,
  confirmée dans ce lot : aucune donnée réelle ne justifie un chiffre
  plutôt qu'un autre, et un mauvais choix serait pire qu'une absence
  assumée (soit trop court pour un vrai planning, soit inutilement long
  pour un petit problème).
- **Conséquences** : **risque de production réel et assumé** — un
  problème suffisamment grand/mal contraint pourrait faire tourner le
  solveur indéfiniment, bloquant la requête HTTP qui l'a déclenché. Ce
  lot ne branche justement aucune requête HTTP réelle sur le solveur
  (§27 de la spécification du lot, `docs/planning-solver.md` §Hors
  périmètre) — ce risque devient bloquant seulement quand un futur lot
  orchestre un vrai appel `PlanningGeneration → solve`, et devra alors
  résoudre ce gap avant, pas après, de brancher cet appel. Détail :
  `docs/planning-solver.md` §Timeouts.

## D094 — Orchestration STRICT → PARTIAL dans `OrToolsPlanningSolver::solve()`, jamais un orchestrateur séparé

- **Contexte** : Lot 6C, §17 de la spécification du lot demande
  explicitement d'auditer où placer la politique "STRICT UNSAT → lancer
  PARTIAL" (`docs/allocation-algorithm.md` §10), en évitant à la fois de
  l'enfouir dans `cp_sat_solver.py` (Python doit rester un adapter
  solveur) et de construire "un pipeline massif prématuré".
- **Décision** : la cascade reste entièrement dans
  `OrToolsPlanningSolver::solve()` (factorisé en méthodes privées
  `solvePartial()`/`buildResult()`/`errorResult()`), jamais dans une
  classe orchestratrice séparée au-dessus de `PlanningSolver`. Deux
  arguments l'emportent : (1) `OptimizationResult` porte déjà
  `strictSolverStatus` **et** `partialSolverStatus` depuis le Lot 5/6A,
  avant même que ce lot existe — preuve que le contrat a toujours
  supposé qu'un seul appel `solve()` puisse produire les deux tentatives
  et un résultat combiné ; (2) "PARTIAL" n'est pas un objet métier mais
  une transformation de modèle solveur (`unassigned[d]` slack) de ce même
  `OptimizationProblem` — chaque implémentation de `PlanningSolver` sait
  construire "sa" version PARTIAL à sa manière (CP-SAT ici), donc cette
  construction appartient structurellement à l'adapter qui sait déjà
  construire STRICT, pas à une couche générique au-dessus qui devrait
  deviner comment.
- **Alternative écartée** : une classe `PlanningSolverOrchestrator`
  généraliste, indépendante du solveur, appelant `solve()` deux fois et
  fusionnant les deux `OptimizationResult` — écartée : `OptimizationResult`
  n'est pas conçu pour être fusionné après coup (ses champs représentent
  déjà un résultat combiné) et une telle classe devrait de toute façon
  déléguer la construction du problème PARTIAL à l'adapter, la rendant
  vide de logique réelle — exactement le "pipeline massif prématuré" à
  éviter.
- **Conséquences** : `UnsatDiagnosticsBuilder`, lui, est bien une classe
  séparée dans `src/Service/` — parce que construire le diagnostic
  (lecture d'`EligibilityMatrix`/`CoveragePolicy`) ne dépend d'aucune
  donnée CP-SAT, seulement d'une liste de clés unassigned déjà décidées,
  donc réutilisable par un futur solveur alternatif sans dupliquer cette
  logique. Détail : `docs/planning-solver.md` §Orchestration.

## D095 — Un candidat éligible mais non sélectionné ne reçoit jamais de fausse raison d'exclusion

- **Contexte** : Lot 6C §9, règle d'honnêteté explicite reprise de
  `docs/allocation-algorithm.md` §17 : "la sélection parmi les candidats
  éligibles n'est jamais formulée par comparaison locale". Un candidat
  réellement `eligible` pour une garde non attribuée (parce que
  structurellement une autre garde a absorbé le problème, ou simplement
  parce que la garde reste non couverte pour une autre raison) ne doit
  jamais apparaître comme "exclu".
- **Décision** : `UnsatDiagnosticsBuilder::buildCandidateExclusions()` ne
  construit un `CandidateExclusionDiagnostic` que pour un candidat dont
  `EligibilityResult::$eligible === false`, en réutilisant directement
  `EligibilityResult::$exclusions` — jamais une raison inventée. Un
  candidat éligible est simplement absent de `candidateExclusions`,
  silence correct plutôt qu'une fausse causalité.
- **Alternative écartée** : lister tous les candidats évalués avec une
  liste de raisons vide pour les éligibles — écartée : ajouterait du bruit
  sans information (un "candidateExclusions: []" par candidat éligible ne
  dit rien qu'une absence ne dise déjà, plus simplement).
- **Conséquences** : testé explicitement
  (`UnsatDiagnosticsBuilderTest::testEligibleButUnselectedCandidateNeverGetsAFabricatedExclusion`).
  Détail : `docs/planning-solver.md` §Diagnostics.

## D096 — `solverAnalysis.available = false` dans ce lot — aucune assumption literal câblée

- **Contexte** : Lot 6C §16, audit explicitement demandé de ce que
  CP-SAT permet réellement d'exposer (cœur d'infaisabilité natif via
  `SufficientAssumptionsForInfeasibility()`). Cette API de CP-SAT exige
  que les contraintes du modèle soient construites avec des littéraux
  d'assumption (`model.AddAssumptions([...])`) — `bin/cp_sat_solver.py`
  construit aujourd'hui ses contraintes directement
  (`AddExactlyOne`/`AddAtMostOne`/somme + `unassigned[d]`), sans aucun
  littéral d'assumption attaché.
- **Décision** : `SolverAnalysis::unavailable()` (`available=false,
  infeasibleCore=null`) dans tous les cas ce lot — jamais un cœur
  d'infaisabilité simulé ou partiel. Le diagnostic local déterministe
  (`structuralDiagnostics`/`candidateExclusions`) reste complet et valide
  sans lui, exactement comme le permet
  `docs/allocation-algorithm.md` §16.
- **Alternative écartée** : restructurer `build_model()` pour attacher un
  littéral d'assumption à chaque contrainte de couverture, afin
  d'extraire un vrai cœur d'infaisabilité — reporté : changement non
  trivial de la construction du modèle, hors du périmètre "prouver que
  STRICT→PARTIAL fonctionne" de ce lot, à réévaluer si un futur besoin
  d'explicabilité fine le justifie.
- **Conséquences** : dette explicite, non bloquante — testé
  (`UnsatDiagnosticsBuilderTest::testSolverAnalysisIsAlwaysUnavailableInThisLot`).
  Détail : `docs/planning-solver.md` §Diagnostics.

## D097 — `diagnosticRelaxations` toujours vide — aucune POLICY_HARD produite aujourd'hui

- **Contexte** : Lot 6C §12/§13, confirmation d'un audit déjà fait au Lot
  6B (`docs/eligibility.md` §3) : `EligibilityService` ne produit
  aujourd'hui aucune raison `POLICY_HARD` (`MAX_DUTIES`/`MAX_WEEKENDS`/
  `TEAM_MIN_REST`/`MAX_CONSECUTIVE_NIGHTS`/`RULE_EXCLUSION`).
- **Décision** : `UnsatDiagnosticsBuilder::buildForCoverageShortfall()`
  renvoie toujours `diagnosticRelaxations = []`. Le type
  `DiagnosticRelaxation` existe (contrat prêt, `docs/fairness.md`-style),
  avec un garde-fou au constructeur qui refuse structurellement tout
  `ExclusionReason` dont le tier n'est pas `POLICY_HARD` — donc même si ce
  type était mal utilisé plus tard, une contrainte HARD ne pourrait
  jamais être proposée comme relaxation.
- **Alternative écartée** : construire une relaxation à partir d'un tier
  POLICY_HARD "hypothétique" pour remplir le modèle — explicitement
  écartée par la spécification du lot ("Ne crée pas de fausses
  relaxations uniquement pour remplir le modèle").
- **Conséquences** : testé explicitement
  (`UnsatDiagnosticsBuilderTest::testNoPolicyHardIsEverProposedAsARelaxationToday`,
  `DiagnosticRelaxationTest::testAHardRuleCanNeverBeProposedAsARelaxation`).
  Détail : `docs/planning-solver.md` §Diagnostics.

## D098 — `existingDataConflict` : contrat prêt, scénario inatteignable avec le modèle actuel

- **Contexte** : Lot 6C §14/§15/§22. `docs/allocation-algorithm.md`
  §10.5/§16 décrit `existingDataConflict` pour le cas où le solve PARTIAL
  est lui-même `UNSATISFIABLE` (ex. verrouillages existants en conflit
  LEGAL_MIN_REST). Audit de ce lot : avec `unassigned[d]` ajouté à
  **chaque** unité REQUIRED et sans aucune contrainte couplant deux
  DutyUnits entre elles (pas de MAX_DUTIES, pas de CONFLICT — voir D099),
  le problème PARTIAL admet toujours au moins la solution triviale "tout
  `unassigned[d] = 1`" — il ne peut donc jamais être lui-même UNSAT avec
  le modèle actuel. `fixedAssignments` (D090), seule source réaliste d'une
  vraie contradiction de données déjà existantes, n'existe toujours pas.
- **Décision** : le contrat (`ExistingDataConflict`,
  `ExistingDataConflictType`,
  `UnsatDiagnosticsBuilder::buildForExistingDataConflict()`, le
  branchement dans `OrToolsPlanningSolver::solvePartial()`) est
  entièrement implémenté et testé — mais uniquement via un test unitaire
  direct du builder et un test d'orchestration piloté par un script
  Python de test dédié (`tests/Solver/fixtures/echo_partial_unsat.py`),
  jamais via un scénario CP-SAT réel de bout en bout, qui n'existe pas.
- **Alternative écartée** : fabriquer un `fixedAssignments` artificiel
  rien que pour produire un vrai scénario PARTIAL-UNSAT testable —
  explicitement interdit par la spécification du lot ("Ne construis pas
  de faux fixedAssignments pour pouvoir tester existingDataConflict").
- **Conséquences** : dette réelle pour un futur lot — le jour où
  `fixedAssignments` existera réellement (D090 résolue), ce chemin devient
  atteignable et devra être re-testé avec un vrai scénario CP-SAT.
  Détail : `docs/planning-solver.md` §PARTIAL lui-même UNSAT.

## D099 — Priorité CRITICAL structurellement inerte avec le jeu de contraintes actuel

- **Contexte** : Lot 6C §24, tests demandés "CRITICAL vs STANDARD en
  concurrence pour une capacité unique → CRITICAL couverte" et "deux
  CRITICAL impossibles à couvrir toutes → minimum non couvert". En
  construisant ces scénarios, audit direct du modèle CP-SAT actuel :
  chaque `DutyUnit` porte ses propres variables `x[unit][candidat]`,
  indépendantes de toute autre unité — rien dans `build_model()` ne lie
  la couverture d'une unité à celle d'une autre (pas de MAX_DUTIES, pas de
  CONFLICT/exclusivité temporelle — confirmé absents de
  `EligibilityService` par l'audit du Lot 6B, `docs/eligibility.md` §3).
  **Conséquence mathématique** : un candidat éligible à plusieurs
  `DutyUnit` peut toujours couvrir tous ces DutyUnit simultanément — la
  couverture d'une unité REQUIRED ne dépend donc que d'elle-même (a-t-elle
  au moins un candidat éligible ?), jamais d'un arbitrage avec une autre
  unité. Il n'existe donc **aucun scénario réel** où laisser une garde
  STANDARD non couverte serait nécessaire pour couvrir une garde CRITICAL
  — les deux phases `PARTIAL_COVERAGE_CRITICAL`/`PARTIAL_COVERAGE_TOTAL`
  comptent et minimisent correctement, mais ne changent jamais la
  couverture obtenue par rapport à "couvrir tout ce qui a un candidat,
  laisser le reste" — avec le modèle actuel, il n'existe littéralement
  aucun véritable choix à faire.
- **Décision** : les tests de la spécification sont honnêtement adaptés
  plutôt que simulés artificiellement — `testTwoCriticalDutiesBothUncoverableAreBothReportedUnassignedAndCritical`
  (deux CRITICAL réellement impossibles, sans concurrence, chacune
  isolément sans candidat) remplace le scénario de "concurrence"
  demandé ; `testCriticalAndStandardEachWithTheirOwnCandidateAreBothCoveredNothingSacrificed`
  démontre explicitement l'absence de sacrifice quand chacune a son
  candidat. Le mécanisme des phases P1/P2 (comptage, ordre, verrouillage)
  reste implémenté intégralement et correctement testé — seule
  l'affirmation "un vrai arbitrage a été observé" est écartée, parce
  qu'elle serait fausse avec le code réel.
- **Alternative écartée** : ajouter une contrainte artificielle (ex. une
  fausse règle de capacité partagée) uniquement pour produire une
  démonstration de contention — explicitement interdit par le principe du
  lot ("ne jamais inventer une donnée métier manquante").
- **Conséquences** : dette réelle et directement actionnable pour la
  suite — la priorité CRITICAL ne deviendra **observable** que lorsque
  `MAX_DUTIES`/`CONFLICT`/une autre contrainte couplant plusieurs
  `DutyUnit` sera réellement implémentée dans `EligibilityService`. Tant
  que ce n'est pas le cas, ce mécanisme reste correct mais silencieux en
  pratique — à garder en tête pour ne pas conclure à tort qu'un futur test
  "CRITICAL non prioritaire" révèle un bug. Détail :
  `docs/planning-solver.md` §Priorité CRITICAL.

## D100 — `AssignmentConflict` : première contrainte globale reliant deux `DutyUnit`, calculée dans le domaine

- **Contexte** : Lot 6D. D099 avait identifié que rien ne couple deux
  `DutyUnit` entre eux dans le modèle CP-SAT — ni `EligibilityMatrix`
  (qui ne retire jamais qu'une seule arête à la fois) ni le solveur
  lui-même. Le lot demande d'introduire une vraie contrainte globale.
- **Décision** : nouveau value object `App\Fairness\AssignmentConflict`
  `{candidateStableKey, leftDutyUnitStableKey, rightDutyUnitStableKey,
  reason, tier (dérivé de reason, jamais indépendant)}`, calculé par
  `App\Service\AssignmentConflictAnalyzer` et porté par un nouveau champ
  `OptimizationProblem::$assignmentConflicts` (jamais un
  `array<string,mixed>`). Architecture : le calcul reste dans le domaine
  (`AssignmentConflictAnalyzer`, `O(units²)` — jamais
  `O(units² × candidats)`, en séparant "ces deux unités sont
  incompatibles" — candidat-indépendant, calculé une fois — de "quels
  candidats sont éligibles aux deux" — calculé ensuite seulement pour les
  paires réellement incompatibles) ; `CpSatPayloadBuilder` traduit
  mécaniquement chaque `AssignmentConflict` en
  `x[left,c] + x[right,c] <= 1` ; `cp_sat_solver.py` ne recalcule jamais
  de chevauchement/écart temporel à partir de timestamps bruts — il ne
  fait qu'ajouter la contrainte que PHP lui a déjà entièrement
  déterminée.
- **Alternative écartée** : laisser Python recalculer les incompatibilités
  depuis `Duty.startsAt`/`endsAt` bruts transmis dans le payload —
  explicitement écartée par la spécification du lot ("le Python ne doit
  pas recalculer les intervalles métier depuis des timestamps bruts") :
  le solveur resterait un pur exécutant de contraintes déjà déterminées,
  jamais un second endroit où la sémantique métier pourrait diverger.
- **Conséquences** : `EligibilityMatrix`/`EligibilityService` restent
  inchangés — `UNAVAILABLE` continue de retirer une seule arête,
  `AssignmentConflict` ne duplique jamais cette règle (testé
  explicitement,
  `GlobalConstraintsSolveTest::testUnavailabilityNeverProducesAGlobalConstraintOnlyAnAbsentEdge`).
  Ordre canonique (`left < right` par comparaison de chaînes, tri par clé
  stable) garanti par l'analyseur et réaffirmé au moment de la
  sérialisation JSON — jamais dépendant de l'ordre Doctrine/hashmap.
  Détail : `docs/planning-solver.md` §Contraintes globales.

## D101 — `CONFLICT` (HARD) et `TEAM_MIN_REST` (POLICY_HARD) implémentées ; `LEGAL_MIN_REST` toujours non implémentée 🟡 Portée révisée par [D105](#d105--legal_min_rest-et-team_min_rest-sont-des-politiques-activables-par-génération--aucune-durée-réglementaire-nest-déduite-automatiquement) (TEAM_MIN_REST n'est plus lue depuis le RuleSet d'équipe mais depuis les options figées de la génération — le calcul géométrique CONFLICT/TEAM_MIN_REST lui-même reste inchangé)

- **Contexte** : Lot 6D §2, audit des six règles candidates
  (CONFLICT/TEAM_MIN_REST/MAX_DUTIES/MAX_WEEKENDS/MAX_CONSECUTIVE_NIGHTS/
  LEGAL_MIN_REST) dans l'ordre demandé.
  - **CONFLICT** : donnée réelle (`Duty.startsAt`/`endsAt`,
    `Duty::overlapsWith()` — déjà présente, déjà documentée comme
    backing CONFLICT, jusque-là jamais utilisée), sémantique sans
    ambiguïté (impossibilité physique), toujours HARD.
  - **TEAM_MIN_REST** : donnée réelle
    (`PlanningRuleSetConfiguration::$teamMinRestHours`, snapshottée via
    `PlanningSnapshotRuleSet` — confirmé lisible et stable pour tout le
    solve), sémantique claire (écart entre `endsAt`/`startsAt` réels,
    jamais `localDate`), toujours POLICY_HARD (D036).
  - **LEGAL_MIN_REST** : aucune source de vérité légale n'existe
    toujours (D036 réaffirmée) — non implémentée, jamais copiée depuis
    `teamMinRestHours`.
- **Décision** : `AssignmentConflictAnalyzer` produit `CONFLICT` pour
  toute paire d'unités dont au moins une paire de Duty constituantes se
  chevauche (instants absolus, jamais `localDate` — DST-safe par
  construction, comme `Duty::overlapsWith()` lui-même), et
  `TEAM_MIN_REST` pour toute paire non chevauchante dont l'écart réel est
  strictement inférieur à `teamMinRestHours` — un écart exactement égal
  au minimum reste autorisé (testé explicitement,
  `AssignmentConflictAnalyzerTest::testGapExactlyEqualToTeamMinRestIsAllowed`).
  `CONFLICT` prime toujours sur `TEAM_MIN_REST` pour une même paire
  (jamais les deux rapportées pour la même incompatibilité). Le tier est
  toujours dérivé de `ExclusionReason::tier()`, jamais reconfigurable.
- **Alternative écartée** : inventer un seuil `LEGAL_MIN_REST` "courant"
  (ex. 11h) — explicitement interdit par la spécification du lot et par
  D036 elle-même.
- **Conséquences** : un `DutyGroupInstance` reste une seule unité — les
  incompatibilités sont calculées en comparant *toutes* les paires de
  Duty constituantes des deux unités (jamais seulement les Duty
  "frontières"), garantissant l'exactitude même si un futur groupe
  n'était pas trié chronologiquement. Détail : `docs/planning-solver.md`
  §Contraintes globales.

## D102 — `INSUFFICIENT_ELIGIBLE_CAPACITY` : cas exact unique (un seul candidat partagé entre deux unités en conflit)

- **Contexte** : Lot 6D §16. `docs/allocation-algorithm.md` §16 cite cet
  exemple : "2 gardes incompatibles, 1 seul candidat commun, capacité
  maximale = 1, demande = 2". La spécification interdit explicitement
  toute généralisation non démontrable sans solveur approximatif.
- **Décision** : `StructuralDiagnosticCode::INSUFFICIENT_ELIGIBLE_CAPACITY`
  n'est produit que dans le cas mathématiquement exact : une unité
  REQUIRED non assignée dont l'unique candidat éligible C est également
  en `AssignmentConflict` (CONFLICT ou TEAM_MIN_REST) avec une autre
  unité REQUIRED dont C est *également* l'unique candidat éligible —
  preuve directe qu'au plus une des deux peut jamais être couverte,
  aucune approximation. S'applique que l'autre unité ait fini assignée
  ou elle-même non assignée. Le cas général (violation du théorème de
  Hall sur un groupe arbitraire d'unités et un pool de candidats plus
  large) n'est délibérément **pas** couvert — nécessiterait un vrai
  algorithme de couplage biparti, non implémenté.
- **Alternative écartée** : un algorithme de couplage biparti complet
  (Hopcroft-Karp ou équivalent) pour détecter tout déficit de capacité
  — écarté explicitement par la spécification du lot ("si une analyse
  complète de capacité devient complexe, laisser cette couche pour plus
  tard plutôt que produire de faux diagnostics").
- **Conséquences** : `StructuralDiagnostic` reste `{code,
  dutyUnitStableKey}` — pour ce nouveau code, chacune des deux unités
  impliquées reçoit sa propre entrée (le lien vers l'autre unité n'est
  pas représenté dans ce type, limite documentée). Testé explicitement
  (`GlobalConstraintsSolveTest::testInsufficientEligibleCapacityDiagnosedWhenExactlyOneSharedCandidate`).
  Détail : `docs/planning-solver.md` §Diagnostics.

## D103 — `diagnosticRelaxations` réellement calculé par un vrai re-solve, jamais inféré

- **Contexte** : Lot 6D §17. Avec `TEAM_MIN_REST` désormais une vraie
  POLICY_HARD capable de causer un UNSAT réel, le mécanisme du Lot 6C
  (jusque-là toujours vide, D097) peut enfin produire une vraie
  relaxation. La spécification impose un algorithme précis : résoudre
  STRICT avec la règle, confirmer UNSAT, construire un problème
  diagnostic *sans* cette POLICY_HARD, vérifier si la couverture
  s'améliore réellement — jamais une déduction a priori.
- **Décision** : `OrToolsPlanningSolver::buildRelaxations()` — appelé
  uniquement quand PARTIAL laisse au moins une garde non assignée et
  qu'au moins un `AssignmentConflict` POLICY_HARD existe dans le
  problème — relance un vrai solve PARTIAL via
  `CpSatPayloadBuilder::buildPartialSolvePayload($problem,
  excludePolicyHardConflicts: true)` et ne construit un
  `DiagnosticRelaxation` que si ce second solve aboutit *réellement* à
  moins de gardes non assignées. La formulation reste conditionnelle
  ("permettrait de retrouver une couverture complète" si le nouveau
  solve est effectivement complet, "permettrait de réduire... de N à M"
  sinon) — jamais "X est la cause" (testé explicitement,
  `GlobalConstraintsSolveTest::testTeamMinRestRelaxationDiagnosticProposesRemovingItAndNeverAHardRule`).
  `DiagnosticRelaxation` garde son garde-fou de constructeur du Lot 6C
  (D097) — une contrainte HARD ne peut structurellement jamais y
  apparaître.
- **Alternative écartée** : déduire qu'une POLICY_HARD "doit" être la
  cause dès qu'elle existe dans le problème, sans re-solve de
  confirmation — explicitement interdit ("jamais inféré").
- **Conséquences** : un coût réel — un troisième appel subprocess
  seulement quand les deux conditions (shortfall + POLICY_HARD présente)
  sont réunies, jamais systématique. S'il existait un jour plusieurs
  types de règles POLICY_HARD simultanément, ce mécanisme les teste
  toutes retirées *ensemble*, pas indépendamment — limitation documentée,
  sans conséquence aujourd'hui puisque `TEAM_MIN_REST` est la seule
  POLICY_HARD réellement produite. Détail : `docs/planning-solver.md`
  §Diagnostics.

## D104 — `MAX_DUTIES`/`MAX_WEEKENDS`/`MAX_CONSECUTIVE_NIGHTS` non implémentées malgré une configuration réelle existante

- **Contexte** : Lot 6D, audit de `PlanningRuleSetConfiguration` —
  découverte que `maxDutiesPerFairnessPeriod`,
  `maxWeekendsPerFairnessPeriod` et `maxConsecutiveNights` existent bel
  et bien comme champs validés et snapshottés (contrairement à ce qui
  avait été supposé aux lots précédents). Un audit plus poussé révèle
  néanmoins des blocages réels et distincts pour chacune :
  - **MAX_DUTIES/MAX_WEEKENDS** : portée temporelle incompatible. Le nom
    même du champ ("PerFairnessPeriod") indique une limite cumulative
    sur tout un `FairnessPeriod` — qui peut couvrir plusieurs
    `PlanningPeriod` distinctes (confirmé : les fixtures de test
    construisent couramment un `FairnessPeriod` annuel contenant une
    `PlanningPeriod` de seulement quelques mois). Or `OptimizationProblem`
    ne voit jamais qu'une seule `PlanningPeriod` à la fois — aucune
    donnée de charge historique inter-génération (autres
    `PlanningPeriod` déjà publiées du même `FairnessPeriod`) n'est
    aujourd'hui accessible depuis `OptimizationProblem`/`FairnessContext`.
    Appliquer la limite en ne comptant que les gardes de *ce* solve
    calculerait silencieusement une chose différente de ce que
    `maxDutiesPerFairnessPeriod` promet réellement.
  - **MAX_DUTIES**, en plus : ambiguïté d'unité non tranchée (DutyUnit ?
    Duty constituante ? workload ?) — le nom pluriel "Duties" suggère un
    compte de Duty constituantes (cohérent avec la convention déjà
    établie de `TOTAL_DUTIES`/`DimensionMembershipCalculator`), mais rien
    ne le confirme explicitement dans le RuleSet.
  - **MAX_WEEKENDS**, en plus : `WEEKEND_GROUPS` n'existe toujours pas
    comme dimension réelle (D086, `docs/fairness.md` §3).
  - **MAX_CONSECUTIVE_NIGHTS** : aucune notion de garde "de nuit" n'est
    identifiable dans le domaine — confirmé absent depuis les audits des
    Lots 4-6C (`FairnessDimensionType` ne porte aucun cas NIGHT).
- **Décision** : aucune des trois n'est implémentée dans ce lot. Chaque
  gap est documenté précisément plutôt que masqué par une approximation
  (compter seulement dans la période courante, deviner l'unité, déduire
  "nuit" d'une heure arbitraire) — tous explicitement interdits par la
  spécification du lot.
- **Alternative écartée** : implémenter une version "approximative" de
  MAX_DUTIES limitée à la `PlanningPeriod` courante, en la présentant
  comme une première approche — écartée : calculerait silencieusement
  une règle différente de `maxDutiesPerFairnessPeriod` sans le dire,
  risquant une confusion produit sérieuse (un admin croirait la limite
  annuelle respectée alors que seule la période courante a été comptée).
- **Conséquences** : dette réelle et concrète pour un futur lot —
  implémenter `MAX_DUTIES`/`MAX_WEEKENDS` correctement nécessite de
  faire remonter la charge historique réelle (Duty déjà assignées dans
  les générations publiées antérieures du même `FairnessPeriod`) jusqu'à
  `OptimizationProblem`, un vrai changement d'architecture, pas
  seulement une nouvelle contrainte CP-SAT. `MAX_CONSECUTIVE_NIGHTS`
  reste bloquée sur l'absence de la dimension NIGHT elle-même. Détail :
  `docs/planning-solver.md` §Hors périmètre.

## D105 — LEGAL_MIN_REST et TEAM_MIN_REST sont des politiques activables par génération ; aucune durée réglementaire n'est déduite automatiquement

- **Contexte** : Lot 6D.1, clarification métier explicite reçue après
  D101 : `TEAM_MIN_REST` (et, une fois une source de donnée acceptée,
  `LEGAL_MIN_REST`) ne doivent **jamais** être imposées globalement à
  toutes les équipes/tous les plannings — ce sont des options que
  l'utilisateur choisit pour *une génération donnée*, figées à sa
  création. D101 avait implémenté `TEAM_MIN_REST` en lisant
  `PlanningRuleSetConfiguration::$teamMinRestHours` (une valeur
  d'équipe, courante au moment du snapshot) — ce lot corrige cette
  portée sans jeter le travail de D100/D101 : le calcul géométrique
  (chevauchement/écart en heures réelles, DST-safe, group-aware) est
  entièrement réutilisé, seule la source de la configuration change.
  `LEGAL_MIN_REST` reste sans aucune valeur par défaut devinée (D036
  réaffirmée une troisième fois) : elle devient implémentable
  uniquement parce qu'elle est désormais un choix explicite du
  planificateur pour cette génération, jamais une constante système.
- **Décision** :
  - `App\Entity\RestPolicyOptions` (readonly, validée dans son
    constructeur) porte `{legalMinRestEnabled, legalMinRestHours,
    teamMinRestEnabled, teamMinRestHours}`. `*Hours` est obligatoire et
    strictement positif exactement quand `*Enabled` est vrai, `null`
    sinon — jamais un défaut silencieux dans un sens ou l'autre. Quand
    les deux sont activées, `teamMinRestHours >= legalMinRestHours` est
    imposé (une politique d'équipe ne peut jamais prétendre être plus
    protectrice tout en étant en réalité plus laxiste que le plancher
    légal qu'elle superpose) — violation = échec de construction
    explicite, jamais une correction silencieuse.
  - Ces options appartiennent à `PlanningGeneration` (4 colonnes
    Doctrine plates, pas d'`#[ORM\Embedded]` — fonctionnalité jamais
    utilisée ailleurs dans ce projet, introduite maintenant non
    justifiée pour 4 champs), fixées à la construction et jamais
    mutées ensuite (seul `$status` change après coup). Contrairement à
    `PlanningRuleSet` → `PlanningSnapshotRuleSet` (nécessaire car le
    RuleSet *actif* d'une équipe peut être réassigné après coup — une
    copie figée séparée est indispensable pour rester interprétable),
    une ligne `PlanningGeneration` est déjà son propre enregistrement
    historique permanent : aucune entité `PlanningSnapshotRestPolicy`
    séparée n'est nécessaire pour satisfaire l'exigence d'immutabilité
    historique du lot — modèle à 2 couches (génération → options
    figées) au lieu des 3 couches initialement envisagées
    (RuleSet-défauts → génération → snapshot figé), qui satisfait
    pourtant intégralement l'invariant demandé (testé explicitement :
    `AssignmentConflictAnalyzerTest::testALaterGenerationsRestPolicyNeverAffectsAnEarlierGenerationsConflicts`).
  - `AssignmentConflictAnalyzer` ne dépend plus de
    `PlanningSnapshotRuleSetRepository` : il lit exclusivement
    `$snapshot->getGeneration()->getRestPolicy()`. Il classe chaque
    paire d'unités selon la précédence CONFLICT → LEGAL_MIN_REST →
    TEAM_MIN_REST (jamais les deux dernières rapportées pour la même
    paire — rendu possible et prouvé correct par l'invariant
    `teamMinRestHours >= legalMinRestHours` de `RestPolicyOptions` :
    toute violation LEGAL est mathématiquement aussi une violation
    TEAM, donc rapporter TEAM en plus serait une pure redondance,
    jamais une information supplémentaire — testé explicitement,
    `AssignmentConflictAnalyzerTest::testBothEnabledGapViolatingLegalReportsLegalOnlyNeverBothForTheSamePair`).
  - `PlanningRuleSetConfiguration::$teamMinRestHours` devient un champ
    historique non lu (docblock mis à jour), conservé uniquement parce
    que sa suppression n'a pas été démontrée nécessaire pour ce lot —
    testé explicitement qu'il n'a plus aucun effet
    (`AssignmentConflictAnalyzerTest::testRuleSetTeamMinRestHoursIsNeverConsultedOnlyTheGenerationsOwnFrozenOptions`).
  - `POST /api/planning-periods/{id}/generations` accepte un corps JSON
    optionnel (`CreatePlanningGenerationRequest`, validé via
    `#[Assert\Callback]` en miroir exact des invariants de
    `RestPolicyOptions`, jamais dupliqués silencieusement) ; un corps
    absent/vide préserve exactement le comportement pré-Lot-6D.1 (les
    deux politiques désactivées). Aucune UI, aucun nouvel endpoint,
    aucun câblage `AUTO` n'est introduit.
  - Le tier reste toujours dérivé de `ExclusionReason::tier()` :
    `LEGAL_MIN_REST` = HARD (jamais relâchable, structurellement
    impossible à proposer comme relaxation — le constructeur de
    `DiagnosticRelaxation` refuse tout ce qui n'est pas POLICY_HARD),
    `TEAM_MIN_REST` = POLICY_HARD (relâchable uniquement
    via un vrai re-solve, mécanisme D103 réutilisé sans modification —
    `OrToolsPlanningSolver::buildRelaxations()` ne collecte que les
    conflits `POLICY_HARD`, donc `LEGAL_MIN_REST` ne peut structurellement
    jamais apparaître dans `diagnosticRelaxations`, y compris quand elle
    est la seule cause réelle de l'UNSAT — testé explicitement,
    `GlobalConstraintsSolveTest::testLegalMinRestAloneNeverProducesAMisleadingRelaxation`).
    `CONFLICT` reste HARD et inconditionnel, actif quelles que soient
    les options de repos.
- **Alternative écartée** : conserver `TEAM_MIN_REST` comme donnée
  d'équipe (portée D101) et n'ajouter que `LEGAL_MIN_REST` en
  per-génération — rejetée explicitement par la clarification métier
  reçue : les deux règles doivent suivre exactement le même modèle
  (options figées par génération), pas un modèle hybride qui aurait
  laissé `TEAM_MIN_REST` continuer d'affecter silencieusement tous les
  plannings d'une équipe. Alternative également écartée : créer
  `PlanningSnapshotRestPolicy` en miroir de `PlanningSnapshotRuleSet`
  — inutile, `PlanningGeneration` étant déjà immuable après création.
- **Conséquences** : deux plannings différents (ou deux générations
  successives de la même `PlanningPeriod`) peuvent désormais utiliser
  deux politiques de repos différentes sans modifier le `RuleSet`
  global de l'équipe ni l'historique d'une génération précédente —
  critère de validation du lot, vérifié par
  `PlanningGenerationControllerTest::testDifferentGenerationsOfTheSamePlanningPeriodCanUseDifferentRestPolicies`.
  Migration `Version20260919053312` : 4 colonnes ajoutées à
  `planning_generations`, rétro-compatibles par défaut `false`/`NULL`
  (= `RestPolicyOptions::none()`, comportement pré-Lot-6D.1 exact pour
  toute génération existante). Détail : `docs/planning-solver.md`
  §Politiques de repos.

## D106 — Orchestration réelle `PlanningGeneration → solve → DutyAssignment AUTO` : `SolverParameterSet`, seed/snapshotHash, concurrence

- **Contexte** : Lot 6E. Jusqu'ici le moteur (`OptimizationProblemBuilder`,
  `OrToolsPlanningSolver`) fonctionnait en composant isolé — aucun flux
  réel ne l'appelait depuis `PlanningGeneration`, aucun `DutyAssignment`
  automatique n'était jamais créé, `PlanningGenerationStatus` s'arrêtait à
  `SNAPSHOTTED`, `timeoutBudget`/`SolverParameterSet`/`snapshotHash`
  restaient absents (D093, D088 non résolues). Audit préalable exhaustif
  (voir le rapport du lot) confirmant précisément quels champs
  existaient déjà (`SolverMetadata.solverType/solverVersion/
  solveDurationMs` réellement peuplés depuis le Lot 6B) et lesquels
  restaient à ajouter.
- **Décision** :
  1. **Lifecycle** — `PlanningGenerationStatus` gagne `SOLVING`,
     `COMPLETED`, `FAILED` : `DRAFT → SNAPSHOTTED → SOLVING →
     {COMPLETED, FAILED}`, les deux derniers terminaux (une nouvelle
     tentative est toujours une nouvelle `PlanningGeneration`, jamais une
     réouverture — cohérent avec "l'historique n'est jamais recalculé").
     `COMPLETED` couvre `coverageStatus` COMPLETE **et** INCOMPLETE (un
     résultat PARTIAL réel est un résultat métier exploitable, jamais un
     échec) ; `FAILED` est réservé à `SolverStatus::UNKNOWN`/`ERROR` ou à
     `existingDataConflict` — zéro `DutyAssignment` n'est jamais créé dans
     ce cas. `SOLVING` reste utile même en solve synchrone : c'est la
     cible réelle du verrou de concurrence (ci-dessous), pas un état
     décoratif.
  2. **Concurrence/idempotence** — `PlanningGeneration::$lockVersion`
     (`#[ORM\Version]`, verrouillage optimiste Doctrine réel) est la
     garantie : la transition `SNAPSHOTTED → SOLVING` est un `flush()`
     séparé, immédiat, avant le solve (potentiellement long) ; un second
     appel concurrent dont l'`UPDATE ... WHERE lock_version = ?` ne
     matche plus aucune ligne lève `OptimisticLockException`, traduite en
     `PlanningGenerationConcurrentSolveException` (409) — jamais une
     simple vérification `if status === SNAPSHOTTED` en mémoire, qui ne
     protège pas contre une vraie course (deux requêtes lisant le même
     statut avant que l'une ou l'autre ne commit).
  3. **`SolverParameterSet`** (nouvelle entité, système, versionnée,
     append-only, jamais éditée) : seuls deux champs réellement consommés
     — `timeoutSeconds` (→ `max_time_in_seconds` CP-SAT, par appel
     `Solve()`) et `numWorkers` (remplace le littéral `1` jusque-là codé
     en dur à trois endroits de `CpSatPayloadBuilder`). Version 1 semée
     directement dans la migration (`timeoutSeconds=60`, `numWorkers=1`)
     — un choix documenté, versionné, jamais une constante cachée dans
     `OrToolsPlanningSolver` : contrairement à `LEGAL_MIN_REST` (D036),
     un budget de solve est une décision d'ingénierie dont MedVue est la
     seule autorité, pas une donnée réglementaire externe à deviner.
     `Process::setTimeout()` (le tueur de subprocess PHP) est fixé à
     `timeoutSeconds × 12` — marge généreuse au-delà du pire cas réel
     d'appels `Solve()` par subprocess (1 base + 10 phases max = 11).
     `timeoutHit` est dérivé honnêtement côté Python : avec
     `max_time_in_seconds` comme seul critère d'arrêt configuré, tout
     statut `FEASIBLE`/`UNKNOWN` ne peut provenir que de l'expiration du
     budget — jamais une approximation.
  4. **Seed/snapshotHash** — `SnapshotHasher` calcule
     `SHA-256(canonicalSnapshotJson)` sur exactement les données
     réellement consommées par `EligibilityMatrixBuilder`/
     `FairnessContextBuilder`/`AssignmentConflictAnalyzer` : membres du
     snapshot (hors `$role`, documenté "audit uniquement, jamais lu par
     le moteur"), leurs `participationPeriods`/`availabilityPeriods`/
     `nonParticipationPeriods` (hors `sourceCreatedAt`/`sourceUpdatedAt`,
     timestamps techniques), `RestPolicyOptions`, et la liste **vivante**
     des `Duty` de la `PlanningPeriod` — jamais `PlanningSnapshotRuleSet.configuration`,
     auditée et confirmée sans le moindre effet sur le calcul aujourd'hui
     (D104 : aucune de MAX_DUTIES/WEEKENDS/CONSECUTIVE_NIGHTS n'est
     câblée, et D105 a détaché `TEAM_MIN_REST` du RuleSet). `SeedMaterialBuilder`
     calcule et persiste `seed` (`teamStableKey + planningPeriodStableKey
     + rulesVersion + snapshotHash + algorithmVersion +
     solverParameterSetVersion + explicitSeed`, docs/allocation-algorithm.md
     §13) — uniquement pour l'audit/l'identité reproductible d'une
     génération : la phase 8 (`deterministicTieBreak`) reste neutre
     (D088), ce seed n'est donc consommé par aucun solve dans ce lot,
     volontairement absent d'`OptimizationProblem`.
  5. **Concurrence sur les données vivantes** — audit confirmant que
     `Duty` n'est jamais dupliquée dans le snapshot
     (docs/planning-generation.md §4) : c'est la seule donnée qu'un solve
     synchrone peut encore voir changer sous lui (rien n'empêche d'ajouter
     une `Duty` à la `PlanningPeriod` pendant que le subprocess tourne).
     `snapshotHash` recalculé juste avant la persistance et comparé à
     celui calculé juste avant le solve : une différence lève
     `StalePlanningGenerationDataException` (409), la génération passe
     `FAILED`, rien n'est jamais persisté contre une configuration devenue
     obsolète — mais aucune fausse vérification n'est ajoutée pour les
     données réellement immuables (membres, RuleSet figé, RestPolicy).
  6. **Persistance AUTO** — `DutyAssignmentService::createAuto()`, une
     vraie frontière distincte de `createManual()` (jamais un détournement
     de sa sémantique) : ne flush jamais elle-même, conçue pour un usage
     en lot. `PlanningGenerationService::persistSuccessfulOutcome()`
     résout chaque `DutyAssignmentEdge` en `(DutyUnit, PlanningTeamMember)`,
     puis crée un `DutyAssignment` par `Duty` constituante du `DutyUnit` —
     un `DutyGroupInstance` assigné produit donc plusieurs `DutyAssignment`,
     tous vers le même candidat (atomicité du groupe préservée à la
     persistance, jamais seulement à la décision CP-SAT).
  7. **Atomicité** — tout (les `DutyAssignment` AUTO, les métadonnées de
     solve de la génération, sa transition de statut, la transition
     éventuelle de `PlanningPeriodStatus`) est `persist()`é en mémoire puis
     un seul `flush()` final — même discipline que
     `PlanningSnapshotService::createSnapshot()`. Un échec de contrainte
     unique en cours de flush (testé explicitement en pré-créant un
     `DutyAssignment` MANUAL en collision) ne laisse strictement rien de
     committed — vérifié en relisant la génération après `$em->clear()`.
  8. **`PlanningPeriodStatus::GENERATED`** — désormais réellement
     déclenché par un solve `COMPLETED` (`DRAFT → GENERATED`, ou
     `VALIDATED → GENERATED` si une régénération invalide une validation
     antérieure, déjà prévu par le graphe de transitions existant). Jamais
     déclenché sur `FAILED`. Un `PlanningPeriod` déjà `PUBLISHED` n'est
     jamais reculé (son propre graphe l'interdit déjà) — la génération
     reste quand même son propre enregistrement historique valide.
  9. **`PUBLISHED` ⇒ `coverageStatus = COMPLETE`** — dette documentée
     depuis le Lot 3 (`docs/planning-domain.md` §17) enfin fermée :
     `PlanningPeriodLifecycleService::transition()` vérifie désormais,
     uniquement pour la cible `PUBLISHED`, qu'une `PlanningGeneration`
     `COMPLETED` avec `coverageStatus = COMPLETE` existe pour la période —
     sinon `PlanningPeriodNotReadyToPublishException` (409), distincte
     d'`InvalidPlanningPeriodTransitionException` (forme du graphe,
     vérifiée en premier). Aucun endpoint de publication n'est créé dans
     ce lot — le point d'intégration est prêt pour un futur lot.
- **Alternative écartée** : un `#[ORM\Embedded]` pour les champs de
  résultat de solve sur `PlanningGeneration` — écarté pour rester
  cohérent avec D105 (colonnes plates, cette fonctionnalité Doctrine
  n'est utilisée nulle part ailleurs dans ce projet). Un enum de statut
  fusionnant `strictSolverStatus`/`partialSolverStatus`/`coverageStatus`
  en une seule valeur — explicitement écarté par la spécification du lot,
  ces trois axes restent indépendants. Vérifier la concurrence par une
  requête `UPDATE ... WHERE status = 'SNAPSHOTTED'` brute plutôt qu'un
  verrou optimiste Doctrine — écarté : `#[ORM\Version]` est le mécanisme
  idiomatique de ce framework pour exactement ce problème, et produit la
  même garantie DB-level sans SQL à la main.
- **Conséquences** : une vraie `PlanningGeneration` peut désormais être
  résolue de bout en bout, produire ses `DutyAssignment` AUTO de manière
  atomique et historique, sans jamais confondre résultat métier, erreur
  technique et état de lifecycle — critère de validation du lot. Nouvel
  endpoint `POST /api/planning-generations/{stableId}/solve` (OWNER/ADMIN
  uniquement), réponse jamais les structures CP-SAT internes, uniquement
  le modèle `UnsatReport` déjà typé quand `coverageStatus = INCOMPLETE`.
  Dette réelle restante : un crash serveur pendant `SOLVING` laisse la
  génération bloquée dans cet état sans mécanisme de nettoyage
  automatique (pas de cron/sweep dans ce lot — un futur lot devra
  décider d'une politique explicite, pas une simple découverte). Seule la
  liste des `Duty` vivantes est couverte par la vérification de
  concurrence — une autre donnée lue en direct qui deviendrait éditable
  plus tard (ex. `DutyType.workloadValue`) ne serait pas automatiquement
  protégée. `fixedAssignments`, REPAIR, SIMULATE, `MAX_DUTIES`/
  `MAX_WEEKENDS`/`MAX_CONSECUTIVE_NIGHTS`, UI, validation/publication
  avancée restent hors périmètre. Détail : `docs/planning-generation.md`
  §2/§13-16, `docs/planning-solver.md` §37.

## D107 — Les clés JWT de production vivent dans un volume nommé, jamais dans la couche inscriptible du conteneur

- **Contexte** : au premier déploiement (2026-09-20), `lexik:jwt:generate-keypair`
  écrit dans `/app/config/jwt` de `medvue-backend`. Ce dossier n'existe pas
  dans l'image (`config/jwt/*.pem` est gitignoré et absent de l'archive
  `git archive`), et `docker diff medvue-backend` montre les deux `.pem` comme
  ajoutés à la couche inscriptible (aucun `VOLUME`, aucun mount). Elles
  survivent à un `docker compose restart` mais pas à un recreate/rebuild/
  `down` : chaque déploiement d'une nouvelle image aurait régénéré une paire
  neuve (étape 7 idempotente uniquement *dans* un conteneur donné) et
  invalidé silencieusement tous les access tokens émis.
- **Décision** : volume Docker **nommé et explicite** `medvue_jwt_keys` dans
  `docker-compose.prod.yml`, monté en écriture sur `/app/config/jwt` du seul
  service `backend`. `--skip-if-exists` devient réellement idempotent d'un
  déploiement à l'autre. Complément : `backend/.dockerignore` exclut
  `config/jwt/`, `.env.local`, `.env.*.local`, `.env.local.php` — un
  `docker build` depuis un working tree (où les clés de dev existent,
  gitignorées) les aurait sinon copiées dans l'image de prod via `COPY . .`.
- **Alternatives écartées** : bind mount vers un dossier de `/opt/stack/apps/
  medvue` — écarté, un remplacement du contenu de l'application (comme lors
  du redéploiement propre du 2026-09-20) l'aurait supprimé, et un chemin
  hôte rend le déploiement dépendant d'un état hors compose ; copie manuelle
  des clés hors procédure — écartée, non reproductible ; clés cuites dans
  l'image ou versionnées — écarté, jamais de clé privée dans Git ni dans
  une image ; Docker secrets — écarté, il faudrait quand même générer et
  stocker les fichiers sur l'hôte, sans gain sur un serveur mono-nœud.
- **Conséquences** : `JWT_PASSPHRASE` (`.env`) et le volume forment un couple
  — régénérer l'un sans l'autre casse la signature. Le volume doit entrer
  dans les sauvegardes (clé privée chiffrée, passphrase conservée
  séparément). Garde-fou automatisé :
  `backend/tests/Deployment/ProdComposeTest.php` (volume nommé pour les clés
  JWT et pour les données PostgreSQL, aucun secret ni clé dans le compose,
  `.gitignore`/`.dockerignore`). Vérification de déploiement : empreinte de
  `public.pem` identique avant/après `up -d --force-recreate backend`
  (`docs/deployment.md` §2).

## D108 — Traefik est le seul proxy de confiance de MedVue, désigné par son adresse exacte

- **Contexte** : derrière Traefik, chaque requête atteint `medvue-backend` avec
  `REMOTE_ADDR` = adresse de Traefik sur le réseau `proxy`. Sans
  `SYMFONY_TRUSTED_PROXIES`, `Request::getClientIp()` renvoyait cette adresse
  pour tous les visiteurs. Mesure réelle au premier déploiement : le serveur
  (vu par Traefik comme `187.124.55.15`) épuisait le limiteur d'inscription
  (5/h) et le login-throttling (5/15 min par identifiant), et un client
  distinct (`81.240.77.225`) obtenait aussitôt `429` sur les deux. Les
  refresh tokens enregistraient aussi l'adresse du proxy dans `created_by_ip`.
- **Décision** : `SYMFONY_TRUSTED_PROXIES` (lu par défaut par
  `framework.trusted_proxies`, aucun changement de code) vaut l'adresse
  **exacte** de Traefik sur `proxy` (`172.18.0.2` au 2026-09-20), pas le
  `/16` du réseau. Traefik écrase de lui-même `X-Forwarded-For` reçu
  d'Internet (aucun `forwardedHeaders.trustedIPs` configuré sur ses
  entrypoints) : seule l'adresse réelle du client arrive au backend.
- **Alternatives écartées** : faire confiance à tout le sous-réseau `proxy`
  (`172.18.0.0/16`) — écarté, `surgicalhub-nginx` et les conteneurs MedVue y
  sont attachés et pourraient forger `X-Forwarded-For` pour contourner les
  limiteurs ; `PRIVATE_SUBNETS`/`REMOTE_ADDR` — encore plus larges ; IP fixe
  pour Traefik — impossible sans toucher à l'infra partagée (hors périmètre).
- **Conséquences** : l'adresse est attribuée dynamiquement par Docker et peut
  changer si Traefik est recréé ou après un reboot. Une valeur périmée
  *échoue en sécurité* (retour au comportement à compteurs partagés, jamais
  une usurpation) mais doit être détectée : `scripts/deploy/check-trusted-proxy.sh`
  compare le `.env` et le backend en marche à l'adresse actuelle de Traefik ;
  `env_file` n'est lu qu'à la création du conteneur (recreate obligatoire
  après modification). Tests : `tests/Security/TrustedProxyClientIpTest.php`
  (buckets par IP réelle, non-usurpation depuis un autre conteneur, IP
  enregistrée) et garde-fous `ProdComposeTest` (une seule adresse exacte,
  toute clé de `backend/.env` redéfinie dans le `.env` de prod).

## D109 — Sauvegardes MedVue : scripts versionnés, dump exécuté dans le conteneur, restauration prouvée dans une cible jetable

- **Contexte** : au premier déploiement, rien ne sauvegardait MedVue. Le
  `backup-postgres.sh` annoncé par `docs/deployment.md` n'existait pas et les
  scripts existants du serveur (`/home/deploy/scripts/*`, crontab de `deploy`)
  ne couvrent que MySQL et les volumes d'uploads de SurgicalHub. Constat
  aggravant : `rclone` n'est pas installé, le `sync_gdrive.sh` existant échoue
  donc chaque nuit et aucune copie hors serveur n'existe.
- **Décision** : `scripts/backup/medvue-backup.sh` (PostgreSQL + volume
  `medvue_jwt_keys`) et `scripts/backup/medvue-restore-test.sh`, **versionnés
  dans le dépôt** (déployés avec l'archive, revus, testés) plutôt que créés à la
  main sur le serveur. `pg_dump` s'exécute **dans** `medvue-database` par son
  socket local : aucun mot de passe n'est lu ni transmis. Format `custom`
  (`--no-owner --no-acl`), validé par `pg_restore --list`, sha256, écriture
  atomique, `umask 077`, `flock`, rétention locale 30 jours avec les 7 plus
  récentes toujours conservées, journal propre
  (`/home/deploy/backups/medvue/backup.log`). La preuve de restauration se fait
  dans un conteneur PostgreSQL **jetable** : sans réseau, données en `tmpfs`,
  supprimé même en cas d'échec ; `--compare-live` compare tables, nombre de
  lignes, contraintes, index, migrations et empreintes des clés. Cron quotidien
  03:45 UTC, ajouté au crontab existant sans en modifier aucune ligne.
- **Alternatives écartées** : copier les scripts à la main dans
  `/opt/stack/backups/scripts/` (non versionné, non testable, et ce répertoire
  n'est pas celui qu'utilise réellement le cron de SurgicalHub) ; ajouter
  MedVue à `backup_uploads.sh`/`rotate_backups.sh`/`sync_gdrive.sh` — écarté :
  consigne de ne pas toucher aux sauvegardes existantes (et leur `find` de
  rotation ne couvre de toute façon que `mysql` et `uploads`) ; mot de passe
  PostgreSQL lu dans `.env` par le script — écarté, inutile via le socket
  local ; sauvegarder `.env` avec les clés — écarté, la passphrase ne doit
  jamais voyager avec la clé qu'elle protège ; restaurer dans la base de
  production pour « tester » — écarté, un test de restauration ne doit
  jamais pouvoir écrire en production.
- **Conséquences** : un test statique (`tests/Deployment/BackupScriptsTest.php`,
  `DeployScriptsTest.php`) garantit l'absence de secret, qu'un `pg_restore`
  ne peut viser que la cible jetable, qu'aucun script ne supprime de volume et
  ne touche aux autres applications. Limites assumées et documentées
  (`docs/backup.md` §5) : pas de copie hors serveur (à décider, chiffrée),
  RPO 24 h, `.env` à conserver manuellement hors serveur, sauvegardes non
  chiffrées au repos (droits Unix). `docker compose down -v` est explicitement
  interdit en production (`docs/deployment.md` §7).

## D110 — Les hôpitaux sont un référentiel structuré (`Hospital`), alimenté par import, jamais un texte libre ni un jeu de données livré 🔴 Remplacé par [D115](#d115--létablissement-nest-pas-une-propriété-du-user--le-référentiel-hospital-est-supprimé)

> **🔴 Remplacé (2026-09-20, avant tout commit/déploiement)** : l'hôpital
> n'est pas une propriété durable de l'utilisateur — les médecins/assistants
> changent d'hôpital d'une année à l'autre. `Hospital`, `User.primaryHospital`,
> `GET /api/hospitals` et `app:hospitals:import` n'existent plus (D115). Le
> texte ci-dessous est conservé tel qu'écrit, pour la traçabilité.

- **Contexte** : l'inscription demande l'institution principale, et l'objectif
  futur est de référencer/rechercher les médecins par institution.
- **Décision** : entité `Hospital` (`stableId` UUIDv7, nom, ville, code postal,
  code pays ISO, `active`) et `User.primaryHospital` (FK `RESTRICT`).
  Recherche côté serveur (`GET /api/hospitals?search=`, publique et limitée en
  débit) sur une colonne dérivée `search_key` (sans accents ni casse), pour ne
  pas expédier la liste au navigateur ni exiger l'extension PostgreSQL
  `unaccent`. Alimentation par `app:hospitals:import` (CSV, idempotent,
  tout-ou-rien). Clé naturelle unique `(pays, LOWER(nom), LOWER(ville))`.
- **Alternatives écartées** : chaîne libre sur `User` (non interrogeable, doublons
  « CHU Liège » / « Chu de Liege ») ; liste statique dans le frontend ; dataset
  téléchargé ou inventé (aucune source officielle n'est dans le dépôt : décision
  métier à prendre, cf. `docs/authentication.md` §15.8) ; identifiant externe
  (aucune source pour le justifier — à ajouter avec elle).
- **Conséquences** : `hospital` et `phone` sont **obligatoires à l'inscription**
  mais **nullables en base** (comptes existants). Tant que le référentiel est
  vide, personne ne peut s'inscrire : l'import précède le déploiement.

## D111 — Une invitation n'est jamais un faux `User` : `TeamInvitation`, et un seul créateur de `User`

- **Contexte** : ajouter par email quelqu'un qui n'a pas de compte.
- **Décision** : entité `TeamInvitation` (équipe, email normalisé, noms
  *proposés*, invitant, rôle futur, `tokenHash`, statut, expiration, acceptation).
  Un `User` incomplet ne doit jamais exister (mot de passe/téléphone/hôpital
  absents, comptes fantômes authentifiables, unicité d'email consommée par
  quelqu'un qui n'a rien accepté). Le `PlanningTeamMember` n'est créé que par
  `PlanningTeamMembershipService::addMember()` quand le `User` existe.
  `UserRegistrationService` reste le **seul** endroit où un `User` naît. Si
  l'email correspond déjà à un `User` : membership immédiat, sans acceptation
  (exigence produit), avec notification.
- **Autorisation** : `PlanningTeamRoleVoter::INVITE` = créateur du Planning **ou**
  OWNER/ADMIN de l'équipe. Élargit volontairement D071/D079 (gestion des membres
  creator-only) pour ce seul chemin ; les endpoints d'ajout par identifiant
  restent creator-only.
- **Alternatives écartées** : `User` avec statut `INVITED` (contredit « aucun User
  incomplet », piège d'authentification) ; `PlanningTeamMember` sans `User`
  (interdit) ; acceptation obligatoire pour un compte existant (non voulue).

## D112 — Téléphone : libphonenumber, stocké en E.164, validé côté serveur

- **Décision** : dépendance `giggsey/libphonenumber-for-php-lite` (port PHP de la
  bibliothèque de Google ; variante *lite* : parsing/validation/formatage, sans
  géocodage/opérateur, bien plus légère). Un regex maison serait faux dès qu'un
  utilisateur non belge arrive, les plans de numérotation changeant par pays.
  `PhoneNumberNormalizer::toE164()` ; contrainte de validation `ValidPhoneNumber`
  sur le DTO ; colonne `users.phone_e164` avec CHECK de forme. `DEFAULT_PHONE_REGION`
  (BE) ne sert qu'à lire un numéro saisi **sans** préfixe international ; un
  numéro en `+…` est interprété seul. La validation frontend n'est qu'un confort.
- **Limites** : validité de plan de numérotation ≠ numéro joignable (pas de SMS
  de vérification).

## D113 — Sécurité des invitations : token haché, transaction verrouillée, inscription classique ≠ consommation

- **Token** : 256 bits aléatoires, seule l'empreinte SHA-256 en base, usage
  unique, expiration configurable (`INVITATION_TTL_HOURS`) vérifiée à chaque
  usage, révocable. L'email est fixé par l'invitation, jamais par le client.
- **Atomicité/concurrence** : inscription par invitation = une transaction avec
  `SELECT … FOR UPDATE` sur l'invitation ; index unique partiel « une invitation
  `PENDING` par (équipe, email) » et index unique `LOWER(email)` ; CHECK de
  cohérence du statut. Vérifié en vrai parallèle (une `201`, une `410`).
- **Multi-invitations** : le token prouve l'accès à la boîte, donc *toutes* les
  invitations `PENDING` utilisables de l'adresse sont consommées ensemble
  (un `User`, N memberships, un email). Une invitation impossible à honorer
  (déjà membre d'une autre équipe du même Planning, D080) reste `PENDING`.
- **Compte créé entre-temps** : jamais de second `User` ; `409
  account_exists_for_invitation`, l'invitation reste `PENDING`, et un
  `POST /api/invitations/{token}/accept` authentifié (email du compte = email de
  l'invitation) crée les memberships.
- **Inscription classique ne consomme rien** : les emails ne sont pas vérifiés ;
  s'inscrire avec l'adresse d'un tiers ne doit pas lui voler ses équipes.
  Conséquence : une personne invitée qui s'inscrit sans passer par le lien doit
  ensuite ouvrir le lien (et se connecter) pour rejoindre l'équipe.
- **Email insensible à la casse** (lecture/connexion/unicité) ; `POST
  /api/register` conserve son `409` historique (fuite d'existence pré-existante,
  atténuée par le rate limit, hors périmètre).
- **Rate limiting** : `invitation_lookup`, `team_invitation` (en plus de
  `register`, inchangé).

## D114 — Emails transactionnels : Symfony Mailer + Twig, templates de la maquette, envoi best-effort

- **Décision** : `symfony/mailer` + `symfony/twig-bundle` (aucun mécanisme d'email
  n'existait). Templates dans `backend/templates/email/`, copiés de
  `docs/Design/emails_medvue` (`_base`/`_components` identiques) avec deux
  écarts : échappement (`|e`) des noms saisis par un tiers — la maquette les
  injecte en `|raw`, vecteur d'injection HTML dans la boîte d'un tiers — et
  liste d'équipes dans l'email « Bienvenue » (un seul email, pas un par
  invitation). Version texte `.txt.twig` pour chacun.
- **Envoi** : synchrone, **après** validation en base, jamais bloquant : un échec
  de transport est logué (sans token) et exposé (`emailSent:false`), il ne défait
  ni l'inscription ni l'invitation. Pas de Messenger (dette). Liens construits
  depuis `APP_FRONTEND_URL`, jamais depuis l'en-tête `Host`.
- **Production** : `MAILER_DSN`, `MAILER_FROM`, `SUPPORT_EMAIL`, `APP_FRONTEND_URL`,
  `INVITATION_TTL_HOURS`, `DEFAULT_PHONE_REGION` dans `.env.prod.example`
  (imposé par `ProdComposeTest`) ; le défaut de dev `null://null` perdrait
  silencieusement les emails. Dev : Mailpit dans `docker-compose.yml`.
- **Placeholders de la maquette** : ses valeurs de démonstration ne doivent
  jamais atteindre un vrai email. Le pied de page utilise `SUPPORT_EMAIL`
  (et non le domaine `medvue.app` de la maquette, différent du domaine de
  production) et le bloc `postal` (adresse inventée) est vidé jusqu'à
  confirmation de l'adresse légale. Le design lui-même n'est pas modifié : ce
  sont les variables/blocs que `_base` prévoit pour cela.

## D115 — L'établissement n'est pas une propriété du User ; le référentiel Hospital est supprimé

- **Contexte** : D110 avait fait de l'hôpital une donnée d'inscription
  (`User.primaryHospital`, obligatoire, choisie dans un référentiel `Hospital`
  alimenté par import). Décision métier ultérieure : les assistants et médecins
  changent d'hôpital d'une année à l'autre. L'établissement est donc une donnée
  **contextuelle et temporelle**, pas une propriété durable de l'identité.
- **Décision** : `User` ne représente que l'identité durable (nom, email,
  téléphone, mot de passe). `User.primaryHospital`, l'entité `Hospital`, son
  repository, `GET /api/hospitals`, la commande `app:hospitals:import`, le
  limiteur `hospital_search`, l'autocomplete frontend, `primaryHospitalStableId`
  dans `POST /api/register` et leurs tests/documentations sont **supprimés**.
  L'inscription ne dépend plus d'aucune table de référence : le problème
  « référentiel vide = personne ne peut s'inscrire » (D110) disparaît. Le champ
  n'est plus ni exigé ni utilisé côté API ; un ancien client qui l'enverrait
  encore reçoit un `422` explicite plutôt qu'un `201` trompeur (D116).
- **Migration** : `Version20260920111015` n'avait jamais été commitée ni
  déployée (dernier commit : déploiement prod-2). Elle a donc été **réécrite en
  place** — elle ne crée plus `hospitals` ni `users.primary_hospital_id` — plutôt
  que d'empiler une migration qui retire aussitôt ce qu'une autre vient
  d'ajouter. Aucun environnement partagé n'a jamais porté ces structures ; les
  bases locales ont été ramenées en arrière (`down`) puis rejouées.
- **Ce qui est volontairement laissé ouvert** : où porter plus tard
  l'affiliation hospitalière. Piste naturelle : `PlanningTeamMember`, qui a déjà
  `membershipStart`/`membershipEnd`, ou une entité dédiée
  (`Institution`/`Site`/`Affiliation`) — à décider quand le besoin métier sera
  confirmé. Aucun champ `hospital` n'est ajouté à `PlanningTeam` ni à
  `PlanningTeamMember` maintenant : ce correctif retire une mauvaise donnée du
  `User`, il ne construit pas la gestion des institutions.
- **Alternatives écartées** : garder `Hospital` « au cas où » (aucun consommateur
  réel, code mort à maintenir) ; garder `User.primaryHospital` nullable/optionnel
  (reste une donnée d'identité fausse dès la première mutation d'un médecin) ;
  ajouter tout de suite un `hospital` sur `PlanningTeamMember` (spéculatif).
- **Conséquences** : si une future recherche « médecins par institution » est
  voulue, elle s'appuiera sur l'affiliation datée, pas sur le profil. Les emails
  ne mentionnent aucun établissement (test `InvitationMailerTest`).

## D116 — Les corps JSON désérialisés en DTO rejettent les champs inconnus (`422`), au lieu de les ignorer

- **Contexte** : le Serializer de Symfony ignore par défaut les propriétés
  inconnues. Après D115, un ancien client qui envoyait encore
  `primaryHospitalStableId` recevait un `201` : il pouvait croire l'hôpital
  enregistré. Plus généralement, une API qui accepte silencieusement une donnée
  que le client croit stockée est trompeuse — et pour `/api/register` un champ
  comme `active` ou `roles` ne doit jamais sembler « accepté ».
- **Audit du reste de l'API** : deux chemins désérialisent un DTO avec le
  Serializer (`POST /api/register`, `POST …/invitations`) ; tous les autres
  contrôleurs lisent le corps avec `json_decode` et ne retiennent que les clés
  qu'ils connaissent (plannings, membres, indisponibilités, générations…).
- **Décision** : ces deux endpoints désérialisent avec
  `AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false` ; un champ inconnu donne
  `422` au format habituel (`{"error":"validation_failed","violations":{champ:
  "This field is not accepted."}}`, une violation par champ), sans effet de bord,
  avant toute consultation d'invitation. `400 invalid_json` reste réservé à un
  corps illisible. Helper unique : `UnknownFieldsResponse`. Pas de mécanisme
  global (listener, normalizer custom) : disproportionné pour deux endpoints.
- **Conséquence pour l'invitation** : demander un `role` n'est plus un
  déclassement silencieux en `MEMBER` mais une erreur (le champ n'existe pas en
  v1).
- **Dette assumée** : les contrôleurs à `json_decode` manuel restent tolérants
  aux clés inconnues. À aligner si/quand ils passent à des DTO désérialisés ;
  ne pas les changer dans ce lot (hors périmètre, risque de régression sans
  bénéfice identifié).

## D117 — Refonte de l'interface : tokens du design system Surgery Hub, mobile d'abord, sans bibliothèque de composants

- **Contexte** : le frontend n'avait aucune charte (HTML brut, un en-tête de
  liens). Une maquette complète (`docs/Design/`, canvas de design) a été
  produite : charte, parcours mobile et desktop, connexion / inscription /
  invitations, plannings, calendrier d'indisponibilités.
- **Décision** :
  - les **tokens** du design system (`docs/Design/Calendrier_selection_multiple/_ds`)
    sont copiés dans `frontend/src/styles/tokens.css` — les variables CSS
    (`--green-700`, `--surface-card`, `--radius-md`…) sont la source de vérité,
    jamais de valeur brute dans les composants ;
  - **pas de MUI ni de bibliothèque de composants** : les composants du design
    system Surgery Hub sont des primitives CSS-tokens ; une poignée de classes
    (`.btn`, `.field`, `.card`, `.tag`, `.alert` — `styles/ui.css`) et trois
    composants React (`Field`/`PasswordField`, `Icon`, `Logo`) suffisent, ce qui
    garde l'« empreinte minimale » du projet (voir `docs/availability.md` §8) ;
  - **Inter auto-hébergée** via `@fontsource/inter` (poids 400–800), pas de
    requête vers Google Fonts (vie privée, hors-ligne, pas de CSP à ouvrir).
    Seule dépendance ajoutée ;
  - **un seul DOM, deux mises en page** (breakpoint 900 px, CSS) : barre latérale
    (`AppShell`) sur desktop, barre de marque + navigation basse à 5 entrées sur
    téléphone ; pages publiques dans `AuthLayout` (panneau de marque + formulaire
    sur desktop, formulaire seul sur mobile). Les routes publiques et protégées
    sont des *layout routes* : plus aucun lien de navigation pour un visiteur
    déconnecté ;
  - le bouton principal est en `green-700` (pas le vert de marque `green-500`) :
    le texte blanc sur `#42A882` ne passe pas 4,5:1. Le vert de marque reste
    réservé à l'identité (logo, points d'état) ;
  - le **logo est un marqueur provisoire** (pictogramme d'activité) : aucun logo
    officiel n'a été fourni.
- **Conséquences** : le tableau de bord n'affiche que des données réelles
  (prochaines indisponibilités, plannings) — la carte « Prochaine garde » de la
  maquette est volontairement absente tant qu'aucune API de gardes personnelles
  n'existe. Le titre de page passe de « Tableau de bord » à « Bonjour,
  {prénom} ! » (tests adaptés).

## D118 — Disponibilités : jour entier uniquement, axe de jours absolus, enregistrement par différence

- **Contexte** : le produit a tranché — indisponibilités *et* préférences de
  garde concernent toujours des **journées entières** (plus de plage horaire pour
  un jour unique, contrairement à `docs/availability.md` §8 avant ce lot).
- **Décision** :
  - **modèle client** : `DayRange {start, end, type}` en **index de jours
    absolus** (jours locaux depuis 1970, `calendarAxis.ts`) — une période à
    cheval sur deux mois ou deux années est un simple intervalle d'entiers ;
    conversion en `Date` uniquement à l'affichage et à l'API (`periodMapping.ts`) ;
  - **algèbre pure** (`selection.ts`, testée) : `mergeRanges` fusionne les plages
    adjacentes *de même nature* seulement, `cutRange` scinde, `addRange` fait
    gagner la nouvelle plage sur la zone commune, `toggleDay` retire / convertit /
    ajoute. Un jour a **une seule nature** côté écran, alors que le backend
    autorise `UNAVAILABLE` et `PREFER_DUTY` à se chevaucher (`docs/availability.md`
    §4) : au chargement, l'indisponibilité l'emporte (signal dur) ;
  - **une période API = une plage contiguë** (règle « chevauche ou touche » du
    backend) : `[minuit local du 1er jour, minuit local du lendemain du dernier[` ;
  - **enregistrement explicite** (`planSave`) : la page compare l'écran aux
    périodes stockées ; une période stockée dont les jours sont exactement une
    plage à l'écran n'est **pas touchée** (garde son identifiant, et son éventuelle
    heure si c'est une ancienne donnée) ; les autres sont supprimées **puis** les
    nouvelles créées (l'inverse serait refusé en `409`). Pas d'appel par clic :
    « Enregistrer » est désactivé tant que rien n'a changé ;
  - **anciennes périodes avec heure** : affichées comme les jours qu'elles
    touchent, jamais modifiées tant que l'utilisateur ne touche pas à ces jours ;
  - **gestes** : un seul jeu d'événements `pointer*` pour souris et tactile (tap
    = bascule, glisser = nouvelle période, `Ctrl/Cmd`+clic = retirer, `Maj`+clic =
    étendre, clavier = activation du bouton). Le défilement automatique aux bords
    est temporisé (450 ms de maintien, 900 ms entre deux avances, un mois à la
    fois) — exigence d'ergonomie du prototype, testée avec des timers factices ;
  - **fenêtre** : 18 mois à partir du mois courant, étendue pour couvrir toute
    période déjà stockée (jamais de période hors axe). Deux mois côte à côte dès
    1280 px, un seul en dessous ;
  - **jours fériés belges** calculés (fixes + Pâques, `holidaysOfYear`), à titre
    **informatif** : la teinte n'empêche jamais la sélection. Hypothèse assumée
    (fuseau par défaut `Europe/Brussels`) ; à rendre configurable le jour où une
    équipe hors Belgique existe.
- **Rejeté** : une bibliothèque de calendrier (aucune ne couvre la sélection
  multi-périodes au doigt avec défilement aux bords) ; un `PATCH` par période
  modifiée (complexité pour un gain nul : suppression + création suffisent).
- **Non implémenté** : les gardes déjà attribuées ne sont pas affichées sur le
  calendrier (aucune API « mes gardes » — `docs/planning-generation.md`), ni
  l'avertissement de chevauchement de la maquette.

## D119 — Frontend : conventions de la refonte

- Les états asynchrones des pages (`Chargement…`, erreurs) gardent leurs
  libellés et leurs `role` (`status` / `alert`) : les tests de comportement
  existants n'ont changé que là où le *contenu* de la page a changé (titre du
  tableau de bord ; marque présente deux fois dans le DOM — barre latérale et
  barre mobile).
- Le tableau de bord ne bloque jamais sur un appel en échec : chaque carte se
  charge seule et retombe sur un état vide.

## D120 — `AvailabilityCollectionResponse` (revue d'une fenêtre) ≠ `UserAvailabilityPeriod` (vérité de disponibilité)

- **Contexte** : posséder des indisponibilités ne prouve pas avoir revu une
  nouvelle tranche de planning (X saisit le 07/08, le planning est prolongé le
  10/12 : rien ne dit qu'il a regardé janvier–mars).
- **Décision** : `UserAvailabilityPeriod` reste la seule vérité sur « suis-je
  indisponible à cette date » (éligibilité, snapshots). Une
  `AvailabilityCollectionResponse` est la **preuve explicite** qu'une personne
  a confirmé ses disponibilités pour une fenêtre d'un planning. Elle ne
  modifie jamais l'éligibilité ; le snapshot capture le calendrier quel que
  soit le statut de réponse (testé). La réponse est rattachée au `User` (pas
  au stint `PlanningTeamMember`, cf. D082) et créée à l'ouverture, pour que
  l'effectif et les compteurs restent un fait historique.
- **Rejeté** : déduire « répondu » de l'existence d'absences (faux dans les deux
  sens) ; recalculer l'historique depuis l'état courant (interdit par CLAUDE.md).
- **Détail** : `docs/availability-collection.md`.

## D121 — Une modification après confirmation ne rouvre pas la réponse

- **Décision** : modifier son calendrier dans la fenêtre met à jour
  `lastAvailabilityChangeAt` (collectes ouvertes seulement) mais laisse la
  réponse confirmée ; l'UI affiche « calendrier modifié depuis le … ». « Je
  n'ai aucune indisponibilité » est refusé (409) tant qu'une `UNAVAILABLE`
  recoupe la fenêtre ; la confirmation est idempotente (première gagnante,
  verrou pessimiste).
- **Pourquoi** : imposer une reconfirmation à chaque retouche est pénible et
  n'apporte rien que l'admin ne voie déjà ; aucune raison métier forte de
  rouvrir automatiquement.

## D122 — Prolonger un planning : agrandissement en place, collecte de la seule tranche nouvelle

- **Contexte** : D077 avait laissé les dates du `Planning` immuables. La collecte
  par tranche exige de pouvoir prolonger.
- **Décision** : `POST /plannings/{id}/extensions` agrandit en place `Planning`,
  puis `FairnessPeriod` et `PlanningPeriod` de chaque ligne (D075 préservé), et
  ouvre une collecte par tranche nouvelle (`AvailabilityWindowCalculator` :
  fin, début, ou les deux ; jamais vide, jamais les dates déjà couvertes).
  Verrou sur la ligne `Planning` pour sérialiser les extensions concurrentes.
  Réduction refusée.
- **Limite assumée** : refusé (409) si une ligne est `VALIDATED`/`PUBLISHED`/
  `ARCHIVED` — une période publiée n'est jamais éditée et le moteur ne sait pas
  générer une tranche additionnelle à côté. Étendre à un planning publié
  demandera un modèle « plusieurs périodes par ligne » (D073 le permet en
  principe), hors lot.
- **Suite de D077** : le `PATCH /plannings/{id}` reste limité au nom.

## D123 — Le créateur participe par une adhésion, pas par un booléen

- **Décision** : participer = avoir une adhésion ouverte dans le planning. Option
  `includeMe` à la création (défaut API `false`, case cochée par défaut dans
  l'UI) : adhésion `OWNER` de la ligne principale, facteur 1.0, début = min
  (aujourd'hui, début du planning). Aucun traitement particulier dans le
  moteur ; indépendant de `Planning.creator` (gestion, D071).
- **Pourquoi `OWNER`** : c'est ce qui rend les endpoints de génération
  accessibles au créateur (`TEAM_MANAGE_PLANNING` exige un OWNER/ADMIN de
  l'équipe) ; les règles d'éligibilité ne lisent pas le rôle.
- `/api/me` expose désormais `stableId` (le front en a besoin pour s'ajouter).

## D124 — `PLANNING_MANAGE_AVAILABILITY` : créateur ou OWNER/ADMIN, plus large que `MANAGE`

- **Décision** : ouvrir/clôturer une collecte, fixer l'échéance et lire les
  réponses de tous est ouvert au créateur **et** à un OWNER/ADMIN ouvert d'une
  équipe du planning. Prolonger le planning reste `MANAGE` (créateur seul, D071).
  MEMBER : ses métadonnées et sa propre réponse, jamais les compteurs ni les
  autres. Élargissement ponctuel de D071, motivé par la demande d'identifier les
  retardataires ; réévaluable avec le modèle de collaborateurs.

## D125 — Lecture des affectations : dernière génération `COMPLETED` de chaque ligne

- **Décision** : `GET /plannings/{id}/assignments` (VIEW) lit les affectations de
  la génération `COMPLETED` la plus récente de chaque ligne ; résumé par personne
  = comptage des affectations réelles via `DimensionMembershipCalculator`,
  sur tout le planning. Pas de métrique « nuits » (dimension non modélisée) ni de
  « week-ends » au sens groupes (D086) : jours de week-end (sam.+dim.).
- **Rejeté** : recalculer cibles/écarts de fairness pour l'affichage.

## D126 — Frontend : magasin partagé, sauvegarde optimiste par diff, retour à la vérité serveur

- **Décision** : `MyAvailabilityProvider` (contexte, pas de bibliothèque de cache)
  porte calendrier et collectes ; sauvegarde par `planSync` (DELETE / PATCH /
  POST), un cycle à la fois, échec → relecture du serveur + message. Le bouton
  « Enregistrer » disparaît ; « Tout effacer » exige un second clic.
- **Révise D118** : « un PATCH par période modifiée » avait été rejeté (une
  suppression + création suffisait) ; la demande d'édition immédiate le rend
  utile (identifiant conservé, une requête, pas de fenêtre où la période
  n'existe pas).
- **Rejeté** : react-query / SWR (refonte non justifiée pour un état de deux
  ressources) ; un PATCH/POST par geste sans diff (impossible d'assurer l'ordre
  ni de fusionner des périodes adjacentes).

## D127 — Vue de pilotage OWNER/ADMIN : deadline informative, rappels audités, jamais un nouveau système d'état

- **Contexte** : un OWNER/ADMIN doit pouvoir suivre qui a confirmé ses
  disponibilités, relancer les retardataires, fixer une date théorique de fin
  d'encodage et lancer une génération — sans dupliquer ce qui existe déjà
  (`AvailabilityCollectionResponse`, `PlanningGenerationService`,
  `PlanningSnapshotService`).
- **Décision — état de collecte** : `PlanningCollectionStatusService` est un
  service de lecture pur, construit au-dessus des `AvailabilityCollectionResponse`
  existantes (D120) et du calendrier personnel (`UserAvailabilityPeriod`, lu en
  direct, jamais copié par planning). Un état à trois valeurs
  (`MemberCollectionState` : `PENDING` / `ACKNOWLEDGED` / `NOT_EXPECTED`),
  jamais déduit du nombre d'indisponibilités — une absence de saisie reste
  `PENDING` tant que personne n'a confirmé, y compris « je n'ai aucune
  indisponibilité » (D120 inchangé). Quand plusieurs collectes existent
  (prolongation), les collectes *ouvertes* sont pertinentes ; s'il n'en reste
  aucune, les dernières collectes closes le restent (historique figé,
  jamais recalculé).
- **Décision — `availabilityDeadline`** : un champ *par planning*, pas par
  collecte : `PATCH /plannings/{id}/settings` fixe en une transaction
  l'échéance de **toutes** les collectes ouvertes (le mécanisme existant,
  `AvailabilityCollection.deadline`, D120) — aucune nouvelle colonne. Nom
  choisi précisément pour ne jamais suggérer une fermeture réelle (jamais
  `availabilityClosesAt`/`lockedAt`). Strictement informative : elle ne bloque
  aucun endpoint (`/my-availability`, confirmation, génération) — testé
  explicitement. Un `deadlineOverdueDays` calculé (jamais stocké) alimente
  l'avertissement visuel « dépassée depuis N jours ».
- **Décision — rappels** : historique **append-only**,
  `PlanningAvailabilityReminder` (`planning`, `recipient`, `sentBy`, `channel`,
  `bulk`, `pendingCollectionCount`, `sentAt`), jamais un champ mutable
  `lastReminderAt` — un trigger Postgres refuse `UPDATE`/`DELETE` sur la table.
  Une ligne n'existe que si le transport a réellement accepté l'email
  (`AvailabilityReminderMailer`, best-effort comme `InvitationMailer`, D114) :
  un échec d'envoi n'est jamais audité comme un rappel envoyé, et ne déclenche
  pas la fenêtre de garde anti-doublon qui suit. Audience = exactement les
  personnes `PENDING` sur au moins une collecte ouverte
  (`AvailabilityCollectionService::pendingResponses()`), jamais recalculée
  autrement. Anti-doublon : une même personne n'est jamais relancée deux fois
  en moins de 5 minutes (`AvailabilityReminderService::MIN_INTERVAL`), et
  `remind()`/`remindPending()` verrouillent la ligne `Planning`
  (`PESSIMISTIC_WRITE`) le temps de l'opération — deux admins qui cliquent en
  même temps sont sérialisés, le second voit l'audit du premier. Le lien de
  l'email pointe vers `/my-availability?collection=<stableId>`, une URL déjà
  stable et publique (jamais un id technique) ; le contenu ne mentionne jamais
  le détail d'une indisponibilité ni les données d'un autre membre.
- **Rejeté** : un job asynchrone (aucune infrastructure de queue n'existe
  encore, l'envoi est synchrone et court, comme les invitations) ; déduire
  « répondu » du nombre d'indisponibilités (interdit par D120) ; un `Voter`
  dédié — tout est `PlanningVoter::MANAGE_AVAILABILITY` (D124), inchangé.

## D128 — `MemberCollectionState` distinct de `AvailabilityResponseStatus`

- **Décision** : `AvailabilityResponseStatus` (`PENDING`/`ACKNOWLEDGED`/
  `WITHDRAWN`) reste le statut d'**une réponse à une collecte**. La vue de
  pilotage a besoin d'un statut **par personne, agrégé sur les collectes
  pertinentes du planning** (une personne peut avoir plusieurs collectes après
  une prolongation) : `MemberCollectionState` (`PENDING`/`ACKNOWLEDGED`/
  `NOT_EXPECTED`) est un type de lecture séparé, jamais une modification du
  premier. `NOT_EXPECTED` couvre un membre parti avant de répondre ou un
  compte désactivé — il n'est compté ni confirmé ni en attente.
- **Pourquoi pas réutiliser `AvailabilityResponseStatus` tel quel** : un même
  enum aurait mélangé deux échelles (une réponse vs une personne), et
  aurait forcé un statut arbitraire quand une personne a plusieurs réponses
  en désaccord (une confirmée, une nouvelle fenêtre encore `PENDING`).

## D129 — Génération au niveau du planning : façade fine, préflight non bloquant, jamais un second solveur

- **Contexte** : le pipeline de génération (`PlanningGenerationService::create`
  → `PlanningSnapshotService::createSnapshot` → `PlanningGenerationService::generate`,
  D106) existe déjà par `PlanningPeriod`, donc par ligne. `Planning` regroupe
  plusieurs lignes (D073) mais n'avait aucun point d'entrée unique pour « générer
  ce planning ».
- **Décision** : `PlanningGenerationLauncher` est une façade qui répète le
  pipeline existant pour chaque `PlanningLine` active, sans réimplémenter ni le
  solveur ni le snapshot. `preflight()` construit un rapport de contrôle à
  partir de données déjà lisibles (statut de collecte via D127, comptage des
  `Duty`/membres, `PlanningRuleSet` actif, statut du `PlanningPeriod`) —
  purement informatif, il ne capture rien. Deux catégories strictement
  séparées (`PreflightIssueCode::isBlocker()`) :
  - **bloquants** (empêchent réellement le pipeline : pas de règle active, pas
    de garde, période `PUBLISHED`/`ARCHIVED`, pas de `SolverParameterSet`) ;
  - **avertissements** (jamais bloquants : membres en attente, deadline
    dépassée, ligne sans membre, période `VALIDATED` qui sera invalidée).
    Aucune donnée organisationnelle (confirmation, deadline) n'est
    jamais un bloqueur — testé explicitement.
- **Snapshot** : le principe `current state → snapshot immuable → generation
  → assignments` (CLAUDE.md) n'est pas touché : le snapshot est pris par
  `PlanningSnapshotService::createSnapshot()` au moment réel du lancement,
  jamais à la deadline ni à un autre instant. Une indisponibilité ajoutée
  après la deadline mais avant le lancement fait partie du snapshot ; une
  autre ajoutée après le lancement ne modifie jamais rétroactivement une
  génération déjà créée — testé avec deux lancements successifs.
- **Concurrence** : un verrou consultatif Postgres par `Planning`
  (`pg_try_advisory_lock`) refuse un second lancement pendant qu'un premier
  tourne (409 `generation_in_progress`), sans toucher au verrouillage
  optimiste existant de `PlanningGenerationService::generate()` (D106) : les
  deux mécanismes restent séparés, à deux granularités différentes (le
  planning entier vs une génération précise).
- **Rejeté** : un deuxième modèle de snapshot ou de solveur ; bloquer la
  génération sur la deadline ou sur les confirmations manquantes (contredit
  explicitement le cahier des charges de ce lot) ; fusionner le nouvel
  endpoint avec `POST /planning-generations/{id}/solve` (routes distinctes,
  le premier orchestrant plusieurs appels du second en substance).

## D134 — Semaine type : composant `WeekStructureEditor` intégré tel quel, boutons de l'app (pas de MUI), pas encore branché

- **Contexte** : le package de design `docs/Design/react_semaine_type/`
  fournit un éditeur de structure hebdomadaire (garde isolée / bloc atomique
  A–D / pas de garde) déjà écrit et testé, avec consigne de ne pas le
  réécrire. Détail : `docs/week-structure.md`.
- **Choix** : copie dans `frontend/src/features/week-structure/` ; logique
  pure `weeklyStructure.ts` et ses tests conservés à l'identique ; composant
  contrôlé (`value`/`onChange`), seule la sélection est interne.
- **Écarts assumés par rapport au package** : le package suppose MUI, que
  l'app n'utilise pas — les boutons passent sur les classes `.btn` de
  `styles/ui.css` (libellés exacts et 48 px au palier `s` conservés) ;
  `tokens.css` du package non copié (toutes ses variables existent dans
  `styles/tokens.css`) ; `aria-label` explicite par tuile (nom accessible
  illisible sinon, réduit à l'initiale en palier `s`) ; `readOnly` dérivé
  plutôt qu'un `setState` dans un `useEffect`.
- **Hors périmètre, volontairement** : aucune page, aucun endpoint, aucune
  persistance. La conversion payload → `DutyPattern` (D052) touche le
  modèle de génération et doit être conçue à part (versionnement, effet
  uniquement sur les générations futures, jamais sur un planning publié).
- **Rejeté** : ajouter MUI pour un seul composant ; réécrire le composant
  dans le style des autres features ; poser les paliers en media queries
  (le composant doit se comporter pareil en colonne latérale ou en pleine
  page).
