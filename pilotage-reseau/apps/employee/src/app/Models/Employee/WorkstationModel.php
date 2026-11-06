<?php
namespace App\Employee\app\Models\Employee;

/**
 * Model dla użytkowników systemu franczyzowego
 */
class WorkstationModel implements \JsonSerializable{
    private $id;
    private $id_brand;
    private $name;
    private $description;

    public function __construct($data) {
        $this->id = $data['id'] ?? null;
        $this->id_brand = $data['id_brand'] ?? null;
        $this->name = $data['name'] ?? null;
        $this->description = $data['description'] ?? null;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'id_brand' => $this->id_brand,
            'name' => $this->name,
            'description' => $this->description
        ];
    }

    public function getId() {
        return $this->id;
    }

    public function getIdBrand() {
        return $this->id_brand;
    }

    public function getName() {
        return $this->name;
    }

    public function getDescription() {
        return $this->description;
    }

    public static function fromArray(array $data) : self
    {
        return new self([
            'id' => $data['id'] ?? null,
            'id_brand' => $data['id_brand'] ?? null,
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null
        ]);
    }
}