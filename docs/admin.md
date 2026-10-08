# Administration de la plateforme MedVue (Admin V1)

> Espace réservé aux **administrateurs de la plateforme** — l'éditeur du SaaS,
> pas les responsables d'équipe. On y suit les comptes, l'adoption, la
> sécurité et la santé technique de MedVue. On n'y gère **aucun** planning,
> aucune équipe, aucune garde. Décisions : `docs/decisions.md` D174 à D177.

## 1. Périmètre

| Dans le périmètre | Hors périmètre (volontairement absent) |
|---|---|
| Comptes : liste, fiche, désactivation / réactivation, révocation des sessions | Disponibilités des équipes, couverture, gardes non attribuées |
| Croissance et adoption : inscriptions, plannings créés, utilisateurs actifs, DAU/MAU, taux de retour, ancienneté | Équité entre médecins, statistiques de gardes |
| Journal d'audit des actions sensibles | Génération, republication, modification d'affectations |
| Erreurs techniques, santé de l'API, de la base, de la file des calculs, des emails, sauvegardes | Gestion des membres d'équipe, tableau de bord par planning |
| Administrateurs de la plateforme, paramètres de sécurité (lecture) | Toute porte d'entrée dans les plannings d'autrui |

Le nombre de plannings créés n'est utilisé que comme **indicateur d'adoption** :
jamais leur contenu. La fiche d'un utilisateur liste les plannings auxquels il
participe (nom, rôle, date) comme **contexte de compte**, sans lien vers eux.

## 2. Rôle global `ROLE_PLATFORM_ADMIN` (D174)

- **Persistance** : colonne `users.platform_admin` (booléen, `false` par
  défaut, `false` pour tous les comptes existants). `User::getRoles()` renvoie
  `ROLE_USER` + `ROLE_PLATFORM_ADMIN` si et seulement si elle est vraie.
- **Indépendant des équipes** : être OWNER/ADMIN d'une équipe ou créateur d'un
  planning ne donne aucun accès à `/api/admin` ; être administrateur de la
  plateforme ne donne **aucun** droit sur les plannings (les voters
  `PlanningVoter`/`PlanningTeamRoleVoter` ne connaissent pas ce rôle).
- **Protection côté serveur** : `access_control` `^/api/admin` →
  `ROLE_PLATFORM_ADMIN` (`config/packages/security.yaml`), et
  `#[IsGranted('ROLE_PLATFORM_ADMIN')]` sur chaque contrôleur
  (`src/Controller/Admin/`). Anonyme → `401` ; connecté sans le rôle → `403`.
  La route frontend `/admin` n'est qu'un écran : aucune donnée n'y est
  embarquée, tout vient de `/api/admin`.
- **Révocation immédiate** : le fournisseur d'utilisateurs recharge le `User`
  depuis la base à chaque requête JWT ; la revendication `roles` du JWT n'est
  jamais lue pour autoriser. Un rôle retiré cesse donc de fonctionner **à la
  requête suivante**, avec le jeton déjà émis (testé :
  `AdminAccessTest::testARevokedRoleStopsWorkingWithTheTokenAlreadyIssued`).
  Le compte, lui, reste utilisable.
- **Aucune élévation par requête** : `POST /api/register` et tous les corps
  JSON rejettent les champs inconnus (`422`, D116) — `platformAdmin`, `roles`
  y compris. Aucun endpoint ne modifie `/api/me`.

### Procédure d'attribution initiale

Seule la console du serveur peut nommer le premier administrateur (il faut
déjà opérer le serveur) ; le compte doit exister (inscription normale) et être
actif :

```bash
# production
docker exec medvue-backend php bin/console app:platform-admin grant prenom.nom@exemple.be
docker exec medvue-backend php bin/console app:platform-admin list
docker exec medvue-backend php bin/console app:platform-admin revoke prenom.nom@exemple.be
```

Chaque changement est audité (acteur `CONSOLE`). Retirer le **dernier**
administrateur est refusé (verrou de ligne sur les administrateurs : deux
retraits concurrents ne peuvent pas passer tous les deux).

