# Éligibilité — choix techniques

Première couche métier réelle du futur moteur de planification
(`docs/allocation-algorithm.md` §2 : "Éligibilité d'abord, optimisation
ensuite, explication enfin"). Pour une `PlanningGeneration` déjà
snapshottée, répond de manière déterministe à : *pour chaque garde/groupe
de gardes et chaque membre du snapshot, ce membre est-il éligible ? Si
non, pourquoi ?*

**Ce lot n'implémente ni ne simule aucun solveur** : aucune optimisation,
aucun `DutyAssignment` automatique, aucun calcul de `GLOBALLY_FORCED` ou
de cible d'équité. Voir §7 "Hors périmètre".

> **Mise à jour (lot Planning, `docs/planning.md`)** : `EligibilityService`
> et `EligibilityMatrixBuilder` n'ont **pas** été modifiés pour comprendre
> plusieurs Teams — ils continuent de travailler sur un seul
> `PlanningSnapshot` rattaché à un seul `PlanningPeriod`. C'est
> précisément la responsabilité du niveau `Planning`/`PlanningLine`
> d'organiser plusieurs contextes mono-équipe séparés, jamais celle de ce
> service.

## 1. Modèle métier (`src/Eligibility/`)

Nouveau namespace, distinct de `src/Entity/` (persistance Doctrine) et de
`src/Dto/` (frontière HTTP) : ce sont des objets de domaine purs, sans
dépendance, construits à la volée à partir du snapshot — jamais
persistés (voir §5). `EligibilityService`/`EligibilityMatrixBuilder`
restent dans `src/Service/`, comme tout le reste de la logique métier
orchestrée (cohérent avec `CLAUDE.md`).

- **`ConstraintTier`** — `HARD` / `POLICY_HARD` / `SOFT`
  (`docs/allocation-algorithm.md` §3).
- **`ExclusionReason`** — le contrat stable complet de codes
  d'exclusion. `ExclusionReason::tier()` fige le tier de chaque code, une
  fois, jamais reconfigurable (§3 : "un reason ne doit pas changer de
  tier selon le contexte"). `ExclusionReason::zerosStructuralOpportunity()`
  fige de la même façon quels codes mettent `structuralOpportunity` à
  `false` — voir §4.
- **`EligibilityExclusion`** — `{reason, tier, context}`. `$tier` est
  **dérivé** de `$reason` dans le constructeur, jamais accepté comme
  paramètre indépendant : il est structurellement impossible de créer une
  exclusion dont le tier contredit `ExclusionReason::tier()`. `$context`
  est un tableau structuré minimal (jamais une chaîne libre de type
  `"not eligible because..."` comme source de vérité).
- **`EligibilityResult`** — `{eligible, exclusions, structuralOpportunity,
  preferred}`. `$eligible` est de la même façon **dérivé** de
  `$exclusions` (`[] === $exclusions`), pas un booléen indépendant —
  même raisonnement que pour `$tier` ci-dessus : un résultat qui prétend
  "éligible" avec une liste d'exclusions non vide est un bug, pas un état
  que ce type devrait pouvoir représenter. C'est un écart volontaire par
  rapport à la signature donnée en exemple dans la demande du lot (qui
  acceptait `eligible` comme premier paramètre du constructeur).
- **`DutyUnit`** (interface) / **`SingleDutyUnit`** / **`DutyGroupUnit`**
  — l'abstraction sur laquelle tout le reste (y compris le futur
  `OptimizationProblemBuilder`) doit s'appuyer, jamais directement sur
  `Duty`. `getStableKey()` renvoie le `stableId` (Duty ou
  DutyGroupInstance), jamais l'`id` auto-incrémenté (D046/D042).
- **`EligibilityMatrix`** — le résultat `DutyUnit × PlanningSnapshotMember`
  pour tout un snapshot, indexé par clés métier stables (jamais un `id`),
  donc value-équivalent quel que soit l'ordre de retour de la base
  (voir §6 "Déterminisme").

## 2. `EligibilityService`

```php
evaluate(PlanningSnapshot $snapshot, DutyUnit $dutyUnit, PlanningSnapshotMember $member): EligibilityResult
```

Lit **uniquement** les collections du `PlanningSnapshotMember` donné
(`participationPeriods` n'est jamais lu ici — l'équité est hors
périmètre, `docs/allocation-algorithm.md` §2) — jamais une table vivante.
Garde-fou défensif : lève `\InvalidArgumentException` si
`$member->getSnapshot() !== $snapshot` (erreur d'appel, jamais un résultat
métier normal).

## 3. Raisons calculables vs déclarées

`ExclusionReason` porte le contrat stable complet donné par la
spécification, mais `EligibilityService` ne **produit** aujourd'hui que :

| Raison | Donnée source | Tier |
|---|---|---|
| `USER_INACTIVE` | `PlanningSnapshotMember.active` (D067, ajouté ce lot) | HARD |
| `MEMBERSHIP_OUT_OF_RANGE` | `membershipStart`/`membershipEnd` snapshotés vs `Duty.localDate` | HARD |
| `UNAVAILABLE` | `PlanningSnapshotAvailabilityPeriod` (type `UNAVAILABLE`) vs instants réels de la `Duty` | HARD |
| `NON_PARTICIPATION` | `PlanningSnapshotNonParticipationPeriod` vs instants réels de la `Duty` | HARD |
| `GROUP_UNAVAILABLE` | dérivée — un composant du groupe exclu par `UNAVAILABLE`/`NON_PARTICIPATION` | HARD |

Toutes les autres valeurs de `ExclusionReason` (`NOT_TEAM_MEMBER`,
`CONFLICT`, `SITE_NOT_ALLOWED`, `MISSING_SKILL`, `LEGAL_MIN_REST`,
`LOCK_CONFLICT`, `TEAM_MIN_REST`, `MAX_DUTIES`, `MAX_WEEKENDS`,
`MAX_CONSECUTIVE_NIGHTS`, `RULE_EXCLUSION`) existent dans l'enum (contrat
stable) mais ne sont **jamais produites** par ce lot :

