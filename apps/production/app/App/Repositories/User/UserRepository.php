<?php
namespace App\Repositories\User;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class UserRepository extends BaseRepository
{

	protected string $table = 'user';

	protected $allowedColumns = [
		'name',
        'surname',
        'displayed_name',
        'email',
        'phone',
        'permission',
        'login',
		'password'
	];

    protected $fetchAllowedColumns = [
        'name',
        'surname'
    ];


    public function logout()
    {
        if(!empty($_SESSION['USER']))
            unset($_SESSION['USER']);

        return true;
    }

    public function logged_in()
    {
        if(!empty($_SESSION['USER']))
            return true;

        return false;
    }

    public function authenticate($row)
    {
        // nowy identyfikator sesji po zalogowaniu (ochrona przed session fixation)
        session_regenerate_id(true);
        $_SESSION['USER'] = $row;
    }
}