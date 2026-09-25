# Semaine type — `WeekStructureEditor`

> **Statut (2026-09-24)** : **branché de bout en bout** (docs/decisions.md
> D136). Endpoint réel `GET/PUT /api/planning-lines/{id}/week-structure`
> (`WeekStructureController`/`WeekStructureService`), intégré dans
> `PilotHeaderActions` (bouton « Semaine type », ligne principale
> uniquement — voir la dette D136 pour les lignes secondaires), et
> réellement consommé par la génération : `PlanningGenerationLauncher::preflight()`
> matérialise le calendrier de `Duty` à la demande
> (`WeeklyDutyCalendarService`) avant tout solve. Le composant lui-même
> reste inchangé dans son contrat visuel D134 — seule une famille d'équité
> par bloc/par jours isolés a été ajoutée (§4 ci-dessous).

Source de design : `docs/Design/react_semaine_type/` (README, prototype
visuel `prototype/Semaine type.dc.html`, exemple d'intégration
`example/SettingsPage.tsx`). Le prototype n'est qu'une référence visuelle :
son runtime (`<x-dc>`, `<sc-for>`) n'est pas porté.

## 1. Rôle

Éditer la **structure hebdomadaire** d'une ligne de planning : pour chacun
des 7 jours (lundi → dimanche), dire si c'est

| Rôle | Sens métier |
|---|---|
| `solo` — « Garde isolée » | une unité à attribuer, un seul jour |
| `block` — « Bloc A…D » | membre d'un bloc ; le bloc entier est attribué **d'un seul tenant** à une seule personne (unité atomique) |
| `none` — « Pas de garde » | le jour **n'existe pas dans la demande** : aucune `Duty` créée. Ce n'est ni une indisponibilité, ni une garde facultative |

## 2. Emplacement et API

```
frontend/src/features/week-structure/
├── weeklyStructure.ts            # logique pure (types, opérations, sérialisation), sans React
├── weeklyStructure.test.ts       # 12 tests de la logique (repris tels quels du design)
├── WeekStructureEditor.tsx       # composant contrôlé
├── WeekStructureEditor.test.tsx  # 7 tests d'interaction (RTL)
├── WeekStructureEditor.css       # styles .wse__* (tokens globaux uniquement)
├── useElementWidth.ts            # largeur réelle du composant → paliers
└── index.ts                      # exports publics
```

```tsx
import { WeekStructureEditor, fromPayload, preset } from '../week-structure'
import type { WeekStructure, WeekStructurePayload } from '../week-structure'

const [structure, setStructure] = useState<WeekStructure>(() =>
  saved ? fromPayload(saved) : preset('vsd'),
)

<WeekStructureEditor
  value={structure}
  onChange={(next, payload) => {
    setStructure(next)
    setDirty(payload) // payload prêt pour le PUT
  }}
/>
```

| Prop | Type | Défaut | Rôle |
|---|---|---|---|
| `value` | `WeekStructure` | — | structure affichée — composant **contrôlé** |
| `onChange` | `(next, payload) => void` | — | appelé à chaque modification, avec la structure et sa forme sérialisée |
| `title` | `string` | « La semaine type » | titre de la carte |
| `readOnly` | `boolean` | `false` | tuiles, champs et actions désactivés ; la sélection est ignorée |

La **sélection** de jours est le seul état interne : elle n'a pas de sens
hors du composant et n'est jamais remontée. Elle est vidée après chaque
action.

Fonctions pures exportées (toutes immuables) : `allSolo`, `preset(id)`
(`vsd`, `vd`, `nosun`, `two`, `solo` — libellés dans `PRESETS`),
`createBlock`, `setSolo`, `setNone`, `dissolveBlock`, `renameBlock`,
`members`, `soloDays`, `excludedDays`, `unitsPerWeek`, `dutyDaysPerWeek`,
`canCreateBlock`, `warnings`, `toPayload`, `fromPayload`.

## 3. Règles métier (dans `weeklyStructure.ts`, jamais dans le JSX)

- Un bloc réunit **au moins 2 jours**, consécutifs ou non (vendredi +
  dimanche sans le samedi est valide).
- Un jour appartient à **un seul** bloc ; l'ajouter à un nouveau bloc le
  retire de l'ancien.
- Un bloc tombé à un seul jour est **dissous automatiquement** ; ce jour
  redevient une garde isolée.
- Lettres **A → D**, première libre d'abord (au plus 3 blocs coexistent en
  pratique : 7 jours ÷ 2).
