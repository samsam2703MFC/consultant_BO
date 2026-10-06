<?php
namespace App\Employee\app\Models\Task;

use JsonSerializable;

class TaskModel implements JsonSerializable {

    private $id;
    private $subcategory_id;
    private $base_name;
    private $name;
    private $base_description;
    private $description;
    private $day_of_week;
    private $execution_time;
    private $frequency;
    private $is_mandatory;
    private $priority;
    private $requires_photo;
    private $base_subcategory_name;
    private $subcategory_name;
    private $category_id;
    private $base_category_name;
    private $category_name;
    private $section_id;
    private $base_section_name;
    private $section_name;
    private $is_done;
    private $is_uploaded_photo;
    private $completion_id;


    public function __construct(array $data) {
        $this->id = $data['id'] ?? null;
        $this->subcategory_id = $data['subcategory_id'] ?? null;
        $this->base_name = $data['base_name'] ?? null;
        $this->name = $data['name'] ?? null;
        $this->base_description = $data['base_description'] ?? null;
        $this->description = $data['description'] ?? null;
        $this->day_of_week = $data['day_of_week'] ?? null;
        $this->execution_time = $data['execution_time'] ?? null;
        $this->frequency = $data['frequency'] ?? null;
        $this->is_mandatory = $data['is_mandatory'] ?? null;
        $this->priority = $data['priority'] ?? null;
        $this->requires_photo = $data['requires_photo'] ?? null;
        $this->base_subcategory_name = $data['base_subcategory_name'] ?? null;
        $this->subcategory_name = $data['subcategory_name'] ?? null;
        $this->category_id = $data['category_id'] ?? null;
        $this->base_category_name = $data['base_category_name'] ?? null;
        $this->category_name = $data['category_name'] ?? null;
        $this->section_id = $data['section_id'] ?? null;
        $this->base_section_name = $data['base_section_name'] ?? null;
        $this->section_name = $data['section_name'] ?? null;
        $this->is_done = $data['is_done'] ?? false;
        $this->is_uploaded_photo = $data['is_uploaded_photo'] ?? false;
        $this->completion_id = $data['completion_id'] ?? false;

    }

    //getetyrs
    public function getId() {
        return $this->id;
    }
    public function getSubcategoryId() {
        return $this->subcategory_id;
    }
    public function getBaseName() {
        return $this->base_name;
    }
    public function getName() {
        return $this->name;
    }
    public function getBaseDescription() {
        return $this->base_description;
    }
    public function getDescription() {
        return $this->description;
    }

    public function getDayOfWeek()
    {
        return $this->day_of_week;
    }
    public function getExecutionTime()
    {
        return $this->execution_time;
    }
    public function getFrequency()
    {
        return $this->frequency;
    }
    public function getIsMandatory()
    {
        return $this->is_mandatory;
    }
    public function getPriority()
    {
        return $this->priority;
    }
    public function getRequiresPhoto()
    {
        return $this->requires_photo;
    }

    public function getBaseCategoryName()
    {
        return $this->base_category_name;
    }
    public function getCategoryName()
    {
        return $this->category_name;
    }
    public function getBaseSubcategoryName()
    {
        return $this->base_subcategory_name;
    }
    public function getSubcategoryName()
    {
        return $this->subcategory_name;
    }
    public function getCategoryId()
    {
        return $this->category_id;
    }
    public function getSectionId()
    {
        return $this->section_id;
    }
    public function getBaseSectionName()
    {
        return $this->base_section_name;
    }
    public function getSectionName()
    {
        return $this->section_name;
    }
    public function isDone()
    {
        return $this->is_done;
    }
    public function isUploadedPhoto()
    {
        return $this->is_uploaded_photo;
    }
    public function getCompletionId()
    {
        return $this->completion_id;
    }


    public function jsonSerialize(): mixed {
        return [
            'id' => $this->id,
            'subcategory_id' => $this->subcategory_id,
            'base_name' => $this->base_name,
            'name' => $this->name,
            'base_description' => $this->base_description,
            'description' => $this->description,
            'day_of_week' => $this->day_of_week,
            'execution_time' => $this->execution_time,
            'frequency' => $this->frequency,
            'is_mandatory' => $this->is_mandatory,
            'priority' => $this->priority,
            'requires_photo' => $this->requires_photo,
            'base_subcategory_name' => $this->base_subcategory_name,
            'subcategory_name' => $this->subcategory_name,
            'category_id' => $this->category_id,
            'base_category_name' => $this->base_category_name,
            'category_name' => $this->category_name,
            'section_id' => $this->section_id,
            'base_section_name' => $this->base_section_name,
            'section_name' => $this->section_name,
            'is_done' => $this->is_done,
            'is_uploaded_photo' => $this->is_uploaded_photo,
            'completion_id' => $this->completion_id,
        ];
    }
}