Ensuite, un administrateur peut en nommer ou en retirer un autre dans
**Paramètres**, en retapant **son propre mot de passe** (un jeton d'accès volé
ne suffit pas). Jamais sur son propre compte. Mot de passe faux → `403
password_confirmation_failed`, audité `DENIED`.

## 3. Actions sur les comptes

| Action | Effet | Règles |
|---|---|---|
| Désactiver | `active = false`, révocation de toutes les familles de refresh tokens, `credentialsVersion` + 1 : le jeton d'accès en cours est refusé **dès la requête suivante** (D142), la connexion et le refresh sont refusés | Jamais soi-même ; jamais un administrateur de la plateforme (retirer son rôle d'abord) |
| Réactiver | `active = true` ; aucune session n'est restaurée | Jamais soi-même |
| Révoquer toutes les sessions | Toutes les familles de refresh tokens révoquées + `credentialsVersion` + 1 ; le compte reste actif | Jamais soi-même |

Chaque action : confirmation dans l'interface, motif facultatif (500
caractères), une transaction contenant l'action **et** son entrée d'audit. Un
refus de règle est audité `DENIED`. Aucune suppression de compte en V1.

## 4. Pages et écrans

`/admin` (cadre dédié : menu latéral dès 760 px, onglets défilants sur
téléphone, lien « Retour à MedVue »). L'entrée « Administration » n'apparaît
dans l'application (menu latéral, page « Mon compte ») que pour un
administrateur de la plateforme.

