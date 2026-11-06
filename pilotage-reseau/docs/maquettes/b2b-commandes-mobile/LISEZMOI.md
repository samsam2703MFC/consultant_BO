# Le B2B et les commandes en détail, au téléphone — trois maquettes

**Statut : codée le 06/11/2026** — écrans 2 et 3. Sam a choisi la **feuille du bas (écran 2)** pour le B2B, et
**les noms des sociétés s'affichent**. Serveur : `GET /exploitation/pro/detail` (`src/ventes_pro.php`) et
`GET /exploitation/commandes/detail` (`src/canaux_offres.php`), voir `docs/contrat-api.md`. Dashboard :
`opDuelPro` (la jauge se touche), `opCmdLigne` (la ligne « Commandes du jour » sous la jauge du comptoir), feuilles
`b2bRendre` et `cmdmRendre` (`#db-b2b`, `#db-cmdm`, styles `bm-*`). Au bureau, les mêmes feuilles s'ouvrent en modale
depuis l'onglet Opérationnel. Captures de la version codée, API simulée (sociétés fictives) : `code-ops.png`,
`code-b2b.png`, `code-commandes.png`, `code-bureau-b2b.png`.

Écarts à la maquette, une fois codé : la feuille B2B garde deux onglets, le jour regardé et le même jour la semaine d'avant,
sans montant (Sam, 06/11 au soir : « 5 vendredis », mis en ligne une heure, est retiré) ; le rapprochement distingue en plus les commandes **payées un autre jour** (le panel les
encaisse souvent à la commande, avant le jour du retrait) et celles **à venir**.

Demande du 06/11/2026 : « dans le dashboard day, je veux
pouvoir vérifier le CA en commandes ; en cliquant sur les ventes B2B, avoir le détail en modale ; le
détail des commandes aussi en modale ; pour les commandes du magasin, il me faut comptoir et
webshop ». Précisé ensuite : **au téléphone**, onglet Opérationnel. **Un appui sur les jauges B2B
ouvre une modale ou un tableau déroulant** avec le détail. **Pas de vignette à bord coloré** :
cartes blanches, filet fin, rien sur le côté.

| | Écran | Fichier |
|---|---|---|
| 1 | Un appui sur la jauge B2B : le tableau se déroule **dans la carte**. Une ligne par société : aujourd'hui, le vendredi dernier, et sa fréquence sur les 4 derniers vendredis. Sous la jauge du comptoir, la ligne « Commandes du jour » (595,60 €, comptoir 6, webshop 9, 3 à vérifier) | `ecran-1.png` |
| 2 | La même demande **en feuille du bas** (la modale du téléphone) : le jour choisi (aujourd'hui, vendredi dernier, les 4 derniers), quatre chiffres (CA B2B, tickets, panier, à facturer), chaque ticket et ses articles, ce que les sociétés achètent | `ecran-2.png` |
| 3 | « Commandes du jour » en feuille du bas : Toutes, Comptoir, Webshop. En tête, **le CA en commandes rapproché** : encaissées en caisse, payées en ligne, prêtes pas retirées, remises sans ticket. Puis chaque commande ; une commande remise sans ticket montre ses articles et le ticket caisse qui pourrait être le sien | `ecran-3.png` |
| | Les trois côte à côte | `planche.jpg` |

Les écrans 1 et 2 répondaient à la même demande : **la feuille du bas (écran 2) est retenue**, le tableau dans la
carte (écran 1) n'est pas codé.

## Réel, illustratif

**Réel** : les deux jauges sont celles de la capture de Halle du vendredi 6/11 à 18:38 — comptoir
1 753 € pour 1 700 € d'objectif, projection 1 757 € ; vendredi dernier 1 685 € à 18:33, 1 727 € la
journée ; B2B 0 ticket aujourd'hui, 327 € le vendredi dernier, part habituelle 12 % (234 €).

**Illustratif** : les sociétés (« Société A… E »), les tickets pro et leurs articles (327 € comme la
jauge), les 15 commandes du jour et leurs paiements. Les totaux sont calculés dans la page, ils se
tiennent : 167,60 € en caisse + 296,60 € en ligne + 103,60 € pas retirés + 27,80 € sans ticket
= 595,60 € pris en commande.

## Ce qu'il fallait pour coder (06/11/2026, avant le codage)

- **Le B2B** : les tickets pro du jour sont déjà lus (`/exploitation/pro`, `ventes_pro.php`, pro =
  fiche client avec une TVA valide) ; il faut y ajouter les **lignes de chaque ticket** (les
  articles, déjà dans `/shops/{id}/transactions`) et le **nom de la société**. Le dashboard ne montre
  aujourd'hui que l'heure et le montant (décision du 03/10/2026). **Tranché le 06/11 : les noms s'affichent.** La fréquence sur 4 vendredis se lit dans les jours
  gravés (`vpJoursLus`).
- **Les commandes** : `/shops/{id}/client-orders` porte déjà le canal (`is_webshop`,
  `fulfilment_mode`), le statut, le montant, les articles (`products`) et le ticket qui l'encaisse
  (`id_transaction`) — voir `coCommandes()` dans `src/canaux_offres.php`. Le rapprochement en
  découle : `id_transaction` posé = en caisse ; webshop sans `id_transaction` et payé = en ligne ;
  remise sans `id_transaction` = à vérifier ; retrait passé, pas remise = pas retirée. Le « ticket
  qui pourrait être le sien » : un ticket sans client, même montant, dans les 10 minutes de la remise.
- À vérifier avant de coder : que le panel rende bien le mode de paiement d'une commande webshop
  (payée en ligne ou au retrait). Les tables locales `ws_orders`, `ws_office_delivery_sites` sont
  retirées (#92) : tout se lit au panel.

## Régénérer

```
python3 -m http.server 8099      # depuis pilotage-reseau/
node pilotage-reseau/docs/maquettes/b2b-commandes-mobile/capturer.js
```

`index.html?v=1`, `?v=2`, `?v=3`, `?v=planche`. `mq.css` reprend les règles du dashboard déployé
(en-tête du téléphone, jauges, onglets) et ajoute le tableau déroulant et la feuille du bas.
