# Clients › Particuliers : la fiche plus serrée et « quand il vient » (maquettes)

**Statut : maquette, rien n'est codé (28/11/2026).** La demande, depuis la fiche en ligne
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
