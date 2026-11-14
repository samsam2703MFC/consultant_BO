// Captures de la section « Le contrôle du consultant » (data/formation/controle/) : l'application du consultant sur
// tablette (page /controle/ du cockpit), en paysage d'iPad (1180 × 820).
// La page est servie par un cockpit LOCAL (le même code), ses lectures d'API (GET) sont prises à l'API de production ;
// toute autre requête (POST, PUT, DELETE…) est refusée : rien ne s'écrit nulle part.
// Chaque article a son plan : plans/<id>.json = [{ nom, route, id, date, debloquer, clics, attendre, cibles, flou, cadre, pause }]
//   nom       : le fichier rendu (photos/<nom>.jpg), le même que le champ « photo » de la partie ;
//   route     : ce qui suit le # de la page (« controle/4 » = le contrôle de la visite 4) ; vide = l'accueil ;
//   id        : le consultant (défaut u8, ou la variable CONSULTANT) ;
//   date      : la date de l'appareil (AAAA-MM-JJ ou AAAA-MM-JJTHH:MM) — les photos et le planning « du jour » d'une visite
//               commencée un autre jour ne se voient qu'à cette date ;
//   debloquer : vrai = les étapes « bloquantes » ne bloquent pas, sur cet appareil seulement (pour pouvoir afficher une
//               étape plus loin dans une visite pas remplie) ; le passage automatique à l'étape suivante est coupé ;
//   langue    : fr (défaut) ou nl, la langue de l'écran ;
//   etape     : le titre (ou le début du titre) de l'étape du contrôle à ouvrir — l'outil la retrouve dans la barre ;
//   clics     : ce qu'on touche avant la capture, dans l'ordre (bouton de la barre du bas, d'une étape, d'un onglet…) ;
//   attendre  : un élément qu'il faut voir avant la capture (lecture lente) ;
//   cibles    : le ou les blocs entourés en bordeaux (#8D1D2C) ;
//   flou      : les éléments à flouter (noms de personnes : donnée personnelle — jamais lisibles) ;
//   flouTexte : [{ sel, rx }] — dans ces éléments, seul le texte qui répond à l'expression est flouté ;
//   cadre     : « centre » (défaut : la première cible au milieu de l'écran), « haut » (la page depuis le haut), « bas »
//               (le bas de la première cible en bas de l'écran), « debut » (le haut de la première
//               cible en haut de l'écran, sous la barre du haut), « ecran » (l'écran tel qu'il est, une fenêtre ouverte).
// Toujours l'écran de la tablette tel qu'il se voit (barres du haut et du bas comprises).
// Usage : node tools/formation/captures_controle.js data/formation/controle [plan…] [-- nom…]
//   BASE (défaut http://127.0.0.1:8096/controle/), PROD (défaut l'API cockpit de production en http).
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const DIR = process.argv[2] || 'data/formation/controle';
const reste = process.argv.slice(3), sep = reste.indexOf('--');
const PLANS_F = sep < 0 ? reste : reste.slice(0, sep), SEULES = sep < 0 ? [] : reste.slice(sep + 1);
const BASE = process.env.BASE || 'http://127.0.0.1:8096/controle/';
const PROD = process.env.PROD || 'http://185.180.206.46/consulant_bo/api/cockpit/';
const ID = process.env.CONSULTANT || 'u8';
const W = 1180, H = 820;
const fichiers = PLANS_F.length ? PLANS_F.map(f => f.endsWith('.json') ? f : path.join(DIR, 'plans', f + '.json')) : fs.readdirSync(path.join(DIR, 'plans')).filter(f => f.endsWith('.json')).map(f => path.join(DIR, 'plans', f));
const OUT = path.join(DIR, 'photos');
fs.mkdirSync(OUT, { recursive: true });

// L'instance de l'application dans la page : mount() rend celle qui existe déjà pour le même hôte.
const HOTE = "window.CockpitVisites.mount(document.getElementById('controle-root'), { role: 'consultant' })";

