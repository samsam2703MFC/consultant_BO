<?php
declare(strict_types=1);

/**
 * Le DDL MySQL des tables hr_dimona_* (module RH / Dimona), tiré de Hr\Support\Schema :
 *
 *   php bin/hr_dimona_schema.php > sql/hr_dimona.sql
 *
 * Les tables se créent aussi seules au premier appel de l'API ; ce fichier sert à les relire ou à les poser
 * à la main. tests/hr/run.php vérifie qu'il est à jour.
 */
require __DIR__ . '/../src/hr/autoload.php';
echo Hr\Support\Schema::mysqlScript();
