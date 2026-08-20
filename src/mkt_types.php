<?php
declare(strict_types=1);

/**
 * Cockpit CEO — le référentiel des types de campagne.
 *
 * Le module marketing autonome disparaît, mais ses types restent : les
 * campagnes passées portent leur identifiant, l'assistant les affiche en
 * grille de cartes, le calendrier en prend la couleur et l'impression le
 * tracé de l'icône. Reprendre l'écran sans reprendre ses règles produisait des
 * types à moitié remplis — un type créé ici sortait sans icône, sans couleur
 * et sans levier lié, donc une carte vide dans l'assistant.
 *
 * Ce fichier porte donc ce que le module tenait dans son
 * `CampaignTypeRepository` : la bibliothèque d'icônes, la dérivation du code,
 * et les contrôles de champs. Mêmes clés, mêmes tracés, mêmes bornes — un type
 * doit se dessiner identique des deux côtés tant que les deux coexistent.
 */

/**
 * La bibliothèque d'icônes, tracés compris.
 *
 * Fermée, et côté serveur : une icône choisie ici doit se redessiner identique
 * dans l'assistant, dans le calendrier et dans les fichiers d'impression, qui
 * sont produits par l'API. Un emoji libre aurait changé de dessin d'un poste à
 * l'autre. Copie conforme de la bibliothèque du module (extraite, pas
 * retapée) : les clés sont écrites en base, elles ne peuvent pas diverger.
 *
 * Famille visuelle : linéaire, `stroke` 1,7, angles arrondis, boîte de 24.
 */
