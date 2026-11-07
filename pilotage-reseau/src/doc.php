<?php
declare(strict_types=1);

/**
 * Gabarit commun des documents — design system « L'Atelier By », §13
 * (docs/design-system/DESIGN_SYSTEM.md), VERSION A retenue le 06/11/2026.
 *
 * Tout ce qui sort de l'écran passe par ici : PDF composés par le serveur
 * (wkhtmltopdf, Chromium en repli) et e-mails. Une valeur change dans le
 * design system, elle change ici — les documents n'écrivent plus leurs
 * couleurs, polices ni en-têtes à la main.
 *
 * Règles tenues :
 * - une seule couleur de marque, le Ruby, à trois endroits : filet d'en-tête,
 *   total dû / bouton, filet d'un encadré ;
 * - papier blanc, ni ombre, ni dégradé, ni bandeau, ni pilule ;
 * - Gotham (400 = fichier Medium, 700 = fichier Regular), repli Helvetica /
 *   Arial — les messageries ignorent les polices web ;
 * - toute donnée vient du système : une mention légale absente n'est pas
 *   remplacée par un exemple, elle n'est pas écrite.
 */

const DOC_INK = '#1c1a17';
const DOC_MUTED = '#7a736a';
const DOC_FAINT = '#b8b2a8';
const DOC_LINE = '#e6e0d8';
const DOC_ROW = 'rgba(34,34,34,.06)';
const DOC_HEAD = '#fbf9f5';
const DOC_RUBY = '#8D1D2C';
const DOC_GREEN = '#3f7a52';
const DOC_STOP = '#A32014';
/** Pile de polices des documents et e-mails (§13.2). Sans guillemets : elle se glisse dans n'importe quelle chaîne PHP ou attribut. */
const DOC_FONT = 'Gotham,Helvetica,Arial,sans-serif';

/**
 * Les @font-face des documents (§13.2) : 400 = Gotham_Medium (texte),
 * 700 = Gotham_Regular (accents), 300 = Light. En data-URI : le moteur PDF
 * tourne sans réseau et sans la base de l'application, et la même page
 * s'ouvre telle quelle dans un navigateur. `font-display: block` : on attend
 * la police avant d'imprimer.
 */
function docFontFaces(): string
{
    static $css = null;
    if ($css !== null) { return $css; }
    $dir = __DIR__ . '/../public/assets/ds/fonts/';
    $css = '';
    foreach ([300 => 'Gotham_Light.otf', 400 => 'Gotham_Medium.otf', 700 => 'Gotham_Regular.otf'] as $poids => $f) {
        $o = @file_get_contents($dir . $f);
        if ($o === false || $o === '') { continue; }
        $css .= "@font-face{font-family:'Gotham';src:url(data:font/otf;base64," . base64_encode($o)
            . ") format('opentype');font-weight:$poids;font-style:normal;font-display:block}";
    }
    return $css;
}

/**
 * Les mentions légales de l'émetteur — raison sociale, siège, numéro
 * d'entreprise, TVA, RPM, IBAN, BIC. Source, par priorité : le réglage
 * `emetteur` (ceo_app_setting), puis config/config.php ('emetteur').
 * Rien d'inventé : sans réglage, la liste est vide.
 *
 * @return array<string,string>
 */
function docEmetteur(): array
{
    $s = function_exists('setting') ? setting('emetteur', []) : [];
    $c = class_exists('Db') ? (Db::config()['emetteur'] ?? []) : [];
    $out = [];
    foreach (['raisonSociale', 'adresse', 'numeroEntreprise', 'tva', 'rpm', 'iban', 'bic', 'site', 'telephone'] as $k) {
        foreach ([is_array($s) ? ($s[$k] ?? null) : null, is_array($c) ? ($c[$k] ?? null) : null] as $v) {
            if (is_string($v) && trim($v) !== '') { $out[$k] = trim($v); break; }
        }
    }
    return $out;
}

