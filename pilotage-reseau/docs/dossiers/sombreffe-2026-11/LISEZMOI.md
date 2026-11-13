# Sombreffe, comptoir et B2B — rapport du 13/11/2026

Le rapport d'entrée en soins intensifs de Sombreffe (magasin 5), mai 2025 → octobre 2026.

| Fichier | Rôle |
|---|---|
| `generer.py` | lit les données, écrit `rapport.html` depuis `gabarit.html` |
| `gabarit.html` | la page : styles, textes, graphiques (les chiffres viennent de `generer.py`) |
| `rapport.html` | la page générée, publiée telle quelle |
| `pdf.js` | `rapport.html` → `Sombreffe-rapport-2026-11.pdf` (A4, Gotham, « Page x / y ») |

```bash
python3 pilotage-reseau/docs/dossiers/sombreffe-2026-11/generer.py && node pilotage-reseau/docs/dossiers/sombreffe-2026-11/pdf.js
```

## Les données à déposer ici

Tant qu'un de ces fichiers manque, sa partie du rapport est marquée « à compléter ». Déposé à côté de
`generer.py`, il entre dans le rapport à la génération suivante.

### `pnl-mois.json` — les frais de personnel face au CA, 18 mois

Dans le navigateur où le cockpit est ouvert (la session suffit), enregistrer la réponse de :

    <adresse du cockpit>/api/cockpit/analyse/pnl/mois?shop=5&du=2025-05&au=2026-10

C'est le P&L quotidien du panel additionné par mois (`src/pnl_mois.php`) : CA, personnel (`labour`), frais
généraux. Le repère est le seuil de main-d'œuvre du cockpit, 33 % du CA par défaut (`seuilsCharges`).

### `b2b-clients.csv` — les clients B2B, année par année

Export CSV (virgule ou point-virgule) de cette requête, en lecture seule, sur `atelierby_db` :

```sql
SELECT k.id AS id_client,
       k.company_name AS societe,
       ROUND(SUM(IF(YEAR(t.insert_timestamp) = 2025, t.total_gross_amount_after_discount, 0)), 2) AS ca_2025,
       COUNT(DISTINCT IF(YEAR(t.insert_timestamp) = 2025, t.ticket_key, NULL)) AS commandes_2025,
       ROUND(SUM(IF(YEAR(t.insert_timestamp) = 2026, t.total_gross_amount_after_discount, 0)), 2) AS ca_2026,
       COUNT(DISTINCT IF(YEAR(t.insert_timestamp) = 2026, t.ticket_key, NULL)) AS commandes_2026,
       ROUND(SUM(IF(t.insert_timestamp >= '2025-11-01' AND t.insert_timestamp < '2026-11-01',
                    t.total_gross_amount_after_discount, 0)), 2) AS ca_12_mois,
       ROUND(SUM(t.total_gross_amount_after_discount), 2) AS ca_total,
       MIN(DATE(t.insert_timestamp)) AS premier_achat,
       MAX(DATE(t.insert_timestamp)) AS dernier_achat,
       k.*
FROM `transaction` t
JOIN client k ON k.id = t.id_client
WHERE t.id_shop = 5 AND k.is_b2b = 1
  AND t.insert_timestamp >= '2025-01-01'
GROUP BY k.id
ORDER BY ca_total DESC;
```

Le calcul est celui du cockpit (`vsProCopie`, `src/ventes_semaines.php`) : la copie de caisse, client marqué pro au
panel. Le cockpit ne compte en B2B que les clients à TVA valide (la fiche du panel) : la note sous le tableau le dit.
Le rapport montre les 15 premiers clients, les autres en une ligne.
