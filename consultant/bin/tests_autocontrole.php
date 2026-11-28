#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de l'autocontrôle qualité (src/autocontrole.php) — base SQLite en mémoire, dossier privé temporaire.
 *
 *   php consultant/bin/tests_autocontrole.php
 *
 * Les règles pures (réponse attendue, valeur d'une réponse, bilan et décision suggérée, consignes sans doublon, grille
 * d'un produit et ses surcharges, validation des grilles, catégorie d'un produit, défauts qui reviennent), les grilles
 * d'exemple (publiables telles quelles), l'enregistrement d'un contrôle (bilan recalculé au serveur, photo rangée dans
 * le dossier privé, refus sans rien écrire), la lecture d'un produit et des résultats, le brouillon et la publication.
 */
$ok = 0; $ko = 0;
// En ligne de commande, le code HTTP ne se pose pas après une sortie : l'avertissement ne dit rien du code testé.
set_error_handler(static fn (int $n, string $m): bool => str_contains($m, 'http_response_code'), E_WARNING);
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
final class Db {
  static ?PDO $p = null;
  static function config(): array { return []; }
  static function pdo(): PDO { if (!self::$p) { self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } return self::$p; }
  static function fx(string $q): string {
    $q = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $q);
    $q = (string) preg_replace('/,\s*(UNIQUE )?KEY \w+ \([^)]*\)/', '', $q);
    return (string) preg_replace('/\)\s*ENGINE=.*$/s', ')', $q);
  }
  static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
  static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
  static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
// Les doubles : réglages, corps de la requête, accès, catalogue, journal.
$GLOBALS['reglages'] = []; $GLOBALS['corps'] = []; $GLOBALS['journal'] = [];
$GLOBALS['mode'] = 'ouvert'; $GLOBALS['identite'] = null;
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['reglages'][$k] ?? $d; }
function settingEcrire(string $k, mixed $v): void { $GLOBALS['reglages'][$k] = json_decode((string) json_encode($v), true); }
function body(): array { return $GLOBALS['corps']; }
function journalAdd(string $a, string $k, ?string $p, string $m, ?string $ts = null): void { $GLOBALS['journal'][] = [$a, $k, $p, $m]; }
function accesMode(): string { return $GLOBALS['mode']; }
function accesIdentite(): ?array { return $GLOBALS['identite']; }
function accesNomMagasin(string $s): string { return ['4' => 'Halle', '5' => 'Sombreffe'][$s] ?? 'Magasin ' . $s; }
function accesMagasins(): array { return ['4' => 'Halle', '5' => 'Sombreffe']; }
// La visite du consultant (src/visites.php) : par son id ou son client_id.
$GLOBALS['visites'] = ['31' => ['id' => 31, 'client_id' => 'v-abc-123', 'shop_id' => '4', 'prevu_le' => '2026-11-26'], '32' => ['id' => 32, 'client_id' => 'v-def-456', 'shop_id' => '5', 'prevu_le' => '2026-11-26']];
function viVisiteParId(string $id): ?array { foreach ($GLOBALS['visites'] as $v) { if ((string) $v['id'] === $id || $v['client_id'] === $id) { return $v; } } return null; }
function ep_prod_catalogue(): array
{
    return [
        ['pwaId' => 6700139, 'ref' => '6700139', 'nom' => 'Cougnou Nature - 400 g.', 'categorie' => 'Cougnou', 'groupe' => 'Saison'],
        ['pwaId' => 1610004, 'ref' => '1610004', 'nom' => 'Pain au Chocolat', 'categorie' => 'Viennoiserie', 'groupe' => 'Boulangerie'],
        ['pwaId' => 1300003, 'ref' => '1300003', 'nom' => 'Pain gris 800 g', 'categorie' => 'Pains', 'groupe' => 'Boulangerie'],
        ['pwaId' => 3100001, 'ref' => '3100001', 'nom' => 'Miel - Tilleul', 'categorie' => 'Épicerie', 'groupe' => 'Épicerie'],
        ['pwaId' => null, 'ref' => 'X1', 'nom' => 'Sans identifiant', 'categorie' => 'Cougnou', 'groupe' => ''],
    ];
}
$prive = sys_get_temp_dir() . '/aq-banc-' . getmypid();
putenv('COCKPIT_PRIVE=' . $prive);
$public = sys_get_temp_dir() . '/aq-public-' . getmypid();
putenv('COCKPIT_PUBLIC=' . $public);
require __DIR__ . '/../src/autocontrole.php';