/** La ligne de mentions du pied : « Raison sociale · adresse · BCE … · TVA … · RPM … ». Vide sans réglage. */
function docMentions(): string
{
    $m = docEmetteur();
    $p = [];
    if (isset($m['raisonSociale'])) { $p[] = $m['raisonSociale']; }
    if (isset($m['adresse'])) { $p[] = $m['adresse']; }
    if (isset($m['numeroEntreprise'])) { $p[] = 'N° d’entreprise ' . $m['numeroEntreprise']; }
    if (isset($m['tva'])) { $p[] = 'TVA ' . $m['tva']; }
    if (isset($m['rpm'])) { $p[] = 'RPM ' . $m['rpm']; }
    return implode(' · ', $p);
}

/** « 1 234,50 € » — espace fine insécable des milliers (U+202F), virgule, € après (§13.1). */
function docMontant(?float $v, int $dec = 2): string
{
    if ($v === null) { return '—'; }
    return str_replace(' ', "\u{202F}", number_format($v, $dec, ',', ' ')) . "\u{00A0}€";
}

/** « 06/11/2026 » (tableaux) ou « 6 novembre 2026 » (texte). */
function docDate(string $iso, bool $texte = false): string
{
    $t = strtotime($iso);
    if ($t === false) { return $iso; }
    if (!$texte) { return date('d/m/Y', $t); }
    $mois = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return (int) date('j', $t) . ' ' . $mois[(int) date('n', $t)] . ' ' . date('Y', $t);
}

/** Le logo en data-URI (PNG sur fond blanc) — partagé avec les rapports. */
function docLogo(): string
{
    return function_exists('rapLogoDataUri') ? rapLogoDataUri() : '';
}

/**
 * La feuille commune d'un document A4 (§13.4) — à poser dans <head>.
 * Gabarit à tables (wkhtmltopdf rend mal le flexbox).
 */
function docCss(): string
{
    return docFontFaces()
        . 'html,body{background:#fff}'
        . 'body{margin:0;font:400 11px/1.5 ' . "'Gotham',Helvetica,Arial,sans-serif" . ';color:' . DOC_INK . ';'
        . '-webkit-print-color-adjust:exact;print-color-adjust:exact}'
        . '.doc-head{width:100%;border-collapse:collapse;border-bottom:2px solid ' . DOC_RUBY . ';margin-bottom:22px}'
        . '.doc-head td{padding:0 0 12px;vertical-align:bottom}'
        . '.doc-type{text-align:right;font-size:10px;color:' . DOC_MUTED . ';line-height:1.5}'
        . '.doc-type b{display:block;font-size:15px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:' . DOC_INK . '}'
        . '.doc-label{font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:' . DOC_MUTED . ';margin-bottom:5px}'
        . '.doc-box{border-left:3px solid ' . DOC_RUBY . ';padding:2px 0 2px 12px;margin:24px 0 0}'
        . 'tr{page-break-inside:avoid}';
}

/**
 * L'en-tête d'une pièce (§13.4) : logo 34 px à gauche ; à droite le type de
 * pièce en capitales 700 et sa ligne de références ; filet Ruby de 2 px.
 */
function docEntete(string $type, string $refs = '', int $hauteurLogo = 34): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $logo = docLogo();
    return '<table class="doc-head" role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;border-bottom:2px solid ' . DOC_RUBY . ';margin-bottom:22px"><tr>'
        . '<td style="padding:0 0 12px;vertical-align:bottom">' . ($logo !== '' ? '<img src="' . $logo . '" alt="L’Atelier By" style="height:' . $hauteurLogo . 'px;display:block">' : '') . '</td>'
        . '<td class="doc-type" style="padding:0 0 12px;vertical-align:bottom;text-align:right;font-family:' . DOC_FONT . ';font-size:10px;color:' . DOC_MUTED . ';line-height:1.5">'
        . '<b style="display:block;font-size:15px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:' . DOC_INK . '">' . $e($type) . '</b>'
        . $e($refs) . '</td></tr></table>';
}

