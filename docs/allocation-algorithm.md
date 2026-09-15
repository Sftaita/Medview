# Algorithme de répartition des gardes

> **Document vivant.** Rien de ce qui est décrit ici n'est implémenté à ce
> jour — c'est le design de référence du moteur de génération, écrit avant
> le code pour aligner les décisions de modélisation en amont (méthode de
> la section 38 du cahier des charges). Il sera corrigé et complété à
> chaque étape d'implémentation réelle : quand une section devient vraie
> dans le code, elle passe de ⏳ à ✅ et toute divergence entre ce document
> et le code constaté doit être corrigée ici, pas ignorée. Voir le
> changelog en fin de fichier.

## 0. Où ça s'implémentera

`src/Service/` ne contient aujourd'hui que `UserRegistrationService`. Rien
de ce qui suit n'existe encore dans le code. Ce document précède
l'implémentation des équipes et des plannings (`docs/decisions.md`
D001-D018 ne couvrent que le socle et l'authentification).

---

## 1. Objectif du moteur

Le moteur ne doit jamais chercher seulement à produire un planning rempli.
Un planning produit doit être :

- **valide** — aucune contrainte dure violée ;
- **équitable** — sur plusieurs dimensions séparées, pas un score unique ;
- **explicable** — chaque attribution doit pouvoir être justifiée ;
- **historiquement cohérent** — s'appuie sur l'historique réel, jamais
  recalculé depuis l'état courant ;
- **espacé** — les gardes d'un même utilisateur évitent de se concentrer ;
- **respectueux** des indisponibilités (toujours) et des préférences
  (autant que possible) ;
- **stable dans le temps** — une régénération ne doit pas repartir de
  zéro ni écraser silencieusement des ajustements manuels.

## 2. Principe non négociable : l'équité est multidimensionnelle

Rappelé dans `CLAUDE.md` — jamais optimiser un score global unique. Un
utilisateur sans week-end pourrait compenser par de nombreuses petites
gardes de semaine ; le moteur doit équilibrer **chaque catégorie
séparément**, selon les règles propres à chaque équipe (D012 : ces règles
sont portées par l'équipe, pas globales à l'application).

### Dimensions suivies par utilisateur, par période d'équité

| Dimension | Description |
|---|---|
| Score global | Somme pondérée, indicative — jamais le seul critère d'arbitrage |
| Nombre total de gardes | |
| Nombre de week-ends (groupes) | Compte le *groupe* (D. `DutyPattern`), pas les jours isolés |
| Nombre de vendredis / samedis / dimanches | Suivis séparément même quand ils font partie d'un groupe |
| Nombre de jours fériés | **+ type exact** (Noël, Nouvel An, Pâques, …) — historique multi-années indépendant du reset annuel du score général (cf. §5) |
| Nombre de nuits / autres catégories spécifiques à l'équipe | Optionnel, selon `DutyType` définis par l'équipe |

## 3. Facteur de participation

Tous les membres n'ont pas forcément la même charge cible
(`TeamMember.participationFactor`). Les objectifs par utilisateur sont
proportionnels à ce facteur, sur chaque dimension du §2 — pas seulement
sur le score global.

**Exemple** (total à répartir : 50 points, A = 1, B = 1, C = 0.5) :

```
poids total = 1 + 1 + 0.5 = 2.5
objectif A = 50 × (1 / 2.5)   = 20
objectif B = 50 × (1 / 2.5)   = 20
objectif C = 50 × (0.5 / 2.5) = 10
```

Le même principe s'applique aux week-ends si l'équipe l'active.

## 4. Période d'équité vs période de planning

Une `FairnessPeriod` (ex. année civile) peut couvrir plusieurs
`PlanningPeriod` successives (ex. janvier-avril, mai-juillet,
août-décembre). Chaque nouvelle génération doit tenir compte de **toutes**
les gardes déjà attribuées dans la `FairnessPeriod` en cours, pas
seulement de la `PlanningPeriod` qu'on génère — sinon l'équilibrage
recommence artificiellement à zéro à chaque période et ne converge jamais
sur l'année.

## 5. Historique des jours fériés : au-delà du reset annuel

Le score général peut se remettre à zéro à chaque nouvelle
`FairnessPeriod`, mais l'historique des jours fériés **nommés** (pas juste
« un jour férié ») doit rester consultable sur plusieurs années : si
Utilisateur A a eu Noël en 2027, le moteur doit chercher à éviter de lui
réattribuer Noël en 2028 si d'autres candidats compatibles existent — même
si le score général de 2027 a été remis à zéro entre-temps.

