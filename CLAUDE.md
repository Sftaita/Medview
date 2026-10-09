# MedVue

## But de l'application

MedVue remplace **Lifen Planning** pour la gestion des gardes médicales.
Objectif final : permettre à des équipes médicales de gérer leurs gardes sur
plusieurs années **sans perdre ni l'historique ni l'équité**, avec un
système fiable là où l'existant repose sur du planning manuel ou
semi-automatique.

L'application doit gérer, à terme :

- plusieurs **équipes**, chacune avec ses membres, ses rôles, ses règles ;
- plusieurs **périodes de planning** par équipe, distinctes de l'équipe
  elle-même ;
- les **indisponibilités** des utilisateurs, déclarées une seule fois dans
  un calendrier global partagé par toutes leurs équipes ;
- la **génération automatique équitable** des gardes (moteur dédié, hors
  contrôleurs) ;
- l'**historique permanent** des affectations, jamais recalculé depuis
  l'état courant ;
- les **échanges de garde** et les **notifications**.

Le cahier des charges complet (40 sections : modèle de données détaillé,
règles d'équité multi-dimensionnelle, contraintes dures/souples, snapshot
de génération, etc.) a été fourni au lancement du projet et guide toutes
les décisions de modélisation à venir.

## Principe non négociable : l'équité est multidimensionnelle

