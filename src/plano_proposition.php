<?php
declare(strict_types=1);

/*
 * Le plan du comptoir proposé d'après les ventes moyennes, moment par moment.
 *
 * La STRUCTURE du comptoir est gardée : chaque emplacement garde sa famille — le
 * groupe du produit qui l'occupe aujourd'hui (viennoiserie devant, minis derrière,
 * frigo traiteur, pains sur la table bois…) ; un emplacement libre prend celle de
 * son voisin d'étage. La proposition ne déplace donc ni le frigo ni la boulangerie :
 * elle choisit, DANS chaque famille et POUR chaque moment (matin, midi,
 * après-midi), les produits les plus vendus au comptoir, et leur quantité.
 *
 *   - Ventes : unités vendues au comptoir (clients pro retirés) pendant les heures
 *     du moment, en moyenne par magasin et par jour ouvert lu, sur la fenêtre.
 *   - Choix : les meilleures ventes du moment dans la famille (à ventes égales,
 *     l'obligatoire exigée d'abord) ; un produit en place ne sort que pour un produit
 *     qui vend nettement plus ; un produit déjà là (au moment précédent,
 *     sinon aujourd'hui) garde sa place ; les nouveaux prennent les emplacements
 *     les plus visibles (étage 1 avant, puis arrière, étage 2, étage 3). Une
 *     famille qui vend peu garde quand même ses meilleurs produits (signalés).
 *   - Lissage : d'un moment à l'autre, un emplacement ne change de produit que si
 *     l'écart de ventes compte (au moins 30 % et une demi-unité par magasin) — sinon le
 *     produit du moment voisin reste.
 *   - Quantité : ventes moyennes du moment ÷ rotation visée (combien de fois
 *     l'emplacement se vide pendant le moment), arrondie au-dessus, entre un
 *     minimum de présentation et un plafond par étage. Un produit gardé sur
 *     plusieurs moments prend la quantité de son moment le plus fort.
 *
 * Les obligatoires ne sont pas forcées : si les ventes ne leur donnent pas de
 * place, la proposition le dit (elles peuvent être posées à la main).
 */

const PP_ROTATIONS = [1.0, 1.5, 2.0, 3.0];
const PP_JOURS = [14, 28, 56];
const PP_PLAFOND = ['e3' => 15, 'e2' => 30, 'e1b' => 50, 'e1a' => 50];
const PP_MINIMUM = ['e3' => 3, 'e2' => 4, 'e1b' => 4, 'e1a' => 4];   // présentation : un rayon ne se montre pas à une pièce
const PP_SEUIL = 0.2;          // vendus moyens par magasin et par jour pendant le moment : en dessous, « vente faible »
const PP_VISIBLE = ['e1a' => 0, 'e1b' => 1, 'e2' => 2, 'e3' => 3];
const PP_ECART_REL = 0.7;      // un écart ne compte que sous 70 % …
const PP_ECART_ABS = 0.5;      // … et d'au moins une demi-unité par magasin et par moment

/**
 * Hors comptoir : les boissons chaudes, les bundles, le B2B, les extras et la livraison,
 * et ce qui a son propre froid (glaces au congélateur, yaourts) — l'épicerie sèche ne les prend pas.
 */
function ppHorsComptoir(array $p): bool
{
    $g = (string) ($p['groupe'] ?? ''); $c = (string) ($p['categorie'] ?? ''); $n = (string) ($p['nom'] ?? '');
    return in_array($g, ['Bundle & Promotion', 'B. 2 B.'], true) || $c === 'Boissons chaudes'
        || preg_match('/extra|frais de livraison|d[ée]pannage|glace|sorbet|yaourt/iu', $c . ' ' . $n) === 1;
}

/**
 * Les ventes moyennes au comptoir, par produit et par moment.
 *
 * @return array{moy:array<string,array<string,float>>,lus:int,magasins:int,sansB2b:int,nonLus:int,du:string,au:string}
 */
