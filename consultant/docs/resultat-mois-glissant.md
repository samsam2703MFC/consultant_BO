# Résultat › Mois : le CA du réseau sur douze mois glissants (27/11/2026)

Sous les tuiles du mois, dans l'écran Résultat, vue **Mois**. Code : `consultant/public/assets/js/ca-glissant-vals.js`
(les valeurs), `ca-glissant-tpl.js` (le gabarit), branché dans `shared/public/assets/js/app.js` et `templates.js` ; par
catégorie, la route `GET /ventes/groupes-mois?du=AAAA-MM&au=AAAA-MM` (`consultant/src/ventes_groupes.php`, contrat :
`shared/docs/contrat-api.md`).

## La fenêtre

Les douze derniers **mois clos** : si le mois regardé est en cours, la fenêtre s'arrête au mois d'avant (l'en-tête le
dit : « mois clos, le mois en cours n'y entre pas »). L'en-tête donne le titre (« Le CA du réseau — 12 mois
glissants », ou « Traiteur — le CA du réseau, 12 mois glissants ») et la période (« premier mois année → dernier mois année », les mois en abrégé).

## La rangée des six cases

**Tout le CA**, puis les cinq catégories principales : **Traiteur, Boulangerie, Viennoiseries, Quiches, Tartes**.
Chaque case montre le CA des 12 mois et, en couleur, l'évolution à magasins comparables face aux 12 mois d'avant ;
dessous, le nombre de magasins (Tout le CA) ou la part du CA (« n % du CA ») pour une catégorie. Une case est un
bouton : cliquer en dessine la courbe ; la case choisie est cernée.

Les ventes par catégorie se lisent au panel magasin par magasin et mois par mois (le CA TTC de chaque groupe de
catégories). La première ouverture prend du temps : la case dit « lecture n / N… » (relevés lus sur relevés à lire) et la section « Lecture des
ventes par catégorie au panel — une fois, puis gardé ». Un mois clos est gardé une fois pour toutes, le mois en cours
se relit à l'heure. Un relevé que le panel ne rend pas est compté « illisible » ; une catégorie que le panel ne rend
pas du tout dit « illisible au panel ».

## La section

- **Trois chiffres** : *12 mois glissants* (le CA TTC encaissé, ou la part de la catégorie) ; *Face aux 12 mois
  d'avant* (réseau entier, ouvertures comprises, avec le CA du N-1) ; *À magasins comparables* (les magasins qui
  avaient un mois plein un an plus tôt).
- **La courbe** : le CA mois par mois (trait plein) face au **N-1** en pointillé gris, les mêmes mois un an plus tôt ;
  étiquetés : le dernier mois et le plus haut des douze. La ligne est coupée là où un mois manque. L'axe garde la
  décimale de son pas (« 22,5 k€ », pas « 23 k€ ») : utile pour les petites catégories comme les Quiches.
- **Au survol d'un mois**, une bulle : le mois, le réseau face au N-1 (avec l'écart), puis chaque magasin, son CA et
  son écart (« sans N-1 » s'il n'avait pas de CA un an plus tôt).

Sans CA lu sur les douze mois, la section dit « Aucun CA mensuel lu pour ces douze mois. » ; si l'année d'avant ne se
lit pas, « année d'avant illisible au panel ».
