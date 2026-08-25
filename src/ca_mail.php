<?php
declare(strict_types=1);

/**
 * Centrale d'achat — e-mail « commande fournisseur ».
 *
 * Quand un franchisé passe commande (une réquisition matière naît côté panel),
 * la centrale veut un e-mail. L'ERP n'offre aucun déclencheur : la détection
 * se fait donc ici, au même rythme que les rapports (cron horaire), sur le
 * MÊME flux que l'écran des commandes — le SUIVI des commandes fournisseur,
 * ep_ca_achats() — pour ne jamais raconter autre chose que ce que l'écran
 * montre. On relance sur ce que le FOURNISSEUR n'a pas accepté, pas sur ce que
 * le magasin a demandé : ce sont deux objets, et deux responsabilités.
 *
 * Le template (sujet, corps, destinataire) vit dans
 * `ceo_app_setting.caMailCommande` et s'édite dans Paramètres, groupé avec
 * la machine SMTP ; l'enregistrement passe par le PUT /parametres/{key} générique.
 * Les réquisitions déjà annoncées vivent dans `caMailVus` (bornées) ; le
 * premier passage SEME ce jeu sans rien envoyer, sinon tout l'historique
 * partirait d'un coup dans la boîte achat@.
 *
 * L'envoi réutilise Smtp (Paramètres → SMTP) ; la protection du cron est le
 * jeton des rapports (`rapportsJeton`) — même curl, même crontab, un secret.
 */

function caMailDefauts(): array
{
    return [
        'actif' => false,
        // Le fournisseur est le DESTINATAIRE : c'est lui qui doit servir la
        // commande. La centrale reste en copie — elle veut savoir, elle n'a
        // pas à faire suivre.
        'auFournisseur' => true,
        // Le courrier vient de la CENTRALE D'ACHAT, pas de la boîte des
        // rapports : c'est elle que le fournisseur doit lire dans son client
        // de messagerie, et à laquelle sa réponse doit revenir.
        'expediteur' => 'Centrale d’achat <achats@atelierby.be>',
        'destinataire' => 'achats@atelierby.be',
        'copie' => '',
        // MESURÉ : l'ERP garde 115 réquisitions au statut « en attente »,
        // toutes de septembre 2025 à avril 2026 — ce statut ne se referme
        // jamais. Relancer un fournisseur sur une commande de l'an dernier
        // n'appelle rien : on ne chasse que ce qui est encore d'actualité.
        'fenetreJours' => 30,
        // Et jamais un mur de lignes : au-delà, le courrier dit combien il en
        // reste plutôt que d'en imprimer cent.
        'maxLignes' => 15,
        'sujet' => 'Commandes en attente — {{fournisseur}} ({{nCommandes}})',
        'corps' => "Bonjour,\n\n"
            . "Les commandes suivantes vous attendent et n'ont pas encore été acceptées :\n\n"
            . "{{lignes}}\n"
            . "Total en attente : {{total}}\n\n"
            . "Merci de les valider dans le portail fournisseur. Ce rappel repart chaque jour "
            . "tant qu'une commande reste en attente.\n\n"
            . "— Centrale d'achat, L'Atelier by",
        // Le SQUELETTE du courrier, éditable comme le reste. Vide = celui
        // d'origine (caMailSquelette). Les variables sont les mêmes que dans
        // le texte, plus {{logo}}, {{marque}}, {{contenu}} et {{sujet}}.
        'html' => '',
    ];
}

/**
 * Le squelette d'origine : tables imbriquées et styles en ligne — la seule
 * mise en page que Gmail et Outlook respectent.
 */
function caMailSquelette(string $service = 'Centrale d’achat',
    string $signature = 'ce message est envoyé automatiquement par la centrale d’achat.'): string
{
    // Le service et la signature sont des ARGUMENTS : la note de campagne
    // emprunte la même lettre, et elle sortait signée « centrale d'achat » —
    // le franchisé croyait à une commande.
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Téléphone d'abord : la lettre se lit sur un écran de 360 points, souvent
    // en THÈME SOMBRE. Trois choses s'y jouent, et elles ont été vues de
    // travers avant d'être corrigées :
    //  · `color-scheme: light` empêche le client d'inverser les couleurs — le
    //    logo noir sur fond blanc devenait invisible ;
    //  · l'en-tête s'EMPILE (logo, puis « Centrale d'achat ») au lieu de deux
    //    colonnes qui se chevauchent sous 400 points ;
    //  · la pastille du nombre passe SOUS le nom du fournisseur, qu'elle
    //    recouvrait.
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light only">'
        . '<meta name="supported-color-schemes" content="light only">'
        . '<style>'
        . ':root{color-scheme:light only;supported-color-schemes:light only}'
        . 'body,table,td{color-scheme:light only}'
        . 'img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}'
        . '@media only screen and (max-width:480px){'
        . '.carte{width:100% !important}'
        . '.pad{padding-left:18px !important;padding-right:18px !important}'
        . '.titre{font-size:17px !important}'
        . '.cmd{font-size:13px !important;word-break:break-word}'
        . '}'
        . '</style></head>'
        . '<body style="margin:0;padding:0;background:#EFE9DF;color:#221E1A">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EFE9DF">'
        . '<tr><td align="center" style="padding:18px 10px">'
        . '<table role="presentation" class="carte" cellpadding="0" cellspacing="0" width="600" style="width:600px;max-width:100%">'
        // Bandeau : le logo seul sur sa ligne, la mention dessous — deux
        // colonnes se chevauchaient sur un téléphone.
        . '<tr><td class="pad" style="background:#ffffff;border-radius:12px 12px 0 0;border-bottom:3px solid #8D1D2C;padding:16px 26px 12px">'
        . '{{logo}}'
        . '<div style="font-family:\'Segoe UI\',Arial,sans-serif;color:#8b8177;font-size:10px;letter-spacing:1.4px;text-transform:uppercase;margin-top:8px">' . $e($service) . '</div>'
        . '</td></tr>'
        . '<tr><td style="background:#ffffff;border-radius:0 0 12px 12px;padding:0 0 20px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">{{contenu}}</table>'
        . '</td></tr>'
        . '<tr><td class="pad" style="font-family:\'Segoe UI\',Arial,sans-serif;padding:12px 26px;color:#8b8177;font-size:11px;line-height:1.5;text-align:center">'
        . '{{marque}} — ' . $e($signature)
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Le carnet d'adresses des fournisseurs.
 *
 * MESURÉ : le panel rend bien /material-suppliers, mais son champ `email` est
 * VIDE pour les quatre fournisseurs qui reçoivent des commandes, et
 * `ceo_supplier` n'en connaît qu'un, sans adresse. Sans carnet tenu ici, il
 * n'y a donc personne à qui écrire. Il vit dans `ceo_app_setting`, saisi
 * depuis l'écran, et le panel garde la priorité le jour où il portera l'adresse.
 */
function caMailNom(string $nom): string
{
    $n = mb_strtolower(trim($nom));
    $n = strtr($n, ['’' => "'", '–' => '-', '—' => '-']);
    $n = preg_replace('/[^a-z0-9]+/u', '', $n) ?? $n;
    return $n;
}

/**
 * Les adresses que le PANEL porte, par nom de fournisseur.
 *
 * MESURÉ : GET /material-suppliers rend six fournisseurs avec leur `email`
 * (SLFood, Berdiff…). L'écran des commandes les lisait déjà, mais par
 * identifiant, et cet identifiant ne voyage pas jusqu'ici — d'où un carnet
 * qui paraissait vide alors que le panel savait. On rapproche donc par NOM,
 * qui est ce que la réquisition nomme.
 *
 * `country_code` est accepté par la route ; on ne s'en sert pas pour filtrer —
 * un fournisseur étranger qui livre la Belgique doit pouvoir être écrit.
 */
function caMailPanelAdresses(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $out = [];
    if (!PanelApi::configured()) { $cache = $out; return $out; }
    foreach (analyseListe(PanelApi::get('/material-suppliers') ?? []) as $f) {
        $nom = trim((string) ($f['name'] ?? ''));
        $mail = trim((string) ($f['email'] ?? ''));
        // Le panel écrit « NULL » en toutes lettres quand la colonne est vide.
        if ($nom === '' || $mail === '' || strtoupper($mail) === 'NULL') { continue; }
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) { continue; }
        $out[caMailNom($nom)] = $mail;
    }
    $cache = $out;
    return $out;
}

