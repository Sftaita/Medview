# Domaine de planification — socle de données

> Documente le modèle **effectivement implémenté** dans ce lot (PlanningTeam,
> membership, historique de participation, périodes d'équité/de planning,
> types/patterns/instances de garde, RuleSet versionné). Ne couvre pas
> encore : disponibilités, absences, préférences, `PlanningGeneration`,
> `PlanningSnapshot`, `DutyAssignment`, OR-Tools, endpoints de génération,
> frontend — lots suivants. Référence algorithmique complète :
> `docs/allocation-algorithm.md`. Décisions actées : `docs/decisions.md`
> D046 et suivantes.

## 1. Identifiants stables

**Convention retenue** : la clé primaire technique reste toujours un
entier auto-incrémenté (cohérent avec `User`/`RefreshToken`, jamais changé
pour ce lot) ; une colonne **`stableId` (UUIDv7, `symfony/uid`)** est
ajoutée en plus partout où le moteur futur ou l'audit ont besoin d'un
identifiant reproductible et jamais recyclé :

| Entité | `stableId` | Pourquoi |
|---|---|---|
| `User` | Oui (ajouté ce lot) | `candidateStableKey` du tie-break (`docs/allocation-algorithm.md` §13) — l'ID auto-incrémenté ne doit jamais servir de matière première à un calcul reproductible |
| `PlanningTeam` | Oui | `teamStableKey` du seed material |
| `PlanningPeriod` | Oui | `planningPeriodStableKey` ; doit rester identique à travers les régénérations |
| `DutyType`, `DutyPattern` | Oui | catalogue référencé par audit/export |
| `DutyGroupInstance`, `Duty` | Oui | `dutyStableKey` — condition dure de stabilité du tie-break futur |
| `PlanningRuleSet` | Oui | c'est cette valeur, pas `version` (entier séquentiel **par équipe**, donc non unique globalement), que `docs/allocation-algorithm.md` désigne par `rulesVersion` |
| `FairnessPeriod`, `PlanningTeamMember`, `TeamMemberParticipationPeriod` | Non | aucun besoin identifié dans le seed material du tie-break ni dans le snapshot — ajouté seulement si un besoin réel apparaît (YAGNI) |

