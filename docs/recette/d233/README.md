# Recette D233 — Début de couverture d'une ligne

**Rien n'a été écrit en production** : aucune date, aucune garde retirée, aucune génération, aucune publication, aucun e-mail.
La régularisation de Trauma Delta reste à autoriser.

## 1. Copie isolée de la production (sur le serveur, 2026-11-04)

Dump frais de la production (lecture seule), PostgreSQL en mémoire, réseau Docker `--internal` (aucune route vers la production ni
vers Internet), aucun port publié, image de production avec le code de la branche en lecture seule, secrets jetables, mailer
`null://null`, SurgicalHub injoignable. Environnement, dump et code supprimés ensuite.

| Vérification | Résultat |
|---|---|
| Migration `Version20261105090000` | 19 instructions, moins de 0,1 s, 68 tables : aucune ligne existante modifiée |
| `up` / `down` / `up` | Identique à chaque étape (hors la table de journal créée) |
| Ancien code sur le nouveau schéma | Précontrôle identique avant et après la migration |
| État de départ | 120 gardes de première ligne, 61 affectations Fell/Ass, 19 gardes sans titulaire, Fell/Ass bloquée |
| Précontrôle avant | 19 gardes, 13 postes, tous « aucun membre à ces dates », adhésions à partir du 2026-11-01, chaque jour avec son titulaire source et la raison `TRIGGERED` |
| Aperçu du 2026-11-01 | 19 retirées, 0 conservée, du 2 au 29 octobre ; **aucune table modifiée** |
| Sans le hash du plan | 409 `coverage_start_confirmation_required` ; aucune table modifiée |
| Confirmé sur la copie | 19 retirées ; tables changées : `duties` (marques de retrait), `planning_lines` (la date), `planning_line_coverage_changes` (le journal), **rien d'autre** |
| Affectations | Première ligne et Fell/Ass identiques (empreintes) ; 61 gardes Fell/Ass actives |
| Adhésions | Les 6 commencent toujours le 2026-11-01 |
| Journal | 1 ligne avec les 19 gardes ; `UPDATE` refusé |
| Précontrôle après | Rien de non couvert, Fell/Ass publiable, première ligne republiable |
| Publication de Fell/Ass sur la copie | Acceptée ; 61 entrées Fell/Ass, aucune pour une garde retirée ; entrées antérieures et affectations identiques |
| Réattribution d'une garde de première ligne à un chirurgien déclencheur | Fell/Ass intacte, aucune garde créée ; annoncé (un poste non créé, ligne publiée, bonne date), jamais bloquant |
| « Créer ces postes » | 1 garde vide, rien d'attribué ; devient un poste vacant à attribuer (6 membres possibles) |
| Production avant / après | Identique (gardes, affectations, événements, publications, entrées, adhésions, statut de Fell/Ass, migration) |

## 2. Interface (copie isolée d'une base de recette locale, Chrome)

| Capture | Ce qu'elle montre |
|---|---|
| `01-dialogue-postes-vacants.jpg` | « Publier de nouvelles lignes » : dates, titulaire source, cause ; « Publier » désactivé |
| `02-parametres-debut-de-couverture.jpg` | Section « Début de couverture de cette ligne » et son explication |
| `03-effet-avant-confirmation.jpg` | L'effet affiché avant toute écriture |
| `04-republication-besoin-annonce.jpg` | Republication : besoin annoncé sur une ligne publiée, « Republier » reste possible |
| `05-calendrier-renforts-crees.jpg` | Après « Créer ces postes » : vendredi et dimanche vacants, le samedi garde son titulaire |
| `06-parametres-390px.jpg` | La section à 390 px, sans débordement |

Défaut trouvé et corrigé pendant ce contrôle : « Du vendredi 15 au dimanche 17 » annonçait un samedi non concerné.

## Limites

- Le plan de vérification (`03`) n'a pas été vu à 390 px : section et dialogue l'ont été, sans débordement.
- La copie isolée de production a exercé l'API réelle. L'interface a été contrôlée sur une base de recette, pas sur les données de Trauma Delta.
