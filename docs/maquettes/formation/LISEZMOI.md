# Formation — les SOP dans `/consulant_bo/formation/` (maquettes, 05/11/2026)

Intégrer toutes les SOP du dossier Drive « L’Atelier Halle — SOP — dernières versions retrouvées »
(44 fichiers → **25 SOP**, PDF et DOCX réunis, 402 étapes) dans un module `/formation/`, à côté du
cockpit et de l’App worker (`/consulant_bo/employee/`).

Le principe : **le Drive reste la source**. Le module relit le dossier, découpe chaque SOP en étapes et
en points critiques. L’équipe lit les SOP pas à pas sur la tablette du poste ou au téléphone dans l’App
worker, puis répond à 3 questions. La responsable ou le consultant valide « vu faire » sur place.

Sur design system (`/public/assets/ds/global.css`). **SOP réelles** : titres, versions, langues,
étapes, points critiques, quiz et écarts entre documents, lus le 05/11/2026. Les traductions FR des
documents NL sont des traductions de travail. **L’équipe de Halle, sa progression et les dates sont
fictives.**

| Fichier | Écran | Ce qu’il montre |
|---|---|---|
| `planche.png` | Planche | les 6 écrans et les 9 points à valider |
| `a-bibliotheque.png` | A — Bibliothèque | les 25 SOP en 4 familles (comme le Drive), filtres poste / moment / langue, l’équipe face à chaque SOP, « À valider » |
| `b-fiche-sop.png` | B — Fiche SOP | Ouverture du magasin (NL v5) : étapes, original NL et traduction FR, photos de la caisse, points critiques, PDF, ce qui a été relevé à la lecture, équipe, quiz |
| `c-tablette.png` | C — Pas à pas (tablette 1280 × 800) | Ouverture du poste et premières cuissons (FR v1.1), étape 7 : une étape par écran, point critique, repères, « j’appelle si » |
| `d-telephone.png` | D — Téléphone (App worker) | onglet Formation : mon parcours, le quiz, « il reste à être vue faire » |
| `e-equipes.png` | E — Équipes | matrice personnes × SOP (lu, quiz, vu faire, à revoir), validations à donner sur place, le fil |
| `f-sources.png` | F — Sources et versions | le dossier Drive SOP par SOP, **7 écarts entre SOP à trancher**, comment une SOP arrive, relecture du quiz, hors collection |

## À valider avant de coder

1. Le Drive reste la source ? (proposé : oui)
2. Étapes structurées + PDF à un clic, ou PDF seul ?
3. Langues : original + traduction de travail signalée (6 SOP NL seulement, 17 FR seulement, 2 FR + NL) ?
4. Où l’équipe lit : tablette du poste et/ou App worker (suivi par personne) ?
5. Qui valide « vu faire » : responsable, consultant, les deux ?
6. Cuisson des baguettes et pains v1.0 « À VALIDER » : visible en lecture seule ou cachée ?
7. Périmètre : Halle d’abord, ou tout le réseau ?
8. Les 7 écarts entre SOP (écran F) : qui tranche, dans le Drive ?
9. Cockpit : entrée « Formation ↗ » dans le rail, taux « vu faire » dans la fiche franchisé et le cadre de visite ?

## Fichiers

- `sops.json` — les SOP lues dans le Drive (sections, étapes, points critiques, quiz, remarques, texte
  complet de 3 SOP) ; `photos/` — les 8 photos de la caisse et 3 pages de l’Ouverture du magasin v5.
- `preparer.js` + `preparer-detail.js` → `donnees.js` (SOP + progression fictive de l’équipe).
- `coque.js` (en-tête, onglets), `fo.css` (styles des maquettes), `capturer.cjs` (captures Playwright).

Régénérer : `node docs/maquettes/formation/preparer.js`, puis un serveur à la racine du dépôt
(`php -S 127.0.0.1:8099 -t .`) et `node docs/maquettes/formation/capturer.cjs`.
