/* Maquettes (capturer.js) : le bouton des vidéos en petit carré à côté d'« Assistant IA » (23/11/2026, demande de Sam).
 * Servir le site assemblé sur 127.0.0.1:8099 (voir panel/tools/videos/LISEZMOI.md), puis : node panel/docs/maquettes/videos-bouton/capturer.js ;
 * la planche : planche.html (capture pleine page). Retenue le 23/11/2026 : la proposition 3 (« À côté, assorti »), rien au téléphone.
 * Trois positions × deux états (au repos ; une nouvelle vidéo à voir, annoncée par une bulle), plus le téléphone.
 * Le vrai dashboard (fausse API), le carré et la bulle posés par-dessus pour la capture ; puis une planche. */
const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');
const { nouveauContexte } = require('../../../tools/videos/mock');

const SORTIE = __dirname;
fs.mkdirSync(SORTIE, { recursive: true });
const ICO = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m10.2 9.3 4.6 2.7-4.6 2.7z"/></svg>';

const CSS = `
.tu-bulle{display:none!important}
.db-rail-ia{position:relative}
/* 1 — dans la ligne d'Assistant IA, au bout */
.mq1 .db-rail-ia .sidebar__item{position:relative;padding-right:44px}
.mq-in{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:26px;height:26px;border-radius:6px;background:#fff;color:#8D1D2C;border:1px solid rgba(141,29,44,.35);display:flex;align-items:center;justify-content:center}
.mq-in.nv{background:#8D1D2C;color:#fff;border-color:#8D1D2C}
/* 2 et 3 — à côté, même hauteur que la ligne */
.mq2 .db-rail-ia,.mq3 .db-rail-ia{display:flex;gap:6px;align-items:stretch}
.mq2 .db-rail-ia .sidebar__item,.mq3 .db-rail-ia .sidebar__item{flex:1;min-width:0}
.mq-cote{flex:none;width:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;position:relative}
.mq2 .mq-cote{background:#fff;border:1px solid #e6e0d8;color:#8D1D2C}
.mq2 .mq-cote.nv{border-color:#8D1D2C}
.mq3 .mq-cote{background:#F2C9A0;color:#8D1D2C;border:1px solid #F2C9A0}
.mq3 .mq-cote.nv{background:#8D1D2C;border-color:#8D1D2C;color:#fff}
.mq-n{position:absolute;top:-8px;right:-8px;min-width:14px;height:14px;padding:0 3px;border-radius:4px;background:#8D1D2C;color:#fff;font:400 9.5px/14px 'Gotham',sans-serif;text-align:center;border:2px solid #faf7f2;box-sizing:content-box}
.mq3 .mq-n{background:#F2C9A0;color:#7A5207}
.mq-pt{position:absolute;top:-4px;right:-4px;width:9px;height:9px;border-radius:50%;background:#F2C9A0;border:2px solid #8D1D2C}
/* La bulle d'annonce, accrochée au carré */
.mq-bulle{position:fixed;z-index:999;width:290px;background:#fff;border:1px solid #e6e0d8;border-left:3px solid #8D1D2C;border-radius:8px;padding:12px 14px 14px;font-family:'Gotham',sans-serif;color:#1c1a17}
.mq-bulle::before{content:'';position:absolute;left:-9px;top:var(--y,22px);width:12px;height:12px;background:#fff;border-left:3px solid #8D1D2C;border-bottom:1px solid #e6e0d8;transform:rotate(45deg)}
.mq-bulle .h{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.mq-bulle .tag{display:inline-flex;padding:3px 8px;border-radius:4px;background:#8D1D2C;color:#fff;font-size:10.5px;letter-spacing:.08em;text-transform:uppercase}
.mq-bulle .x{color:#5c5852;font-size:16px;line-height:1}
.mq-bulle .t{font-size:14px;margin-bottom:4px}
.mq-bulle .r{font-size:12px;line-height:1.5;color:#7a736a}
.mq-bulle .a{display:flex;gap:6px;margin-top:10px}
.mq-bulle .b{display:inline-flex;align-items:center;min-height:30px;padding:5px 11px;border-radius:8px;font-size:12px;border:1px solid #e6e0d8;background:#fff;color:#5c5852}
.mq-bulle .b.p{background:#8D1D2C;border-color:#8D1D2C;color:#fff}
.mq-bulle .b.g{border-color:transparent}
/* Au téléphone : un carré dans l'en-tête, à côté du dossier de formation */
.mq-tel{width:38px;height:38px;border-radius:9px;border:1px solid #8D1D2C;background:#fff;color:#8D1D2C;display:inline-flex;align-items:center;justify-content:center;position:relative;flex:0 0 auto}
.mq-tel svg{width:18px;height:18px}
`;

const BULLE = `<div class="h"><span class="tag">Nouveau</span><span class="x">×</span></div>
<div class="t">Mon équipe : faire ses horaires</div>
<div class="r">Le planning de la semaine, le budget d’heures, adapter un service.</div>
<div class="a"><span class="b p">Voir la vidéo · 2 min 20</span><span class="b g">Plus tard</span></div>`;

