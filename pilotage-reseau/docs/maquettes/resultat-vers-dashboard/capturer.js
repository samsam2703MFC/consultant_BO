/* Résultat › Jour : cliquer un magasin mène à son dashboard — deux maquettes.
 * L'écran est le VRAI cockpit (public/index.html#/resultat) nourri par le jeu
 * d'essai de la capture du 27/10 (jour-2710.json, classement-2710.json) ; la
 * variante est posée par-dessus, dans le DOM, juste avant la capture.
 *   php -S 127.0.0.1:8099 -t .   (depuis la racine du dépôt)
 *   node docs/maquettes/resultat-vers-dashboard/capturer.js */
const fs = require('fs'), path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const ICI = __dirname;
const DASH = 'https://185.180.206.46/consulant_bo/dashboard/?shop=';

/* --- posé dans la page : styles, repères numérotés, curseur, barre d'état --- */
function outils(){
  const st = document.createElement('style');
  st.textContent = `
    .mq-note{background:#FBEFE0;border:1px dashed #D9B489;border-radius:12px;padding:12px 16px;font-size:12.5px;line-height:1.55;color:var(--color-text)}
    .mq-note .tag{display:inline-block;background:var(--color-primary);color:#fff;font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;border-radius:999px;padding:2px 9px;margin-right:8px;vertical-align:1px}
    .mq-note b.t{font-size:13.5px}
    .mq-note ol{margin:7px 0 0;padding:0;list-style:none;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 26px}
    .mq-note li{display:flex;gap:8px;align-items:flex-start}
    .mq-n{flex:none;display:inline-flex;align-items:center;justify-content:center;width:19px;height:19px;border-radius:50%;background:#1f5f8b;color:#fff;font-size:10.5px;font-weight:700;font-family:var(--font-ui);box-shadow:0 0 0 2px #fff}
    .mq-pos{position:absolute;z-index:500}
    .mq-curseur{position:absolute;z-index:501;width:22px;height:26px;pointer-events:none}
    .mq-etat{position:fixed;left:0;bottom:0;z-index:502;background:#f1f3f4;border:1px solid #c9ccd1;border-left:none;border-bottom:none;border-top-right-radius:6px;padding:3px 9px;font:12px/1.4 system-ui,-apple-system,sans-serif;color:#202124}
    .mq-pill{display:inline-flex;align-items:center;gap:5px;white-space:nowrap;border-radius:999px;font-family:var(--font-ui);font-size:11.5px;font-weight:600;padding:5px 11px}
  `;
  document.head.appendChild(st);
  window.mqRepere = (el, n, dx, dy) => {
    const r = el.getBoundingClientRect(), s = document.createElement('span');
    s.className = 'mq-n mq-pos'; s.textContent = n;
    s.style.left = (r.left + window.scrollX + dx) + 'px'; s.style.top = (r.top + window.scrollY + dy) + 'px';
    document.body.appendChild(s);
  };
  window.mqCurseur = (el, dx, dy) => {
    const r = el.getBoundingClientRect(), s = document.createElement('div');
    s.className = 'mq-curseur';
    s.style.left = (r.left + window.scrollX + dx) + 'px'; s.style.top = (r.top + window.scrollY + dy) + 'px';
    // La main du navigateur au survol d'un lien.
    s.innerHTML = '<svg viewBox="0 0 22 26" width="22" height="26"><path d="M7.5 1.5c1 0 1.8.8 1.8 1.8v8.2l.6-.1V9.6c0-1 .8-1.8 1.8-1.8s1.8.8 1.8 1.8v1.6h.5c0-.9.8-1.6 1.7-1.6 1 0 1.8.8 1.8 1.8v1.2c.3-.6.9-1 1.6-1 1 0 1.8.8 1.8 1.8v5.4c0 4-2.8 6.9-6.8 6.9h-2c-2.4 0-4.2-1-5.6-3l-3.4-5c-.6-.8-.4-1.9.4-2.4.8-.6 1.9-.4 2.5.4l1 1.4V3.3c0-1 .8-1.8 1.8-1.8z" fill="#fff" stroke="#111" stroke-width="1.2" stroke-linejoin="round"/></svg>';
    document.body.appendChild(s);
  };
  window.mqEtat = url => { const s = document.createElement('div'); s.className = 'mq-etat'; s.textContent = url; document.body.appendChild(s); };
  window.mqNote = (tag, titre, points) => {
    const ecran = document.querySelector('[data-screen="resultatJour"]');
    const d = document.createElement('div'); d.className = 'mq-note';
    d.innerHTML = '<span class="tag">' + tag + '</span><b class="t">' + titre + '</b><ol>'
      + points.map((p, i) => '<li><span class="mq-n">' + (i + 1) + '</span><span>' + p + '</span></li>').join('') + '</ol>';
    ecran.insertBefore(d, ecran.firstChild);
  };
  window.mqLignes = () => {
    const t = document.querySelector('[data-screen="resultatJour"] table');
    const rows = [...t.rows];
    return { t, entete: rows[0], mags: rows.slice(1, -1), reseau: rows[rows.length - 1] };
  };
}

