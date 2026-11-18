<?php
declare(strict_types=1);

/**
 * L'article du Manuel opératoire « Les chiffres clés du réseau 2026 » (18/11/2026, Sam : « toutes ces valeurs doivent
 * être dans le manuel opératoire comme chiffres clés (article dans le manuel opératoire) »).
 *
 * L'article est ÉCRIT PAR LE CODE depuis les mêmes variables que le reste du cockpit — jamais une seconde copie des
 * chiffres :
 *  - food cost, personnel, panier moyen, articles par ticket : les cibles du réseau (src/cibles_reseau.php), c'est-à-dire
 *    les seuils d'alerte du Catalogue des KPI ;
 *  - clients scannés, menus et formules : les objectifs du Coach Vente (cockpit › Assistant · coachs, amCoachs).
 * Quand l'une change, l'article passe à la version suivante (1.0 → 1.1 …), note et journal à l'appui. Les personnes
 * déjà validées n'ont pas à le revoir (une précision de chiffres, pas un nouveau geste). Un texte retouché à la main dans
 * l'éditeur est remplacé au prochain changement de chiffre : la source, c'est ce fichier.
 *
 * Rangement : Article, famille 04 (Fiches de référence), sous-catégorie D7.1 « Pilotage de l'activité » du référentiel,
 * phases pré-ouverture et opérationnel, compétences C-PILOTAGE.KPI et C-PILOTAGE.Vente, formation FO-05, comme l'article
 * du dashboard (ART-PIL-01). Ouvert à TOUTE L'ÉQUIPE (18/11/2026, Sam : « ouvre l'article à toute l'équipe ») : accès
 * « tous », sans PIN, postes P1 à P12. Publié d'office ; le néerlandais est « à relire ».
 *
 * Quand : à chaque passage d'ensureSop (l'installation au déploiement, les outils du manuel de l'assistant) et des
 * routes /formation/sop/ — une fois par requête, une lecture de ligne quand rien n'a changé.
 */

require_once __DIR__ . '/cibles_reseau.php';

const MC_ID = 'art-cles-01';
const MC_GABARIT = 1;          // la version du texte ci-dessous : la changer republie l'article
const MC_PAR = 'Cockpit (chiffres clés du réseau)';

/** Un nombre à la belge : « 40 », « 3,6 », « 13,50 ». */
function mcNombre(float $v): string
{
    $s = number_format($v, 2, ',', ' ');
    return rtrim(rtrim($s, '0'), ',');
}

/**
 * Les chiffres clés tels que le cockpit les lit maintenant : [clé => valeur] ; une cible vide (un objectif du Coach Vente
 * vidé) n'y est pas. Null quand les sources ne sont pas chargées (rien à écrire).
 */
function mcChiffres(): ?array
{
    if (!function_exists('foodCostCible') || !function_exists('amCoachs')) { return null; }
    $c = [
        'foodCost' => foodCostCible(), 'personnel' => personnelCible(),
        'clientsScannes' => null, 'menus' => null,
        'articles' => articlesCible(), 'panier' => panierCible(),
    ];
    try { $o = amCoachs()['vente']['objectifs'] ?? []; } catch (Throwable $e) { return null; }
    foreach (['clientsScannes', 'menus'] as $k) { $c[$k] = isset($o[$k]) && is_numeric($o[$k]) ? (float) $o[$k] : null; }
    return array_filter($c, static fn ($v) => $v !== null);
}

