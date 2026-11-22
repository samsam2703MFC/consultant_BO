# CLAUDE.md — formation/ (domaine Formation · tables `for_`)

Lire d'abord le `CLAUDE.md` de la racine (règles des quatre domaines, clés, migrations).

- **Périmètre** : le Manuel du réseau (référentiel commun, guides, SOP en français et néerlandais, versions, suivi par
  personne), les postes et le référentiel v25, les tablettes des magasins (accès par PIN, code de gestion).
- **Tables** : `for_*` (`for_sop*`, `for_ref_*`, `for_manuel_*`, `for_tablette_*`).
- **Code** : `src/` (`formation.php`, `sop.php`, `referentiel*.php`, `tablettes.php`…), l'écran `formation/public/formation/`
  servi à `/formation/` (son API à `/formation/api/cockpit/…`), les contenus `formation/data/formation/`, les outils
  `formation/tools/formation/` (à lancer depuis `formation/`).
- **Lu par les autres domaines** : `GET /formation/ref`, `/formation/v25`, `/formation/referentiel`,
  `/formation/procedure` (jeton de service pour les autres portails).
- **Tests** : `php formation/bin/tests_formation.php`, `php formation/bin/tests_manuel_chiffres.php`.
