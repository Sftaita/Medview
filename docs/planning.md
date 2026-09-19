# Planning / PlanningLine / PlanningTeam — choix techniques

Introduit un agrégat supérieur, **visible côté utilisateur**, au-dessus du
moteur mono-équipe existant (PlanningTeam/PlanningPeriod/FairnessPeriod/
Duty/..., inchangé depuis les Lots 2-4 dans sa logique interne) :

```text
Planning
├─ PlanningLine (PRIMARY)   → PlanningTeam A → PlanningPeriod A → Generations A...
├─ PlanningLine (SECONDARY) → PlanningTeam B → PlanningPeriod B → Generations B...
└─ PlanningLine (SECONDARY) → PlanningTeam C → PlanningPeriod C → Generations C...
```

**Ce lot n'ajoute ni solveur, ni génération multi-ligne orchestrée, ni
`DutyAssignment` automatique.** Il ne fait que grouper plusieurs moteurs
mono-équipe déjà existants sous un même planning visible.

Depuis 2026-09-18 (docs/decisions.md D079/D080/D081), `Team` — renommée
`PlanningTeam` — n'est plus une entité globale/partagée : elle appartient
à exactement un `Planning` et n'est plus jamais créée de façon autonome
par un client. Voir §1-§2 et §6.

## 1. Principe central : agrégateur, pas fusion

Un `Planning` n'a **pas** de PlanningTeam propre — c'est un pur
agrégateur. Chaque `PlanningLine` reste rattachée à exactement une
`PlanningTeam`, elle-même rattachée à exactement ce `Planning`, via son
propre `PlanningPeriod`, avec toute la chaîne mono-équipe déjà construite
(`FairnessPeriod` → `Duty`/`DutyType`/`DutyPattern` → `PlanningRuleSet` →
`PlanningSnapshot` → `EligibilityService`) strictement inchangée dans sa
logique. Un Planning à trois lignes, c'est donc **trois populations de
candidats complètement distinctes** — jamais une matrice fusionnée
`Duty ligne A × (Team A + Team B + Team C)`.

`PlanningPeriod.team` reste un `ManyToOne` simple, **jamais** transformé en
relation multi-équipe — décision délibérée pour préserver tous les
invariants déjà construits (Duty/DutyType/DutyPattern/participation/
PlanningRuleSet/snapshot/eligibility appartiennent chacun à une seule
équipe, sans exception).

## 2. `Planning` et `PlanningTeam` (D079)

`Planning` : `stableId`, `name`, `creator` (User), `startsAt`/`endsAt`
(DATE), `timezone`. Aucune PlanningTeam directe. `creator` est le seul
manager en v1 (docs/decisions.md D071) — aucun collaborateur/co-owner, à
ajouter ultérieurement si le besoin apparaît.

