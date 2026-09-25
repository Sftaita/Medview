# Solveur — frontière abstraite, phases d'objectif, adapter OR-Tools CP-SAT

Troisième couche du futur moteur de planification, directement au-dessus
du Lot 5 (`docs/fairness.md`) :

```
Eligibility → Fairness → OptimizationProblem → PlanningSolver → OrToolsPlanningSolver → OR-Tools CP-SAT
```

**Document vivant**, comme `docs/allocation-algorithm.md` — mis à jour à
chaque lot touchant le solveur plutôt que réécrit.

> **Lot 6A** : a posé la frontière abstraite (`PlanningSolver`,
> `OptimizationResult`, `ObjectivePhase`/`ObjectivePhaseFactory`, 8 phases
> GENERATE explicites) sans aucun solveur concret. Voir §1-§12
> ci-dessous.
>
> **Lot 6B** (`OrToolsPlanningSolver`, §13-) : premier solveur réel —
> OR-Tools CP-SAT, exécuté en subprocess Python (aucune dépendance
> OR-Tools n'est jamais importée par le domaine ni même par PHP), résout
> le mode GENERATE en **STRICT** (couverture complète obligatoire) avec
> exécution lexicographique réelle des phases. Pas encore de PARTIAL, pas
> de persistance automatique — voir §21 "Hors périmètre de ce lot (Lot
> 6B)".
>
> **Lot 6C** (STRICT → PARTIAL, §22-) : quand STRICT est prouvé
> `UNSATISFIABLE`, `OrToolsPlanningSolver::solve()` bascule automatiquement
> sur un solve PARTIAL (`unassigned[d]` slack, priorité CRITICAL), et
> construit un vrai diagnostic UNSAT structuré (`UnsatReport`) — plus
> jamais une interface marqueur vide. Toujours pas de persistance
> automatique, toujours pas de `fixedAssignments`/timeout réels — voir §29
> "Hors périmètre de ce lot (Lot 6C)".
>
> **Lot 6D** (contraintes globales, §30-) : première contrainte reliant
> réellement deux `DutyUnit` entre eux (`AssignmentConflict` —
> `CONFLICT` HARD, `TEAM_MIN_REST` POLICY_HARD) — le solveur doit
> désormais réellement arbitrer entre affectations concurrentes (D099
> n'est plus vraie pour ces deux règles). La priorité CRITICAL devient
> observable dans de vrais scénarios CP-SAT ; `diagnosticRelaxations`
> produit sa première vraie relaxation, confirmée par un re-solve réel.
> `MAX_DUTIES`/`MAX_WEEKENDS`/`MAX_CONSECUTIVE_NIGHTS` restent non
> implémentées malgré une configuration réelle existante — voir §35
> "Hors périmètre de ce lot (Lot 6D)".

## 1. Audit préalable — ce qui existait, ce qui manquait

Avant toute modification, relecture de `docs/allocation-algorithm.md`
(§11, §21), `docs/planning-domain.md`, `docs/planning-generation.md`,
`docs/decisions.md`, et audit du code du Lot 5 (`OptimizationProblem`,
`FairnessContext`).

- **`OptimizationProblem`** portait déjà `mode`, `requiredDutyUnits`,
  `optionalDutyUnits`, `requiredDemand`, `eligibilityMatrix`,
  `structurallyForcedLoad`, `fairnessTargets`, `dimensionMembership`,
  `coveragePolicy` — mais **pas** `objectivePhases`, délibérément omis
  par le Lot 5 ("une traduction solveur que ce lot n'atteint jamais").
  C'est précisément le manque que ce lot comble.
- **Aucun contrat `PlanningSolver`/`OptimizationResult`** n'existait —
  `docs/allocation-algorithm.md` §21 les décrivait, rien n'était codé.
- **Écart structurel trouvé** : §11 écrit "Phase 4 : minimize
  maxDeviation(SECONDARY) puis sumDeviation(SECONDARY)" sur une seule
  ligne, alors que le paragraphe suivant précise explicitement que le
  passage min-max-puis-somme "s'applique à PRIMARY **et** SECONDARY (pas
  seulement PRIMARY)" — ambigu tel quel sur le nombre réel de phases.
  Tranché en **D087** : 8 phases distinctes, jamais fusionnées.
- **Aucune classification PRIMARY/SECONDARY des dimensions** n'existait
  ni dans le `FairnessContext` du Lot 5 ni dans `PlanningRuleSet`
  (`configuration` reste un JSON libre sans schéma documenté pour une
  telle clé) — combler ce manque sans deviner un besoin de configuration
  par équipe non demandé est **D086**.
- **Aucune matière de seed réelle** (`snapshotHash`, `SolverParameterSet`,
  `algorithmVersion`) n'existe encore (`docs/allocation-algorithm.md`
  §14 le confirme explicitement) — la phase de tie-break reste donc
  structurelle, sans donnée réelle (**D088**).

## 2. `PlanningSolver` (`src/Fairness/PlanningSolver.php`)

```php
interface PlanningSolver
{
    public function solve(OptimizationProblem $problem): OptimizationResult;

    /** @param list<DutyAssignmentEdge> $excludedEdges */
    public function checkFeasibility(OptimizationProblem $problem, array $excludedEdges): SolverStatus;
}
```

Zéro référence à OR-Tools, CP-SAT, ou toute bibliothèque de solveur.
Substituable par un fake de test, `OrToolsPlanningSolver` (Lot 6B), ou
tout autre solveur, sans jamais toucher le domaine (`docs/decisions.md`
D031). `checkFeasibility()` existe dès le Lot 6A — socle de
`GLOBALLY_FORCED`/`ForcedAssignmentAnalyzer` (§4.3), toujours pas
implémenté lui-même.

> **Mise à jour Lot 6B (`docs/decisions.md` D091)** : `checkFeasibility()`
> retourne `SolverStatus`, pas `bool` — un simple booléen ne peut pas
> représenter `UNKNOWN`/`ERROR` sans mentir (les coder en `false`
> laisserait croire à une infaisabilité *prouvée*). `OPTIMAL`/`FEASIBLE`
> signifient tous deux "une solution existe" pour cet appel sans objectif.

`OrToolsPlanningSolver` (`src/Solver/`, Lot 6B) est désormais la première
implémentation de production — voir §13.

## 3. `OptimizationResult` (`src/Fairness/OptimizationResult.php`)

```
strictSolverStatus  : SolverStatus
partialSolverStatus : SolverStatus|null   (null sauf STRICT=UNSATISFIABLE puis PARTIAL tenté, §10)
coverageStatus      : CoverageStatus
assignments         : list<DutyAssignmentEdge>
unassignedDuties     : list<UnassignedDutyUnit>
objectiveValues      : array<ObjectivePhaseId::value, float>
optimality           : array<ObjectivePhaseId::value, bool>
diagnostics          : UnsatDiagnostics|null   (toujours null dans ce lot)
snapshotHash         : string|null             (toujours null dans ce lot)
solverMetadata       : SolverMetadata|null      (toujours null en dehors des tests)
```

**`SolverStatus`** : `OPTIMAL | FEASIBLE | UNSATISFIABLE | UNKNOWN |
ERROR` — trois concepts jamais confondus : `UNKNOWN` (résolution non
concluante) n'est **jamais** assimilé à `UNSATISFIABLE` (résultat
prouvé) ; `ERROR` (échec technique du solveur) n'est **jamais** confondu
avec un `UNSATISFIABLE` métier. `strictSolverStatus`/`coverageStatus`
sont deux axes indépendants : un `FEASIBLE` peut être `INCOMPLETE`, un
`OPTIMAL` sur le problème PARTIAL n'est `COMPLETE` que si son slack est
resté vide (`docs/allocation-algorithm.md` §10).

**`CoverageStatus`** : `COMPLETE | INCOMPLETE`.

**`DutyAssignmentEdge`** `{dutyUnitStableKey, sourceTeamMemberStableId}`
— identifiants stables, jamais un id auto-incrémenté ni une référence
d'objet (D042). Granularité **stint**, pas personne — cohérent avec
D082 : une vraie future `DutyAssignment.teamMember` référence un
`PlanningTeamMember`, jamais un `User` directement.

