# L'Atelier By — les quatre domaines

Un dépôt, quatre domaines, chacun dans son dossier et avec son préfixe de tables (réorganisation du 19/11/2026) :

| Dossier | Domaine | Tables |
|---|---|---|
| [`marketing/`](marketing/) | Marketing (campagnes, MétaDonnée, fidélité, newsletter, réseaux sociaux, avis) | `mar_` |
| [`formation/`](formation/) | Formation (Manuel du réseau, SOP, référentiel, tablettes des magasins) | `for_` |
| [`consultant/`](consultant/) | Pilotage consultant (cockpit du siège, visites, suivi, plans, audits, rapports, vérification, planogramme) | `con_` |
| [`commercial/`](commercial/) | Portail Commercial (bureaux, offres, échantillons, prospection B2B) | `sm_` |
| [`panel/`](panel/) | Dashboard / day panel franchisé (dashboard magasin, assistant IA, boîte mail, trésorerie, app employés et primes) | `panel_` |
| [`shared/`](shared/) | Partagé : accès, clés (`erp_cles`), base, routeur de l'API, déploiement | noms conservés |
| [`pilotage-reseau/`](pilotage-reseau/) | Hors périmètre : copie de l'ERP, module RH / Dimona | — |

L'application Marketing (campagnes, catalogues imprimés, Social, signage) est dans le dépôt `marketing`, séparé de
celui-ci (décision du 21/11/2026) ; `marketing/` d'ici est le marketing du cockpit.

Toutes les clés : une table commune, `erp_cles`, lue par `shared/src/erp_cles.php`. En ligne (serveur de test) :
http://185.180.206.46/consulant_bo/ — mêmes adresses qu'avant la réorganisation.

La réorganisation : [`REORG_PLAN.md`](REORG_PLAN.md), [`REORG_REPORT.md`](REORG_REPORT.md). Registre des portails et
règles d'export : [`docs/PORTAILS.md`](docs/PORTAILS.md). Design system commun : [`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md).
