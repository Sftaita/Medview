# MedVue — kit PWA

Tout ce qu'il faut pour rendre MedVue installable (Android, iOS, desktop) et utilisable hors connexion au minimum. À déposer à la **racine publique** du site — `public/` dans Symfony.

```
public/
├── manifest.webmanifest        # nom, couleurs, icônes, raccourcis
├── sw.js                       # service worker (doit être servi depuis la racine)
├── offline.html                # page affichée sans réseau
├── favicon.ico                 # 16 / 32 / 48 px embarqués
├── favicon.svg                 # favicon vectoriel (navigateurs récents)
├── apple-touch-icon.png        # 180 px, iOS
├── icons/
│   ├── icon-{16…512}.png       # 11 tailles, badge arrondi, fond transparent
│   ├── maskable-{192,512}.png  # Android : plein cadre, glyphe dans la zone de sécurité
│   ├── monochrome-{192,512}.png# Android 13+ : icônes à thème
│   └── apple-touch-icon-{152,167,180}.png
└── splash/                     # 12 écrans de lancement iOS, portrait
head.html                       # balises à coller dans <head>
```

## Installation dans Symfony

1. Copier tout le contenu (sauf `head.html` et ce README) dans `public/`.
2. Coller `head.html` dans `templates/base.html.twig`, dans `<head>`.
3. Vérifier que le serveur envoie les bons types MIME :
   - `.webmanifest` → `application/manifest+json`
   - `sw.js` → `application/javascript`, **sans cache long** (`Cache-Control: no-cache`), sinon les mises à jour du service worker ne passent pas.
4. Servir en **HTTPS** — obligatoire pour le service worker (localhost excepté).

Avec AssetMapper ou Webpack Encore, ne versionnez **pas** `sw.js` ni `manifest.webmanifest` : leur URL doit rester fixe. Les icônes peuvent l'être, à condition de mettre à jour les chemins du manifest.

## Ce que fait chaque fichier

**manifest.webmanifest** — nom « MedVue », lancement en plein écran (`standalone`), portrait, langue `fr-BE`. `theme_color` blanc pour épouser la barre d'application ; `background_color` `#F5F7FA` (fond de page) pour l'écran de lancement Android, généré automatiquement à partir de l'icône. Trois raccourcis (appui long sur l'icône) : Mes indisponibilités, Mes gardes, Planning d'équipe — **adaptez les URL** (`/indisponibilites`, `/gardes`, `/planning`) à vos routes.

**Icônes** — trois familles, parce que chaque plateforme recadre différemment :
- `any` : badge au rayon de la marque, coins transparents — navigateur desktop, onglets.
- `maskable` : fond vert plein cadre, glyphe réduit pour tenir dans le cercle de sécurité (80 %). Android découpe en cercle, en carré arrondi ou en goutte selon le fabricant ; rien n'est rogné.
- `monochrome` : glyphe blanc sur transparent, pour les icônes à thème d'Android 13+.

iOS ignore le manifest pour les icônes : il utilise `apple-touch-icon.png`, carré plein sans transparence (iOS arrondit lui-même les coins).

En dessous de 32 px, les icônes utilisent la géométrie renforcée du favicon — même dessin, cellules plus épaisses — pour rester lisibles.

**Écrans de lancement iOS** — fond de page, badge centré. Couvrent les iPhone du SE (750 × 1334) au 16 Pro Max (1320 × 2868) et les iPad 10,9″ et 12,9″, en portrait. Un modèle absent de la liste affiche un écran blanc au lancement : rien de cassé, juste moins soigné.

**sw.js** — volontairement prudent, parce que les données de planning doivent toujours être fraîches :
- **navigation** : réseau d'abord ; sans réseau, `offline.html`.
- **icônes, manifest, polices** : cache d'abord.
- **`/api/…` et toute requête non GET** : jamais mis en cache.

Incrémentez `VERSION` dans `sw.js` à chaque déploiement qui modifie un fichier précaché, sinon les anciens restent servis.

**offline.html** — autonome (styles inline, logo inline) : elle s'affiche même si rien d'autre n'est en cache.

## Tester

- **Chrome DevTools → Application → Manifest** : aucun avertissement, les icônes maskable s'affichent correctement dans l'aperçu « Show only the minimum safe area ».
- **Application → Service workers** : cocher « Offline » puis recharger → `offline.html`.
- **Lighthouse → PWA** : critères d'installabilité au vert.
- **iOS** : Safari → Partager → « Sur l'écran d'accueil ». Vérifier l'icône et l'écran de lancement.

## Limites

- **Pas de mode hors ligne réel** : on affiche une page d'attente, on ne consulte pas le planning sans réseau. Le faire proprement demande de mettre en cache les réponses d'API et de gérer les conflits de synchronisation — à décider côté produit.
- **Notifications push** non incluses : elles exigent des clés VAPID et un endpoint côté serveur (ex. `minishlink/web-push` en PHP). iOS ne les accepte que pour une PWA installée, depuis iOS 16.4.
- **Écrans de lancement paysage** non générés (l'app est verrouillée en portrait).
- **Captures d'écran du manifest** (`screenshots`) absentes : Chrome les affiche dans la boîte d'installation enrichie. À ajouter une fois l'interface figée.