**`UnassignedDutyUnit`** `{dutyUnitStableKey, critical}` — volontairement
plus étroit que la forme complète future
(`{dutyUnitId, criticality, candidateExclusions}`) : `candidateExclusions`
appartient au modèle UNSAT complet (§16), hors périmètre de ce lot.

**`UnsatDiagnostics`** — interface marqueur vide. `OptimizationResult`
porte `?UnsatDiagnostics`, toujours `null` aujourd'hui, pour qu'un futur
type concret n'ait qu'à l'implémenter sans casser la signature.

**`SolverMetadata`** `{solverType, solverVersion,
solverParameterSetVersion, solveDurationMs, timeoutHit, parameters}` —
aucun code de production n'en construit d'instance ; seul
`FakePlanningSolver` (tests) le fait.

## 4. Phases d'objectif (`src/Fairness/ObjectivePhase*.php`)

Représentation typée, pas de `{phase: int, type: "CUSTOM", payload:
array}` générique :

- **`ObjectivePhaseId`** (enum string) — identité stable de chaque phase.
- **`ObjectiveDirection`** (enum string) — `MINIMIZE`/`MAXIMIZE`.
- **`ObjectivePhase`** — `{id, direction, dimensions}`, construction
  uniquement via des fabriques statiques nommées (une par phase réelle)
  qui figent la paire `(id, direction)` correcte — impossible de
  construire, par exemple, une phase `MAX_DEVIATION_PRIMARY` avec la
  direction `MAXIMIZE`. `$dimensions` est une `list<FairnessDimensionKey>`,
  vide pour les quatre phases dont la métrique n'est pas une quantité par
  dimension (fériés nommés, espacement, préférences, tie-break).

Aucune technologie de solveur n'apparaît nulle part dans ces types.

## 5. Phases exactes du mode `GENERATE`

```
1. maxDeviationPrimary       MINIMIZE  dimensions PRIMARY
2. sumDeviationPrimary       MINIMIZE  dimensions PRIMARY
3. maxDeviationSecondary     MINIMIZE  dimensions SECONDARY
4. sumDeviationSecondary     MINIMIZE  dimensions SECONDARY
5. namedHolidayRepetitionPenalty  MINIMIZE  (aucune dimension — métrique par holidayCode, hors périmètre fairness — reste NEUTRAL)
6. spacingScore              MAXIMIZE  (aucune dimension)
7. preferenceSatisfaction    MAXIMIZE  (aucune dimension)
8. deterministicTieBreak     MINIMIZE  (aucune dimension — convention, D088 — reste NEUTRAL)
```

> **Statut d'implémentation (docs/decisions.md D139)** : les phases 6
> (`spacingScore`) et 7 (`preferenceSatisfaction`) sont désormais
> réellement résolues par CP-SAT — jusque-là `NEUTRAL` (aucune donnée,
> `objectiveValueScaled` toujours 0, `optimal` toujours vrai
> trivialement) depuis leur introduction structurelle par D087. Deux
> nouveaux kinds de payload : `SPACING_PENALTY` (une variable booléenne
> "ET" par paire de `DutyUnit` pénalisée × candidat, objectif = somme
> négée des pénalités — direction `MAXIMIZE` inchangée, donc
> `objectiveValueScaled = 0` reste le meilleur score possible) et
> `LINEAR` (somme simple de coefficients 1, réutilise
> `build_phase_expression()` tel quel, aucune valeur absolue). Les
> phases 5 et 8 restent `NEUTRAL` (hors périmètre explicite de D139).
> Détail complet du modèle, tests et résultats avant/après sur un cas
> réel : D139.

8 phases distinctes, jamais fusionnées (D087) — testé explicitement
(`ObjectivePhaseFactoryTest::testExactOrderOfPhases`,
`testGenerateReturnsExactlyEightPhases`). Ordre garanti indépendant de
l'ordre d'itération des dimensions fournies en entrée
(`testPhaseOrderNeverDependsOnDimensionIterationOrder`), et déterministe
à construction identique (`testDeterministicAcrossRepeatedConstruction`).

**Aucune somme pondérée globale** — chaque phase reste une entrée
distincte et ordonnée d'une liste, jamais combinée en un score composite
(D032). Aucun coefficient arbitraire nulle part dans ce code.

## 6. `ObjectivePhaseFactory` (`src/Service/ObjectivePhaseFactory.php`)

```php
forMode(OptimizationMode $mode, list<FairnessDimensionKey> $applicableDimensions): list<ObjectivePhase>
```

`GENERATE` entièrement supporté. `REPAIR`/`SIMULATE` échouent
explicitement (`\LogicException`), jamais un ordre partiel ou incorrect
deviné — testé
(`ObjectivePhaseFactoryTest::testRepairModeFailsExplicitly`,
`testSimulateModeFailsExplicitly`). `SIMULATE` ne devient jamais un
troisième ordonnancement autonome (`docs/decisions.md` D041) : une fois
implémenté, il réutilisera l'ordre du mode simulé, jamais un cas `match`
séparé avec sa propre liste de phases.

Consomme `FairnessContext::getApplicableDimensions()` (jamais
`getSupportedDimensions()`) — une dimension `NOT_APPLICABLE` a une
target nulle pour tout le monde et n'a rien à suivre en déviation
(`docs/fairness.md` §7).

## 7. Classification PRIMARY/SECONDARY (D086)

`FairnessDimensionClassifier` (interface) + `DefaultFairnessDimensionClassifier`
(implémentation par défaut). La classification par équipe (lire un futur
`PlanningRuleSet.configuration` structuré) **n'est pas implémentée** —
`FairnessDimensionClassifier` reste une interface à une seule
implémentation, délibérément conçue swappable (interface + alias
`services.yaml`) pour qu'une future implémentation basée sur le RuleSet
remplace `DefaultFairnessDimensionClassifier` sans toucher
`ObjectivePhaseFactory`.

