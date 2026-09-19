# Fairness — choix techniques

Deuxième couche métier du futur moteur de planification
(`docs/allocation-algorithm.md` §2 : "Éligibilité d'abord, optimisation
ensuite, explication enfin"). Construite directement au-dessus de
l'`EligibilityMatrix` du Lot 4 (`docs/eligibility.md`), pour une
`PlanningGeneration` déjà snapshottée, répond de manière déterministe à :
*pour un candidat donné et une dimension donnée, quelle est sa charge
requise théorique ("target"), et une partie de cette charge est-elle déjà
mécaniquement inévitable ("structurally forced") ?*

**Ce lot n'implémente ni ne simule aucun solveur** : aucun OR-Tools,
aucun CP-SAT, aucun pseudo-solveur glouton, aucun `DutyAssignment`
automatique, aucune orchestration multi-lignes. Le résultat s'arrête
exactement à :

```
PlanningSnapshot + EligibilityMatrix → FairnessContext → OptimizationProblem (mode GENERATE)
```

Voir §11 "Hors périmètre de ce lot".

## 1. Modèle métier (`src/Fairness/`)

Nouveau namespace, au même niveau que `src/Eligibility/` — objets de
domaine purs, sans dépendance, construits à la volée depuis le snapshot,
jamais persistés (§9). Les services qui les construisent
(`RequiredDemandBuilder`, `EffectiveExposureService`,
`FairnessTargetService`, `StructurallyForcedAnalyzer`,
`FairnessContextBuilder`, `OptimizationProblemBuilder`,
`DimensionMembershipCalculator`) restent dans `src/Service/`, comme tout
le reste de la logique métier orchestrée (cohérent avec `CLAUDE.md`).

- **`FairnessDimensionType`** — enum des dimensions réellement
  supportées (§2).
- **`FairnessDimensionKey`** — value object identifiant une dimension.
  Pour `DUTY_TYPE`, porte le `dutyTypeStableId` (jamais un `id`
  auto-incrémenté, même convention que `DutyUnit::getStableKey()` en
  Éligibilité) ; pour les autres types, refuse structurellement qu'un
  `stableId` soit fourni. `toStringKey()` est la seule forme utilisée
  comme clé de tableau PHP (les objets ne peuvent pas être clés de
  tableau) — jamais re-parsée ailleurs dans le domaine.
- **`FairnessDimensionValues`** — value object immuable enveloppant un
  `FairnessDimensionKey → float`, au lieu d'un tableau associatif libre.
  Conteneur réutilisé tel quel pour `dimensionMembership` d'une Duty/d'un
  `DutyUnit`, `requiredDemand`, `effectiveExposure`, `grossTargets`,
  `discretionaryTargets`, `structurallyForcedLoad`. Jamais arrondi
  (§5) ; `withAdded()`/`scaledBy()`/`plus()` renvoient toujours une
  nouvelle instance.
- **`FairnessCandidate`** — `{sourceUserStableId, stints}` : un candidat
  de fairness est une **personne**, jamais un stint de membership isolé
  (§3, D082).
- **`StructurallyForcedUnit`** — `{dutyUnit, sourceUserStableId}` (§6).
- **`OptimizationMode`** — enum `GENERATE | REPAIR | SIMULATE`, présent
  pour la complétude structurelle du contrat (`docs/allocation-algorithm.md`
  §21) mais ce lot ne construit jamais autre chose que `GENERATE` (§10).
- **`CoveragePolicy`** — `{requireStrictFirst, criticalDutyUnitStableKeys}`.
- **`FairnessContext`** — §7. **`OptimizationProblem`** — §10.

## 2. Dimensions supportées

Seules les dimensions ci-dessous sont implémentées, chacune adossée à une
source de donnée réellement existante :

| Dimension | Source réelle |
|---|---|
| `TOTAL_DUTIES` | `Duty.demandType` (compte) |
| `WEIGHTED_WORKLOAD` | `DutyType.workloadValue` |
| `FRIDAY` / `SATURDAY` / `SUNDAY` | `Duty.localDate` (jour de semaine ISO) |
| `DUTY_TYPE:<stableId>` | `Duty.dutyType.stableId` — une dimension par `DutyType` réellement rencontré dans la période |

## 3. Dimensions délibérément non supportées

`docs/allocation-algorithm.md` §6 énumère aussi `weekendGroups`,
`holidays`, `namedHolidays[perHolidayCode]`, `nights` — aucune de ces
quatre n'est implémentée dans ce lot, et aucune ne doit être devinée ou
approximée :

- **`NIGHT`** — aucune notion d'heure de nuit n'est modélisée sur `Duty`
  ou `DutyType` aujourd'hui ; la déduire d'une heure de début/fin serait
  une heuristique inventée, jamais une vraie donnée.
- **`HOLIDAY`** — aucun `HolidayDefinition` n'existe dans le domaine.
- **`NAMED_HOLIDAY`** — dépend de `HOLIDAY`, même raison.
- **`WEEKEND_GROUPS`** — détecter "quels jours un `DutyGroupInstance`
  couvre" pour décider s'il est un "groupe week-end" serait une
  heuristique arbitraire sur le contenu d'un groupe, jamais une donnée
  déclarée. `DutyPattern` n'encode aujourd'hui aucun concept de
  "week-end" — son `name`/`code` est une étiquette libre, jamais une
  source de vérité structurelle.

Ces quatre dimensions restent dans le vocabulaire conceptuel de
`docs/allocation-algorithm.md` §6 (design vivant, pas encore entièrement
implémenté) mais n'apparaissent dans aucun code de ce lot — ni
`FairnessDimensionType`, ni `DimensionMembershipCalculator`, ni aucun
test. À réévaluer quand une vraie source de donnée existera pour chacune
(`DutyType.isNightShift`, un `HolidayDefinition`, une classification
explicite de groupe).

## 4. `DimensionMembershipCalculator` — contribution analytique par Duty

```
dimensionMembership(duty) → FairnessDimensionValues
```

Une `Duty` individuelle contribue toujours `TOTAL_DUTIES += 1`,
`WEIGHTED_WORKLOAD += dutyType.workloadValue`,
`DUTY_TYPE:<stableId> += 1`, et `FRIDAY`/`SATURDAY`/`SUNDAY += 1` selon
`localDate`.

**Pour un `DutyGroupUnit`** : `forDutyUnit()` **somme** la contribution de
chaque Duty constituante — le groupe reste un seul `DutyUnit` atomique
pour l'affectation (§6, `docs/eligibility.md` §6), mais chaque Duty
composant reste une unité analytique distincte pour la fairness. Un
groupe vendredi+samedi+dimanche crédite `TOTAL_DUTIES=3`, `FRIDAY=1`,
`SATURDAY=1`, `SUNDAY=1` — jamais `TOTAL_DUTIES=1`. C'est le principe qui
traverse tout le reste du lot (`RequiredDemandBuilder`,
`StructurallyForcedLoad`) : *atomique pour l'affectation, distinct pour
l'analyse*.

## 5. `RequiredDemandBuilder`

```
requiredDemand(d) = Σ_{Duty REQUIRED de la PlanningPeriod} dimensionMembership(duty)[d]
```

Ne lit **que** les `Duty` dont `demandType = REQUIRED` — les `OPTIONAL`
n'entrent jamais dans `requiredDemand` (`docs/allocation-algorithm.md`
§5). Scopé exclusivement à une seule `PlanningPeriod`/`PlanningLine` —
n'agrège jamais entre plusieurs lignes (§8). Ne dépend ni du nombre de
candidats, ni de l'éligibilité, ni d'un solve futur — une pure lecture
des `Duty` requises de la période.

