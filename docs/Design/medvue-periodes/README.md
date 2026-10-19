# MedVue — Périodes successives (composants React)

Composants React + TypeScript pour gérer plusieurs périodes successives dans un planning MedVue.
Spécification fonctionnelle : document « MedVue — Spécification des périodes successives ».

## Lancer la démo

```bash
npm install
npm run dev
```

La barre noire en haut permet de passer Trauma Delta de 1 à 2, 4 ou 8 périodes. La date du jour est figée au 10 oct. 2026 (`src/data/mock.ts`).

## Contenu

```
src/
  types.ts                      Planning, Period, Collecte, Shift…
  lib/dates.ts                  Dates ISO sans fuseau, formats FR, temps écoulé, découpage par mois
  lib/periods.ts                Règles : en cours + suivante, fenêtre de frise, début automatique, validation
  components/
    StatusPill.tsx              Statut de période (couleur + forme de pastille)
    TimeBar.tsx                 Barre de temps + barre par mois
    PlanningCard.tsx            Carte « Mes plannings » (période en cours + suivante uniquement)
    PeriodSelector.tsx          Frise (ordinateur) / flèches + mini-frise (mobile), menu Historique
    PeriodDetailCard.tsx        Grande carte de la période sélectionnée
    PreparationSteps.tsx        Collecte → Génération → Publication
    PeriodTabs.tsx              Calendrier des gardes / Disponibilités
    CreatePeriodDialog.tsx      « Créer la période suivante » (fenêtre, feuille sur mobile)
  pages/
    PlanningsPage.tsx
    PlanningDetailPage.tsx      Assemble tout, gère la sélection, la création, le message de confirmation
  data/mock.ts                  Données fictives (à remplacer par l’API)
  styles/tokens.css             Jetons MedVue (couleurs, typo, rayons)
  styles/periodes.css           Styles des composants, responsive à 720 px
```

## Intégration dans MedVue

- Copiez `src/components`, `src/lib`, `src/types.ts` et `src/styles/periodes.css`. Si vos jetons existent déjà, ne reprenez pas `tokens.css`.
- `PlanningDetailPage` accepte `onCreatePeriod(draft)` et `onOpenCollecte(period)` : appelez votre API et renvoyez la période enregistrée (avec son id serveur).
- `getShifts(planning, period)` fournit les gardes de l’onglet calendrier.
- Remplacez `today` par la date du jour dans le fuseau du planning (`Europe/Brussels`), au format `AAAA-MM-JJ`.
- Les statuts (`preparation`, `collecte`, `generee`, `publiee`, `archivee`) sont des libellés de maquette : alignez-les sur votre backend.

## Règles clés (voir `lib/periods.ts`)

- La nouvelle période commence le lendemain de la fin de la dernière ; durée minimale 7 jours.
- Créer une période ne modifie jamais les précédentes.
- « Mes plannings » montre seulement la période en cours et la suivante.
- Au-delà de 4 périodes, la frise montre la précédente, l’en cours et les suivantes ; le reste est dans « Historique ».
