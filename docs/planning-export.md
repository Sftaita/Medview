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

### Deux documents distincts

| Bouton | Contenu | Source |
|---|---|---|
| « PDF de la dernière diffusion » (`GET /publication.pdf`, D143) | le planning **tel qu'il a été diffusé** lors de la dernière publication/republication, figé | entrées de la dernière `PlanningPublication` |
| « Exporter » (`POST /export`, D150) | le calendrier **tel qu'il est maintenant**, modifications non encore diffusées comprises, en PDF ou Excel | `CurrentCalendarReader` |

Après une réaffectation ou un retrait non republié, les deux diffèrent :
c'est voulu. Une republication fait avancer le PDF de diffusion ; l'export,
lui, suit toujours le calendrier. Aucun des deux ne dépend de l'autre ni du
solveur. Les deux boutons portent une infobulle et le dialogue d'export
rappelle la différence.

## 3. Disponibilité et droits

| Condition | Règle |
|---|---|
| Bouton « Exporter » | onglet Planning, dès que `publication-state.published` |
| Planning exportable | au moins une `PlanningPublication` — sinon 409 `not_yet_published` |
| Qui | `PlanningVoter::VIEW` (créateur, tout membre courant d'une équipe du planning) — le même public que le calendrier et « PDF de la dernière diffusion » (MEMBER, ADMIN, OWNER/créateur : oui ; sans adhésion : 403 ; non authentifié : 401) ; aucun nouvel attribut |

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
| `from` / `to` | facultatifs, `YYYY-MM-DD`, **`from` inclusif, `to` exclusif** (l'interface montre une date de fin **incluse** et envoie le lendemain) (même convention que `Planning::$endsAt` et `/result`) ; défaut = bornes du planning ; `from ≥ startsAt`, `to ≤ endsAt`, `from < to` — toujours revalidé côté serveur |
| `lines` | liste non vide ; **l'ordre du tableau est l'ordre du document** ; chaque `stableId` doit être une ligne **active de ce planning**, une seule fois |
| `lines[].label` | « Nom dans l'export », obligatoire, 80 caractères au plus ; ne renomme jamais la `PlanningLine` |

Tout autre champ (y compris `position`) est refusé.

Réponses : `200` + fichier (`Content-Disposition: attachment`,
`Cache-Control: private, no-store`) ; `400` JSON invalide ; `401` ; `403`
sans VIEW ; `404` planning inconnu ; `409` `not_yet_published` ; `422`
`validation_failed` + `violations` (clés `format`, `title`, `from`, `to`,
`lines`, `lines[i].stableId`, `lines[i].label`, `size` — PDF trop
volumineux, voir §6 —, champ inconnu).

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

**Taille maximale** : dompdf garde tout le document en mémoire et son coût
suit le nombre de rangées du tableau, pas le nombre de gardes. Mesures
(poste de dev, noms longs) : ≈ 37 Mo + 0,25 Mo et 0,018 à 0,026 s par
rangée — une année de 6 lignes dépassait déjà les 128 Mo par défaut de PHP.
D'où :

- `PlanningExportPdfRenderer::rowCount()` compte exactement les rangées que
  la mise en page produira (pour chaque mois, chaque semaine contenant une
  date exportée = 1 rangée de dates + 1 par ligne) ;
- au-delà de `MAX_ROWS` = **800** rangées (≈ 20 s, ≈ 240 Mo ; p. ex.
  10 lignes sur une année entière), la requête est refusée d'emblée
  (`422`, clé `size`) au lieu d'échouer en cours de rendu — l'interface
  propose de réduire la période ou le nombre de lignes, ou de passer en
  Excel, qui écrit en flux et n'a pas cette limite ;
- le rendu relève pour la requête `memory_limit` à 512 Mo et le temps
  d'exécution à 90 s (jamais redescendus : PHP refuse de passer sous la
  mémoire déjà utilisée).

Vérifié jusqu'à 30 lignes et 1 500 rangées : aucun crash, aucune page vide,
aucun contenu hors page, titre intact dans les métadonnées (apostrophe,
`&`, `<>`, guillemets, parenthèses, barre oblique inverse). La police
DejaVu Sans couvre le latin étendu (accents, ł, ż…) mais pas le
chinois/japonais.

## 7. Excel (`.xlsx`)

Un vrai classeur (zip OOXML), deux onglets :

| Onglet | Colonnes | Lignes |
|---|---|---|
| `Planning` | `Date` (vraie date Excel, `dd/mm/yyyy`), `Jour`, puis une colonne par ligne exportée (nom d'export, ordre choisi) | une par date de la période, jours sans garde compris ; plusieurs gardes : « Nom (Type) / Nom (Type) » ; non couverte : « Non attribué » |
| `Par personne` | `Personne`, `Date`, `Jour`, `Ligne` | une par garde couverte exportée ; tri personne (collation française) → date → ordre des lignes |

En-tête figé et en couleur, filtres automatiques, largeurs de colonnes,
week-ends grisés (onglet Planning), impression paysage ajustée à la largeur
avec titre/période en en-tête et date de génération + pagination en pied
(chaque section d'en-tête limitée à 80 caractères avant doublement des `&`,
pour rester sous la limite Excel de 255 caractères).

**Sécurité** : toutes les cellules texte sont des `StringCell` explicites
(jamais `Cell::fromValue()`, qui ferait d'une chaîne commençant par `=` une
formule) ; une valeur commençant par `=`, `+`, `-`, `@`, tabulation ou
retour chariot est préfixée d'une apostrophe. Protection centralisée dans
`PlanningExportXlsxRenderer::text()`, seul point d'entrée des cellules
texte : alias de ligne (en-têtes et colonne « Ligne »), noms des personnes,
cellules du planning (dont les noms de type de garde). Le titre n'est écrit
dans aucune cellule (seulement en-tête/pied et propriétés) ; les dates
restent de vraies dates Excel. En-tête/pied et propriétés du document sont
échappés en XML par MedVue (OpenSpout ne le fait pas) — un seul
échappement, vérifié en relisant le classeur.

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
- « Aperçu » (PDF) : le même endpoint, le PDF affiché dans le dialogue
  (sous le formulaire en dessous de 820 px) ; retiré dès qu'un paramètre
  change. Sur téléphone ou écran tactile (`(max-width: 640px), (pointer:
  coarse)`), où les navigateurs n'affichent pas un PDF dans une `<iframe>`
  de manière fiable, l'aperçu devient un lien « Ouvrir l'aperçu PDF » vers
  le même fichier, dans un nouvel onglet — jamais un rendu HTML ;
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
- Aperçu sur téléphone : un lien vers le PDF (§9), pas d'aperçu intégré.
- PDF limité à 800 rangées (§6) ; au-delà, Excel ou une période plus courte.
  Avec beaucoup de lignes, le PDF reste lisible mais un mois s'étale sur
  plusieurs pages (une ou deux semaines par page).
- Chrome peut demander l'autorisation de « télécharger plusieurs fichiers »
  quand on enchaîne des exports sans recharger la page (protection du
  navigateur, le fichier étant produit après une requête) : l'accepter une
  fois suffit.
- `openspout/openspout` 5.x exige PHP ≥ 8.3 (image de production et CI en
  8.3), alors que `composer.json` déclare encore `php >=8.2`.

## 11. Tests

- `backend/tests/Service/PlanningExportRenderersTest.php` — modèle construit à
  la main : mois/semaines/pages du PDF, sous-période, contenu des deux
  onglets, vraies dates, tri par personne, injection de formule, titre avec
  `&`/`<`, nom de fichier, `rowCount()` égal aux rangées réellement mises en
  page.
- `backend/tests/Controller/PlanningExportControllerTest.php` — de bout en
  bout avec génération OR-Tools réelle : PDF et XLSX d'un planning publié,
  lecteurs autorisés, contenu = calendrier courant, réaffectation et retrait
  après publication reflétés sans nouvelle publication, sélection/ordre/alias
  sans renommage, bornes de période (premier/dernier jour, `to` exclusif, un
  seul jour, fin de mois, février entier, passage à l'heure d'été),
  publication → export → réaffectation → export → retrait → export →
  republication (PDF de diffusion figé jusqu'à la republication, export
  toujours courant), PDF trop volumineux refusé (Excel accepté), payloads
  invalides, ligne d'un autre planning, ligne inactive, 401/403/404, planning
  jamais publié.
- `frontend/src/features/planning/export/*.test.ts(x)`,
  `frontend/src/lib/apiClient.test.ts`,
  `frontend/src/features/planning/calendar/PlanningCalendar.test.tsx` —
  dialogue (valeurs par défaut, formats, sélection, ordre, alias, période,
  validation, payload exact, téléchargement, erreurs serveur, chargement,
  double clic, aperçu, lien d'aperçu sur téléphone, refus `size`), client de
  téléchargement, visibilité et infobulles des deux boutons PDF.
- Vérifications manuelles (Chrome, stack de dev) : téléchargements PDF et
  XLSX réels (une requête, nom de fichier, URL blob libérée, aucun fichier
  sur erreur serveur), dialogue à 1 600, 817 et 387 px de large.

## 12. Ligne conditionnelle (docs/decisions.md D166)

La décision vient de `CurrentCalendarReader` (état live de chaque garde
conditionnelle, `CalendarCell`), jamais du renderer :

| Garde | PDF et XLSX |
|---|---|
| Ligne indépendante non attribuée | « Non attribué » (inchangé) |
| Conditionnelle requise et attribuée | le titulaire |
| Conditionnelle requise non attribuée | « Non attribué » |
| Conditionnelle non requise et vide | **absente** (jamais « Non attribué ») |
| Conditionnelle non requise mais tenue (superflue) | le titulaire (feuille « Planning » et « Par personne ») |
| Conditionnelle indéterminée non tenue | « Renfort non évalué » (jamais assimilée à non requise) |

`PlanningExportItem::gapLabel()` est l'unique source du libellé d'un trou,
lue par les deux renderers. Aucun avertissement textuel n'est ajouté aux
fichiers. Tests : `ConditionalPublicationTest`.
