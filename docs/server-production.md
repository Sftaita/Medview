# Server Production — Hostinger VPS

> Documentation d’accès et d’architecture du serveur de production partagé.
>
> Ce fichier ne doit contenir **aucun secret** : pas de mot de passe, pas de clé privée SSH, pas de token, pas de secret applicatif.
>
> Objectif : permettre à Claude Code ou à tout autre intervenant autorisé de comprendre immédiatement comment accéder au serveur, où sont les applications, quelles conventions respecter et surtout quoi **ne pas modifier**.

---

## 1. Serveur

- **Hébergeur** : Hostinger
- **Type** : VPS Docker
- **IP publique** : `187.124.55.15`
- **Utilisateur SSH de déploiement** : `deploy`
- **Alias SSH local actuellement utilisé** : `surgicalhub-prod`
- **Connexion** :

```bash
ssh surgicalhub-prod
```

L’utilisateur `deploy` est non-root et membre du groupe `docker`.

Ne jamais utiliser `root` pour les opérations courantes de déploiement.

---

## 2. Organisation générale

Le VPS héberge plusieurs applications indépendantes.

Répertoire principal :

```text
/opt/stack
```

Applications :

```text
/opt/stack/apps/
├── surgicalhub/
├── medatwork/
├── medclick/
└── medvue/
```

Chaque application doit être considérée comme une stack indépendante.

### Applications actuellement connues

- SurgicalHub
- MedAtWork
- MedClick
- MedVue

L’ajout d’une application ne doit jamais nécessiter de modifier les fichiers applicatifs, volumes ou bases de données des autres applications.

---

## 3. Reverse proxy

Le serveur utilise **Traefik** comme reverse proxy unique.

Traefik gère :

- routage HTTP/HTTPS ;
- certificats TLS Let's Encrypt ;
- exposition publique des frontends ;
- exposition publique des APIs ;
- middlewares partagés.

Middleware partagé actuellement connu :

```text
secure-headers@file
compress@file
```

Configuration dynamique Traefik :

```text
/opt/stack/traefik/dynamic/middlewares.yml
```

Une nouvelle application doit se brancher sur le réseau Docker partagé `proxy`.

---

## 4. Réseaux Docker

### Réseau partagé

```text
proxy
```

Utilisé uniquement pour permettre à Traefik d’atteindre les services exposés publiquement.

### Règle générale

Ne pas réutiliser inutilement un réseau applicatif partagé pour les bases de données ou services internes.

Chaque nouvelle application doit préférer un réseau interne dédié.

Exemple MedVue :

```text
Traefik
   │
   └── réseau proxy
          │
          ├── medvue-frontend
          └── medvue-backend
                    │
                    └── réseau medvue-internal
                              │
                              └── medvue-database
```

Pour MedVue :

- `medvue-frontend` → réseau `proxy` uniquement ;
- `medvue-backend` → réseaux `proxy` + `medvue-internal` ;
- `medvue-database` → réseau `medvue-internal` uniquement ;
- aucun port PostgreSQL publié sur l’hôte.

La base MedVue ne doit pas être accessible depuis SurgicalHub, MedAtWork ou MedClick.

---

## 5. Convention par application

Chaque application doit disposer de son propre répertoire :

```text
/opt/stack/apps/<app>/
```

et idéalement de :

```text
docker-compose.prod.yml
.env
```

ou de la convention équivalente déjà définie pour cette application.

Chaque application doit conserver ses propres :

- conteneurs ;
- volumes ;
- variables d’environnement ;
- base de données ;
- réseau interne si nécessaire ;
- fichiers de configuration applicatifs ;
- procédure de backup.

Le fichier `.env` réel :

- ne doit jamais être commité ;
- doit être protégé avec des permissions restrictives ;
- doit rester sur le serveur.

Exemple :

```bash
chmod 600 .env
```

---

## 6. Convention Traefik

Pour une application exposée publiquement :

Frontend :

```text
Host(`example.be`) || Host(`www.example.be`)
```

API :

```text
Host(`api.example.be`)
```

Ne jamais mettre de schéma dans `Host()`.

Incorrect :

