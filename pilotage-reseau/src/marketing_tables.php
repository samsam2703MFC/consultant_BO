<?php
declare(strict_types=1);

/**
 * Les tables du portail Marketing — un seul préfixe, `mar_` (demande du 13/10/2026).
 *
 * Le marketing s'était écrit en quatre dialectes : `ceo_fb_*` pour les posts,
 * `brand_guard_*` pour la charte, `ceo_nl_*` pour la newsletter, `ceo_shop_*` pour
 * les avis, `ceo_campagne_*` et `ceo_mkt_*` pour la mesure et les annexes des
 * campagnes. Les tables du module marketing autonome, elles, portaient déjà `mar_`
 * (mar_campaign, mar_lever…). Tout ce que le portail possède passe sous `mar_`,
 * rangé par famille : `mar_social_*`, `mar_bg_*`, `mar_nl_*`, `mar_avis*`,
 * `mar_campagne_*`. Les noms français distinguent nos tables de celles du module
 * autonome (mar_campaign_* en anglais), qui restent ce qu'elles sont.
 *
 * Le code écrit les noms EN CLAIR dans son SQL (pas de constante interpolée) :
 * une requête se lit d'un coup d'œil, et l'inventaire de l'onglet Paramètres
 * (src/marketing_sources.php) les retrouve dans le texte. Ce registre sert à la
 * migration et à la documentation — il dit ce que chaque table contient.
 *
 * MIGRATION — `marMigrer()`, appelée par `ensureInstalled()` AVANT toute
 * création de table. Pour chaque paire ancien → nouveau :
 *  - l'ancienne existe, la nouvelle non → RENAME TABLE : données, index et clés
 *    étrangères suivent, rien n'est copié ni perdu ;
 *  - la nouvelle existe et est vide, l'ancienne a des lignes (une table recréée
 *    vide par un appel arrivé trop tôt) → la vide disparaît, l'ancienne est renommée ;
 *  - les deux ont des lignes → on ne touche à rien et on le dit (journal) :
 *    trancher entre deux jeux de données n'est pas l'affaire d'un démarrage ;
 *  - aucune des deux → rien : le CREATE TABLE IF NOT EXISTS de la famille la fera.
 * Tous les renommages partent en UNE instruction RENAME TABLE : atomique, la base
 * n'est jamais à moitié migrée. Revenir en arrière : RENAME TABLE dans l'autre sens
 * (docs/contrat-api.md donne l'instruction complète).
 */