/* ==========================================================================
   E-MAILS — §13.6, version A
   Table de 600 px, styles en ligne uniquement, un seul bouton, pied relevé.
   ========================================================================== */

/** Un paragraphe de corps : 14 px, interligne 1,6, 12 px d'écart. */
function docMailP(string $html): string
{
    return '<p style="margin:0 0 12px;font-family:' . DOC_FONT . ';font-size:14px;line-height:1.6;color:' . DOC_INK . '">' . $html . '</p>';
}

/** Le bouton « à l'épreuve des messageries » — une seule action par message. */
function docMailBouton(string $libelle, string $url): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<table cellpadding="0" cellspacing="0" role="presentation" style="margin:6px 0 14px"><tr>'
        . '<td style="background:' . DOC_RUBY . ';border-radius:8px">'
        . '<a href="' . $e($url) . '" target="_blank" style="display:inline-block;padding:12px 22px;font-family:' . DOC_FONT . ';font-size:14px;font-weight:700;color:#ffffff;text-decoration:none">' . $e($libelle) . '</a>'
        . '</td></tr></table>';
}

/**
 * La signature (§13.6) : logo, filet vertical Ruby, trois lignes. Toutes les
 * valeurs viennent de la fiche de la personne ; une ligne vide disparaît
 * sans laisser de séparateur orphelin. Sans nom, pas de signature.
 *
 * @param array{nom?:string,fonction?:string,service?:string,telephone?:string,site?:string} $p
 */
function docMailSignature(array $p): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $nom = trim((string) ($p['nom'] ?? ''));
    if ($nom === '') { return ''; }
    $l2 = implode(' · ', array_filter([trim((string) ($p['fonction'] ?? '')), trim((string) ($p['service'] ?? ''))], 'strlen'));
    $site = trim((string) ($p['site'] ?? ''));
    $l3 = array_filter([$e(trim((string) ($p['telephone'] ?? ''))),
        $site !== '' ? '<a href="' . $e(preg_match('#^https?://#', $site) ? $site : 'https://' . $site) . '" style="color:' . DOC_RUBY . ';text-decoration:none">' . $e(preg_replace('#^https?://#', '', $site)) . '</a>' : ''], 'strlen');
    $logo = docLogo();
    return '<table cellpadding="0" cellspacing="0" role="presentation" style="margin-top:16px;font-family:' . DOC_FONT . '"><tr>'
        . ($logo !== '' ? '<td valign="middle" style="padding-right:12px;border-right:2px solid ' . DOC_RUBY . '"><img src="' . $logo . '" alt="L’Atelier By" height="24" style="height:24px;display:block"></td>' : '')
        . '<td valign="middle" style="padding-left:' . ($logo !== '' ? '12' : '0') . 'px;line-height:1.5">'
        . '<div style="font-size:13px;font-weight:700;color:' . DOC_INK . '">' . $e($nom) . '</div>'
        . ($l2 !== '' ? '<div style="font-size:11px;color:' . DOC_MUTED . '">' . $e($l2) . '</div>' : '')
        . ($l3 !== [] ? '<div style="font-size:11px;color:' . DOC_MUTED . '">' . implode(' · ', $l3) . '</div>' : '')
        . '</td></tr></table>';
}

/** Le pied relevé : qui envoie, pourquoi, mentions de l'émetteur. */
function docMailPied(string $pourquoi): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $m = docMentions();
    return '<tr><td style="padding:14px 0 0"><div style="border-top:1px solid ' . DOC_LINE . ';padding-top:9px;font-family:' . DOC_FONT
        . ';font-size:9px;line-height:1.6;color:' . DOC_MUTED . '">' . $pourquoi . ($m !== '' ? '<br>' . $e($m) : '') . '</div></td></tr>';
}

