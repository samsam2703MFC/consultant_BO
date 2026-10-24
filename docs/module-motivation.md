# Module Motivation : les primes des vendeuses

Le module comprend les primes de l'app worker, les ventes croisées, la meilleure vendeuse, le record, la prime B2B et
les grands prix de l'année. Il a trois portes :

- la page admin (`#/primes`) ;
- la page franchisé (`#/primes?shop=3`) ;
- l'application employé (`apps/employee`).

Le module doit pouvoir être remis tel quel à l'intégrateur de l'ERP. Ses données vivent donc dans ses propres
tables, toutes sous le préfixe `mot_` (décision du 23/10/2026, Sam).

## Les tables

`src/motivation.php` crée les tables à la première lecture.

| Table | Contenu | Se vide ? |
|---|---|---|
| `mot_reglage` | Ce que la marque et les franchisés règlent, et ce qui est gravé : une clé, une valeur JSON. | Jamais |
| `mot_cache` | Ce qui se recalcule (fiches du mois, moyennes, catalogue, ventes du jour relues) : une clé, une valeur JSON. | Oui, sans rien perdre |
| `mot_grand_prix` | Les grands prix de l'année, du réseau ou d'un magasin, une ligne chacun. | Jamais |

### `mot_reglage`

| Clé | Ce qu'elle porte |
|---|---|
| `ventePrimesApp` | La liste des primes de l'app : marque, sponsors, franchisés, B2B, icônes, SMS. |
| `venteCroisees` | Les ventes croisées : cible du réseau, cible par magasin, minimum de tickets. |
| `ventePrimes` | La meilleure vendeuse : montant réseau et montant magasin. |
| `ventePrimesHist`, `ventePrimesCrossHist` | Les primes enregistrées, mois par mois. |
| `venteCrossTargets`, `venteCrossPaliers`, `venteCrossMontant`, `venteCrossMontantShop` | Les targets de ventes croisées, d'avant l'app. |
| `venteRecordEurDixieme`, `venteRecordMaxDixiemes` | « Bats ton record ». |
| `ventePrimeMagasin`, `venteConcoursTQ`, `ventePrimeGoogle` | Les réglages d'avant, relus une fois pour en tirer la liste des primes. |
| `primesSms<mois>` | Les SMS de réussite déjà envoyés pour un mois, pour n'en envoyer aucun deux fois. |
| `_repris_de_ceo_app_setting` | La date et le nombre de clés reprises. |

### `mot_cache`

Les clés commencent par :

- `primesMoi`, `primesTous`, `primesClos`, `primesColl`, `primesS12`, `primesJeton`, `primesPromos3`,
  `primesCatalogue` ;
- `prMoy`, `b2bM`, `pvJ`, `vmMois` ;
- `grandsPrix…`.

### `mot_grand_prix`

| Colonne | Contenu |
|---|---|
| `portee`, `shop` | `reseau` (la marque), ou `magasin` avec son identifiant. |
| `defi` | `vendeuse`, `b2b`, `ticket`, `croisees`, `progression` ou `magasin`. |
| `titre`, `lot`, `valeur`, `photo` | Le lot. La photo vit dans `public/uploads/primes`. |
| `payeur`, `sponsor`, `sponsor_site` | Qui l'offre : `marque` ou `sponsor` pour le réseau ; `magasin` ou `sponsor` pour un magasin. |
| `seuil` | Le minimum d'heures ou de tickets sur la saison. |
| `du`, `au` | La saison, en mois entiers. |
| `actif` | Allumé ou éteint. |
| `resultat` | Le classement gravé, trois jours après la fin de la saison. |

## Reprise depuis `ceo_app_setting`

Avant le 23/10/2026, tout vivait dans `ceo_app_setting`. À la première lecture, `motReprendre()` fait trois choses :

1. elle copie les clés du module dans `mot_reglage` ou `mot_cache` (ce qui est déjà dans `mot_` gagne) ;
2. elle efface de `ceo_app_setting` les clés copiées ;
3. elle pose la marque `_repris_de_ceo_app_setting`.