## 6. `EffectiveExposureService`

```
effectiveExposure(user, d) = Σ_{duty} structuralOpportunity(user, duty) × participationFactor(user, duty.localDate) × dimensionWeight(duty, d)
```

Somme sur **toutes** les Duties de la période, REQUIRED et OPTIONAL
confondues — voir `docs/decisions.md` D084 pour la justification textuelle
(la formule du §5 de `docs/allocation-algorithm.md` ne porte aucun filtre
`demandType`, contrairement à `requiredDemand`).

- **`structuralOpportunity(user, duty)`** — lu directement depuis
  l'`EligibilityMatrix` déjà construite (jamais recalculé ici) : `true`
  sauf si le résultat d'éligibilité du candidat pour l'unité contenant
  cette Duty porte une exclusion qui met `structuralOpportunity` à
  `false` (`docs/eligibility.md` §4 — `MEMBERSHIP_OUT_OF_RANGE`,
  `NON_PARTICIPATION`, `USER_INACTIVE`).
- **`participationFactor(user, date)`** — évalué à la date réelle de
  **chaque** Duty individuelle, jamais une approximation
  début/fin/moyenne de période — lu exclusivement depuis les
  `PlanningSnapshotParticipationPeriod` figés du snapshot (jamais un
  `TeamMemberParticipationPeriod` vivant). Pour un candidat multi-stints
  (§3), le stint couvrant la date de la Duty est sélectionné via
  `FairnessCandidate::stintCovering()`. Absence de période couvrante =
  violation d'invariant de domaine (`\LogicException`), jamais
  silencieusement traitée comme facteur nul — un snapshot valide doit
  toujours couvrir la fenêtre de membership qu'il fige.

