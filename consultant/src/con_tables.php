<?php
declare(strict_types=1);

/**
 * Les tables des consultants et des visites — un seul préfixe, `con_` (demande du 26/10/2026 : « l'entièreté des
 * tables utilisées pour les consultants et visite doit être dans des tables au préfixe con_ »).
 *
 * Le suivi des franchisés s'était écrit sous `ceo_`, mêlé au reste du cockpit : la visite et ce qu'elle porte
 * (points, photos, plans d'action), le client mystère, le scoring du trimestre, les tâches et checklists du
 * consultant, la météo du franchisé, le relevé des tâches du jour. Tout ce que le module possède passe sous `con_`,
 * le reste du nom ne change pas : qui connaît `ceo_visite_point` retrouve `con_visite_point`.
 *
 * Comme pour `mar_` (src/marketing_tables.php), le code écrit les noms EN CLAIR dans son SQL ; ce registre sert à la
 * migration, à l'écran « Sources et API » (src/suivi_sources.php) et à la documentation. `api` décrit la ressource
 * que le panel TFBuddy devrait exposer pour que la table vive chez lui, en lecture et en écriture : son chemin, sa
 * clé, ses filtres, son parent quand elle n'a de sens que sous une autre.
 *
 * MIGRATION — `conMigrer()`, appelée par `ensureInstalled()` AVANT toute création de table, mêmes règles que
 * `marMigrer()` : l'ancienne seule → RENAME TABLE (données, index, clés suivent) ; la nouvelle vide et l'ancienne
 * pleine → la vide disparaît, l'ancienne est renommée ; les deux pleines → rien, et le journal le dit ; aucune → le
 * CREATE TABLE IF NOT EXISTS de la famille la fera. Une seule instruction RENAME TABLE : atomique. Revenir en
 * arrière : RENAME TABLE dans l'autre sens (docs/contrat-api.md donne l'instruction complète).
 */

