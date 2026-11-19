/* Dashboard magasin › Promotions : les bundles du Coach Vente (17/11/2026, maquette docs/maquettes/promo-coach, « push it »).
 *
 * À la place de la liste des promotions : 1 le constat (la marge de base, chaque produit en marge × pièces vendues),
 * 2 les couples (un produit qui attire mais rapporte peu + un produit à grande valeur ajoutée), 3 le prix du bundle,
 * 4 la fiche pré-encodée, 5 le lancement : la promotion créée au panel, en caisse du lundi qui suit, et sa fiche PDF
 * envoyée à l'équipe (Paramètres › Mails et automatisations) depuis la boîte du magasin.
 *   Routes : GET /assistant/magasin/bundles, POST /assistant/magasin/bundles/apercu | lancer | renvoyer, GET /assistant/magasin/bundles/fiche
 *   (src/franchise/bundles.php).
 *
 * `window.DashBundles.racine(shop, {apiBase, aller, coach})` rend l'élément du module, gardé d'un rendu à l'autre.
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (v, d) => new Intl.NumberFormat('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).format(v || 0).replace(/[   ]/g, ' ');
  const eur = v => nf(v, 2) + ' €';
  const pct = (v, d) => nf(v, d == null ? 1 : d) + ' %';
  const sgn = (v, d) => (v >= 0 ? '+ ' : '− ') + nf(Math.abs(v), d == null ? 2 : d);
  const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const C = { x: '#A82E40', y: '#1B6FA8', autre: '#cfc8bd', grille: '#ece6dc', axe: '#9a948b', encre: '#2b2724' };
  const PHASES = ['Le constat', 'Les couples', 'Le prix', 'La fiche', 'Le lancement'];
  const PRISES = [[0.05, '1 client sur 20'], [0.1, '1 client sur 10'], [0.2, '1 client sur 5']];

  const st = { shop: null, api: '../api/cockpit', el: null, aller: null, coach: null, d: null, err: null, phase: 1, sel: 0, prix: null, nom: '', duree: '1m', canal: 'tous',
    envoyer: true, prise: 0.1, occupe: false, res: null, flash: null };

  /* --- lectures et écritures ------------------------------------------------------------------------------------ */
  function repondre(r) {
    return r.json().catch(() => ({})).then(j => r.ok ? j : Promise.reject(new Error((j && j.erreurs && j.erreurs.join(' ')) || (j && j.error) || ('HTTP ' + r.status))));
  }
  const lire = () => fetch(st.api + '/assistant/magasin/bundles?shop=' + encodeURIComponent(st.shop), { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(repondre);
  const poster = (p, corps) => fetch(st.api + p, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ shop: st.shop }, corps)) });
  function charger() {
    st.err = null;
    return lire().then(d => { st.d = d; choisir(st.sel); }).catch(e => { st.err = e.message; }).finally(rendre);
  }
  /** Un PDF (aperçu ou fiche) ouvert dans un nouvel onglet. */
  function ouvrirPdf(promesse) {
    const w = window.open('', '_blank');
    promesse.then(r => r.ok ? r.blob() : r.json().catch(() => ({})).then(j => Promise.reject(new Error(j.error || ('HTTP ' + r.status)))))
      .then(b => { const u = URL.createObjectURL(b); if (w) { w.location.href = u; } else { window.open(u, '_blank'); } })
      .catch(e => { if (w) { w.close(); } flash(false, 'La fiche ne s’ouvre pas : ' + e.message); });
  }
  function flash(ok, texte) { st.flash = { ok, texte }; rendre(); }

  /* --- les chiffres ---------------------------------------------------------------------------------------------- */
  const prod = id => (st.d.produits || []).find(p => p.id === id) || null;
  const couples = () => (st.d && st.d.couples) || [];
  const couple = () => couples()[st.sel] || null;
  function choisir(i) {
    st.sel = Math.max(0, Math.min(i, couples().length - 1));
    const c = couple();
    st.prix = c ? c.prix : null;
    st.nom = c ? nomPropose(c) : '';
  }
  function nomPropose(c) {
    const m = { 'le matin': 'Duo du matin', 'le midi': 'Duo du midi', 'l’après-midi': 'Duo du goûter' }[c.moment];
    return m || (c.xNom + ' + ' + c.yNom).slice(0, 80);
  }
  /** Un couple à un prix : comme le serveur (bndCouple). */
  function chiffrer(X, Y, prix) {
    const sep = X.ttc + Y.ttc, htB = prix * (X.ht + Y.ht) / sep, mB = htB - X.cout - Y.cout, gain = mB - X.me;
    const n = Math.round(st.prise * X.q * 30 / st.d.periode.jours);
    return { separes: sep, prix, remise: sep - prix, remisePct: 100 * (sep - prix) / sep, htB, margeBundle: mB, margeBundlePct: 100 * mB / htB, gainTicket: gain,
      bundlesMois: n, gainMois: st.prise * X.q * 30 / st.d.periode.jours * gain, coef: htB / (X.cout + Y.cout), margeSep: X.me + Y.me };
  }
  const zone = r => r >= 7.95 && r <= 12.55 ? 'bon' : (r > (st.d.regles.remiseTrop || 15) ? 'trop' : '');
  /** Les dates : du lundi qui suit aujourd'hui, pour la durée (comme le serveur, bndDates). */
  function dates(duree) {
    const a = new Date(); a.setHours(12, 0, 0, 0);
    const du = new Date(a); du.setDate(a.getDate() + ((8 - a.getDay()) % 7 || 7));
    const au = new Date(du);
    if (duree === '2s') { au.setDate(du.getDate() + 13); } else { au.setMonth(du.getMonth() + (duree === '2m' ? 2 : 1)); au.setDate(au.getDate() - 1); }
    return [du, au];
  }
  const jour = (d, an) => JOURS[d.getDay()] + ' ' + d.getDate() + ' ' + MOIS[d.getMonth()] + (an ? ' ' + d.getFullYear() : '');
  const jm = d => String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0');
  const jmS = s => s ? String(s).slice(8, 10) + '/' + String(s).slice(5, 7) : '';

  /* --- les graphiques -------------------------------------------------------------------------------------------- */
  const halo = (x, y, t, a, c, fw) => `<text x="${x.toFixed(1)}" y="${y.toFixed(1)}" font-size="11.5" font-weight="${fw || 600}" text-anchor="${a || 'middle'}" fill="${c || C.encre}" stroke="#fff" stroke-width="4" stroke-linejoin="round" paint-order="stroke">${esc(t)}</text>`;
  /** Le nuage : chaque produit, sa marge (verticale) et ses pièces vendues (horizontale, échelle log). */
  function nuage(w) {
    const P = st.d.produits || [], base = st.d.base.base, h = 380, g = 52, dr = 14, hh = 16, b = 42, W = w - g - dr, H = h - hh - b;
    const qmax = Math.max(1000, ...P.map(p => p.q)), lmax = Math.log10(qmax * 1.4);
    const lx = q => g + W * Math.log10(Math.max(1, q)) / lmax, ly = m => hh + H * (1 - Math.max(0, Math.min(100, m)) / 100);
    const cs = couples(), xs = new Set(cs.map(c => c.x)), ys = new Set(cs.map(c => c.y)), sel = couple();
    let s = `<rect x="${lx(qmax / 8).toFixed(1)}" y="${ly(base).toFixed(1)}" width="${(g + W - lx(qmax / 8)).toFixed(1)}" height="${(ly(5) - ly(base)).toFixed(1)}" fill="${C.x}" fill-opacity=".06" rx="6"/>`
      + `<rect x="${g}" y="${ly(100).toFixed(1)}" width="${W}" height="${(ly(st.d.regles.margeHaute) - ly(100)).toFixed(1)}" fill="${C.y}" fill-opacity=".06" rx="6"/>`;
    for (let m = 0; m <= 100; m += 20) { s += `<line x1="${g}" x2="${g + W}" y1="${ly(m).toFixed(1)}" y2="${ly(m).toFixed(1)}" stroke="${C.grille}"/><text x="${g - 8}" y="${(ly(m) + 4).toFixed(1)}" font-size="11" text-anchor="end" fill="${C.axe}">${m} %</text>`; }
    for (const q of [1, 10, 100, 1000, 10000]) { if (q <= qmax * 1.4) { s += `<line x1="${lx(q).toFixed(1)}" x2="${lx(q).toFixed(1)}" y1="${hh}" y2="${hh + H}" stroke="${C.grille}"/><text x="${lx(q).toFixed(1)}" y="${hh + H + 16}" font-size="11" text-anchor="middle" fill="${C.axe}">${nf(q)}</text>`; } }
    s += `<text x="${g + W}" y="${hh + H + 34}" font-size="11" text-anchor="end" fill="#7a736a">pièces vendues en ${st.d.periode.jours} jours (échelle log) →</text>`;
    const tri = P.slice().sort((a, b2) => (xs.has(a.id) || ys.has(a.id) ? 1 : 0) - (xs.has(b2.id) || ys.has(b2.id) ? 1 : 0));
    tri.forEach(p => {
      const c = xs.has(p.id) ? C.x : (ys.has(p.id) ? C.y : C.autre), gros = c !== C.autre;
      s += `<circle cx="${lx(p.q).toFixed(1)}" cy="${ly(p.m).toFixed(1)}" r="${gros ? 5 : 3.2}" fill="${c}" stroke="#fff" stroke-width="${gros ? 1.5 : 1}"><title>${esc(p.nom)} : ${nf(p.q)} pièces, marge ${pct(p.m)}</title></circle>`;
    });
    s += `<line x1="${g}" x2="${g + W}" y1="${ly(base).toFixed(1)}" y2="${ly(base).toFixed(1)}" stroke="${C.encre}" stroke-width="1.5" stroke-dasharray="5 4"/>`;
    s += halo(g + 6, ly(base) - 7, 'votre marge de base : ' + pct(base), 'start');
    s += halo(g + W - 6, ly(9), 'ils font le volume, pas la marge', 'end', '#8D1D2C', 400);
    s += halo(g + W - 6, ly(97) + 4, 'grande valeur ajoutée, peu vendus', 'end', '#1B5D8C', 400);
    // Les noms : ceux du couple choisi (les autres se lisent au survol et dans les listes).
    if (sel) {
      [[prod(sel.x), C.x], [prod(sel.y), C.y]].forEach(([p]) => {
        if (!p) { return; }
        const x = lx(p.q), droite = x < g + W * 0.7;
        s += `<circle cx="${x.toFixed(1)}" cy="${ly(p.m).toFixed(1)}" r="8" fill="none" stroke="${C.encre}" stroke-width="1.2"/>`;
        s += halo(x + (droite ? 12 : -12), ly(p.m) + 4, p.nom, droite ? 'start' : 'end');
      });
    }
    return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" role="img" aria-label="La marge et les ventes de chaque produit" style="display:block;font-family:inherit">${s}</svg>`;
  }
  /** Les prix possibles du couple choisi, de −22 % aux deux séparés, de 10 en 10 centimes. */
  function prixPossibles(X, Y) {
    const sep = Math.round((X.ttc + Y.ttc) * 100) / 100, L = [];
    for (let p = Math.max(X.ttc + 0.1, Math.round(sep * 0.78 * 10) / 10); p <= sep + 1e-6; p = Math.round((p + 0.1) * 100) / 100) { L.push(Math.round(p * 100) / 100); }
    if (L[L.length - 1] !== sep) { L.push(sep); }
    return L.slice(-12);
  }
  function colonnes(w, X, Y) {
    const L = prixPossibles(X, Y), cols = L.map(p => chiffrer(X, Y, p));
    const h = 280, g = 48, dr = 8, hh = 24, b = 46, W = w - g - dr, H = h - hh - b, top = Math.max(...cols.map(c => c.margeBundle), X.me) * 1.14;
    const y = v => hh + H * (1 - v / top), bw = W / cols.length;
    let s = '';
    for (let v = 0; v <= top; v += top > 6 ? 2 : 1) { s += `<line x1="${g}" x2="${g + W}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="${v === 0 ? '#d9d2c7' : C.grille}"/><text x="${g - 8}" y="${(y(v) + 4).toFixed(1)}" font-size="11" text-anchor="end" fill="${C.axe}">${v} €</text>`; }
    cols.forEach((c, i) => {
      const z = zone(c.remisePct), col = z === 'bon' ? C.x : (z === 'trop' ? '#e9b9a6' : '#d9cfc4'), on = Math.abs(c.prix - st.prix) < 0.001;
      const x0 = g + i * bw + bw * 0.18, lg = bw * 0.64, yy = y(c.margeBundle), cx = g + i * bw + bw / 2;
      s += `<g data-bdprix="${c.prix}" style="cursor:pointer"><rect x="${(g + i * bw).toFixed(1)}" y="${hh}" width="${bw.toFixed(1)}" height="${(H + b).toFixed(1)}" fill="transparent"/>`
        + `<path d="M${x0.toFixed(1)},${y(0).toFixed(1)}V${(yy + 4).toFixed(1)}Q${x0.toFixed(1)},${yy.toFixed(1)} ${(x0 + 4).toFixed(1)},${yy.toFixed(1)}H${(x0 + lg - 4).toFixed(1)}Q${(x0 + lg).toFixed(1)},${yy.toFixed(1)} ${(x0 + lg).toFixed(1)},${(yy + 4).toFixed(1)}V${y(0).toFixed(1)}Z" fill="${col}"><title>${eur(c.prix)} : marge ${eur(c.margeBundle)} (${pct(c.margeBundlePct)}), le client gagne ${eur(c.remise)}</title></path>`
        + (on ? `<rect x="${(cx - Math.min(28, bw / 2 - 1)).toFixed(1)}" y="${(y(0) + 6).toFixed(1)}" width="${Math.min(56, bw - 2).toFixed(1)}" height="21" rx="6" fill="#8D1D2C"/>` : '')
        + `<text x="${cx.toFixed(1)}" y="${(y(0) + 21).toFixed(1)}" font-size="${bw < 54 ? 11 : 12}" text-anchor="middle" fill="${on ? '#fff' : C.encre}">${eur(c.prix).replace('\u00a0€', bw < 54 ? '' : '\u00a0€')}</text>`
        + `<text x="${cx.toFixed(1)}" y="${(y(0) + 40).toFixed(1)}" font-size="11" text-anchor="middle" fill="#7a736a">${c.remise < 0.005 ? 'séparés' : '−' + pct(c.remisePct, 0)}</text></g>`;
      if (on || i === 0 || i === cols.length - 1) { s += halo(cx, yy - 7, eur(c.margeBundle)); }
    });
    s += `<line x1="${g}" x2="${g + W}" y1="${y(X.me).toFixed(1)}" y2="${y(X.me).toFixed(1)}" stroke="${C.encre}" stroke-width="1.5" stroke-dasharray="5 4"/>`;
    s += halo(g + 4, y(X.me) - 7, X.nom + ' seul : ' + eur(X.me), 'start');
    return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" role="img" aria-label="La marge du bundle selon son prix" style="display:block;font-family:inherit">${s}</svg>`;
  }

  /* --- ce qui se voit ------------------------------------------------------------------------------------------- */
  function tete(titre, bulle) {
    const pas = PHASES.map((p, i) => { const n = i + 1, fait = n < st.phase, on = n === st.phase, ok = fait && st.phase < 5;
      return `<li class="${fait ? 'fait' : (on ? 'on' : '')}">${ok ? `<button type="button" data-bdphase="${n}">` : ''}<span>${fait ? '✓' : n}</span>${p}${ok ? '</button>' : ''}</li>`; }).join('');
    return `<header class="bd-h"><img class="bd-av" src="avatars/vente.png" alt="Coach Vente"><div><span class="bd-k">Coach Vente · créer un bundle qui monte votre marge</span><h2>${esc(titre)}</h2><p>${esc(bulle)}</p></div><ol class="bd-pas">${pas}</ol></header>`;
  }
  const pied = (boutons, mu) => `<footer class="bd-act">${boutons}${st.coach ? '<button type="button" class="bd-lien" data-bd="coach">En discuter avec le Coach Vente ›</button>' : ''}<span class="esp"></span><span class="mu">${mu || ''}</span></footer>`;
  const tuile = (k, v, s, cl) => `<div class="bd-tuile${cl ? ' ' + cl : ''}"><div class="k">${esc(k)}</div><div class="v">${v}</div><div class="s">${s}</div></div>`;
  const nomCourt = n => String(n || '').replace(/ \(à emporter\)/i, '');
  const maj = n => (n ? n.charAt(0).toUpperCase() + n.slice(1) : n);

  function phase1() {
    const d = st.d, B = d.base, P = d.produits || [], cs = couples();
    const X = cs.map(c => prod(c.x)).filter(Boolean), Y = [...new Map(cs.map(c => [c.y, prod(c.y)])).values()].filter(Boolean);
    const liste = (L, cl) => L.map(p => `<li><i class="${cl}"></i><span class="n" title="${esc(p.nom)}">${esc(maj(nomCourt(p.nom)))}</span><span>${nf(p.q)} pcs</span><b>${pct(p.m)}</b></li>`).join('');
    const bulle = cs.length
      ? `« Votre marge de base est de ${pct(B.base)}. Ce qui fait votre volume (${X.slice(0, 3).map(p => p.nom.toLowerCase()).join(', ')}) est sous cette marge ; ce qui rapporte le plus (${Y.slice(0, 3).map(p => nomCourt(p.nom).toLowerCase()).join(', ')}) se vend peu. Couplons-les : le produit qui attire fait vendre celui qui rapporte. »`
      : `« Votre marge de base est de ${pct(B.base)}. Je ne trouve pas encore de couple qui la fasse monter : il faut des produits à ${pct(d.regles.margeHaute, 0)} de marge et plus, avec leur coût de recette au panel. »`;
    return tete('Votre marge de base, et ce qui la tire vers le bas', bulle)
      + `<div class="bd-k4">${tuile('Marge de base', pct(B.base), 'prix hors TVA − coût de recette, sur ' + nf(B.produits) + ' produits chiffrés')}${tuile('Sous la marge de base', pct(B.sousPct, 0), 'des pièces vendues')}
        ${tuile('À ' + pct(d.regles.margeHaute, 0) + ' de marge et plus', pct(B.hautPct), 'des pièces vendues : ce qu’il faut faire monter')}${tuile('La période', `<span style="font-size:16px">${jmS(d.periode.du)} → ${jmS(d.periode.au)}</span>`, nf(B.pieces) + ' pièces vendues')}</div>
      <div class="bd-c1"><div><p class="bd-t">Chaque produit : sa marge et ses ventes</p><div class="bd-graphe" data-bdgraphe="nuage"></div>
        <p class="bd-leg"><span><i style="background:${C.x}"></i>le produit qui attire, à coupler</span><span><i style="background:${C.y}"></i>le produit à grande valeur ajoutée</span><span><i style="background:${C.autre}"></i>les autres produits</span><span><i class="tir"></i>la marge de base</span></p></div>
        <div class="bd-listes"><p class="bd-t">Ils font le volume, pas la marge</p><ul class="bd-l">${liste(X, 'r') || '<li class="mu">—</li>'}</ul>
          <p class="bd-t" style="margin-top:16px">Grande valeur ajoutée, peu vendus</p><ul class="bd-l">${liste(Y, 'b') || '<li class="mu">—</li>'}</ul></div></div>
      ${pied(cs.length ? '<button type="button" class="bd-btn go" data-bdphase="2">Voir les couples qui montent la marge ›</button>' : '', 'Les chiffres de ' + esc(d.magasin) + ', relus au panel')}`;
  }
  function phase2() {
    const cs = couples(), d = st.d;
    const lignes = cs.map((c, i) => {
      const X = prod(c.x), Y = prod(c.y), k = chiffrer(X, Y, c.prix);
      return `<tr class="${i === st.sel ? 'on' : ''}" data-bdsel="${i}"><td><span class="bd-rd"></span></td>
        <td><b>${esc(X.nom)}</b><span class="s">${nf(X.q)} pcs · marge ${pct(X.m)}</span></td><td class="pl">+</td>
        <td><b>${esc(maj(nomCourt(Y.nom)))}</b><span class="s">marge ${pct(Y.m)}</span></td>
        <td class="n">${eur(k.separes)} → <b class="rb">${eur(c.prix)}</b><span class="s">−${eur(k.remise)} · −${pct(k.remisePct, 0)}</span></td>
        <td class="n"><s>${pct(X.m)}</s> → <b>${pct(k.margeBundlePct)}</b></td>
        <td class="n"><b class="ok">${sgn(k.gainTicket)} €</b></td>
        <td class="n"><b>${sgn(k.gainMois, 0)} €</b><span class="s">${nf(k.bundlesMois)} bundles</span></td>
        <td>${c.moment ? `<span class="bd-mo">${esc(c.moment)}</span>` : ''}</td></tr>`;
    }).join('');
    const c = couple(), X = prod(c.x), Y = prod(c.y), k = chiffrer(X, Y, c.prix);
    const B = d.base, ht2 = B.ventesHt + k.bundlesMois * (k.htB - X.ht) * d.periode.jours / 30, mg2 = B.marge + k.bundlesMois * k.gainTicket * d.periode.jours / 30;
    return tete('Les couples qui montent votre marge', '« Chaque couple part d’un produit qui attire mais rapporte peu, et lui ajoute un produit à grande valeur ajoutée. La remise est petite, la marge du ticket monte. Choisissez le couple à lancer. »')
      + `<div class="bd-tab"><table><thead><tr><th></th><th>Le produit qui attire</th><th></th><th>+ le produit qui rapporte</th><th class="n">Séparés → bundle</th><th class="n">Marge du ticket</th><th class="n">En plus / ticket</th><th class="n">Par mois*</th><th>Le moment</th></tr></thead><tbody>${lignes}</tbody></table></div>
      <div class="bd-bilan"><div><span class="bd-t" style="margin:0">${esc(X.nom)} + ${esc(nomCourt(Y.nom).toLowerCase())}, si ${esc(PRISES.find(p => p[0] === st.prise)[1])} le prend</span>
        <p><b>${sgn(k.gainMois, 0)} € de marge par mois</b> · votre marge de base passe de <b>${pct(B.base)}</b> à <b>${pct(100 * mg2 / ht2, 2)}</b> · ${nf(k.bundlesMois)} bundles par mois, environ ${nf(k.bundlesMois / 30, 0)} par jour</p></div>
        <label class="bd-reg">Prise <select data-bdprise="1">${PRISES.map(([v, l]) => `<option value="${v}"${v === st.prise ? ' selected' : ''}>${l}</option>`).join('')}</select></label></div>
      <p class="bd-note">* si ${esc(PRISES.find(p => p[0] === st.prise)[1])} qui achète le produit qui attire prend le bundle. « En plus / ticket » : la marge du bundle moins celle du produit qui attire vendu seul, pour le client qui ne prenait que lui ; un client qui prenait déjà les deux paie la remise.</p>
      ${pied('<button type="button" class="bd-btn go" data-bdphase="3">Régler le prix : ' + esc(X.nom) + ' + ' + esc(nomCourt(Y.nom)) + ' ›</button><button type="button" class="bd-btn" data-bdphase="1">‹ Le constat</button>', cs.length + ' couple' + (cs.length > 1 ? 's' : '') + ' proposés')}`;
  }
  function phase3() {
    const c = couple(), X = prod(c.x), Y = prod(c.y), k = chiffrer(X, Y, st.prix), B = st.d.base;
    return tete('Le prix du bundle : ' + X.nom + ' + ' + nomCourt(Y.nom), `« Séparés, ils font ${eur(k.separes)}. À ${eur(st.prix)}, le client gagne ${eur(k.remise)} ; chaque ${X.nom.toLowerCase()} vendu en bundle laisse ${eur(k.margeBundle)} de marge au lieu de ${eur(X.me)}. Cliquez une colonne pour changer le prix. »`)
      + `<div class="bd-c3"><div><p class="bd-t">La marge que laisse un bundle, selon son prix</p><div class="bd-graphe" data-bdgraphe="prix"></div>
          <p class="bd-leg"><span><i style="background:${C.x}"></i>la remise conseillée : ${st.d.regles.remiseConseil[0]} à ${st.d.regles.remiseConseil[1]} %</span><span><i style="background:#d9cfc4"></i>possible</span><span><i style="background:#e9b9a6"></i>plus de ${st.d.regles.remiseTrop} % : trop de remise</span><span><i class="tir"></i>la marge du produit vendu seul</span></p></div>
        <div class="bd-choix"><p class="bd-t">À ${eur(st.prix)}</p><div class="bd-tuiles">
          ${tuile('Le client gagne', eur(k.remise), '−' + pct(k.remisePct) + ' sur ' + eur(k.separes))}${tuile('Marge du bundle', eur(k.margeBundle) + ' <small>HT</small>', pct(k.margeBundlePct) + ' · le ' + esc(X.nom.toLowerCase()) + ' seul : ' + pct(X.m))}
          ${tuile('Marge en plus', sgn(k.gainTicket) + ' €', 'par client qui prend le bundle au lieu du ' + esc(X.nom.toLowerCase()) + ' seul', 'ok')}${tuile('Par mois, ' + PRISES.find(p => p[0] === st.prise)[1], sgn(k.gainMois, 0) + ' €', nf(k.bundlesMois) + ' bundles · ' + nf(k.bundlesMois / 30, 0) + ' par jour')}</div>
          <ul class="bd-pq"><li><b>Garde-fou</b> : ${k.margeBundlePct >= B.base ? 'la marge du bundle reste au-dessus de votre marge de base (' + pct(B.base) + ').' : 'à ce prix, la marge du bundle (' + pct(k.margeBundlePct) + ') passe sous votre marge de base (' + pct(B.base) + ') : elle monte quand même celle du ' + esc(X.nom.toLowerCase()) + '.'}</li>
          <li><b>Le prix rond</b> : un prix en dizaines de centimes se dit en une phrase et se rend sans petite monnaie.</li>
          <li><b>À surveiller</b> : le ${esc(nomCourt(Y.nom).toLowerCase())} se vend ${nf(Y.q)} fois en ${st.d.periode.jours} jours ; le bilan dira combien de bundles il a fallu pour le faire monter.</li></ul></div></div>
      ${pied('<button type="button" class="bd-btn go" data-bdphase="4">Préparer la fiche du bundle ›</button><button type="button" class="bd-btn" data-bdphase="2">‹ Les couples</button>', 'Ensuite : la fiche pré-encodée, puis le lancement')}`;
  }
  function phase4() {
    const c = couple(), X = prod(c.x), Y = prod(c.y), k = chiffrer(X, Y, st.prix), d = st.d, [du, au] = dates(st.duree), E = d.equipe;
    const gagner = k.margeSep / k.margeBundle - 1;
    const envoiPossible = E.boite && E.n > 0;
    const envoi = !E.boite ? `<span class="bd-ko">La boîte mail du magasin n’est pas reliée : la promotion sera créée, la fiche se téléchargera.</span> <button type="button" class="bd-lien" data-bd="boite">Relier la boîte ›</button>`
      : (E.n === 0 ? `<span class="bd-ko">Aucune adresse d’équipe : la fiche ne part à personne.</span> <button type="button" class="bd-lien" data-bd="parametres">Ajouter l’équipe (Paramètres › Mails et automatisations) ›</button>`
        : `<label class="bd-coche"><input type="checkbox" data-bdenvoyer="1"${st.envoyer ? ' checked' : ''}> <b>La fiche de la promotion (PDF) part par e-mail à l’équipe</b> : ${E.n} personne${E.n > 1 ? 's' : ''}, depuis la boîte du magasin.</label>`);
    return tete('La fiche du bundle, déjà encodée', `« Je l’ai préparée avec vos prix et vos coûts de recette. Vérifiez le nom, les canaux et la durée : à la validation, elle passe en caisse ${jour(du)} et l’équipe reçoit sa fiche. »`)
      + `<div class="bd-c4"><div class="bd-fiche">
        <div class="bd-l4"><span>Nom</span><input class="bd-in bd-nom" data-bdnom="1" maxlength="80" value="${esc(st.nom)}"></div>
        <div class="bd-l4"><span>Le client achète</span><b>1 × ${esc(X.nom)} + 1 × ${esc(nomCourt(Y.nom))}<span class="s">les deux produits sur le même ticket · répétable</span></b></div>
        <div class="bd-l4"><span>Il paie</span><b><span class="bd-prix">${eur(st.prix)}</span><span class="bd-barre">${eur(k.separes)}</span><span class="bd-gain">il gagne ${eur(k.remise)} · −${pct(k.remisePct, 0)}</span></b></div>
        <div class="bd-l4"><span>Où</span><div class="bd-seg">${Object.entries(d.regles.canaux).map(([v, l]) => `<button type="button" class="${st.canal === v ? 'on' : ''}" data-bdcanal="${v}">${esc({ tous: 'Comptoir et webshop', comptoir: 'Comptoir', webshop: 'Webshop' }[v] || l)}</button>`).join('')}</div></div>
        <div class="bd-l4"><span>Quand</span><b>du ${esc(jour(du))} au ${esc(jour(au, true))}<span class="bd-dur"><select class="bd-in" data-bdduree="1">${Object.entries(d.regles.durees).map(([v, l]) => `<option value="${v}"${v === st.duree ? ' selected' : ''}>${esc(l)}${v === '1m' ? ' · la durée standard' : ''}</option>`).join('')}</select></span><span class="s">toujours à partir du lundi qui suit la validation</span></b></div>
        <div class="bd-l4"><span>En caisse</span><b>La remise s’applique toute seule quand le ${esc(X.nom.toLowerCase())} et le ${esc(nomCourt(Y.nom).toLowerCase())} sont sur le même ticket.</b></div></div>
        <aside class="bd-cote"><p class="bd-t">Ce qu’elle vous laisse</p><div class="bd-tuiles">
          ${tuile('Marge du bundle', eur(k.margeBundle) + ' <small>HT</small>', pct(k.margeBundlePct) + ' du prix hors TVA')}${tuile('Coefficient', '×' + nf(k.coef, 2), 'prix payé HT ÷ coût de recette', k.coef >= 2.5 ? 'ok' : '')}
          ${tuile('Pour garder votre marge', sgn(100 * gagner, 0) + ' % de bundles', 'vendus séparément, les deux laissent ' + eur(k.margeSep) + ' HT : chaque bundle en laisse ' + eur(k.margeSep - k.margeBundle) + ' de moins, à rattraper par le nombre', 'large')}</div></aside></div>
      <div class="bd-envoi"><div><span class="bd-n">1</span><span>À la validation, <b>la promotion est créée au panel</b> : en caisse <b>${esc(jour(du))} à l’ouverture</b>, jusqu’au ${esc(jour(au))} au soir.</span></div>
        <div><span class="bd-n">2</span><span>${envoi} <button type="button" class="bd-lien" data-bd="apercu">Voir la fiche</button></span></div></div>
      ${pied(`<button type="button" class="bd-btn go" data-bd="lancer"${st.occupe || !st.nom.trim() ? ' disabled' : ''}>${st.occupe ? 'Création au panel…' : '✓ Valider et lancer ' + jour(du).split(' ')[0] + ' ' + jm(du)}</button><button type="button" class="bd-btn" data-bdphase="3">‹ Le prix</button>`, envoiPossible && st.envoyer ? 'la fiche partira à ' + E.n + ' personne' + (E.n > 1 ? 's' : '') : '')}`;
  }
  function phase5() {
    const R = st.res, b = R && R.bundle;
    if (!b) { return tete('Le lancement', '') + '<p class="bd-vide">Rien n’a été lancé.</p>'; }
    const env = b.envoi, ok = env && !env.erreur;
    const pt = (fait, n) => `<span class="bd-pt ${fait ? 'fait' : 'att'}">${fait ? '✓' : n}</span>`;
    return `<header class="bd-ok-h"><span class="bd-coche-g"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
        <div><h2>« ${esc(b.nom)} » est lancé</h2><p>1 ${esc(b.xNom.toLowerCase())} + 1 ${esc(nomCourt(b.yNom).toLowerCase())} pour ${eur(b.prix)} · en caisse du <b>${esc(jour(new Date(b.du + 'T12:00:00')))} à l’ouverture</b> au ${esc(jour(new Date(b.au + 'T12:00:00')))} au soir</p></div><img class="bd-av" src="avatars/vente.png" alt="Coach Vente"></header>
      ${R.panelErreur ? `<div class="bd-flash ko">Le panel l’a créée sans l’activer : ${esc(R.panelErreur)}. Elle est restée en brouillon au panel.</div>` : ''}
      <div class="bd-deux"><div><p class="bd-t">Où en est la promotion</p><ul class="bd-tl">
          <li>${pt(true)}Validée et créée au panel${b.panelId ? ' (n° ' + b.panelId + ')' : ''}<span>${esc(jmS(b.le))} ${esc(String(b.le).slice(11, 16))}</span></li>
          <li>${pt(!!ok, '·')}${ok ? 'Fiche envoyée à l’équipe' : 'Fiche pas envoyée'}<span>${ok ? esc(String(env.le).slice(11, 16)) : ''}</span></li>
          <li>${pt(false, 2)}En caisse<span>${esc(jour(new Date(b.du + 'T12:00:00')))}</span></li>
          <li>${pt(false, 3)}Fin de la promotion<span>${esc(jour(new Date(b.au + 'T12:00:00')))} au soir</span></li></ul>
          <p class="mu" style="font-size:12px;margin:10px 0 0">Ses utilisations se lisent dans « Où les promotions sont utilisées », au-dessus, dès qu’elle passe en caisse.</p></div>
        <div><p class="bd-t">La fiche de l’équipe</p>
          ${env && env.erreur ? `<p class="bd-ko">${esc(env.erreur)}</p>` : ''}
          ${env && env.a && env.a.length ? `<ul class="bd-gens">${env.a.map(x => `<li>${esc(x.nom)}<span>${esc(x.adresse)}</span><em>✓ envoyé</em></li>`).join('')}</ul>` : (!env ? '<p class="mu">Pas d’envoi demandé.</p>' : '')}
          ${env && env.avertissement ? `<p class="mu">${esc(env.avertissement)}</p>` : ''}
          <div class="bd-ligne"><button type="button" class="bd-btn" data-bdfiche="${b.id}">⤓ Télécharger la fiche (PDF)</button><button type="button" class="bd-btn" data-bdrenvoyer="${b.id}"${st.occupe ? ' disabled' : ''}>${env ? 'Renvoyer' : 'Envoyer'} à l’équipe</button></div></div></div>
      ${pied('<button type="button" class="bd-btn go" data-bd="autre">Créer un autre bundle</button>', 'Une erreur ? La promotion se modifie au cockpit (Promotions › Édition) ou par une demande au bureau.')}`;
  }
  function lances() {
    const L = (st.d && st.d.lances) || [];
    if (!L.length) { return ''; }
    const stt = { a_venir: ['att', 'à venir'], en_caisse: ['ok', 'en caisse'], terminee: ['', 'terminée'] };
    return `<section class="db-card bd-suivi"><table><thead><tr><th>Lancés avec le Coach Vente</th><th>Statut</th><th>Période</th><th>Le client paie</th><th>Fiche</th><th></th></tr></thead><tbody>
      ${L.map(b => `<tr><td><b>${esc(b.nom)}</b><span class="s">1 ${esc(b.xNom.toLowerCase())} + 1 ${esc(nomCourt(b.yNom).toLowerCase())}</span></td><td><span class="bd-st ${stt[b.statut][0]}">${stt[b.statut][1]}</span></td>
        <td>${esc(jmS(b.du))} → ${esc(jmS(b.au))}/${esc(String(b.au).slice(0, 4))}</td><td>${eur(b.prix)} au lieu de ${eur(b.separes)}</td>
        <td>${b.envoi ? (b.envoi.erreur ? '<span class="bd-ko">pas envoyée</span>' : 'envoyée à ' + b.envoi.a.length + ' personne' + (b.envoi.a.length > 1 ? 's' : '')) : '<span class="mu">—</span>'}</td>
        <td><button type="button" class="bd-lien" data-bdfiche="${b.id}">PDF</button></td></tr>`).join('')}</tbody></table></section>`;
  }
  function html() {
    if (st.err && !st.d) { return `<div class="db-err">Les bundles du Coach Vente : ${esc(st.err)}</div>`; }
    if (!st.d) { return '<div class="db-card"><div class="db-sk" style="height:420px"></div></div>'; }
    const fl = st.flash ? `<div class="bd-flash ${st.flash.ok ? 'ok' : 'ko'}">${esc(st.flash.texte)}<button type="button" class="bd-x" data-bd="flash" aria-label="fermer">✕</button></div>` : '';
    if (st.phase > 1 && st.phase < 5 && !couple()) { st.phase = 1; }
    const corps = [phase1, phase2, phase3, phase4, phase5][st.phase - 1]();
    return `${fl}<section class="db-card bd">${corps}</section>${lances()}`;
  }
  function dessiner() {
    st.el.querySelectorAll('[data-bdgraphe]').forEach(el => {
      const w = Math.max(320, Math.floor(el.clientWidth));
      if (el.dataset.bdgraphe === 'nuage') { el.innerHTML = nuage(w); }
      else { const c = couple(); if (c) { el.innerHTML = colonnes(w, prod(c.x), prod(c.y)); } }
    });
  }
  function rendre() {
    if (!st.el) { return; }
    const a = document.activeElement, nom = a && st.el.contains(a) && a.dataset.bdnom, pos = nom ? a.selectionStart : null;
    st.el.innerHTML = html();
    dessiner();
    brancher();
    if (nom) { const i = st.el.querySelector('[data-bdnom]'); if (i) { i.focus(); try { i.setSelectionRange(pos, pos); } catch (e) { /* sans curseur */ } } }
  }
  function corps() { const c = couple(); return { x: c.x, y: c.y, prix: st.prix, nom: st.nom.trim(), duree: st.duree, canal: st.canal }; }
  function lancer() {
    st.occupe = true; rendre();
    const E = st.d.equipe;
    poster('/assistant/magasin/bundles/lancer', Object.assign(corps(), { envoyer: st.envoyer && E.boite && E.n > 0 })).then(repondre)
      .then(r => { st.res = r; st.phase = 5; st.flash = null; return lire().then(d => { st.d = d; }).catch(() => {}); })
      .catch(e => flash(false, 'La promotion n’est pas créée : ' + e.message)).finally(() => { st.occupe = false; rendre(); });
  }
  function renvoyer(id) {
    st.occupe = true; rendre();
    poster('/assistant/magasin/bundles/renvoyer', { id }).then(repondre).then(r => {
      if (st.res && st.res.bundle && st.res.bundle.id === r.bundle.id) { st.res.bundle = r.bundle; }
      flash(!r.bundle.envoi.erreur, r.bundle.envoi.erreur || 'Fiche renvoyée à ' + r.bundle.envoi.a.length + ' personne' + (r.bundle.envoi.a.length > 1 ? 's' : '') + '.');
      return lire().then(d => { st.d = d; });
    }).catch(e => flash(false, e.message)).finally(() => { st.occupe = false; rendre(); });
  }
  function brancher() {
    const on = (sel, ev, fn) => st.el.querySelectorAll(sel).forEach(x => x.addEventListener(ev, fn));
    on('[data-bdphase]', 'click', e => { st.phase = +e.currentTarget.dataset.bdphase; st.flash = null; rendre(); st.el.scrollIntoView({ block: 'start', behavior: 'smooth' }); });
    on('[data-bdsel]', 'click', e => { choisir(+e.currentTarget.dataset.bdsel); rendre(); });
    on('[data-bdprise]', 'change', e => { st.prise = +e.currentTarget.value; rendre(); });
    on('[data-bdprix]', 'click', e => { st.prix = +e.currentTarget.dataset.bdprix; rendre(); });
    on('[data-bdnom]', 'input', e => { st.nom = e.currentTarget.value; const b = st.el.querySelector('[data-bd="lancer"]'); if (b) { b.disabled = st.occupe || !st.nom.trim(); } });
    on('[data-bdcanal]', 'click', e => { st.canal = e.currentTarget.dataset.bdcanal; rendre(); });
    on('[data-bdduree]', 'change', e => { st.duree = e.currentTarget.value; rendre(); });
    on('[data-bdenvoyer]', 'change', e => { st.envoyer = e.currentTarget.checked; rendre(); });
    on('[data-bdfiche]', 'click', e => ouvrirPdf(fetch(st.api + '/assistant/magasin/bundles/fiche?shop=' + encodeURIComponent(st.shop) + '&id=' + e.currentTarget.dataset.bdfiche, { credentials: 'same-origin' })));
    on('[data-bdrenvoyer]', 'click', e => renvoyer(+e.currentTarget.dataset.bdrenvoyer));
    on('[data-bd]', 'click', e => {
      const a = e.currentTarget.dataset.bd;
      if (a === 'lancer') { lancer(); }
      else if (a === 'apercu') { ouvrirPdf(poster('/assistant/magasin/bundles/apercu', corps())); }
      else if (a === 'coach' && st.coach) { st.coach(); }
      else if (a === 'parametres' && st.aller) { st.aller('mails'); }
      else if (a === 'boite' && st.aller) { st.aller('boite'); }
      else if (a === 'autre') { st.res = null; st.phase = 2; rendre(); }
      else if (a === 'flash') { st.flash = null; rendre(); }
    });
  }
  // Les graphiques se redessinent à la largeur de leur cadre quand la fenêtre change.
  let tRedim = null;
  window.addEventListener('resize', () => { clearTimeout(tRedim); tRedim = setTimeout(() => { if (st.el && st.el.isConnected && st.d) { rendre(); } }, 200); });

  window.DashBundles = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      if (o.aller) { st.aller = o.aller; }
      if (o.coach) { st.coach = o.coach; }
      if (!st.el || String(st.shop) !== String(shop)) {
        st.shop = String(shop); st.el = document.createElement('div'); st.el.className = 'bd-racine';
        Object.assign(st, { d: null, err: null, phase: 1, sel: 0, prix: null, nom: '', res: null, flash: null });
        rendre();
        charger();
      }
      return st.el;
    },
    relire() { if (st.el) { charger(); } },
  };
})();