// Une image PNG de 2 × 2, lisible par getimagesizefromstring.
$im = imagecreatetruecolor(2, 2); ob_start(); imagepng($im); $png = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());

$graine = aqGraine();
$brioche = $graine['categories'][0];
$grille = aqGrilleProduit($brioche, $graine['communs'], $graine['produits']['6700139']['surcharges']);
$crit = []; foreach ($grille['criteres'] as $c) { $crit[$c['cle']] = $c; }
// Le contrôle des maquettes : cinq écarts dont un non conforme (retombé).
$ecarts = ['coloration_dessus' => 'dore', 'coloration_dessous' => 'dore', 'dorure' => 'irreguliere', 'cuisson_coeur' => 'cuit',
    'sonne_creux' => 'oui', 'volume' => 'juste', 'retombe' => 'oui', 'mie_toucher' => 'mou', 'croute' => 'fine', 'forme' => 'reguliere',
    'poids' => '412', 'odeur' => 'brioche', 'gout' => 'conforme', 'mie_bouche' => 'pateuse', 'vendeur' => 'oui',
    'ressemblance' => 'proche', 'planogramme' => 'oui'];

echo "Règles\n";
verifie('réponse attendue : échelle, oui / non, mesure', aqCible($crit['mie_toucher']) === 'souple' && aqCible($crit['retombe']) === 'non' && aqCible($crit['poids']) === '400');
verifie('échelle : la réponse attendue est conforme', (aqEvaluer($crit['mie_toucher'], 'souple')['niveau'] ?? '') === 'ok');
$mou = aqEvaluer($crit['mie_toucher'], 'mou');
verifie('échelle : « mou » à surveiller, avec sa correction', ($mou['niveau'] ?? '') === 'mineur' && ($mou['prochaine'] ?? '') === 'Ajouter 1 min de cuisson.');
verifie('oui / non : « retombé » = oui est non conforme et déclasse', (aqEvaluer($crit['retombe'], 'oui')['niveau'] ?? '') === 'majeur' && (aqEvaluer($crit['retombe'], 'oui')['decision'] ?? '') === 'declasser');
verifie('oui / non : la réponse attendue n\'a pas de correction', aqEvaluer($crit['retombe'], 'non') === ['niveau' => 'ok', 'libelle' => 'Non']);
verifie('mesure : 412 g dans 380 à 420 g', (aqEvaluer($crit['poids'], '412')['niveau'] ?? '') === 'ok');
verifie('mesure : 430 g hors tolérance, virgule acceptée', (aqEvaluer($crit['poids'], '430,0')['niveau'] ?? '') === 'mineur' && (aqEvaluer($crit['poids'], '430,0')['libelle'] ?? '') === '430 g');
verifie('réponse inconnue ou vide : rien', aqEvaluer($crit['mie_toucher'], 'spongieux') === null && aqEvaluer($crit['retombe'], '') === null && aqEvaluer($crit['poids'], 'lourd') === null);

$b = aqBilan($grille['criteres'], $ecarts);
verifie('bilan : 12 conformes, 4 à surveiller, 1 non conforme', $b['n'] === ['ok' => 12, 'mineur' => 4, 'majeur' => 1], json_encode($b['n']));
verifie('bilan : décision suggérée « déclasser », niveau non conforme', $b['decision'] === 'declasser' && $b['niveau'] === 'majeur');
$consignes = array_column($b['actions'], 'criteres', 'consigne');
verifie('bilan : « Ajouter 1 min de cuisson » regroupe la mie au toucher et la mie en bouche',
    ($consignes['Ajouter 1 min de cuisson.'] ?? []) === ['Mie au toucher', 'Mie en bouche'] && count($b['actions']) === 4);