const CON_TABLES = [
    // La visite et ce qu'elle porte
    'con_visite'            => ['ancien' => 'ceo_visite', 'famille' => 'Visites', 'quoi' => 'Une visite : magasin, consultant, date, statut, constat, météo, préparation, rapport, CA face au budget',
        'api' => ['chemin' => '/consultant/visits', 'cle' => 'id', 'filtres' => ['shop_id', 'consultant_id', 'statut', 'date_from', 'date_to']]],
    'con_visite_point'      => ['ancien' => 'ceo_visite_point', 'famille' => 'Visites', 'quoi' => 'Les réponses de la checklist d’une visite, point par point (état, note, valeur, commentaire)',
        'api' => ['chemin' => '/consultant/visits/{visite_id}/points', 'cle' => 'id', 'parent' => 'visite_id', 'filtres' => ['module']]],
    'con_visite_photo'      => ['ancien' => 'ceo_visite_photo', 'famille' => 'Visites', 'quoi' => 'Les photos d’une visite (photo du jour, contrôles, corrections, signatures) et leur fichier',
        'api' => ['chemin' => '/consultant/visits/{visite_id}/photos', 'cle' => 'id', 'parent' => 'visite_id', 'filtres' => ['genre', 'point_ref'], 'fichier' => true]],
    'con_visite_action'     => ['ancien' => 'ceo_visite_action', 'famille' => 'Visites', 'quoi' => 'Les points à travailler décidés en visite : priorité, échéance, qui, statut, retour',
        'api' => ['chemin' => '/consultant/action-plans', 'cle' => 'id', 'filtres' => ['shop_id', 'visite_id', 'statut', 'assigne', 'echeance_avant']]],
    'con_visite_action_evt' => ['ancien' => 'ceo_visite_action_evt', 'famille' => 'Visites', 'quoi' => 'L’historique d’un point : chaque changement de statut, par qui, avec quelle photo',
        'api' => ['chemin' => '/consultant/action-plans/{plan_id}/events', 'cle' => 'id', 'parent' => 'plan_id', 'filtres' => []]],
    'con_equipe_releve'     => ['ancien' => 'ceo_equipe_releve', 'famille' => 'Visites', 'quoi' => 'L’effectif relevé en visite : présents, prévus, départs',
        'api' => ['chemin' => '/consultant/team-counts', 'cle' => 'id', 'filtres' => ['shop_id', 'date_from', 'date_to']]],
    // Le travail du consultant
    'con_consultant_tache'     => ['ancien' => 'ceo_consultant_tache', 'famille' => 'Consultant', 'quoi' => 'Les tâches du consultant : avant, pendant ou après une visite, échéance, statut',
        'api' => ['chemin' => '/consultant/todos', 'cle' => 'id', 'filtres' => ['consultant_id', 'shop_id', 'visite_id', 'statut']]],
    'con_consultant_checklist' => ['ancien' => 'ceo_consultant_checklist', 'famille' => 'Consultant', 'quoi' => 'Ce que le consultant remplit des checklists du cadre, par magasin et par jour',
        'api' => ['chemin' => '/consultant/checklist-answers', 'cle' => 'id', 'filtres' => ['shop_id', 'jour', 'membership_id']]],
    'con_franchise_meteo'      => ['ancien' => 'ceo_franchise_meteo', 'famille' => 'Consultant', 'quoi' => 'La météo du franchisé notée en fin de visite : moral, envie, équipe, relation, finances, paiements à temps (sinon quoi et le détail), demandes',
        'api' => ['chemin' => '/consultant/franchisee-mood', 'cle' => 'id', 'filtres' => ['shop_id', 'date_from', 'date_to']]],
    'con_consultant'           => ['ancien' => 'ceo_consultant', 'famille' => 'Consultant', 'quoi' => 'Les consultants d’une installation autonome (sans panel) : nom, rôle, TJM, charge',
        'api' => ['chemin' => '/panel/consultants', 'cle' => 'id', 'filtres' => []]],
    'con_consultant_visit'     => ['ancien' => 'ceo_consultant_visit', 'famille' => 'Consultant', 'quoi' => 'Les visites d’une installation autonome (ancien format, sans tablette)',
        'api' => ['chemin' => '/panel/consultants/{consultant_id}/visits', 'cle' => 'id', 'parent' => 'consultant_id', 'filtres' => []]],
    // Le client mystère et le scoring du trimestre
    'con_msp'            => ['ancien' => 'ceo_msp', 'famille' => 'Client mystère et scoring', 'quoi' => 'Les passages du client mystère encodés par mois (ancien format)',
        'api' => ['chemin' => '/consultant/mystery-shops/monthly', 'cle' => 'id', 'filtres' => ['shop_id', 'mois']]],
    'con_scoring_msp'    => ['ancien' => 'ceo_scoring_msp', 'famille' => 'Client mystère et scoring', 'quoi' => 'Le client mystère par trimestre : obtenu sur maximum, rubriques, PDF, suites',
        'api' => ['chemin' => '/consultant/mystery-shops', 'cle' => 'id', 'filtres' => ['shop_id', 'trimestre']]],
    'con_scoring_google' => ['ancien' => 'ceo_scoring_google', 'famille' => 'Client mystère et scoring', 'quoi' => 'La note Google figée à la fin de chaque trimestre, pour le scoring',
        'api' => ['chemin' => '/consultant/scoring/google', 'cle' => 'shop_id,trimestre', 'filtres' => ['shop_id', 'trimestre']]],
    // Le relevé des tâches du jour (contrôles photo)
    'con_tache_jour'      => ['ancien' => 'ceo_tache_jour', 'famille' => 'Tâches du jour', 'quoi' => 'Les tâches du panel relevées chaque jour, magasin par magasin : faite, note, commentaire',
        'api' => ['chemin' => '/consultant/tasks/daily', 'cle' => 'jour,id_shop,id_task', 'filtres' => ['id_shop', 'date_from', 'date_to']]],
    'con_tache_jour_etat' => ['ancien' => 'ceo_tache_jour_etat', 'famille' => 'Tâches du jour', 'quoi' => 'Le journal des jours relevés : « zéro tâche » n’est pas « jamais relevé »',
        'api' => ['chemin' => '/consultant/tasks/daily/days', 'cle' => 'jour', 'filtres' => ['date_from', 'date_to']]],
    'con_rayon_photo'     => ['ancien' => 'ceo_rayon_photo', 'famille' => 'Tâches du jour', 'quoi' => 'La photo type et la consigne de chaque rayon : le comptoir attendu, en face de chaque « Photo du comptoir »',
        'api' => ['chemin' => '/consultant/shelf-photos', 'cle' => 'cle', 'filtres' => []]],
];

