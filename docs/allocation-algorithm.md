# Algorithme de répartition des gardes — Spécification v1 de référence

> **Document vivant.** Le solveur/moteur d'optimisation décrit ici reste
> entièrement non implémenté. Le Lot 3 (`docs/planning-generation.md`) a
> construit la couche de persistance qui l'entourera —
> `PlanningGeneration`, `PlanningSnapshot` (et ses enfants), `DutyAssignment`
> — sans aucune logique de génération/optimisation : voir §14 et §19
> ci-dessous pour le détail de ce qui est réellement en place. Cette
> version **remplace entièrement** la v0.1 initiale : elle fige
> les fondamentaux algorithmiques et les contrats métier à l'issue d'un
> audit critique en plusieurs passes (voir changelog en fin de fichier).
> Les décisions actées ici sont enregistrées dans `docs/decisions.md`
> (D031-D045) — ce document porte le détail, `decisions.md` porte le choix,
> le contexte et les alternatives écartées, sans duplication.
>
> **Corrections par rapport à la v0.1** : la recommandation initiale
> "commencer par un algorithme glouton" est abandonnée (§21-22) ;
> l'ambiguïté poids-pondérés vs ordre lexicographique est tranchée en
> faveur du second (§11) ; le modèle UNSAT, initialement un principe non
> détaillé, est maintenant entièrement spécifié (§16).

## 0. Où ça s'implémentera

Le solveur/l'algorithme d'optimisation décrit dans ce document (§4-§22)
n'existe encore dans aucun code — aucune intégration OR-Tools, aucun
`OptimizationProblem`, aucune des passes éligibilité/équité/explication.
Le Lot 3 a implémenté la couche de persistance qui l'entourera : voir
`docs/planning-generation.md` pour `PlanningGeneration`, `PlanningSnapshot`
et `DutyAssignment` réellement en base aujourd'hui — un socle
délibérément étroit (D060-D065), pas une anticipation du moteur lui-même.
Ce document reste la spécification de référence à valider/affiner avant
que le moteur proprement dit ne soit codé (voir `docs/decisions.md` D031
et suivantes pour le statut de validation).

## 1. Objectif du moteur

Un planning produit doit être : **valide** (aucune contrainte HARD/
POLICY_HARD violée), **complet ou honnêtement incomplet** (jamais un
planning partiel déguisé en planning complet, §10), **équitable** sur
plusieurs dimensions séparées, **explicable** sans causalité inventée,
**historiquement cohérent**, **espacé**, **respectueux** des
indisponibilités (toujours) et des préférences (best effort borné),
**stable dans le temps** (une régénération ne repart jamais de zéro),
**reproductible** dans un environnement solveur identifié (§14),
**robuste au gaming** (§20).

## 2. Principes invariants

1. **Éligibilité d'abord, optimisation ensuite, explication enfin** — trois
   passes strictement séquentielles et non réentrantes. Aucune donnée
   d'équité n'entre dans le calcul d'éligibilité ; aucune donnée
   d'explication n'est recalculée après coup depuis un état courant.
2. **Le métier ne connaît jamais la technologie du solveur.** Toute règle
   (éligibilité, équité, espacement, historique) s'exprime dans le domaine
   et se traduit en `OptimizationProblem` abstrait (§21), jamais
   directement en variables CP-SAT.
3. **Aucune constante magique.** Tout seuil est soit une propriété
   mathématique intrinsèque du problème (ex. granularité minimale = 1
   garde), soit une règle métier explicitement nommée, unitée et
   configurable par équipe — jamais un poids ou un epsilon abstrait sans
   signification métier.
4. **Déterminisme scopé.** `même snapshot + mêmes règles + même
   algorithmVersion + même environnement solveur versionné + même seed
   explicite ⇒ même résultat`. Jamais promis au-delà de cet environnement
   identifié (§14 — pas de garantie bit-à-bit universelle inter-machines/
   inter-versions du solveur).
5. **Historique jamais recalculé depuis l'état courant.**
6. **Honnêteté de l'explication** — jamais de causalité locale inventée
   pour une décision issue d'un optimum global multi-variable ; jamais un
   silence qui laisserait croire à tort qu'une affectation était
   discrétionnaire (§17).
7. **Couverture avant optimalité** — un planning `PUBLISHED` doit couvrir
   tous les besoins obligatoires ; un planning incomplet n'est jamais
   présenté comme complet (§10).
8. **Demande fixée avant résolution** — `requiredDemand` (§5) ne varie
   jamais en fonction de ce que le solveur parvient effectivement à
   couvrir.

## 3. Taxonomie des contraintes : HARD / POLICY_HARD / SOFT

| Tier | Définition | Comportement solveur | En cas d'UNSAT |
|---|---|---|---|
| **HARD** | Contrainte physique/légale/structurelle, jamais négociable | Bloquante, infeasible-by-construction (pas de variable créée pour les paires exclues) | Jamais proposée en relaxation |
| **POLICY_HARD** | Règle métier d'équipe configurée comme absolue | Bloquante comme HARD pendant le solve | Seule catégorie éligible à une relaxation proposée à l'admin |
| **SOFT** | Objectif optimisé, jamais bloquant | Phases lexicographiques (§11) | N'apparaît jamais dans un diagnostic UNSAT |

`ExclusionReason` (tier fixe, jamais reconfigurable — un même code ne change jamais de tier) :

```
USER_INACTIVE              HARD
NOT_TEAM_MEMBER              HARD
MEMBERSHIP_OUT_OF_RANGE       HARD
UNAVAILABLE                    HARD
SITE_NOT_ALLOWED                 HARD
MISSING_SKILL                      HARD
CONFLICT                             HARD
LEGAL_MIN_REST                         HARD   (minimum réglementaire, jamais négociable)
LOCK_CONFLICT                            HARD
GROUP_UNAVAILABLE                          HARD  (dérivée : un membre du DutyGroupInstance exclu exclut le groupe entier)
TEAM_MIN_REST                                POLICY_HARD  (règle interne plus protectrice, jamais < LEGAL_MIN_REST)
MAX_DUTIES                                     POLICY_HARD
MAX_WEEKENDS                                     POLICY_HARD
MAX_CONSECUTIVE_NIGHTS                             POLICY_HARD
RULE_EXCLUSION                                       POLICY_HARD
```

