# MedVue

## But de l'application

MedVue remplace **Lifen Planning** pour la gestion des gardes médicales.
Objectif final : permettre à des équipes médicales de gérer leurs gardes sur
plusieurs années **sans perdre ni l'historique ni l'équité**, avec un
système fiable là où l'existant repose sur du planning manuel ou
semi-automatique.

L'application doit gérer, à terme :

- plusieurs **équipes**, chacune avec ses membres, ses rôles, ses règles ;
- plusieurs **périodes de planning** par équipe, distinctes de l'équipe
  elle-même ;
- les **indisponibilités** des utilisateurs, déclarées une seule fois dans
  un calendrier global partagé par toutes leurs équipes ;
- la **génération automatique équitable** des gardes (moteur dédié, hors
  contrôleurs) ;
- l'**historique permanent** des affectations, jamais recalculé depuis
  l'état courant ;
- les **échanges de garde** et les **notifications**.

Le cahier des charges complet (40 sections : modèle de données détaillé,
règles d'équité multi-dimensionnelle, contraintes dures/souples, snapshot
de génération, etc.) a été fourni au lancement du projet et guide toutes
les décisions de modélisation à venir.

## Principe non négociable : l'équité est multidimensionnelle

Le moteur de planning (à venir) ne devra **jamais** optimiser un score
global unique — un utilisateur sans week-end pourrait compenser par de
nombreuses petites gardes de semaine. Chaque dimension (score, week-ends,
vendredis/samedis/dimanches, jours fériés, espacement, préférences) doit
être suivie et équilibrée séparément, avec des contraintes dures/souples
clairement distinguées et un résultat explicable (pas de boîte noire).
Ce principe structure tout le modèle de données à venir — à garder en tête
dès qu'une entité liée aux gardes ou aux plannings est conçue. Design
détaillé (encore conceptuel, rien d'implémenté) :
[`docs/allocation-algorithm.md`](docs/allocation-algorithm.md).

## État d'avancement

| Étape | Statut | Référence |
|---|---|---|
| Socle technique (Symfony + React + Docker) | ✅ Livré | `README.md` |
| Authentification (User, register/login/me, rate limiting, access+refresh token rotatif) | ✅ Livré + UAT navigateur complète (2026-09-15, `AUTHENTIFICATION MEDVUE : PASS`) | `docs/authentication.md` §14 |
| Équipes (`PlanningTeam`, propriété exclusive d'un `Planning`), rôles | ✅ Restructuration livrée (2026-09-18) : plus d'entité globale, création inline par ligne, endpoints + UI de gestion des membres ; invitations par email ajoutées ensuite (ligne « Inscription enrichie ») | `docs/planning.md` |
| Disponibilités (calendrier personnel, non-participation administrative) | ✅ Livré (2026-09-16) | `docs/availability.md` |
| Campagnes de collecte de disponibilités | ⏳ Pas commencé | — |
| Génération : `PlanningGeneration`/`PlanningSnapshot`/`DutyAssignment` (persistance, pas d'algorithme) | ✅ Livré (2026-09-16) | `docs/planning-generation.md` |
| Éligibilité : `EligibilityService`/`EligibilityMatrixBuilder` (sous-ensemble de raisons, pas de solveur) | ✅ Livré (2026-09-16) | `docs/eligibility.md` |
| Planning multi-lignes : `Planning`/`PlanningLine` (agrégat visible, moteurs de ligne mono-équipe) | ✅ Livré (2026-09-16) | `docs/planning.md` |
| Fairness : `FairnessContext`/`OptimizationProblem` abstrait (targets, structurally forced, pas de solveur) | ✅ Livré (2026-09-18) | `docs/fairness.md` |
| Frontière `PlanningSolver` + 8 phases d'objectif GENERATE (contrat abstrait, pas de solveur concret) | ✅ Livré (2026-09-18) | `docs/planning-solver.md` |
| `OrToolsPlanningSolver` : OR-Tools CP-SAT réel, STRICT GENERATE, lexicographique | ✅ Livré (2026-09-18) | `docs/planning-solver.md` |
| STRICT → PARTIAL, priorité CRITICAL, diagnostic UNSAT structuré (`UnsatReport`) | ✅ Livré (2026-09-18) | `docs/planning-solver.md` |
| Contraintes globales `CONFLICT`/`TEAM_MIN_REST` (`AssignmentConflict`) — priorité CRITICAL réellement observable | ✅ Livré (2026-09-19) | `docs/planning-solver.md` |
| Politiques de repos par génération : `LEGAL_MIN_REST`/`TEAM_MIN_REST` deviennent des options `RestPolicyOptions` figées par `PlanningGeneration`, jamais un défaut d'équipe | ✅ Livré (2026-09-19) | `docs/planning-solver.md` §36, `docs/decisions.md` D105 |
| Orchestration réelle `PlanningGeneration → solve → DutyAssignment AUTO` : `SolverParameterSet`, seed/snapshotHash, timeout CP-SAT réel, concurrence par verrou optimiste, atomicité, `PUBLISHED` ⇒ coverage COMPLETE | ✅ Livré (2026-09-19) | `docs/planning-generation.md` §13-16, `docs/planning-solver.md` §37, `docs/decisions.md` D106 |
| Moteur de génération avancé (`fixedAssignments` réels, REPAIR, SIMULATE, MAX_DUTIES/MAX_WEEKENDS, UI, validation/publication avancée) | ⏳ Design conceptuel écrit, pas implémenté | `docs/allocation-algorithm.md` |
| Inscription enrichie (téléphone E.164, **sans hôpital** : l'établissement n'est pas une propriété du `User`) + invitations d'équipe (`TeamInvitation`, emails Mailer/Twig, inscription par lien, multi-invitations) | ✅ Livré (2026-09-20), UAT navigateur OK. Affiliation hospitalière : à modéliser plus tard dans un contexte daté (D115), pas d'import ni de référentiel | `docs/authentication.md` §15, `docs/decisions.md` D111-D116 |
| Refonte de l'interface (charte, tokens, mobile d'abord) : cadre, connexion/inscription/invitations, tableau de bord, plannings, calendrier d'indisponibilités en **jour entier** (sélection multiple, tactile) | ✅ Livré (2026-09-21) — tests Vitest, vérifié dans un navigateur | `docs/decisions.md` D117-D119, `docs/availability.md` §8 |
| Collecte des disponibilités par fenêtre (`AvailabilityCollection`/`Response`), prolongation d'un planning, participation du créateur, planning par personne, calendrier optimiste sans « Enregistrer » | ✅ Livré (2026-09-21) — tests backend/frontend + UAT navigateur complète (deux comptes, deux onglets) | `docs/availability-collection.md`, `docs/decisions.md` D120-D126 |
| Vue de pilotage OWNER/ADMIN d'un planning : statut de collecte par membre (drawer, indisponibilités de la période), rappels email individuels/groupés (audit append-only), paramètre `availabilityDeadline` informatif, préflight + génération au niveau du planning (façade sur le pipeline existant, snapshot pris au lancement) | ✅ Livré (2026-09-23) — tests backend/frontend + UAT navigateur complète (génération réelle OR-Tools bout en bout, email réel via Mailpit, immutabilité du snapshot vérifiée) ; vérification mobile non complétée (limite de l'environnement de test, cf. rapport) | `docs/availability-collection.md` §11/§13/§14, `docs/planning-generation.md` §11, `docs/decisions.md` D127-D129 |
| Échanges de garde, notifications (in-app), export calendrier | ⏳ Pas commencé | — |

## Où trouver quoi

- **`docs/decisions.md`** — journal chronologique de chaque choix technique
  structurant, avec son contexte et ses compromis. **À consulter avant de
  remettre en cause une décision existante**, et à compléter à chaque
  nouvelle décision non triviale.
- **`docs/authentication.md`** — modèle de données, stratégie JWT, sécurité,
  décisions ouvertes de la fonctionnalité d'authentification. Un document
  du même type sera créé pour chaque fonctionnalité majeure suivante
  (`docs/teams.md`, …).
- **`docs/availability.md`** — modèle de données, sémantique
  `UNAVAILABLE`/`PREFER_DUTY`, non-participation administrative,
  chevauchement, autorisations et endpoints du Lot 2 (disponibilités).
- **`docs/planning-generation.md`** — modèle de données, cycle de statut,
  composition et immuabilité du snapshot, relation `DutyAssignment` ↔
  `PlanningSnapshotMember`, concurrence, autorisations et endpoints du
  Lot 3 (`PlanningGeneration`/`PlanningSnapshot`/`DutyAssignment` —
  persistance uniquement, pas d'algorithme de génération). Depuis le Lot
  6D.1, `PlanningGeneration` porte aussi sa politique de repos figée
  (`RestPolicyOptions`, D105) — voir §2. Depuis le Lot 6E,
  `PlanningGenerationService::generate()` orchestre un vrai solve
  (`SolverParameterSet`, seed/snapshotHash, `DutyAssignment` AUTO,
  atomicité, concurrence par verrou optimiste, `PUBLISHED` ⇒ coverage
  COMPLETE) — voir §13-16, `docs/decisions.md` D106.
- **`docs/eligibility.md`** — modèle métier d'éligibilité
  (`ExclusionReason`/`ConstraintTier`/`DutyUnit`), raisons réellement
  calculables vs seulement déclarées, sémantique de
  `structuralOpportunity`, atomicité des groupes de gardes, endpoint
  d'audit du Lot 4 (`EligibilityService`/`EligibilityMatrixBuilder` —
  toujours pas de solveur ni d'affectation automatique).
- **`docs/planning.md`** — l'agrégat `Planning`/`PlanningLine` visible
  côté utilisateur, ligne PRIMARY/SECONDARY, isolation stricte des
  populations par ligne (mono-équipe, moteur inchangé), autorisations
  creator-only, `PlanningTeam` propriété exclusive d'un Planning et créée
  inline par sa ligne, règle "une adhésion ouverte par Planning, jamais
  par Team seule" (D079/D080), endpoints et UI du lot Planning +
  restructuration Team.
- **`docs/availability-collection.md`** — collecte des disponibilités par
  fenêtre : "répondu" = événement explicite distinct de
  `UserAvailabilityPeriod` (D120), extension d'un planning (D122), participation
  du créateur (D123), autorisations (D124), lecture des affectations par
  personne (D125), calendrier optimiste (D126).
- **`docs/fairness.md`** — `FairnessContext` (dimensions supportées/non
  supportées, `RequiredDemand`, `EffectiveExposure`, targets bruts et
  discrétionnaires, `STRUCTURALLY_FORCED`) et l'`OptimizationProblem`
  abstrait (mode `GENERATE` uniquement dans ce lot) construits au-dessus
  de l'`EligibilityMatrix` — toujours pas de solveur ni de
  `DutyAssignment` automatique.
- **`docs/planning-solver.md`** — interface `PlanningSolver`
  (`solve`/`checkFeasibility`), `OptimizationResult` (`SolverStatus`,
  `CoverageStatus`, jamais confondus), les 8 phases lexicographiques
  explicites de `GENERATE` (`ObjectivePhase`/`ObjectivePhaseFactory`), et
  `OrToolsPlanningSolver` (`src/Solver/`) — premier solveur réel (OR-Tools
  CP-SAT en subprocess Python, aucun binding PHP officiel n'existe),
  aucune dépendance OR-Tools dans le domaine. Bascule STRICT → PARTIAL
  automatique sur UNSAT prouvé (jamais sur UNKNOWN/ERROR), priorité
  CRITICAL, et `UnsatReport` — diagnostic UNSAT structuré (exclusions
  locales réelles, jamais de fausse causalité). `AssignmentConflict`
  (`CONFLICT` HARD, `TEAM_MIN_REST` POLICY_HARD) — première contrainte
  globale reliant deux `DutyUnit`, calculée dans le domaine
  (`AssignmentConflictAnalyzer`), jamais recalculée par Python ; la
  priorité CRITICAL est désormais réellement observable.
  `diagnosticRelaxations` peut désormais proposer réellement de relâcher
  `TEAM_MIN_REST`, uniquement après un vrai re-solve confirmant
  l'amélioration — `LEGAL_MIN_REST` reste HARD et ne peut structurellement
  jamais y apparaître. `MAX_DUTIES`/`MAX_WEEKENDS`/`MAX_CONSECUTIVE_NIGHTS`
  restent non implémentées malgré une configuration réelle existante.
  `FakePlanningSolver` réservé aux tests. `LEGAL_MIN_REST`/`TEAM_MIN_REST`
  sont des options par génération (`App\Entity\RestPolicyOptions`),
  jamais un défaut global d'équipe ni une durée devinée automatiquement
  (§36, D105). Depuis le Lot 6E : timeout CP-SAT réel et `numWorkers`
  réellement transmis via `SolverParameterSet` (versionné, système,
  jamais une constante cachée), `snapshotHash`/`seed` réels (calculés par
  `SnapshotHasher`/`SeedMaterialBuilder`, le seed reste non consommé par
  le solve — phase de tie-break toujours neutre), `algorithmVersion`
  versionné manuellement (`OptimizationProblemBuilder::ALGORITHM_VERSION`)
  — voir §37, `docs/decisions.md` D106.
- **`docs/authentication.md` §15** — inscription enrichie (téléphone E.164 ;
  l'hôpital n'est **pas** une donnée du profil, D115) et **invitations d'équipe** : `User` ≠ `TeamInvitation`
  (jamais de faux `User`), token haché à usage unique, inscription par lien
  atomique (multi-invitations → un `User`, N memberships, un email), compte créé
  entre-temps, emails (`backend/templates/email/`, maquette
  `docs/Design/emails_medvue`), variables d'env requises en production.
- **`docs/allocation-algorithm.md`** — design du moteur de répartition des
  gardes (équité multidimensionnelle, contraintes, pipeline de
  génération). **Document vivant** : encore conceptuel, à corriger et
  compléter à chaque étape d'implémentation réelle du moteur — jamais
  laisser le document diverger silencieusement du code une fois que
  celui-ci existe.
- **`docs/deployment.md`** — déploiement de production MedVue (séquence,
  checks de santé, mises à jour, interdits en production dont
  `docker compose down -v`, journal des incidents du premier déploiement).
  **`docs/backup.md`** — sauvegardes PostgreSQL + clés JWT, rétention,
  procédures de restauration et limites connues (D107/D108/D109).
- **`README.md`** — arborescence, choix techniques, commandes pour lancer
  le projet, URLs, tests exécutés.

## Conventions de travail sur ce projet

- **Aucune logique métier dans les contrôleurs** — elle vit dans des
  services dédiés (`src/Service/`). Les contrôleurs orchestrent
  (désérialisation, validation, appel au service, mise en forme de la
  réponse), rien de plus.
- **API Platform seulement là où c'est pertinent** — pas de ressource CRUD
  générée automatiquement sur une entité sensible (ex. `User`, voir
  `docs/decisions.md` D009) sans réflexion explicite sur ce qui doit
  réellement être exposé.
- **Un planning publié n'est jamais supprimé**, seulement archivé.
  L'historique des affectations n'est jamais recalculé depuis l'état
  courant — il doit rester disponible même si un membre quitte l'équipe,
  qu'une période est archivée, ou que les règles de score changent
  ensuite.
- **Progression incrémentale** : avant toute implémentation importante,
  analyser l'existant, proposer le modèle de données, identifier les
  contraintes, vérifier les impacts, implémenter, tester, documenter.
  Pas de refonte massive non justifiée.
- **Tests systématiques** pour toute fonctionnalité importante (unitaires
  + fonctionnels côté backend, au minimum le flux principal côté
  frontend), et cas limites couverts explicitement (voir la liste de la
  section 39 du cahier des charges original : membre ajouté/quitté en
  cours d'année, désactivation, impossibilité mathématique de remplir un
  planning, etc.) au moment où la fonctionnalité concernée est construite.

## Démarrer le projet

Voir `README.md` (`docker compose up -d --build`, URLs, commandes de
test/lint). Résumé : backend Symfony sur http://localhost:8010, frontend
React sur http://localhost:5183, PostgreSQL sur `localhost:5432`.
