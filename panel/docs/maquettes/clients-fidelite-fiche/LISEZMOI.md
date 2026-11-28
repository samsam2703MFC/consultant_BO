# Clients › Particuliers : la fiche plus serrée et « quand il vient » (maquettes)

**Statut : A choisie par Sam (« le a », 28/11/2026) et codée** (`panel/public/dashboard/clients.js` : `ficheParticulier`,
`blocFidelite`, `friseFid` ; `clients.css` ; les jours de passage dans `GET /fidelite/profils/clients`). B, C et D
restent ici pour mémoire. La demande, depuis la fiche en ligne
(`dashboard/?shop=4&vue=clients`, la fidélité et son radar mis en ligne le même jour, PR #339) : « compresser
légèrement le drawer (datas) et rajouter une indication sur la fréquence (quand le client était là), visuellement ;
fais-moi 3-4 maquettes en screenshot et on décide ».

Les maquettes chargent les **vraies** feuilles du dashboard (`global.css`, `dashboard.css`, `clients.css`) : la fiche
se dessine comme en ligne. Les éléments neufs (`pcl-serre`, `pcl-fid-k`, `pcl-fid-quand`, `pcl-frise`, `pcl-cal13`,
`pcl-sem`, `pcl-mpm`) sont écrits dans `ff.css` comme ils iraient dans `clients.css`. Client **fictif** (Sophie Renard,
Val-Fleuri, @exemple.be), profil Hebdo, 13 passages en 90 jours, absente trois semaines en octobre.

| Capture | Ce qu'elle montre | Bas du radar (pli à 840 px) |
|---|---|---|
| `fiche-avant.png` | la fiche en ligne : cinq lignes de chiffres, l'aide « Passer en entreprise », le radar sous le pli | 1113 px |
| `fiche-a.png` | **A · la frise des 90 jours** : un trait par jour, plein le jour d'un passage ; le plus long creux | 818 px |
| `fiche-b.png` | **B · le calendrier des 13 semaines** : un carré par jour, une ligne par jour de la semaine ; le jour préféré | 893 px |
| `fiche-c.png` | **C · semaine par semaine** : une barre par semaine, le trait du rythme de son profil ; semaines « au rendez-vous » | 833 px |
| `fiche-d.png` | **D · mois par mois** : les jours de chaque mois et le profil du mois (il glisse, il revient) | 828 px |
| `ensemble.png` | les cinq côte à côte, le trait rouge au bas d'un écran 1080p | |

**Commun à A–D, la fiche serrée** : la remise et le paiement différé passent dans la ligne sous le nom (la bande de
trois chiffres part ; la ville est dans l'adresse) ; coordonnées resserrées, « En ligne » et sa date sur une ligne ;
l'aide « Passer en entreprise » passe dans le titre du bouton (elle reste dans sa fenêtre) ; la fidélité : le profil,
sa condition et le dernier passage sur une ligne, puis quatre chiffres (par mois, en 90 j, panier, commande) ; le
radar à 270 px au lieu de toute la largeur, sans la phrase d'aide.

**Pour le coder** : `GET /fidelite/profils/clients` rend en plus les jours de passage de chaque client sur la mémoire
(une liste de dates, déjà dans `mar_fid_ticket`) ; le dessin se calcule dans `clients.js`.

Régénérer : `node panel/docs/maquettes/clients-fidelite-fiche/capturer.js` (aucun serveur ; `DSF=1` pour des captures
légères). `index.html?v=a` (ou `b`, `c`, `d`, `avant`) n'en montre qu'une.

## Un passage de la frise ouvre son ticket (maquettes, 28/11/2026)

**Statut : maquette, rien n'est codé.** Demande : « avant de mettre en ligne, ajouter la possibilité de cliquer sur une
visite et d'avoir son ticket ; fais une maquette ». `ticket.html` (`tk.js`, `tk.css`) reprend la fiche A et ouvre le
ticket (fictif) du samedi 21/11 de trois façons :

| Capture | Ce qu'elle montre |
|---|---|
| `ticket-1.png` | **1 · la bulle** : sous le trait, par-dessus la fiche, la flèche sur le trait ; rien ne bouge |
| `ticket-2.png` | **2 · sous la frise** : le ticket s'ouvre dans la fiche, le radar descend le temps de le lire |
| `ticket-3.png` | **3 · le ticket de caisse à côté** : un panneau à gauche de la fiche, par-dessus la liste, mis en page comme le ticket de la caisse (n°, prix unitaires) |
| `ticket-ensemble.png` | les trois côte à côte |

Partout : le jour, le canal (comptoir, commande, webshop), l'heure et le n° du ticket ; par ligne la quantité, le produit,
la portion et le total après remise ; la remise et le total ; ‹ › le passage d'avant ou d'après ; × ferme.

**Les données** : rien de nouveau à garder. Les tickets d'un client et d'un jour sont déjà dans `mar_fid_ticket` (id,
heure, montant, canal) ; leurs lignes se liraient à la demande (`/transactions/{id}?include=products`, compte du
cockpit : `product_display_name`, `product_portion_label`, `quantity`, `unit_gross_price`,
`total_gross_value_after_discount`), par une route comme `GET /fidelite/ticket?shop=&client=&jour=`.

Régénérer : `node panel/docs/maquettes/clients-fidelite-fiche/capturer-ticket.js`.
