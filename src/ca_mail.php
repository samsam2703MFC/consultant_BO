<?php
declare(strict_types=1);

/**
 * Centrale d'achat — e-mail « commande fournisseur ».
 *
 * Quand un franchisé passe commande (une réquisition matière naît côté panel),
 * la centrale veut un e-mail. L'ERP n'offre aucun déclencheur : la détection
 * se fait donc ici, au même rythme que les rapports (cron horaire), sur le
 * MÊME flux que l'écran « Commandes franchisés » — ep_ca_commandes() — pour ne
 * jamais raconter autre chose que ce que l'écran montre.
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
        'destinataire' => 'achat@atelierby.be',
        'copie' => '',
        'sujet' => 'Commande fournisseur — {{magasin}} → {{fournisseur}}',
        'corps' => "Bonjour,\n\n"
            . "Le magasin {{magasin}} vient de passer une commande fournisseur.\n\n"
            . "Réquisition : #{{id}}\n"
            . "Fournisseur : {{fournisseur}}\n"
            . "Début de période : {{debut}}\n"
            . "Valeur estimée : {{valeur}}\n"
            . "Passée par : {{par}}\n\n"
            . "— Cockpit CEO, Centrale d'achat",
    ];
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
    return $texte;
}

/** Corps texte → HTML sobre (le template s'écrit en texte, pas en HTML). */
function caMailHtml(string $corps): string
{
    return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#222">'
        . nl2br(htmlspecialchars($corps, ENT_QUOTES, 'UTF-8')) . '</div>';
}

/**
 * Journal des e-mails achats — chaque commande reçue et chaque envoi (réussi
 * ou non) laisse une trace, lisible dans Paramètres. Vit dans
 * `ceo_app_setting.caMailJournal`, borné aux 200 dernières entrées.
 */
function caMailJournal(string $type, string $detail, string $destinataire = ''): void
{
    $j = setting('caMailJournal');
    if (!is_array($j)) { $j = []; }
    array_unshift($j, ['quand' => date('Y-m-d H:i'), 'type' => $type,
        'detail' => mb_substr($detail, 0, 200), 'destinataire' => $destinataire]);
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caMailJournal', json_encode(array_slice($j, 0, 200), JSON_UNESCAPED_UNICODE)]);
}

/** État pour l'écran — la config, jamais rien de secret, le dernier passage et le journal. */
function caMailEtat(): array
{
    $c = caMailConfig();
    $dernier = setting('caMailDernier');
    $journal = setting('caMailJournal');
    return [
        'config' => $c,
        'smtpPret' => Smtp::configured(),
        'dernier' => is_array($dernier) ? $dernier : null,
        'journal' => is_array($journal) ? array_slice($journal, 0, 50) : [],
        'variables' => array_keys(caMailVariables([])),
    ];
}

/** POST /centrale/commandes/mail/test — un essai avec des valeurs d'exemple. */
function wr_ca_mail_test(): array
{
    $c = caMailConfig();
    if (!Smtp::configured()) { return ['ok' => false, 'erreur' => 'SMTP non configuré (Paramètres → SMTP)']; }
    $vars = caMailVariables(['id' => 999, 'magasin' => 'Magasin d’essai', 'fournisseurs' => ['Fournisseur d’essai'],
        'debut' => date('Y-m-d'), 'statut' => 'PENDING', 'valeur' => 123.45, 'par' => 'Essai']);
    $ok = Smtp::envoyer($c['destinataire'], '[ESSAI] ' . caMailRemplir($c['sujet'], $vars),
        caMailHtml(caMailRemplir($c['corps'], $vars)));
    caMailJournal($ok ? 'essai' : 'echec', $ok ? 'Essai du template envoyé'
        : ('Essai en échec — ' . (string) (Smtp::$lastError ?? '')), (string) $c['destinataire']);
    return $ok ? ['ok' => true, 'destinataire' => $c['destinataire']]
               : ['ok' => false, 'erreur' => (string) (Smtp::$lastError ?? 'échec d’envoi')];
}

/**
 * GET /centrale/commandes/mail/cron?jeton= — détecte les réquisitions jamais
 * vues et envoie l'e-mail. Appelé par bin/rapports_cron.sh, même jeton que les
 * rapports. Premier passage : on sème le jeu des vues sans envoyer.
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

    $d = ep_ca_commandes();
    $lignes = (array) ($d['lignes'] ?? []);
    if (($d['etat'] ?? '') !== 'ok') { return ['etat' => 'attente', 'motif' => (string) ($d['source'] ?? '')]; }

    $vus = setting('caMailVus');
    $ids = array_values(array_filter(array_map(fn ($l) => (int) ($l['id'] ?? 0), $lignes)));

    // Première fois : tout l'existant est considéré déjà annoncé.
    if (!is_array($vus)) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['caMailVus', json_encode($ids)]);
        return ['etat' => 'initialisation', 'semees' => count($ids)];
    }

    $envoyes = 0; $echecs = 0;
    foreach ($lignes as $l) {
        $id = (int) ($l['id'] ?? 0);
        if ($id <= 0 || in_array($id, $vus, true)) { continue; }
        $vars = caMailVariables($l);
        caMailJournal('recu', 'Commande reçue — réquisition #' . $vars['id'] . ' · ' . $vars['magasin']
            . ' → ' . $vars['fournisseur'] . ' · ' . $vars['valeur']);
        $sujet = caMailRemplir((string) $c['sujet'], $vars);
        $html = caMailHtml(caMailRemplir((string) $c['corps'], $vars));
        $ok = Smtp::envoyer((string) $c['destinataire'], $sujet, $html);
        if ($ok && trim((string) $c['copie']) !== '') { Smtp::envoyer(trim((string) $c['copie']), $sujet, $html); }
        // Échec : l'identifiant n'est PAS marqué vu — nouvel essai au prochain
        // passage, plutôt qu'une commande silencieusement jamais annoncée.
        if ($ok) { $vus[] = $id; $envoyes++; } else { $echecs++; }
        caMailJournal($ok ? 'envoye' : 'echec', ($ok ? 'E-mail envoyé — ' : 'Envoi en échec — ')
            . 'réquisition #' . $vars['id'] . ' · ' . $vars['magasin']
            . (!$ok ? ' · ' . (string) (Smtp::$lastError ?? '') : ''), (string) $c['destinataire']);
    }

    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caMailVus', json_encode(array_slice(array_values(array_unique($vus)), -500))]);
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caMailDernier', json_encode(['quand' => date('c'), 'envoyes' => $envoyes, 'echecs' => $echecs,
            'erreur' => $echecs ? (string) (Smtp::$lastError ?? '') : ''])]);

    return ['etat' => 'ok', 'envoyes' => $envoyes, 'echecs' => $echecs];
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
        'actionLabel' => 'Ouvrir la commande',
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
        'variables' => ['cle', 'magasin', 'fournisseur', 'date', 'livraison', 'statut', 'retard']];
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
        'is_global' => 0,
        // Rattachement à la commande : la notification pointe l'objet qu'elle
        // réclame, plutôt que d'être un message flottant.
        'source_type' => 'MATERIAL_ORDER',
        'source_id' => $id,
        'action_label' => (string) ($c['actionLabel'] ?? 'Ouvrir la commande'),
        'shop_id' => $sid,
        'supplier_id' => $fid,
    ];

    [$ok, $rep] = PanelApi::post('/notifications', $corps);
    $nid = 0;
    foreach ([$rep['id'] ?? null, $rep['data']['id'] ?? null] as $cand) {
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
