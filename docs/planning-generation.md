# PlanningGeneration / Snapshot / Assignment — choix techniques

Lot 3 : la couche de persistance qui représente une tentative de
génération de planning (`PlanningGeneration`), le snapshot immuable des
données utilisées pour cette génération (`PlanningSnapshot` et ses
enfants), et les affectations de gardes (`DutyAssignment`). **L'algorithme
d'optimisation n'est pas implémenté dans ce lot** — voir §9.

> **Mise à jour (lot Planning, `docs/planning.md`)** : `PlanningGeneration`
> reste rattaché à un seul `PlanningPeriod`, donc une seule Team — inchangé
> par l'introduction de `Planning`/`PlanningLine` au-dessus. Chaque
> `PlanningLine` d'un Planning multi-équipe possède ses propres
> générations, indépendantes des autres lignes.

## 1. Principe central

```
current state
→ capture snapshot
→ generation
→ assignments

future state changes
≠ mutation of old snapshot
```

Une `PlanningGeneration` est une tentative de génération pour une
`PlanningPeriod` donnée. Une `PlanningPeriod` peut accumuler plusieurs
générations successives dans le temps — en créer une nouvelle n'écrase ni
ne supprime jamais une génération précédente (CLAUDE.md : l'historique
n'est jamais recalculé depuis l'état courant).

## 2. `PlanningGeneration` et son cycle de statut

```
DRAFT → SNAPSHOTTED → SOLVING → COMPLETED
                              → FAILED
```

> **Statut d'implémentation (Lot 6E, docs/decisions.md D106)** :
> `SOLVING`/`COMPLETED`/`FAILED` sont désormais réels — un vrai solveur
> existe (`docs/planning-solver.md`). `SOLVING` reste utile même en solve
> synchrone : c'est la cible du verrou de concurrence réel
> (`PlanningGeneration::$lockVersion`, `#[ORM\Version]`) qui rend la
> transition `SNAPSHOTTED → SOLVING` atomique face à deux requêtes
> concurrentes — jamais une simple vérification de statut en mémoire.
> `COMPLETED` et `FAILED` sont tous deux terminaux : aucune tentative
> n'est jamais rejouée en place, une nouvelle tentative est toujours une
> nouvelle `PlanningGeneration` (cohérent avec §1 — l'historique n'est
> jamais recalculé). `COMPLETED` couvre indifféremment
> `coverageStatus = COMPLETE` et `= INCOMPLETE` (un résultat PARTIAL réel
> est un résultat métier exploitable, jamais un échec technique) ;
> `FAILED` est réservé à `SolverStatus::UNKNOWN`/`ERROR` ou à
> `existingDataConflict` — zéro `DutyAssignment` n'est jamais créé dans ce
> cas. Détail complet : §13-16 ci-dessous, `docs/planning-solver.md` §37.

Le paragraphe original ci-dessous documentait pourquoi `COMPLETED`/`FAILED`
n'existaient pas au Lot 3 — conservé pour l'historique, plus exact
aujourd'hui : le cahier des charges proposait aussi `COMPLETED`/`FAILED`,
mais ces statuts représenteraient l'issue d'un solve — aucun solveur
n'existait encore à ce lot, donc les inventer aurait créé des états que le
code ne pouvait jamais légitimement atteindre. Le graphe de transitions vit
sur l'enum (`canTransitionTo()`), appliqué uniquement via
`PlanningGeneration::transitionTo()` — même mécanisme que
`PlanningPeriodStatus` (D050).

**Distinction avec `PlanningPeriodStatus`** : ce sont deux machines à
états séparées, à dessein. `PlanningPeriodStatus` (DRAFT → GENERATED →
VALIDATED → PUBLISHED → ARCHIVED, D050) représente le cycle de vie
*métier* de la période dans son ensemble ; `PlanningGenerationStatus`
représente le cycle d'*une tentative* de génération. Ce lot ne fait
avancer `PlanningPeriodStatus` vers `GENERATED` sous aucune condition —
cette transition suppose un vrai moteur de génération (hors périmètre) et
la précondition `PUBLISHED ⇒ coverageStatus = COMPLETE` documentée comme
lacune dans `docs/planning-domain.md` §17 reste donc toujours ouverte.

> **Statut d'implémentation (Lot 6D.1, docs/decisions.md D105)** :
> `PlanningGeneration` porte désormais aussi sa politique de repos —
> `App\Entity\RestPolicyOptions` (`legalMinRestEnabled`/`legalMinRestHours`/
> `teamMinRestEnabled`/`teamMinRestHours`), passée au constructeur et
> jamais mutée ensuite (comme le reste de la génération, seul `$status`
> change après coup). Contrairement à `PlanningRuleSet` →
> `PlanningSnapshotRuleSet` (§3 — nécessaire car le RuleSet *actif* d'une
> équipe peut être réassigné après coup), aucune entité
> `PlanningSnapshotRestPolicy` séparée n'a été créée : une
> `PlanningGeneration` n'étant jamais éditée après création, elle est déjà
> son propre enregistrement historique permanent — lire
> `getRestPolicy()` sur une génération ancienne renvoie toujours
> exactement les valeurs choisies à sa création, jamais une valeur
> "courante" d'un `RuleSet` ou d'une autre génération. Détail complet :
> `docs/planning-solver.md` §36.

## 3. Snapshot : composition et immuabilité

`PlanningSnapshot` est la racine, en relation 1-1 avec sa
`PlanningGeneration` (contrainte unique sur `generation_id`). Elle agrège :

| Entité | Contenu figé |
|---|---|
| `PlanningSnapshotMember` | `sourceTeamMemberStableId`, `sourceUserStableId`, `membershipStart`/`membershipEnd`, `role` (audit uniquement), `active` (ajouté au Lot 4/D067, gèle `User::isActive()` pour `USER_INACTIVE` — voir `docs/eligibility.md`) |
| `PlanningSnapshotParticipationPeriod` | `validFrom`/`validTo`, `participationFactor`, `changeReason` |
| `PlanningSnapshotAvailabilityPeriod` | `sourceAvailabilityStableId`, `type` (UNAVAILABLE/PREFER_DUTY), `startsAt`/`endsAt`, `sourceCreatedAt`/`sourceUpdatedAt` |
| `PlanningSnapshotNonParticipationPeriod` | `sourceNonParticipationStableId`, `startsAt`/`endsAt`, `sourceCreatedAt`/`sourceUpdatedAt` |
| `PlanningSnapshotRuleSet` | `sourcePlanningRuleSetStableId`, `sourceVersion`, `configuration` (copie complète du JSON) |
| `PlanningSnapshotExternalCommitment` (D161, §24) | gardes que les personnes du snapshot tiennent déjà sur les lignes résolues avant celle-ci : `sourceUserStableId`, `sourceLineStableId`, `sourceGenerationStableId`, `sourceDutyStableId`, `startsAt`/`endsAt`, seuils de repos de la génération propriétaire |

**Pas de FK vers les entités vivantes pour l'identité des membres** —
`PlanningSnapshotMember` ne référence `TeamMember`/`User` que par la
*valeur* de leur `stableId` (colonne `uuid` brute, pas une relation
Doctrine). Lire à travers une FK vivante suivrait silencieusement ce que
cette ligne est devenue (renommée, rôle changé, membership fermé) — exactement
ce qu'un snapshot ne doit jamais faire. Les enfants
(`PlanningSnapshotParticipationPeriod`, etc.) référencent en revanche leur
`PlanningSnapshotMember` par une vraie relation Doctrine (`ManyToOne`,
`onDelete: CASCADE`) — ce ne sont que la décomposition du même snapshot,
sans vie propre en dehors de lui.

**Aucun setter nulle part** : `PlanningSnapshot` et tous ses enfants sont
construits une fois et jamais modifiés ensuite. Une nouvelle génération
obtient un nouveau snapshot ; l'ancien n'est jamais édité.

