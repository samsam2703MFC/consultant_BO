# Fidélité, à quoi ça ressemble maintenant : la carte à points dans l'ERP, le bingo par son API

Demande du 18/11/2026 (Sam) : « à quoi ça pourrait ressembler maintenant, fais une maquette ». Elle suit
`../fidelite-bingo-erp` (les écrans de l'admin ERP habillés avec le handoff Claude Design). Elle respecte la règle
du même jour : « n'utiliser que la structure disponible, ne pas créer de table ou autre ».

**Le montage**, celui de `src/fidelite_bingo.php` (décision du 13/11/2026) :
- **dans l'ERP**, une seule carte de fidélité, **à points**, permanente. Ce sont les champs existants de
  `points_config` : 1 point par euro, paliers, bonus jour et heure, coefficients par magasin. Les cartes à
  tampons s'arrêtent ;
- **le bingo** se calcule dans le cockpit, uniquement par l'API de l'ERP. La case de la semaine se coche dès
  10 points gagnés dans la semaine (1 point = 1 €), lus dans `GET /shops/{id}/loyalty/customers`. Les bons sont
  des codes des **campagnes de bons existantes** de l'ERP (`POST /admin/voucher-campaigns/{id}/codes`), créés
  chaque lundi au nom du client ;
- les saisons, les thèmes des 13 semaines et les valeurs des bons sont dans le fichier
  `src/data/fidelite_bingo.json`, **pas dans une table**.

| Écran | Où | Ce qu'il lit | Capture |
|---|---|---|---|
| 1 · Cartes de fidélité | ERP admin (`overview.twig`) | `GET /loyalty/cards` : la carte à points active, la carte café arrêtée | `captures/1-erp-cartes.jpg` |
| 2 · La carte à points, étape 2 | ERP admin (`form.twig` + `points.twig`) | Les champs de `points_config` ; « Carte produit » décochée | `captures/2-erp-carte-points.jpg` |
| 3 · Fidélité › Programme | Cockpit, ERP franchisé › Fidélité | L'état des pièces dans l'ERP, les règles et la carte de la saison (fichier de saison), ce que l'API ne permet pas | `captures/3-cockpit-programme.jpg` |
| 4 · Fidélité › Magasins | Cockpit | Un magasin (Halle) : la case semaine par semaine, à relancer, les membres et leurs 13 cases | `captures/4-cockpit-magasin.jpg` |
| 5 · Fidélité › Un client | Cockpit | La carte de bingo d'un client, ses points (ERP), ses bons (codes des campagnes) | `captures/5-cockpit-client.jpg` |

Les écrans 3 à 5 sont l'habillage Claude Design des trois parties de la page de test `public/fidelite-test` :
A · l'admin, B · le magasin, C · la carte du client. La maquette est datée du jeudi 14/01/2027, semaine 8 de
l'hiver. Clients et chiffres sont **fictifs**. Le scénario de Julie D. est celui du handoff : 6 cases sur 13, S6
manquée, 6,40 € sur 10 € cette semaine, Bon des Rois à utiliser.

## Ce que l'API de l'ERP ne permet pas (écran 3, « Pas dans l'API de l'ERP »)

- le **×2 après la case**, pour un client, jusqu'au dimanche : l'ERP n'a que des bonus par jour et heure, pour tous ;
- le **produit de la semaine ×2** : pas de coefficient par produit ;
- l'**échange automatique au palier** : le client échange lui-même ses points ;
- les **coefficients personnels** (jour perso, offre perso).

Ces règles demanderaient un changement du moteur de l'ERP, donc une demande à son équipe. L'écran les signale et
ne les simule pas.

## À décider

- Activer la campagne « Bingo hiver · 10 € » (super bon) dans l'ERP avant la semaine 13.
- Valider les montants (seuil 10 €, bons de 2 €, super bon de 10 €, provisoire) et les thèmes du printemps, de
  l'été et de l'automne (`aValider` dans le fichier de saison).
- Arrêter les cartes à tampons (`ends_at`) au lancement de la saison.

## Refaire les captures

```bash
node shot.js
```

`index.html` reprend la feuille et les composants de `../fidelite-bingo-erp` (`maquette.css`, `donnees.js`), puis
`extra.css`, `donnees.js` (règles et saison copiées du fichier de saison, clients fictifs) et `ecrans.js`.