async function poser(p, variante, nouveau) {
  await p.addStyleTag({ content: CSS });
  await p.evaluate(([variante, nouveau, ICO, BULLE]) => {
    document.body.classList.add('mq' + variante);
    const ia = document.querySelector('.db-rail-ia');
    const ligne = ia.querySelector('.sidebar__item');
    let carre;
    if (variante === 1) {
      carre = document.createElement('span'); carre.className = 'mq-in' + (nouveau ? ' nv' : ''); carre.innerHTML = ICO;
      ligne.appendChild(carre);
      if (nouveau) { const d = document.createElement('i'); d.className = 'mq-pt'; carre.appendChild(d); }
    } else {
      carre = document.createElement('span'); carre.className = 'mq-cote' + (nouveau ? ' nv' : ''); carre.innerHTML = ICO; carre.title = 'Les vidéos';
      ia.appendChild(carre);
      if (nouveau) { const n = document.createElement('i'); n.className = 'mq-n'; n.textContent = '1'; carre.appendChild(n); }
    }
    if (nouveau) {
      const r = carre.getBoundingClientRect();
      const b = document.createElement('div'); b.className = 'mq-bulle'; b.innerHTML = BULLE;
      const top = Math.max(8, r.top + r.height / 2 - 30);
      b.style.left = (Math.max(r.right, document.querySelector('#db-rail').getBoundingClientRect().right) + 14) + 'px';
      b.style.top = top + 'px';
      b.style.setProperty('--y', (r.top + r.height / 2 - top - 7) + 'px');
      document.body.appendChild(b);
    }
  }, [variante, nouveau, ICO, BULLE]);
  await p.waitForTimeout(300);
}

(async () => {
  const b = await chromium.launch({ args: ['--no-proxy-server', '--lang=fr-BE'], env: { ...process.env, LANG: 'fr_BE.UTF-8', LANGUAGE: 'fr_BE:fr' } });
  for (const v of [1, 2, 3]) {
    for (const nouveau of [false, true]) {
      const { ctx } = await nouveauContexte(b, { viewport: { width: 1440, height: 900 }, dsf: 2 });
      const p = await ctx.newPage();
      await p.goto('http://127.0.0.1:8099/dashboard/?shop=4&vue=equipe');
      await p.waitForTimeout(2200);
      await poser(p, v, nouveau);
      const f = path.join(SORTIE, `p${v}-${nouveau ? 'nouveau' : 'repos'}.png`);
      await p.screenshot({ path: f, clip: { x: 0, y: 70, width: 600, height: 400 } });
      console.log(f);
      await ctx.close();
    }
  }
  // Au téléphone
  for (const nouveau of [false, true]) {
    const { ctx } = await nouveauContexte(b, { viewport: { width: 390, height: 844 }, dsf: 2 });
    const p = await ctx.newPage();
    await p.goto('http://127.0.0.1:8099/dashboard/?shop=4&vue=equipe');
    await p.waitForTimeout(2200);
    await p.addStyleTag({ content: CSS });
    await p.evaluate(([nouveau, ICO, BULLE]) => {
      const cap = [...document.querySelectorAll('.mb-ic, .mb-hd button')].find(x => x.offsetWidth);
      const box = cap ? cap.parentElement : document.body;
      const s = document.createElement('span'); s.className = 'mq-tel'; s.innerHTML = ICO;
      if (nouveau) { const d = document.createElement('i'); d.className = 'mq-n'; d.textContent = '1'; s.appendChild(d); }
      box.insertBefore(s, cap || null);
      if (nouveau) {
        const r = s.getBoundingClientRect();
        const b = document.createElement('div'); b.className = 'mq-bulle'; b.innerHTML = BULLE;
        b.style.width = '300px'; b.style.left = Math.max(10, r.right - 300) + 'px'; b.style.top = (r.bottom + 12) + 'px';
        b.style.borderLeft = '1px solid #e6e0d8'; b.style.borderTop = '3px solid #8D1D2C';
        b.classList.add('haut'); document.body.appendChild(b);
        const st = document.createElement('style');
        st.textContent = `.mq-bulle.haut::before{left:auto;right:${Math.round(b.getBoundingClientRect().right - r.left - r.width / 2 - 6)}px;top:-9px;border-left:1px solid #e6e0d8;border-bottom:0;border-top:3px solid #8D1D2C}`;
        document.head.appendChild(st);
      }
    }, [nouveau, ICO, BULLE]);
    await p.waitForTimeout(300);
    const f = path.join(SORTIE, `tel-${nouveau ? 'nouveau' : 'repos'}.png`);
    await p.screenshot({ path: f, clip: { x: 0, y: 0, width: 390, height: 360 } });
    console.log(f);
    await ctx.close();
  }
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
