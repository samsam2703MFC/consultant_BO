<?php
declare(strict_types=1);

/**
 * La carte de fidélité à la caisse (12/11/2026, Sam : « faire un suivi et mise en route du système de fidélité des
 * clients : créer le process dans le POS — question “c'est la première fois que vous venez ?” ; oui : clic sur le
 * bouton et donner la carte QR ; si non : donner la carte QR avec un voucher »).
 *
 * Le processus, à la caisse de TFBuddy (développée par Szymon : docs/consignes/caisse-fidelite-szymon.md) :
 *   1. un bouton « Carte fidélité · Klantenkaart » ; la caisse pose la question « C'est la première fois que vous
 *      venez ? » ;
 *   2. « Oui » → POST /fidelite/caisse/carte {premiere: true} : la vendeuse donne la carte QR ;
 *   3. « Non » → POST /fidelite/caisse/carte {premiere: false} : la carte QR ET un bon — la caisse imprime le code que
 *      le cockpit rend (bon « code »), ou la vendeuse donne un bon papier (bon « papier »).
 * Le cockpit compose les consignes à afficher (FR / NL) et les lignes du ticket : la caisse les montre telles quelles.
 *
 * Le bon des déjà-venus est une règle du Programme : le déclencheur « carte » (src/fidelite.php) — sa valeur, sa
 * validité, son magasin (celui du magasin d'abord, sinon celui du réseau). Il vaut dès le lendemain (« sur votre
 * prochain achat ») et pendant `validite` jours.
 *  - bon « code » : le magasin a une campagne de bons au panel (codes uniques, rattachée à sa promotion — l'assistant
 *    des bons du dashboard magasin la crée, src/promos_bons.php). Le cockpit tire un code (FID + 8 signes), le rend
 *    tout de suite à la caisse, puis le crée au panel (POST /admin/voucher-campaigns/{id}/codes, une utilisation) —
 *    après la réponse, et le battement horaire reprend ce qui a échoué : le code n'a besoin d'exister que demain ;
 *  - bon « papier » : pas de campagne — la vendeuse donne un bon imprimé d'avance ; le cockpit le compte.
 *
 * L'accès de la caisse : sa clé de caisse, celle du pointage (mot_borne, sorte « caisse », src/pointage.php) — Szymon
 * la garde déjà dans la configuration de chaque caisse. Le magasin est celui de la clé, jamais celui du corps.
 *
 * La mise en route, magasin par magasin (ceo_fid_lancement) : les cartes reçues, la campagne du bon, l'équipe
 * briefée, le jour du lancement. Avant le lancement, la caisse cache le bouton (GET /fidelite/caisse/etat) et refuse
 * une remise (409) — sauf une remise d'essai (`essai`), sans code créé au panel et hors des comptes, pour les tests.
 *
 * Le suivi (GET /fidelite/caisse) : les cartes données (premières visites, déjà venus), les bons remis, le stock de
 * cartes, les cartes pour 100 tickets, par vendeuse, les nouveaux membres de l'app vus en caisse depuis le lancement,
 * les alertes (stock bas, trop de « déjà venus », plus de carte depuis trois jours, codes non créés).
 *
 * Les routes : la caisse (sa clé) — GET /fidelite/caisse/etat, POST /fidelite/caisse/carte,
 * POST /fidelite/caisse/carte/annuler ; le cockpit (session) — GET /fidelite/caisse, PUT /fidelite/caisse/lancement,
 * POST /fidelite/caisse/pousser.
 */

const FCA_BONS_JOUR = 100;        // bons au plus par magasin et par jour : au-delà, la carte seule (garde-fou)
const FCA_ANNULER_MIN = 15;       // une remise s'annule depuis la caisse pendant 15 minutes
const FCA_DOUBLE_S = 4;           // le même choix à la même caisse en moins de 4 s, sans `ref` : un double appui
const FCA_ESSAIS = 5;             // un code que le panel refuse 5 fois passe « échec » (relance à la main)
const FCA_STOCK_JOURS = 7;        // alerte quand le stock de cartes tient moins de 7 jours au rythme des 14 derniers
const FCA_SANS_CARTE_JOURS = 3;   // alerte quand un magasin lancé n'a donné aucune carte depuis 3 jours
const FCA_DEJA_MAX = 0.8;         // alerte au-delà de 80 % de « déjà venus » (20 cartes au moins sur la période)
const FCA_JOURS_MAX = 92;
const FCA_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // sans 0/O ni 1/I, qui se confondent sur un ticket

/* ======================================================================
   Stockage
   ====================================================================== */

function fcaTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_fid_carte ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'shop_id INT NOT NULL,'
        . 'remise_le DATETIME NOT NULL,'
        . 'premiere TINYINT NOT NULL,'                   // 1 : première visite (la carte) ; 0 : déjà venu (la carte et un bon)
        . 'essai TINYINT NOT NULL DEFAULT 0,'            // remise d'essai : hors des comptes, aucun code créé au panel
        . 'ref VARCHAR(64) NULL,'                        // l'identifiant de l'échange, donné par la caisse : un renvoi ne double pas
        . 'carte VARCHAR(64) NULL,'                      // le numéro de la carte, si la caisse l'a scannée
        . 'emp INT NULL,'                                // la vendeuse connectée à la caisse (franchisee_employee.id)
        . 'borne_id INT NULL,'                           // la clé de caisse (mot_borne.id)
        . 'regle_id INT NULL,'                           // le déclencheur « carte » appliqué (ceo_fid_regle.id)
        . 'bon_mode VARCHAR(8) NULL,'                    // code | papier ; NULL : la carte seule
        . 'bon_libelle VARCHAR(80) NULL,'
        . 'bon_valeur DECIMAL(6,2) NULL,'                // NULL : un produit offert
        . 'bon_code VARCHAR(24) NULL,'
        . 'bon_des DATE NULL,'
        . 'bon_fin DATE NULL,'
        . 'bon_campagne INT NULL,'
        . 'bon_etat VARCHAR(10) NULL,'                   // a_creer | cree | echec | papier | essai | a_retirer | annule
        . 'bon_code_id BIGINT NULL,'                     // le numéro du code au panel
        . 'bon_essais TINYINT NOT NULL DEFAULT 0,'
        . 'bon_erreur VARCHAR(255) NULL,'
        . 'motif VARCHAR(40) NULL,'                      // un déjà-venu sans bon : « aucun bon prévu » | « plafond du jour »
        . 'annule_le DATETIME NULL,'
        . 'UNIQUE KEY ux_fid_carte_ref (shop_id, ref), UNIQUE KEY ux_fid_carte_code (bon_code),'
        . 'KEY ix_fid_carte_shop (shop_id, remise_le), KEY ix_fid_carte_etat (bon_etat)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_fid_lancement ('
        . 'shop_id INT NOT NULL PRIMARY KEY,'
        . 'cartes_recues INT NOT NULL DEFAULT 0,'        // les cartes QR livrées au magasin, en tout
        . 'campagne INT NULL,'                           // la campagne de bons du panel (codes uniques) ; NULL : bons papier
        . 'campagne_nom VARCHAR(120) NULL,'
        . 'equipe_le DATE NULL,'                         // le jour où l'équipe a été briefée
        . 'lance_le DATE NULL,'                          // le bouton s'allume à la caisse ce jour-là
        . 'note VARCHAR(255) NULL,'
        . 'maj_le DATETIME NULL,'
        . 'maj_par VARCHAR(60) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** La mise en route d'un magasin, telle qu'elle est gardée (des valeurs vides si rien n'est encore posé). */
