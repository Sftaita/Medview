# Template — Instructions de déploiement production (à adapter)

> **Ce fichier est un template générique**, extrait de la méthodologie de
> déploiement de SurgicalHub (voir `docs/deployment-versioning.md`,
> `docs/production.md`, `docs/backup-and-restore.md`, `docs/mail-safe-mode.md`,
> `docs/ssh-setup.md`). Il ne contient **aucune information spécifique à
> SurgicalHub** — copiez-le dans le nouveau projet (par ex.
> `docs/deployment-versioning.md`) et remplacez tous les `<PLACEHOLDERS>`.
>
> Objectif : donner à Claude un cadre pour faire des déploiements en
> production **disciplinés, vérifiables et réversibles**, sur un nouveau
> domaine/serveur totalement indépendant de SurgicalHub.

---

## 0. À adapter avant utilisation

Remplacer partout :

- `<APP_NAME>` — nom du projet/app
- `<DOMAIN>` — domaine public (ex. `app.example.com`)
- `<SERVER_HOST>` / `<SERVER_USER>` — accès SSH serveur
- `<DEPLOY_PATH>` — chemin du vrai compose de prod sur le serveur
- `<BACKEND_DIR>` / `<FRONTEND_DIR>` — dossiers déployés (adapter à la stack réelle)
- `<MIGRATION_CMD>` — commande de migration DB de la stack (ex. `doctrine:migrations:migrate`, `prisma migrate deploy`, `alembic upgrade head`...)
- `<CACHE_CLEAR_CMD>` — commande de vidage de cache de la stack, si applicable
- Noms de conteneurs Docker réels (app/web/worker/db/cache)

---

## 1. Règle d'or : jamais d'état intermédiaire en production

La production ne doit **jamais** se retrouver dans un état incohérent :

