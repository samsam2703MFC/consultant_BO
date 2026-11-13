# Fidélité : la carte à points fusionnée avec le bingo, trois maquettes

Demande de Sam du 13/11/2026 :

- « une carte à points, avec en plus un bingo pour la récurrence » ;
- « ça ne marche qu'avec l'API : pas depuis une table, uniquement depuis l'API » ;
- « on ne garderait que la carte à points, fusionnée avec le bingo ».

Le point de départ est la maquette du bingo de saison de Sam (`edition-maquette.html`, 12–13/11/2026) : 13 semaines,
un bon toutes les 3 cases (4,90 → 9,90 → 19,90 → 29,90 €), la carte pleine qui donne le super bon (49,90 €, montant
provisoire) et le tirage d'un an de pain. Les dessins viennent de cette maquette (`ill/`).

| | Maquette | Capture |
|---|---|---|
| 1 | **Le programme** (cockpit, onglet Programme) : la seule carte, avec son barème de points (tranches, ×2, coefficients par magasin, paliers d'échange, validités) et son bingo (case, bons, carte pleine, 13 semaines). On y simule un ticket, puis on publie dans l'ERP. | `m1-programme.jpg` |
| 2 | **Le client** (webshop, PWA) : ma carte (les points en tête, la grille du bingo dessous), au comptoir, mes points, mes bons ; puis un passage en caisse d'un bout à l'autre, avec la route de l'API à chaque étape. | `m2-client.jpg` |
| 3 | **Les clients** (cockpit, onglet Clients) : la récurrence, magasin par magasin. Qui coche chaque semaine (comparé à l'hiver passé), où en sont les membres, la liste des membres, qui relancer. | `m3-clients.jpg` |

## La fusion

Il n'y a plus qu'**une carte** : la carte à points de l'ERP. Les cartes à tampons (Pain, Viennoiserie, Café, Tarte)
s'arrêtent : `PATCH …/cards/{id}` avec `ends_at`. L'API n'a pas d'écriture de solde, donc les tampons ne se
convertissent pas en points ; les récompenses déjà prêtes restent valables jusqu'à leur échéance.

**La case de la semaine se coche avec les points de la carte** : 20 points gagnés du lundi au dimanche, ×2 et
coefficients compris. Le client ne retient qu'une seule unité. Les ×2 font aussi avancer le bingo : aux heures
creuses, 10 € suffisent. Échanger des points ne décoche rien, puisque la case compte les points gagnés dans la
semaine (`points_earned`) et pas le solde.

## Tout vient de l'API (openapi de test, lu le 13/11/2026)

Le cockpit n'a aucune table pour le programme. À chaque ouverture, il relit tout dans l'ERP.

| Besoin | Route |
|---|---|
| La carte et son barème | `GET /admin/loyalty/cards`, `GET /admin/loyalty/cards/{id}` : `points_config {points_per_slice, slice_cents, bonuses[{name, days, from, to, coefficient}], shop_coefficients, tiers[{points, voucher_cents}], voucher_validity_days, points_validity_days}`, `revision`, `terms_locked` |
| Les magasins, produits et catégories | `GET /admin/loyalty/options` |
| Ce qui rapporte, magasin par magasin | `GET /admin/loyalty/preview?shop_id=` |
| Essayer un ticket | `POST /admin/loyalty/simulate {config, shop_id, amount_cents, local_datetime}` → `points, slices, shop_coefficient, bonus_coefficient` (rien n'est écrit) |
| Publier | `POST /admin/loyalty/cards` (nouvelle version), `PATCH /admin/loyalty/cards/{id} {revision, …}` (409 si quelqu'un a modifié entre-temps) |
| Les points de la semaine (la case) | `GET /shops/{id}/loyalty/customers?card_id=&from=&to=` → `programs[].shop_activity.points_earned`, à additionner sur les magasins |
| Solde, paliers, dernier passage | même route : `programs[].global_progress.points {balance, earned_total, spent_total, tiers[].reached, remaining}`, `shop_activity.last_transaction_at` |
| Les chiffres du magasin | `GET /shops/{id}/loyalty/statistics?card_id=&from=&to=` → `participants`, `programs`, `recognitions`, `shop_activity` |
| Les bons du bingo | `GET /admin/voucher-campaigns` (une campagne par valeur, `UNIQUE`, `requires_customer`), `POST /admin/voucher-campaigns/{id}/codes {code, id_customer, valid_to, usage_limit: 1}`, `GET …/codes?id_customer=` |
| Le client | `GET /webshop/loyalty/identity` (code 98…), `/cards`, `/points`, `/history`, `/program`, `/rewards`, `GET /webshop/vouchers`, `POST /webshop/loyalty/points/redeem {card_id, shop_id, points, request_key}`, `POST /webshop/vouchers/activation`, `POST /webshop/vouchers/prepare` |
| La caisse | `POST /shops/{shopId}/clients/loyalty-card/resolve {code}`, `GET /shops/{shop}/clients/{client}/voucher-activation` |

Ce que dit la documentation de l'API et que les écrans respectent :

- l'échange de points contre un bon n'est **jamais automatique** : il se fait au palier exact, depuis l'app ;
- quand deux ×2 tombent en même temps, **le plus fort l'emporte** : ils ne se cumulent pas ;
- un ×2 vaut pour **toute la carte** : il ne se limite pas à une famille et ne se lie pas à un magasin. Le « ×2 le
  lundi à Sombreffe » devient un coefficient de magasin (×1,5) ;
- une carte qui a déjà servi a un **barème figé** (`terms_locked`) : le changer crée une version 2, avec le même code ;
- les soldes sont ceux du **réseau** et se répètent dans chaque magasin, donc on ne les additionne jamais. L'activité
  (points gagnés, ventes) est celle du magasin choisi sur la période, et celle-là s'additionne ;
- une carte « reconnue » en caisse n'est ni un scan prouvé ni un achat ;
- l'API ne donne pas de série par jour : il faut un appel par jour (`from = to`).

## Ce qui manque à l'API

- **Le bingo lui-même.** L'ERP ne coche pas de case. Le cockpit lit les points de chaque semaine et n'écrit dans
  l'ERP que les bons (codes de campagne au nom du client). La grille se recalcule à la demande. À demander à l'ERP
  pour la suite : un palier hebdomadaire dans `points_config`, pour que la grille vive dans la carte.
- **À vérifier sur l'API de test**, avec un jeton : un code de campagne au nom du client apparaît-il dans
  `GET /webshop/vouchers` ?
- **Ce qui disparaît de l'écran faute d'API** : bonus panier, anniversaire ×3, bienvenue, 2e visite, parrainage,
  retour après 45 jours, plafond, jours exclus, budget. Le bingo reprend le rôle des déclencheurs de visite.
- `src/fidelite.php` : réécrit le 13/11/2026 comme un client de ces routes (voir plus bas).

## Réel et illustré

**Réel** : les routes, les champs et les règles de l'API (openapi de `test.tfbuddy.com`), les quatre magasins, la
saison et les bons de la maquette de Sam.

**Illustré** : tous les chiffres des clients et des magasins, ainsi que Julie. L'API de test répond 401 sans jeton,
donc rien n'a été lu.

## Régénérer

```
node docs/maquettes/fidelite-bingo/generer.cjs              # depuis pilotage-reseau/ ; les trois
node docs/maquettes/fidelite-bingo/generer.cjs m3-clients   # une seule
```

`commun.cjs` contient le style (design system commun), le rail, la saison et la grille. Chaque `m*.cjs` écrit sa
page, puis `generer.cjs` la capture (1440 px, ×2).

## La version finale, codée (13/11/2026)

`src/fidelite.php` est réécrit en client de l'API de l'ERP : plus aucune table (contrat : `docs/contrat-api.md`,
« Fidélité — la carte à points et son bingo »). L'écran (`public/assets/js/fidelite-vals.js`, `fidelite-tpl.js`,
`assets/css/fidelite.css`) reprend la maquette 1 pour le Programme et la maquette 3 pour les Clients.

Captures du code, sur le faux ERP (`tools/fidelite_faux_erp.php`, clients illustrés), le jeudi 14/01/2027 à 16:00 :

- `code-programme.png` : la carte avec un brouillon (Sombreffe ×1,5, un palier 300 → 11 €), un ticket simulé et la
  publication en version 2 ;
- `code-clients.png` : le réseau, avec les bons dus ;
- `code-clients-corbais.png` : un magasin.

```bash
php -S 127.0.0.1:8098 -t public docs/maquettes/fidelite-bingo/capture_api.php
```

```bash
node docs/maquettes/fidelite-bingo/capturer.cjs
```
