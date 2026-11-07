# Dépôt des quatre portails — L'Atelier By

Quatre portails, un dossier chacun : `recrutement-developpement/` (`rec_`), `marketing/` (`mar_`),
`pilotage-reseau/` (`pil_`), `formations/` (`frm_`). Chaque dossier a son propre `CLAUDE.md` : lis celui du
dossier où tu travailles, il fait foi pour son code.

- Un portail ne crée, ne modifie et ne supprime **que** ses tables (son préfixe). Il lit l'ERP par son API
  (`atelierby.tfbuddy.com/api/v1`) et **jamais** les tables d'un autre portail : il passe par son API.
- Réglages : `préfixe_variable` du portail. **Toutes les clés dans `erp_cles`**, la table des clés commune
  à l'ERP (décision du 7 nov. 2026, remplace `préfixe_keys` et le projet `master_variable_keys`) : portail
  `commun` (Meta, e-mail, SMS, IA, Google, TF Buddy, jeton de service `erp_jeton_service`, adresses
  `rec_api_url` / `pil_api_url`) ou `rec` / `mar` / `pil` / `frm`. Entre portails : leur API, en-tête
  `X-Service-Token`.
- Chaque dossier se déploie seul (`.github/workflows/`, déclenché par son seul dossier) et s'exporte seul
  vers l'ERP. Registre et règles : `docs/PORTAILS.md`. Design system commun : `docs/DESIGN_SYSTEM.md`.