function caMailCarnet(): array
{
    $v = setting('caFournisseursMails');
    $out = [];
    if (is_array($v)) {
        foreach ($v as $nom => $mail) {
            $mail = trim((string) $mail);
            if ($mail !== '') { $out[caMailNom((string) $nom)] = $mail; }
        }
    }
    return $out;
}

/**
 * L'adresse d'un fournisseur : ce qu'on a corrigé ici d'abord, ce que le panel
 * porte ensuite. Le carnet local n'existe que pour RATTRAPER une adresse
 * absente ou fausse côté panel — il ne le remplace pas.
 */
function caMailAdresse(string $nom, array $carnet, array $panel = []): string
{
    $cle = caMailNom($nom);
    if (($carnet[$cle] ?? '') !== '') { return $carnet[$cle]; }
    if (($panel[$cle] ?? '') !== '') { return trim((string) $panel[$cle]); }
    return trim((string) (caMailPanelAdresses()[$cle] ?? ''));
}

function caMailConfig(): array
{
    $v = setting('caMailCommande');
    return is_array($v) ? array_merge(caMailDefauts(), $v) : caMailDefauts();
}

/** Les variables d'une réquisition, prêtes pour le template. */
function caMailVariables(array $l): array
{
    $fours = (array) ($l['fournisseurs'] ?? []);
    return [
        'id' => (string) ($l['id'] ?? ''),
        'magasin' => (string) ($l['magasin'] ?? '—'),
        'fournisseur' => $fours === [] ? '—' : implode(' + ', $fours),
        'debut' => (string) ($l['debut'] ?? '—'),
        'statut' => ((string) ($l['statut'] ?? '')) === 'PENDING' ? 'En attente' : 'Réalisée',
        'valeur' => number_format((float) ($l['valeur'] ?? 0), 2, ',', ' ') . ' €',
        'par' => (string) (($l['par'] ?? '') !== '' ? $l['par'] : '—'),
    ];
}

function caMailRemplir(string $texte, array $vars): string
{
    foreach ($vars as $k => $v) { $texte = str_replace('{{' . $k . '}}', $v, $texte); }
    // Ce qui reste entre accolades n'a pas de valeur : le laisser partirait
    // « {{magasin}} » au fournisseur — c'est arrivé. On l'efface.
    return trim((string) preg_replace('/\{\{[a-zA-Z0-9_]+\}\}/', '', $texte));
}

/** Le gabarit enregistré parle-t-il encore la langue d'aujourd'hui ? */
function caMailGabaritVieux(array $c): bool
{
    $t = (string) ($c['sujet'] ?? '') . ' ' . (string) ($c['corps'] ?? '');
    return !str_contains($t, '{{lignes}}');
}

/**
 * Le courrier au fournisseur, aux couleurs de la maison.
 *
 * Tables imbriquées et styles en ligne : c'est la seule mise en page que Gmail
 * et Outlook respectent — la même que les rapports, pour que tout ce qui sort
 * du cockpit se ressemble. Le logo voyage EN DATA-URI : un lien vers le
 * serveur du cockpit ne s'affiche pas chez un destinataire externe, qui n'y a
 * pas accès et le verrait en cadre vide.
 *
 * Le corps reste écrit en TEXTE dans les Paramètres : on met en page ici, on
 * ne demande à personne d'écrire du HTML. Les lignes commençant par « · »
 * deviennent les cartes de commande.
 */
function caMailHtml(string $corps, array $g = [], array $c = []): string
{
    $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $F = "font-family:'Segoe UI',Arial,sans-serif";
    $reseau = setting('reseau', []);
    $marque = is_array($reseau) ? ($reseau['nom'] ?? 'L’Atelier by') : 'L’Atelier by';
    $logoUri = function_exists('rapLogoDataUri') ? rapLogoDataUri() : '';
    $logo = $logoUri !== ''
        ? '<img src="' . $logoUri . '" height="30" style="display:block;height:30px" alt="' . $e($marque) . '">'
        : '<span style="' . $F . ';color:#221E1A;font-size:16px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase">' . $e($marque) . '</span>';

    // Le texte se découpe : les lignes « · … » sont des commandes, le reste du
    // discours. Une puce sans commande derrière n'invente pas de carte.
    $avant = []; $cartes = []; $apres = [];
    foreach (preg_split('/\r?\n/', $corps) ?: [] as $ligne) {
        $t = trim($ligne);
        if (str_starts_with($t, '·')) { $cartes[] = ltrim($t, "· \t"); continue; }
        if ($cartes === []) { $avant[] = $ligne; } else { $apres[] = $ligne; }
    }
    $par = function (array $lignes) use ($e, $F): string {
        $txt = trim(implode("\n", $lignes));
        if ($txt === '') { return ''; }
        return '<tr><td class="pad" style="' . $F . ';font-size:14px;line-height:1.6;color:#221E1A;padding:10px 26px 12px">'
            . nl2br($e($txt)) . '</td></tr>';
    };

    $blocCartes = '';
    foreach ($cartes as $c2) {
        // « #169 — Halle — 2 132,84 € — période du … — en attente depuis 12 jour(s) »
        $morceaux = array_map('trim', explode('—', $c2));
        $titre = array_shift($morceaux) ?: '';
        $retard = '';
        foreach ($morceaux as $i2 => $m2) {
            if (str_contains($m2, 'en attente depuis')) { $retard = $m2; unset($morceaux[$i2]); }
        }
        $blocCartes .= '<tr><td class="pad" style="padding:0 26px 8px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F3EC;border-left:4px solid #8D1D2C;border-radius:0 8px 8px 0">'
            . '<tr><td style="' . $F . ';padding:10px 13px">'
            // La clé d'une commande est longue : elle doit pouvoir se couper,
            // sinon elle déborde de l'écran d'un téléphone.
            . '<div class="cmd" style="font-size:14px;font-weight:700;color:#221E1A;line-height:1.3;word-break:break-word">' . $e($titre) . '</div>'
            . ($morceaux !== [] ? '<div style="font-size:12.5px;color:#6E645A;margin-top:4px;line-height:1.45">' . $e(implode(' · ', $morceaux)) . '</div>' : '')
            . ($retard !== '' ? '<div style="font-size:12px;font-weight:700;color:#8D1D2C;margin-top:5px">' . $e($retard) . '</div>' : '')
            . '</td></tr></table></td></tr>';
    }

    // Le nom, puis la pastille DESSOUS : côte à côte, sur un téléphone, la
    // pastille recouvrait le nom du fournisseur.
    $n = (int) ($g['n'] ?? 0);
    $entete = ($g['nom'] ?? '') !== ''
        ? '<tr><td class="pad" style="padding:18px 26px 4px">'
          . '<div class="titre" style="' . $F . ';font-size:19px;font-weight:700;color:#221E1A;line-height:1.25">' . $e($g['nom']) . '</div>'
          . ($n > 0
            ? '<div style="margin-top:7px"><span style="' . $F . ';display:inline-block;background:#F7ECEA;border-radius:999px;padding:5px 12px;font-size:12px;font-weight:700;color:#8D1D2C">'
              . $n . ' commande' . ($n > 1 ? 's' : '') . ' en attente</span></div>'
            : '')
          . '</td></tr>'
        : '';

    $contenu = $entete . $par($avant) . $blocCartes
        . ($blocCartes !== '' ? '<tr><td style="padding:6px 30px 0"></td></tr>' : '')
        . $par($apres);

    // Le squelette : celui des Paramètres s'il est posé, celui d'origine sinon.
    // Une variable inconnue disparaît plutôt que de partir entre accolades.
    $squelette = trim((string) ($c['html'] ?? ''));
    if ($squelette === '') { $squelette = caMailSquelette(); }
    return caMailRemplir($squelette, [
        'logo' => $logo, 'marque' => $e($marque), 'contenu' => $contenu,
        'entete' => $entete, 'cartes' => $blocCartes,
        'fournisseur' => $e((string) ($g['nom'] ?? '')),
        'nCommandes' => (string) (int) ($g['n'] ?? 0),
    ]);
}

