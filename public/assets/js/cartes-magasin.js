/* Les cartes du magasin partagées (26/10/2026) : « l'année, mois par mois, face à l'an passé » et « les six leviers ».
 *
 * Écrites pour le dashboard magasin (dashboard/dashboard.js, vues Année et Mois) et reprises telles quelles par la
 * tablette du consultant (visites.js, étapes « KPI et santé du magasin » et « Les leviers ») : un seul code, donc un
 * seul dessin et les mêmes chiffres des deux côtés. Chaque fonction rend du HTML à partir de la réponse de l'API
 * (/ventes/semaines?vue=annee&articles=1, /leviers/magasins) et de l'état que lui passe la page ; les boutons portent
 * `data-anm` (clients, panier, articles) et `data-levvue` (mois, cumul), que la page branche elle-même.
 * Le style vit dans dashboard.css (dashboard) et dans cartes-magasin.css (tablette).
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const fE = n => n == null ? '—' : nf(Math.round(n) || 0, 0) + ' €';
  const fK = n => n == null ? '—' : (Math.abs(n) >= 10000 ? Number(n / 1000).toLocaleString('fr-BE', { minimumFractionDigits: 0, maximumFractionDigits: 1 }) + ' k€' : fE(n));
  const fU = n => n == null ? '—' : nf(n, 2) + ' €';
  const fN = n => n == null ? '—' : nf(Math.round(n), 0);
  function tuile(k, v, s, cls) { return `<div class="db-tui ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s || ''}</div></div>`; }

  /**
   * L'année, mois par mois, face à l'an passé (24/10/2026, la carte de Cockpit › Résultat › Année pour le magasin,
   * demande de Sam) : la courbe de l'exercice en trait plein, celle de l'an passé en pointillé sur les douze mois — les
   * mois à venir sont les mois à battre —, la bande bleue au-dessus du N-1, orange en dessous, l'écart de chaque mois et
   * trois tuiles. Le sélecteur à trois positions des douze semaines : clients, panier moyen, articles par client.
   * Le mois en cours : les clients se prolongent au rythme de ses jours servis (pointillé, point creux), le panier et
   * les articles se lisent à date ; son écart compare toujours aux mêmes jours de l'an passé (`n1`). L'an passé se trace
   * sur le mois civil entier (`n1Mois`). Un mois d'ouverture, partiel, n'est pas relié.
   *
   * `o` : { annee, mesure (clients | panier | articles), err } ; `D` : la réponse de /ventes/semaines?vue=annee&articles=1.
   */
  function annee(D, o) {
    const Y = o.annee;
    const AN_MESURES = {
      clients: { nom: 'Clients', titre: 'clients', unite: 'clients du mois', v: x => x && x.tickets > 0 ? x.tickets : null, f: v => fN(v),
        cumul: L => ({ a: L.reduce((t, x) => t + x.tickets, 0), b: 1 }) },
      panier: { nom: 'Panier moyen', titre: 'panier moyen', unite: 'panier moyen du mois', v: x => x && x.tickets > 0 ? x.ca / x.tickets : null, f: v => fU(v),
        cumul: L => ({ a: L.reduce((t, x) => t + x.ca, 0), b: L.reduce((t, x) => t + x.tickets, 0) }) },
      articles: { nom: 'Articles par client', titre: 'articles par client', unite: 'articles par client du mois',
        v: x => x && x.tickets > 0 && x.articles > 0 ? x.articles / x.tickets : null, f: v => nf(v, 2),
        cumul: L => ({ a: L.reduce((t, x) => t + x.articles, 0), b: L.reduce((t, x) => t + x.tickets, 0) }) }
    };
    const mk = AN_MESURES[o.mesure] ? o.mesure : 'clients', M = AN_MESURES[mk];
    const bascule = `<span class="db-ong db-ong-p">${Object.entries(AN_MESURES).map(([k, x]) => `<button type="button" data-anm="${k}" class="${k === mk ? 'on' : ''}" aria-pressed="${k === mk}">${x.nom}</button>`).join('')}</span>`;
    const carte = (corps, sous) => `<div class="db-card"><div class="ct" style="flex-wrap:wrap;gap:8px"><span class="db-lab">${Y}, mois par mois — ${M.titre}, face à ${Y - 1}</span>${bascule}${sous ? `<span class="db-mini" style="margin-left:auto">${esc(sous)}</span>` : ''}</div><div style="padding:12px 16px 14px">${corps}</div></div>`;
    if (!D) { return carte(`<div class="db-note">${o.err ? esc(o.err) : 'Lecture des douze mois…'}</div>`); }
    const pc = (a, b) => b ? 100 * a / b : 0;
    // Le signe se lit sur la valeur arrondie : −0,3 % s'écrit « 0 % », pas « −0 % ».
    const sg = v => { const r = Math.round(v); return (r > 0 ? '+' : r < 0 ? '−' : '') + Math.abs(r); };
    const HAUT = '#2E6DB4', BAS = '#C0632B';
    const col = v => v >= 3 ? HAUT : (v <= -3 ? BAS : '#8b8177');
    const MO = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    const T = Array.isArray(D.semaines) ? D.semaines.slice(0, 12) : [];
    if (!T.length) { return carte(`<div class="db-note">Aucun mois lu pour ce magasin.</div>`); }
    const jm = i => new Date(Y, i + 1, 0).getDate();
    // Un mois d'ouverture : le premier mois vendu de la série, partiel, sans vente avant lui dans l'année. En janvier, seul
    // un magasin sans aucune vente l'an passé est dit en ouverture (une fermeture d'un magasin installé reste comparée).
    const sansN1 = !T.some(w => w.n1 || w.n1Mois);
    const ouvDe = (get, y, janvier) => { const i = T.findIndex(w => get(w)); return i >= 0 && (i > 0 || janvier) && (get(T[i]).jours || 0) < new Date(y, i + 1, 0).getDate() - 2 ? i : -1; };
    const iOuv = ouvDe(w => w.source ? w : null, Y, sansN1), iOuv1 = ouvDe(w => w.n1Mois || w.n1, Y - 1, false);
    const n1m = w => w.n1Mois || (!w.enCours && !w.futur ? w.n1 : null);
    // Ce qu'on trace : N (le mois en cours prolongé pour les clients), N-1 sur le mois entier ; l'écart sur `n1`, à date.
    const vT = (w, i) => { if (w.futur || !w.source || i === iOuv) { return null; } const v = M.v(w); return v != null && mk === 'clients' && w.enCours && w.joursServis ? v / w.joursServis * jm(i) : v; };
    const v1T = (w, i) => i === iOuv1 ? null : M.v(n1m(w));
    const ecart = (w, i) => { if (w.futur || !w.source || !w.n1 || i === iOuv || i === iOuv1) { return null; } const n = M.v(w), n1 = M.v(w.n1); return n != null && n1 ? pc(n - n1, n1) : null; };
    if (!T.some((w, i) => vT(w, i) != null)) {
      return carte(`<div class="db-note">${mk === 'articles' ? 'Le panel ne rend pas les articles vendus pour ce magasin sur ces mois.' : 'Aucun mois lu pour ce magasin.'}</div>`);
    }
    const avecN1 = T.some((w, i) => v1T(w, i) != null);
    const vals = [];
    T.forEach((w, i) => { const a = vT(w, i), b = v1T(w, i); if (a != null) { vals.push(a); } if (b != null) { vals.push(b); } });
    const mn = Math.min(...vals), mx = Math.max(...vals), et = mx - mn;
    const lo = mn - Math.max(et * 0.5, mn * 0.02), hi = mx + Math.max(et * 0.18, mx * 0.01) || 1;
    const Wd = 1000, Hh = 200, ml = 30, mr = 30, mt = 26, mb = 30;
    const X = i => (ml + i * (Wd - ml - mr) / 11).toFixed(1);
    const Yp = y => (mt + (hi - y) / (hi - lo || 1) * (Hh - mt - mb)).toFixed(1);
    const ft = 'font-family:var(--font-ui)';
    const iEc = T.findIndex(w => w.enCours);
    let svg = `<svg viewBox="0 0 ${Wd} ${Hh}" preserveAspectRatio="xMinYMin meet" class="db-s6c">`;
    if (iEc >= 0) { const w = (Wd - ml - mr) / 11; svg += `<rect x="${(+X(iEc) - w / 2).toFixed(1)}" y="${mt - 12}" width="${w.toFixed(1)}" height="${Hh - mb - mt + 12}" fill="#8D1D2C" fill-opacity="0.045" rx="4"/>`; }
    [1, 2].forEach(k => { const y = Yp(lo + (hi - lo) * k / 3); svg += `<line x1="${ml}" x2="${Wd - mr}" y1="${y}" y2="${y}" stroke="rgba(34,34,34,.10)"/>`; });
    // La bande entre les deux courbes, mois par mois (pas vers le mois en cours prolongé).
    for (let i = 0; i < 11; i++) {
      const a = vT(T[i], i), b = vT(T[i + 1], i + 1), a1 = v1T(T[i], i), b1 = v1T(T[i + 1], i + 1);
      if (a == null || b == null || a1 == null || b1 == null || (mk === 'clients' && T[i + 1].enCours)) { continue; }
      svg += `<polygon points="${X(i)},${Yp(a)} ${X(i + 1)},${Yp(b)} ${X(i + 1)},${Yp(b1)} ${X(i)},${Yp(a1)}" fill="${a + b >= a1 + b1 ? HAUT : BAS}" fill-opacity="0.14"/>`;
    }
    const seg = (get, garde) => { const out = []; let cur = []; T.forEach((w, i) => { const v = get(w, i); if (v != null && garde(w)) { cur.push(X(i) + ',' + Yp(v)); } else { if (cur.length) { out.push(cur); } cur = []; } }); if (cur.length) { out.push(cur); } return out; };
    seg(v1T, () => true).forEach(s => { if (s.length > 1) { svg += `<polyline points="${s.join(' ')}" fill="none" stroke="#b9b1a6" stroke-width="2" stroke-dasharray="5 4"/>`; } });
    const projete = mk === 'clients';
    seg(vT, w => !(projete && w.enCours)).forEach(s => { if (s.length > 1) { svg += `<polyline points="${s.join(' ')}" fill="none" stroke="#8D1D2C" stroke-width="2.5"/>`; } });
    if (iEc > 0 && vT(T[iEc], iEc) != null && vT(T[iEc - 1], iEc - 1) != null) {
      svg += `<line x1="${X(iEc - 1)}" y1="${Yp(vT(T[iEc - 1], iEc - 1))}" x2="${X(iEc)}" y2="${Yp(vT(T[iEc], iEc))}" stroke="#8D1D2C" stroke-width="2.2" ${projete ? 'stroke-dasharray="2 4"' : ''} stroke-linecap="round"/>`;
    }
    T.forEach((w, i) => {
      const n = vT(w, i), n1 = v1T(w, i), up = n1 == null || n == null || n >= n1;
      if (n1 != null) { svg += `<circle cx="${X(i)}" cy="${Yp(n1)}" r="3.5" fill="#fff" stroke="#b9b1a6" stroke-width="2"/><text x="${X(i)}" y="${(+Yp(n1) + (up ? 16 : -9)).toFixed(1)}" font-size="10" text-anchor="middle" fill="#666" style="${ft}">${M.f(n1)}</text>`; }
      if (n != null) {
        svg += w.enCours ? `<circle cx="${X(i)}" cy="${Yp(n)}" r="4.4" fill="#fff" stroke="#8D1D2C" stroke-width="2"/>` : `<circle cx="${X(i)}" cy="${Yp(n)}" r="4" fill="#8D1D2C"/>`;
        svg += `<text x="${X(i)}" y="${(+Yp(n) + (up ? -9 : 16)).toFixed(1)}" font-size="11" font-weight="600" text-anchor="middle" fill="#8D1D2C" style="${ft}">${M.f(n)}</text>`;
      }
      svg += `<text x="${X(i)}" y="${Hh - 8}" font-size="10.5" font-weight="600" text-anchor="middle" fill="${w.enCours ? '#8D1D2C' : (w.futur ? '#a8a197' : '#222')}" style="${ft}">${MO[i]}${w.enCours ? ' · ' + w.joursServis + ' j' : ''}</text>`;
    });
    svg += '</svg>';
    const chips = T.map((w, i) => {
      const e = ecart(w, i), n = w.futur || !w.source ? null : M.v(w), n1 = w.n1 ? M.v(w.n1) : null, nm = M.v(n1m(w));
      const titre = w.futur ? (nm != null ? (Y - 1) + ' : ' + M.f(nm) + ' — le mois à battre' : 'à venir')
        : (n != null ? M.f(n) + ' · ' + fN(w.tickets) + ' clients · ' + fK(w.ca) + (w.enCours ? ' · en ' + w.joursServis + ' j' : '') : 'pas de vente lue')
          + (n1 != null ? ' · ' + (Y - 1) + (w.enCours ? ' aux mêmes jours' : '') + ' : ' + M.f(n1) : ' · pas de N-1')
          + (i === iOuv || i === iOuv1 ? ' · mois d’ouverture, pas d’écart' : '');
      const pas1 = w.futur ? '<em style="background:transparent;color:var(--color-text-muted)">à venir</em>'
        : (i === iOuv || i === iOuv1 ? '<em style="background:#c9c2b8">ouv.</em>'
        : (avecN1 ? `<em style="background:${e == null ? '#c9c2b8' : col(e)}">${e == null ? '—' : sg(e) + ' %'}</em>` : `<em class="sans">${n != null ? M.f(n) : '—'}</em>`));
      // Sans N-1, la valeur du mois est dans la pastille : elle ne se répète pas sous le nom du mois.
      const sous = w.enCours ? 'en cours' : (w.futur ? (nm != null ? M.f(nm) : '') : (!avecN1 ? '' : (n != null ? M.f(n) : '—')));
      return `<div class="ch" title="${esc(titre)}"><span><b>${MO[i]}</b><span>${sous}</span></span>${pas1}</div>`;
    }).join('');
    // L'année à date : clients, la somme ; panier et articles, le rapport des sommes — sur les mois comparables.
    const comp = T.filter((w, i) => ecart(w, i) != null);
    const cN = M.cumul(comp), c1 = M.cumul(comp.map(w => w.n1));
    const gN = cN.b ? cN.a / cN.b : null, g1 = c1.b ? c1.a / c1.b : null;
    const eT = comp.length && g1 ? pc(gN - g1, g1) : null;
    const lus = T.filter((w, i) => !w.futur && w.source && M.v(w) != null), cT = M.cumul(lus), gT = cT.b ? cT.a / cT.b : null;
    const plage = comp.length ? MO[T.indexOf(comp[0])] + ' → ' + MO[T.indexOf(comp[comp.length - 1])] : '';
    const ec = iEc >= 0 ? T[iEc] : null, clos = T.filter((w, i) => !w.futur && !w.enCours && vT(w, i) != null);
    let tCur;
    if (ec && ec.source) {
      const n = M.v(ec), e = ecart(ec, iEc), j = ec.joursServis || 0;
      tCur = tuile(MO[iEc].replace(/\.$/, '') + ' en cours', n != null ? M.f(n) : '—', (e != null ? sg(e) + ' % vs ' + (Y - 1) + ' aux mêmes jours · ' : '') + j + ' jour' + (j > 1 ? 's' : '') + ' servi' + (j > 1 ? 's' : ''), e == null ? '' : (e >= 0 ? 'bon' : 'vif'));
    } else if (clos.length) {
      const w = clos[clos.length - 1], i = T.indexOf(w), e = ecart(w, i);
      tCur = tuile('Dernier mois · ' + MO[i], M.f(M.v(w)), e != null ? sg(e) + ' % vs ' + (Y - 1) : '', e == null ? '' : (e >= 0 ? 'bon' : 'vif'));
    } else { tCur = tuile('Mois en cours', '—', ''); }
    const meilleur = clos.reduce((a, w) => !a || M.v(w) > M.v(a) ? w : a, null);
    const tuiles = [
      eT != null ? tuile(Y + ' vs ' + (Y - 1), sg(eT) + ' %', M.f(gN) + ' contre ' + M.f(g1) + (plage ? ' · ' + plage : ''), eT >= 0 ? 'bon' : 'vif')
        : tuile(String(Y), gT != null ? M.f(gT) : '—', esc(D.n1Motif || 'pas de N-1')),
      tCur,
      tuile(mk === 'clients' ? 'Meilleur mois' : 'Mois le plus haut', meilleur ? M.f(M.v(meilleur)) : '—', meilleur ? MO[T.indexOf(meilleur)] + ' ' + Y : '')
    ].join('');
    const corps = `<div class="db-s6"><div>${svg}
        <div class="db-axe" style="padding:4px 0 0;justify-content:flex-start;gap:14px;flex-wrap:wrap"><span><i class="c" style="background:#8D1D2C;border-radius:50%"></i>${esc(M.unite)}${projete && ec ? ' (le mois en cours prolongé au rythme de ses jours)' : ''}</span>${avecN1 ? `<span><i class="c" style="background:#b9b1a6;border-radius:50%"></i>même mois en ${Y - 1} (pointillé, mois entier)</span><span><i class="c" style="background:${HAUT};opacity:.35"></i>au-dessus du N-1</span><span><i class="c" style="background:${BAS};opacity:.35"></i>en dessous</span>` : ''}</div>
        <div class="db-s6ch">${chips}</div></div>
      <div class="db-s6t">${tuiles}</div></div>`;
    return carte(corps, (D.source || '').replace(/ · N-1 = .*$/, '') + ' · écart du mois en cours aux mêmes jours de ' + (Y - 1));
  }


  /**
   * Les six leviers du magasin, vue Mois (21/10/2026, demande de Sam) : une tuile par levier — la valeur du mois (à date
   * s'il est en cours), sa pastille (seuils de la Table KPI), l'écart au même mois de l'année d'avant, le réseau et le
   * seuil, la courbe des douze derniers mois. « Cumul de l'année » : janvier → le dernier mois clos, face aux mêmes mois.
   * Quand l'écart porte sur moins de mois que la valeur (ouverture, frais non saisis), la tuile dit les deux chiffres
   * comparés. La Récurrence reste grise : elle attend sa mesure.
   *
   * `o` : { shop, vue (mois | cumul), err } ; `L` : la réponse de /leviers/magasins (tout le réseau).
   */
  function leviers(L, o) {
    if (o.err) { return `<div class="db-err">Les six leviers : ${esc(o.err)}</div>`; }
    if (!L) { return `<div class="db-card lv-card"><div class="ct"><span class="db-lab">Les six leviers</span><span class="db-mini">lecture…</span></div><div class="lv-g">${Array.from({ length: 6 }, () => '<div class="lv-tu"><div class="db-sk" style="width:55%;margin-top:12px"></div><div class="db-sk" style="height:22px;margin:10px 0 6px;width:60%"></div><div class="db-sk" style="width:85%"></div></div>').join('')}</div></div>`; }
    const g = (L.magasins || []).find(x => String(x.id) === String(o.shop));
    if (!g) { return `<div class="db-card lv-card"><div class="ct"><span class="db-lab">Les six leviers</span></div><div class="lv-vide">Pas de P&amp;L pour ce magasin ce mois-ci : les six leviers se liront quand il sera saisi.</div></div>`; }
    const cumul = o.vue === 'cumul' && !!L.cumulAu && !!g.cumul;
    const dc = (n, d) => nf(n, d);
    const sg = (n, d, u) => (n > 0 ? '+' : (n < 0 ? '−' : '±')) + dc(Math.abs(n), d) + u;
    const nomMois = (m, long) => new Date(2000, m - 1, 15).toLocaleDateString('fr-BE', { month: long ? 'long' : 'short' });
    const moisAn = (ym, long) => nomMois(+ym.slice(5, 7), long) + ' ' + ym.slice(0, 4);
    const pct = v => dc(v, 1) + ' %';
    const pa = n => n ? `<i class="lv-p ${n}"></i>` : '';
    // L'écart est bon quand il y a plus de clients, une meilleure note, un coût plus bas.
    const puce = (d, dec, u, hausseBonne) => d == null ? '' : `<span class="lv-c ${d === 0 ? 'z' : ((d > 0) === hausseBonne ? 'b' : 'm')}">${sg(d, dec, u)}</span>`;
    const neutre = t => `<span class="lv-c z">${esc(t)}</span>`;
    const etendue = l => l.length ? nomMois(l[0]) + ' → ' + nomMois(l[l.length - 1]) : '';
    const seuil = (cle, f) => { const x = (L.seuils || {})[cle]; return x && x.alerte != null ? 'seuil ' + f(x.alerte) : ''; };
    const R = cumul ? L.reseau.cumul : L.reseau.mois;
    const ouvLib = g.ouverture ? 'ouvert en ' + moisAn(g.ouverture, true) : 'pas de N-1';
    const mc = cumul ? (g.cumul.moisComparables || []) : [];
    const memesMois = cumul && mc.length && mc.length < L.cumulAu ? 'mêmes mois : ' + etendue(mc) : '';
    const ymSel = L.annee + '-' + String(L.mois).padStart(2, '0');
    // La courbe des douze derniers mois : un trait, le dernier point marqué ; chaque mois se lit au survol.
    const courbe = (vals, coul, f) => {
      const W = 160, H = 34, v = (vals || []).map(x => x == null ? null : +x), ok = v.filter(x => x != null);
      if (ok.length < 2) { return ''; }
      const lo = Math.min(...ok), hi = Math.max(...ok), sx = W / Math.max(1, v.length - 1);
      const y = x => H - 4 - (hi === lo ? 0.5 : (x - lo) / (hi - lo)) * (H - 8);
      let d = '', lev = true;
      v.forEach((x, i) => { if (x == null) { lev = true; return; } d += (lev ? 'M' : 'L') + (i * sx).toFixed(1) + ' ' + y(x).toFixed(1); lev = false; });
      const last = v.length - 1;
      const pts = v.map((x, i) => x == null ? '' : `<circle cx="${(i * sx).toFixed(1)}" cy="${y(x).toFixed(1)}" r="7" fill="transparent"><title>${esc(moisAn(L.serieMois[i]) + ' : ' + f(x))}</title></circle>`).join('');
      return `<svg class="lv-sp" viewBox="-3 0 ${W + 6} ${H}" preserveAspectRatio="none" height="34" role="img" aria-label="les douze derniers mois"><path d="${d}" fill="none" stroke="${coul}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>${v[last] != null ? `<circle cx="${(last * sx).toFixed(1)}" cy="${y(v[last]).toFixed(1)}" r="2.8" fill="${coul}"/>` : ''}${pts}</svg>`;
    };
    const tuile = (l, val, pas, l1, l2, spark, q) => `<div class="lv-tu"><div class="bar" style="background:${esc(l.couleur)}"></div><div class="k" style="color:${esc(l.couleur)}">${esc(l.nom)}</div><div class="q">${esc(q)}</div>
      <div class="v${val === '—' ? ' mu' : ''}">${esc(val)}${pa(pas)}</div><div class="l">${l1}</div>${l2 ? `<div class="l">${l2}</div>` : ''}${spark || ''}</div>`;
    const tuiles = (L.leviers || []).map(l => {
      if (l.vide) { return `<div class="lv-tu vide"><div class="bar" style="background:${esc(l.couleur)}"></div><div class="k" style="color:${esc(l.couleur)}">${esc(l.nom)}</div><div class="q">à définir</div><div class="v mu">—</div><div class="l">laissée vide pour l’instant</div></div>`; }
      if (l.cle === 'recurrence') {
        // Le taux de retour des clients identifiés en caisse, hors pro (23/10/2026) — la part venus au moins deux jours ;
        // en dessous, celui des seuls clients PWA.
        const x = ((cumul ? g.cumul : g.mois) || {}).recurrence, r = R.recurrence || {};
        const q = 'taux de retour des clients identifiés' + (cumul ? ' · janvier → ' + nomMois(L.cumulAu, true) : '');
        const sp = courbe(g.serie.recurrence, l.couleur, v => nf(v, 0) + ' %');
        const l2 = esc('réseau ' + (r.v != null ? nf(r.v, 0) + ' %' : '—') + (seuil('recurrence', a => nf(a, 0) + ' %') ? ' · ' + seuil('recurrence', a => nf(a, 0) + ' %') : ''));
        if (!x) { return tuile(l, '—', null, 'pas encore relevé', l2, sp, q); }
        if (!x.complet) { return tuile(l, '—', null, neutre('relevé en cours'), esc('tickets lus : ' + x.joursLus + ' / ' + x.jours + ' jours'), sp, q); }
        if (!x.clients) { return tuile(l, '—', null, 'aucun client identifié en caisse', l2, sp, q); }
        const p = x.pwa || {};
        const l1 = (x.n1 != null ? puce(x.delta, 1, ' pt', true) + ' N-1 ' + nf(x.n1, 0) + ' %' : neutre('pas de N-1')) + ' ' + esc(x.revenus + ' sur ' + x.clients + ' client' + (x.clients > 1 ? 's' : ''));
        const lp = esc('PWA : ' + (p.clients ? nf(p.v, 0) + ' % (' + p.revenus + ' sur ' + p.clients + ')' : 'aucun client identifié'));
        return tuile(l, nf(x.v, 0) + ' %', x.niveau, l1, (x.partTickets != null ? esc(nf(x.partTickets, 1) + ' % des tickets') + ' · ' : '') + lp + '<br>' + l2, sp, q);
      }
      if (l.cle === 'trafic') {
        const fT = x => nf(x, 0) + (cumul ? '' : ' /j');
        if (cumul) {
          const x = g.cumul.trafic, r = R.trafic || {};
          const l1 = x.comp == null ? neutre('pas de N-1') + ' ' + esc(ouvLib) : puce(x.delta, 1, ' %', true) + ' ' + esc(memesMois ? nf(x.comp, 0) + ' vs ' + nf(x.n1, 0) : 'N-1 ' + nf(x.n1, 0));
          return tuile(l, nf(x.v, 0), null, l1, esc((memesMois ? memesMois + ' · ' : '') + (r.delta != null ? 'réseau ' + sg(r.delta, 1, ' %') : '')), courbe(g.serie.trafic, l.couleur, fT), 'clients · janvier → ' + nomMois(L.cumulAu, true));
        }
        const x = g.mois.trafic, r = R.trafic || {};
        if (x.v == null) { return tuile(l, '—', null, 'pas de P&amp;L ce mois', '', courbe(g.serie.trafic, l.couleur, fT), 'clients par jour'); }
        const l1 = g.mois.ouverture ? neutre('mois d’ouverture') : (x.n1 == null ? neutre('pas de N-1') + ' ' + esc(ouvLib) : puce(x.delta, 1, ' %', true) + ' N-1 ' + nf(x.n1, 0));
        return tuile(l, nf(x.v, 0), x.niveau, l1, esc('réseau ' + (r.v != null ? nf(r.v, 0) : '—') + (x.ecartReseau != null ? ' · ' + sg(x.ecartReseau, 0, ' %') : '')), courbe(g.serie.trafic, l.couleur, fT), 'clients par jour' + (L.moisEnCours ? ' · ' + L.jours + ' jours' : ''));
      }
      if (l.cle === 'xp') {
        const x = (cumul ? g.cumul : g.mois).xp || {}, r = R.xp || {};
        const n1 = x.n1 != null ? puce(x.delta, 2, '', true) + ' N-1 ' + dc(x.n1, 1) + ' ★' : neutre(L.googleN1Des ? 'N-1 : ' + moisAn(L.googleN1Des) : 'pas de N-1');
        const deb = L.googleN1Des ? moisAn(String(+L.googleN1Des.slice(0, 4) - 1) + L.googleN1Des.slice(4)) : '';
        return tuile(l, x.v != null ? dc(x.v, 1) + ' ★' : '—', x.niveau, x.v != null ? n1 : 'pas de relevé ce mois-là',
          esc('réseau ' + (r.v != null ? dc(r.v, 2) + ' ★' : '—') + (seuil('xp', a => dc(a, 1)) ? ' · ' + seuil('xp', a => dc(a, 1)) : '')),
          `<div class="l lv-pied">${esc(deb ? 'relevé mensuel depuis ' + deb : 'la note de la fiche, aujourd’hui')}</div>`, 'note Google' + (x.avis ? ' · ' + nf(x.avis, 0) + ' avis' : ''));
      }
      const r = R[l.cle] || {}, s = seuil(l.cle, a => pct(a));
      const l2 = esc('réseau ' + (r.v != null ? pct(r.v) : '—') + (s ? ' · ' + s : ''));
      const sp = courbe(g.serie[l.cle], l.couleur, pct);
      if (cumul) {
        const x = g.cumul[l.cle] || {};
        if (x.v == null) { return tuile(l, '—', null, 'pas saisi', l2, sp, '% du CA · janvier → ' + nomMois(L.cumulAu, true)); }
        const part = x.comp != null && x.moisCompares < mc.length ? 'sur ' + x.moisCompares + ' mois saisis les deux années' : memesMois;
        const l1 = x.comp == null || x.n1 == null ? neutre('pas de N-1') + ' ' + esc(mc.length ? 'pas saisi l’an dernier' : ouvLib)
          : puce(x.delta, 1, ' pt', false) + ' ' + esc(part ? pct(x.comp) + ' vs ' + pct(x.n1) : 'N-1 ' + pct(x.n1));
        return tuile(l, pct(x.v), x.niveau, l1, (part ? esc(part) + '<br>' : '') + l2, sp, '% du CA · janvier → ' + nomMois(L.cumulAu, true));
      }
      const x = g.mois[l.cle] || {};
      if (x.nonSaisi) { return tuile(l, '—', null, neutre('pas saisi ce mois'), l2, sp, '% du CA'); }
      if (x.v == null) { return tuile(l, '—', null, 'pas de P&amp;L ce mois', l2, sp, '% du CA'); }
      const l1 = x.n1 == null ? neutre(g.mois.ouverture ? 'mois d’ouverture' : 'pas de N-1') + (g.mois.ouverture ? '' : ' ' + esc(ouvLib)) : puce(x.delta, 1, ' pt', false) + ' N-1 ' + pct(x.n1);
      const hors = l.cle === 'food' && (g.horsNorme || []).some(h => h.mois === ymSel) ? '<span class="lv-al">⚠ hors norme, à vérifier au P&amp;L</span>' : '';
      return tuile(l, pct(x.v), x.niveau, l1 + hors, l2, sp, '% du CA');
    }).join('');
    const titre = cumul ? 'cumul ' + L.annee : moisAn(ymSel, true);
    const sous = cumul ? 'janvier → ' + nomMois(L.cumulAu, true) + ', face aux mêmes mois de ' + (L.annee - 1) + (L.moisEnCours ? ' · le mois en cours n’entre pas' : '')
      : (L.moisEnCours ? 'à date (' + L.jours + ' jours), ' : '') + 'face à ' + moisAn((L.annee - 1) + '-' + String(L.mois).padStart(2, '0'), true) + ' et au réseau';
    const seg = `<div class="lv-seg">${[['mois', 'Le mois'], ['cumul', 'Cumul de l’année']].map(o => `<button data-levvue="${o[0]}" class="${(cumul ? 'cumul' : 'mois') === o[0] ? 'on' : ''}"${o[0] === 'cumul' && !(L.cumulAu && g.cumul) ? ' disabled title="le cumul commence avec le premier mois clos"' : ''}>${o[1]}</button>`).join('')}</div>`;
    return `<div class="db-card lv-card"><div class="ct"><span class="db-lab">Les six leviers — ${esc(titre)}</span><span class="db-mini lv-st">${esc(sous)}</span>${seg}</div>
      <div class="lv-g">${tuiles}</div>
      <div class="db-perleg lv-leg"><span>Pastille — seuils de la Table KPI : ${pa('v')} sous l’alerte ${pa('o')} entre alerte et critique ${pa('r')} au-delà${cumul ? '' : ' · trafic : l’écart au réseau'}</span><span>Écart vert : mieux qu’en ${L.annee - 1}</span><span>Courbe : les douze derniers mois${L.moisEnCours ? ', le dernier à date' : ''}</span></div></div>`;
  }

  window.CartesMagasin = { annee, leviers, MESURES_ANNEE: ['clients', 'panier', 'articles'] };
})();
