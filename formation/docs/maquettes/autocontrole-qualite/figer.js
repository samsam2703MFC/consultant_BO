/* Relève la fiche produit réelle de l'app Cuisine pour les maquettes : contexte/fiche-corps.html (le DOM) et
 * contexte/kitchen.css (les règles CSS qui touchent un élément de la page). À relancer quand la fiche change.
 *
 * Il faut l'app Cuisine (dépôt ThaiZu/TFB-Kitchen, `composer install`) et le bouchon de ce dossier :
 *   KITCHEN_DIR=/chemin/TFB-Kitchen php -S 127.0.0.1:8081 contexte/bouchon.php
 *   cd /chemin/TFB-Kitchen && KITCHEN_API_BASE=http://127.0.0.1:8081 JWT_SECRET=dev JWT_ISSUER=dev \
 *     JWT_ACCESS_TOKEN_EXPIRY=3600 JWT_REFRESH_TOKEN_EXPIRY=86400 DEFAULT_LANGUAGE=fr DEFAULT_COUNTRY=BE \
 *     CURRENCY=EUR CURRENCY_SYMBOL=€ APP_NAME=Cuisine APP_DESC=Cuisine \
 *     php -d variables_order=EGPCS -S 127.0.0.1:8010 -t public tools/serve.php
 *   node formation/docs/maquettes/autocontrole-qualite/figer.js [http://127.0.0.1:8010]
 * Le bouchon accepte n'importe quel identifiant.
 */
const fs = require('fs'), path = require('path');
const { chromium } = require('playwright');

const APP = (process.argv[2] || 'http://127.0.0.1:8010').replace(/\/$/, '');
const CTX = path.join(__dirname, 'contexte');
const POLICES = '../../../../../shared/public/assets/ds/fonts/'; // vu depuis contexte/

(async () => {
  const b = await chromium.launch();
  const p = await (await b.newContext({ viewport: { width: 820, height: 1180 } })).newPage();
  await p.goto(APP + '/auth');
  await p.selectOption('select[name="shop_id"]', '1');
  await p.fill('input[name="login"]', 'tablette');
  await p.fill('input[name="password"]', '1234');
  await p.click('button[type="submit"]');
  await p.waitForTimeout(2500);
  await p.goto(APP + '/knowledge/products/1700400');
  await p.waitForTimeout(1500);

  const { css, corps } = await p.evaluate(() => {
    // Les fenêtres cachées de l'app (choix de l'employé, de l'atelier) ne servent pas aux maquettes.
    document.querySelectorAll('.modal, .offcanvas, .modal-backdrop, script, noscript').forEach(n => n.remove());
    const PSEUDO = /::?(hover|focus|focus-visible|focus-within|active|visited|before|after|placeholder|-webkit-[a-z-]+|-moz-[a-z-]+|disabled|checked|not\([^)]*\)|first-child|last-child|first-of-type|last-of-type|nth-child\([^)]*\)|nth-of-type\([^)]*\)|marker|selection|empty)/g;
    const sert = sel => sel.replace(PSEUDO, '').split(',').some(x => { try { return !!document.querySelector(x.trim() || '*'); } catch (e) { return false; } });
    const lire = regles => {
      let out = '';
      for (const r of regles) {
        if (r instanceof CSSFontFaceRule || r instanceof CSSKeyframesRule) out += r.cssText + '\n';
        else if (r instanceof CSSStyleRule) { if (/^(:root|html|body|\*)/.test(r.selectorText) || sert(r.selectorText)) out += r.cssText + '\n'; }
        else if (r instanceof CSSMediaRule || r instanceof CSSSupportsRule) {
          const dedans = lire(r.cssRules);
          if (dedans) out += (r instanceof CSSMediaRule ? '@media ' : '@supports ') + r.conditionText + ' {\n' + dedans + '}\n';
        }
      }
      return out;
    };
    let css = '';
    for (const f of document.styleSheets) {
      const nom = (f.href || 'style de la page').replace(/^.*\/assets\//, '').replace(/\?.*$/, '');
      if (/bootstrap-icons/.test(nom)) continue; // les icônes viennent de panel/, même jeu de classes
      let regles; try { regles = f.cssRules; } catch (e) { continue; }
      const t = lire(regles);
      if (t) css += `/* ── ${nom} ── */\n${t}`;
    }
    return { css, corps: document.body.outerHTML };
  });
  await b.close();

  const tete = "/* CSS de l'app Cuisine (dépôt ThaiZu/TFB-Kitchen) : les règles qui touchent un élément de la fiche produit,\n"
    + '   relevées sur une instance locale par figer.js. Ne pas éditer à la main.\n'
    + '   Le trait 400 (Gotham_Book, absent du dépôt) est servi par Gotham_Medium, le trait normal du design system. */\n';
  fs.writeFileSync(path.join(CTX, 'kitchen.css'), tete + css
    .replace(/url\("?[^")]*\/fonts\/Gotham_Book\.otf"?\)/g, `url("${POLICES}Gotham_Medium.otf")`)
    .replace(/url\("?[^")]*\/fonts\/([A-Za-z_]+\.(?:otf|ttf))"?\)/g, (m, f) => `url("${POLICES}${f}")`));

  const html = corps
    .replace(/src="[^"]*\/assets\/brand\/logo\.png"/g, 'src="../../../../shared/public/assets/img/logo.png"')
    .replace(/src="[^"]*\/photo\/cougnou\.jpg"/g, 'src="contexte/cougnou.jpg"')
    .replace(/href="https?:\/\/[^"]*"/g, 'href="#"')
    .replace(/data-root="[^"]*"/g, 'data-root=""')
    .replace(/\s(onclick|onerror)="[^"]*"/g, '');
  if (/127\.0\.0\.1|localhost/.test(html)) throw new Error('une adresse locale reste dans la fiche');
  fs.writeFileSync(path.join(CTX, 'fiche-corps.html'), html);
  console.log('✓ contexte/fiche-corps.html, contexte/kitchen.css');
})();
