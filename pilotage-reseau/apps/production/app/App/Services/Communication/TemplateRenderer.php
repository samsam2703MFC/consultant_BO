<?php
namespace App\Services\Communication;

use App\Exceptions\CustomException;

class TemplateRenderer
{
    public function render(string $template, array $variables): string
    {
        return preg_replace_callback('/{{\s*([a-z_]+\.[a-z_]+)\s*}}/i', function (array $match) use ($variables) {
            [$group, $field] = explode('.', $match[1], 2);
            if (!array_key_exists($group, $variables) || !array_key_exists($field, $variables[$group])) {
                throw new CustomException('Nieznana zmienna szablonu: ' . $match[1], 'errors', 422);
            }
            return (string) $variables[$group][$field];
        }, $template);
    }
}
