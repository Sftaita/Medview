# MedVue — Détail d'un planning (React + Vite)

Page `/plannings/:id` refondue : claire, responsive (sidebar ≥ 760px, barre du bas sur smartphone).

## Lancer
```bash
npm install
npm run dev
```

## Fichiers
- `src/PlanningDetail.tsx` — la page. Composant contrôlé : données + callbacks en props, état UI (onglet, menus, recherche, tri, feuille) en interne.
- `src/period.ts` — dates de la période calculées (format fr-BE, durée inclusive, découpe par mois, « Commence dans N jours » / « En cours · jour X sur N » dans le fuseau du planning).
- `src/data.ts` — données d'exemple. **Les noms non visibles sur les captures sont fictifs.**
- `src/types.ts` — `Planning`, `Line`, `Member`, `Collecte`.
- `src/planning.css` — styles (préfixe `pd-`), `src/tokens.css` — tokens du design system.
- `src/main.tsx` — démo locale ; remplacez les `console.log` par vos routes / appels API.

## Structure de la page
1. En-tête : retour Plannings, nom, statut, « Générer le planning » + menu (Modifier le nom, Paramètres).
2. **Période du planning** : Du / Au (inclus) en grand, durée, frise des mois, fuseau, « Prolonger la période ».
3. Étapes : Équipe → Indisponibilités → Génération (cliquables, ouvrent l'onglet).
4. Onglets : Lignes de garde · Indisponibilités · Planning.

## À brancher
- `planningView` : passez votre vue du planning généré (reçoit `memberId` et `monthIndex`) quand `planning.hasGeneration` est vrai.
- Collecte : `onOpenCollecte(deadline)`, `onRemind`, `onCloseCollecte` ; `collecte.respondedIds` pilote les statuts Répondu / En attente.
- La ligne `principale` n'est pas supprimable (pas de menu).
