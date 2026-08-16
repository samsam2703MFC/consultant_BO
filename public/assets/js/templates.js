/* Cockpit CEO — gabarits HTML.
 * Traduction fidèle du template du prototype Design Component (Cockpit CEO.dc.html) :
 * mêmes styles inline, mêmes structures ; {{ x }} → ${c.x}, sc-for → map, sc-if → ternaire.
 * x = { A: onClick, C: onChange, I: onInput, DS: dragstart, DP: drop, EN: mouseenter, esc }.
 */

/* Entrée du rail : feuille (bouton simple) ou sous-menu (parent repliable +
   enfants indentés). Le badge s'affiche à droite. */
function navBtn(n, x){
  const { esc } = x;
  const badge = b => b ? `<span style="min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:var(--color-primary);color:#fff;font-size:10px;font-weight:500;display:inline-flex;align-items:center;justify-content:center">${b}</span>` : '';
  if (n.type === 'sub') {
    return `
      <button ${x.A(n.toggle)} style="${n.st}">
        <span style="display:flex;align-items:center;gap:7px"><span style="font-size:9px;color:var(--color-text-muted);width:8px;display:inline-block">${n.chevron}</span>${esc(n.label)}</span>
        ${badge(n.badge)}
      </button>
      ${n.open ? (n.children || []).map(c => `
        <button ${x.A(c.go)} style="${c.st}">
          <span>${esc(c.label)}</span>
          ${badge(c.badge)}
        </button>`).join('') : ''}`;
  }
  return `
    <button ${x.A(n.go)} style="${n.st}">
      <span>${esc(n.label)}</span>
      ${badge(n.badge)}
    </button>`;
}

export function render(c, x){
  const { esc } = x;
  if (c.gate) return tplGate(c.gate, x);
  return `
<div style="display:flex;height:100vh;overflow:hidden;background:var(--color-bg);font-family:var(--font-ui);color:var(--color-text)">

  <aside style="width:236px;flex:0 0 236px;background:var(--color-surface);border-right:0.5px solid var(--color-border-tertiary);display:flex;flex-direction:column;overflow-y:auto">
    <div style="padding:22px 20px 14px">
      <img src="${c.brandLogo}" alt="${esc(c.brandNom) || 'L’Atelier'}" style="width:176px;max-width:100%;height:auto;display:block">
      <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted);margin-top:8px">${esc(c.brandSub)}</div>
    </div>
    <nav style="flex:1;padding:0 12px 16px;display:flex;flex-direction:column;gap:18px">
      ${(c.nav || []).map(g => `
        <div style="display:flex;flex-direction:column;gap:2px">
          <div style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.09em;color:var(--color-text-muted);padding:0 10px 6px">${esc(g.titre)}</div>
          ${g.items.map(n => navBtn(n, x)).join('')}
        </div>`).join('')}
    </nav>
    <div style="padding:14px 20px;border-top:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;gap:10px">
      <button ${x.A(c.userOpen)} title="Mon compte et le compte consultant" style="display:flex;align-items:center;gap:10px;flex:1;min-width:0;border:none;background:transparent;padding:0;cursor:pointer;text-align:left;font-family:var(--font-ui)">
        <div style="width:30px;height:30px;border-radius:50%;background:var(--color-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:500;flex:0 0 auto">${esc(c.userInit)}</div>
        <div style="flex:1;min-width:0">
          <div style="font-size:12px;font-weight:500;color:var(--color-text)">${esc(c.userNom)}</div>
          <div style="font-size:10px;color:var(--color-text-muted)">${esc(c.userRole)}${c.paEtatCourt ? ' · ' + esc(c.paEtatCourt) : ''}</div>
        </div>
      </button>
      ${c.canLogout ? `<button ${x.A(c.logout)} title="Se déconnecter" style="border:none;background:transparent;cursor:pointer;color:var(--color-text-muted);font-family:var(--font-ui);font-size:10.5px;font-weight:500;padding:4px 0" class="hv-line">Quitter</button>` : ''}
    </div>
  </aside>

  <main style="flex:1;overflow-y:auto;min-width:0" id="main-scroll">
    <div style="padding:26px 32px 60px;max-width:1460px">
      <header style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:22px">
        <div>
          <h1 style="font-family:var(--font-display);font-size:30px;font-weight:400;margin:0;line-height:1.15">${esc(c.screenTitle)}</h1>
          <p style="font-size:13px;color:var(--color-text-muted);margin:5px 0 0;max-width:640px">${esc(c.screenSub)}</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;white-space:nowrap">
          <span style="font-size:12px;color:var(--color-text-muted)">${esc(c.metaDate)}</span>
          <span style="font-size:11px;font-weight:500;padding:4px 10px;border-radius:999px;background:var(--color-secondary);color:var(--color-on-abricot)">${esc(c.metaPeriode)}</span>
        </div>
      </header>

      ${c.ready ? `
      ${c.isExploit ? tplExploitation(c, x) : ''}
      ${c.isMagasins ? tplMagasins(c, x) : ''}
      ${c.isHeatmap ? tplHeatmap(c, x) : ''}
      ${c.isObjectifs ? tplObjectifs(c, x) : ''}
      ${c.isBudget ? tplBudget(c, x) : ''}
      ${c.isMarge ? tplMarge(c, x) : ''}
      ${c.isEncodage ? tplEncodage(c, x) : ''}
      ${c.isProduits ? tplProduits(c, x) : ''}
      ${c.isProjets ? tplProjets(c, x) : ''}
      ${c.isControle ? tplControle(c, x) : ''}
      ${c.isTaches ? tplTaches(c, x) : ''}
      ${c.isReporting ? tplReporting(c, x) : ''}
      ${c.isSuivi ? tplSuivi(c, x) : ''}
      ${c.isJournal ? tplJournal(c, x) : ''}
      ${c.isParams ? tplParams(c, x) : ''}
      ${c.isScoring ? tplScoring(c, x) : ''}
      ` : `<div style="padding:60px 0;color:var(--color-text-muted);font-size:13px">Chargement des données du réseau…</div>`}
    </div>
  </main>

  ${c.op ? tplFicheProjet(c, x) : ''}
  ${c.rel ? tplRelance(c, x) : ''}
  ${c.repPrev ? tplRepPrev(c, x) : ''}
  ${c.eqRep ? tplEqRep(c, x) : ''}
  ${c.pdWaste ? tplPerteMagasins(c, x) : ''}
  ${c.userPanel ? tplUserPanel(c, x) : ''}
  ${c.ctrlDet ? tplCtrlDetail(c, x) : ''}
  ${c.np ? tplWizardProjet(c, x) : ''}
  ${c.nt ? tplWizardTache(c, x) : ''}

  ${c.toast ? `<div style="position:fixed;bottom:24px;right:24px;z-index:100;background:#222222;color:#fff;border-radius:10px;padding:12px 18px;font-size:13px;box-shadow:0 10px 30px rgba(34,34,34,0.3);animation:toastIn 200ms ease;max-width:420px">${esc(c.toast)}</div>` : ''}
</div>`;
}

/* --- Écran de connexion / premier lancement ---------------------------------- */
function tplGate(g, x){
  const { esc } = x;
  const logo = (typeof location !== 'undefined' ? location.pathname.replace(/[^/]*$/, '') : '') + 'assets/img/logo.png';
  return `
<div style="display:flex;align-items:center;justify-content:center;height:100vh;background:var(--color-bg);font-family:var(--font-ui);color:var(--color-text)">
  <form ${x.SB(g.submit)} style="width:380px;background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:14px;box-shadow:0 18px 50px rgba(34,34,34,0.10);padding:34px 36px 30px;animation:toastIn 220ms ease">
    <img src="${logo}" alt="L’Atelier" style="width:190px;max-width:100%;height:auto;display:block">
    <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted);margin-top:8px">Pilotage Réseau</div>
    <div style="font-family:var(--font-display);font-size:19px;margin-top:22px">${esc(g.titre)}</div>
    <div style="font-size:12.5px;color:var(--color-text-muted);line-height:1.55;margin-top:5px;text-wrap:pretty">${esc(g.sub)}</div>
    <label style="display:block;font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin:18px 0 6px">Mot de passe</label>
    <input id="gate-pass" type="password" autocomplete="${g.mode === 'setup' ? 'new-password' : 'current-password'}" autofocus
      style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:11px 13px;font-family:var(--font-ui);font-size:14px;background:var(--color-background-secondary);color:var(--color-text)">
    ${g.err ? `<div style="margin-top:10px;padding:9px 12px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:12px;font-weight:500">${esc(g.err)}</div>` : ''}
    <button type="submit" class="hv-fade" style="width:100%;margin-top:16px;border:none;cursor:pointer;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:13.5px;font-weight:500;padding:12px 0;border-radius:999px">${esc(g.bouton)}</button>
  </form>
</div>`;
}

/* --- helpers locaux -------------------------------------------------------- */
const TH = 'text-align:left;font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:12px 14px;border-bottom:0.5px solid var(--color-border-tertiary)';
const TH2 = 'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:12px;border-bottom:0.5px solid var(--color-border-tertiary)';
const selCss = 'font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 8px;background:var(--color-surface);color:var(--color-text)';

function opts(list, val, getV, getN){
  return list.map(o => {
    const v = getV ? getV(o) : o, n = getN ? getN(o) : o;
    return `<option value="${String(v).replace(/"/g, '&quot;')}"${String(v) === String(val) ? ' selected' : ''}>${n}</option>`;
  }).join('');
}

/* --- Tableau des magasins --------------------------------------------------- */
/* Exploitation — une carte par magasin, avec le budget en regard du réel.
   Le graphique bascule entre mensuel et cumulé pour TOUTES les cartes à la
   fois : deux cartes réglées différemment se compareraient à leur insu. */
function tplExploitation(c, x){
  const { esc } = x;
  const carte = m => `
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:9px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
        <div>
          <button ${x.A(m.ouvrir)} title="Ouvrir le P&amp;L détaillé" style="border:none;background:transparent;padding:0;cursor:pointer;font-family:var(--font-ui);font-weight:500;font-size:13.5px;color:var(--color-text);text-align:left" class="hv-line">${esc(m.magasin)}</button>
          <div style="font-size:11.5px;color:var(--color-text-muted)">objectif du mois ${esc(m.objMois)}</div>
        </div>
        <span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:500;white-space:nowrap;${m.att.st}">${esc(m.att.txt)}</span>
      </div>
      <div style="display:flex;align-items:flex-end;gap:16px">
        <div>
          <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">CA du jour</div>
          <div style="font-family:var(--font-display);font-size:27px;line-height:1">${esc(m.jourCa)}</div>
        </div>
        <div style="font-size:11.5px;color:var(--color-text-muted);padding-bottom:3px">
          ${esc(m.jourClients)} clients<br>panier ${esc(m.jourPanier)}
        </div>
      </div>
      <div style="display:flex;gap:14px">
        <div style="flex:1">
          <div style="font-size:10.5px;color:var(--color-text-muted)">Semaine</div>
          <div style="font-weight:500">${esc(m.semCa)}</div>
          <div style="height:6px;border-radius:3px;background:#EDEAE5;overflow:hidden"><i style="display:block;height:100%;border-radius:3px;width:${m.semJauge.w};background:${m.semJauge.c}"></i></div>
        </div>
        <div style="flex:1">
          <div style="font-size:10.5px;color:var(--color-text-muted)">Mois</div>
          <div style="font-weight:500">${esc(m.moisCa)}</div>
          <div style="height:6px;border-radius:3px;background:#EDEAE5;overflow:hidden"><i style="display:block;height:100%;border-radius:3px;width:${m.moisJauge.w};background:${m.moisJauge.c}"></i></div>
        </div>
      </div>
      <div style="border-top:0.5px solid var(--color-border-tertiary);padding-top:9px">
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:3px">
          Budget vs réel — exercice ${esc(String(m.exercice))}${c.exCumul ? ' · cumulé' : ''}
        </div>
        ${m.gVide ? `<div style="padding:22px 0;text-align:center;font-size:11.5px;color:var(--color-text-muted)">aucun historique mensuel</div>` : `
        <svg viewBox="0 0 ${m.gW} ${m.gH}" style="width:100%;height:auto;display:block">
          ${m.gGrille.map(g => `<line x1="0" x2="${g.w}" y1="${g.y}" y2="${g.y}" stroke="rgba(34,34,34,0.09)" stroke-width="0.6"/>`).join('')}
          ${m.gCourbe ? `
            <line x1="0" x2="${m.gW}" y1="${m.gBase}" y2="${m.gBase}" stroke="rgba(34,34,34,0.15)" stroke-width="0.6"/>
            ${m.gCible ? `<polyline points="${m.gCible}" fill="none" stroke="#c9a06a" stroke-width="1.6" stroke-dasharray="3 2.4" stroke-linejoin="round" stroke-linecap="round"/>` : ''}
            ${m.gReel ? `<polyline points="${m.gReel}" fill="none" stroke="#8D1D2C" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>` : ''}
          ` : `
            ${m.gBarres.map(b => `<rect x="${b.x}" y="${b.y}" width="${b.w}" height="${b.h}" rx="1.4" fill="${b.fill}"/>`).join('')}
            ${m.gReperes.map(r => `<line x1="${r.x1}" x2="${r.x2}" y1="${r.y}" y2="${r.y}" stroke="#222" stroke-width="1.5" stroke-linecap="round"/>`).join('')}
          `}
          ${m.gLabels.map(l => `<text x="${l.x}" y="${l.y}" text-anchor="middle" font-size="7" fill="${l.c}">${esc(l.t)}</text>`).join('')}
        </svg>
        <div style="display:flex;justify-content:space-between;margin-top:2px;font-size:11.5px;color:var(--color-text-muted)">
          <span>max ${esc(m.gMax)}</span><span>${esc(m.gNote)}</span>
        </div>`}
      </div>
    </div>`;
  return `
    <svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
      <pattern id="exhach" width="4" height="4" patternTransform="rotate(45)" patternUnits="userSpaceOnUse">
        <rect width="4" height="4" fill="#D9B3B8"/>
        <line x1="0" y1="0" x2="0" y2="4" stroke="var(--color-primary)" stroke-width="1.6"/>
      </pattern>
    </defs></svg>
    ${c.exAvertissement ? `<div style="font-size:11.5px;color:var(--color-on-abricot);background:#FBEFE0;border:1px solid #E8C9A0;padding:7px 11px;border-radius:8px;margin-bottom:14px;display:inline-block">${esc(c.exAvertissement)}</div>` : ''}
    ${c.exVide ? `<div style="padding:50px 0;color:var(--color-text-muted);font-size:13px">Aucune vente enregistrée en caisse — l'écran se remplira dès la première remontée.</div>` : `
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:16px;margin-bottom:14px;display:flex;gap:32px;align-items:center;flex-wrap:wrap">
      <div>
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Réseau — mois</div>
        <div style="font-family:var(--font-display);font-size:27px;line-height:1">${esc(c.exRes.ca)}</div>
      </div>
      <div><div style="font-size:11.5px;color:var(--color-text-muted)">clients</div><div style="font-size:17px;font-weight:500">${esc(c.exRes.clients)}</div></div>
      <div><div style="font-size:11.5px;color:var(--color-text-muted)">panier moyen</div><div style="font-size:17px;font-weight:500">${esc(c.exRes.panier)}</div></div>
      <div style="flex:1;min-width:160px">
        <div style="font-size:11.5px;color:var(--color-text-muted)">objectif ${esc(c.exRes.objectif)}</div>
        <div style="height:6px;border-radius:3px;background:#EDEAE5;overflow:hidden"><i style="display:block;height:100%;border-radius:3px;width:${c.exRes.jauge.w};background:${c.exRes.jauge.c}"></i></div>
      </div>
      <span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:500;white-space:nowrap;${c.exRes.att.st}">${esc(c.exRes.att.txt)}</span>
      <div style="display:flex;gap:4px;background:var(--color-background-secondary);padding:3px;border-radius:10px">
        <button ${x.A(c.exVueMois)} style="${c.exStMois}">Par mois</button>
        <button ${x.A(c.exVueCumul)} style="${c.exStCumul}">Cumulé</button>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(460px,1fr));gap:14px">
      ${c.exMagasins.map(carte).join('')}
    </div>
    <div style="margin-top:16px;background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:16px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px;flex-wrap:wrap">
        <div>
          <div style="font-size:13px;font-weight:500">N vs N-1 — toutes les boutiques</div>
          <div style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.exNv.periode)}${c.exNv.source ? ' · ' + esc(c.exNv.source.split('?')[0]) : ''}</div>
        </div>
        <div style="display:flex;gap:3px;background:var(--color-background-secondary);padding:3px;border-radius:9px">
          ${c.exNvBtns.map(b => `<button ${x.A(b.go)} style="${b.st}">${esc(b.label)}</button>`).join('')}
        </div>
      </div>
      ${c.exNv.chargement ? `<div style="padding:18px 0;font-size:12.5px;color:var(--color-text-muted)">Lecture de l’API du panel…</div>`
        : (!c.exNv.lignes.length ? `<div style="padding:18px 0;font-size:12.5px;color:var(--color-text-muted)">${esc(c.exNv.motif || 'aucune donnée')}</div>` : `
      ${c.exNv.motif ? `<div style="font-size:11px;color:var(--color-on-abricot);background:#FBEFE0;border:1px solid #E8C9A0;padding:6px 9px;border-radius:7px;margin-bottom:8px">${esc(c.exNv.motif)}</div>` : ''}
      <table style="width:100%;border-collapse:collapse;font-size:12.5px">
        <thead><tr>
          <th style="text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 0 6px">Magasin</th>
          <th style="text-align:right;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 10px 6px">N</th>
          <th style="text-align:right;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 10px 6px">N-1</th>
          <th style="text-align:right;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 0 6px">Écart</th>
        </tr></thead>
        <tbody>${c.exNv.lignes.map(l => `<tr>
          <td style="padding:7px 0;border-top:0.5px solid var(--color-border-tertiary);font-weight:500">${esc(l.magasin)}</td>
          <td style="padding:7px 10px;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums">${esc(l.n)}</td>
          <td style="padding:7px 10px;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;color:var(--color-text-muted)">${esc(l.n1)}</td>
          <td style="padding:7px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;white-space:nowrap;color:${l.col};font-weight:500">${esc(l.ecart)}<i style="display:inline-block;width:7px;height:7px;border-radius:50%;background:${l.pt};margin-left:6px;vertical-align:1px"></i></td>
        </tr>`).join('')}</tbody>
      </table>`)}
    </div>
    ${c.exDetail ? tplExploitDetail(c, x) : ''}
    <div style="margin-top:11px;display:flex;gap:18px;flex-wrap:wrap;font-size:11.5px;color:var(--color-text-muted)">
      <span><i style="display:inline-block;width:9px;height:9px;background:var(--color-primary);border-radius:2px;vertical-align:-1px"></i> ${esc(c.exLegendeReel)}</span>
      <span>${c.exCumul
        ? `<i style="display:inline-block;width:14px;height:0;border-top:2px dashed #c9a06a;vertical-align:4px"></i>`
        : `<i style="display:inline-block;width:14px;height:2px;background:#222;vertical-align:3px"></i>`} ${esc(c.exLegendeCible)}</span>
      ${c.exCumul ? '' : `<span><i style="display:inline-block;width:9px;height:9px;background:#D9B3B8;border-radius:2px;vertical-align:-1px"></i> mois partiellement encodé</span>`}
      ${c.exBase ? `<span>objectif jour et semaine : ${esc(c.exBase)}</span>` : ''}
    </div>`}`;
}


/* P&L détaillé d'un magasin. Chaque bloc affiche soit ses données, soit la
   raison pour laquelle il n'en a pas : tant qu'un endpoint du panel n'a pas
   répondu, on écrit « en attente d'API » plutôt que de combler avec une autre
   source. Un écran qui annonce ce qui lui manque est réparable ; un écran
   rempli en douce ne l'est pas. */
