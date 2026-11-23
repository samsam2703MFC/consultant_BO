# La fiche produits du ticket de caisse : la page que le client ouvre en scannant son ticket

**Statut : en ligne (21/11/2026)** côté cockpit (voir « En ligne ») ; le QR à la caisse reste à faire chez TF Buddy.
Les captures et les pages `*.html` de ce dossier sont la maquette d'origine.

Demande de Sam (21/11/2026) : une page que le client ouvre en scannant le QR de son ticket de caisse (adresse du
serveur + numéro de transaction), avec, pour **chaque produit du ticket**, tout ce qui est possible : allergènes,
composition, comment le cuire, comment le finir… Mobile d'abord, au style du site **atelierby.be**. Précisions données
en cours de route :

- la police d'accent est **Playwrite DEVA**, pas Daniel (celle du site) ;
- **c'est ce que les clients verront** : ton du site public ;
- **à glisser** sur téléphone ;
- **pas de grandes photos inutiles** ;
- **la langue suit la boutique du ticket** : un ticket de Halle ouvre la page en néerlandais ;
- **des rectangles aux coins arrondis**, pas de pastilles en pilule (comme le design system, §2) ;
- **le QR porte le numéro de transaction** (il est dans l'API) ; **tout le texte vient de l'API** : la maquette
  n'invente aucun texte produit.

## Les captures

| Fichier | Ce qu'il montre |
|---|---|
| `0-tickets.png` | Les deux tickets de caisse et leur QR : Corbais en français, Halle en néerlandais |
| `1-telephone-ecran.png` | Le premier écran après le scan (390 px) : en-tête compact, le ticket et ses lignes |
| `2-telephone-page.png` | La page entière au téléphone : ticket, allergènes du ticket, ordre de fraîcheur, fiches, avis, application, boutique |
| `3-telephone-glisser.png` | Le carrousel des fiches en trois temps : la cannelloni, le geste, le croissant choisi dans la barre |
| `4-telephone-fiche-cannelloni.png` | Une fiche entière : composition, allergènes, réchauffage (180 °C · 10 min), préparation et finition, conservation |
| `5-bureau-haut.png`, `6-bureau-fiches.png` | Sur ordinateur (1 440 px) : le ticket et les allergènes côte à côte, les fiches en grille de deux |
| `7-telephone-halle-nl.png` | Le ticket de Halle : la page s'ouvre en néerlandais (FR reste à un toucher) |

Pages : `corbais-fr.html` (défaut de Corbais), `corbais-nl.html`, `halle-nl.html` (défaut de Halle), `halle-fr.html` ;
tickets : `ticket-corbais.html`, `ticket-halle.html`. Elles s'ouvrent servies depuis la racine du dépôt (les polices sont
dans `shared/public/assets/ds/fonts/`).

## Le QR du ticket