function fcaLancement(int $shop): array
{
    fcaTables();
    $r = Db::row('SELECT * FROM ceo_fid_lancement WHERE shop_id = ?', [$shop]);
    return [
        'shop' => $shop,
        'cartesRecues' => (int) ($r['cartes_recues'] ?? 0),
        'campagne' => isset($r['campagne']) && (int) $r['campagne'] > 0 ? (int) $r['campagne'] : null,
        'campagneNom' => $r['campagne_nom'] ?? null,
        'equipeLe' => $r['equipe_le'] ?? null,
        'lanceLe' => $r['lance_le'] ?? null,
        'note' => (string) ($r['note'] ?? ''),
        'majLe' => $r['maj_le'] ?? null,
        'majPar' => $r['maj_par'] ?? null,
    ];
}

/** Lancé ce jour-là ? */
function fcaLance(array $L, int $maintenant): bool
{
    return $L['lanceLe'] !== null && substr((string) $L['lanceLe'], 0, 10) <= date('Y-m-d', $maintenant);
}

/* ======================================================================
   Le bon des déjà-venus : la règle, le code
   ====================================================================== */

/** Le déclencheur « carte » d'un magasin : le sien d'abord, sinon celui du réseau ; null s'il n'y en a aucun d'actif. */
function fcaRegle(int $shop): ?array
{
    if (function_exists('ensureFidelite')) { ensureFidelite(); }
    $reseau = null;
    foreach (fidRegles() as $r) {
        if ($r['type'] !== 'declencheur' || !$r['actif'] || ($r['params']['evenement'] ?? '') !== 'carte') { continue; }
        if ($r['shop'] === (string) $shop) { return $r; }
        if ($r['shop'] === '' && $reseau === null) { $reseau = $r; }
    }
    return $reseau;
}

/** « 2 € », « 2,50 € ». */
function fcaEuros(float $v): string
{
    return number_format($v, fmod($v, 1.0) == 0.0 ? 0 : 2, ',', ' ') . ' €';
}

/** Une date « YYYY-MM-DD » en « JJ/MM/AAAA ». */
function fcaDateFr(?string $d): string
{
    return $d === null || $d === '' ? '' : substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4);
}

/**
 * Le bon qu'une règle donne aujourd'hui : son libellé (FR / NL), sa valeur (null pour un produit), ses dates — valable
 * dès demain et pendant `validite` jours.
 */
function fcaBonDe(array $regle, int $maintenant): array
{
    $p = $regle['params'];
    $rc = is_array($p['recompense'] ?? null) ? $p['recompense'] : ['type' => 'bon', 'valeur' => 2, 'libelle' => ''];
    $jours = max(1, (int) ($p['validite'] ?? 30));
    $des = date('Y-m-d', strtotime('tomorrow', $maintenant));
    $fin = date('Y-m-d', strtotime($des . ' +' . ($jours - 1) . ' days'));
    if (($rc['type'] ?? 'bon') === 'produit') {
        $lib = trim((string) ($rc['libelle'] ?? '')) ?: 'un produit offert';
        return ['regle' => (int) $regle['id'], 'valeur' => null, 'libelle' => mb_substr($lib, 0, 80), 'libelleNl' => mb_substr($lib, 0, 80),
            'des' => $des, 'fin' => $fin, 'jours' => $jours];
    }
    $v = round((float) ($rc['valeur'] ?? 2), 2);
    return ['regle' => (int) $regle['id'], 'valeur' => $v, 'libelle' => 'Bon de ' . fcaEuros($v), 'libelleNl' => 'Bon van ' . fcaEuros($v),
        'des' => $des, 'fin' => $fin, 'jours' => $jours];
}

/** Un code de bon lisible : FID + 8 signes (ESSAI + 6 pour un essai, qui n'est jamais créé au panel). */
function fcaCodeNeuf(bool $essai): string
{
    $n = $essai ? 6 : 8;
    $c = '';
    for ($i = 0; $i < $n; $i++) { $c .= FCA_ALPHABET[random_int(0, strlen(FCA_ALPHABET) - 1)]; }
    return ($essai ? 'ESSAI' : 'FID') . $c;
}

/* ======================================================================
   La caisse : sa clé, ses trois routes
   ====================================================================== */

/** Le corps JSON de la requête. */
function fcaCorps(): array
{
    if (function_exists('body')) { return body(); }
    $b = json_decode((string) file_get_contents('php://input'), true);
    return is_array($b) ? $b : [];
}

/** La clé de caisse : sa borne, ou une erreur (404 inconnue ou retirée, 403 lien d'un écran). */
function fcaBorne(string $cle): array
{
    $b = function_exists('ptgBorneDuJeton') ? ptgBorneDuJeton($cle) : null;
    if ($b === null) { http_response_code(404); return ['error' => 'clé de caisse inconnue ou retirée']; }
    if (($b['sorte'] ?? 'ecran') !== 'caisse') { http_response_code(403); return ['error' => 'réservé à une clé de caisse']; }
    return $b;
}

/** Le nom d'un magasin. */
function fcaNomMagasin(int $shop): string
{
    static $noms = null;
    $noms ??= function_exists('planNomsMagasins') ? planNomsMagasins() : [];
    return (string) ($noms[$shop] ?? ('Magasin ' . $shop));
}

