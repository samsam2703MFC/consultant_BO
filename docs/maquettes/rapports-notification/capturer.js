/* Captures des quatre variantes, sur les écrans réels (bureau 1440 px) :
 *   node docs/maquettes/rapports-notification/capturer.js
 * Le script sert lui-même le dépôt sur 127.0.0.1:8099 et simule l'API (donnees.js) ; les
 * variantes (variantes.js) sont greffées dans la page une fois l'écran rendu. */
const path = require('path');
const B = require('./banc');
const V = require('./variantes');

const ICI = __dirname;
const DASH = `http://127.0.0.1:${B.PORT}/public/dashboard/?shop=4&vue=ops&date=2026-10-06`;
const COCKPIT = `http://127.0.0.1:${B.PORT}/public/#/reporting`;

async function pret(p) {
  await p.waitForLoadState('networkidle');
  await p.evaluate(() => document.fonts.ready);
  await p.waitForTimeout(900);
}
async function greffer(p, css) {
  await p.addStyleTag({ content: css });
  await p.addScriptTag({ content: V.prelude() });
}
const boite = (p, sel) => p.evaluate(s => { const r = document.querySelector(s).getBoundingClientRect(); return { x: r.x, y: r.y + scrollY, w: r.width, h: r.height }; }, sel);

async function dashboard(b, v, mes) {
  const p = await B.page(b, 1440, 950);
  await p.goto(DASH);
  await pret(p);
  await greffer(p, V.CSS_DASH);
  const sel = await p.evaluate(k => window.V_DASH[k](RAPPORT), v);
  await p.waitForTimeout(300);
  const f = path.join(ICI, v.toLowerCase() + '-dashboard.png');
  if (v === 'D') {
    // Le tiroir ouvert, puis la cloche seule (le tiroir la recouvre).
    await p.screenshot({ path: f });
    await p.evaluate(() => { document.querySelector('.rn-voile').remove(); document.querySelector('.rn-tiroir').remove(); });
    const r = await boite(p, '.op-mnt');
    await p.screenshot({ path: path.join(ICI, 'd-dashboard-ferme.png'), fullPage: true, clip: { x: 0, y: 0, width: 1440, height: Math.ceil(r.y + r.h + 18) } });
  } else {
    // A : le bandeau et ce qui le suit jusqu'aux tuiles du moment ; B et C : jusqu'à la carte.
    const r = await boite(p, v === 'A' ? '.op-mnt' : sel);
    await p.screenshot({ path: f, fullPage: true, clip: { x: 0, y: 0, width: 1440, height: Math.ceil(r.y + r.h + 18) } });
  }
  mes[v + '-dashboard'] = p.erreurs;
  await p.context().close();
}

async function cockpit(b, v, mes) {
  const p = await B.page(b, 1440, 2300, true);
  await p.goto(COCKPIT);
  await pret(p);
  if (v !== 'B') {
    // Le compositeur, en modification du rapport franchisé (un envoi par magasin).
    await p.locator('button', { hasText: 'Modifier' }).first().click();
    await p.waitForTimeout(700);
  }
  await greffer(p, V.CSS_COCKPIT);
  await p.evaluate(k => window.V_COCKPIT[k](window.MAGS), v);
  await p.waitForTimeout(300);
  const r = await p.evaluate(liste => {
    if (liste) {
      const fin = [...document.querySelectorAll('div')].find(d => /^Dernières générations/.test(d.textContent.trim()) && d.children.length > 1);
      const c = fin.closest('div[style*="border-radius"]') || fin.parentElement;
      const b2 = c.getBoundingClientRect();
      return { x: 0, y: 0, w: 1440, h: Math.min(b2.bottom + 24, 1100) };
    }
    const t = [...document.querySelectorAll('div')].find(d => /^5 · Générer, envoyer, ou enregistrer/.test(d.textContent.trim()) && d.children.length === 1);
    const c = t.parentElement.getBoundingClientRect();
    return { x: 236, y: c.top - 16, w: 1440 - 236, h: c.height + 32 };
  }, v === 'B');
  await p.screenshot({ path: path.join(ICI, v.toLowerCase() + '-reporting.png'), clip: { x: r.x, y: r.y, width: r.w, height: r.h } });
  mes[v + '-reporting'] = p.erreurs;
  await p.context().close();
}

(async () => {
  const s = await B.serveur();
  const b = await B.navigateur();
  const mes = {};
  try {
    for (const v of ['A', 'B', 'C', 'D']) {
      await cockpit(b, v, mes);
      await dashboard(b, v, mes);
    }
    // La planche : les quatre variantes côte à côte.
    const p = await b.newPage({ viewport: { width: 2400, height: 1400 } });
    await p.goto(`http://127.0.0.1:${B.PORT}/docs/maquettes/rapports-notification/planche.html`);
    await pret(p);
    await p.screenshot({ path: path.join(ICI, 'planche.jpg'), fullPage: true, type: 'jpeg', quality: 82 });
  } finally {
    console.log('erreurs', JSON.stringify(mes));
    await b.close();
    s.close();
  }
})();
