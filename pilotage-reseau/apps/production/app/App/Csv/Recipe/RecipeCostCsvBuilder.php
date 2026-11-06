<?php
namespace App\Csv\Recipe;

class RecipeCostCsvBuilder
{
    public function __construct()
    {}

    public function build($recipeCostData)
    {
        $headers = [
            "id_product",
            "product_name",
            "ppc",
            "recipe_cost_zl",
            "berdiff_price_eur",
            "max_price_eur"
        ];

        $output = fopen('php://temp', 'r+');

        // Nagłówki kolumn
        fputcsv($output, $headers, ';');

        // Dane produktów
        foreach ($recipeCostData as $row) {
            fputcsv($output, [
                $row['id'],
                $row['name'],
                $row['ppc'],
                $row['cost'],
                $row['berdiff_price'],
                $row['max_price']
            ], ';');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        // Data w nazwie pliku
        $filename = "cost_price_" . date('Ymd') . ".csv";

        return [
            'file_string' => $csv,
            'filename' => $filename
        ];
    }

}