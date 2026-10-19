<?php

namespace App\Repositories\Client;
use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class ClientRepository extends BaseRepository
{


    protected string $table = 'customer';

    protected $allowedColumns = [
        'name',
        'surname',
        'fac_street',
        'fac_street_num',
        'fac_zip',
        'fac_town',
        'fac_country',
        'id_country',
        'company',
        'tax_number',
        'active',
        'email_head',
        'email_salesman',
        'email_accountant',
        'phone_head',
        'phone_alt',
        'payment_termin',
        'franchisee_code',
        'franchisee_uid',
        'franchise_network_id',
        'credentials',
        'default_currency',
    ];

    public function getStandard()
    {
        $sql = "SELECT * FROM `customer` WHERE active = 1 AND franchise_network_id IS NULL ORDER BY company";
        return $this->query($sql);
    }

    public function getFranchisee()
    {
        $sql = "SELECT * FROM `customer` WHERE active = 1 AND franchise_network_id IS NOT NULL ORDER BY company";
        return $this->query($sql);
    }

    public function getActive()
    {
        $sql = "SELECT * FROM `customer` WHERE active = 1 ORDER BY company";
        return $this->query($sql);
    }

    public function getInactive()
    {
        $sql = "SELECT * FROM `customer` WHERE active = 0 ORDER BY company";
        return $this->query($sql);
    }

    public function getById($id)
    {
        $sql = "SELECT 
                    cs.id as id_customer,
                    cs.name as customer_name,
                    cs.surname as customer_surname,
                    cs.company,
                    cs.fac_street,
                    cs.fac_street_num,
                    cs.fac_zip,
                    cs.fac_town,
                    cs.tax_number
                FROM customer cs
                WHERE cs.id = :id_customer";
        return $this->query($sql, ['id_customer' => $id])[0];
    }

    public function getByFranchiseNetwork($id)
    {
        $sql = "SELECT * FROM customer WHERE franchise_network_id = :id_franchise_network";
        return $this->query($sql, ['id_franchise_network' => $id]);
    }

    public function updatePricelistVersionCAS($idClient, $currentVersion, $version)
    {
       $sql = "UPDATE customer
               SET pricelist_version = :new
               WHERE id = :id_client AND pricelist_version = :old";
       return $this->query($sql, ['id_client' => $idClient, 'old' => $currentVersion, 'new' => $version]);
    }
}