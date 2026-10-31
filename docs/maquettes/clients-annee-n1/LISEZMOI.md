# Clients par mois, 2026 face à 2025 — deux maquettes

**Statut : maquette A choisie (24/10/2026) et codée — `public/assets/js/clients-annee-vals.js`, `clients-annee-tpl.js`, `n1Mois` dans `src/ventes_semaines.php`.**

La demande (24/10/2026) : dans le suivi à l'année des résultats des magasins
(**Cockpit › Résultat › Année › 1 an — 2026**), voir le nombre de clients mois par mois sur
l'année ouverte, comparé à N-1. Un **graphique ligne** qui montre les deux années sur les
douze mois — une analyse annuelle du levier trafic, l'année complète face à l'année passée.

Aujourd'hui l'onglet montre la cible réseau, la trajectoire cumulée du CA et le tableau des
magasins (`avant.png`) : aucun chiffre de clients.

## Le graphique (commun aux deux maquettes)

- **2026 en trait plein bordeaux, 2025 en pointillé gris**, les douze mois sur l'axe ; la bande
  entre les deux est **bleue quand 2026 passe devant, orange quand il recule** — les codes de
  l'onglet Trafic clients (`svgCourbe`, `templates.js`).
- **2025 est tracé sur l'année complète** : novembre et décembre 2025 restent visibles, ce sont
  les mois à battre.
- **Le mois en cours** (octobre, 24 jours lus) est prolongé au rythme du mois (pointillé, point
  creux) pour rester comparable à un mois entier ; son **écart**, lui, se calcule **à date** :
  2025 arrêté au même jour servi (1–24 octobre 2025), comme `GET /ventes/semaines`.
- Sous chaque mois, **la pastille de l'écart** : vert ≥ +3 %, rouge ≤ −3 %, ocre entre les deux
  (mêmes seuils que les barres jumelles de Trafic clients).
- **Les mois d'ouverture ne sont pas reliés** (Halle, 7 jours en février 2026 ; Sombreffe,
  6 jours en avril 2025) : partiels, ils feraient croire à une chute. La pastille dit
  « ouverture », la bulle garde le chiffre.
- **Une fermeture se signale sous la pastille** : Gosselies, juillet 2025 « 7 j fermés » —
  d'où le +47,7 %, qui n'est que +14,4 % par jour ouvert (dans la bulle).
- **Survol d'un mois** : les clients des deux années, les jours ouverts, les clients par jour,
  l'écart en clients et en %.
- **En tête, trois chiffres** : clients 2026 à date, 2025 sur la même période, l'écart. Sur les
  mois comparables seulement (Sombreffe : mai → oct.) ; Halle n'a pas de 2025 et le dit.
- **Le réseau** compte tous les magasins par défaut (+33,5 %, dont les ouvertures) et donne
  à côté l'écart **à périmètre constant** (−0,9 %) ; un bouton « constant » ne garde que les
  magasins qui ont un 2025.

## Les deux maquettes

| | Où | Apport | Limite |
|---|---|---|---|
| **A — un graphique, le magasin au choix** (`a.html`, `a.jpg`, `a-gosselies.jpg`) | entre la trajectoire et le tableau | grand, lisible, toutes les valeurs écrites ; puces Réseau / Corbais / Gosselies / Sombreffe / Halle avec l'écart de l'année ; un clic sur une ligne du tableau choisit le magasin ; une colonne « Clients 2026 vs 2025 » dans le tableau | un magasin à la fois ; la carte ajoute environ 540 px |
| **B — une courbe par magasin** (`b.html`, `b.jpg`) | sous le tableau | tout le réseau d'un coup d'œil : le réseau en grand, puis un graphique par magasin (chacun à son échelle) ; une mini-courbe dans la nouvelle colonne du tableau | courbes plus petites, valeurs au survol seulement ; la section ajoute environ 1 020 px |

Les deux maquettes sont interactives (puces, bouton de périmètre, survol). Le mode « clients
par jour ouvert » existe dans le moteur (`?mode=jour`) mais n'a pas de bouton : la demande est
le nombre de clients.

## Données

**Réel**, relu en lecture seule le 24/10/2026 vers 8 h 30 (`reel-clients-2026.json`) :
`GET /ventes/semaines?shop={2,3,4,5}&vue=annee&date=2026-10-24` (2026, et 2025 arrêté au même
jour servi) et `…&date=2025-12-31` (2025 complet, avec les jours ouverts). Le réseau est la
somme des quatre magasins ; la trajectoire et le tableau du haut reprennent l'écran en ligne
(contexte, grisé).

## Pour le coder (une fois la maquette choisie)

- **Lecture** : `GET /ventes/semaines?shop=…&vue=annee&date=…` existe et rend exactement les
  douze mois face au N-1 arrêté au même jour (`ep_ventes_semaines`, `src/ventes_semaines.php`) ;
  `GET /ventes/reseau?vue=annee` rend tous les magasins en un appel (≈ 5 s). **Un manque** : les
  mois futurs n'ont pas de N-1 (`n1: null`) — pour tracer novembre et décembre 2025, lire le
  mois complet de l'an passé quand la période est future (petit changement dans `vsMagasin`),
  ou reprendre `/stores/perf` (tickets 2025 par mois, déjà lus par l'écran).
- **Écran** : `tplObjectifs` (`templates.js`, bloc `isH1`) et ses valeurs dans `app.js` ;
  l'année suit l'exercice ouvert (`this.exo()`).
- `generer.js` refait `donnees.js` et les captures : `node docs/maquettes/clients-annee-n1/generer.js`
  (ajouter `--autonome <fichier>` pour une page unique, fontes embarquées).
