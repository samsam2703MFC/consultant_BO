/* Maquette « Le chiffre face au budget » de l'Opérationnel : le mois, le trimestre et l'année, chacun face à son budget,
 * sous les jauges du jour. Photographiée sur la page en ligne du dashboard (lecture seule : tout appel autre que GET est
 * intercepté), avec dashboard.js et dashboard.css de ce dépôt ; chiffres réels de Halle (shop 4).
 * Régénérer : node docs/maquettes/budget-periodes/generer.js */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const OUT = __dirname + '/', PUB = path.join(__dirname, '../../../public/dashboard/');
const URL = 'http://185.180.206.46/consulant_bo/dashboard/?shop=4&vue=ops';
async function page(b, vp, date) {
  const c = await b.newContext({ viewport: vp, deviceScaleFactor: 2, timezoneId: 'Europe/Brussels' });
  await c.route('**/*', r => {
    const u = r.request().url();
    if (r.request().method() !== 'GET') { return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
    const m = /\/dashboard\/(dashboard\.js|dashboard\.css)(\?|$)/.exec(u);
    if (m) { return r.fulfill({ status: 200, contentType: m[1].endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(PUB + m[1]) }); }
    return r.continue();
  });
  const p = await c.newPage(); p.on('pageerror', e => console.log('pageerror:', e.message));
  await p.goto(URL + (date ? '&date=' + date : ''), { waitUntil: 'load' });
  await p.waitForFunction(() => document.querySelector('.op-bud .op-bp'), null, { timeout: 240000 });
  await p.waitForTimeout(2500);
  return p;
}
(async () => {
  const b = await chromium.launch();
  let p = await page(b, { width: 1348, height: 1000 }, null);
  const carte = await p.$('.op-bud'); await carte.screenshot({ path: OUT + 'a-carte.png' });
  console.log(await p.$eval('.op-bud', e => e.innerText.replace(/\s+/g, ' ')));
  // La carte à sa place : sous les jauges du jour, au-dessus de l'heure par heure.
  const y = await p.evaluate(() => { const d = document.querySelector('.op-duel'); return d ? d.getBoundingClientRect().top + scrollY - 20 : 0; });
  await p.setViewportSize({ width: 1348, height: 1250 }); await p.evaluate(v => scrollTo(0, v), y); await p.waitForTimeout(400);
  await p.screenshot({ path: OUT + 'b-a-sa-place.png' });
  await p.context().close();
  // Une journée passée : le jour compte entier.
  p = await page(b, { width: 1348, height: 1000 }, '2026-10-31');
  await (await p.$('.op-bud')).screenshot({ path: OUT + 'c-journee-passee-31-10.png' });
  console.log(await p.$eval('.op-bud', e => e.innerText.replace(/\s+/g, ' ')));
  await p.context().close();
  // Au téléphone.
  p = await page(b, { width: 390, height: 844 }, null);
  await (await p.$('.op-bud')).screenshot({ path: OUT + 'd-telephone.png' });
  await p.context().close();
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
