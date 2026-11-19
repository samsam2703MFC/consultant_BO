# Résultat › Évolution magasin — ventes : Tout | Comptoir | B2B

**Statut : codé le 13/11/2026** (maquette du même jour). Demande : « dans le graphique Évolution
magasin, différencier en bascule aussi le CA B2B et le CA comptoir des magasins ».
Captures du code : `code-tout-haut.png`, `code-comptoir-haut.png`, `code-b2b-haut.png`,
`code-b2b-clients-total-haut.png`, et `code-comptoir-mois-non-lus.png` (le cas d'un magasin dont le
B2B n'est pas lu sur ses premiers mois, et d'un magasin où il ne l'est pas du tout).

## Ce qui est codé

- **La bascule** : « ventes : Tout | Comptoir | B2B » en tête de l'onglet, la même que dans Analyse
  réseau, combinée à *Trafic clients / Chiffre d'affaires* et *Par jour ouvert / Total* (état `evCanal`).
- **Les règles** de tout le cockpit : **B2B** = les tickets des clients pro à TVA valide
  (`vpTvaValides`) ; **Comptoir** = le total moins le B2B ; un client pro sans TVA valide reste au
  comptoir. Une ligne sous la bascule dit ce que le canal compte et sa part du CA du réseau sur les
  12 derniers mois.
- **Tout** : l'onglet d'avant, à l'identique ; les faits disent « dont B2B x % sur 12 mois », la
  table trimestrielle gagne *Part B2B*.
- **Comptoir** : courbe, N-1, tuiles et table sur le comptoir seul ; étiquette « Comptoir ».
- **B2B** : courbe bleue (le vert et le rouge sont pris par le gagné / perdu), sa propre échelle ;
  le trafic devient les **commandes pro** (un ticket pro = une commande) ; la 3e tuile devient
  **Part du CA** face au N-1 et au plafond des visites (40 %) ; panier pro ; *Part du CA* dans la table.
- **Un mois dont le B2B n'est pas lu sur tous ses jours vendus** sort des courbes Comptoir et B2B
  (le comptoir porterait sinon le pro des jours manquants) ; la légende le dit (« 5 mois sans B2B lu
  sur tous leurs jours, hors courbe (janv. 25 → mai 25) »). Un magasin sans aucun B2B lu le dit à
  la place du graphique.

## D'où vient le B2B — `GET /ventes/evolution`

Chaque mois et son `n1` portent `pro` {clients, ca, joursLus} (`vsProJours`, `vsProSomme` dans
`src/ventes_semaines.php`), sur les jours vendus du mois :

1. le **relevé des tickets du panel** (`svP{shop}:{jour}`, clé `b`, `vpLu`) là où il existe — celui
   du dashboard et d'Analyse réseau ; il n'existe que depuis `SV_DEBUT` (01/08/2026) ; un jour du mois
   en cours sans relevé valable se lit (`vpJour`, 8 listes au plus par magasin) ;
2. avant, la **copie de caisse** (`transaction` ⨝ `client.is_b2b`, comme la prime B2B), une lecture par
   an civil ; un mois clos que la copie couvre se grave brut, client par client (`vsPro{shop}:{AAAA-MM}`),
   le filtre TVA réappliqué à chaque lecture.

Un jour qu'aucune des deux ne connaît manque à `joursLus`. Tests : `php bin/tests_evolution_pro.php`.

## La maquette

`tout-haut.png`, `comptoir-haut.png`, `b2b-haut.png` : la maquette proposée. **Sa part B2B est
fictive** : les totaux sont les vrais (`../evolution-magasin/donnees.json`, lus au panel le
11/11/2026), le B2B de chaque mois est tiré d'une part de base par magasin, d'une saison et d'un
bruit à graine fixe (`donnees-api.json`, la forme de la réponse de l'endpoint). Les captures du code
ont été prises sur ces mêmes données, l'API simulée.

```bash
node docs/maquettes/evolution-canaux/generer.js
```

```bash
python3 -m http.server 8099 --bind 127.0.0.1
```

```bash
node docs/maquettes/evolution-canaux/capturer.js
```
