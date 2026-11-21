# La fiche produits du ticket de caisse : la page que le client ouvre en scannant son ticket

**Statut : maquette à valider (21/11/2026).** Rien n'est codé côté serveur ni à la caisse.

Demande de Sam (21/11/2026) : une page que le client ouvre en scannant le QR de son ticket de caisse (adresse du
serveur + numéro du ticket), avec, pour **chaque produit du ticket**, tout ce qui est possible : allergènes,
composition, comment le cuire, comment le finir… Mobile d'abord, au style du site **atelierby.be**. Précisions données
en cours de route :

- la police d'accent est **Playwrite DEVA**, pas Daniel (celle du site) ;
- **c'est ce que les clients verront** : ton du site public, aucun mot interne (pas de « Comptoir Frigo - 1 ») ;
- **à glisser** sur téléphone ;
- **pas de grandes photos inutiles** ;
- **la langue suit la boutique du ticket** : un ticket de Halle ouvre la page en néerlandais ;
- **des rectangles aux coins arrondis**, pas de pastilles en pilule (comme le design system, §2).

## Les captures

| Fichier | Ce qu'il montre |
|---|---|
| `0-tickets.png` | Les deux tickets de caisse et leur QR : Corbais en français, Halle en néerlandais |
| `1-telephone-ecran.png` | Le premier écran après le scan (390 px) : en-tête compact, le ticket et ses lignes |
| `2-telephone-page.png` | La page entière au téléphone : ticket, allergènes du ticket, ordre de fraîcheur, fiches, avis, application, boutique |
| `3-telephone-glisser.png` | Le carrousel des fiches en trois temps : fiche 1, le geste, la fiche 4 choisie dans la barre |
| `4-telephone-fiche-soupe.png` | Une fiche entière : composition, allergènes, cuisson (casserole, micro-ondes), finition, conservation |
| `5-bureau-haut.png`, `6-bureau-fiches.png` | Sur ordinateur (1 440 px) : le ticket et les allergènes côte à côte, les fiches en grille de deux |
| `7-telephone-halle-nl.png` | Le ticket de Halle : la page s'ouvre en néerlandais (FR reste à un toucher) |

Pages : `corbais-fr.html` (défaut de Corbais), `corbais-nl.html`, `halle-nl.html` (défaut de Halle), `halle-fr.html` ;
tickets : `ticket-corbais.html`, `ticket-halle.html`. Elles s'ouvrent servies depuis la racine du dépôt (les polices sont
dans `shared/public/assets/ds/fonts/`).

## La page

1. **En-tête** sans photo : « Vos produits, *en détail.* » (Gotham Black, accent Playwrite DEVA en rubis), la date, le
   nombre d'articles et le total.
2. **Le ticket** : une ligne par produit (vignette, quantité, prix) ; toucher une ligne ouvre sa fiche dans le carrousel.
3. **Les allergènes du ticket** : chaque allergène présent et les produits qui le portent, ceux qu'aucun produit ne
   contient (d'après les fiches), et l'avertissement sur les traces : les ateliers travaillent aussi fruits à coque,
   sésame, moutarde, poisson, arachides et soja (ce que déclare au moins un produit du catalogue).
4. **Dans quel ordre les déguster** : les produits rangés par date limite (vente + durée de conservation du panel) :
   « Aujourd'hui, avant 20 h 45 » → « Jusqu'à demain matin » → « Jusqu'au mardi 1er décembre ».
5. **Les fiches, à glisser** : une fiche par écran, la suivante dépasse pour inviter au geste (défilement horizontal
   aimanté, CSS `scroll-snap`) ; une **barre des produits collante** sous la barre du haut (toucher un produit y mène) ;
   position « 1 / 5 », points et flèches comme le carrousel du site ; la hauteur suit la fiche affichée. Chaque fiche :
   vignette et quantité, famille, nom, prix ; accroche ; étiquettes (date limite, végétarien, poids, origine) ;
   **Composition** (allergènes en gras, comme sur une étiquette) ; **Allergènes** (présents, et les 14 un par un sur
   demande) ; **Cuisson** (mode, température ou puissance, durée, conseil ; « sans cuisson » sinon ; ce qu'il vaut
   mieux éviter) ; **Finition** ; **Conservation** et date limite ; « Le recommander » (le produit au webshop de la
   boutique). Sur ordinateur, plus de carrousel : une grille de deux colonnes.
6. **Votre avis** (avis Google de la boutique), **l'application**, **la boutique** (adresse, horaires s'ils sont connus,
   téléphone, « Commander pour demain »), et le pied : « en cas d'allergie, demandez conseil en boutique ».

Style : les jetons de la page d'accueil d'atelierby.be relevés le 21/11/2026 (papier `#FCFBF8`, sable `#F3EEE6`, lin
`#EAE4DC`, rubis `#8D1D2C`, abricot `#F2C9A0`, encre `#232220`), Gotham comme le site (400 Regular New, 500 Medium,
600 Regular, 700 Black), sur-titres en capitales espacées, cartes blanches sur sable, boutons noirs. Écart voulu avec
le site : aucune pilule (le site en a), des rectangles aux coins de 6 à 10 px ; restent ronds les puces et les points.

