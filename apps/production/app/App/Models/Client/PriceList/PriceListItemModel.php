<?php
namespace App\Models\Client\PriceList;

class PriceListItemModel implements \JsonSerializable
{
    private $id_product;
    private $sku;
    private $product_name;
    private $price_net;
    private $vat_rate;
    private $package_size;
    private $package_unit;
    private $is_active;
    private $valid_from;

    public function __construct(array $data)
    {
        $this->id_product = $data['id_product'];
        $this->sku = $data['sku'];
        $this->product_name = $data['product_name'];
        $this->price_net = $data['price_net'];
        $this->vat_rate = $data['vat_rate'];
        $this->package_size = $data['package_size'];
        $this->package_unit = $data['package_unit'];
        $this->is_active = $data['is_active'];
        $this->valid_from = $data['valid_from'];
    }

    public function getPriceNet()
    {
        return $this->price_net;
    }
    public function getVatRate()
    {
        return $this->vat_rate;
    }
    public function getPackageSize()
    {
        return $this->package_size;
    }
    public function getPackageUnit()
    {
        return $this->package_unit;
    }
    public function getIsActive()
    {
        return $this->is_active;
    }
    public function getValidFrom()
    {
        return $this->valid_from;
    }
    public function getIdProduct()
    {
        return $this->id_product;
    }
    public function getSku()
    {
        return $this->sku;
    }
    public function getProductName()
    {
        return $this->product_name;
    }


    public function jsonSerialize() : mixed
    {
        return get_object_vars($this);
    }
}