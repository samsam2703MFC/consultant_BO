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
    <!-- Recherche globale. Elle cherche dans les DONNÉES, pas seulement dans le
         nom des écrans : savoir quel écran porte « Citron Meringué » ou
         « Vitrine 1 » supposait sinon de connaître le rangement. Chaque
         résultat ouvre son écran ET y pose le filtre qui isole la ligne. -->
    <div style="padding:0 12px 12px;position:relative">
      <div style="position:relative">
        <input id="rail-q" value="${esc(c.gq)}" ${x.I(c.gSet)} placeholder="Rechercher partout…"
          style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);background:var(--color-background-secondary);color:var(--color-text);border-radius:9px;height:32px;padding:0 ${c.gVider ? '28px' : '10px'} 0 10px;font-family:var(--font-ui);font-size:12px">
        ${c.gVider ? `<button ${x.A(c.gVider)} title="Effacer" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:pointer;padding:2px 4px;line-height:1">✕</button>` : ''}
      </div>
      ${c.gOuvert ? `<div data-scroll="railq" style="position:absolute;left:12px;right:12px;top:38px;z-index:40;max-height:62vh;overflow-y:auto;background:var(--color-surface);border:0.5px solid var(--color-border-secondary);border-radius:10px;box-shadow:0 14px 34px rgba(34,34,34,0.18)">
        ${c.gRien
          ? `<div style="padding:14px 13px;font-size:11.5px;color:var(--color-text-muted);line-height:1.5">Rien de ce nom.${c.gAttente ? ' Le ' + esc(c.gAttente) + ' est encore en lecture — réessayez dans un instant.' : ''}</div>`
          : c.gGroupes.map(g => `
            <div style="padding:9px 12px 4px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted);border-top:0.5px solid var(--color-border-tertiary)">${esc(g.nom)}</div>
            ${g.lignes.map(l => `<button ${x.A(l.aller)} style="display:block;width:100%;text-align:left;border:none;background:transparent;cursor:pointer;padding:6px 12px;font-family:var(--font-ui)">
              <div style="font-size:12px;font-weight:500;color:var(--color-text);line-height:1.35">${esc(l.titre)}${l.marque ? ` <span style="font-weight:400;font-size:10px;color:var(--color-primary)">${esc(l.marque)}</span>` : ''}</div>
              ${l.detail ? `<div style="font-size:10.5px;color:var(--color-text-muted);line-height:1.35">${esc(l.detail)}</div>` : ''}
            </button>`).join('')}`).join('')}
        ${c.gTrop ? `<div style="padding:8px 12px;font-size:10.5px;color:var(--color-text-muted);border-top:0.5px solid var(--color-border-tertiary)">${c.gTrop} résultat(s) de plus — précisez la recherche.</div>` : ''}
      </div>` : ''}
    </div>

    <nav style="flex:1;padding:0 12px 16px;display:flex;flex-direction:column;gap:18px">
      ${(c.nav || []).map(g => `
        <div style="display:flex;flex-direction:column;gap:2px">
          <!-- L'intitulé de section porte la graisse, les entrées ne l'ont plus.
               Auparavant l'inverse se lisait : le titre en 10 px gris passait
               DERRIÈRE ses propres items en 13 px noir, et la section semblait
               moins importante que son contenu. -->
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:var(--color-text);padding:0 10px 7px">${esc(g.titre)}</div>
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
      ${c.build ? `<span title="Version en ligne — si elle est plus ancienne que la livraison, le navigateur sert une version en cache (Ctrl+Maj+R)" style="font-size:9.5px;color:var(--color-text-muted);white-space:nowrap;font-variant-numeric:tabular-nums">v${esc(c.build)}</span>` : ''}
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
      ${(c.lacunes && c.lacunes.length) ? `<div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:14px 16px;margin-bottom:14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:9px">Ce que cet écran ne peut pas afficher</div>
        ${c.lacunes.map(l => `<div style="display:flex;gap:9px;align-items:baseline;padding:4px 0;flex-wrap:wrap">
          <span style="font-size:11px;font-weight:500;padding:2px 8px;border-radius:999px;white-space:nowrap;${l.api
            ? 'background:#FBEFE0;color:var(--color-on-abricot);border:1px solid #E8C9A0'
            : 'background:var(--color-background-secondary);color:var(--color-text-muted);border:1px solid var(--color-border-tertiary)'}">${esc(l.etiquette)}</span>
          <span style="font-size:12.5px;font-weight:500">${esc(l.champ)}</span>
          <span style="font-size:12px;color:var(--color-text-muted)">${esc(l.quoi)} — ${esc(l.source)}</span>
        </div>`).join('')}
      </div>` : ''}
      ${c.isCat || c.isAsso || c.isPlano ? tplReferentiel(c, x) : ''}
      ${c.isProd ? tplProduction(c, x) : ''}
      ${c.isAnalyse ? tplAnalyse(c, x) : ''}
      ${c.isCentrale ? tplCentrale(c, x) : ''}
      ${c.isDiag ? tplDiagnostic(c, x) : ''}
      ${c.isSeuil ? tplSeuil(c, x) : ''}
      ${c.isFonds ? tplFonds(c, x) : ''}
      ${c.isMktCal ? tplMktCalendrier(c, x) : ''}
      ${c.isMktCamp ? tplMktCampagnes(c, x) : ''}
      ${c.isMktTypes ? tplMktTypes(c, x) : ''}
      ${c.isReput ? tplReputation(c, x) : ''}
      ${c.isRJour ? tplResultatJour(c, x) : ''}
      ${c.isExploit ? tplExploitation(c, x) : ''}
      ${c.isMagasins ? tplMagasins(c, x) : ''}
      ${c.isHeatmap ? tplHeatmap(c, x) : ''}
      ${c.isObjectifs ? tplObjectifs(c, x) : ''}
      ${c.isBudget ? tplBudget(c, x) : ''}
      ${c.isMarge ? tplMarge(c, x) : ''}
      ${c.isEncodage ? tplEncodage(c, x) : ''}
      ${c.isBudgetParam ? tplBudgetParam(c, x) : ''}
      ${c.isBxc ? tplBxc(c, x) : ''}
      ${c.isMesure ? tplMesure(c, x) : ''}
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
  ${c.pdDet ? tplScoringDetail(c, x) : ''}
  ${c.userPanel ? tplUserPanel(c, x) : ''}
  ${c.ctrlDet ? tplCtrlDetail(c, x) : ''}
  ${c.ctrlZoom ? tplCtrlZoom(c, x) : ''}
  ${c.plFiche ? tplPlanoFiche(c, x) : ''}
  ${c.plMw ? tplPlanoMeubleWizard(c, x) : ''}
  ${c.anDetail ? tplAnalyseDetail(c, x) : ''}
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
    <div style="margin-top:16px;background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:16px 18px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px;flex-wrap:wrap">
        <div>
          <div style="font-size:13px;font-weight:500">Analyse rentabilité — résultat net par jour</div>
          <div style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.exRent.periode)}</div>
        </div>
        <div style="display:flex;gap:3px;background:var(--color-background-secondary);padding:3px;border-radius:9px">
          ${c.exRent.btns.map(b => `<button ${x.A(b.go)} style="${b.st}">${esc(b.label)}</button>`).join('')}
        </div>
      </div>
      ${c.exRent.chargement ? `<div style="padding:18px 0;font-size:12.5px;color:var(--color-text-muted)">Lecture de l’API du panel…</div>`
        : (c.exRent.indispo ? `<div style="padding:18px 0;font-size:12.5px;color:var(--color-text-muted)">${esc(c.exRent.indispo)}</div>` : `
      <div style="display:flex;flex-direction:column;gap:10px">
        ${c.exRent.lignes.map(l => `
          <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
            <div style="width:210px;flex:none">
              <div style="font-weight:500;font-size:12.5px">${esc(l.nom)}</div>
              ${l.total ? `<div style="font-size:11px;color:var(--color-text-muted)">${esc(l.total)}</div>` : ''}
            </div>
            ${l.motif ? `<div style="font-size:11.5px;color:var(--color-text-muted)">${esc(l.motif)}</div>` : `
            <div style="display:flex;gap:5px;flex-wrap:wrap">
              ${l.chips.map(ch => ch.go ? `
                <button ${x.A(ch.go)} title="${esc(ch.title)}" style="border:none;cursor:pointer;font-family:var(--font-ui);border-radius:8px;text-align:center;${ch.semaine ? 'width:62px;padding:6px 0' : 'min-width:34px;padding:5px 3px'};${ch.st}">
                  <span style="display:block;font-size:9.5px;letter-spacing:0.04em;opacity:0.85">${esc(ch.lib)}</span>
                  <span style="display:block;font-size:${ch.semaine ? '12px' : '10px'};font-weight:600;margin-top:1px">${esc(ch.pct)}</span>
                </button>` : `
                <span title="${esc(ch.title)}" style="display:inline-block;border-radius:8px;text-align:center;${ch.semaine ? 'width:62px;padding:6px 0' : 'min-width:34px;padding:5px 3px'};${ch.st}">
                  <span style="display:block;font-size:9.5px;letter-spacing:0.04em;opacity:0.85">${esc(ch.lib)}</span>
                  <span style="display:block;font-size:${ch.semaine ? '11px' : '10px'};margin-top:1px">${esc(ch.pct)}</span>
                </span>`).join('')}
            </div>`}
          </div>`).join('')}
      </div>
      <div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:12px;padding-top:10px;display:flex;gap:14px;flex-wrap:wrap;align-items:center;font-size:11px;color:var(--color-text-muted)">
        <span style="font-weight:500">Résultat net :</span>
        ${c.exRent.legende.map(g => `<span><i style="display:inline-block;width:10px;height:10px;border-radius:3px;vertical-align:-1px;${g.st}"></i> ${esc(g.lib)}</span>`).join('')}
        ${c.exRent.source ? `<span style="margin-left:auto">${esc(c.exRent.source)}</span>` : ''}
      </div>`)}
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
    <div style="margin-top:16px">${tplRatiosCouts(c, x)}</div>
    ${c.exRentDet ? tplExploitRentModal(c.exRentDet, x) : ''}
    ${c.exDetail ? tplExploitDetail(c, x) : ''}
    <div style="margin-top:11px;display:flex;gap:18px;flex-wrap:wrap;font-size:11.5px;color:var(--color-text-muted)">
      <span><i style="display:inline-block;width:9px;height:9px;background:var(--color-primary);border-radius:2px;vertical-align:-1px"></i> ${esc(c.exLegendeReel)}</span>
      <span>${c.exCumul
        ? `<i style="display:inline-block;width:14px;height:0;border-top:2px dashed #c9a06a;vertical-align:4px"></i>`
        : `<i style="display:inline-block;width:9px;height:9px;background:rgba(34,34,34,0.07);border:0.5px solid var(--color-border-secondary);border-radius:2px;vertical-align:-1px"></i>`} ${esc(c.exLegendeCible)}</span>
      ${c.exCumul ? '' : `<span><i style="display:inline-block;width:9px;height:9px;background:#C9A227;border-radius:2px;vertical-align:-1px"></i> ${esc(c.exLegendeOr)}</span>`}
      ${c.exCumul ? '' : `<span><i style="display:inline-block;width:9px;height:9px;background:#D9B3B8;border-radius:2px;vertical-align:-1px"></i> mois partiellement encodé</span>`}
      ${c.exBase ? `<span>objectif jour et semaine : ${esc(c.exBase)}</span>` : ''}
    </div>`}`;
}


/* La modale d'un jour de la heatmap de rentabilité : l'addition ligne à
   ligne, comme dans la PWA consultant — CA, coût matière, marge brute, labour
   et overhead répartis par jour d'ouverture, résultat net. */
function tplExploitRentModal(d, x){
  const { esc } = x;
  return `
  <div ${x.A(d.close)} style="position:fixed;inset:0;background:rgba(20,16,14,0.45);z-index:80;animation:fadeIn 140ms ease"></div>
  <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(460px,92vw);max-height:88vh;overflow-y:auto;background:var(--color-surface);border-radius:16px;z-index:81;box-shadow:0 24px 60px rgba(34,34,34,0.3);padding:22px 24px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:4px">
      <div>
        <div style="font-size:16px;font-weight:600;color:var(--color-primary)">${esc(d.titre)}</div>
        <div style="font-size:12px;color:var(--color-text-muted);margin-top:2px">${esc(d.magasin)}${d.sous ? ' · ' + esc(d.sous) : ''}</div>
      </div>
      <button ${x.A(d.close)} aria-label="Fermer" style="border:none;cursor:pointer;background:var(--color-background-secondary);color:var(--color-text);width:28px;height:28px;border-radius:50%;font-size:14px;line-height:1">×</button>
    </div>
    <div style="margin-top:12px">
      ${d.lignes.map(l => `
        <div style="display:flex;align-items:baseline;gap:10px;padding:9px 8px;border-bottom:0.5px solid var(--color-border-tertiary);${l.fort ? 'background:var(--color-background-secondary);border-radius:8px;border-bottom:none;margin:2px 0' : ''}">
          <span style="width:14px;flex:none;color:var(--color-text-muted)">${esc(l.op)}</span>
          <span style="flex:1;${l.fort ? 'font-weight:600' : ''}">${esc(l.lib)}${l.pct ? ` <span style="font-size:11.5px;font-weight:400;color:var(--color-text-muted)">(${esc(l.pct)})</span>` : ''}</span>
          <span style="font-weight:${l.fort ? '600' : '500'};white-space:nowrap;font-variant-numeric:tabular-nums;${l.col ? 'color:' + l.col : ''}">${esc(l.v)}</span>
        </div>`).join('')}
    </div>
    ${d.motif ? `<div style="margin-top:10px;font-size:11.5px;color:var(--color-on-abricot);background:#FBEFE0;border:1px solid #E8C9A0;padding:7px 10px;border-radius:8px">${esc(d.motif)}</div>` : ''}
    <div style="margin-top:10px;font-size:11px;color:var(--color-text-muted)">${esc(d.note)}</div>
  </div>`;
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

/* Catalogue / assortiment / planogramme — une seule liste, trois lectures.
   Les colonnes changent avec l'écran ; les filtres, eux, sont partagés :
   travailler sur un sous-ensemble puis changer d'onglet est le geste normal. */
function tplReferentiel(c, x){
  const { esc } = x;
  const SEL = 'font-family:var(--font-ui);font-size:12.5px;padding:7px 9px;border-radius:8px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);min-width:0';
  const TH = 'text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 10px 7px';
  const TD = 'padding:8px 10px;border-top:0.5px solid var(--color-border-tertiary);font-size:12.5px';
  const num = 'text-align:right;font-variant-numeric:tabular-nums';
  return `
  ${c.refVide ? `<div style="padding:50px 0;color:var(--color-text-muted);font-size:13px">Catalogue indisponible — l’API et la base partagée n’ont rien rendu.</div>` : `
  <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:14px 16px;margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <input id="ref-search" value="${esc(c.refQ)}" ${x.I(c.refSetQ)} placeholder="Rechercher une référence…" style="${SEL};flex:1;min-width:190px">
    <select ${x.C(c.refSetG)} style="${SEL}">${c.refGroupes.map(g => `<option${g === c.refG ? ' selected' : ''}>${esc(g)}</option>`).join('')}</select>
    <select ${x.C(c.refSetC)} style="${SEL}">${c.refCategories.map(g => `<option${g === c.refC ? ' selected' : ''}>${esc(g)}</option>`).join('')}</select>
    <select ${x.C(c.refSetP)} style="${SEL}">${c.refGammes.map(g => `<option${g === c.refP ? ' selected' : ''}>${esc(g)}</option>`).join('')}</select>
    ${c.isCat ? '' : `<button ${x.A(c.refBascule)} style="border:0.5px solid var(--color-border-secondary);background:${c.refToutes ? 'var(--color-primary)' : 'transparent'};color:${c.refToutes ? '#fff' : 'var(--color-text-muted)'};cursor:pointer;font-family:var(--font-ui);font-size:12px;padding:7px 12px;border-radius:8px;white-space:nowrap">Afficher tout le catalogue</button>`}
    <span style="font-size:11.5px;color:var(--color-text-muted);white-space:nowrap">${c.refFiltres} / ${c.refTotal}</span>
  </div>

  ${c.isCat && c.refScoresTxt ? `<div style="font-size:12.5px;color:var(--color-text-muted);margin-bottom:12px">${esc(c.refScoresTxt)}${c.refScorePond ? ' · pondération : ' + esc(c.refScorePond) : ''}</div>` : ''}
  ${(c.refFins || []).length ? `<div style="background:var(--color-surface);border:0.5px solid rgba(141,29,44,0.3);border-left:3px solid var(--color-primary);border-radius:10px;padding:12px 16px;margin-bottom:12px">
    <div style="font-size:12.5px;font-weight:600">Fins de gamme annoncées</div>
    ${c.refFins.map(f2 => `<div style="font-size:12px;margin-top:5px"><span style="font-weight:500">${esc(f2.nom)}</span>
      <span style="color:var(--color-text-muted)">(${esc(f2.ref)})</span> — fin le <span style="font-weight:600;color:var(--color-primary)">${esc(f2.date)}</span>${f2.note ? ` · <span style="color:var(--color-text-muted)">${esc(f2.note)}</span>` : ''}</div>`).join('')}
  </div>` : ''}
  ${c.isAsso ? `<div style="font-size:12.5px;color:var(--color-text-muted);margin-bottom:12px">${c.refMust} référence(s) déclarée(s) obligatoire(s) sur ${c.refTotal}. ${c.refMust === 0 ? 'Aucune pour l’instant : affichez le catalogue et cochez celles que toute boutique doit tenir.' : ''}</div>` : ''}
  ${c.isPlano ? tplPlanoComptoir(c, x) : ''}
  ${c.isPlano ? `<div style="font-size:12.5px;color:var(--color-text-muted);margin-bottom:12px">${c.refPlaces} référence(s) placée(s) au comptoir sur ${c.refTotal}. ${c.refPlaces === 0 ? 'Aucune encore : affichez le catalogue, ouvrez une référence et choisissez son emplacement.' : ''}</div>` : ''}

  <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;min-width:640px">
      <thead><tr>
        <th style="${TH}">Référence</th>
        <th style="${TH}">Catégorie</th>
        ${c.isCat ? `<th style="${TH};${num}" title="Même calcul que l’écran Scoring produits — volume, marge nette, perte, présence au comptoir">Score</th><th style="${TH};${num}">Prix</th><th style="${TH};${num}">Coût</th><th style="${TH};${num}">Marge brute</th><th style="${TH};${num}" title="Commission de marque, au taux des réglages de la centrale d’achat">Commission</th><th style="${TH};${num}" title="Marge après commission de marque — celle que pilote la centrale d’achat">Marge nette</th><th style="${TH};${num}">DLV</th>` : ''}
        ${c.isAsso ? `<th style="${TH};text-align:center">Obligatoire</th><th style="${TH};${num}" title="Quantité minimale à tenir. Le bouton « batch » reprend la fournée minimale de la fiche produit.">Qté min. · batch</th><th style="${TH}" title="Où la référence est présentée au comptoir. « Attribuer » ouvre le planogramme sur le plan actuel.">Emplacement au comptoir</th>` : ''}
        ${c.isPlano ? `<th style="${TH}">Zone</th><th style="${TH}">Meuble</th><th style="${TH}">Niveau</th><th style="${TH};${num}">Emplac.</th>` : ''}
        <th style="${TH};text-align:right">Fiche</th>
      </tr></thead>
      <tbody>
        ${c.refLignes.map(l => `<tr>
          <td style="${TD}">
            <button ${x.A(l.ouvrir)} style="border:none;background:transparent;padding:0;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:500;color:var(--color-text);text-align:left" class="hv-line">${esc(l.nom)}</button>
            ${l.finLe ? `<span style="display:inline-block;margin-left:7px;font-size:10px;font-weight:600;padding:1px 8px;border-radius:999px;background:rgba(141,29,44,0.09);color:var(--color-primary)">fin le ${esc(l.finLe)}</span>` : ''}
            <div style="font-size:10.5px;color:var(--color-text-muted)">${esc(l.ref)}${l.gamme !== '—' ? ' · ' + esc(l.gamme) : ''}</div>
          </td>
          <td style="${TD};color:var(--color-text-muted)">${esc(l.categorie)}<div style="font-size:10.5px">${esc(l.groupe)}</div></td>
          ${c.isCat ? `<td style="${TD};${num}"><span style="${l.scoreSt}">${esc(l.scoreTxt)}</span>${l.scoreVerdict ? `<div style="font-size:10.5px;font-weight:400;color:var(--color-text-muted);margin-top:2px">${esc(l.scoreVerdict)}</div>` : ''}</td>
            <td style="${TD};${num}">${esc(l.prix)}</td>
            <td style="${TD};${num};color:var(--color-text-muted)">${esc(l.cout)}</td>
            <td style="${TD};${num};color:${l.margeC}">${esc(l.marge)}</td>
            <td style="${TD};${num};color:var(--color-text-muted)">${esc(l.commission)}</td>
            <td style="${TD};${num};color:${l.margeNetteC}">${esc(l.margeNette)}<div style="font-size:10.5px;font-weight:400;color:var(--color-text-muted)">${esc(l.margeNetteEur)}</div></td>
            <td style="${TD};${num};color:var(--color-text-muted)">${esc(l.dlv)}</td>` : ''}
          ${c.isAsso ? `<td style="${TD};text-align:center">
            <label title="${l.must ? 'Retirer de l’assortiment obligatoire' : 'Imposer cette référence à tout le réseau'}" style="display:inline-flex;align-items:center;justify-content:center;cursor:pointer">
              <input type="checkbox" ${l.must ? 'checked' : ''} ${x.C(l.mustSet)}
                style="width:17px;height:17px;cursor:pointer;accent-color:var(--color-primary);margin:0">
            </label></td>
            <td style="${TD};${num}">
              <div style="display:inline-flex;align-items:center;gap:6px;justify-content:flex-end">
                <input type="number" min="0" step="1" value="${l.qmin || ''}" ${x.C(l.qminSet)}
                  title="Quantité minimale à tenir en boutique"
                  style="width:62px;text-align:right;font-family:var(--font-ui);font-size:12.5px;padding:5px 7px;border-radius:7px;border:0.5px solid ${l.qminSousBatch ? 'var(--color-primary)' : 'var(--color-border-secondary)'};background:var(--color-surface);color:var(--color-text);font-variant-numeric:tabular-nums">
                ${l.qminBatch ? `<button ${x.A(l.qminBatch)} title="Reprendre le batch de la fiche produit : ${esc(l.batchTxt)}"
                  style="border:0.5px solid var(--color-border-secondary);background:var(--color-background-secondary);color:var(--color-text-muted);border-radius:999px;padding:3px 9px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer;white-space:nowrap">batch ${esc(l.batchTxt)}</button>` : ''}
              </div>
              ${l.qminSousBatch ? `<div style="font-size:10px;color:var(--color-primary);margin-top:3px">sous le batch (${esc(l.batchTxt)})</div>` : ''}
            </td>
            <!-- Une obligation sans place au comptoir ne peut pas être tenue :
                 la colonne le dit, et le bouton mène au plan pour y remédier
                 sans changer d'écran. -->
            <td style="${TD}">
              <div style="display:flex;align-items:center;gap:9px;justify-content:space-between">
                <span style="font-size:12px;${l.place ? '' : 'color:var(--color-text-muted)'}">${l.place ? esc(l.emplacement) : (l.must ? 'pas de place au comptoir' : '—')}</span>
                <button ${x.A(l.planoGo)} title="Ouvrir le planogramme actuel pour cette référence"
                  style="flex:0 0 auto;border:0.5px solid ${l.place ? 'var(--color-border-secondary)' : 'var(--color-primary)'};background:transparent;color:${l.place ? 'var(--color-text-muted)' : 'var(--color-primary)'};border-radius:999px;padding:3px 11px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer;white-space:nowrap">${esc(l.planoBtn)}</button>
              </div>
            </td>` : ''}
          ${c.isPlano ? `<td style="${TD}">${esc(l.zone) || '<span style="color:var(--color-text-muted)">—</span>'}</td>
            <td style="${TD};color:var(--color-text-muted)">${esc(l.meuble) || '—'}</td>
            <td style="${TD};color:var(--color-text-muted)">${esc(l.niveau) || '—'}</td>
            <td style="${TD};${num}">${esc(l.slot) || '—'}</td>` : ''}
          <td style="${TD};text-align:right">${l.parametre ? '<span style="font-size:11px;color:#2d7a3e">remplie</span>' : '<span style="font-size:11px;color:var(--color-text-muted)">vide</span>'}</td>
        </tr>`).join('')}
      </tbody>
    </table>
  </div>
  ${c.isCat ? `<div style="margin-top:11px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;font-size:10.5px;color:var(--color-text-muted)">
    <span style="font-weight:500">Marge</span>
    ${c.refEchelle.map(e => `<span><i style="display:inline-block;width:9px;height:9px;border-radius:2px;background:${e.c};vertical-align:-1px"></i> ${esc(e.l)}</span>`).join('')}
    <span><i style="display:inline-block;width:9px;height:9px;border-radius:2px;background:#C9C2B8;vertical-align:-1px"></i> inconnue (${c.refSansMarge})</span>
  </div>` : ''}
  ${c.refTronque ? `<div style="font-size:11.5px;color:var(--color-text-muted);margin-top:10px">${c.refTronque} référence(s) au-delà des 400 affichées — affinez les filtres.</div>` : ''}
  ${c.refEdit ? tplRefEdit(c, x) : ''}`}`;
}

/* Édition d'une référence. Deux formulaires selon l'écran d'où l'on vient :
   poser une référence au comptoir et chiffrer sa production sont deux gestes
   distincts, les mélanger allongerait la saisie sans servir personne. */
function tplRefEdit(c, x){
  const { esc } = x;
  const e = c.refEdit;
  const L = 'font-size:11px;color:var(--color-text-muted);display:block;margin-bottom:3px';
  const IN = 'font-family:var(--font-ui);font-size:13px;padding:7px 9px;border-radius:8px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);width:100%';
  const champ = (k, lbl, ph) => `<div><label style="${L}">${esc(lbl)}</label>
    <input id="ref-ch-${k}" value="${esc(String(e.champs[k] == null ? '' : e.champs[k]))}" ${x.I(e.set(k))} placeholder="${esc(ph || '')}" style="${IN}"></div>`;
  return `
  <div style="position:fixed;inset:0;background:rgba(20,16,14,.42);display:flex;align-items:center;justify-content:center;padding:24px;z-index:60">
    <div style="background:var(--color-surface);border-radius:14px;padding:22px;width:100%;max-width:${e.mode === 'plano' ? '460px' : '620px'};max-height:88vh;overflow-y:auto">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:16px">
        <div>
          <div style="font-family:var(--font-display);font-size:19px;line-height:1.2">${esc(e.nom)}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted)">${esc(e.ref)} · ${e.mode === 'plano' ? 'emplacement au comptoir' : 'fiche de production'}</div>
        </div>
        <button ${x.A(e.close)} style="border:none;background:transparent;cursor:pointer;color:var(--color-text-muted);font-size:18px;line-height:1;padding:2px 6px">×</button>
      </div>
      ${e.mode === 'plano' ? `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          ${champ('zone', 'Zone', 'Vitrine chaude')}
          ${champ('meuble', 'Meuble', 'M1')}
          ${champ('niveau', 'Niveau', 'Haut')}
          ${champ('slot', 'Emplacement', '3')}
        </div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:10px">Vider la zone retire la référence du planogramme.</div>
      ` : `
        <div style="display:flex;align-items:center;gap:9px;padding:11px 13px;background:var(--color-background-secondary);border-radius:9px;margin-bottom:14px">
          <input type="checkbox" ${e.champs.must ? 'checked' : ''} ${x.C(e.set('must'))} style="width:16px;height:16px;accent-color:var(--color-primary)">
          <div style="flex:1">
            <div style="font-size:13px;font-weight:500">Référence obligatoire</div>
            <div style="font-size:11px;color:var(--color-text-muted)">toute boutique doit la proposer en permanence</div>
          </div>
          <div style="width:110px">${champ('qmin', 'Quantité min.', '0')}</div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px">
          ${champ('prix', 'Prix de vente (€)', '')}
          ${champ('mat', 'Coût matière (€)', '')}
          ${champ('dlv', 'DLV (heures)', '')}
          ${champ('prep', 'Préparation (min)', '')}
          ${champ('cuisson', 'Cuisson (min)', '')}
          ${champ('fin', 'Finition (min)', '')}
          ${champ('bmin', 'Batch minimum', '')}
          ${champ('bmult', 'Multiple de batch', '')}
          ${champ('four', 'Four', '')}
        </div>
        <div style="margin-top:12px">${champ('profil', 'Profil de vente', 'matin, week-end…')}</div>
      `}
      ${e.err ? `<div style="margin-top:12px;font-size:12px;color:var(--color-primary);background:#F7E4E6;border-radius:8px;padding:8px 11px">${esc(e.err)}</div>` : ''}
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px">
        <button ${x.A(e.close)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text);cursor:pointer;font-family:var(--font-ui);font-size:13px;padding:8px 16px;border-radius:9px">Annuler</button>
        <button ${x.A(e.save)} style="border:none;background:var(--color-primary);color:#fff;cursor:pointer;font-family:var(--font-ui);font-size:13px;font-weight:500;padding:8px 18px;border-radius:9px;opacity:${e.busy ? '.6' : '1'}">${e.busy ? 'Enregistrement…' : 'Enregistrer'}</button>
      </div>
    </div>
  </div>`;
}

/* Suivi de production. Le taux de perte se calcule sur les VENTES : deux
   boutiques sur quatre ne déclarent pas leurs fournées, et diviser par ce
   champ leur attribuait 100 % de perte pour une case non remplie. */
function tplProduction(c, x){
  const { esc } = x;
  if (c.prChargement) return `<div style="padding:50px 0;color:var(--color-text-muted);font-size:13px">Lecture des mouvements de caisse…</div>`;
  const CARD = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:16px';
  const TH = 'text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 10px 7px';
  const TD = 'padding:8px 10px;border-top:0.5px solid var(--color-border-tertiary);font-size:12.5px';
  const num = 'text-align:right;font-variant-numeric:tabular-nums';
  return `
  ${c.prAvert ? `<div style="font-size:11.5px;color:var(--color-on-abricot);background:#FBEFE0;border:1px solid #E8C9A0;padding:7px 11px;border-radius:8px;margin-bottom:14px;display:inline-block">${esc(c.prAvert)}</div>` : ''}
  <div style="${CARD};margin-bottom:14px;display:flex;gap:34px;align-items:center;flex-wrap:wrap">
    <div><div style="font-size:10px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Réseau</div>
      <div style="font-size:12.5px;color:var(--color-text-muted)">${esc(c.prPeriode)}</div></div>
    ${c.prReseau.map(k => `<div><div style="font-family:var(--font-display);font-size:23px;line-height:1">${esc(k.v)}</div>
      <div style="font-size:11.5px;color:var(--color-text-muted)">${esc(k.l)}</div></div>`).join('')}
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:14px">
    <div style="${CARD}">
      <div style="font-size:13px;font-weight:500;margin-bottom:9px">Par boutique</div>
      <table style="width:100%;border-collapse:collapse">
        <thead><tr><th style="${TH}">Boutique</th><th style="${TH};${num}">Vendu</th><th style="${TH};${num}">Jeté</th><th style="${TH};${num}">Taux</th></tr></thead>
        <tbody>${c.prMagasins.map(m => `<tr>
          <td style="${TD}">${esc(m.magasin)}${m.note ? `<div style="font-size:10.5px;color:var(--color-on-abricot)">${esc(m.note)}</div>` : ''}</td>
          <td style="${TD};${num};color:var(--color-text-muted)">${esc(m.vendu)}</td>
          <td style="${TD};${num}">${esc(m.jete)}</td>
          <td style="${TD};${num};color:${m.col};font-weight:500">${esc(m.taux)}</td>
        </tr>`).join('')}</tbody>
      </table>
    </div>
    <div style="${CARD}">
      <div style="font-size:13px;font-weight:500;margin-bottom:9px">Motifs de rebut</div>
      <table style="width:100%;border-collapse:collapse">
        <thead><tr><th style="${TH}">Motif</th><th style="${TH};${num}">Quantité</th><th style="${TH};${num}">Lignes</th></tr></thead>
        <tbody>${c.prMotifs.map(m => `<tr>
          <td style="${TD}">${esc(m.motif)}</td>
          <td style="${TD};${num}">${esc(m.quantite)}</td>
          <td style="${TD};${num};color:var(--color-text-muted)">${esc(m.lignes)}</td>
        </tr>`).join('')}</tbody>
      </table>
    </div>
  </div>
  <div style="${CARD};margin-top:14px">
    <div style="font-size:13px;font-weight:500;margin-bottom:3px">Références les plus jetées</div>
    <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:10px">quantité jetée sur la période, et taux rapporté aux ventes</div>
    <div style="display:flex;flex-direction:column;gap:7px">
      ${c.prProduits.map(p => `<div>
        <div style="display:flex;justify-content:space-between;gap:10px;font-size:12px">
          <span>${esc(p.nom)}</span>
          <span style="white-space:nowrap"><b style="font-variant-numeric:tabular-nums">${esc(p.jete)}</b>
            <span style="color:var(--color-text-muted)"> jetés · ${esc(p.vendu)} vendus · </span>
            <span style="color:${p.col};font-weight:500">${esc(p.taux)}</span></span>
        </div>
        <div style="height:5px;border-radius:3px;background:#EDEAE5;overflow:hidden"><i style="display:block;height:100%;border-radius:3px;width:${p.w};background:var(--color-primary)"></i></div>
      </div>`).join('')}
    </div>
  </div>`;
}

/* Analyse dans le temps. La série coûte un appel par point : rien ne part
   avant une sélection explicite, et le nombre de points est annoncé. */
function tplAnalyse(c, x){
  const { esc } = x;
  const SEL = 'font-family:var(--font-ui);font-size:13px;padding:8px 10px;border-radius:9px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);min-width:240px;flex:1';
  const CARD = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:16px';
  const g = c.anGraphe;
  return `
  <svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
    <pattern id="anhach" width="5" height="5" patternTransform="rotate(45)" patternUnits="userSpaceOnUse">
      <rect width="5" height="5" fill="#D9B3B8"/><line x1="0" y1="0" x2="0" y2="5" stroke="var(--color-primary)" stroke-width="2"/>
    </pattern>
  </defs></svg>

  <div style="${CARD};margin-bottom:14px;display:flex;flex-direction:column;gap:12px">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <div style="display:flex;gap:3px;background:var(--color-background-secondary);padding:3px;border-radius:10px">
        ${c.anTypeBtns.map(b => `<button ${x.A(b.go)} style="${b.st}">${esc(b.label)}</button>`).join('')}
      </div>
      <div style="display:flex;gap:3px;background:var(--color-background-secondary);padding:3px;border-radius:10px">
        ${c.anGranBtns.map(b => `<button ${x.A(b.go)} style="${b.st}">${esc(b.label)}</button>`).join('')}
      </div>
      ${c.anVueDispo ? `<div style="display:flex;gap:3px;background:var(--color-background-secondary);padding:3px;border-radius:10px">
        ${c.anVueBtns.map(b => `<button ${x.A(b.go)} style="${b.st}">${esc(b.label)}</button>`).join('')}
      </div>` : ''}
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <input id="an-search" type="search" value="${esc(c.anFiltre)}" ${x.I(c.anFiltrer)}
        placeholder="${c.anType === 'produit' ? 'Rechercher une référence…' : 'Rechercher…'}"
        style="${SEL};min-width:200px;flex:0 1 240px">
      <select ${x.C(c.anChoisir)} style="${SEL}"${c.anOptChargement ? ' disabled' : ''}>
        <option value="">${c.anOptChargement ? 'Lecture des libellés de l’API…'
          : c.anType === 'produit' ? 'Choisir une référence…' : 'Choisir une catégorie…'}</option>
        ${c.anListe
          .map(p => `<option value="${esc(p.id)}"${p.id === c.anCle ? ' selected' : ''}>${esc(p.nom)}</option>`).join('')}
      </select>
      ${c.anPlafond ? `<span style="font-size:11.5px;color:var(--color-text-muted)">${c.anPlafond} points — reconstitués mois par mois depuis l’API</span>` : ''}
    </div>
    ${c.anOptErreur
      ? `<div style="font-size:11.5px;color:var(--color-on-abricot);background:#FBEFE0;border:1px solid #E8C9A0;padding:6px 10px;border-radius:8px">${esc(c.anOptErreur)}</div>`
      : `<div style="font-size:11.5px;color:var(--color-text-muted)">${
          c.anType === 'produit'
            ? `${c.anProduits.length} référence${c.anProduits.length > 1 ? 's' : ''}, les plus vendues d’abord`
            : c.anType === 'souscategorie'
            ? `${c.anSousCategories.length} catégorie${c.anSousCategories.length > 1 ? 's' : ''} — en volume, le CA n’étant ventilé que par groupe`
            : `${c.anCategories.length} groupe${c.anCategories.length > 1 ? 's' : ''} — la ventilation du chiffre d’affaires de l’API`
        }${c.anOptPeriode ? ` · relevé sur ${esc(c.anOptPeriode)}` : ''}${
          c.anFiltre ? ` · <strong style="font-weight:500">${c.anListe.length} sur ${c.anListeTotal}</strong> pour « ${esc(c.anFiltre)} »` : ''
        }</div>`}
  </div>

  ${c.anVide ? `<div style="padding:44px 0;color:var(--color-text-muted);font-size:13px">Choisissez ${c.anType === 'produit' ? 'une référence' : 'une catégorie'} pour construire la série.</div>`
   : c.anChargement ? `<div style="padding:44px 0;color:var(--color-text-muted);font-size:13px">Construction de la série — interrogation de l’API mois par mois…</div>`
   : !g ? `<div style="padding:34px 0;color:var(--color-text-muted);font-size:13px">${esc(c.anMotif || 'aucune donnée')}</div>`
   : `
  <div style="${CARD}">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:6px">
      <div>
        <div style="font-size:13px;font-weight:500">${esc(c.anLibelle || c.anCle)}</div>
        <div style="font-size:11.5px;color:var(--color-text-muted)">${
          c.anVue === 'magasin' && c.anParMagasinMesure
            ? esc(c.anParMagasinMesure) + ' par magasin'
            : esc(c.anMesure)} · ${esc(c.anSource)}</div>
      </div>
      ${g.evolution ? `<div style="text-align:right">
        <div style="font-family:var(--font-display);font-size:21px;line-height:1;color:${g.evolution.col}">${esc(g.evolution.txt)}</div>
        <div style="font-size:11px;color:var(--color-text-muted)">du premier au dernier point clos</div></div>` : ''}
    </div>
    ${c.anLignes ? (c.anLignes.vide
      ? `<div style="padding:30px 0;color:var(--color-text-muted);font-size:12.5px">${esc(c.anLignes.vide)}</div>`
      : `<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
        <div style="display:flex;gap:3px;background:var(--color-background-secondary);padding:3px;border-radius:10px">
          ${c.anBaseBtns.map(o => `<button ${x.A(o.go)} style="${o.st}">${esc(o.label)}</button>`).join('')}
        </div>
        <span style="font-size:11.5px;color:var(--color-text-muted)">${c.anBase100
          ? 'Chaque courbe repart de 100 : seules les formes se comparent. Qui suit le réseau colle à la ligne pointillée, quelle que soit sa taille.'
          : 'Valeurs absolues — la taille des boutiques domine la lecture.'}${
          c.anLignes.exclu ? ' · période en cours exclue, un mois entamé n’est pas comparable' : ''}</span>
      </div>
      <svg viewBox="0 0 ${c.anLignes.W} ${c.anLignes.H}" style="width:100%;height:auto;display:block">
      ${c.anLignes.ticks.map(t => `<line x1="40" x2="${c.anLignes.PD}" y1="${t.y}" y2="${t.y}" stroke="${t.ref ? 'rgba(34,34,34,0.32)' : 'rgba(34,34,34,0.08)'}" stroke-width="${t.ref ? 1.2 : 0.8}"/>
        <text x="34" y="${t.y + 4}" text-anchor="end" font-size="10.5" fill="var(--color-text-muted)" ${t.ref ? 'font-weight="500"' : ''}>${esc(t.t)}</text>`).join('')}
      ${c.anLignes.reseau ? `<path d="${c.anLignes.reseau.d}" fill="none" stroke="var(--color-text)" stroke-width="2.5" stroke-dasharray="7 5" stroke-linejoin="round" opacity="0.6"/>
        ${c.anLignes.reseau.pts.map(q => `<circle cx="${q.x}" cy="${q.y}" r="4" fill="var(--color-surface)" stroke="var(--color-text)" stroke-width="1.8" opacity="0.75"><title>${esc(q.t)}</title></circle>`).join('')}
        <line x1="${c.anLignes.reseau.fin.xd + 5}" y1="${c.anLignes.reseau.fin.y}" x2="${c.anLignes.PD + 34}" y2="${c.anLignes.reseau.fin.ly}" stroke="var(--color-text)" stroke-width="0.9" opacity="0.35"/>
        <text x="${c.anLignes.PD + 40}" y="${c.anLignes.reseau.fin.ly + 4}" font-size="11.5" fill="var(--color-text-muted)" font-style="italic">réseau</text>` : ''}
      ${c.anLignes.series.map(s => `<path d="${s.d}" fill="none" stroke="${s.col}" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
        ${s.pts.map(q => `<circle cx="${q.x}" cy="${q.y}" r="4.5" fill="${s.col}" stroke="var(--color-surface)" stroke-width="2"><title>${esc(q.t)}</title></circle>`).join('')}
        ${s.fin ? `<line x1="${s.fin.xd + 5}" y1="${s.fin.y}" x2="${c.anLignes.PD + 34}" y2="${s.fin.ly}" stroke="${s.col}" stroke-width="0.9" opacity="0.45"/>
          <circle cx="${c.anLignes.PD + 38}" cy="${s.fin.ly}" r="3.5" fill="${s.col}"/>
          <text x="${c.anLignes.PD + 45}" y="${s.fin.ly + 4}" font-size="11.5" fill="var(--color-text)">${esc(s.court)}</text>` : ''}`).join('')}
      ${c.anLignes.labels.map(l => `<text x="${l.x}" y="${l.y}" text-anchor="middle" font-size="11.5" fill="var(--color-text-muted)">${esc(l.t)}</text>`).join('')}
    </svg>
    <div style="display:flex;flex-wrap:wrap;gap:4px 16px;margin-top:8px">
      ${c.anLignes.series.map(s => `<span style="display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--color-text-muted)">
        <span style="width:10px;height:10px;border-radius:3px;background:${s.col};flex:none"></span>${esc(s.nom)}</span>`).join('')}
      <span style="display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--color-text-muted)">
        <span style="width:16px;height:0;border-top:2px dashed var(--color-text);opacity:0.6;flex:none"></span>Moyenne réseau</span>
    </div>
    <div style="overflow-x:auto;margin-top:16px">
    <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:620px">
      <thead><tr>
        <th style="text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 0 6px">Magasin</th>
        ${c.anLignes.entetes.map(h => `<th style="text-align:right;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;padding:0 8px 6px;color:var(--color-text-muted)">${esc(h.t)}</th>`).join('')}
        <th style="text-align:right;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 14px 6px">Évolution</th>
        <th style="text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 0 6px">Suit le réseau ?</th>
      </tr></thead>
      <tbody>
        ${c.anLignes.series.map(s => `<tr>
          <td style="padding:6px 0;border-top:0.5px solid var(--color-border-tertiary);white-space:nowrap">
            <span style="display:inline-block;width:9px;height:9px;border-radius:3px;background:${s.col};margin-right:7px"></span>${esc(s.court)}</td>
          ${s.cells.map(q => `<td style="padding:6px 8px;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap">${esc(q.v)}</td>`).join('')}
          <td style="padding:6px 14px;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap">${esc(s.evo)}</td>
          <td style="padding:6px 0;border-top:0.5px solid var(--color-border-tertiary);white-space:nowrap;color:${s.vCol};font-weight:500">${esc(s.verdict)}
            <span style="font-weight:400;color:var(--color-text-muted)"> ${esc(s.phaseTxt)}</span></td>
        </tr>`).join('')}
        ${c.anLignes.reseau ? `<tr>
          <td style="padding:6px 0;border-top:0.5px solid var(--color-border-secondary);white-space:nowrap;font-style:italic;color:var(--color-text-muted)">
            <span style="display:inline-block;width:14px;border-top:2px dashed var(--color-text);opacity:0.55;margin-right:5px;vertical-align:middle"></span>Moyenne réseau</td>
          ${c.anLignes.reseau.cells.map(q => `<td style="padding:6px 8px;border-top:0.5px solid var(--color-border-secondary);text-align:right;font-variant-numeric:tabular-nums;color:var(--color-text-muted);white-space:nowrap">${esc(q.v)}</td>`).join('')}
          <td style="padding:6px 14px;border-top:0.5px solid var(--color-border-secondary);text-align:right;font-variant-numeric:tabular-nums;color:var(--color-text-muted);font-style:italic">${esc(c.anLignes.reseau.evo)}</td>
          <td style="padding:6px 0;border-top:0.5px solid var(--color-border-secondary);font-size:11px;color:var(--color-text-muted);font-style:italic">référence · ${c.anLignes.nClos} période${c.anLignes.nClos > 1 ? 's' : ''} close${c.anLignes.nClos > 1 ? 's' : ''}</td>
        </tr>` : ''}
      </tbody>
    </table></div>
    <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:8px">Écart à la courbe réseau, en points d’indice — la distance que l’œil voit entre la courbe et la pointillée. Le verdict retient le PLUS GRAND écart, pas la moyenne : moyenner dilue un décrochage d’un mois sur toute la période, et c’est justement ce décrochage sur lequel on peut agir. Jusqu’à 8 points : suit le réseau. De 8 à 20 : écart ponctuel, le mois est nommé. Au-delà : trajectoire propre. La colonne « évolution » ne compare, elle, que le premier et le dernier point.</div>`)
    : `<svg viewBox="0 0 ${g.W} ${g.H}" style="width:100%;height:auto;display:block">
      ${g.grille.map(l => `<line x1="0" x2="${l.w}" y1="${l.y}" y2="${l.y}" stroke="rgba(34,34,34,0.09)" stroke-width="0.8"/>`).join('')}
      ${g.barres.map(b => `<rect x="${b.x}" y="${b.y}" width="${b.w}" height="${b.h}" rx="2" fill="${b.fill}"><title>${esc(b.t || '')}</title></rect>`).join('')}
      ${g.valeurs.map(v => `<text x="${v.x}" y="${v.y}" text-anchor="middle" font-size="10" fill="var(--color-text-muted)">${esc(v.t)}</text>`).join('')}
      ${g.labels.map(l => `<text x="${l.x}" y="${l.y}" text-anchor="middle" font-size="10.5" fill="${l.c}">${esc(l.t)}</text>`).join('')}
    </svg>
    ${g.n1 ? `<div style="display:flex;gap:14px;margin-top:8px">
      <span style="display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--color-text-muted)">
        <span style="width:10px;height:10px;border-radius:3px;background:var(--color-primary);flex:none"></span>Exercice en cours</span>
      <span style="display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--color-text-muted)">
        <span style="width:10px;height:10px;border-radius:3px;background:var(--color-secondary);flex:none"></span>N-1, même étendue</span>
    </div>` : ''}`}
    ${c.anParMagasinMotif && c.anVue === 'magasin' ? `<div style="font-size:11.5px;color:var(--color-on-abricot);background:#FBEFE0;border:1px solid #E8C9A0;padding:6px 10px;border-radius:8px;margin-top:10px">${esc(c.anParMagasinMotif)}</div>` : ''}
    ${c.anMotif ? `<div style="font-size:11.5px;color:var(--color-on-abricot);background:#FBEFE0;border:1px solid #E8C9A0;padding:6px 10px;border-radius:8px;margin-top:8px">${esc(c.anMotif)}</div>` : ''}
    <div style="font-size:11.5px;font-weight:500;color:var(--color-text);margin-top:22px;padding-top:14px;border-top:0.5px solid var(--color-border-tertiary)">Total réseau, comparé à l’an dernier</div>
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;margin-top:8px">
      <thead><tr>
        <th style="text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 0 6px">Période</th>
        <th style="text-align:right;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 10px 6px">${c.anType === 'categorie' ? 'CA' : 'Vendu'}</th>
        <th style="text-align:right;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 10px 6px">N-1</th>
        <th style="text-align:right;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 10px 6px">Écart</th>
        <th style="text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 0 6px">Lecture</th>
      </tr></thead>
      <tbody>${g.lignes.map(l => `<tr ${x.A(l.ouvrir)} title="Voir le détail par magasin sur cette période" style="cursor:pointer">
        <td style="padding:6px 0;border-top:0.5px solid var(--color-border-tertiary)"><span style="text-decoration:underline;text-decoration-color:var(--color-border-secondary);text-underline-offset:3px">${esc(l.libelle)}</span>${l.enCours ? ' <span style="font-size:10.5px;color:var(--color-primary)">en cours</span>' : ''}</td>
        <td style="padding:6px 10px;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums">${esc(l.valeur)}</td>
        <td style="padding:6px 10px;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;color:var(--color-text-muted)">${esc(l.n1)}</td>
        <td style="padding:6px 10px;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;color:${l.deltaCol}">${esc(l.delta)}</td>
        <td style="padding:6px 0;border-top:0.5px solid var(--color-border-tertiary);font-size:11.5px;color:var(--color-text-muted)">${esc(l.motif)}</td>
      </tr>`).join('')}</tbody>
    </table>
  </div>`}`;
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
    ${c.mgAn.chargement ? `<div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:18px;font-size:12.5px;color:var(--color-text-muted)">Lecture de l’API du panel (année en cours, mois par mois)…</div>`
      : (c.mgAn.indispo ? `<div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:18px;font-size:12.5px;color:var(--color-text-muted)">${esc(c.mgAn.indispo)}</div>` : `
    ${c.mgAn.tables.map(t => `
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
      <div style="padding:14px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
        <span style="font-size:13px;font-weight:500">${esc(t.titre)} — ${esc(c.mgAn.annee)}, mois par mois</span>
        <span style="font-size:11.5px;color:var(--color-text-muted);margin-left:8px">${esc(t.sous)}</span>
      </div>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:${190 + c.mgAn.moisLabels.length * 74}px">
          <thead><tr>
            <th style="${TH}">Magasin</th>
            ${c.mgAn.moisLabels.map(mL => `<th style="text-align:right;${TH2}">${esc(mL)}</th>`).join('')}
          </tr></thead>
          <tbody>
            ${t.rows.map(r => `
              <tr style="border-bottom:0.5px solid var(--color-border-tertiary)${r.reseau ? ';border-top:1.5px solid var(--color-border-secondary)' : ''}">
                <td style="padding:9px 14px;font-weight:${r.reseau ? '600' : '500'};white-space:nowrap">${esc(r.nom)}</td>
                ${r.cells.map(v => `<td style="padding:9px 12px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;${r.reseau ? 'font-weight:600;' : ''}${v.st}">${esc(v.t)}</td>`).join('')}
              </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>`).join('')}
    <div style="font-size:11px;color:var(--color-text-muted)">${esc(c.mgAn.source)}</div>`)}
  </div>`;
}

/* --- Heatmap mensuelle ------------------------------------------------------ */
function tplHeatmap(c, x){
  const { esc } = x;
  const cell = cc => `<div ${x.EN(cc.enter)} ${x.A(cc.clic)} style="${cc.st};cursor:pointer">${cc.txt}</div>`;
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
      <div style="margin-top:14px;min-height:20px;font-size:12.5px;color:var(--color-text-muted)">${esc(c.hmDetail)} <span style="opacity:.7">— cliquez une case pour le détail.</span></div>
    </div>
    ${c.hmDet ? tplHeatDetail(c.hmDet, x) : ''}
  </div>`;
}

/* Le détail d'une case de la heatmap : les trois séries côte à côte — budget
   validé, CA théorique de l'étude, réel encaissé — puis l'écart et l'atteinte
   contre celle des deux qui fait référence ce mois-là. */
function tplHeatDetail(d, x){
  const { esc } = x;
  return `
  <div ${x.A(d.close)} style="position:fixed;inset:0;background:rgba(20,16,14,0.45);z-index:80"></div>
  <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(420px,92vw);max-height:88vh;overflow-y:auto;background:var(--color-surface);border-radius:16px;z-index:81;box-shadow:0 24px 60px rgba(34,34,34,0.3);padding:22px 24px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
      <div>
        <div style="font-size:16px;font-weight:600;color:var(--color-primary)">${esc(d.titre)}</div>
        <div style="font-size:12px;color:var(--color-text-muted);margin-top:2px">${esc(d.magasin)}</div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">${esc(d.sous)}</div>
      </div>
      <button ${x.A(d.close)} aria-label="Fermer" style="border:none;cursor:pointer;background:var(--color-background-secondary);color:var(--color-text);width:28px;height:28px;border-radius:50%;font-size:14px;line-height:1">×</button>
    </div>
    <div style="margin-top:16px;display:flex;flex-direction:column;gap:9px">
      ${d.lignes.map(l => `
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;border-bottom:0.5px solid var(--color-border-tertiary);padding-bottom:7px">
          <span style="font-size:12.5px">${esc(l.l)}${l.aide ? `<div style="font-size:10.5px;color:var(--color-text-muted)">${esc(l.aide)}</div>` : ''}</span>
          <span style="font-size:14px;font-weight:500;white-space:nowrap">${esc(l.v)}</span>
        </div>`).join('')}
    </div>
    <div style="margin-top:14px;display:flex;gap:10px">
      <div style="flex:1;background:var(--color-background-secondary);border-radius:10px;padding:10px 12px">
        <div style="font-size:10px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted)">Écart à l'objectif</div>
        <div style="${d.ecartSt};font-size:16px;margin-top:2px">${esc(d.ecart)}</div>
        <div style="font-size:10.5px;color:var(--color-text-muted)">objectif retenu ${esc(d.cibleTxt)}</div>
      </div>
      <div style="flex:1;background:var(--color-background-secondary);border-radius:10px;padding:10px 12px">
        <div style="font-size:10px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted)">Atteinte</div>
        <div style="${d.attSt};font-size:16px;margin-top:2px">${esc(d.att)}</div>
        <div style="font-size:10.5px;color:var(--color-text-muted)">couleur de la case</div>
      </div>
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
          ${c.objCibleSrc ? `<div style="font-size:11px;color:var(--color-text-muted);margin-top:2px">${esc(c.objCibleSrc)}</div>` : ''}
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
                <td style="padding:10px 12px;text-align:right">${r.cible}${r.source ? `<div style="font-size:9.5px;color:var(--color-text-muted)">${esc(r.source)}</div>` : ''}</td>
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
          <!-- Replié par défaut : le total et la marge sont ce qu'on vient lire ;
               le détail des groupes s'ouvre quand on cherche d'où vient un
               écart. -->
          <tr><td colspan="14" style="padding:18px 0 8px">
            <button ${x.A(c.bChToggle)} style="display:inline-flex;align-items:center;gap:8px;border:none;background:none;padding:0;cursor:pointer;font-family:var(--font-ui);font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.07em;color:var(--color-text-muted)">
              <span style="display:inline-block;width:11px;transition:transform 120ms ease;transform:rotate(${c.bChOuvert ? '90' : '0'}deg)">▸</span>
              Groupes de frais encodés — montant, % du CA réel dessous
              <span style="text-transform:none;letter-spacing:0;font-weight:400">(${c.bChRows.length} poste${c.bChRows.length > 1 ? 's' : ''}${c.bChOuvert ? '' : ' — replié'})</span>
            </button>
            <div style="font-size:11px;color:var(--color-text-muted);text-transform:none;letter-spacing:0;font-weight:400;margin-top:4px;line-height:1.45">${esc(c.bChSource())}</div>
          </td></tr>
          ${!c.bChOuvert ? '' : c.bChRows.map(ch => `
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
/* La carte des ratios est partagée : elle s'affiche sur « Marge & coûts » et,
   à la demande, sur « P&L magasins » — même calcul, même rendu, un seul code. */
function tplRatiosCouts(c, x){
  const { esc } = x;
  return `
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
    </div>`;
}

function tplMarge(c, x){
  return `
  <div data-screen="marge" style="display:flex;flex-direction:column;gap:16px">
    ${tplRatiosCouts(c, x)}
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
      <span style="margin-left:auto;display:flex;align-items:center;gap:8px">
        <span style="font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)">Exercice</span>
        <select ${x.C(c.setEncExo)} style="font-size:13px;font-weight:500;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 10px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui)">
          ${c.encExoOpts.map(o => `<option value="${o.v}"${o.v === c.encExoSel ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}
        </select>
      </span>
    </div>
    ${c.encChargement ? `<div style="font-size:12px;color:var(--color-text-muted);background:var(--color-background-secondary);border-radius:9px;padding:9px 13px">Lecture de l'exercice ${esc(c.encExercice)}…</div>` : ''}
    ${c.encChargement ? '' : `

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

    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px 22px">
      <!-- Les charges s'encodent CHAQUE MOIS : le modèle au-dessus dit ce qui
           est attendu, ce bloc-ci garde ce qui est réellement sorti. C'est lui
           que le suivi compare au budget. -->
      <div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:16px;padding-top:15px">
        <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap">
          <div>
            <div style="font-family:var(--font-display);font-size:17px;line-height:1.3">Charges encodées du mois</div>
            <div style="font-size:12px;color:var(--color-text-muted);margin-top:2px">Ce qui est réellement sorti, poste par poste et magasin par magasin. Une case laissée vide n’est pas un zéro : elle reste « non encodée ».</div>
          </div>
          <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap">
            <select ${x.C(c.setEncChMois)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:8px;height:32px;padding:0 10px;font-family:var(--font-ui);font-size:12.5px">
              ${c.encChMoisOpts.map(o => `<option value="${esc(o.v)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}
            </select>
            <span style="font-size:11.5px;color:var(--color-text-muted);margin-right:8px">${esc(c.encChAuto || '')}</span>
            <button ${x.A(c.encChSave)} style="border:none;background:var(--color-primary);color:#fff;border-radius:9px;height:32px;padding:0 15px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Enregistrer les charges du mois</button>
          </div>
        </div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin:9px 0 12px">${esc(c.encChNSaisis)}${c.encChSansId ? ' · <span style="color:var(--color-primary);font-weight:500">enregistrez d’abord le modèle réseau pour attacher les postes</span>' : ''}</div>
        <div style="overflow-x:auto">
        <table style="width:100%;min-width:${320 + (c.encChMagasins || []).length * 190}px;border-collapse:collapse;font-size:12.5px">
          <thead><tr>
            <th style="text-align:left;${lbl};padding:0 10px 9px 0">Poste</th>
            <th style="text-align:right;${lbl};padding:0 10px 9px;width:70px">Taux</th>
            ${(c.encChMagasins || []).map(m2 => `<th style="text-align:right;${lbl};padding:0 6px 9px;min-width:150px">
              ${esc(m2.nom)}<div style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--color-text-muted);margin-top:2px">CA ${esc(m2.ca)}</div></th>`).join('')}
          </tr></thead>
          <tbody>
            ${c.encChLignes.map(l => `<tr style="border-top:0.5px solid var(--color-border-tertiary)">
              <td style="padding:8px 10px 8px 0"><span style="font-weight:500">${esc(l.nom)}</span>${l.categorie ? `<div style="font-size:10.5px;color:var(--color-text-muted)">${esc(l.categorie)}</div>` : ''}</td>
              <td style="padding:8px 10px;text-align:right;color:var(--color-text-muted);font-variant-numeric:tabular-nums">${esc(String(l.pct))} %${l.pctSrc ? `<div style="font-size:9.5px;color:var(--color-text-muted)">${esc(l.pctSrc)}</div>` : ''}</td>
              ${l.cells.map(k2 => `<td style="padding:5px 6px;vertical-align:top">
                <input value="${esc(String(k2.valeur))}" ${x.C(k2.set)} inputmode="decimal" placeholder="non encodé" style="${c.encInputSt}">
                <div style="font-size:10px;color:var(--color-text-muted);text-align:right;margin-top:3px">attendu ${esc(k2.attendu)}${k2.ecart ? ` · <span style="color:${k2.ecartCol};font-weight:500">${esc(k2.ecart)}</span>` : ''}</div>
              </td>`).join('')}
            </tr>`).join('')}
            <tr style="border-top:0.5px solid var(--color-border-secondary)">
              <td style="padding:10px 10px 10px 0;font-weight:500">Total du mois</td>
              <td style="padding:10px;text-align:right;color:var(--color-text-muted);white-space:nowrap;font-size:11px">attendu<br>${esc(c.encChTotAttendu)}</td>
              ${(c.encChTotaux || []).map(t2 => `<td style="padding:10px 6px;text-align:right;white-space:nowrap;font-weight:500">${esc(t2.total)}<div style="font-size:10.5px;font-weight:400;color:var(--color-text-muted)">${esc(t2.pct)}${t2.partiel ? ' · ' + esc(t2.partiel) : ''}</div></td>`).join('')}
            </tr>
          </tbody>
        </table>
        </div>
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
          <!-- L'état de l'enregistrement AUTOMATIQUE : chaque saisie part en
               base après une pause de frappe, le bouton ne sert plus qu'à
               confirmer et à laisser une ligne de journal. -->
          ${c.encAutoEtat ? `<span style="${c.encAutoSt};margin-left:10px">${esc(c.encAutoEtat)}</span>` : ''}
        </div>
      </div>
      ${c.encAlerte ? `<div style="margin-top:12px;padding:10px 13px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:12px;font-weight:500">${esc(c.encAlerte)}</div>` : ''}
      <div style="font-size:11.5px;line-height:1.5;color:var(--color-text-muted);margin-top:12px;text-wrap:pretty">${esc(c.encNote)}</div>
    </div>
    `}
  </div>`;
}

/* Budget × Campagnes : le calendrier des campagnes posé sur la courbe du
   budget, puis l'objectif de la campagne regardée, magasin par magasin. */

/* Mesure d'impact d'une campagne — trois vues sur un bascule :
   paramétrage (avant), résultats (après), produits promus. */
function tplMesure(c, x){
  const { esc } = x;
  const lbl = 'font-size:10px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted)';
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:18px 20px';
  const boite = 'background:var(--color-background-secondary);border-radius:10px;padding:12px 14px';
  const inp = 'font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 9px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui);width:100%';
  const th = 'font-size:10px;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted);font-weight:600;text-align:right;padding:7px 9px;border-bottom:0.5px solid var(--color-border-tertiary)';
  const td = 'padding:8px 9px;border-bottom:0.5px solid var(--color-border-tertiary);text-align:right;font-size:12.5px';
  const jf = s => String(s || '').split('-').reverse().join('/');

  const entete = `
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span style="font-size:12px;color:var(--color-text-muted)">Campagne</span>
      <select ${x.C(c.setMesCamp)} style="font-size:13px;font-weight:500;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 10px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui);max-width:420px">
        ${(c.mesCampOpts || []).map(o => `<option value="${o.v}"${o.v === c.mesCampSel ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}
      </select>
      ${c.mesChargement ? '<span style="font-size:12px;color:var(--color-text-muted)">Lecture des ventes jour par jour…</span>' : ''}
      ${c.mesRecalcul ? '<span style="font-size:12px;color:var(--color-primary)">Recalcul en cours…</span>' : ''}
    </div>`;

  if (c.mesIndispo) {
    return `<div data-screen="mesure" style="display:flex;flex-direction:column;gap:16px;max-width:1240px">${entete}
      <div style="${carte};font-size:13px">${esc(c.mesIndispo)}</div></div>`;
  }
  if (c.mesChargement || c.mesVide) {
    return `<div data-screen="mesure" style="display:flex;flex-direction:column;gap:16px;max-width:1240px">${entete}</div>`;
  }

  const bascule = `
    <div style="display:inline-flex;background:var(--color-background-secondary);border-radius:9px;padding:3px;gap:2px">
      ${(c.mesVues || []).map(v => `<button ${x.A(v.choisir)} style="border:none;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:${v.on ? '600' : '400'};padding:6px 14px;border-radius:7px;background:${v.on ? 'var(--color-surface)' : 'transparent'};color:${v.on ? 'var(--color-primary)' : 'var(--color-text-muted)'};box-shadow:${v.on ? '0 1px 2px rgba(0,0,0,0.06)' : 'none'}">${esc(v.nom)}</button>`).join('')}
    </div>`;

  const titre = `
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div>
        <div style="font-family:var(--font-display);font-size:19px;line-height:1.25">${esc(c.mesNom)}</div>
        <div style="font-size:12px;color:var(--color-text-muted);margin-top:2px">${esc(c.mesType)} · ${esc(c.mesPeriode)} · ${esc(c.mesPerimNoms)}</div>
      </div>
      ${bascule}
    </div>`;

  /* ── A · paramétrage ─────────────────────────────────────────────── */
  const vueParam = () => `
    <div style="${carte};display:flex;flex-direction:column;gap:16px">
      <div>
        <div style="${lbl}">1 · Fenêtres de comparaison</div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:10px">
          <div style="${boite}">
            <div style="${lbl}">Référence « avant »</div>
            <div style="display:flex;gap:6px;margin-top:8px">
              <input type="date" value="${esc(c.mesRef.duCh.val)}" ${x.I(c.mesRef.duCh.set)} style="${inp}">
              <input type="date" value="${esc(c.mesRef.auCh.val)}" ${x.I(c.mesRef.auCh.set)} style="${inp}">
            </div>
            <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:7px">${c.mesRef.jours} jours — semaines pleines : autant de samedis que de lundis.</div>
          </div>
          <div style="${boite}">
            <div style="${lbl}">Campagne</div>
            <div style="font-size:13px;margin-top:8px">${esc(jf(c.mesCamp2.du))} → ${esc(jf(c.mesCamp2.au))}</div>
            <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:7px">${c.mesCamp2.jours} jours${c.mesCamp2.encours ? ' · ' + c.mesCamp2.ecoulee + ' écoulés' : ''}${c.mesCamp2.commencee ? '' : ' · pas encore commencée'}</div>
          </div>
          <div style="${boite}">
            <div style="${lbl}">Rémanence « après »</div>
            <div style="display:flex;align-items:center;gap:8px;margin-top:8px">
              <input type="number" min="0" max="120" value="${esc(c.mesRem.ch.val)}" ${x.I(c.mesRem.ch.set)} style="${inp};width:80px">
              <span style="font-size:12.5px;color:var(--color-text-muted)">jours</span>
            </div>
            <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:7px">${esc(jf(c.mesRem.du))} → ${esc(jf(c.mesRem.au))} — détecte les achats simplement avancés.</div>
          </div>
          <div style="${boite}">
            <div style="${lbl}">Contrôle</div>
            <div style="font-size:13px;margin-top:8px">Période témoin ${esc(jf(c.mesPre.du))} → ${esc(jf(c.mesPre.au))}</div>
            <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:7px">La même mesure, un cran plus tôt : elle donne la variation habituelle du réseau — le bruit.</div>
          </div>
        </div>
      </div>

      <div>
        <div style="${lbl}">2 · Témoin — c'est lui qui rend la mesure vraie</div>
        <div style="font-size:12px;color:var(--color-text-muted);margin:6px 0 9px">Uplift net = variation des magasins en campagne − variation du témoin sur la même fenêtre. Sans témoin, on mesure la météo et la saison.</div>
        <div style="display:inline-flex;background:var(--color-background-secondary);border-radius:9px;padding:3px;gap:2px;margin-bottom:9px">
          ${(c.mesTemoinModes || []).map(m => `<button ${x.A(m.choisir)} style="border:none;cursor:pointer;font-family:var(--font-ui);font-size:12px;font-weight:${m.on ? '600' : '400'};padding:6px 13px;border-radius:7px;background:${m.on ? 'var(--color-surface)' : 'transparent'};color:${m.on ? 'var(--color-primary)' : 'var(--color-text-muted)'};box-shadow:${m.on ? '0 1px 2px rgba(0,0,0,0.06)' : 'none'}">${esc(m.nom)}</button>`).join('')}
        </div>
        <div style="font-size:12px;color:${c.mesTemoinDilue ? '#C17A2A' : 'var(--color-text-muted)'};margin-bottom:9px">${esc(c.mesTemoinNote)}</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px">
          ${(c.mesMagasins || []).map(m => m.enCampagne
            ? `<span style="font-size:12px;padding:6px 11px;border-radius:999px;background:rgba(141,29,44,0.08);color:var(--color-primary);font-weight:500">${esc(m.nom)} · en campagne${m.temoin ? ' + témoin' : ''}</span>`
            : `<button ${x.A(m.bascule)} style="font-size:12px;padding:6px 11px;border-radius:999px;cursor:${m.fige ? 'default' : 'pointer'};font-family:var(--font-ui);border:0.5px solid ${m.temoin ? 'var(--color-text)' : 'var(--color-border-secondary)'};background:${m.temoin ? 'var(--color-text)' : 'var(--color-surface)'};color:${m.temoin ? '#fff' : 'var(--color-text-muted)'}">${esc(m.nom)}${m.temoin ? ' · témoin' : ''}</button>`).join('')}
        </div>
      </div>

      <div>
        <div style="${lbl}">3 · Ce qu'on mesure, et la cible</div>
        <table style="border-collapse:collapse;width:100%;margin-top:8px">
          <tr><th style="${th};text-align:left">Indicateur</th><th style="${th};text-align:left">Source</th><th style="${th}">Base « avant »</th><th style="${th}">Cible</th><th style="${th};text-align:left">Granularité</th></tr>
          ${(c.mesIndics || []).map(i => `<tr>
            <td style="${td};text-align:left"><b>${esc(i.nom)}</b> <span style="color:var(--color-text-muted)">— ${esc(i.detail)}</span></td>
            <td style="${td};text-align:left;color:var(--color-text-muted);font-size:12px">${esc(i.src)}</td>
            <td style="${td}">${esc(i.base)}</td>
            <td style="${td}"><span style="display:inline-flex;align-items:center;gap:5px;justify-content:flex-end"><input type="number" step="0.5" value="${esc(i.cible.val)}" ${x.I(i.cible.set)} style="${inp};width:78px;text-align:right"><span style="font-size:11.5px;color:var(--color-text-muted)">${esc(i.unite)}</span></span></td>
            <td style="${td};text-align:left;font-size:12px;${i.alerte ? 'color:#C17A2A' : 'color:var(--color-text-muted)'}">${esc(i.gran)}</td>
          </tr>`).join('')}
        </table>
      </div>

      <div style="display:grid;grid-template-columns:1.1fr 1fr;gap:12px">
        <div style="${boite}">
          <div style="${lbl}">Références promues</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin:6px 0 8px">Reprises de l'offre de la campagne. Une par ligne pour en ajouter : identifiant, puis libellé (ex. <code>1610004 Bleuet 6 pièces</code>).</div>
          <textarea rows="4" ${x.I(c.mesProduitsTxt.set)} style="${inp};font-family:var(--font-ui);resize:vertical">${esc(c.mesProduitsTxt.val)}</textarea>
        </div>
        <div style="${boite}">
          <div style="${lbl}">Rentabilité</div>
          <div style="display:flex;gap:10px;margin-top:8px">
            <label style="flex:1;font-size:11.5px;color:var(--color-text-muted)">Coût de la campagne (€)
              <input type="number" step="1" value="${esc(c.mesCout.val)}" ${x.I(c.mesCout.set)} style="${inp};margin-top:4px"></label>
            <label style="flex:1;font-size:11.5px;color:var(--color-text-muted)">Marge brute (%)
              <input type="number" step="0.5" value="${esc(c.mesMarge.val)}" ${x.I(c.mesMarge.set)} style="${inp};margin-top:4px"></label>
          </div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:8px">Le retour se calcule sur la marge du CA gagné, pas sur le CA.</div>
        </div>
      </div>

      <div>
        <div style="${lbl}">4 · Relevé Facebook — à la main, avant et après</div>
        <div style="font-size:12px;color:var(--color-text-muted);margin:6px 0 9px">La page n'est pas connectée : deux nombres relevés valent mieux qu'un indicateur absent. À noter le jour du lancement et le jour de la clôture.</div>
        <table style="border-collapse:collapse;width:100%">
          <tr><th style="${th};text-align:left">Page</th><th style="${th}">Abonnés avant</th><th style="${th}">Abonnés après</th><th style="${th}">Écart</th></tr>
          ${(c.mesFbLignes || []).map(l => `<tr>
            <td style="${td};text-align:left">${esc(l.nom)}</td>
            <td style="${td}"><input type="number" min="0" value="${esc(l.avant.val)}" ${x.I(l.avant.set)} style="${inp};width:110px;text-align:right"></td>
            <td style="${td}"><input type="number" min="0" value="${esc(l.apres.val)}" ${x.I(l.apres.set)} style="${inp};width:110px;text-align:right"></td>
            <td style="${td};color:${l.deltaCol};font-weight:600">${esc(l.delta)}</td>
          </tr>`).join('')}
        </table>
      </div>

      <div style="${boite};background:#FBF3DC;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
        <div style="flex:1;min-width:340px">
          <div style="${lbl};color:#8a6d12">Gel de la référence</div>
          <div style="font-size:12px;color:#6b4420;margin-top:5px">Les remontées de caisse arrivent avec du retard : sans photo prise au lancement, l'« avant » change tout seul et le résultat n'est plus reproductible.${c.mesGele ? ' Référence ' + esc(c.mesGele) + '.' : ''}</div>
        </div>
        ${c.mesGele
          ? `<button ${x.A(c.mesDegeler)} style="border:0.5px solid #8a6d12;background:transparent;color:#8a6d12;border-radius:8px;padding:8px 14px;font-size:12.5px;cursor:pointer;font-family:var(--font-ui)">Dégeler</button>`
          : `<button ${x.A(c.mesGeler)} style="border:none;background:var(--color-primary);color:#fff;border-radius:8px;padding:9px 16px;font-size:12.5px;font-weight:500;cursor:pointer;font-family:var(--font-ui)">Geler la référence</button>`}
      </div>
    </div>`;

  /* ── B · résultats ───────────────────────────────────────────────── */
  const vueResultats = () => `
    <div style="${carte};display:flex;flex-direction:column;gap:16px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap">
        <div style="font-size:12px;color:var(--color-text-muted)">Référence ${esc(jf(c.mesRef.du))} → ${esc(jf(c.mesRef.au))} · témoin : ${esc(c.mesTemoinNoms)}</div>
        <div style="text-align:right">
          <div style="${lbl}">Verdict</div>
          <div style="margin-top:4px"><span style="display:inline-block;font-size:13px;font-weight:600;padding:6px 14px;border-radius:999px;${c.mesVerdict.st}">${esc(c.mesVerdict.libelle)}${c.mesVerdict.valeur ? ' · ' + esc(c.mesVerdict.valeur) : ''}</span></div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:5px;font-style:italic">${esc(c.mesVerdict.txt)}</div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px">
        ${(c.mesTuiles || []).map(t => `<div style="${boite}">
          <div style="${lbl}">${esc(t.nom)}</div>
          <div style="font-family:var(--font-display);font-size:23px;margin-top:6px;letter-spacing:-0.4px">${esc(t.val)}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">${esc(t.avant)}${t.d !== '—' ? ' · <b style="color:' + t.dCol + '">' + esc(t.d) + '</b>' : ''}</div>
          ${(t.temoin || t.net) ? `<div style="margin-top:7px;display:flex;gap:5px;flex-wrap:wrap">
            ${t.temoin ? `<span style="font-size:10.5px;padding:3px 8px;border-radius:999px;background:#EDEAE5;color:var(--color-text-muted)">${esc(t.temoin)}</span>` : ''}
            ${t.net ? `<span style="font-size:10.5px;font-weight:600;padding:3px 8px;border-radius:999px;background:var(--color-surface);color:${t.netCol}">${esc(t.net)}</span>` : ''}
          </div>` : ''}
          ${t.note ? `<div style="font-size:11px;color:var(--color-text-muted);margin-top:6px;font-style:italic">${esc(t.note)}</div>` : ''}
        </div>`).join('')}
      </div>

      <div>
        <div style="display:flex;justify-content:space-between;align-items:baseline">
          <div style="${lbl}">Trafic quotidien — indice 100 = moyenne de la référence</div>
          <div style="font-size:11px;color:var(--color-text-muted)"><span style="color:var(--color-primary)">━</span> magasins en campagne &nbsp; <span style="color:#999">╌</span> témoin</div>
        </div>
        ${c.mesCourbe.vide
          ? `<div style="font-size:12.5px;color:var(--color-text-muted);padding:18px 0">Pas de ventes quotidiennes sur cette étendue.</div>`
          : `<svg viewBox="0 0 ${c.mesCourbe.W} ${c.mesCourbe.H}" style="width:100%;height:${c.mesCourbe.H}px;margin-top:6px">
              ${(c.mesCourbe.bandes || []).map(b => `<rect x="${b.x}" y="${c.mesCourbe.yBande}" width="${b.w}" height="${c.mesCourbe.hautBande}" fill="${b.fill}"></rect>
                <text x="${b.milieu}" y="${c.mesCourbe.H - 7}" text-anchor="middle" font-size="9.5" letter-spacing="0.08em" fill="${b.col}">${esc(b.nom)}</text>`).join('')}
              <line x1="0" x2="${c.mesCourbe.W}" y1="${c.mesCourbe.cent}" y2="${c.mesCourbe.cent}" stroke="rgba(34,34,34,0.18)" stroke-width="1" stroke-dasharray="4 4"></line>
              <path d="${c.mesCourbe.temoin}" fill="none" stroke="#999" stroke-width="1.6" stroke-dasharray="4 3"></path>
              <path d="${c.mesCourbe.camp}" fill="none" stroke="var(--color-primary)" stroke-width="2.1"></path>
            </svg>`}
      </div>

      <div style="display:grid;grid-template-columns:1.55fr 0.75fr;gap:14px">
        <div>
          <div style="${lbl};margin-bottom:6px">Par magasin — en % de sa propre base</div>
          <table style="border-collapse:collapse;width:100%">
            <tr><th style="${th};text-align:left">Magasin</th><th style="${th}">Trafic avant</th><th style="${th}">Trafic pendant</th><th style="${th}">Δ trafic</th><th style="${th}">Δ panier</th><th style="${th}">Δ CA net</th><th style="${th}">€ gagnés</th></tr>
            ${(c.mesLignes || []).map(l => `<tr>
              <td style="${td};text-align:left">${esc(l.nom)}</td>
              <td style="${td}">${esc(l.trafAv)}</td><td style="${td}">${esc(l.trafPd)}</td>
              <td style="${td};color:${l.dTrafCol}">${esc(l.dTraf)}</td>
              <td style="${td};color:${l.dPanCol}">${esc(l.dPan)}</td>
              <td style="${td};color:${l.netCol};font-weight:600">${esc(l.net)}</td>
              <td style="${td};color:${l.eurosCol}">${esc(l.euros)}</td></tr>`).join('')}
            ${(c.mesTemLignes || []).map(l => `<tr style="background:var(--color-background-secondary)">
              <td style="${td};text-align:left"><b>Témoin — ${esc(l.nom)}</b></td>
              <td style="${td}">${esc(l.trafAv)}</td><td style="${td}">${esc(l.trafPd)}</td>
              <td style="${td}">${esc(l.dTraf)}</td><td style="${td}">${esc(l.dPan)}</td>
              <td style="${td};color:var(--color-text-muted);font-style:italic">bruit de fond</td><td style="${td}">—</td></tr>`).join('')}
          </table>
          <div style="font-size:11px;color:var(--color-text-muted);margin-top:7px;font-style:italic">Δ CA net = variation du magasin − variation du témoin sur la même fenêtre. € gagnés = ce qu'il a fait en plus de ce qu'il aurait fait en suivant le témoin.</div>
        </div>
        <div style="${boite}">
          <div style="${lbl}">Rentabilité</div>
          <table style="border-collapse:collapse;width:100%;margin-top:6px;font-size:12.5px">
            <tr><td style="padding:4px 0">CA net gagné</td><td style="padding:4px 0;text-align:right;font-weight:600">${esc(c.mesRenta.euros)}</td></tr>
            <tr><td style="padding:4px 0">Marge brute (${esc(c.mesRenta.margePct)})</td><td style="padding:4px 0;text-align:right">${esc(c.mesRenta.marge)}</td></tr>
            <tr><td style="padding:4px 0">Coût campagne</td><td style="padding:4px 0;text-align:right">${esc(c.mesRenta.cout)}</td></tr>
            <tr><td style="padding:7px 0 0"><b>Gain net</b></td><td style="padding:7px 0 0;text-align:right;font-weight:700;color:${c.mesRenta.gainCol}">${esc(c.mesRenta.gain)}</td></tr>
            <tr><td style="padding:4px 0">Retour</td><td style="padding:4px 0;text-align:right;font-weight:600">${esc(c.mesRenta.retour)}</td></tr>
          </table>
          ${c.mesRenta.manque ? `<div style="font-size:11px;color:var(--color-text-muted);margin-top:6px;font-style:italic">${esc(c.mesRenta.manque)}</div>` : ''}
          <div style="height:1px;background:var(--color-border-tertiary);margin:12px 0"></div>
          <div style="${lbl}">Rémanence</div>
          <div style="font-family:var(--font-display);font-size:19px;margin-top:5px;color:${c.mesRemanence.col}">${esc(c.mesRemanence.pct)}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:4px">${esc(c.mesRemanence.txt)}</div>
          ${c.mesBruit ? `<div style="font-size:11px;color:var(--color-text-muted);margin-top:9px;font-style:italic">${esc(c.mesBruit)}</div>` : ''}
        </div>
      </div>
    </div>`;

  /* ── C · produits promus ─────────────────────────────────────────── */
  const vueProduits = () => `
    <div style="${carte};display:flex;flex-direction:column;gap:14px">
      <div>
        <div style="${lbl}">Références promues — réponse à la promotion</div>
        <div style="font-size:12px;color:var(--color-text-muted);margin-top:5px">${esc(c.mesProduitsNote)}</div>
      </div>
      ${(c.mesProduits || []).length ? `
      <table style="border-collapse:collapse;width:100%">
        <tr><th style="${th};text-align:left">Référence</th><th style="${th};text-align:left">Catégorie</th><th style="${th}">Base / jour</th><th style="${th}">Campagne / jour</th><th style="${th}">Réponse</th><th style="${th}">vs N-1</th><th style="${th}">Rémanence</th><th style="${th};text-align:left">Score</th></tr>
        ${c.mesProduits.map(p => `<tr>
          <td style="${td};text-align:left"><b>${esc(p.nom)}</b><div style="font-size:10.5px;color:var(--color-text-muted)">${esc(p.sku)}${p.source === 'saisie' ? ' · ajoutée à la main' : ''}</div></td>
          <td style="${td};text-align:left;color:var(--color-text-muted);font-size:12px">${esc(p.categorie || '—')}</td>
          <td style="${td}">${esc(p.refJour)} u</td>
          <td style="${td}">${esc(p.campJour)} u</td>
          <td style="${td};color:${p.reponseCol};font-weight:600">${esc(p.reponse)}</td>
          <td style="${td}">${esc(p.n1Var)}</td>
          <td style="${td};color:${p.remanenceCol}">${esc(p.remanence)}</td>
          <td style="${td};text-align:left"><span style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:999px;${p.score.st}">${esc(p.score.t)}</span></td>
        </tr>`).join('')}
      </table>
      <div>
        <div style="${lbl};margin-bottom:8px">Volume par jour — base contre campagne</div>
        <div style="display:flex;gap:18px;align-items:flex-end;height:130px;padding:0 4px">
          ${c.mesProduits.map(p => `<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;height:100%">
            <div style="flex:1;display:flex;align-items:flex-end;gap:5px;width:100%;justify-content:center">
              <div title="base ${esc(p.refJour)} u/j" style="width:26px;height:${p.hRef}%;background:rgba(34,34,34,0.16);border-radius:3px 3px 0 0"></div>
              <div title="campagne ${esc(p.campJour)} u/j" style="width:26px;height:${p.hCamp}%;background:var(--color-primary);border-radius:3px 3px 0 0"></div>
            </div>
            <div style="font-size:10.5px;color:var(--color-text-muted);text-align:center;line-height:1.2;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(p.nom)}</div>
          </div>`).join('')}
        </div>
      </div>` : ''}
      <div style="font-size:11.5px;color:var(--color-text-muted);font-style:italic">Le score compare la campagne à la base, au jour, et pénalise une référence dont le volume retombe sous la base après la campagne : elle a vendu d'avance, elle n'a pas vendu en plus.</div>
    </div>`;

  return `
  <div data-screen="mesure" style="display:flex;flex-direction:column;gap:16px;max-width:1240px">
    ${entete}
    ${titre}
    ${c.mesVue === 'param' ? vueParam() : (c.mesVue === 'produits' ? vueProduits() : vueResultats())}
    ${(c.mesMotifs || []).length ? `<div style="font-size:11.5px;color:var(--color-text-muted)">${(c.mesMotifs || []).map(m => esc(m)).join(' · ')}</div>` : ''}
    <div style="font-size:11px;color:var(--color-text-muted)">${esc(c.mesSource)}</div>
  </div>`;
}

function tplBxc(c, x){
  const { esc } = x;
  const lbl = 'font-size:10px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted)';
  if (c.bxcIndispo) {
    return `<div data-screen="bxcampagnes"><div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px 22px;font-size:13px">${esc(c.bxcIndispo)}</div></div>`;
  }
  return `
  <div data-screen="bxcampagnes" style="display:flex;flex-direction:column;gap:16px;max-width:1240px">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span style="font-size:12px;color:var(--color-text-muted)">Exercice</span>
      <select ${x.C(c.setBxcExo)} style="font-size:13px;font-weight:500;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 10px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui)">
        ${c.bxcExoOpts.map(o => `<option value="${o.v}"${o.v === c.bxcExo ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}
      </select>
      ${c.bxcChargement ? '<span style="font-size:12px;color:var(--color-text-muted)">Lecture du budget et des campagnes…</span>' : ''}
    </div>

    ${c.bxcChargement ? '' : `
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:18px 20px">
      <div style="font-family:var(--font-display);font-size:18px;line-height:1.3">Budget et campagnes — ${esc(c.bxcExo)}</div>
      <div style="font-size:12px;color:var(--color-text-muted);margin:2px 0 12px">Barres claires : CA budgété du mois (le théorique de l'étude à défaut). Barres ambrées : mois couvert par au moins une campagne.</div>
      <div style="display:grid;grid-template-columns:repeat(12,1fr);gap:6px;align-items:end;height:132px">
        ${c.bxcMois.map(m => `<div style="display:flex;flex-direction:column;justify-content:flex-end;height:100%" title="${esc(m.nom)} — ${esc(m.montant)} (${esc(m.source)})">
          <div style="height:${m.h}%;border-radius:3px 3px 0 0;background:${m.couvert ? 'var(--pkg-abricot)' : '#DED6C9'}"></div></div>`).join('')}
      </div>
      <div style="display:grid;grid-template-columns:repeat(12,1fr);gap:6px;margin-top:4px">
        ${c.bxcMois.map(m => `<div style="font-size:9.5px;color:var(--color-text-muted);text-align:center">${esc(m.nom)}</div>`).join('')}
      </div>
      <div style="margin-top:10px;display:flex;flex-direction:column;gap:7px">
        ${c.bxcTypes.map(t2 => `
          <div style="display:flex;align-items:flex-start;gap:9px">
            <span style="width:118px;flex:none;padding-top:4px;font-size:10px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:var(--color-text-muted);display:flex;align-items:center;gap:6px">
              <i style="display:inline-block;width:8px;height:8px;border-radius:2px;flex:none;background:${t2.couleur}"></i>
              <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(t2.nom)}</span>
            </span>
            <span style="flex:1;display:flex;flex-direction:column;gap:4px">
              ${t2.lignes.map(li => `<span style="position:relative;display:block;height:22px;background:var(--color-background-secondary);border:0.5px solid var(--color-border-tertiary);border-radius:7px">
                ${li.bandes.map(b2 => `<span ${x.A(b2.choisir)} title="${esc(b2.titre)}" style="position:absolute;top:2px;left:${b2.gauche}%;width:${b2.largeur}%;height:16px;border-radius:5px;cursor:pointer;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;padding:0 5px;font-size:9.5px;font-weight:600;line-height:16px;text-align:center;${b2.on ? 'background:' + t2.couleur + ';color:#fff' : 'background:var(--color-surface);border:1px solid ' + t2.couleur + ';color:var(--color-text)'}">${esc(b2.nom)}</span>`).join('')}
              </span>`).join('')}
            </span>
          </div>`).join('')}
      </div>
      ${c.bxcCouvNote ? `<div style="font-size:11px;color:var(--color-text-muted);margin-top:8px">${esc(c.bxcCouvNote)}</div>` : ''}
    </div>

    ${c.bxcAucune ? `<div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px 22px;font-size:13px;color:var(--color-text-muted)">Aucune campagne sur cet exercice.</div>` : `
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:18px 20px">
      <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px">
        <div>
          <div style="font-family:var(--font-display);font-size:18px;line-height:1.3">Objectifs par magasin</div>
          <div style="font-size:12px;color:var(--color-text-muted);margin-top:2px">${esc(c.bxcCampNom)} · ${esc(c.bxcCampPeriode)} — le budget de la période vient du budget mensuel du magasin, au prorata des jours.</div>
        </div>
        <div style="display:flex;align-items:center;gap:9px">
          <span style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.bxcEtat || '')}</span>
          <select ${x.C(c.setBxcCamp)} style="font-size:12.5px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:7px 10px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui)">
            ${c.bxcCampOpts.map(o => `<option value="${o.v}"${o.v === c.bxcCampSel ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}
          </select>
        </div>
      </div>
      <div style="overflow-x:auto">
      <table style="width:100%;min-width:840px;border-collapse:collapse;font-size:12.5px">
        <thead><tr>
          <th style="text-align:left;${lbl};padding:0 8px 8px 0">Magasin</th>
          <th style="text-align:right;${lbl};padding:0 8px 8px">Budget de la période</th>
          <th style="text-align:right;${lbl};padding:0 8px 8px;width:130px">Objectif</th>
          <th style="text-align:right;${lbl};padding:0 8px 8px">vs budget</th>
          <th style="text-align:right;${lbl};padding:0 8px 8px">CA réalisé</th>
          <th style="text-align:right;${lbl};padding:0 8px 8px">Écart</th>
          <th style="text-align:left;${lbl};padding:0 0 8px 8px;width:150px">Atteinte</th>
        </tr></thead>
        <tbody>
          ${c.bxcLignes.map(l => `<tr style="border-top:0.5px solid var(--color-border-tertiary)">
            <td style="padding:8px 8px 8px 0;font-weight:500">${esc(l.nom)}</td>
            <td style="padding:8px;text-align:right;font-variant-numeric:tabular-nums">${esc(l.budget)}${l.source ? `<div style="font-size:9.5px;color:var(--color-text-muted)">${esc(l.source)}</div>` : ''}</td>
            <td style="padding:5px 8px;text-align:right"><input type="number" value="${esc(String(l.objectif))}" ${x.C(l.setObjectif)} placeholder="—" style="width:118px;box-sizing:border-box;text-align:right;font-size:12.5px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:6px 9px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui)"></td>
            <td style="padding:8px;text-align:right;color:var(--color-text-muted);font-variant-numeric:tabular-nums">${esc(l.pct)}</td>
            <td style="padding:8px;text-align:right;font-variant-numeric:tabular-nums">${esc(l.realise)}</td>
            <td style="padding:8px;text-align:right;font-weight:500;color:${l.ecartCol};font-variant-numeric:tabular-nums">${esc(l.ecart)}</td>
            <td style="padding:8px 0 8px 8px"><span style="display:inline-block;width:96px;height:7px;border-radius:999px;background:var(--color-background-secondary);overflow:hidden;vertical-align:middle"><i style="display:block;height:100%;width:${l.barre}%;background:${l.barreCol}"></i></span> <span style="font-size:11px;color:var(--color-text-muted)">${esc(l.atteinte)}</span></td>
          </tr>`).join('')}
          <tr style="border-top:1.5px solid var(--color-border-secondary)">
            <td style="padding:10px 8px 10px 0;font-weight:600">Total</td>
            <td style="padding:10px 8px;text-align:right;font-weight:600;font-variant-numeric:tabular-nums">${esc(c.bxcTotBudget)}</td>
            <td style="padding:10px 8px;text-align:right;font-weight:600;font-variant-numeric:tabular-nums">${esc(c.bxcTotObjectif)}</td>
            <td style="padding:10px 8px;text-align:right;color:var(--color-text-muted)">${esc(c.bxcTotPct)}</td>
            <td style="padding:10px 8px;text-align:right;font-weight:600;font-variant-numeric:tabular-nums">${esc(c.bxcTotRealise)}</td>
            <td style="padding:10px 8px;text-align:right;font-weight:600;color:${c.bxcTotEcartCol};font-variant-numeric:tabular-nums">${esc(c.bxcTotEcart)}</td>
            <td></td>
          </tr>
        </tbody>
      </table>
      </div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:10px;line-height:1.55">
        L'objectif se saisit en euros ; « vs budget » dit ce qu'il demande en plus. Chaque saisie part en base après une pause de frappe.
        ${c.bxcRealiseNote ? '<br>' + esc(c.bxcRealiseNote) : ''}
      </div>
    </div>`}
    `}
  </div>`;
}

/* Les réglages du budget : ce qui se décide une fois par an (l'étude de
   marché d'un magasin) ou une fois pour tout le réseau (les taux de charges).
   Séparé de l'encodage, qui lui se fait chaque mois. */
function tplBudgetParam(c, x){
  // `esc` vit dans les aides passées au gabarit : l'oublier ici faisait échouer
  // le rendu, et l'écran précédent restait affiché — l'erreur était invisible.
  const { esc } = x;
  const lbl = 'font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)';
  const opts = (arr, sel, v, t) => arr.map(o => `<option value="${v(o)}"${String(v(o)) === String(sel) ? ' selected' : ''}>${t(o)}</option>`).join('');
  return `
  <div data-screen="budgetparam" style="display:flex;flex-direction:column;gap:16px;max-width:1180px">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span style="font-size:12px;color:var(--color-text-muted)">Magasin</span>
      <select ${x.C(c.setEncStore)} style="font-size:13px;font-weight:500;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 10px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui)">
        ${opts(c.encStoreOpts, c.encStore, o => o.id, o => esc(o.nom))}
      </select>
      <span style="font-size:12px;color:var(--color-text-muted)">${esc(c.encMeta)}</span>
      <span style="margin-left:auto;font-size:11px;color:var(--color-text-muted)">L'étude appartient au magasin ; les taux de charges valent pour tout le réseau.</span>
    </div>
    <div style="display:flex;flex-direction:column;gap:16px">
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-top:3px solid var(--pkg-abricot);border-radius:12px;padding:20px 22px">
        <div style="font-family:var(--font-display);font-size:18px;line-height:1.3">Étude de marché</div>
        <div style="font-size:12px;color:var(--color-text-muted);margin:2px 0 16px">Potentiel à maturité, montée en régime et saisonnalité. Enregistrer écrit le CA théorique de l'exercice <em>et</em> des trois suivants, mois par mois.</div>
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
        <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;border-top:0.5px solid var(--color-border-tertiary);padding-top:12px;margin-bottom:10px">
          <div>
            <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap">
              <span style="${lbl.replace('0.06em', '0.08em')};color:var(--pkg-abricot)">CA théorique de l'exercice</span>
              <select ${x.C(c.setEncProj)} style="font-size:11.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:4px 8px;background:var(--color-surface);color:var(--color-text)">
                ${c.encProjOpts.map(o => `<option value="${o.v}"${o.v === c.encProjSel ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}
              </select>
            </div>
            <div style="font-size:22px;font-weight:500;margin-top:3px;color:var(--pkg-abricot)">${c.encTheoExercice}</div>
            <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">${esc(c.encCoef)}</div>
          </div>
          <button ${x.A(c.encLisser)} class="hv-apr" style="border:0.5px solid var(--pkg-abricot);background:none;cursor:pointer;font-family:var(--font-ui);font-size:12px;font-weight:500;color:var(--pkg-abricot);padding:8px 15px;border-radius:999px">Lisser sur les 12 mois</button>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px">
          ${c.encProjLignes.map(l => `
            <div style="flex:1;min-width:132px;border:${l.choisi ? '1.5px solid var(--pkg-abricot)' : '0.5px solid var(--color-border-tertiary)'};border-radius:10px;padding:8px 11px;background:${l.choisi ? 'var(--color-background-secondary)' : 'var(--color-surface)'}">
              <div style="font-size:10px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted)">${esc(l.exercice)}${l.courant ? ' · en cours' : ''}</div>
              <div style="font-size:14px;font-weight:500;margin-top:2px">${esc(l.ca)}</div>
              <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:1px">${esc(l.annee)} — ${esc(l.coef)}</div>
            </div>`).join('')}
        </div>
        <div style="font-size:11px;color:var(--color-text-muted);margin-bottom:14px">${esc(c.encProjNote)}</div>
        <div style="${lbl};margin-bottom:8px">Variation par mois (% du CA annuel)</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(84px,1fr));gap:9px">
          ${c.encSais.map(s => `
            <label style="display:flex;flex-direction:column;gap:4px">
              <span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted)">${s.nom}</span>
              <input type="number" step="0.1" value="${s.valeur}" ${x.C(s.set)} style="${c.encInputSt};font-size:12px;padding:6px 7px" />
              <span style="font-size:10px;color:var(--color-text-muted);text-align:right">${s.montant}</span>
            </label>`).join('')}
        </div>
        <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin:11px 0 8px">
          <span style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.encRampNote)}</span>
          <span style="${c.encSaisTotSt}">Total ${c.encSaisTot}</span>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px">
          <span style="flex:1;min-width:240px;font-size:11px;color:${c.encSaisSource === 'magasin' ? 'var(--color-text-muted)' : '#8a5a13'}">${esc(c.encSaisSourceTxt)}</span>
          <button ${x.A(c.encSaisReseauSave)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:999px;padding:6px 14px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">En faire la courbe du réseau</button>
          <button ${x.A(c.encSaisPousser)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:999px;padding:6px 14px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer" title="${esc(c.encSaisAutres)}">Appliquer aux autres magasins</button>
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
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;border-top:0.5px solid var(--color-border-tertiary);margin-top:16px;padding-top:14px">
          <div style="flex:1;min-width:260px;font-size:11.5px;color:var(--color-text-muted)">
            Chaque saisie part déjà en base ; ce bouton l'écrit avec sa ligne de journal et pose le théorique des trois exercices suivants.
            ${c.encProjFait ? `<div style="margin-top:4px;color:var(--pkg-abricot);font-weight:500">Théorique écrit — ${esc(c.encProjFait)}</div>` : ''}
          </div>
          <span style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.encEtudeEtat || '')}</span>
          <button ${x.A(c.encEtudeSave)} style="border:none;background:var(--pkg-abricot);color:#fff;border-radius:999px;padding:9px 20px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">Enregistrer l'étude et projeter</button>
        </div>
      </div>
    </div>

    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px 22px">
      <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap">
        <div>
          <div style="font-family:var(--font-display);font-size:18px;line-height:1.3">Répartition des charges</div>
          <div style="font-size:12px;color:var(--color-text-muted);margin:2px 0 0">Un taux par poste, lu en euros sur trois bases : le CA théorique de l’étude, le CA validé avec le franchisé, et le CA réel déjà encaissé. Catégories, libellés et comptes se saisissent ici.</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button ${x.A(c.encCatAdd)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:9px;height:32px;padding:0 13px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">+ Catégorie</button>
          <span style="font-size:11.5px;color:var(--color-text-muted);margin-right:8px">${esc(c.encChargesAuto || '')}</span>
          <button ${x.A(c.encChargesSave)} style="border:none;background:var(--color-primary);color:#fff;border-radius:9px;height:32px;padding:0 15px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Enregistrer pour le réseau</button>
        </div>
      </div>
      <!-- Les taux sont communs : le dire à l'endroit où on les modifie évite
           de croire qu'on ajuste seulement le magasin qu'on regarde. -->
      <div style="display:flex;gap:9px;align-items:flex-start;background:rgba(141,29,44,0.05);border:0.5px solid rgba(141,29,44,0.2);border-radius:9px;padding:10px 13px;margin:11px 0 12px">
        <span style="font-size:10px;font-weight:600;padding:2px 9px;border-radius:999px;background:var(--color-primary);color:#fff;white-space:nowrap;flex:0 0 auto">réseau</span>
        <div style="font-size:11.5px;line-height:1.5">${esc(c.encReseauNote)}${c.encChargesModifie ? ' <span style="font-weight:600;color:var(--color-primary)">Modifications non enregistrées.</span>' : ''}</div>
      </div>
      <div style="font-size:11.5px;color:var(--color-text-muted);margin:0 0 14px">${esc(c.encReelNote)}</div>
      ${c.encChargesVide ? `<div style="border:1px dashed var(--color-border-secondary);border-radius:10px;padding:20px;text-align:center;font-size:12.5px;color:var(--color-text-muted);line-height:1.6">Aucun poste de charge dans le modèle réseau. Ajoutez une catégorie pour commencer — ce que vous saisirez vaudra pour tous les magasins.</div>` : `
      <div style="overflow-x:auto">
      <table style="width:100%;min-width:1180px;border-collapse:collapse;font-size:12.5px">
        <thead><tr>
          <th style="text-align:left;${lbl};padding:0 10px 9px 0">Poste</th>
          <th style="text-align:left;${lbl};padding:0 6px 9px;width:170px">Description</th>
          <th style="text-align:left;${lbl};padding:0 6px 9px;width:180px">Gestion</th>
          <th style="text-align:left;${lbl};padding:0 6px 9px;width:96px" title="Plan comptable minimum normalisé">PCMN</th>
          <th style="text-align:right;${lbl};color:var(--pkg-abricot);padding:0 6px 9px;width:92px">% théo.</th>
          <th style="text-align:right;${lbl};color:var(--pkg-abricot);padding:0 6px 9px;width:120px">€ théorique</th>
          <th style="text-align:right;${lbl};padding:0 6px 9px;width:92px">% validé</th>
          <th style="text-align:right;${lbl};padding:0 6px 9px;width:120px">€ validé</th>
          <th style="text-align:right;${lbl};padding:0 6px 9px;width:120px" title="Le même taux appliqué au CA réellement encaissé">€ réel</th>
          <th style="text-align:right;${lbl};padding:0 0 9px 6px;width:96px">Écart</th>
        </tr></thead>
        <tbody>
          ${c.encCats.map(cat => `
            <!-- L'ordre des catégories EST la lecture du compte de résultat :
                 il se change ici, à la souris ou aux flèches. Une catégorie
                 emporte ses postes et les étapes qui s'y rattachent. -->
            <tr draggable="true" ${x.DS(cat.prendre)} ${x.DP(cat.deposer)}><td colspan="10" style="padding:14px 0 6px;cursor:grab">
              <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap">
                <span title="Glissez pour réordonner" style="color:var(--color-text-muted);font-size:12px;line-height:1;letter-spacing:1px">⠿</span>
                <button ${x.A(cat.monter)} title="Remonter cette catégorie" style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:${cat.monter ? 'var(--color-text-muted)' : 'var(--color-border-tertiary)'};border-radius:6px;width:22px;height:22px;font-size:11px;cursor:${cat.monter ? 'pointer' : 'not-allowed'};padding:0;line-height:1">↑</button>
                <button ${x.A(cat.descendre)} title="Descendre cette catégorie" style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:${cat.descendre ? 'var(--color-text-muted)' : 'var(--color-border-tertiary)'};border-radius:6px;width:22px;height:22px;font-size:11px;cursor:${cat.descendre ? 'pointer' : 'not-allowed'};padding:0;line-height:1">↓</button>
                <input value="${esc(cat.nom)}" ${x.C(cat.renommer)} style="border:none;border-bottom:1px solid var(--color-border-tertiary);background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12.5px;font-weight:600;padding:2px 0;min-width:180px">
                <span style="font-size:11px;color:var(--color-text-muted)">${esc(cat.total)} du CA · ${esc(cat.totalE)}</span>
                <button ${x.A(cat.ajouter)} style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text-muted);border-radius:999px;padding:2px 10px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer">+ poste</button>
                <button ${x.A(cat.ajouterPalier)} title="Poser un résultat intermédiaire après cette catégorie — marge brute, marge sur coûts fixes…" style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text-muted);border-radius:999px;padding:2px 10px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer">+ étape</button>
              </div>
            </td></tr>
            ${cat.lignes.map(ch => `
            <tr style="border-top:0.5px solid var(--color-border-tertiary)">
              <td style="padding:7px 10px 7px 0">
                <span style="display:inline-flex;align-items:center;gap:8px;width:100%"><span class="levier-dot" data-lev="${ch.lev}"></span>
                <input value="${esc(ch.nom)}" title="${esc(ch.nom)}" ${x.C(ch.setNom)} placeholder="Libellé du poste" style="flex:1;min-width:0;border:0.5px solid transparent;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12.5px;padding:5px 6px;border-radius:6px">
                <button ${x.A(ch.monter)} title="Remonter ce poste dans sa catégorie" style="flex:0 0 auto;border:none;background:none;color:var(--color-text-muted);font-size:10px;cursor:pointer;padding:0 1px;line-height:1">↑</button>
                <button ${x.A(ch.descendre)} title="Descendre ce poste dans sa catégorie" style="flex:0 0 auto;border:none;background:none;color:var(--color-text-muted);font-size:10px;cursor:pointer;padding:0 1px;line-height:1">↓</button>
                <button ${x.A(ch.retirer)} title="Retirer ce poste" style="flex:0 0 auto;border:none;background:none;color:var(--color-text-muted);font-size:11px;cursor:pointer;padding:0 2px">✕</button></span>
              </td>
              <td style="padding:5px 6px"><input value="${esc(ch.description)}" title="${esc(ch.description)}" ${x.C(ch.setDesc)} placeholder="—" style="width:100%;box-sizing:border-box;border:0.5px solid transparent;background:transparent;color:var(--color-text-muted);font-family:var(--font-ui);font-size:11.5px;padding:5px 6px;border-radius:6px"></td>
              <td style="padding:5px 6px"><input value="${esc(ch.gestion)}" title="${esc(ch.gestion)}" ${x.C(ch.setGestion)} placeholder="—" style="width:100%;box-sizing:border-box;border:0.5px solid transparent;background:transparent;color:var(--color-text-muted);font-family:var(--font-ui);font-size:11.5px;padding:5px 6px;border-radius:6px"></td>
              <td style="padding:5px 6px"><input value="${esc(ch.pcmn)}" ${x.C(ch.setPcmn)} placeholder="—" title="Compte du plan comptable" style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-tertiary);background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui);font-size:11.5px;padding:5px 6px;border-radius:6px;font-variant-numeric:tabular-nums"></td>
              <td style="padding:5px 6px"><input type="number" step="0.1" value="${ch.valeurT}" ${x.C(ch.setT)} style="${c.encInputSt}" /></td>
              <td style="padding:9px 6px;text-align:right;white-space:nowrap;color:var(--pkg-abricot)">${ch.montantT}</td>
              <td style="padding:5px 6px"><input type="number" step="0.1" value="${ch.valeur}" ${x.C(ch.set)} style="${c.encInputSt}" /></td>
              <td style="padding:9px 6px;text-align:right;white-space:nowrap;font-weight:500">${ch.montant}</td>
              <td style="padding:9px 6px;text-align:right;white-space:nowrap;color:var(--color-text-muted)">${ch.montantR}</td>
              <td style="${ch.ecartSt}">${ch.ecart}</td>
            </tr>`).join('')}
            ${cat.paliers.map(p2 => tplEncPalier(p2, x)).join('')}`).join('')}
          ${(c.encPaliersOrphelins || []).length ? `<tr><td colspan="10" style="padding:12px 0 4px;font-size:11px;color:var(--color-text-muted)">Étapes sans catégorie d’ancrage — replacez-les ou retirez-les :</td></tr>
            ${c.encPaliersOrphelins.map(p2 => tplEncPalier(p2, x)).join('')}` : ''}
          <tr style="border-top:0.5px solid var(--color-border-secondary)">
            <td colspan="4" style="padding:11px 10px 11px 0;font-weight:500">Total charges</td>
            <td style="padding:11px 6px;text-align:right;white-space:nowrap;font-weight:500;color:var(--pkg-abricot)">${c.encPctTotT}</td>
            <td style="padding:11px 6px;text-align:right;white-space:nowrap;font-weight:500;color:var(--pkg-abricot)">${c.encChTotT}</td>
            <td style="padding:11px 6px;text-align:right;white-space:nowrap;font-weight:500">${c.encPctTot}</td>
            <td style="padding:11px 6px;text-align:right;white-space:nowrap;font-weight:500">${c.encChTot}</td>
            <td style="padding:11px 6px;text-align:right;white-space:nowrap;font-weight:500;color:var(--color-text-muted)" title="Charges au taux validé, appliquées au CA réellement encaissé">${c.encChTotR}</td>
            <td style="padding:11px 0 11px 6px"></td>
          </tr>
        </tbody>
      </table>
      </div>`}
    </div>
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
                <button ${x.A(r.ouvrirDetail)} title="Le score décomposé, et les suites possibles" style="border:none;background:none;padding:0;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:500;color:var(--color-text);text-align:left" class="hv-line">${esc(r.nom)}</button>
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
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <!-- Trois lectures d'un même portefeuille : où en est chaque projet, ce
           qu'il coûte et rapporte, et ce qu'il demande à une boutique. La
           troisième question ne se lit pas dans un kanban. -->
      ${(c.pjVueBtns || []).map(v => `<button ${x.A(v.go)} style="border-radius:9px;height:32px;padding:0 14px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer;${v.on ? 'border:none;background:var(--color-primary);color:#fff' : 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text)'}">${esc(v.nom)}</button>`).join('')}
      <div style="flex:1"></div>
      <button ${x.A(c.npOpen)} class="hv-fade" style="border:none;cursor:pointer;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:9px 18px;border-radius:999px">+ Nouveau projet</button>
    </div>
    ${c.pjBudgets ? tplProjetsBudgets(c, x) : ''}
    ${c.pjFranchise ? tplProjetsFranchise(c, x) : ''}
    ${c.pjVue !== 'kanban' ? '' : `
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
    <div style="font-size:12px;color:var(--color-text-muted)">Colonnes = famille de projet. Cliquez une carte pour dérouler ses étapes, cochez-les au fil de l'eau, (i) pour le détail. Glissez-déposez une carte pour changer de famille — tout est tracé dans le journal.</div>`}
  </div>`;
}


/* --- Fonds & Royalties : lu chez le module marketing -------------------------
   Le cockpit ne recopie pas le grand livre : il le lit là où il est tenu. Deux
   soldes pour le même fonds, et c'est celui qui a tort qu'on regarderait. */
/* --- Scoring · le détail d'une référence, et ses deux suites -----------------
   Le score décomposé critère par critère (avec les poids des réglages), puis
   les deux gestes qu'un mauvais score appelle : envoyer la référence aux
   projets avec l'adaptation demandée, ou programmer son arrêt et l'annoncer. */
function tplScoringDetail(c, x){
  const { esc } = x;
  const d = c.pdDet;
  const lbl = 'font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--color-text-muted)';
  const inp = 'width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);border-radius:8px;height:32px;padding:0 9px;font-family:var(--font-ui);font-size:12px;background:var(--color-surface);color:var(--color-text)';
  return `
  <div ${x.A(d.fermer)} style="position:fixed;inset:0;background:rgba(20,16,14,0.5);z-index:80;animation:fadeIn 160ms ease"></div>
  <div style="position:fixed;inset:0;z-index:81;display:flex;align-items:center;justify-content:center;padding:22px;pointer-events:none">
    <div style="pointer-events:auto;background:var(--color-surface);border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,0.3);width:760px;max-width:100%;max-height:100%;overflow-y:auto;padding:18px 22px" data-scroll="pddet">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px">
        <div>
          <div style="${lbl}">Détail du score — ${esc(d.periode)}</div>
          <div style="font-size:17px;font-weight:500;margin-top:3px">${esc(d.nom)}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">${esc(d.ref)} · ${esc(d.cat)}${d.finLe ? ` · <span style="color:var(--color-primary);font-weight:600">fin programmée le ${esc(d.finLe)}</span>` : ''}</div>
        </div>
        <div style="display:flex;align-items:center;gap:12px">
          <div style="text-align:right"><div style="font-size:30px;font-weight:600;line-height:1;color:${d.col}">${esc(d.score)}</div>
            <span style="display:inline-block;margin-top:4px;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:500;background:${d.fond};color:${d.col}">${esc(d.verdict)}</span></div>
          <button ${x.A(d.fermer)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted);border-radius:999px;width:28px;height:28px;font-size:14px;cursor:pointer">✕</button>
        </div>
      </div>

      <div style="margin-top:14px">
        ${d.criteres.map(cr => `<div style="display:flex;align-items:center;gap:11px;padding:7px 0;border-top:0.5px solid var(--color-border-tertiary)">
          <span style="width:190px;font-size:12.5px">${esc(cr.nom)} <span style="color:var(--color-text-muted);font-size:10.5px">· poids ${esc(cr.poids)}</span></span>
          <div style="flex:1;height:8px;border-radius:999px;background:var(--color-border-tertiary);overflow:hidden"><i style="display:block;height:100%;border-radius:999px;width:${cr.barre}%;background:${cr.col}"></i></div>
          <span style="width:66px;text-align:right;font-size:12px;font-weight:600;${cr.absent ? 'color:var(--color-text-muted);font-weight:400' : ''}">${esc(cr.note)}</span>
          <span style="width:170px;text-align:right;font-size:11px;color:var(--color-text-muted)">${esc(cr.brut)}</span>
        </div>`).join('')}
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px">
        <div style="border:0.5px solid var(--color-border-secondary);border-radius:11px;padding:13px 15px">
          <div style="font-size:13px;font-weight:600">→ Envoyer aux projets</div>
          <div style="font-size:11px;color:var(--color-text-muted);margin:3px 0 10px;line-height:1.45">Crée une ligne dans Projets de développement, rattachée à la référence, avec l’adaptation demandée.</div>
          <select ${x.C(d.setAdaptation)} style="${inp};margin-bottom:8px">${d.adaptations.map(a => `<option${a === d.adaptation ? ' selected' : ''}>${esc(a)}</option>`).join('')}</select>
          <input id="pdd-prec" value="${esc(d.precision)}" ${x.I(d.setPrecision)} placeholder="Précision (facultatif)" style="${inp};margin-bottom:10px">
          <button ${x.A(d.envoyerProjet)} style="border:none;background:var(--color-primary);color:#fff;border-radius:999px;height:32px;padding:0 15px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Créer la ligne projet</button>
        </div>
        <div style="border:0.5px solid var(--color-border-secondary);border-radius:11px;padding:13px 15px">
          <div style="font-size:13px;font-weight:600">⏹ Arrêter le produit</div>
          <div style="font-size:11px;color:var(--color-text-muted);margin:3px 0 10px;line-height:1.45">Programme la fin de la référence et l’annonce au réseau — bandeau au Catalogue et à l’Assortiment.</div>
          <input type="date" value="${esc(d.finDate)}" ${x.C(d.setFinDate)} style="${inp};margin-bottom:8px">
          <input id="pdd-fin" value="${esc(d.finTexte)}" ${x.I(d.setFinTexte)} placeholder="Note au réseau — ex. remplacé par… dès octobre" style="${inp};margin-bottom:10px">
          <button ${x.A(d.arreter)} style="border:none;background:#666;color:#fff;border-radius:999px;height:32px;padding:0 15px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Programmer l’arrêt et informer</button>
          ${d.annulerFin ? `<button ${x.A(d.annulerFin)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-primary);border-radius:999px;height:32px;padding:0 13px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;margin-left:7px">Annuler l’arrêt</button>` : ''}
          ${d.finNote ? `<div style="font-size:10.5px;color:var(--color-text-muted);margin-top:8px">Note actuelle : ${esc(d.finNote)}</div>` : ''}
        </div>
      </div>
    </div>
  </div>`;
}

/* --- Encodage · une étape intermédiaire du compte de résultat ----------------
   « Marge brute = CA − coût matière ». L'étape ne porte aucun montant propre :
   elle nomme une soustraction entre deux valeurs déjà présentes, et se
   recalcule d'elle-même quand un taux bouge. */
function tplEncPalier(p, x){
  const { esc } = x;
  const sel = (opts, set) => `<select ${x.C(set)} style="border:0.5px solid var(--color-border-tertiary);background:var(--color-surface);color:var(--color-text);border-radius:6px;height:26px;padding:0 6px;font-family:var(--font-ui);font-size:11px;max-width:190px">
    ${opts.map(o => `<option value="${esc(o.v)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}
  </select>`;
  return `
  <tr style="border-top:1px solid var(--color-border-secondary);background:rgba(141,29,44,0.035)">
    <td colspan="4" style="padding:9px 10px 9px 0">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="font-size:11px;color:var(--color-text-muted)">=</span>
        <input value="${esc(p.nom)}" ${x.C(p.setNom)} placeholder="Nom de l’étape" style="border:none;border-bottom:1px solid var(--color-border-tertiary);background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12.5px;font-weight:600;padding:2px 0;min-width:130px;flex:0 1 auto">
        <button ${x.A(p.retirer)} title="Retirer cette étape" style="flex:0 0 auto;border:none;background:none;color:var(--color-text-muted);font-size:11px;cursor:pointer;padding:0 2px">✕</button>
        ${sel(p.gauche, p.setGauche)}
        <span style="font-size:12px;color:var(--color-text-muted)">−</span>
        ${sel(p.droite, p.setDroite)}
      </div>
    </td>
    <td style="padding:9px 6px;text-align:right;white-space:nowrap;font-weight:500;color:var(--pkg-abricot)">${esc(p.pctT)}</td>
    <td style="padding:9px 6px;text-align:right;white-space:nowrap;color:var(--pkg-abricot)">${esc(p.montantT)}</td>
    <td style="padding:9px 6px;text-align:right;white-space:nowrap;font-weight:600;color:${p.col}">${esc(p.pct)}</td>
    <td style="padding:9px 6px;text-align:right;white-space:nowrap;font-weight:600;color:${p.col}">${esc(p.montant)}</td>
    <td style="padding:9px 6px;text-align:right;white-space:nowrap;color:var(--color-text-muted)">${esc(p.montantR)}</td>
    <td style="padding:9px 0 9px 6px"></td>
  </tr>`;
}

/* --- Campagnes marketing · les trois écrans repris du module ----------------- */

/** Le bandeau commun quand les tables mar_* manquent ou chargent. */
function tplMktGarde(c){
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  if (c.mkChargement) { return `<div data-screen="mkt" style="${carte};padding:22px;font-size:12.5px;color:var(--color-text-muted)">Lecture des campagnes…</div>`; }
  if (c.mkIndispo) { return `<div data-screen="mkt" style="${carte};padding:22px;font-size:12.5px;line-height:1.6"><span style="font-size:10px;font-weight:500;padding:2px 9px;border-radius:999px;background:#FBEFE0;color:var(--color-on-abricot);border:1px solid #E8C9A0">indisponible</span><div style="margin-top:8px">${c.mkRaison}</div></div>`; }
  return '';
}

/** Le formulaire d'une campagne — création et correction, même carte. */
function tplMktForm(c, x){
  const { esc } = x;
  const f = c.mkEdit;
  const k = 'font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:.08em;font-weight:500;display:block;margin-bottom:4px';
  const inp = 'width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:8px;height:33px;padding:0 10px;font-family:var(--font-ui);font-size:12.5px';
  const puce = b => `<button ${x.A(b.pick)} style="border-radius:999px;height:29px;padding:0 12px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer;${b.on ? 'border:none;background:var(--color-primary);color:#fff' : 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted)'}">${esc(b.nom)}</button>`;
  return `
  <div style="background:var(--color-surface);border:1px solid var(--color-primary);border-radius:12px;padding:15px 17px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px">
      <span style="font-size:13.5px;font-weight:500">${esc(f.titre)}</span>
      <button ${x.A(f.fermer)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted);border-radius:999px;width:26px;height:26px;font-size:13px;cursor:pointer">✕</button>
    </div>
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:11px 13px">
      <div><span style="${k}">Nom</span><input id="mk-nom" value="${esc(f.nom)}" ${x.I(f.setNom)} placeholder="Saint-Nicolas, Rentrée…" style="${inp}"></div>
      <div><span style="${k}">Type</span><select ${x.C(f.setType)} style="${inp}">
        <option value=""${f.types.some(t => t.on) ? '' : ' selected'}>— sans type —</option>
        ${f.types.map(t => `<option value="${esc(t.id)}"${t.on ? ' selected' : ''}>${esc(t.nom)}</option>`).join('')}</select></div>
      <div><span style="${k}">Budget (€)</span><input id="mk-bud" value="${esc(String(f.budget))}" ${x.I(f.setBudget)} inputmode="decimal" placeholder="0" style="${inp};text-align:right"></div>
      <div><span style="${k}">Du</span><input type="date" value="${esc(f.debut)}" ${x.C(f.setDebut)} style="${inp}"></div>
      <div><span style="${k}">Au</span><input type="date" value="${esc(f.fin)}" ${x.C(f.setFin)} style="${inp}"></div>
      <div><span style="${k}">Périmètre</span><div style="display:flex;gap:6px;padding-top:2px">${f.scopes.map(puce).join('')}</div></div>
    </div>
    <div style="margin-top:11px"><span style="${k}">Statut</span>
      <div style="display:flex;gap:6px;flex-wrap:wrap">${f.statuts.map(puce).join('')}</div></div>
    ${f.err ? `<div style="margin-top:11px;padding:9px 12px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:12px">${esc(f.err)}</div>` : ''}
    <div style="display:flex;justify-content:flex-end;gap:9px;margin-top:13px">
      <button ${x.A(f.fermer)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text);border-radius:999px;height:33px;padding:0 15px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">Annuler</button>
      <button ${x.A(f.envoyer)} style="border:none;background:var(--color-primary);color:#fff;border-radius:999px;height:33px;padding:0 18px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:${f.busy ? 'wait' : 'pointer'}">${f.busy ? 'Enregistrement…' : (f.id ? 'Corriger' : 'Créer la campagne')}</button>
    </div>
  </div>`;
}

function tplMktCalendrier(c, x){
  const { esc } = x;
  const garde = tplMktGarde(c);
  if (garde) { return garde; }
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  return `
  <div data-screen="mktcal" style="display:flex;flex-direction:column;gap:14px">
    ${c.mkEdit ? tplMktForm(c, x) : ''}
    <div style="${carte};overflow:hidden">
      <div style="padding:13px 17px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;gap:10px">
        <span style="font-size:13px;font-weight:500">Calendrier ${esc(c.mkAnnee)}</span>
        <button ${x.A(c.mkAnneePrec)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);border-radius:7px;width:26px;height:26px;cursor:pointer;color:var(--color-text)">‹</button>
        <button ${x.A(c.mkAnneeSuiv)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);border-radius:7px;width:26px;height:26px;cursor:pointer;color:var(--color-text)">›</button>
        <div style="flex:1"></div>
        <span style="font-size:11px;color:var(--color-text-muted)">Cliquez une barre pour corriger la campagne</span>
      </div>
      ${c.mkCalVide ? `<div style="padding:24px 17px;font-size:12.5px;color:var(--color-text-muted)">Aucune campagne datée sur ${esc(c.mkAnnee)}.</div>` : `
      <div style="overflow-x:auto;padding:14px 17px">
        <div style="display:grid;grid-template-columns:210px repeat(12,minmax(52px,1fr));gap:3px 0;min-width:880px">
          <div></div>
          ${c.mkMois.map(m2 => `<div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);text-align:center;padding-bottom:7px;border-bottom:0.5px solid var(--color-border-tertiary)">${esc(m2)}</div>`).join('')}
          ${c.mkCalLignes.map(l => `
            <div style="padding:9px 10px 9px 0;font-size:12px;border-bottom:0.5px solid var(--color-border-tertiary)">
              <div style="font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(l.nom)}">${esc(l.nom)}</div>
              <div style="font-size:10.5px;color:var(--color-text-muted)">${esc(l.type)} · ${esc(l.debut)} → ${esc(l.fin)}</div>
            </div>
            <div style="grid-column:2 / span 12;display:grid;grid-template-columns:repeat(12,1fr);align-items:center;border-bottom:0.5px solid var(--color-border-tertiary)">
              <button ${x.A(l.ouvrir)} title="${esc(l.nom)} — ${esc(l.statutNom)}" style="grid-column:${l.col1} / ${l.col2};height:22px;border:none;border-radius:999px;cursor:pointer;background:${l.couleur}33;border-left:4px solid ${l.couleur};display:flex;align-items:center;padding:0 9px;overflow:hidden">
                <span style="font-size:10px;font-weight:600;padding:1px 7px;border-radius:999px;background:${l.statutFond};color:${l.statutTexte};white-space:nowrap">${esc(l.statutNom)}</span>
              </button>
            </div>`).join('')}
        </div>
      </div>`}
    </div>
  </div>`;
}

function tplMktCampagnes(c, x){
  const { esc } = x;
  const garde = tplMktGarde(c);
  if (garde) { return garde; }
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const th = 'text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 10px 7px';
  const td = 'padding:9px 10px;border-top:0.5px solid var(--color-border-tertiary);font-size:12.5px';
  return `
  <div data-screen="mktcamp" style="display:flex;flex-direction:column;gap:14px">
    <div style="display:flex;justify-content:flex-end;gap:9px;align-items:center">
      <span style="font-size:11px;color:var(--color-text-muted);margin-right:2px">Toute campagne se crée dans l’assistant complet (cadrage, offre, objectifs, prix, photos, budget, communication, planning, leads) : elle apparaît ici, où elle se corrige et se reprend.</span>
      <button ${x.A(c.mkAssistant)} class="hv-fade" style="border:none;cursor:pointer;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:9px 18px;border-radius:999px">+ Nouvelle campagne</button>
    </div>
    ${c.mkEdit ? tplMktForm(c, x) : ''}
    ${c.mkAttente && c.mkAttente.length ? `
    <div>
      <div style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--color-text-muted);font-weight:500;margin-bottom:8px">En attente — à finir ou à lancer</div>
      <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px">
        ${c.mkAttente.map(v => `
        <div ${x.A(v.ouvrir)} class="hv-fade" style="${carte};padding:0;cursor:pointer;text-align:left;font-family:var(--font-ui);overflow:hidden;display:flex;flex-direction:column;aspect-ratio:1/1">
          <div style="flex:1;min-height:0;background:${v.couleur}1f ${v.image ? `url('${esc(v.image)}') center/cover no-repeat` : ''};border-bottom:0.5px solid var(--color-border-tertiary);position:relative">
            ${v.image ? '' : `<span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:600;color:${v.couleur}">${esc((v.nom || '?').trim().charAt(0).toUpperCase())}</span>`}
            <span style="position:absolute;top:8px;left:8px;font-size:10px;font-weight:600;padding:2px 8px;border-radius:999px;background:${v.statutFond};color:${v.statutTexte}">${esc(v.statutNom)}</span>
          </div>
          <div style="padding:11px 13px;display:flex;flex-direction:column;gap:6px">
            <span style="font-size:13px;font-weight:600;color:var(--color-text);line-height:1.35">${esc(v.nom)}</span>
            <span style="display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--color-text-muted)"><span style="width:8px;height:8px;border-radius:2px;background:${v.couleur}"></span>${esc(v.type)}</span>
            <span><span style="font-size:10.5px;font-weight:600;padding:2px 9px;border-radius:999px;background:${v.couleur}1f;color:${v.couleur}">${esc(v.levier)}</span></span>
            <span style="font-size:10.5px;color:var(--color-text-muted)">${esc(v.periode)}</span>
          </div>
        </div>`).join('')}
      </div>
    </div>` : ''}
    <div style="${carte};overflow:hidden">
      ${c.mkVide ? `<div style="padding:24px 17px;font-size:12.5px;color:var(--color-text-muted)">Aucune campagne. Créez la première — elle apparaîtra aussi au calendrier.</div>` : `
      <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:820px">
        <thead><tr>
          <th style="${th};padding-left:17px">Campagne</th><th style="${th}">Type</th>
          <th style="${th}">Périmètre</th><th style="${th}">Statut</th><th style="${th}">Période</th>
          <th style="${th};text-align:right">Budget</th><th style="${th};text-align:right">Dépensé</th>
          <th style="${th};text-align:right">Boutiques</th><th style="${th};padding-right:17px"></th>
        </tr></thead>
        <tbody>${c.mkCampagnes.map(l => `<tr>
          <td style="${td};padding-left:17px"><button ${x.A(l.editer)} class="hv-line" style="border:none;background:none;padding:0;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:500;color:var(--color-text);text-align:left">${esc(l.nom)}</button></td>
          <td style="${td}"><span style="display:inline-flex;align-items:center;gap:6px"><span style="width:8px;height:8px;border-radius:2px;background:${l.typeCouleur}"></span>${esc(l.type)}</span></td>
          <td style="${td};color:var(--color-text-muted)">${esc(l.scope)}</td>
          <td style="${td}"><span style="font-size:10.5px;font-weight:600;padding:2px 9px;border-radius:999px;background:${l.statutFond};color:${l.statutTexte}">${esc(l.statutNom)}</span></td>
          <td style="${td};white-space:nowrap;color:var(--color-text-muted)">${esc(l.periode)}</td>
          <td style="${td};text-align:right;font-variant-numeric:tabular-nums">${esc(l.budget)}</td>
          <td style="${td};text-align:right;font-variant-numeric:tabular-nums;color:var(--color-text-muted)">${esc(l.depense)}</td>
          <td style="${td};text-align:right;color:var(--color-text-muted)">${l.nBoutiques || '—'}</td>
          <td style="${td};padding-right:17px;text-align:right;white-space:nowrap">
            ${l.reprendre ? `<button ${x.A(l.reprendre)} title="Finir ce brouillon dans l’assistant" style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-primary);border-radius:7px;padding:3px 9px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer;margin-right:4px">Reprendre</button>` : ''}
            <button ${x.A(l.editer)} style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text-muted);border-radius:7px;padding:3px 9px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer">Corriger</button>
            <button ${x.A(l.supprimer)} title="Supprimer" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:pointer;padding:0 2px;margin-left:4px">✕</button>
          </td>
        </tr>`).join('')}</tbody>
      </table></div>`}
    </div>
  </div>`;
}

function tplMktTypes(c, x){
  const { esc } = x;
  const garde = tplMktGarde(c);
  if (garde) { return garde; }
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const k = 'font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:.08em;font-weight:500;display:block;margin-bottom:4px';
  const inp = 'width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:8px;height:33px;padding:0 10px;font-family:var(--font-ui);font-size:12.5px';
  const th = 'text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 8px 7px';
  const svg = (path, taille, couleur) => `<svg viewBox="0 0 24 24" width="${taille}" height="${taille}" fill="none" stroke="${couleur}" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="${path}"/></svg>`;
  const f = c.mkTypeForm;
  return `
  <div data-screen="mkttypes" style="display:flex;flex-direction:column;gap:14px">
    <div style="${carte};padding:15px 17px">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:6px">
        <span style="font-size:13px;font-weight:500">Types de campagne</span>
        <button ${x.A(c.mkTypeNouveau)} style="border:none;background:var(--color-primary);color:#fff;border-radius:999px;height:31px;padding:0 14px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">+ Nouveau type</button>
      </div>
      <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:12px;text-wrap:pretty">Chaque type ouvre la première étape de l’assistant : sa carte y porte son icône, sa couleur et son levier. L’ordre ci-dessous est celui de la grille. Un type porté par des campagnes ne se supprime pas — il se désactive, et l’historique garde son étiquette.</div>

      ${f ? `
      <div style="border:1px solid var(--color-primary);border-radius:10px;padding:14px 16px;margin-bottom:14px">
        <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:12px">
          <span style="font-size:13px;font-weight:500">${esc(f.titre)}</span>
          ${f.edition ? `<span style="font-size:11px;color:var(--color-text-muted)">code <i style="font-style:normal;font-family:var(--font-mono,monospace)">${esc(f.code)}</i> — non modifiable</span>` : ''}
        </div>
        ${f.err ? `<div style="margin-bottom:10px;padding:8px 11px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:12px;font-weight:500">${esc(f.err)}</div>` : ''}
        <div style="display:grid;grid-template-columns:2fr 120px;gap:12px">
          <div><span style="${k}">Nom</span><input id="mkt-nom" value="${esc(f.nom)}" ${x.I(f.setNom)} placeholder="Fêtes, Ouverture, Fidélité…" style="${inp}"></div>
          <div><span style="${k}">Couleur</span><input type="color" value="${esc(f.couleur)}" ${x.C(f.setCouleur)} style="${inp};padding:3px 5px"></div>
        </div>
        <div style="margin-top:11px"><span style="${k}">Description</span>
          <textarea id="mkt-desc" rows="2" ${x.I(f.setDescription)} placeholder="Ce que ce type recouvre, pour celui qui crée la campagne." style="${inp};height:auto;padding:8px 10px;line-height:1.5;resize:vertical">${esc(f.description)}</textarea>
          <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:3px">${esc(f.nbCar)}</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:11px">
          <div><span style="${k}">Levier</span>
            <select ${x.C(f.setLevier)} style="${inp}">${opts(f.leviers, f.levierId, o => o.val, o => esc(o.nom))}</select>
          </div>
          <div><span style="${k}">Badge levier</span><input id="mkt-badge" value="${esc(f.badge)}" ${x.I(f.setBadge)} placeholder="Affiché à la place du levier" style="${inp}"></div>
          <div><span style="${k}">KPI attendu</span><input id="mkt-kpi" value="${esc(f.kpi)}" ${x.I(f.setKpi)} placeholder="Nouveaux clients, tickets/jour" style="${inp}"></div>
        </div>
        <div style="margin-top:12px">
          <span style="${k}">Icône — ${esc(f.iconeNom)}</span>
          <div style="display:flex;flex-wrap:wrap;gap:6px">
            ${f.icones.map(ic => `<button ${x.A(ic.choisir)} title="${esc(ic.nom)}" style="${ic.st}">${svg(ic.path, 19, ic.choisi ? 'var(--color-primary)' : 'var(--color-text-muted)')}</button>`).join('')}
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
          <button ${x.A(f.fermer)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text);border-radius:999px;height:30px;padding:0 13px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Annuler</button>
          <button ${x.A(f.envoyer)} style="border:none;background:var(--color-primary);color:#fff;border-radius:999px;height:30px;padding:0 15px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">${f.edition ? 'Enregistrer' : 'Créer le type'}</button>
        </div>
      </div>` : ''}

      <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:820px">
        <thead><tr>
          <th style="${th};padding-left:0">Type</th>
          <th style="${th}">Levier</th>
          <th style="${th}">KPI attendu</th>
          <th style="${th};text-align:right">Campagnes</th>
          <th style="${th};padding-right:0"></th>
        </tr></thead>
        <tbody>${c.mkTypes.map(t => `<tr style="border-top:0.5px solid var(--color-border-tertiary);${t.actif ? '' : 'opacity:.5'}">
          <td style="padding:8px 8px 8px 0">
            <div style="display:flex;align-items:center;gap:9px">
              <span style="width:26px;height:26px;border-radius:8px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;background:${t.couleur}1f">
                ${t.iconePath ? svg(t.iconePath, 15, t.couleur) : `<i style="width:9px;height:9px;border-radius:3px;background:${t.couleur}"></i>`}
              </span>
              <span style="min-width:0">
                <span style="font-size:12.5px;font-weight:500;display:block">${esc(t.nom)}</span>
                ${t.description ? `<span style="font-size:11px;color:var(--color-text-muted);display:block;line-height:1.35;text-wrap:pretty">${esc(t.description)}</span>` : ''}
              </span>
            </div>
          </td>
          <td style="padding:8px">${t.levier ? `<span style="${t.levierSt}">${esc(t.levier)}</span>` : `<span style="font-size:11.5px;color:var(--color-text-muted)">—</span>`}</td>
          <td style="padding:8px;font-size:11.5px;color:var(--color-text-muted)">${esc(t.kpi)}</td>
          <td style="padding:8px;text-align:right;font-size:12px;color:var(--color-text-muted)">${t.nCampagnes || '—'}</td>
          <td style="padding:8px 0;text-align:right;white-space:nowrap">
            <button ${x.A(t.monter)} title="Monter" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:${t.premier ? 'default' : 'pointer'};padding:0 3px;${t.premier ? 'opacity:.3' : ''}">▲</button>
            <button ${x.A(t.descendre)} title="Descendre" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:${t.dernier ? 'default' : 'pointer'};padding:0 3px;${t.dernier ? 'opacity:.3' : ''}">▼</button>
            <button ${x.A(t.editer)} style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text);border-radius:999px;padding:2px 10px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer;margin-left:6px">Modifier</button>
            <button ${x.A(t.basculer)} style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text-muted);border-radius:999px;padding:2px 10px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer;margin-left:3px">${t.actif ? 'Désactiver' : 'Réactiver'}</button>
            <button ${x.A(t.supprimer)} title="Supprimer" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:pointer;padding:0 2px;margin-left:3px">✕</button>
          </td>
        </tr>`).join('')}</tbody>
      </table></div>
    </div>
  </div>`;
}

/* --- Réputation digitale · ce que Google dit de chaque magasin ----------------
   Un bandeau réseau, puis une carte par magasin, les plus mal notés d'abord :
   c'est là que la décision se prend. Chaque carte porte son propre effort — le
   chiffre réseau ne se distribue pas, il donne l'ordre de grandeur. */
/* Résultat du jour — le réseau en une table, le magasin en un clic.
   La table donne la journée de tout le monde ; ouvrir une ligne déplie
   dessous la cascade du magasin, sa ventilation par catégorie et la place du
   jour dans son mois. Sans sélection, la page garde les petites ventilations :
   elles montrent où cliquer. */
function tplResultatJour(c, x){
  const { esc } = x;
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const cap = 'font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.07em;color:var(--color-text-muted)';
  const num = 'font-variant-numeric:tabular-nums';
  if (c.rjChargement) {
    return `<div data-screen="resultatJour" style="padding:40px 0;font-size:13px;color:var(--color-text-muted)">Lecture du compte de résultat de la journée…</div>`;
  }
  if (c.rjErreur) {
    return `<div data-screen="resultatJour" style="${carte};padding:18px;font-size:12.5px;color:var(--color-text-muted);text-wrap:pretty">${esc(c.rjErreurTxt)}</div>`;
  }
  const ent = ['Magasin', 'CA du jour', c.rjRefEntete, 'Tickets', 'Panier', 'Coût matière', 'Marge brute',
    'Main-d’œuvre', 'Frais gén.', 'Résultat'];
  const bord = 'border-top:0.5px solid var(--color-border-tertiary)';
  // Une cellule chiffrée : la valeur, et sous elle son poids dans le CA.
  const cel = (v, coul, sous, fort, titre, sousCoul) => `<td${titre ? ` title="${esc(titre)}"` : ''} style="padding:9px 10px;${bord};text-align:right;${num}${fort ? ';font-weight:500' : ''}${coul ? ';color:' + coul : ''}">${esc(v)}${sous ? `<div style="font-size:10px;color:${sousCoul || 'var(--color-text-muted)'};font-weight:400">${esc(sous)}</div>` : ''}</td>`;
  // Le (i) d'une notion qui a besoin d'être expliquée : au survol, la phrase
  // entière — la place manque dans un en-tête de colonne.
  const aide = txt => txt ? ` <span title="${esc(txt)}" style="display:inline-flex;align-items:center;justify-content:center;width:13px;height:13px;border-radius:50%;border:1px solid var(--color-border-secondary);color:var(--color-text-muted);font-size:9px;font-style:italic;font-weight:600;cursor:help;vertical-align:1px">i</span>` : '';
  const treemap = (tuiles, hauteur) => `
    <div style="position:relative;width:100%;height:${hauteur}px">
      ${tuiles.map(t => `<div style="${t.st}">
        ${t.gros ? `<div style="font-size:12px;font-weight:500;line-height:1.15">${esc(t.nom)}</div>
          <div style="font-family:var(--font-display);font-size:14px;margin-top:3px;${num}">${esc(t.ca)}</div>
          <div style="font-size:10.5px;opacity:.92;${num}">${esc(t.part)} du CA${t.delta ? ' · ' + esc(t.delta) + ' vs réf.' : ''}</div>`
        : (t.moyen ? `<div style="font-size:10.5px;font-weight:500;line-height:1.1">${esc(t.nom)}</div>
          <div style="font-size:10px;opacity:.92;white-space:nowrap;${num}">${esc(t.part)}${t.delta ? ' · ' + esc(t.delta) : ''}</div>`
        : (t.minuscule ? '' : `<div style="font-size:9.5px;line-height:1.05;opacity:.95">${esc(t.nom)}</div>`))}
      </div>`).join('')}
    </div>`;
  const echelle = `<div style="display:flex;gap:11px;flex-wrap:wrap;margin-top:7px;font-size:10px;color:var(--color-text-muted)">
      ${c.rjEchelle.map(e => `<span><i style="display:inline-block;width:9px;height:9px;border-radius:2px;background:${e.coul};vertical-align:-1px"></i> ${esc(e.label)}</span>`).join('')}
    </div>`;

  return `
  <div data-screen="resultatJour" style="display:flex;flex-direction:column;gap:14px">

    <div style="${carte};padding:17px 19px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:11px">
        <div>
          <div style="${cap}">Résultat du jour, magasin par magasin</div>
          <div style="font-family:var(--font-display);font-size:17px;margin-top:3px">${esc(c.rjDateTxt)}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">${esc(c.rjSeuilsTxt)}${aide(c.rjSeuilsAide)}</div>
        </div>
        <div style="display:flex;align-items:flex-start;gap:22px">
          <div style="display:flex;align-items:center;gap:7px">
            <button ${x.A(c.rjPrec)} title="Jour précédent" class="hv-bg" style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);border-radius:8px;height:31px;width:31px;font-size:13px;cursor:pointer;color:var(--color-text)">◂</button>
            <button ${x.A(c.rjSuiv)} title="Jour suivant" class="hv-bg" style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);border-radius:8px;height:31px;width:31px;font-size:13px;cursor:${c.rjSuiv ? 'pointer' : 'default'};color:var(--color-text);opacity:${c.rjSuiv ? '1' : '.35'}">▸</button>
            <button ${x.A(c.rjRefresh)} title="Relire la journée" class="hv-bg" style="border:0.5px solid var(--color-border-secondary);background:transparent;border-radius:8px;height:31px;width:31px;font-size:13px;cursor:pointer;color:var(--color-text-muted);margin-left:4px">⟳</button>
            ${c.rjMaj ? `<span style="font-size:10.5px;color:var(--color-text-muted);margin-left:2px">${esc(c.rjMaj)}</span>` : ''}
            ${c.rjAuj ? `<button ${x.A(c.rjAuj)} class="hv-line" style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);border-radius:999px;height:31px;padding:0 13px;font-family:var(--font-ui);font-size:11.5px;cursor:pointer">Aujourd’hui</button>` : ''}
          </div>
          <div style="text-align:right">
            <div style="${cap}">CA réseau</div>
            <div style="font-family:var(--font-display);font-size:25px;line-height:1.1;${num}">${esc(c.rjReseau.ca)}</div>
            <div style="font-size:11px;color:var(--color-text-muted);${num}">${esc(c.rjReseau.tickets)} tickets · panier ${esc(c.rjReseau.panier)}</div>
          </div>
          <div style="text-align:right">
            <div style="${cap}">Résultat réseau</div>
            <div style="font-family:var(--font-display);font-size:25px;line-height:1.1;color:${c.rjReseau.netCoul};${num}">${esc(c.rjReseau.net)}</div>
            <div style="font-size:11px;color:var(--color-text-muted);${num}">${esc(c.rjReseau.netPct)} du CA</div>
          </div>
        </div>
      </div>

      <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:940px">
        <tr>${ent.map((e, i) => `<th style="text-align:${i === 0 ? 'left' : 'right'};padding:0 10px 7px;${cap}">${esc(e)}${i === 2 ? aide(c.rjRefAide) : ''}</th>`).join('')}</tr>
        ${c.rjLignes.map(l => `
          <tr ${x.A(l.ouvrir)} class="hv-bg" style="${l.st}">
            <td style="padding:9px 10px;${bord}">
              <div style="font-weight:500"><span style="font-size:9px;color:var(--color-text-muted);margin-right:5px">${l.chevron}</span>${esc(l.nom)}</div>
              <div style="font-size:10px;color:var(--color-text-muted);padding-left:14px">${esc(l.sousTitre)}</div>
            </td>
            ${l.ouvert
              ? cel(l.ca, '', '', true) + cel(l.delta, l.deltaCoul, '', false, l.deltaTitre)
                + cel(l.tickets, '', l.ticketsDelta, false, '', l.ticketsCoul) + cel(l.panier, '', '', false)
                + cel(l.fc, l.fcCoul, l.fcPct, false)
                + cel(l.mb, '', l.mbPct, false)
                + cel(l.labour, l.labourCoul, l.labourPct + (l.labourReparti ? ' ·  réparti' : ''), false, l.labourTitre)
                + cel(l.oh, l.ohCoul, l.ohPct + ' · réparti', false)
                + cel(l.net, l.netCoul, l.netPct, true)
              : `<td colspan="9" style="padding:9px 10px;${bord};text-align:right;font-size:11.5px;color:var(--color-text-muted)">aucun chiffre pour cette journée</td>`}
          </tr>`).join('')}
        <tr style="background:var(--color-background-secondary)">
          <td style="padding:10px;border-top:1px solid var(--color-border-secondary);font-weight:500">Réseau
            <span style="font-size:10px;color:var(--color-text-muted);font-weight:400">${esc(c.rjReseau.magasins)} magasin(s) ouvert(s)</span></td>
          ${cel(c.rjReseau.ca, '', '', true)}${cel(c.rjReseau.delta, c.rjReseau.deltaCoul, '', false, c.rjReseau.deltaTitre)}
          ${cel(c.rjReseau.tickets, '', '', false)}${cel(c.rjReseau.panier, '', '', false)}
          ${cel(c.rjReseau.fc, c.rjReseau.fcCoul, c.rjReseau.fcPct, false)}
          ${cel(c.rjReseau.mb, '', c.rjReseau.mbPct, false)}
          ${cel(c.rjReseau.labour, c.rjReseau.labourCoul, c.rjReseau.labourPct, false)}
          ${cel(c.rjReseau.oh, c.rjReseau.ohCoul, c.rjReseau.ohPct, false)}
          ${cel(c.rjReseau.net, c.rjReseau.netCoul, c.rjReseau.netPct, true)}
        </tr>
      </table>
      </div>
      <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:9px;line-height:1.5;text-wrap:pretty">${esc(c.rjNote)}</div>
    </div>

    ${c.rjDetail ? `
    <div style="${carte};padding:17px 19px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
        <div>
          <div style="${cap}">Compte de résultat du jour</div>
          <div style="font-family:var(--font-display);font-size:19px;margin-top:3px">${esc(c.rjDetail.nom)}</div>
        </div>
        <div style="display:flex;align-items:flex-start;gap:14px">
          <div style="text-align:right">
            <div style="font-family:var(--font-display);font-size:29px;line-height:1;${num}">${esc(c.rjDetail.ca)}</div>
            <div style="font-size:11.5px;color:${c.rjDetail.deltaCoul};${num}">${c.rjDetail.delta ? esc(c.rjDetail.delta) + ' ' : ''}${esc(c.rjDetail.deltaTxt)}${aide(c.rjDetail.refAide)}</div>
          </div>
          <button ${x.A(c.rjDetail.fermer)} title="Fermer" style="border:none;background:transparent;cursor:pointer;color:var(--color-text-muted);font-size:16px;line-height:1;padding:2px 6px">×</button>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1.02fr 1fr;gap:22px;margin-top:13px">
        <div>
          <table style="width:100%;border-collapse:collapse;font-size:12.5px">
            ${c.rjDetail.cascade.map(l => `
              <tr style="${l.fort ? 'font-weight:500' : ''}">
                <td style="padding:7px 0;${bord};width:150px">${esc(l.l)}${l.seuil ? `<div style="font-size:10px;color:var(--color-text-muted);font-weight:400">${esc(l.seuil)}</div>` : ''}</td>
                <td style="padding:7px 8px;${bord}">
                  <span style="display:block;height:8px;border-radius:999px;background:var(--color-background-secondary);overflow:hidden">
                    <i style="display:block;height:100%;width:${l.w}%;border-radius:999px;background:${l.coul}"></i></span></td>
                <td style="padding:7px 0;${bord};text-align:right;${num};width:96px">${esc(l.v)}</td>
                <td style="padding:7px 0 7px 12px;${bord};text-align:right;${num};width:64px;color:${l.coul}">${esc(l.p)}</td>
                <td style="padding:7px 0 7px 10px;${bord};text-align:right;${num};width:74px;font-weight:400;font-size:11px;color:${l.dCoul || 'var(--color-text-muted)'}">${esc(l.d)}</td>
              </tr>`).join('')}
          </table>
          ${c.rjDetail.motifNet ? `<div style="font-size:11px;color:var(--color-on-abricot);background:#FBEFE0;border:1px solid #E8C9A0;padding:6px 9px;border-radius:7px;margin-top:9px;line-height:1.45">${esc(c.rjDetail.motifNet)}</div>` : ''}
          <div style="margin-top:10px;font-size:10.5px;color:var(--color-text-muted);background:var(--color-background-secondary);border-radius:8px;padding:8px 10px;line-height:1.5;text-wrap:pretty">${esc(c.rjDetail.note)}</div>
        </div>
        <div>
          <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px">
            <div style="${cap}">Ventes par catégorie</div>
            <div style="font-size:10.5px;color:var(--color-text-muted)">${esc(c.rjDetail.catsLegende)}${aide(c.rjDetail.refAide)}</div>
          </div>
          ${c.rjDetail.sansCat
            ? `<div style="padding:26px 0;font-size:12px;color:var(--color-text-muted)">Le panel ne rend pas de ventilation par catégorie pour cette journée.</div>`
            : `<div style="margin-top:9px">${treemap(c.rjDetail.tuiles, 300)}</div>${echelle}`}
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1.35fr 1fr;gap:22px;margin-top:16px;border-top:0.5px solid var(--color-border-tertiary);padding-top:14px">
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px">
          ${c.rjDetail.kpis.map(k => `<div style="background:var(--color-background-secondary);border-radius:9px;padding:10px 12px">
            <div style="${cap}">${esc(k.l)}</div>
            <div style="font-family:var(--font-display);font-size:20px;line-height:1.1;margin-top:3px;${num}">${esc(k.v)}</div>
            <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:2px">${esc(k.s)}</div>
          </div>`).join('')}
        </div>
        <div>
          <div style="${cap};margin-bottom:7px">Le jour dans le mois</div>
          ${c.rjDetail.serieVide
            ? `<div style="font-size:12px;color:var(--color-text-muted)">Aucun jour ouvert ce mois-ci.</div>`
            : `<div style="display:flex;gap:3px;align-items:center">
              ${c.rjDetail.serie.map(j => `<div title="${esc(j.titre)}" style="flex:1;display:flex;flex-direction:column;height:64px;justify-content:center">
                <div style="height:29px;display:flex;align-items:flex-end"><i style="display:block;width:100%;height:${j.hautHaut}px;background:${j.coul};border-radius:3px 3px 0 0;${j.contour}"></i></div>
                <div style="height:1px;background:var(--color-border-secondary)"></div>
                <div style="height:29px"><i style="display:block;width:100%;height:${j.hautBas}px;background:${j.coul};border-radius:0 0 3px 3px;${j.contour}"></i></div>
              </div>`).join('')}
            </div>
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:10px;color:var(--color-text-muted);margin-top:4px">
              <span>${esc(c.rjDetail.serieDebut)}</span><span style="text-align:center;text-wrap:pretty">${esc(c.rjDetail.serieTxt)}</span><span>${esc(c.rjDetail.serieFin)}</span>
            </div>`}
        </div>
      </div>
    </div>` : (c.rjInvite ? `
    <div style="font-size:11.5px;color:var(--color-text-muted);padding:2px 2px 0;text-wrap:pretty">${esc(c.rjInvite)}</div>` : '')}

  </div>`;
}

function tplReputation(c, x){
  const { esc } = x;
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const cap = 'font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.07em;color:var(--color-text-muted)';
  if (c.repChargement) {
    return `<div data-screen="reputation" style="padding:40px 0;font-size:13px;color:var(--color-text-muted)">Lecture des avis…</div>`;
  }
  if (c.repErreur) {
    return `<div data-screen="reputation" style="${carte};padding:18px;font-size:12.5px;color:var(--color-text-muted)">${esc(c.repErreurTxt)}</div>`;
  }
  const etoiles = (list) => list.map(e => `<span style="${e.st}">★</span>`).join('');
  // Les cinq jauges, dessinées à l'identique pour le réseau et pour un magasin :
  // on compare les deux d'un coup d'œil, ce qui suppose la même échelle et la
  // même mise en page. `note` sert de repère à gauche, la part à droite.
  const jauges = (j, compact) => !j ? '' : `
    <div style="margin-top:${compact ? '10' : '12'}px">
      ${j.barres.map(b2 => `
        <div style="display:flex;align-items:center;gap:7px;margin-bottom:3px">
          <span style="width:26px;text-align:right;font-size:11px;color:var(--color-text-muted);font-variant-numeric:tabular-nums">${b2.note}<i style="${b2.etoileSt};font-style:normal">★</i></span>
          <span style="flex:1;min-width:0;height:7px;border-radius:999px;background:var(--color-background-secondary);overflow:hidden">
            <span style="display:block;${b2.jaugeSt}"></span>
          </span>
          <span style="width:34px;text-align:right;font-size:11px;font-variant-numeric:tabular-nums">${b2.n}</span>
          <span style="width:34px;text-align:right;font-size:10.5px;color:var(--color-text-muted);font-variant-numeric:tabular-nums">${b2.pct}</span>
        </div>`).join('')}
      <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:6px;line-height:1.4;text-wrap:pretty">${esc(j.echantillon)}</div>
    </div>`;
  return `
  <div data-screen="reputation" style="display:flex;flex-direction:column;gap:16px">

    <div style="${carte};padding:18px 20px;display:grid;grid-template-columns:auto 1fr auto;gap:26px;align-items:center">
      <div>
        <div style="${cap};margin-bottom:6px">Moyenne réseau</div>
        <div style="display:flex;align-items:flex-end;gap:10px">
          <span style="${c.repMoyenneSt}">${c.repMoyenne}</span>
          <span style="display:inline-flex;gap:1px;padding-bottom:3px">${etoiles(c.repEtoilesReseau)}</span>
        </div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:5px">${esc(c.repAvis)}</div>
        <!-- Répartition par étoiles : cinq jauges, la plus longue étant la plus
             fréquente. Les pourcentages portent sur les avis LUS, et la ligne
             sous les jauges le dit — pas sur le total de la fiche Google. -->
        <div style="min-width:230px">${jauges({ barres: c.repBarres, echantillon: c.repEchantillon }, false)}</div>
      </div>
      <div style="border-left:0.5px solid var(--color-border-tertiary);padding-left:26px">
        <div style="${cap};margin-bottom:6px">Cible ${c.repCible}</div>
        <div style="font-size:13px;font-weight:500">${esc(c.repSousCibleTxt)}</div>
        <div style="font-size:12.5px;margin-top:7px;font-weight:${c.repEffortFort ? '500' : '400'};color:${c.repEffortFort ? '#8D1D2C' : 'var(--color-text-muted)'}">${esc(c.repEffort)}</div>
      </div>
      <div style="text-align:right;min-width:210px">
        <div style="${cap};margin-bottom:6px">Connecteur Google</div>
        ${c.repConfigure
          ? `<div style="font-size:11.5px;color:var(--color-text-muted);line-height:1.5">${c.repRaccordes} fiche${c.repRaccordes > 1 ? 's' : ''} raccordée${c.repRaccordes > 1 ? 's' : ''}<br>${esc(c.repSynchroTxt)}</div>
             <button ${x.A(c.repSync)} class="hv-fade" style="margin-top:8px;border:none;background:var(--color-primary);color:#fff;border-radius:999px;height:29px;padding:0 14px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer;${c.repBusy ? 'opacity:.6;cursor:default' : ''}">${c.repBusy ? 'Synchronisation…' : 'Synchroniser'}</button>`
          : `<div style="font-size:11.5px;color:#8D1D2C;font-weight:500;line-height:1.5">Aucune clé Google</div>
             <div style="font-size:11px;color:var(--color-text-muted);margin-top:4px;line-height:1.45">Renseignez-la dans Paramètres pour rapatrier les notes et les avis.</div>`}
      </div>
    </div>

    ${c.repVide ? `<div style="${carte};padding:18px;font-size:12.5px;color:var(--color-text-muted);text-wrap:pretty">
      Aucun avis n’est encore remonté. Les notes et les avis viennent des fiches Google des magasins ; le raccordement des fiches n’est pas branché — les tables restent vides jusque-là.
    </div>` : ''}

    <!-- Toutes les tuiles à la même taille. Un alignement sur le début laissait
         chaque carte se dimensionner sur ses avis : deux magasins côte à côte
         n'avaient ni la même hauteur, ni leurs jauges à la même ligne, et l'œil
         ne pouvait plus les comparer sans les relire. Des rangées d'une même
         fraction les alignent aussi entre elles ; le blanc qui reste en bas
         d'une carte courte coûte moins que la comparaison qu'on perdait.
         (Pas d'accent grave dans ce commentaire : on est dans un littéral de
         gabarit, il refermerait la chaîne.) -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(430px,1fr));gap:14px;align-items:stretch;grid-auto-rows:1fr">
      ${c.repMagasins.map(m => `
        <div style="${carte};padding:15px 17px;display:flex;flex-direction:column">
          <div style="display:flex;align-items:flex-start;gap:12px">
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span style="font-size:13.5px;font-weight:500">${esc(m.nom)}</span>
                <span style="${m.badgeSt}">${esc(m.badge)}</span>
              </div>
              <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">
                ${esc(m.avis)}${m.synchro ? ' · ' + esc(m.synchro) : ''}
                ${m.hasUrl ? ` · <a href="${esc(m.url)}" target="_blank" rel="noopener" style="color:var(--color-primary);text-decoration:none">fiche Google ↗</a>` : ''}
                ${m.raccorde ? ` · <button ${x.A(m.detacher)} class="hv-line" style="border:none;background:none;padding:0;color:var(--color-text-muted);font-family:var(--font-ui);font-size:11.5px;cursor:pointer">détacher</button>` : ''}
              </div>
            </div>
            <div style="text-align:right;flex:0 0 auto">
              <div style="${m.noteSt}">${m.note}</div>
              <div style="display:inline-flex;gap:1px;margin-top:3px">${etoiles(m.etoiles)}</div>
            </div>
          </div>

          <div style="${m.effortSt}">${esc(m.effort)}</div>

          ${m.jauges && !m.jauges.vide ? jauges(m.jauges, true) : ''}

          ${!m.raccorde ? `
            <button ${x.A(m.ouvrirRech)} style="margin-top:9px;border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-primary);border-radius:999px;height:28px;padding:0 13px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Raccorder la fiche Google</button>` : ''}

          ${m.rechOuverte && c.repRech ? `
            <div style="margin-top:10px;border:1px solid var(--color-primary);border-radius:10px;padding:11px 13px">
              <div style="${cap};margin-bottom:7px">Chercher la fiche</div>
              <div style="display:flex;gap:7px">
                <input id="rep-q" value="${esc(c.repRech.q)}" ${x.I(c.repRech.setQ)} placeholder="Nom du magasin et ville" style="flex:1;min-width:0;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:8px;height:30px;padding:0 10px;font-family:var(--font-ui);font-size:12px">
                <button ${x.A(c.repRech.chercher)} style="border:none;background:var(--color-primary);color:#fff;border-radius:999px;height:30px;padding:0 14px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer;${c.repRech.busy ? 'opacity:.6;cursor:default' : ''}">${c.repRech.busy ? '…' : 'Chercher'}</button>
                <button ${x.A(c.repRech.fermer)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text);border-radius:999px;height:30px;padding:0 12px;font-family:var(--font-ui);font-size:11.5px;cursor:pointer">Fermer</button>
              </div>
              ${c.repRech.err ? `<div style="margin-top:8px;padding:7px 10px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:11.5px;font-weight:500">${esc(c.repRech.err)}</div>` : ''}
              ${c.repRech.aucun ? `<div style="margin-top:8px;font-size:11.5px;color:var(--color-text-muted)">Aucune fiche trouvée pour cette recherche.</div>` : ''}
              ${c.repRech.candidats.map(k => `
                <div ${x.A(k.choisir)} class="hv-bg" style="display:flex;align-items:center;gap:10px;padding:8px 0;border-top:0.5px solid var(--color-border-tertiary);cursor:pointer">
                  <div style="flex:1;min-width:0">
                    <div style="font-size:12px;font-weight:500">${esc(k.nom)}</div>
                    <div style="font-size:11px;color:var(--color-text-muted)">${esc(k.adresse)}</div>
                  </div>
                  <span style="font-size:11px;color:var(--color-text-muted);white-space:nowrap">${esc(k.note)}</span>
                </div>`).join('')}
            </div>` : ''}

          <div style="${cap};margin:14px 0 2px">5 derniers avis</div>
          ${m.vide ? `<div style="font-size:11.5px;color:var(--color-text-muted);padding:7px 0">Aucun avis rapatrié pour ce magasin.</div>` : ''}
          ${m.derniers.map(a => `
            <div style="padding:8px 0;border-top:0.5px solid var(--color-border-tertiary)">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span style="display:inline-flex;gap:1px">${etoiles(a.etoiles)}</span>
                <span style="font-size:11.5px;font-weight:500">${esc(a.auteur)}</span>
                <span style="font-size:11px;color:var(--color-text-muted)">${a.le}</span>
                ${a.repondu ? `<span style="${a.reponduSt};margin-left:auto">${a.reponduTxt}</span>` : ''}
              </div>
              <div style="${a.texteSt};margin-top:3px">${esc(a.texte)}</div>
            </div>`).join('')}
        </div>`).join('')}
    </div>
  </div>`;
}

/* --- Fonds · écrire une ligne du grand livre ---------------------------------
   La saisie tient dans une carte, pas dans une modale : on la remplit en
   regardant le grand livre qui est juste dessous, et on voit tout de suite si
   la ligne qu'on écrit existe déjà. */
function tplFondsForm(c, x){
  const { esc } = x;
  const f = c.foForm;
  const carte = 'background:var(--color-surface);border:1px solid var(--color-primary);border-radius:12px';
  const k = 'font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:.08em;font-weight:500;display:block;margin-bottom:4px';
  const inp = 'width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:8px;height:33px;padding:0 10px;font-family:var(--font-ui);font-size:12.5px';
  const sel = (nom, val, opts, vide) => `<select ${x.C(f.set(nom))} style="${inp}">
    <option value=""${val ? '' : ' selected'}>${esc(vide)}</option>
    ${opts.map(o => `<option value="${esc(o.id)}"${String(val) === String(o.id) ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}
  </select>`;
  return `
  <div style="${carte};padding:15px 17px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px">
      <span style="font-size:13.5px;font-weight:500">${esc(f.titre)}</span>
      <button ${x.A(f.fermer)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted);border-radius:999px;width:26px;height:26px;font-size:13px;cursor:pointer">✕</button>
    </div>
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px">
      ${f.sensBtns.map(b => `<button ${x.A(b.pick)} style="border-radius:999px;height:30px;padding:0 13px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;${b.on ? 'border:none;background:var(--color-primary);color:#fff' : 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted)'}">${esc(b.nom)}</button>`).join('')}
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px 13px">
      <div style="grid-column:span 2"><span style="${k}">Libellé</span>
        <input id="fo-lib" value="${esc(f.champs.libelle)}" ${x.I(f.set('libelle'))} placeholder="Ce que cette ligne finance ou apporte" style="${inp}"></div>
      <div><span style="${k}">Date</span>
        <input id="fo-date" type="date" value="${esc(f.champs.date)}" ${x.C(f.set('date'))} style="${inp}"></div>
      <div><span style="${k}">Montant</span>
        <input id="fo-mnt" value="${esc(f.champs.montant)}" ${x.I(f.set('montant'))} inputmode="decimal" placeholder="0,00" style="${inp};text-align:right;font-variant-numeric:tabular-nums"></div>
      <div><span style="${k}">Nature</span>
        <select ${x.C(f.set('source'))} style="${inp}">${(c.foSources || []).map(o => `<option value="${esc(o)}"${f.champs.source === o ? ' selected' : ''}>${esc(o)}</option>`).join('')}</select></div>
      <div><span style="${k}">Levier développé</span>${sel('levier', f.champs.levier, c.foLeviersOpts || [], 'aucun')}</div>
      <div><span style="${k}">Boutique</span>${sel('magasin', f.champs.magasin, c.foMagasinsOpts || [], 'tout le réseau')}</div>
      <div><span style="${k}">Campagne</span>${sel('campagne', f.champs.campagne, c.foCampagnesOpts || [], 'aucune')}</div>
      <div style="grid-column:span 2"><span style="${k}">Fournisseur</span>
        <input id="fo-four" list="fo-fournisseurs" value="${esc(f.champs.fournisseur)}" ${x.I(f.set('fournisseur'))} placeholder="choisissez ou tapez un nouveau nom" style="${inp}">
        <datalist id="fo-fournisseurs">${(c.foFournisseurs || []).map(n => `<option value="${esc(n)}"></option>`).join('')}</datalist>
        <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:4px">Repris de la centrale d’achat ; un nom inconnu y est ajouté à l’enregistrement.</div></div>
      <div style="grid-column:span 2"><span style="${k}">Pièce</span>
        <input id="fo-piece" value="${esc(f.champs.piece)}" ${x.I(f.set('piece'))} placeholder="n° de facture, bon de commande…" style="${inp}"></div>
    </div>
    ${f.avert ? `<div style="font-size:11.5px;color:var(--color-on-abricot);margin-top:11px;line-height:1.45">${esc(f.avert)}</div>` : ''}
    ${f.err ? `<div style="margin-top:11px;padding:9px 12px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:12px;line-height:1.45">${esc(f.err)}</div>` : ''}
    <div style="display:flex;justify-content:flex-end;gap:9px;margin-top:14px">
      <button ${x.A(f.fermer)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text);border-radius:999px;height:34px;padding:0 16px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">Annuler</button>
      <button ${x.A(f.envoyer)} style="border:none;background:var(--color-primary);color:#fff;border-radius:999px;height:34px;padding:0 20px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:${f.busy ? 'wait' : 'pointer'};opacity:${f.busy ? '.6' : '1'}">${f.busy ? 'Enregistrement…' : (f.id ? 'Corriger l’écriture' : 'Enregistrer au fonds')}</button>
    </div>
  </div>`;
}

/* --- Fonds · un frais qui revient -------------------------------------------- */
function tplFondsRecForm(c, x){
  const { esc } = x;
  const f = c.foRecForm;
  const carte = 'background:var(--color-surface);border:1px solid var(--color-border-secondary);border-radius:12px';
  const k = 'font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:.08em;font-weight:500;display:block;margin-bottom:4px';
  const inp = 'width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:8px;height:33px;padding:0 10px;font-family:var(--font-ui);font-size:12.5px';
  const puce = b => `<button ${x.A(b.pick)} style="border-radius:999px;height:30px;padding:0 13px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;${b.on ? 'border:none;background:var(--color-primary);color:#fff' : 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted)'}">${esc(b.nom)}</button>`;
  const sel = (nom, val, opts, vide) => `<select ${x.C(f.set(nom))} style="${inp}">
    <option value=""${val ? '' : ' selected'}>${esc(vide)}</option>
    ${opts.map(o => `<option value="${esc(o.id)}"${String(val) === String(o.id) ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}
  </select>`;
  return `
  <div style="${carte};padding:15px 17px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px">
      <span style="font-size:13.5px;font-weight:500">Un frais qui revient</span>
      <button ${x.A(f.fermer)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted);border-radius:999px;width:26px;height:26px;font-size:13px;cursor:pointer">✕</button>
    </div>
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px;align-items:center">
      ${f.sensBtns.map(puce).join('')}
      <span style="width:1px;height:22px;background:var(--color-border-tertiary);margin:4px 3px"></span>
      ${f.rythmes.map(puce).join('')}
      ${f.estSemaines ? `<span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--color-text-muted)">N =
        <input id="fr-sem" type="number" min="1" max="52" value="${esc(f.semaines)}" ${x.I(f.setSemaines)} style="width:56px;text-align:center;border:0.5px solid var(--color-border-secondary);border-radius:8px;height:30px;font-family:var(--font-ui);font-size:12.5px;background:var(--color-surface);color:var(--color-text)"> semaines</span>` : ''}
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px 13px">
      <div style="grid-column:span 2"><span style="${k}">Libellé</span>
        <input id="fr-lib" value="${esc(f.champs.libelle)}" ${x.I(f.set('libelle'))} placeholder="Abonnement, honoraires, redevance…" style="${inp}"></div>
      <div><span style="${k}">Montant d’une échéance</span>
        <input id="fr-mnt" value="${esc(f.champs.montant)}" ${x.I(f.set('montant'))} inputmode="decimal" placeholder="0,00" style="${inp};text-align:right;font-variant-numeric:tabular-nums"></div>
      <div><span style="${k}">Nature</span>
        <select ${x.C(f.set('source'))} style="${inp}">${(c.foSources || []).map(o => `<option value="${esc(o)}"${f.champs.source === o ? ' selected' : ''}>${esc(o)}</option>`).join('')}</select></div>
      <div><span style="${k}">Première échéance</span>
        <input id="fr-deb" type="date" value="${esc(f.champs.debut)}" ${x.C(f.set('debut'))} style="${inp}"></div>
      <div><span style="${k}">Dernière échéance</span>
        <input id="fr-fin" type="date" value="${esc(f.champs.fin)}" ${x.C(f.set('fin'))} style="${inp}"></div>
      <div><span style="${k}">Levier développé</span>${sel('levier', f.champs.levier, c.foLeviersOpts || [], 'aucun')}</div>
      <div><span style="${k}">Boutique</span>${sel('magasin', f.champs.magasin, c.foMagasinsOpts || [], 'tout le réseau')}</div>
    </div>
    ${f.apercu ? `<div style="background:var(--color-background-secondary);border-radius:10px;padding:11px 14px;margin-top:12px">
      <div style="font-size:12px;font-weight:600;color:var(--color-primary)">${f.apercu.n} échéance(s) seront écrites au fonds${f.apercu.total ? ' — ' + esc(f.apercu.total) + ' au total' : ''}${f.apercu.previsionModule ? ' (prévision — le module arrête les dates)' : ''}</div>
      <div style="margin-top:6px">${f.apercu.dates.map(d2 => `<span style="display:inline-block;background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:7px;padding:2px 9px;margin:2px 4px 0 0;font-size:11px;font-variant-numeric:tabular-nums">${esc(d2)}</span>`).join('')}${f.apercu.tronque ? `<span style="font-size:11px;color:var(--color-text-muted)"> … et ${f.apercu.tronque} de plus</span>` : ''}</div>
      <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:7px;line-height:1.45">Ces écritures comptent au budget du fonds dès l’enregistrement — les mois futurs se lisent au grand livre comme des sorties déjà engagées.</div>
    </div>` : ''}
    <div style="font-size:11px;color:var(--color-text-muted);margin-top:10px;line-height:1.45">Un frais récurrent est borné : les échéances sont écrites au grand livre dès l’enregistrement, du début à la fin. Sans fin, il ne se budgète pas.${f.estSemaines ? ' En semaines, les échéances s’écrivent comme des mouvements datés : elles se corrigent ensuite ligne à ligne.' : ''}</div>
    ${f.err ? `<div style="margin-top:11px;padding:9px 12px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:12px;line-height:1.45">${esc(f.err)}</div>` : ''}
    <div style="display:flex;justify-content:flex-end;gap:9px;margin-top:14px">
      <button ${x.A(f.fermer)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text);border-radius:999px;height:34px;padding:0 16px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">Annuler</button>
      <button ${x.A(f.envoyer)} style="border:none;background:var(--color-primary);color:#fff;border-radius:999px;height:34px;padding:0 20px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:${f.busy ? 'wait' : 'pointer'};opacity:${f.busy ? '.6' : '1'}">${f.busy ? 'Écriture…' : 'Écrire les échéances'}</button>
    </div>
  </div>`;
}

function tplFonds(c, x){
  const { esc } = x;
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const k = 'font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:.08em;font-weight:500';
  const th = 'text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 10px 7px';
  const td = 'padding:8px 10px;border-top:0.5px solid var(--color-border-tertiary);font-size:12.5px';
  const num = 'text-align:right;font-variant-numeric:tabular-nums';
  if (c.foChargement) {
    return `<div data-screen="fonds" style="${carte};padding:22px;font-size:12.5px;color:var(--color-text-muted)">Lecture du fonds…</div>`;
  }
  return `
  <div data-screen="fonds" style="display:flex;flex-direction:column;gap:14px">
    ${(c.foErreurs || []).length ? `<div style="${carte};padding:13px 16px;background:#FBEFE0;border-color:#E8C9A0">
      <div style="font-size:12.5px;font-weight:500;color:var(--color-on-abricot)">Le module marketing n\u2019a pas tout rendu</div>
      ${c.foErreurs.map(e => `<div style="font-size:11.5px;color:var(--color-on-abricot);margin-top:4px;line-height:1.45">${esc(e)}</div>`).join('')}
    </div>` : ''}

    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
      ${(c.foTuiles || []).map(t => `<div style="${carte};padding:15px 17px">
        <div style="${k}">${esc(t.k)}</div>
        <div style="font-size:25px;font-weight:500;line-height:1.05;margin-top:3px${t.col ? ';color:' + t.col : ''}">${esc(t.v)}</div>
        <div style="font-size:11px;color:var(--color-text-muted);margin-top:5px;line-height:1.4">${esc(t.aide)}</div>
      </div>`).join('')}
    </div>

    <!-- Tout se tient depuis le pilotage réseau : plus besoin d'ouvrir l'autre
         application pour écrire une ligne. Le module marketing reste le seul à
         TENIR le grand livre — le cockpit adresse ses routes, il ne recopie
         rien. -->
    <div style="${carte};padding:13px 17px;display:flex;align-items:center;gap:9px;flex-wrap:wrap">
      <span style="font-size:13px;font-weight:500;margin-right:4px">Écrire au fonds</span>
      <button ${x.A(() => c.foNouveau('IN'))} style="border:none;background:#2d7a3e;color:#fff;border-radius:9px;height:33px;padding:0 15px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">+ Alimentation</button>
      <button ${x.A(() => c.foNouveau('OUT'))} style="border:none;background:var(--color-primary);color:#fff;border-radius:9px;height:33px;padding:0 15px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">+ Dépense</button>
      <button ${x.A(c.foRecNouveau)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:9px;height:33px;padding:0 14px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">+ Frais récurrent</button>
      <div style="flex:1"></div>
      <span style="font-size:11px;color:var(--color-text-muted)">Les écritures partent dans le module qui tient le fonds ; elles y sont visibles aussitôt.</span>
    </div>

    ${c.foForm ? tplFondsForm(c, x) : ''}
    ${c.foRecForm ? tplFondsRecForm(c, x) : ''}

    <div style="${carte};overflow:hidden">
      <div style="padding:13px 17px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <span style="font-size:13px;font-weight:500">Frais récurrents</span>
        <span style="font-size:11px;color:var(--color-text-muted)">Un modèle, autant d’échéances écrites d’un coup</span>
      </div>
      ${c.foRecVide ? `<div style="padding:16px 17px;font-size:12.5px;color:var(--color-text-muted)">Aucun frais récurrent déclaré.</div>` : `
      <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:620px">
        <thead><tr>
          <th style="${th};padding-left:17px">Frais</th><th style="${th}">Rythme</th>
          <th style="${th}">Période</th><th style="${th}">Imputation</th>
          <th style="${th};${num}">Échéance</th><th style="${th};padding-right:17px"></th>
        </tr></thead>
        <tbody>${c.foRecurrences.map(r => `<tr>
          <td style="${td};padding-left:17px"><span style="font-weight:500">${esc(r.libelle)}</span><div style="font-size:10.5px;color:${r.col}">${esc(r.sens)}</div></td>
          <td style="${td};color:var(--color-text-muted)">${esc(r.rythme)}</td>
          <td style="${td};color:var(--color-text-muted);white-space:nowrap">${esc(r.periode)}</td>
          <td style="${td};color:var(--color-text-muted)">${esc(r.magasin)}</td>
          <td style="${td};${num};font-weight:500">${esc(r.montant)}</td>
          <td style="${td};padding-right:17px;text-align:right"><button ${x.A(r.supprimer)} title="Supprimer ce frais et ses échéances" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:pointer;padding:0 2px">✕</button></td>
        </tr>`).join('')}</tbody>
      </table></div>`}
    </div>

    <div style="${carte};padding:14px 17px 16px">
      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:11px">
        <span style="font-size:13px;font-weight:500">Par levier — où part l’argent du fonds</span>
        <span style="font-size:10.5px;color:var(--color-text-muted)">${c.foLevActifs} / ${c.foLevNb} portent une dépense</span>
      </div>
      ${(c.foLeviers || []).length ? `<div style="display:grid;grid-template-columns:repeat(${c.foLevNb},minmax(0,1fr));gap:9px">
        ${c.foLeviers.map(l => `<div style="border:0.5px solid var(--color-border-tertiary);border-radius:10px;padding:11px 12px;border-top:2.5px solid ${l.couleur};${l.inactif ? 'opacity:.55' : ''}">
          <div style="display:flex;align-items:center;gap:6px">
            <span style="width:8px;height:8px;border-radius:2px;background:${l.couleur};flex:0 0 auto"></span>
            <span style="font-size:11px;font-weight:500;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(l.nom)}">${esc(l.nom)}</span>
          </div>
          <div style="font-size:17px;font-weight:500;font-variant-numeric:tabular-nums;margin-top:6px;line-height:1.1">${esc(l.depense)}</div>
          <div style="height:4px;border-radius:999px;background:var(--color-border-tertiary);overflow:hidden;margin-top:6px"><i style="display:block;height:100%;border-radius:999px;width:${l.barre.toFixed(1)}%;background:${l.couleur}"></i></div>
          <div style="font-size:10px;color:var(--color-text-muted);margin-top:5px;line-height:1.35">${esc(l.part || 'aucune dépense')}</div>
          <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:2px">ROI ${esc(l.roi)}</div>
        </div>`).join('')}
      </div>` : `<div style="font-size:12px;color:var(--color-text-muted)">Aucun levier renseigné.</div>`}
      <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:10px;line-height:1.45">Un levier sans dépense n’a pas de retour à montrer — c’est une absence, pas un zéro.${c.foLevOrphelines ? ' ' + esc(c.foLevOrphelines) : ''}</div>
    </div>

    <div style="display:grid;grid-template-columns:1fr;gap:12px;align-items:start">
      <div style="${carte};overflow:hidden">
        <div style="padding:13px 17px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;justify-content:space-between;gap:10px">
          <span style="font-size:13px;font-weight:500">Grand livre du fonds</span>
          <span style="font-size:11px;color:var(--color-text-muted)">Cliquez une ligne pour la corriger</span>
        </div>
        ${c.foVide ? `<div style="padding:22px 17px;font-size:12.5px;color:var(--color-text-muted)">Aucun mouvement enregistré.</div>` : `
        <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:620px">
          <thead><tr>
            <th style="${th};padding-left:17px">Date</th>
            <th style="${th}">Mouvement</th>
            <th style="${th}">Levier</th>
            <th style="${th}">Réseau / boutique</th>
            <th style="${th};${num}">Montant</th>
            <th style="${th};padding-right:17px"></th>
          </tr></thead>
          <tbody>${c.foLignes.map(l => `<tr>
            <td style="${td};padding-left:17px;white-space:nowrap;color:var(--color-text-muted)">${esc(l.date)}</td>
            <td style="${td}"><span style="font-weight:500">${esc(l.libelle)}</span><div style="font-size:10.5px;color:var(--color-text-muted)">${esc(l.sens)}${l.source ? ' · ' + esc(l.source) : ''}</div></td>
            <td style="${td}">${l.levier
              ? `<span style="display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:500;padding:1px 8px;border-radius:999px;background:${l.levierCol}1f;border:1px solid ${l.levierCol};color:var(--color-text)"><span style="width:7px;height:7px;border-radius:2px;background:${l.levierCol}"></span>${esc(l.levier)}</span>`
              : `<span style="font-size:11px;color:var(--color-on-abricot)">aucun levier</span>`}</td>
            <td style="${td};font-size:11.5px">${l.reseau
              ? `<span style="font-weight:500">Tout le réseau</span>`
              : `<span style="font-weight:500">${esc(l.magasin)}</span>`}${l.campagne ? `<div style="color:var(--color-text-muted)">${esc(l.campagne)}</div>` : ''}${l.fournisseur ? `<div style="color:var(--color-text-muted)">${esc(l.fournisseur)}</div>` : ''}</td>
            <td style="${td};${num};color:${l.col};font-weight:500">${esc(l.montant)}</td>
            <td style="${td};padding-right:17px;text-align:right;white-space:nowrap">
              ${l.editer ? `<button ${x.A(l.editer)} title="Corriger cette écriture" style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text-muted);border-radius:7px;padding:3px 9px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer">Corriger</button>` : ''}
              ${l.supprimer ? `<button ${x.A(l.supprimer)} title="Supprimer cette écriture" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:pointer;padding:0 2px;margin-left:4px">✕</button>` : ''}
              ${!l.editer && !l.supprimer ? `<span style="font-size:10.5px;color:var(--color-text-muted)">frais récurrent</span>` : ''}
            </td>
          </tr>`).join('')}</tbody>
        </table></div>
        ${c.foTronque ? `<div style="padding:9px 17px;font-size:11px;color:var(--color-text-muted);border-top:0.5px solid var(--color-border-tertiary)">${c.foTronque} mouvement(s) plus anciens — le détail complet est dans le module marketing.</div>` : ''}`}
      </div>
    </div>

    <div style="${carte};overflow:hidden">
      <div style="padding:13px 17px;border-bottom:0.5px solid var(--color-border-tertiary);font-size:13px;font-weight:500">Redevances par magasin${c.foMois ? ' — ' + esc(c.foMois) : ''}</div>
      ${c.foRoyaltiesVide ? `<div style="padding:22px 17px;font-size:12.5px;color:var(--color-text-muted)">Aucun magasin.</div>` : `
      <table style="width:100%;border-collapse:collapse">
        <thead><tr>
          <th style="${th};padding-left:17px">Magasin</th>
          <th style="${th};${num}">CA du mois</th>
          <th style="${th}">Taux</th>
          <th style="${th};${num};padding-right:17px">Dû</th>
        </tr></thead>
        <tbody>${c.foRoyalties.map(r => `<tr>
          <td style="${td};padding-left:17px"><span style="font-weight:500">${esc(r.nom)}</span>${r.ville ? `<div style="font-size:10.5px;color:var(--color-text-muted)">${esc(r.ville)}</div>` : ''}</td>
          <td style="${td};${num}">${esc(r.ca)}${r.manque ? `<div style="font-size:10px;color:var(--color-on-abricot);font-weight:400">${esc(r.manque)}</div>` : ''}</td>
          <td style="${td};color:var(--color-text-muted);font-size:11.5px">${esc(r.taux)}</td>
          <td style="${td};${num};padding-right:17px">${esc(r.du)}${r.ecrit ? `<div style="font-size:10px;color:var(--color-text-muted);font-weight:400">${esc(r.ecrit)}</div>` : ''}</td>
        </tr>`).join('')}</tbody>
      </table>`}
      ${c.foRoySource ? `<div style="padding:9px 17px;border-top:0.5px solid var(--color-border-tertiary);font-size:11px;color:var(--color-text-muted);line-height:1.5">${esc(c.foRoySource)}</div>` : ''}
      ${c.foRoyNote ? `<div style="padding:9px 17px;border-top:0.5px solid var(--color-border-tertiary);font-size:11px;color:var(--color-text-muted);line-height:1.5">${esc(c.foRoyNote)}</div>` : ''}
      ${c.foErp ? `<div style="padding:10px 17px;border-top:0.5px solid var(--color-border-tertiary);font-size:11.5px;color:var(--color-on-abricot);line-height:1.5">Reprise ERP : ${esc(c.foErp)}</div>` : ''}
      <!-- Ce que le cockpit ne peut pas encore piloter, dit à l'endroit où on
           le cherche — et pourquoi, avec les routes exactes. -->
      ${(c.foManque || []).map(m => `<div style="padding:13px 17px;border-top:0.5px solid var(--color-border-tertiary);display:flex;gap:9px;align-items:flex-start">
        <span style="font-size:10px;font-weight:500;padding:2px 9px;border-radius:999px;background:#FBEFE0;color:var(--color-on-abricot);border:1px solid #E8C9A0;white-space:nowrap;flex:0 0 auto">manque API</span>
        <div><div style="font-size:12.5px;font-weight:500">${esc(m.champ)}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px;line-height:1.5">${esc(m.quoi)}</div>
          <div style="font-size:11px;color:var(--color-text-muted);margin-top:4px;line-height:1.5">${esc(m.source)}</div></div>
      </div>`).join('')}
    </div>

    <div style="font-size:11.5px;color:var(--color-text-muted);line-height:1.5">Lu et écrit en direct sur ${esc(c.foSource || 'le module marketing')} (${esc(c.foBase)}). Le cockpit n\u2019en garde aucune copie : le fonds se tient à un seul endroit, et les écritures s\u2019y font — depuis cet écran.</div>
  </div>`;
}

/* --- Projets · le fonds de la marque face aux projets ------------------------ */
/**
 * Le rapprochement se fait sur le SOLDE, pas sur la ligne : rien ne rattache
 * encore un mouvement du fonds à un projet, et la carte le dit — sinon on
 * lirait une couverture projet par projet qui n'existe pas.
 */
function tplProjetsFonds(c, x){
  const { esc } = x;
  const f = c.pjFonds;
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const k = 'font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:.08em;font-weight:500';
  if (f.chargement) {
    return `<div style="${carte};padding:15px 18px;font-size:12.5px;color:var(--color-text-muted)">Lecture du fonds de la marque…</div>`;
  }
  return `
  <div style="${carte};padding:15px 18px">
    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px">
      <span style="font-size:13px;font-weight:500">Le fonds de la marque face aux projets</span>
      <button ${x.A(f.ouvrir)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:8px;height:27px;padding:0 11px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Fonds &amp; Royalties</button>
    </div>
    ${f.erreur ? `<div style="font-size:11.5px;color:var(--color-on-abricot);margin-top:7px;line-height:1.45">${esc(f.erreur)}</div>` : ''}
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-top:13px">
      <div><div style="${k}">Alimenté</div><div style="font-size:19px;font-weight:500;margin-top:2px;font-variant-numeric:tabular-nums">${esc(f.alim)}</div></div>
      <div><div style="${k}">Dépensé</div><div style="font-size:19px;font-weight:500;margin-top:2px;font-variant-numeric:tabular-nums">${esc(f.dep)}</div></div>
      <div><div style="${k}">Solde disponible</div><div style="font-size:19px;font-weight:500;margin-top:2px;font-variant-numeric:tabular-nums;color:${f.verdictCol}">${esc(f.solde)}</div></div>
      <div><div style="${k}">Engagé par les projets</div><div style="font-size:19px;font-weight:500;margin-top:2px;font-variant-numeric:tabular-nums">${esc(f.engage)}</div></div>
    </div>
    <div style="height:9px;border-radius:999px;background:var(--color-border-tertiary);overflow:hidden;margin-top:12px">
      <i style="display:block;height:100%;border-radius:999px;width:${f.couvre.toFixed(1)}%;background:${f.verdictCol}"></i>
    </div>
    <div style="font-size:12px;color:${f.verdictCol};margin-top:7px;font-weight:500">${esc(f.verdict)}</div>
    <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:5px;line-height:1.45">${esc(f.note)}</div>
  </div>`;
}

/* --- Projets · les sorties d'argent, mois par mois --------------------------- */
/**
 * Le calendrier des sorties : ce qui quitte le fonds, et ce que les projets
 * font sortir — prévisionnel en GRIS, réel en NOIR.
 *
 * Seul ce qui porte une date figure ici. Ce qui n'en porte pas est annoncé
 * sous le tableau plutôt que réparti au jugé.
 */
function tplProjetsPeriodes(c, x){
  const { esc } = x;
  const p = c.pjPeriodes;
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const th = 'text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 10px 7px';
  const td = 'padding:9px 10px;border-top:0.5px solid var(--color-border-tertiary);font-size:12.5px';
  const num = 'text-align:right;font-variant-numeric:tabular-nums';
  const GRIS = '#C2BDB7', NOIR = 'var(--color-text)', FONDS = 'var(--color-primary)';
  const puce = (col, nom) => `<span style="display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--color-text-muted)"><span style="width:11px;height:4px;border-radius:2px;background:${col}"></span>${nom}</span>`;
  return `
  <div style="${carte};overflow:hidden">
    <div style="padding:13px 18px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
      <span style="font-size:13px;font-weight:500">Les sorties d’argent, mois par mois</span>
      <span style="display:flex;gap:14px;flex-wrap:wrap">${puce(FONDS, 'Sortie du fonds')}${puce(GRIS, 'Budget prévisionnel')}${puce(NOIR, 'Budget réel')}</span>
    </div>
    ${p.vide ? `<div style="padding:22px 18px;font-size:12.5px;color:var(--color-text-muted)">Aucune sortie datée — ni du fonds, ni des projets.</div>` : `
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:640px">
      <thead><tr>
        <th style="${th};padding-left:18px">Mois</th>
        <th style="${th};${num}">Sortie du fonds</th>
        <th style="${th};${num}">Prévisionnel</th>
        <th style="${th};${num}">Réel</th>
        <th style="${th};${num}">Écart</th>
        <th style="${th};width:34%;padding-right:18px">Comparaison</th>
      </tr></thead>
      <tbody>${p.lignes.map(l => `<tr>
        <td style="${td};padding-left:18px;white-space:nowrap"><span style="font-weight:500">${esc(l.mois)}</span>${l.detail ? `<div style="font-size:10.5px;color:var(--color-text-muted);font-weight:400">${esc(l.detail)}</div>` : ''}</td>
        <td style="${td};${num};color:${FONDS}">${esc(l.fonds)}</td>
        <td style="${td};${num};color:var(--color-text-muted)">${esc(l.prev)}</td>
        <td style="${td};${num};font-weight:500">${esc(l.reel)}</td>
        <td style="${td};${num};color:${l.ecartCol}">${esc(l.ecart)}</td>
        <td style="${td};padding-right:18px">
          <div style="display:flex;flex-direction:column;gap:3px">
            <div style="height:5px;border-radius:999px;background:var(--color-border-tertiary);overflow:hidden"><i style="display:block;height:100%;border-radius:999px;width:${l.bFonds.toFixed(1)}%;background:${FONDS}"></i></div>
            <div style="height:5px;border-radius:999px;background:var(--color-border-tertiary);overflow:hidden"><i style="display:block;height:100%;border-radius:999px;width:${l.bPrev.toFixed(1)}%;background:${GRIS}"></i></div>
            <div style="height:5px;border-radius:999px;background:var(--color-border-tertiary);overflow:hidden"><i style="display:block;height:100%;border-radius:999px;width:${l.bReel.toFixed(1)}%;background:${NOIR}"></i></div>
          </div>
        </td>
      </tr>`).join('')}</tbody>
      <tfoot><tr>
        <td style="${td};padding-left:18px;font-weight:500;border-top-width:1px">Total</td>
        <td style="${td};${num};color:${FONDS};font-weight:500;border-top-width:1px">${esc(p.totFonds)}</td>
        <td style="${td};${num};color:var(--color-text-muted);border-top-width:1px">${esc(p.totPrev)}</td>
        <td style="${td};${num};font-weight:500;border-top-width:1px">${esc(p.totReel)}</td>
        <td style="${td};border-top-width:1px"></td><td style="${td};border-top-width:1px"></td>
      </tr></tfoot>
    </table></div>`}
    <div style="padding:11px 18px;border-top:0.5px solid var(--color-border-tertiary);font-size:11px;color:var(--color-text-muted);line-height:1.5">
      Le calendrier ne porte que ce qui a une date : le budget des tâches, placé à l’échéance pour le prévisionnel et à la date de réalisation pour le réel.${p.vidTaches ? ' Aucune tâche ne porte de budget — la ligne des projets reste donc vide.' : ''} ${esc(p.horsCal)}${p.sansDate ? ' ' + esc(p.sansDate) : ''} ${esc(p.note)}
    </div>
  </div>`;
}

/* --- Projets · budgets engagés et retour ------------------------------------- */
function tplProjetsBudgets(c, x){
  const { esc } = x;
  const b = c.pjBudgets;
  const k = 'font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:.08em;font-weight:500';
  const th = 'text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 10px 7px';
  const td = 'padding:9px 10px;border-top:0.5px solid var(--color-border-tertiary);font-size:12.5px';
  const num = 'text-align:right;font-variant-numeric:tabular-nums';
  return `
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
    ${b.tuiles.map(t => `<div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:15px 17px">
      <div style="${k}">${esc(t.k)}</div>
      <div style="font-size:25px;font-weight:500;line-height:1.05;margin-top:3px">${esc(t.v)}</div>
      ${t.barre != null ? `<div style="height:8px;border-radius:999px;background:var(--color-border-tertiary);overflow:hidden;margin-top:7px"><i style="display:block;height:100%;border-radius:999px;width:${t.barre.toFixed(1)}%;background:${t.col}"></i></div>` : ''}
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:5px;line-height:1.4">${esc(t.aide)}</div>
    </div>`).join('')}
  </div>

  ${c.pjFonds ? tplProjetsFonds(c, x) : ''}
  ${c.pjPeriodes ? tplProjetsPeriodes(c, x) : ''}

  <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
    <div style="padding:13px 18px;border-bottom:0.5px solid var(--color-border-tertiary);font-size:13px;font-weight:500">Par projet — ce qui est engagé, ce qui revient</div>
    ${b.vide ? `<div style="padding:24px 18px;font-size:12.5px;color:var(--color-text-muted)">Aucun projet.</div>` : `
    <table style="width:100%;border-collapse:collapse">
      <thead><tr>
        <th style="${th};padding-left:18px">Projet</th>
        <th style="${th}">Famille</th>
        <th style="${th};${num}">Voté</th>
        <th style="${th};${num}">Engagé</th>
        <th style="${th};${num}">Consommé</th>
        <th style="${th};${num}">Valeur est.</th>
        <th style="${th};${num}">Valeur réal.</th>
        <th style="${th};${num}">ROI</th>
        <th style="${th};${num};padding-right:18px">Retour</th>
      </tr></thead>
      <tbody>${b.lignes.map(l => `<tr ${x.A(l.ouvrir)} title="Voir la fiche franchisé" style="cursor:pointer">
        <td style="${td};padding-left:18px">
          <span style="font-weight:500">${esc(l.nom)}</span>
          ${l.leviers.length ? `<div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:4px">${l.leviers.map(lv => `<span style="display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:500;padding:1px 8px;border-radius:999px;background:${lv.couleur}1f;border:1px solid ${lv.couleur};color:var(--color-text)"><span style="width:7px;height:7px;border-radius:2px;background:${lv.couleur}"></span>${esc(lv.nom)}</span>`).join('')}</div>` : ''}
        </td>
        <td style="${td};color:var(--color-text-muted)">${esc(l.famille)}<div style="font-size:10.5px">${esc(l.statut)}</div></td>
        <td style="${td};${num}">${esc(l.vote)}</td>
        <td style="${td};${num}">${esc(l.engage)}</td>
        <td style="${td};${num}">${esc(l.conso)}</td>
        <td style="${td};${num};color:var(--color-text-muted)">${esc(l.est)}</td>
        <td style="${td};${num}">${esc(l.real)}</td>
        <td style="${td};${num};color:${l.roiCol};font-weight:500">${esc(l.roi)}${l.roiEstime ? `<div style="font-size:10px;font-weight:400;color:var(--color-text-muted)">estimé</div>` : ''}</td>
        <td style="${td};${num};padding-right:18px">${esc(l.retour)}</td>
      </tr>`).join('')}</tbody>
    </table>`}
  </div>
  <div style="font-size:11.5px;color:var(--color-text-muted);line-height:1.5">« Engagé » = le prévu des postes de coût, « consommé » = leur réel. Le ROI se lit sur la valeur RÉALISÉE ; tant qu’elle manque, la colonne rend l’estimation et le dit. Le retour n’est calculé qu’avec au moins un mois écoulé — extrapoler un mois sur douze annoncerait un retour que personne n’a observé.${b.note ? ' ' + esc(b.note) : ''}</div>`;
}

/* --- Projets · la fiche que lit un franchisé --------------------------------- */
function tplProjetsFranchise(c, x){
  const { esc } = x;
  const f = c.pjFranchise;
  const k = 'font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:.08em;font-weight:500';
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:15px 17px';
  if (f.vide) {
    return `<div style="${carte};font-size:12.5px;color:var(--color-text-muted)">Aucun projet à présenter.</div>`;
  }
  return `
  <div style="display:flex;gap:7px;flex-wrap:wrap">
    ${f.choix.map(o => `<button ${x.A(o.go)} style="border-radius:999px;height:29px;padding:0 13px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;${o.on ? 'border:1px solid var(--color-primary);background:rgba(141,29,44,0.08);color:var(--color-primary)' : 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text)'}">${esc(o.nom)}</button>`).join('')}
  </div>

  <div style="${carte}">
    <div style="display:grid;grid-template-columns:1.1fr 1fr 1fr;gap:22px">
      <div>
        <div style="${k}">Ce que ce projet développe</div>
        ${f.leviers.length
          ? `<div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:8px">${f.leviers.map(lv => `<span title="${esc(lv.desc)}" style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:500;padding:4px 11px;border-radius:999px;background:${lv.couleur}1f;border:1px solid ${lv.couleur};color:var(--color-text)"><span style="width:9px;height:9px;border-radius:3px;background:${lv.couleur}"></span>${esc(lv.nom)}</span>`).join('')}</div>
             ${f.leviers[0].desc ? `<div style="font-size:11px;color:var(--color-text-muted);margin-top:8px;line-height:1.5">${esc(f.leviers[0].desc)}</div>` : ''}`
          : `<div style="font-size:11.5px;color:var(--color-text-muted);margin-top:8px">Aucun levier choisi sur ce projet.</div>`}
      </div>
      <div>
        <div style="${k}">Ce qui est recherché</div>
        ${f.kpisVide
          ? `<div style="font-size:11.5px;color:var(--color-text-muted);margin-top:8px;line-height:1.5">Aucun indicateur choisi. Sans lui, on saura que le projet est fini, pas s’il a marché.</div>`
          : `<div style="display:flex;flex-direction:column;gap:5px;margin-top:8px">${f.kpis.map(kp => `<div style="font-size:12.5px">${esc(kp)}</div>`).join('')}</div>
             <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:7px;line-height:1.45">Aucune cible chiffrée n’est enregistrée — l’inventer donnerait un objectif que personne n’a fixé.</div>`}
      </div>
      <div>
        <div style="${k}">Pourquoi ce projet</div>
        <div style="font-size:12.5px;line-height:1.55;margin-top:8px">${f.pourquoi ? esc(f.pourquoi) : '<span style="color:var(--color-text-muted)">Rien n’est écrit sur ce que le projet doit apporter.</span>'}</div>
        <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:8px">${esc(f.famille)} · ${esc(f.statut)} · ${esc(f.periode)}</div>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
    ${f.tuiles.map(t => `<div style="${carte}">
      <div style="${k}">${esc(t.k)}</div>
      <div style="font-size:24px;font-weight:500;line-height:1.05;margin-top:3px${t.vert ? ';color:#2d7a3e' : ''}">${esc(t.v)}</div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:5px;line-height:1.4">${esc(t.aide)}</div>
    </div>`).join('')}
  </div>

  <div style="display:grid;grid-template-columns:1.35fr 1fr;gap:12px;align-items:start">
    <div style="${carte}">
      <div style="font-size:13px;font-weight:500;margin-bottom:9px">Ce qui attend la boutique</div>
      ${f.etapesVide
        ? `<div style="font-size:12px;color:var(--color-text-muted)">Aucun jalon ni tâche sur ce projet.</div>`
        : f.etapes.map(e => `<div style="display:flex;gap:11px;align-items:flex-start;padding:8px 0;border-top:0.5px solid var(--color-border-tertiary)">
            <span style="flex:0 0 auto;width:9px;height:9px;border-radius:999px;margin-top:5px;background:${e.fait ? '#2d7a3e' : 'var(--color-border-secondary)'}"></span>
            <div style="flex:1;min-width:0">
              <div style="font-size:12.5px;${e.fait ? 'color:var(--color-text-muted)' : 'font-weight:500'}">${esc(e.nom)}${e.fait ? ' — fait' : ''}</div>
              <div style="font-size:10.5px;color:var(--color-text-muted)">${esc(e.date)}${e.tache ? ' · tâche' : ' · jalon'}</div>
            </div>
          </div>`).join('')}
    </div>
    <div style="${carte}">
      <div style="font-size:13px;font-weight:500;margin-bottom:9px">Ce que cette fiche ne peut pas encore dire</div>
      ${f.manque.map(m => `<div style="display:flex;gap:8px;align-items:flex-start;margin-top:9px">
        <span style="font-size:10px;font-weight:500;padding:2px 8px;border-radius:999px;background:#FBEFE0;color:var(--color-on-abricot);border:1px solid #E8C9A0;white-space:nowrap;flex:0 0 auto">manque</span>
        <div style="font-size:11.5px;line-height:1.5"><b style="font-weight:500">${esc(m.champ)}</b><div style="color:var(--color-text-muted)">${esc(m.source)}</div></div>
      </div>`).join('')}
    </div>
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
    <!-- Tableau de bord : les compteurs d'abord, la liste ensuite. Tuiles
         sobres, un seul chiffre par tuile, et la couleur réservée à ce qui
         demande une action — sinon elle ne veut plus rien dire. -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:10px">
      ${(c.tkTuiles || []).map(t => `<${t.go ? 'button' : 'div'} ${t.go ? x.A(t.go) : ''} ${t.go ? 'class="hv-fade"' : ''}
        style="text-align:left;font-family:var(--font-ui);background:var(--color-surface);border:0.5px solid ${t.vif ? 'rgba(141,29,44,0.28)' : 'var(--color-border-tertiary)'};border-radius:12px;padding:14px 15px;display:flex;flex-direction:column;gap:2px;${t.go ? 'cursor:pointer;' : ''}min-width:0">
        <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--color-text-muted)">${esc(t.lib)}</span>
        <span style="font-family:var(--font-display);font-size:30px;line-height:1.05;color:${t.col}">${esc(t.valeur)}</span>
        <span style="font-size:11px;font-weight:300;color:var(--color-text-muted);line-height:1.35">${esc(t.sous)}</span>
      </${t.go ? 'button' : 'div'}>`).join('')}
    </div>
    <!-- P&L court par magasin, sur le mois EN COURS. La source est l'API :
         la caisse en base s'arrête au dernier jour encodé et ne peut pas
         répondre à « où en sont-ils aujourd'hui ». -->
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:16px">
      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:4px">
        <div style="font-size:13px;font-weight:700">Chiffre d’affaires du mois par magasin</div>
        <div style="font-size:11.5px;font-weight:300;color:var(--color-text-muted)">${esc(c.tkPnlPeriode)} · source API, mois en cours</div>
      </div>
      ${c.tkPnlEtat === 'chargement' ? `<div style="font-size:12.5px;color:var(--color-text-muted);padding:14px 0">Lecture de l’API…</div>`
        : !(c.tkPnl || []).length ? `<div style="font-size:12.5px;color:var(--color-text-muted);padding:14px 0">Aucun magasin rendu par l’API sur le mois en cours.</div>` : `
      <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:660px">
        <thead><tr>
          ${['Magasin', 'CA du mois', 'N-1', 'Écart', 'Tickets', 'Panier', 'Objectif', 'Atteinte']
            .map((h, i) => `<th style="text-align:${i ? 'right' : 'left'};font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 12px 8px 0;white-space:nowrap">${esc(h)}</th>`).join('')}
        </tr></thead>
        <tbody>${c.tkPnl.map(m => `<tr>
          <td style="padding:9px 12px 9px 0;border-top:0.5px solid var(--color-border-tertiary);font-size:12.5px">
            <div style="font-weight:500">${esc(m.nom)}</div>
            <span style="${m.barre};margin-top:5px;max-width:200px"></span></td>
          <td style="padding:9px 12px 9px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;font-weight:500">${esc(m.ca)}</td>
          <td style="padding:9px 12px 9px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;color:var(--color-text-muted)">${esc(m.n1)}</td>
          <td style="padding:9px 12px 9px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;font-weight:500;color:${m.ecartCol}">${esc(m.ecart) || '—'}</td>
          <td style="padding:9px 12px 9px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;color:var(--color-text-muted)">${esc(m.tickets)}</td>
          <td style="padding:9px 12px 9px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;color:var(--color-text-muted)">${esc(m.panier)}</td>
          <td style="padding:9px 12px 9px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-size:12.5px">${m.obj
            ? `<span style="font-variant-numeric:tabular-nums">${esc(m.obj)}</span>`
            : `<span style="font-size:10.5px;font-weight:500;padding:1px 7px;border-radius:999px;background:var(--color-background-secondary);color:var(--color-text-muted);border:1px solid var(--color-border-tertiary);white-space:nowrap">à renseigner</span>`}</td>
          <td style="padding:9px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;font-weight:700;color:${m.attCol}">${m.att ? esc(m.att) : '—'}</td>
        </tr>`).join('')}</tbody>
      </table></div>
      ${c.tkPnlSansObj ? `<div style="font-size:11.5px;color:var(--color-text-muted);margin-top:10px;line-height:1.5">
        <strong style="font-weight:700">${c.tkPnlSansObj} magasin${c.tkPnlSansObj > 1 ? 's' : ''} sur ${c.tkPnlTotal} sans objectif de CA encodé</strong> — l’atteinte reste vide : elle ne peut pas se calculer contre une cible absente. Le budget se saisit dans « Encodage du budget ». En attendant, l’écart N-1 est la seule référence disponible.</div>` : ''}`}
    </div>

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
  <!-- Un panneau par ligne : à deux colonnes, le rapport et sa colonne de 340 px
       se lisaient en zigzag, et la liste des rapports — le contenu principal —
       se retrouvait comprimée par un panneau secondaire. -->
  <div data-screen="reporting" style="display:grid;grid-template-columns:1fr;gap:16px;align-items:start">
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
      <div style="padding:14px 18px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div style="flex:1;min-width:260px">
          <div style="font-size:13px;font-weight:500">Générateur de rapports — par levier, à seuils</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">Un bloc ne s'imprime que si son seuil est franchi ; un rapport sans matière n'est pas envoyé. Envoi automatique : une ligne crontab horaire sur ${c.rapGen.cronUrl ? `<code>${esc(c.rapGen.cronUrl)}</code>` : `l'URL de cron (se génère au premier chargement)`}.</div>
        </div>
        ${c.rapComposeOn
          ? `<button ${x.A(c.rapComposeFermer)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:8px 15px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">← Retour à la liste</button>`
          : `<button ${x.A(c.rapComposeOuvrir)} style="border:none;border-radius:999px;padding:8px 16px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12px;font-weight:600;cursor:pointer">+ Nouveau rapport</button>`}
      </div>
      ${c.rapComposeOn ? `
      <div style="padding:16px 18px;display:flex;flex-direction:column;gap:14px">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Partir d'un modèle</span>
          <select ${x.C(c.rapCompo.chargerModele)} style="font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 9px;background:var(--color-surface);color:var(--color-text)">
            ${c.rapCompo.modeles.map(mo => `<option value="${mo.id}">${esc(mo.nom)}</option>`).join('')}
          </select>
          <span style="margin-left:auto;font-size:12px;font-weight:500">${esc(c.rapCompo.recap)}</span>
        </div>
        <div>
          <div style="font-size:12.5px;font-weight:600;margin-bottom:6px">1 · Les KPI <span style="font-weight:400;color:var(--color-text-muted);font-size:11px">— « Complet » = le tableau tel qu'à l'écran · « Dépassements » = seulement les seuils franchis</span></div>
          ${c.rapCompo.groupes.map(g => `
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin:9px 0 5px"><i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${g.couleur};margin-right:6px"></i>${esc(g.nom)}</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:6px">
              ${g.items.map(it => `
                <div style="display:flex;align-items:center;gap:8px;border:${it.on ? '1.5px solid var(--color-primary)' : '0.5px solid var(--color-border-tertiary)'};border-radius:9px;padding:7px 10px;background:${it.on ? 'var(--color-background-secondary)' : 'var(--color-surface)'}">
                  <span ${x.A(it.toggle)} style="width:15px;height:15px;border-radius:4px;flex:none;cursor:pointer;border:1.5px solid ${it.on ? 'var(--color-primary)' : 'var(--color-border-secondary)'};background:${it.on ? 'var(--color-primary)' : 'transparent'};color:#fff;font-size:10px;line-height:14px;text-align:center">${it.on ? '✓' : ''}</span>
                  <span ${x.A(it.toggle)} style="flex:1;font-size:12px;font-weight:500;cursor:pointer">${esc(it.nom)}</span>
                  ${it.on ? `<span style="display:inline-flex;border:0.5px solid var(--color-border-secondary);border-radius:999px;overflow:hidden;font-size:9.5px;font-weight:600">
                    <button ${x.A(it.setModeComplet)} style="border:none;cursor:pointer;padding:2px 7px;font-family:var(--font-ui);${it.mode === 'complet' ? 'background:var(--color-text);color:var(--color-surface)' : 'background:transparent;color:var(--color-text-muted)'}">Complet</button>
                    <button ${x.A(it.setModeDep)} style="border:none;cursor:pointer;padding:2px 7px;font-family:var(--font-ui);${it.mode !== 'complet' ? 'background:var(--color-text);color:var(--color-surface)' : 'background:transparent;color:var(--color-text-muted)'}">Dépass.</button>
                  </span>` : ''}
                </div>`).join('')}
            </div>`).join('')}
        </div>
        <div>
          <div style="font-size:12.5px;font-weight:600;margin-bottom:6px">2 · Les magasins</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span ${x.A(c.rapCompo.toutLeReseau)} style="display:inline-block;border-radius:999px;padding:5px 12px;font-size:11.5px;font-weight:500;cursor:pointer;${c.rapCompo.tous ? 'border:1.5px solid var(--color-primary);background:var(--color-background-secondary)' : 'border:0.5px solid var(--color-border-secondary)'}">Tout le réseau</span>
            ${c.rapCompo.magasins.map(mg => `<span ${x.A(mg.toggle)} style="display:inline-block;border-radius:999px;padding:5px 12px;font-size:11.5px;font-weight:500;cursor:pointer;${mg.on ? 'border:1.5px solid var(--color-primary);background:var(--color-background-secondary)' : 'border:0.5px solid var(--color-border-secondary)'}">${esc(mg.nom)}</span>`).join('')}
          </div>
        </div>
        ${c.rapCompo.plan.length ? `
        <div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px">
            <span style="font-size:12.5px;font-weight:600">3 · L'ordre et les pages</span>
            <span style="font-size:11px;color:var(--color-text-muted)">Les blocs sortent dans cet ordre ; sans saut, ils s'enchaînent et remplissent la feuille.</span>
            <span style="margin-left:auto;display:flex;gap:6px">
              <button ${x.A(c.rapCompo.pageParLevier)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:5px 13px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui);font-size:11px;font-weight:600;cursor:pointer">Une page par levier</button>
              <button ${x.A(c.rapCompo.sansSaut)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:5px 13px;background:var(--color-surface);color:var(--color-text-muted);font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Aucun saut</button>
              <button ${x.A(c.rapCompo.ordreParLevier)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:5px 13px;background:var(--color-surface);color:var(--color-text-muted);font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Ordre du cockpit</button>
            </span>
          </div>
          ${c.rapCompo.plan.map(pl => `
            ${pl.saut ? `<div style="display:flex;align-items:center;gap:9px;margin:9px 2px"><span style="flex:1;border-top:1px dashed var(--color-border-secondary)"></span><span style="font-size:9.5px;font-weight:600;letter-spacing:0.09em;text-transform:uppercase;color:var(--color-text-muted)">saut de page${pl.force ? ' — imposé' : ''}</span><span style="flex:1;border-top:1px dashed var(--color-border-secondary)"></span></div>` : ''}
            <div style="display:flex;align-items:center;gap:10px;border:0.5px solid var(--color-border-tertiary);border-radius:10px;padding:7px 11px;margin-bottom:5px;background:var(--color-background-secondary)">
              <span style="display:flex;flex-direction:column;gap:1px">
                <span ${x.A(pl.monter)} style="font-size:9px;line-height:9px;cursor:${pl.monter ? 'pointer' : 'default'};color:${pl.monter ? 'var(--color-text-muted)' : 'var(--color-border-secondary)'}">▲</span>
                <span ${x.A(pl.descendre)} style="font-size:9px;line-height:9px;cursor:${pl.descendre ? 'pointer' : 'default'};color:${pl.descendre ? 'var(--color-text-muted)' : 'var(--color-border-secondary)'}">▼</span>
              </span>
              <span style="font-size:10px;font-weight:600;color:var(--color-text-muted);background:var(--color-surface);border-radius:6px;padding:3px 7px;min-width:34px;text-align:center">P. ${pl.page}</span>
              <i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${pl.couleur};flex:none"></i>
              <span style="flex:1;font-size:12px;font-weight:500">${esc(pl.nom)}</span>
              <span style="font-size:9.5px;font-weight:600;color:var(--color-text-muted);border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:2px 8px">${esc(pl.mode)}</span>
              ${pl.premier ? '<span style="font-size:10px;color:var(--color-text-muted);padding:3px 10px">1ʳᵉ page</span>'
                : pl.force ? '<span style="font-size:10px;font-weight:600;color:var(--color-text-muted);border:0.5px dashed var(--color-border-secondary);border-radius:999px;padding:3px 10px" title="Ce bloc ouvre toujours une page">saut imposé</span>'
                : `<button ${x.A(pl.basculerSaut)} style="border:${pl.saut ? 'none' : '0.5px solid var(--color-border-secondary)'};border-radius:999px;padding:4px 11px;font-family:var(--font-ui);font-size:10.5px;font-weight:600;cursor:pointer;${pl.saut ? 'background:var(--color-primary);color:#fff' : 'background:var(--color-surface);color:var(--color-text-muted)'}">saut de page${pl.saut ? ' ✓' : ''}</button>`}
            </div>`).join('')}
          <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:6px">« P. 2 » est l'intention : un bloc plus long que la feuille déborde sur la suivante. Le bilan des tâches et les photos ouvrent toujours leur page, et restent l'un derrière l'autre.</div>
        </div>` : ''}
        <div>
          <div style="font-size:12.5px;font-weight:600;margin-bottom:8px">4 · Quand l'envoyer</div>
          <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:9px">
            <span style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted)">Heure d'envoi</span>
            <select ${x.C(c.rapCompo.setHeure)} style="font-size:13px;font-weight:600;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:7px 12px;background:var(--color-surface);color:var(--color-text)">${Array.from({ length: 24 }, (_, h4) => `<option value="${h4}"${String(c.rapCompo.heure) === String(h4) ? ' selected' : ''}>${String(h4).padStart(2, '0')} h 00</option>`).join('')}</select>
            <span style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted);margin-left:8px">Jours de semaine</span>
            <span style="display:flex;gap:5px;flex-wrap:wrap">
              ${c.rapCompo.dows.map(d6 => `<span ${x.A(d6.toggle)} style="display:inline-block;width:44px;text-align:center;border-radius:8px;padding:7px 0;font-size:11px;font-weight:700;cursor:pointer;${d6.on ? 'background:var(--color-primary);color:#fff' : 'background:var(--color-surface);border:0.5px solid var(--color-border-secondary);color:var(--color-text-muted)'}">${esc(d6.nom)}</span>`).join('')}
            </span>
          </div>
          <div style="display:flex;gap:4px;flex-wrap:wrap;align-items:flex-start">
            <span style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted);width:96px;padding-top:6px">Jours du mois</span>
            <span style="display:grid;grid-template-columns:repeat(16,27px);gap:3px">
              ${c.rapCompo.doms.map(d6 => `<span ${x.A(d6.toggle)} style="display:inline-block;text-align:center;border-radius:6px;padding:4px 0;font-size:10px;font-weight:600;cursor:pointer;${d6.on ? 'background:var(--color-primary);color:#fff' : 'background:var(--color-surface);border:0.5px solid var(--color-border-secondary);color:var(--color-text-muted)'}">${esc(d6.nom)}</span>`).join('')}
            </span>
          </div>
          <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:7px">Aucun jour coché = rapport à la demande. La fenêtre de données se choisit en 5 · Générer (« Période observée ») ; par défaut elle suit la cadence : quotidien → la veille · hebdo → la semaine passée · mensuel → le mois passé.</div>
        </div>
        <div style="background:var(--color-background-secondary);border-radius:10px;padding:13px 15px">
          <div style="font-size:12.5px;font-weight:600;margin-bottom:8px">5 · Générer, envoyer, ou enregistrer${c.rapCompo.edit ? ` <span style="font-weight:500;color:var(--color-primary)">— modification de « ${esc(c.rapCompo.editNom)} »</span>` : ''}</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:11px">
            <button ${x.A(c.rapCompo.apercu)} style="border:none;border-radius:999px;padding:9px 18px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:600;cursor:pointer;${c.rapCompo.busy ? 'opacity:.6' : ''}">${c.rapCompo.busy ? 'En cours…' : 'Générer l’aperçu →'}</button>
            <button ${x.A(c.rapCompo.envoyer)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:9px 16px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">Envoyer par email</button>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
            <input value="${esc(c.rapCompo.nom)}" ${x.C(c.rapCompo.setNom)} placeholder="Nom du rapport" style="flex:2;min-width:180px;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 9px;background:var(--color-surface);color:var(--color-text)">
            <input value="${esc(c.rapCompo.poste)}" ${x.C(c.rapCompo.setPoste)} list="rap-postes" placeholder="Poste destinataire (profils du panel + réseau)" style="flex:2;min-width:220px;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 9px;background:var(--color-surface);color:var(--color-text)">
            <datalist id="rap-postes">${c.rapCompo.postes.map(po => `<option value="${esc(po)}"></option>`).join('')}</datalist>
          </div>
          <!-- La fenêtre OBSERVÉE : c'est elle qui décide si un seuil est
               franchi. « Cadence » garde le comportement d'avant. -->
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:9px">
            <span style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-primary);width:110px">Période observée</span>
            <span style="display:inline-flex;border:0.5px solid var(--color-border-secondary);border-radius:999px;overflow:hidden;font-size:11.5px;font-weight:600">
              ${c.rapCompo.periodes.map(pe => `<button ${x.A(pe.choisir)} title="${esc(pe.aide)}" style="border:none;cursor:pointer;padding:6px 13px;font-family:var(--font-ui);${pe.on ? 'background:var(--color-primary);color:#fff' : 'background:transparent;color:var(--color-text-muted)'}">${esc(pe.nom)}</button>`).join('')}
            </span>
            ${c.rapCompo.perLibre ? `
              <input type="date" value="${esc(c.rapCompo.perDu.val)}" ${x.I(c.rapCompo.perDu.set)} style="font-size:11.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:5px 8px;background:var(--color-surface);color:var(--color-text)">
              <span style="font-size:11.5px;color:var(--color-text-muted)">→</span>
              <input type="date" value="${esc(c.rapCompo.perAu.val)}" ${x.I(c.rapCompo.perAu.set)} style="font-size:11.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:5px 8px;background:var(--color-surface);color:var(--color-text)">`
              : `<span style="font-size:10.5px;color:var(--color-text-muted)">c'est cette fenêtre qui décide si un seuil est franchi</span>`}
          </div>
          <!-- Le repère de comparaison des KPI chiffrés : A-1 neutralise la
               saison, M-1 et S-1 servent à suivre une action récente. -->
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:9px">
            <span style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted);width:110px">Comparer à</span>
            <span style="display:inline-flex;border:0.5px solid var(--color-border-secondary);border-radius:999px;overflow:hidden;font-size:11.5px;font-weight:600">
              ${c.rapCompo.comparaisons.map(cp => `<button ${x.A(cp.choisir)} title="${esc(cp.aide)}" style="border:none;cursor:pointer;padding:6px 13px;font-family:var(--font-ui);${cp.on ? 'background:var(--color-primary);color:#fff' : 'background:transparent;color:var(--color-text-muted)'}">${esc(cp.code)}</button>`).join('')}
            </span>
            <span style="font-size:10.5px;color:var(--color-text-muted)">${esc((c.rapCompo.comparaisons.find(cp => cp.on) || {}).aide || '')} — pour le passage clients et le ticket moyen</span>
          </div>
          <!-- Les deux fenêtres résolues, juste avant de générer : lire un
               rapport sans savoir sur quoi il porte n'apprend rien. -->
          <div style="font-size:11.5px;background:var(--color-background-secondary);border-radius:8px;padding:9px 12px;margin-bottom:10px">
            <b>${esc(c.rapCompo.fenetre)}</b> · ${esc(c.rapCompo.recapMags)}
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:9px">
            <span style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted);width:110px">Mode d'envoi</span>
            <span style="display:inline-flex;border:0.5px solid var(--color-border-secondary);border-radius:999px;overflow:hidden;font-size:11.5px;font-weight:600">
              <button ${x.A(c.rapCompo.envoiGroupe)} style="border:none;cursor:pointer;padding:6px 13px;font-family:var(--font-ui);${c.rapCompo.envoiMode === 'groupe' ? 'background:var(--color-primary);color:#fff' : 'background:transparent;color:var(--color-text-muted)'}">Groupé</button>
              <button ${x.A(c.rapCompo.envoiParMagasin)} style="border:none;cursor:pointer;padding:6px 13px;font-family:var(--font-ui);${c.rapCompo.envoiMode === 'par-magasin' ? 'background:var(--color-primary);color:#fff' : 'background:transparent;color:var(--color-text-muted)'}">Un email par magasin</button>
            </span>
            ${c.rapCompo.envoiMode === 'par-magasin' ? `<span style="font-size:10.5px;color:var(--color-text-muted)">chaque magasin reçoit SA version ; sans matière ou sans adresse, il ne reçoit rien</span>` : ''}
          </div>
          ${c.rapCompo.envoiMode === 'par-magasin' ? `
          <div style="display:flex;flex-direction:column;gap:5px;margin-bottom:9px">
            ${c.rapCompo.carnet.map(cm => `
              <div style="display:flex;gap:8px;align-items:center">
                <span style="width:220px;flex:none;font-size:11.5px;font-weight:500">${esc(cm.nom)}</span>
                <input value="${esc(cm.val)}" ${x.C(cm.set)} placeholder="emails du franchisé (virgules)" style="flex:1;font-size:11.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 8px;background:var(--color-surface);color:var(--color-text)">
              </div>`).join('')}
          </div>` : ''}
          ${c.rapCompo.annuaire.length ? `
          <div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center;margin-bottom:7px">
            <span style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted);width:110px">Annuaire</span>
            ${c.rapCompo.annuaire.map(a2 => `<span ${x.A(a2.toggle)} title="${esc(a2.email)}${a2.poste ? ' · ' + esc(a2.poste) : ''}" style="display:inline-block;border-radius:999px;padding:4px 11px;font-size:11px;font-weight:500;cursor:pointer;${a2.on ? 'background:var(--color-primary);color:#fff' : 'background:var(--color-surface);border:0.5px solid var(--color-border-secondary);color:var(--color-text)'}">${esc(a2.nom)}</span>`).join('')}
          </div>` : ''}
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <input value="${esc(c.rapCompo.dest)}" ${x.C(c.rapCompo.setDest)} placeholder="${c.rapCompo.envoiMode === 'par-magasin' ? 'destinataires réseau — la version complète (consultant)' : 'destinataires (emails, virgules — ou cliquez l’annuaire)'}" style="flex:1;min-width:240px;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 9px;background:var(--color-surface);color:var(--color-text)">
            <button ${x.A(c.rapCompo.enregistrer)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:8px 16px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui);font-size:12px;font-weight:600;cursor:pointer">${c.rapCompo.edit ? 'Enregistrer les modifications' : 'Enregistrer comme rapport récurrent'}</button>
          </div>
        </div>
      </div>` : `
      ${c.rapGen.chargement ? `<div style="padding:16px 18px;font-size:12.5px;color:var(--color-text-muted)">Lecture des rapports…</div>`
        : (c.rapGen.indispo ? `<div style="padding:16px 18px;font-size:12.5px;color:var(--color-text-muted)">${esc(c.rapGen.indispo)}</div>` : `
      <div style="display:flex;flex-direction:column">
        ${c.rapGen.lignes.map(r => `
          <div style="display:flex;flex-direction:column;gap:8px;padding:13px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
              <span style="font-size:13px;font-weight:500">${esc(r.nom)}</span>
              <span style="font-size:11px;color:var(--color-text-muted)">${esc(r.poste)} · ${esc(r.freq)}</span>
              <span ${x.A(r.toggleActif)} style="${r.actifSt}">${r.actifTxt}</span>
              <span style="margin-left:auto;font-size:11.5px;color:var(--color-text-muted);white-space:nowrap">${esc(r.dernier)}</span>
              ${r.dernierStatut ? `<span style="${r.dernierSt}">${esc(r.dernierStatut)}</span>` : ''}
              ${r.ouvrirUrl ? `<a href="${r.ouvrirUrl}" target="_blank" rel="noopener" style="font-size:11.5px;font-weight:500;color:var(--color-primary)">Ouvrir</a>` : ''}
              <button ${x.A(r.gen)} style="border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:6px 10px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">${esc(r.genTxt)}</button>
              <button ${x.A(r.env)} style="border:none;border-radius:7px;padding:6px 10px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Envoyer</button>
              <button ${x.A(r.modifier)} title="Recharger ce rapport dans le compositeur" style="border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:6px 10px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Modifier</button>
              <button ${x.A(r.suppr)} title="Supprimer ce rapport" style="border:none;background:none;color:var(--color-text-muted);font-size:14px;cursor:pointer;padding:2px 4px">✕</button>
            </div>
            ${r.resume ? `<div style="font-size:11.5px;color:var(--color-text-muted)">${esc(r.resume)}</div>` : ''}
            <div style="display:flex;gap:5px;flex-wrap:wrap">
              ${r.blocs.map(b => `<span style="display:inline-flex;align-items:center;gap:5px;font-size:10.5px;border:0.5px solid var(--color-border-tertiary);border-radius:999px;padding:2px 8px;color:var(--color-text-muted)"><i style="width:7px;height:7px;border-radius:2px;background:${b.c};display:inline-block"></i>${esc(b.nom)}</span>`).join('')}
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
              <span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">À</span>
              <input value="${esc(r.destTxt)}" ${x.I(r.setDest)} placeholder="emails séparés par des virgules" style="flex:1;min-width:240px;font-size:11.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:5px 8px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui)">
              <span style="font-size:10.5px;color:var(--color-text-muted)">${esc(r.destAuto || '')}</span>
              <button ${x.A(r.saveDest)} style="border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:5px 10px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">Enregistrer</button>
            </div>
          </div>`).join('')}
      </div>
      ${c.rapGen.runs.length ? `
      <div style="padding:12px 18px">
        <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:6px">Dernières générations</div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12px">
          <select ${x.C(c.rapGen.setRun)} style="font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:7px;padding:6px 9px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui);max-width:100%">
            ${c.rapGen.runOptions.map(o => `<option value="${esc(o.v)}"${o.sel ? ' selected' : ''}>${esc(o.label)}</option>`).join('')}
          </select>
          ${c.rapGen.runSel ? `
            <span style="${c.rapGen.runSel.st}">${esc(c.rapGen.runSel.statut)}</span>
            ${c.rapGen.runSel.resume ? `<span style="color:var(--color-text-muted)">${esc(c.rapGen.runSel.resume)}</span>` : ''}
            <a href="${c.rapGen.runSel.url}" target="_blank" rel="noopener" style="margin-left:auto;font-size:11.5px;font-weight:500;color:var(--color-primary);white-space:nowrap">Ouvrir cette génération</a>` : ''}
        </div>
      </div>` : ''}`)}`}
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
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:16px 18px">
      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div>
          <div style="font-family:var(--font-display);font-size:16px;line-height:1.3">Écrans ouverts — 30 derniers jours</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">Nombre d'ouvertures, écran par écran et jour par jour. De quoi voir ce qui sert vraiment, et alléger le rail de ce qui ne sert pas.</div>
        </div>
        <div style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.vuesTotal || '')}</div>
      </div>
      ${c.vuesChargement ? '<div style="font-size:12px;color:var(--color-text-muted);margin-top:12px">Lecture des ouvertures…</div>' : (c.vuesVide
        ? '<div style="font-size:12px;color:var(--color-text-muted);margin-top:12px">Aucune ouverture enregistrée pour l’instant — la mesure commence à cette livraison.</div>'
        : `
      <div style="overflow-x:auto;margin-top:12px">
        <table style="width:100%;min-width:820px;border-collapse:collapse;font-size:12px">
          <thead><tr>
            <th style="text-align:left;font-size:9.5px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted);padding:0 8px 6px 0">Écran</th>
            <th style="text-align:right;font-size:9.5px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted);padding:0 10px 6px 0;width:70px">Ouvertures</th>
            ${c.vuesJours.map((j, i) => `<th style="padding:0 1px 6px;font-size:8px;font-weight:500;color:var(--color-text-muted);text-align:center">${i % 5 === 0 ? esc(j.court) : ''}</th>`).join('')}
          </tr></thead>
          <tbody>
            ${c.vuesLignes.map(l => `<tr>
              <td style="padding:3px 8px 3px 0;white-space:nowrap">${esc(l.nom)}${l.qui ? `<div style="font-size:9.5px;color:var(--color-text-muted)">${esc(l.qui)}</div>` : ''}</td>
              <td style="padding:3px 10px 3px 0;text-align:right;font-weight:600;font-variant-numeric:tabular-nums">${l.total}</td>
              ${l.cases.map(k2 => `<td style="padding:2px 1px" title="${esc(k2.jour)} — ${k2.n} ouverture(s)"><i style="${k2.st}"></i></td>`).join('')}
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
      ${c.vuesJamais ? `<div style="font-size:11px;color:var(--color-text-muted);margin-top:10px">Jamais ouverts sur la période : ${esc(c.vuesJamais)}</div>` : ''}`)}
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <select ${x.C(c.setLogPeriode)} style="${selCss}">${opts(c.logPeriodes, c.logPeriode)}</select>
      <select ${x.C(c.setLogQui)} style="${selCss}">${opts(c.logQuis, c.logQui)}</select>
      <select ${x.C(c.setLogType)} style="${selCss}">${opts(c.logTypes, c.logType)}</select>
      <select ${x.C(c.setLogProjet)} style="${selCss}">${opts(c.logProjets, c.logProjet)}</select>
      <input type="text" id="log-search" placeholder="Rechercher (projet, tâche, magasin…)" value="${esc(c.logQ)}" ${x.I(c.setLogQ)} style="flex:1;max-width:340px;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 10px;background:var(--color-surface);color:var(--color-text)">
      <span style="margin-left:auto;display:flex;align-items:center;gap:10px">
        <span style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.logCompte || '')}</span>
        <button ${x.A(c.exportCsv)} style="border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:7px 14px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Exporter CSV</button>
      </span>
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
        <div style="font-size:13px;font-weight:500;margin-bottom:12px">Réputation digitale</div>
        <label style="font-size:12px;color:var(--color-text-muted)">Note Google visée (sur 5)
          <input type="number" min="1" max="5" step="0.1" value="${c.repCibleVal}" ${x.C(c.setRepCible)} style="${inputCss}">
        </label>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:10px">Sert de cible à l’écran Réputation digitale : un magasin sous cette note affiche le nombre d’avis 5★ qu’il lui faudrait pour y revenir.</div>
        <div style="border-top:0.5px solid var(--color-border-tertiary);margin:14px 0 12px"></div>
        <label style="font-size:12px;color:var(--color-text-muted)">Clé API Google (Places API)
          <input type="password" autocomplete="off" placeholder="${c.gCleDefinie ? 'Clé enregistrée — saisir pour la remplacer' : 'AIza…'}" ${x.C(c.setGCle)} style="${inputCss}">
        </label>
        <div style="display:flex;align-items:center;gap:10px;margin-top:8px">
          <span style="font-size:11.5px;color:${c.gCleDefinie ? '#2d7a3e' : 'var(--color-text-muted)'};font-weight:500">${c.gCleDefinie ? 'Clé en place · ' + esc(c.gEmpreinte) : 'Aucune clé'}</span>
          ${c.gCleDefinie ? `<button ${x.A(c.gEffacer)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text);border-radius:999px;height:26px;padding:0 11px;font-family:var(--font-ui);font-size:11px;cursor:pointer">Effacer</button>` : ''}
        </div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:10px;text-wrap:pretty">La clé ne quitte pas le serveur : l’écran n’en reçoit qu’une empreinte. Activez « Places API (New) » sur le projet Google et restreignez la clé à cette API.</div>
      </div>
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px">
          <div style="font-size:13px;font-weight:500">Envoi des rapports — machine SMTP</div>
          <span style="${c.sm.etatSt}">${esc(c.sm.etatTxt)}</span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 110px 130px;gap:12px">
          <label style="font-size:12px;color:var(--color-text-muted)">Hôte SMTP
            <input value="${esc(c.sm.hote)}" ${x.C(c.sm.setHote)} placeholder="smtp.gmail.com" style="${inputCss}">
          </label>
          <label style="font-size:12px;color:var(--color-text-muted)">Port
            <input type="number" value="${esc(c.sm.port)}" ${x.C(c.sm.setPort)} style="${inputCss}">
          </label>
          <label style="font-size:12px;color:var(--color-text-muted)">Sécurité
            <select ${x.C(c.sm.setSecurite)} style="${inputCss}">
              ${['tls', 'ssl', 'aucune'].map(v => `<option value="${v}"${c.sm.securite === v ? ' selected' : ''}>${v === 'tls' ? 'STARTTLS (587)' : v === 'ssl' ? 'SSL (465)' : 'Aucune'}</option>`).join('')}
            </select>
          </label>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px">
          <label style="font-size:12px;color:var(--color-text-muted)">Utilisateur
            <input value="${esc(c.sm.utilisateur)}" ${x.C(c.sm.setUtilisateur)} autocomplete="off" placeholder="compte@atelierby.be" style="${inputCss}">
          </label>
          <label style="font-size:12px;color:var(--color-text-muted)">Mot de passe
            <input type="password" ${x.C(c.sm.setMdp)} autocomplete="new-password" placeholder="${c.sm.mdpDefini ? 'En place — saisir pour remplacer' : 'mot de passe ou mot de passe d’application'}" style="${inputCss}">
          </label>
        </div>
        <label style="display:block;font-size:12px;color:var(--color-text-muted);margin-top:10px">Expéditeur
          <input value="${esc(c.sm.expediteur)}" ${x.C(c.sm.setExpediteur)} placeholder="Cockpit L’Atelier By &lt;rapports@atelierby.be&gt;" style="${inputCss}">
        </label>
        <div style="display:flex;align-items:center;gap:10px;margin-top:12px;flex-wrap:wrap">
          <button ${x.A(c.sm.save)} style="border:none;border-radius:999px;padding:8px 16px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;${c.sm.busy ? 'opacity:.6' : ''}">${c.sm.busy ? 'En cours…' : 'Enregistrer'}</button>
          <input value="${esc(c.sm.testA)}" ${x.C(c.sm.setTestA)} placeholder="adresse de test" style="flex:1;min-width:180px;box-sizing:border-box;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 10px;background:var(--color-surface);color:var(--color-text)">
          <button ${x.A(c.sm.test)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:8px 14px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Envoyer un test</button>
        </div>
        ${c.sm.msg ? `<div style="${c.sm.msgSt}">${esc(c.sm.msg)}</div>` : ''}
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:10px;text-wrap:pretty">Le mot de passe ne quitte pas le serveur (ceo_app_setting.smtp) — l’écran ne le relit jamais. Sans SMTP configuré, l’envoi des rapports retombe sur mail() du serveur. Gmail : créez un « mot de passe d’application », hôte smtp.gmail.com, port 587, STARTTLS.</div>
      </div>
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
        <div style="font-size:13px;font-weight:500;margin-bottom:4px">Catalogue des KPI</div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:12px;text-wrap:pretty">Le référentiel qui pilote les écrans et les rapports : un seuil changé ici s’applique partout au prochain calcul. « Alerte » déclenche la ligne dans les rapports, « Critique » la marque en rouge vif.</div>
        ${c.kpiCat.chargement ? `<div style="font-size:12.5px;color:var(--color-text-muted)">Lecture du référentiel…</div>` : `
        <div style="display:flex;flex-direction:column">
          ${c.kpiCat.lignes.map(k => `
            <div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:0.5px solid var(--color-border-tertiary);flex-wrap:wrap">
              <div style="flex:1;min-width:230px">
                <span style="font-size:12.5px;font-weight:500"><i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${k.levCoul};margin-right:6px;vertical-align:0"></i>${esc(k.nom)}</span>
                <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:2px">${esc(k.type)} · ${esc(k.levNom)}</div>
              </div>
              <label style="font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.05em">Alerte
                <input value="${esc(k.alerte)}" ${x.C(k.setAlerte)} style="display:block;width:74px;box-sizing:border-box;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:5px 7px;background:var(--color-surface);color:var(--color-text);text-align:right">
              </label>
              <label style="font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.05em">Critique
                <input value="${esc(k.critique)}" ${x.C(k.setCritique)} style="display:block;width:74px;box-sizing:border-box;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:5px 7px;background:var(--color-surface);color:var(--color-text);text-align:right">
              </label>
              <label style="font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.05em">Mauvais
                <select ${x.C(k.setSens)} style="display:block;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:5px 7px;background:var(--color-surface);color:var(--color-text)">
                  <option value="haut"${k.sens === 'haut' ? ' selected' : ''}>au-dessus</option>
                  <option value="bas"${k.sens === 'bas' ? ' selected' : ''}>en dessous</option>
                </select>
              </label>
              <label style="font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.05em">Sortie
                <select ${x.C(k.setSortie)} style="display:block;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:5px 7px;background:var(--color-surface);color:var(--color-text)">
                  ${c.kpiCat.sorties.map(so => `<option value="${so.val}"${k.sortie === so.val ? ' selected' : ''}>${esc(so.nom)}</option>`).join('')}
                </select>
              </label>
              <span ${x.A(k.toggle)} style="${k.actifSt}">${k.actifTxt}</span>
              ${k.supprimable ? `<button ${x.A(k.suppr)} title="Supprimer ce KPI dérivé" style="border:none;background:none;color:var(--color-text-muted);font-size:13px;cursor:pointer;padding:2px 4px">✕</button>` : `<span style="width:21px"></span>`}
            </div>`).join('')}
        </div>
        <div style="margin-top:14px;padding:13px 15px;background:var(--color-background-secondary);border-radius:10px">
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:9px">Nouveau KPI — une formule sur les mesures du mois</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <input value="${esc(c.kpiCat.d.nom)}" ${x.C(c.kpiCat.set.nom)} placeholder="Nom du KPI" style="flex:2;min-width:170px;font-size:12.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 9px;background:var(--color-surface);color:var(--color-text)">
            <select ${x.C(c.kpiCat.set.num)} style="flex:1;min-width:150px;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 8px;background:var(--color-surface);color:var(--color-text)">${c.kpiCat.mesures.map(mm => `<option value="${mm.val}"${c.kpiCat.d.num === mm.val ? ' selected' : ''}>${esc(mm.nom)}</option>`).join('')}</select>
            <span style="font-size:14px;color:var(--color-text-muted);padding-bottom:7px">÷</span>
            <select ${x.C(c.kpiCat.set.den)} style="flex:1;min-width:150px;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 8px;background:var(--color-surface);color:var(--color-text)">${c.kpiCat.mesures.map(mm => `<option value="${mm.val}"${c.kpiCat.d.den === mm.val ? ' selected' : ''}>${esc(mm.nom)}</option>`).join('')}</select>
            <select ${x.C(c.kpiCat.set.echelle)} style="font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 8px;background:var(--color-surface);color:var(--color-text)">
              <option value="unite"${c.kpiCat.d.echelle === 'unite' ? ' selected' : ''}>unité</option>
              <option value="eur"${c.kpiCat.d.echelle === 'eur' ? ' selected' : ''}>€</option>
              <option value="pct"${c.kpiCat.d.echelle === 'pct' ? ' selected' : ''}>× 100 (%)</option>
            </select>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:8px">
            <select ${x.C(c.kpiCat.set.levier)} style="flex:1;min-width:140px;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 8px;background:var(--color-surface);color:var(--color-text)">${c.kpiCat.leviers.map(lv => `<option value="${lv.val}"${c.kpiCat.d.levier === lv.val ? ' selected' : ''}>${esc(lv.nom)}</option>`).join('')}</select>
            <input value="${esc(c.kpiCat.d.alerte)}" ${x.C(c.kpiCat.set.alerte)} placeholder="Seuil alerte" style="width:96px;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 8px;background:var(--color-surface);color:var(--color-text);text-align:right">
            <input value="${esc(c.kpiCat.d.critique)}" ${x.C(c.kpiCat.set.critique)} placeholder="Critique" style="width:82px;font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 8px;background:var(--color-surface);color:var(--color-text);text-align:right">
            <select ${x.C(c.kpiCat.set.sens)} style="font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 8px;background:var(--color-surface);color:var(--color-text)">
              <option value="bas"${c.kpiCat.d.sens === 'bas' ? ' selected' : ''}>mauvais en dessous</option>
              <option value="haut"${c.kpiCat.d.sens === 'haut' ? ' selected' : ''}>mauvais au-dessus</option>
            </select>
            <select ${x.C(c.kpiCat.set.sortie)} style="font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 8px;background:var(--color-surface);color:var(--color-text)">
              ${c.kpiCat.sorties.map(so => `<option value="${so.val}"${c.kpiCat.d.sortie === so.val ? ' selected' : ''}>Sortie : ${esc(so.nom)}</option>`).join('')}
            </select>
            <button ${x.A(c.kpiCat.creer)} style="border:none;border-radius:999px;padding:8px 16px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Créer le KPI</button>
          </div>
          <div style="font-size:11px;color:var(--color-text-muted);margin-top:8px;text-wrap:pretty">Évalué par magasin sur le mois en cours, dès le prochain rapport (bloc « KPI personnalisés »). Un KPI qui exige une nouvelle source API reste un ajout de code — sa fiche portera « source à câbler ».</div>
        </div>`}
      </div>
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:4px">
          <div style="font-size:13px;font-weight:500">Cadence dynamique des contrôles</div>
          <span ${x.A(c.cad.toggle)} style="${c.cad.actifSt}">${c.cad.actifTxt}</span>
        </div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:12px;text-wrap:pretty">Une tâche bien tenue se contrôle moins souvent ; une tâche qui glisse revient au quotidien. La cadence se calcule par tâche et par magasin, sur les notes réelles du panel.</div>
        ${c.cad.chargement ? `<div style="font-size:12.5px;color:var(--color-text-muted)">Lecture des règles…</div>` : `
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
          <label style="font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.05em">Contrôles consécutifs
            <input value="${esc(c.cad.serie)}" ${x.C(c.cad.setSerie)} style="display:block;width:64px;box-sizing:border-box;font-size:12.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 8px;background:var(--color-surface);color:var(--color-text);text-align:center;margin-top:4px">
          </label>
          <label style="font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.05em">Moyenne exigée (≥)
            <input value="${esc(c.cad.moyenneMin)}" ${x.C(c.cad.setMoyenneMin)} style="display:block;width:64px;box-sizing:border-box;font-size:12.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 8px;background:var(--color-surface);color:var(--color-text);text-align:center;margin-top:4px">
          </label>
          <label style="font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.05em" title="Une note sous cette valeur remet la série de promotion à zéro">Casse la série (&lt;)
            <input value="${esc(c.cad.noteCasse)}" ${x.C(c.cad.setNoteCasse)} style="display:block;width:64px;box-sizing:border-box;font-size:12.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 8px;background:var(--color-surface);color:var(--color-text);text-align:center;margin-top:4px">
          </label>
          <label style="font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.05em" title="Une note à cette valeur ou en dessous ramène la tâche au quotidien, sans étape">Rechute (≤)
            <input value="${esc(c.cad.noteRechute)}" ${x.C(c.cad.setNoteRechute)} style="display:block;width:64px;box-sizing:border-box;font-size:12.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 8px;background:var(--color-surface);color:var(--color-text);text-align:center;margin-top:4px">
          </label>
          <label style="font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.05em">Paliers (jours)
            <input value="${esc(c.cad.paliersTxt)}" ${x.C(c.cad.setPaliers)} placeholder="1, 2, 3, 5, 7" style="display:block;width:120px;box-sizing:border-box;font-size:12.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 8px;background:var(--color-surface);color:var(--color-text);text-align:center;margin-top:4px">
          </label>
          <label style="font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.05em">Périmètre
            <select ${x.C(c.cad.setPerimetre)} style="display:block;font-size:12.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:6px 8px;background:var(--color-surface);color:var(--color-text);margin-top:4px">
              <option value="par-magasin"${c.cad.perimetre === 'par-magasin' ? ' selected' : ''}>Par magasin</option>
              <option value="reseau"${c.cad.perimetre === 'reseau' ? ' selected' : ''}>Réseau entier</option>
            </select>
          </label>
        </div>
        <div style="display:flex;align-items:center;gap:7px;margin-top:11px;flex-wrap:wrap">
          ${c.cad.paliersVis.map((pj, i) => `${i ? '<span style="color:var(--color-text-muted)">→</span>' : ''}<span style="display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:30px;border-radius:8px;border:${i === 0 ? '1.5px solid var(--color-primary);background:rgba(141,29,44,0.07);color:var(--color-primary)' : '0.5px solid var(--color-border-secondary)'};font-size:12px;font-weight:600">${pj} j</span>`).join('')}
          <span style="font-size:11px;color:var(--color-text-muted)">maximum ${c.cad.palierMax} jours — une tâche ne sort jamais du radar</span>
        </div>
        <div style="border-top:0.5px solid var(--color-border-tertiary);margin:14px 0 12px"></div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px">
          <div>
            <span style="font-size:12.5px;font-weight:500">Plan de contrôle</span>
            <span style="font-size:11px;color:var(--color-text-muted);margin-left:8px">${esc(c.cad.planInfo)}</span>
          </div>
          <button ${x.A(c.cad.recalc)} style="border:none;border-radius:999px;padding:7px 14px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer;${c.cad.busy ? 'opacity:.6' : ''}">${c.cad.busy ? 'Calcul en cours… (historique API)' : 'Recalculer (' + c.cad.semaines + ' semaines)'}</button>
        </div>
        ${c.cad.planLignes.length === 0 ? `<div style="font-size:12px;color:var(--color-text-muted)">${c.cad.busy ? 'Lecture de l’historique des contrôles du panel…' : 'Aucun plan calculé — « Recalculer » lit l’historique des contrôles et pose le palier de chaque tâche.'}</div>` : `
        <div style="overflow-x:auto"><table style="border-collapse:collapse;width:100%;min-width:640px">
          <tr>${['Tâche · magasin', '5 derniers', 'Moyenne', 'Cadence', 'Prochain', 'Mouvement'].map(h => `<th style="text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted);padding:7px 9px;border-bottom:0.5px solid var(--color-border-secondary)">${h}</th>`).join('')}</tr>
          ${c.cad.planLignes.map(l => `
          <tr>
            <td style="padding:8px 9px;border-bottom:0.5px solid var(--color-border-tertiary);font-size:12px"><b>${esc(l.tache)}</b><br><span style="color:var(--color-text-muted);font-size:11px">${esc(l.magasin)}</span></td>
            <td style="padding:8px 9px;border-bottom:0.5px solid var(--color-border-tertiary);white-space:nowrap">${l.notes.map(nn => `<span style="display:inline-flex;width:21px;height:21px;border-radius:5px;align-items:center;justify-content:center;font-size:10.5px;font-weight:700;color:#fff;margin-right:3px;background:${nn.coul}">${nn.n}</span>`).join('')}</td>
            <td style="padding:8px 9px;border-bottom:0.5px solid var(--color-border-tertiary);font-size:12px;font-weight:600">${esc(l.moyenne)} / 5</td>
            <td style="padding:8px 9px;border-bottom:0.5px solid var(--color-border-tertiary);font-size:12px">${esc(l.cadence)}</td>
            <td style="padding:8px 9px;border-bottom:0.5px solid var(--color-border-tertiary);font-size:12px;${l.du ? 'color:var(--color-primary);font-weight:600' : ''}">${esc(l.prochain)}</td>
            <td style="padding:8px 9px;border-bottom:0.5px solid var(--color-border-tertiary)"><span style="${l.mouvSt}">${esc(l.mouvTxt)}</span></td>
          </tr>`).join('')}
        </table></div>`}
        <div style="font-size:11px;color:var(--color-text-muted);margin-top:10px;text-wrap:pretty">Les notes viennent du panel (contrôles réels) ; le palier et la série vivent côté cockpit. Chaque changement de règle est journalisé ; le plan se recalcule sur demande.</div>`}
      </div>
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:4px">
          <div style="font-size:13px;font-weight:500">Écriture de la planification — contrat API à prévoir côté panel</div>
          <span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:500;background:rgba(193,122,42,0.16);color:#8a5a13">manque API</span>
        </div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:10px;text-wrap:pretty">${esc(c.cad.apiNote)}</div>
        <pre style="margin:0;background:var(--color-background-secondary);border-radius:10px;padding:13px 15px;font-size:11.5px;line-height:1.55;overflow-x:auto;color:var(--color-text)">${esc(c.cad.apiPre)}</pre>
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
  <div data-scroll="modale-large" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(880px,94vw);max-height:90vh;overflow-y:auto;background:var(--color-background-secondary);border-radius:14px;z-index:81;box-shadow:0 24px 60px rgba(34,34,34,0.3);padding:20px">
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
  <div data-scroll="modale-detail" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:680px;max-height:86vh;overflow-y:auto;background:var(--color-surface);border-radius:14px;z-index:81;box-shadow:0 24px 60px rgba(34,34,34,0.25);padding:26px 28px">
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
  <div data-scroll="wizard-projet" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:860px;max-height:88vh;overflow-y:auto;background:var(--color-surface);border-radius:14px;z-index:81;box-shadow:0 24px 60px rgba(34,34,34,0.25);padding:26px 28px">
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
          <div style="grid-column:1 / -1">
            <div style="${lbl}">Levier principal</div>
            <div style="display:flex;gap:7px;flex-wrap:wrap">
              ${(c.npLevChips || []).map(l => `<button ${x.A(l.go)} title="${esc(l.desc)}" style="${l.st}">
                <span style="${l.dotSt}"></span>${esc(l.nom)}</button>`).join('')}
            </div>
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

        <!-- L'économie du projet : trois saisies, le reste se déduit et reste
             modifiable. Un chiffre corrigé à la main n'est plus recalculé. -->
        <div style="border-top:0.5px solid var(--color-border-tertiary);padding-top:14px">
          <div style="${lbl};margin-bottom:2px">Économie du projet</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:10px">Ce que le projet rapporte, et à qui. Les trois premières cases suffisent : le reste se calcule et se corrige.</div>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
            <div><div style="${lbl}">Marge ciblée franchisé (%)</div>
              <input type="number" step="0.1" min="0" max="100" value="${esc(String(c.npEco.margeCible))}" ${x.C(c.npEco.setMargeCible)} placeholder="Ex. 62" style="${inpD}"></div>
            <div><div style="${lbl}">Prix de vente global (€)</div>
              <input type="number" step="0.01" min="0" value="${esc(String(c.npEco.prixVente))}" ${x.C(c.npEco.setPrixVente)} placeholder="Ex. 3,90" style="${inpD}"></div>
            <div><div style="${lbl}">Volume prévisionnel / magasin</div>
              <input type="number" step="1" min="0" value="${esc(String(c.npEco.volMag))}" ${x.C(c.npEco.setVolMag)} placeholder="pièces / an" style="${inpD}"></div>
            <div><div style="${lbl}">Volume prévisionnel réseau${c.npEco.volReseauAuto ? ' <span style="text-transform:none;letter-spacing:0;font-weight:400">— calculé</span>' : ''}</div>
              <input type="number" step="1" min="0" value="${esc(String(c.npEco.volReseau))}" ${x.C(c.npEco.setVolReseau)} placeholder="${esc(c.npEco.volReseauAide)}" style="${inpD}"></div>
            <div><div style="${lbl}">Retour royalties marque (€)${c.npEco.royaltiesAuto ? ' <span style="text-transform:none;letter-spacing:0;font-weight:400">— calculé</span>' : ''}</div>
              <input type="number" step="1" min="0" value="${esc(String(c.npEco.royaltiesEuro))}" ${x.C(c.npEco.setRoyaltiesEuro)} placeholder="au taux ${esc(String(c.npEco.royaltiesTaux || '—'))} %" style="${inpD}"></div>
            <div><div style="${lbl}">Marge moyenne / magasin / an (€)${c.npEco.margeMagAuto ? ' <span style="text-transform:none;letter-spacing:0;font-weight:400">— calculée</span>' : ''}</div>
              <input type="number" step="1" min="0" value="${esc(String(c.npEco.margeMagAn))}" ${x.C(c.npEco.setMargeMagAn)} placeholder="prix × volume × marge" style="${inpD}"></div>
          </div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:9px;line-height:1.5">${esc(c.npEco.resume)}</div>
        </div>
      </div>` : ''}
      ${c.npS3 ? `
      <div style="display:flex;flex-direction:column;gap:14px">
        <div>
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px">
            <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Rétroplanning — jalons</div>
          </div>
          <!-- Plusieurs modèles : un projet produit et une ouverture ne se
               jalonnent pas pareil, et un même projet peut emprunter aux deux.
               Les jalons s'AJOUTENT, ils ne remplacent pas. -->
          <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:10px">
            <span style="font-size:11px;color:var(--color-text-muted);align-self:center">Modèles :</span>
            ${(c.npJalonModeles || []).map(m2 => `<button ${x.A(m2.charger)} title="Ajoute les ${m2.n} jalons du modèle « ${esc(m2.axe)} », datés depuis l’échéance" style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:5px 12px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">+ ${esc(m2.axe)} <span style="color:var(--color-text-muted)">(${m2.n})</span></button>`).join('')}
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
  <div data-scroll="modale-note" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:760px;max-height:88vh;overflow-y:auto;background:var(--color-surface);border-radius:14px;z-index:81;box-shadow:0 24px 60px rgba(34,34,34,0.25);padding:26px 28px">
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
            ? `<button ${x.A(d.zoomGo)} title="Agrandir et poser des repères" style="border:none;background:none;padding:0;display:block;width:100%;cursor:zoom-in;position:relative"><img src="${d.photo}" alt="Photo de réalisation" style="width:100%;border-radius:10px;border:0.5px solid var(--color-border-tertiary);display:block">
                <span style="position:absolute;left:7px;bottom:7px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;padding:3px 9px;border-radius:999px;background:rgba(20,16,14,0.72);color:#fff">${d.nRep ? d.nRep + ' repère' + (d.nRep > 1 ? 's' : '') : 'annoter'}</span></button>
               <a href="${d.photo}" target="_blank" rel="noopener" style="display:inline-block;margin-top:5px;font-size:10.5px;color:var(--color-text-muted)">fichier d’origine</a>`
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

      <!-- Assistance IA : une PROPOSITION, jamais une note. Elle pré-remplit,
           le consultant valide — c'est son geste qui engage le réseau. -->
      ${d.photo ? `<div style="margin:20px 0 0;padding:12px 14px;border:0.5px solid var(--color-border-tertiary);border-radius:10px;background:var(--color-background-secondary)">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <div style="${'font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)'};flex:1;min-width:150px">Assistance à la notation</div>
          ${d.iaDispo
            ? `<button ${x.A(d.iaGo)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);border-radius:999px;padding:7px 15px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:${d.iaBusy ? 'wait' : 'pointer'};opacity:${d.iaBusy ? '0.6' : '1'};color:var(--color-text)">${d.iaBusy ? 'Analyse de la photo…' : (d.iaFait ? 'Réanalyser' : 'Proposer une évaluation')}</button>`
            : `<span style="font-size:11px;font-weight:500;padding:2px 8px;border-radius:999px;background:#FBEFE0;color:var(--color-on-abricot);border:1px solid #E8C9A0">manque API</span>`}
        </div>
        ${!d.iaDispo ? `<div style="font-size:11.5px;color:var(--color-text-muted);margin-top:7px;line-height:1.5">Aucune clé Anthropic enregistrée. Paramètres → Assistance IA. La clé reste sur le serveur ; le navigateur ne reçoit que la proposition.</div>` : ''}
        ${d.iaMotif ? `<div style="font-size:11.5px;color:var(--color-on-abricot);margin-top:7px;line-height:1.5">${esc(d.iaMotif)}</div>` : ''}
        ${d.iaNom ? `<div style="margin-top:9px">
          <div style="font-size:12.5px"><span style="font-weight:500">Proposition : ${esc(d.iaNom)}</span>${d.iaConfiance ? `<span style="color:var(--color-text-muted)"> · confiance ${esc(d.iaConfiance)}</span>` : ''}${d.iaModele ? `<span style="color:var(--color-text-muted)"> · ${esc(d.iaModele)}</span>` : ''}</div>
          ${(d.iaConstats || []).length ? `<ul style="margin:6px 0 0;padding-left:17px;font-size:12px;color:var(--color-text-muted);line-height:1.55">${d.iaConstats.map(k => `<li>${esc(k)}</li>`).join('')}</ul>` : ''}
          ${d.iaAvert ? `<div style="font-size:11.5px;color:var(--color-on-abricot);margin-top:7px;line-height:1.5">${esc(d.iaAvert)}</div>` : ''}
          <div style="display:flex;gap:9px;margin-top:10px;flex-wrap:wrap">
            <button ${x.A(d.iaAppliquer)} style="border:none;background:var(--color-primary);color:#fff;border-radius:999px;padding:7px 15px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Reprendre cette proposition</button>
            <span style="font-size:11px;color:var(--color-text-muted);align-self:center">rien n’est enregistré tant que vous n’envoyez pas la note</span>
          </div>
        </div>` : ''}
      </div>` : ''}

      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin:22px 0 8px">
        <div style="${'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)'}">Votre évaluation</div>
        ${d.verdict ? `<span style="${d.verdictSt}">${esc(d.verdict)}</span>` : ''}
      </div>
      ${d.niveauDeduit ? `<div style="display:flex;gap:8px;align-items:flex-start;margin:2px 0 9px;font-size:11.5px;line-height:1.5;color:var(--color-text-muted)">
        <span style="width:9px;height:9px;border-radius:2px;background:${d.niveauDeduitCol};flex:0 0 auto;margin-top:4px"></span>
        <div>Déduit des repères : <span style="font-weight:600;color:var(--color-text)">${esc(d.niveauDeduit)}</span> — le plus sévère l’emporte. Choisissez un niveau pour le remplacer.</div>
      </div>` : ''}
      ${d.niveaux.map(lv => `
        <button ${x.A(lv.pick)} style="${lv.st}">
          <span style="${lv.dotSt}"></span>
          <span style="flex:1">${esc(lv.nom)}${lv.deduit ? ' <span style="font-weight:400;font-size:11px;color:var(--color-text-muted)">— déduit</span>' : ''}</span>
          ${lv.aide ? `<span style="font-size:11px;color:var(--color-text-muted);font-weight:400">${esc(lv.aide)}</span>` : ''}
        </button>`).join('')}
      <textarea ${x.C(d.setComment)} rows="4" placeholder="${d.commentRequis ? 'Commentaire obligatoire pour une non-conformité' : 'Commentaire (facultatif)'}" style="width:100%;box-sizing:border-box;margin-top:10px;font-size:13px;border:0.5px solid ${d.commentRequis && !d.comment ? '#8D1D2C' : 'var(--color-border-secondary)'};border-radius:8px;padding:10px 12px;background:var(--color-surface);color:var(--color-text);resize:vertical;line-height:1.55">${esc(d.comment)}</textarea>
      ${d.erreur ? `<div style="margin-top:10px;padding:9px 12px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:12px">${esc(d.erreur)}</div>` : ''}
      <!-- Note au consultant : un geste occasionnel, replié par défaut. -->
      <div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:14px;padding-top:11px">
        <button ${x.A(d.cn.basculer)} style="border:none;background:none;padding:0;cursor:pointer;font-family:var(--font-ui);font-size:12px;font-weight:500;color:var(--color-primary)">
          ${d.cn.ouvert ? '− Note au consultant' : '+ Ajouter une note au consultant'}</button>
        ${d.cn.ouvert ? `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:10px">
          <div><div style="font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:4px">Consultant</div>
            <select ${x.C(d.cn.setQui)} style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);border-radius:8px;height:32px;padding:0 9px;font-family:var(--font-ui);font-size:12px;background:var(--color-surface);color:var(--color-text)">
              ${d.cn.consultants.map(o => `<option value="${esc(o.id)}"${o.id === d.cn.qui ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></div>
          <div><div style="font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:4px">Type de note</div>
            <select ${x.C(d.cn.setType)} style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);border-radius:8px;height:32px;padding:0 9px;font-family:var(--font-ui);font-size:12px;background:var(--color-surface);color:var(--color-text)">
              ${d.cn.types.map(t => `<option${t === d.cn.type ? ' selected' : ''}>${esc(t)}</option>`).join('')}</select></div>
        </div>
        <textarea id="cn-note" ${x.I(d.cn.setTexte)} rows="3" placeholder="Ce que le consultant doit reprendre, rappeler ou savoir" style="width:100%;box-sizing:border-box;margin-top:9px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:8px 10px;font-family:var(--font-ui);font-size:12px;line-height:1.5;background:var(--color-surface);color:var(--color-text);resize:vertical">${esc(d.cn.texte)}</textarea>
        <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap;margin-top:9px">
          ${d.cn.commeBtns.map(b2 => `<button ${x.A(b2.pick)} style="border-radius:999px;height:29px;padding:0 12px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer;${b2.on ? 'border:none;background:var(--color-primary);color:#fff' : 'border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text-muted)'}">${esc(b2.nom)}</button>`).join('')}
          ${d.cn.comme === 'tache' ? `<span style="font-size:10.5px;color:var(--color-text-muted)">échéance</span>
            <input type="date" value="${esc(d.cn.due)}" ${x.C(d.cn.setDue)} style="border:0.5px solid var(--color-border-secondary);border-radius:8px;height:30px;padding:0 8px;font-family:var(--font-ui);font-size:11.5px;background:var(--color-surface);color:var(--color-text)">` : ''}
          <div style="flex:1"></div>
          <button ${x.A(d.cn.envoyer)} style="border:none;background:var(--color-primary);color:#fff;border-radius:999px;height:31px;padding:0 15px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:${d.cn.busy ? 'wait' : 'pointer'}">${d.cn.busy ? 'Envoi…' : 'Envoyer'}</button>
        </div>
        <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:7px;line-height:1.45">Envoyée comme tâche, la note vit dans le projet « Suivi consultants » et suit le circuit normal — échéance, relance, validation. L’API du panel n’expose pas de dépôt de tâche consultant (manque API) : la tâche vit dans le cockpit.</div>
        ` : ''}
      </div>

      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
        <button ${x.A(d.close)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:9px 18px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">Fermer</button>
        ${d.peutNoter ? `<button ${x.A(d.send)} style="border:none;border-radius:999px;padding:9px 20px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:${d.envoi ? 'wait' : 'pointer'};opacity:${d.envoi ? '0.6' : '1'}">${d.envoi ? 'Envoi…' : 'Envoyer la note'}</button>` : ''}
      </div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:10px;line-height:1.5">La note part sur l\u2019API du panel (source de v\u00e9rit\u00e9) et est recopi\u00e9e dans le journal des avis.</div>
    </div>
  </div>`;
}

/* --- Le comptoir dessiné : meubles en colonnes, niveaux en lignes ------------
   Un planogramme se regarde, il ne se lit pas en liste : un trou de facing se
   voit sur un plan et se cherche dans un tableau. Les cases pointillées sont
   libres, les pleines portent leur produit. La structure se déclare ici même —
   l'API du panel n'expose ni zone, ni meuble, ni emplacement (mesuré), donc
   attendre une API aurait laissé l'écran vide indéfiniment. */
function tplPlanoComptoir(c, x){
  const { esc } = x;
  // Style de cellule local : `TD` n'existe qu'à l'intérieur d'autres gabarits.
  // Le lire d'ici passait le lint et cassait l'écran à l'exécution.
  const TD = 'padding:9px 14px;font-size:12.5px;vertical-align:top';
  const lbl = 'font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.09em;color:var(--color-text-muted)';
  const inp = 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);'
    + 'border-radius:7px;height:29px;padding:0 9px;font-family:var(--font-ui);font-size:12px;box-sizing:border-box';
  const btn = (on, dispo) => 'border-radius:8px;height:30px;padding:0 11px;font-family:var(--font-ui);font-size:11.5px;'
    + 'font-weight:500;white-space:nowrap;' + (dispo === false ? 'cursor:not-allowed;opacity:0.45;' : 'cursor:pointer;')
    + (on ? 'border:none;background:var(--color-primary);color:#fff'
          : 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text)');
  if (c.plChargement) {
    return `<div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:22px;margin-bottom:14px;font-size:12.5px;color:var(--color-text-muted)">Lecture du comptoir…</div>`;
  }
  return `
  <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:15px 17px;margin-bottom:14px">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <div style="${lbl}">Le comptoir</div>
      ${c.plVueBtns.map(v => `<button ${x.A(v.go)} style="${btn(v.on)}">${esc(v.nom)}</button>`).join('')}
      ${c.plVue === 'plan' ? `<span style="width:1px;height:20px;background:var(--color-border-tertiary)"></span>
        ${c.plZonesOpts.map(z => `<button ${x.A(z.go)} style="${btn(z.on)}">${esc(z.nom)}</button>`).join('')}
        <!-- Le comptoir se regarde à une heure donnée : à midi, la vitrine à
             viennoiseries n'est plus montée. Les meubles sans moment déclaré
             sont là toute la journée. -->
        ${(c.plPeriodesOpts || []).length > 1 ? `<span style="width:1px;height:20px;background:var(--color-border-tertiary)"></span>
          ${c.plPeriodesOpts.map(pr => `<button ${x.A(pr.go)} title="${esc(pr.aide)}" style="${btn(pr.on)}">${esc(pr.nom)}</button>`).join('')}` : ''}` : ''}
      <div style="flex:1"></div>
      <span style="font-size:11.5px;color:var(--color-text-muted)">${c.plTot.slots} emplacement(s) · ${c.plTot.libres} libre(s) · ${c.plTot.places} placée(s)</span>
      <button ${x.A(c.plImprimer)} title="Imprimer tout le comptoir, zones comprises" style="${btn(false, !!c.plImprimer)}">⎙ Imprimer</button>
      <button ${x.A(c.plOrgGo)} style="${btn(c.plOrg)}">${c.plOrg ? 'Masquer l’organisation' : 'Organiser le comptoir'}</button>
    </div>

    ${c.plOrg ? `<div style="margin-top:12px;padding:12px 14px;border:0.5px solid var(--color-border-tertiary);border-radius:10px;background:var(--color-background-secondary)">
      ${c.plEtapeTxt ? `<div style="font-size:12px;color:var(--color-text);line-height:1.5;margin-bottom:11px"><b style="font-weight:500">Étape suivante.</b> ${esc(c.plEtapeTxt)}</div>` : ''}
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(258px,1fr));gap:14px">

        <div>
          <div style="${lbl};margin-bottom:7px">Zones</div>
          ${c.plZonesListe.map(z => `<div style="display:flex;gap:6px;align-items:center;margin-bottom:5px">
            <button ${x.A(z.choisir)} title="Regarder cette zone" style="flex:0 0 auto;width:20px;height:20px;border-radius:50%;cursor:pointer;border:1px solid ${z.on ? 'var(--color-primary)' : 'var(--color-border-secondary)'};background:${z.on ? 'var(--color-primary)' : 'transparent'}"></button>
            <input value="${esc(z.nom)}" ${x.C(z.renommer)} style="${inp};flex:1;min-width:0">
            <span style="font-size:10.5px;color:var(--color-text-muted);white-space:nowrap">${z.nMeubles} meuble(s)</span>
            <button ${x.A(z.supprimer)} title="Supprimer cette zone" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:pointer;padding:0 2px">✕</button>
          </div>`).join('')}
          <div style="display:flex;gap:6px;margin-top:7px">
            <input id="pl-nzone" value="${esc(c.plNZone.val)}" ${x.I(c.plNZone.set)} placeholder="Vitrine réfrigérée…" style="${inp};flex:1;min-width:0">
            <button ${x.A(c.plZoneAdd)} style="${btn(false)}">Ajouter</button>
          </div>
        </div>

        <div>
          <div style="${lbl};margin-bottom:7px">Meubles${c.plZonesListe.find(z => z.on) ? ' de « ' + esc((c.plZonesListe.find(z => z.on) || {}).nom) + ' »' : ''}</div>
          ${c.plMeublesListe.length ? c.plMeublesListe.map(m => `<div style="display:flex;gap:6px;align-items:center;margin-bottom:5px">
            <input value="${esc(m.nom)}" ${x.C(m.renommer)} style="${inp};flex:1;min-width:0">
            <span style="font-size:10.5px;color:var(--color-text-muted);white-space:nowrap" title="${esc(m.detail || '')}">${m.nNiveaux} niv. · ${m.nSlots} empl.</span>
            <label title="${m.photo ? 'Remplacer la photo' : 'Annexer une photo'}" style="flex:0 0 auto;width:26px;height:26px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;${m.photo ? 'border:0.5px solid var(--color-border-secondary)' : 'border:1px dashed var(--color-border-secondary);color:var(--color-text-muted);font-size:13px;line-height:1'}">${m.photo ? `<img src="${esc(m.photo)}" alt="" style="width:100%;height:100%;object-fit:cover;display:block">` : '＋'}<input type="file" accept="image/jpeg,image/png,image/webp" ${x.C(m.photoSet)} style="display:none"></label>${m.photoDel ? `<button ${x.A(m.photoDel)} title="Retirer la photo" style="border:none;background:none;color:var(--color-text-muted);font-size:11px;cursor:pointer;padding:0 1px">⊗</button>` : ''}
            <button ${x.A(m.supprimer)} title="Supprimer ce meuble" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:pointer;padding:0 2px">✕</button>
          </div>
          ${(m.periodesRef || []).length ? `<div style="display:flex;gap:5px;flex-wrap:wrap;margin:-1px 0 9px 2px">
            ${m.periodesRef.map(pr => `<button ${x.A(pr.bascule)} title="Monté ${esc(pr.nom.toLowerCase())} ?" style="border-radius:999px;padding:2px 9px;font-family:var(--font-ui);font-size:10px;font-weight:500;cursor:pointer;${pr.on ? 'border:1px solid var(--color-primary);background:rgba(141,29,44,0.08);color:var(--color-primary)' : 'border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text-muted)'}">${esc(pr.nom)}</button>`).join('')}
          </div>` : ''}`).join('') : `<div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:5px">Aucun meuble dans cette zone.</div>`}
          <!-- Un meuble ne se saisit pas sur une ligne : il porte un type, une
               température, un mode de présentation et les dimensions de ses
               emplacements. Le champ de nom qui vivait ici ne servait plus à
               rien et faisait chercher l'assistant. -->
          <button ${x.A(c.plMeubleAdd)} style="${btn(false, !!c.plMeubleAdd)};width:100%;justify-content:center;margin-top:7px;height:34px">+ Nouveau meuble — assistant</button>
          <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:5px;line-height:1.45">${c.plMeubleAdd ? 'Type, température, présentation, dimensions et niveaux — créés d’un seul geste.' : 'Créez d’abord une zone.'}</div>
        </div>

        <!-- La CRÉATION des niveaux appartient à l'assistant : elle y est
             demandée avec le reste du meuble. Ce qui reste ici est ce que
             l'assistant ne peut pas faire — retoucher un meuble déjà posé :
             renommer un niveau, lui ajouter un emplacement, en retirer un. -->
        <div>
          <div style="${lbl};margin-bottom:7px">Retoucher un meuble</div>
          ${c.plMeubleOpts.length ? `<select ${x.C(c.plMeubleSetSel)} style="${inp};width:100%;margin-bottom:6px">${c.plMeubleOpts.map(m => `<option value="${m.id}"${m.on ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>` : `<div style="font-size:11.5px;color:var(--color-text-muted)">Aucun meuble à retoucher.</div>`}
          ${c.plNiveauxListe.map(n => `<div style="display:flex;gap:6px;align-items:center;margin-bottom:5px">
            <input value="${esc(n.nom)}" ${x.C(n.renommer)} style="${inp};flex:1;min-width:0">
            <span style="font-size:10.5px;color:var(--color-text-muted);white-space:nowrap">${n.nSlots} empl.</span>
            <label title="${n.photo ? 'Remplacer la photo' : 'Annexer une photo'}" style="flex:0 0 auto;width:26px;height:26px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;${n.photo ? 'border:0.5px solid var(--color-border-secondary)' : 'border:1px dashed var(--color-border-secondary);color:var(--color-text-muted);font-size:13px;line-height:1'}">${n.photo ? `<img src="${esc(l.photo)}" alt="" style="width:100%;height:100%;object-fit:cover;display:block">` : '＋'}<input type="file" accept="image/jpeg,image/png,image/webp" ${x.C(n.photoSet)} style="display:none"></label>${n.photoDel ? `<button ${x.A(n.photoDel)} title="Retirer la photo" style="border:none;background:none;color:var(--color-text-muted);font-size:11px;cursor:pointer;padding:0 1px">⊗</button>` : ''}
            <button ${x.A(n.ajouter)} title="Ajouter un emplacement à ce niveau" style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:6px;width:22px;height:22px;cursor:pointer;font-size:12px;line-height:1">+</button>
            <button ${x.A(n.supprimer)} title="Supprimer ce niveau" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:pointer;padding:0 2px">✕</button>
          </div>`).join('')}
          ${c.plMeubleOpts.length ? `<button ${x.A(c.plNiveauAdd)} style="${btn(false, !!c.plNiveauAdd)};width:100%;justify-content:center;margin-top:7px">+ Un niveau de plus</button>
          <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:5px;line-height:1.45">Il reprend le nombre d’emplacements du dernier niveau de ce meuble.</div>` : ''}
        </div>
      </div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:11px;line-height:1.5">Une zone contient des meubles, un meuble des niveaux, un niveau des emplacements numérotés. Renommer se fait dans le champ ; la suppression d’un élément qui porte des références est refusée puis reconfirmée.</div>
    </div>` : ''}

    ${c.plVide
      ? `<div style="margin-top:14px;padding:24px 18px;border:1px dashed var(--color-border-secondary);border-radius:10px;text-align:center">
          <!-- Le message SUIT l'avancement. Fixe, il disait « pas encore
               déclaré » alors qu'une zone venait d'être créée — on croyait
               l'écriture perdue et on recommençait. -->
          <div style="font-size:13px;font-weight:500">${c.plEtape === 'zone' ? 'Le comptoir n’est pas encore déclaré.' : 'Déclaration en cours.'}</div>
          <div style="font-size:12px;color:var(--color-text-muted);margin-top:6px;line-height:1.55;max-width:560px;margin-left:auto;margin-right:auto">${esc(c.plEtapeTxt || '')} Tant qu’aucun emplacement n’existe, il n’y a rien à choisir pour une référence.</div>
          <!-- L'action de l'étape est proposée LÀ où on lit qu'il faut la faire.
               L'assistant vivait dans un panneau replié, sous un bouton nommé
               « Ajouter » comme deux autres : on ne le trouvait pas. -->
          ${c.plEtape === 'meuble' && c.plMeubleAdd
            ? `<button ${x.A(c.plMeubleAdd)} style="margin-top:12px;border:none;background:var(--color-primary);color:#fff;border-radius:9px;height:34px;padding:0 17px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">+ Nouveau meuble — assistant</button>` : ''}
          ${c.plEtape === 'zone'
            ? `<button ${x.A(c.plOrgGo)} style="margin-top:12px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:9px;height:34px;padding:0 15px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">${c.plOrg ? 'Saisir la première zone ci-dessus' : 'Organiser le comptoir'}</button>` : ''}
        </div>`
      : c.plVue === 'tableau' ? `
        <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:13px">
          <input id="pl-q" value="${esc(c.plQ)}" ${x.I(c.plSetQ)} placeholder="Chercher une référence, un meuble, un niveau…" style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:8px;height:31px;padding:0 10px;font-family:var(--font-ui);font-size:12px;flex:1;min-width:210px">
          <button ${x.A(c.plLibresGo)} style="${btn(c.plLibresSeules)}">Emplacements libres</button>
          <span style="font-size:11.5px;color:var(--color-text-muted)">${c.plRangsN} ligne(s)</span>
        </div>
        <div style="margin-top:11px;border:0.5px solid var(--color-border-tertiary);border-radius:10px;overflow-x:auto">
          <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:760px">
            <thead><tr>
              <th style="${TH}"><button ${x.A(c.plCols[0].go)} style="border:none;background:none;padding:0;cursor:pointer;font:inherit;color:inherit;text-transform:uppercase;letter-spacing:inherit;${c.plCols[0].on ? 'text-decoration:underline;text-underline-offset:3px' : ''}">Emplacement</button></th>
              <th style="${TH}">Dimensions</th>
              <th style="${TH}"><button ${x.A(c.plCols[1].go)} style="border:none;background:none;padding:0;cursor:pointer;font:inherit;color:inherit;text-transform:uppercase;letter-spacing:inherit;${c.plCols[1].on ? 'text-decoration:underline;text-underline-offset:3px' : ''}">Référence</button></th>
              <th style="${TH};text-align:right">Fronts</th>
              <th style="${TH}"><button ${x.A(c.plCols[2].go)} style="border:none;background:none;padding:0;cursor:pointer;font:inherit;color:inherit;text-transform:uppercase;letter-spacing:inherit;${c.plCols[2].on ? 'text-decoration:underline;text-underline-offset:3px' : ''}">État</button></th>
            </tr></thead>
            <tbody>
              ${c.plRangs.map(r => `<tr ${x.A(r.ouvrir)} style="${r.trSt}" title="${r.libre ? 'Viser cet emplacement' : 'Ouvrir la fiche de ' + esc(r.nom)}">
                <td style="${TD}"><span style="font-weight:500">${esc(r.meuble)} · ${esc(r.niveau)} · ${r.position}</span><div style="font-size:10.5px;color:var(--color-text-muted)">${esc(r.zone)}</div></td>
                <td style="${TD};color:var(--color-text-muted)">${esc(r.taille)}</td>
                <td style="${TD}">${r.libre ? '<span style="color:var(--color-text-muted)">—</span>' : esc(r.nom) + `<div style="font-size:10.5px;color:var(--color-text-muted)">${esc(r.ref)}</div>`}</td>
                <td style="${TD};text-align:right;font-variant-numeric:tabular-nums">${esc(r.fronts)}</td>
                <td style="${TD}"><span style="${r.etatSt}">${r.vise ? 'visé' : esc(r.etat)}</span></td>
              </tr>`).join('')}
            </tbody>
          </table>
          ${c.plRangsN === 0 ? `<div style="padding:22px 16px;font-size:12px;color:var(--color-text-muted);text-align:center">Aucun emplacement ne correspond.</div>` : ''}
        </div>
        ${c.plCibleTxt ? `<div style="margin-top:10px;font-size:11.5px;color:var(--color-text)">Emplacement visé : <b style="font-weight:500">${esc(c.plCibleTxt)}</b> — ouvrez une référence pour l’y placer.</div>` : ''}`
      : `<div style="margin-top:14px;overflow-x:auto">
          <div style="display:grid;grid-template-columns:82px repeat(${Math.max(1, c.plMeubles.length)},minmax(190px,1fr));gap:10px;align-items:start;min-width:${82 + c.plMeubles.length * 200}px">
            <div></div>
            ${c.plMeubles.map(m => `<div style="text-align:center;padding-bottom:5px;border-bottom:2px solid var(--color-text)">
              <button ${x.A(m.renommer)} title="Renommer" style="border:none;background:none;padding:0;cursor:pointer;${lbl};color:var(--color-text)">${esc(m.nom)}</button>
              ${c.plOrg ? `<button ${x.A(m.supprimer)} title="Supprimer ce meuble" style="border:none;background:none;padding:0 0 0 6px;cursor:pointer;font-size:10.5px;color:var(--color-text-muted)">✕</button>` : ''}
              ${m.periodes ? `<div style="font-size:10px;font-weight:400;color:var(--color-text-muted);text-transform:none;letter-spacing:0;margin-top:2px">${esc(m.periodes)}</div>` : ''}
            </div>`).join('')}
            ${c.plLignes.map(l => `
              <div style="padding-top:14px;text-align:right;font-size:10.5px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.07em;font-weight:500">${esc(l.nom)}</div>
              ${l.cases.map(k => k.absent
                ? `<div style="min-height:50px"></div>`
                : `<div style="display:grid;grid-template-columns:repeat(${Math.max(1, k.slots.length)},1fr);gap:5px">
                    ${k.slots.map(s => `<div ${x.A(s.clic)} title="${s.libre ? 'Emplacement libre — cliquez pour le viser' : 'Ouvrir la fiche de ' + esc(s.nom)}" style="${s.st}">
                      <span style="overflow:hidden;text-overflow:ellipsis">${s.vise ? 'visé' : (s.libre ? 'libre' : esc(s.nom))}</span>
                      <span style="opacity:0.8">${s.position}${s.detail ? ' · ' + esc(s.detail) : ''}</span>
                    </div>`).join('')}
                    ${c.plOrg ? `<button ${x.A(k.ajouter)} title="Ajouter des emplacements" style="border:1px dashed var(--color-border-secondary);background:transparent;color:var(--color-text-muted);border-radius:7px;min-height:50px;cursor:pointer;font-family:var(--font-ui);font-size:14px">+</button>` : ''}
                  </div>`).join('')}`).join('')}
          </div>
        </div>
        <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-top:12px;font-size:10.5px;color:var(--color-text-muted)">
          <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:13px;height:13px;border:1.5px dashed var(--color-primary);border-radius:3px"></span>libre</span>
          <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:13px;height:13px;background:var(--color-background-secondary);border:0.5px solid var(--color-border-tertiary);border-radius:3px"></span>occupé — cliquez pour la fiche</span>
          <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:13px;height:13px;background:var(--color-primary);border-radius:3px"></span>visé</span>
          ${c.plCibleTxt ? `<span style="margin-left:auto;font-size:11.5px;color:var(--color-text)">Emplacement visé : <b style="font-weight:500">${esc(c.plCibleTxt)}</b> — ouvrez une référence pour l’y placer.</span>` : ''}
        </div>`}

    ${(c.plManque || []).length ? `<div style="margin-top:13px;padding-top:12px;border-top:0.5px solid var(--color-border-tertiary)">
      <div style="${lbl};margin-bottom:7px">Ce que le comptoir ne peut pas encore diffuser</div>
      ${c.plManque.map(m => `<div style="display:flex;gap:9px;align-items:flex-start;margin-top:6px">
        <span style="font-size:10px;font-weight:500;padding:2px 8px;border-radius:999px;background:#FBEFE0;color:var(--color-on-abricot);border:1px solid #E8C9A0;white-space:nowrap;flex:0 0 auto">manque API</span>
        <div style="font-size:11.5px;line-height:1.5"><b style="font-weight:500">${esc(m.champ)}</b> — ${esc(m.quoi)}<div style="color:var(--color-text-muted)">${esc(m.source)}</div></div>
      </div>`).join('')}
    </div>` : ''}
  </div>`;
}

/* --- Détail d'une période d'analyse ------------------------------------------
   Deux lectures : tous les magasins sur la période choisie, puis un magasin
   choisi sur toutes les périodes. La question « est-ce un accident ou une
   tendance » ne se répond pas sur un point isolé. */
function tplAnalyseDetail(c, x){
  const { esc } = x;
  const a = c.anDetail;
  const lbl = 'font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.09em;color:var(--color-text-muted)';
  const th = 'text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 10px 6px';
  const td = 'padding:7px 10px;border-top:0.5px solid var(--color-border-tertiary)';
  const nav = dispo => 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);'
    + 'border-radius:8px;height:29px;padding:0 11px;font-family:var(--font-ui);font-size:12px;font-weight:500;'
    + (dispo ? 'cursor:pointer' : 'cursor:not-allowed;opacity:0.4');
  return `
  <div ${x.A(a.close)} style="position:fixed;inset:0;background:rgba(20,16,14,0.5);z-index:80;animation:fadeIn 160ms ease"></div>
  <div style="position:fixed;inset:0;z-index:81;display:flex;align-items:center;justify-content:center;padding:22px;pointer-events:none">
    <div style="pointer-events:auto;background:var(--color-surface);border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,0.3);width:820px;max-width:100%;max-height:100%;display:flex;flex-direction:column;overflow:hidden">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:15px 19px;border-bottom:0.5px solid var(--color-border-tertiary)">
        <div>
          <div style="${lbl}">${esc(a.titre)} · ${esc(a.mesure)}</div>
          <div style="font-size:17px;font-weight:500;margin-top:3px">${esc(a.periode)}${a.enCours ? ` <span style="font-size:11.5px;font-weight:400;color:var(--color-primary)">période en cours</span>` : ''}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">${esc(a.bornes)}</div>
        </div>
        <div style="display:flex;gap:7px;align-items:center;flex:0 0 auto">
          <button ${x.A(a.prec)} title="Période précédente" style="${nav(!!a.prec)}">←</button>
          <button ${x.A(a.suiv)} title="Période suivante" style="${nav(!!a.suiv)}">→</button>
          <button ${x.A(a.close)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted);border-radius:999px;width:28px;height:28px;font-size:14px;cursor:pointer">✕</button>
        </div>
      </div>

      <div style="padding:15px 19px;overflow-y:auto" data-scroll="andet">
        <div style="display:flex;gap:26px;flex-wrap:wrap;padding-bottom:13px;border-bottom:0.5px solid var(--color-border-tertiary)">
          <div><div style="${lbl}">Réseau</div><div style="font-size:20px;font-weight:500;margin-top:2px">${esc(a.reseau)}</div></div>
          <div><div style="${lbl}">N-1</div><div style="font-size:20px;font-weight:400;margin-top:2px;color:var(--color-text-muted)">${esc(a.reseauN1)}</div></div>
          <div><div style="${lbl}">Écart</div><div style="font-size:20px;font-weight:500;margin-top:2px;color:${a.deltaCol}">${esc(a.delta)}</div></div>
        </div>

        <div style="${lbl};margin:15px 0 7px">Par magasin — cliquez pour suivre un magasin sur toutes les périodes</div>
        ${a.magsVide
          ? `<div style="font-size:12px;color:var(--color-text-muted);line-height:1.55">Aucune ventilation par magasin sur cette période.</div>`
          : `<table style="width:100%;border-collapse:collapse;font-size:12.5px">
              <thead><tr>
                <th style="${th};padding-left:0">Magasin</th>
                <th style="${th};text-align:right">Valeur</th>
                <th style="${th};text-align:right">Part</th>
                <th style="${th};text-align:right">N-1</th>
                <th style="${th};text-align:right;padding-right:0">Écart</th>
              </tr></thead>
              <tbody>${a.mags.map(m => `<tr ${x.A(m.choisir)} title="Suivre ${esc(m.nom)}" style="cursor:pointer;${m.on ? 'background:rgba(141,29,44,0.05)' : ''}">
                <td style="${td};padding-left:0">
                  <span style="font-weight:500">${esc(m.nom)}</span>
                  <div style="height:4px;border-radius:999px;background:var(--color-border-tertiary);margin-top:4px;max-width:220px"><i style="display:block;height:100%;border-radius:999px;width:${m.partPct.toFixed(1)}%;background:${m.on ? 'var(--color-primary)' : 'var(--color-secondary)'}"></i></div>
                </td>
                <td style="${td};text-align:right;font-variant-numeric:tabular-nums">${esc(m.valeur)}</td>
                <td style="${td};text-align:right;font-variant-numeric:tabular-nums;color:var(--color-text-muted)">${esc(m.part)}</td>
                <td style="${td};text-align:right;font-variant-numeric:tabular-nums;color:var(--color-text-muted)">${esc(m.n1)}</td>
                <td style="${td};text-align:right;font-variant-numeric:tabular-nums;padding-right:0;color:${m.deltaCol}">${esc(m.delta)}${m.sansN1 ? `<div style="font-size:10px;color:var(--color-text-muted);font-weight:400">pas de N-1</div>` : ''}</td>
              </tr>`).join('')}</tbody>
            </table>`}
        ${a.ecartTotal ? `<div style="font-size:11px;color:var(--color-on-abricot);margin-top:9px;line-height:1.5">${esc(a.ecartTotal)}</div>` : ''}

        ${a.serie ? `<div style="margin-top:20px;padding-top:15px;border-top:0.5px solid var(--color-border-tertiary)">
          <div style="${lbl};margin-bottom:7px">${esc(a.serie.nom)} — toutes les périodes</div>
          <table style="width:100%;border-collapse:collapse;font-size:12.5px">
            <thead><tr>
              <th style="${th};padding-left:0">Période</th>
              <th style="${th};text-align:right">Valeur</th>
              <th style="${th};text-align:right">N-1</th>
              <th style="${th};text-align:right;padding-right:0">Écart</th>
            </tr></thead>
            <tbody>${a.serie.lignes.map(l => `<tr style="${l.courant ? 'background:rgba(141,29,44,0.04)' : ''}">
              <td style="${td};padding-left:0">${esc(l.libelle)}${l.enCours ? ` <span style="font-size:10.5px;color:var(--color-primary)">en cours</span>` : ''}</td>
              <td style="${td};text-align:right;font-variant-numeric:tabular-nums">${esc(l.valeur)}</td>
              <td style="${td};text-align:right;font-variant-numeric:tabular-nums;color:var(--color-text-muted)">${esc(l.n1)}</td>
              <td style="${td};text-align:right;font-variant-numeric:tabular-nums;padding-right:0;color:${l.deltaCol}">${esc(l.delta)}</td>
            </tr>`).join('')}</tbody>
          </table>
          ${a.serie.note ? `<div style="font-size:11px;color:var(--color-text-muted);margin-top:8px">${esc(a.serie.note)}</div>` : ''}
        </div>` : ''}
      </div>
    </div>
  </div>`;
}

/* --- Assistant de création d'un meuble --------------------------------------
   Quatre étapes : ce que c'est, comment il conserve et présente, la taille d'un
   emplacement, puis ce qu'on s'apprête à créer. Le meuble naît avec ses niveaux
   et ses emplacements — le déclarer en trois écrans successifs faisait
   abandonner à mi-chemin, et un meuble sans emplacement ne sert à rien. */
function tplPlanoMeubleWizard(c, x){
  const { esc } = x;
  const w = c.plMw;
  const lbl = 'font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.09em;color:var(--color-text-muted)';
  const inp = 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);'
    + 'border-radius:8px;height:32px;padding:0 10px;font-family:var(--font-ui);font-size:12.5px;box-sizing:border-box';
  const puce = o => `<button ${x.A(o.pick)} style="border-radius:999px;padding:6px 13px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;${o.on ? 'border:1px solid var(--color-primary);background:rgba(141,29,44,0.08);color:var(--color-primary)' : 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text)'}">${esc(o.v)}</button>`;
  const etapes = ['Le meuble', 'Conservation & présentation', 'Un emplacement', 'Photos', 'Récapitulatif'];
  // Vignette de photo : le même geste partout — déposer, remplacer, retirer.
  const vignette = (data, poser, retirer, titre) => data
    ? `<div style="position:relative;flex:0 0 auto">
        <img src="${data}" alt="" style="width:104px;height:76px;object-fit:cover;border-radius:8px;border:0.5px solid var(--color-border-tertiary);display:block">
        ${retirer ? `<button ${x.A(retirer)} title="Retirer" style="position:absolute;top:4px;right:4px;border:none;background:rgba(20,16,14,0.72);color:#fff;border-radius:999px;width:20px;height:20px;font-size:11px;cursor:pointer;line-height:1">✕</button>` : ''}
        <label style="display:block;text-align:center;font-size:10px;color:var(--color-text-muted);margin-top:3px;cursor:pointer;text-decoration:underline;text-underline-offset:2px">remplacer<input type="file" accept="image/jpeg,image/png,image/webp" ${x.C(poser)} style="display:none"></label>
      </div>`
    : `<label style="flex:0 0 auto;width:104px;height:76px;border:1px dashed var(--color-border-secondary);border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;cursor:pointer;background:var(--color-surface)">
        <span style="font-size:15px;line-height:1;color:var(--color-text-muted)">＋</span>
        <span style="font-size:10px;color:var(--color-text-muted);text-align:center;padding:0 5px">${esc(titre || 'photo')}</span>
        <input type="file" accept="image/jpeg,image/png,image/webp" ${x.C(poser)} style="display:none">
      </label>`;
  return `
  <div ${x.A(w.fermer)} style="position:fixed;inset:0;background:rgba(20,16,14,0.5);z-index:90;animation:fadeIn 160ms ease"></div>
  <div style="position:fixed;inset:0;z-index:91;display:flex;align-items:center;justify-content:center;padding:22px;pointer-events:none">
    <div style="pointer-events:auto;background:var(--color-surface);border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,0.3);width:660px;max-width:100%;max-height:100%;display:flex;flex-direction:column;overflow:hidden">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:15px 19px;border-bottom:0.5px solid var(--color-border-tertiary)">
        <div>
          <div style="${lbl}">Nouveau meuble${w.zone ? ' — ' + esc(w.zone) : ''}</div>
          <div style="font-size:16px;font-weight:500;margin-top:3px">${esc(etapes[w.etape - 1])}</div>
        </div>
        <button ${x.A(w.fermer)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted);border-radius:999px;width:28px;height:28px;font-size:14px;cursor:pointer;flex:0 0 auto">✕</button>
      </div>
      <div style="display:flex;gap:5px;padding:11px 19px 0">
        ${etapes.map((e, i) => `<div title="${esc(e)}" style="flex:1;height:3px;border-radius:999px;background:${i < w.etape ? 'var(--color-primary)' : 'var(--color-border-tertiary)'}"></div>`).join('')}
      </div>

      <div style="padding:16px 19px;overflow-y:auto" data-scroll="plmw">
        ${w.etape === 1 ? `
          <div style="${lbl};margin-bottom:6px">Nom du meuble</div>
          <input id="plmw-nom" value="${esc(w.nom)}" ${x.I(w.set('nom'))} placeholder="Vitrine 1, Gondole A…" style="${inp};width:100%">
          <div style="${lbl};margin:16px 0 7px">Type</div>
          <div style="display:flex;gap:7px;flex-wrap:wrap">${w.types.map(puce).join('')}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:12px;line-height:1.5">Le type sert à lire le plan d’un coup d’œil et à repérer ce qui ne peut pas y aller.</div>
        ` : ''}

        ${w.etape === 2 ? `
          <div style="${lbl};margin-bottom:7px">Température</div>
          <div style="display:flex;gap:7px;flex-wrap:wrap">${w.temperatures.map(puce).join('')}</div>
          <div style="${lbl};margin:18px 0 7px">Mode de présentation</div>
          <div style="display:flex;gap:7px;flex-wrap:wrap">${w.presentations.map(puce).join('')}</div>
          <!-- Le comptoir du matin n'est pas celui de midi : un meuble peut
               n'être monté qu'à certains moments, et le plan se lit alors
               moment par moment. -->
          ${(w.periodes || []).length ? `
            <div style="${lbl};margin:18px 0 7px">Moments de la journée où ce meuble est monté</div>
            <div style="display:flex;gap:7px;flex-wrap:wrap">
              ${w.periodes.map(pr => `<button ${x.A(pr.bascule)} title="${esc(pr.aide)}" style="display:inline-flex;align-items:center;gap:7px;border-radius:999px;height:31px;padding:0 13px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;${pr.on ? 'border:none;background:var(--color-primary);color:#fff' : 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted)'}">
                <span style="width:13px;height:13px;border-radius:4px;flex:0 0 auto;background:${pr.on ? '#fff' : 'transparent'};border:1.5px solid ${pr.on ? '#fff' : 'var(--color-border-secondary)'};display:inline-flex;align-items:center;justify-content:center;font-size:9px;color:var(--color-primary);line-height:1">${pr.on ? '✓' : ''}</span>
                ${esc(pr.nom)}${pr.aide ? `<span style="font-weight:400;opacity:.75;font-size:11px">${esc(pr.aide)}</span>` : ''}</button>`).join('')}
            </div>
            <div style="font-size:11px;color:${w.periodesVide ? 'var(--color-primary)' : 'var(--color-text-muted)'};margin-top:7px">${w.periodesVide ? 'Aucun moment coché : le meuble ne serait monté à aucune heure.' : 'Retenu : ' + esc(w.periodesTxt) + '.'}</div>` : ''}
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:14px;line-height:1.5">La température décide de ce qu’on peut y poser ; le mode de présentation, de la façon dont on compte les fronts — une grille et un panier ne se remplissent pas pareil.</div>
        ` : ''}

        ${w.etape === 3 ? `
          <div style="${lbl};margin-bottom:7px">Dimensions d’un emplacement, en millimètres</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div><div style="font-size:11px;color:var(--color-text-muted);margin-bottom:4px">Longueur</div><input id="plmw-lon" type="number" min="0" max="5000" value="${esc(w.longueur)}" ${x.I(w.set('longueur'))} style="${inp};width:96px;text-align:right"></div>
            <div><div style="font-size:11px;color:var(--color-text-muted);margin-bottom:4px">Largeur</div><input id="plmw-lar" type="number" min="0" max="5000" value="${esc(w.largeur)}" ${x.I(w.set('largeur'))} style="${inp};width:96px;text-align:right"></div>
            <div><div style="font-size:11px;color:var(--color-text-muted);margin-bottom:4px">Hauteur</div><input id="plmw-hau" type="number" min="0" max="5000" value="${esc(w.hauteur)}" ${x.I(w.set('hauteur'))} style="${inp};width:96px;text-align:right"></div>
            <div><div style="font-size:11px;color:var(--color-text-muted);margin-bottom:4px">Capacité (pièces)</div><input id="plmw-cap" type="number" min="0" max="999" value="${esc(w.capacite)}" ${x.I(w.set('capacite'))} placeholder="—" style="${inp};width:110px;text-align:right"></div>
          </div>
          <div style="${lbl};margin:20px 0 7px">Combien de niveaux, combien d’emplacements</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div><div style="font-size:11px;color:var(--color-text-muted);margin-bottom:4px">Niveaux</div><input id="plmw-nniv" type="number" min="1" max="40" value="${esc(w.nNiveaux)}" ${x.I(w.set('nNiveaux'))} style="${inp};width:88px;text-align:right"></div>
            <div><div style="font-size:11px;color:var(--color-text-muted);margin-bottom:4px">Emplacements par niveau</div><input id="plmw-nslot" type="number" min="0" max="40" value="${esc(w.nSlots)}" ${x.I(w.set('nSlots'))} style="${inp};width:130px;text-align:right"></div>
            <div style="font-size:12px;color:var(--color-text-muted);padding-bottom:8px">→ ${w.total} emplacement(s) : ${esc(w.niveauxTxt)}</div>
          </div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:14px;line-height:1.5">Une dimension laissée vide reste inconnue — elle ne sera pas affichée comme un zéro. Tout se corrige ensuite, emplacement par emplacement.</div>
        ` : ''}

        ${w.etape === 4 ? `
          <div style="${lbl};margin-bottom:7px">Photo du meuble</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            ${vignette(w.photoMeuble, w.photoMeubleSet, w.photoMeubleDel, 'le meuble')}
            <div style="font-size:11.5px;color:var(--color-text-muted);line-height:1.5;max-width:330px;align-self:center">Le meuble tel qu’il doit se présenter. C’est à elle qu’on compare ce qu’on voit en boutique.</div>
          </div>
          <div style="${lbl};margin:18px 0 7px">Une photo par niveau</div>
          <div style="display:flex;gap:12px;flex-wrap:wrap">
            ${w.photosNiveau.map(n => `<div style="text-align:center">
              ${vignette(n.data, n.set, n.del, esc(n.nom))}
              <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:3px">${esc(n.nom)}</div>
            </div>`).join('')}
          </div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:14px;line-height:1.5">Tout est facultatif, et rien n’est figé : une photo s’ajoute, se remplace ou se retire ensuite depuis « Retoucher un meuble ».</div>
        ` : ''}

        ${w.etape === 5 ? `
          <div style="border:0.5px solid var(--color-border-tertiary);border-radius:10px;overflow:hidden">
            ${w.recap.map((r, i) => `<div style="display:flex;gap:14px;padding:9px 13px;${i ? 'border-top:0.5px solid var(--color-border-tertiary);' : ''}${i % 2 ? 'background:var(--color-background-secondary);' : ''}">
              <span style="${lbl};flex:0 0 118px">${esc(r.k)}</span>
              <span style="font-size:12.5px;line-height:1.45">${esc(r.v)}</span>
            </div>`).join('')}
          </div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:12px;line-height:1.5">Le meuble, ses niveaux et ses emplacements sont créés d’un seul geste.</div>
        ` : ''}

        ${w.err ? `<div style="margin-top:12px;padding:9px 12px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:12px;line-height:1.45">${esc(w.err)}</div>` : ''}
      </div>

      <div style="display:flex;gap:9px;align-items:center;padding:13px 19px;border-top:0.5px solid var(--color-border-tertiary);background:var(--color-background-secondary)">
        ${w.precedent ? `<button ${x.A(w.precedent)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:9px;height:33px;padding:0 14px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Retour</button>` : ''}
        <div style="flex:1"></div>
        <button ${x.A(w.fermer)} style="border:none;background:transparent;color:var(--color-text-muted);font-family:var(--font-ui);font-size:12px;cursor:pointer;padding:0 8px">Annuler</button>
        ${w.suivant ? `<button ${x.A(w.suivant)} style="border:none;background:var(--color-primary);color:#fff;border-radius:9px;height:33px;padding:0 17px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Continuer</button>` : ''}
        ${w.creer ? `<button ${x.A(w.creer)} style="border:none;background:var(--color-primary);color:#fff;border-radius:9px;height:33px;padding:0 17px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:${w.busy ? 'wait' : 'pointer'};opacity:${w.busy ? '0.6' : '1'}">${w.busy ? 'Création…' : 'Créer le meuble'}</button>` : ''}
      </div>
    </div>
  </div>`;
}

/* --- Fiche de présentation et de vente d'une référence -----------------------
   Où le produit se présente, comment, et avec quelles informations. Trois
   colonnes : la consigne, l'emplacement, la fiche technique. */
function tplPlanoFiche(c, x){
  const { esc } = x;
  const f = c.plFiche;
  const lbl = 'font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.09em;color:var(--color-text-muted)';
  const k = 'font-size:10.5px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.08em;font-weight:500';
  const inp = 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);'
    + 'border-radius:8px;height:31px;padding:0 9px;font-family:var(--font-ui);font-size:12px';
  return `
  <div ${x.A(f.close)} style="position:fixed;inset:0;background:rgba(20,16,14,0.5);z-index:80;animation:fadeIn 160ms ease"></div>
  <div style="position:fixed;inset:0;z-index:81;display:flex;align-items:center;justify-content:center;padding:22px;pointer-events:none">
    <div style="pointer-events:auto;background:var(--color-surface);border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,0.3);overflow:hidden;display:flex;flex-direction:column;width:1180px;max-width:100%;max-height:100%">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:14px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
        <div>
          <div style="${lbl}">Fiche de présentation</div>
          <div style="font-size:16px;font-weight:500;margin-top:3px">${esc(f.nom)}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">${esc(f.ref)}${f.sous ? ' · ' + esc(f.sous) : ''} · ${f.placeTxt ? esc(f.placeTxt) : 'sans emplacement au comptoir'}</div>
        </div>
        <button ${x.A(f.close)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted);border-radius:999px;width:28px;height:28px;font-size:14px;cursor:pointer;flex:0 0 auto">✕</button>
      </div>

      ${f.chargement ? `<div style="padding:40px;font-size:12.5px;color:var(--color-text-muted)">Lecture de la fiche…</div>` : `
      <div style="display:grid;grid-template-columns:320px 1fr 290px;min-height:0;overflow:hidden">

        <div style="padding:14px 16px;border-right:0.5px solid var(--color-border-tertiary);overflow-y:auto" data-scroll="plf1">
          <div style="${lbl}">Photo de présentation</div>
          ${f.photo
            ? `<div style="margin-top:8px;position:relative">
                <img src="${esc(f.photo)}" alt="Photo de présentation" style="width:100%;border-radius:10px;border:0.5px solid var(--color-border-tertiary);display:block">
                <button ${x.A(f.photoRetirer)} title="Retirer la photo" style="position:absolute;top:7px;right:7px;border:none;background:rgba(20,16,14,0.72);color:#fff;border-radius:999px;width:24px;height:24px;font-size:12px;cursor:pointer">✕</button>
              </div>
              <label style="display:inline-block;margin-top:7px;font-size:11px;color:var(--color-text-muted);cursor:pointer;text-decoration:underline;text-underline-offset:3px">Remplacer<input type="file" accept="image/jpeg,image/png,image/webp" ${x.C(f.photoDepose)} style="display:none"></label>`
            : `<label style="margin-top:8px;background:var(--color-background-secondary);border:1px dashed var(--color-border-secondary);border-radius:10px;padding:18px 14px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:7px;cursor:pointer">
                <span style="font-size:12.5px;font-weight:500;color:var(--color-text)">Annexer une photo</span>
                <span style="font-size:11px;color:var(--color-text-muted);line-height:1.45">JPEG, PNG ou WebP — réduite avant l’envoi</span>
                <input type="file" accept="image/jpeg,image/png,image/webp" ${x.C(f.photoDepose)} style="display:none">
              </label>`}
          ${(f.manque || []).filter(m => /photo/i.test(m.champ)).map(m => `
            <div style="margin-top:8px;display:flex;gap:7px;align-items:flex-start">
              <span style="font-size:10px;font-weight:500;padding:2px 8px;border-radius:999px;background:#FBEFE0;color:var(--color-on-abricot);border:1px solid #E8C9A0;white-space:nowrap;flex:0 0 auto">manque API</span>
              <div style="font-size:10.5px;color:var(--color-text-muted);line-height:1.45">Cette photo reste dans le cockpit. ${esc(m.source)}</div>
            </div>`).join('')}

          <div style="${lbl};margin-top:18px">Consigne de présentation</div>
          <textarea id="plf-note" ${x.I(f.noteSet('texte'))} rows="5" placeholder="Comment ce produit doit être présenté au comptoir" style="width:100%;box-sizing:border-box;margin-top:7px;border:0.5px solid var(--color-border-secondary);border-radius:9px;padding:8px 10px;font-family:var(--font-ui);font-size:11.5px;line-height:1.5;color:var(--color-text);background:var(--color-surface);resize:vertical">${esc(f.noteTxt)}</textarea>
          <div style="margin-top:8px;font-size:11px;color:var(--color-text-muted);text-align:right">${esc(f.noteAuto || '')}</div>
          <button ${x.A(f.enregistrerNote)} style="margin-top:4px;width:100%;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:9px;height:32px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:${f.busy ? 'wait' : 'pointer'}">Enregistrer la consigne</button>
          ${f.noteMaj ? `<div style="font-size:10.5px;color:var(--color-text-muted);margin-top:7px">${esc(f.noteMaj)}</div>` : ''}
          ${(f.manque || []).filter(m => /diffusion/i.test(m.champ)).map(m => `
            <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:9px;line-height:1.5"><span style="font-weight:500;color:var(--color-on-abricot)">manque API</span> — ${esc(m.source)}</div>`).join('')}
        </div>

        <div style="padding:14px 16px;border-right:0.5px solid var(--color-border-tertiary);overflow-y:auto" data-scroll="plf2">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
            <div style="${lbl}">Emplacement au comptoir</div>
            ${f.zonesOpts.length ? `<select ${x.C(f.zoneSet)} style="${inp}">${f.zonesOpts.map(z => `<option value="${z.id}"${z.on ? ' selected' : ''}>${esc(z.nom)}</option>`).join('')}</select>` : ''}
          </div>
          ${f.lignes.length ? `
            <div style="margin-top:11px;overflow-x:auto">
              <div style="display:grid;grid-template-columns:62px repeat(${Math.max(1, f.meubles.length)},minmax(160px,1fr));gap:8px;align-items:start">
                <div></div>
                ${f.meubles.map(m => `<div style="text-align:center;padding-bottom:4px;border-bottom:2px solid var(--color-text);${lbl};color:var(--color-text)">${esc(m)}</div>`).join('')}
                ${f.lignes.map(l => `
                  <div style="padding-top:12px;text-align:right;font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.06em;font-weight:500">${esc(l.nom)}</div>
                  ${l.cases.map(cs => cs.absent ? `<div style="min-height:44px"></div>`
                    : `<div style="display:grid;grid-template-columns:repeat(${Math.max(1, cs.slots.length)},1fr);gap:4px">
                        ${cs.slots.map(s => `<div ${x.A(s.clic)} style="${s.st}"><span style="overflow:hidden;text-overflow:ellipsis">${esc(s.nom)}</span><span style="opacity:0.8">${s.position}</span></div>`).join('')}
                      </div>`).join('')}`).join('')}
              </div>
            </div>`
            : `<div style="margin-top:11px;padding:18px;border:1px dashed var(--color-border-secondary);border-radius:9px;font-size:12px;color:var(--color-text-muted);line-height:1.55">Aucun emplacement déclaré dans cette zone. Fermez la fiche et utilisez « Organiser le comptoir ».</div>`}

          <div style="display:flex;gap:10px;align-items:center;margin-top:13px;flex-wrap:wrap">
            <span style="${k}">Fronts</span><input type="number" min="1" value="${f.fronts}" ${x.C(f.set('fronts'))} style="${inp};width:60px;text-align:right">
            <span style="${k}">Min. à tenir</span><input type="number" min="0" value="${f.qmin}" ${x.C(f.set('qmin'))} style="${inp};width:66px;text-align:right">
            <span style="${k}">Ordre</span><input type="number" min="1" value="${f.ordre}" ${x.C(f.set('ordre'))} style="${inp};width:56px;text-align:right">
          </div>
          <div style="display:flex;gap:9px;align-items:center;margin-top:13px;flex-wrap:wrap">
            <button ${x.A(f.placer)} style="border:none;background:var(--color-primary);color:#fff;border-radius:9px;height:33px;padding:0 15px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:${f.busy ? 'wait' : (f.placer ? 'pointer' : 'not-allowed')};opacity:${f.placer && !f.busy ? '1' : '0.5'}">${f.cibleTxt ? 'Placer en ' + esc(f.cibleTxt) : 'Choisissez un emplacement'}</button>
            ${f.retirer ? `<button ${x.A(f.retirer)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-primary);border-radius:9px;height:33px;padding:0 13px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Retirer du comptoir</button>` : ''}
          </div>
          ${f.err ? `<div style="margin-top:10px;padding:8px 11px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:11.5px;line-height:1.45">${esc(f.err)}</div>` : ''}
          ${!f.err && f.ok ? `<div style="margin-top:10px;font-size:11.5px;color:#2d7a3e">${esc(f.ok)}</div>` : ''}

        </div>

        <div style="padding:14px 16px;overflow-y:auto;background:var(--color-background-secondary)" data-scroll="plf3">
          <div style="${lbl}">Fiche technique — de la caisse</div>
          ${f.techniqueVide
            ? `<div style="font-size:11.5px;color:var(--color-text-muted);margin-top:9px;line-height:1.5">Aucun renseignement technique pour cette référence.</div>`
            : `<div style="display:flex;flex-direction:column;gap:9px;margin-top:9px">
                ${f.technique.map(t => `<div><div style="${k}">${esc(t.k)}</div><div style="font-size:12.5px">${esc(t.v)}</div></div>`).join('')}
              </div>`}
          <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:12px;line-height:1.45">Ces champs viennent du catalogue du réseau. Ils ne demandent aucune nouvelle API — seulement d’être renseignés à la source quand ils manquent.</div>
        </div>
      </div>`}
    </div>
  </div>`;
}

/* --- Photo agrandie et ANNOTABLE : cadres numérotés + liste des remarques ----
   On glisse sur la zone, le cadre se pose avec son numéro et sa gravité, et la
   remarque s'écrit à droite. La couleur vient du barème de conformité déjà
   partagé (mineur / majeur / critique) : elle dit la gravité, elle ne décore
   pas. L'épaisseur du trait la redit — deux rouges voisins ne se distinguent
   pas sur une photo sombre. */
function tplCtrlZoom(c, x){
  const { esc } = x;
  const z = c.ctrlZoom;
  const lbl = 'font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:0.09em;color:var(--color-text-muted)';
  const tool = (on, dispo) => 'display:inline-flex;align-items:center;gap:6px;border-radius:9px;height:33px;padding:0 12px;'
    + 'font-family:var(--font-ui);font-size:12px;font-weight:500;white-space:nowrap;'
    + (dispo === false ? 'cursor:not-allowed;opacity:0.45;' : 'cursor:pointer;')
    + (on ? 'border:none;background:var(--color-primary);color:#fff'
          : 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text)');
  return `
  <div ${x.A(z.close)} style="position:fixed;inset:0;background:rgba(20,16,14,0.66);z-index:80;animation:fadeIn 160ms ease"></div>
  <div style="position:fixed;inset:0;z-index:81;display:flex;align-items:center;justify-content:center;padding:22px;pointer-events:none">
    <div style="pointer-events:auto;background:var(--color-surface);border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,0.34);overflow:hidden;display:flex;flex-direction:column;max-width:100%;max-height:100%">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:14px 18px;border-bottom:0.5px solid var(--color-border-tertiary)">
        <div>
          <div style="${lbl}">Repères sur la photo</div>
          <div style="font-size:16px;font-weight:500;margin-top:3px">${esc(z.nom)}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">${esc(z.sous)}</div>
        </div>
        <button ${x.A(z.close)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted);border-radius:999px;width:28px;height:28px;font-size:14px;cursor:pointer;flex:0 0 auto">✕</button>
      </div>

      <div style="display:flex;min-height:0">
        <div style="flex:1;display:flex;flex-direction:column;min-width:0">
          <div style="background:#141110;padding:14px;display:flex;align-items:center;justify-content:center;gap:12px;min-height:0">
            <!-- data-zsurf : la surface de tracé. Les cadres sont positionnés en
                 POURCENTAGE d'elle, jamais en pixels — la photo peut être
                 affichée à n'importe quelle taille. -->
            <div ${x.PD(z.down)} data-zsurf style="position:relative;display:inline-block;line-height:0;touch-action:none;cursor:${z.imgErr ? 'not-allowed' : 'crosshair'};flex:0 1 auto;${z.imgErr ? 'min-width:380px;min-height:280px;background:#211c1a;border-radius:8px' : ''}">
              <img data-zimg src="${z.photo}" alt="Photo de réalisation" style="display:block;max-width:100%;max-height:70vh;border-radius:2px${z.imgErr ? ';display:none' : ''}">
              ${z.imgErr ? `<div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:24px;text-align:center">
                <span style="font-size:11px;font-weight:500;padding:2px 9px;border-radius:999px;background:#FBEFE0;color:var(--color-on-abricot);border:1px solid #E8C9A0">photo indisponible</span>
                <div style="font-size:12px;color:#c9bfb8;line-height:1.55;max-width:320px">${esc(z.imgErrTxt)}</div>
              </div>` : ''}
              ${z.cadres.map(k => `<div ${x.A(k.pick)} data-zbox style="${k.boxSt}">
                <span style="${k.badgeSt}">${k.n}</span>
                <span style="${k.xSt}">✕</span>
              </div>`).join('')}
            </div>
            ${z.compare ? `<div style="flex:0 1 auto;align-self:stretch;display:flex;flex-direction:column;gap:6px;min-width:240px;max-width:46%">
              <div style="${lbl};color:#c9bfb8">Référence attendue${z.produit ? ' — ' + esc(z.produit) : ''}</div>
              ${z.refManque
                ? `<div style="flex:1;background:#211c1a;border-radius:8px;padding:22px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:7px">
                    <span style="font-size:11px;font-weight:500;padding:2px 9px;border-radius:999px;background:#FBEFE0;color:var(--color-on-abricot);border:1px solid #E8C9A0">manque API</span>
                    <div style="font-size:12px;color:#c9bfb8;line-height:1.55;max-width:290px">${esc(z.refTxt)}</div>
                    <div style="font-size:11px;color:#8f857e;line-height:1.5;max-width:290px">${esc(z.refBesoin)}</div>
                  </div>`
                : `<img src="${z.photoRef}" alt="Photo de référence" style="display:block;max-width:100%;max-height:70vh;border-radius:2px">`}
            </div>` : ''}
          </div>

          <div style="display:flex;align-items:center;gap:8px;padding:10px 13px;background:var(--color-background-secondary);border-top:0.5px solid var(--color-border-tertiary);flex-wrap:wrap">
            <span style="${lbl}">Gravité du prochain repère</span>
            ${z.niveaux.map(lv => `<button ${x.A(lv.pick)} title="${esc(lv.nom)}" style="display:inline-flex;align-items:center;gap:6px;border-radius:999px;height:29px;padding:0 11px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer;${lv.on ? 'border:1px solid ' + lv.couleur + ';background:' + lv.couleur + '1f;color:var(--color-text)' : 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted)'}">
              <span style="width:10px;height:10px;border-radius:3px;background:${lv.couleur};flex:0 0 auto"></span>${esc(lv.nom)}</button>`).join('')}
            <div style="width:1px;height:22px;background:var(--color-border-tertiary);margin:0 3px"></div>
            <button ${x.A(z.compareGo)} style="${tool(z.compare, true)}">◫ Comparer</button>
            <button ${x.A(z.undo)} style="${tool(false, !!z.undo)}">↶ Retirer le dernier</button>
            <button ${x.A(z.clear)} style="${tool(false, !!z.clear)}">Tout effacer</button>
            <div style="flex:1"></div>
            <span style="font-size:11px;color:var(--color-text-muted)">Glissez sur la zone — le numéro s’ajoute tout seul</span>
          </div>
        </div>

        <div style="width:310px;flex:0 0 310px;border-left:0.5px solid var(--color-border-tertiary);display:flex;flex-direction:column;overflow-y:auto" data-scroll="zrep">
          <div style="padding:11px 13px 8px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
            <span style="${lbl}">Repères</span>
            <span style="font-size:11px;color:var(--color-text-muted)">${z.n}</span>
          </div>
          ${z.bilan.length ? `<div style="padding:9px 13px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;gap:6px;flex-wrap:wrap">
            ${z.bilan.map(b => `<span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:500;padding:3px 9px;border-radius:999px;background:${b.couleur}1f;color:var(--color-text)">
              <span style="width:8px;height:8px;border-radius:2px;background:${b.couleur}"></span>${b.n} ${esc(b.nom.toLowerCase())}</span>`).join('')}
          </div>` : ''}
          ${z.n === 0
            ? `<div style="padding:22px 15px;font-size:12px;color:var(--color-text-muted);line-height:1.6">Aucun repère. Glissez un cadre sur la zone à reprendre : il prend le numéro suivant et sa remarque s’écrit ici.</div>`
            : z.lignes.map(l => `<div ${x.A(l.pick)} style="${l.rowSt}">
                <span style="${l.pastilleSt}">${l.n}</span>
                <div style="flex:1;min-width:0">
                  ${l.actif
                    ? `<textarea id="zrep-txt" ${x.I(l.setTxt)} rows="3" placeholder="Ce qui est à reprendre, en une phrase" style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:7px 9px;font-family:var(--font-ui);font-size:11.5px;line-height:1.45;color:var(--color-text);background:var(--color-surface);resize:vertical">${esc(l.txt)}</textarea>
                      <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:6px">
                        ${l.niveaux.map(lv => `<button ${x.A(lv.pick)} title="${esc(lv.nom)}" style="display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:3px 8px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer;${lv.on ? 'border:1px solid ' + lv.couleur + ';background:' + lv.couleur + '1f;color:var(--color-text)' : 'border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text-muted)'}">
                          <span style="width:8px;height:8px;border-radius:2px;background:${lv.couleur}"></span>${esc(lv.nom)}</button>`).join('')}
                      </div>`
                    : `<div style="font-size:11.5px;line-height:1.45;${l.vide ? 'color:var(--color-text-muted);font-style:italic' : ''}">${l.vide ? 'Sans remarque — cliquez pour l’écrire' : esc(l.txt)}</div>
                       <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:3px">${esc(l.niveauNom)}</div>`}
                </div>
                <button ${x.A(l.del)} title="Supprimer ce repère" style="border:none;background:transparent;color:var(--color-text-muted);font-size:12px;cursor:pointer;padding:0 2px;flex:0 0 auto">✕</button>
              </div>`).join('')}

          <div style="padding:12px 13px;border-top:0.5px solid var(--color-border-tertiary);margin-top:auto">
            <!-- Le report dans le commentaire n'est plus un bouton : il se fait
                 à l'enregistrement, et la modale se referme dans la foulée. Un
                 avis partait sinon avec des repères que la boutique ne recevait
                 pas — la photo annotée, elle, reste dans le cockpit. -->
            <div style="font-size:11px;color:var(--color-text)">${z.reporte ? esc(String(z.reporte)) + ' repère(s) commenté(s) partiront dans le commentaire de l’avis.' : 'Aucun repère commenté : le commentaire de l’avis restera inchangé.'}</div>
            <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:6px;line-height:1.5">${esc(z.envoiBesoin)}</div>
            <div style="display:flex;gap:8px;margin-top:11px">
              <button ${x.A(z.save)} style="border:none;background:var(--color-primary);color:#fff;border-radius:9px;height:34px;padding:0 15px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:${z.busy ? 'wait' : 'pointer'};opacity:${z.busy ? '0.6' : '1'};flex:1">${z.busy ? 'Enregistrement…' : (z.saved ? 'Enregistré ✓' : 'Enregistrer les repères')}</button>
              <button ${x.A(z.close)} style="${tool(false, true)}">Fermer</button>
            </div>
            <!-- La confirmation et l'échec s'écrivent ICI, sous les boutons : le
                 bandeau de notification de l'application se pose en bas à droite
                 de l'écran, donc sur ce panneau, et recouvrait la réponse. -->
            ${z.err ? `<div style="margin-top:9px;padding:8px 10px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:11.5px;line-height:1.45">${esc(z.err)}</div>` : ''}
            ${!z.err && z.saved && z.savedTxt ? `<div style="margin-top:9px;font-size:11.5px;color:#2d7a3e">${esc(z.savedTxt)}</div>` : ''}
          </div>
        </div>
      </div>
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

      <div style="border-top:0.5px solid var(--color-border-tertiary);margin:26px 0 0"></div>

      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:22px">
        <div style="${sec}">Compte admin ERP (API TFBuddy)</div>
        <span style="${c.erEtatSt}">${esc(c.erEtat)}</span>
      </div>
      <div style="font-size:12px;color:var(--color-text-muted);margin-top:8px;line-height:1.55;text-wrap:pretty">La reprise du catalogue de l\u2019assistant de campagne (gammes saisonni\u00e8res, alias de noms, liens produit \u2194 gamme) passe par l\u2019API de l\u2019ERP d\u00e8s que ce compte est renseign\u00e9 \u2014 m\u00eames identifiants que l\u2019admin TFBuddy. Sans compte, la reprise retombe sur la lecture des tables. Le mot de passe n\u2019est jamais r\u00e9affich\u00e9 ; le laisser vide conserve celui d\u00e9j\u00e0 enregistr\u00e9.</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px">
        <div>
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:5px">T\u00e9l\u00e9phone du compte</div>
          <input type="text" value="${esc(c.erPhone)}" ${x.C(c.setErPhone)} placeholder="+32\u2026" style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)">
        </div>
        <div>
          <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:5px">Mot de passe</div>
          <input type="password" value="${esc(c.erPass)}" ${x.C(c.setErPass)} placeholder="${esc(c.erPassPlaceholder)}" autocomplete="new-password" style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)">
        </div>
      </div>
      <div style="margin-top:12px">
        <div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:5px">Base d\u2019URL de l\u2019API (vide = valeur par d\u00e9faut)</div>
        <input type="text" value="${esc(c.erBase)}" ${x.C(c.setErBase)} placeholder="https://atelierby.tfbuddy.com/api/v1" style="width:100%;box-sizing:border-box;font-size:13px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:9px 12px;background:var(--color-surface);color:var(--color-text)">
      </div>
      ${c.erMsg ? `<div style="${c.erMsgSt}">${esc(c.erMsg)}</div>` : ''}
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px">
        <button ${x.A(c.erTest)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:9px 16px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:${c.erBusy ? 'wait' : 'pointer'}">Tester la connexion</button>
        <button ${x.A(c.erSave)} style="border:none;border-radius:999px;padding:9px 20px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:${c.erBusy ? 'wait' : 'pointer'};opacity:${c.erBusy ? '0.6' : '1'}">${c.erBusy ? 'Enregistrement\u2026' : 'Enregistrer le compte'}</button>
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
      <span style="font-size:11.5px;color:var(--color-text-muted);align-self:center">${esc(c.scAuto || '')}</span>
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


/**
 * Centrale d'achat — gabarit commun aux dix écrans.
 *
 * Le point notable : quand la source manque, on ne montre PAS un écran vide
 * avec un message. On rend la table telle qu'elle sera, colonne par colonne,
 * et chaque ligne dit le champ attendu et l'API qui doit le fournir. Le
 * tableau devient la spécification du branchement : on y lit ce qui manque,
 * mais aussi ce que le cockpit possède déjà et qu'il est inutile de redemander.
 */
function tplCentrale(c, x){
  const { esc } = x;
  const CARD = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:16px';
  const TH = 'text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 12px 8px 0;white-space:nowrap';
  const TD = 'padding:8px 12px 8px 0;border-top:0.5px solid var(--color-border-tertiary);font-size:12.5px';
  const MANQUE = 'font-size:11px;font-weight:500;padding:2px 8px;border-radius:999px;background:#FBEFE0;color:var(--color-on-abricot);border:1px solid #E8C9A0;white-space:nowrap';

  const periodes = c.caPerBtns ? `<div style="display:flex;gap:3px;background:var(--color-background-secondary);padding:3px;border-radius:10px;width:fit-content;margin-bottom:14px">
      ${c.caPerBtns.map(b => `<button ${x.A(b.go)} style="${b.st}">${esc(b.label)}</button>`).join('')}
    </div>` : '';

  if (c.caChargement) {
    return `${periodes}<div style="${CARD};color:var(--color-text-muted);font-size:13px;padding:40px 16px">Chargement…</div>`;
  }

  // --- source absente : la table attendue, champ par champ
  if (c.caAttendu) {
    const n = c.caAttendu.filter(a => !a.dispo).length;
    return `${periodes}
    <div style="${CARD}">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px">
        <span style="${MANQUE}">manque API</span>
        <span style="font-size:13px;font-weight:500">${esc(c.caTitreSrc)}</span>
      </div>
      <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:14px">${esc(c.caSource)} · ${n} colonne${n > 1 ? 's' : ''} à obtenir sur ${c.caAttendu.length}</div>
      <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;min-width:660px">
        <thead><tr>
          <th style="${TH}">Colonne de l’écran</th>
          <th style="${TH}">Donnée attendue</th>
          <th style="${TH}">À obtenir de</th>
        </tr></thead>
        <tbody>${c.caAttendu.map(a => `<tr>
          <td style="${TD};white-space:nowrap">${esc(a.col)}</td>
          <td style="${TD};font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;color:var(--color-text-muted)">${esc(a.champ)}</td>
          <td style="${TD}">${a.dispo
            ? `<span style="font-size:11px;font-weight:500;padding:2px 8px;border-radius:999px;background:rgba(45,122,62,0.1);color:#2d7a3e;white-space:nowrap">déjà disponible</span>
               <span style="font-size:11px;color:var(--color-text-muted);margin-left:6px">${esc(a.src)}</span>`
            : `<span style="${MANQUE}">manque API</span>
               <span style="font-size:11.5px;color:var(--color-text);margin-left:6px">${esc(a.src)}</span>`}
            ${a.note ? `<div style="font-size:11px;color:var(--color-text-muted);margin-top:3px">${esc(a.note)}</div>` : ''}</td>
        </tr>`).join('')}</tbody>
      </table></div>
    </div>`;
  }

  if (c.caEtat === 'erreur' || (c.caMotif && !c.caCols && !c.caKpis)) {
    return `${periodes}<div style="${CARD}"><span style="${MANQUE}">manque API</span>
      <div style="font-size:12.5px;color:var(--color-text-muted);margin-top:8px">${esc(c.caMotif || 'source indisponible')}</div></div>`;
  }

  const kpis = c.caKpis ? `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:14px">
    ${c.caKpis.map(k => `<div style="${CARD}">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:6px">${esc(k.libelle)}</div>
      ${k.vide ? `<span style="${MANQUE}">manque API</span>`
        : `<div style="font-family:var(--font-display);font-size:24px;line-height:1">${esc(k.valeur)}</div>`}
    </div>`).join('')}
  </div>` : '';

  const manquants = (c.caManquants && c.caManquants.length) ? `<div style="${CARD};margin-bottom:14px">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:8px">Reste à brancher</div>
    ${c.caManquants.map(m => `<div style="display:flex;gap:8px;align-items:baseline;font-size:12px;color:var(--color-text-muted);padding:3px 0">
      <span style="${MANQUE}">manque API</span><span>${esc(m)}</span></div>`).join('')}
  </div>` : '';

  const params = c.caParams ? `<div style="${CARD};margin-bottom:14px">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:10px">Moteur de marge</div>
    <div style="display:flex;gap:26px;flex-wrap:wrap">
      ${[['Commission marque', c.caParams.commissionMarquePct, '%'], ['Marge centrale cible', c.caParams.margeCentraleCiblePct, '%'],
         ['TVA par défaut', c.caParams.tvaDefautPct, '%'], ['Objectif baisse prix', c.caParams.objectifBaissePrixPct, '%'],
         ['Objectif hausse volume', c.caParams.objectifHausseVolPct, '%']].map(p => `<div>
        <div style="font-size:11px;color:var(--color-text-muted)">${esc(p[0])}</div>
        <div style="font-size:15px;font-variant-numeric:tabular-nums">${esc(String(p[1] == null ? '—' : String(p[1]).replace('.', ',')))} ${esc(p[2])}</div></div>`).join('')}
    </div></div>` : '';

  const recherche = (c.caEcran === 'caCatalogue') ? `<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
    <input id="ca-search" type="search" value="${esc(c.caQ || '')}" ${x.I(c.caSetQ)} placeholder="Rechercher une référence, une catégorie…"
      style="font-family:var(--font-ui);font-size:13px;padding:8px 10px;border-radius:9px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);min-width:260px;flex:0 1 320px">
    <span style="font-size:11.5px;color:var(--color-text-muted)">${c.caRows ? c.caRows.length : 0} sur ${c.caTotal || 0} référence${(c.caTotal || 0) > 1 ? 's' : ''}${(c.caRows && c.caRows.length >= 300) ? ' · 300 affichées' : ''}</span>
  </div>` : '';

  const table = c.caCols ? `<div style="${CARD}">
    ${c.caAvert ? `<div style="font-size:11.5px;color:var(--color-on-abricot);background:#FBEFE0;border:1px solid #E8C9A0;padding:6px 10px;border-radius:8px;margin-bottom:12px">${esc(c.caAvert)}</div>` : ''}
    ${c.caChips && c.caChips.length ? `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      ${c.caChips.map(ch => `<button ${x.A(ch.pick)} style="border:1px solid ${ch.on ? ch.texte : 'var(--color-border-secondary)'};cursor:pointer;border-radius:999px;padding:5px 13px;font-family:var(--font-ui);font-size:11.5px;font-weight:600;background:${ch.on ? ch.fond : 'transparent'};color:${ch.texte}">${esc(ch.nom)}</button>`).join('')}
      ${c.caChips.some(ch => ch.on) ? `<span style="align-self:center;font-size:11px;color:var(--color-text-muted)">re-cliquer pour tout afficher</span>` : ''}
    </div>` : ''}
    ${c.caFiche ? `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px 22px;margin-bottom:14px;padding:12px 14px;background:var(--color-background-secondary);border-radius:10px">
      ${c.caFiche.map(f2 => `<div><div style="font-size:10px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">${esc(f2[0])}</div>
        <div style="font-size:12.5px;margin-top:2px">${esc(f2[1])}</div></div>`).join('')}
    </div>` : ''}
    ${c.caVide ? `<div style="font-size:12.5px;color:var(--color-text-muted);padding:16px 0">${esc(c.caVide)}</div>` : !c.caRows.length ? `
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:600px">
      <thead><tr>${c.caCols.map((h, i) => `<th style="${TH}${i ? ';text-align:right' : ''}">${esc(h)}</th>`).join('')}</tr></thead>
      <tbody><tr><td colspan="${c.caCols.length}" style="${TD};color:var(--color-text-muted);font-size:12.5px;padding-top:14px">${esc(c.caRien || 'aucune ligne')}</td></tr></tbody>
    </table></div>` : `
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;min-width:720px">
      <thead><tr>${c.caCols.map((h, i) => `<th style="${TH}${i ? ';text-align:right' : ''}">${esc(h)}</th>`).join('')}</tr></thead>
      <tbody>${c.caRows.map(r => `<tr>${r.cells.map(q => `<td style="${TD}${q.num ? ';text-align:right;font-variant-numeric:tabular-nums' : ''}${q.mut ? ';color:var(--color-text-muted)' : ''}${q.col ? ';color:' + q.col : ''}">${
        q.vide ? `<span style="${MANQUE}">${esc(q.t)}</span>`
        : q.act ? `<button ${x.A(q.act)} class="hv-line" style="border:none;background:none;padding:0;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:500;color:var(--color-text);text-align:left">${esc(q.t)}</button>`
        : esc(q.t)}</td>`).join('')}</tr>`).join('')}</tbody>
    </table></div>`}
    ${c.caNote ? `<div style="font-size:11px;color:var(--color-text-muted);margin-top:10px">${esc(c.caNote)}</div>` : ''}
    ${c.caSource ? `<div style="font-size:11px;color:var(--color-text-muted);margin-top:6px">source : ${esc(c.caSource)}</div>` : ''}
  </div>` : '';

  // Second tableau optionnel (ex. CA réseau cumulé sous le suivi fournisseurs) —
  // même rendu de cellules que le tableau principal.
  const table2 = c.caTable2 ? `<div style="${CARD};margin-top:14px">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:10px">${esc(c.caTable2.titre || '')}</div>
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:420px">
      <thead><tr>${c.caTable2.cols.map((h, i) => `<th style="${TH}${i ? ';text-align:right' : ''}">${esc(h)}</th>`).join('')}</tr></thead>
      <tbody>${c.caTable2.rows.map(r => `<tr>${r.cells.map(q => `<td style="${TD}${q.num ? ';text-align:right;font-variant-numeric:tabular-nums' : ''}${q.mut ? ';color:var(--color-text-muted)' : ''}">${esc(q.t)}</td>`).join('')}</tr>`).join('')}</tbody>
    </table></div>
  </div>` : '';

  return periodes + kpis + manquants + params + recherche + table + table2;
}


/**
 * Diagnostic API — ce qui manque, et ce qui est lent.
 *
 * Les deux se corrigent au même endroit : l'API amont. Réunis sur une page,
 * ils forment la demande à porter — quelles données réclamer, et quelles routes
 * faire accélérer, avec les durées mesurées à l'appui. « C'est lent » ne fait
 * bouger personne ; « /exploitation/magasin, 6,4 s au pire, 4 appels dont 2 en
 * série » se traite.
 */
function tplDiagnostic(c, x){
  const { esc } = x;
  const CARD = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:16px';
  const TH = 'text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 12px 8px 0;white-space:nowrap';
  const TD = 'padding:8px 12px 8px 0;border-top:0.5px solid var(--color-border-tertiary);font-size:12.5px';
  const MANQUE = 'font-size:11px;font-weight:500;padding:2px 8px;border-radius:999px;background:#FBEFE0;color:var(--color-on-abricot);border:1px solid #E8C9A0;white-space:nowrap';
  const SAISIE = 'font-size:11px;font-weight:500;padding:2px 8px;border-radius:999px;background:var(--color-background-secondary);color:var(--color-text-muted);border:1px solid var(--color-border-tertiary);white-space:nowrap';
  const MONO = 'font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px';

  return `
  <!-- Les quatre systèmes extérieurs dont le cockpit dépend. L'état vient de
       ceo_connecteur, écrite sur les GESTES (synchronisation, test de compte,
       appel au modèle) ; « configuré » est demandé au client à chaque lecture,
       pour qu'une clé retirée se voie sans attendre un appel. -->
  <div style="${CARD};margin-bottom:16px">
    <div style="font-size:13px;font-weight:600">Connecteurs</div>
    <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px;line-height:1.5;text-wrap:pretty">
      Les systèmes dont dépendent les écrans. « Dernier succès » date le dernier geste qui a abouti — il survit à un échec, pour distinguer une panne d'une configuration jamais faite.
    </div>
    ${c.coChargement ? `<div style="font-size:12px;color:var(--color-text-muted);padding:12px 0 2px">Lecture de l'état…</div>` : `
    <div style="overflow-x:auto;margin-top:10px"><table style="width:100%;border-collapse:collapse;min-width:640px">
      <thead><tr>
        <th style="${TH}">Connecteur</th>
        <th style="${TH}">État</th>
        <th style="${TH}">Dernier succès</th>
        <th style="${TH}">Dernier appel</th>
        <th style="${TH};text-align:right">Passages</th>
      </tr></thead>
      <tbody>${c.coLignes.map(l => `
        <tr>
          <td style="${TD}">
            <div style="font-size:12.5px;font-weight:500">${esc(l.nom)}</div>
            <div style="font-size:11px;color:var(--color-text-muted);line-height:1.35">${esc(l.quoi)}</div>
            ${l.detail ? `<div style="${l.detailSt};margin-top:3px">${esc(l.detail)}</div>` : ''}
          </td>
          <td style="${TD}"><span style="${l.etatSt}">${esc(l.etat)}</span></td>
          <td style="${TD};white-space:nowrap">${esc(l.succes)}</td>
          <td style="${TD};white-space:nowrap;color:var(--color-text-muted)">${esc(l.appel)}</td>
          <td style="${TD};text-align:right;font-variant-numeric:tabular-nums">${esc(l.passages)}</td>
        </tr>`).join('')}</tbody>
    </table></div>`}
  </div>

  <!-- Nettoyage du module marketing : la liste exacte AVANT le geste, et le
       geste ne part que sur confirmation. Un DROP ne se rejoue pas. -->
  ${c.marNet && !c.marNet.chargement && !c.marNet.indispo ? `
  <div style="${CARD};margin-bottom:16px;border:1px solid ${c.marNet.tombe.length ? 'rgba(141,29,44,0.4)' : 'var(--color-border-tertiary)'}">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div>
        <div style="font-size:13px;font-weight:600">Nettoyage du module marketing</div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px;line-height:1.5">
          Gardées : ${c.marNet.garde.length} table(s) — campagnes (mar_campaign*), fonds &amp; redevances (mar_fund*, mar_royalt*), référentiels (mar_brand, mar_shop, mar_lever).
          ${c.marNet.dejaFait ? ' Déjà fait : ' + esc(c.marNet.dejaFait) + '.' : ''}
        </div>
      </div>
      ${c.marNet.executer ? `<button ${x.A(c.marNet.executer)} style="border:none;background:var(--color-primary);color:#fff;border-radius:999px;height:34px;padding:0 17px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:${c.marNet.busy ? 'wait' : 'pointer'}">${c.marNet.busy ? 'Suppression…' : 'Supprimer ' + c.marNet.tombe.length + ' table(s) inutilisée(s)'}</button>`
        : `<span style="font-size:12px;color:#2d7a3e;font-weight:500">Rien à supprimer — la base ne porte que le nécessaire.</span>`}
    </div>
    ${c.marNet.tombe.length ? `<div style="margin-top:10px;font-size:11px;color:var(--color-text-muted);line-height:1.6">Tomberaient : ${c.marNet.tombe.map(t => `<span style="display:inline-block;background:var(--color-background-secondary);border:0.5px solid var(--color-border-tertiary);border-radius:6px;padding:1px 7px;margin:1px 3px 1px 0;font-family:ui-monospace,Menlo,monospace;font-size:10.5px">${esc(t.nom)}${t.lignes ? ' · ' + t.lignes + ' l.' : ''}</span>`).join('')}</div>` : ''}
  </div>` : ''}
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px">
    <div style="${CARD}">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:6px">À réclamer à l’API</div>
      <div style="font-family:var(--font-display);font-size:26px;line-height:1;color:var(--color-on-abricot)">${c.diagNbApi || 0}</div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:3px">donnée(s) qu’aucune source n’expose</div>
    </div>
    <div style="${CARD}">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:6px">À renseigner</div>
      <div style="font-family:var(--font-display);font-size:26px;line-height:1">${c.diagNbSaisie || 0}</div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:3px">la source existe, elle est vide</div>
    </div>
    <div style="${CARD}">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:6px">Appels &gt; ${esc(c.diagSeuil)} s</div>
      <div style="font-family:var(--font-display);font-size:26px;line-height:1;color:${(c.diagLentes || []).length ? 'var(--color-primary)' : '#2d7a3e'}">${(c.diagLentes || []).length}</div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:3px">route(s) lente(s) sur ${c.diagTotal || 0} appel(s)</div>
    </div>
  </div>

  <div style="${CARD};margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:4px">
      <div style="font-size:13px;font-weight:500">Routes dépassant ${esc(c.diagSeuil)} secondes</div>
      ${(c.diagLentes || []).length ? `<button ${x.A(c.diagRaz)} style="border:none;background:transparent;cursor:pointer;font-family:var(--font-ui);font-size:11.5px;color:var(--color-text-muted)" class="hv-line">réinitialiser la mesure</button>` : ''}
    </div>
    <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:12px">Mesuré depuis l’ouverture de l’application, sur les appels réellement effectués. Un appel en erreur est retenu quelle que soit sa durée.</div>
    ${!(c.diagLentes || []).length
      ? `<div style="font-size:12.5px;color:#2d7a3e">Aucun appel au-delà de ${esc(c.diagSeuil)} s sur les ${c.diagTotal || 0} mesurés. Ouvrez les écrans lourds (Analyse dans le temps, Catalogue, P&amp;L d’un magasin) puis revenez ici.</div>`
      : `<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:600px">
      <thead><tr>
        <th style="${TH}">Route</th>
        <th style="${TH};text-align:right">Appels</th>
        <th style="${TH};text-align:right">Moyenne</th>
        <th style="${TH};text-align:right">Pire</th>
        <th style="${TH}">État</th>
      </tr></thead>
      <tbody>${c.diagLentes.map(l => `<tr>
        <td style="${TD};${MONO}">${esc(l.path)}</td>
        <td style="${TD};text-align:right;font-variant-numeric:tabular-nums">${l.n}</td>
        <td style="${TD};text-align:right;font-variant-numeric:tabular-nums;color:var(--color-text-muted)">${esc(l.moy)} ms</td>
        <td style="${TD};text-align:right;font-variant-numeric:tabular-nums;font-weight:500;color:${l.col}">${esc(l.max)} ms</td>
        <td style="${TD}">${l.ko ? `<span style="${MANQUE}">${l.ko} en erreur</span>` : '<span style="font-size:11.5px;color:var(--color-text-muted)">répond</span>'}</td>
      </tr>`).join('')}</tbody></table></div>`}
  </div>

  ${(c.frSources || []).length ? `<div style="${CARD};margin-bottom:16px">
    <div style="font-size:13px;font-weight:500;margin-bottom:4px">Fraîcheur des sources</div>
    <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:12px">Une source en retard ne provoque aucune erreur : elle rend un total d’hier qui reste plausible aujourd’hui. ${esc(c.frResume)} (référence : ${esc(c.frAuj)}).</div>
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:560px">
      <thead><tr><th style="${TH}">Source</th><th style="${TH}">Contenu</th><th style="${TH}">Dernière donnée</th><th style="${TH};text-align:right">Retard</th></tr></thead>
      <tbody>${c.frSources.map(f => `<tr>
        <td style="${TD};${MONO}">${esc(f.table)}</td>
        <td style="${TD};color:var(--color-text-muted)">${esc(f.quoi)}</td>
        <td style="${TD};font-variant-numeric:tabular-nums">${esc(f.derniere)}</td>
        <td style="${TD};text-align:right;font-weight:500;color:${f.col}">${esc(f.retard)}</td>
      </tr>`).join('')}
      ${(c.frApi || []).map(a => `<tr>
        <td style="${TD};${MONO};border-top:0.5px solid var(--color-border-secondary)">${esc(a.route)}</td>
        <td style="${TD};color:var(--color-text-muted);border-top:0.5px solid var(--color-border-secondary)">API amont · ${esc(a.detail)}</td>
        <td colspan="2" style="${TD};border-top:0.5px solid var(--color-border-secondary);color:${a.ok ? '#2d7a3e' : 'var(--color-primary)'};font-weight:500">${esc(a.verdict)}</td>
      </tr>`).join('')}</tbody></table></div>
    ${(c.frEcrans || []).length ? `<div style="margin-top:14px">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:8px">Écrans alimentés par la base plutôt que par l’API</div>
      ${c.frEcrans.map(e => `<div style="padding:7px 0;border-top:0.5px solid var(--color-border-tertiary)">
        <div style="font-size:12.5px;font-weight:500">${esc(e.ecran)} <span style="${MONO};font-weight:400;color:var(--color-text-muted)">${esc(e.route)}</span></div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">lit ${esc(e.lit)} — ${esc(e.consequence)}</div>
        <div style="font-size:11.5px;color:var(--color-on-abricot);margin-top:2px">→ ${esc(e.remplacer)}</div>
      </div>`).join('')}
    </div>` : ''}
  </div>` : ''}

  <div style="${CARD}">
    <div style="font-size:13px;font-weight:500;margin-bottom:4px">Ce que le cockpit ne peut pas afficher</div>
    <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:14px">Détecté sur l’état réel des données, écran par écran. « manque API » : personne ne l’expose, il faut le réclamer. « à renseigner » : la source existe et attend d’être remplie.</div>
    ${c.diagChargement ? `<div style="font-size:12.5px;color:var(--color-text-muted)">Analyse en cours…</div>`
      : !(c.diagGroupes || []).length ? `<div style="font-size:12.5px;color:#2d7a3e">Aucune lacune détectée.</div>`
      : c.diagGroupes.map(g => `
      <div style="margin-bottom:16px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding-bottom:6px;border-bottom:0.5px solid var(--color-border-tertiary)">${esc(g.ecran)}</div>
        ${g.lignes.map(l => `<div style="display:flex;gap:9px;align-items:baseline;padding:7px 0;flex-wrap:wrap;border-bottom:0.5px solid var(--color-border-tertiary)">
          <span style="${l.api ? MANQUE : SAISIE}">${esc(l.etiquette)}</span>
          <span style="font-size:12.5px;font-weight:500;min-width:130px">${esc(l.champ)}</span>
          <span style="font-size:12px;color:var(--color-text-muted);flex:1;min-width:220px">${esc(l.quoi)}<div style="margin-top:2px">${esc(l.source)}</div></span>
        </div>`).join('')}
      </div>`).join('')}
  </div>`;
}


/**
 * Références sous seuil — l'écran de scoring trie, celui-ci coupe.
 *
 * Le curseur fixe la ligne, le tableau rend tout ce qui passe dessous, et la
 * dernière colonne nomme le critère le plus faible : c'est elle qui transforme
 * « cette référence est mauvaise » en « ce coût est à renégocier ». Sans elle
 * la liste ne dit pas sur quoi agir.
 */
function tplSeuil(c, x){
  const { esc } = x;
  const CARD = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:16px';
  const TH = 'text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 12px 8px 0;white-space:nowrap';
  const TD = 'padding:8px 12px 8px 0;border-top:0.5px solid var(--color-border-tertiary);font-size:12.5px';
  const NUM = ';text-align:right;font-variant-numeric:tabular-nums';
  const MANQUE = 'font-size:10.5px;font-weight:500;padding:1px 7px;border-radius:999px;background:#FBEFE0;color:var(--color-on-abricot);border:1px solid #E8C9A0;white-space:nowrap';
  const SEL = 'font-family:var(--font-ui);font-size:13px;padding:8px 10px;border-radius:9px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text)';

  return `
  <div style="${CARD};margin-bottom:14px;display:flex;flex-direction:column;gap:12px">
    <div style="display:flex;gap:18px;align-items:flex-end;flex-wrap:wrap">
      <div style="flex:1;min-width:240px">
        <label style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:7px">Seuil de score</label>
        <div style="display:flex;align-items:center;gap:12px">
          <input id="sq-seuil" type="range" min="0" max="100" step="1" value="${c.sqSeuil}" ${x.I(c.sqSetSeuil)} style="flex:1;min-width:140px;accent-color:var(--color-primary)">
          <span style="font-family:var(--font-display);font-size:26px;line-height:1;min-width:44px;text-align:right">${c.sqSeuil}</span>
        </div>
        <div style="font-size:11px;color:var(--color-text-muted);margin-top:5px">${esc(c.sqRepere)}</div>
      </div>
      <div>
        <label style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:7px">Catégorie</label>
        <select ${x.C(c.sqSetCat)} style="${SEL};min-width:190px">${(c.sqCatOptions || []).map(o => `<option value="${esc(o)}"${o === c.sqCat ? ' selected' : ''}>${esc(o)}</option>`).join('')}</select>
      </div>
      <div>
        <label style="display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:7px">Tri</label>
        <select ${x.C(c.sqSetTri)} style="${SEL};min-width:190px">${(c.sqTriOptions || []).map(o => `<option value="${esc(o.val)}"${o.val === c.sqTri ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>
      </div>
      ${c.sqNb ? `<button ${x.A(c.sqExport)} class="hv-fade" style="border:none;cursor:pointer;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:9px 18px;border-radius:999px">Exporter en CSV</button>` : ''}
    </div>
    <div style="font-size:12px;color:var(--color-text-muted)">
      <strong style="font-weight:700;color:var(--color-text)">${c.sqNb}</strong> référence${c.sqNb > 1 ? 's' : ''} sous ${c.sqSeuil} sur ${c.sqTotal} — elles pèsent <strong style="font-weight:700;color:var(--color-text)">${esc(c.sqCaPart)}</strong> du CA réseau. Pondération : ${esc(c.sqPond)}.
    </div>
  </div>

  <div style="${CARD}">
    ${!c.sqNb ? `<div style="padding:22px 0;font-size:13px;color:#2d7a3e">Aucune référence sous ce seuil${c.sqCat !== 'Toutes les catégories' ? ' dans ' + esc(c.sqCat) : ''}. Relevez le curseur pour élargir.</div>` : `
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;min-width:880px">
      <thead><tr>
        <th style="${TH}">Référence</th>
        <th style="${TH}">Catégorie</th>
        <th style="${TH}${NUM}">Score</th>
        <th style="${TH}">Verdict</th>
        <th style="${TH}${NUM}">Volume</th>
        <th style="${TH}${NUM}">CA réseau</th>
        <th style="${TH}${NUM}">Marge</th>
        <th style="${TH}${NUM}">Perte</th>
        <th style="${TH}">Critère le plus faible</th>
      </tr></thead>
      <tbody>${c.sqLignes.map(r => `<tr>
        <td style="${TD};font-weight:500">${esc(r.nom)}</td>
        <td style="${TD};color:var(--color-text-muted)">${esc(r.cat)}</td>
        <td style="${TD}${NUM};font-weight:700;color:${r.scoreCol}">${r.score}</td>
        <td style="${TD}"><span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:500;background:${r.vFond};color:${r.vCol};white-space:nowrap">${esc(r.verdict)}</span></td>
        <td style="${TD}${NUM}">${esc(r.vol)}</td>
        <td style="${TD}${NUM}">${esc(r.ca)}</td>
        <td style="${TD}${NUM}">${r.margeVide ? `<span style="${MANQUE}">manque API</span>` : esc(r.marge)}</td>
        <td style="${TD}${NUM}">${r.perteVide ? `<span style="${MANQUE}">manque API</span>` : esc(r.perte)}</td>
        <td style="${TD};color:var(--color-text-muted)">${esc(r.faible)}</td>
      </tr>`).join('')}</tbody>
    </table></div>`}
  </div>`;
}
