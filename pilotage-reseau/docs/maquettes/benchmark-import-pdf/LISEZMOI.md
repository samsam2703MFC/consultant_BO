# Benchmark concurrents : importer les PDF d'un concurrent et les rapprocher de nos produits

**Statut : codé le 14/11/2026** (rail Pilotage › Benchmark, `#/benchmark`). Demandes du 14/11/2026 :
- « pour le magasin de Sombreffe et alentours, pour le concurrent Dumont Partner à Sombreffe, encoder ceci dans les
  benchmarks (rajouter un mode en pilotage où j'uploade les PDF et fais le matching avec nos produits) » ;
- « code le mode import PDF, soit import soit recherche sur internet via IA, mais dans le rail Benchmark, et ensuite
  classé quel concurrent et sur quel franchisé (en fonction des codes postaux) » ;
- « rajouter dans Concurrence les codes postaux avec les concurrents (regarde dans le scouting et CRM la façon dont les
  codes postaux sont représentés) ».

Ce qui suit décrit d'abord la maquette (`a.png`, `b.png`, `c.png`), puis ce qui est codé (`code-*.png`).

Un écran **Benchmark concurrents** dans le groupe Pilotage du rail, avec quatre modes : Vue d'ensemble, Relevés,
**Import PDF** (nouveau), Vérification de l'API. L'import se fait en trois étapes :

- `a.png` : **déposer**. À gauche, les concurrents déjà relevés pour Sombreffe (benchmarks du 07/11), rangés en « à
  moins de 15 km », « plus loin » et « supermarchés ». Au centre, la fiche du concurrent : Dumont et Partners,
  artisan, chaussée de Gembloux 37 à Tongrinne, n° BE 0465.380.462. Les PDF se déposent ici (tarif, carte des fêtes,
  folder scanné), avec la date du relevé. À droite, ce que le cockpit a lu dans une page, et les formats qu'il
  reconnaît.
- `b.png` : **rapprocher**. Chaque ligne du PDF face au produit de Sombreffe proposé (catalogue et prix du panel).
  Les deux prix sont ramenés sur une même base : au kilo, à surface égale pour un autre Ø, à la pièce pour un lot, ou
  à la part. On voit l'écart et la confiance. Une ligne est « sûre » à partir de 90 % ; en dessous, elle est « à
  confirmer », et la ligne ouverte montre les candidats, la recherche dans les 482 produits et « Retenir pour ce
  concurrent ». Restent « sans équivalent », « ignorée » (conditions, livraison) et « illisible » (scan).
- `c.png` : **encoder**. Le plan d'écriture chez TFBuddy, route par route (concurrent, observation datée, items avec
  `my_product_id`, promos), ce qui n'est pas écrit, les correspondances retenues, et Sombreffe face à Dumont : écart
  médian, écart par famille, nos plus gros écarts dans les deux sens.

## Données

- **Réel** :
  - nos produits et leurs prix TTC à Sombreffe (`GET /shops/5/products/available`, lu le 14/11/2026 en lecture seule) ;
  - les concurrents déjà relevés pour Sombreffe (`src/data/benchmark_*.json`) ;
  - l'identité de Dumont et Partners (registre public des entreprises).
- **Exemple**, en italique pointillé : les lignes et prix de Dumont, et les noms des PDF. **Les PDF n'ont pas été
  reçus avec la demande** ; les vrais remplaceront l'exemple.
- La distance (≈ 4 km) est à mesurer.

## Ce que la maquette a déjà montré

- Le Cougnou Nature 400 g est à **0,00 €** au panel de Sombreffe : aucune comparaison n'est possible tant que ce prix
  manque (déjà relevé dans le benchmark cougnou).
- Pour Sombreffe, aucun artisan à moins de 10 km n'était relevé : Dumont et Partners serait le premier.
- **Homonyme** : « Pâtisserie Dumont (Bouge) », déjà relevée pour Sombreffe, est une autre société (Namur). Le
  semis retrouve un concurrent par son nom normalisé (`benchmarkNomCle`), donc les deux ne se confondent pas.
