# SMS des formulaires bureaux — plan

> Exécution en ligne (superpowers:executing-plans), TDD.

**Spec :** `docs/superpowers/specs/2026-11-28-sms-formulaires-bureaux-design.md`

### Task 1 : le module serveur (`panel/src/sms_formulaires.php`, tests `panel/bin/tests_sms_formulaires.php`)
- `smsfTexte(string $magasin, string $quoi, array $d): string` — ASCII, ≤ 160, `quoi` = inscription | hors_zone.
- `smsfDestinataires(array $magasin, array $siege): array` — numéros des réglages allumés, au format +32…, sans doublon.
- `smsfEnvoyerMaintenant(string $shop, string $quoi, array $d, callable $envoyer): array` — plafond 10/jour (table
  `panel_sms_formulaire_envoi`), un envoi par destinataire, la trace, le journal → {envoyes, echecs, plafond}.
- `smsfNotifier(string $shop, string $quoi, array $d): void` — la même chose après la réponse.
- Routes : `GET /dashboard/sms-formulaires?shop=`, `POST /dashboard/sms-formulaires`, `POST /dashboard/sms-formulaires/essai`.
- Tests : texte (ASCII, longueur, pas d'e-mail), destinataires (éteint, invalide, doublon), plafond, siège seul pour le
  numéro du siège, numéro invalide refusé.
### Task 2 : les déclencheurs (`bureaux_dev.php`) et le routeur (`shared/public/api/index.php`)
- Inscription (`bdvPublic`) et hors zone (insertion de `bdvHorsZoneEnregistrer`) appellent `smsfNotifier`.
### Task 3 : l'écran (dashboard › Paramètres), la doc, la livraison
- Groupe « Alertes SMS », détail `smsf` ; vérification Chromium (fausse API des vidéos) ; contrat d'API, LISEZMOI,
  CLAUDE.md, index des API, `deploy.trigger`.
