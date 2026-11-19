/* Maquette « Ventes par catégorie » : pourcentages entiers, colonne Coef (CA ÷ coût matière) à côté du taux.
 * Construite sur la page en ligne du dashboard (lecture seule), le DOM transformé dans le navigateur. */
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const fs = require('fs');
const OUT = __dirname + '/';
const URL = 'http://185.180.206.46/consulant_bo/dashboard/?shop=3&vue=jour&date=2026-10-02';
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const c = await b.newContext({ viewport: { width: 1348, height: 900 }, deviceScaleFactor: 2 });
  await c.route('**/*', r => r.request().method() !== 'GET' ? r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }) : r.continue());
  const p = await c.newPage();
  await p.goto(URL, { waitUntil: 'load' });
  await p.waitForFunction(() => [...document.querySelectorAll('.db-ent')].some(e => /taux/i.test(e.innerText)), null, { timeout: 300000 });
  await p.waitForTimeout(800);
  const carte = await p.evaluateHandle(() => [...document.querySelectorAll('.db-card')].find(e => /ventes par catégorie/i.test(e.innerText)));
  await carte.asElement().screenshot({ path: OUT + 'avant.jpg', type: 'jpeg', quality: 86 });
  // La transformation : % entiers, colonne Coef, grille élargie d'une colonne.
  const transforme = () => p.evaluate(() => {
    const carte = [...document.querySelectorAll('.db-card')].find(e => /ventes par catégorie/i.test(e.innerText));
    const acc = carte.querySelector('.db-acc');
    const cols = getComputedStyle(acc.querySelector('.db-ent')).gridTemplateColumns;
    if (!document.getElementById('mq-coef')) {
      const st = document.createElement('style'); st.id = 'mq-coef';
      st.textContent = `.db-acc .db-ent, .db-acc .db-al { grid-template-columns: 22px minmax(150px,1.1fr) 3fr 76px 58px 84px 60px 66px !important; } .db-acc .n.coef { font-variant-numeric: tabular-nums; } .db-acc .n.coef b { font-weight: 700 } .db-acc .n.coef small { font-weight: 500; opacity: .6; margin-right: 2px }`;
      document.head.appendChild(st);
    }
    const ent = acc.querySelector('.db-ent');
    if (!ent.querySelector('.coef')) { const s = document.createElement('span'); s.className = 'n coef'; s.textContent = 'Coef'; ent.appendChild(s); }
    const entier = el => { const m = /^(-?\d+)(?:,(\d+))?\s?%$/.exec(el.textContent.trim()); if (m) { el.textContent = Math.round(parseFloat(m[1] + '.' + (m[2] || '0'))) + ' %'; } };
    acc.querySelectorAll('.db-al').forEach(row => {
      const sp = [...row.children];
      const part = sp[4], marge = sp[5], taux = sp[6];
      const t = /^(-?\d+)(?:,(\d+))?\s?%$/.exec((taux.textContent || '').trim());
      const tauxVal = t ? parseFloat(t[1] + '.' + (t[2] || '0')) : null;
      entier(part); entier(taux);
      if (row.querySelector('.coef')) { return; }
      const s = document.createElement('span'); s.className = 'n coef ' + (taux.className.replace(/\bn\b/, '').trim());
      if (tauxVal != null && tauxVal < 100) { s.innerHTML = '<small>×</small><b>' + (1 / (1 - tauxVal / 100)).toFixed(2).replace('.', ',') + '</b>'; }
      else { s.innerHTML = marge.querySelector('.mu') ? '<span class="mu" title="coût matière inconnu">?</span>' : ''; }
      row.appendChild(s);
    });
    const mini = carte.querySelector('.ct .db-mini');
    if (mini && !/coef/.test(mini.innerText)) { mini.innerHTML = mini.innerHTML.replace(/CA\s*[−-]\s*coût matière/, 'CA − coût matière') + ' · coef : CA ÷ coût matière'; }
    return cols;
  });
  console.log('colonnes :', await transforme());
  await carte.asElement().screenshot({ path: OUT + 'a.jpg', type: 'jpeg', quality: 88 });
  console.log('lignes :', await p.$$eval('.db-acc .db-al', l => l.map(r => r.innerText.replace(/\s+/g, ' ').slice(0, 110))));
  // Le premier groupe ouvert : le coef à chaque niveau.
  // Viennoiserie ouvert, puis sa première catégorie : le coef à chaque niveau. Le clic redessine la carte : on la retrouve.
  const carteDe = () => p.evaluateHandle(() => [...document.querySelectorAll('.db-card')].find(e => /ventes par catégorie/i.test(e.innerText)));
  await p.click('.db-acc .db-al.g[data-cacc="g:Viennoiserie"]'); await p.waitForTimeout(600); await transforme();
  const kc = await p.$('.db-acc .db-al.c[data-cacc]'); if (kc) { await kc.click(); await p.waitForTimeout(600); await transforme(); }
  const c2 = await carteDe();
  await c2.asElement().screenshot({ path: OUT + 'a-ouvert.jpg', type: 'jpeg', quality: 88 });
  fs.writeFileSync(OUT + 'a.html', '<!-- DOM de la carte transformée, dashboard en ligne, Gosselies 02/10/2026 -->\n' + await c2.asElement().evaluate(e => e.outerHTML));
  console.log('ouvert :', await p.$$eval('.db-acc .db-al', l => l.length), 'lignes');
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
