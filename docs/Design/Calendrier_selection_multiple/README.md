# Handoff : Calendrier de sélection multiple (dates, périodes, créneaux)

## Overview

Écran de déclaration de disponibilités pour l'espace **instrumentiste** de Surgery Hub.
Un seul calendrier permet de déclarer, en desktop comme en mobile :

- une journée unique ;
- une période continue (ex. 12 → 16 octobre) ;
- plusieurs dates non consécutives (12, 15, 22 octobre) ;
- plusieurs périodes distinctes (12 → 14 et 20 → 22 octobre) ;
- une période à cheval sur deux mois (28 octobre → 3 novembre) ;
- des créneaux horaires (vue semaine) pour les **préférences de garde** uniquement.

Deux natures de sélection coexistent dans la même grille :

| Nature | Couleur | Granularité |
|---|---|---|
| **Indisponibilité** | rouge (`--red-*`) | **jour entier uniquement** |
| **Préférence de garde** | vert marque (`--green-*`) | jour entier ou créneau horaire |

> Règle métier importante : **une absence est toujours un jour entier.** Aucun créneau horaire n'est possible pour une indisponibilité — dans la vue semaine, un clic dans une colonne bloque le jour complet (et un second clic le libère). Le glisser vertical de 30 min n'est disponible qu'en mode « Préférence de garde ».

## About the Design Files

Le fichier livré (`Calendrier Selection Multiple.dc.html`) est une **référence de design réalisée en HTML** : un prototype interactif qui montre l'apparence et le comportement attendus. **Ce n'est pas du code de production à copier tel quel.**

La tâche consiste à **recréer ce design dans l'environnement existant du codebase cible** (React + TypeScript + MUI dans le cas de Surgery Hub), en utilisant ses patterns, ses composants et son thème. Si aucun environnement n'existe encore, choisir la stack la plus adaptée au projet et y implémenter le design.

Le HTML utilise un runtime de prototypage (balises `<x-dc>`, `<sc-for>`, `<sc-if>`, `<x-import>`) : **ignorez ce runtime**. Ce qui compte est (a) l'algèbre de sélection décrite plus bas, (b) les états visuels, (c) les tokens.

## Fidelity

**High-fidelity.** Couleurs, typographie, espacements, rayons, ombres et états sont définitifs et issus du design system Surgery Hub. L'UI doit être recréée fidèlement avec les composants existants du codebase (`Button`, `Switch`, `Tabs`, `AppBar`, `BottomNav`… ou leurs équivalents MUI thémés).

## Screens / Views

### 1. Desktop — vue « Mois » (écran principal)

**Purpose** — l'instrumentiste déclare ses indisponibilités et ses préférences de garde sur deux mois visibles simultanément.

**Layout**

- Page : fond `--surface-page` (`#F5F7FA`), padding `28px 24px 64px`, contenu centré `max-width: 1300px`, colonne `flex` avec `gap: 24px`.
- En-tête : titre `26px/800` + paragraphe `15px` (`max-width: 64ch`), et à droite le sélecteur de **nature de sélection**.
- Rangée de « cas d'usage » : boutons `height: 40px`, `radius: 12px`, `gap: 8px`, `flex-wrap`.
- Corps : `flex-wrap` avec deux zones — calendrier `flex: 1 1 640px` et panneau latéral `flex: 0 0 312px`. Les deux sont des cartes blanches `1px --border-subtle`, `radius: 12px`, `--shadow-sm`.

**Calendrier (carte)**

- Barre supérieure : `Tabs` (Mois / Semaine · horaires) à gauche ; à droite `‹`, libellé du rail (`min-width: 200px`, centré, tabular-nums), `›`. Flèches : `32×32`, `radius: 9px`, bordure `--border-default`, `opacity: .5` si désactivée (bornes de l'axe).
- **Libellé** : `Octobre — Novembre 2026` quand les deux mois partagent l'année, `Décembre 2026 — Janvier 2027` sinon (l'année apparaît des deux côtés au franchissement).
- Séparateur `1px --border-subtle`, puis padding `18px`.
- **Rail de mois** : conteneur `overflow: hidden` ; piste `display: flex`, largeur `N × 50%` (chaque panneau occupe `100/N` de la piste, soit la moitié de la fenêtre), `transform: translateX(-<page × 100/N>%)`, `transition: transform 200ms cubic-bezier(.22,1,.36,1)`, `align-items: flex-start`. Deux mois visibles à la fois.
- Chaque panneau de mois : titre `14.5px/800` + note `11.5px` ; ligne des jours (`LUN`…`DIM`) en `10.5px/700`, `letter-spacing: .06em`, uppercase, `--text-subtle`, centré.
- Grille : `display: grid; grid-template-columns: repeat(7, 1fr)`, bordure haut + gauche `1px --border-subtle`, `radius: 12px`, `overflow: hidden`, `touch-action: none`.

