# Commandes par canal, promotions et bundles — trois maquettes

> **Codé** (03/10/2026) : les trois. Serveur `src/canaux_offres.php`
> (`GET /exploitation/canaux`, `GET /exploitation/offres` — voir
> `docs/contrat-api.md`), dashboard `public/dashboard/dashboard.js`
> (`canauxCarte`, `offresCarte`, tuiles `murCanaux`/`murOffres`), cockpit
> `valsOffres`/`tplOffres` (`#/offres-canaux`). Le choix laissé ouvert plus
> bas est tranché : une commande webshop encaissée en caisse (`id_transaction`)
> est dans les tickets, le comptoir = tickets − ces commandes ; payée en
> ligne, elle s'ajoute. Tant que le réseau n'a ni commande webshop ni offre,
> les cartes le disent en une ligne.

Deux demandes : **un suivi des promotions et des bundles**, et **le split des
commandes** entre le comptoir, le click & collect et la livraison (ces deux
derniers via le webshop). Trois formes :

| | Maquette | Fichiers |
|---|---|---|
| A | Dashboard magasin, vue Jour : la carte « Commandes et canaux — la journée » remplace « Comptoir et clients pro » — barre à trois segments, trois tuiles (comptoir dont clients pro, click & collect, livraison), les 14 derniers jours empilés par canal, les commandes webshop du jour et ce qu'il reste à préparer | `a.jpg`, `a-page.jpg`, `a.html` |
| B | Dashboard magasin, vue Jour : la carte « Promotions et bundles — ce qu'elles rapportent » — quatre chiffres, une ligne par offre (type, canaux, depuis, aujourd'hui, 7 jours, marge et coefficient, tendance, effet face à la référence, verdict) | `b.jpg`, `b-page.jpg`, `b.html` |
| C | Cockpit, Marque & marketing › « Offres et canaux » : la vue réseau — cinq chiffres, les commandes par canal magasin par magasin (part webshop), les offres en lignes et les magasins en colonnes avec le verdict de chacun | `c.jpg`, `c.html` |
| | La planche : les trois, ce que ça apporte, les limites | `planche.jpg` |

## Réel et illustré

**Réel** : la journée de Halle (shop 4) du vendredi 02/10/2026 — 1 881 € de
ventes, 154 tickets, 254 € de clients pro — et ses 14 derniers jours
(`reel-halle.json`, lu sur `/exploitation/pro`) ; les coques du dashboard
(`contexte-dashboard.html`) et du cockpit (`contexte-cockpit.html`) en ligne.

**Illustré, et dit sur chaque carte** : la part webshop (click & collect,
livraison) et les offres. Au 03/10/2026 le réseau n'a adopté aucune promotion
« jours creux » (`GET /promo` : vide), n'a vendu aucun produit de la catégorie
« Bundle & Promotion » en septembre (`/ventes/stats?vue=mois`, Gosselies et
Halle), et la table `ws_orders` ne porte qu'une commande (Gosselies, juillet,
en livraison). Les chiffres illustrés sont posés sur la journée réelle :
comptoir = 1 881 € − webshop.

## Ce qu'il faudrait pour coder

- **Les canaux** : les commandes du panel (`/shops/{id}/client-orders`)
  portent `is_webshop`, `fulfilment_mode`, `total_value`, `pick_up_datetime`
  et `order_status` — la source du split est déjà là (voir
  `src/commandes.php`, qui ne garde pas encore ces deux champs). La table
  `ws_orders` (`mode`, `delivery_date`, `delivered_at`) et
  `ws_office_delivery_sites` complètent pour la livraison. À trancher une
  fois : une commande webshop payée en ligne est-elle dans les tickets caisse
  (alors le comptoir = tickets − webshop) ou en plus.
- **Les bundles** : les produits de la catégorie « Bundle & Promotion » du
  panel, lus dans les tickets comme les autres (`/ventes/stats`, `categories[]
  .produits[]` : pièces, CA, coût matière, marge). Un bundle rangé ailleurs
  échappe : prévoir une liste de références « offre » dans les réglages.
- **Les promotions** : `GET /promo` porte déjà l'effet face à la référence
  des 4 semaines d'avant (`effet.deltaCaPct`, `verdict`) ; la carte B et la
  page C le reprennent tel quel.

Régénérer : serveur statique sur 8099 depuis la racine du dépôt, puis
`node docs/maquettes/canaux-offres/generer.js`. `co.css` n'ajoute que les
couleurs des canaux, les piles, les pastilles d'offre et de verdict.
