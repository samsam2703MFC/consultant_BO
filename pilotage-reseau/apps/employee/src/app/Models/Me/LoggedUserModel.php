<?php
namespace App\Employee\app\Models\Me;


use App\Employee\app\Models\Employee\AssignedPositionModel;

class LoggedUserModel{
    private $id;
    private $id_shop;
    private $display_name;
    private $role;
    private $lang_code;
    private $positions = [];
    private $permissions;

    public function __construct($data)
    {
        $this->id = $data['id_owner'];
        $this->id_shop = $data['owner_id_shop'];
        $this->display_name = $data['owner_name'];
        $this->role = $data['owner_role'];
        $this->lang_code = $data['owner_lang_code'];

        if(isset($data['positions']) && !empty($data['positions'])){
            foreach ($data['positions'] as $item) {
                $this->positions[] = new AssignedPositionModel($item);
            }
        }

//        if(isset($data['permissions']) && !empty($data['permissions'])){
//            foreach ($data['permissions'] as $item) {
//                $object = new ProductCompetenceModel($item);
//                $this->required_competences[] = $object;
//            }
//        }
        $this->permissions = $data['permissions'];
    }

    public function getId() {
        return $this->id;
    }

    public function getIdShop() {
        return $this->id_shop;
    }

    public function getDisplayName() {
        return $this->display_name;
    }

    public function getRole() {
        return $this->role;
    }

    public function getLanguageCode() {
        return $this->lang_code;
    }

    public function getPermissions() {
        return $this->permissions;
    }

    public function getPositions() {
        return $this->positions;
    }


}