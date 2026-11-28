/* Les captures du vrai écran (code-particuliers.png, code-entreprises.png), avec la fausse API des vidéos
 * (panel/tools/videos/mocks/18_fidelite.js, données fictives). Le site assemblé doit être servi sur 127.0.0.1:8099 :
 *   php shared/bin/assembler_public.php /tmp/site && php -S 127.0.0.1:8099 -t /tmp/site &
 *   node panel/docs/maquettes/fidelite-particuliers-entreprises/capturer-code.js */
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const path = require('path');
const { nouveauContexte } = require('../../../tools/videos/mock');
(async () => {
  const b = await pw.chromium.launch({ args: ['--no-proxy-server'] });
  const { ctx, nonServis } = await nouveauContexte(b, { viewport: { width: 1440, height: 900 }, dsf: 2 });
  const p = await ctx.newPage();
  const err = []; p.on('pageerror', e => err.push(e.message));
  for (const [nom, pub] of [['code-particuliers', 'p'], ['code-entreprises', 'e']]) {
    await p.goto('http://127.0.0.1:' + (process.env.FILM_PORT || 8099) + '/dashboard/?shop=4&vue=fidelite&pub=' + pub);
    await p.waitForTimeout(2500);
    await p.evaluate(() => document.querySelectorAll('.tu-annonce').forEach(x => x.remove()));   // l'annonce d'une vidéo nouvelle
    await p.screenshot({ path: path.join(__dirname, nom + '.png'), fullPage: true }); console.log('✓', nom);
  }
  if (nonServis.size) { console.log('non servis :', [...nonServis].join(', ')); }
  console.log('erreurs', err.length ? err : 'aucune'); await b.close();
})();
