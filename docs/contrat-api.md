# Cockpit CEO — contrat API et mapping base de données

Le HTML ne contient plus aucune donnée métier. Tout passe par `api.js`, qui appelle
un endpoint par écran, normalise la réponse, et l'expose au composant. Si l'API ne
répond pas, `data.js` (jeu de démonstration) prend le relais et `p.source` vaut `demo`.

- Base d'URL : `window.COCKPIT_API_BASE`, défaut `/api/cockpit`
- Format : JSON, `Content-Type: application/json`, montants en euros (nombres, pas de chaînes)
- Dates : `YYYY-MM-DD`. Pourcentages : nombres en points (`32` = 32 %), sauf `margePct` (ratio 0–1)
- Granularité des réels : **mensuelle**
- Timeout client : 4 s, les 19 endpoints sont appelés en parallèle

---

## `GET /carte-sources` et `POST /connecteurs/{code}/test`

**La carte** dit d'où vient ce que chaque écran affiche. Elle est lue dans le
**code**, pas dans une liste tenue à la main — une liste à la main ment dès la
semaine suivante. `bin/carte_sources.php` découpe chaque fonction `ep_*` et
regarde ce qu'elle appelle (`Db::`, `PanelApi::`, `ErpApi::`, `GoogleApi::`,
`Anthropic::`, `ScoutingOsm::`), puis écrit le résultat dans `ceo_app_setting`
sous `carteSources`. Cron quotidien à 3 h 40, et un passage au déploiement.

Quatre catégories : `base` (la base seule), `api` (une API extérieure seule),
`mixte` (les deux), `calcule` (ni l'une ni l'autre — un calcul pur). La réponse
porte `jours`, l'âge de la carte : une carte de trois semaines ne décrit plus
le code d'aujourd'hui.

**Le test** — `POST /connecteurs/{code}/test` — fait un **vrai appel**, pas une
relecture de réglage : une clé peut être présente et refusée. Le résultat
s'écrit dans `ceo_connecteur` comme n'importe quel geste.

| connecteur | ce que le test fait |
|---|---|
| `panel` | authentification, **puis** lecture de `/shops` — un jeton valide ne prouve pas que les données suivent |
| `erp` | `ErpApi::tester()` |
| `google` | une recherche de lieu, la requête la moins chère de l'API |
| `anthropic` | **aucun appel** : une proposition de note se facture. On dit si la clé est là, rien de plus |

## Les notifications push — `GET /push/cle`, `POST /push/abonnements`, `POST /push/essai`

Le Web Push sans dépendance : PHP 8 + OpenSSL. Deux normes assemblées dans
`src/push.php` — **VAPID** (RFC 8292) pour dire qui envoie, et le **chiffrement
`aes128gcm`** (RFC 8291) pour que le service de push transporte sans lire.

Les clés VAPID vivent dans `ceo_app_setting` sous `pushVapid`, générées à la
première demande. **Les changer invalide tous les abonnements** : le navigateur
lie chaque abonnement à la clé publique qu'on lui a donnée.

- `GET /push/cle` → `{ pret, cle, abonnements }`, ou `{ pret: false, motif }`
  quand PHP n'a pas `openssl_pkey_derive`, `hash_hkdf` ou `aes-128-gcm`.
- `POST /push/abonnements` ← `{ shop, endpoint, p256dh, auth }`. Un même
  appareil qui se réabonne garde son endpoint : la ligne est mise à jour.
- `DELETE /push/abonnements` ← `{ endpoint }`.
- `POST /push/essai` ← `{ shop }` → `{ abonnements, envoyes, retires, erreurs }`.

Table `ceo_push_abonnement` (créée à la volée) : `shop_id`, `endpoint` (unique),
`p256dh`, `auth`, `agent`, `cree_le`, `vu_le`, `echecs`. Un envoi qui rend
**404 ou 410** retire l'abonnement : l'appareil ne reviendra pas.

L'envoi est fait par `bin/push_stock.php`, en cron toutes les quinze minutes de
5 h à 20 h. Il retient dans `ceo_app_setting` (`pushStock:<id>`) la liste des
références en alerte au passage précédent et **ne notifie que les nouvelles** —
sans cela, chaque passage rappellerait les quatre-vingt-quinze ruptures de la
veille. Le premier passage d'un magasin n'envoie rien : il pose l'état.

## `GET /ventes/stock`

L'inventaire matière d'**un** magasin. `/centrale/stock` fait la même lecture
pour tout le réseau et tronque à 600 lignes : sur 2 100 références, un magasin
peut y passer entier à la trappe. Le dashboard d'un magasin a besoin du sien,
complet.

Source : `/shops/{id}/material-inventory` (API panel). Une référence jamais
comptée n'est **pas** « à zéro », elle est **absente** — elle n'est pas rendue.

Une référence est **en alerte** quand son stock est négatif (écart de caisse ou
de comptage) ou sous le minimum journalier : les deux appellent un geste.
`manque` dit de combien il faut recharger pour repasser au minimum. Les alertes
sortent en tête, les plus creuses d'abord.

```json
{
  "shop": "2", "references": 128,
  "alertes": 3, "ruptures": 2, "negatifs": 1,
  "dernierComptage": "2026-09-14 18:22:00", "quand": "2026-09-15T14:02:11+02:00",
  "lignes": [
    { "ref": "Beurre doux 82 %", "categorie": "Matières premières",
      "stock": -2.5, "mini": 12, "unite": "kg", "modif": "2026-09-14 18:22:00",
      "alerte": true, "manque": 14.5 }
  ]
}
```

## `GET /ventes/mensuel`

Le CA mois par mois d'un magasin. Sert la **valeur du magasin** du dashboard.

**Deux sources**, parce qu'aucune n'est complète à elle seule :

| source | ce qu'elle a | ce qui lui manque |
|---|---|---|
| `mac_shop_monthly_pnl` (P&L du panel) | les mois clos jusqu'au dernier | aucune ligne en 2025 |
| `transaction` (ventes de caisse) | 2025 | s'arrête au 14 juillet 2026 |

Le P&L fait foi quand il a le mois — c'est un chiffre arrêté ; la caisse comble
le reste. Chaque mois dit d'où il vient (`source`), et `tickets` / `jours`
viennent toujours de la caisse quand elle a le mois, même si le CA vient du
P&L.

Paramètres : `shop` (requis), `mois` (1 à 60, 24 par défaut).

Le **mois en cours est exclu** : incomplet, il tirerait toute moyenne vers le
bas jusqu'à son dernier jour. Les mois sans rien sont rendus quand même, `ca`
à `null` — un trou doit se voir, pas se combler tout seul.

```json
{
  "shop": "4",
  "du": "2024-09", "au": "2026-08",
  "source": "P&L mensuel du panel quand il a le mois (7), ventes de caisse sinon (0) — mois en cours exclu",
  "mois": [
    { "mois": "2024-09", "ca": null, "source": null, "tickets": 0, "jours": 0 },
    { "mois": "2026-02", "ca": 6506.85, "source": "pnl", "tickets": 612, "jours": 7 }
  ]
}
```

`jours` compte les jours distincts où au moins un ticket a été passé : il dit
qu'un mois à 6 507 € sur 7 jours est un mois d'ouverture et non un mois raté.

## 1. Endpoints

| Clé | Endpoint | Alimente |
|---|---|---|
| `meta` | `GET /meta` | en-tête, marque, utilisateur, exercice, seuils |
| `leviers` | `GET /referentiels/leviers` | pastilles levier, Paramètres |
| `kpis` | `GET /referentiels/kpis` | listes KPI des assistants |
| `emailTemplates` | `GET /referentiels/email-templates` | relances, Paramètres |
| `projTemplates` | `GET /referentiels/ceo_project-templates` | assistant Nouveau projet |
| `stores` | `GET /stores?statut=tous` | tous les écrans magasins |
| `perf` | `GET /stores/perf?granularite=mois&annees=2025,2026` | Tableau, Heatmap, Objectifs, Suivi budget, Marge |
| `budgets` | `GET /stores/budgets?exercice=2026` | Suivi budget magasin, cumul réseau |
| `targets` | `GET /targets` | Objectifs de CA (1/3/5 ans) |
| `consultants` | `GET /consultants` | Tâches consultants, Reporting |
| `suppliers` | `GET /fournisseurs` | Tâches consultants |
| `projects` | `GET /projects` | Projets, Tâches consultants |
| `reporting` | `GET /reporting` | Reporting automatisé |
| `journal` | `GET /journal` | Journal |
| `products` | `GET /products/scoring?periode=AAAA-MM` | Scoring produits |
| — | `GET /scouting` | Scouting commercial : saisies, hypothèses, état du connecteur Google, inventaire du cache OSM ; chaque concurrent porte `statut` (businessStatus Google) et `dernierAvis` (date du plus récent avis rendu par sa fiche) — fermé ou sans avis depuis un an, il est écarté par l’écran |
| — | `GET /scouting/tiles/{secteur}` | Scouting commercial : un secteur du cache OpenStreetMap |
| — | `GET /scouting/reseau` | Scouting commercial : magasins du réseau, position (fiche Google ou pointée) et CA réel des douze derniers mois clos — le calage du modèle ; `panier` : panier moyen TTC du réseau et par magasin (même source que l'écran Exploitation, mois en cours, gardé un jour) |
| — | `GET /scouting/etude?lat=&lng=&r=` | Scouting commercial : l'étude de marché locale d'un point — ce qu'OpenStreetMap sait du rayon `r` (mètres, 500 à 15 000) : entreprises par famille (`ent`), zonings avec emprise et entreprises dedans (`zonings`), écoles (`ecoles`), générateurs de flux (`flux`), concurrence indirecte (`indirecte`). Servi du cache `ceo_scouting_etude` s'il a moins de 45 jours (`cache: true`, `releve`), sinon relevé chez Overpass depuis le serveur (jusqu'à deux minutes) ; `force=1` relit ; un relevé qui échoue rend le cache périmé avec `perime: true`, ou 502 |
| — | `GET /ventes/stats?shop=&vue=&date=` | Les heures de la période, le top de chaque heure, et `categories[]` : CA, coût matière, marge brute, taux, part, `groupe`, et `produits[]` de chaque catégorie (id, nom, q, v, c, m, taux, part dans la catégorie) — la liste groupe › catégorie › produit du dashboard magasin. `bundles` (10/10/2026) : les produits de la catégorie « Bundle », groupe « Bundle & Promotion », vendus sur la période. Il donne `{n, pieces, ca, taux, part, lignes[{id, nom, cat, q, v, prix, c, m, taux, heures{h: q}, premiere, derniere}]}`, trié par quantité. Le dashboard opérationnel l'affiche à côté des ventes par catégorie, sans colonne d'heures, avec la composition lue dans la recette du bundle (`/analyse/produits/recette`). `GET /exploitation/bundles/sonde` lit en lecture seule les promotions bundle et buy X get Y du panel (`/admin/promotions/...`) : au 10/10/2026, le compte API reçoit 403. |
| — | (relevé des tickets `svP{shop}:{date}`) | Lu une fois par jour et par magasin, la journée en cours relue toutes les dix minutes. Depuis le 05/10/2026, la relecture est **incrémentale** : le relevé garde ses tickets (`ids`), la liste du jour se relit (un appel) et seuls les tickets nouveaux se lisent (un appel chacun) ; mesuré avant, 298 tickets relus à Corbais, 8 s. Un ticket déjà lu qui a disparu de la liste (annulé) fait tout relire. `produits.ticketsLus` compte les tickets lus pendant l'appel. |
| `fbRegles` | `GET /referentiels/facebook-regles` | Contrôle posts Facebook — pack de règles |
| `fbPosts` | `GET /facebook/posts` | Contrôle posts Facebook — posts, écarts, décisions |

### `/meta`

```json
{
  "reseau": { "nom": "L'Atelier by", "sousTitre": "Cockpit CEO — Réseau" },
  "utilisateur": { "initiales": "GB", "nom": "G. Baert", "role": "CEO · admin" },
  "aujourdhui": "2026-07-31",
  "dateLabel": "Vendredi 31 juillet 2026",
  "periodeLabel": "Données : juillet 2026",
  "exercice": 2026,
  "moisLabels": ["Jan","Fév","Mar","Avr","Mai","Juin","Juil","Août","Sep","Oct","Nov","Déc"],
  "seuils": { "food": 32, "labour": 33, "overhead": 13.5, "royalties": 3, "financieres": 2.2 },
  "contribOuverture": 210000,
  "notes": { "objectifsOuverture": "Dont contribution attendue de l'ouverture de Knokke (oct.–déc.) : env. 210 k€." }
}
```

### `/stores`

```json
[{ "id": "cha", "code": "BXL-CHA", "nom": "Bruxelles — Châtelain", "fr": "M. Lambert",
   "zone": "Bruxelles", "status": "Ouvert", "opened": "2019-03", "valT": 920000, "panier": 11.8 }]
```

`status` ∈ `Ouvert` | `En ouverture` | `Fermé`. Seuls les `Ouvert` entrent dans les cumuls réseau.

### `/stores/perf` — une ligne par magasin et par mois

```json
[{ "storeId": "cha", "annee": 2026, "mois": 7,
   "ca": 96600, "caBudget": 102900, "margeNette": 15200, "margePct": 0.158,
   "tickets": 8181, "panierMoyen": 11.81,
   "foodCostPct": 31.4, "labourCostPct": 32.8, "overheadPct": 13.2, "valorisation": 861000 }]
```

`mois` est 1–12. Un mois non encodé : ligne absente, ou valeurs réelles à `null` avec `caBudget` renseigné.

`caTheoriqueAn` = chiffre d'affaires annuel théorique issu de l'étude de marché du point de vente
(potentiel de la zone de chalandise), indépendant du budget validé avec le franchisé. Le client le
répartit sur les mois au prorata de la saisonnalité du budget et le compare au réel et au budget.
`etudeMarche.date` date la dernière étude ; une étude de plus de 24 mois est signalée comme à
rafraîchir.

### `/stores/budgets` — le budget validé par le consultant

```json
[{ "storeId": "cha", "exercice": 2026, "moisEncodes": 7, "moisTotal": 12,
   "dernierEncodage": "2026-08-04", "panierEngagement": 12.74,
   "caTheoriqueAn": 1620000,
   "etudeMarche": { "date": "2025-09-15", "source": "Étude de marché — zone de chalandise 10 min",
                    "potentielMenages": 4850 },
   "charges": [
     { "poste": "Matières premières", "levier": "food-cost", "pctBudget": 32, "champReel": "food" },
     { "poste": "Rémunérations & charges sociales", "levier": "labour-cost", "pctBudget": 33, "champReel": "labour" },
     { "poste": "Services & biens divers", "levier": "overhead-cost", "pctBudget": 13.5, "champReel": "overhead" },
     { "poste": "Royalties", "levier": "", "pctBudget": 3, "champReel": null },
     { "poste": "Charges financières", "levier": "", "pctBudget": 2.2, "champReel": null }
   ] }]
```

`champReel` désigne le champ de `/stores/perf` qui porte le réel du poste ; `null` = le poste
suit le CA au pourcentage budgété. L'ordre du tableau `charges` est l'ordre affiché.

### `/stores/budget-notes` — les annotations mensuelles d'un exercice

`GET /stores/budget-notes?shop=cha&exercice=2026`

```json
{ "shop": "cha", "exercice": 2026,
  "auteurs": ["franchise", "consultant", "marque"],
  "mois": { "6": { "franchise":  { "texte": "Chantier de voirie devant la boutique tout le mois.",
                                   "par": "M. Dupuis", "le": "2026-07-02 09:14:00" },
                   "consultant": { "texte": "…", "par": "S. Verhoeven", "le": "2026-07-08 16:40:00" } } } }
```

Le tableau du suivi budget dit CE QUI s'est passé, jamais POURQUOI : un mois à −24 % se lit
autrement selon qu'on tenait la caisse, qu'on est passé en visite ou qu'on a lancé la campagne.
D'où **trois voix par mois**, une par intervenant, et une impression qui les emporte.

`mois` est un objet indexé par numéro de mois (`"1"` … `"12"`) ; **les mois sans annotation sont
absents**, et une annotation vide n'est jamais rendue — un texte vide est une annotation effacée,
pas une annotation blanche. `par` et `le` peuvent valoir `null` sur une ligne écrite avant que
l'auteur ne soit connu.

Écriture : `POST /stores/budget-note` avec `{ shop, exercice, mois, auteur, texte, par }`.
`auteur` ∈ `franchise | consultant | marque`, `mois` ∈ 1–12, `texte` plafonné à 2 000 caractères ;
**un texte vide SUPPRIME la ligne**. Rend `{ ok: true, texte, par, le }`. Le serveur écrit
`ceo_shop_month_note` (clé `shop_id, year, month, auteur`) et crée la table au premier appel si
elle manque — une installation qui n'a pas repassé `schema.sql` répond donc normalement.

### `/exploitation/notes` — la note du jour d'un magasin

`GET /exploitation/notes?shop=2&date=2026-09-28`

```json
{ "shop": "2", "jour": "2026-09-28",
  "note": { "jour": "2026-09-28", "texte": "Kermesse de l'école en face, file dès 7 h.", "par": "Sophie", "le": "2026-09-28 13:05:00" },
  "semaine": { "du": "2026-09-28", "au": "2026-10-04", "notes": [ { "jour": "2026-09-28", "texte": "…", "par": "Sophie", "le": "…" } ] },
  "n1":      { "du": "2025-09-29", "au": "2025-10-05", "notes": [ { "jour": "2025-10-03", "texte": "Four 2 en panne toute la matinée.", "par": null, "le": "…" } ] } }
```

Le dashboard journalier dit ce qui s'est passé en chiffres ; la note dit pourquoi. `semaine` est
la semaine du lundi au dimanche qui contient `date`, avec toutes ses notes (celle du jour comprise) ;
`n1` est la même semaine **décalée de 364 jours** — le même jour de semaine, comme les ventes face
au N-1 partout ailleurs. `note` vaut `null` sans note ce jour-là. `par` est un prénom libre, `null`
si la note n'est pas signée.

Écriture : `POST /exploitation/note` avec `{ shop, jour, texte, par }`. Une note par magasin et par
jour ; `texte` plafonné à 2 000 caractères, `par` à 120 ; **un texte vide SUPPRIME la note**. Rend
`{ ok: true, note: { jour, texte, par, le } }` (ou `{ ok: true, vide: true }`). Le serveur écrit
`ceo_shop_day_note` (clé `shop_id, jour`) et crée la table au premier appel si elle manque.

### `/marketing/objectifs-produits` — l'objectif en pièces d'une campagne, magasin par magasin

`GET /marketing/objectifs-produits?campagne=12&date=2026-10-19` (sans `campagne` : celle en cours à la date, sinon la
dernière commencée ; `date` sert aux tests, aujourd'hui par défaut)

```json
{ "date": "2026-10-19", "campagnes": [ { "id": 12, "nom": "Tartes aux pommes", "debut": "2026-10-01", "fin": "2026-10-31", "statut": "En cours" } ],
  "campagne": { "id": 12, "nom": "…", "debut": "…", "fin": "…" },
  "produits": [ { "id": 1043, "nom": "Pommes Tranches", "categorie": "Tartes" } ],
  "magasins": [ { "id": "5", "nom": "Atelier by Harmonie - Sombreffe",
                  "clientsMois": { "clients": 4312, "periode": "septembre 2026", "source": "mois" },
                  "objectif": 500,
                  "bilan": { "objectif": 500, "vendu": 214, "ceJour": 9, "pct": 42.8, "attendu": 59.3, "etat": "retard",
                             "reste": 286, "ilFaut": 26, "rythme": 13.2, "projection": 359,
                             "jours": { "ouverts": 27, "passes": 16, "restants": 11, "calendrier": 31 },
                             "parProduit": [ { "id": 1043, "nom": "Pommes Tranches", "q": 96 } ],
                             "parJour": { "2026-10-01": 12 }, "manquants": 0, "aSuivre": false } } ],
  "reseau": { "objectif": 2150, "vendu": 1320, "ceJour": 60, "pct": 61.4, "attendu": 59.3, "etat": "avance", "rythme": 82,
              "ilFaut": 75.5, "projection": 2222, "enRetard": 2, "avecObjectif": 4,
              "parProduit": [ … ], "cumul": [ { "date": "2026-10-01", "jour": 88, "cumul": 88 } ] } }
```

Les ventes sont les **tickets gravés jour par jour** pour le dashboard (`svP…`) : un produit compte s'il est dans
la liste de la campagne, quel que soit le magasin. `attendu` est linéaire sur les **jours ouverts** (un jour passé
est ouvert s'il a vendu ; un jour à venir suit le rythme de semaine des quatre dernières). `etat` ∈ `sans`
(pas d'objectif) · `atteint` · `avance` (≥ attendu) · `clous` (attendu − 8 points) · `retard`. `rythme` : moyenne
des sept derniers jours ouverts clos ; `projection` = vendu + rythme × jours restants ; `ilFaut` = reste ÷ jours
restants. `clientsMois` est une information pour poser l'objectif : le dernier mois clos encodé, sinon les trente
derniers jours gravés ; `null` si rien n'est connu. `magasins` liste **tous les magasins actifs** ;
`horsPerimetre: true` marque ceux que le module marketing n'a pas mis dans la campagne — l'objectif s'y
pose quand même, et c'est l'objectif posé qui fait foi pour le dashboard.

`GET /marketing/catalogue?q=pomme` — la recherche du multiselect : `{ q, mois, produits: [ { id, nom, categorie, volume } ] }`,
`volume` étant ce que le réseau a vendu le dernier mois clos (tranches déjà gravées ; 0 si inconnu). Deux
caractères au moins ; 80 résultats au plus, par catégorie puis volume.

Écriture : `PUT /marketing/campagnes/{id}/produits` avec `{ produits: [ { id, nom, categorie } ], objectifs: { "5": 500, "3": "" } }`.
Chaque clé absente laisse l'autre intacte ; **la liste des produits remplace la précédente** ; un objectif vide ou
nul **efface** la ligne. `?journal=0` pour les écritures automatiques. Tables `mar_campagne_produit`
(clé `campagne_id, product_id`) et `mar_campagne_objectif_produit` (clé `campagne_id, shop_id`), créées au premier appel.

`GET /exploitation/objectifs-produits?shop=5&date=2026-10-19` — la jauge du dashboard magasin : `{ shop, date,
campagnes: [ { id, nom, debut, fin, produits, …bilan } ] }`, une entrée par campagne en cours ce jour-là où le
magasin a un objectif et la campagne des produits ; vide sinon. Même `bilan` que ci-dessus, sans `parJour`.

### `/creux` et `/promo` — les jours creux : la carte, le catalogue des mécaniques, les promotions

`GET /creux?shop=5&semaines=4` (`jusqua=YYYY-MM-DD` pour les tests) — la carte jour de semaine × heure d'un magasin
sur ses N dernières semaines pleines (lundi → dimanche), lue dans les heures gravées (`svH…`) :

```json
{ "shop": "5", "nom": "Atelier by Harmonie - Sombreffe", "semaines": 4, "du": "2026-08-31", "au": "2026-09-27", "joursLus": 24,
  "ouverts": { "2": true, "3": true }, "moyenne": 188.8, "seuil": 0.7, "heures": [6, 7, …, 18],
  "cells": { "2:14": { "ca": 74.1, "tk": 7.2, "n": 4 } },
  "blocs": [ { "jours": [2, 3, 4, 5], "heures": [14, 15, 16, 17], "heureDe": 14, "heureA": 17, "nom": "Mardi, Mercredi, Jeudi, Vendredi, 14 – 18 h",
               "caH": 80.6, "tkH": 8.1, "panier": 10.0, "part": 43, "potentiel": 3542, "cases": 16 } ],
  "midi": { "tkH": 18.2, "panier": 15.8 },
  "reseau": [ { "id": "4", "nom": "Atelier by - Halle", "moyenne": 187.2, "bloc": { … } } ],
  "sections": { "matin": "Matin (avant 11 h)", … }, "leviers": { "trafic": { "nom": "Trafic", "quoi": "…", "kpi": "…" }, … } }
```

Une case est la moyenne des jours ouverts de ce jour de semaine (un jour compte s'il a vendu). `blocs` : les creux
détectés (en semaine si au moins trois jours sont sous le seuil, samedi, dimanche ; heures 8 – 17 contiguës sous
`seuil × moyenne`). `potentiel` = ce que rapporterait par mois le créneau remonté à 70 % de la moyenne du magasin
(× 4,3 semaines). `reseau` : le creux principal de chaque magasin actif, sur sa propre moyenne.

`GET /promo/mecaniques` — le catalogue, semé depuis `src/data/promo_mecaniques.json` (24 fiches) si la table est vide :
`{ mecaniques: [ { id, code, levier, type, nom, regle, habitude, sections: ["apres-midi"], jours: ["lun", …],
declencheur: ["c:Tartes"], article: ["p:1045|Pommes Crème"], offre, prix, remisePct, margeMin, mesure, canaux: [],
caisse, note, margeGarde, actif, ordre, majLe, majPar, enCours } ], leviers, sections, jours, types, offres, canaux }`.
`enCours` : combien de promotions en cours s'en servent. Les sélecteurs : `g:Nom` (groupe), `c:Nom` (catégorie),
`p:<id>|<libellé>` (produit) — la partie après `|` n'est qu'un libellé.

Écritures du catalogue : `POST /promo/mecaniques` (création, corps = la fiche), `PUT /promo/mecaniques/{id}` (`?journal=0`
pour ne pas journaliser), `POST /promo/mecaniques/{id}/dupliquer` → `{ ok, mecanique }`, `DELETE /promo/mecaniques/{id}`
(**409** si une promotion en cours s'en sert : on désactive à la place), `PUT /promo/mecaniques/ordre { ids: [] }`.

`GET /promo/recherche?q=pom` — la barre de recherche des multiselects : `{ q, resultats: [ { sel, nom, type, info } ] }`,
`type` ∈ groupe · catégorie · produit, groupes et catégories d'abord. Deux caractères au moins.

`GET /promo/propositions?shop=5&levier=panier&jours=2,3,4,5&hde=14&ha=17` — les mécaniques actives du levier qui
s'appliquent aux sections du créneau, chiffrées pour le magasin sur ses tickets gravés (`svP…`) des quatre dernières
semaines, mêmes jours, mêmes heures :

```json
{ "fenetre": { "du": "2026-08-31", "au": "2026-09-27", "joursLus": 16, "manquants": 0 }, "ticketsLus": 1600,
  "propositions": [ { "mecanique": { … }, "jourOk": true,
      "article": { "parJour": 28, "caParJour": 61.4, "refs": 1, "margePct": 78.3, "prixMoyen": 2.4 },
      "declencheur": { "parJour": 3, "refs": 1 },
      "chiffres": { "prix": 6.5, "remisePct": 14, "margeApres": 78.3, "margeEur": 5.1, "seuilParJour": 5.8, "margeGardee": true },
      "attache": { "taux": 41.2, "mois": "août 2026" } } ] }
```

`margePct` vient des coûts de recette gravés avec les tickets (null si inconnus) ; `seuilParJour` : les ventes en plus
qu'il faut chaque jour pour payer la remise sur ce qui se vend déjà ; `attache` (si le module croisements est là) :
la part des tickets du déclencheur qui contiennent déjà l'article, le dernier mois clos, sur la section du créneau.

`GET /promo?shop=5&date=2026-09-28` (sans `shop` : tout le réseau) — les promotions et leur effet :
`{ promos: [ { id, shop, magasin, mecaniqueId, levier, type, nom, regle, jours: [2, 3], heureDe, heureA, du, au,
declencheur, article, offre, prix, remisePct, canaux, note, cible, ref: { caH, tkH, jours }, statut, active,
effet: { caH, tkH, joursLus, deltaCaPct, deltaTkPct, parJour: { "2026-09-15": { caH, tkH } }, verdict, verdictLib } } ], leviers }`.
La **référence est gelée à l'adoption** : les quatre semaines d'avant `du`, mêmes jours, mêmes heures. `effet` compare
le créneau depuis `du` à cette référence ; `verdict` ∈ `tot` (moins de 5 jours lus) · `garder` (ΔCA ≥ 8 %) · `ajuster`
(≥ −3 %) · `arreter`. `statut` ∈ brouillon · en_cours · terminee · arretee.

`POST /promo` — adopter : `{ shop, mecaniqueId, nom, levier, type, regle, jours: [2, 3, 4, 5], heureDe: 14, heureA: 17,
du, au, declencheur: [], article: [], offre, prix, remisePct, canaux: [], note, cible, statut }` → `{ ok, promo }` (avec sa
référence calculée). `PUT /promo/{id}` change `statut`, `note`, `cible`, `canaux`, `nom`, `regle`, `au` ;
`DELETE /promo/{id}` n'efface qu'un brouillon. Tables `ceo_promo_mecanique` et `ceo_promo`, créées au premier appel.

`GET /exploitation/promos?shop=5&date=2026-09-29` — la carte du dashboard magasin : `{ shop, date, promos: [ { …,
ceJour, effet, creneau } ] }`, les promotions en cours dont la période couvre la date ; `ceJour` dit si ce jour de
semaine est un jour du créneau.

### `/exploitation/pro` — les clients pro (B2B) du dashboard magasin

`GET /exploitation/pro?shop=5&date=2026-09-29` — ce que les tickets du panel disent des ventes aux sociétés. Un ticket
porte `is_client_b2b`, `will_be_invoiced`, `deferral_payment` : rien d'autre que l'API du panel (ni la table client, ni
le webshop). Un ticket sans client pro est une vente comptoir. **Aucun nom de compte ne sort** : le panel range sous
« société » des noms de personnes, l'écran ne montre que l'heure et le montant de chaque ticket pro (demande du
03/10/2026). Le relevé ne garde qu'une clé de compte (`c{id_client}` quand le ticket porte l'identifiant, sinon
`h{empreinte du nom}`) pour compter les sociétés servies.

```json
{ "shop": 5, "date": "2026-09-29",
  "jour": { "ca": 4828.39, "tickets": 300, "caPro": 269.99, "ticketsPro": 4, "societes": 3, "panierPro": 67.5, "panierComptoir": 15.4,
            "part": 5.6, "aFacturer": 240.82, "differes": 1, "heures": { "8": [111.0, 2] },
            "liste": [ { "heure": "07:13", "montant": 83.4, "facture": true, "differe": false } ] },
  "mois": { "du": "2026-08-31", "au": "2026-09-29", "jours": 26, "manquants": 0, "caPro": 7980.68, "ca": 108619.68, "part": 7.3,
            "ticketsPro": 65, "tickets": 6600, "panierPro": 122.78, "societes": 9, "ticketsSansCompte": 0 },
  "serie": [ { "j": "2026-08-31", "ca": 4120.5, "caPro": 310.2, "tickets": 280, "ticketsPro": 3 } ],
  "appels": 1, "source": "…" }
```

`jour` est `null` si les tickets du jour ne sont pas lus (panel muet). `mois` couvre les 30 jours jusqu'à la date, sur les
jours lus ; `manquants` compte les jours sans lecture, `serie[].ferme` marque un jour sans ticket. `aFacturer` additionne
les tickets pro marqués à facturer ; `differes` compte ceux en paiement différé. `jour.societes` et `mois.societes`
comptent les comptes distincts (par clé, jamais par nom) ; `mois.ticketsSansCompte`, les tickets pro sans client
identifiable. Les jours gravés avant le 03/10/2026 portent encore le nom sous la clé : il ne sort pas, et un jour en
cours se regrave à la lecture suivante.

Le pro d'un jour se lit dans la **liste** des tickets (`/shops/{id}/transactions?date=`, un appel par jour et par magasin)
et se grave sous `b` dans le relevé du jour (`svP{shop}:{date}`), à côté des produits : la moisson des tickets le pose au
passage, le cron complète les jours gravés avant cette lecture (`vpMoisson`, 40 listes par battement), le dashboard en
relit au plus 8 à la demande. Un jour en cours se relit toutes les dix minutes.

**Le split pro / comptoir dans Résultat.** `GET /exploitation/jour` ajoute à chaque magasin ouvert et au réseau
`caPro`, `ticketsPro`, `panierPro`, `caComptoir`, `ticketsComptoir`, `panierComptoir`, `partPro` (et `proMagasins` au
réseau). La liste des tickets du jour part dans le même lot parallèle que le reste, sauf si le relevé `svP` l'a déjà.
Le comptoir est le CA de la ligne moins le pro. `GET /exploitation/periode` (semaine, mois) ajoute les mêmes champs
plus `proJours` (jours ouverts de l'étendue), `proJoursLus` et `proComplet` : le pro additionne les jours lus (relevés
gravés, complétés par au plus 30 listes lues en parallèle, les plus récentes d'abord) ; le comptoir et la part ne sont
rendus que si tous les jours ouverts sont lus. Les champs valent `null` quand rien n'est lu.


### `/production/plan` — la gestion de production (module franchisé)

Demande du 03/10/2026, maquettes `docs/maquettes/gestion-production`. Écran : **cockpit, rail ERP franchisé ›
Gestion de production** (sous-menu : Plan du jour, Suivi et recuissons, Paramètres ; `#/production-plan`,
`#/production-suivi`, `#/production-parametres`), un magasin et un jour choisis dans la barre (‹ › Aujourd'hui,
Demain, jusqu'à sept jours devant). L'écran charge la page du dashboard en mode intégré
(`dashboard/?embed=1&shop=&date=&onglet=plan|suivi|params` : ni entête ni onglets) — la même page servira telle
quelle côté franchisé. Le dashboard magasin n'a plus d'onglet Production (ni Campagne, ni Plan d'action).

Les lignes du plan portent `groupe` (la section : le groupe de la catégorie au panel), `prix` (prix de vente du
magasin, `products/available`, la moyenne du réseau à défaut) et `ca` = pièces à sortir × prix ; chaque cuisson,
`total.ca` et `total.sansPrix`. L'écran range section › catégorie › produit, avec le sous-total de chaque catégorie,
le total de chaque section et le total de la cuisson, en pièces, plaques et CA.

`GET /production/plan?shop=4&date=YYYY-MM-DD` (aujourd'hui par défaut, jusqu'à 7 jours devant) :

```json
{ "shop": 4, "date": "2026-10-03", "jourSemaine": 6,
  "params": { "cuissons": [ { "id": "c1", "nom": "Matin", "de": "06:00", "a": "11:00", "pct": 50, "daypart": 1 } ],
              "regles": { "semaines": 6, "securite": 10, "minPlaques": 1, "seuilRecuisson": 80, "seuilTrop": 140, "avance": 45, "commandes": true, "webshop": true },
              "enregistre": true, "maj": "…" },
  "categories": [ { "cle": "16100", "nom": "Viennoiserie Ind.", "groupe": "Viennoiserie", "parJour": 280, "ventesParCuisson": { "c1": 84, "c2": 11 },
                    "cuissons": ["c1", "c2"], "plaque": 12, "limite": "10:30", "auto": false } ],
  "dayparts": [ { "id": 1, "nom": "Matin", "de": "06:00", "a": "11:00" } ],
  "base": { "semaines": 6, "jours": ["2026-09-26", "…"], "lus": ["…"], "fermes": [], "manquants": [], "piecesParJour": 651 },
  "plan": [ { "id": "c1", "k": 1, "nom": "Matin", "de": "06:00", "a": "11:00", "pct": 50, "panel": true, "four": "05:15",
              "lignes": [ { "pid": 1610006, "nom": "Croissant", "cat": "Viennoiserie Ind.", "prevJ": 65, "h": { "6": 9.1 }, "zone": ["00:00", "11:00"],
                            "part": 66.7, "prevu": 47.7, "cmd": 20, "ws": 0, "stock": 0, "aCuire": 67.7, "plaque": 12, "plaques": 6, "sortie": 72, "fait": null } ],
              "total": { "pieces": 721, "plaques": 74, "prevu": 435, "cmd": 36, "ws": 0, "stock": 0, "categories": 12 } } ],
  "commandes": [ { "heure": "08:30", "canal": "compt", "webshop": false, "montant": 91, "lignes": [ { "pid": 1610006, "nom": "Croissant", "q": 20, "cuisson": "c1" } ], "cuissons": ["c1"], "sansDetail": false } ],
  "suivi": { "maintenant": "10:00", "cuisson": { "id": "c2", "four": "10:15" }, "lignes": [ { "pid": 1610006, "produit": 72, "vendu": 52, "jete": 0, "prevuMaintenant": 48,
             "ecart": 8.3, "stock": 20, "jusqua": "19:00", "besoin": 19, "couverture": 105, "plan": 24, "verdict": "tient", "aEnfourner": 0, "plaques": 0 } ],
             "total": { "aEnfourner": 132, "plaques": 10, "plan": 193, "planPlaques": 16, "ecart": 6.2 } },
  "faits": { "c1": { "1610006": 72 } } }
```

**Le calcul.** La prévision d'un produit = la moyenne, heure par heure, des `semaines` derniers mêmes jours de la
semaine (les tickets du panel gravés par le module des ventes, `svP{shop}:{jour}` ; une portion compte pour sa
fraction de la pièce ; un jour sans ticket est écarté). Pour chaque cuisson cochée d'une catégorie : part = % de la
cuisson ÷ somme des % des cuissons cochées ; à cuire = prévision du jour × part × (1 + sécurité) + commandes (comptoir,
clients pro) + webshop dont le retrait tombe dans sa zone (de l'ouverture de sa période — minuit pour la première —
à l'ouverture de la cuisson cochée suivante) − stock estimé (sorti à la cuisson précédente, ou ce qui a été validé,
− ventes prévues jusqu'à l'ouverture), arrondi à la plaque (au moins `minPlaques`). Four = ouverture − `avance`.

**Le suivi** (le jour même) : avant la prochaine cuisson (la première dont la période n'est pas ouverte), pour chaque
produit concerné : stock = sorti aux cuissons d'avant − vendu (tickets du jour, relus toutes les dix minutes) − jeté
(`/shops/{id}/products/waste`) ; besoin = prévision de maintenant jusqu'à la cuisson suivante de la catégorie (la
fermeture s'il n'y en a plus) × (1 + sécurité) + commandes à retirer d'ici là ; couverture = stock ÷ besoin. Verdict :
`tard` (après la dernière recuisson de la catégorie), `trop` (couverture ≥ `seuilTrop`), `tient` (≥
`seuilRecuisson`), sinon `recuire` : `aEnfourner` = besoin − stock, arrondi à la plaque.

**Les défauts** : les cuissons = les périodes de vente du panel (`/admin/sales-dayparts/active`, six heures en cache) ;
moins de quatre et une dernière période d'au moins quatre heures → elle se coupe en deux, la seconde moitié étant
une cuisson locale ; parts 50 / 25 / 15 / 10. Une catégorie non réglée est cochée pour les cuissons qui captent au
moins 20 % de ses ventes (`auto: true`) ; boissons, épicerie, B2B et bundles : rien. Caches : `ppBase:{shop}:{date}:{N}`
six heures quand tous les jours sont lus, `ppCmd:{shop}:{date}` dix minutes (jamais le client : produit et quantité).

**Écritures** (le franchisé, depuis l'écran) :

- `POST /production/plan/params` `{ shop, params: { cuissons, categories, regles }, par? }` — une à six cuissons, horaires
  croissants sans chevauchement, parts à 100 % (± 0,5), plaque 1–500, dernière recuisson `hh:mm`, règles bornées ;
  422 avec le motif sinon. Enregistré sous `ppParams:{shop}`.
- `POST /production/plan/fait` `{ shop, date, cuisson, lignes: { pid: pièces } }` — ce qui a été enfourné à une
  cuisson (« Valider la cuisson ») ; la cuisson suivante part de là. Enregistré sous `ppFait:{shop}:{date}`.

**Ce que le panel ne donne pas** (mesuré le 03/10/2026) : les reports de la veille (le stock de départ vaut zéro) ;
`/shops/{id}/statistics/production-planning` ne rend que la journée entière (aucun mode horaire accepté) ; **les
articles des commandes** : `products` est vide dans la liste comme dans la commande seule (`/client-orders/{id}`),
`/client-orders/{id}/products` répond 404 et `/franchisee-shop/{id}/client-orders/{date}/products` 500. Une commande
retirée est dans les tickets (`id_transaction`), donc dans les ventes et la prévision ; une commande à venir s'affiche
(`sansDetail: true`, heure, canal, montant) sans changer le plan tant que le panel n'en joint pas les articles —
le calcul les prendra d'office le jour où `products` sera rempli.

### L'app employés (`/employee`) — « App worker »

L'application mobile des employés (TFB-Employee), hébergée par le cockpit sous `/consulant_bo/employee/`
et liée depuis le rail, ERP franchisé › **App worker ↗** (demande du 06/10/2026). Elle ne parle pas
à l'API du cockpit : elle appelle l'API du panel (`panelApi.base`) avec les identifiants de
l'employé (`POST /employees/authenticate`, puis `/employees/{id}`, `/employees/{id}/tasks`,
`/employees/{id}/schedule`, `/shops/{id}`). Code dans `apps/employee`, détails dans
`apps/employee/LISEZMOI.md`. Aux couleurs et à la typo de la marque. Seule exception : l'onglet
**Primes** lit le cockpit (`GET /ventes/moi`, ci-dessous) avec le jeton d'employé.

### Les primes dans l'app worker — `GET /ventes/moi`, `GET /ventes/primes-reglages`, `POST /ventes/primes-app`

Demande du 06/10/2026, refondue le 14/10/2026 (`docs/maquettes/primes-epure/`, variante A) : dans l'onglet
**Primes** de l'app employés, **chaque carte est une prime**, un target, une catégorie de produits ou une
promotion du panel, avec **une récompense** réglée par l'admin, 50 € par défaut. Le classement à la Strava
est dans un menu déroulant. Plus de texte d'explication. Code : `src/primes.php` (le calcul) et
`src/primes_app.php` (la liste, les promos, les moyennes, les cartes).

| Prime | Type | Mesure | Qui gagne la récompense | Réglage |
|---|---|---|---|---|
| Ventes croisées | target | ses tickets à **2 lignes ou plus** (deux produits différents ; trois croissants font une ligne) **dont une au moins de la catégorie choisie** (`categorie`, un menu déroulant des catégories du catalogue ; vide : toutes), en % de ses tickets, sur les jours moissonnés | chaque vendeuse dont le taux du mois atteint la **cible du magasin** (sinon celle du réseau), 30 tickets au moins | la récompense et la catégorie dans la liste ; `POST /ventes/croisees {cibleDefaut, cibles: {shop: pct ou ""}, minTickets}` |
| Objectif magasin | target | l'atteinte de l'**objectif du mois** (le budget, `ceo_shop_month_perf`, sinon le CA théorique), en projection pour le mois en cours | chaque personne du magasin au planning, au-dessus de `heuresMin`, si l'atteinte dépasse `pct` (100 % par défaut) | dans la liste : `pct`, `heuresMin` |
| Catégories : **Queen of Tartes**, **Queen of Quiches**… | categorie | le nombre de **pièces** d'une famille (un motif sur la catégorie ou le nom : `tarte`, `quiche`) vendues dans le mois à son nom (`q[cle]` de la moisson) | la **1re du magasin**, `minPieces` au moins ; à égalité, moins de tickets gagne. Plus de couronne du réseau payée | dans la liste : `lib`, `motif`, `minPieces` (six au plus ; la clé vient du libellé et ne change plus) |
| Promos du panel | promo | les **bundles vendus** (le produit du bundle, une ligne du ticket) ou les pièces des produits d'une offre « X achetés + Y offerts », sur les **seules dates** et dans les **seuls magasins** de la promotion (`qp[cle]`) | la **1re du magasin** de la promo, `minPieces` au moins | une promo se crée depuis la liste des promotions du panel (`GET /ventes/primes-promos`) ; six au plus |
| Bats ton record (inchangé) | target | lignes par ticket face à son record 12 mois | 100 € par dixième dès le 2e, 3 au plus | `POST /ventes/record` (onglet Targets & primes) |
| Meilleure vendeuse (inchangé) | classement | score = CA ÷ (heures + 20) × créneau | 1re du magasin 75 €, 1re du réseau 150 € | `POST /ventes/primes-montants` (onglet Targets & primes) |
| **Note Google** (14/10/2026, à la place du coefficient) | target | la note de la fiche Google du magasin (`mar_avis_fiche`, la Réputation), face au seuil (`seuil`, 4,5 par défaut) | quand la note est **au seuil ou au-dessus**, chaque personne du magasin au planning, au-dessus de `heuresMin`, gagne la récompense (**25 €** par défaut, la sienne, pas la récompense par défaut) ; en dessous, rien | dans la liste : `seuil`, `heuresMin`, `montant`, le payeur. `POST /ventes/prime-google` n'existe plus |

- `POST /ventes/primes-app {recompense, liste: [{type: target|categorie|promo, cle, lib, actif, montant,
  classement, icone, …}]}` : la liste des primes (`ceo_app_setting.ventePrimesApp`). Les trois targets (`croisees`,
  `objectif` : `pct`, `heuresMin` ; `croisees` : `categorie` ; `google` : `seuil`, `heuresMin`, 25 € par défaut,
  éteinte au départ si l'ancien coefficient l'était) sont toujours là, dans cet ordre ; une catégorie porte `motif` et `minPieces` ; une promo
  nouvelle arrive avec sa référence `ref: {source: api|catalogue, id}`, que le cockpit relit dans la liste des
  promotions (`primesPromos`) : rien de ce que le navigateur envoie sur ses produits n'est cru, une promo déjà
  là garde ce qu'elle avait. `classement` : la prime a sa liste dans le menu déroulant de l'app. Au premier
  appel, la liste se déduit des réglages d'avant (`venteConcoursTQ` → catégories, au montant de leur couronne
  du magasin ; `ventePrimeMagasin.heuresMin`). **Qui paie** chaque prime : `payeur: marque|magasin|sponsor`
  et `sponsor` (son nom ; un sponsor sans nom reste à la marque) ; `externes: {record, meilleure}` dit qui paie
  ces deux-là. `icone` : le chemin d'une icône déposée (`uploads/primes/<hex>.<png|webp|jpg|svg>`, rien d'autre
  n'est gardé), sur chaque prime et sur `externes.record`, `externes.meilleure` ; sans icône, l'app met l'emoji.
  `sms: {actif}` : le **SMS à la réussite** (plus bas). Au journal ; les fiches du mois en cours se recalculent.
- `POST /ventes/primes-icone {data}` (14/10/2026) : une icône en data-URL, PNG, WebP ou JPEG (16 px au moins) ou
  SVG, **1 Mo au plus**. Un SVG n'entre que sans script, gestionnaire d'événement, objet embarqué ni entité, et
  si ses liens restent dans le fichier (`#id`) ou sont des images `data:` (`primesSvgSain`). Elle est écrite sous
  `public/uploads/primes/` avec un nom tiré au hasard ; la réponse `{ok, icone}` donne le chemin que la liste
  garde ensuite (`POST /ventes/primes-app`). 413 si trop lourde, 415 si le format est refusé, 422 si illisible.
  Le `.htaccess` sert ces SVG avec une CSP qui les empêche d'exécuter quoi que ce soit, même ouverts seuls.
  Écran : ERP franchisé › Marketing franchisé › Primes de l'app worker (`#/primes-app-worker`), un clic sur la tuile d'une prime (✕ : retour à l'emoji).
  `POST /ventes/prime-magasin` et `POST /ventes/concours` n'existent plus.
- `GET /ventes/primes-promos[?frais=1]` : les promotions qu'une prime peut suivre, gravées une heure
  (`primesPromos3`) : `{api, compte: admin|consultant|null, erreurs: [{chemin, code, erreur}], liste: [{source: api,
  sorte: bundle|xy|quantite|remise, id, nom, prix, du, au, shops[], ids[], composants[], prime: {cle, actif} | null}]}`.
  **L'API promotions du panel, et elle seule** (16/10/2026) : `/admin/promotions/bundles`, `buy-x-get-y`, `quantity`,
  `scheduled-product-discount`, chacune `?status=ACTIVE&limit=100` (nom, `valid_from`/`valid_to`, `shop_ids`, prix
  `bundle_promotion.bundle_price` ; produits : `items`, `eligible_product_ids`, `reward_product_ids`,
  `trigger/reward.product_ids` ; le produit d'un bundle est son `product_id`, sinon le produit du catalogue qui porte
  son nom). Un brouillon (`status` ≠ ACTIVE) est écarté. Plus de repli sur les bundles du catalogue, et une prime de
  promo « catalogue » d'avant n'est plus gardée. **Ces routes sont sous `/admin`, l'API consultant n'en a aucune** :
  elles se lisent avec le **compte admin ERP** (`ErpApi`, `POST /admin/auth/login`, Paramètres → Compte admin ERP) ;
  sans lui, le compte consultant en repli reçoit 403 — `compte` et `erreurs` le disent, la liste est vide.
  La liste de l'API ne porte que des compteurs : la **fiche** de chaque promotion (`/admin/promotions/{sorte}/{id}`) est
  lue aussi — `items[].id_product` (bundle), `trigger/reward.products|product_ids`, et les **catégories visées**
  (`trigger/reward.categories[].name`, « Cookies ») relues dans le catalogue par leur nom (sous-catégorie, sinon
  catégorie). Un bundle de l'API sans produit à lui se compte par ses articles. `shop_ids` de la liste peut être un
  texte (« 4,5 »). `GET /ventes/primes-promos/sonde` (lecture seule) rend la forme brute : clés, 1er élément, sa fiche.
  `GET /ventes/promos/sonde-portions?shop=4[&q=tarte]` (17/10/2026, lecture seule) : où le panel décrit les portions
  d'un produit — les clés « portion » de /shops/{id}/products/available, les fiches produit plausibles de l'API
  (consultant et admin), la table `product_portion` de la base. Pour l'assistant des promotions « sur une part ».
  `GET /ventes/promos/sonde-fiche?q=test[&sorte=buy-x-get-y]` (17/10/2026, lecture seule) : toutes les promotions de la
  sorte, **tous statuts** (`promotions: [{id, nom, statut}]`), et pour celles dont le nom contient `q` (trois au plus) la
  ligne de la liste et la fiche brute (`trouvees: [{item, fiche: {code, corps}}]`) — pour relire une promotion créée à la
  main dans le panel avant que l'assistant « Nouvelle promotion » du magasin n'en écrive une.
- **Deux familles de primes** (16/10/2026). `famille: marque` (défaut) : posée par la marque, payée par la marque
  ou un sponsor (`payeur: magasin` demandé sur une prime de la marque revient à la marque), visible de chaque
  franchisé. `famille: magasin` : la prime promo qu'un **franchisé** pose pour **son** magasin — `shop` (un seul), la
  promotion ne compte que ses ventes (`promo.shops = [shop]`), `payeur: magasin` imposé ; clé `pm{shop}z…` (une même
  promotion du panel peut porter la prime de la marque `pb15` et celle d'un magasin `pm4zb15`). Six au plus par
  magasin (six promos de la marque à part). Une promotion du panel prise par un franchisé doit tourner dans son
  magasin (422 sinon). Réenregistrée, une prime garde sa famille. Cockpit : carte « Primes de la marque et des
  sponsors » et carte « Primes des franchisés » (filtre par magasin, « + Nouvelle prime du magasin ») ; dans les
  promotions du panel, une promotion d'un seul magasin propose « + prime du magasin ».
- `GET /ventes/primes-catalogue[?frais=1]` (16/10/2026) : pour la barre de recherche de l'assistant « Nouvelle prime
  promotion » : `{groupes: [{nom, n}], categories: [{nom, groupe, n}], produits: [{id, nom, cat, grp}]}` (le catalogue
  de la production, `pwaId` = l'identifiant des lignes de tickets ; gardé une heure, `primesCatalogue`).
- **La période d'une prime** (16/10/2026) : une catégorie ou un target (ventes croisées, objectif, note Google) porte
  `du` / `au` (AAAA-MM-JJ, l'un ou l'autre ou aucun ; à l'envers, remis dans l'ordre) ; une promo a les dates de sa
  promotion. Une prime compte et s'affiche dans **chaque mois que sa période touche**, nulle part ailleurs : le mois
  calculé (`primesAppMois`, posé par la fiche, le mois à venir et les primes du mois) lit une prime hors période comme
  éteinte (`primesAppEntree`, `primesAppDuType`) ; les réglages et l'enregistrement lisent la liste telle quelle.
  Cockpit : l'assistant (catégorie) propose « Tous les mois, sans fin » ou « Entre deux dates », la fenêtre de réglage
  des targets « Du » / « Au » ; le tableau montre la période et « à venir » / « terminée » face au mois en cours.
- **Sur objectif** (16/10/2026) : deux règles de plus pour une prime promo, posées par l'assistant. `regle: magasin` —
  une **prime pour le magasin** : quand le **total du magasin** sur les produits et la période atteint son objectif, la
  récompense est **une seule somme pour le magasin**, partagée à parts égales (au centime) entre ses vendeuses du mois
  (niveau `equipe`, `montantMag` la somme, `partage` entre combien ; l'app montre la somme « magasin », puis la part) ; `regle: vendeuse` — chacune qui atteint **son** objectif
  (`objVend` : l'objectif du magasin ÷ ses vendeuses, arrondi au-dessus ; niveau `objectif`). L'entrée porte
  `objectifs: {magasin: pièces}`, `vendeuses: {magasin: moyenne}`, `objVend`, `pct` (la hausse demandée), `n1: {magasin:
  pièces l'an dernier}` ; sans objectif, 422. Le classement des promos porte `shop` et `totalMag` ; la carte de l'app
  (`objPcs`, `moiPcs`) : pour « magasin », la jauge est le total du magasin face à son objectif, « dont toi X pcs » en
  dessous ; pour « vendeuse », ses pièces face au sien.
- `GET /ventes/primes-reference?ids=1,2&du=&au=&shops=4,5` (16/10/2026, l'assistant) : par magasin, les pièces vendues de
  ces produits sur la même période l'an dernier (`n1` ; `/shops/{id}/statistics/sales/product-category-groups`, gardé par
  `apTranches2`), à défaut au rythme des 28 derniers jours ramené à la période (`recent`) ; `ref`, `source: n1|recent`.
  L'assistant (étape « Qui gagne ») en fait le tableau : l'an dernier, l'objectif (+ x %, modifiable magasin par
  magasin), les vendeuses (moyenne des `primes-moyennes`), l'objectif par vendeuse.
- **Le tableau des primes ne s'édite plus** (16/10/2026) : seul l'interrupteur actif / éteint, enregistré tout de suite
  (`POST /ventes/primes-app`). « Modifier » rouvre l'assistant (une promo : tout prérempli, sa clé gardée) ou la fenêtre
  de réglage (target, catégorie, Bats ton record, meilleure vendeuse : ses réglages, qui paie, son icône ; « Supprimer »
  pour une catégorie ou une promo). « + prime » d'une promotion du panel ouvre l'assistant, la promotion choisie.
  **Un seul assistant pour créer** (16/10/2026) : « + Nouvelle prime » demande d'abord la sorte — **Promotion** (des
  produits sur une période) ou **Catégorie** (chaque mois, tous les magasins, les produits dont le nom contient un mot :
  le `motif`, une expression sans majuscules, avec la liste des produits du catalogue qu'il trouve ; la 1re de chaque
  magasin gagne). « Modifier » une catégorie rouvre l'assistant, sa clé gardée ; la fenêtre de réglage ne sert plus
  qu'aux targets, au record et à la meilleure vendeuse. Une promo du cockpit rouverte part avec `refaire: true` et sa nouvelle sélection
  dans `promo_neuve` : le serveur la relit en produits (même identifiant, même clé ; 422 si elle ne donne aucun produit).
- **L'icône dans l'assistant** (16/10/2026) : à l'étape « Nom & aperçu », un emoji (dessiné en PNG de 128 px) ou une
  photo (recadrée au carré par le centre, réduite à 128 px, coins arrondis), faits dans le navigateur ; à la création,
  `POST /ventes/primes-icone` d'abord, puis la prime avec le chemin rendu (`icone`).
- **L'assistant « Nouvelle prime promotion »** (cockpit, Marketing franchisé → Primes de l'app worker, 16/10/2026) enregistre par
  `POST /ventes/primes-app` une entrée `{type: promo, ref: {source: cockpit}, lib, montant, minPieces, regle:
  premiere|seuil, payeur, sponsor, promo: {source: cockpit, sel: [{t: groupe|categorie|produit, v, lib}], du, au,
  shops[]}}` (une sous-catégorie : `v` = « catégorie\0sous-catégorie »). Le serveur relit les produits de la
  sélection dans le catalogue (`primesSelIds`, jamais les identifiants du navigateur), tire l'identifiant, et la clé
  devient `pk…`. La moisson relit la sélection chaque jour (un produit ajouté à la catégorie compte). `regle` vaut
  aussi pour les promos du panel : `premiere` (la 1re du magasin gagne, comme avant) ou `seuil` (chaque vendeuse à
  `minPieces` pièces ou plus gagne la récompense ; la carte de l'app vise alors le seuil, `repereType: cible`).
- `GET /ventes/primes-moyennes?n=1|3|6` : par magasin (et `reseau`), la moyenne mensuelle des n derniers
  mois clos, depuis les jours moissonnés (`prMoy{mois}`, un jour) et les KPIs du mois (`pvCa`) :
  `{vendeuses (30 tickets au moins dans le mois), tickets, ticketsVendeuse, ticketMoyen, lignesTicket,
  croisees, atteinte (CA ÷ objectif), pieces: {cle: par vendeuse}, cible}` ; `promos: [{cle, lib, du,
  magasins: {shop: pièces par vendeuse depuis le début}, reseau}]`. Écran : Marketing franchisé › Primes de l'app worker,
  « Moyennes par magasin », la cible de ventes croisées de chaque magasin en face, « caler sur la moyenne +
  n pts », export CSV.
- `GET /ventes/primes-reglages` : `{app (la liste, externes, sms), smsConfigure, categories (le menu déroulant des
  ventes croisées : les catégories du catalogue, sans les bundles), magasin, croisees, concours, google: {actif,
  seuil, montant, heuresMin, notes: {shop: note}}, record, meilleure, magasins, m, lib}`. La colonne « Moyenne
  réseau » de la note Google en tire la note moyenne et le nombre de magasins au seuil. Écran : ERP franchisé › Marketing franchisé › **Primes de l'app worker** (16/10/2026, avant : Équipe & ventes › Paramètres ; la liste des primes, les promotions du panel,
  les moyennes par magasin ; il s'ouvre même si le classement n'a pas pu être calculé).
- **Un mois à venir** (16/10/2026) : `m` peut aller jusqu'à trois mois en avance (`PRIMES_MOIS_A_VENIR`). La fiche est
  alors celle des primes prévues (`primesAVenir`) : `aVenir: true`, `acquis: 0`, `enJeu` la somme des récompenses,
  `cartes: [{cle, type, emo, icone, lib, montant, max, etat: avenir, objectif, unite, regle, du, au, heuresMin?,
  minTickets?}]` — les targets actives, Bats ton record, la meilleure vendeuse, et les promos dont la période touche
  le mois dans le magasin de la personne (l'objectif est celui de son magasin) ; pas de classement, rien de compté.
- `GET /ventes/moi?m=AAAA-MM` depuis l'app worker : la route est ouverte avant la session du
  cockpit, pour une identité prouvée. Soit l'**identité signée** par l'app (`X-Worker-Emp`,
  `X-Worker-Nom` en base64, `X-Worker-Jour`, `X-Worker-Sign` = HMAC-SHA256 de `emp|nom|jour` avec
  le secret que les deux déduisent du même `config/config.php`, le jour ou la veille), soit le
  **jeton d'employé** (`Authorization: Bearer …` ou `X-Employee-Token`), dont l'identifiant (`sub`)
  est confirmé par le panel (`GET /employees/{id}` avec ce même jeton, vérifié une fois par dix
  minutes). Sans cela, 401. La personne se retrouve par son identifiant, sinon par son nom
  d'affichage ; le personnel vient de la table locale **et** du panel (`/shops/{id}/employees`, gravé
  une heure). Inconnue partout : 404 avec le nom reçu. Depuis le cockpit (session),
  `GET /ventes/moi?emp=&m=` ou `?nom=` montre la même fiche. Réponse : `{emp, m, lib, enCours, magasin: {id, nom},
  cartes: [{cle, type: target|categorie|promo|classement, emo, icone (le chemin de l'icône déposée, sinon ""),
  lib, montant (la récompense ; le record : son plafond, max), gain, etat: gagne|tete|rang|att|vide, val,
  unite: pct|pcs|lpt|score|note, cible, ecart, jauge, repere, rang, sur, premier: {nom, q, moi}, second,
  minPieces, avis (la note Google), motif: pas_compte|sans_vente (le mois est compté, aucun ticket à son nom)|pas_objectif|pas_note|heures|pas_classee|pas_record|
  min_tickets|null, classement, jauge, repere, repereType, repereVal, repereJour}], classements: [{cle, lib, type,
  emo, icone, unite: pieces|taux, magasin[],
  reseau[], nMag, nRes}] (les dix premières et la personne, avec le nom du panel et la localité : le seul
  endroit qui porte des noms), acquis, enJeu (= aPortee), google: {actif, note, avis, seuil, recompense,
  heuresMin, atteint, sousMin, montant}, montants: {croisees, record, meilleure, concours, promo, magasin,
  google}, heures, aujourdhui, briques: {croisees, record,
  meilleure, concours, promos, google}, prime: {objectif, ca, atteinte, projection, atteinteProj, paliers: [{pct,
  montant, atteint, manque, parJour, vous, equipe}], palier, sousMin, vous, equipe, personnes},
  collection, semaines12}`. `acquis` = ce que le mois donnerait s'il finissait comme ça (la 1re d'une
  catégorie ou d'une promo compte), la note Google comprise ; `enJeu` = la récompense de chaque carte. La
  **demi-jauge** d'une carte (16/10/2026, `primesCarteJauge`) : `jauge` = où elle en est aujourd'hui, `repere` =
  l'objectif à atteindre, tous deux en % d'une échelle qui part de 0 (de seuil − 1 à 5 pour la note Google) ;
  `repereType` : `cible` (target), `seuil` (note Google), `passer` (la 1re du magasin + 1, catégories, promos,
  meilleure vendeuse), `garder` (elle est 1re : le chiffre de la 2e), `min` (personne n'a vendu : le minimum de
  pièces) ; `repereVal` le chiffre à atteindre. L'objectif du magasin en cours de mois porte `aDate`
  (l'atteinte à date), `jour`, `cibleJour` (où en être au jour J, au prorata des jours), `repereJour` (sa
  position), `avance` (aDate − cibleJour) ; `val` reste la projection. Les **catégories** n'ont plus de jauge à
  l'écran mais un podium : `podium` = `[{rang, nom, q, moi}]`, les trois premières du magasin (elle à la 3e ligne,
  à son rang, quand elle est plus loin ; vide pour une promo ou un target) ; `pour1` = les pièces qu'il lui faut
  pour être 1re (dépasser la 1re, le minimum compris), `avance1` = son avance sur la 2e quand elle mène. Les targets se lisent en barre (16/10/2026) :
  `barre` = la valeur sur une échelle où l'objectif (`repereVal`) est toujours à 75 % (de 0 ; de seuil − 1 pour la
  note Google ; bornée à 100), `barreJour` = le jour J sur la même échelle (pour une catégorie, le trait est ses pièces + `pour1`, ou la 2e quand
  elle mène, le minimum quand elle est seule), `actif` = la prime est activée (carte
  gagnée ou en tête ; objectif du magasin en cours de mois : le CA encaissé a passé l'objectif). La fiche porte `v`
  (sa forme, 8 au 16/10/2026) : un cache d'une autre forme est recalculé, un déploiement ne sert pas une fiche d'avant. La page **Statistiques** de l'app lit la
  même fiche, ses chiffres à elle seule : briques (ticket moyen `meilleure.panier`, lignes par ticket
  `record.lt`, ventes croisées, CA, CA par heure, tickets, places), aujourdhui, collection, et `semaines12`, dont
  chaque semaine porte depuis le 16/10/2026 `panier` (son CA ÷ ses tickets), `lignesTicket`, et `mag` / `res` :
  la vendeuse moyenne de son magasin et du réseau la même semaine, `{taux, lignesTicket, tickets, panier,
  caHeure}` (`tickets` = tickets par vendeuse qui a vendu ; `panier` = CA ÷ tickets des ventes par vendeuse ;
  `caHeure` = CA ÷ heures planifiées de celles qui ont vendu ; jamais un nom). Cache `primesS12v4:{emp}`, les
  moyennes `primesS12moy` (une heure, pour tout le monde). Les
  briques, la collection et les douze semaines nourrissent l'Accueil de l'app. Mis en cache 15 minutes
  (mois en cours) ou un jour (mois clos) par personne ; `&frais=1` recalcule.
- `GET /ventes/primes-mois?m=` : tout le monde sur un mois (le dernier mois clos par défaut) :
  `magasins[] {id, nom, objectif, ca, atteinte, atteinteProj, palier: {pct, montant}, eligibles, equipe, cible,
  google: {note, seuil, montant, atteint, eligibles, equipe}}`, `personnes[] {id, nom, shopId, magasinNom, heures,
  tickets, croisees: {taux, montant}, record, meilleure, concours: {pieces, titres, montant}, promo: {pieces,
  titres, montant}, magasin, google, total, parPayeur, parPayeurApp}`, `totaux` (avec `google` et `parPayeur` :
  `{marque, magasin, "sponsor:Nom"}`), `magasins[].parPayeur`, `concours`, `promos: {actif, liste[]}`, `google`,
  `enregistre`.
  Écran : Équipe & ventes › Résultats (« Payé par : … »). **« Enregistrer les primes »** (`POST /ventes/primes
  {m}`) grave en plus, sous `appWorker`, les primes de l'app de chacune (ventes croisées, catégories, promos,
  objectif magasin, note Google ; identifiants, montants et `parPayeur`, jamais de nom) et une ligne de
  journal par personne primée, qui dit qui paie. Puis, si le **SMS à la réussite** est activé, chaque personne
  primée reçoit un SMS (« Bravo Léa ! Octobre : 100 € de primes gagnées (Ventes croisées, Queen of Quiches). »),
  une fois par mois (`primesSms{mois}`) : `appWorker.sms: {actif, configure, envoyes, echecs, sansNumero}`, et
  une ligne de journal. La réussite est alors acquise, pas une projection. Le numéro vient de la fiche
  (`franchisee_employee.phone`, sinon `/shops/{id}/employees` du panel, gardé à part : `pvEmployesTel`), mis au
  format +32. Le fournisseur : Twilio, réglé dans `config/config.php › sms` (`sid`, `token`, `expediteur`) ou
  `TWILIO_SID`, `TWILIO_TOKEN`, `SMS_EXPEDITEUR` (`src/sms.php`) ; sans lui, rien ne part, l'écran et le journal
  le disent.
- La moisson des lignes par ticket (`pvL{shop}:{jour}`, version 3) compte `c` (les tickets à deux lignes ou
  plus), `cc` ces mêmes tickets par catégorie de produits qu'ils contiennent (`k` : le jour les compte), `q`
  les pièces par famille — les catégories (motif) et les **promos** (leurs identifiants de produits,
  `promoFamilles`) — et `tq` en tout, par vendeuse ; `f` liste les familles comptées ce jour-là. Avec une
  catégorie de ventes croisées réglée, `c` se lit dans `cc` partout (le mois, les semaines, les moyennes, le
  compteur du jour) et les jours sans `k` depuis le mois d'avant se recomptent.
  Le complément du cron (`pvCroiseesComplement`, 1 500 tickets par heure) recompte les jours des versions
  d'avant **et** les jours qui n'ont pas une famille qu'ils doivent porter (`pvFamillesRequises` : une
  catégorie depuis le mois d'avant, une promo sur ses dates et ses magasins). `pvCroiseesMois` rend aussi
  `qp`, les pièces de chaque promo sur ses seules dates et ses seuls magasins. `pvE{shop}:{jour}` grave les
  ventes du jour par vendeuse ; le compteur du jour lit ses seuls tickets (`pvLJ{shop}:{emp}`, dix
  minutes) ; le CA d'un magasin jour par jour se grave (`pvJ{shop}:{jour}`).
- L'app (`apps/employee`, route `/primes`, `PrimesService`) appelle le cockpit à
  `COCKPIT_API_URL` (déduit de son adresse, sinon `EMPLOYEE_COCKPIT_API`) avec le jeton de la session.
- Tests sans base ni réseau : `php bin/tests_primes.php`.

### `/production/flux/*` — l'application Production du magasin (`/production`)

Une application à part, `/production/?shop=4&date=YYYY-MM-DD&page=params|plan|suivi|cloture`
(`&embed=1` sans en-tête : c'est elle que le rail du cockpit intègre, ERP franchisé › Gestion de
production, quatre pages). Le jour se choisit dans la barre des jours de la page : d'hier à J+7
pour le plan, les sept derniers jours pour la validation et la clôture, toute autre date au
calendrier ; intégrée, la page renvoie le jour au cockpit (`postMessage({pfDate})`), qui le garde
d'une page du rail à l'autre. Le flux de la journée, en quatre pages :

| Page | Lecture | Écriture |
|---|---|---|
| 1. Paramètres | `GET /production/flux/params?shop=` | `POST /production/flux/params` `{shop, gp: {cuissons, categories, regles}, flux: {jours, obligatoires, veille, garde, stockMin, ajusterJ7, modePeu}, par}` |
| 2. Plan de production | `GET /production/flux/plan?shop=&date=` (jusqu'à J+7) | — |
| 3. Validation et suivi | `GET /production/flux/suivi?shop=&date=` (jusqu'à aujourd'hui) | `POST /production/flux/valider` `{shop, date, cuisson, lignes: {pid: pièces}, par}` |
| 4. Clôture | `GET /production/flux/cloture?shop=&date=` | `POST /production/flux/cloture` `{shop, date, lignes: {pid: {report, jete, reste, compte}}, par}` |
| 5. Fours et équipe | `GET /production/flux/fours?shop=&date=` (jusqu'à J+7) | `POST /production/flux/fours` `{shop, fours: [{id, nom, plaques, chauffe}], categories: {cat: {four, temp, duree, parPlaque}}, operateurs: [{id, nom, de, a}], etapes: {cat: [étape]}, etapesProduits: {pid: [étape]}, par}` ; `POST /production/flux/fours/simuler` (même corps + `date`) : les deux Gantt sans rien écrire |

**Hors du cockpit, la page Suivi seule** (demande du 06/10/2026) : `/production/?shop=4&date=…`
ouvre toujours le suivi, sans les étapes (`page=` est ignoré) ; Paramètres, Plan, Clôture et Fours
restent dans l'écran Gestion de production du cockpit (`&embed=1`).

- **Durée de vie** (demande du 06/10/2026, maquette C) : chaque produit est **short life** (S, vendu
  le jour même), **medium life** (M, se garde 2 à 3 jours) ou **long life** (L, une semaine et plus).
  Elle se règle par catégorie, avec des exceptions produit. Rangée dans `pfParams:{shop}` sous
  `vie: {cat: {catCle: S|M|L}, prod: {pid: S|M|L}}` : seulement ce qui s'écarte de la proposition.
  Proposition par catégorie : long life pour ce qui se garde au lendemain (`flux.garde`) et la
  biscuiterie, les cookies, les cakes, l'épicerie, la confiserie ; medium life pour les pains, tartes,
  tartissières, quiches, entremets à partager, salades et plats ; short life pour le reste
  (viennoiserie, petite boulangerie, sandwichs, wraps, pâtisserie et entremets individuels). Par
  produit : brownies et brookies en long life. Ordre : le réglage du produit, puis la proposition
  du produit, puis la catégorie (son réglage, sinon sa proposition).
  - `GET /production/flux/suivi` porte sur chaque produit `vie`, `vieCat` (celle de sa catégorie),
    `vieAuto` (la proposition propre au produit, ou null), `vieProd` (le réglage du produit, ou null),
    `vieExc` (il s'écarte de sa catégorie), et `vie: {categories: [{cle, nom, groupe, vie, defaut,
    regle, produits, exceptions}], maj}` : les catégories suivies ce jour.
  - `cases[]` : l'heure entamée porte aussi `v`, ce qui s'y est déjà vendu (son `prev` ne couvre que
    le reste de l'heure) ; vendu + prévu = la vente attendue de l'heure.
  - `prevH: {heure: pièces}` : la prévision de chaque heure de la journée, passées comprises (la
    même que `cases[].prev` pour une heure à venir entière).
  - `POST /production/flux/vie` `{shop, cat: {catCle: S|M|L|null}, prod: {pid: S|M|L|null}, par}` :
    la pose depuis le suivi, sans toucher au reste des réglages ; null revient à la proposition ;
    `flux.enregistre` reste faux pour un magasin qui n'a posé qu'elle. L'enregistrement des
    Paramètres la garde. 400 sans magasin ; 422 pour une valeur hors S, M, L, un produit ou une
    catégorie invalide, ou rien à poser.
  - L'écran : trois onglets. Short et medium life gardent le suivi heure par heure ; le « trop »
    devient « Jeté ce soir » (short) ou « Se garde demain » (medium). Le long life se lit en stock :
    vendu par jour (`moyJ`, sinon `prevJ`), le stock face à 3 jours de vente, les jours de stock,
    « Sous le stock » quand il ne dépasse pas son stock minimum de recuisson (0 sans réglage) ; le
    conseil est la cuisson déjà prévue aujourd'hui, sinon de quoi tenir 3 jours. Le S · M · L sous
    chaque produit pose son exception ; « Régler par catégorie » ouvre le réglage des catégories.
    L'onglet suit l'adresse (`&vie=S|M|L`).
  - La clôture : un produit medium ou long life se garde par défaut (`lignes[].garde`, `lignes[].vie`),
    comme une catégorie « se garde » ; un short life se jette.

- **Par jour de la semaine** (`flux.jours[1..7]`) : le nombre de cuissons du jour (les n premières
  cuissons du magasin, leurs parts ramenées à 100 %) et la **production minimum de la 1re cuisson**
  en % de la journée : la 1re cuisson est relevée à ce minimum, les suivantes réduites d'autant.
  Une catégorie qui ne cuisait qu'à une cuisson absente ce jour-là passe à la dernière du jour.
- **Obligatoires** (`flux.obligatoires[pid] = {jours, min}`) : planifiés les jours cochés, au moins
  `min` pièces en 1re cuisson, même sans vente dans la base. Par défaut : l'assortiment obligatoire
  du cockpit (`ceo_prod_product.must`), tous les jours, son minimum (`qmin`, sinon 2).
- **Préparées la veille** (`flux.veille`, catégories ; la viennoiserie par défaut) : le plan d'un jour
  porte la colonne « à préparer pour demain matin » = la 1re cuisson du lendemain (base des mêmes
  jours que le lendemain). **Se garde** (`flux.garde` ; biscuits, cakes, épicerie, boissons par
  défaut) : la clôture propose de les garder, le reste se jette.
- **Heure maximum de vente** (`flux.heureMax`, `{pid: "HH:MM"}`, demande du 04/10/2026, « pistolet
  11:00 ») : le produit ne se vend plus après cette heure. Sa prévision s'arrête là (l'heure entamée
  au prorata) ; seules les cuissons dont la vente ouvre avant elle le portent (la 1re au moins), chacune
  pour ce que le produit vend dans sa période, la dernière gardée prenant aussi les commandes retirées
  plus tard ; à J−7, une dernière vente juste avant l'heure n'est pas « trop peu » ; le stock minimum de
  recuisson s'arrête à cette heure. Vide = toute la journée ; une heure hors de 00:01–23:59 est refusée
  (422). Le plan la rend par produit (`lignes[].heureMax`), l'écran la montre « ≤ 11 h » à côté du nom ;
  Paramètres › 4 · Les produits : colonne « Vendu jusqu'à ». `POST /production/flux/heure-max`
  `{shop, heureMax: {pid: "HH:MM" | "" | null}, par}` la pose ou la retire (vide ou null) pour quelques
  produits sans toucher au reste des réglages : un magasin qui n'a rien enregistré garde ses réglages
  proposés (`flux.enregistre` reste faux). 422 pour une heure invalide, rien d'écrit.
- **Poids de J−7** (`gp.regles.poidsJ7`, 0 à 100, 40 par défaut, demande du 04/10/2026) : le même
  jour de la semaine passée pèse ce pourcentage de la prévision, les autres jours lus de la base
  se partagent le reste ; à 0, la moyenne simple (toutes les semaines pareil). `base.poidsJ7` :
  le poids appliqué (`null` quand c'est la moyenne simple : poids 0, J−7 pas lu ou fermé).
- **Comptoir et commandes** (demande du 03/10/2026) : les commandes ne sont pas des ventes
  comptoir. Le panel ne joint aucun article aux commandes (`/shops/{id}/client-orders`, `products`
  vide) ; ils sont dans le ticket de la commande (`id_transaction`), souvent payé avant le jour du
  retrait. La prévision part du comptoir seul : les tickets de chaque jour de la base moins ceux
  des commandes encaissées ce jour-là, à l'heure de leur ticket (`base.commandes.retirees`, pièces
  par jour retirées en moyenne). Les commandes POS et webshop retirées le jour planifié
  s'ajoutent ensuite à la cuisson de leur heure de retrait (`commandes` : `pos`, `webshop`, `ca`,
  `pieces`, `sansDetail` = à payer au retrait, articles encore inconnus ; par ligne
  `commandesJour = {pos, webshop}`). Les tickets de commande lus se gardent (`ppTk:{shop}`).
- **Plan** : par produit (section › catégorie › produit, triés par volume), vendu à J−7 au comptoir
  (`j7.magasin` : tickets moins ceux des commandes), webshop et commandes POS retirées ce jour-là
  (`j7.webshop`, `j7.commandes`, articles de leur ticket ; nombre et montant dans `j7.webshop`,
  `j7.commandes` en tête) ; la prévision comptoir du jour ; le
  report de la veille (la clôture d'hier, déduite de la 1re cuisson) ; à produire par cuisson
  (`c[id] = {sortie, plaques, plaque, stock, prevu, fait, zone, ajustJ7, cmd, ws}`), le total, le CA au prix du magasin.
- **Parts de cuisson par catégorie** (`gp.categories[cat].parts = {idCuisson: %}`, demande du
  03/10/2026 : « les tartes, 30 matin, 30 midi, 30 après-midi ») : la production du jour de la
  catégorie se répartit selon ces parts (au prorata si elles ne font pas 100) ; `cuissons` en
  découle (les parts non nulles). Sans parts propres (`null`), la catégorie suit les parts de la
  journée et le minimum de la 1re cuisson ; avec, le minimum ne s'y applique pas. Un jour à moins de
  cuissons, la part d'une cuisson absente va à la dernière cuisson de la catégorie qui reste.
- **Step de production** (`gp.categories[cat].plaque`, les pièces par fournée ; `steps` = `[1, 8, 20]`
  proposés, un autre nombre reste permis, `null` = à l'unité) : la production et la proposition J−7
  s'arrondissent à ce step. **Stock minimum de recuisson** (`flux.stockMin[cat]`, pièces) : en
  dessous, le suivi annonce un manque et conseille de recuire (jusqu'à la limite de la catégorie).
- **J−7, trop ou trop peu** (demande du 03/10/2026) : par produit, `j7.derniere` (l'heure du
  dernier ticket), `j7.poubelle` (le jeté du panel, ou de la clôture de J−7 si plus grand ; `null`
  non lu), `j7.manque` (la prévision entre la dernière vente et la dernière vente du magasin,
  `j7.derniereVente`), `j7.verdict` `peu` (épuisé avant la fermeture) | `trop` (poubelle) |
  `mixte` | `juste` | `aucune` (pas vendu) | `null` (tickets pas lus), `j7.plus` (arrondi au step
  supérieur) et `j7.moins` (arrondi au step inférieur), `step`. La
  proposition vise le besoin de J−7, `j7.besoin` = `j7.vendu` + manqué, face à `j7.plan` (le plan
  du jour avant ajustement) : `plus` = ce qui manque au plan pour l'atteindre, `moins` = ce qui le
  dépasse, au plus la poubelle (pas de double compte quand la prévision est déjà sous J−7). Avec
  `flux.ajusterJ7` (oui par défaut), la proposition en tient compte : `plus` s'ajoute à la cuisson
  qui couvre l'heure du manque (la dernière à défaut), `moins` se retire en partant de la dernière
  cuisson (un obligatoire garde un step en 1re cuisson) ; `ajustJ7` = ce qui a été appliqué. Avec
  `flux.modePeu = 'stock'`, le trop peu relève plutôt le stock minimum de recuisson du produit
  (`stockMin` de la ligne, repris par le suivi).
- **Suivi** : une cuisson met ses pièces en vitrine à l'ouverture de sa période (validé, sinon le
  plan). Pour chaque produit, `cases[]` heure par heure : passé = report + sorti − vendu (tickets),
  à venir = stock actuel + cuissons à venir − prévision (l'heure entamée au prorata). `manque`
  (première heure projetée sous zéro et le déficit), `verdict` rupture | manque | trop | ok,
  `conseil` (pièces et plaques à recuire), `stockMin` (le seuil de recuisson du produit).
- **Moyenne vendue au suivi** (demande du 04/10/2026) : chaque produit porte `moy` (`{heure:
  pièces}`, une valeur par heure de `heures`) et `moyJ` (la journée) : la moyenne simple de ce
  qui s'est vendu au comptoir les mêmes jours des semaines lues (6 par défaut, réglage
  `semaines`), sans les commandes (les articles de leur ticket sortent de la base), portions
  ramenées à la pièce. Ce n'est pas la prévision : celle-ci pondère J−7 (`poidsJ7`) et coupe à
  l'heure maximum de vente. `base.joursLus` liste les jours de la moyenne, `base.commandesRetirees`
  dit si les commandes ont pu être lues. La base de prévision la garde à côté du profil (`moy`,
  cache `gpBase7:…`). Pour comparer, chaque produit porte aussi `vc` (`{heure: pièces}`, le vendu au
  comptoir de chaque heure passée ou entamée, sans les commandes ; null pour une heure à venir) et
  `vcJ` (la journée) ; `vendu` reste la vitrine, commandes retirées comprises. À l'écran, tout le
  tableau de surveillance est en nombres entiers (l'API garde une décimale) ; sous chaque produit,
  la ligne « moy. 6 dimanches » (le jour suit la date) donne la moyenne de chaque heure ; la case
  « moyenne par heure » la masque. La ligne « vendu comptoir » s'ajoute avec sa case (décochée par
  défaut), le vendu en vert à 25 % au-dessus de la moyenne, en orange à 25 % en dessous.
- **Suivi et clôture** : la vitrine se vide du comptoir et des commandes retirées (à leur heure de
  retrait) ; le ticket d'une commande payée pour un autre jour n'en sort rien.
- **Fours** (demande du 03/10/2026) : les fours du magasin (1 à 6 ; `plaques` = plaques par
  fournée) et, par catégorie, la cuisson : `four` (`null` = hors four), `temp` (°C), `duree` (min
  par fournée), `parPlaque` (pièces par plaque) ; proposés d'après le nom et la section
  (`auto`) tant que rien n'est enregistré (`pfFours:{shop}`). `gantt.fours[].fournees[]` : pour
  chaque cuisson du plan, les plaques de chaque catégorie (pièces ÷ pièces par plaque, au-dessus),
  regroupées par four et par réglage, la plus chaude d'abord, en fournées de la capacité du four,
  enchaînées pour sortir à l'ouverture de la vente sans recouvrir la cuisson précédente
  (`debut`, `fin`, `temp`, `duree`, `plaques`, `capacite`, `categories`, `retard` en minutes) ;
  `occupation` (min), `utilisation` (% : minutes de cuisson sur la plage de production, de la 1re
  heure « au four » à la dernière ouverture de vente, `fenetre` ; plus de 100 % = le four ne suffit
  pas), `remplissage` (% : plaques enfournées sur la capacité des fournées), `horsFour`, `retards`,
  `axe` (heures du Gantt), `entree` (par cuisson, les pièces de chaque catégorie : l'écran recalcule
  le Gantt avec des fours ou des réglages pas encore enregistrés, bouton « Rafraîchir le Gantt »).
- **Grouper par température** (demande du 03/10/2026) : une fournée réunit les catégories d'un four
  à la même température ; chacune sort à sa durée (`categories[].sortie`), la fournée dure la plus
  longue. La plus chaude d'abord, mais un four commence par la température où il est resté ;
  changer de température coûte la chauffe du four (`fours[].chauffe`, 0 à 120 min, 10 par défaut ;
  `fournees[].chauffe`), comptée dans l'occupation. Chaque catégorie d'une fournée porte ses `produits` ([nom, pièces], dans l'ordre
  des fournées) : les feuilles de cuisson de la journée (une page par cuisson, four par four)
  et la page de chaque four à l'impression.
- **Répartir sur les fours** : `four = '*'` (proposé pour toute catégorie qui passe au four) : les
  plaques d'une même température, fournée pleine par fournée pleine, au four qui la sortirait le
  plus tôt (sa charge de la cuisson face à sa fenêtre libre avant l'ouverture, chauffe comprise :
  le four déjà à cette température passe devant) ; ajouter un four et « Rafraîchir » répartit.
- **Étapes et opérateurs** (demande du 03/10/2026) : une étape = `{nom, quand: avant|apres, minutes,
  par: plaque|piece|lot, op}` ; par catégorie (`etapes`, proposées tant que rien n'est enregistré,
  `etapesAuto`) et propres à un produit (`etapesProduits`, elles remplacent celles de sa catégorie).
  Les opérateurs : `{id, nom, de, a}` (service). `equipe` : par opérateur, ses tâches (`debut`,
  `fin`, `etape`, `nom`, `qte`, `par`, `minutes`, `quand`, `cuisson`, `retard` = minutes après
  l'ouverture de la vente pour une finition, `horsService`), `charge` (min), `service` (min),
  `utilisation` (%) ; `aAttribuer` (étapes sans opérateur), `heures` (minutes de travail du jour),
  `retards`, `horsService`, `axe`. Avant cuisson : au plus tard pour finir à l'entrée au four (à
  l'ouverture de la vente hors four) ; après cuisson : au plus tôt dès la sortie ; les étapes d'un
  même produit se suivent dans leur ordre, même à deux opérateurs. Une catégorie finie après
  cuisson sort du four d'autant plus tôt (`fournees[].cible`). Chaque tâche porte `pieces` et
  `produits` ([nom, pièces], du plus produit au moins produit) : l'écran en tire l'impression par
  poste, une page A4 par four et par opérateur (fournées ou tâches dans l'ordre, détail des
  produits, case à cocher, signature).
- **Sonde** : `GET /production/flux/sonde?shop=&date=` (lecture seule, des comptes et des noms de
  champs, jamais un client) : les commandes du jour, encaissées ou non, les routes du panel essayées
  pour leurs articles, la part des tickets pro qui sont des commandes ; `&liste=1` : la taille et
  les bornes de la liste des commandes. `champsClient` : les noms des champs du client d'une
  commande (jamais leurs valeurs).
- **Commandes d'un produit** (demande du 04/10/2026) : `GET /production/flux/commandes?shop=&date=`
  rend les commandes retirées le jour planifié et à J−7, non annulées :
  `{date, j7, clientsLus, nomsComplets, commandes: [{jour, heure (retrait), id (n° de commande),
  canal (compt = POS, cc = webshop, liv = webshop livré), statut, client, pro, montant,
  lignes: [[pid, pièces]], sansDetail}]}`. Les articles sont ceux des tickets déjà lus pour le plan
  (`ppTk:{shop}`). Le client se relit au panel à chaque appel et ne s'écrit nulle part : une société
  garde son nom ; une personne sort en « Prénom N. », en entier seulement quand l'API exige une
  session (`nomsComplets`) ; jamais de téléphone ni d'e-mail. Panel muet : les commandes du plan
  sans client (`clientsLus: false`). Un nom tout en capitales sort écrit comme un nom. L'écran du
  plan les lit au premier produit déplié : un clic sur un produit commandé ce jour-là ouvre sous
  sa ligne l'heure de retrait, le client et la quantité de ce produit, rien d'autre.
- **Validation** : le même enregistrement que l'écran historique (`ppFait:{shop}:{date}`, `c[cuisson]`),
  plus qui et quand (`v[cuisson] = {le, par}`).
- **Clôture** : reste = report d'hier + sorti − vendu − jeté déjà déclaré au panel ; `report`
  (gardé pour demain) devient le stock de départ du plan du lendemain (`pfCloture:{shop}:{date}`).
  Rien n'est écrit au panel : ce qui se jette est à encoder en caisse.
- **Comptage réel à la clôture** (demande du 04/10/2026) : le panel n'a aucune route pour la
  production réelle ni pour le stock de fin de journée (26 routes sondées, seule la poubelle répond ;
  les mouvements de production de la caisse ne vivent que dans la copie de la base, arrêtée au 13/07).
  Le reste calculé repose donc sur le sorti validé, sinon sur le plan. L'équipe peut compter la
  vitrine : `POST /production/flux/cloture` accepte par produit `compte` (0 à 5 000, vide ou null =
  pas compté, 422 sinon) et rend `comptes`. `GET` rend par ligne `compte`, `ecart` (compté − reste
  calculé) et `sortiReel` (compté + vendu + jeté déclaré − report d'hier : la production que le
  compté laisse supposer), et en totaux `compte`, `comptes`, `ecart`, `valeurEcart` (au prix de
  vente). À l'écran, une saisie du compté répartit la ligne (gardé pour une catégorie « se garde »,
  jeté sinon) ; « Tout garder », « Tout jeter » et « Proposition » partent du compté quand il existe.
- **Dernière vente à la clôture** (demande du 04/10/2026) : `GET` rend par ligne `derniere` (l'heure
  entamée du dernier ticket du produit au comptoir, les tickets des commandes retirés ; null si
  rien au comptoir), `derniereA` (`"HH:MM"`, la minute de ce ticket, quand le relevé la porte et
  qu'elle tombe dans la même heure ; null sinon), `venteApres` (ce que le produit vend d'habitude
  après cette heure, sur son profil, jusqu'à la dernière vente du magasin ou à son heure maximum de
  vente), `epuise` (`venteApres` ≥ 1 et vitrine vide : compté 0, sinon reste calculé 0) et
  `heureMax`. En tête : `derniereVente` et `derniereVenteA` (le dernier ticket du magasin), et en
  totaux `epuises`. La minute vient du relevé des tickets (`svP{shop}:{date}`, champ
  `d: {pid: "HH:MM"}`), gravé à la lecture du jour depuis le 04/10/2026 ; un jour relevé avant ne
  donne que l'heure. À l'écran : la colonne « Dernière vente » après « Vendu », « 16:42 » ou
  « 16 h », en orange avec « épuisé » ; une tuile « Vides avant la fin » compte ces produits et la
  vente perdue estimée. Le compté saisi recalcule « épuisé » sans recharger.

### `/exploitation/semaine-jours` — la semaine jour par jour (dashboard téléphone, onglet Semaine)

Demande du 04/10/2026 : le dashboard magasin au téléphone passe en trois onglets, Exploitation,
Contrôle et Semaine (maquette A « les feux », `docs/maquettes/dashboard-3-onglets/`). L'onglet
Semaine montre un damier jour × mesure. Le chiffre, l'objectif et le résultat de chaque jour
viennent déjà de `/exploitation/jour?date=` (`magasins[].semaine`) ; cette route ajoute ce qui ne se
lit que jour par jour.

`GET /exploitation/semaine-jours?shop=4&date=YYYY-MM-DD` — la semaine (lundi → dimanche) de la date :

```json
{ "shop": 4, "du": "2026-09-28", "au": "2026-10-04",
  "jours": [ { "date": "2026-10-03", "futur": false, "aujourdhui": false,
               "controles": { "rendus": 8, "total": 11, "notes": 7, "nc": 0, "aControler": 1, "sansPhoto": 1,
                              "manquent": ["Photo du comptoir - Biscuiterie", "…"],
                              "sansPhotoNoms": ["Photo du comptoir - Traiteur"] },
               "poubelle": { "pieces": 76, "cout": 36.2 } }, … ] }
```

- **Contrôles** : les tâches obligatoires du panel ce jour-là (`/pwa/tasks?date=&shop=`, une tâche
  dont le panel ne dit pas qu'elle est facultative compte), comptées comme l'onglet Contrôle :
  rendues **avec leur photo**, notées, non conformes (note sous le seuil, 4), à contrôler, et les
  noms de celles qui manquent. Depuis le 06/10/2026, une tâche cochée sans photo n'est plus rendue :
  elle se compte à part (`sansPhoto`, `sansPhotoNoms`), ni rendue ni manquante. Le dimanche 4/10 à
  Halle passe ainsi de 9 rendus sur 11 à 0 sur 11, 9 cochés sans photo. Le dashboard applique la
  même règle (tuile Contrôles du téléphone, lignes « Tâches du jour » et « Contrôles en photo »).
  Gardées cinq minutes pour aujourd'hui, une demi-heure pour un jour passé
  (`dsCtrl2:{shop}:{date}`) : les notes du consultant arrivent après coup.
- **Poubelle** : les pièces jetées déclarées et leur coût net, comme `/exploitation/invendus` sur un
  jour (mêmes caches `inv:{shop}:{d}:{d}`, les jours manquants lus au panel en parallèle).
- Les jours à venir sont rendus vides (`futur: true`, `null`), sans appel au panel ; un jour dont le
  panel n'a jamais répondu : `controles: null`.

Côté écran, les couleurs suivent des seuils réunis dans `MA` (public/dashboard/dashboard.js) : chiffre
≥ 100 % de l'objectif vert, ≥ 90 % orange ; résultat ≥ 15 % des ventes vert, ≥ 5 % orange ; matière
≤ 35 % vert, ≤ 45 % orange ; main-d'œuvre ≤ 20 % vert, ≤ 25 % orange ; poubelle ≤ 1,5 % du chiffre
vert, ≤ 3 % orange ; contrôles tous rendus vert, ≥ 80 % orange ; clients ≥ 95 % de J−7 vert, ≥ 85 %
orange. Une journée en cours ne passe pas au rouge : gris tant qu'elle n'est pas finie.

### `/exploitation/invendus` — les invendus et la poubelle

`GET /exploitation/invendus?shop=4&date=2026-10-02` (le jour), `?shop=4&du=&au=` (la semaine, le mois), sans `shop` le
réseau magasin par magasin (`?periode=jour|7|30` pour le cockpit : `magasins[]` avec `ca` et `part` = coût ÷ CA lu en
une fois sur `/consultant/shops/sales-kpis`, `produits[]` additionnés d'un magasin à l'autre — trente au plus, avec
`parMagasin` — `parMotif` du réseau, et `reseau` : `cout`, `part`, `pieces`, `caPerdu`, `declarent` sur `magasins`).
L'écran cockpit **Magasins › Invendus et poubelle** (`#/invendus-poubelle`) le lit tel quel. Source unique, mesurée le 03/10/2026 : `GET /shops/{id}/products/waste?date_from=&date_to=`
du panel, qui rend par produit les pièces jetées (`waste_qty`), le coût de recette de ces pièces (`recipe_waste_gross`,
**TTC** : 0,725 € le croissant pour 0,684 € net), la valeur de vente perdue (`ca_waste_net`) et le motif dominant
(`top_reason` : `expiration`, `damage`, `tasting`, `quality`). Le filtre par motif n'existe pas (mesuré : `reason=`
ignoré), seul le motif dominant d'un produit est connu.

```json
{ "shop": 4, "magasin": "Atelier by - Halle", "lu": true, "declare": true, "date": "2026-10-02",
  "pieces": 41, "cout": 89.83, "coutBrut": 95.23, "caPerdu": 297.96, "references": 2, "coutSource": "panel",
  "produits": [ { "pid": 1300017, "nom": "Sandwich (10 + 5)", "categorie": "Petite Boulangerie", "pieces": 27, "cout": 80.26, "coutBrut": 85.08,
                  "caPerdu": 274.16, "motif": "expiration", "motifLib": "fin de journée", "motifPieces": 27, "vendus": 30, "taux": 47.4 } ],
  "parMotif": [ { "motif": "expiration", "lib": "fin de journée", "pieces": 41, "cout": 89.83 } ],
  "report": { "dispo": false, "motif": "Les reports au lendemain ne se lisent pas dans le panel : …" }, "source": "…" }
```

`lu` est faux quand le panel ne répond pas (rien n'est alors retranché du résultat) ; `declare` est faux quand la
réponse est vide — mesuré : Corbais et Sombreffe ne déclarent rien, Halle et Gosselies déclarent. `cout` est le coût
de production **net** : le coût de recette du panel pour ce magasin × pièces quand il existe et tient face au brut
(entre 70 % et 100 %), sinon le brut ÷ 1,06. Mesuré : le panel rend un brut de 0 sur un tiers des références de
Gosselies (Couque au Beurre, Pain Doré…) : elles sont prises au coût de recette actuel (`auCatalogue`), et celles qui
n'ont de coût nulle part comptent zéro et sont comptées (`sansCout`). `caPerdu` : la valeur du panel quand il la
chiffre, sinon pièces × prix de vente du magasin (`perduCatalogue`) — mesuré : sur une fenêtre d'un jour le panel
rend 0,00 là où la semaine rend 274,16 € pour les mêmes pièces. En vue Jour, `vendus` et `taux` (jetées ÷ (jetées +
vendues)) viennent du relevé gravé des ventes du jour, sans lecture de plus. Cache `inv:{shop}:{du}:{au}` : dix minutes quand la fenêtre
touche aujourd'hui ou hier, six heures sinon ; un panel muet ressert la dernière lecture.

**Les reports au lendemain** (« carryover ») n'ont pas de route de lecture : la caisse les écrit comme une production du
matin (`POST /product-movements`, motif `carryover`, lu dans le journal `product_movement` de la base partagée qui
s'arrête à la mi-juillet) et le document OpenAPI du panel (`/swagger/openapi.json`, 933 routes) n'en expose aucune
lecture. L'écran le dit (`report.motif`) plutôt que de les inventer.

**Le détail des saisies** (demande du 09/10/2026) : `GET /exploitation/invendus/detail?shop=4&date=` (ou `&du=&au=`) rend,
pour un magasin, le même total par produit que la carte (`produits`, `pieces`, `cout`, `caPerdu`, `references`,
`parMotif`) et chaque saisie de caisse — `saisies[]` : `le`, `heure`, `operateur` (nom court « Prénom N. » pris sur
`/shops/{id}/employees`, gardé un jour sous `inv:ops:{shop}`), `operateurId`, `pid`, `produit`, `categorie`, `pieces`,
`motif`, `motifLib`, `source` — dans l'ordre du temps, avec `saisiesPieces`, `parOperateur[]` et `parHeure[]`. Les
saisies viennent du journal `product_movement` de la base partagée (`movement_type = 'WASTE'`), le seul endroit qui
porte l'heure et l'opérateur : le panel ne rend qu'un total par produit (mesuré le 09/10/2026 : ni `group_by`, ni
route de mouvements, ni « evidence » sous `/shops/{id}`). `journal` dit jusqu'où il va (`derniere`, gardée dix minutes
sous `inv:journal:{shop}`), s'il couvre la fenêtre (`couvre`) et, sinon, pourquoi les saisies manquent (`motif`) —
mesuré : il s'arrête au 14/07/2026 pour chaque magasin. Le dashboard magasin l'ouvre dans une modale depuis la ligne
« − Invendus et poubelle » du P&L court et depuis la carte « Invendus et poubelle ». La réponse porte aussi
`remarques[]` (ci-dessous) et `motifsQualite` (`quality`, `damage`).

**Agir sur un problème de qualité** (demande du 09/10/2026). Sur une ligne au motif `quality` ou `damage`, la modale
propose deux actions. `GET /exploitation/invendus/actions?shop=4&date=&pid=` rend de quoi les préparer :
`recette.lignes[]` (les matières du produit, par `rpApi`), `reclamation` (`candidates[]` : les matières de la recette
qui sont chez un fournisseur réclamable, `acheteFini` quand la recette n'a qu'une matière — un produit acheté fini —,
`matieres[]`, `fournisseurs[]`, `livraisons[]`, `motifs[]` et `motifSuggere`, pris sur les références réclamables
gardées dix minutes sous `rc:refs:{shop}` ; `indispo` quand le compte consultant manque), `producteur` (qui a déclaré
la production de la référence ce jour-là dans le journal, `null` sinon), `operateurs[]` (id, nom court) et
`remarques[]` déjà faites sur ce produit ce jour. La réclamation part par `POST /fournisseurs/reclamation`, la même
route que le téléphone (sans photo ; au téléphone, un lien ouvre le formulaire complet déjà rempli). La remarque va à
`POST /equipe/remarques` `{shop, employeId | employeNom, texte, le, heure, pid, produit, pieces, motif, saisieId,
auteur}` → `{ok, id, remarque}` (422 sans texte ou sans personne), gardée dans `ceo_operateur_remarque` — les
évaluations des opérateurs — et journalisée (« Équipe »). `GET /equipe/remarques?shop=4[&employe=][&du=&au=]` les
relit (`remarques[]`, `parOperateur[]`).

**Dans le P&L.** `GET /exploitation/jour` et `GET /exploitation/periode` portent sur chaque magasin et sur le réseau
`invendus` (coût net, `null` si le panel est muet), `invendusPct`, `invendusPieces`, `invendusDeclare`,
`invendusSource`, et le résultat les retranche : `net = CA − coût matière − invendus − main-d'œuvre − frais généraux`
(demande du 03/10/2026). Rien de déclaré vaut zéro ; un panel muet ne retranche rien et le dit. La série du mois
(`serie[].net`) ne les porte pas : une fenêtre par jour coûterait trente lectures par magasin.

### `/planogramme/standard` — le comptoir standard : un seul plan, ses moments, ses rotations

Un seul plan pour tous les magasins. La géométrie est fixe (`src/plano_std.php`) : 25 sections de 30 cm, sections 1–2 =
caisse ; zones `z0` sec mortex blanc (1–8), `z1` frigo (9–12), `z2` sec mortex (13–16), `z3` frigo (17–20), `z4` sec
table bois (21–25) ; niveaux `e3` top picking, `e2`, `e1b` étage 1 arrière, `e1a` étage 1 avant (les frigos et la table
bois n'ont que l'étage 1) — 66 emplacements. Les produits viennent du catalogue (API panel) ; un emplacement porte **un
produit par moment** de la journée : `matin` (jusqu'à 10 h), `midi` (11–14 h), `aprem` (dès 15 h), les mêmes heures que les
ventes du dashboard. Un produit « toute la journée » tient les trois moments.

`GET /planogramme/standard?date=2026-09-20` (sans `date` : aujourd'hui ; `GET /planogramme` est un alias) :

```json
{ "date": "2026-09-29",
  "layout": { "sections": 25, "largeurCm": 30, "caisse": [1, 2],
              "zones": [ { "id": "z1", "nom": "Frigo sandwichs", "nomDefaut": "Frigo", "type": "frigo", "mat": null, "de": 9, "a": 12 } ],
              "niveaux": [ { "k": "e3", "nom": "Étage 3", "court": "É3", "sub": "Top picking · 10 cm", "cm": 10 } ],
              "periodes": [ { "k": "matin", "nom": "Matin", "court": "M", "de": 0, "a": 10 } ] },
  "emplacements": [ { "cle": "3|e2", "section": 3, "niveau": "e2", "zone": "z0", "journee": false, "moments": 2,
      "occupants": [ { "periodes": ["matin", "aprem"], "journee": false, "ref": "1610006", "nom": "Croissant", "groupe": "Viennoiserie",
                       "qte": 24, "photo": null, "crop": null, "du": "2026-09-29", "par": "CEO", "le": "2026-09-29 19:12:15" },
                     { "periodes": ["midi"], "journee": false, "ref": "1620004", "nom": "Mini - Rhubarbe", "qte": 18, "…": "…" } ],
      "ref": "1610006", "nom": "Croissant", "qte": 24, "…": "le produit principal (celui qui tient le plus de moments)" } ],
  "totaux": { "emplacements": 66, "remplis": 65, "partages": 1, "sansQuantite": 2, "unites": 924 },
  "modifie": { "le": "2026-09-29 19:12:20", "par": "CEO" }, "enVigueurDepuis": "2026-09-29", "source": "…" }
```

`qte` est ce que l'emplacement contient plein ; c'est elle qui fait les rotations. `photo` est une photo déposée au
cockpit (sinon la photo de la recette du panel), `crop` son recadrage carré `{x, y, s}`.

**Écrire** (chaque écriture rend le plan complet, comme le `GET`) :

| Route | Corps | Effet |
|---|---|---|
| `PUT /planogramme/standard/emplacement` | `{section, niveau, periodes?, ref, nom?, groupe?, qte?, crop?}` | pose `ref` sur les moments `periodes` (`"journee"` ou absent = les trois, sinon `["midi"]`, `["matin","aprem"]`…). Les produits déjà là cèdent ces moments et gardent les autres ; un produit déjà posé ailleurs n'est pas retiré. |
| idem | `{section, niveau, periodes?, qte?, crop?}` sans `ref` | règle le produit qui tient exactement ces moments, ou le seul qui les croise ; `409` si l'emplacement est ambigu ou vide. |
| idem | `{section, niveau, periodes?, ref: null}` | vide l'emplacement sur ces moments. |
| `POST /planogramme/standard/photo` | `{section, niveau, periodes?, data}` | photo en data-URL (jpeg, png, webp, 6 Mo) ; `data: ""` revient à la photo du panel. |
| `PUT /planogramme/standard/zone` | `{zone: "z1", nom}` | renomme une zone (vide = nom par défaut). |
| `POST /planogramme/standard/vider` | `{confirmer: true}` | vide tout le plan (historique gardé). |

**Historique.** Chaque ligne a `du` / `au`. Changer de produit ou de quantité ferme la ligne en cours à la veille
(`au`) et en ouvre une nouvelle datée du jour ; une ligne ouverte le jour même est modifiée sur place. La photo et le
recadrage se modifient sur place. `?date=` relit le plan d'un jour passé ; les rotations d'un jour lisent le plan de ce
jour-là.

`GET /planogramme/standard/photos?refs=1610006,1620004` — la photo de recette du panel par référence, téléchargée une
fois sous `uploads/plano/panel/` : `{ "photos": { "1610006": { "url": "uploads/plano/panel/1610006.jpg", "nom": "…" } },
"restants": 0 }`. 24 références par appel ; `restants` dit combien il en reste à télécharger.

`GET /planogramme/standard/ventes?jours=14` — les unités vendues au comptoir par référence sur le réseau (le sélecteur de
produits les classe par ventes).

**Les rotations.** `GET /planogramme/rotations?shop=2&jours=7&au=2026-09-28` (`shop=reseau` pour tous les magasins ;
`jours` 7, 14 ou 30 ; `au` par défaut hier) :

```json
{ "du": "2026-09-22", "au": "2026-09-28", "jours": ["2026-09-22", "…"], "simulation": false, "appels": 0,
  "periodes": [ { "k": "matin", "nom": "Matin", "court": "M", "de": 0, "a": 10 } ], "sansQuantite": ["8|e3"],
  "magasins": [ { "id": "2", "nom": "Atelier by Berlo - Corbais", "court": "Corbais",
      "ouverts": ["2026-09-22"], "nonLus": [], "fermes": [], "sansB2b": ["2026-09-26"], "comptoir": 0.74, "horsComptoir": 29.5,
      "sections": { "5": { "zone": "z0", "capacite": 74, "jours": { "2026-09-22": 0.85, "2026-09-26": null }, "moyenne": 1.04 } },
      "emplacements": { "3|e2": { "ref": "1610006", "qte": 24, "vendus": { "2026-09-22": 31 }, "vendusMoyen": 29.4, "rotation": 1.62,
          "blocs": [ { "ref": "1610006", "periodes": ["matin", "aprem"], "journee": false, "qte": 24, "jours": { "2026-09-22": 22 },
                       "vendusMoyen": 21.0, "rotation": 0.88 } ] } } } ],
  "reseau": { "sections": { "5": 1.04 }, "comptoir": 0.74 }, "source": "…" }
```

- **Ventes comptées.** Les unités vendues au comptoir, relevé horaire du panel (`svP`). Le B2B est retiré : les unités
  des tickets pro (`pb` dans le relevé) sont ôtées produit par produit, au prorata de chaque heure. Un jour dont le pro
  n'est pas encore lu (`sansB2b`) est laissé hors de la moyenne ; le cron le complète (`svB2bMoisson`, « produits pro »
  dans la sortie de `svCron`).
- **Rotation d'un produit** = unités vendues pendant les heures de ses moments ÷ `qte`. Celle d'un emplacement =
  la somme de ses produits ; celle d'une section = la moyenne des emplacements pondérée par leur capacité
  (`qte` × moments tenus / 3) ; celle du comptoir, idem sur tout le plan.
- **Plan lu.** Chaque jour se calcule avec le plan en vigueur ce jour-là. Si aucun plan n'existait sur la fenêtre,
  le plan actuel sert de simulation (`simulation: true`) ; `plan=actuel` force ce mode.
- `comptoir` : la rotation moyenne du comptoir du magasin sur ses jours lus (au réseau : la moyenne des magasins) ;
  `horsComptoir` : la part (en %) des unités vendues au comptoir qui sont des produits absents du plan.

**La proposition d'après les ventes.** `GET /planogramme/standard/proposition?jours=28&rotation=1.5` (`jours` 14, 28
ou 56 ; `rotation` 1, 1,5, 2 ou 3) propose, emplacement par emplacement et moment par moment, les produits et les
quantités d'après les ventes moyennes au comptoir :

- **Structure gardée.** Chaque emplacement garde sa famille : le groupe du produit qui l'occupe aujourd'hui (un
  emplacement libre prend celle de son voisin d'étage). La proposition ne déplace ni le frigo ni la boulangerie.
- **Ventes.** Unités vendues au comptoir (clients pro retirés) pendant les heures du moment, en moyenne par magasin et
  par jour ouvert lu sur la fenêtre (relevés `svP` gravés, aucun appel au panel).
- **Choix.** Dans chaque famille et pour chaque moment, les meilleures ventes prennent les emplacements. Un produit en
  place ne sort que pour un produit qui vend nettement plus (au moins 30 % et une demi-unité par magasin et par moment
  de plus) ; les nouveaux prennent les emplacements les plus visibles (étage 1 avant, arrière, étage 2, étage 3). Un
  lissage garde le produit du moment voisin quand l'écart ne compte pas. Hors comptoir : boissons chaudes, bundles, B2B,
  extras, glaces et yaourts. Les obligatoires ne sont pas forcées : celles que les ventes laissent dehors sont listées.
- **Quantité.** Ventes moyennes du moment ÷ `rotation`, arrondie au-dessus, entre un minimum de présentation
  (3 / 4 / 4 : étage 3 / 2 / 1) et un plafond (15 / 30 / 50). Un produit sur plusieurs moments prend la quantité de son
  moment le plus fort.

Réponse : `emplacements[]` (`cle`, `famille`, `actuel[]`, `propose[]` avec `periodes`, `qte`, `vendus` par moment,
`plafonne`, `faible`, `obligatoire`, et `statut` : `identique`, `quantite`, `change`, `libre`), `resume` (`changes`,
`quantites`, `identiques`, `unitesActuel`, `unitesPropose`, `faibles`), `entrants[]`, `sortants[]`, `absents[]` (meilleures
ventes sans place et pourquoi), `obligatoires` (`exigees`, `placees`, `dehors[]`), `joursLus`, `regle`.

`POST /planogramme/standard/proposition` — `{jours, rotation, cles: ["3|e1a", …]}` recalcule la proposition avec les
mêmes paramètres et l'applique aux emplacements donnés (les `identique` sont sautés), avec l'histoire du plan : ce qui
change aujourd'hui ferme la version d'hier. Rend le plan (comme `GET /planogramme/standard`) et `appliques`.

**Le montage en magasin (tablette).** La page `planogramme/?shop=2` montre le plan zone par zone au moment de la journée
(choisi à l'heure de la tablette). `GET /planogramme/standard/montage?shop=2&date=2026-09-29` →
`{ "zones": { "z0": { "photo": "uploads/plano/montage/…jpg", "auteur": "Tablette", "quand": "2026-09-29 07:42:10" } } }` ;
`POST /planogramme/standard/montage` `{shop, zone: "z0", date?, data, auteur?}` pose la photo de la zone montée
(`data: ""` la retire). La tâche du panel « Photo du comptoir - <zone> » est retrouvée par le nom de la zone.
`GET /visites/conformite` lit ce plan : emplacements tenus, zones photographiées montées le jour même.

### `/production/saisons` et `/production/obligatoire` — l'assortiment obligatoire, toute l'année ou par saison

Une référence obligatoire l'est **toute l'année**, ou **pendant une saison** : une gamme saisonnière du panel (Noël,
Automnale, Saint-Nicolas…), récurrente chaque année (seuls le jour et le mois comptent, la fin est comprise). Pendant
sa saison elle est exigée comme les autres ; hors saison elle n'est ni exigée ni comptée manquante. Une gamme qui
couvre l'année (Standard, B2B) n'est pas une saison. Un produit n'est **saisonnier** que s'il est dans une gamme
saisonnière et dans aucune gamme permanente.

Les dates des gammes viennent de la base partagée (`product_availability_period`) ; leur contenu (quels produits)
seulement de l'API du panel (`/product-availability-periods/{id}/products`, la table de liaison de la base est
vide), lu pour toutes les gammes et gardé 12 h (réglage `assortimentGammes`). Une gamme que le panel ne rend pas
garde sa dernière lecture réussie.

`GET /production/saisons[?date=2026-09-29][&rafraichir=1]` :

```json
{ "aujourdhui": "2026-09-29",
  "saisons": [ { "id": 8, "emoji": "🎄", "nom": "Noël & Nouvel An", "nomPanel": "🎄 Gamme Noël & Nouvel An (Décembre-Janvier)",
                 "debut": "11-01", "fin": "01-15", "recurrente": true,
                 "fenetre": { "du": "2026-11-01", "au": "2027-01-15", "ouverte": false, "jours": 33 },
                 "produits": ["4100001", "4100002"], "lu": true, "alerte": null, "obligatoires": 4 } ],
  "permanentes": [ { "id": 14, "emoji": "🥖", "nom": "Standard", "produits": 363 } ],
  "obligatoires": { "annee": 12, "parSaison": { "6": 4, "8": 4 }, "exigibles": 16, "saisonInconnue": 0 },
  "alertes": [ "🍦 Glace commence le 04/01 alors que son nom dit « avril » : jour et mois inversés au panel ?" ],
  "contenuLe": "2026-09-29 20:09:59", "contenuSource": "api", "source": "…" }
```

Les saisons ouvertes d'abord (celle qui ferme le plus tôt en tête), puis celles qui ouvrent. `fenetre` est la saison
en cours, sinon la prochaine ; `jours` compte jusqu'à la fermeture (ouverte) ou l'ouverture (fermée). `alertes` signale
une date de début dont le jour et le mois semblent inversés par rapport au nom, une gamme dont le panel ne rend pas
les produits, et les obligatoires rattachées à une saison disparue (plus exigées).

`PUT /production/obligatoire/{ref}` — `{ "must": true, "saison": 8 | null, "qmin": 4 }` : ne touche que l'obligatoire,
sa saison et son minimum (la fiche de production n'est pas réécrite). `saison: null` = toute l'année ; une gamme
permanente vaut toute l'année ; une saison inconnue → `422`. `must: false` retire la référence (minimum et saison
remis à zéro). `qmin` absent garde le minimum en place ; hors de 0..9 999 → `422`. Réponse :
`{ ok, ref, must, qmin, saison, exigible, saisonFenetre }`. `PUT /production/produit/{ref}` (la fiche) garde la saison,
et l'efface quand elle décoche l'obligatoire.

**Le catalogue** (`GET /production/catalogue`) ajoute à chaque produit : `saisons` (gammes saisonnières du produit),
`standard` (il est aussi dans une gamme permanente), `saisonnier`, `saison` (celle de son obligatoire, ou `null`),
`exigible` (obligatoire exigée aujourd'hui) et `saisonFenetre` ; `periods` porte les noms des gammes quand la base ne
les donne pas.

**Les visites** (`GET /visites/conformite`) ne comptent que les obligatoires exigées : au dernier jour de vente pour
l'assortiment, aujourd'hui pour les obligatoires sans place au comptoir. `assortiment` ajoute `saisonnieres` (exigées
de saison) et `horsSaison` (obligatoires de saison non exigées à cette date).

### `/tablette/book` et `/tablette/photos` — les données de la tablette des vendeuses

La tablette « Book vendeuses » (`public/tablette/`, application compilée du dépôt `pwa_sales_tablet`) lit ses produits,
catégories et saisons au BO ; le reste (allergènes UE, FAQ, services, réflexes de vente, statistiques) reste embarqué.
Code : `src/tablette.php`. Le lien d'un magasin est `tablette/?shop=<id>` (+ `&lang=nl`, `&prices=0`) ; la tablette
garde `shop` sur l'appareil.

`GET /tablette/book?shop=4[&ensemble=comptoir|tout][&rafraichir=1]` (sans `shop`, ou magasin inconnu : le réseau) :

```json
{ "schema": 1, "version": "27f01940c216303f7932a9a5042b07d5d5d4f188", "genereLe": "2026-10-02T09:12:00+02:00",
  "shop": { "id": "4", "nom": "Halle" }, "ensemble": "comptoir",
  "book": {
    "categories": [ { "id": "g-viennoiserie", "n": ["Viennoiserie", "Viennoiserie"] } ],
    "seasons": [ { "id": "s8", "img": "img/s/christmas-new-year-range.png", "m": [11, 12, 1], "n": ["Noël & Nouvel An", ""],
                   "dates": ["1er novembre au 15 janvier", "1 november t/m 15 januari"], "tip": ["", ""] } ],
    "products": [ { "id": "1610006", "cat": "g-viennoiserie", "season": "s8", "img": "uploads/tablette/1610006-640c.jpg",
                    "price": 1.3, "unit": ["pièce", "stuk"], "best": true, "name": ["Croissant", ""], "desc": ["", ""],
                    "pitch": ["", ""], "ingr": ["", ""], "al": ["gluten", "oeufs", "lait"], "tr": [], "alKnown": true,
                    "trKnown": false, "alRaw": "", "diet": null, "keep": ["Conservation : Comptoir Frigo - 1 (2°C – 4°C).", ""],
                    "dlc": 1, "cross": ["1700012"], "crossLine": ["", ""],
                    "combos": [ { "avec": ["Boissons chaudes", ""], "quand": ["Matin (avant 11 h)", "Ochtend (voor 11 u)"],
                                  "nom": ["", ""], "cible": 7.5, "ids": [] } ] } ] },
  "manque": { "total": 98, "photos": 39, "nl": 98, "allergenes": 43, "descriptions": 98 },
  "photosRestantes": 12,
  "sources": { "produits": "…", "photos": "…", "best": "…", "saisons": "…", "allergenes": "…" } }
```

- **Textes** : paires `[FR, NL]`, `""` quand le néerlandais n'existe pas (la tablette affiche alors le français). Seuls
  les noms de groupes ont un néerlandais (brouillon `TB_GROUPES_NL`, à faire valider) et les saisons dont le panel porte
  un alias `nl`. `pitch`, `ingr`, `tip`, `crossLine` sont vides : aucune source n'existe.
- **Vente additionnelle** (`combos`, `cross`) : les combos du réseau (écran Croisements, table `ceo_combo`) dont le
  produit fait partie de A — même règle que l'écran : groupe, catégorie ou produit exacts. Pour chacun : `avec` = B en
  toutes lettres (« (groupe) » retiré), `quand` = le moment (`matin`, `midi`, `apresmidi` ; vide = toute la journée),
  `nom` = le surnom du combo (vide quand ce n'est que « A × B »), `cible` = la target d'attache en % (ou `null`), `ids`
  = les produits de B présents au book, meilleures ventes d'abord, 4 au plus, le produit lui-même exclu (vide quand B
  est hors comptoir, comme les boissons). `cross` = ces produits réunis, sans doublon. Sans combo : `[]`.
- **Produits** : `ensemble=comptoir` (défaut) = les produits du planogramme standard + ceux des gammes saisonnières
  ouvertes ou qui ouvrent sous 45 jours + les obligatoires exigées ; planogramme vide → tout le catalogue actif.
  `tout` = tout le catalogue actif. `id` = l'id produit du panel (`pwaId`). Triés par catégorie puis par nom.
- **Catégories** : le groupe du BO (`product_category_group`, le premier quand la catégorie en a deux), sinon la
  catégorie ; `id` = `g-` + le nom sans accents. Dans l'ordre Viennoiserie, Boulangerie, Pâtisserie, Tartes, Quiches,
  Traiteur, Biscuiterie, Épicerie, Boissons, Fêtes & Occasions, Bundle & Promotion, B. 2 B., puis les autres.
- **Saisons** : celles de `/production/saisons` (actives, ni permanentes ni terminées), dans l'ordre du calendrier (date
  de début). `id` = `s` + l'id de la gamme ; `m` = ses mois, du premier au dernier, Nouvel An passé ; `dates` écrites en
  toutes lettres ; `img` = une illustration embarquée dans la tablette choisie par mot-clé du nom (Noël, Saint-Nicolas,
  Épiphanie, Saint-Valentin, Pâques, Fête des mères, Glace/Estivale, Automne, Hiver), sinon `""`. `season` d'un produit
  = sa gamme ouverte, sinon celle qui ouvre le plus tôt ; la clé est absente s'il n'en a aucune au book.
- **`price`** : le prix réseau du catalogue (`prix` : saisi au BO, sinon moyenne des boutiques, sinon prix conseillé),
  `null` s'il n'y en a pas. **`unit`** : le poids de la fiche (`600 g`, `1,2 kg`), sinon `pièce`/`stuk` pour un produit
  vendu à la pièce, sinon vide. **`best`** : les 8 meilleures ventes au comptoir du magasin sur 28 jours (relevés
  quotidiens `svP`, aucun appel au panel), parmi les produits du book ; sans magasin ou sans vente relevée, celles du
  réseau. **`diet`** : `"vege"` si `product.is_vegetarian`, sinon `null` (aucun indicateur vegan n'existe).
  **`keep`** : le lieu de stockage et sa consigne, puis la réchauffe, en français (« Conservation : Comptoir Frigo - 1
  (2°C – 4°C). Réchauffer 10 min à 180 °C. ») ; jamais la seule température (4 °C par défaut, pain compris). La base
  partagée ne porte que l'id du lieu de stockage : son nom et sa consigne (`storage_name`, `storage_description`)
  viennent de `GET /products` du panel — une lecture pour tout le catalogue, gardée 24 h (`ceo_app_setting`
  `tablettePanelProduits`) ; la réchauffe du panel quand elle est > 0, sinon celle de la base. Panel muet : la dernière
  liste lue, sinon la base seule. Mesuré le 02/10/2026 : 49 des 98 produits du comptoir ont un lieu de stockage nommé au
  panel (les autres pointent des `id_storage` 1, 2, 5, 24 que le panel ne nomme pas). **`dlc`** : jours entiers,
  arrondis vers le bas à une heure près (1 444 min = 1) ; moins de six heures, ou inconnue → `0` (« immédiat »). Durée
  du catalogue du BO, sinon `product.shelf_life_minutes`, sinon celle du panel (`GET /products`).
- **Allergènes** : `al` ne porte que les 14 identifiants UE (`gluten`, `crust`, `oeufs`, `poisson`, `arach`, `soja`,
  `lait`, `noix`, `celeri`, `moutarde`, `sesame`, `sulfites`, `lupin`, `mollusques`). Deux sources, dans l'ordre :
  1. **`product.allergene`**, s'il se lit EN ENTIER comme une liste d'allergènes nommés (français, néerlandais ou
     anglais) : il fait foi, `alKnown: true`, `alRaw` = le texte. Mesuré le 02/10/2026 : vide partout.
  2. Sinon **la recette du produit au panel** (`product.id_recipe`, à défaut celui de `GET /products`) :
     `GET /recipes/{id}`, ses sous-recettes `GET /subrecipes/{id}` (récursivement, 6 niveaux au plus, chacune une
     fois — `GET /recipes/{id}` d'une sous-recette rend `200 []`), et chaque matière première `GET /materials/{id}`
     (seule la fiche détaillée porte `allergens: [{id, code, name}]`, pas la liste `/materials`). Le référentiel est
     `GET /allergens` du panel (les 14 codes UE, id → code) : un allergène se reconnaît par son id, puis par son code ;
     `cereals_gluten` → `gluten`, `crustaceans` → `crust`, `eggs` → `oeufs`, `fish` → `poisson`, `peanuts` → `arach`,
     `soybeans` → `soja`, `milk` → `lait`, `nuts` → `noix`, `celery` → `celeri`, `mustard` → `moutarde`,
     `sesame_seeds` → `sesame`, `sulphur_dioxide_sulphites` → `sulfites`, `lupin` → `lupin`, `molluscs` → `mollusques`
     (table en dur seulement si le référentiel n'a jamais pu être lu). Les emballages (catégorie de matière
     « … (Emballage) » ; `is_part_of_package` vaut 0 même pour eux et n'est pas lu) ne comptent pas, sauf s'ils portent
     des allergènes. `al` = l'union des allergènes des matières. **`alKnown: true` seulement si** tout l'arbre a été lu
     et date de moins de 24 h, qu'il compte au moins une matière hors emballage, que CHAQUE matière porte une liste
     non vide dont chaque allergène est reconnu (id dans le référentiel, code cohérent, l'un des 14), et que
     `product.allergene` est vide. **Une liste vide veut dire « jamais saisie », pas « sans allergène »** (mesuré : les
     œufs, le beurre, les fromages et le sel du traiteur ont une liste vide). Sinon `alKnown: false`, `al` garde les
     allergènes connus (la tablette les montre « contient », le reste « à vérifier ») et `alRaw` dit pourquoi, en
     français : « Allergènes non renseignés au panel pour : Fondant chocolat. », « Recette du panel pas encore lue en
     entier. », « Recette introuvable au panel. », « Recette du panel sans matière première. », « Allergène du panel
     non reconnu : … » — précédé du texte de `product.allergene` s'il en a un qu'on ne sait pas lire.
  3. Sans recette : `al: []`, `alKnown: false`, `alRaw` = « Pas de recette au panel. ».

  `alRaw` est vide quand `alKnown` vient de la recette. Les traces n'existent nulle part : `tr: []`,
  `trKnown: false`. **`alKnown: false` interdit à la tablette d'afficher le produit « sans » un allergène.**
  Recettes, sous-recettes, matières et référentiel sont gardés 24 h par entrée (`ceo_app_setting`
  `tabletteAllergenes` = `{reference: {codes, le}, recettes: {id: {nom, materiaux: [{id, nom, cat}], sous, le}},
  sousRecettes: {…}, materiaux: {id: {nom, cat, allergenes: [{id, code}], le}}}`, entrées inutilisées oubliées après
  30 jours). Chaque calcul du book relit au panel ce qui manque ou a plus de 24 h, six requêtes de front, 8 s au plus
  (`GET /products` à part, 6 s au plus) : ce qui n'est pas lu à temps reste « à vérifier » et sera lu au calcul suivant
  — un book ainsi incomplet (ou calculé panel muet) ne se garde que 30 minutes au lieu de six heures. Le temps qui
  reste relit d'avance les entrées de 18 à 24 h (heure étalée par entrée), pour que le cache n'expire jamais d'un bloc.
  Un premier calcul à froid (~220 lectures pour les 98 produits du comptoir) en demande deux. Une panne du panel ne
  casse jamais le book : les allergènes déjà connus restent « contient », tout le reste passe « à vérifier ».
- **`img`** : la vignette `uploads/tablette/<ref>-640c.jpg`, sinon la photo du panel `uploads/plano/panel/<ref>.<ext>`,
  sinon `""` ; chemins relatifs à la racine publique du BO. Les vignettes sont carrées (640 × 640, moins si l'original
  est plus petit ; JPEG 80) : une photo qui ne l'est pas est posée entière au milieu, ses bords prolongés jusqu'au
  carré — la tablette montre les photos en carré, sans rien couper. Elles se font à
  la lecture, 40 au plus et en 3 s au plus par appel ; la tablette ne déclenche jamais de téléchargement au panel.
- **`manque`** : par produit du book — sans photo, sans nom néerlandais, allergènes à vérifier (`alKnown: false`,
  partiels compris), sans description ; `total` = produits à qui il manque au moins une des quatre. **`photosRestantes`** : photos à lire au
  panel (jamais lues, fichier perdu, absence notée il y a plus de sept jours) + vignettes pas encore faites — ce que
  `POST /tablette/photos` ferait.
- **`sources`** : une phrase par source (`produits`, `photos`, `best`, `saisons`, `allergenes`) — ce qui a été lu, ou
  pourquoi c'est vide. `produits` dit la couverture de `keep` et `dlc` (« conservation : 49/98 (dont 48 depuis le
  panel) ») ; `allergenes` compte les produits complets, partiels, sans allergène connu et sans recette, nomme les
  matières sans allergènes saisis qui bloquent le plus de produits, et dit ce qui a été lu au panel (référentiel
  `/allergens`, cache, lectures, temps, lectures remises). Une source muette laisse ses champs vides et le dit ici ; le
  book répond quand même `200`.
- **Cache** : le book est calculé une fois pour six heures (`ceo_app_setting` `tabletteBook:<shop|reseau>:<ensemble>`),
  trente minutes si ses lectures au panel n'ont pas abouti ; `rafraichir=1` le recalcule (et relit au panel les
  recettes, matières et fiches de plus de 24 h, pas les autres). Les photos sont relues à chaque appel : une photo arrivée change `version`, que la tablette
  compare pour se recharger. `version` = sha1 du contenu de `book`.
- **Erreur** : catalogue illisible ou vide → `500` `{ "erreur": "book indisponible : …" }` (la tablette garde ses données
  d'exemple) ; base injoignable → `503` du contrôleur frontal (`{ "error": … }`).

`POST /tablette/photos` — `{ "shop": "4" | null, "ensemble"?: "comptoir" | "tout" }` → `{ "faites": 40, "restants": 69,
"manquantes": 78 }`. Pour les produits du book de ce magasin : au plus 24 photos de recette lues au panel (même chaîne
et même table `ceo_plano_std_photo` que le planogramme, mais la recette est lue par l'`id_recipe` du catalogue,
`PanelApi::recipePhotos`, sans passer par l'assortiment d'un magasin), téléchargées sous `uploads/plano/panel/`, puis
leurs vignettes (80 au plus, 25 s au plus). `faites` = références traitées par l'appel (photo lue, absence notée pour
sept jours, ou vignette faite) ; `restants` = ce qu'un appel suivant ferait encore ; `manquantes` = produits toujours
sans photo. L'écran BO rappelle tant que `restants > 0`. Sans compte panel configuré alors qu'il reste des photos à
lire → `503` `{ "error": "compte API du panel non configuré…", "faites", "restants", "manquantes" }`. La tablette ne
l'appelle jamais.

### `/tablette/objectifs` — les objectifs de l'accueil de la tablette

`GET /tablette/objectifs?shop=4[&date=2026-10-02][&rafraichir=1]` (sans `shop`, ou magasin inconnu : le réseau,
`shop: null` ; `date` absente, future ou illisible → aujourd'hui). Code : `src/tablette_objectifs.php`.

```json
{ "schema": 1, "genereLe": "2026-10-02T20:15:00+02:00", "date": "2026-10-02", "shop": { "id": "4", "nom": "Halle" },
  "ca": { "semaine": { "du": "2026-09-28", "au": "2026-10-04", "realise": 7472.3, "objectif": 13962.82, "attendu": 9255.18 },
          "mois":    { "du": "2026-10-01", "au": "2026-10-31", "realise": 3572.45, "objectif": 61000, "attendu": 3694.64 } },
  "venteAdd": { "semaine": { "parTicket": 2.41, "cible": null, "tickets": 482 },
                "mois":    { "parTicket": 2.38, "cible": null, "tickets": 121 } },
  "sources": { "ca": "Résultat › Semaine et › Mois du BO (…) ; semaine : objectif au budget validé ; …",
               "venteAdd": "Articles par ticket = lignes de caisse ÷ tickets (…) ; semaine : 4/4 jours moissonnés ; …" } }
```

- **`ca`** : les chiffres de Résultat › Semaine et › Mois (`GET /exploitation/periode`, ceux du dashboard magasin), pas
  recalculés. Semaine = lundi → dimanche qui contient `date` ; mois = son mois calendaire. `realise` = CA **TTC**
  encaissé (ventes caisse après remise : `ca` du margin-heatmap du panel, = `income` du daily-summary, taxe comprise) du
  début de l'étendue jusqu'à `date` incluse. `objectif` = celui de l'étendue entière : le budget validé du mois
  (`ceo_shop_month_perf.revenue_budget`), à défaut le CA théorique de l'étude (`ca_theorique`), réparti jour par jour par
  la pondération réseau sur les jours où le magasin ouvre — une semaine à cheval sur deux mois prend chaque jour dans le
  budget de son mois. `attendu` = la part de cet objectif des jours jusqu'à `date` comprise. Pour une `date` passée,
  réalisé et attendu se relisent dans les `jours` de Résultat (quelques centimes d'arrondi). Réseau : la ligne `reseau`
  de Résultat (objectif et attendu sur les seuls magasins qui en ont un). `objectif`/`attendu` `null` : pas de
  pondération adoptée, ou ni budget ni CA théorique ; les trois `null` : ventes non lues, vue en panne, magasin inactif.
- **`venteAdd`** — « articles par ticket » (décision du 02/10/2026), la mesure de Ventes › primes, lue dans la moisson
  horaire des tickets du panel (`/transactions/{id}?include=products`, gravée par jour et par magasin :
  `ceo_app_setting` `pvL<magasin>:<jour>`, voir `panel_ventes.php`) ; aucun appel au panel. Une **ligne** = un produit
  encaissé sur le ticket, quelle que soit sa quantité (trois croissants sur une ligne comptent 1) ; tous les tickets du
  magasin, clients pro compris. `parTicket` = total des lignes ÷ total des tickets (pas une moyenne de moyennes), arrondi
  à 0,01, du lundi ou du 1er jusqu'à `date` incluse — **la moisson s'arrête à la veille** : la journée en cours n'y est
  jamais, et un jour pas encore moissonné manque (rien avant le 01/08/2026). `tickets` = les tickets de cette base (`0`
  si les jours lus n'en ont aucun, `null` si aucun jour n'est lu ; `parTicket` est alors `null`). Réseau = tous les
  magasins actifs. `cible` = la target cross-selling du magasin (`ceo_app_setting.venteCrossTargets`, en lignes par
  ticket, la dernière posée au plus tard le mois de `date` — posée par `POST /ventes/cross-target {shop, target, m}`,
  aucun écran du BO ne la règle aujourd'hui) ; `null` sans target, et toujours `null` pour le réseau (les targets se
  posent par magasin).
- **`sources`** : `ca` = la définition, puis, par vue, la source de l'objectif de CE magasin (budget validé, CA théorique,
  partiel s'il manque un mois) ou pourquoi c'est `null`, et l'heure du calcul ; `venteAdd` = la définition de la ligne,
  les jours moissonnés sur les jours attendus par vue (jours-magasin pour le réseau), la cible ou son absence.
- **Cache** : Résultat lit tout le réseau au panel (~12 s pour les deux vues, mesuré) : elles se calculent une fois pour
  tous les magasins, avec la moisson (une requête groupée), et se gardent 10 min (`ceo_app_setting` `tabletteObjectifs:<date>`), 1 min si une source a manqué ;
  un verrou MySQL sérialise les calculs. Sous PHP-FPM, un calcul complet de moins de 2 h est servi aussitôt et recalculé
  après la réponse ; ailleurs, l'appel qui trouve le cache expiré attend le calcul. `rafraichir=1` recalcule.
- **Erreur** : `500` `{ "erreur": "objectifs indisponibles : …" }` ; une vue en panne laisse ses champs à `null` et le dit
  dans `sources.ca`, l'autre répond ; une moisson illisible laisse `venteAdd` à `null` et le dit dans `sources.venteAdd`.

### `/tablette/remarques` — les remarques des clients saisies sur la tablette

Une vendeuse note au comptoir ce qu'un client a dit ; la tablette l'envoie, l'écran BO « Tablette vendeuses » les
montre (carte « Remarques des clients ») avec une bascule « Traitée ». Code : `src/tablette_remarques.php` ; table
`ceo_tablette_remarque`, créée au démarrage par `installer.php` (`ensureTabletteRemarques`) et dans `sql/schema.sql`.
Contrat figé avec la tablette (`pwa_sales_tablet`, `src/data/remarks.ts`). **Accès** : l'API est ouverte (auth
désactivée en production, comme tout le cockpit) et ces routes n'ajoutent aucun contrôle — le plafond par magasin et
par jour borne ce qu'un appel abusif peut remplir. Même origine que le BO : la tablette est servie depuis
`public/tablette/`, aucun en-tête CORS n'est posé.

`POST /tablette/remarques` (la tablette) — `{ "id": "<uuid>", "shop": "4" | null, "type": "compliment" | "suggestion" |
"reclamation", "texte": "…", "langue": "fr" | "nl", "saisieLe": "2026-10-02T18:40:12+02:00" }` →
`201` `{ "ok": true, "id": "<uuid>" }` ; déjà reçue → `200` `{ "ok": true, "id", "doublon": true }`.

- **Idempotence** : `id` (8-4-4-4-12 hexadécimal, rangé en minuscules) est la clé primaire. Le doublon est reconnu avant
  toute autre règle : un renvoi n'est jamais refusé, même plafond atteint.
- **`400` `{ "erreur": "…" }`** (définitif, la tablette jette la remarque) : `id` absent ou mal formé, `type` hors des
  trois, `texte` vide ou de plus de 1 000 caractères (compté après retrait des espaces de bord ; les caractères de
  contrôle, hors retours à la ligne et tabulations, sont retirés). Seules ces trois erreurs du corps rendent `400`.
- **Le magasin ne fait jamais perdre une remarque** : `null` ou `""` = sans magasin ; un `shop` qui n'est pas un magasin
  connu (`shops`, sinon `ceo_shop`) est accepté (`201`) et gardé sans magasin (`shop_id` NULL), la valeur reçue rangée
  telle quelle dans `shop_brut` (32 caractères au plus) — l'écran BO l'affiche « magasin inconnu (<valeur>) ».
- **Corrigé plutôt que refusé** : `langue` autre que `nl` → `fr` ; `saisieLe` absente, illisible, d'avant 2024 ou dans le
  futur → l'heure de réception. Les dates sont rangées dans le fuseau du serveur.
- **`429` `{ "erreur": "trop de remarques pour ce magasin aujourd’hui (200 au plus par jour)" }`** : 200 remarques déjà
  reçues ce jour (heure de réception) pour ce magasin ; les remarques sans magasin et celles d'un magasin inconnu
  comptent ensemble pour un magasin. La tablette réessaie.
- **`5xx`** : base injoignable (`503` du contrôleur frontal) — la tablette garde la remarque et réessaie.
- **Journal** : une ligne par remarque reçue (`Tablette`, `Remarque client`, le magasin, le type, la longueur et la
  langue — pas le texte du client). L'adresse IP de l'appel est gardée dans la table, jamais rendue.

`GET /tablette/remarques?shop=4&jours=30` (l'écran BO) → `{ "remarques": [ { "id", "shop": { "id": "4", "nom": "Halle" }
| null, "shopBrut": null | "99", "type", "texte", "langue", "saisieLe": "2026-10-02T18:40:12+02:00", "recuLe": "…",
"traitee": false, "traiteeLe": null | "…" } ], "total": 7, "nonTraitees": 5 }`. `shopBrut` (ajout au contrat, lu par le
BO seul) = le magasin envoyé quand il n'était pas connu, alors `shop: null`. Sans `shop` : tous les magasins, et les
remarques sans magasin ou d'un magasin inconnu (seule cette vue les montre). Période : saisies depuis minuit il y a `jours` jours (défaut 30, 366 au plus). Tri : `saisieLe`
décroissante. `remarques` en porte au plus 1 000 ; `total` et `nonTraitees` comptent toute la période. `nom` = le nom
court du magasin. `shop` mal formé → `400` ; magasin inconnu → liste vide.

`PATCH /tablette/remarques/{id}` (l'écran BO) — `{ "traitee": true | false }` → `200` `{ "ok": true }` ; pose ou efface
`traitee_le`, et journalise (`CEO`, `Remarque client`) quand l'état change. `traitee` absent ou non booléen → `400` ;
id inconnu ou mal formé → `404` `{ "erreur": "remarque inconnue" }`.

### `/scoring` — le scoring du trimestre : quatre postes de cinq points par magasin

`GET /scoring?trimestre=2026-T3` (sans `trimestre` : le trimestre en cours) :

```json
{ "trimestre": { "cle": "2026-T3", "annee": 2026, "n": 3, "mois": [7, 8, 9], "du": "2026-07-01", "au": "2026-09-30", "arrete": "2026-09-28",
                 "clos": false, "enCours": true, "court": "T3 2026", "lib": "T3 2026 — juillet → septembre", "prec": "2026-T2", "suiv": "2026-T4" },
  "trimestres": [ { "cle": "2026-T3", "lib": "…", "enCours": true } ],
  "postes": { "google": { "nom": "Note Google", "regle": "…" }, "taches": { … }, "msp": { … }, "budget": { … } },
  "magasins": [ { "id": "5", "nom": "Atelier by Harmonie - Sombreffe", "court": "Sombreffe", "fr": "Harmonie", "rang": 1,
      "postes": { "google": { "v": 4.8, "note": 4.8, "avis": 69, "le": "2026-09-14 19:07", "gele": false },
                  "taches": { "v": 0, "part": 0, "faites": 0, "attendues": 1068, "jours": 89, "manquees": 356, "joursZero": 89 },
                  "msp":    { "v": 4.25, "obtenu": 68, "maximum": 80, "rubriques": { "Accueil": "17 / 20" }, "commentaire": "…", "fichier": "uploads/scoring/msp/5-2026-T3.pdf", "par": "Sam", "le": "2026-09-18 10:12" },
                  "budget": { "v": 4.49, "ratio": 89.8, "ca": 144790, "budget": 161280, "mois": 3 } },
      "total": 13.54, "n": 4, "sur": 20, "etoiles": 3.39,
      "prec": { "total": 17.4, "n": 4, "etoiles": 4.36, "postes": { "google": 4.8, "taches": null, "msp": 4, "budget": 4.72 } }, "delta": -0.97 } ],
  "reseau": { "sur20": 11.4, "magasins": 4, "complets": 3, "etoiles": 2.85 },
  "sources": { "obligatoires": 9, "obligatoiresLues": "2026-09-29", "obligatoiresChecklists": ["CQ-02"], "googleSynchro": "2026-09-14 19:07" },
  "rapport": { "id": 12, "actif": true, "copies": [ "ceo@…" ], "carnet": { "5": 2, "4": 0 }, "smtp": true } }
```

Les règles : **Google**, la note de la fiche (`mar_avis_fiche`) telle quelle, **gelée** dans `ceo_scoring_google` à chaque
lecture du trimestre en cours — un trimestre clos relit ce qui a été gelé. **Tâches**, la moyenne des journées relevées
(`ceo_tache_jour`) : un jour vaut la part des tâches rendues (une tâche notée vaut sa cote / 5), et un jour où une tâche
**obligatoire** manque vaut 0 ; obligatoire = le drapeau `is_mandatory` du panel (rendu `obligatoire` par `/pwa/tasks`), que le
relevé quotidien garde jour par jour (`ceo_tache_jour.obligatoire`) ; pour les jours relevés avant cette colonne, la liste des
tâches connues obligatoires (cumulée jour après jour dans le réglage `scoringObligatoires`) s'applique. Le réglage
`scoringChecklistsObligatoires` (vide par défaut) ajoute au besoin des checklists entières par préfixe. **Client mystère**, `obtenu / maximum × 5`.
**Budget**, le CA des mois du trimestre face à leur budget (`/stores/perf`, la fonction `ep_perf`) : 100 % = 5, 90 % = 4, 80 % = 3,
70 % = 2, 60 % = 1, 50 % et moins = 0, au prorata entre deux paliers ; un mois sans budget ne
compte pas, aucun mois budgété : poste sans donnée. Le total est **toujours sur 20** (`sur`) : un poste `v: null` vaut 0
et se dit (`n` = postes renseignés) ; `etoiles` = total ÷ 4 ; le classement suit le total.

`GET /scoring/msp?shop=5` — l'historique des rapports client mystère d'un magasin (tout le réseau sans `shop`) : `{ msp: [ … ] }`.

`POST /scoring/msp` — `{ shop, trimestre, obtenu, maximum, rubriques: { "Accueil": "17 / 20" }, commentaire, par,
fichier: "data:application/pdf;base64,…" }` → `{ ok, msp }`. Réencoder remplace ; le PDF (8 Mo au plus) est gardé s'il
n'est pas renvoyé, sous `public/uploads/scoring/msp/<shop>-<trimestre>.pdf`. `DELETE /scoring/msp/{id}` efface le rapport et
son PDF. Tables `ceo_scoring_msp` et `ceo_scoring_google`, créées au premier appel.

`GET /scoring/rapport?trimestre=2026-T3&shop=5&format=html|pdf` — le rapport A4 : la page réseau (podium, tableau, lecture,
règles) puis une page par magasin (sans `shop`) ou celle du magasin demandé. `html` s'imprime depuis le navigateur ;
`pdf` passe par le moteur du serveur (501 s'il manque).

`POST /scoring/envoyer` — `{ trimestre, essai?: "adresse" }` : chaque magasin dont le carnet du reporting (ligne
`scoring-trimestre`, `dest_par_magasin`) porte une adresse reçoit le classement réseau et sa page en PDF, plus son rapport
client mystère s'il est joint ; les `destinataires` de la ligne reçoivent le document complet. `essai` envoie le premier
magasin à cette seule adresse, sans journal. Réponse : `{ ok, resume, magasins: [ { magasin, statut: envoye|sans-adresse|echec|erreur, envoyes, note } ], copies, runId }`.
Le cron du reporting fait la même chose le **1er jour de chaque trimestre à 8 h** pour le trimestre révolu (`scoringCron`).

### `/analyse/produits` — la fiche d'une référence : un magasin seul, le tableau, la moyenne par jour

    GET /analyse/produits?mois=1|3|6|12        la grille « Par référence » (semaines jusqu'à 6 mois, mois à 12)
    GET /analyse/produits?mois=6&pid=1540001   l'an dernier de la référence, mêmes tranches décalées d'un an

La réponse de la grille porte aussi, depuis le 05/10/2026, `bornes` (`[[du, au], …]`, une par
tranche) et `jours` (le nombre de jours de chaque tranche ; la dernière s'arrête à aujourd'hui).
La fiche d'une référence (**Produits › Où ça se vend › Par référence**, clic sur une ligne) :

| Choix | Ce qu'il fait |
|---|---|
| Tous les magasins, ou un magasin | un magasin seul face à la moyenne réseau (en pointillé) et à l'an dernier ; l'axe se recale sur eux, chaque point se lit au survol ; un clic sur la carte du magasin fait de même, un second revient à tous |
| Courbe ou Tableau | le tableau des quantités : une ligne par magasin montré, la moyenne réseau, l'an dernier, et pour un magasin seul son écart à la moyenne en %, tranche par tranche ; le total (ou la moyenne par jour) juste après le nom |
| Total ou Moyenne par jour | chaque tranche divisée par ses jours : la semaine en cours, incomplète, ne plonge plus ; la colonne de tête devient la moyenne par jour de toute la période |

La tranche en cours porte un astérisque et la note dit combien de jours elle compte. Les cartes
des magasins disent aussi la moyenne par jour de la période.

**Jour par jour** (demande du 05/10/2026 : « les 6 derniers mercredis d'un magasin, pour évaluer
les promotions ») :

    GET /analyse/produits/jours?pid=1540001&jour=3&n=6   les 6 derniers mercredis (jour 1 = lundi … 7 = dimanche ; n de 2 à 12, 6 par défaut)
    GET /analyse/produits/jours?pid=1540001&n=14         les 14 derniers jours (n de 7 à 28, 14 par défaut)

Réponse : `{ pid, nom, cat, jour, n, dates: [YYYY-MM-DD…], libelles: ["mer. 30/09"…], enCours
(le dernier jour est aujourd'hui), magasins: [{id, nom}], parShop: {sid: [pièces | null]},
reseau: [moyenne des magasins lus ce jour-là], promos: [{id, shop, nom, du, au, statut, heures,
jours: [index dans dates], surProduit}], muets }`. Mêmes lectures que la grille
(product-category-groups), une par magasin et par jour, gravées une fois le jour clos ; `null` =
le panel n'a pas répondu pour ce magasin ce jour-là. Les promotions sont celles des jours creux
(`ceo_promo`, hors brouillons) actives ce jour-là, dans leurs jours de la semaine ;
`surProduit` quand un de leurs articles nomme la référence.

Dans la fiche, « Semaines | Jours » : en Jours, « Tous les jours » ou un jour de la semaine, et
le nombre de jours. Un magasin seul porte une bande jaune sur ses jours de promotion ; tous les
magasins, une marque de leur couleur sous l'axe. Le tableau surligne les cases en promotion.
« L'effet des promotions sur cette référence » : pour chaque promotion, la moyenne de ses jours
face aux autres jours de la série (sans promotion, sans le jour en cours), en %, et ce que les
autres magasins ont fait ces mêmes jours ; l'écart net en points retire la tendance du réseau.

### `/analyse/produits/magasin` — la fiche d'un produit dans le dashboard d'un magasin

    GET /analyse/produits/magasin?pid=3210004&shop=4&mois=1|3|12

Demande du 06/10/2026 (maquette B, « seulement le magasin actif ») : dans le dashboard magasin,
un clic sur un produit de la liste des catégories (groupe › catégorie › produit) ouvre une modale
à deux onglets. **Aucun autre magasin n'y est nommé ni chiffré à part** : le réseau n'est qu'un
repère anonyme.

```json
{ "pid": 3210004, "shop": 4, "nom": "Cookie Chocolat Lait", "cat": "Cookies",
  "semaines": { "tranches": ["S29", "…", "S41"], "bornes": [["2026-07-13", "2026-07-19"], "…"], "jours": [7, "…", 2],
                "magasin": [3, 12, "…", 8], "reseau": [25, 22, "…", 7], "anDernier": [56.3, "…"], "muettes": [], "magasins": 4 },
  "prix": { "mois": 1, "periode": "septembre 2026", "du": "2026-09-01", "au": "2026-09-30",
            "magasin": { "q": 116, "qm": 116, "ca": 290, "p": 2.5, "ec": -7.7, "v10k": 22.56, "rel": 9.8, "auMed": 24.26, "peu": false },
            "reseau": { "med": 2.71, "min": 2.5, "max": 2.9, "volMoyen": 21.06, "volMin": 12.28, "volMax": 32.41, "magasins": 4 } },
  "prixMotif": null }
```

- **`semaines`** — les 12 dernières semaines closes et la semaine en cours (lundi → dimanche, la
  dernière coupée à aujourd'hui) : `magasin` ses pièces, `reseau` la moyenne par magasin des
  magasins lus cette semaine-là, `anDernier` la moyenne par magasin l'an dernier (la même lecture
  que la fiche « Où ça se vend »). Mêmes tranches gravées que la grille `/analyse/produits`.
- **`prix`** — sur le dernier mois clos (`mois=1`), 3 ou 12 : le prix encaissé du magasin, son écart
  au prix réseau (`ec`), son volume à taille égale (`v10k`, pièces pour 10 000 € de chiffre) et son
  écart aux autres (`rel`), ce qu'un alignement au prix réseau changerait par mois (`auMed`) ; le
  réseau : prix médian, bornes de prix et de volume, volume moyen, nombre de magasins. Même calcul
  que `/analyse/prix-volume`. `null` avec `prixMotif` quand le produit n'est vendu que par un
  magasin, est hors comparaison ou n'est pas vendu par ce magasin sur la période.
- 400 sans `pid` ou `shop` ; 404 pour un magasin inconnu ou fermé. Lecture seule. Le dashboard la lit
  avec `_cache=900`.

L'écran : onglet **Ventes · 12 semaines**, la courbe du magasin face à la moyenne du réseau, ses
chiffres (pièces, par semaine, 6 dernières semaines face aux 6 d'avant, face au réseau) et le
tableau semaine par semaine ; onglet **Prix face au réseau**, le nuage prix encaissé × volume à
taille égale avec le seul point du magasin, coupé en quatre par le prix réseau et le volume moyen,
la plage du réseau en fond, ses chiffres à côté, et le choix dernier mois, 3 mois, 12 mois. Échap,
la croix ou un clic hors de la modale la ferment. Au téléphone, une feuille plein écran.

### `/analyse/prix-volume` — le prix encaissé de chaque magasin, face à ce qu'il vend

    GET /analyse/prix-volume?mois=1      le dernier mois clos (défaut)
    GET /analyse/prix-volume?mois=3      les trois derniers mois clos
    GET /analyse/prix-volume?mois=12     les douze derniers mois clos

L'écran **Produits › Où ça se vend › Prix × volume** : une ligne par référence vendue dans au
moins deux magasins, une colonne par magasin. Même source que la grille « Par référence »
(`/shops/{id}/statistics/sales/product-category-groups`, par mois, gravé une fois clos) : la
tranche porte les pièces ET le chiffre de chaque magasin, le prix encaissé en sort.

```json
{ "mois": 1, "du": "2026-09-01", "au": "2026-09-30", "periode": "septembre 2026",
  "magasins": [{ "id": "2", "nom": "Atelier by Berlo - Corbais", "court": "Corbais",
                 "taille": 121505, "plusBas": 93, "plusHaut": 31 }],
  "categories": ["Pain", "Quiches"],
  "comparables": 213, "prixDiff": 190, "ecart10": 150,
  "refs": [{ "pid": 812, "nom": "Pain 10 Céréales", "cat": "Pain", "med": 3.15, "min": 2.9, "max": 3.9,
             "ecart": 34.5, "diff": true, "ca": 6222,
             "mag": { "2": { "q": 469, "qm": 469, "ca": 1829.1, "p": 3.9, "ec": 23.8,
                             "v10k": 38.6, "rel": -65.1, "auMed": -351.75, "peu": false } } }],
  "muets": 0 }
```

- **`p`** — prix encaissé : chiffre ÷ pièces, remises comprises. Ce que le client paie, pas le
  tarif affiché.
- **`med`** — prix réseau : la médiane des magasins qui en ont vendu au moins 5 sur la période
  (tous, s'il n'y en a pas deux). `peu` marque un magasin sous ce seuil.
- **`v10k`** / **`rel`** — volume à taille égale : pièces pour 10 000 € de chiffre du magasin
  (`taille`), et l'écart en % à la moyenne des autres magasins qui vendent la référence.
- **`auMed`** — ce qu'un alignement sur le prix réseau changerait, en € par mois, à volume
  constant (positif : le magasin vend sous le réseau).
- **`diff`** — prix différents : au moins 2 % ET 5 centimes entre le plus bas et le plus haut
  (la règle de l'Analyse magasin ; en dessous, c'est le bruit des remises). `plusBas` /
  `plusHaut` comptent, parmi elles, les références où le magasin a le prix le plus bas / haut.
- Hors comparaison : catégories `Bundle…` et `Extra…`, frais de livraison, dépannage, sacs, et
  tout prix encaissé sous 0,30 €. Les catégories désactivées au catalogue sont déjà retirées
  par la lecture des tranches.
- Mois clos seulement : le prix d'un mois entamé bouge encore. Lecture seule.

### `/magasin/analyse` — l'étape Prix lue chez le panel

`GET /magasin/analyse?shop=&mois=3|6|12` (Magasins › Analyse magasin). Le **levier 3** (prix
sous le réseau) lit les mêmes tranches que `/analyse/prix-volume` sur les `mois` derniers
**mois clos** ; les autres leviers lisent encore la caisse locale. Mêmes règles qu'avant (au
moins 2 % et 5 centimes sous la médiane des autres magasins ayant vendu ≥ 5 pièces, gain à
volume constant), le volume se compare à taille égale. `levier3.source` dit d'où viennent les
prix (`panel · d’avril 2026 à septembre 2026 (mois clos)`, ou `caisse locale · …` en repli).
Quand la caisse locale n'a rien sur la période, la réponse porte `motif` **et** `levier3` avec
`prixSeul: true` : l'écran garde l'étape Prix.

### `/fournisseurs/reclamation` — les réclamations du dashboard au téléphone, avec photos

`POST /fournisseurs/reclamation` (inchangé : `shopId, idMatiere, sku, idFournisseur, idUnite,
quantite, idLivraison, motif, action, texte`) accepte deux champs de plus :

- **`photos`** — jusqu'à 4 data-URL (JPEG, PNG, WebP ; 6 Mo chacune). La route de création du
  panel (`POST /material-complaints`) ne prend que du texte : les photos sont posées par le
  cockpit sous `public/uploads/reclamations/`, sous un nom aléatoire, et leurs **liens** sont
  ajoutés à la description envoyée au fournisseur (« Photos (2) : http://…/uploads/reclamations/3-… »)
  — il les ouvre sans compte. Enregistrées avant l'envoi, effacées si le panel refuse ;
  rattachées ensuite à la réclamation (`ceo_reclamation_photo`). La réponse rend `photos`.
- **`auteur`** — le prénom de qui signale ; ajouté en fin de description (« — signalé par … »).

`GET /fournisseurs/reclamations?shop=3&mois=12` ne rend que les réclamations du magasin, et
chaque ligne porte `photos` : les chemins des photos prises depuis le dashboard.

### `/fournisseurs/matiere-code` — le code-barres d'un carton, appris au premier scan

Le panel ne porte **aucun code-barres** sur ses matières (`/shops/{id}/materials` et
`/material-suppliers/{id}/materials` n'ont ni EAN ni GTIN ; `connected-materials` porte
seulement `supplier_sku`). Le scan de l'étiquette (onglet Réclamation du dashboard au téléphone)
reconnaît donc le produit :

1. par ce que le cockpit a **appris** (`ceo_matiere_code`, servi dans
   `GET /fournisseurs/reclamation-refs` sous `codes` : `{ "05412345678908": "63", … }`) ;
2. sinon par le **SKU du fournisseur** quand le code-barres le porte (Code128 `1002343`) ;
3. sinon le produit est choisi une fois à la main, et le lien est retenu :

`POST /fournisseurs/matiere-code` `{ code, idMatiere, shopId?, sku?, nom? }` → `{ ok, code, idMatiere }`.
`code` : 3 à 64 caractères imprimables — le GTIN (01) pour une étiquette GS1 (le lot et la date
changent d'un carton à l'autre, pas le GTIN), sinon l'EAN, sinon le texte lu. Le dernier choix
l'emporte (corriger un produit mal reconnu réécrit le lien).

La lecture se fait **sur le téléphone**, sur la photo (zxing-wasm, `assets/vendor/zxing/`) : le
site est servi en http, où ni la caméra en direct ni `BarcodeDetector` ne sont permis. Pour une
étiquette GS1, le lot (10), la DLC (17), la DDM (15/16) et la date de fabrication (11/13) partent
dans la description (« Étiquette : lot L2409A · DDM 31/12/2026 · GTIN 05412345678908 »), et la
photo de l'étiquette est jointe.

### Le scan en direct et l'adresse https

La caméra en direct (`getUserMedia`) n'est permise qu'aux pages sécurisées. Le serveur est déjà
servi en https sur son IP — **https://185.180.206.46/consulant_bo/** — avec un certificat
Let's Encrypt d'adresse IP que le certbot du serveur gère et renouvelle (hors de ce dépôt ;
`bin/deploy.sh`, étape 5a, n'y touche pas et vérifie seulement que le dashboard y répond). En
http, l'écran garde le scan par photo et propose la même page en https.
Dans le viseur, le flux est lu réduit (1 280 px, vite) ; au premier code lu, l'image pleine est
relue plus fort et ses codes s'ajoutent (le long GS1-128 du lot et des dates y est attrapé).
Un EAN-13 et le GTIN (01) d'une même étiquette ont la même clé : le GTIN sur 14 chiffres.

### `/pwa/tasks/heatmap/mois?obligatoires=1` — les tâches obligatoires seules

Le dashboard magasin ne montre que les tâches **obligatoires** (`obligatoire` du panel) : le jour
(carrousel des contrôles en photo, bloc des tâches, cellule du téléphone) filtre `/pwa/tasks` côté
écran ; la semaine et le mois passent `obligatoires=1`, qui écarte les lignes dont le panel dit
qu'elles sont facultatives (une ligne relevée avant le drapeau, `NULL`, est gardée). Les contrôles
de formation (CQ-F…) restent dans Contrôle des tâches du cockpit.

### `/products/scoring` — une ligne par référence vendue sur la période

```json
[
  { "id": "vi1", "nom": "Croissant pur beurre", "categorie": "Viennoiserie",
    "volume": 48200, "prix": 1.35, "coutUnit": 0.47, "tendVol": 1.04, "magasins": 9 }
]
```

`volume` = unités vendues sur le réseau (magasins ouverts) pour la période ; `prix` = prix de
vente TTC moyen constaté ; `coutUnit` = coût de revient matière + emballage ; `tendVol` = ratio
de volume vs même mois N-1 (1,04 = +4 %) ; `magasins` = nombre de magasins ouverts ayant vendu la
référence sur la période (le taux de pénétration est `magasins / magasins ouverts`, calculé côté
client à partir de `/stores`). Le client calcule CA réseau, marge unitaire, marge brute, rang par
catégorie (CA décroissant) et les trois notes du score — l'API n'envoie que les mesures brutes.

### Champs projet ajoutés

`famille` (obligatoire) classe le projet dans le kanban : `Produits`, `Services`,
`Organisation & coûts`, `Développement réseau` (table de référence `ceo_project_famille`). Le
statut reste un champ à part, affiché sur la carte. Chaque tâche accepte deux champs optionnels
`desc` (texte libre) et `budget` (montant € affecté à l'étape) ; à défaut le client affiche la
quote-part du budget projet. Saisie franchisé (CA réel, clients, panier moyen déduit) :
`PUT /stores/{id}/perf/{annee}-{mois}` avec `{ ca, tickets, charges: [{poste, montant}] }` — le panier
moyen n'est jamais transmis, il vaut toujours `ca / tickets` ; les ratios food/labour/overhead sont
recalculés serveur (`montant / ca`).

Écran Encodage du budget — routes d'écriture : `PUT /stores/{id}/budget?exercice=AAAA` avec le corps
`{ caMensuel: [12 nombres], caTheoriqueMensuel: [12 nombres], panierEngagement,
etudeMarche: {date, source, potentielMenages, potentielMaturite, anneeExploitation,
monteeEnRegime: {a1: 70, a2: 80, a3: 90}, saisonnalite: [12 pourcentages],
annexe: {nom, url, taille, date}},
charges: [{poste, levier, pctBudget, pctTheorique}] }`
(`caTheoriqueAn` = somme de `caTheoriqueMensuel` ; le CA théorique de l'exercice vaut
`potentielMaturite × monteeEnRegime[annéeExploitation]`, réparti par `saisonnalite`)
(`caTheoriqueAn` = somme de `caTheoriqueMensuel`, calculée côté serveur). Le fichier d'annexe passe par `POST /stores/{id}/budget/annexe` (multipart) qui renvoie
`{nom, url, taille, date}` à replacer dans `etudeMarche.annexe`.
Le serveur écrit `ceo_shop_budget` + `ceo_shop_month_perf.ca_budget`
et journalise l'opération (`ceo_journal_entry`, type `Budget`).

Chaque tâche accepte aussi `magasinId` (optionnel, `null` = tâche réseau) : une tâche est rattachée
à un projet et, si elle porte sur un point de vente précis, à un magasin. L'écran Tâches filtre sur
ce champ et l'affiche sur la ligne. `POST /projects/{id}/tasks` et `PATCH /projects/{id}/tasks/{taskId}`
acceptent `magasinId`.

Routes d'écriture projets : `PATCH /projects/{id}` (`famille`, `statut`),
`PATCH /projects/{id}/tasks/{taskId}` (`done`, `magasinId`, `note`), `PATCH /projects/{id}/milestones/{index}` (`reel`).

### Validation d'une tâche consultant

Une tâche n'est plus seulement cochée, elle est **notée**. La note porte à la fois la conformité
et la gravité — il n'y a donc pas de champ « gravité » à côté :

| Note | Niveau | Effet |
|---|---|---|
| 5 | Exemplaire | clôture |
| 4 | Conforme | clôture |
| 3 | Non conforme — mineur | clôture **+ signalement** |
| 2 | Non conforme — majeur | clôture **+ signalement** |
| 1 | Non conforme — critique | clôture **+ signalement** |

Chaque tâche de `/projects` porte désormais `note` (1..5, `null` = rendue mais pas encore
validée — c'est ce qui alimente le groupe « À valider »), `valideePar`, et `signalement` (le
dernier signalement de la tâche, ou `null`).

`PATCH /projects/{id}/tasks/{taskId}` accepte `{ note, famille, type, commentaire, copie, par }`.
La note, la clôture et le signalement sont écrits dans **une seule transaction** : une tâche close
dont le signalement s'est perdu en route est pire que les deux ensemble. Deux refus en `422` :
une note hors `1..5`, et une note sous le seuil sans `famille` ni `type`.

Les cinq niveaux, le seuil et le référentiel famille → type viennent de `GET /meta`, clé
`signalement` (réglage `ceo_app_setting`, modifiable par `PUT /parametres/signalement`) :

```json
{ "seuil": 4,
  "niveaux": [{ "n": 5, "nom": "Exemplaire", "couleur": "#C9A227", "aide": "au-dessus de l'attendu" }],
  "familles": [{ "nom": "Livrable", "types": ["Incomplet", "Non conforme au brief"] }] }
```

**Une seule source.** Aucun libellé ni couleur n'est recopié dans le JavaScript : le jour où
« mineur » devient « à surveiller », il ne change qu'ici. Les mêmes cinq niveaux servent au panel
consultant (`pwa_consultant`) pour les tâches boutique — un « majeur » doit vouloir dire la même
chose des deux côtés, sinon les chiffres ne s'additionnent pas. Le référentiel famille/type, lui,
est propre à chaque application.

### `GET /pwa/tasks/nc` — les non-conformités d'une journée ou d'une période

    ?shop=4&date=2026-09-13        une journée (la veille, sous la vue Jour)
    ?shop=4&du=2026-09-07&au=…     une période (sous les vues Semaine et Mois)

Ce que le **dashboard magasin** lit : les tâches d'une boutique notées sous le
seuil sur la fenêtre demandée, avec leur motif. Tout vient de `mac_task_review` — aucun appel au panel, la
journée est révolue donc figée. Le barème part avec la réponse : la page n'a pas à lire `/meta`
pour nommer une gravité.

```json
{ "shopId": "4", "date": "2026-09-13", "seuil": 4,
  "niveaux": [{ "n": 1, "nom": "Non conforme — critique", "couleur": "#8D1D2C" }],
  "notees": 18,
  "nc": [{ "taskId": "101", "jour": "2026-09-13",
           "tache": "Contrôle Qualité – Températures frigo vitrine",
           "note": 1, "comment": "Frigo vitrine relevé à 9 °C à 16 h.",
           "consultant": "K. Moreau", "releveeLe": "2026-09-13 16:20",
           "recidive": 3,
           "suite": { "jour": "2026-09-14", "note": 4, "conforme": true },
           "valideeLe": null, "valideePar": null }],
  "semaine": [{ "jour": "2026-09-07", "nc": 1, "notees": 16, "releve": true }],
  "indispo": false }
```

- **non-conformité** = `rating < seuil`, ou `is_accepted = 0` pour un avis venu d'un panel qui
  aurait un autre seuil. `notees` compte toutes les tâches notées, conformes comprises : sans le
  dénominateur, « cinq écarts » ne veut rien dire.
- **`recidive`** : le nombre de journées où la MÊME tâche était déjà non conforme sur les sept
  jours précédents, bornes comprises. `null` en dessous de deux.
- **`tache`** : le référentiel `todo_task`, à défaut le relevé quotidien `ceo_tache_jour`, à
  défaut l'identifiant — jamais un nom inventé.
- **`suite`** : la PREMIÈRE note posée sur la même tâche après ce jour-là, `null` s'il n'y en a
  pas eu. C'est elle qui dit si l'écart a été repris, et quand — sans elle, la liste des
  non-conformités d'un mois ne serait qu'un palmarès des reproches. La vue Jour ne s'en sert pas :
  elle lit le devenir de la tâche dans la journée en cours, plus riche (rendue sans note, pas
  rendue du tout).
- **`semaine`** : la série des sept derniers jours, renseignée **seulement** pour une journée.
  Sur une période, le compte de la période est déjà l'échelle.
- **`indispo`** : `mac_task_review` absente. L'écran se tait alors, il n'invente pas un zéro.

Ce que la MÊME tâche est devenue depuis ne vient PAS d'ici : le dashboard le lit dans
`/pwa/tasks?date=<aujourd'hui>`, qu'il charge déjà.

**Le dashboard ne fait que regarder.** Il n'écrit rien sur les tâches : noter (`POST
/pwa/tasks/review`), contresigner (`POST /pwa/tasks/validate`) et relancer se font dans
**Contrôle des tâches**, l'écran qui porte la responsabilité de l'avis. Une contresignature
déjà posée s'affiche ici — c'est un fait à connaître, pas une commande à actionner.

### Le détail des ventes : noms, catégories et portions lus chez le panel

Mesuré à Halle le 03/10/2026 : une demi-tarte « Frangipane & Pommes - 1/2 »
vendue n'apparaissait pas dans « Ventes par catégorie ». La ligne de ticket
portait le nom de la caisse du magasin (« Frangipanes & Appels », en
néerlandais), la copie locale de la base ignorait ce produit créé depuis son
extraction (donc « Sans catégorie »), et la portion comptait comme une pièce
au coût d'une pièce entière. Trois règles, toutes en direct depuis l'API :

- **Le catalogue** (`svCatalogue`) : `/shops/{id}/products/available` de
  chaque magasin du compte, le nom réseau (`base_name`) et la catégorie
  (`base_category_name`), une lecture par heure gardée dans `ceo_app_setting`
  (`svCatalogue`), la dernière lecture servie si le panel se tait. La copie
  locale de `product` ne comble que les références que le panel ne liste plus.
- **Les portions** (`svPortion`) : une ligne de ticket avec
  `id_product_portion` est une ligne à part, clé « produit:portion »
  (`6700284:77`), nom « Frangipane & Pommes — 1/2 »
  (`product_portion_label`), coût = coût de la pièce × `portion_fraction`
  du `manufacturing_cost_snapshot` (sinon le type `ONE_HALF`, `ONE_QUARTER`…,
  sinon le libellé « 1/3 »), ou le coût chiffré par le panel quand la pièce
  n'a pas de recette connue. Les lecteurs qui raisonnent par pièce
  (planogramme, objectifs produits, jours creux, bundles) ramènent la portion
  à son produit par `(int)` de la clé.
- **Les noms** (`svNomProduit`) : à l'affichage, le nom du catalogue remplace
  celui de la ligne de ticket, la portion conservée — y compris pour les
  journées gravées avant cette règle.

Les tickets d'une journée close restent gravés tels qu'ils ont été lus : une
journée lue avant cette règle garde ses portions fondues dans la pièce, et
n'est relue que si on efface son gravé.

**Le catalogue, les catégories et les prix, en direct** (`panelCatalogue`) :
une lecture par heure, gardée dans `ceo_app_setting` (`panelCatalogue`),
compose les groupes (`/product-category-groups`), les catégories
(`/product-categories`, le nom réseau `base_name`) et les produits de chaque
magasin du compte (`/shops/{id}/products/available` : nom réseau, catégorie et
ses groupes quand la ligne les porte, prix pratiqué `portion_price` par
magasin, prix conseillé, recette, DLV, poids, marge attendue). Dessus :
`catalogueCategories` (les groupes manquants comblés par la table de liaison
de la copie, seule à les porter sinon), `cataloguePrix` (la moyenne des
magasins, `cataloguePrixMagasin` pour un seul), la liste du référentiel
(`/production/catalogue`, sur `panelCatalogueComplet` : les produits de chaque
catégorie, `/product-categories/{id}/products`, lus en parallèle, pour garder
les références qu'aucun magasin ne propose en ce moment, `dispo` = false), la fiche produit (`/products/{id}` d'abord), les
recherches de produits (`/marketing/catalogue`, `/promo/recherche`) et la
catégorie de chaque produit du scoring. La copie locale (`product`,
`product_category`, `shop_product`) ne sert plus que si le panel se tait.

**Le coût matière, aussi en direct** (`catalogueCouts`, `coutsPanelMagasin`) :
le panel d'abord — `recipe_cost_net` de `/shops/{id}/products/available`, le
coût de CE magasin pour le détail de ses ventes, la moyenne des magasins du
compte pour les écrans réseau (scoring, référentiel, prix × volume) — en mémo
une heure (`coutsPanel` : ts, couts, parMagasin), la dernière lecture servie
si le panel se tait. Les recettes de la copie locale (`recipe_cost`) ne
comblent plus que les références que le panel ne chiffre pas, et le disent
dans `source` (« … (copie locale) »). La saisie du cockpit garde la main en
aval, comme avant.

### Le coût matière du P&L quand le panel ne le chiffre pas

Mesuré à Halle le 03/10/2026 : dès qu'un produit vendu n'a pas de coût de
recette, le panel rend `margin_value` (margin-heatmap) et `material_cost`
(hourly-distribution) nuls, `margin_status` COST_INCOMPLETE — et le P&L
lisait un coût matière de 100 %, une marge brute de 0. Désormais
(`svMatiereJour`) : le coût matière d'une journée se recompose depuis les
tickets, la somme des coûts de recette des lignes vendues (le coût du panel
pour ce magasin, la portion à sa fraction), les lignes sans coût prenant leur
CA au taux des lignes connues. `coutMatiereSource` le dit sur le résultat du
jour (« recettes vendues · estimé, x % du CA avec coût connu »), la série du
mois, la semaine et la rentabilité font de même sur les journées gravées, les
heures des stats de vente aussi (`matiere.source`). Sans tickets lus, le
coût reste inconnu, jamais zéro.

### `GET /exploitation/jour` — les clients de J−7 au même moment

Chaque magasin porte `j7` : `{ date, moment, tickets, ca, ticketsJour, caJour, mb, mbJour,
heures: [{ h, tickets, ca }] }`, le même jour de la semaine d'avant lu dans ses heures
gravées. Quand la journée regardée est aujourd'hui, `tickets` et `ca` s'arrêtent à l'heure
qu'il est (`moment`, l'heure en cours comptée au prorata des minutes) ; une journée close
prend J−7 entière et `moment` est nul. Depuis le 08/10/2026, `mb` est la marge brute de J−7
arrêtée au même moment (ventes moins matière des heures gravées, `null` dès qu'une heure
vendue n'a pas sa matière), `mbJour` celle de la journée entière, et `heures` les ventes de
J−7 heure par heure (toutes les heures, pas seulement celles d'avant `moment`). Le dashboard
en fait le duel de l'onglet Opérationnel (jauge à deux pistes, cumul heure par heure, tableau
chiffre par chiffre) et compare les vignettes de la vue Jour à J−7 à la même heure quand la
journée regardée est aujourd'hui. Nul si J−7 n'est pas lu.

### `GET /analyse/produits/recette` — la recette d'un produit, pour l'onglet « Recette & marge » (08/10/2026)

`GET /analyse/produits/recette?pid=2300010&shop=4[&rafraichir=1][&colonnes=1]` rend la recette du produit **par l'API du
panel d'abord** (le soir du 08/10/2026, « api only », « du changement à chaque édition de prix ») : `/products/{pid}`
donne la recette (`id_recipe`, gardé 24 h), `/shops/{shop}/recipes/{recette}/cost` le coût que le panel calcule pour ce
magasin aux prix du jour, sous-recettes dépliées (`elements` emboîtés, type `sub-recipe` puis `ingredient`, quantités
déjà ramenées à la recette dans `required_quantity`, `price_net` par unité de base, `price_source` LOCAL ou MISSING,
`calculated_req_price_net`, fournisseur, `cost_net`, `cost_status`, `missing_materials`). Relu passée la minute
(`recetteCout:<recette>:<shop>`). Une matière MISSING dans ce magasin est prise au prix médian des autres magasins
(`/shops/{id}/materials`, `prixSource` « autre magasin »), comme le faisait la copie. La réponse porte `api: true`,
`cout.complet`, `cout.manquants` et `lu`. Le dashboard fait alors le coefficient, la marge et les paliers sur ce coût
du jour (`total`), le coût gravé avec les tickets restant affiché en repère. Quand l'API ne répond pas, la copie locale
prend le relais (ci-dessous, `source` le dit), gardée 24 h par produit et magasin (`ceo_app_setting`
`recetteProduit:<pid>:<shop>`). Tables mesurées le 08/10/2026 : `product.id_recipe` → `product_recipe` (nom,
`yield_quantity`, `id_unit`, `is_subrecipe`) → `product_recipe_material_connection` (`parent_recipe_id`,
`child_ingredient_id` ou `child_recipe_id`, `quantity`) → `material` (`id_category` → `material_category`, `id_unit`
→ `unit` : `name`, `smaller_unit_name`, `conversion_factor`) → `shop_material_price_list` (`base_unit_price_net`
par matière et magasin, le plus récent, sinon celui d'un autre magasin) ; `recipe_cost` (`calculated_cost_net` par
recette et magasin, le plus récent, sinon `id_shop` 0). `&colonnes=1` (diagnostic, sans cache) ajoute les listes de
colonnes, les lignes brutes et les coûts bruts :

```json
{ "pid": 2300010, "nom": "FlipFlap - Thon", "recette": { "id": 573, "nom": "FlipFlap Thon", "rendement": 1 },
  "cout": { "net": 3.91, "source": "recette du magasin (copie locale)" },
  "lignes": [ { "nom": "Thon à l’huile", "cat": "Conserves", "qte": 70, "unite": "g", "prixUnite": 26.43, "prixParUnite": "kg",
                "cout": 1.85, "part": 47.3, "sous": null, "type": "matiere" },
              { "nom": "Huile", "qte": 20, "unite": "ml", "cout": 0.08, "sous": "Mayonnaise maison", "type": "matiere", "…": "…" } ],
  "total": 3.71, "complet": false, "sansPrix": 1, "nLignes": 8, "source": "copie locale du panel : …", "colonnes": { "…": "…" } }
```

- Les sous-recettes sont dépliées (4 niveaux au plus, chacune une fois), leurs lignes au prorata de la quantité
  demandée divisée par le rendement de la sous-recette (`sous` = son nom) ; une sous-recette sans ligne lisible
  vaut son coût gravé. Le coût d'une ligne = quantité × prix de base de la matière (`prixSource` : magasin, réseau,
  autre magasin) ; une matière sans prix garde sa quantité, `cout` null et `motif`, et `complet` passe à faux.
  `hypothese` dit dans quelle unité les quantités ont été lues : `base` (kg, l) ou `petite` (g, ml) quand le total
  des lignes s'approche davantage de la référence ainsi ; la référence (`reference`) est `&cout=` quand le dashboard
  passe le coût de la pièce gravé avec les tickets, sinon `cout.net`. Les lignes sont triées par coût ; `part` se
  lit sur le total chiffré.
- `cout.net` : `recipe_cost` du magasin, sinon du réseau (id_shop 0), sinon la moyenne des magasins, divisé par le
  rendement de la recette, avec `type` (`price_type` de la copie : `net`, `custom`…). Le dashboard lit de son côté
  le coût de la pièce gravé avec les tickets (la ligne cliquée) : c'est lui qui fait la marge et le coefficient de
  la modale, et il ne montre `cout.net` qu'à défaut de coût gravé. Mesuré le 08/10/2026 à Halle : la recette 244
  (FlipFlap - Thon) porte un `recipe_cost` `custom` de 0,79 € daté du 12/07/2026 quand les autres magasins et les
  tickets disent 3,73 à 3,91 €, et la recette ligne à ligne donne 3,87 € (13 lignes, prix d'un autre magasin pour
  les matières sans prix à Halle).
- Les colonnes de la copie n'ont jamais été cartographiées en entier : chaque table est lue par
  `information_schema` et les colonnes retenues sont dites dans `colonnes` (quantité, unité, prix, sous-recette…).
  `sansRecette: true` quand le produit n'a pas de recette, `indispo: true` avec `motif` quand la copie ne porte pas
  les lignes.
- La modale produit du dashboard (vue Jour, clic sur un produit de « Ventes par catégorie ») porte un troisième
  onglet « Recette & marge » : la jauge du coefficient (prix encaissé ÷ coût de recette, zones de l'échelle des
  marges : rouge sous × 1,67, orange jusqu'à × 2,5, vert au-delà ; repères de la catégorie, du magasin du jour et
  de l'objectif = 100 ÷ seuil matière), la recette et son split, la marge en cascade (matière, main-d'œuvre et
  frais généraux aux seuils du P&L, résultat par pièce) et le produit face à sa catégorie, au magasin, au prix
  réseau et à l'objectif. La ligne cliquée porte `data-fc` (coût matière du jour) et `data-fcat` (catégorie).
- Le prix de vente se tape (08/10/2026) : dans la carte du coefficient, un champ « Prix de vente » (prix encaissé
  par défaut, pas de 5 centimes par les boutons ou les flèches) recalcule la pièce à chaque frappe : le coefficient,
  son verdict, le repère qui se déplace sur la jauge (un repère gris garde le prix encaissé), la marge brute, le
  résultat par pièce aux seuils, l'écart face au prix réseau, le chiffre en plus sur les pièces vendues du jour à
  volume égal, la cascade de marge et une pastille « prix testé » dans l'en-tête. Sous l'axe, « Le prix pour y
  arriver » : chaque palier de la jauge (le prix réseau, marge 40 %, sa catégorie, marge 60 %, le magasin du jour,
  l'objectif) en raccourci = coût de recette × coefficient, arrondi aux 5 centimes supérieurs, qui remplit le champ.
  « Remettre » revient au prix encaissé. Tout se passe dans la modale : rien n'est écrit, ni au panel ni au serveur ;
  changer le prix de vente reste une action du panel.
- Chaque ligne de matière de la recette se déplie d'un clic (08/10/2026, « api only, from swagger ») sur la fiche de
  la matière lue par `GET /analyse/matieres/fiche` (ci-dessous) : ce magasin, le prix conseillé, le réseau, le
  fournisseur, puis le tableau des magasins (prix par kg, écart face au repère) et celui des fournisseurs (colis, prix
  par kg, valable depuis, prochain prix, écart face au prix du magasin).

### `GET /analyse/matieres/fiche` — la fiche d'une matière première, API du panel seulement (08/10/2026)

`GET /analyse/matieres/fiche?mid=327&shop=4[&rafraichir=1][&sonde=1]` ne lit que l'API du panel, jamais la copie
locale (demande : « api only, from swagger »). Routes du swagger (`/swagger/openapi.json`) mesurées le 08/10/2026 :

- `/shops/{id}/materials`, pour chaque magasin actif du cockpit (table `shops`) : `base_unit_price_net` (le prix du
  magasin par unité de base), `suggested_base_unit_price_net` (prix conseillé : rempli pour 1 matière sur 631),
  `reference_unit_price_net`, `unit_name`, `category_name`, `vat_rate`, `source_type` (CENTRAL, OPEN),
  `integrated_supplier`. Une lecture par magasin, gardée 24 h (`ceo_app_setting` `matieresApi:<shop>`), 10 minutes
  pour le magasin demandé (un prix changé au panel doit se voir vite) ; `lu.magasin` dit l'heure de cette lecture et
  « Relire » dans la modale passe `&rafraichir=1`, qui relit tout ;
- `/material-suppliers` (7 fournisseurs, la centrale de type CENTRAL), puis par fournisseur `/catalog-mappings`
  (`material_id` ↔ `catalog_product_sku`, `package_size`, `package_unit`, `units_per_pack`), `/connected-materials`
  (`supplier_sku`) et `/materials` ; gardés 24 h pour le réseau (`fournisseursMatieres2`) ;
- `/material-suppliers/{f}/shops/{s}/price-lists/current` et `/latest` : la liste de prix du fournisseur pour le
  magasin, par colis et DATÉE (`price_net`, `package_size`, `package_unit`, `vat_rate`, `valid_from`,
  `franchisee_material_name`, `is_active`, `is_mapped`). Lues seulement pour les fournisseurs qui portent la matière,
  gardées 24 h (`listePrix:<f>:<s>`). Le prix par unité de base = `price_net ÷ (package_size × units_per_pack)`,
  vérifié par `&sonde=1` sur les matières liées de Halle (SLFood 178/264 exacts, les autres étant d'anciennes listes ;
  centrale 1/1 ; Rawette 16/16 ; CDT 20/20).

```json
{ "mid": 63, "nom": "Baguette Tradition 500 g.", "cat": "Petite Boulangerie", "uniteBase": "pcs", "par": "pce", "facteur": 1,
  "tva": 6, "sourceType": "CENTRAL", "conseille": null, "reference": null, "repere": 1.4656, "repereNom": "médiane du réseau",
  "magasin": { "id": "4", "court": "Halle", "prix": 1.4656, "sansPrix": false, "absente": false, "ecart": 0 },
  "reseau": [ { "id": "4", "court": "Halle", "ceMagasin": true, "lu": true, "prix": 1.4656, "ecart": 0 }, { "…": "…" } ],
  "stats": { "n": 4, "min": 1.4656, "max": 1.4656, "med": 1.4656 },
  "fournisseurs": [ { "id": "1", "nom": "SLFood", "centrale": true, "via": "catalogue", "sku": "1003021", "colis": { "prix": 26.38, "taille": 18, "unite": "pcs", "parColis": 1, "tva": 0 },
                     "parUnite": 1.4656, "depuis": "2026-09-04", "enVigueur": true, "ecart": 0, "prochain": { "prix": 25.65, "parUnite": 1.425, "depuis": "2026-10-09" } } ],
  "source": "API du panel : …" }
```

- Les prix sont exprimés par kg quand l'unité de base est le gramme (`facteur` 1000, `par` « kg »), par l pour le
  ml, par pièce sinon. `repere` = prix conseillé, sinon prix de référence, sinon médiane du réseau ; `ecart` en %.
- `magasin.sansPrix` : le magasin porte la matière à 0 (le thon à Halle) ; `absente` : il ne la porte pas.
  `fournisseurs` vide : aucun fournisseur du panel ne porte la matière (le thon, acheté librement).
- `prochain` : la liste `latest` quand elle vaut plus tard que `current`, dans le futur, à un autre prix.
- La recette de la modale reste chiffrée par la copie locale (`/analyse/produits/recette`) : quand l'API dit un autre
  prix que celui que la recette a pris, la fiche le dit en note (la copie se synchronise à son rythme).
- Le panel ne porte pas le prix d'achat de la centrale chez ses propres fournisseurs : `/material-suppliers/{f}/raw-materials`
  et `/price-history` n'ont que trois matières d'essai. Le « prix centrale » montré est donc le prix de sa liste pour le
  magasin.
- 400 sans `mid` ou `shop`, 503 sans compte panel, 404 matière inconnue de l'API, 502 magasin illisible et matière
  inconnue partout.

### Dashboard magasin : l'onglet « Opérationnel » (06/10/2026)

Depuis le 10/10/2026, l'ordinateur n'a plus d'onglet « Jour » : Opérationnel montre toute la journée. Une adresse `?vue=jour` ouvre Opérationnel. Le téléphone garde ses onglets Exploitation et Contrôle.

Premier onglet du dashboard, au bureau comme au téléphone, ouvert par défaut quand l'adresse ne
donne pas de vue (`/dashboard/?shop=4`, ou `vue=ops`). Au téléphone, il est le premier des quatre
onglets du bas (Opérationnel, Exploitation, Contrôle, Semaine), en une colonne : les mêmes tuiles,
la vitrine en liste courte, les heures, l'équipe et les cuissons en lignes, les photos du jour, le
P&L court et les catégories, les cartes et le stock. La journée en cours, centrée sur le terrain,
relue toutes les deux minutes sur aujourd'hui :

| Bloc | Lecture |
|---|---|
| Maintenant : l'heure de Bruxelles, les ventes face à J−7 à la même heure, l'objectif, la vitrine, l'équipe en poste, la prochaine cuisson | `/ventes/stats?vue=jour`, `/exploitation/jour`, `/production/flux/suivi` (`_cache=90`) |
| Ventes par catégorie (liste ou treemap) et P&L court de la journée, main-d'œuvre comprise | les cartes de la vue Jour |
| La journée : l'équipe, le four, la vitrine, les commandes de 04:00 à 20:00 ; les ventes au comptoir de chaque heure face à la moyenne des 6 derniers mêmes jours | `/exploitation/jour` (planning), `/production/flux/suivi`, `/exploitation/canaux` |
| La vitrine en trois onglets de durée de vie (06/10/2026), short, medium et long life, chacun avec le niveau actuel de marchandises : pièces et valeur en vitrine (`stock` × `prix`), puis face à ce qui se vendra encore aujourd'hui (la prévision des heures à venir, `cases[].prev`) pour short et medium, ou en jours de vente (`moyJ`, sinon `prevJ`) pour le long life, et les alertes. Short et medium : vide, va manquer, jeté ce soir ou se garde demain, à recuire. Long life : le stock face à 3 jours de vente, les jours de stock, sous le stock ou en stock, la cuisson prévue ou de quoi tenir 3 jours. Short life (06/10/2026) : la prévision de chaque heure de toute la journée (`prevH`, passées comprises), en petit le vendu des heures passées et de l'heure entamée (`cases[].v` ; vert bien au-dessus du prévu, orange bien en dessous), les heures à venir teintées du stock projeté (`cases[].q` : bas, vide), le prévu du jour ; medium life de même. Les trois onglets se lisent en dépliant catégorie (`groupe`) › sous-catégorie (`cat`) › produit, chaque niveau avec ses chiffres (short et medium : ses heures ; long life : son stock face à 3 jours de vente, ses jours de stock), ses alertes et ce qu'il faut recuire, tout replié au départ ; au téléphone, le même dépliant (une bande d'heures par ligne en short et medium). Les références en ordre repliées | `/production/flux/suivi` (`vie`, `cases`) |
| Les contrôles en photo | le carrousel de la vue Jour |
| Commandes clients, non-conformités d'hier, poubelle, promotions | `/ventes/commandes`, `/pwa/tasks/nc`, `/exploitation/invendus`, `/exploitation/promos` |
| Le stock du magasin, en liste déroulante : les alertes d'abord, puis tout l'inventaire par catégorie | `/ventes/stock` |

Les ventes par heure se lisent sur `/ventes/stats` (tickets). Celles de `/exploitation/jour`
viennent de margin-heatmap, qui avance du décalage de Bruxelles du jour (2 h l'été, 1 h l'hiver,
mesuré face à hourly-distribution) : depuis le 06/10/2026, le serveur les ramène à l'heure locale
(`hmHeureLocale`) pour la série `heures`, le chiffre attribué à chaque personne en poste et la
dernière heure de la projection ; les profils horaires bâtis avant sont effacés une fois
(`profilHeureV` = 2) et se rebâtissent à l'heure locale. Le tiroir du stock montre désormais toute la liste
dans toutes les vues, pas seulement les références en alerte.

### `_cache=N` — une lecture gardée à la demande (06/10/2026)

Toute lecture `GET` peut demander `_cache=N` (secondes, 900 au plus) : le serveur la garde N
secondes (`ceo_app_setting`, clé `rcG:` + md5 du chemin et des paramètres triés), la sert
périmée jusqu'à 2 × N secondes en la refaisant en arrière-plan, et la refait pendant l'appel
au-delà — le même mécanisme que le Résultat ci-dessous. Une erreur, un 4xx ou un `indispo` ne
se gardent pas ; une liste repart sans le champ `cache` ; `rafraichir=1` force le calcul.
`/exploitation/jour` et `/periode` gardent leur propre cache. Le dashboard magasin la demande
pour ses lectures lentes qu'il ne relit pas après une écriture :

| Lecture | Gardée |
|---|---|
| ventes des 30 mois, 6 semaines, record, tâches du mois, rentabilité | 10 min |
| tendance, canaux, offres, invendus, non-conformités | 5 min |
| stock, tâches du jour, commandes, notifications | 2 min |

Pas les photos des contrôles (leurs liens expirent), ni les notes, objectifs, promotions et pro
(rapides, et écrits depuis le dashboard).

Chaque réponse JSON porte l'en-tête standard `Server-Timing` : `charge` (le code, avec l'état
d'opcache), `schema` (la vérification des tables) et `calcul` (la réponse), en millisecondes.
La vérification des tables ne se fait plus qu'une fois par version du code : l'empreinte des
sources (nom, date, taille) est gardée dans `ceo_app_setting` (`schemaVu`) ; un déploiement la
change, et une table ou une colonne manquante pendant un appel l'efface (l'appel suivant
revérifie tout).

### `GET /exploitation/jour` et `/exploitation/periode` — le calcul gardé (04/10/2026)

**Main-d'œuvre de la semaine et du mois (10/10/2026).** `/exploitation/periode` additionne le coût du personnel de chaque jour tel que le panel l'alloue (`/shops/{id}/statistics/daily-summary` : `employee_cost`, et `shop_cost` pour les frais). `/pnl?period=month` rend le personnel cumulé du 1er à aujourd'hui, pas le mois entier. Le répartir sur les 31 jours d'octobre donnait 1 257,69 € pour Halle au 10/10 au lieu d'environ 3 900 €. Ce cumul ne sert plus qu'en repli, divisé par les jours ouverts écoulés. Un jour lu deux jours plus tard est gardé (`dsJ:{magasin}:{jour}`). `labourSource` dit d'où vient le chiffre. Le tableau « Le duel, chiffre par chiffre » de l'onglet Opérationnel ne garde que CA, clients et panier.

**Dashboard magasin, Semaine et Mois (10/10/2026).** Plus de barre du stock (elle reste dans Opérationnel). « Ta place dans le réseau » classe le B2B (`caPro`, la part `partPro` dessous) à la place des messages du panel, qui ne sont plus lus.

**Calendrier et sélecteur de période (10/10/2026).** Le calendrier de la semaine et du mois suit le bouton CA / Atteinte / Clients : chiffre, couleur, jauge vers l'objectif (trait à 100 %), titre et légende. Les clients se jugent face à l'objectif clients (objectif CA du jour ÷ panier moyen de la période). La journée en cours est hachurée, non jugée. Au bureau et sur tablette, une frise remplace le champ date : la semaine active du lundi au dimanche en Opérationnel et en Semaine, ses flèches passant d'une semaine à l'autre (`/exploitation/periode?vue=mois` du ou des mois touchés ; en Semaine, un clic sur un jour l'ouvre en Opérationnel), 12 mois en Mois et 4 trimestres en Trimestre (`/stores/perf?granularite=mois`). Chaque période est un bouton avec son chiffre ; la case du jour regardé prend le CA en direct de la page.

**Ventes par catégorie = P&L court (10/10/2026).** Le Total de la carte reprend le CA et la marge brute du P&L de la journée. La somme des produits lus dans les tickets peut être un peu plus basse (lignes de caisse sans produit, remises sur ticket, arrondis) : l'écart s'affiche sur une ligne « Non ventilé ». Quand des catégories n'ont pas de coût dans les tickets (en gris), leur marge tombe aussi dans cette ligne.

**À compléter (10/10/2026).** `GET /ventes/stats` renvoie `aCompleter` : ce qu'il faut remplir pour que les catégories collent au P&L.
- `lignes` : chaque ligne de caisse sans produit (`type: "ligne"`, heure `mn`, libellé de caisse `nom`, `q`, `v`) et chaque écart entre le total d'un ticket et la somme de ses lignes (`type: "ticket"`, une remise sur le ticket entier quand `v` est négatif). Le relevé des tickets `svP{shop}:{date}` les garde dans `sp`. Un relevé qui ne les porte pas encore se relit une fois en entier : aujourd'hui, ou un jour passé ouvert en vue jour.
- `nLignes`, `caLignes`, `caTickets` : le compte et les montants. `detail` vaut `false` si un jour de la période n'a pas encore son `sp`.
- `sansCout`, `nSansCout`, `caSansCout` : les produits vendus sans coût de recette, à chiffrer dans le panel.
Sous « Ventes par catégorie », la carte « À compléter pour des chiffres justes » montre deux vignettes avec leur liste déroulante : « Ventes sans produit » (à rattacher à un produit dans la caisse ; le reste de l'écart = arrondis ou tickets pas encore lus) et « Produits sans coût » (un clic ouvre la fiche du produit).

**Tickets pas encore lus (11/10/2026).** Mesuré à Halle le 11/10 : la carte annonçait « Ventes sans produit 45 € » avec « 0 ligne sans produit ». Les 45,40 € étaient un ticket de 11:17, six lignes toutes rattachées à un produit, passé après la dernière lecture des tickets (le relevé du jour se garde dix minutes, l'heure par heure aussi, chacun de son côté).
- `svProduitsJour(..., $attendu)` : `/ventes/stats` passe pour aujourd'hui le nombre de tickets de l'heure par heure. Un relevé du jour qui en a lu moins se complète tout de suite, les nouveaux tickets seulement, une fois par minute au plus.
- `aCompleter.ticketsLus` (tickets des relevés de la période) et `aCompleter.luA` (aujourd'hui, l'heure de la dernière lecture, `HH:MM`).
- La carte : « Ventes sans produit » vaut les lignes sans produit et les remises sur ticket des relevés, pas l'écart entier. Le reste de l'écart se lit « X € de N tickets pas encore lus, dernière lecture à HH:MM ». Des tickets pas encore lus seuls ne montrent pas la carte.
- « Non ventilé » nomme ce qu'il contient : produits sans coût, lignes sans produit et remises, tickets pas encore lus (lecture de HH:MM).

**Bundles et marges partielles dans « Ventes par catégorie » (10/10/2026).** A : un bundle se range dans la catégorie du produit que nomme sa recette (« 2 Cookies » → « Cookies »), au prix du bundle et avec sa marge ; un bundle mixte se partage au prorata du coût de ses lignes (`svBundleCats`, recette lue à l'API du panel, partage gardé 24 h dans `bdlCat:{pid}:{shop}`). Sa ligne produit porte `bundle` : `{de, n, prix, part}`. Sans produit reconnu dans sa recette, il reste dans « Bundle ». La carte « Bundles vendus » ne change pas. B : chaque catégorie garde la marge de ses produits chiffrés : `c`, `m` et `taux` portent sur `vChiffre` ; `nSans` et `vSans` comptent ses produits sans coût, nommés sur sa ligne. Une catégorie n'affiche « ? » que si aucun de ses produits n'est chiffré. « Non ventilé » ne garde que la marge des produits sans coût, estimée par le P&L.

**En-tête et duel (10/10/2026).** Le sous-titre « Dashboard magasin · période · en direct » et la ligne « face à samedi dernier · clients · panier · au rythme de la journée » sont retirés.

**Recettes relues passées 10 secondes (10/10/2026).** La recette d'un produit au panel (`recetteDuProduit:{pid}`) et son coût pour le magasin (`recetteCout:{rid}:{shop}`) se gardent 10 s (`RP_SEC_RECETTE`), le partage des bundles aussi. `/ventes/stats` rechiffre les ventes sans coût des tickets au coût de la recette du moment (`svRechiffrer` : recettes et coûts lus en parallèle, 30 produits au plus, pièces entières) : une recette créée ou complétée au panel sort le produit de « Produits sans coût » à la relecture suivante, sans relire les tickets. La fiche produit relit sa recette quand elle a plus de 10 s.

**L'équipe du jour : chaque personne, heure par heure (20/10/2026, maquette A choisie par Sam).** Un clic sur une ligne du tableau (ou sur la barre de la personne dans la frise) déplie sous elle **une case par heure de son poste** : ce qu'elle a vendu (une barre à l'échelle de sa meilleure heure, €, tickets, panier, sa part des ventes du magasin dans l'heure), ses cuissons à l'heure du four, « production » quand le magasin ne vend pas encore (si elle tient un poste de production), « aucune vente à son nom », « pas encore de vente » pour l'heure en cours, « à venir » ensuite. En tête : la production jusqu'aux premières ventes du magasin, la vente ensuite, qui était au comptoir avec elle, l'heure de lecture des tickets. Un second clic sur la ligne la replie. Nouveau : `GET /exploitation/equipe-heures?shop=4&date=AAAA-MM-JJ` → `{personnes: {nom: {h6: [ca, tickets], …}}, sansNom, lu}` — la liste des tickets du jour au panel (`/shops/{id}/transactions?date=`, le vendeur `id_employee` nommé comme au planning, l'heure `insert_timestamp`, le montant), lue au premier dépliage pour ce seul magasin, gardée dix minutes (un jour lu le lendemain est clos et se garde : `eqH{magasin}:{date}`), relue avec le bouton de rafraîchissement.

**L'heure par heure : les articles par ticket (20/10/2026, demandé par Sam).** Chaque case horaire dit aussi le nombre moyen d'articles par ticket de la personne dans l'heure (« 2,4 art./ticket »). La liste des tickets du jour ne porte pas les articles : chaque ticket se relit avec ses lignes (`/transactions/{id}?include=products`), une seule fois — gardé ticket par ticket (`eqA{magasin}:{date}`), vingt secondes de lecture par passage ; le dashboard relit tant que ça avance (cinq fois au plus) et dit « articles : lecture… » en attendant. Un article = la quantité d'une ligne produit ; une ligne au poids (quantité non entière) compte pour un ; les lignes sans produit ne comptent pas. `GET /exploitation/equipe-heures` rend désormais `{personnes: {nom: {h6: [ca, tickets, articles, tickets aux articles lus]}}, aLire}` ; le relevé n'est gardé (`eqH…`, forme `v` 2) qu'une fois tous les articles lus.

**L'équipe du jour : les tickets à la minute (20/10/2026).** `GET /exploitation/equipe-heures` rend en plus `noms` et `tickets: [[minute du jour, rang du vendeur dans noms (-1 sans vendeur), montant, articles ou null]]` (relevé `eqH…` forme `v` 3). Le dashboard ne déplace aucun ticket : chaque vente reste au compte de qui l'a encaissée (une réattribution à la personne seule en vente a été essayée puis retirée le même jour, à la demande de Sam).

**L'e-mail « pointages suspects » de fin de journée (20/10/2026, demandé par Sam).** Une vendeuse seule au comptoir qui ne s'identifie pas à la caisse encaisse au compte de la session ouverte. Le dashboard laisse chaque ticket où il est ; le soir, le magasin reçoit le constat. Un cas = au moins 3 tickets encaissés au compte de quelqu'un qui n'est pas en poste à cette minute (parti, pas encore arrivé, pas au planning comme « Caisse 2 », ou sans vendeur) pendant qu'une seule personne était en vente (poste vente, caisse ou comptoir ; tout le monde si le planning ne dit pas de poste) ; dix minutes de grâce autour d'un poste ; deux personnes en vente = pas de cas. La phrase : « X (planning 13:00–18:30) était la seule personne en vente, mais n'a pas pointé à la caisse : de 13:12 à 18:09, 34 tickets (392,10 €) ont été encaissés au compte de Y, dont le poste se terminait à 13:00 » (« n'a pas toujours pointé » si elle a quelques tickets à son nom pendant ce temps). Une ligne du reporting (code `pointages-suspects`, quotidien, 21 h, par magasin) : carnet de la ligne, sinon celui de l'affiche du mois ; les destinataires réseau de la ligne reçoivent un récapitulatif de tous les magasins concernés. Un magasin n'est servi qu'une fois par jour (`psMailEnvoyes`) ; pas de planning ou pas de tickets lus = rien. « Générer » montre le récapitulatif sans rien envoyer. Le planning vient de `/exploitation/jour` (identifiant et horaires de chacun), les tickets de `/shops/{id}/transactions?date=` (vendeur et minute). `src/pointages_mail.php`, `bin/tests_pointages.php`.

**Promotions du magasin : le panel relu à l'ouverture de l'onglet (21/10/2026, demandé par Sam).** Une promotion créée ou changée directement au panel ne se voyait dans l'onglet qu'après les dix minutes de la lecture gardée. À chaque ouverture de l'onglet, la liste gardée s'affiche d'abord, puis le dashboard relit le panel (`GET /exploitation/promos-magasin?shop=&frais=1`) et remplace la liste dès que la relecture arrive (jamais par une lecture plus ancienne : `luTs`). En tête : « relu au panel à HH:MM » et un bouton « ↻ relire le panel ». La réponse porte `lu` (HH:MM), `luTs`, `luJour`. Une relecture demandée se contente d'une lecture de moins de 20 secondes (`PV_RELU_RECENT`) : deux onglets ouverts ensemble ne relisent pas deux fois le panel. Les fiches que la liste du panel ne rend pas se lisent ensemble, quatre de front (`ErpApi::getParallele`, qui refait le jeton sur un 401 et ne relance que les requêtes refusées), au lieu d'une à une — mesuré sur un faux panel à 0,3 s la fiche : 12 fiches en 1,2 s au lieu de 3,6 s.

**Promotions : la structure de l'API du panel (21/10/2026, demandé par Sam).** La documentation publique du panel (Swagger : `https://atelierby.tfbuddy.com/api/documentation/`, spécification `…/api/documentation/api.php`, OpenAPI 3, 933 routes) décrit les « Buy X Get Y » ainsi : `trigger` et `reward` portent `quantity`, `product_ids`, `category_ids`, `excluded_product_ids` ; une cible produit est un entier **ou `{id_product, id_product_portion}`** — la portion d'un produit se vise par son numéro ; une catégorie ne porte pas de portion ; `category_targets` / `portion_scope` n'y figurent plus. Une promotion se modifie par **`PATCH …/{id}`** (pas de PUT documenté). `status` : `DRAFT`, `ACTIVE`, `INACTIVE` (le cockpit garde `INACTIVE` pour ses brouillons) ; nouveaux : `activation_mode: VOUCHER_ONLY`, `voucher_campaign_id`. Le cockpit suit : (1) une portion visée part produit par produit, `product_ids: [{id_product, id_product_portion}]`, et une catégorie choisie avec une portion part en ses produits du magasin qui ont cette portion (hors exclusions) — `category_ids` vide ; un produit choisi sans cette portion au panel se dit (422) avant tout envoi ; (2) les portions de chaque produit se lisent à `/products/{id}/portions` (compte admin, sinon consultant ; type `ONE_QUARTER`/`ONE_HALF`, fraction 0,25/0,5 ou libellé « 1/4 »), sinon dans la table `product_portion`, et se gardent un jour (`pmPortions`) ; (3) modifier et activer : `PATCH` (corps complet), `PUT` en repli si le panel répond 404/405 ; (4) la relecture compare les produits avec leur portion (« 12:77 ») et reconnaît la portion d'une promotion relue par le numéro de ses portions (½, ¼ dans l'assistant). La sonde `GET /ventes/promos/sonde-portions?shop=4&q=tarte` lit aussi `/products/{id}/portions` et `/shops/{magasin}/products/{id}/portions/prices`, et dit ce que le cockpit en retient (`retenu`).

**Fiche franchisé : l'onglet Documents (22/10/2026, demandé par Sam).** Un troisième onglet de Fiche & scoring, à côté de « Ce magasin » et « Le réseau » : les documents du magasin choisi — rapports de réunion, contrats de mise à disposition, autres pièces —, par sorte, les plus récents d'abord. On dépose un ou plusieurs fichiers (PDF, Word, Excel, PowerPoint, OpenDocument, photos, texte, e-mails ; 25 Mo au plus, ou moins si PHP le limite) avec une sorte, un titre (sinon le nom du fichier), la date du document et une note ; on ouvre (un PDF ou une image s'affiche, le reste se télécharge), on télécharge, on retire (la ligne et le fichier). Routes : `GET /franchises/documents?shop=` → `{documents: [{id, sorte, sorteLib, titre, nom, mime, octets, date, note, deposeLe}], sortes, maxOctets, extensions}` ; `POST /franchises/documents` en **multipart** (`shop, sorte, titre, date, note, fichiers[]`) → `{ajoutes, refuses: [{nom, motif}]}` ; `GET /franchises/documents/fichier?id=[&telecharger=1]` sort le fichier lui-même ; `DELETE /franchises/documents?id=`. Les fichiers vivent sous `public/uploads/documents/{magasin}/` (préservé par le déploiement) sous un nom tiré au hasard, et un `.htaccess` y refuse tout accès direct : un contrat ne se lit que par l'API. Table `ceo_doc`. `src/documents.php`, `bin/tests_documents.php`. **Le mail « matériel mis à disposition »** (maquette validée par Sam le 22/10/2026) vit dans cet onglet, bloc « Courriers au franchisé » : matériel mis à disposition à l'état neuf ; l'entretien (nettoyage, courant, périodique) et toutes les pièces qui cassent (remplacement, réparations) à la charge du franchisé. Avant l'envoi : la liste du matériel (facultative), le contrat joint (un document « Contrat de mise à disposition » du magasin, le plus récent par défaut, ou sans pièce jointe), le destinataire (les adresses que les carnets des rapports connaissent pour ce magasin, ou une adresse saisie), et l'aperçu du mail tel qu'il partira. Envoyé, il est rangé dans la sorte « Courrier envoyé » (en PDF quand le serveur sait en faire, sinon en HTML), avec le destinataire et la pièce jointe en note. Routes : `GET /franchises/documents/mail-materiel?shop=[&materiel=&contrat=]` → `{sujet, html, adresses, contrats, smtp}` ; `POST /franchises/documents/mail-materiel {shop, a, materiel?, contrat?}` → `{ok, a, sujet, copie}` (un contrat de plus de 10 Mo est refusé : trop lourd pour un e-mail).

**Le CA net d'un mois, tous les magasins, par e-mail (22/10/2026, demandé par Sam : « net pas brut »).** En tête de Reporting automatisé, une carte d'envoi ponctuel : le mois (le mois clos par défaut, douze au choix), le CA net hors TVA de chaque magasin et du réseau, la TVA, le TTC et les tickets, un destinataire et « Envoyer par e-mail ». Net = TTC après remise − TVA, ticket par ticket (`total_gross_amount_after_discount` − `tax_amount_after_discount` des tickets du panel, `/shops/{id}/transactions?date=`), tous les tickets, clients pro compris. Une journée close se lit une fois (`caNet{magasin}:{jour}`) ; le mois se lit par lots de vingt-cinq secondes, l'écran relit tant que ça avance. L'e-mail ne part qu'un mois entièrement lu, avec la TVA de chaque ticket, et seulement à une adresse que le cockpit connaît (destinataires des rapports, compte SMTP — la règle des dossiers). Routes : `GET /rapports/ca-net?mois=AAAA-MM` → `{magasins: [{shopId, nom, brut, tva, net, tickets, jours, joursLus, sansTva}], reseau, aLire, complet, adresses, smtp, moisDispo}` ; `POST /rapports/ca-net/envoyer {mois, a}` (409 tant que le mois n'est pas lu ou qu'un ticket n'a pas de TVA, 422 pour une adresse inconnue). `src/ca_net_mois.php`, `bin/tests_ca_net.php`.

**L'e-mail « contrôle qualité non réalisé » de 19:00 (20/10/2026, maquette validée par Sam).** Une ligne du reporting automatisé, semée au premier chargement de l'écran Rapports : code `cq-non-realise`, quotidien, 19 h, par magasin — heure, activation et carnet d'adresses s'y règlent comme les autres. Au passage du cron horaire de 19 h, chaque magasin qui a des contrôles obligatoires ce jour-là et **aucune** photo (aucune tâche notée ni à contrôler ; « cochée sans photo » ne compte pas) reçoit un e-mail : le constat (« 0 photo sur N contrôles attendus »), le mot (« Bonjour, nous vous informons que les tâches de contrôle qualité n'ont pas été réalisées aujourd'hui… »), les contrôles attendus (tâche par tâche jusqu'à huit, par checklist au-delà), le mois en calendrier (une case par jour : contrôles faits avec photo sur attendus — réalisé, partiel, aucun, aucun contrôle prévu, pas encore relevé, à venir) et un bouton vers les contrôles du jour au dashboard. Un magasin avec au moins une photo, ou sans contrôle prévu, ne reçoit rien. Panel muet : aucun envoi. Adresses : le carnet de la ligne, sinon celui de l'affiche du mois. Un magasin n'est servi qu'une fois par jour, envoi à la main compris (`cqMailEnvoyes`) ; « Générer » montre l'e-mail d'un magasin concerné sans rien envoyer. Le lien du bouton garde l'adresse publique du cockpit vue par un navigateur (`cqMailBase`), le cron appelant par 127.0.0.1. Le mois se lit dans le relevé des tâches (`ceo_tache_jour`, tâches obligatoires), la journée en direct (`/pwa/tasks`).

**Le relevé des tâches : les tâches notées restent obligatoires (20/10/2026).** `/pwa/tasks` ne donnait pas aux tâches déjà notées le drapeau `obligatoire` du panel : le relevé les gardait « facultatives » (0) et le mois des tâches obligatoires (dashboard magasin, `?obligatoires=1`) les perdait — des jours faits paraissaient moins faits. Les tâches notées portent maintenant le drapeau du panel (`null` s'il ne le dit pas), le relevé garde `NULL` pour « inconnu », et les tâches notées déjà relevées à 0 repassent à `NULL`, une fois (`tachesNoteeObligReset`). Le scoring n'en dépend pas (une tâche notée est faite).

**Dashboard magasin, vue Opérationnel : l'équipe du jour (20/10/2026, maquette D choisie par Sam).** Dans « La journée », chaque barre de l'équipe se clique (et un bouton « L'équipe du jour ») : sous la carte s'ouvre **L'équipe du jour, à HH:MM**, une ligne par personne, la personne cliquée surlignée, rangée par ventes à l'heure de vente — planning (services du jour réunis, heures prévues), heures faites à l'heure qu'il est (le planning écoulé, pas une pointeuse), **vendu** et tickets (le vendeur sur le ticket), **par heure** (ses ventes ÷ les heures de son poste où le magasin a vendu : une boulangère qui cuit avant l'ouverture n'est pas pénalisée), panier et articles par client, **produit** (les cuissons dont l'heure de four tombe dans son poste, seulement si elle tient un poste de production : boulangerie, four, production, cuisine, pâtisserie, labo), **coût** (son taux horaire au panel × ses heures faites ; un franchisé est dit « hors P&L ») et **coût / ventes** ; les ventes faites par quelqu'un hors du planning ont leur ligne, et la ligne Magasin totalise. Un second clic sur la même barre, ou ×, referme. Côté serveur, `GET /exploitation/jour` porte pour chaque magasin `vendeurs: [{nom, ca, tickets, articles}]`, lu à `/shops/{id}/statistics/sales/employees/{date}` (null si la route ne répond pas : l'écran le dit et garde heures et coûts). Au passage, la lecture des douze semaines (`s12|`) retrouve sa garde côté serveur (`_cache=600`), perdue au passage de six à douze semaines.

**Dashboard magasin au téléphone : Contrôle allégé, « sur l'objectif » (20/10/2026, demande de Sam).** L'onglet **Contrôle** ne garde que les contrôles en photo et le bouton « Réclamer un produit au fournisseur » : le verdict, les tuiles (commandes, stock, poubelle…) et la note du jour en sortent (la note reste dans Exploitation). Le libellé de l'écart devient « d'avance sur l'objectif » / « sur l'objectif à ce jour » (vue Semaine au téléphone, « … % de l'objectif à ce jour »), et « en avance / en retard sur l'objectif » dans les tuiles Écart du trimestre et de l'année : « l'attendu » (l'objectif au prorata des jours servis) ne se disait pas.

**Dashboard magasin, douze semaines : clients, panier moyen, articles par client (19/10/2026, demande de Sam).** Un sélecteur à trois positions sur la section des douze semaines : **Clients** (tickets), **Panier moyen** (CA ÷ tickets), **Articles par client** (pièces vendues ÷ tickets) — la même courbe face au N-1, la même bande, les mêmes pastilles et tuiles (douze semaines : le rapport des sommes sur les semaines qui ont un N-1 ; la semaine en cours face au N-1 aux mêmes jours ; la semaine la plus haute). Le choix se garde dans le navigateur (`db.s12m`). Les pièces : `GET /ventes/semaines?…&articles=1` ajoute `articles` à chaque période et à son `n1`, lues à `/consultant/shops/sales-kpis` (`products`, un appel par fenêtre pour tout le réseau, une fenêtre close gravée `vsA:du|au`). Sans pièces lues, la vue Articles le dit. Les écarts arrondis à zéro s'écrivent « 0 % » (plus « −0 % »), ici et dans Résultat › Trafic clients.

**Dashboard magasin, vue Semaine : les douze dernières semaines face au N-1 (19/10/2026, demande de Sam).** La section « Les six dernières semaines » devient **« Les douze dernières semaines — clients, face au N-1 »**, la courbe de Résultat › Trafic clients pour un magasin : `GET /ventes/semaines?shop=&date=&n=13`, les douze semaines closes en courbe (2026 en trait plein, la même semaine un an plus tôt en pointillé, la bande entre les deux bleue au-dessus du N-1, orange en dessous), l'écart de chaque semaine en pastille (bleu, orange, gris autour de zéro), et trois tuiles : douze semaines face au N-1 (les semaines qui ont un N-1), la semaine en cours face au N-1 aux mêmes jours servis (hors de la courbe, qu'elle ferait plonger) — ou, pour une semaine passée, cette semaine face au N-1 —, la meilleure semaine.

**Résultat › Trafic clients : 12 semaines face au N-1 (19/10/2026, maquettes « A + B » choisies par Sam).** L'onglet « Analyse réseau » devient **Trafic clients** (même place, même clé `reseau`) ; ses étendues s'appellent 12 semaines, 6 mois, Année. En semaines, il lit `GET /ventes/reseau?vue=semaine&n=13` (`n` va désormais jusqu'à 13, pour `/ventes/semaines` aussi) et montre les **douze semaines closes** : la semaine en cours, servie un jour ou deux, ferait plonger la courbe et fausserait les cumuls — elle reste dans l'onglet Semaine. La courbe du réseau compare des **magasins comparables** (ceux qui ont un N-1 : `ticketsComp` face à `n1.tickets`), avec une bande entre les deux courbes, bleue au-dessus du N-1, orange en dessous (des couleurs que les daltoniens distinguent, comme les pastilles d'écart et « Qui fait l'écart » de l'onglet). Nouveau, **la carte des écarts** : une ligne par magasin, une colonne par semaine (ou par mois), l'écart au N-1 écrit dans chaque case et coloré, le réseau en tête, le magasin qui décroche le plus en premier, « — » sans N-1 ; un clic sur un magasin ouvre sa courbe face au N-1 sous la carte (un second clic ou × la referme). Le tableau magasin par magasin se range lui aussi du plus gros recul au plus gros gain. Rien d'autre ne change au serveur.

**Boutons et listes : des rectangles aux coins arrondis (18/10/2026, demande de Sam).** Dans la console, le dashboard magasin, Production, Planogramme et Marketing, les boutons, les listes déroulantes, les champs, les onglets et les filtres ne sont plus des pilules : `border-radius: 999px` devient `8px` (les boutons des onglets segmentés, dans leur cadre, `6px`). Les petites étiquettes gardent presque leur forme (8 px sur 18 à 20 px de haut). Restent ronds : les interrupteurs (Obligatoire, envois de la newsletter, filtres du planogramme), les pastilles et points de couleur, les compteurs du rail. Les applications à part (employés, tablette, assistant) ne changent pas. Rien ne change au serveur.

**Catalogue : le 20/80 à imprimer (18/10/2026, demande de Sam).** Le bouton « ⎙ PDF 20/80 » du Catalogue imprime, en paysage, les références qui font ensemble 80 % du chiffre du réseau sur la période du score (Mois clos, Trimestre, 12 mois), la plus grosse d'abord. Page 1 : leur nombre face à toute la gamme, leur chiffre, leur marge nette pondérée, la courbe de Pareto, puis le tableau — rang, référence, catégorie, chiffre, part, cumul, volume, prix moyen, marge nette, score, décision, obligatoire. Puis **le 20/80 de chaque catégorie** : les références qui font 80 % du chiffre de leur catégorie, deux catégories par rangée. Puis **l'assortiment proposé** (demande de Sam : « à part les produits 20/80, 6 classiques + 2 de saison pour chaque catégorie ») : dans chaque catégorie, son 20/80 se garde d'office ; s'y ajoutent les 6 classiques (dans aucune gamme saisonnière seule, `saisonnier` du catalogue) au meilleur score de la période, vendues sur la période, et, pour chaque saison du panel (`/production/saisons`), les 2 saisonnières de sa gamme au meilleur score **sur les 12 derniers mois** (`/products/scoring?fenetre=annee`, lu à la demande : leur saison y est, quelle que soit la période de l'écran) ; une saisonnière de deux gammes sert les deux saisons. Le reste sort de la proposition (les obligatoires qui en sortent sont dites) ; une référence retenue que le score dit « Effacer » est une place à remplacer, une place sans classique vendue reste libre. Mise en page (refaite le 18/10/2026, « illisible, non compréhensible ») : une fiche par catégorie, en quatre colonnes qui se lisent de gauche à droite — 1 À garder (avec sa part du chiffre de la catégorie), 2 Les 6 classiques, 3 Deux produits par saison (la saison, ses dates, ses deux produits), 4 À retirer (obligatoire aujourd'hui, jamais vendu, de saison mais pas dans les deux meilleurs) —, une légende en tête et le total en une phrase ; en gros caractères, sans score brut ni emoji (le moteur PDF du serveur les imprime mal). Ensuite, **le prix et le volume magasin par magasin**, pour ces références : combien le magasin en vend, son prix encaissé face au prix réseau (la médiane, en %), combien il en vend plus cher et moins cher (2 % et 5 centimes d'écart au moins), son volume à taille égale (`rel` de `/analyse/prix-volume`), ce que coûterait l'alignement au prix réseau (€ sur la période) et celles qu'il ne vend pas. Enfin, référence par référence : le prix réseau, l'écart entre magasins et, pour chaque magasin, le prix (en couleur hors du prix réseau), les pièces et ▲/▼ face à la moyenne. Les prix sont ceux de `/analyse/prix-volume` sur la même étendue (`mois` 1, 3 ou 12). L'écran envoie ses lignes, le serveur met en page : `POST /products/pareto.pdf` `{periode, periodePrix, seuil, caTotal, totalRefs, courbe[101], magasins[{id, nom}], lignes[{nom, cat, ca, vol, prix, mn, score, decision, oblig, med, ecart, mag{id: {p, q, rel, auMed}}}], refs[{k, nom, cat, ca, mn, score, decision, oblig, saisonnier, saisons[], scoreAn, caAn}], saisons[{id, nom, du, au}], saisonsLues, classiques: 6, parSaison: 2}` (`refs` : toute la gamme ; sans `refs`, ni 20/80 par catégorie ni proposition) → `application/pdf` (`catalogue-20-80-AAAA-MM-JJ.pdf`) ; 422 sans ligne, 501 sans moteur PDF (`php bin/tests_catalogue.php`).

**Catalogue : rang, saison, tri par colonne (18/10/2026, demande de Sam).** La liste gagne trois colonnes : **Rang** — le rang au chiffre d'affaires sur toute la gamme (« 4 / 230 ») —, **Rang catégorie** — le rang dans sa catégorie (« 1 / 18 »), une colonne à part qui se trie aussi —, ceux du score (`pdCalcule` : `rangGlobal`, `rang`) — et **Saison** — les gammes saisonnières du panel où la référence est vendue (`saisons` du catalogue, noms et emoji de `/production/saisons`), « seulement en saison » quand elle n'est dans aucune gamme permanente. Un filtre « Toutes les saisons / Hors saison / une saison » rejoint la barre d'outils. Chaque en-tête porte une flèche : un clic trie la colonne, un second inverse, les cases vides restent en bas ; une question remet son ordre. L'export CSV porte la saison et les deux rangs. « ↺ Réinitialiser » (visible dès qu'un filtre est posé) remet la recherche, la catégorie, la saison, la question et le tri.

**« Nécessaire » fondu dans « Obligatoire », la marge nette, la part des ventes (18/10/2026, demandes de Sam).** Il ne reste qu'une case, **Obligatoire** : imposée en boutique (l'assortiment obligatoire, son minimum, le plan de production) et toujours gardée au score (décision « Garder », `pdCalcule` lit `must` au catalogue). À la première requête qui suit la mise en ligne, `aoFusionNecessaire()` (appelée par `ensureInstalled`) rend obligatoire toute l'année, sans minimum, chaque référence cochée « nécessaire » (une obligatoire de saison reste ce qu'elle est), note chaque passage au journal (« Assortiment obligatoire ») et remet `ceo_prod_revue.necessaire` à zéro — une fois pour toutes (`php bin/tests_assortiment.php`). La route `PUT /products/{id}/necessaire` est retirée ; les PDF d'arbitrage et de catégorie disent « obligatoire ». Dans le Catalogue, un interrupteur **Obligatoire** sur chaque ligne (un clic, sans ouvrir la fiche ; retirer se confirme) et la case de la fiche écrivent `PUT /production/obligatoire/{ref}`. La **marge nette** est (prix de vente moyen − coût matière première) ÷ prix de vente moyen, en % — celle du score — dans la liste, la fiche et l'export. La colonne **Part des ventes** : le chiffre de la référence ÷ celui de toutes les références du réseau, sur la période du score.

**Le Catalogue refondu : quatre questions, une liste, une fiche (18/10/2026, maquette « A + D » choisie par Sam).** `#/catalogue` n'a plus d'onglets. En tête, quatre questions, chacune avec son chiffre, qui filtrent la liste (un second clic retire le filtre) : **Que retirer de la gamme ?** (les références à la décision « Effacer » du score), **Où vend-on moins que le réseau ?** (le manque à gagner réseau par mois, `/produits/manque`), **Les prix sont-ils tenus ?** (les références dont le prix encaissé varie de 10 % ou plus entre magasins, `/analyse/prix-volume`), **Qu'est-ce qui décroche ?** (−15 % ou pire : la 2e moitié des derniers mois face à la 1re, réseau entier, `/analyse/produits`). Puis une seule liste : Référence, Prix, Marge nette (prix de vente moyen − matière première, en %), Volume, Part des ventes, Score, Magasins, Tendance, Décision, Obligatoire — plus le manque par mois ou l'écart de prix quand la question le demande. Un clic ouvre la fiche à droite : **Magasins** (pièces face à la moyenne, prix encaissé face au prix réseau, manque à gagner là où elle manque), **Marges** (prix catalogue et encaissé, coût matière, marges brute et nette, commission, ce qui fait le score), **Dans le temps** (la courbe réseau, la tendance), **Fiche** (la fiche de production en lecture). Les gestes d'avant y restent : revue ★, Obligatoire, « Score en détail · projets · arrêt » (la fenêtre du scoring : envoyer aux projets, arrêter le produit), « Fiche de production » (la fenêtre d'édition), la perte par magasin ; la barre d'outils garde la période du score, les seuils Garder / Modifier (enregistrés pour le réseau), le PDF d'arbitrage, le PDF de la catégorie et un export CSV. Les lectures détaillées s'ouvrent de la fiche ou de la question, sans onglet, avec un retour « ‹ Catalogue » : une référence magasin par magasin (`#/analyse-produits`, la courbe jour par jour et l'effet des promotions), Prix × volume, Dans le temps, Manque à gagner. Retirés : les écrans Scoring, Sous seuil et Par magasin (`#/scoring`, `#/sous-seuil`, `#/usage-catalogue` mènent au Catalogue) et l'ancienne liste des fiches. **Assortiment obligatoire** devient un onglet de **Comptoir · planogramme** (onglets Comptoir, Assortiment obligatoire). Rien ne change au serveur.

**Produits : le Catalogue réunit les lectures d'une référence (18/10/2026, demandes de Sam).** Le rail Produits a deux entrées. **Catalogue** (`#/catalogue`) a neuf onglets : Fiches, Scoring, Sous seuil, Par référence, Par magasin, Manque à gagner, Prix × volume, Dans le temps, Assortiment obligatoire. Scoring et Sous seuil viennent de « Gamme · scoring », Par référence, Par magasin, Manque à gagner et Prix × volume de « Où ça se vend » (qui quitte le rail), Dans le temps de son entrée ; chaque onglet garde son écran, son contenu et la phrase d'explication de son ancien écran. **Comptoir · planogramme** (`#/planogramme`) sort du Catalogue et redevient une entrée du rail, sous Produits. Les adresses ne changent pas et ouvrent l'onglet du Catalogue : `#/scoring`, `#/sous-seuil`, `#/analyse-produits`, `#/usage-catalogue`, `#/manque-a-gagner`, `#/prix-volume`, `#/analyse`. Les badges : au Catalogue, les références que le scoring propose d'effacer ; au Comptoir, les obligatoires sans place au comptoir. La recherche du rail trouve aussi les écrans tenus en onglets (« Scoring produits · Produits · Catalogue »). Rien ne change au serveur. *(Remplacé le 18/10/2026 par le Catalogue en une liste et sa fiche, ci-dessus.)*

**Gestion consultant et Plan de développement retirés de la console (18/10/2026, demande de Sam).** Les deux écrans quittent la console, code compris : Gestion consultant (`#/gestion-consultant` — planning du consultant, tâches de toutes sources, réseau consultants × magasins, cadre de visite et types de visite) et Plan de développement (`#/plan-developpement` — rampe de l'étude, engagement de l'année, actions, rituel trimestriel et son PDF). Leurs entrées du rail (« Suivi de terrain › Gestion consultant », « Magasins › Plan de développement ») disparaissent, avec leurs gabarits, leurs valeurs et leurs styles (`.gc-*` propres à l'écran ; ceux que la fiche franchisé partage restent). Les anciennes adresses mènent ailleurs (`App.ADRESSES_RETIREES`) : `#/gestion-consultant`, `#/taches-consultants` et `#/taches` à Fiche & scoring, `#/plan-developpement` à Résultat. Le dashboard magasin garde sa carte « Les annotations du trimestre » (`GET /plan`) sans le lien vers le cockpit ; l'application visites ne renvoie plus au cockpit pour régler les types et le cadre de visite. Rien ne change au serveur : `/consultants/gestion`, `/visites/cadre`, `/consultants/taches`, `/plan`, `/plan/*` et `/plan.pdf` restent, comme leurs tables. La console ne lisait ces routes qu'à l'ouverture des écrans : rien ne sort du chargement.

**Promotions du magasin : le panel seul (18/10/2026, demande de Sam : « API seule, plus de copie »).** Le cockpit ne garde plus rien des promotions du magasin : la liste `promosMagasin` de `ceo_app_setting` n'est plus lue ni écrite (ce qui y reste n'est plus utilisé). Une promotion du magasin est une « Buy X Get Y » du panel réservée à ce seul magasin (`SELECTED_SHOPS`, `shop_ids: [magasin]`) : active, elle est **en caisse** ; inactive, c'est un **brouillon**. Le dashboard la lit au panel (`pvRegles()`, la liste gardée 10 minutes et mise à jour à chaque écriture du cockpit) ; « Enregistrer » l'écrit au panel (POST pour créer, PUT pour modifier — créée inactive, relue, activée), « Garder en brouillon » fait la même écriture laissée INACTIVE, « Supprimer » un brouillon est un `DELETE` au panel. Plus d'états « Enregistrée · pas encore en caisse » ni « Renvoyée par la marque » : un panel qui refuse ne crée rien (502, l'assistant reste ouvert avec l'erreur) ; créée mais pas activée, elle reste au panel comme brouillon (`panelErreur`). Le numéro d'une promotion du magasin est celui du panel (`id: "p21"`, `panelId: 21`). La console (Analyse magasin › Promotions) lit au panel les promotions réservées à un seul magasin, en lecture seule, avec le coefficient du lot d'aujourd'hui : plus de « Réessayer au panel », « Mise en caisse ✓ » ni « Rendre » (les routes `POST /promos-magasin/{id}/panel` et `PATCH /promos-magasin/{id}` sont retirées). La liste du panel se lit **page après page** puis aux numéros qui suivent le plus grand, jusqu'à trois absents de suite (`pvListe()`) : « Test » n° 21 et « Test 2 » n° 22, en caisse, manquaient à la liste lue d'un coup — et donc à la reconnaissance des promotions dans les tickets. La sonde `GET /ventes/promos/sonde-fiche` rend `numerosLus`, tous les numéros que le cockpit lit.

**Contrôle des tâches : plus de contrôle par exception (18/10/2026, demande de Sam).** Toute photo rendue est à noter, une photo obligatoire comprise, quelle que soit la moyenne des contrôles passés : plus de tâche « maîtrisée » masquée. `GET /pwa/tasks` ne porte plus `taches[].maitrise` ni `totals.masquees`, et `totals.aControler` / `aValider` comptent toutes les photos non notées. Retirés : `PUT /taches/maitrise` (Rouvrir, contrôle permanent), `maitriseReglages()` et `maitriseEtats()`, la création de `ceo_task_maitrise` et du réglage `maitrise` à l'installation (la table et le réglage déjà en base restent, plus lus). À l'écran (`#/controle-taches`) : plus de badge ni de filtre « Maîtrisées », plus de bandeau « contrôle(s) maîtrisé(s) » ni de bouton Rouvrir ; l'application visites montre toutes les tâches du jour, et la loupe photo du dashboard magasin perd sa ligne « Tenue ».

**Les promotions du magasin (17/10/2026).** Le dashboard magasin a un onglet **Promotions** (sur ordinateur) : le magasin du dashboard (`?shop=`), lui seul, la liste des promotions qui touchent le magasin (celles de la marque, lues au panel, et les demandes du magasin) rangée par statut — Actives, À venir, À valider, Brouillons, Terminées, Toutes — et par sorte, avec une recherche ; « + Nouvelle promotion » ouvre l'assistant « X achetés + Y » en cinq étapes (une catégorie ou des produits, des exclusions, offert / prix fixe / remise € / remise %, le moins cher d'abord, du … au …, créneaux de la semaine, répétable et nombre maximal par ticket), avec le récapitulatif, le **calculateur de coefficient du lot** (×2 en repère, non bloquant, le cas le plus serré nommé) et le ticket de ce cas. Pas de validation : « Enregistrer la promotion » l'enregistre aussitôt (sous ×2 aussi, depuis le 18/10/2026) ; la marque la met en caisse ou la renvoie avec un mot (console, Analyse magasin › Promotions). Lecture et écriture : `/exploitation/promos-magasin` (ci-dessous).

**Commandes et canaux, au mois (17/10/2026).** Dans le dashboard magasin, vue Mois, la carte « Commandes et canaux » a quatre tuiles : Comptoir (hors clients pro), Clients pro — B2B, Click & collect, Livraison. Chacune porte la courbe de ses six derniers mois (le mois en cours projeté, en pointillé) et l'écart au mois d'avant aux mêmes dates (« +44 % vs sept. ») ; un clic ouvre son cumul du mois face au mois d'avant, avec la fin de mois au rythme actuel, et un second clic le referme. Lecture : `GET /exploitation/canaux-mois` (ci-dessous). Les vues Jour et Semaine ne changent pas. Tests : `php bin/tests_canaux.php`.

**Les posts au portail social (17/10/2026).** Les posts Facebook, Instagram et LinkedIn se créent, se valident et se publient dans le portail social (`/social`, dépôt `samsam2703MFC/social`). Le portail Marketing perd son circuit des posts : plus de rédacteur (Créer › Post), ni de Valider, ni de Publier, ni des paramètres Publication et Agent de contrôle. Il garde Tableau de bord, **1 · Planifier** (campagnes et newsletters, calendrier), **2 · Newsletter**, **3 · Mesurer** (newsletters, avis Google, posts sauvages) et Paramètres (API et tables, charte, pages, avis) ; le rail, le tableau de bord, les campagnes et le calendrier mènent au portail social (« + Post ↗ », « Préparer un post ↗ »). Les anciennes adresses `onglet=valider|publier|social` et `onglet=creer&vue=post` y mènent (avec `?shop=`, à l'espace franchisé `social/franchisee/?shop=`) ; `onglet=creer` est la newsletter. Dans la console, le hub Marketing : « + Post » ouvre `social/SA Post.dc.html`, la porte « Vue franchisé » l'espace franchisé du magasin, la tuile Posts le portail social ; `#/controle-posts-facebook` mène à `/social/`. Au serveur, `mhAFaire()` (`GET /marketing/hub`, `aFaire`) ne liste plus de post ni de campagne « sans prise de parole » (les posts du portail social ne s'y voient pas) ; le canal `social` du hub reste lu, les routes `/marketing/social/*` et `/marketing/publication/*` restent.

**Fiche & scoring allégée (16/10/2026).** La fiche n'a plus que deux lectures, Ce magasin et Le réseau. Retirés : le parcours de visite (Agenda, Checklist, Fin de visite · météo et l'habillage tablette), les tuiles Dernière visite, Prochaine visite, Plans d'action, Client mystère et Météo du franchisé, les colonnes Dernière visite et Plans ouverts du réseau, et le lien « Visites ↗ » du rail. La console ne lit plus `/consultants/checklists` ni `/franchises/meteo` (les routes restent au serveur). Le poste Client mystère reste dans le scoring : il s'encode dans Paramètres › Client mystère (`#/client-mystere`, le formulaire de l'ancien écran) ; « modifier » sur le poste du bandeau y mène, le magasin choisi. Remarques opérateurs est retiré (`#/remarques-operateurs` mène à la fiche, dont la tuile Remarques · 30 j garde le détail par opérateur).

**Fiche & scoring (16/10/2026).** Fiche franchisé et Scoring du trimestre ne font qu'une entrée, « Fiche & scoring » (`#/fiche-franchise`), qui s'ouvre sur « Ce magasin » : le scoring du trimestre en bandeau (note sur 20, étoiles, rang, écart au trimestre précédent, les quatre postes avec leur détail, rapport A4), « Commencer la visite » et « Encoder le client mystère », puis la santé du magasin et ses deux volets. « Le réseau » réunit le classement du trimestre (`GET /scoring`) et le benchmark (`GET /franchises`) dans une seule table, avec le choix du trimestre, le rapport A4, l'envoi aux magasins, les règles et le carnet d'envoi. « Client mystère » est le formulaire d'encodage de l'ancien écran. La visite garde ses trois étapes (Agenda, Checklist, Fin de visite · météo). `#/scoring-trimestre` mène au réseau (ou au client mystère s'il était ouvert). Rien de nouveau au serveur.

**Résultat reprend Performance (16/10/2026).** Résultat a sept onglets : Jour, Semaine, Mois, Année, 3 et 5 ans, Marge & coûts, Analyse réseau (Trafic clients depuis le 19/10/2026). Année et 3 et 5 ans montrent la trajectoire (l'ancien Objectifs, horizon 1 an ou 3/5 ans — le sélecteur d'horizon change d'onglet), Marge & coûts l'ancien onglet du même nom ; le mois clos (Performance par magasin et les trois tableaux de douze mois) se lit sous l'onglet Mois, sous « Le dernier mois clos ». Mêmes lectures qu'avant (`/stores/perf`, `/stores/budgets`, `/targets`…), rien de nouveau au serveur. Performance n'a plus d'entrée : `#/performance` mène à Résultat, `#/magasins` à l'onglet Mois, `#/objectifs` à Année, `#/marge` à Marge & coûts.

**Marketing : une entrée, deux portes (16/10/2026).** Le rail « Marque & marketing » n'a plus qu'une entrée Marketing (`#/marketing`), à la place de « Module Marketing ↗ » et « Campagnes ». Onglet Aujourd'hui : l'espace marque (portail `marketing/`, + Campagne → `assistant/`, + Post et + Newsletter → `marketing/?onglet=creer`), la vue franchisé (`marketing/?shop={id}`, lien à copier), les chiffres par canal et la liste « à faire » de `GET /marketing/hub` (la même lecture que le tableau de bord du portail ; publication réelle éteinte et newsletter en mode test signalées). Les onglets Campagnes, Calendrier, Budget × Campagnes, Objectifs produits et Mesure restent ceux de la console. Le badge du rail compte les posts à traiter.

**Jours creux retiré de la console (16/10/2026).** L'écran (carte jour × heure, assistant de promotion, promotions adoptées, catalogue des mécaniques) quitte la console ; `#/jours-creux` mène à Résultat. Les routes `/creux` et `/promo/*` et les tables `ceo_promo`, `ceo_promo_mecanique` restent : l'analyse produits marque toujours les promotions sur ses courbes. Les promotions ne partaient pas en caisse : rien ne change en magasin.

**Console : production, prospection, centrale d'achat, offres et canaux sortis (16/10/2026).** Gestion de production quitte la console : l'application `production/` ouverte seule montre ses cinq pages en onglets (`?page=params|plan|suivi|cloture|fours`, Suivi par défaut) — elle ne montrait que le Suivi depuis le 06/10. Prospection quitte la console : `prospection/` prend la mise en page bureau au-delà de 900 px (carte, calcul, export CSV), la mise en page téléphone en dessous. Posts Facebook, Newsletter et Newsletter magasin quittent la console : le portail `marketing/` les tient (même module newsletter.js, mêmes routes). Calendrier et Campagnes restent : le portail ne supprime pas une campagne et n'envoie pas la note aux franchisés. La centrale d'achat (Commandes, Demande de prix, Campagnes commerciales, Facturation magasins, Paramètres › Centrale d'achat, et ses cartes de Paramètres › Général : rappel fournisseur, relance « commande à valider », journal des envois) et Offres et canaux sont retirés. Les routes `/centrale/*` restent au serveur ; `bin/rapports_cron.sh` n'appelle plus `/centrale/commandes/mail/cron`. La commission de marque (`caParams().commissionMarquePct`) garde sa valeur et sert toujours la marge nette du catalogue, mais ne se règle plus depuis la console. Les anciennes adresses ouvrent l'application qui a repris l'écran (`App.ADRESSES_SORTIES`) ; celles de la centrale et d'Offres et canaux mènent à Résultat. La console ne charge plus au démarrage `/exploitation`, `/marketing/social/regles`, `/marketing/social/posts`, `/marketing/brand-guard/stats` ni `/marketing/brand-guard/pages`.

**Prospection : export CSV (16/10/2026).** L'écran Prospection (bureau) exporte ma liste en CSV (« ⤓ Exporter (CSV) », point-virgule, BOM pour Excel) : lieu, type, genre, offre, adresse, téléphone, site, distance, taille, zoning, statut, visite, action à suivre et sa date, note. Il remplace l'export de Développement commercial.

**Console consultant allégée (16/10/2026).** Écrans retirés, code compris : Leviers (`analysemag`), Tâches consultants, P&L magasins (Exploitation), Heatmap mensuelle, Suivi de production, et la vue « Commandes » morte de la centrale. Leurs adresses mènent là où vit leur contenu : `#/taches-consultants` → Gestion consultant, `#/pl-magasins` et `#/heatmap` → Résultat (la heatmap reste sous Résultat › Mois), `#/analyse-magasin` → Équipe & ventes. Routes retirées, sans appelant : `GET /magasin/analyse`, `/magasin/analyse.pdf` (`src/analyse_magasin.php` supprimé), `/exploitation/magasin`, `/production/suivi`. Gestion de production : une entrée de menu au lieu de cinq, les cinq étapes en onglets (mêmes adresses `#/production-*`). Prospection : une seule entrée ; Développement commercial et Prospection mobile y sont fondus (`#/developpement-commercial` et `#/prospection-mobile` → `#/prospection`, lien du téléphone et « copier le lien » dans l'en-tête). L'export CSV de Développement commercial n'a pas d'équivalent dans Prospection. L'écran ne lit plus `/scouting/demarchage` pour rien : le module Prospection lit ses lieux lui-même.

**Analyse magasin sans l'onglet « Leviers » (16/10/2026).** L'écran Analyse magasin garde trois onglets : Équipe & ventes, Panier · croisements, Réputation. L'entrée « Analyse magasin » du rail ouvre Équipe & ventes ; l'ancienne adresse `#/analyse-magasin` (et l'identifiant `analysemag`) y mène aussi. Le code de l'écran Leviers reste en place, sans entrée.

**La vitrine, ce qui va manquer (10/10/2026).** Au bureau et sur tablette, la carte « La vitrine » (Opérationnel) montre d'abord ce qui va manquer. Une liste déroulante par durée de vie, fermée au départ, résume dans son en-tête : combien vont manquer, combien cuire, les ventes à sauver ; au bout de l'en-tête, trois petits carrés de filtre discrets, propres à chaque durée de vie, avec leur nombre : rouge « Vide maintenant », orange « Va manquer », vert « En ordre » (éteint au départ). Un clic sur un carré filtre cette durée de vie seule et l'ouvre. Short et medium life : un produit manque quand il n'y en a plus au bout d'une heure alors que la prévision de l'heure suivante en demande (et au moins une demi-pièce d'ici la fermeture) ; la ligne, sur une seule ligne, porte à gauche une micro-vignette discrète avec l'heure de la rupture (rouge pâle : vide ; cadre orange : va manquer ; vide : en ordre ; long life : le stock), le reste du texte en infobulle, puis sa journée heure par heure (vert, orange, rouge ; pâle à venir), les pièces et les euros qui manqueront, et combien en mettre dans la prochaine cuisson. Long life : le stock face à trois jours de vente, et ce qu'il faut refaire. Un clic ouvre la fiche de stock du produit. Données : `/production/flux/suivi`. Le téléphone garde l'ancienne vitrine.

**La fiche de stock de La vitrine (10/10/2026).** Un clic sur une ligne de La vitrine ouvre la fiche de stock du produit, la réponse d'abord : « Recuire N pièces dans la cuisson de 14:00 » (ou maintenant) avec son calcul, à vendre d'ici la fermeture − en vitrine − déjà prévu = à recuire, ou « Pas de recuisson » avec ce qui restera ce soir ; long life : de quoi tenir trois jours de vente. À vendre d'ici la fermeture : la prévision des derniers mêmes jours (`prevH`), l'heure entamée au prorata. En vitrine : `stock`, jamais sous zéro (un stock négatif au panel veut dire des ventes au-delà des sorties déclarées, la fiche le signale). Déjà prévu : les lignes du produit dans les cuissons à venir non validées (`cuissons[].lignes[].sortie`). À recuire : l'arrondi supérieur de ce qui manque, à un quart de pièce près, pour finir la journée au plus juste. Le graphique de la journée heure par heure : la moyenne vendue des derniers mêmes jours (`moy`), le vendu d'aujourd'hui (`vc`), la vitrine au bout de chaque heure (`cases[].q`), les cuissons du jour, et la vitrine à venir avec la recuisson proposée. À droite, les cuissons du jour, la proposition, et la journée sans et avec recuisson. Un lien ouvre la fiche du produit. La carte suit le même calcul : « vide maintenant » veut dire la vitrine à zéro et encore à vendre (une rupture du matin réapprovisionnée ne compte plus), « va manquer » la première heure à venir où elle sera vide, et le « + N » à recuire est celui de la fiche.

**Contrôle des tâches : toutes les photos du jour (10/10/2026).** Le rail des photos du cockpit (`#/controle-taches`) garde les tâches maîtrisées (contrôle par exception) : leur photo s'affiche, badge « maîtrisée », moyenne et date de re-contrôle, avec un filtre « Maîtrisées ». La liste « à noter » les écarte toujours. Une tâche maîtrisée non rendue reste hors du rail. *(Remplacé le 18/10/2026 : plus de contrôle par exception, toute photo est à noter.)*

**`/stores/perf` et la caisse locale (10/10/2026).** Les mois d'avant juillet 2026 sans P&L mensuel (Halle de février à juin) viennent de la caisse locale, qui ne bouge plus. Sa lecture se garde six heures (`perfTx:{année}`) et sert encore quand la requête dépasse ses 6 secondes : la frise des mois ne montre plus « pas de vente » pour ces mois quand la page charge beaucoup en même temps.

**Ventes d'une catégorie sur 12 semaines (10/10/2026).** `GET /analyse/categories/magasin?shop=4&niveau=categorie|groupe&nom=Tartes` renvoie `semaines` : `tranches`, `bornes`, `jours`, `magasin` et `reseau` en euros (le réseau : la moyenne par magasin), `magasinQ` et `reseauQ` en pièces, `muettes`, `magasins` ; avec `groupe` (le groupe d'une catégorie) ou `categories` (les catégories du groupe que le magasin a vendues). Mêmes tranches que la fiche produit ; la catégorie d'un produit est celle du catalogue. Dans « Ventes par catégorie », chaque groupe et chaque catégorie porte un petit (i) discret : il ouvre la seule courbe, en chiffre d'affaires ou en pièces, sans déplier la ligne.

Mesuré le 04/10/2026 : `/exploitation/jour` faisait ~45 appels au panel (tous les magasins, le
classement réseau du dashboard en a besoin), 15 à 60 s à chaque affichage ; `/exploitation/periode`
10 s. Le calcul se garde désormais dans `ceo_app_setting` (`exJour:{date}`,
`exPer:{vue}:{date}` : `{le, ttl, r}`), pour tous les appelants :

| Réponse | Fraîche pendant |
|---|---|
| jour, aujourd'hui | 3 min |
| jour, hier | 15 min |
| jour, avant | 1 h |
| semaine ou mois en cours | 5 min |
| semaine ou mois clos | 1 h |
| une réponse du panel a manqué (« sans réponse ») | 1 min |
| `indispo` | pas gardée |

Chaque réponse porte `cache: {le, age, frais, relu}`. Périmée de moins d'une demi-heure, elle
part tout de suite (`frais: false, relu: true`) et le serveur la recalcule en arrière-plan :
après la réponse sous PHP-FPM ; sinon (le serveur en ligne tourne sous mod_php, mesuré le
04/10/2026) par une requête à lui-même, `http://127.0.0.1/…/api/cockpit/exploitation/…&fond=1`,
qu'il n'attend pas (la route continue seule, `ignore_user_abort`) — pas pendant un calcul en
cours, pas deux fois par minute (`rcRelance:{clé}`), et si elle est refusée (une
authentification), le calcul se fait pendant l'appel. Le dashboard relit une réponse `relu` une
fois, trente secondes plus tard. Plus vieille qu'une demi-heure, ou sans cache, la réponse se
calcule pendant l'appel, sous verrou MySQL : des appels simultanés attendent le même calcul.
`?rafraichir=1` force un calcul neuf (le bouton « mettre à jour » du cockpit, qui affiche
l'heure du calcul, `cache.le`). Le PDF du mois ne part jamais d'un calcul périmé. Le cron
horaire des rapports recalcule le jour, la semaine et le mois d'aujourd'hui (`resultat` dans sa
réponse). Les appels au panel partent en file, six de front : dès qu'une réponse arrive, la
requête suivante part (`PanelApi::getParallele`), au lieu de paquets qui attendaient chacun
leur appel le plus lent.

### `GET /exploitation/canaux` — par où passent les commandes : comptoir, click & collect, livraison

    ?shop=4&date=2026-10-02            un magasin, la journée
    ?shop=4&du=2026-09-28&au=2026-10-04   un magasin, une période (la semaine, le mois du dashboard)
    ?periode=jour|7|30&date=…          le réseau, magasin par magasin (sans shop)

La source est double et mesurée : les **commandes du panel**
(`/shops/{id}/client-orders?date_from=`, gardées dix minutes dans
`ceo_app_setting` sous `coCmd:{shop}:{du}`, sans rien de nominatif — quand,
canal, montant, articles, statut, encaissée) et la **caisse** (les heures
gravées, `svHeuresJours`). Une commande est **webshop** si `is_webshop` est
posé ou si `fulfilment_mode` parle de livraison ; livraison si
`fulfilment_mode` contient `deliv`, `livr`, `ship` ou `office`, sinon click &
collect. Une commande non webshop est une précommande au comptoir : elle
n'entre pas dans le split. Une commande annulée (`non_collection_id_reason`)
est ignorée. Le statut se lit dans `order_status` et `issuing_timestamp` :
à préparer, en préparation (`accepting_timestamp`), prête
(`completion_timestamp`), en route, remise (click & collect) ou livrée —
une commande encaissée en caisse (`id_transaction`) compte remise, mesuré à
Halle : le panel la laisse « new ».

**Le comptoir = tickets caisse − les commandes webshop encaissées en caisse**
(`id_transaction` posé) ; une commande payée en ligne s'ajoute au CA du jour.
Les clients pro restent dans le comptoir (le dashboard les dit « dont »).

Un magasin :

    { shop, date,
      jour:     { joursLus, caisse, tickets, comptoir, cc: {n, ca}, liv: {n, ca}, webshop, encaisse, total, part },
      serie:    [ { j, lu, caisse, tickets, comptoir, cc, liv, ccN, livN } × 14 ],
      quatorze: { webshop, total, cc: {n, ca}, liv: {n, ca} },
      liste:    [ { heure, canal: compt|cc|liv, articles, montant, statut } ],   toutes les commandes du jour, précommandes au comptoir comprises
      nWebshop, aPreparer, demain: { n, ca }, indispo, source }

Une période (`du`, `au`, 62 jours au plus) rend `periode` à la place de
`jour` (le même bloc, plus `du`, `au`, `joursEcoules`), `serie` jour par jour
sur toute la période (les jours à venir sans caisse, mais avec les commandes
déjà prises), `parJour` (commandes, comptoir, click & collect, livraison, à
préparer), `parSemaine` au-delà de quinze jours (bornée à la période),
`liste` jusqu'à sept jours, `nCommandes`, `aPreparer` (dues et ouvertes) et
`aVenir` (prises pour plus tard).

`indispo` est vrai quand le panel n'a pas répondu : le split retombe sur la
caisse seule, le dashboard garde alors la carte « Comptoir et clients pro ».
Le réseau rend `magasins[]` (le même bloc sans `serie`, plus `shop`, `nom`)
et `reseau` (comptoir, cc, liv, webshop, total, part, `livrent` et `vendent`
= combien de magasins livrent, vendent en ligne).

### `GET /exploitation/canaux-mois` — chaque canal mois par mois, et le cumul du mois face au mois d'avant (17/10/2026)

`?shop=4&mois=2026-10&n=6` (`n` de 2 à 12, 6 par défaut ; un mois futur est ramené au mois en cours). Pour la carte
« Commandes et canaux » du dashboard magasin en vue Mois : quatre canaux (le comptoir hors clients pro, les clients
pro — B2B, le click & collect, la livraison), la courbe des six derniers mois de chacun, l'écart au mois d'avant aux
mêmes dates et, au clic sur un canal, son cumul du mois face au mois d'avant.

    { shop, mois, precedent: "2026-09", proDepuis: "2026-08-01", indispo, source,
      historique: [ { m: "2026-05", jours, enCours, joursLus, joursEcoules, comptoir, tickets, pro, proJours, ticketsPro,
                      cc, ccN, liv, livN, total } × n ],          // du plus ancien au mois choisi
      jours: { courant:   [ { j, lu, comptoir, tickets, pro, ticketsPro, cc, ccN, liv, livN } ],   // jusqu'à aujourd'hui
               precedent: [ … le mois d'avant entier … ] } }

- `comptoir` = la caisse moins les commandes webshop encaissées en caisse, **clients pro compris** (comme
  `/exploitation/canaux`) : le comptoir hors pro, c'est `comptoir − pro`.
- `pro` = les tickets des sociétés à TVA valide, gravés depuis `SV_DEBUT` (01/08/2026) ; `null` quand aucun jour du
  mois n'est gravé (avant août, notamment) — inconnu, pas zéro. `proJours` dit combien de jours sont gravés.
- Le mois choisi et celui d'avant se lisent jour par jour (la caisse heure par heure, gravée ; les tickets pro gravés,
  10 jours au plus relus au panel) ; les mois plus anciens prennent la caisse du mois (`statistics/sales/kpis`, gardée
  une fois le mois clos) et le pro des seuls jours déjà gravés. Une seule lecture des commandes du panel
  (`client-orders` depuis le premier mois, gardée dix minutes) ; une commande à venir ne compte pas.
- Au dashboard, le mois en cours se projette au rythme des jours lus (`valeur ÷ joursLus × jours` ; le webshop sur
  `joursEcoules`).

### `/exploitation/promos-magasin` — les promotions « X achetés + Y » du magasin, au panel (17/10/2026 ; panel seul depuis le 18/10/2026)

Pour l'onglet **Promotions** du dashboard magasin (sur ordinateur) : la liste des promotions qui touchent le magasin et
l'assistant en cinq étapes (Nom → Ce qu'on achète (X) → Ce qu'on reçoit (Y) → Période → Vérifier et
enregistrer), calqué sur le formulaire « Buy X Get Y » du panel. Les promotions du magasin **vivent au panel, et
seulement là** (18/10/2026) : une « Buy X Get Y » réservée à ce seul magasin (`SELECTED_SHOPS`, `shop_ids: [magasin]`),
active = en caisse, inactive = brouillon. Le cockpit n'en garde rien (ci-dessous, « L'écriture au panel »).

`GET /exploitation/promos-magasin?shop=4`

    { shop, erreurPanel, source: "panel", regles: { coefMin: 2, joursMax: 366, enMemeTemps: 2 },
      panel: [ { id, nom, type: "buyxgety"|"bundle", texte, statut, etat: "active"|"a_venir"|"terminee"|"inactive",
                 du, au, reseau, magasins, x, y, recompense } ],     // celles de la marque qui touchent le magasin
      demandes: [ { id: "p21", panelId: 21, shop, shops: [4], statut: "brouillon"|"en_caisse",   // celles du magasin, relues au panel
                    nom, description, x: { q, cats: [{id, nom}], prods: [{id, nom}], exclus: [{id, nom}] },
                    y: { q, type: "FREE"|"FIXED_PRICE"|"FIXED_AMOUNT_DISCOUNT"|"PERCENTAGE_DISCOUNT", valeur, cats, prods, exclus },
                    du: "2026-10-20 07:00", au, creneaux: [ { jours: [6, 7], de: "15:00", a: "18:00" } ],
                    repetable, maxParTicket } ] }

- `panel` vient de `pvRegles()` (la liste buy-x-get-y du panel et ses fiches, gardée 10 min) ; `x`, `y` et
  `recompense` sont ceux de `pvNormaliser()` (`{q, p, c, x, portion}` — `portion` : ONE_HALF | ONE_QUARTER quand toutes
  les cibles du côté portent cette `portion_scope`, sinon null) et servent au bouton « Dupliquer » et au coefficient.
- Le coefficient de marge (18/10/2026) : la colonne « Coef. du lot » et sa modale se recalculent dans le navigateur
  avec `/exploitation/promos-magasin/produits` (prix HT et coûts de recette d'aujourd'hui), pour les promotions du
  magasin comme pour celles de la marque (X achetés + Y ; pas les bundles). La modale montre le cas le plus serré, puis
  « produit par produit » le pire lot de chaque produit visé. Rien n'est gardé de l'enregistrement : le coefficient est
  toujours celui d'aujourd'hui.
- `demandes` vient des mêmes règles (`pvNormaliser()` porte aussi `description`, `duH`/`auH` à la minute, `creneaux`
  regroupés depuis `schedule`, `repetable`, `maxParTicket`) ; les noms des produits et catégories manquent quand le panel
  ne les rend pas — le dashboard les complète avec la liste du magasin.

`GET /exploitation/promos-magasin/canaux?shop=4&jours=30` (7 | 30) — où les promotions s'appliquent (18/10/2026) :

    { shop, jours, du, au, erreur,
      promos: [ { id (n° au panel | "x" = remise non reconnue), nom, type, texte,
                  compt: { n, r, t }, cc: { n, r, t }, liv: { n, r, t } } ],      // n = fois, r = remise, t = tickets
      parJour: [ { j, lu, compt: { n, r }, web: { n, r } } ], total: { compt, cc, liv },
      joursSans: [jours pas encore lus], commandes: bool, enLigne: { cc, liv } }

- Les tickets à remise gravés (`svP<shop>:<jour>`, `r`) passent par la reconnaissance des promotions (`pvTicket`). Un
  ticket est du webshop quand une commande du panel (`coCommandes`, ligne `tx` = `id_transaction`) porte sa transaction :
  click & collect ou livraison selon la commande ; sinon comptoir. Une commande payée en ligne n'a pas de ticket :
  comptée dans `enLigne`, ses promotions ne se lisent pas encore. `commandes: false` = commandes illisibles, tout au comptoir.
- La sonde des commandes payées en ligne (18/10/2026, lecture seule) : `GET /ventes/promos/sonde-commandes?shop=4[&jours=14]`
  rend les comptes (commandes, webshop, payées en ligne, encaissées au magasin), les clés d'une commande et de ses lignes,
  les chemins des remises et du paiement, deux commandes payées en ligne en exemple et ce que rendent les routes de détail
  d'une commande (consultant, admin) — rien de nominatif (noms, e-mails, téléphones, adresses, notes retirés, `pmSondePropre`).
- Le dashboard (onglet Promotions, « Où les promotions sont utilisées ») : une barre par promotion, en nombre ou en
  remise €, son coefficient de marge (le bouton de la liste), et le jour par jour, comptoir en bas, webshop au-dessus.
- Une promotion vaut pour le magasin qui l'écrit, lui seul (17/10/2026) : `shops` vaut `[shop]`, un `shops` envoyé est ignoré,
  et une promotion ne se modifie que depuis son magasin (404 sinon).

`POST /exploitation/promos-magasin` `{ shop, id?, action: "brouillon"|"envoyer", nom, description, shops, x, y, du, au,
creneaux, repetable, maxParTicket }` — `id` : « p21 », la promotion n° 21 du panel (une autre que celle du magasin :
404). Rien ne s'écrit sans le compte admin du panel (503). `envoyer` vérifie les règles (sinon **422** `{ error: "à
compléter", erreurs: [phrases], coef }`), sort ce qui ne se chiffre pas, puis écrit au panel : créée (POST) ou modifiée
(PUT …/{panelId}) INACTIVE, relue, activée → `{ ok, demande: {…, statut: "en_caisse"}, coef, exclusAuto, panelErreur:
null }`. **Pas de validation** de la marque. `brouillon` : la même écriture laissée INACTIVE (ce que le panel exige —
nom, X, Y, dates — est demandé ; un brouillon ne compte pas dans les deux à la fois) ; refusé à une promotion en caisse
(409). Le panel refuse : **502** `{ error }`, rien n'est créé ; créée mais pas activée (un écart à la relecture, une
activation refusée) : 200 avec `panelErreur`, `statut: "brouillon"` (inactive au panel). Un envoi à la fois par magasin
(verrou : un double clic ne crée pas deux promotions, 409 sinon). Le dashboard propose Reprendre, Dupliquer et Supprimer
sur un brouillon, Modifier et Dupliquer en caisse. Les valeurs de l'avantage se saisissent TTC, comme en caisse.

`DELETE /exploitation/promos-magasin?shop=4&id=p21` — un brouillon du magasin se supprime au panel (`DELETE
/admin/promotions/buy-x-get-y/21`) ; en caisse, 409 ; un panel qui ne supprime pas (405) : 409, elle y reste inactive.

`GET /exploitation/promos-magasin/produits?shop=4` — ce que l'assistant chiffre, la liste même que le serveur relit à
l'envoi : `{ shop, produits: [ { id, nom, catId, cat, prixTtc, prixHt, tva, cout, source: "magasin"|"reseau"|null } ],
nMagasin, nReseau, nSansCout }`. Le coût est celui de la recette **pour ce magasin** (`/analyse/produits/coefficients`) ;
le panel rend 0 tant qu'il ne l'a pas calculé pour lui (17/10/2026 : un magasin entier sans coût) — le coût du réseau
prend alors la place (`catalogueCouts()` : la moyenne des magasins du compte, sinon la recette de la copie locale),
`source: "reseau"`, et l'assistant le dit. Un produit sans coût nulle part, ou sans prix, n'est pas chiffré et se nomme
(`sansCout`, `sansPrix`) ; à l'enregistrement, il sort de la promotion (`exclusAuto`).

Côté marque — la console, **Magasins › Analyse magasin › Promotions** (`#/promotions`) : `GET /promos-magasin[?statut=en_caisse|brouillon]`
— lecture seule, au panel : les promotions réservées à un seul magasin (même forme que `demandes`, la plus récente
d'abord, avec `coef` : le coefficient du lot aux prix et coûts d'aujourd'hui), `noms` (les noms courts des magasins),
`erreurPanel` et `regles`. L'écran trie par statut (En caisse, Brouillons, Toutes), montre la règle, le magasin, la
période, le coefficient et son cas le plus serré, et ouvre le dashboard de chaque magasin sur son onglet Promotions.

Les règles du magasin, imposées au serveur :

- une date de fin, **un an au plus** (366 jours ; 14 jours jusqu'au 18/10/2026) ; la fin après le début, pas déjà passée.
  L'étape Période de l'assistant a des durées rapides — 1 semaine, 1 mois, 1 an — qui remplissent les deux dates ;
- **deux promotions à la fois au plus**, magasin par magasin (les promotions en caisse — actives au panel) ;
- le **coefficient du lot**, affiché avec ×2 en repère — il **ne bloque plus** (18/10/2026) : sous ×2, la promotion
  s'enregistre et part au panel, en rouge dans la liste et la modale. Prix payé HT ÷ coût matière pour x pièces d'un produit de X et y pièces
  d'un produit de Y, la récompense sur la pièce la moins chère, le cas le plus serré de toutes les paires (une paire où
  le produit de X est aussi dans Y et moins cher ne se présente pas en caisse), dans chaque magasin couvert, avec ses
  prix et coûts de recette (`/analyse/produits/coefficients`). Le navigateur fait le même calcul à chaque frappe. Un
  produit sans coût de recette n'est pas chiffré (`sansCout`) : il sort de la promotion à l'enregistrement.

**L'écriture au panel (17/10/2026).** Le format est celui de la fiche relue sur la promotion test « 10 + 2 »
(`GET /admin/promotions/buy-x-get-y/19`) : `{ name, description, promotion_type: "BUY_X_GET_Y", status, priority: 100,
is_exclusive: false, valid_from, valid_to ("AAAA-MM-JJ HH:MM:SS"), is_repeatable, max_applications_per_transaction,
shop_scope_type: "SELECTED_SHOPS", activation_mode: "AUTOMATIC", shop_ids: [shop], schedule: [{day_of_week, start_time,
end_time}], trigger: {quantity, product_ids, category_ids, excluded_product_ids}, reward: {…, type, value,
selection_strategy: "CHEAPEST"} }` (`ErpApi::ecrire`, compte admin du panel). Les créneaux : `schedule: [{day_of_week,
time_from, time_to}]` (18/10/2026 — le panel refusait `start_time`/`end_time` : « schedule.0.time_from is required ») ;
l'heure avec secondes, sans si le panel refuse le format. Rien ne part en caisse sans avoir été relu :
1. `POST /admin/promotions/buy-x-get-y` en **INACTIVE** (ou `PUT …/{id}` quand un premier envoi l'a déjà créée,
   `panelId`) ;
2. `GET …/{id}` : la fiche relue est comparée à l'envoi (nom, magasin, dates, répétable, X et Y — quantités, produits,
   catégories, exclusions —, type et valeur, nombre de créneaux) ;
3. `PUT …/{id}` en **ACTIVE** (sinon `PATCH {status}`), relue ACTIVE → `statut: "en_caisse"`, `panelId`. Un brouillon
   s'arrête à l'étape 2. La fiche relue remplace la promotion dans les règles gardées (`pvRetenir()`).
**Les produits qui ne se chiffrent pas sortent d'office** (17/10/2026, `pmExclureNonChiffres`) : à l'enregistrement, un
produit visé sans coût de recette (ni du magasin ni du réseau) ou sans prix au panel quitte la promotion — choisi par sa
catégorie, il passe dans les exclusions (`excluded_product_ids` au panel) ; choisi un à un, il quitte la liste —, et
`exclusAuto` les nomme. Le coefficient se calcule sur le reste ; si plus rien ne se chiffre (une liste vidée),
l'enregistrement est refusé (422, les noms sortis dits). Depuis le 18/10/2026, le coefficient ne retient plus l'envoi au panel. Un refus : rien n'est
créé (502). Un écart, une activation refusée : la promotion reste inactive au panel (un brouillon) et `panelErreur` dit
l'erreur du panel et ses champs refusés, ou l'écart. Le format des créneaux (`schedule`) est vérifié à la relecture.

**Les portions (17/10/2026).** Chaque côté (X, Y) peut viser une variante : `portion` = `ONE_HALF` (½) ou `ONE_QUARTER`
(¼), sinon toutes les variantes. Au panel, chaque cible la porte (`category_targets: [{id_category, portion_scope}]`,
`product_targets: [{id_product, portion_scope}]`), au format relu sur « 4 Quarts + 1 Gratuit » (n° 2) ; la relecture
vérifie que la portion est reprise, sinon la promotion reste inactive. Le coefficient compte l'article à sa fraction de la
pièce (prix et coût proportionnels) : « 4 quarts achetés, le 5e offert » vaut le coefficient de la pièce ÷ 1,25. Une
récompense à prix fixe ou en euros s'applique à la part. La caisse compte les articles de la portion visée : une ½ n'y
est pas deux quarts (le panel ne pondère pas les portions entre elles).

Un **verrou par promotion** (fichier, `flock`) empêche deux envois simultanés de la même promotion (double clic, console et
dashboard en même temps) de créer deux promotions au panel ; le second relit le numéro gardé par le premier.

Tests : `php bin/tests_promos.php` (un panel en doublure : création, relecture, activation, refus, créneaux non repris,
reprise par PUT, activation par PATCH, portions).

### `GET /exploitation/offres` — ce que les promotions et les bundles rapportent

    ?shop=4&date=2026-10-02&periode=7      un magasin, les 7 derniers jours
    ?shop=4&du=2026-09-28&au=2026-10-04    un magasin, la semaine ou le mois du dashboard
    ?periode=jour|7|30&date=…              le réseau : les offres en lignes, les magasins en colonnes

Deux familles d'offres, une ligne chacune :

- **bundle** : un produit dont la catégorie du panel s'appelle « Bundle &
  Promotion » (`svCategories`, nom contenant `bundle` ou `promotion`), lu dans
  les tickets jour par jour (`svProduitsJour`, le cache `svP{shop}:{j}`) sur
  les 35 derniers jours : les 7 derniers, et les 4 semaines d'avant comme
  référence. `delta` = pièces par jour lu sur 7 jours face à la référence ;
  `marge` et `coef` (CA ÷ coût matière) sur 7 jours. Le temps de lecture est
  borné (35 s pour un magasin, partagé entre les magasins en vue réseau) : un
  jour non lu l'est à la lecture suivante, `kpi.joursLus` le dit.
- **promo** : une promotion des jours creux (`ceo_promo`, en cours ou finie
  depuis moins de 30 jours), avec l'effet de `jcEffet` : `delta` = CA/h du
  créneau face à la référence gelée au lancement, `deltaTk` pour les clients.
  Le CA de la ligne est celui du créneau sur la période.

    { shop, date, periode,
      offres: [ { type: bundle|promo, id, nom, regle, canaux, depuis, au?, statut?, levier?,
                  periode: {pieces, ca}, auj: {pieces, ca}, sept: {pieces, ca},
                  marge, coef, spark[7], delta, deltaTk?, verdict: tot|garder|ajuster|arreter, verdictLib, mot } ],
      kpi: { ca, caJour, pieces, caPeriode, part, marge, bundles, promos, aAjuster, joursLus, ticketsLus } }

La **fenêtre** (`fen` : du, au, jours, lus) porte la marge, la tendance
(`spark`, une barre par jour) et le delta : la période dès qu'elle fait sept
jours, sinon les sept derniers jours ; la référence est toujours les 28 jours
qui la précèdent. `periodeJours` dit combien de jours de la période sont lus.

Le verdict d'un bundle : **trop tôt** sous 5 jours vendus sur la fenêtre ;
**garder** à partir de +8 % face à la référence ; **ajuster** entre −3 % et
+8 % ; **arrêter** sous −3 %. Sans référence (bundle nouveau), c'est la marge
qui tranche : garder à 50 % et plus, ajuster dessous. Le réseau rend
`offres[]` fusionnées par type et nom (`magasins: {shop: {pieces, ca, verdict,
verdictLib, mot, delta}}`, `pieces`, `ca` totaux), `magasins[]` avec leur
`kpi`, et un `kpi` réseau.

Qui les lit : le dashboard magasin en vues Jour, Semaine et Mois (les cartes
« Commandes et canaux », qui remplace « Comptoir et clients pro » dès que les
commandes sont lues, et « Promotions et bundles — ce qu'elles rapportent » ;
au téléphone, les tuiles Webshop et Offres du mur) et le cockpit, Marque &
marketing › **Offres et canaux** (`#/offres-canaux`), sur la journée, 7 ou
30 jours.

### `GET /pwa/tasks/photos` — les photos d'une journée, en une lecture

    ?shop=3&date=2026-09-24

Ce que lit le carrousel **« Les contrôles en photo »** du dashboard magasin (vue Jour, bureau et
téléphone) : le franchisé qui n'est pas au magasin voit ce que l'équipe a montré et ce que le
consultant en a dit. `/pwa/tasks/detail` rend UNE tâche ; lu tâche par tâche, un comptoir de
seize contrôles coûtait seize fois la même chaîne d'appels. Ici chaque niveau part une fois, de
front : checklists et tâches du jour, avancement de chaque checklist (il porte `attachment_id`),
puis les URL signées.

```json
{ "shopId": "3", "date": "2026-09-24", "validite": 1200,
  "photos": [{ "taskId": "1210", "photo": "https://…r2.cloudflarestorage.com/…?X-Amz-Expires=1200…",
               "checklist": "CQ-02 — Contrôle qualité d'ouverture.",
               "reperes": [{ "n": 1, "x": 0.3274, "y": 0.2511, "l": 0.4067, "h": 0.5433, "niveau": 3, "txt": "Assortiment" }],
               "avis": { "note": 3, "accepte": false, "comment": "1. [mineur] Assortiment",
                         "consultant": "Sam Verheyden", "le": "2026-09-24 13:55" } }],
  "checklists": { "1212": "CQ-02 — Contrôle qualité d'ouverture." },
  "api": { "configure": true, "erreur": null } }
```

- **`photos`** : seulement les tâches qui ont une photo. Le reste de la journée (non rendues,
  clôturées sans photo, heures, personnes) vient de `/pwa/tasks?date=`, que la vue Jour
  lit déjà — la page croise les deux par `taskId`.
- **`validite`** : les URL signées expirent (secondes). La page relit la route au-delà de
  quinze minutes, et dès qu'une image refuse de se charger.
- **`avis`** et **`reperes`** sont locaux (`mac_task_review`, `ceo_task_annotation`) : ils ne
  dépendent pas du panel.
- **`checklists`** : le nom de la checklist de chaque tâche du jour, photo ou non — `/pwa/tasks`
  ne le porte pas pour une tâche déjà notée.
- Lecture seule. Noter, poser un repère ou contresigner restent dans **Contrôle des tâches**.

**Le cockpit la lit aussi** : la page Tâches › Contrôle (`#/controle-taches`)
montre, par défaut, chaque boutique en photos — la même bande que le dashboard
(`assets/css/controles-photo.css`, partagé) : la photo, l'heure, la pastille
d'état, qui l'a rendue, les écarts d'abord, les non rendues en fin de piste ;
filtres par état dans l'en-tête de la boutique. Sous chaque photo à contrôler,
la **note rapide** : les niveaux au-dessus du seuil (4 Conforme, 5 Exemplaire),
en un clic, sans commentaire ni repère — même `POST /pwa/tasks/review` que le
volet, avec les identifiants lus dans `/pwa/tasks/detail` ; « Noter… » ouvre le
volet pour le reste (photo en grand, repères, commentaire, niveaux sous le
seuil). Une lecture par boutique et par journée,
relue passé quinze minutes (les URL expirent à vingt) ; la bascule
« Liste » redonne le tableau et ses colonnes.

**L'application visites aussi** : dans le contrôle guidé du consultant, l'étape
« Les contrôles en photo » (après la photo du jour) montre la même bande pour
la boutique visitée — `GET /pwa/tasks?date=&shop=` (le paramètre `shop`
limite les appels au panel et les lignes à cette boutique) et
`GET /pwa/tasks/photos?shop=&date=`, dont chaque photo porte désormais
`checklistId` et `completionId`. La note rapide 4 / 5 y part dans la file
hors ligne comme toute écriture, avec `role: consultant` et `auteur` : le
serveur consigne alors le consultant dans `consultant_name` (avis terrain) et
laisse les colonnes `owner_*` à la direction. La photo en grand (loupe) porte
la fiche, la note rapide et ‹ ›. L'étape est faite quand plus rien n'attend
une note.

### Qui fait autorité sur quoi

Le cockpit vit dans la base du panel. Certaines données lui appartiennent, la
plupart non — et pour celles-là, la table `ceo_*` n'est qu'un **repli
d'installation autonome**, vide sur une base réelle.

| Donnée | Source d'autorité | Repli local |
|---|---|---|
| Magasins | `shops` | `ceo_shop` (miroir, rempli à la volée pour les clés étrangères) |
| Consultants | `user_membership` ⨝ `user_profile` (`app = 'CONSULTANT'`, actifs) | `ceo_consultant` |
| Destinataires des rapports | `user_membership` ⨝ `user_profile` (actifs, avec e-mail) | `ceo_person` |
| CA / marge mensuels | `mac_shop_monthly_pnl` | `ceo_shop_month_perf` |
| Tickets, panier moyen | `transaction` | — |
| Catalogue produits | `sig_products`, `transaction_product` | `ceo_product` |
| Leviers, KPI, positions | `of_tag`, `kpi`, `position` | créées si absentes |
| **Budget encodé** | **`ceo_shop_month_perf`** | — (donnée propre au cockpit) |
| **Validation des tâches** | **`ceo_project_task`, `ceo_task_issue`** | — (donnée propre au cockpit) |
| Projets, jalons, coûts | `ceo_project*` | — (données propres au cockpit) |
| Fournisseurs | `ceo_supplier` | — (aucune table partagée équivalente) |

**Le jeu de démonstration ne se charge jamais tout seul.** `sql/seed.sql` est
gardé derrière `seed: true` (config) ou `COCKPIT_SEED=1` ; par défaut une base
neuve reste vide, prête pour les vraies données.

**Rien n'est écrit en dur côté écran.** Les échéances proposées par les
assistants sont relatives à la date du jour, l'intervenant proposé est le
premier intervenant réel, et les liens de rapport partent de l'adresse d'où
l'application est servie.

### `GET /stores/perf` — d'où vient chaque colonne

Trois sources, fusionnées par (magasin, année, mois) :

| Champ | Source | Remarque |
|---|---|---|
| `ca`, `margeNette`, `margePct`, `labourCostPct`, `overheadPct` | `mac_shop_monthly_pnl` | table partagée avec le panel |
| `tickets`, `panierMoyen`, et `ca` de repli | `transaction` | ventes caisse de l'exercice courant |
| **`caBudget`, `caTheorique`** | **`ceo_shop_month_perf`** | **le budget encodé — aucune autre table ne le porte** |

La troisième passe n'est pas facultative : ni `mac_shop_monthly_pnl` ni
`transaction` ne connaissent le budget. Sans elle, l'encodage était écrit en
base et jamais relu, et tous les écrans qui comparent au budget — suivi budget,
heatmap, objectifs de CA — affichaient un objectif vide **sans lever la moindre
erreur**. Le défaut ne se voyait qu'en présence des tables partagées : sans
elles, l'endpoint tombait dans son repli, qui lisait le budget correctement.
C'est-à-dire qu'il marchait partout sauf en production.

Un mois **budgété sans réel** est rendu, avec `ca: null` : « budget 80 k, rien
encaissé » est une information, pas une ligne à masquer.

### Suivi des tâches — traitement et reporting semaine / mois

`GET /taches/suivi?periode=semaine|mois` (défaut `mois` ; 7 ou 30 jours glissants) :

```json
{ "periode": "semaine", "depuis": "2026-08-08",
  "validees": 12, "moyenne": 4.08,
  "repartition": { "5": 3, "4": 6, "3": 2, "2": 1, "1": 0 },
  "ouverts": 2, "traites": 4,
  "signalements": [ { "id": 7, "tacheId": "t12", "tache": "…", "projet": "…",
                      "owner": {"t":"c","id":"c1"}, "note": 2,
                      "famille": "Délai", "type": "Rendu hors délai",
                      "statut": "vu", "ouvert": true,
                      "creeLe": "…", "vuLe": "…", "closLe": null } ],
  "parIntervenant": [ { "owner": {"t":"c","id":"c1"}, "validees": 5, "moyenne": 4.2, "ouverts": 1 } ] }
```

Deux règles de lecture qui ne se devinent pas :

1. **La période porte sur la date de VALIDATION** (`ceo_project_task.validated_at`),
   pas sur `done_on` qui est la livraison. Une tâche rendue en mars et jugée en
   août appartient au suivi d'août — la borner sur la livraison la ferait
   disparaître de tout suivi utile.
2. **Les signalements ouverts remontent tous**, quelle que soit leur date. Un
   signalement de trois semaines qui traîne doit apparaître dans le suivi de la
   semaine : c'est même le premier à devoir sauter aux yeux, et une borne de
   date l'aurait masqué.

`PATCH /task-issues/{id}` avec `{ statut, commentaire, par }` — cycle
`nouveau` → `vu` → `traite`, et retour possible à `nouveau`.

| Statut | Effet |
|---|---|
| `vu` | pose `seen_at` — **ne clôt pas**. Voir n'est pas régler. |
| `traite` | pose `closed_at` et `closed_by`, et **exige un commentaire** (`422` sinon) : clore sans dire ce qui a été fait, c'est perdre l'information que le suivi cherchait à produire. |
| `nouveau` | rouvre, et laisse une trace au journal. |

Un statut hors de ces trois valeurs est refusé en `422`. Chaque transition
écrit une ligne dans `ceo_journal_entry`.

```sql
ALTER TABLE ceo_project_task ADD COLUMN validated_at DATETIME NULL;
```

**Aucune migration à lancer à la main.** `ensureValidation()` (`src/installer.php`) pose les deux
colonnes, la table `ceo_task_issue` et le réglage `signalement` au démarrage, sur une base neuve
comme sur une base déjà en service — `schema.sql` et `seed.sql`, eux, ne repassent jamais sur une
installation existante. Pour information, l'équivalent manuel :

```sql
ALTER TABLE ceo_project_task ADD COLUMN note TINYINT NULL;
ALTER TABLE ceo_project_task ADD COLUMN validated_by VARCHAR(80) NULL;
-- + la table ceo_task_issue (voir sql/schema.sql)
```

```sql
ALTER TABLE ceo_shop_budget ADD COLUMN ca_theorique_an DECIMAL(12,2) NULL;
ALTER TABLE ceo_shop_month_perf ADD COLUMN ca_theorique DECIMAL(12,2) NULL;
ALTER TABLE ceo_shop_budget_charge ADD COLUMN pct_theorique DECIMAL(5,2) NULL;
ALTER TABLE ceo_shop_budget ADD COLUMN etude_date DATE NULL;
ALTER TABLE ceo_shop_budget ADD COLUMN etude_source VARCHAR(160) NULL;
ALTER TABLE ceo_shop_budget ADD COLUMN etude_potentiel_menages INT NULL;
ALTER TABLE ceo_shop_budget ADD COLUMN etude_potentiel_maturite DECIMAL(12,2) NULL;
ALTER TABLE ceo_shop_budget ADD COLUMN annee_exploitation TINYINT NULL;
ALTER TABLE ceo_shop_budget ADD COLUMN montee_regime JSON NULL;   -- {"a1":70,"a2":80,"a3":90}
ALTER TABLE ceo_shop_budget ADD COLUMN saisonnalite JSON NULL;     -- 12 pourcentages
ALTER TABLE ceo_shop_budget ADD COLUMN etude_annexe JSON NULL;     -- {"nom","url","taille","date"}

-- Annotations mensuelles du suivi budget (créée aussi au vol par ensureBudgetNotes()) :
CREATE TABLE IF NOT EXISTS ceo_shop_month_note (
  shop_id VARCHAR(8) NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL,
  auteur VARCHAR(12) NOT NULL,          -- franchise | consultant | marque
  texte TEXT NULL, maj_par VARCHAR(120) NULL, maj_le DATETIME NULL,
  PRIMARY KEY (shop_id, year, month, auteur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE ceo_project ADD COLUMN famille VARCHAR(40) NOT NULL;
ALTER TABLE ceo_project_task ADD COLUMN description TEXT NULL;
ALTER TABLE ceo_project_task ADD COLUMN budget DECIMAL(10,2) NULL;
ALTER TABLE ceo_project_task ADD COLUMN shop_id VARCHAR(8) NULL;   -- magasin de rattachement (NULL = réseau)
ALTER TABLE ceo_project_task ADD CONSTRAINT fk_task_shop FOREIGN KEY (shop_id) REFERENCES ceo_shop(id);
```

### `/targets`

```json
{ "ca": { "h1": { "an": 2026, "cible": 7400000 },
          "h3": { "an": 2028, "cible": 11500000, "note": "Hypothèse : {ouvertures} ouvertures d'ici 2028…" },
          "h5": { "an": 2030, "cible": 18000000, "note": "…" } },
  "expansion": { "h1": { "an": 2026, "cible": 2, "reel": 1 } },
  "caMoyenOuverture": 820000 }
```

`note` accepte les jetons `{ouvertures}` et `{caMoyen}`, substitués côté client.

### `/projects`

```json
[{ "id": "p1", "nom": "Gamme snacking été", "statut": "En cours", "prio": "Haute",
   "debut": "2026-03-02", "fin": "2026-09-15", "axes": ["Ventes"], "leviers": ["trafic","food-cost"],
   "budget": 24000, "valeurEst": 62000, "valeurReal": null, "kpis": ["CA réseau"],
   "jalons": [{ "nom": "Étude gamme & pricing", "cible": "2026-04-10", "reel": "2026-04-08" }],
   "couts":  [{ "poste": "Agence", "prevu": 9000, "reel": 9400 }],
   "taches": [{ "id": "t1", "nom": "Fiches techniques", "owner": { "t": "c", "id": "c1" },
                "due": "2026-08-14", "done": null }] }]
```

`owner.t` : `c` = consultant, `s` = fournisseur ; `owner.id` référence `/consultants` ou `/fournisseurs`.

### `/referentiels/facebook-regles` — le pack de règles de l'agent

```json
{
  "seuil": 4,
  "familles": [
    { "nom": "Charte de marque", "types": ["Marque absente", "Casse de marque", "Hashtag de marque absent", "Magasin non identifié", "Ton non conforme"] },
    { "nom": "Mentions légales", "types": ["Conditions de promotion absentes", "Règlement de concours absent", "Allégation santé", "Superlatif non justifié"] }
  ],
  "regles": [
    { "code": "marque-absente", "nom": "Marque citée", "famille": "Charte de marque",
      "type": "Marque absente", "gravite": 3, "actif": true, "aide": "Le post doit nommer la marque du réseau." }
  ]
}
```

`gravite` ∈ 1 (mineur) | 2 (majeur) | 3 (critique). Les `params` de chaque règle
(mots déclencheurs, seuils de longueur, domaines autorisés, lexique…) restent
côté serveur : le client n'a pas à connaître le détail du moteur, seulement ce
qu'il affiche. Les **cinq niveaux de notes ne sont pas ici** — ce sont ceux de
`meta.signalement`, une seule échelle de conformité pour les tâches consultants
et pour les posts.

Le client renvoie le pack **entier** par `PUT /parametres/fbControle`
(`{ "valeur": { … } }`) quand le CEO active ou désactive une règle : le serveur
stocke un réglage, pas un delta.

### `/facebook/posts` — les posts soumis, leur contrôle et leur décision

```json
[{
  "id": "fb03", "magasinId": "gnd", "magasin": "Gand — Korenmarkt",
  "auteur": "J. De Smet", "format": "Carrousel",
  "message": "Grand concours de rentrée à L'Atelier by Korenmarkt !…",
  "lien": null,
  "medias": [{ "nom": "panier-1.jpg", "type": "image", "alt": "Panier gourmand", "largeur": 1440, "hauteur": 1440 }],
  "publierLe": "2026-08-03 11:30", "soumisLe": "2026-07-30 16:05",
  "statut": "a_valider",
  "agent": { "note": 1, "resume": "1 écart — plus grave : critique · Mentions légales",
             "le": "2026-07-30 16:05", "passages": 1 },
  "ecarts": [{ "id": 7, "code": "concours-reglement", "regle": "Règlement de concours",
               "famille": "Mentions légales", "type": "Règlement de concours absent",
               "gravite": 3, "message": "Jeu-concours sans règlement cité ni lien vers celui-ci.",
               "extrait": "concours", "statut": "ouvert" }],
  "decision": { "note": null, "famille": null, "type": null, "commentaire": null, "le": null, "par": null },
  "publie": { "le": null, "fbId": null }
}]
```

`statut` ∈ `brouillon` | `a_controler` | `a_valider` | `valide` | `refuse` | `publie`.
`magasinId` à `null` = post réseau (l'ancrage local n'est alors pas exigé).

`agent.note` est la **proposition** du moteur, `decision.note` la décision
**signée** par un humain ; les deux sont distinctes et affichées comme telles —
l'écart entre elles mesure le calibrage du pack de règles. `statut` d'un écart
∈ `ouvert` | `ignore` | `corrige` ; un écart `ignore` reste renvoyé mais sort du
calcul de la note.

**Calcul de la note** (sur les écarts non ignorés) :

| Écarts retenus | Note | Niveau |
|---|---|---|
| aucun | 5 | Exemplaire |
| 1 ou 2 mineurs | 4 | Conforme — détails signalés |
| 3 mineurs et plus | 3 | Non conforme — mineur |
| au moins un majeur | 2 | Non conforme — majeur |
| au moins un critique | 1 | Non conforme — critique |

---

### `/scouting` — saisies du scouting commercial

Chargé à l'ouverture de l'écran (pas au démarrage du cockpit). Les données
OpenStreetMap elles-mêmes ne transitent pas par ce endpoint : elles vivent dans
`ceo_scouting_tile`, relues par le serveur — chaque semaine par cron
(`bin/scouting_refresh.php`), ou à la demande par `POST /scouting/refresh/{secteur}`
(« Recharger les données »). Le navigateur n'interroge Overpass lui-même qu'en
repli, sans API ou si le serveur n'atteint pas Overpass.

```json
{
  "params": { "spend": 586, "emprise": 0, "passage": 15, "surface": 250, "empriseMax": 30,
              "compK": 0.22, "hhSize": 2.31, "minScore": 55, "radius": 2, "thresh": 4.5 },
  "google": { "configure": true, "langue": "fr", "empreinte": "AIzaSy…Qx4c" },
  "competitors": [{ "id": "n123456", "name": "Boulangerie Dupont", "commune": "Halle", "arr": "Hal-Vilvorde",
                    "rating": 4.6, "reviews": 132, "source": "google", "comment": "File le samedi" }],
  "candidates": [{ "id": 1756900000000, "name": "Halle — zone 50.733/4.237", "commune": "Halle", "arr": "Hal-Vilvorde",
                   "prov": "Brabant flamand", "lat": 50.7336, "lng": 4.2372, "hh": 9800, "market": 5742800,
                   "emprise": 0.155, "ca": 1047000, "score": 72, "n": 6, "strong": 2, "m2": 4188 }],
  "populations": { "23027": 42608 },
  "tiles": [{ "sector": 0, "fetchedAt": "2026-09-03 17:12:40", "bytes": 91234 }]
}
```

- `params` : `null` tant qu'aucune hypothèse n'a été enregistrée (défauts de l'étude Halle côté client).
- `google` : l'état du connecteur Google de Paramètres (`PUT /parametres/google-cle`) — jamais la clé. Les notes des
  concurrents se demandent au serveur par `POST /scouting/notes` ; sans clé, il répond 422.
| Scouting — signe de vie Google de toute la carte (statut, date du dernier avis), par lots | `POST /scouting/concurrents/vie` (`{ n }`, 25 par défaut, 40 au plus ; rend `faits`, `fermes`, `dormants`, `reste`, `ecartes` ; colonnes `business_status`, `last_review_at`) |
- `competitors[].source` ∈ `google` | `manuel` ; une note `manuel` prime sur Google. `rating: null` avec
  `source: "google"` = commerce déjà interrogé sans note (pas réinterrogé).
- `id` d'un concurrent = type + id OSM (`n`, `w`, `r`), ou `m` + horodatage pour un commerce ajouté à la main
  (`POST /scouting/concurrents`), qui porte alors `lat` et `lng`. `id` d'une zone candidate = horodatage client (ms).
- `competitors[]` porte aussi la **qualification terrain** (retour de l'étude de potentiel du 09/10/2026) :
  `type` (boulangerie, patisserie, sandwicherie, friterie, snack, supermarche, superette, autre ; null = relevé OSM),
  `force` (0–100, vue sur place, prime sur la note ; null = calculée), `reference` (le concurrent de référence de la
  zone : fort quoi qu'en dise la note), `visiteLe`, `terrain` (≤ 500 car.). L'écran pondère la force par le type
  (sandwicherie 0,3, friterie 0,15, snack 0,3, supermarché 0,35, supérette 0,15, autre 0) ; un type pondéré sous 1
  n'est jamais « fort » sauf référence.
- `candidates[].terrain` : le relevé terrain de la zone retenue (`PUT /scouting/candidates/{id}/terrain`) :
  `{ visiteLe, visibilite, acces, facade, parking, remarques, flux: [{ axe, vehJour, sens, source, le }] }`, chaînes bornées,
  six axes au plus ; null tant que rien n'est saisi. Imprimé dans le dossier (« Le relevé terrain »).
- `params` porte aussi les hypothèses du **modèle à deux zones** : `empriseP` (emprise de la zone primaire, 5 minutes en
  voiture, 30 par défaut), `empriseS` (zone secondaire, 5 à 10 minutes, 6,5), `indirecte` (poids d'un supermarché dans
  la pression, en % d'une boulangerie, 35 ; les six commerces indirects les plus proches comptent, pas davantage), `poles` (1 : la zone secondaire s'arrête là où un concurrent fort est plus
  près que le point), `routeDist` (1 : pression sur la distance par la route, Valhalla, face à 1,3 × rayon). Le calcul
  reste à l'écran : les isochrones de 5 et 10 minutes (Valhalla) sur la grille du recensement, la zone construite
  `(ménages primaires × empriseP + secondaires × empriseS) × dépense ÷ (1 − passage)`, les deux emprises abaissées par
  la pression comme celle du rayon ; le dossier montre les deux estimations côte à côte.
- `POST /scouting/dossier.pdf` accepte en plus `estimations` (≤ 10 lignes × 4 : mesure, rayon, zone construite, lecture),
  `estimationsCols`, `estimationsNote`, `terrain` (≤ 12 × 2), `fluxMesures` (≤ 6 × 4 : axe, véhicules/jour, sens, source · date),
  `terrainNote` et `motOutil` (« l'outil repère, l'étude confirme ») ; les distances des concurrents portent la distance par
  la route quand l'écran l'a (`0,4 km · 1,3 km par la route`).
- `tiles` : inventaire seulement ; `GET /scouting/tiles/{secteur}` renvoie le JSON `{ t, c, b, p }` déposé
  par le navigateur (communes, commerces, nœuds `place`). 404 si le secteur n'est pas en cache.

### `/prospection/{shop}` — la liste de démarchage du magasin, au serveur

Ce que le franchisé fait de chaque lieu relevé par `/scouting/demarchage` : dans ma liste, visite datée,
retour, note (part au CRM marketing), et l'**action à suivre** décidée sur place avec sa date. Une seule
réserve pour le cockpit (écrans Prospection et Développement commercial) et la page mobile
`prospection/?shop={id}` (`ceo_prospect`, clé magasin + id OSM).

```json
{ "shop": "2", "n": 3, "lieux": { "n123456": { "coche": true, "visite": "2026-09-19", "retour": "rdv", "note": "Devis 40 pers.",
                                               "action": "devis", "actionLe": "2026-09-25", "le": "2026-09-19", "nom": "École de Blanmont" } } }
```

- `retour` ∈ `''` | `rappeler` | `rdv` | `interesse` | `refus` | `client` ; `action` ∈ `''` | `mail` | `test` | `devis` | `rappel` | `passer` | `commande` ;
  `offre` = le type d'offre choisi pour ce lieu (vide = celui de son genre).
- `GET /prospection/offres` → `{ offres: [{ id, nom, pitch, contenu, genres (regex sur le libellé du genre), familles[], depense, commandes, part }] }` :
  le **type d'offre par client** — un hôpital ne prend pas la même chose qu'une maison communale. Dix offres (plateau bureau, petit-déjeuner
  d'équipe, collations d'école, pause soignants, réceptions communales, pause formation, café après cérémonie, après-match, petit-déjeuner
  d'hôtel, pause du personnel) avec leur argument, leur contenu et les hypothèses du calcul de CA. `PUT /prospection/offres` `{ offres: [{ id, depense?,
  commandes?, part?, nom?, pitch?, contenu? }] }` règle le catalogue pour tout le réseau (`ceo_app_setting.prospectionOffres`).
- `PUT /prospection/{shop}` `{ lieux: { id: { coche?, visite?, retour?, note?, action?, actionLe?, nom? } } }` : fusion champ par
  champ (400 lieux par appel au plus) ; une ligne vidée disparaît. Rend les lignes relues.

### `/newsletter` — campagnes email + SMS, marque et franchisés (mode test)

`GET /newsletter?role=brand|{shopId}`. Le rôle est porté par la requête, comme le dashboard porte son magasin :
le serveur applique les droits du rôle demandé et ne les élargit jamais. **Mode test** : aucun envoi ne part ;
les bases et leurs comptes (`sources`, `segments[].count`) sont un jeu d'essai tant que les vraies bases clients
ne sont pas raccordées — la réponse le dit (`test: true`).

```json
{ "test": true, "role": "2", "canSend": true, "domaine": "latelier.by", "smtp": false,
  "moi": { "id": "2", "nom": "Corbais", "kind": "franchise", "senderName": "L'Atelier By Corbais", "email": "", "status": "unverified", "canSend": true },
  "magasins": [ … ], "sources": [{ "id": "indiv", "table": "tfb_customers", "name": { "fr": "Clients individuels", "nl": "…", "en": "…" }, "count": 4210, "optin": 3480 }],
  "segments": [{ "id": "tous-2", "src": "indiv", "shop": "2", "name": { "fr": "…" }, "rule": { "fr": "…" }, "rules": { "magasin": "2", "produit": null, "periode": "365", "panier_min": null }, "count": 1248, "split": [886, 318, 44] }],
  "campagnes": [{ "id": 1, "name": "Tarte diamant — Corbais", "segment": "tous-2", "langs": ["fr", "nl"], "templateId": "gagne", "channel": "both", "subjects": { "fr": "…" },
                  "sendMode": "manual", "trigger": null, "sendAt": "2026-09-12 08:30:00", "maxVouchers": 30, "sender": "2", "status": "sent",
                  "stats": { "sent": 1248, "open": 599, "click": 237, "vouchers": 22, "revenue": 1184 }, "exemple": true, "createdBy": "brand" }],
  "chiffres": { "optin": 6934, "envoisMois": 2, "vouchers": [58, 100] } }
```

- Vue **marque** (`brand`) : tous les magasins, segments et campagnes. Vue **magasin** : ses segments (`shop` = lui ou `""`),
  ses campagnes (créées par lui ou parties de son adresse), son magasin seul dans `magasins`.
- `magasins[].status` ∈ `verified` | `warmup` | `unverified` ; `canSend` : la marque autorise ou bloque l'envoi du franchisé.
- `POST /newsletter/campagnes` `{ role, name, segment, langs[], templateId, channel, subjects{}, bodies{}, sms{}, headlines{}, ctas{}, sendMode, trigger?, date, time, maxVouchers?, sender, testSent?, status? }`
  → `{ ok, campagne }`. Franchisé : refusé si bloqué (403), segment d'un autre magasin (403), mode `auto` (403), expéditeur autre que le sien (403).
- `DELETE /newsletter/campagnes/{id}` : brouillon ou programmée ; une campagne envoyée reste (409). Franchisé : les siennes seulement.
- `POST /newsletter/segments` (marque seule) `{ name, src, shop, product, period, minBasket, count, rule }` → `{ ok, segment }` ; la règle est gardée en `rules_json`.
- `PUT /newsletter/magasins/{id}` `{ role, senderName?, email?, status?, canSend? }` : le franchisé ne touche qu'à son nom et son adresse ;
  le statut suit l'adresse (domaine de la marque = vérifié) et la marque peut le fixer ; `canSend` marque seule.
- `POST /newsletter/test` `{ role, subject }` → `{ ok, simule: true }` : journalisé, rien ne part.
- Tables : `mar_nl_magasin`, `mar_nl_source`, `mar_nl_segment`, `mar_nl_campagne` (créées et semées par `ensureNewsletter()` ; les magasins
  suivent `ep_stores()` à chaque démarrage). Les modèles (copy FR/NL/EN, SMS) vivent dans le module front `assets/js/newsletter.js`.

**Le moteur** (`src/newsletter_envoi.php`) — contacts, envoi, suivi, horloge :

- `POST /newsletter/contacts/import` (marque) `{ source, shop?, lot?, csv }` : CSV avec en-tête (`email` requis ; `prenom`, `nom`, `langue`,
  `magasin`, `telephone`, `optin`, `optin_sms`, `dernier_achat`, `panier`, `produits`, `anniversaire`, `cree_le`) → `{ nouveaux, misAJour, ignores, lot, contacts }`.
  Une base qui a des contacts compte pour de vrai (`sources[].reel`, `segments[].reel`) ; sinon le jeu d'essai reste.
  `GET /newsletter/contacts/lots`, `DELETE /newsletter/contacts/lots/{lot}`.
- `PUT /newsletter/reglages` (marque) `{ dispatch?, testAdresses?, domaine? }`. `dispatch: true` exige un SMTP configuré ;
  tant qu'il est faux, **rien ne part** (mode test), la réponse de `/newsletter` porte `test: true`.
- `POST /newsletter/test` `{ subject, body, headline, cta, templateId, lang, sender, voucher }` : le mail rendu part aux adresses de test
  (`nlTestAdresses`, sinon l'expéditeur SMTP), même en mode test → `{ ok, simule, adresses, erreur }`.
- `POST /newsletter/campagnes/{id}/envoyer` : la date passe à maintenant et l'horloge passe ; `POST /newsletter/tick` (marque) : l'horloge à la demande.
- `GET /newsletter/cron?jeton=…` (`nlJeton`, cron toutes les 5 minutes par `bin/newsletter_cron.sh`) : campagnes dues par lots de 100 par minute
  (500 par passage), statut `sched → live → sent` ; automatisations évaluées une fois par jour (`dormant45` : dernier achat il y a 45 jours
  exactement ; `birthday` : J-3 ; `firstOffice` : compte office créé il y a 2 jours ; `third` : deux mails déjà reçus ; `season` : J-7 avant la
  date de la campagne). Jamais deux mails au même contact le même jour, jamais deux fois la même campagne.
- Suivi, routes publiques : `GET /newsletter/o/{token}` (pixel), `GET /newsletter/c/{token}` (clic → redirection vers `nlLienWebshop` ou le cockpit),
  `GET /newsletter/u/{token}` (désinscription en un clic). Les stats d'une campagne envoyée viennent de `mar_nl_envoi` (`stats.reel`).
- Vouchers : codes uniques (8 caractères, `mar_nl_voucher`) générés au départ jusqu'à `maxVouchers` ; `POST /newsletter/vouchers/{code}/utiliser` les encaisse.
- Non branchés, et dits tels quels dans Paramètres : SMS (aucun fournisseur), Stripe. La déclinaison sur les réseaux (LinkedIn, Instagram,
  Slack) du brief a été retirée de l'assistant, qui compte quatre étapes : segment, template, texte, envoi.
- Page franchisé hors cockpit : `newsletter/?shop={id}` (même module, rôle = magasin).

### `/visites` — l'application terrain (consultant, franchisé, admin)

Une PWA (`visites/`) sur le téléphone du consultant et de l'admin, et les onglets
« Campagne » et « Plan d'action » du dashboard magasin pour le franchisé ; rien
dans le rail. Le
serveur relit l'existant (CA `/exploitation/jour`, Google `/reputation`,
non-conformités `/pwa/tasks/nc`, push, SMTP) et porte ce qui n'existait pas :
la visite, ses points, ses photos, le plan d'action à trois acteurs, le
rapport mystery shopper, l'effectif. Rôle par lien : `?role=consultant&id=u8`,
`?shop=3` (franchisé), `?role=admin`.

| Route | Rôle | Ce qu'elle rend ou fait |
|---|---|---|
| `GET /visites/app?role=&id=&shop=` | tous | tout ce que l'application affiche : `boutiques[]` (feu, motifs, `ca`, `google`, `plano`, `equipe`, `msp`, `plansOuverts`, dernière et prochaine visite), `visites[]` (−90 j … +21 j), `points[]`, `photos[]`, `plans[]`, `msp{}`, `equipe{}`, `checklist[]`, `causes{}`, `seuils{}`, `frequence{}`, `reseau{}`. Le franchisé n'a que sa boutique. |
| `GET /visites/boutique/{shop}` | tous | ce qui coûte un appel au panel (`nc`) et l'historique 3 mois : `visites[]` terminées, `plansParMois`, `planoParVisite[]`, `photosJour[]`, `msp[]`, `equipe`. |
| `GET /visites/synthese` | admin | la synthèse du jour : `compteurs`, `boutiques[]`, `escalades[]`, `actions[]`, `consultants{}`. Envoyée par mail le matin si `seuils.mails` et `seuils.mailSynthese`. |
| `GET /visites/reglages` | admin | checklist, seuils, fréquences, état de l'horloge, adresse du cron. |
| `GET /visites/conformite?shop=` | tous | ce que le cockpit sait du comptoir sans rien ressaisir : `planogramme` (emplacements dessinés et tenus, %, comptoirs photographiés montés aujourd'hui, obligatoires sans place) et `assortiment` (références obligatoires vues en caisse sur la fenêtre calée sur la dernière vente, manquantes nommées). Une source absente rend son `motif`, pas un chiffre. |
| `GET /visites/campagnes?shop=` | tous | les campagnes marketing de la boutique (du J−60 au J+30, hors brouillon / annulée / archivée), chacune avec les clients face au N-1 et à l'objectif : `statut` (avenir, encours, close), `jourCourant` / `nbJours`, `pct` (le « + x % » de `mar_campaign.objective_coef_pct`), `clientsA1` (les clients de l'an dernier sur la période, source `n1` ou `budget`), `clientsPrevus` = N-1 × (1 + pct) et `objectifJour` (seulement si un `pct` existe), `reel` et `n1Ecoule` (cette année et N-1 aux mêmes jours de semaine, −364 j, jusqu'à aujourd'hui), `caReel`, `caN1`, `objectifCA` (`mar_campagne_objectif`), `budget` et `clientsBudget` (budget / panier, pour dire « objectif à fixer » avec un chiffre), `serie[]` jour par jour `{date, tickets, n1}`. Calcul `viCampagneCalcul`, gardé 20 min par boutique (`viCamp|{shop}`, `force=1` pour recalculer). |
| `GET /visites/cron?jeton=` | cron | l'horloge : escalade auto des P0 dépassés, rappels J-1 (18 h) et jour J (7 h), synthèse (7 h). Une fois par jour chacune (`visitesCron`). |
| `POST /visites` | consultant, admin | planifier : `client_id`, `shop`, `consultant`, `prevu_le`, `debut_h`, `duree_min`, `motif` (reguliere, asap, due, revisite). Rejouable : même `client_id`, même visite. |
| `PUT /visites/{id}` | consultant, admin | `statut` (planifiee, confirmee, en_cours, terminee, annulee), créneau, et la review : `sentiment` 1..5 (énergie de l'équipe), `execution` (standards, raccourcis, ecarts), `clients` (satisfaits, mitiges, insatisfaits), `causes[]` (production, equipe, decor, prix, appro, accueil, hygiene, autre), `diagnostic` (pourquoi, vu et mesuré), `reco` (lue par le franchisé, reprise dans la synthèse), `positif`, `notes`. `{id}` est l'identifiant ou le `client_id`. |
| `PUT /visites/{id}/points` | consultant | `points[]` : `ref`, `module`, `libelle`, `etat` (ok, ko, na), `note` 1..5, `valeur` (% planogramme), `commentaire`, `causes[]`. Idempotent par (visite, ref). |
| `POST /visites/photos` | tous | `client_id`, `shop` ou `visite_id`, `ref`, `plan_id`, `genre` (jour_facade, jour_interieur, jour_arriere, point, avant, apres, correction), `data` (data-URL ≤ 2 Mo, JPEG/PNG/WebP), `prise_a`, `lat`, `lng`. Fichier sous `uploads/visites/{shop}/`. |
| `POST /plans` | consultant, admin | un plan ou `plans[]` : `client_id`, `shop` ou `visite_id`, `ref`, `titre`, `detail`, `priorite` (P0, P1, P2), `assigne` (franchise, equipe, consultant, admin), `echeance`, `campagne_id` (la campagne que l'action sert, en pastille sur l'action). Push au franchisé. |
| `PUT /plans/{id}` | tous | `statut` + `role` : transitions permises par rôle (franchisé : ouvert/reprendre → attente ; admin et consultant : attente → valide ou reprendre, valide → ferme, ouvert → escalade, …), `retour` (à reprendre), `escalade_motif`, `photo_client_id`, et le contenu (`campagne_id` compris) pour consultant et admin. 409 si le passage est refusé. Push à qui de droit. |
| `POST /msp` | consultant, admin | `shop`, `mois` AAAA-MM, `rubriques{}` (/20), `total`, `commentaires`, `fichier` (data-URL PDF ≤ 8 Mo). Un rapport par boutique et par mois. |
| `PUT /equipe/{shop}` | consultant, franchisé | `effectif`, `prevu`, `departs`, `releve_le`. |
| `PUT /visites/reglages` | admin | `checklist[]` (modules produit, hygiene, visuel, planogramme, msp ; points `ref`, `libelle`, `photo`, `pct`), `seuils{}`, `frequence{shop: jours}`. |
| `POST /visites/tick` | admin | l'horloge tout de suite (`force` : sans attendre l'heure). |

Feu tricolore (`seuils`, la première règle qui s'applique) : 🔴 P0 ouvert
plus de `p0Jours`, Google sous `googleCible − googleRouge`, planogramme sous
`planoRouge`, rubrique MSP sous `mspAlerte` ; 🟡 P0 ou P1 ouvert, Google sous
la cible, deux avis ≤ 2/5 sur 30 jours, planogramme sous `planoOrange`, visite
due (fréquence par boutique), CA sous −`caOrange` % seulement si `caSeul` ;
🟢 sinon.

Tables : `ceo_visite`, `ceo_visite_point`, `ceo_visite_photo`,
`ceo_visite_action`, `ceo_visite_action_evt`, `ceo_msp`, `ceo_equipe_releve` ;
réglages `visitesChecklist`, `visitesSeuils`, `visitesFrequence`,
`visitesJeton`, `visitesCron`. Abonnements push : `shop_id` = la boutique
(franchisé), `c:{consultant}` ou `admin`.

Écrans du consultant : la fiche de visite ouvre le **contrôle guidé**
(`#controle/{id}`), un seul écran vertical, étape après étape — photo du jour,
chiffres et alertes, un module de checklist par étape, vu sur place et
recommandation, plan d'action et fin de visite ; chaque étape se replie une
fois faite. Le franchisé n'a pas de page à part : deux onglets de son dashboard magasin
montent le module, en **consultation**. « Campagne » (`?vue=campagne`, écran
`v_campagne`) : la carte « Objectif de campagne » (clients, N-1, objectif, la
trajectoire cumulée, le CA, les autres campagnes en une ligne), rien d'autre.
« Plan d'action » (`?vue=actions`, écran `v_plans`, où mènent
les notifications push) : la liste des actions à faire puis des fermées, la
campagne qu'une action sert en pastille, ce que le consultant a vu, l'historique
des visites. Pas de photo ni de changement de statut depuis ces onglets : ils
restent au consultant et à l'admin.

Hors ligne (module `assets/js/visites.js`) : la lecture `/visites/app` est
gardée en IndexedDB ; chaque écriture porte un `client_id`, est appliquée à
l'écran, mise en file et rejouée au retour du réseau ; les photos sont
réduites sur l'appareil (bord long 1600 px, JPEG 0,7, EXIF retiré) avant
d'entrer dans la file.

## 2. Mapping base de données → écran → champ

### Tables existantes réutilisées (`franchise_buddy_db`)

| Table | Colonnes | Endpoint | Écran · champ |
|---|---|---|---|
| `of_tag` | `id, name, color` | `/referentiels/leviers` | pastilles levier partout · `slug` = slug(`name`), `nom`, `color` |
| `kpi` | `id, name, unit, target, seuil_bas, seuil_haut, levid` | `/referentiels/kpis`, `/meta.seuils` | assistants (liste KPI) · Marge & coûts (seuils food/labour/overhead) |
| `position` | `id, app_type, name` (`app_type='CONSULTANT'`) | `/consultants` | Tâches consultants · rôle de l'intervenant |
| `formation`, `checklist`, `todo_task` | — | non utilisés par le cockpit | (périmètre Manuel Opératoire) |

Les seuils du cockpit viennent de `kpi.seuil_haut` pour les codes food / labour / overhead ;
`meta.seuils` est la projection de ces lignes.

### Tables à créer (DDL proposé)

Toutes les tables propres au cockpit portent le préfixe `ceo_`. Les tables réutilisées du
Manuel Opératoire (`of_tag`, `kpi`, `position`) gardent leur nom d'origine — elles ne sont
pas créées par le cockpit, seulement lues.

```sql
-- Points de vente
CREATE TABLE ceo_shop (
  id            VARCHAR(8) PRIMARY KEY,
  code          VARCHAR(16) NOT NULL UNIQUE,
  name          VARCHAR(120) NOT NULL,
  franchisee    VARCHAR(120) NOT NULL,
  zone          VARCHAR(60) NOT NULL,
  status        ENUM('Ouvert','En ouverture','Fermé') NOT NULL DEFAULT 'Ouvert',
  opened_on     DATE NULL,
  valuation_target DECIMAL(12,2) NULL,
  basket_ref    DECIMAL(6,2) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Réel mensuel encodé par le franchisé (une ligne par magasin et par mois)
CREATE TABLE ceo_shop_month_perf (
  shop_id       VARCHAR(8) NOT NULL,
  year          SMALLINT NOT NULL,
  month         TINYINT NOT NULL,
  revenue       DECIMAL(12,2) NULL,   -- ca
  revenue_budget DECIMAL(12,2) NULL,  -- caBudget (issu de ceo_shop_budget_month)
  net_margin    DECIMAL(12,2) NULL,
  tickets       INT NULL,
  basket_avg    DECIMAL(6,2) NULL,
  food_pct      DECIMAL(5,2) NULL,
  labour_pct    DECIMAL(5,2) NULL,
  overhead_pct  DECIMAL(5,2) NULL,
  valuation     DECIMAL(12,2) NULL,
  encoded_at    DATETIME NULL,
  PRIMARY KEY (shop_id, year, month),
  FOREIGN KEY (shop_id) REFERENCES ceo_shop(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Budget validé une fois par exercice avec le consultant
CREATE TABLE ceo_shop_budget (
  shop_id       VARCHAR(8) NOT NULL,
  fiscal_year   SMALLINT NOT NULL,
  validated_on  DATE NULL,
  validated_by  INT NULL,              -- position.id du consultant
  basket_target DECIMAL(6,2) NULL,
  months_total  TINYINT NOT NULL DEFAULT 12,
  PRIMARY KEY (shop_id, fiscal_year),
  FOREIGN KEY (shop_id) REFERENCES ceo_shop(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ceo_shop_budget_month (
  shop_id       VARCHAR(8) NOT NULL,
  fiscal_year   SMALLINT NOT NULL,
  month         TINYINT NOT NULL,
  revenue_budget DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (shop_id, fiscal_year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ceo_shop_budget_line (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  shop_id       VARCHAR(8) NOT NULL,
  fiscal_year   SMALLINT NOT NULL,
  label         VARCHAR(120) NOT NULL, -- poste
  levid         INT UNSIGNED NULL,     -- of_tag.id
  pct_budget    DECIMAL(5,2) NOT NULL,
  real_field    VARCHAR(20) NULL,      -- food | labour | overhead | NULL
  sort_order    SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Objectifs réseau 1/3/5 ans
CREATE TABLE ceo_network_target (
  horizon       ENUM('h1','h3','h5') PRIMARY KEY,
  target_year   SMALLINT NOT NULL,
  revenue_target DECIMAL(14,2) NOT NULL,
  openings_target TINYINT NULL,
  openings_real TINYINT NULL,
  note          TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Projets, jalons, coûts, tâches
CREATE TABLE ceo_project (
  id VARCHAR(8) PRIMARY KEY, name VARCHAR(200) NOT NULL,
  status ENUM('À lancer','En cours','En retard','En pause','Terminé','Abandonné') NOT NULL,
  priority ENUM('Basse','Moyenne','Haute') NOT NULL DEFAULT 'Moyenne',
  starts_on DATE, ends_on DATE, budget DECIMAL(12,2), value_est DECIMAL(12,2), value_real DECIMAL(12,2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ceo_project_levid  (project_id VARCHAR(8), levid INT UNSIGNED, PRIMARY KEY (project_id, levid));
CREATE TABLE ceo_project_milestone (id INT AUTO_INCREMENT PRIMARY KEY, project_id VARCHAR(8), name VARCHAR(200), target_on DATE, done_on DATE NULL);
CREATE TABLE ceo_project_cost   (id INT AUTO_INCREMENT PRIMARY KEY, project_id VARCHAR(8), label VARCHAR(120), planned DECIMAL(12,2), actual DECIMAL(12,2));
CREATE TABLE ceo_project_task (
  id VARCHAR(10) PRIMARY KEY, project_id VARCHAR(8) NOT NULL, name VARCHAR(200) NOT NULL,
  owner_kind ENUM('c','s') NOT NULL, owner_id VARCHAR(10) NOT NULL,
  shop_id VARCHAR(8) NULL,
  due_on DATE NOT NULL, done_on DATE NULL, reminded_on DATE NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Journal + reporting
CREATE TABLE ceo_journal_entry (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, happened_at DATETIME NOT NULL,
  actor VARCHAR(80) NOT NULL, kind VARCHAR(40) NOT NULL, project VARCHAR(200) NULL, message TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ceo_report_schedule (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, frequency VARCHAR(30) NOT NULL,
  recipients TEXT NOT NULL, next_run DATE NULL, format VARCHAR(10) NOT NULL DEFAULT 'PDF', active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ceo_app_setting (`key` VARCHAR(60) PRIMARY KEY, value TEXT NOT NULL);
```

`ceo_app_setting` porte tout ce que `/meta` renvoie hors référentiels (libellés réseau, exercice
courant, contribution attendue des ouvertures, notes).

```sql
-- Catalogue et ventes mensuelles par référence (alimente /products/scoring)
CREATE TABLE ceo_product (
  id            VARCHAR(24) PRIMARY KEY,
  nom           VARCHAR(120) NOT NULL,
  categorie     VARCHAR(60)  NOT NULL,
  actif         TINYINT(1)   NOT NULL DEFAULT 1
);

CREATE TABLE ceo_product_month_sales (
  product_id    VARCHAR(24) NOT NULL,
  annee         SMALLINT    NOT NULL,
  mois          TINYINT     NOT NULL,
  volume        INT         NOT NULL,          -- unités vendues, réseau
  nb_magasins   SMALLINT    NOT NULL,          -- magasins ouverts ayant vendu la référence
  prix_moyen    DECIMAL(8,2) NOT NULL,         -- prix de vente TTC moyen
  cout_unitaire DECIMAL(8,2) NOT NULL,         -- matière + emballage
  PRIMARY KEY (product_id, annee, mois),
  FOREIGN KEY (product_id) REFERENCES ceo_product(id)
);
```


```sql
-- Contrôle des posts Facebook des magasins
CREATE TABLE mar_social_post (
  id               VARCHAR(16) PRIMARY KEY,
  shop_id          VARCHAR(8)   NULL,          -- NULL = post réseau
  author           VARCHAR(120) NOT NULL,
  format           VARCHAR(20)  NOT NULL,      -- Photo | Carrousel | Vidéo | Texte | Lien | Événement
  message          TEXT         NOT NULL,
  link             VARCHAR(400) NULL,
  medias_json      JSON         NULL,          -- [{"nom","type","alt","largeur","hauteur"}]
  planned_at       DATETIME     NULL,
  submitted_at     DATETIME     NOT NULL,
  status           ENUM('brouillon','a_controler','a_valider','valide','refuse','publie') NOT NULL DEFAULT 'a_controler',
  agent_note       TINYINT      NULL,          -- proposition de l'agent
  agent_summary    VARCHAR(400) NULL,
  agent_ran_at     DATETIME     NULL,
  agent_runs       SMALLINT     NOT NULL DEFAULT 0,
  note             TINYINT      NULL,          -- décision humaine
  decision_famille VARCHAR(60)  NULL,          -- obligatoire sous le seuil / en cas de refus
  decision_type    VARCHAR(80)  NULL,
  decision_comment TEXT         NULL,
  decided_at       DATETIME     NULL,
  decided_by       VARCHAR(80)  NULL,
  published_at     DATETIME     NULL,
  fb_post_id       VARCHAR(64)  NULL,          -- id Facebook, quand la publication sera branchée
  CONSTRAINT fk_fbpost_shop FOREIGN KEY (shop_id) REFERENCES ceo_shop(id)
);

CREATE TABLE mar_social_ecart (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  post_id    VARCHAR(16)  NOT NULL,
  rule_code  VARCHAR(40)  NOT NULL,
  rule_name  VARCHAR(120) NOT NULL,
  famille    VARCHAR(60)  NOT NULL,
  type       VARCHAR(80)  NOT NULL,
  gravite    TINYINT      NOT NULL,            -- 1 mineur | 2 majeur | 3 critique
  message    VARCHAR(400) NOT NULL,
  extrait    VARCHAR(200) NULL,
  status     ENUM('ouvert','ignore','corrige') NOT NULL DEFAULT 'ouvert',
  created_at DATETIME     NOT NULL,
  CONSTRAINT fk_finding_post FOREIGN KEY (post_id) REFERENCES mar_social_post(id) ON DELETE CASCADE
);
```

Les écarts sont **réécrits à chaque contrôle** : ils décrivent le texte tel
qu'il est, pas son historique (le journal, lui, garde la trace). Seul
`status = 'ignore'` est reporté d'un contrôle au suivant, par `rule_code` — une
dérogation accordée ne se rejoue pas à chaque passage de l'agent. L'id d'un
écart change donc au recontrôle.

`tendVol` est calculé côté API : `volume(annee, mois) / volume(annee-1, mois)`.

-- Scouting commercial
CREATE TABLE ceo_scouting_tile (
  sector      TINYINT UNSIGNED PRIMARY KEY,    -- secteur Overpass 0–8
  fetched_at  DATETIME NOT NULL,
  payload     MEDIUMTEXT NOT NULL              -- JSON { t, c, b, p } déposé par le navigateur
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ceo_scouting_competitor (
  osm_id         VARCHAR(20) PRIMARY KEY,      -- n123 / w456 / r789
  name           VARCHAR(200) NOT NULL DEFAULT '',
  commune        VARCHAR(120) NOT NULL DEFAULT '',
  arrondissement VARCHAR(60)  NOT NULL DEFAULT '',
  rating         DECIMAL(2,1) NULL,
  reviews        INT UNSIGNED NULL,
  rating_source  ENUM('google','manuel') NULL,
  comment        VARCHAR(200) NULL,
  updated_at     DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ceo_scouting_candidate (
  id             BIGINT UNSIGNED PRIMARY KEY,  -- horodatage client (ms)
  name           VARCHAR(200) NOT NULL,
  commune        VARCHAR(120) NOT NULL,
  arrondissement VARCHAR(60)  NOT NULL,
  province       VARCHAR(60)  NOT NULL,
  lat            DECIMAL(9,6) NOT NULL,
  lng            DECIMAL(9,6) NOT NULL,
  households     INT UNSIGNED NOT NULL,
  market         INT UNSIGNED NOT NULL,
  emprise        DECIMAL(5,4) NOT NULL,        -- ratio 0–1
  revenue        INT UNSIGNED NOT NULL,        -- CA annuel TTC estimé
  score          TINYINT UNSIGNED NOT NULL,
  shops          SMALLINT UNSIGNED NOT NULL,
  strong         SMALLINT UNSIGNED NOT NULL,
  revenue_m2     INT UNSIGNED NOT NULL,
  created_at     DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ceo_scouting_population (
  ins         CHAR(5) PRIMARY KEY,             -- code NIS
  population  INT UNSIGNED NOT NULL,
  imported_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Les hypothèses du modèle sont une ligne de `ceo_app_setting` (`scoutingParams`).
La clé Google est celle du connecteur Google de Paramètres (ligne `google`,
`PUT /parametres/google-cle`) : le serveur interroge Places, la clé n'est jamais
renvoyée à l'écran.

```sql
### Écran → tables

| Écran | Tables |
|---|---|
| Tâches consultants | `ceo_project_task`, `ceo_project`, `ceo_shop`, `position`, `of_tag` |
| Tableau des magasins | `ceo_shop`, `ceo_shop_month_perf` |
| Heatmap mensuelle | `ceo_shop`, `ceo_shop_month_perf`, `ceo_shop_budget_month` |
| Objectifs de CA | `ceo_network_target`, `ceo_shop_budget_month`, `ceo_shop_month_perf`, `ceo_app_setting` |
| Suivi budget magasin | `ceo_shop_budget`, `ceo_shop_budget_month`, `ceo_shop_budget_line`, `ceo_shop_month_perf` |
| Marge & coûts | `ceo_shop_month_perf`, `kpi` (seuils) |
| Projets | `ceo_project`, `ceo_project_milestone`, `ceo_project_cost`, `ceo_project_task`, `ceo_project_levid` |
| Reporting | `ceo_report_schedule`, `ceo_shop`, `position` |
| Scoring produits | `ceo_product`, `ceo_product_month_sales` |
| Contrôle posts Facebook | `mar_social_post`, `mar_social_ecart`, `ceo_shop`, `ceo_app_setting` (clé `fbControle`) |
| Journal | `ceo_journal_entry` |
| Paramètres | `of_tag`, `kpi`, `ceo_app_setting`, `ceo_shop` |

---

## 3. Écritures (assistants et actions du cockpit)

Les écrans qui écrivent aujourd'hui en mémoire attendent ces routes :

| Action | Route |
|---|---|
| Nouveau projet (assistant 4 étapes) | `POST /projects` |
| Nouvelle tâche (assistant 3 étapes) | `POST /projects/{id}/tasks` |
| Changement de statut projet | `PATCH /projects/{id}` |
| Relance d'une tâche | `POST /tasks/{id}/reminder` |
| Modification d'un seuil ou d'un modèle d'email | `PUT /parametres/{key}` |
| Scouting — hypothèses du modèle | `PUT /parametres/scoutingParams` (`{ "valeur": … }`) |
| Scouting — dépôt d'un secteur OSM dans le cache partagé (repli navigateur) | `PUT /scouting/tiles/{secteur}` (corps : `{ t, c, b, p }`, ≤ 12 Mo) |
| Scouting — relecture d'un secteur OpenStreetMap par le serveur | `POST /scouting/refresh/{secteur}` (0 à 8 ; une à trois minutes ; rend le secteur `{ t, c, b, p }` et le dépose dans le cache ; 502 si Overpass ne répond pas, le cache est alors conservé) |
| Scouting — notes, avis, source, commentaire terrain (lot ≤ 500) | `PUT /scouting/competitors` (`{ "rows": [{ id, name?, commune?, arr?, rating?, reviews?, source?, comment? }] }` — seules les clés présentes sont modifiées) |
| Scouting — qualification terrain d'un concurrent (type, force 0–100, référence, visite, relevé) | même `PUT /scouting/competitors`, champs `type`, `force`, `reference`, `visiteLe`, `terrain` par ligne ; identifiants `n`, `w`, `r` ou `m` ; une ligne vidée de tout est effacée, sauf un commerce ajouté à la main |
| Scouting — un commerce vu sur place, absent d'OpenStreetMap | `POST /scouting/concurrents` (`{ name, lat, lng, commune?, arr?, type?, rating?, comment?, force?, reference?, visiteLe?, terrain? }` → `{ ok, id: "m…", concurrent }`) ; 400 sans nom ou hors de Belgique |
| Scouting — retirer un commerce ajouté à la main | `DELETE /scouting/concurrents/{m…}` (404 inconnu ; jamais un relevé OSM) |
| Scouting — relevé terrain d'une zone retenue | `PUT /scouting/candidates/{id}/terrain` (corps = le relevé ; vide = effacé ; 404 zone inconnue) → `{ ok, id, terrain }` |
| Scouting — notes Google d'un lot de commerces (≤ 40 ; clé de Paramètres, côté serveur) | `POST /scouting/notes` (`{ "rows": [{ id, name, addr?, commune?, arr?, lat, lng }] }` → `{ rows: [{ id, rating, reviews }], erreur? }` ; 422 sans clé) |
| Scouting — zone candidate retenue / retirée | `POST /scouting/candidates` (objet zone) · `DELETE /scouting/candidates/{id}` |
| Scouting — position d'un magasin du réseau pointée sur la carte | `PUT /scouting/reseau/{id}` (`{ lat, lng }`, Belgique seulement ; prime sur la fiche Google ; ligne `ceo_app_setting.scoutingReseau`) |
| Scouting — import CSV StatBel | `PUT /scouting/populations` (`{ "populations": { "NIS": population }, "fichier"? }`) |
| Soumission d'un post Facebook au contrôle | `POST /facebook/posts` |
| (Re)passer l'agent de contrôle sur un post | `POST /facebook/posts/{id}/controle` |
| Décision du CEO (valider / refuser) ou publication déclarée | `PATCH /facebook/posts/{id}` |
| Écarter, rouvrir ou clore un écart | `PATCH /facebook/posts/{id}/ecarts/{ecartId}` |
| Activation/désactivation d'une règle de contrôle | `PUT /parametres/fbControle` |

Toute écriture doit aussi produire une ligne `ceo_journal_entry` (l'écran Journal en dépend).

### Le cadre de visite, la gestion des consultants, l'agenda Google (09/10/2026)

Module `src/visites_cadre.php`. Chaque visite a un **type** (la liste déroulante du planning) : sa durée, le
profil de consultant qu'il demande, sa checklist (modules et points, même forme que la liste standard) et ses
tâches à faire avant, pendant et après. Le **cadre** dit, par magasin, combien de visites de chaque type par mois
ou par trimestre, par quel profil, par quel consultant. Les **tâches du consultant** vivent dans
`ceo_consultant_tache` : celles que le type génère à la planification (une par tâche du type, `client_id`
`v{visite}:{n}`, échéance J−n / J / J+n) et celles qu'il se donne. Colonnes ajoutées à `ceo_visite` :
`type_code` (défaut `reguliere`), `ics_seq`. Réglages : `visitesTypes`, `visitesCadre`, `visitesProfils`,
`visitesConsultantProfil` ; la checklist du type « Régulière » reste `visitesChecklist`, un seul réglage lu aux
deux endroits. Les types livrés : reguliere (23 points), production (14), hygiene (12), msp (8), bilan (10),
ouverture (19), suivi (dynamique : les plans d'action ouverts), revisite (dynamique : les points non conformes de
la dernière visite).

| Route | Ce qu'elle rend ou fait |
|---|---|
| `GET /visites/cadre?mois=` | `types[]` (code, nom, duree, profil, profilNom, actif, dynamique, checklist[], taches[] {quand, delai, libelle}, points, nbTaches), `cadre[]` (id, shop, type, nb, par, profil, consultant, consultantNom, auto), `attendu[]` (le cadre face au réel du mois : attendu, faites, planifiees, aPlanifier, derniere, prochaine), `profils[]`, `consultants[]` (avec profil), `magasins[]`. |
| `PUT /visites/cadre` | `types[]` (la régulière avec `checklist` écrit `visitesChecklist`), `cadre[]`, `profils[]`, `consultantProfil{id: code}`. Rend la lecture. |
| `GET /consultants/gestion?consultant=u6&mois=2026-10` | l'écran Gestion consultant : `consultant`, `consultants[]`, `visites[]` du mois ±6 jours (tous consultants, `mienne`, `typeNom`, `magasin`, `points{total,faits,ko}`, `google` le lien « Ajouter à Google Agenda »), `plans[]` ouverts, `taches[]` (ouvertes, du mois, faites ce mois), `cadre[]` (= attendu), `boutiques[]` (feu, dernière et prochaine visite), `reseau[]` (consultant × magasin : dernière, prochaine, responsable ; visites du mois, tâches ouvertes et en retard), `helpdesk` (les cas du panel quand il répond), `agenda` (`ics`, `webcal`, `invitations`, `smtp`, `notePanel`). `consultant=tous` pour le réseau ; sans paramètre, le compte connecté (`consultantIdCompte`). |
| `POST /consultants/taches` | `titre`, `consultant` (sinon le compte), `shop`, `echeance`, `detail`, `source` (perso…), `visite_id`, `client_id` (rejouable). |
| `PUT /consultants/taches/{id}` | `statut` (a_faire, en_cours, fait, annule — `fait_le` posé), `titre`, `detail`, `echeance`, `shop`. |
| `GET /consultants/{id}/visites.ics?jeton=` | le flux iCalendar du consultant (−60 j … +180 j, hors annulées), **sans session** : le jeton de l'adresse (`vcIcsJeton`, HMAC du jeton des visites) est sa clé. Google Agenda s'y abonne (« À partir de l'URL ») et le relit toutes les 12 à 24 h. |
| `POST /visites` | accepte `type` (code) ; la durée vient du type si elle n'est pas donnée ; la réponse porte `suite` : `taches` créées, `invitation` (`envoye`, `motif`, `a`). |
| `PUT /visites/{id}` | accepte `type` ; déplacée (date, heure, durée, consultant, type) → les tâches ouvertes du type suivent, `ics_seq` + 1, invitation « Mise à jour » ; annulée → tâches annulées, invitation CANCEL ; terminée → les tâches « pendant » faites, note VISIT déposée au panel si `seuils.notePanel`. |
| `PUT /visites/{id}/points` | le module est un code court (celui de la checklist du type), plus seulement ceux de la liste standard. |
| `GET /visites/app` | en plus : `types[]`, `cadre[]`, `taches[]` (celles des visites servies), `consultants[].profil`, `boutiques[].derniereVisite.type` et `prochaineVisite.type`. |

Seuils ajoutés (`visitesSeuils`) : `invitations` (vrai : l'invitation .ics part au consultant à chaque visite
planifiée, déplacée, annulée — SMTP requis), `notePanel` (faux : à la clôture, une note de type VISIT est
déposée dans le panel, type « Visite » de `notePanelDeposer`). `viFrequence` lit le cadre quand il est réglé
(30 / visites par mois), la fréquence en jours reste le repli.

### Les franchisés — évaluation et suivi (09/10/2026)

Module `src/franchises.php`. Rien n'est ressaisi : tout est lu là où il vit, une source qui ne répond pas rend
son `motif`.

| Route | Ce qu'elle rend |
|---|---|
| `GET /franchises` | `magasins[]` : feu, motifs, `responsable` (le consultant du cadre), `journalier` (`mois` {atteinte, ca, budget, pro, proLus, proJours} : le CA du mois face au budget attendu à date et la part du CA pro, d'après `/exploitation/periode?vue=mois` ; scoring du trimestre courant, tâches sur 30 jours `pct` et `joursObligManques` d'après `ceo_tache_jour` et les obligatoires du scoring, invendus 7 jours `part` du CA seulement avec sa `cible`, `revues` (points moyens des tâches notées sur 30 jours `moyenne`, `nc`, `mineures`, `majeures`, `critiques`), `reclamations` (réclamations fournisseur sur 30 jours `n` et `ouvertes` sans réponse), note Google, CA de la semaine), `terrain` (dernière et prochaine visite, plans ouverts et P0, planogramme de la dernière visite, dernier client mystère encodé, lignes de cadre). `_cache=600` conseillé. |
| `GET /franchises/fiche?shop=` | la fiche : `feu`, `consultants[]` du cadre, `journalier` (`mois` {ca, budget, attendu, atteinte en %, ecart, tickets, panier, pro {part, ca, tickets, panier, joursLus, jours, complet, max 40, alerte 35}, jours[]}, `tachesJour` les revues du jour, `taches` 30 jours avec `jours[]` {jour, f, t, oblig}, `invendus` {part, cible} — rien d'autre, le détail reste sur l'écran Invendus et poubelle —, `revues` {moyenne, notees, parNote[], nc, mineures, majeures, critiques, infractions[] {jour, tache, note, niveau, niveauNom, comment, recidive, suite}} d'après `mac_task_review` (toute tâche notée sous le seuil du barème `signalement`), `reclamations` {n, ouvertes, acceptees, refusees, montant, parFournisseur[], dernieres[] (5), lignes[] (toutes, 60 au plus)} d'après `ep_fournisseurs_reclamations`, `objectifs` {produits[], campagnes[]}, `remarques` {n, parOperateur[], dernieres[]}, `google`, `ca`), `terrain` (`visites` {derniere avec points et ecarts, prochaines, mois, historique}, `plans`, `cadre`, `msp`, `mspVisites`, `conformite` (`vcConformiteMagasin`), `scoring`, `plano`, `equipe`, `app`), `journal[]` (visites terminées, événements des plans d'action, remarques, client mystère, 60 jours, `volet` journalier ou terrain). La page du franchisé `dashboard/suivi.html?shop=` la lit telle quelle. |

| `GET /consultants/checklists?consultant=&shop=` | les checklists **des consultants** seulement, rien que l'API du panel : `consultants[]` {id = membership, nom, poste, niveau, moi} d'après `/panel/consultants` ; `choisi` (le compte du cockpit par défaut) ; `checklists[]` {id, nom, description, poste de travail, heure, taches[] {id, nom, description, section, categorie, sousCategorie, frequence, jour, heure, obligatoire, priorite, photoRequise, note, faitPanel}} : les checklists du cadre opérationnel (`/operational-framework/checklists` et `…/{id}/tasks`) qui portent des tâches du poste, puis « Tâches du poste » pour le reste ; les tâches du compte viennent de `/consultant/tasks?date=` avec leur état (`faitPanel`), celles d'un autre consultant de `/levels/{niveau}/tasks` sans état ; `saisies` {taskId: {etat, commentaire, panel, le, par}} : ce qui est rempli aujourd'hui pour ce magasin. |
| `POST /consultants/checklists` | le formulaire de visite : `{ shop, consultant, consultant_nom, moi, checklist_id, checklist_nom, panel, taches: [{task_id, nom, etat: fait | pas_fait | na, commentaire}] }` → `{ ok, enregistrees, panelEnvoyees, panelRefusees, saisies }`. Gardé par magasin, jour, consultant et tâche (`ceo_consultant_checklist`, mis à jour si la tâche est remplie de nouveau). Une tâche « fait » du compte du cockpit part au panel (`POST /consultant/tasks/{id}/mark-as-done` avec le commentaire), une fois par jour : le panel compte une complétion par jour, pas par magasin. 422 sans magasin, sans consultant ou sans tâche remplie. |
| `GET /franchises/meteo?shop=` | la météo du franchisé, lue par le consultant seulement (elle n'est pas dans `/franchises/fiche`, que lit la page du franchisé) : `echelles` (moral, envie, equipe, relation, de 1 orage à 5 soleil), `derniere`, `meteos[]` {le, consultant, moral, envie, equipe, relation, envies, demandes[] {texte, tacheId, statut, faitLe}, inquietudes, note}, `tendance[]` (12 dernières, chronologique), `demandesOuvertes`. |
| `POST /franchises/meteo` | `{ client_id, shop, le?, moral, envie, equipe, relation, envies, demandes: [texte] ou texte une par ligne, inquietudes, note, taches: true }` → `{ ok, meteo, taches }`. Chaque demande devient une tâche du consultant (`ceo_consultant_tache`, source `perso`, échéance J+7) sauf `taches: false`. Idempotent par `client_id`. 422 si le magasin manque ou si tout est vide. Table `ceo_franchise_meteo`. |

Le cockpit : section « Franchisés · évaluation et suivi » du rail — Fiche franchisé, Scoring du trimestre, puis
La fiche suit la visite en cinq étapes : 1 Agenda (les visites des 14 jours, le choix du magasin), 2 Checklist (les
checklists du consultant, puis la liste des tâches et le formulaire), 3 Santé du magasin (CA du mois face au budget,
part B2B, scoring, ses postes Google et Tâches, infractions mineures, météo, puis les deux volets et le journal),
4 Benchmark réseau, 5 Fin de visite (la météo : comment se sent le franchisé). Sur tablette, la fiche devient l'application du consultant, comme la PWA Visites : en-tête d'app (☰, logo, magasin, relire, page du franchisé, initiales), en-tête d'étape avec ‹ et « étape n / 5 », barre des 5 étapes fixe en bas. `?app=consultant` force ce mode à toute largeur, ouvre la fiche et pose le manifeste `consultant.webmanifest` (installable sur l'écran d'accueil, sans service worker). Sur ordinateur, rien ne change. Le rail :
deux sous-menus (Suivi journalier et opérations : tâches et contrôles photo, objectifs, remarques
opérateurs, note Google, reporting — l'entrée Invendus et poubelle est revenue sous Magasins, le suivi du
franchisé ne gardant que la part des invendus dans le CA et les réclamations fournisseur ; la fiche et la page
du franchisé lisent en trois niveaux : la tuile KPI dit le chiffre, dépliée elle montre son détail, « Tout voir »
ouvre une modale sur la liste complète — infractions, réclamations, tâches jour par jour, plans d'action ; Suivi de terrain : gestion consultant, visites, client mystère, conformité
du comptoir). Écrans nouveaux : `#/gestion-consultant`, `#/fiche-franchise`, `#/remarques-operateurs`
(`GET /equipe/remarques`). Les anciennes adresses restent valables.

### `GET /analyse/pnl/mois` — le P&L mois par mois d'un magasin, depuis le P&L quotidien du panel (09/10/2026)

`GET /analyse/pnl/mois?shop=4&du=2026-02&au=2026-10[&tous=1][&jours=1][&rafraichir=1]`. Le panel ne sert le P&L
mensuel que pour le mois courant, mais son P&L quotidien (`/consultant/shops/{id}/pnl/daily?date_from&date_to`)
sert le passé : un appel par mois et par magasin (`tous=1` : chaque magasin actif), en parallèle, agrégé en
`ca`, `matiere`, `labour`, `overhead`, `resultat` avec leurs parts du CA, les jours servis et les jours avec ventes.
Mesuré le 09/10/2026 : `material` et `result` sont à zéro sur presque tous les jours (le panel ne porte pas la
matière, cf. ticket T5a) ; `labour` et `overhead` sont ce que le franchisé encode. Les mois clos se gardent 24 h
(`pnlMois:<shop>:<mois>`), `jours=1` joint le détail par jour. Né pour le dossier investisseur de Halle.

### `GET /dossiers/fichiers` et `POST /dossiers/fichiers/envoyer` — les dossiers déposés dans `docs/dossiers/` et leur envoi en pièces jointes (09/10/2026)

Un dossier déposé est un sous-dossier de `docs/dossiers/` du dépôt (livré sur le serveur avec le reste), nommé en
slug `a-z0-9-`, avec un `dossier.json` facultatif : `titre`, `objet` (sujet du courriel), `resume` (texte du
courriel, paragraphes séparés par une ligne vide). Premiers dossiers : `halle-2026-10`, le dossier investisseur de
Halle (PDF 14 pages A4, français puis néerlandais, + classeur Excel ; `generer.py` et `classeur.py` sont les générateurs, ils ne se joignent pas), et `halle-produits-2026-10`, la liste des produits de Halle avec leur coefficient (PDF + Excel).

`GET /dossiers/fichiers[?dossier=halle-2026-10]` → `{dossiers: [{dossier, titre, objet, fichiers: [{nom, type,
octets, modifie}], octets}], adressesConnues: [...], smtp: bool, source}`. Seuls les `.pdf`, `.xlsx`, `.docx`,
`.csv`, `.png`, `.jpg` sont joignables. 404 sur un `dossier=` inconnu.

`POST /dossiers/fichiers/envoyer` `{dossier, a[, copies: [...], fichiers: [noms], objet, message]}` envoie les
fichiers du dossier (tous, ou ceux de `fichiers`) en pièces jointes par la machine SMTP du cockpit (`Smtp::envoyer`),
avec une page de garde HTML (titre, message, liste des pièces). Le destinataire `a` et les `copies` doivent être
des adresses que le cockpit connaît déjà (`adressesConnues` : destinataires des rapports de `ceo_rapport`, compte
et expéditeur SMTP) — pas de relais ouvert. Réponse `{ok: true, dossier, titre, a, copies, objet, pieces: [{nom,
octets}], octets, via: 'smtp', envoye}`. Refus : 404 dossier inconnu ou aucun fichier joignable, 400 adresse
invalide, 403 adresse inconnue du cockpit, 413 pièces cumulées au-delà de 20 Mo, 503 SMTP non configuré, 502 le
serveur SMTP refuse (`error` porte `Smtp::$lastError`). Chaque envoi, réussi ou refusé par le serveur, laisse une
ligne `ceo_journal_entry` (kind `dossier`, project = le slug).

### `GET /analyse/produits/coefficients` — chaque produit d'un magasin avec son coefficient, sa catégorie et son groupe (09/10/2026)

`GET /analyse/produits/coefficients?shop=4[&inactifs=1][&sonde=1]`. API du panel seulement : `/shops/{id}/products/available`
(prix pratiqué `portion_price` / `portion_price_net`, TVA, coût de recette du jour `recipe_cost_net` / `recipe_cost_gross`,
catégorie en objet avec `price_coefficient` et `groups`, `is_divisible` / `portion_size`), `/product-categories` (coefficient
cible `price_coefficient`, atelier de production) et `/product-category-groups` (le groupe, la famille : Tartes, Traiteur…).
Le coefficient est **prix HT de la pièce entière ÷ coût de recette net** (un produit divisible vendu à la portion : prix de la
portion ÷ `portion_size`). Zones de l'onglet « Recette & marge » : `ko` sous × 1,67 (marge brute < 40 %), `att` sous × 2,5,
`ok` au-delà, `mu` sans coût ni prix, `ab` recette à vérifier (coût au-dessus du prix ou sous 5 % de celui-ci, la règle de vraisemblance du P&L ; hors des médianes) ; objectif du réseau × 3,13 (matière 32 %). Un produit dont la catégorie n'a pas de groupe prend sa catégorie pour groupe. Réponse : `{shop, magasin, court, n, nChiffres,
coefMedian, zones{ok,att,ko,mu}, seuils{ko,att,objectif,food}, produits: [{id, nom, catId, cat, groupe, atelier, secteur, vie,
actif, webshop, divisible, part, tva, prixTtc, prixHt, conseille, cout, coutTtc, coef, coefTtc, margePct, foodPct, margeHt,
zone, cible, ecartCible, prixCible, prixObjectif, recette, stock}], categories: [{id, nom, groupe, atelier, cible, n, nChiffres,
coefMedian, coefMin, coefMax, zones}], groupes: [{nom, n, nChiffres, coefMedian, coefMin, coefMax, zones}], lu, source}`, produits
triés par groupe, catégorie, nom ; les produits inactifs sont écartés sauf `inactifs=1`. `prixCible` et `prixObjectif` : le prix
TTC de la pièce qui atteindrait la cible de sa catégorie, ou l'objectif du réseau, aux 5 centimes supérieurs. Relu à chaque
appel. 400 sans `shop`, 503 sans API, 502 quand le panel ne rend aucun produit. `sonde=1` rend les premières lignes brutes.

**La modale d'une catégorie en deux onglets (10/10/2026, maquette A).** Le (i) d'un groupe ou d'une catégorie ouvre deux onglets. « 12 dernières semaines » : la courbe du magasin face à la moyenne par magasin du réseau, la tendance du magasin en pointillé (droite des moindres carrés sur les semaines closes) et, sous l'axe, l'écart de chaque semaine à la précédente (▲▼ %) ; à côté, la tendance des 4 dernières semaines closes face aux 4 d'avant, la même pour le réseau, la pente par semaine, la meilleure et la plus faible semaine, la semaine en cours, l'écart au réseau . Le tableau des sous-catégories a été retiré (10/10/2026). « La journée · 6 h – 18 h » : les ventes de chaque heure du jour choisi (bleu) face à la moyenne des 6 derniers mêmes jours (gris), l'heure en cours au trait rouge ; à côté, le vendu, la moyenne à la même heure, l'écart, l'heure forte, la part d'avant midi et celle d'habitude, une journée moyenne. CA ou pièces. Plus de tableau de chiffres : un clic sur une semaine ou une heure montre ses valeurs dans une mini-vignette (un second clic la retire ; la fiche produit aussi). Nouveau `GET /analyse/categories/journee?shop=&niveau=groupe|categorie&nom=&date=` : `{date, jourNom, maintenant (le jour même), jours (les mêmes jours lus), manquants, heures: [{h 6…17, ca, q, moyCa, moyQ}]}`, lu dans les relevés de tickets (`svProduitsJour`), un jour sans aucune vente au magasin ne compte pas dans la moyenne.

**Tablette (10/10/2026).** Sous 760 px, les vignettes des jours de la frise gardent le montant entier (sans « € » ni « obj. »). La jauge de l'objectif du jour : quand le jour, J−7 ou la projection dépasse l'objectif, l'échelle s'allonge jusqu'au plus haut et l'objectif devient un repère sur les deux pistes, son montant sous l'axe à sa place ; le trait « journée écoulée » se place sur l'objectif au prorata de la journée.

**Fiche produit sans tableau, « Ta place dans le réseau » dans l'Opérationnel (10/10/2026).** La fiche produit perd son tableau des semaines : un clic sur un point de la courbe montre ses valeurs dans la mini-vignette (pièces du magasin, du réseau). La zone cliquable de chaque semaine passe au-dessus des points, pour qu'un doigt posé sur un point ouvre la vignette (modale des catégories aussi). Le bandeau « Ta place dans le réseau » (chiffre d'affaires, clients, panier moyen, marge brute, B2B) s'affiche dans l'Opérationnel juste au-dessus de l'objectif du jour, sur la journée comme en vue Jour.

**Fiche produit, onglet Prix et Recette & marge (10/10/2026).** L'onglet « Prix face au réseau » montre le prix de vente en caisse du magasin (`portion_price_gross` de products/available, le prix pratiqué) à côté du prix encaissé, et les autres magasins : en gris et nommés dans le nuage (un clic sur un point : prix de vente, prix encaissé, pièces pour 10 000 €, pièces vendues), et en liste sous le côté (magasin, vente, encaissé, pièces pour 10 000 €). `GET /analyse/produits/magasin` porte `prix.magasins` : `[{nom, moi, p, v10k, q, brut}]` et `prix.magasin.brut`. Les lignes d'explication sont retirées (onglet 1 : « en pièces ; un grand magasin vend plus… » ; Prix : « prix encaissé = chiffre ÷ pièces… » ; Recette & marge : « une pièce vendue aujourd'hui · coût de recette… »). Une recette d'une seule ligne (le produit acheté tel quel, 100 % du coût) n'a plus de liste déroulante. L'axe des pièces de la courbe des 12 semaines prend un pas rond au-delà de 60 (une catégorie compte des milliers de pièces).

**`GET /ventes/tickets?shop=&date=&cat=` (ou `&q=`) (10/10/2026).** Les tickets d'un jour qui portent une catégorie (ou un mot du nom d'un produit), ligne par ligne, pour lire une promotion ou une remise : `{ticketsDuJour, ticketsLus, tickets: [{id, heure, total, remiseTicket, bon, pro, lignes: [{pid, nom, cat, vise, q, pu, remise, total, portion}]}]}`. Lecture seule, rien n'est gardé ; ni client, ni vendeur, ni remarque.

**Mode de service (11/10/2026).** `&service=0` garde les tickets d'un mode de service, toutes leurs lignes (`vise: true`). Seuls ces tickets se lisent. Chaque ticket porte `service` (l'`id_serving_method` de la liste du panel), la réponse aussi. Mesuré à Halle du 1er au 11/10 : 0 = sur place (le compte colle heure par heure à `eat_in_qty` de l'heure par heure), 2 = à emporter ; 21 tickets sur place sur 1 659. Avec `cat` ou `q`, le filtre s'ajoute : les tickets du mode, la ligne visée seulement.

**Pro = TVA valide (11/10/2026).** Un ticket ne compte pro que si le panel le marque client pro (`is_client_b2b`) ET que la fiche du client porte un numéro de TVA valide (`client.tax_number` ; BE : clé modulo 97 ; autre pays de l'UE : forme). Mesuré à Halle le 11/10 : 6 tickets marqués pro pour 469,20 €, une seule fiche avec TVA (114,50 €), cinq sans TVA ni nom de société.
- `vpTvaValides(shop, clés)` : le verdict par client, lu à la fiche (`/shops/{id}/clients/{id}`) et gardé dans `vpTva:{shop}` (six heures s'il est bon, une heure sinon : une fiche complétée au panel compte dans l'heure). Une fiche illisible garde l'avis du panel ; un ticket sans identifiant de client n'est pas pro.
- Le relevé `b` gardé dans `svP{shop}:{date}` reste l'avis brut du panel ; `vpFiltreTva` le filtre à la lecture (`vpLu`, `vpJour`, `vpGraveListe`, donc le Résultat, le split pro / comptoir, la règle des 40 %, les clients manquants au comptoir). Jours passés compris : une fiche complétée fait revenir ses tickets au pro.
- `GET /exploitation/pro` : `jour.sansTva` (le jour) et `sansTva` (les 30 jours) `{tickets, ca}` = ce qui est marqué pro au panel sans TVA valide, compté au comptoir. La carte « Clients pro » le dit : « N tickets marqués pro sans TVA valide… à compléter dans le panel ».
- Les pièces pro (production, planogramme) : le relevé garde `pbc` = les pièces pro client par client ; `svPbValide` n'en garde que les clients à TVA valide. Un relevé d'avant sans `pbc` se range client par client au cron (`svB2bMoisson`) ou à la demande, faute de budget il garde son `pb`. Un relevé du jour sans `pbc` se relit une fois en entier.
- `GET /ventes/tickets` : `pro` suit la même règle. Les commandes du plan de production aussi (`pro` = client pro à TVA valide, ou département de société).

**Lignes retirées du dashboard (11/10/2026, demande de Sam).** « Pas déclenchées : … » sous les promotions de Bundles vendus ; le sous-titre des sources du P&L (« matière : coût des recettes vendues · personnel : … · frais généraux : … », jour, semaine et mois) ; le sous-titre « ce qui manque dans la caisse et le panel · la ligne « Non ventilé » ci-dessus » de la carte À compléter. Dans la fiche produit, onglet Recette & marge : la source de la recette (« API du panel : /shops/{magasin}/recipes/{recette}/cost… », toujours dans `source` de la réponse) et la note du coefficient « ce que le prix fait de la matière : prix de vente ÷ coût de recette ». Les paliers « Le prix pour y arriver » (marge 40 %, sa catégorie, le prix réseau, marge 60 %, le magasin aujourd'hui, objectif) sont retirés aussi : trop d'information ; le prix se tape toujours dans la carte du coefficient. Les pastilles d'en-tête des fiches (`.fi-chip`) passent en rectangles aux coins arrondis de 4 px. Dans Opérationnel : la mention « ventes encaissées, pro compris » (« tickets encaissés, pro compris » en Clients) à droite de la carte Heure par heure, et au téléphone la ligne de renvoi « Le P&L, les ventes par catégorie, les promotions et les portions : Exploitation › · Les photos, les commandes, le stock et la poubelle : Contrôle › » ; les onglets du bas y mènent. Puis toutes les petites phrases des sections d'Opérationnel : les sous-titres du duel chiffre par chiffre (« à la même heure, puis la journée entière de la semaine passée »), d'Heure par heure (« le cumul d'aujourd'hui face à… »), de La journée (« de 04:00 à 20:00 · le trait rouge… »), de La vitrine (« à HH:MM · prévision des 6 derniers… », « ce qui est sorti du four face à… »), des Ventes par catégorie (« groupe › catégorie › produit · barre… · couleur… · coef… », et ceux des onglets Treemap, Promotions et Portions) et des Bundles vendus (« la journée · catégorie Bundle ») ; la règle de chaque durée de vie à côté de « N réf. » ; la puce de source de l'objectif (« budget du mois · profil des … ») et celle sans objectif ; « en direct · relu toutes les 2 min » sous l'heure ; « à encoder à la fermeture » et « pas de promotion posée pour aujourd'hui » ; « · face aux 6 derniers … » du titre des ventes au comptoir et « en euros, au prix de vente, hors commandes » de sa légende ; « une case par heure · cadre noir… » de la légende de la vitrine et l'aide « ▸ un clic ouvre la sous-catégorie… » ; « les écarts d'abord · un clic ouvre la photo en grand » sous les contrôles en photo. La raison de la cloche barrée du stock passe en infobulle. Aussi : « la journée · anonyme » sous « Ta place dans le réseau » (reste le nombre de magasins et les 🏆) ; l'aide « à chiffrer dans le panel… » et « à rattacher à un produit dans la caisse » d'À compléter ; dans le P&L, la source sous chaque ligne (« recettes vendues · estimé… », « mesure », « coût de production des pièces jetées ») : restent les seuils, les pièces jetées et « rien déclaré au panel ». `coutMatiereSource`, `labourSource` et `overheadSource` restent dans la réponse. Dans le cockpit, écran Résultat : le sous-titre de la page (« La journée, la semaine et le mois du réseau, face à l'objectif… Ouvrez une ligne pour le détail du magasin. ») et la phrase de chaque onglet (Jour, Semaine « l'objectif, ce qui est fait, ce qui reste… », Mois, Analyse réseau). Un écran au sous-titre vide n'affiche plus de ligne vide sous son titre. Les invitations « Ouvrez une ligne… » sous les tableaux Jour, Semaine et Mois et la note de source sous le tableau Semaine et Mois sont retirées aussi.

**Pondération des jours et caches du Résultat (11/10/2026).** Adopter une pondération (`POST /exploitation/ponderation-jours`) périme tout calcul gardé avant l'heure d'adoption (`adopteLe`) : `/exploitation/jour` et `/exploitation/periode` (rcServi) le servent alors comme périmé (`cache.frais` faux, `cache.relu` vrai) et le recalculent aussitôt, et le calcul des objectifs de la tablette n'est plus frais. Les objectifs du jour, de la semaine et du mois du dashboard, du cockpit et de la tablette suivent donc la nouvelle pondération dès l'affichage suivant, au lieu d'attendre la fin du cache (jusqu'à une heure pour un jour passé). Restent les chiffres, les états (« 🏆 objectif atteint », la prochaine cuisson) et les clés de couleur des légendes.

**Main-d'œuvre, frais généraux et marge nette des mois passés (11/10/2026).** Le tableau « mois par mois » de la vue Année (`GET /stores/perf`) lisait la main-d'œuvre, les frais généraux et la marge nette dans `mac_shop_monthly_pnl`, une copie du P&L mensuel du panel qui ne porte que juillet et août 2026 (août : frais généraux à 111,9 % du CA). Mesuré : le résumé quotidien du panel (`/shops/{id}/statistics/daily-summary?date=`) rend `employee_cost` et `shop_cost` pour tout jour passé.
- `resCoutsPeriode(shop, du, au, budget)` additionne les jours, gravés dans `dsJ:{shop}:{date}` une fois clos (resJoursPanel) ; `manque` = les jours encore sans chiffre.
- `/stores/perf` : pour chaque mois de l'année, `labourCostPct` et `overheadPct` = Σ jour ÷ CA du mois ; `margeNette` = CA − food cost − main-d'œuvre − frais généraux, `margePct` en fraction ; `coutsSource` = « panel, jour par jour ». 120 lectures au plus par requête ; un mois incomplet reste vide avec `coutsManque`.
- Le cron (`svCron` → `resCoutsMoisson`, 400 lectures par battement) remplit l'année d'avance.
- `/exploitation/periode` lit aussi les jours des mois passés : le P&L d'un mois passé a son résultat net.
- Le sous-titre « marge, labour et overhead connus depuis juillet 2026, food cost depuis janvier » est retiré.
- **API seule** (même jour, demande de Sam : « pas de table locale, API only ») : `/stores/perf` ne lit plus aucune copie locale du panel. Magasins : `/consultant/shops`. CA, tickets, panier : `/shops/{id}/statistics/sales/kpis` du mois (`pvKpisMois`, lus en parallèle par `pvKpisMoisLot`, gardés une fois le mois clos). Food cost : la heatmap de marge. Main-d'œuvre et frais généraux : le résumé quotidien, les deux années demandées (la plus récente d'abord), une somme gardée par mois clos et complet (`dsM:{shop}:{AAAA-MM}`). Seul le budget vient encore d'une table, celle du cockpit (`ceo_shop_month_perf`, saisi dans le cockpit). Sans réponse du panel : `[]`, plus de repli local. Le cron remplit l'année en cours et la précédente. Un jour clos se grave dès que le panel répond, même sans planning : sans vente ni planning (fermé, avant l'ouverture) il compte 0 € ; vendu sans planning, il rend la main-d'œuvre du mois inconnue (`sansPlanning`, le tableau affiche « — » avec le nombre de jours en bulle, les frais généraux restent). Un mois encore en lecture affiche « … ».

**Le P&L mensuel du panel (11/10/2026).** `/stores/perf` lit la main-d'œuvre et les frais généraux de chaque mois dans `/consultant/shops/{id}/pnl/monthly?from=AAAA-MM&to=AAAA-MM` (24 mois au plus ; sans `from`/`to` la route répond 422) : un appel par magasin (`resPnlMensuel`, une année par appel — mesuré : 22 mois d'un coup ne répondent pas pour trois magasins sur quatre —, gardé dans `pnlM:{shop}`, le mois en cours relu passée l'heure). Le mois en cours prend le cumul jour par jour à date (resCoutsPeriode) : le P&L mensuel y compte les frais du mois entier face au CA à date (Halle, 11/10 : 68,9 %). Le poste `material` y vaut 0 : le food cost vient de la heatmap. Recoupé à Halle avec le résumé quotidien additionné : septembre 9 477,50 € contre 9 458 € de personnel, 13 042,65 € de frais des deux côtés. Les jours vendus sans planning (relevé jour par jour gravé par le cron, lu sans appel) posent `sansPlanning` : le tableau affiche la main-d'œuvre du panel suivie d'un « ! » rouge, la bulle dit combien de jours manquent.

Les autres routes de coûts, mesurées par la sonde : `/costs` (les 9 types de frais), `/shops/{id}/costs?period=AAAA-MM` et `/shops/{id}/owner/costs?period=AAAA-MM` (les lignes de frais du mois : loyer, énergie, assurances… et leur total, 13 042,65 € à Halle en septembre). `/costs/{id}` répond 500 au panel (« CostRepository::getById() » absent de leur code).

**Sonde des coûts du panel (11/10/2026).** `GET /exploitation/couts/sonde?shop=4[&mois=AAAA-MM]` : le contrat Swagger des routes de coûts du panel (`/costs`, `/costs/{id}`, `/shops/{id}/costs`, `/shops/costs/{id}`, `/shops/{id}/owner/costs`, `/consultant/shops/{id}/pnl/monthly`, `/consultant/shops/pnl/monthly`, `/consultant/shops/pnl-summary`) et ce qu'elles rendent pour le magasin, en lecture seule. Ni mot de passe, ni jeton, ni coordonnées ; listes coupées à 15 éléments.

**Tickets et commandes (11/10/2026).** Chaque ticket de `GET /ventes/tickets` porte `refCommande` (une référence de commande sur le ticket) et `source` (la source de la liste du panel). `&commandes=1` lit en plus les commandes clients des quatorze jours d'avant (`/shops/{id}/client-orders?date_from=`, une lecture) et pose `commande` sur chaque ticket qui en encaisse une : `{canal: compt | cc | liv, retrait, passee}`, null sinon ; la réponse porte `commandesLues`.

**Tickets avec client (11/10/2026).** `&client=1` garde les tickets rattachés à un client du panel (`id_client` dans la liste), pro ou particulier, toutes leurs lignes. Chaque ticket porte `client`, `facture` (`will_be_invoiced`), `differe` (`deferral_payment`) et `pro` (`is_client_b2b`). Jamais le nom ni l'identifiant du client. Chaque ticket porte aussi `fiche` : la fiche du client relue au panel (`/shops/{id}/clients/{id}`, une lecture par client), réduite à `{lue, tva, valide, pays, pro, societe, compte}` (`vtFicheClient`) : un numéro de TVA est-il présent (au panel : `client.tax_number`), valide (BE : clé modulo 97 ; autre pays de l'UE : forme seulement, `valide` null), le préfixe pays, le drapeau pro de la fiche, un nom de société oui ou non, et le rang anonyme du client dans la réponse pour compter les clients. La réponse porte `champsFiche`, les noms des champs de la fiche, jamais leurs valeurs. Le drapeau pro suit la fiche du client au panel : mesuré le 10/10 sur 400 tickets, 6 tickets pro tous facturés, et 3 tickets facturés à un client particulier, comptés au comptoir.

**Promotions du panel reconnues dans les tickets (10/10/2026, maquette B3 · 3).** `GET /ventes/stats` renvoie `promos` :

- `regles[]` : chaque promotion déclenchée sur les jours lus. Champs : `{id, nom, type (buyxgety | bundle | remise), texte, statut, fois, tickets, q, v, r}`. Elles sont triées par ventes. L'id `x` regroupe les remises de ligne qu'aucune promotion n'explique.
- `autres[]` : les promotions qui valent pour le magasin mais n'ont rien donné.
- `produits` : `{clé produit : {id promotion : [pièces, ventes, remise, fois]}}`. La clé est celle de `categories[].produits[].id` (« pid » ou « pid:portion »).
- `tickets`, `v`, `q`, `r` : les totaux.
- `joursLus`, `joursSans` : les jours lus avant le relevé des remises sont sans promotions.
- `erreur` : le compte admin du panel est absent ou muet.

Les règles se lisent avec le compte ADMIN du panel (`ErpApi`). La source est `/admin/promotions/buy-x-get-y?limit=100`, puis le détail de chaque règle. Ce détail donne les produits et catégories qui déclenchent et qui reçoivent, plus le type et la valeur de la récompense. Pour un bundle, il donne les articles et leur prix remisé. Les règles sont gardées 10 minutes dans `pvRegles`. Le code est dans `src/promos_ventes.php`.

La lecture des tickets (`svP{shop}:{jour}`) garde `r`, la liste des tickets à remise de ligne, en entier : `[{t, mn, l: [[clé, pid, q, prix unitaire, remise, total]]}]`. Un relevé sans `r` se relit une fois pour aujourd'hui, ou pour un jour passé ouvert seul.

Une ligne remisée se rattache à la règle dont la récompense explique la remise au centime :

| Type de récompense | Remise par pièce |
|---|---|
| FIXED_PRICE | prix − valeur |
| FIXED_AMOUNT_DISCOUNT | valeur |
| FREE | prix |
| PERCENTAGE_DISCOUNT | prix × valeur ÷ 100 |

Quand deux règles expliquent la même remise, une règle ACTIVE passe avant une inactive, puis la priorité du panel départage. Les pièces qui déclenchent se prennent dans le même ticket : d'abord le reste de la ligne remisée, puis les lignes sans remise des produits ou catégories qui déclenchent.

À Halle le 10/10, sur 10 tickets :

| Promotion | Fois | Pièces | Ventes | Remise |
|---|---|---|---|---|
| 2 Cookies ! | 6 | 12 | 35,40 € | 1,80 € |
| 3 Cookie's ! | 3 | 9 | 23,70 € | 4,20 € |
| Brood | 1 | 2 | 23,40 € | 1,40 € |
| **Total** | **10** | | **82,50 €** | |

C'est 2,8 % du CA. Lecture seule.

Le dashboard affiche ces promotions à deux endroits :

- **Ventes par catégorie.** Un onglet « Promotions » à côté de Liste et Treemap, gardé dans `localStorage` (`db.cVue`). Il montre :
  - un bandeau : part du CA en promotion, remises, tickets avec une promotion, une pastille par promotion avec ses fois ;
  - l'arbre groupe › catégorie › produit, avec une ligne par promotion sous chaque produit. Seuls les groupes touchés y sont, du plus promu au moins promu. Le premier groupe et sa première catégorie sont ouverts. Les autres groupes tiennent sur une ligne « Sans promotion ».
- **Bundles vendus.** Le bloc « Les promotions » donne pour chaque promotion :
  - son poids dans le CA complet : ventes ÷ CA de la journée ;
  - son poids dans chaque catégorie touchée : ventes dans la catégorie ÷ CA de la catégorie.

  Les promotions actives pas déclenchées sont listées en bas. Sous 600 px, les lignes s'empilent.

La sonde `GET /exploitation/bundles/sonde?admin=1&detail=11-18` lit ce détail.

**Portions (10/10/2026, maquette A + la colonne « reste » de B).** `GET /ventes/stats` ajoute deux choses :

- sur chaque produit des catégories vendu en part (id « pid:portion ») : `portion: {lib, f}`. C'est le libellé lu dans le nom (« 1/4 ») et la fraction de la pièce entière (0,25), via `svPortionDuNom`. `f` vaut null quand le libellé ne dit pas la fraction ;
- `portions.prix` : `{pid : prix de la pièce entière}` au catalogue du magasin, pour les produits vendus en parts (`cataloguePrixMagasin`).

Le dashboard ajoute un onglet « Portions » à côté de Promotions dans Ventes par catégorie. On y voit seulement les catégories qui vendent en parts.

Le bandeau donne quatre chiffres :
- la part du CA vendue en portions ;
- le nombre de pièces entières vendues ;
- le CA par pièce entière ;
- les pièces encore en parts.

Le tableau va de la catégorie au produit, avec ces colonnes :
- une barre en pièces entières, une couleur par taille ;
- une colonne par taille présente : Entière, ½, ¼… ;
- « En entières » : Σ fraction × pièces ;
- « Reste » : la dernière pièce coupée moins les parts vendues. C'est une estimation : rien ne restait de la veille et rien n'a été recoupé ;
- le CA et le CA par pièce entière.

Le prix entier d'un produit vient des entières vendues, sinon du catalogue. Les produits vendus seulement entiers tiennent sur une ligne.

À Halle le 10/10 :

| Mesure | Valeur |
|---|---|
| CA tartes et quiches vendu en portions | 59 % |
| Pièces entières vendues | 30 ¼ |
| CA par pièce entière | 24,46 € |
| Pièces encore en parts | 7 ¾ |

**Téléphone : les vues réparties entre les onglets (10/10/2026).** Chaque onglet a son rôle, sans doublon :

| Onglet | Ce qu'il porte |
|---|---|
| Opérationnel | Le direct : l'objectif face à J−7, les quatre tuiles, le duel, l'heure par heure, la vitrine, la journée (équipe et cuissons), et deux renvois en bas. |
| Exploitation | Les chiffres de la journée : les tuiles, le P&L court, les ventes par catégorie (Liste, Treemap, Promotions, Portions), les bundles et les promotions. |
| Contrôle | Inchangé : les photos, les contrôles, les commandes, le stock, la poubelle, la réclamation et la note. |
| Semaine | Inchangé. |

Le P&L, les catégories et les bundles ont quitté Opérationnel. Les photos, les mini-cartes commandes et poubelle et le stock aussi : Contrôle les portait déjà.

Sous 560 px, rien ne défile de côté, sauf le carrousel des photos. Les tableaux gardent ces colonnes :

| Tableau | Colonnes |
|---|---|
| Liste | catégorie, CA, taux de marge |
| Promotions | produit, ventes en promo, part |
| Portions | produit, pièces entières, reste, CA |

Les autres réglages du téléphone :
- la grille horaire de la vitrine tient dans la ligne ;
- la date de l'en-tête est courte : « aujourd’hui · sam. 10 oct. ».

Vérifié à 390 et 360 px : aucune page ne défile de côté.

**Téléphone : une donnée, un seul onglet (11/10/2026).** Le passage du 10/10 laissait des KPI en double d'un onglet à l'autre. Chaque chiffre du jour ne se lit plus qu'à un endroit :

| Donnée | Où | Retiré de |
|---|---|---|
| CA face à l'objectif et à J−7 | Opérationnel, la jauge | duel d'Opérationnel, tuile CA d'Exploitation |
| Clients, panier face à J−7 | Opérationnel, le duel | tuile Clients d'Exploitation |
| Les heures | Opérationnel, la courbe | ventes au comptoir heure par heure d'Opérationnel, tuile Heure par heure d'Exploitation |
| Vitrine, équipe, cuissons | Opérationnel, leurs cartes | les quatre tuiles d'Opérationnel |
| Résultat, matière, main-d'œuvre, coût de la poubelle | Exploitation, le P&L court | tuiles Résultat, Matière, Main-d'œuvre, Invendus |
| Rang dans le réseau, canaux | Exploitation, deux tuiles | — |
| Déclaration de la poubelle, photos, contrôles, commandes, stock | Contrôle | — |
| CA jour par jour de la semaine | Semaine, les barres | la ligne « Chiffre / obj. » du damier |

Le verdict d'Exploitation se juge toujours sur toutes les mesures. Le bureau ne change pas : `opDuelTable(D, {sansCa})` n'est appelé sans le CA qu'au téléphone.

**Téléphone : La vitrine compacte (11/10/2026).** Dans l'onglet Opérationnel, la carte passe de 672 à 386 px à Halle :
- les onglets S, M et L tiennent en deux lignes, le niveau puis les alertes. La phrase et la valeur en euros passent dans l'infobulle, la jauge reste au bureau ;
- chaque section tient sur une ligne : nom, pièces en vitrine, à recuire, état ;
- la grille des heures ne s'affiche qu'à l'ouverture de la section.

Bureau et tablette inchangés : seuls la branche téléphone d'`opVitrine` et `opArbre` changent, avec du CSS sous 560 px.

**Téléphone : La journée compacte (11/10/2026).** Dans l'onglet Opérationnel, `opJourneeMob` remplace les lignes une à une par une frise de la journée. La carte passe de 302 à 195–210 px à Halle.

- **L'équipe :** une barre fine par personne au planning, le nom dedans :
  - vert : en poste ;
  - gris : a fini ;
  - cadre : arrive plus tard ;
  - bleu gris : un jour passé.
- **Le trait rouge :** l'heure actuelle.
- **Les cuissons :** un repère à l'heure du four sous la frise, puis une ligne de pastilles « 05:15 Matin 897 ». La couleur dit l'état :
  - vert : validée ;
  - orange : à valider ;
  - cadre : à venir.

  Seule la prochaine cuisson dit dans combien de temps elle part.
- **L'en-tête :** les heures au planning et le nombre de personnes en poste.
- **Le détail :** les heures exactes, les pièces et la plage de cuisson sont dans l'infobulle.

Le bureau et la tablette gardent leurs cartes.

**Contrôle des franchisés sur tablette (12/10/2026).** Page `controle/` : l'application Visites montée en mode tablette (`CockpitVisites.mount(…, { role: 'consultant', tablette: true })`), installable (manifest, service worker de portée `/controle/`). Aucune route nouvelle : la lecture reste `GET /visites/app`, les écritures restent celles de l'app Visites (`POST /visites`, `PUT /visites/{id}`, `PUT /visites/{id}/points`, `POST /visites/photos`, `POST /plans`), par la même file hors ligne. L'accueil n'a pas de barre de menu : les boutiques avec leur date de contrôle (en cours, prochaine visite planifiée, ou à planifier) et, par une bascule, l'agenda en calendrier du mois. Une boutique ouvre le contrôle : une étape à l'écran, les étapes du contrôle guidé dans la barre du bas (photo du jour, contrôles photo, chiffres, un module de checklist par étape, constat, plan d'action et fin de visite), chacune avec son état et ses écarts. Les points se notent en OK / Écart / N/A. Consultant : `?id=u8` une fois, gardé sur l'appareil. Correction au passage : l'étape « chiffres » est marquée lue quand on la quitte (elle l'était à la sortie de l'étape d'avant depuis l'ajout des contrôles photo).

**Météo du franchisé (12/10/2026).** La visite porte `meteo` (1 orage, 2 pluie, 3 nuageux, 4 éclaircies, 5 grand soleil ; `null` sans avis), colonne `ceo_visite.meteo` ajoutée à chaud. `GET /visites/app` la rend sur chaque visite, `PUT /visites/{id}` l'accepte (hors 1..5 : `null`). Elle se choisit à la dernière étape du contrôle, « Plan d'action et fin de visite », et part avec « Terminer ». Le résumé « Vu sur place » et la carte boutique de la tablette rappellent la dernière.

**Contrôles qualité du mois (12/10/2026).** `GET /controles/qualite?m=YYYY-MM[&shop=ID]` : chaque contrôle photo du mois, magasin par magasin et jour par jour, lu à l'API du panel (`/consultant/shops/{id}/tasks?date=`, une lecture par magasin et par jour, quatre de front ; tâches à photo exigée ou avec pièce jointe). Ligne : `shopId`, `shop`, `date`, `taskId`, `tache`, `checklist`, `checklistId`, `completionId`, `heure` (prévue), `fait` (heure faite), `photo` (booléen), `note` (1..5 du panel, `review_rating`), `commentaire` (`review_comment`), `noteLe`, `etat` = la note, sinon `a_noter` (photo sans note), `en_attente` (aujourd'hui sans photo) ou `non_rendue` (journée passée sans photo). Rendus aussi : `niveaux` et `seuil` du barème `signalement` (Exemplaire, Conforme, non conforme mineur, majeur, critique), `magasins`, `manque` (journées pas encore lues : l'écran relit) et `lus`. Rien de nominatif : ni qui a fait, ni qui a noté. Chaque journée est gardée (`cqmJ:{id}:{date}`) cinq minutes pour aujourd'hui, une demi-heure pour hier, six heures avant ; une note posée par `POST /pwa/tasks/review` efface la journée concernée. Écran cockpit « Contrôles qualité du mois » (Franchisés › Suivi journalier et opérations) : KPI, puces par niveau, tableau par magasin, liste des contrôles ; une ligne ouvre la photo en modale (`GET /pwa/tasks/detail`, URL fraîche) où l'on peut noter, motif obligatoire sous « Conforme ».

### `GET /marketing/hub` — le module Marketing, tous les canaux en une lecture (13/10/2026)

`GET /marketing/hub[?shop={id}]` (`src/marketing_hub.php`), lu par la page `marketing/`. Réseaux sociaux, emailing et
campagnes vivaient dans quatre sections du rail ; la lecture les pose côte à côte **sans rien recalculer à sa façon** :
campagnes = les lignes de `GET /marketing` (`mar_campaign`), emailing = `mar_nl_campagne` avec les statistiques de
`GET /newsletter` (`nlStatsReelles` d'abord), réseaux sociaux = `GET /facebook/posts`, charte = `bgStatsPeriode(30)` de
`/marketing/brand-guard/stats` plus les posts **sauvages** des 30 derniers jours (`mar_bg_controle.sauvage = 1`), qu'aucun
écran ne listait. Sans `shop` : la vue de la marque. Avec `shop` : les posts du magasin, ses newsletters (créées par lui ou
parties de son adresse), les campagnes RÉSEAU et celles qui le couvrent (`mar_campaign_shop`) — jamais un brouillon de la
marque —, sa ligne de la charte. `shop` vaut un id de boutique (`[\w-]{1,16}`) ; `brand` ou `reseau` reviennent à la marque.

Réponse : `{genere, aujourdhui, shop, magasin, magasins: [{id, nom, franchise}], campagnes, emailing, social, charte, aFaire}`.
Chaque canal porte `dispo` : **une table absente rend `{dispo: false, raison}` pour ce canal seul**, jamais un 500 qui
emporterait les autres.

- `campagnes` : `{liste: [{id, nom, typeId, type, typeCouleur, reseau, nBoutiques, statut, statutNom, statutTexte,
  statutFond, debut, fin, budget, depense, image, phase}], chiffres: {total, enCours, aVenir, terminees, brouillons, budget,
  depense}}`. `phase` se lit aux dates, pas au libellé du statut : `brouillon` (statut draft, cancelled, canceled ou archived,
  quelles que soient les dates), `a_venir`, `en_cours`, `terminee`, `sans_date`. Budget et dépense ne comptent pas les brouillons.
- `emailing` : `{liste: [{id, nom, objet, statut, canal, mode, declencheur, envoiLe, expediteur, testEnvoye, exemple, envoyes,
  ouvertures, clics, vouchers, reel}], role, test, smtp, chiffres: {total, exemples, programmees, automatisations, envoyeesMois,
  envoyes, ouvertures, clics, tauxOuverture, tauxClic, optin}}`. Les campagnes d'exemple (`exemple = 1`) restent dans la liste
  mais **sortent des chiffres** ; `exemples` dit combien. `optin` = contacts `mar_nl_contact` opt-in non désinscrits, `null`
  tant qu'aucun contact n'est importé.
- `social` : `{liste: [posts de GET /facebook/posts], parStatut, chiffres: {total, aTraiter, aPublier, publiesMois, refuses,
  noteMoyenne}, regles: {seuil, familles, niveaux, formats, marque, hashtag}}` — de quoi décider sans second appel (familles
  d'écart obligatoires sous le seuil ou en cas de refus) et pré-remplir un post avec la marque et le hashtag de la charte.
- `charte` : `{stats: {jours, controles, tauxConformite, scoreMoyen, sauvages, topRegles, boutiques}, sauvages: [{id,
  magasinId, boutique, statut, score, texte, message, lien, publieLe, quand, ecarts: [{libelle, gravite, correction}]}],
  charteVersion, meta}`.
- `aFaire` : `[{canal: campagne|email|social|charte|avis, niveau: urgent|normal, titre, detail, cible: {onglet:
  plan|creer|valider|publier|mesurer, post?, campagne?, vue?}}]` (l'onglet est l'étape du flux où se fait le geste), l'urgent d'abord, 40 au plus. Post à valider (marque seulement ; urgent s'il sort sous 48 h), post à contrôler,
  post validé dont la date est passée sans être marqué publié (urgent : sans son id Facebook, Brand Guard le prendra pour un
  sauvage), post refusé depuis moins de 14 jours (vue magasin), posts sauvages, newsletter programmée sous 7 jours sans envoi
  test, newsletters programmées en mode test, campagne encore en brouillon qui démarre sous 14 jours. Et la seule règle qui lit
  les trois canaux : **une campagne en cours, ou qui démarre sous 7 jours, sans aucun post (hors refusés) ni aucune newsletter
  datés dans sa période**. Depuis le 13/10/2026 un post (`mar_social_post.campagne_id`) et une newsletter
(`mar_nl_campagne.campagne_id`) se rattachent à leur campagne : le rattachement compte d'abord, les dates seulement pour
un contenu rattaché à rien. Et deux règles de la publication : **publication en échec** (urgent) et post validé dont
l'heure est passée sans aucune publication.

**Le flux (13/10/2026).** Chaque post porte aussi `reseaux` (`["facebook","instagram","linkedin"]`), `campagneId` et
`publications: [{id, reseau, statut: programme|en_cours|publie|erreur|annule, prevuLe, publieLe, lien, externeId, erreur,
tentatives}]` ; `social.publication` vaut `{reelle, auto, reseaux: {facebook, instagram, linkedin}}` (accès configuré
ou non, jamais un secret) ; chaque newsletter porte `campagneId`. `POST /marketing/newsletter/campagnes` accepte
`campagne` (l'id de la campagne de rattachement).

Écritures : la page appelle les routes de chaque canal, inchangées (`POST /facebook/posts`, `PATCH /facebook/posts/{id}`,
`PATCH …/ecarts/{id}`, et la newsletter qui écrit elle-même). Une seule extension : **`POST /facebook/posts/{id}/controle`
accepte `{soumettre: true}`**, qui fait sortir un brouillon (statut `a_controler`, `submitted_at` à maintenant, ligne de
journal) avant le contrôle — sans elle, un post enregistré en brouillon ne pouvait plus jamais être soumis.

**La page `marketing/`** — état au 13/10/2026 : un rail à gauche, comme celui du cockpit (la vue réseau ou magasin, les
étapes et leurs compteurs, les sous-vues de l'étape ouverte, les paramètres, les liens ; un tiroir sous 900 px), un
tableau de bord (la chaîne des étapes, « À faire », les 14 prochains
jours) puis cinq étapes, **1 · Planifier** (campagnes et leurs contenus, calendrier), **2 · Créer** (post : campagne,
réseaux, visuel téléversé, aperçu, prêt ? par réseau ; newsletter), **3 · Valider** (contrôle, décision, refusés),
**4 · Publier** (réseau par réseau, programmé, publiés maintenant, annulés ; tout ce qui est programmé) et
**5 · Mesurer** (résultats par canal et par campagne, avis Google, posts sauvages), et les paramètres (publication, API
et tables, agent, charte, pages, avis). Adresse : `marketing/?shop=&onglet=plan|creer|valider|publier|mesurer|parametres
&vue=calendrier|newsletter|avis|sauvages&mois=AAAA-MM&p=publication|sources|agent|charte|pages|avis` ; les anciens
`onglet=campagnes|calendrier|social|email` mènent à l'étape correspondante. *Première version, pour mémoire* : six onglets — vue d'ensemble (un chiffre par
canal, à faire, les 14 prochains jours), calendrier (campagnes en bandes, newsletters et posts au jour, filtre par canal ;
une liste au téléphone), campagnes (avec leurs prises de parole, « Préparer un post », l'assistant de campagne), réseaux sociaux
(rédaction avec aperçu, contrôle, décision, publication avec l'id Facebook, sauvages, la charte sur 30 jours), emailing (le
module `newsletter.js` monté tel quel, rôle `brand` ou le magasin), paramètres (règles de l'agent, charte, pages, cible des
avis, API et tables). Adresse : `marketing/?shop=&onglet=vue|calendrier|campagnes|social|email|parametres&mois=AAAA-MM
&vue=a_traiter|a_publier|publies|refuses|brouillons|sauvages|tous|avis&p=sources|agent|charte|pages|avis`. Comme la newsletter,
le rôle n'est pas vérifié côté serveur : la vue magasin est une vue, pas un droit.

### `GET /marketing/sources` — les API et les tables du module Marketing (13/10/2026)

`GET /marketing/sources` (`src/marketing_sources.php`), lu par l'onglet Paramètres → « API et tables » de `marketing/`.
Comme la carte des sources, **lu dans le code** : seule la liste des routes que la page appelle est déclarée
(`MS_ROUTES` — 34 routes au 13/10/2026, la page et le module newsletter qu'elle monte ; `bin/tests.php` vérifie que chaque fonction
existe et que le routeur l'appelle). Chaque route est suivie de fonction en fonction (6 niveaux au plus, sans
l'installateur), et l'on relève les tables nommées dans le SQL (`FROM`, `JOIN` ⇒ lue ; `INSERT`, `UPDATE`, `DELETE`
⇒ écrite ; `CREATE`, `ALTER` ⇒ créée ; `ON DUPLICATE KEY UPDATE col` n'est pas une table) et les API **réellement
appelées** (`PanelApi::…`, `ErpApi::…` = TFBuddy ; `MetaGraph::http|posts|resoudrePage…`, `GoogleApi::…`,
`Anthropic::…`, `Smtp::envoyer` ; un `statut()` ou `configured()` ne sort pas du serveur et ne compte pas).
`wr_param_put`, qui sert tous les réglages, est déclaré à part : pour nos deux clés il n'écrit que `ceo_app_setting`.

Réponse : `{genere, base, baseLue, apis, routes, tables, liees, limites}`.
- `apis` : `[{id: base|panel|erp|meta|google|anthropic|smtp, nom, famille: locale|tfbuddy|externe, hote, quoi,
  configure, routes: ["MÉTHODE /chemin"]}]` — l'état vient des `statut()` des clients, jamais un secret.
- `routes` : `[{methode, chemin, fonction, fichier, quoi, existe, fonctions, apis, tfbuddy, locale, tables: [{nom,
  mode: lecture|ecriture|creation}]}]`. `tfbuddy` : la route appelle le panel ou l'ERP ; `locale` : rien ne sort du
  serveur (base et calcul).
- `tables` : `[{nom, origine: portail|tfbuddy|module|cockpit, origineNom, ecrite, routes, existe, lignes}]`. Origine :
  une table du registre `MAR_TABLES` = portail ; un autre `mar_*` = module marketing autonome (base partagée) ; une table créée par `src/` ou `sql/schema.sql` = cockpit ; le reste =
  base partagée TFBuddy (`shops`…). `existe` et `lignes` (estimées par InnoDB) viennent d'`information_schema`,
  `null` si la base ne répond pas.
- `registre` : `[{nom, famille, quoi, ancien}]` — les tables du portail et leur nom d'avant le 13/10/2026.
- `liees` : l'assistant de campagne (`/api/v1/marketing`, copie de l'API du module autonome, catalogue repris de
  l'ERP TFBuddy) et les écrans du cockpit.

**Avis Google dans `/marketing/hub` (13/10/2026).** Nouveau canal `avis` : `{cible, reseau, magasins, connecteur:
{configure, raccordes, derniereSynchro, etat, erreur}}`, c'est `GET /reputation` tel quel (en vue magasin, sa seule
ligne ; le réseau reste en comparaison). `aFaire` y ajoute `canal: avis` : fiche Google à raccorder, note sous la
cible (avec le nombre d'avis 5★ à obtenir), avis non relus depuis plus de 7 jours (connecteur configuré seulement).

**Règles de l'agent : les paramètres ne se perdent plus (13/10/2026).** `PUT /parametres/fbControle` reçoit le pack
tel que `/referentiels/facebook-regles` le sert, c'est-à-dire **sans** `params` : activer une règle enregistrait des
règles sans mots, sans domaines, sans seuils — « Conditions de promotion » ne relevait plus rien. `fbRegles()` reprend
désormais, règle par règle, les paramètres absents du défaut livré (`fbReglesDefaut()`) ; ceux qu'on a posés restent.
Un réglage déjà abîmé est réparé à la lecture.

### Tables du portail Marketing : un seul préfixe, `mar_` (13/10/2026)

Tout ce que le portail possède passe sous `mar_`, rangé par famille (`src/marketing_tables.php`, `MAR_TABLES`). Les
tables du module marketing autonome (`mar_campaign`, `mar_campaign_type`… en anglais) ne changent pas.

| Nouveau nom | Ancien nom |
|---|---|
| `mar_social_post` | `ceo_fb_post` |
| `mar_social_ecart` | `ceo_fb_finding` |
| `mar_social_publication` | — (nouvelle : une publication par post et par réseau) |
| `mar_bg_controle` / `mar_bg_page` / `mar_bg_regle` | `brand_guard_checks` / `brand_guard_pages` / `brand_guard_rules` |
| `mar_nl_magasin`, `_source`, `_segment`, `_campagne`, `_contact`, `_envoi`, `_voucher` | `ceo_nl_…` (même suffixe) |
| `mar_avis_fiche` / `mar_avis` | `ceo_shop_reputation` / `ceo_shop_review` |
| `mar_campagne_annexe` / `mar_campagne_annexe_type` | `ceo_mkt_annexe` / `ceo_mkt_annexe_type` |
| `mar_campagne_mesure`, `_releve`, `_snapshot`, `_objectif`, `_objectif_produit`, `_produit` | `ceo_campagne_…` (même suffixe) |

**Migration** : `marMigrer()`, appelée par `ensureInstalled()` avant toute création de table, au premier appel qui
suit le déploiement. Ancienne présente et nouvelle absente : renommée. Nouvelle présente mais vide (recréée trop tôt) et
ancienne pleine : la vide disparaît, l'ancienne est renommée. Les deux pleines : rien, une ligne de journal le dit.
Tous les renommages partent en **une seule** instruction `RENAME TABLE` (atomique ; données, index et clés étrangères
suivent). Une panne n'empêche pas le cockpit de répondre : l'empreinte du schéma n'est pas posée, l'appel suivant
réessaie. Une installation neuve crée directement les nouveaux noms.

**Retour arrière** (redéployer l'ancienne version ne suffit pas : elle recréerait des tables vides) — avant de
redéployer, dans la base :

```sql
RENAME TABLE
  mar_social_post TO ceo_fb_post, mar_social_ecart TO ceo_fb_finding,
  mar_bg_controle TO brand_guard_checks, mar_bg_page TO brand_guard_pages, mar_bg_regle TO brand_guard_rules,
  mar_nl_magasin TO ceo_nl_magasin, mar_nl_source TO ceo_nl_source, mar_nl_segment TO ceo_nl_segment,
  mar_nl_campagne TO ceo_nl_campagne, mar_nl_contact TO ceo_nl_contact, mar_nl_envoi TO ceo_nl_envoi,
  mar_nl_voucher TO ceo_nl_voucher,
  mar_avis_fiche TO ceo_shop_reputation, mar_avis TO ceo_shop_review,
  mar_campagne_annexe TO ceo_mkt_annexe, mar_campagne_annexe_type TO ceo_mkt_annexe_type,
  mar_campagne_mesure TO ceo_campagne_mesure, mar_campagne_releve TO ceo_campagne_releve,
  mar_campagne_snapshot TO ceo_campagne_snapshot, mar_campagne_objectif TO ceo_campagne_objectif,
  mar_campagne_objectif_produit TO ceo_campagne_objectif_produit, mar_campagne_produit TO ceo_campagne_produit;
```

(Retirer de la liste une table absente de la base : `RENAME TABLE` échoue en entier sinon. `mar_social_publication`
et les colonnes `reseaux` / `campagne_id` peuvent rester : l'ancienne version les ignore.)

**Les adresses** : les API du portail vivent sous `/api/cockpit/marketing/…` — `/marketing/social/posts…`,
`/marketing/social/regles`, `/marketing/newsletter…`, `/marketing/avis…`, `/marketing/publication…`, à côté de
`/marketing/brand-guard…` et `/marketing/hub|sources`. Les anciennes restent servies, réécrites avant le routage
(`MAR_ALIAS`, `marCheminCanonique()`) : `/facebook/posts` → `/marketing/social/posts`, `/referentiels/facebook-regles`
→ `/marketing/social/regles`, `/newsletter` → `/marketing/newsletter` (les mails déjà partis portent
`/newsletter/o|c|u/{token}`), `/reputation` → `/marketing/avis`. Deux écritures de réglage ont leur adresse :
`PUT /marketing/social/regles` (= `PUT /parametres/fbControle`) et `PUT /marketing/avis/cible`
(= `PUT /parametres/reputationCible`).

### Publication Facebook, Instagram, LinkedIn (13/10/2026)

`src/social_publication.php`, avec `MetaGraph::publierPage|publierInstagram|instagramDePage` et `LinkedInApi`. Mise en
service, droits et jetons : `docs/publication-reseaux.md`.

- `POST /marketing/social/posts/{id}/publier` `{quand: maintenant|programme, reseaux?}` → `{ok, publications}`.
  409 si la publication réelle est éteinte ou si le post n'est pas validé. Contrôle préalable par réseau
  (`spPreparer`) : un réseau qui ne passe pas est annulé avec sa raison et les autres partent ; 422 si aucun ne
  passe, ou si un réseau demandé nommément (`reseaux`) ne passe pas — rien n'est parti.
- `DELETE /marketing/social/posts/{id}/publier` : annule ce qui est programmé ou en échec.
- `GET /marketing/publication/cron?jeton=` (`socialJeton`) : publie ce qui est dû, retente une erreur après 15 minutes
  (3 essais), 20 par passage. Ne fait rien si la publication réelle est éteinte. Appelé par `bin/social_cron.sh`.
- `GET /marketing/publication/etat` : `{reglages: {reelle, auto, pageMarque, linkedinOrganisation, linkedinVersion},
  meta, linkedin, pages, reseaux, compteurs, cronDernier, base}` — l'existence des jetons, jamais leur valeur.
- `PUT /marketing/publication/reglages` : 422 si `reelle` sans aucun accès configuré.
- `POST /marketing/publication/tester` `{reseau}` et `POST /marketing/publication/media` `{data, nom?}` (JPEG, PNG ou
  WebP, 8 Mo ; rendu `{url, chemin, largeur, hauteur, poids, avertissements}`).
- Écritures existantes : `POST /marketing/social/posts` accepte `reseaux` et `campagneId` ; une **validation**
  (`PATCH …/{id}` à `valide`) programme le post si « Programmer à la validation » est coché ; « marqué publié » à la
  main annule ce qui était programmé.

**22/10/2026 — Objectifs de CA et Suivi budget « à date ».** Le réel du mois en cours s'arrête à aujourd'hui (`/stores/perf`, API du panel) ; sa cible ne compte plus que les jours écoulés (`partMois` : jour / jours du mois, 1 pour un mois clos). Les colonnes disent « Réel au 22/10 » (ou « Réel à fin septembre » quand le dernier mois lu est clos) au lieu de « fin juil. » écrit en dur. Projection : le reste de l'année part des jours restants du mois en cours. Suivi budget : cumul, écart par magasin et écart du mois en cours sur la même règle ; plus de juillet figé (`slice(0, 7)`, `i <= 6`). `/meta.aujourdhui` ne recule plus sous la date du serveur : la base de démonstration posait `2026-07-31`.

**22/10/2026 — `/stores/perf` manqué au chargement.** Chaque lecture du chargement a 20 s ; au-delà (ou sur une erreur), `perf` valait `null` et les écrans de l'année (Objectifs, Marge, Mois) affichaient des zéros partout, budgets compris, sans le dire. `perfAssure()` relit alors `/stores/perf` sans délai imposé, une fois ; l'onglet Année tourne pendant la lecture, et l'écran Objectifs affiche « lecture en cours », ou « pas pu être lus » / « aucun chiffre » avec un bouton Relire.

**23/10/2026 — Promotions : les nouveaux paramètres du panel, la sonde des champs.** Demande de Sam : adapter l'assistant des promotions du dashboard magasin aux nouveaux paramètres de l'API (seuil de ticket déclencheur, webshop ou comptoir…). La documentation publique (`api.php`, relue le 23/10/2026) est identique à celle du 21/10 (même empreinte) : ni seuil de ticket ni canal n'y figurent, sur aucun des quatre types (`buy-x-get-y`, `scheduled-product-discount`, `quantity`, `bundles`) ; `pvNormaliser` ne garde que les champs connus. Pour voir ce que le panel rend vraiment : `GET /ventes/promos/sonde-champs[?shop=4]` (lecture seule) relit les six promotions les plus récentes de chaque type (et celles du magasin), aplatit leurs champs (« trigger.product_ids[].id_product », type et exemple de valeur) et rend `nouveaux` (ni documentés ni lus par le cockpit, `PM_CHAMPS_CONNUS`) et `conditions` (ce qui ressemble à un seuil, un montant ou un canal), plus la liste générale `/admin/promotions` si elle existe. Rien de nominatif (`pmSondePropre`).

**23/10/2026 — Promotions : maquette de l'étape « Conditions du ticket » (à valider).** Dans l'assistant du dashboard magasin, derrière `&conditions=1` dans l'adresse (sans lui, rien ne change : cinq étapes, ni canal ni seuil envoyés) : une étape 4 avec le canal (`canal` : `tous` | `comptoir` | `webshop`), le seuil du ticket (`seuil`, € TTC avant remise, null = sans seuil) et la règle du ticket (répétable, nombre maximal, venue de la Période) ; leurs étiquettes dans le récapitulatif et la liste ; le ticket d'exemple dit ce qu'il manque au lot pour atteindre le seuil. Le serveur ne les écrit pas encore au panel : leurs noms dans l'API restent à lire avec `sonde-champs`.

**23/10/2026 — Promotions : la liste en tableau, une information par colonne (Sam : proposition B, avec les colonnes Comptoir et Webshop de C).** Colonnes par défaut : Promotion, Origine (magasin / marque), Le client achète, Il reçoit, Comptoir, Webshop, Seuil, Début, Fin, Statut, Coef. ; « Colonnes » ajoute Sorte, Créneaux, Par ticket, N° au panel (le choix est gardé dans le navigateur, `db.pmCols`) ; les actions (Reprendre, Modifier, Dupliquer, Supprimer le brouillon) passent dans le menu « ⋯ » de la ligne. Le canal et le seuil se lisent au panel par `pvConditions` sous leurs noms plausibles (seuil : `minimum_basket_value`, `min_order_amount`, `min_ticket_amount`, `threshold_amount`… ; canal : `sales_channels`/`channels` en liste, `is_pos`/`is_webshop`/`webshop_only` en booléens) — `canaux` null quand rien ne restreint la promotion (comptoir et webshop cochés), `seuil` null sans seuil ; les noms réels restent à confirmer par `sonde-champs`. La liste reçoit aussi le lot d'un bundle (`bundle.items`, `bundle.prix`), les créneaux et la règle du ticket des promotions de la marque.

**23/10/2026 — Promotions : l'étape « Conditions du ticket » en ligne (maquette validée par Sam).** L'assistant passe à six étapes pour tous (plus d'interrupteur `&conditions=1`). Le corps envoyé porte `canal` (`tous` | `comptoir` | `webshop`) et `seuil` (€ TTC avant remise, null = sans seuil, 1 000 € au plus) ; `pmPropre` les garde. Au panel (`pmConditionsCorps`) : sous les noms relus sur une promotion du panel qui en porte (`pmChampsAppris`, depuis `pvConditions` : la clé du seuil, la clé du canal et ses jetons, « POS », « WEBSHOP »…) ; tant qu'aucune n'en porte, sous les noms plausibles (`PM_CLES_SEUIL` : `min_order_amount`, `minimum_order_value`, `min_ticket_amount` ; `PM_CLES_CANAL` : `sales_channels`, `channels`, jetons `POS` / `WEBSHOP`). Rien n'est ajouté pour une promotion sans conditions, sauf pour effacer celles qu'elle avait. La relecture compare (`pmConditionsEcarts`) : si le panel ne reprend pas le seuil ou le canal, la promotion reste inactive au panel (un brouillon) et l'écart se dit — elle ne passe jamais en caisse sans ses conditions.

**23/10/2026 — Promotions : plus de limite à deux en même temps ; le prix final à l'étape Y.** Demande de Sam : la règle « 2 promotions du magasin en même temps au plus » est retirée (`PM_EN_MEME_TEMPS`, `regles.enMemeTemps` et le contrôle de `pmErreurs` supprimés) ; l'étape Période dit seulement, pour information, quelles autres promotions du magasin sont en caisse sur ces dates. À l'étape Y (ce que le client reçoit), l'encadré « Prix final pour le client » donne, produit par produit, le prix normal TTC, le prix final avec l'avantage et la remise, puis le lot du cas le plus serré (le même que « Sur le ticket ») ; il se recalcule à chaque frappe, et « ↻ Recalculer » relit les prix du magasin (`/exploitation/promos-magasin/produits`). Corrigé au passage : la durée de l'étape Période (devenue l'étape 5) se met de nouveau à jour pendant la saisie des dates.

**23/10/2026 — Promotions : la valeur Y depuis un prix final, et les moyennes d'une catégorie.** Demande de Sam : à l'étape Y, « Ou depuis le prix final » — le prix TTC du lot entier (X + Y) ou d'une pièce Y ; la valeur se calcule pour le type choisi (prix fixe = le prix de la pièce Y ; remise € = prix normal − ce prix ; remise % arrondie au %), « Offert » passe en prix fixe sauf pour un prix nul ; changer de type garde le prix final et recalcule la valeur ; une valeur saisie à la main efface le prix final. Pour une catégorie (ou plusieurs produits), le calcul prend la moyenne (`pmMoyenne`) : prix de vente moyen et coût d'achat moyen des produits visés qui ont un prix (et un coût), à la fraction de la portion. L'encadré « Prix final » ajoute la ligne Moyenne et le lot moyen avec son coefficient sur la moyenne (prix payé HT moyen ÷ coût d'achat moyen) ; le coefficient du cas le plus serré reste celui qui s'affiche partout ailleurs. Rien ne change côté serveur : la valeur calculée part comme une valeur saisie.

**23/10/2026 — Promotions : « Tout le temps », plus de durée maximale.** Demande de Sam : un bouton « Tout le temps » dans les durées rapides de l'étape Période (et dans « Remplir un exemple ») pose le début 99 ans en arrière (00:00) et la fin 99 ans devant (23:59). La règle « un an au plus » (`PM_JOURS_MAX`, `regles.joursMax`) est retirée : seule la date de fin reste obligatoire (« Donnez la date de fin »), et le bouton la remplit. Une période « tout le temps » se dit ainsi partout (récapitulatif, Vérifier, message d'enregistrement) ; dans la liste, un début à plus de 90 ans en arrière s'affiche « toujours », une fin à plus de 90 ans devant « sans fin ». Au panel, ces dates partent telles quelles ; si le panel ne les garde pas, la relecture le dit (début, fin) et la promotion reste inactive.

**23/10/2026 — Promotions : « Tout le temps » raccourci.** Le panel refuse « depuis 99 ans, pour 99 ans » (1927 → 2125). « Tout le temps » pose désormais le début aujourd'hui à 00:00 et la fin au 31/12/2037 à 23:59 (`PM_TOUJOURS_AU`) : les dates du panel s'arrêtent au début de 2038, comme un TIMESTAMP — c'est la fin la plus lointaine sûre. Une fin au 31/12/2037 ou après s'affiche « sans fin » (liste, récapitulatif, Vérifier, message d'enregistrement).

**23/10/2026 — Dashboard Opérationnel : la jauge du comptoir seul, la jauge du B2B dessous (maquette A, Sam) ; Heure par heure séparé ou combiné.** Le B2B (tickets `is_client_b2b`) faussait la jauge du jour. `/exploitation/jour` ajoute, par magasin : `heuresPro` [{h, ca, n}] (le pro d'aujourd'hui heure par heure, `exHeuresPro`), `partProHabituelle` / `partProJours` (le CA pro sur le CA des quatre derniers mêmes jours dont le relevé est gravé, `exPartProHabituelle`, sans appel au panel) ; `j7` ajoute `caPro` (au même moment), `caProJour`, `ticketsPro`, `ticketsProJour` et `heures[].pro` / `proN` (le relevé pro gravé de J−7, null s'il n'est pas lu). Le dashboard (`opDuelSplit`) : la jauge du haut compte le comptoir seul (CA − CA pro, J−7 de même), face à l'objectif du jour moins la part B2B habituelle ; la projection suit le comptoir. Dessous, la jauge du B2B (`opDuelPro`) : aujourd'hui et J−7 au même moment (la journée de J−7 en hachuré), à sa propre échelle, face à sa part de l'objectif (le trait) ; absente pour un magasin sans B2B. Le B2B pas encore lu : une seule jauge, comptoir et pro ensemble, comme avant. Heure par heure : « Comptoir et pro séparés » (le comptoir et le pro d'aujourd'hui, en pointillé ceux de J−7 ; le pro en paliers ; l'objectif comptoir et la part B2B habituelle) ou « Combinés » (une ligne par journée, comme avant) ; le choix est gardé (`db.opPro`) ; sans B2B, pas de bascule. Le tableau chiffre par chiffre reste sur le total.

**23/10/2026 — Promotions : plus de noms de champs devinés pour le seuil et le canal (bogue signalé par Sam).** Le panel ignorait les noms plausibles (`min_order_amount`, `minimum_order_value`, `min_ticket_amount`, `sales_channels`, `channels`) : à l'enregistrement, la promotion restait inactive (brouillon, « le panel ne reprend pas le seuil »), elle se rouvrait sans seuil ni canal (le panel ne les avait pas gardés), et l'enregistrer de nouveau la passait en caisse sans eux. `PM_CLES_SEUIL` et `PM_CLES_CANAL` sont retirés : `pmConditionsCorps` n'écrit le seuil et le canal que sous les noms relus sur une promotion du panel qui en porte (`pmChampsAppris`). Tant qu'aucune n'en porte, une demande avec un seuil ou un canal restreint est refusée (422, `pmConditionsInconnues`) avant toute écriture au panel ; « comptoir et webshop, sans seuil » passe comme avant. `GET /exploitation/promos-magasin` rend `regles.conditions` {seuil, canal} (booléens : le nom est connu). L'assistant grise « Comptoir seulement », « Webshop seulement » et « À partir d'un montant » quand le nom manque, avec l'avertissement « Pas encore disponible… créez-en une au panel avec un seuil et un canal » ; ils s'activent d'eux-mêmes dès qu'une promotion du panel en porte. Pour lire les noms réels : `GET /ventes/promos/sonde-champs?shop=4`.

**23/10/2026 — Dashboard Opérationnel : le P&L court sous le duel, plus de bundles.** Demande de Sam : « Le P&L court de la journée » passe juste sous « Le duel, chiffre par chiffre » ; « Ventes par catégorie » prend ensuite toute la largeur. La carte « Bundles vendus » (ses quatre tuiles Vendus, CA bundles, Part du CA, Marge brute, son tableau et la liste des promotions en dessous) est retirée, au bureau comme au téléphone (onglet Exploitation) : double emploi avec l'onglet Promotions des ventes par catégorie. `bundlesCarte`, `bundlesDe` et `promoBundles` sont supprimés ; `/ventes/stats` rend toujours `bundles`, et un bundle rangé dans une catégorie garde son étiquette dans la liste.

**23/10/2026 — Production : la page « Validation et suivi » retirée, et « Production ↗ » du rail.** Demande de Sam. L'application `production/` garde quatre onglets : Paramètres, Plan de production, Clôture, Fours ; sans `?page=` (ou avec l'ancien `?page=suivi`), elle s'ouvre sur le Plan. Partent avec la page : la validation des cuissons à la sortie du four (`POST /production/flux/valider` n'a plus d'écran) et le réglage de la durée de vie S · M · L par catégorie et par produit (`POST /production/flux/vie`, idem) ; les réglages déjà posés restent. La lecture `GET /production/flux/suivi` reste : la vitrine du dashboard magasin la lit. Le rail de la console perd « Production ↗ » (qui s'ouvrait sur cette page) ; l'ancienne adresse d'écran `production-suivi` mène au Plan. Le dashboard magasin perd les liens « suivi de production › » de la vitrine.

**23/10/2026 — Dashboard Opérationnel : les quatre vignettes du bas retirées.** Demande de Sam : « Commandes clients » (en retard, à venir, demain), « Hier » (non-conformités, photos notées), « Poubelle » et « Promotions » ne s'affichent plus sous les contrôles en photo (`opMinis` et les styles `.op-mini*` supprimés). Les lectures restent (les commandes nourrissent la frise de La journée ; la poubelle, le P&L).

**23/10/2026 — Console : la page Marketing (« Aujourd'hui », `#/marketing`) et son entrée du rail retirées.** Demande de Sam. Partent le hub (ses portes, ses chiffres par canal, sa liste « à faire »), sa lecture de `GET /marketing/hub` côté console (`mhCharge`, `valsMktHub`, `tplMktHub`) et l'adresse `#/marketing` (elle ouvre l'accueil). « Marketing ↗ », l'application /marketing/, reste dans le rail quand elle y est. Les onglets de campagne de la console (Campagnes, Budget × Campagnes, Objectifs produits, Mesure) restent à leur adresse (`#/campagnes`…), sous le titre « Campagnes », sans entrée dans le rail. L'API `/marketing/hub` reste.

**23/10/2026 — Console : les onglets de campagne retirés.** Demande de Sam (« garde Marketing ↗, retire les onglets de campagne »). Partent de la console : Campagnes (liste et calendrier, la note de campagne, le formulaire, `#/campagnes`, `#/calendrier`), Budget × Campagnes (`#/budget-campagnes`) et Mesure (`#/mesure-campagnes`) — leurs écrans, leurs lectures côté console et leurs gabarits ; ces adresses ouvrent l'accueil. Restent : « Marketing ↗ » (l'application /marketing/) dans le rail ; Objectifs produits (`#/objectifs-produits`), qui garde son entrée « Objectifs » du rail (Franchisés › Suivi journalier et opérations) et le lien de la fiche franchisé ; Types de campagne dans Paramètres. Les API `/marketing…` côté serveur restent, comme l'assistant de campagne (/assistant/).

**23/10/2026 — Rail de la console : le menu « Application employé ».** Demande de Sam : le rail est le bac à sable du dev, qui exportera d'ici les trois pièces de l'application employé vers l'intégrateur de l'ERP. Le menu « Application employé » réunit la **page admin** (`primesApp`, section marque : les primes de la marque et des sponsors, les promotions, les moyennes), la **page franchisé (magasin)** (`primesApp`, section franchisé : les primes que chaque franchisé pose pour son magasin) et **Application ↗** (`employee/auth`, la connexion de l'app : horaire, tâches, primes, profil). Il remplace « App worker ↗ » et le sous-menu « Primes · module app worker » d'ERP franchisé (qui garde Prospection ↗ et Tablette vendeuses) ; le lien direct « Mes primes dans l'app ↗ » se fond dans « Application ↗ » (les primes sont un onglet de l'app). Une entrée de premier niveau du rail peut désormais poser un état en plus de l'écran (`c[4]`), comme un enfant de sous-menu.
