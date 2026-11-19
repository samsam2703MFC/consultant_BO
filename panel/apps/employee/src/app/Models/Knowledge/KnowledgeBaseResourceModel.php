<?php
namespace App\Employee\app\Models\Knowledge;

use JsonSerializable;

class KnowledgeBaseResourceModel implements JsonSerializable {

    private $id;
    private $title;
    private $slug;
    private $summary;
    private $url;
    private $category_name;
    private $id_category;
    private $language;
    private $status;
    private $publish_at;
    private $expires_at;
    private $thumbnail_url;
    private $created_by;
    private $updated_by;
    private $created_at;
    private $updated_at;
    private $ulid_str;

    public function __construct($data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function jsonSerialize(): array {
        return get_object_vars($this);
    }

    //getters

    public function getId() {
        return $this->id;
    }

    public function getTitle() {
        return $this->title;
    }

    public function getSlug() {
        return $this->slug;
    }

    public function getSummary() {
        return $this->summary;
    }

    public function getUrl() {
        return $this->url;
    }

    public function getCategoryName() {
        return $this->category_name;
    }

    public function getIdCategory() {
        return $this->id_category;
    }

    public function getLanguage() {
        return $this->language;
    }

    public function getStatus() {
        return $this->status;
    }

    public function getPublishAt() {
        return $this->publish_at;
    }

    public function getExpiresAt() {
        return $this->expires_at;
    }

    public function getThumbnailUrl() {
        return $this->thumbnail_url;
    }

    public function getCreatedBy() {
        return $this->created_by;
    }

    public function getUpdatedBy() {
        return $this->updated_by;
    }

    public function getCreatedAt() {
        return $this->created_at;
    }

    public function getUpdatedAt() {
        return $this->updated_at;
    }

    public function getUlidStr() {
        return $this->ulid_str;
    }

}