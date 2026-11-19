/* Captures des maquettes « Fil du franchisé ».
 *   php -S 127.0.0.1:8099 -t .   (depuis pilotage-reseau/)  ;   node docs/maquettes/fil-franchise/capturer.js */
const path = require('path');
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
  for (const m of ['a', 'b', 'c']) {
    const p = await ctx.newPage();
    const err = [];
    p.on('pageerror', e => err.push(e.message));
    p.on('requestfailed', r => err.push('échec ' + r.url()));
    await p.goto('http://127.0.0.1:8099/docs/maquettes/fil-franchise/' + m + '.html', { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready);
    await p.addStyleTag({ content: 'html,body,#app,.shell,.workspace,.workspace__main,.app__body{height:auto!important;max-height:none!important;overflow:visible!important}' });
    await p.waitForTimeout(300);
    await p.screenshot({ path: path.join(__dirname, m + '.png'), fullPage: true });
    console.log(m, '·', await p.evaluate(() => document.body.scrollHeight), 'px', err.length ? '· ERREURS ' + err.join(' | ') : '');
    await p.close();
  }
  await b.close();
})();
