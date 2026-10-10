# Intégration SurgicalHub → MedVue : synchronisation des congés

> **Statut (2026-10-10) : implémenté côté MedVue (phases B à E), non déployé.**
> Décisions harmonisées entre MedVue et SurgicalHub (§0, seule référence —
> elles remplacent toute formulation antérieure). Choix techniques :
> `docs/decisions.md` D182-D184. État réel de l'implémentation : §15.
> Audit réalisé le 2026-10-09 sur MedVue `master` (`552d9db`) et SurgicalHub
> `origin/main` (`8921383`, version de production `v2026.10.09-prod`), dans un
> worktree séparé (`../SurgicalHub-medvue-integration`, branche
> `feat/medvue-leave-integration`) : le checkout principal de SurgicalHub
> contient un chantier non commité et a 26 commits de retard sur `origin/main`.
> Il n'a pas été touché.

Règle fondatrice : **les données métier circulent uniquement de SurgicalHub
vers MedVue.** MedVue ne crée, ne modifie ni ne supprime jamais de congé
dans SurgicalHub, et n'y envoie aucune indisponibilité, préférence, garde ou
donnée de planning.

---

## 0. Décisions harmonisées (référence commune aux deux projets)

Harmonisées le 2026-10-10 et reprises à l'identique dans la
documentation de SurgicalHub (`docs/api.md`, section intégration MedVue v1).
En cas de divergence avec un autre passage, **ce tableau fait foi**. D6 et D9 ont été
reconfirmées par l'utilisateur le 2026-10-10, après la relecture (options
et impacts présentés : retrait du futur, période en cours conservée et
retirable ; conservation hors fenêtre).

| # | Sujet | Décision |
|---|---|---|
| D1 | Statuts | SurgicalHub n'a pas de statut : toute absence existante est `CONFIRMED` = indisponibilité ferme ; une absence supprimée dans SurgicalHub est retirée localement à la synchronisation complète suivante. Toute autre valeur de `status` est ignorée (§8). |
| D2 | Droits d'association | Les 4 rôles réels (`ROLE_ADMIN`, `ROLE_MANAGER`, `ROLE_SURGEON`, `ROLE_INSTRUMENTIST`) lient leur propre compte ; seul `ROLE_ADMIN` lie le compte d'autrui, avec un code généré par le compte MedVue cible (§4.3). |
| D3 | Garde-fou administrateur | Email au titulaire MedVue à chaque association (nom SurgicalHub, acteur, date) ; association visible et dissociable dans MedVue (§4.3). |
| D4 | Dissociation depuis MedVue | Appel technique `DELETE …/links/{linkId}` vers SurgicalHub (204, idempotent) ; ne touche aucune absence. Une révocation côté SurgicalHub n'appelle pas MedVue : MedVue l'apprend à la lecture suivante (`410 link_revoked`). |
| D5 | Fenêtre de synchronisation | `[aujourd'hui − 90 jours, aujourd'hui + 24 mois]` (configurable). |
| D6 | Rétention ≠ fenêtre | Une période importée n'est **jamais** supprimée automatiquement parce qu'elle sort de la fenêtre : la fenêtre dit seulement ce qui est réconcilié. Hors fenêtre, une période importée n'est plus mise à jour ; elle est conservée (aucune politique de purge pour l'instant — à décider séparément si besoin). |
| D7 | Fréquence | Cron toutes les **30 minutes** (crontab de `deploy` + `flock`, schéma existant) ; synchronisation initiale juste après l'association (premier passage du cron) et manuelle depuis MedVue. Jamais dans le worker OR-Tools. |
| D8 | Avant chaque génération | Une synchronisation est **toujours tentée** pour chaque participant associé. Échec et dernière réussite **< 24 h** (seuil configurable) : avertissement explicite, génération autorisée. Dernière réussite **≥ 24 h** ou **jamais** : **blocage** pour les personnes concernées ; dérogation possible, explicite, réservée à un rôle autorisé, tracée dans l'audit, jamais assimilée à une synchronisation réussie. Les participants non associés ne sont jamais concernés (§7.5). |
| D9 | Révocation (`410` ou « Dissocier ») | Retrait des périodes importées **futures** de cette association ; le passé et la période en cours sont conservés, et retirables par leur titulaire ; les périodes `MANUAL` ne sont jamais touchées. Un `404` ne révoque pas : il **suspend** sans rien supprimer (§9). |
| D10 | Historique | Les snapshots de génération sont immuables et ne sont jamais modifiés par une synchronisation, une sortie de fenêtre ou une révocation. |
| D11 | Sens des données | MedVue ne modifie jamais SurgicalHub : seuls appels sortants, `GET …/absences` et le `DELETE` technique de D4. |
| D12 | Dépendances | `symfony/http-client` côté MedVue ; `symfony/rate-limiter` côté SurgicalHub. |

## 1. Constats — SurgicalHub

### 1.1 Socle

- Symfony 7.4 / PHP 8.2+, Doctrine ORM 3, **MySQL 8** (serveur MySQL
  partagé du VPS : surgicalhub, medatwork, medclick), React/TypeScript.
- Authentification : JWT Lexik + refresh tokens Gesdinet, un seul firewall
  `^/api` stateless, provider `User` par email. **Aucune authentification
  machine-à-machine n'existe** (ni clé d'API, ni HMAC, ni client credentials).
- **Pas de composant `symfony/rate-limiter`** installé.
- `symfony/http-client` présent ; Messenger (transport Doctrine) + conteneur
  `surgicalhub-worker`.
- Autorisations : RBAC par Voters (règle de `CLAUDE.md`), transitions de
  statut par endpoints dédiés.
- Audit : `AuditService::recordGlobal()` (`AuditEvent`) et
  `UserAuditEvent` (acteur + utilisateur ciblé, type enum, message, payload
  JSON) — ce dernier est le bon support pour tracer une association.
- Production : VPS Docker, `/opt/stack/apps/surgicalhub`, Traefik commun,
  API publique `https://api.surgicalhub.be`, crons dans le crontab de
  `deploy` (`flock`), déploiement par `git archive` (`docs/production.md`).
  `docs/production-hostinger.md` est explicitement obsolète (archive).

### 1.2 Les congés : entité `Absence`

```
Absence { id int auto, user → User (NOT NULL), dateStart date, dateEnd date (inclus),
          reason varchar(255) NULL, createdBy → User, createdAt datetime }
```

Constats structurants — **plusieurs hypothèses de la mission ne
correspondent pas au code** :

| Hypothèse de la mission | Réalité SurgicalHub |
|---|---|
| Statuts Approuvé / En attente / Refusé / Annulé | **Aucun statut.** Une absence est effective dès sa création (elle libère immédiatement des missions, neutralise des occurrences de planning, déclenche des emails). Il n'existe ni demande, ni validation, ni refus. |
| Annulation | **Suppression physique** (`em->remove`). Aucune trace d'annulation n'est conservée côté `Absence`. |
| Date de dernière modification | **Aucun `updatedAt`.** Seulement `createdAt`. |
| Modification | En place (`PATCH`), et **découpage** : « retirer un jour » au milieu d'une période réduit l'absence existante et **crée une nouvelle absence** (nouvel `id`) pour la suite. |
| Granularité | Journée entière uniquement (pas de demi-journée), dates civiles. |
| Chevauchements | **Aucun contrôle** : deux absences d'une même personne peuvent se chevaucher. |
| Motif | `reason` est un texte libre (peut contenir un motif médical) → **jamais exporté**. |

