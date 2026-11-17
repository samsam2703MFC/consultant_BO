# Livret « Créer mon catalogue »

Le livret d'instructions du module **Mon catalogue** du dashboard magasin (`public/dashboard/catalogue.js`), pour
les franchisés : les cinq étapes de l'assistant, captures annotées à l'appui.

Le même contenu est dans le **manuel opératoire** (module Formation, tablette › Manuel › Franchisé, rubrique 05) :
article **0.0.52** « Le dashboard : l’onglet Mon catalogue », source `data/formation/dashboard/art-dash-catalogue.json`
(voir `docs/formation.md`). Une correction du livret se reporte dans l'article, et inversement.

| Fichier | Rôle |
|---|---|
| `livret-catalogue.pdf` | le livret, A4, 10 pages : c'est lui qu'on diffuse |
| `livret.html` | sa source : une `<section class="page">` par page A4 (design system, `docs/DESIGN_SYSTEM.md` §13) |
| `captures/` | les captures du dashboard en ligne, pastilles numérotées comprises (boutique Atelier By - Halle, 17/11/2026) |
| `outils/capture.js` | refait les captures, **en lecture seule** : toute requête autre qu'un GET vers l'API est refusée sur place |
| `outils/pdf.js` | recompose le PDF à partir de `livret.html` et signale une page qui déborde |

## Refaire le livret

Il faut Node et Playwright (son Chromium). Depuis ce dossier :

```bash
DASHBOARD_URL='http://…/consulant_bo/dashboard/?shop=4&vue=catalogue' node outils/capture.js captures
node outils/pdf.js
```

- Les captures se prennent à 1600 × 1000 (à 1440 px de large, les réglages d'une page de l'étape 3 se chevauchent).
- Le catalogue ouvert est le premier de la liste de la boutique ; les réglages faits pour les captures (titre,
  gamme cochée, titre de l'affiche) restent à l'écran et ne sont jamais enregistrés.
- Le lien et le QR de l'étape 5 sont des exemples (`catalogue.exemple`) : le script ne crée pas de lien sur le serveur.
- Quand le module change, refaire les captures **et relire le texte** du livret : il décrit le module du 17/11/2026.
