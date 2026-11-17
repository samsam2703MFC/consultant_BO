/* Captures de la maquette « La promotion proposée par le Coach Vente » (17/11/2026).
   A et B : l'onglet Promotions EN LIGNE (Halle), la liste des promotions remplacée par a-proposition.html / b-validee.html.
   C : la fiche envoyée à l'équipe, servie en local : php -S 127.0.0.1:8099 -t pilotage-reseau (depuis la racine du dépôt)
   puis : node pilotage-reseau/docs/maquettes/promo-coach/capturer.cjs   (SEUL=a-proposition pour un seul écran) */
const fs = require('fs');
const { chromium } = require('playwright');
const EN_LIGNE = 'http://185.180.206.46/consulant_bo/dashboard/?shop=4&vue=promos';
const css = fs.readFileSync(__dirname + '/maquette.css', 'utf8');
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1.25 });
  for (const nom of ['p1-constat', 'p2-couples', 'p3-prix', 'a-proposition', 'b-validee']) {
    if (process.env.SEUL && process.env.SEUL !== nom) { continue; }
    const p = await ctx.newPage(); const err = [];
    p.on('pageerror', e => err.push(e.message));
    await p.route('**/garde.js*', r => r.fulfill({ contentType: 'application/javascript', body: '' }));
    await p.goto(EN_LIGNE, { waitUntil: 'networkidle', timeout: 180000 }).catch(() => {});
    await p.waitForSelector('.pm-liste', { timeout: 60000 });
    await p.waitForTimeout(1500);
    await p.evaluate(([html, css]) => {
      const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
      const l = document.querySelector('.pm-liste'); const d = document.createElement('div'); d.innerHTML = html; l.replaceWith(...d.childNodes);
      const t = document.querySelector('.pm-lecture');
      if (t) { t.textContent = 'Le Coach Vente prépare vos promotions avec vos prix et vos coûts : vous validez, elle passe en caisse le lundi suivant et l’équipe reçoit sa fiche.'; }
      // Les phases du bundle se lisent sans la carte « Où les promotions sont utilisées » au-dessus : elle est repliée.
      if (document.getElementById('pc') && document.querySelector('.bn')) { const cn = document.querySelector('.cn-card'); if (cn) { cn.style.display = 'none'; } }
    }, [fs.readFileSync(__dirname + '/' + nom + '.html', 'utf8'), css]);
    await p.evaluate('document.fonts.ready');
    await p.waitForTimeout(800);
    // Le rail est fixe : la fenêtre prend toute la hauteur de la page pour qu'il descende jusqu'en bas.
    const H = await p.evaluate(() => document.documentElement.scrollHeight);
    await p.setViewportSize({ width: 1440, height: H }); await p.waitForTimeout(500);
    await p.screenshot({ path: __dirname + '/' + nom + '.png' });
    console.log(nom, H, 'px', err.join(' | '));
    await p.close();
  }
  if (!process.env.SEUL || process.env.SEUL === 'c-equipe') {
    const p = await ctx.newPage();
    await p.setViewportSize({ width: 1500, height: 1000 });
    await p.goto('http://127.0.0.1:8099/docs/maquettes/promo-coach/c-equipe.html', { waitUntil: 'networkidle' });
    await p.evaluate('document.fonts.ready');
    await p.screenshot({ path: __dirname + '/c-equipe.png', fullPage: true });
    console.log('c-equipe', await p.evaluate(() => document.documentElement.scrollHeight), 'px');
    await p.close();
  }
  await b.close();
})();
