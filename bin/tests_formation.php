#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du module Formation — sans base, sans réseau, sans compte panel.
 *
 *   php bin/tests_formation.php
 *
 * Ce qui doit rester vrai quoi que rende le panel : aucune donnée personnelle
 * ni aucun secret ne sort vers le navigateur ; la sonde de l'écran API ne lit
 * que les routes du référentiel formation ; les lignes de liaison (niveau ↔
 * compétence, compétence ↔ formation) donnent l'identifiant de la CIBLE, pas
 * celui de la liaison.
 */

// --- doublures : le module n'a besoin que de PanelApi::liste ---------------------
final class PanelApi
{
    public static function liste(array $d): array
    {
        if (array_is_list($d)) { return array_values(array_filter($d, 'is_array')); }
        foreach (['data', 'items', 'results'] as $k) {
            if (isset($d[$k]) && is_array($d[$k])) { return self::liste($d[$k]); }
        }
        return [];
    }
}

require __DIR__ . '/../src/formation.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "Données sensibles\n";
foreach (['password', 'pin', 'refresh_token', 'access_token', 'phone', 'email', 'money_per_hour', 'login', 'iban', 'session_id', 'password_hash'] as $k) {
    verifie("« $k » est retenu", fmSecret($k));
}
foreach (['id', 'name', 'description', 'competency_id', 'level_name', 'shipping', 'position_id', 'training_id', 'title'] as $k) {
    verifie("« $k » passe", !fmSecret($k));
}
$s = fmScrub(['id' => 4, 'name' => 'Julie', 'phone' => '+32', 'positions' => [['id' => 1, 'email' => 'x@y.be', 'level_name' => 'Confirmée']], 'money_per_hour' => 14]);
verifie('nettoyage récursif', !isset($s['phone'], $s['money_per_hour'], $s['positions'][0]['email']) && $s['positions'][0]['level_name'] === 'Confirmée', json_encode($s));
$b = fmScalaires(['id' => 1, 'name' => 'Four', 'password' => 'x', 'tags' => ['a'], 'note' => null]);
verifie('champs bruts : scalaires sans secret', $b === ['id' => 1, 'name' => 'Four', 'note' => null], json_encode($b));

echo "Sonde de l'écran API (liste blanche)\n";
foreach (['/positions', '/positions/12/levels', '/position-levels/3/competencies', '/competencies/7', '/competencies?section_id=2',
    '/career-stages/1/competencies', '/operational-framework/trainings/4/trainers', '/operational-procedures/9', '/knowledge-base/resources',
    '/product-competences/name-aliases', '/packages', '/employees/41', '/franchisee-employee/5/competences', '/course-commissions'] as $c) {
    verifie("accepte $c", (bool) preg_match(FM_SONDE, $c));
}
foreach (['/consultant/auth/login', '/admin/auth/login', '/competencies/../admin', '/employees', '/shops/4/employees', '/positions/1/delete?x=<s>',
    '/competencies/1/trainings', 'https://evil.example/competencies', '/competencies#x', '/attachments/4/presigned-url'] as $c) {
    verifie("refuse $c", !preg_match(FM_SONDE, $c));
}

echo "Normalisation\n";
$c = fmCompetence(['id' => 9001, 'competency_id' => 104, 'name' => 'Relever les températures', 'category_name' => 'HACCP']);
verifie('liaison niveau ↔ compétence : id de la compétence', $c['id'] === 104 && $c['nom'] === 'Relever les températures' && $c['categorie'] === 'HACCP', json_encode($c));
$c = fmCompetence(['id' => 7, 'base_name' => 'Base', 'subcategory_id' => '11', 'subcategory_name' => 'Accueil']);
verifie('nom de base en repli, entiers lus dans des chaînes', $c['nom'] === 'Base' && $c['sousCategorieId'] === 11);
$l = fmLiens(['trainings' => [['id' => 60001, 'training_id' => 1, 'name' => 'HACCP']], 'operational_procedures' => [['id' => 70001, 'id_operational_procedure' => 501, 'title' => 'Relevé']],
    'knowledge_resources' => [['id' => 50001, 'knowledge_resource_id' => 801, 'title' => 'Vidéo']]]);
verifie('liens : identifiants des cibles', $l['formations'][0]['id'] === 1 && $l['procedures'][0]['id'] === 501 && $l['ressources'][0]['id'] === 801, json_encode($l));
verifie('liens absents ⇒ listes vides', fmLiens([]) === ['procedures' => [], 'formations' => [], 'ressources' => []]);
$n = fmNiveau(['level_id' => 3, 'level_name' => 'Confirmée', 'level_order' => '2', 'position_id' => 1], null);
verifie('niveau : noms de champs alternatifs', $n['id'] === 3 && $n['nom'] === 'Confirmée' && $n['ordre'] === 2 && $n['posteId'] === 1, json_encode($n));
verifie('liste enveloppée dans data', count(fmListe(['data' => [['id' => 1], ['id' => 2]]])) === 2);
verifie('réponse non tableau ⇒ liste vide', fmListe('erreur') === [] && fmListe(null) === []);

echo "\n$ok ok, $ko KO\n";
exit($ko === 0 ? 0 : 1);
