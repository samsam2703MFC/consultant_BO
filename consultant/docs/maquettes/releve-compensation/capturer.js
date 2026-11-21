/* Captures de la maquette « Relevé de compensation » (fichiers locaux, sans serveur).
 *   node consultant/docs/maquettes/releve-compensation/generer.js
 *   node consultant/docs/maquettes/releve-compensation/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
(async () => {
  const b = await pw.chromium.launch();
  const err = []; const mes = {};
  const prendre = async (nom, largeur, sortie, opts = {}) => {
    const p = await b.newPage({ viewport: { width: largeur, height: 1000 }, deviceScaleFactor: 2 });
    p.on('pageerror', e => err.push(nom + ' : ' + e.message));
    await p.goto('file://' + path.join(__dirname, nom + '.html'), { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready);
    mes[nom] = await p.evaluate(() => ({
      largeur: document.documentElement.scrollWidth, hauteur: document.documentElement.scrollHeight,
      // une page A4 qui déborde : son contenu dépasse le pied
      debordePages: [...document.querySelectorAll('.doc__page')].map(pg => {
        const pied = pg.querySelector('.doc__illu').getBoundingClientRect().top;
        const der = [...pg.children].filter(c => !c.classList.contains('doc__foot') && !c.classList.contains('doc__illu')).pop();
        return Math.round(pied - der.getBoundingClientRect().bottom);
      }),
      polices: [...document.fonts].filter(f => f.status === 'loaded').length,
    }));
    await p.screenshot({ path: path.join(__dirname, sortie), fullPage: true, ...opts });
    await p.close();
  };
  for (const v of ['a', 'b', 'c']) {
    await prendre(v, 1724, v + '.png');
    await prendre(v + '-nl', 1724, v + '-nl.png');
  }
  await prendre('planche', 2558, 'planche.jpg', { type: 'jpeg', quality: 82 });
  console.log(JSON.stringify(mes, null, 1), 'erreurs', err.length ? err : 'aucune');
  await b.close();
})();
