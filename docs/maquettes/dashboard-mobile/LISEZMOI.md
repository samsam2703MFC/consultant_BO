# Dashboard magasin sur téléphone — trois formes

Trois propositions pour l'écran du gérant sur son téléphone, à **390 × 844**
(iPhone 15). Sur mobile, **seuls le jour et la semaine** sont retenus : pas de
mois, pas de trimestre, pas d'année — ces vues-là restent au bureau.

Les chiffres sont **réels**, lus sur le serveur : Atelier by Berlo — Corbais,
lundi 14 septembre 2026 (`/exploitation/jour`), la semaine du 14 au 20
arrêtée au 15 (`/exploitation/periode?vue=semaine`), et la valeur du magasin
(`/ventes/mensuel`). Une maquette qui ment sur ses ordres de grandeur ne dit
rien de ce qui tiendra à l'écran.

| | forme | ce qu'elle coûte |
|---|---|---|
| **A** | `a-app-onglets.html` — le chiffre du jour en grand, l'alerte juste dessous, six lignes repliées qui portent chacune leur chiffre, deux onglets en bas | la plus proche d'une app ; c'est aussi celle qui demande le plus de gestes pour tout lire |
| **B** | `b-cartes.html` — une carte par sujet, complète, qu'on fait défiler ; anneau du jour, sept barres de la semaine, cascade du compte | rien n'est caché, mais la page est longue |
| **C** | `c-fil.html` — ni carte ni onglet, une suite de chiffres séparés par des filets, dans l'ordre des questions qu'on se pose | la plus dense et la plus rapide à lire ; la moins « app », et rien n'y est cliquable en évidence |

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
