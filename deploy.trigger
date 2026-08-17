Cache navigateur : forcer la revalidation du shell et des modules JS/CSS.

Apache ne servait aucun Cache-Control, seulement un ETag : le navigateur
applique alors son cache heuristique et continue d afficher l ancienne version
apres une livraison. L ecran parait inchange alors que le serveur est a jour.
mod_headers est active et la recette verifie desormais l en-tete reellement
servi, pas seulement present au fichier.
