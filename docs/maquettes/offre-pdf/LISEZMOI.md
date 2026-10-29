# Le devis des magasins aux couleurs de L'Atelier by — trois propositions

**Statut : C2 retenue le 29/10/2026. L'intégration est prête dans
[`integration/`](integration/LISEZMOI.md) ; rien n'est encore branché dans le panel.**

La demande : refaire le visuel des offres (devis) que les magasins envoient à
leurs clients, produites par `OfferService::getPDF()` (panel, TCPDF). Le
document porte encore le thème **or #CD9B36 / anthracite #3F4648** de
l'ancienne identité, en DejaVu Sans. Les trois propositions reprennent la
charte du design system (`public/assets/ds/global.css`) : **rubis #8D1D2C,
abricot #F2C9A0, beige #EAE4DC, Gotham (texte) et Vank (titres)**.

Elles ne sont pas des maquettes HTML : elles sont **dessinées par TCPDF**,
le moteur du service. Ce que montrent les JPG et les PDF est donc ce que le
service produira, au millimètre, et chaque proposition est une classe prête
à brancher (voir « Brancher la proposition retenue »).

| | Proposition | Fichiers |
|---|---|---|
| — | **Aujourd'hui** : le code actuel recopié tel quel, sur la même offre | `avant.php`, `avant.pdf`, `avant.jpg` |
| A | **Épurée** : le même document, mêmes blocs, mêmes huit colonnes, réimprimé dans la charte (blanc, rubis seul, Gotham, Vank) | `proposition-a.php`, `a.pdf`, `a.jpg` |
| B | **Bandeau rubis** : bandeau et logo blanc, trois cartes (magasin, client, jour J), tableau à six colonnes, encadré du total à payer, bon pour accord, pied beige avec l'IBAN | `proposition-b.php`, `b.pdf`, `b.jpg` |
| C1 | **Carnet d'événement — le volet en cartes** : à gauche une pile de cartes arrondies (jour J en rubis, lieu, client, magasin, règlement), à droite la sélection, la remarque et le total, les conditions, le bon pour accord | `proposition-c.php`, `c1.pdf`, `c1.jpg` |
| C2 | **Carnet d'événement — une colonne** : une grande carte beige (date en tuile, titre, mode), trois cartes, la sélection dans une carte, remarque et total, conditions et bon pour accord | `proposition-c.php`, `c2.pdf`, `c2.jpg` |
| C3 | **Carnet d'événement — une carte par produit** : titre et pastilles, trois cartes, chaque produit dans sa carte (quantité en pastille), total sur une bande rubis, remarque / conditions / bon pour accord en trois cartes | `proposition-c.php`, `c3.pdf`, `c3.jpg` |
| | Les planches : les quatre côte à côte (avant, A, B, C) ; les trois versions de C et leurs variantes | `planche.jpg`, `planche-c.jpg` |

C a été refaite en trois versions plus simples, tout en rectangles aux coins
arrondis (rayon 3 mm). La première version (volet beige pleine hauteur, ligne
manuscrite, pointillés) reste dans l'historique : commit `fec7743`.

Chaque proposition existe aussi en **retrait en magasin** (`*-retrait.jpg`) et
en **offre de 30 lignes** (`*-long.pdf`, `*-long-N.jpg`) : les pages suivantes
gardent leur décor (dessiné dans `Header()`/`Footer()`) et le tableau y reprend
son en-tête.

## L'offre d'exemple — illustrée

`donnees.php` : un lunch d'entreprise livré (« Séminaire d'équipe — lunch pour
40 personnes », jeudi 12/11/2026), sept lignes dont trois remisées (−10 %,
−5 %, −6,00 €), TVA 6 %, livraison 15,00 €, une remarque du client. **Le
client, les adresses, la TVA, l'IBAN et les montants sont inventés** ; seule la
forme des données est celle du service (`getById($id, true, true)` et
`ShopRepository::getById()`).

## Ce que le rendu d'aujourd'hui montre

Constaté sur `avant.jpg` / `avant-long-*.jpg`. Les trois propositions corrigent
les cinq premiers points ; le dernier, seulement B et C :

- **`htmlspecialchars()` dans un `Cell()`** (`renderOfferTitle`) : le nom de
  l'offre s'imprime « Séminaire d&amp;#039;équipe ». `Cell()` n'interprète pas
  le HTML : toute apostrophe, tout « & » s'affiche en code.
- Un nom de produit long **déborde sur les colonnes Qté et P.U.** (`Cell()` ne
  coupe pas).
