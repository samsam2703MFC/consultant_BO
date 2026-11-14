# Dashboard magasin : Trimestre et Année dans un seul onglet, l'année simplifiée

**Statut : maquette à valider. Rien n'est codé dans `public/dashboard/`.**

Demande du 14/11/2026 : « dans le dashboard, peut-on grouper Trimestre et Année (et en même temps simplifier
Année) ». Précision du même jour : « laisser le graphique avec le trafic client et N-1 ».

Les captures montrent le **vrai dashboard**, servi depuis le dépôt, avec les **vraies lectures** de l'API du
cockpit au 14/11/2026. La variante est posée par-dessus, dans le DOM, juste avant la capture
(`capturer.js`). Halle (shop 4) a ouvert en février 2026 et n'a donc pas d'année précédente. Gosselies
(shop 3) montre le trait de 2025.

## Avant

- `avant-trimestre.png` : l'onglet Trimestre. On y trouve la frise des 4 trimestres, 6 tuiles, les 3 mois et
  les annotations.
- `avant-annee.png` : l'onglet Année, dans cet ordre :
  - 6 tuiles et la barre de l'objectif ;
  - la rampe à 5 ans ;
  - le tableau des trimestres (12 colonnes) ;
  - le trafic face à N-1 ;
  - la heatmap sur 24 mois ;
  - le tableau des mois.

## Après : un seul onglet « Année »

`apres-annee.png` (Halle, T4 en cours ouvert) :

1. **L'onglet Trimestre disparaît.** Une adresse `vue=trimestre` déjà partagée ouvre l'Année sur ce
   trimestre.
2. **L'objectif tient en une carte** :
   - la barre montre le réalisé, avec des hachures jusqu'à la projection et un repère pour l'attendu à ce
     jour ;
   - dessous, 3 chiffres (Réalisé, Écart à ce jour, Projection fin d'année) au lieu de 6 tuiles.
3. **Les 4 trimestres sont côte à côte.** Chacun affiche :
   - son statut (clos, en cours, à venir), son réalisé sur l'objectif et sa pastille d'atteinte ;
   - l'écart et la marge nette ;
   - ses 3 mois en miniature : gris pour le budget, couleur pour le réalisé.

   Un clic sur un trimestre l'ouvre.
4. **Le détail du trimestre choisi reprend tout l'ex-onglet Trimestre** :
   - ses 3 mois ;
   - objectif, réalisé, attendu, écart, clients manquants, marge nette ;
   - les annotations des trois voix, les actions et le rituel.
5. **Les 12 mois tiennent en une bande** rangée par trimestre. La couleur donne l'atteinte du budget, avec les
   mêmes seuils qu'aujourd'hui. Un trait gris marque le même mois de l'an passé, avec l'évolution en %. Cette
   bande remplace la heatmap sur 24 mois.
6. **Le trafic clients face à N-1 reste tel quel et visible** : clients, panier moyen, articles par client.
7. **Repliés, un clic pour les ouvrir** : le tableau des mois et la rampe à 5 ans (`apres-plis.png`, plis
   ouverts).

Autres vues :
- `apres-annee-t2.png` : le même écran avec T2 (clos) choisi.
- `apres-annee-n1.png` : Gosselies, avec une année précédente. On y voit le trait gris de 2025 dans la bande et
  le graphique du trafic face à 2025.

## Ce qui disparaît

- les 6 tuiles de l'Année et les 6 tuiles du Trimestre, remplacées par la carte de l'objectif et le détail du
  trimestre ;
- le tableau des trimestres en 12 colonnes, remplacé par les 4 cartes et le détail ;
- la heatmap sur 24 mois et son interrupteur % / CA, remplacés par la bande des 12 mois.

## À trancher avant de coder

- **Trimestre ouvert par défaut** : le trimestre en cours (proposé), ou aucun.
- **Pastille d'atteinte d'un trimestre** : comme le tableau d'aujourd'hui, elle compare le réalisé à
  l'**attendu à ce jour**. Pour T4 de Halle, cela donne 99 %, alors que la frise actuelle affiche « 48 % de
  l'objectif ». L'autre lecture possible est le réalisé sur l'objectif du trimestre.
- **Mois en cours dans la bande** : pas d'évolution en % sur N-1, puisqu'un mois incomplet ne se compare pas à
  un mois complet. La comparaison « aux mêmes jours » reste dans le graphique du trafic.

## Refaire les captures

```bash
php -S 127.0.0.1:8096 -t pilotage-reseau/public
```

```bash
node pilotage-reseau/docs/maquettes/annee-trimestres/capturer.js
```

La session est simulée (siège) pour la seule route `/acces/moi`. Le reste est relayé à l'API du cockpit en
ligne, lue en http (`MQ_API`). `MQ_SHOP` et `MQ_SHOP_N1` choisissent les magasins.
