<?php
// L'audit des fournisseurs (12/11/2026) : les fonctions sans base de src/audits.php — questions, points, règles,
// sous-questions, score, statut, relances. php bin/tests_audits.php
declare(strict_types=1);

$GLOBALS['REGLAGES'] = ['auditsJeton' => 'secret-de-test'];
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['REGLAGES'][$k] ?? $d; }
require __DIR__ . '/../src/audits.php';

$ok = 0; $ko = 0;
$v = function (string $n, bool $c, string $d = '') use (&$ok, &$ko) { if ($c) { $ok++; echo "  ok  $n\n"; } else { $ko++; echo "  KO  $n — $d\n"; } };

// --- le tronc commun livré
$tronc = auTroncDefaut();
$continuer = auQuestionNormalise($tronc[0] + ['id' => 1]);
$pourquoi = auQuestionNormalise($tronc[0]['sous'] + ['id' => 2, 'parent' => 1]);
$prix = auQuestionNormalise($tronc[1] + ['id' => 3]);
$delais = auQuestionNormalise($tronc[2] + ['id' => 4]);
$v('tronc : continuer ? est un choix noté sur 5', $continuer['type'] === 'choix' && auPointsMax($continuer) === 5.0 && count($continuer['choix']) === 3);
$v('tronc : « Oui, avec réserves » vaut 2 points', auPoints($continuer, 'reserves') === 2.0);
$v('tronc : la sous-question ne garde sa condition que sous une mère', $pourquoi['si'] === ['op' => 'in', 'valeur' => ['reserves', 'non']] && auQuestionNormalise($tronc[0]['sous'])['si'] === null);
$v('tronc : prix ≤ 2 exige un commentaire et ouvre une action', $prix['regle']['commentaire'] === true && $prix['regle']['action'] === 'Revoir les prix avec {fournisseur}');
$v('libellé : {fournisseur} prend le nom', auLibelle($prix['libelle'], 'Glace Rawette') === 'Les prix de Glace Rawette sont-ils corrects ?');

// --- les types et leurs points
$v('note : 1 point par point de note, coef 2 → 8 pour 4', auPoints(auQuestionNormalise(['type' => 'note', 'coef' => 2, 'libelle' => 'x']), 4) === 8.0);
$v('note : 6 et 2,5 refusés', auValeur($prix, 6) === null && auValeur($prix, 2.5) === null && auValeur($prix, '3') === 3);
$on = auQuestionNormalise(['type' => 'ouinon', 'libelle' => 'DLC conformes ?']);
$v('oui/non : 5 et 0 par défaut', auPoints($on, 'oui') === 5.0 && auPoints($on, 'non') === 0.0 && auValeur($on, 'peut-être') === null);
$on2 = auQuestionNormalise(['type' => 'ouinon', 'libelle' => 'x', 'choix' => [['code' => 'oui', 'points' => 3], ['code' => 'non', 'points' => 1]]]);
$v('oui/non : points réglés', auPoints($on2, 'oui') === 3.0 && auPointsMax($on2) === 3.0);
$nb = auQuestionNormalise(['type' => 'nombre', 'libelle' => 'Ruptures ce semestre', 'paliers' => [['points' => 0], ['jusqua' => 2, 'points' => 3], ['jusqua' => 0, 'points' => 5]]]);
$v('nombre : paliers triés, « au-delà » en dernier', array_column($nb['paliers'], 'jusqua') === [0.0, 2.0, null]);
$v('nombre : 0 → 5, 2 → 3, 4 → 0', auPoints($nb, 0.0) === 5.0 && auPoints($nb, 2.0) === 3.0 && auPoints($nb, 4.0) === 0.0 && auPointsMax($nb) === 5.0);
$mu = auQuestionNormalise(['type' => 'multi', 'libelle' => 'Outils', 'choix' => [['libelle' => 'Caisse'], ['libelle' => 'Webshop'], ['libelle' => '']]]);
$v('multi : non noté, codes tirés des libellés, choix vide écarté', auPointsMax($mu) === null && array_column($mu['choix'], 'code') === ['caisse', 'webshop']);
$v('multi : codes inconnus filtrés, vide → null', auValeur($mu, ['caisse', 'fax']) === ['caisse'] && auValeur($mu, []) === null);
$ch = auQuestionNormalise(['type' => 'choix', 'libelle' => 'x', 'choix' => [['libelle' => 'Même'], ['libelle' => 'Même']]]);
$v('choix : deux libellés identiques ont deux codes', array_column($ch['choix'], 'code') === ['meme', 'meme2']);
$dt = auQuestionNormalise(['type' => 'date', 'libelle' => 'x']);
$v('date : 31/02 refusé', auValeur($dt, '2026-02-31') === null && auValeur($dt, '2026-02-28') === '2026-02-28');
$ph = auQuestionNormalise(['type' => 'photo', 'libelle' => 'x']);
$v('photo : seul un chemin uploads/audits est accepté', auValeur($ph, 'uploads/audits/3/12/a-b.jpg') === 'uploads/audits/3/12/a-b.jpg' && auValeur($ph, '../../etc/passwd') === null && auValeur($ph, 'https://x/y.jpg') === null);
$v('type inconnu : note par défaut', auQuestionNormalise(['type' => 'zzz', 'libelle' => 'x'])['type'] === 'note');

