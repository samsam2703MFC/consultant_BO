# Benchmark concurrents : importer les PDF d'un concurrent et les rapprocher de nos produits

**Statut : maquette, à valider.** Demande du 14/11/2026 : « pour le magasin de Sombreffe et alentours, pour le
concurrent Dumont Partner à Sombreffe, encoder ceci dans les benchmarks (rajouter un mode en pilotage où j'uploade les
PDF et fais le matching avec nos produits) ».

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
