# CLAUDE.md — Semaine type

Lis `README.md` avant d'écrire du code.

## Ce qu'on te demande

Intégrer `WeekStructureEditor` dans le front React + TypeScript + MUI de MedVue, dans les paramètres d'une ligne de planning, et brancher l'enregistrement.

Le composant est **déjà écrit** et testé. Ne le réécris pas : déplace `src/` dans `src/features/week-structure/` (ou l'équivalent du projet), puis adapte.

## Étapes

1. Copier `src/` dans le projet. Lancer `npx vitest run` : tout doit passer avant de toucher quoi que ce soit.
2. Si le design system est déjà chargé globalement, **ne pas** importer `tokens.css`.
3. Remplacer les quatre `<button className="wse__btn">` et `Dissoudre` par le `Button` MUI thémé du projet. Conserver les libellés exacts et la hauteur 48 px au palier `s`.
4. Créer la page ou la section de paramètres en suivant `example/SettingsPage.tsx`.
5. Côté back : endpoint `GET/PUT /api/planning-lines/{id}/week-structure` qui accepte le payload décrit dans le README, et le convertit en `DutyPattern`.

## Règles non négociables

- **Composant contrôlé.** `value` + `onChange`. Pas d'état de structure interne en dehors de la sélection.
- **La logique reste dans `weeklyStructure.ts`**, pure et testée. Aucune règle métier dans le JSX.
- **Un jour exclu (`excluded`) ne crée aucune `Duty`.** Pas une indisponibilité, pas une garde facultative.
- **Un bloc = une unité atomique** : une seule personne le couvre en entier.
- **Paliers sur la largeur du composant** (`useElementWidth`), jamais sur `window` ni en media queries.
- **Cibles tactiles ≥ 48 px au palier `s`.**
- **Tokens du design system uniquement** — aucune couleur en dur.
- Copie en français, sentence case, sans emoji. Libellés exacts : « Garde isolée », « Pas de garde », « Créer un bloc », « Dissoudre ».

## Ne pas faire

- Ne pas porter le runtime du prototype (`<x-dc>`, `<sc-for>`) : `prototype/` n'est qu'une référence visuelle.
- Ne pas autoriser plus de 4 lettres, ni un bloc d'un seul jour.
- Ne pas modifier les plannings déjà publiés quand la structure change : elle ne s'applique qu'aux prochaines générations.

## Questions à remonter au designer

Voir la section « Questions ouvertes » du README : jours fériés, blocs à cheval sur deux semaines, mesure de l'équité.
