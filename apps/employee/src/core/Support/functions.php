<?php

function show($stuff)
{
    echo "<pre>";
    print_r($stuff);
    echo "</pre>";
}

function redirect($path)
{
    header("Location: " . ROOT. "/" . trim($path, "/"));
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

function getSplittedTimestamp($timestampString){

    $timestamp = strtotime($timestampString);

    $time = date('H:i:s', $timestamp);
    $date = date('y-m-d', $timestamp);

    return [
        "date" => $date,
        "time" => $time
    ];
}

/**
 * Ładuje tłumaczenia dla całej aplikacji lub dla konkretnego modułu.
 *
 * @param string $lang - Kod języka (np. 'pl', 'en').
 * @param string|null $module - Opcjonalny moduł, dla którego mają być załadowane tłumaczenia.
 * @param string $defaultLang - Domyślny język na wypadek braku tłumaczeń (np. 'en').
 * @return array - Zwraca tablicę tłumaczeń.
 */
function loadTranslations($type, $lang, $module = null, $defaultLang = 'en') {
    $basePath = __DIR__ . '/../I18n/translations/'; // <- od Support do I18n
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


function getUserLanguage() {
    // Sprawdź, czy istnieje nagłówek HTTP_ACCEPT_LANGUAGE
    $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en';

    // Rozdziel języki i wybierz pierwszy
    $languages = explode(',', $acceptLanguage);
    $primaryLanguage = trim($languages[0]); // np. "en-US"

    // Wyciągnij tylko pierwsze dwie litery (kod ISO 639-1)
    $languageCode = substr($primaryLanguage, 0, 2);

    // Zwróć język lub domyślnie "en"
    return !empty($languageCode) ? strtolower($languageCode) : 'en';
}

function sanitizeSlug(string $slug): string
{
    return strtolower(preg_replace('/[^a-z0-9-]/', '', $slug));
}

