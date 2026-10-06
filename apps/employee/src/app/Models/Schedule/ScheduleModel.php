<?php
namespace App\Employee\app\Models\Schedule;

/**
 * Model dla harmonogramu pracowników systemu franczyzowego
 */
class ScheduleModel implements \JsonSerializable{
    private $id_schedule;
    private $id_employee;
    private $start_hour;
    private $end_hour;
    private $work_date;
    private $create_timestamp;
    private $id_shop;
    private $name;
    private $surname;
    private $display_name;
    private $phone;
    private $lang_code;

    public function __construct($data) {
        $this->id_schedule = $data['id_schedule'] ?? null;
        $this->id_employee = $data['id_employee'] ?? null;
        $this->start_hour = $data['start_hour'] ?? null;
        $this->end_hour = $data['end_hour'] ?? null;
        $this->work_date = $data['work_date'] ?? null;
        $this->create_timestamp = $data['create_timestamp'] ?? null;
        $this->id_shop = $data['id_shop'] ?? null;
        $this->name = $data['name'] ?? null;
        $this->surname = $data['surname'] ?? null;
        $this->display_name = $data['display_name'] ?? null;
        $this->phone = $data['phone'] ?? null;
        $this->lang_code = $data['lang_code'] ?? null;
    }

    public function jsonSerialize(): array {
        return [
            'id_schedule' => $this->id_schedule,
            'id_employee' => $this->id_employee,
            'start_hour' => $this->start_hour,
            'end_hour' => $this->end_hour,
            'work_date' => $this->work_date,
            'create_timestamp' => $this->create_timestamp,
            'id_shop' => $this->id_shop,
            'name' => $this->name,
            'surname' => $this->surname,
            'display_name' => $this->display_name,
            'phone' => $this->phone,
            'lang_code' => $this->lang_code
        ];
    }

    public function getIdSchedule() {
        return $this->id_schedule;
    }

    public function getIdEmployee() {
        return $this->id_employee;
    }

    public function getStartHour() {
        return $this->start_hour;
    }

    public function getEndHour() {
        return $this->end_hour;
    }

    public function getWorkDate() {
        return $this->work_date;
    }

    public function getCreateTimestamp() {
        return $this->create_timestamp;
    }

    public function getIdShop() {
        return $this->id_shop;
    }

    public function getName() {
        return $this->name;
    }

    public function getSurname() {
        return $this->surname;
    }

    public function getDisplayName() {
        return $this->display_name;
    }

    public function getPhone() {
        return $this->phone;
    }

    public function getLangCode() {
        return $this->lang_code;
    }
}