**Le défaut métier est réel, ce n'est pas une constante inventée** :
`docs/allocation-algorithm.md` §6 définit explicitement PRIMARY =
`WEEKEND_GROUPS` + `NAMED_HOLIDAY`. Ce défaut a été audité une seconde
fois après une première livraison de ce lot (question explicite posée à
l'utilisateur, réponse consignée dans D086) — la conclusion reste la
même : `primaryDimensions()` renvoie volontairement `[]` aujourd'hui, non
par choix de conception, mais parce que `WEEKEND_GROUPS`/`NAMED_HOLIDAY`
n'existent pas comme dimensions réellement calculées
(`docs/fairness.md` §3 — aucun `HolidayDefinition`, aucune classification
de groupe week-end, donc aucun `requiredDemand`/`effectiveExposure`/
`dimensionMembership` pour elles). Ajouter ces deux dimensions
uniquement comme identités de classification, sans leur chaîne de calcul
réelle, a été explicitement écarté : ça romprait l'invariant "jamais une
dimension sans source de donnée réelle" tenu depuis le Lot 4/5, et la
dimension ne pourrait de toute façon jamais apparaître dans un
`OptimizationProblem` réel (`FairnessContextBuilder::deriveSupportedDimensions()`
ne la produirait jamais).

Les phases 1-2 (max/sum PRIMARY) existent donc structurellement
aujourd'hui (8 phases toujours) mais portent une liste de dimensions
vide. `weightedWorkload`/`totalDuties` restent toujours classées
SECONDARY, jamais PRIMARY par accident — testé explicitement
(`DefaultFairnessDimensionClassifierTest`, `ObjectivePhaseFactoryTest`) :
PRIMARY vide aujourd'hui, toute dimension supportée classée SECONDARY,
PRIMARY et SECONDARY disjoints, aucune dimension absente de l'entrée
jamais inventée.

**Chemin pour un futur lot** qui implémenterait réellement
`WEEKEND_GROUPS`/`NAMED_HOLIDAY` — dans cet ordre, le classifier étant la
dernière pièce à toucher, jamais la première : `FairnessDimensionType` →
`FairnessDimensionKey` → `RequiredDemandBuilder` →
`EffectiveExposureService` (si nécessaire) →
`DimensionMembershipCalculator` →
`FairnessContextBuilder::deriveSupportedDimensions()` → seulement alors
`DefaultFairnessDimensionClassifier::primaryDimensions()`.

## 8. Préservation lexicographique

Le contrat n'encode pas encore la mécanique d'appels `Solve()`
successifs d'un adapter OR-Tools — mais la représentation en liste
strictement ordonnée de `ObjectivePhase` (jamais un ensemble non ordonné,
jamais une somme pondérée) rend explicite pour un futur adapter que la
phase N+1 doit s'exécuter dans l'espace de solutions qui préserve la
valeur optimale de la phase N. Rien dans cette représentation n'empêche
ou ne rend ambiguë cette sémantique.

## 9. Tie-break (D088)

`ObjectivePhase::deterministicTieBreak()` — direction `MINIMIZE`
conventionnelle (aucune sémantique "plus grand est meilleur" naturelle
pour un tie-break ; convention documentée, pas une exigence de la
spécification). Aucune matière de seed réelle construite : `seedMaterial`
nécessite `snapshotHash`/`algorithmVersion`/`solverParameterSetVersion`,
dont `docs/allocation-algorithm.md` §14 confirme qu'aucun n'existe encore
dans le code. La phase reste une identité structurelle pure, sans
paramètre — le gap est documenté ici plutôt que comblé par une valeur
inventée.

## 10. `FakePlanningSolver` (test uniquement)

`tests/Fairness/FakePlanningSolver.php` implémente `PlanningSolver` :
capture le dernier `OptimizationProblem` reçu par `solve()`, retourne un
`OptimizationResult` préconfiguré par le test, capture chaque appel à
`checkFeasibility()` (problème + edges exclues) et retourne une
faisabilité préconfigurée. **Aucune logique gloutonne** — jamais un
pseudo-solveur de production (`docs/decisions.md` D031 : le projet a
explicitement écarté l'approche "greedy d'abord").

## 11. Immutabilité

`OptimizationProblem`, `ObjectivePhase`, `OptimizationResult` sont tous
`final readonly` avec propriétés promues — une tentative d'écriture
externe sur une propriété ou d'ajout dans un tableau de propriété
readonly lève une `Error` PHP (`Cannot modify readonly property`), testé
explicitement
(`ObjectivePhaseFactoryTest::testPhaseDimensionsCannotBeMutatedFromOutside`).
Les tableaux PHP étant des types valeur (copy-on-write), une collection
retournée par un getter ne peut de toute façon pas être mutée en retour
vers l'objet source — verrouillé par un test de régression explicite
(`FakePlanningSolverTest::testMutatingAnExposedCollectionNeverAffectsTheProblem`,
`testSolveNeverMutatesTheProblemItReceives`).

## 12. Hors périmètre du Lot 6A (résolu ou toujours vrai — voir §21 pour le Lot 6B)

```
OR-Tools, CP-SAT, toute dépendance de solveur installée         — Lot 6B : résolu (§13)
Mapping des variables x[duty,candidate]                          — Lot 6B : résolu (§15)
Transformation STRICT → PARTIAL, slack unassigned[d]              — toujours hors périmètre (§21)
Diagnostic UNSAT complet, infeasible cores, relaxations POLICY_HARD — toujours hors périmètre (§21)
Analyse exhaustive GLOBALLY_FORCED, ForcedAssignmentAnalyzer complet — toujours hors périmètre (§21)
Orchestration PlanningPipeline complète (STRICT puis PARTIAL, §10) — toujours hors périmètre (§21)
Persistance réelle des résultats du solve                        — toujours hors périmètre (§21)
DutyAssignment AUTO                                               — toujours hors périmètre (§21)
REPAIR, SIMULATE (phases + implémentation)                        — toujours hors périmètre (§21)
seedMaterial/tieBreakKey réels (D088 — ingrédients absents)       — toujours hors périmètre (§21)
notifications, frontend                                          — toujours hors périmètre (§21)
```

Le Lot 6B (§13-§21) ajoute l'adapter `OrToolsPlanningSolver` et
l'exécution lexicographique réelle, exactement dans la frontière posée
ici — aucune modification n'a été nécessaire dans `src/Fairness/` (à
part `objectivePhases`, déjà prévu au Lot 6A) ni dans `src/Eligibility/`.

## 13. `OrToolsPlanningSolver` — intégration OR-Tools (Lot 6B)

**OR-Tools n'a aucun binding PHP officiel** — seuls C++, Python, Java et
.NET sont officiellement supportés par Google. `docs/decisions.md` D031
avait déjà anticipé et tranché ce point avant même que le code existe :
*"dépendance opérationnelle à un solveur externe (subprocess, pas un
microservice HTTP au démarrage)"*. Le Lot 6B applique cette décision à la
lettre — aucune architecture improvisée n'a été nécessaire :

```
App\Solver\OrToolsPlanningSolver (PHP, implements PlanningSolver)
  → Symfony\Component\Process\Process
  → bin/cp_sat_solver.py (Python, package PyPI officiel `ortools`)
  → ortools.sat.python.cp_model (CP-SAT)
```

Un seul appel subprocess par `solve()`/`checkFeasibility()` — pas un
appel par phase : le script Python construit le modèle CP-SAT une fois,
puis boucle en interne sur les phases lexicographiques, réutilisant le
même `CpModel`/`CpSolver` (ajout de contraintes de verrouillage entre
phases, jamais de reconstruction).

**Package et version** : `ortools==9.15.6755` (PyPI), dernière version
disponible au moment du lot — installée dans un venv Python dédié
(`/opt/ortools-venv`, `backend/Dockerfile`) plutôt que dans le Python
système, pour respecter la protection PEP 668 de Debian 13 (l'image de
base, `dunglas/frankenphp:1-php8.3`) sans `--break-system-packages`. La
version exacte a été découverte empiriquement (un premier essai avec une
version choisie a priori — `9.11.4210` — a échoué au build : `pip` a
listé les versions réellement publiées, dont la dernière a été retenue et
figée) — jamais devinée.

**Compatibilité runtime confirmée** : `docker compose exec backend
/opt/ortools-venv/bin/python3 -c "from ortools.sat.python import cp_model; ..."`
résout `OPTIMAL` sur un modèle trivial — vérifié manuellement pendant le
lot, puis re-vérifié par chaque test d'intégration
(`OrToolsPlanningSolverTest`, qui invoque le vrai subprocess).

