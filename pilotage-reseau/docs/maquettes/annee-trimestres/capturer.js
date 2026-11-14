/* Dashboard magasin : Trimestre et Année réunis en un onglet « Année », et l'année simplifiée — maquette.
 *
 * L'écran est le VRAI dashboard (public/dashboard/), servi depuis le dépôt et nourri par les VRAIES lectures de
 * l'API du cockpit (Halle, shop 4) : la variante est posée par-dessus, dans le DOM, juste avant la capture.
 * La session est simulée (siège) pour la seule route /acces/moi ; tout le reste est relayé à l'API en ligne.
 *
 *   php -S 127.0.0.1:8096 -t pilotage-reseau/public          (depuis la racine du dépôt)
 *   node pilotage-reseau/docs/maquettes/annee-trimestres/capturer.js
 *
 * Écrit : avant-trimestre.png, avant-annee.png, apres-annee.png, apres-annee-t2.png, apres-plis.png, apres-annee-n1.png */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const ICI = __dirname;
const LOCAL = process.env.MQ_LOCAL || 'http://127.0.0.1:8096';
const PROD = process.env.MQ_API || 'http://185.180.206.46/consulant_bo/api/cockpit';
const SHOP = process.env.MQ_SHOP || '4';            // Halle : ouvert en 2026, sans année précédente
const SHOP_N1 = process.env.MQ_SHOP_N1 || '3';      // un magasin qui a vendu toute l'année précédente

