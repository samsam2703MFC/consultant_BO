# Fidélité, troisième version : le réglage au cockpit, les profils au dashboard magasin, la fiche d'un client

Demande du 12/11/2026 (Sam) : « les conditions doivent être dans le cockpit de pilotage, dans le rail Fidélité ;
le dashboard doit aller dans le dashboard shop (magasin franchisé) ; les conditions seraient 1× par mois, 2 à 3×
par mois, 1× par semaine, 2× par semaine, 3× par semaine (Fanbase) ; on doit pouvoir prendre un client et voir
l'historique de son comportement ».

| Maquette | Où | Capture |
|---|---|---|
| **A** | Cockpit › rail ERP franchisé › Fidélité › onglet **Profils clients** : les conditions, réglées pour tout le réseau, et publiées dans les magasins | `a-cockpit.jpg` |
| **B** | Dashboard magasin › onglet **Fidélité** : les six profils en % des clients scannés, la condition sous chaque vignette, les radars du profil choisi, les clients qui reviennent | `b-dashboard.jpg` |
| **C** | Dashboard magasin › Fidélité › **un client** : son profil mois par mois, ses passages semaine par semaine, ses habitudes, ses radars, ses tickets | `c-client.jpg` |

`generer.py` refait les trois pages depuis `donnees.py` (chiffres de la sonde) et `v3.css`.

## Les profils

| Profil (nom proposé) | Condition | Par mois | Halle, 90 jours |
|---|---|---|---|
| Fanbase | 3 fois par semaine ou plus | 12 ou plus | 0 |
| Fidèle | 2 fois par semaine | 8 à 11 | 0 |
| Hebdo | 1 fois par semaine | 4 à 7 | 1 |
| Régulier | 2 à 3 fois par mois | 2 à 3 | 1 |
| Occasionnel | 1 fois par mois | 1 | 4 |
| Découverte | moins d'1 fois par mois | moins d'1 | 203 |

## Les chiffres sont réels

L'Atelier by Halle, lus le 12/11/2026 par l'API du panel, sans aucune table locale
(`tools/sonde_profils_halle.php`) : 209 clients scannés sur 90 jours, et l'historique du client A sur 180 jours
(16 tickets, le premier le 21/09/2026). Les clients sont anonymisés (A à F).

## À trancher

- **Les noms des profils** : Fanbase est demandé ; Fidèle, Hebdo, Régulier, Occasionnel et Découverte sont proposés.
- **La fenêtre de mesure** : 90 jours. Le client A est Hebdo sur 90 jours, mais passe 2 fois par semaine depuis
  octobre : faut-il une fenêtre plus courte, ou le profil du mois en cours ?
- **Le bouton « Activer sa carte fidélité »** de la fiche client : aucune route d'activation n'existe dans l'API du
  panel aujourd'hui.
- **Les noms à l'écran du magasin** : prénom et initiale du nom.
