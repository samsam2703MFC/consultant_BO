<?php
/**
 * Cockpit CEO — seed de démonstration (réseau belge, génération déterministe).
 *
 * Port fidèle de la génération data.js du prototype Claude Design :
 * même graine (20260731), même PRNG (mulberry32), mêmes chiffres.
 *
 * Usage :
 *   php bin/seed.php            → exécute les INSERT dans la base configurée (config/config.php)
 *   php bin/seed.php --sql      → écrit sql/seed.sql (aucune connexion requise)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// PRNG mulberry32 — parité binaire avec la version JavaScript du prototype
// ---------------------------------------------------------------------------
final class Mulberry32
{
    private int $a;

    public function __construct(int $seed) { $this->a = $seed & 0xFFFFFFFF; }

    /** Math.imul : multiplication 32 bits (on ne garde que les 32 bits bas). */
    private static function imul(int $x, int $y): int
    {
        $x &= 0xFFFFFFFF; $y &= 0xFFFFFFFF;
        $xl = $x & 0xFFFF; $xh = ($x >> 16) & 0xFFFF;
        return ($xl * $y + ((($xh * $y) & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }

    public function next(): float
    {
        $this->a = ($this->a + 0x6D2B79F5) & 0xFFFFFFFF;
        $a = $this->a;
        $t = self::imul($a ^ ($a >> 15), (1 | $a) & 0xFFFFFFFF);
        $t = (($t + self::imul($t ^ ($t >> 7), (61 | $t) & 0xFFFFFFFF)) & 0xFFFFFFFF) ^ $t;
        return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
    }
}

// ---------------------------------------------------------------------------
// Jeu de données (identique au prototype)
// ---------------------------------------------------------------------------
const TODAY = '2026-07-31';
const SAISON = [0.92, 0.88, 0.98, 1.02, 1.05, 1.08, 1.12, 0.85, 1.0, 1.04, 1.10, 1.35];

/** Leviers — ids of_tag du Manuel Opératoire, couleurs = source de vérité. */
const LEVIERS = [
    ['slug' => 'trafic',        'nom' => 'Trafic',            'type' => 'Vente', 'tag' => 4, 'color' => '#6366f1', 'desc' => 'Faire venir plus de monde : visibilité locale, vitrine, animations, signalétique.'],
    ['slug' => 'recurrence',    'nom' => 'Récurrence',        'type' => 'Vente', 'tag' => 3, 'color' => '#ec4899', 'desc' => 'Faire revenir les clients : fidélisation PWA, qualité constante, loyalty, suivi B2B.'],
    ['slug' => 'xp',            'nom' => 'Expérience Client', 'type' => 'Vente', 'tag' => 2, 'color' => '#f59e0b', 'desc' => 'Qualité du moment en boutique : accueil < 3 s, conseil, ambiance, rapidité.'],
    ['slug' => 'food-cost',     'nom' => 'Food Cost',         'type' => 'Coût',  'tag' => 5, 'color' => '#10b981', 'desc' => 'Coût matière : recettes, contrôle réception ProdAtelier, FIFO, casse & invendus.'],
    ['slug' => 'labour-cost',   'nom' => 'Labour Cost',       'type' => 'Coût',  'tag' => 6, 'color' => '#8b5cf6', 'desc' => 'Coût main d\'œuvre : plannings au flux, productivité, ratio CA/ETP, polyvalence.'],
    ['slug' => 'overhead-cost', 'nom' => 'Overhead Cost',     'type' => 'Coût',  'tag' => 7, 'color' => '#8b5cf6', 'desc' => 'Charges fixes : loyer, énergies, abonnements, assurances, maintenance.'],
];

const KPI_LIST = ['CA réseau', 'Trafic', 'Panier moyen', 'Récurrence client', 'Food cost %', 'Labour cost %', 'Overhead cost %', 'Marge nette franchisé %', 'Points de vente', 'Expérience client (mystère)'];

function stores(): array
{
    return [
        ['id' => 'cha', 'code' => 'BXL-CHA', 'nom' => 'Bruxelles — Châtelain',            'fr' => 'M. Lambert',   'zone' => 'Bruxelles',      'status' => 'Ouvert',       'opened' => '2019-03', 'caBase' => 82000, 'g' => 1.09, 'tg' => 1.12, 'panier' => 11.8, 'm25' => 0.150, 'm26' => 0.158, 'valT' => 920000, 'r0' => 0.90, 'r1' => 0.97, 'food' => 29,   'labour' => 30.5, 'overhead' => 11],
        ['id' => 'fla', 'code' => 'BXL-FLA', 'nom' => 'Ixelles — Flagey',                  'fr' => 'S. Peeters',   'zone' => 'Bruxelles',      'status' => 'Ouvert',       'opened' => '2020-09', 'caBase' => 74000, 'g' => 1.05, 'tg' => 1.09, 'panier' => 11.2, 'm25' => 0.141, 'm26' => 0.146, 'valT' => 840000, 'r0' => 0.85, 'r1' => 0.90, 'food' => 30,   'labour' => 31.5, 'overhead' => 11.5],
        ['id' => 'ucc', 'code' => 'BXL-UCC', 'nom' => 'Uccle — Bascule',                   'fr' => 'S. Peeters',   'zone' => 'Bruxelles',      'status' => 'Ouvert',       'opened' => '2022-04', 'caBase' => 61000, 'g' => 1.07, 'tg' => 1.10, 'panier' => 12.4, 'm25' => 0.146, 'm26' => 0.151, 'valT' => 700000, 'r0' => 0.82, 'r1' => 0.88, 'food' => 29.5, 'labour' => 32,   'overhead' => 12],
        ['id' => 'wat', 'code' => 'WAT-01',  'nom' => 'Waterloo — Centre',                 'fr' => 'C. Dubois',    'zone' => 'Brabant wallon', 'status' => 'Ouvert',       'opened' => '2021-06', 'caBase' => 58000, 'g' => 1.03, 'tg' => 1.08, 'panier' => 10.9, 'm25' => 0.135, 'm26' => 0.139, 'valT' => 640000, 'r0' => 0.78, 'r1' => 0.82, 'food' => 31,   'labour' => 32.5, 'overhead' => 12.5],
        ['id' => 'lln', 'code' => 'LLN-01',  'nom' => 'Louvain-la-Neuve — Grand-Place',    'fr' => 'C. Dubois',    'zone' => 'Brabant wallon', 'status' => 'Ouvert',       'opened' => '2023-02', 'caBase' => 52000, 'g' => 1.11, 'tg' => 1.13, 'panier' => 9.8,  'm25' => 0.137, 'm26' => 0.142, 'valT' => 560000, 'r0' => 0.80, 'r1' => 0.92, 'food' => 30.5, 'labour' => 31,   'overhead' => 12],
        ['id' => 'nam', 'code' => 'NAM-01',  'nom' => 'Namur — Marché',                    'fr' => 'A. Rousseau',  'zone' => 'Namur',          'status' => 'Ouvert',       'opened' => '2022-10', 'caBase' => 49000, 'g' => 1.04, 'tg' => 1.08, 'panier' => 10.4, 'm25' => 0.132, 'm26' => 0.136, 'valT' => 520000, 'r0' => 0.76, 'r1' => 0.80, 'food' => 31.5, 'labour' => 33,   'overhead' => 13],
        ['id' => 'lie', 'code' => 'LIE-01',  'nom' => 'Liège — Le Carré',                  'fr' => 'V. Martin',    'zone' => 'Liège',          'status' => 'Ouvert',       'opened' => '2021-11', 'caBase' => 56000, 'g' => 0.97, 'tg' => 1.05, 'panier' => 10.1, 'm25' => 0.132, 'm26' => 0.124, 'mdrift' => -0.0015, 'fdrift' => 0.35, 'valT' => 610000, 'r0' => 0.84, 'r1' => 0.76, 'food' => 33.5, 'labour' => 34, 'overhead' => 13.5, 'risk' => true],
        ['id' => 'gnd', 'code' => 'GND-01',  'nom' => 'Gand — Korenmarkt',                 'fr' => 'J. De Smet',   'zone' => 'Flandre',        'status' => 'Ouvert',       'opened' => '2023-05', 'caBase' => 67000, 'g' => 1.16, 'tg' => 1.14, 'panier' => 12.1, 'm25' => 0.155, 'm26' => 0.162, 'valT' => 760000, 'r0' => 0.88, 'r1' => 1.02, 'food' => 28.5, 'labour' => 29.5, 'overhead' => 11],
        ['id' => 'anv', 'code' => 'ANV-01',  'nom' => 'Anvers — Meir',                     'fr' => 'J. De Smet',   'zone' => 'Flandre',        'status' => 'Ouvert',       'opened' => '2024-03', 'caBase' => 71000, 'g' => 1.13, 'tg' => 1.12, 'panier' => 12.6, 'm25' => 0.143, 'm26' => 0.149, 'valT' => 800000, 'r0' => 0.75, 'r1' => 0.94, 'food' => 29.5, 'labour' => 30.5, 'overhead' => 11.5],
        ['id' => 'kno', 'code' => 'KNO-01',  'nom' => 'Knokke — Lippenslaan',              'fr' => 'L. Vermeulen', 'zone' => 'Littoral',       'status' => 'En ouverture', 'opened' => '2026-10', 'caBase' => 78000, 'g' => 1,    'tg' => 1,    'panier' => 13.2, 'm25' => 0.15,  'm26' => 0.15,  'valT' => 860000, 'r0' => 0,    'r1' => 0,    'food' => 29,   'labour' => 30,   'overhead' => 12],
    ];
}

/** Performance mensuelle 2025–2026 — même séquence PRNG que le prototype. */
function buildPerf(array &$stores): void
{
    $rnd = new Mulberry32(20260731);
    foreach ($stores as &$s) {
        if ($s['status'] !== 'Ouvert') { continue; }
        $s['perf'] = [];
        foreach ([2025, 2026] as $y) {
            $arr = [];
            for ($m = 0; $m < 12; $m++) {
                $idx = ($y - 2025) * 12 + $m;
                $caT = $y === 2026 ? round($s['caBase'] * SAISON[$m] * $s['tg'] / 100) * 100 : null;
                $actual = !($y === 2026 && $m > 6);
                if (!$actual) { $arr[] = ['ca' => null, 'caT' => $caT, 'marge' => null, 'mp' => null, 'tickets' => null, 'panier' => null, 'food' => null, 'labour' => null, 'overhead' => null, 'val' => null]; continue; }
                $noise = 1 + ($rnd->next() - 0.5) * 0.07;
                $ca = round($s['caBase'] * SAISON[$m] * ($y === 2026 ? $s['g'] : 1) * $noise / 100) * 100;
                $mp = $y === 2026 ? $s['m26'] : $s['m25'];
                if (isset($s['mdrift']) && $y === 2026) { $mp += $s['mdrift'] * $m; }
                $marge = round($ca * $mp / 10) * 10;
                $panier = round($s['panier'] * ($y === 2026 ? 1 : 0.962) * (1 + ($rnd->next() - 0.5) * 0.03), 2);
                $tickets = (int) round($ca / $panier);
                $food = round($s['food'] + ($y === 2025 ? -0.4 : 0) + ((isset($s['fdrift']) && $y === 2026) ? $s['fdrift'] * $m : 0) + ($rnd->next() - 0.5) * 0.8, 1);
                $labour = round($s['labour'] + ($y === 2025 ? -0.3 : 0) + ($rnd->next() - 0.5) * 0.9, 1);
                $overhead = round($s['overhead'] + ($rnd->next() - 0.5) * 0.4, 1);
                $val = round($s['valT'] * ($s['r0'] + ($s['r1'] - $s['r0']) * $idx / 18) / 1000) * 1000;
                $arr[] = compact('ca', 'caT', 'marge', 'mp', 'tickets', 'panier', 'food', 'labour', 'overhead', 'val');
            }
            $s['perf'][$y] = $arr;
        }
    }
}

function consultants(): array
{
    return [
        ['id' => 'mj', 'nom' => 'Marc Janssens',  'role' => 'Organisation & RH',      'tjm' => 850, 'charge' => 78, 'email' => 'marc.janssens@conseil.be', 'visites' => [
            ['2026-07-24', 'Liège — Carré', 'Audit planning équipe — dérive labour cost'], ['2026-07-17', 'Bruxelles — Châtelain', 'Suivi plannings au flux (pilote)'],
            ['2026-07-10', 'Ixelles — Flagey', 'Relevé contrats énergie (état des lieux)'], ['2026-06-26', 'Namur — Centre', 'Audit planning + entretien franchisé'],
            ['2026-06-18', 'Liège — Carré', 'Audit plannings 9 magasins — restitution']]],
        ['id' => 'ed', 'nom' => 'Élise Dupont',   'role' => 'Marketing & trafic',     'tjm' => 780, 'charge' => 64, 'email' => 'elise.dupont@conseil.be', 'visites' => [
            ['2026-07-22', 'Anvers — Meir', 'Plan trafic rentrée + PLV snacking'], ['2026-07-08', 'Gand — Veldstraat', 'Mesure mystère post-formation'],
            ['2026-06-30', 'Bruxelles — Châtelain', 'Test PLV gamme snacking été']]],
        ['id' => 'tw', 'nom' => 'Thomas Wouters', 'role' => 'Data & IT',              'tjm' => 900, 'charge' => 55, 'email' => 't.wouters@conseil.be', 'visites' => [
            ['2026-07-15', 'Louvain-la-Neuve — Grand-Place', 'Déploiement tableau CA/ETP automatisé'], ['2026-06-09', 'Wavre — Centre', 'Recueil données socio-éco (étude Wallonie picarde)']]],
        ['id' => 'sr', 'nom' => 'Sofia Ricci',    'role' => 'Retail ops & formation', 'tjm' => 820, 'charge' => 71, 'email' => 'sofia.ricci@conseil.be', 'visites' => [
            ['2026-07-29', 'Knokke — Lippenslaan', 'Préparation ouverture — parcours & formation'], ['2026-07-21', 'Liège — Carré', 'Formation accueil < 3 s — rattrapage équipe'],
            ['2026-07-03', 'Namur — Centre', 'Contrôle standard accueil (client mystère)'], ['2026-06-24', 'Anvers — Meir', 'Formation 9 équipes — session Flandre']]],
    ];
}

function suppliers(): array
{
    return [
        ['id' => 'pa', 'nom' => 'ProdAtelier', 'perim' => 'Production & fiches techniques', 'email' => 'contact@prodatelier.be'],
        ['id' => 'sn', 'nom' => 'Studio Nord', 'perim' => 'Design, print & agencement',     'email' => 'hello@studionord.be'],
        ['id' => 'at', 'nom' => 'AtelierTech', 'perim' => 'Développement PWA & IT',         'email' => 'dev@ateliertech.be'],
    ];
}

/** [id, nom, owner_kind, owner_id, due, done, shop] */
function projects(): array
{
    $T = fn ($id, $nom, $ot, $oid, $due, $done = null, $mag = null) => compact('id', 'nom', 'ot', 'oid', 'due', 'done', 'mag');
    return [
        ['id' => 'p1', 'famille' => 'Produits', 'nom' => 'Gamme snacking été', 'statut' => 'En cours', 'prio' => 'Haute', 'debut' => '2026-03-02', 'fin' => '2026-09-15', 'axes' => ['Ventes'], 'leviers' => ['trafic', 'food-cost'], 'budget' => 24000, 'valeurEst' => 120000, 'valeurReal' => null, 'valeurTxt' => 'CA additionnel réseau, année pleine', 'kpis' => ['CA réseau', 'Panier moyen'],
            'jalons' => [['Étude gamme & pricing', '2026-04-10', '2026-04-08'], ['Fiches techniques ProdAtelier', '2026-05-22', '2026-05-30'], ['Test 3 magasins pilotes', '2026-07-10', '2026-07-12'], ['Formation équipes', '2026-08-20', null], ['Lancement réseau', '2026-09-15', null]],
            'taches' => [$T('t11', 'Benchmark pricing snacking', 'c', 'ed', '2026-03-27', '2026-03-25'), $T('t12', 'Recettes & fiches techniques', 's', 'pa', '2026-05-22', '2026-05-30'), $T('t13', 'PLV & affiches magasins pilotes', 's', 'sn', '2026-06-26', '2026-06-24'), $T('t14', 'Bilan test pilotes', 'c', 'sr', '2026-07-17', '2026-07-19'), $T('t15', 'Kit formation équipes', 'c', 'sr', '2026-08-14')],
            'couts' => [['Jours-homme consultants', 12000, 10200], ['Fournisseurs — ProdAtelier', 8000, 7400], ['Print & PLV', 4000, 1800]],
            'crm' => ['gain' => 'Une gamme snacking propriétaire qui différencie l\'enseigne sur le déjeuner nomade', 'apport' => 'Panier moyen et fréquence de visite en journée', 'objectif' => '+8 % de panier moyen sur le créneau 11h-14h en réseau', 'attendu' => 120000, 'realise' => 74000]],
        ['id' => 'p2', 'famille' => 'Services', 'nom' => 'Programme fidélité PWA v2', 'statut' => 'En cours', 'prio' => 'Haute', 'debut' => '2026-05-04', 'fin' => '2026-11-30', 'axes' => ['Ventes'], 'leviers' => ['recurrence'], 'budget' => 38000, 'valeurEst' => 180000, 'valeurReal' => null, 'valeurTxt' => 'Récurrence +8 % sur clients identifiés', 'kpis' => ['Taux de retour client'],
            'jalons' => [['Cadrage & specs', '2026-05-29', '2026-05-28'], ['Développement (3 sprints)', '2026-09-30', null], ['Lancement réseau', '2026-11-30', null]],
            'taches' => [$T('t21', 'Specs fonctionnelles v2', 'c', 'tw', '2026-05-29', '2026-05-28'), $T('t22', 'Sprint 1 — comptes clients', 's', 'at', '2026-06-30', '2026-06-30'), $T('t23', 'Sprint 2 — carte de fidélité', 's', 'at', '2026-07-24'), $T('t24', 'Plan de lancement & CRM', 'c', 'ed', '2026-09-04')],
            'couts' => [['Développement AtelierTech', 28000, 16500], ['Jours-homme consultants', 7000, 4200], ['Licences & outils', 3000, 800]],
            'crm' => ['gain' => 'Une base client identifiée et adressable, propriété du réseau', 'apport' => 'Récurrence des visites et connaissance client', 'objectif' => '25 000 membres actifs et 32 % de visites identifiées', 'attendu' => 95000, 'realise' => 0]],
        ['id' => 'p3', 'famille' => 'Organisation & coûts', 'nom' => 'Refonte planning équipes (CA/ETP)', 'statut' => 'En retard', 'prio' => 'Haute', 'debut' => '2026-05-18', 'fin' => '2026-07-20', 'axes' => ['Marge nette franchisé'], 'leviers' => ['labour-cost'], 'budget' => 15000, 'valeurEst' => 95000, 'valeurReal' => null, 'valeurTxt' => 'Gain labour-cost −1,5 pt réseau', 'kpis' => ['Ratio CA/ETP'],
            'jalons' => [['Audit plannings 9 magasins', '2026-06-12', '2026-06-18'], ['Modèle & outils', '2026-07-20', null], ['Déploiement réseau', '2026-09-30', null]],
            'taches' => [$T('t31', 'Audit plannings 9 magasins', 'c', 'mj', '2026-06-12', '2026-06-18', 'lie'), $T('t32', 'Modèle de planning type', 'c', 'mj', '2026-07-06', null, 'lie'), $T('t33', 'Tableau CA/ETP automatisé', 'c', 'tw', '2026-07-15', '2026-07-14')],
            'couts' => [['Jours-homme consultants', 13000, 15600], ['Licences & outils', 2000, 1200]],
            'crm' => ['gain' => 'Un modèle de planning réplicable à chaque ouverture', 'apport' => 'Marge nette franchisé via le labour cost', 'objectif' => 'Labour cost ramené sous 28 % dans les 9 magasins', 'attendu' => 143000, 'realise' => 41000]],
        ['id' => 'p4', 'famille' => 'Organisation & coûts', 'nom' => 'Renégociation contrats énergie', 'statut' => 'En cours', 'prio' => 'Moyenne', 'debut' => '2026-06-15', 'fin' => '2026-10-31', 'axes' => ['Marge nette franchisé'], 'leviers' => ['overhead-cost'], 'budget' => 6000, 'valeurEst' => 54000, 'valeurReal' => null, 'valeurTxt' => 'Économie annuelle réseau (9 sites)', 'kpis' => ['Overhead cost %'],
            'jalons' => [['État des lieux contrats', '2026-07-10', '2026-07-08'], ['Appel d\'offres (9 sites)', '2026-09-18', null], ['Nouveaux contrats signés', '2026-10-31', null]],
            'taches' => [$T('t41', 'Relevé contrats & échéances', 'c', 'mj', '2026-07-10', '2026-07-08'), $T('t42', 'Appel d\'offres énergie (9 sites)', 'c', 'mj', '2026-09-18')],
            'couts' => [['Jours-homme consultants', 6000, 2500]],
            'crm' => ['gain' => 'Un pouvoir de négociation réseau sur les contrats d\'énergie', 'apport' => 'Baisse des charges fixes magasin', 'objectif' => '-18 % sur la facture énergie annuelle consolidée', 'attendu' => 61000, 'realise' => 0]],
        ['id' => 'p5', 'famille' => 'Développement réseau', 'nom' => 'Ouverture Knokke — Lippenslaan', 'statut' => 'En cours', 'prio' => 'Haute', 'debut' => '2026-04-01', 'fin' => '2026-10-01', 'axes' => ['Développement réseau'], 'leviers' => ['trafic'], 'budget' => 45000, 'valeurEst' => 96000, 'valeurReal' => null, 'valeurTxt' => 'Marge année 1 du point de vente', 'kpis' => ['Points de vente'],
            'jalons' => [['Signature bail', '2026-05-15', '2026-05-12'], ['Travaux & agencement', '2026-08-25', null], ['Recrutement & formation', '2026-09-20', null], ['Ouverture', '2026-10-01', null]],
            'taches' => [$T('t51', 'Plans & permis agencement', 's', 'sn', '2026-06-20', '2026-06-19', 'kno'), $T('t52', 'Travaux & agencement boutique', 's', 'sn', '2026-08-25', null, 'kno'), $T('t53', 'Recrutement responsable & équipe', 'c', 'sr', '2026-09-05'), $T('t54', 'Formation initiale (TFB)', 'c', 'sr', '2026-09-20')],
            'couts' => [['Agencement Studio Nord', 32000, 24500], ['Jours-homme consultants', 9000, 5300], ['Autres (juridique, permis)', 4000, 1200]],
            'crm' => ['gain' => 'Une implantation littorale à fort trafic saisonnier', 'apport' => 'Chiffre d\'affaires réseau et notoriété', 'objectif' => 'Ouverture au 01/10 avec CA mensuel > 62 k€ dès M+3', 'attendu' => 210000, 'realise' => 0]],
        ['id' => 'p6', 'famille' => 'Produits', 'nom' => 'Contrôle réception ProdAtelier', 'statut' => 'À lancer', 'prio' => 'Moyenne', 'debut' => '2026-09-15', 'fin' => '2026-12-15', 'axes' => ['Marge nette franchisé'], 'leviers' => ['food-cost'], 'budget' => 9000, 'valeurEst' => 70000, 'valeurReal' => null, 'valeurTxt' => 'Réduction casse & écarts réception', 'kpis' => ['Food cost %'],
            'jalons' => [['Cadrage process réception', '2026-10-09', null], ['Pilote 2 magasins', '2026-11-13', null], ['Déploiement réseau', '2026-12-15', null]],
            'taches' => [$T('t61', 'Cadrage process réception', 'c', 'sr', '2026-10-09', null, 'cha')],
            'couts' => [['Jours-homme consultants', 9000, 0]],
            'crm' => ['gain' => 'Un process de réception homogène et opposable au fournisseur', 'apport' => 'Food cost et qualité produit', 'objectif' => 'Écarts de réception sous 1,5 % de la valeur livrée', 'attendu' => 48000, 'realise' => 0]],
        ['id' => 'p7', 'famille' => 'Services', 'nom' => 'Parcours accueil < 3 secondes', 'statut' => 'Terminé', 'prio' => 'Moyenne', 'debut' => '2026-02-02', 'fin' => '2026-05-30', 'axes' => ['Ventes'], 'leviers' => ['xp'], 'budget' => 11000, 'valeurEst' => 62000, 'valeurReal' => 62000, 'valeurTxt' => 'Panier moyen +2,1 % constaté post-formation', 'kpis' => ['NPS réseau'],
            'jalons' => [['Standard accueil & script', '2026-04-17', '2026-04-16'], ['Formation 9 équipes', '2026-05-15', '2026-05-13'], ['Mesure mystère', '2026-05-29', '2026-05-29']],
            'taches' => [$T('t71', 'Standard accueil & script', 'c', 'sr', '2026-04-17', '2026-04-16'), $T('t72', 'Formation 9 équipes', 'c', 'sr', '2026-05-15', '2026-05-13'), $T('t73', 'Mesure mystère post-formation', 'c', 'ed', '2026-05-29', '2026-05-29')],
            'couts' => [['Jours-homme consultants', 10000, 9600], ['Client mystère', 1000, 800]],
            'crm' => ['gain' => 'Un standard d\'accueil mesurable, transposable aux nouvelles équipes', 'apport' => 'Expérience client et taux de transformation', 'objectif' => 'Accueil sous 3 secondes dans 90 % des visites mystère', 'attendu' => 62000, 'realise' => 58000]],
        ['id' => 'p8', 'famille' => 'Développement réseau', 'nom' => 'Étude implantation Wallonie picarde', 'statut' => 'En pause', 'prio' => 'Basse', 'debut' => '2026-05-01', 'fin' => '2027-02-28', 'axes' => ['Développement réseau'], 'leviers' => ['trafic'], 'budget' => 7000, 'valeurEst' => 0, 'valeurReal' => null, 'valeurTxt' => 'Étude — valeur portée par les ouvertures futures', 'kpis' => ['Points de vente'],
            'jalons' => [['Recueil données socio-éco', '2026-06-05', '2026-06-09'], ['Analyse zones', '2027-01-15', null], ['Recommandation', '2027-02-28', null]],
            'taches' => [$T('t81', 'Recueil données socio-éco', 'c', 'tw', '2026-06-05', '2026-06-09')],
            'couts' => [['Jours-homme consultants', 6000, 1200], ['Données & études', 1000, 0]],
            'crm' => ['gain' => 'Une lecture objective du potentiel Wallonie picarde', 'apport' => 'Qualité des décisions d\'implantation', 'objectif' => 'Recommandation argumentée sur 3 zones prioritaires', 'attendu' => 0, 'realise' => 0]],
    ];
}

function people(): array
{
    return [
        ['id' => 'ceo', 'nom' => 'Laurent Mertens',  'role' => 'CEO',                     'email' => 'laurent.mertens@latelierby.be'],
        ['id' => 'dir', 'nom' => 'Sophie Claes',     'role' => 'Directrice réseau',       'email' => 'sophie.claes@latelierby.be'],
        ['id' => 'cfo', 'nom' => 'Bart Peeters',     'role' => 'Comptabilité & finance',  'email' => 'bart.peeters@latelierby.be'],
        ['id' => 'exp', 'nom' => 'Julie Lambert',    'role' => 'Responsable expansion',   'email' => 'julie.lambert@latelierby.be'],
        ['id' => 'ass', 'nom' => 'Nadia El Amrani',  'role' => 'Assistante de direction', 'email' => 'nadia.elamrani@latelierby.be'],
    ];
}

function reports(): array
{
    return [
        ['id' => 'r1', 'type' => 'Financier',            'postes' => ['p1', 'p3', 'p4', 'p5'], 'nom' => 'Rapport de performance réseau',      'freq' => 'Mensuel',      'dernier' => '2026-07-02', 'dest' => 'ceo', 'cc' => 'dir', 'actif' => 1, 'desc' => 'CA par magasin, valeur consolidée, cible vs réel, snapshot heatmap'],
        ['id' => 'r2', 'type' => 'Financier',            'postes' => ['p1', 'p5'],             'nom' => 'Trajectoire objectifs 1/3/5 ans',     'freq' => 'Trimestriel',  'dernier' => '2026-07-05', 'dest' => 'ceo', 'cc' => 'dir', 'actif' => 1, 'desc' => 'Position vs cibles de CA, projection fin d\'année'],
        ['id' => 'r3', 'type' => 'Pilotage projets',     'postes' => ['p8'],                   'nom' => 'Suivi des projets',                   'freq' => 'Hebdomadaire', 'dernier' => '2026-07-27', 'dest' => 'ceo', 'cc' => 'ass', 'actif' => 1, 'desc' => 'Avancement, jalons, retards, échéances de la semaine'],
        ['id' => 'r4', 'type' => 'Contrôle qualité',     'postes' => ['p9', 'p10'],            'nom' => 'Contrôle consultants & fournisseurs', 'freq' => 'Hebdomadaire', 'dernier' => '2026-07-27', 'dest' => 'ceo', 'cc' => '',    'actif' => 1, 'desc' => 'Livraisons à temps / en retard, responsables en cause, fiabilité'],
        ['id' => 'r5', 'type' => 'Financier',            'postes' => ['p11'],                  'nom' => 'ROI & coûts',                         'freq' => 'Mensuel',      'dernier' => '2026-07-02', 'dest' => 'ceo', 'cc' => 'cfo', 'actif' => 1, 'desc' => 'ROI par projet, réel vs budget, dépassements'],
        ['id' => 'r6', 'type' => 'Commercial',           'postes' => ['p1', 'p7'],             'nom' => 'KPI clés réseau',                     'freq' => 'Mensuel',      'dernier' => '2026-07-02', 'dest' => 'ceo', 'cc' => 'dir', 'actif' => 1, 'desc' => 'Ventes, marge nette, points de vente, variation vs période précédente'],
        ['id' => 'r7', 'type' => 'Développement réseau', 'postes' => ['p2'],                   'nom' => 'Expansion & pipeline',                'freq' => 'Mensuel',      'dernier' => '2026-07-02', 'dest' => 'ceo', 'cc' => 'exp', 'actif' => 1, 'desc' => 'État du pipeline, ouvertures vs objectif, zones blanches prioritaires'],
        ['id' => 'r8', 'type' => 'Financier',            'postes' => ['p3', 'p6'],             'nom' => 'Marge & dérives de coûts',            'freq' => 'Mensuel',      'dernier' => '2026-07-02', 'dest' => 'ceo', 'cc' => 'cfo', 'actif' => 1, 'desc' => 'Marge nette, dérives food/labour/overhead, magasins à traiter'],
        ['id' => 'r9', 'type' => 'Commercial',           'postes' => ['p3', 'p4'],             'nom' => 'Top & flop magasins',                 'freq' => 'Mensuel',      'dernier' => '2026-07-02', 'dest' => 'ceo', 'cc' => '',    'actif' => 0, 'desc' => 'Classements automatiques, magasins à risque récurrent'],
    ];
}

function alertRules(): array
{
    return [
        ['a1', 'Projet en retard', 'Push + email', 1],
        ['a2', 'Dépassement de budget projet', 'Push + email', 1],
        ['a3', 'Objectif de CA à risque', 'Email', 1],
        ['a4', 'Magasin en sous-performance 3 mois consécutifs', 'Push + email', 1],
        ['a5', 'Relance restée sans réponse (72 h)', 'Push', 0],
    ];
}

function journal(): array
{
    return [
        ['2026-07-31 08:15', 'Système', 'Alerte', '—', 'Magasin LIE-01 en sous-performance pour le 3e mois consécutif — food-cost 36,1 %'],
        ['2026-07-30 17:40', 'CEO', 'Statut', 'Refonte planning équipes', 'Statut passé de « En cours » à « En retard » (jalon Modèle & outils dépassé)'],
        ['2026-07-30 09:12', 'Système', 'Relance', 'Programme fidélité PWA v2', 'Relance J+1 envoyée à AtelierTech — tâche « Sprint 2 — carte de fidélité »'],
        ['2026-07-29 16:05', 'CEO', 'Coût', 'Ouverture Knokke', 'Coût ajouté : Agencement Studio Nord — acompte 2, 12 400 €'],
        ['2026-07-28 11:30', 'Système', 'Rapport', '—', 'Rapport « Suivi des projets » (hebdo) généré et envoyé au CEO (PDF)'],
        ['2026-07-27 14:22', 'CEO', 'Pipeline', '—', 'Candidat D. Carlier déplacé de « Business plan » à « Validé » (zone Tournai réservée)'],
        ['2026-07-24 10:02', 'Système', 'Import', '—', 'Import CSV — performances mensuelles de juin validées pour 9 magasins'],
        ['2026-07-22 09:45', 'M. Janssens', 'Tâche', 'Renégociation contrats énergie', 'Tâche « Relevé contrats & échéances » livrée (2 j d\'avance)'],
        ['2026-07-19 15:10', 'S. Ricci', 'Tâche', 'Gamme snacking été', 'Tâche « Bilan test pilotes » livrée (+2 j vs échéance)'],
        ['2026-07-15 09:00', 'CEO', 'Pipeline', '—', 'L. Vermeulen (Signé) converti en point de vente KNO-01 — rétroplanning d\'ouverture créé'],
        ['2026-07-12 17:55', 'Système', 'Alerte', 'Refonte planning équipes', 'Budget dépassé : 16 800 € réel vs 15 000 € prévu (+12 %)'],
        ['2026-07-10 08:30', 'Système', 'Rapport', '—', 'Rapport « Trajectoire objectifs 1/3/5 ans » (T2) envoyé au CEO et au Comité'],
        ['2026-07-05 11:18', 'CEO', 'Objectif', '—', 'Cible de CA réseau 2026 confirmée à 7,4 M€ après revue S1'],
        ['2026-06-30 16:44', 'AtelierTech', 'Tâche', 'Programme fidélité PWA v2', 'Tâche « Sprint 1 — comptes clients » livrée à temps'],
        ['2026-06-18 10:25', 'M. Janssens', 'Tâche', 'Refonte planning équipes', 'Tâche « Audit plannings 9 magasins » livrée (+6 j vs échéance)'],
        ['2026-06-12 09:05', 'CEO', 'Création', 'Contrôle réception ProdAtelier', 'Projet créé — levier Food Cost, lancement prévu 15/09'],
        ['2026-05-30 14:12', 'ProdAtelier', 'Tâche', 'Gamme snacking été', 'Tâche « Recettes & fiches techniques » livrée (+8 j vs échéance)'],
        ['2026-05-12 12:00', 'CEO', 'Jalon', 'Ouverture Knokke', 'Jalon « Signature bail » atteint avec 3 j d\'avance'],
    ];
}

function projTemplates(): array
{
    return [
        'Ventes' => ['jalons' => [['Étude & cadrage', -90], ['Test magasins pilotes', -45], ['Déploiement réseau', 0]], 'couts' => [['Jours-homme consultants', 10000], ['Print & PLV', 3000]]],
        'Marge nette franchisé' => ['jalons' => [['État des lieux', -90], ['Plan d\'action validé', -45], ['Mise en œuvre réseau', 0]], 'couts' => [['Jours-homme consultants', 8000], ['Licences & outils', 2000]]],
        'Développement réseau' => ['jalons' => [['Signature bail', -120], ['Travaux & agencement', -40], ['Recrutement & formation', -10], ['Ouverture', 0]], 'couts' => [['Agencement', 30000], ['Jours-homme consultants', 9000], ['Autres (juridique, permis)', 4000]]],
        'Produit — Interne (production)' => ['jalons' => [['Recette & fiche technique', -100], ['Essais production', -60], ['Test magasins pilotes', -30], ['Lancement réseau', 0]], 'couts' => [['Jours-homme consultants', 6000], ['Essais production & matières', 4000], ['Print & PLV', 2500]]],
        'Produit — Externe (achat)' => ['jalons' => [['Sourcing fournisseurs', -100], ['Appel d\'offres & dégustation', -60], ['Contrat & référencement', -30], ['Lancement réseau', 0]], 'couts' => [['Jours-homme consultants', 5000], ['Échantillons & tests', 1500], ['Print & PLV', 2500]]],
    ];
}

function emailTemplates(): array
{
    return [
        ['e1', 'Rappel J-3 avant échéance', '[L\'Atelier by] Échéance dans 3 jours — {tache}', "Bonjour {destinataire},\n\nPetit rappel : la tâche « {tache} » (projet {projet}) arrive à échéance le {echeance}.\nMerci de confirmer que la livraison est en bonne voie, ou de signaler tout blocage dès maintenant.\n\nBien à vous,\nDirection réseau — L'Atelier by"],
        ['e2', 'Relance J+1 en retard', '[L\'Atelier by] Tâche en retard — {tache}', "Bonjour {destinataire},\n\nLa tâche « {tache} » (projet {projet}) était attendue le {echeance} et n'est pas livrée.\nMerci de nous transmettre sous 48 h : la nouvelle date ferme, la cause du retard et le plan de rattrapage.\n\nBien à vous,\nDirection réseau — L'Atelier by"],
        ['e3', 'Candidat sans activité', '[L\'Atelier by] Votre projet de franchise', "Bonjour {destinataire},\n\nNous restons sans nouvelle depuis quelques semaines concernant votre projet d'ouverture ({zone}).\nSouhaitez-vous poursuivre ? Nous pouvons planifier un point téléphonique cette semaine.\n\nBien à vous,\nDirection réseau — L'Atelier by"],
    ];
}

/** [id, nom, catégorie, volume, prix, coût unitaire, tendance vol, magasins] */
function products(): array
{
    return [
        ['vi1', 'Croissant pur beurre', 'Viennoiserie', 48200, 1.35, 0.47, 1.04, 9], ['vi2', 'Pain au chocolat', 'Viennoiserie', 39500, 1.55, 0.58, 1.06, 9],
        ['vi3', 'Chausson aux pommes', 'Viennoiserie', 12400, 1.95, 0.81, 0.97, 8], ['vi4', 'Croissant aux amandes', 'Viennoiserie', 6100, 2.60, 1.22, 1.12, 6],
        ['vi5', 'Couque suisse', 'Viennoiserie', 4300, 2.20, 1.15, 0.91, 4], ['pa1', 'Baguette tradition', 'Pain', 41800, 1.30, 0.44, 1.02, 9],
        ['pa2', 'Pain au levain 500 g', 'Pain', 18900, 3.40, 1.19, 1.09, 9], ['pa3', 'Pain multicéréales', 'Pain', 9700, 3.60, 1.51, 1.05, 7],
        ['pa4', 'Pain de mie brioché', 'Pain', 5200, 3.20, 1.66, 0.88, 5], ['pt1', 'Flan pâtissier (part)', 'Pâtisserie', 14600, 3.10, 1.02, 1.07, 8],
        ['pt2', 'Éclair café', 'Pâtisserie', 11200, 3.40, 1.29, 0.99, 8], ['pt3', 'Tarte au sucre (part)', 'Pâtisserie', 8900, 3.20, 1.12, 1.03, 6],
        ['pt4', 'Merveilleux', 'Pâtisserie', 7400, 3.90, 1.72, 1.18, 7], ['pt5', 'Cheesecake spéculoos', 'Pâtisserie', 3600, 4.50, 2.34, 0.94, 3],
        ['sn1', 'Sandwich poulet curry', 'Snacking salé', 16800, 6.40, 2.30, 1.11, 9], ['sn2', 'Quiche lorraine (part)', 'Snacking salé', 9300, 4.80, 1.87, 1.04, 7],
        ['sn3', 'Focaccia mozzarella', 'Snacking salé', 6900, 5.60, 2.63, 1.14, 5], ['sn4', 'Wrap végétarien', 'Snacking salé', 3100, 5.90, 3.01, 0.93, 3],
        ['bo1', 'Café filtre', 'Boissons', 34500, 2.40, 0.31, 1.03, 9], ['bo2', 'Cappuccino', 'Boissons', 21700, 3.30, 0.62, 1.08, 9],
        ['bo3', 'Jus pressé orange', 'Boissons', 7800, 3.90, 1.68, 0.96, 6],
    ];
}

// ---------------------------------------------------------------------------
// Génération des INSERT
// ---------------------------------------------------------------------------
function q(?string $s): string { return $s === null ? 'NULL' : "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'"; }
function n(int|float|null $v): string { return $v === null ? 'NULL' : (string) $v; }
function j(mixed $v): string { return q(json_encode($v, JSON_UNESCAPED_UNICODE)); }

function buildStatements(): array
{
    $sql = [];
    $stores = stores();
    buildPerf($stores);
    $seuils = ['food' => 32, 'labour' => 33, 'overhead' => 13.5, 'royalties' => 3, 'financieres' => 2.2];

    // --- of_tag (leviers) — insérés seulement si absents (base autonome)
    foreach (LEVIERS as $l) {
        $sql[] = "INSERT INTO of_tag (id, name, color) VALUES ({$l['tag']}, " . q($l['nom']) . ', ' . q($l['color']) . ') ON DUPLICATE KEY UPDATE color = VALUES(color);';
    }

    // --- kpi : seuils de coûts + liste des KPI réseau
    $kpiSeuils = [['food', 'Food cost', '%', null, $seuils['food']], ['labour', 'Labour cost', '%', null, $seuils['labour']],
        ['overhead', 'Overhead cost', '%', null, $seuils['overhead']], ['royalties', 'Royalties', '%', null, $seuils['royalties']],
        ['financieres', 'Charges financières', '%', null, $seuils['financieres']], ['ca_etp', 'Ratio CA/ETP', '€', 13000, null]];
    foreach ($kpiSeuils as [$code, $name, $unit, $bas, $haut]) {
        $sql[] = 'INSERT INTO kpi (code, name, unit, seuil_bas, seuil_haut) VALUES (' . q($code) . ', ' . q($name) . ', ' . q($unit) . ', ' . n($bas) . ', ' . n($haut) . ');';
    }
    foreach (KPI_LIST as $nom) {
        $sql[] = 'INSERT INTO kpi (code, name) VALUES (NULL, ' . q($nom) . ');';
    }

    // --- position (consultants du Manuel Opératoire)
    foreach (consultants() as $c) {
        $sql[] = "INSERT INTO position (app_type, name) VALUES ('CONSULTANT', " . q($c['nom']) . ');';
    }

    // --- magasins + perf mensuelle + budgets
    // pwa_shop_id : id de la boutique dans le panel consultant (atelierby_db).
    // En production, remplacez ces valeurs de démo par les vrais ids du panel.
    foreach ($stores as $i => $s) {
        $sql[] = 'INSERT INTO ceo_shop (id, code, name, franchisee, zone, status, opened_on, valuation_target, basket_ref, pwa_shop_id) VALUES (' .
            q($s['id']) . ', ' . q($s['code']) . ', ' . q($s['nom']) . ', ' . q($s['fr']) . ', ' . q($s['zone']) . ', ' . q($s['status']) . ', ' .
            q($s['opened'] . '-01') . ', ' . n($s['valT']) . ', ' . n($s['panier']) . ', ' . ($i + 1) . ');';
    }

    foreach ($stores as $s) {
        if (!isset($s['perf'])) { continue; }
        // budget du magasin (comme api.js demoPayload : caTheorique = budget × coef dérivé de l'id)
        $budAn = array_sum(array_map(fn ($r) => $r['caT'] ?? 0, $s['perf'][2026]));
        $seed = array_sum(array_map('ord', str_split($s['id'])));
        $coef = 0.92 + (($seed % 21) / 100);
        $theoAn = round($budAn * $coef / 1000) * 1000;
        $menages = 4200 + ($seed % 17) * 130;
        $sql[] = 'INSERT INTO ceo_shop_budget (shop_id, fiscal_year, validated_on, basket_target, months_total, ca_theorique_an, etude_date, etude_source, etude_potentiel_menages, etude_potentiel_maturite, annee_exploitation, montee_regime, saisonnalite) VALUES (' .
            q($s['id']) . ", 2026, '2026-01-10', " . n(round($s['panier'] * 1.08, 2)) . ', 12, ' . n($theoAn) . ", '2025-09-15', " .
            q('Étude de marché — zone de chalandise 10 min') . ', ' . n($menages) . ', ' . n(round($theoAn / 0.7 / 1000) * 1000) . ', 1, ' .
            j(['a1' => 70, 'a2' => 80, 'a3' => 90]) . ', ' . j(array_map(fn ($r) => round(100 * ($r['caT'] ?? 0) / max(1, $budAn), 1), $s['perf'][2026])) . ');';
        // lignes de charges (mêmes 5 postes que le prototype)
        $lines = [['Matières premières', 5, $seuils['food'], 'food'], ['Rémunérations & charges sociales', 6, $seuils['labour'], 'labour'],
            ['Services & biens divers', 7, $seuils['overhead'], 'overhead'], ['Royalties', null, $seuils['royalties'], null], ['Charges financières', null, $seuils['financieres'], null]];
        foreach ($lines as $i => [$label, $lev, $pct, $field]) {
            $sql[] = 'INSERT INTO ceo_shop_budget_line (shop_id, fiscal_year, label, levid, pct_budget, pct_theorique, real_field, sort_order) VALUES (' .
                q($s['id']) . ', 2026, ' . q($label) . ', ' . n($lev) . ', ' . n($pct) . ', ' . n($pct) . ', ' . q($field) . ", $i);";
        }
        foreach ([2025, 2026] as $y) {
            foreach ($s['perf'][$y] as $mi => $r) {
                $m = $mi + 1;
                if ($y === 2026 && $r['caT'] !== null) {
                    $sql[] = "INSERT INTO ceo_shop_budget_month (shop_id, fiscal_year, month, revenue_budget) VALUES (" . q($s['id']) . ", $y, $m, {$r['caT']});";
                }
                $theoM = ($y === 2026 && $budAn > 0 && $r['caT'] !== null) ? round($theoAn * $r['caT'] / $budAn) : null;
                $sql[] = 'INSERT INTO ceo_shop_month_perf (shop_id, year, month, revenue, revenue_budget, ca_theorique, net_margin, tickets, basket_avg, food_pct, labour_pct, overhead_pct, valuation, encoded_at) VALUES (' .
                    q($s['id']) . ", $y, $m, " . n($r['ca']) . ', ' . n($r['caT']) . ', ' . n($theoM) . ', ' . n($r['marge']) . ', ' . n($r['tickets']) . ', ' .
                    n($r['panier']) . ', ' . n($r['food']) . ', ' . n($r['labour']) . ', ' . n($r['overhead']) . ', ' . n($r['val']) . ', ' .
                    ($r['ca'] !== null ? q(sprintf('%d-%02d-04 09:00:00', $y, min(12, $m + 1))) : 'NULL') . ');';
            }
        }
    }

    // --- objectifs réseau
    $sql[] = "INSERT INTO ceo_network_target VALUES ('h1', 2026, 7400000, 2, 1, NULL);";
    $sql[] = "INSERT INTO ceo_network_target VALUES ('h3', 2028, 11500000, 6, 1, " . q('Hypothèse : {ouvertures} ouvertures d\'ici 2028 (Knokke, Tournai, puis 3 zones à sécuriser), CA moyen de {caMoyen} par nouveau point de vente en année pleine.') . ');';
    $sql[] = "INSERT INTO ceo_network_target VALUES ('h5', 2030, 18000000, 12, 1, " . q('Hypothèse : {ouvertures} ouvertures d\'ici 2030. La cible exige d\'ouvrir en moyenne 2 à 3 points de vente par an — le pipeline candidats doit rester ≥ 8 dossiers actifs.') . ');';

    // --- intervenants
    foreach (consultants() as $i => $c) {
        $sql[] = 'INSERT INTO ceo_consultant (id, position_id, name, role, email, daily_rate, workload) VALUES (' .
            q($c['id']) . ', ' . ($i + 1) . ', ' . q($c['nom']) . ', ' . q($c['role']) . ', ' . q($c['email']) . ', ' . n($c['tjm']) . ', ' . n($c['charge']) . ');';
        foreach ($c['visites'] as [$date, $store, $objet]) {
            $sql[] = 'INSERT INTO ceo_consultant_visit (consultant_id, visited_on, store_label, subject) VALUES (' . q($c['id']) . ', ' . q($date) . ', ' . q($store) . ', ' . q($objet) . ');';
        }
    }
    foreach (suppliers() as $s) {
        $sql[] = 'INSERT INTO ceo_supplier (id, name, perimeter, email) VALUES (' . q($s['id']) . ', ' . q($s['nom']) . ', ' . q($s['perim']) . ', ' . q($s['email']) . ');';
    }

    // --- projets
    $levBySlug = [];
    foreach (LEVIERS as $l) { $levBySlug[$l['slug']] = $l['tag']; }
    foreach (projects() as $p) {
        $sql[] = 'INSERT INTO ceo_project (id, name, famille, status, priority, axe, axes_json, kpis_json, value_txt, starts_on, ends_on, budget, value_est, value_real) VALUES (' .
            q($p['id']) . ', ' . q($p['nom']) . ', ' . q($p['famille']) . ', ' . q($p['statut']) . ', ' . q($p['prio']) . ', ' . q($p['axes'][0]) . ', ' .
            j($p['axes']) . ', ' . j($p['kpis']) . ', ' . q($p['valeurTxt']) . ', ' . q($p['debut']) . ', ' . q($p['fin']) . ', ' .
            n($p['budget']) . ', ' . n($p['valeurEst']) . ', ' . n($p['valeurReal']) . ');';
        foreach ($p['leviers'] as $slug) {
            $sql[] = 'INSERT INTO ceo_project_levid VALUES (' . q($p['id']) . ', ' . $levBySlug[$slug] . ');';
        }
        foreach ($p['jalons'] as $i => [$nom, $cible, $reel]) {
            $sql[] = 'INSERT INTO ceo_project_milestone (project_id, name, target_on, done_on, sort_order) VALUES (' . q($p['id']) . ', ' . q($nom) . ', ' . q($cible) . ', ' . q($reel) . ", $i);";
        }
        foreach ($p['couts'] as [$label, $prevu, $reel]) {
            $sql[] = 'INSERT INTO ceo_project_cost (project_id, label, planned, actual) VALUES (' . q($p['id']) . ', ' . q($label) . ", $prevu, $reel);";
        }
        foreach ($p['taches'] as $t) {
            $sql[] = 'INSERT INTO ceo_project_task (id, project_id, name, owner_kind, owner_id, shop_id, due_on, done_on) VALUES (' .
                q($t['id']) . ', ' . q($p['id']) . ', ' . q($t['nom']) . ', ' . q($t['ot']) . ', ' . q($t['oid']) . ', ' . q($t['mag']) . ', ' . q($t['due']) . ', ' . q($t['done']) . ');';
        }
        $c = $p['crm'];
        $sql[] = 'INSERT INTO ceo_project_crm VALUES (' . q($p['id']) . ', ' . q($c['gain']) . ', ' . q($c['apport']) . ', ' . q($c['objectif']) . ', ' . n($c['attendu']) . ', ' . n($c['realise']) . ');';
    }

    // --- personnel, reporting, alertes, templates, journal
    foreach (people() as $p) {
        $sql[] = 'INSERT INTO ceo_person VALUES (' . q($p['id']) . ', ' . q($p['nom']) . ', ' . q($p['role']) . ', ' . q($p['email']) . ');';
    }
    foreach (reports() as $r) {
        $sql[] = 'INSERT INTO ceo_report_schedule (id, name, type, description, frequency, postes_json, dest_id, cc_id, last_run, active) VALUES (' .
            q($r['id']) . ', ' . q($r['nom']) . ', ' . q($r['type']) . ', ' . q($r['desc']) . ', ' . q($r['freq']) . ', ' . j($r['postes']) . ', ' .
            q($r['dest']) . ', ' . ($r['cc'] === '' ? 'NULL' : q($r['cc'])) . ', ' . q($r['dernier']) . ', ' . $r['actif'] . ');';
    }
    foreach (alertRules() as [$id, $nom, $canal, $actif]) {
        $sql[] = 'INSERT INTO ceo_alert_rule VALUES (' . q($id) . ', ' . q($nom) . ', ' . q($canal) . ", $actif);";
    }
    foreach (emailTemplates() as [$id, $nom, $sujet, $corps]) {
        $sql[] = 'INSERT INTO ceo_email_template VALUES (' . q($id) . ', ' . q($nom) . ', ' . q($sujet) . ', ' . q($corps) . ');';
    }
    foreach (projTemplates() as $axe => $tpl) {
        $sql[] = 'INSERT INTO ceo_project_template VALUES (' . q($axe) . ', ' .
            j(array_map(fn ($x) => ['nom' => $x[0], 'j' => $x[1]], $tpl['jalons'])) . ', ' .
            j(array_map(fn ($x) => ['poste' => $x[0], 'prevu' => $x[1]], $tpl['couts'])) . ');';
    }
    foreach (journal() as [$ts, $qui, $type, $projet, $msg]) {
        $sql[] = 'INSERT INTO ceo_journal_entry (happened_at, actor, kind, project, message) VALUES (' . q($ts . ':00') . ', ' . q($qui) . ', ' . q($type) . ', ' . q($projet) . ', ' . q($msg) . ');';
    }

    // --- produits (période juillet 2026 + N-1 dérivé de la tendance)
    foreach (products() as [$id, $nom, $cat, $vol, $prix, $cout, $tend, $mags]) {
        $sql[] = 'INSERT INTO ceo_product VALUES (' . q($id) . ', ' . q($nom) . ', ' . q($cat) . ', 1);';
        $sql[] = "INSERT INTO ceo_product_month_sales VALUES (" . q($id) . ", 2026, 7, $vol, $mags, $prix, $cout);";
        $volN1 = (int) round($vol / $tend);
        $sql[] = "INSERT INTO ceo_product_month_sales VALUES (" . q($id) . ", 2025, 7, $volN1, $mags, $prix, $cout);";
    }

    // --- liens de partage du panel consultant (démo — table mac_report_share)
    $shares = [
        ['tok_demo_cha_2026-06_aK3xW9pQvR7mZsL0dF2gH4jN8bYcE6tU1', 1, '2026-06', 'Rapport mensuel — Bruxelles Châtelain — juin 2026', 1, 'Marc Janssens', '2026-07-03 09:12:00', '2026-07-17 09:12:00', null, 7, '2026-07-15 08:40:00'],
        ['tok_demo_lie_2026-06_bT5yV2nM8cX4kP0qW7rZ1sD9fG3hJ6lA2', 7, '2026-06', 'Rapport mensuel — Liège Le Carré — juin 2026', 1, 'Marc Janssens', '2026-07-04 14:30:00', '2026-07-18 14:30:00', null, 12, '2026-07-16 19:05:00'],
        ['tok_demo_gnd_2026-07_cU8wQ4rN1mY6zT3vK9xB5dH0jF7gL2pS3', 8, '2026-07', 'Rapport mensuel — Gand Korenmarkt — juillet 2026', 4, 'Sofia Ricci', '2026-08-02 10:05:00', '2026-08-16 10:05:00', null, 3, '2026-08-05 11:22:00'],
        ['tok_demo_nam_2026-05_dV1zX7sP4nW2qM9rT5yK8cB3fJ6hG0lD4', 6, '2026-05', 'Rapport mensuel — Namur Marché — mai 2026', 2, 'Élise Dupont', '2026-06-05 16:45:00', '2026-06-19 16:45:00', '2026-06-12 09:00:00', 1, '2026-06-06 12:10:00'],
    ];
    foreach ($shares as [$tok, $shop, $ym, $label, $cid, $cname, $created, $expires, $revoked, $opens, $lastOpen]) {
        $sql[] = 'INSERT INTO mac_report_share (token, id_shop, ym, label, html, id_consultant, consultant_name, created_at, expires_at, revoked_at, opens, last_opened_at) VALUES (' .
            q($tok) . ", $shop, " . q($ym) . ', ' . q($label) . ', NULL, ' . $cid . ', ' . q($cname) . ', ' . q($created) . ', ' . q($expires) . ', ' . q($revoked) . ", $opens, " . q($lastOpen) . ');';
    }

    // --- paramètres applicatifs (/meta hors référentiels)
    $settings = [
        'reseau'           => ['nom' => "L'Atelier by", 'sousTitre' => 'Cockpit CEO — Réseau'],
        'utilisateur'      => ['initiales' => 'GB', 'nom' => 'G. Baert', 'role' => 'CEO · admin'],
        'aujourdhui'       => TODAY,
        'dateLabel'        => 'Vendredi 31 juillet 2026',
        'periodeLabel'     => 'Données : juillet 2026',
        'exercice'         => 2026,
        'moisLabels'       => ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'],
        'contribOuverture' => 210000,
        'notes'            => ['objectifsOuverture' => "Dont contribution attendue de l'ouverture de Knokke (oct.–déc.) : env. 210 k€."],
        'familles'         => ['Produits', 'Services', 'Organisation & coûts', 'Développement réseau'],
        'reportTypes'      => ['Financier', 'Commercial', 'Contrôle qualité', 'Pilotage projets', 'Développement réseau'],
        'caMoyenOuverture' => 820000,
        'pwaBase'          => 'https://panel.atelierby.be',   // base d'URL du panel consultant (pwa_consultant)
    ];
    foreach ($settings as $k => $v) {
        $sql[] = 'INSERT INTO ceo_app_setting VALUES (' . q($k) . ', ' . j($v) . ');';
    }

    return $sql;
}

// ---------------------------------------------------------------------------
// Exécution
// ---------------------------------------------------------------------------
$statements = buildStatements();

if (in_array('--sql', $argv, true)) {
    $out = __DIR__ . '/../sql/seed.sql';
    file_put_contents($out, "-- Généré par bin/seed.php --sql — ne pas éditer à la main.\nSET NAMES utf8mb4;\n\n" . implode("\n", $statements) . "\n");
    fwrite(STDERR, 'seed.sql écrit (' . count($statements) . " instructions)\n");
    exit(0);
}

require __DIR__ . '/../src/Db.php';
$pdo = Db::pdo();
$pdo->beginTransaction();
foreach ($statements as $stmt) {
    $pdo->exec($stmt);
}
$pdo->commit();
fwrite(STDERR, count($statements) . " instructions exécutées.\n");
