# Les documents du programme commercial B2B (20/11/2026)

Au gabarit des documents de gestion, version A (`docs/DESIGN_SYSTEM.md` §13.4) :

| Fichier | Pour qui | Pages |
|---|---|---|
| `contrat-agent-commercial` | le commercial (agent commercial indépendant) et la marque | 4 (contrat, annexes : feuille de mission, magasins et zones, barème et exemple) |
| `feuille-de-mission` | le commercial | 1 |
| `explication-franchises` | les franchisés : comment ça marche, ce que ça coûte | 2 |
| `convention-franchise` | le franchisé et la marque : accord sur la zone, les échantillons, la commission du premier mois | 3 |

Les valeurs décidées viennent du portail Commercial (paliers 20 / 10 / 5 % sur douze mois, objectif de 8 500 € HT par mois,
20 % du premier mois facturés aux franchisés avec les royalties, échantillons « ÉCHANTILLON COMMERCIAL » offerts). Celles
qui ne le sont pas restent en blanc, à compléter à la signature (raison sociale et numéro d'entreprise de la marque,
minimum garanti, plafond des échantillons, délais, préavis…). Les contrats sont des projets, à faire relire par un juriste.

Modifier : le `.html`, puis `node commercial/docs/documents/rendre.cjs` (Chromium) refait les `.pdf` à côté.
