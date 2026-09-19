<?php
declare(strict_types=1);

/**
 * Newsletter — le MOTEUR : les contacts, les segments évalués, le rendu du
 * mail, l'envoi SMTP par lots, le suivi (ouverture, clic, désinscription),
 * les vouchers, l'horloge (campagnes programmées, automatisations).
 *
 * Deux régimes, un interrupteur (`nlDispatch`, Paramètres → Envois) :
 *  - MODE TEST (défaut) : rien ne part vers les clients. Les campagnes
 *    programmées attendent ; seul le « test à 3 adresses » part, vers les
 *    adresses de test de la marque.
 *  - DISPATCH : l'horloge (cron, toutes les 5 minutes) fait partir les
 *    campagnes dues par lots de 100 par minute, évalue les automatisations
 *    chaque nuit, et n'écrit jamais deux fois au même client le même jour.
 *
 * Les contacts vivent dans `ceo_nl_contact`, importés par CSV (Paramètres →
 * Bases de données) par lots que l'on peut retirer d'un geste. Tant qu'une
 * base n'a aucun contact, ses compteurs restent le jeu d'essai du brief.
 *
 * SMS : aucun fournisseur n'est branché — le texte est gardé, rien ne part,
 * et l'écran le dit. La déclinaison sur les réseaux du brief a été retirée.
 * Vouchers : codes uniques générés au départ, marqués « utilisé » par
 * POST /newsletter/vouchers/{code}/utiliser ; Stripe n'est pas branché.
 */

const NL_SOURCES_ID = ['indiv', 'office', 'b2b', 'crm'];
const NL_LOT_MAX = 500;          // par passage d'horloge (5 minutes à 100 par minute)
const NL_CADENCE = 100;          // mails par minute

function ensureNewsletterEnvoi(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_nl_contact ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'source_id VARCHAR(16) NOT NULL,'
        . 'shop_id VARCHAR(16) NOT NULL DEFAULT "",'
        . 'email VARCHAR(190) NOT NULL,'
        . 'telephone VARCHAR(32) NOT NULL DEFAULT "",'
        . 'prenom VARCHAR(80) NOT NULL DEFAULT "",'
        . 'nom VARCHAR(80) NOT NULL DEFAULT "",'
        . 'langue CHAR(2) NOT NULL DEFAULT "fr",'
        . 'optin TINYINT NOT NULL DEFAULT 1,'
        . 'optin_sms TINYINT NOT NULL DEFAULT 0,'
        . 'dernier_achat DATE NULL,'
        . 'panier DECIMAL(8,2) NULL,'
        . 'produits VARCHAR(255) NOT NULL DEFAULT "",'   // tags séparés par des virgules : tartine, cougnou…
        . 'anniversaire DATE NULL,'
        . 'cree_le DATE NULL,'
        . 'desinscrit_le DATETIME NULL,'
        . 'token CHAR(32) NOT NULL,'
        . 'lot VARCHAR(40) NOT NULL DEFAULT "",'
        . 'importe_le DATETIME NOT NULL,'
        . 'UNIQUE KEY u_src_email (source_id, email),'
        . 'KEY k_token (token), KEY k_lot (lot), KEY k_shop (shop_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_nl_envoi ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'campagne_id INT UNSIGNED NOT NULL,'
        . 'contact_id INT UNSIGNED NOT NULL,'
        . 'langue CHAR(2) NOT NULL DEFAULT "fr",'
        . 'token CHAR(32) NOT NULL,'
        . 'sent_at DATETIME NOT NULL,'
        . 'opened_at DATETIME NULL,'
        . 'clicked_at DATETIME NULL,'
        . 'voucher VARCHAR(12) NULL,'
        . 'erreur VARCHAR(200) NULL,'
        . 'UNIQUE KEY u_camp_contact (campagne_id, contact_id),'
        . 'KEY k_token (token), KEY k_contact_jour (contact_id, sent_at)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_nl_voucher ('
        . 'code VARCHAR(12) PRIMARY KEY,'
        . 'campagne_id INT UNSIGNED NOT NULL,'
        . 'contact_id INT UNSIGNED NULL,'
        . 'cree_le DATETIME NOT NULL,'
        . 'utilise_le DATETIME NULL,'
        . 'shop_id VARCHAR(16) NULL,'
        . 'KEY k_camp (campagne_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    foreach (['headlines_json TEXT NULL', 'ctas_json TEXT NULL', 'image VARCHAR(40) NULL', 'started_at DATETIME NULL', 'finished_at DATETIME NULL', 'auto_dernier DATE NULL'] as $col) {
        try { Db::exec('ALTER TABLE ceo_nl_campagne ADD COLUMN ' . $col); } catch (Throwable $e) { /* déjà là */ }
    }
    if ((string) setting('nlJeton', '') === '') {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value', ['nlJeton', json_encode(bin2hex(random_bytes(24)))]);
    }
}

/* ---------------------------------------------------------------------------
 * Réglages
 * ------------------------------------------------------------------------- */