function ppVentes(int $jours): array
{
    $au = date('Y-m-d', strtotime('-1 day'));
    $du = date('Y-m-d', strtotime($au . ' -' . ($jours - 1) . ' days'));
    $tot = []; $lus = 0; $sansB2b = 0; $nonLus = 0; $cout = 0;
    $mags = psMagasins();
    foreach ($mags as $m) {
        for ($j = $du; $j <= $au; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
            $r = psVentesJour($m['id'], $j, $cout, 0);
            if ($r['etat'] === 'ferme') { continue; }
            if ($r['etat'] === 'sansB2b') { $sansB2b++; continue; }
            if ($r['etat'] !== 'lu') { $nonLus++; continue; }
            $lus++;
            foreach ($r['h'] as $h => $l) {
                $k = psPeriodeHeure((int) $h);
                foreach ($l as $pid => $q) { $tot[(string) $pid][$k] = ($tot[(string) $pid][$k] ?? 0.0) + (float) $q; }
            }
        }
    }
    $moy = [];
    foreach ($tot as $pid => $l) { foreach ($l as $k => $q) { $moy[$pid][$k] = $lus > 0 ? $q / $lus : 0.0; } }
    return ['moy' => $moy, 'lus' => $lus, 'magasins' => count($mags), 'sansB2b' => $sansB2b, 'nonLus' => $nonLus, 'du' => $du, 'au' => $au];
}

/**
 * La proposition : pour chaque emplacement, les produits par moment et leur quantité.
 */