## 6. Espacement

À équité comparable, préférer étaler les gardes d'un utilisateur (ex. 3,
17, 31 mai) plutôt que les concentrer (3, 4, 5 mai). Le moteur doit éviter,
si possible : deux week-ends consécutifs, deux blocs trop proches, trois
jours consécutifs hors groupe volontairement lié, une garde juste avant/
après un week-end complet, une concentration disproportionnée sur une
courte période.

## 7. Contraintes dures vs souples

| Dures (jamais violées) | Souples (best effort, écarts rapportés) |
|---|---|
| Indisponibilité déclarée | Équité globale multidimensionnelle |
| Utilisateur non membre / inactif | Équilibre des week-ends |
| Hors période de planning | Équilibre des jours fériés (nommés) |
| Conflit avec une garde incompatible | Espacement (§6) |
| Jours liés (`DutyPattern`) non respectés | Préférences utilisateur (`PREFER_DUTY`) |
| Maximum absolu défini par l'équipe | Éviter deux week-ends consécutifs |
| Règle d'exclusion obligatoire de l'équipe | Éviter la répétition d'un jour férié nommé |
| | Minimiser les concentrations |

Le moteur doit pouvoir **expliquer** quelles contraintes souples n'ont pas
pu être parfaitement respectées pour un planning donné (§9).

## 8. Préférences positives (`PREFER_DUTY`)

Augmentent la probabilité d'attribution et peuvent assouplir la règle
d'espacement, mais ne doivent **jamais** : violer une indisponibilité,
violer une contrainte dure, provoquer un déséquilibre majeur si une
meilleure solution existe.

## 9. Ordre de priorité par défaut (configurable par équipe)

1. Respecter les contraintes dures.
2. Équilibrer les groupes de garde (jours liés).
3. Équilibrer les week-ends.
4. Équilibrer les jours fériés (nommés).
5. Équilibrer le score global.
6. Maximiser l'espacement.
7. Respecter les préférences positives.
8. Minimiser les écarts secondaires.

Chaque équipe doit pouvoir reconfigurer les poids relatifs (§10) — cet
ordre est le défaut de l'application, pas une constante en dur.

## 10. Paramètres configurables par équipe

Score des types de garde, groupes de jours liés, poids de l'équité
globale / des week-ends / des jours fériés, importance de l'espacement,
maximum de gardes sur une période, minimum de jours entre deux gardes,
autoriser ou non deux week-ends consécutifs, politique de report annuel
(`FairnessPeriod.carryOverPolicy`), validation admin des échanges,
deadline des campagnes.

**Implication modèle de données** : ces poids doivent vivre sur l'entité
`Team` (ou une entité `TeamFairnessRules` dédiée) — pas en constantes
applicatives. À trancher au moment de modéliser `Team`.

## 11. Snapshot à la génération

Au moment `T` où un planning est généré, le moteur fige un **snapshot**
exact des indisponibilités/préférences de chaque membre (état + dates de
création/modification à `T`). Les modifications futures ne réécrivent
jamais ce snapshot. Une indisponibilité ajoutée après `T` :

- ne modifie jamais automatiquement le planning déjà généré ;
- est détectée en conflit avec une garde déjà attribuée si applicable, et
  signalée explicitement (utilisateur + admins des équipes concernées) —
  jamais un échec silencieux.

## 12. Architecture des services envisagée (backend)

Aucun de ces services n'existe encore. Un seul par responsabilité, jamais
de logique dans les contrôleurs (`CLAUDE.md`) :

| Service | Responsabilité |
|---|---|
| `PlanningGenerationService` | Orchestre le pipeline complet (§13), point d'entrée unique |
| `EligibilityService` | Exclut les candidats non éligibles à une garde donnée (contraintes dures) |
| `FairnessService` | Calcule charges actuelles vs objectifs théoriques, par dimension (§2-3) |
| `SpacingService` | Score l'espacement d'un candidat pour une garde donnée (§6) |
| `HolidayFairnessService` | Historique multi-années des jours fériés nommés (§5) |
| `DutyGroupingService` | Construit les groupes de jours liés (`DutyPattern`) comme unités logiques |
| `PreferenceService` | Intègre les `PREFER_DUTY` sans jamais violer une contrainte dure |
| `ConstraintService` | Évalue dures/souples pour un couple (candidat, garde) |
| `PlanningOptimizationService` | Choisit l'affectation finale parmi les candidats éligibles |
| `PlanningExplanationService` | Produit, pour chaque garde, les données ayant motivé le choix (§9 du principe d'explicabilité) |

## 13. Étapes du pipeline de génération

1. Charger la `PlanningPeriod`.
2. Charger les membres actifs de l'équipe (facteur de participation
   inclus).