/**
 * Le plan de migration, sans toucher à la base : ce qu'il faut renommer, ce qu'il faut effacer (une nouvelle table
 * vide), ce qui reste en conflit. Mêmes règles que marPlanMigration().
 *
 * @param array<string,int|null> $existantes nom de table → lignes (null : inconnu)
 * @return array{renommer: array<string,string>, effacer: list<string>, conflits: list<string>}
 */
function conPlanMigration(array $existantes): array
{
    $plan = ['renommer' => [], 'effacer' => [], 'conflits' => []];
    foreach (CON_TABLES as $nouveau => $d) {
        $ancien = $d['ancien'];
        if (!array_key_exists($ancien, $existantes)) { continue; }
        if (!array_key_exists($nouveau, $existantes)) { $plan['renommer'][$ancien] = $nouveau; continue; }
        $nNouveau = $existantes[$nouveau]; $nAncien = $existantes[$ancien];
        if ($nNouveau === 0 && $nAncien !== 0) { $plan['effacer'][] = $nouveau; $plan['renommer'][$ancien] = $nouveau; }
        elseif ($nAncien === 0) { continue; }   // l'ancienne est vide : la nouvelle fait foi, on laisse l'ancienne
        else { $plan['conflits'][] = $ancien . ' et ' . $nouveau . ' ont toutes deux des lignes'; }
    }
    return $plan;
}

/** La migration elle-même. Rend ce qui a été fait, pour le journal et l'écran « Sources et API ». */
function conMigrer(): array
{
    $anciens = array_column(CON_TABLES, 'ancien');
    $noms = array_merge($anciens, array_keys(CON_TABLES));
    $existantes = [];
    $place = implode(',', array_fill(0, count($noms), '?'));
    foreach (Db::rows('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $place . ')', $noms) as $r) {
        $existantes[(string) $r['t']] = null;
    }
    // Rien d'ancien en base : déjà migrée, ou installation neuve. Une seule requête, c'est tout.
    if (!array_intersect($anciens, array_keys($existantes))) { return ['renommer' => [], 'effacer' => [], 'conflits' => []]; }
    // Les lignes ne se comptent que là où le plan en a besoin : les deux noms présents.
    foreach (CON_TABLES as $nouveau => $d) {
        if (array_key_exists($d['ancien'], $existantes) && array_key_exists($nouveau, $existantes)) {
            foreach ([$d['ancien'], $nouveau] as $t) {
                $existantes[$t] = (int) (Db::row('SELECT COUNT(*) AS n FROM `' . $t . '`')['n'] ?? 0);
            }
        }
    }
    $plan = conPlanMigration($existantes);
    foreach ($plan['effacer'] as $t) { Db::exec('DROP TABLE `' . $t . '`'); }
    if ($plan['renommer']) {
        Db::exec('RENAME TABLE ' . implode(', ', array_map(static fn ($a, $n) => '`' . $a . '` TO `' . $n . '`', array_keys($plan['renommer']), $plan['renommer'])));
    }
    if ($plan['renommer'] || $plan['conflits']) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['conMigration', json_encode(['le' => date('Y-m-d H:i'), 'renommees' => $plan['renommer'], 'effacees' => $plan['effacer'], 'conflits' => $plan['conflits']], JSON_UNESCAPED_UNICODE)]);
    }
    if (function_exists('journalAdd') && ($plan['renommer'] || $plan['conflits'])) {
        journalAdd('Installation', 'Paramètre', 'Suivi des franchisés',
            ($plan['renommer'] ? count($plan['renommer']) . ' table(s) renommée(s) sous con_ : ' . implode(', ', array_map(static fn ($a, $n) => $a . ' → ' . $n, array_keys($plan['renommer']), $plan['renommer'])) : '')
            . ($plan['conflits'] ? ' — à trancher à la main : ' . implode(' ; ', $plan['conflits']) : ''));
    }
    return $plan;
}