**Deux invariants de résistance au gaming, testés explicitement**
(`EffectiveExposureServiceTest`) :

1. `UNAVAILABLE` (déclaration personnelle) ne réduit **jamais**
   `structuralOpportunity`/`effectiveExposure`/target — même principe que
   `docs/availability.md` §2 et `docs/eligibility.md` §4, appliqué ici à
   l'exposition plutôt qu'à l'éligibilité brute.
2. `NON_PARTICIPATION` (fait administratif, par équipe) met bien
   `structuralOpportunity` à `false` et réduit donc `effectiveExposure`/
   target — un fait structurel, pas une déclaration personnelle.

## 7. `FairnessTargetService`

```
grossTarget(user, d) = requiredDemand(d) × effectiveExposure(user, d) / Σ_v effectiveExposure(v, d)
```

- Exposition individuelle nulle → `grossTarget = 0` exactement, jamais
  une division par zéro au niveau individuel.
- Exposition totale nulle pour une dimension → dimension `NOT_APPLICABLE`
  pour tout le monde (`getApplicableDimensions()`), jamais une division
  par zéro globale.
- Targets toujours fractionnaires, jamais arrondis ici — l'arrondi, si
  jamais nécessaire, reste strictement un problème d'affichage
  (`docs/allocation-algorithm.md` §5).

**Invariant de contrôle testé** :
`Σ_user grossTarget(user,d) = requiredDemand(d)` pour toute dimension
applicable, vérifié à des valeurs adversariales
`{0, 0.1, 0.5, 0.9, 1, 2, 10}` et sur au moins une distribution d'équipe
fortement asymétrique (`FairnessTargetServiceTest`).

**Target discrétionnaire** (docs/decisions.md D085) :

```
discretionaryTargetAtSolve(user, d) = max(0, grossTarget(user, d) − structurallyForcedLoad(user, d))
```

Jamais négatif — un candidat dont la charge forcée dépasserait son
`grossTarget` (cas limite mathématiquement possible) voit simplement sa
cible discrétionnaire clampée à 0, jamais un signal de "dette" négatif.

## 8. `StructurallyForcedAnalyzer`

```
STRUCTURALLY_FORCED(dutyUnit, U) = U est l'unique candidat HARD-éligible à dutyUnit
```

- 0 candidat HARD-éligible → aucune unité forcée pour ce `dutyUnit`.
- exactement 1 → forcée sur ce candidat.
- 2 ou plus → aucune unité forcée.

**"HARD-éligible" ≠ `EligibilityResult::eligible`** : seules les
exclusions de tier `HARD` (`ConstraintTier::HARD`) retirent un candidat
de l'ensemble HARD-éligible. Une exclusion `POLICY_HARD` (aujourd'hui
aucune n'est produite — `docs/eligibility.md` §3 — mais le futur
`MAX_DUTIES`/`TEAM_MIN_REST`/`MAX_WEEKENDS` en sera une) ne doit **jamais**
fabriquer un `STRUCTURALLY_FORCED` artificiel : un candidat rendu
`ineligible` uniquement par une contrainte POLICY_HARD reste compté comme
HARD-éligible pour ce calcul. Testé explicitement
(`StructurallyForcedAnalyzerTest::testAPolicyHardExclusionNeverShrinksTheHardEligibleSet`,
matrice construite à la main avec `ExclusionReason::MAX_DUTIES`).

