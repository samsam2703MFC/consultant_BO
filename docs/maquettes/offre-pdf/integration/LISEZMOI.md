# Intégrer le devis C2 dans le panel

**C2 « une colonne » retenue le 29/10/2026.** Ce dossier contient ce qu'il faut
pour la brancher dans `OfferService::getPDF()`. Rien n'est encore poussé dans le
panel : son dépôt n'est pas accessible d'ici.

| Fichier | Où le poser dans le panel |
|---|---|
| `OfferPdf.php` — la classe du devis (C2, un seul fichier, namespace `App\Api\Repositories\Utils`) | à côté de `TCPDFModel.php` |
| `OfferService.php` — le service modifié, prêt à remplacer l'actuel | `App\Api\Services\Client\OfferService.php` |
| les 4 polices (ci-dessous) | dans `offer-pdf-fonts/`, à côté d'`OfferPdf.php` |
| `verifier.php` — le test de bout en bout | ne part pas dans le panel |

Les deux PHP gardent les fins de ligne CRLF du panel.

## Ce qui change dans `OfferService.php`

Trois choses, rien d'autre (contrôles, nom du fichier et `Output` inchangés) :

```diff
-use App\Api\Repositories\Utils\TCPDFModel;
+use App\Api\Repositories\Utils\OfferPdf;
```

```diff
-        // Configuration PDF avec thème gold/charcoal
-        $pdf = new TCPDFModel('p', 'mm', 'a4', true, 'UTF-8', false);
-        … (≈ 30 lignes : marges, AddPage, renderHeader … renderFooter)
+        // Le devis aux couleurs de L'Atelier by (version C2, « une colonne »)
+        $pdf = new OfferPdf($offerData, $shopData, $currencySymbol);
+        $pdf->render();
```

et les méthodes `renderHeader()`, `renderClientInfo()`, `renderOfferTitle()`,
`renderProductsTable()`, `fillProfessionalTable()`, `renderDeliveryInfo()`,
`renderComments()`, `renderFooter()` sont supprimées. Le vieux `getPDF()` en
commentaire au bas du fichier n'est pas touché.

## Les polices

Gotham n'existe qu'en OpenType CFF, que TCPDF ne lit pas : on pose les TrueType
convertis de `../polices/` et Vank (déjà TrueType). Depuis la racine de ce dépôt :

```bash
mkdir -p <panel>/…/Utils/offer-pdf-fonts
cp docs/maquettes/offre-pdf/polices/Gotham-*.ttf public/assets/ds/fonts/GC_Vank.ttf <panel>/…/Utils/offer-pdf-fonts/
```

Au premier devis, TCPDF en tire ses définitions dans le dossier temporaire du
système (`/tmp/offer-pdf-fonts/`, ~1 s une seule fois, sous verrou) puis
embarque un sous-ensemble : ~100 Ko par devis. Les deux dossiers se changent :
`OfferPdf::$fontsDir`, `OfferPdf::$fontsCacheDir`.

**Si les polices manquent**, le devis part quand même, en DejaVu (comme
aujourd'hui), et `error_log` le dit : « OfferPdf : polices de la charte
indisponibles ». La mise en page reste juste, mais plus large : l'offre
d'exemple passe sur deux pages.

## Le logo

Inchangé : `SHARED_FILES_URL/franchise-images/pdf-logo.jpg`, lu comme
aujourd'hui. C2 le pose sur fond blanc, en haut à gauche (50 mm de large).

## Les libellés

Quinze libellés nouveaux (groupe `offer`). Tant qu'ils ne sont pas dans les
traductions, `OfferPdf` prend les siens, en français, néerlandais ou anglais
selon la langue de l'appel (le français à défaut) — le devis est donc complet
dès le premier jour. Les libellés existants (`commercial_offer_no`, `delivery`,
`in_store_pickup`, `shipping_cost`, `conditions`, `total`, `tax_number_short`,
`phone_short`, `general_terms_available_upon_request`, `document_generated_on`)
viennent des traductions, comme aujourd'hui.

| Clé | fr | nl | en |
|---|---|---|---|
| `the_day` | Le jour J | De grote dag | The big day |
| `issued_on` | Émise le | Opgemaakt op | Issued on |
| `for` | Pour | Voor | For |
| `attention_of` | À l'attention de | Ter attentie van | For the attention of |
| `prepared_by` | Préparé par | Bereid door | Prepared by |
| `your_selection` | Votre sélection | Uw selectie | Your selection |
| `excl_vat` | HTVA | excl. btw | excl. VAT |
| `incl_vat` | TVAC | incl. btw | incl. VAT |
| `vat` | TVA | Btw | VAT |
| `total_to_pay` | Total à payer | Te betalen | Total due |
| `your_notes` | Vos remarques | Uw opmerkingen | Your notes |
| `agreement` | Bon pour accord | Voor akkoord | Approval |
| `agreement_date` | Date | Datum | Date |
| `agreement_signature` | Nom et signature | Naam en handtekening | Name and signature |
| `continued` | suite | vervolg | continued |

Les dates (« jeudi 12 novembre 2026 », « NOV. 2026 ») suivent la langue de
l'appel par `IntlDateFormatter` (fr_BE, nl_BE, en_GB, pl_PL) ; sans l'extension
intl, elles passent en chiffres (`12/11/2026`).

## Vérifié

`verifier.php` fait tourner **le vrai `OfferService.php` modifié** avec des
dépôts factices qui servent l'offre d'exemple, et TCPDF 6 :

```bash
TCPDF_AUTOLOAD=/chemin/vendor/autoload.php php docs/maquettes/offre-pdf/integration/verifier.php /tmp/verif
LANGUE=nl TCPDF_AUTOLOAD=… php …/verifier.php /tmp/verif-nl      # en néerlandais
SANS_POLICES=1 TCPDF_AUTOLOAD=… php …/verifier.php /tmp/verif-dv # le repli en DejaVu
```

Livraison, retrait et offre de 30 lignes sortent comme `../c2.jpg`,
`../c2-retrait.jpg` et `../c2-long-*.jpg` ; le fichier garde son nom
(`Offre_187_Studio_Marelle_SRL.pdf`). Les noms de propriétés et de méthodes
d'`OfferPdf` ont été vérifiés contre TCPDF. Une réserve : `TCPDFModel` n'a pas pu
l'être — s'il déclare une méthode du même nom (`render`, `label`, `card`,
`stack`…), PHP le dira au chargement ; il suffira de renommer.

Prérequis : PHP ≥ 7.4 (le service en demande déjà 8), mbstring ; intl conseillé.

## Encore à trancher

1. **Les frais de livraison sont-ils TVAC ?** Le « Total à payer » les ajoute
   tels quels au total TVAC.
2. **La remise en montant** (`id_discount_type` ≠ 1) porte-t-elle sur la ligne
   ou sur l'unité ? L'affichage (« −6,00 € ») est celui d'aujourd'hui.
3. **« Émise le »** prend la date de génération ; si l'offre a un `created_at`,
   le prendre.
4. **Le nom commercial du magasin** est lu dans `shop.name`, à défaut
   `representative_name`.
5. **Le panel sert-il d'autres enseignes ?** Le logo vient des fichiers de la
   franchise, mais les couleurs et les polices sont celles de L'Atelier by :
   une autre enseigne recevrait un devis rubis et beige.