```text
Host(`https://www.example.be`)
```

Correct :

```text
Host(`www.example.be`)
```

Les services exposés doivent utiliser :

```text
entrypoints=websecure
tls.certresolver=letsencrypt
```

et les middlewares partagés applicables.

Toujours vérifier le port réellement écouté par le conteneur avant de définir :

```text
traefik.http.services.<service>.loadbalancer.server.port
```

Ne jamais supposer automatiquement qu’il s’agit du port 80.

---

## 7. DNS

Pour une application suivant le modèle frontend + API :

```text
example.be
www.example.be
api.example.be
```

Les enregistrements A doivent pointer vers :

```text
187.124.55.15
```

Toute modification DNS doit être strictement limitée à l’enregistrement demandé.

Ne jamais modifier sans demande explicite :

- MX ;
- SPF ;
- DKIM ;
- DMARC ;
- autres sous-domaines ;
- enregistrements d’autres applications.

---

## 8. MedVue

### Domaine

```text
medvue.be
www.medvue.be
api.medvue.be
```

### Répertoire serveur

```text
/opt/stack/apps/medvue
```

### Architecture

- frontend statique construit en production ;
- backend FrankenPHP ;
- PostgreSQL dédié ;
- réseau interne Docker dédié : `medvue-internal` ;
- réseau Traefik partagé : `proxy`.

### Base de données

MedVue utilise PostgreSQL.

Ne jamais utiliser le MySQL partagé du serveur pour MedVue.

Le conteneur PostgreSQL MedVue doit rester isolé et ne publier aucun port sur l’hôte.

---

## 9. Applications existantes à protéger

Avant toute opération sur une nouvelle application, considérer comme intouchables sauf demande explicite :

```text
surgicalhub
medatwork
medclick
traefik
mysql
redis
phpmyadmin
portainer
```

Ne jamais :

- arrêter Docker globalement ;
- lancer `docker system prune` sans analyse précise ;
- supprimer un réseau partagé ;
- supprimer un volume non identifié ;
- arrêter tous les conteneurs ;
- modifier le compose d’une autre application ;
- modifier une base d’une autre application ;
- réutiliser un volume d’une autre application ;
- écraser `/opt/stack` ;
- exécuter un `docker compose down` depuis le mauvais répertoire.

---

## 10. Avant tout déploiement

Faire un snapshot de référence :

```bash
docker ps -a
docker network ls
docker volume ls
```

Inspecter également les réseaux partagés concernés :

```bash
docker network inspect proxy
```

Pour une opération risquant d’affecter l’infrastructure commune, vérifier aussi l’état de Traefik.

Après le déploiement, refaire les mêmes vérifications.

Toute différence inattendue sur les applications existantes est un signal d’arrêt.

---

## 11. Déploiement d’une nouvelle application

Principe :

1. Créer un nouveau répertoire sibling dans :

```text
/opt/stack/apps/<nouvelle-app>
```

2. Ne modifier aucune autre application.
3. Créer son propre compose de production.
4. Créer son réseau interne dédié si nécessaire.
5. Utiliser uniquement le réseau `proxy` pour la communication avec Traefik.
6. Déployer les secrets uniquement dans le `.env` serveur.
7. Builder uniquement les images de la nouvelle application.
8. Démarrer uniquement sa stack.
9. Vérifier les logs.
10. Tester via le vrai domaine public.
11. Vérifier qu’aucun autre conteneur, volume ou réseau applicatif n’a changé.

---

## 12. Commandes à éviter

Ne jamais exécuter sans justification explicite :

```bash
docker system prune
docker volume prune
docker network prune
docker stop $(docker ps -q)
docker rm -f $(docker ps -aq)
```

Éviter également tout :

```bash
docker compose down
```

tant que le répertoire courant et le projet Compose ciblé n’ont pas été explicitement vérifiés.

---

## 13. SSH

La connexion actuellement fonctionnelle utilise :

```bash
ssh surgicalhub-prod
```

Le nom `surgicalhub-prod` est historique : il pointe aujourd’hui vers le VPS partagé et pas uniquement vers SurgicalHub.

Ne pas créer une nouvelle clé SSH pour chaque application tant que l’accès vise :

- le même serveur ;
- le même utilisateur `deploy` ;
- la même frontière de confiance.

En revanche, ne jamais partager ni commiter la clé privée SSH.

---

## 14. Secrets

Ne jamais écrire dans ce fichier :

- clé privée SSH ;
- mot de passe PostgreSQL/MySQL ;
- `APP_SECRET` ;
- passphrase JWT ;
- clé privée JWT ;
- token GitHub ;
- token Hostinger ;
- credentials rclone ;
- mots de passe admin.

Ce document doit pouvoir être commité sans exposer de secret.

---

## 15. Principe d’isolation

La règle la plus importante du serveur est :

> Une application peut partager Traefik et l’IP publique du VPS, mais elle ne doit pas dépendre des ressources privées d’une autre application sauf si cette dépendance est explicitement conçue et documentée.

Une nouvelle app doit donc être ajoutée comme une nouvelle stack indépendante, pas comme une extension d’une stack existante.

---

## 16. En cas de doute

Avant toute opération susceptible de toucher l’infrastructure commune :

1. inspecter ;
2. comparer avec l’état connu ;
3. ne rien modifier tant que la cible exacte n’est pas identifiée ;
4. privilégier une opération locale au répertoire de l’application ;
5. ne jamais corriger silencieusement une incohérence constatée.

---

## 17. Documents complémentaires

Pour chaque application, conserver une documentation propre à son déploiement, par exemple :

```text
docs/deployment.md
```

Ce fichier `server-production.md` décrit **le serveur partagé**.

Le fichier `docs/deployment.md` de chaque projet décrit **comment cette application particulière est déployée sur ce serveur**.

Les deux documents sont complémentaires.
