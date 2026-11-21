// Captures de la maquette (Playwright du conteneur) : node capturer.js
// Sert la racine du dépôt le temps des captures (les polices sont dans shared/public/assets/ds/fonts).
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ICI = __dirname;
const RACINE = path.resolve(ICI, '../../../..');
const PORT = 8097;
const URL = (f) => `http://127.0.0.1:${PORT}/${path.relative(RACINE, path.join(ICI, f))}`;
const attendre = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const serveur = spawn('python3', ['-m', 'http.server', String(PORT), '--bind', '127.0.0.1', '--directory', RACINE], { stdio: 'ignore' });
  await attendre(800);
  const b = await chromium.launch();
  const temporaires = [];
  const cadre = (nom) => { temporaires.push(nom); return path.join(ICI, nom); };
  try {
    const ouvrir = async (fichier, largeur, hauteur) => {
      const p = await b.newPage({ viewport: { width: largeur, height: hauteur }, deviceScaleFactor: 2, hasTouch: largeur < 600 });
      await p.goto(URL(fichier), { waitUntil: 'networkidle' });
      await p.evaluate(() => document.fonts.ready);
      await p.addStyleTag({ content: 'html{scroll-behavior:auto}' }); // défilements instantanés pendant les captures
      await attendre(300);
      return p;
    };
    // Place le haut de la section des fiches juste sous la barre du haut.
    const versFiches = (p) => p.evaluate(() => {
      const y = document.getElementById('fiches').getBoundingClientRect().top + window.scrollY - document.querySelector('.nav').offsetHeight;
      window.scrollTo(0, y);
    });
    // Des images côte à côte, avec leur légende.
    const planche = async (images, legendes, sortie, largeur = 390, rayon = 26) => {
      const p = await b.newPage({ viewport: { width: 30 + images.length * (largeur + 30), height: 200 }, deviceScaleFactor: 2 });
      await p.setContent(`<html><body style="margin:0;background:#EAE4DC;font-family:system-ui;display:flex;align-items:flex-start;gap:30px;padding:30px 30px 20px">
        ${images.map((c, k) => `<figure style="margin:0;width:${largeur}px"><img src="${URL(c)}" style="width:${largeur}px;display:block;border-radius:${rayon}px;box-shadow:0 10px 30px rgba(35,34,32,.18)">
        <figcaption style="margin-top:14px;text-align:center;font-size:15px;color:#5d574f">${legendes[k]}</figcaption></figure>`).join('')}</body></html>`, { waitUntil: 'networkidle' });
      await p.screenshot({ path: path.join(ICI, sortie), fullPage: true });
      await p.close();
    };

    // Les deux tickets de caisse et leur QR : Corbais en français, Halle en néerlandais.
    for (const t of ['corbais', 'halle']) {
      const p = await ouvrir(`ticket-${t}.html`, 420, 900);
      await p.locator('.t').screenshot({ path: cadre(`_ticket-${t}.png`) });
      await p.close();
    }
    await planche(['_ticket-corbais.png', '_ticket-halle.png'], ['Corbais : ticket et page en français', 'Halle : ticket et page en néerlandais'], '0-tickets.png', 360, 0);

    // Téléphone, Corbais : le premier écran, la page entière, puis le geste de glisser en trois temps.
    let p = await ouvrir('corbais-fr.html', 390, 844);
    await p.screenshot({ path: path.join(ICI, '1-telephone-ecran.png') });
    await p.screenshot({ path: path.join(ICI, '2-telephone-page.png'), fullPage: true });
    await versFiches(p); await attendre(300);
    await p.screenshot({ path: cadre('_glisser-0.png') });
    await p.evaluate(() => { // au milieu du geste : l'aimant coupé, la fiche suivante à moitié entrée
      const t = document.getElementById('track'); t.style.scrollSnapType = 'none';
      const pas = t.children[1].offsetLeft - t.children[0].offsetLeft; t.scrollLeft = pas * 0.42;
    });
    await attendre(250);
    await p.screenshot({ path: cadre('_glisser-1.png') });
    await p.evaluate(() => { document.getElementById('track').style.scrollSnapType = ''; window.carrousel.aller(3); });
    await attendre(900); await versFiches(p); await attendre(300);
    await p.screenshot({ path: cadre('_glisser-2.png') });
    // La fiche de la soupe, entière (barres collantes rendues statiques pour la capture d'un seul élément).
    await p.addStyleTag({ content: '.nav,.pick{position:static}' });
    await attendre(200);
    await p.locator('#p-2120004').screenshot({ path: path.join(ICI, '4-telephone-fiche-soupe.png') });
    await p.close();
    await planche(['_glisser-0.png', '_glisser-1.png', '_glisser-2.png'],
      ['Fiche 1 : le croissant', 'On glisse vers la gauche…', '…ou on touche « Soupe » dans la barre'], '3-telephone-glisser.png');

    // Bureau, Corbais : le haut de la page et la grille des fiches.
    p = await ouvrir('corbais-fr.html', 1440, 900);
    await p.screenshot({ path: path.join(ICI, '5-bureau-haut.png') });
    await versFiches(p); await attendre(300);
    await p.screenshot({ path: path.join(ICI, '6-bureau-fiches.png') });
    await p.close();

    // Téléphone, Halle : la page s'ouvre en néerlandais.
    p = await ouvrir('halle-nl.html', 390, 844);
    await p.screenshot({ path: cadre('_halle-0.png') });
    await p.evaluate(() => { const c = document.querySelector('.stack .card'); window.scrollTo(0, c.getBoundingClientRect().top + window.scrollY - 80); });
    await attendre(200);
    await p.screenshot({ path: cadre('_halle-1.png') });
    await p.evaluate(() => window.carrousel.aller(3));
    await attendre(900); await versFiches(p); await attendre(300);
    await p.screenshot({ path: cadre('_halle-2.png') });
    await p.close();
    await planche(['_halle-0.png', '_halle-1.png', '_halle-2.png'],
      ['Ticket de Halle : la page s’ouvre en néerlandais', 'Allergènes et fraîcheur, en néerlandais', 'Les fiches aussi (FR reste à un toucher)'], '7-telephone-halle-nl.png');
  } finally {
    await b.close();
    serveur.kill();
    temporaires.forEach((c) => fs.rmSync(path.join(ICI, c), { force: true }));
  }
})();