/* --- la variante, construite DANS la page à partir des mêmes lectures que le dashboard --- */
async function variante(page, q, SHOP) {
  await page.evaluate(async ({ SHOP, q }) => {
    const Y = new Date().getFullYear();
    const lire = u => fetch('../api/cockpit' + u, { headers: { Accept: 'application/json' } }).then(r => r.json());
    const [plan, perf] = await Promise.all([
      lire('/plan?shop=' + SHOP + '&exercice=' + Y),
      lire('/stores/perf?granularite=mois&annees=' + (Y - 1) + ',' + Y + '&pro=' + SHOP),
    ]);
    const fK = v => v == null ? '—' : (Math.round(v / 100) / 10).toLocaleString('fr-BE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' k€';
    const fSK = v => v == null ? '—' : (v >= 0 ? '+ ' : '− ') + fK(Math.abs(v));
    const fP = v => v == null ? '—' : Math.round(v) + ' %';
    const fN = v => v == null ? '—' : Math.round(v).toLocaleString('fr-BE');
    const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    // les mêmes seuils que le dashboard : rouge < 80, orange 80–95, vert 95–105, doré > 105
    const col = p => p == null ? '#B9B2A8' : p < 80 ? '#8D1D2C' : p < 95 ? '#D98A0B' : p < 105 ? '#7CB342' : '#B0792A';
    const MO = ['jan', 'fév', 'mar', 'avr', 'mai', 'juin', 'juil', 'août', 'sep', 'oct', 'nov', 'déc'];
    const auj = new Date(), mAuj = auj.getMonth() + 1;
    const cells = (Array.isArray(perf) ? perf : []).filter(c => String(c.storeId) === SHOP);
    const cell = (an, m) => cells.find(c => +c.annee === an && +c.mois === m) || null;
    const bud = c => c ? (c.caBudget || c.caTheorique || 0) : 0;
    const A = plan.annee || {}, T = plan.trimestres || [];
    const tSel = T.find(t => +t.t === q) || T.find(t => t.enCours) || T[0];

    // --- ce qu'on garde de la page actuelle, plié : le tableau des mois, le trafic, la rampe
    const secs = [...document.querySelectorAll('.db-sec')];
    const racine = secs[0].parentElement;
    const apres = (sec) => { const out = []; let n = sec.nextElementSibling; while (n && !n.classList.contains('db-sec')) { out.push(n); n = n.nextElementSibling; } return out; };
    const secDe = mot => secs.find(s => s.textContent.includes(mot));
    const secTrafic = secDe('Le trafic') || null;
    const trafic = secTrafic ? [secTrafic, ...apres(secTrafic)] : [];
    const cartes = secDe('Les mois') ? apres(secDe('Les mois')) : [];
    const tableMois = cartes.find(c => c.textContent.includes('mois par mois'));
    const rampe = [...document.querySelectorAll('.db-card')].find(c => c.textContent.includes('La rampe à 5 ans'));
    let n = secs[0]; const vieux = []; while (n) { vieux.push(n); n = n.nextElementSibling; }
    [...racine.querySelectorAll(':scope > .db-err')].forEach(e => vieux.push(e));

    // --- 1. l'onglet Trimestre disparaît
    const bT = document.querySelector('.db-ong button[data-vue="trimestre"]'); if (bT) { bT.remove(); }

    // --- 2. l'objectif de l'année, en une carte
    const pR = A.objectif ? 100 * A.realise / A.objectif : 0, pA = A.objectif && A.attendu != null ? 100 * A.attendu / A.objectif : null;
    const pPr = A.objectif && A.projection != null ? 100 * A.projection / A.objectif : null;
    const hero = `<div class="db-card mq-hero" id="mq-hero">
      <div class="mq-hero-t"><div><span class="db-lab">Objectif ${Y} · ${A.objectifSource === 'rampe' ? 'rampe de l’étude, à engager' : 'engagement du franchisé'}</span><b>${fK(A.objectif)}</b></div>
        <div class="mq-hero-a">${fP(A.partEcoulee)} de l’année écoulée</div></div>
      <div class="mq-barre"><i style="width:${Math.min(100, pR).toFixed(1)}%"></i>${pPr != null ? `<s style="left:${Math.min(100, pR).toFixed(1)}%;width:${Math.max(0, Math.min(100, pPr) - Math.min(100, pR)).toFixed(1)}%"></s>` : ''}${pA != null ? `<b style="left:${Math.min(100, pA).toFixed(1)}%"><em>attendu à ce jour</em></b>` : ''}</div>
      <div class="mq-3">
        <div><span class="db-lab">Réalisé</span><b>${fK(A.realise)}</b><span>${fP(pR)} de l’objectif</span></div>
        <div class="${A.ecart >= 0 ? 'bon' : 'vif'}"><span class="db-lab">Écart à ce jour</span><b>${fSK(A.ecart)}</b><span>${A.ecart >= 0 ? 'en avance' : 'en retard'} sur l’attendu (${fK(A.attendu)})</span></div>
        <div class="${A.projection >= A.objectif ? 'bon' : 'vif'}"><span class="db-lab">Projection fin d’année</span><b>${fK(A.projection)}</b><span>${A.objectif ? fSK(A.projection - A.objectif) + ' sur l’objectif' : 'au rythme actuel'}</span></div>
      </div></div>`;

    // --- 3. les quatre trimestres côte à côte
    const mini = t => { const mx = Math.max(...t.mois.map(m => Math.max(bud(cell(Y, m)), (cell(Y, m) || {}).ca || 0)), 1);
      return `<div class="mq-mini">${t.mois.map(m => { const c = cell(Y, m), b = bud(c), ca = (c || {}).ca || 0, fut = m > mAuj && Y === auj.getFullYear(), enc = m === mAuj && Y === auj.getFullYear();
        return `<div><div class="bb"><i class="b" style="height:${(46 * b / mx).toFixed(0)}px"></i>${fut ? '' : `<i class="r" style="height:${(46 * ca / mx).toFixed(0)}px;background:${enc ? 'var(--prize)' : col(b ? 100 * ca / b : null)}"></i>`}</div><span>${MO[m - 1]}</span></div>`; }).join('')}</div>`; };
    const carteT = t => { const att = t.attendu ? 100 * (t.realise || 0) / t.attendu : null;
      const tag = t.enCours ? '<span class="db-tg enc">en cours</span>' : (t.clos ? '<span class="db-tg">clos</span>' : '<span class="db-tg fut">à venir</span>');
      return `<button type="button" class="mq-q${+t.t === +tSel.t ? ' on' : ''}" data-mqq="${t.t}">
        <div class="hd"><b>T${t.t}</b>${tag}${att != null && !t.futur ? `<span class="db-pill" style="background:${col(att)}">${fP(att)}</span>` : ''}</div>
        <div class="v">${t.futur ? '—' : fK(t.realise)}<span> / ${fK(t.objectif)}</span></div>
        ${mini(t)}
        <div class="s">${t.ecart == null ? (t.futur ? 'trimestre à venir' : '') : `<b style="color:${t.ecart >= 0 ? 'var(--green)' : 'var(--color-primary)'}">${fSK(t.ecart)}</b> sur l’attendu`}${t.netPct != null && !t.futur ? ' · marge nette ' + fP(t.netPct) : ''}</div>
      </button>`; };
    const quatre = `<div class="mq-quatre" id="mq-quatre">${T.map(carteT).join('')}</div>`;

    // --- 4. le détail du trimestre choisi (l'ex-onglet Trimestre)
    const t = tSel, att = t.attendu ? 100 * (t.realise || 0) / t.attendu : null;
    const M = t.mois.map(m => cell(Y, m) || { mois: m });
    const mx = Math.max(...M.map(x => Math.max(bud(x), x.ca || 0)), 1);
    const voix = [['franchise', 'Franchisé', 'fr'], ['consultant', 'Consultant', 'co'], ['marque', 'Marque', 'ma']];
    const notes = t.notes || {}, acts = (plan.actions || []).filter(a => +a.trimestre === +t.t), et = (t.revue && t.revue.etape) || 1;
    const lesNotes = voix.filter(v => notes[v[0]] && notes[v[0]].texte).map(v => `<div class="mq-note-v"><b>${v[1]}</b> — ${esc(notes[v[0]].texte.length > 220 ? notes[v[0]].texte.slice(0, 220) + '…' : notes[v[0]].texte)}</div>`).join('');
    const detail = `<div class="db-card mq-det" id="mq-det">
      <div class="ct"><span class="db-lab">Le trimestre T${t.t} ${Y}${t.enCours ? ' — en cours' : (t.clos ? ' — clos' : ' — à venir')}</span><span class="db-mini">${t.mois.map(m => MO[m - 1]).join(' · ')} · objectif du plan de développement</span></div>
      <div class="mq-det-g">
        <div class="db-mo">${M.map(x => { const b = bud(x), ca = x.ca || 0, pct = b ? 100 * ca / b : null, enc = +x.mois === mAuj, fut = +x.mois > mAuj;
          return `<div><div class="bars"><i class="b" style="height:${(140 * b / mx).toFixed(0)}px"></i>${fut ? '' : `<i class="r ${enc ? 'enc' : (pct != null && pct >= 95 ? 'ok' : '')}" style="height:${(140 * ca / mx).toFixed(0)}px"></i>`}</div><div class="n">${MO[x.mois - 1]} · ${fut ? '—' : fK(ca)}</div><div class="d">budget ${fK(b)}${pct != null && !fut ? ' · ' + fP(pct) : ''}${enc ? ' · en cours' : ''}</div></div>`; }).join('')}</div>
        <dl class="mq-kv">
          <div><dt>Objectif</dt><dd>${fK(t.objectif)}</dd></div>
          <div><dt>Réalisé</dt><dd>${fK(t.realise)} <span>${att != null ? fP(att) + ' de l’attendu' : ''}</span></dd></div>
          <div><dt>Attendu à ce jour</dt><dd>${fK(t.attendu)}</dd></div>
          <div><dt>Écart</dt><dd style="color:${t.ecart >= 0 ? 'var(--green)' : 'var(--color-primary)'}">${fSK(t.ecart)}</dd></div>
          <div><dt>Clients manquants</dt><dd>${t.clients == null ? '—' : (t.clients > 0 ? fN(t.clients) : 'aucun')} <span>${t.clients != null && t.clients < 0 ? fN(-t.clients) + ' clients d’avance · ' : ''}${t.panier ? 'au panier ' + t.panier.toLocaleString('fr-BE', { minimumFractionDigits: 2 }) + ' €' : ''}</span></dd></div>
          <div><dt>Marge nette</dt><dd>${fP(t.netPct)} <span>${t.food != null ? 'food ' + fP(t.food) : ''}${t.labour != null ? ' · labour ' + fP(t.labour) : ''}</span></dd></div>
        </dl>
        <div class="mq-ann"><div class="db-lab">Annotations</div>${lesNotes || '<div class="db-mini" style="font-style:italic;margin:6px 0">pas encore d’annotation</div>'}
          <div class="db-lab" style="margin-top:10px">Actions</div>${acts.length ? acts.map(a => `<div class="mq-act">${esc(a.libelle)}${a.responsable ? ' <span>· ' + esc(a.responsable) + '</span>' : ''}</div>`).join('') : '<div class="db-mini" style="margin:4px 0">aucune action posée</div>'}
          <div class="db-rit">Rituel du trimestre : ${[1, 2, 3, 4, 5].map(i => `<i class="${i <= et ? 'on' : ''}"></i>`).join('')} étape ${et} / 5</div></div>
      </div></div>`;

    // --- 5. les douze mois en une bande, l'an passé en filigrane
    const aN1 = cells.some(c => +c.annee === Y - 1 && c.ca);
    const m1 = cells.filter(c => +c.annee === Y && c.ca).map(c => +c.mois).sort((a, b) => a - b)[0];
    const premier = m1 ? MO[m1 - 1] + ' ' + Y : String(Y);
    const mx12 = Math.max(...cells.filter(c => +c.annee >= Y - 1).map(c => Math.max(c.ca || 0, bud(c))), 1);
    const bande = `<div class="db-card" id="mq-bande"><div class="ct"><span class="db-lab">Les 12 mois · ${Y} face à ${Y - 1}</span><span class="db-mini">barre : CA ${Y}, couleur = atteinte du budget · or : mois en cours · ${aN1 ? 'trait gris : ' + (Y - 1) + ', même mois' : 'pas de ' + (Y - 1) + ' : premier mois vendu en ' + premier}</span></div>
      <div class="mq-bande">${[1, 2, 3, 4].map(qq => `<div class="mq-tq${+qq === +tSel.t ? ' on' : ''}"><div class="mq-tq-l">T${qq}</div><div class="mq-tq-m">${[0, 1, 2].map(k => { const m = (qq - 1) * 3 + k + 1, c = cell(Y, m), p = cell(Y - 1, m), b = bud(c), ca = (c || {}).ca || 0, fut = m > mAuj, enc = m === mAuj, pct = b && !fut ? 100 * ca / b : null;
        return `<div class="mq-m"><div class="bb">${p && p.ca ? `<u style="bottom:${(100 * p.ca / mx12).toFixed(1)}%" title="${Y - 1} : ${fK(p.ca)}"></u>` : ''}${fut ? '' : `<i style="height:${(100 * ca / mx12).toFixed(1)}%;background:${enc ? 'var(--prize)' : col(pct)}"></i>`}</div>
          <div class="n">${MO[m - 1]}</div><div class="d">${fut ? '' : fK(ca)}</div><div class="d">${pct == null ? '' : `<span style="color:${enc ? 'var(--prize-ink)' : col(pct)}">${fP(pct)}</span>`}</div><div class="d ev">${p && p.ca && !fut && !enc ? `${ca >= p.ca ? '+' : '−'} ${Math.abs(Math.round(100 * (ca / p.ca - 1)))} % sur ${Y - 1}` : (p && p.ca && enc ? 'mois en cours' : '')}</div></div>`; }).join('')}</div></div>`).join('')}</div></div>`;

    // --- 6. replié : le détail des mois, le trafic, la rampe
    const pli = (id, titre, sous, noeuds) => { const d = document.createElement('details'); d.className = 'mq-pli'; d.id = id;
      d.innerHTML = `<summary><span>${titre}</span><small>${sous}</small></summary>`; const box = document.createElement('div'); box.className = 'mq-pli-in'; noeuds.filter(Boolean).forEach(x => box.appendChild(x)); d.appendChild(box); return d; };

    vieux.forEach(e => e.remove());
    const h = document.createElement('div');
    h.innerHTML = `<div class="db-sec">L’année ${Y}<small>l’objectif, les quatre trimestres, les douze mois — cliquer un trimestre pour l’ouvrir</small></div>${hero}${quatre}${detail}${bande}`;
    [...h.childNodes].forEach(x => racine.appendChild(x));
    // le trafic face à l'an passé reste visible, tel qu'aujourd'hui
    trafic.forEach(x => racine.appendChild(x)); if (trafic[1]) { trafic[1].id = 'mq-trafic'; }
    racine.appendChild(pli('mq-pli-mois', 'Le détail des mois', 'CA, budget, clients, panier, food, labour, overhead, marge nette', [tableMois]));
    racine.appendChild(pli('mq-pli-rampe', 'Le long terme', 'la rampe à 5 ans de l’étude de marché, et les engagements', [rampe]));
  }, { SHOP, q });
}

/* --- le bandeau de la maquette et les repères numérotés --- */
async function annoter(page, etapes) {
  await page.evaluate((etapes) => {
    const st = document.createElement('style');
    st.textContent = `
      .mq-bandeau{background:#FBEFE0;border:1px dashed #D9B489;border-radius:12px;padding:12px 16px;margin:10px 0 4px;font:12.5px/1.55 var(--font-ui);color:var(--color-text)}
      .mq-bandeau .tag{display:inline-block;background:var(--color-primary);color:#fff;font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;border-radius:999px;padding:2px 9px;margin-right:8px;vertical-align:1px}
      .mq-bandeau ol{margin:7px 0 0;padding:0;list-style:none;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 26px}
      .mq-bandeau li{display:flex;gap:8px;align-items:flex-start}
      .mq-n{flex:none;display:inline-flex;align-items:center;justify-content:center;width:19px;height:19px;border-radius:50%;background:#1f5f8b;color:#fff;font:700 10.5px var(--font-ui);box-shadow:0 0 0 2px #fff}
      .mq-pos{position:absolute;z-index:500}`;
    document.head.appendChild(st);
    const sec = document.querySelector('.db-sec');
    const b = document.createElement('div'); b.className = 'mq-bandeau';
    b.innerHTML = `<span class="tag">Maquette</span><b>Trimestre et Année réunis dans un seul onglet « Année », et l’année simplifiée</b><ol>${etapes.map((e, i) => `<li><span class="mq-n">${i + 1}</span><span>${e[1]}</span></li>`).join('')}</ol>`;
    sec.parentElement.insertBefore(b, sec);
    etapes.forEach((e, i) => { const el = document.querySelector(e[0]); if (!el) { return; } const r = el.getBoundingClientRect(), s = document.createElement('span');
      s.className = 'mq-n mq-pos'; s.textContent = i + 1; s.style.left = (r.left + window.scrollX - 9) + 'px'; s.style.top = (r.top + window.scrollY - 9) + 'px'; document.body.appendChild(s); });
  }, etapes);
}

const STYLE_VARIANTE = `
  .mq-hero{padding:16px 20px 18px}
  .mq-hero-t{display:flex;align-items:flex-end;justify-content:space-between;gap:12px}
  .mq-hero-t b{display:block;font:400 30px/1.1 var(--font-display);margin-top:4px;font-variant-numeric:tabular-nums}
  .mq-hero-a{font:400 12px var(--font-ui);color:var(--color-text-muted)}
  .mq-barre{position:relative;height:16px;border-radius:8px;background:var(--color-background-secondary);margin:14px 0 22px}
  .mq-barre i{position:absolute;left:0;top:0;bottom:0;border-radius:8px;background:var(--color-primary)}
  .mq-barre s{position:absolute;top:0;bottom:0;background:repeating-linear-gradient(135deg,rgba(141,29,44,.28) 0 3px,transparent 3px 7px)}
  .mq-barre b{position:absolute;top:-4px;bottom:-4px;width:2px;background:var(--color-text)}
  .mq-barre b em{position:absolute;top:22px;left:50%;transform:translateX(-50%);white-space:nowrap;font:400 10.5px var(--font-ui);font-style:normal;color:var(--color-text-muted)}
  .mq-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
  .mq-3>div{border:1px solid var(--color-border-tertiary);border-radius:11px;padding:10px 14px}
  .mq-3>div.vif{border-color:var(--color-primary);background:rgba(141,29,44,.05)}.mq-3>div.vif b{color:var(--color-primary)}
  .mq-3>div.bon{border-color:rgba(63,122,82,.3);background:#f0f7f2}.mq-3>div.bon b{color:var(--green)}
  .mq-3 b{display:block;font:400 22px/1.15 var(--font-display);margin:3px 0 2px;font-variant-numeric:tabular-nums}
  .mq-3 span:last-child{font:400 11px var(--font-ui);color:var(--color-text-muted)}
  .mq-quatre{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:10px}
  .mq-q{all:unset;box-sizing:border-box;cursor:pointer;background:var(--color-surface);border:1px solid var(--color-border-tertiary);border-radius:12px;padding:12px 14px;display:flex;flex-direction:column;gap:6px;font-family:var(--font-ui)}
  .mq-q:hover{border-color:var(--color-border-secondary)}
  .mq-q.on{border:2px solid var(--color-primary);padding:11px 13px;background:rgba(141,29,44,.03)}
  .mq-q .hd{display:flex;align-items:center;gap:4px}.mq-q .hd b{font:400 16px var(--font-display)}.mq-q .hd .db-pill{margin-left:auto;font-size:11px;padding:2px 8px}
  .mq-q .v{font:400 20px var(--font-display);font-variant-numeric:tabular-nums}.mq-q .v span{font:400 12px var(--font-ui);color:var(--color-text-muted)}
  .mq-q .s{font:400 11px var(--font-ui);color:var(--color-text-muted)}
  .mq-mini{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
  .mq-mini>div{text-align:center}.mq-mini span{font:400 10px var(--font-ui);color:var(--color-text-muted)}
  .mq-mini .bb{height:48px;display:flex;align-items:flex-end;justify-content:center;gap:3px;border-bottom:1px solid var(--color-border-tertiary)}
  .mq-mini .bb i{display:block;width:12px;border-radius:3px 3px 0 0;min-height:1px}.mq-mini .bb i.b{background:#e9e2d8}
  .mq-det-g{display:grid;grid-template-columns:1.3fr .9fr 1.1fr;gap:6px;align-items:start}
  .mq-kv{margin:0;padding:14px 16px;display:flex;flex-direction:column;gap:9px;border-left:1px solid var(--color-border-tertiary);border-right:1px solid var(--color-border-tertiary)}
  .mq-kv div{display:flex;justify-content:space-between;gap:8px;font:400 12.5px var(--font-ui)}.mq-kv dt{color:var(--color-text-muted)}.mq-kv dd{margin:0;text-align:right;font-variant-numeric:tabular-nums}
  .mq-kv dd span{display:block;font-size:10.5px;color:var(--color-text-muted)}
  .mq-ann{padding:12px 16px;font:400 12px/1.45 var(--font-ui)}
  .mq-note-v{margin:6px 0}.mq-act{margin:4px 0}.mq-act span{color:var(--color-text-muted)}
  .mq-bande{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;padding:12px 16px 14px}
  .mq-tq{border-radius:10px;padding:6px 6px 4px;background:var(--color-background-secondary)}
  .mq-tq.on{background:rgba(141,29,44,.06);box-shadow:inset 0 0 0 1px rgba(141,29,44,.25)}
  .mq-tq-l{font:400 10px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:var(--color-text-muted);padding:0 4px 4px}
  .mq-tq-m{display:grid;grid-template-columns:repeat(3,1fr);gap:4px}
  .mq-m{text-align:center;min-width:0}
  .mq-m .bb{position:relative;height:120px;display:flex;align-items:flex-end;justify-content:center;border-bottom:1px solid var(--color-border-tertiary)}
  .mq-m .bb i{display:block;width:60%;max-width:34px;border-radius:4px 4px 0 0;min-height:1px}
  .mq-m .bb u{position:absolute;left:12%;right:12%;height:0;border-top:2px solid #8a847c;opacity:.55}
  .mq-m .n{font:400 11.5px var(--font-ui);margin-top:5px}.mq-m .d{font:400 10.5px var(--font-ui);color:var(--color-text-muted);font-variant-numeric:tabular-nums}
  .mq-m .d .ev{color:var(--color-text-muted)}
  .mq-pli{background:var(--color-surface);border:1px solid var(--color-border-tertiary);border-radius:12px;margin-bottom:10px}
  .mq-pli>summary{cursor:pointer;list-style:none;padding:12px 16px;display:flex;align-items:baseline;gap:10px;font:400 14px var(--font-display)}
  .mq-pli>summary::before{content:'▸';font-size:11px;color:var(--color-text-muted)}.mq-pli[open]>summary::before{content:'▾'}
  .mq-pli>summary small{font:400 11.5px var(--font-ui);color:var(--color-text-muted)}
  .mq-pli-in{padding:0 12px 4px}.mq-pli-in>.db-card{margin-bottom:10px}`;

async function principal() {
  const browser = await pw.chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-BE', deviceScaleFactor: 1 });
  const cache = new Map();
  await ctx.route('**/api/cockpit/**', async (route) => {
    const u = new URL(route.request().url());
    const ch = u.pathname.replace(/^.*\/api\/cockpit/, '') + u.search;
    if (ch.startsWith('/acces/moi')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ connecte: true, role: 'siege', nom: 'Maquette', shops: [] }) });
    }
    if (!cache.has(ch)) {
      try {
        const r = await route.fetch({ url: PROD + ch, timeout: 120000 });
        cache.set(ch, { status: r.status(), body: await r.body(), type: r.headers()['content-type'] || 'application/json' });
      } catch (e) { cache.set(ch, { status: 502, body: Buffer.from('{"error":"lecture impossible"}'), type: 'application/json' }); }
    }
    const c = cache.get(ch);
    return route.fulfill({ status: c.status, contentType: c.type, body: c.body });
  });
  const page = await ctx.newPage();
  const ouvrir = async (vue, shop) => {
    await page.goto(LOCAL + '/dashboard/?shop=' + (shop || SHOP) + '&vue=' + vue, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.querySelectorAll('.db-sec').length >= 1 && document.querySelectorAll('.db-sk').length === 0, null, { timeout: 180000 });
    await page.waitForTimeout(800);
  };
  const zone = (de, a) => page.evaluate(([de, a]) => { const r1 = document.querySelector(de).getBoundingClientRect(), r2 = document.querySelector(a).getBoundingClientRect();
    return { x: 0, y: Math.max(0, r1.top + window.scrollY - 16), width: document.documentElement.clientWidth, height: r2.bottom - r1.top + 32 }; }, [de, a]);
  // l'avant : les deux onglets d'aujourd'hui
  await ouvrir('trimestre');
  await page.screenshot({ path: path.join(ICI, 'avant-trimestre.png'), fullPage: true });
  await ouvrir('annee');
  await page.screenshot({ path: path.join(ICI, 'avant-annee.png'), fullPage: true });
  // l'après : un seul onglet, le trimestre en cours ouvert
  await page.addStyleTag({ content: STYLE_VARIANTE });
  await variante(page, 0, SHOP);
  const etapes = [
    ['.db-ong button[data-vue="annee"]', 'Un seul onglet <b>Année</b> : l’onglet Trimestre disparaît (une adresse <code>vue=trimestre</code> ouvre l’Année sur ce trimestre).'],
    ['#mq-hero', 'L’objectif en <b>une carte</b> : la barre (réalisé, hachures jusqu’à la projection, repère de l’attendu) et <b>3 chiffres</b> au lieu de 6 tuiles.'],
    ['#mq-quatre', 'Les <b>4 trimestres côte à côte</b> : réalisé sur objectif, atteinte, écart, marge, et leurs 3 mois en miniature. Un clic ouvre le détail.'],
    ['#mq-det', 'Le <b>détail du trimestre choisi</b> — tout l’ex-onglet Trimestre : ses 3 mois, ses chiffres, les annotations des trois voix, les actions, le rituel.'],
    ['#mq-bande', 'Les <b>12 mois en une bande</b> rangée par trimestre, l’an passé en trait gris : remplace la heatmap sur 24 mois.'],
    ['#mq-trafic', 'Le <b>trafic clients face à l’an passé</b> reste tel quel, visible : clients, panier moyen, articles par client.'],
    ['#mq-pli-mois', '<b>Replié</b>, un clic pour l’ouvrir : le tableau des mois et la rampe à 5 ans.'],
  ];
  await annoter(page, etapes);
  await page.screenshot({ path: path.join(ICI, 'apres-annee.png'), fullPage: true });
  // le même écran, un autre trimestre choisi (T2, clos)
  await ouvrir('annee');
  await page.addStyleTag({ content: STYLE_VARIANTE });
  await variante(page, 2, SHOP);
  await page.screenshot({ path: path.join(ICI, 'apres-annee-t2.png'), fullPage: true, clip: await zone('#mq-quatre', '#mq-bande') });
  // les plis ouverts
  await page.evaluate(() => document.querySelectorAll('.mq-pli').forEach(d => { d.open = true; }));
  await page.waitForTimeout(600);
  await page.screenshot({ path: path.join(ICI, 'apres-plis.png'), fullPage: true, clip: await zone('#mq-pli-mois', '#mq-pli-rampe') });
  // un magasin ouvert avant l'an passé : le trait gris de l'année précédente dans la bande des 12 mois
  if (SHOP_N1) {
    await ouvrir('annee', SHOP_N1);
    await page.addStyleTag({ content: STYLE_VARIANTE });
    await variante(page, 0, SHOP_N1);
    await page.screenshot({ path: path.join(ICI, 'apres-annee-n1.png'), fullPage: true, clip: await zone('.db-sec', '#mq-trafic') });
  }
  await browser.close();
  console.log('captures écrites dans', ICI);
}
principal().catch(e => { console.error('ÉCHEC', e); process.exit(1); });