3. Construire le snapshot des indisponibilités/préférences (§11).
4. Construire toutes les gardes de la période (par `DutyType`).
5. Construire les groupes de jours liés (`DutyPattern`).
6. Charger l'historique de la `FairnessPeriod` en cours (§4).
7. Charger l'historique multi-années des jours fériés nommés (§5).
8. Calculer les charges actuelles par dimension (§2).
9. Déterminer les objectifs théoriques par utilisateur (§3).
10. Exclure les candidats non éligibles (`EligibilityService`).
11. Optimiser la répartition (§14).
12. Minimiser les déséquilibres résiduels.
13. Maximiser l'espacement à équité comparable.
14. Respecter les préférences positives dans la marge laissée par 11-13.
15. Produire le rapport d'explication (`PlanningExplanationService`).

## 14. Approche algorithmique envisagée — **non tranchée**

Deux familles d'approches, à choisir à l'implémentation (l'issue sera
actée dans `docs/decisions.md`, pas seulement ici) :

- **Heuristique gloutonne priorisée** — pour chaque garde (dans un ordre
  qui traite d'abord les plus contraintes : jours fériés nommés, groupes
  week-end, puis le reste), sélectionner le candidat éligible qui
  minimise l'écart aux objectifs (§9) à cet instant, avec repli
  (`backtracking`) limité si un choix local rend une garde ultérieure
  insoluble. Simple à implémenter et à expliquer (chaque choix est un
  calcul local traçable), mais peut converger vers un optimum local
  seulement.
- **Solveur de satisfaction/optimisation de contraintes** (ex. un moteur
  CP-SAT type OR-Tools, piloté depuis PHP via un microservice ou un appel
  externe) — modélise tout le problème (variables, contraintes dures,
  fonction objectif multi-critère pondérée) et cherche un optimum global.
  Résultat potentiellement meilleur, mais plus complexe à opérer
  (dépendance externe, temps de calcul moins prévisible) et l'explicabilité
  (§9) demande un travail spécifique (un solveur ne "raconte" pas ses
  choix nativement).

**Recommandation de départ** (à confirmer avant implémentation) :
commencer par l'heuristique gloutonne priorisée — elle couvre l'exigence
d'explicabilité nativement et suffit tant que les équipes restent de
taille raisonnable (quelques dizaines de membres). Basculer vers un
solveur seulement si des cas réels démontrent que l'heuristique ne trouve
pas de solution alors qu'une existe (§15).

## 15. Cas limites identifiés (à couvrir par des tests quand implémenté)

Membre ajouté/quittant en cours d'année, changement de
`participationFactor` en cours de période, utilisateur sans disponibilité
renseignée, utilisateur totalement indisponible, **impossibilité
mathématique de remplir le planning** (le moteur doit le signaler
explicitement, jamais produire un planning invalide en silence), plusieurs
utilisateurs ex æquo, groupe week-end impossible à attribuer, jour férié
inclus dans un week-end, changement de règles après publication,
absence ajoutée après génération (§11), échange créant un conflit,
régénération après modification manuelle (gardes verrouillées jamais
touchées), prolongation d'une `PlanningPeriod`, équipe inactive,
utilisateur désactivé (cohérent avec D012/D015 : un utilisateur désactivé
ne doit plus être candidat, mais son historique reste intact).

## 16. Régénération et verrouillage manuel

`DutyAssignment.source` distingue `AUTO` / `MANUAL` / `SWAP`.
`DutyAssignment.locked` protège une garde d'une régénération. Une
régénération peut porter sur : tout le planning, une sous-période, les
gardes non verrouillées seulement, les gardes non attribuées seulement —
jamais un écrasement silencieux d'une modification manuelle.

---

## Historique des révisions

| Version | Date | Changement |
|---|---|---|
| v0.1 | 2026-09-15 | Première version : design conceptuel complet, rien d'implémenté. Reprend et structure les sections 7, 11-17, 23-24, 34-35, 39-40 du cahier des charges initial. |
