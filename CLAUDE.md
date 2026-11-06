# Dépôt des quatre portails — L'Atelier By

Quatre portails, un dossier chacun : `recrutement-developpement/` (`rec_`), `marketing/` (`mar_`),
`pilotage-reseau/` (`pil_`), `formations/` (`frm_`). Chaque dossier a son propre `CLAUDE.md` : lis celui du
dossier où tu travailles, il fait foi pour son code.

- Un portail ne crée, ne modifie et ne supprime **que** ses tables (son préfixe). Il lit l'ERP par son API
  (`atelierby.tfbuddy.com/api/v1`) et **jamais** les tables d'un autre portail : il passe par son API.
- Réglages et secrets : `préfixe_variable` / `préfixe_keys` du portail ; les clés des intégrations
  partagées (SMS, Meta, Google, Anthropic) dans la table commune `master_variable_keys`.
- Chaque dossier se déploie seul (`.github/workflows/`, déclenché par son seul dossier) et s'exporte seul
  vers l'ERP. Registre et règles : `docs/PORTAILS.md`. Design system commun : `docs/DESIGN_SYSTEM.md`.