/* --- Maquette A : toute la ligne est le lien ------------------------------ */
function varianteA(dash){
  mqNote('Maquette A', 'Toute la ligne ouvre le dashboard du magasin', [
    'Un clic n’importe où sur la ligne ouvre le <b>dashboard Opérationnel</b> du magasin, à la date regardée — dans le même onglet (Ctrl/⌘-clic ou clic molette : nouvel onglet).',
    'Au survol, la ligne s’éclaire, le nom se souligne et la <b>flèche ›</b> s’allume en bout de ligne ; la barre d’état montre l’adresse <code>dashboard/?shop=…&amp;vue=ops&amp;date=…</code>.',
    'Le compte de résultat qui se dépliait au clic sur la ligne passe sur le <b>petit bouton ▸</b> devant le nom.',
    'La mini-courbe des ventes par heure ouvre toujours sa fenêtre heure par heure : elle ne mène pas au dashboard.',
  ]);
  const L = mqLignes();
  // Les chiffres ne passent plus à la ligne : la colonne de la flèche prend sa place ailleurs.
  [...L.mags, L.reseau].forEach(tr => [...tr.cells].slice(2).forEach(td => { td.style.whiteSpace = 'nowrap'; }));
  const th = document.createElement('th'); th.style.cssText = 'width:34px;padding:0 0 7px'; L.entete.appendChild(th);
  const td0 = L.reseau.cells[1].cloneNode(); L.reseau.appendChild(td0);
  L.mags.forEach((tr, i) => {
    const survol = i === 0;
    const bord = tr.cells[0].style.borderTop;
    // Le ▸ devient un vrai petit bouton : c'est lui qui déplie le compte de résultat.
    const chev = tr.cells[0].querySelector('div span');
    chev.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;width:19px;height:19px;border:0.5px solid var(--color-border-secondary);border-radius:6px;background:var(--color-surface);font-size:8.5px;color:var(--color-text-muted);margin-right:8px;vertical-align:1px';
    const td = document.createElement('td');
    td.style.cssText = 'padding:9px 4px 9px 2px;text-align:center;border-top:' + (bord || '0.5px solid var(--color-border-tertiary)');
    td.innerHTML = survol
      ? '<span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:var(--color-primary);color:#fff;font-size:17px;line-height:1;padding-bottom:2px">›</span>'
      : '<span style="font-size:19px;color:var(--color-border-secondary)">›</span>';
    tr.appendChild(td);
    if (survol) {
      tr.style.background = 'var(--color-background-secondary)';
      tr.cells[0].style.boxShadow = 'inset 3px 0 0 var(--color-primary)';
      const nom = tr.cells[0].querySelector('div');
      nom.style.color = 'var(--color-primary)';
      [...nom.childNodes].filter(n => n.nodeType === 3 && n.textContent.trim()).forEach(n => {
        const u = document.createElement('span'); u.style.cssText = 'text-decoration:underline;text-underline-offset:3px'; u.textContent = n.textContent; n.replaceWith(u); });
    }
  });
  const corbais = L.mags[0];
  mqCurseur(corbais.cells[2], 14, 56);
  mqEtat(dash + corbais.dataset.shop + '&vue=ops&date=2026-10-27');
  mqRepere(corbais.cells[2], 1, -6, 6);
  const fl = corbais.cells[corbais.cells.length - 1];
  mqRepere(fl, 2, -14, 2);
  mqRepere(L.mags[2].cells[0], 3, -8, 8);
  const sp = L.mags[3].cells[1]; mqRepere(sp, 4, sp.getBoundingClientRect().width - 14, 4);
}