- Montants à l'anglaise (`1.20€`, `584.02€`) ; « 0% » sur les lignes sans
  remise.
- Deux colonnes « Total (brut) » : la 4ᵉ est le total avant remise, la 8ᵉ le
  total TVAC après remise.
- Le pied (`SetY(-35)`) **se pose sur le commentaire** dès que la page est
  pleine ; en page 2 le tableau **repart sans en-tête**.
- Les frais de livraison ne sont dans **aucun total**.

## Les trois en bref

**A — Épurée.** Le plus petit pas : pas un montant de plus ni de moins, les
blocs dans le même ordre. En-têtes explicites (avant remise, après remise,
HTVA, TVAC), montants alignés à droite au format belge, « — » sans remise,
« dont TVA » sous le total général. Le moins d'encre. *Limites* : huit colonnes
restent denses ; ni total à payer livraison comprise, ni bon pour accord ; la
marque reste discrète.

**B — Bandeau rubis.** Reconnaissable au premier coup d'œil. Six colonnes : le
prix unitaire barré puis remisé et la pastille de remise disent à eux seuls
« avant / après remise » (le total avant remise = quantité × prix barré). Total
à payer **livraison comprise**, case « Bon pour accord » à signer et renvoyer,
coordonnées légales et IBAN au pied de chaque page. *Limites* : bandeau et
encadré pleins (encre) ; 16 libellés nouveaux.

**C — Carnet d'événement, trois versions.** Le plus « traiteur » : la date
d'abord ; une ligne par produit (quantité × prix barré/remisé, remise en
pastille, TVAC et HTVA dessous) ; la remarque du client mise en avant ; total à
payer et bon pour accord comme B. Tout en rectangles arrondis, un seul trait
`OffreCarnet` pour ce que les trois partagent. *Limites communes* : le plus loin
du document actuel ; moins de produits par page (30 lignes : 3 pages, contre 2
pour A et B).

- **C1 — le volet en cartes.** La plus proche de la première version, sans
  l'aplat ; la plus de marge sur la page. *Limite* : colonne de droite plus
  étroite, les noms longs passent sur deux lignes.
- **C2 — une colonne.** Se lit de haut en bas : quand, pour qui, où, quoi,
  combien ; les noms tiennent sur une ligne. *Limite* : la plus juste en
  hauteur — dès 8 produits environ, la dernière carte passe en page 2.
- **C3 — une carte par produit.** La plus lisible ligne à ligne, la quantité en
  pastille, le total sur une bande rubis. *Limite* : une offre longue empile
  beaucoup de cartes ; remarque et conditions en colonnes étroites.

## À trancher avant de brancher

1. **Les frais de livraison sont-ils TVAC ?** B et C les ajoutent tels quels au
   « Total à payer » (A les laisse à part, comme aujourd'hui).
2. **La remise en montant** (`id_discount_type` ≠ 1) porte-t-elle sur la ligne
   ou sur l'unité ? L'exemple la prend sur la ligne ; l'affichage (« −6,00 € »)
   reste le même qu'aujourd'hui, l'ambiguïté aussi.
3. **« Émise le »** : le document n'a pas de date d'émission ; les propositions
   prennent la date de génération. Si l'offre porte un `created_at`, le
   prendre.
4. **Le nom commercial du magasin** (« Atelier by - Halle ») : lu dans
   `shop.name`, à défaut `representative_name`.
5. **« À l'attention de »** : pour un client B2B, le prénom et le nom du client
   s'ils sont remplis.
6. Une **date de validité** de l'offre (« valable jusqu'au … ») manque aux
   données : un champ à ajouter si on la veut.

## Brancher la proposition retenue (dans le panel)

C2 retenue : tout est dans [`integration/`](integration/LISEZMOI.md) — la
classe `OfferPdf` (un seul fichier), `OfferService.php` modifié, les polices à
poser, les quinze libellés en fr / nl / en, et `verifier.php` qui fait tourner
le service modifié de bout en bout.

## Régénérer

```bash
# TCPDF, une fois, dans un dossier de travail
composer require tecnickcom/tcpdf
# les devis (PDF + JPG, pdftoppm de poppler-utils)
TCPDF_AUTOLOAD=/chemin/vendor/autoload.php php docs/maquettes/offre-pdf/generer.php
# les planches : serveur statique sur 8099 depuis la racine, puis
node docs/maquettes/offre-pdf/planche.js
```

`php generer.php b` ne refait que B (`c1`, `c2`, `c3` pour C). L'horodatage est figé au 29/10/2026 10:42
(sauf « avant », dont le code lit l'heure courante).
