# Semaine type — `WeekStructureEditor`

> **Statut (2026-09-23)** : composant frontend **livré et testé, pas encore
> branché**. Aucune page ne l'utilise, aucun endpoint back n'existe, rien
> n'est persisté. Ce document décrit le contrat du composant pour que
> l'intégration (paramètres d'une ligne de planning + endpoint
> `week-structure` → `DutyPattern`) puisse s'appuyer dessus. Décision : D134.

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

## 4. Payload (contrat avec le back, à venir)

`toPayload` / `fromPayload` font un aller-retour sans perte (testé pour les
5 presets). Un jour absent des trois listes est relu comme garde isolée ;
une lettre inconnue ou dupliquée est ignorée ; un bloc relu à un seul jour
est dissous.

```json
{
  "blocks":   [{ "id": "A", "name": "Week-end", "days": ["VEN", "SAM", "DIM"] }],
  "solo":     ["LUN", "MAR", "MER", "JEU"],
  "excluded": []
}
```

Correspondance prévue côté solveur (non implémentée) :

| Payload | Back |
|---|---|
| chaque entrée de `blocks` | un `DutyPattern` à N jours → une `DutyGroupInstance` par semaine, attribuée comme unité atomique (D052, `allocation-algorithm.md` §9) |
| chaque jour de `solo` | un `DutyPattern` à 1 jour |
| chaque jour de `excluded` | **aucune** `Duty` — absent de `requiredDemand` |

Endpoint prévu : `GET/PUT /api/planning-lines/{id}/week-structure`. Une
modification ne s'applique qu'aux **prochaines générations** — jamais aux
plannings déjà générés ou publiés.

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

## 7. Tests

```bash
cd frontend && npx vitest run src/features/week-structure
```

- `weeklyStructure.test.ts` : bloc V·S·D, bloc non consécutif, refus d'un
  bloc d'un jour, dissolution automatique, retrait sans dissolution, jour
  exclu, ordre et réutilisation des lettres, avertissements, renommage,
  aller-retour payload, forme du payload.
- `WeekStructureEditor.test.tsx` : résumé et 7 tuiles, création d'un bloc
  Ven + Dim via l'UI et payload émis, exclusion d'un jour + rappel,
  renommage et dissolution, annulation sans `onChange`, lecture seule,
  alerte « aucune garde ».

Non vérifié : rendu réel dans un navigateur (jsdom ne mesure pas les
largeurs — les tests tournent au palier `s`).

## 8. Reste à faire / questions ouvertes

1. Intégration dans les paramètres d'une ligne de planning
   (`PlanningSettingsModal` ou section dédiée) + état « modifié / enregistré ».
2. Endpoint `week-structure` et conversion en `DutyPattern` — à concevoir
   avec les `DutyPattern` existants (D052) : remplacement, versionnement,
   effet sur les générations futures uniquement.
3. Questions du designer (README du package) : jours fériés (réglage par
   ligne ?), blocs à cheval sur deux semaines, mesure de l'équité d'un bloc
   (chaque type de bloc équilibré séparément — cohérent avec l'équité
   multidimensionnelle — ou « bloc de 3 jours = 3 gardes » ?).
