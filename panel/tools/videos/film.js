/* Le moteur des vidéos d'instruction du dashboard magasin.
 *
 *   const { Film } = require('./film');
 *   const f = await Film.ouvrir('01-decouvrir', { vue: '' });
 *   await f.titre('Découvrir le dashboard', 'Le tour en deux minutes');
 *   await f.dire('Le rail, à gauche, ouvre chaque page.');
 *   await f.montrer(f.p.locator('nav'), 'Le rail');        // un cadre autour d'un élément
 *   await f.cliquer(f.p.getByText('Semaine').first());      // le curseur y va, clique
 *   await f.fin();                                          // → sortie/01-decouvrir.mp4
 *
 * La page est filmée par le screencast de Chromium (images JPEG au fil des changements), puis ffmpeg en fait un MP4
 * H.264 à 30 i/s, en respectant le temps réel de chaque image. Le curseur, les sous-titres, les cadres et les cartons
 * sont dessinés DANS la page (calque au-dessus de tout, sans pointer-events) : ce qu'on voit est ce qui est filmé.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');
const { DEMO, nouveauContexte } = require('./mock');

const W = 1440, H = 810, DSF = 4 / 3;            // 1920 × 1080 en sortie
// Le port du site servi en local (FILM_PORT, 8099 par défaut) : plusieurs tournages peuvent tourner en même temps.
const PORT = +(process.env.FILM_PORT || 8099);
const BASE = 'http://127.0.0.1:' + PORT;
const attendre = ms => new Promise(r => setTimeout(r, ms));

/* Le calque : injecté avant chaque chargement de page. */
const CALQUE = `(() => {
  if (window.__film) return;
  const css = \`
  /* Design system L'Atelier By (docs/DESIGN_SYSTEM.md) : Gotham 400 = texte, 700 = accents seuls ; Ruby #8D1D2C,
     Abricot #F2C9A0, papier #faf7f2, filet #e6e0d8, encre #1c1a17 ; aucune pilule (rayons 4 à 8 px), pas d'ombre. */
  #film-calque{position:fixed;inset:0;z-index:2147483647;pointer-events:none;font-family:'Gotham',system-ui,sans-serif;font-weight:400;color:#1c1a17;-webkit-font-smoothing:antialiased}
  #film-calque *{box-sizing:border-box}
  #film-curseur{position:absolute;left:0;top:0;width:26px;height:26px;transform:translate(-3px,-2px);transition:transform .08s;filter:drop-shadow(0 1px 2px rgba(28,26,23,.35))}
  #film-curseur.appui{transform:translate(-3px,-2px) scale(.86)}
  .film-onde{position:absolute;width:44px;height:44px;margin:-22px 0 0 -22px;border-radius:50%;border:2px solid #8D1D2C;animation:film-onde .6s ease-out forwards}
  @keyframes film-onde{from{transform:scale(.3);opacity:.95}to{transform:scale(1.5);opacity:0}}
  /* La mise en avant : un cadre Ruby 2 px, le reste de l'écran voilé (voile des modales du système). */
  #film-cadre{position:absolute;border:2px solid #8D1D2C;border-radius:8px;box-shadow:0 0 0 9999px rgba(24,18,20,.30);opacity:0;transition:all .45s cubic-bezier(.2,.8,.2,1)}
  #film-cadre.on{opacity:1}
  #film-etiq{position:absolute;background:#8D1D2C;color:#fff;font-size:14px;letter-spacing:.08em;text-transform:uppercase;padding:7px 12px;border-radius:6px;white-space:nowrap;opacity:0;transition:opacity .35s}
  #film-etiq.on{opacity:1}
  /* Le sous-titre : encre, rayon 8 px ; les mots appuyés en Abricot (couleur, pas de gras). */
  #film-st{position:absolute;left:50%;bottom:30px;transform:translate(-50%,10px);max-width:1120px;width:max-content;background:rgba(28,26,23,.93);color:#fff;font-size:23px;line-height:1.4;padding:14px 26px 15px;border-radius:8px;border-left:4px solid #8D1D2C;text-align:left;opacity:0;transition:opacity .35s,transform .35s}
  #film-st.on{opacity:1;transform:translate(-50%,0)}
  #film-st b{color:#F2C9A0;font-weight:400}
  #film-etape{position:absolute;right:16px;top:12px;background:#8D1D2C;color:#fff;font-size:12px;letter-spacing:.14em;text-transform:uppercase;padding:8px 14px;border-radius:6px;opacity:0;transition:opacity .35s}
  #film-etape.on{opacity:1}

  /* La confirmation du navigateur (window.confirm) ne se filme pas : on la redessine (§7.11 du design system). */
  #film-conf{position:absolute;inset:0;display:none;align-items:center;justify-content:center;background:rgba(24,18,20,.42)}
  #film-conf.on{display:flex}
  #film-conf .fc-bx{display:block;width:460px;background:#fff;border:1px solid #e6e0d8;border-radius:12px;padding:22px 24px;text-align:left}
  #film-conf .fc-t{display:block;margin:0 0 8px;font-size:16px;color:#1c1a17}
  #film-conf .fc-p{display:block;margin:0;font-size:13px;line-height:1.5;color:#5c5852}
  #film-conf .fc-ac{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}
  #film-conf .fc-b{display:inline-flex;align-items:center;min-height:36px;padding:7px 16px;border:1px solid #e6e0d8;border-radius:8px;background:#fff;font-size:13px;color:#1c1a17}
  #film-conf .fc-b.fc-ok{background:#8D1D2C;border-color:#8D1D2C;color:#fff}

  /* --- Les diapositives ------------------------------------------------- */
  #film-carton{position:absolute;inset:0;opacity:0;transition:opacity .6s;overflow:hidden}
  #film-carton.on{opacity:1}
  .fd{position:absolute;inset:0;display:flex;flex-direction:column;padding:56px 80px 44px}
  .fd-tete{display:flex;justify-content:space-between;align-items:flex-end;padding-bottom:18px;border-bottom:2px solid #8D1D2C}
  .fd-tete img{height:40px;display:block}
  .fd-tete .k{font-size:13px;letter-spacing:.18em;text-transform:uppercase;color:#7a736a;text-align:right;line-height:1.6}
  .fd-pied{display:flex;justify-content:space-between;align-items:center;padding-top:14px;border-top:1px solid #e6e0d8;font-size:13px;color:#7a736a;letter-spacing:.02em}
  .fd-corps{flex:1;display:flex;align-items:center;gap:72px}
  .fd .kick{font-size:14px;letter-spacing:.2em;text-transform:uppercase;color:#8D1D2C;margin:0 0 18px}
  .fd h1{font-size:58px;line-height:1.08;letter-spacing:-.01em;margin:0 0 22px;font-weight:400}
  .fd .acc{font-family:'Playwrite DEVA','Gotham',cursive;font-size:24px;line-height:1.6;margin:0}
  .fd p.t{font-size:22px;line-height:1.55;color:#5c5852;margin:0;max-width:820px}
  .fd ol{list-style:none;margin:0;padding:0;counter-reset:i}
  .fd ol li{counter-increment:i;display:flex;align-items:center;gap:18px;font-size:21px;line-height:1.35;padding:13px 0;border-bottom:1px solid rgba(34,34,34,.06)}
  .fd ol li:last-child{border-bottom:0}
  .fd ol li::before{content:counter(i);flex:none;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px}

  /* Couverture et fin : la couverture de marque (Ruby plein, logo blanc, accents Abricot). */
  .fd--marque{background:#8D1D2C;color:#fff}
  .fd--marque .fd-tete{border-bottom-color:#F2C9A0}
  .fd--marque .fd-tete .k,.fd--marque .fd-pied{color:#E8C9CD}
  .fd--marque .fd-pied{border-top-color:rgba(242,201,160,.35)}
  .fd--marque .kick{color:#F2C9A0}
  .fd--marque .acc{color:#F2C9A0}
  .fd--marque .gauche{flex:1.15}
  .fd--marque .droite{flex:1;background:rgba(255,255,255,.06);border:1px solid rgba(242,201,160,.35);border-radius:8px;padding:22px 28px}
  .fd--marque .droite .kick{margin-bottom:6px}
  .fd--marque ol li{border-bottom-color:rgba(242,201,160,.22);color:#fff}
  .fd--marque ol li::before{border:1.5px solid #F2C9A0;color:#F2C9A0}

  /* Intercalaire de chapitre : le papier, le logo, le filet Ruby (gabarit des documents, version A). */
  .fd--papier{background:#faf7f2;color:#1c1a17}
  .fd--papier .num{flex:none;width:168px;height:168px;border-radius:50%;background:#8D1D2C;color:#fff;display:flex;align-items:center;justify-content:center;font-size:76px;letter-spacing:-.02em}
  .fd--papier h1{font-size:52px}
  .fd--papier .bloc{border-left:3px solid #8D1D2C;padding-left:28px}
  .fd--papier ol li::before{background:#8D1D2C;color:#fff}
  .fd--papier ol{max-width:860px}
  .fd--papier .fd-pied .p{color:#8D1D2C}

  /* Formation : objectifs, « À vous » (exercice), quiz. */
  .fd .col{flex:1}
  .fd--papier .obj li::before{content:'';width:22px;height:22px;border-radius:50%;background:none;border:2px solid #8D1D2C;box-sizing:border-box}
  .fd--papier .obj li{font-size:23px;padding:16px 0}
  .fd-exo{background:#fff;border:1px solid #e6e0d8;border-left:3px solid #B0792A;border-radius:8px;padding:28px 34px;max-width:980px}
  .fd-exo .kick{color:#7A5207}
  .fd-exo ol li::before{background:#F6E7CE !important;color:#7A5207 !important}
  .fd-pause{display:flex;align-items:center;gap:16px;margin-top:22px;font-size:17px;color:#7A5207}
  .fd-pause .barre{flex:1;height:6px;border-radius:3px;background:#F6E7CE;overflow:hidden}
  .fd-pause .barre i{display:block;height:100%;width:0;background:#B0792A;animation:fd-temps linear forwards}
  @keyframes fd-temps{to{width:100%}}
  .fd-quiz{display:grid;gap:12px;max-width:980px;margin-top:8px}
  .fd-quiz .r{display:flex;align-items:center;gap:16px;padding:16px 20px;background:#fff;border:1px solid #e6e0d8;border-radius:8px;font-size:21px;transition:all .5s}
  .fd-quiz .r .l{flex:none;width:34px;height:34px;border-radius:50%;border:1.5px solid #B8B2A8;display:flex;align-items:center;justify-content:center;font-size:15px;color:#5c5852;transition:all .5s}
  .fd-quiz.revele .r{opacity:.45}
  .fd-quiz.revele .r.bon{opacity:1;border-color:#3f7a52;background:rgba(63,122,82,.08)}
  .fd-quiz.revele .r.bon .l{background:#3f7a52;border-color:#3f7a52;color:#fff}
  .fd-expl{margin-top:18px;font-size:19px;line-height:1.5;color:#3f7a52;opacity:0;transition:opacity .5s;max-width:980px}
  .fd-quiz.revele + .fd-expl{opacity:1}
  \`;
  const st = document.createElement('style'); st.textContent = css;
  const c = document.createElement('div'); c.id = 'film-calque';
  c.innerHTML = '<div id="film-cadre"></div><div id="film-etiq"></div><div id="film-st"></div><div id="film-etape"></div><div id="film-carton"></div><div id="film-conf"></div>' +
    '<svg id="film-curseur" viewBox="0 0 26 26"><path d="M3 2 L3 21 L8.2 16.4 L11.6 24 L15 22.6 L11.7 15.2 L18.5 15.2 Z" fill="#fff" stroke="#1c1416" stroke-width="1.6" stroke-linejoin="round"/></svg>';
  let veille = false;
  const poser = () => {
    if (!document.documentElement) return;
    document.documentElement.appendChild(st); document.documentElement.appendChild(c);
    if (!veille) { veille = true; new MutationObserver(() => { if (c.parentNode !== document.documentElement || document.documentElement.lastElementChild !== c) { poser(); } }).observe(document.documentElement, { childList: true }); }
  };
  if (document.documentElement) poser(); else { const mo = new MutationObserver(() => { if (document.documentElement) { mo.disconnect(); poser(); } }); mo.observe(document, { childList: true }); }
  document.addEventListener('DOMContentLoaded', poser);
  const cur = () => c.querySelector('#film-curseur');
  let x = -50, y = -50;
  addEventListener('mousemove', e => { x = e.clientX; y = e.clientY; cur().style.left = x + 'px'; cur().style.top = y + 'px'; }, true);
  addEventListener('mousedown', () => { cur().classList.add('appui'); const o = document.createElement('div'); o.className = 'film-onde'; o.style.left = x + 'px'; o.style.top = y + 'px'; c.appendChild(o); setTimeout(() => o.remove(), 700); }, true);
  addEventListener('mouseup', () => cur().classList.remove('appui'), true);
  window.__film = {
    st(html) { const e = c.querySelector('#film-st'); if (!html) { e.classList.remove('on'); return; } e.innerHTML = html; e.classList.add('on'); },
    etape(t) { const e = c.querySelector('#film-etape'); if (!t) { e.classList.remove('on'); return; } e.textContent = t; e.classList.add('on'); },
    cadre(r, etiq) {
      const e = c.querySelector('#film-cadre'), l = c.querySelector('#film-etiq');
      if (!r) { e.classList.remove('on'); l.classList.remove('on'); return; }
      const m = 6; Object.assign(e.style, { left: (r.x - m) + 'px', top: (r.y - m) + 'px', width: (r.w + 2 * m) + 'px', height: (r.h + 2 * m) + 'px' });
      e.classList.add('on');
      if (etiq) { l.textContent = etiq; const haut = r.y - m - 40 > 8; Object.assign(l.style, { left: Math.max(8, Math.min(innerWidth - 320, r.x - m)) + 'px', top: (haut ? r.y - m - 40 : r.y + r.h + m + 8) + 'px' }); l.classList.add('on'); } else { l.classList.remove('on'); }
    },
    carton(html) { const e = c.querySelector('#film-carton'); if (!html) { e.classList.remove('on'); return; } e.innerHTML = html; e.classList.add('on'); },
    async prets() {
      const imgs = ['/assets/img/logo.png', '/assets/img/newsletter/logo-white.png'].map(u => { const i = new Image(); i.src = u; return i.decode().catch(() => null); });
      await Promise.all([...imgs, document.fonts.load("400 20px 'Gotham'"), document.fonts.load("700 20px 'Gotham'"), document.fonts.load("400 20px 'Playwrite DEVA'")].map(p => Promise.resolve(p).catch(() => null)));
      await document.fonts.ready;
    },
    curseur(v) { cur().style.display = v ? '' : 'none'; },
    onde(px, py) { const o = document.createElement('div'); o.className = 'film-onde'; o.style.left = px + 'px'; o.style.top = py + 'px'; c.appendChild(o); setTimeout(() => o.remove(), 700); },
    conf(titre, texte) {
      const e = c.querySelector('#film-conf');
      if (!titre) { e.classList.remove('on'); return null; }
      e.innerHTML = '<div class="fc-bx"><span class="fc-t"></span><span class="fc-p"></span><div class="fc-ac"><span class="fc-b">Annuler</span><span class="fc-b fc-ok">OK</span></div></div>';
      e.querySelector('.fc-t').textContent = titre; e.querySelector('.fc-p').textContent = texte || '';
      e.classList.add('on');
      const r = e.querySelector('.fc-ok').getBoundingClientRect();
      return { x: r.x, y: r.y, w: r.width, h: r.height };
    },
  };
})();`;

