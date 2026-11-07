<?php
namespace App\Employee\app\Models\Knowledge;


use App\User\Models\OperationalFramework\Role\AdminRoleModel;
use App\User\Models\OperationalFramework\Role\RoleModel;
use JsonSerializable;

class TrainingModel implements JsonSerializable {

    private $id;
    private $name;
    private $base_name;
    private $description;
    private $base_description;
    private $subcategory_id;
    private $subcategory_name;
    private $base_subcategory_name;
    private $category_name;
    private $base_category_name;
    public function __construct(array $data) {
        $this->id = $data['id'];
        $this->name = $data['name'];
        $this->base_name = $data['base_name'];
        $this->description = $data['description'] ?? null;
        $this->base_description = $data['base_description'] ?? null;
        $this->subcategory_id = $data['subcategory_id'] ?? null;
        $this->subcategory_name = $data['subcategory_name'] ?? null;
        $this->base_subcategory_name = $data['base_subcategory_name'] ?? null;
        $this->category_name = $data['category_name'] ?? null;
        $this->base_category_name = $data['base_category_name'] ?? null;

    }

    public function getId() {
        return $this->id;
    }
    public function getBaseName() {
        return $this->base_name;
    }
    public function getName() {
        return $this->name;
    }
    public function getDescription() {
        return $this->description;
    }
    public function getBaseDescription() {
        return $this->base_description;
    }
    public function getSubcategoryId() {
        return $this->subcategory_id;
    }

    public function getSubcategoryName() {
        return $this->subcategory_name;
    }

    public function getBaseSubcategoryName() {
        return $this->base_subcategory_name;
    }

    public function getCategoryName() {
        return $this->category_name;
    }

    public function getBaseCategoryName() {
        return $this->base_category_name;
    }

    public function jsonSerialize(): mixed {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'base_name' => $this->getBaseName(),
            'description' => $this->getDescription(),
            'base_description' => $this->getBaseDescription(),
            'subcategory_id' => $this->getSubcategoryId(),
            'subcategory_name' => $this->getSubcategoryName(),
            'base_subcategory_name' => $this->getBaseSubcategoryName(),
            'category_name' => $this->getCategoryName(),
            'base_category_name' => $this->getBaseCategoryName()
        ];
    }
}