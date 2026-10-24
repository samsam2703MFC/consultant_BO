/* Captures de la maquette « Suivi des franchisés ».
   Serveur statique à la racine du dépôt : php -S 127.0.0.1:8099 -t .
   puis : node docs/maquettes/suivi-franchises/capturer.cjs
   La page est écrite sans <!doctype> (format Artifact) : on l'enveloppe ici, comme à la publication. */
const fs = require('fs');
let pw;
try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const BASE = 'http://127.0.0.1:8099/docs/maquettes/suivi-franchises/';
const page = fs.readFileSync(__dirname + '/index.html', 'utf8');
const doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"></head><body>' + page + '</body></html>';
const VUES = [
  ['a-tableau-de-bord', m => m.montrer('tableau')],
  ['b-preparer', m => { m.montrer('tableau'); m.onglet('preparer'); }],
  ['c-adapter-envoyer', m => { m.montrer('tableau'); m.onglet('adapter'); }],
  ['d-points-corrections', m => { m.montrer('tableau'); m.onglet('points'); }],
  ['e-tablette', m => { m.montrer('tablette'); m.aller(3); }],
  ['f-tablette-fin', m => { m.montrer('tablette'); m.aller(8); }],
  ['g-client-mystere', m => m.montrer('msp')],
  ['h-page-franchise', m => m.montrer('franchise')],
  ['i-qui-fait-quoi', m => m.montrer('admin')]
];
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const [larg, haut, suffixe, liste] of [[1600, 1000, '', VUES], [390, 844, '-tel', VUES.filter(v => /^(a|e|h)-/.test(v[0]))]]) {
    const ctx = await b.newContext({ viewport: { width: larg, height: haut }, deviceScaleFactor: larg < 500 ? 2 : 1.25 });
    for (const [nom, faire] of liste) {
      const p = await ctx.newPage();
      const err = [];
      p.on('pageerror', e => err.push(e.message));
      p.on('requestfailed', r => { if (!/\/fonts\//.test(r.url())) { err.push('échec ' + r.url()); } });
      // La page enveloppée est servie à une adresse fictive du même dossier : les chemins relatifs suivent.
      await p.route(BASE + '__apercu.html', r => r.fulfill({ contentType: 'text/html; charset=utf-8', body: doc }));
      await p.goto(BASE + '__apercu.html', { waitUntil: 'networkidle' });
      await p.evaluate('document.fonts.ready');
      await p.evaluate(f => { eval('(' + f + ')')(window.maquette); }, faire.toString());
      await p.waitForTimeout(300);
      const large = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
      await p.screenshot({ path: __dirname + '/' + nom + suffixe + '.png', fullPage: true });
      console.log(nom + suffixe, '·', await p.evaluate(() => document.body.scrollHeight), 'px', large ? '· DÉBORDE EN LARGEUR' : '', err.length ? '· ' + err.join(' | ') : '');
      await p.close();
    }
    await ctx.close();
  }
  await b.close();
})();
