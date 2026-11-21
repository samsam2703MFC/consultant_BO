# Relevé de compensation mensuel — Halle (maquette)

**Statut : maquette du 21/11/2026, version C retenue, à valider. Rien n'est codé.**

Demandes du 21/11/2026 :
1. adapter au magasin de Halle le règlement de Żabka : les recettes du jour vont à la centrale, une compensation
   mensuelle déduit les achats et les redevances, la centrale verse le solde vers le 10 ;
2. « fais la maquette du relevé mensuel de Halle » : trois mises en page montrées (A Pièces, B Facture, C Cascade) ;
3. « la version C, mettre une facture de marchandises de la centrale à 40 %, reverser au franchisé le 10 la marge,
   et faire ensuite une page avec le détail approximatif du franchisé : marge, personnel, loyer ».

Les versions A et B, et la première version de C (recettes gardées par le franchisé, prélèvement par domiciliation
B2B), sont dans l'historique Git (commit `7dcbc2f`).

## Le modèle maquetté

- La centrale **encaisse les recettes** du magasin. Les cartes, les titres-repas et Payconiq lui sont versés
  directement. Les espèces lui sont versées chaque jour.
- Le **5 du mois suivant**, elle émet le relevé. Elle déduit la **facture de marchandises** (les livraisons du mois
  au tarif de la centrale, posées à 40 % du CA HT), les **redevances** (5 %) et l'**abonnement** TFBuddy. Elle
  ajoute ce qu'elle doit au magasin : avoirs, prime aux standards.
- Le **10**, elle **verse le solde** : la marge du franchisé. Un solde négatif fait l'objet d'une facture de
  régularisation.

## Les pages

`releve.png` (français) et `releve-nl.png` (néerlandais) : les trois pages dans le cadre de présentation.
`releve-halle-2026-09.pdf` et `releve-halle-2026-09-nl.pdf` : le document seul, en A4, tel qu'il partirait.

| Page | Ce qu'elle montre | Septembre 2026 |
|---|---|---|
| 1. La compensation | des recettes du mois (TTC) à la marge versée le 10, la part de la centrale en barre | recettes 53 900,74 € ; marchandises 21 560,29 € ; redevances 3 076,43 € ; abonnement 119,79 € ; avoir 48,97 € ; **versé le 10/10 : 29 193,20 €** (54,2 % des recettes) |
| 2. Votre mois, en approximation | versé − TVA à reverser = marge HT − personnel − loyer = reste − autres frais = résultat estimé ; « sur 100 € de ventes HT » | TVA nette 1 278,65 € ; marge HT 27 914,55 € (54,9 %) ; personnel 9 477,50 € ; loyer 3 559,48 € ; reste 14 877,57 € (29,3 %) ; autres frais 6 864,73 € ; **résultat estimé 8 012,84 €** (15,8 %) |
| 3. Les recettes et les espèces | les recettes par moyen de paiement, versées à la centrale ; les espèces encaissées face aux versements, semaine par semaine | cartes 86,4 %, titres-repas 6,3 %, espèces 7,2 %, Payconiq 0,1 % |

Gabarit des documents de gestion, version A (`docs/DESIGN_SYSTEM.md` §13.4). Le Ruby n'apparaît qu'au filet
d'en-tête, au montant versé et au filet de l'encadré.

## Les chiffres

- **Mesurés** (dossier investisseur `consultant/docs/dossiers/halle-2026-10`, lu le 09/10/2026) : chiffre
  d'affaires HT 50 849,75 €, 30 jours, 4 156 tickets, personnel 9 477,50 €, score du T3 12,09 / 20.
- **Posés** :
  - la facture de marchandises à 40 % du CA HT. Le panel mesurait la matière de Halle à 36,2 % du CA en septembre :
    si toute la matière passe par la centrale, elle y prend environ 3,8 points ;
  - les redevances, le loyer (7 %) et les autres frais (énergie 5 %, amortissements 5 %, comptabilité 1,5 %,
    entretien 1 %, assurances 1 %), selon le budget 2026 de Halle ;
  - le seuil de la prime aux standards (14 / 20), une hypothèse à décider.
