# Galette des rois 2027 : notre situation face à la concurrence

Relevé du 7 novembre 2026 · réseau L'Atelier By (Corbais, Gosselies, Halle, Sombreffe).

**Sources :**
- Nos prix : atelierby.be, page Galette des Rois, et le panel de Halle (9/10/2026).
- Concurrents : 24 prix publics relevés chez 14 concurrents (supermarchés, chaînes, artisans).
  Il n'existe pas encore de prix 2027 : les relevés datent de la saison de janvier 2026, sauf
  mention contraire.

Données brutes : `src/data/benchmark_galette.json`. Rapport à jour :
`php bin/benchmark.php rapport galette`.

**Comment on compare.** Les galettes se vendent au diamètre ou au nombre de parts. Chaque prix
est ramené à **notre diamètre, Ø 24 cm, à surface égale** : prix × (24 / Ø)². Quand la page ne
donne qu'un nombre de parts, on retient 4 p ≈ Ø 18 cm, 6 p ≈ Ø 22 cm, 8 p ≈ Ø 25 cm. Ces
diamètres sont marqués « estimé » dans le détail.

## En bref

1. **Prix : nous sommes au prix des boulangeries, à 2 à 4 fois celui des supermarchés.**
   - Notre galette à 24,90 € (Ø 24 cm) se place 5 % sous la médiane des artisans (26,18 €) et
     7 % sous celle des chaînes (26,73 €).
   - Les trois concurrents qui publient un vrai diamètre sont à **23,5–24,3 €** : Paysans-Artisans,
     Dandoy et Lefevre. Nous sommes exactement dans ce prix.
   - Les supermarchés sont à 5,60–11,90 € ramenés à Ø 24 (médiane 7,13 €).
   - **La précommande à 19,90 € n'a pas d'équivalent** : aucun concurrent relevé n'offre de
     remise pour réserver tôt.
2. **Visibilité web : bien meilleure que pour le cougnou, 7 contrôles sur 11.**
   - La page dédiée a le bon titre et la bonne description, le prix est affiché, et la version
     NL dit « driekoningentaart ».
   - Il manque le diamètre et le nombre de parts (0 mention), le balisage produit, la mention
     sur l'accueil et le robots.txt.

## 1. Prix

### Notre galette

| Produit | Prix | Ø | Prix ramené à Ø 24 |
|---|---|---|---|
| Galette frangipane, à partir du 31/12 | 24,90 € | 24 cm | 24,90 € |
| Galette frangipane, précommande webshop jusqu'au 30/12 | 19,90 € | 24 cm | 19,90 € |

Au panel de Halle (9/10/2026), la galette était encore à **20,50 €**, avec un coefficient de
× 2,12 (marge brute 53 %). À 24,90 €, le coefficient passe à × 2,57 ; à 19,90 €, il est de
× 2,06. Il faut aligner le prix du panel avant l'ouverture des précommandes.

### La concurrence, ramenée à Ø 24 cm