/** Ce que la caisse montre et imprime pour une remise gardée. */
function fcaReponse(array $r): array
{
    $essai = (int) $r['essai'] === 1;
    $premiere = (int) $r['premiere'] === 1;
    $mode = $r['bon_mode'] ?? null;
    $bon = null; $ticket = null;
    $E = $essai ? ['ESSAI — ', 'TEST — '] : ['', ''];
    if ($mode !== null) {
        $bon = ['mode' => (string) $mode, 'code' => $mode === 'code' ? (string) $r['bon_code'] : null,
            'libelle' => (string) $r['bon_libelle'], 'valeur' => $r['bon_valeur'] !== null ? (float) $r['bon_valeur'] : null,
            'des' => (string) $r['bon_des'], 'jusqu_au' => (string) $r['bon_fin']];
        $nl = $bon['valeur'] !== null ? 'Bon van ' . fcaEuros($bon['valeur']) : $bon['libelle'];
        if ($mode === 'code') {
            $ecran = ['fr' => $E[0] . 'Donnez la carte QR et le bon imprimé (' . $bon['libelle'] . ').',
                'nl' => $E[1] . 'Geef de QR-kaart en de afgedrukte bon (' . $nl . ').'];
            $ticket = array_values(array_filter([
                $essai ? '*** ESSAI — NON VALABLE ***' : null,
                'L’Atelier by — Bon fidélité · Klantenbon',
                $bon['valeur'] !== null ? $bon['libelle'] . ' sur votre prochain achat' : ucfirst($bon['libelle']) . ' à votre prochaine visite',
                $bon['valeur'] !== null ? $nl . ' op uw volgende aankoop' : ucfirst($bon['libelle']) . ' bij uw volgende bezoek',
                'Code : ' . $bon['code'],
                'Valable du ' . fcaDateFr($bon['des']) . ' au ' . fcaDateFr($bon['jusqu_au']),
                'Geldig van ' . fcaDateFr($bon['des']) . ' tot ' . fcaDateFr($bon['jusqu_au']),
                'Un bon par ticket · Eén bon per kasticket',
            ]));
        } else {
            $ecran = ['fr' => $E[0] . 'Donnez la carte QR et un bon papier : ' . $bon['libelle'] . ', valable jusqu’au ' . fcaDateFr($bon['jusqu_au']) . '.',
                'nl' => $E[1] . 'Geef de QR-kaart en een papieren bon: ' . $nl . ', geldig tot ' . fcaDateFr($bon['jusqu_au']) . '.'];
        }
    } elseif (!$premiere) {
        $ecran = ['fr' => $E[0] . 'Donnez la carte QR (pas de bon : ' . ($r['motif'] ?? 'aucun bon prévu') . ').',
            'nl' => $E[1] . 'Geef de QR-kaart (geen bon: ' . (($r['motif'] ?? '') === 'plafond du jour' ? 'daglimiet bereikt' : 'geen bon voorzien') . ').'];
    } else {
        $ecran = ['fr' => $E[0] . 'Donnez la carte QR au client.', 'nl' => $E[1] . 'Geef de klant de QR-kaart.'];
    }
    return ['id' => (int) $r['id'], 'premiere' => $premiere, 'essai' => $essai, 'remise' => $bon !== null ? 'carte_bon' : 'carte',
        'bon' => $bon, 'motif' => $r['motif'] ?? null, 'ecran' => $ecran, 'ticket' => $ticket, 'annule' => $r['annule_le'] !== null];
}

/**
 * GET /fidelite/caisse/etat?b= — ce que la caisse montre avant la question : le bouton s'allume-t-il (magasin lancé),
 * la question et ses deux réponses (FR / NL), le bon des déjà-venus, les cartes du jour.
 */
function ep_fidelite_caisse_etat(?int $maintenant = null): array
{
    $maintenant ??= time();
    $borne = fcaBorne((string) ($_GET['b'] ?? ''));
    if (isset($borne['error'])) { return $borne; }
    $shop = (int) $borne['shop'];
    $L = fcaLancement($shop);
    $regle = fcaRegle($shop);
    $bon = $regle !== null ? fcaBonDe($regle, $maintenant) : null;
    $mode = $L['campagne'] !== null ? 'code' : 'papier';
    $j = Db::row('SELECT COUNT(*) n, SUM(CASE WHEN bon_mode IS NOT NULL THEN 1 ELSE 0 END) b FROM ceo_fid_carte
        WHERE shop_id = ? AND essai = 0 AND annule_le IS NULL AND remise_le >= ?', [$shop, date('Y-m-d 00:00:00', $maintenant)]) ?? [];
    $avecBon = $bon !== null ? ' + ' . mb_strtolower(mb_substr($bon['libelle'], 0, 1)) . mb_substr($bon['libelle'], 1) : '';
    $avecBonNl = $bon !== null ? ' + ' . mb_strtolower(mb_substr($bon['libelleNl'], 0, 1)) . mb_substr($bon['libelleNl'], 1) : '';
    return [
        'shop' => $shop, 'magasin' => fcaNomMagasin($shop),
        'lance' => fcaLance($L, $maintenant), 'lanceLe' => $L['lanceLe'],
        'bouton' => ['fr' => 'Carte fidélité', 'nl' => 'Klantenkaart'],
        'question' => ['fr' => 'C’est la première fois que vous venez ?', 'nl' => 'Is het de eerste keer dat u bij ons komt?'],
        'oui' => ['fr' => 'Oui — la carte QR', 'nl' => 'Ja — de QR-kaart'],
        'non' => ['fr' => 'Non, déjà venu — la carte QR' . $avecBon, 'nl' => 'Nee, al eerder geweest — de QR-kaart' . $avecBonNl],
        'bon' => $bon === null ? null : ['mode' => $mode, 'libelle' => $bon['libelle'], 'valeur' => $bon['valeur'], 'jours' => $bon['jours']],
        'aujourdhui' => ['cartes' => (int) ($j['n'] ?? 0), 'bons' => (int) ($j['b'] ?? 0)],
    ];
}

/**
 * POST /fidelite/caisse/carte {b, premiere, ref?, carte?, emp?, essai?} — une carte donnée : la remise gardée, et ce
 * que la caisse montre et imprime. `premiere` : true (première visite → la carte) ou false (déjà venu → la carte et un
 * bon). `ref` : l'identifiant de l'échange, tiré par la caisse — un renvoi avec la même `ref` rend la même remise.
 */
function wr_fidelite_caisse_carte(?int $maintenant = null): array
{
    $maintenant ??= time();
    $b = fcaCorps();
    $borne = fcaBorne((string) ($b['b'] ?? ''));
    if (isset($borne['error'])) { return $borne; }
    $p = $b['premiere'] ?? null;
    if (!is_bool($p) && $p !== 0 && $p !== 1) { http_response_code(400); return ['error' => 'premiere attendu : true (première visite) ou false (déjà venu)']; }
    $premiere = (bool) $p;
    $shop = (int) $borne['shop'];
    fcaTables();
    $ref = is_string($b['ref'] ?? null) && preg_match('/^[A-Za-z0-9_.:-]{6,64}$/', $b['ref']) ? $b['ref'] : null;
    if ($ref !== null && ($deja = Db::row('SELECT * FROM ceo_fid_carte WHERE shop_id = ? AND ref = ?', [$shop, $ref])) !== null) {
        return fcaReponse($deja) + ['repris' => true];
    }
    $essai = !empty($b['essai']);
    $L = fcaLancement($shop);
    if (!$essai && !fcaLance($L, $maintenant)) {
        http_response_code(409);
        return ['error' => 'la carte fidélité n’est pas encore lancée dans ce magasin (cockpit : Fidélité › En caisse)', 'lance' => false];
    }
    if ($ref === null) {
        // Un double appui : le même choix, à la même caisse, quelques secondes plus tôt.
        $prec = Db::row('SELECT * FROM ceo_fid_carte WHERE borne_id = ? AND premiere = ? AND essai = ? AND annule_le IS NULL AND remise_le >= ? ORDER BY id DESC LIMIT 1',
            [(int) $borne['id'], $premiere ? 1 : 0, $essai ? 1 : 0, date('Y-m-d H:i:s', $maintenant - FCA_DOUBLE_S)]);
        if ($prec !== null) { return fcaReponse($prec) + ['repris' => true]; }
    }
    $carte = is_scalar($b['carte'] ?? null) ? mb_substr(trim(preg_replace('/[^\x21-\x7E]/', '', (string) $b['carte'])), 0, 64) : '';
    $emp = is_numeric($b['emp'] ?? null) && (int) $b['emp'] > 0 ? (int) $b['emp'] : null;

    $bon = null; $motif = null; $mode = null; $etat = null;
    if (!$premiere) {
        $regle = fcaRegle($shop);
        if ($regle === null) {
            $motif = 'aucun bon prévu';
        } else {
            $n = $essai ? 0 : (int) (Db::row('SELECT COUNT(*) n FROM ceo_fid_carte WHERE shop_id = ? AND essai = 0 AND bon_mode IS NOT NULL AND annule_le IS NULL AND remise_le >= ?',
                [$shop, date('Y-m-d 00:00:00', $maintenant)])['n'] ?? 0);
            if ($n >= FCA_BONS_JOUR) {
                $motif = 'plafond du jour';
            } else {
                $bon = fcaBonDe($regle, $maintenant);
                $mode = $L['campagne'] !== null ? 'code' : 'papier';
                $etat = $essai ? 'essai' : ($mode === 'code' ? 'a_creer' : 'papier');
            }
        }
    }
    $id = null;
    for ($essaiCode = 0; $essaiCode < 3 && $id === null; $essaiCode++) {
        $code = $mode === 'code' ? fcaCodeNeuf($essai) : null;
        try {
            Db::exec('INSERT INTO ceo_fid_carte (shop_id, remise_le, premiere, essai, ref, carte, emp, borne_id, regle_id, bon_mode, bon_libelle, bon_valeur, bon_code, bon_des, bon_fin, bon_campagne, bon_etat, motif)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$shop, date('Y-m-d H:i:s', $maintenant), $premiere ? 1 : 0, $essai ? 1 : 0, $ref, $carte !== '' ? $carte : null, $emp, (int) $borne['id'],
                    $bon['regle'] ?? null, $mode, $bon['libelle'] ?? null, $bon['valeur'] ?? null, $code, $bon['des'] ?? null, $bon['fin'] ?? null,
                    $mode === 'code' ? $L['campagne'] : null, $etat, $motif]);
            $id = (int) Db::pdo()->lastInsertId();
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') { throw $e; }    // seule une clé unique déjà prise se rattrape
            // Une `ref` envoyée deux fois au même instant : la remise de l'autre requête.
            if ($ref !== null && ($deja = Db::row('SELECT * FROM ceo_fid_carte WHERE shop_id = ? AND ref = ?', [$shop, $ref])) !== null) {
                return fcaReponse($deja) + ['repris' => true];
            }
            if ($code === null) { throw $e; }      // sans code, rien d'autre ne peut entrer en collision : un autre code
        }
    }
    if ($id === null) { http_response_code(500); return ['error' => 'remise non enregistrée']; }
    if (!$essai) { Db::exec('UPDATE mot_borne SET vue_le = ? WHERE id = ?', [date('Y-m-d H:i:s', $maintenant), (int) $borne['id']]); }
    return fcaReponse(Db::row('SELECT * FROM ceo_fid_carte WHERE id = ?', [$id]));
}

