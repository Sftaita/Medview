# Recette D222 — Générer une ligne seule et les postes non pourvus

Preuves des deux recettes de la PR #32 (`docs/decisions.md` D222). Les deux ont
été faites **dans le navigateur (Chrome desktop)**, avec le backend, le worker
et le frontend de la branche lancés dans des conteneurs jetables, sur une
**copie isolée** de la base de dev. Elles n'ont rien écrit dans la base de dev
`app`, ni dans `app_test`, ni en production. Aucun e-mail n'a été envoyé : rien
dans ces parcours n'en envoie.

## Bases conservées pour la validation (ne pas supprimer)

| Base | Contenu |
|---|---|
| `app_test_lg` | tests automatisés, première version de la migration (tour 1) |
| `app_test_lg2` | tests automatisés, migration finale (tour 2) |
| `app_uat_lg` | recette 1 (« Recette D222 Gardes »), première version de la migration |
| `app_uat_lg2` | recette 2 (« Recette D222 v2 »), migration finale |

Comptes de recette : `uat-d222-*@example.test` (recette 1), `uat2-*@example.test` (recette 2).
Les mots de passe sont générés et ne sont pas consignés ici.

## Recette 1 (2026-11-02, avant la revue)

- **Départ** : ligne Seniors générée (28 gardes), une garde réaffectée à la
  main (Sam, `MANUAL`), une garde retirée ; ligne Juniors ajoutée ensuite.
- **Action** : « Générer les postes non pourvus », sans règle de repos.
- **Résultat** : Seniors 27 conservées + 1 ajoutée ; Juniors 28 ajoutées ;
  **0 affectation existante modifiée**. La réaffectation manuelle est intacte.
- **Contrôle indépendant** : empreinte md5 de chaque ligne de 14 tables avant
  et après. Sur les 2 989 lignes qui existaient avant, **0 a été modifiée**.
- **Captures** : `r1-lignes-compteurs.jpg`, `r1-apercu.jpg`, `r1-bilan.jpg`.

## Recette 2 (2026-11-02, après la revue)

- **Base** : nouvelle copie de la base de dev (`app_uat_lg2`). La migration
  finale y a été appliquée, annulée (`down`), puis réappliquée : l'aller-retour
  fonctionne sur des données réelles.
- **Départ** (21 jours, trois lignes, aucune règle de repos) :
  - **Seniors** (Alix, Basile) : générée seule, **publiée**, puis une garde
    retirée le 6 janvier, ce qui crée une modification non publiée ;
  - **Juniors** (Camille, Basile) : générée seule. La garde du 5 janvier est
    réaffectée à la main à Camille (`MANUAL`). Celle du 7 janvier est retirée,
    et Camille comme Basile se déclarent indisponibles ce jour-là. **Lou**
    rejoint ensuite l'équipe ;
  - **Renfort** (Alix, Camille) : jamais générée.
- **Écran** :
  - « Générer le planning » a disparu de l'en-tête. L'action principale est
    « Générer les postes non pourvus » (`r2-entete-sans-generer-le-planning.jpg`).
  - « Régénérer entièrement… » est rangé dans le menu « ⋯ » comme action
    destructrice (`r2-menu-regenerer.jpg`). Il cite les 20 gardes de Juniors
    qui seraient remplacées, et « Régénérer et remplacer » reste désactivé
    tant que la confirmation n'est pas cochée (`r2-regeneration-confirmation.jpg`).
    Rien n'a été lancé.
  - Dans l'aperçu (`r2-apercu-publiee-exclue-membre-tardif.jpg`) :
    - Seniors porte une case non cochable et la mention « Publiée : jamais
      complétée automatiquement » ;
    - Juniors annonce « 1 membre arrivé depuis la génération est pris en
      compte » ;
    - Renfort reçoit sa première génération ;
    - les cases « Règles de repos » sont décochées.
- **Lancement** (sélection par défaut) — job `FILL` n° 20, `SUCCEEDED` :

  | Ligne | Conservées | Nouvelles | Toujours non pourvues | Modifiées |
  |---|---|---|---|---|
  | Seniors (publiée) | 20 | 0 | 1 | **0** |
  | Juniors | 20 | 1 (Lou, 7 janv.) | 0 | **0** |
  | Renfort | 0 | 19 | 2 | **0** |

- **Contrôle indépendant** : empreinte md5 de chaque ligne de 14 tables
  (`docs/recette/d222/rows.sql`). Sur les **3 079** lignes qui existaient
  avant, **0 a été modifiée**. Les lignes ajoutées sont :
  - 20 affectations et 1 événement ;
  - 1 génération et son snapshot (Renfort) ;
  - 3 membres de snapshot : les 2 de Renfort et Lou, seul marqué
    `captured_late_at` ;
  - 1 indisponibilité figée et 22 engagements externes.
- **Titulaires** :
  - la garde réaffectée à Camille le 5 janvier est toujours `MANUAL` ;
  - le trou de Seniors (publiée) reste vacant ;
  - la garde Juniors du 7 janvier revient à Lou.
- **Statistiques et calendrier après actualisation** : `/result` et
  `/statistics` correspondent exactement au décompte en base, ligne par ligne
  et personne par personne (Alix 21, Basile 18, Camille 20, Lou 1). Après
  rechargement, « Lignes de garde » affiche Seniors 20/21, Juniors 21/21 et
  Renfort 19/21 (`r2-lignes-apres-actualisation.jpg`). Le bilan affiché
  correspond au job (`r2-bilan.jpg`).

## Ce que « non testé » veut dire ici

Rien n'a vérifié la fonctionnalité dans ces cas, ni par un test automatisé, ni
dans le navigateur, ni en base :

- **Affichage à 390 px** : l'outil navigateur ne change pas la largeur réelle
  de la page.
- **Ligne de renfort et sa source générées dans le même passage** : le code
  et l'aperçu le gèrent (`afterSource`), mais aucun test ne l'exerce.
- **Production** : aucun déploiement, aucune recette sur une copie de la base
  de production.