function ppProposition(int $jours, float $rot): array
{
    $V = ppVentes($jours);
    if ($V['lus'] === 0) {
        return ['erreur' => 'aucune journée de vente relevée au comptoir sur la fenêtre — la proposition attend les relevés du panel'];
    }
    $moy = $V['moy'];
    $PER = array_column(PS_PERIODES, 'k');
    $nomsP = array_column(PS_PERIODES, 'nom', 'k');

    // Le catalogue : famille (groupe), nom, obligatoire exigée aujourd'hui. Clé = identifiant de caisse.
    $cat = []; $refDe = [];
    foreach (ep_prod_catalogue() as $p) {
        $pid = (string) ($p['pwaId'] ?? $p['ref']);
        $cat[$pid] = $p; $refDe[$pid] = (string) $p['ref'];
    }
    $pidDe = array_flip($refDe);
    $exige = static fn (array $p) => array_key_exists('exigible', $p) ? !empty($p['exigible']) : !empty($p['must']);

    // Le plan d'aujourd'hui, et la famille de chaque emplacement.
    $etat = psEtat();
    $E = []; foreach ($etat['emplacements'] as $e) { $E[$e['cle']] = $e; }
    $groupeDe = static function (array $o) use ($cat, $pidDe): string {
        $pid = (string) ($pidDe[(string) $o['ref']] ?? $o['ref']);
        return (string) ($cat[$pid]['groupe'] ?? ($o['groupe'] ?? ''));
    };
    $famille = [];
    foreach ($E as $cle => $e) {
        $occ = $e['occupants'] ?? [];
        if ($occ) {
            // Le produit qui tient le plus de moments donne la famille.
            usort($occ, static fn ($a, $b) => count($b['periodes']) <=> count($a['periodes']));
            $famille[$cle] = $groupeDe($occ[0]);
        }
    }
    foreach ($E as $cle => $e) {
        if (!empty($famille[$cle])) { continue; }
        // Libre : la famille du voisin le plus proche sur le même étage de la même zone.
        $best = null; $d = 99;
        foreach ($E as $c2 => $e2) {
            if ($e2['niveau'] !== $e['niveau'] || $e2['zone'] !== $e['zone'] || empty($famille[$c2])) { continue; }
            $dd = abs($e2['section'] - $e['section']);
            if ($dd < $d) { $d = $dd; $best = $famille[$c2]; }
        }
        $z = psZoneDe((int) $e['section']);
        $famille[$cle] = $best ?? ($z['type'] === 'frigo' ? 'Traiteur' : ($e['niveau'] === 'e3' ? 'Épicerie' : ($e['niveau'] === 'e2' ? 'Biscuiterie' : ($z['mat'] === 'bois' ? 'Boulangerie' : 'Viennoiserie'))));
    }
    $slotsDe = [];
    foreach ($E as $cle => $e) { $slotsDe[$famille[$cle]][] = $cle; }

    // Aujourd'hui, qui tient quel emplacement à quel moment.
    $actuel = [];
    foreach ($E as $cle => $e) { foreach ($e['occupants'] ?? [] as $o) { foreach ($o['periodes'] as $k) { $actuel[$cle][$k] = (string) $o['ref']; } } }

    // Moment par moment, famille par famille.
    $choix = [];   // cle => k => pid
    $prec = [];    // pid => cle du moment précédent
    foreach ($PER as $k) {
        $ici = [];
        foreach ($slotsDe as $fam => $cles) {
            $cand = [];
            foreach ($cat as $pid => $p) {
                if ((string) ($p['groupe'] ?? '') !== $fam || ppHorsComptoir($p)) { continue; }
                $v = $moy[$pid][$k] ?? 0.0;
                if ($v <= 0) { continue; }
                $cand[] = ['pid' => (string) $pid, 'v' => $v, 'ob' => $exige($p)];
            }
            usort($cand, static fn ($a, $b) => ($b['v'] <=> $a['v']) ?: ($b['ob'] <=> $a['ob']) ?: strcmp($a['pid'], $b['pid']));
            $retenus = array_slice($cand, 0, count($cles));
            // Avantage au produit en place : il ne sort que pour un produit qui vend nettement plus
            // (au moins 30 % et une demi-unité par magasin et par moment de plus) — échanger deux faibles ne sert à rien.
            $enPlace = [];
            foreach ($cles as $cle) { if (($actuel[$cle][$k] ?? null) !== null) { $enPlace[(string) ($pidDe[$actuel[$cle][$k]] ?? $actuel[$cle][$k])] = true; } }
            $dedans = array_fill_keys(array_column($retenus, 'pid'), true);
            foreach ($cand as $c) {
                if (!isset($enPlace[$c['pid']]) || isset($dedans[$c['pid']])) { continue; }
                for ($i = count($retenus) - 1; $i >= 0; $i--) {
                    $w = $retenus[$i];
                    if (isset($enPlace[$w['pid']])) { continue; }
                    if ($c['v'] >= PP_ECART_REL * $w['v'] || $w['v'] - $c['v'] < PP_ECART_ABS) {
                        unset($dedans[$w['pid']]); $retenus[$i] = $c; $dedans[$c['pid']] = true;
                    }
                    break;
                }
            }
            usort($retenus, static fn ($a, $b) => ($b['v'] <=> $a['v']) ?: strcmp($a['pid'], $b['pid']));
            $libres = array_fill_keys($cles, true);
            $aPlacer = [];
            foreach ($retenus as $c) {
                // Garder sa place : celle du moment précédent, sinon celle d'aujourd'hui.
                $ou = null;
                if (isset($prec[$c['pid']]) && isset($libres[$prec[$c['pid']]])) { $ou = $prec[$c['pid']]; }
                if ($ou === null) {
                    foreach ($cles as $cle) {
                        if (isset($libres[$cle]) && ($actuel[$cle][$k] ?? null) !== null && (string) ($pidDe[$actuel[$cle][$k]] ?? $actuel[$cle][$k]) === $c['pid']) { $ou = $cle; break; }
                    }
                }
                if ($ou !== null) { $choix[$ou][$k] = $c; unset($libres[$ou]); $ici[$c['pid']] = $ou; }
                else { $aPlacer[] = $c; }
            }
            // Les nouveaux : les emplacements les plus visibles d'abord.
            $rest = array_keys($libres);
            usort($rest, static fn ($a, $b) => (PP_VISIBLE[$E[$a]['niveau']] <=> PP_VISIBLE[$E[$b]['niveau']]) ?: ($E[$a]['section'] <=> $E[$b]['section']));
            foreach ($aPlacer as $i => $c) { $cle = $rest[$i]; $choix[$cle][$k] = $c; $ici[$c['pid']] = $cle; }
        }
        $prec = $ici;
    }

    // Lissage : un emplacement garde le produit du moment voisin quand l'écart de ventes ne compte pas.
    $ou = static function (array $choix, string $pid, string $k): ?string {
        foreach ($choix as $c => $l) { if (isset($l[$k]) && $l[$k]['pid'] === $pid) { return (string) $c; } }
        return null;
    };
    for ($tour = 0; $tour < 5; $tour++) {
        $bouge = false;
        foreach ($choix as $cle => $parK) {
            foreach ($PER as $i => $k) {
                $x = $choix[$cle][$k] ?? null; if ($x === null) { continue; }
                foreach ([$i - 1, $i + 1] as $jv) {
                    if (!isset($PER[$jv])) { continue; }
                    $y = $choix[$cle][$PER[$jv]] ?? null;
                    if ($y === null || $y['pid'] === $x['pid']) { continue; }
                    $vy = $moy[$y['pid']][$k] ?? 0.0;
                    if ($vy <= 0 || ($vy < PP_ECART_REL * $x['v'] && $x['v'] - $vy >= PP_ECART_ABS)) { continue; }
                    if ($ou($choix, $y['pid'], $k) !== null) { continue; }   // déjà présenté ailleurs à ce moment
                    $choix[$cle][$k] = ['pid' => $y['pid'], 'v' => $vy, 'ob' => $y['ob']];
                    $bouge = true;
                    break;
                }
            }
        }
        if (!$bouge) { break; }
    }

    // Les occupants proposés : un produit sur plusieurs moments n'en fait qu'un, à la quantité de son moment le plus fort.
    $emps = []; $nChange = 0; $nQte = 0; $nIdem = 0; $nFaibles = 0; $uAct = 0.0; $uProp = 0.0; $places = [];
    $q2 = static fn ($v) => $v === null ? null : round((float) $v, 2);
    foreach ($E as $cle => $e) {
        $parP = [];
        foreach ($PER as $k) { if (isset($choix[$cle][$k])) { $parP[$choix[$cle][$k]['pid']][$k] = $choix[$cle][$k]; } }
        $prop = [];
        foreach ($parP as $pid => $ks) {
            $besoin = max(array_map(static fn ($c) => $c['v'], $ks));
            $q = max(PP_MINIMUM[$e['niveau']], min(PP_PLAFOND[$e['niveau']], (int) ceil($besoin / $rot - 1e-9)));
            $p = $cat[$pid];
            $places[$pid] = true;
            $prop[] = ['ref' => $refDe[$pid], 'nom' => trim((string) $p['nom']), 'groupe' => $p['groupe'] ?? null,
                'periodes' => array_values(array_intersect($PER, array_keys($ks))), 'journee' => count($ks) === count($PER),
                'qte' => $q, 'plafonne' => (int) ceil($besoin / $rot - 1e-9) > PP_PLAFOND[$e['niveau']], 'faible' => $besoin < PP_SEUIL,
                'vendus' => (object) array_map(static fn ($k) => round($moy[$pid][$k] ?? 0.0, 1), array_combine($PER, $PER)),
                'obligatoire' => $exige($p)];
        }
        usort($prop, static fn ($a, $b) => array_search($a['periodes'][0], $PER, true) <=> array_search($b['periodes'][0], $PER, true));
        $act = array_map(static fn ($o) => ['ref' => (string) $o['ref'], 'nom' => $o['nom'], 'periodes' => $o['periodes'], 'qte' => $o['qte']], $e['occupants'] ?? []);
        $sig = static fn (array $l, bool $avecQ) => implode(';', array_map(static fn ($o) => $o['ref'] . '@' . implode(',', $o['periodes']) . ($avecQ ? '#' . $q2($o['qte']) : ''), $l));
        $statut = $sig($act, true) === $sig($prop, true) ? 'identique' : ($sig($act, false) === $sig($prop, false) ? 'quantite' : ($prop === [] ? 'libre' : 'change'));
        if ($statut === 'identique') { $nIdem++; } elseif ($statut === 'quantite') { $nQte++; } else { $nChange++; }
        if ($prop && !array_filter($prop, static fn ($o) => !$o['faible'])) { $nFaibles++; }
        foreach ($act as $o) { $uAct += (float) ($o['qte'] ?? 0) * count($o['periodes']) / count($PER); }
        foreach ($prop as $o) { $uProp += $o['qte'] * count($o['periodes']) / count($PER); }
        $emps[] = ['cle' => $cle, 'section' => $e['section'], 'niveau' => $e['niveau'], 'zone' => $e['zone'], 'famille' => $famille[$cle],
            'actuel' => $act, 'propose' => $prop, 'statut' => $statut];
    }

    // Ce qui entre, ce qui sort, ce qui reste dehors.
    $dansPlan = [];
    foreach ($E as $e) { foreach ($e['occupants'] ?? [] as $o) { $dansPlan[(string) ($pidDe[(string) $o['ref']] ?? $o['ref'])] = (string) ($o['nom'] ?? $o['ref']); } }
    $jour = static fn ($pid) => round(array_sum($moy[(string) $pid] ?? []), 1);
    $entrants = []; $sortants = [];
    foreach ($places as $pid => $_) { if (!isset($dansPlan[$pid])) { $entrants[] = ['ref' => $refDe[$pid], 'nom' => trim((string) $cat[$pid]['nom']), 'groupe' => $cat[$pid]['groupe'] ?? null, 'vendus' => $jour($pid)]; } }
    foreach ($dansPlan as $pid => $nom) { if (!isset($places[$pid])) { $sortants[] = ['ref' => $refDe[$pid] ?? $pid, 'nom' => trim($nom), 'groupe' => $cat[$pid]['groupe'] ?? null, 'vendus' => $jour($pid)]; } }
    usort($entrants, static fn ($a, $b) => $b['vendus'] <=> $a['vendus']);
    usort($sortants, static fn ($a, $b) => $b['vendus'] <=> $a['vendus']);
    $absents = [];
    foreach ($cat as $pid => $p) {
        if (isset($places[$pid]) || ppHorsComptoir($p) || $jour($pid) < 1) { continue; }
        $fam = (string) ($p['groupe'] ?? '');
        $absents[] = ['ref' => $refDe[$pid], 'nom' => trim((string) $p['nom']), 'groupe' => $fam ?: null, 'vendus' => $jour($pid),
            'raison' => isset($slotsDe[$fam]) ? 'famille complète (' . count($slotsDe[$fam]) . ' emplacements)' : 'aucun emplacement de sa famille au comptoir'];
    }
    usort($absents, static fn ($a, $b) => $b['vendus'] <=> $a['vendus']);
    $oblig = []; $obPlaces = 0;
    foreach ($cat as $pid => $p) {
        if (!$exige($p) || ppHorsComptoir($p)) { continue; }
        if (isset($places[$pid])) { $obPlaces++; continue; }
        $fam = (string) ($p['groupe'] ?? '');
        $oblig[] = ['ref' => $refDe[$pid], 'nom' => trim((string) $p['nom']),
            'vendus' => $jour($pid),
            'raison' => isset($slotsDe[$fam]) ? 'moins vendue que les ' . count($slotsDe[$fam]) . ' produits retenus de sa famille' : 'aucun emplacement de sa famille (' . ($fam ?: 'sans groupe') . ')'];
    }

    return ['du' => $V['du'], 'au' => $V['au'], 'jours' => $jours, 'rotation' => $rot, 'rotations' => PP_ROTATIONS, 'fenetres' => PP_JOURS,
        'joursLus' => $V['lus'], 'magasins' => $V['magasins'], 'joursSansB2b' => $V['sansB2b'], 'joursNonLus' => $V['nonLus'],
        'periodes' => PS_PERIODES, 'emplacements' => $emps,
        'resume' => ['emplacements' => count($emps), 'changes' => $nChange, 'quantites' => $nQte, 'identiques' => $nIdem,
            'unitesActuel' => (int) round($uAct), 'unitesPropose' => (int) round($uProp), 'faibles' => $nFaibles],
        'entrants' => $entrants, 'sortants' => $sortants, 'absents' => array_slice($absents, 0, 20),
        'obligatoires' => ['exigees' => $obPlaces + count($oblig), 'placees' => $obPlaces, 'dehors' => $oblig],
        'familles' => array_map(static fn ($l) => count($l), $slotsDe),
        'regle' => 'Chaque emplacement garde sa famille ; dans chaque famille, les meilleures ventes du moment prennent les emplacements, un produit déjà en place garde le sien. Quantité = ventes moyennes du moment (par magasin et par jour) ÷ '
            . rtrim(rtrim(number_format($rot, 1, ',', ''), '0'), ',') . ', entre ' . PP_MINIMUM['e3'] . ' / ' . PP_MINIMUM['e2'] . ' / ' . PP_MINIMUM['e1a'] . ' et ' . PP_PLAFOND['e3'] . ' / ' . PP_PLAFOND['e2'] . ' / ' . PP_PLAFOND['e1a'] . ' (étage 3 / 2 / 1).',
        'source' => 'relevés horaires du panel, ventes au comptoir (clients pro retirés), moyenne par magasin et par jour ouvert lu'];
}