**Cellule de jour** (`position: relative`, hauteur **72 px** en densité confortable, **58 px** en compact)

- Bordures droite et bas `1px --border-subtle` ; fond `--surface-card`.
- Jours hors du mois affiché : `opacity: .62` (desktop) / `.4` (mobile).
- Numéro : `14px`, poids `500` (non sélectionné) / `700` (sélectionné), `margin: 7px 0 0 9px` (13px si début de période), tabular-nums.
- **Bande de sélection** (`div` absolu, `pointer-events: none`) : `top/bottom: 6px`, `background` = fond léger de la nature, `border-top/bottom: 1px` de la couleur de bord. `left: 6px` + rayons gauches si jour de début, sinon `left: -2px` (la bande se raccorde d'une cellule à l'autre) ; symétrique à droite. Date isolée : bordure complète `1px` + `radius: 10px`.
- **Marqueur de début / fin** : barre absolue `4px` de large, `top/bottom: 6px`, couleur `mark` de la nature, collée à `left: 6px` (début) ou `right: 6px` (fin), avec rayons `10px` du côté concerné.
- **Pastille du jour aux extrémités** : le numéro devient une pastille `25×25` (`26×24` en mobile), `radius: 8px`, fond = couleur `mark`, texte blanc `700`. C'est le marqueur principal de début et de fin — il fonctionne aussi bien sur une date isolée (les deux extrémités coïncident) que sur une période.
- **Badge de période** : uniquement sur le jour de début, et seulement s'il existe **plus d'une** période de la même nature. Position `bottom: 7px; right: 9px`, `padding: 0 4px`, `radius: 5px`, fond `--surface-card`, bordure `1px` couleur `mark`, texte `9px/800` couleur `mark`. Libellé `P1`, `P2`… pour les indisponibilités, `G1`, `G2`… pour les gardes.
- **Survol** (non sélectionné) : rectangle absolu `inset: 6px`, `radius: 10px`, fond `--surface-hover`, bordure `1px dashed --border-strong`.
- **Aperçu pendant le glisser** : mêmes bandes, mais `border-top/bottom-style: dashed`.
- **Jour déjà occupé** (mission attribuée) : `box-shadow: inset 3px 0 0 var(--blue-500)` + libellé de l'établissement en pied de cellule (`9.5px/700`, `--blue-700`, tronqué en ellipsis). Si le jour est aussi sélectionné, le libellé est réduit à un point `•` pour ne pas surcharger la bande.
- **Week-end et jour férié** : fond `--blue-50`, numéro en `--blue-700` poids `600`. Le nom du férié s'affiche en pied de cellule (`9.5px/700`, `--blue-600`). Ce sont des jours **entièrement sélectionnables** — la teinte est informative, pas bloquante. Survol sur ces jours : fond `--blue-100`, bordure `1px dashed var(--blue-500)`.
- **Cellules de tête de grille** : les jours du mois **précédent** ne sont pas affichés (cellule vide, sans bordure de sélection). Les jours du mois **suivant** en fin de grille restent affichés à `opacity: .62` (desktop) / `.4` (mobile).
- **Période continuant avant le panneau** : si le début de la plage est hors du mois affiché, le premier jour visible reçoit les rayons gauches + un `border-left: 1px dashed` de la couleur de bord, pour signaler la continuation.

**Légende** (sous la grille, `flex-wrap`, `gap: 8px 18px`, texte `11.5px/600 --text-muted`) — 7 entrées : Survolée · Date isolée · Période · Indisponibilité · Préférence de garde · Jour déjà occupé · Week-end · jour férié. Pastilles `17×17`.
*(Les entrées « Début de période » et « Fin de période » ont été retirées : la pastille pleine du jour suffit à les signaler.)*

