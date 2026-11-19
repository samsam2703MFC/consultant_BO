# Formation — les standards du réseau dans `/consulant_bo/formation/` (maquettes v2, 05/11/2026)

Les 25 SOP du Drive « L’Atelier Halle — SOP — dernières versions retrouvées » (44 fichiers, 402 étapes)
sont **reprises et rangées dans nos bases**, qui deviennent la source. Chacune existe **en FR et en NL**,
étape par étape, avec son PDF dans les deux langues ; ensemble elles forment le **Manuel opératoire**.
L’équipe les lit **sur la tablette du poste** (onglet SOP de l’app Cuisine `pwa_kitchen`, et au comptoir) :
son nom, son PIN, le pas à pas, le quiz. La **responsable du magasin** valide « vu faire » et la personne
acquiert la **compétence**. Les standards valent pour **tout le réseau**.

SOP, étapes, points critiques, quiz et écarts : réels (lus le 05/11/2026). Le NL de l’écran C2 et de
l’éditeur (F) est une traduction faite pour la maquette, à relire. **Équipe de Halle, progression,
chiffres des autres magasins : fictifs.** Noms des 14 compétences : proposés.

| Fichier | Écran |
|---|---|
| `planche.png` | les 8 écrans, ce qui est décidé, ce qui reste à confirmer |
| `a-bibliotheque.png` | A — Standards : 25 SOP en 4 familles, FR et NL (traduction à relire signalée), compétence, progression réseau |
| `b-fiche-sop.png` | B — Fiche SOP : Ouverture du magasin, FR et NL côte à côte, photos, PDF FR · NL, Manuel, écart tranché, équipe, quiz |
| `c1-tablette-qui.png` | C1 — Tablette, onglet SOP de l’app Cuisine : SOP du poste par compétence, « Qui lit ? » nom + PIN |
| `c2-tablette-etape.png` | C2 — Tablette, pas à pas en néerlandais (Tom W.) ; l’écart tranché dans le texte |
| `c3-tablette-validation.png` | C3 — Tablette, quiz réussi ; la responsable valide « vu faire » avec son PIN → compétence acquise |
| `d-manuel.png` | D — Manuel opératoire : sommaire, PDF complet FR et NL, la même SOP dans les deux langues |
| `e-competences.png` | E — Compétences : réseau × compétences, Halle personne × compétence, à voir faire par la responsable |
| `f-redaction.png` | F — Rédaction et versions : SOP en base, FR / NL relues ou à relire, 7 écarts tranchés, modifier et publier |

## Décidé le 05/11

1. Nos bases sont la source ; le Drive devient l’archive.
2. Étapes structurées + PDF FR / NL généré ; les SOP vivent dans Formation et dans le Manuel opératoire.
3. Tout en FR et en NL, y compris tout le Manuel opératoire.
4. Lecture sur la tablette du poste (nom + PIN, comme les checklists de l’app Cuisine).
5. « Vu faire » validé par la responsable du magasin.
6. Cuisson des baguettes et pains « À VALIDER » : laissée visible, sans validation.
7. Standards valables pour tout le réseau.
8. Écarts : monnayeur automatique (pas de comptage de caisse) · recuisson du matin pour la catégorie pain
   seulement (deux produits différents) · la SOP Flans v1.1 fait foi (Fiche programmes corrigée) ·
   nettoyage des fours après 16 h.
9. Couplé aux compétences à acquérir.

## Encore à confirmer

- Les 14 compétences : reprendre celles du panel (fiche technique › compétences requises) ou garder celles-ci ?
- Qui relit les 19 traductions faites à la reprise (un néerlandophone, un francophone) ?
- Écarts 5 à 7, proposés : checklist de fermeture alignée sur la v1.3 ; traduction de la Fermeture refaite ;
  Recuisson de l’après-midi rangée en 01 avec un seul code.
- Fermeture v1.3 : l’étape « compter la caisse » est retirée (monnayeur) — à confirmer.
- Le branchement avec le Manuel Opératoire du panel (table `formation` de `franchise_buddy_db`, structure inconnue ici).

## Fichiers

`sops.json` (SOP lues dans le Drive), `photos/`, `preparer.js` + `preparer-detail.js` → `donnees.js`,
`coque.js` (cockpit), `tablette.js` (tablette), `fo.css`, `capturer.cjs`.
Régénérer : `node docs/maquettes/formation/preparer.js`, serveur à la racine (`php -S 127.0.0.1:8099 -t .`),
puis `node docs/maquettes/formation/capturer.cjs`.
