# Semaine type — composant React

Éditeur de la **structure hebdomadaire** d'une ligne de planning MedVue : chaque jour est une garde isolée, membre d'un bloc attribué d'un seul tenant, ou sans garde.

```
react_semaine_type/
├── src/
│   ├── weeklyStructure.ts        # logique pure, sans React — types, opérations, sérialisation
│   ├── weeklyStructure.test.ts   # tests Vitest de la logique
│   ├── WeekStructureEditor.tsx   # composant contrôlé
│   ├── WeekStructureEditor.css   # styles (dépend des tokens du design system)
│   ├── useElementWidth.ts        # largeur réelle du composant, pour les paliers
│   ├── tokens.css                # copie des tokens, si l'app ne les charge pas encore
│   └── index.ts                  # exports publics
├── example/SettingsPage.tsx      # intégration type : lecture, édition, enregistrement
├── prototype/Semaine type.dc.html# référence visuelle et comportementale
└── CLAUDE.md                     # consignes pour Claude Code
```

Aucune dépendance hors React 18+. TypeScript strict.

## Utilisation

```tsx
import { WeekStructureEditor, preset } from '@/features/week-structure';
import '@/features/week-structure/tokens.css'; // seulement si le design system n'est pas déjà chargé

const [structure, setStructure] = useState(() => preset('vsd'));

<WeekStructureEditor
  value={structure}
  onChange={(next, payload) => {
    setStructure(next);
    // payload est prêt à être envoyé au back
  }}
/>
```

### Props

| Prop | Type | Défaut | Rôle |
|---|---|---|---|
| `value` | `WeekStructure` | — | Structure affichée (composant **contrôlé**) |
| `onChange` | `(next, payload) => void` | — | Appelé à chaque modification |
| `title` | `string` | « La semaine type » | Titre de la carte |
| `readOnly` | `boolean` | `false` | Désactive la sélection et les actions |

La sélection en cours est un état **interne** : elle n'est pas remontée, elle n'a pas de sens hors du composant.

## Modèle de données

```ts
type DayRole = { mode: 'solo' } | { mode: 'block'; block: 'A'|'B'|'C'|'D' } | { mode: 'none' };

interface WeekStructure {
  days: [DayRole × 7];            // 0 = lundi … 6 = dimanche
  blocks: { id; name }[];
}
```

**Payload** envoyé au back, via `toPayload` — et relu via `fromPayload` :

```json
{
  "blocks":   [{ "id": "A", "name": "Week-end", "days": ["VEN", "SAM", "DIM"] }],
  "solo":     ["LUN", "MAR", "MER", "JEU"],
  "excluded": []
}
```

### Correspondance côté solveur

| Payload | Côté back |
|---|---|
| chaque entrée de `blocks` | un `DutyPattern` à N jours ; chaque semaine génère **une** `DutyGroupInstance`, attribuée comme une unité atomique |
| chaque jour de `solo` | un `DutyPattern` à 1 jour |
| chaque jour de `excluded` | **aucune** `Duty` créée — le jour n'entre pas dans `requiredDemand` |

Un jour exclu n'est ni une indisponibilité, ni une garde facultative : il n'existe pas dans la demande.

## Règles métier

- Un bloc réunit **au moins 2 jours**, consécutifs ou non (vendredi + dimanche sans le samedi est valide).
- Un jour appartient à **un seul** bloc. L'ajouter à un nouveau bloc le retire de l'ancien.
- Un bloc tombé à un seul jour est **dissous** automatiquement ; ce jour redevient une garde isolée.
- Lettres **A → D**, attribuées dans l'ordre, la première libre d'abord. En pratique 3 blocs au plus coexistent (7 jours ÷ 2).
- Avertissements : aucune garde générée ; bloc de 5 jours ou plus ; rappel de la nature d'un jour exclu.
- Une semaine va toujours du **lundi au dimanche** : un bloc dimanche + lundi relie deux jours d'une même semaine, pas un dimanche au lundi suivant.

## Responsive

Les paliers dépendent de la **largeur du composant**, pas de la fenêtre — il se comporte pareil dans une colonne latérale ou en pleine page.

| Palier | Largeur | Affichage |
|---|---|---|
| `l` | ≥ 820 px | nom complet, rôle en toutes lettres, cercle de sélection |
| `m` | 480–819 px | « Lun » centré, étiquette d'une lettre (G, lettre du bloc, —), double contour |
| `s` | < 480 px | initiale, cases resserrées, boutons 48 px en 2 × 2, champs 48 px |

Le composant pose `data-tier` sur sa racine ; tout le reste est en CSS.

## Accessibilité

- Chaque jour est un `<button aria-pressed>` : lecteur d'écran et clavier (Tab, Espace) fonctionnent sans code supplémentaire.
- En paliers `m` et `s`, l'étiquette d'une lettre porte `aria-label` avec le rôle complet.
- Les rails de blocs sont décoratifs (`aria-hidden`) : l'information est portée par les tuiles et la liste des blocs.
- Les avertissements bloquants ont `role="alert"`.
- Anneau de focus visible (`--focus-ring`), `prefers-reduced-motion` respecté.

## Intégration MUI

Les boutons sont de simples `<button>` stylés pour ne pas imposer de dépendance. En production, remplacez-les par le `Button` MUI thémé (voir le commentaire dans `WeekStructureEditor.tsx`). Les classes `.wse__*` restent valables pour tout le reste.

## Tests

```bash
npx vitest run src/weeklyStructure.test.ts
```

Couvrent : bloc V·S·D, bloc non consécutif, bloc refusé à un jour, dissolution automatique, retrait sans dissolution, jour exclu, ordre et réutilisation des lettres, avertissements, renommage, aller-retour payload sans perte pour les 5 modèles.

## Questions ouvertes

1. **Jours fériés** — faut-il un réglage par ligne (jour normal · comme un dimanche · rattaché au bloc voisin · pas de garde) ?
2. **Blocs à cheval sur deux semaines** (dimanche + lundi suivant) — utile pour certaines équipes ?
3. **Mesure de l'équité** — chaque type de bloc est équilibré séparément, le total de jours sert d'arbitre. Faut-il une option « un bloc de 3 jours = 3 gardes isolées » ?
