# MedVue — Mes plannings (React + Vite)

Liste des plannings, responsive : sidebar ≥ 760px, en-tête + barre du bas sur smartphone.

## Lancer
```bash
npm install
npm run dev
```

## Fichiers
- `src/MesPlannings.tsx` — la page. Composant contrôlé : données + callbacks en props.
- `src/dates.ts` — formatage fr, durées inclusives, découpe par mois.
- `src/data.ts` — `plannings` (1 planning) et `manyPlannings` (4, pour tester les groupes).
- `src/types.ts` — `User`, `Planning`, `PlanningStep`, `PlanningsHrefs`.
- `src/mes-plannings.css` — styles (préfixe `mp-`), `src/tokens.css` — tokens du design system.
- `src/icons.tsx` — icônes (tracés Lucide).

## Comportement
- Statut calculé depuis les dates : *Commence dans N jours* / *En cours · jour X sur N* / *Terminé*.
- Compteur à droite (J-N, jours restants) — masqué sur mobile (redondant avec le badge).
- Frise par mois : partie écoulée remplie, trait « aujourd'hui » si en cours.
- Pied de carte : durée, lignes, membres, étape (`draft` → À générer, `collect` → Collecte des indispos ouverte, `published` → Publié).
- Plusieurs plannings → groupes En cours / À venir / Terminés (terminés du plus récent au plus ancien).
- État vide si `plannings` est vide.

## À brancher
- `hrefs` : remplacez les routes par défaut. Avec React Router, remplacez les `<a>` par `<Link>`.
- `onCreate` : ouvre la création de planning. `onLogout` : déconnexion.
- `step` : mappez depuis l'état réel de votre API.
