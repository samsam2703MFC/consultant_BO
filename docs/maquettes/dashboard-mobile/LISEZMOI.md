# Dashboard magasin sur téléphone — trois formes

Trois propositions pour l'écran du gérant sur son téléphone, à **390 × 844**
(iPhone 15). Sur mobile, **seuls le jour et la semaine** sont retenus : pas de
mois, pas de trimestre, pas d'année — ces vues-là restent au bureau.

Les chiffres sont **réels**, lus sur le serveur : Atelier by Berlo — Corbais,
lundi 14 septembre 2026 (`/exploitation/jour`), la semaine du 14 au 20
arrêtée au 15 (`/exploitation/periode?vue=semaine`), et la valeur du magasin
(`/ventes/mensuel`). Une maquette qui ment sur ses ordres de grandeur ne dit
rien de ce qui tiendra à l'écran.

Après une première série (l'app à onglets, les cartes, le fil), la forme
retenue est un **mélange des deux premières** : le héros du jour et les deux
onglets de l'une, les cartes qui portent leur contenu de l'autre. Trois
variantes du mélange :

| | forme | ce qu'elle coûte |
|---|---|---|
| **1** | `ab1-heros-cartes.html` — le héros et son alerte, puis les cartes toutes ouvertes : sept barres pour la semaine, pastilles des tâches, cascade du compte, courbe de la valeur | un seul défilement descend tout, rien ne demande de clic ; mais l'écran déborde de moitié |
| **2** | `ab2-heros-grille.html` — le héros reprend l'anneau, puis quatre petites cartes en grille (tâches, non-conformités, heure de pointe, panier), la semaine et la valeur en pleine largeur | **tout le jour tient sur un écran, sans défiler** ; en contrepartie chaque chiffre est plus petit et le compte du jour passe au second plan |
| **3** | `ab3-heros-repli.html` — les cartes repliées, chacune sur une ligne avec son chiffre, la semaine montrée ouverte pour l'exemple | la plus courte à l'arrivée et celle qui laisse le plus de place au chiffre du jour ; il faut un geste par carte |

`planche.html` met les trois côte à côte.

## Regénérer

```bash
node docs/maquettes/dashboard-mobile/generer.js
```

Les pages se lisent servies depuis la **racine du dépôt** (elles chargent
`/public/assets/ds/global.css` et `/public/dashboard/dashboard.css`) :

```bash
npx http-server . -p 8099 -c-1
# → http://127.0.0.1:8099/docs/maquettes/dashboard-mobile/planche.html
```

Les PNG sont capturés à `deviceScaleFactor: 3`, comme un écran de téléphone.