`MIN_REST` est scindé en deux codes distincts plutôt qu'un seul code à tier
variable — évite qu'un même code change de comportement selon le
paramétrage d'équipe. **Point ouvert** : la valeur exacte du seuil légal
(`LEGAL_MIN_REST`) dépend du cadre réglementaire applicable aux équipes
médicales ciblées — à confirmer par un référent métier avant
implémentation, pas déduite ici. `MAX_CONSECUTIVE_NIGHTS` pourrait
nécessiter le même traitement si un plafond légal existe dans certaines
juridictions — à vérifier au moment de l'implémentation, pas tranché ici.

## 4. Éligibilité et forced assignments

### 4.1 Matrice d'éligibilité

`EligibilityService` produit, pour chaque `(DutyUnit, Candidat)`, soit
`ELIGIBLE`, soit `INELIGIBLE + reasons[]` (liste de `ExclusionReason` avec
leur tier). Un `DutyGroupInstance` (§9) est un seul `DutyUnit` — éligible
en bloc ou inéligible en bloc, jamais une demi-éligibilité.

### 4.2 STRUCTURALLY_FORCED

```
STRUCTURALLY_FORCED(dutyUnit, U) = |{ candidats HARD-éligibles à dutyUnit }| = {U}
```

Calcul local, pré-solve, coût négligeable — fait partie de la phase
Eligibility, toujours calculé exhaustivement.

### 4.3 GLOBALLY_FORCED

```
GLOBALLY_FORCED(dutyUnit, U) = Feasible(P) AND NOT Feasible(P ∧ x[dutyUnit,U] = 0)
```

où `P` est le problème STRICT complet sous HARD + POLICY_HARD. Calcul
global, nécessite `PlanningSolver.checkFeasibility` (§21) — pas une
approximation de graphe biparti (les contraintes globales du problème réel
— `MAX_DUTIES`, `MIN_REST`, `DutyPattern`, nuits consécutives — ne se
réduisent pas à un couplage biparti simple). `STRUCTURALLY_FORCED ⇒
GLOBALLY_FORCED` toujours.

**Stratégie de coût** (la sémantique reste exacte, la couverture est
bornée par le budget) :
1. `STRUCTURALLY_FORCED` calculé exhaustivement, toujours, avant le solve.
2. `GLOBALLY_FORCED` **jamais** calculé exhaustivement avant le solve.
   Pré-filtrage sur les paires `(dutyUnit, candidat réellement assigné)`
   où un indice bon marché suggère une contrainte serrée (candidat proche
   d'un plafond POLICY_HARD, groupe à faible effectif éligible, `MIN_REST`
   rendant des gardes voisines mutuellement exclusives sur un petit pool).
   Résultats mis en cache par `(snapshotHash, excludedEdge)`.
3. Si le budget ne permet pas de couvrir toutes les paires filtrées, le
   calcul reste disponible à la demande (explication ponctuelle) sans être
   exhaustif — limite assumée de la v1, documentée comme telle (§17).

`ForcedReason : STRUCTURAL_UNIQUE_CANDIDATE | GLOBAL_CONSTRAINT_NECESSITY`
— provenance toujours persistée quand elle influence l'explication ou la
fairness.

### 4.4 Forced load et fairness — traitement à deux niveaux temporels

```
totalLoad(user, d)              = raw(user, d)                     // alimente maxDuties, minRest, espacement, sécurité — sans exception
discretionaryLoadAtSolve(user,d)= raw(user, d) − structurallyForcedLoad(user, d)
discretionaryLoadHistorical(user,d) = raw(user, d) − structurallyForcedLoad(user, d) − globallyForcedLoad(user, d)
```

`discretionaryLoadAtSolve` pilote l'objectif du solve **en cours**
(§11, phases fairness) — parce que `structurallyForcedLoad` est toujours
connu avant de résoudre, contrairement à `globallyForcedLoad`.
`discretionaryLoadHistorical` (calculé après coup, potentiellement
asynchrone/best-effort) alimente le **registre utilisé pour les cibles des
périodes futures** — une garde globalement forcée cette année n'est jamais
comptée comme un choix qui justifierait d'en donner moins l'an prochain.

Cette séparation temporelle (pas seulement une séparation de nature)
résout la tension entre les deux principes à préserver : ne pas pénaliser
quelqu'un pour une garde structurellement/globalement imposée, sans
neutraliser artificiellement trop de gardes et détruire la précision du
signal d'équité du solve en cours.

## 5. Exposition structurelle et `requiredDemand`

```
structuralOpportunity(user, duty) ∈ {0,1}
  = 1 ssi user aurait été structurellement candidat à duty (membership,
    site, habilitation, type de garde, période d'activité) —
    IGNORE indisponibilité personnelle, préférence, refus.
    N'INCLUT PAS participationFactor (pas un signal d'éligibilité).

effectiveExposure(user, d) = Σ_{duty ∈ d} structuralOpportunity(user, duty) × participationFactor(user, duty.date)
```

`participationFactor` entre exactement une fois, évalué à la date de
chaque garde individuelle — gère nativement un changement de facteur en
cours de période sans recalcul spécial.

`requiredDemand(d)` — fixé à la construction du snapshot depuis les `Duty`
`REQUIRED`, **jamais recalculé après un solve partiel** :

```
requiredDemand(TOTAL)                   = |{ Duty requis de la période }|
requiredDemand(WEEKEND_GROUPS)          = |{ DutyGroupInstance classé week-end }|
requiredDemand(FRIDAY|SATURDAY|SUNDAY)  = |{ Duty requis dont la date tombe ce jour }|
requiredDemand(HOLIDAY)                 = |{ Duty requis dont la date correspond à un HolidayDefinition }|
requiredDemand(NAMED_HOLIDAY[code])     = |{ Duty requis dont la date correspond à ce holidayCode }|
requiredDemand(NIGHT | dutyType)        = |{ Duty requis de ce DutyType }|
requiredDemand(WEIGHTED_WORKLOAD)       = Σ_{Duty requis} dutyType.weight    (unité de charge, pas un compte)

target(user, d) = requiredDemand(d) × effectiveExposure(user, d) / Σ_v effectiveExposure(v, d)
```

