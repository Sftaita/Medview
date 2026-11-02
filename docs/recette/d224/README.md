# Recette D224 — Lignes de renfort à blocs hérités (Première ligne → Fell/Ass)

Preuves de la PR #34 (`docs/decisions.md` D224). Rien n'a été écrit en
production : aucune garde, aucune génération, aucune migration.

## 1. Copie isolée de la production (sur le serveur, 2026-11-02)

Restauration jetable de `medvue_20261102_164106.dump` (sauvegarde vérifiée de
la production après le déploiement `v2026.11.02-prod`) : PostgreSQL en mémoire,
réseau Docker `--internal` (aucune route vers la production ni vers Internet),
image de production avec le code de la branche en lecture seule, secrets
jetables, mailer `null://`. Rien n'a été téléchargé ; l'environnement a été
supprimé ensuite et la production vérifiée inchangée (migration
`Version20261102090000`, santé OK).

- **Migration `Version20261103090000`** : 10 instructions, toutes additives, aucune
  instruction destructrice ; 0 des 2 711 lignes de 68 tables modifiée ; `down`
  puis `up` sans perte.
- **Cas réel Trauma Delta** — « Première ligne » publiée (120 gardes), « Fell/Ass »
  (6 membres). Les déclencheurs réels n'existant pas en production, une
  configuration **illustrative** a été saisie sur trois chirurgiens réels de la
  Première ligne (vendredi + samedi + dimanche + mardi ; samedi ; vendredi +
  dimanche) :
  - ouvrir le formulaire et prévisualiser : 0 écriture ;
  - enregistrer sur ce planning à plusieurs périodes dont la Première ligne est
    publiée : accepté (`INHERITED`, hérite de Première ligne) ; semaine type propre
    refusée (`structure_inherited`) ;
  - aperçu de génération : 0 écriture ; « Générer cette ligne » (Fell/Ass seule,
    période 1) : 9 gardes pour 9 gardes source déclenchées — 2 blocs de 3 jours,
    1 bloc vendredi + dimanche, 1 samedi isolé ; mêmes horaires, blocs d'un seul
    tenant, jamais le titulaire de la garde source, aucun chevauchement dans le
    planning, aucune garde pendant une indisponibilité ; l'aperçu annonçait
    exactement 9 ;
  - **les 120 affectations publiées de la Première ligne identiques, ligne par
    ligne** ; seules lignes existantes changées : le statut de la période de
    Fell/Ass (DRAFT → GENERATED) et la tentative de synchronisation SurgicalHub
    avant une première génération (comportements existants) ;
  - relance : `nothing_to_fill`, rien de créé ; période 2 : « source pas encore
    générée » ;
  - **titulaire de la source modifié à la main** (bloc vendredi + dimanche donné au
    chirurgien qui déclenche les trois jours) : la complétion crée le samedi dans
    le bloc existant, **non pourvu**, 0 modification ; vendredi et dimanche gardent
    leurs lignes ; le titulaire du bloc est proposé en premier ; une fois choisi,
    un titulaire pour les trois jours, lignes d'origine inchangées ; rien publié ni
    envoyé.

## 2. Interface (copie isolée de la base de dev `app_uat_ri`, Chrome)

Planning « Recette D224 » : Première ligne (lundi–jeudi isolés, bloc
vendredi–dimanche ; Alix, Basile, Camille) générée et publiée ; Fell/Ass (Dora,
Eli, Fanny). Déclencheurs : Alix vendredi + samedi + dimanche, Basile samedi,
Camille vendredi + dimanche.

| Capture | Ce qu'elle montre |
|---|---|
| `01-lignes-regles-a-configurer.jpg` | Fell/Ass : « Règles de couverture à configurer », accès direct |
| `02-regles-de-couverture-blocs-herites.jpg` | Règles de couverture, renfort conditionnel, « Structure des blocs héritée de Première ligne » |
| `03-matrice-resume-previsualisation.jpg` | Matrice chirurgiens × jours, résumé, aperçu : 9 gardes dont 3 blocs (rien enregistré) |
| `04-ligne-configuree-9-gardes.jpg` | « Renfort de Première ligne · 3 chirurgiens déclencheurs · blocs hérités », 9 gardes à pourvoir |
| `05-generer-cette-ligne.jpg` | Générer cette ligne : 9 gardes, règles de repos décochées |
| `06-calendrier-bloc-3-jours-et-samedi-seul.jpg` | Week-end d'Alix : bloc de 3 jours ; week-end de Basile : samedi seul |
| `07-calendrier-bloc-vendredi-dimanche.jpg` | Week-end de Camille : bloc vendredi + dimanche, pas de samedi |
| `08-regles-de-generation.jpg` | Règles de génération : la répartition, jamais « les déclencheurs sont configurés » |

Bilan affiché : « 9 gardes attribuées, 28 affectations existantes conservées,
0 modifiée ».

## Bases conservées

`app_test_ri` (tests automatisés de la branche), `app_uat_ri` (cette recette),
avec celles de D222 (`app_test_lg`, `app_test_lg2`, `app_uat_lg`, `app_uat_lg2`,
`app_uat_lg3`).
