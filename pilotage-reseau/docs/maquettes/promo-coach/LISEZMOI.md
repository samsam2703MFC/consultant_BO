# Maquette — la promotion proposée par le Coach Vente (17/11/2026)

Demande de Sam : « enlever le tableau [la liste des promotions de l'onglet Promotions] et mettre là un assistant qui vient
de l'Assistant IA, avec une promotion pré-encodée ; si le franchisé valide, la promotion part pour 1 mois à partir de
lundi prochain (standard), et on en fait un PDF envoyé par mail à l'équipe. »

## Avant la fiche : créer un bundle qui monte la marge (17/11/2026, phases 1 à 3)

Demande de Sam : « le but est de créer des bundles (les phases avant la création dans le formulaire proposé) pour
augmenter la marge : coupler des produits qui ont une faible marge avec des produits à grande valeur ajoutée, pour
augmenter la marge de base. »

| Écran | Ce qu'il montre |
|---|---|
| `p1-constat.png` | 1 · Le constat : la marge de base de Halle (58,5 %), la part des pièces vendues sous cette marge (57 %) et à 78 % et plus (3,4 %) ; le nuage de chaque produit (marge × pièces vendues, échelle log), la zone « ils font le volume, pas la marge » et la zone « grande valeur ajoutée, peu vendus » ; les deux listes. |
| `p2-couples.png` | 2 · Les couples : chaque couple = un produit qui attire (faible marge, gros volume) + un produit qui rapporte (forte marge) ; séparés → prix du bundle, la marge du ticket avant → après, la marge en plus par ticket, par mois si 1 client sur 10 le prend, le moment. Les couples choisis : la marge en plus par mois et la nouvelle marge de base. |
| `p3-prix.png` | 3 · Le prix du couple choisi : la marge du bundle selon son prix (3,30 € → 4,20 €), la zone de remise conseillée (8 à 12 %), la marge du produit seul en repère ; à droite, le prix retenu et ses garde-fous. « Préparer la fiche du bundle » ouvre l'écran A (la fiche pré-encodée), puis B (lancement) et C (l'équipe). |

Vrais chiffres de Halle : `halle.json` (pièces vendues du 19/10 au 17/11/2026, GET /analyse/produits?mois=1 ; prix et
coûts de recette, /exploitation/promos-magasin/produits) ; `python3 generer.py` refait p1, p2 et p3. La marge de base :
somme (prix HT − coût) × pièces ÷ somme prix HT × pièces, sur les 181 produits chiffrés. « En plus / ticket » : la
marge du bundle moins celle du produit qui attire vendu seul. La prise (1 client sur 10) est une hypothèse.

À trancher aussi : les règles du Coach Vente pour former un couple (le produit qui attire : sous la marge de base et au
moins 300 pièces par mois ? le produit qui rapporte : 78 % de marge et plus ?), la remise conseillée (8 à 12 %), la
prise par défaut (1 client sur 10), et si l'on peut choisir plusieurs couples d'un coup (une fiche par couple).

## Le simulateur de couples (17/11/2026, écrans S-A et S-B)

Demande de Sam : « pour les couples, un simulateur, avec un bloc de marge sur une jauge coefficient ; quand je clique sur
un produit, un code couleur entre noir et or avec le produit à apparier pour augmenter la marge ».

| Écran | Ce qu'il montre |
|---|---|
| `s-a-pain-au-chocolat.png` | Phase 2, onglet « Le simulateur » (à côté de « Les couples proposés »). Les produits du magasin en tuiles, famille par famille. 1er clic : le produit qui attire (Pain au chocolat, cadre Ruby) ; tous les autres prennent leur couleur, du **noir** (le bundle baisse la marge du ticket) à l'**or** (il la monte le plus), en points de marge du ticket au prix conseillé ; la même famille est hachurée (ils se remplacent). 2e clic : le partenaire (Cappuccino). À droite : le prix (une réglette de 10 en 10 centimes, la remise conseillée en rouge), **la jauge du coefficient du ticket** (×1 → ×6, rouge sous ×1,67, orange jusqu'à ×2,5, vert au-delà ; l'aiguille grise du produit seul ×2,23, l'aiguille Ruby du bundle ×4,42) et **le bloc de marge** (marge du ticket 55,1 % → 77,4 %, en € HT 0,99 € → 3,07 €, par mois, la marge de base) ; les trois partenaires les plus dorés ; « Préparer la fiche ». |
| `s-b-baguette.png` | Le même simulateur après un clic sur Baguette Tradition (29,4 %, ×1,42 : rouge) : les couleurs se refont pour elle ; avec un cappuccino à 4,50 €, le ticket passe à 63,3 % et ×2,73 (vert). |

