# Tablette des vendeuses (Book vendeuses)

`public/tablette/` est la version compilée de l'application tablette, dont les
sources vivent dans leur propre dépôt :
https://github.com/samsam2703MFC/pwa_sales_tablet (React + Vite, PWA).
Le commit compilé est noté dans `public/tablette/VERSION`.

Adresse : `…/consulant_bo/tablette/?shop=<id du magasin>` (lien par magasin
dans l'écran « Tablette vendeuses » du cockpit). Ce dossier est l'identité de
l'application installée sur les tablettes : ne pas le renommer.

Les données viennent de `GET api/cockpit/tablette/book` (src/tablette.php) ;
les photos de `uploads/tablette/` (vignettes 640 px) et
`uploads/plano/panel/`. Le mode hors ligne et l'installation sur l'écran
d'accueil n'existent qu'en https.

Recompiler et installer (depuis une copie du dépôt de la tablette placée à
côté de celle-ci) :

    npm ci
    npm run build
    rm -rf ../consultant_bo/public/tablette
    cp -r dist ../consultant_bo/public/tablette
    git rev-parse HEAD > ../consultant_bo/public/tablette/VERSION

Puis committer ici (`Tablette vendeuses : build <sha>`) et déployer comme
d'habitude. Ne pas mettre de `.htaccess` dans `public/tablette/` : la règle
404 des fichiers absents est dans `public/.htaccess`.
