<?php


$filename = $order_data->invoice_num;

header('Cache-Control: public');
header('Content-type: application/pdf');
header("Content-Disposition: attachment; filename={$filename}.pdf");
header('Content-Length: '.strlen($proforma));

echo $proforma;