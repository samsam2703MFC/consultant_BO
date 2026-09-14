# Les non-conformités de la veille, dans le Dashboard › Jour

Maquettes pour la demande : *dans la vue Jour, ajouter les non-conformités des
tâches du jour −1*. Trois partis pris, mêmes données, même charte
(`public/assets/ds/global.css` + `public/dashboard/dashboard.css`).

- `v1-tuile-bandeau.html` — une 6ᵉ tuile dans le bandeau des tâches, tiroir au clic.
- `v2-carte-dediee.html` — une carte à part sous le bandeau : compte, semaine, liste.
- `v3-bandeau-alerte.html` — un bandeau d'alerte en tête de page, et des marques
  dans le fil de la journée existant.

Les PNG du même nom sont les captures (Chromium, 1560 px, ×2).
`generer.js` régénère les trois pages ; `maquette.css` porte les styles
candidats à verser dans `dashboard.css`.

Les chiffres et les cinq non-conformités sont **fictifs** : magasin 4
(Waterloo — Centre), lundi 14 septembre 2026, veille au dimanche 13.

## Ce que ça coûte à brancher

Aucun endpoint nouveau. La page lit déjà `/pwa/tasks?date=` pour la journée en
cours (`charger()`, clé `taches|<date>`) ; il suffit de la lire une seconde fois
sur la veille.

| Donnée de la maquette | Source |
| --- | --- |
| La non-conformité | `/pwa/tasks?date=J-1` → `shops[].taches[]`, `accepte === false` (ou `note < seuil`) |
| Gravité (mineure / majeure / critique) | `/meta` → `signalement.niveaux` (5 Exemplaire · 4 Conforme · 3/2/1 NC mineur/majeur/critique) |
| Le constat | `comment` de la tâche — obligatoire sous le seuil, donc toujours présent |
| Qui a relevé, à quelle heure | `consultant`, `valideeLe` / `majLe` |
| La checklist | `checklist` |
| La colonne « Aujourd'hui » | la lecture **déjà faite** du jour : même `taskId`, statut `aControler` / `sansPhoto` / `nonRendue`, et `note` si elle est retombée |
| La photo et ses repères | `/pwa/tasks/detail?shop=&task=&date=` (`photo`, `reperes`) — au clic seulement |
| Les 7 derniers jours (V2) | `/pwa/tasks/heatmap/mois?du=&au=` — déjà lu par les vues Semaine et Mois |

Points ouverts, à trancher avant de coder :

1. **La veille d'un jour fermé.** Si le magasin n'a pas ouvert la veille, faut-il
   remonter au dernier jour ouvert plutôt que d'afficher un bloc vide ?
2. **Le seuil.** Il vit dans le réglage `signalement` (défaut 4) ; la page
   dashboard ne lit pas encore `/meta`. Soit un appel de plus, soit se contenter
   d'`accepte`, qui est déjà calculé côté serveur (mais sans le nom du niveau).
3. **La récidive** (« 3ᵉ fois en 7 jours ») demande une lecture de 7 jours, pas
   d'un seul. Utile, mais c'est ce qui coûte le plus cher ici.
