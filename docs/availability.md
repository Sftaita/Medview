# Disponibilités et non-participation — choix techniques

Ce document explique **comment** le Lot 2 (disponibilités, non-participation
administrative, préférences) est construit et **pourquoi**. Il complète
`docs/authentication.md` (socle utilisateur/JWT) et `docs/planning-domain.md`
(équipes, membres, historique de participation).

Portée : calendrier personnel (`UserAvailabilityPeriod`), non-participation
administrative par équipe (`TeamMemberNonParticipationPeriod`), lecture
minimale des membres d'une équipe. **Pas encore** : `EligibilityService`,
matrice `Duty × Candidate`, calcul de `structuralOpportunity`, snapshot de
génération, scoring des préférences — voir §7 de ce document et §18 du
cahier des charges original.

---

## 1. Modèle de données

### Entité `UserAvailabilityPeriod` (`backend/src/Entity/UserAvailabilityPeriod.php`)

Le calendrier personnel d'un `User` — jamais d'un `TeamMember`. Une seule
ligne s'applique à toutes les équipes de cet utilisateur (voir §3).

| Champ | Type | Notes |
|---|---|---|
| `id` | int, auto-increment | |
| `stableId` | UUIDv7 | URLs publiques (`/api/me/calendar/{stableId}`) — jamais `id`. |
| `user` | `ManyToOne User`, `ON DELETE CASCADE` | Toujours l'utilisateur courant côté API (§6) — jamais un autre `User` en paramètre. |
| `startsAt` / `endsAt` | `TIMESTAMPTZ` (`datetimetz_immutable`) | Instant absolu, comme `Duty` — supporte les plages horaires partielles et les jours entiers indifféremment. Semi-ouvert `[startsAt, endsAt[`. |
| `type` | `UserAvailabilityType` (`UNAVAILABLE` \| `PREFER_DUTY`) | Voir §2. |
| `createdAt` / `updatedAt` | `DateTimeImmutable` | |

Volontairement **aucun champ `reason`** ni catégorie (maladie/congé/
formation/…) — voir §2. Contrairement à `TeamMemberParticipationPeriod`/
`FairnessPeriod`, ce n'est pas un historique append-only : `reschedule()`
modifie une entrée en place (pas de valeur d'audit à préserver pour une
entrée de calendrier personnel).

### Entité `TeamMemberNonParticipationPeriod` (`backend/src/Entity/TeamMemberNonParticipationPeriod.php`)

Une fenêtre de non-participation administrative attachée au `TeamMember`
(donc à **une** équipe) — jamais au `User`.

| Champ | Type | Notes |
|---|---|---|
| `id` | int, auto-increment | |
| `stableId` | UUIDv7 | URLs publiques — jamais `id`. |
| `teamMember` | `ManyToOne TeamMember`, `ON DELETE CASCADE` | |
| `startsAt` / `endsAt` | `TIMESTAMPTZ` | Même sémantique que ci-dessus. |
| `createdAt` / `updatedAt` | `DateTimeImmutable` | |

### `TeamMember.stableId` (D056)

`docs/planning-domain.md` avait explicitement choisi de **ne pas** ajouter
de `stableId` à `TeamMember` ("aucun besoin identifié... YAGNI"). Ce lot
introduit le premier besoin réel : adresser un membre depuis une URL
publique (`/api/teams/{teamStableId}/members/{memberStableId}/...`) sans
jamais exposer l'`id` auto-incrémenté. Migration `Version20260916071843` —
backfill nullable-puis-NOT-NULL, même méthode que `users.stable_id`
(`Version20260915212445`).

---

## 2. Sémantique `UNAVAILABLE` / `PREFER_DUTY`

`UserAvailabilityType` :