`GLOBALLY_FORCED` (`docs/allocation-algorithm.md` §4.3) reste
délibérément non implémenté — nécessite un `PlanningSolver.checkFeasibility()`
qui n'existe pas encore, hors périmètre de ce lot.

**`StructurallyForcedLoad(user, dimension)`** — pour chaque unité forcée,
crédite `dimensionMembership(dutyUnit)` en entier, donc **à travers
toutes** les dimensions analytiques de cette unité (§4) : un groupe
vendredi+samedi+dimanche forcé contribue `TOTAL_DUTIES += 3`,
`FRIDAY += 1`, `SATURDAY += 1`, `SUNDAY += 1` — jamais un simple `+= 1`
forfaitaire.

## 9. `FairnessContext`

Immuable, strictement scopé à exactement un
`PlanningLine + PlanningTeam + PlanningPeriod + PlanningGeneration +
PlanningSnapshot`. Porte : les candidats (§3 ci-dessous), les dimensions
supportées/applicables, `requiredDemand`, `dimensionMembership` par
`DutyUnit`, `effectiveExposure`/`grossTargets`/`discretionaryTargets`/
`structurallyForcedLoad` par candidat, et les `structurallyForcedUnits`
eux-mêmes — jamais un unique tableau non typé fourre-tout.