## La langue

La langue par défaut est celle de la **boutique du ticket** (`boutiques.{id}.langue` dans `donnees.json` : Corbais `fr`,
Halle `nl`) ; le sélecteur FR / NL reste en haut. Le ticket de caisse est imprimé dans la même langue (« Kassa 1 »,
« TOTAAL EUR », « waarvan btw 6 % »). Le néerlandais suit le ton du site (« uw »). Les noms et les ingrédients
néerlandais viennent des **alias du panel** (catalogue lu avec `lang_code=nl` : « Traditioneel Stokbrood 500 g. »,
« Brie, Appelen & Honing », « Soep - Pompoen & Citroen ») ; les prix sont ceux de chaque boutique (Halle : croissant
1,80 €, stokbrood 2,20 €).

## Le QR du ticket

`{adresse}/t/{boutique}-{ticket}-{clé}` (maquette : `atelierby.be/t/2-104583-7KQ2`). L'adresse se lit dans la
configuration (`erp_cles`), jamais en dur. La **clé** (quelques caractères d'un HMAC de la boutique et du numéro) empêche
de parcourir les tickets des autres clients en changeant le numéro. Il faut que la caisse (TF Buddy) imprime ce QR en
pied de ticket : c'est leur côté, à demander.

## D'où viennent les données

| Bloc | Source | Dans la maquette |
|---|---|---|
| Lignes du ticket | `GET /transactions/{id}?include=products` (le cockpit la lit déjà, `shared/src/panel_ventes.php`) | ticket composé (numéro, heure, quantités) ; produits et prix réels |
| Nom, prix, photo, poids, végétarien | catalogue public : `GET /api/v1/shops/{id}/products/available?include=webshop` | réels |
| Allergènes | même catalogue, `webshop.allergen_codes` (les matières de la fiche technique) | réels |
| Durée de conservation | `shelf_life_minutes` | réelle (la date limite en est calculée) |
| Ingrédients | `label_ingredients` | réels pour Brie, Pommes & Miel et l'éclair ; **exemples** pour le croissant, la baguette et la soupe (voir plus bas) |
| Accroche, origine, cuisson, finition, conseil de conservation | aucun champ du panel aujourd'hui (`reheating_time_minutes` / `reheating_temperature_celsius` existent mais sont vides) | **exemples**, listés produit par produit dans `donnees.json` (`_exemples`) |

## Ce que la lecture du catalogue a montré (Corbais, 254 produits, 21/11/2026)

- **Les ingrédients ne sont souvent que le nom du produit** : 169 produits n'ont qu'un élément (« Ingrédients :
  Croissant. », « Ingrédients : Baguette Tradition. »), 13 n'en ont aucun, 72 en ont une vraie liste. Pour les produits
  achetés finis à la centrale, il faut la composition du fournisseur sur la matière.
- **Cuisson** : `reheating_*` est rempli pour **1** produit sur 254 (TartoCake Cerise & Pistache, 45 min à 160 °C).
  **Nutri-Score** : 0 sur 254. Photo : 188 sur 254. Poids : 133.
- **La température de conservation est une valeur par défaut** (4 °C pour 200 produits, croissants et baguettes compris) :
  la page ne peut pas dire « au frigo » d'après ce champ ; il faut un conseil de conservation par produit.
- **La durée de conservation est souvent la valeur par défaut** (1 444 minutes pour 160 produits).
- Fautes visibles par le client dans les noms des matières : « Quiche Greque », « Paix au Noix & Sésame ».
- **Le catalogue public du webshop expose le coût de revient et la marge** (`recipe_cost_gross`, `recipe_cost_net`,
  `expected_margin`) et le stock (`in_stock`) de chaque produit, à qui l'appelle sans compte. La page du ticket ne doit
  rendre que les champs listés ci-dessus ; la fuite elle-même est à signaler à TF Buddy.

## Pour la réaliser (proposition, à décider)

- Domaine **Marketing** (la relation client) : la page `marketing/public/ticket/`, l'API
  `GET /api/v1/public/marketing/tickets/{clé}` (format de `docs/API_STANDARD.md`) qui vérifie la clé, lit les lignes du
  ticket et les champs du catalogue, et ne rend que ce que la page affiche.
- Les textes pour le client qui n'ont pas de champ au panel (accroche, cuisson, finition, conservation, en FR et NL) :
  à demander à TF Buddy comme champs du produit ; à défaut, une table `mar_fiche_client` (une ligne par produit et par
  langue), remplie par le siège.
- La caisse : le QR en pied de ticket (TF Buddy).

## Régénérer

```bash
cd marketing/docs/maquettes/ticket-fiche-produits
python3 generer.py
node capturer.js
```

`generer.py` n'a besoin de rien (le module Python `qrcode` seulement si un `qr-*.svg` manque) ; `capturer.js` utilise le
Playwright du conteneur et sert la racine du dépôt le temps des captures. Les photos (webshop) et le logo (site) sont lus
en ligne.
