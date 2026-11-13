# Fidélité bingo + points : ce que l'API de l'ERP doit ajouter

13/11/2026. Ce document est destiné aux développeurs de l'ERP (TF Buddy). Le programme suit le handoff de Sam
(`handoff/README.md`). Le cockpit le règle et le lit **uniquement par l'API** : il n'a ni table ni moyen d'écrire des
points.

Comparé à l'openapi de test du 13/11/2026, il manque quatre choses. Sans elles, l'écran les affiche comme « pas dans
l'API » et ne les simule pas.

## Ce qui marche déjà par l'API

- **La carte à points.** `points_config` de `/admin/loyalty/cards` : tranches, créneaux ×2, coefficients par magasin,
  paliers, validités. On publie en `PATCH` avec la `revision`, ou par une version suivante si la carte est figée.
- **La case de la semaine, lue après coup.** Le cockpit lit `shop_activity.gross_sales_before_corrections` de
  `GET /shops/{id}/loyalty/customers?card_id&from&to` sur la semaine et additionne les magasins.
- **Les bons du bingo.** Un code de la campagne de la saison au nom du client, via
  `POST /admin/voucher-campaigns/{id}/codes {code, id_customer, valid_from, valid_to, usage_limit: 1}`.

## 1. La case de la semaine, dans la carte

Aujourd'hui, la case n'existe que dans le cockpit. L'app et la caisse ne la voient pas, et elle se calcule après coup.

Demande : un **seuil d'achats hebdomadaire** sur la carte. Par exemple `bingo: {weekly_threshold_cents: 1000,
week_start: monday, timezone}`, et pour chaque client, l'état de la semaine en cours : dépensé, case cochée, cochée le.
L'état serait lisible par `GET /webshop/loyalty/cards` côté app et par `/shops/{id}/loyalty/customers` côté cockpit.
Les tickets comptés sont ceux de la boutique, du click & collect et de la livraison, tous magasins de la carte.

## 2. Un coefficient par client et par produit, appliqué au calcul des points

L'API ne connaît qu'un ×2 par créneau horaire (`bonuses`), le même pour tous, sur tout le ticket, et le plus fort
l'emporte. Le handoff demande, **ligne par ligne** du ticket :

```
coefLigne = max(caseCochee ? 2 : 1, coefJour(client, date), coefProduit(client, produit))
```

Les coefficients :

| Coefficient | Pour qui | Valeur |
|---|---|---|
| Case cochée | tous, sur les tickets qui suivent la case, jusqu'au dimanche 23:59 (le ticket qui coche reste à ×1) | ×2 |
| Jour perso | un client, un jour de la semaine | ×3 |
| Offre perso | un client, un produit | ×2 ou ×3 |
| Produit de la semaine | tous, un produit | ×2 |
| Produit habituel | jamais bonifié | ×1 |

Demande :
- un objet **BonusHebdo** par client et par semaine :
  `{client_id, week, day?: {index (0 = lundi), coef, reason}, products: [{product_id, coef, type: perso|week|usual, reason}]}` ;
- une écriture, par exemple `PUT /admin/loyalty/cards/{id}/weekly-bonuses` par lots, que le cockpit (ou l'ERP lui-même)
  remplit chaque lundi ;
- une lecture côté app, `GET /webshop/loyalty/weekly-bonus`, pour l'onglet Points ;
- au calcul des points d'un ticket, le max par ligne ci-dessus, en plus des `bonuses` existants (toujours le max, jamais la somme) ;
- `POST /admin/loyalty/simulate` qui accepte des lignes `{product_id, amount_cents}` et le client, pour la simulation du cockpit.

## 3. Le profil d'achat, pour choisir les bonus

Pour choisir le jour perso et l'offre perso, il faut connaître pour chaque client :
- son jour et son créneau habituels ;
- les catégories qu'il achète et celles qu'il ne prend jamais ;
- son panier moyen ;
- les produits qu'il prenait et ne prend plus.

Les rapports de fidélité ne donnent que des totaux par période, sans les lignes de ticket.

Demande, au choix :
- un `GET /shops/{id}/loyalty/customers/{client}/profile` qui rend ces éléments ;
- ou que l'ERP choisisse lui-même les bonus (point 2) d'après ses tickets.

## 4. L'échange automatique au palier

Le handoff : « tous les 100 points, un bon de 5 €, le reste est gardé », sans geste du client. L'API, elle, dit :
« This does not exchange automatically when a tier is reached », et seul le client peut échanger
(`POST /webshop/loyalty/points/redeem`, jeton client).

Demande : une option sur la carte, par exemple `points_config.auto_redeem: true`. Quand le solde atteint le palier,
l'ERP émet le bon et déduit les points, autant de fois que nécessaire.

## Ce qui reste au cockpit

- Le calendrier des quatre saisons de l'année, avec leurs thèmes : `src/data/fidelite_bingo.json`.
- Les réglages, la publication de la carte, l'émission des bons du bingo (le cron du lundi) et la lecture de la
  récurrence.
