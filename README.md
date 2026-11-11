# L'Atelier By — les quatre portails

Un dépôt, quatre portails, chacun dans son dossier, chacun avec son préfixe de tables et son déploiement :

| Dossier | Portail | Tables | En ligne |
|---|---|---|---|
| [`recrutement-developpement/`](recrutement-developpement/) | Recrutement & développement | `rec_` | à verser |
| [`marketing/`](marketing/) | Marketing (Social compris) | `mar_` | à verser — aujourd'hui dépôt `marketing` |
| [`pilotage-reseau/`](pilotage-reseau/) | Pilotage réseau (cockpit, visites, dashboard, app employés) | `pil_` (aujourd'hui `ceo_`…) | http://185.180.206.46/consulant_bo/ |
| [`formations/`](formations/) | Formations | `frm_` | à extraire du cockpit |

Toutes les clés de l'ERP : une table commune, `erp_cles` (décision du 7 nov. 2026 ; elle remplace le projet `master_variable_keys`).

Registre de la réorganisation, règles d'export vers l'ERP et organisation des API : [`docs/PORTAILS.md`](docs/PORTAILS.md).
Design system commun (écrans et documents) : [`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md).
