<?php
namespace App\Employee\app\Models\Employee;


use App\Employee\app\Models\Task\TaskModel;

class AssignedPositionModel
{
    private $id;
    private $name;
    private $description;
    private $level_id;
    private $level_name;
    private $level_description;
    private $level_order;
    private $tasks = [];

    public function __construct($data)
    {
        $this->id = $data['id'];
        $this->name = $data['name'];
        $this->description = $data['description'];
        $this->level_id = $data['level_id'];
        $this->level_name = $data['level_name'];
        $this->level_description = $data['level_description'];
        $this->level_order = $data['level_order'];

        if(isset($data['tasks']) && !empty($data['tasks'])){
            foreach ($data['tasks'] as $item) {
                $object = new TaskModel($item);
                $this->tasks[] = $object;
            }
        }
    }

    public function getId(){ return $this->id; }
    public function getName(){ return $this->name; }
    public function getDescription(){ return $this->description; }
    public function getLevelId(){ return $this->level_id; }
    public function getLevelName(){ return $this->level_name; }
    public function getLevelDescription(){ return $this->level_description; }
    public function getLevelOrder(){ return $this->level_order; }
    public function getTasks(){ return $this->tasks; }
}