<?php
declare(strict_types=1);

namespace App\Services\Workshop\Manager;

/**
 * Audyt kompetencji stanowiska Kierownika Produkcji i Rozwoju Produktu : obszary kompetencji z opisu stanowiska,
 * pytania (teksty FR / PL w etykietach widoku « manager »), skala 0–3 i poziom oczekiwany na pytanie.
 */
final class CompetencyQuestionnaire
{
    /** 0 = brak, 1 = do rozwoju, 2 = opanowane (oczekiwane), 3 = ekspert. */
    public const SCALE = [0, 1, 2, 3];
    public const EXPECTED = 2;

    public const AREAS = [
        'craft' => ['craft_bakery', 'craft_frozen', 'craft_scale'],
        'production' => ['prod_planning', 'prod_yield', 'prod_organisation'],
        'quality' => ['qual_haccp', 'qual_traceability', 'qual_audit'],
        'product' => ['product_recipe', 'product_costing', 'product_stop'],
        'purchasing' => ['pur_negotiation', 'pur_stock'],
        'logistics' => ['log_transport', 'log_coldchain'],
        'tools' => ['tools_erp', 'tools_office'],
        'languages' => ['lang_pl', 'lang_fr', 'lang_en'],
        'attitude' => ['att_rigour', 'att_authority', 'att_results'],
    ];

    /** Poziom oczekiwany inny niż 2 (polski ojczysty, angielski mile widziany). */
    public const EXPECTED_BY_QUESTION = ['lang_pl' => 3, 'lang_en' => 1];

    /** @return list<string> */
    public static function questions(): array
    {
        return array_merge(...array_values(self::AREAS));
    }

    public static function expected(string $question): int
    {
        return self::EXPECTED_BY_QUESTION[$question] ?? self::EXPECTED;
    }
}
