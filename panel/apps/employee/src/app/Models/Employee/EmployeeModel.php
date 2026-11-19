<?php
namespace App\Employee\app\Models\Employee;


use App\Employee\app\Models\Knowledge\CompetencyModel;

/**
 * Model dla użytkowników systemu franczyzowego
 */
class EmployeeModel implements \JsonSerializable{
    private $id;
    private $id_brand;
    private $id_shop;
    private $name;
    private $surname;
    private $display_name;
    private $active;
    private $phone;
    private $email;
    private $lang_code;
    private $id_role;
    private $login;
    private $password;
    private $create_timestamp;
    private $refresh_token;
    private $money_per_hour;
    private $id_production_area;
    private $production_area_name;
    private $workstations = [];
    private $competencies = [];
    private $positions = [];

    public function __construct($data) {
        $this->id = $data['id'] ?? null;
        $this->id_brand = $data['id_brand'] ?? null;
        $this->id_shop = $data['id_shop'] ?? null;
        $this->name = $data['name'] ?? null;
        $this->surname = $data['surname'] ?? null;
        $this->display_name = $data['display_name'] ?? null;
        $this->active = $data['active'] ?? null;
        $this->phone = $data['phone'] ?? null;
        $this->email = $data['email'] ?? null;
        $this->lang_code = $data['lang_code'] ?? null;
        $this->id_role = $data['id_role'] ?? null;
        $this->login = $data['login'] ?? null;
        $this->password = $data['password'] ?? null;
        $this->create_timestamp = $data['create_timestamp'] ?? null;
        $this->refresh_token = $data['refresh_token'] ?? null;
        $this->money_per_hour = $data['money_per_hour'] ?? null;
        $this->id_production_area = $data['id_production_area'] ?? null;
        $this->production_area_name = $data['production_area_name'] ?? null;

        if(isset($data['workstations']) && !empty($data['workstations'])){
            foreach ($data['workstations'] as $item) {
                $object = new WorkstationModel($item);
                $this->workstations[] = $object;
            }
        }

        if(isset($data['competencies']) && !empty($data['competencies'])){
            foreach ($data['competencies'] as $item) {
                $object = new CompetencyModel($item);
                $this->competencies[] = $object;
            }
        }

        if(isset($data['positions']) && !empty($data['positions'])){
            foreach ($data['positions'] as $item) {
                $object = new AssignedPositionModel($item);
                $this->positions[] = $object;
            }
        }
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'id_brand' => $this->id_brand,
            'id_shop' => $this->id_shop,
            'name' => $this->name,
            'surname' => $this->surname,
            'display_name' => $this->display_name,
            'active' => $this->active,
            'phone' => $this->phone,
            'email' => $this->email,
            'lang_code' => $this->lang_code,
            'id_role' => $this->id_role,
            'login' => $this->login,
            'password' => $this->password,
            'create_timestamp' => $this->create_timestamp,
            'refresh_token' => $this->refresh_token,
            'money_per_hour' => $this->money_per_hour,
            'id_production_area' => $this->id_production_area,
            'production_area_name' => $this->production_area_name,
            'workstations' => $this->workstations
        ];
    }

    public function getId() {
        return $this->id;
    }

    public function getIdBrand() {
        return $this->id_brand;
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

    public function getActive() {
        return $this->active;
    }

    public function getPhone() {
        return $this->phone;
    }

    public function getEmail() {
        return $this->email;
    }

    public function getLangCode() {
        return $this->lang_code;
    }

    public function getIdRole() {
        return $this->id_role;
    }

    public function getLogin() {
        return $this->login;
    }

    public function getPassword() {
        return $this->password;
    }

    public function getCreateTimestamp() {
        return $this->create_timestamp;
    }

    public function getRefreshToken() {
        return $this->refresh_token;
    }

    public function getMoneyPerHour() {
        return $this->money_per_hour;
    }

    public function getIdProductionArea() {
        return $this->id_production_area;
    }

    public function getProductionAreaName() {
        return $this->production_area_name;
    }

    public function isWorkstationAssigned($id_workstation)
    {
        if(!empty($this->workstations)){
            foreach ($this->workstations as $item){
                if($item->getId() == $id_workstation) return true;
            }
        }

        return false;
    }

    public function getWorkstationString()
    {
        $workstation_string = "";
        if(!empty($this->workstations)){
            foreach ($this->workstations as $workstation){
                $workstation_string .= ", ". $workstation->getName();
            }
            $workstation_string = trim($workstation_string, ", ");
        }

        return $workstation_string;
    }

    public function getWorkstationIds()
    {
        $workstation_ids = [];
        if(!empty($this->workstations)){
            foreach ($this->workstations as $workstation){
                $workstation_ids[] = $workstation->getId();
            }
        }

        return $workstation_ids;
    }

    public function getCompetencies() {
        return $this->competencies;
    }

    public function getCompetencyIds()
    {
        $competencyIds = [];
        if(!empty($this->competencies)){
            foreach ($this->competencies as $competency){
                $competencyIds[] = $competency->getId();
            }
        }

        return $competencyIds;
    }

    public function getPositions() {
        return $this->positions;
    }

    public function getPositionIds()
    {
        $positionIds = [];
        if(!empty($this->positions)){
            foreach ($this->positions as $position){
                $positionIds[] = $position->getId();
            }
        }

        return $positionIds;
    }

    public static function fromArray(array $data) : self
    {
        return new self([
            'id' => $data['id'] ?? null,
            'id_brand' => $data['id_brand'] ?? null,
            'id_shop' => $data['id_shop'] ?? null,
            'name' => $data['name'] ?? null,
            'surname' => $data['surname'] ?? null,
            'display_name' => $data['display_name'] ?? null,
            'active' => $data['active'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'lang_code' => $data['lang_code'] ?? null,
            'id_role' => $data['id_role'] ?? null,
            'login' => $data['login'] ?? null,
            'password' => $data['password'] ?? null,
            'create_timestamp' => $data['create_timestamp'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'money_per_hour' => $data['money_per_hour'] ?? null,
            'id_production_area' => $data['id_production_area'] ?? null,
            'production_area_name' => $data['production_area_name'] ?? null,
            'workstations' => isset($data['workstations']) ? array_map(fn($item) => WorkstationModel::fromArray($item), $data['workstations']) : []
        ]);
    }
}