/**
 * Journal des e-mails achats — chaque commande reçue et chaque envoi (réussi
 * ou non) laisse une trace, lisible dans Paramètres. Vit dans
 * `ceo_app_setting.caMailJournal`, borné aux 200 dernières entrées.
 */
function caMailJournal(string $type, string $detail, string $destinataire = '', array $plus = []): void
{
    $j = setting('caMailJournal');
    if (!is_array($j)) { $j = []; }
    array_unshift($j, array_merge(['quand' => date('Y-m-d H:i'), 'type' => $type,
        'detail' => mb_substr($detail, 0, 200), 'destinataire' => $destinataire], $plus));
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caMailJournal', json_encode(array_slice($j, 0, 200), JSON_UNESCAPED_UNICODE)]);
}

/** État pour l'écran — la config, jamais rien de secret, le dernier passage et le journal. */
function caMailEtat(): array
{
    $c = caMailConfig();
    $dernier = setting('caMailDernier');
    $journal = setting('caMailJournal');
    $suivi = setting('caMailQuotidien');
    $carnet = caMailCarnet();

    // Les fournisseurs qui attendent VRAIMENT quelque chose : ceux qui portent
    // une commande en attente. C'est à eux qu'il faut une adresse, pas à tout
    // un annuaire.
    $fournisseurs = [];
    try {
        $aV = caMailAValider();
        if ($aV !== null) {
            foreach (caMailGroupes($aV, (int) ($c['fenetreJours'] ?? 30)) as $cle => $g) {
                $adresse = caMailAdresse($g['nom'], $carnet, $g['mails']);
                $fournisseurs[] = ['cle' => $cle, 'nom' => $g['nom'], 'commandes' => $g['n'],
                    'total' => round((float) $g['total'], 2), 'email' => $adresse,
                    // Ce qui dort : compté, jamais relancé, et dit.
                    'anciennes' => (int) $g['anciennes'],
                    'anciennesTotal' => round((float) $g['anciennesTotal'], 2),
                    'plusRecenteAncienne' => (string) $g['plusRecenteAncienne'],
                    'source' => $adresse === '' ? '' : (isset($carnet[$cle]) ? 'corrigée ici' : 'du panel'),
                    'dernier' => (string) (is_array($suivi) ? ($suivi[$cle]['dernier'] ?? '') : ''),
                    'envois' => (int) (is_array($suivi) ? ($suivi[$cle]['envois'] ?? 0) : 0)];
            }
        }
    } catch (Throwable $e) { /* commandes indisponibles : l'écran le dira */ }

    return [
        'config' => $c,
        'smtpPret' => Smtp::configured(),
        'dernier' => is_array($dernier) ? $dernier : null,
        'journal' => is_array($journal) ? array_slice($journal, 0, 50) : [],
        'variables' => ['fournisseur', 'nCommandes', 'total', 'lignes', 'magasins', 'avis'],
        'fournisseurs' => $fournisseurs,
        'sansAdresse' => count(array_filter($fournisseurs, fn ($f) => $f['email'] === '')),
        'gabaritVieux' => caMailGabaritVieux($c),
        'squelette' => caMailSquelette(),
        'variablesHtml' => ['logo', 'marque', 'contenu', 'entete', 'cartes', 'fournisseur', 'nCommandes'],
        'fenetreJours' => (int) ($c['fenetreJours'] ?? 30),
        'classees' => (function () { $v = setting('caMailClassees');
            return is_array($v)
                ? ['n' => count((array) ($v['ids'] ?? [])), 'quand' => (string) ($v['quand'] ?? ''),
                   'par' => (string) ($v['par'] ?? '')]
                : ['n' => 0, 'quand' => '', 'par' => '']; })(),
        'defauts' => caMailDefauts(),
    ];
}

/**
 * Les réquisitions CLASSÉES — traitées de notre côté, quoi qu'en dise l'ERP.
 *
 * L'ERP garde des réquisitions au statut « en attente » qu'il ne referme
 * jamais (mesuré : 115, de septembre 2025 à avril 2026). Aucune route ne
 * permet de les accepter chez lui : on ne peut donc que dire ICI qu'on les
 * considère traitées, pour que le rappel quotidien cesse de les compter.
 *
 * Ce classement ne touche RIEN dans l'ERP — l'écran doit le dire, sans quoi
 * on croirait la commande acceptée côté fournisseur. Il se défait.
 */
function caMailClassees(): array
{
    $v = setting('caMailClassees');
    return is_array($v) ? array_map('strval', (array) ($v['ids'] ?? [])) : [];
}

/**
 * Le rappel au FRANCHISÉ : sa réquisition attend d'être validée.
 *
 * Deux rappels, deux destinataires, deux canaux — et il ne faut pas les
 * confondre : le fournisseur reçoit un E-MAIL (il doit accepter la commande),
 * le franchisé une NOTIFICATION dans l'ERP, là où il travaille (il doit
 * valider la sienne). Écrire au franchisé par mail le noierait dans une boîte
 * qu'il n'ouvre pas de la journée.
 */
function caFranchiseDefauts(): array
{
    return [
        'titre' => 'Commande #{{id}} — à valider',
        'message' => "Votre commande #{{id}} du {{debut}} ({{valeur}}) n'est pas encore validée{{retard}}. "
            . "Merci de la finaliser pour qu'elle parte chez {{fournisseur}}.",
        'priorite' => 'warning',
        'actionLabel' => 'Voir mes commandes',
        'actionUrl' => 'https://atelierby.tfbuddy.com/panel/material-requisitions',
        'jours' => 7,
    ];
}

function caFranchiseConfig(): array
{
    $v = setting('caRelanceFranchise');
    return is_array($v) ? array_merge(caFranchiseDefauts(), $v) : caFranchiseDefauts();
}

/**
 * POST /centrale/commandes/relance-franchise — une notification au magasin.
 *
 * La réquisition est cherchée dans le MÊME flux que l'écran : ce qui est
 * relancé est exactement ce qui est affiché, jamais un objet retrouvé
 * autrement qui dirait autre chose.
 */