const MAR_TABLES = [
    // Réseaux sociaux
    'mar_social_post'        => ['ancien' => 'ceo_fb_post', 'famille' => 'Réseaux sociaux', 'quoi' => 'Les posts : rédaction, contrôle, décision, publication'],
    'mar_social_ecart'       => ['ancien' => 'ceo_fb_finding', 'famille' => 'Réseaux sociaux', 'quoi' => 'Les écarts relevés par l’agent sur un post'],
    'mar_social_publication' => ['ancien' => null, 'famille' => 'Réseaux sociaux', 'quoi' => 'Une publication par post et par réseau (Facebook, Instagram, LinkedIn)'],
    // Charte de marque (Brand Guard)
    'mar_bg_controle'        => ['ancien' => 'brand_guard_checks', 'famille' => 'Charte de marque', 'quoi' => 'Chaque verdict de la charte, avant ou après publication, sauvages compris'],
    'mar_bg_page'            => ['ancien' => 'brand_guard_pages', 'famille' => 'Charte de marque', 'quoi' => 'La page Facebook de chaque magasin'],
    'mar_bg_regle'           => ['ancien' => 'brand_guard_rules', 'famille' => 'Charte de marque', 'quoi' => 'Les règles de la charte relues par le modèle'],
    // Emailing
    'mar_nl_magasin'         => ['ancien' => 'ceo_nl_magasin', 'famille' => 'Emailing', 'quoi' => 'L’adresse d’envoi de la marque et de chaque magasin'],
    'mar_nl_source'          => ['ancien' => 'ceo_nl_source', 'famille' => 'Emailing', 'quoi' => 'Les bases de contacts (particuliers, bureaux, B2B, CRM)'],
    'mar_nl_segment'         => ['ancien' => 'ceo_nl_segment', 'famille' => 'Emailing', 'quoi' => 'Les segments et leurs règles'],
    'mar_nl_campagne'        => ['ancien' => 'ceo_nl_campagne', 'famille' => 'Emailing', 'quoi' => 'Les newsletters et automatisations'],
    'mar_nl_contact'         => ['ancien' => 'ceo_nl_contact', 'famille' => 'Emailing', 'quoi' => 'Les contacts importés, leur consentement, leur désinscription'],
    'mar_nl_envoi'           => ['ancien' => 'ceo_nl_envoi', 'famille' => 'Emailing', 'quoi' => 'Chaque mail parti : ouverture, clic, voucher'],
    'mar_nl_voucher'         => ['ancien' => 'ceo_nl_voucher', 'famille' => 'Emailing', 'quoi' => 'Les codes promo émis et utilisés'],
    // Avis Google
    'mar_avis_fiche'         => ['ancien' => 'ceo_shop_reputation', 'famille' => 'Avis Google', 'quoi' => 'La fiche Google de chaque magasin : note, nombre d’avis, synchro'],
    'mar_avis'               => ['ancien' => 'ceo_shop_review', 'famille' => 'Avis Google', 'quoi' => 'Les avis rapatriés, magasin par magasin'],
    // Campagnes (le côté cockpit : mesure, objectifs, annexes)
    'mar_campagne_annexe'           => ['ancien' => 'ceo_mkt_annexe', 'famille' => 'Campagnes', 'quoi' => 'Les PDF joints à une campagne'],
    'mar_campagne_annexe_type'      => ['ancien' => 'ceo_mkt_annexe_type', 'famille' => 'Campagnes', 'quoi' => 'Les types d’annexe'],
    'mar_campagne_mesure'           => ['ancien' => 'ceo_campagne_mesure', 'famille' => 'Campagnes', 'quoi' => 'Les réglages de la mesure d’impact d’une campagne'],
    'mar_campagne_releve'           => ['ancien' => 'ceo_campagne_releve', 'famille' => 'Campagnes', 'quoi' => 'Les relevés à la main (abonnés Facebook avant/après)'],
    'mar_campagne_snapshot'         => ['ancien' => 'ceo_campagne_snapshot', 'famille' => 'Campagnes', 'quoi' => 'La mesure gelée en fin de campagne'],
    'mar_campagne_objectif'         => ['ancien' => 'ceo_campagne_objectif', 'famille' => 'Campagnes', 'quoi' => 'Les objectifs de CA par campagne et par magasin'],
    'mar_campagne_objectif_produit' => ['ancien' => 'ceo_campagne_objectif_produit', 'famille' => 'Campagnes', 'quoi' => 'Les objectifs par produit'],
    'mar_campagne_produit'          => ['ancien' => 'ceo_campagne_produit', 'famille' => 'Campagnes', 'quoi' => 'Les produits mis en avant par une campagne'],
];

/**
 * Le plan de migration, sans toucher à la base : ce qu'il faut renommer, ce qu'il
 * faut effacer (une nouvelle table vide), ce qui reste en conflit.
 *
 * @param array<string,int|null> $existantes nom de table → lignes (null : inconnu)
 * @return array{renommer: array<string,string>, effacer: list<string>, conflits: list<string>}
 */
