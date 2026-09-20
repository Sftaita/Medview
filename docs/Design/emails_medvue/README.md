# MedVue — emails transactionnels d'invitation d'équipe

Trois templates Twig prêts à déposer dans un projet Symfony.

```
templates/email/
├── _base.html.twig                              # le modèle : toute la mécanique email
├── _components.html.twig                        # macros de contenu (p, info, warning, rows…)
├── team_invitation_existing_account.html.twig   # 1. ajouté à une équipe (compte existant)
├── team_invitation_new_account.html.twig        # 2. invitation (pas encore de compte)
└── account_created_welcome.html.twig            # 3. compte créé + bienvenue
preview/                                          # rendus avec données d'exemple (non déployés)
```

Les trois emails **étendent `_base.html.twig`** : ils ne contiennent que du contenu (une quinzaine de lignes chacun). Toute la plomberie email — tables, largeur 600, styles inline, préheader, bouton bulletproof, correctifs Outlook, media queries, pied de page — vit dans le modèle, en un seul endroit.

## Créer un nouvel email

```twig
{% extends 'email/_base.html.twig' %}
{% import  'email/_components.html.twig' as ui %}

{% set preheader  = 'Votre planning de la semaine est publié.' %}
{% set accent     = '#2D7FF9' %}          {# '#42A882' vert · '#2D7FF9' bleu (action requise) #}
{% set eyebrow    = 'Planning' %}
{% set h1         = 'Votre planning<br>de la semaine' %}
{% set panelLabel = 'Équipe' %}
{% set panelTitle = teamName %}
{% set panelSub   = 'Semaine du ' ~ weekStart %}
{% set ctaLabel   = 'Ouvrir le planning' %}
{% set ctaUrl     = planningUrl %}
{% set footerNote = 'Vous recevez cet email car vous êtes membre d’une équipe MedVue.' %}

{% block content %}
{{ ui.p('Bonjour ' ~ firstName ~ ',', 24) }}
{{ ui.p('Le planning de l’équipe ' ~ ui.b(teamName) ~ ' vient d’être publié.', 18) }}
{{ ui.rows([['Équipe', teamName], ['Période', weekStart ~ ' → ' ~ weekEnd], ['Gardes', shiftCount]]) }}
{% endblock %}

{% block after_cta %}
{{ ui.warning('Modifications possibles.', 'Le planning peut évoluer jusqu’à 48 h avant le début de la période.') }}
{% endblock %}
```

### Variables du modèle

| Variable | Rôle |
|---|---|
| `preheader` | texte d'aperçu masqué (~85 caractères) — **obligatoire** |
| `accent` | couleur du filet haut et de l'eyebrow : `#42A882` (vert) ou `#2D7FF9` (bleu, action requise) |
| `eyebrow` | petit label en capitales au-dessus du titre |
| `h1` | titre ; le `<br>` est autorisé |
| `panelLabel` / `panelTitle` / `panelSub` | panneau d'en-tête ; `panel = false` le masque |
| `panelStyle` | `'soft'` (fond clair, défaut) ou `'solid'` (aplat vert) |
| `ctaLabel` / `ctaUrl` | bouton ; sans `ctaLabel`, pas de bouton |
| `showFallbackUrl` | URL en clair sous le bouton (défaut `true`) |
| `footerNote` | phrase de contexte d'envoi dans le pied de page |
| `supportUrl` / `supportEmail` | liens du pied de page (défauts fournis) |

Blocs surchargeables : `title`, `content` (obligatoire), `after_cta`, `signature`, `postal`.

### Macros de contenu

Chaque macro produit **une ligne de tableau** et s'utilise dans `content` ou `after_cta` :

| Macro | Usage |
|---|---|
| `ui.p(html, top)` | paragraphe 16/26 ; `top` = espace au-dessus (24 pour le premier) |
| `ui.b(text)` | fragment gras **échappé** — à composer dans `ui.p()` |
| `ui.info(titre, html)` | encart gris : information de contexte |
| `ui.warning(titre, html)` | encart ambre : précaution, lien personnel, expiration |
| `ui.success(titre, html)` | encart vert : confirmation |
| `ui.small(html)` | mention discrète |
| `ui.rows([[clé, valeur], …])` | tableau récapitulatif clé / valeur |
| `ui.divider()` | filet de séparation |

Le HTML passé aux macros est rendu tel quel : n'y injectez jamais de saisie utilisateur brute — passez-la par `ui.b()`, qui échappe son argument, ou par `|e`.

## Envoi (Symfony Mailer)

