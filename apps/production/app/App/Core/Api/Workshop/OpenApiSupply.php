<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

use App\Services\Workshop\Supply\InvoiceService;
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
            'GET /supply/orders' => ['tag' => $t, 'id' => 'listSupplierOrders', 'summary' => 'Zamówienia do dostawców / Commandes fournisseurs', 'response' => 'SupplierOrders', 'errors' => [422],
                'query' => ['status' => ['type' => 'string', 'enum' => ['open', 'all', ...SupplyService::STATUSES], 'description' => 'défaut : open (brouillon, envoyée, reçue en partie)']]],
            'POST /supply/orders' => ['tag' => $t, 'id' => 'createSupplierOrders', 'summary' => 'Zamówienia z potrzeb (jedno na dostawcę) / Commandes depuis les besoins (une par fournisseur)', 'body' => 'SupplierOrdersInput', 'response' => 'SupplierOrdersCreated', 'ok' => 201, 'errors' => [400, 422],
                'description' => "Brouillons groupés par fournisseur courant de la matière ; livraison souhaitée la veille de la semaine (au plus tôt demain). SUPPLY_NO_SUPPLIER (422) : matière sans fournisseur."],
            'GET /supply/orders/{id:\d+}' => ['tag' => $t, 'id' => 'getSupplierOrder', 'summary' => 'Zamówienie : linie, przyjęcia, ceny / Commande : lignes, réceptions, prix', 'response' => 'SupplierOrder', 'errors' => [404]],
            'PATCH /supply/orders/{id:\d+}' => ['tag' => $t, 'id' => 'updateSupplierOrder', 'summary' => 'Zmień szkic zamówienia / Modifier une commande brouillon', 'body' => 'SupplierOrderInput', 'response' => 'SupplierOrder', 'errors' => [400, 404, 409, 422],
                'description' => 'Brouillon seulement (PO_NOT_EDITABLE, 409). save_supplier_email = enregistrer aussi l\'adresse chez le fournisseur.'],
            'PUT /supply/orders/{id:\d+}/lines' => ['tag' => $t, 'id' => 'saveSupplierOrderLines', 'summary' => 'Linie szkicu (całość) / Lignes du brouillon (remplacées)', 'body' => 'SupplierOrderLinesInput', 'response' => 'SupplierOrder', 'errors' => [400, 404, 409, 422]],
            'GET /supply/orders/{id:\d+}/preview' => ['tag' => $t, 'id' => 'previewSupplierOrder', 'summary' => 'Podgląd maila / Aperçu de l\'e-mail', 'response' => 'SupplierOrderMail', 'errors' => [404, 422]],
            'POST /supply/orders/{id:\d+}/send' => ['tag' => $t, 'id' => 'sendSupplierOrder', 'summary' => 'Wyślij mailem albo oznacz jako wysłane / Envoyer par e-mail ou marquer envoyée', 'body' => 'SupplierOrderSendInput', 'response' => 'SupplierOrder', 'errors' => [400, 404, 409, 422],
                'description' => "E-mail mis en file (modèle supplier_order, PL ou FR, CSV joint) ; manual = commande passée autrement (téléphone, magasin). PO_NO_LINES, PO_NO_EMAIL (422)."],
            'POST /supply/orders/{id:\d+}/receive' => ['tag' => $t, 'id' => 'receiveSupplierOrder', 'summary' => 'Przyjęcie dostawy / Réception', 'body' => 'SupplierOrderReceiveInput', 'response' => 'SupplierOrder', 'errors' => [400, 404, 409, 422],
                'description' => 'Quantité et prix réels par ligne (prix par défaut : prix commandé) ; reçue en partie ou en totalité. PO_NOT_RECEIVABLE (409) : commande non envoyée, reçue ou annulée.'],
            'POST /supply/orders/{id:\d+}/cancel' => ['tag' => $t, 'id' => 'cancelSupplierOrder', 'summary' => 'Anuluj zamówienie / Annuler la commande', 'response' => 'SupplierOrder', 'errors' => [404, 409]],
            'DELETE /supply/orders/{id:\d+}' => ['tag' => $t, 'id' => 'deleteSupplierOrder', 'summary' => 'Usuń szkic / Supprimer le brouillon', 'response' => 'SupplierOrderDeleted', 'errors' => [404, 409]],
            'GET /supply/invoices' => ['tag' => $t, 'id' => 'listPurchaseInvoices', 'summary' => 'Faktury zakupowe do kontroli / Factures d\'achat à contrôler', 'response' => 'PurchaseInvoices', 'errors' => [422],
                'query' => ['status' => ['type' => 'string', 'enum' => ['all', ...InvoiceService::STATUSES], 'description' => 'défaut : open']],
                'description' => "Contrôle par facture : state = no_lines, unmapped (lignes non reliées à une matière), no_order (pas de commande liée), gaps (écarts), clean."],
            'POST /supply/invoices/ksef/search' => ['tag' => $t, 'id' => 'searchKsefPurchaseInvoices', 'summary' => 'Faktury w KSeF za okres / Factures KSeF de la période', 'body' => 'KsefSearchInput', 'response' => 'KsefSearchResult', 'errors' => [400, 422],
                'description' => "Lecture seule dans KSeF (acheteur, date d'émission, < 3 mois) ; rien n'est enregistré. KSEF_UNAVAILABLE (422) : KSeF non configuré ou indisponible."],
            'POST /supply/invoices/ksef/import' => ['tag' => $t, 'id' => 'importKsefPurchaseInvoices', 'summary' => 'Import faktur z KSeF / Import de factures KSeF', 'body' => 'KsefImportInput', 'response' => 'KsefImportResult', 'ok' => 201, 'errors' => [400, 422],
                'description' => 'XML de chaque facture (FA) lu dans KSeF, lignes et fournisseur ; correspondances mémorisées appliquées ; factures déjà importées ignorées.'],
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
            'POST /supply/invoices/{id:\d+}/decision' => ['tag' => $t, 'id' => 'decidePurchaseInvoice', 'summary' => 'Zaakceptuj / reklamacja / Accepter / contester', 'body' => 'InvoiceDecisionInput', 'response' => 'PurchaseInvoice', 'errors' => [400, 404, 422]],
            'POST /supply/invoices/{id:\d+}/receive' => ['tag' => $t, 'id' => 'receiveFromPurchaseInvoice', 'summary' => 'Przyjęcie dostawy wg faktury / Réception d\'après la facture', 'response' => 'PurchaseInvoice', 'errors' => [404, 409, 422],
                'description' => 'Quantités et prix nets de la facture enregistrés comme réception de la commande liée (lignes reliées à ses matières). Sans commande : une commande « reçue » est créée depuis la facture (les quantités entrent dans le stock) et liée à elle. INVOICE_NO_SUPPLIER, INVOICE_NOTHING_TO_RECEIVE (422).'],
            'POST /supply/invoices/{id:\d+}/prices' => ['tag' => $t, 'id' => 'importInvoicePrices', 'summary' => 'Ostatnie ceny z faktury do surowców / Derniers prix de la facture vers les matières', 'body' => 'InvoicePricesInput', 'response' => 'PurchaseInvoice', 'errors' => [400, 404, 409, 422],
                'description' => "Prix net de la facture par unité de base (moyenne pondérée si plusieurs lignes d'une matière) → prix de la fiche matière, fournisseur de la facture comme fournisseur courant ; historique des prix et recalcul des coûts des recettes (comme la mise à jour manuelle). line_ids : lignes choisies (défaut : toutes les lignes reliées). Réponse : la facture + imported_prices (ancien / nouveau prix) + skipped."],
            'DELETE /supply/invoices/{id:\d+}' => ['tag' => $t, 'id' => 'deletePurchaseInvoice', 'summary' => 'Usuń fakturę (otwartą) / Supprimer la facture (ouverte)', 'response' => 'SupplierOrderDeleted', 'errors' => [404, 409]],
            'GET /supply/mappings' => ['tag' => $t, 'id' => 'listInvoiceMappings', 'summary' => 'Przypisania nazw dostawców / Correspondances des noms fournisseurs', 'response' => 'InvoiceMappings'],
            'DELETE /supply/mappings/{id:\d+}' => ['tag' => $t, 'id' => 'deleteInvoiceMapping', 'summary' => 'Usuń przypisanie / Supprimer la correspondance', 'response' => 'SupplierOrderDeleted', 'errors' => [404]],
        ];
    }

    public static function schemas(): array
    {
        $num = ['type' => 'number'];
        $nNum = ['type' => 'number', 'nullable' => true];
        $nStr = ['type' => 'string', 'nullable' => true];
        $date = ['type' => 'string', 'format' => 'date'];
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
            'SupplierOrders' => ['type' => 'object', 'properties' => ['orders' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $summary]], 'can_write' => ['type' => 'boolean']]],
            'SupplierOrdersCreated' => ['type' => 'object', 'properties' => ['orders' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SupplierOrder']]]],
            'SupplierOrdersInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['lines'], 'properties' => ['week_start' => $nDate, 'lines' => $lines]],
            'SupplierOrder' => ['type' => 'object', 'properties' => $summary + [
                'supplier_email' => $nStr, 'supplier_phone' => $nStr, 'note' => $nStr, 'can_edit' => ['type' => 'boolean'], 'can_receive' => ['type' => 'boolean'],
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'raw_material_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'base_unit' => ['type' => 'string'], 'unit' => ['type' => 'string'], 'factor' => $num,
                    'quantity' => $num, 'package_size' => $nNum, 'packages' => $nNum, 'unit_price' => $nNum, 'catalog_price' => $nNum, 'value' => $nNum,
                    'received_qty' => $num, 'remaining' => $num, 'received_value' => $nNum, 'last_price' => $nNum, 'last_day' => $nDate,
                    'price_gap_pct' => ['type' => 'number', 'nullable' => true, 'description' => 'dernier prix reçu vs prix commandé (%)'], 'qty_gap_pct' => $nNum,
                ]]],
                'receipts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['line_id' => ['type' => 'integer'], 'raw_material_id' => ['type' => 'integer'], 'received_on' => $date, 'quantity' => $num, 'unit_price' => $nNum]]],
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
                ]]],
            ]],
            'SupplierOrderDeleted' => ['type' => 'object', 'properties' => ['deleted' => ['type' => 'boolean'], 'id' => ['type' => 'integer']]],
            'InvoiceFile' => ['type' => 'object', 'properties' => ['filename' => ['type' => 'string'], 'mime' => ['type' => 'string'], 'size' => ['type' => 'integer'], 'content_base64' => ['type' => 'string']]],
            'PurchaseInvoices' => ['type' => 'object', 'properties' => ['invoices' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $invoice + ['control' => $control]]], 'can_write' => ['type' => 'boolean']]],
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
            'InvoiceDecisionInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['status'], 'properties' => ['status' => ['type' => 'string', 'enum' => InvoiceService::STATUSES], 'note' => $nStr]],
            'InvoiceMappings' => ['type' => 'object', 'properties' => ['mappings' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'supplier_key' => ['type' => 'string'], 'supplier' => $nStr, 'supplier_name' => ['type' => 'string'], 'supplier_unit' => $nStr,
                'raw_material_id' => ['type' => 'integer'], 'material' => $nStr, 'base_unit' => ['type' => 'string'], 'factor' => $num, 'updated_at' => ['type' => 'string'],
            ]]], 'can_write' => ['type' => 'boolean']]],
        ];
    }
}