`PlanningTeam` : `stableId`, `planning` (ManyToOne obligatoire), `name`,
`active`. Ni `slug` (plus de raison d'être une fois qu'une PlanningTeam
n'est plus navigable en dehors de son Planning) ni colonne `timezone`
propre — `PlanningTeam::getTimezone()` délègue à
`$this->planning->getTimezone()`, une seule source de vérité. Une
`PlanningTeam` n'est **jamais** créée seule : elle naît systématiquement
*inline*, dans la même transaction qu'une `PlanningLine`
(`PlanningLineService::addLine()`) — voir §3 et §9.

## 3. `PlanningLine` et la ligne PRIMARY

`stableId`, `planning`, `planningTeam`, `planningPeriod`, `name`, `type`
(`PlanningLineType::PRIMARY`/`SECONDARY`), `position`, `active`.

**Exactement une ligne PRIMARY par Planning** — la première créée, en même
temps que le `Planning` **et sa `PlanningTeam` primaire** lui-même, dans
une seule transaction (`PlanningService::create()` →
`PlanningLineService::addLine()`, `EntityManager::wrapInTransaction()`,
D074) : un `Planning` observable avec zéro ligne serait un état
intermédiaire cassé. Toute ligne ajoutée ensuite via
`PlanningLineService::addLine()` est `SECONDARY`, et crée elle aussi sa
propre `PlanningTeam` fraîche. Aucun mécanisme de promotion/rétrogradation
en v1.

**La ligne PRIMARY n'est jamais supprimable** (D074) — v1 n'a pas de
mécanisme de promotion, donc la supprimer laisserait le Planning sans
ligne principale. Une ligne `SECONDARY` peut être supprimée par le
créateur ; la suppression ne supprime **pas** sa `PlanningTeam`/
`PlanningPeriod`/`FairnessPeriod` sous-jacents (aucun service de
suppression n'existe pour eux, inchangé depuis `docs/planning-domain.md`
§14) — ils restent en base, simplement non référencés par une ligne.

## 4. `PlanningLine` ≠ `PlanningTeam` (D073, toujours valide)

En v1, une `PlanningTeam` ne peut alimenter qu'une seule
`PlanningLine` — mécaniquement garanti maintenant, pas seulement imposé
par contrainte : une `PlanningTeam` n'est jamais créée autrement qu'inline
par une `PlanningLine`, et `UNIQUE(planning_team_id)` sur `planning_lines`
empêche toute réutilisation. Cette relation 1:1 **n'est toujours pas** une
identité conceptuelle entre les deux entités — elles restent deux entités
séparées précisément pour pouvoir lever cette restriction plus tard
(plusieurs lignes sur la même PlanningTeam) sans refonte du domaine.

L'ancien conflit "cette Team alimente déjà une ligne de ce Planning"
(`PlanningTeamAlreadyInUseException`) est **supprimé** : structurellement
impossible désormais, puisqu'un client ne peut plus jamais référencer une
PlanningTeam existante en créant une ligne (voir §9).

## 5. Relation avec `PlanningPeriod` (D075)

Chaque `PlanningLine` crée sa propre `PlanningTeam`, son propre
`FairnessPeriod` et son propre `PlanningPeriod`, bornés **exactement** sur
`[Planning.startsAt, Planning.endsAt)` — vérifié dans le constructeur de
`PlanningLine` (`\InvalidArgumentException` sinon, même style que les
autres invariants cross-entité du domaine ; le constructeur vérifie
également `$planningTeam->getPlanning() === $planning`). Pas de période
différenciée par ligne en v1.

`OverlappingFairnessPeriodException` (409, `team_already_scheduled`) reste
théoriquement possible dans la signature de
`PlanningLineService::addLine()`/`PlanningService::create()`, mais ne peut
plus jamais se produire en pratique : une `PlanningTeam` fraîchement créée
ne peut par construction avoir aucun `FairnessPeriod` préexistant. Conservé
uniquement parce que `FairnessPeriodService::create()` peut le lever.

## 6. Adhésion unique par Planning, pas par application (D080, remplace D072)

Changement de règle métier : `PlanningTeamMember` n'autorise plus qu'**une
seule adhésion ouverte par (Planning, User)** — et non plus une seule dans
toute l'application (ancienne règle D072, abandonnée : elle n'avait plus
de sens dès qu'une Team appartient à un seul Planning). Un User peut donc
tenir des adhésions ouvertes **simultanées** dans des PlanningTeams de
Plannings *différents* — mais jamais deux adhésions ouvertes dans deux
PlanningTeams du **même** Planning à la fois.

Contrainte DB : `UNIQUE(planning_id, user_id) WHERE membership_end IS
NULL` sur `planning_team_members` (migration `Version20260917091305`).
`planning_id` y est une dénormalisation de
`planningTeam.planning`, garantie cohérente par une clé étrangère
composite `(planning_team_id, planning_id)` (même technique que D051) —
voir D081. Historique préservé par stints (inchangé) : un User peut avoir
appartenu à la PlanningTeam A du Planning X en 2025 puis à la PlanningTeam
B du Planning Y en 2026, ou aux deux **simultanément** si X ≠ Y — jamais à
deux PlanningTeams d'un même Planning en même temps.
`PlanningTeamMembershipService::addMember()` vérifie désormais
`PlanningTeamMemberRepository::findOpenMembershipForUserInPlanning(Planning,
User)` (ce Planning précis) au lieu de `findOpenMembershipForUser()`
(toute l'application) ; exception renommée
`PlanningTeamMembershipConflictException`, message adapté.

## 7. Populations distinctes par ligne

`EligibilityService`/`EligibilityMatrixBuilder`/`PlanningSnapshotService`
ne sont **pas** modifiés dans leur logique — ils continuent de travailler
sur un `PlanningSnapshot`/`PlanningGeneration` rattaché à un seul
`PlanningPeriod`, donc une seule PlanningTeam. Un membre de la
PlanningTeam A n'apparaît jamais comme candidat pour la ligne B, et
réciproquement — pas parce qu'un nouveau filtre a été ajouté, mais parce
que rien dans la chaîne snapshot/eligibility n'a jamais eu connaissance
d'autre chose qu'une seule PlanningTeam à la fois. C'est le niveau
`Planning`/`PlanningLine` qui organise ces contextes séparés, jamais le
moteur lui-même.

## 8. `PlanningGeneration` reste mono-ligne

Inchangé : `PlanningGeneration → PlanningPeriod`. Chaque ligne a ses
propres générations, indépendantes des autres lignes du même Planning.
Une action UI future ("Générer le planning") pourrait orchestrer une
génération par ligne, mais cette orchestration n'existe pas dans ce lot —
et ne remplace toujours aucun solveur.

> **Mise à jour (Lot 5, `docs/fairness.md`)** : cette isolation
> mono-ligne est désormais aussi la frontière du `FairnessContext` — un
> `FairnessContext` (et l'`OptimizationProblem` qui en découle) reste
> strictement scopé à une seule `PlanningLine`/`PlanningTeam`, jamais
> agrégé entre lignes d'un même Planning. `FairnessContextBuilder`
> résout d'ailleurs explicitement la `PlanningLine` réelle derrière la
> `PlanningPeriod` du snapshot (`PlanningLineRepository::findOneByPlanningPeriod()`),
> confirmant que l'invariant "toute `PlanningPeriod` a une `PlanningLine`"
> posé par ce lot tient bien en pratique.

## 9. Endpoints

```
POST   /api/plannings
GET    /api/plannings
GET    /api/plannings/{stableId}
PATCH  /api/plannings/{stableId}
POST   /api/plannings/{stableId}/lines
PATCH  /api/plannings/{stableId}/lines/{lineStableId}
DELETE /api/plannings/{stableId}/lines/{lineStableId}

GET    /api/plannings/{planningStableId}/teams
GET    /api/plannings/{planningStableId}/teams/{teamStableId}/members
POST   /api/plannings/{planningStableId}/teams/{teamStableId}/members
POST   /api/plannings/{planningStableId}/teams/{teamStableId}/members/{memberStableId}/end

GET    /api/plannings/{planningStableId}/teams/{teamStableId}/members/{memberStableId}/non-participation
POST   /api/plannings/{planningStableId}/teams/{teamStableId}/members/{memberStableId}/non-participation
PATCH  /api/plannings/{planningStableId}/teams/{teamStableId}/members/{memberStableId}/non-participation/{stableId}
DELETE /api/plannings/{planningStableId}/teams/{teamStableId}/members/{memberStableId}/non-participation/{stableId}
```

`creator` est toujours `#[CurrentUser]`, jamais un champ accepté du
client (`CreatePlanningRequest` n'a pas de champ `creatorStableId` — un
tel champ dans le payload est silencieusement ignoré, jamais lu).

**Aucun endpoint n'accepte plus jamais d'identifiant de PlanningTeam en
entrée (D079)** :

- `POST /api/plannings` prend `primaryTeam.name` (un nom, pas un
  `teamStableId`) — la PlanningTeam primaire est créée inline.
- `POST .../lines` prend seulement `name` — crée toujours une ligne
  `SECONDARY` avec sa propre PlanningTeam fraîche ; `type` n'est pas un
  champ accepté non plus.
- `POST .../teams/{teamId}/members` prend `userStableId` (jamais un
  `teamStableId` à créer — la Team existe déjà, c'est le candidat qu'on
  identifie), `role`, `membershipStart`.

Chaque réponse `Planning` porte un booléen `canManage` (D078), calculé côté
serveur via `PlanningVoter::MANAGE` — le frontend n'a ainsi jamais besoin
de connaître son propre `stableId` pour décider d'afficher les actions de
gestion (`/api/me` n'expose actuellement que l'`id` auto-incrémenté, pas
`stableId` — dette connue, indépendante de ce lot).

**PATCH `/api/plannings/{stableId}`** ne modifie que `name` en v1 (D077)
— changer `startsAt`/`endsAt` demanderait de faire cascader le changement
sur le `PlanningPeriod` de chaque ligne et de revalider chaque
`FairnessPeriod` de PlanningTeam, délibérément non tenté ici.

## 10. Autorisations (D071, D076, D079)

`PlanningVoter`, séparé de `PlanningTeamRoleVoter` :

- **`PLANNING_MANAGE`** — vrai uniquement pour `planning.creator`. Un
  OWNER/ADMIN d'une PlanningTeam associée (via une `PlanningLine`, y
  compris la ligne PRIMARY) n'obtient **aucun** droit de gestion de ce
  seul fait. C'est aussi la règle qui gouverne l'ajout/la fin d'adhésion
  d'un membre (`PlanningTeamMemberController`) — gérer les membres d'une
  équipe est un acte de structure du Planning, pas un acte de rôle
  d'équipe. `PlanningTeamRoleVoter::MANAGE_PLANNING` (OWNER/ADMIN de la
  PlanningTeam) reste la seule autorité pour les générations/snapshots/
  assignations manuelles de la ligne de cette équipe — une distinction
  volontairement conservée, pas unifiée avec `PlanningVoter`.
- **`PLANNING_VIEW`** — vrai pour le créateur, ou tout membre courant
  (n'importe quel rôle) d'une PlanningTeam qui alimente une des lignes du
  Planning. Implémenté par une unique requête sur la colonne dénormalisée
  `planning_team_members.planning_id` (D081), sans plus jamais énumérer
  les `PlanningLine`.

**`GET /api/plannings`** (D076, règle choisie faute de besoin
fonctionnel encore précisé) :

```text
creator                             → VIEW + MANAGE
membre d'une PlanningTeam associée  → VIEW uniquement
tout autre utilisateur              → aucun accès
```

## 11. Migration des données existantes

Migration `Version20260917091305`. Vérifié avant migration : au moment de
ce lot, la base de dev contenait 2 `teams` (fixtures QA manuelles,
Cardiologie/Radiologie), 1 `planning` et 1 `planning_line` (créés lors
d'un test manuel dans le navigateur), et 2 `fairness_periods`/
`planning_periods` associés — aucune donnée utilisateur réelle. Aucune de
ces lignes n'a pu être portée vers `planning_teams` : une Team de fixture
n'avait par construction aucun Planning propriétaire à lui attribuer, ce
qui est désormais obligatoire. La migration les supprime explicitement
(`DELETE FROM planning_lines/planning_periods/fairness_periods/plannings`)
avant de restructurer le schéma. **À recréer manuellement si nécessaire**
après ce lot : les deux équipes de fixture et le planning de démonstration
du lot précédent — aucune perte de donnée réelle.

## 12. Frontend

`/plannings` (liste + création par **nom** d'équipe primaire, jamais par
identifiant technique). `/plannings/{stableId}` (détail : lignes avec
nom d'équipe/effectif/période/rôle PRIMARY-SECONDARY, actions
creator-only : renommer, ajouter une ligne par **nom**, supprimer une
ligne secondaire ; panneau "Gérer les membres" par ligne — liste, ajout
par `userStableId` + rôle + date d'entrée, fin d'adhésion). Aucune UI de
génération/éligibilité — hors périmètre (déjà couvert par l'API d'audit
`GET /api/planning-generations/{stableId}/eligibility` du Lot 4, non
exposée dans cette UI).

Les anciennes pages d'équipe globale (`/teams`, `/teams/:teamId`,
`/teams/:teamId/availability-calendar`) sont **supprimées** (D079) — une
PlanningTeam n'est plus navigable en dehors de son Planning. Conséquence :
l'UI de non-participation administrative qu'exposait `/teams/:teamId`
(Lot 2) n'a plus de point d'entrée frontend — l'endpoint backend existe
toujours et fonctionne (renesté, §9), seule l'UI manque. Dette explicite,
à raccrocher à une future itération du panneau "Gérer les membres" de
`PlanningDetailPage`.

## 13. Hors périmètre de ce lot

```
OR-Tools / solveur
Lot 5 fairness
génération automatique
orchestration multi-ligne ("Générer le planning" pour toutes les lignes)
partage du rôle de propriétaire / invitations à gérer un Planning
candidats cross-team au sein d'un même Planning
échange de gardes entre lignes
optimisation globale entre lignes
promotion/rétrogradation PRIMARY ↔ SECONDARY
période différenciée par ligne (toutes les lignes partagent exactement
  les dates du Planning, §5)
UI de non-participation administrative (endpoint existant, plus de point
  d'entrée frontend depuis la suppression de /teams/:teamId, §12)
```
