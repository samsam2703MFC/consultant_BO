# Le dashboard magasin au design system, page par page

Demande du 28/11/2026 : « applique-le au vrai dashboard, commence par Opérationnel », puis « fais toutes les pages dans ce
style, valide et pousse une par une ». La référence : la maquette `../design-system-dashboard/` et `docs/DESIGN_SYSTEM.md`.

Chaque page : les règles du design system dans une section datée de sa feuille (`panel/public/dashboard/*.css`), bornée à
la page ; les couleurs écrites en dur remplacées par les jetons ; les tests (`php shared/bin/tests_noms_globaux.php`,
`php shared/bin/test_chargement.php`, `php panel/bin/tests_*.php`) ; une capture avant / après, 1440 × 900, faite sur le
vrai dashboard servi en local avec la fausse API du tournage (`panel/tools/videos/mock.js`, « Magasin démo ») ; un commit.

| Page | Fait le | Ce qui change |
|---|---|---|
| Opérationnel | 28/11/2026 | cadre `.op-ds` : titres des cartes en h2 du §7.2 ; frise des jours en choix du §7.6 ; le résumé du jour comme la Semaine (jauge, attendu à l'heure, « Reste … pour l'objectif ») ; « Ta place dans le réseau » en cartes blanches, rang en étiquette neutre ; jauges réalisé / J−7 / objectif aux couleurs de graphique (le pro suit le comptoir) ; **la jauge des commandes par canal gardée**, plus haute, ses couleurs d'identification en propriétés (`--canal-c`, `--canal-k`, `--canal-w`) ; les vues en sélecteurs segmentés ; le duel à l'encre, l'écart avec son mot ; filets pleins ; vitrine S / M / L en cases neutres ; contrôles en photo en cartes blanches, la zone en point |
| Commandes | 28/11/2026 | déjà au système (26/11) : tailles hors échelle remises (17 → 18 px, 9 et 9,5 → 10 px) ; les couleurs des canaux lues dans `.pco` (`--pco-web`, `--pco-comp`, `--pco-bur`), une seule source au lieu de deux. La bascule Liste / Tournées reste à l'Abricot (décision de Sam, 24/11). |
| Tâches | 28/11/2026 | les non-conformités à l'arrêt (`--stop`), plus au bordeaux de la marque (§3 : bordeaux ≠ rouge d'arrêt) ; la note 5 « Exemplaire » en vert comme 4, son mot la distingue (plus d'or à part, §7.13) ; couleurs en dur → jetons ; plus de pilule (notes, compteur des photos) ; filets pleins ; tailles dans l'échelle (38 → 26, 13,5 → 13, 9 et 9,5 → 10, 20 → 21 px). |