/* --- Maquette B : un lien Dashboard sous chaque nom, la ligne garde son détail --- */
function varianteB(dash){
  mqNote('Maquette B', 'Un lien « Dashboard » sous chaque nom — le clic sur la ligne garde le compte de résultat', [
    'Sous le nom de chaque magasin, un petit lien <b>Dashboard ↗</b> : il ouvre le dashboard Opérationnel du magasin à la date regardée, <b>dans un nouvel onglet</b> (comme « Dashboard magasin ↗ » du menu).',
    'Le clic sur le reste de la ligne ne change pas : il déplie le compte de résultat du magasin sous le tableau (Corbais ▾, en bas).',
    'Dans le compte de résultat déplié, le même lien en grand : <b>« Dashboard de Corbais ›»</b>.',
    'Rien ne bouge dans l’écran d’aujourd’hui, mais il faut viser le lien : la ligne seule ne mène pas au dashboard.',
  ]);
  const L = mqLignes();
  L.mags.forEach((tr, i) => {
    const survol = i === 1;
    const d = document.createElement('div');
    d.style.cssText = 'margin:5px 0 0 14px';
    d.innerHTML = survol
      ? '<span class="mq-lien" style="display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:600;padding:2px 9px;border-radius:999px;border:1px solid var(--color-primary);background:#F7E4E6;color:var(--color-primary)">Dashboard ↗</span>'
      : '<span class="mq-lien" style="display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:500;padding:2px 9px;border-radius:999px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted)">Dashboard ↗</span>';
    tr.cells[0].appendChild(d);
  });
  const goss = L.mags[1], lien = goss.cells[0].querySelector('.mq-lien');
  mqCurseur(lien, lien.getBoundingClientRect().width - 6, 10);
  mqEtat(dash + goss.dataset.shop + '&vue=ops&date=2026-10-27');
  mqRepere(lien, 1, lien.getBoundingClientRect().width + 6, -2);
  mqRepere(L.mags[0].cells[2], 2, -8, 8);
  // Le compte de résultat ouvert : le lien en grand, à côté du ×.
  const det = [...document.querySelectorAll('[data-screen="resultatJour"] > div')].find(d => /Compte de résultat du jour/.test(d.textContent) && d.querySelector('button[title="Fermer"]'));
  if (det) {
    const fermer = det.querySelector('button[title="Fermer"]');
    const b = document.createElement('span');
    b.className = 'mq-pill'; b.style.cssText = 'background:var(--color-primary);color:#fff;padding:8px 15px;font-size:12px;align-self:center';
    b.innerHTML = 'Dashboard de Corbais <span style="font-size:14px;line-height:1">›</span>';
    fermer.parentNode.insertBefore(b, fermer);
    mqRepere(b, 3, -10, -13);
  }
}

(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const [mq, fn, hauteur, ouvrir] of [['a', varianteA, 1010, false], ['b', varianteB, 1330, true]]) {
    const p = await b.newPage({ viewport: { width: 1536, height: hauteur }, deviceScaleFactor: 1 });
    const err = []; p.on('pageerror', e => err.push(e.message));
    await p.route(/\/exploitation\/jour/, r => r.fulfill({ status: 200, contentType: 'application/json', body: fs.readFileSync(path.join(ICI, 'jour-2710.json')) }));
    await p.route(/\/taches\/classement/, r => r.fulfill({ status: 200, contentType: 'application/json', body: fs.readFileSync(path.join(ICI, 'classement-2710.json')) }));
    await p.goto('http://127.0.0.1:8099/public/index.html#/resultat', { waitUntil: 'load' });
    await p.waitForSelector('[data-screen="resultatJour"] table');
    await p.waitForTimeout(2500);
    if (ouvrir) { await p.click('text=Corbais'); await p.waitForTimeout(1500); }
    await p.evaluate(() => document.fonts.ready);
    // Chaque ligne retient son shopId (le vrai code le prend dans rjLignes[i].id).
    await p.evaluate(ids => { const t = document.querySelector('[data-screen="resultatJour"] table'); [...t.rows].slice(1, -1).forEach((tr, i) => { tr.dataset.shop = ids[i]; }); },
      JSON.parse(fs.readFileSync(path.join(ICI, 'jour-2710.json'))).magasins.map(m => m.shopId));
    await p.evaluate(outils);
    await p.evaluate(fn, DASH);
    await p.waitForTimeout(300);
    await p.screenshot({ path: path.join(ICI, mq + '.png') });
    console.log(mq, 'erreurs', err.length ? err : 'aucune');
    await p.close();
  }
  await b.close();
})();
