/* Le moteur de carte des maquettes Scouting v2.
 *
 * Rien n'est inventé : le fond est OpenStreetMap (désaturé en CSS), la
 * population vient de la grille 1 km² du recensement 2021 déjà servie avec
 * l'écran (public/assets/data/population_grid_2021.json), les concurrents sont
 * les positions relevées dans OpenStreetMap et mises en cache par le serveur
 * (donnees.js). Les surfaces peintes sont calculées ici, dans la page, avec la
 * même arithmétique que l'écran : ménages = population ÷ 2,31, un rayon de
 * 4 km pour la zone de chalandise.
 *
 * carte({ el, centre, zoom, cadre, peindre, zone, apres })
 *   peindre : 'potentiel' (ménages accessibles par point de vente, en classes)
 *             | 'population' (la population là où elle vit) | null
 *   zone    : { lat, lng, rayon, isochrone } — cercle et polygone à tracer
 *   apres   : reçoit les mesures calculées, pour les écrire dans la page
 */
(function (global) {
  const HH = 2.31;            // taille moyenne des ménages (Belgique)
  const R = 4;                // rayon de chalandise, comme le curseur de l'écran
  const CASE = 0.05;          // pas des cases de recherche, en degrés
  const RAMPE = ['#e9f0e6', '#bcd6b5', '#7fb076', '#2f7d32'];
  const GRIS = 'rgba(120,110,100,.18)';
  const POP = ['#f6efe3', '#e9d7b4', '#d9b978', '#c2933c'];
  const ROUGE = ['#f7e9ea', '#e3b9bd', '#c77c85', '#8D1D2C'];
  const BLEU = ['#eaf0f4', '#c2d4e0', '#8fb0c6', '#3f6f92'];
  const SPEND = 339, PASSAGE = 0.15, EMAX = 20, COMPK = 0.2, FORCE = 0.62;

  const km = (a, b, c, d) => {
    const x = (c - a) * 111.2, y = (d - b) * 111.2 * Math.cos((a + c) / 2 * Math.PI / 180);
    return Math.sqrt(x * x + y * y);
  };
  const cle = (a, b) => Math.round(a / CASE) + '|' + Math.round(b / CASE);
  const ranger = (liste, lat, lng) => {
    const g = new Map();
    liste.forEach(p => { const k = cle(lat(p), lng(p)); if (!g.has(k)) { g.set(k, []); } g.get(k).push(p); });
    return g;
  };
  const autour = (g, lat, lng, r) => {
    const pas = Math.ceil(r / 111.2 / CASE), out = [];
    const i0 = Math.round(lat / CASE), j0 = Math.round(lng / CASE);
    for (let i = -pas; i <= pas; i++) { for (let j = -pas; j <= pas; j++) {
      const l = g.get((i0 + i) + '|' + (j0 + j)); if (l) { out.push.apply(out, l); }
    } }
    return out;
  };
  const dedans = (poly, lat, lng) => {
    let ok = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
      const a = poly[i], b = poly[j];
      if ((a[0] > lat) !== (b[0] > lat)
        && lng < (b[1] - a[1]) * (lat - a[0]) / (b[0] - a[0]) + a[1]) { ok = !ok; }
    }
    return ok;
  };

  /* Un isochrone plausible : une couronne déformée autour du point, plus
     longue le long des axes. Le tracé est illustratif — le calcul réel se
     demande à un service de routage. */
  const isochrone = (lat, lng, base) => {
    const p = [];
    for (let a = 0; a < 360; a += 6) {
      const t = a * Math.PI / 180;
      const r = base * (1 + 0.34 * Math.sin(2 * t + 0.6) + 0.14 * Math.cos(3 * t - 1.1) + 0.07 * Math.sin(5 * t));
      p.push([lat + r / 111.2 * Math.cos(t), lng + r / (111.2 * Math.cos(lat * Math.PI / 180)) * Math.sin(t)]);
    }
    return p;
  };

  let _grille = null;
  const charger = () => _grille ? Promise.resolve(_grille)
    : fetch('/public/assets/data/population_grid_2021.json').then(r => r.json()).then(d => {
      const cel = d.cellules || [];
      _grille = { cel: cel, g: ranger(cel, c => c[0], c => c[1]), source: d.source, annee: d.annee };
      return _grille;
    });

  global.carte = function (o) {
    const el = document.getElementById(o.el || 'map');
    const map = L.map(el, { zoomControl: false, attributionControl: true, zoomSnap: 0,
      dragging: false, scrollWheelZoom: false, doubleClickZoom: false, boxZoom: false, keyboard: false });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19,
      attribution: '© OpenStreetMap · population : recensement 2021 (StatBel / Eurostat)'
    }).addTo(map);
    if (o.cadre) { map.fitBounds(o.cadre, { padding: [14, 14] }); } else { map.setView(o.centre, o.zoom); }

    const cnv = document.createElement('canvas');
    cnv.className = 'cnv';
    el.parentNode.appendChild(cnv);

    const pts = (global.SC && global.SC.pts) || [];
    const gPts = ranger(pts, p => p[0], p => p[1]);
    const communes = (global.SC && global.SC.communes) || [];

    charger().then(gr => {
      const w = el.clientWidth, h = el.clientHeight, dpr = window.devicePixelRatio || 1;
      cnv.width = w * dpr; cnv.height = h * dpr;
      cnv.style.width = w + 'px'; cnv.style.height = h + 'px';
      const cx = cnv.getContext('2d');
      cx.scale(dpr, dpr);
      const P = ll => map.latLngToContainerPoint(L.latLng(ll[0], ll[1]));
      const mes = { classes: [0, 0, 0, 0], bornes: [], sommets: [], annee: gr.annee };

      /* --- ménages accessibles et concurrents, maille par maille ----------- */
      const vues = gr.cel.filter(c => {
        const p = P(c);
        return p.x > -60 && p.x < w + 60 && p.y > -60 && p.y < h + 60;
      });
      const CALCUL = { potentiel: 1, marche: 1, concurrence: 1, ca: 1, emprise: 1 };
      const val = [];
      if (CALCUL[o.peindre]) {
        vues.forEach(c => {
          let pop = 0;
          autour(gr.g, c[0], c[1], R + 1).forEach(v => {
            const d = km(c[0], c[1], v[0], v[1]);
            if (d <= R - 0.5) { pop += v[2]; } else if (d < R + 0.5) { pop += v[2] * (R + 0.5 - d); }
          });
          let n = 0, pression = 0;
          autour(gPts, c[0], c[1], R).forEach(p => {
            const d = km(c[0], c[1], p[0], p[1]);
            if (d <= R) { n++; pression += FORCE * (1 - 0.6 * d / R); }
          });
          const hh = pop / HH;
          const emprise = Math.max(4, EMAX / (1 + COMPK * pression));
          const ca = hh * SPEND * emprise / 100 / (1 - PASSAGE);
          val.push([c, hh, n, hh / (n + 1), emprise, ca]);
        });
        const COL = { potentiel: 3, marche: 1, concurrence: 2, ca: 5, emprise: 4 };
        const k = COL[o.peindre];
        const tri = val.map(v => v[k]).sort((a, b) => a - b);
        const q = f => tri[Math.floor(tri.length * f)];
        mes.bornes = [q(0.25), q(0.5), q(0.75)];
        mes.mailles = val.length;
        mes.menages = Math.round(vues.reduce((s, c) => s + c[2], 0) / HH);
        mes.sans = val.filter(v => v[2] === 0).length;
        mes.max = tri[tri.length - 1];
      }

      /* --- peinture -------------------------------------------------------- */
      const carre = (c, couleur) => {
        const a = P([c[0] - 0.5 / 111.2, c[1] - 0.5 / (111.2 * Math.cos(c[0] * Math.PI / 180))]);
        const b = P([c[0] + 0.5 / 111.2, c[1] + 0.5 / (111.2 * Math.cos(c[0] * Math.PI / 180))]);
        cx.fillStyle = couleur;
        cx.fillRect(a.x, b.y, Math.max(b.x - a.x, 1.2), Math.max(a.y - b.y, 1.2));
      };
      if (CALCUL[o.peindre]) {
        const COL = { potentiel: 3, marche: 1, concurrence: 2, ca: 5, emprise: 4 };
        const RA = { potentiel: RAMPE, marche: BLEU, concurrence: ROUGE, ca: RAMPE, emprise: RAMPE };
        const k = COL[o.peindre], ra = RA[o.peindre];
        cx.globalAlpha = 0.78;
        val.forEach(v => {
          const m = v[k];
          const i = m < mes.bornes[0] ? 0 : m < mes.bornes[1] ? 1 : m < mes.bornes[2] ? 2 : 3;
          mes.classes[i]++;
          carre(v[0], ra[i]);
        });
        cx.globalAlpha = 1;
      } else if (o.peindre === 'arr') {
        /* Lecture par arrondissement : chaque maille prend la valeur de
           l'arrondissement de la commune la plus proche. */
        const gCom = ranger(communes, c => c[3], c => c[4]);
        const vals = Object.keys(o.valeursArr).map(k2 => o.valeursArr[k2]).sort((a, b) => a - b);
        const q = f => vals[Math.floor(vals.length * f)];
        mes.bornes = [q(0.25), q(0.5), q(0.75)];
        cx.globalAlpha = 0.72;
        vues.forEach(c => {
          let best = null, bd = 99;
          autour(gCom, c[0], c[1], 14).forEach(x => {
            const d = km(c[0], c[1], x[3], x[4]); if (d < bd) { bd = d; best = x; }
          });
          if (!best || bd > 11) { return; }
          const m = o.valeursArr[best[1]];
          if (m == null) { return; }
          const i = m < mes.bornes[0] ? 0 : m < mes.bornes[1] ? 1 : m < mes.bornes[2] ? 2 : 3;
          mes.classes[i]++;
          carre(c, RAMPE[i]);
        });
        cx.globalAlpha = 1;
      } else if (o.peindre === 'population') {
        cx.globalAlpha = 0.72;
        vues.forEach(c => {
          const i = c[2] < 250 ? 0 : c[2] < 900 ? 1 : c[2] < 2500 ? 2 : 3;
          carre(c, POP[i]);
        });
        cx.globalAlpha = 1;
      }

      /* --- concurrents ----------------------------------------------------- */
      const rayon = map.getZoom() > 10 ? 4 : 1.35;
      cx.fillStyle = o.peindre === 'potentiel' ? 'rgba(141,29,44,.62)' : 'rgba(141,29,44,.85)';
      pts.forEach(p => {
        const q = P(p);
        if (q.x < -5 || q.x > w + 5 || q.y < -5 || q.y > h + 5) { return; }
        cx.beginPath(); cx.arc(q.x, q.y, rayon, 0, 6.2832); cx.fill();
        if (rayon > 3) { cx.strokeStyle = '#fff'; cx.lineWidth = 1; cx.stroke(); }
      });

      /* --- la zone dessinée ------------------------------------------------ */
      if (o.zone) {
        const z = o.zone, c0 = P([z.lat, z.lng]);
        const bord = P([z.lat, z.lng + z.rayon / (111.2 * Math.cos(z.lat * Math.PI / 180))]);
        const rpx = Math.abs(bord.x - c0.x);
        cx.setLineDash([6, 5]); cx.strokeStyle = 'rgba(60,60,60,.65)'; cx.lineWidth = 1.5;
        cx.beginPath(); cx.arc(c0.x, c0.y, rpx, 0, 6.2832); cx.stroke(); cx.setLineDash([]);
        if (z.isochrone) {
          const poly = isochrone(z.lat, z.lng, z.isochrone);
          cx.beginPath();
          poly.forEach((p, i) => { const q = P(p); i ? cx.lineTo(q.x, q.y) : cx.moveTo(q.x, q.y); });
          cx.closePath();
          cx.fillStyle = 'rgba(47,125,50,.16)'; cx.fill();
          cx.strokeStyle = '#2f7d32'; cx.lineWidth = 2.2; cx.stroke();
          // mesures de la zone : ménages et concurrents dedans / dans le cercle
          let popI = 0, popC = 0, nI = 0, nC = 0;
          autour(gr.g, z.lat, z.lng, z.isochrone * 2 + 4).forEach(v => {
            if (dedans(poly, v[0], v[1])) { popI += v[2]; }
            if (km(z.lat, z.lng, v[0], v[1]) <= z.rayon) { popC += v[2]; }
          });
          autour(gPts, z.lat, z.lng, z.isochrone * 2 + 4).forEach(p => {
            if (dedans(poly, p[0], p[1])) { nI++; }
            if (km(z.lat, z.lng, p[0], p[1]) <= z.rayon) { nC++; }
          });
          mes.zone = { hhIso: Math.round(popI / HH), hhCer: Math.round(popC / HH), nIso: nI, nCer: nC };
        }
        cx.fillStyle = '#8D1D2C';
        cx.beginPath(); cx.arc(c0.x, c0.y, 6, 0, 6.2832); cx.fill();
        cx.strokeStyle = '#fff'; cx.lineWidth = 2; cx.stroke();
      }

      /* --- les meilleures mailles, rattachées à leur commune --------------- */
      if (o.peindre === 'potentiel') {
        const gCom = ranger(communes, c => c[3], c => c[4]);
        const meilleures = val.slice().sort((a, b) => b[3] - a[3]).slice(0, 400);
        const vu = new Map();
        meilleures.forEach(v => {
          let best = null, bd = 99;
          autour(gCom, v[0][0], v[0][1], 12).forEach(c => {
            const d = km(v[0][0], v[0][1], c[3], c[4]); if (d < bd) { bd = d; best = c; }
          });
          if (!best || bd > 9) { return; }
          const k = best[0];
          if (!vu.has(k) || vu.get(k).m < v[3]) { vu.set(k, { nom: best[0], arr: best[1], m: v[3], hh: v[1], n: v[2] }); }
        });
        mes.sommets = [...vu.values()].sort((a, b) => b.m - a.m).slice(0, 7);
      }

      if (o.apres) { o.apres(mes, map); }
      global.__faits = (global.__faits || 0) + 1;
      if (global.__faits >= (global.__attendus || 1)) { global.__pret = true; }
    });
    return map;
  };
})(window);
