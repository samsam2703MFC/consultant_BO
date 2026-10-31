# Plan : rubrique « Vérification » (stocks, ventes, recettes, chiffre d'affaires)

Demandes du 31/10/2026. Maquette : [`docs/maquettes/verification/`](maquettes/verification/LISEZMOI.md)
(`planche.jpg` pour tout voir d'un coup).

## 1. Le besoin

1. Reprendre **les stocks et les ventes** de chaque franchisé et vérifier, **par les
   recettes**, que tout est en ordre. Faire la même chose pour le **chiffre d'affaires**.
2. Une **bascule** qui liste **toutes les recettes douteuses ou manquantes**, pour une
   gestion à 100 %.
3. Un **audit par magasin au jour, à la semaine et au mois** : entré · produit · vendu ·
   poubelle · invendu, **en %, dans un tableau**.
4. **Un tableau par magasin, tous de la même structure** : stock début + entrées − stock
   fin = sorti ; sorti − ventes − poubelle = **delta**, par catégorie puis par produit.
   Le but : **voir où ça fuit, et si ça fuit**.
5. **Tout enregistrer chaque jour en base**, pour suivre les progrès de gestion dans le
   temps.

**Décisions du 31/10 :**

- **l'entrée, c'est la validation des commandes entrantes**. Une commande compte comme
  entrée le jour où le magasin valide sa réception au panel, avec les quantités
  validées. Une commande livrée mais pas validée n'est pas une entrée ;
- **seuils de fuite : 3 % / 6 %** (vert, orange, rouge au-delà) ;
- **un inventaire matière chaque semaine** dans chaque magasin : l'écart matière se lit
  d'un inventaire à l'autre, sur sept jours ;
- **la liste des recettes à corriger est pour le CEO seul** : pas d'envoi ;
- **pas d'alerte pour l'instant**.

## 2. Les écrans

Une section **Vérification** dans le rail, entre Magasins et Produits. Cinq onglets et
une bascule, toujours visible :

| Onglet | Question | Maquette |
|---|---|---|
| Audit des flux | Ce qui est entré, produit, vendu, jeté, gardé — et le reste ? (jour · semaine · mois) | `a`, `a-jour`, `b` |
| Fuites par magasin | Dans quel magasin, quelle catégorie, quel produit ou quelle matière ça fuit ? | `g` (produits finis), `g-matieres` (matières premières, avec les commandes validées de la semaine) |
| Matières ↔ recettes | La matière consommée est-elle expliquée par les recettes ? | `c` |
| Chiffre d'affaires | La caisse, le P&L, les redevances et la matière disent-ils la même chose ? | `d` |
| Évolution | La gestion s'améliore-t-elle ? | `f` |
| **Bascule** « Recettes douteuses ou manquantes » | Quelles recettes corriger d'abord ? | `e` |

## 3. Les définitions

### 3.1 Audit des flux (pièces, valorisées au prix de vente HT)

Pour un magasin et un jour :

- **Gardé d'hier** = la clôture de la veille (ce qui se garde pour le lendemain) ;
- **Entré** = les **commandes entrantes validées à la réception**, au jour de leur
  validation. Pour les pièces, ce sont les produits finis de ces commandes (centrale :
  baguettes…). Pour la matière, ce sont toutes leurs lignes validées, valorisées au prix
  du magasin ;
- **Produit** = les cuissons validées en production (sinon le plan, marqué « estimé ») ;
- **Disponible** = gardé d'hier + entré + produit = 100 % ;
- **Vendu** (tickets, commandes comprises), **Poubelle** (déclarée en caisse),
  **Invendu** (clôture du soir, gardé pour demain) ;
- **Non expliqué** = disponible − vendu − poubelle − invendu.

À la semaine et au mois : somme des journées ÷ somme des disponibles (le taux d'une
journée moyenne). **Produit ÷ entré** (matière au coût) ne se lit pas sur un seul jour
(environ trois commandes validées par semaine), mais sur sept jours glissants, ou mieux
entre deux inventaires.

### 3.2 Fuites par magasin (même structure pour chaque magasin)

Par catégorie (dépliable en produits), sur la période :

```
Produits finis (pièces) :
Stock début + Entrées (commandes validées) + Produit (cuissons validées) − Stock fin = Sorti
Sorti − Ventes − Poubelle déclarée                                              = Delta (la fuite)

Matières premières (au coût) :
Stock début + Entrées (commandes validées) − Stock fin                          = Sorti (consommé)
Sorti − Ventes × recettes − Poubelle × recettes                                 = Delta

Delta % = Delta ÷ Sorti        Delta € = Delta × prix (de vente HT pour les pièces, au coût pour les matières)
```

Les lignes sont **les mêmes et dans le même ordre** pour tous les magasins (le catalogue
du réseau), pour qu'une ligne rouge se compare d'un coup d'œil. Une matrice catégories ×
magasins résume le tout en tête. La bascule *Produits finis / Matières premières* passe
au même tableau en matières (`g-matieres`). Le stock y est l'inventaire hebdomadaire
(le lundi avant l'ouverture). Quand un inventaire manque, la photo du soir du stock
matière prend le relais, et l'écran le dit. Les entrées sont les commandes
validées, et l'écran liste, sous la matrice, les commandes de la semaine une par une :
fournisseur, date de commande, date et heure de validation, lignes, valeur, et ce qui a
été corrigé à la réception. Une commande livrée mais pas validée apparaît en rouge. Ses
matières sortent alors en delta négatif, et la ligne le signale.

Sur une semaine, delta de la semaine = somme des « non expliqué » des jours : les deux
onglets se recoupent.

### 3.3 Matières ↔ recettes (d'un inventaire hebdomadaire à l'autre)

```
Consommé réel = stock inventaire début + entrées (commandes validées) − stock inventaire fin
Théorique     = Σ (pièces produites + produits finis reçus) × recette dépliée jusqu'à la matière
Écart         = réel − théorique   (quantité, %, €)
Matière expliquée = théorique ÷ réel
```

Les sous-recettes sont dépliées (4 niveaux au plus, comme `rpApi`), les unités ramenées à
l'unité de base, les portions à leur fraction (`svPortion`). Une tolérance par catégorie
de matière (farines 8 %, laitages 6 %, garnitures 5 % : réglable). Les emballages et
l'hygiène, qui ne sont dans aucune recette, sont suivis à part.

**Attribution de l'écart**, par règle, à confirmer en visite :

- *recette* : la matière entre dans une recette signalée dans la liste ;
- *poubelle non déclarée* : un produit short life sans poubelle déclarée ;
- *hors caisse* : un produit fini reçu, ni vendu ni jeté ;
- *tolérance* : sous le seuil.

### 3.4 Chiffre d'affaires

La caisse fait référence ; chaque autre chiffre se lit en écart :

- **P&L** du panel (somme de `pnl/daily` sur le mois) ;
- **base des redevances** (la facture du mois) ;
- **CA attendu par la matière** = consommé réel ÷ taux matière du magasin (coût de
  recette ÷ prix, pondéré par ses ventes). Il ne vaut que si les recettes sont fiables,
  d'où la couverture affichée à côté.

On montre aussi, à part : les **lignes sans produit** (« divers »), les **annulations
après encaissement**, les **anomalies relevées par le panel**, et les « jours à
regarder » (un créneau sans ticket en plein service, une rafale d'annulations, des
produits reçus sans trace en caisse).

### 3.5 Recettes douteuses ou manquantes (la bascule)

| Règle | Détection |
|---|---|
| Pas de recette | produit vendu sur la période, `id_recipe` vide |
| Recette vide | aucune ligne |
| Matière sans prix | `price_source` MISSING dans un magasin qui vend le produit |
| Coût invraisemblable | coût de la pièce < 5 % ou ≥ 100 % du prix HT (le contrôle `svCoutPlausible` existe) |
| Hors norme du réseau | plus de 40 % d'écart à la médiane des magasins (ex. coût « custom ») |
| Unité suspecte | une ligne pèse plus de la moitié du coût, et ×1000 ou ÷1000 la ramène dans la norme de sa catégorie |
| Rendement absent | `yield_quantity` à 0 ou vide |
| Portion sans fraction | une portion vendue coûte la pièce entière |
| Matière jamais en recette | achetée sur la période, employée par aucune recette |

On classe par **CA concerné sur 30 jours**. On montre la **couverture**, c'est-à-dire la
part du CA qui passe par une recette fiable, avec l'objectif de 100 %. « Repérée depuis »
vient de la base quotidienne ; une ligne disparaît d'elle-même quand la recette est
corrigée au panel. Le bouton « Ouvrir au panel » mène à la recette. **On n'écrit rien au
panel.** La liste est **pour le CEO seul** (31/10) : pas d'envoi, un export suffit.

## 4. Les sources : routes du swagger du panel

Le swagger se trouve à `https://atelierby.tfbuddy.com/api/swagger/openapi.json`. Il est
public et compte 933 routes, dont 763 « runtime routes » **sans contrat documenté** : leur
forme se mesure par sonde avant d'être codée.

### Déjà lues et mesurées par le cockpit

| Donnée | Route | Où dans le code |
|---|---|---|
| Ventes par produit, par jour (lignes de ticket, portions, B2B) | `/shops/{id}/transactions?date=`, puis `/transactions/{id}?include=products` | `svProduitsJour` (gravé `svP{shop}:{jour}`) |
| Ventes par heure, coût matière du panel | `/shops/{id}/statistics/sales/hourly-distribution/{date}` | `stats_ventes.php` |
| Poubelle déclarée, par produit et motif | `/shops/{id}/products/waste` | `invLignes` |
| Produit (cuissons validées), clôture, report, compté | cockpit (`ppFait:`, `pfCloture:`) | `production_flux.php` |
| Recette d'un produit et coût par magasin | `/products/{pid}` → `id_recipe` ; `/shops/{sid}/recipes/{rid}/cost` | `rpApi` |
| Prix des matières par magasin | `/shops/{id}/materials` | `mfMatieresApi` |
| Produits vendus et coût de recette du jour | `/shops/{id}/products/available` | `produits_coef.php` |
| Stock matière courant | `/shops/{id}/material-inventory` | `ep_ventes_stock` |
| P&L | `/consultant/shops/{id}/pnl/daily` (`/pnl/monthly` ne répond pas) | `pnl_mois.php` |
| Factures de redevances | `/admin/royalties/invoices` (réalm admin) | `endpoints.php` (Facturation) |
| Catégories et groupes | `/product-categories`, `/product-category-groups` | `produits_coef.php` |
| Besoins et réquisitions | `/shops/{id}/material-requisitions`, `/list` | Centrale d'achat |
| **Commandes entrantes (l'entrée)** | `GET /deliveries/{id}` rend la commande entière : `order_key`, `id_shop`, `id_supplier`, `order_date`, `expected_date`, `supplier_planned_delivery_date`, `supplier_accepted_at`, `in_transit_sent_at`, **`delivered_on`** (la validation de réception), `supplier_fulfillment_status`. **Aucun montant.** Aucune route ne liste les commandes (`/shops/{id}/orders`, `/orders/materials`, `/orders`, `/material-orders` répondent 404 ORDER_NOT_FOUND en consultant comme en admin) : le cockpit balaie les identifiants récents | `caSuiviCommandes` (`endpoints.php`), `wr_ca_relance` |

### À sonder (dans le swagger, sans contrat)

| Donnée | Routes candidates |
|---|---|
| **Inventaires** (dates, lignes comptées) | `/shops/{id}/materials/stocktakings`, `/stocktakings/{id}`, `/materials/stocktakings/{id}/document` |
| **Lignes validées des commandes** (quantités par matière, commandé ou validé) | les lignes dans `/deliveries/{id}` ? sinon `/material-suppliers/{f}/orders/{o}/final-items` (lignes finales après réception), `/comparison` (commandé face à livré), `/read-model`, `/shops/{id}/orders/{o}/supplier-fulfillment`. La validation elle-même est `POST /orders/{id}/delivery` (le geste du magasin) ; `POST /shops/{id}/manual-open-deliveries` couvre les fournisseurs libres (OPEN) ; `/shops/{id}/supplement-orders` les compléments |
| **Recettes en masse** | `/recipes`, `/recipes/flatten`, `/recipes/{id}`, `/recipes/{id}/cost`, `/shops/{id}/recipes`, `/subrecipes`, `/products/{id}/portions` |
| **Corrections et annulations** | `/shops/{id}/sales-corrections`, `/shops/{id}/reports/sales/original-period-later-corrections`, `/transactions/{id}/corrections` |
| **Anomalies du panel** | `/panel/sales-anomalies`, `/panel/sales-anomalies/{id}` |
| Rapports de ventes, factures B2B | `/shops/{id}/reports/sales/monthly`, `/reports/sales/marge`, `/shops/{id}/invoices/summary` |

La sonde suit le modèle existant (`/exploitation/invendus/sonde`, `/ventes/stats/sonde`) :
lecture seule, GET uniquement, et le contrat mesuré est noté dans `docs/contrat-api.md`.

## 5. La base quotidienne

On n'enregistre que des **agrégats calculés par le cockpit**, jamais une copie des
sources, comme le font déjà `pvMois` et `svP`. Tables créées par `installer.php`
(`CREATE TABLE IF NOT EXISTS`) :

```sql
-- Un magasin, un jour : l'audit des flux et les indicateurs du jour.
CREATE TABLE IF NOT EXISTS ceo_verif_jour (
  shop_id INT NOT NULL, jour DATE NOT NULL,
  garde_eur DECIMAL(10,2), entre_eur DECIMAL(10,2), entre_cout DECIMAL(10,2),
  produit_eur DECIMAL(10,2), produit_cout DECIMAL(10,2), produit_estime TINYINT(1) DEFAULT 0,
  vendu_eur DECIMAL(10,2), poubelle_eur DECIMAL(10,2), invendu_eur DECIMAL(10,2), nonexpl_eur DECIMAL(10,2),
  ca_caisse DECIMAL(10,2), lignes_sans_produit_eur DECIMAL(10,2), annulations_eur DECIMAL(10,2),
  recettes_fiables_pct DECIMAL(5,2), recettes_douteuses INT,
  stock_matiere_cout DECIMAL(10,2),           -- photo du soir de /material-inventory, au coût
  calcule_le DATETIME NOT NULL, version SMALLINT NOT NULL DEFAULT 1,
  PRIMARY KEY (shop_id, jour)
);
-- Un magasin, un jour, un produit : la matière des tableaux « Fuites » (catégorie → produit).
CREATE TABLE IF NOT EXISTS ceo_verif_produit (
  shop_id INT NOT NULL, jour DATE NOT NULL, product_id INT NOT NULL, categorie VARCHAR(120),
  garde DECIMAL(9,2), recu DECIMAL(9,2), produit DECIMAL(9,2), vendu DECIMAL(9,2),
  poubelle DECIMAL(9,2), invendu DECIMAL(9,2), prix_ht DECIMAL(8,3), cout DECIMAL(8,4),
  PRIMARY KEY (shop_id, jour, product_id), KEY (jour)
);
-- Une commande entrante validée : l'entrée, ligne par ligne (la source ne garde pas de montant).
CREATE TABLE IF NOT EXISTS ceo_verif_commande (
  delivery_id INT NOT NULL PRIMARY KEY, order_key VARCHAR(40), shop_id INT NOT NULL, supplier_id INT,
  commandee_le DATE, livree_le DATE NULL, validee_le DATETIME NULL,   -- delivered_on
  lignes JSON,                                -- [{material_id, unite, qte_commandee, qte_validee, prix_magasin}]
  valeur DECIMAL(10,2),                       -- Σ qte_validee × prix du magasin au jour de la validation
  corrigee TINYINT(1) DEFAULT 0, vu_le DATETIME NOT NULL,
  KEY (shop_id, validee_le)
);
-- Une matière entre deux inventaires (onglet Matières).
CREATE TABLE IF NOT EXISTS ceo_verif_matiere (
  shop_id INT NOT NULL, inv_debut DATE NOT NULL, inv_fin DATE NOT NULL, material_id INT NOT NULL,
  stock_debut DECIMAL(12,3), entree DECIMAL(12,3), stock_fin DECIMAL(12,3), theorique DECIMAL(12,3),
  unite VARCHAR(16), prix_unite DECIMAL(10,4), cause VARCHAR(24),
  PRIMARY KEY (shop_id, inv_debut, inv_fin, material_id)
);
-- Le registre des recettes douteuses : quand repérée, quand résolue.
CREATE TABLE IF NOT EXISTS ceo_verif_recette (
  cle VARCHAR(80) NOT NULL,                   -- règle:produit (ou règle:matière)
  regle VARCHAR(24) NOT NULL, product_id INT NULL, material_id INT NULL,
  detail VARCHAR(400), ca_30j DECIMAL(10,2), magasins VARCHAR(120),
  premier_vu DATE NOT NULL, dernier_vu DATE NOT NULL, resolu_le DATE NULL,
  PRIMARY KEY (cle)
);
```

Volume : environ 70 produits × 4 magasins × 365 jours, soit 100 000 lignes par an dans
`ceo_verif_produit`, ce qui reste modeste.

**Calcul de nuit** : `GET /api/cockpit/verification/cron?jeton=…`, ajouté au fichier
`/etc/cron.d/cockpit-rapports` posé par `deploy.sh`, à **4 h 30** :

1. il balaie les commandes (`/deliveries/{id}`, comme le suivi des achats) et enregistre
   celles validées depuis le dernier passage dans `ceo_verif_commande`, avec leurs lignes
   valorisées au prix du magasin. Ces lignes font l'entrée ;
2. il calcule la veille de chaque magasin et l'écrit (upsert) ;
3. il **recalcule les sept jours d'avant**, pour absorber les corrections tardives, les
   cuissons validées après coup et les commandes validées en retard ;
4. il prend la photo du soir de `/material-inventory` (le stock matière jour par jour,
   même sans inventaire formel) ;
5. il passe les règles de recettes et met à jour `ceo_verif_recette` (premier vu,
   dernier vu, résolu) ;
6. il écrit une ligne au journal (`journalAdd`).

Il garde le budget de lecture des tickets (`SV_BUDGET_CRON`) : la moisson horaire a déjà
lu la plupart des tickets dans la journée. **Rien ne s'efface** : une correction de
recette se voit comme une amélioration, à partir du jour où elle a eu lieu.

## 6. Ce qu'il faut coder

| Pièce | Fichier | Contenu |
|---|---|---|
| Calculs et lectures | `src/verification.php` (nouveau) | `vfFluxJour`, `vfFuites`, `vfMatieres`, `vfCa`, `vfRecettes`, `vfEvolution`, la sonde |
| Calcul de nuit | `src/verification.php` + `bin/verif_jour.php` | cron, recalcul d'une plage (admin) |
| Tables | `src/installer.php` | les quatre `CREATE TABLE` |
| Routes | `public/api/index.php` | `GET /verification/flux?vue=jour\|semaine\|mois&date=&shop=` · `GET /verification/fuites?vue=&date=&shop=&niveau=produits\|matieres` · `GET /verification/matieres?shop=&du=&au=` · `GET /verification/ca?mois=` · `GET /verification/recettes?shop=&regle=` · `GET /verification/evolution?semaines=12&indicateur=` · `GET /verification/sonde?q=` · `GET /verification/cron?jeton=` · `POST /verification/recalculer` |
| Réglages | `PUT /parametres/verifSeuils` | seuils (§ 7) |
| Écran | `public/assets/js/app.js`, `templates.js` | section du rail, cinq onglets, bascule, tableaux, graphiques (CSS de `vf.css` repris dans `app.css`) |
| Contrat | `docs/contrat-api.md` | une section par route, avec les mesures de la sonde |
| Tests | `bin/tests.php` | formules (flux, delta, consommé réel), règles de recettes sur des cas connus |

## 7. Seuils (Paramètres, réglables ; 3 % / 6 % confirmés le 31/10)

| Indicateur | Vert | Orange | Rouge |
|---|---|---|---|
| Non expliqué / delta (% du disponible ou du sorti) | ≤ 3 % | ≤ 6 % | > 6 % |
| Poubelle | ≤ 6 % | ≤ 9 % | > 9 % |
| Invendu gardé | ≤ 8 % | ≤ 12 % | > 12 % |
| Produit ÷ entré (matière) | ≥ 92 % | ≥ 85 % | < 85 % |
| Recettes fiables (% du CA) | ≥ 97 % | ≥ 90 % | < 90 % |
| Écart de CA (P&L, redevances, attendu) | ≤ 1 % | ≤ 4 % | > 4 % |
| Écart matière | selon la tolérance de la catégorie de matière | | |

## 8. Les étapes

| Lot | Contenu | Dépend de | Taille |
|---|---|---|---|
| **0. Sonde** | Mesurer les routes « à sonder » (§ 4), écrire le contrat | compte panel | S |
| **1. Recettes à corriger** | La bascule et ses neuf règles, la couverture, le registre | routes déjà connues | M |
| **2. Audit des flux + Fuites (produits finis)** | Jour, semaine, mois ; un tableau par magasin ; la matrice | données déjà lues | M |
| **3. Base quotidienne + Évolution** | Tables, cron 4 h 30, recalcul sur 7 jours, onglet Évolution | lot 2 | M |
| **4. Matières ↔ recettes** | Inventaires, livraisons, recettes dépliées, tolérances, attribution ; Fuites en matières | lots 0 et 1 | L |
| **5. Chiffre d'affaires** | Caisse, P&L, redevances, CA attendu, lignes sans produit, annulations, anomalies | lots 0 et 4 | M |
| ~~6. Alertes~~ | **Pas pour l'instant (31/10).** Plus tard : un magasin rouge deux jours de suite, une commande livrée non validée depuis 24 h | lot 3 | S |

L'ordre est choisi pour que **les recettes passent d'abord** : tant qu'une recette est
fausse, l'écart matière et le CA attendu le sont aussi.

## 9. Risques et limites

- **Production réelle** : le panel n'a pas de route (mesuré le 04/10 : 26 routes
  sondées). Le produit vient des cuissons validées dans le cockpit, sinon du plan,
  marqué « estimé ». Un magasin qui ne valide pas ses cuissons a un audit moins sûr :
  l'écran le dit, et le compté de la clôture aide.
- **Report (carryover)** : aucune route de lecture. On le prend dans la clôture du
  cockpit (`pfCloture`).
- **Commandes entrantes** : elles se lisent une par une (`/deliveries/{id}`, aucune route
  de liste) et sans montant. La valeur vient donc du prix du magasin. Le lot 0 doit
  trouver où se lisent **les quantités validées ligne par ligne** : dans
  `/deliveries/{id}`, ou dans `final-items` / `comparison`. Sans elles, l'entrée d'une
  commande validée serait sa quantité commandée, ce qui est moins juste quand la
  réception a été corrigée.
- **Commande livrée mais pas validée** : elle n'entre pas, et ses matières sortent en delta
  négatif. L'écran le signale en rouge, sinon le magasin aurait intérêt à ne pas valider. Pas
  d'alerte pour l'instant (31/10) : on le lit à l'écran.
- **Inventaires** : un par semaine dans chaque magasin (31/10). Quand un inventaire
  manque, ou qu'une matière n'est comptée qu'une fois, la photo du soir de
  `/material-inventory` prend le relais, et l'écran le dit. Le lot 0 vérifie que les
  inventaires se lisent bien par `/shops/{id}/materials/stocktakings`.
- **Recettes** : elles faussent tout le reste, d'où le lot 1 en premier et la
  couverture affichée partout.
- **Charge du panel** : lecture de nuit, sous le budget existant ; le jour, on ne relit
  que la journée en cours.
- **Personnes** : aucun nom d'employé dans ces écrans. On dit « poste de caisse », pas
  qui.

## 10. Questions — toutes réglées le 31/10

1. L'entrée : **la validation des commandes entrantes**.
2. Les seuils de fuite : **3 % / 6 %**.
3. Les inventaires : **chaque semaine**.
4. La liste des recettes à corriger : **pour le CEO seul**.
5. Les alertes : **pas pour l'instant**.

Reste à mesurer, au lot 0 : les quantités validées ligne par ligne des commandes, et la
lecture des inventaires hebdomadaires.
