# Handoff : Centrale d'achat — intégration au BO consultant

## Overview
Module « Centrale d'achat » du cockpit consultant général. Il sert à filtrer et vérifier les flux de marchandise entre fournisseurs et franchisés, et à négocier les prix des prochaines campagnes commerciales. Le module couvre dix écrans accessibles depuis un rail plat, dont deux spécifiques à la centrale : **Campagnes commerciales** (C2, lecture seule, source API cockpit marketing) et **Demande de prix** (C1, parcours en 4 étapes).

## About the Design Files
Les fichiers de ce dossier sont des **références de design réalisées en HTML** (prototype exécutable), pas du code de production à copier. La tâche est de **recréer ces écrans dans l'environnement existant du BO consultant** (framework, design system, couche d'accès aux données de la codebase), en réutilisant ses patterns. Les données du prototype sont des seeds déterministes en mémoire (localStorage) : dans le BO elles doivent venir des APIs listées plus bas.

## Fidelity
**Hi-fi.** Couleurs, typographie, densités et interactions sont définitives ; elles suivent le design system L'Atelier by (tokens CSS `--color-*`, `--radius-*`, `--font-ui`). Dans le BO, appliquer les tokens équivalents de la codebase plutôt que les valeurs littérales quand ils existent.

## Écrans

Rail plat, en-tête « Centrale d'achat » / sous-titre « Cockpit consultant général », entrées dans cet ordre :

| Entrée | Vue | Rôle |
|---|---|---|
| Cockpit | `cockpit` | KPI réseau sur période glissante (7 j / 30 j / trimestre / année) |
| Campagnes commerciales | `campagnes` | Campagnes du cockpit marketing + contrôle des flux |
| Demande de prix | `demande` | Négociation fournisseur en 4 étapes |
| Suivi fournisseurs | `achats` | Commandes fournisseurs, réception, litiges (badge = nb litiges) |
| Commandes franchisés | `commandes` | Commandes magasins (badge = nb commandes « nouvelle ») |
| Catalogue & marge | `catalogue` → `fiche` | Catalogue produits + moteur de marge, fiche produit |
| Analyse des ventes | `ventes` | Ventes par magasin vs objectifs, export CSV |
| Stock | `stock` | Stock, alertes, ruptures |
| Facturation magasins | `facturation` | Factures magasins, TVA par ligne, relances |
| Réglages | `reglages` | Paramètres (dont RFA fournisseurs en lecture seule) |

En bas du rail : bloc **« Analyse d'usage — debug »** (tracker d'usage, voir plus bas).

### C2 — Campagnes commerciales
- Grille de cartes campagne (statut, nom, période, objectif volume total). Carte sélectionnée : bordure `--color-primary`.
- Bandeau détail : nom, période, source (`API cockpit marketing`), assortiment + nb réfs, objectif volume, prix cible, remise négociée, bouton primaire **« Négocier cette campagne → »** (ouvre C1 en mode campagne).
- Table gauche **Objectifs de volume par magasin** : objectif campagne vs quantités commandées par les franchisés sur les réfs de l'assortiment, barre de progression (≥ 80 % vert `#2d7a3e`, ≥ 40 % orange `--color-collection-border`, sinon gris).
- Table droite **Contrôle des flux fournisseurs** : reçu / commandé, écart de quantité (rouge si négatif), nb de lignes hors prix négocié, alerte « Facture en litige ».

