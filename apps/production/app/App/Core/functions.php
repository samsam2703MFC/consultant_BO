<?php 

function show($stuff)
{
	echo "<pre>";
	print_r($stuff);
	echo "</pre>";
}

function esc($str)
{
	return htmlspecialchars($str);
}


function redirect($path)
{
	header("Location: " . ROOT."/".$path);
	die;
}

function old_value($key, $default = '')
{
    if(!empty($_POST[$key]))
        return $_POST[$key];

    return $default;
}

function old_select($key, $value, $default = '')
{

    if(!empty($_POST[$key]) && $_POST[$key] == $value)
    {
        return ' selected ';
    }

    if(!empty($default) && $default == $value)
    {
        return ' selected ';
    }

    return '';
}

function old_checkbox($key, $value, $default = '')
{
    if(!empty($_POST[$key]) && $_POST[$key] == $value)
    {
        return ' checked ';
    }

    if(!empty($default) && $default == $value)
    {
        return ' checked ';
    }

    return '';
}

function old_values_with_same_name($post, $assoc, $keyId = 'id', $keyName = 'name', $keyQuantity = 'quantity')
{
    $oldValues = [];

    foreach ($assoc as $item){
        $keyValueMaterials[$item[$keyId]] = $item[$keyName];
    }

    foreach ($post as $material)
    {
        if(!isset($keyValueMaterials[key($material)])) continue;

        array_push($oldValues, [$keyId => key($material), $keyQuantity => $material[key($material)], $keyName => $keyValueMaterials[key($material)]]);
    }

    return $oldValues;
}

function user($key = '')
{

    if(!empty($_SESSION['USER']))
    {
        if(empty($key))
            return $_SESSION['USER'];

        if(!empty($_SESSION['USER']->$key))
        {
            return $_SESSION['USER']->$key;
        }
    }
    return '';
}

function get_image($filename = '')
{
    if(file_exists($filename))
        return ROOT . '/assets/img/'.$filename;

    return ROOT . '/assets/img/noimage.png';
}

function decoded_ajax_json($data)
{
    return json_decode(json_encode(json_decode($data)), true);
}

function encoded_ajax_json($data)
{
    return json_encode(json_decode(json_encode($data)), true);
}

function convert_std_to_assoc($data)
{
    return json_decode(json_encode($data), TRUE);
}


// Funkcja do wyświetlania składników
function displayIngredients($elements, $indent = '') {
    foreach ($elements as $element) {
        // Jeśli element jest składnikiem
        if ($element['type'] === 'ingredient') {
            echo "<tr class='parent_{$element['parent_id']} hideRow'> {$indent} <td>{$element['name']}</td><td>{$element['required_quantity']} {$element['unit_name']}</td><td>{$element['price_for_required_quantity']} zł</td></tr>";
        }
        // Jeśli element jest podprzepisem
        if ($element['type'] === 'sub-recipe') {
            echo "
                    <tr class='bg-light-warning parent_{$element['parent_id']} hideRow'> 
                        {$indent} 
                        <td>
                            <button class='btn btn-outline-dark' type='button' onclick='expandChildren(\"parent_{$element['id']}\")'>+</button> {$element['name']}
                        </td>
                        <td>{$element['required_quantity']} {$element['unit_name']}</td>
                        <td>{$element['price_for_required_quantity']} zł</td>
                        <td></td></tr>";
            // Rekurencyjne wyświetlanie składników podprzepisu
            displayIngredients($element['elements'], $indent . '<td></td>');
        }
    }
}

function printRecipeInTable($data)
{
// Iteracja przez główną strukturę danych
    foreach ($data as $item) {
        // Sprawdzanie, czy mamy do czynienia z przepisem głównym czy składnikiem
        if (isset($item['elements'])) {
            echo 'Przepis: ' . $item['name'] . PHP_EOL;
            displayIngredients($item['elements']);
        } else {
            // Jeśli to składnik poza podprzepisem
            echo 'Składnik: ' . $item['name'] . PHP_EOL;
        }
        echo PHP_EOL; // Dodanie pustej linii dla lepszej czytelności
    }
}