- **`NOT_TEAM_MEMBER`** — structurellement inatteignable avec la
  signature actuelle de `evaluate()` : elle ne reçoit que des
  `PlanningSnapshotMember` déjà membres du snapshot. Resterait pertinent
  si l'API gagnait un jour un point d'entrée acceptant un `User` brut.
- **`CONFLICT`** — jamais produite comme `EligibilityExclusion` par
  `EligibilityService`, et ce restera vrai : un conflit lie **deux**
  affectations concurrentes l'une à l'autre, une information qu'une
  évaluation `evaluate(dutyUnit, member)` — un seul couple à la fois — ne
  peut structurellement pas exprimer. **Mise à jour (Lot 6D,
  `docs/planning-solver.md` §31)** : implémentée ailleurs, comme
  contrainte *globale* plutôt que comme exclusion locale —
  `App\Service\AssignmentConflictAnalyzer` calcule les paires de
  `DutyUnit` physiquement incompatibles (`Duty::overlapsWith()`),
  `OptimizationProblem::$assignmentConflicts` les porte,
  `OrToolsPlanningSolver`/`cp_sat_solver.py` les traduit en
  `x[A,c] + x[B,c] <= 1`. La frontière reste nette : `EligibilityMatrix`
  retire une seule arête à la fois (édition locale), `AssignmentConflict`
  relie deux arêtes entre elles (contrainte globale) — jamais la même
  chose exprimée deux fois.
