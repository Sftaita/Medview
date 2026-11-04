# Plan de déploiement : blocs hérités et refonte des périodes (PR #34, #35)

**Exécuté le 2026-11-03 (`v2026.11.03-prod`, journal : `docs/deployment.md` §6).** Preuves de la répétition :
`docs/recette/production-20261103/`. Séquence générale : `docs/deployment.md` §3.

## Périmètre

- De `v2026.11.02-prod` (`6a3ad7a`) à `master` `a727973`, ou au commit validé le jour J : backend, worker et frontend.
- Deux migrations : `Version20261103090000` et `Version20261104090000`.
- Aucune nouvelle variable d'environnement, aucun cron, aucun changement de `docker-compose.prod.yml`, aucune nouvelle
  dépendance.

## Avant

1. CI verte sur le commit exact à déployer ; production vérifiée fichier par fichier sur `6a3ad7a`.
2. Vérifier en lecture seule : aucun job `QUEUED`/`RUNNING`, migration `Version20261102090000`, 120 affectations courantes.
   Si ces chiffres ont changé, comprendre pourquoi avant de continuer.
3. Choisir un moment sans usage en cours par les gestionnaires et prévenir le créateur de Trauma Delta.

## Jour J

1. Sauvegarde manuelle `medvue-backup.sh`, puis `medvue-restore-test.sh --compare-live`. **Arrêt si ce n'est pas PASS.**
2. `medvue-offsite.sh` à la main : le cron hors site n'est toujours pas installé (dernier contrôle 2026-11-03).
3. Empreinte de chaque ligne avant ; `ps`, images, volumes et Traefik enregistrés dans `deploy-<date>/`.
4. Archive du commit en LF, vérifiée puis extraite ; images construites avec `APP_VERSION=v<date>-prod`.
5. **Migration avant la bascule**, depuis un conteneur jetable de la nouvelle image sur `medvue-internal` seulement, avec
   l'environnement du backend en fichier 600 supprimé ensuite (jamais `--env-file .env`, jamais `compose run`). Le SQL de
   `--dry-run --write-sql` doit être identique à `migration_up.sql`, sinon **arrêt**.
6. Bascule `up -d --no-build backend worker frontend` juste après les crons de :00 ou :30, aucun job actif.
7. `cache:clear`, `jwt --skip-if-exists` (clé publique identique), `restart`, `check-trusted-proxy.sh`.
8. Attendu : `api.medvue.be` répond 404 Traefik environ 30 s, jusqu'à ce que le backend soit `healthy`.

## Après

Contrôles, tous sans écriture sur les gardes :

- santé, `.env` et clés non exposés, `/api/me` 401 sans jeton ;
- `app:solver:smoke` dans backend et worker, worker consommateur, `messenger:failed:show` vide, `app:planning-cycles:audit` ;
- crons suivants passés sur les nouveaux conteneurs ;
- empreinte après : tables métier identiques, sauf la ligne de migration, la synchronisation SurgicalHub et la lecture d'un
  flux agenda.

**Interdits pendant ces contrôles** : génération, complétion, `GET /fill-preview` (il matérialise des gardes sur une ligne
jamais générée), `PATCH` d'une période, publication, envoi d'e-mail.

Puis recette authentifiée **par le créateur**, en lecture : détail de Trauma Delta, action principale, onglets, calendrier
(120 gardes), « Modifier les dates » ouvert puis annulé. `index.html` n'étant pas protégé du cache, un navigateur peut garder
l'ancienne interface plusieurs heures : recharger en forçant.

Enfin : tag `v<date>-prod`, ligne dans `docs/deployment.md` §6, statut dans `CLAUDE.md`, par une PR de documentation.

## Changements visibles dès la mise en ligne

- « Modifier les dates » devient disponible sur la période 1 publiée de Trauma Delta et sur le début de la période 2. Rien ne
  s'applique sans confirmation, mais les titulaires et le journal sont réels dès le premier « Confirmer ».
- Une nouvelle ligne ajoutée rejoint aussi la période publiée.
- Les lignes principales passent en semaine type générale ; leur structure ne change pas (lue sur la ligne tant qu'aucun
  modèle n'est enregistré).

## Retour arrière

| Situation | Procédure | Preuve |
|---|---|---|
| Échec avant la bascule | Laisser l'ancien code : il lit les 120 gardes à l'identique sur le nouveau schéma. `down` possible | Répété |
| Après la bascule, aucun changement de dates ni configuration héritée | Revenir à l'archive de `v2026.11.02-prod`, puis `down` vers `Version20261102090000` : aucune ligne modifiée | Répété |
| Après un changement de dates réel | `down` **refusé**. Le code seul n'est **pas sûr** : l'ancien code ignore `retired_at` et remontrerait les gardes retirées comme actives sans titulaire (déduit du code, non testé). Corriger en avant | Refus répété |
| Après une configuration héritée | `down` de `Version20261103090000` refusé de la même façon. Corriger en avant | Garde lue dans le code, non répétée |
| Dernier recours | Restaurer le dump du jour J et l'archive précédente (`docs/deployment.md` §4). **Perd tout ce qui a été fait depuis.** Décision de l'utilisateur uniquement | Procédure de restauration testée au point 1 du jour J |

Avant le premier changement de dates réel, le retour arrière reste simple ; c'est le moment où il faut avoir fini de vérifier.

## Non vérifié à la date du déploiement

- L'interface sur les vraies données de production (vérifiée en recette et sur la pile de dev).
- Un vrai SMTP et un vrai téléphone pour les nouveaux parcours.
- Le parcours de republication personnalisée en conditions réelles.
