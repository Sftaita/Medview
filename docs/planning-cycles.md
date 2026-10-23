# Périodes successives d'un planning (`PlanningCycle`) — spécification technique

> **Statut : spécification validée (2026-10-10). Lots L1 (D186), L2 (D187,
> correctif D188), L3 (D189-D193), L4a (D194), L4b (D195), L4c (D196), L4d
> (D197), L4e (D198), rappel du samedi (D199), L4f (D200), L5 (D201) et L6
> (D202) implémentés, recette L7 faite (D203), lecture du calendrier des
> simples membres limitée aux lignes publiées (D204), non déployés ;
> migrations L1 → L4c répétées sur le dump de production du 2026-10-17
> (D196) puis, avec le code final, sur celui du 2026-10-19 (D205 ; L4d → L7
> n'ajoutent aucune migration) ; plan de mise en production :
> [`deployment-periods.md`](deployment-periods.md).**
> Écarts de L1 avec ce document, imposés par la compatibilité avec la version
> précédente : voir D186 (colonnes nullables jusqu'en L2, `ON DELETE SET NULL`
> sur la ligne d'une période, trous I8 reportés en L3).
> Écarts de L2 : voir D187. Les principaux :
> - contrainte « période d'une ligne ⇒ cycle » au lieu du NOT NULL, pour les
>   orphelines historiques ;
> - les parcours non encore adaptés aux périodes (L3/L4) refusent un planning
>   à plusieurs périodes (409 `multiple_periods_not_supported`) au lieu de
>   résoudre la période ;
> - le flag couvre aussi la modification de la fin, la suppression et la
>   collecte par période.
> Correctif de L2 (D188) : flag désactivé et planning à une seule période,
> l'ajout et la suppression d'une ligne gardent le comportement d'avant L1
> (§5.5 ne s'applique qu'une fois la création activée ou à plusieurs
> périodes) ; une période orpheline ne verrouille ni ne suit son cycle.
> Clôture de L3 : D190 (diagnostic) et D191 — un lancement est résolu sans
> rien écrire, puis écrit en une fois sous le `CalendarWriteLock`, après
> revalidation de chaque génération et du calendrier final (une ligne en
> échec ne laisse jamais de calendrier incompatible) ; l'audit bloque sur une
> affectation active d'une période orpheline. D192 : une frontière historique
> (fin d'une période verrouillée) qui coupe un bloc jamais matérialisé est
> acceptée sur confirmation explicite ; la fraction du bloc dans la nouvelle
> période devient un bloc de reste, rien n'est créé dans la période précédente.
> Écarts de L3 : voir D189. Les principaux : période obligatoire
> (`period_required`) pour générer, compléter ou lire le préflight d'un
> planning à plusieurs périodes ; `VALIDATED` bloque la génération (I8) ;
> aucune frontière entre périodes ne coupe un bloc (option A, contrôlée à la
> création, au changement de fin et à la modification de la structure) ;
> l'écriture d'une génération revalide les engagements voisins sous le
> `CalendarWriteLock`.
> Arbitrages utilisateur A–M et points complémentaires 1–7 intégrés.
> Décisions à enregistrer à l'implémentation : D186 et suivantes
> (`docs/decisions.md`). D185 (composants sur données fictives) reste
> valable : ce sont ces composants qui seront branchés au lot L6.

Vocabulaire : **période** (interface, API) = **`PlanningCycle`** (code,
base). Une `PlanningPeriod` reste la période *d'une ligne* dans un cycle.

---

## 1. Constat de départ (audit du 2026-10-10)

- Une ligne a une seule `PlanningPeriod`
  (`planning_lines.planning_period_id` NOT NULL + `uniq_planning_lines_planning_period_id`),
  aux dates exactes du planning (D075). ~40 appels à
  `PlanningLine::getPlanningPeriod()`.
- « Prolonger » agrandit cette période en place, refusé dès VALIDATED /
  PUBLISHED (D122, qui annonçait déjà « plusieurs périodes par ligne »).
- Déjà rattachés à une période : `Duty`, `DutyGroupInstance`,
  `PlanningGeneration` → snapshot → `DutyAssignment`, réaffectation, validation
  d'un échange.
- Rattachés au planning entier : collectes (non-chevauchement par planning),
  `PlanningJob` (un actif par planning), `PlanningPublication` (un `FIRST` par
  planning), documents PDF, notifications, rappels, `DutyAssignmentEvent`.
- Données historiques déjà immuables (vérifié) : `Duty` sans setter ;
  `DutyType.workloadValue` fixé au constructeur, aucun endpoint ne le modifie ;
  `DutyPattern.family` fixé au constructeur, `WeekStructureService::replace`
  désactive et recrée, ne mute jamais ; collectes : dates fixes, jamais
  supprimées ; aucune garde n'est jamais supprimée.
- Trous à fermer quel que soit le lot : `PlanningGenerationService::create()`
  n'interdit pas une période PUBLISHED ; `WeeklyDutyCalendarService`
  matérialiserait des jours manquants dans une période PUBLISHED ; la
  récupération des jobs (`PlanningJobStore`, `PlanningJobRecovery`) passe par
  `planning_lines.planning_period_id` ; supprimer une ligne ayant des demandes
  d'échange produit une 500 (RESTRICT non intercepté).

---

## 2. Invariants définitifs

**Structure**

- I1. Un `Planning` a au moins un cycle. Les cycles sont numérotés 1, 2, 3…
  sans trou, numéro unique par planning, jamais renuméroté.
- I2. Les cycles sont **contigus et sans chevauchement** : `cycle(n+1).startsAt
  = cycle(n).endsAt` (bornes de fin exclusives, comme partout). Durée minimale
  7 jours.
- I3. Une `PlanningPeriod` appartient à exactement un cycle et à exactement
  une ligne (sauf périodes orphelines historiques, §6.3) ; au plus une période
  par (ligne, cycle) ; ses dates sont celles de son cycle (généralisation de
  D075).
- I4. Les périodes d'une même équipe ne se chevauchent pas (garanti en base).
- I5. `Planning.startsAt/endsAt` = enveloppe des cycles (premier début,
  dernière fin), mise à jour dans la transaction qui change un cycle.
- I6. La participation d'une ligne = l'ensemble des cycles où elle a une
  période. **Aucun statut persistant** : prise d'effet = début de sa première
  période (déduit).

**Historique**

- I7. Créer, prolonger ou supprimer un cycle ne modifie jamais une période
  d'un autre cycle (dates, statut, gardes, générations, affectations,
  publications, documents, événements).
- I8. Une période VALIDATED / PUBLISHED / ARCHIVED n'est jamais agrandie,
  raccourcie, régénérée, ni complétée par de nouvelles gardes matérialisées.
  Elle reste réaffectable (D143), échangeable (D178) et complétable
  (« Compléter automatiquement », trous uniquement) comme aujourd'hui ; chaque
  changement reste tracé (`DutyAssignmentEvent`, `wasPublished`).
- I9. Snapshots, générations, entrées et documents de publication,
  notifications et événements d'audit sont immuables (triggers append-only
  existants inchangés). Aucun identifiant stable ne change.
- I10. Le poids d'une garde (`DutyType.workloadValue`) et sa famille
  (`DutyPattern.family`) restent immuables ; les rendre modifiables exigera un
  versionnement (point d'extension G, §9). `AllocationFamily::rename()`
  (non appelée) ne doit pas être exposée sans ce versionnement.

**Fonctionnement**

- I11. Génération, complétion, statistiques et équité restent isolées par
  ligne/équipe et portent sur **une** période. Les contraintes HARD (conflit
  de créneau, `LEGAL_MIN_REST`, et `TEAM_MIN_REST` quand elle est choisie)
  sont respectées **à travers les frontières de cycles** (E, §5.4).
- I12. Les confirmations de disponibilités sont propres à une collecte, donc
  à un cycle ; une collecte fermée ne se rouvre jamais ; une collecte
  n'appartient qu'à un seul cycle.
- I13. Le statut d'un cycle est **déduit** (§4.2), jamais stocké.
- I14. Les droits restent ceux de `PlanningVoter` (sujet `Planning`) ; aucun
  nouveau rôle.

---

## 3. Modèle de données

```
plannings ──< planning_cycles ──< planning_periods >── planning_lines ── planning_teams
    │              │                     │
    │              ├──< availability_collections     (collectes du cycle)
    │              ├──< planning_jobs                (calcul d'un cycle)
    │              ├──< planning_publications        (publications d'un cycle)
    │              └──< planning_availability_reminders (nullable, nouveaux rappels)
    │
    └── planning_periods ──< duties, duty_group_instances, planning_generations (inchangés)
```

### 3.1 `planning_cycles` (nouvelle table)

| Colonne | Type | Règle |
|---|---|---|
| `id` | int PK | |
| `stable_id` | uuid | UUIDv7, unique, exposé dans l'API |
| `planning_id` | FK → plannings RESTRICT | |
| `number` | smallint | ≥ 1 |
| `starts_at`, `ends_at` | date | `[début, fin)` |
| `created_at` | timestamptz | |
| `created_by_id` | FK → users RESTRICT, nullable | NULL pour les cycles 1 créés par la migration |

Contraintes : `uniq_planning_cycles_stable_id` ; `uniq_planning_cycles_planning_number (planning_id, number)` ;
`uniq_planning_cycles_id_planning_id (id, planning_id)` (cible des FK composites) ;
`chk_planning_cycles_dates (ends_at > starts_at)` ;
`excl_planning_cycles_no_overlap EXCLUDE USING gist (planning_id WITH =, daterange(starts_at, ends_at, '[)') WITH &&)`.
La contiguïté (I2) est garantie par le service sous verrou (§5.1) — elle
n'est pas exprimable simplement en contrainte.

### 3.2 `planning_periods` (colonnes ajoutées)

- `planning_cycle_id` → `planning_cycles`, RESTRICT (nullable en L1 ; en L2,
  `chk_planning_periods_cycle_of_line` : obligatoire pour toute période d'une
  ligne, les orphelines historiques restant telles quelles — D187).
- `planning_line_id` **nullable** → `planning_lines`, RESTRICT (NULL
  uniquement pour les orphelines historiques, §6.3).
- `uniq_planning_periods_line_cycle (planning_line_id, planning_cycle_id)`.
- `excl_planning_periods_team_no_overlap EXCLUDE USING gist (team_id WITH =, daterange(starts_at, ends_at, '[)') WITH &&)`.
- FK composite `(planning_line_id, team_id) → planning_lines(id, planning_team_id)`
  (ajout de `uniq_planning_lines_id_team (id, planning_team_id)`).
- Les dates restent sur la période (lues partout) et égales à celles du cycle
  (constructeur + service).

### 3.3 Rattachements au cycle

| Table | Colonne | Remplissage migration |
|---|---|---|
| `availability_collections` | `planning_cycle_id` (nullable en L1, NOT NULL en L2), FK composite `(planning_cycle_id, planning_id)` | cycle 1 du planning |
| `planning_jobs` | `planning_cycle_id` (nullable en L1, NOT NULL en L2), FK composite | cycle 1 |
| `planning_publications` | `planning_cycle_id` (nullable en L1, NOT NULL en L2), FK composite + index partiel `uniq_planning_publications_first_per_cycle (planning_cycle_id) WHERE kind = 'FIRST'` | cycle 1 (trigger append-only désactivé puis réactivé **dans la transaction**, §6.2) |
| `planning_availability_reminders` | `planning_cycle_id` nullable | non rempli (lignes append-only intactes ; NULL = rappel antérieur aux périodes) |

Inchangés : `duties`, `duty_group_instances`, `planning_generations`,
snapshots, `duty_assignments`, `duty_assignment_events`,
`planning_publication_entries/deliveries/documents`, `duty_swap_*`,
`weekly_duty_reminders`, `fairness_periods` (prolongée en dates, §5.1).

### 3.4 `planning_lines.planning_period_id`

Conservée telle quelle au lot L1 (schéma purement additif, §6). Supprimée au
lot L2 (`Version20261012090000`, D187) : le lien est
`planning_periods.planning_line_id` (`PlanningLine::getPeriods()`,
`periodIn(cycle)`). Les parcours que L3/L4 rendront « par période » lisent
encore « la » période d'une ligne par `getSingleCyclePeriod()` /
`requireSingleCyclePeriod()`, qui **refusent** (409
`multiple_periods_not_supported`) un planning à plusieurs périodes — jamais
une résolution implicite vers la période 1.

### 3.5 `FairnessPeriod`

Une par équipe, conservée : registre d'équité de l'équipe, prolongé à chaque
nouveau cycle (le moteur ne la lit pas ; la contrainte de non-chevauchement
par équipe reste). Raccourcie à la suppression d'un dernier cycle (§5.3).
Créée à la première période d'une ligne ajoutée sans cycle éligible (§5.5).

---

## 4. Notions exposées

### 4.1 En cours / suivante / sélectionnée (point 1)

Calculées dans le fuseau du planning (`today` = date locale du planning) :

- **en cours** : le cycle qui contient `today`, sinon aucun ;
- **suivante** : le premier cycle dont `startsAt > today`, sinon aucune ;
- **sélectionnée** : choisie par l'utilisateur (URL `?periode=N`), jamais
  stockée côté serveur. Par défaut : en cours, sinon suivante, sinon la
  dernière.

Plusieurs cycles futurs (point 2) : autorisés, contigus ; « Mes plannings »
n'affiche que l'en cours et la suivante ; la frise montre tout jusqu'à 4
cycles, puis précédente + en cours + suivantes, le reste dans « Historique » ;
chaque futur cycle se prépare, se génère et se publie indépendamment.

### 4.2 Statut déduit d'un cycle (I13)

Sur les périodes des lignes **qui comptent** dans le cycle (lot L4a, D194 :
applicables ou incomplètes ; une ligne sans demande dans la période est
neutre, voir §4.3) :

| Statut affiché | Condition (première qui s'applique) |
|---|---|
| `published` | toutes PUBLISHED (ou ARCHIVED) |
| `partially_published` | certaines PUBLISHED, pas toutes (D194) |
| `generated` | toutes au moins GENERATED (VALIDATED est transitoire) |
| `collecting` | une collecte du cycle est OPEN |
| `preparation` | sinon — y compris quand **aucune** ligne ne compte : une période n'est jamais « publiée » sans ligne réellement publiée |

C'est l'état **courant**. Le statut d'une publication passée reste figé avec
elle (lot L4c). Chaque période expose aussi `lineCounts`
(`counting`, `published`, `incomplete`, `withoutDemand`) et, pour chaque
ligne, son `applicability`.

Plus des faits bruts toujours fournis : `publishedAt` (première publication
du cycle), `lastPublishedAt`, `generatedAt` (dernière génération COMPLETED),
compteurs par statut de période, état et progression des collectes. Le statut
`archivee` de la maquette n'est pas produit (C) : une période passée et
publiée affiche « Publiée » et la pastille de temps « Terminée le … ».

### 4.3 Applicabilité d'une ligne dans une période (lot L4a, D194)

Un seul service décide si une ligne participe à une période
(`LineApplicabilityService`, valeur `LineApplicability`), lu par le statut
de période, le préflight de publication, la publication et les vues du
calendrier. Jamais stocké, jamais une désactivation automatique.

| `state` | Quand | Effet |
|---|---|---|
| `INACTIVE` | ligne inactive | hors de tout, comme avant |
| `NOT_IN_PERIOD` | pas de période de ligne dans ce cycle (§5.5) | rien à lire ni publier |
| `NO_DEMAND` | active, **aucune garde** dans la période (ni récurrente, ni ponctuelle, ni reste D192) **et aucune** structure hebdomadaire récurrente active | **neutre** : ne bloque ni la génération (L4b) ni la publication des autres, n'est jamais publiée, garde son statut |
| `INCOMPLETE` | demande réelle, pas encore générée, un prérequis manque (`NO_ACTIVE_RULE_SET`, `NO_MEMBER_IN_PERIOD`, `CONDITIONAL_SOURCE_NOT_IN_PERIOD`) | compte : la période ne peut pas être entièrement publiée |
| `APPLICABLE` | demande réelle, prête ou déjà générée (`GENERATED`) | compte |

`reasons` explique toujours l'état : preuves de demande
(`DUTIES_MATERIALIZED`, `WEEKLY_STRUCTURE`, `CONDITIONAL_POLICY`,
`GENERATED`), absence (`NO_DUTY`, `NO_WEEKLY_STRUCTURE`) ou prérequis
manquants. Une politique conditionnelle seule ne crée pas de demande
(`conditional: true` reste signalé). « Sans demande » ne masque jamais une
garde : une seule garde de la période — requise, non couverte, conditionnelle
indéterminée, ponctuelle — fait compter la ligne.

---

## 5. Règles métier résolues

### 5.1 Création de la période suivante (A, D, I, point 2)

`POST /api/plannings/{planning}/periods`, droit `MANAGE` (créateur).

1. Transaction, verrou `PESSIMISTIC_WRITE` sur le planning.
2. `previousPeriodStableId` doit être le dernier cycle, sinon
   **409 `period_sequence_changed`** (double soumission, deux onglets).
3. `startsAt = dernier.endsAt` (jamais saisi) ; `endsAt − startsAt ≥ 7` jours
   sinon **422 `period_too_short`**.
4. Aucun job actif sur le planning, sinon **409 `job_in_progress`**.
5. Création du cycle ; pour **chaque ligne active**, une `PlanningPeriod`
   DRAFT aux dates du cycle ; prolongation de la `FairnessPeriod` de chaque
   équipe ; enveloppe du planning.
6. **Aucune collecte, aucun email** (A). Aucune garde matérialisée,
   aucune affectation copiée.
7. Filets en base : `EXCLUDE` sur cycles et périodes, `UNIQUE (planning, number)`,
   `UNIQUE (line, cycle)` — toute violation → rollback complet, 409.
8. Réponse 201 : la période créée (format §7.2).

La semaine type (rattachée à l'équipe) et la politique de demande (rattachée à
la ligne) s'appliquent naturellement au nouveau cycle (J, §5.6).

### 5.2 Modifier la fin de la dernière période (B)

`PATCH /api/plannings/{planning}/periods/{period}` `{endsAt, expectedEndsAt}`,
droit `MANAGE`. Conditions communes :

- le cycle est le **dernier** (sinon 409 `period_not_last`) ;
- aucune de ses périodes n'est VALIDATED / PUBLISHED / ARCHIVED
  (409 `period_locked`) ;
- `expectedEndsAt` = fin actuelle (409 `period_changed`) ;
- aucun job actif ; durée ≥ 7 jours ; verrou planning.

**Allonger** (`endsAt` > fin actuelle) — autorisé, y compris si GENERATED :

- cycle, périodes, `FairnessPeriod`, enveloppe prolongés ;
- **collectes inchangées** (dates immuables, confirmations propres à leur
  fenêtre) : les jours ajoutés apparaissent « non couverts par une collecte »
  et le gestionnaire peut ouvrir une collecte sur ce reliquat (A, manuelle) ;
- gardes : rien n'est matérialisé maintenant (matérialisation à la demande au
  préflight, comme aujourd'hui) ;
- générations : la dernière génération COMPLETED reste la génération courante
  mais ne couvre plus les nouveaux jours → couverture incomplète ; la réponse
  signale `generationOutdated: true` ; publier exige toujours une couverture
  complète (D106) → regénérer ou « Compléter automatiquement ». Aucun
  snapshot n'est modifié.

**Raccourcir** (`endsAt` < fin actuelle) — autorisé seulement si les
données le permettent sans incohérence :

- aucune génération (quel que soit son statut) sur une période du cycle
  (409 `period_has_generation`) ;
- aucune garde matérialisée à partir de la nouvelle fin
  (409 `period_has_duties_after`) — les gardes ne sont jamais supprimées et
  un simple préflight les matérialise ;
- aucune collecte dont la fenêtre dépasse la nouvelle fin
  (409 `collection_beyond_end`) — dates de collecte immuables.

**Début** : jamais modifiable, sauf le cas d'aujourd'hui conservé à
l'identique : un planning à **un seul cycle**, non verrouillé, peut encore
avancer son début (`POST .../extensions` existant, D122). Ce endpoint est
limité à ce cas ; l'allongement de fin passe par le PATCH ci-dessus.

> **Complété par D207 (2026-10-21)** — les règles ci-dessus restent celles de
> la **dernière** période. S'y ajoutent, sur le même PATCH
> (`{startsAt?, expectedStartsAt?, endsAt?, expectedEndsAt?}`) :
>
> - **Frontière entre deux périodes** : la fin d'une période qui n'est pas
>   la dernière se déplace seulement si **elle et la suivante sont
>   vierges** (aucune période de ligne validée / publiée / archivée, aucune
>   génération, publication, job ni garde préparée ; sinon 409
>   `period_locked`, `period_not_blank` ou `next_period_not_blank`). Le début
>   de la suivante suit, sans trou ni chevauchement ; chacune garde 7 jours
>   (`next_period_too_short`) ; aucun bloc coupé.
> - **Début de la première période** : modifiable tant qu'elle est vierge.
>   Le début d'une période suivante n'est jamais choisi (409
>   `period_start_follows_previous`).
> - **Collecte intacte** : dans une période vierge, la collecte unique qui
>   la couvre exactement, sans réponse, modification, retrait ni rappel,
>   **suit les nouvelles dates** (personnes attendues recalculées). Toute
>   autre collecte borne les dates (`collection_beyond_end`,
>   `collection_before_start`) — l'alinéa « collectes inchangées » ci-dessus
>   reste vrai pour elles.
> - `GET .../periods/date-options` : pour le sélecteur, chaque jour possible
>   ou non, avec tous ses motifs (mêmes codes que l'écriture).
>
> **Précisé par D208 (2026-10-22)** — collecte intacte : en plus, ouverte,
> et aucun rappel sur la période (y compris un rappel sans période envoyé
> après son ouverture) ; une absence supprimée compte comme une
> modification. Refus explicites : `collection_announced` (participants
> sollicités), `collection_answered` (réponse ou modification). La vue
> d'une période expose `canEditStart` / `canEditEnd` selon ces mêmes
> règles. Un déplacement de frontière est validé en une seule transaction,
> contrôlée avant validation (aucun trou ni chevauchement dans les données
> validées).

### 5.3 Supprimer la dernière période (L)

`DELETE /api/plannings/{planning}/periods/{period}?expected=…`, droit `MANAGE`.
Autorisé **uniquement** si toutes ces conditions sont vraies, sinon 409 avec la
raison :

- dernier cycle, et `number > 1` (`period_not_deletable`) ;
- **aucune collecte** rattachée au cycle, même sans réponse
  (`period_has_collection`) : l'ouverture d'une collecte est un événement
  métier (membres sollicités, rappels possibles) et une collecte n'est jamais
  supprimée — le doute tranche pour le refus ;
- aucune génération sur ses périodes (`period_has_generation`) — ce qui
  implique aucune affectation, aucun événement d'audit, aucun échange ;
- aucune publication rattachée (`period_has_publication`) ;
- aucun job, actif ou terminé, rattaché (`period_has_job`).

Effets, en une transaction : suppression des gardes et instances de groupe
matérialisées du cycle (gardes conditionnelles d'abord, à cause de
`coverage_source`) — elles ne sont référencées par rien quand il n'y a ni
génération ni publication, et les FK RESTRICT bloquent sinon (dernier filet) ;
suppression des périodes et du cycle ; `FairnessPeriod` et enveloppe ramenées
à la fin du cycle précédent ; `FairnessPeriod` supprimée si l'équipe n'a plus
aucune période. Journalisée (log applicatif + événement si une table
d'événements de planning existe à ce moment ; sinon le log suffit, aucune
donnée historique n'est perdue par construction).

### 5.4 Repos et conflits entre périodes (E)

- `PersonCommitmentReader` lit, pour chaque personne de la population
  figée, les affectations courantes de **toutes les périodes du planning**
  (toutes lignes, tous cycles, y compris la même ligne dans le cycle voisin)
  qui touchent `[début − marge, fin + marge)`, où marge = plus longue durée
  de repos applicable + durée de garde maximale. Source : la génération
  COMPLETED la plus récente de chaque période, publiée ou non (un brouillon
  voisin contraint aussi ; quand il sera régénéré, il respectera à son tour
  celui-ci).
- Ces engagements sont figés dans le snapshot (mécanisme `freezeExternalCommitments`
  existant, D161) et entrent dans le `snapshotHash` (déjà le cas).
- Même règle en direct : candidats de réaffectation
  (`ReassignmentCandidateService`), validation d'échange, complétion.
- Les cycles futurs déjà générés (point 2) contraignent aussi la génération
  d'un cycle antérieur : la règle est symétrique.
- Une garde publiée réaffectée après coup crée une incohérence avec un voisin
  ? Le contrôle en direct l'empêche (mêmes contraintes HARD).
- Implémenté en L3 (D189) : `NeighbourCommitmentCollector`. Pas de marge
  fixe : une garde d'une autre période est gardée si elle chevauche l'étendue
  des gardes de la période générée, ou si son écart est inférieur au seuil le
  plus strict des deux générations. L'écriture d'une génération relit cet
  ensemble sous le `CalendarWriteLock` et refuse le résultat s'il a changé
  (`neighbour_calendar_changed`). La réaffectation et les échanges restent
  refusés en multipériode jusqu'en L4.
- Lot L4d (D197) : candidats, réaffectation et retrait acceptés en
  multipériode. La période est celle de la garde ; le contrôle en direct
  porte sur toutes les périodes ; `wasPublished` est lu sous le verrou.
  Les échanges restent refusés en multipériode.

### 5.5 Ajout d'une ligne (F)

- Prise d'effet **au début d'un cycle** (granularité du cycle) : une ligne ne
  peut pas commencer en milieu de période (les dates d'une période restent
  celles du cycle ; les demandes conditionnelles s'apparient au jour près).
- `POST /api/plannings/{planning}/lines` accepte `fromPeriodStableId`
  (optionnel). Un cycle est **éligible** si `endsAt > today` (pas de cycle
  entièrement passé — aucune donnée historique fabriquée) et si aucune de ses
  périodes n'est VALIDATED / PUBLISHED / ARCHIVED.
- La ligne reçoit une période DRAFT dans `fromPeriod` **et dans chaque cycle
  suivant déjà créé** ; tous doivent être éligibles, sinon
  **409 `later_period_published`** (un cycle futur publié avant, I) — choisir
  un cycle de départ plus tardif.
- Par défaut : le premier cycle éligible à partir de la période en cours.
- Aucun cycle éligible (tous publiés ou passés) : la ligne est créée **sans
  période** (équipe créée, aucune `FairnessPeriod`) ; elle participera au
  prochain cycle créé. Aucune donnée n'est fabriquée.
- Cycles en cours de collecte : les membres ajoutés ensuite à l'équipe sont
  enregistrés dans les collectes ouvertes (hook existant `registerMember`).
- Une ligne SECONDARY conditionnelle ne peut prendre effet que sur des cycles
  où sa ligne source a une période (§5.6).
- Suppression d'une ligne : autorisée seulement si aucune de ses périodes n'a
  de génération, de garde affectée ou de demande d'échange (409
  `line_has_history`) ; ses périodes sans historique (et leurs gardes
  matérialisées) sont alors supprimées avec elle. Les règles existantes
  restent (ligne principale, source d'une politique active). Corrige la 500
  actuelle et ne crée plus d'orphelines.
- Compatibilité (D188) : tant que la création de périodes est désactivée,
  un planning à une seule période garde les règles d'avant L1 — la ligne
  reçoit toujours sa période dans l'unique cycle, et une ligne supprimée
  laisse sa période et son historique, rattachés à aucune ligne (seule une
  ligne visée par une demande d'échange est refusée, 409 `line_has_history`).

### 5.4 bis Génération d'une période déjà en partie publiée (lot L4b, D195)

Une période peut contenir à la fois des lignes publiées, des lignes à
générer et des lignes sans demande. `scope: "unlocked_lines"` génère les
lignes à générer seulement ; il est exigé explicitement dès qu'une ligne
verrouillée côtoie une ligne à générer. Une ligne sans demande est exclue,
une ligne verrouillée est conservée et devient un engagement des autres,
quelle que soit sa place dans l'ordre D161. Une ligne à générer qui a des
gardes mais aucun membre dans la période est `INCOMPLETE` et bloque le
lancement (`NO_MEMBER_IN_PERIOD`). Une garde sans candidat, elle, reste une
garde non couverte (`coverage: INCOMPLETE`). Détail :
`docs/planning-generation.md` §34.

### 5.6 Demande conditionnelle et cycles (J)

- La politique reste versionnée par ligne (D162). Chaque génération fige la
  sienne dans son snapshot (inchangé) ; la demande en direct d'une période
  lit la politique figée de **la dernière génération COMPLETED de cette
  période** (et non plus « de la ligne »).
- La source d'une période conditionnelle est **la période de la ligne source
  dans le même cycle** (`sourcePeriodInSameCycle`, aux quatre endroits :
  matérialisation, contrôle de matérialisation, snapshot, demande en direct).
- Modifier les déclencheurs : toujours permis ; s'applique à la prochaine
  génération de chaque période non publiée ; jamais à une période publiée
  (sa génération courante garde la version figée ; une période publiée n'est
  pas régénérée, I8).
- Changer le mode ou la source : refusé si une période **non publiée** de la
  ligne cible a déjà des gardes matérialisées (`line_already_materialized`,
  restreint aux périodes non publiées) ; les périodes publiées gardent leurs
  gardes et leur politique figée — l'historique n'est pas réécrit. La règle
  `planning_already_published` disparaît : elle est remplacée par celle-ci,
  plus précise.
- Création d'un cycle : les lignes source et cible reçoivent leur période
  dans la même transaction ; la cible ne peut être matérialisée que si la
  source l'est (règle existante, résolue dans le cycle).
- Une ligne source ne peut pas être supprimée tant qu'une politique active la
  référence (inchangé).

### 5.7 Publication (I, points 3 et 7)

- `publish`, `republish`, préflight, « Modifications non publiées »,
  entrées, PDF, notifications : **par cycle**. Une publication `FIRST` par
  cycle ; les suivantes `UPDATE`.
- Publier N+1 avant N : autorisé ; le préflight renvoie l'avertissement non
  bloquant `EARLIER_PERIOD_UNPUBLISHED`.
- Une période suivante en DRAFT ne bloque plus la republication d'une
  période publiée.
- **Lot L4a (D194), déjà en place** : une ligne `NO_DEMAND` est exclue du
  préflight (listée à part dans `neutralLines`, avec son statut et son
  applicabilité) et de la publication (son statut n'est jamais modifié : une
  ligne DRAFT reste DRAFT). Cas réel : Trauma Delta, dont la ligne
  secondaire vide bloquait toute republication.
- **Lot L4c (D196), en place** : publication par ligne.
  - « Publier » sans liste : tout ou rien ; avec `lines` : ces lignes
    seulement (publication partielle, jamais déduite).
  - Chaque opération enregistre les lignes qu'elle concerne
    (`planning_publication_lines` : `FIRST_FOR_LINE` ou `UPDATED`, jamais
    une ligne inchangée) et sa complétude figée (`cycle_complete`).
  - « Republier » ne publie jamais une ligne jamais publiée, sauf choisie
    explicitement (`newLines`).
  - Une ligne non publiée ne bloque plus la republication des lignes
    publiées.
  - Une ligne conditionnelle part avec ou après sa source.
  - Audience de la première publication d'une ligne : ses membres sur la
    période, un seul email par personne.
- PDF et emails des nouvelles publications : la plage est celle du cycle,
  libellé « Période N · du … au … », nom de fichier
  `planning-<slug>-periode-<N>.pdf`. Les documents déjà stockés sont servis
  octet pour octet, inchangés (D173).
- Audience de la première publication d'un cycle : membres des lignes actives
  dont l'adhésion chevauche le cycle. Republication : seules les personnes
  dont les gardes de ce cycle changent (D172, inchangé).

### 5.8 Lectures transverses (points 3 et 7)

- « Mes gardes », flux agenda, carte « Prochaine garde », rappel du samedi :
  gardes dont **la période de la garde** est PUBLISHED, tous cycles
  confondus (et non plus le statut de « la » période de la ligne).
- Échanges (K) : les deux gardes doivent être dans la même période (contrôle
  existant) ; les options de création ne proposent que des gardes de
  périodes publiées. Inchangé à l'intérieur d'une période.
- **Lot L4e (D198), en place** :
  - une seule lecture, `CurrentCalendarReader::readPublished()`, cycle par
    cycle, selon le statut propre de chaque période ; chaque unité de
    « Mes gardes » porte `period` ;
  - UID d'agenda = identifiant stable du bloc ou de la garde, indépendant
    de la période ;
  - rappel : une semaine qui traverse deux périodes les lit toutes les
    deux, un seul email ; idempotence `(user, planning, semaine)`
    inchangée ;
  - échanges : garde échangeable si sa période est publiée ; deux
    périodes : 422 `other_period` à la demande et à la proposition,
    `other_period` à la revalidation ; options et `proposableUnits`
    limités à la période de la garde offerte ; statut relu en base sous le
    verrou.
- Export PDF/XLSX : limité aux cycles publiés ; par défaut la période
  sélectionnée ; une plage qui touche un cycle non publié est refusée (422).
- Export des absences (D181) : par défaut la période sélectionnée.
- **Lot L4f (D200), en place** — écarts assumés avec les deux points
  ci-dessus :
  - une plage qui touche une période non publiée n'est **pas** refusée :
    seules les périodes de ligne publiées sont exportées, les autres jours
    se lisent « Non publiée » et sont listés dans le document ; 409
    `not_published_in_range` seulement s'il n'y a rien de publié ;
  - l'API exporte par défaut tout le planning (compatibilité) ; `period`
    est le raccourci d'une période ; le suivi des disponibilités exporte la
    période qu'il affiche ;
  - gestionnaire : calendrier courant, marqué « Calendrier opérationnel —
    modifications non publiées possibles » s'il diffère de la dernière
    publication ; simple membre : ce qui a été diffusé, lignes publiées
    seulement ;
  - périodes identifiées (PDF : liste + « P2 » ; XLSX : colonne
    « Période », feuille « Informations ») ; absences : `?period` ou
    `?from=&to=`.
- Rappel du samedi (D199) : calendrier courant, modifications non
  republiées marquées dans l'email, comparées à la dernière publication de
  chaque période.

### 5.9 Collectes (A, point 2)

- `POST /api/plannings/{planning}/periods/{period}/availability-collections`
  `{deadline?, startsAt?, endsAt?}` : par défaut la partie du cycle non
  couverte par une collecte ; la fenêtre doit tenir dans le cycle
  (422 `window_outside_period`). Aucun email à l'ouverture (comme aujourd'hui).
- Le endpoint existant `POST /api/plannings/{planning}/availability-collections`
  reste, avec la même règle « une fenêtre dans un seul cycle ».
- Membres attendus : adhésions qui chevauchent la fenêtre (inchangé). Les
  réponses d'une collecte ne valent que pour elle.
- `collection-status`, échéance du planning (`/settings`), rappels : **par
  cycle** (`?period=`). L'intervalle minimal de 5 min entre deux rappels à une
  même personne reste par planning.
- Création d'un **nouveau planning** : comportement actuel conservé (cycle 1
  créé avec sa collecte ouverte), A ne visant que les périodes suivantes.

### 5.10 SurgicalHub (M)

Le contrôle de fraîcheur avant génération porte sur les participants associés
dont l'adhésion chevauche **le cycle généré** (au lieu de toute l'enveloppe
du planning). Contrat de synchronisation, fenêtre (−90 j / +24 mois), règle
des 24 h, dérogation du créateur : inchangés.

---

## 6. Migration et retour arrière

### 6.1 Principe : additif d'abord, destructif ensuite, activation en dernier

| Lot | Migration | Ancien code compatible ? | Retour arrière |
|---|---|---|---|
| L1 | additive : `planning_cycles`, colonnes `planning_cycle_id` / `planning_line_id`, contraintes, remplissage cycle 1 | **oui** (l'ancien code ignore les ajouts ; un seul cycle) | redéployer le tag précédent, sans toucher à la base ; `down()` possible tant qu'il n'existe qu'un cycle par planning |
| L2 | supprime `planning_lines.planning_period_id` + index | non | `down()` reconstruit la colonne depuis `planning_periods` **et échoue s'il existe plus d'un cycle quelque part** (aucune perte silencieuse) |
| Activation | `MEDVUE_PERIOD_CREATION_ENABLED=1` (désactivé par défaut) | — | tant que la création est désactivée, aucun deuxième cycle n'existe : tous les `down()` restent valides |

**Après le premier deuxième cycle créé en production, aucun `down()` n'est
réversible** (point 4). Le retour arrière est alors : (a) correctif en avant
(cas normal) ; ou (b) restauration de la sauvegarde prise juste avant
l'activation — qui perd les données saisies depuis et doit être décidée
explicitement. L'activation est donc une étape distincte, faite après la
recette en production avec la création désactivée.

### 6.2 Contenu de la migration L1 (dans une transaction)

1. Créer `planning_cycles`.
2. Pour chaque planning : un cycle 1 (UUIDv7 généré en PHP, dates du
   planning, `created_by_id` NULL).
3. `planning_periods.planning_line_id` ← `planning_lines.planning_period_id` ;
   `planning_cycle_id` ← cycle 1 du planning de l'équipe.
4. `availability_collections`, `planning_jobs` ← cycle 1.
5. `planning_publications` ← cycle 1 : `ALTER TABLE … DISABLE TRIGGER
   trg_planning_publications_append_only`, `UPDATE`, `ENABLE TRIGGER` — dans
   la même transaction, documenté dans la migration (seule écriture sur une
   table append-only, ajout d'une colonne nouvelle uniquement).
6. Ajout des FK, `EXCLUDE`, index après remplissage (les NOT NULL attendent L2, D186).
7. **Assertions qui annulent la migration** si elles échouent : un cycle par
   planning ; dates de chaque période = dates de son cycle ; chaque période
   non orpheline a sa ligne ; chaque collecte tient dans son cycle ; nombre de
   lignes de chaque table métier inchangé.

### 6.3 Contrôles préalables en production (lecture seule)

- Périodes orphelines (`planning_periods` sans ligne) : nombre et
  détail — elles gardent `planning_line_id` NULL et restent hors de tous
  les parcours, comme aujourd'hui (0 en développement).
- Périodes dont les dates diffèrent de celles du planning (attendu : 0).
- Collectes hors des dates du planning (attendu : 0).
- Jobs actifs (attendu : 0 au moment de migrer).
- Propriétaire des tables append-only = utilisateur des migrations (requis
  pour `DISABLE TRIGGER`).

### 6.4 Preuve de conservation

Commande en lecture seule `app:planning-cycles:audit` livrée en L1 :
empreintes (hash ordonné) et comptes de `plannings`, `planning_lines`,
`planning_teams`, `planning_team_members`, `planning_periods` (id, statut,
dates), `duties`, `duty_assignments` (id, garde, titulaire, courante,
source), `duty_assignment_events`, `planning_generations` (id, statut,
`snapshot_hash`), snapshots, `availability_collections` et réponses,
`planning_publications` / entrées / documents (empreinte du contenu),
`duty_swap_*`, et de tous les `stable_id`. Exécutée avant et après sur une
restauration jetable du dump de production : toutes les empreintes doivent
être identiques, hors colonnes ajoutées. Puis `down()`, nouvelle comparaison
(schéma via `pg_dump --schema-only`).

### 6.5 Procédure (docs/deployment.md)

Sauvegarde + `restore-test --compare-live` PASS → archive du code →
contrôles §6.3 → `migrate --dry-run --write-sql` relu (toute instruction
`UPDATE`/`ALTER … DROP` justifiée) → `migrate` → `app:planning-cycles:audit`
→ sauvegarde + `restore-test` PASS. Jamais `schema:update`, jamais
`down -v`. Aucun déploiement sans accord explicite.

---

## 7. Contrat d'API

Toutes les réponses d'un endpoint « par période » renvoient
`period: {stableId, number}` pour que le frontend écarte une réponse qui ne
correspond plus à la période sélectionnée. Sans paramètre `period`, les
endpoints existants travaillent sur la période par défaut (en cours, sinon
suivante, sinon dernière) : compatibilité des anciens clients pendant le
déploiement ; le frontend passe toujours le paramètre.

### 7.1 Plannings

- `GET /api/plannings` : ajoute `today`, `currentPeriod`, `nextPeriod`
  (format résumé §7.2, ou `null`), `periodCount`. `published` est
  conservé (rétrocompatibilité) mais n'est plus affiché comme statut global.
- `GET /api/plannings/{id}` : ajoute `today`, `periods[]` (format §7.2),
  `currentPeriodStableId`, `nextPeriodStableId`. Dans `lines[]`,
  `planningPeriodStableId` et `periodStatus` deviennent ceux de la période
  par défaut (déprécié) et `lines[].periods[]` donne `{periodStableId,
  planningPeriodStableId, status}` par cycle.
- **En place (lot L6, D202)** : `currentPeriod`, `nextPeriod` et
  `latestPeriod` (la dernière période, pour une carte une fois tout terminé)
  portent `status`, `timeState`, `lineCounts`, `collections`,
  `publishedAt`, `lastPublishedAt`, `generatedAt` (calculés comme §7.2, sans
  `lines` ni droits) ; le détail ajoute `canCreatePeriod` (flag et `MANAGE`)
  et `canOpenPeriodCollection` (flag et `MANAGE_AVAILABILITY`).

### 7.2 Format d'une période

```json
{
  "stableId": "…", "number": 2,
  "startsAt": "2027-01-31", "endsAt": "2027-05-01", "lastDay": "2027-04-30",
  "status": "preparation | collecting | generated | published",
  "timeState": "past | current | future",
  "publishedAt": null, "lastPublishedAt": null, "generatedAt": null,
  "lines": [{"lineStableId": "…", "planningPeriodStableId": "…", "status": "DRAFT"}],
  "collections": {"open": 0, "closed": 0, "acknowledged": 0, "expected": 0,
                  "deadline": null, "uncoveredDays": 89},
  "isLast": true, "canEditStart": true, "canEditEnd": true, "canDelete": true
}
```

### 7.3 Périodes

| Méthode | Chemin | Corps | Succès | Erreurs |
|---|---|---|---|---|
| GET | `/api/plannings/{p}/periods` | — | 200 liste | 403, 404 |
| POST | `/api/plannings/{p}/periods` | `{endsAt, previousPeriodStableId}` | 201 période | 403, 409 `period_sequence_changed` / `job_in_progress` / `period_creation_disabled`, 422 `period_too_short` / `invalid_date` |
| PATCH | `/api/plannings/{p}/periods/{c}` | `{endsAt, expectedEndsAt}` | 200 période + `generationOutdated` | 409 `period_not_last` / `period_locked` / `period_changed` / `period_has_generation` / `period_has_duties_after` / `collection_beyond_end` / `job_in_progress`, 422 |
| DELETE | `/api/plannings/{p}/periods/{c}?expected={stableId}` | — | 204 | 409 `period_not_deletable` / `period_has_collection` / `period_has_generation` / `period_has_publication` / `period_has_job` / `period_sequence_changed` |
| POST | `/api/plannings/{p}/periods/{c}/availability-collections` | `{deadline?, startsAt?, endsAt?}` | 201 collecte | 409 `period_fully_collected`, 422 `window_outside_period` |

### 7.4 Endpoints existants qui prennent `?period={stableId}` (ou le champ `period` dans le corps)

`collection-status`, `collection-status/members/{id}`, `settings`
(échéance), `availability-reminders`, `generation-preflight`, `generations`
(POST), `complete`, `jobs/latest`, `result`, `assignments`, `statistics`,
`publication-preflight`, `publish`, `republish`, `publication-state`,
`publications/{id}/pdf` (déduit de la publication), `export`,
`availability-export.pdf`. Déjà en place (lot L4a, D194) : `result` et
`assignments` — période par défaut sinon (en cours, sinon suivante, sinon
dernière), champ `period` dans la réponse, jamais deux périodes fusionnées ;
`result` expose aussi l'`applicability` de chaque ligne. En place (lot L4c,
D196) :
- `publication-preflight`, `publication-state`, `publication.pdf` : lecture,
  période par défaut sans paramètre ;
- `publish` (`lines` facultatif) et `republish` (`newLines` facultatif) :
  écriture, `period` exigé sur un planning à plusieurs périodes (422
  `period_required`) ;
- `GET /api/plannings/{p}/publications/{publication}/pdf` : le PDF stocké
  d'une publication passée.
En place (lot L4d, D197) : `duties/{d}/reassignment-candidates`,
`duties/{d}/reassign` et `duties/{d}/unassign` ne prennent pas de
paramètre : la période est celle de la garde, renvoyée dans `period`. Une
garde d'une période orpheline : 404. L'endpoint technique
`POST /api/planning-generations/{id}/assignments` est fermé (410
`endpoint_removed`).
En place (lot L4e, D198), sans paramètre de période : `GET /api/me/duties`
(toutes les périodes publiées, `period` par unité), le flux
`/api/calendar-feeds/{token}.ics`, et les échanges (`/api/duty-swaps/options`,
`/api/duty-swap-requests`, propositions, acceptation) : la période est celle
des gardes, `period` dans chaque unité décrite ; deux périodes : 422
`other_period`.
En place (lot L4f, D200) : `export` (champ `period` facultatif, ou
`from`/`to` sur plusieurs périodes ; sans les deux : tout le planning ;
409 `not_published_in_range`) et `availability-export.pdf` (`?period` ou
`?from=&to=`, tout le planning sans paramètre ; 422 `validation_failed`
— jamais 404 pour une période inconnue, contrairement aux autres
endpoints).
`POST /api/plannings/{p}/lines` accepte
`fromPeriodStableId`. Erreur commune : 404 `period_not_found` si la
période n'appartient pas au planning.

### 7.5 Statistiques (H)

`GET /api/plannings/{p}/statistics?period={c}` :

```json
{
  "period": {"stableId": "…", "number": 3, "startsAt": "…", "endsAt": "…",
             "lines": [{"lineStableId": "…", "publication": "PUBLISHED | UNPUBLISHED | NOT_GENERATED",
                        "groups": [ … ]}]},
  "cumulative": {
    "throughPeriodNumber": 3,
    "published":   {"periodNumbers": [1, 2, 3], "groups": [ … ]},
    "unpublished": {"periodNumbers": [], "groups": [ … ]}
  }
}
```

- **Période** : les gardes du cycle sélectionné ; chaque ligne porte son état
  de publication, affiché.
- **Cumul publié** : les périodes PUBLISHED des cycles `≤` sélectionné, ligne
  par ligne, affectations courantes (réaffectations après publication
  comprises, comme aujourd'hui).
- **Cumul en préparation** : les périodes non publiées des cycles `≤`
  sélectionné qui ont une génération COMPLETED. **Jamais additionné** au
  cumul publié ; affiché à part (« En préparation »).
- Règles de comptage inchangées (gardes individuelles, charge pondérée,
  vendredis, samedis, dimanches, types, familles) ; lignes jamais mélangées ;
  lignes de résultat par adhésion (stint) comme aujourd'hui.

**En place (lot L5, D201)** — forme réelle, écart assumé avec le JSON
ci-dessus pour ne pas casser l'écran actuel avant L6 :

```json
{
  "period": {"stableId": "…", "number": 3, "startsAt": "…", "endsAt": "…",
             "lines": [{"lineStableId": "…", "lineName": "…", "publication": "PUBLISHED | UNPUBLISHED | NOT_GENERATED"}]},
  "currentPeriod": {"startsAt": "…", "endsAt": "…", "throughPeriodNumber": 3, "periodNumbers": [3],
                    "groups": [{"groupStableId": "…", "groupLabel": "…", "publication": "UNPUBLISHED", "provisional": true,
                                "periodNumbers": [3], "members": [ … ]}]},
  "cumulative":  {"…": "published line periods of periods ≤ 3"},
  "provisional": {"…": "unpublished line periods with a generation, periods ≤ 3 — null for a plain member"}
}
```

- `?period` obligatoire sur plusieurs périodes (422 `period_required`),
  facultatif sur une seule ; 404 `period_not_found`.
- Un simple membre ne voit rien de non publié (groupes retirés de
  `currentPeriod`, `provisional` null) ; un gestionnaire (`MANAGE_CALENDAR`)
  voit tout.
- Lecture par `CycleHistoryReader` (§9).

---

## 8. Frontend (réutilisation de `features/periods`)

| Composant | Branchement réel |
|---|---|
| `PlanningCard` | « Mes plannings » : `currentPeriod` / `nextPeriod`, `lineCount`, `memberCount`, rôle déduit des droits (Propriétaire = `canManage`, Gestionnaire = `canPublish`, sinon Membre). Plus de badge global « Publié ». Tableau de bord aligné. |
| `PeriodSelector` | `periods[]`, sélection dans l'URL `?periode=N`, création si `canManage`. |
| `PeriodDetailCard` | période sélectionnée ; bannière « Consultation de l'historique » pour une période publiée antérieure à la dernière. |
| `PreparationSteps` | collecte (progression réelle `acknowledged/expected`, échéance ; « Ouvrir la collecte » → §5.9), génération (`generatedAt`), publication (`publishedAt`). |
| `CreatePeriodDialog` | `POST …/periods` ; `onCreate` asynchrone (attente, erreurs, 409 « la période a changé, rechargez »). |
| `PeriodTabs` | **remplacé** par les vrais onglets existants (Lignes, Indisponibilités, Planning : calendrier, statistiques Période / Cumul publié / En préparation, publication, export), tous paramétrés par la période ; le style de barre d'onglets de la maquette est repris. |
| `StatusPill`, `TimeBar`, `MonthTimeBar`, `dates.ts`, `periods.ts` | réutilisés ; statuts mappés §4.2 (`archivee` non produit). |

- En-tête : celui de la maquette (« Commun à toutes les périodes »),
  fusionné avec `PilotHeaderActions` ; « Vous en faites partie » ←
  `participating` ; badge « Actif » retiré (aucune donnée réelle).
- Changement de période = changement de contexte : composants dépendants
  remontés par `key={period.stableId}`, requêtes annulées
  (`AbortController`, déjà accepté par `apiFetch`) et réponses dont
  `period.stableId` ne correspond plus ignorées.
- Mobile : CSS de la maquette ; la barre d'action fixe est placée au-dessus
  de la navigation du bas (`--bottomnav-height`) ; contrôlé à 390 px, sans
  défilement horizontal.
- La page `/dev/periodes` (D185) est supprimée en fin de L6.

**En place (lot L6, D202)** — écarts avec le tableau ci-dessus :
- `PreparationSteps` ne change pas d'onglet au clic ; il porte en plus l'état
  de chaque ligne dans la période (publiée, générée, à générer, incomplète
  avec ses prérequis, sans garde demandée).
- La collecte s'ouvre seulement si `canOpenPeriodCollection` et des jours de
  la période ne sont couverts par aucune collecte (`uncoveredDays`).
- « Prolonger la période » (D122) n'est offert que sur un planning à une
  seule période (menu « ⋯ »).
- `fetchPlanningStatistics`, le calendrier, les modales de publication, la
  génération (`scope: "unlocked_lines"` choisi explicitement) et le suivi de
  collecte nomment toujours la période ; une réponse d'une autre période est
  ignorée.

---

## 9. Équité inter-périodes (G) — préparé, non implémenté

- Aucune compensation dans ce chantier : chaque génération reste équilibrée
  sur sa seule période (état actuel, D132).
- Données préservées pour une future équité cumulative, sans nouvelle
  table : gardes immuables (date, type, poids, famille par motif), affectations
  courantes et historiques, événements d'audit, adhésions datées
  (participation), collectes et réponses, publications. Invariant I10
  (pas de poids ni de famille modifiables sans version).
- Point d'extension unique : `CycleHistoryReader` (lecture des affectations
  courantes de cycles donnés, par ligne/équipe et par personne), utilisé par
  le cumul des statistiques. Un futur lot pourra en dériver des cibles
  historiques (`docs/allocation-algorithm.md` §4.4) sans second moteur ;
  `OptimizationProblem` et le solveur ne changent pas dans ce chantier.

---

## 10. Dépendances impactées

| Zone | Fichiers (backend `src/` sauf mention) | Lot |
|---|---|---|
| Entités | `PlanningCycle` (nouveau), `PlanningPeriod`, `PlanningLine`, `AvailabilityCollection`, `PlanningJob`, `PlanningPublication`, `PlanningAvailabilityReminder` | L1 |
| Résolution ligne/cycle → période | ~40 appels `getPlanningPeriod()` ; `PlanningLineRepository::findOneByPlanningPeriod` ; `PlanningRepository::findWithPublishedLine*` ; SQL brut `PlanningJobStore`, `PlanningJobRecovery` | L1 (cycle 1) puis L2 |
| Planning / lignes | `PlanningService`, `PlanningLineService` (addLine, deleteLine), `PlanningExtensionService`, `AvailabilityWindowCalculator`, `FairnessPeriodService`, contrôleurs Planning / Line / Extension | L2 |
| Collectes | `AvailabilityCollectionService`, `PlanningCollectionStatusService`, `AvailabilityReminderService`, contrôleurs collection / status / settings | L2 |
| Génération | `PlanningGenerationLauncher`, `PlanningJobService`, `RunPlanningJobHandler`, `PlanningGenerationService::create`, `PlanningSnapshotService`, `PersonCommitmentReader`, `WeeklyDutyCalendarService`, `DutyMaterializationService`, `LiveDemandViewFactory`, `PlanningCompletionService`, `PlanningLineDemandPolicyService`, `SurgicalHubFreshnessGate`, `PlanningLaunchController`, `PlanningCompletionController` | L3 |
| Lecture / réaffectation | `CurrentCalendarReader`, `PlanningResultService`, `PlanningAssignmentViewService`, `DutyReassignmentService`, `ReassignmentCandidateService` | L4 |
| Publication | `PlanningPublicationService`, `PlanningPublicationPreflightService`, `PublicationChangeService`, `PlanningPdfRenderer`, `PlanningPublicationMailer`, templates `pdf/planning`, `email/planning_published*` | L4 |
| Transverses | `MyDutiesService`, `DutyCalendarFeedRenderer`, `WeeklyDutyReminderService`, `DutySwapService`, `DutySwapQueryService`, `PlanningExportService`, `PlanningExportRequestParser`, `PlanningAbsenceExportDataBuilder` | L4 |
| Statistiques | `PlanningStatisticsService`, `PlanningStatisticsController`, `CycleHistoryReader` (nouveau) | L5 |
| Frontend | `pages/PlanningsPage`, `pages/PlanningDetailPage`, `pages/DashboardPage`, `features/planning/*` (types, api, calendar, result/StatisticsPanel, PublishModal, pilot, jobs, ExtendPlanningForm), `features/periods/*` | L6 |
| Docs | ce document, `planning.md`, `planning-domain.md`, `planning-generation.md`, `availability-collection.md`, `fairness.md`, `allocation-algorithm.md`, `planning-export.md`, `duty-swaps.md`, `deployment.md`, `decisions.md` (D186+), `CLAUDE.md` | chaque lot |

---

## 11. Lots et critères de validation

Chaque lot : tests unitaires + fonctionnels backend du lot, suite complète
verte, `tsc`/oxlint/Prettier/Vitest verts, documentation et décisions du lot
écrites. Aucun commit hors du chantier. Aucun déploiement sans accord.

**L1 — Domaine et migration additive (aucun changement visible)**
- `PlanningCycle`, colonnes et contraintes ; tout nouvel enregistrement
  est rattaché au cycle unique ; les lectures ne changent pas (elles
  passent par le cycle à partir de L2). Corrigé à la validation de L1
  (D186) : la fermeture des trous I8 (génération et matérialisation
  refusées sur une période publiée) change une règle de génération et
  passe en L3.
- Critères : migration up/down/up sur base de dev et sur restauration jetable
  du dump de production ; `app:planning-cycles:audit` identique avant/après ;
  contraintes vérifiées par tests d'intégration (chevauchement de cycles,
  doublon de numéro, deux périodes pour une ligne et un cycle, période hors
  équipe) ; suite backend complète verte sans changement de comportement ;
  l'ancien tag tourne sur le schéma L1.

**L2 — Périodes : créer, allonger/raccourcir, supprimer ; lignes ; collectes**
- Critères : 2e et 3e cycles créés ; plages invalides refusées ; double
  soumission (deux requêtes concurrentes réelles → une seule création) ;
  atomicité multi-ligne (échec forcé sur la dernière ligne → aucun cycle) ;
  cycle 1 strictement identique (empreintes) après création du 2e ; règles
  B et L couvertes cas par cas (chaque code 409) ; ajout de ligne F (cycle
  futur publié, aucun cycle éligible, conditionnelle) ; collecte manuelle,
  membres attendus, confirmations indépendantes, aucune réouverture ;
  `collection-status` et rappels par cycle ; création désactivée par défaut.

**L3 — Génération, complétion, jobs** (implémenté, D189)
- Comprend la fermeture des trous I8, reportée de L1 (D186), et le refus des
  frontières qui coupent un bloc (option A).
- Critères : génération limitée au cycle choisi ; période publiée d'un autre
  cycle inchangée (empreintes) ; isolation lignes/équipes ; indisponibilités
  et règles réelles ; repos et conflits à la frontière (garde le dernier jour
  du cycle N, solveur réel OR-Tools refuse le lendemain dans N+1, et
  symétrique N+1 généré avant N) ; politique conditionnelle J (source dans le
  même cycle, changement de mode refusé/accepté) ; récupération d'un job
  abandonné sur un cycle 2 ; SurgicalHub limité aux participants du cycle.

**L4 — Lectures, réaffectation, publication, transverses**
- Critères : calendrier et `/result` par cycle ; réaffectation et échange
  après publication toujours possibles et audités, y compris sur un cycle
  passé ; échange entre deux cycles refusé ; publication N+1 avant N avec
  avertissement non bloquant ; PDF/emails « Période N » pour les nouvelles
  publications, anciens documents servis à l'octet près ; « Mes gardes »,
  agenda et rappel du samedi sur plusieurs cycles publiés ; export limité aux
  cycles publiés.

**L5 — Statistiques**
- Critères : période sélectionnée ; cumul publié sur les cycles `≤`
  sélectionné ; en préparation séparé et signalé ; vendredis / samedis /
  dimanches / charge / types / familles identiques aux valeurs actuelles sur
  un planning à un cycle (non-régression).

**L6 — Frontend**
- Critères : composants `features/periods` branchés (aucune nouvelle UI) ;
  sélecteur, navigation, URL ; bon calendrier et bonnes statistiques par
  période ; réponse tardive d'une période précédente ignorée (test) ;
  création via la fenêtre, erreurs 409/422 affichées ; états de chargement ;
  responsive 390 px sans défilement horizontal.

**L7 — Recette et documentation**
- Recette navigateur ordinateur + smartphone sur la pile de dev avec OR-Tools
  et worker réels : planning à un cycle (non-régression), création des
  cycles 2 et 3, collecte, génération, publication de 3 avant 2, réaffectation
  après publication, échange, statistiques. Documentation et décisions à jour.
  Puis, sur accord : déploiement avec création désactivée, contrôles,
  activation séparée.
