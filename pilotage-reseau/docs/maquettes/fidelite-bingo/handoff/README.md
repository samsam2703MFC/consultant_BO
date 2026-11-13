# Handoff : Bingo de saison + Points (fidélité L'Atelier by)

## Vue d'ensemble
Programme de fidélité à deux moteurs, sur une seule carte :

- **Bingo** : fait **revenir** (fréquence). Une case par semaine, sur les 13 semaines d'un trimestre. Bons tous les `x` cases, plus un super bon cadeau à 13/13.
- **Points** : fait **consommer** (panier). Points à chaque euro, doublés pour le reste de la semaine dès que la case est cochée. Un bon tous les `palier` points.

Les deux se complètent. Le bingo ne récompense qu'**une** visite qualifiante par semaine : dépenser 40 € d'un coup ne coche qu'une case. Une fois la case cochée, le bingo n'a plus rien à offrir jusqu'à lundi. Les points doublés prennent alors le relais et incitent à continuer d'acheter.

Trois surfaces :
1. **Carte imprimée A6**, donnée en caisse avec un QR d'activation.
2. **Onglet Fidélité › Bingo de l'app client** : carte vivante, jauges, portefeuille, notifications.
3. **Réglages cockpit** (back-office) : tous les paramètres ci-dessous.

## À propos des fichiers
Les fichiers de ce dossier sont des **références de design en HTML** : des prototypes qui montrent le rendu et le comportement attendus. Ce n'est pas du code de production à copier. La tâche est de **les recréer dans l'environnement du codebase cible** (React, Vue, natif…) avec ses patterns et son design system existant (L'Atelier by). S'il n'y a pas encore d'environnement, choisissez le framework le plus adapté.

- `Bingo-et-Points-mock.html` : maquette interactive autonome (hors ligne). **Ouvrez-la en premier.** Ajoutez des tickets, passez à la semaine suivante, cliquez une semaine passée pour la basculer cochée ou manquée, et changez les réglages dans la barre du haut.
- `source/Bingo et Points.dc.html` : source de la maquette. Toute la logique métier est dans la classe `Component` en bas du fichier : `ticket()`, `bons()`, `cellules()`, `renderVals()`.
- `assets/` : illustrations et logos utilisés.

## Fidélité
**Haute fidélité** pour les couleurs, la typographie, la grille et les états. Les montants et les seuils sont des **valeurs de départ réglables**, pas des décisions définitives.

---

## Règles métier

### Paramètres (cockpit) et valeurs par défaut
| clé | défaut | rôle |
|---|---|---|
| `seuilHebdo` | 10 € | dépense cumulée dans la semaine (lundi–dimanche) pour cocher la case |
| `x` | 3 (2, 3 ou 4) | un bon bingo toutes les x cases cochées |
| `valeurBonBingo` | 2 € | valeur de chaque bon bingo |
| `valeurSuperBon` | 10 € | super bon cadeau, carte pleine 13/13 |
| `validiteJours` | 30 | validité de tous les bons, dès le lendemain du déblocage |
| `ptsParEuro` | 1 | points par euro, `floor(montant × ptsParEuro)` par ticket |
| `multiplicateurCase` | ×2 | appliqué aux tickets **postérieurs** à la case cochée, jusqu'au dimanche 23:59 |
| `palier` | 100 pts | conversion automatique en bon |
| `valeurBonPoints` | 5 € | valeur du bon points |

### Bingo
- Une carte = **13 semaines** (un trimestre), lundi → dimanche. Hiver 2026-27 : du 23/11/2026 au 21/02/2027. Quatre cartes par an (voir le tableau des saisons dans la maquette).
- La case de la semaine `n` se coche **automatiquement** quand la somme des tickets rattachés à la carte (boutique, click & collect, livraison) atteint `seuilHebdo`. Au plus **une case par semaine**. Une semaine passée sans atteindre le seuil devient « manquée », pour toujours.
- Les bons bingo se débloquent sur le **nombre** de cases cochées, pas sur leur position. Le bon `i` arrive à la `i·x`-ième case cochée. Nombre de bons = `floor(13 / x)`. Le bon prend la date de la case qui le débloque.
- Noms et illustrations des bons, selon le rang `k` de la case : k ≤ 4 « Bon de l'Avent » (cake), k ≤ 7 « Bon des Rois » (tartelette), k ≤ 11 « Bon de l'hiver » (hiver), sinon « Bon carte pleine » (cupcake).
- **Super bon cadeau** : 13 cases sur 13. Il s'ajoute aux bons x. Une seule semaine manquée le rend impossible pour la saison, et l'app affiche alors « une semaine manquée : au printemps ».
- Les bons sont de vrais codes du panel de bons existant, créés comme celui du « déjà venu ». Ils arrivent dans le portefeuille de l'app.

### Points
- À chaque ticket : `gain = floor(montant × ptsParEuro) × (caseDejaCochee ? 2 : 1)`.
  - Le ticket **qui coche** la case est compté ×1. Les tickets suivants de la même semaine sont comptés ×2.
- Après chaque ajout : `while (solde >= palier) { solde -= palier; créer bon points }`. Le reste est conservé.
- Les points **ne sont pas remis à zéro** en fin de saison. Seule la carte bingo change.
- Sur le même ticket, ajoutez les points **avant** d'évaluer le palier. Plusieurs événements sont possibles (case + bon bingo + bon points) : la maquette n'en notifie qu'un, par priorité bon points > super bon > bon bingo > case > ticket. En production, prévoyez une notification groupée.

### Algorithme `onTicket(carte, montant, date)` (référence : `ticket()` dans la source)
```
sem = semaine(date)
depenseSem += montant
boost = carte.cochees[sem] existe
gain = floor(montant * ptsParEuro) * (boost ? 2 : 1)
si !boost et depenseSem >= seuilHebdo :
    cochees[sem] = date
    n = nombre de cochees
    si n % x == 0 et n/x <= floor(13/x) : créer bon bingo n/x
    si n == 13 : créer super bon
points += gain ; convertir les paliers
```
Une semaine se clôt le dimanche 23:59 : remise à zéro de `depenseSem`, fin du boost.

### Coefficients personnalisés (onglet Points)
Chaque lundi, le moteur calcule pour chaque client un jeu de bonus d'après son profil d'achat. Les bonus **ne s'additionnent pas** : sur chaque ligne de ticket, le coefficient le plus fort s'applique.

`coefLigne = max(caseCochee ? 2 : 1, coefJour(date), coefProduit(produit))`

- **Case cochée ×2** : pour tous, sur les tickets postérieurs à la case, jusqu'au dimanche.
- **Jour perso ×3** : un jour où le client vient rarement, la veille de son jour habituel. Exemple maquette : vendredi, pour une cliente du samedi matin.
- **Offre perso ×2 ou ×3** : un produit choisi pour élargir le panier. Exemples :
  - une catégorie jamais achetée (éclair, ×3) ;
  - une affinité « les clients qui aiment X » (tartelette, ×3) ;
  - un produit délaissé (cookies, ×2).
  La maquette fait tourner une offre par semaine (`PERSO[(semaine-1) % 3]`).
- **Produit de la semaine ×2** : pour tous, c'est le thème de la case bingo.
- **Produit habituel ×1** : on ne bonifie jamais ce que le client achète déjà ; il est affiché comme repère.

Le profil affiché sous les bonus (« Choisi d'après vos habitudes ») explique le choix au client. Données nécessaires :
- jour et créneau habituels ;
- catégories achetées et jamais achetées ;
- panier moyen ;
- produits achetés auparavant mais plus récemment.

Modèle : `BonusHebdo { clientId, semaine, jour?: {index, coef, raison}, produits: [{sku, coef, type: perso|semaine|habituel, raison}] }`.

Écran (onglet Points, de haut en bas) :
1. En-tête « POINTS » + « de Julie », solde et reste au palier, jauge abricot.
2. **Votre semaine** : 7 pastilles Lun → Dim avec la date et le coefficient du jour. Couleurs : ×1 beige, ×2 abricot, ×3 rubis. Le jour courant a un contour noir de 1,4 px, les jours passés une opacité de 0,45. Dessous, une ligne d'explication par bonus.
3. **Compte plus cette semaine** : offre perso (fond abricot à 30 %, bordure pleine, coefficient sur fond rubis), produit de la semaine (bordure pointillée, coefficient abricot), produit habituel (×1 grisé).
4. **Choisi d'après vos habitudes** : puces du profil et rappel de non-cumul.

### Modèle de données (proposition)
- `CarteSaison { id, saison, debut, fin, semaines[13]: {n, du, au, titre, sous, fete?, jourFete?, illustration} }`
- `CarteClient { id, clientId, carteSaisonId, numero, qrToken, cochees: {n: date}, depenseParSemaine: {n: €} }`
- `ComptePoints { clientId, solde, historique[] {ticketId, gain, multiplicateur, date} }`
- `Bon { code, type: bingo|super|points, valeur, debloqueLe, valableJusquau, utiliseLe? }`

---

## Écrans

### 1. Carte imprimée (A6, 540 × 762 px dans la maquette)
- Fond `#F4EFE8`, rayon 10 px, padding 26 px. La légère rotation de −1,2° n'est que de la mise en scène : à ignorer à l'impression.
- En-tête : logo (26 px de haut) à gauche, « CARTE FIDÉLITÉ / Hiver 2026 – 2027 » à droite (10 px, capitales, tracking .12em, rubis).
- Titre : « BINGO » en Vank 76 px rubis, puis « d'hiver » en Playwrite DEVA 28 px.
- Grille : `x+1` colonnes, soit x semaines puis le bon en bout de ligne. Bordure de 1,6 px rubis, filets intérieurs de 1,2 px.
  - Case semaine (min. 112 px de haut) : `S{n}` et les dates (8,5 px) ; étiquette « Fête · jj/mm » pleine rubis ou « À l'honneur » en contour ; illustration de 42 px ; titre (10,5 px) ; sous-titre (8,5 px). Les semaines de fête ont un fond abricot à 42 % sur blanc.
  - Case bon : fond rubis, illustration en blanc (`filter: brightness(0) invert(1)`), étiquette « Débloqué » abricot.
  - **Super bon cadeau** : occupe le reste de la dernière ligne. Fond rubis, encoches de ticket (cercles blancs de 16 px à gauche et à droite), cadre intérieur pointillé abricot, gâteau en blanc de 66 px, « SUPER BON CADEAU » en Vank 25 px.
- Pied : « Comment ça marche » (bingo + points) et QR de 96 px avec « Activez votre carte / N° ».

### 2. App, onglet Fidélité › Bingo (écran de 368 px de large)
De haut en bas :
1. Sélecteur Bingo / Points / Portefeuille.
2. En-tête : « BINGO » Vank 34 px + « d'hiver », et à droite `cochées / 13` avec le nombre de bons.
3. **Carte de la semaine** : illustration, « Cette semaine · dates », titre, jauge de dépense vers le seuil, texte de relance. La bordure est en pointillé tant que la case n'est pas cochée, puis pleine.
4. **Carte points** : « MES POINTS » + solde (Vank 24 px) / palier, jauge abricot, et un badge d'état :
   - avant la case : « ×1 · cochez pour ×2 » (fond beige, texte gris) ;
   - après la case : « ×2 jusqu'à dimanche » (fond rubis, texte blanc).
   - Ligne d'aide : « +N pts au dernier ticket · encore M pts pour un bon de 5 € ».
5. **Grille mini** (mêmes colonnes que la carte, cases de 84 px de haut, rayon 10 px). États d'une semaine :
   - cochée : tampon rond rubis incliné à −14°, avec ✓ et la date ;
   - en cours : contour pointillé rubis et mini-jauge ;
   - à venir : opacité 0,42 ;
   - manquée : illustration en niveaux de gris, étiquette « manquée ».

   États d'un bon : débloqué (rubis), prochain (contour pointillé, « encore k cases »), futur, perdu, utilisé, expiré.
6. **Portefeuille** : liste des bons valides, du plus récent au plus ancien. Tuile rubis pour un bon bingo, abricot pour un bon points. Origine, nom, valeur, code, date de fin, bouton « Montrer ».
7. **Notification** en haut : fond noir pour un ticket ou une case, rubis pour un bon. Animation d'entrée : translateY(−30px) → 0 en 0,35 s.

Animation de déblocage (case, bon ou palier) : `scale .85 → 1.06 → 1` en 0,6 s, ease.

---

## Design tokens (L'Atelier by)
- Rubis `#8D1D2C` (primary) · Abricot `#F2C9A0` (secondary) · Beige `#EAE4DC` (fond) · Beige clair `#F4EFE8` · Blanc `#FFFFFF` · Texte `#222222` · Texte secondaire `#666666`
- Bordures : `rgba(34,34,34,.10)` et `rgba(34,34,34,.18)` · Rose de bon verrouillé : `color-mix(rubis 12 %, blanc)`
- Polices : **Vank** (display : BINGO, POINTS, SUPER BON CADEAU, soldes) · **Gotham** (UI) · **Playwrite DEVA** (accent script « d'hiver »). Graisse 400 partout.
- Rayons : 6 / 8 / 12 px. Cartes de l'app : 14 px.

## Assets
Illustrations rouges de la bibliothèque L'Atelier by (`img/illustrations/*/Red/`), 560 × 560 px, fond transparent. Elles sont copiées dans `assets/ill/` :

| fichier | utilisation |
|---|---|
| bread-2 | cougnou |
| saint-nicolas | Saint-Nicolas |
| chocolat | chocolat chaud |
| cookies | pain d'épices |
| noel | Noël |
| artboard-4 | Nouvel An, étincelle du super bon |
| galette | Épiphanie |
| bread-1 | bon pain |
| cake-slice | Blue Monday |
| croissant | croissant, bon points |
| crepes | Chandeleur |
| valentin | Saint-Valentin |
| tarte | semaine 13 |
| cake | Bon de l'Avent, super bon |
| tartelette | Bon des Rois |
| hiver | Bon de l'hiver, carte hiver |
| cupcake | Bon carte pleine |
| printemps / ete / automne | cartes des autres saisons |

Logos : `assets/logo.png` et `assets/logo-white.png`.

## Points ouverts
- Les montants (seuil, valeurs des bons, taux, palier) sont des hypothèses à valider par la direction.
- Thème de la semaine 13 (« La tarte du dimanche ») : provisoire.
- Faut-il que le ×2 s'applique aussi à la part du ticket qui dépasse le seuil ? (La maquette : non.)