**Fenêtre temporelle** : seuls les éléments dont l'intervalle
intersecte `[planningPeriod.startsAt, planningPeriod.endsAt)` sont
copiés (`TeamMemberRepository::findIntersecting()` et équivalents sur les
trois autres repositories). `TeamMemberParticipationPeriod` est comparé en
dates pures (`DATE`, mêmes bornes que `PlanningPeriod`) ; `UserAvailabilityPeriod`
et `TeamMemberNonParticipationPeriod` sont `TIMESTAMPTZ`, donc les bornes de
la période sont résolues en instants absolus via le fuseau de l'équipe
avant comparaison (même technique que
`DutyMaterializationService::resolveInstant()`).

**Précondition** : un `PlanningRuleSet` `ACTIVE` doit exister pour
l'équipe, sinon `NoActivePlanningRuleSetException` (409) — rien à figer
sinon.

## 4. Choix sur les `Duty` : pas dupliquées dans le snapshot

Vérifié directement dans le code (`backend/src/Entity/Duty.php`) avant de
trancher, comme demandé : `Duty` ne porte **aucune méthode mutante** —
toutes ses propriétés ne sont écrites que dans le constructeur. Une fois
créée, une `Duty` est donc déjà entièrement immuable par construction.

**Décision** : `Duty` reste l'unité métier stable de la `PlanningPeriod` ;
`DutyAssignment` référence `Duty` directement, sans `PlanningSnapshotDuty`.
Dupliquer une donnée déjà immuable n'apporterait aucune garantie
supplémentaire, seulement une source de divergence à maintenir.

## 5. `DutyAssignment` ↔ `PlanningSnapshotMember`

Une affectation porte **à la fois** :

- `teamMember` (la ligne vivante — nécessaire au fonctionnement courant,
  listes, permissions) ;