- **`UNAVAILABLE`** — signal **dur** : `eligibility = false` pour toute
  garde concernée, dans **toutes** les équipes de l'utilisateur (§3).
  `structuralOpportunity` reste **inchangée** — c'est ce qui rend le
  "gaming" inopérant (docs/allocation-algorithm.md §20 "Preuve de
  résistance au gaming") : déclarer massivement des indisponibilités
  exclut l'utilisateur des gardes sans jamais améliorer son ratio
  d'équité apparent.
- **`PREFER_DUTY`** — signal **doux** : ne touche ni `eligibility` ni
  `structuralOpportunity`. Stocké et exposé par ce lot ; aucun calcul de
  score de préférence n'est encore implémenté (`optimizationPreference`,
  hors périmètre — §7).

Pas de champ `reason`, pas de catégories : demande explicite du cahier des
charges pour ce lot. Une préférence ne peut jamais contourner une
indisponibilité ou une contrainte dure — ce sera la responsabilité du
futur moteur (non implémenté) de faire dominer le signal dur.

---

## 3. Indisponibilité personnelle vs non-participation administrative

Deux notions distinctes, volontairement non fusionnées :

| | `UserAvailabilityPeriod` (`UNAVAILABLE`) | `TeamMemberNonParticipationPeriod` |
|---|---|---|
| Rattaché à | `User` | `TeamMember` (une équipe) |
| Portée | Toutes les équipes de l'utilisateur | Une seule équipe |
| Qui déclare | L'utilisateur lui-même | OWNER/ADMIN de l'équipe (§6) |
| Effet futur sur `eligibility` | `false` | `false` |
| Effet futur sur `structuralOpportunity` | **Inchangée** | **`0`** |

Exemple (repris du cahier des charges, testé dans
`TeamMemberNonParticipationServiceTest::testNonParticipationInOneTeamDoesNotAffectAnother`) :
un `User` membre de Team A et Team B, mis en non-participation
administrative dans Team A pour novembre, reste normalement candidat dans
Team B. Une indisponibilité personnelle, elle, aurait bloqué les deux
équipes (`UserAvailabilityServiceTest::testUnavailabilityIsStoredOnceAndVisibleAcrossBothTeams`).

`TeamMemberParticipationPeriod` (le facteur structurel de participation
dans le temps, `docs/planning-domain.md`) n'est pas modifiée par ce lot —
voir aussi la note ajoutée dans `docs/allocation-algorithm.md` §20.

---

## 4. Règles de chevauchement

Intervalles semi-ouverts `[startsAt, endsAt[`. `startsAt >= endsAt` est
refusé (`InvalidArgumentException` au niveau entité, `422
validation_failed` au niveau API).

**Politique volontairement plus stricte que `FairnessPeriod`/
`TeamMemberParticipationPeriod`** (qui autorisent deux segments à se
toucher exactement) : ici, deux périodes qui se chevauchent **ou se
touchent** (la fin de l'une égale le début de l'autre) sont toutes deux
refusées avec `409 Conflict`. Raison : contrairement à un historique de
segments contigus par construction, ces périodes sont créées
indépendamment les unes des autres par des appels API séparés — les
laisser se toucher silencieusement créerait des données ambiguës sans
bénéfice.

- `UserAvailabilityPeriod` : le chevauchement/contact est vérifié **par
  type** (`user_id, type`) — deux périodes `UNAVAILABLE` du même
  utilisateur ne peuvent ni se chevaucher ni se toucher ; il en va de même
  pour deux `PREFER_DUTY`. En revanche `UNAVAILABLE` et `PREFER_DUTY`
  peuvent librement coexister sur les mêmes dates (§2).
- `TeamMemberNonParticipationPeriod` : vérifié par `team_member_id` (pas
  de dimension `type`).

Double garde-fou, comme pour `FairnessPeriod`/`TeamMemberParticipationPeriod` :

1. **Application** — `UserAvailabilityService`/`TeamMemberNonParticipationService`
   interrogent `findOverlappingOrTouchingForUser()`/`...ForTeamMember()`
   avant toute création/modification et lèvent une exception dédiée
   (`OverlappingUserAvailabilityPeriodException` /
   `OverlappingNonParticipationPeriodException`), traduite en `409` par le
   contrôleur.