- DB avec des migrations plus récentes que le code réellement déployé (ou l'inverse)
- Code déployé plus récent que les migrations appliquées
- Déploiement partiel / cherry-pick de fichiers
- Copie manuelle de fichiers en dehors de la procédure documentée
- Version réellement en cours d'exécution inconnue ou non vérifiable

Si l'une de ces situations est détectée à n'importe quel moment : **s'arrêter,
documenter l'état exact constaté, et obtenir une décision explicite** —
ne jamais "corriger" silencieusement une incohérence sans la signaler.

## 2. Interdiction du déploiement partiel (anti-cherry-pick)

- Si le serveur a plus d'un commit de retard sur le HEAD local, il est
  **interdit** de déployer un sous-ensemble de fichiers/commits.
- Avant tout déploiement : produire et afficher un **rapport d'écart**
  (jamais sauté) contenant :
  - commit HEAD local
  - dernier tag `*-prod*` connu
  - commit réellement déployé sur le serveur (voir §3, technique du marqueur)
  - diff commits/fichiers entre les deux
  - migrations en attente
  - toute anomalie (ex. état DB plus avancé que le code associé)
- Par défaut, si l'écart dépasse 1 commit : déployer le **HEAD complet**,
  jamais un sous-ensemble — c'est la seule garantie de cohérence mutuelle
  entre les fichiers.

## 3. Technique du marqueur de fichier

S'il n'y a pas de `.git` sur le serveur (code copié manuellement), on ne
peut **jamais** supposer que le dernier tag connu reflète la réalité.

Pour vérifier ce qui tourne réellement : chercher sur le serveur (`grep`
dans les fichiers déployés) une chaîne/un changement introduit par un
commit précis ("marqueur"), et comparer avec le code local. Ne jamais
se fier au tag seul.

## 4. Construire l'artefact depuis `git archive`, jamais depuis le working tree

```bash
git archive --format=tar.gz HEAD -- <BACKEND_DIR> <FRONTEND_DIR> > /tmp/deploy_$(date +%Y%m%d_%H%M%S).tar.gz
scp /tmp/deploy_*.tar.gz <SERVER_USER>@<SERVER_HOST>:/tmp/
```

Ça garantit qu'aucun fichier non committé/non suivi ne se retrouve déployé :
déployé == committé == ce qui sera taggé.

## 5. Séquence mécanique de déploiement

1. Local : `git status` propre, identifier HEAD et dernier tag `*-prod*`.
2. Serveur : identifier le commit réellement déployé (marqueur, §3) et
   l'état des migrations.
3. Rapport d'écart (§2) ; s'arrêter et demander validation si écart > 1 commit.
4. **Backups d'abord** (dump DB + tar du code actuellement déployé) —
   toujours avant toute écriture sur le serveur.
5. Construire l'artefact (`git archive`, §4) → `scp` vers `/tmp` du serveur.
6. Extraire l'archive par-dessus le dossier source du serveur.
7. Vérifier l'état des migrations ; lancer `<MIGRATION_CMD> --dry-run` ;
   relire manuellement le SQL généré — confirmer que les instructions
   destructrices (`DROP`/`DELETE`/`TRUNCATE`) n'apparaissent que dans un
   rollback, jamais dans la migration forward, sauf si explicitement
   requis et documenté. Ne jamais lancer une migration non relue.
8. Rebuild des conteneurs `--no-cache` (en tâche de fond/`nohup`, c'est
   lent — poller un fichier de log pour un marqueur de code de sortie
   explicite plutôt que bloquer).
9. `docker compose up -d` pour recréer les conteneurs.
10. Lancer la migration réelle (`--no-interaction` ou équivalent).
11. `<CACHE_CLEAR_CMD>` puis **redémarrer** le conteneur app (le cache vit
    souvent dans un volume : un simple clear ne suffit pas, il faut le
    restart pour qu'il prenne effet) ; redémarrer séparément le
    worker/consumer async si du code de handler de message a changé
    (un process long-running garde en mémoire les anciennes
    classes/templates tant qu'il n'est pas redémarré — piège classique).
12. **Checks de santé obligatoires** :
    - frontend charge (`200`)
    - API répond sans `500`
    - fichier de secrets/`.env` non exposé publiquement (`404`)
    - login réel produit un token valide
    - endpoint "qui suis-je" renvoie le bon rôle
    - tous les conteneurs attendus sont `Up`
    - logs app + worker sans erreur critique liée au déploiement
    - migrations : "déjà à la dernière version"
13. Si une fonctionnalité majeure a changé : un **test fonctionnel réel
    ciblé** supplémentaire, via le vrai domaine public/API (pas seulement
    en `exec` dans le conteneur) — voir §7 pour la garde anti-effets-de-bord.
14. Seulement une fois tout au vert : créer le tag annoté, le pousser,
    vérifier que le commit taggé existe bien sur le remote, ajouter
    (jamais réécrire) une ligne à l'historique des versions déployées.

### Rollback

Restaurer le dump DB, restaurer l'archive code pré-déploiement, rebuild
`--no-cache` + `up -d`, vérifier les logs. Ne jamais taguer un état
rollback — documenter l'incident à la place. Le "dernier bon tag" reste
celui d'avant la tentative échouée.

## 6. Tags et historique — règles immuables

- Tag uniquement **après validation complète**, jamais avant.
- Format `v<YYYY.MM.DD>-prod[-N]` — un tag par jour calendaire de
  déploiement, suffixe `-2`, `-3`... si plusieurs déploiements le même jour.
- Le tag doit pointer sur un commit déjà poussé sur origin (pousser le
  code d'abord si besoin) — un tag non poussé ne sert à rien à l'équipe.
- Le dernier tag `*-prod*` par ordre chronologique est, par définition,
  la source de vérité unique de "ce qui tourne actuellement" — jamais
  une supposition/un souvenir.
- L'historique des versions déployées et l'historique des incidents sont
  des logs **append-only** : nouvelle ligne ajoutée, jamais de ligne
  existante réécrite.
- Après chaque déploiement (succès ou échec), rapport final obligatoire :
  version/tag/commit, écart trouvé avant déploiement, chemins des backups,
  migrations appliquées, état des conteneurs, tableau des checks de santé,
  résultats des tests fonctionnels ciblés + confirmation du nettoyage,
  vérification des logs, et toute "limite assumée" (ce qui n'a
  délibérément pas été testé, et pourquoi).

## 7. Garde anti-effets-de-bord réels pendant les tests en prod

Pattern générique (email, SMS, paiement, webhook sortant... — tout appel
sortant avec effet réel) :

- Un **unique intercepteur central**, branché sur l'événement de plus bas
  niveau du framework qui se déclenche pour *chaque* appel sortant de ce
  type — jamais un intercepteur par point d'appel, pour qu'aucun nouveau
  chemin de code ne puisse le contourner par accident.
- Deux modes quand la garde est active :
  - **Capture** : si le canal configuré est un récepteur local vérifié et
    non routable (ex. un attrape-mail local), laisser passer le payload
    tel quel (cibles/destinataires réels visibles pour un test réaliste)
    mais le taguer et le logger — le canal étant physiquement incapable
    d'atteindre l'extérieur, c'est sans risque.
  - **Liste blanche** : si le canal pourrait réellement atteindre
    l'extérieur, filtrer/rejeter toute cible absente d'une liste blanche
    explicite (correspondance exacte ou domaine/namespace interne
    uniquement), et annuler l'opération entière si plus rien ne reste
    après filtrage.
- Le choix du mode ne se base **jamais** sur le seul nom d'environnement
  (pas juste "est-ce dev ou prod") mais sur deux faits vérifiables
  indépendamment : (a) la garde est-elle active, (b) le transport
  réellement configuré correspond-il à un récepteur local connu-sûr. Une
  variable d'env mal configurée ne peut donc jamais désactiver
  silencieusement la protection en se faisant passer pour sûre — une
  demande explicite de "mode capture" contre un transport non local est
  refusée et retombe en mode liste blanche strict, loggé en critique.
- Une bascule explicite permet de forcer la garde active même en
  production, pour une fenêtre de test manuel contrôlée (activer → tester
  → désactiver immédiatement après). L'activation exige un vrai rechargement
  de la config de l'app (un simple restart de process peut ne pas relire
  le fichier de config — utiliser la commande complète de "recréation").
- Les envois sortants étant souvent traités en asynchrone (file
  d'attente), le point d'interception se déclenche au moment de l'envoi
  réel, pas de la mise en file — les logs du point de mise en file ne
  montrent qu'une "intention", pas le résultat réel ; seuls les logs de
  la garde elle-même font foi de ce qui a été réellement bloqué/capturé/envoyé.
- Ce mécanisme est un filet **technique** pour une discipline
  **procédurale** (ex. "toujours utiliser des comptes de test jetables")
  qui a échoué une fois en pratique — défense en profondeur, pas un
  remplacement de la discipline.

## 8. Backups & restauration

- Deux types de backup : dump DB complet (compressé) et archive des
  fichiers/volumes de données uploadés par l'app.
- Planifié nocturne : dump → archive → sync hors-site (stockage cloud) →
  rotation, chaque étape en script/cron séparé, décalés de quelques
  minutes, avec un fichier de log partagé.
- Convention de nommage : `<quoi>_<YYYYMMDD_HHMMSS>.<ext>.gz`, un dossier
  plat par type.
- Rétention : fenêtre fixe (ex. 30 jours) en local, rotée par un script
  dédié ; copie hors-site comme filet durable.
- Mot de passe/secrets des scripts de backup lus depuis un fichier
  protégé à l'exécution, jamais en dur dans le script ou le crontab.
- Restauration en deux modes : **écrasement d'urgence** (dump réinjecté
  directement dans la DB live) et **vérification préalable** (restaurer
  dans une DB temporaire jetable, vérifier compte de tables/lignes, puis
  la supprimer) — préférer ce second mode sauf urgence réelle.
- Checklist mensuelle : pas d'erreurs dans les logs de backup, backups
  récents, sync hors-site à jour, espace disque suffisant, un vrai test
  de restauration en DB temporaire fonctionne encore, la rotation coupe
  correctement, les jobs planifiés sont toujours enregistrés.

## 9. Accès SSH au nouveau serveur

- Générer une paire de clés ed25519 dédiée à ce projet/serveur
  (`ssh-keygen -t ed25519`) — ne pas réutiliser une clé personnelle
  générale.
- Installer la clé publique dans `authorized_keys` d'un utilisateur de
  déploiement dédié, non privilégié (jamais `root`).
- Ajouter un alias `Host` dans `~/.ssh/config` (hostname/IP, user,
  identity file) pour que `ssh <alias>` fonctionne sans réécrire les flags.
- Documenter uniquement les clés **publiques** (traçabilité des accès) —
  les clés privées ne touchent jamais le repo/la doc, restent sur la
  machine d'origine, permissions `600`.
- Si le serveur doit pull depuis un remote Git privé : générer une paire
  de clés **séparée, sur le serveur lui-même**, enregistrer sa clé
  publique comme deploy key en lecture seule sur l'hébergeur Git (accès
  écriture désactivé sauf si le serveur doit pousser), config SSH dédiée
  avec `IdentitiesOnly yes` pointant vers cette clé — isole le credential
  de lecture du serveur de toute clé personnelle humaine.
- Principe général : une paire de clés par frontière de confiance
  (ta machine → serveur, serveur → hébergeur Git), jamais de
  partage/réutilisation entre frontières, documenter chaque
  ajout/rotation avec date et raison.

## 10. Autres règles structurantes

- L'environnement Docker local reflète la structure conceptuelle de la
  prod (conteneurs séparés app/web/worker/db/cache) — un vrai
  attrape-mail local (type Mailpit) en dev permet de vérifier les flux
  email visuellement sans jamais sortir de la machine.
- Les process worker/consumer long-running gardent en mémoire le
  code/templates chargés — toujours redémarrer explicitement le worker
  après un déploiement touchant des handlers de jobs en arrière-plan ou
  leurs templates.
- Les migrations ne sont **jamais** auto-lancées par le démarrage des
  conteneurs — toujours une commande explicite et séparée, avec
  `--dry-run` relu comme garde obligatoire avant le run réel.
- Historique d'incidents append-only : date, version déployée,
  description, root cause, impact, actions correctives et préventives —
  jamais réécrit, uniquement complété.
- Comptes de test jetables (créés via une commande CLI qui court-circuite
  le flux d'inscription normal — "aucun email envoyé"), taggés avec un
  domaine interne non routable, et toujours vérifier + enregistrer que le
  nettoyage a laissé zéro résidu (une requête de confirmation, pas
  seulement "j'ai supprimé").
- Pour une opération de test réellement irréversible sans suppression
  possible : ne pas exécuter le chemin de succès en production — valider
  le chemin d'erreur/validation à la place, et documenter explicitement
  le chemin de succès non testé comme "limite assumée".
- Préférer une preuve de bout en bout via le vrai domaine public/API
  (à travers le reverse proxy/CORS) plutôt qu'un simple `exec` interne au
  conteneur — ça détecte des problèmes de proxy/CORS/routing invisibles
  autrement.
- Édition de secrets sur un serveur live : éviter les outils/éditeurs qui
  pourraient afficher le fichier entier (fuite d'autres secrets dans le
  terminal/les logs) — préférer un petit script de remplacement ciblé
  d'une seule variable à la fois.

---

## Ce qu'il ne faut PAS copier tel quel depuis SurgicalHub

Si vous partez du texte réel de `docs/deployment-versioning.md` de
SurgicalHub pour rédiger ce fichier dans le nouveau projet, retirez :

- Domaine, IP serveur, user SSH, noms de conteneurs, chemins spécifiques
  à SurgicalHub.
- Commandes spécifiques Symfony/Doctrine ou React/Vite — ne garder que
  comme illustration si des exemples concrets sont utiles pour la
  nouvelle stack.
- Tout workflow métier (planning, missions, instrumentistes, chirurgiens,
  facturation...).
- Le contenu de `production-hostinger.md` (hébergement mutualisé
  remplacé par le VPS Docker) — spécifique à l'hébergeur, pas transposable
  à un déploiement Docker/VPS générique.
- Les noms exacts de variables d'env / classes du garde anti-effets-de-bord
  (`MAIL_SAFE_MODE`, etc.) — ne réutiliser que le pattern abstrait du §7.
- Toute config rclone/cloud storage spécifique (compte, noms de dossiers).