| Page | Contenu |
|---|---|
| Vue générale | 8 indicateurs (comptes, adoption, actifs 7 j / 30 j), 3 graphiques (inscriptions, plannings créés, utilisateurs actifs) sur 7 j / 30 j / 90 j / 12 mois, état technique synthétique |
| Utilisateurs | Recherche nom/email, filtre actif/désactivé, tri, pagination **côté serveur** ; fiche : compte, dernière activité, jours actifs (30 j), sessions récentes (appareil résumé, jamais l'IP ni le jeton), plannings (contexte), historique d'audit, actions |
| Statistiques | Inscriptions / plannings / utilisation globale par jour, semaine ou mois ; DAU, MAU, DAU/MAU ; taux de retour 7 j et 30 j ; ancienneté des comptes actifs |
| Activité | Journal d'audit filtrable (type, résultat), paginé ; erreurs techniques (onglet séparé) |
| Infrastructure | API, PostgreSQL (latence, version, taille), migrations, file des calculs, emails de publication, sauvegarde, test de restauration, erreurs ; bouton « Revérifier » |
| Paramètres | Administrateurs (ajouter / retirer avec mot de passe), durées de session et de jetons, cookies sécurisés, rétention, version — lecture seule |

Graphiques sans dépendance (`features/admin/charts.tsx`) : une série par
histogramme, infobulle au survol **et au clavier**, tableau « Voir les
données » sous chaque graphique ; la courbe DAU/MAU distingue ses deux séries
par la couleur **et** le trait (MAU en pointillés). Palette (vert 600 / bleu
600) validée pour le daltonisme. Tableaux transformés en cartes empilées sous
760 px.

## 5. Métriques : sources et définitions (D175)

Tous les jours sont des jours **Europe/Brussels** (`PlatformTime::TIMEZONE`) ;
« les N derniers jours » = aujourd'hui et les N-1 jours précédents.

| Métrique | Source | Définition |
|---|---|---|
| Utilisateurs inscrits | `users` | Tous les comptes, désactivés compris |
| Comptes actifs / désactivés | `users.active` | État administratif du compte — **pas** un usage |
| Nouvelles inscriptions | `users.created_at` | Comptes créés sur la période |
| Plannings créés | `plannings.created_at` | Plannings créés sur la période (adoption uniquement) |
| Utilisateur actif (jour) | `user_activity_days` | A **ouvert ou prolongé une session** ce jour-là (login ou rotation du refresh token) |
| Utilisateurs actifs 7 j / 30 j | idem | Utilisateurs distincts actifs au moins un jour de la fenêtre |
| DAU | idem | Utilisateurs actifs ce jour-là |
| MAU | idem | Utilisateurs distincts actifs sur les 30 jours se terminant ce jour-là |
| DAU/MAU | idem | Moyenne des DAU sur 30 jours ÷ MAU du jour (null si MAU = 0) |
| Utilisation globale | idem | Jours-utilisateurs : somme, sur la période, des (utilisateur, jour) actifs |
| Taux de retour à 7 j / 30 j | `users.created_at` + `user_activity_days` | Cohorte : inscrits sur 90 jours consécutifs, les plus récents dont la fenêtre est **complète** (inscription ≤ aujourd'hui − 7 / − 30). Taux = part ayant été active un **autre** jour que celui de l'inscription, dans les 7 / 30 jours suivants. `null` si cohorte vide |
| Ancienneté | `users.created_at`, comptes actifs | < 30 j, 30-89 j, 90-364 j, ≥ 365 j |
| Dernière activité d'un compte | `user_activity_days.last_seen_at` | Dernière ouverture ou prolongation de session connue |

### 5.1 Pourquoi « ouvrir ou prolonger une session »

Le jeton d'accès dure 15 minutes et le frontend renouvelle toujours la session
à l'ouverture de l'application : toute utilisation un jour donné passe par un
login ou un refresh. Seule exception assumée : une session ouverte peu avant
minuit et utilisée moins de 15 minutes après compte pour la veille.

### 5.2 Historique

La migration `Version20261008100000` reconstitue `user_activity_days` depuis
`refresh_tokens.created_at` : chaque ligne de cette table est **exactement** un
login ou une rotation (jamais supprimée), donc l'historique est réel depuis la
première connexion enregistrée, pas une estimation. Les inscriptions déjà en
base sont reprises dans le journal d'audit comme `USER_REGISTERED`
(`context.backfilled = true`, affiché « reconstitué »), datées par
`users.created_at`.

### 5.3 Données personnelles

Seuls `(user_id, jour, première et dernière heure du jour)` sont conservés pour
l'activité — ni IP, ni page, ni navigateur. Les sessions affichées dans la
fiche résument l'User-Agent déjà stocké par `refresh_tokens` (« Chrome ·
Windows ») ; l'IP n'est jamais exposée.

### 5.4 Rétention

| Donnée | Durée | Mécanisme |
|---|---|---|
| `user_activity_days` | 400 jours (12 mois + la fenêtre MAU) | `app:platform:purge-telemetry` (cron quotidien) |
| `technical_error_events` | 90 jours | idem |
| `platform_audit_events` | Permanent | Append-only (trigger), jamais purgé |

### 5.5 Coût

Une requête d'insertion idempotente (`INSERT … ON CONFLICT`) par login et par
refresh — jamais par requête API. Les agrégats portent sur des colonnes
indexées (`users.created_at`, `plannings.created_at`,
`user_activity_days.activity_date`) et ne sont calculés qu'à l'ouverture d'une
page d'administration ; aucun calcul périodique.

## 6. Journal d'audit (D176)

Table `platform_audit_events`, **append-only** (trigger PostgreSQL refusant
`UPDATE`/`DELETE`), écrite uniquement par `PlatformAuditLogger`.

| Champ | Contenu |
|---|---|
| `type` | `USER_REGISTERED`, `USER_DEACTIVATED`, `USER_REACTIVATED`, `USER_SESSIONS_REVOKED`, `PLATFORM_ADMIN_GRANTED`, `PLATFORM_ADMIN_REVOKED`, `PASSWORD_RESET_COMPLETED` |
| `outcome` | `SUCCESS`, `DENIED` (refus d'une règle : soi-même, mot de passe faux, cible administratrice…), `FAILURE` (réservé) |
| `actor_kind` / `actor_id` | `USER` (+ l'utilisateur), `CONSOLE`, `SYSTEM` — CHECK de cohérence en base |
| `target_user_id` | Compte concerné |
| `context` | Faits courts non sensibles : motif saisi, nombre de sessions fermées, IP de l'administrateur, voie d'inscription, raison du refus. **Jamais** un mot de passe, un jeton, un secret, une donnée médicale ou de planning |

Le journal d'audit n'est **pas** un journal technique : les erreurs serveur
vont dans `technical_error_events` (§7).

## 7. Supervision technique (D177)

Chaque vérification est faite **réellement** à l'ouverture de la page, sur les
seules ressources de MedVue, et rend `ok`, `warning`, `error` ou `unknown`
(« Non vérifiable » — jamais « opérationnel » par défaut).

| Vérification | Comment | Fiabilité |
|---|---|---|
| API | La requête a abouti | Exacte |
| PostgreSQL | `SELECT 1` chronométré, `server_version`, `pg_database_size` de la base MedVue | Exacte ; si la base est en panne, l'administration elle-même est inaccessible (l'authentification en dépend) : `/api/health` reste la sonde externe |
| Migrations | Doctrine Migrations : en attente / appliquées inconnues | Exacte |
| File des calculs | `messenger_messages` (attente, plus ancien message, file d'échec) + `planning_jobs` des 7 derniers jours (réussis, échoués, durées moyenne et max) | Un worker arrêté n'est visible que s'il y a du travail en attente (> 10 min → `error`) |
| Emails de publication | `planning_publication_notifications` : `FAILED`, en attente > 1 h, envoyés sur 7 j | Exacte pour les emails de publication (les autres emails sont synchrones) |
| Sauvegarde / test de restauration | Fichiers `backup.json` / `restore-test.json` écrits par `scripts/backup/*.sh` dans `/home/deploy/backups/medvue/status`, montés **en lecture seule** sur `/app/var/ops-status` | Ce que les scripts ont écrit ; absent → `unknown`. Sauvegarde > 36 h → `warning` ; test de restauration > 31 j → `warning` |
| Erreurs serveur | `technical_error_events` : 500 de l'API (`ApiExceptionListener`) et messages Messenger en échec (`WorkerFailureListener`) — classe de l'exception, route, méthode, jamais le message | Best-effort : une erreur survenue pendant une panne de la base n'est que dans les journaux du conteneur |
| Version | Fichier `RELEASE` écrit dans l'image de production (`APP_VERSION` à la construction) | Absent (dev, image construite sans l'argument) → « non renseignée » |

Interdits respectés : pas d'accès au socket Docker, ni à l'hôte, ni aux autres
applications du VPS ; aucune variable d'environnement ni secret dans les
réponses (testé) ; aucune commande, sauvegarde ou restauration déclenchable.

## 8. Endpoints

Tous sous `/api/admin`, JWT + `ROLE_PLATFORM_ADMIN`. Erreurs JSON
`{error, message}` ; corps stricts (champ inconnu → `422`) ; paramètres de
requête invalides → `400 invalid_query`.

| Méthode | Route | Rôle |
|---|---|---|
| `GET` | `/overview` | Indicateurs de la Vue générale |
| `GET` | `/analytics/timeseries?range=7d\|30d\|90d\|12m&granularity=day\|week\|month` | Séries complétées par des zéros : inscriptions, plannings, actifs, jours-utilisateurs |
| `GET` | `/analytics/adoption?range=30d\|90d\|12m` | DAU/MAU quotidiens, taux de retour, ancienneté |
| `GET` | `/users?search=&status=all\|active\|disabled&sort=createdAt\|lastActivity\|name\|email&direction=asc\|desc&page=&perPage=` | Liste paginée (max 100 / page) |
| `GET` | `/users/{stableId}` | Fiche |
| `POST` | `/users/{stableId}/deactivate` · `/reactivate` · `/revoke-sessions` | `{reason?}` → fiche à jour ; `409` `cannot_target_self`, `target_is_platform_admin`, `already_disabled`, `already_active` |
| `GET` | `/audit-events?type=&outcome=&user=&page=&perPage=` | Journal d'audit |
| `GET` | `/technical-errors?page=` | Erreurs techniques |
| `GET` | `/system-health` | Santé (`Cache-Control: no-store`) |
| `GET` | `/settings` | Administrateurs, sécurité, rétention, version |
| `POST` | `/platform-admins` | `{email, password}` → `201` ; `403 password_confirmation_failed`, `404 user_not_found`, `409` `cannot_target_self`, `already_platform_admin`, `target_disabled` |
| `POST` | `/platform-admins/{stableId}/revoke` | `{password}` ; `409` `cannot_target_self`, `not_platform_admin`, `last_platform_admin` |

## 9. Code

| Couche | Fichiers |
|---|---|
| Contrôleurs (minces) | `src/Controller/Admin/*` (`AdminJson` : corps stricts, pagination, erreurs) |
| Services | `src/Service/Admin/` : `PlatformAnalytics`, `AdminUserDirectory`, `AdminUserService`, `PlatformAdminService`, `PlatformAuditLogger`, `UserActivityRecorder`, `TechnicalErrorLog`, `SystemHealth`, `BackupStatusReader`, `AppVersion`, `AdminSettings`, `TelemetryRetention` |
| Entités | `User.platformAdmin`, `PlatformAuditEvent` (+ enums) ; `user_activity_days` et `technical_error_events` en DBAL (tables techniques sans comportement) |
| Commandes | `app:platform-admin grant\|revoke\|list`, `app:platform:purge-telemetry` |
| Frontend | `src/features/admin/`, `src/pages/admin/`, routes `/admin/*` dans `App.tsx` |
| Migration | `Version20261008100000` (ajouts uniquement, réversible) |

## 10. Tests

Backend (`tests/Controller/Admin/`, `tests/Service/Admin/`,
`tests/Command/PlatformAdminCommandTest.php`) : accès anonyme / utilisateur /
OWNER d'équipe / administrateur sur chaque route, auto-attribution impossible,
rôle refusé à l'inscription, révocation effective avec le jeton déjà émis,
désactivation (jeton, refresh et login refusés), réactivation, révocation des
sessions, refus audités, corps stricts, pagination / recherche / filtres / tri,
caractère `%` littéral, aucune fuite de secret (passphrase JWT, `APP_SECRET`,
DSN, hash, jeton, IP), statistiques sur base vide, jours de Bruxelles, semaines,
mois, DAU/MAU, cohortes complètes, ancienneté, journal append-only en base,
erreurs enregistrées sans leur message, rétention, santé (base, migrations,
file bloquée, sauvegarde inconnue), commande console.

Frontend (`src/pages/admin/admin.test.tsx`) : garde d'accès, entrée de menu,
Vue générale (distinction comptes / actifs, « Non vérifiable »), recherche et
filtres envoyés au backend, désactivation confirmée avec motif, refus affiché
dans le dialogue, pas d'action sur soi-même, mot de passe requis pour attribuer
le rôle, graphiques accessibles (tableau, infobulle au focus).

## 11. Limites connues / suites possibles

- Pas de suppression de compte ni d'export RGPD (V1). **Conséquence
  opérationnelle** : un compte ne peut plus être supprimé en base, même à la
  main — son inscription est dans `platform_audit_events` (append-only) et son
  activité dans `user_activity_days`, toutes deux en `ON DELETE RESTRICT`. Les
  comptes jetables de recette en production (`docs/deployment.md` §6) sont donc
  **désactivés** après usage, jamais supprimés ; ils restent comptés parmi les
  inscrits. Un effacement RGPD futur devra être conçu explicitement
  (pseudonymisation du compte plutôt que suppression), jamais en désactivant le
  trigger d'audit.
- La disponibilité d'un worker inactif n'est pas observable (pas de battement
  hors calcul) ; seule une file bloquée l'est.
- Pas de mesure des temps de réponse HTTP (aurait demandé une écriture par
  requête) : la performance affichée se limite à la latence PostgreSQL et à la
  durée des calculs de planning.
- Les paramètres de sécurité sont en lecture seule ; les limites de débit
  (`rate_limiter.yaml`, `login_throttling`) ne sont pas encore affichées.
- La vérification d'email n'existe pas encore : la fiche l'indique.
- Les statuts de sauvegarde ne remontent qu'après le premier passage des
  scripts mis à jour **et** le montage du répertoire `status` (déploiement).
