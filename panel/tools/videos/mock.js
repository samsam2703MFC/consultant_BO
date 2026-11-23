/* Fausse API du cockpit pour les vidéos : chaque module de mocks/ exporte
 *   module.exports = function (m, path, q, corps) { return objet | undefined }
 * (m = méthode, path = chemin sans /api/cockpit ni query, q = URLSearchParams, corps = JSON envoyé ou null).
 * Le premier module qui rend autre chose que undefined répond. Sinon : {} et le chemin est noté « non servi ».
 * Date et heure figées : DEMO.now (jeudi 19/11/2026, 15 h 40, Europe/Brussels). */
const path = require('path');
const fs = require('fs');
const DEMO = { now: '2026-11-19T15:40:00+01:00', date: '2026-11-19', shop: '4' };
function modules() {
  const dir = path.join(__dirname, 'mocks');
  return fs.readdirSync(dir).filter(f => f.endsWith('.js')).sort().map(f => {
    const p = path.join(dir, f); delete require.cache[require.resolve(p)]; return require(p);
  });
}
async function installer(ctx, opts = {}) {
  const mods = modules();
  const nonServis = new Set();
  await ctx.route('**/api/cockpit/**', async r => {
    const req = r.request();
    const u = new URL(req.url());
    const p = u.pathname.replace(/.*\/api\/cockpit/, '');
    let corps = null; try { corps = req.postDataJSON(); } catch (e) { corps = req.postData(); }
    for (const mod of mods) {
      let rep; try { rep = mod(req.method(), p, u.searchParams, corps); } catch (e) { console.log('MOCK ERR', p, e.message); }
      if (rep !== undefined) {
        if (rep && rep.__status) { return r.fulfill({ status: rep.__status, json: rep.json || {} }); }
        if (rep && rep.__body) { return r.fulfill({ status: 200, contentType: rep.type, body: rep.__body }); }
        return r.fulfill({ json: rep });
      }
    }
    nonServis.add(req.method() + ' ' + p + u.search);
    return r.fulfill({ json: {} });
  });
  return nonServis;
}
async function nouveauContexte(browser, opts = {}) {
  const ctx = await browser.newContext({
    viewport: opts.viewport || { width: 1440, height: 900 }, deviceScaleFactor: opts.dsf || 1,
    locale: 'fr-BE', timezoneId: 'Europe/Brussels', ...(opts.ctx || {}),
  });
  await ctx.clock.setFixedTime(new Date(DEMO.now));
  const nonServis = await installer(ctx, opts);
  return { ctx, nonServis };
}
module.exports = { DEMO, installer, nouveauContexte };
