/* Les cartes du magasin partagées (26/10/2026) : « l'année, mois par mois, face à l'an passé » et « les six leviers ».
 *
 * Écrites pour le dashboard magasin (dashboard/dashboard.js, vues Année et Mois) et reprises telles quelles par la
 * tablette du consultant (visites.js, étapes « KPI et santé du magasin » et « Les leviers ») : un seul code, donc un
 * seul dessin et les mêmes chiffres des deux côtés. Chaque fonction rend du HTML à partir de la réponse de l'API
 * (/ventes/semaines?vue=annee&articles=1, /leviers/magasins) et de l'état que lui passe la page ; les boutons portent
 * `data-anm` (clients, panier, articles) et `data-levvue` (mois, annee), que la page branche elle-même.
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
   * seuil. Les petites courbes des douze mois sont parties (26/10/2026, Sam : « elles ne servent à rien ») : l'année se
   * lit dans « L'évolution de l'année » (evolution() ci-dessous), qui remplace le cumul.
   *
   * `o` : { shop, vue (mois | annee), err } ; `L` : la réponse de /leviers/magasins (tout le réseau).
   */
  function leviers(L, o) {
    if (o.err) { return `<div class="db-err">Les six leviers : ${esc(o.err)}</div>`; }
    if (!L) { return `<div class="db-card lv-card"><div class="ct"><span class="db-lab">Les six leviers</span><span class="db-mini">lecture…</span></div><div class="lv-g">${Array.from({ length: 6 }, () => '<div class="lv-tu"><div class="db-sk" style="width:55%;margin-top:12px"></div><div class="db-sk" style="height:22px;margin:10px 0 6px;width:60%"></div><div class="db-sk" style="width:85%"></div></div>').join('')}</div></div>`; }
    const g = (L.magasins || []).find(x => String(x.id) === String(o.shop));
    if (!g) { return `<div class="db-card lv-card"><div class="ct"><span class="db-lab">Les six leviers</span></div><div class="lv-vide">Pas de P&amp;L pour ce magasin ce mois-ci : les six leviers se liront quand il sera saisi.</div></div>`; }
    // « cumul » : la vue d'avant le 26/10/2026, qu'un navigateur peut avoir gardée.
    const vue = o.vue === 'annee' || o.vue === 'cumul' ? 'annee' : 'mois';
    const seg = `<div class="lv-seg">${[['mois', 'Le mois'], ['annee', 'L’évolution de l’année']].map(x => `<button data-levvue="${x[0]}" class="${vue === x[0] ? 'on' : ''}">${x[1]}</button>`).join('')}</div>`;
    if (vue === 'annee') { return evolution(L, g, seg); }
    const dc = (n, d) => nf(n, d);
    const sg = (n, d, u) => (n > 0 ? '+' : (n < 0 ? '−' : '±')) + dc(Math.abs(n), d) + u;
    const nomMois = (m, long) => new Date(2000, m - 1, 15).toLocaleDateString('fr-BE', { month: long ? 'long' : 'short' });
    const moisAn = (ym, long) => nomMois(+ym.slice(5, 7), long) + ' ' + ym.slice(0, 4);
    const pct = v => dc(v, 1) + ' %';
    const pa = n => n ? `<i class="lv-p ${n}"></i>` : '';
    // L'écart est bon quand il y a plus de clients, une meilleure note, un coût plus bas.
    const puce = (d, dec, u, hausseBonne) => d == null ? '' : `<span class="lv-c ${d === 0 ? 'z' : ((d > 0) === hausseBonne ? 'b' : 'm')}">${sg(d, dec, u)}</span>`;
    const neutre = t => `<span class="lv-c z">${esc(t)}</span>`;
    const seuil = (cle, f) => { const x = (L.seuils || {})[cle]; return x && x.alerte != null ? 'seuil ' + f(x.alerte) : ''; };
    const R = L.reseau.mois;
    const ouvLib = g.ouverture ? 'ouvert en ' + moisAn(g.ouverture, true) : 'pas de N-1';
    const ymSel = L.annee + '-' + String(L.mois).padStart(2, '0');
    const tuile = (l, val, pas, l1, l2, q, pied) => `<div class="lv-tu"><div class="bar" style="background:${esc(l.couleur)}"></div><div class="k" style="color:${esc(l.couleur)}">${esc(l.nom)}</div><div class="q">${esc(q)}</div>
      <div class="v${val === '—' ? ' mu' : ''}">${esc(val)}${pa(pas)}</div><div class="l">${l1}</div>${l2 ? `<div class="l">${l2}</div>` : ''}${pied || ''}</div>`;
    const tuiles = (L.leviers || []).map(l => {
      if (l.vide) { return `<div class="lv-tu vide"><div class="bar" style="background:${esc(l.couleur)}"></div><div class="k" style="color:${esc(l.couleur)}">${esc(l.nom)}</div><div class="q">à définir</div><div class="v mu">—</div><div class="l">laissée vide pour l’instant</div></div>`; }
      if (l.cle === 'recurrence') {
        // Le taux de retour des clients identifiés en caisse, hors pro (23/10/2026) — la part venus au moins deux jours ;
        // en dessous, celui des seuls clients PWA.
        const x = (g.mois || {}).recurrence, r = R.recurrence || {};
        const q = 'taux de retour des clients identifiés';
        const l2 = esc('réseau ' + (r.v != null ? nf(r.v, 0) + ' %' : '—') + (seuil('recurrence', a => nf(a, 0) + ' %') ? ' · ' + seuil('recurrence', a => nf(a, 0) + ' %') : ''));
        if (!x) { return tuile(l, '—', null, 'pas encore relevé', l2, q); }
        if (!x.complet) { return tuile(l, '—', null, neutre('relevé en cours'), esc('tickets lus : ' + x.joursLus + ' / ' + x.jours + ' jours'), q); }
        if (!x.clients) { return tuile(l, '—', null, 'aucun client identifié en caisse', l2, q); }
        const p = x.pwa || {};
        const l1 = (x.n1 != null ? puce(x.delta, 1, ' pt', true) + ' N-1 ' + nf(x.n1, 0) + ' %' : neutre('pas de N-1')) + ' ' + esc(x.revenus + ' sur ' + x.clients + ' client' + (x.clients > 1 ? 's' : ''));
        const lp = esc('PWA : ' + (p.clients ? nf(p.v, 0) + ' % (' + p.revenus + ' sur ' + p.clients + ')' : 'aucun client identifié'));
        return tuile(l, nf(x.v, 0) + ' %', x.niveau, l1, (x.partTickets != null ? esc(nf(x.partTickets, 1) + ' % des tickets') + ' · ' : '') + lp + '<br>' + l2, q);
      }
      if (l.cle === 'trafic') {
        const x = g.mois.trafic, r = R.trafic || {}, q = 'clients par jour' + (L.moisEnCours ? ' · ' + L.jours + ' jours' : '');
        if (x.v == null) { return tuile(l, '—', null, 'pas de P&amp;L ce mois', '', q); }
        const l1 = g.mois.ouverture ? neutre('mois d’ouverture') : (x.n1 == null ? neutre('pas de N-1') + ' ' + esc(ouvLib) : puce(x.delta, 1, ' %', true) + ' N-1 ' + nf(x.n1, 0));
        return tuile(l, nf(x.v, 0), x.niveau, l1, esc('réseau ' + (r.v != null ? nf(r.v, 0) : '—') + (x.ecartReseau != null ? ' · ' + sg(x.ecartReseau, 0, ' %') : '')), q);
      }
      if (l.cle === 'xp') {
        const x = g.mois.xp || {}, r = R.xp || {};
        // googleN1Des : le mois où le relevé aura un an — le N-1 n'existe qu'à partir de là.
        const n1 = x.n1 != null ? puce(x.delta, 2, '', true) + ' N-1 ' + dc(x.n1, 1) + ' ★' : neutre(L.googleN1Des ? 'N-1 dès ' + moisAn(L.googleN1Des) : 'pas de N-1');
        const deb = L.googleN1Des ? moisAn(String(+L.googleN1Des.slice(0, 4) - 1) + L.googleN1Des.slice(4)) : '';
        return tuile(l, x.v != null ? dc(x.v, 1) + ' ★' : '—', x.niveau, x.v != null ? n1 : 'pas de relevé ce mois-là',
          esc('réseau ' + (r.v != null ? dc(r.v, 2) + ' ★' : '—') + (seuil('xp', a => dc(a, 1)) ? ' · ' + seuil('xp', a => dc(a, 1)) : '')),
          'note Google' + (x.avis ? ' · ' + nf(x.avis, 0) + ' avis' : ''), `<div class="l lv-pied">${esc(deb ? 'relevé mensuel depuis ' + deb : 'la note de la fiche, aujourd’hui')}</div>`);
      }
      const r = R[l.cle] || {}, s = seuil(l.cle, a => pct(a));
      const l2 = esc('réseau ' + (r.v != null ? pct(r.v) : '—') + (s ? ' · ' + s : ''));
      const x = g.mois[l.cle] || {};
      if (x.nonSaisi) { return tuile(l, '—', null, neutre('pas saisi ce mois'), l2, '% du CA'); }
      if (x.v == null) { return tuile(l, '—', null, 'pas de P&amp;L ce mois', l2, '% du CA'); }
      const l1 = x.n1 == null ? neutre(g.mois.ouverture ? 'mois d’ouverture' : 'pas de N-1') + (g.mois.ouverture ? '' : ' ' + esc(ouvLib)) : puce(x.delta, 1, ' pt', false) + ' N-1 ' + pct(x.n1);
      const hors = l.cle === 'food' && (g.horsNorme || []).some(h => h.mois === ymSel) ? '<span class="lv-al">⚠ hors norme, à vérifier au P&amp;L</span>' : '';
      return tuile(l, pct(x.v), x.niveau, l1 + hors, l2, '% du CA');
    }).join('');
    const sous = (L.moisEnCours ? 'à date (' + L.jours + ' jours), ' : '') + 'face à ' + moisAn((L.annee - 1) + '-' + String(L.mois).padStart(2, '0'), true) + ' et au réseau';
    return `<div class="db-card lv-card"><div class="ct"><span class="db-lab">Les six leviers — ${esc(moisAn(ymSel, true))}</span><span class="db-mini lv-st">${esc(sous)}</span>${seg}</div>
      <div class="lv-g">${tuiles}</div>
      <div class="db-perleg lv-leg"><span>Pastille — seuils de la Table KPI : ${pa('v')} sous l’alerte ${pa('o')} entre alerte et critique ${pa('r')} au-delà · trafic : l’écart au réseau</span><span>Écart vert : mieux qu’en ${L.annee - 1}</span></div></div>`;
  }

  /**
   * Les leviers, vue « L'évolution de l'année » (26/10/2026, Sam : « pas le cumul de l'année, l'évolution sur l'année
   * sur les cinq leviers » — la maquette D, en plus fin). Une ligne par levier : une barre fine par mois de l'année
   * (janvier → le mois en cours, à date), colorée aux seuils de la Table KPI ; le meilleur et le pire mois ; le mois en
   * cours et son statut ; la moyenne des mois lus face à celle des autres magasins sur les mêmes mois. Le trafic est
   * rouge à plus de 10 % (le seuil de la Table KPI) sous la moyenne des autres magasins ce mois-là. Google n'a pas de
   * série mois par mois : sa note reste dans « Le mois ». Un mois hors norme (food cost loin de la médiane du magasin,
   * à vérifier au P&L) se dessine hachuré et n'entre ni dans la moyenne, ni dans le meilleur et le pire.
   */
  function evolution(L, g, seg) {
    const S = L.seuils || {};
    const mois = (L.serieMois || []).map((ym, i) => ({ ym, i })).filter(m => +m.ym.slice(0, 4) === +L.annee);
    const autres = (L.magasins || []).filter(m => String(m.id) !== String(g.id));
    const hors = new Set((g.horsNorme || []).map(h => h.mois));
    const LETTRE = ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];
    const nomMois = (ym, long) => new Date(2000, +ym.slice(5, 7) - 1, 15).toLocaleDateString('fr-BE', { month: long ? 'long' : 'short' });
    const moy = a => { const x = a.filter(v => v != null); return x.length ? x.reduce((t, v) => t + v, 0) / x.length : null; };
    const f = (cle, v) => v == null ? '—' : nf(v, cle === 'trafic' ? 0 : 1) + (cle === 'trafic' ? '' : ' %');
    const num = v => v == null ? null : +v;
    const reseau = (cle, i) => moy(autres.map(m => num(((m.serie || {})[cle] || [])[i])));
    const alerteTrafic = (S.trafic || {}).alerte != null ? +S.trafic.alerte : -10;
    const seuilTxt = cle => {
      if (cle === 'trafic') { return 'rouge à plus de ' + nf(Math.abs(alerteTrafic), 0) + ' % sous le réseau'; }
      const s = S[cle];
      return s && s.alerte != null ? 'seuil ' + (s.sens === 'haut' ? '≤ ' : '≥ ') + nf(s.alerte, s.alerte % 1 ? 1 : 0) + ' %' : '';
    };
    const etat = (cle, v, i) => {
      if (v == null) { return ''; }
      if (cle === 'trafic') { const r = reseau('trafic', i); return r ? ((v / r - 1) * 100 < alerteTrafic ? 'r' : 'v') : ''; }
      const s = S[cle]; if (!s || s.alerte == null) { return ''; }
      const crit = s.critique != null ? +s.critique : +s.alerte;
      return s.sens === 'haut' ? (v <= s.alerte ? 'v' : (v <= crit ? 'o' : 'r')) : (v >= s.alerte ? 'v' : (v >= crit ? 'o' : 'r'));
    };
    const STATUT = { v: 'sous le seuil', o: 'entre alerte et critique', r: 'hors seuil' };
    const STATUT_TRAFIC = { v: 'au niveau du réseau', r: 'sous le réseau' };
    const PILL = { v: 'b', o: 'o', r: 'm' };
    const dernier = mois.length ? mois[mois.length - 1] : null;
    const lignes = (L.leviers || []).filter(l => !l.vide && l.cle !== 'xp' && (g.serie || {})[l.cle]).map(l => {
      const cle = l.cle, haut = (S[cle] || {}).sens === 'haut';
      const pts = mois.map(m => ({ ym: m.ym, i: m.i, v: num(g.serie[cle][m.i]), hors: cle === 'food' && hors.has(m.ym) }));
      const lus = pts.filter(p => p.v != null && !p.hors);
      const mx = Math.max(1, ...pts.filter(p => p.v != null).map(p => p.v));
      const barres = pts.map(p => {
        const e = p.hors ? 'h' : (p.v == null ? 'n' : (etat(cle, p.v, p.i) || 'z'));
        const h = p.v == null ? 2 : Math.max(3, Math.round(p.v / mx * 24));
        const t = nomMois(p.ym, true) + ' : ' + (p.v == null ? 'pas de valeur' : f(cle, p.v)) + (p.hors ? ' · hors norme, à vérifier au P&L' : '') + (p === pts[pts.length - 1] && L.moisEnCours ? ' (à date)' : '');
        return `<i class="${e}" style="height:${h}px" title="${esc(t)}"></i>`;
      }).join('');
      const lettres = pts.map((p, k) => `<span${k === pts.length - 1 ? ' class="cur"' : ''}>${LETTRE[+p.ym.slice(5, 7) - 1]}</span>`).join('');
      const nom = `<div class="nm"><b style="color:${esc(l.couleur)}">${esc(l.nom)}</b><small>${esc((cle === 'trafic' ? 'clients par jour' : (cle === 'recurrence' ? 'taux de retour des clients identifiés' : '% du CA')) + ' · ' + seuilTxt(cle))}</small></div>`;
      const sp = `<div class="br"><div class="sp">${barres}</div><div class="lb">${lettres}</div></div>`;
      if (!lus.length) { return `<div class="lv-ev">${nom}${sp}<div class="bm">pas encore de mois lu cette année</div><div class="an"></div></div>`; }
      const meilleur = lus.reduce((a, p) => (haut ? p.v < a.v : p.v > a.v) ? p : a);
      const pire = lus.reduce((a, p) => (haut ? p.v > a.v : p.v < a.v) ? p : a);
      const cur = pts[pts.length - 1], eCur = cur.v != null && !cur.hors ? etat(cle, cur.v, cur.i) : '';
      const curTxt = (cur.hors ? '⚠ hors norme' : (eCur ? (cle === 'trafic' ? STATUT_TRAFIC : STATUT)[eCur] : ''));
      const m = moy(lus.map(p => p.v)), mr = moy(lus.map(p => reseau(cle, p.i)));
      // L'écart arrondi comme il s'affiche : −0,04 pt se lit « au niveau du réseau ».
      const dE = cle === 'trafic' ? 0 : 1, ecBrut = m != null && mr != null ? (cle === 'trafic' ? (m / mr - 1) * 100 : m - mr) : null;
      const ec = ecBrut == null ? null : Math.round(ecBrut * 10 ** dE) / 10 ** dE;
      const bon = ec == null ? null : (haut ? ec <= 0 : ec >= 0);
      const ecTxt = ec == null ? '' : (ec === 0 ? 'au niveau du réseau' : (ec > 0 ? '+' : '−') + nf(Math.abs(ec), dE) + (cle === 'trafic' ? ' %' : ' pt') + ' face au réseau');
      const ecartes = pts.filter(p => p.hors).map(p => nomMois(p.ym) + ' ' + f(cle, p.v));
      return `<div class="lv-ev">${nom}${sp}
        <div class="bm"><span>Meilleur <b>${esc(nomMois(meilleur.ym))}</b> ${esc(f(cle, meilleur.v))}</span><span>Pire <b>${esc(nomMois(pire.ym))}</b> ${esc(f(cle, pire.v))}</span>
          <span>${esc(nomMois(cur.ym, true))}${L.moisEnCours ? ' à date' : ''} <b>${esc(f(cle, cur.v))}</b>${curTxt ? ` <em class="lv-c ${cur.hors ? 'o' : PILL[eCur]}">${esc(curTxt)}</em>` : ''}</span>
          ${ecartes.length ? `<span class="mu">écarté, hors norme : ${esc(ecartes.join(', '))}</span>` : ''}</div>
        <div class="an"><b>${esc(f(cle, m))}</b><small>moyenne ${lus.length < mois.length ? 'des ' + lus.length + ' mois lus' : 'de l’année'} · réseau ${esc(f(cle, mr))}</small>${ecTxt ? `<em class="lv-c ${ec === 0 ? 'z' : (bon ? 'b' : 'm')}">${esc(ecTxt)}</em>` : ''}</div></div>`;
    }).join('');
    const sous = mois.length ? nomMois(mois[0].ym, true) + ' → ' + nomMois(dernier.ym, true) + (L.moisEnCours ? ' (à date, ' + L.jours + ' jours)' : '') + ', mois par mois' + (g.ouverture && +g.ouverture.slice(0, 4) === +L.annee ? ' · ouvert en ' + nomMois(g.ouverture, true) : '') : '';
    return `<div class="db-card lv-card"><div class="ct"><span class="db-lab">Les cinq leviers — l’évolution de ${esc(L.annee)}</span><span class="db-mini lv-st">${esc(sous)}</span>${seg}</div>
      <div class="lv-evs">${lignes}</div>
      <div class="db-perleg lv-leg"><span>Une barre par mois, à la couleur de la Table KPI : <i class="lv-p v"></i> sous l’alerte <i class="lv-p o"></i> entre alerte et critique <i class="lv-p r"></i> au-delà</span><span>Réseau : la moyenne des autres magasins, sur les mêmes mois</span><span>Expérience client : la note Google n’a pas de relevé mois par mois, elle se lit dans « Le mois »</span></div></div>`;
  }

  window.CartesMagasin = { annee, leviers, MESURES_ANNEE: ['clients', 'panier', 'articles'] };
})();