function tplExploitDetail(c, x){
  const { esc } = x;
  const d = c.exDetail;
  return `
  <div style="margin-top:16px;background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:18px">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px">
      <div>
        <div style="font-family:var(--font-display);font-size:19px;line-height:1.2">${esc(d.nom)}</div>
        <div style="font-size:11.5px;color:var(--color-text-muted)">P&amp;L détaillé${d.du ? ' · ' + esc(d.du) + ' → ' + esc(d.au) : ''}</div>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <div style="display:flex;gap:3px;background:var(--color-background-secondary);padding:3px;border-radius:9px">
          ${c.exPerBtns.map(p => `<button ${x.A(p.go)} style="${p.st}">${esc(p.label)}</button>`).join('')}
        </div>
        <button ${x.A(d.close)} title="Fermer" style="border:none;background:transparent;cursor:pointer;color:var(--color-text-muted);font-size:16px;line-height:1;padding:2px 6px">×</button>
      </div>
    </div>
    ${d.chargement ? `<div style="padding:26px 0;color:var(--color-text-muted);font-size:12.5px">Lecture des API du panel…</div>`
      : (!d.blocs.length ? `<div style="padding:26px 0;color:var(--color-text-muted);font-size:12.5px">Aucune réponse du panel.</div>` : `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px">
      ${d.blocs.map(b => `
        <div style="border:0.5px solid var(--color-border-tertiary);border-radius:10px;padding:13px;background:${b.attente ? 'var(--color-background-secondary)' : 'var(--color-surface)'}">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px">
            <div style="font-size:12.5px;font-weight:500">${esc(b.titre)}</div>
            ${b.attente
              ? `<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:500;background:#FBEFE0;color:var(--color-on-abricot);white-space:nowrap">en attente d’API</span>`
              : `<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:500;background:#E6F2E9;color:#2d7a3e;white-space:nowrap">API</span>`}
          </div>
          ${b.attente
            ? `<div style="font-size:11.5px;color:var(--color-text-muted);line-height:1.5;word-break:break-word">${esc(b.motif)}</div>`
            : `<div style="font-size:10.5px;color:var(--color-text-muted);margin-bottom:8px;word-break:break-all">${esc(b.source)}</div>
               ${b.avert ? `<div style="font-size:11px;color:var(--color-on-abricot);background:#FBEFE0;border:1px solid #E8C9A0;padding:6px 9px;border-radius:7px;margin-bottom:8px;line-height:1.45">${esc(b.avert)}</div>` : ''}
               ${b.tuiles ? `<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                 ${b.tuiles.map(t => `<div style="background:var(--color-background-secondary);border-radius:8px;padding:9px 11px">
                   <div style="font-family:var(--font-display);font-size:18px;line-height:1.1">${esc(t.v)}</div>
                   <div style="font-size:10.5px;color:var(--color-text-muted)">${esc(t.l)}</div>
                 </div>`).join('')}</div>` : ''}
               ${b.cascade ? `<table style="width:100%;border-collapse:collapse;font-size:12.5px">
                 ${b.cascade.map(l => `<tr style="${l.fort ? 'font-weight:500' : ''}">
                   <td style="padding:5px 0;border-top:0.5px solid var(--color-border-tertiary)">${esc(l.l)}</td>
                   <td style="padding:5px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums">${esc(l.v)}</td>
                   <td style="padding:5px 0 5px 10px;border-top:0.5px solid var(--color-border-tertiary);text-align:right;color:var(--color-text-muted);font-variant-numeric:tabular-nums">${esc(l.p)}</td>
                 </tr>`).join('')}</table>` : ''}
               ${b.cats ? `<div style="display:flex;flex-direction:column;gap:6px">
                 ${b.cats.map(c => `<div>
                   <div style="display:flex;justify-content:space-between;gap:8px;font-size:11.5px">
                     <span>${esc(c.nom)}${c.marge ? `<span style="color:var(--color-text-muted)"> · ${esc(c.marge)}</span>` : ''}</span>
                     <span style="white-space:nowrap"><span style="font-variant-numeric:tabular-nums">${esc(c.ca)}</span>
                       <span style="color:var(--color-text-muted)"> · ${esc(c.part)}</span>
                       ${c.delta ? `<span style="color:${c.deltaC}"> ${esc(c.delta)}</span>` : ''}</span>
                   </div>
                   <div style="height:5px;border-radius:3px;background:#EDEAE5;overflow:hidden"><i style="display:block;height:100%;border-radius:3px;width:${c.w};background:${c.col}"></i></div>
                 </div>`).join('')}
                 <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:4px;font-size:10px;color:var(--color-text-muted)">
                   ${b.echelle.map(e => `<span><i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${e.c};vertical-align:-1px"></i> ${esc(e.l)}</span>`).join('')}
                 </div>
                 <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:3px">Largeur : poids dans le CA · Couleur : niveau de marge</div>
                 ${b.sansMarge ? `<div style="font-size:11px;color:var(--color-on-abricot);background:#FBEFE0;border:1px solid #E8C9A0;padding:6px 9px;border-radius:7px;margin-top:6px;line-height:1.45">l’API ne renvoie pas de food cost par catégorie : les barres restent grises, faute de marge connue</div>` : ''}
                 </div>` : ''}
               ${b.rang ? `<div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:5px">panier moyen réseau ${esc(b.rang.moyenne)}</div>
                 <table style="width:100%;border-collapse:collapse;font-size:12px">
                 ${b.rang.lignes.map(r => `<tr style="${r.moi ? 'font-weight:500;color:var(--color-primary)' : ''}">
                   <td style="padding:3px 0">${esc(r.magasin)}</td>
                   <td style="padding:3px 0;text-align:right;font-variant-numeric:tabular-nums">${esc(r.panier)}</td>
                   <td style="padding:3px 0 3px 10px;text-align:right;color:var(--color-text-muted)">${esc(r.ppc)}</td>
                 </tr>`).join('')}</table>` : ''}
               ${b.lignes.length ? `<table style="width:100%;border-collapse:collapse;font-size:11.5px;margin-top:6px">
                 ${b.lignes.map(l => `<tr>
                   <td style="padding:3px 0;color:var(--color-text-muted)">${esc(l.k)}</td>
                   <td style="padding:3px 0;text-align:right">${esc(l.v)}</td>
                 </tr>`).join('')}</table>` : ''}`}
        </div>`).join('')}
    </div>`)}
  </div>`;
}

function tplMagasins(c, x){
  const { esc } = x;
  return `
  <div data-screen="magasins" style="display:flex;flex-direction:column;gap:16px">
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
        <div style="font-size:13px;font-weight:500">Performance par magasin — ${c.storeHdrPeriode}</div>
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:12px;color:var(--color-text-muted)">Zone</span>
          <select ${x.C(c.setZoneF)} style="${selCss}">${opts(c.zoneOptions, c.zoneF)}</select>
        </div>
      </div>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:1080px">
          <thead><tr>
            ${c.storeCols.map(col => `<th ${x.A(col.sort)} style="${col.st}">${esc(col.label)}${col.arrow}</th>`).join('')}
          </tr></thead>
          <tbody>
            ${c.storeRows.map(r => `
              <tr style="border-bottom:0.5px solid var(--color-border-tertiary)">
                <td style="padding:10px 14px"><div style="font-weight:500">${esc(r.nom)}</div><div style="font-size:11px;color:var(--color-text-muted)">${esc(r.code)} · ${esc(r.fr)}</div></td>
                <td style="padding:10px 12px;text-align:right;white-space:nowrap">${r.marge}</td>
                <td style="padding:10px 12px;text-align:right;white-space:nowrap;color:var(--color-text-muted)">${r.margeN1}</td>
                <td style="padding:10px 12px;text-align:right"><span style="${r.margeVarSt}">${r.margeVar}</span></td>
                <td style="padding:10px 12px;text-align:right;white-space:nowrap">${r.val}<div style="font-size:10.5px;color:var(--color-text-muted)">cible ${r.valT}</div></td>
                <td style="padding:10px 12px;text-align:center"><span style="${r.valPctSt}">${r.valPct}</span></td>
                <td style="padding:10px 12px;text-align:right;white-space:nowrap">${r.ca}<div style="font-size:10.5px;color:var(--color-text-muted)">cible ${r.caT}</div></td>
                <td style="padding:10px 12px;text-align:center"><span style="${r.caPctSt}">${r.caPct}</span></td>
                <td style="padding:10px 12px;text-align:right;white-space:nowrap">${r.tickets}<div style="${r.tickEvoSt}">${r.tickEvo}</div></td>
                <td style="padding:10px 14px;text-align:right;white-space:nowrap">${r.panier}<div style="${r.panEvoSt}">${r.panEvo}</div></td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>
  </div>`;
}

/* --- Heatmap mensuelle ------------------------------------------------------ */
function tplHeatmap(c, x){
  const { esc } = x;
  const cell = cc => `<div ${x.EN(cc.enter)} style="${cc.st}">${cc.txt}</div>`;
  return `
  <div data-screen="heatmap" style="display:flex;flex-direction:column;gap:14px">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <div style="display:inline-flex;border:0.5px solid var(--color-border-secondary);border-radius:999px;overflow:hidden">
        <button ${x.A(c.hmMetricCa)} style="${c.hmBtnCaSt}">CA du mois</button>
        <button ${x.A(c.hmMetricPct)} style="${c.hmBtnPctSt}">% d'atteinte de l'objectif</button>
      </div>
      <div style="display:inline-flex;border:0.5px solid var(--color-border-secondary);border-radius:999px;overflow:hidden">
        <button ${x.A(c.hmY25)} style="${c.hmBtn25St}">${c.hmYearPrev}</button>
        <button ${x.A(c.hmY26)} style="${c.hmBtn26St}">${c.hmYearCur}</button>
      </div>
      <span style="font-size:12px;color:var(--color-text-muted)">${esc(c.hmNote)}</span>
    </div>
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:16px;overflow-x:auto">
      <div style="display:grid;grid-template-columns:190px repeat(12,minmax(56px,1fr));gap:3px;min-width:940px">
        <div></div>
        ${c.hmMois.map(m => `<div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted);text-align:center;padding:4px 0">${m}</div>`).join('')}
        ${c.hmRows.map(r => `
          <div style="font-size:12px;font-weight:500;display:flex;align-items:center;padding-right:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(r.nom)}</div>
          ${r.cells.map(cell).join('')}`).join('')}
        <div style="font-size:12px;font-weight:700;display:flex;align-items:center;border-top:2px solid var(--color-border-secondary);padding-top:6px;margin-top:3px">Réseau</div>
        ${c.hmReseau.map(cell).join('')}
      </div>
      <div style="margin-top:14px;min-height:20px;font-size:12.5px;color:var(--color-text-muted)">${esc(c.hmDetail)}</div>
    </div>
  </div>`;
}

/* --- Objectifs de CA -------------------------------------------------------- */
function tplObjectifs(c, x){
  const { esc } = x;
  return `
  <div data-screen="objectifs" style="display:flex;flex-direction:column;gap:16px">
    <div style="display:inline-flex;border:0.5px solid var(--color-border-secondary);border-radius:999px;overflow:hidden;align-self:flex-start">
      <button ${x.A(c.hz1)} style="${c.hz1St}">${c.hzLabel1}</button>
      <button ${x.A(c.hz3)} style="${c.hz3St}">${c.hzLabel3}</button>
      <button ${x.A(c.hz5)} style="${c.hz5St}">${c.hzLabel5}</button>
    </div>
    ${c.isH1 ? `
      <div style="display:grid;grid-template-columns:380px 1fr;gap:16px;align-items:start">
        <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)">Cible réseau ${c.objExo}</div>
          <div style="font-size:32px;font-weight:500;margin-top:6px">${c.objCible}</div>
          <div style="display:flex;flex-direction:column;gap:8px;margin-top:14px;font-size:13px">
            <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Réel à fin juillet</span><span style="font-weight:500">${c.objReel}</span></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Cible au prorata</span><span>${c.objProrata}</span></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Taux d'atteinte</span><span style="${c.objAttSt}">${c.objAtt}</span></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Projection fin ${c.objExo}</span><span style="font-weight:500">${c.objProj}</span></div>
          </div>
          <div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:14px;padding-top:12px;font-size:12px;color:var(--color-text-muted)">${esc(c.objNote)}</div>
          <div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:12px;padding-top:12px">
            <div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted);margin-bottom:8px">Cumul des budgets magasins</div>
            <div style="display:flex;flex-direction:column;gap:7px;font-size:13px">
              <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">${esc(c.cumLabel)}</span><span style="font-weight:500">${c.cumBudget}</span></div>
              <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">${esc(c.cumReelLabel)}</span><span style="font-weight:500">${c.cumReel}</span></div>
              <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Écart cumulé vs budget</span><span style="${c.cumEcartSt}">${c.cumEcart}</span></div>
              <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Magasins sous budget</span><span style="font-weight:500">${c.cumSous}</span></div>
            </div>
          </div>
        </div>
        <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
          <div style="display:flex;align-items:center;justify-content:space-between">
            <div style="font-size:13px;font-weight:500">Trajectoire cumulée ${c.objExo} — réel vs cible</div>
            <div style="display:flex;gap:14px;font-size:11px;color:var(--color-text-muted)">
              <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:14px;height:2px;background:#8D1D2C;display:inline-block"></span>Réel</span>
              <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:14px;height:2px;background:#c9a06a;display:inline-block"></span>Cible</span>
            </div>
          </div>
          <svg width="100%" height="220" viewBox="0 0 660 220" preserveAspectRatio="none" style="margin-top:10px">
            <line x1="20" y1="195" x2="640" y2="195" stroke="rgba(34,34,34,0.15)" stroke-width="1"></line>
            <polyline points="${c.trajCible}" fill="none" stroke="#c9a06a" stroke-width="2" stroke-dasharray="5 4"></polyline>
            <polyline points="${c.trajReel}" fill="none" stroke="#8D1D2C" stroke-width="2.5"></polyline>
          </svg>
          <div style="display:grid;grid-template-columns:repeat(12,1fr);font-size:10px;color:var(--color-text-muted);padding:0 8px">
            ${c.hmMois.map(m => `<span style="text-align:center">${m}</span>`).join('')}
          </div>
        </div>
      </div>
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px">
          <thead><tr>
            <th style="${TH}">Magasin</th>
            <th style="text-align:right;${TH2}">Cible ${c.objExo}</th>
            <th style="text-align:right;${TH2}">Réel à fin juil.</th>
            <th style="text-align:right;${TH2}">Cible prorata</th>
            <th style="text-align:center;${TH2}">Écart</th>
            <th style="text-align:center;${TH2}">Atteinte</th>
            <th style="text-align:right;${TH2.replace('padding:12px', 'padding:12px 14px')}">Suivi budget</th>
          </tr></thead>
          <tbody>
            ${c.objRows.map(r => `
              <tr style="border-bottom:0.5px solid var(--color-border-tertiary)">
                <td style="padding:10px 14px;font-weight:500">${esc(r.nom)}</td>
                <td style="padding:10px 12px;text-align:right">${r.cible}</td>
                <td style="padding:10px 12px;text-align:right">${r.reel}</td>
                <td style="padding:10px 12px;text-align:right;color:var(--color-text-muted)">${r.prorata}</td>
                <td style="padding:10px 12px;text-align:center"><span style="${r.ecartSt}">${r.ecart}</span></td>
                <td style="padding:10px 12px;text-align:center"><span style="${r.attSt}">${r.att}</span></td>
                <td style="padding:10px 14px;text-align:right"><button ${x.A(r.goBudget)} style="border:none;background:none;color:var(--color-primary);font-size:12px;font-weight:500;cursor:pointer;font-family:var(--font-ui);white-space:nowrap">Ouvrir →</button></td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>` : ''}
    ${c.isH35 ? `
      <div style="display:grid;grid-template-columns:380px 1fr;gap:16px;align-items:start">
        <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)">Cible réseau ${c.hzAn}</div>
          <div style="font-size:32px;font-weight:500;margin-top:6px">${c.hzCible}</div>
          <div style="display:flex;flex-direction:column;gap:8px;margin-top:14px;font-size:13px">
            <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Run-rate annuel actuel</span><span style="font-weight:500">${c.hzRunrate}</span></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Écart à combler</span><span style="color:var(--color-primary);font-weight:500">${c.hzGap}</span></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Ouvertures prévues d'ici ${c.hzAn}</span><span style="font-weight:500">${c.hzOuv}</span></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Contribution des ouvertures</span><span style="font-weight:500">${c.hzContrib}</span></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Croissance à périmètre constant</span><span>${c.hzLfl}</span></div>
          </div>
        </div>
        <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
          <div style="font-size:13px;font-weight:500;margin-bottom:14px">Décomposition de la trajectoire</div>
          <div style="display:flex;flex-direction:column;gap:12px">
            ${c.hzBars.map(b => `
              <div>
                <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:5px"><span>${esc(b.label)}</span><span style="font-weight:500">${b.val}</span></div>
                <div style="height:16px;border-radius:5px;background:var(--color-background-secondary);overflow:hidden"><div style="${b.st}"></div></div>
              </div>`).join('')}
          </div>
          <div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:16px;padding-top:12px;font-size:12.5px;color:var(--color-text-muted);line-height:1.6">${esc(c.hzNote)}</div>
        </div>
      </div>` : ''}
  </div>`;
}

