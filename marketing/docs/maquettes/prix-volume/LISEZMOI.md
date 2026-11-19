# Prix × volume — trois propositions

La question : « où vérifier les volumes de ventes des magasins par rapport à
leur prix de vente ». Un quatrième onglet sous **Produits › Où ça se vend**.
Données réelles de **septembre 2026** (dernier mois clos), lues en ligne :

- les pièces vendues par magasin et par référence : `GET /analyse/produits?mois=12`,
  tranche de septembre ;
- le prix encaissé par magasin (chiffre ÷ pièces, remises comprises) :
  `GET /analyse/prix-transfert?source=&cible=&m=2026-09` sur les douze couples de
  magasins. Une référence absente de tous les couples a le même prix partout.

| | Proposition | Planche |
|---|---|---|
| A | La grille : chaque référence × chaque magasin, prix encaissé + pièces + volume à taille égale | `planche-a.jpg` |
| B | La fiche : prix contre volume en nuage, magasin par magasin, et ce qu'un alignement changerait | `planche-b.jpg` |
| C | Par magasin : moins cher / plus cher que le réseau, signal de volume, simulation « aux prix de » | `planche-c.jpg` |

**Volume à taille égale** : pièces pour 10 000 € de chiffre du magasin, face à la
moyenne des autres magasins qui vendent la référence — sans quoi Corbais, trois
fois plus grand, gagnerait toutes les comparaisons. **Prix réseau** : la médiane
des prix encaissés.

Pour coder, rien de neuf côté panel : les tranches lues par « Où ça se vend »
portent déjà, par magasin et par référence, les pièces ET le chiffre
(`apCondense` → `[nom, cat, qté, CA]`). Le calcul « aux prix de »
(`ep_prix_transfert`) existe et n'a pas encore d'écran.

Régénérer : serveur statique sur 8099 depuis la racine, puis
`node docs/maquettes/prix-volume/generer.js`.
