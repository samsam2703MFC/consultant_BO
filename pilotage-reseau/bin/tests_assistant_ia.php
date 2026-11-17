#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de l'assistant IA (src/franchise/assistant_ia.php, 17/11/2026) — sans base, sans réseau, sans clé.
 *
 *   php bin/tests_assistant_ia.php
 *
 * Une base factice sert deux documents du manuel (une SOP courte, un article long), les passages qu'on y range, les
 * réglages, et des jours de ventes ; un faux modèle joue le Professeur. On vérifie : la recherche dans le manuel, la
 * lecture par pages, la demande d'un visuel (rien d'inventé, formes et mesures ramenées au possible), les chiffres
 * relus par semaine face à l'an passé, la période d'un modèle qui glisse, et l'agent rapide (modèle, effort, sans repli,
 * sommaire en contexte, outils du manuel).
 */

// --- doublures -----------------------------------------------------------------------
final class Db
{
    public static array $sops = [];
    public static array $versions = [];
    public static array $passages = [];
    public static array $reglages = [];
    /** [jour => [tickets, ca, tickets_pro, ca_pro]] : les jours de ventes d'un magasin. */
    public static array $jours = [];

    public static function rows(string $sql, array $a = []): array
    {
        if (str_contains($sql, 'FROM ceo_sop')) { return array_values(self::$sops); }
        if (str_contains($sql, 'FROM aia_manuel_passage')) { return self::$passages; }
        if (str_contains($sql, 'FROM ceo_hist_jour') && str_contains($sql, 'GROUP BY jour')) {
            $n = count($a);
            [$du, $au] = [$a[$n - 2], $a[$n - 1]];
            $out = [];
            foreach (self::$jours as $j => $x) {
                if ($j < $du || $j > $au) { continue; }
                $out[] = ['jour' => $j, 't' => $x[0], 'ca' => $x[1], 'tp' => $x[2], 'cp' => $x[3], 'o' => $x[0] > 0 ? 1 : 0];
            }
            ksort($out);
            return $out;
        }
        return [];
    }

    public static function row(string $sql, array $a = []): ?array
    {
        if (str_contains($sql, 'COUNT(*) AS n FROM ceo_sop')) { return ['n' => count(self::$sops)]; }
        if (str_contains($sql, 'FROM ceo_sop WHERE id = ?')) { return self::$sops[$a[0]] ?? null; }
        if (str_contains($sql, 'FROM ceo_sop_version')) { $c = self::$versions[$a[0] . '|' . $a[1]] ?? null; return $c === null ? null : ['contenu' => $c]; }
        if (str_contains($sql, 'FROM aia_reglage')) { return isset(self::$reglages[$a[0]]) ? ['valeur' => self::$reglages[$a[0]]] : null; }
        return null;
    }

    public static function exec(string $sql, array $a = []): int
    {
        if (str_starts_with($sql, 'DELETE FROM aia_manuel_passage')) { self::$passages = []; return 1; }
        if (str_starts_with($sql, 'INSERT INTO aia_manuel_passage')) {
            foreach (array_chunk($a, 7) as [$id, $v, $o, $sec, $num, $t, $cle]) {
                self::$passages[] = ['sop_id' => $id, 'version' => $v, 'ordre' => $o, 'section' => $sec, 'numero' => $num, 'texte' => $t, 'cle' => $cle];
            }
            return 1;
        }
        if (str_contains($sql, 'INTO aia_reglage')) { self::$reglages[$a[0]] = $a[1]; return 1; }
        return 0;
    }
}
final class PanelApi
{
    public static function configured(): bool { return false; }
    public static function get(string $p): mixed { return null; }
}
final class Anthropic
{
    public static function config(): array { return ['cle' => 'cle-de-test', 'espace' => '']; }
    public static function configured(): bool { return true; }
}
function setting(string $k, mixed $d = null): mixed { return $d; }
function analyseListe($r): array { return is_array($r) && array_is_list($r) ? $r : []; }
function body(): array { return []; }
function journalAdd(...$x): void {}

