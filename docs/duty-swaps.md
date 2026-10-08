# Échanges de gardes entre membres

> Décisions : `docs/decisions.md` D178 (modèle et parcours), D179
> (revalidation sur le calendrier final, atomicité, concurrence), D180
> (emails, reprise, maintenance). Code : `src/Service/DutySwap*.php`,
> `DutyReassignmentService::applySwap()`, `src/Controller/DutySwapController.php`,
> `frontend/src/features/swaps/`, `frontend/src/pages/SwapsPage.tsx`.

## 1. Principe

Deux membres d'une même ligne de planning échangent leurs gardes **sans
validation du gestionnaire**. Une règle domine tout le reste :

> **Tant qu'un échange n'est pas accepté et effectivement enregistré dans
> MedVue, chacun reste responsable de sa garde initiale.**

Une demande ou une proposition, quel que soit son statut, ne change
**jamais** le titulaire d'une garde. La seule source de vérité des
titulaires reste `DutyAssignment.current` (D131) ; une demande n'est qu'un
workflow à côté du calendrier. Le calendrier ne change qu'au moment où la
personne qui décide accepte, que le serveur **revalide tout** et écrit la
permutation dans une seule transaction.

L'avertissement de responsabilité est affiché avant tout envoi (et doit être
coché : `acknowledgedResponsibility: true`, refusé en 422 sinon), sur chaque
demande en attente côté demandeur, et répété dans chaque email d'une demande
ou d'une proposition en attente.

## 2. Parcours

Depuis **Mes gardes**, sur une garde à venir : **Échanger ma garde**.

| Choix | Ce qui est créé | Qui décide |
|---|---|---|
| « J'ai déjà convenu d'un échange » — un collègue et **sa** garde | demande `AGREED` + **une** proposition rédigée par le demandeur | le collègue (accepte / refuse) |
| « Je cherche quelqu'un… » → un collègue / plusieurs collègues | demande `SEARCH`, audience `SELECTED` + destinataires | le demandeur, parmi les propositions reçues |
| « Je cherche quelqu'un… » → toute l'équipe | demande `SEARCH`, audience `ALL` (membres actuels de la ligne) | le demandeur |

- Une garde d'un **bloc** (`DutyGroupInstance`) s'échange toujours **bloc
  entier** : la demande nomme le premier jour du bloc, quelle que soit la
  journée choisie ; jamais un jour seul. Un bloc peut s'échanger contre une
  garde isolée (les deux personnes l'ont accepté).
- Les deux gardes appartiennent à la **même ligne** (même `PlanningTeam`,
  même `PlanningPeriod`, même génération).
- Une seule demande ouverte par garde (index unique partiel) ; une seule
  proposition en attente par personne et par demande.
- Pour une demande ouverte, la page **Échanges** (`/swaps`) montre trois
  listes : **Mes demandes**, **Propositions reçues**, **Demandes de
  l'équipe**. Ouvrir une demande montre ses propositions, les actions
  permises et son historique. « Mes gardes » marque la garde **« Échange
  demandé »** — elle reste listée parce qu'elle est toujours à vous.
- Accepter passe par une **confirmation explicite** qui rappelle la nouvelle
  répartition ; l'écran n'affiche ensuite que ce que le serveur a répondu
  (aucune mutation optimiste).
- Annuler : le demandeur, tant que la demande est ouverte. Retirer : l'auteur
  d'une proposition en attente (pour un échange convenu, retirer = annuler la
  demande). **Un échange enregistré ne s'annule pas** (409
  `swap_already_completed`) : il faut un nouvel échange ou une réattribution
  explicite par le gestionnaire.

## 3. Modèle de données

| Table | Rôle |
|---|---|
| `duty_swap_requests` | la demande : planning, ligne, demandeur (`User` + `PlanningTeamMember`), garde offerte (`offered_duty_id`, premier jour du bloc) et **la ligne `DutyAssignment` exacte détenue au moment de la demande** (`offered_assignment_id`), `kind` `AGREED`/`SEARCH`, `audience` `SELECTED`/`ALL`, statut, `expires_at` (= début de la garde), `closed_at`, `accepted_proposal_id` |
| `duty_swap_request_recipients` | destinataires nommés (AGREED : exactement un ; SELECTED : au moins un ; ALL : aucun) |
| `duty_swap_proposals` | une contrepartie : auteur, titulaire (`counterpart_member_id`), garde (`counterpart_duty_id`) et **sa ligne `DutyAssignment` exacte** (`counterpart_assignment_id`), statut, `decided_at`, `decided_by_id` |
| `duty_swap_events` | journal **append-only** (trigger qui refuse UPDATE et DELETE) |
| `duty_swap_notifications` | boîte d'envoi des emails (§8) |
| `duty_assignment_events.swap_proposal_id` | relie chaque changement du calendrier dû à un échange à la proposition acceptée |

