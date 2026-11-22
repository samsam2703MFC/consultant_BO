# Prompt agent de code — La fiche produits du ticket de caisse (la page du QR)

> **À donner tel quel à un agent de code**, avec `fiche-ticket.html` (la page de référence, autonome : l'ouvrir dans un
> navigateur, au format téléphone 390 px puis ordinateur ; `?lang=nl` pour le néerlandais) et `donnees-exemple.json` (la
> réponse de l'API pour ce ticket d'exemple).
>
> La page existe et tourne : dépôt `consultant_BO`, page `marketing/public/ticket/` (`index.html`, `ticket.css`,
> `ticket.js`), API `marketing/src/ticket_client.php`, tests `marketing/bin/tests_ticket_client.php`, maquette et relevés
> `marketing/docs/maquettes/ticket-fiche-produits/LISEZMOI.md`. Ce prompt sert à la refaire ailleurs (webshop, site,
> application) ou à la faire évoluer **sans rien changer de ce qui est décidé ici**.
>
> État du 21/11/2026, décisions de Sam comprises.

---

## 0. Ta mission

Le client scanne le QR imprimé en pied de son **ticket de caisse**. Il arrive sur une page, sur son téléphone, qui lui
dit **tout sur chaque produit de son ticket** : composition, allergènes, cuisson, préparation et finition, conservation
et date limite. La page est au style du site **atelierby.be**, dans la **langue de la boutique** du ticket.

Reproduis `fiche-ticket.html` : mêmes blocs, même ordre, mêmes textes d'interface, mêmes valeurs de design. Les données
viennent de l'API du panel TF Buddy (§3) ; **aucun texte produit n'est écrit à la main**.

---

## 1. Règles de travail

1. **Tout le texte produit vient de l'API**, tel quel : pas de résumé, pas de reformulation, pas de texte inventé.
   Un bloc sans donnée **n'est pas affiché** (pas de « non renseigné », pas de bloc vide).
2. **Ne rends jamais** au navigateur ce que les routes du panel renvoient en plus : coût de revient, marge, prix d'achat,
   stock, coût de fabrication des lignes, nom du client, employé, appareil. La réponse au navigateur ne contient que
   les champs du §3.6.
3. **Pas de grandes photos** : des vignettes (76 px dans la fiche, 44 px dans le ticket, 30 px dans la barre).
4. **Rectangles aux coins arrondis, jamais de pilule** (`border-radius: 999px` interdit) : boutons 10 px, étiquettes
   et pastilles 6 px, cartes 18 px. Restent ronds : les puces et les petits points.
5. **Ne remets pas** ce que Sam a retiré (§4.4).
6. Interface en **français et néerlandais** (§6), apostrophes typographiques (`’`), espaces insécables dans les heures
   et les prix (« 20 h 45 », « 21.00 u », « 6,10 € », « € 6,10 »).
7. Adresses (webshop, avis Google, application, adresse du QR) **lues dans la configuration**, jamais en dur.

---

## 2. Le parcours et le QR

- **Le QR** imprimé en pied du ticket contient : `{adresse}/t/{boutique}-{transaction}-{clé}`
  (en essai : `https://185.180.206.46/consulant_bo/ticket/?t={boutique}-{transaction}-{clé}`).
  - `boutique` : l'identifiant de la boutique dans le panel (Corbais 2, Gosselies 3, Halle 4, Sombreffe 5…) ;
  - `transaction` : l'identifiant de la transaction du panel (`id`, pas le `ticket_key`) ;
  - `clé` : 6 caractères majuscules, `strtoupper(substr(hash_hmac('sha256', "ticket-client:{boutique}:{transaction}", SECRET), 0, 6))`.
    Elle empêche de lire le ticket d'un autre client en changeant le numéro. Comparaison en temps constant ; une clé
    fausse rend **404 « ticket introuvable »**, sans dire pourquoi.
  - La caisse (TF Buddy) doit imprimer ce QR : `POST /pos/transactions/{id}/fiscal-receipt-printed` marque l'impression.
- **La langue** : celle de la boutique. Belgique : code postal 1500–3999 ou 8000–9999 → **nl**, sinon **fr** (Bruxelles :
  fr). Un réglage par boutique peut forcer (`ticketLangues`). Le sélecteur FR / NL en haut à droite bascule sans
  recharger. Le ticket de caisse est imprimé dans la même langue.
- **Page publique**, `noindex`, sans session ; seule la clé protège.

---