function recipeList2html($list)
{
    $html = '';
    foreach ($list as $item)
    {
        $html .= "<li style='margin-left: 50px; margin-top: 10px; font-size: 1rem'>";

        if($item['type'] == "sub-recipe"){
            $html .= "<span class='font-bold'><u>{$item['name']}</u></span>";
        } else {
            $html .= "{$item['name']}";
        }

        $required_quantity = round($item['required_quantity'],0);
        $html .= "<span class='text-muted mx-5'> {$required_quantity}{$item['unit_name']} => [{$item['price_for_required_quantity']}zł]</span>";
        if ( !empty($item['elements']) ) // recurse here
            $html .= recipeList2html($item['elements']);
        $html .= '</li>';
    }
    return '<ul>' . $html . '</ul>';
}

function countIngredients($recipe, &$ingredientData) {

    foreach ($recipe as $item) {
        if ($item['type'] !== 'sub-recipe') {

            if(!isset($ingredientData[$item['name']])){
                $ingredientData[$item['name']] = array(
                    'name' => $item['name'],
                    'id' => $item['id'],
                    'required_quantity' => 0,
                    'unit_price' => $item['suggested_base_unit_price'],
                    'unit_name' => $item['unit_name'],
                    'id_unit' => $item['id_unit']
                );
            }

            $ingredientData[$item['name']]['required_quantity'] += $item['required_quantity'];
        }

        if (isset($item['elements']) && is_array($item['elements'])) {
            countIngredients($item['elements'], $ingredientData);
        }
    }
}

function increaseItemToProduce(&$elements, $to_produce){
    foreach ($elements as &$element){
        $element['required_quantity'] = $element['required_quantity'] * $to_produce;

        if (isset($item['elements']) && is_array($item['elements'])) {
            increaseItemToProduce($item['elements'], $to_produce);
        }
    }
}

function getSelectedKeyArray($data, $column_name = "id")
{
    $res_arr = [];

    if(!empty($data)){
        foreach ($data as $item){
            array_push($res_arr, $item[$column_name]);
        }
    }

    return $res_arr;
}

function sortAssocArray(array $array, $param, $ascending = true) {

    $sortableArray = array();


    foreach($array as $key => $value) {
        $sortableArray[$key] = $value[$param];
    }

    if ($ascending) {
        asort($sortableArray);
    } else {
        arsort($sortableArray);
    }

    // Utwórz nową tablicę, która będzie posortowana według wartości w pomocniczej tablicy
    $sortedArray = array();
    foreach($sortableArray as $key => $value) {
        $sortedArray[$key] = $array[$key];
    }

    return $sortedArray;
}



/**
 * Ładuje tłumaczenia dla całej aplikacji lub dla konkretnego modułu.
 *
 * @param string $lang - Kod języka (np. 'pl', 'en').
 * @param string|null $module - Opcjonalny moduł, dla którego mają być załadowane tłumaczenia.
 * @param string $defaultLang - Domyślny język na wypadek braku tłumaczeń (np. 'en').
 * @return array - Zwraca tablicę tłumaczeń.
 */
function loadTranslations($type, $lang, $module = null, $defaultLang = 'pl') {
    $basePath = "../public/resources/lang/";
    $filePath = $basePath . $type . "/" . $lang . "/" . $module . ".json";

    // Ładowanie tłumaczeń dla danego języka
    if (file_exists($filePath)) {
        $string = file_get_contents($filePath);
        $translations = json_decode(json_encode(json_decode($string)), true);
    } else {
        $translations = [];
    }

    // Fallback do domyślnego języka, jeśli tłumaczenie nie istnieje
    if (empty($translations) && $lang !== $defaultLang) {
        $fallbackPath = $basePath . "{$defaultLang}";
        $fallbackPath .= $module ? "{$module}.json" : "messages.json";
        if (file_exists($fallbackPath)) {
            $translations = json_decode(file_get_contents($fallbackPath), true);
        }
    }

    return $translations;
}

function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // wersja 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // wariant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
