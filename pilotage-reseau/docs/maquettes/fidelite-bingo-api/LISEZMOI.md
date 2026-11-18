# Fidélité, à quoi ça ressemble maintenant : la carte à points dans l'ERP, le bingo par son API

Demande du 18/11/2026 (Sam) : « à quoi ça pourrait ressembler maintenant, fais une maquette ». Elle suit
`../fidelite-bingo-erp` (les écrans de l'admin ERP habillés avec le handoff Claude Design). Elle respecte la règle
du même jour : « n'utiliser que la structure disponible, ne pas créer de table ou autre ».

**Le montage**, celui de `src/fidelite_bingo.php` (décision du 13/11/2026) :
- **dans l'ERP**, une seule carte de fidélité, **à points**, permanente. Ce sont les champs existants de
  `points_config` : 1 point par euro, paliers, bonus jour et heure, coefficients par magasin. Les cartes à
  tampons s'arrêtent ;
- **le bingo** se calcule dans le cockpit, uniquement par l'API de l'ERP. La case de la semaine se coche dès
  10 € d'achats dans la semaine, tous magasins (`shop_activity.gross_sales_before_corrections` de
  `GET /shops/{id}/loyalty/customers?from=&to=&card_id=`). Les bons sont
  des codes des **campagnes de bons existantes** de l'ERP (`POST /admin/voucher-campaigns/{id}/codes`), créés
  chaque lundi au nom du client ;
- les saisons, les thèmes des 13 semaines et les valeurs des bons sont dans le fichier
  `src/data/fidelite_bingo.json`, **pas dans une table**.

| Écran | Où | Ce qu'il lit | Capture |
|---|---|---|---|
| 1 · Cartes de fidélité | ERP admin (`overview.twig`) | `GET /loyalty/cards` : la carte à points active, la carte café arrêtée | `captures/1-erp-cartes.jpg` |
| 2 · La carte à points, étape 2 | ERP admin (`form.twig` + `points.twig`) | Les champs de `points_config` ; « Carte produit » décochée | `captures/2-erp-carte-points.jpg` |
| 3 · Fidélité › Programme | Cockpit, ERP franchisé › Fidélité | L'état des pièces dans l'ERP, les règles et la carte de la saison (fichier de saison), ce que l'API ne permet pas | `captures/3-cockpit-programme.jpg` |
| 4 · Fidélité › Magasins | Cockpit | Un magasin (Halle) : la case semaine par semaine, à relancer, les membres et leurs 13 cases | `captures/4-cockpit-magasin.jpg` |
| 5 · Fidélité › Un client | Cockpit | La carte de bingo d'un client, ses points (ERP), ses bons (codes des campagnes) | `captures/5-cockpit-client.jpg` |
| 6 · App client | L'app de l'ERP (routes `/webshop`, jeton du client) | Bingo, Points, Portefeuille, le bon montré à la caisse ; la route sous chaque écran | `captures/6-app-client.jpg` |

## Vérifié dans l'API de l'ERP (18/11/2026)

Spécification de `test.tfbuddy.com` (`/docs/openapi.json`, 1 277 routes) et réponse de la production
`atelierby.tfbuddy.com`. Sa documentation publiée (`/api/documentation/api.php`, 933 routes) ne liste pas les routes
de fidélité, mais elles répondent **401** (protégées) et non **404** (absentes) : elles existent.

| Pièce | Route | Ce qui est confirmé |
|---|---|---|
| Carte à points (admin) | `/admin/loyalty/cards`, `/options`, `/preview`, `/simulate` | Les routes du `.rar` |
| Achats de la semaine par client | `GET /shops/{id}/loyalty/customers?from=&to=&card_id=` | `items[].shop_activity.gross_sales_before_corrections`, `programs[].shop_activity.points_earned`, `global_progress.points` |
| Bon à un client | `POST /admin/voucher-campaigns/{id}/codes` | Corps `code`, `status`, `id_customer`, `valid_from`, `valid_to`, `usage_limit` ; lecture filtrée par `id_customer` |
| App : points | `GET /webshop/loyalty/points`, `POST …/points/redeem` | Solde, 50 derniers lots, échange au palier exact contre un bon au nom du client |
| App : bons | `GET /webshop/vouchers` | Les bons du client (jeton client) |
| App : règles, historique | `GET /webshop/loyalty/program`, `/history` | Routes présentes ; le détail de l'historique n'est pas décrit |
| Caisse | `POST /vouchers/validate`, `/redeem`, `/reverse` | Présentes |

**Ce qui reste à faire pour que ça tourne, sans table :**
- le droit `LOYALTY.MANAGE` pour le compte de l'API du cockpit (403 le 13/11, `tools/sonde_cartes_fidelite.php`) ;
- la grille du bingo dans l'app : aucune route ne la rend. L'app la calcule depuis l'historique du client et le
  calendrier de saison, ou lit le cockpit (`/fidelite/client`, aujourd'hui réservé au siège) ;
- passer le module de sa page de test (`public/fidelite-test`, écritures en simulation) à la production : campagnes
  actives, tâche du lundi.

Les écrans 3 à 5 sont l'habillage Claude Design des trois parties de la page de test `public/fidelite-test` :
A · l'admin, B · le magasin, C · la carte du client. La maquette est datée du jeudi 14/01/2027, semaine 8 de
l'hiver. Clients et chiffres sont **fictifs**. Le scénario de Julie D. est celui du handoff : 6 cases sur 13, S6
manquée, 6,40 € sur 10 € cette semaine, Bon des Rois à utiliser.

## Ce que l'API de l'ERP ne permet pas (écran 3, « Pas dans l'API de l'ERP »)

- le **×2 après la case**, pour un client, jusqu'au dimanche : l'ERP n'a que des bonus par jour et heure, pour tous ;
- le **produit de la semaine ×2** : pas de coefficient par produit ;
- l'**échange automatique au palier** : le client échange lui-même ses points ;
- les **coefficients personnels** (jour perso, offre perso).

Ces règles demanderaient un changement du moteur de l'ERP, donc une demande à son équipe. L'écran les signale et
ne les simule pas.

## À décider

- Activer la campagne « Bingo hiver · 10 € » (super bon) dans l'ERP avant la semaine 13.
- Valider les montants (seuil 10 €, bons de 2 €, super bon de 10 €, provisoire) et les thèmes du printemps, de
  l'été et de l'automne (`aValider` dans le fichier de saison).
- Arrêter les cartes à tampons (`ends_at`) au lancement de la saison.

## Refaire les captures

```bash
node shot.js
```

`index.html` reprend la feuille et les composants de `../fidelite-bingo-erp` (`maquette.css`, `donnees.js`), puis
`extra.css`, `donnees.js` (règles et saison copiées du fichier de saison, clients fictifs) et `ecrans.js`.