/**
 * POST /fidelite/caisse/carte/annuler {b, id} — une remise faite par erreur, annulée depuis la caisse dans les
 * 15 minutes : hors des comptes, et son code (s'il est créé) désactivé au panel — repris au battement s'il résiste.
 */
function wr_fidelite_caisse_annuler(?int $maintenant = null): array
{
    $maintenant ??= time();
    $b = fcaCorps();
    $borne = fcaBorne((string) ($b['b'] ?? ''));
    if (isset($borne['error'])) { return $borne; }
    fcaTables();
    $r = Db::row('SELECT * FROM ceo_fid_carte WHERE id = ? AND shop_id = ?', [(int) ($b['id'] ?? 0), (int) $borne['shop']]);
    if ($r === null) { http_response_code(404); return ['error' => 'remise introuvable pour ce magasin']; }
    if ($r['annule_le'] !== null) { return ['ok' => true, 'id' => (int) $r['id'], 'deja' => true]; }
    if (strtotime((string) $r['remise_le']) < $maintenant - FCA_ANNULER_MIN * 60) {
        http_response_code(422);
        return ['error' => 'trop tard : une remise s’annule dans les ' . FCA_ANNULER_MIN . ' minutes (ensuite, au cockpit)'];
    }
    return fcaAnnuler($r, $maintenant);
}

/** Annule une remise : son code créé au panel se désactive (ou attend le battement), un code pas encore créé ne l'est jamais. */
function fcaAnnuler(array $r, int $maintenant): array
{
    $etat = $r['bon_etat'];
    if ($etat === 'cree') {
        $etat = fcaDesactiver($r) ? 'annule' : 'a_retirer';
    } elseif ($etat !== null) {
        $etat = 'annule';
    }
    Db::exec('UPDATE ceo_fid_carte SET annule_le = ?, bon_etat = ? WHERE id = ?', [date('Y-m-d H:i:s', $maintenant), $etat, (int) $r['id']]);
    return ['ok' => true, 'id' => (int) $r['id'], 'bon' => $etat];
}

/** Désactive au panel le code d'une remise. */
function fcaDesactiver(array $r): bool
{
    if ((int) ($r['bon_code_id'] ?? 0) <= 0 || !class_exists('ErpApi') || !ErpApi::disponible()) { return false; }
    $w = ErpApi::ecrire('PATCH', '/admin/voucher-codes/' . (int) $r['bon_code_id'] . '/status', ['status' => 'DISABLED']);
    if ($w['erreur'] !== null) {
        Db::exec('UPDATE ceo_fid_carte SET bon_erreur = ? WHERE id = ?', [mb_substr('désactivation : ' . $w['erreur'], 0, 255), (int) $r['id']]);
        return false;
    }
    return true;
}

/* ======================================================================
   Les codes au panel : créés après la réponse, repris au battement
   ====================================================================== */