`FairnessContextBuilder` est le seul point de construction : il résout le
`PlanningLine` réel derrière la `PlanningPeriod` du snapshot
(`PlanningLineRepository::findOneByPlanningPeriod()`, `\LogicException`
si absent — l'invariant "toute `PlanningPeriod` réelle a une
`PlanningLine`" tient toujours en pratique, `PlanningPeriodLifecycleService::create()`
n'étant jamais appelé ailleurs que depuis `PlanningLineService::addLine()`),
puis enchaîne
`RequiredDemandBuilder → EffectiveExposureService → StructurallyForcedAnalyzer → FairnessTargetService`
et assemble le tout. Il est structurellement impossible de mélanger deux
`PlanningLine`/`PlanningTeam` dans un même `FairnessContext` : tout est
dérivé d'un seul snapshot passé en paramètre au constructeur.

### Candidat = personne, jamais un stint isolé (D082)

`EligibilityMatrix` est indexée par `sourceTeamMemberStableId` (un
**stint** de `PlanningTeamMember`) — `PlanningTeamMemberRepository::findIntersecting()`
peut légitimement capturer plusieurs stints non chevauchants du même
`User` dans un seul snapshot (parti puis revenu dans la fenêtre de la
période). `FairnessContextBuilder::buildCandidates()` regroupe ces
membres par `sourceUserStableId` : un `FairnessCandidate` porte la liste
de tous ses stints, et toutes les quantités du `FairnessContext` sont
indexées par `sourceUserStableId` — jamais par stint. Testé explicitement
(`FairnessContextBuilderTest::testMultipleHistoricalStintsOfTheSameUserAreCountedAsOneCandidate`) :
un même User avec deux stints dans un snapshot ne produit **qu'un** seul
candidat, dont l'exposition/target additionne les deux stints sans double
comptage.

### Isolation multi-Planning

Un `User` peut appartenir à plusieurs `Planning` simultanément (au plus
une `PlanningTeam` active par `Planning`). Chaque `FairnessContext` n'est
construit qu'à partir d'un seul snapshot — donc n'utilise que la
membership, la participation, la non-participation, les Duties,
l'éligibilité et les targets de **ce** snapshot. Testé explicitement
(`FairnessContextBuilderTest`) :

- `testFairnessContextsOfDifferentPlanningsAreFullyIndependent` — deux
  `FairnessContext` d'un même User dans deux Plannings distincts ne
  partagent aucune donnée.
- `testPersonalNonParticipationInOnePlanningNeverAffectsTheOther` — une
  `TeamMemberNonParticipationPeriod` déclarée dans le Planning A (par
  équipe, attachée au `PlanningTeamMember`) n'affecte jamais le contexte
  du Planning B.
- `testPersonalUserAvailabilityIsVisibleFromBothIndependentSnapshotsButNeverReducesExposure` —
  une `UserAvailabilityPeriod` (`UNAVAILABLE`, personnelle, transversale à
  toutes les équipes du User) apparaît légitimement dans les deux
  snapshots, mais ne réduit `structuralOpportunity`/`effectiveExposure`
  dans aucun des deux (§6, invariant 1).

## 10. `OptimizationProblem` et `OptimizationProblemBuilder`

Pipeline complet de ce lot :

```
PlanningGeneration → PlanningSnapshot → EligibilityMatrix
  → [DimensionMembership, RequiredDemand, EffectiveExposure, StructurallyForced, FairnessTargets]
  → FairnessContext → OptimizationProblemBuilder → OptimizationProblem(GENERATE)
```

`OptimizationProblemBuilder::build(FairnessContext)` construit, sans
aucune dépendance à un solveur :

- `mode` — toujours `OptimizationMode::GENERATE` dans ce lot (`REPAIR`/
  `SIMULATE` existent dans l'enum pour la complétude structurelle du
  contrat, jamais produits ici).
- `requiredDutyUnits`/`optionalDutyUnits` — la matrice d'éligibilité
  splittée via `DutyUnit::isRequired()` (`docs/decisions.md` D083).
- `requiredDemand`, `eligibilityMatrix` — repris tels quels du
  `FairnessContext`.
- `structurallyForcedLoad`, `fairnessTargets` — respectivement
  `structurallyForcedLoad` et `discretionaryTargets` (§7) du
  `FairnessContext`, par candidat.
- `dimensionMembership` — par `DutyUnit`, repris du `FairnessContext`.
- `coveragePolicy` — `requireStrictFirst = true`, `criticalDutyUnitStableKeys`
  dérivé de `Duty.criticality = CRITICAL` (union des Duties composant
  chaque unité).

**Champs explicitement exclus** (`docs/allocation-algorithm.md` §21 en
liste d'autres, non backés par une vraie donnée dans ce lot) :
`seedMaterial`, `snapshotHash`, `solverVersion`, `timeoutBudget`,
`changeCostByUnit`, `objectivePhases`. `fixedAssignments` n'existe pas
non plus dans ce lot : aucun concept de verrouillage/fixation sans
ambiguïté n'existe encore pour une génération (`DutyAssignment.locked`
existe en persistance depuis le Lot 3 mais aucun flux applicatif ne
l'alimente encore) — l'ajouter maintenant serait une anticipation.

Testé explicitement (`OptimizationProblemBuilderTest`) : `mode` toujours
`GENERATE` sans aucune donnée de solveur ; séparation correcte
required/optional ; aucune Duty d'une autre `PlanningLine` ne fuit jamais
dans le problème d'une ligne donnée.

## 11. Hors périmètre de ce lot

```
GLOBALLY_FORCED (nécessite PlanningSolver.checkFeasibility, D038 —
  docs/allocation-algorithm.md §4.3)
PlanningSolver, tout adapter OR-Tools/CP-SAT
Tout pseudo-solveur glouton
DutyAssignment automatique (AUTO)
Orchestration multi-lignes (chaque OptimizationProblem reste scopé à une
  seule PlanningLine/PlanningTeam)
NIGHT, HOLIDAY, NAMED_HOLIDAY, WEEKEND_GROUPS (§3 — aucune source de
  donnée réelle aujourd'hui)
objectivePhases, seedMaterial, snapshotHash, solverVersion,
  timeoutBudget, changeCostByUnit, fixedAssignments (§10)
Persistance de FairnessContext/OptimizationProblem (§9 ci-dessous)
```

## 12. Pas de persistance

`RequiredDemand`, `FairnessContext`, `EffectiveExposure`,
`FairnessTargets`, `OptimizationProblem` sont des structures calculées,
reconstruites à la demande depuis le snapshot immuable — comme
`EligibilityMatrix` (`docs/eligibility.md` §9), aucun besoin réel
démontré pour les persister dans ce lot. Aucune table `fairness_targets`
ou équivalente créée.
