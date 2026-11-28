# CLAUDE.md — formation/ (domaine Formation · tables `for_`)

Lire d'abord le `CLAUDE.md` de la racine (règles des quatre domaines, clés, migrations).

- **Périmètre** : le Manuel du réseau (référentiel commun, guides, SOP en français et néerlandais, versions, suivi par
  personne), les postes et le référentiel v25, les tablettes des magasins (accès par PIN, code de gestion).
- **Tables** : `for_*` (`for_sop*`, `for_ref_*`, `for_manuel_*`, `for_tablette_*`, `for_mediatheque_video`).
- **Code** : `src/` (`formation.php`, `sop.php`, `referentiel*.php`, `tablettes.php`…), l'écran `formation/public/formation/`
  servi à `/formation/` (son API à `/formation/api/cockpit/…`), les contenus `formation/data/formation/`, les outils
  `formation/tools/formation/` (à lancer depuis `formation/`).
- **Lu par les autres domaines** : `GET /formation/ref`, `/formation/v25`, `/formation/referentiel`,
  `/formation/procedure` (jeton de service pour les autres portails).
- **Médiathèque** (23/11/2026, refaite le 26/11 : sections du rail, pages, onglets, titres, recherche) : le catalogue des
  manuels (`public/formation/catalogue/`, `#/videos`, `#/videos/<page>`) range les vidéos d'instruction du dashboard
  magasin, lues dans leur registre `panel/public/dashboard/videos/videos.json` (fichier public, à l'adresse du cockpit) :
  rien à faire ici quand une vidéo s'ajoute (`panel/src/LISEZMOI.md`).
  **Collections** (26/11/2026) : Utilisation du dashboard (ce registre), Production, Vente et comptoir, Hygiène et
  sécurité, Ouverture et fermeture. Les vidéos filmées (`src/mediatheque.php`, table `for_mediatheque_video`) s'ajoutent
  dans le portail, rail › **Médiathèque** › Gérer les vidéos (`public/formation/mediatheque.js`) : un fichier (MP4, MOV
  ou WebM, envoyé en morceaux de 4 Mo, rangé dans `public/uploads/formation/videos/`) ou un lien (YouTube, Vimeo, Google
  Drive, fichier https). Le catalogue lit `GET /formation/mediatheque/liste` (ouverte aux magasins) ; les adresses
  `#/videos/c/<collection>[/<catégorie>]` et `#/videos/v/<id>` (le lien du lecteur du dashboard).
- **Examens théoriques** (26/11/2026, `src/examens.php`, tables `for_exam_*`, écran `#/rfexamens` — `examens.js`) : la
  banque de questions des candidats franchisés (dix thèmes, sources suivies par empreinte), relue chaque mois
  (`bin/examens_cron.sh`, cron 06:20, une fois par mois) ; le CRM la lit (`GET /formation/examens/banque`, jeton de
  service) et fait passer l'examen dans l'application du candidat. Rien n'entre dans un examen sans validation ici.
  Contrat : `shared/docs/contrat-api.md` § Formation · Examens théoriques.
- **Tests** : `php formation/bin/tests_formation.php`, `php formation/bin/tests_manuel_chiffres.php`, `php formation/bin/tests_mediatheque.php`.
