/* Maquettes « Clients par mois, 2026 face à 2025 » — Cockpit › Résultat › Année › 1 an.
 *
 *   A — un graphique ligne, le magasin au choix (puces + clic sur une ligne du tableau),
 *       posé entre la trajectoire et le tableau des magasins ;
 *   B — une courbe par magasin (et le réseau), sous le tableau ; une mini-courbe dans
 *       une nouvelle colonne du tableau.
 *
 * Les deux lisent window.CN_DONNEES (reel-clients-2026.json, relu le 24/10/2026) et
 * partagent le même graphique : 2026 en trait plein bordeaux, 2025 en pointillé gris,
 * la bande entre les deux en bleu quand 2026 passe devant, en orange quand il recule —
 * les codes de l'onglet Trafic clients (svgCourbe, templates.js).
 *
 * Deux lectures :
 *   - « Clients du mois » (par défaut) : le mois en cours est prolongé au rythme des jours lus
 *     (pointillé, point creux) ; son écart, lui, se calcule à date — 2025 arrêté au
 *     même jour servi, comme GET /ventes/semaines ;
 *   - « Clients par jour » (?mode=jour, sans bouton : la demande est le nombre de clients) : par jour OUVERT pour un magasin (une semaine de
 *     fermeture ne passe plus pour une chute), par jour calendaire pour le réseau.
 */
