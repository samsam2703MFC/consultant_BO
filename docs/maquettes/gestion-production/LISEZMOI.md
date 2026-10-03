# Gestion de production — trois maquettes

> **Codé** (03/10/2026) : serveur `src/production_plan.php` (`GET /production/plan`,
> `POST /production/plan/params`, `POST /production/plan/fait` — voir `docs/contrat-api.md`),
> dashboard magasin, onglet **Production** (ordinateur seulement : pas d'écran au
> téléphone, demande du 03/10). Les trois écrans des maquettes sont les trois
> sous-onglets ; les chiffres viennent du panel, plus rien d'illustré.

Demande du 03/10/2026 : un module de **gestion de production**, à transférer
côté franchisé. Le process :

1. paramétrer les cuissons (les périodes de vente de l'API du panel) ;
2. paramétrer le % de la journée produit à chaque cuisson (50 % à la première) ;
3. paramétrer quelles catégories se cuisent à quelles cuissons
   (viennoiserie en 1 et 2, biscuiterie en 3 et 4…) ;
4. gérer les recuissons à chaque cuisson : commandes, webshop, et la moyenne
   des ventes des 6 dernières semaines, heure par heure.

| | Maquette | Fichiers |
|---|---|---|
| A | **Paramètres** : les quatre cuissons (périodes de vente du panel + une 4e locale), le % par cuisson, la matrice catégories × cuissons (pièces par plaque, dernière recuisson), les règles (6 semaines, + 10 % de sécurité, arrondi à la plaque, seuil de recuisson à 80 %, commandes et webshop, stock visé en fin de journée) | `a.html`, `a.jpg` |
| B | **Plan du jour** : une tuile par cuisson (four, pièces, plaques), puis cuisson par cuisson le détail par produit — prévision heure par heure, vendredi moyen, part de la cuisson, + 10 %, commandes, webshop, stock estimé, à cuire, plaques — et les commandes du jour rangées dans leur cuisson | `b.html`, `b.jpg` |
| C | **Suivi et recuissons** : le recalcul de 10:00 avant le four de la cuisson 2 — vendu face au prévu, stock en vitrine, besoin jusqu'à la cuisson suivante, ce qu'il faut enfourner face au plan de la nuit, verdict par produit (recuire, tient, trop produit, trop tard) | `c.html`, `c.jpg` |
| | La planche : les trois, ce que ça apporte, les limites | `planche.html`, `planche.jpg` |

## Ce que le panel donne (mesuré le 03/10/2026)

- **Les périodes de vente** : `GET /admin/sales-dayparts` — trois, Matin
  06:00–11:00, Midi 11:00–13:00, Après-midi 14:00–19:00. Le créneau 13–14 h
  n'est dans aucune. Aucune 4e période : elle est locale tant qu'elle n'est pas
  créée dans le panel.
- **Les ventes heure par heure, par produit** : les tickets du panel, déjà
  gravés jour par jour par le module des ventes (`svP{shop}:{jour}`) — la
  base de la prévision (moyenne des 6 derniers mêmes jours).
- **Les catégories et leurs groupes** : le catalogue du panel (81 catégories,
  12 groupes).
- **Les commandes** : `GET /shops/{id}/client-orders` (heure de retrait, canal,
  webshop ou non). Les articles ne sont pas toujours joints à la liste.
- **Ce qui manque** : `GET /shops/{id}/statistics/production-planning` existe
  mais ne rend que la journée entière (`distribution_mode: whole_day`, aucun
  autre mode accepté) ; les reports de la veille (carryover) n'ont pas de route
  de lecture ; les types de préparation (`/preparation-types` : mise en place,
  cuisson, dorure…) existent sans quantités.

## Réel et illustré

**Réel** : Halle (shop 4), septembre 2026 — les quantités par heure des
catégories de tête, les dix produits de la viennoiserie et leurs parts
(`reel-halle.json`, lu sur `/ventes/stats`), les trois périodes de vente du
panel, les trois commandes du 02/10. **Illustré** : la 4e cuisson, les
pourcentages, les pièces par plaque, les articles des commandes, les ventes du
matin du suivi et la majoration du vendredi (+ 8 % sur la moyenne du mois, là où
le module prendra les 6 derniers vendredis).

    node docs/maquettes/gestion-production/generer.js   (serveur statique sur 8099 depuis la racine)