function wr_ca_relance_franchise(): array
{
    $b = body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); return ['ok' => false, 'erreur' => 'réquisition requise']; }

    $d = ep_ca_commandes();
    $ligne = null;
    foreach ((array) ($d['lignes'] ?? []) as $l) {
        if ((int) ($l['id'] ?? 0) === $id) { $ligne = $l; break; }
    }
    if ($ligne === null) { return ['ok' => false, 'erreur' => 'réquisition introuvable dans le flux du panel']; }
    $sid = (int) ($ligne['magasinId'] ?? 0);
    if ($sid <= 0) { return ['ok' => false, 'erreur' => 'magasin inconnu — la notification ne saurait où aller']; }

    $debut = (string) ($ligne['debut'] ?? '');
    $retard = '';
    if ($debut !== '' && $debut < date('Y-m-d')) {
        $j = (int) floor((time() - strtotime($debut)) / 86400);
        if ($j > 0) { $retard = ' (en attente depuis ' . $j . ' jour(s))'; }
    }
    $vars = [
        'id' => (string) $id,
        'magasin' => (string) ($ligne['magasin'] ?? ''),
        'fournisseur' => implode(' + ', (array) ($ligne['fournisseurs'] ?? [])) ?: 'votre fournisseur',
        'debut' => $debut !== '' ? $debut : '—',
        'valeur' => number_format((float) ($ligne['valeur'] ?? 0), 2, ',', ' ') . ' €',
        'retard' => $retard,
    ];

    $c = caFranchiseConfig();
    $jours = max(1, (int) ($c['jours'] ?? 7));
    [$ok, $rep] = PanelApi::post('/notifications', [
        'title' => caMailRemplir((string) $c['titre'], $vars),
        'message' => caMailRemplir((string) $c['message'], $vars),
        'priority' => (string) ($c['priorite'] ?? 'warning'),
        'status' => 'published',
        'visible_from' => date('Y-m-d H:i:s'),
        'visible_to' => date('Y-m-d H:i:s', time() + $jours * 86400),
        'type' => 'once',
        'is_global' => 0,
        'shops' => [$sid],
        'source_type' => 'material_requisition',
        'source_id' => $id,
        'action_label' => (string) ($c['actionLabel'] ?? 'Voir mes commandes'),
        'action_url' => caMailRemplir((string) ($c['actionUrl'] ?? ''), $vars),
    ]);
    $nid = 0;
    foreach ([$rep['inserted_id'] ?? null, $rep['id'] ?? null, $rep['data']['id'] ?? null] as $cand) {
        if (is_numeric($cand)) { $nid = (int) $cand; break; }
    }
    // La date est gardée par réquisition : la ligne dira « relancé le … »
    // plutôt que de laisser relancer dix fois le même magasin.
    if ($ok) {
        $v = setting('caRelancesFranchise');
        $v = is_array($v) ? $v : [];
        $v[(string) $id] = ['quand' => date('Y-m-d'), 'notification' => $nid];
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['caRelancesFranchise', json_encode(array_slice($v, -500, null, true), JSON_UNESCAPED_UNICODE)]);
    }
    caMailJournal($ok ? 'franchise' : 'echec',
        ($ok ? 'Rappel au franchisé — ' : 'Rappel au franchisé en échec — ')
        . 'réquisition #' . $id . ' · ' . $vars['magasin']
        . ($ok && $nid ? ' · notification #' . $nid : '')
        . (!$ok ? ' · ' . (string) (PanelApi::$lastError ?? '') : ''), $vars['magasin']);
    if (!$ok) { return ['ok' => false, 'erreur' => PanelApi::$lastError ?? 'notification refusée']; }
    return ['ok' => true, 'notification' => $nid, 'magasin' => $vars['magasin']];
}

/**
 * POST /centrale/commandes/mail/envoyer — le rappel au fournisseur, MAINTENANT.
 *
 * Le rappel part de lui-même une fois par jour ; ce bouton sert quand on veut
 * qu'il parte tout de suite. La borne d'un envoi par jour saute pour ce
 * fournisseur-là, et seulement pour lui.
 */
function wr_ca_mail_envoyer(): array
{
    $b = body();
    $nom = trim((string) ($b['fournisseur'] ?? ''));
    if ($nom === '') { http_response_code(400); return ['ok' => false, 'erreur' => 'fournisseur requis']; }
    $c = caMailConfig();
    if (!Smtp::configured()) { return ['ok' => false, 'erreur' => 'SMTP non configuré (Paramètres → SMTP)']; }

    $suivi = setting('caMailQuotidien');
    $suivi = is_array($suivi) ? $suivi : [];
    unset($suivi[caMailNom($nom)]);
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caMailQuotidien', json_encode($suivi, JSON_UNESCAPED_UNICODE)]);

    $lignesTout = caMailAValider();
    if ($lignesTout === null) { return ['ok' => false, 'erreur' => 'commandes illisibles côté panel']; }
    // On ne relance QUE le fournisseur demandé : les autres gardent leur
    // rythme quotidien.
    $cible = caMailNom($nom);
    $lignes = array_values(array_filter($lignesTout, function ($l) use ($cible) {
        foreach ((array) ($l['fournisseurs'] ?? []) as $f) { if (caMailNom((string) $f) === $cible) { return true; } }
        return false;
    }));
    // À la main : ni fenêtre de trente jours, ni classement. On relance ce
    // fournisseur, maintenant, avec tout ce qu'il a en attente.
    $r = caMailRappels($lignes, $c, true);
    if (($r['envoyes'] ?? 0) < 1) {
        return ['ok' => false, 'erreur' => ($r['echecs'] ?? 0) > 0
            ? 'envoi refusé par le serveur de courrier — ' . (string) (Smtp::$lastError ?? '')
            : 'rien à envoyer : ce fournisseur n’a aucune commande en attente'];
    }
    $sans = (array) ($r['sansAdresse'] ?? []);
    return ['ok' => true, 'envoyes' => (int) $r['envoyes'], 'sansAdresse' => $sans,
        // Sans adresse, le courrier part à la centrale : le dire, sinon on
        // croirait le fournisseur prévenu.
        'vers' => $sans ? 'la centrale (ce fournisseur n’a pas d’adresse)'
            : caMailAdresse($nom, caMailCarnet())];
}

/**
 * GET /centrale/commandes/mail/apercu — le courrier tel qu'il partira.
 *
 * Rendu avec un exemple, à partir du gabarit ENREGISTRÉ (ou de celui qu'on
 * essaie, transmis en paramètre) : on doit pouvoir regarder avant d'envoyer,
 * sans poster à personne.
 */