Le moteur de planning (à venir) ne devra **jamais** optimiser un score
global unique — un utilisateur sans week-end pourrait compenser par de
nombreuses petites gardes de semaine. Chaque dimension (score, week-ends,
vendredis/samedis/dimanches, jours fériés, espacement, préférences) doit
être suivie et équilibrée séparément, avec des contraintes dures/souples
clairement distinguées et un résultat explicable (pas de boîte noire).
Ce principe structure tout le modèle de données à venir — à garder en tête
dès qu'une entité liée aux gardes ou aux plannings est conçue. Design
détaillé (encore conceptuel, rien d'implémenté) :
[`docs/allocation-algorithm.md`](docs/allocation-algorithm.md).

## État d'avancement

| Étape | Statut | Référence |
|---|---|---|
| Socle technique (Symfony + React + Docker) | ✅ Livré | `README.md` |
| Authentification (User, register/login/me, rate limiting, access+refresh token rotatif) | ✅ Livré + UAT navigateur complète (2026-09-15, `AUTHENTIFICATION MEDVUE : PASS`) | `docs/authentication.md` §14 |
| Équipes (`PlanningTeam`, propriété exclusive d'un `Planning`), rôles | ✅ Restructuration livrée (2026-09-18) : plus d'entité globale, création inline par ligne, endpoints + UI de gestion des membres ; invitations par email ajoutées ensuite (ligne « Inscription enrichie ») | `docs/planning.md` |
| Disponibilités (calendrier personnel, non-participation administrative) | ✅ Livré (2026-09-16) | `docs/availability.md` |
| Campagnes de collecte de disponibilités | ⏳ Pas commencé | — |
| Génération : `PlanningGeneration`/`PlanningSnapshot`/`DutyAssignment` (persistance, pas d'algorithme) | ✅ Livré (2026-09-16) | `docs/planning-generation.md` |
| Éligibilité : `EligibilityService`/`EligibilityMatrixBuilder` (sous-ensemble de raisons, pas de solveur) | ✅ Livré (2026-09-16) | `docs/eligibility.md` |
| Planning multi-lignes : `Planning`/`PlanningLine` (agrégat visible, moteurs de ligne mono-équipe) | ✅ Livré (2026-09-16) | `docs/planning.md` |
| Fairness : `FairnessContext`/`OptimizationProblem` abstrait (targets, structurally forced, pas de solveur) | ✅ Livré (2026-09-18) | `docs/fairness.md` |
| Frontière `PlanningSolver` + 8 phases d'objectif GENERATE (contrat abstrait, pas de solveur concret) | ✅ Livré (2026-09-18) | `docs/planning-solver.md` |
| `OrToolsPlanningSolver` : OR-Tools CP-SAT réel, STRICT GENERATE, lexicographique | ✅ Livré (2026-09-18). Phases 6/7 (`spacingScore`/`preferenceSatisfaction`) réellement résolues depuis D139 (2026-09-24) — jusque-là `NEUTRAL`, trouvé par audit qualitatif réel (`SpacingPenaltyCalculator`, `DutyUnitSpan`, kinds CP-SAT `SPACING_PENALTY`/`LINEAR`) | `docs/planning-solver.md` §5, `docs/decisions.md` D139 |
| STRICT → PARTIAL, priorité CRITICAL, diagnostic UNSAT structuré (`UnsatReport`) | ✅ Livré (2026-09-18) | `docs/planning-solver.md` |
| Contraintes globales `CONFLICT`/`TEAM_MIN_REST` (`AssignmentConflict`) — priorité CRITICAL réellement observable | ✅ Livré (2026-09-19) | `docs/planning-solver.md` |
| Politiques de repos par génération : `LEGAL_MIN_REST`/`TEAM_MIN_REST` deviennent des options `RestPolicyOptions` figées par `PlanningGeneration`, jamais un défaut d'équipe | ✅ Livré (2026-09-19) | `docs/planning-solver.md` §36, `docs/decisions.md` D105 |
| Orchestration réelle `PlanningGeneration → solve → DutyAssignment AUTO` : `SolverParameterSet`, seed/snapshotHash, timeout CP-SAT réel, concurrence par verrou optimiste, atomicité, `PUBLISHED` ⇒ coverage COMPLETE | ✅ Livré (2026-09-19) | `docs/planning-generation.md` §13-16, `docs/planning-solver.md` §37, `docs/decisions.md` D106 |
| Moteur de génération avancé (`fixedAssignments` réels, REPAIR, SIMULATE, MAX_DUTIES/MAX_WEEKENDS, validation/publication avancée) | ⏳ Design conceptuel écrit, pas implémenté (MAX_DUTIES/MAX_WEEKENDS/MAX_CONSECUTIVE_NIGHTS confirmées inertes, D138) | `docs/allocation-algorithm.md` |
| Inscription enrichie (téléphone E.164, **sans hôpital** : l'établissement n'est pas une propriété du `User`) + invitations d'équipe (`TeamInvitation`, emails Mailer/Twig, inscription par lien, multi-invitations) | ✅ Livré (2026-09-20), UAT navigateur OK. Affiliation hospitalière : à modéliser plus tard dans un contexte daté (D115), pas d'import ni de référentiel | `docs/authentication.md` §15, `docs/decisions.md` D111-D116 |
| Refonte de l'interface (charte, tokens, mobile d'abord) : cadre, connexion/inscription/invitations, tableau de bord, plannings, calendrier d'indisponibilités en **jour entier** (sélection multiple, tactile) | ✅ Livré (2026-09-21) — tests Vitest, vérifié dans un navigateur | `docs/decisions.md` D117-D119, `docs/availability.md` §8 |
| Collecte des disponibilités par fenêtre (`AvailabilityCollection`/`Response`), prolongation d'un planning, participation du créateur, planning par personne, calendrier optimiste sans « Enregistrer » | ✅ Livré (2026-09-21) — tests backend/frontend + UAT navigateur complète (deux comptes, deux onglets) | `docs/availability-collection.md`, `docs/decisions.md` D120-D126 |
| Vue de pilotage OWNER/ADMIN d'un planning : statut de collecte par membre (drawer, indisponibilités de la période), rappels email individuels/groupés (audit append-only), paramètre `availabilityDeadline` informatif, préflight + génération au niveau du planning (façade sur le pipeline existant, snapshot pris au lancement) | ✅ Livré (2026-09-23) — tests backend/frontend + UAT navigateur complète (génération réelle OR-Tools bout en bout, email réel via Mailpit, immutabilité du snapshot vérifiée) ; vérification mobile non complétée (limite de l'environnement de test, cf. rapport) | `docs/availability-collection.md` §11/§13/§14, `docs/planning-generation.md` §11, `docs/decisions.md` D127-D129 |
| Semaine type : composant `WeekStructureEditor` (garde isolée / bloc atomique / pas de garde) | ✅ Composant livré (2026-09-23, D134), **branché** depuis D136 ci-dessous | `docs/week-structure.md`, `docs/decisions.md` D134 |
| Structure hebdomadaire configurable par `PlanningLine`, familles d'équité génériques `ALLOCATION_FAMILY` (remplace `WEEKEND_GROUPS`) : `AllocationFamily`, `DutyPattern.family`/`.recurring`, `Duty.pattern`, pipeline réel de matérialisation (`WeekStructureService`/`WeeklyDutyCalendarService`, jusque-là inexistant en production), endpoint `GET/PUT .../week-structure`, matérialisation à la demande au préflight de génération | ✅ Livré (2026-09-24) — tests backend/frontend ; UI limitée à la ligne principale (dette) ; pas d'heure de garde configurable, pas d'exceptions calendaires datées (dette) | `docs/week-structure.md`, `docs/planning-domain.md` §9-§11, `docs/fairness.md` §2-§4, `docs/allocation-algorithm.md` §5/§6/§9, `docs/planning-generation.md` §20, `docs/decisions.md` D136 |
| Configuration opérationnelle de la génération, de bout en bout : activation des règles de planning (`PlanningRuleSetController`, porte d'activation sans formulaire de paramètres inertes), règles de repos choisies au lancement planning-level (`RestPolicyOptions` threadée jusqu'à `PlanningGenerationLauncher`, jusque-là hardcodée à `none()`), structure de ligne dynamique (`familyUnitCounts`) et distinction OPTIMAL/FEASIBLE dans le préflight/résultat, statistiques par famille (`countsByFamily`) | ✅ Livré (2026-09-24) — tests backend/frontend + UAT navigateur complète (génération réelle OR-Tools COMPLETE+OPTIMAL et INCOMPLETE+diagnostic réel provoqués tous deux en conditions réelles, statistiques par famille équilibrées vérifiées, nettoyage zéro résidu) | `docs/planning-generation.md` §21, `docs/decisions.md` D138 |
| Refonte du détail d'un planning (`/plannings/:id`, maquette `react_planning_detail`) : en-tête + menu « ⋯ », période (fin inclusive), étapes, onglets Lignes / Indisponibilités / Planning, panneaux Membres / Prolonger / Renommer — composants et endpoints existants réutilisés, aucun changement backend | ✅ Livré (2026-09-25) — tests Vitest + validation navigateur desktop/mobile (Playwright, compte de dev) | `docs/decisions.md` D140 |
| Mot de passe oublié / réinitialisation (`PasswordResetToken`, lien à usage unique dans le fragment d'URL, rate limiting IP+email, invalidation de toutes les sessions + des JWT déjà émis via `credentialsVersion`) | ✅ Livré (2026-09-26) — tests backend (55 nouveaux + 859 suite complète) + frontend (351) + UAT navigateur complète (Mailpit réel, ancien mot de passe refusé, nouveau accepté, lien réutilisé rejeté, email inconnu indistinguable) | `docs/authentication.md` §16, `docs/decisions.md` D141-D142 |
| Workflow proposition → diffusion : calendrier vertical multi-lignes, remplacer/retirer (bloc entier, candidats impossibles absents), « Compléter automatiquement » (`fixedAssignments` réels, trous uniquement — pas REPAIR), statistiques (charge pondérée, types), publication enregistrée garde par garde + email/PDF, « Modifications non publiées » + republication à l'audience par date, rappel du samedi, droit « Gestionnaire » accordé par le créateur | ✅ Livré (2026-09-27) — tests backend (901) + frontend (367) + UAT navigateur/API sur la stack dev (génération réelle, retrait, complétion, publication, republication, rappel via Mailpit) ; vérification mobile non faite (outil navigateur) | `docs/planning-generation.md` §22, `docs/decisions.md` D143-D148, `docs/deployment.md` §5 bis |
| Génération et complétion **asynchrones** : `PlanningJob` (QUEUED/RUNNING/SUCCEEDED/FAILED), Symfony Messenger (transport Doctrine), conteneur `worker`, battement pendant la résolution, récupération des jobs abandonnés (jamais de SOLVING éternel), un seul calcul actif par planning (index partiel), suivi par polling et reprise après retour sur la page ; « Générer » masqué après publication | ✅ Livré (2026-09-27) — tests backend/frontend + recette navigateur avec OR-Tools réel (> 30 s) | `docs/planning-generation.md` §23, `docs/decisions.md` D149, `docs/deployment.md` §5 ter |
| Export du planning publié (PDF / Excel `.xlsx`) : calendrier **courant** (`CurrentCalendarReader`, jamais le solveur ni le snapshot), lignes/ordre/noms d'export/titre/période choisis dans un dialogue, un modèle intermédiaire unique pour les deux formats, aperçu PDF, protection contre l'injection de formule, préférences non persistées | ✅ Livré et déployé en production (`v2026.09.28-prod`, 2026-09-28, `docs/deployment.md` §6 ; aucun planning publié en production à cette date, export de bout en bout sur données réelles à vérifier au premier) — tests backend (946, dont 21 nouveaux) + frontend (412) ; revue avant merge : PDF limité à 800 rangées (dépassement mémoire corrigé), « PDF de la dernière diffusion » vs « Exporter », aperçu en lien sur téléphone ; vérifié dans Chrome (téléchargements réels, 1 600 / 817 / 387 px) | `docs/planning-export.md`, `docs/decisions.md` D150 |
| Tableau de bord refondu (maquette `react_dashboard`) + cadre applicatif aligné (menu latéral dès 760 px, barre du bas à 4 entrées, avatar « Mon compte ») ; `GET /api/plannings` expose `myLineName`/`memberCount`/`published` | ✅ Livré (2026-09-28) — tests backend + frontend ; comparé au pixel près à la maquette dans Chrome (1 600 px et 390 px) | `docs/decisions.md` D151, `docs/Design/react_dashboard` |
| « Mes plannings » refondu (maquette `react_mes_plannings`) : cartes groupées En cours / À venir / Terminés, statut et compteur de jours, frise par mois, fin inclusive, étape déduite (Publié / Collecte ouverte / À générer) ; `GET /api/plannings` expose `lineCount`/`collecting` | ✅ Livré (2026-09-29) — tests backend + frontend | `docs/decisions.md` D169, `docs/Design/react_mes_plannings` |
| Ligne secondaire **conditionnelle** (« renfort selon le chirurgien de garde »), de bout en bout : multi-appartenance d'un `User` à plusieurs lignes (D160), contraintes inter-lignes par personne — engagements figés + contrôle live symétrique (D161), politique de demande **versionnée** avec déclencheurs `User` × jours de semaine (D162), gardes `CONDITIONAL` reliées à leur garde source (`Duty.coverageSource`), `DemandView` LIVE/SNAPSHOT, `SELF_COVERAGE` (D163), génération source → cible dans l'orchestration D149, demande figée par génération dans le snapshot et le `snapshotHash`, unités non déclenchées absentes du problème, équité et exposition sur la demande réelle (D164), calendrier live — `dependentImpacts`, `coverage_not_required`, renforts superflus conservés, retrait explicite, complétion sur la demande live, indéterminé jamais complété (D165), publication / statistiques / emails / PDF / export PDF-XLSX sur une seule décision live (D166), frontend — « Paramètres de la ligne », matrice chirurgiens × jours, cinq états live, responsive et accessible (D167) | ✅ Livré et déployé en production (`v2026.09.29-prod`, 2026-09-29, `docs/deployment.md` §6 ; branche `feature/conditional-secondary-line` fusionnée dans `master` avec le tableau de bord D151) — tests backend + frontend, mutations ciblées à chaque lot, recette navigateur desktop (17 étapes) et smartphone (390 px) avec OR-Tools et worker réels ; L9 : correctif des candidats de réaffectation au premier jour d'une adhésion (dates, jamais instants) | `docs/planning.md` §16, `docs/planning-generation.md` §24-§28, `docs/planning-solver.md` §38-§39, `docs/fairness.md`, `docs/planning-export.md` §12, `docs/decisions.md` D160-D167 |
| « Mes gardes » (`GET /api/me/duties`, calendrier courant des lignes publiées, à venir / passées, blocs groupés, renforts signalés, adhésions closes incluses) + carte « Prochaine garde » sur l'accueil | ✅ Livré et déployé en production (`v2026.09.29-prod-3`, 2026-09-29) — tests backend + frontend | `docs/decisions.md` D168 |
| Abonnement agenda de « Mes gardes » (Google Agenda, Apple Calendrier, Outlook) : flux iCalendar `GET /api/calendar-feeds/{token}.ics` public par adresse secrète (`CalendarFeed`, jeton en clair assumé), lecture exacte de `MyDutiesService`, événements journée entière, blocs d'un seul tenant, renforts incertains `TENTATIVE`, créer / régénérer / désactiver depuis « Mes gardes » | ✅ Livré et déployé en production (`v2026.09.29-prod-3`, 2026-09-29, `docs/deployment.md` §6 ; PR #1) — tests backend (34 nouveaux, suite complète 1175 OK) + frontend (15 nouveaux, suite 503 OK), mutations ciblées (règle publique, jetons révoqués) ; recette API + navigateur desktop sur une copie de la base de dev, flux relu par ical.js ; non vérifiés : mobile dans le navigateur, et de vrais Google/Apple/Outlook (le flux doit être joignable publiquement, donc en production) | `docs/calendar-subscription.md`, `docs/decisions.md` D170 |
| Correctif republication / complétion (cas réel de production, reproduit sur une copie locale) : incohérences du préflight localisables (garde ou bloc, dates, titulaire, règle `reasonCode`) dans le calendrier et les modales Publier/Republier, « Voir dans le calendrier » ; « Compléter automatiquement » affiche « Complétion en cours… » pendant un job | ✅ Livré et déployé en production (`v2026.09.29-prod-5`, 2026-09-29, `docs/deployment.md` §6 ; PR #2) — tests backend + frontend, recette Playwright locale (absence après publication, réattribution, retrait + complétion), contrôle mesuré en production (0,7-0,9 s, jamais bloquant pour le calendrier) | `docs/decisions.md` D171, `docs/planning-generation.md` §29 |
| Emails de republication personnalisés : seules les personnes dont les propres gardes changent (retrait / ajout, par `User`), un email chacune avec ses seuls changements (blocs avec toutes leurs dates) + PDF du planning republié ; boîte d'envoi `PlanningPublicationNotification` (changements figés avec la publication, réservation atomique : ni envoi parallèle ni renvoi d'un email `SENT` ; doublon possible seulement sur incertitude SMTP), reprise `app:publication-notifications:retry` (cron) ; PDF de chaque publication **stocké tel que généré** (`PlanningPublicationDocument`, D173) : renvois et téléchargement servent les mêmes octets, nom et période | ✅ Livré et déployé en production (`v2026.09.30-prod`, 2026-09-30, `docs/deployment.md` §6 ; PR #3) — suite backend complète (fichier par fichier) + frontend, recette navigateur + Mailpit sur la pile de dev (génération OR-Tools réelle, échec SMTP puis reprise concurrente) ; CI verte (backend 1 204 tests), cron de reprise installé en production (`docs/deployment.md` §5 quater, premier passage confirmé) ; parcours de republication pas encore exercé en production (aucune republication réelle depuis) | `docs/planning-generation.md` §30, `docs/decisions.md` D172-D173 |
| Administration de la plateforme V1 (`/admin`, éditeur SaaS — **pas** la gestion des plannings) : rôle global `ROLE_PLATFORM_ADMIN` (`users.platform_admin`, relu en base à chaque requête, attribution initiale par `app:platform-admin`, puis par un admin avec son mot de passe), comptes (liste/fiche, désactiver/réactiver/révoquer les sessions), statistiques d'adoption (activité journalière `user_activity_days` écrite au login/refresh, reprise depuis `refresh_tokens`), journal d'audit append-only, erreurs techniques, santé en lecture seule (sauvegardes par fichier de statut monté en lecture seule) | ✅ Livré et déployé en production (`v2026.10.08-prod`, 2026-10-08, `docs/deployment.md` §6 ; PR #4 + correctif du test de restauration PR #5) — backend 1 259 tests (CI) + frontend 537 ; migration répétée sur une restauration jetable du dump de production ; recette API en production (refus 403, retrait immédiat du rôle, désactivation effective, audit avec IP réelle, statut des sauvegardes) ; premier administrateur attribué ; interface vue en dev seulement (Chrome, 390/820 px) |  `docs/admin.md`, `docs/decisions.md` D174-D177 |
| Échanges de gardes entre membres, sans validation du gestionnaire : échange déjà convenu (le collègue accepte/refuse), recherche auprès de collègues choisis ou de toute la ligne (propositions, une seule retenue), annulation/retrait, avertissement de responsabilité exigé par l'API, revalidation complète **sur le calendrier après permutation** sous le verrou du calendrier (la garde cédée n'est jamais un faux conflit), blocs entiers, permutation atomique `source = SWAP`, historique append-only, emails (proposition, confirmation aux deux avec consigne de prévenir la direction) par boîte d'envoi + `app:duty-swaps:maintain`, page « Échanges », « Mes gardes » (« Échanger ma garde » / « Échange demandé »), historique en lecture seule pour les gestionnaires | ✅ Livré et déployé en production (`v2026.10.09-prod`, 2026-10-09, `docs/deployment.md` §6 ; PR #6) — CI verte (backend 1 294 tests dont `tests/Deployment`, frontend 552) ; recette sur la pile de dev : Chrome desktop (échange convenu refusé pour repos puis accepté, emails Mailpit, historique gestionnaire), 390 px par iframe, acceptations réellement simultanées par API (une seule appliquée) ; en production : API, droits et cron vérifiés, **aucun échange réel ni email d'échange déclenché** (vrais membres, vrai SMTP) ; cron `app:duty-swaps:maintain` installé | `docs/duty-swaps.md`, `docs/decisions.md` D178-D180, `docs/deployment.md` §5 sexies |
| Export PDF des absences d'un planning (« Exporter les absences (PDF) », onglet Indisponibilités) : calendrier collectif mensuel + récapitulatif par membre, `GET /api/plannings/{id}/availability-export.pdf` sous `MANAGE_AVAILABILITY`, jours civils du fuseau du planning, limité à la période et aux adhésions, lecture live (jamais un snapshot), dompdf existant, aucune migration | ✅ Livré et déployé en production (`v2026.10.09-prod-2`, 2026-10-09, `docs/deployment.md` §6 ; PR #7) — CI verte (backend 1 327 tests sans skip, frontend 559) ; recette navigateur sur la pile de dev ; en production : export calculé en lecture seule sur le vrai planning (0 jour hors période/adhésion, totaux identiques à un recalcul SQL indépendant, PDF valide), données inchangées ; **recette authentifiée depuis l'interface en production restant à effectuer** | `docs/availability.md` §11, `docs/decisions.md` D181 |
| Notifications in-app | ⏳ Pas commencé | — |

## Où trouver quoi

- **`docs/decisions.md`** — journal chronologique de chaque choix technique
  structurant, avec son contexte et ses compromis. **À consulter avant de
  remettre en cause une décision existante**, et à compléter à chaque
  nouvelle décision non triviale.
- **`docs/authentication.md`** — modèle de données, stratégie JWT, sécurité,
  décisions ouvertes de la fonctionnalité d'authentification. Un document
  du même type sera créé pour chaque fonctionnalité majeure suivante
  (`docs/teams.md`, …).
- **`docs/availability.md`** — modèle de données, sémantique
  `UNAVAILABLE`/`PREFER_DUTY`, non-participation administrative,
  chevauchement, autorisations et endpoints du Lot 2 (disponibilités).
- **`docs/planning-generation.md`** — modèle de données, cycle de statut,
  composition et immuabilité du snapshot, relation `DutyAssignment` ↔
  `PlanningSnapshotMember`, concurrence, autorisations et endpoints du
  Lot 3 (`PlanningGeneration`/`PlanningSnapshot`/`DutyAssignment` —
  persistance uniquement, pas d'algorithme de génération). Depuis le Lot
  6D.1, `PlanningGeneration` porte aussi sa politique de repos figée
  (`RestPolicyOptions`, D105) — voir §2. Depuis le Lot 6E,
  `PlanningGenerationService::generate()` orchestre un vrai solve
  (`SolverParameterSet`, seed/snapshotHash, `DutyAssignment` AUTO,
  atomicité, concurrence par verrou optimiste, `PUBLISHED` ⇒ coverage
  COMPLETE) — voir §13-16, `docs/decisions.md` D106. Depuis D138,
  `PlanningGenerationLauncher::launch()` accepte une `RestPolicyOptions`
  choisie au lancement (jusque-là hardcodée `none()`), le préflight
  expose `familyUnitCounts` par ligne, et `PlanningRuleSetController`
  (porte d'activation, jamais un formulaire) débloque `NO_ACTIVE_RULE_SET`
  — voir §21.
- **`docs/eligibility.md`** — modèle métier d'éligibilité
  (`ExclusionReason`/`ConstraintTier`/`DutyUnit`), raisons réellement
  calculables vs seulement déclarées, sémantique de
  `structuralOpportunity`, atomicité des groupes de gardes, endpoint
  d'audit du Lot 4 (`EligibilityService`/`EligibilityMatrixBuilder` —
  toujours pas de solveur ni d'affectation automatique).
- **`docs/planning.md`** — l'agrégat `Planning`/`PlanningLine` visible
  côté utilisateur, ligne PRIMARY/SECONDARY, isolation stricte des
  populations par ligne (mono-équipe, moteur inchangé), autorisations
  creator-only, `PlanningTeam` propriété exclusive d'un Planning et créée
  inline par sa ligne, règle "une adhésion ouverte par équipe" — un même
  User peut appartenir à plusieurs lignes d'un Planning (D160, remplace
  D080), endpoints et UI du lot Planning + restructuration Team.
- **`docs/availability-collection.md`** — collecte des disponibilités par
  fenêtre : "répondu" = événement explicite distinct de
  `UserAvailabilityPeriod` (D120), extension d'un planning (D122), participation
  du créateur (D123), autorisations (D124), lecture des affectations par
  personne (D125), calendrier optimiste (D126).
- **`docs/week-structure.md`** — composant `WeekStructureEditor`
  (`frontend/src/features/week-structure/`) : structure hebdomadaire d'une
  ligne (garde isolée, bloc attribué d'un seul tenant, jour sans garde =
  absent de la demande), familles d'équité par bloc/jours isolés (D136),
  props, règles métier, payload `blocks`/`solo`/`soloFamily`/`excluded`.
  **Branché** depuis D136 : endpoint réel, persistance (`AllocationFamily`,
  `DutyPattern.family`/`.recurring`), matérialisation réelle du calendrier
  (`WeeklyDutyCalendarService`, à la demande au préflight de génération).
- **`docs/fairness.md`** — `FairnessContext` (dimensions supportées/non
  supportées, `RequiredDemand`, `EffectiveExposure`, targets bruts et
  discrétionnaires, `STRUCTURALLY_FORCED`) et l'`OptimizationProblem`
  abstrait (mode `GENERATE` uniquement dans ce lot) construits au-dessus
  de l'`EligibilityMatrix` — toujours pas de solveur ni de
  `DutyAssignment` automatique.
- **`docs/planning-solver.md`** — interface `PlanningSolver`
  (`solve`/`checkFeasibility`), `OptimizationResult` (`SolverStatus`,
  `CoverageStatus`, jamais confondus), les 8 phases lexicographiques
  explicites de `GENERATE` (`ObjectivePhase`/`ObjectivePhaseFactory`), et
  `OrToolsPlanningSolver` (`src/Solver/`) — premier solveur réel (OR-Tools
  CP-SAT en subprocess Python, aucun binding PHP officiel n'existe),
  aucune dépendance OR-Tools dans le domaine. Bascule STRICT → PARTIAL
  automatique sur UNSAT prouvé (jamais sur UNKNOWN/ERROR), priorité
  CRITICAL, et `UnsatReport` — diagnostic UNSAT structuré (exclusions
  locales réelles, jamais de fausse causalité). `AssignmentConflict`
  (`CONFLICT` HARD, `TEAM_MIN_REST` POLICY_HARD) — première contrainte
  globale reliant deux `DutyUnit`, calculée dans le domaine
  (`AssignmentConflictAnalyzer`), jamais recalculée par Python ; la
  priorité CRITICAL est désormais réellement observable.
  `diagnosticRelaxations` peut désormais proposer réellement de relâcher
  `TEAM_MIN_REST`, uniquement après un vrai re-solve confirmant
  l'amélioration — `LEGAL_MIN_REST` reste HARD et ne peut structurellement
  jamais y apparaître. `MAX_DUTIES`/`MAX_WEEKENDS`/`MAX_CONSECUTIVE_NIGHTS`
  restent non implémentées malgré une configuration réelle existante.
  `FakePlanningSolver` réservé aux tests. `LEGAL_MIN_REST`/`TEAM_MIN_REST`
  sont des options par génération (`App\Entity\RestPolicyOptions`),
  jamais un défaut global d'équipe ni une durée devinée automatiquement
  (§36, D105). Depuis le Lot 6E : timeout CP-SAT réel et `numWorkers`
  réellement transmis via `SolverParameterSet` (versionné, système,
  jamais une constante cachée), `snapshotHash`/`seed` réels (calculés par
  `SnapshotHasher`/`SeedMaterialBuilder`, le seed reste non consommé par
  le solve — phase de tie-break toujours neutre), `algorithmVersion`
  versionné manuellement (`OptimizationProblemBuilder::ALGORITHM_VERSION`)
  — voir §37, `docs/decisions.md` D106.
- **`docs/authentication.md` §15** — inscription enrichie (téléphone E.164 ;
  l'hôpital n'est **pas** une donnée du profil, D115) et **invitations d'équipe** : `User` ≠ `TeamInvitation`
  (jamais de faux `User`), token haché à usage unique, inscription par lien
  atomique (multi-invitations → un `User`, N memberships, un email), compte créé
  entre-temps, emails (`backend/templates/email/`, maquette
  `docs/Design/emails_medvue`), variables d'env requises en production.
- **`docs/authentication.md` §16** — mot de passe oublié / réinitialisation :
  `PasswordResetToken` (token haché à usage unique, jamais de colonne sur
  `User`, D141), rate limiting IP+email consommé sans condition (jamais un
  oracle d'existence de compte), verrou de ligne pour la concurrence,
  `credentialsVersion` invalidant immédiatement tout JWT déjà émis (D142),
  révocation de toutes les familles de refresh tokens, token dans le
  fragment d'URL côté frontend.
- **`docs/planning-export.md`** — export PDF/Excel du calendrier courant
  d'un planning publié (D150) : source de vérité (`CurrentCalendarReader`),
  contrat `POST /api/plannings/{id}/export` (ordre = tableau `lines`, `to`
  exclusif), modèle intermédiaire `PlanningExportData` partagé par les deux
  renderers (dompdf, `openspout/openspout`), injection de formule, nom de
  fichier, dialogue, limites.
- **`docs/calendar-subscription.md`** — abonnement agenda de « Mes gardes »
  (D170) : flux iCalendar public par adresse secrète, contenu (lecture
  `MyDutiesService`), événements journée entière, `CalendarFeed`,
  endpoints, liens Google / Apple / Outlook, limites (délai de
  synchronisation propre à chaque agenda).
- **`docs/admin.md`** — administration de la plateforme (D174-D177) : rôle
  global `ROLE_PLATFORM_ADMIN` indépendant des équipes et sans droit sur les
  plannings, procédure d'attribution initiale, actions sur les comptes,
  définitions et sources de chaque métrique, rétention, journal d'audit,
  supervision en lecture seule, endpoints `/api/admin/*`.
- **`docs/duty-swaps.md`** — échanges de gardes entre membres (D178-D180) :
  une demande n'est jamais une source de vérité des titulaires, parcours
  convenu / recherche / toute la ligne, modèle (lignes d'affectation gelées,
  décideur dérivé), statuts, droits, contrôles à l'acceptation sur le
  calendrier final, historique append-only, emails et reprise, endpoints,
  concurrence, limites.
- **`docs/allocation-algorithm.md`** — design du moteur de répartition des
  gardes (équité multidimensionnelle, contraintes, pipeline de
  génération). **Document vivant** : encore conceptuel, à corriger et
  compléter à chaque étape d'implémentation réelle du moteur — jamais
  laisser le document diverger silencieusement du code une fois que
  celui-ci existe.
- **`docs/deployment.md`** — déploiement de production MedVue (séquence,
  checks de santé, mises à jour, interdits en production dont
  `docker compose down -v`, journal des incidents du premier déploiement).
  **`docs/backup.md`** — sauvegardes PostgreSQL + clés JWT, rétention,
  procédures de restauration et limites connues (D107/D108/D109).
- **`README.md`** — arborescence, choix techniques, commandes pour lancer
  le projet, URLs, tests exécutés.

## Conventions de travail sur ce projet

- **Aucune logique métier dans les contrôleurs** — elle vit dans des
  services dédiés (`src/Service/`). Les contrôleurs orchestrent
  (désérialisation, validation, appel au service, mise en forme de la
  réponse), rien de plus.
- **API Platform seulement là où c'est pertinent** — pas de ressource CRUD
  générée automatiquement sur une entité sensible (ex. `User`, voir
  `docs/decisions.md` D009) sans réflexion explicite sur ce qui doit
  réellement être exposé.
- **Un planning publié n'est jamais supprimé**, seulement archivé.
  L'historique des affectations n'est jamais recalculé depuis l'état
  courant — il doit rester disponible même si un membre quitte l'équipe,
  qu'une période est archivée, ou que les règles de score changent
  ensuite.
- **Progression incrémentale** : avant toute implémentation importante,
  analyser l'existant, proposer le modèle de données, identifier les
  contraintes, vérifier les impacts, implémenter, tester, documenter.
  Pas de refonte massive non justifiée.
- **Tests systématiques** pour toute fonctionnalité importante (unitaires
  + fonctionnels côté backend, au minimum le flux principal côté
  frontend), et cas limites couverts explicitement (voir la liste de la
  section 39 du cahier des charges original : membre ajouté/quitté en
  cours d'année, désactivation, impossibilité mathématique de remplir un
  planning, etc.) au moment où la fonctionnalité concernée est construite.

## Démarrer le projet

Voir `README.md` (`docker compose up -d --build`, URLs, commandes de
test/lint). Résumé : backend Symfony sur http://localhost:8010, frontend
React sur http://localhost:5183, PostgreSQL sur `localhost:5432`.