`OrToolsPlanningSolver` n'importe **aucun** symbole OR-Tools/CP-SAT —
seul `bin/cp_sat_solver.py` le fait. `App\Solver\` (namespace dédié,
distinct de `App\Fairness\`/`App\Service\`) rend cette frontière
auditable par simple recherche de fichiers : aucun fichier hors
`src/Solver/` et `bin/cp_sat_solver.py` ne référence OR-Tools/CP-SAT.

## 14. Aucune logique métier dans l'adapter

`OrToolsPlanningSolver`/`CpSatPayloadBuilder`/`cp_sat_solver.py` ne
recalculent **aucune** formule métier — ils traduisent uniquement des
valeurs déjà produites par le domaine :

| Donnée consommée | Source réelle |
|---|---|
| Paires éligibles | `EligibilityMatrix::getForDutyUnit()` |
| `requiredDutyUnits`/`optionalDutyUnits` | `OptimizationProblem` |
| Poids par dimension d'une DutyUnit | `OptimizationProblem::getDimensionMembership()` |
| Target discrétionnaire par personne | `OptimizationProblem::getFairnessTarget()` |
| Charge forcée par personne | `OptimizationProblem::getStructurallyForcedLoad()` |
| Phases, ordre, direction, dimensions PRIMARY/SECONDARY | `OptimizationProblem::getObjectivePhases()` |

Le solveur ne connaît ni la timeline de membership, ni
`participationFactor`, ni `structuralOpportunity`, ni la formule de
target — exactement l'exigence du lot.

## 15. Modèle CP-SAT

**Variables** — pour chaque `(DutyUnit, candidat)` réellement éligible
(`EligibilityResult::eligible === true`), une seule `BoolVar`. Aucune
variable n'est créée pour une paire inéligible — *infeasible-by-
construction* — testé explicitement
(`CpSatPayloadBuilderTest::testVariableExistsOnlyForAnEligiblePair`).

**Couverture STRICT** — `requiredDutyUnit` : `AddExactlyOne(vars)`.
`optionalDutyUnit` : `AddAtMostOne(vars)` — jamais forcé, mais jamais
doublement assigné non plus (un même nœud du problème reste un seul nœud,
`docs/allocation-algorithm.md` §9, que sa couverture soit obligatoire ou
non). Aucun `UNASSIGNED`/slack/dummy candidate dans ce lot.

**Groupes atomiques** — un `DutyGroupInstance` est déjà un seul `DutyUnit`
(`DutyUnit::getStableKey()`, Lot 4) : une seule variable par
`(groupe, candidat)`, jamais une par Duty constituante — l'atomicité
découle de la structure du modèle, aucune contrainte ajoutée après coup.
Testé explicitement
(`CpSatPayloadBuilderTest::testGroupIsRepresentedAsExactlyOneUnitNeverOnePerConstituentDuty`).

**Fixed assignments** — `docs/decisions.md` D090 : `OptimizationProblem`
ne porte toujours aucun `fixedAssignments` (confirmé absent à l'audit de
ce lot, `DutyAssignment.locked` jamais renseigné par un flux réel). Le
payload envoie toujours un ensemble vide — aucun verrouillage `x[d][c]=1`
n'est jamais construit dans ce lot. Testé explicitement
(`CpSatPayloadBuilderTest::testFixedAssignmentsAreAlwaysEmptyNoConceptExistsYet`).

**HARD/POLICY_HARD** — audit confirmé : `EligibilityService` (Lot 4) ne
produit aujourd'hui **aucune** raison `POLICY_HARD`
(`MAX_DUTIES`/`MAX_WEEKENDS`/`TEAM_MIN_REST`/`MAX_CONSECUTIVE_NIGHTS`/
`RULE_EXCLUSION` — aucune n'est jamais émise, `docs/eligibility.md` §3).
Toutes les contraintes HARD réellement calculables aujourd'hui sont donc
déjà entièrement capturées par l'existence/absence d'une variable
(`constraint enforced by eligibility`) — `OrToolsPlanningSolver` n'ajoute
lui-même **aucune** contrainte HARD/POLICY_HARD globale
(`constraint enforced globally by CP-SAT` : ensemble vide dans ce lot).
Rien de plus n'est codé — coder une contrainte `MAX_DUTIES` sans paramètre
réel aurait été inventer une donnée.

## 16. Fairness — scaling entier

Voir `docs/decisions.md` D092 pour la justification complète : `CpSatScale::SCALE
= 10_000`, dérivé de la précision réelle de `DutyType.workloadValue`
(`decimal(6,2)`, donc `smallestUnit(WEIGHTED_WORKLOAD) = 0.01`) ;
`smallestUnit = 1.0` pour les dimensions comptées en gardes entières.

**Déviation normalisée, mise à l'échelle sans division dans le
solveur** — `CpSatPayloadBuilder` calcule, pour chaque
`(personne, dimension)` d'une phase non neutre :

```
scale(personne,d)  = max(fairnessTarget(personne,d), smallestUnit(d))
perUnitCoeff        = SCALE / scale(personne,d)                         // ratio, calculé une fois en PHP
terme(personne,d)   = Σ_unit dimensionMembership(unit,d) × perUnitCoeff × x[unit][stint-de-la-personne]
                       − (structurallyForcedLoad(personne,d) + fairnessTarget(personne,d)) × perUnitCoeff
```

Toute la division a lieu ici, en PHP, contre des constantes déjà connues
avant le solve — **jamais** une division à l'intérieur de CP-SAT. Un
candidat multi-stints (D082) voit ses variables sommées à travers *tous*
ses stints. Un candidat sans aucune arête éligible contribue quand même
un terme constant (`−(forced+target)×perUnitCoeff`), jamais ignoré —
testé (`OrToolsPlanningSolverTest::testCandidateWithNoEligibleEdgeAnywhereDoesNotBreakTheSolve`).
`fairnessTarget = 0` ne divise jamais par zéro (`scale = max(0,
smallestUnit) = smallestUnit > 0` toujours) — testé
(`CpSatPayloadBuilderTest::testZeroFairnessTargetNeverDividesByZero`).

`maxDeviation` : variable entière `worst`, `worst ≥ terme_i` et
`worst ≥ −terme_i` pour chaque terme, `Minimize(worst)`. `sumDeviation` :
une variable `abs_i` par terme (mêmes deux contraintes), `Minimize(Σ
abs_i)` — la linéarisation standard de la valeur absolue en programmation
linéaire entière, aucune division ni contrainte non linéaire.

## 17. Phases neutres — sémantique précise

Une phase est **neutre** exactement quand sa liste `terms` (construite par
`CpSatPayloadBuilder`) est vide — jamais déduit d'autre chose. C'est
toujours le cas pour les 4 phases sans dimension dans ce lot
(fériés nommés, espacement, préférences, tie-break — aucune donnée réelle,
`docs/decisions.md` D088) et pour `MAX_DEVIATION_PRIMARY`/
`SUM_DEVIATION_PRIMARY` (PRIMARY toujours vide, D086).

Une phase neutre : n'est **jamais** résolue (`cp_sat_solver.py` ne
construit ni variable `worst`, ni `abs_i`, ni appel `Solve()`
supplémentaire pour elle) ; est enregistrée `attempted: true, neutral:
true, optimal: true, objectiveValueScaled: 0` — trivialement satisfaite,
jamais une erreur, jamais une donnée fabriquée ; ne modifie jamais le
résultat des phases suivantes (rien à verrouiller). Conséquence directe :
les phases 5-8 (fériés/espacement/préférences/tie-break), toujours
neutres dans ce lot, ne peuvent **structurellement** jamais dégrader une
phase antérieure — testé explicitement
(`OrToolsPlanningSolverTest::testNeutralPhasesNeverDegradeAnythingHoldingNoRealDataInThisLot`,
`testPrimaryPhasesAreNeutralAndFabricateNothing`).

## 18. Exécution lexicographique réelle

```
Step 0  Solve() sans objectif — feasibility de base (HARD + couverture)
        garantit qu'au moins un vrai Solve() a lieu même si toutes les
        phases sont neutres
si INFEASIBLE/UNKNOWN/ERROR : arrêt, aucune phase tentée

pour chaque phase, dans l'ordre :
  si neutre : enregistrer trivial, continuer (aucun Solve())
  sinon :
    définir l'objectif (MAX_DEVIATION → worst, SUM_DEVIATION → Σ abs)
    Solve()
    si OPTIMAL : verrouiller (`expr ≤ V` si MINIMIZE, `expr ≥ V` si MAXIMIZE
                 — jamais une égalité stricte, qui sur-contraindrait sans
                 bénéfice puisque V est déjà la valeur prouvée), continuer
    si FEASIBLE (non prouvé optimal) : arrêt de la chaîne (D089),
                 phases restantes jamais tentées
    si UNSATISFIABLE/UNKNOWN/ERROR : arrêt de la chaîne