function nlReglagePoser(string $cle, mixed $valeur): void
{
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($valeur, JSON_UNESCAPED_UNICODE)]);
}
function nlDispatch(): bool { return (bool) setting('nlDispatch', false); }
/** Les adresses qui reçoivent le « test à 3 adresses » : réglage, sinon l'expéditeur SMTP. */
function nlAdressesTest(): array
{
    $l = setting('nlTestAdresses', []);
    $l = is_array($l) ? array_values(array_filter(array_map(fn ($a) => trim((string) $a), $l), fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false)) : [];
    if ($l === [] && Smtp::configured()) {
        $exp = Smtp::config()['expediteur'];
        $a = preg_match('/<([^>]+)>/', $exp, $m) ? $m[1] : $exp;
        if (filter_var($a, FILTER_VALIDATE_EMAIL)) { $l[] = $a; }
    }
    return array_slice($l, 0, 3);
}
function nlReglages(): array
{
    return ['dispatch' => nlDispatch(), 'smtp' => Smtp::configured(), 'smtpExpediteur' => Smtp::configured() ? Smtp::config()['expediteur'] : '',
        'testAdresses' => nlAdressesTest(), 'sms' => false,
        'cron' => rapBaseUrl() . '/api/cockpit/newsletter/cron?jeton=' . (string) setting('nlJeton', ''), 'cronDernier' => setting('nlCronDernier', null)];
}

/* ---------------------------------------------------------------------------
 * Contacts et segments
 * ------------------------------------------------------------------------- */
/** Nombre de contacts (total, opt-in) par base ; [] pour une base sans contact. */
function nlContactsParSource(): array
{
    $out = [];
    foreach (Db::rows('SELECT source_id, COUNT(*) n, SUM(optin = 1 AND desinscrit_le IS NULL) o, SUM(optin_sms = 1 AND desinscrit_le IS NULL) s FROM ceo_nl_contact GROUP BY source_id') as $r) {
        $out[$r['source_id']] = ['n' => (int) $r['n'], 'optin' => (int) $r['o'], 'sms' => (int) $r['s']];
    }
    return $out;
}

/** La clause SQL d'un segment sur ceo_nl_contact (opt-in mail, non désinscrit) ; [sql, params]. */
function nlSegmentSql(array $seg): array
{
    $w = ['c.source_id = ?', 'c.optin = 1', 'c.desinscrit_le IS NULL'];
    $p = [$seg['source_id']];
    $rules = nlJson($seg['rules_json'] ?? null, []);
    $shop = (string) ($seg['shop_id'] ?? '');
    if ($shop !== '') { $w[] = 'c.shop_id = ?'; $p[] = $shop; }
    if (is_array($rules)) {
        if (!empty($rules['produit'])) { $w[] = 'FIND_IN_SET(?, REPLACE(c.produits, " ", ""))'; $p[] = (string) $rules['produit']; }
        $per = (string) ($rules['periode'] ?? '');
        if ($per === '30' || $per === '90' || $per === '365') { $w[] = 'c.dernier_achat >= DATE_SUB(CURDATE(), INTERVAL ? DAY)'; $p[] = (int) $per; }
        elseif ($per === 'dormant45') { $w[] = 'c.dernier_achat IS NOT NULL AND c.dernier_achat < DATE_SUB(CURDATE(), INTERVAL 45 DAY)'; }
        if (!empty($rules['panier_min'])) { $w[] = 'c.panier >= ?'; $p[] = (float) $rules['panier_min']; }
    }
    return [implode(' AND ', $w), $p];
}

/** Compte réel d'un segment (total et par langue), ou null si sa base n'a aucun contact. */
function nlSegmentCompte(array $seg, array $parSource): ?array
{
    if (!isset($parSource[$seg['source_id']])) { return null; }
    [$sql, $p] = nlSegmentSql($seg);
    $out = ['total' => 0, 'split' => [0, 0, 0]];
    foreach (Db::rows('SELECT c.langue l, COUNT(*) n FROM ceo_nl_contact c WHERE ' . $sql . ' GROUP BY c.langue', $p) as $r) {
        $i = array_search($r['l'], NL_LANGUES, true);
        $out['total'] += (int) $r['n'];
        if ($i !== false) { $out['split'][$i] += (int) $r['n']; }
    }
    return $out;
}

/**
 * POST /newsletter/contacts/import — { role, source, shop?, lot?, csv }
 * CSV avec en-tête (séparateur , ; ou tab) : email, prenom, nom, langue, magasin,
 * telephone, optin, optin_sms, dernier_achat, panier, produits, anniversaire, cree_le.
 * Seule la marque importe. Une adresse déjà connue de la base est MISE À JOUR.
 */