verifie('bilan : aucun critère oublié', $b['manquants'] === [] && $b['reclamation'] === false);
$sans = $ecarts; unset($sans['sonne_creux'], $sans['poids']);
verifie('bilan : un critère obligatoire sans réponse est signalé, pas le facultatif', aqBilan($grille['criteres'], $sans)['manquants'] === ['sonne_creux']);
$tout = []; foreach ($grille['criteres'] as $c) { $tout[$c['cle']] = aqCible($c); }
$bc = aqBilan($grille['criteres'], $tout);
verifie('bilan : tout à la cible = en vente, aucun écart', $bc['decision'] === 'vente' && $bc['niveau'] === 'ok' && $bc['ecarts'] === [] && $bc['n']['ok'] === 17);
$aigre = aqBilan($grille['criteres'], array_merge($tout, ['odeur' => 'aigre']));
verifie('bilan : odeur aigre = jeter, réclamation proposée', $aigre['decision'] === 'jeter' && $aigre['reclamation'] === true);

echo "Grille d'un produit\n";
verifie('les critères de la catégorie puis les communs, dans l\'ordre des étapes',
    count($grille['criteres']) === 17 && end($grille['criteres'])['cle'] === 'planogramme' && $grille['criteres'][0]['etape'] === 'cuisson');
verifie('surcharge du produit : poids cible 400, tolérance 20', $crit['poids']['cible'] === 400 && $crit['poids']['tolerance'] === 20);
$g2 = aqGrilleProduit($brioche, $graine['communs'], ['retombe' => ['type' => 'mesure', 'libelle' => 'Retombé (à la sortie)']]);
$r2 = array_values(array_filter($g2['criteres'], fn ($c) => $c['cle'] === 'retombe'))[0];
verifie('surcharge : la valeur oui, la structure jamais', $r2['libelle'] === 'Retombé (à la sortie)' && $r2['type'] === 'oui_non');
verifie('photos par étape, présentation comprise', $grille['photos'] === ['cuisson' => 'obligatoire', 'structure' => 'facultative', 'organoleptique' => 'non', 'presentation' => 'facultative']);

echo "Validation des grilles\n";
verifie('les grilles d\'exemple sont publiables telles quelles', aqErreurs($graine) === [], json_encode(aqErreurs($graine), JSON_UNESCAPED_UNICODE));
$casse = $brioche;
$casse['criteres'][0]['options'][0]['cible'] = true;              // deux réponses attendues
unset($casse['criteres'][1]['options'][0]['cause']);              // une correction incomplète
$casse['criteres'][2]['etape'] = 'cuisine';                       // une étape inconnue
$casse['criteres'][3]['cle'] = $casse['criteres'][4]['cle'];      // une clé en double
$casse['photos']['structure'] = 'peut-être';
$err = implode("\n", aqValiderGrille($casse));
verifie('deux réponses attendues : refusé', str_contains($err, 'une et une seule réponse attendue'));
verifie('correction sans cause : refusée', str_contains($err, 'il manque la cause probable'));
verifie('étape inconnue, clé en double, photo impossible : refusées', str_contains($err, 'étape inconnue') && str_contains($err, 'déjà prise') && str_contains($err, 'obligatoire, facultative ou non'));
verifie('grille vide : refusée', in_array('La grille n\'a aucun critère.', aqValiderGrille(['libelle' => 'X', 'criteres' => []]), true));

echo "Catégorie d'un produit\n";
$cat = fn (array $p) => aqCategorieDe($graine, $p)['cle'] ?? null;
verifie('Cougnou : brioches & cougnous', $cat(['id' => 6700139, 'nom' => 'Cougnou Nature - 400 g.', 'categorie' => 'Cougnou']) === 'brioche');
verifie('pain au chocolat : viennoiserie, pas pain', $cat(['id' => 1, 'nom' => 'Pain au Chocolat', 'categorie' => 'Boulangerie']) === 'viennoiserie');
verifie('pain gris : pains', $cat(['id' => 2, 'nom' => 'Pain gris 800 g', 'categorie' => 'Pains']) === 'pain');
verifie('épicerie : aucune grille (les critères communs seulement)', $cat(['id' => 3, 'nom' => 'Miel - Tilleul', 'categorie' => 'Épicerie']) === null);
$force = $graine; $force['produits']['3'] = ['categorie' => 'patisserie'];
verifie('la catégorie donnée par le siège l\'emporte', (aqCategorieDe($force, ['id' => 3, 'nom' => 'Miel', 'categorie' => 'Épicerie'])['cle'] ?? null) === 'patisserie');