```php
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// 1. Ajouté à une équipe — le destinataire a déjà un compte
$email = (new TemplatedEmail())
    ->to($user->getEmail())
    ->subject(sprintf("Vous avez rejoint l'équipe %s sur MedVue", $team->getName()))
    ->htmlTemplate('email/team_invitation_existing_account.html.twig')
    ->context([
        'firstName'   => $user->getFirstName(),
        'inviterName' => $inviter->getFullName(),
        'teamName'    => $team->getName(),
        'loginUrl'    => $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
    ]);

$mailer->send($email);
```

### Contextes attendus

| Template | Variables |
|---|---|
| `team_invitation_existing_account` | `firstName`, `inviterName`, `teamName`, `loginUrl` |
| `team_invitation_new_account` | `firstName`, `inviterName`, `teamName`, `signupUrl` |
| `account_created_welcome` | `firstName`, `inviterName`, `teamName`, `teamUrl` |

Optionnelles dans les trois : `supportUrl` (défaut `https://medvue.app/aide`) et `supportEmail` (défaut `support@medvue.app`).

**Toutes les URL doivent être absolues** (`UrlGeneratorInterface::ABSOLUTE_URL`) : un chemin relatif ne fonctionne pas dans un client mail.

### Objets suggérés

1. `Vous avez rejoint l'équipe {teamName} sur MedVue`
2. `{inviterName} vous invite à rejoindre l'équipe {teamName} sur MedVue`
3. `Bienvenue sur MedVue — vous avez rejoint l'équipe {teamName}`

L'objet n'est pas dans le template : il se définit sur l'objet `TemplatedEmail`. Chaque fichier le rappelle en commentaire Twig d'en-tête.

### Lien d'inscription (template 2)

`signupUrl` doit être un **lien signé, personnel et à usage unique** — le corps de l'email l'annonce explicitement. Utilisez `UriSigner` ou un token en base avec expiration :

```php
'signupUrl' => $this->uriSigner->sign(
    $this->urlGenerator->generate('app_register_from_invitation',
        ['token' => $invitation->getToken()],
        UrlGeneratorInterface::ABSOLUTE_URL
    )
),
```

## Version texte

Symfony n'en génère pas automatiquement. Ajoutez un `.txt.twig` à côté de chaque fichier et référencez-le avec `->textTemplate(...)`, ou laissez le bundle `twig/cssinliner-extension` faire le rendu texte. Sans version texte, certains filtres anti-spam pénalisent l'envoi.

## Contraintes de rendu respectées

- Tables imbriquées `role="presentation"`, largeur fixe 600 px, une seule colonne.
- **Styles 100 % inline** ; le `<style>` en `<head>` ne porte que les media queries (plusieurs clients le suppriment — l'email reste correct sans).
- Aucune image, aucune police distante : la mise en forme est faite de cellules colorées, de filets et de typographie. Polices Arial / Helvetica.
- Boutons « bulletproof » : `<td bgcolor>` + `<a display:inline-block>`, jamais d'image ni de `<button>`.
- Largeurs explicites et `mso-line-height-rule:exactly` pour le moteur Word d'Outlook ; bloc conditionnel `<!--[if mso]>` pour le DPI.
- `<meta name="color-scheme" content="light dark">` et aucune couleur pure `#000` / `#fff` en fond, pour survivre au mode sombre.
- Préheader masqué (~85 caractères) en premier élément du `<body>`.
- Chaque fichier pèse ~10–13 Ko, très loin de la coupure Gmail à 100 Ko.
- Repli sous le bouton : l'URL en clair, cliquable.

## Identité visuelle

Reprise de la charte Surgery Hub : vert de marque `#42A882`, fond de page `#F5F7FA`, cartes blanches à filet `#E7EBEF`, rayons 12 px, hiérarchie typographique et échelle de 4 px. Le bleu `#2D7FF9` distingue l'email 2 (invitation, action requise) ; l'ambre `#F0A91B` porte l'avertissement « lien personnel ».

## À confirmer avant envoi

- **Adresse postale du pied de page** — `Rue de la Loi 1, 1000 Bruxelles` est un placeholder.
- **Logo** — la marque est composée typographiquement (pastille verte « M » + mot « MedVue »). Pour un vrai logo, héberger un PNG en https et remplacer la cellule d'en-tête, avec `alt="MedVue"`.
- **Domaines** `medvue.app` utilisés dans les liens d'aide et de support.
- Ces emails sont **transactionnels** : pas de lien de désinscription. Si un email marketing s'ajoute à la série, il en faudra un.