- Plusieurs de nos produits n'ont pas de poids ou de Ø au panel (Tarte Sucre Blanc, Saint-Nicolas, Cougnou Sucre,
  dont le poids est estimé à 220 g). Ces lignes se comparent alors à la pièce.

## Pour le coder

- **Serveur** (`src/benchmark.php`) :
  - `POST /benchmarks/import` : dépôt du PDF, rangé dans le dossier privé, reconnu à son empreinte sha256 ;
  - `GET /benchmarks/import/{id}` : lignes lues et rapprochements proposés ;
  - `POST /benchmarks/import/{id}/encoder` : écrit avec les fonctions existantes de `BenchmarkApi`
    (`creerConcurrent`, `creerObservation`, `creerItem` via `benchmarkItemPourApi`), puis relit chaque écriture ;
  - l'étiquette `[cockpit:pdf:<empreinte>]` évite les doublons, comme le semis saisonnier.
- **Tables** : `pil_bench_import` (documents et lignes lues) et `pil_bench_correspondance` (libellé d'un concurrent
  → notre produit, confirmé à la main), dans le préfixe du portail Pilotage.
- **Lecture des PDF** :
  - PDF texte : `pdftotext -layout`, à vérifier sur le serveur ;
  - PDF scanné : lecture d'image avec la clé IA `commun` d'`erp_cles` ;
  - l'IA ne fait que lire : le rapprochement reste calculé, et rien n'est écrit sans « Encoder ».

Générer : `node generer.js` ; capturer (serveur `php -S 127.0.0.1:8099 -t .` depuis `pilotage-reseau/`) : `node capturer.js`.

## Ce qui est codé

Captures du vrai cockpit sur le banc d'essai (`essai/`) : relevé d'**exemple**, catalogue réel de Sombreffe.

| Capture | Écran |
|---|---|
| `code-1-nouveau.png` | Benchmark › Importer : nouveau relevé, un PDF du concurrent |
| `code-2-releve.png` | le PDF lu : le concurrent, son code postal et ses magasins, chaque ligne face à notre produit |
| `code-3-encode.png` | après « Confirmer », « Vérifier », « Encoder » : 38 / 38 lignes relues dans TFBuddy |
| `code-4-classement.png` | Benchmark › Classement : les concurrents de chaque magasin et de son franchisé, par code postal |
| `code-5-zones.png` | Benchmark › Zones : les codes postaux de chaque magasin, un code testé |
| `code-6-internet.png` | Benchmark › Importer : la recherche internet par l'IA |
| `code-7-dashboard-concurrence.png` | Dashboard magasin › Concurrence : les concurrents du magasin par code postal |

### Les codes postaux

Le cockpit ne peut pas ouvrir le dépôt du CRM (`atelier-espace-candidat`) : la représentation reprise est celle de
**MétaDonnée**, dans ce dépôt, qui range déjà un lead sur son magasin par son code postal.
- Une zone est une liste de **plages `{de, a, shopId, libelle}`** (le format de `mdReglages()['cp']`) ; un code seul
  est une plage `de = a`. Un code peut servir deux magasins (Gembloux 5030 : Sombreffe et Corbais).
- Par défaut, les codes postaux à moins de 10 km de chaque boutique (localités bpost, coordonnées OpenStreetMap,
  calculé une fois : `BI_ZONES_DEFAUT`). Réglage `benchmark.zones` dans `ceo_app_setting`.
- Affichage : l'étiquette « CP 5140 », puis « dans la zone », « zone de Gosselies », « hors zone », « national ».
- Chez TFBuddy, un concurrent n'a pas de champ code postal : le cockpit l'écrit dans ses notes, « CP 5140 [cp:5140] »,
  et le relit de là (sinon de sa ville).