assignments lus depuis le dernier Solve() réellement exécuté
```

**Politique `FEASIBLE`** (`docs/decisions.md` D089, aucune décision
préexistante ne tranchait ce cas précis) : la solution conservatrice de
la spécification du lot a été retenue après audit — une phase non finale
`FEASIBLE` (non prouvée optimale) arrête la chaîne, jamais traitée comme
`OPTIMAL`. Une phase jamais tentée est **absente** de
`objectiveValues`/`optimality` (jamais `0.0`/`false` fabriqués) —
distingue "jamais atteinte" de "neutre, trivialement satisfaite".

## 19. Mapping des statuts (§19)

Un seul vocabulaire partagé — `SolverStatus`'s propres valeurs
(`OPTIMAL`/`FEASIBLE`/`UNSATISFIABLE`/`UNKNOWN`/`ERROR`) servent
**directement** de chaînes JSON échangées avec `cp_sat_solver.py`
(`STATUS_MAP` côté Python, `SolverStatus::tryFrom()` côté PHP) — une
seule source de vérité, jamais deux mappings qui pourraient diverger.
`CP-SAT MODEL_INVALID` et toute exception Python non prévue deviennent
`ERROR` ; toute sortie non-JSON ou absente du subprocess devient `ERROR`
côté PHP (`OrToolsPlanningSolver::runProcess()`). `UNKNOWN` n'est
**jamais** transformé en `UNSATISFIABLE` — testé explicitement avec un
script de test dédié qui simule une réponse `UNKNOWN` sans dépendre du
comportement réel (difficile à provoquer) de CP-SAT
(`OrToolsPlanningSolverTest::testUnknownStatusIsNeverMappedToUnsatisfiable`,
`tests/Solver/fixtures/echo_unknown.py`).

## 20. Déterminisme

`num_search_workers = 1` (recherche mono-thread, déterministe) et
`random_seed = 0` (constante technique fixe, **distincte** du futur
tie-break métier D088 — ce paramètre gouverne uniquement les heuristiques
internes de CP-SAT, jamais un choix de candidat) — documentés,
explicites, jamais cachés. Multi-thread (`num_search_workers > 1`)
apporterait de la vitesse au prix d'une reproductibilité plus faible
(recherche en portefeuille parallèle) — non retenu pour ce lot, dont la
priorité est de prouver la correction, pas le débit. Testé
(`OrToolsPlanningSolverTest::testSameProblemSolvedTwiceProducesTheSameResult`).
Reproductibilité garantie uniquement dans l'environnement versionné de ce
lot (même image Docker, même version `ortools`) — jamais promise
bit-à-bit universelle (`docs/allocation-algorithm.md` §14).

**Timeouts** — `docs/decisions.md` D093 : aucun budget n'existe sur
`OptimizationProblem` ; `OrToolsPlanningSolver` ne configure donc
**aucun** timeout, ni sur le `Process` (`setTimeout(null)` explicite) ni
sur CP-SAT (`max_time_in_seconds` jamais renseigné) — un solve tourne
jusqu'à conclusion réelle. Risque de production assumé et documenté, sans
conséquence dans ce lot puisqu'aucune requête HTTP réelle n'est encore
branchée sur le solveur.

## 21. Hors périmètre du Lot 6B (résolu ou toujours vrai — voir §29 pour le Lot 6C)

```
PARTIAL solve, unassigned[d], transformation slack         — Lot 6C : résolu (§23)
Diagnostic UNSAT complet, infeasible cores                 — Lot 6C : résolu pour le diagnostic local (§24-27) ; infeasible core reste indisponible (D096)
relaxations POLICY_HARD                                    — Lot 6C : contrat prêt (§26), vide en pratique (D097)
GLOBALLY_FORCED exhaustif, ForcedAssignmentAnalyzer          — toujours hors périmètre (§29)
fixedAssignments réels (D090)                               — toujours hors périmètre (§29)
Timeout réel (D093)                                          — toujours hors périmètre (§29)
seedMaterial/tieBreakKey réels (D088)                        — toujours hors périmètre (§29)
Orchestration PlanningGeneration → solve → DutyAssignment AUTO — toujours hors périmètre (§29)
Persistance des résultats du solve                           — toujours hors périmètre (§29)
REPAIR, SIMULATE                                              — toujours hors périmètre (§29)
```

## 22. Principe STRICT → PARTIAL (Lot 6C)

```
1. solve(STRICT)
   OPTIMAL/FEASIBLE → coverageStatus = COMPLETE, partialSolverStatus = null
   UNKNOWN/ERROR    → retourné tel quel, PARTIAL jamais déclenché
   UNSATISFIABLE    → seul cas qui déclenche PARTIAL

2. solve(PARTIAL) — uniquement si (1) a conclu UNSATISFIABLE
   OPTIMAL/FEASIBLE → coverageStatus = COMPLETE si unassignedDuties = [],
                       sinon INCOMPLETE ; diagnostics construit uniquement
                       s'il reste des gardes non attribuées
   UNSATISFIABLE     → existingDataConflict (§27)
   UNKNOWN/ERROR     → retourné tel quel, jamais coercé en INCOMPLETE prouvé
```

`strictSolverStatus` reste toujours le vrai statut STRICT (jamais écrasé
par un échec PARTIAL ultérieur — voir `OrToolsPlanningSolver::errorResult()`,
docs/decisions.md D094) ; `partialSolverStatus` reste `null` tant que
PARTIAL n'a jamais été appelé — c'est le signal observable, utilisé
directement par les tests, qu'aucun second solve n'a eu lieu.

## 23. Modèle PARTIAL

`OptimizationProblem` reste **strictement inchangé** — aucune donnée
n'est mutée ni reconstruite. La transformation PARTIAL existe uniquement
dans `CpSatPayloadBuilder::buildPartialSolvePayload()` (payload JSON) et
`cp_sat_solver.py` (modèle CP-SAT) :

```
pour chaque requiredDutyUnit :
  unassigned[d] ∈ {0,1}
  Σ x[d][candidate] + unassigned[d] = 1        // jamais les deux, jamais aucun

pour chaque optionalDutyUnit : inchangé (AddAtMostOne, jamais de variable unassigned)
```

Testé explicitement
(`StrictPartialSolveTest::testOptionalDutyUnitsNeverAppearInCoveragePhaseKeys`,
`testProblemIsNeverMutatedWhenPartialIsTriggered`).

## 24. Priorité CRITICAL

Les deux premières phases d'un solve PARTIAL, avant même les 8 phases
GENERATE :

```
1. PARTIAL_COVERAGE_CRITICAL   minimize Σ unassigned[d], d ∈ REQUIRED ∩ CRITICAL
2. PARTIAL_COVERAGE_TOTAL      minimize Σ unassigned[d], d ∈ REQUIRED
```

**Constat d'audit important** (`docs/decisions.md` D099) : avec le jeu de
contraintes actuel (aucun MAX_DUTIES, aucun CONFLICT/exclusivité
temporelle entre DutyUnits — confirmé absent de `EligibilityService`,
`docs/eligibility.md` §3), un candidat éligible à plusieurs DutyUnits peut
toujours les couvrir *tous* simultanément — la couverture d'une unité ne
dépend donc jamais de celle d'une autre. **Il n'existe aujourd'hui aucun
scénario réel de "concurrence" où sacrifier une garde STANDARD serait
nécessaire pour couvrir une garde CRITICAL.** Les deux phases restent
implémentées, ordonnées et testées correctement (comptage, verrouillage
lexicographique), mais ce mécanisme est **structurellement inerte** avec
le modèle réel actuel — il ne redevient observable que lorsqu'une
contrainte couplant plusieurs DutyUnits (MAX_DUTIES, CONFLICT, ...) sera
réellement implémentée. Les tests de ce lot le vérifient honnêtement :
deux CRITICAL isolément impossibles sont bien toutes deux signalées
(`testTwoCriticalDutiesBothUncoverableAreBothReportedUnassignedAndCritical`),
et une CRITICAL et une STANDARD ayant chacune son candidat sont toutes
deux couvertes, sans sacrifice
(`testCriticalAndStandardEachWithTheirOwnCandidateAreBothCoveredNothingSacrificed`).

## 25. Fairness en PARTIAL

`requiredDemand` (fixé à la construction du `FairnessContext`, Lot 5) et
`fairnessTargets` (`discretionaryTargetAtSolve`) ne sont **jamais**
recalculés à partir de ce que PARTIAL est parvenu à couvrir — testé
explicitement
(`StrictPartialSolveTest::testRequiredDemandNeverChangesAfterAPartialSolve`).
Les phases GENERATE (PRIMARY/SECONDARY/fériés/espacement/préférences/
tie-break) suivent, dans le même ordre qu'en STRICT, **après** les deux
phases de couverture — le verrouillage lexicographique (§18) garantit
qu'aucune optimisation de fairness ne peut jamais dégrader le minimum
d'unassigned déjà prouvé optimal. PRIMARY reste neutre (D086), SECONDARY
continue de fonctionner avec des données réelles même quand une partie
des gardes reste non couverte — testé
(`testSecondaryFairnessStillOptimizesInPartialWithMixedCoverage`,
`testPrimaryStaysNeutralInPartialToo`, `testFairnessNeverIncreasesUnassignedCount`).

## 26. `coverageStatus`

```
COMPLETE   si unassignedDuties = [] (STRICT réussi, ou PARTIAL sans reste)
INCOMPLETE si unassignedDuties ≠ [] après PARTIAL, ou si PARTIAL lui-même
           n'a pas conclu (UNSATISFIABLE/UNKNOWN/ERROR)