Endpoints existants :

- `GET/POST/PATCH/DELETE /api/absences[...]` — gestionnaire
  (`PlanningVoter::PLANNING_MANAGE`), pour n'importe quel utilisateur.
- `/api/absences/mine` (`SelfAbsenceController`) — libre-service
  `ROLE_SURGEON` / `ROLE_INSTRUMENTIST` (`AbsenceVoter`) : liste, création,
  modification, suppression, retrait d'un jour.

Aucune de ces routes n'est réutilisable pour MedVue : elles exigent un JWT
utilisateur et renvoient `reason`.

### 1.3 Utilisateurs et rôles

- `User.id` entier auto-incrémenté (stable, jamais réattribué), email unique,
  `active` (suspension, pas de suppression en usage courant), `roles` JSON.
- Rôles réellement utilisés : `ROLE_ADMIN`, `ROLE_MANAGER`, `ROLE_SURGEON`,
  `ROLE_INSTRUMENTIST` (+ `ROLE_USER` implicite). **Pas de hiérarchie de
  rôles** dans `security.yaml`. Il existe un utilisateur technique
  `system@surgicalhub.internal` sans rôle.
- Adaptation de la table de droits de la mission : « USER » = `ROLE_SURGEON`
  ou `ROLE_INSTRUMENTIST`. Un compte sans aucun de ces quatre rôles
  (compte technique) ne peut rien lier.

### 1.4 Pages

- **Il n'existe pas de page « Paramètres »** : chaque espace a sa page
  « Profil » (`/app/i/profile`, `/app/s/profile`, `/app/m/profile`).
- Administration : `/app/admin/users` (`AdminUsersPage`, tiroir de fiche
  utilisateur, `UserAdministrationVoter`, ADMIN uniquement).

## 2. Constats — MedVue

### 2.1 Modèle des indisponibilités

`UserAvailabilityPeriod` (`user_availability_periods`) : `stableId` UUIDv7,
`user`, `startsAt`/`endsAt` `TIMESTAMPTZ` semi-ouverts, `type`
(`UNAVAILABLE` | `PREFER_DUTY`), `createdAt`/`updatedAt`. Pas de provenance.

Invariant de chevauchement **à double garde-fou** :

1. `UserAvailabilityService` refuse (`409`) toute période qui chevauche **ou
   touche** une période du même type (`findOverlappingOrTouchingForUser`) ;
2. contrainte SQL `excl_user_availability_periods_no_overlap`
   (`EXCLUDE USING gist (user_id =, type =, tstzrange(starts_at, ends_at, '[]') &&)`).

→ Un congé importé du 12 au 20 décembre est aujourd'hui **impossible à
enregistrer** à côté d'une indisponibilité manuelle du 10 au 15, et même à
côté d'une indisponibilité qui se termine le 11 au soir.

### 2.2 Lecteurs des indisponibilités (tous à vérifier en Phase D)

| Lecteur | Usage | Effet d'un chevauchement manuel/importé |
|---|---|---|
| `PlanningSnapshotService` | copie chaque période en `PlanningSnapshotAvailabilityPeriod` à la création du snapshot | correct (copie ligne à ligne) |
| `EligibilityService` | exclusion `UNAVAILABLE` par période qui recouvre une garde | correct, mais **deux causes identiques** pour une même garde → à dédoublonner dans l'explication |
| `SnapshotHasher` | hash des périodes du snapshot (tri par `sourceAvailabilityStableId`) | correct |
| `ReassignmentCandidateService` (remplacement, échanges) | calendrier live | correct |
| `AvailabilityCollectionService` | `recordAvailabilityChange()` (`lastAvailabilityChangeAt`), refus d'une confirmation « aucune indisponibilité » si des périodes existent | correct (test > 0) |
| `PlanningCollectionStatusService` / `MemberAvailabilityDetail` | **compte** et liste des indisponibilités par membre | **double comptage** → à corriger (jours distincts ou liste avec provenance) |
| `PlanningAbsenceExportDataBuilder` / `AbsenceDays` | jours distincts | correct (déjà dédoublonné, D181) |
| Frontend `/my-availability` (`periodMapping.planSync`) | autosave par **diff** entre l'écran et toutes les périodes stockées | **dangereux** : sans changement, le diff tenterait de modifier / supprimer les périodes importées |

### 2.3 Collectes

`AvailabilityCollectionResponse` distingue déjà `acknowledgedAt` (réponse
explicite) et `lastAvailabilityChangeAt` (information de workflow, D120).
L'import n'a qu'à passer par `recordAvailabilityChange()` ; il ne touche
jamais `acknowledge()`.

### 2.4 Génération

Préflight (`PlanningGenerationLauncher::preflight`, blockers + warnings),
lancement synchrone qui crée le `PlanningJob`, résolution dans le worker ;
le snapshot est pris au lancement (D129). C'est au lancement que la
fraîcheur des congés doit être vérifiée.

### 2.5 Exploitation

- Production : `api.medvue.be`, conteneurs `medvue-backend` / `medvue-worker`
  / `medvue-database`, PostgreSQL privé (injoignable depuis SurgicalHub,
  vérifié à chaque déploiement).
- Planification existante : crontab de `deploy` + `flock` + `docker compose
  exec -T backend php bin/console …` (§5 bis à 5 sexies). Worker Messenger
  dédié aux calculs de planning (un seul transport, `planning_jobs`).
- Rate limiter Symfony déjà utilisé (inscription, invitations, mot de passe).

### 2.6 Divergences documentation / code relevées

