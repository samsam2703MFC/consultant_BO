<?php

namespace App\Repositories\Document;
use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class DocumentRepository extends BaseRepository
{

    protected string $table = '';

    protected $allowedColumns = [

    ];


    public function saveDocument($filename, $base)
    {
        header('Cache-Control: public');
        header('Content-type: application/pdf');
        header("Content-Disposition: attachment; filename={$filename}.pdf");
        header('Content-Length: ' . strlen($base));
        echo $base;
    }
}