/* --- Suivi budget magasin --------------------------------------------------- */
function tplBudget(c, x){
  const { esc } = x;
  const thB = 'font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)';
  return `
  <div data-screen="budget" style="display:flex;flex-direction:column;gap:16px">
    <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:24px;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:12px;color:var(--color-text-muted)">Magasin</span>
        <select ${x.C(c.setBStore)} style="font-size:13px;font-weight:500;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 10px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui)">
          ${opts(c.bStoreOpts, c.bStore, o => o.id, o => esc(o.nom))}
        </select>
        <span style="font-size:12px;color:var(--color-text-muted)">${esc(c.bMeta)}</span>
      </div>
      <div style="display:flex;gap:28px;text-align:right">
        <div><div style="${thB}">Budget validé ${c.bExercice}</div><div style="font-size:15px;font-weight:500;margin-top:2px">${c.bBudgetAn}</div></div>
        <div><div style="${thB}">Mois encodés</div><div style="font-size:15px;font-weight:500;margin-top:2px">${c.bEncodes}</div></div>
        <div><div style="${thB}">Dernier encodage</div><div style="font-size:15px;font-weight:500;margin-top:2px">${c.bDernier}</div></div>
      </div>
    </div>

    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px 22px;min-width:0">
      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:24px;margin-bottom:16px;flex-wrap:wrap">
        <div>
          <div style="font-family:var(--font-display);font-size:18px;line-height:1.3">Chiffre d'affaires — budget contre réel</div>
          <div style="font-size:12px;color:var(--color-text-muted);margin-top:2px">${esc(c.bScopeNom)} · budget encodé une fois par le consultant, réel encodé chaque mois par le franchisé</div>
        </div>
        <div style="display:inline-flex;border:0.5px solid var(--color-border-secondary);border-radius:999px;overflow:hidden">
          <button ${x.A(c.bScopeShop)} style="${c.bScopeShopSt}">Ce magasin</button>
          <button ${x.A(c.bScopeRes)} style="${c.bScopeResSt}">Réseau</button>
        </div>
        <div style="display:flex;align-items:center;gap:18px;font-size:11.5px;color:var(--color-text-muted)">
          <span style="display:inline-flex;align-items:center;gap:6px"><span style="width:10px;height:10px;border-radius:2px;background:#D8CEC2"></span>Budget validé</span>
          <span style="display:inline-flex;align-items:center;gap:6px"><span style="width:10px;height:10px;border-radius:2px;background:var(--color-primary)"></span>Réel encodé</span>
          ${c.bHasTheoChart ? `<span style="display:inline-flex;align-items:center;gap:6px"><span style="width:10px;height:10px;border-radius:2px;background:var(--pkg-abricot)"></span>CA théorique (étude de marché)</span>` : ''}
        </div>
      </div>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:960px">
          <tr>
            <td style="width:150px;border-bottom:0.5px solid var(--color-border-secondary)"></td>
            ${c.bBars.map(b => `
              <td style="height:150px;vertical-align:bottom;border-bottom:0.5px solid var(--color-border-secondary)">
                <div style="display:flex;gap:4px;align-items:end;justify-content:center">
                  ${b.hasTheo ? `<div style="${b.theoSt}"></div>` : ''}
                  <div style="${b.budSt}"></div><div style="${b.reelSt}"></div>
                </div>
              </td>`).join('')}
            <td style="border-bottom:0.5px solid var(--color-border-secondary)"></td>
          </tr>
          <tr>
            <td style="width:150px;padding:8px 10px 8px 0;${thB}">Mois</td>
            ${c.bMoisCols.map(m => `<td style="text-align:right;${thB};padding:8px 6px;white-space:nowrap;${m.st}">${m.nom}</td>`).join('')}
            <td style="text-align:right;${thB};padding:8px 6px;white-space:nowrap;padding-left:14px;border-left:0.5px solid var(--color-border-tertiary)">Total</td>
          </tr>
          ${c.bHasTheoChart ? `
          <tr style="border-top:0.5px solid var(--color-border-tertiary)">
            <td style="padding:9px 10px 9px 0;font-weight:500"><span style="display:inline-flex;align-items:center;gap:7px"><span style="width:8px;height:8px;border-radius:2px;background:var(--pkg-abricot)"></span>CA théorique</span></td>
            ${c.bLigneTheo.map(v => `<td style="padding:9px 6px;text-align:right;white-space:nowrap;color:var(--pkg-abricot)">${v}</td>`).join('')}
            <td style="padding:9px 6px;text-align:right;white-space:nowrap;font-weight:500;padding-left:14px;border-left:0.5px solid var(--color-border-tertiary);color:var(--pkg-abricot)">${c.bTotTheo}</td>
          </tr>` : ''}
          <tr style="border-top:0.5px solid var(--color-border-tertiary)">
            <td style="padding:9px 10px 9px 0;font-weight:500"><span style="display:inline-flex;align-items:center;gap:7px"><span style="width:8px;height:8px;border-radius:2px;background:#D8CEC2"></span>Budget validé</span></td>
            ${c.bLigneBud.map(v => `<td style="padding:9px 6px;text-align:right;white-space:nowrap">${v}</td>`).join('')}
            <td style="padding:9px 6px;text-align:right;white-space:nowrap;font-weight:500;padding-left:14px;border-left:0.5px solid var(--color-border-tertiary)">${c.bTotBud}</td>
          </tr>
          <tr style="border-top:0.5px solid var(--color-border-tertiary)">
            <td style="padding:9px 10px 9px 0;font-weight:500"><span style="display:inline-flex;align-items:center;gap:7px"><span style="width:8px;height:8px;border-radius:2px;background:var(--color-primary)"></span>Réel encodé</span></td>
            ${c.bLigneReel.map(v => `<td style="padding:9px 6px;text-align:right;white-space:nowrap">${v}</td>`).join('')}
            <td style="padding:9px 6px;text-align:right;white-space:nowrap;font-weight:500;padding-left:14px;border-left:0.5px solid var(--color-border-tertiary)">${c.bTotReel}</td>
          </tr>
          <tr><td colspan="14" style="padding:18px 0 8px;font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.07em;color:var(--color-text-muted)">Groupes de frais encodés — montant, % du CA réel dessous</td></tr>
          ${c.bChRows.map(ch => `
            <tr style="border-top:0.5px solid var(--color-border-tertiary)">
              <td style="padding:9px 10px 9px 0"><span style="display:inline-flex;align-items:center;gap:8px"><span class="levier-dot" data-lev="${ch.lev}"></span>${esc(ch.nom)}</span></td>
              ${ch.cells.map(v => `<td style="${v.st}">${v.txt}<div style="font-size:11px;color:var(--color-text-muted)">${v.pct}</div></td>`).join('')}
              <td style="padding:9px 6px 9px 14px;text-align:right;white-space:nowrap;font-weight:500;border-left:0.5px solid var(--color-border-tertiary)">${ch.tot}<div style="font-size:11px;font-weight:400;color:var(--color-text-muted)">${ch.totPct}</div></td>
            </tr>`).join('')}
          <tr style="border-top:0.5px solid var(--color-border-secondary)">
            <td style="padding:10px 10px 10px 0;font-weight:500">Total frais encodés</td>
            ${c.bChTotRow.map(v => `<td style="${v.st}">${v.txt}<div style="font-size:11px;font-weight:400;color:var(--color-text-muted)">${v.pct}</div></td>`).join('')}
            <td style="padding:10px 6px 10px 14px;text-align:right;white-space:nowrap;font-weight:500;border-left:0.5px solid var(--color-border-tertiary)">${c.bChTotAll}<div style="font-size:11px;font-weight:400;color:var(--color-text-muted)">${c.bChTotAllPct}</div></td>
          </tr>
          <tr style="border-top:0.5px solid var(--color-border-tertiary)">
            <td style="padding:10px 10px 10px 0;font-weight:500">Marge après frais</td>
            ${c.bMargeRow.map(v => `<td style="${v.st}">${v.txt}<div style="font-size:11px;font-weight:400;color:var(--color-text-muted)">${v.pct}</div></td>`).join('')}
            <td style="padding:10px 6px 10px 14px;text-align:right;white-space:nowrap;font-weight:500;border-left:0.5px solid var(--color-border-tertiary)">${c.bMargeTot}<div style="font-size:11px;font-weight:400;color:var(--color-text-muted)">${c.bMargeTotPct}</div></td>
          </tr>
          <tr><td colspan="14" style="padding:18px 0 8px;font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.07em;color:var(--color-text-muted)">Écarts</td></tr>
          <tr style="border-top:0.5px solid var(--color-border-secondary)">
            <td style="padding:9px 10px 9px 0;font-weight:500">Réel − budget</td>
            ${c.bLigneEc.map(v => `<td style="${v.st}">${v.txt}<div style="${v.pctSt}">${v.pct}</div></td>`).join('')}
            <td style="${c.bTotEcPair.st}">${c.bTotEcPair.txt}<div style="${c.bTotEcPair.pctSt}">${c.bTotEcPair.pct}</div></td>
          </tr>
          ${c.bHasTheoChart ? `
          <tr style="border-top:0.5px solid var(--color-border-tertiary)">
            <td style="padding:9px 10px 9px 0;font-weight:500">Réel − théorique</td>
            ${c.bEcTheo.map(v => `<td style="${v.st}">${v.txt}<div style="${v.pctSt}">${v.pct}</div></td>`).join('')}
            <td style="${c.bTotEcTheo.st}">${c.bTotEcTheo.txt}<div style="${c.bTotEcTheo.pctSt}">${c.bTotEcTheo.pct}</div></td>
          </tr>
          <tr style="border-top:0.5px solid var(--color-border-tertiary)">
            <td style="padding:9px 10px 9px 0;font-weight:500">Budget − théorique</td>
            ${c.bEcBudTheo.map(v => `<td style="${v.st}">${v.txt}<div style="${v.pctSt}">${v.pct}</div></td>`).join('')}
            <td style="${c.bTotEcBudTheo.st}">${c.bTotEcBudTheo.txt}<div style="${c.bTotEcBudTheo.pctSt}">${c.bTotEcBudTheo.pct}</div></td>
          </tr>` : ''}
        </table>
      </div>
    </div>

    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px 22px;min-width:0">
      <div style="font-family:var(--font-display);font-size:18px;line-height:1.3">Écarts et manque à gagner par magasin</div>
      <div style="font-size:12px;color:var(--color-text-muted);margin-top:2px;margin-bottom:14px">Cumul janv. → ${c.bCumMois} ${c.bExercice} · cliquez une ligne pour charger le magasin ci-dessus</div>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:880px">
          <thead><tr>
            <th style="text-align:left;${thB};padding:0 10px 9px 0">Magasin</th>
            <th style="text-align:right;${thB};padding:0 6px 9px">Réel</th>
            <th style="text-align:right;${thB};padding:0 6px 9px">Budget</th>
            <th style="text-align:right;${thB};padding:0 6px 9px">Réel − budget</th>
            <th style="text-align:right;${thB};padding:0 6px 9px">Réel / théorique</th>
            <th style="text-align:right;${thB};padding:0 6px 9px">Théorique</th>
            <th style="text-align:right;${thB};padding:0 6px 9px">Réel − théorique</th>
            <th style="text-align:right;${thB};padding:0 6px 9px 14px;border-left:0.5px solid var(--color-border-tertiary);width:150px">Manque à gagner</th>
          </tr></thead>
          <tbody>
            ${c.bParMag.map(m => `
              <tr ${x.A(m.select)} class="hv-bg" style="${m.rowSt}">
                <td style="padding:9px 10px 9px 0;cursor:pointer">
                  <div style="font-weight:500">${esc(m.nom)}</div>
                  <div style="font-size:11px;color:var(--color-text-muted);margin-top:1px">${esc(m.zone)}</div>
                </td>
                <td style="padding:9px 6px;text-align:right;white-space:nowrap;font-weight:500">${m.reel}</td>
                <td style="padding:9px 6px;text-align:right;white-space:nowrap;color:var(--color-text-muted)">${m.budget}</td>
                <td style="${m.ecBSt}">${m.ecB}<div style="font-size:11px;font-weight:400">${m.ecBP}</div></td>
                <td style="${m.realSt}">${m.real}</td>
                <td style="padding:9px 6px;text-align:right;white-space:nowrap;color:var(--pkg-abricot)">${m.theo}</td>
                <td style="${m.ecTSt}">${m.ecT}<div style="font-size:11px;font-weight:400">${m.ecTP}</div></td>
                <td style="${m.mqSt}">${m.mq}<div style="font-size:11px;font-weight:400;color:var(--color-text-muted)">${m.mqPct}</div><span style="display:block;height:5px;border-radius:999px;background:var(--color-background-secondary);margin-top:4px"><span style="${m.barSt}"></span></span></td>
              </tr>`).join('')}
            <tr style="border-top:0.5px solid var(--color-border-secondary)">
              <td style="padding:10px 10px 10px 0;font-weight:500">Réseau</td>
              <td style="padding:10px 6px;text-align:right;white-space:nowrap;font-weight:500">${c.bResReel}</td>
              <td style="padding:10px 6px;text-align:right;white-space:nowrap;color:var(--color-text-muted)">${c.bResBud}</td>
              <td style="${c.bMagTotEcSt}">${c.bMagTotEc}<div style="font-size:11px;font-weight:400">${c.bMagTotEcP}</div></td>
              <td style="${c.bResRealSt}">${c.bResReal}</td>
              <td style="padding:10px 6px;text-align:right;white-space:nowrap;color:var(--pkg-abricot)">${c.bResTheo}</td>
              <td style="${c.bMagTotEcTSt}">${c.bMagTotEcT}<div style="font-size:11px;font-weight:400">${c.bMagTotEcTP}</div></td>
              <td style="padding:10px 6px 10px 14px;text-align:right;white-space:nowrap;font-weight:500;border-left:0.5px solid var(--color-border-tertiary);color:var(--pkg-abricot)">${c.bMagTotMq}<div style="font-size:11px;font-weight:400;color:var(--color-text-muted)">${c.bMagTotMqPct}</div></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:12px;padding-top:11px;font-size:11.5px;color:var(--color-text-muted);text-wrap:pretty">${esc(c.bMagNote)}</div>
    </div>
  </div>`;
}