Qui décide n'est pas stocké : c'est **le participant qui n'a pas rédigé la
proposition** (`DutySwapProposal::getDecider()`). Personne n'accepte donc sa
propre proposition ni à la place d'un autre.

Garde-fous en base : une demande `OPEN` par ligne d'affectation offerte ;
une proposition `ACCEPTED` par demande ; une proposition `PENDING` par
(demande, auteur) ; `closed_at` renseigné ⇔ statut final ; `COMPLETED` ⇔
`accepted_proposal_id` ; `decided_at` ⇔ statut final. Rien n'est jamais
supprimé.

## 4. Statuts

Demande (seul `OPEN` n'est pas final) :

| Code | Libellé | Quand |
|---|---|---|
| `OPEN` | En attente de réponse / Proposition reçue (s'il y a quelque chose à décider pour vous) | à la création |
| `COMPLETED` | Échange confirmé | une proposition acceptée **et** la permutation écrite |
| `REFUSED` | Refusé | échange convenu refusé par le collègue |
| `CANCELLED` | Annulé | par le demandeur |
| `EXPIRED` | Expiré | la garde offerte a commencé |
| `OBSOLETE` | Devenu indisponible | la garde offerte n'est plus détenue par la ligne gelée (réattribution, autre échange), période plus publiée, ligne désactivée |

Proposition : `PENDING` (En attente de réponse / Proposition reçue),
`ACCEPTED` (Échange confirmé), `REFUSED` (Refusé), `WITHDRAWN` (Retirée),
`NOT_SELECTED` (Non retenu — une autre a été acceptée), `CANCELLED`,
`EXPIRED`, `OBSOLETE` (Devenu indisponible — la garde proposée a changé de
titulaire ou commencé ; pour un échange convenu, la demande devient
`OBSOLETE` avec elle).

Les fermetures « système » (expiration, obsolescence) sont faites **avant
d'afficher** une demande (`DutySwapService::settle()`) et par la commande de
maintenance pour toutes les autres (§8) ; chacune laisse un événement sans
acteur. Un échange enregistré clôt aussitôt, dans sa transaction, les
autres demandes et propositions qui portaient sur une ligne remplacée.

## 5. Droits

- Un membre : demande l'échange d'une garde **qu'il détient** (refus 409
  `duty_not_held` sinon), répond aux demandes qui lui sont adressées,
  propose une de **ses** gardes, consulte ses demandes et propositions,
  annule ses demandes ouvertes, retire ses propositions en attente.
- Une demande ciblée n'est visible que du demandeur et de ses destinataires ;
  une demande « toute l'équipe », des **membres actuels de la ligne**
  (adhésion non terminée à l'équipe de la ligne) — jamais d'une autre ligne
  ou d'un autre planning. Les propositions : visibles de leurs deux
  participants ; un autre membre ne voit que les siennes.
- Les destinataires doivent être des membres actuels et actifs de la ligne
  (422 `invalid_recipients` sinon).
- Gestionnaires (`PlanningVoter::MANAGE_CALENDAR`) : **lecture seule** de
  l'historique des échanges du planning (`GET /api/plannings/{id}/duty-swaps`,
  vue « Échanges » de l'onglet Planning). Aucune approbation n'existe ; un
  gestionnaire ne peut pas accepter pour un membre (403).
- Une demande non visible répond **404** (son existence n'est pas révélée).

## 6. Contrôles au moment de l'acceptation

Tout est revérifié dans `DutyReassignmentService::applySwap()`, **sous le
verrou du calendrier**, à partir de l'état réel courant — jamais de ce qui
était vrai à la création :

1. période `PUBLISHED` (pas brouillon, pas `ARCHIVED`), ligne active ;
2. aucune des deux gardes (bloc entier) n'a commencé ;
3. chaque garde est encore détenue **via la ligne `DutyAssignment` gelée**
   dans la demande / la proposition, et le bloc l'est en entier par la même
   personne — sinon `duty_changed` (même si la même personne la détient de
   nouveau par une ligne plus récente : une décision prise sur un état n'est
   jamais appliquée à un autre) ;
4. aucune ligne verrouillée (`locked`) ;
5. ligne conditionnelle : chaque renfort doit être requis par la demande
   live (D165) — un renfort superflu ou indéterminé ne change pas de
   titulaire ;
6. pour chacune des deux personnes, sur le **calendrier après permutation** :
   compte actif, adhésion couvrant les dates, membre du snapshot de la
   génération, indisponibilités et non-participations actuelles,
   chevauchements, repos légal et repos d'équipe de la génération, et toutes
   ses gardes des autres lignes (D161) — **la garde qu'elle cède est exclue
   de ses engagements** (`$releasedDuties`), sinon elle passerait pour un
   faux conflit avec la garde même qu'elle donne ;
7. après écriture, si l'échange a changé l'état d'un renfort dépendant d'une
   des deux gardes (ligne conditionnelle, D165), il est **annulé** (rollback,
   `changes_reinforcements`) : un échange entre membres ne laisse jamais un
   renfort à régler par le gestionnaire.

Les mêmes contrôles (sauf 7) sont faits **à titre indicatif** quand une
proposition est faite, pour refuser tout de suite un échange impossible ;
ce n'est jamais le dernier mot.

Un refus à l'acceptation : rien n'est écrit, un événement
`SWAP_VALIDATION_FAILED` (raison, partie concernée) est journalisé dans une
transaction séparée, la demande est réglée (`settle`) et la réponse 409
`swap_not_applicable` porte `reason` (code stable ou `ExclusionReason`),
`party` (`requester`/`counterpart`) et un message en français (« Bob Durand
ne peut pas assurer la garde du mardi 5 janvier 2027 : repos légal
insuffisant. »).

## 7. Historique

- `duty_swap_events` : `REQUEST_CREATED`, `REQUEST_SENT` (destinataires,
  nombre de personnes prévenues), `PROPOSAL_CREATED`, `PROPOSAL_WITHDRAWN`,
  `PROPOSAL_REFUSED`, `PROPOSAL_ACCEPTED`, `PROPOSAL_NOT_SELECTED`,
  `PROPOSAL_OBSOLETE`, `SWAP_COMPLETED` (qui prend quoi, lignes d'affectation
  remplacées et créées), `REQUEST_REFUSED`, `REQUEST_CANCELLED`,
  `REQUEST_EXPIRED`, `REQUEST_OBSOLETE`, `SWAP_VALIDATION_FAILED`. Acteur,
  date, références et données d'audit ; acteur nul pour une transition
  système.
- La chronologie affichée est **lue dans ce journal**, jamais reconstituée
  depuis les statuts. Un événement sur une proposition que le lecteur ne
  peut pas voir est omis ; la liste des destinataires n'est montrée qu'au
  demandeur et aux gestionnaires.
- Côté calendrier : nouvelles lignes `DutyAssignment` `source = SWAP`,
  anciennes `current = false` (jamais supprimées), un `DutyAssignmentEvent`
  par jour de chaque bloc (`author` = la personne qui a accepté,
  `wasPublished = true`, `swap_proposal_id`).

## 8. Emails

| Type | Destinataire | Quand |
|---|---|---|
| `AGREED_PROPOSAL_RECEIVED` | le collègue | échange convenu proposé |
| `REQUEST_RECEIVED` | chaque destinataire, ou chaque membre actuel de la ligne (ALL) | demande `SEARCH` envoyée |
| `PROPOSAL_RECEIVED` | le demandeur | une proposition reçue |
| `PROPOSAL_REFUSED` | l'auteur de la proposition | refus |
| `SWAP_CONFIRMED` | **chacun des deux participants** | échange enregistré |

Objet de la confirmation : « MedVue — Confirmation de votre échange de
gardes » ; elle donne la nouvelle répartition, rappelle que les attributions
sont officielles, **demande de prévenir la direction**, et précise
qu'aucune validation supplémentaire du gestionnaire n'est nécessaire. Les
emails « en attente » répètent que rien n'est modifié.

Fiabilité (même mécanisme que les emails de publication, D172/D173) :

- chaque email est une ligne `duty_swap_notifications` écrite **dans la
  transaction de l'étape** : une confirmation n'existe que si l'échange est
  commité, un échange commité a toujours ses deux confirmations ;
- envoi **après le commit** ; un échec SMTP ne défait jamais l'échange ;
- contenu figé (`payload`) : un renvoi dit la même chose ;
- `dedup_key` unique : la même étape ne peut pas devoir deux fois le même
  email à la même personne ;
- réservation atomique avant chaque envoi : jamais deux envois parallèles,
  jamais de renvoi d'un email `SENT` ;
- chaque tentative est tracée (`attempts`, `last_attempt_at`, `last_error`) ;
- `SENT` = accepté par le serveur SMTP, **jamais une preuve de réception** ;
  doublon possible uniquement si le processus meurt entre l'acceptation SMTP
  et l'enregistrement `SENT` (compromis assumé contre une perte).

**`app:duty-swaps:maintain`** (cron, `docs/deployment.md` §5 sexies) :
clôt les demandes qui ne peuvent plus aboutir (avec leurs événements), puis
retente les emails `FAILED`, `PENDING` depuis plus de 2 min, `SENDING`
depuis plus de 15 min, jusqu'à 5 tentatives. Sans danger à tout moment, même
deux fois en même temps.

Pour une demande « toute l'équipe », un seul email par membre et par demande
(pas de relance) ; la demande reste ensuite visible dans « Demandes de
l'équipe ».

## 9. Endpoints

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/me/duty-swaps` | `{mine, received, team}` |
| GET | `/api/duty-swaps/options?dutyStableId=` | garde offerte (contrôlée échangeable), collègues de la ligne et leurs gardes à venir, demande déjà ouverte |
| POST | `/api/duty-swap-requests` | `{dutyStableId, kind, audience, recipientUserStableIds, counterpartDutyStableId?, acknowledgedResponsibility}` → 201 + détail |
| GET | `/api/duty-swap-requests/{id}` | détail + `history` + `proposableUnits` |
| POST | `/api/duty-swap-requests/{id}/cancel` | annuler |
| POST | `/api/duty-swap-requests/{id}/proposals` | `{dutyStableId}` → 201 |
| POST | `/api/duty-swap-proposals/{id}/accept` | accepter (revalidation + permutation) ; `alreadyApplied: true` si déjà fait (double clic) |
| POST | `/api/duty-swap-proposals/{id}/refuse` | refuser |
| POST | `/api/duty-swap-proposals/{id}/withdraw` | retirer |
| GET | `/api/plannings/{id}/duty-swaps` | historique, gestionnaires seulement |

`GET /api/me/duties` expose en plus `dutyStableId`, `swappable` et
`swapRequestStableId`.

Erreurs : `{error, message, reason?, party?}` — `responsibility_not_acknowledged`
(422), `invalid_recipients` (422), `other_line` (422), `duty_not_held`,
`duty_not_swappable`, `duty_started`, `duty_locked`, `already_requested`,
`already_proposed`, `request_closed`, `proposal_closed`,
`swap_already_completed`, `swap_not_applicable` (409), `forbidden` (403),
`not_found` (404).

## 10. Concurrence

- Toute étape qui modifie une demande ou ses propositions verrouille d'abord
  la ligne de la demande (`SELECT … FOR UPDATE`) puis la relit : deux
  étapes sur une demande sont sérialisées, la seconde voit le résultat de la
  première (409 compréhensible, jamais un écrasement).
- L'acceptation prend **d'abord** le verrou du calendrier du planning
  (`CalendarWriteLock`, le même que les modifications du gestionnaire, la
  complétion et la publication), **puis** la ligne de la demande ; c'est la
  seule étape qui prend le verrou du calendrier — aucun cycle d'attente
  possible. Deux acceptations sur un même planning sont sérialisées : la
  seconde revalide contre l'échange de la première.
- Double clic / nouvelle tentative : la seconde acceptation trouve la
  proposition déjà `ACCEPTED` et renvoie l'état sans rien réécrire
  (`alreadyApplied: true`).
- Écriture : ordre D131 (toutes les lignes courantes des deux blocs marquées
  remplacées et flushées, puis les nouvelles insérées), à cause de l'index
  unique partiel vérifié instruction par instruction ; tout ou rien.

## 11. Publication

Un échange se fait sur une période **`PUBLISHED`** (les membres ne voient
que les lignes publiées). Pas de solveur, pas de régénération, statut
inchangé. Le calendrier courant change : « Mes gardes », l'agenda abonné,
le rappel du samedi et l'export le reflètent aussitôt ; le gestionnaire voit
« Modifications non publiées » et peut republier (les deux personnes
reçoivent alors aussi l'email de republication, D172).

## 12. Limites connues

- La concurrence réelle (deux processus simultanés) est garantie par les
  verrous PostgreSQL ; les tests automatisés la simulent en séquence (une
  seule connexion par test) — vérifiée en parallèle réel lors de la recette.
- Pas d'expiration choisie par le demandeur (seulement le début de la garde),
  pas de relance automatique, pas de notifications in-app (emails seulement).
- Échange limité à deux gardes d'une même ligne ; pas d'échange à trois ni
  entre lignes.
- Un échange qui modifierait les renforts nécessaires d'une ligne
  conditionnelle est refusé (le gestionnaire peut toujours réattribuer).
- La direction n'est pas un acteur de MedVue : l'email demande aux
  participants de la prévenir.
- L'équité est recalculée sur les affectations courantes (statistiques), pas
  rééquilibrée : un échange accepté par les deux personnes est respecté.