Une seule `Duty` (ex. Noël un dimanche) incrémente `TOTAL`, `SUNDAY`,
`HOLIDAY`, `NAMED_HOLIDAY[CHRISTMAS]` indépendamment — plusieurs
compteurs sur la même unité de demande, jamais fragmentée.

**`Duty.demandType : REQUIRED | OPTIONAL`** — les `OPTIONAL` n'entrent
jamais dans `requiredDemand`, ne participent jamais à la contrainte de
couverture STRICT, ne peuvent jamais causer d'UNSAT. Si assignées, elles
alimentent `totalLoad`/sécurité mais sont suivies dans une dimension
séparée `optionalLoad`, jamais mêlées au calcul de déviation ancré sur
`requiredDemand`.

**Cas limites** : target fractionnaire jamais arrondi dans le calcul de
déviation (l'arrondi n'intervient que dans un affichage de synthèse) ;
petit échantillon (`Σ_v effectiveExposure(v,d)` sous un seuil configurable)
→ dimension suivie/affichée mais rétrogradée en phase secondaire non
déterminante ; membre présent quelques semaines → géré nativement par la
fenêtre de membership dans `structuralOpportunity` ; membre à exposition
nulle sur une dimension → `target = 0` exactement, jamais un déficit ;
dimension sans aucun candidat exposé → ignorée entièrement, pas de
division par zéro.

## 6. Modèle de fairness multidimensionnel

Dimensions suivies indépendamment : `totalDuties`, `weightedWorkload`,
`weekendGroups`, `fridays`, `saturdays`, `sundays`, `holidays`,
`namedHolidays[perHolidayCode]`, `nights`, `dutyTypeSpecific[...]`,
`forcedLoad` (structural + global), `discretionaryLoad`, `optionalLoad`.

`weightedWorkload` et `totalDuties` restent suivis mais **jamais promus en
critère d'arbitrage primaire** (principe non négociable de `CLAUDE.md`).

```
raw(user, d)       = nombre réel de gardes assignées dans la dimension d (post-solve)
deviation(user, d) = discretionaryLoadAtSolve(user,d) − discretionaryTarget(user,d)   // pendant le solve
```

**Normalisation** — convention v1, documentée comme telle (pas une vérité
mathématique universelle), choisie pour empêcher les petites cibles de
dominer artificiellement le score relatif :

```
smallestUnit(d) = 1                                    // dimensions comptées en gardes
smallestUnit(WEIGHTED_WORKLOAD) = plus petite unité de charge représentable par le modèle

scale(user, d) = max(target(user, d), smallestUnit(d))
normalizedDeviation(user, d) = deviation(user, d) / scale(user, d)
```

**Tests adversariaux obligatoires** avant toute implémentation du calcul
de déviation : `target ∈ {0, 0.1, 0.5, 0.9, 1, 2, 10}`, plus au moins une
distribution d'équipe très asymétrique.

**Invariant de contrôle** (à vérifier en test) : `Σ_user target(user,d) =
requiredDemand(d)` et `Σ_user raw(user,d) ≤ requiredDemand(d)` (égalité
seulement si `coverageStatus = COMPLETE`) — une violation signale un bug
de calcul de cible.

```
positiveDeviation(user,d) = max(0, deviation(user,d))
negativeDeviation(user,d) = max(0, −deviation(user,d))
maxDeviation(D) = max_{user,d∈D} |normalizedDeviation(user,d)|
sumDeviation(D) = Σ_{user,d∈D} |normalizedDeviation(user,d)|
```

Min-max sur `|normalizedDeviation|` (pas seulement la déviation positive)
— une déviation négative marquée signale un sous-service systémique, pas
seulement l'absence de surcharge.

**Dimensions primaires par défaut** (configurable par équipe) :
`weekendGroups`, `namedHolidays`. **Secondaires** : `fridays`, `saturdays`,
`sundays`, `holidays`, `nights`, `dutyTypeSpecific`, `weightedWorkload`,
`totalDuties`.

## 7. Historique des fériés nommés

```
holidayPenalty(user, holidayCode) = Σ_{occurrences passées o} decayFactor ^ yearsAgo(o)
```

`decayFactor ∈ (0,1)`, configurable par équipe (défaut proposé `0.5`) —
décroissance exponentielle continue, pas de fenêtre fixe ni de coupure
dure. Toutes les occurrences restent stockées et consultables
indéfiniment, seul leur poids diminue. `holidayCode` stable (`CHRISTMAS`,
`NEW_YEAR`, `EASTER`, …), pas une date (Pâques est mobile). Nouveau
membre : aucune entrée = poids nul, neutre, jamais un déficit à rattraper.
Historique importé : `source: IMPORTED`, pondéré identiquement, provenance
toujours affichée en explication.

## 8. Espacement

```
spacingScore(user, duty) = f(
  daysSinceLastAssignedDuty, daysUntilNextLockedDuty, weekendAdjacencyPenalty,
  density7, density14, density30, consecutiveDaysPenalty, consecutiveDutyGroupsPenalty
)
```

Toujours en jours calendaires (arithmétique de dates, jamais de durée en
heures — un changement d'heure DST n'affecte jamais un calcul de distance
en jours). Jamais réinitialisé au 1er janvier, ni au début d'une
`FairnessPeriod`/`PlanningPeriod` — propriété physique continue du
calendrier du membre.

## 9. DutyPattern / DutyGroupInstance

