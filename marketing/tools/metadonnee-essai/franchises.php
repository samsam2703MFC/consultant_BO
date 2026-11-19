<?php
// Kit d'essai MétaDonnée (voir LISEZMOI.md).
// Les franchisés travaillent leurs leads dans le faux ERP (statuts + dates).
$E = __DIR__ . '/etat';
$meta = []; foreach (json_decode(file_get_contents("$E/leads_meta.json"), true) as $l) { $meta[$l['id']] = strtotime($l['created_time']); }
$erp = json_decode(file_get_contents("$E/erp.json"), true);
$plan = ['7101' => ['new'], '7102' => ['seen', 25], '7103' => ['office_connected', 12, 18, 900], '7104' => ['contacted', 30, 45], '7105' => ['office_created', 20, 40],
    '7106' => ['office_connected', 10, 22, 2000], '7107' => ['lost', 60, 90], '7108' => ['seen', 14], '7109' => ['contacted', 20, 62], '7111' => ['office_created', 50, 70],
    '7112' => ['office_connected', 8, 19, 1500], '7113' => ['office_connected', 15, 33, 3000], '7114' => ['contacted', 27, 36], '7115' => ['seen', 20], '7116' => ['new'],
    '7118' => ['office_connected', 30, 55, 2600], '7119' => ['lost', 120, 240], '7120' => ['new'], '7121' => ['new']];
$iso = fn ($t) => gmdate('c', $t);
foreach ($erp['leads'] as $mid => &$l) {
    $p = $plan[$mid] ?? null; if (!$p) { continue; }
    $t0 = $meta[$mid];
    $l['status'] = $p[0];
    if (isset($p[1])) { $l['seen_at'] = $iso($t0 + $p[1] * 60); }
    if (isset($p[2])) { $l['contacted_at'] = $iso($t0 + $p[2] * 60); }
    if (isset($p[3])) { $l['connected_at'] = $iso($t0 + $p[3] * 60); }
}
file_put_contents("$E/erp.json", json_encode($erp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "ok\n";