async function entourer(p, sel) {
  const loc = p.locator(sel).first();
  await loc.waitFor({ state: 'visible', timeout: 20000 });
  // Un bloc de toute la largeur (barres du haut et du bas) : le trait à l'intérieur, sinon il sort de l'écran.
  await loc.evaluate(el => { const r = el.getBoundingClientRect(); el.style.outline = '4px solid #8D1D2C'; el.style.outlineOffset = r.width >= innerWidth - 20 ? '-5px' : '4px'; });
  return true;
}
(async () => {
  const b = await chromium.launch({ args: ['--lang=fr-BE'], env: Object.assign({}, process.env, { LANG: 'fr_BE.UTF-8', LANGUAGE: 'fr_BE' }) });
  let ok = 0, ko = 0;
  for (const f of fichiers) {
    for (const pl of JSON.parse(fs.readFileSync(f, 'utf8'))) {
      if (SEULES.length && !SEULES.includes(pl.nom)) { continue; }
      const ctx = await b.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 4 / 3, serviceWorkers: 'block', hasTouch: true, locale: 'fr-BE', timezoneId: 'Europe/Brussels' });
      // L'écran en français (la langue d'une visite se garde sur l'appareil) : les articles décrivent l'écran français.
      await ctx.addInitScript(l => { try { localStorage.setItem('vi.lng', l); for (let i = 1; i < 500; i++) { localStorage.setItem('vi.lng.' + i, l); } } catch (e) { /* privé */ } }, pl.langue || 'fr');
      await ctx.route('**/*', async r => {
        const u = r.request().url();
        if (r.request().method() !== 'GET') { return r.abort(); }
        const i = u.indexOf('/api/cockpit/');
        if (u.startsWith(new URL(BASE).origin + '/') && i >= 0) {
          try { const res = await ctx.request.get(PROD + u.slice(i + 13), { timeout: 60000 }); return r.fulfill({ status: res.status(), headers: res.headers(), body: await res.body() }); }
          catch (e) { return r.abort(); }
        }
        return r.continue();
      });
      const p = await ctx.newPage();
      try {
        if (pl.date) { await p.clock.setFixedTime(new Date(pl.date.length > 10 ? pl.date : pl.date + 'T11:00:00')); }
        const url = `${BASE}?id=${encodeURIComponent(pl.id || ID)}${pl.route ? '#' + pl.route : ''}`;
        await p.goto(url, { waitUntil: 'networkidle', timeout: 120000 }).catch(() => {});
        await p.waitForTimeout(pl.pause || 6000);
        if (pl.debloquer) {
          await p.evaluate(h => { const x = eval(h); x.D.seuils = Object.assign({}, x.D.seuils, { etapeAuto: false, etapesBloquantes: { photos_ext: false, photos_int: false, 'mod:*': false, controles: false, checklist: false, pistes: false, constat: false, meteo: false, evaluation: false, equipe: false } }); x.rendre(); }, HOTE);
          await p.waitForTimeout(500);
        }
        if (pl.etape) {
          // Le temps qui contient l'étape, puis l'étape elle-même dans la ligne des étapes du temps.
          const trouve = await p.evaluate(([h, t]) => {
            const x = eval(h); const n = (x._etapes && x._etapes.n) || 0; const titres = [];
            for (let i = 0; i < n; i++) { x.ouvert[x._etapes.cle] = i; x.rendre(); const h1 = document.querySelector('.tb-ctl .tb-h h1'); titres.push(h1 ? h1.textContent.trim() : ''); }
            // Le titre exact d'abord, sinon le premier qui commence par lui.
            let i = titres.indexOf(t); if (i < 0) { i = titres.findIndex(x2 => x2.startsWith(t)); }
            if (i >= 0) { x.ouvert[x._etapes.cle] = i; x.rendre(); window.scrollTo(0, 0); }
            return i;
          }, [HOTE, pl.etape]);
          if (trouve < 0) { console.log('  étape absente', pl.nom, pl.etape); }
          await p.waitForTimeout(pl.pauseEtape || 3000);
        }
        for (const c of pl.clics || []) { await p.locator(c).first().click({ timeout: 20000 }); await p.waitForTimeout(pl.pauseClic || 2000); }
        if (pl.attendre) { await p.locator(pl.attendre).first().waitFor({ timeout: 90000 }); }
        // Un nom au milieu d'un libellé : seul le morceau de texte qui le porte est flouté (le libellé reste lisible).
        for (const ft of pl.flouTexte || []) {
          await p.locator(ft.sel).evaluateAll((els, rx) => els.forEach(el => {
            const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT); const re = new RegExp(rx); let n;
            while ((n = w.nextNode())) { const m = re.exec(n.nodeValue); if (!m) { continue; }
              const fin = n.splitText(m.index); fin.splitText(m[0].length); const sp = document.createElement('span'); sp.style.filter = 'blur(5px)'; fin.parentNode.replaceChild(sp, fin); sp.appendChild(fin); break; }
          }), ft.rx);
        }
        for (const s of pl.flou || []) { await p.locator(s).evaluateAll(els => els.forEach(e => { e.style.filter = 'blur(6px)'; })); }
        let nb = 0;
        for (const s of pl.cibles || []) { try { await entourer(p, s); nb++; } catch (e) { console.log('  cible absente', pl.nom, s); } }
        if (pl.cadre !== 'ecran') {
          if (pl.cadre === 'haut' || !(pl.cibles || []).length) { await p.evaluate(() => scrollTo(0, 0)); }
          else {
            await p.locator(pl.cibles[0]).first().evaluate((el, cadre) => {
              const r = el.getBoundingClientRect(); const top = (document.querySelector('.tb-top') || { offsetHeight: 0 }).offsetHeight;
              const bas = (document.querySelector('.tb-bar') || { offsetHeight: 0 }).offsetHeight; const utile = innerHeight - top - bas;
              let y = scrollY + r.top - top - 14;
              if (cadre === 'bas') { y = scrollY + r.bottom + 14 + bas - innerHeight; }
              else if (cadre === 'debut') { y = scrollY + r.top - top - 14; }
              else if (r.height < utile - 28) { y = scrollY + r.top - top - (utile - r.height) / 2; }
              scrollTo(0, Math.max(0, y));
            }, pl.cadre || 'centre').catch(() => {});
          }
        }
        await p.waitForTimeout(500);
        await p.screenshot({ path: path.join(OUT, pl.nom + '.jpg'), type: 'jpeg', quality: 82 });
        console.log(nb === (pl.cibles || []).length ? 'ok' : 'PARTIEL', pl.nom); ok++;
      } catch (e) { console.log('ÉCHEC', pl.nom, e.message.split('\n')[0]); ko++; }
      await ctx.close();
    }
  }
  await b.close();
  console.log(`${ok} capture(s), ${ko} échec(s)`);
})();