- **`SITE_NOT_ALLOWED` / `MISSING_SKILL`** — aucune notion de site ou de
  compétence/habilitation n'est modélisée nulle part dans le domaine
  actuel (confirmé dans `docs/planning-domain.md` : "Skill/habilitation
  requirements are deliberately not modeled yet").
- **`LEGAL_MIN_REST`** — dépend toujours d'un seuil légal non tranché
  (D036, point ouvert explicite).
- **`TEAM_MIN_REST`** — même statut que `CONFLICT` ci-dessus : jamais une
  `EligibilityExclusion` (même raison structurelle — lie deux
  affectations, pas une seule). **Mise à jour (Lot 6D)** : implémentée
  comme contrainte globale POLICY_HARD, via le même
  `AssignmentConflictAnalyzer`, à partir de
  `PlanningRuleSetConfiguration::$teamMinRestHours` (réellement
  snapshotté via `PlanningSnapshotRuleSet` — voir `docs/decisions.md`
  D101).
- **`LOCK_CONFLICT`** — suppose une régénération qui respecte
  `DutyAssignment.locked`, hors périmètre (pas de régénération du tout).
- **`MAX_DUTIES` / `MAX_WEEKENDS` / `MAX_CONSECUTIVE_NIGHTS` /
  `RULE_EXCLUSION`** — dépendent des compteurs/targets du futur
  `FairnessService`/`SpacingService`, non implémentés.

Aucune de ces raisons n'est jamais renvoyée par erreur : `EligibilityService`
ne contient tout simplement aucun code capable de les produire.

## 4. `structuralOpportunity`

Règle centrale, testée explicitement (`EligibilityServiceTest`,
`§structuralOpportunity`) :

```text
false si :
  - MEMBERSHIP_OUT_OF_RANGE (membre hors de sa fenêtre de membership)
  - NON_PARTICIPATION (non-participation administrative)
  - USER_INACTIVE (voir D067 ci-dessous)

true malgré :
  - UNAVAILABLE (indisponibilité personnelle)
  - PREFER_DUTY (jamais une exclusion, jamais un effet sur cette valeur)
```

C'est la règle de résistance au gaming de `docs/availability.md` §2,
appliquée pour la première fois dans du code exécutable : une
indisponibilité personnelle ne réduit jamais l'exposition structurelle
théorique — seul un fait administratif/structurel (non-participation,
hors de l'équipe, compte désactivé) le fait.

**Calcul pour un `DutyGroupUnit`** : `structuralOpportunity` est mis à
`false` **au moment où la cause composant-par-composant est détectée**,
avant toute décision d'emballage en `GROUP_UNAVAILABLE` (§5) — jamais
déduit après coup à partir du code de raison final. Ça évite toute
dépendance fragile entre "quelle raison est affichée" et "quel effet
structurel elle a réellement eu".

## 5. `USER_INACTIVE` (D067)

La spécification du lot demandait explicitement un choix argumenté entre
étendre le snapshot ou différer cette raison. **Décision : étendre.**
`PlanningSnapshotMember.active` fige `User::isActive()` au moment du
snapshot (migration `Version20260916095723`, colonne `NOT NULL` ajoutée
directement — zéro ligne existante en base au moment du lot, donc pas de
backfill nécessaire, contrairement à `users.stable_id`). Justification :

- coût minimal (une seule colonne booléenne) ;
- la désactivation est un vrai cas limite déjà exigé par `CLAUDE.md`
  ("cas limites couverts explicitement... désactivation") ;
- laisser `EligibilityService` lire `$member->getUser()->isActive()` en
  direct aurait violé le principe central du Lot 3 ("une génération
  historique doit toujours être interprétée à partir de l'état figé au
  moment du snapshot").

**Sémantique retenue pour `structuralOpportunity`** : un compte désactivé
est traité comme un fait structurel (comme `MEMBERSHIP_OUT_OF_RANGE`), pas
comme une déclaration personnelle — un administrateur qui désactive un
compte retire réellement la personne de la capacité de l'équipe, ce n'est
pas un choix personnel circonstanciel comme `UNAVAILABLE`.

## 6. Groupes de gardes (`DutyGroupUnit`)

Un `DutyGroupInstance` reste un seul `DutyUnit` — éligible en bloc ou
inéligible en bloc, jamais une demi-éligibilité
(`docs/allocation-algorithm.md` §4.1, D052).

- **`MEMBERSHIP_OUT_OF_RANGE`** sur un composant : reportée **directement**
  (jamais emballée), avec `context.affectedDutyStableIds` listant les
  gardes du groupe concernées — la spécification ne demande l'emballage
  `GROUP_UNAVAILABLE` que pour les causes d'indisponibilité/non-
  participation.
- **`UNAVAILABLE`/`NON_PARTICIPATION`** sur un composant, pour un groupe :
  emballées en une seule exclusion `GROUP_UNAVAILABLE`, dont
  `context.rootCauses` liste chaque cause racine (garde concernée, raison
  d'origine, identifiant de la période source) — jamais perdue, toujours
  disponible pour un futur audit détaillé.
- Pour une `SingleDutyUnit`, ces mêmes causes sont reportées directement
  (`UNAVAILABLE`/`NON_PARTICIPATION`), sans emballage — l'emballage n'a de
  sens que pour un vrai groupe.

## 7. Endpoint

```
GET /api/planning-generations/{stableId}/eligibility
```

Réservé OWNER/ADMIN (`PlanningTeamRoleVoter::MANAGE_PLANNING`, renommé
depuis `TeamRoleVoter` — docs/decisions.md D079) — délibérément
plus restrictif que la lecture de génération/snapshot
(`TEAM_VIEW_PLANNING`, ouverte à tout membre) : c'est un outil d'audit,
pas une lecture générale. Aucune entité Doctrine ni objet de domaine
exposé directement — DTO de lecture construit à la main
(`EligibilityController::matrixToArray()`), même convention que
`PlanningGenerationController`.

## 8. Déterminisme

`EligibilityMatrix` est indexée par clés métier stables
(`dutyUnit->getStableKey()`, `member->getSourceTeamMemberStableId()`),
jamais par `id` auto-incrémenté ni par ordre d'insertion —
`EligibilityMatrixBuilder::build()` trie même explicitement les listes
`dutyUnits`/`candidates` par clé stable avant de construire la matrice,
pour que deux constructions depuis le même snapshot soient
value-équivalentes quel que soit l'ordre réellement retourné par
PostgreSQL. Testé directement (`EligibilityMatrixBuilderTest::testMatrixIsDeterministicAcrossRebuilds`).

## 9. Pas de persistance de la matrice

`EligibilityMatrix` n'est jamais stockée en base — reconstruite à la
demande depuis le snapshot immuable, qui contient déjà tout ce qu'il faut
(§8 : c'est justement ce déterminisme qui rend la reconstruction à la
demande sûre). Aucun besoin réel démontré pour la persister dans ce lot ;
à reconsidérer si un futur `OptimizationProblemBuilder` a besoin de la
figer à un instant T distinct du snapshot lui-même.

## 10. Hors périmètre de ce lot

> **Mise à jour (Lot 5, `docs/fairness.md`)** : `STRUCTURALLY_FORCED` est
> désormais implémenté (`StructurallyForcedAnalyzer`), directement
> au-dessus de cette même `EligibilityMatrix` — voir `docs/fairness.md`
> §8. `GLOBALLY_FORCED` reste non implémenté, pour la même raison
> ci-dessous.

```
GLOBALLY_FORCED (nécessite PlanningSolver.checkFeasibility, D038)
Optimisation, OR-Tools, tout pseudo-solveur glouton
DutyAssignment automatique (AUTO)
targets/deviation de fairness POST-SOLVE (`raw`, `deviation`,
  `normalizedDeviation` — nécessitent un solve réel ; les targets
  PRÉ-solve (`requiredDemand`, `grossTarget`, `discretionaryTargetAtSolve`)
  sont désormais implémentés, voir `docs/fairness.md`)
export Excel (les DTO/structures actuelles — EligibilityMatrix, ses
  entrées — sont déjà la forme qu'un futur export consommerait, mais
  aucun writer n'existe)
```