echo "Défauts qui reviennent\n";
$hist = [['cree_le' => '2026-11-24 08:05:00', 'ecarts' => [['cle' => 'mie_toucher', 'libelle' => 'Mie au toucher', 'prochaine' => 'Ajouter 1 min de cuisson.']]],
    ['cree_le' => '2026-11-21 08:00:00', 'ecarts' => [['cle' => 'mie_toucher', 'libelle' => 'Mie au toucher'], ['cle' => 'dorure', 'libelle' => 'Dorure']]],
    ['cree_le' => '2026-11-19 08:10:00', 'ecarts' => [['cle' => 'mie_toucher', 'libelle' => 'Mie au toucher'], ['cle' => 'dorure', 'libelle' => 'Dorure']]]];
$rec = aqRecurrents($hist, 3);
verifie('mie molle 3 fois : signalée, avec ses jours et sa correction', count($rec) === 1 && $rec[0]['cle'] === 'mie_toucher' && $rec[0]['fois'] === 3
    && $rec[0]['jours'] === ['2026-11-24', '2026-11-21', '2026-11-19'] && $rec[0]['prochaine'] === 'Ajouter 1 min de cuisson.');
verifie('dorure 2 fois : pas encore', count(aqRecurrents($hist, 2)) === 2 && count($rec) === 1);

echo "Grilles publiées\n";
verifie('rien de publié : les tablettes lisent l\'exemple, marqué à valider', !empty(aqGrilles()['aValider']) && count(aqGrilles()['categories']) === 5);

echo "Enregistrer un contrôle\n";
$GLOBALS['corps'] = ['shop' => '4', 'produit' => 6700139, 'fournee' => '07:40', 'pieces' => 30, 'auteur' => 'Nathan',
    'reponses' => $ecarts + ['inconnu' => 'x'], 'photos' => ['cuisson' => $png, 'structure' => $png], 'decision' => 'declasser', 'prevenir' => true];
$r = wr_aq_controle();
verifie('le contrôle est enregistré', ($r['ok'] ?? false) === true && ($r['id'] ?? 0) > 0, json_encode($r, JSON_UNESCAPED_UNICODE));
$row = Db::row('SELECT * FROM con_aq_controle WHERE id = ?', [$r['id'] ?? 0]) ?? [];
verifie('bilan recalculé au serveur : non conforme, 5 écarts, suggestion déclasser', ($row['niveau'] ?? '') === 'majeur' && (int) ($row['ecarts'] ?? 0) === 5 && ($row['suggestion'] ?? '') === 'declasser');
verifie('réponses : seules celles de la grille sont gardées', !array_key_exists('inconnu', json_decode((string) ($row['reponses'] ?? '{}'), true)));
verifie('fournée, pièces, auteur, responsable prévenu', ($row['fournee'] ?? '') === '07:40' && (int) ($row['pieces'] ?? 0) === 30 && ($row['auteur'] ?? '') === 'Nathan' && (int) ($row['prevenir'] ?? 0) === 1);
$photos = Db::rows('SELECT * FROM con_aq_photo WHERE controle_id = ? ORDER BY etape', [$r['id'] ?? 0]);
verifie('deux photos rangées dans le dossier privé', count($photos) === 2 && is_file($prive . '/autocontrole/' . $photos[0]['fichier']) && $photos[0]['mime'] === 'image/png');
verifie('le journal du siège le note', str_contains((string) ($GLOBALS['journal'][0][3] ?? ''), 'déclassé · 5 écart(s) · responsable prévenu'));

