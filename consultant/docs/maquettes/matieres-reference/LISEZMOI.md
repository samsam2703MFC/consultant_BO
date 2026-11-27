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

## Le troisième affichage : « Références et pertes (réseau) » (26/11/2026)

Demande du 26/11/2026 : « mettre tous les prix moyens Belgique au kg, et les % de perte ; d'abord sur la Tagliata,
ensuite sur tous les autres produits ». Serveur : `src/verification_references.php` ; écran :
`verification-refs-vals.js`, `verification-refs-tpl.js` ; contrat : `shared/docs/contrat-api.md`
(`/verification/recettes/references`). Pas de maquette.

- **Où** : Recettes à corriger › Matières premières, troisième bouton de l'affichage (après *Par fournisseur ou
  catégorie* et *Toutes les matières, prix de référence au choix*). Lien direct :
  `#/verification/recettes?vue=refs&recette=tagliata` (le nom ou le numéro de la recette ; sans `recette`, l'affichage
  s'ouvre vide).
- **En tête** : une recherche (« Recette : son nom ou son numéro »), « Ouvrir la recette », « Toute la carte » (les
  recettes déjà lues), « Relire ». Plusieurs recettes portent le nom : l'écran les propose en pastilles.
- **Six repères** : Matières, À changer (la proposition diffère du panel), Prix de marché (aucun magasin ne la chiffre),
  Sans prix (ni magasin ni prix de marché : à saisir), Pertes à poser, Prix à aligner (un magasin paie autrement que la
  référence).
- **Une ligne par matière** (sous-recettes dépliées) : sa catégorie, centrale ou libre, son unité ; où elle est
  employée ; son prix dans chaque magasin ; sa référence et sa perte au panel ; **le prix de référence proposé** (la
  moyenne des magasins qui la chiffrent par leur propre prix, local ou fournisseur ; sinon un prix de marché belge
  estimé, fichier `src/data/prix_reference_be.json`, daté) et **la perte proposée** (celle du fichier, sinon une perte
  courante de la famille de matière : herbes 30 %, oignons et échalotes 10 %… ; les produits transformés n'en reçoivent
  pas). Les deux restent modifiables ; une ligne cochée part.
- **L'écriture** : « Vérifier ce qui sera écrit » puis « Appliquer au panel » — `PATCH /materials/{id}` (prix de
  référence et perte, pour tout le réseau), par lots de 20, relu, noté au registre des corrections (« Annuler ») ; puis
  le food cost des recettes touchées, relu avant et après dans chaque magasin. Les prix d'achat des magasins ne sont pas
  touchés : au panel, le prix d'un magasin passe avant la référence. Case « avec le compte administrateur du cockpit ».
- **« Même food cost dans tous les magasins »** (case, 26/11 au soir) : le prix propre d'un magasin qui s'écarte de la
  référence la reprend (`POST /materials/{id}/manual-price`, au registre).
- **« Pertes sur les lignes — toute la carte »** : le coût d'une recette ne compte que la perte posée sur sa ligne
  (mesuré au panel sur la Tagliata) ; ce bloc pose, dans toutes les recettes et sous-recettes de la carte, la perte que
  porte chaque matière. « Vérifier (15 premières recettes) », puis « Appliquer à toute la carte », par tranches de 15,
  chaque recette écrite entière, relue, au registre.
- **« Tout faire, toute la carte »** : en un clic, les matières cochées (prix, pertes, alignement si coché), puis les
  pertes sur les lignes ; l'avancement s'affiche (« 1/2 · Prix, pertes et alignement : lot 3 sur 5 »…). Chaque écriture
  s'annule depuis le registre.