(function () {
  'use strict';
  const D = window.CN_DONNEES;
  const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const MOISL = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const Y = D.annee, ME = D.moisEnCours, JL = D.joursLus;
  const jm = (y, m) => new Date(y, m, 0).getDate();
  const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/ /g, ' ');
  const sg = n => (n < 0 ? '− ' : '+ ') + nf(Math.abs(n));
  const pc = (v, d) => v == null ? '—' : (v < 0 ? '−' : '+') + nf(Math.abs(v), d == null ? 1 : d) + ' %';
  const COUL = v => v == null ? { bg: '#F1EEEA', tx: '#8a837a' } : v >= 3 ? { bg: '#E6F2E8', tx: '#2d7a3e' } : v <= -3 ? { bg: '#FBE5E7', tx: '#C0182B' } : { bg: '#F6EFD9', tx: '#8a6508' };
  const moisDe = s => { const m = /\d\d\/(\d\d)\/(\d{4})/.exec(s || ''); return m ? { m: +m[1], y: +m[2] } : null; };

  /* --- Les séries --------------------------------------------------------------------- */
  // Un magasin, mois par mois : ce qu'on trace (n, n1), l'écart à date, et la bulle.
  function serieMagasin(mg, mode) {
    const ouv = moisDe(mg.premiereVente);
    return mg.mois.map((x, i) => {
      const m = i + 1, etat = m > ME ? 'futur' : (m === ME ? 'encours' : 'clos');
      const ouv26 = ouv && ouv.y === Y && ouv.m === m, ouv25 = ouv && ouv.y === Y - 1 && ouv.m === m;
      const p = { i, lab: MOIS[i], etat, ouv25, ouv26, notes: [] };
      if (mode === 'mois') {
        // Un mois d'ouverture est partiel (Halle : 7 jours en février) : tracé, il ferait
        // croire à une chute. On ne le relie pas ; la bulle garde le chiffre.
        p.n = x.n == null || ouv26 ? null : (etat === 'encours' ? Math.round(x.n / x.nCal * jm(Y, m)) : x.n);
        p.proj = etat === 'encours' && p.n != null;
        p.n1 = ouv25 ? null : x.n1;
      } else {
        p.n = x.n == null || !x.nJ ? null : x.n / x.nJ;
        p.n1 = x.n1 == null || !x.n1J ? null : x.n1 / x.n1J;
      }
      const comp = x.n != null && x.n1c != null && !ouv25 && !ouv26;
      p.ecart = !comp ? null : (mode === 'mois' ? 100 * (x.n / x.n1c - 1) : 100 * ((x.n / x.nJ) / (x.n1c / x.n1cJ) - 1));
      p.nonComp = (ouv25 || ouv26) && etat !== 'futur';
      if (mode === 'mois' && comp && etat === 'clos' && Math.abs(x.nJ - x.n1J) >= 3) {
        p.notes.push(x.n1J < x.nJ ? (Y - 1) + ' : ' + (jm(Y - 1, m) - x.n1J) + ' j fermés' : Y + ' : ' + (jm(Y, m) - x.nJ) + ' j fermés');
      }
      // La bulle.
      const parJ = (t, j) => j ? ' · ' + nf(t / j) + ' / jour' : '';
      const L = [`<b>${MOISL[i]}</b>`];
      if (etat === 'futur') {
        L.push(x.n1 != null ? `${Y - 1} : <b>${nf(x.n1)}</b> clients${parJ(x.n1, x.n1J)} <span class="mu">— le mois à battre</span>` : `<span class="mu">pas de ${Y - 1}</span>`);
      } else {
        if (x.n == null) { L.push(`<span class="mu">${Y} : pas encore ouvert</span>`); }
        else if (etat === 'encours') { L.push(`${Y} : <b>${nf(x.n)}</b> clients en ${x.nCal} j${parJ(x.n, x.nJ)}`, `<span class="mu">env. ${nf(Math.round(x.n / x.nCal * jm(Y, m)))} au rythme du mois entier</span>`); }
        else { L.push(`${Y} : <b>${nf(x.n)}</b> clients · ${x.nJ} j ouverts${parJ(x.n, x.nJ)}`); }
        if (x.n1 == null) { L.push(`<span class="mu">${Y - 1} : ${ouv && ouv.y === Y ? 'pas encore ouvert' : '—'}</span>`); }
        else if (etat === 'encours') { L.push(`${Y - 1} au ${JL}/${String(m).padStart(2, '0')} : <b>${nf(x.n1c)}</b>${parJ(x.n1c, x.n1cJ)} <span class="mu">(mois entier ${nf(x.n1)})</span>`); }
        else { L.push(`${Y - 1} : <b>${nf(x.n1)}</b> clients · ${x.n1J} j ouverts${parJ(x.n1, x.n1J)}`); }
        if (p.nonComp) { L.push(`<span class="mu">mois d’ouverture (${esc(mg.premiereVente)}) : pas d’écart</span>`); }
        else if (comp) {
          const ec = x.n - x.n1c, cls = ec >= 0 ? 'up' : 'dn';
          const ePj = 100 * ((x.n / x.nJ) / (x.n1c / x.n1cJ) - 1), eMo = 100 * (x.n / x.n1c - 1);
          L.push(`Écart${etat === 'encours' ? ' à date' : ''} : <b class="${cls}">${sg(ec)} clients · ${pc(eMo)}</b>` + (Math.abs(ePj - eMo) >= 1 ? `<br><span class="mu">par jour ouvert : ${pc(ePj)}</span>` : ''));
        }
      }
      p.bulle = L.join('<br>');
      return p;
    });
  }

  // Le réseau : la somme des magasins. « comp » ne garde, mois par mois, que les
  // magasins qui ont un N-1 comparable — l'écart à périmètre constant.
  function serieReseau(mode, perim) {
    const S = D.magasins.map(mg => ({ mg, s: serieMagasin(mg, 'mois') }));
    return MOIS.map((lab, i) => {
      const m = i + 1, etat = m > ME ? 'futur' : (m === ME ? 'encours' : 'clos');
      const cal = etat === 'encours' ? JL : jm(Y, m), cal1 = jm(Y - 1, m);
      const pris = S.filter(({ mg, s }) => {
        const x = mg.mois[i];
        if (perim !== 'comp') { return true; }
        if (etat === 'futur') { return x.n1 != null; }
        return x.n != null && x.n1c != null && !s[i].ouv25 && !s[i].ouv26;
      });
      let n = 0, nP = 0, n1 = 0, n1c = 0, nb = 0, nb1 = 0, vu = false, vu1 = false, vuc = false;
      pris.forEach(({ mg }) => {
        const x = mg.mois[i];
        if (x.n != null) { n += x.n; nP += etat === 'encours' ? x.n / x.nCal * jm(Y, m) : x.n; nb++; vu = true; }
        if (x.n1 != null) { n1 += x.n1; nb1++; vu1 = true; }
        if (x.n1c != null) { n1c += x.n1c; vuc = true; }
      });
      const p = { i, lab, etat, notes: [], proj: false };
      if (mode === 'mois') { p.n = vu ? Math.round(nP) : null; p.proj = etat === 'encours' && vu; p.n1 = vu1 ? n1 : null; }
      else { p.n = vu ? n / cal : null; p.n1 = vu1 ? n1 / cal1 : null; }
      const calC = etat === 'encours' ? JL : cal1;
      p.ecart = vu && vuc && etat !== 'futur' ? (mode === 'mois' ? 100 * (n / n1c - 1) : 100 * ((n / cal) / (n1c / calC) - 1)) : null;
      const L = [`<b>${MOISL[i]}</b> · réseau${perim === 'comp' ? ' à périmètre constant' : ''}`];
      if (etat === 'futur') { L.push(`${Y - 1} : <b>${nf(n1)}</b> clients · ${nb1} magasins <span class="mu">— le mois à battre</span>`); }
      else {
        L.push(`${Y} : <b>${nf(n)}</b> clients${etat === 'encours' ? ' en ' + JL + ' j' : ''} · ${nb} magasin${nb > 1 ? 's' : ''} · ${nf(n / cal)} / jour`);
        if (etat === 'encours') { L.push(`<span class="mu">env. ${nf(Math.round(nP))} au rythme du mois entier</span>`); }
        L.push(`${Y - 1}${etat === 'encours' ? ' au ' + JL + '/' + String(m).padStart(2, '0') : ''} : <b>${nf(etat === 'encours' ? n1c : n1)}</b> clients · ${nb1} magasin${nb1 > 1 ? 's' : ''}`);
        if (p.ecart != null) { const ec = n - n1c; L.push(`Écart${etat === 'encours' ? ' à date' : ''} : <b class="${ec >= 0 ? 'up' : 'dn'}">${sg(ec)} clients · ${pc(100 * (n / n1c - 1))}</b>`); }
        if (perim !== 'comp' && nb !== nb1) { L.push(`<span class="mu">${nb} magasins en ${Y} contre ${nb1} en ${Y - 1} : l’écart compte les ouvertures</span>`); }
      }
      p.bulle = L.join('<br>');
      return p;
    });
  }

  // L'année à date : seulement les mois comparables, pour que l'écart dise vrai.
  function cumul(cle, perim) {
    const mags = cle === 'reseau' ? D.magasins : D.magasins.filter(m => m.id === cle);
    let n = 0, n1 = 0, nJ = 0, n1J = 0, mois = [], tout26 = 0;
    mags.forEach(mg => {
      const s = serieMagasin(mg, 'mois');
      mg.mois.forEach((x, i) => {
        if (i + 1 > ME || x.n == null) { return; }
        tout26 += x.n;
        const comp = x.n1c != null && !s[i].ouv25 && !s[i].ouv26;
        if (cle === 'reseau' && perim !== 'comp') { n += x.n; nJ += x.nJ; if (x.n1c != null) { n1 += x.n1c; n1J += x.n1cJ; } if (!mois.includes(i)) { mois.push(i); } return; }
        if (!comp) { return; }
        n += x.n; n1 += x.n1c; nJ += x.nJ; n1J += x.n1cJ; if (!mois.includes(i)) { mois.push(i); }
      });
    });
    mois.sort((a, b) => a - b);
    const tous = mois.length === ME;
    return { n, n1, nJ, n1J, tout26, ecart: n1 ? 100 * (n / n1 - 1) : null, mois,
      plage: !mois.length ? '' : (tous ? 'janv. → ' + MOIS[ME - 1] + ' (au ' + JL + '/' + String(ME).padStart(2, '0') + ')' : MOIS[mois[0]] + ' → ' + MOIS[mois[mois.length - 1]] + (mois[mois.length - 1] === ME - 1 ? ' (au ' + JL + '/' + String(ME).padStart(2, '0') + ')' : '')) };
  }
  const serie = (cle, mode, perim) => cle === 'reseau' ? serieReseau(mode, perim) : serieMagasin(D.magasins.find(m => m.id === cle), mode);
  const nomDe = cle => cle === 'reseau' ? 'Réseau' : D.magasins.find(m => m.id === cle).court;

  /* --- Le graphique ------------------------------------------------------------------- */
  function pas(v) { const p = Math.pow(10, Math.floor(Math.log10(v))); const r = v / p; return (r <= 1 ? 1 : r <= 2 ? 2 : r <= 2.5 ? 2.5 : r <= 5 ? 5 : 10) * p; }
  function svg(P, o) {
    const W = Math.max(280, o.w), H = o.h, compact = !!o.compact;
    const ml = compact ? 34 : 46, mr = compact ? 6 : 12, mt = compact ? 14 : 24;
    const bas = compact ? 40 : (P.some(p => p.notes.length) ? 74 : 60), cb = H - bas;
    const vals = []; P.forEach(p => { if (p.n != null) { vals.push(p.n); } if (p.n1 != null) { vals.push(p.n1); } });
    if (!vals.length) { return `<svg viewBox="0 0 ${W} ${H}" height="${H}"><text x="${W / 2}" y="${H / 2}" text-anchor="middle" font-size="12" fill="#666">Aucun client lu.</text></svg>`; }
    let lo = Math.min(...vals), hi = Math.max(...vals);
    const st = pas((hi - lo || hi || 1) / 3);
    lo = Math.max(0, Math.floor((lo - st * 0.6) / st) * st); hi = Math.ceil((hi + st * 0.35) / st) * st;
    const cw = (W - ml - mr) / 12, X = i => ml + (i + 0.5) * cw, Yv = v => mt + (hi - v) / (hi - lo || 1) * (cb - mt);
    const f = (v, d) => (+v).toFixed(d == null ? 1 : d);
    const fv = v => o.mode === 'jour' ? nf(v) : nf(v);
    let h = `<svg viewBox="0 0 ${W} ${H}" height="${H}" class="cn-g">`;
    // la grille et l'échelle
    for (let v = lo; v <= hi + 1e-6; v += st) {
      h += `<line x1="${ml}" x2="${W - mr}" y1="${f(Yv(v))}" y2="${f(Yv(v))}" stroke="rgba(34,34,34,${v === lo ? .16 : .07})"/>`;
      h += `<text x="${ml - 6}" y="${f(Yv(v) + 3.5)}" text-anchor="end" font-size="${compact ? 9 : 10}" fill="#9a948c">${nf(v)}</text>`;
    }
    // le mois en cours, en fond
    const ie = P.findIndex(p => p.etat === 'encours');
    if (ie >= 0) { h += `<rect x="${f(X(ie) - cw / 2)}" y="${mt - 8}" width="${f(cw)}" height="${f(cb - mt + 8)}" fill="#8D1D2C" fill-opacity=".045" rx="4"/>`; }
    // la bande entre les deux années, coupée là où les courbes se croisent
    for (let i = 0; i < 11; i++) {
      const a = P[i], b = P[i + 1];
      if (a.n == null || a.n1 == null || b.n == null || b.n1 == null || b.proj || a.proj) { continue; }
      const x1 = X(i), x2 = X(i + 1), d1 = a.n - a.n1, d2 = b.n - b.n1;
      const poly = (pts, up) => { h += `<polygon points="${pts.map(q => f(q[0]) + ',' + f(q[1])).join(' ')}" fill="${up ? '#2E6DB4' : '#C0632B'}" fill-opacity="${compact ? .16 : .14}"/>`; };
      if (d1 * d2 >= 0) { poly([[x1, Yv(a.n)], [x2, Yv(b.n)], [x2, Yv(b.n1)], [x1, Yv(a.n1)]], d1 + d2 >= 0); }
      else {
        const t = d1 / (d1 - d2), xc = x1 + t * (x2 - x1), yc = Yv(a.n + t * (b.n - a.n));
        poly([[x1, Yv(a.n)], [xc, yc], [x1, Yv(a.n1)]], d1 > 0); poly([[xc, yc], [x2, Yv(b.n)], [x2, Yv(b.n1)]], d2 > 0);
      }
    }
    // 2025 : pointillé gris, sur les douze mois
    const segs = (cle, garde) => { const out = []; let cur = []; P.forEach(p => { if (p[cle] != null && garde(p)) { cur.push(p); } else { if (cur.length) { out.push(cur); } cur = []; } }); if (cur.length) { out.push(cur); } return out; };
    segs('n1', () => true).forEach(sg1 => { if (sg1.length > 1) { h += `<polyline points="${sg1.map(p => f(X(p.i)) + ',' + f(Yv(p.n1))).join(' ')}" fill="none" stroke="#b9b1a6" stroke-width="${compact ? 1.8 : 2}" stroke-dasharray="5 4" stroke-linejoin="round"/>`; } });
    // 2026 : trait plein jusqu'au dernier mois clos, puis le mois en cours en pointillé
    segs('n', p => !p.proj).forEach(sg2 => { if (sg2.length > 1) { h += `<polyline points="${sg2.map(p => f(X(p.i)) + ',' + f(Yv(p.n))).join(' ')}" fill="none" stroke="#8D1D2C" stroke-width="${compact ? 2.2 : 2.5}" stroke-linejoin="round" stroke-linecap="round"/>`; } });
    P.forEach(p => { if (p.proj && p.i > 0 && P[p.i - 1].n != null) { h += `<line x1="${f(X(p.i - 1))}" y1="${f(Yv(P[p.i - 1].n))}" x2="${f(X(p.i))}" y2="${f(Yv(p.n))}" stroke="#8D1D2C" stroke-width="2.2" stroke-dasharray="2 4" stroke-linecap="round"/>`; } });
    // les points et leurs valeurs
    const montrer = p => !compact || p.i === o.survol || (p.etat === 'encours') ;
    P.forEach(p => {
      const up = p.n1 == null || p.n == null || p.n >= p.n1;
      if (p.n1 != null) {
        h += `<circle cx="${f(X(p.i))}" cy="${f(Yv(p.n1))}" r="${compact ? 2.6 : 3.4}" fill="#fff" stroke="#b9b1a6" stroke-width="${p.ouv25 ? 1.5 : 2}" ${p.ouv25 ? 'stroke-dasharray="2 2"' : ''}/>`;
        if (montrer(p) && !(compact && p.etat === 'encours')) { h += `<text x="${f(X(p.i))}" y="${f(Yv(p.n1) + (up ? 15 : -8))}" text-anchor="middle" font-size="${compact ? 9 : 10}" fill="#7d776f">${fv(p.n1)}${p.ouv25 && !compact ? ' · ouverture' : ''}</text>`; }
      }
      if (p.n != null) {
        h += p.proj || p.etat === 'encours'
          ? `<circle cx="${f(X(p.i))}" cy="${f(Yv(p.n))}" r="${compact ? 3.4 : 4.4}" fill="#fff" stroke="#8D1D2C" stroke-width="2"/>`
          : `<circle cx="${f(X(p.i))}" cy="${f(Yv(p.n))}" r="${compact ? 3 : 4}" fill="#8D1D2C"/>`;
        if (montrer(p)) { h += `<text x="${f(X(p.i))}" y="${f(Yv(p.n) + (up ? -9 : 17))}" text-anchor="middle" font-size="${compact ? 9.5 : 11}" font-weight="600" fill="#8D1D2C">${fv(p.n)}</text>`; }
      }
    });
    // les mois, puis l'écart de chacun
    const yl = cb + (compact ? 13 : 17), yp = cb + (compact ? 19 : 26), hp = compact ? 15 : 18;
    P.forEach(p => {
      const cur = p.etat === 'encours';
      h += `<text x="${f(X(p.i))}" y="${f(yl)}" text-anchor="middle" font-size="${compact ? 9.5 : 10.5}" font-weight="600" fill="${cur ? '#8D1D2C' : (p.etat === 'futur' ? '#a8a197' : '#222')}">${p.lab}${cur && !compact ? ' · ' + JL + ' j' : ''}</text>`;
      if (p.etat === 'futur') { return; }
      const c = COUL(p.ecart), t = p.nonComp ? (compact ? 'ouv.' : 'ouverture') : (p.ecart == null ? '—' : pc(p.ecart, compact ? 0 : 1));
      const pw = Math.min(cw - 6, compact ? 40 : 66);
      h += `<rect x="${f(X(p.i) - pw / 2)}" y="${f(yp)}" width="${f(pw)}" height="${hp}" rx="${hp / 2}" fill="${c.bg}"/>`;
      h += `<text x="${f(X(p.i))}" y="${f(yp + hp / 2 + 3.6)}" text-anchor="middle" font-size="${compact ? 9 : 10.5}" font-weight="600" fill="${c.tx}">${t}</text>`;
      if (!compact && p.notes.length) { h += `<text x="${f(X(p.i))}" y="${f(yp + hp + 12)}" text-anchor="middle" font-size="9" fill="#9a6b2b">${esc(p.notes[0])}</text>`; }
    });
    if (!compact) { h += `<text x="${ml - 6}" y="${f(yp + hp / 2 + 3.6)}" text-anchor="end" font-size="9" font-weight="600" fill="#9a948c" letter-spacing=".04em">ÉCART</text>`; }
    // le survol : une colonne par mois
    h += `<line class="cn-guide" x1="0" x2="0" y1="${mt - 8}" y2="${cb}" stroke="#222" stroke-opacity=".25" stroke-dasharray="3 3" style="display:none"/>`;
    P.forEach(p => { h += `<rect data-i="${p.i}" data-x="${f(X(p.i))}" x="${f(X(p.i) - cw / 2)}" y="0" width="${f(cw)}" height="${H}" fill="transparent"/>`; });
    return h + '</svg>';
  }

  // Monte un graphique dans `el` : largeur réelle (le texte reste net), bulle au survol.
  function monter(el, lire) {
    const bulle = document.createElement('div'); bulle.className = 'cn-bulle';
    const rendre = () => {
      const o = lire(); const W = el.clientWidth;
      el.innerHTML = svg(o.P, Object.assign({ w: W }, o)); el.appendChild(bulle);
      const g = el.querySelector('.cn-guide');
      el.querySelectorAll('rect[data-i]').forEach(r => {
        r.addEventListener('mouseenter', () => {
          const i = +r.dataset.i, x = +r.dataset.x, p = o.P[i];
          g.setAttribute('x1', x); g.setAttribute('x2', x); g.style.display = '';
          bulle.innerHTML = p.bulle; bulle.style.display = 'block';
          const bw = bulle.offsetWidth; bulle.style.left = Math.max(0, Math.min(W - bw, x + 14 > W - bw ? x - bw - 14 : x + 14)) + 'px'; bulle.style.top = '4px';
        });
        r.addEventListener('mouseleave', () => { g.style.display = 'none'; bulle.style.display = 'none'; });
      });
    };
    rendre();
    if (window.ResizeObserver) { let lw = el.clientWidth; new ResizeObserver(() => { if (Math.abs(el.clientWidth - lw) > 2) { lw = el.clientWidth; rendre(); } }).observe(el); }
    return rendre;
  }

  /* --- Le cadre de l'écran (existant, repris de tplResultat / tplObjectifs) ------------- */
  const ONGS = ['Jour', 'Semaine', 'Mois', 'Année', '3 et 5 ans', 'Marge & coûts', 'Trafic clients'];
  function cadreHaut() {
    // la trajectoire cumulée : CA réel cumulé (somme des magasins) contre une cible linéaire — repère de contexte
    const ca = [292, 236, 311, 325, 324, 315, 266, 292, 301, 252].map(v => v * 1000); // k€, réseau 2026 (/ventes/reseau?vue=annee)
    let c = 0; const cum = ca.map(v => (c += v));
    const cible = Array.from({ length: 12 }, (_, i) => 3.9e6 * (i + 1) / 12);
    const Xt = i => 20 + i * (620 / 11), Yt = v => 195 - v / 4.1e6 * 180;
    return `<div class="mq-h1">Résultat</div>
      <div class="mq-sous">La journée, la semaine et le mois — puis l’année, les horizons à 3 et 5 ans et la marge. Où en est-on, et comment évolue-t-on.</div>
      <div class="mq-ongs"><div>${ONGS.map(o => `<span class="${o === 'Année' ? 'on' : ''}"><i>✓</i>${o}</span>`).join('')}</div></div>
      <div class="mq-hz"><span class="on">1 an — ${Y}</span><span>3 ans — ${Y + 2}</span><span>5 ans — ${Y + 4}</span></div>
      <div class="mq-haut mq-existant">
        <div class="mq-carte">
          <div class="mq-k">Cible réseau ${Y}</div>
          <div style="font-size:32px;font-weight:500;margin-top:6px">3,9 M€</div>
          <div style="font-size:11px;color:var(--color-text-muted);margin-top:2px">somme des magasins — aucune cible réseau encodée</div>
          <div class="mq-ligne"><span>Réel au 24/10</span><span style="font-weight:500">2,9 M€</span></div>
          <div class="mq-ligne"><span>Cible au prorata</span><span>3,1 M€</span></div>
          <div class="mq-ligne"><span>Taux d’atteinte</span><span style="color:#8a6508;font-weight:500">93,8 %</span></div>
          <div class="mq-ligne"><span>Projection fin ${Y}</span><span style="font-weight:500">3,6 M€</span></div>
        </div>
        <div class="mq-carte">
          <div style="display:flex;justify-content:space-between;align-items:center"><div style="font-size:13px;font-weight:500">Trajectoire cumulée ${Y} — réel vs cible</div>
            <div class="cn-leg"><span><i></i>Réel</span><span><i style="border-top:2px dashed #c9a06a"></i>Cible</span></div></div>
          <svg viewBox="0 0 660 210" width="100%" height="170" preserveAspectRatio="none" style="margin-top:8px"><line x1="20" y1="195" x2="640" y2="195" stroke="rgba(34,34,34,.15)"/>
            <polyline points="${cible.map((v, i) => Xt(i).toFixed(1) + ',' + Yt(v).toFixed(1)).join(' ')}" fill="none" stroke="#c9a06a" stroke-width="2" stroke-dasharray="5 4"/>
            <polyline points="${cum.map((v, i) => Xt(i).toFixed(1) + ',' + Yt(v).toFixed(1)).join(' ')}" fill="none" stroke="#8D1D2C" stroke-width="2.5"/></svg>
          <div style="display:grid;grid-template-columns:repeat(12,1fr);font-size:10px;color:var(--color-text-muted);padding:0 4px">${['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'].map(m => `<span style="text-align:center">${m}</span>`).join('')}</div>
        </div>
      </div>`;
  }
  const ATT = { '105 %': ['#E6F2E8', '#2d7a3e'], '96 %': ['#F6EBDD', '#8a5a00'], '93 %': ['#F6EBDD', '#8a5a00'], '67 %': ['#F6E4E6', '#8D1D2C'] };
  // Le tableau des magasins ; `colonne(mg)` rend la nouvelle cellule, `attr(mg)` les attributs de la ligne.
  function tableau(colonne, attr, titreCol) {
    const rows = D.tableau.map(t => {
      const mg = D.magasins.find(m => m.court === t.court);
      return `<tr ${attr ? attr(mg) : ''}>
        <td>${esc(mg.nom)}${attr ? '<span class="cn-voir">voir la courbe ↑</span>' : ''}</td>
        <td>${t.cible}${t.source ? `<div class="src">${esc(t.source)}</div>` : ''}</td>
        <td style="font-weight:500">${t.reel}</td><td style="color:var(--color-text-muted)">${t.prorata}</td>
        <td style="text-align:center;color:${t.up ? '#2d7a3e' : '#8D1D2C'}">${t.up ? '▲' : '▼'} ${t.ecart}</td>
        <td style="text-align:center"><span class="mq-pill" style="background:${ATT[t.att][0]};color:${ATT[t.att][1]}">${t.att}</span></td>
        <td class="cn-col">${colonne(mg)}</td>
        <td><span class="ouv">Ouvrir →</span></td></tr>`;
    }).join('');
    return `<div class="mq-carte mq-scroll"><table class="mq-tab"><thead><tr><th>Magasin</th><th>Cible ${Y}</th><th>Réel au 24/10</th><th>Cible prorata</th><th style="text-align:center">Écart</th><th style="text-align:center">Atteinte</th><th>${titreCol}<span class="cn-neuf">nouveau</span></th><th>Suivi budget</th></tr></thead><tbody>${rows}</tbody></table></div>`;
  }
  const legende = () => `<div class="cn-leg"><span><i></i>${Y}</span><span><i class="n1"></i>${Y - 1}</span><span><i class="bd"></i>${Y} devant / derrière</span></div>`;
  const cellCumul = (cle, perim) => {
    const c = cumul(cle, perim);
    if (c.ecart == null) { return `<span style="color:var(--color-text-muted)">${nf(c.tout26)}</span><small>pas de ${Y - 1}</small>`; }
    const k = COUL(c.ecart);
    return `${nf(c.n)} <b style="color:${k.tx};font-weight:600">${pc(c.ecart)}</b><small>${Y - 1} : ${nf(c.n1)}${c.mois.length < ME ? ' · ' + esc(c.plage.replace(/ \(au .*\)$/, '')) : ''}</small>`;
  };
  const noteBas = (mode, cle, perim) => `<div class="cn-note"><b>Lecture.</b> ${mode === 'mois'
    ? `${MOISL[ME - 1].charAt(0).toUpperCase() + MOISL[ME - 1].slice(1)} est en cours (${JL} jours lus) : sa valeur est prolongée au rythme du mois (pointillé, point creux), mais son <b>écart</b> se calcule à date — ${Y - 1} arrêté au ${JL}/${String(ME).padStart(2, '0')}. Novembre et décembre ${Y - 1} restent tracés : ce sont les mois à battre.`
    : `Clients par jour ${cle === 'reseau' ? 'calendaire (le réseau ouvre 7 j / 7)' : '<b>ouvert</b> : une semaine de fermeture ne passe plus pour une chute de clientèle'} ; le mois en cours se lit sur ses ${JL} premiers jours.`}
    ${cle === 'reseau' && perim !== 'comp' ? ` Le réseau compte <b>tous</b> les magasins : Halle (ouvert le 20/02/${Y}) et Sombreffe (25/04/${Y - 1}) gonflent l’écart — « périmètre constant » ne garde que les magasins qui ont un ${Y - 1}.` : ''}
    Source : panel, ventes par jour (GET /ventes/semaines, vue année), relu le 24/10/${Y}.</div>`;

  /* --- Maquette A : un graphique, le magasin au choix ------------------------------------ */
  function pageA(root) {
    const S = { cle: (new URLSearchParams(location.search)).get('magasin') || 'reseau', mode: (new URLSearchParams(location.search)).get('mode') || 'mois', perim: 'tous' };
    root.innerHTML = cadreHaut() + `<div class="cn-carte" id="cn-a"></div><div id="cn-tab"></div>`;
    const carte = root.querySelector('#cn-a'), tab = root.querySelector('#cn-tab');
    let redessiner = null;
    function rendre() {
      const c = cumul(S.cle, S.perim), P = serie(S.cle, S.mode, S.perim);
      const mg = S.cle === 'reseau' ? null : D.magasins.find(m => m.id === S.cle);
      const k = COUL(c.ecart);
      carte.innerHTML = `<div class="cn-tete"><span class="cn-titre">Clients par mois — ${Y} face à ${Y - 1}<span class="cn-neuf">nouveau</span></span>${legende()}</div>
        <div class="cn-puces">${['reseau'].concat(D.tableau.map(t => D.magasins.find(m => m.court === t.court).id)).map(id => {
          const cc = cumul(id, S.perim); const kk = COUL(cc.ecart);
          return `<button class="cn-puce${S.cle === id ? ' on' : ''}" data-cle="${id}">${id === 'reseau' ? 'Réseau · ' + D.magasins.length + ' magasins' : esc(nomDe(id))}<b style="color:${kk.tx}">${cc.ecart == null ? 'pas de N-1' : pc(cc.ecart)}</b></button>`; }).join('')}
          ${S.cle === 'reseau' ? `<span class="cn-perim">périmètre <button data-perim="tous" class="${S.perim === 'tous' ? 'on' : ''}">tous les magasins</button><button data-perim="comp" class="${S.perim === 'comp' ? 'on' : ''}">constant</button></span>` : ''}</div>
        <div class="cn-chiffres">
          <div><small>Clients ${Y}</small><b>${nf(c.ecart == null ? c.tout26 : c.n)}</b><span>${c.ecart == null ? 'au 24/10' : esc(c.plage)}</span></div>
          <div><small>${Y - 1}, même période</small><b>${c.ecart == null ? '—' : nf(c.n1)}</b><span>${c.ecart == null ? (mg && mg.premiereVente ? 'première vente le ' + esc(mg.premiereVente) : '') : 'arrêté au même jour servi'}</span></div>
          <div><small>Écart</small><b style="color:${k.tx}">${c.ecart == null ? '—' : sg(c.n - c.n1)}</b><span>${c.ecart == null ? 'pas d’année précédente' : pc(c.ecart) + ' de clients'}${S.cle === 'reseau' && S.perim !== 'comp' ? ' · à périmètre constant ' + pc(cumul('reseau', 'comp').ecart) : ''}</span></div>
        </div>
        <div class="cn-svg" id="cn-g"></div>${noteBas(S.mode, S.cle, S.perim)}`;
      redessiner = monter(carte.querySelector('#cn-g'), () => ({ P, h: 300, mode: S.mode }));
      carte.querySelectorAll('[data-cle]').forEach(b => b.onclick = () => { S.cle = b.dataset.cle; rendre(); });
      carte.querySelectorAll('[data-perim]').forEach(b => b.onclick = () => { S.perim = b.dataset.perim; rendre(); });
      tab.innerHTML = tableau(mg2 => cellCumul(mg2.id), mg2 => `class="cn-cl${S.cle === mg2.id ? ' cn-sel' : ''}" data-cle="${mg2.id}"`, `Clients ${Y} vs ${Y - 1}`);
      tab.querySelectorAll('tr[data-cle]').forEach(tr => tr.onclick = () => { S.cle = S.cle === tr.dataset.cle ? 'reseau' : tr.dataset.cle; rendre(); carte.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); });
    }
    rendre();
  }

  /* --- Maquette B : une courbe par magasin ------------------------------------------------ */
  function spark(cle) {
    const P = serie(cle, 'mois', 'tous'), W = 92, H = 28;
    const v = []; P.forEach(p => { if (p.n != null && !p.proj) { v.push(p.n); } if (p.n1 != null) { v.push(p.n1); } });
    if (!v.length) { return ''; }
    const lo = Math.min(...v) * 0.92, hi = Math.max(...v) * 1.04, X = i => 2 + i * (W - 4) / 11, Yv = x => 2 + (hi - x) / (hi - lo || 1) * (H - 4);
    const l = (k, g) => P.filter(p => p[k] != null && g(p)).map(p => X(p.i).toFixed(1) + ',' + Yv(p[k]).toFixed(1)).join(' ');
    return `<svg class="cn-spark" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}"><polyline points="${l('n1', () => true)}" fill="none" stroke="#b9b1a6" stroke-width="1.5" stroke-dasharray="3 2.5"/><polyline points="${l('n', p => !p.proj)}" fill="none" stroke="#8D1D2C" stroke-width="2"/></svg>`;
  }
  function pageB(root) {
    const S = { mode: (new URLSearchParams(location.search)).get('mode') || 'mois', perim: 'tous' };
    root.innerHTML = cadreHaut() + `<div id="cn-tab"></div><div id="cn-b"></div>`;
    const tab = root.querySelector('#cn-tab'), zone = root.querySelector('#cn-b');
    tab.innerHTML = tableau(mg => `<div style="display:flex;align-items:center;justify-content:flex-end;gap:6px">${spark(mg.id)}<div>${cellCumul(mg.id)}</div></div>`, mg => `class="cn-cl" data-cle="${mg.id}"`, `Clients ${Y} vs ${Y - 1}`)
      .replace(/voir la courbe ↑/g, 'voir la courbe ↓');
    function carteHtml(cle, grand) {
      const c = cumul(cle, S.perim), k = COUL(c.ecart), mg = cle === 'reseau' ? null : D.magasins.find(m => m.id === cle);
      return `<div class="cn-carte" id="cn-c-${cle}"><div class="cn-mini-t"><b>${cle === 'reseau' ? 'Réseau · ' + D.magasins.length + ' magasins' : esc(mg.nom)}</b>
        ${cle === 'reseau' ? `<span class="cn-perim">périmètre <button data-perim="tous" class="${S.perim === 'tous' ? 'on' : ''}">tous les magasins</button><button data-perim="comp" class="${S.perim === 'comp' ? 'on' : ''}">constant</button></span>` : ''}
        <span class="v">${c.ecart == null ? `${nf(c.tout26)} clients · <span style="color:var(--color-text-muted)">pas de ${Y - 1} — première vente le ${esc(mg && mg.premiereVente)}</span>` : `${nf(c.n)} clients · <strong style="color:${k.tx}">${pc(c.ecart)}</strong> <span style="color:var(--color-text-muted)">(${sg(c.n - c.n1)})${cle === 'reseau' && S.perim !== 'comp' ? ' · à périmètre constant ' + pc(cumul('reseau', 'comp').ecart) : ''}</span>`}</span></div>
        <div class="cn-mini-s">${c.ecart == null ? `${Y} seul : rien à comparer avant l’ouverture` : `${Y - 1} même période : ${nf(c.n1)} · ${esc(c.plage)}`}</div>
        <div class="cn-svg" data-g="${cle}"></div></div>`;
    }
    function rendre() {
      zone.innerHTML = `<div class="cn-sec"><h2>Clients par mois — ${Y} face à ${Y - 1}</h2><span class="cn-neuf">nouveau</span><small>un graphique par magasin, chacun à son échelle</small>${legende()}</div>
        ${carteHtml('reseau', true)}<div class="cn-grille" style="margin-top:16px">${D.tableau.map(t => carteHtml(D.magasins.find(m => m.court === t.court).id)).join('')}</div>${noteBas(S.mode, 'magasin', 'comp')}`;
      zone.querySelectorAll('[data-g]').forEach(el => { const cle = el.dataset.g; monter(el, () => ({ P: serie(cle, S.mode, S.perim), h: cle === 'reseau' ? 250 : 210, compact: cle !== 'reseau', mode: S.mode })); });
      zone.querySelectorAll('[data-perim]').forEach(b => b.onclick = () => { S.perim = b.dataset.perim; rendre(); });
    }
    rendre();
    tab.querySelectorAll('tr[data-cle]').forEach(tr => tr.onclick = () => { const c = zone.querySelector('#cn-c-' + tr.dataset.cle); if (c) { c.scrollIntoView({ behavior: 'smooth', block: 'center' }); c.style.boxShadow = '0 0 0 2px #8D1D2C'; setTimeout(() => { c.style.boxShadow = ''; }, 1400); } });
  }

  window.CN = { pageA, pageB, serie, cumul };
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-mq]').forEach(el => (el.dataset.mq === 'b' ? pageB : pageA)(el));
  });
})();
