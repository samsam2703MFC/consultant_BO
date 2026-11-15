<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('Content-Type: application/json');
echo json_encode(wr_fidelite_profils_post(), JSON_UNESCAPED_UNICODE);
