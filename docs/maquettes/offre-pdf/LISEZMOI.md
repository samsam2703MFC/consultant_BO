# Le devis des magasins aux couleurs de L'Atelier by — trois propositions

**Statut : propositions à valider. Rien n'est branché dans le panel.**

La demande : refaire le visuel des offres (devis) que les magasins envoient à
leurs clients, produites par `OfferService::getPDF()` (panel, TCPDF). Le
document porte encore le thème **or #CD9B36 / anthracite #3F4648** de
l'ancienne identité, en DejaVu Sans. Les trois propositions reprennent la
charte du design system (`public/assets/ds/global.css`) : **rubis #8D1D2C,
abricot #F2C9A0, beige #EAE4DC, Gotham (texte) et Vank (titres)**, Playwrite
pour l'accent manuscrit de C.

Elles ne sont pas des maquettes HTML : elles sont **dessinées par TCPDF**,
le moteur du service. Ce que montrent les JPG et les PDF est donc ce que le
service produira, au millimètre, et chaque proposition est une classe prête
à brancher (voir « Brancher la proposition retenue »).

| | Proposition | Fichiers |
|---|---|---|
| — | **Aujourd'hui** : le code actuel recopié tel quel, sur la même offre | `avant.php`, `avant.pdf`, `avant.jpg` |
| A | **Épurée** : le même document, mêmes blocs, mêmes huit colonnes, réimprimé dans la charte (blanc, rubis seul, Gotham, Vank) | `proposition-a.php`, `a.pdf`, `a.jpg` |
| B | **Bandeau rubis** : bandeau et logo blanc, trois cartes (magasin, client, jour J), tableau à six colonnes, encadré du total à payer, bon pour accord, pied beige avec l'IBAN | `proposition-b.php`, `b.pdf`, `b.jpg` |
| C | **Carnet d'événement** : volet beige (jour J en grand, livraison, client, magasin, règlement), colonne lue comme une carte, remarque du client sur papier abricot, bon pour accord | `proposition-c.php`, `c.pdf`, `c.jpg` |
| | La planche : les quatre côte à côte, ce que chacune apporte, ses limites ; les variantes | `planche.jpg` |

Chaque proposition existe aussi en **retrait en magasin** (`*-retrait.jpg`) et
en **offre de 30 lignes** (`*-long.pdf`, `*-long-N.jpg`) : les pages suivantes
gardent leur décor (dessiné dans `Header()`/`Footer()`) et le tableau y reprend
son en-tête.

## L'offre d'exemple — illustrée

`donnees.php` : un lunch d'entreprise livré (« Séminaire d'équipe — lunch pour
40 personnes », jeudi 12/11/2026), sept lignes dont trois remisées (−10 %,
−5 %, −6,00 €), TVA 6 %, livraison 15,00 €, une remarque du client. **Le
client, les adresses, la TVA, l'IBAN et les montants sont inventés** ; seule la
forme des données est celle du service (`getById($id, true, true)` et
`ShopRepository::getById()`).

## Ce que le rendu d'aujourd'hui montre

Constaté sur `avant.jpg` / `avant-long-*.jpg`. Les trois propositions corrigent
les cinq premiers points ; le dernier, seulement B et C :

- **`htmlspecialchars()` dans un `Cell()`** (`renderOfferTitle`) : le nom de
  l'offre s'imprime « Séminaire d&amp;#039;équipe ». `Cell()` n'interprète pas
  le HTML : toute apostrophe, tout « & » s'affiche en code.
- Un nom de produit long **déborde sur les colonnes Qté et P.U.** (`Cell()` ne
  coupe pas).
- Montants à l'anglaise (`1.20€`, `584.02€`) ; « 0% » sur les lignes sans
  remise.
- Deux colonnes « Total (brut) » : la 4ᵉ est le total avant remise, la 8ᵉ le
  total TVAC après remise.
- Le pied (`SetY(-35)`) **se pose sur le commentaire** dès que la page est
  pleine ; en page 2 le tableau **repart sans en-tête**.
- Les frais de livraison ne sont dans **aucun total**.

## Les trois en bref

**A — Épurée.** Le plus petit pas : pas un montant de plus ni de moins, les
blocs dans le même ordre. En-têtes explicites (avant remise, après remise,
HTVA, TVAC), montants alignés à droite au format belge, « — » sans remise,
« dont TVA » sous le total général. Le moins d'encre. *Limites* : huit colonnes
restent denses ; ni total à payer livraison comprise, ni bon pour accord ; la
marque reste discrète.

**B — Bandeau rubis.** Reconnaissable au premier coup d'œil. Six colonnes : le
prix unitaire barré puis remisé et la pastille de remise disent à eux seuls
« avant / après remise » (le total avant remise = quantité × prix barré). Total
à payer **livraison comprise**, case « Bon pour accord » à signer et renvoyer,
coordonnées légales et IBAN au pied de chaque page. *Limites* : bandeau et
encadré pleins (encre) ; 16 libellés nouveaux.

**C — Carnet d'événement.** Le plus chaleureux, le plus « traiteur » : la date
en grand, ce que le client cherche d'abord ; une ligne par produit (quantité ×
prix barré/remisé, remise en pastille, TVAC et HTVA dessous) ; la remarque du
client mise en avant ; total à payer et bon pour accord comme B. *Limites* : le
plus loin du document actuel ; moins de lignes par page (30 produits : 3 pages,
contre 2 pour A et B) ; le volet beige est un aplat.

