# L'épicerie depuis le PDF d'un fournisseur : la simulation (v2)

**Statut : codé** (bouton « ＋ Épicerie depuis un PDF » de Produits › Gamme · scoring, `src/produits_import.php` ;
voir `docs/contrat-api.md`, section `/produits/import/*`). Simulation du 27/10/2026, après les décisions du jour : prix de
vente maximum commun à tous les magasins, EAN et TVA au produit, activation par les périodes, fiche FR/NL, allergènes au
clic.

Le document, `Tarif-Comptoir-des-Saveurs.pdf`, est le tarif d'un fournisseur **fictif** (deux pages : tarif, puis
fiches avec ingrédients) : un article déjà vendu par le réseau, une matière déjà connue sous la même référence
fournisseur, un prix à l'unité seul, un article sans prix conseillé, un code-barres à la clé fausse, un coffret à la TVA
douteuse, vendu à Noël seulement.

| Capture | Ce qu'elle montre |
|---|---|
| `0-le-tarif-pdf.png` | Le tarif déposé (page 1). |
| `1-le-bouton.png` | Le bouton dans la barre d'outils de l'écran Produits. |
| `2-etape-fournisseur.png` | Le fournisseur reconnu par son n° de TVA ; la date du tarif, unique pour le réseau. |
| `3-etape-articles-matieres.png` | Coût de la pièce ; matières reprises ou nouvelles ; le sirop bloque sans catégorie matière. |
| `4-etape-prix-de-vente.png` | Prix maximum (tous magasins), TVA, coefficient face à la cible, prix cible en un clic. |
| `5a-fiches-a-completer.png` | Les fiches à la lecture : néerlandais manquant, code-barres faux (miel), allergènes à confirmer. |
| `5b-fiches-completees.png` | Après « Traduire » (marqué « à relire »), EAN corrigé, « aucun allergène » confirmé, coffret en période de Noël. |
| `6-validation-aujourdhui.png` | **Aujourd'hui** : validé et signé ; TFBuddy n'a aucune route d'écriture — le CSV prend le relais. |
| `7-cree-apres-ouverture.png` | **Le jour où TFBuddy ouvre `POST /products/resale`** : neuf chaînes créées. |

Ce qui est simulé : la lecture du PDF et la traduction par Claude (réponses fixées d'avance, conformes au document) et
l'API TFBuddy (un faux serveur qui répond comme le Swagger, puis comme il répondrait avec la route ouverte). L'adresse
affichée est celle de production. Tout le reste est le code réel du cockpit, piloté dans Chromium.