- La semaine va toujours du **lundi au dimanche** : un bloc dimanche + lundi
  relie deux jours de la même semaine, jamais un dimanche au lundi suivant.
- Avertissements (`warnings`) : aucune garde générée (`warn`, `role="alert"`),
  bloc de 5 jours ou plus (`warn`), rappel de la nature d'un jour exclu
  (`info`).

## 4. Payload (contrat avec le back, réel — docs/decisions.md D136)

`toPayload` / `fromPayload` font un aller-retour sans perte (testé pour les
5 presets). Un jour absent des trois listes est relu comme garde isolée ;
une lettre inconnue ou dupliquée est ignorée ; un bloc relu à un seul jour
est dissous ; un `family`/`soloFamily` absent (ancien payload) est relu
comme chaîne vide, jamais une erreur.

```json
{
  "blocks":     [{ "id": "A", "name": "Week-end", "family": "Week-end", "days": ["VEN", "SAM", "DIM"] }],
  "solo":       ["LUN", "MAR", "MER", "JEU"],
  "soloFamily": "Semaine",
  "excluded":   []
}
```

`family` (par bloc) / `soloFamily` (commune à tous les jours isolés d'une
ligne, jamais un jour isolé individuellement — voir D136) sont un texte
libre, saisi via un champ « Équilibrer avec » ajouté à chaque bloc et à la
section des jours isolés (§6). Une chaîne vide signifie « pas de famille »
— jamais devinée. Le back fait un *get-or-create* par équipe sur un code
normalisé du libellé (`AllocationFamily`, D136) : deux blocs/jours tapés
avec le même libellé partagent la même famille, deux libellés
différents (même proches) créent deux familles distinctes — pas de
correspondance floue en v1.

Correspondance réelle côté back (`WeekStructureService::replace()`) :

| Payload | Back |
|---|---|
| chaque entrée de `blocks` | un `DutyPattern` neuf (`recurring = true`) à N composants → une `DutyGroupInstance` matérialisée chaque semaine par `WeeklyDutyCalendarService`, attribuée comme unité atomique (D052, `allocation-algorithm.md` §9) |
| chaque jour de `solo` | un `DutyPattern` neuf à 1 composant (`recurring = true`) → une `Duty` isolée par semaine |
| chaque jour de `excluded` | **aucune** `Duty` — absent de `requiredDemand` |

`GET/PUT /api/planning-lines/{id}/week-structure`
(`WeekStructureController`), réservé au créateur du Planning ou à un
OWNER/ADMIN de l'équipe de la ligne (`PlanningVoter::MANAGE_LINE_STRUCTURE`).
Chaque `PUT` remplace **atomiquement** toute la structure : les patterns
`recurring` actifs de l'équipe sont désactivés (jamais supprimés, jamais
mutés) et remplacés par des patterns neufs — c'est ce remplacement, à lui
seul, qui garantit qu'une modification ne s'applique jamais qu'aux
**prochaines générations** : une `Duty` déjà matérialisée garde pour
toujours sa propre référence, inchangée.

Matérialisation réelle : `WeeklyDutyCalendarService::ensureMaterialized()`
est appelée par `PlanningGenerationLauncher::preflight()` pour chaque ligne
active avant de compter ses `Duty` — idempotente (jamais une semaine
dupliquée), sans effet tant qu'aucune structure `recurring` n'est
configurée. Chaque garde matérialisée couvre le jour calendaire entier
(00:00 → 00:00 le lendemain) — aucune heure de garde n'est demandée par ce
lot, `DutyType` n'en porte aucune (dette, §8).

## 5. Affichage

Paliers calculés sur la **largeur du composant** (`useElementWidth` +
`ResizeObserver`), jamais sur la fenêtre ni en media queries ; posés en
`data-tier` sur la racine, tout le reste est en CSS.

| Palier | Largeur | Affichage |
|---|---|---|
| `l` | ≥ 820 px | nom complet, rôle en toutes lettres, cercle de sélection |
| `m` | 480–819 px | « Lun », étiquette d'une lettre (G, lettre du bloc, —), double contour |
| `s` | < 480 px | initiale, boutons 48 px (`--tap-min`) en 2 × 2, champs 48 px |

Teintes par bloc : A vert (brand), B bleu, C gris, D ambre — variables
locales `--tone-*` dérivées des tokens globaux, aucune couleur en dur.

## 6. Adaptations par rapport au package de design

