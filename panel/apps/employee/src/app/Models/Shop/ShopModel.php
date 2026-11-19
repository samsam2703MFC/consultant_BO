<?php
namespace App\Employee\app\Models\Shop;


class ShopModel implements \JsonSerializable
{
    private $id;
    private $representative_name;
    private $name;
    private $email;
    private $street;
    private $street_num;
    private $city;
    private $zip;
    private $phone;
    private $opening_hour;
    private $opening_minute;
    private $closing_hour;
    private $closing_minute;
    private $tax_number_prefixe;
    private $tax_number;
    private $bank_account_number;
    private $bank_name;
    private $bank_swift;
    private $bank_iban;
    private $country_code;

    public function __construct($data) {
        $this->id = $data['id'] ?? null;
        $this->representative_name = $data['representative_name'] ?? null;
        $this->name = $data['name'] ?? null;
        $this->email = $data['email'] ?? null;
        $this->street = $data['street'] ?? null;
        $this->street_num = $data['street_num'] ?? null;
        $this->city = $data['city'] ?? null;
        $this->zip = $data['zip'] ?? null;
        $this->phone = $data['phone'] ?? null;
        $this->opening_hour = $data['opening_hour'] ?? null;
        $this->opening_minute = $data['opening_minute'] ?? null;
        $this->closing_hour = $data['closing_hour'] ?? null;
        $this->closing_minute = $data['closing_minute'] ?? null;
        $this->tax_number_prefixe = $data['tax_number_prefixe'] ?? null;
        $this->tax_number = $data['tax_number'] ?? null;
        $this->bank_account_number = $data['bank_account_number'] ?? null;
        $this->bank_name = $data['bank_name'] ?? null;
        $this->bank_swift = $data['bank_swift'] ?? null;
        $this->bank_iban = $data['bank_iban'] ?? null;
        $this->country_code = $data['country_code'] ?? null;
    }

    public function jsonSerialize(): mixed {
        return [
            'id' => $this->id,
            'representative_name' => $this->representative_name,
            'name' => $this->name,
            'email' => $this->email,
            'street' => $this->street,
            'street_num' => $this->street_num,
            'city' => $this->city,
            'zip' => $this->zip,
            'phone' => $this->phone,
            'opening_hour' => $this->opening_hour,
            'opening_minute' => $this->opening_minute,
            'closing_hour' => $this->closing_hour,
            'closing_minute' => $this->closing_minute,
            'tax_number_prefixe' => $this->tax_number_prefixe,
            'tax_number' => $this->tax_number,
            'bank_account_number' => $this->bank_account_number,
            'bank_name' => $this->bank_name,
            'bank_swift' => $this->bank_swift,
            'bank_iban' => $this->bank_iban,
            'country_code' => $this->country_code
        ];
    }


    public function getId() {
        return $this->id;
    }

    public function getRepresentativeName() {
        return $this->representative_name;
    }

    public function getName() {
        return $this->name;
    }

    public function getEmail() {
        return $this->email;
    }

    public function getStreet() {
        return $this->street;
    }

    public function getStreetNum() {
        return $this->street_num;
    }

    public function getCity() {
        return $this->city;
    }

    public function getZip() {
        return $this->zip;
    }

    public function getPhone() {
        return $this->phone;
    }

    public function getOpeningHour() {
        return $this->opening_hour;
    }

    public function getOpeningMinute() {
        return $this->opening_minute;
    }

    public function getClosingHour() {
        return $this->closing_hour;
    }

    public function getClosingMinute() {
        return $this->closing_minute;
    }

    public function getTaxNumberPrefixe() {
        return $this->tax_number_prefixe;
    }

    public function getTaxNumber() {
        return $this->tax_number;
    }

    public function getBankAccountNumber() {
        return $this->bank_account_number;
    }

    public function getBankName() {
        return $this->bank_name;
    }

    public function getBankSwift() {
        return $this->bank_swift;
    }

    public function getBankIban() {
        return $this->bank_iban;
    }

    public function getPeppolIdentifiers() {
        return $this->peppol_identifiers;
    }

    public function getOpeningHours() {
        $hour = intval($this->getOpeningHour());
        $minute = intval($this->getOpeningMinute());

        return sprintf("%02d:%02d", $hour, $minute);
    }

    public function getClosingHours() {
        $hour = intval($this->getClosingHour());
        $minute = intval($this->getClosingMinute());

        return sprintf("%02d:%02d", $hour, $minute);
    }

    public function getCountryCode()
    {
        return $this->country_code;
    }
}