require __DIR__ . '/../src/stats_ventes.php';
require __DIR__ . '/../src/historique_ventes.php';
require __DIR__ . '/../src/sop.php';
require __DIR__ . '/../src/assistant_magasin.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// --- le manuel factice : une SOP de fermeture, un long article ---------------------------------------------------------
$sop = static fn (string $id, string $titre, string $statut, string $dossier) => ['id' => $id, 'code' => strtoupper($id), 'type' => 'SOP', 'dossier' => $dossier,
    'poste' => 'vente', 'partie' => null, 'moment' => 'fermeture', 'acces' => null, 'statut' => $statut, 'version' => '1.2', 'version_revalider' => '0',
    'langue_origine' => 'FR', 'trad_fr' => 'origine', 'trad_nl' => 'a_relire', 'competence_id' => null, 'competence_nom' => null, 'titre_fr' => $titre,
    'titre_nl' => '', 'objectif_fr' => 'Fermer le magasin propre et en sécurité', 'objectif_nl' => '', 'duree' => '20 min', 'nb_etapes' => 3, 'nb_photos' => 0,
    'ordre' => 1, 'source' => null, 'maj_le' => '2026-11-10 10:00:00', 'maj_par' => 'test'];
Db::$sops['ferm'] = $sop('ferm', 'Fermeture du magasin', 'publiee', '03');
Db::$sops['four'] = $sop('four', 'Cuisson des viennoiseries', 'brouillon', '02');
Db::$versions['ferm|1.2'] = json_encode(['titre' => ['fr' => 'Fermeture du magasin'], 'objectif' => ['fr' => 'Fermer le magasin propre et en sécurité'],
    'sections' => [['titre' => ['fr' => 'La caisse'], 'etapes' => [
        ['titre' => ['fr' => 'Compter la caisse'], 'texte' => ['fr' => 'Comptez le fond de caisse : 150 € exactement, le reste au coffre.']],
        ['titre' => ['fr' => 'Alarme'], 'texte' => ['fr' => 'Activez l’alarme avant de sortir, code personnel.'], 'critique' => true],
    ]]], 'points_critiques' => [['fr' => 'Jamais plus de 150 € en caisse la nuit']],
    'quiz' => [['question' => ['fr' => 'Combien en caisse la nuit ?'], 'choix' => ['fr' => ['50 €', '150 €', '500 €']], 'bonne' => 1]]]);
$etapes = [];
for ($i = 1; $i <= 60; $i++) { $etapes[] = ['titre' => ['fr' => 'Plaque ' . $i], 'texte' => ['fr' => str_repeat('Enfournez les croissants à 175 °C pendant 16 minutes, buée 3 secondes. ', 4)]]; }
Db::$versions['four|1.2'] = json_encode(['titre' => ['fr' => 'Cuisson des viennoiseries'], 'sections' => [['titre' => ['fr' => 'Le four'], 'etapes' => $etapes]]]);

echo "La recherche dans le manuel\n";
$n = aiaManuelIndexer();
verifie('le manuel est découpé en passages (en-têtes, étapes, points critiques, quiz)', $n === 2 + 2 + 1 + 1 + 60, (string) $n);
verifie('rien à refaire tant que le manuel ne change pas', aiaManuelIndexer() === -1);
verifie('la clé de recherche est sans accents', aiaNorm('Pâte à CHOUX, été') === 'pate a choux ete');
verifie('les mots vides et les pluriels tombent', aiaMots('Comment fermer les caisses du magasin ?') === ['fermer', 'caisse', 'magasin'], json_encode(aiaMots('Comment fermer les caisses du magasin ?')));
$r = aiaManuelChercher('combien d’argent laisser dans la caisse le soir');
verifie('la caisse du soir trouve la SOP de fermeture', ($r['passages'][0]['id'] ?? '') === 'ferm', json_encode($r['passages'][0] ?? null, JSON_UNESCAPED_UNICODE));
$r = aiaManuelChercher('température cuisson croissant');
verifie('la cuisson trouve le document du four, brouillon signalé', ($r['passages'][0]['id'] ?? '') === 'four' && str_contains($r['passages'][0]['statut'], 'brouillon'));
verifie('trois passages par document au plus', count(array_filter($r['passages'], static fn ($p) => $p['id'] === 'four')) <= 3);
verifie('une question vide revient en erreur', isset(aiaManuelChercher('le la les')['erreur']));

echo "La lecture d'un document\n";
$L = aiaManuelLire('ferm');
verifie('la SOP entière : étapes, point critique marqué, quiz et sa réponse', str_contains($L['etapes'], '[POINT CRITIQUE] Alarme') && $L['pages'] === 1
    && ($L['quiz'][0]['bonneReponse'] ?? '') === '150 €' && $L['pointsCritiques'] === ['Jamais plus de 150 € en caisse la nuit'], json_encode($L, JSON_UNESCAPED_UNICODE));