L'or est relatif au produit cliqué : le plus doré est le meilleur partenaire de CE produit. Les chiffres : `halle.json` ;
`python3 generer_simulateur.py` refait les deux écrans.

À trancher : la couleur se fait-elle sur les points de marge du ticket (montré) ou sur les euros de marge en plus ? Le
simulateur remplace-t-il « Les couples proposés » ou vit-il à côté (montré : deux onglets) ? Les tuiles : les 26 produits
les plus vendus et ceux à 78 % et plus (montré), ou tout le catalogue avec une recherche ?

## La fiche, le lancement, l'équipe (écrans A, B, C)

Les écrans A et B sont posés sur l'onglet Promotions **en ligne** (Halle) : la carte « Où les promotions sont utilisées »
reste, la liste des promotions est retirée et remplacée par la carte du Coach Vente.

| Écran | Ce qu'il montre |
|---|---|
| `a-proposition.png` | Le Coach Vente propose une promotion déjà encodée (proposition 1 sur 3) : le nom, ce que le client achète, ce qu'il paie, les canaux, la période (du lundi qui suit la validation, 1 mois), la caisse ; à droite ce qu'elle laisse (marge du duo, coefficient, le volume à gagner pour garder la marge) et pourquoi ce duo. En bas : ce qui se passe à la validation, puis « Valider et lancer lundi 23/11 », « Modifier », « Une autre proposition », « En discuter avec le Coach Vente ». |
| `b-validee.png` | Après la validation : où en est la promotion (créée au panel « à venir », en caisse lundi, fin, bilan du Coach Vente le lendemain de la fin), la fiche envoyée à l'équipe (destinataires, envoyé), télécharger / renvoyer, annuler avant lundi ; la liste des promotions lancées avec le Coach Vente. |
| `c-equipe.png` | L'e-mail reçu par l'équipe et la fiche PDF A4 jointe : le duo, les dates, la caisse, les phrases pour le proposer, la mise en place. **Ni marge ni coefficient** dans la fiche de l'équipe. |

Chiffres : les prix et les coûts de recette sont ceux de Halle au panel (croissant 1,80 € TTC, coût 0,68 € ; café 2,30 €
TTC, coût 0,15 €). Duo à 3,50 € : marge 2,47 € HT (74,8 %), coefficient ×3,97 ; séparés, ils laissent 3,04 € HT, d'où
+23 % de duos pour garder la marge. Prénoms d'équipe d'exemple, adresses masquées.

## À trancher avant de coder

1. **Les adresses de l'équipe** : d'où viennent-elles ? (les fiches du personnel au panel, ou une liste saisie dans
   Paramètres › Mails et automatisations).
2. **La création au panel** : le franchisé crée-t-il la promotion lui-même (le dashboard écrit au panel, comme l'assistant
   des promotions du cockpit), ou la validation part-elle au bureau, qui la crée ?
3. **« 1 mois »** : du lundi au même quantième le mois suivant moins un jour (23/11 → 22/12, montré ici), ou 4 semaines
   pleines (23/11 → 20/12, du lundi au dimanche) ?
4. **La liste des promotions** retirée de l'onglet : disparaît-elle tout à fait, ou reste-t-elle derrière un lien
   « Voir les 32 promotions en caisse » ?

Captures : `php -S 127.0.0.1:8099 -t pilotage-reseau` (racine du dépôt), puis
`node pilotage-reseau/docs/maquettes/promo-coach/capturer.cjs`.