**Ligne d'aide** — `12.5px/1.6 --text-subtle` : « Clic : ajouter ou retirer une journée · Clic-glisser : période continue — chaque glisser crée une période *supplémentaire* · En glissant au-delà du dernier jour affiché, le calendrier défile vers le mois suivant · Ctrl/Cmd + clic : retirer · Maj + clic : étendre la dernière plage ».

### 2. Desktop — vue « Semaine · horaires »

**Purpose** — poser des créneaux précis de préférence de garde, ou bloquer un jour complet en indisponibilité.

- Grille `grid-template-columns: 54px repeat(7, 1fr)`, bordure haut + gauche, `radius: 12px`.
- Gouttière horaire : 14 lignes de `44px` (07:00 → 20:00), fond `--surface-sunken`, texte `11px/600` tabular-nums aligné à droite, `border-bottom: 1px --border-subtle`.
- Colonnes de jour : hauteur `28 × 22px = 616px` (28 pas de 30 min), `cursor: ns-resize`, `border-right: 1px --border-subtle`. Fond en `repeating-linear-gradient` vertical : trait clair (`--gray-100`) à la demi-heure, trait `--border-subtle` à l'heure.
- Colonne de week-end ou de jour férié : même `repeating-linear-gradient` vertical, mais construit sur `--blue-50` / `--blue-100` au lieu de `--surface-card` / `--gray-100`. La colonne reste cliquable. L'entête du jour passe en `--blue-700`.
- **Bloc de créneau** : `position: absolute; left/right: 3px`, `radius: 9px`, `padding: 6px 8px`, fond léger de la nature, bordure `1px` couleur de bord, `border-left: 4px` couleur `mark`. Heure en `11.5px/800` (avec `padding-right: 22px` pour laisser la place au ×), sous-titre `10.5px/600`. Bouton de suppression `22×22`, `radius: 6px`, fond `rgba(255,255,255,.8)`, en haut à droite.
- **Bloc « journée entière »** : occupe toute la hauteur de la colonne, libellé « Journée entière » + « <nature> · jour complet ».
- **Mission déjà attribuée** : même géométrie mais fond `--status-info-surface`, bordure `--blue-100`, `border-left: 4px var(--accent)`, `pointer-events: none`.
- **Aperçu de glisser** : bordure `dashed`, libellé `hh:mm → hh:mm` + « Glissez pour ajuster ».
- Ligne d'aide adaptée à la nature active (texte exact dans la section Interactions).

### 3. Panneau latéral (récapitulatif)