function ep_ca_mail_apercu(): string
{
    $c = caMailConfig();
    foreach (['sujet', 'corps', 'html'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') { $c[$k] = (string) $_GET[$k]; }
    }
    $g = ['nom' => 'Rawette', 'n' => 2, 'total' => 5845.30, 'anciennes' => 3, 'lignes' => [
        ['id' => 169, 'magasin' => 'Atelier by - Halle', 'valeur' => 2132.84, 'debut' => date('Y-m-d', strtotime('-12 day'))],
        ['id' => 168, 'magasin' => 'Atelier by - Halle', 'valeur' => 3712.46, 'debut' => date('Y-m-d', strtotime('-5 day'))],
    ]];
    $vars = caMailVariablesGroupe($g, false);
    return caMailHtml(caMailRemplir((string) $c['corps'], $vars), $g, $c);
}

/**
 * POST /centrale/commandes/mail/classer — classer ce qui attend aujourd'hui,
 * ou tout rouvrir. Le geste est BORNÉ à ce que l'écran montre au moment du
 * clic : classer « tout ce qui attendra un jour » n'aurait pas de sens.
 */
function wr_ca_mail_classer(): array
{
    $b = body();
    if (!empty($b['rouvrir'])) {
        Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', ['caMailClassees']);
        caMailJournal('classement', 'Classement annulé — les réquisitions redeviennent relançables');
        journalAdd('CEO', 'Centrale', 'Réquisitions', 'Classement des réquisitions annulé');
        return ['ok' => true, 'rouvertes' => true];
    }

    $d = ep_ca_commandes();
    if (($d['etat'] ?? '') !== 'ok') {
        http_response_code(503);
        return ['error' => 'commandes indisponibles — rien n’a été classé'];
    }
    $ids = [];
    foreach ((array) ($d['lignes'] ?? []) as $l) {
        if (strtoupper((string) ($l['statut'] ?? '')) !== 'PENDING') { continue; }
        $id = (string) ($l['id'] ?? '');
        if ($id !== '') { $ids[] = $id; }
    }
    $ids = array_values(array_unique(array_merge(caMailClassees(), $ids)));
    $u = setting('utilisateur', []);
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caMailClassees', json_encode(['ids' => $ids, 'quand' => date('c'),
            'par' => (string) (is_array($u) ? ($u['nom'] ?? '') : '')], JSON_UNESCAPED_UNICODE)]);
    // Le suivi quotidien repart de zéro : ce qui vient d'être classé ne doit
    // pas garder un « relancé le … » qui laisserait croire à un envoi en cours.
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caMailQuotidien', json_encode([])]);
    caMailJournal('classement', count($ids) . ' réquisition(s) classées — plus de rappel automatique '
        . '(l’ERP n’est pas modifié)');
    journalAdd('CEO', 'Centrale', 'Réquisitions', count($ids) . ' réquisition(s) classées côté cockpit');
    return ['ok' => true, 'classees' => count($ids)];
}

/**
 * GET /centrale/commandes/mail/courriers?fournisseur=… — ce qui est parti.
 *
 * Le rappel parle de RÉQUISITIONS matière ; la ligne du suivi montre une
 * COMMANDE (ORD-…). Le panel ne relie pas les deux : on rend donc les
 * courriers envoyés au FOURNISSEUR de la commande, en disant ce que chacun
 * citait — plutôt qu'un lien inventé entre deux objets qui ne se connaissent pas.
 */
function ep_ca_mail_courriers(): array
{
    $nom = trim((string) ($_GET['fournisseur'] ?? ''));
    $j = setting('caMailJournal');
    $j = is_array($j) ? $j : [];
    $cle = $nom !== '' ? caMailNom($nom) : '';
    $out = [];
    foreach ($j as $e) {
        if (!in_array((string) ($e['type'] ?? ''), ['envoye', 'echec', 'clos'], true)) { continue; }
        if ($cle !== '' && caMailNom((string) ($e['fournisseur'] ?? '')) !== $cle) { continue; }
        $out[] = [
            'quand' => (string) ($e['quand'] ?? ''),
            'type' => (string) ($e['type'] ?? ''),
            'sujet' => (string) ($e['sujet'] ?? ''),
            'destinataire' => (string) ($e['destinataire'] ?? ''),
            'copie' => (string) ($e['copie'] ?? ''),
            'reqs' => array_values((array) ($e['reqs'] ?? [])),
            'detail' => (string) ($e['detail'] ?? ''),
        ];
        if (count($out) >= 40) { break; }
    }
    $suivi = setting('caMailQuotidien');
    $etat = (is_array($suivi) && $cle !== '' && isset($suivi[$cle])) ? $suivi[$cle] : null;
    return ['fournisseur' => $nom, 'courriers' => $out,
        'depuis' => (string) ($etat['depuis'] ?? ''), 'envois' => (int) ($etat['envois'] ?? 0),
        'dernier' => (string) ($etat['dernier'] ?? ''),
        'note' => 'Les rappels portent sur les réquisitions matière ; le panel ne les relie pas aux commandes ORD-… '
            . 'Ce sont donc les courriers envoyés à ce fournisseur.'];
}

/**
 * PUT /centrale/fournisseurs/mail — l'adresse d'un fournisseur.
 *
 * Le carnet est tenu ICI parce que ni le panel ni `ceo_supplier` ne portent
 * l'adresse des fournisseurs qui reçoivent des commandes (mesuré). Une adresse
 * vide EFFACE l'entrée : « pas d'adresse » doit rester dicible.
 */
function wr_ca_fournisseur_mail(): array
{
    $b = body();
    $nom = trim((string) ($b['nom'] ?? ''));
    if ($nom === '') { http_response_code(422); return ['error' => 'le fournisseur est requis']; }
    $mail = trim((string) ($b['email'] ?? ''));
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        return ['error' => 'adresse e-mail illisible'];
    }
    $v = setting('caFournisseursMails');
    $carnet = is_array($v) ? $v : [];
    $cle = caMailNom($nom);
    foreach (array_keys($carnet) as $k) { if (caMailNom((string) $k) === $cle) { unset($carnet[$k]); } }
    if ($mail !== '') { $carnet[$nom] = $mail; }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caFournisseursMails', json_encode($carnet, JSON_UNESCAPED_UNICODE)]);
    journalAdd('CEO', 'Centrale', $nom, $mail !== ''
        ? 'Adresse fournisseur enregistrée : ' . $mail
        : 'Adresse fournisseur retirée');
    return ['ok' => true, 'nom' => $nom, 'email' => $mail];
}

/** POST /centrale/commandes/mail/test — un essai avec des valeurs d'exemple. */
function wr_ca_mail_test(): array
{
    $c = caMailConfig();
    if (!Smtp::configured()) { return ['ok' => false, 'erreur' => 'SMTP non configuré (Paramètres → SMTP)']; }
    // L'essai part où on le demande — sinon à la centrale. Envoyer l'essai à
    // achat@ depuis achat@ ne prouvait pas qu'un fournisseur recevrait quoi
    // que ce soit.
    $vers = trim((string) (body()['vers'] ?? ''));
    if ($vers !== '' && !filter_var($vers, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'erreur' => 'adresse d’essai illisible'];
    }
    if ($vers === '') { $vers = (string) $c['destinataire']; }
    $vars = caMailVariablesGroupe(['nom' => 'Fournisseur d’essai', 'n' => 2, 'total' => 1234.56,
        'lignes' => [
            ['id' => 998, 'magasin' => 'Magasin d’essai', 'valeur' => 1111.11, 'debut' => date('Y-m-d', strtotime('-9 day'))],
            ['id' => 999, 'magasin' => 'Magasin d’essai', 'valeur' => 123.45, 'debut' => date('Y-m-d', strtotime('-2 day'))],
        ]], false);
    $ok = Smtp::envoyer($vers, '[ESSAI] ' . caMailRemplir($c['sujet'], $vars),
        caMailHtml(caMailRemplir($c['corps'], $vars), ['nom' => 'Fournisseur d’essai', 'n' => 2], $c),
        [], trim((string) ($c['expediteur'] ?? '')));
    caMailJournal($ok ? 'essai' : 'echec', $ok ? 'Essai du template envoyé'
        : ('Essai en échec — ' . (string) (Smtp::$lastError ?? '')), $vers);
    return $ok ? ['ok' => true, 'destinataire' => $vers]
               : ['ok' => false, 'erreur' => (string) (Smtp::$lastError ?? 'échec d’envoi')];
}