/* --- Marge & coûts ---------------------------------------------------------- */
function tplMarge(c, x){
  const { esc } = x;
  return `
  <div data-screen="marge" style="display:flex;flex-direction:column;gap:16px">
    <div style="display:grid;grid-template-columns:380px 1fr;gap:16px;align-items:start">
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
        <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)">Marge nette réseau — juillet</div>
        <div style="display:flex;align-items:baseline;gap:10px;margin-top:6px"><span style="font-size:32px;font-weight:500">${c.mgReseau}</span><span style="${c.mgTrSt}">${c.mgTr}</span></div>
        <svg width="100%" height="70" viewBox="0 0 320 70" preserveAspectRatio="none" style="margin-top:8px"><polyline points="${c.mgTraj}" fill="none" stroke="#8D1D2C" stroke-width="2"></polyline></svg>
        <div style="font-size:11px;color:var(--color-text-muted)">${c.mgEvoLabel}</div>
        <div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:14px;padding-top:12px;display:flex;flex-direction:column;gap:6px;font-size:12.5px">
          <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Seuil Food Cost</span><span style="font-weight:500">≤ ${c.sFoodTxt}</span></div>
          <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Seuil Labour Cost</span><span style="font-weight:500">≤ ${c.sLabourTxt}</span></div>
          <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Seuil Overhead Cost</span><span style="font-weight:500">≤ 13,5 %</span></div>
        </div>
      </div>
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
        <div style="font-size:13px;font-weight:500;margin-bottom:12px">Alertes actives — action recommandée par levier</div>
        <div style="display:flex;flex-direction:column;gap:10px">
          ${c.mgAlerts.map(a => `
            <div class="levier-item" data-lev="${a.lev}" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0 8px 14px">
              <div>
                <div style="font-size:13px;font-weight:500">${esc(a.store)} — ${esc(a.msg)}</div>
                <div style="font-size:12px;color:var(--color-text-muted);margin-top:2px">${esc(a.action)}</div>
              </div>
              <span class="levier-badge" data-lev="${a.lev}"><span class="levier-dot"></span>${esc(a.levNom)}</span>
            </div>`).join('')}
        </div>
      </div>
    </div>
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
      <div style="padding:14px 18px;border-bottom:0.5px solid var(--color-border-tertiary);font-size:13px;font-weight:500">Ratios de coûts par magasin — ${c.mgHdrPeriode} · où se gagne / se perd la marge</div>
      <table style="width:100%;border-collapse:collapse;font-size:12.5px">
        <thead><tr>
          <th style="${TH}">Magasin</th>
          <th style="text-align:right;${TH2}">Marge nette</th>
          <th style="text-align:right;${TH2}">vs N-1</th>
          <th style="text-align:center;${TH2}">Food Cost</th>
          <th style="text-align:center;${TH2}">Labour Cost</th>
          <th style="text-align:center;${TH2}">Overhead</th>
          <th style="text-align:right;${TH2};white-space:nowrap">CA/ETP<div style="font-size:10px;font-weight:400;text-transform:none;letter-spacing:0;color:var(--color-text-muted);margin-top:2px">min ${c.mgSeuilEtp}</div></th>
          <th style="${TH}">Statut</th>
        </tr></thead>
        <tbody>
          ${c.mgRows.map(r => `
            <tr style="border-bottom:0.5px solid var(--color-border-tertiary)">
              <td style="padding:10px 14px;font-weight:500">${esc(r.nom)}</td>
              <td style="padding:10px 12px;text-align:right;font-weight:500">${r.marge}</td>
              <td style="padding:10px 12px;text-align:right"><span style="${r.varSt}">${r.var}</span></td>
              <td style="padding:10px 12px;text-align:center"><span style="${r.foodSt}">${r.food}</span></td>
              <td style="padding:10px 12px;text-align:center"><span style="${r.labourSt}">${r.labour}</span></td>
              <td style="padding:10px 12px;text-align:center"><span style="${r.ovSt}">${r.ov}</span></td>
              <td style="padding:10px 12px;text-align:right;white-space:nowrap"><span style="${r.caEtpSt}">${r.caEtp}</span><div style="font-size:11px;color:var(--color-text-muted);margin-top:3px">${r.etp}</div></td>
              <td style="padding:10px 14px;font-size:12px;color:var(--color-text-muted)">${esc(r.statut)}</td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>
  </div>`;
}

/* --- Encodage du budget ------------------------------------------------------ */
function tplEncodage(c, x){
  const { esc } = x;
  const lbl = 'font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)';
  return `
  <div data-screen="encodage" style="display:flex;flex-direction:column;gap:16px;max-width:1180px">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span style="font-size:12px;color:var(--color-text-muted)">Magasin</span>
      <select ${x.C(c.setEncStore)} style="font-size:13px;font-weight:500;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 10px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui)">
        ${opts(c.encStoreOpts, c.encStore, o => o.id, o => esc(o.nom))}
      </select>
      <span style="font-size:12px;color:var(--color-text-muted)">${esc(c.encMeta)}</span>
      <span style="margin-left:auto;font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)">Exercice ${c.encExercice}</span>
    </div>

    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px 22px">
      <div style="font-family:var(--font-display);font-size:18px;line-height:1.3">Chiffre d'affaires, mois par mois</div>
      <div style="font-size:12px;color:var(--color-text-muted);margin:2px 0 16px">Deux séries à encoder : le CA théorique de l'étude de marché et le CA validé avec le franchisé. Montants en euros, hors TVA.</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(118px,1fr));gap:12px">
        ${c.encMois.map(m => `
          <div style="display:flex;flex-direction:column;gap:8px">
            <span style="${lbl}">${m.nom}</span>
            <label style="display:flex;flex-direction:column;gap:3px">
              <span style="display:inline-flex;align-items:center;gap:5px;font-size:10px;color:var(--pkg-abricot);font-weight:500"><span style="width:7px;height:7px;border-radius:2px;background:var(--pkg-abricot)"></span>Théorique</span>
              <input type="number" value="${m.theo}" ${x.C(m.setTheo)} style="${c.encInputSt}" />
            </label>
            <label style="display:flex;flex-direction:column;gap:3px">
              <span style="display:inline-flex;align-items:center;gap:5px;font-size:10px;color:var(--color-text-muted);font-weight:500"><span style="width:7px;height:7px;border-radius:2px;background:#D8CEC2"></span>Validé</span>
              <input type="number" value="${m.valeur}" ${x.C(m.set)} style="${c.encInputSt}" />
            </label>
          </div>`).join('')}
      </div>
      <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:24px;flex-wrap:wrap;border-top:0.5px solid var(--color-border-tertiary);margin-top:16px;padding-top:13px">
        <div><div style="${lbl.replace('0.06em', '0.08em')};color:var(--pkg-abricot)">Total théorique</div><div style="font-size:22px;font-weight:500;margin-top:3px;color:var(--pkg-abricot)">${c.encTheoTot}</div></div>
        <div><div style="${lbl.replace('0.06em', '0.08em')}">Total validé</div><div style="font-size:22px;font-weight:500;margin-top:3px">${c.encCaTot}</div></div>
        <div style="text-align:right"><div style="${lbl.replace('0.06em', '0.08em')}">Validé − théorique</div><div style="${c.encTheoDeltaSt};margin-top:3px">${c.encTheoDelta}</div></div>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:16px">
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-top:3px solid var(--pkg-abricot);border-radius:12px;padding:20px 22px">
        <div style="font-family:var(--font-display);font-size:18px;line-height:1.3">Étude de marché</div>
        <div style="font-size:12px;color:var(--color-text-muted);margin:2px 0 16px">Potentiel à maturité, montée en régime et saisonnalité. Encodé au mois ci-dessus : ${c.encTheoTot}.</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:14px">
          <label style="display:flex;flex-direction:column;gap:5px">
            <span style="${lbl}">Potentiel à maturité (€)</span>
            <input type="number" value="${c.encTheoBase}" ${x.C(c.setEncTheoBase)} style="${c.encInputSt}" />
          </label>
          <label style="display:flex;flex-direction:column;gap:5px">
            <span style="${lbl}">Année d'exploitation</span>
            <select ${x.C(c.setEncAnnee)} style="${c.encInputSt};text-align:left">${opts(c.encAnneeOpts, c.encAnnee, o => o.v, o => o.nom)}</select>
          </label>
          ${c.encRamp.map(r => `
            <label style="display:flex;flex-direction:column;gap:5px">
              <span style="${lbl}">${r.k} (% du potentiel)</span>
              <input type="number" step="1" value="${r.valeur}" ${x.C(r.set)} style="${c.encInputSt}" />
            </label>`).join('')}
        </div>
        <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;border-top:0.5px solid var(--color-border-tertiary);padding-top:12px;margin-bottom:14px">
          <div>
            <div style="${lbl.replace('0.06em', '0.08em')};color:var(--pkg-abricot)">CA théorique de l'exercice</div>
            <div style="font-size:22px;font-weight:500;margin-top:3px;color:var(--pkg-abricot)">${c.encTheoExercice}</div>
            <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">${esc(c.encCoef)}</div>
          </div>
          <button ${x.A(c.encLisser)} class="hv-apr" style="border:0.5px solid var(--pkg-abricot);background:none;cursor:pointer;font-family:var(--font-ui);font-size:12px;font-weight:500;color:var(--pkg-abricot);padding:8px 15px;border-radius:999px">Lisser sur les 12 mois</button>
        </div>
        <div style="${lbl};margin-bottom:8px">Variation par mois (% du CA annuel)</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(84px,1fr));gap:9px">
          ${c.encSais.map(s => `
            <label style="display:flex;flex-direction:column;gap:4px">
              <span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted)">${s.nom}</span>
              <input type="number" step="0.1" value="${s.valeur}" ${x.C(s.set)} style="${c.encInputSt};font-size:12px;padding:6px 7px" />
              <span style="font-size:10px;color:var(--color-text-muted);text-align:right">${s.montant}</span>
            </label>`).join('')}
        </div>
        <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin:11px 0 14px">
          <span style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.encRampNote)}</span>
          <span style="${c.encSaisTotSt}">Total ${c.encSaisTot}</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
          <label style="display:flex;flex-direction:column;gap:5px">
            <span style="${lbl}">Date de l'étude</span>
            <input type="date" value="${c.encEtudeDate}" ${x.C(c.setEncEtudeDate)} style="${c.encInputSt}" />
          </label>
          <label style="display:flex;flex-direction:column;gap:5px">
            <span style="${lbl}">Ménages en zone</span>
            <input type="number" value="${c.encMenages}" ${x.C(c.setEncMenages)} style="${c.encInputSt}" />
          </label>
          <label style="display:flex;flex-direction:column;gap:5px;grid-column:1 / -1">
            <span style="${lbl}">Source</span>
            <input type="text" value="${esc(c.encEtudeSrc)}" ${x.C(c.setEncEtudeSrc)} style="${c.encInputSt};text-align:left" />
          </label>
        </div>
        <div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:14px;padding-top:13px">
          <div style="${lbl};margin-bottom:9px">Annexe — document de l'étude</div>
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <label class="hv-apr-line" style="display:inline-flex;align-items:center;gap:8px;border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:8px 15px;cursor:pointer;font-size:12px;font-weight:500">
              Joindre un fichier
              <input type="file" accept=".pdf,.xlsx,.xls,.docx,.csv" ${x.C(c.encAnxPick)} style="display:none" />
            </label>
            <div style="min-width:0;flex:1">
              ${c.encHasAnx ? `
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                  <span style="display:inline-flex;align-items:center;gap:8px;background:var(--color-background-secondary);border-radius:8px;padding:7px 12px;font-size:12px;font-weight:500">${esc(c.encAnxNom)}</span>
                  <span style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.encAnxMeta)}</span>
                  <button ${x.A(c.encAnxDel)} style="border:none;background:none;cursor:pointer;font-family:var(--font-ui);font-size:11.5px;font-weight:500;color:var(--color-primary);padding:0">Retirer</button>
                </div>` : ''}
            </div>
          </div>
          <label style="display:flex;flex-direction:column;gap:5px;margin-top:11px;max-width:520px">
            <span style="${lbl}">Ou lien vers le document (Drive, SharePoint…)</span>
            <input type="url" value="${esc(c.encAnxUrl)}" ${x.C(c.setEncAnxUrl)} placeholder="https://" style="${c.encInputSt};text-align:left" />
          </label>
        </div>
      </div>
    </div>

    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px 22px">
      <div style="font-family:var(--font-display);font-size:18px;line-height:1.3">Répartition des charges</div>
      <div style="font-size:12px;color:var(--color-text-muted);margin:2px 0 16px">Deux jeux de taux par poste : celui de l'étude de marché et celui validé avec le franchisé. Les montants se recalculent à la saisie.</div>
      <div style="overflow-x:auto">
      <table style="width:100%;min-width:860px;border-collapse:collapse;font-size:12.5px">
        <thead><tr>
          <th style="text-align:left;${lbl};padding:0 10px 9px 0">Poste</th>
          <th style="text-align:right;${lbl};color:var(--pkg-abricot);padding:0 6px 9px;width:110px">% théorique</th>
          <th style="text-align:right;${lbl};color:var(--pkg-abricot);padding:0 6px 9px;width:150px">Montant théorique</th>
          <th style="text-align:right;${lbl};padding:0 6px 9px;width:110px">% validé</th>
          <th style="text-align:right;${lbl};padding:0 6px 9px;width:150px">Montant validé</th>
          <th style="text-align:right;${lbl};padding:0 0 9px 6px;width:110px">Écart</th>
        </tr></thead>
        <tbody>
          ${c.encCharges.map(ch => `
            <tr style="border-top:0.5px solid var(--color-border-tertiary)">
              <td style="padding:9px 10px 9px 0"><span style="display:inline-flex;align-items:center;gap:8px"><span class="levier-dot" data-lev="${ch.lev}"></span>${esc(ch.nom)}</span></td>
              <td style="padding:7px 6px"><input type="number" step="0.1" value="${ch.valeurT}" ${x.C(ch.setT)} style="${c.encInputSt}" /></td>
              <td style="padding:9px 6px;text-align:right;white-space:nowrap;color:var(--pkg-abricot)">${ch.montantT}</td>
              <td style="padding:7px 6px"><input type="number" step="0.1" value="${ch.valeur}" ${x.C(ch.set)} style="${c.encInputSt}" /></td>
              <td style="padding:9px 6px;text-align:right;white-space:nowrap;font-weight:500">${ch.montant}</td>
              <td style="${ch.ecartSt}">${ch.ecart}</td>
            </tr>`).join('')}
          <tr style="border-top:0.5px solid var(--color-border-secondary)">
            <td style="padding:11px 10px 11px 0;font-weight:500">Total charges</td>
            <td style="padding:11px 6px;text-align:right;white-space:nowrap;font-weight:500;color:var(--pkg-abricot)">${c.encPctTotT}</td>
            <td style="padding:11px 6px;text-align:right;white-space:nowrap;font-weight:500;color:var(--pkg-abricot)">${c.encChTotT}</td>
            <td style="padding:11px 6px;text-align:right;white-space:nowrap;font-weight:500">${c.encPctTot}</td>
            <td style="padding:11px 6px;text-align:right;white-space:nowrap;font-weight:500">${c.encChTot}</td>
            <td style="padding:11px 0 11px 6px"></td>
          </tr>
        </tbody>
      </table>
      </div>
      <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap;border-top:0.5px solid var(--color-border-tertiary);margin-top:14px;padding-top:14px">
        <div style="display:flex;gap:32px;flex-wrap:wrap">
          <div>
            <div style="${lbl.replace('0.06em', '0.08em')};color:var(--pkg-abricot)">Marge théorique</div>
            <div style="font-size:24px;font-weight:500;line-height:1.1;margin-top:4px;color:var(--pkg-abricot)">${c.encMargeT}</div>
            <div style="font-size:12px;color:var(--color-text-muted);margin-top:3px">${c.encMargePctT}</div>
          </div>
          <div>
            <div style="${lbl.replace('0.06em', '0.08em')}">Marge budgétée</div>
            <div style="${c.encMargeSt};margin-top:4px">${c.encMarge}</div>
            <div style="font-size:12px;color:var(--color-text-muted);margin-top:3px">${c.encMargePct}</div>
          </div>
        </div>
        <div style="display:flex;gap:10px">
          <button ${x.A(c.encReset)} class="hv-line" style="border:0.5px solid var(--color-border-secondary);background:none;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:500;color:var(--color-text);padding:9px 16px;border-radius:999px">Réinitialiser</button>
          <button ${x.A(c.encSave)} style="border:none;background:var(--color-primary);cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:500;color:#fff;padding:9px 20px;border-radius:999px">Enregistrer le budget</button>
        </div>
      </div>
      ${c.encAlerte ? `<div style="margin-top:12px;padding:10px 13px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:12px;font-weight:500">${esc(c.encAlerte)}</div>` : ''}
      <div style="font-size:11.5px;line-height:1.5;color:var(--color-text-muted);margin-top:12px;text-wrap:pretty">${esc(c.encNote)}</div>
    </div>
  </div>`;
}

/* --- Scoring produits -------------------------------------------------------- */
function tplProduits(c, x){
  const { esc } = x;
  return `
  <div data-screen="produits" style="display:flex;flex-direction:column;gap:16px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px">
      ${c.pdKpis.map(k => `
        <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:16px 18px">
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)">${esc(k.k)}</div>
          <div style="font-size:26px;font-weight:500;margin-top:6px;line-height:1.1">${k.v}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:4px">${esc(k.s)}</div>
        </div>`).join('')}
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <select ${x.C(c.setPdCat)} style="${selCss};font-family:var(--font-ui)">${opts(c.pdCatOptions, c.pdCat)}</select>
      <select ${x.C(c.setPdSort)} style="${selCss};font-family:var(--font-ui)">${opts(c.pdSortOptions, c.pdSort, o => o.val, o => o.nom)}</select>
      <span style="font-size:12px;color:var(--color-text-muted)">Pondération du score — ${c.pdPond}</span>
    </div>
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
      <div style="padding:14px 18px;border-bottom:0.5px solid var(--color-border-tertiary);font-size:13px;font-weight:500">Scoring des références — volume, marge nette, taux de perte et présence au comptoir · ${c.pdPeriode || ''}</div>
      <div style="overflow-x:auto">
      <table style="width:100%;min-width:1080px;border-collapse:collapse;font-size:12.5px">
        <thead><tr>
          <th style="${TH}">Référence</th>
          <th style="text-align:right;${TH2}">Volume / mois</th>
          <th style="text-align:left;${TH2};width:118px">Pénétration réseau</th>
          <th style="text-align:right;${TH2}">Taux de perte</th>
          <th style="text-align:right;${TH2}">Marge unit.</th>
          <th style="text-align:right;${TH2}">CA réseau · marge brute</th>
          <th style="text-align:center;${TH2}">Rang catégorie</th>
          <th style="text-align:left;${TH2};width:140px">Profil V · M · P · C</th>
          <th style="text-align:right;${TH2}">Score</th>
          <th style="${TH}">Arbitrage</th>
        </tr></thead>
        <tbody>
          ${c.pdRows.map(r => `
            <tr style="border-bottom:0.5px solid var(--color-border-tertiary)">
              <td style="padding:10px 14px">
                <div style="font-weight:500">${esc(r.nom)}</div>
                <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:1px">${esc(r.cat)}</div>
              </td>
              <td style="padding:10px 12px;text-align:right;white-space:nowrap">
                <div style="font-weight:500">${r.vol}</div>
                <div style="${r.tendSt}">${r.tend}</div>
              </td>
              <td style="padding:10px 12px">
                <div style="display:flex;align-items:baseline;gap:6px"><span style="font-weight:500">${r.pen}</span><span style="font-size:11.5px;color:var(--color-text-muted)">${r.mags}</span></div>
                <span style="display:block;height:5px;border-radius:999px;background:var(--color-background-secondary);margin-top:4px"><span style="${r.barPen}"></span></span>
              </td>
              <td style="padding:10px 12px;text-align:right;white-space:nowrap">
                <button ${x.A(r.openWaste)} title="Voir la perte magasin par magasin" style="border:none;background:none;padding:0;cursor:pointer;text-align:right;font-family:var(--font-ui)">
                  <div style="${r.perteSt};text-decoration:underline;text-decoration-color:var(--color-border-secondary);text-underline-offset:3px">${r.perteTxt}</div>
                  ${r.perteDetail ? `<div style="font-size:11px;color:var(--color-text-muted)">${esc(r.perteDetail)}</div>` : ''}
                </button>
              </td>
              <td style="padding:10px 12px;text-align:right;white-space:nowrap">
                <div style="font-weight:500">${r.mu}</div>
                <div style="font-size:11.5px;color:var(--color-text-muted)">${r.mp} · PV ${r.prix}</div>
              </td>
              <td style="padding:10px 12px;text-align:right;white-space:nowrap">
                <div style="font-weight:500">${r.ca}<span style="color:var(--color-text-muted);font-weight:400"> · ${r.mg}</span></div>
                <div style="font-size:11.5px;color:var(--color-text-muted)">${r.partCaRes} du CA produit</div>
              </td>
              <td style="padding:10px 12px;text-align:center;white-space:nowrap">
                <span style="${r.rangSt}">${r.rang}</span>
                <div style="font-size:11px;color:var(--color-text-muted);margin-top:3px">${r.part} du CA catégorie</div>
              </td>
              <td style="padding:10px 12px">
                <div style="display:flex;flex-direction:column;gap:4px">
                  <div title="Volume vendu" style="display:flex;align-items:center;gap:6px"><span style="font-size:9.5px;font-weight:500;color:var(--color-text-muted);width:8px">V</span><span style="flex:1;height:5px;border-radius:999px;background:var(--color-background-secondary)"><span style="${r.barVol}"></span></span></div>
                  <div title="${r.mgDispo ? 'Marge nette' : 'Marge nette — sans donnée, exclue du score'}" style="display:flex;align-items:center;gap:6px;opacity:${r.mgDispo ? '1' : '0.35'}"><span style="font-size:9.5px;font-weight:500;color:var(--color-text-muted);width:8px">M</span><span style="flex:1;height:5px;border-radius:999px;background:var(--color-background-secondary)"><span style="${r.barMg}"></span></span></div>
                  <div title="${r.perteDispo ? 'Taux de perte' : 'Taux de perte — sans donnée, exclu du score'}" style="display:flex;align-items:center;gap:6px;opacity:${r.perteDispo ? '1' : '0.35'}"><span style="font-size:9.5px;font-weight:500;color:var(--color-text-muted);width:8px">P</span><span style="flex:1;height:5px;border-radius:999px;background:var(--color-background-secondary)"><span style="${r.barPerte}"></span></span></div>
                  <div title="${r.comptoirDispo ? 'Présence au comptoir' : 'Présence au comptoir — sans donnée, exclue du score'}" style="display:flex;align-items:center;gap:6px;opacity:${r.comptoirDispo ? '1' : '0.35'}"><span style="font-size:9.5px;font-weight:500;color:var(--color-text-muted);width:8px">C</span><span style="flex:1;height:5px;border-radius:999px;background:var(--color-background-secondary)"><span style="${r.barComptoir}"></span></span></div>
                </div>
              </td>
              <td style="padding:10px 12px;text-align:right;white-space:nowrap">
                <div style="${r.scoreSt}">${r.score}</div>
                <span style="display:block;width:56px;height:5px;border-radius:999px;background:var(--color-background-secondary);margin-left:auto;margin-top:3px"><span style="${r.scoreBar}"></span></span>
              </td>
              <td style="padding:10px 14px"><span style="${r.verdictSt}">${r.verdict}</span></td>
            </tr>`).join('')}
        </tbody>
      </table>
      </div>
      <div style="padding:12px 18px;border-top:0.5px solid var(--color-border-tertiary);font-size:11.5px;color:var(--color-text-muted);text-wrap:pretty">${esc(c.pdNote)}</div>
    </div>
  </div>`;
}

/* --- Contrôle des tâches (checklists consultants du panel) -------------------- */
function tplControle(c, x){
  const { esc } = x;
  const card = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const dateSel = c.ctrlDates && c.ctrlDates.length
    ? `<select ${x.C(c.setCtrlDate)} style="${selCss};font-family:var(--font-ui)">${c.ctrlDates.map(d => `<option value="${d.val}"${d.sel ? ' selected' : ''}>${esc(d.label)}</option>`).join('')}</select>`
    : `<span style="font-size:12.5px;font-weight:500">${esc(c.ctrlDateLabel)}</span>`;
  return `
  <div data-screen="controle" style="display:flex;flex-direction:column;gap:16px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px">
      ${c.ctrlKpis.map(k => `
        <div style="${card};padding:16px 18px">
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)">${esc(k.k)}</div>
          <div style="font-size:26px;font-weight:500;margin-top:6px;line-height:1.1">${esc(k.v)}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:4px">${esc(k.s)}</div>
        </div>`).join('')}
    </div>
    ${c.ctrlRepVide ? '' : `
    <div style="${card};padding:16px 18px">
      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div style="font-size:13px;font-weight:500">Répartition des avis par niveau de conformité</div>
        <div style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.ctrlNotees)} notée(s)${c.ctrlNonNotees !== '0' ? ' · ' + esc(c.ctrlNonNotees) + ' sans note' : ''}</div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-top:14px">
        <div>
          <div style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:0.07em;color:#2d7a3e;margin-bottom:8px">Conforme</div>
          ${c.ctrlRepConf.map(r => `
            <div style="margin-bottom:10px">
              <div style="display:flex;align-items:center;gap:8px">
                <span style="${r.dotSt}"></span>
                <span style="font-size:12.5px;flex:1">${esc(r.nom)}</span>
                <span style="font-size:12.5px;font-weight:600;white-space:nowrap">${esc(r.txt)}</span>
              </div>
              <span style="display:block;height:5px;border-radius:999px;background:var(--color-background-secondary);margin-top:5px"><span style="${r.barSt}"></span></span>
            </div>`).join('')}
        </div>
        <div>
          <div style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:0.07em;color:#8D1D2C;margin-bottom:8px">Non conforme</div>
          ${c.ctrlRepNc.map(r => `
            <div style="margin-bottom:10px">
              <div style="display:flex;align-items:center;gap:8px">
                <span style="${r.dotSt}"></span>
                <span style="font-size:12.5px;flex:1">${esc(r.nom)}</span>
                <span style="font-size:12.5px;font-weight:600;white-space:nowrap">${esc(r.txt)}</span>
              </div>
              <span style="display:block;height:5px;border-radius:999px;background:var(--color-background-secondary);margin-top:5px"><span style="${r.barSt}"></span></span>
            </div>`).join('')}
        </div>
      </div>
    </div>`}
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Journée</span>
      ${dateSel}
      <select ${x.C(c.setCtrlShop)} style="${selCss};font-family:var(--font-ui)">${opts(c.ctrlShopOptions, c.ctrlShop)}</select>
      <select ${x.C(c.setCtrlOnly)} style="${selCss};font-family:var(--font-ui)">${opts(c.ctrlOnlyOptions, c.ctrlOnly, o => o.val, o => o.nom)}</select>
    </div>
    ${c.ctrlApiOff ? `
      <div style="background:rgba(193,122,42,0.10);border:0.5px solid rgba(193,122,42,0.35);border-radius:10px;padding:12px 16px;font-size:12.5px;line-height:1.55">
        <span style="font-weight:600">Compte consultant non configuré.</span> Les noms de tâches et les photos viennent de l’API du panel : renseignez le téléphone et le mot de passe du compte dans <span style="font-weight:500">Paramètres → Compte consultant (API panel)</span>. En attendant, les tâches s’affichent par leur identifiant et la notation est indisponible.${c.ctrlApiErr ? `<div style="margin-top:6px;color:#8a5a13">${esc(c.ctrlApiErr)}</div>` : ''}
      </div>` : ''}
    ${c.ctrlIndispo ? `
      <div style="${card};padding:28px 22px;text-align:center">
        <div style="font-size:14px;font-weight:500">Aucun avis consultant à contrôler pour l’instant</div>
        <div style="font-size:12.5px;color:var(--color-text-muted);margin-top:8px;line-height:1.6;max-width:620px;margin-left:auto;margin-right:auto">Les tâches et checklists sont évaluées par les consultants dans le panel ; leurs avis apparaissent ici dès qu’ils sont enregistrés (table partagée <code>mac_task_review</code>). Le détail vivant des checklists du jour reste dans le panel.</div>
      </div>` : (c.ctrlEmpty ? `
      <div style="${card};padding:24px;text-align:center;font-size:13px;color:var(--color-text-muted)">Aucune tâche ne correspond à ce filtre pour cette journée.</div>` : `
      <div style="display:flex;flex-direction:column;gap:14px">
        ${c.ctrlShops.map(s => `
          <div style="${card};overflow:hidden">
            <div style="padding:13px 18px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;justify-content:space-between;gap:10px">
              <div style="font-size:13.5px;font-weight:600">${esc(s.shop)}</div>
              <div style="font-size:11.5px;color:var(--color-text-muted)">${s.nValid} / ${s.nTaches} notée(s) — donc validée(s)</div>
            </div>
            <div style="overflow-x:auto">
            <table style="width:100%;min-width:920px;border-collapse:collapse;font-size:12.5px">
              <thead><tr>
                <th style="${TH}">Tâche</th>
                <th style="text-align:center;${TH2};width:78px">Note</th>
                <th style="text-align:left;${TH2};width:120px">Conformité</th>
                <th style="text-align:left;${TH2};width:150px">Consultant</th>
                <th style="text-align:left;${TH2}">Commentaire</th>
                <th style="text-align:right;${TH};width:200px">Validation (note posée)</th>
              </tr></thead>
              <tbody>
                ${s.taches.map(t => `
                  <tr style="border-bottom:0.5px solid var(--color-border-tertiary)">
                    <td style="padding:10px 14px"><button ${x.A(t.open)} title="Voir la photo et noter" style="border:none;background:none;padding:0;text-align:left;font-family:var(--font-ui);font-size:12.5px;font-weight:500;color:var(--color-text);cursor:pointer;text-decoration:underline;text-decoration-color:var(--color-border-secondary);text-underline-offset:3px">${esc(t.tache)}</button></td>
                    <td style="padding:10px 12px;text-align:center;white-space:nowrap;${t.noteSt}">${esc(t.note)}</td>
                    <td style="padding:10px 12px;${t.accSt}">${esc(t.acc)}</td>
                    <td style="padding:10px 12px;color:var(--color-text)">${esc(t.consultant)}</td>
                    <td style="padding:10px 12px;color:var(--color-text-muted);text-wrap:pretty">${t.hasComment ? esc(t.comment) : '<span style="opacity:0.6">—</span>'}</td>
                    <td style="padding:10px 14px;text-align:right;white-space:nowrap">
                      <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px">
                        <span style="font-size:11px;color:${t.valide ? '#2d7a3e' : 'var(--color-text-muted)'}">${esc(t.valideMeta)}</span>
                        <button ${x.A(t.toggle)} style="${t.btnSt}">${esc(t.btnLabel)}</button>
                      </div>
                    </td>
                  </tr>`).join('')}
              </tbody>
            </table>
            </div>
          </div>`).join('')}
      </div>`)}
    ${c.ctrlConsultants && c.ctrlConsultants.length ? `
      <div style="${card};overflow:hidden">
        <div style="padding:13px 18px;border-bottom:0.5px solid var(--color-border-tertiary);font-size:13px;font-weight:500">Activité d’évaluation par consultant — journée du ${esc(c.ctrlDateLabel)}</div>
        <div style="overflow-x:auto">
        <table style="width:100%;min-width:560px;border-collapse:collapse;font-size:12.5px">
          <thead><tr>
            <th style="${TH}">Consultant</th>
            <th style="text-align:right;${TH2}">Avis</th>
            <th style="text-align:right;${TH2}">Note moyenne</th>
            <th style="text-align:right;${TH2}">Refus</th>
            <th style="text-align:right;${TH};width:110px">Validés</th>
          </tr></thead>
          <tbody>
            ${c.ctrlConsultants.map(cc => `
              <tr style="border-bottom:0.5px solid var(--color-border-tertiary)">
                <td style="padding:10px 14px;font-weight:500">${esc(cc.nom)}</td>
                <td style="padding:10px 12px;text-align:right">${esc(cc.avis)}</td>
                <td style="padding:10px 12px;text-align:right">${esc(cc.noteMoy)}</td>
                <td style="padding:10px 12px;text-align:right">${esc(cc.refuses)}</td>
                <td style="padding:10px 14px;text-align:right">${esc(cc.valides)}</td>
              </tr>`).join('')}
          </tbody>
        </table>
        </div>
      </div>` : ''}
  </div>`;
}

/* --- Projets (kanban par famille) -------------------------------------------- */
function tplProjets(c, x){
  const { esc } = x;
  return `
  <div data-screen="projets" style="display:flex;flex-direction:column;gap:14px">
    <div style="display:flex;align-items:center;justify-content:flex-end;gap:12px">
      <button ${x.A(c.npOpen)} class="hv-fade" style="border:none;cursor:pointer;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:9px 18px;border-radius:999px">+ Nouveau projet</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;align-items:start">
      ${c.kanban.map(col => `
        <div ${x.DP(col.drop)} style="background:rgba(255,255,255,0.55);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:10px;min-height:220px">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:4px 6px 10px">
            <span style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.07em;color:var(--color-text-muted)">${esc(col.nom)}</span>
            <span style="font-size:11px;color:var(--color-text-muted)">${col.n}</span>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px">
            ${col.items.map(p => `
              <div draggable="true" ${x.DS(p.drag)} ${x.A(p.toggle)} style="${p.cardSt}">
                <div style="display:flex;align-items:flex-start;gap:8px">
                  <div style="font-size:13.5px;font-weight:500;line-height:1.4;min-width:0">${esc(p.nom)}</div>
                  <span style="${p.chevSt}">▾</span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:8px">
                  <span style="${p.statutSt}">${p.statut}</span>
                  ${p.levs.map(l => `<span class="levier-dot" data-lev="${l}" title="${l}"></span>`).join('')}
                  <span style="font-size:11px;color:var(--color-text-muted);margin-left:auto;white-space:nowrap">Échéance ${p.fin}</span>
                </div>
                <div style="height:4px;border-radius:999px;background:var(--color-background-secondary);margin-top:10px;overflow:hidden"><div style="${p.avSt}"></div></div>
                <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap;margin-top:7px;font-size:11px;color:var(--color-text-muted)">
                  <span style="white-space:nowrap">${p.av} avancé · ${p.resteTxt}</span>
                  ${p.alerte ? `<span style="color:var(--color-primary);font-weight:500;white-space:nowrap">${p.alerte}</span>` : ''}
                </div>
                ${p.ouvert ? `
                  <div style="margin-top:11px;padding-top:10px;border-top:0.5px solid var(--color-border-tertiary);display:flex;flex-direction:column;gap:8px">
                    <div style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.07em;color:var(--color-text-muted)">Étapes du projet</div>
                    ${p.etapes.map(e => `
                      <div style="display:flex;flex-direction:column">
                        <div style="${e.rowSt}">
                          <span ${x.A(e.check)} style="${e.boxSt}">${e.boxTxt}</span>
                          <div style="min-width:0;flex:1">
                            <div style="${e.nomSt}">${esc(e.nom)}</div>
                            <div style="font-size:10.5px;margin-top:2px"><span style="${e.dueSt}">${e.due}</span><span style="color:var(--color-text-muted)"> · ${esc(e.meta)}</span></div>
                          </div>
                          <span ${x.A(e.infoT)} style="${e.iSt}" title="Détail de l'étape">i</span>
                        </div>
                        ${e.info ? `
                          <div style="margin:2px 0 6px 23px;padding:9px 11px;border:0.5px solid var(--color-border-tertiary);border-radius:8px;background:var(--color-background-secondary);display:grid;grid-template-columns:auto minmax(0,1fr);column-gap:10px;row-gap:4px">
                            ${e.rows.map(r => `
                              <span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted);white-space:nowrap">${r.k}</span>
                              <span style="font-size:11.5px;line-height:1.4;text-wrap:pretty">${esc(r.v)}</span>`).join('')}
                          </div>` : ''}
                      </div>`).join('')}
                    ${p.rienReste ? `<div style="font-size:11.5px;color:var(--color-text-muted)">Aucune étape enregistrée.</div>` : ''}
                    <button ${x.A(p.open)} class="hv-line" style="align-self:flex-start;margin-top:2px;border:0.5px solid var(--color-border-secondary);background:none;cursor:pointer;font-family:var(--font-ui);font-size:11.5px;font-weight:500;color:var(--color-text);padding:5px 12px;border-radius:999px">Ouvrir la fiche projet</button>
                  </div>` : ''}
              </div>`).join('')}
          </div>
        </div>`).join('')}
    </div>
    <div style="font-size:12px;color:var(--color-text-muted)">Colonnes = famille de projet. Cliquez une carte pour dérouler ses étapes, cochez-les au fil de l'eau, (i) pour le détail. Glissez-déposez une carte pour changer de famille — tout est tracé dans le journal.</div>
  </div>`;
}

/* --- Tâches consultants ------------------------------------------------------ */
/* Styles du dépli de validation, nommés une fois plutôt que recopiés six. */
const dkv = 'display:grid;grid-template-columns:auto minmax(0,1fr);column-gap:14px;row-gap:7px';
const dk = 'font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted);white-space:nowrap;padding-top:2px';
const dv = 'font-size:12px;line-height:1.45;text-wrap:pretty';
const dcap = 'font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)';
const dsel = 'width:100%;font-size:11.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 8px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui)';

function tplTaches(c, x){
  const { esc } = x;
  return `
  <div data-screen="taches" style="display:flex;flex-direction:column;gap:14px">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <select ${x.C(c.setTkWho)} style="${selCss}">${opts(c.tkPeople, c.tkWho, o => o.val, o => esc(o.nom))}</select>
      <select ${x.C(c.setTkStore)} style="${selCss}">${opts(c.tkStores, c.tkStore, o => o.val, o => esc(o.nom))}</select>
      <span style="flex:1;min-width:0;font-size:12px;color:var(--color-text-muted)">${esc(c.tkResume)}</span>
      <button ${x.A(c.ntOpen)} class="hv-fade" style="border:none;cursor:pointer;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:9px 18px;border-radius:999px">+ Nouvelle tâche</button>
    </div>
    <div style="display:flex;flex-direction:column;gap:14px">
      ${c.tkGroups.map(g => `
        <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
          <div style="display:flex;align-items:center;gap:9px;padding:11px 16px;border-bottom:0.5px solid var(--color-border-tertiary)">
            <span style="${g.dotSt}"></span>
            <span style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.07em;color:${g.couleur}">${g.nom}</span>
            <span style="font-size:11px;color:var(--color-text-muted)">${g.n}</span>
          </div>
          ${g.items.map(t => `
            <div style="${t.rowSt}">
              <div ${x.A(t.toggleOpen)} class="hv-bg" style="display:flex;align-items:flex-start;gap:12px;padding:12px 16px;cursor:pointer">
                <span ${x.A(t.check)} style="${t.boxSt}">${t.boxTxt}</span>
                <div style="flex:1;min-width:0">
                  <div style="${t.nomSt}">${esc(t.nom)}</div>
                  <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:3px">
                    <span style="font-size:11px;color:var(--color-text-muted)">${esc(t.qui)} · ${esc(t.projet)}</span>
                    ${t.hasMag ? `<span style="font-size:10.5px;font-weight:500;padding:2px 7px;border-radius:999px;background:var(--color-background-secondary);border:0.5px solid var(--color-border-tertiary);color:var(--color-text);white-space:nowrap">${esc(t.magasin)}</span>` : ''}
                    ${t.hasLvl ? `<span style="${t.lvlSt}"><i style="${t.lvlNumSt};font-style:normal">${t.lvlNum}</i>${esc(t.lvlTxt)}</span>` : ''}
                    ${t.hasSig ? `<span style="${t.sigSt}">${esc(t.sigTxt)}</span>` : ''}
                  </div>
                </div>
                <span style="${t.dueSt}">${t.due}</span>
                <span style="${t.chevSt}">▾</span>
              </div>
              ${t.ouvert ? `
                <div style="margin:0 16px 14px 44px;display:grid;grid-template-columns:${t.vOuvert ? 'minmax(0,1fr) 372px' : 'minmax(0,1fr)'};gap:14px;align-items:start">
                  <div style="padding:11px 14px;border-radius:9px;background:var(--color-background-secondary)">
                    <div style="${dkv}">
                      ${t.rows.map(r => `
                        <span style="${dk}">${r.k}</span>
                        <span style="${dv}">${esc(r.v)}</span>`).join('')}
                    </div>
                    <div style="${dcap};margin:13px 0 6px">Cet intervenant</div>
                    <div style="${dkv}">
                      ${t.histo.map(r => `
                        <span style="${dk}">${r.k}</span>
                        <span style="${dv}">${esc(r.v)}</span>`).join('')}
                    </div>
                  </div>
                  ${t.vOuvert ? `
                  <div style="padding:12px 14px;border-radius:9px;background:var(--color-surface);border:0.5px solid var(--color-border-secondary)">
                    <div style="${dcap};margin-bottom:6px">Votre validation</div>
                    <div style="display:flex;align-items:center;gap:1px">
                      ${[1, 2, 3, 4, 5].map(n => `<button ${x.A(() => t.setNote(n))} aria-label="${n}/5" style="${t.starSt(n)}">★</button>`).join('')}
                    </div>
                    ${t.hasLvb ? `
                      <div style="${t.lvbSt}"><i style="${t.lvbNumSt}">${t.lvbNum}</i>${esc(t.lvbTxt)}
                        <span style="margin-left:auto;font-size:10.5px;font-weight:500;opacity:0.75">${esc(t.lvbAide)}</span></div>` : ''}
                    ${t.sousSeuil ? `
                      <div style="margin-top:10px;border:1px solid rgba(141,29,44,0.26);border-radius:9px;background:rgba(141,29,44,0.035);padding:10px">
                        <div style="display:flex;align-items:center;gap:6px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-primary);margin-bottom:8px">
                          <span style="width:14px;height:14px;border-radius:50%;background:var(--color-primary);color:#fff;font-size:9px;display:flex;align-items:center;justify-content:center">!</span>
                          Signaler à l'intervenant
                          <span style="margin-left:auto;text-transform:none;letter-spacing:0;font-size:10px;background:rgba(192,24,43,0.12);color:#C0182B;border-radius:999px;padding:1px 8px">${esc(t.sousTxt)}</span>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:7px">
                          <select ${x.C(t.setFam)} style="${dsel}">${opts(t.fams, t.famCour)}</select>
                          <select ${x.C(t.setTyp)} style="${dsel}">${opts(t.typs, t.typCour)}</select>
                        </div>
                      </div>` : ''}
                    <textarea ${x.C(t.setCom)} rows="2" placeholder="Commentaire (facultatif)" style="width:100%;margin-top:9px;border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:7px 9px;font-size:12px;font-family:var(--font-ui);color:var(--color-text);resize:vertical;box-sizing:border-box">${esc(t.commentaire)}</textarea>
                    <button ${x.A(t.valider)} class="hv-fade" style="width:100%;margin-top:9px;border:none;border-radius:999px;padding:9px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;${t.peutValider ? '' : 'opacity:0.5;cursor:default'}">${t.boutonTxt}</button>
                  </div>` : ''}
                </div>` : ''}
            </div>`).join('')}
        </div>`).join('')}
      ${c.tkVide ? `<div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:18px;font-size:12px;color:var(--color-text-muted)">Aucune tâche pour cet intervenant.</div>` : ''}
    </div>
    <div style="font-size:12px;color:var(--color-text-muted)">Cochez une tâche quand elle est rendue, ouvrez la ligne pour la noter de 1 à 5. Une note sous 4 ouvre un signalement — tout est tracé dans le journal.</div>
  </div>`;
}

/* --- Reporting --------------------------------------------------------------- */
function tplReporting(c, x){
  const { esc } = x;
  return `
  <div data-screen="reporting" style="display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start">
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
      <div style="padding:14px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
        <div style="font-size:13px;font-weight:500">Rapports récurrents — génération et envoi automatiques (PDF)</div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:11px">
          <select ${x.C(c.setRepFFreq)} style="font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:5px 8px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui)">${opts(c.repFFreqOpts, c.repFFreq)}</select>
          <select ${x.C(c.setRepFType)} style="font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:5px 8px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui)">${opts(c.repFTypeOpts, c.repFType)}</select>
          <div style="display:inline-flex;border:0.5px solid var(--color-border-secondary);border-radius:999px;overflow:hidden">
            ${c.repEtatBtns.map(b => `<button ${x.A(b.go)} style="${b.st}">${b.nom}</button>`).join('')}
          </div>
          <span style="margin-left:auto;font-size:11.5px;color:var(--color-text-muted)">${esc(c.repCount)}</span>
        </div>
      </div>
      <div style="display:flex;flex-direction:column">
        ${c.repVide ? `<div style="padding:18px;font-size:12px;color:var(--color-text-muted)">Aucun rapport ne correspond à ces filtres.</div>` : ''}
        ${c.repRows.map(r => `
          <div style="display:flex;flex-direction:column;gap:10px;padding:13px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
              <div style="flex:1;min-width:220px">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <span style="font-size:13px;font-weight:500">${esc(r.nom)}</span>
                  <span style="font-size:11px;color:var(--color-text-muted)">${esc(r.type)}</span>
                  <span ${x.A(r.toggleActif)} style="${r.actifSt}">${r.actifTxt}</span>
                </div>
                <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">${esc(r.desc)}</div>
              </div>
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <select ${x.C(r.setFreq)} style="${selCss}">${opts(['Hebdomadaire', 'Mensuel', 'Trimestriel', 'Annuel'], r.freq)}</select>
                <div style="font-size:11.5px;color:var(--color-text-muted);white-space:nowrap">Dernier : ${r.dernier}</div>
                <div style="display:flex;gap:6px">
                  <button ${x.A(r.gen)} style="border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:6px 10px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">PDF</button>
                  <button ${x.A(r.send)} style="border:none;border-radius:7px;padding:6px 10px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Envoyer</button>
                </div>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
              <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:240px">
                <span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);width:20px">À</span>
                <select ${x.C(r.setDest)} style="flex:1;min-width:0;font-size:11.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:4px 7px;background:var(--color-surface);color:var(--color-text)">
                  ${opts(c.repPeople, r.dest, o => o.val, o => esc(o.nom))}
                </select>
                <span style="${r.destSt}">${r.destEmail}</span>
              </div>
              <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:240px">
                <span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);width:20px">Cc</span>
                <select ${x.C(r.setCc)} style="flex:1;min-width:0;font-size:11.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:4px 7px;background:var(--color-surface);color:var(--color-text)">
                  <option value=""${r.cc === '' ? ' selected' : ''}>— Aucune copie —</option>
                  ${opts(c.repPeople, r.cc, o => o.val, o => esc(o.nom))}
                </select>
                <span style="${r.ccSt}">${r.ccEmail}</span>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
              <span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Postes</span>
              <div style="display:flex;gap:4px;flex-wrap:wrap">
                ${r.postes.map(p => `<button ${x.A(p.toggle)} title="${esc(p.label)}" style="${p.st}">${p.tag}</button>`).join('')}
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
              <span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">URL</span>
              <code style="flex:1;min-width:260px;font-size:11px;color:var(--color-text-muted);background:var(--color-background-secondary);border-radius:6px;padding:5px 9px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r.url)}</code>
              <button ${x.A(r.copy)} style="border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:5px 10px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Copier</button>
              <button ${x.A(r.prev)} style="border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:5px 10px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Aperçu PDF</button>
            </div>
          </div>`).join('')}
      </div>
    </div>
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
      <div style="font-size:13px;font-weight:500;margin-bottom:12px">Alertes automatiques — push + email</div>
      <div style="display:flex;flex-direction:column;gap:12px">
        ${c.alertRows.map(a => `
          <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
            <input type="checkbox" ${a.actif ? 'checked' : ''} ${x.C(a.toggle)} style="accent-color:var(--color-primary);margin-top:2px">
            <span>
              <span style="font-size:12.5px;font-weight:500;display:block">${esc(a.nom)}</span>
              <span style="font-size:11px;color:var(--color-text-muted)">${esc(a.canal)}</span>
            </span>
          </label>`).join('')}
      </div>
    </div>

    <div style="grid-column:1 / -1;display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
        <div style="padding:14px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
          <div style="font-size:13px;font-weight:500">Panel consultant — générer un rapport</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">Rapports de gestion (hebdo / mensuel) et checklist tâches, rendus par le panel consultant.</div>
        </div>
        <div style="padding:16px 18px;display:flex;flex-direction:column;gap:12px">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <select ${x.C(c.setPwaType)} style="${selCss};font-family:var(--font-ui)">${opts(c.pwaTypes, c.pwaType, o => o.val, o => o.nom)}</select>
            <select ${x.C(c.setPwaScope)} style="${selCss};font-family:var(--font-ui)">${opts(c.pwaScopes, c.pwaScope, o => o.val, o => esc(o.nom))}</select>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <code style="flex:1;min-width:240px;font-size:10.5px;color:var(--color-text-muted);background:var(--color-background-secondary);border-radius:6px;padding:5px 9px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(c.pwaUrl)}</code>
            <button ${x.A(c.pwaCopy)} style="border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:5px 10px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Copier</button>
            <button ${x.A(c.pwaGen)} style="border:none;border-radius:7px;padding:6px 12px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer;white-space:nowrap">Générer le rapport →</button>
          </div>
          <div style="font-size:11.5px;color:var(--color-text-muted);line-height:1.5;text-wrap:pretty">${esc(c.pwaNote)}</div>
          ${c.pwaHasBase ? '' : `<div style="padding:9px 12px;border-radius:8px;background:rgba(193,122,42,0.14);color:#8a5a13;font-size:11.5px;font-weight:500">${esc(c.pwaBase)}</div>`}
        </div>
      </div>

      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
        <div style="padding:14px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
          <div style="font-size:13px;font-weight:500">Panel consultant — liens de partage récupérés</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">Rapports mensuels figés partagés par les consultants — un rapport, une boutique, un mois, sans authentification.</div>
        </div>
        <div style="display:flex;flex-direction:column">
          ${c.pwaSharesVide ? `<div style="padding:16px 18px;font-size:12px;color:var(--color-text-muted)">Aucun lien de partage récupéré du panel.</div>` : ''}
          ${c.pwaShares.map(p => `
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:11px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
              <div style="flex:1;min-width:220px">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <span style="font-size:12.5px;font-weight:500">${esc(p.magasin)}</span>
                  <span style="font-size:11px;color:var(--color-text-muted)">${p.ym}</span>
                  <span style="${p.etatSt}">${p.etat}</span>
                </div>
                <div style="font-size:11px;color:var(--color-text-muted);margin-top:2px">${esc(p.meta)}</div>
              </div>
              <button ${x.A(p.copy)} style="border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:5px 10px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Copier</button>
              <button ${x.A(p.open)} ${p.actif ? '' : 'disabled'} style="border:none;border-radius:7px;padding:5px 10px;background:${p.actif ? 'var(--color-primary)' : 'var(--color-background-secondary)'};color:${p.actif ? '#fff' : 'var(--color-text-muted)'};font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:${p.actif ? 'pointer' : 'default'}">Ouvrir</button>
            </div>`).join('')}
        </div>
      </div>
    </div>

    <div style="grid-column:1 / -1;display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
        <div style="padding:14px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
          <div style="font-size:13px;font-weight:500">Rapport consultant — par district</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">Chaque consultant reçoit les magasins de son district et les leviers à travailler en visite.</div>
        </div>
        <div style="display:flex;flex-direction:column">
          ${c.distRows.map(d => `
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:13px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
              <div style="flex:1;min-width:200px">
                <div style="font-size:12.5px;font-weight:500">${esc(d.nom)}</div>
                <div style="font-size:11px;color:var(--color-text-muted);margin-top:2px">${esc(d.stores)}</div>
                <div style="display:flex;align-items:center;gap:6px;margin-top:7px;flex-wrap:wrap">
                  <span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">À discuter</span>
                  ${d.leviers.map(l => `<span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:2px 8px"><span class="levier-dot" data-lev="${l.id}"></span>${esc(l.nom)}</span>`).join('')}
                </div>
              </div>
              <button ${x.A(d.send)} class="hv-line" style="border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:6px 12px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer;white-space:nowrap">Envoyer</button>
            </div>`).join('')}
        </div>
      </div>

      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
        <div style="padding:14px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
          <div style="font-size:13px;font-weight:500">Direct Link franchisé — plan d'action</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">Lien nominatif où le franchisé note les actions qu'il engage et pour quand. Les retours alimentent le journal.</div>
        </div>
        <div style="display:flex;flex-direction:column">
          ${c.dlRows.map(d => `
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:11px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
              <div style="flex:1;min-width:150px">
                <div style="font-size:12.5px;font-weight:500">${esc(d.store)}</div>
                <div style="font-size:11px;color:${d.etatCol};margin-top:2px">${esc(d.etat)}</div>
              </div>
              <code style="flex:1;min-width:170px;font-size:10.5px;color:var(--color-text-muted);background:var(--color-background-secondary);border-radius:6px;padding:5px 9px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(d.url)}</code>
              <button ${x.A(d.copy)} style="border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:5px 10px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Copier</button>
              <button ${x.A(d.relance)} style="border:none;border-radius:7px;padding:5px 10px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Relancer</button>
            </div>`).join('')}
        </div>
      </div>
    </div>
  </div>`;
}

/* --- Journal ------------------------------------------------------------------ */
/* --- Suivi des tâches --------------------------------------------------------- */
/**
 * Ce qui a été validé sur la période, et les signalements à traiter.
 *
 * Trois blocs, dans l'ordre où l'on décide : les chiffres, qui dit s'il y a un
 * problème ; la répartition et les intervenants, qui disent où ; les
 * signalements, qui se traitent sur place.
 */
function tplSuivi(c, x){
  const { esc } = x;
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const titre = 'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.07em;color:var(--color-text-muted)';
  return `
  <div data-screen="suivi" style="display:flex;flex-direction:column;gap:14px">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      ${c.suOnglets.map(o => `<button ${x.A(o.go)} style="${o.st}">${o.nom}</button>`).join('')}
      <span style="flex:1"></span>
    </div>

    ${c.suChargement ? `<div style="${carte};padding:18px;font-size:12px;color:var(--color-text-muted)">Lecture du suivi…</div>` : `
      <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px">
        ${c.suTuiles.map(t => `
          <div style="${carte};padding:13px 15px">
            <div style="${titre};margin-bottom:5px">${esc(t.k)}</div>
            <div style="${t.vSt}">${esc(t.v)}</div>
            <div style="font-size:11px;color:var(--color-text-muted);margin-top:2px">${esc(t.s)}</div>
          </div>`).join('')}
      </div>

      <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;align-items:start">
        <div style="${carte};padding:14px 16px">
          <div style="${titre};margin-bottom:11px">Répartition des notes</div>
          ${c.suBarres.map(b => `
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
              <span style="${b.libSt};width:170px;flex:0 0 auto">${esc(b.nom)}</span>
              <span style="flex:1;min-width:0;background:var(--color-background-secondary);border-radius:999px;height:7px">
                <span style="display:block;${b.barSt}"></span></span>
              <span style="font-size:11.5px;color:var(--color-text-muted);width:44px;text-align:right;flex:0 0 auto">${b.n} · ${b.pct}%</span>
            </div>`).join('')}
        </div>

        <div style="${carte};padding:14px 16px">
          <div style="${titre};margin-bottom:11px">Par intervenant</div>
          ${c.suGens.length === 0 ? `<div style="font-size:12px;color:var(--color-text-muted)">Aucune validation sur la période.</div>` : `
          <div style="display:grid;grid-template-columns:minmax(0,1fr) auto auto auto;column-gap:14px;row-gap:8px;align-items:center">
            <span style="font-size:10px;color:var(--color-text-muted)"></span>
            <span style="font-size:10px;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted)">Validées</span>
            <span style="font-size:10px;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted)">Moyenne</span>
            <span style="font-size:10px;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted)">Ouverts</span>
            ${c.suGens.map(g => `
              <span style="font-size:12.5px;min-width:0">${esc(g.nom)}</span>
              <span style="font-size:12px;text-align:right">${g.validees}</span>
              <span style="${g.moySt};text-align:right">${g.moyenne}</span>
              <span style="${g.ouvSt};text-align:right">${g.ouverts}</span>`).join('')}
          </div>`}
        </div>
      </div>

      <div style="${carte};overflow:hidden">
        <div style="display:flex;align-items:center;gap:9px;padding:11px 16px;border-bottom:0.5px solid var(--color-border-tertiary)">
          <span style="${titre}">Signalements</span>
          <span style="font-size:11px;color:var(--color-text-muted)">les ouverts d'abord, quelle que soit leur date</span>
        </div>
        ${c.suSignalements.length === 0 ? `<div style="padding:18px;font-size:12px;color:var(--color-text-muted)">Aucun signalement — rien à traiter.</div>` : ''}
        ${c.suSignalements.map(g => `
          <div style="${g.rowSt}">
            <div style="flex:1;min-width:0">
              <div style="font-size:13px;font-weight:500;line-height:1.3">${esc(g.tache)}</div>
              <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:4px">
                <span style="font-size:11px;color:var(--color-text-muted)">${esc(g.qui)} · ${esc(g.projet)}</span>
                <span style="${g.lvlSt}"><i style="${g.lvlNumSt};font-style:normal">${g.lvlNum}</i>${esc(g.lvlTxt)}</span>
                <span style="font-size:10.5px;font-weight:500;padding:2px 7px;border-radius:999px;background:var(--color-background-secondary);border:0.5px solid var(--color-border-tertiary)">${esc(g.quoi)}</span>
                <span style="${g.etatSt}">${esc(g.etat)}</span>
                <span style="font-size:11px;color:var(--color-text-muted)">${esc(g.age)}</span>
              </div>
              ${g.aCommentaire ? `<div style="font-size:11.5px;line-height:1.45;color:var(--color-text-muted);margin-top:6px;white-space:pre-line">${esc(g.commentaire)}</div>` : ''}
            </div>
            <div style="flex:0 0 300px;display:flex;flex-direction:column;gap:6px">
              ${g.ouvert ? `
                <textarea ${x.C(g.majNote)} rows="2" placeholder="Ce qui a été fait — obligatoire pour clore"
                  style="width:100%;border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:6px 8px;font-size:11.5px;font-family:var(--font-ui);color:var(--color-text);resize:vertical;box-sizing:border-box">${esc(g.note)}</textarea>
                <div style="display:flex;gap:6px">
                  <button ${x.A(g.vu)} style="flex:0 0 auto;border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:6px 12px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Marquer vu</button>
                  <button ${x.A(g.traiter)} class="hv-fade" style="flex:1;border:none;border-radius:999px;padding:6px 12px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Traiter</button>
                </div>`
              : `<button ${x.A(g.rouvrir)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:6px 12px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Rouvrir</button>`}
            </div>
          </div>`).join('')}
      </div>

      <div style="font-size:12px;color:var(--color-text-muted)">Voir n'est pas régler : « Marquer vu » dit qu'on a lu, « Traiter » ferme et demande ce qui a été fait. Tout passe au journal.</div>
    `}
  </div>`;
}

function tplJournal(c, x){
  const { esc } = x;
  return `
  <div data-screen="journal" style="display:flex;flex-direction:column;gap:14px">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <select ${x.C(c.setLogType)} style="${selCss}">${opts(c.logTypes, c.logType)}</select>
      <select ${x.C(c.setLogQui)} style="${selCss}">${opts(c.logQuis, c.logQui)}</select>
      <input type="text" id="log-search" placeholder="Rechercher (projet, tâche, magasin…)" value="${esc(c.logQ)}" ${x.I(c.setLogQ)} style="flex:1;max-width:340px;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 10px;background:var(--color-surface);color:var(--color-text)">
      <button ${x.A(c.exportCsv)} style="margin-left:auto;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:7px 14px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Exporter CSV</button>
    </div>
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
      <table style="width:100%;border-collapse:collapse;font-size:12.5px">
        <thead><tr>
          <th style="${TH};white-space:nowrap">Horodatage</th>
          <th style="${TH2};text-align:left">Auteur</th>
          <th style="${TH2};text-align:left">Type</th>
          <th style="${TH2};text-align:left">Projet</th>
          <th style="${TH};text-align:left">Événement</th>
        </tr></thead>
        <tbody>
          ${c.logRows.map(l => `
            <tr style="border-bottom:0.5px solid var(--color-border-tertiary)">
              <td style="padding:9px 14px;white-space:nowrap;color:var(--color-text-muted)">${l.ts}</td>
              <td style="padding:9px 12px;font-weight:500;white-space:nowrap">${esc(l.qui)}</td>
              <td style="padding:9px 12px"><span style="${l.typeSt}">${esc(l.type)}</span></td>
              <td style="padding:9px 12px;color:var(--color-text-muted)">${esc(l.projet)}</td>
              <td style="padding:9px 14px;line-height:1.45">${esc(l.msg)}</td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>
  </div>`;
}

/* --- Paramètres --------------------------------------------------------------- */
function tplParams(c, x){
  const { esc } = x;
  const inputCss = 'display:block;width:100%;box-sizing:border-box;margin-top:5px;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 10px;background:var(--color-surface);color:var(--color-text)';
  const rowInput = 'width:100%;box-sizing:border-box;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 9px;background:var(--color-background-secondary);color:var(--color-text)';
  return `
  <div data-screen="parametres" style="display:grid;grid-template-columns:1fr;gap:16px;align-items:start">
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
      <div style="font-size:13px;font-weight:500;margin-bottom:4px">Les 6 leviers de gestion</div>
      <div style="font-size:12px;color:var(--color-text-muted);margin-bottom:14px">Couleurs = source de vérité of_tag.color du design system. Marge = Vente − Coût.</div>
      <div style="display:grid;grid-template-columns:186px 94px minmax(0,1fr);align-items:center;column-gap:0;row-gap:0">
        <div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding-bottom:8px;padding-right:16px">Levier</div>
        <div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding-bottom:8px;padding-right:16px">Type</div>
        <div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding-bottom:8px">Ce que le levier recouvre</div>
        ${c.paramLeviers.map(l => `
          <div style="padding:10px 16px 10px 0;border-top:0.5px solid var(--color-border-tertiary)"><span class="levier-badge" data-lev="${l.slug}"><span class="levier-dot"></span>${esc(l.nom)}</span></div>
          <div style="padding:10px 16px 10px 0;border-top:0.5px solid var(--color-border-tertiary);font-size:12px;color:var(--color-text-muted)">${l.type}</div>
          <div style="padding:10px 0;border-top:0.5px solid var(--color-border-tertiary);font-size:12px;color:var(--color-text-muted);line-height:1.45;text-wrap:pretty">${esc(l.desc)}</div>`).join('')}
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:16px">
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
        <div style="font-size:13px;font-weight:500;margin-bottom:12px">Seuils d'alerte de coûts</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label style="font-size:12px;color:var(--color-text-muted)">Food Cost max (%)
            <input type="number" value="${c.sFoodVal}" ${x.C(c.setSFood)} style="${inputCss}">
          </label>
          <label style="font-size:12px;color:var(--color-text-muted)">Labour Cost max (%)
            <input type="number" value="${c.sLabourVal}" ${x.C(c.setSLabour)} style="${inputCss}">
          </label>
        </div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:10px">Appliqués aux alertes du module Marge &amp; coûts.</div>
      </div>
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
        <div style="font-size:13px;font-weight:500;margin-bottom:12px">Modèles d'email de relance</div>
        <div style="display:flex;flex-direction:column;gap:12px">
          ${c.paramTpls.map(t => `
            <div>
              <div style="font-size:12.5px;font-weight:500">${esc(t.nom)}</div>
              <div style="font-size:11px;color:var(--color-text-muted);margin:2px 0 6px">Sujet : ${esc(t.sujet)}</div>
              <textarea ${x.C(t.set)} rows="3" style="width:100%;box-sizing:border-box;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:8px 10px;background:var(--color-background-secondary);color:var(--color-text);resize:vertical;line-height:1.5">${esc(t.corps)}</textarea>
            </div>`).join('')}
        </div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:8px">Variables : {destinataire} {tache} {projet} {echeance} {zone}</div>
      </div>
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
        <div style="font-size:13px;font-weight:500;margin-bottom:4px">Templates de projet par axe</div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:12px">Jalons (jours avant échéance) et postes de coût proposés au chargement dans « Nouveau projet ».</div>
        <select ${x.C(c.setTplAxe)} style="width:100%;box-sizing:border-box;font-size:12.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 10px;background:var(--color-surface);color:var(--color-text);margin-bottom:12px">
          ${opts(c.npAxes, c.tplAxe)}
        </select>
        <div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:6px">Jalons du rétroplanning</div>
        <div style="display:flex;flex-direction:column;gap:6px">
          ${c.tplJalons.map(jl => `
            <div style="display:grid;grid-template-columns:1fr 108px 22px;gap:6px;align-items:center">
              <input value="${esc(jl.nom)}" ${x.C(jl.setNom)} style="${rowInput}">
              <div style="display:flex;align-items:center;gap:5px">
                <input type="number" value="${jl.j}" ${x.C(jl.setJ)} max="0" style="width:56px;box-sizing:border-box;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 8px;background:var(--color-background-secondary);color:var(--color-text)">
                <span style="font-size:10.5px;color:var(--color-text-muted)">jours</span>
              </div>
              <button ${x.A(jl.del)} title="Supprimer" style="border:none;background:transparent;cursor:pointer;color:var(--color-text-muted);font-size:13px;padding:0">✕</button>
            </div>`).join('')}
        </div>
        <button ${x.A(c.tplJalAdd)} style="border:none;background:transparent;cursor:pointer;color:var(--color-primary);font-family:var(--font-ui);font-size:12px;font-weight:500;padding:6px 0 12px">+ Ajouter un jalon</button>
        <div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:6px">Postes de coût</div>
        <div style="display:flex;flex-direction:column;gap:6px">
          ${c.tplCouts.map(ct => `
            <div style="display:grid;grid-template-columns:1fr 108px 22px;gap:6px;align-items:center">
              <input value="${esc(ct.poste)}" ${x.C(ct.setPoste)} style="${rowInput}">
              <input type="number" value="${ct.prevu}" ${x.C(ct.setPrevu)} min="0" step="500" style="width:100%;box-sizing:border-box;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 8px;background:var(--color-background-secondary);color:var(--color-text)">
              <button ${x.A(ct.del)} title="Supprimer" style="border:none;background:transparent;cursor:pointer;color:var(--color-text-muted);font-size:13px;padding:0">✕</button>
            </div>`).join('')}
        </div>
        <button ${x.A(c.tplCoutAdd)} style="border:none;background:transparent;cursor:pointer;color:var(--color-primary);font-family:var(--font-ui);font-size:12px;font-weight:500;padding:6px 0 0">+ Ajouter un poste</button>
      </div>
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
        <div style="font-size:13px;font-weight:500;margin-bottom:12px">Général</div>
        <div style="display:flex;flex-direction:column;gap:9px;font-size:12.5px">
          <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Année de référence des objectifs</span><span style="font-weight:500">${c.paramExo || ''}</span></div>
        </div>
      </div>
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
        <div style="font-size:13px;font-weight:500;margin-bottom:12px">Réseau</div>
        <div style="display:flex;flex-direction:column;gap:9px;font-size:12.5px">
          <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Intervenants</span><span style="font-weight:500">${esc(c.paramIntervenants)}</span></div>
          <div style="display:flex;justify-content:space-between"><span style="color:var(--color-text-muted)">Magasins / zones d'implantation</span><span style="font-weight:500">${esc(c.paramMagasins)}</span></div>
        </div>
      </div>
    </div>
  </div>`;
}

/* --- Fiche projet (panneau latéral) ------------------------------------------ */
function tplFicheProjet(c, x){
  const { esc } = x;
  const op = c.op;
  const sec = 'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)';
  return `
  <div ${x.A(c.closeProj)} style="position:fixed;inset:0;background:rgba(34,34,34,0.35);z-index:60;animation:fadeIn 160ms ease"></div>
  <div style="position:fixed;top:0;right:0;bottom:0;width:600px;background:var(--color-surface);z-index:61;box-shadow:-12px 0 40px rgba(34,34,34,0.18);overflow-y:auto;animation:panelIn 200ms ease">
    <div style="padding:24px 28px 60px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px">
        <div>
          <div style="${sec}">Fiche projet</div>
          <h2 style="font-family:var(--font-display);font-size:24px;font-weight:400;margin:4px 0 0;line-height:1.2">${esc(op.nom)}</h2>
        </div>
        <button ${x.A(c.closeProj)} style="border:none;background:var(--color-background-secondary);border-radius:50%;width:30px;height:30px;font-size:14px;cursor:pointer;color:var(--color-text-muted);flex:0 0 auto">✕</button>
      </div>
      <div style="display:flex;align-items:center;gap:8px;margin-top:14px;flex-wrap:wrap">
        <select ${x.C(op.setStatut)} style="font-size:12px;font-weight:500;border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:5px 10px;background:var(--color-surface);color:var(--color-text)">
          ${opts(['À lancer', 'En cours', 'En retard', 'En pause', 'Terminé', 'Abandonné'], op.statut)}
        </select>
        ${op.levs.map(l => `<span class="levier-badge" data-lev="${l.slug}"><span class="levier-dot"></span>${esc(l.nom)}</span>`).join('')}
        <span style="font-size:11px;font-weight:500;padding:4px 10px;border-radius:999px;background:var(--color-background-secondary);color:var(--color-text-muted)">${esc(op.axes)}</span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:18px">
        <div><div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Début</div><div style="font-size:13px;font-weight:500;margin-top:2px">${op.debut}</div></div>
        <div><div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Échéance</div><div style="font-size:13px;font-weight:500;margin-top:2px">${op.fin}</div></div>
        <div><div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Priorité</div><div style="font-size:13px;font-weight:500;margin-top:2px">${op.prio}</div></div>
        <div><div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Avancement</div><div style="font-size:13px;font-weight:500;margin-top:2px">${op.av}</div></div>
      </div>
      <div style="background:var(--color-background-secondary);border-radius:10px;padding:12px 14px;margin-top:14px;font-size:12.5px">
        <span style="font-weight:500">Valeur visée : ${op.valeur}</span> — ${esc(op.valeurTxt)}
      </div>

      <div style="${sec};margin:22px 0 10px">Rétroplanning — jalons (calculés depuis l'échéance du ${op.fin})</div>
      <div style="display:flex;flex-direction:column;gap:0">
        ${op.jalons.map(jl => `
          <div style="display:flex;gap:12px">
            <div style="display:flex;flex-direction:column;align-items:center;width:14px;flex:0 0 auto">
              <span style="${jl.dotSt}"></span>
              <span style="flex:1;width:1.5px;background:var(--color-border-tertiary)"></span>
            </div>
            <div style="padding-bottom:16px;min-width:0;flex:1">
              <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline">
                <span style="font-size:13px;font-weight:500">${esc(jl.nom)}</span>
                <span style="font-size:11.5px;color:var(--color-text-muted);white-space:nowrap">limite ${jl.cible}</span>
              </div>
              <div style="${jl.etatSt}">${jl.etat}</div>
            </div>
          </div>`).join('')}
      </div>

      <div style="${sec};margin:14px 0 10px">Tâches &amp; responsables</div>
      <div style="display:flex;flex-direction:column;gap:8px">
        ${op.taches.map(t => `
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;border:0.5px solid var(--color-border-tertiary);border-radius:10px;padding:10px 12px">
            <div style="min-width:0">
              <div style="font-size:12.5px;font-weight:500">${esc(t.nom)}</div>
              <div style="font-size:11px;color:var(--color-text-muted);margin-top:2px">${esc(t.owner)} · ${t.ownerType} · échéance ${t.due}</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex:0 0 auto">
              <span style="${t.statutSt}">${t.statut}</span>
              ${t.relancable ? `<button ${x.A(t.relancer)} style="border:none;border-radius:7px;padding:5px 10px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Relancer</button>` : ''}
            </div>
          </div>`).join('')}
      </div>

      <div style="${sec};margin:22px 0 10px">Coût de développement — budget vs réel</div>
      <table style="width:100%;border-collapse:collapse;font-size:12.5px">
        <tbody>
          ${op.couts.map(ct => `
            <tr style="border-bottom:0.5px solid var(--color-border-tertiary)">
              <td style="padding:8px 0">${esc(ct.poste)}</td>
              <td style="padding:8px 0;text-align:right;color:var(--color-text-muted)">${ct.prevu}</td>
              <td style="padding:8px 0 8px 16px;text-align:right;font-weight:500">${ct.reel}</td>
            </tr>`).join('')}
          <tr>
            <td style="padding:10px 0;font-weight:500">Total</td>
            <td style="padding:10px 0;text-align:right;color:var(--color-text-muted)">${op.budgetTot}</td>
            <td style="padding:10px 0 10px 16px;text-align:right;font-weight:700">${op.reelTot}</td>
          </tr>
        </tbody>
      </table>
      <div style="${op.ecartSt}">${op.ecartTxt}</div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:18px">
        <div style="background:var(--color-background-secondary);border-radius:10px;padding:14px">
          <div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">ROI</div>
          <div style="font-size:22px;font-weight:500;margin-top:4px;color:${op.roiCl}">${op.roi}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted)">${op.roiPct} du coût engagé</div>
        </div>
        <div style="background:var(--color-background-secondary);border-radius:10px;padding:14px">
          <div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">KPI de suivi</div>
          <div style="font-size:13px;font-weight:500;margin-top:6px;line-height:1.5">${esc(op.kpis)}</div>
        </div>
      </div>
      <div style="margin-top:28px;padding-top:16px;border-top:0.5px solid var(--color-border-tertiary);display:flex;justify-content:flex-end">
        <button ${x.A(c.deleteProj)} title="Supprimer définitivement ce projet et son suivi" style="border:0.5px solid rgba(141,29,44,0.4);border-radius:8px;padding:8px 14px;background:transparent;color:#8D1D2C;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Supprimer le projet</button>
      </div>
    </div>
  </div>`;
}

/* --- Relance email ------------------------------------------------------------ */
function tplRelance(c, x){
  const { esc } = x;
  return `
  <div ${x.A(c.relClose)} style="position:fixed;inset:0;background:rgba(34,34,34,0.4);z-index:80;animation:fadeIn 140ms ease"></div>
  <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:560px;background:var(--color-surface);border-radius:14px;z-index:81;box-shadow:0 24px 60px rgba(34,34,34,0.25);padding:24px 26px">
    <div style="font-family:var(--font-display);font-size:20px">Relance par email</div>
    <div style="font-size:12.5px;color:var(--color-text-muted);margin-top:4px">À : <span style="font-weight:500;color:var(--color-text)">${esc(c.rel.to)}</span> — ${esc(c.rel.email)}</div>
    <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin:16px 0 5px">Sujet</div>
    <input type="text" value="${esc(c.rel.sujet)}" ${x.C(c.relSujet)} style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)">
    <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin:12px 0 5px">Message</div>
    <textarea ${x.C(c.relCorps)} rows="9" style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:10px 12px;background:var(--color-surface);color:var(--color-text);resize:vertical;line-height:1.55">${esc(c.rel.corps)}</textarea>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
      <button ${x.A(c.relClose)} style="border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 16px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:13px;font-weight:500;cursor:pointer">Annuler</button>
      <button ${x.A(c.relSend)} style="border:none;border-radius:8px;padding:9px 18px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:13px;font-weight:500;cursor:pointer">Envoyer la relance</button>
    </div>
  </div>`;
}

/* --- Aperçu PDF / Générateur HTML --------------------------------------------- */
function tplRepPrev(c, x){
  const { esc } = x;
  const rp = c.repPrev;
  return `
  <div ${x.A(c.repPrevClose)} style="position:fixed;inset:0;background:rgba(34,34,34,0.45);z-index:80;animation:fadeIn 140ms ease"></div>
  <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(880px,94vw);max-height:90vh;overflow-y:auto;background:var(--color-background-secondary);border-radius:14px;z-index:81;box-shadow:0 24px 60px rgba(34,34,34,0.3);padding:20px;animation:toastIn 180ms ease">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px">
      <div style="font-size:13px;font-weight:500">${esc(rp.nom)}</div>
      <div style="display:flex;align-items:center;gap:10px">
        <div style="display:inline-flex;border:0.5px solid var(--color-border-secondary);border-radius:999px;overflow:hidden">
          <button ${x.A(c.repPrevTabPdf)} style="${rp.tabPdfSt}">Aperçu PDF</button>
          <button ${x.A(c.repPrevTabCode)} style="${rp.tabCodeSt}">Générateur HTML</button>
        </div>
        <button ${x.A(c.repPrevClose)} style="border:none;background:transparent;cursor:pointer;color:var(--color-text-muted);font-size:16px;padding:4px">✕</button>
      </div>
    </div>
    <code style="display:block;font-size:10.5px;color:var(--color-text-muted);background:var(--color-surface);border-radius:6px;padding:6px 10px;margin-bottom:14px;overflow-wrap:break-word">${esc(rp.url)}</code>
    ${rp.isCode ? `
    <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:10px">Code du rapport généré depuis les postes cochés. Variables : <code style="font-size:10.5px">{periode}</code> <code style="font-size:10.5px">{dest}</code> <code style="font-size:10.5px">{cc}</code> <code style="font-size:10.5px">{date_generation}</code> — remplacées à la génération.</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start">
      <div style="display:flex;flex-direction:column;gap:8px">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <span style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Structure — rapport.html</span>
          <button ${x.A(rp.copyHtml)} style="border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:4px 10px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Copier</button>
        </div>
        <textarea readonly spellcheck="false" style="width:100%;box-sizing:border-box;height:480px;font-family:ui-monospace,monospace;font-size:10.5px;line-height:1.55;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:10px 12px;background:var(--color-surface);color:var(--color-text);resize:vertical;white-space:pre">${esc(rp.htmlCode)}</textarea>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <span style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Mise en forme — rapport.css</span>
          <button ${x.A(rp.copyCss)} style="border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:4px 10px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Copier</button>
        </div>
        <textarea readonly spellcheck="false" style="width:100%;box-sizing:border-box;height:480px;font-family:ui-monospace,monospace;font-size:10.5px;line-height:1.55;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:10px 12px;background:var(--color-surface);color:var(--color-text);resize:vertical;white-space:pre">${esc(rp.cssCode)}</textarea>
      </div>
    </div>` : ''}
    ${rp.isPdf ? `
    <div style="background:#fff;border-radius:4px;box-shadow:0 4px 18px rgba(34,34,34,0.14);padding:36px 40px;min-height:780px;max-width:580px;margin:0 auto">
      <div style="display:flex;align-items:baseline;justify-content:space-between;border-bottom:1.5px solid #222;padding-bottom:12px">
        <img src="${c.brandLogo}" alt="L’Atelier" style="width:120px;height:auto;display:block">

        <div style="font-size:10.5px;color:#666;text-transform:uppercase;letter-spacing:0.08em">Rapport automatique · ${rp.periodeLabel}</div>
      </div>
      <div style="font-family:var(--font-display);font-size:24px;margin-top:20px">${esc(rp.nom)}</div>
      <div style="font-size:11.5px;color:#666;margin-top:4px">À : ${esc(rp.to)}${esc(rp.ccTxt)} · Fréquence : ${rp.freq}</div>
      <div style="display:flex;flex-direction:column;gap:18px;margin-top:24px">
        ${rp.sections.map(s => `
          <div>
            <div style="display:flex;align-items:baseline;gap:8px;border-bottom:0.5px solid #ddd;padding-bottom:5px">
              <span style="font-size:10px;font-weight:600;color:#8D1D2C">${s.tag}</span>
              <span style="font-size:13px;font-weight:600">${esc(s.label)}</span>
            </div>
            <div style="font-size:11px;color:#555;margin-top:6px;line-height:1.5">${esc(s.desc)}</div>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:10px">
              <div style="height:38px;background:#f2efe9;border-radius:3px"></div>
              <div style="height:38px;background:#f2efe9;border-radius:3px"></div>
              <div style="height:38px;background:#f2efe9;border-radius:3px"></div>
              <div style="height:38px;background:#f2efe9;border-radius:3px"></div>
            </div>
          </div>`).join('')}
      </div>
      <div style="border-top:0.5px solid #ddd;margin-top:28px;padding-top:8px;font-size:9.5px;color:#999;display:flex;justify-content:space-between">
        <span>Généré automatiquement le ${rp.dateGenLabel} — cockpit L'Atelier by</span><span>1 / 1</span>
      </div>
    </div>` : ''}
  </div>`;
}

/* --- Rapport consultant -------------------------------------------------------- */
function tplEqRep(c, x){
  const { esc } = x;
  const q = c.eqRep;
  return `
  <div ${x.A(c.eqClose)} style="position:fixed;inset:0;background:rgba(34,34,34,0.4);z-index:80;animation:fadeIn 140ms ease"></div>
  <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:680px;max-height:86vh;overflow-y:auto;background:var(--color-surface);border-radius:14px;z-index:81;box-shadow:0 24px 60px rgba(34,34,34,0.25);padding:26px 28px;animation:toastIn 180ms ease">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
      <div>
        <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)">Rapport consultant</div>
        <div style="font-family:var(--font-display);font-size:22px;margin-top:2px">${esc(q.nom)}</div>
        <div style="font-size:12.5px;color:var(--color-text-muted);margin-top:2px">${esc(q.role)} · ${esc(q.email)} · TJM ${q.tjm}</div>
      </div>
      <button ${x.A(c.eqClose)} style="border:none;background:transparent;cursor:pointer;color:var(--color-text-muted);font-size:16px;padding:4px">✕</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:16px">
      <div style="background:var(--color-background-secondary);border-radius:10px;padding:12px 14px">
        <div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Fiabilité</div>
        <div style="font-size:20px;font-weight:500;margin-top:4px;color:${q.fiabCl}">${q.fiab}</div>
        <div style="font-size:11px;color:var(--color-text-muted)">${esc(q.fiabSub)}</div>
      </div>
      <div style="background:var(--color-background-secondary);border-radius:10px;padding:12px 14px">
        <div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Visites magasin</div>
        <div style="font-size:20px;font-weight:500;margin-top:4px">${q.nVisites}</div>
        <div style="font-size:11px;color:var(--color-text-muted)">${q.nSites} magasin(s) · 8 dern. semaines</div>
      </div>
      <div style="background:var(--color-background-secondary);border-radius:10px;padding:12px 14px">
        <div style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Checklist tâches</div>
        <div style="font-size:20px;font-weight:500;margin-top:4px">${q.nDone}/${q.nTaches}</div>
        <div style="font-size:11px;color:var(--color-text-muted)">livrées · ${q.nLate} en retard</div>
      </div>
    </div>
    <div style="margin-top:20px">
      <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:8px">Visites en magasin — où et quand</div>
      <div style="display:flex;flex-direction:column">
        ${q.visites.map(v => `
          <div style="display:grid;grid-template-columns:74px 210px 1fr;gap:10px;padding:8px 0;border-bottom:0.5px solid var(--color-border-tertiary);font-size:12.5px;align-items:baseline">
            <span style="color:var(--color-text-muted);white-space:nowrap">${v.date}</span>
            <span style="font-weight:500">${esc(v.store)}</span>
            <span style="color:var(--color-text-muted)">${esc(v.objet)}</span>
          </div>`).join('')}
      </div>
    </div>
    <div style="margin-top:20px">
      <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:8px">Checklist — tâches &amp; livraisons</div>
      <div style="display:flex;flex-direction:column">
        ${q.taches.map(t => `
          <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:0.5px solid var(--color-border-tertiary)">
            <span style="${t.dotSt}">${t.dot}</span>
            <div style="flex:1;min-width:0">
              <div style="font-size:12.5px;font-weight:500">${esc(t.nom)}</div>
              <div style="font-size:11px;color:var(--color-text-muted)">${esc(t.projet)} · échéance ${t.due}</div>
            </div>
            <span style="${t.stSt}">${t.st}</span>
          </div>`).join('')}
      </div>
    </div>
  </div>`;
}

/* --- Assistant « Nouveau projet » ---------------------------------------------- */
function tplWizardProjet(c, x){
  const { esc } = x;
  const lbl = 'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:6px';
  const inp = 'width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:10px 12px;font-family:var(--font-ui);font-size:13px;background:var(--color-background-secondary);color:var(--color-text)';
  const inpD = inp.replace('padding:10px 12px', 'padding:9px 12px');
  return `
  <div ${x.A(c.npClose)} style="position:fixed;inset:0;background:rgba(34,34,34,0.4);z-index:80;animation:fadeIn 140ms ease"></div>
  <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:860px;max-height:88vh;overflow-y:auto;background:var(--color-surface);border-radius:14px;z-index:81;box-shadow:0 24px 60px rgba(34,34,34,0.25);padding:26px 28px;animation:toastIn 180ms ease">
    <div style="font-family:var(--font-display);font-size:20px">Nouveau projet</div>
    <div style="font-size:12.5px;color:var(--color-text-muted);margin-top:4px">${esc(c.npStepSub)}</div>
    <div style="display:flex;align-items:center;gap:0;margin:20px 0 22px">
      ${c.npSteps.map(s => `
        <button ${x.A(s.go)} style="${s.btnSt}">
          <span style="${s.dotSt}">${s.num}</span>
          <span style="${s.labSt}">${s.label}</span>
        </button>
        ${s.sep ? `<span style="${s.sepSt}"></span>` : ''}`).join('')}
    </div>
    <div style="display:flex;flex-direction:column;gap:14px">
      ${c.npS1 ? `
      <div style="display:flex;flex-direction:column;gap:14px">
        <div>
          <div style="${lbl}">Nom du projet</div>
          <input value="${esc(c.np.nom)}" ${x.C(c.npNom)} placeholder="Ex. Refonte carte automne" style="${inp}">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div>
            <div style="${lbl}">Levier principal</div>
            <select ${x.C(c.npLev)} style="${inp}">${opts(c.npLevs, c.np.lev, o => o.slug, o => esc(o.nom))}</select>
          </div>
          <div>
            <div style="${lbl}">Axe</div>
            <select ${x.C(c.npAxe)} style="${inp}">${opts(c.npAxes, c.np.axe)}</select>
          </div>
          <div>
            <div style="${lbl}">Début</div>
            <input type="date" value="${c.np.debut}" ${x.C(c.npDebut)} style="${inpD}">
          </div>
          <div>
            <div style="${lbl}">Échéance</div>
            <input type="date" value="${c.np.fin}" ${x.C(c.npFin)} style="${inpD}">
          </div>
        </div>
      </div>` : ''}
      ${c.npS2 ? `
      <div style="display:flex;flex-direction:column;gap:14px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div>
            <div style="${lbl}">Valeur visée (€ / an)</div>
            <input type="number" value="${c.np.valeur}" ${x.C(c.npValeur)} min="0" step="1000" placeholder="Ex. 54000" style="${inpD}">
          </div>
          <div>
            <div style="${lbl}">KPI de suivi</div>
            <select ${x.C(c.npKpi)} style="${inp}">
              <option value=""${c.np.kpi === '' ? ' selected' : ''}>— Aucun —</option>
              ${opts(c.npKpis, c.np.kpi)}
            </select>
          </div>
        </div>
        <div>
          <div style="${lbl}">Valeur — description</div>
          <input value="${esc(c.np.valeurTxt)}" ${x.C(c.npValeurTxt)} placeholder="Ex. Économie annuelle réseau (9 sites)" style="${inpD}">
        </div>
        <div>
          <div style="${lbl}">Priorité</div>
          <div style="display:flex;gap:8px">
            <button ${x.A(c.npPrioH)} style="${c.npPrioHSt}">Haute</button>
            <button ${x.A(c.npPrioM)} style="${c.npPrioMSt}">Moyenne</button>
            <button ${x.A(c.npPrioB)} style="${c.npPrioBSt}">Basse</button>
          </div>
        </div>
      </div>` : ''}
      ${c.npS3 ? `
      <div style="display:flex;flex-direction:column;gap:14px">
        <div>
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px">
            <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Rétroplanning — jalons</div>
            <button ${x.A(c.npLoadJalons)} title="Jalons types de l'axe, datés depuis l'échéance. Modifiables dans Paramètres." style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:5px 12px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Charger le template de l'axe</button>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px">
            ${c.npJalons.map(jl => `
              <div style="display:grid;grid-template-columns:1fr 150px 28px;gap:8px;align-items:center">
                <input value="${esc(jl.nom)}" ${x.C(jl.setNom)} placeholder="Nom du jalon" style="${inp.replace('padding:10px 12px', 'padding:8px 12px')}">
                <input type="date" value="${jl.cible}" ${x.C(jl.setCible)} style="${inp.replace('padding:10px 12px', 'padding:7px 10px').replace('font-size:13px', 'font-size:12.5px')}">
                <button ${x.A(jl.del)} title="Supprimer" style="border:none;background:transparent;cursor:pointer;color:var(--color-text-muted);font-size:14px;padding:0">✕</button>
              </div>`).join('')}
          </div>
          <button ${x.A(c.npJalAdd)} style="border:none;background:transparent;cursor:pointer;color:var(--color-primary);font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:8px 0 0">+ Ajouter un jalon</button>
        </div>
        <div style="border-top:0.5px solid var(--color-border-tertiary);padding-top:14px">
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:8px">Tâches &amp; responsables</div>
          <div style="display:flex;flex-direction:column;gap:8px">
            ${c.npTaches.map(t => `
              <div style="display:grid;grid-template-columns:1fr 190px 150px 28px;gap:8px;align-items:center">
                <input value="${esc(t.nom)}" ${x.C(t.setNom)} placeholder="Nom de la tâche" style="${inp.replace('padding:10px 12px', 'padding:8px 12px')}">
                <select ${x.C(t.setWho)} style="${inp.replace('padding:10px 12px', 'padding:8px 10px').replace('font-size:13px', 'font-size:12.5px')}">${opts(c.npOwners, t.who, o => o.val, o => esc(o.nom))}</select>
                <input type="date" value="${t.due}" ${x.C(t.setDue)} style="${inp.replace('padding:10px 12px', 'padding:7px 10px').replace('font-size:13px', 'font-size:12.5px')}">
                <button ${x.A(t.del)} title="Supprimer" style="border:none;background:transparent;cursor:pointer;color:var(--color-text-muted);font-size:14px;padding:0">✕</button>
              </div>`).join('')}
          </div>
          <button ${x.A(c.npTacheAdd)} style="border:none;background:transparent;cursor:pointer;color:var(--color-primary);font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:8px 0 0">+ Ajouter une tâche</button>
        </div>
      </div>` : ''}
      ${c.npS4 ? `
      <div style="display:flex;flex-direction:column;gap:14px">
        <div>
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px">
            <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Coût de développement — postes budgétés</div>
            <button ${x.A(c.npLoadCouts)} title="Postes types de l'axe. Modifiables dans Paramètres." style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:5px 12px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Charger le template de l'axe</button>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px">
            ${c.npCouts.map(ct => `
              <div style="display:grid;grid-template-columns:1fr 150px 28px;gap:8px;align-items:center">
                <input value="${esc(ct.poste)}" ${x.C(ct.setPoste)} placeholder="Poste (ex. Jours-homme consultants)" style="${inp.replace('padding:10px 12px', 'padding:8px 12px')}">
                <input type="number" value="${ct.prevu}" ${x.C(ct.setPrevu)} min="0" step="500" style="${inp.replace('padding:10px 12px', 'padding:7px 10px').replace('font-size:13px', 'font-size:12.5px')}">
                <button ${x.A(ct.del)} title="Supprimer" style="border:none;background:transparent;cursor:pointer;color:var(--color-text-muted);font-size:14px;padding:0">✕</button>
              </div>`).join('')}
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px">
            <button ${x.A(c.npCoutAdd)} style="border:none;background:transparent;cursor:pointer;color:var(--color-primary);font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:0">+ Ajouter un poste</button>
            <div style="font-size:12.5px;color:var(--color-text-muted)">Budget total : <span style="font-weight:500;color:var(--color-text)">${c.npBudgetTot}</span></div>
          </div>
        </div>
        <div style="background:var(--color-background-secondary);border-radius:10px;padding:16px 18px">
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:10px">Récapitulatif</div>
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px">
            ${c.npRecap.map(r => `
              <div>
                <div style="font-size:10.5px;color:var(--color-text-muted)">${r.k}</div>
                <div style="font-size:12.5px;font-weight:500;margin-top:3px;line-height:1.35">${esc(r.v)}</div>
              </div>`).join('')}
          </div>
        </div>
      </div>` : ''}
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:24px;padding-top:18px;border-top:0.5px solid var(--color-border-tertiary)">
      <button ${x.A(c.npClose)} style="border:none;background:transparent;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:9px 0;color:var(--color-text-muted)">Annuler</button>
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:12px;color:${c.npWarnCol}">${c.npWarn}</span>
        ${c.npCanPrev ? `<button ${x.A(c.npPrev)} style="border:0.5px solid var(--color-border-secondary);background:transparent;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:9px 18px;border-radius:999px;color:var(--color-text)">Précédent</button>` : ''}
        ${c.npCanNext ? `<button ${x.A(c.npNext)} class="hv-fade" style="border:none;cursor:pointer;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:9px 20px;border-radius:999px">Suivant</button>` : ''}
        ${c.npS4 ? `<button ${x.A(c.npCreate)} class="hv-fade" style="border:none;cursor:pointer;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:9px 20px;border-radius:999px">Créer le projet</button>` : ''}
      </div>
    </div>
  </div>`;
}

/* --- Assistant « Nouvelle tâche » ----------------------------------------------- */
function tplWizardTache(c, x){
  const { esc } = x;
  const lbl = 'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:6px';
  const inp = 'width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:10px 12px;font-family:var(--font-ui);font-size:13px;background:var(--color-background-secondary);color:var(--color-text)';
  return `
  <div ${x.A(c.ntClose)} style="position:fixed;inset:0;background:rgba(34,34,34,0.4);z-index:80;animation:fadeIn 140ms ease"></div>
  <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:760px;max-height:88vh;overflow-y:auto;background:var(--color-surface);border-radius:14px;z-index:81;box-shadow:0 24px 60px rgba(34,34,34,0.25);padding:26px 28px;animation:toastIn 180ms ease">
    <div style="font-family:var(--font-display);font-size:20px">Nouvelle tâche</div>
    <div style="font-size:12.5px;color:var(--color-text-muted);margin-top:4px">${esc(c.ntStepSub)}</div>
    <div style="display:flex;align-items:center;gap:0;margin:20px 0 22px">
      ${c.ntSteps.map(s => `
        <button ${x.A(s.go)} style="${s.btnSt}">
          <span style="${s.dotSt}">${s.num}</span>
          <span style="${s.labSt}">${s.label}</span>
        </button>
        ${s.sep ? `<span style="${s.sepSt}"></span>` : ''}`).join('')}
    </div>

    ${c.ntS1 ? `
    <div style="display:flex;flex-direction:column;gap:14px">
      <div>
        <div style="${lbl}">Projet de rattachement</div>
        <select ${x.C(c.ntProjet)} style="${inp}">${opts(c.ntProjets, c.nt.projet, o => o.val, o => esc(o.nom))}</select>
      </div>
      <div>
        <div style="${lbl}">Intitulé de la tâche</div>
        <input value="${esc(c.nt.nom)}" ${x.C(c.ntNom)} placeholder="Ex. Audit plannings — magasins Flandre" style="${inp}">
      </div>
      <div>
        <div style="${lbl}">Magasin concerné — optionnel</div>
        <select ${x.C(c.ntMagasin)} style="${inp}">${opts(c.ntMagasins, c.nt.magasin, o => o.val, o => esc(o.nom))}</select>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:5px">Laissez « Aucun — tâche réseau » si la tâche ne porte pas sur un point de vente précis.</div>
      </div>
      <div style="background:var(--color-background-secondary);border-radius:10px;padding:14px 16px">
        <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Objectif du projet</div>
        <div style="font-size:12.5px;line-height:1.5;margin-top:5px">${esc(c.ntObjectif)}</div>
      </div>
    </div>` : ''}

    ${c.ntS2 ? `
    <div style="display:flex;flex-direction:column;gap:14px">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div>
          <div style="${lbl}">Responsable</div>
          <select ${x.C(c.ntWho)} style="${inp}">${opts(c.npOwners, c.nt.who, o => o.val, o => esc(o.nom))}</select>
        </div>
        <div>
          <div style="${lbl}">Échéance</div>
          <input type="date" value="${c.nt.due}" ${x.C(c.ntDue)} style="${inp.replace('padding:10px 12px', 'padding:9px 12px')}">
        </div>
      </div>
      <div>
        <div style="${lbl}">Colonne de départ</div>
        <div style="display:flex;gap:8px">
          ${c.ntCols.map(col => `<button ${x.A(col.pick)} style="${col.st}">${col.nom}</button>`).join('')}
        </div>
      </div>
      <div style="font-size:12px;color:var(--color-text-muted)">${esc(c.ntCharge)}</div>
    </div>` : ''}

    ${c.ntS3 ? `
    <div style="background:var(--color-background-secondary);border-radius:10px;padding:16px 18px">
      <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:10px">Récapitulatif</div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px">
        ${c.ntRecap.map(r => `
          <div>
            <div style="font-size:10.5px;color:var(--color-text-muted)">${r.k}</div>
            <div style="font-size:12.5px;font-weight:500;margin-top:3px;line-height:1.35">${esc(r.v)}</div>
          </div>`).join('')}
      </div>
    </div>` : ''}

    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:24px;padding-top:18px;border-top:0.5px solid var(--color-border-tertiary)">
      <button ${x.A(c.ntClose)} style="border:none;background:transparent;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:9px 0;color:var(--color-text-muted)">Annuler</button>
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:12px;color:${c.ntWarnCol}">${c.ntWarn}</span>
        ${c.ntCanPrev ? `<button ${x.A(c.ntPrev)} style="border:0.5px solid var(--color-border-secondary);background:transparent;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:9px 18px;border-radius:999px;color:var(--color-text)">Précédent</button>` : ''}
        ${c.ntCanNext ? `<button ${x.A(c.ntNext)} class="hv-fade" style="border:none;cursor:pointer;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:9px 20px;border-radius:999px">Suivant</button>` : ''}
        ${c.ntS3 ? `<button ${x.A(c.ntCreate)} class="hv-fade" style="border:none;cursor:pointer;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:9px 20px;border-radius:999px">Créer la tâche</button>` : ''}
      </div>
    </div>
  </div>`;
}

/* --- Détail d'une tâche checklist : photo de réalisation + notation --------- */
function tplCtrlDetail(c, x){
  const { esc } = x;
  const d = c.ctrlDet;
  return `
  <div ${x.A(d.close)} style="position:fixed;inset:0;background:rgba(34,34,34,0.35);z-index:60;animation:fadeIn 160ms ease"></div>
  <div style="position:fixed;top:0;right:0;bottom:0;width:560px;background:var(--color-surface);z-index:61;box-shadow:-12px 0 40px rgba(34,34,34,0.18);overflow-y:auto;animation:panelIn 200ms ease">
    <div style="padding:24px 28px 60px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px">
        <div>
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)">Tâche checklist${d.checklist ? ' — ' + esc(d.checklist) : ''}</div>
          <h2 style="font-family:var(--font-display);font-size:22px;font-weight:400;margin:4px 0 0;line-height:1.2">${esc(d.nom)}</h2>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:4px">${esc(d.date)}${d.statut ? ' · ' + esc(d.statut) : ''}${d.obligatoire ? ' · ' + esc(d.obligatoire) : ''}</div>
        </div>
        <button ${x.A(d.close)} style="border:none;background:var(--color-background-secondary);border-radius:50%;width:30px;height:30px;font-size:14px;cursor:pointer;color:var(--color-text-muted);flex:0 0 auto">✕</button>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:20px">
        <div>
          <div style="${'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)'};margin-bottom:6px">Photo en boutique</div>
          ${d.photo
            ? `<a href="${d.photo}" target="_blank" rel="noopener" title="Ouvrir en grand"><img src="${d.photo}" alt="Photo de réalisation" style="width:100%;border-radius:10px;border:0.5px solid var(--color-border-tertiary);display:block"></a>`
            : `<div style="background:var(--color-background-secondary);border-radius:10px;padding:22px 12px;text-align:center;font-size:12px;color:var(--color-text-muted);line-height:1.5">${esc(d.photoTxt)}</div>`}
        </div>
        <div>
          <div style="${'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)'};margin-bottom:6px">Référence attendue${d.produit ? ' — ' + esc(d.produit) : ''}</div>
          ${d.photoRef
            ? `<a href="${d.photoRef}" target="_blank" rel="noopener" title="Ouvrir en grand"><img src="${d.photoRef}" alt="Photo de référence du produit" style="width:100%;border-radius:10px;border:0.5px solid var(--color-border-tertiary);display:block"></a>`
            : `<div style="background:var(--color-background-secondary);border-radius:10px;padding:22px 12px;text-align:center;font-size:12px;color:var(--color-text-muted);line-height:1.5">${esc(d.photoRefTxt)}</div>`}
        </div>
      </div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:6px">Un contrôle qualité se juge par comparaison : la fiche technique du produit s\u2019affiche en face de la photo prise en boutique.</div>

      <div style="${'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)'};margin:22px 0 6px">Avis du consultant</div>
      <div style="font-size:13px;font-weight:500">${esc(d.avisTxt)}</div>
      ${d.avisComment ? `<div style="font-size:12.5px;color:var(--color-text-muted);margin-top:4px;line-height:1.5">${esc(d.avisComment)}</div>` : ''}

      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin:22px 0 8px">
        <div style="${'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)'}">Votre évaluation</div>
        ${d.verdict ? `<span style="${d.verdictSt}">${esc(d.verdict)}</span>` : ''}
      </div>
      ${d.niveaux.map(lv => `
        <button ${x.A(lv.pick)} style="${lv.st}">
          <span style="${lv.dotSt}"></span>
          <span style="flex:1">${esc(lv.nom)}</span>
          ${lv.aide ? `<span style="font-size:11px;color:var(--color-text-muted);font-weight:400">${esc(lv.aide)}</span>` : ''}
        </button>`).join('')}
      <textarea ${x.C(d.setComment)} rows="4" placeholder="${d.commentRequis ? 'Commentaire obligatoire pour une non-conformité' : 'Commentaire (facultatif)'}" style="width:100%;box-sizing:border-box;margin-top:10px;font-size:13px;border:0.5px solid ${d.commentRequis && !d.comment ? '#8D1D2C' : 'var(--color-border-secondary)'};border-radius:8px;padding:10px 12px;background:var(--color-surface);color:var(--color-text);resize:vertical;line-height:1.55">${esc(d.comment)}</textarea>
      ${d.erreur ? `<div style="margin-top:10px;padding:9px 12px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:12px">${esc(d.erreur)}</div>` : ''}
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
        <button ${x.A(d.close)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:9px 18px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">Fermer</button>
        ${d.peutNoter ? `<button ${x.A(d.send)} style="border:none;border-radius:999px;padding:9px 20px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:${d.envoi ? 'wait' : 'pointer'};opacity:${d.envoi ? '0.6' : '1'}">${d.envoi ? 'Envoi…' : 'Envoyer la note'}</button>` : ''}
      </div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:10px;line-height:1.5">La note part sur l\u2019API du panel (source de v\u00e9rit\u00e9) et est recopi\u00e9e dans le journal des avis.</div>
    </div>
  </div>`;
}

/* --- Panneau utilisateur : mon identité + le compte consultant (API panel) --- */
function tplUserPanel(c, x){
  const { esc } = x;
  const u = c.userPanel;
  const sec = 'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)';
  return `
  <div ${x.A(u.close)} style="position:fixed;inset:0;background:rgba(34,34,34,0.35);z-index:70;animation:fadeIn 160ms ease"></div>
  <div style="position:fixed;top:0;right:0;bottom:0;width:520px;background:var(--color-surface);z-index:71;box-shadow:-12px 0 40px rgba(34,34,34,0.18);overflow-y:auto;animation:panelIn 200ms ease">
    <div style="padding:24px 28px 60px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px">
        <div style="display:flex;align-items:center;gap:12px">
          <div style="width:42px;height:42px;border-radius:50%;background:var(--color-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:500">${esc(u.initiales || '—')}</div>
          <div>
            <div style="${sec}">Mon compte</div>
            <h2 style="font-family:var(--font-display);font-size:21px;font-weight:400;margin:2px 0 0;line-height:1.2">${esc(u.nom || 'Sans nom')}</h2>
          </div>
        </div>
        <button ${x.A(u.close)} style="border:none;background:var(--color-background-secondary);border-radius:50%;width:30px;height:30px;font-size:14px;cursor:pointer;color:var(--color-text-muted);flex:0 0 auto">\u2715</button>
      </div>

      <div style="${sec};margin:24px 0 8px">Identité affichée</div>
      <div style="font-size:12px;color:var(--color-text-muted);margin-bottom:10px;line-height:1.5">C\u2019est ce nom qui appara\u00eet comme relecteur sur les t\u00e2ches que vous notez.</div>
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
        <div>
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:5px">Nom</div>
          <input type="text" value="${esc(u.nom)}" ${x.C(u.setNom)} placeholder="Pr\u00e9nom Nom" style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)">
        </div>
        <div>
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:5px">Initiales</div>
          <input type="text" value="${esc(u.initiales)}" ${x.C(u.setInit)} maxlength="3" placeholder="AB" style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)">
        </div>
      </div>
      <div style="margin-top:12px">
        <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:5px">R\u00f4le</div>
        ${u.aRoles
          ? `<select ${x.C(u.setRole)} style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)">${u.roles.map(r => `<option value="${esc(r)}"${r === u.role ? ' selected' : ''}>${esc(r)}</option>`).join('')}${u.roles.indexOf(u.role) < 0 && u.role ? `<option value="${esc(u.role)}" selected>${esc(u.role)}</option>` : ''}</select>
             <div style="font-size:11px;color:var(--color-text-muted);margin-top:4px">R\u00f4les lus dans la base partag\u00e9e (atelierby_db).</div>`
          : `<input type="text" value="${esc(u.role)}" ${x.C(u.setRole)} placeholder="CEO \u00b7 admin" style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)">
             <div style="font-size:11px;color:var(--color-text-muted);margin-top:4px">Aucun r\u00e9f\u00e9rentiel de r\u00f4les dans la base partag\u00e9e : saisie libre.</div>`}
      </div>
      <div style="display:flex;justify-content:flex-end;margin-top:12px">
        <button ${x.A(u.saveIdent)} style="border:none;border-radius:999px;padding:8px 18px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">Enregistrer mon identit\u00e9</button>
      </div>
      ${u.identMsg ? `<div style="${u.identMsgSt}">${esc(u.identMsg)}</div>` : ''}

      <div style="border-top:0.5px solid var(--color-border-tertiary);margin:26px 0 0"></div>

      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:22px">
        <div style="${sec}">Compte consultant (API panel)</div>
        <span style="${c.paEtatSt}">${esc(c.paEtat)}</span>
      </div>
      <div style="font-size:12px;color:var(--color-text-muted);margin-top:8px;line-height:1.55;text-wrap:pretty">Les noms des t\u00e2ches, les photos de r\u00e9alisation et l\u2019envoi des notes passent par l\u2019API du panel consultant. Renseignez le compte que le cockpit utilise pour s\u2019y connecter \u2014 m\u00eame t\u00e9l\u00e9phone et mot de passe que dans le panel. Le mot de passe n\u2019est jamais r\u00e9affich\u00e9 ; le laisser vide conserve celui d\u00e9j\u00e0 enregistr\u00e9.</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px">
        <div>
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:5px">T\u00e9l\u00e9phone du compte</div>
          <input type="text" value="${esc(c.paPhone)}" ${x.C(c.setPaPhone)} placeholder="+32\u2026" style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)">
        </div>
        <div>
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:5px">Mot de passe</div>
          <input type="password" value="${esc(c.paPass)}" ${x.C(c.setPaPass)} placeholder="${esc(c.paPassPlaceholder)}" autocomplete="new-password" style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)">
        </div>
      </div>
      <div style="margin-top:12px">
        <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:5px">Base d\u2019URL de l\u2019API (vide = valeur par d\u00e9faut)</div>
        <input type="text" value="${esc(c.paBase)}" ${x.C(c.setPaBase)} placeholder="https://\u2026/api/v1" style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)">
      </div>
      ${c.paMsg ? `<div style="${c.paMsgSt}">${esc(c.paMsg)}</div>` : ''}
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px">
        <button ${x.A(c.paTest)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:9px 16px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:${c.paBusy ? 'wait' : 'pointer'}">Tester la connexion</button>
        <button ${x.A(c.paSave)} style="border:none;border-radius:999px;padding:9px 20px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:${c.paBusy ? 'wait' : 'pointer'};opacity:${c.paBusy ? '0.6' : '1'}">${c.paBusy ? 'Enregistrement\u2026' : 'Enregistrer le compte'}</button>
      </div>

      ${u.canLogout ? `<div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:26px;padding-top:16px;display:flex;justify-content:flex-end">
        <button ${x.A(u.logout)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:8px 16px;background:transparent;color:var(--color-text-muted);font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Se d\u00e9connecter</button>
      </div>` : ''}
    </div>
  </div>`;
}

/* --- Réglages du scoring produits (sous-menu Paramètres) --------------------- */
function tplScoring(c, x){
  const { esc } = x;
  return `
  <div data-screen="scoring" style="display:flex;flex-direction:column;gap:16px;max-width:900px">
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
      <div style="font-size:13px;font-weight:500;margin-bottom:4px">Pondération des critères</div>
      <div style="font-size:12px;color:var(--color-text-muted);margin-bottom:14px;line-height:1.55;text-wrap:pretty">Le score sur 100 est la moyenne pondérée de quatre notes. Les poids sont relatifs : leur somme n\u2019a pas besoin de faire 100, la part effective est recalculée. Un critère sans donnée est EXCLU du calcul et le score est repondéré sur les autres — il n\u2019est jamais pénalisé par une donnée manquante.</div>
      <div style="display:flex;flex-direction:column;gap:12px">
        ${c.scCriteres.map(cr => `
          <div style="display:grid;grid-template-columns:1fr 110px 70px;gap:12px;align-items:center">
            <div>
              <div style="font-size:13px;font-weight:500">${esc(cr.nom)}</div>
              <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">${esc(cr.aide)}</div>
            </div>
            <input type="number" min="0" step="5" value="${esc(cr.val)}" ${x.C(cr.set)} style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)">
            <div style="font-size:13px;font-weight:600;text-align:right">${esc(cr.part)}</div>
          </div>`).join('')}
      </div>
    </div>

    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
      <div style="font-size:13px;font-weight:500;margin-bottom:4px">Échelle de la marge nette</div>
      <div style="font-size:12px;color:var(--color-text-muted);margin-bottom:14px;line-height:1.55;text-wrap:pretty">Le taux de marge est converti en note sur une échelle ABSOLUE, définie par deux bornes : linéaire entre elles, plafonnée au-delà. Une note absolue ne bouge pas quand un AUTRE produit change — contrairement à une note relative à la gamme.</div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px">
        <div><div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:8px">Marge basse (%)</div><input type="number" min="0" max="100" step="1" value="${esc(c.scMBas)}" ${x.C(c.setScMBas)} style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)"></div>
        <div><div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:8px">vaut (points)</div><input type="number" min="0" max="100" step="1" value="${esc(c.scMBasNote)}" ${x.C(c.setScMBasNote)} style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)"></div>
        <div><div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:8px">Marge haute (%)</div><input type="number" min="0" max="100" step="1" value="${esc(c.scMHaut)}" ${x.C(c.setScMHaut)} style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)"></div>
        <div><div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:8px">vaut (points)</div><input type="number" min="0" max="100" step="1" value="${esc(c.scMHautNote)}" ${x.C(c.setScMHautNote)} style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)"></div>
      </div>
      <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:8px">${esc(c.scMargeApercu)}</div>
    </div>

    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
      <div style="font-size:13px;font-weight:500;margin-bottom:14px">Seuils de verdict (score sur 100)</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div><div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:8px;color:#2d7a3e">\u2265 Moteur de gamme</div><input type="number" min="0" max="100" step="1" value="${esc(c.scMoteur)}" ${x.C(c.setScMoteur)} style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)"></div>
        <div><div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:8px;color:#8D1D2C">&lt; \u00c0 arbitrer</div><input type="number" min="0" max="100" step="1" value="${esc(c.scConforter)}" ${x.C(c.setScConforter)} style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)"></div>
      </div>
    </div>

    ${c.scAlerte ? `<div style="padding:11px 14px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:12.5px;font-weight:500">${esc(c.scAlerte)}</div>` : ''}
    ${c.scMsg ? `<div style="${c.scMsgSt}">${esc(c.scMsg)}</div>` : ''}
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button ${x.A(c.scReset)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:9px 18px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">Annuler</button>
      <button ${x.A(c.scSave)} style="border:none;border-radius:999px;padding:9px 20px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">Enregistrer</button>
    </div>
  </div>`;
}

/* --- Perte d'une référence, magasin par magasin ------------------------------ */
function tplPerteMagasins(c, x){
  const { esc } = x;
  const w = c.pdWaste;
  return `
  <div ${x.A(w.close)} style="position:fixed;inset:0;background:rgba(34,34,34,0.4);z-index:80;animation:fadeIn 140ms ease"></div>
  <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:660px;max-height:86vh;overflow-y:auto;background:var(--color-surface);border-radius:14px;z-index:81;box-shadow:0 24px 60px rgba(34,34,34,0.25);padding:24px 26px">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px">
      <div>
        <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)">Taux de perte par magasin</div>
        <div style="font-family:var(--font-display);font-size:20px;margin-top:3px">${esc(w.nom)}</div>
        ${w.periode ? `<div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">${esc(w.periode)}</div>` : ''}
      </div>
      <button ${x.A(w.close)} style="border:none;background:var(--color-background-secondary);border-radius:50%;width:30px;height:30px;font-size:14px;cursor:pointer;color:var(--color-text-muted);flex:0 0 auto">\u2715</button>
    </div>
    <div style="display:flex;align-items:baseline;gap:12px;margin-top:16px;padding:12px 14px;background:var(--color-background-secondary);border-radius:10px">
      <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">R\u00e9seau</div>
      <div style="font-size:20px;font-weight:600">${esc(w.reseauTaux)}</div>
      <div style="font-size:11.5px;color:var(--color-text-muted)">${esc(w.reseauDetail)}</div>
    </div>
    ${w.chargement ? `<div style="padding:24px;text-align:center;font-size:12.5px;color:var(--color-text-muted)">Lecture des pertes magasin par magasin\u2026</div>` : ''}
    ${w.erreur ? `<div style="margin-top:12px;padding:9px 12px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:12px">${esc(w.erreur)}</div>` : ''}
    ${(!w.chargement && w.vide) ? `<div style="padding:20px;text-align:center;font-size:12.5px;color:var(--color-text-muted)">Aucune perte enregistr\u00e9e sur cette r\u00e9f\u00e9rence pour la p\u00e9riode.</div>` : ''}
    ${(!w.chargement && !w.vide) ? `
    <div style="display:flex;flex-direction:column;gap:2px;margin-top:14px">
      ${w.rows.map(r => `
        <div style="padding:10px 2px;border-bottom:0.5px solid var(--color-border-tertiary)">
          <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px">
            <span style="font-size:13px;font-weight:500">${esc(r.magasin)}</span>
            <span style="${r.tauxSt};font-size:14px">${esc(r.taux)}</span>
          </div>
          <span style="display:block;height:5px;border-radius:999px;background:var(--color-background-secondary);margin-top:6px"><span style="${r.barSt}"></span></span>
          <div style="display:flex;justify-content:space-between;gap:10px;margin-top:4px;font-size:11.5px;color:var(--color-text-muted)">
            <span>${esc(r.detail)}${r.motif ? ' \u00b7 ' + esc(r.motif) : ''}</span>
            ${r.caPerdu ? `<span>${esc(r.caPerdu)} perdu</span>` : ''}
          </div>
        </div>`).join('')}
    </div>
    ${w.note ? `<div style="font-size:11.5px;color:var(--color-text-muted);margin-top:12px;line-height:1.5;text-wrap:pretty">${esc(w.note)}</div>` : ''}` : ''}
    <div style="display:flex;justify-content:flex-end;margin-top:16px">
      <button ${x.A(w.close)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:9px 18px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">Fermer</button>
    </div>
  </div>`;
}
