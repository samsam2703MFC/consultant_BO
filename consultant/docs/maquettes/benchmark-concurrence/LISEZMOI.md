# Dashboard magasin — onglet « Concurrence » (maquette)

Nouvel onglet du dashboard magasin, après « Plan d'action » : le benchmark saisonnier du
magasin face à ses concurrents, une famille à la fois (Cougnou / Galette des rois).

| Fichier | Contenu |
|---|---|
| `cougnou.jpg`, `cougnou.html` | Halle, cougnou, comparé au kilo |
| `galette.jpg`, `galette.html` | Halle, galette des rois, prix ramené à notre Ø 24 cm à surface égale |

De haut en bas :
1. **Six tuiles** : notre prix comparable, la médiane des supermarchés, des artisans et des
   chaînes (avec notre écart), le nombre de concurrents moins chers, le score de visibilité web.
2. **L'échelle** : une puce par relevé (supermarché, artisan, chaîne), notre prix en trait bordeaux.
3. **Les concurrents du magasin** : supermarchés nationaux et boulangeries proches, prix, taille,
   prix comparable, écart, source. Le bouton « + Relevé terrain » crée une observation dans le
   module Benchmark de TFBuddy.
4. **Visibilité web** (contrôles relus par la routine) et **À faire** (bouton « → Plan d'action »).

**Réel.** Les relevés du 7/11/2026 (`rapport_*.json`, produits par `php bin/benchmark.php
rapport …`), nos prix, les contrôles web lus sur atelierby.be, et la coque du dashboard
(`../canaux-offres/contexte-dashboard.html`).

**Données, une fois codé.** `GET /api/cockpit/benchmarks/{cougnou|galette}/rapport`, filtré
sur `magasins` contient le shop. Les prix viendront de l'API dès que les relevés seront posés.

Pour régénérer les captures, lancer un serveur statique sur le port 8099 depuis la racine,
puis :

```
node pilotage-reseau/docs/maquettes/benchmark-concurrence/generer.js
```

**Codé (07/11/2026)** : `public/dashboard/dashboard.js` (onglet `vue=concurrence`, `rendConcurrence`), avec en
plus la recherche d'un produit du catalogue du magasin (« Aller chercher un produit… ») et le formulaire
« + Relevé terrain ». Serveur : `GET /benchmarks/magasin?shop=`, `POST /benchmarks/releve` (`src/benchmark.php`).