- Carte `flex: 0 0 312px`, padding `18px`, colonne `gap: 14px`.
- Eyebrow « RÉCAPITULATIF » `11px/700`, `letter-spacing: .06em`, uppercase, `--text-subtle`.
- Compteur `30px/800`, `letter-spacing: -.03em`, couleur = `mark` de la nature active (ou `--text-subtle` si vide) ; titre `17px/700` à côté ; sous-titre `12.5px --text-muted`.
- Titres possibles : `N dates sélectionnées` (aucune période), `N périodes sélectionnées` (toutes les entrées sont des périodes), `N sélections` (mélange) avec sous-titre « X période(s) · Y date(s) isolée(s) · Z jours ». En vue semaine : `N créneaux sélectionnés` + « Sur K journée(s) · semaine du 12 au 18 octobre ».
- **Groupement par nature** : un sous-titre par nature (`11.5px/800`, uppercase, couleur `ink`, précédé d'un point `9×9`), libellé « Indisponibilité — 2 périodes · 6 jours » ou « — N dates ».
- Ligne d'élément : `flex`, `gap: 10px`, `padding: 9px 10px`, `radius: 12px`. Fond léger + bordure de la nature si c'est une période, sinon blanc + `--border-subtle`. Pastille `26×22`, `radius: 7px` portant `P1`/`G1` (fond `mark`, texte blanc) ou `·` (fond `--gray-100`). Libellé `13.5px/700` tabular-nums (`12 oct. → 14 octobre` ou `15 octobre`), sous-libellé `11.5px --text-muted` (`Lun. → Mer. · 3 jours`). Bouton `×` `26×26`, `radius: 8px`, hover `--status-crit-surface` / `--red-100` / `--red-700`.
- Zone scrollable `max-height: 340px`.
- **Vide** : encart `1px dashed --border-default`, `radius: 12px`, texte centré `13px --text-muted` : « Aucune date sélectionnée. Cliquez ou glissez dans le calendrier. »
- **Chevauchement** : encart `--status-info-surface` / `--blue-100` / texte `--blue-700` : « Chevauchement : le 8 oct., le 14 oct. comportent déjà une mission attribuée. La déclaration partira comme demande à confirmer. »
- **Aide contextuelle** : encart `--surface-sunken` / `--border-subtle`.
- Actions, de haut en bas : `Button secondary md full-width` → « + Ajouter une période » (« + Ajouter un autre créneau » ou « + Ajouter un jour complet » en vue semaine) ; `Button ghost sm full-width` → « Tout effacer » ; `Button primary lg full-width` → « Valider N jours » / « Valider N créneau(x) ».
- **Confirmation** : encart `--status-ok-surface` / `--green-200` / texte `--green-800/600` : « Déclaration transmise : 8 jour(s) sur 3 sélection(s). »

### 4. Sélecteur de nature de sélection (desktop)

Groupe segmenté dans une carte `padding: 4px`, `radius: 12px`, bordure `--border-subtle`. Deux boutons `height: 38px`, `padding: 0 14px`, `radius: 9px`, `gap: 8px`, point `9×9` en tête. Actif : fond léger de la nature, bordure `1px` couleur `mark`, texte `700` couleur `ink`. Inactif : transparent, `--text-muted`, point à `opacity: .45`.

### 5. Mobile (maquette 390 px)

- Cadre `width: 390px`, `radius: 28px`, `--shadow-lg`, bordure `--border-default`.
- Barre d'état factice `34px` (9:41 + batterie), puis `AppBar` du design system (titre « Disponibilités »), hauteur 56 px.
- Contenu : padding `14px 20px 0`, colonne `gap: 13px`.
- **Switch de nature** : bandeau `padding: 12px 14px`, `radius: 12px`, fond léger + bordure de la nature active. Composant `Switch` avec pour libellé la nature courante ; sous-texte `11.5px --text-muted` : « Les dates touchées seront déclarées comme non travaillables. » / « Les dates touchées seront proposées en priorité pour une garde. »
  *Le switch ne sert **pas** à choisir entre date isolée et période — les gestes s'en chargent.*
- **Navigation de mois** : `‹` + nom du mois `15px/700` + `›`.
- **Grille** : entêtes `L M M J V S D` `10.5px/700` centrés ; cellules `48px` de haut, sans bordures, numéro centré `14px`, pastille `26×24` aux extrémités de période, bande de sélection à bords francs (`left/right: 0`) pour les jours intermédiaires. Rail de mois identique au desktop (piste `width: 400%`, un mois visible).
- **Aide** `11.5px --text-subtle` : « Touchez une date pour l'ajouter ou la retirer · Restez appuyé et glissez pour tracer une période · Glissez jusqu'au bord pour passer au mois suivant ».
- **Chips de rappel** : pastilles `height: 30px`, `radius: 999px`, fond léger + bordure `mark` de la nature, texte `12px/700` couleur `ink`, `×` à droite. Tap = retrait de la période.
- **Barre d'action collante** (`position: sticky; bottom: 0`) : `padding: 12px 20px`, `border-top: 1px --border-subtle`, fond `--surface-card`, `--shadow-nav`. À gauche compteur `14px/800` (« 3 périodes sélectionnées » / « 4 dates sélectionnées » / « Aucune date sélectionnée ») + résumé `11.5px --text-muted` tronqué. À droite `Button ghost sm` « Effacer » + `Button primary md` « Continuer ». En dessous, le `BottomNav` du design system (4 entrées : Aujourd'hui, Offres, Planning, Profil ; `Planning` actif).

## Interactions & Behavior

### Desktop — vue mois

