# Maquettes écrans — Contrôle qualité visuel (L'Atelier By)

Maquettes **statiques** (aucun code fonctionnel : pas de TensorFlow.js, pas de k-NN,
pas de persistance) illustrant les 4 écrans du futur prototype `maquette-qc-vision.html`
avant tout développement.

## Contenu

- `maquette-qc-vision-screens.html` — les 4 écrans dessinés en HTML/CSS pur
  (les vignettes de vitrine sont des SVG factices générés à l'affichage) ;
- `captures/01-chargement.png` — écran de chargement du modèle (~16 Mo) ;
- `captures/02-entrainement.png` — onglet Entraînement : classes, dépôt par lot,
  avertissement « échantillon insuffisant », galerie de références ;
- `captures/03-controle.png` — onglet Contrôle : les 4 verdicts (CONFORME,
  NON CONFORME, À ARBITRER, PHOTO INEXPLOITABLE), justification par les 3 références
  les plus proches, boutons d'arbitrage, compteur de session ;
- `captures/04-reglages.png` — onglet Réglages : 4 curseurs de seuils, export/import
  JSON, persistance, « Tout effacer ».

## Regénérer les captures

```bash
npm install playwright-core   # Chromium requis
node shot.js                  # cf. script : capture chaque .board en PNG @2x
```

Charte : Ruby Red `#8D1D2C`, Abricot Pastel `#F2C9A0`, blanc, noir.
Format : tablette paysage 1280×800, cibles tactiles ≥ 44 px.
