# Nouveaux produits depuis un PDF : la simulation

**Statut : codé** (bouton « ＋ Nouveaux produits (PDF) » de Produits › Gamme · scoring, `src/produits_import.php` ;
voir `docs/contrat-api.md`, section `/produits/import/*`). Simulation du 27/10/2026.

Le document déposé, `Gamme-fetes-2026.pdf`, est une fiche gamme fictive mais réaliste : dix nouveautés de fin d'année,
bilingues, une bûche déjà au catalogue, un prix écrit HTVA, une galette sans catégorie au référentiel. Les catégories
sont les vraies catégories du réseau (celles de Halle, lues le 09/10/2026).

| Capture | Ce qu'elle montre |
|---|---|
| `1-le-pdf.png` | Le PDF déposé (première page). |
| `2-le-bouton.png` | Le bouton dans la barre d'outils de l'écran Produits. |
| `3-relecture.png` | Le PDF lu : la bûche déjà au catalogue décochée, le TTC du pain d'épices déduit du prix HTVA, la galette « à choisir ». |
| `4-verification-aujourdhui.png` | **Aujourd'hui** : « Vérifier l'API » constate qu'aucune route n'accepte POST (Swagger du 27/10/2026 : *Products* en lecture seule). La création reste grisée, le CSV prend le relais. |
| `5-creation-apres-ouverture.png` | **Le jour où TFBuddy ouvre `POST /products`** (demande : `docs/spec-api-panel.md` §7) : neuf produits créés, inactifs, chacun avec son numéro TFBuddy ; la bûche existante n'est pas renvoyée. |

Ce qui est simulé : la lecture du PDF par Claude (réponse fixée d'avance, conforme au document) et l'API TFBuddy (un
faux serveur qui répond comme le Swagger, puis comme il répondrait avec la route ouverte). L'adresse affichée est celle
de production. Tout le reste est le code réel du cockpit, piloté dans Chromium.
