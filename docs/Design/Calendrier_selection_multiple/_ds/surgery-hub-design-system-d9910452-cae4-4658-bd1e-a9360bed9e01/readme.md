# Surgery Hub — Design System

> Surgery Hub is a Belgian platform that connects **independent surgical instrument technicians (instrumentistes de bloc opératoire)** with healthcare facilities (hospitals, clinics) for one-off replacement missions. This design system covers the redesign target: the **instrumentiste space** — a mobile-first app used on a phone, often between two procedures or in the OR changing room.

This repository is the Surgery Hub design system: brand foundations, design tokens, reusable React components, and a high-fidelity mobile UI kit for the instrumentiste app.

---

## Provenance & status

Built to match the **production app** (React + TypeScript + MUI). Anchored to the real brand charter supplied by the team:

- **Brand green** `#42A882` (primary) → `#63C9A3` (light)
- **Page background** `#F5F7FA`
- **Rounded corners** 12px
- **Typeface** Inter
- Reference screenshots of the live screens (Aujourd'hui, Déclarer, Détail/Encodage, bottom-bar nav) were provided and informed the recreation.

**Design goal of the redesign:** a mobile-first instrumentiste experience — bottom-bar navigation, clear visual hierarchy, big touch targets, and legibility in OR conditions (gloves, fast glances), while keeping the existing color charter.

**Substitutions to confirm:**
- **Logo** is an original placeholder mark (no official logo was supplied).
- **Icons** use [Lucide](https://lucide.dev) (the production app uses MUI Icons — see Iconography).
- **Fonts**: Inter is loaded from Google Fonts; self-host for offline use.

---

## Product represented

**Instrumentiste app** (`ui_kits/instrumentiste/`) — mobile-first, French. Surfaces covered:
- **Aujourd'hui** — day view: mission(s) of the day, quick access to encoding, declare-a-mission, available offers.
- **Offres** — proposed missions to accept/decline.
- **Mes missions** — upcoming, to-encode, and history.
- **Planning** — calendar of availability/missions.
- **Notifications** — alerts (mission assigned, encoding reminder…).
- **Déclarer une mission** — report an availability or an off-platform mission.
- **Détail mission** — facility, hours, specialty, access to encoding.
- **Encodage** — post-procedure entry (interventions, hours).
- **Profil** — personal info, specialties, documents.

---

## CONTENT FUNDAMENTALS — how Surgery Hub writes

**Language: French.** Voice is direct, clear, and reassuring — software used around clinical work, so copy is calm and action-oriented.

- **Tone:** factual and helpful. State the situation and the next step: *"Mission attribuée. Pensez à encoder avant 22h."* — not hype, not alarm.
- **Address:** warm but professional. Greets by first name (*"Bonjour, Thomas !"*); instructions use the imperative or *vous* (*"Pensez à…"*, *"Déclarez…"*). The exclamation in the greeting is the one allowed flourish.
- **Casing:** sentence case for everything — buttons (*Prendre*, *Refuser*, *Encoder la mission*, *Terminer l'encodage*), titles, nav. Uppercase only for the small tracked eyebrow label (e.g. *MISSION DU JOUR*).
- **Domain vocabulary (keep exact):** mission, offre, instrumentiste, bloc opératoire, établissement / site, chirurgien, encodage, intervention, matériel, disponibilité, planning, heures prestées, à encoder, attribuée.
- **Numbers & times** use Inter tabular figures: `10h00 → 20h00`, `Durée : 11h00`, `Mar. 16 juin`. Belgian/French time format (`10h00`, not `10:00 AM`).
- **Emoji:** none. **Exclamation points:** only the greeting.
- **Errors → guidance:** *"Encodage incomplet. Ajoutez le matériel utilisé."* — never a bare *"Erreur : champ obligatoire manquant."*

See the **Voix & ton** card.

---

## VISUAL FOUNDATIONS

**Register:** clean, calm, trustworthy, mobile-first. White cards on a cool light-gray page, brand green for identity and primary actions, a blue accent reserved for the featured/active mission.

**Color**
- **Brand green** (`--brand` = `--green-500` `#42A882`): primary buttons, active nav, identity, "prendre", success. Hover `--green-600`, active `--green-700`.
- **Blue accent** (`--accent` = `--blue-600`): the *Mission du jour* hero gradient, info banners, "à venir" status, secondary emphasis. Not a primary action color.
- **Neutrals — cool gray** (`--gray-*`): chrome, text, borders. Page background is `--gray-50` `#F5F7FA`.
- **Mission status** travels as fg/surface pairs: *proposée* (green), *à venir* (blue), *en cours* (green + pulse), *à encoder* (amber), *terminée* (gray), *refusée* (red). See **Mission status** card.

**Type** — Inter throughout. Mobile body 15px (`--text-base`), inputs/comfortable 16px. Screen titles 26px/800 (*"Bonjour, Thomas !"*). Data (times, durations, dates) uses Inter **tabular numerals**.

**Spacing & layout** — 4px grid. Mobile primitives: app bar 56px, bottom nav 64px, screen gutter 20px. **Touch targets ≥ 48px** (gloves, quick taps). Default control height 48px; primary CTAs 56px.

**Corners & borders** — **12px is the brand default** (`--radius-md`) for cards, inputs, buttons. Pill only for tags, chips, status pills, toggles, and badges. Hairline borders (`--border-subtle`) do most separation.

**Elevation** — soft, low-spread shadows tinted with gray. Cards mostly sit on a hairline border; elevation is reserved for the featured hero, sheets, toasts, and the bottom nav (upward shadow `--shadow-nav`).

**Backgrounds** — `--gray-50` page, white cards. The one gradient in the system is the **blue *Mission du jour* hero** (`--blue-600 → --blue-700`); used once, deliberately. No decorative gradients elsewhere.

**Motion** — quick and confident: `--dur-fast 120ms` / `--dur-base 200ms`, easing `cubic-bezier(0.22,1,0.36,1)`. Fades and short slides; the only loop is the gentle pulse on the *en cours* status dot. Respects `prefers-reduced-motion`.

**Interaction states** — hover: subtle surface tint / one step darker on filled buttons. Press: filled buttons darken + nudge 0.5px (cards scale 0.995). Focus: visible 3px green ring (`--focus-ring`), never removed. Selected/active nav: brand green.

**Cards** — white, `1px --border-subtle`, `--radius-md`. Mission cards carry a thin left **status accent bar**; the offer card puts the site name in brand green; the featured mission is a full blue gradient surface.

---

## ICONOGRAPHY

- **Library here:** [Lucide](https://lucide.dev) via CDN, outline, ~1.75px stroke, 18–24px.
- **Production note / substitution:** the live app is MUI, which ships **Material Icons**. Lucide is used in this kit as a clean, license-friendly stand-in with a similar outline weight. If you standardize on MUI Icons, map names 1:1 (e.g. `calendar-days`→`CalendarMonth`, `tag`→`LocalOffer`, `file-text`→`Description`, `house`→`Home`).
- **Usage:** render `<i data-lucide="name"></i>` then call `lucide.createIcons()` (the kit re-runs this after each navigation).
- **Common glyphs:** `house`, `calendar-days`, `tag`, `bell`, `user-round`, `stethoscope`, `scissors`, `file-text`, `clipboard-list`, `map-pin`, `clock`, `plus`, `plus-circle`, `hourglass`, `badge-check`, `settings`, `log-out`, `chevron-left/right`. See the **Iconographie** card.
- **No emoji, no unicode glyphs as icons.** The logo mark is the only bespoke vector (`assets/logo/`).

---

## Index / manifest

**Root**
- `styles.css` — global entry (import manifest only). Consumers link this.
- `readme.md` — this file. · `SKILL.md` — Agent-Skill wrapper.

**`tokens/`** (all `@import`ed by `styles.css`)
- `fonts.css` (Inter) · `colors.css` · `typography.css` · `spacing.css` · `effects.css` · `base.css`

**`guidelines/`** — specimen cards (Design System tab)
- Colors: brand green · neutrals · mission status · surfaces+text
- Type: display · body · data · weights+eyebrow
- Spacing: scale · radius · elevation
- Brand: logo · iconographie · voix & ton

**`components/`** — React primitives (`window.DesignSystem_d99104.*`)
- `forms/`: **Button**, **IconButton**, **Input**, **Select**, **Checkbox**, **Switch**
- `feedback/`: **Badge**, **StatusPill** (mission states), **Toast**
- `data-display/`: **Card**, **Avatar**, **StatTile**, **Tag**
- `navigation/`: **Tabs**
- `mobile/`: **AppBar**, **BottomNav**, **MissionHero**, **MissionCard**, **OfferCard**, **SectionHeader**, **InfoBanner**, **EmptyState**, **ListRow**
- Each has `.jsx` + `.d.ts` + `.prompt.md`; each directory has one `@dsCard` demo HTML.

**`ui_kits/instrumentiste/`** — the mobile app (interactive). See its `README.md`.

**`assets/`** — `logo/mark.svg` · `logo/mark-mono.svg`

---

## Using this system

```html
<link rel="stylesheet" href="styles.css">
<script src="_ds_bundle.js"></script>
<script>
  const { Button, MissionHero, StatusPill } = window.DesignSystem_d99104;
</script>
```

Prefer **semantic tokens** (`--text-body`, `--surface-card`, `--brand`, `--accent`, `--status-warn-fg`) over raw scale values. Use **StatusPill** for any mission state, the mono-tabular Inter for all schedule/metric data, and keep tap targets ≥ 48px.

---

## CAVEATS — please confirm

1. **Logo is a placeholder.** Send the official Surgery Hub logo and I'll swap it everywhere.
2. **Icons are Lucide** (CDN). If the app should mirror MUI Material Icons exactly, I'll switch the set and the mapping.
3. **The app is MUI in production.** These components are framework-agnostic CSS-token primitives that *match* the look; they are not drop-in MUI theme overrides. If you want a **MUI theme** (palette + typography + component overrides) generated from these tokens, I can produce that next.
4. **Screens are a redesign proposal.** I improved hierarchy, spacing, and the featured-mission hero while keeping your charter. Tell me which screens to push further or pull back.
5. **Content is sample data** (Belgian sites, French copy). Share real establishment names / specialty taxonomy if you want them reflected.
