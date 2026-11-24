# Commandes : mieux en UX — trois maquettes

**Statut : propositions, à choisir.** Rien n'est codé.

Demande du 24/11/2026, une fois la page Commandes en ligne (Liste et Tournées, au jour, à la semaine et au mois) :
« qu'est-ce que tu peux proposer de meilleur en termes d'UX ? fais 2-3 screenshots maquette ». Magasin 3, Gosselies ;
la journée est celle de `../commandes-tournees/donnees.js` (fictive), mardi 24/11 à 10:40.

## Ce qui gêne dans ce qui est en ligne

- **Deux interrupteurs qui se combinent** (Jour · Semaine · Mois et Liste · Tournées) : six écrans, et il faut deviner
  lequel répond à la question du moment.
- **Tout est à lire, rien n'est à faire** : la page montre l'état des commandes, mais le magasin ne peut ni marquer une
  commande prête, ni dire qu'une tournée part, ni relancer un bureau. Les décisions (placer une livraison, les bureaux
  qui n'ont pas commandé) sont éparpillées dans la page.
- **Le livreur n'a rien** : la feuille de route s'imprime, et le magasin ne sait pas où en est la tournée.
- **Le démarrage est vide** : à Gosselies, aucune tournée ; il faut les créer et chercher les bureaux un par un.

## Les trois

| | Écran | L'idée |
|---|---|---|
| **A** | `ecran-a.png` — **Aujourd'hui** | Trois onglets par intention (Aujourd'hui · Semaine et mois · Tournées et bureaux) à la place des deux interrupteurs. En tête, **le prochain départ** et son compte à rebours : chaque commande, prête ou pas, « Marquer prête », « chargée », puis « La tournée part ». À droite, **la boîte « À faire »** : chaque décision avec la décision proposée en un clic (placer la livraison sans tournée dans la tournée qui passe à 600 m ; relancer les 6 bureaux avant 16:00 ; la précommande pas retirée d'hier). |
| **B** | `ecran-b.png` — **le téléphone du livreur** | « Envoyer à Nadia » ouvre la tournée sur son téléphone, sans application : les arrêts dans l'ordre (itinéraire, appel), un arrêt (la consigne, ce qu'il faut remettre, le paiement, « Livré » en grand, « Un problème »), la fin de tournée (le bilan, l'argent à rapporter). Au magasin, chaque « Livré » passe en direct dans Commandes. |
| **C** | `ecran-c.png` — **démarrer en deux minutes** | Les tournées proposées d'après les commandes des clients pro des 90 derniers jours : la zone (code postal), l'heure habituelle, le rythme et le panier de chaque bureau ; les cas douteux à part (trop loin, trop rares, qui viennent chercher) ; « Créer les 3 tournées ». Plus tard, un bureau qui commande souvent hors tournée est proposé dans « À faire ». |

**Recommandation : A et C d'abord, B ensuite.** A change le quotidien (on agit au lieu de lire) et ne demande presque
rien de nouveau au serveur ; C lève le premier obstacle à Gosselies (zéro tournée). B demande un lien signé pour le
livreur et un état « en route / livrée » tenu au cockpit : plus de travail, à faire une fois A en place.

## Ce qu'il faudrait pour coder

- **A** : « Marquer prête » écrit au panel (l'état `completed` d'une commande, à vérifier : route d'écriture de
  `/client-orders/{id}`) ; « chargée » et « La tournée part » se gardent au cockpit (une table `panel_tournee_jour` :
  tournée, jour, chargées, partie à, rentrée à). Le rappel aux bureaux : le modèle de relance du portail Commercial,
  ouvert au dashboard. Les onglets remplacent les deux interrupteurs (les mêmes vues, rangées autrement).
- **B** : un lien signé par tournée et par jour (comme les liens de la fiche ticket), une page téléphone hors session,
  `POST …/livre` par arrêt ; la commande passe « livrée » au cockpit, et au panel si une route le permet.
- **C** : les commandes des clients pro sur 90 jours (une lecture du panel), regroupées par code postal et par créneau
  (avant 10:00, 10:00 – 14:00) ; un regroupement simple, ajustable, sans optimisation d'itinéraire.

## Régénérer

```
node panel/docs/maquettes/commandes-ux/capturer.js
```

`index.html?v=a`, `?v=b`, `?v=c` ; les styles et les données viennent de `../commandes-tournees/`.
