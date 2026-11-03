# Recette D229-D231 — Coupe de bloc, nouvelle ligne, collectes et préparation

Base isolée `app_ergo_uat2` (copie de la base de recette de la PR #34), backend, worker et frontend de la
branche sur les ports 8012 / 5185, e-mails dans Mailpit local, OR-Tools réel. **Aucune donnée de production
touchée.** La base est conservée comme preuve, comme `app_ergo_uat` de la recette précédente (`docs/recette/d228/`).

Planning « Recette D224 » : Première ligne publiée et Fell/Ass générée en période 1 ; bloc Ven-Sam-Dim du 15 au
17 janvier 2027 attribué à Basile.

## Résultats vérifiés en base

| Scénario | Résultat |
|---|---|
| Fin le vendredi 15 janvier, « Confirmer et avertir » | Vendredi : même affectation, Basile. Samedi et dimanche retirés, affectations remplacées et historisées. 22 gardes retirées, 1 republication, 1 PDF, e-mails à Alix, Basile, Camille seulement ; Basile n'est prévenu que de ses dates perdues (16, 17 janvier…) |
| Suite en période 2 | Samedi et dimanche : une seule unité, un titulaire (Alix). Blocs ordinaires entiers, 22-24 janvier : Basile |
| Nouvelle ligne « Externes » sur la période publiée | Rejoint les périodes 1, 2, 3 sur la semaine type générale. « Compléter la période 1 » : 12 gardes, 101 conservées, 0 modifiée. Affectations de la ligne publiée, publications, PDF, entrées, notifications : identiques (empreintes) |
| Collecte sollicitée, période 3 (non confirmé, annulé) | Réduction, statut ouvert, 0 réponse sur 6, 6 messages déjà envoyés, date limite du 20 au 18 mars : tout affiché avant confirmation |
| Préparation, période 5 raccourcie | Annonce retirée, date limite et cible au dernier jour, rappels recalés (18 et 23 août). Panneau sans erreur |

## Défaut trouvé par cette recette et corrigé

Une annonce annulée gardait sa date : « Préparer la période » affichait des erreurs et un code brut. Elle repart à
« Non programmée » ; le plan confirmé reste dans le journal. Capture `07` : avant ; capture `08` : après.

## Captures

`01` calendrier : jours à confirmer (« ! ») ; `02` avertissement et dates proposées ; `03` confirmation, partie de
bloc et titulaire ; `04` après « Confirmer et avertir » ; `05` « Compléter » : ligne publiée inchangée, nouvelle ligne ;
`06` impact sur une collecte sollicitée ; `07` préparation avant correctif ; `08` préparation après correctif ;
`09`, `10` calendrier et confirmation à 390 px ; `11` période après confirmation à 390 px.