function wr_newsletter_contacts_import(): array
{
    ensureNewsletter();
    if (nlRole() !== 'brand') { http_response_code(403); return ['error' => 'Seule la marque importe des contacts.']; }
    $b = body();
    $src = (string) ($b['source'] ?? 'indiv');
    if (!in_array($src, NL_SOURCES_ID, true)) { http_response_code(422); return ['error' => 'base inconnue']; }
    $shopDefaut = mb_substr(trim((string) ($b['shop'] ?? '')), 0, 16);
    $csv = (string) ($b['csv'] ?? '');
    if (trim($csv) === '') { http_response_code(422); return ['error' => 'csv attendu']; }
    $lot = mb_substr(trim((string) ($b['lot'] ?? '')), 0, 40);
    if ($lot === '') { $lot = 'lot-' . date('Ymd-His'); }
    $lignes = preg_split('/\r\n|\n|\r/', trim($csv));
    if (count($lignes) < 2) { http_response_code(422); return ['error' => 'CSV : en-tête puis au moins une ligne']; }
    $sep = substr_count($lignes[0], ';') >= substr_count($lignes[0], ',') ? (substr_count($lignes[0], "\t") > substr_count($lignes[0], ';') ? "\t" : ';') : ',';
    $ent = array_map(fn ($h) => strtolower(trim(str_replace(['é', 'è', 'ê'], 'e', (string) $h))), str_getcsv($lignes[0], $sep, '"', '\\'));
    $col = fn (string $n) => array_search($n, $ent, true);
    $iEmail = $col('email') !== false ? $col('email') : ($col('e-mail') !== false ? $col('e-mail') : $col('mail'));
    if ($iEmail === false) { http_response_code(422); return ['error' => 'colonne « email » introuvable dans l’en-tête']; }
    $magasins = [];
    foreach (Db::rows('SELECT shop_id, nom FROM ceo_nl_magasin WHERE shop_id <> "brand"') as $m) { $magasins[strtolower((string) $m['nom'])] = (string) $m['shop_id']; $magasins[(string) $m['shop_id']] = (string) $m['shop_id']; }
    $faits = 0; $ignores = 0; $maj = 0;
    $date = function (?string $v): ?string {
        $v = trim((string) $v); if ($v === '') { return null; }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) { return "$m[1]-$m[2]-$m[3]"; }
        if (preg_match('/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})/', $v, $m)) { return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]); }
        return null;
    };
    $oui = fn ($v) => $v === null || $v === '' ? 1 : (preg_match('/^(1|oui|yes|true|y|o|x)$/i', trim((string) $v)) ? 1 : 0);
    foreach (array_slice($lignes, 1, 20000) as $ligne) {
        if (trim($ligne) === '') { continue; }
        $c = str_getcsv($ligne, $sep, '"', '\\');
        $email = strtolower(trim((string) ($c[$iEmail] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $ignores++; continue; }
        $v = fn (string $n) => ($i = $col($n)) !== false ? trim((string) ($c[$i] ?? '')) : '';
        $langue = strtolower(substr($v('langue') ?: 'fr', 0, 2)); if (!in_array($langue, NL_LANGUES, true)) { $langue = 'fr'; }
        $mag = strtolower($v('magasin')); $shop = $mag !== '' ? ($magasins[$mag] ?? $shopDefaut) : $shopDefaut;
        $existe = Db::row('SELECT id FROM ceo_nl_contact WHERE source_id = ? AND email = ?', [$src, $email]);
        $panier = $v('panier') !== '' ? (float) str_replace(',', '.', $v('panier')) : null;
        Db::exec('INSERT INTO ceo_nl_contact (source_id, shop_id, email, telephone, prenom, nom, langue, optin, optin_sms, dernier_achat, panier, produits, anniversaire, cree_le, token, lot, importe_le)'
            . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
            . ' ON DUPLICATE KEY UPDATE shop_id = VALUES(shop_id), telephone = VALUES(telephone), prenom = VALUES(prenom), nom = VALUES(nom), langue = VALUES(langue), optin = VALUES(optin), optin_sms = VALUES(optin_sms),'
            . ' dernier_achat = VALUES(dernier_achat), panier = VALUES(panier), produits = VALUES(produits), anniversaire = VALUES(anniversaire), cree_le = COALESCE(VALUES(cree_le), cree_le), lot = VALUES(lot), importe_le = NOW()',
            [$src, $shop, $email, mb_substr($v('telephone') ?: $v('tel') ?: $v('gsm'), 0, 32), mb_substr($v('prenom'), 0, 80), mb_substr($v('nom'), 0, 80), $langue,
                $oui($v('optin') ?: $v('opt-in') ?: null), $v('optin_sms') !== '' ? $oui($v('optin_sms')) : 0,
                $date($v('dernier_achat') ?: $v('dernier achat')), $panier, mb_substr(strtolower(str_replace(' ', '', $v('produits'))), 0, 255),
                $date($v('anniversaire')), $date($v('cree_le') ?: $v('cree le')) ?? date('Y-m-d'), bin2hex(random_bytes(16)), $lot]);
        if ($existe) { $maj++; } else { $faits++; }
    }
    journalAdd('Marque', 'Newsletter', null, "Contacts importés ($src, lot $lot) : $faits nouveaux, $maj mis à jour, $ignores ignorés");
    return ['ok' => true, 'lot' => $lot, 'nouveaux' => $faits, 'misAJour' => $maj, 'ignores' => $ignores, 'contacts' => nlContactsParSource()];
}

/** GET /newsletter/contacts/lots — les lots importés, pour les retirer. */
function ep_newsletter_lots(): array
{
    ensureNewsletter();
    return ['lots' => array_map(fn ($r) => ['lot' => $r['lot'], 'source' => $r['source_id'], 'n' => (int) $r['n'], 'le' => $r['le']],
        Db::rows('SELECT lot, source_id, COUNT(*) n, MAX(importe_le) le FROM ceo_nl_contact GROUP BY lot, source_id ORDER BY le DESC LIMIT 60'))];
}

/** DELETE /newsletter/contacts/lots/{lot} — retire un lot (et ses envois de suivi restent, anonymes). */
function wr_newsletter_lot_delete(string $lot): array
{
    ensureNewsletter();
    if (nlRole() !== 'brand') { http_response_code(403); return ['error' => 'marque seule']; }
    $n = Db::exec('DELETE FROM ceo_nl_contact WHERE lot = ?', [$lot]);
    journalAdd('Marque', 'Newsletter', null, "Lot de contacts retiré : $lot ($n)");
    return ['ok' => true, 'retires' => $n, 'contacts' => nlContactsParSource()];
}

/* ---------------------------------------------------------------------------
 * Rendu du mail
 * ------------------------------------------------------------------------- */
function nlBase(): string { return rapBaseUrl(); }

function nlTexte(array $camp, string $champ, string $lang): string
{
    $j = nlJson($camp[$champ] ?? null, []);
    if (!is_array($j)) { return ''; }
    return (string) ($j[$lang] ?? $j['fr'] ?? reset($j) ?: '');
}

/** Le HTML d'un mail : en-tête rubis, visuel, titre, corps, bouton, pied — styles en ligne, images en URL absolue. */
function nlRendre(array $camp, string $lang, array $contact, ?string $voucher, ?string $token): string
{
    $base = nlBase();
    $img = (string) ($camp['image'] ?? '');
    $exp = Db::row('SELECT * FROM ceo_nl_magasin WHERE shop_id = ?', [$camp['sender_shop_id']]) ?: ['sender_name' => "L'Atelier By", 'sender_email' => '', 'nom' => ''];
    $lien = $token ? $base . '/api/cockpit/newsletter/c/' . $token : $base;
    $unsub = $token ? $base . '/api/cockpit/newsletter/u/' . $token : '#';
    $pixel = $token ? '<img src="' . htmlspecialchars($base . '/api/cockpit/newsletter/o/' . $token, ENT_QUOTES) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">' : '';
    $sub = function (string $t) use ($contact, $voucher, $lien, $base): string {
        return str_replace(['{{prénom}}', '{{prenom}}', '{{code_promo}}', '{{code}}', '{{lien_webshop}}', '{{lien_boutique}}', '{{lien}}'],
            [(string) ($contact['prenom'] ?: ''), (string) ($contact['prenom'] ?: ''), (string) ($voucher ?: ''), (string) ($voucher ?: ''), $lien, $lien, $lien], $t);
    };
    $corps = trim($sub(nlTexte($camp, 'bodies_json', $lang)));
    $corps = preg_replace('/^\s*(Bonjour|Dag|Hello)\s*,/u', '$1 ,', $corps);   // « Bonjour , » quand le prénom manque
    $corps = str_replace(' ,', ',', (string) $corps);
    $paras = array_filter(array_map('trim', preg_split('/\n\s*\n/', $corps)));
    $htmlCorps = implode('', array_map(fn ($p) => '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#222222">' . nl2br(htmlspecialchars($p, ENT_QUOTES)) . '</p>', $paras));
    $titre = htmlspecialchars($sub(nlTexte($camp, 'headlines_json', $lang)), ENT_QUOTES);
    $cta = htmlspecialchars($sub(nlTexte($camp, 'ctas_json', $lang)) ?: 'Voir', ENT_QUOTES);
    $pied = ['fr' => ['Webshop', 'Votre boutique', 'Ouvert du mardi au dimanche, 7h – 18h30', 'Se désinscrire'], 'nl' => ['Webshop', 'Uw winkel', 'Open van dinsdag tot zondag, 7u – 18u30', 'Uitschrijven'], 'en' => ['Webshop', 'Your shop', 'Open Tuesday to Sunday, 7am – 6:30pm', 'Unsubscribe']][$lang] ?? ['Webshop', 'Votre boutique', '', 'Se désinscrire'];
    $badge = ($camp['template_id'] ?? '') === 'fidelite' ? '<p style="margin:16px 0 0"><span style="display:inline-block;background:#F2C9A0;color:#6b4420;border-radius:999px;padding:5px 12px;font-size:11px;font-weight:bold">'
        . htmlspecialchars(['fr' => 'Votre 3e mail · fidélité débloquée', 'nl' => 'Uw 3e mail · trouwvoordeel ontgrendeld', 'en' => 'Your 3rd email · loyalty unlocked'][$lang] ?? '', ENT_QUOTES) . '</span></p>' : '';
    $voucherHtml = $voucher ? '<p style="margin:16px 0 0;font-size:13px;color:#666666">' . (['fr' => 'Votre code', 'nl' => 'Uw code', 'en' => 'Your code'][$lang] ?? 'Code') . ' : <strong style="font-size:16px;color:#8D1D2C;letter-spacing:.08em">' . htmlspecialchars($voucher, ENT_QUOTES) . '</strong></p>' : '';
    return '<!DOCTYPE html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . htmlspecialchars($sub(nlTexte($camp, 'subjects_json', $lang)), ENT_QUOTES) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#EAE4DC;font-family:Helvetica,Arial,sans-serif;color:#222222">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#EAE4DC"><tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#FFFFFF;border-radius:14px;overflow:hidden">'
        . '<tr><td style="background:#8D1D2C;padding:18px;text-align:center"><img src="' . htmlspecialchars($base . '/assets/img/newsletter/logo-white.png', ENT_QUOTES) . '" alt="L\'Atelier By" height="28" style="height:28px;width:auto;display:inline-block"></td></tr>'
        . ($img !== '' ? '<tr><td style="padding:26px 24px 8px;text-align:center"><img src="' . htmlspecialchars($base . '/assets/img/newsletter/' . $img, ENT_QUOTES) . '" alt="" height="120" style="height:120px;width:auto;display:inline-block"></td></tr>' : '')
        . '<tr><td style="padding:10px 28px 24px">'
        . ($titre !== '' ? '<h1 style="margin:0 0 12px;font-family:Georgia,serif;font-weight:normal;font-size:24px;line-height:1.2;color:#8D1D2C">' . $titre . '</h1>' : '')
        . $htmlCorps
        . '<p style="margin:20px 0 0"><a href="' . htmlspecialchars($lien, ENT_QUOTES) . '" style="display:inline-block;background:#8D1D2C;color:#FFFFFF;text-decoration:none;border-radius:8px;padding:12px 20px;font-size:14px;font-weight:bold">' . $cta . '</a></p>'
        . $voucherHtml . $badge
        . '</td></tr>'
        . '<tr><td style="padding:14px 28px 20px;border-top:1px solid #EEE8E0;font-size:11px;line-height:1.7;color:#666666">'
        . htmlspecialchars($exp['sender_name'] . ($exp['nom'] && $exp['nom'] !== $exp['sender_name'] ? ' · ' . $exp['nom'] : ''), ENT_QUOTES) . '<br>'
        . htmlspecialchars($pied[0] . ' · ' . $pied[1], ENT_QUOTES) . '<br>' . htmlspecialchars($pied[2], ENT_QUOTES)
        . '<br><a href="' . htmlspecialchars($unsub, ENT_QUOTES) . '" style="color:#666666;text-decoration:underline">' . htmlspecialchars($pied[3], ENT_QUOTES) . '</a>'
        . '</td></tr></table>' . $pixel . '</td></tr></table></body></html>';
}

/** L'expéditeur affiché : le magasin si son domaine est celui du compte SMTP, sinon le compte SMTP au nom du magasin. */
function nlExpediteur(array $camp): string
{
    $exp = Db::row('SELECT * FROM ceo_nl_magasin WHERE shop_id = ?', [$camp['sender_shop_id']]);
    $smtp = Smtp::config()['expediteur'];
    $compte = preg_match('/<([^>]+)>/', $smtp, $m) ? $m[1] : $smtp;
    $domaineCompte = strtolower((string) substr(strrchr($compte, '@') ?: '', 1));
    $nom = $exp ? (string) $exp['sender_name'] : "L'Atelier By";
    $mail = $exp ? strtolower((string) $exp['sender_email']) : '';
    if ($mail !== '' && $domaineCompte !== '' && str_ends_with($mail, '@' . $domaineCompte)) { return $nom . ' <' . $mail . '>'; }
    return $nom . ' <' . $compte . '>';
}

/* ---------------------------------------------------------------------------
 * Vouchers
 * ------------------------------------------------------------------------- */
function nlVoucherCode(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // ni 0/O ni 1/I
    do { $c = ''; for ($i = 0; $i < 8; $i++) { $c .= $alphabet[random_int(0, 31)]; } }
    while (Db::row('SELECT 1 FROM ceo_nl_voucher WHERE code = ?', [$c]) !== null);
    return $c;
}
function nlVoucherPour(array $camp, int $contactId): ?string
{
    $max = (int) ($camp['max_vouchers'] ?? 0);
    if ($max <= 0) { return null; }
    $n = (int) (Db::row('SELECT COUNT(*) n FROM ceo_nl_voucher WHERE campagne_id = ?', [(int) $camp['id']])['n'] ?? 0);
    if ($n >= $max) { return null; }   // premier arrivé, premier servi
    $code = nlVoucherCode();
    Db::exec('INSERT INTO ceo_nl_voucher (code, campagne_id, contact_id, cree_le, shop_id) VALUES (?,?,?,NOW(),?)', [$code, (int) $camp['id'], $contactId, $camp['sender_shop_id'] === 'brand' ? null : $camp['sender_shop_id']]);
    return $code;
}
/** POST /newsletter/vouchers/{code}/utiliser — la boutique encaisse le voucher. */
function wr_newsletter_voucher_utiliser(string $code): array
{
    ensureNewsletter();
    $code = strtoupper(trim($code));
    $v = Db::row('SELECT * FROM ceo_nl_voucher WHERE code = ?', [$code]);
    if ($v === null) { http_response_code(404); return ['error' => 'code inconnu']; }
    if ($v['utilise_le'] !== null) { http_response_code(409); return ['error' => 'déjà utilisé le ' . $v['utilise_le']]; }
    $role = nlRole();
    Db::exec('UPDATE ceo_nl_voucher SET utilise_le = NOW(), shop_id = COALESCE(?, shop_id) WHERE code = ?', [$role !== 'brand' ? $role : null, $code]);
    return ['ok' => true, 'code' => $code, 'campagne' => (int) $v['campagne_id']];
}

/* ---------------------------------------------------------------------------
 * Envoi
 * ------------------------------------------------------------------------- */
/** Les contacts d'une campagne pas encore servis, dans ses langues, sans autre mail aujourd'hui. */
function nlDestinataires(array $camp, int $n): array
{
    $seg = Db::row('SELECT * FROM ceo_nl_segment WHERE id = ?', [$camp['segment_id']]);
    if ($seg === null) { return []; }
    [$sql, $p] = nlSegmentSql($seg);
    $langs = array_values(array_intersect(NL_LANGUES, explode(',', (string) $camp['langues'])));
    if ($langs === []) { return []; }
    $sql .= ' AND c.langue IN (' . implode(',', array_fill(0, count($langs), '?')) . ')';
    $p = array_merge($p, $langs);
    $p[] = (int) $camp['id'];
    return Db::rows('SELECT c.* FROM ceo_nl_contact c WHERE ' . $sql
        . ' AND NOT EXISTS (SELECT 1 FROM ceo_nl_envoi e WHERE e.campagne_id = ? AND e.contact_id = c.id)'
        . ' AND NOT EXISTS (SELECT 1 FROM ceo_nl_envoi e2 WHERE e2.contact_id = c.id AND e2.sent_at >= CURDATE())'
        . ' ORDER BY c.id LIMIT ' . max(1, min(NL_LOT_MAX, $n)), $p);
}

/** Un mail à un contact : rendu, envoi, trace. Rend true si parti. */
function nlEnvoyerUn(array $camp, array $c): bool
{
    $lang = in_array($c['langue'], NL_LANGUES, true) ? $c['langue'] : 'fr';
    $token = bin2hex(random_bytes(16));
    $voucher = nlVoucherPour($camp, (int) $c['id']);
    $html = nlRendre($camp, $lang, $c, $voucher, $token);
    $sujet = str_replace(['{{prénom}}', '{{prenom}}'], (string) $c['prenom'], nlTexte($camp, 'subjects_json', $lang));
    $ok = Smtp::envoyer((string) $c['email'], $sujet, $html, [], nlExpediteur($camp));
    Db::exec('INSERT INTO ceo_nl_envoi (campagne_id, contact_id, langue, token, sent_at, voucher, erreur) VALUES (?,?,?,?,NOW(),?,?)',
        [(int) $camp['id'], (int) $c['id'], $lang, $token, $voucher, $ok ? null : mb_substr((string) (Smtp::$lastError ?? 'échec'), 0, 200)]);
    return $ok;
}

/** Fait partir un lot d'une campagne (100 par minute) ; rend [envoyés, échecs, restants]. */
function nlEnvoyerLot(array $camp, int $max = NL_LOT_MAX): array
{
    $dest = nlDestinataires($camp, $max);
    $env = 0; $ko = 0; $debut = microtime(true); $i = 0;
    foreach ($dest as $c) {
        if (nlEnvoyerUn($camp, $c)) { $env++; } else { $ko++; }
        $i++;
        // cadence : au plus NL_CADENCE par minute
        $attendu = $i / NL_CADENCE * 60;
        $ecoule = microtime(true) - $debut;
        if ($attendu > $ecoule) { usleep((int) (($attendu - $ecoule) * 1000000)); }
        if ($ko >= 20 && $env === 0) { break; }   // le SMTP refuse tout : on n'insiste pas
    }
    $reste = count(nlDestinataires($camp, 1));
    return [$env, $ko, $reste];
}

/** Les chiffres d'une campagne depuis ses envois ; null si aucun envoi (le jeu d'essai reste). */
function nlStatsReelles(int $campId): ?array
{
    $r = Db::row('SELECT COUNT(*) n, SUM(erreur IS NULL) ok, SUM(opened_at IS NOT NULL) o, SUM(clicked_at IS NOT NULL) c FROM ceo_nl_envoi WHERE campagne_id = ?', [$campId]);
    if ((int) ($r['n'] ?? 0) === 0) { return null; }
    $v = Db::row('SELECT COUNT(*) n, SUM(utilise_le IS NOT NULL) u FROM ceo_nl_voucher WHERE campagne_id = ?', [$campId]);
    return ['sent' => (int) $r['ok'], 'echecs' => (int) $r['n'] - (int) $r['ok'], 'open' => (int) $r['o'], 'click' => (int) $r['c'], 'vouchers' => (int) ($v['u'] ?? 0), 'vouchersEmis' => (int) ($v['n'] ?? 0), 'revenue' => 0, 'reel' => true];
}

/**
 * L'horloge — GET /newsletter/cron?jeton=… (cron, toutes les 5 minutes) et
 * POST /newsletter/tick (la marque, à la demande). En mode test : ne fait
 * rien partir, dit ce qui attend.
 */
function nlHorloge(): array
{
    ensureNewsletter();
    $out = ['dispatch' => nlDispatch(), 'smtp' => Smtp::configured(), 'campagnes' => [], 'automatisations' => []];
    nlReglagePoser('nlCronDernier', date('Y-m-d H:i:s'));
    $dues = Db::rows('SELECT * FROM ceo_nl_campagne WHERE send_mode = "manual" AND statut IN ("sched", "live") AND send_at <= NOW() AND exemple = 0 ORDER BY send_at, id');
    if (!nlDispatch() || !Smtp::configured()) {
        $out['attente'] = array_map(fn ($c) => ['id' => (int) $c['id'], 'nom' => $c['nom'], 'sendAt' => $c['send_at']], $dues);
        $out['motif'] = !Smtp::configured() ? 'SMTP non configuré' : 'mode test : aucun envoi ne part';
        return $out;
    }
    $budget = NL_LOT_MAX;
    foreach ($dues as $camp) {
        if ($budget <= 0) { break; }
        if ($camp['statut'] === 'sched') {
            Db::exec('UPDATE ceo_nl_campagne SET statut = "live", started_at = NOW(), updated_at = NOW() WHERE id = ?', [(int) $camp['id']]);
            journalAdd('Horloge', 'Newsletter', null, 'Campagne en cours d’envoi : ' . $camp['nom']);
        }
        [$env, $ko, $reste] = nlEnvoyerLot($camp, $budget);
        $budget -= ($env + $ko);
        if ($reste === 0) {
            Db::exec('UPDATE ceo_nl_campagne SET statut = "sent", finished_at = NOW(), updated_at = NOW() WHERE id = ?', [(int) $camp['id']]);
            journalAdd('Horloge', 'Newsletter', null, 'Campagne envoyée : ' . $camp['nom']);
        }
        $out['campagnes'][] = ['id' => (int) $camp['id'], 'nom' => $camp['nom'], 'envoyes' => $env, 'echecs' => $ko, 'reste' => $reste];
    }
    // Automatisations : une fois par jour, la nuit (ou au premier passage du jour)
    foreach (Db::rows('SELECT * FROM ceo_nl_campagne WHERE send_mode = "auto" AND statut = "live" AND exemple = 0') as $camp) {
        if ((string) ($camp['auto_dernier'] ?? '') === date('Y-m-d')) { continue; }
        if ($budget <= 0) { break; }
        $dest = nlAutoDestinataires($camp, $budget);
        $env = 0; $ko = 0;
        foreach ($dest as $c) { if (nlEnvoyerUn($camp, $c)) { $env++; } else { $ko++; } usleep(600000); }
        $budget -= ($env + $ko);
        Db::exec('UPDATE ceo_nl_campagne SET auto_dernier = CURDATE(), updated_at = NOW() WHERE id = ?', [(int) $camp['id']]);
        $out['automatisations'][] = ['id' => (int) $camp['id'], 'nom' => $camp['nom'], 'declencheur' => $camp['trigger_rule'], 'envoyes' => $env, 'echecs' => $ko];
    }
    return $out;
}

/** Les contacts que la règle d'une automatisation désigne AUJOURD'HUI (chacun une fois, 1 mail par jour). */
function nlAutoDestinataires(array $camp, int $n): array
{
    $seg = Db::row('SELECT * FROM ceo_nl_segment WHERE id = ?', [$camp['segment_id']]);
    if ($seg === null) { return []; }
    [$sql, $p] = nlSegmentSql($seg);
    $regle = '';
    switch ((string) $camp['trigger_rule']) {
        case 'dormant45': $regle = 'c.dernier_achat = DATE_SUB(CURDATE(), INTERVAL 45 DAY)'; break;
        case 'birthday': $regle = 'c.anniversaire IS NOT NULL AND DATE_FORMAT(c.anniversaire, "%m-%d") = DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 3 DAY), "%m-%d")'; break;
        case 'firstOffice': $regle = 'c.source_id = "office" AND c.cree_le = DATE_SUB(CURDATE(), INTERVAL 2 DAY)'; break;
        case 'third': $regle = '(SELECT COUNT(*) FROM ceo_nl_envoi e3 WHERE e3.contact_id = c.id AND e3.erreur IS NULL) = 2'; break;
        case 'season': $regle = 'DATE(?) = DATE_ADD(CURDATE(), INTERVAL 7 DAY)'; $p[] = (string) $camp['send_at']; break;
        default: return [];
    }
    $langs = array_values(array_intersect(NL_LANGUES, explode(',', (string) $camp['langues'])));
    if ($langs === []) { return []; }
    $sql .= ' AND ' . $regle . ' AND c.langue IN (' . implode(',', array_fill(0, count($langs), '?')) . ')';
    $p = array_merge($p, $langs);
    $p[] = (int) $camp['id'];
    return Db::rows('SELECT c.* FROM ceo_nl_contact c WHERE ' . $sql
        . ' AND NOT EXISTS (SELECT 1 FROM ceo_nl_envoi e WHERE e.campagne_id = ? AND e.contact_id = c.id)'
        . ' AND NOT EXISTS (SELECT 1 FROM ceo_nl_envoi e2 WHERE e2.contact_id = c.id AND e2.sent_at >= CURDATE())'
        . ' ORDER BY c.id LIMIT ' . max(1, min(NL_LOT_MAX, $n)), $p);
}

function ep_newsletter_cron(): array
{
    ensureNewsletter();
    $jeton = (string) setting('nlJeton', '');
    if ($jeton === '' || !hash_equals($jeton, (string) ($_GET['jeton'] ?? ''))) { http_response_code(403); return ['error' => 'jeton absent ou invalide']; }
    set_time_limit(360);
    return nlHorloge();
}

/** POST /newsletter/tick — la marque fait passer l'horloge maintenant. */
function wr_newsletter_tick(): array
{
    if (nlRole() !== 'brand') { http_response_code(403); return ['error' => 'marque seule']; }
    set_time_limit(360);
    return nlHorloge();
}

/** POST /newsletter/campagnes/{id}/envoyer — « envoyer maintenant » : la date passe à maintenant, l'horloge fait le reste. */
function wr_newsletter_campagne_envoyer(int $id): array
{
    ensureNewsletter();
    $role = nlRole();
    $c = Db::row('SELECT * FROM ceo_nl_campagne WHERE id = ?', [$id]);
    if ($c === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    if ($role !== 'brand' && $c['created_by'] !== $role) { http_response_code(403); return ['error' => "Cette campagne n'est pas la vôtre."]; }
    if ($c['statut'] === 'sent') { http_response_code(409); return ['error' => 'déjà envoyée']; }
    if ($c['send_mode'] === 'auto') { http_response_code(409); return ['error' => 'une automatisation part par sa règle, pas à la demande']; }
    Db::exec('UPDATE ceo_nl_campagne SET statut = "sched", send_at = NOW(), updated_at = NOW() WHERE id = ?', [$id]);
    $h = nlHorloge();
    $r = Db::row('SELECT * FROM ceo_nl_campagne WHERE id = ?', [$id]);
    return ['ok' => true, 'campagne' => $r ? nlCampagneRow($r) : null, 'horloge' => $h];
}

/** PUT /newsletter/reglages — { dispatch?, testAdresses?, domaine? } (marque). */
function wr_newsletter_reglages(): array
{
    ensureNewsletter();
    if (nlRole() !== 'brand') { http_response_code(403); return ['error' => 'marque seule']; }
    $b = body();
    if (array_key_exists('dispatch', $b)) {
        $on = !empty($b['dispatch']);
        if ($on && !Smtp::configured()) { http_response_code(422); return ['error' => 'Dispatcher demande un SMTP configuré (Paramètres → E-mail).']; }
        nlReglagePoser('nlDispatch', $on);
        journalAdd('Marque', 'Newsletter', null, $on ? 'Envois DISPATCHÉS : les campagnes partent vraiment' : 'Retour en mode test : plus aucun envoi ne part');
    }
    if (array_key_exists('testAdresses', $b)) {
        $l = is_array($b['testAdresses']) ? $b['testAdresses'] : preg_split('/[,;\s]+/', (string) $b['testAdresses']);
        $l = array_values(array_filter(array_map(fn ($a) => trim((string) $a), $l), fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
        nlReglagePoser('nlTestAdresses', array_slice($l, 0, 3));
    }
    if (array_key_exists('domaine', $b)) {
        $d = strtolower(trim((string) $b['domaine']));
        if ($d !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d)) { http_response_code(422); return ['error' => 'domaine invalide']; }
        nlReglagePoser('nlDomaine', $d);
    }
    return ['ok' => true, 'reglages' => nlReglages()];
}

/* ---------------------------------------------------------------------------
 * Suivi : ouverture, clic, désinscription — routes publiques, sortie non JSON
 * ------------------------------------------------------------------------- */
function nlSuivi(string $quoi, string $token): void
{
    ensureNewsletter();
    $token = preg_match('/^[a-f0-9]{32}$/', $token) ? $token : '';
    $e = $token !== '' ? Db::row('SELECT * FROM ceo_nl_envoi WHERE token = ?', [$token]) : null;
    if ($quoi === 'o') {
        if ($e && $e['opened_at'] === null) { Db::exec('UPDATE ceo_nl_envoi SET opened_at = NOW() WHERE id = ?', [(int) $e['id']]); }
        header('Content-Type: image/gif'); header('Cache-Control: no-store, private'); header('Pragma: no-cache');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }
    if ($quoi === 'c') {
        $cible = rapBaseUrl();
        if ($e) {
            if ($e['clicked_at'] === null) { Db::exec('UPDATE ceo_nl_envoi SET clicked_at = NOW(), opened_at = COALESCE(opened_at, NOW()) WHERE id = ?', [(int) $e['id']]); }
            $camp = Db::row('SELECT sender_shop_id FROM ceo_nl_campagne WHERE id = ?', [(int) $e['campagne_id']]);
            $lien = (string) setting('nlLienWebshop', '');
            if ($lien !== '') { $cible = $lien; }
        }
        header('Location: ' . ($cible !== '' ? $cible : '/'), true, 302);
        exit;
    }
    // désinscription : un clic, pas de question
    $ok = false;
    if ($e) { Db::exec('UPDATE ceo_nl_contact SET desinscrit_le = NOW(), optin = 0, optin_sms = 0 WHERE id = ?', [(int) $e['contact_id']]); $ok = true; }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>L\'Atelier By</title></head>'
        . '<body style="margin:0;background:#EAE4DC;font-family:Helvetica,Arial,sans-serif;color:#222"><div style="max-width:480px;margin:60px auto;background:#fff;border-radius:14px;padding:32px 28px;text-align:center">'
        . '<div style="font-family:Georgia,serif;font-size:22px;color:#8D1D2C;margin-bottom:10px">' . ($ok ? 'C’est noté.' : 'Lien inconnu') . '</div>'
        . '<p style="font-size:14px;line-height:1.6;color:#666;margin:0">' . ($ok ? 'Vous ne recevrez plus nos nouvelles par e-mail ni par SMS. Vous restez le bienvenu en boutique.' : 'Ce lien de désinscription n’est pas valable. Écrivez-nous et nous nous en occupons.') . '</p>'
        . '</div></body></html>';
    exit;
}

/** POST /newsletter/test — le mail rendu part aux adresses de test (SMTP), même en mode test. */
function nlEnvoyerTest(array $b, string $role): array
{
    $adresses = nlAdressesTest();
    if (!Smtp::configured() || $adresses === []) { return ['ok' => true, 'simule' => true, 'adresses' => 0, 'motif' => Smtp::configured() ? 'aucune adresse de test' : 'SMTP non configuré']; }
    $lang = in_array($b['lang'] ?? '', NL_LANGUES, true) ? $b['lang'] : 'fr';
    $camp = ['id' => 0, 'sender_shop_id' => mb_substr((string) ($b['sender'] ?? $role), 0, 16), 'template_id' => (string) ($b['templateId'] ?? 'blank'), 'image' => nlImage((string) ($b['templateId'] ?? '')),
        'subjects_json' => json_encode([$lang => (string) ($b['subject'] ?? '')], JSON_UNESCAPED_UNICODE), 'bodies_json' => json_encode([$lang => (string) ($b['body'] ?? '')], JSON_UNESCAPED_UNICODE),
        'headlines_json' => json_encode([$lang => (string) ($b['headline'] ?? '')], JSON_UNESCAPED_UNICODE), 'ctas_json' => json_encode([$lang => (string) ($b['cta'] ?? '')], JSON_UNESCAPED_UNICODE), 'max_vouchers' => 0];
    $contact = ['prenom' => 'Marie', 'email' => ''];
    $html = nlRendre($camp, $lang, $contact, !empty($b['voucher']) ? 'TEST1234' : null, null);
    $sujet = '[TEST] ' . str_replace(['{{prénom}}', '{{prenom}}'], 'Marie', (string) ($b['subject'] ?? ''));
    $partis = 0; $erreur = null;
    foreach ($adresses as $a) { if (Smtp::envoyer($a, $sujet, $html, [], nlExpediteur($camp))) { $partis++; } else { $erreur = Smtp::$lastError; } }
    journalAdd($role === 'brand' ? 'Marque' : 'Magasin ' . $role, 'Newsletter', null, 'Test envoyé à ' . $partis . ' adresse(s) : ' . ($b['subject'] ?? ''));
    return ['ok' => $partis > 0, 'simule' => false, 'adresses' => $partis, 'erreur' => $erreur];
}

/** L'illustration d'un modèle (fichier de assets/img/newsletter). */
function nlImage(string $templateId): string
{
    return ['produit' => 'bread.png', 'happy' => 'croissant.png', 'fidelite' => 'cake.png', 'gagne' => 'tart.png', 'office' => 'sandwiches.png'][$templateId] ?? '';
}
