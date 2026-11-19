<?php
namespace App\Employee\app\Models\Knowledge;

class ProcedureSectionModel implements \JsonSerializable
{
    private $id;
    private $procedure_id;
    private $base_title;
    private $title;
    private $section_title_id;
    private $base_content;
    private $content;
    private $sort_order;

    public function __construct($data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->procedure_id = $data['procedure_id'] ?? null;
        $this->base_title = $data['base_title'] ?? null;
        $this->title = $data['title'] ?? null;
        $this->section_title_id = $data['section_title_id'] ?? null;
        $this->base_content = $data['base_content'] ?? null;
        $this->content = $data['content'] ?? null;
        $this->sort_order = $data['sort_order'] ?? null;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'procedure_id' => $this->procedure_id,
            'base_title' => $this->base_title,
            'title' => $this->title,
            'section_title_id' => $this->section_title_id,
            'base_content' => $this->base_content,
            'content' => $this->content,
            'sort_order' => $this->sort_order
        ];
    }

    public function getId()
    {
        return $this->id;
    }

    public function getProcedureId()
    {
        return $this->procedure_id;
    }

    public function getBaseTitle()
    {
        return $this->base_title;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function getSectionTitleId()
    {
        return $this->section_title_id;
    }

    public function getBaseContent()
    {
        return $this->base_content;
    }

    public function getContent()
    {
        return $this->content;
    }

    public function getSortOrder()
    {
        return $this->sort_order;
    }

}
