# Les primes dans l'app worker : prime individuelle à la vente, prime magasin sur objectif

**Statut : B retenue le 06/10/2026, avec la prime magasin en euros par heure prestée, et codée** (`src/primes.php`, `apps/employee` route `/primes`, cockpit Équipe & ventes › Paramètres ; voir `docs/contrat-api.md`).

Demande du 06/10/2026 : « rajouter tout le modeling et le système de prime à la vente et objectif
magasin », « prime individuelle et prime magasin », avec « son nombre de ventes croisées ».

Les écrans sont ceux de l'app worker (`/employee`, 390 px, aux couleurs de la marque) et une page
de réglages du cockpit. Les chiffres sont ceux de Halle au 6 octobre 2026 (`reel-halle-primes.json`) ;
personne n'est nommé : « vous », et des collègues qui sont des heures, pas des personnes.

## Ce qui existe déjà dans le cockpit (src/ventes.php)

| Règle | Comment ça marche | Réglages |
|---|---|---|
| Bats ton record | sa meilleure moyenne mensuelle de lignes par ticket sur 12 mois glissants ; le 1er dixième au-dessus pose le record, chaque dixième suivant paie | 100 € par dixième, 3 au plus, 30 tickets minimum |
| Meilleure vendeuse | score = CA ÷ (heures + 20) × coefficient de créneau ; la 1re de chaque magasin, la 1re du réseau | 75 € magasin, 150 € réseau |
| Enregistrer les primes | le calcul désigne, le CEO confirme, le mois clos seulement, au journal | |

Le vendeur est lu sur le ticket (`transaction.id_employee`), les heures sur le planning du panel.
Les tickets sans vendeur comptent au magasin, jamais à une personne.

## Le modèle proposé : deux primes qui s'additionnent

### 1. La prime individuelle, à la vente (par personne, par mois)

| Brique | Mesure | Objectif | Paliers | Au plus |
|---|---|---|---|---|
| **Ventes croisées** (nouveau) | tickets à 2 lignes ou plus, en % de ses tickets | la cible du magasin (Halle 55 %, réseau 50 % par défaut) | cible 40 € · + 5 pts 70 € · + 10 pts 100 € | 100 € |
| Bats ton record (existant) | lignes par ticket | son record 12 mois | 100 € par dixième dès le 2e | 300 € |
| Meilleure vendeuse (existant) | score CA par heure corrigé | 1re du magasin, du réseau | 75 € · 150 € | 150 € |

Une vente croisée est un ticket à deux produits différents ou plus : trois croissants font une ligne.
Le **nombre** se lit tous les jours dans l'app (« 29 ventes croisées aujourd'hui sur 61 tickets ») ;
la **prime** se joue sur le taux du mois, pas sur le nombre brut, pour ne pas primer les gros
horaires, la même raison qui classe les vendeuses au CA par heure. Trente tickets au moins dans le
mois, comme partout.

### 2. La prime magasin, sur l'objectif du mois (collective)

| Réglage | Proposition | Pourquoi |
|---|---|---|
| L'objectif | celui du budget, déjà dans le cockpit (Halle : 61 000 € en octobre) | une seule vérité, personne ne la change en cours de mois |
| La forme | des **euros par heure prestée**, selon le palier atteint | chacune comprend sa prime à la minute ; l'enveloppe suit la taille de l'équipe |
| Les paliers | 97 % → 0,50 €/h · 100 % → 1,00 €/h · 105 % → 1,50 €/h · 110 % → 2,00 €/h | un seul barème pour le réseau |
| Les heures | celles du planning du panel, le mois entier ; 20 h minimum dans le mois | la même base que le classement |
| Qui paie | la marque, en bons, début du mois suivant | comme les primes existantes |

À Halle, 604 h au planning du mois : la prime de l'équipe vaut 302 € à 97 %, 604 € à 100 %
(0,99 % de l'objectif), 906 € à 105 %, 1 208 € à 110 %. Une vendeuse à 128 h touche 64, 128,
192 ou 256 €. L'alternative, un pourcentage de l'objectif partagé au prorata des heures, reste
proposée dans la page de réglages.

### Octobre à Halle, le mois en cours

| | |
|---|---|
| Encaissé au 6 | 11 463 € (18,8 %) |
| Rythme de la première semaine | 1 528 € en semaine, 2 696 € le week-end |
| Projection du mois | 57 881 €, soit 94,9 % |
| Il manque pour 97 % | 1 289 €, soit 52 € par jour sur 25 jours |
| Il manque pour 100 % | 3 119 €, soit 125 € par jour |
| Septembre | 50 850 € sur 61 000 €, soit 83,4 % : aucun palier |

Pour « vous » (une vendeuse de Halle, 128 h au planning, 38,5 h prestées au 6) : 262 tickets,
146 ventes croisées (56 %, cible 55 % : 40 € acquis), 2,68 lignes par ticket pour un record de 2,8
(à 0,12 du record), score 59, 2e du magasin. Acquis au rythme actuel : 40 €. À portée : 373 €
(ventes croisées à 60 %, record battu de 0,2, 1re du magasin, objectif du magasin atteint).
Les mois passés de l'historique illustrent les règles ; octobre et septembre sont réels.

## Les maquettes

| | forme | ce qu'elle apporte | ce qu'elle coûte |
|---|---|---|---|
| **A** | `a.html` : **une page** qui défile : ce mois-ci, aujourd'hui, les trois primes, la prime magasin, les mois passés | tout d'un coup d'œil, un seul onglet « Primes » | longue ; la prime magasin arrive bas |
| **B** | `b-moi.html` et `b-magasin.html` : **deux onglets**, Moi et Mon magasin | Moi : le compteur du jour en premier, mes semaines ; Mon magasin : jour par jour, ce qu'il manque par palier, l'équipe | un geste de plus pour voir le magasin |
| Cockpit | `cockpit.html` : **Équipe de vente › Primes › Paramètres** | tous les réglages au même endroit, l'aperçu sur Halle, ce que l'app lit | une sous-page de plus à côté de Résultats et Targets |

Captures : `a-tel.png` (premier écran), `a-long.png` (la page entière), `b-moi-*.png`,
`b-magasin-*.png`, `cockpit.png`, `planche.jpg`.

## Ce que l'app worker lirait

`GET /ventes/moi?m=2026-10`, avec le jeton de l'app :
`{acquis, aPortee, aujourdhui{tickets, croisees}, briques[3], magasin{objectif, ca, projection,
atteinte, paliers[], heures, heuresEquipe, part}, mois[]}`. Jamais un autre nom que le sien.
Le mois en cours se montre en projection ; seul le mois clos se paie, par le geste
« Enregistrer les primes » qui existe déjà, étendu à la prime magasin.

## Pour refaire les captures

    npx http-server . -p 8099 -c-1        (depuis la racine du dépôt)
    node docs/maquettes/primes/generer.js && node docs/maquettes/primes/capturer.js
