# CLAUDE.md — Calendrier de sélection multiple (Surgery Hub)

Lis `README.md` de ce dossier en entier avant d'écrire du code : il contient les mesures, les couleurs et l'algèbre de sélection.

## Ce qu'on te demande

Recréer le prototype `Calendrier Selection Multiple.dc.html` en **React + TypeScript** dans le codebase Surgery Hub (React + TS + MUI en production). Le HTML est une **référence de design**, pas du code à copier : il utilise un runtime de prototypage (`<x-dc>`, `<sc-for>`, `<sc-if>`, `<x-import>`) qu'il faut ignorer. Ce qui doit être porté fidèlement : l'algèbre de sélection, les états visuels, les tokens.

Fidélité attendue : **high-fidelity**. Couleurs, typos, espacements et rayons sont définitifs.

## Ordre de travail recommandé

1. `calendarAxis.ts` — génération de l'axe de mois (nom, nombre de jours, offset lundi-first, index de départ) + jours fériés. **Ne jamais coder les mois en dur** : la première version l'avait fait sur 4 mois et la pagination se bloquait au-delà.
2. `selection.ts` — module pur : `Range`, `Slot`, `merge`, `cut`, `add`, `toggle`. Aucune dépendance React.
3. `selection.test.ts` — voir la liste de cas ci-dessous. Faire passer les tests avant de toucher à l'UI.
4. `useDaySelection.ts` — hook d'état + gestes (`pointerdown/move/up`, `follow` avec temporisation).
5. `MonthGrid.tsx` / `DayCell.tsx` — vue mois, rail de deux mois.
6. `WeekGrid.tsx` — vue semaine, créneaux 30 min (garde) et jour complet (indisponibilité).
7. `SelectionSummary.tsx` — panneau latéral.
8. Écran mobile — mêmes composants, props de densité différentes ; pas de composant dupliqué.

## Règles non négociables

- **Une indisponibilité est toujours un jour entier.** Le type doit l'interdire : un `Slot` de type `indispo` est forcément `{ allDay: true }`. En vue semaine, le glisser vertical est désactivé pour cette nature.
- **Deux natures**, jamais fusionnées entre elles : `indispo` (rouge) et `garde` (vert marque).
- **Chaque clic-glisser ajoute une période**, il ne remplace jamais la sélection existante.
- **Dates en index absolus** en interne (axe continu de jours) ; conversion en `Date` seulement à l'affichage et aux frontières d'API. C'est ce qui rend les périodes inter-mois triviales.
- **Un seul jeu de gestes pour souris et tactile** (`pointer*`). Pas de branche `Ctrl`/`Shift` sur mobile : ces modificateurs sont simplement absents, aucun mode à activer.
- **Ne jamais colorer la cellule entière.** La lisibilité vient de la superposition : fond léger + filet haut/bas + pastille pleine aux extrémités + badge de période. Tester avec 15+ jours sélectionnés.
- **Aucun jour bloqué.** Week-ends et jours fériés sont teintés `--blue-50` (chiffre `--blue-700`) à titre informatif mais restent pleinement sélectionnables.
- **Les jours du mois précédent ne sont pas affichés** en tête de grille (cellules vides) ; ceux du mois suivant restent visibles en fin de grille, atténués.
- **Défilement automatique temporisé.** Pendant un glisser, atteindre le bord ne fait avancer le rail qu'après ~450 ms de maintien, puis ~900 ms entre deux avances, et **d'un seul mois à la fois**. Requis d'ergonomie : sans cela le calendrier saute plusieurs mois et le geste devient inutilisable.
- **Tokens du design system uniquement** — `var(--red-600)`, `var(--brand)`, `var(--border-subtle)`… jamais de hex en dur.
- **`font-variant-numeric: tabular-nums`** sur toutes les dates, heures, durées et compteurs.
- **Cibles tactiles ≥ 48px**, contrôles 48px, CTA primaires 56px.
- Copie en **français**, sentence case, pas d'emoji. Vocabulaire métier exact : mission, offre, instrumentiste, établissement, disponibilité, planning, encodage.

## Cas de test de `selection.ts`

- deux plages adjacentes de même nature → fusionnées en une ;
- deux plages adjacentes de nature différente → restent distinctes ;
- période à cheval sur deux mois → une seule plage continue ;
- période à cheval sur un changement d'**année** → une seule plage continue ;
- week-end ou jour férié traversé par un glisser → **inclus** dans la période, aucun traitement particulier ;
- retrait d'un jour au milieu d'une période → deux plages ;
- clic sur un jour déjà sélectionné de la même nature → retiré ;
- clic sur un jour sélectionné de l'autre nature → converti, pas retiré ;
- glisser d'une garde par-dessus une indisponibilité → la garde l'emporte sur la zone commune ;
- `merge` est idempotent et trie toujours par date de début.

## Ce qui reste à brancher (mocké dans le prototype)

- jours déjà occupés par une mission attribuée (nom d'établissement par date) ;
- calendrier des jours fériés belges (mockés jusqu'en août 2027) ;
- persistance de la sélection à la validation (`ranges` + `slots`).

Les données affichées (CHIREC, St-Jean, Brugmann, octobre 2026) sont des exemples.

## Questions ouvertes à trancher avec le designer

- `intensiteSelection` : le designer travaille en **`contrastée`** — est-ce le défaut à retenir ?
- Les tweaks du prototype (`intensiteSelection`, `afficherBadgesPeriodes`, `afficherMissionsExistantes`, `densite`) deviennent-ils des réglages produit ou des constantes ?
- Quelle fenêtre de navigation dans le produit : 14 mois comme le prototype, ou 18 mois glissants ?
- Faut-il réintroduire une notion de jour verrouillé (planning figé par l'établissement) ? Le point d'extension `LOCKED` est en place mais vide.

## Fichiers de référence

- `README.md` — spécification complète (layout, composants, mesures, tokens, interactions, state).
- `Calendrier Selection Multiple.dc.html` — prototype interactif. La logique est dans la classe `Component` en bas du fichier : `merge`, `cut`, `add`, `splitLocked`, `toggle`, `onDown`, `onMove`, `onGridMove`, `commit`, `follow`, `buildCells`.
- `_ds/surgery-hub-design-system-.../` — tokens et bundle du design system (`tokens/*.css`, `styles.css`, `_ds_bundle.js`).
- `support.js` — runtime du prototype. **Ne pas porter.**

Pour ouvrir le prototype : servir le dossier (`npx serve .`) et ouvrir `Calendrier Selection Multiple.dc.html`.
