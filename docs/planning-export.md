# Export d'un planning publié (PDF / Excel)

Décision : [`docs/decisions.md`](decisions.md) D150.

## 1. Objectif

Donner, à tout moment, une copie imprimable (PDF) ou exploitable (Excel
`.xlsx`) du **calendrier courant** d'un planning publié : les lignes
choisies, dans l'ordre choisi, sous le nom choisi pour le document, sur tout
le planning ou une sous-période.

L'export n'est **ni une publication ni une génération** : il n'écrit rien,
n'envoie aucun email, ne renomme ni le planning ni ses lignes.

## 2. Source de vérité

`CurrentCalendarReader` (D143), la lecture partagée par l'écran du
calendrier (`/result`), le préflight de publication, le diff « Modifications
non publiées » et le rappel du samedi : pour chaque ligne active, les
`Duty` de sa période et leur titulaire **actuel** (`DutyAssignment.current`
de la génération COMPLETED la plus récente, D125/D131).

Conséquences :

- une réaffectation ou un retrait fait après publication apparaît dans
  l'export suivant, **qu'il ait été republié ou non** (le dialogue le
  rappelle) ;
- jamais `OptimizationResult`, le `PlanningSnapshot`, le `coverageStatus`
  figé d'une génération, ni les entrées d'une `PlanningPublication` ;
- une personne apparaît exactement comme sur le calendrier : prénom + nom de
  son `User`, via le `PlanningTeamMember` titulaire.

À ne pas confondre avec « Télécharger le PDF » (`GET /publication.pdf`,
D143), qui reste **la dernière diffusion figée**, sans les modifications
faites depuis.

## 3. Disponibilité et droits

| Condition | Règle |
|---|---|
| Bouton « Exporter » | onglet Planning, dès que `publication-state.published` |
| Planning exportable | au moins une `PlanningPublication` — sinon 409 `not_yet_published` |
| Qui | `PlanningVoter::VIEW` (créateur, tout membre courant d'une équipe du planning) — le même public que le calendrier et « Télécharger le PDF » ; aucun nouvel attribut |

## 4. API

`POST /api/plannings/{planningStableId}/export`

```json
{
  "format": "pdf",
  "title": "Gardes Orthopédie",
  "from": "2026-10-01",
  "to": "2027-01-01",
  "lines": [
    { "stableId": "…", "label": "Orthopédie" },
    { "stableId": "…", "label": "Membres supérieurs" }
  ]
}
```

| Champ | Règle |
|---|---|
| `format` | `pdf` ou `xlsx` |
| `title` | obligatoire, 120 caractères au plus ; caractères de contrôle retirés, espaces normalisés ; ne modifie jamais `Planning.name` |
| `from` / `to` | facultatifs, `YYYY-MM-DD`, **`to` exclusif** (même convention que `Planning::$endsAt` et `/result`) ; défaut = bornes du planning ; `from ≥ startsAt`, `to ≤ endsAt`, `from < to` — toujours revalidé côté serveur |
| `lines` | liste non vide ; **l'ordre du tableau est l'ordre du document** ; chaque `stableId` doit être une ligne **active de ce planning**, une seule fois |
| `lines[].label` | « Nom dans l'export », obligatoire, 80 caractères au plus ; ne renomme jamais la `PlanningLine` |

Tout autre champ (y compris `position`) est refusé.

Réponses : `200` + fichier (`Content-Disposition: attachment`,
`Cache-Control: private, no-store`) ; `400` JSON invalide ; `401` ; `403`
sans VIEW ; `404` planning inconnu ; `409` `not_yet_published` ; `422`
`validation_failed` + `violations` (clés `format`, `title`, `from`, `to`,
`lines`, `lines[i].stableId`, `lines[i].label`, champ inconnu).

## 5. Architecture

```text
PlanningExportController           (VIEW, JSON → service, fichier → réponse)
        ↓
PlanningExportService              (publié ?, orchestration, nom de fichier)
        ↓
PlanningExportRequestParser  →  PlanningExportRequest
        ↓
PlanningExportDataBuilder    →  PlanningExportData   (CurrentCalendarReader)
       ↙                    ↘
PlanningExportPdfRenderer    PlanningExportXlsxRenderer
(dompdf + Twig)              (openspout/openspout)
```

`PlanningExportData` : titre, format, premier et dernier jour (inclus),
date/heure de génération (fuseau du planning), lignes exportées dans
l'ordre (`stableId`, nom d'export), et un `PlanningExportDay` par date, avec
une cellule par ligne : liste de `PlanningExportItem` (titulaire ou `null`
= non attribué ; type de garde seulement si la cellule en contient
plusieurs). Les renderers ne lisent jamais la base.

## 6. PDF

- A4 paysage ; chaque mois commence une page (« Octobre 2026 ») ;
- grille lundi → dimanche : pour chaque semaine, une rangée de dates
  (« lun. 5 ») puis une rangée par ligne exportée (nom d'export, ordre
  choisi) avec le titulaire sous chaque jour ; « — » = pas de garde ;
  « Non attribué » en rouge ; les jours hors période exportée sont grisés ;
