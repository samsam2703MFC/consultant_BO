# Réclamations fournisseur : les photos à chaque fois, et un tableau annuel réclamé / livré

**Statut : A et B retenues et codées le 21/11/2026 (« code A et B, photo obligatoire ») ; C non retenue.** Demande du 21/11/2026, sur l'écran Franchisés › Demandes des franchisés ›
Réclamations fournisseur (`avant.png`) : « est-ce que je peux avoir à chaque fois les photos ? et aussi un tableau annuel
(volume réclamé / volume vendu, sur le client et en général) — faire les maquettes en screenshot et ensuite décider ».

| Capture | Ce qu'elle montre |
|---|---|
| `avant.png` | l'écran tel qu'il est aujourd'hui (capture du 21/11) : « Pièces jointes 1 », mais aucune photo visible |
| `a.png` | **A · les photos à chaque réclamation** : une colonne Photos (vignette + nombre, « sans photo » en rouge), un filtre et un indicateur « Sans photo », et dans la fiche les photos en grand (vignettes, « Tout télécharger ») ; en plus, la part du produit dans ce qui a été livré au magasin (semaine, mois, année) et la même part pour le réseau |
| `b.png` | **B · tableau annuel par magasin** : un 3e onglet « Tableau annuel » ; magasins × mois, chaque case = montant réclamé et sa part du livré (au prix d'achat), carte de chaleur, ligne Réseau, colonnes Livré et Accepté ; export Excel et PDF |
| `c.png` | **C · tableau annuel par produit, le client face au réseau** : pour chaque produit, pièces livrées, pièces réclamées, taux sur l'année, pire mois, pire semaine, montant, courbe mois par mois ; Gosselies à gauche, le réseau à droite |

B et C sont les deux vues du même onglet (« Par magasin » / « Par produit ») : on peut garder les deux.

## Données

- Les 14 réclamations, leurs montants, motifs, statuts et la fiche du pain au chocolat : **recopiés de la capture du
  21/11/2026**.
- **Illustration** (le serveur n'était pas joignable depuis la session) : le nombre de photos par réclamation, les deux
  photos (celles des maquettes `panel/docs/maquettes/reclamations/photos`), tous les volumes livrés, et les mois de B
  hors de la capture. Chaque écran porte l'étiquette « chiffres d'illustration ».

## D'où viendraient les données (à vérifier au codage)

- **Photos** : `GET /consultant/shops/material-complaints` porte déjà `attachments` (l'écran n'en compte que le nombre,
  `pj`) ; chaque pièce s'ouvre par `GET /attachments/{id}/presigned-url` (lien signé à durée limitée, la même route que les
  photos des tâches, `PanelApi::attachmentUrl`). Le lien se demande à l'ouverture de la fiche, pas pour toute la liste.
  S'y ajoutent les photos prises depuis le dashboard, déjà gardées par le cockpit (`panel_reclamation_photo`,
  `rcPhotosDe`). À vérifier : la forme exacte d'un élément de `attachments` (identifiant, type, vignette).
- **Volume livré** : les livraisons fournisseur reçues au panel, `material_order_item_delivery_log` joint à
  `material_order` (magasin) — packs livrés × taille du pack (pièces) et × prix du pack (€), par matière, magasin et
  jour, comme `consultant/src/inventaires.php` le lit déjà. Lecture SQL directe d'une table du panel : écart connu
  (REORG_PLAN.md §8), à passer par une route du panel si elle existe.
- **Volume réclamé** : `qty` (dans `unit_name`) et le montant de chaque réclamation, déjà dans
  `GET /fournisseurs/reclamations`.
- « Volume vendu » est compris ici comme **ce que le fournisseur a livré (vendu) au magasin**. Si c'est la vente en
  caisse du magasin qui est voulue, la base change (ventes du panel par produit) : à trancher.

## Générer

`node generer.js` (a.html, b.html, c.html), puis `node capturer.js` (a.png, b.png, c.png), depuis ce dossier.

## Le code (21/11/2026)

- **Serveur** : `consultant/src/reclamations.php` — `GET /fournisseurs/reclamation-fiche?shop=&id=` (photos : liens signés
  des pièces jointes + photos du dashboard ; part du livré semaine / mois / année et réseau) et
  `GET /reclamations/annuel?annee=&fournisseur=` (réservée au siège). La liste (`GET /fournisseurs/reclamations`) rend
  `nbPhotos` pour tout le réseau.
- **Cockpit** : `helpdesk-vals.js`, `helpdesk-tpl.js`, `helpdesk.css` — colonne Photos, filtre et indicateur « Sans photo »,
  photos dans la fiche, part du livré ; 3e onglet « Tableau annuel » avec export CSV (Excel).
- **Photo obligatoire** : `wr_reclamation_creer` refuse une réclamation sans photo (422, rien ne part au panel). Le
  formulaire de la tablette et la fenêtre des invendus du dashboard demandent la photo avant d'envoyer ; depuis le
  contrôle du cockpit, la photo de la tâche contrôlée est reprise (relue au panel par le serveur). Une réclamation
  créée directement dans l'application TF Buddy n'est pas concernée : ce n'est pas notre code.
- **Banc** : `php consultant/bin/tests_reclamations.php`.
- `code-a.png`, `code-b.png` : l'écran codé, API simulée avec les réclamations de la capture (volumes simulés).
- À vérifier en ligne : que les entrées du grand livre liées à une commande (`material_inventory_log.id_order`) sont bien
  les livraisons, et la forme d'un élément de `attachments`.