/**
 * GET /centrale/commandes/mail/cron?jeton= — le rappel QUOTIDIEN au fournisseur.
 *
 * Une commande passée n'est rien tant qu'elle n'est pas acceptée : le rappel
 * repart donc chaque jour, et ne s'arrête que lorsqu'elle sort de l'attente.
 *
 * Un mail PAR FOURNISSEUR, pas par commande : Rawette a deux commandes en
 * attente, elle reçoit UNE lettre qui les liste toutes les deux. Quatre
 * fournisseurs et cent cinquante réquisitions donneraient sinon trois cents
 * envois, que personne ne lirait.
 *
 * Le cron passe toutes les heures ; l'envoi est borné à un par fournisseur et
 * par jour — la date du dernier envoi est gardée dans `caMailQuotidien`.
 */
function ep_ca_mail_cron(): array
{
    $jeton = (string) setting('rapportsJeton', '');
    if ($jeton === '' || !hash_equals($jeton, (string) ($_GET['jeton'] ?? ''))) {
        http_response_code(403);
        return ['error' => 'jeton absent ou invalide — poser ceo_app_setting.rapportsJeton'];
    }

    $c = caMailConfig();
    if (empty($c['actif'])) { return ['etat' => 'inactif']; }
    if (!Smtp::configured()) { return ['etat' => 'erreur', 'motif' => 'SMTP non configuré']; }

    $lignes = caMailAValider();
    if ($lignes === null) { return ['etat' => 'attente', 'motif' => 'commandes illisibles côté panel']; }
    return caMailRappels($lignes, $c);
}

/**
 * Ce qu'on relance : les commandes que le FOURNISSEUR n'a pas acceptées.
 *
 * C'était l'erreur : le rappel se bâtissait sur les réquisitions — la demande
 * du magasin. Or ce que le fournisseur doit faire, c'est accepter SA commande.
 * On lit donc le suivi (étape « envoyée, pas encore acceptée »), sans la borne
 * des deux par magasin qui ne vaut que pour l'affichage.
 *
 * Chaque commande ne nomme qu'UN fournisseur — celui de la commande, retenu
 * par l'ERP. Le courrier ne peut donc plus s'adresser au mauvais.
 *
 * @return array<int,array>|null null quand le panel n'a rien rendu.
 */
function caMailAValider(): ?array
{
    $d = ep_ca_achats();
    $sv = (array) ($d['suivi'] ?? []);
    if (!isset($sv['aValider']) || !is_array($sv['aValider'])) { return null; }
    $out = [];
    foreach ($sv['aValider'] as $o) {
        $out[] = [
            // La CLÉ de la commande fait l'identifiant : c'est elle que le
            // fournisseur lit dans son portail, pas un numéro interne.
            'id' => (string) ($o['cle'] ?? ('#' . ($o['id'] ?? ''))),
            'magasin' => (string) ($o['magasin'] ?? ''),
            'fournisseurs' => [(string) ($o['fournisseur'] ?? '')],
            'debut' => (string) ($o['date'] ?? ''),
            'statut' => 'PENDING',
            // MESURÉ : /deliveries/{id} ne porte aucun montant. Zéro ici veut
            // dire « inconnu », et le courrier n'affiche pas de total faux.
            'valeur' => 0.0,
            'livraison' => (string) ($o['livraisonPrevue'] ?? ''),
        ];
    }
    return $out;
}

/**
 * L'envoi lui-même, à partir des lignes de commandes — séparé du cron pour
 * être vérifiable sans panel : c'est la règle d'envoi qui doit être sûre, pas
 * la plomberie qui va la chercher.
 */
function caMailRappels(array $lignes, array $c, bool $manuel = false): array
{
    $tous = caMailGroupes($lignes, $manuel ? 0 : (int) ($c['fenetreJours'] ?? 30), $manuel);
    // Un fournisseur dont TOUT est hors fenêtre n'est pas relancé — mais il
    // reste dans l'état de l'écran, qui dira ce qui dort.
    $groupes = array_filter($tous, fn ($g) => $g['n'] > 0);
    $carnet = caMailCarnet();
    $suivi = setting('caMailQuotidien');
    if (!is_array($suivi)) { $suivi = []; }
    $auj = date('Y-m-d');

    // Ce qui n'est plus en attente sort du suivi — et le journal le dit une
    // fois : une commande acceptée doit cesser d'être relancée, et on doit
    // pouvoir le vérifier.
    foreach (array_keys($suivi) as $cle) {
        if (!isset($groupes[$cle])) {
            caMailJournal('clos', 'Plus de commande en attente pour « '
                . (string) ($suivi[$cle]['nom'] ?? $cle) . ' » — rappels arrêtés');
            unset($suivi[$cle]);
        }
    }

    $envoyes = 0; $echecs = 0; $sansAdresse = [];
    foreach ($groupes as $cle => $g) {
        $adresse = caMailAdresse($g['nom'], $carnet, $g['mails']);
        $vers = $adresse;
        if ($vers === '') {
            // Sans adresse, la commande ne doit pas disparaître : la centrale
            // reçoit à la place, et l'écran nomme le fournisseur à renseigner.
            $sansAdresse[] = $g['nom'];
            $vers = trim((string) $c['destinataire']);
            if ($vers === '') { continue; }
        }
        // Un envoi par jour et par fournisseur : le cron passe toutes les
        // heures. La borne ne vaut QUE pour l'automatique — « à tout moment
        // je dois pouvoir relancer » veut dire à tout moment, y compris deux
        // fois le même jour.
        if (!$manuel && (string) ($suivi[$cle]['dernier'] ?? '') === $auj) { continue; }

        $vars = caMailVariablesGroupe($g, $adresse === '');
        $sujet = caMailRemplir((string) $c['sujet'], $vars);
        $html = caMailHtml(caMailRemplir((string) $c['corps'], $vars), $g, $c);
        $de = trim((string) ($c['expediteur'] ?? ''));
        $ok = Smtp::envoyer($vers, $sujet, $html, [], $de);
        // La centrale garde la copie — sauf quand c'est déjà elle qui reçoit.
        $copie = trim((string) $c['copie']);
        if ($copie === '' && $adresse !== '') { $copie = trim((string) $c['destinataire']); }
        if ($ok && $copie !== '' && $copie !== $vers) { Smtp::envoyer($copie, $sujet, $html, [], $de); }

        if ($ok) {
            $suivi[$cle] = ['nom' => $g['nom'], 'dernier' => $auj,
                'envois' => (int) ($suivi[$cle]['envois'] ?? 0) + 1,
                'depuis' => (string) ($suivi[$cle]['depuis'] ?? $auj)];
            $envoyes++;
        } else { $echecs++; }
        // Un rappel envoyé à la main compte comme celui du jour : l'automatique
        // ne repartira pas dans l'heure derrière lui.
        caMailJournal($ok ? 'envoye' : 'echec',
            ($ok ? 'Rappel envoyé — ' : 'Envoi en échec — ') . $g['nom'] . ' · '
            . $g['n'] . ' commande(s) en attente'
            . ($adresse === '' ? ' · SANS ADRESSE, envoyé à la centrale' : '')
            . (!$ok ? ' · ' . (string) (Smtp::$lastError ?? '') : ''), $vers,
            // Ce que le courrier citait : c'est ce qui permet, depuis une
            // commande, de retrouver les lettres qui en parlaient.
            ['fournisseur' => $g['nom'], 'cle' => $cle, 'sujet' => $sujet,
             'reqs' => array_values(array_filter(array_map(
                 fn ($l) => (string) ($l['id'] ?? ''), $g['lignes']))),
             'copie' => ($ok && $copie !== '' && $copie !== $vers) ? $copie : '']);
    }

    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caMailQuotidien', json_encode($suivi, JSON_UNESCAPED_UNICODE)]);
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caMailDernier', json_encode(['quand' => date('c'), 'envoyes' => $envoyes, 'echecs' => $echecs,
            'fournisseurs' => count($groupes),
            'sansAdresse' => array_values(array_unique($sansAdresse)),
            'erreur' => $echecs ? (string) (Smtp::$lastError ?? '') : ''], JSON_UNESCAPED_UNICODE)]);

    return ['etat' => 'ok', 'envoyes' => $envoyes, 'echecs' => $echecs,
        'fournisseurs' => count($groupes), 'sansAdresse' => array_values(array_unique($sansAdresse))];
}

