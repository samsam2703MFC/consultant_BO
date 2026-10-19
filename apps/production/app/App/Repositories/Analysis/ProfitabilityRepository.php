<?php
namespace App\Repositories\Analysis;

use App\Core\Helpers\BaseRepository;
use App\Repositories\Company\ErpCompanyOtherSettingsRepository;
use RecipeModel;
use WdtProductModel;

/**
 * Order model class
 */
class ProfitabilityRepository extends BaseRepository
{

    protected string $table = '';

    protected $allowedColumns = [

    ];


    public function getSummaryBySection()
    {
        $sql = "SELECT
                    ps.id,
                    ps.name as section_name,
                    @exchange_rate_euro:=(SELECT exchange_rate_euro FROM erp_company_other_settings LIMIT 1) as exchange_rate_euro, 
                    IFNULL(prod_emp.emp_qty, 0) as employee_qty,
                    IFNULL(prod_emp.sum_cost, 0) as employee_cost_zl,
                    IFNULL(section_income.total_earning_eur, 0) as total_income_eur,
                    (IFNULL(section_income.total_earning_eur, 0)*@exchange_rate_euro) as total_income_zl,
                    IFNULL(total_raw_price_zl, 0) as total_raw_price_zl
                FROM production_sections ps
                LEFT JOIN (SELECT COUNT(id) as emp_qty, SUM(cost) as sum_cost, id_section FROM production_employee GROUP BY id_section) prod_emp
                    ON ps.id = prod_emp.id_section
                LEFT JOIN (SELECT x.id_section,
                    SUM(x.total_price_eur) as total_earning_eur,
                    SUM(x.total_raw_price_zl) as total_raw_price_zl
                    FROM (SELECT
                    fp.id_section,
                    (COUNT(cs.id_product) * IFNULL(fpcp.price, fp.standard_price)) as total_price_eur,
                    (COUNT(cs.id_product) * fp.pre_calculated_raw_price_per_carton)  as total_raw_price_zl,           
                    cs.id_product
                    FROM palets_delivery pd
                    INNER JOIN carton_pallet_connection cpc
                    ON pd.id_palet = cpc.id_palet
                    INNER JOIN carton_stock cs
                    ON cpc.id_carton = cs.id_carton
                    INNER JOIN final_product fp
                    ON cs.id_product = fp.id
                    LEFT JOIN final_product_customer_price fpcp
                    ON pd.id_customer = fpcp.id_customer 
                        AND fpcp.id_final_product = cs.id_product
                    WHERE pd.delivery_date BETWEEN DATE_ADD(NOW(), INTERVAL -30 DAY) AND NOW()
                    GROUP BY pd.id_customer, cs.id_product) x
                    GROUP BY x.id_section) section_income
                ON ps.id = section_income.id_section
                ORDER BY ps.name";
        return $this->query($sql);
    }

    public function getByCategoryAndSectionInRange($data)
    {
        $sql = "SELECT 
                    wdp.id_product,
                    wdp.name,
                    SUM(wdp.quantity) as quantity,
                    SUM(wdp.quantity*price_carton) as earned_value_euro,
                    fp.id_recipe
                FROM document_wdt_product wdp
                INNER JOIN document_wdt wdt
                    ON wdp.id_document_wdt = wdt.id
                INNER JOIN final_product fp
                    ON wdp.id_product = fp.id
                WHERE fp.id_section = :id_section AND wdt.established_date BETWEEN :start_date AND :end_date
                GROUP BY wdp.id_product
                ORDER BY wdp.name";
        return $this->query($sql, $data);


//        $sql = "SELECT
//                x.category_name,
//                TRUNCATE(SUM(x.raw_price_zl),2) as raw_price_zl,
//                TRUNCATE(SUM(x.total_price_eur),2) as sum_total_price_eur,
//                TRUNCATE((SUM(x.total_price_eur)*exchange_rate_euro),2) as sum_total_price_zl
//
//                FROM
//                (SELECT
//                    fp.id_category,
//                    cp.name as category_name,
//                    (SELECT exchange_rate_euro FROM erp_company_other_settings LIMIT 1) as exchange_rate_euro,
//                    (COUNT(cs.id_product) * fp.pre_calculated_raw_price_per_carton) as raw_price_zl,
//                    (COUNT(cs.id_product) * IFNULL(fpcp.price, fp.standard_price)) as total_price_eur,
//                    cs.id_product
//                    FROM palets_delivery pd
//                    INNER JOIN carton_pallet_connection cpc
//                    ON pd.id_palet = cpc.id_palet
//                    INNER JOIN carton_stock cs
//                    ON cpc.id_carton = cs.id_carton
//                    INNER JOIN final_product fp
//                    ON cs.id_product = fp.id
//                    LEFT JOIN final_product_customer_price fpcp
//                    ON pd.id_customer = fpcp.id_customer
//                        AND fpcp.id_final_product = cs.id_product
//                    INNER JOIN category_product cp
//                   	ON fp.id_category = cp.id
//                    WHERE pd.delivery_date BETWEEN :start_date AND :end_date
// 					AND fp.id_section = :id_section
//                    GROUP BY pd.id_customer, cs.id_product) x
//
//                    GROUP BY x.id_category;";
//        return $this->query($sql, $data);
    }










}