const MKT_ICONES = [
    'etoile'      => ['label' => 'Étoile',        'path' => 'M12 2l2.4 7.4H22l-6 4.5 2.3 7.1L12 16.6 5.7 21l2.3-7.1-6-4.5h7.6z'],
    'cadeau'      => ['label' => 'Cadeau',        'path' => 'M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7M12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7'],
    'sapin'       => ['label' => 'Sapin',         'path' => 'M12 2l4 6h-3l4 6h-3l4 6H4l4-6H5l4-6H6z'],
    'boutique'    => ['label' => 'Boutique',      'path' => 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6'],
    'panier'      => ['label' => 'Panier',        'path' => 'M6 6h15l-1.5 9h-12zM6 6L5 3H2M9 20a1 1 0 1 0 .01 0M18 20a1 1 0 1 0 .01 0'],
    'gens'        => ['label' => 'Clients',       'path' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8'],
    'poubelle'    => ['label' => 'Anti-gaspi',    'path' => 'M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14'],
    'croissant'   => ['label' => 'Viennoiserie',  'path' => 'M3 14a9 9 0 0 0 18 0c0-1-3 1-5 1s-4-2-4-4 2-5 1-5a9 9 0 0 0-10 8z'],
    'gateau'      => ['label' => 'Pâtisserie',    'path' => 'M4 21h16v-6a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4zM12 11V7M9 7V4M15 7V4M4 17h16'],
    'cafe'        => ['label' => 'Café',          'path' => 'M4 8h13v6a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5zM17 9h2a2 2 0 0 1 0 5h-2M6 3v2M10 3v2M14 3v2'],
    'plat'        => ['label' => 'Traiteur',      'path' => 'M3 16h18M4 16a8 8 0 0 1 16 0M12 5v3M2 20h20'],
    'etiquette'   => ['label' => 'Promotion',     'path' => 'M20 12l-8 8-9-9V3h8zM7.5 7.5a1 1 0 1 0 .01 0'],
    'megaphone'   => ['label' => 'Annonce',       'path' => 'M3 11l14-7v16L3 13zM7 14v5'],
    'fusee'       => ['label' => 'Lancement',     'path' => 'M5 15c-1 3 0 5 0 5s2 1 5 0M9 15l-3-3 1-4a11 11 0 0 1 9-6 11 11 0 0 1-6 9l-4 1zM14 9a1.5 1.5 0 1 0 .01 0'],
    'calendrier'  => ['label' => 'Saisonnalité',  'path' => 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z'],
    'soleil'      => ['label' => 'Été',           'path' => 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM12 1v3M12 20v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M1 12h3M20 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1'],
    'flocon'      => ['label' => 'Hiver',         'path' => 'M12 2v20M2 12h20M5 5l14 14M19 5L5 19'],
    'coeur'       => ['label' => 'Fidélité',      'path' => 'M12 20s-7-4.6-7-9.5A3.9 3.9 0 0 1 12 7a3.9 3.9 0 0 1 7 3.5c0 4.9-7 9.5-7 9.5z'],
    'poignee'     => ['label' => 'Partenariat',   'path' => 'M8 12l3 3 5-5M3 8l4-4 4 3 5-1 5 4-4 9-5-2-5 2-4-9z'],
    'ecole'       => ['label' => 'Écoles',        'path' => 'M2 8l10-4 10 4-10 4zM6 11v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5'],
    'bureau'      => ['label' => 'Entreprises',   'path' => 'M4 21V5a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v16M15 21V10h4a1 1 0 0 1 1 1v10M2 21h20M8 8h3M8 12h3M8 16h3'],
    'camion'      => ['label' => 'Livraison',     'path' => 'M3 7h11v9H3zM14 10h4l3 3v3h-7M6.5 19a1.5 1.5 0 1 0 .01 0M17.5 19a1.5 1.5 0 1 0 .01 0'],
    'ecran'       => ['label' => 'Digital',       'path' => 'M4 4h16v12H4zM2 20h20M9 8l4 2-4 2z'],
    'mobile'      => ['label' => 'Mobile',        'path' => 'M7 2h10a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zM10 19h4'],
    'photo'       => ['label' => 'Réseaux',       'path' => 'M4 3h16a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zM8 11a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM21 15l-5-5L5 21'],
    'graphique'   => ['label' => 'Performance',   'path' => 'M4 20V10M10 20V4M16 20v-7M22 20H2'],
    'cible'       => ['label' => 'Objectif',      'path' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM12 13a1 1 0 1 0 .01 0'],
    'ticket'      => ['label' => 'Bon / voucher', 'path' => 'M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4zM13 6v12'],
    'euro'        => ['label' => 'Prix',          'path' => 'M17 6a7 7 0 1 0 0 12M3 10h9M3 14h9'],
    'horloge'     => ['label' => 'Happy hour',    'path' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2'],
    'lieu'        => ['label' => 'Local',         'path' => 'M12 21s-7-6.3-7-11a7 7 0 0 1 14 0c0 4.7-7 11-7 11zM12 8a2 2 0 1 0 .01 0'],
    'drapeau'     => ['label' => 'Ouverture',     'path' => 'M4 22V4M4 4h13l-2 4 2 4H4'],];

/**
 * Les colonnes réellement présentes dans `mar_campaign_type`.
 *
 * Le schéma des tables `mar_*` vient des migrations du module, pas du cockpit :
 * sur une base restée à une version antérieure, `icon_key` ou `lever_id`
 * peuvent manquer. Lire la liste une fois évite d'écrire une colonne absente —
 * l'UPDATE entier échouerait, et l'écran répondrait 500 sur un simple
 * renommage.
 */
function mktTypeColonnes(): array
{
    static $cols = null;
    if ($cols === null) {
        $cols = array_column(
            Db::rows('SELECT column_name FROM information_schema.columns
                       WHERE table_schema = DATABASE() AND table_name = ?', ['mar_campaign_type']),
            'column_name');
        // MySQL renvoie COLUMN_NAME en majuscules selon la configuration.
        $cols = array_map('strtolower', $cols);
    }
    return $cols;
}

function mktTypeAColonne(string $nom): bool
{
    return in_array($nom, mktTypeColonnes(), true);
}

/**
 * Code technique dérivé d'un nom : « Fêtes (Noël) » donne « fetes_noel ».
 *
 * Les accents sont ramenés à leur lettre plutôt que supprimés — « fetes » se
 * retrouve, « fts » ne se retrouve pas. C'est la dérivation du module, au
 * caractère près : les jeux de données et les reprises désignent « fetes », et
 * un code produit autrement ne se retrouve plus.
 */
function mktSlug(string $texte): string
{
    $sans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);
    $sans = $sans === false ? $texte : $sans;
    $sans = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $sans) ?? '');
    return mb_substr(trim($sans, '_'), 0, 40);
}

/** `#abc` est développé, tout le reste est refusé plutôt que corrigé. */
function mktCouleur(mixed $valeur): ?string
{
    $brut = strtolower(trim((string) ($valeur ?? '')));
    if ($brut === '') { return null; }
    if (preg_match('/^#([0-9a-f]{3})$/', $brut, $court) === 1) {
        return '#' . $court[1][0] . $court[1][0] . $court[1][1] . $court[1][1] . $court[1][2] . $court[1][2];
    }
    if (preg_match('/^#[0-9a-f]{6}$/', $brut) !== 1) {
        throw new RuntimeException('Couleur invalide : format attendu #RRGGBB.');
    }
    return $brut;
}

function mktTexte(mixed $valeur, int $max): ?string
{
    $texte = trim((string) ($valeur ?? ''));
    return $texte === '' ? null : mb_substr($texte, 0, $max);
}

/**
 * Contrôle des champs d'un type, communs à la création et à la modification.
 *
 * Mêmes bornes que le module : 120 pour le nom, 300 pour la description, 60
 * pour le badge de levier, 160 pour le KPI. Elles ne sont pas décoratives —
 * ce sont les tailles des colonnes, et MySQL tronquerait en silence.
 *
 * @param array<string,mixed> $data champs déjà fusionnés avec l'existant
 * @return array<string,mixed> colonnes prêtes pour l'INSERT / l'UPDATE
 */
function mktTypeValide(array $data): array
{
    $label = trim((string) ($data['label'] ?? ''));
    if ($label === '') { throw new RuntimeException('Le nom du type est obligatoire.'); }
    if (mb_strlen($label) > 120) { throw new RuntimeException('Le nom du type dépasse 120 caractères.'); }

    // L'icône est prise dans la bibliothèque, jamais dans le corps de la
    // requête : accepter un tracé arbitraire ferait entrer du SVG écrit
    // ailleurs dans des pages et des PDF que la marque signe.
    $cle = trim((string) ($data['icon_key'] ?? ''));
    if ($cle !== '' && !isset(MKT_ICONES[$cle])) {
        throw new RuntimeException(sprintf('Icône inconnue : « %s ».', $cle));
    }

    $levier = $data['lever_id'] ?? null;
    if (!empty($levier)) {
        if (Db::row('SELECT 1 FROM mar_lever WHERE id = ?', [(int) $levier]) === null) {
            throw new RuntimeException('Levier introuvable.');
        }
    }

    return [
        'label'             => $label,
        'description'       => mktTexte($data['description'] ?? null, 300),
        'color_hex'         => mktCouleur($data['color_hex'] ?? null),
        // Le tracé est recopié depuis la bibliothèque : tout ce qui dessine
        // aujourd'hui lit `icon_path`, l'impression comprise, et n'a pas à
        // connaître les clés.
        'icon_key'          => $cle === '' ? null : $cle,
        'icon_path'         => $cle === '' ? null : MKT_ICONES[$cle]['path'],
        'lever_id'          => empty($levier) ? null : (int) $levier,
        'lever_badge_label' => mktTexte($data['lever_badge_label'] ?? null, 60),
        'default_kpi_label' => mktTexte($data['default_kpi_label'] ?? null, 160),
    ];
}

/** La bibliothèque telle que l'écran la consomme : clé, libellé, tracé. */
function mktIcones(): array
{
    $out = [];
    foreach (MKT_ICONES as $cle => $icone) {
        $out[] = ['cle' => $cle, 'nom' => $icone['label'], 'path' => $icone['path']];
    }
    return $out;
}