$avant = (int) Db::row('SELECT COUNT(*) n FROM con_aq_controle')['n'];
$GLOBALS['corps'] = ['shop' => '4', 'produit' => 6700139, 'reponses' => $sans, 'photos' => ['cuisson' => $png]];
verifie('critère obligatoire sans réponse : refusé', (wr_aq_controle()['manquants'] ?? []) === ['sonne_creux']);
$GLOBALS['corps'] = ['shop' => '4', 'produit' => 6700139, 'reponses' => $ecarts, 'photos' => []];
verifie('photo de cuisson obligatoire : refusé', str_contains((string) (wr_aq_controle()['error'] ?? ''), 'obligatoire'));
$GLOBALS['corps'] = ['shop' => '4', 'produit' => 6700139, 'reponses' => $ecarts, 'photos' => ['cuisson' => 'data:image/png;base64,AAAA']];
verifie('photo illisible : refusée', str_contains((string) (wr_aq_controle()['error'] ?? ''), 'photo illisible'));
$GLOBALS['corps'] = ['shop' => '4', 'produit' => 999, 'reponses' => $ecarts];
verifie('produit inconnu du catalogue : refusé', (wr_aq_controle()['error'] ?? '') === 'produit inconnu du catalogue');
$GLOBALS['mode'] = 'ferme'; $GLOBALS['identite'] = ['role' => 'franchise', 'shops' => ['5']];
$GLOBALS['corps'] = ['shop' => '4', 'produit' => 6700139, 'reponses' => $ecarts, 'photos' => ['cuisson' => $png]];
verifie('un franchisé ne contrôle pas un autre magasin', (wr_aq_controle()['error'] ?? '') === 'magasin non autorisé');
verifie('refus : rien d\'écrit', (int) Db::row('SELECT COUNT(*) n FROM con_aq_controle')['n'] === $avant);
$GLOBALS['identite'] = ['role' => 'franchise', 'shops' => ['4']];
$GLOBALS['corps'] = ['shop' => '4', 'produit' => 6700139, 'reponses' => $tout, 'photos' => ['cuisson' => $png], 'essai' => true];
$essai = wr_aq_controle();
verifie('son magasin : un contrôle d\'essai est accepté, sans journal', ($essai['essai'] ?? false) === true && count($GLOBALS['journal']) === 1);
$GLOBALS['mode'] = 'ouvert'; $GLOBALS['identite'] = null;

echo "Lire\n";
$_GET = ['shop' => '4', 'produit' => '6700139'];
$p = ep_aq_produit();
verifie('le produit : sa grille (17 critères), son historique sans les essais', count($p['grille']['criteres'] ?? []) === 17 && count($p['historique'] ?? []) === 1 && ($p['historique'][0]['nbEcarts'] ?? 0) === 5);
verifie('le produit : grilles d\'exemple signalées, pas de planogramme hors du cockpit', ($p['aValider'] ?? false) === true && array_key_exists('planogramme', $p) && $p['planogramme'] === null);
$_GET = ['shop' => '4', 'produit' => '3100001'];
verifie('épicerie : les critères communs seulement', count(ep_aq_produit()['grille']['criteres'] ?? []) === 2);
$_GET = ['shop' => '4'];
$liste = ep_aq_produits();
verifie('la liste : produits avec grille d\'abord, sans les produits sans identifiant', count($liste['produits']) === 4 && $liste['produits'][3]['grille'] === null
    && ($liste['produits'][0]['aujourdhui']['niveau'] ?? '') === 'majeur');
$_GET = ['jours' => '7'];
$res = ep_aq_controles();
verifie('les résultats du réseau : sans les essais, avec les photos', count($res['controles']) === 1 && count($res['controles'][0]['photos']) === 2
    && str_starts_with($res['controles'][0]['photos'][0]['url'], '/autocontrole/magasin/photos/'));
$_GET = ['jours' => '7', 'essais' => '1'];
verifie('les essais, à la demande', count(ep_aq_controles()['controles']) === 2);

