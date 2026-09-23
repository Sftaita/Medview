# Collecte des disponibilités par fenêtre temporelle

Lot du 2026-09-21 — décisions `docs/decisions.md` D120 à D126.

## 0. Le problème

Un utilisateur qui possède déjà des `UserAvailabilityPeriod` n'a **pas** pour
autant revu ses disponibilités quand un planning est prolongé : X saisit des
absences le 07/08, le planning `01/09 → 31/12` est créé le 25/08, puis
prolongé le 10/12 jusqu'au 31/03. Rien ne prouve que X a regardé
janvier–mars. Il faut donc un **événement explicite** (« mes disponibilités
sont à jour »), rattaché à une **fenêtre** de dates, et conservé dans
l'historique.

Deux responsabilités, jamais fusionnées (D120) :

| Entité | Répond à |
|---|---|
| `UserAvailabilityPeriod` | « Cette personne est-elle indisponible à cette date ? » — vérité métier, seule source de l'éligibilité et des snapshots. |
| `AvailabilityCollectionResponse` | « Cette personne a-t-elle revu ses disponibilités pour cette tranche ? » — workflow de complétude. **Ne modifie jamais l'éligibilité.** |

## 1. Modèle de données

- **`AvailabilityCollection`** (`availability_collections`) : `planning`,
  fenêtre `[startsAt, endsAt)` (dates, fuseau du planning, `endsAt` exclusive
  comme `Planning`), `openedAt`, `deadline` (date, nullable), `createdBy`,
  `status` (`OPEN`/`CLOSED`), `closedAt`. Contraintes DB : `CHECK ends_at >
  starts_at`, **exclusion** `(planning_id =, daterange(starts_at, ends_at) &&)`
  — une date n'est collectée qu'une fois par planning.
- **`AvailabilityCollectionResponse`** : une ligne par **`User`** attendu
  (pas par `PlanningTeamMember` : une personne qui quitte puis revient reste un
  seul répondant, cf. D082). `acknowledgedAt` + `acknowledgementKind`
  (`CONFIRMED` / `NO_UNAVAILABILITY`, ensemble ou pas du tout — `CHECK`),
  `lastAvailabilityChangeAt`, `withdrawnAt`. Unique `(collection, user)`.
- Statut dérivé, jamais stocké : `ACKNOWLEDGED` (dès qu'il y a un
  `acknowledgedAt`), sinon `WITHDRAWN` (a quitté avant de répondre), sinon
  `PENDING` (« À renseigner »).

Les lignes de réponse sont créées **à l'ouverture** : l'effectif attendu et les
compteurs X/Y sont un fait historique, jamais recalculé depuis l'état courant
des adhésions ni des `UserAvailabilityPeriod`.

## 2. Qui est attendu

À l'ouverture : chaque utilisateur actif ayant, dans **n'importe quelle équipe
du planning**, une adhésion qui recoupe la fenêtre. Ensuite, dans une collecte
**ouverte** uniquement :

- **membre ajouté après l'ouverture** → ligne `PENDING` (ou réactivée s'il
  avait été retiré) — hook dans `PlanningTeamMembershipService::addMember` ;
- **membre retiré** → `WITHDRAWN` s'il n'avait pas répondu **et** que la fenêtre
  commence à/après la fin de son adhésion ; s'il a participé à une partie de la
  fenêtre, il reste attendu ; une réponse déjà donnée n'est jamais effacée ;
- une collecte **fermée** est de l'historique figé : elle ne gagne aucun
  répondant et n'enregistre plus aucune modification.

Le créateur n'est attendu que s'il est participant (§8).

## 3. Ce que « Répondu » veut dire

Un événement explicite, deux chemins (`POST …/acknowledge`, utilisateur courant
uniquement) :

- `CONFIRMED` — « mes disponibilités pour cette période sont à jour », avec ou
  sans absences ;
- `NO_UNAVAILABILITY` (`{"noUnavailability": true}`) — « je n'ai aucune
  indisponibilité sur cette période ». **Refusé (409 `unavailability_exists`)**
  tant qu'une période `UNAVAILABLE` recoupe la fenêtre (une `PREFER_DUTY` ou une
  absence hors fenêtre ne contredit rien). Le front ne propose même pas ce
  bouton si des absences sont à l'écran dans la fenêtre.