| Geste | Effet |
|---|---|
| Clic simple | Bascule la journée : l'ajoute si absente, la retire si déjà sélectionnée **de la même nature** ; la convertit si elle était de l'autre nature. |
| Clic + glisser | Trace une période continue. **Chaque glisser ajoute une période** au lieu de remplacer la sélection existante. |
| Ctrl/Cmd + clic | Retire la journée de la sélection. |
| Maj + clic | Étend depuis l'ancre (dernière journée touchée) jusqu'au jour cliqué, dans la nature active. |
| Glisser vers un jour du mois suivant/précédent | Le rail défile automatiquement (`follow()`), sans lâcher le bouton : une période peut franchir la frontière de mois. **Temporisation obligatoire** : il faut rester ~**450 ms** au-delà du bord avant que le rail avance, puis ~**900 ms** avant l'avance suivante, et le rail ne progresse que **d'un mois à la fois**. Sans cela le défilement saute plusieurs mois et devient inutilisable. Les compteurs (`_pendPage`, `_pendAt`, `_pageAt`) sont réinitialisés à chaque `pointerdown`. |
| `‹` / `›` | Pagination de deux mois affichés sur les 14 de l'axe (septembre 2026 → octobre 2027). |

Implémentation du glisser : `pointerdown` sur la cellule (avec `setPointerCapture`), `pointermove` sur la **grille** qui résout la cellule via `document.elementFromPoint` + `closest('[data-day]')` (indispensable pour que le geste tactile continue de suivre le doigt), `pointerup` global qui valide. Un glisser sans déplacement (`moved === false`) est traité comme un clic simple.

### Desktop — vue semaine

- **Préférence de garde** : glisser vertical dans une colonne → créneau au pas de 30 min ; un clic simple pose 30 min ; chaque nouveau glisser **ajoute** un créneau. Aide : « Glissez verticalement dans une colonne pour tracer un créneau (pas de 30 min) · Un clic simple pose 30 min · Chaque nouveau glisser ajoute un créneau · Les blocs bleus sont des missions déjà attribuées ».
- **Indisponibilité** : le glisser vertical est désactivé. Un clic dans la colonne pose un bloc **journée entière** ; un second clic sur le même jour le retire. Aide : « Une indisponibilité couvre toujours la journée entière : un clic dans la colonne bloque le jour complet, un second le libère. Passez en « Préférence de garde » pour tracer des créneaux horaires. »

### Mobile

Exactement les mêmes règles, sans clavier ni clic droit :

1. **Tap** = ajoute / retire la journée.
2. **Appui + glisser** = période continue (même code que le clic-glisser).
3. **Glisser vers le bord** = défilement vers le mois suivant, sans lâcher.
4. **Switch** = bascule Indisponibilité ↔ Préférence de garde.

Aucun mode « sélection multiple » à activer : la sélection multiple est l'état par défaut.

### Règles de sélection (algèbre)

À implémenter tel quel — c'est le cœur fonctionnel :

- Les journées sont manipulées comme des **index absolus** sur un axe continu de dates. L'axe est **généré**, pas codé en dur : 14 mois à partir de septembre 2026, chaque mois calculant son nombre de jours (`new Date(y, m + 1, 0).getDate()`) et son jour de départ lundi-first (`(new Date(y, m, 1).getDay() + 6) % 7`). C'est ce qui rend les périodes inter-mois triviales et la pagination illimitée. Dans le produit, générer l'axe sur la fenêtre réellement navigable (ex. 18 mois glissants).
- `merge(ranges)` : trie par date de début, puis fusionne deux plages **adjacentes ou chevauchantes uniquement si elles sont de même nature** (`r.s <= last.e + 1 && r.type === last.type`).
- `cut(ranges, a, b)` : retire l'intervalle `[a, b]` en scindant les plages traversées.
- `add(ranges, a, b, type)` = `merge(cut(ranges, a, b) ++ splitLocked(a, b, type))` — la nouvelle plage l'emporte toujours sur l'ancienne sur la zone commune (une garde tracée sur une indisponibilité la remplace).
- `splitLocked(a, b, type)` : découpe la plage autour des jours non déclarables, qui sont donc **exclus** d'une période qui les traverse (la période se scinde en deux). *Variante possible à confirmer : interrompre complètement le tracé.*
- Aperçu en cours de glisser : `add(ranges, drag.from, drag.to, drag.type)` calculé à la volée, sans muter l'état.

### Transitions

- Rail de mois : `transform 200ms cubic-bezier(.22,1,.36,1)` (`--dur-base` / `--ease-out`).
- Survol de cellule et boutons : `120ms` (`--dur-fast`).
- Respecter `prefers-reduced-motion` (le design system le prévoit).

## State Management