`{adresse}/t/{boutique}-{transaction}-{clé}` (maquette : `atelierby.be/t/2-104583-7KQ2` ; servi aujourd'hui :
`{adresse}/ticket/?t={boutique}-{transaction}-{clé}`, clé de 6 caractères). Le numéro est celui de la
transaction du panel : `GET /shops/{boutique}/transactions/{transaction}` (ou `/transactions/{id}?include=products`, que
le cockpit lit déjà dans `shared/src/panel_ventes.php`) rend les lignes du ticket. L'adresse se lit dans la configuration
(`erp_cles`), jamais en dur. La **clé** (quelques caractères d'un HMAC de la boutique et de la transaction) empêche de
parcourir les tickets des autres clients en changeant le numéro. La caisse (TF Buddy) imprime ce QR en pied de ticket
(`POST /pos/transactions/{id}/fiscal-receipt-printed` marque l'impression du ticket) : c'est leur côté, à demander.

## La page

1. **En-tête** sans photo : « Vos achats, *en détail.* » (Gotham Black, accent Playwrite DEVA en rubis), la date, le
   nombre d'articles et le total.
2. **Le ticket** : une ligne par produit (vignette, quantité, prix) ; toucher une ligne ouvre sa fiche dans le carrousel.
3. **Les allergènes du ticket** : chaque allergène présent et les produits qui le portent, et l'avertissement sur les traces (allergènes que déclare au moins un produit du catalogue).
4. **Dans quel ordre les déguster** : les produits rangés par date limite (heure de la transaction + durée de
   conservation du produit).
5. **Les fiches, à glisser** : une fiche par écran, la suivante dépasse pour inviter au geste (CSS `scroll-snap`) ;
   barre des produits collante ; « 1 / 5 », points et flèches comme le carrousel du site ; la hauteur suit la fiche
   affichée. Sur ordinateur, une grille de deux colonnes. Un bloc sans donnée dans l'API n'est pas affiché.
6. **Votre avis** (avis Google de la boutique), **l'application**, **la boutique**, et le pied : « en cas d'allergie,
   demandez conseil en boutique ».

Style : les jetons de la page d'accueil d'atelierby.be (papier `#FCFBF8`, sable `#F3EEE6`, lin `#EAE4DC`, rubis
`#8D1D2C`, abricot `#F2C9A0`, encre `#232220`), Gotham comme le site (400 Regular New, 500 Medium, 600 Regular,
700 Black), sur-titres en capitales espacées, cartes blanches sur sable, boutons noirs ; aucune pilule (le site en a) :
des rectangles aux coins de 6 à 10 px.

## D'où vient chaque bloc (tout est lu dans l'API, rien n'est réécrit)

Deux lectures par produit : la **fiche technique** du panel, `GET /products/{id}/technical-sheet/raw` (le cockpit la lit
déjà pour le manuel Formation, `formation/src/fiches_techniques.php`), et le **catalogue** de la boutique,
`GET /shops/{boutique}/products/available?include=webshop&lang_code={fr|nl}&alias_type=webshop`.

| Bloc | Champ de l'API |
|---|---|
| Nom, famille | catalogue : `name` (alias de la langue), `category.groups[].name` ; sinon fiche : `name`, `category_name` |
| Prix | catalogue de la boutique : `portion_price_gross` ; sinon fiche : `suggested_sale_price` |
| Vignette | catalogue : `webshop_image.url` ; sinon fiche : `photos.main_photo_path` (adresse signée une heure : le serveur la redemande, comme `GET /formation/ref/fiches/photo/{id}`) |
| Composition | catalogue : `label_ingredients` (traduit) ; les allergènes de chaque ingrédient viennent de la recette de la fiche (`recipe.elements[].allergens`) ; sans catalogue, les ingrédients de la recette |
| Allergènes | recette de la fiche + catalogue `webshop.allergen_codes` |
| Cuisson | fiche : `reheating_temperature_celsius` et `reheating_time_minutes` (« Réchauffer au four ») ; les compétences de cuisson `required_competences[].description` (« Comment nous le cuisons ») |
| Préparation et finition | fiche : `preparation.steps[].step_description` |
| Conservation | fiche : `storage_description` (« 2°C – 4°C ») et `shelf_life_minutes` (la date limite) |
| Végétarien, poids | fiche : `is_vegetarian`, `single_weight` |
| « Le recommander » | seulement si `webshop_active` |

Les routes rendent aussi le coût, la marge et le stock : la page n'en garde rien (voir plus bas).

## Ce que la lecture a montré (21/11/2026)

- **Les produits de la centrale n'ont pas leur composition au panel** : la recette du panel n'a qu'un ingrédient, le
  produit fini (« Croissant », « Soupe Potiron & Citron (6 x 1l.) ») ; la page affiche alors ce nom. La composition
  complète, la conservation et la préparation existent chez SLFood (`final_product.storage_info_label`,
  `prepare_info_label`, ingrédients de la recette aplatie avec leurs alias d'étiquette, par langue :
  `pilotage-reseau/apps/production/app/App/Services/Product/ProductExportService.php`). Elles arrivent dans TF Buddy par
  le catalogue du fournisseur : `GET /material-suppliers/catalog/products/{id}/specification` (`composition`,
  `storage_info`, `preparation_info`, par `lang_code`). Cette route n'a pas répondu au jeton de cette session : à lire avec
  le compte du cockpit, pour compléter les produits de la centrale.
- **Une béchamel sans allergène** : dans la recette de la cannelloni, l'ingrédient « Béchamel » (centrale) ne porte ni lait
  ni gluten. Pour ce plat, le total reste juste (le lait et le gluten viennent d'autres ingrédients), mais un produit qui
  n'aurait que cette béchamel comme source de lait ou de gluten serait annoncé sans. À corriger sur la matière avant de
  montrer les allergènes au client.
- **Conservation** : `storage_description` est celle du comptoir de la boutique (« Comptoir Frigo - 1, 2°C – 4°C »),
  croissants compris ; la page dirait donc « entre 2 °C et 4 °C » pour un croissant.
- **Réchauffage** rempli pour 2 des 275 produits lus (254 du catalogue de Corbais, 21 plats chauds) : la cannelloni
  poulet & estragon et le TartoCake Cerise & Pistache ; **Nutri-Score** : aucun.
- **Les textes des compétences et des étapes sont écrits pour l'équipe** (« Déposer la roquette… sur la plaquette
  FlipFlap », « Préchauffez le four à 180 °C et faites cuire 25 à 35 minutes » pour une quiche vendue cuite) : la page
  les montre tels quels.
- **Néerlandais** : noms et ingrédients traduits (alias du panel) ; étapes et compétences seulement en français
  (`lang_code=nl` les rend en français). Les traductions passent par `PATCH /recipe-preparation-steps/{id}/aliases` ;
  en attendant, la page néerlandaise les montre en français (balisées `lang="fr"`).
- Fautes visibles par le client : « Quiche Greque », « Paix au Noix & Sésame » (noms de matières).
- **Le catalogue public du webshop expose le coût de revient et la marge** (`recipe_cost_gross`, `recipe_cost_net`,
  `expected_margin`) et le stock (`in_stock`) de chaque produit, sans compte. La fiche technique « raw » rend aussi le
  coût. La page ne rend que les champs du tableau ci-dessus ; la fuite est à signaler à TF Buddy.

## En ligne (21/11/2026)

- Page client : `/consulant_bo/ticket/?t={boutique}-{transaction}-{clé}` (`marketing/public/ticket/`), API
  `marketing/src/ticket_client.php` (route publique `/marketing/ticket/client`, gardée par la clé signée).
- Écran d'essai du siège : `/consulant_bo/ticket/essai/` — boutique et jour (les transactions du panel, chacune avec sa
  page et son QR), ou un numéro de transaction.
- Mesuré sur le serveur : la transaction porte `occurred_at_utc` (heure de la vente en UTC, convertie à l'heure belge),
  `insert_timestamp`, `ticket_key` (affiché comme numéro du ticket) et `total_gross_amount_after_discount` ; ses lignes
  `id_product`, `quantity`, `unit_gross_price`, `total_gross_value_after_discount`, `product_display_name`,
  `product_portion_label`. Le swagger ne décrit pas l'heure. Elle porte aussi le nom du client et le coût de fabrication
  des lignes : la page n'en rend rien.
- Le nom de la boutique vient du cockpit (`ceo_shop`) : le panel rend la raison sociale (« Neutralles SRL »).
- **Spécification du fournisseur** (21/11/2026) : lue par fournisseur (`GET /material-suppliers`, puis
  `/{id}/catalog-mappings` : produit du catalogue → matière, et `/{id}/catalog/products?include=specification_multilingual`),
  gardée six heures. Mesuré sur le serveur : 7 fournisseurs, 368 correspondances, 246 matières avec une spécification —
  mais **leurs compositions « fr » et « nl » sont le texte polonais** (« Mąka pszenna - T45, Woda, Masło… » : les alias
  d'étiquette des matières de SLFood ne sont pas remplis), et leurs conservation et préparation décrivent le surgelé livré
  à la boutique (« −18 °C », « laisser décongeler »). La page ne prend donc une composition que si elle est vraiment dans
  la langue (`tclTexteDansLaLangue` : ni identique au polonais, ni en lettres polonaises) et ne reprend ni la conservation
  ni la préparation. Elle s'affichera d'elle-même quand SLFood remplira les noms d'étiquette FR / NL de ses matières : un
  produit acheté fini prend alors la composition du fournisseur à la place de son nom, et un plat composé détaille
  l'ingrédient de la centrale (« Béchamel (…) »). Diagnostic, avec le nombre de compositions vraiment traduites :
  `GET /marketing/ticket/essai/specifications[?matiere=]`.
- **Allergènes à vérifier**, d'après les compositions polonaises de SLFood (matière → ce que le panel déclare) :
  « Cannelloni - Poulet Estragon » (165) contient *Masa jajowa* (œufs), *Mleko* (lait) et *Orzech pistacje* (pistaches,
  fruits à coque) — le panel ne déclare que le gluten ; « Quiche Greque » (76) : œufs, lait, gluten (déclarés) ;
  « Soupe Potiron & Citron » (178) : *vegeta* (un assaisonnement qui contient d'ordinaire du céleri) — le panel ne déclare
  aucun allergène.
- Retirés à la demande de Sam (21/11/2026) : le chapeau sous le titre, la phrase « Aucun de vos produits ne
  contient… », l'indication « Touchez un produit dans la barre… » sur ordinateur et le dépliant « Les 14 allergènes,
  un par un ». La maquette (captures) les montre encore.
- Réglages facultatifs (`ceo_app_setting`) : `ticketWebshopUrl`, `ticketAvisUrls` (`{boutique: adresse}`),
  `ticketApplicationUrl`, `ticketLangues` (`{boutique: "fr"|"nl"}`, sinon le code postal).

- Titre « Vos achats, *en détail.* » / « Uw aankopen, *in detail.* » (Sam, 21/11/2026 ; la maquette dit encore
  « Vos produits »).
- **Dossier pour un agent de code** : `marketing/docs/agent-fiche-ticket/` — `PROMPT_AGENT.md` (le prompt), `fiche-ticket.html`
  (la page en ligne, autonome, avec un ticket d'exemple), `donnees-exemple.json`, `fiche-ticket-agent.zip` (les trois) ;
  refaits par `python3 construire.py` à partir des fichiers servis.

## Pour la réaliser (proposition d'origine)

> Réalisé autrement le 21/11/2026 : page `marketing/public/ticket/`, API `GET /marketing/ticket/client?t=` (hors du
> format `/api/v1/…` de `docs/API_STANDARD.md`) — voir « En ligne ». Reste à faire : le QR en pied de ticket (TF Buddy).

- Domaine **Marketing** (la relation client) : la page `marketing/public/ticket/`, l'API
  `GET /api/v1/public/marketing/tickets/{clé}` (format de `docs/API_STANDARD.md`) qui vérifie la clé, lit la transaction,
  les fiches techniques et le catalogue de la boutique (cache de quelques heures, comme les fiches du manuel), et ne rend
  que ce que la page affiche.
- La caisse : le QR en pied de ticket (TF Buddy).

## Régénérer

```bash
cd marketing/docs/maquettes/ticket-fiche-produits
python3 generer.py
node capturer.js
```

`donnees.json` recopie les deux lectures (sans coûts ni marges) ; seul le ticket est composé (numéro de transaction,
heure, quantités). `generer.py` n'a besoin de rien (le module Python `qrcode` seulement si un `qr-*.svg` manque) ;
`capturer.js` utilise le Playwright du conteneur. Les photos du webshop et le logo du site sont lus en ligne ; la
vignette de la cannelloni (photo signée du panel) est gardée dans `img/`.