/**
 * Le message complet (§13.6) : fond blanc, marges 26 / 30 px, table de
 * 600 px ; en-tête logo 30 px à gauche, objet à droite en 10 px gris, filet
 * Ruby de 2 px ; puis le corps, puis le pied.
 *
 * @param array{sujet?:string,corps:string,pied?:string,service?:string,titre?:string} $o
 *   `corps` est du HTML déjà échappé ; `pied` aussi (lignes du pied relevé).
 */
function docMail(array $o): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $logo = docLogo();
    $sujet = (string) ($o['sujet'] ?? '');
    $droite = trim(implode(' · ', array_filter([(string) ($o['service'] ?? ''), $sujet], 'strlen')));
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light only">'
        . '<title>' . $e($sujet) . '</title>'
        . '<style>@media only screen and (max-width:640px){.doc-mail{width:100% !important}.doc-pad{padding:20px 16px !important}}</style>'
        . '</head><body style="margin:0;padding:0;background:#ffffff;color:' . DOC_INK . '">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff"><tr><td align="center" class="doc-pad" style="padding:26px 30px">'
        . '<table role="presentation" class="doc-mail" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%">'
        . '<tr><td style="border-bottom:2px solid ' . DOC_RUBY . ';padding:0 0 10px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td valign="bottom">' . ($logo !== '' ? '<img src="' . $logo . '" alt="L’Atelier By" height="30" style="height:30px;display:block">' : '<span style="font-family:' . DOC_FONT . ';font-size:14px;font-weight:700;color:' . DOC_INK . '">L’Atelier By</span>') . '</td>'
        . '<td valign="bottom" align="right" style="font-family:' . DOC_FONT . ';font-size:10px;color:' . DOC_MUTED . ';padding-left:12px">' . $e($droite) . '</td>'
        . '</tr></table></td></tr>'
        . '<tr><td style="padding:20px 0 0;font-family:' . DOC_FONT . ';font-size:14px;line-height:1.6;color:' . DOC_INK . '">'
        . (($o['titre'] ?? '') !== '' ? '<div style="font-size:21px;line-height:1.25;font-weight:700;color:' . DOC_INK . ';margin:0 0 12px">' . $e($o['titre']) . '</div>' : '')
        . $o['corps'] . '</td></tr>'
        . ($o['pied'] ?? '')
        . '</table></td></tr></table></body></html>';
}

/**
 * La version texte brut d'un e-mail HTML (§13.6) : les liens gardent leur
 * adresse, les cellules et paragraphes deviennent des lignes.
 */
function docMailTexte(string $html): string
{
    $h = (string) preg_replace('#<(head|style|script)\b.*?</\1>#is', '', $html);
    $h = (string) preg_replace('#<!--ecran-->.*?<!--/ecran-->#s', '', $h);
    $h = (string) preg_replace_callback('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', static function ($m) {
        $t = trim(strip_tags($m[2]));
        $u = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        if (str_starts_with($u, 'javascript:') || str_starts_with($u, 'cid:')) { return $t; }
        return $t === '' || $t === $u ? $u : $t . ' (' . $u . ')';
    }, $h);
    $h = (string) preg_replace('#<img\b[^>]*alt="([^"]*)"[^>]*>#i', '', $h);
    $h = (string) preg_replace('#<br\s*/?>#i', "\n", $h);
    $h = (string) preg_replace('#</(p|div|tr|h[1-6]|li|table)>#i', "\n", $h);
    $h = (string) preg_replace('#</t[dh]>#i', ' ', $h);
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = (string) preg_replace("/[ \t\u{00A0}]+/u", ' ', $t);
    $t = (string) preg_replace("/ *\n */", "\n", $t);
    $t = (string) preg_replace("/\n{3,}/", "\n\n", $t);
    return trim($t) . "\n";
}
