# Résultat › Jour : cliquer un magasin ouvre son dashboard

**Statut : maquette A retenue et codée le 27/10/2026**, avec trois choix.
Le dashboard s'ouvre dans un **nouvel onglet**, en vue **`ops`**, et
**seulement sur l'onglet Jour** : les lignes de Semaine et Mois ne changent pas.
Le code est dans `public/assets/js/app.js` (`valsResultatJour`, `ouvrir` et
`deplier` des lignes), dans `public/assets/js/templates.js` (`tplResultatJour`)
et dans `public/assets/css/app.css` (bloc « la ligne ouvre le dashboard du
magasin »). La maquette B reste ici pour mémoire.

Demande du 27/10/2026 : « au clic sur la ligne du magasin, je dois être redirigé
sur le dashboard avec l'id magasin » —
`…/dashboard/?shop=4&vue=ops&date=2026-10-27`.

Aujourd'hui, le clic sur une ligne **déplie le compte de résultat du magasin**
sous le tableau (cascade, objectif, planning, ventes par catégorie, le jour dans
le mois). La redirection prend cette place : les deux maquettes disent ce que
devient ce détail.

Les captures sont le **vrai cockpit**, nourri par le jeu d'essai de la capture
du 27/10 (`jour-2710.json`, `classement-2710.json`) ; la variante est posée
par-dessus juste avant la capture. Halle est le shop 4 ; les shopId de Corbais (2),
Gosselies (3) et Sombreffe (5) sont illustratifs : le code prend `shopId` dans
la réponse de `/exploitation/jour`.

## Maquette A — toute la ligne est le lien

`a.png`

1. Un clic n'importe où sur la ligne ouvre le dashboard Opérationnel du magasin
   à la date regardée, **dans le même onglet**. Ctrl/⌘-clic ou clic molette :
   nouvel onglet.
2. Au survol : la ligne s'éclaire, un filet rubis à gauche, le nom souligné, une
   flèche › rubis en bout de ligne, l'adresse dans la barre d'état.
3. Le compte de résultat déplié passe sur le **petit bouton ▸** devant le nom.
4. La mini-courbe des ventes par heure garde sa fenêtre heure par heure.

C'est la demande prise au mot. En contrepartie, le détail du magasin demande
de viser le ▸.

## Maquette B — un lien sous chaque nom, la ligne garde son détail

`b.png`

1. Sous chaque nom, un petit lien **Dashboard ↗** ouvre le dashboard Opérationnel
   à la date regardée, **dans un nouvel onglet**, comme « Dashboard magasin ↗ » du
   menu.
2. Le clic sur la ligne ne change pas : il déplie le compte de résultat.
3. Dans le compte de résultat déplié, le même lien en grand :
   « Dashboard de Corbais › ».

L'écran actuel ne change pas, mais la ligne seule ne mène pas au dashboard.

## Commun aux deux

- Adresse : `dashboard/?shop=<shopId>&vue=ops&date=<date regardée>`. Elle est
  relative à la racine du cockpit, comme l'entrée du menu (`ext:dashboard/`).
  Elle marche donc sous `/consulant_bo/` comme en local. Si l'on recule de
  quelques jours avec ◂, on arrive sur le dashboard de ce jour-là.
- La ligne Réseau et les lignes d'un magasin fermé ne mènent nulle part, ou
  au dashboard sans chiffres pour un magasin fermé (à trancher).

## Tranché le 27/10/2026

- **A.**
- **Nouvel onglet**, comme « Dashboard magasin ↗ » du menu.
- **`vue=ops`**.
- **Onglet Jour seulement** : Semaine et Mois gardent leur clic actuel.

## Regénérer les captures

```bash
php -S 127.0.0.1:8099 -t .          # depuis la racine du dépôt
node docs/maquettes/resultat-vers-dashboard/capturer.js
```