## À trancher avant de brancher

1. **Les frais de livraison sont-ils TVAC ?** B et C les ajoutent tels quels au
   « Total à payer » (A les laisse à part, comme aujourd'hui).
2. **La remise en montant** (`id_discount_type` ≠ 1) porte-t-elle sur la ligne
   ou sur l'unité ? L'exemple la prend sur la ligne ; l'affichage (« −6,00 € »)
   reste le même qu'aujourd'hui, l'ambiguïté aussi.
3. **« Émise le »** : le document n'a pas de date d'émission ; les propositions
   prennent la date de génération. Si l'offre porte un `created_at`, le
   prendre.
4. **Le nom commercial du magasin** (« Atelier by - Halle ») : lu dans
   `shop.name`, à défaut `representative_name`.
5. **« À l'attention de »** : pour un client B2B, le prénom et le nom du client
   s'ils sont remplis.
6. Une **date de validité** de l'offre (« valable jusqu'au … ») manque aux
   données : un champ à ajouter si on la veut.

## Brancher la proposition retenue (dans le panel)

1. **Les classes** : `commun.php` (charte + outils) et la proposition retenue,
   dans le namespace du panel (p. ex. `App\Api\Repositories\Utils`), avec
   `use App\Api\Core\Language;` — `getTranslation()` et `TCPDFModel` existent
   déjà. Dans `OffreCharte::POLICES`, mettre les chemins des TTF du serveur.
2. **`getPDF()`** : remplacer tout ce qui va de `// Configuration PDF` au
   `$filename = …` par
   ```php
   $pdf = (new OffrePdfB($offerData, $shopData, $currencySymbol))->rendre();
   ```
   puis supprimer `renderHeader()` … `renderFooter()` et
   `fillProfessionalTable()`. Le reste (contrôles, nom du fichier, `Output`)
   ne change pas.
3. **Les polices** : Gotham n'est livrée qu'en OpenType **CFF**, que TCPDF ne lit
   pas ; `polices/` en porte les TrueType convertis (`polices/otf2ttf.py`, avec
   fontTools). **Light, Medium et Black** sont les seules graisses livrées qui
   portent le « € » (`Gotham_Regular.otf` et `Gotham_Regular_New.otf` sont des
   Black sans « € »). Vank et Playwrite sont déjà en TrueType
   (`public/assets/ds/fonts/`). TCPDF en tire ses définitions une fois
   (`TCPDF_FONTS::addTTFfont()`, fait par `OffreCharte::polices()` dans
   `OffreCharte::$dossierPolices`) puis embarque un sous-ensemble : un devis
   pèse ~115 Ko. Gotham est marquée « aperçu et impression » (fsType 4) :
   l'embarquer dans un PDF est permis.
4. **Les logos** : `public/assets/img/logo.png` (A, C) et
   `public/assets/img/newsletter/logo-white.png` (B), PNG transparents (TCPDF a
   besoin de GD ou d'Imagick pour la transparence). Les déposer sous
   `SHARED_FILES_URL/franchise-images/` à côté de `pdf-logo.jpg` et pointer
   `OffreCharte::$logo` / `$logoBlanc` dessus.
5. **Les libellés** (groupe `offer`) à créer — valeurs françaises dans
   `donnees.php` (`libelles_offre()`) :
   - A (12) : `attention_of`, `before_discount`, `commercial_offer`, `continued`,
     `event_date`, `excl_vat`, `for`, `from`, `incl_vat`, `including_vat`,
     `issued_on`, `offer_no` ;
   - B (16) : `agreement`, `agreement_date`, `agreement_signature`,
     `attention_of`, `continued`, `excl_vat`, `for`, `from`, `incl_vat`,
     `issued_on`, `offer_no`, `the_day`, `total_to_pay`, `unit_price`, `vat`,
     `your_notes` ;
   - C (18) : `agreement`, `agreement_date`, `agreement_signature`,
     `at_the_shop`, `attention_of`, `continued`, `excl_vat`, `for`, `incl_vat`,
     `issued_on`, `payment`, `prepared_by`, `prepared_for_you`, `the_day`,
     `total_to_pay`, `vat`, `your_notes`, `your_selection`.
6. Les dates longues (« jeudi 12 novembre 2026 ») passent par
   `IntlDateFormatter` dans la langue de l'appel (fr_BE, nl_BE, en_GB, pl_PL),
   à défaut `d/m/Y`. Les montants sont au format belge (`1 234,56 €`).

## Régénérer

```bash
# TCPDF, une fois, dans un dossier de travail
composer require tecnickcom/tcpdf
# les devis (PDF + JPG, pdftoppm de poppler-utils)
TCPDF_AUTOLOAD=/chemin/vendor/autoload.php php docs/maquettes/offre-pdf/generer.php
# la planche : serveur statique sur 8099 depuis la racine, puis
node docs/maquettes/offre-pdf/planche.js
```

`php generer.php b` ne refait que B. L'horodatage est figé au 29/10/2026 10:42
(sauf « avant », dont le code lit l'heure courante).
