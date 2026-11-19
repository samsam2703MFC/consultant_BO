# Maquette — la mise en page en assistant (17/11/2026)

Demande de Sam : « la mise en page pourrait se faire sous forme d'assistant, et la dernière page de l'assistant serait
la mise en page finale, s'il veut déplacer une catégorie en drag and drop » ; « c'est aussi catégorie par catégorie
sélectionnée ».

L'étape 3 (Présentation) de Mon catalogue devient un petit assistant, sous les 5 étapes :

| Écran | Ce qu'il montre |
| --- | --- |
| `a-categorie.png` | Une étape par catégorie choisie à l'étape 2 (Halle : Pain Tradition, Cougnou, Plateau Sandwich). « Comment présenter ces 11 produits ? » : 4 mises en page, chacune avec le nombre de pages qu'elle donne (rouge au-delà de 2) ; l'aperçu des pages côte à côte ; « un produit seul sur une page, avec sa promotion » ; gabarit, catégorie manuscrite. |
| `b-pleine-page.png` | La pleine page cochée : le produit choisi en grand, puis « Et les 7 autres ? » ; l'aperçu de la pleine page et de la liste. |
| `c-finale.png` | La mise en page finale : toutes les pages dans l'ordre, rangées par catégorie ; une catégorie se prend par ⠿ et se dépose (ses pages suivent) ; les pages de la marque restent en place ; nombre de pages, format, langues. |

Les aperçus sont les vrais rendus du catalogue de Halle (« Mes Buches »), lus dans le dashboard en ligne sans rien
enregistrer (`collecter.cjs`), puis posés à la place de l'étape 3 actuelle (`generer.cjs`, `maquette.css`) :

```
node collecter.cjs && node generer.cjs        # SEUL=c-finale pour un seul écran
```

Rien n'est codé : à valider avant.
