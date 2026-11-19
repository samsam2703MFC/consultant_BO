<?php
declare(strict_types=1);

/**
 * Le dépôt en quatre domaines (réorganisation du 19/11/2026) : où est la racine, et où est le code.
 *
 *   marketing/   formation/   consultant/   panel/   — un dossier par domaine (src/, public/, bin/, tests/, sql/, docs/)
 *   shared/      ce qui sert à plusieurs domaines (accès, clés, base, routeur de l'API, déploiement)
 *   pilotage-reseau/   ce qui est hors des quatre domaines (copie de l'ERP, module RH)
 *   public/      le site ASSEMBLÉ (shared/bin/assembler_public.sh) : des liens vers le public/ de chaque domaine, et les
 *                fichiers déposés (uploads/) ; il n'est pas dans Git. Sur le serveur : TARGET_DIR/public.
 *
 * Même disposition dans le dépôt et sur le serveur : un chemin relatif entre deux fichiers reste vrai partout.
 */

/** Les dossiers du code, dans l'ordre où l'API les charge : le partagé d'abord. */
const DEPOT_DOMAINES = ['shared', 'marketing', 'formation', 'consultant', 'panel', 'commercial'];

/** La racine du dépôt (ou du déploiement). */
function depotRacine(): string
{
    return dirname(__DIR__, 2);
}

/** Les modules PHP du serveur, tous domaines : chaque …/src/*.php (sans le module RH, hors périmètre). */
function depotSources(): array
{
    $r = depotRacine();
    $out = [];
    foreach (DEPOT_DOMAINES as $d) {
        foreach (glob("$r/$d/src/*.php") ?: [] as $f) { $out[] = $f; }
    }
    return $out;
}

/** Le site assemblé (adresse → fichier) : TARGET_DIR/public sur le serveur, public/ à la racine en développement. */
function depotPublic(): string
{
    return depotRacine() . '/public';
}