/** Le contenu de l'article (le format de foContenu) pour ces chiffres. Pure. */
function mcContenu(array $c): array
{
    $e = static fn (string $fr, string $nl) => ['fr' => $fr, 'nl' => $nl];
    $pct = static fn (float $v) => mcNombre($v) . ' %';
    $etapes = [];
    $n = 0;
    $ajoute = static function (string $tFr, string $tNl, string $fr, string $nl, bool $critique = false) use (&$etapes, &$n, $e) {
        $etapes[] = ['numero' => (string) ++$n, 'titre' => $e($tFr, $tNl), 'texte' => $e($fr, $nl), 'critique' => $critique];
    };
    if (isset($c['foodCost'])) {
        $v = $pct($c['foodCost']);
        $ajoute("Food cost : $v du CA au plus", "Foodcost: hoogstens $v van de omzet",
            "Le coût matière — le coût de recette de tout ce qui est vendu — divisé par le chiffre d'affaires du mois.\n"
            . "Il se lit dans le dashboard (la matière, en % des ventes) et chez le Coach Vente.\n"
            . "Au-dessus de $v, le levier est le prix : les produits vendus sous la médiane des boulangeries concurrentes montent à la médiane, avec un lot qui garde le prix actuel pour qui prend plus.",
            "De grondstofkost — de receptkost van alles wat verkocht wordt — gedeeld door de omzet van de maand.\n"
            . "Je leest hem in het dashboard (de grondstoffen, in % van de verkoop) en bij de Verkoopcoach.\n"
            . "Boven $v is de hefboom de prijs: producten die onder de mediaan van de concurrerende bakkerijen verkocht worden, gaan naar de mediaan, met een bundel die de huidige prijs behoudt voor wie meer neemt.", true);
    }
    if (isset($c['personnel'])) {
        $v = $pct($c['personnel']); $o = $pct($c['personnel'] + 5);
        $ajoute("Frais de personnel : $v du CA au plus", "Personeelskosten: hoogstens $v van de omzet",
            "Les salaires de l'équipe — le planning du panel — divisés par le chiffre d'affaires.\n"
            . "Dashboard, ligne Main-d'œuvre : vert jusqu'à $v, orange jusqu'à $o, rouge au-delà.\n"
            . "Le levier : un planning qui suit les heures de vente, pas l'inverse.",
            "De lonen van het team — de planning van het panel — gedeeld door de omzet.\n"
            . "Dashboard, lijn Arbeid: groen tot $v, oranje tot $o, rood daarboven.\n"
            . "De hefboom: een planning die de verkoopuren volgt, niet omgekeerd.", true);
    }
    if (isset($c['clientsScannes'])) {
        $v = $pct($c['clientsScannes']);
        $ajoute("Clients scannés : $v des tickets au moins", "Gescande klanten: minstens $v van de kassatickets",
            "La part des tickets rattachés à un client — sa carte ou son application scannée en caisse — sur les 90 derniers jours (Fidélité).\n"
            . "Le geste : demander la carte à chaque client, à chaque passage.",
            "Het aandeel kassatickets dat aan een klant gekoppeld is — zijn kaart of app gescand aan de kassa — over de laatste 90 dagen (Klantenbinding).\n"
            . "Het gebaar: bij elke klant, bij elk bezoek, naar de kaart vragen.");
    }
    if (isset($c['menus'])) {
        $v = $pct($c['menus']);
        $ajoute("Menus et formules : $v du CA au moins", "Menu's en formules: minstens $v van de omzet",
            "La part du chiffre d'affaires faite par les menus et formules : la vente additionnelle.\n"
            . "Le geste : proposer la formule à chaque client du midi, et la montrer en vitrine.",
            "Het aandeel van de omzet dat menu's en formules opbrengen: de bijverkoop.\n"
            . "Het gebaar: elke middagklant de formule voorstellen, en ze in de toonbank tonen.");
    }
    if (isset($c['articles'])) {
        $v = mcNombre($c['articles']);
        $ajoute("Articles par ticket : $v au moins", "Artikelen per kassaticket: minstens $v",
            "Le nombre d'articles vendus divisé par le nombre de tickets.\n"
            . "Le geste : une proposition complémentaire à chaque client — la boisson avec la viennoiserie, le dessert avec le sandwich.",
            "Het aantal verkochte artikelen gedeeld door het aantal kassatickets.\n"
            . "Het gebaar: bij elke klant een aanvullend voorstel — de drank bij de viennoiserie, het dessert bij het broodje.");
    }
    if (isset($c['panier'])) {
        $v = mcNombre($c['panier']) . ' €';
        $ajoute("Panier moyen : $v au moins", "Gemiddeld kassaticket: minstens $v",
            "Le chiffre d'affaires divisé par le nombre de tickets.\n"
            . "Il monte avec les articles par ticket, les menus et les lots.",
            "De omzet gedeeld door het aantal kassatickets.\n"
            . "Hij stijgt met de artikelen per ticket, de menu's en de bundels.");
    }
    $regle = [
        ['numero' => '1', 'titre' => $e('Qui les règle', 'Wie ze instelt'),
         'texte' => $e("Le Catalogue des KPI du cockpit (Paramètres) règle le food cost, le personnel, le panier moyen et les articles par ticket : une valeur changée là s'applique partout — rapports, dashboard, trésorerie, prix objectif des produits, Coach Vente — et cet article passe à la version suivante.\n"
                . "Les clients scannés et les menus sont les objectifs du Coach Vente (cockpit, Assistant · coachs).",
                "De KPI-catalogus van de cockpit (Instellingen) stelt de foodcost, het personeel, het gemiddelde kassaticket en de artikelen per ticket in: een waarde die daar verandert, geldt overal — rapporten, dashboard, thesaurie, streefprijs van de producten, Verkoopcoach — en dit artikel gaat naar de volgende versie.\n"
                . "De gescande klanten en de menu's zijn de doelstellingen van de Verkoopcoach (cockpit, Assistent · coaches)."), 'critique' => false],
        ['numero' => '2', 'titre' => $e('Où les suivre', 'Waar ze op te volgen'),
         'texte' => $e("Dans le dashboard du magasin, mois en cours et mois dernier.\n"
                . "Chez le Coach Vente : « Où en sont mes objectifs 2026 ? » — il donne l'écart à chaque cible, ce qu'il vaut en euros, et le geste qui le rapproche.",
                "In het winkeldashboard, lopende maand en vorige maand.\n"
                . "Bij de Verkoopcoach: « Hoe staan mijn doelstellingen 2026 ervoor? » — hij geeft het verschil met elk doel, wat het in euro waard is, en het gebaar dat het dichterbij brengt."), 'critique' => false],
    ];
    $quiz = [];
    if (isset($c['foodCost'])) {
        $v = $c['foodCost'];
        $ch = [$pct(max(1, $v - 8)), $pct($v), $pct(min(99, $v + 10))];
        $quiz[] = ['question' => $e('Quelle est la cible de food cost du réseau ?', 'Wat is het foodcostdoel van het netwerk?'),
            'choix' => ['fr' => $ch, 'nl' => $ch], 'bonne' => 1];
    }
    if (isset($c['personnel'])) {
        $x = $pct($c['personnel'] + 3);
        $quiz[] = ['question' => $e("Un magasin est à $x de frais de personnel. Que montre le dashboard ?", "Een winkel zit op $x personeelskosten. Wat toont het dashboard?"),
            'choix' => ['fr' => ['Vert : sous la cible', 'Orange : au-dessus de la cible, à moins de cinq points', 'Rouge : plus de cinq points au-dessus'],
                        'nl' => ['Groen: onder het doel', 'Oranje: boven het doel, minder dan vijf punten', 'Rood: meer dan vijf punten erboven']], 'bonne' => 1];
    }
    $quiz[] = ['question' => $e('Où change-t-on la cible de panier moyen ?', 'Waar verander je het doel voor het gemiddelde kassaticket?'),
        'choix' => ['fr' => ['Dans le Catalogue des KPI du cockpit', 'Dans la caisse', 'Dans cet article'],
                    'nl' => ['In de KPI-catalogus van de cockpit', 'In de kassa', 'In dit artikel']], 'bonne' => 0];
    return [
        'titre' => $e('Les chiffres clés du réseau 2026', 'De kerncijfers van het netwerk 2026'),
        'objectif' => $e("Connaître les chiffres clés que le réseau vise en 2026 : ce que chacun mesure, où il se lit, et quel geste le fait bouger.",
            "De kerncijfers kennen die het netwerk in 2026 nastreeft: wat elk cijfer meet, waar je het leest, en welk gebaar het doet bewegen."),
        'pour' => $e("Toute l'équipe du magasin, de l'opérateur au franchisé (P1 à P12) : chacun sait vers quoi le magasin tend et quel geste y mène. À relire à chaque nouvelle version.",
            'Het hele winkelteam, van medewerker tot franchisenemer (P1 tot P12): iedereen weet waar de winkel naartoe wil en welk gebaar ertoe leidt. Opnieuw te lezen bij elke nieuwe versie.'),
        'duree' => '10 min de lecture',
        'sections' => [
            ['titre' => $e('Les chiffres clés', 'De kerncijfers'), 'etapes' => $etapes],
            ['titre' => $e('Qui les règle, où les suivre', 'Wie ze instelt, waar ze op te volgen'), 'etapes' => $regle],
        ],
        'points_critiques' => [
            $e('Un chiffre se lit sur le mois en cours ET face au mois dernier : un début de mois est encore instable.',
               'Een cijfer lees je over de lopende maand ÉN tegenover vorige maand: een begin van de maand is nog onstabiel.'),
            $e('Une hausse de prix se présente toujours avec son lot au prix actuel : le client fidèle garde son prix en prenant plus.',
               'Een prijsverhoging stel je altijd voor met haar bundel aan de huidige prijs: de vaste klant behoudt zijn prijs door meer te nemen.'),
        ],
        'quiz' => $quiz,
    ];
}