- Le magasin et son franchisé : la table des boutiques (`ep_stores`), sinon `/shops` du panel (« Atelier by Harmonie -
  Sombreffe » → Harmonie).

### Le serveur (`src/benchmark_import.php`)

| Route | Rôle |
|---|---|
| `GET /benchmarks/import` | les relevés importés, les magasins et leurs zones, l'état de l'IA et du panel |
| `GET /benchmarks/import/{id}` | un relevé rapproché du catalogue du magasin |
| `POST /benchmarks/import/pdf` | `{fichier: dataURL, nom, concurrent?: {nom, cp}}` : PDF gardé dans le dossier privé, lu par l'IA |
| `POST /benchmarks/import/web` | `{concurrent, lieu, site?, consignes?}` : recherche internet par l'IA |
| `PUT /benchmarks/import` | `{id, concurrent?, magasins?, date?, choix?: {ligne: pid \| 0 sans équivalent \| -1 ignorer \| null}}` |
| `POST /benchmarks/import/encoder` | `{id, apercu?}` : le plan, puis l'écriture relue dans TFBuddy |
| `DELETE /benchmarks/import/{id}` | le relevé et son PDF (ce qui est encodé reste chez TFBuddy) |
| `GET /benchmarks/classement` | les concurrents de chaque magasin, classés par code postal |
| `PUT /benchmarks/zones` | `{plages}` ou `{defaut: true}` |

- **IA** (`src/anthropic.php`) : `releveDepuisPdf` (le PDF en document, sortie structurée au schéma du relevé) et
  `releveDepuisWeb` (recherche et lecture web, outil `enregistrer_releve` strict, tours en pause repris). Modèle :
  réglage `anthropic.modeleBenchmark`, Claude Opus 5.5 par défaut, repli serveur sur refus. La clé est celle de
  Paramètres › IA. **Le modèle relève, il ne décide rien** : le rapprochement est calculé par le cockpit.
- **Rapprochement** : la mémoire des correspondances confirmées (`ceo_bench_correspondance`, ce concurrent puis le
  réseau), les mots du libellé face au nom et à la catégorie de nos produits, le format. Sûre à 90 % (cochée d'office),
  à confirmer en dessous ; rien n'est écrit sans « Encoder », et une ligne à confirmer bloque l'encodage.
- **Comparaison** : au kilo, à surface égale (autre Ø), à la pièce (lots), à la part ; indicative quand la taille
  n'est écrite que d'un côté, absente quand notre prix est à 0 au panel.
- **Encodage** : par magasin retenu, le concurrent (retrouvé par son nom, créé sinon), une observation datée portant
  `[cockpit:import:{id}]`, ses lignes ; rejouable sans doublon, chaque écriture relue.
- **Tests** : `php bin/tests_benchmark_import.php` (75 essais, sans réseau).

### Le banc d'essai (`essai/`)

```
rm -rf /tmp/bench-essai
BENCH_ESSAI=/tmp/bench-essai php -S 127.0.0.1:8099 -t . docs/maquettes/benchmark-import-pdf/essai/routeur.php
node docs/maquettes/benchmark-import-pdf/essai/capturer-code.js
```

(depuis `pilotage-reseau/`). Le vrai cockpit et le vrai serveur ; la base en SQLite, TFBuddy simulé, le catalogue de
Sombreffe (`catalogue-sombreffe.json` : noms, catégories, prix, poids, sans coûts de recette) ; l'IA simulée rend
`releve-exemple.json`, un relevé d'**exemple** construit pour la maquette.

### Avant la mise en ligne

- Poser la clé Anthropic (Paramètres › IA) si elle ne l'est pas : sans elle, le PDF et internet ne se lisent pas.
- Les PDF voyagent en base64 dans du JSON : la taille permise suit `post_max_size` de PHP (8 Mo → 5,7 Mo de PDF).
- Les PDF de Dumont et Partners n'ont pas encore été reçus : rien n'est encodé pour lui.