```

Le cas rare "STRICT UNSAT → PARTIAL COMPLETE" reste représentable et
correctement mappé (`coverageStatus = COMPLETE` malgré `strictSolverStatus
= UNSATISFIABLE`) — jamais forcé à `INCOMPLETE` seulement parce que
STRICT a échoué. Non atteignable avec un vrai scénario CP-SAT aujourd'hui
(même raison structurelle que D099 : si STRICT est UNSAT à cause d'une
unité sans candidat, PARTIAL laissera *exactement* cette même unité non
assignée) — testé via un script de test dédié
(`tests/Solver/fixtures/echo_partial_complete.py`,
`testPartialRareCaseFullCoverageDespiteStrictUnsat`).

## 27. `UnsatReport` — le vrai modèle de diagnostic

`UnsatDiagnostics` n'est plus une interface marqueur vide — `UnsatReport`
(`src/Fairness/`) l'implémente avec des value objects typés partout,
jamais un `array<string,mixed>` :

```
UnsatReport {
  strictSolverStatus, partialSolverStatus
  requiredDutyCount, assignedDutyCount
  unassignedDuties: list<UnassignedDutyDiagnostic>
    { dutyUnitStableKey, critical, candidateExclusions: list<CandidateExclusionDiagnostic> }
      { candidateId, exclusions: list<EligibilityExclusion> }   // réutilisé tel quel depuis l'Éligibilité
  structuralDiagnostics: list<StructuralDiagnostic>
    { code: StructuralDiagnosticCode, dutyUnitStableKey }
  solverAnalysis: SolverAnalysis { available, infeasibleCore }
  diagnosticRelaxations: list<DiagnosticRelaxation>
    { ruleCode, tier (toujours POLICY_HARD, gardé au constructeur), phrasing, disclaimer }
  existingDataConflict: ExistingDataConflict?
    { type, affectedDutyUnitStableKeys, affectedCandidateIds, reasons, message }
}
```

Construit par `UnsatDiagnosticsBuilder` (`src/Service/`) — solver-agnostic
(prend une simple `list<string>` de clés unassigned, jamais un type
CP-SAT), à partir uniquement de données déjà produites par le domaine
(`EligibilityMatrix`, `CoveragePolicy`).

**`candidateExclusions`** : uniquement les candidats avec au moins une
vraie exclusion (`EligibilityResult::$exclusions` non vide) — jamais tous
les candidats évalués. Un candidat éligible mais non retenu n'apparaît
jamais (D095) : *"Marc n'a pas été choisi parce que Sophie était
meilleure"* n'est jamais une phrase que ce modèle peut produire, parce
qu'il ne contient tout simplement pas cette information.

**`structuralDiagnostics`** : uniquement `NO_ELIGIBLE_CANDIDATE` (zéro
candidat éligible pour une unité non assignée — toujours vrai, jamais une
approximation). `INSUFFICIENT_ELIGIBLE_CAPACITY` (l'autre exemple cité par
`docs/allocation-algorithm.md` §16) nécessiterait un vrai argument de
capacité bipartite (violation du théorème de Hall) — non implémenté,
volontairement, plutôt que risquer un diagnostic erroné.

**`solverAnalysis`** : toujours `{available: false, infeasibleCore: null}`
(D096) — `cp_sat_solver.py` ne construit aucun littéral d'assumption
aujourd'hui.

**`diagnosticRelaxations`** : toujours `[]` (D097) — confirmé, aucune
raison `POLICY_HARD` produite par `EligibilityService`.

## 28. `existingDataConflict`

Se remplit uniquement quand PARTIAL lui-même est `UNSATISFIABLE`
(`docs/allocation-algorithm.md` §10.5) — diagnostic distinct et plus
grave qu'un simple déficit de couverture, jamais confondu avec lui.
**Inatteignable avec le modèle réel d'aujourd'hui** (D098) : sans
`fixedAssignments` (D090), et avec `unassigned[d]` disponible pour chaque
unité REQUIRED, le problème PARTIAL admet toujours au moins la solution
triviale "tout non assigné" — il ne peut jamais être lui-même UNSAT.
Le contrat est entièrement implémenté et testé (`UnsatDiagnosticsBuilderTest::testExistingDataConflictBuildsACompleteReport`,
`StrictPartialSolveTest::testPartialItselfUnsatisfiableRoutesToExistingDataConflict`
via `tests/Solver/fixtures/echo_partial_unsat.py`) — jamais via un vrai
scénario CP-SAT de bout en bout.

## 29. Hors périmètre du Lot 6C (résolu ou toujours vrai — voir §35 pour le Lot 6D)

```
PlanningGeneration → solve → persist AUTO                    — toujours hors périmètre (§35)
fixedAssignments réels (D090)                                 — toujours hors périmètre (§35)
timeoutBudget réel (D093)                                     — toujours hors périmètre (§35)
SolverParameterSet complet, snapshotHash réel, algorithmVersion réel (D088) — toujours hors périmètre (§35)
GLOBALLY_FORCED, ForcedAssignmentAnalyzer                     — toujours hors périmètre (§35)
Infeasible core CP-SAT réel (D096 — nécessite des assumption literals) — toujours hors périmètre (§35)
INSUFFICIENT_ELIGIBLE_CAPACITY (nécessite un vrai argument de capacité bipartite) — Lot 6D : résolu pour le cas exact à candidat unique (D102), général toujours hors périmètre
REPAIR, SIMULATE complet                                       — toujours hors périmètre (§35)
DutyAssignmentEvent, notifications, frontend                   — toujours hors périmètre (§35)
validation/publication métier complète                         — toujours hors périmètre (§35)
```

Le Lot 6D (§30-§35) a implémenté une vraie contrainte couplant plusieurs
DutyUnits (CONFLICT/TEAM_MIN_REST), rendant enfin observable la priorité
CRITICAL (D099 ne s'applique plus à ces deux règles) — cette frontière
(`PlanningSolver`, `OrToolsPlanningSolver`, `UnsatDiagnosticsBuilder`)
n'a exigé aucune réécriture pour l'accueillir, comme prévu.

## 30. Contraintes globales (Lot 6D)

Première catégorie de contrainte qui **relie** deux `(DutyUnit,
candidat)` autrement indépendants — distincte d'une exclusion locale
(`EligibilityExclusion`, qui retire une seule arête) :

```
Exclusion locale   : candidat C ne peut jamais faire la garde D           → x[D,C] n'existe pas
Contrainte globale : C peut faire D1, C peut faire D2, mais pas les deux  → x[D1,C] + x[D2,C] <= 1
```

`UNAVAILABLE` reste exclusivement une exclusion locale (arête absente,
`docs/eligibility.md` §4) — jamais dupliquée en contrainte globale,
testé explicitement
(`GlobalConstraintsSolveTest::testUnavailabilityNeverProducesAGlobalConstraintOnlyAnAbsentEdge`).

## 31. `AssignmentConflict` — modèle et calcul (D100)

```
AssignmentConflict {
  candidateStableKey
  leftDutyUnitStableKey, rightDutyUnitStableKey   // left < right, ordre canonique
  reason   : CONFLICT | TEAM_MIN_REST
  tier     : dérivé de reason, jamais indépendant
}
```

Calculé par `AssignmentConflictAnalyzer` (`src/Service/`), porté par
`OptimizationProblem::$assignmentConflicts` — `OptimizationProblem`
lui-même reste immuable, ce champ est peuplé une fois à la construction,
jamais recalculé après coup.

**Performance** (§Complexité) : les deux `DutyUnit` d'une paire sont
incompatibles ou non **indépendamment du candidat** (le chevauchement
physique ou l'écart de repos ne dépendent que des horaires des deux
unités) — calculé une seule fois par paire d'unités (`O(units²)`), puis
seulement pour les paires réellement incompatibles, les candidats
éligibles aux deux sont identifiés (intersection). Jamais
`O(units² × candidats)`.

**Déterminisme** : les `DutyUnit` sont triées par clé stable avant la
double boucle ; `left`/`right` d'un conflit sont toujours dans cet ordre
canonique ; `CpSatPayloadBuilder` retrie explicitement avant sérialisation
JSON. Testé
(`AssignmentConflictAnalyzerTest::testConflictsAreReturnedInCanonicalKeyOrderNeverInsertionOrder`,
`testRepeatedAnalysisOfTheSameSnapshotIsFullyDeterministic`).

**`DutyGroupInstance`** : toutes les paires de Duty constituantes des
deux unités sont comparées (jamais seulement une paire "frontière"),
garantissant l'exactitude sans supposer un tri implicite. Le conflit
référence toujours la clé stable du groupe, jamais une Duty constituante
— testé
(`AssignmentConflictAnalyzerTest::testGroupVsSingleDutyConflictUsesGroupStableKeyNeverAConstituentDuty`).

## 32. `CONFLICT` et `TEAM_MIN_REST` (D101)

> **Statut d'implémentation (Lot 6D.1, D105)** : la source de
> `TEAM_MIN_REST` décrite ci-dessous (`PlanningRuleSetConfiguration`/
> `PlanningSnapshotRuleSet`, une valeur d'équipe) est remplacée par les
> options figées de la `PlanningGeneration` elle-même
> (`App\Entity\RestPolicyOptions`) — voir §36. Le calcul géométrique
> (écart en secondes Unix, DST-safe, group-aware, frontière `<` stricte)
> reste identique et pleinement réutilisé ; seule la donnée consultée
> pour savoir *si* la règle est active et à quel seuil change.
> `LEGAL_MIN_REST` est désormais également implémentée, avec le même
> mécanisme, sous la même condition (activable par génération, jamais
> globale) — la section "toujours non implémentée" ci-dessous est
> obsolète, conservée pour l'historique.

**`CONFLICT`** (HARD) : `Duty::overlapsWith()` (déjà présent dans le
domaine avant ce lot, jamais utilisé jusqu'ici) — instants absolus,
DST-safe par construction.

**`TEAM_MIN_REST`** (POLICY_HARD) : `PlanningRuleSetConfiguration::$teamMinRestHours`,
lu depuis `PlanningSnapshotRuleSet` (copie figée au moment du snapshot,
jamais le `PlanningRuleSet` vivant). Écart calculé en secondes Unix
divisées par 3600 (jamais une différence de `DateTimeImmutable` en
heures murales) — un changement d'heure DST entre les deux gardes ne
fausse jamais le calcul. Un écart **exactement égal** au minimum reste
autorisé (`<` strict, jamais `<=`) — testé explicitement aux trois
frontières (`testGapExactlyEqualToTeamMinRestIsAllowed`,
`testGapBelowTeamMinRestIsAPolicyHardConflict`,
`testGapAboveTeamMinRestIsAllowed`). Absent de configuration
(`teamMinRestHours = null`, ou aucun `PlanningSnapshotRuleSet` du tout)
→ `TEAM_MIN_REST` ne se déclenche jamais, jamais une valeur par défaut
inventée.

`CONFLICT` prime toujours sur `TEAM_MIN_REST` pour une même paire —
jamais les deux rapportés pour la même incompatibilité (un chevauchement
rend la question du repos sans objet).

**`LEGAL_MIN_REST`** : toujours non implémentée (D036 réaffirmée) —
aucune source de vérité légale.

## 33. Priorité CRITICAL — enfin observable

Avec un vrai `AssignmentConflict`, le PARTIAL solve doit désormais
réellement choisir. Scénario type
(`GlobalConstraintsSolveTest::testCriticalVsStandardRealConflictKeepsCriticalCoveredAndSacrificesStandard`) :
deux gardes incompatibles (même candidat unique éligible aux deux), l'une
CRITICAL, l'autre STANDARD — `PARTIAL_COVERAGE_CRITICAL` force la
CRITICAL à être couverte, la STANDARD reste explicitement non assignée.
Vérifié que la décision vient réellement de CP-SAT (deux gardes
incompatibles CRITICAL → au moins une reste non assignée, correctement
minimisée à 1 et correctement signalée `critical: true` — testé
`testTwoCriticalIncompatibleDutiesMinimizeCriticalUnassignedToOne`).

## 34. Diagnostics étendus (D102, D103)

**`INSUFFICIENT_ELIGIBLE_CAPACITY`** — le seul cas mathématiquement exact
implémenté : deux unités REQUIRED en conflit, un unique candidat éligible
partagé par les deux → preuve directe qu'au plus une peut être couverte.
`UnsatDiagnosticsBuilder::hasProvenInsufficientCapacity()`. Le cas
général (théorème de Hall sur un groupe arbitraire) reste hors périmètre
— nécessiterait un vrai algorithme de couplage biparti.

**`diagnosticRelaxations`** — plus jamais systématiquement vide (D097
reste vraie pour tout autre POLICY_HARD non encore implémenté, mais
`TEAM_MIN_REST` peut désormais y apparaître réellement).
`OrToolsPlanningSolver::buildRelaxations()` ne propose une relaxation
qu'après avoir **réellement re-résolu** PARTIAL sans les conflits
POLICY_HARD et observé une amélioration authentique du nombre de gardes
non assignées — jamais une déduction a priori. Coût : un troisième appel
subprocess, seulement quand un déficit de couverture existe *et* qu'au
moins un conflit POLICY_HARD est présent. La formulation reste
conditionnelle ("permettrait de retrouver une couverture complète" /
"permettrait de réduire... de N à M"), jamais "X est la cause" — et
aucune contrainte HARD ne peut structurellement y apparaître (garde-fou
constructeur de `DiagnosticRelaxation`, D097).

## 35. Hors périmètre de ce lot (Lot 6D)

```
PlanningGeneration → solve → persist AUTO                    — Lot 6E : résolu (§37, docs/planning-generation.md §13-16)
fixedAssignments réels (D090)                                 — toujours hors périmètre (aucun planning antérieur à préserver dans ce lot)
timeoutBudget réel (D093)                                     — Lot 6E : résolu (§37)
SolverParameterSet complet, snapshotHash réel, algorithmVersion réel (D088) — Lot 6E : résolu pour timeoutSeconds/numWorkers/snapshotHash/algorithmVersion (§37) ; tieBreakKey/phase 8 consommant le seed reste hors périmètre
GLOBALLY_FORCED, ForcedAssignmentAnalyzer                     — toujours hors périmètre
Infeasible core CP-SAT réel (D096)                            — toujours hors périmètre
MAX_DUTIES / MAX_WEEKENDS (D104 — portée FairnessPeriod incompatible avec
  ce qu'OptimizationProblem peut voir aujourd'hui, une seule PlanningPeriode)