// --- règles et sous-questions
$v('condition « in » sur un choix et sur un multi', auCondition(['op' => 'in', 'valeur' => ['non']], 'non') && auCondition(['op' => 'in', 'valeur' => ['webshop']], ['caisse', 'webshop']));
$v('condition « le » : 2 ≤ 2, pas 3 ; une absence ne déclenche rien', auCondition($prix['regle'], 2) && !auCondition($prix['regle'], 3) && !auCondition($prix['regle'], null));
$v('sous-question : visible si réserves ou non, cachée si oui', auVisible($pourquoi, [1 => 'reserves']) && !auVisible($pourquoi, [1 => 'oui']) && !auVisible($pourquoi, []));
$v('règle sans effet : écartée', auRegleNormalise(['op' => 'le', 'valeur' => 2]) === null);

// --- ordre, score, manques
$rawette = auQuestionNormalise(['id' => 5, 'fournisseur' => 'fr1', 'type' => 'note', 'libelle' => 'Qualité visuelle', 'ordre' => 10]);
$autre = auQuestionNormalise(['id' => 6, 'fournisseur' => 'fx', 'type' => 'note', 'libelle' => 'Autre']);
$qs = auQuestionsPour([$delais, $rawette, $pourquoi, $prix, $continuer, $autre], 'fr1');
$v('ordre : tronc commun puis questions propres, sous-question sous sa mère', array_column($qs, 'id') === [1, 2, 3, 4, 5], json_encode(array_column($qs, 'id')));
$s = auScore($qs, [1 => 'reserves', 2 => 'Prix en hausse', 3 => 2, 4 => 4, 5 => 4]);
$v('score : 2 + 2 + 4 + 4 = 12 / 20 → 60 %', $s === ['points' => 12.0, 'max' => 20.0, 'pct' => 60.0], json_encode($s));
$v('score : une question non répondue sort du maximum', auScore($qs, [1 => 'oui', 3 => 5])['max'] === 10.0);
$v('score : rien de noté → pct null', auScore([$pourquoi], [])['pct'] === null);
$m = auManques($qs, [1 => 'non', 3 => 1, 4 => 3, 5 => 3], []);
$v('manques : le « pourquoi » obligatoire et le commentaire du prix', $m === [['question' => 2, 'motif' => 'reponse'], ['question' => 3, 'motif' => 'commentaire']], json_encode($m));
$v('manques : rien si tout est là', auManques($qs, [1 => 'oui', 3 => 4, 4 => 3, 5 => 3], []) === []);
$v('statut : 80 bon, 60 à surveiller, 59,9 revoir', auStatut(80.0) === 'bon' && auStatut(60.0) === 'surveiller' && auStatut(59.9) === 'revoir' && auStatut(null) === null);

// --- teinte et affichage
$v('teinte : la note, sinon la part des points (réserves 2/5 → 3)', auTeinte($prix, 2) === 2 && auTeinte($continuer, 'reserves') === 3 && auTeinte($continuer, 'oui') === 5 && auTeinte($pourquoi, 'x') === null);
$v('affichage : libellé du choix, liste du multi', auAffiche($continuer, 'reserves') === 'Oui, avec réserves' && auAffiche($mu, ['caisse', 'webshop']) === 'Caisse, Webshop');

// --- relances
$p = AU_RELANCES; $env = '2026-11-02 09:00:00'; $clo = '2026-11-20';
$v('relance : rien avant J+5', !auRelanceDue($p, $env, $clo, 0, '2026-11-06 18:00:00'));
$v('relance : J+5 due, puis faite', auRelanceDue($p, $env, $clo, 0, '2026-11-07 09:00:00') && !auRelanceDue($p, $env, $clo, 1, '2026-11-08 09:00:00'));
$v('relance : J+10 puis la veille de la clôture', auRelanceDue($p, $env, $clo, 1, '2026-11-12 10:00:00') && auRelanceDue($p, $env, $clo, 2, '2026-11-19 09:30:00') && !auRelanceDue($p, $env, $clo, 3, '2026-11-20 12:00:00'));

// --- le lien
$v('jeton : 32 caractères, propre au destinataire', strlen(auJeton(12)) === 32 && auJeton(12) !== auJeton(13));
$GLOBALS['REGLAGES']['auditsJeton'] = 'autre-secret';
$v('jeton : change avec le secret', auJeton(12) !== (function () { $GLOBALS['REGLAGES']['auditsJeton'] = 'secret-de-test'; return auJeton(12); })());

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
