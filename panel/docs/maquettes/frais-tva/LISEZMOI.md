# Dashboard magasin › Frais, et le P&L court hors TVA

**Statut : codé le 16/11/2026** (demande « code it, push it »), avec les choix proposés ci-dessous. Code :
`src/pnl_ht.php`, `src/franchise/frais.php`, `/exploitation/jour` et `/exploitation/periode`, `public/dashboard/dashboard.js` (onglet `frais`,
carte du P&L) ; routes : `docs/contrat-api.md` ; tests : `php bin/tests_pnl_ht.php`. Maquette du même jour.

Demandes (16/11/2026) : « le P&L refaire sans TVA », puis « faire un onglet dans le rail avec leur frais, et marquer
si oui ou non c'est avec ou sans TVA, et le taux », puis « faire une maquette (en screenshot) ».

## Le constat

La carte « Le P&L court de la journée » mélange les deux bases :

| Ligne | Base aujourd'hui | Source |
|---|---|---|
| Chiffre d'affaires | **TTC** après remise | `ca` de margin-heatmap (docs/contrat-api.md, « taxe comprise ») |
| Coût matière | coût de recette (hors TVA) | CA − `margin_value` du panel, sinon recettes des tickets |
| Invendus et poubelle | coût de production **net** | `/shops/{id}/products/waste` |
| Main-d'œuvre | salaires (pas de TVA) | daily-summary `employee_cost` |
| Frais généraux | **tels que saisis au panel**, TVA comprise ou non selon la saisie | daily-summary `shop_cost`, `/shops/{id}/owner/costs` |

Tous les pourcentages se rapportent au CA TTC : ils sont trop bas d'environ 6 %, et la TVA compte comme une recette.

## Les deux écrans

- `a-onglet-frais.png` : le rail du bureau, groupe « Les chiffres », une entrée **Frais** après Trésorerie. Les frais du
  mois, poste par poste, tels que le panel les rend ; pour chacun, **TVA comprise ? Oui / Non** et le **taux** (0, 6, 12,
  21 %), le hors TVA et la TVA qui en découlent. En tête : saisi, hors TVA, TVA comprise, postes à indiquer. En bas : ce
  que ça change dans le P&L court.
- `b-pnl-hors-tva.png` : Opérationnel › « Le P&L court de la journée · hors TVA ». Le CA encaissé (TTC) en tête, la
  TVA des tickets retirée, puis tout se rapporte au CA hors TVA ; les frais généraux hors TVA d'après l'onglet Frais.
  À droite, la même journée avant et après (sur la carte envoyée : résultat + 501 € · 19,1 % avant, + 382 € · 15,4 %
  après ; coût matière 39,4 % → 41,7 %, au-dessus du seuil de 40 %).

Chiffres : la journée de la carte envoyée (CA 2 628 €, 187 clients), TVA des tickets à 6 % pour l'exemple ; le total
des frais est réel (Halle, septembre 2026, 13 042,65 €), les lignes de frais sont des exemples.

## Ce qui est proposé

1. **CA hors TVA** = CA encaissé × (1 − TVA ÷ TTC des tickets du jour). La TVA vient de la caisse, ticket par ticket
   (`tax_amount_after_discount` de `/shops/{id}/transactions?date=`, la liste que le dashboard lit déjà toutes les dix
   minutes pour le split pro : pas d'appel de plus). Un jour sans tickets lus prend le taux habituel du magasin, et le dit.
2. **Frais généraux hors TVA** = frais du jour × (hors TVA ÷ saisi) des frais du mois, d'après les choix de l'onglet
   Frais. Un poste pas encore indiqué compte tel que saisi et se signale en orange.
3. Le P&L passe hors TVA **au jour, à la semaine et au mois** (la même carte). Restent TTC : le CA des tuiles,
   l'objectif, le panier et la valeur du magasin (le budget est posé en TTC, la caisse encaisse en TTC).
4. Les choix « TVA comprise » et « taux » se gardent au cockpit (table `pil_frais_tva`, par magasin et par poste),
   d'un mois à l'autre ; le panel n'est pas modifié.

## Restait à trancher (codé avec ces choix)

- Le coût matière : on suppose que la marge du panel (`margin_value`) est calculée sur le CA TTC (CA − coût de
  recette), comme le recalcul depuis les tickets. Si elle l'est sur le hors TVA, le « coût matière » d'aujourd'hui
  contient la TVA ; à vérifier sur un jour avec le compte consultant.
- Qui remplit l'onglet Frais : le franchisé dans son dashboard, et le siège (qui ouvre tous les dashboards) ; le nom
  de qui a indiqué s'affiche sous le poste (retiré de l'écran le 21/11/2026 : « take off indiqué le 21/11 dans le
  tableau » ; l'API rend toujours `majLe` et `majPar`).
- La vraie liste des frais : celle du panel (`/shops/{id}/owner/costs?period=AAAA-MM`), postes et types à relire.

## Fichiers

`coque.js` (le rail et la barre du haut, d'après `public/dashboard/dashboard.js`), `donnees.js` (les chiffres),
`frais.css`, `a-onglet-frais.html`, `b-pnl-hors-tva.html`. Captures :

```
php -S 127.0.0.1:8099 -t pilotage-reseau
node pilotage-reseau/docs/maquettes/frais-tva/capturer.cjs
```
