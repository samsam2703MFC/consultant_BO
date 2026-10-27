# L'épicerie depuis le PDF d'un fournisseur : la simulation

**Statut : codé** (bouton « ＋ Épicerie depuis un PDF » de Produits › Gamme · scoring, `src/produits_import.php` ;
voir `docs/contrat-api.md`, section `/produits/import/*`). Simulation du 27/10/2026.

Le document, `Tarif-Comptoir-des-Saveurs.pdf`, est le tarif d'un fournisseur **fictif** : dix articles, dont un déjà
vendu par le réseau (« Fiesta Arabica »), un dont la matière existe déjà sous la même référence fournisseur, un sans
prix de colis (prix à l'unité seul), un sans prix conseillé, un à la TVA douteuse.

| Capture | Ce qu'elle montre |
|---|---|
| `1-le-tarif-pdf.png` | Le tarif déposé. |
| `2-le-bouton.png` | Le bouton dans la barre d'outils de l'écran Produits. |
| `3-etape-fournisseur.png` | Le fournisseur du PDF, reconnu dans TFBuddy par son n° de TVA (#6) ; la date du tarif. |
| `4-etape-articles-matieres.png` | Coût de la pièce ; matières reprises (par la référence, par le nom) ou nouvelles ; le sirop bloque tant qu'il n'a pas de catégorie matière. |
| `5-etape-produits.png` | Produits de vente : coefficient face à la cible de la catégorie, prix pour la cible appliqué en un clic (spéculoos). |
| `6-etape-validation.png` | Récapitulatif, plus de bloquant, sept points à regarder ; le bouton « Valider ». |
| `7-valide-aujourdhui.png` | **Aujourd'hui** : validé et signé ; « Vérifier l'API » constate que TFBuddy n'a aucune route d'écriture — le CSV prend le relais. |
| `8-cree-apres-ouverture.png` | **Le jour où TFBuddy ouvre `POST /products/resale`** : neuf chaînes créées, produit par produit. |

Ce qui est simulé : la lecture du PDF par Claude (réponse fixée d'avance, conforme au document) et l'API TFBuddy (un
faux serveur qui répond comme le Swagger, puis comme il répondrait avec la route ouverte). L'adresse affichée est celle
de production. Tout le reste est le code réel du cockpit, piloté dans Chromium.