/** Le numéro d'un code créé : à la racine, dans `data`, dans `data.code`, dans `code`. */
function fcaIdCode($corps): ?int
{
    if (!is_array($corps)) { return null; }
    foreach ([$corps['data']['code'] ?? null, $corps['code'] ?? null, $corps['data'] ?? null, $corps] as $n) {
        if (is_array($n) && isset($n['id']) && is_numeric($n['id']) && (int) $n['id'] > 0) { return (int) $n['id']; }
    }
    return null;
}

/**
 * Crée au panel les codes en attente (et désactive ceux des remises annulées qui résistaient) : `$id` pour une seule
 * remise, sinon les plus anciennes, `$max` au plus. Rend une phrase pour le journal du battement.
 */
function fcaPousser(int $max = 30, ?int $id = null, ?int $maintenant = null): string
{
    $maintenant ??= time();
    fcaTables();
    $L = $id !== null
        ? Db::rows("SELECT * FROM ceo_fid_carte WHERE id = ? AND bon_etat IN ('a_creer', 'a_retirer')", [$id])
        : Db::rows("SELECT * FROM ceo_fid_carte WHERE bon_etat IN ('a_creer', 'a_retirer') ORDER BY id LIMIT " . max(1, min(200, $max)));
    if ($L === []) { return 'rien en attente'; }
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { return count($L) . ' en attente — API du panel indisponible'; }
    $faits = 0; $ko = 0; $retires = 0;
    foreach ($L as $r) {
        if ($r['bon_etat'] === 'a_retirer') {
            if (fcaDesactiver($r)) { Db::exec("UPDATE ceo_fid_carte SET bon_etat = 'annule' WHERE id = ?", [(int) $r['id']]); $retires++; }
            continue;
        }
        if ((string) $r['bon_fin'] < date('Y-m-d', $maintenant)) {
            Db::exec("UPDATE ceo_fid_carte SET bon_etat = 'echec', bon_erreur = ? WHERE id = ?", ['périmé avant d’être créé au panel', (int) $r['id']]);
            $ko++;
            continue;
        }
        $corps = ['code' => (string) $r['bon_code'], 'status' => 'ACTIVE', 'usage_limit' => 1,
            'valid_from' => $r['bon_des'] . ' 00:00:00', 'valid_to' => $r['bon_fin'] . ' 23:59:59'];
        $w = ErpApi::ecrire('POST', '/admin/voucher-campaigns/' . (int) $r['bon_campagne'] . '/codes', $corps);
        // Un début refusé (le panel compare les débuts en UTC, src/promos_bons.php) : le code sans début.
        if ($w['erreur'] !== null && stripos((string) $w['erreur'], 'valid_from') !== false) {
            $w = ErpApi::ecrire('POST', '/admin/voucher-campaigns/' . (int) $r['bon_campagne'] . '/codes', ['valid_from' => null] + $corps);
        }
        if ($w['erreur'] === null) {
            Db::exec("UPDATE ceo_fid_carte SET bon_etat = 'cree', bon_code_id = ?, bon_erreur = NULL, bon_essais = bon_essais + 1 WHERE id = ?", [fcaIdCode($w['corps']), (int) $r['id']]);
            $faits++;
        } else {
            $n = (int) $r['bon_essais'] + 1;
            Db::exec('UPDATE ceo_fid_carte SET bon_essais = ?, bon_erreur = ?, bon_etat = ? WHERE id = ?',
                [$n, mb_substr((string) $w['erreur'], 0, 255), $n >= FCA_ESSAIS ? 'echec' : 'a_creer', (int) $r['id']]);
            $ko++;
        }
    }
    return $faits . ' code(s) créé(s)' . ($ko ? ', ' . $ko . ' refusé(s)' : '') . ($retires ? ', ' . $retires . ' désactivé(s)' : '');
}

/** Le battement horaire (rapports/cron). */
function fcaCron(): string
{
    return fcaPousser(60);
}

/* ======================================================================
   Le cockpit : la mise en route et le suivi
   ====================================================================== */

/** Une date du corps : « YYYY-MM-DD », vide → null ; false si elle est invalide. */
function fcaDateCorps($v): string|null|false
{
    if ($v === null || $v === '') { return null; }
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : false;
}

/**
 * PUT /fidelite/caisse/lancement {shop, cartesRecues?, cartesAjout?, campagne?, equipeLe?, lanceLe?, note?, qui?} — la
 * mise en route d'un magasin. `campagne` : le numéro d'une campagne de bons du panel à codes uniques (relue pour la
 * vérifier), 0 ou null pour des bons papier.
 */
