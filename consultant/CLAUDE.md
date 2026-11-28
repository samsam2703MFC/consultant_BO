# CLAUDE.md — consultant/ (domaine Pilotage consultant · tables `con_`)

Lire d'abord le `CLAUDE.md` de la racine (règles des quatre domaines, clés, migrations).

- **Périmètre** : le cockpit du siège pour piloter le réseau — visites et PWA du consultant, suivi des franchisés et
  fil du franchisé, scoring, plans de développement, audits fournisseurs, rapports, KPI, budgets et perfs des
  magasins, vérification (recettes, matières, CA), production et catalogue, assortiment, planogramme, stocks et
  invendus, centrale d'achat, benchmark, contrôles des tâches et leur assistant IA, helpdesk des franchisés,
  autocontrôle qualité des produits (tablette du magasin, grilles et résultats au siège, 24/11/2026).
- **Tables** : `con_*` (dont `con_pla_*` pour le planogramme, `con_hdk_*` pour le helpdesk, `con_aq_*` pour
  l'autocontrôle qualité). Registre historique des visites : `consultant/src/con_tables.php`.
- **Code** : `src/` (63 modules), écrans `public/` (`visites/`, `suivi/`, `controle/`, `task/`, `planogramme/`,
  `caisse/`, `audit/`, `audits/`, `autocontrole/` et les scripts du cockpit dans `assets/js/`). La SPA du cockpit et
  son routeur sont dans `shared/`.
- **Autocontrôle qualité** (`src/autocontrole.php`, grilles d'exemple `src/data/autocontrole_grilles.json`) : la
  tablette `autocontrole/?shop=` (`&produit=` ouvre un produit, `&essai=1` enregistre à part, `&role=` qui contrôle :
  équipe, manager, franchisé, consultant), le siège `autocontrole/grilles.html` (résultats, grilles par catégorie,
  publication), l'onglet Qualité de la fiche produit du cockpit (réglages par produit). Dans la visite (26/11/2026) :
  l'étape « Le contrôle qualité de 3 produits » de `visites.js` ouvre la tablette dans un cadre (`&visite=&cadre=1`) ;
  les contrôles (`con_aq_controle.visite_id`) entrent dans le rapport (`suQualite*`). Maquettes et choix :
  `formation/docs/maquettes/autocontrole-qualite/LISEZMOI.md`.
- **Crons** : `consultant/bin/rapports_cron.sh`, `consultant/bin/visites_cron.sh`, `consultant/bin/stock_cron.sh`,
  `consultant/bin/helpdesk_cron.sh` (relances SMS du helpdesk) ; `consultant/bin/push_stock.php`.
- **Lit en direct** des tables d'autres applications (ERP TF Buddy, PWA consultant `mac_*`) : écart connu, à passer par
  leurs API (REORG_PLAN.md §8).
- **Absences de Mon équipe** (27/11/2026, `panel/src/equipe_absences.php`) : l'équipe de la visite (`viEquipeJour`) montre une
  absence payée à part, pas à noter ; les ventes par heure (`ventes.php`) écartent ses heures (`eqaSansAbsencesSql`).
- **Tests** : `php consultant/bin/tests_*.php` (suivi, visites, vérification, audits, benchmark…) ; les primes et le
  pointage sont dans `panel/`.
