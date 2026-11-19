# Publication sur les réseaux — mise en service

Le portail Marketing (`/marketing/`, étape **4 · Publier**) publie un post validé sur
**Facebook**, **Instagram** et **LinkedIn**. Le code est livré ; il reste à lui donner
des accès. Tant que ce n'est pas fait — et tant que « Publication réelle » n'est pas
cochée (Paramètres → Publication) — **rien ne part** : le portail prépare, la marque
publie à la main puis marque le post publié avec son id Facebook.

Ce document dit ce qu'il faut créer chez Meta et chez LinkedIn, où mettre les jetons,
et comment vérifier. Rien n'a été essayé contre les vraies API depuis le dépôt : les
tests simulent Meta et LinkedIn (voir « Ce qui est vérifié »).

## Comment ça marche

1. Le post choisit ses réseaux au moment de la rédaction (`mar_social_post.reseaux`).
2. Validé par la marque, il se **programme** tout seul à son heure si « Programmer à la
   validation » est coché ; sinon « Programmer » ou « Publier maintenant » sur sa carte.
   Une ligne `mar_social_publication` par réseau.
3. **L'horloge** (`bin/social_cron.sh`, toutes les 5 minutes, posée par `bin/deploy.sh`
   dans `/etc/cron.d/cockpit-rapports`) appelle `GET /marketing/publication/cron?jeton=…`
   et publie ce qui est dû. Une erreur est retentée 15 minutes plus tard, 3 fois au plus.
4. Quand tous ses réseaux (hors annulés) sont publiés, le post passe « publié » avec l'id
   Facebook rendu : Brand Guard le reconnaît en relisant la page et ne le compte pas
   comme sauvage.

Avant tout appel, chaque réseau est **contrôlé** (`spPreparer`) : Instagram exige un
visuel téléversé (10 au plus, 2 200 caractères, 30 hashtags), LinkedIn ne publie que les
posts de la marque (3 000 caractères), Facebook veut un texte ou un visuel. Un réseau qui
ne passe pas est **annulé avec sa raison** et les autres partent ; si aucun ne passe, ou
si l'on a demandé ce réseau nommément, rien ne part (422).

Où part un post :

