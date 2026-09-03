#!/usr/bin/env python3
"""
Construit public/assets/data/population_grid_2021.json — la grille de population
du scouting commercial : les cellules de 1 km² du recensement 2021 (StatBel,
diffusé par Eurostat/GISCO), chacune rattachée à sa commune, avec la surface
officielle des communes.

Pourquoi : le site de StatBel bloque les téléchargements automatiques (captcha),
mais Eurostat/GISCO diffuse la même grille (Census 2021 grid V2.0) et les
contours communaux LAU sans restriction. Le fichier produit est statique, livré
avec public/, et lu par l'écran (scouting.js, loadGrid) : les ménages d'un rayon
sont ceux des cellules qu'il couvre, là où les gens habitent.

Étapes (à rejouer pour une nouvelle édition) :
  1. Grille : https://gisco-services.ec.europa.eu/census/2021/Eurostat_Census-GRID_2021_V2-0.zip
     (728 Mo). Le CSV fait 8,5 Go : on le filtre en flux sans l'extraire —
        unzip -p Eurostat_Census-GRID_2021_V2-0.zip ESTAT_Census_2021_V2.csv | grep -a ',A,T,BE_' > be_T.csv
     (une ligne par cellule belge, statistique T = population totale).
  2. Contours : https://gisco-services.ec.europa.eu/distribution/v2/lau/download/ref-lau-2024-01m.geojson.zip
     → LAU_RG_01M_2024_3035.geojson (même projection que la grille : EPSG:3035).
  3. Communes OpenStreetMap telles que l'écran les connaît : les 9 secteurs du
     cache (GET /scouting/tiles/{n}) dans un dossier, t0.json … t8.json — pour
     aligner les codes NIS (fusions 2025, arrondissement de La Louvière).
  4. python3 bin/scouting_grille.py be_T.csv LAU_RG_01M_2024_3035.geojson dossier_tiles sortie.json

Dépendances : pyproj, shapely (pip install pyproj shapely).
Licence des données : Eurostat/GISCO, réutilisation libre avec mention de la
source (CC BY 4.0) ; contours © EuroGeographics.
"""
import csv, json, math, os, re, sys
from pyproj import Transformer
from shapely.geometry import shape, Point
from shapely.strtree import STRtree

if len(sys.argv) != 5:
    sys.exit('usage : scouting_grille.py be_T.csv LAU_3035.geojson dossier_tiles sortie.json')
csv_path, lau_path, tiles_dir, out_path = sys.argv[1:5]

# --- contours et surfaces officielles des communes (LAU 2024) ----------------
d = json.load(open(lau_path))
feats = [f for f in d['features'] if f['properties'].get('CNTR_CODE') == 'BE']
del d
geoms, ids, areas, pop_lau = [], [], {}, {}
for f in feats:
    p = f['properties']
    nis = str(p['GISCO_ID']).split('_', 1)[1]
    g = shape(f['geometry'])
    geoms.append(g); ids.append(nis)
    areas[nis] = round(float(p.get('AREA_KM2') or g.area / 1e6), 2)
    pop_lau[nis] = int(p.get('POP_2024') or 0)
tree = STRtree(geoms)

# --- cellules : centre en WGS84, commune par point-dans-polygone --------------
to_wgs = Transformer.from_crs('EPSG:3035', 'EPSG:4326', always_xy=True)
cells, total = [], 0
with open(csv_path) as fh:
    for line in fh:
        m = re.search(r'BE_CRS3035RES1000mN(\d+)E(\d+)', line)
        if not m:
            continue                       # « BE_unallocated » : population sans cellule
        row = next(csv.reader([line]))
        if row[2] != 'T':
            continue
        pop = int(float(row[5])) if row[5].strip() else 0
        if pop <= 0:
            continue
        e, n = int(m.group(2)) + 500, int(m.group(1)) + 500
        pt = Point(e, n); nis = ''
        for idx in tree.query(pt):
            if geoms[idx].contains(pt):
                nis = ids[idx]; break
        if not nis:                        # bord de mer, frontière : la plus proche à moins de 1,5 km
            idx = tree.nearest(pt)
            if geoms[idx].distance(pt) <= 1500:
                nis = ids[idx]
        lon, lat = to_wgs.transform(e, n)
        cells.append([round(lat, 4), round(lon, 4), pop, nis]); total += pop