/** Les chiffres dits en clair pour une note de version : « food cost 40 % → 38 % ». */
function mcEcarts(array $avant, array $apres): string
{
    $lib = ['foodCost' => 'food cost', 'personnel' => 'personnel', 'clientsScannes' => 'clients scannés', 'menus' => 'menus', 'articles' => 'articles par ticket', 'panier' => 'panier moyen'];
    $t = [];
    foreach ($lib as $k => $l) {
        $a = $avant[$k] ?? null; $b = $apres[$k] ?? null;
        if ($a === $b || ($a !== null && $b !== null && abs((float) $a - (float) $b) < 0.001)) { continue; }
        $t[] = $l . ' ' . ($a === null ? '—' : mcNombre((float) $a)) . ' → ' . ($b === null ? '—' : mcNombre((float) $b));
    }
    return implode(', ', $t);
}

/**
 * Écrit ou met à jour l'article. Rend ce qui a été fait (« créé 1.0 », « version 1.1 : … ») ou null quand rien n'a
 * changé. Une fois par requête ; une panne ne casse jamais la lecture du manuel.
 */
function mcSynchroniser(): ?string
{
    static $fait = false;
    if ($fait) { return null; }
    $fait = true;
    try {
        $c = mcChiffres();
        if ($c === null || !function_exists('foContenu')) { return null; }
        $empreinte = ['gabarit' => MC_GABARIT, 'chiffres' => array_map(static fn ($v) => round((float) $v, 2), $c)];
        $r = Db::row('SELECT id, version, source FROM ceo_sop WHERE id = ?', [MC_ID]);
        $avant = $r !== null ? json_decode((string) ($r['source'] ?? ''), true) : null;
        if ($r !== null && is_array($avant) && ($avant['empreinte'] ?? null) == $empreinte) { return null; }
        $contenu = foContenu(mcContenu($c));
        if (foContenuErreur($contenu) !== null) { return null; }
        $st = foStats($contenu);
        $now = date('Y-m-d H:i:s');
        $source = json_encode(['genere' => 'src/manuel_chiffres_cles.php', 'empreinte' => $empreinte], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($r === null) {
            $ver = '1.0';
            $ordre = (int) (Db::row('SELECT COALESCE(MAX(ordre), 0) + 1 AS o FROM ceo_sop')['o'] ?? 1);
            Db::exec('INSERT INTO ceo_sop (id, code, type, dossier, poste, moment, acces, statut, version, version_revalider, langue_origine, trad_fr, trad_nl,
                        titre_fr, titre_nl, objectif_fr, objectif_nl, duree, nb_etapes, nb_photos, ordre, source, maj_le, maj_par)
                      VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [MC_ID, foCodeAuto('Article', null), 'Article', '04', 'vente', 'tous', 'publiee', $ver, $ver, 'FR', 'origine', 'a_relire',
                    $contenu['titre']['fr'], $contenu['titre']['nl'], $contenu['objectif']['fr'], $contenu['objectif']['nl'], $contenu['duree'],
                    $st['etapes'], $st['photos'], $ordre, $source, $now, MC_PAR]);
            // Le classement du Manuel du réseau (colonnes du référentiel) : comme l'article du dashboard.
            try {
                Db::exec('UPDATE ceo_sop SET souscat = ?, phases = ?, postes = ?, competences = ?, formations = ?, checklists = ? WHERE id = ?',
                    ['D7.1', '["preouverture","operationnel"]', '["P1","P2","P3","P4","P5","P6","P7","P8","P9","P10","P11","P12"]', '["C-PILOTAGE.KPI","C-PILOTAGE.Vente"]', '["FO-05"]', '[]', MC_ID]);
            } catch (Throwable $e) { /* le référentiel n'est pas encore là : l'article reste non classé */ }
            $note = 'Création : les chiffres clés du réseau, écrits depuis les cibles du cockpit';
        } else {
            $ver = foVersionSuivante((string) $r['version']);
            $ecarts = mcEcarts(is_array($avant) ? (array) ($avant['empreinte']['chiffres'] ?? []) : [], $empreinte['chiffres']);
            $note = $ecarts !== '' ? 'Chiffres clés mis à jour : ' . $ecarts : 'Texte des chiffres clés revu';
            Db::exec('UPDATE ceo_sop SET version = ?, titre_fr = ?, titre_nl = ?, objectif_fr = ?, objectif_nl = ?, duree = ?, nb_etapes = ?, nb_photos = ?,
                        trad_nl = ?, source = ?, maj_le = ?, maj_par = ? WHERE id = ?',
                [$ver, $contenu['titre']['fr'], $contenu['titre']['nl'], $contenu['objectif']['fr'], $contenu['objectif']['nl'], $contenu['duree'],
                    $st['etapes'], $st['photos'], 'a_relire', $source, $now, MC_PAR, MC_ID]);
        }
        Db::exec('INSERT INTO ceo_sop_version (sop_id, version, contenu, note, revalider, publie_le, publie_par) VALUES (?, ?, ?, ?, 0, ?, ?)',
            [MC_ID, $ver, foJson($contenu), mb_substr($note, 0, 500), $now, MC_PAR]);
        if (function_exists('journalAdd')) { try { journalAdd(MC_PAR, 'Formation', 'Les chiffres clés du réseau 2026', 'Version ' . $ver . ' publiée — ' . $note); } catch (Throwable $e) { /* */ } }
        return ($r === null ? 'créé ' : 'version ') . $ver . ' : ' . $note;
    } catch (Throwable $e) {
        error_log('mcSynchroniser : ' . $e->getMessage());
        return null;
    }
}
