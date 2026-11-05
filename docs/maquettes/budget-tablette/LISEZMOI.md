# Tablette de contrôle : le mois, le trimestre, l'année face au budget — maquette

Demande du 05/11/2026 : « Rajoute une jauge mensuelle / trimestrielle et annuelle de la jauge de CA par rapport au
budget », précisée : « budget année », « pas dans le dashboard mais dans la tablette de contrôle ».

Dans la tablette de contrôle, temps **4 · Analyse**, étape **KPI et santé du magasin**, le bloc « Le CA face au budget »
s'ouvre désormais sur une carte **Le mois, le trimestre, l'année** : trois jauges, l'une sous l'autre. Les onglets
d'avant (jour, semaine, mois, trimestre, année, chacun avec son mini P&L) restent dessous. Un toucher sur une jauge
ouvre son onglet.

## Ce que montre chaque jauge

Chaque jauge va de 0 à l'objectif de l'étendue. Les chiffres sont ceux des onglets (`GET /visites/budget/{shop}`),
arrêtés à la veille, jour clos.

| Élément | Ce qu'il dit |
|---|---|
| La barre pleine | le réalisé, à la couleur du statut des onglets : vert au budget, orange proche, rouge sous le budget |
| Le trait pointillé | l'attendu à date, au rythme des budgets |
| Le hachuré | au rythme actuel : l'objectif × réalisé ÷ attendu. Absent quand l'étendue est complète |
| À droite | le réalisé, son pourcentage de l'objectif, l'écart à l'attendu, ce qui manque (ou l'avance), la projection |
| Sous le nom | jour 4 sur 30, jour 35 sur 92, jour 308 sur 365, et la place dans le réseau |
| Pour l'année | la base du budget (« budget validé et CA théorique ») et les mois hors comparaison |

**Le budget de l'année** est celui que la tablette calcule déjà pour son onglet Année :
- le budget validé de chaque mois, sinon le CA théorique de l'étude ;
- un mois sans budget ni vente reste hors comparaison.

Pour Halle, l'objectif 2026 est de **673 800 €** : mars à décembre au budget validé (615 000 €), février au
théorique (58 800 €), janvier hors comparaison puisque le magasin n'avait ni budget ni vente.

## Les chiffres de Halle, arrêtés au 04/11/2026

| Étendue | Réalisé | Attendu à date | Objectif | Écart | Au rythme actuel |
|---|---|---|---|---|---|
| Novembre 2026 (jour 4 sur 30) | 7 850 € | 7 976 € | 61 000 € | −1,6 %, proche | 60 036 € |
| T4 2026 (jour 35 sur 92) | 66 733 € | 68 976 € | 183 000 € | −3,3 %, proche | 177 049 € |
| Année 2026 (jour 308 sur 365) | 434 507 € | 559 776 € | 673 800 € | −22,4 %, sous le budget | 523 014 € |

Halle est 4e sur 4 sur les trois étendues.

## Captures

| Fichier | Ce que c'est |
|---|---|
| `a-trois-jauges.png` | la carte |
| `b-a-sa-place.png` | la carte à sa place, au-dessus des onglets et du mini P&L |
| `c-annee-ouverte.png` | un toucher sur l'année : son onglet s'ouvre dessous |
| `d-portrait-nl.png` | en portrait, en néerlandais |

**Comment elles sont faites.**
- L'application en ligne, ouverte en lecture seule (tout appel autre que GET est intercepté), avec `visites.js` et
  `visites-nl.js` de ce dépôt.
- Visite n° 6 : Halle, en cours. Chiffres réels.
- Pour aller directement à l'analyse, les étapes bloquantes sont coupées le temps de la capture.

Régénérer : `node docs/maquettes/budget-tablette/generer.js`

## Statut

**Codée le 05/11/2026 sur la branche, à valider avant la mise en ligne.**

- `vb3Html()` dans `public/assets/js/visites.js`, appelée en tête de `budgetHtml()`. Styles `.vb3` dans le même
  fichier ; traductions dans `visites-nl.js`.
- Rien de nouveau côté serveur.
- La carte d'abord posée dans l'Opérationnel du dashboard a été retirée.