2. **Base de données** — contrainte `EXCLUDE USING gist` (`btree_gist`,
   déjà activé par `Version20260915212500`), mais avec des bornes
   **inclusives** `tstzrange(starts_at, ends_at, '[]')` plutôt que `'[)'`
   comme pour `FairnessPeriod` : deux périodes qui se touchent partagent
   alors un point commun, que l'opérateur `&&` détecte correctement comme
   un chevauchement. Non `DEFERRABLE` (contrairement à
   `excl_participation_periods_no_overlap`) : chaque création/modification
   ici est une opération unique (un seul `INSERT`/`UPDATE`), pas une paire
   fermeture-puis-ouverture — pas d'état intermédiaire transitoire à
   couvrir.

Testé aux frontières dans `UserAvailabilityPeriodTest`/
`TeamMemberNonParticipationPeriodTest` (`overlapsOrTouches()`) et via les
tests de service qui contournent délibérément le service pour prouver que
c'est la contrainte SQL, pas seulement le code applicatif, qui empêche un
chevauchement.

---

## 5. Portée multi-équipe

`UserAvailabilityPeriod` est stocké **une seule fois** sur `User`, jamais
dupliqué par équipe. `UserAvailabilityPeriodRepository::findByUser()` et
`findOverlappingOrTouchingForUser()` ne filtrent jamais par équipe — le
futur `EligibilityService` (non implémenté) pourra les interroger
directement pour n'importe quelle `Team` de l'utilisateur candidat.

`TeamMemberNonParticipationPeriod`, à l'inverse, est intrinsèquement
scopé par `team_member_id` — un même `User` peut avoir des fenêtres de
non-participation indépendantes dans chacune de ses équipes.

---

## 6. Autorisations

Pas de rôle global `ROLE_ADMIN` (CLAUDE.md D012) : tout passe par
`TeamMember::role` (`OWNER`/`ADMIN`/`MEMBER`) et un Voter dédié.

### `PlanningTeamRoleVoter` (`backend/src/Security/Voter/PlanningTeamRoleVoter.php`, D057; renommé depuis `TeamRoleVoter` — docs/decisions.md D079)

