# Répétition des migrations sur une copie isolée de la production (2026-11-03)

PR #34 (D224) et PR #35 (D225-D231). Code : `2219742`, arbre identique au commit de fusion `a727973`. **Rien n'a été écrit
en production, aucune génération n'a été lancée, ni sur la production ni sur la copie.**

## Environnement

Sur le serveur, rien n'a été téléchargé : dump vérifié `medvue_20261103_034501.dump`, PostgreSQL en mémoire, réseau Docker
`--internal` (aucune route vers la production ni vers Internet), aucun port publié, image de production avec le code
fusionné en lecture seule, secrets jetables, mailer `null://null`, SurgicalHub injoignable. Environnement supprimé ensuite.

## Résultats

| Vérification | Résultat |
|---|---|
| Intégrité du dump | OK (sha256) |
| Migrations | `Version20261103090000` + `Version20261104090000`, 40 instructions, moins de 0,1 s |
| Instruction de modification de données | Une seule : `UPDATE planning_lines SET week_structure_mode = 'GENERAL' WHERE type = 'PRIMARY'`, sur une colonne créée par la même migration |
| `up` | 2 672 lignes de 68 tables identiques |
| `down` | 2 672 lignes identiques, aucune colonne ni table restante |
| `up` à nouveau | 2 672 lignes identiques |
| Lectures API (31 réponses, dont 120 gardes) : code actuel avant / après `down` | Identiques |
| Code actuel sur le nouveau schéma (fenêtre avant bascule, retour du code seul) | Identiques |
| Code nouveau après migration | 120 gardes identiques ; seules différences : `canEditStart` / `canEditEnd` (voulu, D225-D226) et le champ ajouté `coverage` |
| Aperçus de « Modifier les dates » sur Trauma Delta | 0 ligne écrite, 0 ligne de journal |
| Sans confirmation | 409 `date_change_confirmation_required` |
| Coupe confirmée sur la copie (fin le 22 janvier, sans avertir) | 6 gardes retirées, 114 actives, aucune affectation courante sur une garde retirée, aucune active après la date, 1 ligne de journal, publications et notifications inchangées |
| `down` après ce changement | Refusé : « refusing to forget which duties were retired or added » |

## Lecture des journaux

- Dans `r1.log`, le « FAIL » de l'étape 3b est attendu : lire le flux agenda met à jour `calendar_feeds.last_fetched_at`,
  comportement existant. La référence des empreintes est prise après cette lecture.
- `r2.log` et `r3.log` : étapes fonctionnelles et ancien code sur le nouveau schéma. Le test du `down` refusé (dernière
  ligne du tableau) a été exécuté à part, son résultat est reporté ci-dessus.
- Le résultat de la coupe sur la copie n'est pas celui d'un cas réel décidé : c'est un test, la production garde ses dates.

## Conservé

- Ici : `r1.log`, `r2.log`, `r3.log`, `migration_up.sql` (aucune donnée personnelle).
- Sur le serveur, `/home/deploy/backups/medvue/rehearsal-20261103/` (droits 700/600, hors rotation) : les mêmes journaux et
  `copy_migrated.dump`, la copie migrée avant tout test d'écriture. **Elle contient des données personnelles** et n'est pas
  supprimée automatiquement : à effacer quand elle n'est plus utile.
- Bases locales de recette : `app_ergo_uat`, `app_ergo_uat2`, `app_uat_ri`, `app_uat_lg`, `app_uat_lg2`, `app_uat_lg3`.
- Preuves navigateur : `docs/recette/d224/`, `docs/recette/d228/`, `docs/recette/d229/`.
