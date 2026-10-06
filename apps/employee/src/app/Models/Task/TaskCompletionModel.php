<?php
namespace App\Employee\app\Models\Task;

use JsonSerializable;

class TaskCompletionModel  {

    private $id;
    private $task_id;
    private $task_name;
    private $task_description;
    private $scheduled_for_date;
    private $scheduled_time;
    private $completed_at;
    private $completed_by_employee_id;
    private $completed_by_employee_display_name;
    private $note;
    private $attachment_id;


    public function __construct(array $data) {
        $this->id = $data['id'] ?? null;
        $this->task_id = $data['task_id'] ?? null;
        $this->task_name = $data['task_name'] ?? null;
        $this->task_description = $data['task_description'] ?? null;
        $this->scheduled_for_date = $data['scheduled_for_date'] ?? null;
        $this->scheduled_time = $data['scheduled_time'] ?? null;
        $this->completed_at = $data['completed_at'] ?? null;
        $this->completed_by_employee_id = $data['completed_by_employee_id'] ?? null;
        $this->completed_by_employee_display_name = $data['completed_by_employee_display_name'] ?? null;
        $this->note = $data['note'] ?? null;
        $this->attachment_id = $data['attachment_id'] ?? null;
    }

    //getters
    public function getId() { return $this->id; }
    public function getTaskId() { return $this->task_id; }
    public function getTaskName() { return $this->task_name; }
    public function getTaskDescription() { return $this->task_description; }
    public function getScheduledForDate() { return $this->scheduled_for_date; }
    public function getScheduledTime() { return $this->scheduled_time; }
    public function getCompletedAt() { return $this->completed_at; }
    public function getCompletedByEmployeeId() { return $this->completed_by_employee_id; }
    public function getCompletedByEmployeeDisplayName() { return $this->completed_by_employee_display_name; }
    public function getNote() { return $this->note; }
    public function getAttachmentId() { return $this->attachment_id; }

}