function wr_fidelite_caisse_lancement(): array
{
    $b = fcaCorps();
    $shop = (int) ($b['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'magasin attendu']; }
    $L = fcaLancement($shop);
    $avant = $L;
    $avis = [];
    if (array_key_exists('cartesRecues', $b)) {
        if (!is_numeric($b['cartesRecues']) || (int) $b['cartesRecues'] < 0) { http_response_code(422); return ['error' => 'cartes reçues : un nombre positif']; }
        $L['cartesRecues'] = min(1000000, (int) $b['cartesRecues']);
    }
    if (array_key_exists('cartesAjout', $b)) {
        if (!is_numeric($b['cartesAjout'])) { http_response_code(422); return ['error' => 'livraison : un nombre de cartes']; }
        $L['cartesRecues'] = max(0, min(1000000, $L['cartesRecues'] + (int) $b['cartesAjout']));
    }
    foreach (['equipeLe', 'lanceLe'] as $k) {
        if (!array_key_exists($k, $b)) { continue; }
        $d = fcaDateCorps($b[$k]);
        if ($d === false) { http_response_code(422); return ['error' => $k . ' : une date AAAA-MM-JJ']; }
        $L[$k] = $d;
    }
    if (array_key_exists('note', $b)) { $L['note'] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $b['note'])), 0, 255); }
    if (array_key_exists('campagne', $b)) {
        $c = (int) ($b['campagne'] ?? 0);
        if ($c <= 0) {
            $L['campagne'] = null; $L['campagneNom'] = null;
        } elseif ($c !== $L['campagne']) {
            if (!class_exists('ErpApi') || !ErpApi::disponible() || !function_exists('pbFiche')) {
                http_response_code(503);
                return ['error' => 'API du panel indisponible : la campagne ne peut pas être vérifiée'];
            }
            $f = pbFiche($c);
            if ($f === null) { http_response_code(404); return ['error' => 'campagne ' . $c . ' introuvable au panel']; }
            $camp = is_array($f['campaign'] ?? null) ? $f['campaign'] : $f;
            if (strtoupper((string) ($camp['code_type'] ?? '')) !== 'UNIQUE') { http_response_code(422); return ['error' => 'il faut une campagne à codes uniques (UNIQUE) : chaque client reçoit son code']; }
            if (!empty($camp['requires_customer'])) { http_response_code(422); return ['error' => 'il faut une campagne sans client imposé : le client n’est pas encore connu à la remise de la carte']; }
            if (strtoupper((string) ($camp['status'] ?? '')) !== 'ACTIVE') { $avis[] = 'la campagne n’est pas active au panel : ses codes ne passeront pas en caisse tant qu’elle ne l’est pas'; }
            $L['campagne'] = $c;
            $L['campagneNom'] = mb_substr((string) ($camp['name'] ?? ''), 0, 120) ?: null;
        }
    }
    $qui = mb_substr(trim((string) ($b['qui'] ?? 'Cockpit')), 0, 60) ?: 'Cockpit';
    $vals = [$shop, $L['cartesRecues'], $L['campagne'], $L['campagneNom'], $L['equipeLe'], $L['lanceLe'], $L['note'], date('Y-m-d H:i:s'), $qui];
    if (Db::row('SELECT shop_id FROM ceo_fid_lancement WHERE shop_id = ?', [$shop]) === null) {
        Db::exec('INSERT INTO ceo_fid_lancement (shop_id, cartes_recues, campagne, campagne_nom, equipe_le, lance_le, note, maj_le, maj_par) VALUES (?,?,?,?,?,?,?,?,?)', $vals);
    } else {
        Db::exec('UPDATE ceo_fid_lancement SET cartes_recues = ?, campagne = ?, campagne_nom = ?, equipe_le = ?, lance_le = ?, note = ?, maj_le = ?, maj_par = ? WHERE shop_id = ?',
            array_merge(array_slice($vals, 1), [$shop]));
    }
    $quoi = [];
    if ($L['cartesRecues'] !== $avant['cartesRecues']) { $quoi[] = $L['cartesRecues'] . ' cartes reçues'; }
    if ($L['campagne'] !== $avant['campagne']) { $quoi[] = $L['campagne'] !== null ? 'bon : campagne ' . $L['campagne'] : 'bon : papier'; }
    if ($L['equipeLe'] !== $avant['equipeLe']) { $quoi[] = 'équipe briefée ' . ($L['equipeLe'] !== null ? 'le ' . fcaDateFr($L['equipeLe']) : '(retiré)'); }
    if ($L['lanceLe'] !== $avant['lanceLe']) { $quoi[] = $L['lanceLe'] !== null ? 'lancement le ' . fcaDateFr($L['lanceLe']) : 'lancement retiré'; }
    if ($quoi !== [] && function_exists('journalAdd')) { journalAdd($qui, 'Fidélité', null, 'Carte en caisse · ' . fcaNomMagasin($shop) . ' : ' . implode(', ', $quoi)); }
    return ['ok' => true, 'lancement' => fcaLancement($shop), 'avis' => $avis];
}

/** POST /fidelite/caisse/pousser {id?} — relancer la création des codes au panel (ceux en échec repartent). */
function wr_fidelite_caisse_pousser(): array
{
    fcaTables();
    $b = fcaCorps();
    $id = (int) ($b['id'] ?? 0);
    $n = $id > 0
        ? Db::exec("UPDATE ceo_fid_carte SET bon_etat = 'a_creer', bon_essais = 0 WHERE id = ? AND bon_etat = 'echec' AND annule_le IS NULL", [$id])
        : Db::exec("UPDATE ceo_fid_carte SET bon_etat = 'a_creer', bon_essais = 0 WHERE bon_etat = 'echec' AND annule_le IS NULL AND bon_fin >= ?", [date('Y-m-d')]);
    return ['ok' => true, 'relances' => $n, 'resultat' => fcaPousser(100, $id > 0 ? $id : null)];
}

