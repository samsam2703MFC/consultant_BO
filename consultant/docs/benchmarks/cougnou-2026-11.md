# Cougnou 2026 : notre situation face à la concurrence

Relevé du 7 novembre 2026 · réseau L'Atelier By (Corbais, Gosselies, Halle, Sombreffe).
Sources : catalogue du panel (Halle, 9/10/2026), atelierby.be, 29 prix publics relevés sur
18 concurrents (supermarchés, chaînes, artisans à moins de 25 km de nos boutiques).
Données brutes : `src/data/benchmark_cougnou.json` ; rapport à jour : `php bin/benchmark.php rapport cougnou`.

## En bref

1. **Prix : nous sommes au niveau des supermarchés, très en dessous des boulangeries.**
   Notre cougnou à 2,90 € revient à **≈ 13,2 €/kg**. C'est 20 % au-dessus de la médiane des
   supermarchés (11,0 €/kg), mais 30 % sous la médiane des artisans (18,8 €/kg) et 41 % sous
   celle des chaînes (22,2 €/kg). Le poids (≈ 220 g) est **estimé à partir du coût de recette** :
   il faut le confirmer avec la fiche technique.
2. **Visibilité web : nous sommes invisibles sur le cougnou.** Aucune recherche « cougnou +
   ville » ne fait remonter L'Atelier By. La page « Gamme Noël » du site passe **3 contrôles
   sur 11** : le mot « cougnou » n'est ni dans le titre ni dans la description, aucun prix n'est
   affiché, il n'y a pas de balisage produit, et la page NL ne dit pas « koekebrood ».
   Personne ne tient ce terrain localement : c'est une place à prendre avant le 1er décembre.

## 1. Prix

### Nos cougnous

| Produit | Prix | Poids | €/kg |
|---|---|---|---|
| Cougnou Sucre / Raisin / Chocolat / Cannelle | 2,90 € | ≈ 220 g *(estimé)* | ≈ 13,2 € |
| Cougnou 4+1 (5 pièces) | 11,60 € (2,32 € pièce) | ≈ 1 100 g | ≈ 10,5 € |
| Cougnou Nature 400 g | **0 € sur le site, sans prix au panel** | 400 g | — |

Marges (Halle, coût de recette du panel) : coefficient × 2,4 à × 2,9. Le chocolat est le plus
juste (× 2,43, marge 59 %), le 4+1 aussi (× 2,23, marge 55 %).

### La concurrence, au kilo

| Type | Relevés | Min | Médiane | Max | Nous vs médiane |
|---|---|---|---|---|---|
| Supermarchés (Aldi, Colruyt, Carrefour, Delhaize, Match) | 10 | 7,48 € | 10,96 € | 23,92 € | **+20 %** |
| Artisans (rayon de nos boutiques) | 8 | 13,00 € | 18,75 € | 26,90 € | **−30 %** |
| Chaînes (Pain d'Antan, Pain Quotidien, Louise) | 4 | 21,11 € | 22,23 € | 30,56 € | **−41 %** |

8 des 22 produits dont le poids est connu sont moins chers que nous au kilo, et ce sont tous
des supermarchés, sauf un artisan en promotion (Viennoiseries Christophe, 150 g à 1,95 €).

**Les repères les plus proches de nos boutiques :**

- **Corbais :** Le Pain d'Antan (Wavre) vend 180 g à 3,80–4,10 € (21–23 €/kg), Le Pain
  Quotidien Wavre 5,50 € la pièce, et Les Trésors du Pain (Mont-Saint-Guibert, à 3 km) prend les
  précommandes en ligne (prix pas encore publié pour 2026).
- **Gosselies :** Viennoiseries Christophe 2,20 € les 150 g (14,7 €/kg, en promo à 1,95 €),
  500 g à 8,50 € ; Boulangerie Louise 2,20 € le petit, 3+1 gratuit (1,65 € pièce).
- **Sombreffe :** pas de prix publié dans un rayon de 15 km. Les plus proches : Pâtisserie
  Dumont (Namur) 2,50 €, et les coopératives bio 17,5–27 €/kg.
- **Halle :** Kiekebich (Anderlecht) 180 g à 3,90 € (21,7 €/kg), Van Dessel (Dilbeek) 3,25 €
  le petit. Les boulangeries de Halle (Josse Locus…) ne publient aucun prix.

**À noter :**

- Le seul « 3+1 » plus agressif que notre 4+1 est celui de Boulangerie Louise.
- Les prix supermarchés viennent de l'index des moteurs de recherche (saison 2025 probable) :
  les pages produit refusent la lecture directe.
- Les webshops des artisans rouvrent vers le 20–25 novembre : il faudra refaire un relevé à
  ce moment-là.

### Décisions de prix proposées

- **Garder 2,90 €.** Nous sommes « prix supermarché, qualité boulangerie ». Le prix est un
  argument : il faut l'afficher au kilo face aux artisans, et ne pas le baisser.
- **Fixer le Cougnou Nature 400 g entre 5,20 € et 6,50 €** (13–16 €/kg), toujours sous les
  artisans (15–24 €/kg en 500 g) et proche du Boni 500 g de Colruyt (4,99 €). Aujourd'hui, il
  s'affiche à 0 € sur le site.
- **Publier le poids** de chaque cougnou (étiquette, site, webshop) : sans lui, le client ne
  peut pas voir que nous sommes 30 à 40 % moins chers que les boulangeries.

## 2. Visibilité web

### Contrôles automatiques sur atelierby.be (refaits à chaque passage de la routine)

| | Contrôle |
|---|---|
| ✓ | Page « Gamme Noël & Nouvel An » en ligne, et présente dans le sitemap |
| ✓ | Cougnous lisibles dans le HTML de la page (5 des 6 produits) |
| ✗ | « cougnou » dans le titre : *« Gamme Noël & Nouvel An · L'Atelier By »* |
| ✗ | « cougnou » dans la description, qui parle de *bûches* (aucune bûche dans la liste) |
| ✗ | Prix affichés |
| ✗ | Poids affichés (seul le Nature 400 g a le sien) |
| ✗ | Balisage schema.org Product / Offer |
| ✗ | Cougnou visible sur l'accueil (il n'y est que dans le JavaScript) |
| ✗ | robots.txt (404) |
| ✗ | « koekebrood » sur la page NL (Halle) : titre et description restés en français |