| Type | Relevés | Min | Médiane | Max | Nous (24,90 €) vs médiane |
|---|---|---|---|---|---|
| Supermarchés (Aldi, Lidl, Colruyt, Carrefour, Delhaize, Intermarché) | 9 | 5,58 € | 7,13 € | 11,89 € | +249 % |
| Chaînes (Dandoy, Pain d'Antan) | 3 | 23,51 € | 26,73 € | 40,89 € | **−7 %** |
| Artisans | 7 | 23,68 € | 26,18 € | 37,33 € | **−5 %** |

**Les repères :**

- **Prix comparables au nôtre :**
  - Paysans-Artisans (Namur), Ø 24 cm, 24,33 € ;
  - Dandoy, Ø 28 cm, 32 €, soit 23,51 € ramené à Ø 24 ;
  - Lefevre (Marche), Ø 22 cm, 19,90 €, soit 23,68 € ;
  - Van Dessel (Dilbeek, près de Halle), 6 p, 21 €, soit ≈ 25 €.
- **Plus chers que nous :**
  - Pain d'Antan (Wavre, Gosselies) : 8 p à 29 € (≈ 26,70 €) et 4 p à 23 € (≈ 40,90 €) ;
  - Chacolathé (Andenne) : ≈ 26–29 € ;
  - Boulengier (meilleure galette de Bruxelles 2025) : ≈ 34–37 €.
- **Supermarchés :**
  - Aldi 4,99 €, Lidl, Colruyt et Intermarché 5,99 €, Carrefour 4,69–6,99 € ;
  - Delhaize 6,89–9,99 € pour les 6 parts, 12,59–12,99 € pour ses galettes « Taste of
    Inspirations » et cuites en magasin (taille non précisée).
- **Aucun prix publié** à Gosselies, Charleroi ou Halle. Paul, Le Pain Quotidien, Louise et
  Maison Demaret ne publient pas de prix non plus.

### Décisions de prix proposées

- **Garder 24,90 €.** C'est le prix des artisans qui donnent un vrai diamètre, et nous restons
  sous les chaînes et les pâtissiers. Un prix plus bas ne nous ferait pas gagner contre les
  supermarchés, qui sont 3 fois moins chers.
- **Faire de la précommande à 19,90 € l'argument principal.** Elle est 20 % sous le prix des
  artisans et personne d'autre ne la propose. Il faut la mettre en avant partout : page,
  webshop, Facebook, tickets de caisse en décembre.
- **Afficher « Ø 24 cm · 8 parts »** sur la page, le webshop et l'étiquette : les concurrents
  vendent au nombre de personnes, et sans cette mention le client ne peut pas comparer.

## 2. Visibilité web

Contrôles automatiques sur atelierby.be (refaits à chaque passage de la routine) :

| | Contrôle |
|---|---|
| ✓ | Page `/galette-des-rois.html` en ligne, et présente dans le sitemap |
| ✓ | « galette » dans le titre (*Galette des Rois · L'Atelier By*) et dans la description |
| ✓ | Galette lisible dans le HTML servi, prix affiché (19,90 € / 24,90 €) |
| ✓ | Version NL : *Driekoningentaart · L'Atelier By* |
| ✗ | Diamètre ou nombre de parts affiché |
| ✗ | Balisage schema.org Product / Offer |
| ✗ | Galette visible sur l'accueil (HTML servi) |
| ✗ | robots.txt (404) |

**Points en plus, qui différencient notre page :** bon cadeau à envoyer à un ami, photo
« couronnée » imprimée sur l'étiquette, jeu de la Fève d'Or, retrait en boutique.

**Ce qui affaiblit la page :**

- Le titre ne contient aucun nom de ville.
- Les gammes Saint-Nicolas et Épiphanie du site portent la même description (« Traditionnelle
  galette des rois… »), ce qui est faux pour Saint-Nicolas et fait doublon pour Épiphanie.

### Plan d'action, avant le 15 décembre

1. Ajouter **« Ø 24 cm · 8 parts »** au produit (page, webshop, étiquette).
2. Mettre le titre au format **« Galette des rois artisanale – Corbais, Gosselies, Sombreffe,
   Halle | L'Atelier By »**.
3. Ajouter un **balisage Product/Offer** avec les deux prix (19,90 € jusqu'au 30/12, puis
   24,90 €) et les dates de saison.
4. Mettre une **mise en avant sur l'accueil** à partir du 1er décembre : « Précommandez votre
   galette à 19,90 € ».
5. Corriger la description de la gamme Saint-Nicolas ; ajouter un robots.txt avec le sitemap.
6. Aligner le prix du panel (20,50 € à Halle) sur 24,90 € / 19,90 €.
7. **Relever à nouveau les prix vers le 3 janvier**, quand les concurrents publient les leurs.
   La routine du jeudi tourne pour la galette du 1er décembre au 20 janvier.

## 3. Dans l'ERP

Le relevé galette s'ajoute à celui du cougnou dans le module Benchmark de TFBuddy :
- mêmes concurrents (retrouvés par leur nom, jamais recréés) ;
- une observation datée par concurrent et par magasin ;
- des items rattachés à notre produit 4100013 (Galette Frangipane), en `unit_type = cm`,
  `unit_size` = diamètre.

Pour poser les deux relevés (simulation d'abord, puis écriture) :

```bash
php bin/benchmark.php semer tout
```

```bash
php bin/benchmark.php semer tout --appliquer
```
