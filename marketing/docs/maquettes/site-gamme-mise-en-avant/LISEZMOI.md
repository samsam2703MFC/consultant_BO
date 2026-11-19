# Site atelierby.be — la gamme de saison en « mise en avant », comme les plateaux de fromages

**Statut : maquette à valider (08/10/2026).** Rien n'est codé : la maquette se réalise dans le CMS du site, sans ligne de code.

Demande du 08/10/2026 : « retirer 🍂 Gamme de saison · du 1er septembre au 1er novembre / Gamme Automnale /
Sélection chaleureuse… / ← → / Mis en avant / Melocake – Boîte de 10 / Chocolaterie … et remplacer par un
module comme pour le fromage (fais une maquette) ».

## Où est ce bloc

Le bloc collé est le **chapitre « Gamme de saison »** (`#saison`) de la page d'accueil du site de la marque,
atelierby.be, dont le code vit dans le dépôt `samsam2703MFC/landing` (`index.html`, données `lp_api.php`,
gammes de l'ERP par `lp_seasons.php`). Il montre la gamme ouverte du panel (ici « Automnale », du 1er septembre
au 1er novembre), sa description, une galerie et les produits mis en avant, avec leur catégorie du webshop
(« Chocolaterie »). Capture : `avant-chapitre-saison.png`.

« Le module du fromage » est la **mise en avant « Nos plateaux de fromages. »** : une fiche de
CMS › Accueil › Mise en avant (table `lp_highlights`), rendue en couverture de l'accueil (`#miseenavant`), avec
sur-titre, titre, texte, grande photo et trois détails pointés, carte du produit principal, produits annexes,
lien du bas. Depuis le 08/10/2026, le dépôt landing (commits « Accueil : mises en avant en carrousel, chapitre de
saison masqué… ») masque le chapitre de saison par défaut (`lp_params.home_season_chapter` = 0) et met les fiches
actives en carrousel de couverture.

## La maquette

Une fiche « Gamme Automnale » ajoutée au carrousel de couverture, en première position, construite avec les
produits mis en avant de la gamme (catalogue du webshop) : `fiche-mise-en-avant.json` porte exactement les
champs à saisir dans le CMS.

- `couverture-bureau.png` : la couverture (1440 px) avec la fiche Automnale. Sur-titre « 🍂 Gamme de saison ·
  du 1er septembre au 1er novembre », titre « L'automne, *en chocolat.* », texte, carte « Édition d'automne ·
  Melocake – Boîte de 10 · Commander », panneau « Aussi dans la gamme » (six produits), lien « Commander toute
  la gamme Automnale », mosaïque de quatre photos pointées, pilule de navigation (trois fiches : Automnale,
  plateaux de fromages, viennoiseries).
- `couverture-telephone.png` : la même fiche au téléphone (390 px).

Les photos de la maquette sont les images des produits du webshop ; sur le site, les quatre photos de la fiche
se choisissent dans le CMS (grande photo portrait + trois détails, recadrage dans le CMS) — une photo de
comptoir d'automne ferait mieux que la pyramide de Melocakes.

## Comment le faire, sans code

1. CMS › Accueil › Mise en avant › nouvelle fiche : reprendre les textes FR/NL de `fiche-mise-en-avant.json`
   (sur-titre, titre avec le mot en `<span class="script">…</span>`, texte, texte du lien).
2. Produit principal : Melocake – Boîte de 10 (étiquette « Édition d'automne », accroche). Produits annexes :
   Boîte de 6, Blanc – Boîte de 3, Amandes grillées & salées - Lait, Cuiller Chocolat chaud Nature, Noisettes du
   Piémont – Lait, Melocake Spéculoos – Boîte de 3. Titre de la liste : « Aussi dans la gamme ».
3. Photos : grande photo + trois détails, puis les points sur les photos (produit lié, étiquette).
4. Lien du bas : la catégorie du webshop de la gamme (`link_mode` = group) ou l'adresse de la gamme.
5. Position 1 pour la mettre en tête du carrousel ; `season_id` = la gamme Automnale pour qu'elle disparaisse
   d'elle-même le 1er novembre ; `home_season_chapter` reste à 0 (le chapitre de saison reste masqué).

## Régénérer les captures

Le rendu se fait avec le dépôt landing servi en local (`php -S 127.0.0.1:8102 -t ../landing`), les données de
`lp_api.php?r=all` lues en ligne et complétées de la fiche (script de session, pas versionné ici : il relaie les
images par curl à travers le proxy).