- **Illustratifs**, marqués d'un astérisque sur les pages :
  - le TTC, à 6 % sur tout le chiffre ;
  - la répartition par moyen de paiement, celle de Corbais du 2 au 8/11/2026 (l'API TFBuddy refusait le jeton de
    la session de travail) ;
  - les espèces de chaque semaine, réparties selon la semaine type de Halle, et leurs versements ;
  - l'abonnement, l'avoir et les numéros de pièce.
- **Entre crochets** : les mentions qui se lisent dans l'ERP (raison sociale, adresses, numéros, IBAN).

Le dossier investisseur donnait 9 921,84 € de résultat opérationnel pour septembre. L'écart avec les 8 012,84 €
d'ici vient de la matière à 40 % au lieu de 36,2 %, des redevances, et des frais pris au modèle du budget plutôt
qu'aux 13 042,80 € encodés au panel.

## Avant de coder

| Ligne du relevé | Source | État |
|---|---|---|
| Recettes par moyen de paiement | export comptable du jour TFBuddy (`/shops/{id}/reports/sales/daily/cash-flow/csv`), lu par `consultant/src/caisse.php` | existe |
| Recettes reçues par la centrale | les relevés bancaires de son compte (CODA) | **manque** |
| Facture de marchandises | aucune API de commandes ni de factures fournisseurs (`ep_ca_cockpit`, « manquants ») | **manque** |
| Redevances | `fondsRoyaltiesCalcul()` (`shared/src/endpoints.php`) ; les taux de la fiche boutique ne sont remplis pour aucun magasin (voir `../royalties/`) | existe, taux à remplir |
| Factures de redevances, abonnement | réalm admin de l'ERP (`ep_ca_facturation`) | existe en lecture |
| Avoirs | les réclamations fournisseur du dashboard (photo, code-barres) | à relier à une pièce de l'ERP |
| Prime aux standards | le scoring trimestriel du cockpit | **règle à décider** |
| Personnel (page 2) | P&L du panel (`/consultant/shops/{id}/pnl/monthly`) | existe |
| Loyer et autres frais (page 2) | le budget du magasin ; le réel dans les frais du panel (`/shops/{id}/owner/costs`) | existe |

## La légalité en Belgique : à faire valider avant de signer

Le principe (une compensation conventionnelle entre ce que chacun doit) est courant. Le reversement intégral pose
en revanche des questions à faire trancher par un avocat (droit de la franchise et des paiements) et par le
comptable :

- **Encaisser pour le compte du franchisé**, puis lui reverser l'excédent, peut relever des services de paiement
  (loi du 11 mars 2018) et d'un agrément de la BNB. Si la centrale fait faillite, l'argent du franchisé tombe dans
  sa masse.
- **Espèces** : les paiements en espèces de plus de 3 000 € sont interdits (loi du 18 septembre 2017, art. 67).
  Halle encaisse environ 3 900 € d'espèces par mois. Plus sûr : déposer sur le compte du franchisé, puis virer.
- **Cartes et titres-repas** : les contrats sont au nom du franchisé. Les faire payer à la centrale suppose l'accord
  de l'acquéreur et des émetteurs, ou une cession ou un gage des créances.
- **La facture de marchandises** doit correspondre aux livraisons (prix × quantités). Calculée en pourcentage du CA,
  elle ressemble à une redevance (TVA 21 % et non 6 %).
- **Le contrat** : Halle a déjà signé ; il faut un avenant accepté par le franchisé, et pour les futurs franchisés,
  l'information précontractuelle (Code de droit économique, livre X). Les clauses déséquilibrées entre entreprises
  et l'abus de dépendance économique sont interdits.
- **Factures électroniques** : depuis le 1er janvier 2026, les factures entre entreprises passent par Peppol. Le
  relevé n'est pas une facture ; les factures de marchandises, de redevances et d'abonnement doivent exister à part.

## Régénérer

```
node consultant/docs/maquettes/releve-compensation/generer.js
node consultant/docs/maquettes/releve-compensation/capturer.js
```

Les pages lisent la police Gotham et le logo dans `shared/public/assets/` par un chemin relatif : elles s'ouvrent
telles quelles dans un navigateur, sans serveur. `capturer.js` vérifie que le contenu de chaque page A4 s'arrête
avant le pied, et imprime les deux PDF.