class Film {
  static async ouvrir(nom, opts = {}) {
    const f = new Film(nom, opts);
    await f._demarrer();
    return f;
  }
  constructor(nom, opts) {
    this.nom = nom; this.opts = opts;
    this.dossier = path.join(__dirname, 'images', nom);
    this.sortie = path.join(__dirname, 'sortie');
    this.images = []; this.souris = { x: W * 0.6, y: H * 0.55 };
    this.sous = []; this.t0 = null;
  }
  async _demarrer() {
    fs.rmSync(this.dossier, { recursive: true, force: true });
    fs.mkdirSync(this.dossier, { recursive: true });
    fs.mkdirSync(this.sortie, { recursive: true });
    this.b = await chromium.launch({ args: ['--no-proxy-server', '--hide-scrollbars', '--lang=fr-BE'], env: { ...process.env, LANG: 'fr_BE.UTF-8', LANGUAGE: 'fr_BE:fr' } });
    const { ctx, nonServis } = await nouveauContexte(this.b, { viewport: { width: W, height: H }, dsf: DSF });
    this.ctx = ctx; this.nonServis = nonServis;
    await ctx.addInitScript(CALQUE);
    // L'annonce d'une nouvelle fonction (la bulle accrochée au carré des vidéos, tutos.js) ne se filme pas : elle
    // couvrirait l'écran. Une vidéo qui l'explique la montre le temps voulu (f.annonce(true)). Le carré, lui, reste.
    await ctx.addInitScript(() => { document.addEventListener('DOMContentLoaded', () => { document.documentElement.classList.add('film-sans-annonce'); const st = document.createElement('style'); st.textContent = 'html.film-sans-annonce .tu-annonce{display:none!important}'; document.head.appendChild(st); }); });
    this.p = await ctx.newPage();
    this.p.on('pageerror', e => console.log('  ERREUR JS', e.message));
    this.p.on('dialog', d => d.accept().catch(() => null));
    // La page se charge AVANT le tournage (pas d'écran vide en tête de vidéo).
    const q = new URLSearchParams({ shop: DEMO.shop, ...(this.opts.vue ? { vue: this.opts.vue } : {}), ...(this.opts.query || {}) });
    await this.p.goto(BASE + (this.opts.chemin || '/dashboard/') + '?' + q);
    await this.p.waitForTimeout(this.opts.chargement || 2500);
    await this.p.evaluate(() => window.__film.prets());
    if (this.opts.avant) { await this.opts.avant(this.p); }
    await this.p.mouse.move(this.souris.x, this.souris.y);
    // Le tournage.
    this.cdp = await this.ctx.newCDPSession(this.p);
    this.cdp.on('Page.screencastFrame', async ev => {
      const t = ev.metadata.timestamp; if (this.t0 == null) { this.t0 = t; }
      const fichier = path.join(this.dossier, String(this.images.length).padStart(6, '0') + '.jpg');
      fs.writeFileSync(fichier, Buffer.from(ev.data, 'base64'));
      this.images.push({ fichier, t });
      try { await this.cdp.send('Page.screencastFrameAck', { sessionId: ev.sessionId }); } catch (e) { /* fin */ }
    });
    await this.cdp.send('Page.startScreencast', { format: 'jpeg', quality: 92, maxWidth: Math.round(W * DSF), maxHeight: Math.round(H * DSF), everyNthFrame: 1 });
    this.debut = Date.now();
  }

