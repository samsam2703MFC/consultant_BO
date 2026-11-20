# La vidéo de la publicité « Lunch au bureau »

15 secondes, quatre photos du site de la marque (atelierby.be, rangées dans `photos/`) et une carte de fin, au design
system (Gotham, rubis #8D1D2C, papier). `video.html` est l'animation (`?format=9x16|4x5`, `?langue=fr|nl`,
`window.poser(t)`) ; `rendre.cjs` la rend image par image (Chromium, 30 i/s) puis l'assemble avec ffmpeg en MP4
H.264, sans son, dans `commercial/public/commercial/meta/` (servi à `/commercial/meta/`), avec une affiche JPEG.

```
node commercial/tools/video-lunch/rendre.cjs                  # fr, 9:16 et 4:5
node commercial/tools/video-lunch/rendre.cjs --langue nl      # la version néerlandaise, celle de Halle (rendue le 20/11/2026)
```

Le portail montre la vidéo et le texte du post (Leads Meta, « La publicité ») : `smPubLunch` dans
`commercial/src/commercial_meta.php`. Changer un texte de la vidéo : `TEXTES` dans `video.html`, puis rendre à nouveau.
