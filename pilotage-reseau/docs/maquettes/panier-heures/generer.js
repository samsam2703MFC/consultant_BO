/* Maquette « Heure par heure » : la bascule Panier (le panier moyen de chaque heure, aujourd'hui face à J−7).
 * Photographiée sur la page en ligne du dashboard (lecture seule : tout appel autre que GET est intercepté), avec
 * dashboard.js et dashboard.css de ce dépôt à la place des fichiers servis — la capture montre le code de la branche.
 * Régénérer : node docs/maquettes/panier-heures/generer.js */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const OUT = __dirname + '/', PUB = path.join(__dirname, '../../../public/dashboard/');
const BASE = 'http://185.180.206.46/consulant_bo/dashboard/?shop=4&vue=ops&date=';
const titre = () => [...document.querySelectorAll('.db-card')].find(e => { const t = e.querySelector('.op-h2'); return t && /heure par heure/i.test(t.innerText); });
async function page(b, date, vp, local) {
  const c = await b.newContext({ viewport: vp, deviceScaleFactor: 2 });
  await c.route('**/*', r => {
    const u = r.request().url();
    if (r.request().method() !== 'GET') { return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
    if (local && /\/dashboard\/dashboard\.js(\?|$)/.test(u)) { return r.fulfill({ status: 200, contentType: 'application/javascript', body: fs.readFileSync(PUB + 'dashboard.js') }); }
    if (local && /\/dashboard\/dashboard\.css(\?|$)/.test(u)) { return r.fulfill({ status: 200, contentType: 'text/css', body: fs.readFileSync(PUB + 'dashboard.css') }); }
    return r.continue();
  });
  const p = await c.newPage();
  await p.goto(BASE + date, { waitUntil: 'load' });
  await p.waitForFunction(() => [...document.querySelectorAll('.op-h2')].some(e => /heure par heure/i.test(e.innerText)) && document.querySelector('[data-opcrb]'), null, { timeout: 240000 });
  await p.waitForTimeout(2500);
  return p;
}
async function carte(p, fichier) {
  const el = (await p.evaluateHandle(titre)).asElement();
  await el.scrollIntoViewIfNeeded();
  await el.screenshot({ path: OUT + fichier });
  return el;
}
(async () => {
  const b = await chromium.launch();
  const bureau = { width: 1348, height: 900 }, tel = { width: 390, height: 844 };
  // Avant : la carte en ligne, CA.
  let p = await page(b, '2026-11-01', bureau, false);
  await carte(p, 'avant.png'); await p.context().close();
  // A : aujourd'hui, en cours de journée — la bascule sur Panier.
  p = await page(b, '2026-11-01', bureau, true);
  await p.click('[data-opcrb="pan"]'); await p.waitForTimeout(600);
  const el = await carte(p, 'a-aujourdhui.png');
  fs.writeFileSync(OUT + 'a.html', '<!-- La carte « Heure par heure » en mode Panier, Halle (shop 4), 01/11/2026 -->\n' + await el.evaluate(e => e.outerHTML));
  console.log('légende :', await p.$eval('.op-dleg', e => e.innerText.replace(/\s+/g, ' ')));
  await p.context().close();
  // B : une journée terminée (samedi 31/10), la courbe entière.
  p = await page(b, '2026-10-31', bureau, true);
  await p.click('[data-opcrb="pan"]'); await p.waitForTimeout(600);
  await carte(p, 'b-journee-terminee.png');
  console.log('légende B :', await p.$eval('.op-dleg', e => e.innerText.replace(/\s+/g, ' ')));
  await p.context().close();
  // Téléphone : la même bascule, sans les valeurs sur les points.
  p = await page(b, '2026-11-01', tel, true);
  await p.click('[data-opcrb="pan"]'); await p.waitForTimeout(600);
  await carte(p, 'c-telephone.png'); await p.context().close();
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