$L1 = aiaManuelLire('four');
$L2 = aiaManuelLire('four', 2);
verifie('un long document se lit par pages, sans rien perdre', $L1['pages'] >= 2 && isset($L1['suite']) && mb_strlen($L1['etapes']) <= AIA_PAGE
    && str_contains($L1['etapes'] . $L2['etapes'] . (aiaManuelLire('four', 3)['etapes'] ?? ''), 'Plaque 60'), $L1['pages'] . ' pages');
verifie('un document inconnu revient en erreur', isset(aiaManuelLire('absent')['erreur']));
verifie('le sommaire range par famille et dit le statut', str_contains(aiaManuelSommaireTexte(), "## Nettoyage et fermeture\n- ferm · FERM · SOP · Fermeture du magasin")
    && str_contains(aiaManuelSommaireTexte(), 'brouillon (pas encore relu)'));
verifie('les sources : les documents connus, une fois chacun', array_column(aiaSources(['ferm', 'absent', 'ferm']), 'titre') === ['Fermeture du magasin']);

echo "La demande d'un visuel\n";
$base = ['forme' => 'courbe', 'titre' => 'Mes semaines', 'donnees' => 'ventes_par_semaine', 'mesure' => 'tickets', 'du' => '2026-09-07', 'au' => '2026-10-04',
    'comparer' => 'an_passe', 'jours_semaine' => [], 'heure_debut' => null, 'heure_fin' => null, 'categorie' => '', 'limite' => 0, 'entetes' => ['x'], 'lignes' => [['9']]];
$D = aiaDemande($base);
verifie('une demande de ventes ne garde aucune ligne recopiée', $D['entetes'] === [] && $D['lignes'] === [] && $D['comparer'] === 'an_passe');
verifie('des données inconnues sont écartées', aiaDemande(['donnees' => 'meteo'] + $base) === null);
verifie('un classement de produits se montre en barres, en euros ou en pièces', ($p = aiaDemande(['donnees' => 'produits', 'forme' => 'courbe', 'mesure' => 'panier'] + $base))['forme'] === 'barres'
    && $p['mesure'] === 'ca' && $p['comparer'] === 'aucune');
verifie('les catégories face au réseau se comparent en parts', ($c = aiaDemande(['donnees' => 'categories', 'comparer' => 'reseau'] + $base))['mesure'] === 'part');
$m = aiaDemande(['donnees' => 'manuel', 'forme' => 'courbe', 'entetes' => ['Produit', 'Four'], 'lignes' => [['Croissant', '175 °C'], ['Pain', '230 °C']]] + $base);
verifie('un tableau du manuel garde ses lignes, en tableau', $m['forme'] === 'tableau' && $m['lignes'][1] === ['Pain', '230 °C']);
$V = aiaVisuel($m, 5);
verifie('le tableau du manuel revient tel quel', is_array($V) && $V['tableau']['lignes'][0] === ['Croissant', '175 °C'] && $V['series'] === []);

echo "Les chiffres relus, semaine par semaine, face à l'an passé\n";
// Quatre semaines de septembre 2026 et les mêmes un an plus tôt (364 jours) : 100 clients par jour, puis 90.
foreach ([['2026-09-07', 100, 1300.0], ['2025-09-08', 90, 1080.0]] as [$d0, $t, $ca]) {
    for ($i = 0; $i < 28; $i++) { Db::$jours[date('Y-m-d', strtotime($d0 . ' +' . $i . ' days'))] = [$t, $ca, 0, 0.0]; }
}
$V = aiaVisuel($D, 5);
verifie('quatre semaines, 700 clients chacune', is_array($V) && $V['libelles'] === ['S37', 'S38', 'S39', 'S40'] && $V['series'][0]['valeurs'] === [700.0, 700.0, 700.0, 700.0], json_encode($V));
verifie('un an plus tôt, au même jour de semaine : 630 clients', ($V['series'][1]['valeurs'] ?? null) === [630.0, 630.0, 630.0, 630.0] && $V['series'][1]['role'] === 'ref', json_encode($V['series'][1] ?? null));
verifie('le tableau porte les deux séries', $V['tableau']['entetes'] === ['Période', 'Mon magasin', 'Un an plus tôt'] && $V['tableau']['lignes'][0] === ['S37', 700.0, 630.0]);
$P = aiaVisuel(['mesure' => 'panier', 'comparer' => 'aucune', 'donnees' => 'ventes_par_jour', 'forme' => 'colonnes'] + $D, 5);
verifie('le panier d’un jour : 13 €', $P['series'][0]['valeurs'][0] === 13.0 && count($P['libelles']) === 28 && $P['forme'] === 'colonnes', json_encode(array_slice($P['series'][0]['valeurs'], 0, 3)));
verifie('une période sans historique ne fabrique rien', is_string(aiaVisuel(['du' => '2026-06-01', 'au' => '2026-06-30', 'comparer' => 'aucune'] + $D, 5)));

