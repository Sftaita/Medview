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

## 3. Contrôle à 390 px (téléphone, 2026-11-03, `app_uat_ri`, Chrome)

Second planning, « Recette D224 mobile » (mars 2027), mêmes personnes : Première
ligne publiée (28 gardes), Fell/Ass vierge. Parcours complet à 390 px, de la
configuration à la génération, cases cochées par de vrais clics.

| Capture | Ce qu'elle montre |
|---|---|
| `09-390-menu-ouvert-vers-le-haut.jpg` | Menu « ⋯ » de la dernière ligne, ouvert vers le haut : les 3 entrées atteignables |
| `10-390-regles-de-couverture.jpg` | Ligne vierge : semaine type et règles de couverture |
| `11-390-blocs-herites.jpg` | « Automatique, rien à régler : les horaires et les blocs viennent de Première ligne », déclencheurs, membres Fell/Ass exclus de la matrice |
| `12-390-matrice-cochee.jpg` | Chirurgiens × jours : 21 cases nommées, 48 × 55 px |
| `13-390-resume-previsualisation.jpg` | Résumé en phrases, aperçu : 9 gardes dont 3 blocs |
| `14-390-enregistre.jpg` | Confirmation amenée à l'écran |
| `15-390-generer-cette-ligne.jpg` | « Jamais générée · 9 gardes à pourvoir », bouton entier |
| `16-390-dialogue-generation.jpg` | Générer cette ligne : Fell/Ass seule, Première ligne non proposée |
| `17-390-calendrier.jpg` | Deux colonnes lisibles : bloc de 3 jours d'une seule personne, samedi seul |

Mesures : aucun débordement horizontal (page 390 = 390, corps des dialogues
`scrollWidth` = `clientWidth`).

Défauts trouvés à cette largeur, corrigés et couverts par un test (mutation
vérifiée pour le menu, la confirmation et la réponse tardive) :

1. **Coche effacée toute seule** : une réponse de chargement arrivée après la
   première action écrasait le brouillon (mesuré : clic à 7,7 s, sélection
   effacée à 15,8 s). Provoqué ici par le double chargement de `StrictMode`,
   donc en développement ; seule la dernière réponse compte désormais.
2. **« Supprimer la ligne » inatteignable** sur la dernière ligne : sous la barre
   du bas et coupé par la carte, page déjà en bas. Antérieur à D224 (D140). Le
   menu s'ouvre vers le haut si sa dernière entrée n'est pas réellement affichée.
3. **Bouton « Prévisualiser… »** : 10 px de débordement, le libellé passe à la ligne.
4. **Confirmation d'enregistrement** sous le pli : amenée à l'écran.
5. **Titres** plus petits que le texte qu'ils introduisent : agrandis.

Textes clarifiés : qui déclenche (« Quand X est de garde sur Première ligne le
vendredi, samedi ou dimanche : un renfort Fell/Ass »), qui assure (« Chaque
renfort est assuré par un membre de Fell/Ass »), d'où viennent horaires et blocs
(« Automatique, rien à régler »), plus de « ligne source » dans le choix du mode,
plus de « à couvrir chaque jour » sur l'onglet Lignes.

Résultat en base : 9 gardes exactement sur les jours déclenchés, 3 blocs d'un seul
titulaire chacun (Eli 3 jours, Fanny vendredi + dimanche, Dora 3 jours) et un samedi
isolé (Fanny), mêmes horaires que la source ; Première ligne : même empreinte avant
et après (`dc6a061b…`) ; bilan affiché « 9 gardes attribuées, 28 affectations
existantes conservées, 0 modifiée ».

Sur le premier planning, modifier puis rétablir une règle n'a changé aucune garde
ni affectation (empreintes identiques), seule une version de règle s'ajoute.

**Non vérifié** : un vrai téléphone (Chrome, cadre de 390 px, souris) ; le tactile
réel et le clavier virtuel n'ont pas été exercés.

## 4. Production, contrôle en lecture seule (2026-11-03)

Transaction `READ ONLY` : migration `Version20261102090000` (D224 non appliquée),
120 affectations courantes sur la Première ligne de Trauma Delta, 0 garde Fell/Ass,
0 job de génération depuis le déploiement du 2026-11-02 (dernier : 2026-09-29).

## Bases conservées

`app_test_ri` (tests automatisés de la branche), `app_uat_ri` (cette recette, les deux plannings),
avec celles de D222 (`app_test_lg`, `app_test_lg2`, `app_uat_lg`, `app_uat_lg2`,
`app_uat_lg3`).