echo "Brouillon et publication\n";
$doc = aqGrilles(false);
$doc['categories'][0]['criteres'][0]['options'][0]['cause'] = '';
$GLOBALS['corps'] = $doc;
$w = wr_aq_grilles();
verifie('le brouillon s\'enregistre même incomplet, avec ses erreurs', ($w['ok'] ?? false) && isset($w['erreurs']['brioche']) && isset($GLOBALS['reglages'][AQ_REGLAGE]));
verifie('un brouillon en erreur ne se publie pas', isset(wr_aq_publier()['erreurs']['brioche']) && !isset($GLOBALS['reglages'][AQ_REGLAGE_PUBLIE]));
$doc['categories'][0]['criteres'][0]['options'][0]['cause'] = 'Four pas assez chaud.';
$doc['categories'][0]['quand'] = 'À chaque fournée.';
$GLOBALS['corps'] = $doc;
wr_aq_grilles();
$pub = wr_aq_publier();
verifie('corrigé, il se publie : les tablettes lisent la nouvelle grille', ($pub['ok'] ?? false) && empty(aqGrilles()['aValider']) && aqGrilles()['categories'][0]['quand'] === 'À chaque fournée.');
verifie('l\'état des grilles pour le siège', ep_aq_grilles()['publiee']['le'] !== null && ep_aq_grilles()['erreurs'] === []);

echo "Standard qualité (manuel des fiches techniques)\n";
$std = aqStandardCritere($crit['mie_toucher']);
verifie('mie au toucher : bon = souple ; à surveiller = mou, ferme ; pas bon = très mou, dur',
    $std['bon'] === ['Souple'] && $std['surveiller'] === ['Mou', 'Ferme'] && $std['pasBon'] === ['Très mou', 'Dur']);
verifie('oui / non : « retombé » bon = Non, pas bon = Oui', aqStandardCritere($crit['retombe'])['bon'] === ['Non'] && aqStandardCritere($crit['retombe'])['pasBon'] === ['Oui']);
verifie('mesure : la plage est bonne, hors tolérance à surveiller', aqStandardCritere($crit['poids'])['bon'] === ['380 à 420 g'] && aqStandardCritere($crit['poids'])['surveiller'] === ['Hors tolérance']);
$sp = aqStandardProduit(['id' => 6700139, 'nom' => 'Cougnou Nature - 400 g.', 'categorie' => 'Cougnou']);
verifie('le standard du Cougnou : 4 étapes, 17 critères, poids du produit', count($sp['etapes'] ?? []) === 4 && array_sum(array_map(fn ($e) => count($e['criteres']), $sp['etapes'])) === 17
    && $sp['etapes'][1]['criteres'][5]['bon'] === ['380 à 420 g']);
$se = aqStandardProduit(['id' => 3100001, 'nom' => 'Miel - Tilleul', 'categorie' => 'Épicerie']);
verifie('épicerie : la seule étape Présentation', count($se['etapes'] ?? []) === 1 && $se['etapes'][0]['etape'] === 'presentation');

echo "Réglage d'un produit (fiche produit du cockpit)\n";
$a = ep_aq_produit_admin(1610004);
verifie('pain au chocolat : grille par mots-clés, rien de forcé', ($a['grille']['cle'] ?? '') === 'viennoiserie' && $a['grille']['forcee'] === false && $a['mesures'] === []);
$GLOBALS['corps'] = ['categorie' => 'brioche'];
$w = wr_aq_produit_admin(1610004);
verifie('grille forcée : brioche, effective tout de suite', ($w['grille']['cle'] ?? '') === 'brioche' && $w['grille']['forcee'] === true && $w['grille']['auto'] === 'viennoiserie'
    && (aqCategorieDe(aqGrilles(), ['id' => 1610004, 'nom' => 'Pain au Chocolat'])['cle'] ?? '') === 'brioche');
$GLOBALS['corps'] = ['surcharges' => ['poids' => ['cible' => 90, 'tolerance' => 5], 'odeur' => ['cible' => 1, 'tolerance' => 1]]];
$w = wr_aq_produit_admin(1610004);
$poids = array_values(array_filter($w['mesures'] ?? [], fn ($m) => $m['cle'] === 'poids'))[0] ?? [];
verifie('poids propre au produit : 90 g ± 5, le critère non mesuré est ignoré', ($poids['produit'] ?? []) === ['cible' => 90, 'tolerance' => 5]
    && !isset($GLOBALS['reglages']['autocontroleProduits']['1610004']['surcharges']['odeur']));
