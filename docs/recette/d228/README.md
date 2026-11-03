# Recette D225-D228 — Refonte ergonomique du planning et des périodes

Base isolée `app_ergo_uat` (copie de la base de recette de la PR #34), backend, worker et frontend
de la branche sur les ports 8012 / 5185, e-mails dans Mailpit local, OR-Tools réel. **Aucune donnée de
production touchée.** La base est conservée comme preuve.

Planning « Recette D224 » : Première ligne publiée et Fell/Ass générée (période 1), période 2 jamais
générée, période 3 générée sur une seule ligne.

## Emplacement de l'action principale (mesuré dans le navigateur, x,y,largeur,hauteur)

| Écran | Période | Libellé | Action | Menu « ⋯ » |
|---|---|---|---|---|
| 1 600 px | 1 | Période complète | 1016,128,340,56 | 1364,128,56,56 |
| 1 600 px | 2 | Générer la période 2 | 1016,128,340,56 | 1364,128,56,56 |
| 1 600 px | 3 | Compléter la période 3 | 1016,128,340,56 | 1364,128,56,56 |
| 390 px | 1 | Compléter / Période complète | 20,257,265,56 | 293,257,56,56 |
| 390 px | 2 | Générer la période 2 | 20,257,265,56 | 293,257,56,56 |

Identique au pixel près, dès 150 ms après le changement de période. Aucun défilement horizontal à 390 px.

## Données avant / après (requêtes SQL sur la base isolée)

| Étape | Affectations d'origine | Publications | PDF stockés | E-mails | Résultat |
|---|---|---|---|---|---|
| Prolonger la période 1 publiée au 7 févr. | identiques (md5) | 1 | identiques | 0 | 7 gardes vides marquées, période 2 au 8 févr. |
| Compléter la période 1 | identiques (md5) | 1 | identiques | 0 | 7 + 1 renfort attribués, 0 modifiée, 7 « Modifications non publiées » |
| Annuler | identiques | inchangées | identiques | 0 | rien |
| Confirmer et avertir (28 janv., double-clic) | 14 retirées, historisées | +1 | +1 | 3, aux seuls titulaires concernés | une seule entrée de journal |
| Confirmer (14 janv.) | 17 retirées, historisées | inchangées | identiques | 0 | période 2 au 15 janv. |

## Captures

`01` conséquences d'une prolongation ; `02` après enregistrement ; `03` « Compléter » : nouvelles dates
seulement ; `04` bilan 0 modifiée ; `05` modifications non publiées ; `06` vendredi 15 janvier refusé (il
coupe le bloc) ; `07` « Confirmer et avertir » refusé avec son motif ; `08` les trois choix ; `09` après
« Confirmer et avertir » ; `10` ce que chaque ligne utilise ; `11` les trois questions dans une ligne ;
`12` semaine type générale et son `?` ; `13` `14` à 390 px ; `15` les trois choix à 390 px, **avant** le correctif
de la liste écrasée (hauteur 0, corrigée ensuite) ; `16` période suivante déjà générée ; `17` les trois choix à 390 px, liste visible, après le correctif.

## Défauts trouvés par cette recette et corrigés

Liste des gardes écrasée à 390 px ; « Préparer la période » affichant les anciennes dates après un
changement ; résumé affiché sous une date refusée ; période suivante vierge non citée dans la confirmation ;
Fell/Ass annoncée « inchangée » alors qu'elle suit sa source.
