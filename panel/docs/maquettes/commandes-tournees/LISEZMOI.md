# Les commandes du magasin, liées aux tournées et aux bureaux à livrer — maquettes

**Statut : A et B retenues ensemble, sur un interrupteur abricot** (Sam, 24/11/2026 : « A et B on toggle (apricot) »).
C reste à décider. Rien n'est codé.

Demande du 24/11/2026 : « dans le dashboard franchisé, faire un panel pour les commandes (webshop, comptoir, bureau),
liées avec les tournées et les offices à livrer ; faire une maquette screenshot, 2 ou 3 versions, avec le shop
numéro 3 ». Magasin 3 : **Atelier by Max & Sandra - Gosselies** (rue du Pircha 202, 06:00 – 18:00).

## Ce que propose la maquette

- **Une entrée du rail, « Commandes »**, dans « La journée », sous Opérationnel. Son compteur (7) : les commandes à
  préparer avant 13:00.
- **Trois canaux**, avec les couleurs de la fiche client (`FD_CANAUX`, `dashboard.js`) : Comptoir bordeaux (la commande
  prise au comptoir, au téléphone ou par mail), Webshop bleu, Bureau vert (la commande d'un bureau livré).
- **Une commande à livrer a sa tournée et son arrêt** (« Charleroi 11:15 · arrêt 2 ») ; une commande sans livraison se
  retire au magasin. Une livraison qu'aucune tournée ne prend est signalée, avec la tournée qui passe à côté.
- **Les bureaux à livrer** : chaque tournée dessert des bureaux. Ceux qui n'ont pas commandé apparaissent sur la tournée
  (cercle en pointillé), et pour demain on voit qui a commandé, qui pas encore (avant 16:00, la règle « commander la
  veille avant 16 h » des modèles du portail Commercial), qui ne commande pas ce jour-là. « Relancer » reprendrait le
  modèle de relance du portail Commercial (`commercial/src/commercial.php`).

## A et B : une page, un interrupteur abricot

La page Commandes a deux vues, **Liste** (A) et **Tournées** (B), et un interrupteur en haut à droite, à la place
d'une entrée de rail de plus. Il est à l'Abricot de la marque, comme l'Assistant IA du rail et les boutons abricot du
coffre : fond `#FFF8F1`, filet `#F2C9A0`, texte `#7A3E12` ; la vue allumée en `#F2C9A0`, texte Ruby (`--accent`). Chaque
vue porte son compte (Liste 20, Tournées 3). Le reste de la barre ne bouge pas : la date, Jour · Semaine, « Nouvelle
commande ». Proposé pour le codage : la vue choisie se garde d'une visite à l'autre, par magasin (`localStorage`).

Pas de gras sur la vue allumée : le §4.3 du design system le réserve à sa liste fermée (le bouton abricot de
`formation.css` est en 700, c'est un écart déjà en ligne, pas repris ici).

| Fichier | Ce que c'est |
|---|---|
| `bascule-liste.png`, `bascule-tournees.png` | l'interrupteur de près, dans ses deux positions |
| `ecran-a.png` | la page, interrupteur sur Liste |
| `ecran-b.png` | la page, interrupteur sur Tournées |

## Les trois versions de départ

| | Écran | L'idée |
|---|---|---|
| **A** | `ecran-a.png` — **la liste du jour** | Une seule liste, de la première livraison à la dernière, avec un filet « Maintenant ». Le bandeau donne les trois canaux (nombre, montant, part). Filtres : canal, à livrer ou à retirer. À droite, les trois tournées (état, arrêts livrés ou prêts) et demain : les bureaux qui n'ont pas encore commandé. |
| **B** | `ecran-b.png` — **le tableau des tournées** | La journée en frise (06:00 – 18:00) : chaque camionnette et ses tournées, les retraits au magasin, l'heure qu'il est. Dessous, une colonne par tournée, ses arrêts dans l'ordre, et une colonne pour le retrait au magasin. C'est l'écran de celle ou celui qui charge la camionnette. |
| **C** | `ecran-c.png` — **la feuille de route** | Une tournée choisie : son plan schématique, ses arrêts dans l'ordre (heure, bureau, étage, consigne, montant, paiement, statut), le chargement au départ, l'encaissement à rapporter, et la livraison proposée en plus. Dessous, les bureaux de la tournée : leur semaine (L M M J V), leur habitude, leur panier moyen et demain. « Imprimer la feuille de route », « Envoyer à Nadia ». |
| | `planche.jpg` | Les trois côte à côte. |

Recommandation de départ : A pour entrer, C au clic sur une tournée. Retenu : A et B sur l'interrupteur. C peut
rester la page d'une tournée, ouverte par « Feuille de route » depuis l'une ou l'autre vue.

## Réel, illustratif

**Réel**, lu au panel le 24/11/2026 (`GET /shops`, `GET /shops/3/client-orders`) : le magasin, son adresse, ses heures ;
l'ordre de grandeur des montants de Gosselies — un petit-déjeuner de bureau à 171,75 €, des lunchs de bureau à 68,90 € et
80,70 €, des précommandes du comptoir à 15,90 € et 19,90 € retirées à 16:00 ; ses clients pro surtout à Charleroi,
Courcelles et Gosselies (162 fiches pro).

**Illustratif** : la journée entière — les 20 commandes, les bureaux (noms inventés, zones réelles : Aéropôle, Biopark,
Gosselies, Jumet, Courcelles, Charleroi), les trois tournées, les livreurs, les camionnettes, l'objectif du jour du rail.
Les clients particuliers ne sont désignés que par des initiales inventées. Aucun client réel n'est repris.

Ce qu'on lit au panel pour Gosselies, et qui compte pour coder :
- **342 commandes en tout, toutes « comptoir »** (`is_webshop` faux, `fulfilment_mode` `collect`). Aucune commande
  webshop, aucune livraison, aucun `id_client_department` ni `office_offer_id`. Presque rien depuis janvier (4 commandes
  en novembre).
- **Les commandes de bureau existent, mais prises au comptoir** : en novembre, un petit-déjeuner de 2 560,20 € (06/11,
  07:00), un de 171,75 € et deux lunchs (13/11), clients pro, encaissés en caisse — rangés en `collect`, alors qu'ils
  sont livrés. Le panel ne dit donc pas, aujourd'hui, qu'une commande de Gosselies est livrée.
- À Corbais (magasin 2), on voit les deux autres formes : le webshop en livraison (`is_webshop` vrai,
  `fulfilment_mode` `delivery`, `payment_status` `paid`) et les commandes d'un département de client (`id_client_department`,
  170 commandes d'un même client pro, livrées avant 8 h).

## Ce qu'il faudra pour coder

- **Les commandes** : `/shops/{id}/client-orders` donne déjà tout ce qu'affichent A et C pour une commande — l'heure
  (`pick_up_datetime`), le canal (`is_webshop`, `fulfilment_mode`), le statut (`order_status`, `accepting_timestamp`,
  `completion_timestamp`, `issuing_timestamp`), le montant, le paiement (`payment_status`, `id_transaction`, client
  `can_deferral` et `payment_terms` pour « Facture · 14 j »), le commentaire (« Livrer avant 8 h dans le lunchroom »).
  La lecture existe : `coCommandes()` (`marketing/src/canaux_offres.php`), `/exploitation/commandes/detail`.
- **Le canal « Bureau » est à définir** : `office_offer_id` ou `id_client_department` posés, ou client pro (TVA valide,
  `vpTvaValides`, `consultant/src/ventes_pro.php`) livré. À Gosselies, seule la troisième règle trouverait quelque chose, et seulement si la commande
  dit qu'elle est livrée.
- **Les tournées n'existent pas dans l'API du panel** : `/routes`, `/delivery-routes`, `/shops/3/tours`,
  `/office-delivery-sites`, `/offices` répondent 404 ; `/webshop/offices` demande une session de bureau
  (`OFFICE_AUTH_REQUIRED`). Le portail Commercial le dit déjà : « le compte du bureau au webshop et sa tournée : API de
  TF Buddy à définir avec Szymon ». Deux chemins, à trancher :
  1. attendre l'API de TF Buddy (tournée d'un bureau, ordre de passage, livreur) ;
  2. les tenir au dashboard : `panel_tournee` (magasin, nom, départ, retour, livreur, véhicule, jours) et
     `panel_tournee_bureau` (tournée, client du panel, ordre, consigne), la commande rattachée par son client.
     Le champ texte `sm_client.tournee` du portail Commercial deviendrait un lien vers `panel_tournee`.
- **Le plan de C** est schématique (des points placés à la main) : un vrai plan demande les coordonnées des bureaux
  (géocodage de l'adresse du client) et une carte. À décider ; la feuille de route vaut sans.
- **Relancer** : le modèle « relance » du portail Commercial existe (`commercial/src/commercial.php`) ; il faudrait
  l'ouvrir depuis le dashboard du magasin, pour un bureau de ses tournées.

## Régénérer

```
node panel/docs/maquettes/commandes-tournees/capturer.js
```

Aucun serveur à lancer : le navigateur lit les fichiers sur le disque (polices `shared/public/assets/ds/fonts/`, logo
`panel/public/tablette/img/logo.png`). `index.html?v=a` (Liste), `?v=b` (Tournées), `?v=c` ; `planche.html` assemble les trois captures ; le script recadre
l'interrupteur (`bascule-*.png`).
Les données sont dans `donnees.js`, le dessin dans `maquette.js`, les styles (design system et rail) dans `mq.css`.