/** Les bons remis en caisse sur une période (pour le Programme, src/fidelite.php) : un magasin, ou tout le réseau. */
function fcaBonsRemis(string $shop, string $du, string $au): int
{
    try {
        fcaTables();
        $sql = 'SELECT COUNT(*) n FROM ceo_fid_carte WHERE essai = 0 AND annule_le IS NULL AND bon_mode IS NOT NULL AND remise_le >= ? AND remise_le <= ?';
        $a = [$du . ' 00:00:00', $au . ' 23:59:59'];
        if ($shop !== '') { $sql .= ' AND shop_id = ?'; $a[] = (int) $shop; }
        return (int) (Db::row($sql, $a)['n'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

/** Les clés de caisse actives par magasin : [shop => {n, vueLe}]. */
function fcaCles(): array
{
    $out = [];
    try {
        if (function_exists('ptgTables')) { ptgTables(); }
        foreach (Db::rows("SELECT shop, COUNT(*) n, MAX(vue_le) vue FROM mot_borne WHERE sorte = 'caisse' AND actif = 1 GROUP BY shop") as $r) {
            $out[(int) $r['shop']] = ['n' => (int) $r['n'], 'vueLe' => $r['vue']];
        }
    } catch (Throwable $e) { /* sans pointage : aucune clé */ }
    return $out;
}

/** Les tickets relevés d'un magasin entre deux jours (ceo_pwa_jour, src/recurrence_pwa.php) : [jour => tickets]. */
function fcaTickets(int $shop, string $du, string $au): array
{
    try {
        $out = [];
        foreach (Db::rows('SELECT jour, tickets FROM ceo_pwa_jour WHERE shop_id = ? AND jour >= ? AND jour <= ? AND lu_le IS NOT NULL', [$shop, $du, $au]) as $r) {
            $out[substr((string) $r['jour'], 0, 10)] = (int) $r['tickets'];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Les nouveaux membres de l'app vus en caisse d'un magasin depuis un jour (ceo_pwa_client × ceo_pwa_visite) ; null si illisible. */
function fcaMembres(int $shop, string $depuis): ?int
{
    try {
        $r = Db::row('SELECT COUNT(DISTINCT c.client_id) n FROM ceo_pwa_client c JOIN ceo_pwa_visite v ON v.client_id = c.client_id
            WHERE v.shop_id = ? AND c.pwa = 1 AND c.membre_depuis >= ?', [$shop, $depuis]);
        return (int) ($r['n'] ?? 0);
    } catch (Throwable $e) { return null; }
}

/** Le résumé d'une règle « carte » pour l'écran. */
function fcaRegleVue(?array $regle): ?array
{
    if ($regle === null) { return null; }
    $b = fcaBonDe($regle, time());
    return ['id' => (int) $regle['id'], 'shop' => $regle['shop'], 'libelle' => $b['libelle'], 'valeur' => $b['valeur'], 'jours' => $b['jours']];
}

/**
 * GET /fidelite/caisse?jours=30&shop= — la mise en route et le suivi : par magasin ouvert, les étapes du lancement,
 * les cartes et les bons de la période, le stock, les cartes pour 100 tickets, les nouveaux membres de l'app, les
 * alertes ; pour le réseau (ou le magasin choisi), la série par jour, les vendeuses, les dernières remises.
 */
function ep_fidelite_caisse(?int $maintenant = null): array
{
    $maintenant ??= time();
    fcaTables();
    $jours = max(7, min(FCA_JOURS_MAX, (int) ($_GET['jours'] ?? 30)));
    $filtre = (int) ($_GET['shop'] ?? 0);
    $auj = date('Y-m-d', $maintenant);
    $du = date('Y-m-d', strtotime($auj . ' -' . ($jours - 1) . ' days'));
    $du14 = date('Y-m-d', strtotime($auj . ' -13 days'));
    $mags = function_exists('fidMagasins') ? fidMagasins() : [];
    $cles = fcaCles();

    // Les remises comptées (hors essais, hors annulées), par magasin et par jour.
    $parJour = []; $parMag = []; $totaux = [];
    foreach (Db::rows('SELECT shop_id, SUBSTR(remise_le, 1, 10) jour, premiere, bon_mode, COUNT(*) n FROM ceo_fid_carte
        WHERE essai = 0 AND annule_le IS NULL GROUP BY shop_id, SUBSTR(remise_le, 1, 10), premiere, bon_mode') as $r) {
        $s = (int) $r['shop_id']; $j = (string) $r['jour']; $n = (int) $r['n'];
        $totaux[$s] = ($totaux[$s] ?? 0) + $n;
        $parMag[$s]['dernier'] = max($parMag[$s]['dernier'] ?? '', $j);
        if ($j >= $du14) { $parMag[$s]['n14'] = ($parMag[$s]['n14'] ?? 0) + $n; }
        if ($j < $du) { continue; }
        $k = (int) $r['premiere'] === 1 ? 'premieres' : 'dejaVenus';
        $parJour[$s][$j][$k] = ($parJour[$s][$j][$k] ?? 0) + $n;
        if ($r['bon_mode'] !== null) { $parJour[$s][$j]['bons'] = ($parJour[$s][$j]['bons'] ?? 0) + $n; $parJour[$s][$j][$r['bon_mode']] = ($parJour[$s][$j][$r['bon_mode']] ?? 0) + $n; }
        if ((int) $r['premiere'] === 0 && $r['bon_mode'] === null) { $parJour[$s][$j]['sansBon'] = ($parJour[$s][$j]['sansBon'] ?? 0) + $n; }
    }
    $attente = [];
    foreach (Db::rows("SELECT shop_id, bon_etat, COUNT(*) n FROM ceo_fid_carte WHERE bon_etat IN ('a_creer', 'echec', 'a_retirer') GROUP BY shop_id, bon_etat") as $r) {
        $attente[(int) $r['shop_id']][(string) $r['bon_etat']] = (int) $r['n'];
    }
    $reseauRegle = null;
    foreach (fidRegles() as $r) {
        if ($r['type'] === 'declencheur' && $r['actif'] && ($r['params']['evenement'] ?? '') === 'carte' && $r['shop'] === '') { $reseauRegle = $r; break; }
    }

    $magasins = []; $R = ['cartes' => 0, 'premieres' => 0, 'dejaVenus' => 0, 'bons' => 0, 'code' => 0, 'papier' => 0, 'sansBon' => 0,
        'aujourdhui' => 0, 'recues' => 0, 'reste' => 0, 'tickets' => 0, 'cartesTickets' => 0, 'membres' => null, 'lances' => 0];
    foreach ($mags as $m) {
        $s = (int) $m['id'];
        $L = fcaLancement($s);
        $lance = fcaLance($L, $maintenant);
        $P = ['cartes' => 0, 'premieres' => 0, 'dejaVenus' => 0, 'bons' => 0, 'code' => 0, 'papier' => 0, 'sansBon' => 0];
        foreach ($parJour[$s] ?? [] as $x) { foreach ($x as $k => $v) { $P[$k] += $v; } }
        $P['cartes'] = $P['premieres'] + $P['dejaVenus'];
        $aujN = ($parJour[$s][$auj]['premieres'] ?? 0) + ($parJour[$s][$auj]['dejaVenus'] ?? 0);
        $donnees = $totaux[$s] ?? 0;
        $reste = $L['cartesRecues'] - $donnees;
        $rythme = ($parMag[$s]['n14'] ?? 0) / 14;
        $joursRestants = $rythme > 0 ? (int) floor(max(0, $reste) / $rythme) : null;
        // Cartes pour 100 tickets : sur les jours relevés de la période (le jour en cours compte s'il est lu).
        $tk = fcaTickets($s, $du, $auj);
        $nTk = array_sum($tk);
        $nCtk = 0;
        foreach (array_keys($tk) as $j) { $nCtk += ($parJour[$s][$j]['premieres'] ?? 0) + ($parJour[$s][$j]['dejaVenus'] ?? 0); }
        $membres = $L['lanceLe'] !== null ? fcaMembres($s, (string) $L['lanceLe']) : null;
        $regle = fcaRegle($s);
        $cle = $cles[$s] ?? null;
        $etapes = [
            ['k' => 'cle', 'nom' => 'Clé de caisse', 'fait' => $cle !== null, 'txt' => $cle !== null ? ($cle['vueLe'] ? 'vue le ' . fcaDateFr(substr((string) $cle['vueLe'], 0, 10)) : 'jamais utilisée') : 'à créer (Pointages › Clé pour la caisse)'],
            ['k' => 'cartes', 'nom' => 'Cartes QR reçues', 'fait' => $L['cartesRecues'] > 0, 'txt' => $L['cartesRecues'] > 0 ? number_format($L['cartesRecues'], 0, ',', ' ') . ' cartes' : 'aucune livraison notée'],
            ['k' => 'bon', 'nom' => 'Bon des déjà-venus', 'fait' => $regle !== null, 'txt' => $regle === null ? 'aucun déclencheur « carte » actif' : (fcaBonDe($regle, $maintenant)['libelle'] . ' · ' . ($L['campagne'] !== null ? 'code imprimé (campagne ' . $L['campagne'] . ')' : 'bon papier'))],
            ['k' => 'equipe', 'nom' => 'Équipe briefée', 'fait' => $L['equipeLe'] !== null, 'txt' => $L['equipeLe'] !== null ? 'le ' . fcaDateFr($L['equipeLe']) : 'à faire'],
            ['k' => 'lance', 'nom' => 'Lancé en caisse', 'fait' => $lance, 'txt' => $L['lanceLe'] === null ? 'pas de date' : ($lance ? 'depuis le ' : 'prévu le ') . fcaDateFr($L['lanceLe'])],
            ['k' => 'premiere', 'nom' => 'Première carte donnée', 'fait' => $donnees > 0, 'txt' => $donnees > 0 ? number_format($donnees, 0, ',', ' ') . ' en tout' : 'pas encore'],
        ];
        $alertes = [];
        if ($lance && $cle === null) { $alertes[] = 'lancé sans clé de caisse : la caisse ne peut rien envoyer'; }
        if ($lance && $L['cartesRecues'] > 0 && $reste <= 0) { $alertes[] = 'plus de cartes en stock'; }
        elseif ($lance && $joursRestants !== null && $joursRestants < FCA_STOCK_JOURS) { $alertes[] = 'stock de cartes : ' . $reste . ' (≈ ' . $joursRestants . ' jour' . ($joursRestants > 1 ? 's' : '') . ')'; }
        if ($lance && $L['lanceLe'] <= date('Y-m-d', strtotime($auj . ' -' . FCA_SANS_CARTE_JOURS . ' days'))
            && ($parMag[$s]['dernier'] ?? '') < date('Y-m-d', strtotime($auj . ' -' . (FCA_SANS_CARTE_JOURS - 1) . ' days'))) {
            $alertes[] = 'aucune carte donnée depuis ' . FCA_SANS_CARTE_JOURS . ' jours';
        }
        if ($P['cartes'] >= 20 && $P['dejaVenus'] / $P['cartes'] > FCA_DEJA_MAX) {
            $alertes[] = round(100 * $P['dejaVenus'] / $P['cartes']) . ' % de « déjà venus » : la question est-elle bien posée ?';
        }
        $att = $attente[$s] ?? [];
        if (($att['echec'] ?? 0) > 0) { $alertes[] = $att['echec'] . ' code' . ($att['echec'] > 1 ? 's' : '') . ' refusé' . ($att['echec'] > 1 ? 's' : '') . ' par le panel'; }
        if (($att['a_creer'] ?? 0) > 0) {
            $vieux = Db::row("SELECT MIN(remise_le) m FROM ceo_fid_carte WHERE shop_id = ? AND bon_etat = 'a_creer'", [$s]);
            if ($vieux !== null && $vieux['m'] !== null && strtotime((string) $vieux['m']) < $maintenant - 3 * 3600) { $alertes[] = $att['a_creer'] . ' code(s) pas encore créé(s) au panel'; }
        }
        $magasins[] = ['id' => $s, 'nom' => (string) $m['nom'], 'lancement' => $L + ['lance' => $lance], 'cle' => $cle, 'etapes' => $etapes,
            'pret' => count(array_filter($etapes, static fn ($e) => $e['fait'])), 'regle' => fcaRegleVue($regle),
            'periode' => $P, 'aujourdhui' => $aujN, 'total' => $donnees,
            'stock' => ['recues' => $L['cartesRecues'], 'donnees' => $donnees, 'reste' => $reste, 'jours' => $joursRestants],
            'pour100' => $nTk > 0 ? round(100 * $nCtk / $nTk, 1) : null, 'ticketsLus' => $nTk, 'joursLus' => count($tk),
            'membres' => $membres, 'attente' => $att, 'alertes' => $alertes];
        foreach (['cartes', 'premieres', 'dejaVenus', 'bons', 'code', 'papier', 'sansBon'] as $k) { $R[$k] += $P[$k]; }
        $R['aujourdhui'] += $aujN; $R['recues'] += $L['cartesRecues']; $R['reste'] += $L['cartesRecues'] > 0 ? max(0, $reste) : 0;
        $R['tickets'] += $nTk; $R['cartesTickets'] += $nCtk; $R['lances'] += $lance ? 1 : 0;
        if ($membres !== null) { $R['membres'] = ($R['membres'] ?? 0) + $membres; }
    }
    $R['pour100'] = $R['tickets'] > 0 ? round(100 * $R['cartesTickets'] / $R['tickets'], 1) : null;

    // La série par jour, les vendeuses, les dernières remises : le magasin choisi, sinon le réseau.
    $serie = [];
    for ($j = $du; $j <= $auj; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
        $x = ['jour' => $j, 'premieres' => 0, 'dejaVenus' => 0];
        foreach ($parJour as $s => $J) {
            if ($filtre > 0 && $s !== $filtre) { continue; }
            $x['premieres'] += $J[$j]['premieres'] ?? 0; $x['dejaVenus'] += $J[$j]['dejaVenus'] ?? 0;
        }
        $serie[] = $x;
    }
    $emps = [];
    try { $emps = function_exists('venteEmployes') ? venteEmployes() : []; } catch (Throwable $e) { /* sans noms : le numéro */ }
    $w = $filtre > 0 ? ' AND shop_id = ' . $filtre : '';
    $vendeuses = array_map(static fn ($r) => ['emp' => (int) $r['emp'], 'nom' => (string) ($emps[(int) $r['emp']]['nom'] ?? ('#' . (int) $r['emp'])),
        'shop' => (int) $r['shop_id'], 'cartes' => (int) $r['n'], 'premieres' => (int) $r['p'], 'dejaVenus' => (int) $r['n'] - (int) $r['p']],
        Db::rows('SELECT emp, shop_id, COUNT(*) n, SUM(premiere) p FROM ceo_fid_carte WHERE essai = 0 AND annule_le IS NULL AND emp IS NOT NULL AND remise_le >= ?' . $w
            . ' GROUP BY emp, shop_id ORDER BY n DESC LIMIT 15', [$du . ' 00:00:00']));
    $noms = array_column($mags, 'nom', 'id');
    $dernieres = array_map(static fn ($r) => ['id' => (int) $r['id'], 'le' => (string) $r['remise_le'], 'shop' => (int) $r['shop_id'],
        'magasin' => (string) ($noms[(string) $r['shop_id']] ?? ('Magasin ' . $r['shop_id'])),
        'vendeuse' => $r['emp'] !== null ? (string) ($emps[(int) $r['emp']]['nom'] ?? ('#' . (int) $r['emp'])) : null,
        'premiere' => (int) $r['premiere'] === 1, 'essai' => (int) $r['essai'] === 1, 'annule' => $r['annule_le'] !== null,
        'bon' => $r['bon_mode'] !== null ? ['mode' => (string) $r['bon_mode'], 'libelle' => (string) $r['bon_libelle'], 'code' => $r['bon_code'],
            'fin' => (string) $r['bon_fin'], 'etat' => (string) $r['bon_etat'], 'erreur' => $r['bon_erreur']] : null,
        'motif' => $r['motif'], 'carte' => $r['carte']],
        Db::rows('SELECT * FROM ceo_fid_carte WHERE 1 = 1' . $w . ' ORDER BY id DESC LIMIT 25'));

    return ['du' => $du, 'au' => $auj, 'jours' => $jours, 'shop' => $filtre ?: null, 'magasins' => $magasins, 'reseau' => $R,
        'regleReseau' => fcaRegleVue($reseauRegle), 'serie' => $serie, 'vendeuses' => $vendeuses, 'dernieres' => $dernieres,
        'garde' => ['bonsJour' => FCA_BONS_JOUR, 'annulerMin' => FCA_ANNULER_MIN]];
}
