/* Les captures de la maquette « la fiche d'une personne » (24/11/2026) : le vrai dashboard servi en local avec la
 * fausse API des vidéos (panel/tools/videos/mock.js : données FICTIVES, Magasin démo), la maquette posée par-dessus
 * (fiche.css, fiche.js). Aucune écriture, aucune donnée réelle.
 *   php shared/bin/assembler_public.php <site> && php -S 127.0.0.1:8099 -t <site>
 *   node panel/docs/maquettes/equipe-gestion/fiche-personne/capturer.cjs [dossier de sortie] */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const { DEMO, nouveauContexte } = require('../../../../tools/videos/mock');
const OUT = process.argv[2] || __dirname;
const CSS = fs.readFileSync(path.join(__dirname, 'fiche.css'), 'utf8'), JS = fs.readFileSync(path.join(__dirname, 'fiche.js'), 'utf8');
const ETATS = [['a-identite', 'a1-fenetre-identite-et-contrat'], ['a-evaluations', 'a2-fenetre-evaluations'], ['b-page', 'b-page-de-la-personne']];
(async () => {
  const b = await chromium.launch({ args: ['--no-proxy-server'] });
  for (const [etat, nom] of ETATS) {
    const { ctx } = await nouveauContexte(b, { viewport: { width: 1440, height: 900 }, dsf: 2 });
    const p = await ctx.newPage(), err = [];
    p.on('pageerror', e => err.push(e.message));
    await p.goto('http://127.0.0.1:' + (process.env.FILM_PORT || 8099) + '/dashboard/?shop=' + DEMO.shop + '&vue=equipe');
    await p.waitForTimeout(2500);
    const tard = p.getByText('Plus tard'); if (await tard.count()) { await tard.first().click(); await p.waitForTimeout(300); }
    // La personne choisie dans Responsabilités : Camille Roussel.
    const c = p.locator('[data-eqp-sel="201"]'); if (await c.count()) { await c.first().click(); await p.waitForTimeout(400); }
    await p.addStyleTag({ content: CSS }); await p.addScriptTag({ content: JS });
    await p.evaluate(x => window.FP.monter(x), etat);
    await p.waitForTimeout(400);
    if (etat.startsWith('a-')) {
      const h = await p.evaluate(() => document.querySelector('.fp-mod').scrollHeight);
      await p.setViewportSize({ width: 1440, height: Math.max(900, h + 72) });
      await p.waitForTimeout(300);
      await p.screenshot({ path: path.join(OUT, nom + '.png') });
    } else {
      // La fenêtre à la hauteur de la page : le rail (fixe) descend avec elle.
      const h = await p.evaluate(() => document.documentElement.scrollHeight);
      await p.setViewportSize({ width: 1440, height: h + 20 });
      await p.waitForTimeout(300);
      await p.screenshot({ path: path.join(OUT, nom + '.png') });
    }
    console.log(nom, err.length ? 'ERREURS ' + err.join(' | ') : 'ok');
    await ctx.close();
  }
  await b.close();
})();
