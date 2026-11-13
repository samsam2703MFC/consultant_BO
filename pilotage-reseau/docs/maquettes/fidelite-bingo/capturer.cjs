/* Captures de l'écran Fidélité tel que codé — le cockpit réel (public/), l'API de la fidélité sur le faux ERP.
 *   php -S 127.0.0.1:8098 -t public docs/maquettes/fidelite-bingo/capture_api.php     (depuis pilotage-reseau/)
 *   node docs/maquettes/fidelite-bingo/capturer.cjs */
const path = require('path');
const { chromium } = require('playwright');
const BASE = process.env.MQ_BASE || 'http://127.0.0.1:8098/';
const D = __dirname;

(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--lang=fr-BE'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 950 }, deviceScaleFactor: 2, locale: 'fr-BE', timezoneId: 'Europe/Brussels' });
  const p = await ctx.newPage();
  const err = []; p.on('pageerror', e => err.push(e.message));
  const attendre = async sel => { await p.waitForSelector(sel, { timeout: 60000 }); await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(400); };
  // Le cockpit défile dans son propre cadre : la fenêtre prend la hauteur de l'écran Fidélité, puis on capture.
  const plein = async nom => {
    await p.setViewportSize({ width: 1440, height: 950 });
    const h = await p.evaluate(() => { const f = document.querySelector('.fi'); const r = f.getBoundingClientRect(); let e = f.parentElement, haut = r.top; while (e) { haut += e.scrollTop || 0; e = e.parentElement; } return Math.ceil(haut + f.scrollHeight + 40); });
    await p.setViewportSize({ width: 1440, height: Math.max(950, h) });
    await p.waitForTimeout(500);
    await p.screenshot({ path: path.join(D, nom) });
    console.log('capture', nom, h);
  };

  await p.goto(BASE + 'index.html#/fidelity', { waitUntil: 'networkidle' });
  await attendre('.fi-carte');
  // Le programme, avec un brouillon : Sombreffe ×1,5, un palier de plus ; puis un ticket simulé.
  const coefs = p.locator('.fi-r:has-text("Par magasin") input');
  await coefs.nth(3).fill('1,5'); await coefs.nth(3).dispatchEvent('change');
  await p.waitForTimeout(200);
  await p.locator('.fi-add:has-text("Ajouter un palier")').click();
  await p.waitForTimeout(200);
  await p.locator('.fi-card:has-text("Simuler") input[type=datetime-local]').fill('2027-01-14T15:10');
  await p.locator('.fi-card:has-text("Simuler") input[type=datetime-local]').dispatchEvent('change');
  await p.locator('.fi-card:has-text("Simuler") button:has-text("Simuler")').click();
  await attendre('.fi-kpi');
  await plein('code-programme.png');
  // Une autre carte de l'année : le printemps (thèmes à valider, campagnes en brouillon).
  await p.locator('.fi-sa:has-text("Printemps")').click();
  await p.waitForFunction(() => /carte de bingo · printemps/i.test(document.body.innerText), null, { timeout: 60000 });
  await attendre('.fi-sem-g');
  await plein('code-programme-printemps.png');
  await p.locator('.fi-sa:has-text("Hiver")').click();
  await p.waitForFunction(() => /carte de bingo · hiver/i.test(document.body.innerText), null, { timeout: 60000 });

  // Les clients : le réseau, puis les bons dus.
  await p.locator('.fi-segc button:has-text("Clients")').click();
  await attendre('.fi-tuiles');
  await p.locator('button:has-text("Voir les bons dus")').click();
  await attendre('.fi-card:has-text("Les bons du bingo") .fi-kv');
  await plein('code-clients.png');
  // Un magasin.
  await p.locator('.fi-segc.p button:has-text("Corbais")').click();
  await p.waitForFunction(() => document.querySelector('.fi-grp h3') && /Corbais/.test(document.body.innerText));
  await attendre('.fi-tuiles');
  await plein('code-clients-corbais.png');
  // La page de test à part (public/fidelite-test) : l'admin, le magasin test Halle, la carte d'un client.
  await p.setViewportSize({ width: 1280, height: 900 });
  await p.goto(BASE + 'fidelite-test/index.html', { waitUntil: 'networkidle' });
  await p.waitForSelector('#admin-c .req', { timeout: 120000 });
  await p.waitForSelector('#mag-c tr[data-id]', { timeout: 180000 });
  await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(400);
  await p.screenshot({ path: path.join(D, 'code-page-test-admin.png'), fullPage: true, clip: await p.evaluate(() => { const r = document.getElementById('admin').getBoundingClientRect(); return { x: 0, y: r.top + scrollY - 120, width: 1280, height: r.height + 140 }; }) });
  await p.locator('#mag-c button[data-filtre="bien"]').click(); await p.waitForTimeout(300);
  await p.screenshot({ path: path.join(D, 'code-page-test-halle.png'), fullPage: true, clip: await p.evaluate(() => { const r = document.getElementById('magasin').getBoundingClientRect(); return { x: 0, y: r.top + scrollY - 10, width: 1280, height: r.height + 20 }; }) });
  // Un client qui a des cases et des semaines manquées : le filtre « la moitié et plus », le premier.
  await p.locator('#mag-c button[data-filtre="bien"]').click();
  await p.locator('#mag-c tr[data-id]').first().click();
  await p.waitForSelector('#cl-c .tel', { timeout: 180000 });
  await p.waitForTimeout(600);
  await p.screenshot({ path: path.join(D, 'code-page-test-carte.png'), fullPage: true, clip: await p.evaluate(() => { const r = document.getElementById('carte').getBoundingClientRect(); return { x: 0, y: r.top + scrollY - 10, width: 1280, height: r.height + 20 }; }) });
  console.log('captures de la page de test : admin, halle, carte');
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