# --- alignement sur les codes NIS d'OpenStreetMap ---------------------------
# OSM porte les codes 2025 des communes flamandes fusionnées ; LAU 2024 porte
# encore les anciennes. Un ancien code dont les cellules tombent en majorité
# dans la boîte englobante d'une commune OSM inconnue de LAU (donc née d'une
# fusion) est relabellisé en bloc — ses cellules restent celles de son contour
# exact — et la surface de la nouvelle commune est la somme des anciennes. Un
# code LAU absent d'OSM pour une autre raison (commune que l'écran n'a pas
# encore lue) est gardé tel quel.
def dist(a, b, c, dd):
    p = math.pi / 180; x = (c - a) * p; y = (dd - b) * p * math.cos((a + c) / 2 * p)
    return math.sqrt(x * x + y * y) * 6371
osm = {}
for i in range(9):
    for c in json.load(open(os.path.join(tiles_dir, 't%d.json' % i)))['c']:
        osm.setdefault(c['ins'], c)
nouvelles = [c for c in osm.values() if c['ins'] not in areas]      # communes OSM sans code LAU : fusions
votes = {}
for cell in cells:
    if not cell[3] or cell[3] in osm:
        continue
    lat, lng = cell[0], cell[1]
    cands = [c for c in nouvelles if c['bb'][0] <= lat <= c['bb'][2] and c['bb'][1] <= lng <= c['bb'][3]]
    if not cands:
        continue
    best = min(cands, key=lambda c: dist(lat, lng, c['lat'], c['lng']))
    votes.setdefault(cell[3], {}); votes[cell[3]][best['ins']] = votes[cell[3]].get(best['ins'], 0) + 1
remap = {old: max(m, key=m.get) for old, m in votes.items()}
for cell in cells:
    if cell[3] in remap:
        cell[3] = remap[cell[3]]
for old, new in remap.items():
    areas[new] = round(areas.get(new, 0.0) + areas.pop(old, 0.0), 2)

# --- contrôle et écriture -----------------------------------------------------
by = {}
for c in cells:
    if c[3]:
        by[c[3]] = by.get(c[3], 0) + c[2]
print('cellules : %d · population : %d · codes reportés : %d' % (len(cells), total, len(remap)))
for old, new in sorted(remap.items()):
    print('   %s -> %s %s' % (old, new, osm[new]['name']))
manque = [osm[k]['name'] for k in osm if k not in by]
print('communes OSM sans total : %s' % (manque or 'aucune'))
ratios = sorted(by[k] / pop_lau[k] for k in by if pop_lau.get(k))
print('grille 2021 / population LAU 2024 sur %d communes : médiane %.3f (p5 %.3f, p95 %.3f)'
      % (len(ratios), ratios[len(ratios) // 2], ratios[len(ratios) // 20], ratios[len(ratios) * 19 // 20]))
out = {
    'source': 'Grille de population 1 km², recensement 2021 — StatBel, diffusion Eurostat/GISCO (Census 2021 grid V2.0), population au lieu de résidence ; rattachement aux communes par les contours LAU 2024 (GISCO)',
    'annee': 2021,
    'licence': 'Eurostat/GISCO : réutilisation libre avec mention de la source (CC BY 4.0) ; contours © EuroGeographics',
    'communes': areas,
    'cellules': cells,
}
json.dump(out, open(out_path, 'w'), ensure_ascii=False, separators=(',', ':'))
print('écrit : %s (%d Ko)' % (out_path, os.path.getsize(out_path) // 1024))
