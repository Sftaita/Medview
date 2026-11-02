# Recette D222-D223 — Générer une ligne seule et les postes non pourvus

Preuves des trois recettes de la PR #32 (`docs/decisions.md` D222, D223). Toutes
ont été faites **dans le navigateur (Chrome)**, avec le backend, le worker
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
| `app_uat_lg3` | recette 3 : copie de `app_uat_lg2` après la recette 2, plus « Recette D223 » |

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

## Recette 3 (2026-11-02, après la deuxième revue — D223)

Base `app_uat_lg3` (copie de `app_uat_lg2`, qui reste intacte). Affichage
téléphone : l'application chargée une fois dans une iframe de même origine de
**390 px** de large (la fenêtre du navigateur ne se redimensionne pas), puis la
même iframe élargie pour le bureau.

- **« Générer les postes non pourvus » à 390 px** sur « Recette D222 v2 » :
  - en-tête : le bouton principal **débordait de 10 px à gauche** (libellé
    long sur une ligne) → corrigé, il passe sur deux lignes dans la marge ;
  - attente de l'aperçu visible et annoncée, bouton de lancement indisponible
    (`r3-390-apercu-chargement.jpg`) ; aperçu prêt en 6 à 19 s selon le cache
    (`r3-390-apercu.jpg` : Seniors publiée non cochable, Juniors rien à faire,
    Renfort 2 postes à compléter) ;
  - **double clic** sur le lancement : un seul job créé (n° 21), bouton
    « Envoi… » désactivé, puis bouton de l'en-tête désactivé pendant le calcul ;
  - bilan : à 390 px la colonne « Affectations existantes modifiées » était
    **cachée derrière un défilement horizontal**, et Renfort affichait
    « Postes vacants complétés » alors que **0** garde avait été attribuée
    (`r3-390-bilan-avant-correction.jpg`) → corrigé : une carte par ligne,
    chaque nombre étiqueté, et « Personne n'a pu être attribué : postes vacants
    inchangés » (`r3-390-bilan.jpg`). 0 affectation existante modifiée.
- **Calendrier, ligne publiée à côté d'une ligne brouillon** : « Compléter
  automatiquement » reste disponible pour Renfort, et la ligne publiée est
  nommée : « Seniors est publiée : elle n'est jamais complétée
  automatiquement. Attribuez ses gardes une à une, puis republiez. »
  (`r3-390-calendrier-ligne-publiee-et-brouillon.jpg`).
- **« Recette D223 »** (une ligne Seniors, 7 jours, générée, publiée, puis la
  garde du 3 février retirée — seule une ligne publiée a un trou) :
  - **appel direct de l'API historique** `POST /complete` : **409**
    `published_lines_not_completed` (ligne Seniors nommée) ; sur 3 158 lignes
    de 14 tables, **0** différente avant/après ; aucun job (22 → 22) ; PDF de
    la diffusion identique (SHA-256) ; aucun e-mail dans Mailpit (20 → 20) ;
  - **bouton** : indisponible, raison affichée à côté et annoncée
    (`aria-describedby`) (`r3-390-completer-indisponible.jpg`) ;
  - **réaffectation manuelle** du trou depuis le calendrier à 390 px :
    acceptée (`r3-390-reaffectation-manuelle.jpg`) ; historique : retrait puis
    attribution `MANUAL`, tous deux marqués « après publication ». La titulaire
    d'origine ayant été choisie, A → ∅ → A n'est pas une modification à
    republier (règle existante) ; la republication explicite après un vrai
    changement est couverte par les tests ;
  - bureau après la réaffectation (`r3-bureau-apres-reaffectation.jpg`).

## Ce que « non testé » veut dire ici

Rien n'a vérifié la fonctionnalité dans ces cas, ni par un test automatisé, ni
dans le navigateur, ni en base :

- **Production** : aucun déploiement, aucune recette sur une copie de la base
  de production.
- **Vrai téléphone** : l'affichage à 390 px a été vérifié dans une iframe de
  Chrome de bureau, pas sur un appareil (tactile, clavier virtuel, Safari iOS).
- **Ligne de renfort et sa source générées dans le même passage** : testé par
  `PlanningLineFillConditionalTest` (OR-Tools réel), pas dans le navigateur.