  /* --- le temps ---------------------------------------------------------- */
  pause(ms) { return this.p.waitForTimeout(ms); }
  /** Durée de lecture d'un sous-titre : ~15 caractères par seconde, 2,2 s au moins. */
  duree(texte) { const n = String(texte).replace(/<[^>]+>/g, '').length; return Math.max(2200, Math.round(n / 15 * 1000) + 900); }

  /* --- le calque --------------------------------------------------------- */
  async dire(html, ms) { await this.p.evaluate(h => window.__film.st(h), html); await this.pause(ms == null ? this.duree(html) : ms); }
  async taire(ms = 350) { await this.p.evaluate(() => window.__film.st('')); await this.pause(ms); }
  async etape(t) { await this.p.evaluate(x => window.__film.etape(x), t || ''); }
  /* --- les diapositives (design system : couverture de marque, intercalaire papier) --- */
  static esc(t) { return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  /** Montre une diapositive, la garde ms, puis rend l'écran. */
  async diapo(html, ms, garder) {
    await this.p.evaluate(h => { window.__film.st(''); window.__film.etape(''); window.__film.cadre(null); window.__film.curseur(false); window.__film.carton(h); }, html);
    await this.pause(ms);
    if (garder) { return; }   // la dernière diapositive reste jusqu'à la fin du film
    await this.p.evaluate(() => { window.__film.carton(''); window.__film.curseur(true); });
    await this.pause(700);
  }
  pied(gauche, droite) { return '<div class="fd-pied"><span>' + gauche + '</span><span class="p">' + (droite || '') + '</span></div>'; }
  /** La couverture : Ruby plein, logo blanc, titre, accroche (Playwrite), le programme à droite. */
  async couverture({ kicker, titre, accroche, programme, ms = 5200 }) {
    const e = Film.esc;
    const html = '<div class="fd fd--marque"><div class="fd-tete"><img src="/assets/img/newsletter/logo-white.png" alt="L’Atelier by"><div class="k">Dashboard magasin<br>' + e(this.opts.serie || 'Tutoriel') + '</div></div>' +
      '<div class="fd-corps"><div class="gauche"><p class="kick">' + e(kicker || '') + '</p><h1>' + titre + '</h1>' + (accroche ? '<p class="acc">' + e(accroche) + '</p>' : '') + '</div>' +
      (programme && programme.length ? '<div class="droite"><p class="kick">Au programme</p><ol>' + programme.map(x => '<li><span>' + x + '</span></li>').join('') + '</ol></div>' : '') + '</div>' +
      this.pied('Les écrans sont ceux du dashboard ; les chiffres et les noms sont fictifs', e(this.opts.duree || '')) + '</div>';
    await this.diapo(html, ms);
  }
  /** L'intercalaire d'un chapitre : papier, logo, filet Ruby, numéro dans un cercle Ruby. */
  async chapitre({ n, total, titre, texte, points, ms }) {
    const e = Film.esc;
    const html = '<div class="fd fd--papier"><div class="fd-tete"><img src="/assets/img/logo.png" alt="L’Atelier by"><div class="k">' + e(this.opts.titreSerie || 'Dashboard magasin') + '</div></div>' +
      '<div class="fd-corps"><div class="num">' + n + '</div><div class="bloc"><p class="kick">Étape ' + n + ' sur ' + total + '</p><h1>' + titre + '</h1>' + (texte ? '<p class="t">' + texte + '</p>' : '') +
      (points && points.length ? '<ol style="margin-top:22px">' + points.map(x => '<li><span>' + x + '</span></li>').join('') + '</ol>' : '') + '</div></div>' +
      this.pied('L’Atelier by · Dashboard magasin', 'Étape ' + n + ' / ' + total) + '</div>';
    await this.diapo(html, ms || Math.max(3400, this.duree(titre + ' ' + (texte || '') + ' ' + (points || []).join(' ')) * 0.8));
  }
  /** Les objectifs de la formation : « À la fin de ce module, vous saurez… ». */
  async objectifs({ titre = 'À la fin de ce module, vous saurez', points, ms }) {
    const html = '<div class="fd fd--papier"><div class="fd-tete"><img src="/assets/img/logo.png" alt="L’Atelier by"><div class="k">' + Film.esc(this.opts.titreSerie || 'Formation') + '</div></div>' +
      '<div class="fd-corps"><div class="bloc col"><p class="kick">Objectifs</p><h1>' + titre + '</h1><ol class="obj">' + points.map(x => '<li><span>' + x + '</span></li>').join('') + '</ol></div></div>' +
      this.pied('L’Atelier by · Formation dashboard magasin', 'Objectifs') + '</div>';
    await this.diapo(html, ms || Math.max(5000, this.duree(points.join(' ')) * 0.9));
  }
  /** « À vous » : la mise en pratique ; la barre or dit le temps de la pause (le formateur peut arrêter la vidéo). */
  async exercice({ titre, consignes, pause = 9000 }) {
    const html = '<div class="fd fd--papier"><div class="fd-tete"><img src="/assets/img/logo.png" alt="L’Atelier by"><div class="k">' + Film.esc(this.opts.titreSerie || 'Formation') + '</div></div>' +
      '<div class="fd-corps"><div class="fd-exo"><p class="kick">À vous · mise en pratique</p><h1 style="font-size:40px">' + titre + '</h1><ol>' + consignes.map(x => '<li><span>' + x + '</span></li>').join('') + '</ol>' +
      '<div class="fd-pause"><span>Mettez la vidéo en pause le temps de le faire</span><span class="barre"><i style="animation-duration:' + pause + 'ms"></i></span></div></div></div>' +
      this.pied('L’Atelier by · Formation dashboard magasin', 'Mise en pratique') + '</div>';
    await this.diapo(html, pause + 600);
  }
  /** Un quiz : la question, les réponses, puis la bonne réponse en vert et son explication. */
  async quiz({ n, question, reponses, bonne, explication, reflexion = 6000, lecture }) {
    const L = 'ABCD';
    const html = '<div class="fd fd--papier"><div class="fd-tete"><img src="/assets/img/logo.png" alt="L’Atelier by"><div class="k">' + Film.esc(this.opts.titreSerie || 'Formation') + '</div></div>' +
      '<div class="fd-corps"><div class="col"><p class="kick">Quiz' + (n ? ' · question ' + n : '') + '</p><h1 style="font-size:40px;max-width:1100px">' + question + '</h1>' +
      '<div class="fd-quiz" id="fd-q">' + reponses.map((r, i) => '<div class="r' + (i === bonne ? ' bon' : '') + '"><span class="l">' + L[i] + '</span><span>' + r + '</span></div>').join('') + '</div>' +
      '<p class="fd-expl">' + (explication || '') + '</p></div></div>' +
      this.pied('L’Atelier by · Formation dashboard magasin', 'Quiz') + '</div>';
    await this.p.evaluate(h => { window.__film.st(''); window.__film.etape(''); window.__film.cadre(null); window.__film.curseur(false); window.__film.carton(h); }, html);
    await this.pause(reflexion);
    await this.p.evaluate(() => { const q = document.getElementById('fd-q'); if (q) { q.classList.add('revele'); } });
    await this.pause(lecture || Math.max(3800, this.duree(explication || '') + 800));
    await this.p.evaluate(() => { window.__film.carton(''); window.__film.curseur(true); });
    await this.pause(700);
  }

  /** La fin : couverture de marque, « À retenir ». */
  async finale({ titre, retenir, accroche, ms = 6500 }) {
    const e = Film.esc;
    const html = '<div class="fd fd--marque"><div class="fd-tete"><img src="/assets/img/newsletter/logo-white.png" alt="L’Atelier by"><div class="k">Dashboard magasin<br>' + e(this.opts.serie || 'Tutoriel') + '</div></div>' +
      '<div class="fd-corps"><div class="gauche"><p class="kick">C’est à vous</p><h1>' + titre + '</h1>' + (accroche ? '<p class="acc">' + e(accroche) + '</p>' : '') + '</div>' +
      (retenir && retenir.length ? '<div class="droite"><p class="kick">À retenir</p><ol>' + retenir.map(x => '<li><span>' + x + '</span></li>').join('') + '</ol></div>' : '') + '</div>' +
      this.pied('Une question ? « Demande au bureau », en haut du dashboard', '') + '</div>';
    await this.diapo(html, ms, true);
  }

  /** Le rectangle d'un élément (locator ou sélecteur), dans la fenêtre ; le fait défiler à vue s'il le faut. */
  async rect(cible, defiler = true) {
    const loc = typeof cible === 'string' ? this.p.locator(cible).first() : cible;
    if (defiler) { await this.defilerVers(loc); }
    const r = await loc.boundingBox();
    if (!r) { throw new Error('élément introuvable : ' + cible); }
    return { x: r.x, y: r.y, w: r.width, h: r.height, loc };
  }
  /** Défile en douceur jusqu'à mettre l'élément dans le tiers haut de l'écran, s'il n'est pas déjà bien visible. */
  async defilerVers(loc, marge = 90) {
    const info = await loc.evaluate((el, marge) => {
      const r = el.getBoundingClientRect();
      let s = el.parentElement;
      while (s && s !== document.body) { const cs = getComputedStyle(s); if (/(auto|scroll)/.test(cs.overflowY) && s.scrollHeight > s.clientHeight + 2) { break; } s = s.parentElement; }
      const conteneur = (s && s !== document.body) ? s : null;
      const haut = conteneur ? conteneur.getBoundingClientRect().top : 0, bas = conteneur ? conteneur.getBoundingClientRect().bottom : innerHeight;
      const visible = r.top >= haut + 8 && r.bottom <= bas - 120;
      return { visible, delta: r.top - haut - marge, conteneur: !!conteneur };
    }, marge);
    if (info.visible) { return; }
    await loc.evaluate(async (el, delta) => {
      let s = el.parentElement;
      while (s && s !== document.body) { const cs = getComputedStyle(s); if (/(auto|scroll)/.test(cs.overflowY) && s.scrollHeight > s.clientHeight + 2) { break; } s = s.parentElement; }
      const cible = (s && s !== document.body) ? s : document.scrollingElement;
      const de = cible.scrollTop, a = Math.max(0, Math.min(cible.scrollHeight - cible.clientHeight, de + delta));
      const n = Math.max(20, Math.min(55, Math.round(Math.abs(a - de) / 18)));
      for (let i = 1; i <= n; i++) { const t = i / n, e = t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; cible.scrollTop = de + (a - de) * e; await new Promise(r => requestAnimationFrame(r)); }
    }, info.delta);
    await this.pause(350);
  }
  /** Défile la page (ou le conteneur principal) de dy pixels, en douceur. */
  async defiler(dy, conteneur) {
    await this.p.evaluate(async ({ dy, conteneur }) => {
      const cible = conteneur ? document.querySelector(conteneur) : document.scrollingElement;
      const de = cible.scrollTop, a = Math.max(0, Math.min(cible.scrollHeight - cible.clientHeight, de + dy));
      const n = Math.max(20, Math.min(60, Math.round(Math.abs(a - de) / 16)));
      for (let i = 1; i <= n; i++) { const t = i / n, e = t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; cible.scrollTop = de + (a - de) * e; await new Promise(r => requestAnimationFrame(r)); }
    }, { dy, conteneur });
    await this.pause(300);
  }
  async montrer(cible, etiquette, ms) {
    const r = await this.rect(cible);
    await this.p.evaluate(([r, e]) => window.__film.cadre(r, e), [{ x: r.x, y: r.y, w: r.w, h: r.h }, etiquette || '']);
    if (ms) { await this.pause(ms); }
    return r;
  }
  /** Un cadre autour de TOUS les éléments d'un sélecteur (plusieurs lignes d'un tableau…), défilé à vue d'abord. */
  async montrerTout(sel, etiquette, ms) {
    const loc = this.p.locator(sel);
    await this.defilerVers(loc.first());
    const r = await this.p.evaluate(sel => {
      const L = [...document.querySelectorAll(sel)].map(e => e.getBoundingClientRect()).filter(r => r.width && r.height);
      if (!L.length) { return null; }
      const x = Math.min(...L.map(r => r.left)), y = Math.min(...L.map(r => r.top)), x2 = Math.max(...L.map(r => r.right)), y2 = Math.max(...L.map(r => r.bottom));
      return { x, y, w: x2 - x, h: y2 - y };
    }, sel);
    if (!r) { throw new Error('rien pour ' + sel); }
    await this.p.evaluate(([r, e]) => window.__film.cadre(r, e), [r, etiquette || '']);
    if (ms) { await this.pause(ms); }
    return r;
  }
  /** Montre ou cache l'annonce « Nouveau » du carré des vidéos (cachée par défaut). */
  async annonce(oui, ms = 500) { await this.p.evaluate(o => document.documentElement.classList.toggle('film-sans-annonce', !o), !!oui); await this.pause(ms); }
  async cacher(ms = 300) { await this.p.evaluate(() => window.__film.cadre(null)); await this.pause(ms); }

  /* --- la souris --------------------------------------------------------- */
  async aller(x, y, ms) {
    const de = this.souris, dist = Math.hypot(x - de.x, y - de.y);
    const duree = ms || Math.max(380, Math.min(1100, dist * 1.1));
    const n = Math.max(12, Math.round(duree / 16));
    // Une courbe légère, comme une main.
    const cx = (de.x + x) / 2 + (y - de.y) * 0.12, cy = (de.y + y) / 2 - (x - de.x) * 0.12;
    for (let i = 1; i <= n; i++) {
      const t = i / n, e = t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
      const px = (1 - e) * (1 - e) * de.x + 2 * (1 - e) * e * cx + e * e * x, py = (1 - e) * (1 - e) * de.y + 2 * (1 - e) * e * cy + e * e * y;
      await this.p.mouse.move(px, py);
      await attendre(16);
    }
    this.souris = { x, y };
  }
  async survoler(cible, { dx = 0.5, dy = 0.5, pause = 250 } = {}) {
    const r = await this.rect(cible);
    await this.aller(r.x + r.w * dx, r.y + r.h * dy);
    await this.pause(pause);
    return r;
  }
  async cliquer(cible, opts = {}) {
    const r = await this.survoler(cible, opts);
    await this.p.mouse.down(); await attendre(90); await this.p.mouse.up();
    await this.pause(opts.apres == null ? 900 : opts.apres);
    return r;
  }
  /** Un bouton qui demande confirmation (window.confirm) : le geste, la confirmation redessinée, OK, puis le vrai clic
   * (envoyé à l'élément, sans bouger la souris ; la boîte du navigateur est acceptée d'office). */
  async cliquerConfirme(cible, titre, texte, { apres = 1300 } = {}) {
    const r = await this.survoler(cible);
    await this.p.evaluate(([x, y]) => window.__film.onde(x, y), [this.souris.x, this.souris.y]);
    await this.pause(350);
    const ok = await this.p.evaluate(([t, x]) => window.__film.conf(t, x), [titre, texte || '']);
    await this.pause(1600);
    await this.aller(ok.x + ok.w / 2, ok.y + ok.h / 2);
    await this.p.evaluate(([x, y]) => window.__film.onde(x, y), [this.souris.x, this.souris.y]);
    await this.pause(300);
    await this.p.evaluate(() => window.__film.conf(null));
    await r.loc.dispatchEvent('click');
    await this.pause(apres);
    return r;
  }
  /** Tire la souris d'un point à un autre (bouton enfoncé). */
  async tirer(x1, y1, x2, y2, ms = 1200) {
    await this.aller(x1, y1); await this.pause(200);
    await this.p.mouse.down(); await attendre(120);
    await this.aller(x2, y2, ms);
    await attendre(120); await this.p.mouse.up();
    await this.pause(700);
  }
  async taper(texte, delai = 70) { await this.p.keyboard.type(texte, { delay: delai }); await this.pause(300); }

  /* --- la fin ------------------------------------------------------------ */
  async fin(finale) {
    await this.taire(200); await this.cacher(200); await this.etape('');
    if (finale) { await this.finale(finale); } else { await this.pause(800); }
    // L'écran immobile n'envoie plus d'image : la dernière dure jusqu'ici (horloge des images : secondes depuis 1970).
    this.tFin = Date.now() / 1000;
    await this.cdp.send('Page.stopScreencast');
    await attendre(300);
    if (this.nonServis.size && process.env.FILM_DEBUG) { console.log('  non servis :', [...this.nonServis].join(' · ')); }
    await this.b.close();
    return this.monter();
  }
  monter() {
    // Sous charge, le screencast livre des images dans le désordre : on les remet dans l'ordre de leur horodatage
    // (sinon des durées négatives, ramenées au minimum, allongent la vidéo).
    const imgs = this.images.slice().sort((a, b) => a.t - b.t);
    if (!imgs.length) { throw new Error('aucune image filmée'); }
    const fin = (Date.now() - this.debut) / 1000; // garde-fou : la dernière image dure jusqu'à la fin
    const lignes = ['ffconcat version 1.0'];
    for (let i = 0; i < imgs.length; i++) {
      const d = i + 1 < imgs.length ? imgs[i + 1].t - imgs[i].t : Math.max(0.5, Math.min(15, (this.tFin || imgs[i].t + 1) - imgs[i].t));
      lignes.push("file '" + path.basename(imgs[i].fichier) + "'", 'duration ' + Math.max(0.001, d).toFixed(4));
    }
    lignes.push("file '" + path.basename(imgs[imgs.length - 1].fichier) + "'");
    const liste = path.join(this.dossier, 'liste.txt');
    fs.writeFileSync(liste, lignes.join('\n') + '\n');
    const mp4 = path.join(this.sortie, this.nom + '.mp4');
    execFileSync('ffmpeg', ['-y', '-hide_banner', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', liste,
      '-vf', 'scale=1920:1080:flags=lanczos,format=yuv420p', '-r', '25', '-c:v', 'libx264', '-preset', 'slow', '-crf', String(this.opts.crf || 28),
      '-tune', 'stillimage', '-movflags', '+faststart', mp4], { cwd: this.dossier, stdio: 'inherit' });
    const duree = imgs[imgs.length - 1].t - imgs[0].t + 1;
    console.log('  vidéo :', mp4, '·', imgs.length, 'images ·', duree.toFixed(1), 's', '· (filmé', fin.toFixed(1), 's)');
    return mp4;
  }
}

module.exports = { Film, W, H };