- `docs/availability.md` §6 « pas de rôle global » : vrai pour les équipes,
  mais `ROLE_PLATFORM_ADMIN` existe depuis D174 (sans droit sur les
  calendriers — pas d'impact ici).
- `docs/availability.md` §9 et en-tête : « pas encore `EligibilityService` /
  snapshot » — obsolète (livrés depuis).
- `docs/availability.md` §8 décrit encore le bouton « Enregistrer »,
  supprimé par D126 (le §10 le précise).
- SurgicalHub : `docs/api.md` §26.3 décrit `GET/POST/DELETE /api/absences`
  mais pas `PATCH` ni `remove-day`.

## 3. Architecture retenue

```
             (1) code d'association (vérification technique)
SurgicalHub ─────────────────────────────────────────────────► MedVue
   POST https://api.medvue.be/api/integrations/surgicalhub/v1/link-codes/redeem

             (2) lecture des congés (seul flux de données métier)
MedVue ──── GET https://api.surgicalhub.be/api/integrations/medvue/v1/... ──► SurgicalHub
        ◄──────────────────── congés (id, dates, statut) ────────────────────
```

- Deux appels HTTPS par les domaines publics (Traefik), **deux secrets
  distincts**, un par sens. Aucun accès SQL, réseau Docker, volume ou
  secret utilisateur partagé.
- MedVue initie toute synchronisation. SurgicalHub ne pousse rien.
- Le seul appel SurgicalHub → MedVue échange un code contre un identifiant
  d'association ; il ne renvoie aucune donnée de MedVue.
- Un second appel technique MedVue → SurgicalHub (D4) : `DELETE
  .../links/{linkId}` quand l'utilisateur dissocie depuis MedVue, pour que
  SurgicalHub n'affiche pas une association morte. Il ne touche aucun congé.

### 3.1 Authentification machine-à-machine

- Secret aléatoire de 256 bits par sens (`MEDVUE_INTEGRATION_TOKEN` côté
  SurgicalHub pour appeler MedVue et inversement), en `.env` de production,
  jamais dans Git. Le serveur appelé ne stocke que son **empreinte SHA-256**
  et compare avec `hash_equals`.
- SurgicalHub : nouveau firewall `^/api/integrations/medvue` placé **avant**
  `^/api`, stateless, authenticator dédié produisant un utilisateur
  technique `ROLE_MEDVUE_INTEGRATION` sans `User` Doctrine ; ce rôle n'a
  accès qu'à ces routes (access_control + Voter), et ces routes n'ont que
  des `GET`, plus le `DELETE` de l'association (D4).
- MedVue : même principe sur `^/api/integrations/surgicalhub`.
- Rotation : accepter temporairement deux empreintes (ancienne + nouvelle).

## 4. Association des comptes

### 4.1 Parcours

1. MedVue, « Mon compte » → « SurgicalHub » → **Générer un code**.
   Code de 12 caractères base32 sans ambiguïté (60 bits), affiché
   `XXXX-XXXX-XXXX`, valable **10 minutes**, un seul code actif par compte
   (en générer un invalide le précédent).
2. SurgicalHub, Profil → **Intégration MedVue** → saisie du code.
   Ou : administrateur → fiche utilisateur → **Intégration MedVue**.
3. SurgicalHub vérifie le droit (Voter), le rate limit, puis appelle
   `redeem` avec son identifiant d'utilisateur cible.
4. MedVue, dans une transaction avec verrou sur la ligne du code :
   code connu, non expiré, non consommé → consommé, association créée
   (ou remplacée si c'est la même paire), réponse `linkId`.
5. SurgicalHub enregistre `linkId` sur l'utilisateur cible, audit
   `UserAuditEvent`.
6. MedVue notifie le titulaire par email (voir D3) et synchronise dans les
   30 minutes (cron, D7) ou immédiatement sur « Synchroniser ».

Si SurgicalHub échoue entre 4 et 5, MedVue a une association que
SurgicalHub ignore : la lecture renvoie `404 link_not_found`, MedVue
l'affiche (« Association à refaire ») ; un nouveau code pour la même paire
remplace l'ancienne association.

### 4.2 Propriétés du code

Aléatoire `random_bytes`, stocké **haché** (SHA-256, comme
`PasswordResetToken`), usage unique, expiration 10 min, jamais journalisé
(ni dans les logs, ni dans l'audit, ni dans Sentry — le champ est exclu des
contextes), ne contient aucune donnée personnelle, n'est ni un JWT ni lié à
une session.

Anti-force brute, en trois couches :

- SurgicalHub : 5 essais / 15 min par acteur et 20 / 15 min par utilisateur
  cible (ajout de `symfony/rate-limiter` 7.4 — nouvelle dépendance).
- MedVue : limite globale sur `redeem` (ex. 60/min) ; réponse **identique**
  pour un code inconnu, expiré ou consommé (`invalid_code`).
- Entropie : 60 bits pour 10 minutes de validité rendent l'énumération
  irréaliste même sans limite.

### 4.3 Droits (adaptés aux rôles réels)

| Rôle SurgicalHub | Lier son compte | Lier le compte d'autrui | Dissocier |
|---|---|---|---|
| `ROLE_ADMIN` | oui | oui (fiche utilisateur) | soi + autrui |
| `ROLE_MANAGER` | oui | **non** | soi |
| `ROLE_SURGEON` / `ROLE_INSTRUMENTIST` | oui | non | soi |
| aucun de ces rôles | non | non | — |

Côté MedVue, le titulaire voit l'association et peut **dissocier** à tout
moment.

Garde-fou administrateur (D3) : le code prouve que le titulaire MedVue a
consenti à une association, pas que le compte SurgicalHub choisi par
l'administrateur est le bon. MedVue envoie donc un email au titulaire à
chaque association (« associé au compte SurgicalHub de <nom SurgicalHub>,
par <acteur>, le … » — données envoyées par SurgicalHub, sens autorisé), et
l'association est visible et dissociable dans MedVue. Les emails des deux
comptes ne sont pas comparés (ils peuvent légitimement différer).

### 4.4 Unicité

- MedVue : une association **courante** (`ACTIVE` ou `SUSPENDED`) par
  `User` MedVue et une par identifiant SurgicalHub (index uniques partiels).
  Un code redeemé pour un compte SurgicalHub déjà associé à un **autre**
  compte MedVue → `409 already_linked` (jamais de remplacement silencieux) ;
  un compte MedVue dont l'association courante vise un autre compte
  SurgicalHub → `409 medvue_account_already_linked`.
- SurgicalHub : une association active par `User`.
- Remplacement : dissocier puis associer, ou nouveau code pour la même paire
  (confirme l'association, ou la reprend si elle était suspendue).

### 4.5 Traçabilité

- SurgicalHub : `UserAuditEvent` `MEDVUE_LINKED` / `MEDVUE_UNLINKED` /
  `MEDVUE_LINK_FAILED` (acteur, utilisateur cible, date, résultat), sans code.
- MedVue : table append-only `surgical_hub_link_events` (opération, résultat,
  utilisateur, côté initiateur, date) + journal d'audit plateforme existant
  (D176) si pertinent.

## 5. Contrat API

### 5.1 SurgicalHub → MedVue : échange du code

```
POST https://api.medvue.be/api/integrations/surgicalhub/v1/link-codes/redeem
Authorization: Bearer <secret SurgicalHub→MedVue>
Content-Type: application/json

{ "code": "K7QM-2XPA-9DRT",
  "surgicalHubUserId": "4812",
  "surgicalHubDisplayName": "Dr Jeanne Martin",
  "actorDisplayName": "Admin X",
  "actorIsAdministrator": true }
```

Corps plat ; tout champ inconnu est refusé. Le code est normalisé des deux
côtés (casse, espaces, tirets, O→0, I/L→1).

| Réponse | Sens |
|---|---|
| `200 {"linkId": "<uuid>", "linkedAt": "<ATOM UTC>"}` | association créée ; la même paire qui échange un nouveau code reçoit le **même** `linkId` (association confirmée, pas recréée) |
| `422 {"error": "invalid_code"}` | inconnu, expiré, remplacé, déjà consommé ou compte MedVue désactivé (indistinguables) |
| `422 {"error": "validation_failed", "violations"}` | payload invalide (le code n'est jamais renvoyé) |
| `409 {"error": "already_linked"}` | ce compte SurgicalHub est associé à un autre compte MedVue ; le code **n'est pas consommé** (l'administrateur peut corriger la cible) |
| `409 {"error": "medvue_account_already_linked"}` | le compte MedVue est associé à un autre compte SurgicalHub ; il doit d'abord être dissocié dans MedVue |
| `401 {"error": "unauthorized"}` | secret absent ou invalide (un JWT MedVue n'est pas accepté) |
| `429` + `Retry-After` | 60 / minute par IP appelante |

Aucune donnée MedVue (nom, email, plannings) dans la réponse. SurgicalHub
traite toute autre réponse ou un délai dépassé (10 s) comme un échec
technique : aucune association enregistrée.

### 5.2 MedVue → SurgicalHub : lecture des congés

```
GET https://api.surgicalhub.be/api/integrations/medvue/v1/links/{linkId}/absences?from=2026-09-08&to=2028-04-08
Authorization: Bearer <secret MedVue→SurgicalHub>
```

- `from`/`to` : dates `YYYY-MM-DD` obligatoires, `to - from ≤ 850 jours`
  (la fenêtre D5 fait environ 821 jours) ; sinon `400 {"error": "invalid_window"}`.
- Renvoie **toutes** les absences du titulaire qui **intersectent**
  `[from, to]`, non tronquées, triées par `id`.
- **Pas de pagination** : la synchronisation supprime les congés disparus,
  elle a donc besoin d'un instantané complet et cohérent (une lecture
  unique dans une transaction). Plafond de 1 000 absences : au-delà,
  `422 window_too_large` — jamais une réponse tronquée.

```json
{
  "apiVersion": 1,
  "linkId": "0199c5…",
  "window": { "from": "2026-09-08", "to": "2028-04-08" },
  "generatedAt": "2026-10-09T14:02:11+02:00",
  "complete": true,
  "absences": [
    { "id": "8120", "startDate": "2026-12-12", "endDate": "2026-12-20",
      "status": "CONFIRMED", "updatedAt": null }
  ]
}
```

- `endDate` **inclusive** (convention SurgicalHub).
- `status` : seule valeur produite aujourd'hui `CONFIRMED` (voir D1).
  MedVue **ignore** toute autre valeur future (jamais traitée comme
  confirmée par défaut).
- `updatedAt` : `null` (SurgicalHub n'a pas cette donnée) ; MedVue compare
  les dates, pas un horodatage.
- Exclus : `reason`, `createdBy`, sites, missions, tout le reste.

| Réponse | Sens côté MedVue |
|---|---|
| `200` | instantané complet → réconciliation |
| `404 {"apiVersion": 1, "error": "link_not_found"}` | `linkId` inconnu de SurgicalHub (jamais enregistré, ou sauvegarde restaurée) → association MedVue **`SUSPENDED`**, rien n'est supprimé (§9.2) |
| `410 {"apiVersion": 1, "error": "link_revoked"}` | association révoquée dans SurgicalHub → `REVOKED_REMOTE`, D9 appliquée (§9.1) |
| tout autre `404`/`410` (page HTML de Traefik ou nginx, corps différent), `400`, `401`, `403`, `422`, `429`, `5xx`, délai dépassé, JSON invalide | **échec : aucune écriture locale, aucune révocation** |

La suspension et la révocation ne se déclenchent **que** sur un corps JSON
exact portant `apiVersion = 1` et l'un des deux codes ci-dessus : une route
mal configurée ou une maintenance ne doit jamais effacer de données. Seul
`410` retire des imports. Côté SurgicalHub, un même `linkId` peut avoir plusieurs
lignes historiques (révocation puis nouvelle association de la même paire)
mais au plus une active : `200` s'il en existe une active, `410` si
seulement des révoquées, `404` s'il n'a jamais été connu.

### 5.3 MedVue → SurgicalHub : dissociation (D4)

`DELETE https://api.surgicalhub.be/api/integrations/medvue/v1/links/{linkId}` →
`204`, idempotent (aussi si déjà révoquée) ; `404 link_not_found` si jamais
connu. Ne touche aucune absence. Appelé après la révocation locale, au
mieux (un échec n'annule pas la révocation MedVue : SurgicalHub n'est plus
lu de toute façon).

### 5.4 Configuration

| Côté | Variable | Contenu |
|---|---|---|
| MedVue | `SURGICALHUB_INBOUND_TOKEN_SHA256` | empreinte(s) SHA-256 du secret SurgicalHub → MedVue, séparées par des virgules (rotation) ; vide = intégration fermée |
| MedVue | `SURGICALHUB_API_BASE_URL`, `SURGICALHUB_API_TOKEN` | URL de l'API SurgicalHub et secret MedVue → SurgicalHub (en clair, `.env` de production seulement) |
| MedVue | `SURGICALHUB_SYNC_PAST_DAYS` (90), `SURGICALHUB_SYNC_FUTURE_MONTHS` (24) | fenêtre réconciliée (D5) |
| MedVue | `SURGICALHUB_FRESHNESS_HOURS` (24), `SURGICALHUB_LAUNCH_TIMEOUT_SECONDS` (4), `SURGICALHUB_LAUNCH_BUDGET_SECONDS` (20) | contrôle avant génération (D8) |
| MedVue | `SURGICALHUB_LINK_CODE_TTL` (600) | validité d'un code d'association, en secondes |
| SurgicalHub | `MEDVUE_INBOUND_TOKEN_SHA256` | empreinte(s) du secret MedVue → SurgicalHub |
| SurgicalHub | `MEDVUE_API_BASE_URL`, `MEDVUE_REDEEM_TOKEN` | URL de l'API MedVue et secret SurgicalHub → MedVue |

## 6. Modèle de données

### 6.1 MedVue (PostgreSQL)

1. `user_availability_periods.source` `VARCHAR(20) NOT NULL DEFAULT 'MANUAL'`
   (`MANUAL` | `SURGICAL_HUB`). Une colonne sur la période plutôt qu'une
   simple table de correspondance : tous les lecteurs (API, UI, contrainte
   SQL, snapshot) ont besoin de la provenance sans jointure.
2. Contrainte d'exclusion recréée **partielle** :
   `EXCLUDE USING gist (user_id WITH =, type WITH =, tstzrange(starts_at, ends_at, '[]') WITH &&) WHERE (source = 'MANUAL')`.
   L'invariant actuel reste intégral entre périodes manuelles ; les
   périodes importées peuvent chevaucher les manuelles et entre elles
   (SurgicalHub autorise les chevauchements). Les lignes existantes sont
   toutes `MANUAL` et satisfont déjà la contrainte.
3. `surgical_hub_links` : `id` uuid, `user_id`, `surgical_hub_user_id`,
   `status` (`ACTIVE` | `SUSPENDED` | `REVOKED_LOCAL` | `REVOKED_REMOTE`),
   `linked_at`, `revoked_at`, `suspended_at`, `last_sync_attempt_at`,
   `last_successful_sync_at`, `last_sync_error` (code court, jamais un
   message technique) ; verrou de ligne (`SELECT … FOR UPDATE`) pour
   sérialiser synchronisation, suspension et révocation ; uniques partiels
   sur `user_id` et `surgical_hub_user_id`
   `WHERE status IN ('ACTIVE', 'SUSPENDED')`.
4. `surgical_hub_link_codes` : `user_id`, `code_hash` unique, `expires_at`,
   `consumed_at`, `created_at`.
5. `surgical_hub_imported_leaves` (correspondance) : `link_id`,
   `external_absence_id`, `availability_period_id` (unique, FK `ON DELETE CASCADE`),
   `start_date`, `end_date`, `first_imported_at`, `last_changed_at` ;
   unique `(link_id, external_absence_id)` → **aucun doublon possible**.
6. `surgical_hub_link_events` : journal append-only.
7. `planning_snapshot_availability_periods.source` (copie de la provenance,
   pour l'explication « Indisponible (SurgicalHub) »). **Non inclus dans
   `snapshotHash`** : la provenance ne change pas le problème résolu.

### 6.2 SurgicalHub (MySQL)

1. `medvue_account_link` : `id`, `user_id`, `medvue_link_id` (uuid, unique),
   `linked_by_id`, `linked_at`, `revoked_at`, `revoked_by_id` (NULL si
   révoquée par MedVue), unique partiel émulé par une colonne générée
   (`active_user_id` = `user_id` si non révoquée) car MySQL n'a pas d'index
   partiel.
2. Nouvelles valeurs de `UserAuditEventType`.
3. **Aucune modification de `Absence`** ni des flux d'absence existants.

## 7. Synchronisation

### 7.1 Fenêtre

`[aujourd'hui − 90 jours, aujourd'hui + 24 mois]` (D5, `SURGICALHUB_SYNC_PAST_DAYS`
/ `SURGICALHUB_SYNC_FUTURE_MONTHS`). La fenêtre délimite **ce qui est
réconcilié**, pas ce qui est conservé (D6) :

- une période importée qui intersecte la fenêtre est créée, mise à jour ou
  retirée selon la réponse complète de SurgicalHub ;
- une période importée entièrement hors fenêtre n'est plus lue ni modifiée,
  et **n'est jamais supprimée pour cette seule raison** : elle reste dans le
  calendrier telle qu'elle a été importée en dernier. Aucune purge n'est
  prévue ; une éventuelle politique de rétention sera une décision distincte.

### 7.2 Conversion

Une absence `[startDate, endDate]` (dates incluses) devient
`[startDate 00:00, endDate+1 00:00[` en **`Europe/Brussels`**
(`SURGICALHUB_TIMEZONE`), soit une période journée entière, cohérente avec
le calendrier personnel (D119) et l'export d'absences.

### 7.3 Algorithme (`SurgicalHubLeaveSyncService`)

1. Lecture HTTP (délai 10 s), **validation intégrale** avant toute écriture :
   `apiVersion`, `linkId`, `complete === true`, fenêtre identique à la
   demande, ids uniques, dates valides, `endDate ≥ startDate`, chaque
   absence intersecte la fenêtre. Une anomalie = échec, rien n'est écrit.
2. Transaction unique, verrou `FOR UPDATE` sur l'association (deux
   synchronisations concurrentes sont sérialisées) :
   - ignorées : statut ≠ `CONFIRMED` ;
   - **nouvelle** (id inconnu) → `UserAvailabilityService::importCreate()` ;
   - **modifiée** (dates différentes) → `importReschedule()` ;
   - **identique** → aucune écriture (pas même `updatedAt`) ;
   - **disparue** : uniquement si la période connue intersecte la fenêtre
     et que la réponse est complète et validée → `importDelete()` ;
   - mise à jour de `last_successful_sync_at`.
3. Les méthodes `import*` vivent dans `UserAvailabilityService` (pas de
   second moteur) : elles posent `source = SURGICAL_HUB`, sautent le
   contrôle de chevauchement réservé aux périodes manuelles, et appellent
   `AvailabilityCollectionService::recordAvailabilityChange()` dans la même
   transaction, exactement comme une saisie manuelle.
4. **Jamais** une période `MANUAL` n'est lue pour être modifiée ou
   supprimée par la synchronisation (requêtes filtrées sur la table de
   correspondance de l'association).
5. Échec réseau / authentification / validation : transaction jamais
   ouverte, `last_sync_error` renseigné, données locales intactes. La
   synchronisation suivante reprend de zéro (idempotente).

### 7.4 Déclencheurs

| Déclencheur | Mécanisme |
|---|---|
| Après association | premier passage du cron (≤ 30 min), les associations jamais synchronisées en premier ; ou immédiatement par « Synchroniser » |
| Périodique | cron `*/30` (`app:surgicalhub:sync`, `flock`, crontab de `deploy`, comme `docs/deployment.md` §5 quater) : toutes les associations actives (D7) |
| Manuel | « Synchroniser SurgicalHub » sur `/my-availability` (synchrone, 10/h par compte) |
| Avant génération | §7.5 |

Pas de nouveau conteneur, pas de nouveau transport Messenger : le worker
actuel est occupé par des résolutions longues, une synchronisation y
attendrait derrière un calcul.

### 7.5 Fraîcheur avant génération

Au lancement (`PlanningGenerationLauncher::launch`, avant la création du
snapshot) : une synchronisation est **toujours tentée** pour chaque
participant **associé** (D8). Les participants non associés ne sont jamais
concernés. Pour ceux dont la tentative échoue, selon
`lastSuccessfulSyncAt` et le seuil `SURGICALHUB_FRESHNESS_HOURS` (24) :

| Dernière réussite | Effet |
|---|---|
| < 24 h | avertissement explicite (`SURGICALHUB_SYNC_FAILED_RECENT_DATA`) dans le résultat du lancement, génération autorisée |
| ≥ 24 h, ou jamais | **blocage** `409 surgicalhub_data_stale`, liste des personnes et de leur dernière réussite |

Durée bornée : chaque appel au lancement a un délai de 4 s
(`SURGICALHUB_LAUNCH_TIMEOUT_SECONDS`, contre 10 s pour la synchronisation
périodique) ; dès la première panne de SurgicalHub (toute erreur autre
qu'une réponse invalide pour une seule personne) ou une fois le budget de
20 s (`SURGICALHUB_LAUNCH_BUDGET_SECONDS`) dépassé, les participants
restants ne sont plus appelés et sont classés selon leur dernière
synchronisation réussie, comme un échec. Un participant dont l'association
est **suspendue** n'est jamais appelé : même classement, erreur
`link_suspended`. La limite est stricte : à exactement 24 h, c'est un
blocage. Une relance sans dérogation explicite reste bloquée.

Dérogation au blocage : relance avec `overrideStaleSurgicalHubData: true`,
uniquement par le **créateur du planning** (rôle le plus élevé d'un
planning ; un « Gestionnaire » ne peut pas déroger), consignée dans le
journal append-only (personnes concernées, dernière réussite, auteur,
date) et rattachée à la génération. Elle n'est jamais enregistrée comme une
synchronisation réussie (`lastSuccessfulSyncAt` inchangé). Le préflight
affiche l'état (associés, dernière réussite) avant le lancement.

## 8. Statuts (D1)

SurgicalHub n'ayant aucun statut, la proposition est :

| Cible de la mission | Traitement proposé |
|---|---|
| Approuvé | toute absence existante = `CONFIRMED` = indisponibilité ferme (c'est déjà sa portée dans SurgicalHub : elle libère des missions) |
| En attente / Refusé | n'existent pas ; le contrat est prêt (`status` ignoré s'il est différent de `CONFIRMED`) |
| Annulé | = absence supprimée → retrait local à la synchronisation suivante complète |

Introduire un circuit de validation dans SurgicalHub serait un chantier
métier distinct (il changerait le comportement des missions et des emails)
et n'est pas proposé ici.

## 9. Révocation, suspension et congés conservés

> D9 (portée du retrait) et D6 (rétention hors fenêtre) reconfirmées par
> l'utilisateur le 2026-10-10 après présentation des options.

### 9.1 Révocation (`410 link_revoked`, ou « Dissocier » dans MedVue)

Dans la même transaction que le changement de statut :

- les périodes importées de **cette** paire de comptes qui **commencent
  aujourd'hui ou plus tard** (jour civil `Europe/Brussels`) sont retirées,
  via `UserAvailabilityService` (donc `lastAvailabilityChangeAt` des
  collectes ouvertes mis à jour) ;
- les périodes passées, **et la période en cours** (commencée avant
  aujourd'hui), sont conservées entières — jamais tronquées ;
- les périodes `MANUAL` ne sont jamais touchées ;
- les snapshots déjà pris gardent leurs copies (D10).

Une période conservée n'est plus tenue à jour par aucune association : elle
reste non modifiable, mais **son titulaire peut la retirer** (« Retirer »
dans la liste « Congés SurgicalHub » ; l'API expose `deletable`). Sans cela,
un congé en cours révoqué puis annulé dans SurgicalHub resterait bloquant
jusqu'à sa fin (relecture du 2026-10-10). Une nouvelle association de la
même paire reprend ces périodes à la synchronisation suivante.

### 9.2 Suspension (`404 link_not_found`)

`404` dit que SurgicalHub n'a **jamais connu** cette association — ce
qu'il répondrait pour toutes les associations après la restauration d'une
sauvegarde antérieure. Ce n'est donc **pas** une révocation : l'association
passe en `SUSPENDED`, **rien n'est supprimé**, plus aucune lecture n'est
faite, le titulaire est prévenu (« Mes indisponibilités », « Mon compte »),
et ses congés importés restent en lecture seule (non retirables : ils
restent rattachés à une association non terminée). Une association
suspendue occupe toujours les deux comptes. Elle n'en sort que par le
titulaire :

- un **nouveau code** pour la même paire la **reprend** (même `linkId`,
  imports intacts, événement `LINK_RESUMED`) ;
- « **Dissocier** » la révoque (§9.1).

Avant une génération, un participant suspendu n'est jamais appelé : il est
classé selon sa dernière synchronisation réussie (avertissement < 24 h,
blocage au-delà — D8).

**Garde-fou global** (`app:surgicalhub:sync`) : au deuxième `404` d'une même
passe, la passe s'arrête, alerte (« possibly a restored backup ») et sort en
erreur ; tant que deux associations ou plus sont suspendues, les passes
suivantes n'appellent plus SurgicalHub et continuent d'alerter, jusqu'à
vérification. Une vraie dissociation répond `410`, jamais `404`.

## 10. Interface

### MedVue

- `/my-availability` : les jours importés sont affichés en indisponibilité
  avec un marquage discret « SurgicalHub » (hachure + infobulle « Congé
  SurgicalHub du … au … »), **non éditables**. `planSync` ne travaille que
  sur les périodes `MANUAL` ; tracer une préférence de garde sur un jour
  importé est refusé (le signal dur domine). Une indisponibilité manuelle
  peut chevaucher un congé importé.
- En tête : « SurgicalHub · dernière synchronisation le … » +
  « Synchroniser SurgicalHub » si associé ; erreurs en clair (« SurgicalHub
  est momentanément injoignable — vos congés déjà importés restent
  pris en compte »).
- « Mon compte » : section SurgicalHub (générer un code, état,
  dissocier).
- API : `GET /api/me/calendar` expose `source` et `editable` ;
  `PATCH`/`DELETE` d'une période importée → `409 imported_period_read_only`.
- Suivi de collecte (gestionnaire) : provenance visible, comptage en jours
  distincts.

### SurgicalHub

- Section « Intégration MedVue » dans les trois pages Profil (saisie du
  code, état, dissocier) et dans la fiche utilisateur de l'administration.

## 11. Tests prévus

Tous les cas de la §10 de la mission, notamment :

- **SurgicalHub** : Voter (soi / autrui × 4 rôles + sans rôle), firewall
  d'intégration (sans secret, mauvais secret, JWT utilisateur refusé sur
  les routes d'intégration, secret d'intégration refusé sur toute autre
  route), contrat de lecture (pas de `reason`, fenêtre, plafond, absence
  d'un autre utilisateur jamais renvoyée, association révoquée → 410, jamais connue → 404),
  rate limit, audit sans code.
- **MedVue** : code (expiré, consommé, remplacé, haché, jamais loggé), course
  de deux redeem simultanés, unicités, synchronisation (création,
  modification, découpage SurgicalHub, suppression, idempotence, aucune
  écriture si identique, erreur réseau / 401 / JSON invalide /
  `complete: false` / fenêtre incohérente → zéro écriture, période
  manuelle jamais touchée, période hors fenêtre jamais supprimée (D6), 404/410 non conformes sans effet, révocation : futur retiré et passé/en cours conservés (D9)), contrainte SQL
  partielle contournée volontairement (manuel/manuel refusé,
  manuel/importé et importé/importé acceptés), snapshot (importé copié avec
  provenance, hash inchangé pour des données manuelles identiques),
  éligibilité (exclusion unique malgré deux périodes), collecte
  (`lastAvailabilityChangeAt` mis à jour, jamais d'acquittement),
  génération (sync toujours tentée, échec < 24 h = avertissement, ≥ 24 h ou jamais = blocage, dérogation réservée au créateur et tracée, non associés
  non bloqués), absence de toute route MedVue écrivant vers SurgicalHub
  (test d'inventaire des appels HTTP sortants : uniquement `GET` + le
  `DELETE` d'association, D4).
- **Frontend** (Vitest, deux applications) : affichage et non-édition des
  jours importés, `planSync` ignorant les périodes importées, états et
  erreurs de synchronisation, saisie du code et droits.
- **Intégration** : les deux piles de développement côte à côte
  (SurgicalHub joint par MedVue via son URL de dev), parcours complet.

## 12. Migrations

- MedVue : **2 migrations** — `Version20261010090000` (tables
  d'association, codes, journal append-only) et `Version20261010120000`
  (colonne `source`, contrainte d'exclusion partielle, table de
  correspondance, `provenance` du snapshot). Seule instruction non
  additive : le `DROP CONSTRAINT` / `ADD CONSTRAINT` de l'exclusion, dans la
  même transaction (DDL transactionnel PostgreSQL). Le `down` de la seconde
  supprime d'abord les périodes importées (la contrainte restaurée lie de
  nouveau toutes les lignes).
- SurgicalHub : 1 migration additive (`medvue_account_link`).
- **Fait** : montée et descente des deux migrations MedVue sur les bases de
  développement et de test (données de développement).
- **Fait le 2026-10-10, avec l'accord de l'utilisateur** : montée puis
  descente des deux migrations MedVue sur une restauration jetable du dump
  de production `medvue_20261010_034501.dump` (sha256 vérifié), sur le
  serveur, dans un conteneur PostgreSQL sans réseau et en mémoire, supprimé
  ensuite — la base en service n'a été que lue (deux variables
  d'environnement), production restée en `Version20261008200000`. SQL
  rejoué : celui des classes de migration, une transaction par migration
  comme Doctrine. Résultats :
  - avant : 197 périodes de calendrier, 564 périodes de snapshot, 5
    contraintes d'exclusion ;
  - montée : les deux migrations passent ; 197/197 périodes `MANUAL`,
    564/564 périodes de snapshot `MANUAL` ; contrainte devenue
    `… WHERE (source = 'MANUAL')` ; 4 nouvelles tables vides ;
  - sur des lignes réelles, dans une transaction annulée : une période
    importée chevauchant une période manuelle est acceptée ; une seconde
    période manuelle sur les mêmes dates est refusée
    (`exclusion_violation`) ;
  - descente (ordre inverse) : tables, nombres exacts de lignes,
    contraintes et index **identiques** à l'avant ; schéma
    (`pg_dump --schema-only`) identique hormis le jeton aléatoire
    `
estrict` que `pg_dump` 16 écrit à chaque export ; contrainte
    d'origine restaurée.

## 13. Risques

| Risque | Mitigation |
|---|---|
| Joignabilité HTTPS conteneur → domaine public sur le même VPS (hairpin Traefik) | à vérifier en lecture seule avant la Phase C (`curl` depuis `medvue-backend` vers `api.surgicalhub.be/api/health` ou équivalent, et inversement) |
| Mauvaise personne liée par un administrateur | D3 |
| Suppression massive sur réponse partielle | réponse sans pagination, `complete`, validation intégrale, suppression limitée à la fenêtre |
| Absence découpée dans SurgicalHub | gérée comme modification + création (ids) |
| Fuseau horaire (dates SurgicalHub → instants MedVue) | fuseau explicite configuré, test DST |
| Désactivation d'un compte SurgicalHub | l'association reste ; les congés restent servis (décision à confirmer si besoin) |
| Secret compromis | rotation à deux empreintes, révocation immédiate en retirant l'empreinte |
| Checkout SurgicalHub principal non propre | tout le travail dans le worktree dédié |

## 14. Déploiement et retour arrière (prévision)

Ordre : SurgicalHub d'abord (association + API, inertes tant que MedVue ne
les appelle pas), puis MedVue. Secrets créés sur le serveur, jamais dans
Git. Cron MedVue installé en dernier, après une synchronisation manuelle
d'un compte de test. Retour arrière : retirer le cron, restaurer le code
précédent de MedVue (la colonne `source` et les tables ajoutées sont
ignorées par l'ancien code ; la contrainte partielle reste compatible car
l'ancien code n'écrit que des périodes `MANUAL`) ; côté SurgicalHub,
retirer le secret désactive l'API. **Aucun déploiement sans validation
explicite.**

## 15. Implémentation MedVue (état au 2026-10-10)

### Endpoints

| Endpoint | Accès | Rôle |
|---|---|---|
| `GET /api/me/surgicalhub` | titulaire (JWT) | `{available, link}` : état de la dernière association |
| `POST /api/me/surgicalhub/link-code` | titulaire | `201 {code, expiresAt}`, `Cache-Control: no-store`, 10/h |
| `DELETE /api/me/surgicalhub/link` | titulaire | `204` ; D9 puis `DELETE` technique vers SurgicalHub ; `404 not_linked` |
| `POST /api/me/surgicalhub/sync` | titulaire | `{outcome:{status,error,created,updated,removed}, link}`, 10/h |
| `POST /api/integrations/surgicalhub/v1/link-codes/redeem` | SurgicalHub (secret) | §5.1 |
| `GET /api/me/calendar` | titulaire | + `source`, `editable` |
| `PATCH`/`DELETE /api/me/calendar/{id}` | titulaire | `409 imported_period_read_only` sur un congé importé |
| `GET /api/plannings/{id}/generation-preflight` | gestionnaires | + `surgicalHub` (associés, dernière reprise), sans appel réseau |
| `POST /api/plannings/{id}/generations` | gestionnaires | D8 : `202` + `surgicalHub {warnings, overridden}`, `409 surgicalhub_data_stale`, `403 surgicalhub_override_forbidden` ; corps `overrideStaleSurgicalHubData: true` (créateur seul) |
| `GET /api/planning-generations/{id}/snapshot` | — | + `provenance` par période |

Commande : `app:surgicalhub:sync` (cron 30 min, `docs/deployment.md` §5 septies).

### Code

- Domaine : `SurgicalHubLink` / `SurgicalHubLinkCode` / `SurgicalHubLinkEvent`
  (append-only) / `SurgicalHubImportedLeave`, `UserAvailabilitySource`,
  `UserAvailabilityPeriod::$source`, `PlanningSnapshotAvailabilityPeriod::$provenance`.
- Services (`src/Service/SurgicalHub/`) : `SurgicalHubLinkService`
  (codes, échange, révocations + D9), `SurgicalHubApiClient` (seuls appels
  sortants, validation intégrale), `SurgicalHubLeaveSyncService`
  (réconciliation), `SurgicalHubFreshnessGate` (D8), `SurgicalHubCalendar`
  (fenêtre, dates → instants `Europe/Brussels`), `SurgicalHubLinkMailer`.
- `UserAvailabilityService::import*()` ; contrôle de chevauchement limité
  aux périodes manuelles.
- Sécurité : `SurgicalHubIntegrationAuthenticator` + `IntegrationClient`,
  firewall `surgicalhub_integration`.
- Frontend : `features/surgicalhub/` (section « Mon compte », ligne de
  synchronisation, liste des congés), calendrier à deux couches
  (`periodMapping.manualPeriods/importedRanges/withoutPreferencesOn`),
  dialogue de génération.
- Migrations : `Version20261010090000`, `Version20261010120000`.

### Relecture du 2026-10-10 (session SurgicalHub) — corrigé

- `404 link_not_found` révoquait et retirait les imports futurs : il
  **suspend** désormais, sans rien supprimer, avec un arrêt global du cron
  au-delà d'une association inconnue par passe (§9.2) — cas d'une
  sauvegarde SurgicalHub restaurée.
- Un congé conservé par une révocation restait bloquant et non retirable :
  son titulaire peut désormais le retirer (§9.1).
- Le calendrier coupait à chaque geste les préférences de garde **déjà
  enregistrées** qui chevauchaient un congé importé : seule une préférence
  **ajoutée** par le geste sur un jour importé est écartée ; une préférence
  existante n'est jamais modifiée, simplement dessinée sous le congé.
- Le contrôle avant génération appelait SurgicalHub participant par
  participant avec 10-15 s chacun : délai de 4 s par appel au lancement,
  arrêt dès la première panne (les suivants classés selon leur dernière
  réussite), budget global de 20 s ; un participant suspendu n'est jamais
  appelé.
- §12 décrivait comme faite la vérification des migrations sur le dump de
  production : elle a été faite ensuite, avec l'accord de l'utilisateur
  (résultats au §12).

### Relecture finale (2026-10-10) — corrigé

- Une période conservée par une révocation était jugée « synchronisée »
  seulement si **sa propre** association était courante : après une
  nouvelle association de la même paire, elle restait retirable jusqu'à la
  synchronisation suivante, qui l'aurait réimportée si son titulaire
  l'avait retirée entre-temps. Elle est désormais jugée sur la **paire de
  comptes** (test de non-régression, détecté par mutation).
- Trouvé par la recette navigateur : le **tableau de bord** (« Mes
  indisponibilités ») ne lisait plus que les saisies manuelles depuis la
  séparation du calendrier en deux couches — les congés importés n'y
  apparaissaient pas. Il affiche désormais l'union des indisponibilités
  manuelles et des congés importés (test de non-régression, détecté par
  mutation).
- Trouvé par la recette navigateur : la liste « Congés SurgicalHub »
  affichait « repris automatiquement ici » même quand plus aucun congé
  listé n'était synchronisé ; la phrase n'apparaît plus que s'il y en a un.

### Vérifié

- Tests backend : association (20), synchronisation (37, dont 20 réponses
  défaillantes sans aucune écriture locale), génération (5, OR-Tools réel),
  format du code (5) ; mutations ciblées (consommation du code, secret,
  garde de fenêtre, 404 exact, drapeau `complete`, révocation « futur
  seulement ») toutes détectées.
- Tests frontend : calendrier (couches, « Tout effacer », préférence),
  « Mon compte », dialogue de génération ; mutation de `planSync` détectée.
- Recette conjointe de bout en bout le 2026-10-10 entre le worktree
  SurgicalHub (`:8092`, base MySQL jetable) et la pile de dev MedVue :
  association par code, synchronisation initiale, idempotence,
  modification, retrait d'un jour (scission), suppression, SurgicalHub
  arrêté (aucun changement), révocation SurgicalHub (`410`), nouvelle
  association, dissociation depuis MedVue (`DELETE` reçu) — aucune anomalie
  de contrat ; le compteur `removed` d'une révocation distante, trouvé à
  0, a été corrigé.

- Seconde recette conjointe le 2026-10-10, après les corrections de la
  relecture, sur les deux piles de dev : association et trois imports
  (passé, en cours, futur) ; restauration SurgicalHub simulée (`404`) →
  `SUSPENDED`, aucune suppression ; nouveau code de la même paire → même
  `linkId`, congés intacts, synchronisation 0/0/0 ; `410` →
  `REVOKED_REMOTE`, `removed = 1` (le futur), passé et en cours conservés
  et retirables ; `PATCH` d'un congé orphelin → `409`, `DELETE` → `204` ;
  nouvelle association reprenant le passé, puis « Dissocier » → révocation
  côté SurgicalHub. Contrat v1 aligné des deux côtés (corps du redeem,
  codes d'erreur, alphabet du code, fenêtre de 821 j ≤ 850, noms des
  variables, secrets de test croisés).

### Non vérifié / limites

- Production : déployé le 2026-10-10 (`v2026.10.10-prod`, `docs/deployment.md`
  §6), après SurgicalHub. Joignabilité HTTPS entre conteneurs par les
  domaines publics vérifiée dans les deux sens. Recette conjointe en
  production (compte MedVue jetable `@example.test`, comptes SurgicalHub
  jetables, `MAIL_SAFE_MODE` côté SurgicalHub) : sondes avec secret
  (`404 link_not_found` pour un lien inexistant, `422 invalid_code` pour un
  faux code), import de 3 congés sans motif, modification, création puis
  suppression, `410` → `REVOKED_REMOTE` (futur retiré, passé et en cours
  conservés), nouvelle association (réimport sans doublon), « Dissocier »
  (`revoked_via MEDVUE` côté SurgicalHub), saisies manuelles identiques à
  chaque étape ; données retirées ensuite par l'API du titulaire. **Non
  exercée de bout en bout en production : la suspension (`404` sur une
  association réelle)**, qui suppose une base SurgicalHub restaurée — aucun
  mécanisme de simulation sans écriture directe en base ; couverte par les
  tests (`SurgicalHubSyncTest`). L'avis d'association n'a pas été remis
  (domaine `.test` refusé par le SMTP, association non affectée).
- Recette navigateur complète le 2026-10-10 (Chrome, bureau, pile de dev,
  compte et planning jetables `@example.test`, SurgicalHub de dev arrêté) :
  congés importés rayés et préférence manuelle existante intacte dessous ;
  « Synchroniser » → message clair, congés conservés ; préflight
  indiquant le participant associé ; lancement refusé (dernière reprise à
  48 h), case explicite du créateur → génération lancée, puis résolue par
  le worker OR-Tools avec exactement 3 gardes sans titulaire, les 10, 11 et
  12 novembre (jours du congé importé) ; dérogation journalisée
  (`STALE_DATA_OVERRIDDEN`, erreur, dernière réussite, job) ;
  « Dissocier » → futur retiré, passé et en cours conservés, préférence
  manuelle réapparue, « Retirer » sur le congé en cours ; snapshot de la
  génération toujours porteur du congé importé (D10) ; état suspendu
  affiché sur les deux pages ; tableau de bord après correction.
- Interface vérifiée dans Chrome sur la pile de dev (bureau, 1 568 px) :
  « Mon compte » non associé et associé, calendrier avec deux congés
  importés qui se chevauchent (union rayée, hors récapitulatif éditable,
  listée sous « Congés SurgicalHub »), « Synchroniser SurgicalHub » avec
  SurgicalHub arrêté (message clair, congés conservés). Le dialogue de
  génération l'a été ensuite par la recette ci-dessus. Pendant la
  vérification, le serveur Vite de dev ne voyait pas les fichiers modifiés
  (montage Windows) : un redémarrage du conteneur `frontend` a suffi.
- Largeur téléphone vérifiée le 2026-10-10 (Chrome, cadre de 390 × 844 px,
  pile de dev, données jetables supprimées ensuite) : tableau de bord
  (congés importés comptés), « Mes indisponibilités » (barre de
  synchronisation, congés rayés, préférence manuelle, liste « Congés
  SurgicalHub »), « Mon compte » associé, dialogue de génération (ligne
  SurgicalHub, puis refus pour données de plus de 24 h avec la case du
  créateur, bouton désactivé tant qu'elle n'est pas cochée, aucun job créé).
  Aucun défilement horizontal sur ces écrans. Seul débordement relevé :
  l'encart de collecte du tableau de bord (`CollectionCallout`, 482 px),
  **préexistant** et étranger à cette intégration, laissé hors de ce lot.
- Le suivi de collecte compte des **périodes** d'indisponibilité : un jour
  couvert par une saisie manuelle et un congé importé compte deux périodes
  (chacune affichée avec sa provenance).
- L'explication d'une exclusion à l'écran reste « indisponible » ; la
  provenance est dans le contexte de l'exclusion (endpoint d'éligibilité)
  et dans le snapshot.

