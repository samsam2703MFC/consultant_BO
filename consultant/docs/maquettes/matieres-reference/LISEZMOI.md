# Recettes à corriger › Matières premières : le prix de référence fournisseur, en liste déroulante (maquette)

**Statut : codé le 14/11/2026** (« ok code-le et mets en ligne »). Serveur : `src/verification_matieres_prix.php`,
`GET /verification/recettes/matieres` ; écriture : le geste `prix` des corrections, étendu à plusieurs magasins et
annulable quand un prix en remplace un autre ; écran : `verification-prix-vals.js`, `verification-prix-tpl.js` (style
`vm-` dans `verification.css`) ; tests : `php bin/tests_verification_correction.php` (partie « matières premières »).
Écart à la maquette : la liste déroulante est une liste native (un choix, puis, s'il le faut, le poids d'une pièce ou
le prix saisi juste dessous).

Demande du 14/11/2026 : « en bascule, un tableau avec toutes les matières premières utilisées dans les recettes, où
je peux adapter avec le prix de référence fournisseur (liste déroulante) ».

## L'écran (`a.png`, et `b.png` : la liste déroulante de près)

- **La bascule**, en tête de « Recettes à corriger » : *Recettes à corriger* | *Matières premières*.
- **Cinq repères** : matières dans les recettes vendues, sans prix dans un magasin, centrale sans liste de prix,
  écart de plus de 10 % à la référence, unité à vérifier ; les mêmes en pastilles pour filtrer, plus une recherche.
- **Une ligne par matière** : sa catégorie, sa source (centrale ou libre), son unité ; les recettes qui l'emploient et
  leur CA sur 30 jours ; **son prix dans chaque magasin** (✓ égal à la référence, orange s'il s'en écarte, rouge
  « sans prix », vert « à écrire » quand un prix est choisi).
- **Le prix de référence, en liste déroulante** : la liste de prix **en vigueur** d'un fournisseur lié à la matière
  au panel (prix du colis ÷ taille du colis, daté), le prix médian des autres magasins, un prix saisi, ou laisser sans
  prix. Quand le colis et la matière ne se comptent pas dans la même unité, la conversion se pose dans la liste
  (Poche Sauce Tikka : colis de 4 poches chez Berdiff, matière en grammes → poids d'une poche).
- **Appliquer à** : un magasin, les magasins sans prix, ou tous.
- **L'effet sur les recettes** sous la ligne choisie : le coût de la pièce avant / après dans le magasin.
- **Une barre en bas** : les prix choisis, puis « Vérifier ce qui sera écrit » et « Appliquer » — le même chemin que
  les corrections de recettes (écrit au panel, relu, au registre, « Annuler »).

## Ce qui est réel, ce qui est illustratif

Lu en ligne le 14/11/2026 par `/analyse/matieres/fiche` (lecture seule) : les noms, catégories, sources et unités des
matières ; les prix des magasins (Épices poulet 12,90 €/kg partout ; Fondant chocolat, Sucre, Oignons grillés, Poulet
effiloché sans prix à Halle seulement ; Mayonnaise, Houmous, iceberg sans prix nulle part) ; les fournisseurs (Berdiff
s.a. et SLFood pour la centrale, Bruyerre S.A. en libre) et leurs listes de prix (Poche Sauce Tikka 10,83 € les 4,
Frangipane & Abricots 96,22 € les 10, Cougnou 57,78 € les 24) ; la Boîte pâtissière comptée en grammes (188,60 €/kg).
Le compteur « sans prix » (75) est celui de la liste du 13/11. **Illustratifs** : les autres compteurs, le nombre de
recettes et le CA par matière, le poids d'une poche (500 g), le coût avant / après du Pain au Chocolat Nappé.

## Régénérer

```
php -S 127.0.0.1:8099 -t .        # depuis pilotage-reseau/
node docs/maquettes/matieres-reference/generer.js
node docs/maquettes/matieres-reference/capturer.js
```