/** Les paramètres d'une requête : fenêtre et rotation visée, bornées aux valeurs proposées. */
function ppParams(array $src): array
{
    $j = (int) ($src['jours'] ?? 28);
    $r = (float) str_replace(',', '.', (string) ($src['rotation'] ?? 1.5));
    return [in_array($j, PP_JOURS, true) ? $j : 28, in_array($r, PP_ROTATIONS, true) ? $r : 1.5];
}

/** GET /planogramme/standard/proposition?jours=28&rotation=1.5 */
function ep_plano_std_proposition(): array
{
    @set_time_limit(90);
    [$j, $r] = ppParams($_GET);
    return ppProposition($j, $r);
}

/**
 * POST /planogramme/standard/proposition — {jours, rotation, cles: ["3|e1a", …]}
 * Recalcule la proposition (mêmes paramètres) et l'applique aux emplacements donnés,
 * avec l'histoire du plan : ce qui change aujourd'hui ferme la version d'hier.
 */
function wr_plano_std_proposition(): array
{
    @set_time_limit(120);
    $b = body();
    [$j, $r] = ppParams($b);
    $cles = array_values(array_unique(array_map('strval', is_array($b['cles'] ?? null) ? $b['cles'] : [])));
    if (!$cles) { http_response_code(422); return ['error' => 'aucun emplacement choisi']; }
    $P = ppProposition($j, $r);
    if (isset($P['erreur'])) { http_response_code(409); return ['error' => $P['erreur']]; }
    $parCle = []; foreach ($P['emplacements'] as $e) { $parCle[$e['cle']] = $e; }
    $tous = array_column(PS_PERIODES, 'k');
    $n = 0;
    foreach ($cles as $cle) {
        $e = $parCle[$cle] ?? null;
        if ($e === null || $e['statut'] === 'identique') { continue; }
        $s = (int) $e['section']; $niv = (string) $e['niveau'];
        $couverts = [];
        foreach ($e['propose'] as $o) {
            psPoser($s, $niv, $o['periodes'], ['ref' => $o['ref'], 'nom' => $o['nom'], 'groupe' => $o['groupe'], 'qte' => $o['qte']]);
            $couverts = array_merge($couverts, $o['periodes']);
        }
        $vides = array_values(array_diff($tous, $couverts));
        if ($vides) { psPoser($s, $niv, $vides, ['ref' => null]); }
        $n++;
    }
    journalAdd('CEO', 'Planogramme', 'Proposition d’après les ventes',
        $n . ' emplacement(s) posé(s) — ventes moyennes sur ' . $j . ' j, rotation visée ' . str_replace('.', ',', (string) $r) . ' par moment');
    return psEtat() + ['appliques' => $n];
}