/**
 * Les commandes EN ATTENTE, groupées par fournisseur.
 *
 * Une réquisition peut nommer plusieurs fournisseurs : elle apparaît alors
 * chez chacun d'eux — chacun ne peut servir que sa part, mais tous doivent
 * savoir qu'on les attend.
 */
function caMailGroupes(array $lignes, int $fenetreJours = 0, bool $toutPrendre = false): array
{
    $borne = $fenetreJours > 0 ? date('Y-m-d', strtotime('-' . $fenetreJours . ' day')) : '';
    // Un envoi demandé À LA MAIN ne se laisse borner par rien : quelqu'un a
    // cliqué, il sait ce qu'il fait. La fenêtre et le classement protègent
    // l'envoi AUTOMATIQUE, pas la décision d'un humain.
    $classees = $toutPrendre ? [] : array_flip(caMailClassees());
    $out = [];
    foreach ($lignes as $l) {
        if (strtoupper((string) ($l['statut'] ?? '')) !== 'PENDING') { continue; }
        // Classée ici : traitée pour nous, quoi qu'en dise l'ERP.
        if (isset($classees[(string) ($l['id'] ?? '')])) { continue; }
        // Hors fenêtre : compté, jamais relancé. Le taire ferait croire que le
        // fournisseur n'a rien en souffrance.
        $vieille = $borne !== '' && (string) ($l['debut'] ?? '') !== '' && (string) $l['debut'] < $borne;
        $fours = (array) ($l['fournisseurs'] ?? []);
        if ($fours === []) { $fours = ['—']; }
        foreach ($fours as $nom) {
            $nom = trim((string) $nom);
            if ($nom === '' || $nom === '—') { continue; }
            $cle = caMailNom($nom);
            if (!isset($out[$cle])) {
                $out[$cle] = ['nom' => $nom, 'n' => 0, 'total' => 0.0, 'lignes' => [], 'mails' => [],
                    'anciennes' => 0, 'anciennesTotal' => 0.0, 'plusRecenteAncienne' => ''];
            }
            if ($vieille) {
                $out[$cle]['anciennes']++;
                $out[$cle]['anciennesTotal'] += (float) ($l['valeur'] ?? 0);
                $d2 = (string) ($l['debut'] ?? '');
                if ($d2 > $out[$cle]['plusRecenteAncienne']) { $out[$cle]['plusRecenteAncienne'] = $d2; }
                continue;
            }
            $out[$cle]['n']++;
            $out[$cle]['total'] += (float) ($l['valeur'] ?? 0);
            $out[$cle]['lignes'][] = $l;
            if (($l['email'] ?? '') !== '') { $out[$cle]['mails'][$cle] = (string) $l['email']; }
        }
    }
    return $out;
}

/** Les variables d'un rappel groupé — dont la liste des commandes en clair. */
function caMailVariablesGroupe(array $g, bool $sansAdresse): array
{
    $auj = new DateTimeImmutable(date('Y-m-d'));
    $lignes = [];
    $maxL = 15;
    $restant = max(0, count($g['lignes']) - $maxL);
    foreach (array_slice($g['lignes'], 0, $maxL) as $l) {
        $debut = (string) ($l['debut'] ?? '');
        $jours = '';
        if ($debut !== '') {
            $depuis = (int) $auj->diff(new DateTimeImmutable($debut))->format('%r%a');
            // Le nombre de jours d'attente est ce qui fait agir : « depuis
            // 12 jours » se lit autrement qu'une date.
            if ($depuis < 0) { $jours = ' — en attente depuis ' . abs($depuis) . ' jour(s)'; }
        }
        $montant = (float) ($l['valeur'] ?? 0);
        // « 25/08/2026 » plutôt que « 2026-08-25 » : la lettre part chez un
        // fournisseur, pas dans un fichier.
        $fr = static fn (string $d): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
            ? substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4) : $d;
        $lignes[] = '· Commande ' . (string) ($l['id'] ?? '') . ' — ' . (string) ($l['magasin'] ?? '—')
            . ($montant > 0 ? ' — ' . number_format($montant, 2, ',', ' ') . ' €' : '')
            . ($debut !== '' ? ' — passée le ' . $fr($debut) : '')
            . (((string) ($l['livraison'] ?? '')) !== '' ? ' — livraison prévue le ' . $fr((string) $l['livraison']) : '')
            . $jours;
    }
    $magasins = array_values(array_unique(array_map(
        fn ($l) => (string) ($l['magasin'] ?? ''), $g['lignes'])));
    $ids = array_map(fn ($l) => (string) ($l['id'] ?? ''), $g['lignes']);
    $debuts = array_values(array_filter(array_map(fn ($l) => (string) ($l['debut'] ?? ''), $g['lignes'])));
    sort($debuts);
    $pars = array_values(array_unique(array_filter(array_map(
        fn ($l) => (string) ($l['par'] ?? ''), $g['lignes']))));
    return [
        'fournisseur' => (string) $g['nom'],
        'nCommandes' => (string) $g['n'],
        // Sans montant connu, on n'écrit pas « 0,00 € » : l'ERP ne porte aucun
        // montant sur les commandes, et un zéro se lirait comme une gratuité.
        'total' => ((float) $g['total']) > 0
            ? number_format((float) $g['total'], 2, ',', ' ') . ' €'
            : 'montant non communiqué par notre système',
        'lignes' => implode("\n", $lignes)
            . ($restant > 0 ? "\n… et " . $restant . " autre(s) commande(s) en attente." : '')
            . (((int) ($g['anciennes'] ?? 0)) > 0
                ? "\n(" . (int) $g['anciennes'] . ' commande(s) plus ancienne(s) restent marquées en attente dans notre système ; '
                  . 'elles ne font pas l’objet de ce rappel.)' : ''),
        'magasins' => implode(', ', $magasins),
        'avis' => $sansAdresse
            ? '(Ce fournisseur n’a pas d’adresse e-mail dans le cockpit : ce rappel vous est adressé à sa place.)'
            : '',
        // Les noms d'AVANT le regroupement. Un gabarit enregistré à l'époque
        // où le mail parlait d'une seule réquisition doit continuer à dire
        // quelque chose de vrai — sinon il sort tel quel, « {{magasin}} »
        // compris, ce qui s'est vu.
        'magasin' => $magasins === [] ? '—' : implode(', ', $magasins),
        'id' => implode(', ', array_filter($ids)),
        'debut' => $debuts === [] ? '—' : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $debuts[0])
            ? substr($debuts[0], 8, 2) . '/' . substr($debuts[0], 5, 2) . '/' . substr($debuts[0], 0, 4)
            : $debuts[0]),
        // Même règle que {{total}} : pas de « 0,00 € » là où le montant est
        // simplement inconnu.
        'valeur' => ((float) $g['total']) > 0
            ? number_format((float) $g['total'], 2, ',', ' ') . ' €'
            : 'montant non communiqué par notre système',
        'par' => $pars === [] ? '—' : implode(', ', $pars),
        'statut' => 'En attente',
    ];
}

