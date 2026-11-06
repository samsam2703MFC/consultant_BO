<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

use App\Services\Workshop\Supply\InvoiceService;

/**
 * OpenAPI : rodzaj faktur zakupowych (surowce / usługi / odrzucone), reguły dostawców, Finanse — « Faktury i koszty »
 * i koszty usług miesiąca (zamknięcie → kalkulator kosztów).
 */
final class OpenApiFinance
{
    public static function operations(): array
    {
        $s = 'supply';
        $t = 'finance';
        $month = "Mois du coût AAAA-MM (pas dans le futur). SERVICE_MONTH_CLOSED (409) : mois des services clôturé — le rouvrir d'abord.";
        return [
            'POST /supply/invoices/{id:\d+}/classify' => ['tag' => $s, 'id' => 'classifyPurchaseInvoice', 'summary' => 'Usługa → Finanse / odrzuć / z powrotem do zaopatrzenia / Service → Finances, rejeter, retour aux achats',
                'body' => 'InvoiceClassifyInput', 'response' => 'PurchaseInvoice', 'errors' => [400, 404, 409, 422],
                'description' => "service : coût de l'entreprise (catégorie du calculateur de coûts : fixed:…, media:…, log:… ; service_month défaut = mois d'émission), la facture quitte Zaopatrzenie ; "
                    . "reject : ne concerne pas l'entreprise (note) ; goods : retour aux matières (correspondances mémorisées réappliquées). Lignes d'un service ou d'une facture rejetée sans matière : ni prix, ni stock. "
                    . "rule = true : « toujours pour ce fournisseur » (NIP) — appliquée tout de suite aux autres factures ouvertes de ce NIP (applied) et à l'import KSeF suivant ; goods + rule supprime la règle. "
                    . "INVOICE_PROCESSED (409) : prix importés, commande liée ou décision — la facture reste aux achats. " . $month],
            'GET /supply/supplier-rules' => ['tag' => $s, 'id' => 'listSupplierRules', 'summary' => 'Reguły dostawców (NIP → usługa / odrzucona) / Règles fournisseurs', 'response' => 'SupplierRules'],
            'DELETE /supply/supplier-rules/{id:\d+}' => ['tag' => $s, 'id' => 'deleteSupplierRule', 'summary' => 'Usuń regułę / Supprimer la règle', 'response' => 'SupplierOrderDeleted', 'errors' => [404],
                'description' => 'Les factures suivantes de ce fournisseur reviennent aux achats ; celles déjà classées restent.'],
            'GET /finance/workspace' => ['tag' => $t, 'id' => 'getFinanceWorkspace', 'summary' => 'Faktury i koszty : przypisz, zatwierdź, sprawdź wzrosty / Factures et coûts : relier, valider, hausses',
                'response' => 'FinanceWorkspace',
                'description' => "Poste « Administracja i fakturowanie ». 1. lignes sans matière (suggestion) et factures qui ressemblent à un service ; 2. factures prêtes (un clic), avec avertissements, bloquées ; "
                    . "3. hausses au-dessus du prix de base sans décision avec effet par mois (achats des 90 derniers jours ÷ 3 × hausse), petites hausses sous finance.small_price_up_pct. Les actions sont celles des factures d'achat."],
            'GET /finance/services' => ['tag' => $t, 'id' => 'getServiceMonth', 'summary' => 'Koszty usług miesiąca / Coûts de services du mois', 'response' => 'ServiceMonth', 'errors' => [422],
                'query' => ['month' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}$', 'description' => 'défaut : mois précédent']],
                'description' => "Factures de services du mois par catégorie : total, mois précédent, écart ; fournisseur présent le mois précédent sans facture = missing (ajouter le coût ou « pas ce mois-ci ») ; "
                    . "calc : montant actuel du calculateur de coûts (lignes manuelles) ; blockers (open, missing, no_amount, not_over, cost_month_locked) ; puces des 6 derniers mois."],
            'POST /finance/services' => ['tag' => $t, 'id' => 'createServiceCost', 'summary' => 'Koszt bez faktury KSeF / Coût sans facture KSeF', 'body' => 'ServiceCostInput', 'response' => 'ServiceInvoice', 'ok' => 201, 'errors' => [400, 409, 422],
                'description' => "Fournisseur étranger, ticket, PDF : montant net en PLN, catégorie, mois (défaut : mois de la date), fichier facultatif (PDF / JPEG / PNG ≤ 5 Mo). Saisi par les Finances : validé tout de suite. INVOICE_EXISTS (409) : même NIP et numéro. " . $month],
            'PUT /finance/services/marks' => ['tag' => $t, 'id' => 'markServiceMonth', 'summary' => '« Nie w tym miesiącu » / « Pas ce mois-ci »', 'body' => 'ServiceMarkInput', 'response' => 'ServiceMonth', 'errors' => [400, 409, 422],
                'description' => 'none = true : coût attendu (catégorie + fournisseur du mois précédent) sans facture ce mois-ci ; false : annulé. ' . $month],
            'PATCH /finance/services/{id:\d+}' => ['tag' => $t, 'id' => 'updateServiceInvoice', 'summary' => 'Kategoria, miesiąc, kwota PLN / Catégorie, mois, montant PLN', 'body' => 'ServiceInvoiceInput', 'response' => 'ServiceInvoice', 'errors' => [400, 404, 409, 422],
                'description' => "cost_amount : montant net en PLN compté dans les coûts (null = total net de la facture si en PLN ; obligatoire pour une autre devise). Une facture validée dont la catégorie, le mois ou le montant change revient à vérifier. " . $month],
            'POST /finance/services/{id:\d+}/decision' => ['tag' => $t, 'id' => 'decideServiceInvoice', 'summary' => 'Zatwierdź / do sprawdzenia / Valider / à vérifier', 'body' => 'ServiceDecisionInput', 'response' => 'ServiceInvoice', 'errors' => [400, 404, 409, 422],
                'description' => 'Validée : catégorie, mois et montant PLN requis. ' . $month],
            'POST /finance/services/{id:\d+}/classify' => ['tag' => $t, 'id' => 'classifyServiceInvoice', 'summary' => 'Z powrotem do zaopatrzenia / odrzuć / Retour aux achats, rejeter', 'body' => 'InvoiceClassifyInput', 'response' => 'PurchaseInvoice', 'errors' => [400, 404, 409, 422],
                'description' => 'Même action que POST /supply/invoices/{id}/classify, avec la permission des Finances.'],
            'GET /finance/services/{id:\d+}/file' => ['tag' => $t, 'id' => 'getServiceInvoiceFile', 'summary' => 'Plik faktury / Fichier de la facture', 'response' => 'InvoiceFile', 'errors' => [404]],
            'DELETE /finance/services/{id:\d+}' => ['tag' => $t, 'id' => 'deleteServiceCost', 'summary' => 'Usuń koszt wpisany ręcznie / Supprimer un coût saisi', 'response' => 'SupplierOrderDeleted', 'errors' => [404, 409],
                'description' => 'Seulement les coûts saisis à la main (SERVICE_NOT_MANUAL, 409) ; une facture KSeF se rejette. ' . $month],
            'POST /finance/months/{ym:\d{4}-\d{2}}/accept-all' => ['tag' => $t, 'id' => 'acceptServiceMonth', 'summary' => 'Zatwierdź wszystkie faktury miesiąca / Valider toutes les factures du mois', 'response' => 'ServiceMonth', 'errors' => [409, 422],
                'description' => 'Factures à vérifier avec catégorie et montant PLN ; les autres restent (left). ' . $month],
            'POST /finance/months/{ym:\d{4}-\d{2}}/close' => ['tag' => $t, 'id' => 'closeServiceMonth', 'summary' => 'Zamknij miesiąc → kalkulator kosztów / Clôturer → calculateur de coûts', 'response' => 'ServiceMonth', 'errors' => [409, 422],
                'description' => "Mois terminé (SERVICE_MONTH_NOT_OVER, 409), au moins une facture ou « pas ce mois-ci » (SERVICE_MONTH_EMPTY, 409), tout validé, rien de manquant (SERVICE_MONTH_INCOMPLETE, 409). Totaux écrits dans le calculateur de coûts (mois créé s'il manque) : coûts fixes (une ligne par fournisseur), médias (montant du média), logistique (une ligne par type) ; "
                    . "les lignes manuelles de ces catégories sont remplacées et gardées pour la réouverture. COST_MONTH_LOCKED (409) : mois du calculateur fermé. Réponse : le mois + pushed."],
            'POST /finance/months/{ym:\d{4}-\d{2}}/reopen' => ['tag' => $t, 'id' => 'reopenServiceMonth', 'summary' => 'Otwórz miesiąc usług / Rouvrir le mois des services', 'response' => 'ServiceMonth', 'errors' => [409, 422],
                'description' => 'Lignes écrites depuis les factures retirées du calculateur, lignes manuelles rétablies. SERVICE_MONTH_OPEN (409) : pas clôturé ; COST_MONTH_LOCKED (409).'],
        ];
    }