$GLOBALS['corps'] = ['surcharges' => ['poids' => ['cible' => 90]]];
verifie('une cible sans tolérance : refusée', str_contains((string) (wr_aq_produit_admin(1610004)['error'] ?? ''), 'ensemble'));
$GLOBALS['corps'] = ['categorie' => 'inconnue'];
verifie('une grille inconnue : refusée', (wr_aq_produit_admin(1610004)['error'] ?? '') === 'catégorie de grille inconnue');
$GLOBALS['corps'] = ['photo' => $png];
$w = wr_aq_produit_admin(1610004);
verifie('photo de référence qualité : rangée dans uploads/autocontrole, lue par la tablette', str_starts_with((string) ($w['photo']['qualite'] ?? ''), 'uploads/autocontrole/ref-1610004-')
    && is_file($public . '/' . $w['photo']['qualite']));
$_GET = ['shop' => '4', 'produit' => '1610004'];
verifie('la tablette montre la photo qualité et la grille forcée', (ep_aq_produit()['produit']['photo'] ?? '') === $w['photo']['qualite'] && (ep_aq_produit()['grille']['cle'] ?? '') === 'brioche');
$GLOBALS['corps'] = ['categorie' => '', 'surcharges' => [], 'photo' => null];
wr_aq_produit_admin(1610004);
verifie('tout retiré : le produit n\'a plus de réglage', !isset($GLOBALS['reglages']['autocontroleProduits']['1610004']));
verifie('le Cougnou garde le poids des grilles d\'exemple', (ep_aq_produit_admin(6700139)['mesures'][0]['produit'] ?? []) === ['cible' => 400, 'tolerance' => 20]);
verifie('ses derniers contrôles sur 30 jours (sans les essais)', (ep_aq_produit_admin(6700139)['resultats']['n'] ?? 0) === 1);
verifie('route : GET et PUT /autocontrole/produits/{id}', aqRoute('GET', '/autocontrole/produits/6700139') !== null);

echo "Contrôle de 3 produits dans la visite du consultant\n";
$GLOBALS['corps'] = ['shop' => '4', 'produit' => 6700139, 'visite' => 'v-abc-123', 'auteur' => 'Camille', 'reponses' => $ecarts, 'photos' => ['cuisson' => $png], 'essai' => true, 'client_id' => 'aq-visite-0001'];
$kv = wr_aq_controle();
verifie('rattaché à la visite (par son client_id), rôle consultant, jamais un essai', ($kv['visite'] ?? null) === 31 && ($kv['role'] ?? '') === 'consultant' && ($kv['essai'] ?? true) === false);
$avant = (int) Db::row('SELECT COUNT(*) n FROM con_aq_controle')['n'];
$kv2 = wr_aq_controle();
verifie('rejoué depuis le même appareil : le même contrôle, pas un second', ($kv2['deja'] ?? false) === true && ($kv2['id'] ?? 0) === ($kv['id'] ?? -1) && (int) Db::row('SELECT COUNT(*) n FROM con_aq_controle')['n'] === $avant);
$GLOBALS['corps'] = ['shop' => '4', 'produit' => 1610004, 'visite' => '32', 'reponses' => [], 'photos' => []];
verifie('la visite d\'un autre magasin : refusée', str_contains((string) (wr_aq_controle()['error'] ?? ''), 'autre magasin'));
$GLOBALS['corps'] = ['shop' => '4', 'produit' => 1610004, 'visite' => '99', 'reponses' => [], 'photos' => []];
verifie('une visite inconnue : refusée', str_contains((string) (wr_aq_controle()['error'] ?? ''), 'visite inconnue'));
$tv = []; foreach (aqGrilleProduit(aqGraine()['categories'][1], aqGraine()['communs'])['criteres'] as $c) { $tv[$c['cle']] = aqCible($c); }
$GLOBALS['corps'] = ['shop' => '4', 'produit' => 1610004, 'role' => 'manager', 'auteur' => 'Lucie', 'reponses' => $tv, 'photos' => ['cuisson' => $png]];
verifie('hors visite : le manager contrôle, son rôle est gardé', (wr_aq_controle()['role'] ?? '') === 'manager');
$_GET = ['shop' => '4', 'visite' => '31'];
$vq = ep_aq_visite();
verifie('les contrôles de la visite, avec leurs photos', count($vq['controles']) === 1 && $vq['controles'][0]['produit'] === 'Cougnou Nature - 400 g.' && count($vq['controles'][0]['photos']) === 1
    && $vq['controles'][0]['roleNom'] === 'Consultant');