/* ==========================================================================
   Relance d'une commande — l'enveloppe posée sur chaque ligne du suivi.
   Le template vit dans `ceo_app_setting.caRelanceCommande` et s'édite dans
   Paramètres, avec les autres courriers. L'envoi passe par la machine SMTP ;
   la date de relance est gardée par commande (`caRelances`) pour que la ligne
   dise « relancée le … » plutôt que de laisser relancer dix fois.
   ========================================================================== */

function caRelanceDefauts(): array
{
    return [
        'titre' => 'Commande {{cle}} — à valider',
        'message' => "La commande {{cle}} du magasin {{magasin}}, passée le {{date}}, n'a pas encore été validée{{retard}}. Merci de la traiter dans le portail fournisseur.",
        'priorite' => 'warning',
        'actionLabel' => 'Voir les commandes à valider',
        // La page du panel qui liste les commandes en attente de validation.
        'actionUrl' => 'https://atelierby.tfbuddy.com/panel/material-orders/pending',
        'jours' => 7,     // durée de visibilité de la notification
    ];
}

function caRelanceConfig(): array
{
    $v = setting('caRelanceCommande');
    return is_array($v) ? array_merge(caRelanceDefauts(), $v) : caRelanceDefauts();
}

/** État du template pour l'écran Paramètres. */
function caRelanceEtat(): array
{
    $r = setting('caRelances');
    return ['config' => caRelanceConfig(),
        'envoyees' => is_array($r) ? count($r) : 0,
        'variables' => ['cle', 'magasin', 'fournisseur', 'date', 'livraison', 'statut', 'retard', 'id']];
}

/**
 * POST /centrale/achats/relance {id} — relance UNE commande par NOTIFICATION
 * (POST /notifications), pas par e-mail : la relance vit dans l'ERP, à côté de
 * la commande, et non dans une boîte mail. La commande est relue à la source
 * (/deliveries/{id}) — on ne relance jamais sur la foi de ce que l'écran
 * affichait il y a dix minutes. La date de relance est gardée par commande
 * (`caRelances`) pour que la ligne dise « relancée le … ».
 */
function wr_ca_relance(): array
{
    $b = body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); return ['ok' => false, 'erreur' => 'commande requise']; }

    $o = PanelApi::get('/deliveries/' . $id);
    if (!is_array($o) || empty($o['id'])) {
        return ['ok' => false, 'erreur' => PanelApi::$lastError ?? 'commande introuvable'];
    }

    $fid = (int) ($o['id_supplier'] ?? 0);
    $sid = (int) ($o['id_shop'] ?? 0);
    $nom = 'Fournisseur ' . $fid;
    foreach (analyseListe(PanelApi::get('/material-suppliers') ?? []) as $f) {
        if ((int) ($f['id'] ?? 0) === $fid) { $nom = (string) ($f['name'] ?? $nom); break; }
    }
    $magasin = 'Magasin ' . $sid;
    try {
        $r = Db::rows('SELECT name FROM shops WHERE id = ?', [$sid]);
        if ($r && !empty($r[0]['name'])) { $magasin = (string) $r[0]['name']; }
    } catch (PDOException $e) { /* le nom technique fera l'affaire */ }

    $vide = static fn ($v): bool => $v === null || $v === '' || $v === 'NULL';
    $ff = strtoupper((string) ($o['supplier_fulfillment_status'] ?? ''));
    $statut = !$vide($o['supplier_rejected_at'] ?? null) ? 'refusée'
        : (!$vide($o['delivered_on'] ?? null) ? 'livrée'
        : (!$vide($o['in_transit_sent_at'] ?? null) ? 'en transit'
        : ($ff === 'FINALIZED' ? 'finalisée'
        : (!$vide($o['supplier_accepted_at'] ?? null) ? 'acceptée' : 'envoyée, pas encore acceptée'))));
    $prevue = !$vide($o['supplier_planned_delivery_date'] ?? null) ? (string) $o['supplier_planned_delivery_date']
        : (!$vide($o['expected_date'] ?? null) ? (string) $o['expected_date'] : '');
    $retard = '';
    if ($prevue !== '' && $prevue < date('Y-m-d') && $vide($o['delivered_on'] ?? null)) {
        $retard = ' (livraison prévue le ' . $prevue . ', ' . (int) floor((time() - strtotime($prevue)) / 86400) . ' jour(s) de retard)';
    }

    $vars = ['cle' => (string) ($o['order_key'] ?? ('#' . $id)), 'magasin' => $magasin,
        'fournisseur' => $nom, 'date' => substr((string) ($o['order_date'] ?? ''), 0, 10),
        'livraison' => $prevue !== '' ? $prevue : '—', 'statut' => $statut, 'retard' => $retard];

    $c = caRelanceConfig();
    $jours = max(1, (int) ($c['jours'] ?? 7));
    $corps = [
        'title' => caMailRemplir((string) $c['titre'], $vars),
        'message' => caMailRemplir((string) $c['message'], $vars),
        'priority' => (string) ($c['priorite'] ?? 'warning'),
        'status' => 'published',
        'visible_from' => date('Y-m-d H:i:s'),
        'visible_to' => date('Y-m-d H:i:s', time() + $jours * 86400),
        'type' => 'once',
        'is_global' => 0,
        // Ciblage : l'API refuse une notification non globale sans magasins,
        // et la clé qu'elle attend est `shops` (mesuré).
        'shops' => [$sid],
        // Rattachement à la commande : la notification pointe l'objet qu'elle
        // réclame, plutôt que d'être un message flottant.
        'source_type' => 'material_order',
        'source_id' => $id,
        'action_label' => (string) ($c['actionLabel'] ?? 'Voir les commandes à valider'),
        // Les variables sont acceptées dans le lien : {{cle}} ou l'identifiant
        // servent à pointer une commande précise si la page le permet un jour.
        'action_url' => caMailRemplir((string) ($c['actionUrl'] ?? ''), $vars + ['id' => (string) $id]),
    ];

    [$ok, $rep] = PanelApi::post('/notifications', $corps);
    $nid = 0;
    foreach ([$rep['inserted_id'] ?? null, $rep['id'] ?? null, $rep['data']['id'] ?? null] as $cand) {
        if (is_numeric($cand)) { $nid = (int) $cand; break; }
    }
    caMailJournal($ok ? 'relance' : 'echec',
        ($ok ? 'Relance (notification) — ' : 'Relance en échec — ') . $vars['cle'] . ' · ' . $nom
            . ($ok && $nid ? ' · notification #' . $nid : '')
            . (!$ok ? ' · ' . (string) (PanelApi::$lastError ?? '') : ''), $nom);
    if (!$ok) {
        return ['ok' => false, 'erreur' => PanelApi::$lastError ?? 'la notification a été refusée par l’API'];
    }

    $r = setting('caRelances');
    if (!is_array($r)) { $r = []; }
    $r[(string) $id] = ['quand' => date('Y-m-d H:i'), 'notification' => $nid, 'fournisseur' => $nom];
    if (count($r) > 300) { $r = array_slice($r, -300, null, true); }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caRelances', json_encode($r, JSON_UNESCAPED_UNICODE)]);

    return ['ok' => true, 'notification' => $nid, 'fournisseur' => $nom, 'quand' => $r[(string) $id]['quand']];
}