### C1 — Demande de prix (4 étapes)
Étape 1 « Sélection » : **base de référence** au choix — `Période` (dates + presets), `Assortiment` (fenêtre de ventes portée par l'assortiment), `Campagne` (nouveau). Puis magasins inclus, puis sélection des produits (recherche, filtre catégorie).
Étape 2 « Consolidation » : objectifs (baisse prix / hausse volume), 3 KPI, groupes par fournisseur avec quantité et prix cible éditables par ligne.
Étape 3 « Demande de prix » : mail préformaté (objet, corps, copier / mailto), validation → enregistrement dans le suivi.
Étape 4 « Suivi » : avancement des demandes.

**Mode campagne** (spécificités) :
- périmètre produits = produits de `campagnes.assortiment_id` ;
- fenêtre de ventes de référence = période de cet assortiment ;
- prix cible = `campagnes.prix_cible_baisse_pct` ;
- volume demandé = `campagnes.objectifs_volume` des magasins retenus, réparti au prorata des ventes constatées ;
- les deux champs d'objectifs de l'étape 2 passent en lecture seule (pilotés par la campagne) ;
- KPI 2 devient « Objectif de campagne » : volume cible + u./j visées vs u./j constatées.

## Contrats de données

Toutes les tables sont « table driven » : l'UI ne calcule rien en dur. Schéma versionné (`SCHEMA_VERSION = 8`).

```
categories            { id, nom }
fournisseurs          { id, nom, conditions, rfa_pct, redevance_centrale_pct, email }
magasins              { id, nom, ville }
produits              { id, nom, categorie_id, fournisseur_id, prix_achat, prix_achat_n1,
                        prix_vente_magasin, recommande_par_marque, tva_pct,
                        stock_actuel, stock_min, rotation_attendue }
assortiments          { id, nom, description, periode_debut, periode_fin, produits[] }
campagnes             { id, nom, source, statut, periode_debut, periode_fin, assortiment_id,
                        remise_negociee_pct, prix_cible_baisse_pct, fournisseurs[],
                        objectifs_volume { <magasin_id>: qte } }
benchmarks            { id, produit_ou_categorie, libelle, prix_benchmark, rotation_benchmark, marge_min_cible }
objectifs             { id, produit_ou_categorie, magasin_id|null, periode, objectif_ca, objectif_qte }
parametres            { commission_marque_pct, marge_centrale_cible_pct, tva_defaut_pct,
                        objectif_baisse_prix_pct, objectif_hausse_volume_pct, … }
ventes                { id, date, magasin_id, produit_id, quantite, ca }
commandes             { id, magasin_id, date, statut, total }
lignes_commande       { id, commande_id, produit_id, quantite, prix_unitaire }
commandes_fournisseur { id, fournisseur_id, date, statut, total }
lignes_commande_fournisseur { id, commande_fournisseur_id, produit_id,
                        quantite_commandee, quantite_recue, prix_unitaire }
factures_fournisseur  { id, fournisseur_id, commande_fournisseur_id, date, montant, statut }
demandes_prix         { créé par C1 : fournisseur, périmètre, quantités, prix cibles, statut }
remises_fournisseur   { id, fournisseur_id, type, base_ca, taux, montant }   // RFA
retro_magasins        { id, magasin_id, periode, montant_retrocede }
```

Statuts utilisés : commandes magasins `nouvelle | préparée | expédiée | livrée` ; commandes fournisseurs `envoyée | partiellement reçue | reçue` ; factures fournisseur `à payer | payée | litige` ; campagnes `préparation | négociation | validée`.

## Endpoints attendus côté BO

| Donnée | Source | Mode | Notes |
|---|---|---|---|
| `campagnes` | **API cockpit marketing** | lecture seule | porte l'objectif de volume par magasin ; ne jamais écrire depuis la centrale |
| `produits` (dont `tva_pct`) | API produits | lecture seule pour `tva_pct` | `tva_pct` n'est jamais saisi dans la centrale ; repli `parametres.tva_defaut_pct` |
| `fournisseurs` (dont `rfa_pct`) | table fournisseurs / API achats | lecture seule dans Réglages | affiché mais non éditable |
| `ventes` | API ventes | lecture | filtrage par fenêtre `[debut, fin]` |
| `commandes` / `lignes_commande` | API commandes franchisés | lecture + transition de statut | l'expédition décrémente le stock |
| `commandes_fournisseur` / lignes | API achats | lecture + réception | la réception incrémente le stock et recalcule le statut |
| `factures_fournisseur` | API achats | lecture | statut `litige` alimente les alertes de flux |
| `assortiments` | référentiel assortiments | lecture | fenêtre de ventes de référence |
| `demandes_prix` | à créer côté BO | écriture | créé à l'étape 3 de C1 |

## Règles de calcul (à reproduire à l'identique)

**Marge produit** (`computeMargin`) : `ca = prix_vente_magasin` ; `commission = ca × commission_marque_pct/100` ; `marge_brute = prix_vente_magasin − prix_achat` ; `marge_nette = marge_brute − commission` ; `taux = marge_nette / ca`. Arrondi 2 décimales.

**Marge d'une commande magasin** (`orderMargin`) : somme des lignes `quantite × prix_unitaire` pour le CA, `quantite × produit.prix_achat` pour l'achat ; commission sur le CA ; `nette = (ca − achat) − commission`.

**TVA** : jamais de taux global. Par ligne : `quantite × prix_unitaire × produit.tva_pct/100`, repli `parametres.tva_defaut_pct` si le produit n'a pas de taux. Une facture affiche la liste des taux présents.

**RFA** : `base_ca × fournisseur.rfa_pct/100`, taux issu de la table fournisseurs (lecture seule).

**Demande de prix — défauts de ligne**
- hors campagne : `qty = round(vendu × (1 + objectif_hausse_volume_pct/100))`, `cible = round2(prix_achat × (1 − objectif_baisse_prix_pct/100))`.
- en campagne : `cible = round2(prix_achat × (1 − campagne.prix_cible_baisse_pct/100))` ; `qty = round(campObj × vendu_p / venduSel)` où `campObj = Σ objectifs_volume[magasin]` sur les magasins retenus et `venduSel = Σ vendu` des produits sélectionnés → le total demandé égale l'objectif de campagne.
- comparaison de rythme : `rateObj = campObj / durée_campagne_en_jours`, `rateVendu = venduScope / durée_fenêtre_de_ventes` ; les deux sont affichés en u./j (ne jamais comparer des volumes de durées différentes).
- écart de prix par ligne : `(cible − prix_achat) / prix_achat` (vert si ≤ 0).

**Contrôle des flux (C2)** : écart quantité = `Σ quantite_recue − Σ quantite_commandee` par fournisseur ; ligne « hors prix négocié » si `|prix_unitaire − produit.prix_achat| > 0,001` ; alerte litige si une facture du fournisseur est en statut `litige` ; atteinte magasin = `Σ quantite commandée par le magasin sur les réfs de l'assortiment / objectifs_volume[magasin]`.

**Formatage** : nombres et pourcentages en français (virgule décimale, séparateur de milliers espace), montants en €, dates `jj/mm/aa`.

## State management
État local d'écran (aucun state serveur dans le prototype) : `view`, `selectedId`, filtres par écran (`catFilter`, `cmdFilterMag`, `stockCat`…), période du cockpit, et pour C1 : `demStep`, `demBase` (`periode | assortiment | campagne`), `demAssort`, `demCampId`, `demDebut/demFin`, `demMags`, `demProds`, `demCat`, `demSearch`, `demEmailFour`, `demValide`, plus deux maps hors state pour les saisies de ligne (`demQty`, `demTarget`). Dans le BO, `view` doit passer par le routeur et les filtres restent locaux.

## Tracker d'usage (debug)
Bloc en bas du rail. Compteur `{ n, t }` par clé, persisté (`localStorage` clé `erp_usage_v1`), clés `view:<vue>`, `dem:step<n>`, `camp:select`, `camp:negocier`, `act:export_csv`. Il affiche « X/Y fonctions utilisées », la liste des fonctions utilisées avec leur compteur, celles jamais ouvertes, et un bouton de réinitialisation. Objectif : affiner l'UX en repérant les fonctions mortes. Côté BO, brancher sur l'outil d'analytics existant plutôt que sur localStorage.

## Design tokens
Tokens du design system L'Atelier by (`_ds/l-atelier-by-*/global.css`) : `--color-bg`, `--color-surface`, `--color-background-secondary`, `--color-text`, `--color-text-secondary`, `--color-text-muted`, `--color-border-secondary`, `--color-border-tertiary`, `--color-primary`, `--color-collection-border`, `--radius-sm/md/lg`, `--font-ui`. Sémantique ajoutée par le module : vert `#2d7a3e` (positif), orange `--color-collection-border` (vigilance), rouge `--color-primary` (alerte / écart). Classes utilitaires locales : `.erp-card`, `.erp-th`, `.erp-td`, `.num` (chiffres tabulaires), `.t-page-title`, `.t-section-title`, `.t-label`, `.t-admin-label`, `.t-small`.

## Assets
`img/logo.png` (logo L'Atelier utilisé en tête de rail). Aucune autre image : pas d'icônes, glyphes texte uniquement (`✓`, `→`, `←`, `▾`).

## Files
- `ERP.dc.html` — prototype complet (rail, 10 écrans, C1 et C2).
- `erp-data.js` — schéma, seeds déterministes, moteur de marge, presets de période.