- **Pas de MUI** (le package le supposait) : les boutons utilisent les
  classes `.btn` de `styles/ui.css` (`btn--primary` « Créer un bloc »,
  `btn--secondary` « Garde isolée »/« Pas de garde », `btn--ghost`
  « Annuler », `btn--danger btn--sm` « Dissoudre »). Hauteur 48 px au
  palier `s` imposée par `WeekStructureEditor.css`.
- **`tokens.css` du package non copié** : toutes les variables utilisées
  existent déjà dans `styles/tokens.css`, chargé globalement.
- **Imports de types** en `import type` (`verbatimModuleSyntax`).
- **Accessibilité** : chaque tuile porte un `aria-label` explicite
  (« Vendredi, bloc A (Week-end) ») — sans lui, le nom accessible était la
  concaténation brute des spans (« VVendrediGarde isolée ») et se réduisait
  à l'initiale en palier `s`. Tuiles en `<button aria-pressed>`, rails de
  blocs `aria-hidden`, focus visible, `prefers-reduced-motion` respecté.
- **`readOnly`** : la sélection est dérivée (ignorée) au lieu d'être vidée
  par un `useEffect` (règle oxlint `set-state-in-effect`).
- **Famille d'équité (D136)** : un champ « Équilibrer avec » (texte libre,
  classe `.wse__input` réutilisée) ajouté à chaque bloc et à la section des
  jours isolés — la seule interaction nouvelle qu'a justifiée la notion de
  famille, comme prévu au §3 du cahier des charges du lot.

## 7. Tests

```bash
cd frontend && npx vitest run src/features/week-structure
```

- `weeklyStructure.test.ts` (18 tests) : bloc V·S·D, bloc non consécutif,
  refus d'un bloc d'un jour, dissolution automatique, retrait sans
  dissolution, jour exclu, ordre et réutilisation des lettres,
  avertissements, renommage, aller-retour payload, forme du payload,
  classement en famille d'équité (bloc + jours isolés), famille vide =
  aucune famille, famille absente du payload relue comme vide.
- `WeekStructureEditor.test.tsx` (9 tests) : résumé et 7 tuiles, création
  d'un bloc Ven + Dim via l'UI et payload émis, exclusion d'un jour +
  rappel, renommage et dissolution, annulation sans `onChange`, lecture
  seule (y compris les champs de famille), alerte « aucune garde »,
  classement en famille via l'UI.
- `WeekStructureModal.test.tsx` (4 tests, nouveau) : chargement de la
  structure courante, erreur de chargement sans jamais ouvrir l'éditeur,
  erreur d'enregistrement sans jamais fermer la modale, annulation sans
  appel réseau.
- Backend (`WeekStructureServiceTest`, `WeeklyDutyCalendarServiceTest`,
  `WeekStructureControllerTest`, `DimensionMembershipCalculatorTest`) :
  voir docs/decisions.md D136 pour la liste complète des scénarios A–H.

Non vérifié : rendu réel dans un navigateur (jsdom ne mesure pas les
largeurs — les tests tournent au palier `s`).

## 8. Reste à faire / questions ouvertes

1. ~~Intégration dans les paramètres d'une ligne de planning~~ — fait
   (D136) : bouton « Semaine type » dans `PilotHeaderActions`, mais
   **seulement pour la ligne principale** — aucune UI ne permet encore de
   choisir une ligne secondaire (l'API accepte pourtant n'importe quel
   `stableId` de ligne). Aucun scénario réel testé n'a de ligne secondaire
   configurée différemment ; à étendre le jour où un vrai besoin apparaît.
2. ~~Endpoint `week-structure` et conversion en `DutyPattern`~~ — fait
   (D136) : `WeekStructureController`/`WeekStructureService`, remplacement
   atomique complet à chaque `PUT`, effet sur les générations futures
   uniquement (`DutyPattern.recurring`, jamais muté en place).
3. Questions du designer (README du package), toujours ouvertes : jours
   fériés (réglage par ligne ?), blocs à cheval sur deux semaines. La
   mesure de l'équité d'un bloc est en revanche tranchée par D136 :
   « famille d'équité » configurable par bloc/jours isolés, jamais
   « bloc de N jours = N gardes » comptées comme famille (la famille
   compte 1 par unité, les dimensions calendaires comptent, elles, par
   jour réel — voir D136 Scénario F).
4. Heure de garde configurable (créneaux autres que jour calendaire
   plein 00:00→00:00) — non demandée par ce lot, non construite
   (dette D136).
5. Exceptions calendaires datées (ex. dimanche 25 décembre = garde
   exceptionnelle malgré une structure « dimanche = aucune garde ») —
   point d'extension documenté par D136, rien d'implémenté.
