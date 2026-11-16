#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des automatisations du magasin (src/franchise/automatisations.php) : le réglage, les variables, les canaux,
 * la commande trop récente — sans base, sans réseau. Les commandes ci-dessous sont des formes de commande du panel
 * (`/shops/{id}/client-orders`), pas des données du réseau.
 *
 *   php bin/tests_automatisations.php
 */

require __DIR__ . '/../src/sms.php';
require __DIR__ . '/../src/franchise/automatisations.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "Le réglage\n";
[$g, $e] = auValider(AU_RAPPEL, []);
verifie('le réglage de départ est valide', $e === null && $g['canal'] === 'les2' && $g['heure'] === '17:00' && $g['actif'] === false);
[$g, $e] = auValider(AU_RAPPEL, ['actif' => true, 'canal' => 'sms', 'heure' => '18:30']);
verifie('allumé, SMS, 18:30', $e === null && $g['actif'] && $g['canal'] === 'sms' && $g['heure'] === '18:30');
[, $e] = auValider(AU_RAPPEL, ['canal' => 'fax']);
verifie('un canal inconnu est refusé', $e !== null);
[, $e] = auValider(AU_RAPPEL, ['heure' => '07:00']);
verifie('une heure avant midi est refusée', $e !== null);
[, $e] = auValider(AU_RAPPEL, ['heure' => '21:30']);
verifie('une heure après 21:00 est refusée', $e !== null);
[, $e] = auValider(AU_RAPPEL, ['heure' => '17h']);
verifie('une heure mal écrite est refusée', $e !== null);
[, $e] = auValider(AU_RAPPEL, ['sms' => str_repeat('a', AU_SMS_MAX + 1)]);
verifie('un SMS de plus de deux messages est refusé', $e !== null);
[, $e] = auValider(AU_RAPPEL, ['canal' => 'email', 'objet' => '']);
verifie('un e-mail sans objet est refusé', $e !== null);
[, $e] = auValider(AU_RAPPEL, ['canal' => 'sms', 'objet' => '']);
verifie('SMS seul : l’objet de l’e-mail peut être vide', $e === null);
[, $e] = auValider(AU_RAPPEL, ['canal' => 'les2', 'sms' => '  ']);
verifie('les deux canaux : un SMS vide est refusé', $e !== null);
[$g] = auValider(AU_RAPPEL, ['corps' => "a\r\nb"]);
verifie('les retours chariot sont retirés', $g['corps'] === "a\nb");

echo "Les variables\n";
$o = ['id' => 2041, 'pick_up_datetime' => '2026-11-17 08:30:00', 'total_value' => 68.4, 'id_transaction' => 0,
    'accepting_timestamp' => '2026-11-12 11:42:00', 'client' => ['name' => 'Camille', 'surname' => 'Exemple', 'email' => 'Camille@Exemple.be', 'phone' => '0470 12 34 56']];
$v = auVars($o, 'L’Atelier By Halle');
verifie('le jour en toutes lettres', $v['jour'] === 'mardi 17 novembre', $v['jour']);
verifie('l’heure', $v['heure'] === '08:30');
verifie('le montant', $v['total'] === '68,40 €', $v['total']);
verifie('non réglée : le reste à payer', $v['paiement'] === 'Reste à payer au retrait : 68,40 €.', $v['paiement']);
$v2 = auVars(['id_transaction' => 991] + $o, 'X');
verifie('déjà réglée (un ticket de commande)', $v2['paiement'] === 'Elle est déjà réglée.');
$v3 = auVars(['client' => ['name' => '', 'surname' => '', 'is_b2b' => 1, 'company_name' => 'Café Exemple']] + $o, 'X');
verifie('un pro sans prénom : sa société', $v3['prenom'] === 'Café Exemple');
$sms = auRemplir(AU_RAPPEL['sms'], $v);
verifie('le SMS rempli', $sms === 'L’Atelier By Halle : votre commande vous attend demain, mardi 17 novembre, à 08:30. Reste à payer au retrait : 68,40 €.', $sms);
verifie('le SMS tient en un ou deux messages', mb_strlen($sms) <= AU_SMS_MAX);
$vide = auRemplir('Bonjour {prenom}, à {heure}.', ['heure' => '10:00']);
verifie('une variable vide s’efface sans laisser d’espace devant la virgule', $vide === 'Bonjour, à 10:00.', $vide);
verifie('une accolade qui n’est pas une variable reste', auRemplir('{autre} {heure}', ['heure' => '9:00']) === '{autre} 9:00');
verifie('l’objet de départ', auRemplir(AU_RAPPEL['objet'], $v) === 'Votre commande de demain, 08:30 · L’Atelier By Halle');

echo "Les canaux\n";
[$m, $t] = auContacts($o);
verifie('l’e-mail en minuscules, le téléphone au format international', $m === 'camille@exemple.be' && $t === '+32470123456', $m . ' ' . $t);
[$m, $t] = auContacts(['client' => ['email' => 'pas-un-email', 'phone' => '']]);
verifie('ni e-mail valide ni téléphone', $m === null && $t === null);
verifie('les deux, tout est là', auCanaux('les2', 'a@b.be', '+32470') === ['email' => true, 'sms' => true]);
verifie('les deux, sans e-mail : le SMS seul', auCanaux('les2', null, '+32470') === ['email' => false, 'sms' => true]);
verifie('les deux, sans téléphone : l’e-mail seul', auCanaux('les2', 'a@b.be', null) === ['email' => true, 'sms' => false]);
verifie('e-mail seul', auCanaux('email', 'a@b.be', '+32470') === ['email' => true, 'sms' => false]);
verifie('e-mail demandé, pas d’e-mail : le SMS le remplace', auCanaux('email', null, '+32470') === ['email' => false, 'sms' => true]);
verifie('SMS demandé, pas de téléphone : l’e-mail le remplace', auCanaux('sms', 'a@b.be', null) === ['email' => true, 'sms' => false]);
verifie('injoignable', auCanaux('les2', null, null) === ['email' => false, 'sms' => false]);

echo "La commande trop récente\n";
verifie('acceptée cinq jours avant : rappel', !auTropRecente($o));
verifie('acceptée la veille à 10:00 pour 08:30 : pas de rappel', auTropRecente(['accepting_timestamp' => '2026-11-16 10:00:00'] + $o));
verifie('sans date d’acceptation : rappel', !auTropRecente(['accepting_timestamp' => null] + $o));

echo "\n$ok ok, $ko en échec\n";
exit($ko > 0 ? 1 : 0);
