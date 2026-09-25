# MedVue — page d'accueil (React)

Page d'accueil de MedVue, fidèle au produit actuellement implémenté. React 18 + TypeScript + Vite, **aucune dépendance** hors React.

```
react_homepage/
├── index.html               # <title>, meta description, favicon, police Inter
├── public/
│   ├── favicon.svg
│   └── logo/mark.svg
├── src/
│   ├── main.tsx
│   ├── HomePage.tsx         # la page, découpée en sections
│   ├── content.ts           # tous les textes et données d'exemple
│   ├── homepage.css         # styles (tokens du design system)
│   └── tokens.css           # copie des tokens — à retirer si le DS est déjà chargé
├── reference/medvue-accueil.html   # maquette validée, en un seul fichier
└── CLAUDE.md
```

## Lancer

```bash
npm install
npm run dev      # http://localhost:5173
npm run build    # dist/
```

## Modifier les textes

Tout le contenu est dans `src/content.ts` : liens, lignes du hero, données du calendrier, statistiques, étapes, FAQ. Les textes longs des sections restent dans `HomePage.tsx`, au plus près de leur mise en forme.

Typographie française : une **espace fine insécable** (`NB`, U+202F) précède `? : ; !` et borde l'intérieur des guillemets « ». Conservez-la en ajoutant du texte.

## Liens à brancher

Dans `content.ts` → `links` : `/connexion`, `/inscription`, `/contact`, `/mentions-legales`, `/confidentialite`. À aligner sur vos routes Symfony.

## Référencement

- Le `<title>` et la meta description sont dans `index.html`.
- Le **H1** est unique : « Le planning de gardes médicales, enfin simple. »
- Rendu côté client : les moteurs l'indexent, mais un **pré-rendu statique** est préférable pour une page d'accueil. Options simples : `vite-plugin-prerender`, ou rendre la page depuis un gabarit Twig en réutilisant `homepage.css`.

## Responsive

Tout en CSS, sans JavaScript de mesure :

| Largeur | Effet |
|---|---|
| ≥ 1060 px | navigation de sections visible dans l'en-tête |
| ≤ 720 px | les 5 étapes passent en colonne |
| ≤ 480 px | boutons de l'en-tête à 48 px (cible tactile) |
| ≤ 400 px | le mot « MedVue » s'efface, le logo reste |

Les deux tableaux (statistiques, lignes de planning) défilent horizontalement sur petit écran plutôt que d'être tassés.

## Accessibilité

- Onglets des statistiques en `role="tablist"` / `tab` / `tabpanel`, `aria-selected`.
- FAQ : `<button aria-expanded aria-controls>`, réponses masquées par `hidden`.
- Tableaux sémantiques (`<table>`, `<th scope>`).
- Focus visible partout, `prefers-reduced-motion` respecté.

## Règle de contenu

La page ne présente que des fonctionnalités **existantes**. Avant d'ajouter une section ou une maquette, vérifiez que le comportement est livré dans le produit. Voir `CLAUDE.md`.
