<?php
declare(strict_types=1);

/**
 * Le registre des renommages de la réorganisation en quatre domaines (19/11/2026) : ancien nom → nouveau nom, par
 * domaine. Les migrations shared/db/migrations/00N_*_tables.php le lisent ; la remise à zéro (bin/deploy.sh,
 * shared/bin/db_admin.php) y retrouve les tables qui s'appelaient ceo_* ; les tests vérifient qu'aucun ancien nom ne
 * reste dans le code. Les anciens noms ne vivent plus qu'ici et dans les migrations.
 */
const REORG_TABLES = [
    'FOR' => [   // Formation
        'ceo_manuel_formation' => 'for_manuel_formation',
        'ceo_manuel_procedure' => 'for_manuel_procedure',
        'ceo_ref_bloc' => 'for_ref_bloc',
        'ceo_ref_corresp' => 'for_ref_corresp',
        'ceo_ref_domaine' => 'for_ref_domaine',
        'ceo_ref_formation' => 'for_ref_formation',
        'ceo_ref_item' => 'for_ref_item',
        'ceo_ref_po_point' => 'for_ref_po_point',
        'ceo_ref_po_section' => 'for_ref_po_section',
        'ceo_ref_souscat' => 'for_ref_souscat',
        'ceo_sop' => 'for_sop',
        'ceo_sop_personne' => 'for_sop_personne',
        'ceo_sop_suivi' => 'for_sop_suivi',
        'ceo_sop_tache' => 'for_sop_tache',
        'ceo_sop_version' => 'for_sop_version',
        'ceo_tablette_acces' => 'for_tablette_acces',
        'ceo_tablette_essai' => 'for_tablette_essai',
        'ceo_tablette_gestion_essai' => 'for_tablette_gestion_essai',
        'ceo_tablette_magasin' => 'for_tablette_magasin',
    ],
];

/** Tous les renommages, tous domaines : [ancien => nouveau]. */
function reorgTables(): array
{
    return array_merge(...array_values(REORG_TABLES));
}