`DutyGroupInstance` = instance concrète d'un `DutyPattern`, traitée comme
**un seul nœud du problème d'optimisation** — éligibilité et atomicité
garanties par construction, pas par une contrainte ajoutée après coup.
Groupe traversant deux mois/années/`FairnessPeriod` : aucun traitement
spécial, chaque dimension créditée par la date réelle de chaque garde
constituante. Verrouillage partiel incohérent (deux membres du groupe
verrouillés à des titulaires différents) : erreur d'intégrité détectée à
la construction du snapshot, jamais une surprise du solveur. Maximum
atteint uniquement si le groupe entier est ajouté : garanti nativement
(un seul nœud, charge complète en bloc).

## 10. Modèle strict / partial diagnostic solve

Aucune garde `UNASSIGNED` n'existe dans le `OptimizationProblem` de base
(§21) — le problème STRICT reste pur.

```
1. Construire OptimizationProblem STRICT (couverture = 1 stricte, Duty REQUIRED seulement)
2. solve(STRICT) → result

3. si result.status ∈ {OPTIMAL, FEASIBLE} :
     coverageStatus = COMPLETE ; retourner result (partialSolverStatus = null)

4. si result.status = UNSATISFIABLE :
     construire OptimizationProblem PARTIAL (transformation slack : unassigned[d] ajouté,
       Σx[d][c] + unassigned[d] = 1)
     objectivePhases PARTIAL = [
       minimize count(unassigned ∩ CRITICAL),
       minimize count(unassigned),
       ...phases normales du mode (§11), calculées sur le sous-ensemble assigné
     ]
     solve(PARTIAL) → partialResult
     coverageStatus = (partialResult.unassignedDuties.length == 0) ? COMPLETE : INCOMPLETE
     si partialResult.status = UNSATISFIABLE :
         diagnostic distinct et plus grave : conflit HARD indépendant de toute nouvelle
         affectation (ex. verrouillages existants en conflit LEGAL_MIN_REST) —
         jamais confondu avec un déficit de couverture normal (`existingDataConflict`, §16)

5. si result.status ∈ {UNKNOWN, ERROR} :
     ne jamais basculer en PARTIAL sur cette base — retenter STRICT avec budget étendu,
     ou surfacer l'échec technique
```

`Duty.criticality : STANDARD | CRITICAL` (défaut `STANDARD`) pilote la
phase 1 du solve PARTIAL. `CoverageStatus : COMPLETE | INCOMPLETE`.

**Règle de publication** : `PUBLISHED` nécessite `coverageStatus =
COMPLETE`. Aucun override admin en v1 — ajouté seulement si un besoin réel
apparaît.

**OPTIMAL / FEASIBLE / UNSATISFIABLE / UNKNOWN / ERROR — politique produit** :

| Statut | Peut persister ? | Transition lifecycle |
|---|---|---|
| `OPTIMAL` + `COMPLETE` | Oui | Peut avancer vers VALIDATED normalement |
| `FEASIBLE` + `COMPLETE` | Oui | Validation admin explicite obligatoire, warning "optimalité non prouvée" — sauf REPAIR à changeCost sous seuil configuré et sans dépassement de tolérance de fairness, alors acceptable sans validation supplémentaire |
| `UNSATISFIABLE` (strict, avant partial) | N/A | Bascule automatique vers PARTIAL (§10) |
| `INCOMPLETE` (après partial) | Non pour PUBLISHED | Reste à GENERATED, `UnsatReport`/couverture incomplète affichés |
| `UNKNOWN` | Non | Jamais assimilé à UNSAT — retenté ou surfacé "calcul non concluant" |
| `ERROR` | Non | Erreur système, jamais confondue avec un UNSAT métier |

## 11. Optimisation lexicographique par mode

### GENERATE

```
Phase 0  HARD + POLICY_HARD (précondition)
Phase 1  couverture complète (mécanisme strict/partial, §10 — pas une phase d'optimisation classique)
Phase 2  minimize maxDeviation(PRIMARY)     sur discretionaryLoadAtSolve
Phase 3  minimize sumDeviation(PRIMARY)
Phase 4  minimize maxDeviation(SECONDARY) puis sumDeviation(SECONDARY)
Phase 5  minimize pénalité de répétition de fériés nommés
Phase 6  maximize score d'espacement
Phase 7  maximize préférences satisfaites (dans l'espace figé par 2-6)
Phase 8  tie-break déterministe
```

Le passage min-max-puis-somme s'applique à PRIMARY **et** SECONDARY (pas
seulement PRIMARY) — sinon une personne très pénalisée sur une dimension
secondaire pourrait passer inaperçue derrière une bonne moyenne.

### REPAIR

La fairness devient une **contrainte de non-régression**, pas un objectif
actif — cohérent avec l'objectif de changement minimal.

```
Phase 0  HARD + POLICY_HARD, sur le sous-ensemble invalidé/non verrouillé uniquement
Phase 1  retrouver coverageStatus = COMPLETE (mécanisme strict/partial appliqué au sous-problème)
Phase 2  minimize changedAssignmentCount restreint aux assignations PUBLISHED
Phase 3  minimize changeCost total (pondéré)
Phase 4  contrainte : maxDeviation(PRIMARY) ≤ maxDeviation(PRIMARY) pré-réparation + tolérance nommée
Phase 5  idem SECONDARY
Phase 6  espacement, préférences — uniquement sur les gardes effectivement retouchées
Phase 7  tie-break
```

```
changedAssignmentCount = |{ dutyUnit : newAssignee ≠ previousAssignee }|     // compte brut

changeCost(dutyUnit) = f(
  planningLifecycleState (DRAFT<GENERATED<VALIDATED<PUBLISHED, coût croissant),
  daysUntilDuty (proche = coût élevé),
  userAlreadyNotified (coût élevé si oui),
  dutyConfirmedByUser (coût élevé si oui),
  wasManuallyModified (coût élevé — ne pas défaire une décision humaine sans raison forte)
)
```

Phase 2 limite la surface perçue de perturbation (compte brut) ; Phase 3
arbitre entre deux changements de gravité différente (déplacer une garde
dans 3 mois vs une garde confirmée demain) — les deux sont nécessaires.

### SIMULATE

Même ordre que le mode simulé (GENERATE ou REPAIR) — pas un troisième
ordonnancement. `persist=false`, aucune notification.