## 3. Les données (API du panel TF Buddy, compte de service)

Base : `https://atelierby.tfbuddy.com/api/v1`. Garder les lectures **six heures** (fiches, catalogues, spécifications ;
pas la transaction). Mesuré sur le serveur le 21/11/2026 : le swagger ne décrit pas tout (voir les notes).

### 3.1 La transaction

`GET /shops/{boutique}/transactions/{transaction}` (repli : `GET /transactions/{transaction}?include=products`).
Refuser si `id_shop` ≠ boutique du QR.

| Champ | Usage |
|---|---|
| `occurred_at_utc` | heure de la vente, **en UTC** → heure de Bruxelles (non décrite au swagger ; repli `insert_timestamp`) |
| `ticket_key` | numéro affiché « Votre ticket n° … » (sinon l'`id`) |
| `total_gross_amount_after_discount` | total payé TTC |
| `products[].id_product`, `quantity` | le produit et sa quantité (même produit sur deux lignes → additionner, sauf portions différentes) |
| `products[].unit_gross_price` | prix unitaire TTC (sinon `total_gross_value_after_discount` / quantité) |
| `products[].product_portion_label` | portion (« 1/8 », « 1/2 ») : affichée « 1 (1/2) × 12,50 € » |
| `products[].product_display_name` | nom de caisse (repli seulement) |

À ne jamais rendre : `client_name`, `id_client`, `id_employee`, `id_device`, `manufacturing_cost_snapshot`, `remark`…

### 3.2 La fiche technique de chaque produit

`GET /products/{id}/technical-sheet/raw?lang_code=fr` (les textes ne sont qu'en français : `lang_code=nl` ne traduit pas).

| Champ | Bloc |
|---|---|
| `object.name`, `object.category_name` | nom et famille, si le catalogue ne les donne pas |
| `object.single_weight` | étiquette « 400 g » (si > 0) |
| `object.is_vegetarian` | étiquette « Végétarien » (si 1) |
| `object.shelf_life_minutes` | date limite = heure de la vente + durée ; ordre de fraîcheur |
| `object.storage_description` | « 2°C – 4°C » → « À conserver entre 2 °C et 4 °C. » |
| `object.reheating_temperature_celsius`, `reheating_time_minutes` | « Réchauffer au four · 180 °C · 10 min » (si > 0) |
| `object.webshop_active` | bouton « Le recommander » (si 1 et adresse du webshop réglée) |
| `object.suggested_sale_price` | prix, si ni la ligne ni le catalogue n'en donnent |
| `recipe.elements[]` (hors `is_part_of_package`) | composition (`name`), allergènes (`allergens`, noms français → codes §3.5), matière (`ingredient_id`), `source_type` (`CENTRAL` = de la centrale) |
| `preparation.steps[].step_description` | « Préparation et finition », étapes numérotées, telles quelles |
| `competences[]` dont le nom contient « cuisson » : `description` | « Comment nous le cuisons », texte tel quel (liste si « 1. … 2. … », replié au-delà de 260 caractères : « Lire la suite ») |
| `photos.main_photo_path` (ou `shop_photo_path`) | vignette si le catalogue n'en a pas — **adresse signée une heure** : ne pas la garder plus de 50 min |

### 3.3 Le catalogue de la boutique (noms et ingrédients traduits)

`GET /shops/{boutique}/products/available?webshop_active=1&include=webshop&alias_type=webshop&lang_code={fr|nl}` —
lu dans les deux langues. C'est la source des traductions (alias du panel).

| Champ | Usage |
|---|---|
| `name` | nom du produit dans la langue |
| `category.groups[0].name` | famille dans la langue (« Viennoiserie » / « Koffiekoeken ») |
| `label_ingredients` | composition de l'étiquette (« Ingrédients: … » / « Ingrediënten: … », préfixe retiré) |
| `webshop.allergen_codes` | allergènes (codes) |
| `webshop_image.url` | vignette |
| `portion_price_gross` | prix si la ligne n'en a pas |

À ne jamais rendre : `recipe_cost_gross`, `recipe_cost_net`, `expected_margin`, `in_stock`… (le catalogue public les
expose aujourd'hui : signalé à TF Buddy).

### 3.4 La spécification du fournisseur (composition des produits de la centrale)

Les produits achetés finis n'ont qu'une matière dans leur recette (« Croissant », « Cougnou … - Cru »). Leur composition
est dans la spécification du fournisseur :
`GET /material-suppliers` → pour chaque fournisseur : `GET /material-suppliers/{id}/catalog-mappings`
(`catalog_product_id` → `material_id`) et `GET /material-suppliers/{id}/catalog/products?include=specification_multilingual`
(`specification[]` : `lang_code`, `composition`, `storage_info`, `preparation_info`).

- Produit acheté fini (une seule matière, avec spécification) : la composition du fournisseur **remplace** son nom.
- Plat composé : l'ingrédient de la centrale est **détaillé** : « Béchamel (lait, farine de blé…) ».
- Les mots en CAPITALES du fournisseur (les allergènes de l'étiquette) sont mis en gras.
- **N'utiliser un texte que s'il est vraiment dans la langue** : au 21/11/2026, les compositions « fr » et « nl » de
  SLFood sont le texte polonais (« Mąka pszenna - T45, Woda, Masło… ») ; seules 15 compositions françaises (les glaces
  Rawette) sont vraies. Écarter un texte identique au polonais, ou avec des lettres polonaises (ąęłńśźżćó), ou des mots
  d'étiquette polonais (woda, cukier, mleko, jajka, cebula, kurczak, vegeta…).
- **Ne pas reprendre** `storage_info` ni `preparation_info` : ils décrivent le surgelé livré à la boutique (« −18 °C »,
  « laisser décongeler »), pas ce que le client emporte.

### 3.5 Les 14 allergènes (ordre et libellés)

| Code | FR | NL |
|---|---|---|
| `cereals_gluten` | Gluten | Gluten |
| `crustaceans` | Crustacés | Schaaldieren |
| `eggs` | Œufs | Eieren |
| `fish` | Poisson | Vis |
| `peanuts` | Arachides | Pinda’s |
| `soybeans` | Soja | Soja |
| `milk` | Lait | Melk |
| `nuts` | Fruits à coque | Noten |
| `celery` | Céleri | Selderij |
| `mustard` | Moutarde | Mosterd |
| `sesame_seeds` | Sésame | Sesam |
| `sulphites` | Sulfites | Sulfieten |
| `lupin` | Lupin | Lupine |
| `molluscs` | Mollusques | Weekdieren |

Allergènes d'un produit = ceux des éléments de sa recette ∪ `webshop.allergen_codes`. Noms français de la fiche technique
→ codes : « Céréales contenant du gluten » → `cereals_gluten`, « Œufs » → `eggs`, « Lait » → `milk`, « Graines de
sésame » → `sesame_seeds`, « Fruits à coque » → `nuts`, « Moutarde », « Arachides », « Poisson », « Soja »…

« Nos ateliers travaillent aussi … » = les allergènes que déclare au moins un produit du catalogue de la boutique et
qu'aucun produit du ticket ne contient.

### 3.6 La réponse au navigateur (forme exacte : `donnees-exemple.json`)

```
boutique   {id, nom, rue, cp, ville, tel, langue}        nom PUBLIC (« Atelier by - Halle »), pas la raison sociale
                                                           que rend GET /shops (« Neutralles SRL »)
langue     "fr" | "nl"
transaction {id, ticket, date (heure locale ISO) | null, total}
lignes[]   {id, qte, prix, portion, nom{fr,nl}, famille{fr,nl}, image,
            composition{fr,nl: {items: [[texte, [codes], détail, fournisseur]], traduit}},
            allergenes[codes], vegetarien, poids, vieMinutes, stockage, rechauffe{minutes,degres}|null,
            cuisson[textes], etapes[textes], webshop, fiche}
travailles [codes]
liens      {webshop, avis, application}                   vides si non réglés : blocs cachés
```

Un texte affiché dans une autre langue que celle de la page (repli en français) porte `lang="fr"`.

---

## 4. La page (de haut en bas)

### 4.1 Téléphone (d'abord)

1. **Barre du haut** collante (63 px, fond papier translucide, flou, filet) : logo L’Atelier (28 px de haut) à gauche,
   sélecteur **FR | NL** à droite (rectangle 9 px, la langue active en encre).
2. **En-tête** sans photo : sur-titre rubis « VOTRE TICKET · CORBAIS » ; titre **« Vos achats, »** (Gotham Black 40 px)
   et en dessous, en Playwrite DEVA rubis, **« en détail. »** ; une ou deux étiquettes sable : « Samedi 21 novembre 2026
   · 11 h 52 » (pictogramme horloge) (si l'heure est connue) et « 9 articles · 56,70 € ».
3. Fond **sable** pour la suite. **Carte du ticket** : « VOTRE TICKET N° {ticket_key} », « 5 produits, 9 articles », une
   ligne par produit (vignette 44 px, nom, « 4 × 1,70 € », total, flèche) — toucher une ligne ouvre sa fiche ; « Total
   payé, TVA comprise » et le total (24 px Black) ; en petit, la boutique et l'heure.
4. **Carte des allergènes** : sur-titre rubis « ALLERGÈNES », « Ce que contient votre ticket » ; une ligne par
   allergène présent : pastille rubis claire + noms des produits qui le portent ; encadré sable avec le pictogramme d'alerte : « Nos ateliers
   travaillent aussi poisson, arachides… : des traces restent possibles. Une allergie sévère ? Parlez-en en boutique
   avant de déguster. »
5. **Carte de fraîcheur** : « Dans quel ordre les déguster » — frise verticale, un point par date limite, le premier en
   rubis : « Aujourd’hui, avant 20 h 45 » / « Jusqu’à demain matin, dimanche 22 novembre » / « Jusqu’au mardi
   24 novembre » ; sous chaque date, les produits (« Croissant × 4 »). Sans heure de vente : « Dans les 12 heures après
   l’achat », « Dans les 24 heures… », « Dans les 3 jours… ». « Avant 20 h 45 » est arrondi au quart d'heure inférieur ;
   lendemain avant midi → « demain matin ».
6. **Les fiches, à glisser** : sur-titre « FICHE PAR FICHE », titre « Tout ce qu’il faut *savoir.* », une ligne
   « Glissez d’un produit à l’autre, ou touchez-en un dans la barre. » ; **barre des produits collante** sous la barre du
   haut (boutons vignette 30 px + nom + « ×4 », l'actif en encre, défilement horizontal centré sur l'actif) ; ligne de
   commandes : « 1 / 5 », points (l'actif : barre rubis 26 × 6 px, coins 2 px), flèches ← → (44 px, rectangles 10 px) ;
   **carrousel** : défilement horizontal aimanté (`scroll-snap-type: x mandatory`, `scroll-snap-stop: always`), une fiche
   par écran (largeur `100% - 44px`), la suivante dépasse à droite ; la hauteur du carrousel suit la fiche affichée ;
   après un changement de fiche, la page remonte en haut de la fiche si on était plus bas. Clavier : ← →.
7. **Une fiche** (carte papier, 18 px) : vignette 76 px avec la quantité en badge encre « ×4 » (coin haut droit) ; à
   côté : famille (sur-titre rubis), nom (25 px Black), prix (« 4 × 1,70 € · 6,80 € », « 1/2 · 12,50 € ») ;
   étiquettes : date limite (rubis plein si c'est aujourd'hui), « Végétarien » (abricot), poids ; puis les blocs, séparés
   par un filet, chacun avec son sur-titre en capitales espacées :
   - **Composition** : les ingrédients, chaque allergène entre parenthèses et en gras : « Brie de Meaux (**lait**),
     Roquette, … Baguette Tradition (**gluten**). » ;
   - **Allergènes** : pastilles rubis claires (« • Gluten ») ; sinon « Aucun des 14 allergènes réglementaires, d’après
     nos fiches. » ;
   - **Cuisson** (si donnée) : carte sable avec pictogramme four : « RÉCHAUFFER AU FOUR » / « **180 °C** · 10 min »
     (la durée en rubis) ; carte « COMMENT NOUS LE CUISONS » avec le texte de la compétence ;
   - **Préparation et finition** (si étapes) : liste numérotée (numéros dans des carrés rubis clair de 24 px) ;
   - **Conservation** : « À conserver entre 2 °C et 4 °C. » et l'encadré rubis clair (pictogramme horloge) « À déguster jusqu’au mardi
     24 novembre » ;
   - **« Le recommander »** (bouton noir pleine largeur), si le produit est au webshop.
8. **Fin de page** : carte abricot « VOTRE AVIS — Comment était votre passage à Corbais ? » + 5 étoiles + bouton
   « Laisser un avis » (si l'adresse est réglée) ; carte « Tous vos tickets, au même endroit » (application, si réglée) ;
   carte « VOTRE BOUTIQUE » : nom public, adresse, téléphone, « Commander pour demain » (si webshop).
9. **Pied** : « Les informations de cette page viennent des fiches techniques de nos produits, à la date de votre achat.
   Une recette peut évoluer : en cas d’allergie ou d’intolérance, **demandez conseil en boutique**. » et « © 2026
   L’Atelier By ».

### 4.2 Ordinateur (≥ 860 px)

Ticket à gauche, allergènes et fraîcheur à droite (`1.15fr 1fr`) ; plus de carrousel ni de commandes : les fiches en
**grille de deux colonnes** ; la barre des produits reste et fait défiler jusqu'à la fiche ; titre 64 px.

### 4.3 États

« Un instant… » pendant la lecture ; « **Ticket introuvable** — Le lien de ce ticket n’est pas valable. Scannez de
nouveau le QR de votre ticket, ou demandez en boutique. » (clé fausse, transaction absente) ; « Le serveur ne répond
pas. Réessayez dans un instant. »

### 4.4 Retiré par Sam (ne pas remettre)

- le chapeau sous le titre (« Composition, allergènes, cuisson, finition et conservation : tout sur les produits de
  votre ticket. ») ;
- la phrase « Aucun de vos produits ne contient, d’après nos fiches : arachides, céleri… » ;
- l'indication « Touchez un produit dans la barre pour aller à sa fiche. » (ordinateur) ;
- le dépliant « Les 14 allergènes, un par un » ;
- les grandes photos (couverture, photo pleine largeur des fiches, illustration de la boutique).

---

## 5. Design (jetons de la page d'accueil d'atelierby.be)

```css
:root{
  --paper:#FCFBF8; --ink:#232220; --muted:#8A847C; --faint:#B8B2A8;
  --hair:rgba(35,34,32,.08); --hair-2:rgba(35,34,32,.12);
  --ruby:#8D1D2C; --apricot:#F2C9A0; --lin:#EAE4DC; --sand:#F3EEE6;
  --gutter:clamp(20px,4vw,56px); --ease:cubic-bezier(.2,.8,.2,1); --nav:63px;
}
```

- **Polices** : Gotham — 400 `Gotham_Regular_New.otf`, 500 `Gotham_Medium.otf` (texte courant), 600 `Gotham_Regular.otf`
  (libellés, boutons), 700 `Gotham_Black.otf` (titres, valeurs) ; accent **Playwrite DEVA** Light (300), rubis, pour
  « en détail. » et « savoir. » (abricot sur fond sombre). Fichiers : `shared/public/assets/ds/fonts/` de consultant_BO
  (intégrés dans `fiche-ticket.html`).
- **Sur-titres** : 11 px, capitales, `letter-spacing: .26em`, 600, `--muted` (rubis pour les sections « clé »).
- **Corps** : 14 px / 1.5–1.6, 500, `#3a3631` ; légendes 12–13 px `--muted`.
- **Cartes** : fond `--paper` sur `--sand`, coins 18 px, 20 px de marge intérieure, pas d'ombre.
- **Bouton** : fond `--ink`, texte `--paper`, 14 px 600, 16 × 26 px, coins 10 px, flèche →.
- **Étiquettes** (`.tag`) : fond `--sand`, 12 px 600, 7 × 11 px, coins 6 px ; urgente : fond `--ruby`, texte blanc ;
  végétarien : fond `--apricot`, texte `#6b4420`.
- **Pastilles d'allergène** : fond `rgba(141,29,44,.08)`, texte `--ruby` 13 px 600, point rubis de 7 px, coins 6 px.
- **Encadrés** : date limite `rgba(141,29,44,.06)` texte rubis ; avertissement fond `--sand` avec le pictogramme d'alerte en rubis. Aucun emoji dans l'interface.
- Pictogrammes : traits 1,6 px, `stroke: currentColor`, sans remplissage (four, horloge, feuille, poids, flèches,
  alerte, étoile).

---

## 6. Textes de l'interface

| Clé | FR | NL |
|---|---|---|
| sur-titre | Votre ticket · {ville} | Uw kasticket · {ville} |
| titre | Vos achats, *en détail.* | Uw aankopen, *in detail.* |
| articles | {n} articles | {n} artikelen |
| ticket | Votre ticket n° {num} | Uw kasticket nr. {num} |
| ticket (titre) | {p} produits, {n} articles | {p} producten, {n} artikelen |
| total | Total payé, TVA comprise | Totaal betaald, incl. btw |
| allergènes | Ce que contient votre ticket | Wat uw kasticket bevat |
| traces | Nos ateliers travaillent aussi {liste} : des traces restent possibles. Une allergie sévère ? Parlez-en en boutique avant de déguster. | In onze ateliers verwerken we ook {liste}: sporen zijn mogelijk. Een ernstige allergie? Vraag raad in de winkel voor u proeft. |
| fraîcheur | Dans quel ordre les déguster | Wat eet u best eerst? |
| fiches | Fiche par fiche · Tout ce qu’il faut *savoir.* | Product per product · Alles wat u moet *weten.* |
| glisser | Glissez d’un produit à l’autre, ou touchez-en un dans la barre. | Veeg van het ene product naar het andere, of tik er een aan in de balk. |
| blocs | Composition · Allergènes · Cuisson · Préparation et finition · Conservation | Samenstelling · Allergenen · Bakken en opwarmen · Bereiding en afwerking · Bewaren |
| réchauffer | Réchauffer au four | Opwarmen in de oven |
| compétence | Comment nous le cuisons | Hoe wij het bakken |
| lire | Lire la suite | Meer lezen |
| conserver | À conserver entre {a} et {b}. | Bewaren tussen {a} en {b}. |
| date | À déguster {quand} | Te eten {quand} |
| aujourd'hui | aujourd’hui, avant {h} | vandaag, vóór {h} |
| demain | jusqu’à demain matin, {jour} | tot morgenochtend, {jour} |
| plus tard | jusqu’au {jour} | tot {jour} |
| sans heure | dans les {n} heures / jours après l’achat | binnen {n} uur / dagen na aankoop |
| végétarien | Végétarien | Vegetarisch |
| recommander | Le recommander | Opnieuw bestellen |
| aucun | Aucun des 14 allergènes réglementaires, d’après nos fiches. | Geen van de 14 wettelijke allergenen, volgens onze fiches. |
| avis | Votre avis · Comment était votre passage à {ville} ? · Laisser un avis | Uw mening · Hoe was uw bezoek in {ville}? · Laat een review achter |
| boutique | Votre boutique · Adresse · Téléphone · Commander pour demain | Uw winkel · Adres · Telefoon · Bestel voor morgen |

Formats : FR « Samedi 21 novembre 2026 · 11 h 52 », « 1er décembre », « 6,10 € » ; NL « Zaterdag 21 november 2026 ·
9.12 u », « € 6,10 ». Le néerlandais vouvoie (« uw »), comme le site.

---

## 7. Checklist

- [ ] Une clé fausse ou une transaction d'une autre boutique → 404, page « Ticket introuvable ».
- [ ] La réponse au navigateur ne contient ni coût, ni marge, ni stock, ni nom de client (chercher `cost`, `margin`,
      `stock`, `client` dans la réponse).
- [ ] Ticket de Corbais → français ; de Halle → néerlandais ; FR | NL bascule sans recharger.
- [ ] L'heure de vente vient de `occurred_at_utc` convertie à l'heure de Bruxelles (hiver +1, été +2).
- [ ] Nom public de la boutique, pas la raison sociale.
- [ ] Aucun texte polonais ; aucun « −18 °C » ni « laisser décongeler ».
- [ ] Un bloc sans donnée n'apparaît pas ; aucune pilule ; aucune grande photo ; les éléments du §4.4 absents.
- [ ] Téléphone 390 px : la fiche suivante dépasse, le glissement s'aimante, la barre suit, la hauteur s'ajuste.
- [ ] Ordinateur 1 440 px : grille de deux colonnes, plus de commandes du carrousel.
- [ ] Comparer avec `fiche-ticket.html`, côte à côte.

---

## 8. Données à corriger à la source (pas dans la page)

- **SLFood** : remplir les noms d'étiquette FR et NL des matières (aujourd'hui la composition « fr » / « nl » est le
  polonais).
- **Allergènes à vérifier** : « Cannelloni - Poulet Estragon » (matière 165) contient œufs, lait et pistaches d'après
  SLFood — le panel ne déclare que le gluten ; « Soupe Potiron & Citron » (178) contient de la *vegeta* (céleri
  probable) — le panel ne déclare rien ; « Béchamel » (691) n'a aucun allergène.
- **Panel** : réchauffage rempli pour 2 produits sur 275 lus ; température de conservation par défaut (4 °C, croissants
  compris) ; étapes et compétences seulement en français (`PATCH /recipe-preparation-steps/{id}/aliases`) ; fautes
  « Quiche Greque », « Paix au Noix & Sésame ».
- **TF Buddy** : le catalogue public du webshop expose coût de revient, marge et stock.