echo "La période d'un modèle glisse avec le temps\n";
$g = aiaGlisser($D, '2026-10-05', '2026-11-16');
verifie('six semaines plus tard : six semaines plus loin, mêmes jours de semaine', $g['du'] === '2026-10-19' && $g['au'] === '2026-11-15', $g['du'] . ' → ' . $g['au']);
$g = aiaGlisser(['donnees' => 'ventes_par_mois', 'du' => '2025-11-01', 'au' => '2026-10-31'] + $D, '2026-10-31', '2026-11-17');
verifie('un modèle de mois glisse d’un mois, jusqu’à aujourd’hui', $g['du'] === '2025-12-01' && $g['au'] === '2026-11-17', $g['du'] . ' → ' . $g['au']);

echo "Le Professeur, l'agent rapide\n";
$appels = [];
$prof = static function (array $corps, array $c) use (&$appels): array {
    $appels[] = $corps;
    if (count($appels) === 1) {
        $raw = json_encode(['model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'manuel_lire', 'input' => ['id' => 'ferm']]]]);
    } else {
        $raw = json_encode(['model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 80, 'output_tokens' => 40],
            'content' => [['type' => 'text', 'text' => json_encode(['titre' => 'La caisse du soir', 'resume' => '150 € en caisse, pas plus.', 'chiffres' => [], 'points' => [],
                'priorites' => [], 'conclusion' => 'L’alarme avant de sortir.', 'suites' => [], 'visuels' => [], 'sources' => ['ferm']])]]]);
    }
    return [json_decode($raw, true), json_decode($raw), null];
};
$R = amDemander(['aujourdhui' => '2026-11-17 (mardi)'], 'Combien je laisse en caisse ?', [], 'professeur', 5, $prof);
verifie('il répond au deuxième appel', ($R['reponse']['titre'] ?? '') === 'La caisse du soir' && count($appels) === 2, json_encode($R, JSON_UNESCAPED_UNICODE));
verifie('Claude Haiku 5.5, effort bas', $appels[0]['model'] === 'claude-haiku-5-5' && $appels[0]['output_config']['effort'] === 'low');
verifie('sans repli côté serveur (Haiku n’en a pas)', !isset($appels[0]['fallbacks']));
verifie('le sommaire du manuel en contexte, mis en cache', str_contains($appels[0]['messages'][0]['content'][0]['text'], 'ferm · FERM · SOP · Fermeture du magasin')
    && isset($appels[0]['messages'][0]['content'][0]['cache_control']));
verifie('le personnage du Professeur', str_contains($appels[0]['system'][0]['text'], 'Le Professeur'));
$res = $appels[1]['messages'][count($appels[1]['messages']) - 1]['content'][0]['content'];
verifie('l’outil manuel_lire a rendu la SOP', str_contains($res, 'Comptez le fond de caisse'), mb_substr($res, 0, 200));
verifie('le schéma de réponse demande visuels et sources', in_array('visuels', amSchema()['required'], true) && in_array('sources', amSchema()['required'], true));
$it = amSchema()['properties']['visuels']['items'];
verifie('chaque champ d’un visuel est requis, aucun champ libre', $it['additionalProperties'] === false && count($it['required']) === count($it['properties']));
Db::$reglages['assistantProfesseur'] = json_encode(['modele' => 'claude-opus-5-5', 'effort' => 'medium']);
verifie('le réglage change le modèle du Professeur', aiaProfesseurModele() === ['modele' => 'claude-opus-5-5', 'effort' => 'medium']);

echo "\n" . ($ko === 0 ? "Tout est vert : $ok vérifications." : "$ko échec(s), $ok réussite(s).") . "\n";
exit($ko === 0 ? 0 : 1);