```
SimulationReport { before, after, changedAssignments[{duty, previousAssignee, newAssignee, changeCost}],
                    fairnessDelta, coverageStatus, diagnostics }
```

## 12. Préférences

`PREFER_DUTY` maximisé en dernière phase SOFT (phase 7 GENERATE / phase 6
REPAIR), strictement à l'intérieur de l'espace de solutions déjà contraint
par les phases précédentes. Deux mécanismes seulement, jamais un epsilon
abstrait :

1. **Défaut : préservation exacte** — phases fairness pire-cas (2, 4) et
   fériés nommés (5) préservées par égalité stricte de leur valeur
   optimale, aucune tolérance par défaut.
2. **Tolérance explicite, nommée, par dimension, en unités réelles** —
   configurable par équipe uniquement sur les phases "qualité globale"
   (lissage secondaire, espacement), jamais sur un score composite
   abstrait (`maxWeekendDeviationTolerance: 1`, jamais `epsilon: 0.05`).

## 13. Tie-break déterministe

```
seedMaterial = teamStableKey + planningPeriodStableKey + rulesVersion + snapshotHash
             + algorithmVersion + solverParameterSetVersion + explicitSeed

tieBreakKey = StableHash(seedMaterial + dutyStableKey + candidateStableKey)
```

`dutyStableKey` exige que `Duty` conserve une **identité stable à travers
les régénérations d'une même `PlanningPeriod`** (créée une fois, jamais
recréée) — condition dure, pas une recommandation. `explicitSeed` dérivé
par défaut de `(teamStableKey, planningPeriodStableKey)`, override
possible en `SIMULATE` pour l'exploration. Utiliser un ID comme matière
première de hash est sûr (propriété d'avalanche de la fonction de hash,
aucune corrélation avec l'ordre des entrées) — c'est **trier/comparer**
directement par ID qui est interdit, pas l'utiliser comme ingrédient d'un
hash. Compteur de "victoires de tie-break" : non retenu en v1, pas de
donnée empirique démontrant un biais structurel — à réévaluer seulement si
un audit d'exécution réelle en démontre un.

## 14. Snapshot et versioning

> **Statut d'implémentation (Lot 3, `docs/planning-generation.md`)** :
> `PlanningSnapshot` implémente le principe "snapshot hybride" ci-dessous
> pour les données déjà modélisées (membres, participation, disponibilités,
> non-participation, `PlanningRuleSet`) — mais sans `snapshotHash`/format
> canonique, sans `SolverParameterSet`, sans détection de concurrence par
> hash (§15 ci-dessous reste non implémenté ; le Lot 3 détecte la
> concurrence par statut + contrainte unique, une méthode plus simple
> suffisante en l'absence de solve asynchrone). `algorithmVersion`,
> `solverType/Version`, `seed`, `mode`, `coverageStatus`,
> `solverMetadata` n'existent pas encore sur `PlanningGeneration` — ils
> supposent un solveur qui n'existe pas.

**Snapshot hybride** : références vers les objets structurants + copie
immuable des données qui influencent réellement le calcul.

**Format canonique pour le hash** : clés triées alphabétiquement, pas
d'espaces, nombres en chaînes décimales de précision fixe (jamais un
flottant natif), tableaux triés par clé stable avant sérialisation.

**Entre dans le hash** : membres + `participationFactor` historisé +
fenêtres de membership, gardes + dates + types, indisponibilités,
préférences, verrouillages, `rulesVersion`, métriques historiques
utilisées, historique fériés utilisé.

**N'entre jamais dans le hash** : timestamps techniques sans valeur
métier (`createdAt`/`updatedAt` d'audit DB, heure de calcul du snapshot,
métadonnées de cache).

```
snapshotHash = SHA-256(canonicalSnapshotJson)
```

**Reproductibilité — contrat corrigé** : MedVue garantit la
reproductibilité **dans un environnement de solveur explicitement
versionné et configuré de manière déterministe** — pas une promesse
bit-à-bit universelle entre toutes les machines et toutes les versions
d'OR-Tools.

`PlanningGeneration` — persisté directement :
```
algorithmVersion, rulesVersion, snapshotId, snapshotHash,
solverType, solverVersion, solverParameterSetVersion, seed, mode,
generatedAt, coverageStatus,
solverMetadata: { solveDurationMs, timeout, optimalityStatus (par phase), objectiveValues (par phase) }
```

`solverParameterSetVersion` référence un `SolverParameterSet` **versionné
séparément** (append-only, jamais modifié en place, cohérent avec le
traitement de `PlanningRuleSet`) : nombre de workers, deterministic mode,
search strategy, timeout, preprocessing options — jamais dupliqué en clair
sur chaque génération.

**Identité reproductible d'une génération** : `snapshotHash + rulesVersion
+ algorithmVersion + solverType + solverVersion + solverParameterSetVersion
+ seed + mode`. Deux générations partageant ce tuple exact sont attendues
identiques ; MedVue ne promet rien au-delà.

## 15. Concurrence

```
T0  snapshot figé, snapshotHash = H0
T1  solve lancé (asynchrone)
T2  mutation externe des données sous-jacentes
T3  solve termine, résultat calculé contre H0
T4  tentative de persist :
      currentStateHash recalculé
      si ≠ H0 : rejet atomique, rapport de conflit structuré (diff champ par champ),
                aucune écriture partielle
      sinon : persist + incrémentation de version optimiste (ex. PlanningPeriod.snapshotVersion)
```

Jamais de publication silencieuse sur données obsolètes.

## 16. Modèle UNSAT

```json
{
  "strictSolverStatus": "UNSATISFIABLE",
  "partialSolverStatus": "OPTIMAL",
  "coverageStatus": "INCOMPLETE",
  "requiredDutyCount": 47,
  "assignedDutyCount": 46,
  "unassigned": [
    { "dutyId": "...", "criticality": "STANDARD",
      "candidateExclusions": { "A": [{"reason":"MAX_DUTIES","tier":"POLICY_HARD"}],
                                "B": [{"reason":"UNAVAILABLE","tier":"HARD"}] } }
  ],
  "structuralDiagnostics": [
    { "cause": "MAX_DUTIES_PER_MEMBER", "tier": "POLICY_HARD", "required": 10, "capacity": 8 }
  ],
  "solverAnalysis": { "available": true, "infeasibleCore": ["..."] },
  "diagnosticRelaxations": [
    { "wouldRelax": "MAX_WEEKENDS", "tier": "POLICY_HARD",
      "phrasing": "La suppression de la règle MAX_WEEKENDS permettrait de retrouver une couverture complète.",
      "disclaimer": "Une relaxation possible parmi d'autres — pas nécessairement la cause unique." }
  ],
  "existingDataConflict": null
}
```

Quatre couches, dans cet ordre de calcul : (A) précalcul de capacité
déterministe, (B) matrice locale d'exclusions par garde/groupe, (C)
analyse native du solveur si disponible (cœur d'infaisabilité), (D)
relaxations diagnostiques — toujours au conditionnel, **jamais sur une
contrainte HARD**. `existingDataConflict` se remplit uniquement si le
solve PARTIAL lui-même est `UNSATISFIABLE` (§10.5) — diagnostic distinct
et plus grave qu'un simple déficit de couverture.

## 17. Explicabilité

```json
{
  "dutyId": "...",
  "selectedCandidate": "...",
  "forcedness": {
    "status": "GLOBALLY_FORCED",
    "reason": "GLOBAL_CONSTRAINT_NECESSITY",
    "analysisPerformed": true,
    "phrasing": "A n'était pas le seul candidat localement éligible, mais l'analyse globale a montré que cette affectation était nécessaire pour conserver une solution complète."
  },
  "eligibleCandidates": [ { "userId":"...", "discretionaryMetrics": {...}, "spacingScore": 18, "preferenceApplied": true } ],
  "excludedCandidates": [ { "userId":"...", "reasons": [{"code":"UNAVAILABLE","tier":"HARD"}] } ],
  "optimizationPhases": [ { "phase": 2, "quantity": "maxDeviation(PRIMARY)", "achievedValue": 0.31, "optimalityProven": true } ],
  "decisivePhase": 2,
  "tieBreakApplied": false,
  "rulesVersion": "...", "algorithmVersion": "...", "seed": "..."
}
```

Règle d'honnêteté : les exclusions sont de vraies causalités locales
("B écarté car MAX_DUTIES"). La sélection parmi les candidats éligibles
n'est jamais formulée par comparaison locale — toujours par référence à la
phase décisive ("cette affectation fait partie d'une solution globalement
optimale pour les phases 1 à N ; remplacer A par B dégraderait la
phase N+1"). Si `GLOBALLY_FORCED` n'a pas été calculé pour cette
affectation (§4.3), `analysisPerformed = false` et la phrase le dit
explicitement — jamais un silence laissant croire à une discrétion qui n'a
pas été vérifiée.

Ce qui doit être figé (persisté) : tout ce qui alimente réellement
l'objectif du solveur (cibles/expositions au moment T, `objectiveValues`
par phase, `forcedLoad`). Ce qui peut être recalculé à la demande :
formatage d'affichage, agrégations purement présentationnelles.

## 18. Lifecycle du planning

```
DRAFT → GENERATED → VALIDATED → PUBLISHED → ARCHIVED
```

| État | Régénérer | Réparer | Modif. manuelle | Audit |
|---|---|---|---|---|
| DRAFT | Oui, libre | N/A | Oui, libre | Non |
| GENERATED | Oui (écrase) | Oui | Oui (divergence trackée) | Oui, dès validation |
| VALIDATED | Oui (avertissement) | Oui | Oui | Oui |
| PUBLISHED | Non | Oui uniquement | Oui, jamais silencieuse | Oui, systématique |
| ARCHIVED | Non | Non | Non | — (figé, jamais supprimé) |

`PUBLISHED` requiert `coverageStatus = COMPLETE` (§10). `FEASIBLE +
COMPLETE` bloque le passage automatique GENERATED → VALIDATED (validation
admin explicite, warning "optimalité non prouvée") ; `OPTIMAL + COMPLETE`
peut avancer normalement (§10, table de politique produit).

## 19. DutyAssignment, audit trail, assigned vs performed

> **Statut d'implémentation (Lot 3, `docs/planning-generation.md`)** :
> seul l'état courant existe (`DutyAssignment` — `duty`, `teamMember`,
> `snapshotMember`, `source: AUTO|MANUAL|SWAP`, `locked`), créé
> uniquement en `MANUAL` via un endpoint admin minimal.
> `DutyAssignmentEvent` (append-only) et les statuts
> `PLANNED/PERFORMED/CANCELLED/REPLACED` ci-dessous restent entièrement
> non implémentés — ce lot ne mute jamais une affectation après
> création, donc l'invariant "toute mutation accompagnée d'un événement"
> n'a encore rien à garantir.

État courant séparé de l'historique append-only :

```
DutyAssignment { duty, currentAssignee, status: PLANNED|PERFORMED|CANCELLED|REPLACED,
                 source: AUTO|MANUAL|SWAP|REPAIR, locked, currentGenerationRef, timestamps }

DutyAssignmentEvent { assignmentRef, eventType: CREATED|REASSIGNED|LOCKED|UNLOCKED|CANCELLED|PERFORMED|REPLACED|REVERTED,
                       previousAssignee, newAssignee, reason, actor, generationRef, timestamp, metadata }
```

Invariant applicatif : toute mutation de `DutyAssignment` est
obligatoirement accompagnée, dans la même transaction, d'exactement un
nouveau `DutyAssignmentEvent` — jamais d'`UPDATE` silencieux (cohérent
avec D023).

Fairness historique : dates passées → issue réelle (`PERFORMED` ou
remplaçant effectif de `REPLACED`), jamais l'assignation initiale une fois
remplacée. Dates futures dans une période en cours → état `PLANNED`
courant, toujours marqué provisoire dans tout rapport.
`ForcedAssignmentRecord` rattaché à `DutyAssignment` : `structurallyForced,
globallyForced (bool|UNKNOWN), forcedReason, analysisPerformed`.

## 20. Taxonomie des absences et `participationFactor`

Deux mécanismes seulement, pas cinq catégories séparées :

1. **Événements bloquants d'éligibilité** — `UserAvailabilityType::UNAVAILABLE`
   (terminologie réellement implémentée, lot disponibilités — voir
   `docs/availability.md`; ce document employait auparavant
   `PERSONAL_UNAVAILABILITY`, jamais codé) (vacances ponctuelles, contrainte
   personnelle déclarée) → `eligibility = false` pour les gardes concernées,
   `structuralOpportunity` **inchangée**. Un utilisateur ne peut jamais
   améliorer son ratio d'équité en déclarant massivement des
   indisponibilités. Le pendant SOFT, `UserAvailabilityType::PREFER_DUTY`,
   est stocké et exposé par le même lot mais ne touche ni `eligibility` ni
   `structuralOpportunity` — seul un futur `optimizationPreference` en
   tiendra compte (non implémenté).
2. **Événements réducteurs de capacité** — congé long approuvé,
   suspension, congé maternité/paternité long, mise temporaire hors pool,
   changement contractuel officiel → modélisés **exclusivement** via
   `ParticipationFactorTimeline` (`participationFactor(user, date) = 0` ou
   réduit sur la fenêtre concernée), jamais une catégorie séparée. Seul un
   acte administratif modifie ce facteur, jamais l'utilisateur
   unilatéralement.

`MEMBERSHIP_OUT_OF_RANGE` (avant entrée / après sortie) : `eligibility =
false` **et** `structuralOpportunity = 0`.

**Troisième mécanisme, ajouté par le lot disponibilités** (voir
`docs/availability.md`), distinct des deux ci-dessus :
`TeamMemberNonParticipationPeriod`, une fenêtre temporaire *par équipe*
(attachée au `TeamMember`, jamais au `User`) où `eligibility = false`
**et** `structuralOpportunity = 0` pour cette équipe uniquement — sans
toucher `participationFactor` ni les autres équipes du même `User`. Ce
n'est ni une indisponibilité personnelle (mécanisme 1, transversale à
toutes les équipes) ni un changement de `participationFactor` (mécanisme
2, modélise un taux réduit durable, pas une fenêtre à opportunité nulle).
Seul le modèle de données est implémenté à ce stade — aucun
`EligibilityService`/moteur ne lit encore ce champ.

`ParticipationFactorTimeline` : segments `{value, effectiveFrom,
effectiveTo, changeReason}` par `TeamMember`, **append-only** — un
changement ajoute un segment, ne modifie jamais un segment passé. Jamais
de recalcul rétroactif de l'exposition passée avec le facteur courant.
`changeReason : ADMINISTRATIVE_LEAVE | SUSPENSION | CONTRACTUAL_CHANGE |
RETURN_TO_FULL_PARTICIPATION | ...` — métadonnée d'audit, pas un second
mécanisme de calcul.

**Preuve de résistance au gaming** : un utilisateur qui déclare 70
indisponibilités personnelles sur 100 gardes structurellement possibles ne
touche jamais `participationFactor`/`target`. Son `raw` chute (HARD-exclu
de la plupart des gardes), donc `deviation` devient fortement négative —
visible, jamais récompensée. `minimize max(|normalizedDeviation|)` rend ce
déséquilibre un signal actif que l'optimiseur voudrait corriger mais ne
peut pas (l'utilisateur reste HARD-exclu par ses propres déclarations) —
le déséquilibre reste visible, correctement attribué, absorbé par le
reste de l'équipe, sans jamais améliorer le ratio apparent du déclarant.

## 21. Contrats abstraits

```
OptimizationProblem {
  requiredDuties: [DutyUnit]              // Duty ou DutyGroupInstance, demandType=REQUIRED
  optionalDuties: [DutyUnit]              // jamais dans la contrainte de couverture, jamais cause d'UNSAT
  requiredDemand: [dimension → value]     // fixé avant tout solve, §5
  eligibilityMatrix: [(dutyUnitId, candidateId) → {eligible, tier si exclu}]
  fixedAssignments: [(dutyUnitId, candidateId)]
  structurallyForcedLoad: [(candidateId, dimension) → count]
  fairnessTargets: [(candidateId, dimension) → discretionaryTargetAtSolve]
  dimensionMembership: [dutyUnitId → {dimension: weight}]
  objectivePhases: [PhaseDefinition]      // ordonnées, §11, dépend du mode
  changeCostByUnit: [dutyUnitId → cost]   // REPAIR seulement
  coveragePolicy: { requireStrictFirst: true, criticalDutyUnits: [dutyUnitId] }
  seedMaterial: string
  mode: GENERATE | REPAIR | SIMULATE
  timeoutBudget
}

OptimizationResult {
  strictSolverStatus: OPTIMAL|FEASIBLE|UNSATISFIABLE|UNKNOWN|ERROR
  partialSolverStatus: OPTIMAL|FEASIBLE|UNSATISFIABLE|UNKNOWN|ERROR|null
  coverageStatus: COMPLETE|INCOMPLETE
  assignments: [(dutyUnitId, candidateId)]
  unassignedDuties: [{dutyUnitId, criticality, candidateExclusions}]
  objectiveValues: [phase → value]
  optimality: [phase → proven: bool]
  diagnostics: UnsatReport | null
  snapshotHash
  solverMetadata: { solverType, solverVersion, solverParameterSetVersion, solveDurationMs, timeoutHit, parameters }
}

interface PlanningSolver {
  solve(problem: OptimizationProblem) → OptimizationResult
  checkFeasibility(problem: OptimizationProblem, excludedEdges: [(dutyUnitId, candidateId)]) → bool
}
```

`UNASSIGNED` n'existe jamais dans le `OptimizationProblem` de base — le
problème STRICT reste pur. Le problème PARTIAL est une transformation
dérivée et contrôlée (slack `unassigned[d]`), construite par
`OptimizationModelBuilder` (domaine), jamais par le contrat abstrait
lui-même. `analyzeForcedAssignments` n'entre **pas** dans l'interface
`PlanningSolver` — c'est une composition de `checkFeasibility` orchestrée
par `ForcedAssignmentAnalyzer` (domaine), indépendante du solveur utilisé.

## 22. Mapping conceptuel vers OR-Tools CP-SAT (adapter, non contractuel)

Variables `x[dutyUnit][candidate] ∈ {0,1}` créées uniquement pour les
paires éligibles (infeasible-by-construction). Contrainte de couverture
`Σ_c x[d][c] = 1` par `dutyUnit` requis (STRICT) ou `+ unassigned[d]`
(PARTIAL). Atomicité des groupes automatique (un seul `dutyUnit`).
Verrouillages : `x[d][c*]=1` fixé, pas de variables pour les autres
candidats. Compteurs par dimension et déviation : variables auxiliaires
entières, cibles fractionnaires mises à l'échelle (facteur fixe, ex.
×10 000) pour rester déterministe. Min-max : variable `worst` bornant les
quantités concernées, objectif `minimize worst`. Phases lexicographiques :
appels `Solve()` répétés, chaque phase ajoutant une contrainte d'égalité
sur la valeur optimale de la phase précédente — pas de multi-objectif
lexicographique natif dans CP-SAT, piloté côté adapter. `GLOBALLY_FORCED`
: `checkFeasibility` via assumptions/résolution incrémentale (détail
d'implémentation de l'adapter). Statuts CP-SAT natifs
(`OPTIMAL`/`FEASIBLE`/`INFEASIBLE`/`UNKNOWN`/`MODEL_INVALID`) mappés
quasi directement vers le contrat abstrait (§10), `MODEL_INVALID` et toute
exception technique devenant `ERROR`.

## 23. Architecture backend

```
Domain (indépendant du solveur)
  EligibilityService, ForcedAssignmentAnalyzer, DutyGroupingService,
  FairnessContextBuilder, FairnessMetricsService, FairnessTargetService,
  HolidayFairnessService, SpacingService, PreferenceService, ChangeCostService

Optimization boundary
  OptimizationProblemBuilder, PlanningSolver (interface), OrToolsPlanningSolver (adapter)

Orchestration
  PlanningPipeline (interne, paramétré GENERATE/REPAIR/SIMULATE + persist)
  PlanningGenerationService / PlanningRepairService / PlanningSimulationService (façades fines)
  PlanningSnapshotService, PlanningVersionService,
  PlanningExplanationService, UnsatExplanationService
```

Aucune logique métier dans les contrôleurs — le contrôleur désérialise,
appelle une façade, sérialise `OptimizationResult`/`UnsatReport`.

## 24. Modèle de données conceptuel

`FairnessPeriod`, `PlanningPeriod` (lifecycle §18), `PlanningRuleSet`
(versionné), `HolidayDefinition`, `DutyType`, `DutyPattern`,
`DutyGroupInstance`, `Duty` (identité stable à travers les régénérations,
`demandType: REQUIRED|OPTIONAL`, `criticality: STANDARD|CRITICAL`),
`DutyAssignment` + `DutyAssignmentEvent` (§19), `PlanningGeneration`
(§14), `PlanningSnapshot`, `SolverParameterSet` (versionné),
`AssignmentExplanation` (§17), `UnsatReport` (§16),
`ForcedAssignmentRecord` (§19), `ParticipationFactorTimeline` (§20),
`UserAvailabilityPeriod` (`UserAvailabilityType::UNAVAILABLE | PREFER_DUTY`,
implémenté — voir `docs/availability.md`), `TeamMemberNonParticipationPeriod`
(implémenté, même document).

## 25. Matrice de tests (résumé, complète les scénarios S01-S30 déjà identifiés)

Forced-load stable quel que soit l'ordre d'entrée des `dutyUnits` ;
résistance au gaming (70 indispos déclarées, §20) ; congé long approuvé vs
indisponibilité personnelle produisant des targets différents ; snapshot
hash stable pour deux lectures DB dans un ordre différent ; génération
concurrente avec mutation entre T0 et T4 ; `FEASIBLE` non auto-publié sans
validation admin ; `UNKNOWN` jamais affiché comme "impossible" ; REPAIR :
équité en contrainte de non-régression ; phase secondaire en min-max, pas
en somme pure ; groupe traversant deux `FairnessPeriod` crédite chaque
année correctement ; tie-break reproductible sur deux exécutions
identiques ; décroissance exponentielle des fériés sans coupure brutale ;
tests adversariaux de normalisation (`target ∈ {0, 0.1, 0.5, 0.9, 1, 2,
10}` + distribution asymétrique) ; STRICT UNSATISFIABLE → PARTIAL
COMPLETE (cas rare, §10) ; PARTIAL lui-même UNSATISFIABLE →
`existingDataConflict` distinct d'un déficit de couverture normal ;
`requiredDemand` stable malgré un solve PARTIAL laissant des gardes non
pourvues.

---

## Historique des révisions

| Version | Date | Changement |
|---|---|---|
| v0.1 | 2026-09-15 | Première version : design conceptuel complet, rien d'implémenté. |
| v2.0 | 2026-09-15 | Spécification finale v1 à l'issue d'un audit critique en trois passes. Remplace la recommandation greedy (§14 v0.1) par une architecture d'optimisation globale par contraintes (CP-SAT/OR-Tools) derrière l'abstraction `PlanningSolver`. Tranche l'ambiguïté poids-pondérés/lexicographique en faveur de l'optimisation lexicographique par phases. Spécifie entièrement le modèle UNSAT (quatre couches, strict/partial diagnostic solve, `coverageStatus`). Ajoute : exposition structurelle par garde, `requiredDemand` fixé avant solve, forced load à deux niveaux (structural/global), taxonomie HARD/POLICY_HARD/SOFT, snapshot hybride versionné, `DutyAssignment`/`DutyAssignmentEvent`, tie-break déterministe stable, taxonomie des absences. Décisions actées dans `docs/decisions.md` D031-D045.