function marPlanMigration(array $existantes): array
{
    $plan = ['renommer' => [], 'effacer' => [], 'conflits' => []];
    foreach (MAR_TABLES as $nouveau => $d) {
        $ancien = $d['ancien'];
        if ($ancien === null || !array_key_exists($ancien, $existantes)) { continue; }
        if (!array_key_exists($nouveau, $existantes)) { $plan['renommer'][$ancien] = $nouveau; continue; }
        $nNouveau = $existantes[$nouveau]; $nAncien = $existantes[$ancien];
        if ($nNouveau === 0 && $nAncien !== 0) { $plan['effacer'][] = $nouveau; $plan['renommer'][$ancien] = $nouveau; }
        elseif ($nAncien === 0) { continue; }   // l'ancienne est vide : la nouvelle fait foi, on laisse l'ancienne
        else { $plan['conflits'][] = $ancien . ' et ' . $nouveau . ' ont toutes deux des lignes'; }
    }
    return $plan;
}

/** La migration elle-même. Rend ce qui a été fait, pour le journal et l'écran. */
function marMigrer(): array
{
    $anciens = array_values(array_filter(array_column(MAR_TABLES, 'ancien')));
    $noms = array_merge($anciens, array_keys(MAR_TABLES));
    $existantes = [];
    $place = implode(',', array_fill(0, count($noms), '?'));
    foreach (Db::rows('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $place . ')', $noms) as $r) {
        $existantes[(string) $r['t']] = null;
    }
    // Rien d'ancien en base : déjà migrée, ou installation neuve. Une seule requête, c'est tout.
    if (!array_intersect($anciens, array_keys($existantes))) { return ['renommer' => [], 'effacer' => [], 'conflits' => []]; }
    // Les lignes ne se comptent que là où le plan en a besoin : les deux noms présents.
    foreach (MAR_TABLES as $nouveau => $d) {
        if ($d['ancien'] !== null && array_key_exists($d['ancien'], $existantes) && array_key_exists($nouveau, $existantes)) {
            foreach ([$d['ancien'], $nouveau] as $t) {
                $existantes[$t] = (int) (Db::row('SELECT COUNT(*) AS n FROM `' . $t . '`')['n'] ?? 0);
            }
        }
    }
    $plan = marPlanMigration($existantes);
    foreach ($plan['effacer'] as $t) { Db::exec('DROP TABLE `' . $t . '`'); }
    if ($plan['renommer']) {
        Db::exec('RENAME TABLE ' . implode(', ', array_map(static fn ($a, $n) => '`' . $a . '` TO `' . $n . '`', array_keys($plan['renommer']), $plan['renommer'])));
    }
    if (function_exists('journalAdd') && ($plan['renommer'] || $plan['conflits'])) {
        journalAdd('Installation', 'Paramètre', 'Marketing',
            ($plan['renommer'] ? count($plan['renommer']) . ' table(s) renommée(s) sous mar_ : ' . implode(', ', array_map(static fn ($a, $n) => $a . ' → ' . $n, array_keys($plan['renommer']), $plan['renommer'])) : '')
            . ($plan['conflits'] ? ' — à trancher à la main : ' . implode(' ; ', $plan['conflits']) : ''));
    }
    return $plan;
}

/* --- les adresses du portail ---------------------------------------------------------- */

/**
 * Les API du portail vivent sous /api/cockpit/marketing/… : /marketing/social,
 * /marketing/newsletter, /marketing/avis, à côté de /marketing/brand-guard et des
 * campagnes. Les anciennes adresses restent valables, réécrites ici avant le
 * routage : des mails déjà partis portent le suivi /newsletter/o|c|u/…, le cron
 * appelle /newsletter/cron, et un signet ou un écran resté en cache aussi.
 * Une seule table de correspondance : le routeur ne connaît que les nouvelles.
 */
const MAR_ALIAS = [
    '/facebook/posts'               => '/marketing/social/posts',
    '/referentiels/facebook-regles' => '/marketing/social/regles',
    '/newsletter'                   => '/marketing/newsletter',
    '/reputation'                   => '/marketing/avis',
];

/** Le chemin canonique d'un appel : l'ancien préfixe remplacé, le reste intact. */
function marCheminCanonique(string $path): string
{
    foreach (MAR_ALIAS as $ancien => $nouveau) {
        if ($path === $ancien || str_starts_with($path, $ancien . '/')) { return $nouveau . substr($path, strlen($ancien)); }
    }
    return $path;
}
