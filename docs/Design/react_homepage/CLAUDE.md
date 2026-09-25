# CLAUDE.md — page d'accueil MedVue

Lis `README.md` avant de toucher au code.

## Règle absolue

La page d'accueil ne montre **que ce qui existe dans le produit**. Ne transforme jamais une capacité théorique du moteur, une donnée disponible dans le backend ou une évolution prévue en fonctionnalité visible.

Les noms et chiffres des maquettes sont fictifs ; les **comportements** montrés doivent exister.

### Ce qui existe et peut être montré
- Calendrier personnel d'indisponibilités : une date, plusieurs dates, une période ; ordinateur et smartphone.
- Préférence de garde **recueillie** (pas utilisée par la génération).
- Suivi de la collecte (qui a répondu, qui est en attente) et relance.
- Génération globale : éligibilité, indisponibilités, conflits de garde, contraintes de repos configurées, équité du moteur.
- Solution partielle et liste des gardes non couvertes.
- Réaffectation manuelle d'une garde.
- Statistiques : onglets « Cette période » (défaut) et « Cumul du planning », colonnes Lun → Dim, comptage par jour ISO réel.
- Plusieurs lignes de planning, nommées et configurées par l'équipe.

### Ce qu'il ne faut pas montrer
- Optimisation des préférences.
- Comparaison à la moyenne, score d'équité, colonnes fériés / charge globale / week-end.
- Explication par médecin d'une attribution ou d'une garde non couverte.
- Liste de remplaçants suggérés.
- Frise de participation, prorata, arrivées / départs à l'écran.
- Échanges de gardes, demandes de remplacement, notifications, export, synchronisation calendrier, application native.

## Conventions

- Contenu dans `content.ts`, présentation dans `HomePage.tsx`, styles dans `homepage.css`.
- **Tokens du design system uniquement** (`var(--brand)`, `var(--text-strong)`…) — aucune couleur en dur, sauf le fond `#16202B` de la bande sombre.
- Classes BEM courtes, états via attributs `data-*`.
- Responsive en CSS (media queries), pas de mesure JavaScript.
- Cibles tactiles ≥ 48 px sur mobile. Texte ≥ 11 px.
- Copie en français, sentence case, sans emoji, espace fine insécable avant `? : ; !`.
- Un seul H1.

## Si on te demande d'ajouter une fonctionnalité à la page

Demande d'abord si elle est livrée. Si la réponse n'est pas un oui clair, ne l'ajoute pas.
