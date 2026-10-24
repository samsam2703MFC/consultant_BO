/* Exporte chaque vue de l'écran Fidélité en HTML autonome, pour l'itérer dans Claude Design (24/10/2026) :
 * programme.html, programme-edition.html (une règle ouverte, la barre de publication), clients.html.
 * Le HTML est celui que le cockpit rend ; les styles sont intégrés (jetons du design system + fidelite.css).
 *
 *   le cockpit en local sur 8080 (README), puis : node docs/maquettes/fidelite/claude-design/exporter.js */
const fs = require('fs'), path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const RACINE = path.resolve(__dirname, '../../../..'), OUT = __dirname + '/';
const css = fs.readFileSync(RACINE + '/public/assets/css/fidelite.css', 'utf8');
const ds = fs.readFileSync(RACINE + '/public/assets/ds/global.css', 'utf8');
const racine = (ds.match(/:root\s*\{[\s\S]*?\n\}/) || [''])[0];
const nettoie = h => h.replace(/\s(data-[hci]|data-scroll|data-en|data-ds|data-dp)="[^"]*"/g, '');
const page = (titre, vue, note, entete, ecran) => `<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fidélité — ${titre}</title>
<!--
  Cockpit L'Atelier by — ERP franchisé › Fidélité (#/fidelity), vue « ${vue} ».
  Export statique de l'écran codé (24/10/2026) : le HTML tel que le cockpit le rend, les styles intégrés
  (jetons du design system + fidelite.css). Les chiffres clients sont un jeu d'essai.
  ${note}
  Polices : celles du design system (Gotham, Vank) ; à défaut, la pile système prend le relais.
  Les classes fi-* sont celles du code (public/assets/css/fidelite.css) : les garder facilite le retour.
-->
<style>
/* --- Jetons du design system L'Atelier by (public/assets/ds/global.css) --- */
${racine}
/* Sans les fichiers de polices, une pile de secours sans empattement plutôt que le Times du navigateur. */
:root{--font-ui:'Gotham',-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif;--font-display:'Vank','Gotham',-apple-system,'Helvetica Neue',Arial,sans-serif}
html,body{margin:0}
body{background:var(--color-bg);font-family:var(--font-ui),'Helvetica Neue',Arial,sans-serif;color:var(--color-text);-webkit-font-smoothing:antialiased}
.page{max-width:1240px;margin:0 auto;padding:30px 32px 60px}
.page>header{margin-bottom:22px}
.page>header h1{font-family:var(--font-display),Georgia,serif;font-size:30px;font-weight:400;margin:0;line-height:1.15}
.page>header p{font-size:13px;color:var(--color-text-muted);margin:6px 0 0;max-width:640px;line-height:1.5}
input,select,button{font:inherit}
/* --- Écran Fidélité (public/assets/css/fidelite.css) --- */
${css}
</style>
</head>
<body>
<main class="page">
${entete}
${ecran}
</main>
</body>
</html>
`;
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const p = await b.newPage({ viewport: { width: 1440, height: 900 }, locale: 'fr-BE' });
  const err = []; p.on('pageerror', e => err.push(e.message));
  await p.goto('http://127.0.0.1:8080/#/fidelity', { waitUntil: 'load' }); await p.waitForTimeout(3500);
  const entete = await p.evaluate(() => { const h = [...document.querySelectorAll('h1')].find(x => /Fidélité/.test(x.textContent)); const t = h ? h.parentElement.querySelector('p') : null;
    return `<header><h1>Fidélité</h1><p>${t ? t.textContent.trim() : ''}</p></header>`; });
  const ecran = () => p.evaluate(() => document.querySelector('[data-screen="fidelite"]').outerHTML);
  const vues = [];
  vues.push(['programme', 'Programme', 'Programme', 'Toutes les règles fermées. Une ligne = une règle (titre court, résumé, statistique sur 30 jours, interrupteur).', await ecran()]);
  await p.locator('.fi-row-main', { hasText: 'du mar au jeu' }).click(); await p.waitForTimeout(300);
  await p.locator('.fi-edit .fi-in.h').first().fill('15h'); await p.locator('.fi-edit .fi-in.h').first().press('Tab'); await p.waitForTimeout(400);
  vues.push(['programme-edition', 'Programme — une règle ouverte', 'Programme · édition', 'Le créneau ×2 du mardi au jeudi est ouvert et son heure de début modifiée (15:00) : la phrase aux valeurs modifiables, la portée, « Retirer la règle » / « OK », et la barre flottante de publication en bas.', await ecran()]);
  await p.locator('.fi-dock .fi-lien').click(); await p.waitForTimeout(300);
  await p.getByRole('button', { name: /^Clients$/ }).click(); await p.waitForTimeout(3000);
  vues.push(['clients', 'Clients', 'Clients', 'Segment « À risque » ouvert. En haut les cinq chiffres ; la grille récence × fréquence (une case ouvre son segment) et le détail du segment ; d’un mois à l’autre ; ce qu’achète chaque segment.', await ecran()]);
  for (const [fic, titre, vue, note, html] of vues) {
    const s = page(titre, vue, note, entete, nettoie(html));
    fs.writeFileSync(OUT + fic + '.html', s);
    console.log(fic + '.html', (s.length / 1024).toFixed(0) + ' Ko');
  }
  // Contrôle : chaque fichier s'ouvre seul, sans erreur.
  for (const [fic] of vues) {
    const q = await b.newPage({ viewport: { width: 1300, height: 900 }, deviceScaleFactor: 1 });
    q.on('pageerror', e => err.push(fic + ' : ' + e.message));
    await q.goto('file://' + OUT + fic + '.html'); await q.waitForTimeout(400);
    await q.close();
  }
  console.log('erreurs :', err.join(' | ') || 'aucune');
  await b.close();
})();