$_GET = ['shop' => '5', 'visite' => '31'];
verifie('la visite lue depuis un autre magasin : rien', ep_aq_visite()['controles'] === []);
$_GET = ['jours' => '7'];
verifie('les résultats du réseau disent qui a contrôlé', in_array('manager', array_column(ep_aq_controles()['controles'], 'role'), true));
$sq = suQualite(['id' => 31]);
$html = suQualiteHtml($sq, 'nl', fn ($x) => htmlspecialchars((string) $x));
verifie('le rapport (PDF) : le bloc en néerlandais, le produit, le lot, la photo intégrée', str_contains($html, 'De kwaliteitscontrole van de producten · 1') && str_contains($html, 'Cougnou Nature')
    && str_contains($html, 'afgeprijsd') && str_contains($html, 'data:image/png;base64,') && str_contains($html, 'Retombé après cuisson'));
$vu = suQualiteVue(['id' => 31], 'fr');
verifie('l\'écran du bureau et la page du franchisé : sans photo intégrée', ($vu['controles'][0]['niveauTxt'] ?? '') === 'Non conforme' && count($vu['controles'][0]['ecarts']) === 5
    && ($vu['controles'][0]['photo'] ?? false) === true && !str_contains((string) json_encode($vu), 'base64'));
verifie('une visite sans contrôle : pas de bloc', suQualite(['id' => 77]) === null && suQualiteHtml(null, 'fr', 'htmlspecialchars') === '');
aqDetacherVisites('visite_id = ?', [31]);
verifie('visite effacée : ses contrôles restent au magasin, détachés', suQualite(['id' => 31]) === null && (int) Db::row('SELECT COUNT(*) n FROM con_aq_controle WHERE produit_id = 6700139 AND client_id = ?', ['aq-visite-0001'])['n'] === 1);
verifie('route : GET /autocontrole/magasin/visite', aqRoute('GET', '/autocontrole/magasin/visite') !== null);
$suivi = (string) file_get_contents(__DIR__ . '/../src/suivi.php');
verifie('le rapport de visite appelle le bloc (gardé si le module manque)', str_contains($suivi, "if (function_exists('suQualiteHtml')) { \$h .= suQualiteHtml(suQualite(\$v), \$l, \$e); }"));

echo "Accès\n";
$acces = (string) file_get_contents(__DIR__ . '/../../shared/src/acces.php');
verifie('le franchisé a /autocontrole/magasin, pas les grilles', str_contains($acces, "'/autocontrole/magasin'") && !str_contains($acces, "'/autocontrole/grilles'"));
$routeur = (string) file_get_contents(__DIR__ . '/../../shared/public/api/index.php');
verifie('le routeur charge le module et lui passe ses routes', str_contains($routeur, "consultant/src/autocontrole.php") && str_contains($routeur, 'aqRoute($method, $path)'));
verifie('route inconnue sous /autocontrole : 404 du routeur', aqRoute('GET', '/autocontrole/rien') === null && aqRoute('GET', '/autre') === null);

// Ménage du dossier privé temporaire.
foreach (glob($prive . '/autocontrole/*') ?: [] as $f) { @unlink($f); }
@rmdir($prive . '/autocontrole'); @rmdir($prive);
foreach (glob($public . '/uploads/autocontrole/*') ?: [] as $f) { @unlink($f); }
@rmdir($public . '/uploads/autocontrole'); @rmdir($public . '/uploads'); @rmdir($public);
echo "\n$ok ok, $ko KO\n";
exit($ko ? 1 : 0);
