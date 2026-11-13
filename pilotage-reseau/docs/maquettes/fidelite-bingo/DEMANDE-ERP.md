# Fidélité bingo + points : ce que l'API de l'ERP doit ajouter

13/11/2026. Ce document est destiné aux développeurs de l'ERP (TF Buddy). Le programme suit le handoff de Sam
(`handoff/README.md`). Le cockpit le règle et le lit **uniquement par l'API** : il n'a ni table ni moyen d'écrire des
points.

Comparé à l'openapi de test du 13/11/2026, il manque trois choses. Sans elles, l'écran les affiche comme « pas dans
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

## 2. Deux coefficients que l'API n'a pas : la case cochée et le produit de la semaine

L'API ne connaît que des créneaux (`bonuses` : jours, heures, coefficient), les mêmes pour tous, sur tout le ticket,
et le plus fort l'emporte. Les **jours ×2 / ×3** du programme y passent déjà.

**Pas de coefficient par personne pour le moment** (Sam, 13/11/2026). Le jour perso et l'offre perso du handoff sont
retirés, et l'ERP n'a pas besoin de profil d'achat.

Ce qui manque, **ligne par ligne** du ticket :

```
coefLigne = max(coefCréneau(date), caseCochee ? 2 : 1, produitDeLaSemaine(produit) ? 2 : 1)
```

| Coefficient | Pour qui | Valeur |
|---|---|---|
| Créneaux (jours, heures) | tous | ×2, ×3 — **déjà dans l'API** |
| Case cochée | un client, sur ses tickets qui suivent la case, jusqu'au dimanche 23:59 (le ticket qui coche reste à ×1) | ×2 |
| Produit de la semaine | tous, un produit, pour la semaine | ×2 |

Demande :
- **case cochée** : un multiplicateur tant que la case du client est cochée cette semaine. Il suppose le point 1.
- **produit de la semaine** : un coefficient par produit et par période sur la carte. Par exemple
  `points_config.product_bonuses: [{product_ids, from, to, coefficient}]`, appliqué ligne par ligne.
- dans les deux cas, toujours le max avec les créneaux, jamais la somme.
- `POST /admin/loyalty/simulate` qui accepte des lignes `{product_id, amount_cents}`, pour la simulation du cockpit.

## 3. L'échange automatique au palier

Le handoff : « tous les 100 points, un bon de 5 €, le reste est gardé », sans geste du client. L'API, elle, dit :
« This does not exchange automatically when a tier is reached », et seul le client peut échanger
(`POST /webshop/loyalty/points/redeem`, jeton client).

Demande : une option sur la carte, par exemple `points_config.auto_redeem: true`. Quand le solde atteint le palier,
l'ERP émet le bon et déduit les points, autant de fois que nécessaire.

## Ce qui reste au cockpit

- Le calendrier des quatre saisons de l'année, avec leurs thèmes : `src/data/fidelite_bingo.json`.
- Les réglages, la publication de la carte, l'émission des bons du bingo (le cron du lundi) et la lecture de la
  récurrence.
