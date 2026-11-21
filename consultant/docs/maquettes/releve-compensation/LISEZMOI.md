# Relevé de compensation mensuel — Halle (maquette)

**Statut : maquette du 21/11/2026, à valider. Rien n'est codé.**

Demande du 21/11/2026 : adapter au magasin de Halle le règlement de Żabka (les recettes du jour vont à la centrale,
une compensation mensuelle déduit achats et redevances, la centrale verse le solde vers le 10), puis « fais la
maquette du relevé mensuel de Halle ».

## Le modèle maquetté : la compensation « à la belge »

Le reversement intégral de Żabka ne se transpose pas tel quel à Halle. Ce que la centrale facture au magasin pèse
entre 6 % (redevances seules) et 42 % (toute la matière en plus) de ses recettes. Encaisser 100 % des recettes
reviendrait surtout à garder l'argent du franchisé, jusqu'à 40 jours. La maquette suit donc la variante proposée
le même jour :

- les recettes (cartes, titres-repas, Payconiq, espèces déposées) restent sur un **compte de recettes au nom du
  franchisé** ;
- la centrale émet le **relevé le 5** : ce que le magasin lui doit (redevances, livraisons de la centrale d'achat,
  abonnement TFBuddy), moins ce qu'elle lui doit (avoirs, prime aux standards) ;
- elle **prélève le solde le 10** par domiciliation SEPA B2B ; un solde en faveur du magasin lui est versé le même jour ;
- la page 2 contrôle les **espèces** : l'encaissé de la caisse face aux dépôts déclarés, semaine par semaine.

La version C chiffre aussi le modèle de reversement intégral : « si la centrale encaissait vos recettes, elle vous
verserait 47 135,92 € le 10/10/2026 ».

## Les captures

Gabarit des documents de gestion, **version A** (`docs/DESIGN_SYSTEM.md` §13.4). Les mises en page nouvelles se
montrent en trois versions (§13) : les trois versions diffèrent par la page 1, la page 2 est commune.

| Fichier | Version | Ce qu'elle montre |
|---|---|---|
| `planche.jpg` | les trois pages 1 côte à côte | pour choisir |
| `a.png`, `a-nl.png` | **A — Pièces** | le relevé de compte : une pièce par ligne (date, numéro, désignation), débit ou crédit, puis le solde à prélever |
| `b.png`, `b-nl.png` | **B — Facture** | ce que le magasin doit, puis ce que la centrale lui doit, HT / TVA / TTC par ligne, le récapitulatif de TVA (21 % et 6 %) |
| `c.png`, `c-nl.png` | **C — Cascade** | des recettes du mois (TTC) à ce qui reste après la compensation, la part de la centrale en barre (12,6 %) |

Septembre 2026 : 6 813,79 € dus à la centrale (redevances 3 076,43 €, livraisons 3 617,57 €, abonnement 119,79 €),
48,97 € dus par elle (un avoir), **6 764,82 € à prélever le 10/10/2026**.

## Les chiffres

- **Mesurés** (dossier investisseur `consultant/docs/dossiers/halle-2026-10`, lu le 09/10/2026) : chiffre
  d'affaires HT 50 849,75 €, 30 jours de vente, 4 156 tickets ; score du T3 12,09 / 20, rang 2 sur 4.
- **Calculés** : les redevances, avec la grille du budget 2026 de Halle (marque 2 %, assistance 1,5 %, marketing
  1,5 %, sur le HT, TVA 21 %) : 2 542,50 € HT.
- **Illustratifs**, marqués d'un astérisque sur les pages :
  - le TTC, à 6 % sur tout le chiffre ;
  - la répartition par moyen de paiement : celle de Corbais du 2 au 8/11/2026 (`panel/docs/maquettes/caisse-reports/donnees.json`),
    car l'API TFBuddy refusait le jeton de la session de travail ;
  - les espèces de chaque semaine, réparties selon la semaine type de Halle, et les dépôts ;
  - les livraisons de la centrale, l'abonnement, l'avoir et les numéros de pièce.
- **Entre crochets** : les mentions de l'émetteur et du destinataire qui se lisent dans l'ERP (raison sociale,
  adresses, numéros d'entreprise et de TVA, RPM, IBAN, n° de mandat, communication structurée).

## Avant de coder : les sources, ce qui manque, ce qui est à décider

| Ligne du relevé | Source | État |
|---|---|---|
| Recettes par moyen de paiement | export comptable du jour TFBuddy (`/shops/{id}/reports/sales/daily/cash-flow/csv`), lu par `consultant/src/caisse.php` | existe |
| Chiffre d'affaires HT, base des redevances | ventes du panel ; calcul des redevances `fondsRoyaltiesCalcul()` (`shared/src/endpoints.php`) | existe ; les taux de la fiche boutique (`royalty_*_percentage`) ne sont remplis pour aucun magasin (lu le 16/11/2026, voir `../royalties/`) |
| Factures de redevances, abonnement TFBuddy | réalm admin de l'ERP, `/admin/royalties/invoices` et `/admin/billing/invoices` (`ep_ca_facturation`) | existe en lecture |
| Livraisons de la centrale d'achat | aucune API de commandes ni de factures fournisseurs (`shared/src/endpoints.php`, `ep_ca_cockpit`, « manquants ») | **manque** |
| Avoirs (réclamations fournisseur) | les réclamations avec photo et code-barres du dashboard | à relier à une pièce de l'ERP |
| Prime aux standards | le scoring trimestriel du cockpit | **à décider** : la règle (seuil de 14 / 20 posé pour la maquette) et le montant |
| Dépôts d'espèces | les lignes « Versement espèces » et « Dépôts » de l'export comptable, vides aujourd'hui (Corbais, semaine du 2 au 8/11) | **manque** : une saisie, ou les extraits CODA du compte de recettes |
| Mentions de l'émetteur, mandat B2B | configuration de l'émetteur dans l'ERP | **manque** : à lister avec leur source avant d'émettre (§13.4) |

Décisions à prendre :

- la version (A, B ou C), avant de généraliser le gabarit ;
- les dates : relevé le 5, prélèvement le 10, remarques avant le 8 ;
- la langue : FR ou NL selon la fiche du destinataire (§13.1) ;
- la clause de compensation dans le contrat de franchise et le mandat de domiciliation B2B du magasin. Si le
  reversement intégral était retenu, le faire valider par un avocat : un encaissement pour le compte du franchisé
  peut relever des services de paiement (loi du 11 mars 2018).

## Régénérer

```
node consultant/docs/maquettes/releve-compensation/generer.js
node consultant/docs/maquettes/releve-compensation/capturer.js
```

Les pages lisent la police Gotham et le logo dans `shared/public/assets/` par un chemin relatif : elles s'ouvrent
telles quelles dans un navigateur, sans serveur. `capturer.js` vérifie aussi que le contenu de chaque page A4
s'arrête avant le pied.
