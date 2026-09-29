# Abonnement agenda — « Mes gardes » dans Google, Apple et Outlook

Décision : `docs/decisions.md` D170. S'appuie sur « Mes gardes » (D168).

## 1. Principe

Chaque personne peut créer une **adresse secrète** de son calendrier de
gardes. Son agenda (Google Agenda, Apple Calendrier, Outlook, ou tout agenda
qui sait « s'abonner à un calendrier par URL ») interroge cette adresse
régulièrement : une garde ajoutée, remplacée ou retirée dans MedVue suit
d'elle-même. Rien n'est écrit dans l'agenda de la personne par MedVue ; le
calendrier abonné est en lecture seule.

## 2. Contenu du flux

Exactement ce que montre « Mes gardes » (`MyDutiesService::dutiesOf()`) :

- calendrier **courant** des lignes **publiées** (un brouillon n'apparaît
  jamais) ; une modification faite sur une ligne publiée apparaît au
  prochain passage de l'agenda, republiée ou non ;
- seulement les gardes de la personne, y compris celles faites sous une
  adhésion close ; aucune coupure par ancienneté ;
- une **unité** = un événement **journée entière** (un bloc samedi +
  dimanche = un seul événement du samedi au dimanche) ; jours non contigus
  → plusieurs événements ;
- titre `Garde {ligne}` ou `Renfort {ligne}`, suivi de ` · {bloc}` ; un
  renfort non requis actuellement ou à confirmer est **provisoire**
  (`STATUS:TENTATIVE`) et le dit dans son titre ;
- description : planning, ligne, type ou bloc ; lien vers le planning dans
  MedVue.

Code : `App\Calendar\DutyCalendarEvents` (gardes → événements, pur),
`App\Calendar\IcsWriter` (RFC 5545 : CRLF, échappement, pliage 75 octets),
`App\Calendar\DutyCalendarFeedRenderer`.

## 3. Modèle

`CalendarFeed` (table `calendar_feeds`) : `user`, `token` (64 hex, 256 bits,
**stocké en clair**, voir D170), `createdAt`, `revokedAt`, `lastFetchedAt`.
Au plus une adresse active par personne (index unique partiel
`uniq_calendar_feeds_active_user`) ; les adresses révoquées sont conservées.

## 4. Endpoints

| Méthode | Route | Auth | Effet |
|---|---|---|---|
| GET | `/api/me/calendar-feed` | JWT | `{feed: null \| {token, createdAt, lastFetchedAt}}` |
| POST | `/api/me/calendar-feed` | JWT | crée l'adresse si besoin (idempotent) |
| POST | `/api/me/calendar-feed/regenerate` | JWT | nouvelle adresse, l'ancienne cesse aussitôt |
| DELETE | `/api/me/calendar-feed` | JWT | révoque l'adresse (204, idempotent) |
| GET/HEAD | `/api/calendar-feeds/{token}.ics` | **aucune** — le jeton est la capacité | `text/calendar`, 404 identique pour un jeton inconnu, révoqué ou un compte désactivé |

Dates JSON en UTC (`DATE_ATOM`). `lastFetchedAt` est réécrit au plus une
fois par heure.

## 5. Frontend

Bouton « Ajouter à mon agenda » sur `/my-duties` → `CalendarSubscriptionModal`
(`frontend/src/features/duties/`). Liens construits par
`subscriptionLinks()` depuis `VITE_API_URL` :

- Google : `https://calendar.google.com/calendar/render?cid=<webcal encodé>`
  (sur téléphone, s'ouvre dans le navigateur ; l'application Google Agenda
  affiche ensuite le calendrier une fois ajouté au compte) ;
- Apple (iPhone, iPad, Mac) : `webcal://…` ;
- Outlook.com / Microsoft 365 :
  `https://outlook.{live,office}.com/calendar/0/addfromweb?url=<https>&name=…` ;
- tout autre agenda : copier le lien.

## 6. Limites connues

- Le délai de mise à jour dépend de l'agenda, pas de MedVue : quelques
  heures en général, **jusqu'à 24 h pour Google** (le flux propose 6 h via
  `REFRESH-INTERVAL`/`X-PUBLISHED-TTL`, Google l'ignore).
- En développement, l'adresse pointe sur `localhost` : Google et Outlook
  (serveurs distants) ne peuvent pas la lire ; seul un agenda local peut.
- Pas d'heure de garde : événements journée entière (dette D136).
- La réinitialisation du mot de passe ne révoque pas l'adresse (choix D170).
