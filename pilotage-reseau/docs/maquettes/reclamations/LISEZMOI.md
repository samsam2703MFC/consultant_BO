# Réclamations fournisseur dans le dashboard magasin — trois propositions

Créer une réclamation (le POST existe : `POST /fournisseurs/reclamation` →
panel `POST /material-complaints`), y joindre des photos et une note, et suivre
les réclamations passées, depuis le dashboard du magasin.

Données réelles lues le 01/10/2026 : les 27 réclamations de Gosselies sur 12 mois
(`/fournisseurs/reclamations?mois=12`), ses motifs, matières et livraisons
(`/fournisseurs/reclamation-refs?shop=3`). L'exemple de saisie (pains au
chocolat, 60 pièces) et ses deux photos — prises au comptoir de Gosselies — sont
une illustration.

| | Proposition | Planche |
|---|---|---|
| A | Interrupteur « Réclamations » dans la barre → tiroir « Nouvelle » / « Mes réclamations » ; 4e onglet au téléphone | `planche-a.jpg` |
| B | Bascule « Les chiffres / Les réclamations » : compteurs, formulaire en 4 étapes, historique complet | `planche-b.jpg` |
| C | Téléphone « photo d'abord » : bouton flottant, photos → produit et problème → envoyée | `planche-c.jpg` |

Point ouvert avant de coder : les réclamations du panel portent des pièces
jointes (`attachments`), mais la route utilisée aujourd'hui pour créer
(`POST /material-complaints`) ne prend que les champs texte. Il faut la route de
dépôt des photos d'une réclamation (celle qu'utilise l'application du magasin).

Régénérer : serveur statique sur 8099 depuis la racine, puis
`node docs/maquettes/reclamations/generer.js`.
