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
côté serveur (`CreateDutyAssignmentRequest` n'a pas de champ `source`) —
`AUTO`/`SWAP` restent réservés au futur moteur et au futur workflow
d'échange.

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