```ts
type Nature = 'indispo' | 'garde';

type Range = { s: number; e: number; type: Nature }; // index absolus de jours, inclusifs
type Slot  =
  | { d: number; type: Nature; allDay: true }
  | { d: number; type: Nature; s: string; e: string }; // 'HH:MM'

type State = {
  view: 'mois' | 'semaine';
  page: number;            // index du premier mois affiché (0..MONTHS.length - visible)
  type: Nature;            // nature active
  ranges: Range[];         // sélection jour(s) / période(s), toujours normalisée par merge()
  slots: Slot[];           // créneaux de la vue semaine
  anchor: number | null;   // ancre pour Maj + clic
  hover: number | null;    // jour survolé (desktop)
  drag: { from: number; to: number; type: Nature; moved: boolean } | null;
  // hors state React (refs), pour la temporisation du défilement automatique :
  // _pageAt: number | null — horodatage de la dernière avance
  // _pendPage: number | null — page visée par le survol de bord en cours
  // _pendAt: number | null — début du survol de bord
  tdrag: { d: number; a: number; b: number } | null; // glisser vertical (garde uniquement)
  hint: string | null;     // aide contextuelle du panneau
  validated: string | null;// message de confirmation
  preset: string;          // cas d'usage démontré (démo uniquement)
};
```

Transitions principales : `pointerdown` → `drag` ; `pointermove` → `drag.to` + `follow()` éventuel ; `pointerup` → `add()` puis `drag: null`. Toute mutation de sélection remet `validated` à `null`.

**Données à brancher** (mockées dans le prototype) :

- jours déjà occupés par une mission attribuée → nom d'établissement par date ;
- calendrier des **jours fériés** (belges) — mockés jusqu'en août 2027 dans le prototype ;
- persistance de la sélection (`ranges` + `slots`) à la validation.

Il n'existe **plus de notion de jour bloqué / non déclarable** : toutes les dates de l'axe sont sélectionnables. `LOCKED` est un tableau vide conservé comme point d'extension si le produit réintroduit un verrouillage de planning.

Aucune requête réseau n'est simulée ; « Valider » n'affiche qu'un message local.

## Design Tokens

Tous issus du design system Surgery Hub (`tokens/*.css`) — **utiliser les variables, pas les valeurs brutes**.

**Couleurs de nature**

| Rôle | Indisponibilité | Préférence de garde |
|---|---|---|
| fond doux (`band`) | `--red-50` | `--green-50` |
| bord doux (`edge`) | `--red-100` | `--green-200` |
| fond contrasté (option) | `--red-100` | `--green-100` |
| bord contrasté (option) | `--red-500` | `--green-400` |
| marqueur (`mark`) | `--red-600` | `--green-700` |
| texte (`ink`) | `--red-700` | `--green-800` |
| point de légende | `--red-600` | `--brand` (`#42A882`) |

**Surfaces & texte** — `--surface-page` (`#F5F7FA`), `--surface-card` (blanc), `--surface-sunken`, `--surface-hover`, `--border-subtle`, `--border-default`, `--border-strong`, `--text-strong`, `--text-body`, `--text-muted`, `--text-subtle`, `--white`.

**Accents** — `--accent` / `--blue-500` / `--blue-600` / `--blue-700` / `--blue-100` / `--status-info-surface` pour les missions existantes ; `--blue-50` + `--blue-100` + `--blue-700` pour les week-ends et jours fériés ; `--status-ok-surface` + `--green-200` pour la confirmation ; `--status-crit-surface` + `--red-100/700` pour le hover de suppression ; `--gray-100` pour les traits de demi-heure.

**Typographie** — Inter (`--font-sans`). Tailles utilisées : `9px`, `9.5px`, `10.5px`, `11px`, `11.5px`, `12px`, `12.5px`, `13px`, `13.5px`, `14px`, `14.5px`, `15px`, `17px`, `20px`, `26px`, `30px`. Poids `500 / 600 / 700 / 800`. Eyebrow : `11–12px`, `700`, `letter-spacing: .06em`, uppercase. **`font-variant-numeric: tabular-nums` sur toutes les dates, heures, durées et compteurs.**

**Espacements** — grille 4 px. Valeurs employées : `3, 4, 6, 7, 8, 9, 10, 12, 13, 14, 16, 18, 20, 24, 28, 64`.