Autres défauts relevés :

- Le webshop est une application JavaScript sans contenu indexable, et les cougnous ne sont
  **pas commandables en ligne**.
- La gamme Saint-Nicolas porte la description de la galette des rois.
- « Canelle » est mal orthographié dans le nom du produit.

### Recherche locale

- Sur **« cougnou Corbais / Wavre / Gosselies / Charleroi / Sombreffe / Gembloux / Halle »**,
  L'Atelier By n'apparaît jamais. Les résultats sont des encyclopédies, des recettes, Too Good To
  Go, et quelques articles de presse (Maison Demaret, Le Pain d'Antan).
- **Aucun concurrent local n'a non plus de page cougnou bien placée.** Le terrain est libre.
- Ce qui nous dessert, ce sont nos **fiches incohérentes** :
  - le nom change d'une fiche à l'autre : « Atelier by », « L'Atelier By Berlo », « Het
    Atelier » ;
  - Yelp place Halle au **n° 227** au lieu du 22 ;
  - les horaires ne concordent pas entre le site et Pages d'Or ;
  - d'anciennes pages (`/nos-ateliers/`, `/nl/points-de-vente/…`) sont encore indexées mais
    renvoient 404.
- **Les concurrents prennent les précommandes en ligne :** presque tous les artisans qui ont un
  webshop (Bakeronline / Bestelonline) le font, dont Les Trésors du Pain à 3 km de Corbais.

*Limite : la recherche a été faite depuis un moteur hors de Belgique. Le classement Google Maps
réel se mesure automatiquement dès que la clé Google (Places) est posée dans Paramètres : la
routine relève alors notre rang sur « cougnou <ville> », « boulangerie <ville> » et
« koekebrood Halle ».*

### Plan d'action, avant le 1er décembre

1. **Page `/cougnou/` + `/nl/koekebrood/`** : titre *« Cougnou artisanal – Corbais, Gosselies,
   Sombreffe, Halle | L'Atelier By »*, prix, poids, prix au kilo, date limite de commande, dates
   de retrait, bouton « Précommander mon cougnou ».
2. **Titre et description de la gamme Noël centrés sur le cougnou**, et version NL traduite.
3. **Balisage Product/Offer** (prix, EUR, disponibilité, dates de saison) ; corriger le 0 € du
   Nature 400 g.
4. **Cougnous précommandables sur le webshop**, avec un lien depuis chaque page boutique.
5. **Fiches Google Business des 4 boutiques** : un seul nom « L'Atelier By – Ville », Halle au
   n° 22, horaires justes, lien du site, un produit « Cougnou » avec photo et prix, un post par
   semaine à partir du 15 novembre.
6. **Fiches Yelp, Pages d'Or, Foursquare, Uber Eats** corrigées ; redirections 301 des
   anciennes URL ; robots.txt avec le sitemap.
7. **Un post cougnou sur les 4 pages Facebook** avec le lien de précommande ; proposer un sujet
   « où acheter son cougnou » à L'Avenir, la DH ou Vlan.

## 3. Le benchmark dans l'API, et sa routine

- **Vérification de l'API :**
  - `php bin/benchmark.php verifier` passe les routes Benchmark de TFBuddy en lecture.
  - `--ecrire` ajoute un cycle complet (concurrent, observation, item ; création, lecture,
    modification, suppression) sur un concurrent témoin, supprimé ensuite.
  - La même vérification passe par l'API du cockpit : `GET /api/cockpit/benchmarks/verification`,
    ou `POST` avec `{"ecrire": true}`.
- **Benchmark cougnou :**
  - `php bin/benchmark.php semer cougnou` simule, `--appliquer` pose dans TFBuddy, pour chaque magasin,
    ses concurrents, une observation datée du relevé et ses items, rattachés à nos produits
    (`my_product_id`).
  - Rejouable sans doublon.
- **Routine :**
  - `bin/rapports_cron.sh` (déjà appelé chaque heure) passe le lundi à 7 h, et aussi le jeudi
    à 7 h du 1er novembre au 6 janvier.
  - À chaque passage : vérification de l'API, photo des analyses de chaque magasin
    (`ceo_benchmark_snapshot`), rapport cougnou (prix au kilo + contrôles web + rang Google si
    la clé est posée), envoi par e-mail.
  - Destinataires : `PUT /api/cockpit/benchmarks/reglages {"destinataires": [...]}`. La galette des rois suit le même circuit : `docs/benchmarks/galette-2026-11.md`.
- **Remarques sur l'API TFBuddy** (tirées de sa spec OpenAPI, à remonter au prestataire) :
  - `date_from` et `date_to` sont « passés sans validation au SQL » : **risque d'injection SQL**
    (le cockpit n'envoie que des dates validées) ;
  - `shopId` n'est pas contrôlé contre le jeton, et une observation ou un item n'est pas
    contrôlé contre son magasin : **pas de cloisonnement entre magasins** (la vérification en
    écriture le mesure et le signale) ;
  - les PATCH répondent 204 avec un corps, et `inserted_id` peut valoir 0 ou 1.
