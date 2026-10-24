# Fidélité — quatre maquettes

> **Retenu** (24/10/2026) : le Programme de C, les Clients de B. **Codé** le même jour : serveur
> `src/fidelite.php` (`GET /fidelite`, `GET /fidelite/clients`, `POST /fidelite/regles` — voir
> `docs/contrat-api.md`), écran `public/assets/js/fidelite-vals.js` + `fidelite-tpl.js` +
> `assets/css/fidelite.css`, rail **ERP franchisé › Fidélité** (`#/fidelity`). Les règles s'enregistrent
> pour de vrai ; l'analyse des clients tourne sur un jeu d'essai tant que `ceo_fid_visite` est vide.

Demande du 24/10/2026 : un module **fidélité** dans l'ERP, page `/fidelity` —
les cartes de fidélité, les points, les bons et les cadeaux du client, ce qui
les déclenche (points → bon, événements), les jours et heures où les points
comptent double — et un onglet d'**analyse complète de la récurrence des
clients et de leur type d'achat**. Quatre façons de faire la page, à trancher.

Chaque maquette est une page à deux onglets, rangée dans le cockpit sous
**ERP franchisé › Fidélité** :

- **Programme** : la règle de base (1 € = 1 point, 150 points = un bon de 5 €
  valable 60 jours), les cartes à tampons (pain, viennoiserie, café, tarte),
  les bons et cadeaux du mois, les déclencheurs, les créneaux ×2 / ×3 ;
- **Clients · récurrence et type d'achat** : fréquence de visite, cohortes,
  segments (habitués, réguliers, occasionnels, nouveaux, à risque, endormis),
  rythme entre deux visites, et six types d'achat (pain quotidien,
  petit-déjeuner, lunch, goûter, occasion, commande & bureau).

| | Maquette | Fichiers |
|---|---|---|
| A | **Tout sur une page** — six tuiles, puis une carte par brique : la jauge et les soldes de points, les bons et cadeaux du mois, la semaine heure par heure avec les créneaux ×2, les cartes à tampons, les déclencheurs, le détail par magasin. Clients : fréquence (part des clients face à la part du CA), cohortes, segments avec le déclencheur lié, types d'achat | `a-programme.jpg`, `a-clients.jpg` |
| B | **Le parcours client** — inscrit → 1re visite → 2e visite → régulier → habitué, puis à risque → endormi ; sous chaque étape, le déclencheur qui pousse à la suivante et ce qu'il a donné. Clients : la matrice récence × fréquence, le détail du segment choisi (à risque), qui monte et qui descend d'un mois à l'autre, ce qu'achète chaque segment | `b-programme.jpg`, `b-clients.jpg` |
| C | **Les règles en phrases** — chaque règle s'écrit comme une phrase aux valeurs modifiables, en quatre blocs (on gagne, ça compte double, on reçoit, garde-fous) ; à droite l'app du client telle que les règles la montrent, un simulateur sur trois clients types, le budget. Clients : le type d'achat heure par heure, une / deux / trois missions, les passages d'une mission à l'autre, la liste des clients | `c-programme.jpg`, `c-clients.jpg` |
| D | **Le calendrier d'abord** — la semaine heure par heure en grand (trafic en fond, ×2 par-dessus, effet mesuré membres / non-membres), les trois mois à venir (gammes, temps forts, jours sans ×2) ; jauge, cartes et déclencheurs en compact. Clients : jours entre deux visites, rétention par cohorte, retour spontané d'un régulier selon les jours d'absence, la semaine type | `d-programme.jpg`, `d-clients.jpg` |
| | La planche : les quatre, ce que ça apporte, les limites | `planche.jpg` |

## Réel et illustré

**Réel** : les quatre magasins (Corbais, Gosselies, Halle, Sombreffe) et le
CA de Halle en septembre ; le modèle de la PWA déjà paramétrable depuis le
webshop (`/franchisor/loyalty-*`, dépôt WebShop) — `pwa_loyalty_card`
(carte à tampons : famille, seuil, récompense, validité, portée réseau ou
magasin), `pwa_loyalty_config` (`euros_per_point`, `voucher_points`,
`voucher_value`, `voucher_label`), la règle « les ×2 ne jouent que sur les
points, jamais sur les tampons » ; les bons de l'ERP (`voucher_campaign`,
motif `FIDELITE`, émetteur marque ou magasin).

**Illustré, et dit sur chaque écran** : tous les chiffres clients (membres,
points, bons, segments, cohortes, missions). Le trafic heure par heure est
plausible, pas lu.

## Ce qu'il faudrait pour coder

- **Les règles ×2 / ×3** : la PWA connaît déjà des ×2 (`docs/LOYALTY.md` du
  dépôt `latelier-by-pwa`, non consulté ici) — à vérifier où ils se règlent.
  Sinon, une table `pwa_loyalty_rule` (multiplicateur, jours, heure de début /
  fin, familles ou gamme, magasin ou réseau, dates, actif) que la PWA lit au
  calcul des points.
- **Les déclencheurs** : une table d'événements (inscription, n-ième visite,
  anniversaire, panier ≥ X, parrainage, absence ≥ N jours) → récompense
  (points, bon ERP `voucher_campaign` motif `FIDELITE`, produit offert),
  validité. La jauge 150 points → bon existe déjà (`voucher_points`).
- **La récurrence** : il faut le client sur le ticket. Les tickets du panel
  ne portent pas l'identifiant fidélité ; la PWA le connaît sans doute
  (elle dérive les tampons des achats du client) — à vérifier. Une table `pwa_loyalty_visit` (client, magasin, date-heure, montant,
  lignes) ou le rattachement ticket ↔ client suffit à tout le reste.
- **Le type d'achat** : se déduit des lignes du ticket (familles du
  catalogue) et de l'heure ; un panier de 25 € et plus est une « occasion »,
  une commande webshop est « commande & bureau ». Les seuils sont des
  réglages.
- **L'effet des ×2** : même calcul que les promotions jours creux
  (`GET /promo`, effet face aux 4 semaines d'avant), restreint aux membres,
  comparé aux non-membres.
- **Données personnelles** : prénom + initiale à l'écran, fiche complète
  réservée au magasin du client et à la marque, export limité aux clients
  ayant accepté la newsletter.

## À trancher

1. La maquette (ou le mélange — par exemple le Programme de C avec les
   Clients de B).
2. La règle de base : 1 € = 1 point et 150 points = 5 € rendent 3,3 % du CA
   (3,8 % avec les ×2) ; le coût de septembre illustré est 1,7 % du CA.
3. Le bon « on vous attend » : à 45 jours aujourd'hui, à 21 jours proposé
   (un régulier revient seul à 52 % à 21 jours, à 21 % à 45 jours — chiffres
   illustrés, à mesurer).

Régénérer : `python3 -m http.server 8099` depuis la racine du dépôt, puis
`node docs/maquettes/fidelite/generer.js`. `fi.css` porte le style ; les
couleurs des six types d'achat sont validées (séparation daltonisme).
