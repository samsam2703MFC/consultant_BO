<?php
namespace App\Employee\app\Models\Knowledge;


class CompetencyModel implements \JsonSerializable
{
    private $id;
    private $subcategory_id;
    private $subcategory_name;
    private $base_subcategory_name;
    private $category_id;
    private $category_name;
    private $base_category_name;
    private $section_id;
    private $name;
    private $base_name;
    private $description;
    private $base_description;
    private $created_at;
    private $verification_method;

    private $operational_procedures = [];

    private $trainings = [];

    private $knowledge_resources = [];


    public function __construct($data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->subcategory_id = $data['subcategory_id'] ?? null;
        $this->name = $data['name'] ?? null;
        $this->description = $data['description'] ?? null;
        $this->created_at = $data['created_at'] ?? null;
        $this->category_name = $data['category_name'] ?? null;
        $this->category_id = $data['category_id'] ?? null;
        $this->subcategory_name = $data['subcategory_name'] ?? null;
        $this->section_id = $data['section_id'] ?? null;
        $this->verification_method = $data['verification_method'] ?? null;
        $this->base_subcategory_name = $data['base_subcategory_name'] ?? null;
        $this->base_category_name = $data['base_category_name'] ?? null;
        $this->base_name = $data['base_name'] ?? null;
        $this->base_description = $data['base_description'] ?? null;

        if(isset($data['operational_procedures']) && is_array($data['operational_procedures'])) {
            $this->operational_procedures = [];
            foreach ($data['operational_procedures'] as $procedureData) {
                $this->operational_procedures[] = new ProcedureModel($procedureData);
            }
        }

        if(isset($data['trainings']) && is_array($data['trainings'])) {
            $this->trainings = [];
            foreach ($data['trainings'] as $trainingData) {
                $this->trainings[] = new TrainingModel($trainingData);
            }
        }

        if(isset($data['knowledge_resources']) && is_array($data['knowledge_resources'])) {
            $this->knowledge_resources = [];
            foreach ($data['knowledge_resources'] as $resourceData) {
                $this->knowledge_resources[] = new KnowledgeBaseResourceModel($resourceData);
            }
        }
    }

    public function jsonSerialize() : mixed
    {
        return [
            'id' => $this->id,
            'subcategory_id' => $this->subcategory_id,
            'name' => $this->name,
            'description' => $this->description,
            'created_at' => $this->created_at,
            'category_id' => $this->category_id,
            'category_name' => $this->category_name,
            'subcategory_name' => $this->subcategory_name,
            'section_id' => $this->section_id,
            'verification_method' => $this->verification_method,
            'base_subcategory_name' => $this->base_subcategory_name,
            'base_category_name' => $this->base_category_name,
            'base_name' => $this->base_name,
            'base_description' => $this->base_description,
            'operational_procedures' => $this->operational_procedures,
            'trainings' => $this->trainings,
            'knowledge_resources' => $this->knowledge_resources,
        ];
    }

    public function getId()
    {
        return $this->id;
    }

    public function getIdSubcategory()
    {
        return $this->subcategory_id;
    }

    public function getSubcategoryId()
    {
        return $this->subcategory_id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getCreatedAt()
    {
        return $this->created_at;
    }

    public function getIdCategory()
    {
        return $this->category_id;
    }

    public function getCategoryId()
    {
        return $this->category_id;
    }

    public function getCategoryName()
    {
        return $this->category_name;
    }

    public function getSubcategoryName()
    {
        return $this->subcategory_name;
    }

    public function getSectionId()
    {
        return $this->section_id;
    }

    public function getVerificationMethod()
    {
        return $this->verification_method;
    }

    public function getBaseSubcategoryName()
    {
        return $this->base_subcategory_name;
    }

    public function getBaseCategoryName()
    {
        return $this->base_category_name;
    }

    public function getBaseName()
    {
        return $this->base_name;
    }

    public function getBaseDescription()
    {
        return $this->base_description;
    }

    public function getOperationalProcedures()
    {
        return $this->operational_procedures;
    }

    public function getAssignedProcedureIds()
    {
        $ids = [];
        foreach ($this->operational_procedures as $procedure) {
            $ids[] = $procedure->getId();
        }
        return $ids;
    }

    public function getTrainings()
    {
        return $this->trainings;
    }

    public function getAssignedTrainingIds()
    {
        $ids = [];
        foreach ($this->trainings as $training) {
            $ids[] = $training->getId();
        }
        return $ids;
    }

    public function getKnowledgeResources()
    {
        return $this->knowledge_resources;
    }

    public function getAssignedKnowledgeResourceIds()
    {
        $ids = [];
        foreach ($this->knowledge_resources as $resource) {
            $ids[] = $resource->getId();
        }
        return $ids;
    }

}