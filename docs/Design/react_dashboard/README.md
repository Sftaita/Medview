# MedVue — Tableau de bord (React + Vite)

Page d'accueil refondue, responsive : sidebar ≥ 760px, en-tête + barre du bas sur smartphone.

## Lancer
```bash
npm install
npm run dev
```

## Fichiers
- `src/Dashboard.tsx` — la page. Composant contrôlé : données + callbacks en props.
- `src/dates.ts` — formatage fr-BE (« Jeu. 1 oct. 2026 », « Ven. 20 → dim. 22 nov. »), durées inclusives, découpe par mois, « Dans N jours ».
- `src/data.ts` — données d'exemple.
- `src/types.ts` — `User`, `PlanningSummary`, `Unavailability`, `DashboardHrefs`.
- `src/dashboard.css` — styles (préfixe `db-`), `src/tokens.css` — tokens du design system.
- `src/icons.tsx` — icônes (tracés Lucide).
- `src/main.tsx` — démo locale (date figée au 26/09/2026).

## Comportement
- **Mes plannings** : statut calculé (Commence dans N jours / En cours · jour X sur N / Terminé), frise par mois, indisponibilités de l'utilisateur en marques rouges sur la frise.
- **Mes indisponibilités** : seules les périodes à venir (fin ≥ aujourd'hui), triées ; la prochaine porte « Dans N jours ». État vide si aucune.
- Mobile : libellés raccourcis (« Tout voir », dates sans jour de semaine, sans nombre de membres) via `.db-long` / `.db-short`.

## À brancher
- `hrefs` : remplacez les routes par défaut (`/plannings`, `/mes-gardes`, `/mes-indisponibilites`…) par les vôtres. Si vous utilisez React Router, remplacez les `<a>` par `<Link>`.
- `onDeclare` : ouvre votre formulaire de déclaration.
- `onLogout` : déconnexion.
- `PlanningSummary.published` : affiche le lien « Voir mes gardes » quand le planning est publié.