    public static function schemas(): array
    {
        $num = ['type' => 'number'];
        $nNum = ['type' => 'number', 'nullable' => true];
        $nStr = ['type' => 'string', 'nullable' => true];
        $month = ['type' => 'string', 'pattern' => '^\d{4}-(0[1-9]|1[0-2])$', 'example' => '2026-09'];
        $category = ['type' => 'string', 'maxLength' => 40, 'example' => 'log:FUEL', 'description' => 'fixed:<catégorie de coût fixe> | media:<code média> | log:<code logistique> (liste : categories)'];
        $free = ['type' => 'object', 'additionalProperties' => true];
        $service = ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'], 'source' => ['type' => 'string'], 'number' => $nStr, 'issue_date' => $nStr, 'seller_name' => $nStr, 'seller_nip' => $nStr, 'seller_key' => ['type' => 'string'],
            'currency' => ['type' => 'string'], 'total_net' => $nNum, 'total_gross' => $nNum, 'cost_amount' => ['type' => 'number', 'nullable' => true, 'description' => 'montant net PLN compté (null : à saisir, autre devise)'],
            'cost_amount_set' => ['type' => 'boolean'], 'cost_category' => $nStr, 'service_month' => $nStr, 'status' => ['type' => 'string', 'enum' => ['open', 'accepted']],
            'by_rule' => ['type' => 'boolean'], 'note' => $nStr, 'decided_by' => $nStr, 'decided_at' => $nStr, 'file' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true], 'ksef_number' => $nStr]];
        return [
            'InvoiceClassifyInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['action'], 'properties' => [
                'action' => ['type' => 'string', 'enum' => InvoiceService::CLASSIFY], 'cost_category' => $category + ['nullable' => true], 'service_month' => $month + ['nullable' => true],
                'note' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true], 'rule' => ['type' => 'boolean', 'description' => '« toujours pour ce fournisseur » (NIP)']]],
            'SupplierRules' => ['type' => 'object', 'properties' => ['rules' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'seller_nip' => ['type' => 'string'], 'seller_name' => $nStr, 'action' => ['type' => 'string', 'enum' => ['service', 'reject']], 'cost_category' => $nStr, 'category' => $nStr,
                'invoices' => ['type' => 'integer'], 'last_invoice' => $nStr, 'created_by' => $nStr, 'created_at' => ['type' => 'string']]]], 'can_write' => ['type' => 'boolean']]],
            'FinanceWorkspace' => ['type' => 'object', 'properties' => [
                'map' => ['type' => 'object', 'properties' => ['invoices' => ['type' => 'array', 'items' => $free], 'invoices_total' => ['type' => 'integer'], 'lines_total' => ['type' => 'integer'], 'suspects' => ['type' => 'array', 'items' => $free]]],
                'validate' => ['type' => 'object', 'properties' => ['ready' => ['type' => 'array', 'items' => $free], 'ready_net' => $num, 'warnings' => ['type' => 'array', 'items' => $free], 'blocked' => ['type' => 'integer'], 'services_open' => ['type' => 'integer']]],
                'price_ups' => ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true, 'properties' => [
                    'raw_material_id' => ['type' => 'integer'], 'invoice_id' => ['type' => 'integer'], 'change_pct' => $num, 'base_price' => $num, 'new_price' => $num, 'extra' => $num,
                    'monthly_quantity' => $num, 'monthly_extra' => ['type' => 'number', 'description' => 'zł par mois (achats des 90 derniers jours)'], 'small' => ['type' => 'boolean'], 'recipes' => ['type' => 'integer']]]],
                    'small_pct' => $num, 'small_count' => ['type' => 'integer'], 'monthly_total' => $num, 'days' => ['type' => 'integer']]],
                'service_month' => $free, 'kpi' => $free, 'counts' => $free, 'auto' => $free, 'can_write' => ['type' => 'boolean'], 'can_finance' => ['type' => 'boolean']]],
            'ServiceInvoice' => $service,
            'ServiceMonth' => ['type' => 'object', 'properties' => [
                'month' => ['type' => 'string'], 'label' => ['type' => 'string'], 'prev_month' => ['type' => 'string'], 'next_month' => $nStr,
                'closed' => ['type' => 'object', 'nullable' => true, 'properties' => ['at' => ['type' => 'string'], 'by' => $nStr, 'total' => $num]],
                'cost_month' => ['type' => 'object', 'nullable' => true, 'properties' => ['id' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => ['OPEN', 'LOCKED']]]],
                'categories' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'code' => $nStr, 'label' => ['type' => 'string'], 'group' => $nStr, 'group_label' => $nStr, 'invoices' => ['type' => 'array', 'items' => $service],
                    'count' => ['type' => 'integer'], 'open' => ['type' => 'integer'], 'total' => $num, 'prev_total' => $num, 'diff_pct' => $nNum,
                    'missing' => ['type' => 'array', 'items' => $free], 'marked' => ['type' => 'array', 'items' => $free], 'state' => ['type' => 'string', 'enum' => ['open', 'missing', 'done', 'none', 'empty']],
                    'calc' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true], 'pushed' => $nNum]]],
                'progress' => $free, 'totals' => $free, 'can_close' => ['type' => 'boolean'],
                'blockers' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['empty', 'open', 'missing', 'no_amount', 'not_over', 'cost_month_locked']]],
                'months' => ['type' => 'array', 'items' => $free], 'category_list' => ['type' => 'array', 'items' => $free], 'without_month' => ['type' => 'integer'],
                'pushed' => ['type' => 'array', 'items' => $free], 'accepted' => ['type' => 'integer'], 'left' => ['type' => 'integer'], 'can_write' => ['type' => 'boolean']]],
            'ServiceCostInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['seller_name', 'issue_date', 'cost_category', 'total_net'], 'properties' => [
                'seller_name' => ['type' => 'string', 'maxLength' => 255], 'seller_nip' => ['type' => 'string', 'nullable' => true, 'example' => 'BE0123456789'], 'number' => $nStr,
                'issue_date' => ['type' => 'string', 'format' => 'date'], 'cost_category' => $category, 'service_month' => $month + ['nullable' => true],
                'total_net' => ['type' => 'number', 'exclusiveMinimum' => true, 'minimum' => 0, 'description' => 'PLN'], 'total_gross' => $nNum, 'note' => $nStr,
                'filename' => $nStr, 'content_base64' => ['type' => 'string', 'nullable' => true, 'description' => 'PDF, JPEG ou PNG ≤ 5 Mo']]],
            'ServiceMarkInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['month', 'cost_category', 'seller_key', 'none'], 'properties' => [
                'month' => $month, 'cost_category' => $category, 'seller_key' => ['type' => 'string', 'example' => 'nip:5260215088'], 'none' => ['type' => 'boolean']]],
            'ServiceInvoiceInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'cost_category' => $category, 'service_month' => $month, 'cost_amount' => ['type' => 'number', 'nullable' => true, 'minimum' => 0], 'note' => $nStr]],
            'ServiceDecisionInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['status'], 'properties' => ['status' => ['type' => 'string', 'enum' => ['accepted', 'open']]]],
        ];
    }
}