**Rayons** — `5px` (badge), `7px` (pastille de liste), `8px` (pastille de jour, bouton `×`), `9px` (bloc horaire, segment), `10px` (bande de cellule), `12px` (`--radius-md`, défaut marque), `28px` (cadre mobile), `999px` (chips).

**Hauteurs clés** — cellule mois `72px` (confortable) / `58px` (compact) ; cellule mobile `48px` ; pas horaire `22px` (30 min) ; ligne d'heure `44px` ; colonne semaine `616px` ; app bar `56px` ; bottom nav `64px` ; cible tactile minimale **48px**.

**Ombres** — `--shadow-sm` (cartes), `--shadow-lg` (cadre mobile), `--shadow-nav` (barre d'action).

**Motion** — `--dur-fast: 120ms`, `--dur-base: 200ms`, `--ease-out: cubic-bezier(.22,1,.36,1)`.

## Tweaks exposés dans le prototype

Quatre options paramétrables, à conserver ou non selon le besoin produit :

| Prop | Valeurs | Effet |
|---|---|---|
| `intensiteSelection` | `douce` (défaut) / `contrastée` | Passe les fonds/bords de sélection au palier supérieur (`red-100`/`red-500`, `green-100`/`green-400`). *Le designer travaille actuellement en `contrastée` — à confirmer comme défaut.* |
| `afficherBadgesPeriodes` | `true` (défaut) / `false` | Affiche les badges `P1`/`G1` sur le jour de début quand il y a plusieurs périodes. |
| `afficherMissionsExistantes` | `true` (défaut) / `false` | Affiche les missions déjà attribuées (liseré bleu + établissement). |
| `densite` | `confortable` (défaut) / `compact` | Hauteur de cellule 72 px ou 58 px. |

## Assets

Aucune image. Les composants du design system utilisés sont `Button`, `Switch`, `Tabs`, `AppBar`, `BottomNav` (`window.DesignSystem_d99104.*`). Les icônes du design system sont **Lucide** dans le kit et **Material Icons** en production — aucune icône custom n'est introduite ici ; les flèches de mois sont les caractères `‹` et `›`, à remplacer par `chevron-left` / `chevron-right` dans le codebase.

Les données affichées (CHIREC, St-Jean, Brugmann, dates d'octobre 2026) sont des **données d'exemple**.

## Files

- `Calendrier Selection Multiple.dc.html` — le prototype complet (vue mois, vue semaine, panneau latéral, maquette mobile). La logique de sélection se trouve dans la classe `Component` en bas du fichier : `merge`, `cut`, `add`, `splitLocked`, `toggle`, `onDown`, `onMove`, `onGridMove`, `commit`, `follow`, `buildCells`. La génération de l'axe (`MONTHS`, `TOTAL`, `HOLIDAYS`) est en tête du même bloc.
- `_ds/surgery-hub-design-system-.../` — les tokens et le bundle du design system référencés par le prototype (`tokens/*.css`, `styles.css`, `_ds_bundle.js`).

## Implementation notes for Claude Code

1. Commencer par porter l'**algèbre de sélection** (`Range[]` + `merge`/`cut`/`add`) dans un module pur et la couvrir de tests : périodes adjacentes de même nature qui fusionnent, natures différentes qui ne fusionnent pas, période inter-mois, retrait au milieu d'une période.
2. Représenter les dates en index absolus en interne et ne convertir en `Date` qu'aux frontières (affichage, API).
3. Utiliser un seul gestionnaire `pointer*` pour souris et tactile — pas de branche `Ctrl/Shift` côté mobile, ces modificateurs sont simplement absents.
4. Ne pas colorer la cellule entière : l'effet de lisibilité vient de la superposition fond léger + filet + pastille pleine aux extrémités + badge. Tester avec 15+ jours sélectionnés.
5. Vérifier les contrastes : le texte sur fond `red-50` / `green-50` utilise `red-700` / `green-800` ; le texte blanc n'apparaît que sur les pastilles pleines `red-600` / `green-700` ; le bleu de week-end (`blue-50`) porte du `blue-700`.
7. La temporisation du défilement automatique (point 450/900 ms ci-dessus) est un **requis d'ergonomie**, pas un détail : la première version sans temporisation était injouable.
6. Rappel métier : **aucun créneau horaire pour une indisponibilité.** Le modèle doit l'interdire côté type (`Slot` pour une indisponibilité est forcément `allDay: true`).
