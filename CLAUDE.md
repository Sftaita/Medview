# MedVue

## But de l'application

MedVue remplace **Lifen Planning** pour la gestion des gardes médicales.
Objectif final : permettre à des équipes médicales de gérer leurs gardes sur
plusieurs années **sans perdre ni l'historique ni l'équité**, avec un
système fiable là où l'existant repose sur du planning manuel ou
semi-automatique.

L'application doit gérer, à terme :

- plusieurs **équipes**, chacune avec ses membres, ses rôles, ses règles ;
- plusieurs **périodes de planning** par équipe, distinctes de l'équipe
  elle-même ;
- les **indisponibilités** des utilisateurs, déclarées une seule fois dans
  un calendrier global partagé par toutes leurs équipes ;
- la **génération automatique équitable** des gardes (moteur dédié, hors
  contrôleurs) ;
- l'**historique permanent** des affectations, jamais recalculé depuis
  l'état courant ;
- les **échanges de garde** et les **notifications**.

Le cahier des charges complet (40 sections : modèle de données détaillé,
règles d'équité multi-dimensionnelle, contraintes dures/souples, snapshot
de génération, etc.) a été fourni au lancement du projet et guide toutes
les décisions de modélisation à venir.

## Principe non négociable : l'équité est multidimensionnelle

Le moteur de planning (à venir) ne devra **jamais** optimiser un score
global unique — un utilisateur sans week-end pourrait compenser par de
nombreuses petites gardes de semaine. Chaque dimension (score, week-ends,
vendredis/samedis/dimanches, jours fériés, espacement, préférences) doit
être suivie et équilibrée séparément, avec des contraintes dures/souples
clairement distinguées et un résultat explicable (pas de boîte noire).
Ce principe structure tout le modèle de données à venir — à garder en tête
dès qu'une entité liée aux gardes ou aux plannings est conçue. Design
détaillé (encore conceptuel, rien d'implémenté) :
[`docs/allocation-algorithm.md`](docs/allocation-algorithm.md).

## État d'avancement

| Étape | Statut | Référence |
|---|---|---|
| Socle technique (Symfony + React + Docker) | ✅ Livré | `README.md` |
| Authentification (User, JWT, register/login/me) | ✅ Livré | `docs/authentication.md` |
| Équipes, invitations, rôles | ⏳ Pas commencé | — |
| Indisponibilités, campagnes de collecte | ⏳ Pas commencé | — |
| Moteur de génération, équité, historique | ⏳ Design conceptuel écrit, pas implémenté | `docs/allocation-algorithm.md` |
| Échanges de garde, notifications, export calendrier | ⏳ Pas commencé | — |

## Où trouver quoi

- **`docs/decisions.md`** — journal chronologique de chaque choix technique
  structurant, avec son contexte et ses compromis. **À consulter avant de
  remettre en cause une décision existante**, et à compléter à chaque
  nouvelle décision non triviale.
- **`docs/authentication.md`** — modèle de données, stratégie JWT, sécurité,
  décisions ouvertes de la fonctionnalité d'authentification. Un document
  du même type sera créé pour chaque fonctionnalité majeure suivante
  (`docs/teams.md`, …).
- **`docs/allocation-algorithm.md`** — design du moteur de répartition des
  gardes (équité multidimensionnelle, contraintes, pipeline de
  génération). **Document vivant** : encore conceptuel, à corriger et
  compléter à chaque étape d'implémentation réelle du moteur — jamais
  laisser le document diverger silencieusement du code une fois que
  celui-ci existe.
- **`README.md`** — arborescence, choix techniques, commandes pour lancer
  le projet, URLs, tests exécutés.

## Conventions de travail sur ce projet

- **Aucune logique métier dans les contrôleurs** — elle vit dans des
  services dédiés (`src/Service/`). Les contrôleurs orchestrent
  (désérialisation, validation, appel au service, mise en forme de la
  réponse), rien de plus.
- **API Platform seulement là où c'est pertinent** — pas de ressource CRUD
  générée automatiquement sur une entité sensible (ex. `User`, voir
  `docs/decisions.md` D009) sans réflexion explicite sur ce qui doit
  réellement être exposé.
- **Un planning publié n'est jamais supprimé**, seulement archivé.
  L'historique des affectations n'est jamais recalculé depuis l'état
  courant — il doit rester disponible même si un membre quitte l'équipe,
  qu'une période est archivée, ou que les règles de score changent
  ensuite.
- **Progression incrémentale** : avant toute implémentation importante,
  analyser l'existant, proposer le modèle de données, identifier les
  contraintes, vérifier les impacts, implémenter, tester, documenter.
  Pas de refonte massive non justifiée.
- **Tests systématiques** pour toute fonctionnalité importante (unitaires
  + fonctionnels côté backend, au minimum le flux principal côté
  frontend), et cas limites couverts explicitement (voir la liste de la
  section 39 du cahier des charges original : membre ajouté/quitté en
  cours d'année, désactivation, impossibilité mathématique de remplir un
  planning, etc.) au moment où la fonctionnalité concernée est construite.

## Démarrer le projet

Voir `README.md` (`docker compose up -d --build`, URLs, commandes de
test/lint). Résumé : backend Symfony sur http://localhost:8010, frontend
React sur http://localhost:5183, PostgreSQL sur `localhost:5432`.