MAX_CONSECUTIVE_NIGHTS (D104 — aucune notion de garde "de nuit" identifiable)
LEGAL_MIN_REST (D036 — aucune source de vérité légale) — implémentée au Lot
  6D.1 (§36) en tant qu'option par génération ; aucune valeur par défaut
  n'est devinée
INSUFFICIENT_ELIGIBLE_CAPACITY général (théorème de Hall, couplage biparti)
REPAIR, SIMULATE complet                                       — toujours hors périmètre
DutyAssignmentEvent, notifications, frontend                   — toujours hors périmètre
validation/publication métier complète                         — Lot 6E : précondition PUBLISHED ⇒ coverage COMPLETE résolue (§37, D106) ; aucun endpoint de publication créé
```

Le Lot 6E (§37) a orchestré un vrai flux `PlanningGeneration → solve →
persist AUTO`, exactement dans la frontière anticipée ici — aucune
réécriture n'a été nécessaire. Le prochain lot probable devra faire
remonter la charge historique réelle jusqu'à `OptimizationProblem`
(débloquant MAX_DUTIES/MAX_WEEKENDS, un vrai changement d'architecture),
ou construire REPAIR/l'UI de validation-publication.

## 36. Politiques de repos par génération : `RestPolicyOptions` (Lot 6D.1, D105)

`LEGAL_MIN_REST` et `TEAM_MIN_REST` ne sont **jamais** des règles
globales imposées à toutes les équipes/tous les plannings — ce sont des
options que le planificateur active explicitement pour *une génération
donnée*, figées à sa création et jamais mutées ensuite
(`App\Entity\PlanningGeneration` n'est modifiée après coup que sur son
`$status` — elle est déjà son propre enregistrement historique
permanent, aucune entité `PlanningSnapshotRestPolicy` séparée n'est
nécessaire, contrairement à `PlanningSnapshotRuleSet`).

**`App\Entity\RestPolicyOptions`** (readonly, validée dans son
constructeur, jamais un bag `array`) :

```
{
  legalMinRestEnabled: bool,
  legalMinRestHours:   ?int,   // requis et > 0 ssi legalMinRestEnabled, null sinon
  teamMinRestEnabled:  bool,
  teamMinRestHours:    ?int,   // requis et > 0 ssi teamMinRestEnabled, null sinon
}
```

Invariants imposés à la construction (jamais une correction silencieuse
— échec explicite) :
- `*Hours` obligatoire et strictement positif exactement quand `*Enabled`
  est vrai, `null` sinon.
- Si les deux sont activées : `teamMinRestHours >= legalMinRestHours`
  (une politique d'équipe ne peut jamais prétendre être plus protectrice
  tout en étant en réalité plus laxiste que le plancher légal).
- `legalMinRestHours` n'est **jamais** déduit automatiquement (pas de
  "11h par défaut") — MedVue n'a toujours aucune source de vérité légale
  (D036) ; le planificateur le fournit explicitement à chaque activation.

**Lecture** : `AssignmentConflictAnalyzer` lit exclusivement
`$snapshot->getGeneration()->getRestPolicy()` — plus aucune dépendance à
`PlanningSnapshotRuleSetRepository`. `PlanningRuleSetConfiguration::$teamMinRestHours`
devient un champ historique non lu.

**Précédence** : pour une même paire d'unités, CONFLICT prime toujours ;
sinon, si le gap viole `legalMinRestHours`, `LEGAL_MIN_REST` est rapportée
et `TEAM_MIN_REST` ne l'est jamais en plus pour cette même paire — rendu
correct par l'invariant `teamMinRestHours >= legalMinRestHours` :
toute violation LEGAL est mathématiquement aussi une violation TEAM, donc
la rapporter séparément serait une pure redondance.

**Tier** : inchangé — `LEGAL_MIN_REST` = HARD (jamais relâchable ;
`DiagnosticRelaxation` refuse structurellement de la proposer),
`TEAM_MIN_REST` = POLICY_HARD (relâchable uniquement via un vrai
re-solve, D103, mécanisme réutilisé sans modification). Puisque
`OrToolsPlanningSolver::buildRelaxations()` ne collecte que les conflits
`POLICY_HARD`, `LEGAL_MIN_REST` ne peut structurellement jamais
apparaître dans `diagnosticRelaxations`, y compris quand elle est la
seule cause réelle de l'UNSAT — testé explicitement
(`GlobalConstraintsSolveTest::testLegalMinRestAloneNeverProducesAMisleadingRelaxation`).

**API** : `POST /api/planning-periods/{id}/generations` accepte un corps
JSON optionnel (`CreatePlanningGenerationRequest`) ; absent/vide = les
deux politiques désactivées (comportement pré-Lot-6D.1 exact). Validation
en miroir exact de `RestPolicyOptions` via `#[Assert\Callback]`, 422 sur
toute violation (jamais une correction silencieuse d'un champ invalide).

**Historique** : deux générations d'une même `PlanningPeriod` (ou deux
plannings distincts) peuvent utiliser deux politiques de repos
différentes sans modifier le `RuleSet` global de l'équipe ni l'historique
d'une génération antérieure — testé explicitement
(`PlanningGenerationControllerTest::testDifferentGenerationsOfTheSamePlanningPeriodCanUseDifferentRestPolicies`,
`AssignmentConflictAnalyzerTest::testALaterGenerationsRestPolicyNeverAffectsAnEarlierGenerationsConflicts`).