`absenceCount = 0` ne signifie donc ni « répondu » ni « pas répondu ».

**Idempotence** (double clic, deux onglets) : verrou pessimiste sur la ligne de
réponse dans une transaction courte ; la première confirmation gagne, un second
appel renvoie la même sans rien modifier — y compris avec un `kind` différent.
Réponse à une collecte fermée : 409 `collection_closed`, sauf si déjà répondu
(renvoyé tel quel).

## 4. Statuts et transitions

`OPEN → CLOSED` (`POST …/close`, idempotent). Pas de réouverture : une nouvelle
fenêtre ouvre une nouvelle collecte. **Rien ne ferme automatiquement**, pas
même l'échéance dépassée : une réponse tardive reste utile. L'échéance
(`PATCH`, `null` pour l'effacer) n'est modifiable que sur une collecte ouverte
et ne peut pas être dans le passé (jugé dans le fuseau du planning).

## 5. Extension d'un planning

`POST /api/plannings/{id}/extensions` (créateur seul — `PlanningVoter::MANAGE`) :
`{ startsAt?, endsAt?, deadline? }` = la **nouvelle plage complète** (une borne
omise est conservée).

`AvailabilityWindowCalculator` (pur) déduit les tranches **réellement
nouvelles** : extension par la fin → une fenêtre ; par le début → une fenêtre ;
des deux côtés → deux fenêtres ; jamais les dates déjà couvertes. Plusieurs
extensions successives donnent des fenêtres adjacentes, jamais chevauchantes.

- plage identique → 422 `no_new_range` (jamais de collecte vide) ;
- plage qui ne contient pas l'ancienne → 422 `range_shrink_not_supported` ;
- une ligne dont le `PlanningPeriod` est `VALIDATED`/`PUBLISHED`/`ARCHIVED` →
  409 `planning_period_locked` (voir D122) ;
- échéance dans le passé → 422, **avant** toute modification.

`PlanningExtensionService` verrouille la ligne `Planning` (`FOR UPDATE`) puis la
relit : deux extensions concurrentes sont sérialisées, la seconde voit la plage
de la première et est refusée `no_new_range` au lieu d'ouvrir une seconde
collecte. Tout ce qui peut refuser la demande est vérifié avant la première
écriture. Puis : `Planning`, et pour chaque ligne `FairnessPeriod` +
`PlanningPeriod` sont agrandis en place (D075 : la période d'une ligne égale
toujours la plage du planning) ; une collecte est ouverte par tranche.

## 6. `lastAvailabilityChangeAt`

`UserAvailabilityService` prévient (dans la même transaction) que des dates ont
changé ; les réponses des collectes **ouvertes** dont la fenêtre recoupe les
dates touchées (anciennes **et** nouvelles pour un déplacement) reçoivent
`lastAvailabilityChangeAt`. Pour une réponse non confirmée, l'admin voit ainsi
« a modifié son calendrier le 15/12, sans confirmer ». Créations, éditions,
suppressions, `UNAVAILABLE` et `PREFER_DUTY` comptent. Le calendrier étant
global (D057), un changement touche toutes les collectes ouvertes concernées de
tous les plannings.

**Une modification après confirmation ne rouvre pas la réponse** (D121) : elle
met seulement `lastAvailabilityChangeAt` à jour, et le front affiche
« calendrier modifié depuis le … ». Forcer une nouvelle confirmation à chaque
retouche serait pénible pour une information que l'admin voit déjà, et la
confirmation explicite reste une preuve valable de revue.

## 7. Fuseau et dates

Fenêtres et échéances sont des **dates** dans le fuseau du planning
(`Europe/Brussels`). Pour les comparer aux `UserAvailabilityPeriod`
(TIMESTAMPTZ), la fenêtre est convertie en instants : minuit local de
`startsAt` → minuit local de `endsAt` exclu (DST compris — testé autour du
changement d'heure du 28/03/2027). Les horodatages (`openedAt`,
`acknowledgedAt`…) sont écrits en UTC via l'horloge injectée
(`ClockInterface`), donc figeables dans les tests.

## 8. Participation du créateur (D123)

Aucun booléen : **participer, c'est avoir une adhésion ouverte** dans le
planning. `POST /api/plannings` accepte `includeMe` (défaut `false`, comportement
antérieur) : le créateur reçoit une adhésion `OWNER` sur la ligne principale,
facteur de participation 1.0, à partir du plus tôt de aujourd'hui et du début
du planning — un membre comme un autre pour les indisponibilités, l'éligibilité,
le repos, la fairness et le solveur (aucun cas particulier dans le moteur ;
testé : il apparaît dans le snapshot comme n'importe quel membre, et pas s'il
n'est pas inclus). `OWNER` est le rôle qui rend accessibles les endpoints de
génération de sa ligne (`TEAM_MANAGE_PLANNING`) ; il est indépendant de
`Planning.creator`, qui garde seul la gestion du planning (D071).

L'UI propose la case « M'inclure dans le planning » (cochée par défaut) à la
création ; sur la page d'un planning déjà créé, un créateur non participant
dispose de « M'inclure dans ce planning » (adhésion via l'endpoint existant,
avec son propre `stableId`, désormais exposé par `/api/me`). Le planning
expose `participating` et `canManage` séparément. Un utilisateur n'a qu'une
adhésion ouverte par planning (D080) : l'option concerne donc la ligne
principale.

## 9. Autorisations (D124)

| | Créateur | OWNER/ADMIN d'une équipe | MEMBER | Extérieur |
|---|---|---|---|---|
| Lister/lire les collectes (métadonnées + sa propre réponse) | ✓ | ✓ | ✓ | 403 |
| Compteurs X/Y, `GET …/responses`, `?status=PENDING` | ✓ | ✓ | ✗ (403) | ✗ |
| Ouvrir une collecte, échéance, clôture | ✓ | ✓ | ✗ | ✗ |
| Prolonger le planning | ✓ | ✗ | ✗ | ✗ |
| Confirmer sa propre réponse | si attendu | si attendu | si attendu | 403 |

`PlanningVoter::MANAGE_AVAILABILITY` (créateur ou OWNER/ADMIN ouvert d'une
équipe du planning) est volontairement plus large que `MANAGE` : relancer les
retardataires est du travail d'équipe, pas de structure du planning. Personne ne
peut jamais confirmer pour un autre : l'utilisateur cible n'est pas un
paramètre de l'endpoint (un `userStableId` dans le corps est ignoré, testé).

## 10. Endpoints

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/plannings/{planning}/availability-collections` | historique, plus récent d'abord |
| POST | `/api/plannings/{planning}/availability-collections` | collecte explicite `{startsAt, endsAt, deadline?}` (dans le planning, sans chevauchement) |
| GET | `/api/availability-collections/{id}` | une collecte |
| PATCH | `/api/availability-collections/{id}` | `{deadline}` |
| POST | `/api/availability-collections/{id}/close` | clôture |
| GET | `/api/availability-collections/{id}/responses[?status=]` | suivi (gestionnaires) |
| POST | `/api/availability-collections/{id}/acknowledge` | sa propre confirmation |
| GET | `/api/me/availability-collections` | mes collectes ouvertes (à faire + confirmées) |
| POST | `/api/plannings/{planning}/extensions` | prolonger + ouvrir la collecte |
| GET | `/api/plannings/{planning}/assignments[?userStableId&from&to]` | planning d'une personne (§12) |

Contrôleurs minces (autorisation + codes HTTP) ; toute la règle est dans
`AvailabilityCollectionService` / `PlanningExtensionService`. Aucune ressource
API Platform générique. Des `stableId` partout.

## 11. Rappels (implémenté — Lot pilotage, docs/decisions.md D127)

`GET …/responses?status=PENDING` et
`AvailabilityCollectionService::pendingResponses()` renvoient exactement les
personnes encore attendues — l'audience d'un rappel, réutilisée telle quelle
par `AvailabilityReminderService`.

`PlanningAvailabilityReminder` (append-only, trigger Postgres refusant
`UPDATE`/`DELETE`) audite chaque envoi réellement accepté par le transport
(gabarit dédié `templates/email/availability_reminder.*.twig`, best-effort
comme les emails d'invitation, D114). Un envoi individuel
(`POST /plannings/{id}/members/{memberId}/reminders`) ou groupé
(`POST /plannings/{id}/reminders/pending`, cible uniquement les `PENDING`)
sont tous deux réservés à `PLANNING_MANAGE_AVAILABILITY` (D124, inchangé) et
protégés contre le double envoi : verrou consultatif sur la ligne `Planning`
+ une même personne jamais relancée deux fois en moins de 5 minutes. Voir
`docs/decisions.md` D127.

## 12. Planning d'une personne (D125)

Il n'existait aucune API de lecture des affectations. `GET
/api/plannings/{id}/assignments` (`VIEW` du planning) renvoie les affectations
de la **génération `COMPLETED` la plus récente de chaque ligne** (les anciennes
restent en base, jamais réécrites), triées par date, filtrables par personne
(`userStableId`, une personne du planning sinon 404) et par mois (`from`/`to`,
`to` exclu). Avec une personne, un `summary` compte ses affectations **réelles**
sur tout le planning (pas seulement le mois) avec le classement de
`DimensionMembershipCalculator` : total, charge pondérée, vendredis / samedis /
dimanches, jours de week-end (sam.+dim.), détail par type de garde. **« Nuits »
n'est pas une métrique** : aucune dimension « nuit » n'existe dans le modèle
(`FairnessDimensionType`) ; une équipe qui définit un type de garde `NIGHT` le
voit dans le détail par type. Rien n'est recalculé côté fairness (cibles,
exposition) : ce sont des comptages.

## 13. Frontend (D126)

- `MyAvailabilityProvider`, monté au-dessus des routes authentifiées, est la
  source unique du calendrier personnel et des collectes ouvertes ; le tableau
  de bord et `/my-availability` le lisent, le tableau de bord ne fait plus son
  propre `GET`.
- **Optimiste et sans « Enregistrer »** : chaque geste met l'écran à jour
  aussitôt, puis `planSync` (diff écran / serveur) produit `DELETE` /
  `PATCH` / `POST`. Un seul cycle de sauvegarde à la fois : les modifications
  faites pendant un envoi sont reprises au tour suivant (jamais de requêtes
  parallèles ni dans le désordre) ; suppressions d'abord, puis éditions
  (réductions avant agrandissements), puis créations, car le backend refuse deux
  périodes de même nature qui se touchent, même un instant. Étendre ou raccourcir
  une période est un `PATCH` (identifiant conservé), pas une suppression + création.
- **Échec** : l'écran revient à ce que le serveur détient réellement (relu, pas
  deviné) et un message explicite s'affiche ; les modifications regroupées dans
  le même cycle sont annulées ensemble. La sauvegarde survit à un changement de
  page (elle vit dans le fournisseur).
- « Tout effacer » demande un second clic (suppression massive sans annulation).
- `/my-availability?collection=<id>` ouvre le calendrier sur la fenêtre, la
  marque, et affiche les deux réponses possibles.
- Page planning : suivi des collectes (X/Y, échéance, filtre Tous / Répondu /
  À renseigner, historique), formulaire de prolongation, planning par personne
  (sélecteur, mois par mois, retour à l'équipe entière, résumé).
- **Vue de pilotage OWNER/ADMIN** (D127-D129) : synthèse « X / Y ont confirmé »
  + barre de progression, avertissement d'échéance dépassée (jamais bloquant),
  « Relancer les membres en attente » (garde anti-double-clic), tableau des
  membres (état, indisponibilités, dernier rappel) → panneau latéral (drawer)
  au clic — identité, état de confirmation, indisponibilités/préférences de
  la période, historique des rappels, bouton « Envoyer un rappel » —, modale
  Paramètres (deadline, texte explicite non bloquant) et modale de génération
  (préflight complet, avertissements, résultat par ligne). Composants dans
  `frontend/src/features/planning/pilot/`.

## 14. Limites connues

- Prolonger un planning dont une ligne est déjà validée/publiée est refusé : le
  moteur ne sait pas encore générer une tranche additionnelle à côté d'une
  période publiée. À lever avec le moteur avancé (`docs/allocation-algorithm.md`).
- Aucune notification in-app (seul le canal email existe pour les rappels,
  `ReminderChannel::EMAIL`).
- Le rollback d'une sauvegarde annule tout le cycle en cours, pas seulement le
  dernier geste fautif.
- La génération au niveau du planning (D129) répète le pipeline existant ligne
  par ligne, séquentiellement ; pas de parallélisation, pas de génération
  partielle (une ligne en échec n'empêche pas les suivantes, mais rien ne les
  relance automatiquement).
- Un membre retiré en milieu de fenêtre reste attendu pour cette fenêtre.
