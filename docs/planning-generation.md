# PlanningGeneration / Snapshot / Assignment — choix techniques

Lot 3 : la couche de persistance qui représente une tentative de
génération de planning (`PlanningGeneration`), le snapshot immuable des
données utilisées pour cette génération (`PlanningSnapshot` et ses
enfants), et les affectations de gardes (`DutyAssignment`). **L'algorithme
d'optimisation n'est pas implémenté dans ce lot** — voir §9.

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
DRAFT → SNAPSHOTTED
```

`PlanningGenerationStatus` est volontairement réduit à ces deux valeurs.
Le cahier des charges proposait aussi `COMPLETED`/`FAILED`, mais ces
statuts représenteraient l'issue d'un solve — aucun solveur n'existe
encore dans ce lot, donc les inventer maintenant créerait des états que le
code ne peut jamais légitimement atteindre. Le graphe de transitions vit
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

## 3. Snapshot : composition et immuabilité

`PlanningSnapshot` est la racine, en relation 1-1 avec sa
`PlanningGeneration` (contrainte unique sur `generation_id`). Elle agrège :

| Entité | Contenu figé |
|---|---|
| `PlanningSnapshotMember` | `sourceTeamMemberStableId`, `sourceUserStableId`, `membershipStart`/`membershipEnd`, `role` (audit uniquement) |
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
```

Aucune entité Doctrine n'est exposée directement — chaque contrôleur
construit un DTO de lecture à la main
(`PlanningGenerationController::snapshotToArray()`), qui inclut un bloc
`summary` (nombre de membres, d'indisponibilités, de préférences, de
non-participations) pensé pour un futur écran d'administration minimal.

## 10. Autorisations

`TeamRoleVoter` étendu avec deux attributs (sujet : `Team`) :

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

```
EligibilityService complet
Duty × Candidate matrix
structuralOpportunity final
FairnessService / SpacingService / HolidayFairnessService
Preference scoring
PlanningOptimizationService (solveur)
DutyAssignmentEvent (append-only, docs/allocation-algorithm.md §19) et
  les statuts PLANNED/PERFORMED/CANCELLED/REPLACED — ce lot ne construit
  que l'état courant de DutyAssignment, jamais son historique d'événements
swap workflow / notifications
régénération intelligente
snapshotHash / hash canonique (docs/allocation-algorithm.md §14) — la
  détection de concurrence de ce lot repose sur le statut + une contrainte
  unique, pas sur un hash comparé au moment du persist (ce mécanisme-là
  suppose un solve asynchrone qui n'existe pas encore)
```