**UUIDv7 plutôt qu'ULID** : type natif PostgreSQL `uuid` (16 octets,
indexation compacte), même propriété d'ordonnancement temporel qu'un ULID
(bonne localité d'index B-tree), mais format RFC 9562 largement standard
(export, interopérabilité), alors qu'un ULID resterait stocké comme
chaîne sans type natif équivalent. Type Doctrine `uuid` enregistré dans
`config/packages/doctrine.yaml` (`Symfony\Bridge\Doctrine\Types\UuidType`).

**Précision importante** : utiliser un ID comme *matière première d'un
hash* (tie-break, §13 de l'algorithme) reste sûr même s'il s'agissait d'un
entier — c'est *trier/comparer directement* par ID qui est interdit. Le
choix UUID ici est motivé par la stabilité/l'absence de collision
inter-équipe, pas par une propriété cryptographique du tie-break lui-même.

## 2. Dates et temps

Trois représentations distinctes, jamais mélangées :

| Nature | Type Doctrine | Colonne PostgreSQL | Exemple |
|---|---|---|---|
| Date métier (pas d'heure) | `date_immutable` | `DATE` | `FairnessPeriod.startsAt`, `PlanningPeriod.startsAt`, `TeamMember.membershipStart`, `TeamMemberParticipationPeriod.validFrom`, `Duty.localDate` |
| Instant physique exact (arithmétique de conflit) | `datetimetz_immutable` | `TIMESTAMPTZ` | `Duty.startsAt` / `Duty.endsAt` |
| Timestamp technique/audit | `datetime_immutable` (défaut, non typé explicitement) | `TIMESTAMP WITHOUT TIME ZONE` | `createdAt`/`updatedAt` sur toutes les entités |

**Pourquoi `Duty` déroge à la convention `TIMESTAMP WITHOUT TIME ZONE`
utilisée partout ailleurs dans le projet** : `createdAt`/`updatedAt` sont
des timestamps d'audit écrits par un seul processus PHP (UTC implicite),
jamais affichés en heure locale. `Duty.startsAt`/`endsAt` représentent un
instant physique réel qui doit rester temporellement exact (conflits,
repos minimum) indépendamment de toute dérive de configuration de fuseau
de session — `TIMESTAMPTZ` élimine cette classe de risque par
construction (PostgreSQL normalise en interne, indépendamment du fuseau
de session au moment de la lecture).

**`Duty.timezone`** (chaîne IANA, ex. `Europe/Brussels`) est conservée
séparément car `TIMESTAMPTZ` ne fait jamais transiter le nom du fuseau
d'origine — seul l'instant absolu survit au round-trip.
`PlanningTeam::getTimezone()` délègue à `planning.timezone` (depuis D079 —
plus de colonne `timezone` propre à l'équipe) : une équipe = le fuseau
opérationnel de son Planning en v1 ; pas encore de fuseau par site/membre.

**DST** : `DutyMaterializationService::resolveInstant()` construit
l'instant absolu à partir de l'heure murale + fuseau IANA explicite — PHP
applique alors les règles de changement d'heure de ce fuseau pour cette
date précise, garantissant un instant correct même à cheval sur une
transition DST. Testé explicitement (`testDstSpringForwardDoesNotDistortSpacingArithmetic`).

**`Duty.localDate`** : convention — une garde compte comme le jour où elle
**commence**, même si elle traverse minuit (une garde de nuit
samedi 20h→dimanche 6h reste un samedi pour l'équité). Calculée une seule
fois à la construction, à partir d'une chaîne `Y-m-d` fraîchement
construite (jamais l'objet `DateTimeImmutable` converti au fuseau
d'équipe conservé tel quel) — un piège rencontré en implémentant ce lot :
convertir l'instant au fuseau de l'équipe puis appeler `setTime(0,0)`
laisse un décalage UTC résiduel sur l'objet (`+01:00` en hiver), ce qui
rend une comparaison ultérieure avec une date métier "pure" (`+00:00`)
incorrecte alors même que les deux représentent le même jour calendaire.

**Espacement jamais réinitialisé aux frontières** (§8 de l'algorithme) :
`Duty.localDate` et `startsAt`/`endsAt` restent des dates calendaires
absolues, jamais bornées par `FairnessPeriod`/`PlanningPeriod` — rien dans
ce lot n'introduit de logique de reset, cohérent avec la spécification.

## 3. PlanningTeam

**Renommée depuis `Team` le 2026-09-18** (docs/decisions.md D079) : n'est
plus une entité globale/partagée. `stableId`, `planning` (ManyToOne
obligatoire — une PlanningTeam appartient à exactement un `Planning`),
`name`, `active`. Ni `slug` (plus de raison d'être une fois qu'une équipe
n'est plus navigable en dehors de son Planning) ni colonne `timezone`
propre (`getTimezone()` délègue à `planning.getTimezone()`). Ne référence
jamais ses membres directement (voir `PlanningTeamMember`) — un `User`
appartient à zéro, une ou plusieurs équipes. Une `PlanningTeam` n'est
jamais créée de façon autonome par un client : toujours inline, par
`PlanningLineService::addLine()` — détail complet dans `docs/planning.md`.

## 4. PlanningTeamMember — un stint de membership, pas un pointeur nullable

**Renommée depuis `TeamMember`** (D079), avec un champ `planning`
supplémentaire, dénormalisé depuis `planningTeam.planning` (D081) — voir
plus bas.

Une ligne = **un stint continu** d'appartenance (`membershipStart`,
`membershipEnd` nullable). Quitter une équipe ferme le stint
(`membershipEnd`), ne le supprime jamais. Revenir crée un **nouveau**
`PlanningTeamMember` — l'historique reste une vraie séquence append-only,
jamais réécrite.

- `role` (`OWNER`/`ADMIN`/`MEMBER`) — **jamais** intégré à
  `User::getRoles()` (D012, rappelé explicitement dans le code).
- Au plus un stint **ouvert** (`membershipEnd IS NULL`) par
  `(planning, user)` — enforced par un **index unique partiel** PostgreSQL
  (`uniq_planning_team_members_open_membership`), pas seulement une
  vérification applicative. **Historique de la règle** : l'index portait
  d'abord sur `(team_id, user_id)` (un User pouvait être membre ouvert de
  plusieurs Teams à la fois), puis sur `user_id` seul au Lot Planning
  (D072 : un User n'avait plus jamais qu'une seule Team active dans toute
  l'application) ; depuis D079/D080 (2026-09-18), il porte sur
  `(planning_id, user_id)` — un User peut de nouveau tenir des adhésions
  ouvertes simultanées, mais seulement dans des PlanningTeams de
  **Plannings différents**, jamais deux dans le même Planning
  (`docs/planning.md` §6). `planning_id` est une dénormalisation de
  `planningTeam.planning`, garantie cohérente par une clé étrangère
  composite `(planning_team_id, planning_id)` — même technique que le
  §"Portée exacte du contrôle d'intégrité" ci-dessous.
- `isCurrentlyOpen()` (le prédicat DB-enforced) est distinct de
  `isActiveAt(date)` (date-aware, tient compte de `membershipStart` futur)
  — deux questions différentes, jamais confondues dans le code.

## 5. Historique de participation — `TeamMemberParticipationPeriod`

Le `participationFactor` n'est **jamais** un champ mutable sur
`TeamMember` — c'est une timeline de segments `[validFrom, validTo)`
(borne haute **exclusive** — deux segments consécutifs s'articulent
exactement, `validTo` du premier = `validFrom` du second, sans trou ni
recouvrement).

- **Immutabilité réelle, pas seulement documentée** : `validFrom`,
  `participationFactor` et `teamMember` n'ont aucun setter — fixés au
  constructeur. Seul `close(validTo)` existe, utilisable une seule fois
  (`\LogicException` sinon).
- `participationFactor` stocké en `decimal(6,4)` (jamais un float binaire),
  exposé via `toFloat()` pour l'arithmétique du futur moteur — une seule
  multiplication par garde ne cumule pas d'erreur flottante significative
  à cette précision.
- `> 0` obligatoire (vérifié au constructeur) ; **pas de plafond** —
  aucune raison mathématique de limiter à `≤ 1` dans le modèle d'équité
  proportionnelle, plafonner arbitrairement aurait été une constante
  magique injustifiée.
- **Non-chevauchement enforced en base** via une contrainte d'exclusion
  PostgreSQL (`EXCLUDE USING gist`, extension `btree_gist`), pas
  seulement une vérification applicative — voir §7 "piège rencontré"
  ci-dessous pour un détail d'implémentation non trivial.
- `participationFactorAt(teamMember, date)` = `ParticipationPeriodService::factorAt()`,
  seule méthode d'accès — aucune logique temporelle dispersée ailleurs.
- `ParticipationFactorChangeReason` (`INITIAL`, `ADMINISTRATIVE_LEAVE`,
  `SUSPENSION`, `CONTRACTUAL_CHANGE`, `RETURN_TO_FULL_PARTICIPATION`,
  `MEMBERSHIP_ENDED`) — métadonnée d'audit uniquement, **un seul mécanisme
  de calcul** derrière toutes ces raisons (cohérent avec
  `docs/allocation-algorithm.md` §20 : absence personnelle ponctuelle ≠
  réduction de capacité administrative, mais cette dernière passe
  toujours par cette même timeline, jamais une voie de calcul séparée).

## 6. FairnessPeriod

Période comptable de l'équité, scopée par équipe. **Ne se chevauche
jamais dans une même équipe** (une garde doit appartenir à exactement un
ledger d'équité) — enforced par la même technique `EXCLUDE USING gist`.
Volontairement **sans** `carryOverPolicy` ni `status` dans ce lot :
l'historique des fériés nommés survit déjà à toute frontière via la
décroissance exponentielle (§7 de l'algorithme), et aucune autre règle de
report n'a de moteur pour la lire — ajouter la colonne maintenant aurait
été un concept à moitié construit.

## 7. PlanningPeriod

Sous-période opérationnelle, référence sa `FairnessPeriod` et doit
tomber **entièrement dans ses dates** (vérifié au constructeur — voir
"portée du contrôle DB" ci-dessous). `stableId` immuable, jamais recréé à
travers les régénérations (aucune régénération n'existe encore dans ce
lot, mais l'entité est prête).

**Lifecycle** — `PlanningPeriodStatus` (`DRAFT → GENERATED → VALIDATED →
PUBLISHED → ARCHIVED`), graphe de transitions **explicite** porté par
l'enum lui-même (`canTransitionTo()`), appliqué uniquement via
`PlanningPeriod::transitionTo()` / `PlanningPeriodLifecycleService::transition()` :

```
DRAFT      → GENERATED
GENERATED  → VALIDATED
VALIDATED  → PUBLISHED, GENERATED   (régénérer après validation invalide la validation)
PUBLISHED  → ARCHIVED               (jamais en arrière)
ARCHIVED   → (terminal)
```

**Lacune assumée** (voir §12 "Dette") : `docs/allocation-algorithm.md`
exige `PUBLISHED` ⇒ `coverageStatus = COMPLETE`, mais `coverageStatus`
vit sur `PlanningGeneration`, hors périmètre de ce lot — seule la forme de
la machine à états est enforced aujourd'hui.

> **Statut d'implémentation (Lot 6E, `docs/decisions.md` D106)** : cette
> lacune est fermée — `PlanningGeneration` porte désormais un vrai
> `coverageStatus`, et `PlanningPeriodLifecycleService::transition()`
> vérifie, uniquement pour la cible `PUBLISHED`, qu'une génération
> `COMPLETED` avec `coverageStatus = COMPLETE` existe pour la période
> (sinon `PlanningPeriodNotReadyToPublishException`, 409). Aucun endpoint
> de publication n'est créé dans ce lot — voir
> `docs/planning-generation.md` §16.

## 8. DutyType

Catalogue **par équipe** (pas un enum global — chaque équipe définit ses
propres types). `code` unique par équipe (le même code est réutilisable
par une autre équipe).

**`workloadValue` en `decimal(6,2)`, jamais un float binaire** :
comparé à une alternative "entier mis à l'échelle" (ce dont le solveur
CP-SAT aura réellement besoin), le choix `decimal` a été retenu parce que
la mise à l'échelle est une préoccupation d'**adapter** (déjà actée en
`docs/allocation-algorithm.md` §22, "cibles fractionnaires mises à
l'échelle... pour rester déterministe" — une responsabilité de
`OptimizationModelBuilder`, jamais du stockage). Stocker directement des
entiers mis à l'échelle ferait fuiter un détail d'implémentation du
solveur dans le domaine et dans toute vue d'administration — exactement
ce que le principe D031 (le métier ne dépend jamais du solveur) interdit.

`requiresSpecificSkill` (suggéré dans la demande initiale)
**volontairement omis** : sans entité `Skill`/`Habilitation` (hors
périmètre), un booléen isolé serait un concept à moitié construit sans
aucun consommateur — sera ajouté avec le lot disponibilités/éligibilité.

## 9. DutyPattern / DutyPatternComponent

Deux entités, pas une seule (question explicitement posée) : un
`DutyPattern` a un nombre **variable** de composants (2 pour un week-end,
2 pour 24+25 décembre, potentiellement plus) — une collection relationnelle
(`DutyPatternComponent`, une ligne par composant) permet des contraintes
réelles (offset unique par pattern, `code` unique par équipe) qu'un JSON
non typé ou un jeu de colonnes fixe ne permettrait pas. `DutyPattern` est
une **définition** (règle), jamais encore une instance datée — voir
`DutyGroupInstance`.

`DutyPatternComponent.team` est une dénormalisation délibérée : elle
permet une clé étrangère composite `(pattern_id, team_id) →
duty_patterns(id, team_id)` et `(duty_type_id, team_id) →
duty_types(id, team_id)`, garantissant en base — pas seulement en PHP —
que le `DutyType` d'un composant appartient à la même équipe que son
pattern (voir §11).

> **Statut d'implémentation (Lot Semaine type, docs/decisions.md D136)** :
> `DutyPattern` porte désormais deux champs supplémentaires, tous deux
> nullable/à défaut rétrocompatible :
>
> - `family: ?AllocationFamily` — classe le pattern dans une famille
>   d'équité générique (« Week-end », « Semaine », ...), nouvelle entité
>   calquée sur `DutyType` (catalogue par équipe, `code` unique par équipe,
>   `stableId` immuable). Nullable et immuable une fois posé — un pattern
>   sans famille ne contribue jamais à la dimension `ALLOCATION_FAMILY`
>   (`docs/fairness.md`).
> - `recurring: bool` (défaut `false`) — distingue un pattern appartenant à
>   la structure hebdomadaire récurrente d'une ligne (`WeekStructureService`,
>   `dayOffset` réinterprété comme jour ISO 0=Lundi..6=Dimanche) d'un
>   pattern ad hoc construit directement (ex. par un test, ou un futur
>   pattern « 24+25 décembre » ponctuel). Délibérément distinct d'`active` :
>   les deux notions ne se recouvrent pas, et les confondre a provoqué une
>   régression réelle corrigée pendant ce lot (voir D136).

## 10. DutyGroupInstance

Instance datée concrète d'un `DutyPattern` au sein d'une `PlanningPeriod`
— traitée comme **un seul nœud** pour l'optimisation future (§9/§14 de
l'algorithme), même si chaque `Duty` constituante reste une ligne
distincte pour les compteurs analytiques. `anchorDate` = la date à
laquelle `dayOffset = 0` du pattern fait référence.

`DutyGroupInstance.team` dénormalisé pour les mêmes raisons de clé
étrangère composite : garantit que le `DutyPattern` utilisé appartient à
la même équipe que la `PlanningPeriod`.

## 11. Duty

Instance concrète datée. `stableId` **jamais recréé** à travers une
régénération de la même `PlanningPeriod` — condition dure pour la
stabilité du futur tie-break (§13 de l'algorithme). `demandType`
(`REQUIRED`/`OPTIONAL`) et `criticality` (`STANDARD`/`CRITICAL`) portent
directement les concepts déjà actés en spécification (§5, §10, §21).

> **Statut d'implémentation (Lot Semaine type, docs/decisions.md D136)** :
> `Duty.pattern: ?DutyPattern` — nullable, rétrocompatible avec toute
> `Duty` antérieure à ce lot. Pour une `Duty` groupée, toujours dérivé de
> `groupInstance.pattern` (jamais une valeur divergente) ; pour une `Duty`
> isolée matérialisée depuis un pattern à un seul composant
> (`WeeklyDutyCalendarService`), c'est la seule voie d'accès à son pattern.
> `Duty::getAllocationFamily()` (= `pattern?->getFamily()`) est le point
> d'entrée unique, identique que la `Duty` soit groupée ou isolée.

`overlapsWith()` compare des **instants absolus** (`startsAt`/`endsAt`),
jamais des dates locales — correct par construction y compris à travers
un changement d'heure.

## Portée exacte du contrôle d'intégrité base de données

Contraintes ajoutées (deux migrations : schéma de base, puis
`Version20260915212500` "hardening") :

| Invariant | Mécanisme |
|---|---|
| `*.stableId` uniques | Index unique classique |
| `DutyType.code` / `DutyPattern.code` unique par équipe | Index unique composite `(team_id, code)` |
| Au plus un membership ouvert par `(planning, user)` | Index unique **partiel** sur `planning_team_members(planning_id, user_id)` (`WHERE membership_end IS NULL`) — historique de cette règle : `docs/planning.md` §6 |
| Au plus un `PlanningRuleSet` `ACTIVE` par équipe | Index unique **partiel** (`WHERE status = 'ACTIVE'`) |
| `FairnessPeriod` : pas de chevauchement par équipe | `EXCLUDE USING gist` (extension `btree_gist`) |
| `TeamMemberParticipationPeriod` : pas de chevauchement par membre | `EXCLUDE USING gist`, **`DEFERRABLE INITIALLY DEFERRED`** (voir piège ci-dessous) |
| Cohérence d'équipe cross-table (`PlanningPeriod`↔`FairnessPeriod`, `DutyGroupInstance`↔`PlanningPeriod`/`DutyPattern`, `DutyPatternComponent`↔`DutyPattern`/`DutyType`, `Duty`↔`PlanningPeriod`/`DutyType`) | **Clés étrangères composites** `(id, team_id)` — technique décrite ci-dessous |
| `Duty` d'un groupe appartenant à une autre `PlanningPeriod` | Clé étrangère composite `(group_instance_id, planning_period_id) → duty_group_instances(id, planning_period_id)` |
| `PlanningTeamMember.planning` cohérent avec `planningTeam.planning` (D081) | Clé étrangère composite `(planning_team_id, planning_id) → planning_teams(id, planning_id)` |
| Dates non inversées, facteur/workload positifs | `CHECK` (défense en profondeur, déjà validé aussi dans les constructeurs) |

**Technique des clés étrangères composites** : pour garantir qu'un enfant
appartient à la même équipe que son parent (une invariante cross-table
qu'un simple `CHECK` ne peut pas exprimer), l'entité enfant porte une
colonne `team_id` dénormalisée, et une clé étrangère composite référence
`(id, team_id)` du parent — qui porte lui-même une contrainte `UNIQUE(id,
team_id)` en plus de sa clé primaire. Ceci rend l'incohérence
**structurellement impossible**, sans trigger. Ces contraintes composites
ne sont volontairement **pas** représentées dans le mapping Doctrine
(l'ORM n'a pas besoin de les connaître pour l'hydratation d'objets) —
`doctrine:schema:validate`/`doctrine:migrations:diff` afficheront donc
toujours ce diff spécifique comme "non synchronisé" : **attendu**, ne
jamais l'appliquer (il annulerait le durcissement).

**Piège rencontré et corrigé pendant ce lot — contraintes `EXCLUDE` et
séquences UPDATE+INSERT dans un seul `flush()`** : `ParticipationPeriodService::changeFactor()`
ferme le segment ouvert (UPDATE) puis en ouvre un nouveau (INSERT) dans le
même `flush()`. Avec une contrainte `EXCLUDE` par défaut (vérifiée
immédiatement après *chaque* instruction SQL, pas à la validation de la
transaction), l'ordre d'exécution des deux instructions par l'Unit of
Work de Doctrine peut produire un état intermédiaire en chevauchement et
rejeter à tort un changement pourtant légal. Corrigé en rendant cette
contrainte `DEFERRABLE INITIALLY DEFERRED` (vérifiée à la validation de
la transaction) **et** en forçant explicitement sa vérification
immédiatement après le `flush()` du service
(`SET CONSTRAINTS excl_participation_periods_no_overlap IMMEDIATE`) — un
vrai chevauchement introduit par un bug ailleurs remonte donc quand même
tout de suite, sans attendre un `COMMIT` qui pourrait tarder (ou, en
test, sous `dama/doctrine-test-bundle`, ne jamais survenir puisque chaque
test est annulé). La contrainte `FairnessPeriod` n'a pas ce besoin
(`FairnessPeriodService::create()` ne fait qu'un `INSERT` isolé) — laissée
en vérification immédiate par défaut, plus simple.

**Autre piège rencontré — collections inverses non synchronisées en
mémoire** : construire un `TeamMemberParticipationPeriod` (ou un `Duty`
rattaché à un `DutyGroupInstance`) ne met **pas** automatiquement à jour
la collection déjà chargée côté `TeamMember`/`DutyGroupInstance` — un
comportement standard de Doctrine (l'ORM ne devine pas qu'un objet doit
rejoindre une collection juste parce que la clé étrangère a été posée
côté propriétaire). Corrigé en synchronisant explicitement les deux côtés
dans le constructeur du côté "plusieurs"
(`TeamMember::addParticipationPeriod()`, `DutyGroupInstance::addDuty()`),
plutôt que de forcer un rechargement depuis la base à chaque fois.

## 12. PlanningRuleSet — versionné, immuable une fois en vigueur

**Option B retenue** (quelques colonnes structurantes + configuration
JSON validée), pas 40 colonnes typées :

| Critère | Colonnes typées (Option A) | JSON validé par DTO (Option B, retenu) |
|---|---|---|
| Intégrité DB | Totale mais rigide | Partielle sur le structurant, validation applicative sur le reste |
| Évolutivité | Migration à chaque nouvelle règle | Aucune migration pour étendre `additionalPolicy` |
| Lisibilité/requêtabilité | Bonne | Bonne pour le structurant, limitée sur `additionalPolicy` |
| Risque de JSON incontrôlé | Nul | Neutralisé : `PlanningRuleSetConfiguration` (DTO validé par Symfony Validator) est le seul point d'entrée, jamais un tableau brut persisté sans passer par lui |

`version` : entier séquentiel **par équipe** (assigné par
`PlanningRuleSetService`, jamais par l'appelant — évite une course sur
"quel est le prochain numéro"). `stableId` : identifiant global stable,
c'est cette valeur que `docs/allocation-algorithm.md` désigne par
`rulesVersion` (jamais `version`, qui n'est unique que par équipe).

**Immutabilité réellement appliquée par l'entité**, pas seulement
documentée : `updateConfiguration()`/`activate()` lèvent
`ImmutableRuleSetException` dès que le statut n'est plus `DRAFT`.
`activate()` rétrograde automatiquement l'ancien `ACTIVE` de l'équipe en
`RETIRED` — au plus un `ACTIVE` par équipe, enforced par l'index unique
partiel (§ ci-dessus). **Deux `flush()` séparés** dans
`PlanningRuleSetService::activate()` (retirer, puis activer) — un index
unique partiel, contrairement à une contrainte `EXCLUDE`, ne peut jamais
être différé ; l'exécuter en une seule transition SQL risquerait un état
intermédiaire à deux lignes `ACTIVE` simultanées selon l'ordre choisi par
l'Unit of Work.

**`LEGAL_MIN_REST` volontairement absent du DTO** (D036, §13 de
l'algorithme) : `PlanningRuleSetConfiguration.teamMinRestHours` porte
uniquement la politique **de l'équipe** (`TEAM_MIN_REST`, POLICY_HARD).
Aucune valeur réglementaire n'est inventée ici — voir §13 "Dette" pour la
provenance restant à définir.

> **Statut d'implémentation (Lot 6D.1, docs/decisions.md D105)** :
> `PlanningRuleSetConfiguration.teamMinRestHours` ne s'applique **plus**
> à toutes les générations d'une équipe — c'est un champ historique, non
> lu par `AssignmentConflictAnalyzer`. `TEAM_MIN_REST` (comme
> `LEGAL_MIN_REST`, désormais implémentée elle aussi) est une option
> choisie explicitement par génération (`App\Entity\RestPolicyOptions`,
> figée à la création de la `PlanningGeneration`), jamais un défaut
> global de l'équipe silencieusement appliqué à tous ses plannings.
> Détail : `docs/planning-solver.md` §36.

## 13. Convention pour l'exposition API (aucun endpoint créé dans ce lot)

Aucune ressource API Platform, aucun contrôleur créé pour ce lot (demande
explicite). Guidance pour les lots suivants, cohérente avec D009 (jamais
de CRUD générique sur `User`) :

| Entité | Exposition future |
|---|---|
| `PlanningTeam`, `DutyType`, `DutyPattern` | CRUD dédié envisageable (contrôleurs/DTOs explicites), jamais une `ApiResource` générique sans réflexion — et jamais de création autonome pour `PlanningTeam` : toujours inline via `PlanningLineService::addLine()` (D079) |
| `PlanningTeamMember` | Actions métier explicites (`addMember`/`endMembership`), jamais un `PATCH` générique qui permettrait de modifier `membershipEnd` en dehors de `PlanningTeamMembershipService` |
| **`TeamMemberParticipationPeriod`, `PlanningRuleSet` (historique), `Duty`** | **Jamais** de CRUD générique, même plus tard — uniquement des actions métier explicites (`changeFactor`, `createDraft`/`activate`, matérialisation) qui préservent les invariants d'immuabilité/versioning ; un `PATCH` direct sur l'une de ces entités contournerait exactement les garanties que ce lot construit |
| `PlanningPeriod` | Lecture libre envisageable, écriture uniquement via `PlanningPeriodLifecycleService` |

## 14. Suppression et historique

| Entité | Politique |
|---|---|
| `User`, `PlanningTeam` | Jamais de suppression physique une fois utilisée — `active`/désactivation |
| `PlanningTeamMember` | Jamais supprimée — `membershipEnd` (fermeture) |
| `TeamMemberParticipationPeriod` | Jamais supprimée, jamais mutée après création sauf `close()` une fois |
| `FairnessPeriod`, `PlanningPeriod` | `ON DELETE RESTRICT` sur toute référence descendante — suppression possible seulement si rien ne la référence encore (aucun service de suppression fourni dans ce lot, la contrainte protège un futur endpoint) |
| `DutyType`, `DutyPattern` | `active` pour retirer du catalogue ; `RESTRICT` empêche la suppression une fois référencée |
| `DutyPatternComponent` | Seul `ON DELETE CASCADE` du domaine — un composant n'a aucun sens sans son pattern, et le pattern lui-même est protégé par `RESTRICT` dès qu'il est instancié |
| `Duty`, `DutyGroupInstance` | Jamais de suppression, aucun chemin fourni — l'identité doit survivre indéfiniment (tie-break, audit, futur `DutyAssignment`) |
| `PlanningRuleSet` | Jamais supprimée une fois `ACTIVE`/`RETIRED` ; un `DRAFT` inutilisé pourrait l'être (non implémenté, pas nécessaire dans ce lot) |

## 15. Services métier

Six services, un par frontière d'invariant réelle — pas un par entité
(`DutyType`/`DutyPattern` n'ont pas de service dédié : leurs seules
règles, l'unicité et l'appartenance d'équipe, sont déjà portées par la
base et par les méthodes de l'entité elle-même) :

`TeamMembershipService` (ouverture/fermeture atomique
membership+historique de participation), `ParticipationPeriodService`
(changement de facteur, lecture à une date), `FairnessPeriodService`
(création avec pré-vérification de chevauchement conviviale),
`PlanningPeriodLifecycleService` (création, transitions), `PlanningRuleSetService`
(versioning, activation, validation), `DutyMaterializationService`
(création de `Duty` isolée ou de groupe complet).

**Note d'implémentation** : ces six services sont déclarés `public: true`
dans `config/services.yaml` — sans quoi le compilateur de conteneur
Symfony les aurait retirés entièrement (aucun contrôleur ne les
consomme encore dans ce lot), les rendant inaccessibles même aux tests
d'intégration. À reconsidérer (retour à `private`, implicite dès qu'un
vrai consommateur existe) une fois le lot suivant branché dessus — voir
§16 "Dette".

## 16. Audit de compatibilité avec le futur moteur

| Besoin futur | Données disponibles | Statut |
|---|---|---|
| `structuralOpportunity(user, duty)` | Implémenté depuis le Lot 4 pour les dimensions membership/non-participation/`active` — `EligibilityService`, voir `docs/eligibility.md` §4 | OK (Lot 4, partiel) |
| `effectiveExposure(user, dimension)` | Implémenté depuis le Lot 5 — `EffectiveExposureService`, voir `docs/fairness.md` §6 | OK (Lot 5) |
| `requiredDemand(dimension)` | Implémenté depuis le Lot 5 pour `TOTAL_DUTIES`/`WEIGHTED_WORKLOAD`/`FRIDAY`/`SATURDAY`/`SUNDAY`/`DUTY_TYPE` — `RequiredDemandBuilder`, voir `docs/fairness.md` §5 | OK (Lot 5, partiel — `WEEKEND_GROUPS`/`HOLIDAY`/`NAMED_HOLIDAY`/`NIGHT` toujours sans source de donnée) |
| `participationFactorAt(date)` | `TeamMemberParticipationPeriodRepository::findEffectiveAt()` (état vivant) / `PlanningSnapshotParticipationPeriod::covers()` (état snapshotté, lu par `EffectiveExposureService`) | OK |
| `STRUCTURALLY_FORCED` | Implémenté depuis le Lot 5 — `StructurallyForcedAnalyzer`, voir `docs/fairness.md` §8 | OK (Lot 5) |
| Atomicité `DutyGroup` | `DutyGroupInstance` + FK composite garantissant la cohérence de période ; homogénéité REQUIRED/OPTIONAL désormais garantie au constructeur (D083) | OK |
| Tie-break stable | `PlanningTeam.stableId`, `PlanningPeriod.stableId`, `Duty.stableId`, `User.stableId`, `PlanningRuleSet.stableId` — tous présents, tous immuables | OK |
| Snapshot | Implémenté depuis le Lot 3 — `PlanningSnapshot` et ses enfants, voir `docs/planning-generation.md` | OK (Lot 3) |
| `OptimizationProblem` (mode GENERATE) | Implémenté depuis le Lot 5 — `OptimizationProblemBuilder`, voir `docs/fairness.md` §10 | OK (Lot 5, partiel — pas de `fixedAssignments`/`objectivePhases`) |
| GENERATE / REPAIR / SIMULATE (solveur réel) | Aucune logique de solveur ; `PlanningPeriodStatus` fournit déjà le lifecycle sur lequel ces modes s'articuleront ; `OptimizationMode` existe mais seul `GENERATE` est construit | Attendu — hors périmètre |

Aucun manque structurel bloquant identifié pour ce lot précis. Le seul
gap réel concerne `coverageStatus` (§7 ci-dessus), qui dépend
explicitement d'une entité hors périmètre (`PlanningGeneration`).

## 17. Dette / points ouverts (les vrais, pas une liste de précaution)

1. **`LEGAL_MIN_REST`** : aucune valeur ni source de vérité *automatique*
   définie (système ? juridiction ? configuration externe ?) — reste
   entièrement non devinée (D036). Implémentée au Lot 6D.1 (D105) comme
   option **manuelle** par génération (`RestPolicyOptions`) : le
   planificateur saisit lui-même la durée à chaque activation ; aucune
   détection de juridiction ni récupération automatique d'une règle
   légale n'existe — ce gap-là reste entier, seul le mécanisme d'activation
   est désormais réel.
2. **`PUBLISHED` ⇒ `coverageStatus = COMPLETE`** — fermée au Lot 6E
   (`docs/decisions.md` D106) : `PlanningPeriodLifecycleService::transition()`
   vérifie désormais réellement cette précondition contre la
   `PlanningGeneration` `COMPLETED` la plus récente.
3. **Services de domaine marqués `public: true`** par nécessité technique
   temporaire (aucun consommateur réel encore) — à revisiter une fois de
   vrais contrôleurs existent.
4. **`MAX_CONSECUTIVE_NIGHTS`** pourrait nécessiter la même séparation
   légal/équipe que `MIN_REST` selon la juridiction réelle visée — non
   tranché, signalé dans `docs/allocation-algorithm.md` §3.
5. **Régénération** (Duty stable à travers une re-génération) : le champ
   et l'intention existent (`stableId` jamais recréé), mais aucun
   mécanisme de régénération n'existe encore pour le vérifier en
   situation réelle — seulement testé par construction/absence de
   recréation, pas par un vrai cycle GENERATE→REGENERATE.