Hors périmètre (inchangé) : détection automatique de juridiction,
récupération automatique de règle légale, choix automatique d'une durée,
UI, câblage `AUTO` complet.

## 37. Production réelle : orchestration, timeout, `SolverParameterSet`, seed, snapshotHash (Lot 6E, docs/decisions.md D106)

Le câblage `AUTO` annoncé au §36 est désormais réel —
`docs/planning-generation.md` §13-16 documente l'orchestration complète
(`PlanningGenerationService::generate()`) côté persistance ; cette section
documente ce qui a changé côté solveur/contrat abstrait.

**Timeout réel (résout D093)** — `SolverParameterSet` (nouvelle entité
système, versionnée, append-only) porte `timeoutSeconds`/`numWorkers`,
les deux seuls champs réellement consommés. `OptimizationProblem` gagne
`timeoutSeconds`/`numWorkers` (nullable/`1` par défaut pour la
construction directe en test, jamais pour un vrai problème de
production) : `CpSatPayloadBuilder` les injecte dans chaque payload JSON
(`maxTimeInSeconds`, `numWorkers` — remplace le littéral `1` codé en dur
à trois endroits) ; `cp_sat_solver.py` fixe
`solver.parameters.max_time_in_seconds` une fois par subprocess,
appliqué à chaque appel `Solve()` de la chaîne lexicographique.
`Process::setTimeout()` (PHP, le tueur de subprocess) est fixé à
`timeoutSeconds × 12` — 12 étant un de plus que le pire cas réel de 11
appels `Solve()` par subprocess (1 base + 10 phases max) ; un
`ProcessTimedOutException` est capturé et mappé à `SolverStatus::ERROR`,
jamais une requête qui plante.

**`timeoutHit` honnête** — avec `max_time_in_seconds` comme seul critère
d'arrêt jamais configuré dans ce script, tout statut `FEASIBLE`/`UNKNOWN`
ne peut provenir que de l'expiration du budget (propriété documentée de
CP-SAT, pas une approximation) — `_is_timeout_status()` le dérive
explicitement, jamais un booléen deviné.

**`snapshotHash` réel (résout partiellement D088)** — `SnapshotHasher`
calcule `SHA-256(canonicalSnapshotJson)` sur exactement les données
consommées par `EligibilityMatrixBuilder`/`FairnessContextBuilder`/
`AssignmentConflictAnalyzer` (membres + leurs périodes de
participation/disponibilité/non-participation, `RestPolicyOptions`, et la
liste **vivante** des `Duty`) — jamais `PlanningSnapshotRuleSet.configuration`,
auditée et confirmée sans effet sur le calcul aujourd'hui. Reste `null`
sur `OptimizationResult` (le champ existant depuis le Lot 6A) — le hash
vit sur `PlanningGeneration`, calculé par l'orchestrateur, pas par le
solveur lui-même.

**Seed réel, jamais consommé par le solve (D088 reste partiellement
ouverte)** — `SeedMaterialBuilder` calcule et persiste `seed`
(`teamStableKey + planningPeriodStableKey + rulesVersion + snapshotHash +
algorithmVersion + solverParameterSetVersion + explicitSeed`, §13)
uniquement pour l'identité reproductible d'une génération
(docs/allocation-algorithm.md §14) — la phase 8
(`deterministicTieBreak`) reste neutre exactement comme avant ce lot
(D088) : aucun `tieBreakKey` n'est calculé à partir de `dutyStableKey`/
`candidateStableKey`, ce gap reste explicitement ouvert plutôt que
comblé par une implémentation partielle.

**`algorithmVersion`** — `OptimizationProblemBuilder::ALGORITHM_VERSION`
(`'allocation-v1'`), une constante à incrémenter manuellement, jamais à
chaque commit — représente la version du comportement métier du pipeline,
jamais la version de l'application ni celle d'OR-Tools.

**Hors périmètre encore (voir docs/decisions.md D106 "dette réelle")** :
`fixedAssignments` (D090, non nécessaire pour un premier GENERATE sans
planning antérieur à préserver), `GLOBALLY_FORCED`/`ForcedAssignmentAnalyzer`,
infeasible core CP-SAT réel (D096), REPAIR, SIMULATE, MAX_DUTIES/
MAX_WEEKENDS/MAX_CONSECUTIVE_NIGHTS (D104).