Premier Voter du projet — jusqu'ici seulement évoqué dans les docblocks de
`TeamMember`/`TeamMemberRole`. Scopé strictement aux besoins de ce lot (pas
de framework d'autorisation générique) :

| Attribut | Sujet | Règle |
|---|---|---|
| `TEAM_VIEW` | `Team` | Tout membre actuel (n'importe quel rôle) de cette équipe. |
| `TEAM_MEMBER_MANAGE_NON_PARTICIPATION` | `TeamMember` (le membre ciblé) | OWNER/ADMIN de l'équipe de ce membre uniquement. |
| `TEAM_MEMBER_VIEW_NON_PARTICIPATION` | `TeamMember` (le membre ciblé) | Le membre lui-même, ou OWNER/ADMIN de son équipe. |

### `/api/me/calendar` — pas de Voter

Ces endpoints n'acceptent jamais un autre `User` en paramètre : toute
opération passe par `#[CurrentUser] User $user` et interroge/modifie
exclusivement les lignes de cet utilisateur (`findOneByStableId()` +
comparaison `->getUser() !== $user` → `404`, jamais `403`, pour ne jamais
confirmer l'existence d'une entrée d'un autre utilisateur). Un Voter
serait une couche supplémentaire sans règle métier à exprimer — voir la
réponse utilisateur consignée dans la session de ce lot.

---

## 7. Endpoints

### Calendrier personnel

```
GET    /api/me/calendar
POST   /api/me/calendar
PATCH  /api/me/calendar/{stableId}
DELETE /api/me/calendar/{stableId}
```

`PersonalCalendarController`. Payload `POST`/`PATCH` :
`{"type": "UNAVAILABLE" | "PREFER_DUTY", "startsAt": "...", "endsAt": "..."}`
(ISO 8601). DTO dédié `UpsertUserAvailabilityPeriodRequest` — jamais de
désérialisation directe dans l'entité Doctrine.

### Membres d'une équipe (minimal, D059)

```
GET /api/teams/{teamStableId}/members
```

`TeamMembersController`. Volontairement minimal : `stableId`, `firstName`,
`lastName`, `role`, `active`. Pas d'invitation, pas d'ajout/suppression de
membre, pas d'édition de rôle, pas de création d'équipe — hors périmètre
de ce lot (la gestion d'équipe complète est un lot futur). Existe
uniquement pour donner au frontend de quoi lister les membres et ouvrir la
gestion de leur non-participation (`TeamDetailPage`).

### Non-participation administrative

```
GET    /api/teams/{teamStableId}/members/{memberStableId}/non-participation
POST   /api/teams/{teamStableId}/members/{memberStableId}/non-participation
PATCH  /api/teams/{teamStableId}/members/{memberStableId}/non-participation/{stableId}
DELETE /api/teams/{teamStableId}/members/{memberStableId}/non-participation/{stableId}
```

`TeamMemberNonParticipationController`. Payload `POST`/`PATCH` :
`{"startsAt": "...", "endsAt": "..."}` (pas de `type`). Un membre d'une
autre équipe que `teamStableId`, ou un `memberStableId` inexistant,
renvoie `404` (jamais de fuite d'existence cross-équipe).

Aucune ressource API Platform générique sur ces entités (CLAUDE.md) : DTO
dédiés, contrôleurs Symfony classiques, services métier (`UserAvailabilityService`,
`TeamMemberNonParticipationService`) qui centralisent la détection de
chevauchement — les contrôleurs restent fins (désérialisation, appel
service, mise en forme JSON).

---

## 8. Frontend

`/my-availability` (`MyAvailabilityPage.tsx`) : calendrier de **sélection
multiple** maison (pas de nouvelle dépendance de calendrier). Décisions et
raisons : `docs/decisions.md` D117 (charte) et D118 (modèle et enregistrement).
Résumé :

- **Jour entier uniquement** pour `UNAVAILABLE` *comme* pour `PREFER_DUTY` : plus
  de plage horaire (l'ancien champ « Préciser une plage horaire » est supprimé).
  L'API, elle, accepte toujours des instants — d'anciennes périodes avec heure
  restent lisibles (affichées comme les jours qu'elles touchent) et ne sont
  jamais modifiées sans action de l'utilisateur sur ces jours.
- **Gestes** : tap / clic = bascule d'une journée ; appui-glisser = période
  continue (chaque glisser en **ajoute** une) ; glisser jusqu'au bord fait défiler
  d'un mois (temporisé) ; `Ctrl/Cmd`+clic retire, `Maj`+clic étend. Deux mois
  côte à côte à partir de 1280 px, un seul en dessous (dont le téléphone).
- **Deux natures** jamais fusionnées : indisponibilité (rouge) et préférence de
  garde (vert). Une garde tracée sur une indisponibilité la remplace sur la zone
  commune.
- **Enregistrement** : bouton « Enregistrer » (désactivé tant qu'il n'y a rien à
  changer) ; les plages contiguës sont envoyées comme **une** période chacune
  (règle §4) ; suppressions avant créations ; `409` → « Une période de même type
  chevauche ou touche déjà ces dates. »
- Code : `src/features/availability/` (`calendarAxis`, `selection`,
  `periodMapping` : purs et testés ; `useDaySelection` : gestes ;
  `AvailabilityCalendar`, `SelectionSummary`, `calendar.css`).

`TeamDetailPage.tsx` : table minimale des membres (D059) + panneau de
gestion de la non-participation administrative par membre (liste, création,
suppression — pas d'édition inline, l'endpoint `PATCH` reste disponible
pour un futur besoin). Le calendrier personnel et la non-participation
administrative sont volontairement deux UI séparées, jamais présentées
comme la même notion (§3).

---

## 9. Hors périmètre de ce lot

Comme listé dans la demande d'origine : `PlanningGeneration`,
`PlanningSnapshot`, `DutyAssignment`, matrice `Duty × Candidate`,
`EligibilityService` complet, calcul de `structuralOpportunity` final,
optimisation/solveur, scoring des préférences, résolution automatique des
conflits entre équipes, notifications, échanges de gardes. Ce lot fournit
uniquement le modèle de données et les API sur lesquels ces couches futures
s'appuieront.
