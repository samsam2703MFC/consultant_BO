/* node capture.js <vue|''> [sortie.png] [--plein] [--clic "texte"]... [--w 1440 --h 900]
 * Ouvre /dashboard/?shop=4&vue=<vue> avec la fausse API, attend, capture, liste les chemins non servis et les erreurs JS. */
const { chromium } = require('playwright');
const { DEMO, nouveauContexte } = require('./mock');
(async () => {
  const a = process.argv.slice(2);
  const vue = a[0] || '';
  const sortie = (a[1] && !a[1].startsWith('--')) ? a[1] : require('path').join(__dirname, 'shots', (vue || 'ops') + '.png');
  require('fs').mkdirSync(require('path').dirname(sortie), { recursive: true });
  const plein = a.includes('--plein');
  const opt = k => { const i = a.indexOf(k); return i >= 0 ? a[i + 1] : null; };
  const clics = []; a.forEach((x, i) => { if (x === '--clic') clics.push(a[i + 1]); });
  const b = await chromium.launch({ args: ['--no-proxy-server'] });
  const { ctx, nonServis } = await nouveauContexte(b, { viewport: { width: +(opt('--w') || 1440), height: +(opt('--h') || 900) } });
  const p = await ctx.newPage();
  p.on('pageerror', e => console.log('ERREUR JS', e.message));
  p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) console.log('CONSOLE', m.text()); });
  await p.goto('http://127.0.0.1:' + (process.env.FILM_PORT || 8099) + '/dashboard/?shop=' + DEMO.shop + (vue ? '&vue=' + vue : ''));
  await p.waitForTimeout(2000);
  for (const c of clics) { await p.getByText(c, { exact: false }).first().click(); await p.waitForTimeout(1200); }
  await p.screenshot({ path: sortie, fullPage: plein });
  console.log('capture :', sortie);
  if (nonServis.size) console.log('NON SERVIS :\n  ' + [...nonServis].join('\n  '));
  await b.close();
})();