- une semaine n'est jamais coupée entre deux pages ; si un mois déborde
  (beaucoup de lignes, noms longs), la semaine suivante passe entière sur
  la page d'après et porte elle-même son mois et ses jours ;
- les noms longs vont à la ligne, jamais tronqués ;
- chaque page : titre, période, « Document généré le … à HH:MM — état du
  planning à cette date », « Page n / N ».

## 7. Excel (`.xlsx`)

Un vrai classeur (zip OOXML), deux onglets :

| Onglet | Colonnes | Lignes |
|---|---|---|
| `Planning` | `Date` (vraie date Excel, `dd/mm/yyyy`), `Jour`, puis une colonne par ligne exportée (nom d'export, ordre choisi) | une par date de la période, jours sans garde compris ; plusieurs gardes : « Nom (Type) / Nom (Type) » ; non couverte : « Non attribué » |
| `Par personne` | `Personne`, `Date`, `Jour`, `Ligne` | une par garde couverte exportée ; tri personne (collation française) → date → ordre des lignes |

En-tête figé et en couleur, filtres automatiques, largeurs de colonnes,
week-ends grisés (onglet Planning), impression paysage ajustée à la largeur
avec titre/période en en-tête et date de génération + pagination en pied.

**Sécurité** : toutes les cellules texte sont des `StringCell` explicites
(jamais `Cell::fromValue()`, qui ferait d'une chaîne commençant par `=` une
formule) ; une valeur commençant par `=`, `+`, `-`, `@`, tabulation ou
retour chariot est préfixée d'une apostrophe. En-tête/pied et propriétés du
document sont échappés en XML par MedVue (OpenSpout ne le fait pas).

## 8. Nom du fichier

`Gardes_Orthopedie_2026-10_2026-12.pdf` : titre translittéré en ASCII,
tout autre caractère remplacé par `-`, 60 caractères au plus, `Planning` si
rien ne reste ; « Gardes_ » n'est pas ajouté si le titre commence déjà par
« Gardes » ; un seul mois → `…_2026-10.xlsx`. Le titre n'est jamais utilisé
comme chemin.

## 9. Interface

Onglet Planning → « Exporter » → dialogue « Exporter le planning » :

- Format : PDF (défaut) | Excel (.xlsx) ;
- Titre du document (défaut : nom du planning) ;
- Lignes : toutes les lignes actives, cochées, dans l'ordre du planning ;
  pour chacune « Afficher dans l'export », son nom réel, « Nom dans
  l'export », Monter / Descendre ;
- Période : Planning complet (défaut) | Période personnalisée (Du / Au
  inclus, bornées au planning) ;
- « Aperçu » (PDF) : le même endpoint, le PDF affiché dans le dialogue ;
  retiré dès qu'un paramètre change ;
- « Exporter » : téléchargement sous le nom donné par le serveur.

Contrôles avant envoi (au moins une ligne, un nom par ligne, un titre, une
période valide) ; boutons désactivés et dialogue non fermable pendant la
production du fichier ; un double clic n'envoie qu'une requête ; erreurs
serveur traduites (non publié, ligne devenue inexportable, période, accès,
échec).

Les choix ne sont **pas enregistrés** (D150, option A) : chaque ouverture
repart du planning.

## 10. Limites connues

- Préférences d'export non persistées (voir D150).
- Une ligne active jamais générée apparaît vide (comme sur le calendrier).
- Les blocs atomiques ne sont pas signalés comme tels dans l'export (le
  titulaire de chaque jour l'est) ; le PDF de diffusion (D143) les marque.
- L'aperçu dépend du lecteur PDF intégré du navigateur (certains navigateurs
  mobiles ne l'affichent pas dans une `<iframe>` ; l'export lui-même
  fonctionne).
- Très nombreuses lignes : le PDF reste lisible mais un mois s'étale alors
  sur plusieurs pages (une ou deux semaines par page).

## 11. Tests

- `backend/tests/Service/PlanningExportRenderersTest.php` — modèle construit à
  la main : mois/semaines/pages du PDF, sous-période, contenu des deux
  onglets, vraies dates, tri par personne, injection de formule, titre avec
  `&`/`<`, nom de fichier.
- `backend/tests/Controller/PlanningExportControllerTest.php` — de bout en
  bout avec génération OR-Tools réelle : PDF et XLSX d'un planning publié,
  lecteurs autorisés, contenu = calendrier courant, réaffectation et retrait
  après publication reflétés sans nouvelle publication, sélection/ordre/alias
  sans renommage, bornes de période (premier/dernier jour, `to` exclusif),
  payloads invalides, ligne d'un autre planning, ligne inactive, 401/403/404,
  planning jamais publié.
- `frontend/src/features/planning/export/*.test.ts(x)`,
  `frontend/src/lib/apiClient.test.ts`,
  `frontend/src/features/planning/calendar/PlanningCalendar.test.tsx` —
  dialogue (valeurs par défaut, formats, sélection, ordre, alias, période,
  validation, payload exact, téléchargement, erreurs serveur, chargement,
  double clic, aperçu), client de téléchargement, visibilité du bouton.
