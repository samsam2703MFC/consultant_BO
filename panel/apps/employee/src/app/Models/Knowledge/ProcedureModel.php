<?php
namespace App\Employee\app\Models\Knowledge;


class ProcedureModel implements \JsonSerializable
{
    private $id;
    private $base_category_name;
    private $base_subcategory_name;
    private $category_name;
    private $subcategory_name;
    private $base_title;
    private $title;
    private $base_description;
    private $description;
    private $category_id;
    private $subcategory_id;
    private $section_id;
    private $created_at;
    private $updated_at;

    /** @var ProcedureSectionModel[] */
    private $sections = [];


    public function __construct($data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->base_category_name = $data['base_category_name'] ?? null;
        $this->category_name = $data['category_name'] ?? null;
        $this->base_subcategory_name = $data['base_subcategory_name'] ?? null;
        $this->subcategory_name = $data['subcategory_name'] ?? null;
        $this->base_title = $data['base_title'] ?? null;
        $this->title = $data['title'] ?? null;
        $this->base_description = $data['base_description'] ?? null;
        $this->description = $data['description'] ?? null;
        $this->subcategory_id = $data['subcategory_id'] ?? null;
        $this->category_id = $data['category_id'] ?? null;
        $this->section_id = $data['section_id'] ?? null;
        $this->created_at = $data['created_at'] ?? null;
        $this->updated_at = $data['updated_at'] ?? null;

        if(isset($data['sections'])){
            foreach ($data['sections'] as $stage){
                $this->sections[] = new ProcedureSectionModel($stage);
            }
        }
    }

    public function jsonSerialize(): mixed
    {
        return [
            'id' => $this->id,
            'base_category_name' => $this->base_category_name,
            'category_name' => $this->category_name,
            'base_title' => $this->base_title,
            'title' => $this->title,
            'base_description' => $this->base_description,
            'description' => $this->description,
            'subcategory_id' => $this->subcategory_id,
            'category_id' => $this->category_id,
            'section_id' => $this->section_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'sections' => $this->sections,
            'base_subcategory_name' => $this->base_subcategory_name,
            'subcategory_name' => $this->subcategory_name,
        ];
    }

    public function getId()
    {
        return $this->id;
    }

    public function getBaseCategoryName()
    {
        return $this->base_category_name;
    }

    public function getBaseSubcategoryName()
    {
        return $this->base_subcategory_name;
    }

    public function getSubcategoryName()
    {
        return $this->subcategory_name;
    }

    public function getCategoryName()
    {
        return $this->category_name;
    }

    public function getBaseTitle()
    {
        return $this->base_title;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function getBaseDescription()
    {
        return $this->base_description;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getCategoryId()
    {
        return $this->category_id;
    }

    public function getSubcategoryId()
    {
        return $this->subcategory_id;
    }

    public function getSectionId()
    {
        return $this->section_id;
    }

    public function getCreatedAt()
    {
        return $this->created_at;
    }

    public function getUpdatedAt()
    {
        return $this->updated_at;
    }

    public function getSections()
    {
        return $this->sections;
    }
}