| Post | Facebook | Instagram | LinkedIn |
|---|---|---|---|
| de la marque (sans magasin) | la page de la marque (Paramètres → Publication) | le compte professionnel relié à cette page | la page entreprise (id d'organisation) |
| d'un magasin | la page du magasin (Paramètres → Pages Facebook, « résolue ») | le compte professionnel relié à la page du magasin | — (refusé : page de la marque seulement) |

## Meta : Facebook et Instagram

Un seul accès sert les deux : un **System User** du Business Manager, le même que Brand
Guard utilise pour relire les pages.

1. **Application Meta** (developers.facebook.com) de type Business, rattachée au Business
   Manager de la marque. Produits : *Facebook Login for Business* (pour générer le jeton)
   et *Instagram* (API Graph).
2. **System User** (Business Manager → Paramètres → Utilisateurs → Utilisateurs système),
   rôle *Admin* ou *Employé* ; lui **attribuer chaque page** (la page de la marque et celle
   de chaque magasin) avec le contrôle total, et les comptes Instagram professionnels liés.
3. **Jeton** du System User pour l'application, sans expiration, avec les droits :
   `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `instagram_basic`,
   `instagram_content_publish` (et `business_management` si le Business Manager l'exige).
   Une publication réelle demande en général que l'application soit **en mode Live** et
   que ces droits aient passé l'**App Review** de Meta.
4. Chaque page Instagram doit être un **compte professionnel relié à la page Facebook**
   (Paramètres de la page → Comptes liés).

Où le mettre — **jamais en base, jamais dans le navigateur** :

```
# variable d'environnement du PHP
META_SYSTEM_TOKEN=EAAG…
```

ou, dans `config/config.php` (hors Git, conservé par le déploiement) :

```php
'meta' => ['systemToken' => 'EAAG…', 'graphVersion' => 'v21.0'],
```

Le jeton de chaque page est demandé à Meta au moment de publier (`me/accounts`) ; rien
d'autre n'est stocké.

Puis, dans le portail : **Paramètres → Publication** — l'identifiant de la page de la
marque (ou la choisir parmi les pages connues) ; **Paramètres → Pages Facebook** —
l'adresse de la page de chaque magasin, puis « Résoudre ».

## LinkedIn : la page entreprise

1. **Application LinkedIn** (linkedin.com/developers), vérifiée par la page entreprise de
   la marque, avec le produit **Community Management API** (accès accordé par LinkedIn
   sur demande).
2. **Jeton** OAuth d'un **administrateur de la page**, avec le droit
   `w_organization_social` (et `r_organization_admin` pour que « Tester » puisse lire la
   page). Un jeton LinkedIn **expire après 60 jours** : notez la date.
3. L'**id d'organisation** : le nombre dans l'adresse d'administration de la page
   (`linkedin.com/company/12345678/admin/`).

```
LINKEDIN_ACCESS_TOKEN=AQX…
```

ou `config/config.php` :

```php
'linkedin' => ['accessToken' => 'AQX…', 'expireLe' => '2026-12-12', 'organisation' => '12345678'],
```

`expireLe` fait afficher « expire dans N j » dans Paramètres → Publication (en rouge sous
10 jours). L'id d'organisation peut aussi se saisir à l'écran. La **version d'API**
(`AAAAMM`) est par défaut celle d'il y a deux mois — LinkedIn retire les anciennes ;
on peut la fixer à l'écran.

## Les visuels

« Téléverser un visuel » (étape 2 · Créer) envoie l'image à
`POST /marketing/publication/media` : JPEG, PNG ou WebP, 8 Mo au plus, convertie en JPEG
et rangée dans `public/uploads/social/`. Le post garde son **adresse absolue** :
Instagram et Facebook vont la chercher eux-mêmes, elle doit donc être **lisible depuis
Internet**. Si le cockpit est servi derrière une adresse privée, réglez `cockpitBase`
(l'adresse publique du cockpit, déjà utilisée par les rapports). L'écran prévient si le
format sort de ce qu'Instagram accepte (4:5 à 1,91:1) ou si l'image fait moins de
1 080 px de large. LinkedIn, lui, reçoit les octets (dépôt `images?action=initializeUpload`).

## Mettre en route

1. Poser les jetons (ci-dessus), redémarrer PHP-FPM si ce sont des variables d'environnement.
2. Paramètres → Publication : renseigner la page de la marque et l'organisation LinkedIn,
   puis **Tester** chaque réseau (un appel réel en lecture, rien n'est publié).
3. Cocher **Publication réelle** (refusé tant qu'aucun accès n'est configuré), et
   **Programmer à la validation** si l'on veut que la validation suffise.
4. Vérifier l'horloge : « horloge passée le … » en tête des réglages. Sinon, contrôler
   `/etc/cron.d/cockpit-rapports` et lancer `bin/social_cron.sh` à la main.

Pour tout arrêter : décocher « Publication réelle ». L'horloge ne fait plus rien ; ce qui
était programmé attend, ou s'annule depuis la carte du post.

## Routes

| Méthode | Chemin | Rôle |
|---|---|---|
| GET | `/marketing/publication/etat` | réglages, accès (jamais un secret), pages, compteurs, dernier passage de l'horloge |
| PUT | `/marketing/publication/reglages` | `{reelle?, auto?, pageMarque?, linkedinOrganisation?, linkedinVersion?}` — 422 si `reelle` sans aucun accès |
| POST | `/marketing/publication/tester` | `{reseau}` — un appel réel en lecture : pages attribuées au System User, compte Instagram relié, page LinkedIn lisible |
| POST | `/marketing/publication/media` | `{data: dataURL, nom?}` — le visuel, rendu avec son adresse absolue (`url`), sa taille et des avertissements |
| POST | `/marketing/social/posts/{id}/publier` | `{quand: maintenant\|programme, reseaux?}` — 409 si publication éteinte ou post non validé |
| DELETE | `/marketing/social/posts/{id}/publier` | annule ce qui n'est pas encore parti |
| GET | `/marketing/publication/cron?jeton=` | l'horloge (`socialJeton`, tiré au hasard à l'installation) |

## Ce qui est vérifié

- `php bin/tests.php`, section « Publication » : le contrôle préalable de chaque réseau,
  les appels Facebook (photos déposées non publiées puis un post qui les réunit),
  Instagram (conteneur, attente, publication ; carrousel), LinkedIn (dépôt d'images,
  `multiImage`, en-têtes de version, 401 = jeton expiré) — sur des transports simulés.
- Un essai d'intégration sur MariaDB, réseaux simulés : verrous (publication éteinte,
  post non validé), publication immédiate marque + LinkedIn, programmation puis horloge,
  ordre des appels Instagram, échec puis reprise, pas de double publication, un réseau
  annulé qui ne retient pas les autres.
- **Pas d'essai contre les vraies API** de Meta ni de LinkedIn : le premier « Tester »
  avec de vrais jetons est la première vérification réelle.