`setting()` renvoie encore une clé du module vers ses tables, pour du code qui l'appellerait par habitude.

Le code du module lit et écrit avec `motReglage()`, `motReglageEcrire()`, `motCache()`, `motCacheEcrire()` et
`motCacheVider()`. `bin/tests_primes.php` échoue si un fichier écrit encore une clé du module dans
`ceo_app_setting`.

Restent hors du module, et donc dans `ceo_app_setting` :

- la moisson des tickets (`pvL…`, `pvE…`, `pvEmployes…`), qui appartient aux ventes ;
- la simulation des ventes croisées (`venteSim…`).

## Les grands prix de l'année

Ils sont dans `src/grands_prix.php`, à l'onglet « Grands prix de l'année » de l'écran des primes. L'écran a deux
parties :

- la vitrine (maquette B) : une carte par grand prix, avec le lot, qui l'offre, la valeur, le défi et le top 3 ;
- la création (maquette D) : le défi, le prix, qui l'offre, qui peut gagner et la saison.

Sur la page admin, la marque pose ceux du réseau ; ceux des franchisés s'y lisent et s'y modifient. Sur la page
franchisé, ceux de la marque se lisent, avec la mieux classée du magasin, et le franchisé pose les siens.

### Les défis

| Défi | Ce qui classe | Source |
|---|---|---|
| Meilleure vendeuse | CA par heure prestée sur la saison, un minimum d'heures | `venteMois` : le CA à son nom, les heures du planning |
| Meilleure progression | Le même CA par heure, face aux mêmes mois de l'année d'avant | idem |
| Reine des ventes croisées | La part des tickets à deux articles ou plus, un minimum de tickets | La moisson des tickets (`pvCroiseesMois`) |
| Plus gros apport B2B | Le CA HT de ses tickets pro | La base partagée, comme la prime B2B (`b2bTickets`) |
| Plus gros ticket | Son plus gros ticket, hors clients pro | La base partagée (`transaction`) |
| Magasin de l'année (réseau seulement) | Le CA du magasin face à son objectif | `ceo_shop_month_perf` et le CA jour par jour |

Le minimum monte avec la saison. À mi-saison, la moitié des heures suffit pour être classée.

### L'API

`GET /ventes/grands-prix[?shop=3]` rend les grands prix, chacun avec ses dix premiers, sa saison, combien sont
classés et combien sont sous le minimum. Avec `shop`, il rend aussi `ici`, la mieux classée du magasin.

`POST /ventes/grands-prix` crée ou modifie un grand prix. `{id, actif}` seul ne change que l'interrupteur.

`POST /ventes/grands-prix/retirer {id}` retire un grand prix.

`GET /ventes/moi`, sur le mois en cours, porte aussi `grandsPrix` : les grands prix de la vendeuse (ceux de la marque
et ceux de son magasin). Ce sont ceux en cours, à venir, ou finis depuis moins de 45 jours. Chacun porte :

- sa place `moi {rang, valeur}` (pour le magasin de l'année, celle de son magasin) ;
- ou ce qu'il lui manque, `manque {n, sorte}` (des heures ou des tickets) ;
- le `top` 3 ;
- la saison et l'`unite` de la valeur.

L'app les montre dans « Mes primes », en cartes qui défilent (`apps/employee`, `primes.twig`). Le classement se garde
quinze minutes.

Les six grands prix de la maquette retenue, pour la saison 2026, ont été posés une fois (le 23/10/2026, `gpMaquette`) :

- Refaire ta terrasse ;
- Week-end à Rome pour deux ;
- Trottinette électrique ;
- Machine espresso + 1 an de café ;
- Journée spa pour deux ;
- Soirée d'équipe + 2 000 €.

Ils naissent éteints : les sponsors de la maquette sont fictifs, et un grand prix allumé se voit tout de suite dans
l'app. Il faut les vérifier, puis les allumer un par un. La marque `_grands_prix_maquette_2026` dans `mot_reglage`
empêche de les recréer.

Tests : `php bin/tests_grands_prix.php`.