- `snapshotMember` (la ligne figée de *cette* génération — nécessaire à
  l'interprétation historique si le `TeamMember` change de rôle ou quitte
  l'équipe plus tard).

`DutyAssignmentService::createManual()` résout `snapshotMember` à partir
de `teamMember` via `PlanningSnapshotMemberRepository::findOneBySnapshotAndTeamMemberStableId()`
— si aucune ligne ne correspond (le membre n'était pas présent au moment
du snapshot), `InvalidDutyAssignmentException` (422). Une affectation ne
peut donc jamais être créée avant que la génération soit snapshottée
(`PlanningGenerationNotSnapshottedException`, 409).

`DutyAssignment` porte aussi deux garde-fous redondants directement dans
son constructeur (`\InvalidArgumentException`, même style que
`Duty`/`PlanningPeriod`) : le `Duty` doit appartenir à la `PlanningPeriod`
de la génération, et le `TeamMember` à son équipe. Ce sont des filets de
sécurité contre un bug de service, jamais le chemin de validation
utilisateur (`DutyAssignmentService` valide déjà tout avant construction
et produit les exceptions typées ci-dessus).

**Source forcée** : `DutyAssignmentSource::MANUAL` est toujours imposé
côté serveur (`CreateDutyAssignmentRequest` n'a pas de champ `source`).
Depuis, `AUTO` est écrit par la génération (§13-16) et `SWAP` par un
échange conclu entre deux membres (`DutyReassignmentService::applySwap()`,
`docs/duty-swaps.md`, `docs/decisions.md` D178) — le client ne choisit
jamais la source.

**Unicité** : `UNIQUE(generation_id, duty_id)` — un `Duty` ne peut avoir
qu'une affectation active par génération, mais peut être affecté
différemment dans deux générations historiques distinctes de la même
`PlanningPeriod` (testé : `DutyAssignmentControllerTest::testSameDutyCanBeAssignedInTwoDifferentGenerations`).

**Ce qui n'est pas vérifié ici** : disponibilité, espacement, équité,
règles du `PlanningRuleSet` — c'est le périmètre du futur
`EligibilityService`, explicitement hors de ce lot.

## 6. Concurrence et idempotence

**Snapshot** : `PlanningSnapshotService::createSnapshot()` vérifie d'abord
`generation.status === DRAFT` (chemin rapide, le cas séquentiel courant),
puis s'appuie sur la contrainte unique de `planning_snapshots.generation_id`
— une tentative concurrente qui aurait passé la vérification avant l'autre
échoue au `flush()` avec `UniqueConstraintViolationException`, convertie
en la même `PlanningGenerationAlreadySnapshottedException` (409). Même
schéma que `UserRegistrationService`/`EmailAlreadyUsedException` (D026) :
la contrainte base de données est la vraie garantie, l'exception applicative
en est la traduction propre. Tout le snapshot (racine + membres + enfants +
rule set + transition de statut) est construit dans un seul `flush()`
Doctrine — donc une seule transaction : en cas d'échec, rien n'est
persisté partiellement.

**Assignment** : même technique pour `UNIQUE(generation_id, duty_id)` →
`DuplicateDutyAssignmentException` (409).

## 7. Suppression

| Entité | Politique |
|---|---|
| `PlanningGeneration` | Aucun endpoint de suppression fourni — historique, jamais recalculé (cohérent avec `Duty`/`DutyGroupInstance`, `docs/planning-domain.md` §14) |
| `PlanningSnapshot` et enfants | Jamais supprimés indépendamment de leur génération ; `onDelete: CASCADE` depuis les enfants vers leur parent snapshot existe pour la cohérence du schéma, mais aucun chemin applicatif ne supprime jamais un `PlanningSnapshot` |
| `DutyAssignment` | Aucun endpoint de suppression dans ce lot. La règle "une affectation MANUAL reste supprimable tant que la génération est éditable" est actée conceptuellement mais ne peut être branchée avant qu'"éditable" ait un sens réel (régénération, lot futur) — décision différée, pas un oubli |

## 8. Composite FKs : pourquoi pas ici (écart avec D051)

`docs/planning-domain.md` (D051) utilise des clés étrangères composites
`(id, team_id)` pour garantir en base qu'un enfant référence bien un
parent de la même équipe. `DutyAssignment` a deux invariants du même type
(`duty.planningPeriod === generation.planningPeriod`,
`teamMember.team === generation.team`), mais ce lot les vérifie en
application (`DutyAssignmentService`, avec redondance dans le
constructeur — §5), pas via une FK composite.

**Pourquoi** : appliquer la même technique demanderait d'ajouter une
contrainte `UNIQUE(id, planning_period_id)` sur `duties` et
`UNIQUE(id, team_id)` sur `team_members` — deux tables déjà livrées dans
un lot précédent, pour un unique nouveau consommateur. C'est une
extension de schéma disproportionnée pour ce lot au regard de
`CLAUDE.md` ("pas de refonte massive non justifiée"). À reconsidérer si un
second consommateur a besoin de la même garantie au niveau base.

## 9. Endpoints

```
POST /api/planning-periods/{planningPeriodStableId}/generations
GET  /api/planning-periods/{planningPeriodStableId}/generations
GET  /api/planning-generations/{stableId}
POST /api/planning-generations/{stableId}/snapshot
GET  /api/planning-generations/{stableId}/snapshot
POST /api/planning-generations/{generationStableId}/assignments
POST /api/planning-generations/{stableId}/solve
```

> **Statut d'implémentation (Lot 6E, docs/decisions.md D106)** :
> `POST .../solve` lance un vrai solve synchrone (OWNER/ADMIN uniquement)
> et retourne `{generationStableId, status, strictSolverStatus,
> partialSolverStatus, coverageStatus, assignmentCount,
> unassignedDutyCount, objectiveValues, optimality, solverMetadata,
> diagnostics}` — jamais les structures CP-SAT internes, uniquement le
> modèle `UnsatReport` déjà typé (`docs/planning-solver.md` §27) quand
> `coverageStatus = INCOMPLETE`. Erreurs : 409
> `generation_not_solvable` (pas SNAPSHOTTED, ou déjà en cours/résolue —
> `PlanningGenerationConcurrentSolveException`), 409
> `no_solver_parameter_set` (aucun `SolverParameterSet` semé — précondition
> réelle, jamais un défaut deviné), 409 `stale_generation_data` (une
> `Duty` a été ajoutée à la `PlanningPeriod` pendant le solve). Toute
> logique reste dans `PlanningGenerationService::generate()` — le
> contrôleur ne fait que l'autorisation et le mapping de code HTTP.

Aucune entité Doctrine n'est exposée directement — chaque contrôleur
construit un DTO de lecture à la main
(`PlanningGenerationController::snapshotToArray()`), qui inclut un bloc
`summary` (nombre de membres, d'indisponibilités, de préférences, de
non-participations) pensé pour un futur écran d'administration minimal.

## 10. Autorisations

`PlanningTeamRoleVoter` (renommé depuis `TeamRoleVoter`, docs/decisions.md
D079) étendu avec deux attributs (sujet : `PlanningTeam`) :

- `TEAM_VIEW_PLANNING` — tout membre courant de l'équipe, n'importe quel
  rôle (lecture des générations/snapshots).
- `TEAM_MANAGE_PLANNING` — OWNER/ADMIN uniquement (création de génération,
  snapshot, affectation manuelle).

Pas de nouveau Voter : ces deux attributs suivent exactement le même
schéma que `VIEW_TEAM`/le contrôle "managing role" déjà utilisé pour la
non-participation (D059 et `docs/availability.md` §6).

## 11. Frontend

Aucune UI construite dans ce lot. `docs/planning-domain.md` note déjà
qu'aucun endpoint ne permet de lister les `PlanningPeriod` d'une équipe ni
de découvrir un `teamStableId` par la navigation (D059) — construire même
une UI minimale de génération aurait donc exigé d'élargir significativement
la surface API (lister les `PlanningPeriod`, au minimum), hors du périmètre
explicitement demandé pour ce lot ("privilégie le backend + tests et
documente l'absence d'UI plutôt que d'élargir fortement le scope").

> **Mise à jour lot pilotage (docs/decisions.md D129)** : un point d'entrée
> *planning* existe désormais — `GET /plannings/{id}/generation-preflight` et
> `POST /plannings/{id}/generations` (`PlanningGenerationLauncher`, façade
> fine sur `PlanningGenerationService`/`PlanningSnapshotService`, une ligne à
> la fois) — avec une UI (préflight, avertissements, résultat) dans
> `frontend/src/features/planning/pilot/GenerationModal.tsx`. Les endpoints
> `PlanningGenerationController` par `PlanningPeriod` restent inchangés et
> sont ce que la façade appelle en interne.

## 12. Hors périmètre de ce lot

> **Mise à jour Lot 4** : `EligibilityService`, `EligibilityMatrixBuilder`
> et une première définition réelle de `structuralOpportunity` sont
> désormais livrés — voir `docs/eligibility.md`. Les deux premières lignes
> ci-dessous ne sont donc plus hors périmètre du projet, seulement de
> *ce* lot (Lot 3) ; le reste de la liste demeure exact.
>
> **Mise à jour Lot 5** : `FairnessContext`/`OptimizationProblem` (targets
> pré-solve, `STRUCTURALLY_FORCED`) sont désormais livrés — voir
> `docs/fairness.md`. `SpacingService`/`HolidayFairnessService` restent
> hors périmètre (aucune source de donnée `NIGHT`/`HOLIDAY` ni logique
> d'espacement). `PlanningOptimizationService` (solveur) reste hors
> périmètre du projet dans son ensemble, pas seulement de ce lot.

```
SpacingService / HolidayFairnessService
Preference scoring
DutyAssignmentEvent (append-only, docs/allocation-algorithm.md §19) et
  les statuts PLANNED/PERFORMED/CANCELLED/REPLACED — ce lot ne construit
  que l'état courant de DutyAssignment, jamais son historique d'événements
swap workflow / notifications
régénération intelligente
```

> **Statut d'implémentation (Lot 6E, docs/decisions.md D106)** :
> `PlanningOptimizationService` (le solveur) et `snapshotHash` sont
> désormais résolus — voir §13-16 ci-dessous et `docs/planning-solver.md`
> §37. La détection de concurrence de ce lot (statut + contrainte unique,
> pensée pour un flux séquentiel) reste exacte pour le snapshot lui-même
> (toujours immuable) ; §16 ci-dessous documente le mécanisme distinct
> ajouté au Lot 6E pour la seule donnée qui peut réellement changer sous
> un solve synchrone désormais réel : la liste vivante des `Duty`.

## 13. `SolverParameterSet` (Lot 6E, docs/decisions.md D106)

Entité système (pas par équipe), versionnée, append-only, jamais éditée —
même discipline que `PlanningRuleSet`. Deux champs seulement, tous deux
réellement consommés : `timeoutSeconds` (→ `max_time_in_seconds` CP-SAT,
par appel `Solve()`) et `numWorkers` (remplace le littéral `1` jusque-là
codé en dur dans `CpSatPayloadBuilder`). Version 1 semée directement par
la migration (`timeoutSeconds=60`, `numWorkers=1`) — un choix documenté et
versionné, jamais une constante cachée : contrairement à `LEGAL_MIN_REST`
(D036), un budget de solve est une décision d'ingénierie dont MedVue est
la seule autorité, pas une donnée externe à deviner.
`PlanningGenerationService::generate()` résout
`SolverParameterSetRepository::findLatest()` une fois par solve ; son
absence est une vraie précondition (`NoSolverParameterSetException`, 409),
jamais un défaut silencieux.

## 14. Orchestration réelle : `PlanningGenerationService::generate()`

```
claim SNAPSHOTTED → SOLVING (flush séparé, verrou optimiste réel)
résoudre snapshot + SolverParameterSet
calculer snapshotHash (avant solve) + seed (audit uniquement)
construire OptimizationProblem (EligibilityMatrix → FairnessContext → OptimizationProblem)
solve() réel (OrToolsPlanningSolver)
si résultat exploitable :
  re-vérifier snapshotHash (après solve) — concurrence sur les Duty vivantes
  résoudre chaque DutyAssignmentEdge en (DutyUnit, PlanningTeamMember)
  créer un DutyAssignment AUTO par Duty constituante (groupe = plusieurs lignes, même candidat)
  enregistrer les métadonnées de solve + transitionTo(COMPLETED)
  transitionTo(GENERATED) sur la PlanningPeriod si légal
sinon :
  enregistrer les métadonnées de solve (audit) + transitionTo(FAILED)
  zéro DutyAssignment
un seul flush() final pour tout ce qui précède (hors le claim initial)
```

**Résultat exploitable** (`isUsableOutcome()`) : `strictSolverStatus`
∈ {OPTIMAL, FEASIBLE}, ou `strictSolverStatus = UNSATISFIABLE` avec
`partialSolverStatus` ∈ {OPTIMAL, FEASIBLE} (PARTIAL a produit un résultat
réel, complet ou non). Tout le reste (`UNKNOWN`/`ERROR` côté STRICT, ou
PARTIAL lui-même `UNSATISFIABLE`/`UNKNOWN`/`ERROR`) est un échec — `FAILED`,
zéro affectation, jamais une couverture INCOMPLETE fabriquée pour masquer
une inconclusion technique.

## 15. Persistance `DutyAssignment` AUTO

`DutyAssignmentService::createAuto()` — une vraie frontière distincte de
`createManual()` (jamais un détournement de sa sémantique) :
`DutyAssignmentSource::AUTO` toujours forcé, ne flush jamais elle-même
(conçue pour un usage en lot au sein d'une seule transaction plus large),
accepte un `PlanningSnapshot` déjà résolu au lieu de le rechercher à
chaque appel. Un `DutyGroupInstance` assigné produit un `DutyAssignment`
par `Duty` constituante, toutes vers le même candidat — l'atomicité du
groupe, déjà garantie côté CP-SAT (une seule variable de décision par
groupe, `docs/planning-solver.md` §15), est préservée jusqu'à la
persistance. Une `DutyUnit` non assignée (PARTIAL incomplet) ne reçoit
strictement aucun `DutyAssignment` — jamais un membre fictif, jamais un
enregistrement `UNASSIGNED`, jamais un champ nullable : la non-affectation
reste représentée uniquement par `OptimizationResult.unassignedDuties`.

## 16. Atomicité et concurrence réelles (Lot 6E)

**Atomicité** : `DutyAssignment` AUTO, métadonnées de solve, transition de
statut de la génération et de la `PlanningPeriod` sont tous `persist()`és
en mémoire puis un seul `flush()` final — même discipline que
`PlanningSnapshotService::createSnapshot()` (§6). Un échec de contrainte
unique en cours de flush (testé explicitement en pré-créant une collision)
ne laisse strictement rien de committed pour ce batch.

**Concurrence sur le SNAPSHOTTED → SOLVING** : verrou optimiste Doctrine
réel (`PlanningGeneration::$lockVersion`, `#[ORM\Version]`) — sa propre
petite transaction, avant le solve (potentiellement long). Un second appel
concurrent dont l'`UPDATE ... WHERE lock_version = ?` ne matche plus rien
lève `OptimisticLockException`, traduite en
`PlanningGenerationConcurrentSolveException` (409) — jamais une simple
vérification de statut en mémoire (§2 explique pourquoi ça ne suffit pas).

**Concurrence sur les données vivantes** : `Duty` n'est jamais dupliquée
dans le snapshot (§4) — c'est la seule donnée qu'un solve synchrone
(désormais réel, avec une vraie durée d'exécution) peut voir changer sous
lui. `snapshotHash` (`SnapshotHasher`) recalculé juste avant la
persistance et comparé à celui calculé juste avant le solve : une
différence lève `StalePlanningGenerationDataException` (409), la
génération passe `FAILED`, rien n'est jamais persisté contre une
configuration devenue obsolète en cours de solve.

**`PUBLISHED` ⇒ `coverageStatus = COMPLETE`** : dette documentée depuis le
Lot 3 (`docs/planning-domain.md` §17), enfin fermée —
`PlanningPeriodLifecycleService::transition()` vérifie, uniquement pour la
cible `PUBLISHED`, qu'une `PlanningGeneration` `COMPLETED` avec
`coverageStatus = COMPLETE` existe pour la période ; sinon
`PlanningPeriodNotReadyToPublishException` (409). Aucun endpoint de
publication n'est créé dans ce lot — le point d'intégration est prêt.

## 17. Consultation du planning généré (docs/decisions.md D130)

**Audit préalable** (explicitement demandé avant toute implémentation) :
avant ce lot, `UnsatReport` n'existait que le temps de la réponse HTTP de
`POST .../solve` (§9) — jamais écrit en base. Une garde `REQUIRED` non
couverte, consultée après coup (rafraîchissement, autre session), n'avait
donc aucune raison disponible, y compris juste après un solve réel qui les
avait pourtant calculées.

**Persistance réelle** : `PlanningGeneration.diagnostics` (colonne `JSON`
nullable, migration `Version20260923090000`). `recordSolverRun()` le peuple
à partir du même `UnsatReport` que celui déjà retourné par `/solve`,
uniquement quand `coverageStatus = INCOMPLETE` (jamais pour `COMPLETE`,
jamais pour un snapshot périmé rejeté, §16 — `null` explicite dans ce cas).
`UnsatReportPresenter` est le seul point de sérialisation, partagé par le
contrôleur `/solve` existant et par le nouveau lot — jamais deux mappings
divergents du même `UnsatReport`.

**Nouvel endpoint** (jamais un enrichissement de `POST
.../{id}/assignments`, dont le contrat — fenêtré par mois, filtrable par
personne, uniquement les affectations existantes — est incompatible avec
une vue de couverture pleine période incluant les gardes sans affectation) :

```
GET /api/plannings/{planningStableId}/result?from=...&to=...
```

Autorisation : `PlanningVoter::VIEW` (identique à `/assignments`). Pour
chaque `PlanningLine` active, `PlanningResultService` réutilise
`findMostRecentCompletedByPlanningPeriod` (D125, appliqué indépendamment par
ligne — pas d'historique multi-génération) et construit un DTO de lecture
dédié (`PlanningResultView`/`PlanningResultLine`/`PlanningResultDuty`/
`PlanningResultCandidateReason`, aucune entité Doctrine exposée) :

- **Toute garde `REQUIRED` de la période est listée**, couverte ou non —
  contrairement à `/assignments`, qui ne montre que ce qui existe déjà et
  laisse silencieusement disparaître une garde non couverte.
- Les compteurs de couverture (`requiredDutyCount`/
  `coveredRequiredDutyCount`) portent toujours sur la période complète de la
  ligne, indépendamment d'un filtre `from`/`to` — seule la liste `duties[]`
  retournée est filtrée (même règle que `PlanningAssignmentViewService::
  summarize()`, §11/D125).
- Les raisons d'une garde non couverte viennent uniquement de
  `PlanningGeneration.diagnostics`, indexées par `dutyUnitStableKey`
  (clé du `DutyGroupInstance` pour un groupe, jamais celle d'une `Duty`
  constituante isolée). **Jamais recalculées** : une génération antérieure à
  ce lot (`diagnostics = null`) ou une garde absente de l'`UnsatReport`
  affiche une liste de raisons vide, jamais une exclusion fabriquée (D095).
- Aucune ligne sans génération `COMPLETED` n'est ignorée : elle apparaît
  avec `generationStableId = null`, jamais un faux `0/0`.

**Frontend** : `PersonalPlanningView.tsx` (évolué, pas remplacé) affiche,
en vue équipe, un bandeau de couverture (`CoverageHeader`) et signale
chaque garde non couverte (`UncoveredDuty`, disclosure "Pourquoi ?" avec les
vraies raisons ou un message neutre si aucune n'est disponible) ; la vue par
personne (`/assignments`) est inchangée. `INCOMPLETE` est un état métier
normal, jamais rendu comme une erreur ; aucune donnée solveur/Python brute
n'est exposée.

**Hors périmètre** : validation/publication, `REPAIR`, échanges de garde,
historique de plusieurs générations par ligne, statistiques d'équité.

## 18. Calendrier dynamique : réaffectation manuelle (docs/decisions.md D131)

Le calendrier généré n'est plus figé : une garde (ou tout son bloc atomique
si elle appartient à un `DutyGroupInstance`) peut être réaffectée
manuellement, y compris après publication, sans jamais perdre l'historique.

**Modèle** : `DutyAssignment` gagne un booléen `current` — une ligne n'est
plus jamais mutée, elle est marquée `markSuperseded()` puis remplacée par
une nouvelle ligne `MANUAL`. Un index unique **partiel**
`(generation_id, duty_id) WHERE current` remplace l'ancienne contrainte
stricte. Toute lecture de "l'affectation actuelle" (`/result`,
`/assignments`) filtre désormais `current = true`
(`DutyAssignmentRepository::findForGenerations()`).

**Éligibilité live** : `ReassignmentCandidateService` — jamais
`EligibilityService`/`AssignmentConflictAnalyzer`, tous deux figés sur le
snapshot — relit l'état réel au moment de l'ouverture du modal
(indisponibilités, non-participation, autres gardes courantes du
candidat) et au moment de l'enregistrement (`DutyReassignmentService`,
revalidation complète, jamais une confiance dans la liste affichée).

**Endpoints** :

```
GET  /api/plannings/{planningStableId}/duties/{dutyStableId}/reassignment-candidates
POST /api/plannings/{planningStableId}/duties/{dutyStableId}/reassign
```

Le premier détecte automatiquement le bloc (le `DutyGroupInstance` entier,
ou la garde seule) et renvoie tous les candidats réels de l'équipe, y
compris les non-sélectionnables avec leur vraie raison (jamais masqués).
Le second réaffecte — sauvegarde explicite uniquement : choisir un candidat
dans le modal n'écrit rien tant que "Enregistrer la modification" n'a pas
réussi côté serveur. Payload : `{teamMemberStableId,
expectedCurrentTeamMemberStableId}` — ce dernier est l'identité réellement
revue à l'enregistrement (409 `stale_reassignment` si le calendrier a
changé entre-temps, jamais un écrasement silencieux entre deux
gestionnaires) ; 409 `invalid_candidate` si le candidat choisi n'est plus
valide au moment de sauvegarder. Autorisation : nouvel attribut
`PlanningVoter::MANAGE_CALENDAR`, même population que
`GENERATE`/`MANAGE_AVAILABILITY` (créateur ou OWNER/ADMIN d'équipe).

**Atomicité du bloc** : toutes les Duty constituantes changent ensemble,
dans une même transaction — jamais un split partiel, même en cas d'échec
(rollback intégral). L'écriture utilise deux `flush()` explicites (superseder
puis insérer) à cause de l'index partiel, dérogation documentée à la
convention "un seul flush" (D131).

**Historique** : `DutyAssignmentEvent`, append-only (même patron que
`PlanningAvailabilityReminder`), une ligne par Duty constituante — ancien
assignee (nullable, `null` = garde qui était non couverte), nouvel
assignee, auteur, date, `wasPublished` figé au moment du changement.

**Frontend** : `ReassignmentModal.tsx` (`features/planning/result/`),
ouvert depuis `PersonalPlanningView.tsx` via un bouton "Réattribuer" /
"Attribuer" visible uniquement quand `planning.canManageCalendar` est vrai
(nouveau champ, même calcul serveur que `canGenerate`/
`canManageAvailability`). Aucune écriture optimiste : le calendrier
principal ne change qu'après un 2xx confirmé, puis un rafraîchissement
explicite de `/result`.

**Hors périmètre de ce lot** : statistiques de répartition, préflight +
publication (`PlanningPeriodLifecycleService::transition()` existe déjà
mais reste sans appelant), emails de publication et de modification
post-publication, `REPAIR`, override manuel d'une contrainte HARD/POLICY_HARD.

## 19. Publication : préflight + publication du calendrier courant (docs/decisions.md D133)

`PlanningPeriodLifecycleService::transition()` — jusqu'ici sans appelant
(§9/§18 plus haut) — est enfin invoqué : la publication ne signifie jamais
« le planning devient immuable », seulement « cet état courant du
calendrier est officiellement communiqué ». Le planning publié reste
entièrement modifiable (réaffectation, D131 ; statistiques toujours à
jour, D132).

**Préflight, jamais sur l'historique** : `PlanningPublicationPreflightService`
lit exclusivement l'état courant (`DutyAssignment` `current = true`,
D131) — jamais `OptimizationResult`, le `coverageStatus` figé de la
génération, ni les diagnostics initiaux (ceux-ci restent consultables pour
audit via `/result`, mais ne décident jamais de la publiabilité). Vérifie,
en réutilisant entièrement `ReassignmentCandidateService` (aucune nouvelle
logique de contrainte) :

- gardes `REQUIRED` non couvertes ;
- cohérence des blocs (`DutyGroupInstance`, défense indépendante de
  l'atomicité déjà garantie par le lot précédent) ;
- validité structurelle de chaque affectation courante (membership,
  non-participation, indisponibilité déclarée, compte actif) ;
- conflits (overlap, `LEGAL_MIN_REST`/`TEAM_MIN_REST` si actifs).

**Endpoints** :

```
GET  /api/plannings/{planningStableId}/publication-preflight
POST /api/plannings/{planningStableId}/publish
```

Le premier ne modifie jamais rien. Le second **revalide systématiquement**
côté serveur — jamais confiance dans un préflight chargé côté client
quelques secondes plus tôt. Autorisation : `PlanningVoter::PUBLISH`, même
population que `MANAGE_CALENDAR`.

**Façade Planning sur un lifecycle par ligne** : comme la génération (D129),
`PlanningPeriodStatus` vit sur `PlanningPeriod`, une par `PlanningLine` —
publier "le planning" transitionne chaque ligne active. Concurrence :
verrou consultatif Postgres par Planning, même patron que
`PlanningGenerationLauncher` (D129), espace de noms distinct. Idempotence :
un second `POST /publish` alors que toutes les lignes actives sont déjà
`PUBLISHED` → 409 `already_published`.

**Régénération après publication** : déjà bloquée sans aucun changement —
`PlanningGenerationLauncher::preflight()` marque `PERIOD_LOCKED` dès qu'une
ligne active est `PUBLISHED`/`ARCHIVED`, et `canGenerate()` refuse dès
qu'un seul bloqueur existe, quelle que soit la ligne concernée. Vérifié,
pas reconstruit.

**Frontend** : `PublishModal.tsx` (même patron que `GenerationModal`/
`ReassignmentModal` — sauvegarde explicite, aucune écriture avant la
confirmation serveur), bouton « Publier le planning » dans
`PersonalPlanningView.tsx` visible uniquement si `planning.canPublish`.
Statut affiché (« Publié » / « Non publié ») dérivé du statut réel par
ligne renvoyé par `/publish`, jamais réécrit côté client autrement.

## 20. Matérialisation à la demande depuis la structure hebdomadaire (docs/decisions.md D136)

Avant ce lot, **aucun** contrôleur/service de production n'appelait jamais
`DutyMaterializationService` — chaque `Duty` vue jusqu'ici (démos, smoke
tests) avait été créée à la main. `PlanningGenerationLauncher::preflight()`
appelle désormais `WeeklyDutyCalendarService::ensureMaterialized()` pour
chaque ligne active, *avant* de compter ses `Duty` et de décider
`NO_DUTIES` — sans quoi une ligne dont la structure hebdomadaire vient
d'être configurée resterait bloquée jusqu'à ce qu'un gestionnaire clique
quand même sur « Générer ».

Effet de bord assumé sur une lecture (GET), documenté explicitement dans
le docblock de `PlanningGenerationLauncher` : idempotent (une semaine déjà
matérialisée n'est jamais dupliquée — `DutyGroupInstanceRepository::findOneByPeriodPatternAndAnchor()`/
`DutyRepository::findOneByPeriodPatternAndLocalDate()`), sans effet tant
qu'aucune structure `recurring` n'est configurée pour la ligne (aucune
structure devinée par défaut).

**Piège trouvé et corrigé pendant ce lot** : la première version de ce
hook lisait *tout* `DutyPattern` `active = true` d'une équipe, y compris
des patterns ad hoc construits directement par des tests pré-existants
(`createTwoDutyGroup()`) — jamais destinés à un traitement hebdomadaire
récurrent. Corrigé par un champ dédié `DutyPattern.recurring`
(`DutyPatternRepository::findActiveRecurringByTeam()`, jamais
`findActiveByTeam()`) — détail complet et scénarios de régression :
`docs/decisions.md` D136.

> **Mise à jour D163** : les lignes sont matérialisées dans l'ordre de
> résolution (`PlanningLineOrder`, sources d'abord). Une ligne
> conditionnelle matérialise des gardes `CONDITIONAL` reliées à la garde de
> sa ligne source du même jour ; un jour sans source unique n'est pas
> matérialisé et remonte au préflight (`COVERAGE_SOURCE_MISSING`
> avertissement, `AMBIGUOUS_COVERAGE_SOURCE` bloquant). Tant que la
> génération conditionnelle n'existe pas, un planning ayant une ligne
> conditionnelle était bloqué (`CONDITIONAL_GENERATION_NOT_YET_AVAILABLE`,
> temporaire, **supprimé par D164** — voir §25). Détail : `docs/decisions.md` D163.

Point d'accroche choisi après audit des alternatives :
`PlanningGenerationService::create()` (Lot 3) était trop tard — le blocage
`NO_DUTIES` du préflight intervient avant tout appel à `create()` — et son
docblock affirme explicitement n'avoir jamais changé depuis le Lot 3 ; un
hook plus profond aurait rompu cette garantie documentée sans bénéfice
réel.

## 21. Configuration opérationnelle de bout en bout (docs/decisions.md D138)

Lot qui rend réellement utilisable, de bout en bout, ce que les lots
précédents avaient construit — **aucun nouveau moteur, aucune nouvelle
abstraction algorithmique**. Audit préalable exhaustif (voir D138) :
`PlanningRuleSetConfiguration` reste entièrement inerte (aucun champ lu
par le solveur réel) ; la seule vraie règle de génération consommée
aujourd'hui est la politique de repos (`RestPolicyOptions`, D105), déjà
exposée par `PlanningGenerationController` (par période) mais jamais
threadée jusqu'au lanceur planning-level.

**`PlanningRuleSetController` (`GET`/`POST .../rule-set(/activate)`)** —
nouveau, mais volontairement mince : jamais un formulaire de paramètres,
seulement une porte d'activation. `activate()` envoie systématiquement une
configuration vide ; DRAFT/ACTIVE/RETIRED, version et stableId ne
transpirent jamais côté HTTP — voir le docblock du contrôleur pour l'audit
complet champ par champ. Autorisation : `PlanningVoter::MANAGE_RULE_SET`
(créateur du Planning ou OWNER/ADMIN d'une équipe qui en possède une
ligne) — **jamais** `PlanningTeamRoleVoter::MANAGE_PLANNING`, qui exclut
explicitement le créateur par son propre docblock ; un bug réel de ce
type a été trouvé et corrigé pendant ce lot (voir D138 pour le détail).

**`RestPolicyRequestParser`** — extrait de `PlanningGenerationController`
pour être partagé avec `PlanningLaunchController`, qui accepte désormais
un `?RestPolicyOptions` optionnel sur `launch()` (`runLine()` ne
hardcode plus `RestPolicyOptions::none()`) : un manager choisit, au
moment de générer, les mêmes règles de repos pour toutes les lignes
actives du Planning — jamais une par ligne à ce niveau (le endpoint par
période reste disponible pour ce cas).

**`familyUnitCounts`** — `LaunchLineReadiness` porte désormais le nombre
d'unités REQUIRED par nom d'`AllocationFamily` (calculé une fois via
`DutyUnitFactory`, jamais par comptage de `Duty` bruts — D136 Scénario
F), exposé par le préflight planning-level et rendu dans
`GenerationModal` — noms toujours dynamiques, jamais « Week-end »/
« Semaine » câblés en dur.

**Frontend** — `RuleSetModal.tsx` (nouveau), `GenerationModal.tsx`
étendu : section « Règles de repos » (deux cases à cocher, désactivées
par défaut, jamais de valeur légale devinée), structure de la ligne
rendue depuis `familyUnitCounts`, distinction explicite OPTIMAL/FEASIBLE
dans le résultat (jamais « planning optimal » pour un résultat COMPLETE
non prouvé optimal), et un rendu du diagnostic UNSAT qui réutilise
`UnsatReportPresenter` tel quel — `phrasing`/`disclaimer` d'une
`DiagnosticRelaxation` affichés verbatim, jamais une causalité recalculée
en React ; les codes de raison bruts (`UNAVAILABLE`, `TEAM_MIN_REST`…)
sont traduits en français plutôt qu'affichés tels quels.

**`PlanningStatisticsService`/`StatisticsMemberRow`** — gagne
`countsByFamily` (même principe que `countsByWeekday` : un membre, un
total réel par famille — jamais un jugement) ; les colonnes affichées
sont celles réellement utilisées par la génération concernée d'un
groupe, jamais une liste fixe. `StatisticsPanel` (frontend) ajoute les
colonnes dynamiquement, la clé chaîne vide affichée « Sans famille ».

**UAT navigateur réelle** (voir D138 pour le compte-rendu complet) : un
planning jetable, une vraie activation de règle, une vraie génération
OR-Tools COMPLETE + OPTIMAL avec bloc V/S/D au même titulaire et
statistiques par famille équilibrées, puis une vraie génération
INCOMPLETE provoquée par un `TEAM_MIN_REST` volontairement excessif —
diagnostic et relaxation réels affichés, jamais simulés. Nettoyage
complet vérifié (zéro résidu, 17 tables).

## 22. De la proposition au planning diffusé (docs/decisions.md D143-D148)

Workflow : générer → calendrier vertical multi-lignes (`PlanningCalendar`) →
corrections (remplacer / retirer, bloc toujours entier) → gardes non
attribuées → « Compléter automatiquement » (trous uniquement) → statistiques
→ publier (email + PDF à chaque participant) → planning publié, toujours
éditable → « Modifications non publiées » → republier (email aux seules
personnes dont les propres gardes changent, avec leurs seuls changements et
le PDF actualisé — §30, D172) → rappel du samedi.

| Endpoint | Droit | Rôle |
|---|---|---|
| `GET /api/plannings/{id}/duties/{duty}/reassignment-candidates` | MANAGE_CALENDAR | titulaire courant + remplaçants **réellement** possibles de la même ligne (D144) |
| `POST .../duties/{duty}/reassign` | MANAGE_CALENDAR | remplace le titulaire du bloc entier, revalidation serveur |
| `POST .../duties/{duty}/unassign` | MANAGE_CALENDAR | retire le titulaire du bloc entier (non attribué volontaire) |
| `POST /api/plannings/{id}/complete` | MANAGE_CALENDAR | comble les trous, affectations existantes fixées (D145) |
| `GET /api/plannings/{id}/statistics` | VIEW | + `weightedLoad`, `countsByDutyType` |
| `POST /api/plannings/{id}/publish` | PUBLISH | première diffusion, enregistrée garde par garde (D143) |
| `POST /api/plannings/{id}/republish` | PUBLISH | diffuse les changements depuis la dernière diffusion (D172 : un email personnel par personne concernée) |
| `GET /api/plannings/{id}/publication-state` | VIEW (détails : PUBLISH) | publié ?, dates, changements non diffusés, historique (`recipientCount`, `sentCount`, `failedCount`) |
| `GET /api/plannings/{id}/publication.pdf` | VIEW | PDF de la dernière diffusion |
| `POST /api/plannings/{id}/export` | VIEW (planning publié) | PDF ou Excel du **calendrier courant** — lignes, ordre, noms, titre, période choisis (`docs/planning-export.md`, D150) |
| `PUT /api/plannings/{id}/teams/{t}/members/{m}/role` | MANAGE (créateur) | accorde/retire « Gestionnaire » (ADMIN, D147) |

Commande : `app:duty-reminders:weekly` (D146, cron du samedi).

Tables (migration `Version20260927090000`) : `planning_publications`,
`planning_publication_entries`, `planning_publication_deliveries`,
`weekly_duty_reminders` (toutes append-only) ;
`duty_assignment_events.new_assignment_id` nullable (retrait).

## 23. Génération et complétion asynchrones (docs/decisions.md D149)

« Générer le planning » et « Compléter automatiquement » ne s'exécutent plus
dans la requête HTTP.

Cycle d'une génération :

1. `POST /api/plannings/{id}/generations` (GENERATE) : récupération des jobs
   morts du planning, refus si un job est actif (409 `job_in_progress`),
   préflight (409 `not_launchable` + blockers), insertion d'un `PlanningJob`
   `QUEUED`, message `RunPlanningJob`, **202** `{job}`. Aucune
   `PlanningGeneration` n'est créée ici.
2. Le worker réclame le job (`QUEUED → RUNNING`), démarre le battement, puis
   `PlanningGenerationLauncher::launch()` : préflight refait, et par ligne
   `create → snapshot → generate` (`SNAPSHOTTED → SOLVING → COMPLETED|FAILED`).
3. Fin : `SUCCEEDED` + `outcome` (résumé par ligne, `coverage`
   COMPLETE/INCOMPLETE, diagnostics) — ou `FAILED` + code.

Cycle d'une complétion : identique avec `POST /api/plannings/{id}/complete`
(MANAGE_CALENDAR) et `PlanningCompletionService::complete()` (D145, inchangé :
titulaires courants figés, trous seuls calculés, relecture sous verrou avant
écriture).

Suivi : `GET /api/plannings/{id}/jobs/latest` (VIEW) — le job actif, sinon le
plus récent, jamais le détail technique d'un échec. Travaux abandonnés :
`PlanningJobRecovery` (5 min sans battement, 30 min en file). Exploitation :
`docs/deployment.md` §5 ter.

Tables : `planning_jobs` (index unique partiel « un job actif par
planning »), `messenger_messages` — migration `Version20260927170000`.

## 24. Contraintes entre lignes, par personne (docs/decisions.md D161)

Depuis D160, un même `User` peut appartenir à plusieurs lignes. Les
incompatibilités réelles se raisonnent sur la **personne**, jamais sur le
stint, avec une seule règle (`PersonCommitmentChecker`) pour le chemin figé
et le chemin live.

**Ordre de résolution** — `PlanningLineOrder::activeInResolutionOrder()` :
sources d'abord (sans objet tant qu'aucune ligne conditionnelle n'existe),
puis `position`, puis id. Le lanceur (`PlanningGenerationLauncher`, exécuté
par le worker D149) suit cet ordre ; la complétion aussi.

**Génération** — `PlanningSnapshotService::createSnapshot()` fige, pour
chaque personne du snapshot, les affectations courantes des lignes actives
qui **précèdent** la ligne (`PlanningSnapshotExternalCommitment`, valeurs
seulement). `EligibilityService` en déduit des exclusions locales
(`CROSS_LINE_CONFLICT` HARD, `CROSS_LINE_LEGAL_MIN_REST` HARD,
`CROSS_LINE_TEAM_MIN_REST` POLICY_HARD) sur toute l'unité, sans toucher à
`structuralOpportunity`. Les engagements entrent dans le `snapshotHash`
(uniquement s'il y en a) et sont exposés par
`GET /api/planning-generations/{id}/snapshot`. Une ancienne génération ne
change jamais : une modification ultérieure de l'autre ligne ne touche pas
ses engagements figés.

**Repos entre lignes** — même génération : ses propres seuils (inchangé).
Autre génération : le plus strict des deux (règle active dès qu'une des
deux l'a activée, au plus grand nombre d'heures). Écart égal au minimum
autorisé ; instants absolus.

**Live** — `ReassignmentCandidateService` lit via `PersonCommitmentReader`
tout ce que la personne tient sur toutes les lignes actives : la liste des
candidats, la revalidation à l'écriture et le préflight de publication sont
symétriques (modifier la ligne A voit la ligne B, et inversement).

**Complétion** — ligne après ligne dans l'ordre de résolution ; les trous
qu'une ligne va combler sont des engagements virtuels pour les suivantes ;
la matrice est construite sans les engagements figés, remplacés par le
contrôle live.

Table : `planning_snapshot_external_commitments` — migration
`Version20260928120000`.

## 25. Génération d'une ligne conditionnelle (docs/decisions.md D164)

Une ligne conditionnelle (D162/D163) se génère dans le **même** lancement
que les autres lignes (worker D149, `PlanningGenerationLauncher`), sans
orchestrateur dédié.

**Ordre et précondition** — `PlanningLineOrder` place la source avant la
cible. La cible n'est générée que si sa source a produit une génération
`COMPLETED` **dans ce lancement** ; sinon aucune génération, aucun snapshot,
aucun solve : la ligne remonte `error = source_generation_failed`
(`generationStableId`/`status` à `null`) et le job est `FAILED`.
`PlanningSnapshotService` refuse aussi, avant toute écriture, une cible dont
la source n'a jamais été générée (`ConditionalSourceNotGeneratedException`).

**Demande figée** — le snapshot de la cible fige :

| Table | Contenu |
|---|---|
| `planning_snapshot_demand_policies` | `policyStableId`, `policyVersion`, `sourceLineStableId`, `sourceGenerationStableId` (au plus une par snapshot) |
| `planning_snapshot_demand_triggers` | `triggerStableId`, `userStableId`, `weekdayMask`, `increment` |
| `planning_snapshot_demand_decisions` | une par garde conditionnelle : garde, `sourceDutyStableId`, `weekday`, `sourceUserStableId`, `triggerStableId`, `dayReason`, `required` (TRUE/FALSE/NULL = indéterminé), `reason` (`TRIGGERED_BY_BLOCK` pour un jour requis par son bloc) |

Les titulaires lus sont les affectations courantes de la génération source
de ce lancement. `SnapshotDemandView` relit ces seules valeurs : ni
politique en vigueur, ni `DutyAssignment.current`.

**Problème** — seules les unités requises entrent dans l'`EligibilityMatrix`
et donc dans l'`OptimizationProblem` (bloc entier dès qu'un jour est
déclenché). Une unité non requise ou indéterminée est **absente** — jamais
OPTIONAL.

**Résultats possibles de la cible** :

- aucune unité déclenchée → `COMPLETED`, 0 affectation, snapshot + décisions
  + `snapshotHash` complets ;
- une garde source sans titulaire → unité **indéterminée** (`required =
  NULL`, raison `SOURCE_UNASSIGNED`), absente du problème, cible `COMPLETED`
  pour le reste, couverture du job `INCOMPLETE` ;
- le résultat de lancement de chaque ligne conditionnelle porte
  `demand = {requiredUnitCount, notRequiredUnitCount, undeterminedUnitCount}`.

**Hash** — section `demand` et `coverageSourceStableId` uniquement s'ils
existent : un snapshot indépendant garde sa forme canonique d'avant D164.
Instants normalisés en UTC (bug de fuseau corrigé, voir D164).

**Historique vs live** — l'historique d'une génération ne change jamais.
Le calendrier courant lit les titulaires d'aujourd'hui avec la version de
politique figée par la génération courante de la ligne : une nouvelle
politique s'applique à la **prochaine** génération, jamais au calendrier
déjà généré.

Tables : migration `Version20260929090000`. Tests :
`ConditionalGenerationTest`.

## 26. Calendrier live d'une ligne conditionnelle (docs/decisions.md D165)

Règle : les règles de demande sont celles **figées par la génération
courante** de la ligne (D164), les titulaires source ceux de
`DutyAssignment.current`. L'historique de la génération ne bouge jamais.

| Opération | Effet sur la ligne conditionnelle |
|---|---|
| Réaffectation / retrait sur la ligne source | Rien n'est écrit ; la réponse porte `dependentImpacts` (état avant/après de chaque bloc dépendant) |
| Nouvelle affectation sur un renfort non requis (réaffectation, remplacement d'un titulaire superflu, endpoint bas niveau) | 409 `coverage_not_required` |
| Idem, demande indéterminée | 409 `coverage_undetermined` |
| « Retirer l'affectation » sur un renfort | Retrait append-only (D144), aucun nouvel endpoint |
| « Compléter automatiquement » | Unités = demande **live** requise (titulaires que la source va recevoir dans la même opération compris) ; renforts superflus tenus = unités *load-only* fixées ; indéterminés jamais complétés |

États live (`LiveCoverageState`) : `REQUIRED_ASSIGNED`,
`REQUIRED_UNASSIGNED`, `NOT_REQUIRED_UNASSIGNED`, `NOT_REQUIRED_ASSIGNED`,
`UNDETERMINED` — exposés par `/result` (`demand` par garde conditionnelle,
`undeterminedDutyCount`/`superfluousDutyCount` par ligne), par la liste des
candidats (`assignable`, `notAssignableReason`, `demand`) et par
`dependentImpacts`.

Préflight de publication : non requis + couvert → `superfluousCoverages`
(avertissement) ; indéterminé → `undeterminedDuties` (bloque publication et
republication, et la transition `PUBLISHED`).

Tests : `ConditionalCalendarTest`.

## 27. Publication et sorties avec une ligne conditionnelle (docs/decisions.md D166)

Une seule décision live, prise par `CurrentCalendarReader` (état de chaque
garde conditionnelle, `CalendarCell::isShown()` / `isUndetermined()`),
lue par toutes les sorties du calendrier courant :

| État | Préflight | Publication | PDF de publication | Export | Récapitulatif de republication |
|---|---|---|---|---|---|
| requis + tenu | OK | oui | titulaire | titulaire | normal |
| requis + vide | bloquant | non (règle D143 de republication inchangée) | « Non attribué » | « Non attribué » | « Non attribué » |
| non requis + vide | rien | oui | absent (non enregistré) | absent | absent, ou « Pas de renfort » s'il a changé |
| non requis + tenu | avertissement `SUPERFLUOUS_CONDITIONAL_COVERAGE` | **oui** | titulaire | titulaire | normal |
| indéterminé | bloquant `UNDETERMINED_CONDITIONAL_DEMAND` | non (publication et republication) | — | « Renfort non évalué » | — |

Statistiques : « Cette période » et « Cumul du planning » comptent la
**charge réelle** (toutes les affectations courantes, renfort superflu
compris) ; la **demande** et la **couverture manquante** se lisent dans
`/result` (`requiredDutyCount`, `uncoveredRequiredDutyCount`,
`notRequiredDutyCount`, `superfluousDutyCount`, `undeterminedDutyCount`).

Limite (D145, inchangée) : la complétion garde l'éligibilité figée par le
snapshot ; pour un renfort devenu requis après la génération, une personne
indisponible au moment de la génération reste exclue même si elle est libre
aujourd'hui.

## 28. Interface de la ligne de renfort (docs/decisions.md D167)

- **Configurer** : menu « ⋯ » → « Paramètres de la ligne » → semaine type,
  « Mode de couverture », « Ligne à renforcer », matrice chirurgiens × jours
  (locale jusqu'à « Enregistrer », avertissements du backend affichés).
- **Lire le calendrier** : renfort requis et attribué → le nom ; requis et
  vide → « ⚠ Renfort requis — non attribué » ; non requis et vide → rien ;
  non requis mais tenu → le nom + « Renfort non requis » ; non évaluable →
  « ? Renfort non évalué ». Résumé : gardes et renforts manquants, renforts
  non évalués, renforts superflus.
- **Modifier** : après une réaffectation de la ligne source, ses
  conséquences sur les renforts (`dependentImpacts`) sont résumées dans le
  modal puis sur le calendrier ; un renfort superflu se retire par
  « Retirer le renfort » ; un renfort non requis ou non évaluable n'ouvre
  aucun sélecteur.
- **Publier** : bloquants (gardes, renforts requis, renforts non évalués)
  et avertissements (renforts superflus, jamais bloquants) séparés.
- **Préflight de génération** : « N gardes indépendantes à répartir » ne
  compte que les unités requises des lignes indépendantes ; une ligne de
  renfort est annoncée « renforts déterminés après l'attribution de la ligne
  « X » » (`demandSourceLineName`) — jamais un nombre de renforts avant que
  la ligne source soit attribuée.
- **Vue « toute l'équipe »** : même lecture que le calendrier (un renfort non
  requis et vide n'y figure pas ; « ? Renfort non évalué » ; « Couverture
  complète » jamais annoncée tant qu'un renfort est non évalué).

## 29. Incohérences localisables et complétion en cours (docs/decisions.md D171)

- `GET /publication-preflight` : chaque affectation refusée
  (`invalidAssignments`, `conflicts`) porte `unitStableKey`, `dates`,
  `dutyStableIds` (toutes les gardes du bloc) et `reasonCode`
  (`ExclusionReason`) ; un bloc incohérent (`inconsistentGroups`) porte
  aussi `duty`, `dates`, `dutyStableIds` et `members` (ses titulaires).
- Le calendrier d'un gestionnaire lit ce préflight : panneau « Affectations
  à corriger », marquage de la cellule, « Voir dans le calendrier » ; les
  modales Publier/Republier listent les mêmes éléments en tête. Le contrôle
  ne retarde jamais le calendrier ; en cours ou en échec, il le dit
  (« Contrôle des incohérences en cours… », « n'a pas pu être effectué —
  Réessayer ») : jamais un faux « rien à corriger ».
- Pendant un job, « Compléter automatiquement » devient « Complétion en
  cours… » (ou « Calcul en cours… ») et une ligne d'état s'affiche au-dessus
  du calendrier. La complétion reste possible sur un planning `PUBLISHED`
  (D145) ; elle ne corrige jamais une affectation existante devenue invalide.

## 30. Emails de republication personnalisés (docs/decisions.md D172)

- **Référence** : la dernière `PlanningPublication` (entrées figées, D143) —
  jamais la génération initiale ni les modifications intermédiaires ; A → B
  → A n'est pas un changement. Comparaison par `User` (identité durable,
  plusieurs adhésions possibles, D160).
- **Destinataires** : l'ancien titulaire de chaque garde changée (garde
  retirée) et son nouveau titulaire (garde ajoutée) — personne d'autre ;
  comptes désactivés exclus. Aucun email si rien n'a changé (`no_changes`).
- **Contenu** : un email par personne, « Garde(s) retirée(s) » / « Garde(s)
  ajoutée(s) » avec ses seuls changements, un bloc une fois avec chacune de
  ses dates ; PDF joint = planning général de la version republiée.
- **PDF figé (D173)** : rendu une fois, dans la transaction de la
  publication, et stocké (`planning_publication_documents` : octets,
  SHA-256, nom du planning et période publiés, append-only). Tous les emails
  de cette publication — renvois compris — et « Télécharger le PDF »
  servent ces octets ; l'objet et la période des emails viennent aussi de
  là. Une publication antérieure à D173 n'a pas de document : rendu à la
  volée depuis ses entrées, comme avant (rien n'est reconstruit).
- **Envoi** : `planning_publication_notifications` (une ligne par
  publication × personne, changements figés en JSON) écrite dans la
  transaction de la publication, envoyée juste après le commit par
  `PublicationNotificationSender` ; réservation atomique avant chaque envoi
  (jamais d'envoi parallèle, jamais de renvoi d'un email enregistré
  `SENT`) ; échecs retentés par `app:publication-notifications:retry`
  (cron, `docs/deployment.md` §5 quater) jusqu'à 5 tentatives, puis
  visibles (`failedCount`). Doublon possible uniquement si SMTP a accepté
  le message juste avant une coupure, avant l'enregistrement `SENT` (D172).
- **Première publication** : inchangée (chaque participant, PDF joint),
  désormais par la même boîte d'envoi (donc retentée elle aussi).
