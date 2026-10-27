<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

use App\Services\Workshop\Supply\InvoiceService;
use App\Services\Workshop\Supply\PriceHistoryService;
use App\Services\Workshop\Supply\SupplyService;

/** Documentation OpenAPI de l'approvisionnement : besoins en matières et emballages, stocks, commandes fournisseurs, réceptions. */
final class OpenApiSupply
{
    public static function operations(): array
    {
        $t = 'supply';
        return [
            'GET /supply/needs' => ['tag' => $t, 'id' => 'getSupplyNeeds', 'summary' => 'Potrzeby surowców i opakowań / Besoins en matières et emballages', 'response' => 'SupplyNeeds', 'errors' => [422],
                'query' => ['date' => ['type' => 'string', 'format' => 'date', 'description' => 'un jour de la première semaine de production (défaut : dans 7 jours)'], 'weeks' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4]],
                'description' => "Besoin = à produire de la feuille de la semaine ÷ pièces par carton × recette par carton (recette à plat). "
                    . "Stock = dernier inventaire (fin de journée) + réceptions − consommation de production depuis (cartons produits × recette) ; sans inventaire : depuis la première réception. "
                    . "À commander : matière « en stock » avec max — si dispo après production < min, réassort jusqu'au max ; sinon (« à la commande ») ce qui manque ; arrondi au colis fournisseur. "
                    . "Quantités dans l'unité de base de la matière (g, ml, szt…) ; unit / factor = unité affichée (kg, l…)."],
            'GET /supply/materials' => ['tag' => $t, 'id' => 'listSupplyMaterials', 'summary' => 'Surowce : tryb, stany, zużycie / Matières : mode, stocks, consommation', 'response' => 'SupplyMaterials',
                'description' => 'Toutes les matières : mode (stock / order), min., max., stock théorique, commandé non reçu, consommation moyenne par semaine (4 dernières semaines).'],
            'PATCH /supply/materials/{id:\d+}' => ['tag' => $t, 'id' => 'saveSupplyMaterial', 'summary' => 'Tryb i stany surowca / Mode et stocks d\'une matière', 'body' => 'SupplyMaterialInput', 'response' => 'SupplyMaterial', 'errors' => [400, 404, 422]],
            'POST /supply/materials/{id:\d+}/count' => ['tag' => $t, 'id' => 'countSupplyMaterial', 'summary' => 'Inwentaryzacja surowca / Inventaire d\'une matière', 'body' => 'SupplyCountInput', 'response' => 'SupplyMaterial', 'ok' => 201, 'errors' => [400, 404, 422],
                'description' => 'Stock compté en fin de journée (unité de base) ; la consommation et les réceptions comptent à partir du lendemain.'],
            'POST /supply/materials/counts' => ['tag' => $t, 'id' => 'countSupplyMaterials', 'summary' => 'Inwentaryzacja magazynu (arkusz) / Inventaire de toutes les matières', 'body' => 'SupplyCountsInput', 'response' => 'SupplyCountsResult', 'ok' => 201, 'errors' => [400, 422],
                'description' => "Toutes les quantités comptées de la feuille en un enregistrement (unité de base, même date) ; une ligne en erreur bloque l'ensemble."],
            'POST /supply/materials/limits' => ['tag' => $t, 'id' => 'suggestSupplyLimits', 'summary' => 'Min./maks. 1–2 tyg. / Min./max. 1–2 semaines', 'body' => 'SupplyLimitsInput', 'response' => 'SupplyLimitsResult', 'errors' => [400, 422],
                'description' => "Pour les matières choisies avec une consommation sur 4 semaines : mode « sur stock », min. = 1 semaine, max. = 2 semaines (arrondis aux emballages entiers)."],
            'GET /supply/deliveries' => ['tag' => $t, 'id' => 'getSupplierDeliveries', 'summary' => 'Dostawy surowców : kto, co, kiedy / Livraisons de matières : qui, quoi, quand', 'response' => 'SupplierDeliveries', 'errors' => [422],
                'query' => ['date' => ['type' => 'string', 'format' => 'date', 'description' => 'un jour de la semaine de production (défaut : aujourd\'hui)']],
                'description' => "Semaine de production (week.start_day) : commandes envoyées par jour de livraison — state expected, partial, late (date dépassée), received (au jour de la réception) ; "
                    . "late = commandes ouvertes des semaines précédentes, undated = sans date. Fournisseurs : matières (fournisseur actuel, commandes et factures des 6 derniers mois), "
                    . "jours habituels (au moins 2 livraisons et un quart des livraisons ce jour-là, réceptions et dates de factures sur 6 mois), dernière et prochaine livraison, commandes ouvertes."],
            'GET /supply/orders' => ['tag' => $t, 'id' => 'listSupplierOrders', 'summary' => 'Zamówienia do dostawców / Commandes fournisseurs', 'response' => 'SupplierOrders', 'errors' => [422],
                'query' => ['status' => ['type' => 'string', 'enum' => ['open', 'all', ...SupplyService::STATUSES], 'description' => 'défaut : open (brouillon, envoyée, reçue en partie)']]],
            'POST /supply/orders' => ['tag' => $t, 'id' => 'createSupplierOrders', 'summary' => 'Zamówienia z potrzeb (jedno na dostawcę) / Commandes depuis les besoins (une par fournisseur)', 'body' => 'SupplierOrdersInput', 'response' => 'SupplierOrdersCreated', 'ok' => 201, 'errors' => [400, 422],
                'description' => "Brouillons groupés par fournisseur courant de la matière ; livraison : premier jour habituel du fournisseur à partir de la veille de la semaine (au plus tôt demain) ; langue de la fiche fournisseur. SUPPLY_NO_SUPPLIER (422) : matière sans fournisseur."],
            'GET /supply/orders/{id:\d+}' => ['tag' => $t, 'id' => 'getSupplierOrder', 'summary' => 'Zamówienie : linie, przyjęcia, ceny / Commande : lignes, réceptions, prix', 'response' => 'SupplierOrder', 'errors' => [404]],
            'PATCH /supply/orders/{id:\d+}' => ['tag' => $t, 'id' => 'updateSupplierOrder', 'summary' => 'Zmień szkic zamówienia / Modifier une commande brouillon', 'body' => 'SupplierOrderInput', 'response' => 'SupplierOrder', 'errors' => [400, 404, 409, 422],
                'description' => 'Brouillon seulement (PO_NOT_EDITABLE, 409). save_supplier_email = enregistrer aussi l\'adresse chez le fournisseur.'],
            'PUT /supply/orders/{id:\d+}/lines' => ['tag' => $t, 'id' => 'saveSupplierOrderLines', 'summary' => 'Linie szkicu (całość) / Lignes du brouillon (remplacées)', 'body' => 'SupplierOrderLinesInput', 'response' => 'SupplierOrder', 'errors' => [400, 404, 409, 422]],
            'GET /supply/orders/{id:\d+}/preview' => ['tag' => $t, 'id' => 'previewSupplierOrder', 'summary' => 'Podgląd maila / Aperçu de l\'e-mail', 'response' => 'SupplierOrderMail', 'errors' => [404, 422]],
            'POST /supply/orders/{id:\d+}/send' => ['tag' => $t, 'id' => 'sendSupplierOrder', 'summary' => 'Wyślij mailem albo oznacz jako wysłane / Envoyer par e-mail ou marquer envoyée', 'body' => 'SupplierOrderSendInput', 'response' => 'SupplierOrder', 'errors' => [400, 404, 409, 422],
                'description' => "E-mail mis en file (modèle supplier_order, PL ou FR, CSV joint) ; manual = commande passée autrement (téléphone, magasin). PO_NO_LINES, PO_NO_EMAIL (422)."],
            'POST /supply/orders/{id:\d+}/receive' => ['tag' => $t, 'id' => 'receiveSupplierOrder', 'summary' => 'Przyjęcie dostawy / Réception', 'body' => 'SupplierOrderReceiveInput', 'response' => 'SupplierOrder', 'errors' => [400, 404, 409, 422],
                'description' => 'Quantité et prix réels par ligne (prix par défaut : prix commandé), lot et date limite ; un problème signalé crée une non-conformité fournisseur liée à la réception. Reçue en partie ou en totalité. PO_NOT_RECEIVABLE (409) : commande non envoyée, reçue ou annulée.'],
            'POST /supply/orders/{id:\d+}/cancel' => ['tag' => $t, 'id' => 'cancelSupplierOrder', 'summary' => 'Anuluj zamówienie / Annuler la commande', 'response' => 'SupplierOrder', 'errors' => [404, 409]],
            'DELETE /supply/orders/{id:\d+}' => ['tag' => $t, 'id' => 'deleteSupplierOrder', 'summary' => 'Usuń szkic / Supprimer le brouillon', 'response' => 'SupplierOrderDeleted', 'errors' => [404, 409]],
            'GET /supply/invoices' => ['tag' => $t, 'id' => 'listPurchaseInvoices', 'summary' => 'Faktury zakupowe do kontroli / Factures d\'achat à contrôler', 'response' => 'PurchaseInvoices', 'errors' => [422],
                'query' => ['status' => ['type' => 'string', 'enum' => ['all', ...InvoiceService::STATUSES], 'description' => 'défaut : open']],
                'description' => "Contrôle par facture : state = no_lines, unmapped (lignes non reliées à une matière), no_order (pas de commande liée), gaps (écarts), clean."],
            'POST /supply/invoices/ksef/search' => ['tag' => $t, 'id' => 'searchKsefPurchaseInvoices', 'summary' => 'Faktury w KSeF za okres / Factures KSeF de la période', 'body' => 'KsefSearchInput', 'response' => 'KsefSearchResult', 'errors' => [400, 422],
                'description' => "Lecture seule dans KSeF (acheteur, date d'émission, < 3 mois) ; rien n'est enregistré. KSEF_UNAVAILABLE (422) : KSeF non configuré ou indisponible."],
            'POST /supply/invoices/ksef/import' => ['tag' => $t, 'id' => 'importKsefPurchaseInvoices', 'summary' => 'Import faktur z KSeF / Import de factures KSeF', 'body' => 'KsefImportInput', 'response' => 'KsefImportResult', 'ok' => 201, 'errors' => [400, 422],
                'description' => 'XML de chaque facture (FA) lu dans KSeF, lignes et fournisseur ; correspondances mémorisées appliquées ; factures déjà importées ignorées.'],
            'POST /supply/invoices/ksef/sync' => ['tag' => $t, 'id' => 'syncKsefPurchaseInvoices', 'summary' => 'Pobierz nowe z KSeF (jedno kliknięcie) / Récupérer les nouvelles factures KSeF', 'response' => 'KsefSyncResult', 'errors' => [422],
                'description' => "Recherche depuis la dernière facture KSeF importée (− 7 jours ; sinon 30 jours, au plus 89 jours) jusqu'à aujourd'hui, import de toutes les nouvelles (50 max), traitement automatique des factures entièrement reconnues. KSEF_UNAVAILABLE (422)."],
            'POST /supply/invoices/process-ready' => ['tag' => $t, 'id' => 'processReadyPurchaseInvoices', 'summary' => 'Zatwierdź gotowe faktury / Valider les factures prêtes', 'body' => 'InvoiceProcessInput', 'response' => 'InvoiceProcessReadyResult',
                'description' => 'Toutes les factures ouvertes sans blocage ni avertissement (fournisseur connu, toutes les lignes reliées, sans écart, sans hausse de prix, totaux cohérents) : comme POST /supply/invoices/{id}/process.'],
            'POST /supply/invoices/upload' => ['tag' => $t, 'id' => 'uploadPurchaseInvoice', 'summary' => 'Faktura z pliku (XML KSeF, PDF, skan, zdjęcie) / Facture depuis un fichier', 'body' => 'InvoiceUploadInput', 'response' => 'PurchaseInvoice', 'ok' => 201, 'errors' => [400, 409, 422],
                'description' => "XML FA : lecture complète. PDF : texte (pdftotext), lignes reconnues quand quantité × prix = valeur ; le reste se complète à la main. "
                    . "Scan ou photo (JPEG, PNG, WebP, ou PDF sans texte) : texte OCR lu par le client dans ocr_text, mêmes règles, source « ocr » ; "
                    . "PDF_SCANNED (422) : PDF sans texte envoyé sans ocr_text. INVOICE_EXISTS (409) : même fournisseur et numéro. Max 5 Mo."],
            'GET /supply/invoices/{id:\d+}' => ['tag' => $t, 'id' => 'getPurchaseInvoice', 'summary' => 'Faktura : linie, przypisania, kontrola / Facture : lignes, correspondances, contrôle', 'response' => 'PurchaseInvoice', 'errors' => [404],
                'description' => "Par ligne : quantité en unité de base (quantité × facteur), prix net par unité de base, comparés à la ligne de commande (reçu, sinon commandé ; prix commandé) et au catalogue. "
                    . "Drapeaux : unmapped, not_in_order, qty_over_received, qty_over_ordered, qty_under_received, price_over, price_under (tolérances 1 % / 0,5 %). Suggestions de matières pour les lignes non reliées."],
            'GET /supply/invoices/{id:\d+}/file' => ['tag' => $t, 'id' => 'getPurchaseInvoiceFile', 'summary' => 'Plik faktury (PDF / XML) w base64 / Fichier de la facture (base64)', 'response' => 'InvoiceFile', 'errors' => [404]],
            'PATCH /supply/invoices/{id:\d+}' => ['tag' => $t, 'id' => 'updatePurchaseInvoice', 'summary' => 'Dostawca, zamówienie, numer, data / Fournisseur, commande, numéro, date', 'body' => 'PurchaseInvoiceInput', 'response' => 'PurchaseInvoice', 'errors' => [400, 404, 409, 422],
                'description' => 'Le fournisseur choisi est mémorisé pour le NIP du vendeur. INVOICE_CLOSED (409) : facture acceptée ou contestée.'],
            'PUT /supply/invoices/{id:\d+}/lines' => ['tag' => $t, 'id' => 'savePurchaseInvoiceLines', 'summary' => 'Linie faktury PDF (całość) / Lignes de la facture PDF', 'body' => 'PurchaseInvoiceLinesInput', 'response' => 'PurchaseInvoice', 'errors' => [400, 404, 409, 422],
                'description' => 'Factures PDF ou saisies seulement (INVOICE_LINES_READ_ONLY pour KSeF / XML).'],
            'PUT /supply/invoices/{id:\d+}/lines/{lineId:\d+}/map' => ['tag' => $t, 'id' => 'mapPurchaseInvoiceLine', 'summary' => 'Przypisz nazwę dostawcy do surowca / Relier le nom du fournisseur à une matière', 'body' => 'InvoiceLineMapInput', 'response' => 'PurchaseInvoice', 'errors' => [400, 404, 409, 422],
                'description' => 'factor = unités de base de la matière par unité de facture (ex. sac de 25 kg, matière en g : 25000). remember (défaut true) : mémorisé pour ce fournisseur et appliqué aux lignes identiques.'],
            'PUT /supply/invoices/{id:\d+}/map' => ['tag' => $t, 'id' => 'mapPurchaseInvoiceLines', 'summary' => 'Przypisz wszystkie linie / Relier toutes les lignes', 'body' => 'InvoiceLinesMapInput', 'response' => 'PurchaseInvoice', 'errors' => [400, 404, 409, 422],
                'description' => 'Plusieurs lignes en un appel (matière + facteur) ; facteur absent : unités, poids lu dans le nom (« WOREK 25KG » → 25 000 g) ou conditionnement de la matière. remember (défaut true) : mémorisé pour le fournisseur.'],
            'POST /supply/invoices/{id:\d+}/process' => ['tag' => $t, 'id' => 'processPurchaseInvoice', 'summary' => 'Zatwierdź fakturę (ceny, stan, akceptacja) / Valider la facture (prix, stock, acceptation)', 'body' => 'InvoiceProcessInput', 'response' => 'PurchaseInvoice', 'errors' => [400, 404, 409, 422],
                'description' => "En un clic et tout ou rien : prix vers les fiches matières (si pas encore importés), quantités en stock (si pas encore reçues : commande liée ou commande « reçue » créée), acceptation. prices / stock / accept (défaut true) choisissent les étapes. "
                    . "INVOICE_NO_LINES, INVOICE_UNMAPPED, INVOICE_NO_SUPPLIER (422) ; INVOICE_PRICE_UP_PENDING (409) : prix d'une matière au-dessus du prix de base (fiche matière, calcul des recettes) sans décision — POST …/price-decisions d'abord, force n'y change rien ; "
                    . "INVOICE_NEEDS_CONFIRMATION (409) : écarts avec la commande, écart de prix au-delà de supply.invoice_price_alert_pct ou total différent — renvoyer avec force = true. Réponse : la facture + processed."],
            'POST /supply/invoices/{id:\d+}/price-decisions' => ['tag' => $t, 'id' => 'decidePurchaseInvoicePrices', 'summary' => 'Podwyżki cen : nowa cena bazowa albo bez zmian / Hausses de prix : nouveau prix de base ou inchangé', 'body' => 'InvoicePriceDecisionInput', 'response' => 'PurchaseInvoice', 'errors' => [400, 404, 409, 422],
                'description' => "Une hausse au-dessus du prix de base (raw_material.price_per_unit, celui du calcul des recettes) au-delà de supply.invoice_price_up_pct (0 = toute hausse) n'est jamais appliquée automatiquement. "
                    . "accept : le prix de la facture deviendra le prix de base (recettes recalculées) à la validation ou à l'import des prix ; keep : le prix de base ne change pas, la facture peut être acceptée. "
                    . "raw_material_ids : matières choisies (défaut : toutes celles sans décision). Qui et quand sont gardés ; une nouvelle correspondance, un autre facteur ou un prix plus haut annulent la décision. INVOICE_NO_PRICE_UP (409) : rien à décider ; INVOICE_CLOSED (409). Réponse : la facture + decided."],
            'POST /supply/invoices/{id:\d+}/decision' => ['tag' => $t, 'id' => 'decidePurchaseInvoice', 'summary' => 'Zaakceptuj / reklamacja / Accepter / contester', 'body' => 'InvoiceDecisionInput', 'response' => 'PurchaseInvoice', 'errors' => [400, 404, 422]],
            'POST /supply/invoices/{id:\d+}/receive' => ['tag' => $t, 'id' => 'receiveFromPurchaseInvoice', 'summary' => 'Przyjęcie dostawy wg faktury / Réception d\'après la facture', 'response' => 'PurchaseInvoice', 'errors' => [404, 409, 422],
                'description' => 'Quantités et prix nets de la facture enregistrés comme réception de la commande liée (lignes reliées à ses matières). Sans commande : une commande « reçue » est créée depuis la facture (les quantités entrent dans le stock) et liée à elle. INVOICE_NO_SUPPLIER, INVOICE_NOTHING_TO_RECEIVE (422).'],
            'POST /supply/invoices/{id:\d+}/prices' => ['tag' => $t, 'id' => 'importInvoicePrices', 'summary' => 'Ostatnie ceny z faktury do surowców / Derniers prix de la facture vers les matières', 'body' => 'InvoicePricesInput', 'response' => 'PurchaseInvoice', 'errors' => [400, 404, 409, 422],
                'description' => "Prix net de la facture par unité de base (moyenne pondérée si plusieurs lignes d'une matière) → prix de la fiche matière, fournisseur de la facture comme fournisseur courant ; historique des prix et recalcul des coûts des recettes (comme la mise à jour manuelle). line_ids : lignes choisies (défaut : toutes les lignes reliées). "
                    . "INVOICE_PRICE_UP_PENDING (409) : hausse au-dessus du prix de base sans décision ; décision keep : matière laissée au prix de base (kept). Réponse : la facture + imported_prices (ancien / nouveau prix) + skipped + kept."],
            'DELETE /supply/invoices/{id:\d+}' => ['tag' => $t, 'id' => 'deletePurchaseInvoice', 'summary' => 'Usuń fakturę (otwartą) / Supprimer la facture (ouverte)', 'response' => 'SupplierOrderDeleted', 'errors' => [404, 409]],
            'GET /supply/mappings' => ['tag' => $t, 'id' => 'listInvoiceMappings', 'summary' => 'Przypisania nazw dostawców / Correspondances des noms fournisseurs', 'response' => 'InvoiceMappings'],
            'DELETE /supply/mappings/{id:\d+}' => ['tag' => $t, 'id' => 'deleteInvoiceMapping', 'summary' => 'Usuń przypisanie / Supprimer la correspondance', 'response' => 'SupplierOrderDeleted', 'errors' => [404]],
            'GET /supply/prices' => ['tag' => $t, 'id' => 'listMaterialPriceHistory', 'summary' => 'Historia cen surowców (za kg) / Historique des prix des matières (au kg)', 'response' => 'MaterialPriceOverview', 'errors' => [422],
                'query' => ['months' => ['type' => 'integer', 'enum' => PriceHistoryService::MONTHS, 'description' => 'défaut : 12']],
                'description' => "Par matière ayant un prix sur la période : dernier prix et fournisseur, variation vs le prix précédent, min. et max. avec leur fournisseur, nombre de fournisseurs. Prix en zł par unité de base (g, ml, szt) ; factor = unités de base dans l'unité affichée (kg = 1000). Sources : factures d'achat, réceptions avec prix (commandes sans facture), changements de la fiche matière."],
            'GET /supply/materials/{id:\d+}/prices' => ['tag' => $t, 'id' => 'getMaterialPriceHistory', 'summary' => 'Historia cen surowca według dostawcy / Historique des prix d\'une matière par fournisseur', 'response' => 'MaterialPriceHistory', 'errors' => [404, 422],
                'query' => ['months' => ['type' => 'integer', 'enum' => PriceHistoryService::MONTHS, 'description' => 'défaut : 24']],
                'description' => "Points (date, fournisseur, prix, quantité, facture / commande / source) pour le graphique, résumé par fournisseur (dernier, min., max., moyenne pondérée par la quantité, variation), fournisseur le moins cher sur 12 mois."],
        ];
    }

    public static function schemas(): array
    {
        $num = ['type' => 'number'];
        $nNum = ['type' => 'number', 'nullable' => true];
        $nStr = ['type' => 'string', 'nullable' => true];
        $date = ['type' => 'string', 'format' => 'date'];
        $ready = ['type' => 'object', 'nullable' => true, 'description' => 'factures ouvertes : ok = prête pour « Valider la facture » et l\'automatique', 'properties' => [
            'ok' => ['type' => 'boolean'], 'blockers' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['no_lines', 'unmapped', 'no_supplier', 'price_up']]],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['gaps', 'price_jump', 'total_mismatch']]], 'unmapped' => ['type' => 'integer'],
            'price_ups' => ['type' => 'integer', 'description' => 'matières au-dessus du prix de base sans décision (bloquant : price_up)'],
        ]];
        $auto = ['type' => 'object', 'description' => 'paramètres supply.invoice_auto_prices / _full, supply.invoice_price_alert_pct et supply.invoice_price_up_pct', 'properties' => [
            'prices' => ['type' => 'boolean'], 'full' => ['type' => 'boolean'], 'price_alert_pct' => $num, 'price_up_pct' => $num]];
        $nDate = ['type' => 'string', 'format' => 'date', 'nullable' => true];
        $supplier = ['type' => 'object', 'nullable' => true, 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'email' => $nStr]];
        $material = [
            'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'category' => $nStr, 'packaging' => ['type' => 'boolean'],
            'base_unit' => ['type' => 'string', 'example' => 'g'], 'unit' => ['type' => 'string', 'example' => 'kg'], 'factor' => ['type' => 'number', 'example' => 1000],
            'supplier' => $supplier, 'mode' => ['type' => 'string', 'enum' => SupplyService::MODES], 'stock_min' => $nNum, 'stock_max' => $nNum,
            'stock' => $nNum, 'stock_basis' => ['type' => 'string', 'enum' => ['count', 'receipts'], 'nullable' => true], 'count_date' => $nDate,
            'price' => ['type' => 'number', 'nullable' => true, 'description' => 'prix catalogue par unité de base'], 'package_size' => $nNum, 'on_order' => $num,
        ];
        $summary = [
            'id' => ['type' => 'integer'], 'code' => ['type' => 'string', 'example' => 'ZD-20261022-001'], 'status' => ['type' => 'string', 'enum' => SupplyService::STATUSES],
            'supplier' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]],
            'week_start' => $nDate, 'delivery_date' => $nDate, 'language' => ['type' => 'string', 'enum' => ['pl', 'fr']], 'email_to' => $nStr,
            'sent_at' => $nStr, 'sent_by' => ['type' => 'string', 'enum' => ['email', 'manual'], 'nullable' => true], 'created_at' => ['type' => 'string'],
            'lines_count' => ['type' => 'integer'], 'value_ordered' => $num, 'value_received' => $num, 'value_received_expected' => $num, 'received_pct' => $num,
            'price_gaps' => ['type' => 'integer', 'description' => 'réceptions à un prix différent du prix commandé'],
        ];
        $invoice = [
            'id' => ['type' => 'integer'], 'source' => ['type' => 'string', 'enum' => ['ksef', 'xml', 'pdf', 'manual', 'ocr']], 'ksef_number' => $nStr, 'number' => $nStr, 'issue_date' => $nDate,
            'seller_nip' => $nStr, 'seller_name' => $nStr, 'supplier' => ['type' => 'object', 'nullable' => true, 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]],
            'currency' => ['type' => 'string'], 'total_net' => $nNum, 'total_gross' => $nNum,
            'order' => ['type' => 'object', 'nullable' => true, 'properties' => ['id' => ['type' => 'integer'], 'code' => ['type' => 'string'], 'status' => ['type' => 'string']]],
            'status' => ['type' => 'string', 'enum' => InvoiceService::STATUSES], 'file' => ['type' => 'object', 'nullable' => true, 'properties' => ['filename' => ['type' => 'string'], 'mime' => ['type' => 'string']]],
            'created_at' => ['type' => 'string'],
        ];
        $control = ['type' => 'object', 'properties' => [
            'state' => ['type' => 'string', 'enum' => ['no_lines', 'unmapped', 'no_order', 'gaps', 'clean']], 'lines' => ['type' => 'integer'], 'mapped' => ['type' => 'integer'],
            'issues' => ['type' => 'integer'], 'value_gap' => $num, 'lines_net' => $num, 'total_mismatch' => ['type' => 'boolean'],
            'price_jumps' => ['type' => 'integer', 'description' => 'lignes dont le prix au kg s\'écarte de la fiche matière de plus de supply.invoice_price_alert_pct (drapeau price_jump ; pas pour une hausse déjà décidée)'],
            'price_ups' => ['type' => 'integer', 'description' => 'matières au-dessus du prix de base sans décision (lignes : drapeau price_up)'],
            'price_ups_decided' => ['type' => 'integer', 'description' => 'hausses décidées (drapeaux price_up_accept, price_up_keep)'],
        ]];
        $lines = ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['raw_material_id', 'quantity'], 'properties' => [
            'raw_material_id' => ['type' => 'integer'], 'quantity' => ['type' => 'number', 'description' => 'unité de base, > 0'], 'unit_price' => ['type' => 'number', 'nullable' => true, 'description' => 'défaut : prix catalogue'],
        ]]];
        return [
            'SupplyNeeds' => ['type' => 'object', 'properties' => [
                'date' => $date, 'weeks' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['week_start' => $date, 'week_end' => $date, 'iso_week' => ['type' => 'integer']]]],
                'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $material + [
                    'need' => $num, 'need_weeks' => ['type' => 'array', 'items' => $num], 'products' => ['type' => 'integer'], 'available_after' => $num,
                    'to_order' => $num, 'packages' => ['type' => 'integer', 'nullable' => true], 'value' => $nNum,
                ]]],
                'totals' => ['type' => 'object', 'additionalProperties' => true], 'warnings' => ['type' => 'object', 'additionalProperties' => true], 'can_write' => ['type' => 'boolean'],
            ]],
            'SupplyMaterial' => ['type' => 'object', 'properties' => $material],
            'SupplyMaterials' => ['type' => 'object', 'properties' => [
                'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $material + ['weekly_use' => $num]]], 'use_weeks' => ['type' => 'integer'], 'can_write' => ['type' => 'boolean'],
            ]],
            'SupplyMaterialInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'supply_mode' => ['type' => 'string', 'enum' => SupplyService::MODES], 'stock_min' => $nNum, 'stock_max' => $nNum,
            ]],
            'SupplyCountInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['quantity'], 'properties' => [
                'quantity' => ['type' => 'number', 'minimum' => 0], 'count_date' => ['type' => 'string', 'format' => 'date', 'description' => 'défaut : aujourd\'hui'], 'note' => $nStr,
            ]],
            'SupplyCountsInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['counts'], 'properties' => [
                'count_date' => ['type' => 'string', 'format' => 'date', 'description' => 'défaut : aujourd\'hui'], 'note' => $nStr,
                'counts' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object', 'required' => ['id', 'quantity'], 'properties' => ['id' => ['type' => 'integer'], 'quantity' => ['type' => 'number', 'minimum' => 0]]]],
            ]],
            'SupplyCountsResult' => ['allOf' => [['$ref' => '#/components/schemas/SupplyMaterials'], ['type' => 'object', 'properties' => ['saved' => ['type' => 'integer'], 'count_date' => ['type' => 'string', 'format' => 'date']]]]],
            'SupplyLimitsInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['ids'], 'properties' => ['ids' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'integer']]]],
            'SupplyLimitsResult' => ['allOf' => [['$ref' => '#/components/schemas/SupplyMaterials'], ['type' => 'object', 'properties' => ['updated' => ['type' => 'integer'], 'skipped' => ['type' => 'integer']]]]],
            'SupplierDeliveries' => ['type' => 'object', 'properties' => [
                'week_start' => $date, 'week_end' => $date, 'iso_week' => ['type' => 'integer'], 'today' => $date, 'history_days' => ['type' => 'integer'],
                'days' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['date' => $date, 'weekday' => ['type' => 'integer', 'description' => '1 = lundi … 7 = dimanche'],
                    'today' => ['type' => 'boolean'], 'deliveries' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SupplierDelivery']]]]],
                'late' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SupplierDelivery']],
                'undated' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SupplierDelivery']],
                'totals' => ['type' => 'object', 'properties' => ['deliveries' => ['type' => 'integer'], 'suppliers' => ['type' => 'integer'], 'received' => ['type' => 'integer'], 'late' => ['type' => 'integer'], 'undated' => ['type' => 'integer']]],
                'suppliers' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'phone' => $nStr, 'email' => $nStr, 'active' => ['type' => 'boolean'],
                    'materials' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'packaging' => ['type' => 'boolean'],
                        'current' => ['type' => 'boolean', 'description' => 'fournisseur actuel de la matière']]]],
                    'weekdays' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['day' => ['type' => 'integer'], 'deliveries' => ['type' => 'integer']]]],
                    'usual_days' => ['type' => 'array', 'items' => ['type' => 'integer']], 'deliveries' => ['type' => 'integer', 'description' => 'jours de livraison sur history_days'],
                    'last_delivery' => $nDate, 'next_delivery' => $nDate, 'open_orders' => ['type' => 'integer'], 'in_week' => $nDate,
                ]]],
                'can_order' => ['type' => 'boolean'],
            ]],
            'SupplierDelivery' => ['type' => 'object', 'properties' => [
                'order_id' => ['type' => 'integer'], 'code' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['sent', 'partial', 'received']],
                'state' => ['type' => 'string', 'enum' => ['expected', 'partial', 'late', 'received']], 'date' => $nDate, 'planned_date' => $nDate, 'received_on' => $nDate,
                'supplier' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'phone' => $nStr]],
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['material_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'packaging' => ['type' => 'boolean'],
                    'quantity' => $num, 'received' => $num, 'unit' => ['type' => 'string'], 'factor' => $num]]],
            ]],
            'SupplierOrders' => ['type' => 'object', 'properties' => ['orders' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $summary]], 'can_write' => ['type' => 'boolean']]],
            'SupplierOrdersCreated' => ['type' => 'object', 'properties' => ['orders' => ['type' => 'array', 'items' => ['allOf' => [['$ref' => '#/components/schemas/SupplierOrder'],
                ['type' => 'object', 'properties' => ['usual_days' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                    'description' => 'jours de livraison habituels du fournisseur (1 = lundi) : fiche fournisseur, sinon historique des livraisons']]]]]]]],
            'SupplierOrdersInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['lines'], 'properties' => ['week_start' => $nDate, 'lines' => $lines]],
            'SupplierOrder' => ['type' => 'object', 'properties' => $summary + [
                'supplier_email' => $nStr, 'supplier_phone' => $nStr, 'note' => $nStr, 'can_edit' => ['type' => 'boolean'], 'can_receive' => ['type' => 'boolean'],
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'raw_material_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'base_unit' => ['type' => 'string'], 'unit' => ['type' => 'string'], 'factor' => $num,
                    'quantity' => $num, 'package_size' => $nNum, 'packages' => $nNum, 'unit_price' => $nNum, 'catalog_price' => $nNum, 'value' => $nNum,
                    'received_qty' => $num, 'remaining' => $num, 'received_value' => $nNum, 'last_price' => $nNum, 'last_day' => $nDate,
                    'price_gap_pct' => ['type' => 'number', 'nullable' => true, 'description' => 'dernier prix reçu vs prix commandé (%)'], 'qty_gap_pct' => $nNum,
                ]]],
                'receipts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['line_id' => ['type' => 'integer'], 'raw_material_id' => ['type' => 'integer'], 'received_on' => $date, 'quantity' => $num, 'unit_price' => $nNum,
                    'lot_number' => $nStr, 'best_before' => $nDate, 'nc_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'non-conformité créée à la réception'],
                    'employee' => ['type' => 'string', 'nullable' => true, 'description' => 'salarié qui a réceptionné sur la tablette']]]],
            ]],
            'SupplierOrderInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'delivery_date' => $nDate, 'language' => ['type' => 'string', 'enum' => ['pl', 'fr']], 'email_to' => $nStr, 'note' => $nStr, 'save_supplier_email' => ['type' => 'boolean'],
            ]],
            'SupplierOrderLinesInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['lines'], 'properties' => ['lines' => $lines]],
            'SupplierOrderMail' => ['type' => 'object', 'properties' => ['recipient' => $nStr, 'language' => ['type' => 'string'], 'subject' => ['type' => 'string'], 'body' => ['type' => 'string'], 'template_id' => ['type' => 'integer', 'nullable' => true]]],
            'SupplierOrderSendInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['manual' => ['type' => 'boolean', 'description' => 'commande passée hors e-mail']]],
            'SupplierOrderReceiveInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['lines'], 'properties' => [
                'received_on' => ['type' => 'string', 'format' => 'date', 'description' => 'défaut : aujourd\'hui'],
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id', 'quantity'], 'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'ligne de la commande'], 'quantity' => ['type' => 'number', 'minimum' => 0], 'unit_price' => ['type' => 'number', 'nullable' => true],
                    'lot_number' => ['type' => 'string', 'maxLength' => 64, 'nullable' => true, 'description' => 'lot du fournisseur (enregistré en majuscules)'], 'best_before' => $nDate,
                ]]],
                'problems' => ['type' => 'array', 'description' => 'une non-conformité fournisseur (source supplier_delivery) par ligne signalée', 'items' => ['type' => 'object', 'required' => ['line_id', 'description'], 'properties' => [
                    'line_id' => ['type' => 'integer'], 'description' => ['type' => 'string', 'maxLength' => 5000], 'severity' => ['type' => 'string', 'enum' => ['minor', 'major', 'critical']],
                    'quantity' => ['type' => 'number', 'nullable' => true, 'description' => 'quantité concernée, unité de base'],
                ]]],
            ]],
            'SupplierOrderDeleted' => ['type' => 'object', 'properties' => ['deleted' => ['type' => 'boolean'], 'id' => ['type' => 'integer']]],
            'InvoiceFile' => ['type' => 'object', 'properties' => ['filename' => ['type' => 'string'], 'mime' => ['type' => 'string'], 'size' => ['type' => 'integer'], 'content_base64' => ['type' => 'string']]],
            'PurchaseInvoices' => ['type' => 'object', 'properties' => ['invoices' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $invoice + ['control' => $control, 'ready' => $ready]]], 'can_write' => ['type' => 'boolean'], 'auto' => $auto]],
            'PurchaseInvoice' => ['type' => 'object', 'properties' => $invoice + [
                'note' => $nStr, 'decided_at' => $nStr, 'control' => $control, 'text' => ['type' => 'string', 'nullable' => true, 'description' => 'texte lu dans le PDF'],
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'line_no' => ['type' => 'integer'], 'name' => ['type' => 'string', 'description' => 'nom chez le fournisseur'], 'code' => $nStr,
                    'quantity' => $num, 'unit' => $nStr, 'unit_price' => $nNum, 'net_value' => $nNum, 'vat_rate' => $nStr, 'factor' => $nNum,
                    'material' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true], 'control' => ['type' => 'object', 'additionalProperties' => true],
                    'suggestions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['raw_material_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'score' => $num, 'base_unit' => ['type' => 'string'], 'factor' => $num]]],
                ]]],
                'orders' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]], 'suppliers' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                'can_write' => ['type' => 'boolean'], 'editable' => ['type' => 'boolean'], 'lines_editable' => ['type' => 'boolean'], 'can_receive' => ['type' => 'boolean'],
                'ready' => $ready, 'auto' => $auto,
                'price_refs' => ['type' => 'object', 'description' => 'par matière (id) : prix précédent (hors cette facture) et fournisseur le moins cher sur 12 mois', 'additionalProperties' => ['type' => 'object', 'properties' => [
                    'previous' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true], 'best' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true], 'suppliers' => ['type' => 'integer'],
                ]]],
                'price_ups' => ['type' => 'array', 'description' => 'matières dont le prix de la facture dépasse le prix de base (calcul des recettes)', 'items' => ['type' => 'object', 'properties' => [
                    'raw_material_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'unit' => ['type' => 'string', 'example' => 'kg'], 'factor' => $num,
                    'base_price' => ['type' => 'number', 'description' => 'prix de base actuel, par unité de base'], 'invoice_price' => ['type' => 'number', 'description' => 'prix de la facture par unité de base (moyenne pondérée)'],
                    'new_price' => ['type' => 'number', 'description' => 'prix de base après acceptation (prix du colis arrondi ÷ colis)'], 'change_pct' => $num, 'quantity' => $num,
                    'extra' => ['type' => 'number', 'description' => 'surcoût sur cette facture : quantité × (prix facture − prix de base)'], 'recipes' => ['type' => 'integer', 'description' => 'recettes qui utilisent la matière'],
                    'decision' => ['type' => 'string', 'enum' => InvoiceService::PRICE_DECISIONS, 'nullable' => true], 'decided_by' => $nStr, 'decided_at' => $nStr,
                ]]],
                'processed' => ['type' => 'object', 'description' => 'POST …/process seulement', 'properties' => ['prices' => ['type' => 'integer'], 'prices_skipped' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'prices_kept' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'matières laissées au prix de base (décision keep)'], 'stock' => ['type' => 'boolean'], 'accepted' => ['type' => 'boolean']]],
                'decided' => ['type' => 'integer', 'description' => 'POST …/price-decisions seulement'],
                'auto_processed' => ['type' => 'object', 'nullable' => true, 'description' => 'upload : traitement automatique effectué (facture entièrement reconnue)', 'additionalProperties' => true],
            ]],
            'KsefSearchInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['from', 'to'], 'properties' => ['from' => $date, 'to' => $date]],
            'KsefSearchResult' => ['type' => 'object', 'properties' => ['invoices' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'ksef_number' => ['type' => 'string'], 'number' => $nStr, 'issue_date' => $nDate, 'seller_nip' => $nStr, 'seller_name' => $nStr, 'net' => $nNum, 'gross' => $nNum, 'currency' => $nStr, 'imported' => ['type' => 'boolean'],
            ]]]]],
            'KsefImportInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['ksef_numbers'], 'properties' => ['ksef_numbers' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string']]]],
            'KsefImportResult' => ['type' => 'object', 'properties' => ['imported' => ['type' => 'array', 'items' => ['type' => 'integer']], 'skipped' => ['type' => 'integer'],
                'errors' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['ksef_number' => ['type' => 'string'], 'message' => ['type' => 'string']]]]]],
            'InvoiceUploadInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['filename', 'content_base64'], 'properties' => [
                'filename' => ['type' => 'string'], 'content_base64' => ['type' => 'string', 'format' => 'byte', 'description' => 'PDF, XML FA ou image (JPEG, PNG, WebP), 5 Mo max'],
                'ocr_text' => ['type' => 'string', 'nullable' => true, 'maxLength' => 200000, 'description' => 'texte lu par OCR (scan, photo) ; vide = image enregistrée, lignes à saisir'],
            ]],
            'PurchaseInvoiceInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'supplier_id' => ['type' => 'integer', 'nullable' => true], 'po_id' => ['type' => 'integer', 'nullable' => true], 'number' => $nStr, 'issue_date' => $nDate, 'note' => $nStr,
            ]],
            'PurchaseInvoiceLinesInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['lines'], 'properties' => ['lines' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['name', 'quantity'], 'properties' => [
                'name' => ['type' => 'string'], 'code' => $nStr, 'quantity' => $num, 'unit' => $nStr, 'unit_price' => $nNum, 'net_value' => $nNum, 'vat_rate' => $nStr,
            ]]]]],
            'InvoiceLineMapInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['raw_material_id'], 'properties' => [
                'raw_material_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'null = délier'], 'factor' => ['type' => 'number', 'nullable' => true, 'description' => 'unités de base par unité de facture ; absent : déduit des unités (kg → g = 1000, sac / carton = conditionnement fournisseur)'],
                'remember' => ['type' => 'boolean', 'description' => 'défaut true'],
            ]],
            'InvoicePricesInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['line_ids' => ['type' => 'array', 'items' => ['type' => 'integer']]]],
            'InvoicePriceDecisionInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['decision'], 'properties' => [
                'decision' => ['type' => 'string', 'enum' => InvoiceService::PRICE_DECISIONS, 'description' => 'accept : nouveau prix de base ; keep : prix de base inchangé'],
                'raw_material_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'défaut : toutes les hausses sans décision'],
            ]],
            'InvoiceLinesMapInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['lines'], 'properties' => [
                'lines' => ['type' => 'array', 'maxItems' => 300, 'items' => ['type' => 'object', 'required' => ['line_id', 'raw_material_id'], 'properties' => [
                    'line_id' => ['type' => 'integer'], 'raw_material_id' => ['type' => 'integer'], 'factor' => ['type' => 'number', 'nullable' => true, 'description' => 'unités de base par unité de facture ; absent : déduit'],
                ]]], 'remember' => ['type' => 'boolean', 'description' => 'défaut true'],
            ]],
            'InvoiceProcessInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'prices' => ['type' => 'boolean', 'description' => 'prix vers les fiches matières (défaut true)'], 'stock' => ['type' => 'boolean', 'description' => 'quantités en stock (défaut true)'],
                'accept' => ['type' => 'boolean', 'description' => 'accepter la facture (défaut true)'], 'force' => ['type' => 'boolean', 'description' => 'valider malgré les avertissements (facture seule)'],
            ]],
            'InvoiceProcessReadyResult' => ['type' => 'object', 'properties' => [
                'processed' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                'errors' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'number' => $nStr, 'code' => ['type' => 'string']]]],
                'left' => ['type' => 'integer', 'description' => 'factures ouvertes restantes (à relier ou à confirmer)'],
            ]],
            'KsefSyncResult' => ['type' => 'object', 'properties' => [
                'from' => $date, 'to' => $date, 'found' => ['type' => 'integer'], 'more' => ['type' => 'integer', 'description' => 'nouvelles factures au-delà de 50 (cliquer encore)'],
                'imported' => ['type' => 'array', 'items' => ['type' => 'integer']], 'skipped' => ['type' => 'integer'],
                'errors' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]], 'auto' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            ]],
            'MaterialPriceOverview' => ['type' => 'object', 'properties' => ['months' => ['type' => 'integer'], 'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'raw_material_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'base_unit' => ['type' => 'string'], 'unit' => ['type' => 'string'], 'factor' => $num, 'packaging' => ['type' => 'boolean'],
                'catalog_price' => $nNum, 'current_supplier' => $nStr, 'last' => ['type' => 'object', 'additionalProperties' => true], 'previous' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true],
                'change_pct' => $nNum, 'min' => ['type' => 'object', 'additionalProperties' => true], 'max' => ['type' => 'object', 'additionalProperties' => true], 'suppliers' => ['type' => 'integer'], 'points' => ['type' => 'integer'],
            ]]]]],
            'MaterialPriceHistory' => ['type' => 'object', 'properties' => [
                'material' => ['type' => 'object', 'additionalProperties' => true], 'months' => ['type' => 'integer'],
                'suppliers' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]], 'best' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true],
                'points' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'kind' => ['type' => 'string', 'enum' => ['invoice', 'receipt', 'catalog']], 'day' => $date, 'supplier_id' => ['type' => 'integer', 'nullable' => true], 'supplier' => $nStr,
                    'price' => $num, 'qty' => $nNum, 'ref' => $nStr, 'invoice_id' => ['type' => 'integer', 'nullable' => true],
                    'user' => ['type' => 'string', 'nullable' => true, 'description' => 'qui a changé la fiche (points catalog)'],
                ]]],
            ]],
            'InvoiceDecisionInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['status'], 'properties' => ['status' => ['type' => 'string', 'enum' => InvoiceService::STATUSES], 'note' => $nStr]],
            'InvoiceMappings' => ['type' => 'object', 'properties' => ['mappings' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'supplier_key' => ['type' => 'string'], 'supplier' => $nStr, 'supplier_name' => ['type' => 'string'], 'supplier_unit' => $nStr,
                'raw_material_id' => ['type' => 'integer'], 'material' => $nStr, 'base_unit' => ['type' => 'string'], 'factor' => $num, 'updated_at' => ['type' => 'string'],
            ]]], 'can_write' => ['type' => 'boolean']]],
        ];
    }
}
