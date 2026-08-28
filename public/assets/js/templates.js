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
      ${c.isUsage ? tplUsage(c, x) : ''}
      ${c.isManque ? tplManque(c, x) : ''}
      ${c.isAnm ? tplAnm(c, x) : ''}
      ${c.isVentes ? tplVentes(c, x) : ''}
      ${c.isCrois ? tplCrois(c, x) : ''}
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
      ${c.isMesure ? (c.mesSimple ? tplMesureComp(c, x) : tplMesure(c, x)) : ''}
      ${c.isProduits ? tplProduits(c, x) : ''}
      ${c.isProjets ? tplProjets(c, x) : ''}
      ${c.isControle ? tplControle(c, x) : ''}
      ${c.isSuiviM ? tplSuiviM(c, x) : ''}
      ${c.isKpiT ? tplKpiT(c, x) : ''}
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
        ${c.refLignes.map(l => `<tr ${l.prendre ? 'draggable="true" ' + x.DS(l.prendre) : ''}${l.prendre ? ' title="Glissez cette référence sur un emplacement du comptoir" style="cursor:grab"' : ''}>
          <td style="${TD}">
            <button ${x.A(l.ouvrir)} style="border:none;background:transparent;padding:0;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:500;color:var(--color-text);text-align:left" class="hv-line">${esc(l.nom)}</button>
            ${l.finLe ? `<span style="display:inline-block;margin-left:7px;font-size:10px;font-weight:600;padding:1px 8px;border-radius:999px;background:rgba(141,29,44,0.09);color:var(--color-primary)">fin le ${esc(l.finLe)}</span>` : ''}
            <div style="font-size:10.5px;color:var(--color-text-muted)">${esc(l.ref)}${l.gamme !== '' ? ' · ' + esc(l.gamme) : ''}</div>
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
                <span style="font-size:12px;${l.place ? '' : 'color:var(--color-text-muted)'}">${l.place ? esc(l.emplacement) : (l.must ? 'pas de place au comptoir' : '')}</span>
                <button ${x.A(l.planoGo)} title="Ouvrir le planogramme actuel pour cette référence"
                  style="flex:0 0 auto;border:0.5px solid ${l.place ? 'var(--color-border-secondary)' : 'var(--color-primary)'};background:transparent;color:${l.place ? 'var(--color-text-muted)' : 'var(--color-primary)'};border-radius:999px;padding:3px 11px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer;white-space:nowrap">${esc(l.planoBtn)}</button>
              </div>
            </td>` : ''}
          ${c.isPlano ? `<td style="${TD}">${esc(l.zone) || '<span style="color:var(--color-text-muted)"></span>'}</td>
            <td style="${TD};color:var(--color-text-muted)">${esc(l.meuble) || ''}</td>
            <td style="${TD};color:var(--color-text-muted)">${esc(l.niveau) || ''}</td>
            <td style="${TD};${num}">${esc(l.slot) || ''}</td>` : ''}
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
/**
 * Mesure d'une campagne — la LECTURE : une rangée par magasin, sa courbe, ses
 * deux périodes chacune contre son N-1, et l'effet net. Rien d'autre : le
 * paramétrage vit derrière un bouton, il ne s'impose plus avant de lire.
 */
function tplMesureComp(c, x){
  const { esc } = x;
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:15px 17px';
  const lbl = 'font-size:9.5px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:var(--color-text-muted)';
  const seg = o => `<button ${x.A(o.go)} style="border:none;padding:6px 13px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer;${o.on ? 'background:var(--color-primary);color:#fff' : 'background:transparent;color:var(--color-text-muted)'}">${esc(o.nom)}</button>`;
  const grp = l => `<span style="display:inline-flex;border:0.5px solid var(--color-border-secondary);border-radius:999px;overflow:hidden">${l.map(seg).join('')}</span>`;

  const barre = `
    <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:13px">
      <select ${x.C(c.mcCampSet)} style="font-size:12.5px;font-weight:500;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:6px 10px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui);max-width:400px">
        ${(c.mcCampOpts || []).map(o => `<option value="${o.v}"${o.v === c.mcCampSel ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}
      </select>
      ${grp(c.mcMesures || [])}
      ${(c.mcUnites || []).length && !c.mcUniteInutile ? grp(c.mcUnites) : ''}
      <span style="flex:1"></span>
      ${c.mcRecalcul ? '<span style="font-size:11.5px;color:var(--color-primary)">Lecture en cours…</span>' : ''}
      <button ${x.A(c.mcAvance)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text-muted);border-radius:8px;height:30px;padding:0 12px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Paramétrage avancé</button>
    </div>`;

  if (c.mcIndispo) {
    return `<div style="${carte}">${barre}<div style="font-size:12.5px;color:var(--color-text-muted);line-height:1.55">${esc(c.mcIndispo)}</div></div>`;
  }
  if (c.mcChargement) {
    return `<div style="${carte}">${barre}<div style="font-size:12.5px;color:var(--color-text-muted)">Lecture des ventes, jour par jour, sur les deux années…</div></div>`;
  }
  if (c.mcVide) { return `<div style="${carte}">${barre}</div>`; }

  const rangee = (m, temoin) => `
    <div style="${carte};padding:12px 14px;margin-bottom:8px;${temoin ? 'background:var(--color-background-secondary);border-style:dashed' : ''}">
      <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
        <div style="min-width:206px;flex:0 0 auto">
          <div style="font-size:13px;font-weight:600;${temoin ? 'color:var(--color-text-muted)' : ''}">${esc(m.nom)}${m.faible && !temoin ? ` <span style="font-size:9.5px;font-weight:600;padding:1px 7px;border-radius:999px;background:#FBF3DC;color:var(--color-on-abricot);border:1px solid #E8C9A0;vertical-align:1px">à confirmer</span>` : ''}</div>
          ${temoin ? `<div style="font-size:10.5px;color:var(--color-text-muted)">${m.magasins} magasin(s) qui n’ont rien lancé</div>` : ''}
          <div style="margin-top:7px">${m.courbe}</div>
        </div>
        ${m.manque
          ? `<div style="flex:1;font-size:12px;color:var(--color-text-muted);padding-top:6px">${esc(m.manque)}</div>`
          : `<div style="flex:1;display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
          <div style="min-width:96px">
            <div style="${lbl}">Avant</div>
            <div style="font-size:17px;font-weight:600;font-variant-numeric:tabular-nums;line-height:1.2">${esc(m.avant.v)}${c.mcUniteTxt ? `<span style="font-size:10.5px;font-weight:400;color:var(--color-text-muted)"> ${esc(c.mcUniteTxt)}</span>` : ''}</div>
            <div style="font-size:10.5px;color:var(--color-text-muted);font-variant-numeric:tabular-nums">N-1 : ${esc(m.avant.n1)} · <b style="color:${m.avant.col};font-weight:600">${esc(m.avant.ecart)}</b></div>
          </div>
          <div style="min-width:96px">
            <div style="${lbl}">Pendant</div>
            <div style="font-size:17px;font-weight:600;font-variant-numeric:tabular-nums;line-height:1.2">${esc(m.pendant.v)}${c.mcUniteTxt ? `<span style="font-size:10.5px;font-weight:400;color:var(--color-text-muted)"> ${esc(c.mcUniteTxt)}</span>` : ''}</div>
            <div style="font-size:10.5px;color:var(--color-text-muted);font-variant-numeric:tabular-nums">N-1 : ${esc(m.pendant.n1)} · <b style="color:${m.pendant.col};font-weight:600">${esc(m.pendant.ecart)}</b></div>
          </div>
          ${c.mcParJour && !c.mcUniteInutile ? `<div style="min-width:96px">
            <div style="${lbl}">Sur la période</div>
            <div style="font-size:15px;font-weight:600;font-variant-numeric:tabular-nums;line-height:1.2">${esc(m.periode.v)}</div>
            <div style="font-size:10.5px;color:var(--color-text-muted);font-variant-numeric:tabular-nums">N-1 : ${esc(m.periode.n1)}</div>
          </div>` : ''}
          <div style="margin-left:auto;text-align:right;background:var(--color-background-secondary);border-radius:9px;padding:7px 13px;min-width:120px">
            <div style="${lbl};color:var(--color-on-abricot)">Effet net</div>
            <div style="font-size:19px;font-weight:700;color:${m.netCol};font-variant-numeric:tabular-nums;line-height:1.2">${esc(m.net)}</div>
            <div style="font-size:9.5px;color:var(--color-text-muted)">écart pendant − écart avant</div>
          </div>
        </div>`}
      </div>
    </div>`;

  return `
    <div style="${carte};margin-bottom:12px">
      ${barre}
      <div style="display:flex;gap:22px;flex-wrap:wrap;font-size:11.5px;color:var(--color-text-muted)">
        <span><b style="color:var(--color-text);font-weight:600">${esc(c.mcNom)}</b>${c.mcType ? ' · ' + esc(c.mcType) : ''}${c.mcScope ? ' · ' + esc(c.mcScope) : ''}</span>
        <span>Avant : <b style="color:var(--color-text);font-weight:500">${esc(c.mcFen.avant)}</b> <span style="opacity:.7">(N-1 ${esc(c.mcFen.avantN1)})</span></span>
        <span>Pendant : <b style="color:var(--color-text);font-weight:500">${esc(c.mcFen.pendant)}</b> <span style="opacity:.7">(N-1 ${esc(c.mcFen.pendantN1)})</span></span>
        <span>${c.mcFen.jours} jour(s)</span>
        ${c.mcFen.aVenir ? `<span style="color:var(--color-on-abricot)">${esc(c.mcFen.aVenir)}</span>` : ''}
        ${c.mcLibre ? `<span style="color:var(--color-primary);font-weight:500">période choisie à la main</span>` : ''}
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:9px">
        <span style="font-size:10.5px;color:var(--color-text-muted)">Mesurer du</span>
        <input type="date" value="${esc(c.mcDu)}" ${x.C(c.mcDuSet)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:7px;height:28px;padding:0 8px;font-family:var(--font-ui);font-size:11.5px">
        <span style="font-size:10.5px;color:var(--color-text-muted)">au</span>
        <input type="date" value="${esc(c.mcAu)}" ${x.C(c.mcAuSet)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:7px;height:28px;padding:0 8px;font-family:var(--font-ui);font-size:11.5px">
        ${c.mcLibreRaz ? `<button ${x.A(c.mcLibreRaz)} style="border:none;background:none;color:var(--color-text-muted);font-size:11px;cursor:pointer;text-decoration:underline;text-underline-offset:2px">revenir à la campagne</button>` : `<span style="font-size:10.5px;color:var(--color-text-muted)">par défaut, la période de la campagne — la période d’avant suit automatiquement</span>`}
      </div>
      ${c.mcPasCommencee ? `<div style="margin-top:10px;padding:9px 12px;border-radius:8px;background:#FBF3DC;border:1px solid #E8C9A0;font-size:11.5px;color:var(--color-on-abricot);line-height:1.5">Cette campagne <b style="font-weight:600">n’a pas encore commencé</b> : il n’y a rien à mesurer. Les fenêtres ci-dessus sont celles qui seront comparées le moment venu — ou choisissez une période passée pour lire un autre épisode.</div>` : ''}
      <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:10.5px;color:var(--color-text-muted);margin-top:8px;align-items:center">
        <span><span style="display:inline-block;width:20px;border-top:2px solid var(--color-primary);vertical-align:4px;margin-right:5px"></span>Cette année</span>
        <span><span style="display:inline-block;width:20px;border-top:2px dashed #c9b8a8;vertical-align:4px;margin-right:5px"></span>N-1, mêmes semaines</span>
        <span><span style="display:inline-block;width:20px;height:10px;background:rgba(141,29,44,0.10);border:1px solid rgba(141,29,44,0.30);vertical-align:-1px;margin-right:5px"></span>Campagne</span>
        <span style="margin-left:auto">${esc(c.mcPerimetre)}</span>
      </div>
    </div>
    ${(c.mcMagasins || []).map(m => rangee(m, false)).join('')}
    ${(c.mcHors || []).length ? `<div style="font-size:10px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted);margin:14px 0 7px 2px">Hors campagne — ils n’ont rien lancé</div>` : ''}
    ${(c.mcHors || []).map(m => rangee(m, false)).join('')}
    ${c.mcTemoin ? rangee(c.mcTemoin, true) : ''}
    <div style="${carte}">
      <div style="font-size:11.5px;line-height:1.55">${esc(c.mcBruitTxt)}
        <div style="color:var(--color-text-muted);margin-top:5px">L’<b style="font-weight:500">effet net</b> est la seule mesure qui parle de la campagne : écart pendant − écart avant. Un magasin déjà en croissance avant la campagne ne lui doit pas cette croissance.</div>
        <div style="color:var(--color-text-muted);margin-top:5px">${esc(c.mcSource)}</div>
        ${(c.mcMotifs || []).map(m => `<div style="color:var(--color-on-abricot);margin-top:4px">${esc(m)}</div>`).join('')}
      </div>
    </div>`;
}

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
      ${c.mesRetour ? `<button ${x.A(c.mesRetour)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text);border-radius:8px;height:30px;padding:0 12px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">← Revenir à la lecture</button>` : ''}
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
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">${esc(t.avant)}${t.d !== '' ? ' · <b style="color:' + t.dCol + '">' + esc(t.d) + '</b>' : ''}</div>
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
              <td style="${td};color:var(--color-text-muted);font-style:italic">bruit de fond</td><td style="${td}"></td></tr>`).join('')}
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
          <td style="${td};text-align:left;color:var(--color-text-muted);font-size:12px">${esc(p.categorie || '')}</td>
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
          <th style="text-align:right;${lbl};padding:0 8px 8px">Panier moyen <sup style="color:var(--color-primary)">(i)</sup></th>
          <th style="text-align:right;${lbl};padding:0 8px 8px">Clients A-1</th>
          <th style="text-align:right;${lbl};padding:0 8px 8px">Clients prévus</th>
          <th style="text-align:right;${lbl};padding:0 8px 8px">Base A-1</th>
          <th style="text-align:right;${lbl};padding:0 8px 8px">Gain campagne</th>
          <th style="text-align:right;${lbl};padding:0 8px 8px">Attendu</th>
          <th style="text-align:right;${lbl};padding:0 8px 8px;width:130px">Objectif</th>
          <th style="text-align:right;${lbl};padding:0 8px 8px">Budget période</th>
          <th style="text-align:right;${lbl};padding:0 0 8px 8px">Atteinte attendue</th>
        </tr></thead>
        <tbody>
          ${c.bxcLignes.map(l => `<tr style="border-top:0.5px solid var(--color-border-tertiary)">
            <td style="padding:8px 8px 8px 0;font-weight:500">${esc(l.nom)}</td>
            <td style="padding:8px;text-align:right;font-variant-numeric:tabular-nums">${esc(l.panier)}</td>
            <td style="padding:8px;text-align:right;color:var(--color-text-muted);font-variant-numeric:tabular-nums">${esc(l.clientsA1)}${l.clientsA1Note ? `<div style="font-size:9.5px;color:var(--color-text-muted)">${esc(l.clientsA1Note)}</div>` : ''}</td>
            <td style="padding:8px;text-align:right;font-variant-numeric:tabular-nums">${esc(l.clientsPrevus)}<div style="font-size:9.5px;color:var(--color-primary)">${esc(l.plus)} / jour</div></td>
            <td style="padding:8px;text-align:right;color:var(--color-text-muted);font-variant-numeric:tabular-nums">${esc(l.base)}${l.baseNote ? `<div style="font-size:9.5px;color:var(--color-text-muted)">${esc(l.baseNote)}</div>` : ''}</td>
            <td style="padding:8px;text-align:right;font-weight:600;color:var(--color-primary);font-variant-numeric:tabular-nums">${esc(l.gain)}</td>
            <td style="padding:8px;text-align:right;font-weight:600;font-variant-numeric:tabular-nums">${esc(l.attendu)}</td>
            <td style="padding:5px 8px;text-align:right"><input type="number" value="${esc(String(l.objectif))}" ${x.C(l.setObjectif)} placeholder="—" style="width:112px;box-sizing:border-box;text-align:right;font-size:12.5px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:6px 9px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui)"></td>
            <td style="padding:8px;text-align:right;color:var(--color-text-muted);font-variant-numeric:tabular-nums">${esc(l.budget)}${l.source ? `<div style="font-size:9.5px;color:var(--color-text-muted)">${esc(l.source)}</div>` : ''}</td>
            <td style="padding:8px 0 8px 8px;text-align:right"><span style="${l.attAttSt}">${esc(l.attAtt || '')}</span></td>
          </tr>`).join('')}
          <tr style="border-top:1.5px solid var(--color-border-secondary)">
            <td style="padding:10px 8px 10px 0;font-weight:600">Total</td>
            <td></td>
            <td style="padding:10px 8px;text-align:right;font-weight:600;color:var(--color-text-muted);font-variant-numeric:tabular-nums">${esc(c.bxcEffet.clientsA1)}</td>
            <td style="padding:10px 8px;text-align:right;font-weight:600;font-variant-numeric:tabular-nums">${esc(c.bxcEffet.clientsPrevus)}</td>
            <td style="padding:10px 8px;text-align:right;font-weight:600;color:var(--color-text-muted);font-variant-numeric:tabular-nums">${esc(c.bxcEffet.base)}</td>
            <td style="padding:10px 8px;text-align:right;font-weight:600;color:var(--color-primary);font-variant-numeric:tabular-nums">${esc(c.bxcEffet.gain)}</td>
            <td style="padding:10px 8px;text-align:right;font-weight:600;font-variant-numeric:tabular-nums">${esc(c.bxcEffet.attendu)}</td>
            <td style="padding:10px 8px;text-align:right;font-weight:600;font-variant-numeric:tabular-nums">${esc(c.bxcTotObjectif)}</td>
            <td style="padding:10px 8px;text-align:right;font-weight:600;color:var(--color-text-muted);font-variant-numeric:tabular-nums">${esc(c.bxcTotBudget)}</td>
            <td></td>
          </tr>
        </tbody>
      </table>
      </div>
      <div style="margin-top:20px;padding-top:16px;border-top:0.5px solid var(--color-border-tertiary)">
        <div style="${lbl};margin-bottom:12px">Ce que chaque magasin devrait faire</div>
        <div style="display:flex;flex-direction:column;gap:17px">
          ${c.bxcJauges.map(g => `<div>
            <div style="display:flex;justify-content:space-between;gap:12px;font-size:12px;margin-bottom:5px">
              <span style="font-weight:500">${esc(g.nom)}</span>
              <span style="color:var(--color-text-muted)">${esc(g.detail)}</span>
            </div>
            <div style="position:relative;height:22px;background:rgba(34,34,34,.05);border-radius:5px">
              ${g.vide ? '' : `<i style="position:absolute;left:0;top:0;height:22px;width:${g.base}%;background:#D8CEC2;border-radius:5px 0 0 5px"></i>
              <i style="position:absolute;left:${g.gaugeGauche}%;top:0;height:22px;width:${g.gain}%;background:var(--color-primary);opacity:.85"></i>`}
              ${g.obj == null ? '' : `<i title="${esc(g.objTxt)}" style="position:absolute;left:${g.obj}%;top:-4px;height:30px;width:2px;background:var(--color-text);border-radius:2px"></i>`}
            </div>
          </div>`).join('')}
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:var(--color-text-muted);margin-top:13px">
          <span><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#D8CEC2;margin-right:5px;vertical-align:-1px"></i>Base — mêmes dates l'an dernier</span>
          <span><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--color-primary);opacity:.85;margin-right:5px;vertical-align:-1px"></i>Gain attendu de la campagne</span>
          <span><i style="display:inline-block;width:2px;height:12px;background:var(--color-text);margin-right:5px;vertical-align:-2px"></i>Objectif — celui saisi, sinon le budget de la période</span>
        </div>
      </div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:14px;line-height:1.55">
        <sup style="color:var(--color-primary);font-weight:600">(i)</sup> ${esc(c.bxcEffet.note)}
        ${c.bxcEffet.sansBase ? `<br>${c.bxcEffet.sansBase} magasin(s) sans relevé l'an dernier : leur base est la moyenne de leurs 3 derniers mois, ramenée à la durée de la campagne.` : ''}
        <br>L'objectif se saisit en euros ; à défaut, c'est le budget de la période qui sert de ligne. Chaque saisie part en base après une pause de frappe.
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
  // Étiquettes des mini-colonnes PV / Achat / Marge de la cellule de marge.
  const MLAB = 'font-size:9px;text-transform:uppercase;letter-spacing:.07em;color:var(--color-text-muted);font-weight:500';
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
      <input id="pd-q" type="search" value="${esc(c.pdQ)}" ${x.I(c.setPdQ)} placeholder="Rechercher une référence…" style="${selCss};font-family:var(--font-ui);width:230px">
      <select ${x.C(c.setPdCat)} style="${selCss};font-family:var(--font-ui)">${opts(c.pdCatOptions, c.pdCat)}</select>
      <span style="font-size:12px;color:var(--color-text-muted)">Pondération du score — ${c.pdPond}</span>
    </div>
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
      <div style="padding:14px 18px;border-bottom:0.5px solid var(--color-border-tertiary);font-size:13px;font-weight:500">Scoring des références — volume, marge nette, taux de perte et présence au comptoir · ${c.pdPeriode || ''}</div>
      <!-- Le tableau PLAT : une seule ligne par référence, aucun graphique.
           La couleur ne reste que sur trois signaux — taux de marge (puce à
           l'échelle du réseau), perte, score. Tendance, pénétration, CA,
           marge brute, profil V·M·P·C et verdict vivent dans la fiche, au
           clic sur le nom. -->
      <div style="overflow-x:auto">
      <table style="width:100%;min-width:1020px;border-collapse:collapse;font-size:12.5px">
        <!-- Chaque en-tête TRIE : un clic trie la colonne, un second inverse.
             La flèche dit la colonne et le sens en cours. -->
        <thead><tr>
          ${c.pdCols.map((col, i2) => `<th ${x.A(col.sort)} title="${esc(col.titre)}" style="${i2 === 0 ? TH : TH2 + ';text-align:' + col.align};cursor:pointer;user-select:none;white-space:nowrap">${esc(col.label)}${col.arrow}</th>`).join('')}
        </tr></thead>
        <tbody>
          ${c.pdRows.map(r => `
            <tr style="border-bottom:0.5px solid var(--color-border-tertiary)">
              <td style="padding:11px 6px 11px 14px;white-space:nowrap;font-size:11.5px;color:var(--color-text-muted)">${esc(r.cat)}</td>
              <td style="padding:11px 12px;white-space:nowrap">
                <button ${x.A(r.ouvrirDetail)} title="La fiche : score décomposé, CA et marge par période, suites possibles" style="border:none;background:none;padding:0;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:500;color:var(--color-text);text-align:left" class="hv-line">${esc(r.nom)}</button>
              </td>
              <td style="padding:11px 12px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;font-weight:500">${r.vol}</td>
              <td style="padding:11px 12px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:var(--color-text-muted)">${r.prix}</td>
              <td style="padding:11px 12px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:var(--color-text-muted)">${r.achat}</td>
              <td style="padding:11px 12px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;font-weight:500">${r.mu}</td>
              <td style="padding:11px 12px;text-align:right;white-space:nowrap" title="Taux de marge — couleur à l'échelle du réseau">
                <span style="display:inline-block;width:7px;height:7px;border-radius:999px;background:${r.margeCol};margin-right:6px;vertical-align:1px"></span><span style="font-weight:600;color:${r.margeCol}">${r.margeTxt}</span>
              </td>
              <td style="padding:11px 12px;text-align:right;white-space:nowrap">
                <button ${x.A(r.openWaste)} title="${esc(r.perteDetail || 'Voir la perte magasin par magasin')}" style="border:none;background:none;padding:0;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;${r.perteSt};text-decoration:underline;text-decoration-color:var(--color-border-secondary);text-underline-offset:3px">${r.perteTxt}</button>
              </td>
              <td style="padding:11px 12px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:var(--color-text-muted)">${r.rangGlobal}</td>
              <td style="padding:11px 12px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:var(--color-text-muted)" title="${r.part} du CA catégorie">${r.rang}</td>
              <td style="padding:11px 14px;text-align:right;white-space:nowrap" title="${esc(r.verdict)}">
                <span style="font-size:14px;font-weight:600;color:${r.scoreCol}">${r.score}</span>
              </td>
            </tr>`).join('')}
          ${c.pdRows.length ? '' : `<tr><td colspan="11" style="padding:20px 14px;font-size:12.5px;color:var(--color-text-muted)">Aucune référence ne correspond${c.pdQ ? ' à « ' + esc(c.pdQ) + ' »' : ''}.</td></tr>`}
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
                    <td style="padding:10px 12px;color:var(--color-text-muted);text-wrap:pretty">${t.hasComment ? esc(t.comment) : '<span style="opacity:0.6"></span>'}</td>
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
            <!-- CONTRÔLE PAR EXCEPTION : ce qui est maîtrisé quitte la liste du
                 jour sans disparaître. Le motif reste lisible et un bouton
                 ramène la tâche tout de suite — un contrôle qui s'efface sans
                 dire pourquoi ressemble à un oubli. -->
            ${s.nMasquees ? `<div style="border-top:0.5px solid var(--color-border-tertiary);background:var(--color-background-secondary)">
              <button ${x.A(c.ctrlMasqPlier)} style="width:100%;text-align:left;border:none;background:none;cursor:pointer;font-family:var(--font-ui);padding:10px 18px;display:flex;align-items:center;gap:9px">
                <span style="font-size:11.5px;font-weight:500;color:var(--color-text)">${s.nMasquees} contrôle(s) maîtrisé(s)</span>
                <span style="font-size:11px;color:var(--color-text-muted)">${c.ctrlMasqTout ? '— masquer le détail' : '— afficher'}</span>
              </button>
              ${c.ctrlMasqTout ? `<div style="padding:0 18px 12px">
                ${s.masquees.map(t => `<div style="display:flex;align-items:center;gap:12px;padding:7px 0;border-top:0.5px solid var(--color-border-tertiary)">
                  <span style="flex:1;min-width:0">
                    <span style="font-size:12.5px;font-weight:500">${esc(t.tache)}</span>
                    <span style="display:block;font-size:11px;color:var(--color-text-muted)">${esc(t.maitriseMoy || t.maitriseMotif)}${t.recontrole ? ' · ' + esc(t.recontrole) : ''}</span>
                  </span>
                  <button ${x.A(t.rouvrir)} title="Remettre ce contrôle dans la liste du jour" style="flex:0 0 auto;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:999px;padding:5px 13px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Rouvrir</button>
                </div>`).join('')}
              </div>` : ''}
            </div>` : ''}
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

/* La heatmap « suivi mensuel » : magasin × mois, faites / pas faites, et le
   détail d'une cellule cliquée — les tâches les moins faites d'abord, puis la
   grille jour par jour (vert fait, bordeaux pas fait, pointillé pas attendue). */
/* L'écran « Table KPI » : l'encodage (catégorie, source endpoint, test en
   direct) et les valeurs collectées — le magasin de valeurs du réseau. */
function tplKpiT(c, x){
  const { esc } = x;
  const card = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const TH10 = 'font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted)';
  const inp = 'font-family:var(--font-ui);font-size:12.5px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:8px 11px;background:var(--color-surface);color:var(--color-text)';
  const src = 'font-family:ui-monospace,Menlo,monospace;font-size:10.5px;background:var(--color-background-secondary);border-radius:5px;padding:2px 7px';
  const cranPill = cr => cr ? `<span style="display:inline-block;min-width:22px;text-align:center;border-radius:6px;padding:1px 5px;font-size:10px;font-weight:700;${cr.st};margin-left:5px">${esc(cr.lib)}</span>` : '';
  if (c.ktChargement) { return `<div data-screen="table-kpi"><div style="${card};padding:16px 18px;font-size:12.5px;color:var(--color-text-muted)">Table KPI — lecture…</div></div>`; }
  const F = c.ktForm;
  const fiche = !F.ouvert ? '' : `
  <div style="${card};padding:16px 18px">
    <div style="display:flex;justify-content:space-between;align-items:baseline">
      <div style="font-size:13.5px;font-weight:600">Nouveau KPI — la fiche d'encodage</div>
      <span ${x.A(F.fermer)} style="cursor:pointer;font-size:11.5px;color:var(--color-text-muted);text-decoration:underline">fermer</span>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
      <label style="${TH10}">Nom<br><input value="${esc(F.nom)}" ${x.C(F.setNom)} placeholder="Productivité CA / ETP / m²" style="${inp};min-width:230px;margin-top:4px"></label>
      <label style="${TH10}">Catégorie<br><input value="${esc(F.categorie)}" ${x.C(F.setCategorie)} list="ktCats" placeholder="Ventes" style="${inp};width:140px;margin-top:4px"><datalist id="ktCats">${F.categories.map(cg => `<option value="${esc(cg)}">`).join('')}</datalist></label>
      <label style="${TH10}">Sous-catégorie<br><input value="${esc(F.sousCategorie)}" ${x.C(F.setSousCategorie)} placeholder="Productivité" style="${inp};width:140px;margin-top:4px"></label>
      <label style="${TH10}">Unité<br><select ${x.C(F.setUnite)} style="${inp};margin-top:4px">${['€','%','n','€/m²','min','★'].map(u => `<option${u === F.unite ? ' selected' : ''}>${u}</option>`).join('')}</select></label>
      <label style="${TH10}">Maille<br><select ${x.C(F.setGrain)} style="${inp};margin-top:4px"><option value="jour"${F.grain === 'jour' ? ' selected' : ''}>par jour</option><option value="mois"${F.grain === 'mois' ? ' selected' : ''}>par mois</option></select></label>
      <label style="${TH10}">Type de source<br><select ${x.C(F.setType)} style="${inp};margin-top:4px;border-color:var(--color-primary)">${F.types.map(t => `<option value="${t.val}"${t.sel ? ' selected' : ''}>${esc(t.nom)}</option>`).join('')}</select></label>
    </div>
    ${F.type === 'compose' ? `
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:flex-end">
      <label style="${TH10}">Donnée A<br><select ${x.C(F.setA)} style="${inp};min-width:190px;margin-top:4px"><option value="">choisir…</option>${F.operandes.map(o => `<option value="${esc(o.val)}"${o.val === F.a ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></label>
      <select ${x.C(F.setOp1)} style="${inp};width:56px;text-align:center;font-size:15px">${F.ops.map(o => `<option value="${esc(o.val)}"${o.val === F.op1 ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>
      <label style="${TH10}">Donnée B<br><select ${x.C(F.setB)} style="${inp};min-width:190px;margin-top:4px"><option value="">choisir…</option>${F.operandes.map(o => `<option value="${esc(o.val)}"${o.val === F.b ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></label>
      <select ${x.C(F.setOp2)} style="${inp};width:56px;text-align:center;font-size:15px">${F.ops.map(o => `<option value="${esc(o.val)}"${o.val === F.op2 ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>
      <label style="${TH10}">Donnée C — facultative<br><select ${x.C(F.setC)} style="${inp};min-width:190px;margin-top:4px"><option value="">aucune</option>${F.operandes.map(o => `<option value="${esc(o.val)}"${o.val === F.c ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></label>
    </div>
    <div style="font-size:11px;color:var(--color-text-muted);margin-top:6px">Les opérandes sont les KPI déjà encodés et les attributs de la fiche magasin. La ligne réseau applique la même formule aux valeurs réseau — les ratios sont pondérés juste (CA ÷ tickets = le vrai panier réseau).</div>` : `
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;align-items:flex-end">
      <label style="${TH10}">Réseau =<br><select ${x.C(F.setAgregat)} style="${inp};margin-top:4px"><option value="somme"${F.agregat === 'somme' ? ' selected' : ''}>somme des magasins</option><option value="moyenne"${F.agregat === 'moyenne' ? ' selected' : ''}>moyenne des magasins</option></select></label>
      <label style="${TH10}">Source — endpoint de l'application<br>
        <select ${x.C(F.setEndpoint)} style="${inp};min-width:340px;margin-top:4px">
          <option value="">choisir…</option>
          ${F.endpoints.map(e => `<option value="${esc(e.val)}"${e.sel ? ' selected' : ''}>${esc(e.nom)}</option>`).join('')}
        </select></label>
      <button ${x.A(F.tester)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:8px 16px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12px;cursor:pointer">${esc(F.testerTxt)}</button>
      ${F.listes.length ? `<label style="${TH10}">Liste<br><select ${x.C(F.setListe)} style="${inp};margin-top:4px">${F.listes.map(l => `<option value="${esc(l.val)}"${l.sel ? ' selected' : ''}>${esc(l.val)}[]</option>`).join('')}</select></label>` : ''}
      ${F.champs.length ? `<label style="${TH10}">Champ à lire<br><select ${x.C(F.setChamp)} style="${inp};margin-top:4px"><option value="">choisir…</option>${F.champs.map(ch => `<option value="${esc(ch.val)}"${ch.sel ? ' selected' : ''}>${esc(ch.val)}</option>`).join('')}</select></label>` : ''}
    </div>
    ${F.sondeErreur ? `<div style="font-size:12px;color:#8D1D2C;margin-top:10px">${esc(F.sondeErreur)}</div>` : ''}
    ${F.apercu.length ? `<div style="border:0.5px solid var(--color-border-tertiary);border-radius:10px;padding:9px 13px;margin-top:10px;background:rgba(45,122,62,0.05);font-size:12px">
      <b style="color:#2d7a3e">test réussi</b> — valeurs lues à l'instant : ${F.apercu.map(a2 => `${esc(a2.nom)} <b>${esc(a2.val)}</b>`).join(' · ')}</div>` : ''}`}
    <div style="margin-top:12px">
      <span style="${TH10}">Échelle de valeur — 4 bornes croissantes (facultatif) : −− sous la 1re, ++ dès la 4e</span>
      <div style="display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap">
        <span style="display:inline-block;border-radius:6px;padding:4px 9px;font-size:11px;font-weight:700;background:rgba(141,29,44,0.75);color:#fff">−−</span>
        <input value="${esc(F.e1)}" ${x.C(F.setE1)} placeholder="90" style="${inp};width:80px;text-align:center">
        <span style="display:inline-block;border-radius:6px;padding:4px 9px;font-size:11px;font-weight:700;background:rgba(217,119,6,0.45)">−</span>
        <input value="${esc(F.e2)}" ${x.C(F.setE2)} placeholder="110" style="${inp};width:80px;text-align:center">
        <span style="display:inline-block;border-radius:6px;padding:4px 9px;font-size:11px;font-weight:700;background:rgba(34,34,34,0.08)">=</span>
        <input value="${esc(F.e3)}" ${x.C(F.setE3)} placeholder="130" style="${inp};width:80px;text-align:center">
        <span style="display:inline-block;border-radius:6px;padding:4px 9px;font-size:11px;font-weight:700;background:rgba(45,122,62,0.45)">+</span>
        <input value="${esc(F.e4)}" ${x.C(F.setE4)} placeholder="150" style="${inp};width:80px;text-align:center">
        <span style="display:inline-block;border-radius:6px;padding:4px 9px;font-size:11px;font-weight:700;background:#2d7a3e;color:#fff">++</span>
      </div>
    </div>
    <div style="display:flex;gap:10px;margin-top:14px;align-items:center">
      <button ${x.A(F.enregistrer)} style="border:none;border-radius:999px;padding:9px 18px;background:${F.pret ? 'var(--color-primary)' : 'var(--color-border-secondary)'};color:#fff;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:${F.pret ? 'pointer' : 'default'}">${esc(F.enregistrerTxt)}</button>
      <span style="font-size:11px;color:var(--color-text-muted)">Dès l'enregistrement : collecte au prochain battement du cron, historique au fil des jours, repris dans le bloc « Table KPI » des rapports.</span>
    </div>
  </div>`;
  const FI = c.ktFiche;
  const ficheMag = `
  <div style="${card};padding:16px 18px">
    <div style="font-size:13.5px;font-weight:600">Fiche magasin — les données statiques</div>
    <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">Saisies une fois (m², places assises…), utilisables comme opérandes des KPI composés — elles apparaissent dans les listes sous « … (fiche) ».</div>
    <div style="overflow-x:auto;margin-top:12px">
      <table style="border-collapse:collapse;font-size:12px">
        <tr><th style="text-align:left;${TH10};padding:5px 10px 5px 0">Magasin</th>
        ${FI.attrs.map(a => `<th style="text-align:right;${TH10};padding:5px 10px">${esc(a.libelle)} <span ${x.A(a.retirer)} style="cursor:pointer;color:var(--color-primary)">✕</span></th>`).join('')}
        </tr>
        ${FI.magasins.map(m => `
        <tr style="border-top:0.5px solid var(--color-border-tertiary)">
          <td style="padding:7px 10px 7px 0;font-weight:600">${esc(m.nom)}</td>
          ${FI.attrs.map(a => `<td style="padding:5px 10px;text-align:right"><input value="${esc(FI.val(m.id, a.cle))}" data-ktf="${esc(m.id)}|${esc(a.cle)}" style="${inp};width:90px;text-align:right;padding:6px 8px"></td>`).join('')}
        </tr>`).join('')}
      </table>
    </div>
    <div style="display:flex;gap:10px;margin-top:12px;align-items:center;flex-wrap:wrap">
      <input id="kt-attr-neuf" placeholder="Nouvel attribut — ex. Surface de vente (m²)" style="${inp};min-width:260px">
      <button ${x.A(FI.ajouter)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:8px 15px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12px;cursor:pointer">+ Ajouter</button>
      <button ${x.A(FI.enregistrer)} style="border:none;border-radius:999px;padding:8px 16px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">${esc(FI.enregistrerTxt)}</button>
    </div>
  </div>`;
  return `
  <div data-screen="table-kpi" style="display:flex;flex-direction:column;gap:16px">
    <div style="${card};padding:16px 18px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div>
          <div style="font-size:13.5px;font-weight:600">Les KPI encodés — par catégorie</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px;max-width:720px">Chaque KPI déclare sa source (un endpoint, ou une formule sur d'autres KPI) ; le cron le collecte chaque heure et range les valeurs par magasin + réseau. Les rapports lisent cette table telle quelle (bloc « Table KPI »).</div>
        </div>
        <div style="display:flex;gap:8px">
          <button ${x.A(c.ktCollecte)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:8px 15px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12px;cursor:pointer">${esc(c.ktCollecteTxt)}</button>
          <button ${x.A(F.ouvrir)} style="border:none;border-radius:999px;padding:8px 16px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">+ Nouveau KPI</button>
        </div>
      </div>
      ${c.ktVide ? `<div style="font-size:12.5px;color:var(--color-text-muted);margin-top:12px">Aucun KPI encodé — « + Nouveau KPI » pour commencer.</div>` : ''}
      ${c.ktGroupes.map(g => `
      <div style="margin-top:14px">
        <div style="font-size:12.5px;font-weight:700;border-bottom:1.5px solid var(--color-border-secondary);padding-bottom:4px">${esc(g.nom)}</div>
        <div style="overflow-x:auto"><table style="border-collapse:collapse;width:100%;min-width:880px;font-size:12px;font-variant-numeric:tabular-nums">
          <tr><th style="text-align:left;${TH10};padding:6px 8px 5px 0">KPI</th><th style="text-align:right;${TH10};padding:6px 8px">Réseau</th><th style="text-align:left;${TH10};padding:6px 8px">Quand</th><th style="text-align:left;${TH10};padding:6px 8px">Historique</th><th style="text-align:left;${TH10};padding:6px 8px">Par magasin</th><th style="text-align:left;${TH10};padding:6px 8px">Source</th><th></th></tr>
          ${g.kpis.map(k => `
          <tr style="border-top:0.5px solid var(--color-border-tertiary)">
            <td style="padding:8px 8px 8px 0"><b>${esc(k.nom)}</b>${k.sousCat ? ` <span style="font-size:10px;color:var(--color-text-muted)">${esc(k.sousCat)}</span>` : ''}<br><span style="font-size:10px;color:var(--color-text-muted)">${esc(k.grain)} · ${esc(k.agregat)}</span></td>
            <td style="padding:8px;text-align:right;white-space:nowrap"><b style="font-size:13px">${esc(k.valeur)}</b>${cranPill(k.cranReseau)}</td>
            <td style="padding:8px;color:var(--color-text-muted)">${esc(k.quand)}<br><span style="font-size:10px">${esc(k.maj)}</span></td>
            <td style="padding:8px"><span style="display:inline-flex;align-items:flex-end;gap:2px;height:20px">${k.spark.map((pt, i2) => `<i style="display:inline-block;width:6px;height:${pt.h}px;border-radius:2px 2px 0 0;background:${i2 === k.spark.length - 1 ? 'var(--color-primary)' : '#D8CEC2'}"></i>`).join('')}</span></td>
            <td style="padding:8px;font-size:11px">${k.parMagasin.map(m => `${esc(m.nom)} <b>${esc(m.val)}</b>${cranPill(m.cran)}`).join(' · ')}</td>
            <td style="padding:8px"><span style="${src}">${esc(k.sourceTxt)}</span><br><span style="${src};margin-top:3px;display:inline-block">${esc(k.champTxt)}</span></td>
            <td style="padding:8px;text-align:right"><span ${x.A(k.suppr)} style="cursor:pointer;color:var(--color-text-muted);font-size:11px">retirer</span></td>
          </tr>`).join('')}
        </table></div>
      </div>`).join('')}
    </div>
    ${fiche}
    ${ficheMag}
  </div>`;
}

/* L'écran « Suivi mensuel » : la heatmap seule, sur sa propre page. */
function tplSuiviM(c, x){
  const card = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  return `
  <div data-screen="suivi-mensuel" style="display:flex;flex-direction:column;gap:16px">
    ${tplCtrlHeat(c, x, card)}
  </div>`;
}

function tplCtrlHeat(c, x, card){
  const { esc } = x;
  const TH10 = 'font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted)';
  const leg = `
    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;font-size:11px;color:var(--color-text-muted);margin-top:12px">
      <span style="font-size:10px;text-transform:uppercase;letter-spacing:0.06em">Part des t\u00e2ches faites</span>
      ${[['rgba(141,29,44,0.75)', '&lt; 60 %'], ['rgba(217,119,6,0.45)', '60\u201370 %'], ['rgba(201,162,39,0.40)', '70\u201380 %'], ['rgba(45,122,62,0.45)', '80\u201390 %'], ['#2d7a3e', '&#8805; 90 %']]
        .map(([cl, tx]) => `<span><i style="display:inline-block;width:13px;height:13px;border-radius:3px;background:${cl};margin-right:5px;vertical-align:-2px"></i>${tx}</span>`).join('')}
      <span style="margin-left:auto">${esc(c.hmNote)}</span>
      ${c.hmCompleter ? `<button ${x.A(c.hmCompleter)} style="border:none;border-radius:999px;padding:6px 13px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:11px;font-weight:500;cursor:pointer">${esc(c.hmCompleterTxt)}</button>` : ''}
    </div>`;
  const det = !c.hmDetail ? '' : c.hmDetail.chargement
    ? `<div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:14px;padding-top:12px;font-size:12.5px;color:var(--color-text-muted)">Lecture du d\u00e9tail\u2026</div>`
    : c.hmDetail.erreur
    ? `<div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:14px;padding-top:12px;font-size:12.5px;color:#8D1D2C">${esc(c.hmDetail.erreur)}</div>`
    : c.hmDetail.jour ? `
    <div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:14px;padding-top:12px">
      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div style="font-size:13px;font-weight:600">${esc(c.hmDetail.titre)} <span style="font-weight:400;color:var(--color-text-muted);font-size:11.5px">jour cliqu\u00e9</span></div>
        <div style="display:flex;align-items:baseline;gap:12px">
          <span style="font-size:12px">${esc(c.hmDetail.resume)}</span>
          <span ${x.A(c.hmDetail.fermer)} style="cursor:pointer;font-size:11.5px;color:var(--color-text-muted);text-decoration:underline">fermer</span>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-top:10px">
        <div>
          <div style="${TH10};margin-bottom:6px;color:#8D1D2C">Pas faites (${c.hmDetail.pasFaites.length})</div>
          ${c.hmDetail.pasFaites.length ? c.hmDetail.pasFaites.map(t => `<div style="font-size:12px;padding:5px 0;border-bottom:0.5px solid var(--color-border-tertiary)"><i style="display:inline-block;width:9px;height:9px;border-radius:3px;background:#8D1D2C;opacity:.8;margin-right:8px"></i>${esc(t)}</div>`).join('') : `<div style="font-size:12px;color:var(--color-text-muted)">aucune \u2014 tout est fait ce jour-l\u00e0</div>`}
        </div>
        <div>
          <div style="${TH10};margin-bottom:6px;color:#2d7a3e">Faites (${c.hmDetail.faitesListe.length})</div>
          ${c.hmDetail.faitesListe.length ? c.hmDetail.faitesListe.map(t => `<div style="font-size:12px;padding:5px 0;border-bottom:0.5px solid var(--color-border-tertiary)"><i style="display:inline-block;width:9px;height:9px;border-radius:3px;background:#2d7a3e;opacity:.85;margin-right:8px"></i>${esc(t)}</div>`).join('') : `<div style="font-size:12px;color:var(--color-text-muted)">aucune</div>`}
        </div>
      </div>
    </div>` : `
    <div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:14px;padding-top:12px">
      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div style="font-size:13px;font-weight:600">${esc(c.hmDetail.titre)} <span style="font-weight:400;color:var(--color-text-muted);font-size:11.5px">cellule cliqu\u00e9e</span></div>
        <div style="display:flex;align-items:baseline;gap:12px">
          <span style="font-size:12px">${esc(c.hmDetail.resume)}</span>
          <span ${x.A(c.hmDetail.fermer)} style="cursor:pointer;font-size:11.5px;color:var(--color-text-muted);text-decoration:underline">fermer</span>
        </div>
      </div>
      <div style="overflow-x:auto;margin-top:10px">
        <table style="border-collapse:collapse;font-size:12px;min-width:720px">
          <tr>
            <th style="text-align:left;${TH10};padding:5px 8px 5px 0">T\u00e2che \u2014 les moins faites d\u2019abord</th>
            <th style="text-align:right;${TH10};padding:5px 8px">Att.</th>
            <th style="text-align:right;${TH10};padding:5px 8px">Faites</th>
            <th style="text-align:right;${TH10};padding:5px 10px 5px 8px">Part</th>
            <th style="text-align:left;padding:5px 0 5px 6px">
              <span style="display:inline-flex;gap:2px">${c.hmDetail.joursLibs.map(j => `<i style="width:16px;text-align:center;font-size:8.5px;font-style:normal;color:${j.we ? 'var(--color-primary)' : 'var(--color-text-muted)'};font-weight:${j.we ? '600' : '400'}">${esc(j.n)}</i>`).join('')}</span>
            </th>
          </tr>
          ${c.hmDetail.taches.map(t => `
          <tr style="border-top:0.5px solid var(--color-border-tertiary)">
            <td style="padding:6px 8px 6px 0;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${esc(t.nom)}">${esc(t.nom)}</td>
            <td style="padding:6px 8px;text-align:right;color:var(--color-text-muted)">${esc(t.attendues)}</td>
            <td style="padding:6px 8px;text-align:right">${esc(t.faites)}</td>
            <td style="padding:6px 10px 6px 8px;text-align:right;${t.partSt}">${esc(t.partTxt)}</td>
            <td style="padding:6px 0 6px 6px">
              <span style="display:inline-flex;gap:2px">${t.cases.map(cs => `<i title="${esc(cs.titre)}" style="display:inline-block;width:16px;height:16px;border-radius:4px;${cs.st}"></i>`).join('')}</span>
            </td>
          </tr>`).join('')}
        </table>
      </div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:8px">Chaque case est un jour du mois : vert = faite, bordeaux = pas faite, pointill\u00e9 = pas attendue ce jour-l\u00e0.</div>
    </div>`;
  const corpsMois = c.hmjChargement
    ? `<div style="font-size:12.5px;color:var(--color-text-muted);margin-top:12px">Lecture du cache\u2026</div>`
    : c.hmjVide
    ? `<div style="font-size:12.5px;color:var(--color-text-muted);margin-top:12px">Aucune journ\u00e9e relev\u00e9e sur ce mois \u2014 le relev\u00e9 avance heure par heure (ou tout de suite avec \u00ab\u00a0Compl\u00e9ter maintenant\u00a0\u00bb).</div>${leg}`
    : `
    <div style="overflow-x:auto;margin-top:12px">
      <table style="border-collapse:separate;border-spacing:2px;font-variant-numeric:tabular-nums">
        <tr>
          <th style="text-align:left;${TH10};padding:2px 6px">Magasin</th>
          ${c.hmjJours.map(j => `<th style="width:${c.hmjTaille}px;min-width:${c.hmjTaille}px;font-size:9px;font-weight:${j.we ? '600' : '400'};color:${j.we ? 'var(--color-primary)' : 'var(--color-text-muted)'};padding:2px 0;text-align:center;white-space:nowrap">${esc(j.n)}</th>`).join('')}
          <th style="${TH10};padding:2px 8px;text-align:center">${esc(c.hmjTotLabel)}</th>
        </tr>
        ${c.hmjLignes.map(l => `
        <tr>
          <td style="font-size:12.5px;font-weight:600;white-space:nowrap;padding:0 10px 0 6px">${esc(l.shop)}</td>
          ${l.cells.map(cc => `<td ${x.A(cc.clic)} title="${esc(cc.titre)}" style="${cc.st}">${esc(cc.txt)}</td>`).join('')}
          <td ${x.A(l.totClic)} title="ouvrir le d\u00e9tail du mois, t\u00e2che par t\u00e2che" style="${l.totSt}">
            <div style="font-size:11.5px;font-weight:700">${esc(l.totTxt)}</div>
            <div style="font-size:8.4px;color:var(--color-text-muted)">${esc(l.totSous)}</div>
          </td>
        </tr>`).join('')}
      </table>
    </div>
    <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:6px">Chaque colonne est un jour du mois (les week-ends en bordeaux, les jours non relev\u00e9s en pointill\u00e9). Cliquez un jour pour ses t\u00e2ches, la derni\u00e8re colonne pour la grille compl\u00e8te du mois.</div>
    ${leg}`;
  const corpsAnnee = c.hmChargement
    ? `<div style="font-size:12.5px;color:var(--color-text-muted);margin-top:12px">Lecture du cache\u2026</div>`
    : c.hmVide
    ? `<div style="font-size:12.5px;color:var(--color-text-muted);margin-top:12px">Aucune journ\u00e9e encore relev\u00e9e \u2014 le relev\u00e9 d\u00e9marre au prochain passage du cron (chaque heure), ou tout de suite avec le bouton ci-dessous.</div>${leg}`
    : `
    <div style="overflow-x:auto;margin-top:12px">
      <table style="border-collapse:separate;border-spacing:3px;width:100%;min-width:920px;font-variant-numeric:tabular-nums">
        <tr>
          <th style="text-align:left;${TH10};padding:2px 6px">Magasin</th>
          ${c.hmMois.map(mLib => `<th style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.03em;color:var(--color-text-muted);padding:2px 1px;text-align:center">${esc(mLib)}</th>`).join('')}
          <th style="${TH10};padding:2px 1px;text-align:center">12 mois</th>
        </tr>
        ${c.hmLignes.map(l => `
        <tr>
          <td style="font-size:12.5px;font-weight:600;white-space:nowrap;padding:0 10px 0 6px">${esc(l.shop)}</td>
          ${l.cells.map(cc => `
          <td ${x.A(cc.clic)} title="${esc(cc.titre)}" style="${cc.st}">
            <div style="font-size:12px;font-weight:700">${esc(cc.txt)}</div>
            <div style="font-size:8.6px;opacity:.85;margin-top:1px">${esc(cc.sous)}${cc.partiel ? ' *' : ''}</div>
          </td>`).join('')}
          <td style="${l.totSt}">
            <div style="font-size:12px;font-weight:700">${esc(l.totTxt)}</div>
            <div style="font-size:8.6px;color:var(--color-text-muted);margin-top:1px">${esc(l.totSous)}</div>
          </td>
        </tr>`).join('')}
      </table>
    </div>
    ${leg}
    <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:6px">* mois partiellement relev\u00e9 \u2014 le pourcentage ne porte que sur les journ\u00e9es d\u00e9j\u00e0 relev\u00e9es.</div>`;
  return `
  <div style="${card};padding:16px 18px">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div>
        <div style="font-size:13.5px;font-weight:600">Suivi mensuel \u2014 faites / pas faites${c.hmVue === 'jours' ? ' \u00b7 ' + esc(c.hmMoisTitre) : ' \u00b7 12 mois glissants'}</div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px;max-width:720px">Faite = rendue dans le panel ce jour-l\u00e0 (not\u00e9e, photographi\u00e9e ou au statut fait) \u00b7 Pas faite = attendue et rest\u00e9e sans rendu. La note des contr\u00f4les est une autre affaire \u2014 ici on mesure si le travail est fait.</div>
      </div>
      <div style="display:inline-flex;border:0.5px solid var(--color-border-secondary);border-radius:999px;overflow:hidden">
        ${c.hmToggle.map(t => `<span ${x.A(t.clic)} style="padding:7px 15px;font-size:12px;font-weight:500;cursor:pointer;${t.on ? 'background:var(--color-primary);color:#fff' : 'color:var(--color-text-muted)'}">${esc(t.txt)}</span>`).join('')}
      </div>
    </div>
    ${c.hmVue === 'jours' ? corpsMois : corpsAnnee}
    ${det}
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

      <!-- CA réseau et marge brute par période : sortis de la ligne du
           tableau, ils vivent ici — mois affiché, trimestre, année dernière. -->
      <div style="border:0.5px solid var(--color-border-secondary);border-radius:11px;padding:13px 15px;margin-top:16px">
        <div style="font-size:13px;font-weight:600">Ce que la référence a rapporté</div>
        ${d.perChargement ? `<div style="font-size:11.5px;color:var(--color-text-muted);margin-top:8px">Lecture de la caisse…</div>` : ''}
        ${d.perIndispo ? `<div style="font-size:11.5px;color:var(--color-text-muted);margin-top:8px">Ventes par période indisponibles (tables de caisse injoignables).</div>` : ''}
        ${!d.perChargement && !d.perIndispo ? `
        <table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:12.5px">
          <thead><tr>
            <th style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 0 6px">Période</th>
            <th style="text-align:right;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 0 6px">Volume</th>
            <th style="text-align:right;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 0 6px">CA réseau</th>
            <th style="text-align:right;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 0 6px">Marge brute</th>
          </tr></thead>
          <tbody>${d.periodes.map(f => `<tr style="border-top:0.5px solid var(--color-border-tertiary)">
            <td style="padding:6px 0">${esc(f.label)}</td>
            <td style="padding:6px 0;text-align:right;font-variant-numeric:tabular-nums;color:var(--color-text-muted)">${esc(f.volume)}</td>
            <td style="padding:6px 0;text-align:right;font-variant-numeric:tabular-nums;font-weight:500">${esc(f.ca)}</td>
            <td style="padding:6px 0;text-align:right;font-variant-numeric:tabular-nums;font-weight:500">${esc(f.marge)}</td>
          </tr>`).join('')}</tbody>
        </table>
        <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:7px">Marge brute = CA − volume × coût matière. Sans coût connu, la marge reste vide.</div>` : ''}
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
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:2px">
              <button ${x.A(v.note)} class="hv-fade" title="La note pour les franchisés — à imprimer ou à envoyer" style="border:1px solid var(--color-border-tertiary);background:#fff;border-radius:999px;padding:4px 11px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;color:var(--color-text);cursor:pointer">Note aux franchisés</button>
              <button ${x.A(v.corriger)} class="hv-fade" title="Corriger nom, dates, budget et statut sans passer par l’assistant" style="border:1px solid var(--color-border-tertiary);background:#fff;border-radius:999px;padding:4px 11px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;color:var(--color-text-muted);cursor:pointer">Corriger</button>
            </div>
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
          <td style="${td};text-align:right;color:var(--color-text-muted)">${l.nBoutiques || ''}</td>
          <td style="${td};padding-right:17px;text-align:right;white-space:nowrap">
            ${l.reprendre ? `<button ${x.A(l.reprendre)} title="Ouvrir la campagne dans l’assistant — offre, canaux, documents joints" style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-primary);border-radius:7px;padding:3px 9px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer;margin-right:4px">${esc(l.reprendreNom || 'Assistant')}</button>` : ''}
            <button ${x.A(l.note)} title="La note pour les franchisés — à imprimer ou à envoyer" style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text-muted);border-radius:7px;padding:3px 9px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer;margin-right:4px">Note</button>
            <button ${x.A(l.editer)} style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text-muted);border-radius:7px;padding:3px 9px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer">Corriger</button>
            <button ${x.A(l.supprimer)} title="Supprimer" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:pointer;padding:0 2px;margin-left:4px">✕</button>
          </td>
        </tr>`).join('')}</tbody>
      </table></div>`}
    </div>
    ${tplMktNote(c, x)}
  </div>`;
}

/**
 * La note d'une campagne : la page A4 telle qu'elle s'imprimera, le courrier
 * qui la porte, et ce qui est déjà parti.
 *
 * L'aperçu est un IFRAME nourri du HTML du serveur : le document imprimé et le
 * PDF joint au courrier sortent du même rendu. Le reconstruire ici aurait
 * donné deux pages qui se ressemblent — jusqu'au jour où elles ne se
 * ressemblent plus.
 */
function tplMktNote(c, x){
  const n = c.mkNote;
  if (!n) { return ''; }
  const { esc } = x;
  const k = 'font-size:10px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:.08em;font-weight:500;display:block;margin-bottom:4px';
  const inp = 'width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:8px;height:33px;padding:0 10px;font-family:var(--font-ui);font-size:12.5px';
  const zone = inp.replace('height:33px;padding:0 10px', 'min-height:74px;padding:8px 10px;line-height:1.5;resize:vertical');
  const bt = 'border:0.5px solid var(--color-border-tertiary);background:var(--color-surface);color:var(--color-text);border-radius:8px;padding:8px 15px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer';
  const btPlein = 'border:none;background:var(--color-primary);color:#fff;border-radius:8px;padding:9px 18px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer';

  return `
  <div ${x.A(n.fermer)} style="position:fixed;inset:0;background:rgba(20,16,14,.5);z-index:80;animation:fadeIn 160ms ease"></div>
  <div style="position:fixed;inset:0;z-index:81;display:flex;align-items:flex-start;justify-content:center;padding:24px 16px;pointer-events:none;overflow:auto">
    <div data-scroll="mknote" style="pointer-events:auto;background:var(--color-surface);border-radius:14px;width:min(980px,100%);max-height:100%;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.3)">
      <div style="padding:16px 18px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:flex-start;gap:12px">
        <div style="flex:1;min-width:0">
          <div style="font-size:14.5px;font-weight:600">${esc(n.titre)}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">${esc(n.sousTitre || 'Note aux franchisés')}</div>
        </div>
        <button ${x.A(n.fermer)} style="border:none;background:none;color:var(--color-text-muted);font-size:16px;cursor:pointer;line-height:1">✕</button>
      </div>

      ${n.chargement ? `<div style="padding:26px 18px;font-size:12.5px;color:var(--color-text-muted)">Lecture de la campagne et de sa référence de l’an dernier…</div>`
      : n.err ? `<div style="padding:22px 18px;font-size:12.5px;color:#8D1D2C">${esc(n.err)}</div>` : `
      <div style="padding:12px 18px 0;display:flex;gap:6px">
        <button ${x.A(n.versNote)} style="${n.stNote}">La note</button>
        <button ${x.A(n.versMail)} style="${n.stMail}">Le courrier</button>
        <button ${x.A(n.versJournal)} style="${n.stJournal}">Envois${n.journal && n.journal.length ? ' (' + n.journal.length + ')' : ''}</button>
      </div>

      ${n.onglet === 'journal' ? `
      <div style="padding:16px 18px 20px">
        ${n.journalVide ? `<div style="font-size:12.5px;color:var(--color-text-muted)">Cette note n’a encore été envoyée à personne.</div>` : `
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          ${n.journal.map(e => `<tr>
            <td style="padding:8px 8px 8px 0;border-bottom:0.5px solid var(--color-border-tertiary);white-space:nowrap;color:var(--color-text-muted)">${esc(e.quand)}</td>
            <td style="padding:8px;border-bottom:0.5px solid var(--color-border-tertiary)"><span style="${e.etatSt}">${esc(e.etat)}</span></td>
            <td style="padding:8px;border-bottom:0.5px solid var(--color-border-tertiary)">${esc(e.destinataire)}</td>
            <td style="padding:8px 0 8px 8px;border-bottom:0.5px solid var(--color-border-tertiary);color:var(--color-text-muted)">${esc(e.detail)}</td>
          </tr>`).join('')}
        </table>`}
      </div>`
      : n.onglet === 'mail' ? `
      <div style="padding:16px 18px 20px;display:flex;flex-direction:column;gap:14px">
        <div>
          <span style="${k}">Destinataires — une note par magasin</span>
          <table style="width:100%;border-collapse:collapse;font-size:12.5px">
            ${n.dest.map(d => `<tr>
              <td style="padding:7px 8px 7px 0;border-bottom:0.5px solid var(--color-border-tertiary);width:26px"><input type="checkbox" ${d.on ? 'checked' : ''} ${x.C(d.basculer)} /></td>
              <td style="padding:7px 8px;border-bottom:0.5px solid var(--color-border-tertiary)">${esc(d.magasin)}<div style="font-size:10.5px;color:var(--color-text-muted)">${esc(d.franchise)}</div></td>
              <td style="padding:7px 0 7px 8px;border-bottom:0.5px solid var(--color-border-tertiary)"><input value="${esc(d.adresse)}" placeholder="adresse du franchisé" ${x.C(d.setAdresse)} style="${inp};height:30px${d.manque ? ';border-color:var(--color-primary)' : ''}" /></td>
            </tr>`).join('')}
          </table>
          ${n.sansAdresse.length ? `<div style="font-size:11px;color:var(--color-text-muted);margin-top:6px">Sans adresse, donc sans envoi : ${esc(n.sansAdresse.join(', '))}. L’adresse saisie ici est retenue pour les campagnes suivantes.</div>` : ''}
        </div>

        <div>
          <span style="${k}">En copie — l’agence et les consultants</span>
          ${n.copiesVide ? `<div style="font-size:11.5px;color:var(--color-text-muted)">Aucune adresse disponible : renseignez celle de l’agence ci-dessous, et les consultants depuis Paramètres.</div>` : `
          ${!n.copiesAgence.length ? '' : `
          <div style="font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--color-text-muted);margin-bottom:5px">Agence</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:11px">
            ${n.copiesAgence.map(cc => cc.sansAdresse
              ? `<span title="Renseignez son adresse dans « Expéditeur et gabarit », plus bas" style="display:inline-flex;align-items:center;gap:7px;border:0.5px dashed var(--color-border-secondary);border-radius:999px;padding:5px 12px;font-size:12px;color:var(--color-text-muted)">${esc(cc.nom)} · adresse à renseigner</span>`
              : `<label title="${esc(cc.adresse)}" style="display:inline-flex;align-items:center;gap:7px;border:0.5px solid ${cc.on ? 'var(--color-primary)' : 'var(--color-border-tertiary)'};border-radius:999px;padding:5px 12px 5px 9px;font-size:12px;cursor:pointer;background:${cc.on ? 'rgba(141,29,44,.06)' : 'var(--color-surface)'}">
                <input type="checkbox" ${cc.on ? 'checked' : ''} ${x.C(cc.basculer)} />
                <span>${esc(cc.nom)}<span style="color:var(--color-text-muted)"> · ${esc(cc.role)}</span></span>
              </label>`).join('')}
          </div>`}
          ${!n.copiesConsultants.length ? '' : `
          <div style="font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--color-text-muted);margin-bottom:5px">Consultants</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px">
            ${n.copiesConsultants.map(cc => `<label title="${esc(cc.adresse)}" style="display:inline-flex;align-items:center;gap:7px;border:0.5px solid ${cc.on ? 'var(--color-primary)' : 'var(--color-border-tertiary)'};border-radius:999px;padding:5px 12px 5px 9px;font-size:12px;cursor:pointer;background:${cc.on ? 'rgba(141,29,44,.06)' : 'var(--color-surface)'}">
              <input type="checkbox" ${cc.on ? 'checked' : ''} ${x.C(cc.basculer)} />
              <span>${esc(cc.nom)}<span style="color:var(--color-text-muted)"> · ${esc(cc.role)}</span></span>
            </label>`).join('')}
          </div>`}`}
          <div style="font-size:11px;color:var(--color-text-muted);margin-top:6px">Les personnes cochées reçoivent la note en copie visible — le franchisé voit qui d’autre l’a reçue. Le choix est retenu pour les campagnes suivantes.</div>
        </div>

        <div>
          <span style="${k}">Pièces jointes</span>
          <div style="font-size:12.5px;display:flex;flex-direction:column;gap:4px">
            <div>La note de campagne (PDF)${n.fichier ? ` — <span style="color:var(--color-text-muted)">${esc(n.fichier)}</span>` : ''}</div>
            ${n.annexes.map(a => `<div>${esc(a.nom)} <span style="color:var(--color-text-muted)">· ${esc(a.type)} · ${esc(a.taille)}</span>${a.perdu ? ` <span style="color:#8D1D2C">— fichier absent du serveur, ne partira pas</span>` : ''}</div>`).join('')}
          </div>
          <div style="font-size:11px;color:var(--color-text-muted);margin-top:5px">Les documents se déposent dans l’assistant, étape « Communication ».${n.annexesNon ? ` ${n.annexesNon} document(s) déposé(s) mais non coché(s) : ils ne partent pas.` : ''}</div>
        </div>

        <div><span style="${k}">Aperçu du courrier</span>
          <iframe srcdoc="${esc(n.apercuMail)}" title="Aperçu du courrier" style="width:100%;height:300px;border:0.5px solid var(--color-border-tertiary);border-radius:10px;background:#fff"></iframe>
        </div>

        <div>
          <button ${x.A(n.basculerGabarit)} style="border:none;background:none;padding:0;cursor:pointer;font-family:var(--font-ui);font-size:10.5px;font-weight:500;text-transform:uppercase;letter-spacing:.07em;color:var(--color-text-muted)">
            <span style="display:inline-block;width:11px;transform:rotate(${n.gabaritOuvert ? '90' : '0'}deg)">▸</span> Expéditeur et gabarit du courrier
          </button>
          ${!n.gabaritOuvert ? '' : `
          <div style="display:flex;flex-direction:column;gap:10px;margin-top:10px">
            <label><span style="${k}">Expéditeur</span><input value="${esc(n.expediteur)}" ${x.C(n.setExpediteur)} style="${inp}" /></label>
            <label><span style="${k}">Sujet</span><input value="${esc(n.sujet)}" ${x.C(n.setSujet)} style="${inp}" /></label>
            <label><span style="${k}">Introduction</span><textarea ${x.C(n.setIntro)} style="${zone}">${esc(n.intro)}</textarea></label>
            <label><span style="${k}">Pied du courrier</span><textarea ${x.C(n.setPied)} style="${zone}">${esc(n.pied)}</textarea></label>
            <label><span style="${k}">Gabarit HTML — vide = celui de la maison</span><textarea ${x.C(n.setHtml)} placeholder="{{logo}} {{marque}} {{entete}} {{contenu}}" style="${zone};font-family:ui-monospace,monospace;font-size:11.5px">${esc(n.html)}</textarea></label>
            <div style="border-top:0.5px solid var(--color-border-tertiary);padding-top:11px">
              <span style="${k}">L’agence — elle signe la création, sur la note comme dans le courriel</span>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                <label style="flex:1;min-width:160px"><span style="${k}">Nom</span><input value="${esc(n.agenceNom)}" ${x.C(n.setAgenceNom)} style="${inp}" /></label>
                <label style="flex:1;min-width:160px"><span style="${k}">Site ou contact</span><input value="${esc(n.agenceSite)}" ${x.C(n.setAgenceSite)} style="${inp}" /></label>
                <label style="flex:1;min-width:160px"><span style="${k}">Adresse e-mail</span><input value="${esc(n.agenceEmail)}" ${x.C(n.setAgenceEmail)} placeholder="contact@agence.be" style="${inp}" /></label>
                <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;${bt}">Choisir un logo<input type="file" accept="image/*" ${x.C(n.setAgenceLogo)} style="display:none" /></label>
                ${n.agenceLogo ? `<span style="display:inline-flex;align-items:center;gap:7px"><img src="${esc(n.agenceLogo)}" alt="" style="height:26px;border-radius:4px" /><button ${x.A(n.retirerLogo)} title="Retirer le logo" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:pointer">✕</button></span>` : ''}
              </div>
              ${n.agenceLogoErr ? `<div style="font-size:11px;color:#8D1D2C;margin-top:5px">${esc(n.agenceLogoErr)}</div>` : ''}
            </div>
            <div style="font-size:11px;color:var(--color-text-muted)">Variables disponibles : ${esc(n.variables)}</div>
            <div><button ${x.A(n.enregistrerGabarit)} style="${bt}">Enregistrer le gabarit</button></div>
          </div>`}
        </div>
      </div>`
      : `
      <div style="padding:16px 18px 0">
        ${n.moteurNote ? `<div style="font-size:11.5px;color:var(--color-text-muted);border:0.5px solid var(--color-border-tertiary);border-radius:8px;padding:9px 11px;margin-bottom:12px">${esc(n.moteurNote)}</div>` : ''}
        <div style="border:0.5px solid var(--color-border-tertiary);border-radius:10px;padding:12px 13px;margin-bottom:12px">
          <span style="${k}">Le mot du responsable — il s’imprime sur la note et ouvre le courriel</span>
          <textarea ${x.C(n.setMot)} placeholder="Pourquoi cette campagne, ce qu’on attend du magasin, ce qui est fourni…" style="${zone}">${esc(n.mot)}</textarea>
          <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
            <label style="flex:1;min-width:170px"><span style="${k}">Signé</span><input value="${esc(n.motNom)}" ${x.C(n.setMotNom)} style="${inp}" /></label>
            <label style="flex:1;min-width:170px"><span style="${k}">Fonction</span><input value="${esc(n.motFonction)}" ${x.C(n.setMotFonction)} style="${inp}" /></label>
            <div style="display:flex;align-items:flex-end"><button ${x.A(n.enregistrerMot)} style="${bt}">${n.motEtat === 'en-cours' ? 'Enregistrement…' : 'Enregistrer le mot'}</button></div>
          </div>
        </div>
        <iframe id="note-apercu" srcdoc="${esc(n.apercu)}" title="Aperçu de la note" style="width:100%;height:520px;border:0.5px solid var(--color-border-tertiary);border-radius:10px;background:#fff"></iframe>
        <div style="font-size:11px;color:var(--color-text-muted);margin-top:7px">${esc(n.visuelNote || '')}</div>
      </div>`}

      <div style="padding:14px 18px 18px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-top:0.5px solid var(--color-border-tertiary);margin-top:16px">
        <button ${x.A(n.imprimer)} style="${bt}">Imprimer</button>
        ${n.telecharger ? `<button ${x.A(n.telecharger)} style="${bt}">Télécharger le PDF</button>` : ''}
        <span style="flex:1"></span>
        ${n.envoi === 'en-cours' ? `<span style="font-size:12px;color:var(--color-text-muted)">Envoi en cours…</span>` : ''}
        <span style="font-size:11.5px;color:var(--color-text-muted)">${n.nPrets} magasin${n.nPrets > 1 ? 's' : ''} prêt${n.nPrets > 1 ? 's' : ''}</span>
        <button ${x.A(n.envoyer)} style="${btPlein}${n.envoyer ? '' : ';opacity:.5;cursor:not-allowed'}">Envoyer par mail</button>
      </div>`}
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
          <td style="padding:8px">${t.levier ? `<span style="${t.levierSt}">${esc(t.levier)}</span>` : `<span style="font-size:11.5px;color:var(--color-text-muted)"></span>`}</td>
          <td style="padding:8px;font-size:11.5px;color:var(--color-text-muted)">${esc(t.kpi)}</td>
          <td style="padding:8px;text-align:right;font-size:12px;color:var(--color-text-muted)">${t.nCampagnes || ''}</td>
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
  // L'ordre se lit comme on pilote : le chiffre, sa référence, sa cible,
  // puis l'activité qui l'explique (tickets → panier → produits par client),
  // et la ligne finale. Marge, main-d'œuvre et frais vivent dans le détail
  // qu'on ouvre — comme le coût matière avant eux.
  const ent = ['Magasin', 'CA du jour', c.rjRefEntete, 'Objectif du jour', 'Tickets', 'Panier',
    'Produits / client', 'Résultat'];
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
      <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:860px">
        <tr>${ent.map((e, i) => `<th style="text-align:${i === 0 ? 'left' : 'right'};padding:0 10px 7px;${cap}">${esc(e)}${i === 2 ? aide(c.rjRefAide) : ''}</th>`).join('')}</tr>
        ${c.rjLignes.map(l => `
          <tr ${x.A(l.ouvrir)} class="hv-bg" style="${l.st}">
            <td style="padding:9px 10px;${bord}">
              <div style="font-weight:500"><span style="font-size:9px;color:var(--color-text-muted);margin-right:5px">${l.chevron}</span>${esc(l.nom)}</div>
              ${l.sousTitre ? `<div style="font-size:10px;color:var(--color-text-muted);padding-left:14px">${esc(l.sousTitre)}</div>` : ''}
            </td>
            ${l.ouvert
              ? cel(l.ca, '', '', true) + cel(l.delta, l.deltaCoul, '', false, l.deltaTitre)
                + cel(l.fc, l.fcCoul, l.fcPct, false, l.fcTitre)
                + cel(l.tickets, '', l.ticketsDelta, false, '', l.ticketsCoul) + cel(l.panier, '', '', false)
                + cel(l.ppc, '', '', false)
                + cel(l.net, l.netCoul, l.netPct, true)
              : `<td colspan="7" style="padding:9px 10px;${bord};text-align:right;font-size:11.5px;color:var(--color-text-muted)">aucun chiffre pour cette journée</td>`}
          </tr>`).join('')}
        <tr style="background:var(--color-background-secondary)">
          <td style="padding:10px;border-top:1px solid var(--color-border-secondary);font-weight:500">Réseau
            <span style="font-size:10px;color:var(--color-text-muted);font-weight:400">${esc(c.rjReseau.magasins)} magasin(s) ouvert(s)</span></td>
          ${cel(c.rjReseau.ca, '', '', true)}${cel(c.rjReseau.delta, c.rjReseau.deltaCoul, '', false, c.rjReseau.deltaTitre)}
          ${cel(c.rjReseau.fc, c.rjReseau.fcCoul, c.rjReseau.fcPct, false, c.rjReseau.fcTitre)}
          ${cel(c.rjReseau.tickets, '', '', false)}${cel(c.rjReseau.panier, '', '', false)}
          ${cel(c.rjReseau.ppc, '', '', false)}
          ${cel(c.rjReseau.net, c.rjReseau.netCoul, c.rjReseau.netPct, true)}
        </tr>
      </table>
      </div>
      <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:9px;line-height:1.5;text-wrap:pretty">${esc(c.rjNote)}</div>
    </div>

    <!-- Le classement des tâches du jour : lu chez le panel, qui compte aussi
         les sautées, les échecs, les obligatoires manquées et la clôture de
         journée — quatre choses que le cockpit ne savait pas dire. -->
    <div style="${carte};padding:17px 19px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
        <div>
          <div style="${cap}">Tâches du jour — classement réseau</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">Demandées, faites, sautées, en échec — et les journées closes.</div>
        </div>
        ${c.rjClReseau ? `<div style="text-align:right">
          <div style="font-family:var(--font-display);font-size:26px;line-height:1;color:${c.rjClReseau.col};${num}">${esc(c.rjClReseau.taux)}</div>
          <div style="font-size:11px;color:var(--color-text-muted)">${esc(c.rjClReseau.detail)}</div>
          <div style="font-size:11px;color:var(--color-text-muted)">${esc(c.rjClReseau.clos)}</div>
        </div>` : ''}
      </div>
      ${c.rjClIndispo
        ? `<div style="font-size:12px;color:var(--color-text-muted);margin-top:12px">${esc(c.rjClIndispo)}</div>`
        : (c.rjClChargement
          ? `<div style="font-size:12px;color:var(--color-text-muted);margin-top:12px">Lecture du classement…</div>`
          : `<div style="display:flex;flex-direction:column;gap:8px;margin-top:13px">
              ${c.rjClLignes.map(l => `
                <div style="display:flex;align-items:center;gap:11px">
                  <span style="width:15px;flex:none;font-size:11px;color:var(--color-text-muted);text-align:right">${l.rang}</span>
                  <span style="width:230px;flex:none;font-size:12.5px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(l.nom)}</span>
                  <span style="flex:1;min-width:90px;height:8px;border-radius:999px;background:var(--color-background-secondary);overflow:hidden">
                    <i style="display:block;height:100%;width:${l.barre}%;border-radius:999px;background:${l.tauxCol}"></i></span>
                  <span style="width:58px;flex:none;text-align:right;font-size:12.5px;font-weight:600;color:${l.tauxCol};${num}">${esc(l.taux)}</span>
                  <span style="width:190px;flex:none;font-size:11px;color:var(--color-text-muted);${num}">${esc(l.detail)}</span>
                  <span style="flex:none;display:flex;gap:6px">
                    ${l.manque ? `<span style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:999px;background:#F7E4E6;color:var(--color-primary)">${esc(l.manque)}</span>` : ''}
                    ${l.clos ? `<span style="font-size:10px;padding:2px 8px;border-radius:999px;background:#E6F2E9;color:#2d7a3e">${esc(l.clos)}</span>` : ''}
                  </span>
                </div>`).join('')}
            </div>`)}
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

      ${c.rjDetail.objectif ? `
      <div style="display:flex;align-items:center;gap:12px;margin-top:12px;padding:10px 13px;border-radius:10px;background:var(--color-background-secondary);flex-wrap:wrap">
        <span style="${cap}">Objectif du jour</span>
        <span style="font-size:14px;font-weight:600;${num}">${esc(c.rjDetail.objectif.montant)}</span>
        <span style="position:relative;flex:1;min-width:120px;height:10px;border-radius:999px;background:var(--color-surface);overflow:hidden">
          ${c.rjDetail.objectif.proj ? `<i style="position:absolute;left:0;top:0;height:100%;width:${c.rjDetail.objectif.proj.w}%;border-radius:999px;background:#C9A227;opacity:.45"></i>` : ''}
          <i style="position:absolute;left:0;top:0;height:100%;width:${c.rjDetail.objectif.w}%;border-radius:999px;background:${c.rjDetail.objectif.coul}"></i></span>
        <span style="font-size:14px;font-weight:700;color:${c.rjDetail.objectif.coul};${num}">${esc(c.rjDetail.objectif.pct)}</span>
        <span style="font-size:11.5px;color:${c.rjDetail.objectif.coul};${num}">${esc(c.rjDetail.objectif.ecart)}</span>
        ${c.rjDetail.objectif.proj ? `<span style="width:100%;font-size:11.5px;font-weight:600;color:#8a6d12">
          Projection fin de journée : ${esc(c.rjDetail.objectif.proj.montant)}${c.rjDetail.objectif.proj.pct ? ' — ' + esc(c.rjDetail.objectif.proj.pct) + ' de l’objectif' : ''}
          <span style="font-weight:400;color:var(--color-text-muted)">· ${esc(c.rjDetail.objectif.proj.detail)}</span>
          ${c.rjDetail.objectif.proj.rythme ? `<span style="font-weight:400;color:var(--color-text-muted)"> · ${esc(c.rjDetail.objectif.proj.rythme)}</span>` : ''}
        </span>` : (c.rjDetail.projMotif ? `<span style="width:100%;font-size:11px;color:var(--color-text-muted)">Pas de projection : ${esc(c.rjDetail.projMotif)}.</span>` : '')}
        <span style="font-size:10.5px;color:var(--color-text-muted);width:100%">${esc(c.rjDetail.objectif.source)} · ${esc(c.rjDetail.objectif.base)}</span>
      </div>` : ''}

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
      ${f.investBascule ? `<div style="grid-column:span 2"><button ${x.A(f.investBascule)} style="display:inline-flex;align-items:center;gap:8px;border-radius:999px;height:31px;padding:0 13px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;${f.invest ? 'border:1px solid #E8C9A0;background:#FBF3DC;color:var(--color-on-abricot)' : 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted)'}">
        <span style="width:13px;height:13px;border-radius:4px;border:1.5px solid ${f.invest ? 'var(--color-on-abricot)' : 'var(--color-border-secondary)'};display:inline-flex;align-items:center;justify-content:center;font-size:9px;line-height:1">${f.invest ? '✓' : ''}</span>
        Investissement — équipe plutôt qu’il ne consomme</button>
        <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:4px">Le grand livre le badge et le sous-totalise par mois et par an.</div></div>` : ''}
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

    ${(c.foFournTotaux || []).length ? `<div style="${carte}">
      <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin-bottom:9px">
        <span style="font-size:13px;font-weight:500">Par fournisseur — ce que le fonds leur a payé</span>
        <span style="font-size:11px;color:var(--color-text-muted)">cliquez un fournisseur pour ses écritures</span>
      </div>
      ${c.foFournTotaux.map(fo => `
        <div ${x.A(fo.ouvrir)} style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;padding:8px 10px;border-radius:8px;cursor:pointer;${fo.ouvert ? 'background:var(--color-background-secondary)' : ''}">
          <span style="font-size:9px;color:var(--color-text-muted);width:9px">${fo.ouvert ? '▾' : '▸'}</span>
          <span style="font-size:12.5px;font-weight:500">${esc(fo.nom)}</span>
          <span style="font-size:10.5px;color:var(--color-text-muted)">${fo.n} écriture(s)</span>
          <span style="flex:1"></span>
          ${fo.invest ? `<span style="font-size:11px;font-weight:500;color:var(--color-on-abricot);font-variant-numeric:tabular-nums">${esc(fo.invest)}</span>` : ''}
          ${fo.revenu ? `<span style="font-size:11px;font-weight:500;color:#2d7a3e;font-variant-numeric:tabular-nums">${esc(fo.revenu)}</span>` : ''}
          <span style="font-size:12.5px;font-weight:600;color:var(--color-primary);font-variant-numeric:tabular-nums">− ${esc(fo.paye)}</span>
        </div>
        ${fo.ouvert ? fo.mvts.map(m => `
          <div style="display:flex;align-items:baseline;gap:10px;padding:4px 10px 4px 31px;font-size:11.5px">
            <span style="color:var(--color-text-muted);white-space:nowrap">${esc(m.date)}</span>
            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(m.libelle)}${m.invest ? ` <span style="display:inline-block;font-size:9px;font-weight:600;padding:0 6px;border-radius:999px;background:#FBF3DC;color:var(--color-on-abricot);border:1px solid #E8C9A0">investissement</span>` : ''}</span>
            <span style="color:${m.col};font-weight:500;font-variant-numeric:tabular-nums;white-space:nowrap">${esc(m.montant)}</span>
          </div>`).join('') : ''}`).join('')}
      ${c.foFournSans ? `<div style="font-size:10.5px;color:var(--color-text-muted);margin-top:9px;line-height:1.45">${esc(c.foFournSans)}</div>` : ''}
    </div>` : ''}

    <div style="display:grid;grid-template-columns:1fr;gap:12px;align-items:start">
      <div style="${carte};overflow:hidden">
        <div style="padding:13px 17px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span style="font-size:13px;font-weight:500">Grand livre du fonds</span>
          ${(c.foOrdreBtns || []).length && !c.foVide ? `<span style="display:inline-flex;border:0.5px solid rgba(34,34,34,0.14);border-radius:999px;overflow:hidden">
            ${c.foOrdreBtns.map(o => `<button ${x.A(o.go)} title="Ordre des mois" style="border:none;padding:5px 11px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer;${o.on ? 'background:var(--color-primary);color:#fff' : 'background:transparent;color:var(--color-text-muted)'}">${esc(o.nom)}</button>`).join('')}
          </span>` : ''}
          <span style="flex:1"></span>
          <span style="font-size:11px;color:var(--color-text-muted)">Un bloc par mois — entrées, sorties, solde · cliquez une ligne pour la corriger</span>
        </div>
        ${(c.foMoisBadges || []).length > 1 ? `<div style="padding:9px 17px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;gap:6px;flex-wrap:wrap;align-items:center">
          ${c.foMoisBadges.map(m => `<button ${x.A(m.go)} title="${m.on ? 'Revoir tous les mois' : 'Ne montrer que ' + esc(m.nom)}" style="border-radius:999px;padding:3px 11px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer;${m.on ? 'border:none;background:var(--color-primary);color:#fff' : 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text)'}">${esc(m.nom)}</button>`).join('')}
        </div>` : ''}
        ${(c.foAnnees || []).length ? `<div style="padding:8px 17px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;gap:18px;flex-wrap:wrap;background:var(--color-background-secondary)">
          ${c.foAnnees.map(a => `<span style="font-size:11px;font-variant-numeric:tabular-nums"><b style="font-weight:600">${esc(a.an)}</b>
            <span style="color:#2d7a3e;font-weight:500">${esc(a.entrees)}</span>
            <span style="color:var(--color-primary);font-weight:500">${esc(a.sorties)}</span>
            ${a.invest ? `<span style="color:var(--color-on-abricot);font-weight:500">· ${esc(a.invest)}</span>` : ''}</span>`).join('')}
        </div>` : ''}
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
          <tbody>${(c.foMoisGroupes || []).map(g => `
            <tr><td colspan="6" style="padding:11px 17px;background:var(--color-background-secondary);border-top:0.5px solid var(--color-border-tertiary)">
              <div style="display:flex;align-items:baseline;gap:14px;flex-wrap:wrap">
                <span style="font-size:12.5px;font-weight:600">${esc(g.nom)}</span>
                <span style="font-size:11px;font-weight:500;color:#2d7a3e;font-variant-numeric:tabular-nums">Entrées ${esc(g.totEntrees)}</span>
                <span style="font-size:11px;font-weight:500;color:var(--color-primary);font-variant-numeric:tabular-nums">Sorties ${esc(g.totSorties)}</span>
                ${g.totInvest ? `<span style="font-size:11px;font-weight:500;color:var(--color-on-abricot);font-variant-numeric:tabular-nums">${esc(g.totInvest)}</span>` : ''}
                <span style="flex:1"></span>
                <span style="font-size:11px;color:var(--color-text-muted)">Solde en fin de mois&nbsp;: <b style="color:${g.soldeCol};font-variant-numeric:tabular-nums">${esc(g.solde)}</b></span>
              </div>
            </td></tr>
            ${g.entrees.concat(g.sorties).map(l => `<tr>
            <td style="${td};padding-left:17px;white-space:nowrap;color:var(--color-text-muted)">${esc(l.date)}</td>
            <td style="${td}"><span style="font-weight:500">${esc(l.libelle)}</span>${l.invest ? ` <span style="display:inline-block;font-size:9.5px;font-weight:600;padding:1px 7px;border-radius:999px;background:#FBF3DC;color:var(--color-on-abricot);border:1px solid #E8C9A0;vertical-align:1px">investissement</span>` : ''}<div style="font-size:10.5px;color:${l.col}">${esc(l.sens)}${l.source ? ` <span style="color:var(--color-text-muted)">· ${esc(l.source)}</span>` : ''}</div></td>
            <td style="${td}">${l.levier
              ? `<span style="display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:500;padding:1px 8px;border-radius:999px;background:${l.levierCol}1f;border:1px solid ${l.levierCol};color:var(--color-text)"><span style="width:7px;height:7px;border-radius:2px;background:${l.levierCol}"></span>${esc(l.levier)}</span>`
              : (l.sens === 'sortie' ? `<span style="font-size:11px;color:var(--color-on-abricot)">aucun levier</span>` : '')}</td>
            <td style="${td};font-size:11.5px">${l.reseau
              ? `<span style="font-weight:500">Tout le réseau</span>`
              : `<span style="font-weight:500">${esc(l.magasin)}</span>`}${l.campagne ? `<div style="color:var(--color-text-muted)">${esc(l.campagne)}</div>` : ''}${l.fournisseur ? `<div style="color:var(--color-text-muted)">${esc(l.fournisseur)}</div>` : ''}</td>
            <td style="${td};${num};color:${l.col};font-weight:500">${esc(l.montant)}</td>
            <td style="${td};padding-right:17px;text-align:right;white-space:nowrap">
              ${l.editer ? `<button ${x.A(l.editer)} title="Corriger cette écriture" style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text-muted);border-radius:7px;padding:3px 9px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer">Corriger</button>` : ''}
              ${l.supprimer ? `<button ${x.A(l.supprimer)} title="Supprimer cette écriture" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:pointer;padding:0 2px;margin-left:4px">✕</button>` : ''}
              ${!l.editer && !l.supprimer ? `<span style="font-size:10.5px;color:var(--color-text-muted)">frais récurrent</span>` : ''}
            </td>
          </tr>`).join('')}`).join('')}</tbody>
        </table></div>
        ${c.foTronque ? `<div style="padding:9px 17px;font-size:11px;color:var(--color-text-muted);border-top:0.5px solid var(--color-border-tertiary)">${c.foTronque} mouvement(s) sur des mois plus anciens — le détail complet est dans le module marketing.</div>` : ''}`}
      </div>
    </div>

    <div style="${carte};overflow:hidden">
      <div style="padding:13px 17px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;gap:11px;flex-wrap:wrap">
        <span style="font-size:13px;font-weight:500">Redevances par client</span>
        <!-- La période se choisit ici : l'API recalcule CA, taux et dû pour
             le mois demandé — borné au mois courant, un CA qui n'existe pas
             encore ne se facture pas. -->
        ${c.foMoisChoisi ? `<input id="fo-roy-mois" type="month" value="${esc(c.foMoisChoisi)}" max="${esc(c.foMoisMax)}" ${x.C(c.foMoisSet)} title="Période des redevances" style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:8px;height:29px;padding:0 9px;font-family:var(--font-ui);font-size:12px">` : ''}
        <div style="flex:1"></div>
        ${c.foRoyEcrire ? `<button ${x.A(c.foRoyEcrire)} title="Une écriture par client — seule la redevance marketing part au fonds ; aperçu avant écriture" style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:8px;height:27px;padding:0 11px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Tout insérer au grand livre</button>` : ''}
      </div>
      ${!c.foRoyPlan ? '' : `<div style="padding:13px 17px;border-bottom:0.5px solid var(--color-border-tertiary);background:var(--color-background-secondary)">
        <div style="font-size:12.5px;font-weight:500">Redevances marketing ${esc(c.foRoyPlan.mois)} — ce qui partirait au fonds</div>
        <div style="font-size:11px;color:var(--color-text-muted);margin-top:3px;line-height:1.45">Seule la sorte Marketing alimente le fonds : les redevances Marque et Assistance restent des revenus de la marque, hors grand livre.</div>
        ${c.foRoyPlan.busy ? `<div style="font-size:12px;color:var(--color-text-muted);margin-top:7px">Calcul en cours…</div>` : `
        ${c.foRoyPlan.err ? `<div style="font-size:11.5px;color:var(--color-primary);margin-top:7px">${esc(c.foRoyPlan.err)}</div>` : `
        <table style="width:100%;border-collapse:collapse;margin-top:9px">
          <tbody>${c.foRoyPlan.lignes.map(l => `<tr>
            <td style="padding:4px 0;font-size:12px">${esc(l.magasin)}</td>
            <td style="padding:4px 0;font-size:12px;color:var(--color-text-muted)">${esc(l.sorte)}${l.taux ? ' · ' + esc(l.taux) : ''}</td>
            <td style="padding:4px 0;font-size:12px;text-align:right;font-variant-numeric:tabular-nums">${esc(l.montant)}</td>
            <td style="padding:4px 0 4px 9px;font-size:10.5px;color:var(--color-text-muted);text-align:right;white-space:nowrap">${l.deja ? 'déjà écrite' : 'à écrire'}</td>
          </tr>`).join('')}</tbody>
        </table>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:11px">
          <span style="font-size:11.5px;color:var(--color-text-muted)">${c.foRoyPlan.vide ? 'Tout est déjà écrit pour ce mois.' : `À écrire : <b style="color:var(--color-text)">${esc(c.foRoyPlan.total)}</b>${c.foRoyPlan.nDeja ? ' · ' + c.foRoyPlan.nDeja + ' ligne(s) déjà en place' : ''}`}</span>
          <span style="display:flex;gap:8px">
            <button ${x.A(c.foRoyPlan.fermer)} style="border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text-muted);border-radius:999px;height:30px;padding:0 14px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Annuler</button>
            ${c.foRoyPlan.vide ? '' : `<button ${x.A(c.foRoyPlan.confirmer)} style="border:none;background:var(--color-primary);color:#fff;border-radius:999px;height:30px;padding:0 16px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Confirmer l’écriture</button>`}
          </span>
        </div>`}`}
      </div>`}
      ${c.foRoyaltiesVide ? `<div style="padding:22px 17px;font-size:12.5px;color:var(--color-text-muted)">Aucun client.</div>` : `
      <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:${640 + (c.foRoyTypes || []).length * 110}px">
        <thead><tr>
          <th style="${th};padding-left:17px">Client</th>
          <th style="${th};${num}">CA ${esc(c.foMois)}</th>
          ${(c.foRoyTypes || []).map(t => `<th style="${th};${num}">${esc(t.nom)}${t.auFonds ? `<div style="font-size:9px;letter-spacing:.04em;color:#2d7a3e">→ fonds</div>` : ''}</th>`).join('')}
          <th style="${th};${num}">Dû total</th>
          <th style="${th};padding-right:17px;text-align:right">Grand livre</th>
        </tr></thead>
        <tbody>${c.foRoyalties.map(r => `<tr>
          <td style="${td};padding-left:17px"><span style="font-weight:500">${esc(r.nom)}</span>${r.ville ? `<div style="font-size:10.5px;color:var(--color-text-muted)">${esc(r.ville)}</div>` : ''}</td>
          <td style="${td};${num}">${esc(r.ca)}${r.manque ? `<div style="font-size:10px;color:var(--color-on-abricot);font-weight:400">${esc(r.manque)}</div>` : ''}</td>
          ${(r.cellules || []).map(cell => `<td style="${td};${num}"><span style="font-size:11px;color:var(--color-text-muted)">${esc(cell.taux)}</span>${cell.montant ? `<div style="font-weight:500">${esc(cell.montant)}</div>` : ''}</td>`).join('')}
          <td style="${td};${num};font-weight:500">${esc(r.du)}</td>
          <td style="${td};padding-right:17px;text-align:right;white-space:nowrap">
            ${r.inserer ? `<button ${x.A(r.inserer)} title="Insérer la redevance marketing de ce client au grand livre — le libellé porte la sorte et le montant" style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:7px;padding:4px 10px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer">Insérer au grand livre</button>` : ''}
            ${r.ecrit ? `<span style="font-size:10.5px;color:#2d7a3e;font-weight:500">✓ ${esc(r.ecrit)}</span>` : ''}
            ${!r.inserer && !r.ecrit ? `<span style="font-size:10.5px;color:var(--color-text-muted)"></span>` : ''}
          </td>
        </tr>`).join('')}</tbody>
      </table></div>`}
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
          <td style="padding:9px 12px 9px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;font-weight:500;color:${m.ecartCol}">${esc(m.ecart) || ''}</td>
          <td style="padding:9px 12px 9px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;color:var(--color-text-muted)">${esc(m.tickets)}</td>
          <td style="padding:9px 12px 9px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;color:var(--color-text-muted)">${esc(m.panier)}</td>
          <td style="padding:9px 12px 9px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-size:12.5px">${m.obj
            ? `<span style="font-variant-numeric:tabular-nums">${esc(m.obj)}</span>`
            : `<span style="font-size:10.5px;font-weight:500;padding:1px 7px;border-radius:999px;background:var(--color-background-secondary);color:var(--color-text-muted);border:1px solid var(--color-border-tertiary);white-space:nowrap">à renseigner</span>`}</td>
          <td style="padding:9px 0;border-top:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px;font-weight:700;color:${m.attCol}">${m.att ? esc(m.att) : ''}</td>
        </tr>`).join('')}</tbody>
      </table></div>
      ${c.tkPnlSansObj ? `<div style="font-size:11.5px;color:var(--color-text-muted);margin-top:10px;line-height:1.5">
        <strong style="font-weight:700">${c.tkPnlSansObj} magasin${c.tkPnlSansObj > 1 ? 's' : ''} sur ${c.tkPnlTotal} sans objectif de CA encodé</strong> — l’atteinte reste vide : elle ne peut pas se calculer contre une cible absente. Le budget se saisit dans « Encodage du budget ». En attendant, l’écart N-1 est la seule référence disponible.</div>` : ''}`}
    </div>

    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      ${c.tkMoi ? `<button ${x.A(c.tkMoiGo)} title="Les tâches dont je suis l'intervenant" style="border:0.5px solid ${c.tkMoiOn ? 'var(--color-primary)' : 'var(--color-border-secondary)'};background:${c.tkMoiOn ? 'var(--color-primary)' : 'transparent'};color:${c.tkMoiOn ? '#fff' : 'var(--color-text)'};border-radius:999px;padding:7px 14px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer;white-space:nowrap">Mes tâches${c.tkMoiN ? ' · ' + c.tkMoiN : ''}</button>` : ''}
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
                    <!-- La photo de contrôle qui a motivé la demande, avec ses
                         repères : une consigne se comprend mieux devant le
                         cliché qu'à côté d'une phrase. -->
                    ${t.photo ? `
                      <div style="${dcap};margin:13px 0 6px">${esc(t.photo.titre)}</div>
                      ${t.photo.chargement || t.photo.attente
                        ? `<div style="font-size:11.5px;color:var(--color-text-muted)">${t.photo.attente ? 'dépliez pour la charger' : 'lecture de la photo…'}</div>`
                        : (t.photo.vide
                          ? `<div style="font-size:11.5px;color:var(--color-text-muted)">${esc(t.photo.motif)}</div>`
                          : `<div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
                              <div style="position:relative;display:inline-block;line-height:0;flex:0 1 380px;max-width:100%${t.photo.imgErr ? ';min-height:150px;background:var(--color-background-secondary);border-radius:8px' : ''}">
                                <img data-tphoto="${esc(t.photo.cle)}" src="${t.photo.url}" alt="Photo de contrôle" style="display:${t.photo.imgErr ? 'none' : 'block'};width:100%;border-radius:8px">
                                ${t.photo.imgErr
                                  ? `<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:16px;text-align:center;font-size:11.5px;color:var(--color-text-muted);line-height:1.5">${esc(t.photo.imgErrTxt)}</div>`
                                  : t.photo.reperes.map(r => `<div style="${r.boxSt}"><span style="${r.numSt}">${r.n}</span></div>`).join('')}
                              </div>
                              <div style="flex:1;min-width:180px">
                                <div style="font-size:11.5px;font-weight:500">${esc(t.photo.tache)}</div>
                                <div style="font-size:11px;color:var(--color-text-muted);margin-top:2px">${esc(t.photo.legende)}${t.photo.avis ? ' · ' + esc(t.photo.avis) : ''}</div>
                                <div style="display:flex;flex-direction:column;gap:6px;margin-top:9px">
                                  ${t.photo.reperes.filter(r => r.txt).map(r => `
                                    <div style="display:flex;gap:7px;align-items:flex-start;font-size:11.5px;line-height:1.45">
                                      <span style="flex:0 0 auto;width:16px;height:16px;border-radius:50%;color:#fff;font-size:9.5px;font-weight:700;display:flex;align-items:center;justify-content:center;background:${r.coul}">${r.n}</span>
                                      <span>${esc(r.txt)}</span>
                                    </div>`).join('')}
                                </div>
                              </div>
                            </div>`)}` : ''}
                    <div style="margin-top:12px;display:flex;justify-content:flex-end">
                      <button ${x.A(t.supprimer)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text-muted);border-radius:999px;padding:5px 12px;font-family:var(--font-ui);font-size:11px;cursor:pointer">Supprimer la tâche</button>
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
              <span style="display:inline-flex;gap:6px;align-items:center;white-space:nowrap">
                <input type="date" value="${esc(c.rapCompo.perDu.val)}" ${x.I(c.rapCompo.perDu.set)} style="font-size:11.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:5px 8px;background:var(--color-surface);color:var(--color-text)">
                <span style="font-size:11.5px;color:var(--color-text-muted)">→</span>
                <input type="date" value="${esc(c.rapCompo.perAu.val)}" ${x.I(c.rapCompo.perAu.set)} style="font-size:11.5px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:5px 8px;background:var(--color-surface);color:var(--color-text)">
              </span>`
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
            ${c.vuesLignes.map(l => `<tr${l.horsTop ? ' style="background:var(--color-background-secondary)"' : ''}>
              <td style="padding:3px 8px 3px 0;white-space:nowrap">${esc(l.nom)}${l.horsTop ? ' <span style="font-size:9px;font-weight:600;padding:1px 6px;border-radius:999px;background:var(--color-surface);color:var(--color-text-muted)">hors top 5</span>' : ''}${l.qui ? `<div style="font-size:9.5px;color:var(--color-text-muted)">${esc(l.qui)}</div>` : ''}</td>
              <td style="padding:3px 10px 3px 0;text-align:right;font-weight:600;font-variant-numeric:tabular-nums">${l.total}</td>
              ${l.cases.map(k2 => `<td style="padding:2px 1px" title="${esc(k2.jour)} — ${k2.n} ouverture(s)"><i style="${k2.st}"></i></td>`).join('')}
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
      ${c.vuesReste ? `<div style="display:flex;align-items:center;gap:9px;margin-top:10px;flex-wrap:wrap">
        <span style="font-size:11px;color:var(--color-text-muted)">Top 5 affiché · ${c.vuesReste} autre${c.vuesReste > 1 ? 's' : ''} écran${c.vuesReste > 1 ? 's' : ''} ouvert${c.vuesReste > 1 ? 's' : ''} sur la période</span>
        <select ${x.C(c.setVuesAutre)} style="font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:5px 9px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui);max-width:340px">
          ${c.vuesAutres.map(o => `<option value="${esc(o.v)}"${o.v === c.vuesAutreSel ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}
        </select>
      </div>` : ''}
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
      <!-- Les cinq derniers d'abord ; le reste s'ouvre d'un clic. -->
      ${c.logPlier ? `<div style="padding:10px 14px;border-top:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;gap:10px">
        <button ${x.A(c.logPlier)} style="border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:6px 13px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">${c.logTout ? 'Ne montrer que les 5 derniers' : 'Afficher les ' + c.logReste + ' plus anciens'}</button>
        <span style="font-size:11px;color:var(--color-text-muted)">${c.logTout ? 'Journal complet affiché' : '5 derniers événements'}</span>
      </div>` : ''}
    </div>

    <!-- Les e-mails partis du cockpit : rapports et commandes fournisseur,
         dans un seul tableau — « ce mail est-il parti ? » se répondait
         jusqu'ici dans deux écrans différents. -->
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden">
      <div style="padding:13px 14px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <span style="font-size:13px;font-weight:500">E-mails envoyés — rapports et commandes fournisseur</span>
        <span style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.mailsCompte || '')}</span>
      </div>
      ${c.mailsChargement ? `<div style="padding:16px 14px;font-size:12.5px;color:var(--color-text-muted)">Lecture des envois…</div>`
        : c.mailsIndispo ? `<div style="padding:16px 14px;font-size:12.5px;color:var(--color-on-abricot)">Les envois n’ont pas pu être lus — l’API n’a pas répondu.</div>`
        : c.mailsVide ? `<div style="padding:16px 14px;font-size:12.5px;color:var(--color-text-muted)">Aucun envoi tracé pour l’instant.</div>` : `
      <table style="width:100%;border-collapse:collapse;font-size:12.5px">
        <thead><tr>
          <th style="${TH};white-space:nowrap">Horodatage</th>
          <th style="${TH2};text-align:left">Source</th>
          <th style="${TH2};text-align:left">Objet</th>
          <th style="${TH2};text-align:left">Destinataire</th>
          <th style="${TH2};text-align:left">État</th>
          <th style="${TH};text-align:left">Détail</th>
        </tr></thead>
        <tbody>
          ${c.mailsRows.map(m => `
            <tr style="border-bottom:0.5px solid var(--color-border-tertiary)">
              <td style="padding:9px 14px;white-space:nowrap;color:var(--color-text-muted)">${esc(m.ts)}</td>
              <td style="padding:9px 12px"><span style="${m.srcSt}">${esc(m.source)}</span></td>
              <td style="padding:9px 12px;font-weight:500">${esc(m.objet)}</td>
              <td style="padding:9px 12px;color:var(--color-text-muted)">${esc(m.dest)}</td>
              <td style="padding:9px 12px"><span style="${m.etatSt}">${esc(m.etat)}</span></td>
              <td style="padding:9px 14px;line-height:1.45;color:var(--color-text-muted)">${esc(m.detail)}</td>
            </tr>`).join('')}
        </tbody>
      </table>
      ${c.mailsPlier ? `<div style="padding:10px 14px;border-top:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;gap:10px">
        <button ${x.A(c.mailsPlier)} style="border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:6px 13px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">${c.mailsTout ? 'Ne montrer que les 5 derniers' : 'Afficher les ' + c.mailsReste + ' plus anciens'}</button>
        <span style="font-size:11px;color:var(--color-text-muted)">${c.mailsTout ? 'Tous les envois affichés' : '5 derniers envois'}</span>
      </div>` : ''}`}
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
    ${!c.prmAgences ? '' : `
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
      <div style="font-size:13px;font-weight:500;margin-bottom:4px">Agences</div>
      <div style="font-size:12px;color:var(--color-text-muted);margin-bottom:14px">Le réseau travaille avec plusieurs agences : chacune porte son nom, son adresse et son logo. La note de campagne signe celle que la campagne désigne sur un de ses canaux ; à défaut, celle marquée « par défaut ».</div>
      ${c.prmAgences.chargement ? `<div style="font-size:12px;color:var(--color-text-muted)">Lecture du référentiel…</div>` : `
      <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:720px">
        <thead><tr>
          <th style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 8px 8px 0">Agence</th>
          <th style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 8px 8px">Adresse e-mail</th>
          <th style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 8px 8px">Site</th>
          <th style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 8px 8px">Logo</th>
          <th style="text-align:right;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:0 8px 8px">Campagnes</th>
          <th style="padding:0 0 8px 8px"></th>
        </tr></thead>
        <tbody>
          ${c.prmAgences.lignes.map(a => `<tr style="border-top:0.5px solid var(--color-border-tertiary)">
            <td style="padding:7px 8px 7px 0"><input value="${esc(a.nom)}" ${x.C(a.setNom)} style="${rowInput}" />${a.defaut ? `<div style="font-size:10px;color:var(--color-primary);margin-top:3px">par défaut</div>` : `<button ${x.A(a.parDefaut)} style="border:none;background:none;padding:0;margin-top:3px;font-size:10px;color:var(--color-text-muted);cursor:pointer;text-decoration:underline">définir par défaut</button>`}</td>
            <td style="padding:7px 8px"><input value="${esc(a.email)}" ${x.C(a.setEmail)} placeholder="contact@agence.be" style="${rowInput}" /></td>
            <td style="padding:7px 8px"><input value="${esc(a.site)}" ${x.C(a.setSite)} placeholder="agence.be" style="${rowInput}" /></td>
            <td style="padding:7px 8px">${a.logo ? `<img src="${esc(a.logo)}" alt="" style="height:24px;border-radius:3px;vertical-align:middle" />` : ''}<label style="display:inline-block;margin-left:${a.logo ? '8px' : '0'};font-size:11.5px;color:var(--color-primary);cursor:pointer;text-decoration:underline">${a.logo ? 'changer' : 'choisir'}<input type="file" accept="image/*" ${x.C(a.setLogo)} style="display:none" /></label></td>
            <td style="padding:7px 8px;text-align:right;color:var(--color-text-muted)">${a.campagnes || ''}</td>
            <td style="padding:7px 0 7px 8px;text-align:right"><button ${x.A(a.retirer)} title="Retirer du référentiel" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:pointer">✕</button></td>
          </tr>`).join('')}
          <tr style="border-top:0.5px solid var(--color-border-tertiary)">
            <td style="padding:9px 8px 9px 0"><input value="${esc(c.prmAgences.nom)}" ${x.C(c.prmAgences.setNom)} placeholder="Nouvelle agence" style="${rowInput}" /></td>
            <td style="padding:9px 8px"><input value="${esc(c.prmAgences.email)}" ${x.C(c.prmAgences.setEmail)} placeholder="contact@agence.be" style="${rowInput}" /></td>
            <td colspan="3"></td>
            <td style="padding:9px 0 9px 8px;text-align:right"><button ${x.A(c.prmAgences.ajouter)} style="border:none;background:var(--color-primary);color:#fff;border-radius:7px;padding:6px 13px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:${c.prmAgences.ajouter ? 'pointer' : 'not-allowed'};opacity:${c.prmAgences.ajouter ? '1' : '.5'}">Ajouter</button></td>
          </tr>
        </tbody>
      </table></div>
      ${c.prmAgences.err ? `<div style="font-size:11.5px;color:#8D1D2C;margin-top:8px">${esc(c.prmAgences.err)}</div>` : ''}`}
    </div>`}
    ${!c.prmNote ? '' : `
    <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
      <div style="font-size:13px;font-weight:500;margin-bottom:4px">Notes de campagne — expéditeur</div>
      <div style="font-size:12px;color:var(--color-text-muted);margin-bottom:14px">L’adresse d’où partent les notes aux franchisés. Le nom, le logo et l’adresse des agences se règlent dans le tableau ci-dessus.</div>
      ${c.prmNote.chargement ? `<div style="font-size:12px;color:var(--color-text-muted)">Lecture des réglages…</div>` : `
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end">
        <label style="font-size:12px;color:var(--color-text-muted)">Expéditeur des notes<input value="${esc(c.prmNote.expediteur)}" ${x.C(c.prmNote.setExpediteur)} style="${inputCss}" /></label>
      </div>
      <div style="display:flex;align-items:center;gap:10px;margin-top:12px;flex-wrap:wrap">
        <span style="flex:1"></span>
        <span style="font-size:11px;color:var(--color-text-muted)">${c.prmNote.nCarnet} adresse${c.prmNote.nCarnet > 1 ? 's' : ''} de franchisé au carnet</span>
        <button ${x.A(c.prmNote.enregistrer)} style="border:none;background:var(--color-primary);color:#fff;border-radius:8px;padding:8px 16px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;cursor:pointer">Enregistrer</button>
      </div>
      ${c.prmNote.err ? `<div style="font-size:11.5px;color:#8D1D2C;margin-top:8px">${esc(c.prmNote.err)}</div>` : ''}`}
    </div>`}
    <div style="display:flex;flex-direction:column;gap:16px">
      <div style="margin:8px 0 -4px"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:var(--color-text)">Seuils &amp; cibles</div><div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">Ce qui déclenche les alertes des écrans et des rapports.</div></div>
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
      <div style="margin:8px 0 -4px"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:var(--color-text)">Courriers &amp; notifications</div><div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">Tout ce qui part du cockpit : la machine d’envoi, les modèles de messages, et le journal de ce qui est parti.</div></div>
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
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px">
          <div style="font-size:13px;font-weight:500">Centrale d’achat — rappel quotidien au fournisseur</div>
          <span style="${c.cm.etatSt}">${esc(c.cm.etatTxt)}</span>
        </div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:12px;text-wrap:pretty">Un e-mail <b style="font-weight:500">par fournisseur et par jour</b>, listant toutes ses commandes encore en attente — il repart chaque jour tant qu’une commande n’est pas acceptée, et s’arrête d’elle-même ensuite. Le fournisseur est le destinataire, la centrale reçoit la copie. Détection au rythme du cron horaire des rapports, envoi par la machine SMTP ci-dessus. Seules les commandes des <b style="font-weight:500">${esc(c.cm.fenetre)}</b> écoulés sont relancées : l’ERP garde des réquisitions « en attente » qu’il ne referme jamais, et relancer un fournisseur sur une commande de l’an dernier n’appelle rien. Les plus anciennes sont comptées, et le courrier dit qu’elles existent.</div>
        <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;cursor:pointer;margin-bottom:12px">
          <input type="checkbox" ${c.cm.actif ? 'checked' : ''} ${x.C(c.cm.toggle)} style="width:15px;height:15px;accent-color:var(--color-primary)">
          Envoi automatique activé
        </label>
        <label style="display:block;font-size:12px;color:var(--color-text-muted);margin-bottom:12px">Expéditeur affiché — et adresse de réponse
          <input value="${esc(c.cm.expediteur)}" ${x.C(c.cm.setExpediteur)} placeholder="Centrale d’achat &lt;achats@atelierby.be&gt;" style="${inputCss}">
          <span style="display:block;font-size:10.5px;color:var(--color-text-muted);margin-top:4px">Le fournisseur lit ce nom et répond à cette adresse. L’envoi passe toujours par le compte SMTP configuré plus haut — seul l’en-tête change.</span>
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label style="font-size:12px;color:var(--color-text-muted)">La centrale — copie, et repli si le fournisseur n’a pas d’adresse
            <input value="${esc(c.cm.destinataire)}" ${x.C(c.cm.setDestinataire)} placeholder="achats@atelierby.be" style="${inputCss}">
          </label>
          <label style="font-size:12px;color:var(--color-text-muted)">Copie supplémentaire (optionnel)
            <input value="${esc(c.cm.copie)}" ${x.C(c.cm.setCopie)} placeholder="—" style="${inputCss}">
          </label>
        </div>
        <label style="display:block;font-size:12px;color:var(--color-text-muted);margin-top:10px">Sujet
          <input value="${esc(c.cm.sujet)}" ${x.C(c.cm.setSujet)} style="${inputCss}">
        </label>
        <label style="display:block;font-size:12px;color:var(--color-text-muted);margin-top:10px">Corps du message
          <textarea rows="7" ${x.C(c.cm.setCorps)} style="${inputCss};resize:vertical;font-family:var(--font-ui)">${esc(c.cm.corps)}</textarea>
        </label>
        <div style="font-size:11px;color:var(--color-text-muted);margin-top:6px">Variables : ${esc(c.cm.variables)}</div>
        ${c.cm.vieux ? `<div style="margin-top:9px;padding:9px 12px;border-radius:8px;background:#FBF3DC;border:1px solid #E8C9A0;font-size:11.5px;color:var(--color-on-abricot);line-height:1.5">Ce gabarit date d’avant le regroupement : il parle d’<b style="font-weight:600">une</b> commande alors que le courrier en liste plusieurs. Les anciennes variables restent remplies, mais <b style="font-weight:600">{{lignes}}</b> — la liste des commandes — n’y figure pas.
          ${c.cm.restaurer ? `<button ${x.A(c.cm.restaurer)} style="margin-left:6px;border:1px solid #E8C9A0;background:#fff;color:var(--color-on-abricot);border-radius:999px;padding:3px 11px;font-family:var(--font-ui);font-size:11px;font-weight:600;cursor:pointer">Reprendre le gabarit d’origine</button>` : ''}</div>` : ''}
        <div style="margin-top:12px;border-top:0.5px solid var(--color-border-tertiary);padding-top:12px">
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button ${x.A(c.cm.htmlBascule)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text);border-radius:8px;height:30px;padding:0 12px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">${c.cm.htmlOuvert ? 'Masquer' : 'Modifier'} la mise en page HTML</button>
            <button ${x.A(c.cm.apercuBascule)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text);border-radius:8px;height:30px;padding:0 12px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">${c.cm.apercuOuvert ? 'Masquer' : 'Voir'} l’aperçu</button>
            <span style="font-size:11px;color:var(--color-text-muted)">${c.cm.html ? 'mise en page personnalisée' : 'mise en page d’origine'}</span>
          </div>
          ${c.cm.htmlOuvert ? `
            <textarea rows="12" ${x.C(c.cm.setHtml)} placeholder="Laissez vide pour la mise en page d’origine" style="${inputCss};resize:vertical;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;margin-top:9px">${esc(c.cm.html)}</textarea>
            <div style="font-size:11px;color:var(--color-text-muted);margin-top:6px">Variables de mise en page : ${esc(c.cm.variablesHtml)} — <b style="font-weight:500">{{contenu}}</b> porte le message et les cartes de commande.
              ${c.cm.htmlDefaut ? `<button ${x.A(c.cm.htmlDefaut)} style="margin-left:6px;border:none;background:none;color:var(--color-primary);font-size:11px;font-weight:600;cursor:pointer;text-decoration:underline;text-underline-offset:2px">reprendre le squelette d’origine</button>` : ''}</div>` : ''}
          ${c.cm.apercuOuvert ? `<iframe src="${esc(c.cm.apercuUrl)}" style="width:100%;height:520px;border:0.5px solid var(--color-border-tertiary);border-radius:10px;margin-top:10px;background:#fff"></iframe>` : ''}
        </div>
        <div style="display:flex;align-items:center;gap:10px;margin-top:12px;flex-wrap:wrap">
          <button ${x.A(c.cm.save)} style="border:none;border-radius:999px;padding:8px 16px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;${c.cm.busy ? 'opacity:.6' : ''}">${c.cm.busy ? 'En cours…' : 'Enregistrer'}</button>
          <input value="${esc(c.cm.testVers)}" ${x.C(c.cm.setTestVers)} placeholder="adresse d’essai — vide = la centrale" style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:8px;height:32px;padding:0 10px;font-family:var(--font-ui);font-size:12px;min-width:220px">
          <button ${x.A(c.cm.test)} style="border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:8px 14px;background:transparent;color:var(--color-text);font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer">Envoyer un essai</button>
          <span style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.cm.dernier)}</span>
        </div>
        ${c.cm.msg ? `<div style="${c.cm.msgSt}">${esc(c.cm.msg)}</div>` : ''}

        <div style="margin-top:16px;padding-top:14px;border-top:0.5px solid var(--color-border-tertiary)">
          <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin-bottom:4px">
            <span style="font-size:12.5px;font-weight:500">Adresses des fournisseurs</span>
            ${c.cm.sansAdresse ? `<span style="font-size:11px;font-weight:500;padding:2px 9px;border-radius:999px;background:#FBF3DC;color:var(--color-on-abricot);border:1px solid #E8C9A0">${c.cm.sansAdresse} sans adresse</span>` : ''}
          </div>
          <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-bottom:9px">
            <button ${x.A(c.cm.classer)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:8px;height:30px;padding:0 12px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Classer les ${c.cm.enAttenteN} réquisition(s) en attente</button>
            ${c.cm.rouvrir ? `<button ${x.A(c.cm.rouvrir)} style="border:none;background:none;color:var(--color-text-muted);font-size:11px;cursor:pointer;text-decoration:underline;text-underline-offset:2px">rouvrir</button>` : ''}
            ${c.cm.classeesTxt ? `<span style="font-size:11px;color:var(--color-text-muted)">${esc(c.cm.classeesTxt)}</span>` : ''}
          </div>
          <div style="font-size:11px;color:var(--color-text-muted);margin-bottom:10px;line-height:1.5;text-wrap:pretty">Classer arrête le rappel <b style="font-weight:500">automatique</b> : rien n’est modifié dans l’ERP, le fournisseur n’est pas averti, et la cloche du Suivi fournisseurs reste là pour relancer à la main. Le classement se défait.</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:10px;text-wrap:pretty">Ceux qui ont une commande en attente. Ni le panel ni le référentiel local ne portent leur adresse : sans elle, le rappel part à la centrale — la commande n’est jamais perdue, mais le fournisseur ne sait rien.</div>
          ${!(c.cm.fournisseurs || []).length ? `<div style="font-size:12px;color:var(--color-text-muted)">Aucune commande en attente pour l’instant.</div>` : c.cm.fournisseurs.map(f => `
            <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-bottom:7px">
              <span style="min-width:190px;font-size:12.5px;font-weight:500">${esc(f.nom)}${f.sans ? ` <span style="font-size:10px;font-weight:600;color:var(--color-on-abricot)">sans adresse</span>` : ''}</span>
              <span style="font-size:11px;color:var(--color-text-muted);min-width:140px">${f.commandes} commande(s) · ${esc(f.total)}${f.anciennes ? `<div style="color:var(--color-on-abricot)">${esc(f.anciennes)}</div>` : ''}</span>
              <input value="${esc(f.email)}" ${x.C(f.set)} placeholder="commandes@fournisseur.be" style="border:0.5px solid ${f.sans ? '#E8C9A0' : 'var(--color-border-secondary)'};background:var(--color-surface);color:var(--color-text);border-radius:8px;height:30px;padding:0 10px;font-family:var(--font-ui);font-size:12px;flex:1;min-width:210px">
              <button ${x.A(f.enregistrer)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text);border-radius:8px;height:30px;padding:0 12px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">Enregistrer</button>
              <span style="font-size:10.5px;color:var(--color-text-muted);min-width:150px">${esc(f.relance)}${f.source ? ' · ' + esc(f.source) : ''}</span>
            </div>`).join('')}
        </div>
      </div>
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px">
          <div style="font-size:13px;font-weight:500">Relance « commande à valider » — notification</div>
          <span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:500;background:var(--color-background-secondary);color:var(--color-text-muted)">${c.rl.envoyees} envoyée${c.rl.envoyees > 1 ? 's' : ''}</span>
        </div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:12px;text-wrap:pretty">Le texte de la notification créée dans l’ERP par la cloche du Suivi fournisseurs. Elle est rattachée au magasin de la commande et porte un lien vers celle-ci — ce n’est pas un e-mail.</div>
        <label style="display:block;font-size:12px;color:var(--color-text-muted)">Titre
          <input value="${esc(c.rl.titre)}" ${x.C(c.rl.setTitre)} style="${inputCss}">
        </label>
        <label style="display:block;font-size:12px;color:var(--color-text-muted);margin-top:10px">Message
          <textarea rows="4" ${x.C(c.rl.setMessage)} style="${inputCss};resize:vertical;font-family:var(--font-ui)">${esc(c.rl.message)}</textarea>
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr 110px;gap:12px;margin-top:10px">
          <label style="font-size:12px;color:var(--color-text-muted)">Priorité
            <select ${x.C(c.rl.setPriorite)} style="${inputCss}">
              ${['info', 'warning', 'urgent'].map(v => `<option value="${v}"${c.rl.priorite === v ? ' selected' : ''}>${v}</option>`).join('')}
            </select>
          </label>
          <label style="font-size:12px;color:var(--color-text-muted)">Libellé du lien
            <input value="${esc(c.rl.actionLabel)}" ${x.C(c.rl.setActionLabel)} style="${inputCss}">
          </label>
          <label style="font-size:12px;color:var(--color-text-muted)">Visible (jours)
            <input type="number" min="1" max="60" value="${esc(c.rl.jours)}" ${x.C(c.rl.setJours)} style="${inputCss}">
          </label>
        </div>
        <label style="display:block;font-size:12px;color:var(--color-text-muted);margin-top:10px">Lien ouvert par la notification
          <input value="${esc(c.rl.actionUrl)}" ${x.C(c.rl.setActionUrl)} placeholder="https://atelierby.tfbuddy.com/panel/material-orders/pending" style="${inputCss}">
        </label>
        <div style="font-size:11px;color:var(--color-text-muted);margin-top:6px">Variables : ${esc(c.rl.variables)}</div>
        <div style="display:flex;align-items:center;gap:10px;margin-top:12px">
          <button ${x.A(c.rl.save)} style="border:none;border-radius:999px;padding:8px 16px;background:var(--color-primary);color:#fff;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;${c.rl.busy ? 'opacity:.6' : ''}">${c.rl.busy ? 'En cours…' : 'Enregistrer'}</button>
        </div>
        ${c.rl.msg ? `<div style="${c.rl.msgSt}">${esc(c.rl.msg)}</div>` : ''}
      </div>
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:20px">
        <div style="font-size:13px;font-weight:500;margin-bottom:4px">Journal des envois — e-mails &amp; notifications</div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:12px;text-wrap:pretty">Chaque commande reçue, chaque e-mail et chaque relance (réussis ou non) laissent une trace — les 50 dernières entrées.</div>
        ${!c.cm.journal.length ? `<div style="font-size:12.5px;color:var(--color-text-muted)">Rien encore — le journal se remplit dès la première commande détectée ou le premier essai.</div>` : `
        <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:520px">
          <thead><tr>
            <th style="text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 12px 8px 0;white-space:nowrap">Quand</th>
            <th style="text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 12px 8px 0;white-space:nowrap">Événement</th>
            <th style="text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 12px 8px 0">Détail</th>
            <th style="text-align:left;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:0 0 8px 0;white-space:nowrap">Destinataire</th>
          </tr></thead>
          <tbody>${c.cm.journal.map(j => `<tr>
            <td style="padding:7px 12px 7px 0;border-top:0.5px solid var(--color-border-tertiary);font-size:12px;white-space:nowrap;color:var(--color-text-muted)">${esc(j.quand)}</td>
            <td style="padding:7px 12px 7px 0;border-top:0.5px solid var(--color-border-tertiary);font-size:12px;font-weight:500;white-space:nowrap;color:${j.col}">${esc(j.type)}</td>
            <td style="padding:7px 12px 7px 0;border-top:0.5px solid var(--color-border-tertiary);font-size:12px">${esc(j.detail)}</td>
            <td style="padding:7px 0;border-top:0.5px solid var(--color-border-tertiary);font-size:12px;white-space:nowrap;color:var(--color-text-muted)">${esc(j.destinataire)}</td>
          </tr>`).join('')}</tbody>
        </table></div>`}
      </div>
      <div style="margin:8px 0 -4px"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:var(--color-text)">Référentiels</div><div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">Les tables qui pilotent les écrans : KPI, modèles, gabarits.</div></div>
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
      <!-- Cadence dynamique des contrôles : retirée de l'écran pour le moment — le backend (src/cadence.php, /cadence) reste en place, le bloc se restaure depuis l'historique git. -->
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
              <input type="number" step="1" min="0" value="${esc(String(c.npEco.royaltiesEuro))}" ${x.C(c.npEco.setRoyaltiesEuro)} placeholder="au taux ${esc(String(c.npEco.royaltiesTaux || ''))} %" style="${inpD}"></div>
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
            ? `<button ${x.A(d.zoomGo)} title="Agrandir et poser des repères" style="border:none;background:none;padding:0;display:block;width:100%;cursor:zoom-in;position:relative"><img src="${d.photo}" alt="Photo de réalisation" style="width:100%;aspect-ratio:1/1;object-fit:cover;object-position:center;border-radius:10px;border:0.5px solid var(--color-border-tertiary);display:block">
                <span style="position:absolute;left:7px;bottom:7px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;padding:3px 9px;border-radius:999px;background:rgba(20,16,14,0.72);color:#fff">${d.nRep ? d.nRep + ' repère' + (d.nRep > 1 ? 's' : '') : 'annoter'}</span></button>
               <a href="${d.photo}" target="_blank" rel="noopener" style="display:inline-block;margin-top:5px;font-size:10.5px;color:var(--color-text-muted)">fichier d’origine</a>`
            : `<div style="background:var(--color-background-secondary);border-radius:10px;aspect-ratio:1/1;display:flex;align-items:center;justify-content:center;padding:12px;text-align:center;font-size:12px;color:var(--color-text-muted);line-height:1.5">${esc(d.photoTxt)}</div>`}
        </div>
        <div>
          <div style="${'font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)'};margin-bottom:6px">Référence attendue</div>
          ${d.photoRef
            ? `<a href="${d.photoRef}" target="_blank" rel="noopener" title="Ouvrir en grand"><img src="${d.photoRef}" alt="Photo de référence du produit" style="width:100%;aspect-ratio:1/1;object-fit:cover;object-position:center;border-radius:10px;border:0.5px solid var(--color-border-tertiary);display:block"></a>`
            : `<div style="background:var(--color-background-secondary);border-radius:10px;aspect-ratio:1/1;display:flex;align-items:center;justify-content:center;padding:12px;text-align:center;font-size:12px;color:var(--color-text-muted);line-height:1.5">${esc(d.photoRefTxt)}</div>`}
        </div>
      </div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:6px">Un contrôle qualité se juge par comparaison : la photo produit (products/available \u2192 recipes/{id_recipe}, le même API que le webshop) s\u2019affiche en face de la photo prise en boutique. Sans chemin : « Pas de photo. »</div>

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

      <!-- Réclamation fournisseur : le défaut vient du produit, pas du geste. -->
      <div style="border-top:0.5px solid var(--color-border-tertiary);margin-top:12px;padding-top:11px">
        <button ${x.A(d.rc.basculer)} style="border:none;background:none;padding:0;cursor:pointer;font-family:var(--font-ui);font-size:12px;font-weight:500;color:var(--color-primary)">
          ${d.rc.ouvert ? '− Réclamation fournisseur' : '+ Ouvrir une réclamation fournisseur'}</button>
        ${d.rc.fait ? `<span style="margin-left:9px;font-size:11px;font-weight:600;padding:2px 9px;border-radius:999px;background:#E6F2E9;color:#2d7a3e">déposée${typeof d.rc.fait === 'number' ? ' #' + d.rc.fait : ''}</span>` : ''}
        ${d.rc.ouvert ? `
          ${d.rc.chargement ? `<div style="font-size:11.5px;color:var(--color-text-muted);margin-top:9px">Lecture du catalogue fournisseur…</div>`
            : (d.rc.indispo ? `<div style="font-size:11.5px;color:var(--color-text-muted);margin-top:9px">${esc(d.rc.indispo)}</div>` : `
          <div style="margin-top:10px">
            <div style="font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:4px">Référence en cause</div>
            <input value="${esc(d.rc.q)}" ${x.I(d.rc.setQ)} placeholder="Chercher une référence ou un SKU…" style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:7px 10px;font-family:var(--font-ui);font-size:12px;background:var(--color-surface);color:var(--color-text)">
            ${d.rc.trouvees.length ? `<div style="display:flex;flex-direction:column;gap:2px;margin-top:5px;max-height:140px;overflow-y:auto">
              ${d.rc.trouvees.map(m => `<button ${x.A(m.choisir)} style="text-align:left;border:none;background:var(--color-background-secondary);border-radius:7px;padding:6px 9px;cursor:pointer;font-family:var(--font-ui);font-size:11.5px">${esc(m.nom)} <span style="color:var(--color-text-muted)">· ${esc(m.sku)}</span></button>`).join('')}
            </div>` : ''}
            ${d.rc.matiere ? `<div style="font-size:11.5px;margin-top:6px;color:#2d7a3e;font-weight:500">✓ ${esc(d.rc.matiere)}</div>` : ''}
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:9px">
            <div><div style="font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:4px">Livraison</div>
              <select ${x.C(d.rc.setLivraison)} style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);border-radius:8px;height:32px;padding:0 9px;font-family:var(--font-ui);font-size:11.5px;background:var(--color-surface);color:var(--color-text)">
                ${d.rc.livraisons.map(o => `<option value="${esc(o.v)}"${o.v === d.rc.livraison ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></div>
            <div><div style="font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:4px">Motif</div>
              <select ${x.C(d.rc.setMotif)} style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);border-radius:8px;height:32px;padding:0 9px;font-family:var(--font-ui);font-size:11.5px;background:var(--color-surface);color:var(--color-text)">
                ${d.rc.motifs.map(o => `<option value="${esc(o.v)}"${o.v === d.rc.motif ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></div>
          </div>
          <div style="display:flex;gap:9px;align-items:flex-end;margin-top:9px;flex-wrap:wrap">
            <div style="width:120px"><div style="font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:4px">Quantité</div>
              <input type="number" min="0" step="1" value="${esc(d.rc.quantite)}" ${x.I(d.rc.setQuantite)} style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);border-radius:8px;height:32px;padding:0 9px;font-family:var(--font-ui);font-size:12px;background:var(--color-surface);color:var(--color-text)"></div>
            <span style="display:inline-flex;background:var(--color-background-secondary);border-radius:9px;padding:3px;gap:2px">
              ${d.rc.actions.map(a2 => `<button ${x.A(a2.choisir)} style="border:none;cursor:pointer;font-family:var(--font-ui);font-size:11.5px;font-weight:${a2.on ? '600' : '400'};padding:5px 11px;border-radius:7px;background:${a2.on ? 'var(--color-surface)' : 'transparent'};color:${a2.on ? 'var(--color-primary)' : 'var(--color-text-muted)'}">${esc(a2.nom)}</button>`).join('')}
            </span>
            ${d.rc.valeur ? `<span style="font-size:11.5px;color:var(--color-text-muted)">valeur réclamée : <b style="color:var(--color-text)">${esc(d.rc.valeur)}</b></span>` : ''}
          </div>
          <textarea ${x.I(d.rc.setTexte)} rows="3" placeholder="Ce que la boutique constate" style="width:100%;box-sizing:border-box;margin-top:9px;border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:8px 10px;font-family:var(--font-ui);font-size:12px;line-height:1.5;background:var(--color-surface);color:var(--color-text);resize:vertical">${esc(d.rc.texte)}</textarea>
          ${d.rc.err ? `<div style="margin-top:8px;padding:8px 11px;border-radius:8px;background:rgba(141,29,44,0.08);color:#8D1D2C;font-size:11.5px">${esc(d.rc.err)}</div>` : ''}
          <div style="display:flex;align-items:center;gap:9px;margin-top:10px;flex-wrap:wrap">
            <span style="font-size:10.5px;color:var(--color-text-muted);flex:1;min-width:220px;line-height:1.45">${esc(d.rc.note)}</span>
            <button ${x.A(d.rc.envoyer)} style="border:none;background:${d.rc.peut ? 'var(--color-primary)' : '#c9beb4'};color:#fff;border-radius:999px;height:31px;padding:0 16px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:${d.rc.busy ? 'wait' : 'pointer'}">${d.rc.busy ? 'Envoi…' : 'Déposer la réclamation'}</button>
          </div>
          <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:7px;line-height:1.45">La réclamation part chez le fournisseur et apparaît dans Suivi fournisseurs. Les repères posés sur la photo sont recopiés dans le texte — la pièce jointe, elle, attend l'identifiant que l'API ne rend pas encore.</div>
          `)}` : ''}
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
/**
 * Une liste de choix qui s'édite : on tape pour filtrer, on ajoute ce qui
 * manque, la croix retire une position.
 *
 * Le panneau s'ouvre DANS la ligne et non par-dessus : le tableau défile
 * horizontalement, et un panneau flottant y serait coupé net.
 */
function plCbx(cb, x){
  const { esc } = x;
  if (!cb) { return ''; }
  const champ = 'display:flex;align-items:center;gap:5px;border:0.5px solid ' + (cb.ouvert ? 'var(--color-primary)' : 'var(--color-border-secondary)')
    + ';background:var(--color-surface);border-radius:6px;padding:4px 7px;font-size:11.5px;cursor:pointer;min-width:112px';
  return `<div ${x.A(e => e.stopPropagation())}>
    <div ${x.A(cb.ouvrir)} style="${champ}">
      <span style="flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;${cb.vide ? 'color:var(--color-text-muted)' : 'font-weight:500'}">${esc(cb.val)}</span>
      <span style="font-size:8px;color:var(--color-text-muted)">▾</span>
    </div>
    ${cb.ouvert ? `<div style="margin-top:5px;border:0.5px solid var(--color-border-tertiary);border-radius:8px;overflow:hidden;background:var(--color-surface);min-width:172px">
      <input id="pl-cbx" value="${esc(cb.q)}" ${x.I(cb.setQ)} placeholder="Filtrer ou écrire…" style="width:100%;box-sizing:border-box;border:none;border-bottom:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text);padding:6px 8px;font-family:var(--font-ui);font-size:11.5px;outline:none">
      ${cb.items.length ? cb.items.map(i => `<div style="display:flex;align-items:center;gap:4px;padding:0 4px 0 0">
        <button ${x.A(i.choisir)} style="flex:1;text-align:left;border:none;background:none;padding:5px 8px;font-family:var(--font-ui);font-size:11.5px;cursor:pointer;color:var(--color-text);${i.on ? 'font-weight:600' : ''}">${esc(i.nom)}</button>
        <button ${x.A(i.supprimer)} title="Retirer de la liste" style="border:none;background:none;color:var(--color-text-muted);font-size:11px;cursor:pointer;padding:0 3px">✕</button>
      </div>`).join('') : `<div style="padding:6px 8px;font-size:10.5px;color:var(--color-text-muted)">Aucune position ne correspond.</div>`}
      ${cb.ajouter ? `<button ${x.A(cb.ajouter)} style="width:100%;text-align:left;border:none;border-top:0.5px solid var(--color-border-tertiary);background:rgba(141,29,44,0.05);color:var(--color-primary);padding:6px 8px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;cursor:pointer">＋ Ajouter « ${esc(cb.ajoutTxt)} » à la liste</button>` : ''}
      ${cb.vider ? `<button ${x.A(cb.vider)} style="width:100%;text-align:left;border:none;border-top:0.5px solid var(--color-border-tertiary);background:none;color:var(--color-text-muted);padding:5px 8px;font-family:var(--font-ui);font-size:10.5px;cursor:pointer">Aucun ${esc(cb.quoi === 'format' ? 'format' : 'contenant')}</button>` : ''}
    </div>` : ''}
  </div>`;
}

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
      ${c.plVue === 'plan' ? `<button ${x.A(c.plPhotosGo)} title="${c.plPhotosOn ? 'Revenir au plan en texte' : 'Montrer les photos, pavées selon la grille'}" style="${btn(c.plPhotosOn)}">Photos</button>` : ''}
      ${c.plVue === 'plan' ? `<span style="width:1px;height:20px;background:var(--color-border-tertiary)"></span>
        ${c.plZonesOpts.map(z => `<button ${x.A(z.go)} style="${btn(z.on)}">${esc(z.nom)}</button>`).join('')}
        <!-- Le comptoir se regarde à une heure donnée : à midi, la vitrine à
             viennoiseries n'est plus montée. Les meubles sans moment déclaré
             sont là toute la journée. -->
        ${(c.plPeriodesOpts || []).length > 1 ? `<span style="width:1px;height:20px;background:var(--color-border-tertiary)"></span>
          ${c.plPeriodesOpts.map(pr => `<button ${x.A(pr.go)} title="${esc(pr.aide)}" style="${btn(pr.on)}">${esc(pr.nom)}</button>`).join('')}` : ''}` : ''}
      <div style="flex:1"></div>
      <span style="font-size:11.5px;color:var(--color-text-muted)">${c.plTot.slots} emplacement(s) · ${c.plTot.libres} libre(s) · ${c.plTot.places} placée(s)</span>
      <button ${x.A(c.plImprimer)} title="Imprimer tout le comptoir, zones comprises — la feuille sort aussi en PDF par le navigateur" style="${btn(false, !!c.plImprimer)}">⎙ Imprimer</button>
      <button ${x.A(c.plExporter)} title="Le comptoir en CSV : quoi, où, combien, à quel moment — pour la centrale et les boutiques" style="${btn(false, !!c.plExporter)}">⇩ Exporter</button>
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
            <button ${x.A(m.assistant)} title="Modifier — type, température, présentation, moments" style="border:none;background:none;color:var(--color-text-muted);font-size:12px;cursor:pointer;padding:0 2px">✎</button>
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
          <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:1080px">
            <thead><tr>
              <th style="${TH}"><button ${x.A(c.plCols[0].go)} style="border:none;background:none;padding:0;cursor:pointer;font:inherit;color:inherit;text-transform:uppercase;letter-spacing:inherit;${c.plCols[0].on ? 'text-decoration:underline;text-underline-offset:3px' : ''}">Emplacement</button></th>
              <th style="${TH}">Photo</th>
              <th style="${TH}">Format</th>
              <th style="${TH}">Contenant</th>
              <th style="${TH}"><button ${x.A(c.plCols[1].go)} style="border:none;background:none;padding:0;cursor:pointer;font:inherit;color:inherit;text-transform:uppercase;letter-spacing:inherit;${c.plCols[1].on ? 'text-decoration:underline;text-underline-offset:3px' : ''}">Référence</button></th>
              <th style="${TH};text-align:right">Par slot</th>
              <th style="${TH}">Ligne × rangées</th>
              <th style="${TH}"><button ${x.A(c.plCols[2].go)} style="border:none;background:none;padding:0;cursor:pointer;font:inherit;color:inherit;text-transform:uppercase;letter-spacing:inherit;${c.plCols[2].on ? 'text-decoration:underline;text-underline-offset:3px' : ''}">État</button></th>
            </tr></thead>
            <tbody>
              ${c.plRangs.map(r => `<tr ${x.A(r.ouvrir)} ${x.DP(r.deposer)} class="pl-slot" style="${r.trSt}" title="${r.libre ? 'Viser cet emplacement, ou y glisser une référence' : esc(r.nom) + ' — cliquez pour la fiche'}">
                <td style="${TD}"><span style="font-weight:500">${esc(r.meuble)} · ${esc(r.niveau)} · ${r.position}</span><div style="font-size:10.5px;color:var(--color-text-muted)">${esc(r.zone)}</div></td>
                <td style="${TD}">${r.libre ? '<span style="color:var(--color-text-muted)"></span>' : `
                  <div ${x.A(e => e.stopPropagation())} style="display:flex;align-items:center;gap:4px">
                    <label title="${r.photo ? 'Remplacer la photo' : 'Annexer la photo du produit'}" style="flex:0 0 auto;width:34px;height:34px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;${r.photo ? 'border:0.5px solid var(--color-border-secondary)' : 'border:1px dashed var(--color-border-secondary);color:var(--color-text-muted);font-size:14px;line-height:1'}">${r.photo ? `<img src="${esc(r.photo)}" alt="" style="width:100%;height:100%;object-fit:cover;display:block">` : '＋'}<input type="file" accept="image/jpeg,image/png,image/webp" ${x.C(r.photoSet)} style="display:none"></label>
                    ${r.photoDel ? `<button ${x.A(r.photoDel)} title="Retirer la photo" style="border:none;background:none;color:var(--color-text-muted);font-size:11px;cursor:pointer;padding:0 1px">⊗</button>` : ''}
                  </div>`}</td>
                <td style="${TD}">${plCbx(r.format, x)}${r.dims ? `<div style="font-size:10px;color:var(--color-text-muted);margin-top:3px">${esc(r.dims)}</div>` : ''}</td>
                <td style="${TD}">${plCbx(r.contenant, x)}</td>
                <td style="${TD}${r.prendre ? ';cursor:grab' : ''}" ${r.prendre ? 'draggable="true" ' + x.DS(r.prendre) + ' title="Glissez-la sur un autre emplacement"' : ''}>${r.libre ? '<span style="color:var(--color-text-muted)"></span>' : esc(r.nom) + `<div style="font-size:10.5px;color:var(--color-text-muted)">${esc(r.ref)}${r.autresOcc ? ' · +' + r.autresOcc + ' autre(s) moment(s)' : ''}</div>`
                  + ((r.periodesRef || []).length ? `<div ${x.A(e => e.stopPropagation())} style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px">
                    ${r.periodesRef.map(pr => `<button ${x.A(pr.bascule)} title="Présentée ${esc(pr.nom.toLowerCase())} ?" style="border-radius:999px;padding:1px 8px;font-family:var(--font-ui);font-size:9.5px;font-weight:500;cursor:pointer;${pr.on ? 'border:1px solid var(--color-primary);background:rgba(141,29,44,0.08);color:var(--color-primary)' : 'border:0.5px solid var(--color-border-tertiary);background:transparent;color:var(--color-text-muted)'}">${esc(pr.nom)}</button>`).join('')}
                  </div>` : '')}</td>
                <td style="${TD};text-align:right">${r.libre ? '<span style="color:var(--color-text-muted)"></span>'
                  : `<input ${x.A(e => e.stopPropagation())} type="number" min="0" max="400" value="${esc(r.parSlot)}" ${x.I(r.parSlotSet)} ${x.C(r.parSlotEcrire)} placeholder="—" style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);border-radius:6px;height:27px;width:58px;padding:0 6px;font-family:var(--font-ui);font-size:12.5px;font-weight:500;text-align:right">`}</td>
                <td style="${TD}">${r.grille ? `
                  <div style="display:inline-flex;border:0.5px solid var(--color-border-tertiary);border-radius:999px;overflow:hidden">
                    ${r.grille.opts.map(o => `<button ${x.A(o.go)} title="${o.c} par ligne" style="border:none;padding:3px 7px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer;${o.on ? 'background:var(--color-primary);color:#fff' : 'background:transparent;color:var(--color-text-muted)'}">${o.c}</button>`).join('')}
                  </div>
                  <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:4px">${r.grille.txt} = <b style="font-weight:600;color:var(--color-text)">${r.grille.poses}</b> posés${r.grille.taille ? ' · ' + esc(r.grille.taille) : ''}</div>
                  ${r.grille.reste ? `<div style="margin-top:4px;font-size:10px;background:rgba(199,158,44,0.14);color:var(--color-on-abricot);border-radius:5px;padding:2px 6px;display:inline-block">${r.grille.reste} hors grille${r.grille.justeTxt ? ` — <button ${x.A(r.grille.justeGo)} style="border:none;background:none;padding:0;font:inherit;color:inherit;text-decoration:underline;text-underline-offset:2px;cursor:pointer">${esc(r.grille.justeTxt)}</button>` : ''}</div>` : ''}`
                  : `<span style="color:var(--color-text-muted);font-size:11px">${r.libre ? '' : 'nombre à saisir'}</span>`}</td>
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
              <button ${x.A(m.assistant)} title="Modifier ce meuble — type, température, moments — dans l’assistant" style="border:none;background:none;padding:0 0 0 6px;cursor:pointer;font-size:11px;color:var(--color-text-muted)">✎</button>
              ${c.plOrg ? `<button ${x.A(m.supprimer)} title="Supprimer ce meuble" style="border:none;background:none;padding:0 0 0 6px;cursor:pointer;font-size:10.5px;color:var(--color-text-muted)">✕</button>` : ''}
              ${m.periodes ? `<div style="font-size:10px;font-weight:400;color:var(--color-text-muted);text-transform:none;letter-spacing:0;margin-top:2px">${esc(m.periodes)}</div>` : ''}
            </div>`).join('')}
            ${c.plLignes.map(l => `
              <div style="padding-top:14px;text-align:right;font-size:10.5px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.07em;font-weight:500">${esc(l.nom)}</div>
              ${l.cases.map(k => k.absent
                ? `<div style="min-height:50px"></div>`
                : `<div style="display:grid;grid-template-columns:repeat(${Math.max(1, k.slots.length)},1fr);gap:5px${c.plPhotosOn ? ';align-items:start' : ''}">
                    ${k.slots.map(s => (c.plPhotosOn && s.photo && !s.vise)
                      ? `<div ${x.A(s.clic)} draggable="true" ${x.DS(s.prendre)} ${x.DP(s.deposer)} class="pl-slot" title="${esc(s.nom)} — ${s.photoTxt ? s.photoTxt + ' par emplacement · ' : ''}cliquez pour la fiche, glissez pour la déplacer" style="${s.stPhoto}">
                        <div style="aspect-ratio:1;display:grid;grid-template-columns:repeat(${s.photoCols},1fr);grid-template-rows:repeat(${s.photoRangs},1fr);gap:1px;background:var(--color-border-tertiary)">
                          ${Array.from({ length: s.photoN }).map(() => `<img src="${esc(s.photo)}" alt="" style="width:100%;height:100%;object-fit:cover;display:block;background:var(--color-surface)">`).join('')}
                        </div>
                        <div style="padding:5px 7px 6px;font-size:10px;line-height:1.35">
                          <div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(s.nom)}</div>
                          <div style="color:var(--color-text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${s.position}${s.detail ? ' · ' + esc(s.detail) : ''}</div>
                        </div>
                      </div>`
                      : `<div ${x.A(s.clic)} ${s.prendre ? 'draggable="true" ' + x.DS(s.prendre) : ''} ${x.DP(s.deposer)} class="pl-slot" title="${s.libre ? 'Emplacement libre — cliquez pour le viser, ou glissez-y une référence' : esc(s.nom) + ' — cliquez pour la fiche, glissez pour la déplacer'}" style="${s.st}">
                      <span style="overflow:hidden;text-overflow:ellipsis">${s.vise ? 'visé' : (s.libre ? 'libre' : esc(s.nom))}</span>
                      <span style="opacity:0.8">${s.position}${s.detail ? ' · ' + esc(s.detail) : ''}${(c.plPhotosOn && !s.libre && !s.photo && !s.vise) ? ' · sans photo' : ''}</span>
                    </div>`).join('')}
                    ${c.plOrg ? `<button ${x.A(k.ajouter)} title="Ajouter des emplacements" style="border:1px dashed var(--color-border-secondary);background:transparent;color:var(--color-text-muted);border-radius:7px;min-height:50px;cursor:pointer;font-family:var(--font-ui);font-size:14px">+</button>` : ''}
                  </div>`).join('')}`).join('')}
          </div>
        </div>
        <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-top:12px;font-size:10.5px;color:var(--color-text-muted)">
          <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:13px;height:13px;border:1.5px dashed var(--color-primary);border-radius:3px"></span>libre</span>
          <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:13px;height:13px;background:var(--color-background-secondary);border:0.5px solid var(--color-border-tertiary);border-radius:3px"></span>occupé — cliquez pour la fiche</span>
          <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:13px;height:13px;background:var(--color-primary);border-radius:3px"></span>visé</span>
          <span>Glissez une référence du catalogue sur une case ; d’une case à l’autre, elle déménage — sur une case occupée, les deux références échangent.</span>
          ${c.plPhotosOn && c.plPhotosManque ? `<span style="color:var(--color-on-abricot)">${c.plPhotosManque} référence(s) placée(s) sans photo — la case reste en texte ; la photo s’ajoute au tableau ou dans la fiche.</span>` : ''}
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
          <div style="${lbl}">${w.edition ? 'Modifier le meuble' : 'Nouveau meuble'}${w.zone ? ' — ' + esc(w.zone) : ''}</div>
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

        ${w.etape === 3 && w.edition ? `
          <div style="font-size:11.5px;line-height:1.55;color:var(--color-text);background:var(--color-background-secondary);border-radius:8px;padding:9px 12px;margin-bottom:14px">
            Aujourd’hui : <b style="font-weight:500">${esc(w.structTxt || '')}</b>. D’ici on <b style="font-weight:500">ajoute</b> — des niveaux, des emplacements — jamais on ne retire : retirer déplacerait ce qui est posé, et se fait dans « Organiser le comptoir ». Les dimensions saisies ne valent que pour les emplacements <b style="font-weight:500">créés</b> ; celles d’un emplacement existant se changent par son format, au tableau.
          </div>
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
        ${w.creer ? `<button ${x.A(w.creer)} style="border:none;background:var(--color-primary);color:#fff;border-radius:9px;height:33px;padding:0 17px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:${w.busy ? 'wait' : 'pointer'};opacity:${w.busy ? '0.6' : '1'}">${w.busy ? (w.edition ? 'Enregistrement…' : 'Création…') : (w.edition ? 'Enregistrer les modifications' : 'Créer le meuble')}</button>` : ''}
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
          <div style="width:42px;height:42px;border-radius:50%;background:var(--color-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:500">${esc(u.initiales || '')}</div>
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

  // ── Les réclamations matière, en tête de « Suivi fournisseurs ». Ce qui
  //    traîne d'abord : c'est l'ancienneté qui fait agir, pas le décompte.
  const reclamations = () => {
    if (c.reclChargement) { return `<div style="${CARD};margin-bottom:14px;padding:12px 16px;display:flex;align-items:center;gap:12px">
      <span style="font-size:13.5px;font-weight:600">Réclamations matière</span>
      <span style="font-size:12px;color:var(--color-text-muted)">lecture…</span></div>`; }
    if (c.reclIndispo) { return `<div style="${CARD};font-size:12.5px;color:var(--color-text-muted);margin-bottom:14px">${esc(c.reclIndispo)}</div>`; }
    if (!c.reclFourn || !c.reclFourn.length) { return ''; }
    const T = c.reclTotaux || {};
    const chiffre = (n, lib, coul) => `<div style="flex:1;min-width:120px">
      <div style="font-size:10px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted)">${esc(lib)}</div>
      <div style="font-family:var(--font-display);font-size:24px;line-height:1.1;margin-top:3px${coul ? ';color:' + coul : ''}">${n}</div></div>`;
    // REPLIÉ : une ligne. Le reste ne s'affiche que si on le demande.
    if (!c.reclOuvert) {
      return `
      <div ${x.A(c.reclBasculer)} class="hv-bg" style="${CARD};margin-bottom:14px;cursor:pointer;display:flex;align-items:center;gap:12px;padding:12px 16px">
        <span style="font-size:13.5px;font-weight:600">Réclamations matière</span>
        <span style="font-size:12.5px;font-weight:600;color:${c.reclResumeCol}">${esc(c.reclResume)}</span>
        <span style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.reclFenetre || '')}</span>
        <span style="margin-left:auto;font-size:11px;color:var(--color-text-muted)">déplier ▾</span>
      </div>`;
    }

    return `
    <div style="${CARD};margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap">
        <div>
          <div ${x.A(c.reclBasculer)} style="font-family:var(--font-display);font-size:17px;line-height:1.25;cursor:pointer">Réclamations matière <span style="font-size:11px;color:var(--color-text-muted);font-family:var(--font-ui)">replier ▴</span></div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:2px">Ce que les boutiques ont signalé sur les produits — et ce que le fournisseur en a fait.</div>
          <div style="display:flex;align-items:center;gap:9px;margin-top:8px;flex-wrap:wrap">
            <span style="display:inline-flex;background:var(--color-background-secondary);border-radius:9px;padding:3px;gap:2px">
              ${(c.reclPeriodes || []).map(p2 => `<button ${x.A(p2.choisir)} style="border:none;cursor:pointer;font-family:var(--font-ui);font-size:12px;font-weight:${p2.on ? '600' : '400'};padding:5px 12px;border-radius:7px;background:${p2.on ? 'var(--color-surface)' : 'transparent'};color:${p2.on ? 'var(--color-primary)' : 'var(--color-text-muted)'}">${esc(p2.nom)}</button>`).join('')}
            </span>
            ${c.reclEcartees ? `<span style="font-size:11px;color:var(--color-text-muted)">${esc(c.reclEcartees)}</span>` : ''}
          </div>
        </div>
        <div style="display:flex;gap:18px;flex-wrap:wrap">
          ${chiffre(T.ouvertes, 'Sans réponse', 'var(--color-primary)')}
          ${T.montant ? `<div style="flex:1;min-width:150px">
            <div style="font-size:10px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted)">Ce que ça pèse</div>
            <div style="font-family:var(--font-display);font-size:24px;line-height:1.1;margin-top:3px;color:var(--color-primary)">${esc(T.montant)}</div>
            <div style="font-size:10px;color:var(--color-text-muted);margin-top:2px">${esc(T.montantNote)}</div></div>` : ''}
          ${chiffre(T.reglees, 'Réglées', '#2d7a3e')}
          ${chiffre(T.refusees, 'Refusées', '')}
          ${chiffre(T.total, 'Total', '')}
        </div>
      </div>

      <div style="display:flex;flex-direction:column;gap:10px;margin-top:14px">
        ${c.reclFourn.map(f => `
          <div style="background:var(--color-background-secondary);border-radius:10px;padding:12px 14px">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
              <div style="font-size:14px;font-weight:600">${esc(f.nom)}</div>
              <div style="font-size:11.5px;color:var(--color-text-muted)">${f.total} réclamation${f.total > 1 ? 's' : ''} · ${f.ouvertes} sans réponse · la plus ancienne : <b style="color:${f.ancienneCol}">${esc(f.ancienne)}</b> · ${esc(f.delai)}</div>
            </div>
            <div style="display:grid;grid-template-columns:1.25fr 1fr;gap:18px;margin-top:10px">
              <div>
                <div style="font-size:9.5px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:6px">Références qui reviennent</div>
                ${f.refs.map(r2 => `<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                  <span style="flex:0 0 190px;font-size:11.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r2.nom)}</span>
                  <span style="flex:1;height:7px;border-radius:999px;background:var(--color-surface);overflow:hidden"><i style="display:block;height:100%;width:${r2.w}%;background:var(--color-primary);border-radius:999px"></i></span>
                  <span style="flex:0 0 20px;text-align:right;font-size:11.5px;font-weight:600">${r2.n}</span>
                </div>`).join('')}
              </div>
              <div>
                <div style="font-size:9.5px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:6px">Motifs</div>
                ${f.motifs.map(m2 => `<div style="font-size:11.5px;margin-bottom:3px">${esc(m2.nom)} <b>${m2.n}</b></div>`).join('')}
              </div>
            </div>
          </div>`).join('')}
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:14px">
        ${[['Qui réclame — par magasin', c.reclParMagasin], ['Sur quoi — par référence', c.reclParRef]].map(([titre, liste]) => `
          <div>
            <div style="font-size:9.5px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:7px">${esc(titre)}</div>
            ${(liste || []).map(l => `<div style="display:flex;align-items:center;gap:9px;margin-bottom:5px">
              <span style="flex:0 0 200px;font-size:11.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(l.nom)}</span>
              <span style="flex:1;height:7px;border-radius:999px;background:var(--color-background-secondary);overflow:hidden"><i style="display:block;height:100%;width:${l.w}%;background:${l.ouvertes ? 'var(--color-primary)' : '#bdb3a6'};border-radius:999px"></i></span>
              <span style="flex:0 0 92px;text-align:right;font-size:11px;color:var(--color-text-muted)">${l.n} · ${esc(l.qte)} u</span>
              <span style="flex:0 0 78px;text-align:right;font-size:11.5px;font-weight:600">${esc(l.montant)}</span>
            </div>`).join('')}
          </div>`).join('')}
      </div>

      <div style="display:flex;align-items:center;gap:9px;margin:14px 0 8px;flex-wrap:wrap">
        <span style="display:inline-flex;background:var(--color-background-secondary);border-radius:9px;padding:3px;gap:2px">
          ${(c.reclFiltres || []).map(f2 => `<button ${x.A(f2.choisir)} style="border:none;cursor:pointer;font-family:var(--font-ui);font-size:12px;font-weight:${f2.on ? '600' : '400'};padding:5px 12px;border-radius:7px;background:${f2.on ? 'var(--color-surface)' : 'transparent'};color:${f2.on ? 'var(--color-primary)' : 'var(--color-text-muted)'}">${esc(f2.nom)}</button>`).join('')}
        </span>
        <span style="font-size:11px;color:var(--color-text-muted)">${esc(c.reclCompte || '')}</span>
      </div>

      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;min-width:900px">
          <thead><tr>
            <th style="${TH};width:70px">Âge</th><th style="${TH}">Référence</th><th style="${TH};width:80px">Qté</th><th style="${TH};width:90px">Valeur</th>
            <th style="${TH}">Motif</th><th style="${TH}">Ce que dit la boutique</th>
            <th style="${TH};width:130px">Magasin</th><th style="${TH};width:110px">Statut</th>
          </tr></thead>
          <tbody>
            ${(c.reclLignes || []).map(l => `<tr ${x.A(l.ouvrir)} class="hv-bg" style="cursor:pointer${l.horsDix ? ';background:var(--color-background-secondary)' : ''}">
              <td style="${TD};color:${l.ageCol};font-weight:600">${esc(l.age)}</td>
              <td style="${TD}">${esc(l.reference)}${l.pj ? `<div style="font-size:10px;color:var(--color-text-muted)">${esc(l.pj)}</div>` : ''}</td>
              <td style="${TD}">${esc(l.qte)}</td>
              <td style="${TD};font-weight:600">${esc(l.montant)}</td>
              <td style="${TD}">${esc(l.motif)}</td>
              <td style="${TD};color:var(--color-text-muted);max-width:320px">${esc(l.texte)}</td>
              <td style="${TD}">${esc(l.magasin)}</td>
              <td style="${TD}"><span style="font-size:10.5px;font-weight:600;padding:2px 9px;border-radius:999px;${l.statutSt}">${esc(l.statut)}</span></td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
      ${c.reclReste ? `<div style="display:flex;align-items:center;gap:9px;margin-top:10px;flex-wrap:wrap">
        <span style="font-size:11px;color:var(--color-text-muted)">${c.reclReste} autre${c.reclReste > 1 ? 's' : ''} réclamation${c.reclReste > 1 ? 's' : ''} dans cette fenêtre</span>
        <select ${x.C(c.setReclAutre)} style="font-size:12px;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:5px 9px;background:var(--color-surface);color:var(--color-text);font-family:var(--font-ui);max-width:420px">
          ${(c.reclAutres || []).map(o => `<option value="${esc(o.v)}"${o.v === c.reclAutreSel ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}
        </select>
      </div>` : ''}
      <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:9px;line-height:1.5">${esc(c.reclSource || '')}</div>
    </div>
    ${c.reclDet ? `
    <div ${x.A(c.reclDet.fermer)} style="position:fixed;inset:0;background:rgba(20,16,14,0.45);z-index:80"></div>
    <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(620px,92vw);max-height:86vh;overflow-y:auto;background:var(--color-surface);border-radius:16px;z-index:81;box-shadow:0 24px 60px rgba(34,34,34,0.3);padding:20px 22px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
        <div>
          <div style="font-size:15px;font-weight:600">${esc(c.reclDet.titre)}</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">${esc(c.reclDet.sous)}</div>
        </div>
        <button ${x.A(c.reclDet.fermer)} style="border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text-muted);border-radius:999px;width:26px;height:26px;cursor:pointer;flex:none">✕</button>
      </div>
      <div style="margin-top:10px;display:flex;gap:9px;align-items:center;flex-wrap:wrap">
        <span style="font-size:11px;font-weight:600;padding:3px 10px;border-radius:999px;${c.reclDet.statutSt}">${esc(c.reclDet.statut)}</span>
        ${c.reclDet.montant ? `<span style="font-size:11.5px;color:var(--color-text-muted)">valeur réclamée : <b style="color:var(--color-text)">${esc(c.reclDet.montant)}</b></span>` : ''}
      </div>
      <div style="background:var(--color-background-secondary);border-radius:10px;padding:11px 13px;margin-top:12px;font-size:12.5px;line-height:1.55">
        <b>La boutique :</b> ${esc(c.reclDet.texte)}
      </div>
      ${c.reclDet.reponse ? `<div style="background:#E6F2E9;border-radius:10px;padding:11px 13px;margin-top:8px;font-size:12.5px;line-height:1.55">
        <b>Le fournisseur${c.reclDet.reponseLe ? ' — ' + esc(c.reclDet.reponseLe) : ''} :</b> ${esc(c.reclDet.reponse)}</div>`
        : `<div style="font-size:12px;color:var(--color-text-muted);margin-top:8px">Aucune réponse du fournisseur à ce jour.</div>`}
      ${c.reclDet.pjNote ? `<div style="font-size:11.5px;color:var(--color-on-abricot);background:#FBEFE0;border:1px solid #E8C9A0;border-radius:9px;padding:9px 11px;margin-top:10px;line-height:1.5">${esc(c.reclDet.pjNote)}</div>` : ''}
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:12px;line-height:1.5">Lecture seule : répondre, relancer et clore passent par les webhooks du fournisseur, qui refusent le compte consultant.</div>
    </div>` : ''}`;
  };

  if (c.caChargement) {
    // Ce qu'on attend est NOMMÉ : « chargement » seul laisse croire que
    // l'écran est figé. Ici on dit d'où vient la lenteur — le panel est lu
    // magasin par magasin, et c'est lui qui prend le temps.
    return `${periodes}<div style="${CARD};padding:26px 20px">
      <div style="font-size:13px;font-weight:500;margin-bottom:4px">${esc(c.caTitre || 'Lecture en cours')}</div>
      <div style="font-size:11.5px;color:var(--color-text-muted);margin-bottom:12px">${esc(c.caChargeTxt || 'Lecture des commandes chez le panel, magasin par magasin…')}</div>
      <div class="jauge"><i></i></div>
    </div>`;
  }
  const recl = c.isCentrale && c.reclFourn !== undefined ? reclamations() : '';

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
        <div style="font-size:15px;font-variant-numeric:tabular-nums">${esc(String(p[1] == null ? '' : String(p[1]).replace('.', ',')))} ${esc(p[2])}</div></div>`).join('')}
    </div></div>` : '';

  const recherche = (c.caEcran === 'caCatalogue') ? `<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
    <input id="ca-search" type="search" value="${esc(c.caQ || '')}" ${x.I(c.caSetQ)} placeholder="Rechercher une référence, une catégorie…"
      style="font-family:var(--font-ui);font-size:13px;padding:8px 10px;border-radius:9px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);min-width:260px;flex:0 1 320px">
    <span style="font-size:11.5px;color:var(--color-text-muted)">${c.caRows ? c.caRows.length : 0} sur ${c.caTotal || 0} référence${(c.caTotal || 0) > 1 ? 's' : ''}${(c.caRows && c.caRows.length >= 300) ? ' · 300 affichées' : ''}</span>
  </div>` : '';

  // Suivi fournisseurs — le grand tableau : fournisseur ▸ magasin ▸ ses 2
  // dernières commandes, avec l'avancement en quatre segments.
  const SEG = { on: '#2d7a3e', cur: '#c17a2a', ko: '#8D1D2C', '': 'var(--color-border-tertiary)' };
  // « À valider par le magasin » a été RETIRÉ de cet écran : il n'affichait
  // que les 115 réquisitions que l'ERP garde ouvertes sans jamais les
  // refermer — un tableau qui ne demandait aucune action. Le rappel au
  // franchisé reste disponible (POST /centrale/commandes/relance-franchise,
  // gabarit dans Paramètres) : il n'attend qu'un endroit où être posé.

  // Le tableau annuel des achats a été RETIRÉ : il ne pouvait s'établir que
  // par magasin — l'ERP n'enregistre pas le fournisseur d'une réquisition —
  // et ce n'était pas ce qu'on venait chercher sur un écran de commandes.
  // La route (/centrale/fournisseurs/annee) reste, si le besoin revient.

  const suivi = c.caSvGroupes ? `
    ${c.caSvKpis ? `<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px">
      ${c.caSvKpis.map(k => `<div style="${CARD};flex:1;min-width:170px;padding:12px 14px">
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)">${esc(k[0])}</div>
        <div style="font-size:22px;font-weight:500;line-height:1.1;margin-top:3px${k[3] ? ';color:' + k[3] : ''}">${esc(k[1])}</div>
        <div style="font-size:11px;color:var(--color-text-muted);margin-top:2px">${esc(k[2])}</div>
      </div>`).join('')}
    </div>` : ''}
    ${c.caSvChips ? `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      ${c.caSvChips.map(ch => `<button ${x.A(ch.pick)} style="border:1px solid ${ch.on ? 'var(--color-primary)' : 'var(--color-border-secondary)'};cursor:pointer;border-radius:999px;padding:5px 13px;font-family:var(--font-ui);font-size:11.5px;font-weight:600;background:${ch.on ? 'rgba(141,29,44,0.08)' : 'transparent'};color:${ch.on ? 'var(--color-primary)' : 'var(--color-text-muted)'}">${esc(ch.nom)}</button>`).join('')}
      <span style="flex:1"></span>
      ${c.caSvMaj ? `<span style="align-self:center;font-size:11px;color:var(--color-text-muted)">lu à ${esc(c.caSvMaj)}</span>` : ''}
      <button ${x.A(c.caSvRafraichir)} title="Relire les commandes maintenant" style="border:0.5px solid var(--color-border-secondary);cursor:pointer;border-radius:999px;padding:5px 13px;font-family:var(--font-ui);font-size:11.5px;font-weight:500;background:transparent;color:var(--color-text)${c.caSvBusy ? ';opacity:.5' : ''}">${c.caSvBusy ? 'Lecture…' : '↻ Actualiser'}</button>
    </div>` : ''}
    ${!c.caSvGroupes.length ? `<div style="${CARD};font-size:12.5px;color:var(--color-text-muted);margin-bottom:16px">${esc(c.caSvRien || '')}</div>` : `
    <div style="${CARD};margin-bottom:16px">
      <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:1010px">
        <thead><tr>
          <th style="${TH};width:170px">Magasin</th><th style="${TH};width:130px">Commande</th>
          <th style="${TH};width:78px">Passée</th><th style="${TH};width:86px">Livraison</th>
          <th style="${TH};width:240px">Avancement</th><th style="${TH};width:170px">Dernier geste</th>
          <th style="${TH};width:80px">Source</th>
          <th style="${TH};width:52px;text-align:center">Relance</th>
        </tr></thead>
        <tbody>
          ${c.caSvGroupes.map(g => `
            <tr><td colspan="8" style="border-top:0.5px solid var(--color-border-secondary);background:var(--color-background-secondary);padding:9px 12px;font-size:12.5px;font-weight:600">
              ${esc(g.nom)}
              <span style="font-weight:400;font-size:11px;color:var(--color-text-muted)"> · ${esc(g.meta)} · </span>
              <span style="font-weight:600;font-size:11px;color:${g.alerteCol}">${esc(g.alerte)}</span>
              <button ${x.A(g.mailer)} title="Envoyer maintenant le rappel par courrier à ${esc(g.nom)} — il part de lui-même une fois par jour" style="margin-left:9px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted);border-radius:7px;height:22px;padding:0 9px;font-family:var(--font-ui);font-size:10.5px;font-weight:500;cursor:pointer${g.mailEnCours ? ';opacity:.5' : ''}">${g.mailEnCours ? '…' : '✉ rappeler'}</button>
            </td></tr>
            ${g.commandes.map(o => `<tr ${x.A(o.courriers)} title="Voir les courriers envoyés à ${esc(g.nom)}" style="cursor:pointer">
              <td style="${TD};font-weight:${o.magasin ? '500' : '400'}">${esc(o.magasin)}</td>
              <td style="${TD};font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;color:var(--color-text-muted)">${esc(o.cle)}</td>
              <td style="${TD};color:var(--color-text-muted);white-space:nowrap">${esc(o.date)}</td>
              <td style="${TD};white-space:nowrap${o.livraisonCol ? ';color:' + o.livraisonCol + ';font-weight:500' : ';color:var(--color-text-muted)'}">${esc(o.livraison)}</td>
              <td style="${TD}"><span style="display:inline-flex;align-items:center">
                ${o.segs.map(sg => `<i style="display:block;width:22px;height:5px;border-radius:3px;margin-right:3px;background:${SEG[sg]}"></i>`).join('')}
                <span style="font-size:11.5px;font-weight:500;margin-left:6px;white-space:nowrap;color:${o.libelleCol}">${esc(o.libelle)}</span>
                ${o.badge ? `<span style="font-size:10.5px;font-weight:600;padding:1px 7px;border-radius:999px;background:rgba(141,29,44,0.10);color:var(--color-primary);margin-left:6px;white-space:nowrap">${esc(o.badge)}</span>` : ''}
              </span></td>
              <td style="${TD};font-size:11.5px;color:var(--color-text-muted)">${esc(o.geste)}</td>
              <td style="${TD};font-size:11.5px;color:var(--color-text-muted)">${esc(o.source)}</td>
              <td ${x.A(e => e.stopPropagation())} style="${TD};text-align:center">
                ${o.relancable ? `<button ${x.A(o.relancer)} title="${esc(o.relanceTitre)}" style="border:0.5px solid ${o.relanceLe ? 'var(--color-border-tertiary)' : 'var(--color-primary)'};background:${o.relanceLe ? 'transparent' : 'rgba(141,29,44,0.06)'};color:${o.relanceLe ? 'var(--color-text-muted)' : 'var(--color-primary)'};border-radius:8px;width:28px;height:26px;cursor:pointer;font-size:13px;line-height:1;padding:0${o.relanceEnCours ? ';opacity:.5' : ''}">${o.relanceEnCours ? '…' : '🔔'}</button>
                  ${o.relanceLe ? `<div style="font-size:9.5px;color:var(--color-text-muted);margin-top:2px;white-space:nowrap">${esc(o.relanceLe.slice(5, 10))}</div>` : ''}`
                  : `<span title="${esc(o.relanceTitre)}" style="color:var(--color-border-secondary)"></span>`}
              </td>
            </tr>`).join('')}`).join('')}
        </tbody>
      </table></div>
      ${c.caSvMails ? `
      <div ${x.A(c.caSvMails.fermer)} style="position:fixed;inset:0;background:rgba(20,16,14,0.5);z-index:90"></div>
      <div style="position:fixed;inset:0;z-index:91;display:flex;align-items:center;justify-content:center;padding:22px;pointer-events:none">
        <div style="pointer-events:auto;background:var(--color-surface);border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,0.3);width:680px;max-width:100%;max-height:100%;display:flex;flex-direction:column;overflow:hidden">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:15px 19px;border-bottom:0.5px solid var(--color-border-tertiary)">
            <div>
              <div style="font-size:10.5px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-text-muted)">Courriers envoyés · ${esc(c.caSvMails.commande)}</div>
              <div style="font-size:16px;font-weight:500;margin-top:3px">${esc(c.caSvMails.fournisseur)}</div>
              <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">${esc(c.caSvMails.resume)}</div>
            </div>
            <button ${x.A(c.caSvMails.fermer)} style="border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted);border-radius:999px;width:28px;height:28px;font-size:14px;cursor:pointer;flex:0 0 auto">✕</button>
          </div>
          <div style="padding:14px 19px;overflow-y:auto" data-scroll="svmails">
            ${c.caSvMails.chargement ? `<div style="font-size:12.5px;color:var(--color-text-muted)">Lecture du journal…</div>`
              : (c.caSvMails.vide ? `<div style="font-size:12.5px;color:var(--color-text-muted);line-height:1.55">Aucun courrier envoyé à ce fournisseur pour l’instant. Le rappel part au passage horaire suivant si une commande reste en attente.</div>`
              : c.caSvMails.lignes.map(l => `
                <div style="display:flex;gap:11px;align-items:flex-start;padding:9px 0;border-bottom:0.5px solid var(--color-border-tertiary)">
                  <span style="width:9px;height:9px;border-radius:50%;margin-top:5px;flex:0 0 auto;background:${l.echec ? 'var(--color-primary)' : (l.clos ? 'var(--color-border-secondary)' : '#2d7a3e')}"></span>
                  <div style="flex:1;min-width:0">
                    <div style="font-size:12.5px;font-weight:500">${esc(l.sujet)}</div>
                    <div style="font-size:11px;color:var(--color-text-muted);margin-top:2px">${esc(l.quand)} · ${esc(l.vers)}${l.copie ? ' · copie ' + esc(l.copie) : ''}</div>
                    ${l.reqs ? `<div style="font-size:11px;color:var(--color-text-muted);margin-top:2px">${esc(l.reqs)}</div>` : ''}
                    ${l.echec ? `<div style="font-size:11px;color:var(--color-primary);margin-top:2px">${esc(l.detail)}</div>` : ''}
                  </div>
                </div>`).join(''))}
            ${c.caSvMails.note ? `<div style="font-size:10.5px;color:var(--color-text-muted);margin-top:12px;line-height:1.5">${esc(c.caSvMails.note)}</div>` : ''}
          </div>
        </div>
      </div>` : ''}
      <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:11px;color:var(--color-text-muted);margin-top:12px">
        <span>étapes : envoyée → acceptée → en transit → livrée</span>
        <span style="display:inline-flex;align-items:center;gap:6px"><i style="display:block;width:22px;height:5px;border-radius:3px;background:#2d7a3e"></i> franchi</span>
        <span style="display:inline-flex;align-items:center;gap:6px"><i style="display:block;width:22px;height:5px;border-radius:3px;background:#c17a2a"></i> en cours</span>
        <span style="display:inline-flex;align-items:center;gap:6px"><i style="display:block;width:22px;height:5px;border-radius:3px;background:#8D1D2C"></i> bloqué / en retard</span>
      </div>
    </div>`}` : '';

  // Commandes franchisés : une carte par fournisseur, ses 5 dernières
  // commandes. Les chips de statut vivent au-dessus de la grille.
  const groupes = c.caFournGroupes ? `
    ${c.caChips && c.caChips.length ? `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
      ${c.caChips.map(ch => `<button ${x.A(ch.pick)} style="border:1px solid ${ch.on ? ch.texte : 'var(--color-border-secondary)'};cursor:pointer;border-radius:999px;padding:5px 13px;font-family:var(--font-ui);font-size:11.5px;font-weight:600;background:${ch.on ? ch.fond : 'transparent'};color:${ch.texte}">${esc(ch.nom)}</button>`).join('')}
      ${c.caChips.some(ch => ch.on) ? `<span style="align-self:center;font-size:11px;color:var(--color-text-muted)">re-cliquer pour tout afficher</span>` : ''}
    </div>` : ''}
    ${!c.caFournGroupes.length ? `<div style="${CARD};font-size:12.5px;color:var(--color-text-muted)">${esc(c.caRien || 'aucune ligne')}</div>` : `
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(460px,1fr));gap:14px">
      ${c.caFournGroupes.map(g => `<div style="${CARD};display:flex;flex-direction:column">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:10px">
          <div>
            <div style="font-size:13.5px;font-weight:600${g.special ? ';color:var(--color-text-muted)' : ''}">${esc(g.nom)}</div>
            <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:2px">${esc(g.note)}</div>
          </div>
          <span style="font-size:11px;font-weight:600;padding:3px 10px;border-radius:999px;white-space:nowrap;background:${g.resumeFond};color:${g.resumeCol}">${esc(g.resume)}</span>
        </div>
        <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">
          <thead><tr>
            <th style="${TH}">Réquisition</th><th style="${TH}">Magasin</th><th style="${TH}">Début</th>
            <th style="${TH}">Statut</th><th style="${TH};text-align:right">Valeur</th><th style="${TH}">Par</th>
          </tr></thead>
          <tbody>${g.commandes.map(o => `<tr>
            <td style="${TD};font-variant-numeric:tabular-nums;color:var(--color-text-muted)">${esc(o.id)}</td>
            <td style="${TD}">${esc(o.magasin)}</td>
            <td style="${TD};color:var(--color-text-muted);white-space:nowrap">${esc(o.debut)}</td>
            <td style="${TD};white-space:nowrap"><span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:${o.col};margin-right:6px;vertical-align:1px"></span><span style="color:${o.col};font-weight:500;font-size:12px">${esc(o.statut)}</span></td>
            <td style="${TD};text-align:right;font-variant-numeric:tabular-nums">${esc(o.valeur)}</td>
            <td style="${TD};color:var(--color-text-muted)">${esc(o.par)}</td>
          </tr>`).join('')}</tbody>
        </table></div>
      </div>`).join('')}
    </div>`}` : '';

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

  return periodes + recl + suivi + kpis + manquants + params + recherche + groupes + table + table2;
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
/**
 * Usage du catalogue : ce que chaque magasin vend des références du réseau.
 *
 * Un tableau magasins × mois, et deux dépliants imbriqués — le magasin, puis
 * la catégorie. Le détail n'est demandé au serveur qu'à l'ouverture : sept
 * cents lignes par magasin n'ont rien à faire dans l'écran d'entrée.
 */
/**
 * Manque à gagner — ce que l'assortiment absent coûte, en euros.
 *
 * Une seule mesure, trois façons de la lire : le tableau magasins × mois, le
 * dépliant qui l'ouvre référence par référence, et le classement réseau des
 * références. Le basculement se fait sur les mêmes données déjà chargées —
 * rien n'est relu, et les trois lectures ne peuvent donc pas diverger.
 */
/**
 * Analyse magasin — un wizard en quatre étapes.
 *
 * Le wizard n'est pas un formulaire : rien ne s'y saisit. C'est un ORDRE DE
 * LECTURE — la synthèse, puis chaque levier, puis le plan — pour qu'un
 * entretien franchisé suive toujours le même fil. Les étapes restent
 * cliquables directement : on revient sur un chiffre sans refaire le tour.
 */
/**
 * Target de vente & classement — le personnel, au CA par heure prestée.
 *
 * Le podium des primes d'abord, puis le tableau ; la fiche d'une personne
 * s'ouvre en dépliant sous sa ligne. Les non-classables (sous le seuil
 * d'heures, ou sans vente à leur nom) restent VISIBLES, grisés, avec leur
 * motif : les sortir du tableau ferait croire qu'ils n'ont pas travaillé.
 */
/**
 * Croisements — l'attache entre deux familles, au choix.
 *
 * L'ordre A × B est écrit en toutes lettres au-dessus des sélecteurs : le
 * croisement est asymétrique, et un écran qui laisse deviner l'ordre produit
 * des conclusions inversées.
 */
function tplCrois(c, x){
  const { esc } = x;
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const lbl = 'font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--color-text-muted);font-weight:500';
  const th = 'text-align:right;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:10px;border-bottom:0.5px solid var(--color-border-tertiary)';
  const td = 'padding:9px 10px;border-bottom:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums';
  const pill = f => `border:0.5px solid ${f ? 'var(--color-primary)' : 'var(--color-border-tertiary)'};background:${f ? 'var(--color-primary)' : 'var(--color-surface)'};color:${f ? '#fff' : 'var(--color-text-muted)'};border-radius:999px;padding:5px 13px;font-family:var(--font-ui);font-size:11.5px;cursor:pointer`;
  const SEL = 'font-family:var(--font-ui);font-size:12.5px;padding:7px 10px;border-radius:9px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);max-width:280px';
  const spark = vals => {
    const max = Math.max(1, ...vals.filter(v => v != null));
    return `<span style="display:inline-flex;align-items:flex-end;gap:2px;height:20px;vertical-align:middle">${vals.map((v, i) =>
      `<i style="width:6px;border-radius:2px 2px 0 0;height:${v == null ? 2 : Math.max(2, Math.round(18 * v / max))}px;background:${i === vals.length - 1 ? 'var(--color-primary)' : '#D6CBBA'}"></i>`).join('')}</span>`;
  };
  const selecteur = (titre, f, avecTous) => `
    <div>
      <span style="${lbl}">${esc(titre)}</span><br>
      <select ${x.C(f.choisir)} style="${SEL};margin-top:6px">
        <option value="">— choisir —</option>
        ${avecTous ? `<option value="t:tous" ${f.val === 't:tous' ? 'selected' : ''}>★ Tous les tickets — tout produit</option>` : ''}
        <optgroup label="Groupes">${f.groupes.map(o2 => `<option value="${esc(o2.v)}" ${f.val === o2.v ? 'selected' : ''}>${esc(o2.nom)}</option>`).join('')}</optgroup>
        <optgroup label="Catégories">${f.categories.map(o2 => `<option value="${esc(o2.v)}" ${f.val === o2.v ? 'selected' : ''}>${esc(o2.nom)}</option>`).join('')}</optgroup>
        <optgroup label="Produits">${f.produits.map(o2 => `<option value="${esc(o2.v)}" ${f.val === o2.v ? 'selected' : ''}>${esc(o2.nom)}</option>`).join('')}</optgroup>
      </select>
    </div>`;

  if (c.crChargementOpt) {
    return `<div data-screen="croisements"><div style="${carte};padding:20px 22px;font-size:12.5px;color:var(--color-text-muted)">Lecture du catalogue…</div></div>`;
  }
  if (c.crOptIndispo) {
    return `<div data-screen="croisements"><div style="${carte};padding:20px 22px;font-size:12.5px">Le catalogue n’a pas pu être lu.</div></div>`;
  }

  const detail = (dt, feuille) => !dt ? '' : (dt.feuille = feuille, '') || `
    <tr><td colspan="${c.crEntetes.length + (c.crTarget != null ? 6 : 5)}" style="padding:0;background:#FBF8F4;border-top:0.5px solid var(--color-border-tertiary)">
      <div style="padding:14px 18px 16px">
        ${dt.chargement ? `<div style="font-size:12px;color:var(--color-text-muted)">Lecture des tickets du magasin…</div>`
        : dt.err ? `<div style="font-size:12px;color:#8D1D2C">${esc(dt.err)}</div>` : `
        <div style="display:flex;align-items:baseline;gap:12px;margin-bottom:8px">
          <span style="${lbl}">Vendeuse par vendeuse — ${esc(c.crMoisDetail)}</span>
          <span style="flex:1"></span>
          ${dt.feuille ? `<a href="${esc(dt.feuille)}" target="_blank" rel="noreferrer" style="font-size:11px;font-weight:500;color:var(--color-primary);text-decoration:none;border:0.5px solid var(--color-primary);border-radius:999px;padding:4px 11px">↓ Feuille de ce magasin</a>` : ''}
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          <thead><tr><th style="${th};text-align:left">Vendeur·se</th><th style="${th}">Tickets A</th>
            <th style="${th}">Avec B</th><th style="${th}">Taux</th><th style="${th}">Manqués</th><th style="${th}">À la clé / mois</th></tr></thead>
          <tbody>${dt.lignes.map(l => `<tr>
            <td style="${td};text-align:left;font-weight:500">${esc(l.nom)}</td>
            <td style="${td}">${l.ff}</td><td style="${td}">${l.avec}</td>
            <td style="${td};font-weight:600;color:${l.col}">${esc(l.taux)}</td>
            <td style="${td}">${l.manques}</td>
            <td style="${td};font-weight:600;color:var(--color-primary)">${esc(l.eur)}</td>
          </tr>`).join('')}</tbody>
        </table>
        ${dt.petits ? `<div style="font-size:11px;color:var(--color-text-muted);padding-top:8px">${esc(dt.petits)}</div>` : ''}
        ${dt.sans ? `<div style="font-size:11px;color:var(--color-text-muted);padding-top:3px">${esc(dt.sans)}</div>` : ''}`}
      </div>
    </td></tr>`;

  return `
  <div data-screen="croisements" style="display:flex;flex-direction:column;gap:14px;max-width:1380px">
    <div style="${carte};padding:16px 18px">
      <div style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap">
        ${selecteur('A — sur les tickets contenant…', c.crSelA, true)}
        <span style="font-family:var(--font-display);font-size:18px;color:var(--color-text-muted);padding-bottom:8px">×</span>
        ${selecteur('B — combien contiennent aussi…', c.crSelB, false)}
        <span style="flex:1"></span>
        ${c.crDurees.map(t => `<button ${x.A(t.choisir)} style="${pill(t.on)}">${esc(t.nom)}</button>`).join('')}
        <span style="width:10px"></span>
        ${(c.crDayparts || []).map(t => `<button ${x.A(t.choisir)} style="${pill(t.on)}">${esc(t.nom)}</button>`).join('')}
        ${!c.crTargetChamp ? '' : `
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--color-text-muted)" title="${c.crTargetChamp.enregistre ? 'La target du combo — écrite dès la sortie du champ' : 'La target partira avec l’enregistrement du combo'}">
          🎯 Target
          <input type="number" min="1" max="100" step="0.5" value="${esc(c.crTargetChamp.val)}" ${x.C(c.crTargetChamp.poser)}
            placeholder="—" style="width:64px;font-family:var(--font-ui);font-size:12.5px;padding:6px 8px;border-radius:8px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);text-align:right"> %
        </label>`}
        ${c.crEnregistrer && !c.crDejaEnregistre ? `<button ${x.A(c.crEnregistrer)} style="${pill(true)}">💾 Enregistrer ce combo</button>`
          : c.crDejaEnregistre ? `<span style="font-size:11.5px;color:#2d7a3e;font-weight:600">✓ combo enregistré — sa puce est ci-dessous</span>` : ''}
      </div>
      ${!c.crCombos.length ? '' : `
      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <span style="${lbl};margin-right:2px">Combos enregistrés</span>
        ${!c.crRapportHref ? '' : `<a href="${esc(c.crRapportHref)}" target="_blank" rel="noreferrer" style="font-size:11px;font-weight:500;color:var(--color-primary);text-decoration:none;border:0.5px solid var(--color-primary);border-radius:999px;padding:4px 11px;order:99;margin-left:auto">↓ Rapport de tous les combos — réseau + par magasin</a>`}
        ${c.crCombos.map(cb => `
        <span style="display:inline-flex;align-items:center;gap:7px;background:${cb.on ? 'rgba(141,29,44,.06)' : '#FBF8F4'};border:0.5px solid ${cb.on ? 'var(--color-primary)' : 'var(--color-border-secondary)'};border-radius:999px;padding:5px 6px 5px 13px;font-size:12px;font-weight:500;${cb.on ? 'color:var(--color-primary)' : ''}">
          <span ${x.A(cb.choisir)} style="cursor:pointer">${esc(cb.nom)}${cb.surnom ? ` <i style="font-style:normal;color:var(--color-text-muted);font-weight:400;font-size:10.5px">${esc(cb.surnom)}</i>` : ''}</span>
          <span ${x.A(cb.cibler)} title="Poser ou changer la target d’attache" style="cursor:pointer;font-size:10.5px;color:${cb.target ? '#8a5a1c' : 'var(--color-text-muted)'}">🎯${cb.target ? ' ' + esc(cb.target) : ''}</span>
          <button ${x.A(cb.retirer)} title="Retirer ce combo — l’historique en cache est gardé" style="border:none;background:none;color:var(--color-text-muted);cursor:pointer;font-size:11px;padding:0 4px">✕</button>
        </span>`).join('')}
      </div>`}
    </div>

    ${c.crRien ? `<div style="${carte};padding:20px 22px;font-size:12.5px;color:var(--color-text-muted)">Choisissez une famille A et une famille B — ou cliquez un combo enregistré.</div>`
    : c.crChargement ? `<div style="${carte};padding:20px 22px;font-size:12.5px;color:var(--color-text-muted)">Lecture des tickets — les mois déjà en cache ne se relisent pas…</div>`
    : c.crMotif ? `<div style="${carte};padding:20px 22px;font-size:12.5px">${esc(c.crMotif)}</div>` : `
    <div style="${carte}">
      <div style="padding:16px 18px 0;display:flex;gap:14px;align-items:baseline;flex-wrap:wrap">
        <div>
          <div style="${lbl}">${esc(c.crTitre)} — taux d’attache, mois par mois</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">${esc(c.crSousTitre)}</div>
        </div>
        <span style="flex:1"></span>
        <a href="${esc(c.crPdfHref)}" target="_blank" rel="noreferrer" style="font-size:11.5px;font-weight:500;color:var(--color-primary);text-decoration:none;border:0.5px solid var(--color-primary);border-radius:999px;padding:5px 13px">↓ Feuille PDF</a>
      </div>
      <div style="overflow-x:auto;padding-top:8px"><table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:900px">
        <thead><tr>
          <th style="${th};text-align:left;padding-left:18px">Périmètre</th>
          ${c.crEntetes.map(m => `<th style="${th}">${esc(m)}</th>`).join('')}
          <th style="${th};text-align:left;padding-left:20px">Tendance</th>
          ${c.crTarget != null ? `<th style="${th}" title="écart au target du dernier mois complet">Δ target</th>` : ''}
          <th style="${th}">Tickets A</th><th style="${th};padding-right:18px">Laissé au comptoir</th>
        </tr></thead>
        <tbody>
          <tr style="background:#FBF8F4;font-weight:600">
            <td style="${td};text-align:left;padding-left:18px">RÉSEAU</td>
            ${c.crReseau.cases.map(x2 => `<td style="${td};${x2.st}">${esc(x2.v)}</td>`).join('')}
            <td style="${td};text-align:left;padding-left:20px">${spark(c.crReseau.spark)}</td>
            ${c.crTarget != null ? `<td style="${td};font-weight:600;color:${c.crReseau.delta ? c.crReseau.delta.col : 'var(--color-text-muted)'}">${c.crReseau.delta ? esc(c.crReseau.delta.txt) : ''}</td>` : ''}
            <td style="${td}">${esc(c.crReseau.ff)}</td>
            <td style="${td};padding-right:18px;color:var(--color-primary)">${esc(c.crReseau.eur)}</td>
          </tr>
          ${c.crLignes.map(l => `
          <tr ${x.A(l.basculer)} style="cursor:pointer${l.ouvert ? ';background:#FBF8F4' : ''}">
            <td style="${td};text-align:left;padding-left:18px;font-weight:500"><span style="color:var(--color-text-muted);font-size:11px">${l.ouvert ? '▾' : '▸'}</span> ${esc(l.nom)}</td>
            ${l.cases.map(x2 => `<td style="${td};${x2.st}">${esc(x2.v)}</td>`).join('')}
            <td style="${td};text-align:left;padding-left:20px">${spark(l.spark)}</td>
            ${c.crTarget != null ? `<td style="${td};font-weight:600;color:${l.delta ? l.delta.col : 'var(--color-text-muted)'}">${l.delta ? esc(l.delta.txt) : ''}</td>` : ''}
            <td style="${td}">${esc(l.ff)}</td>
            <td style="${td};padding-right:18px;font-weight:600;color:${l.col}">${esc(l.eur)}</td>
          </tr>
          ${l.ouvert ? detail(c.crDetail, l.feuille) : ''}`).join('')}
        </tbody>
      </table></div>
      <div style="font-size:11px;color:var(--color-text-muted);padding:12px 18px 16px;line-height:1.55">
        Le croisement est <b>asymétrique</b>, et c’est voulu : « tickets A avec B » n’est pas « tickets B avec A ».
        Le mois marqué * est en cours : il se recalcule à chaque lecture, les mois révolus sont en cache.
        « Tickets A » et « laissé au comptoir » portent sur le dernier mois complet — manqués × prix moyen de B réellement encaissé, un plafond de geste, pas une promesse.
        Les euros ne s’additionnent pas d’un combo à l’autre : un même ticket peut manquer deux combos.
        Vert ≥ 25 % · ambre 15-25 % · bordeaux &lt; 15 %.
      </div>
    </div>`}
  </div>`;
}

function tplVentes(c, x){
  const { esc } = x;
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const lbl = 'font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--color-text-muted);font-weight:500';
  const th = 'text-align:right;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:10px;border-bottom:0.5px solid var(--color-border-tertiary)';
  const td = 'padding:9px 10px;border-bottom:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums';
  const pill = f => `border:0.5px solid ${f ? 'var(--color-primary)' : 'var(--color-border-tertiary)'};background:${f ? 'var(--color-primary)' : 'var(--color-surface)'};color:${f ? '#fff' : 'var(--color-text-muted)'};border-radius:999px;padding:5px 13px;font-family:var(--font-ui);font-size:11.5px;cursor:pointer`;
  const primeOr = `background:#FFF7E0;border:0.5px solid #E8C9A0;color:#8a5a1c;border-radius:999px;padding:2px 10px;font-size:10.5px;font-weight:600;white-space:nowrap`;
  const primeR = `background:var(--color-primary);color:#fff;border-radius:999px;padding:2px 10px;font-size:10.5px;font-weight:600;white-space:nowrap`;
  const avatar = ini => `<span style="display:inline-flex;width:24px;height:24px;border-radius:50%;background:#EFE3D5;color:#8a5a1c;align-items:center;justify-content:center;font-size:10px;font-weight:700;margin-right:8px;vertical-align:middle">${esc(ini)}</span>`;

  const filtres = `
      <div style="display:flex;gap:6px;flex-wrap:wrap;padding:12px 18px 14px;align-items:center">
        ${c.tvMoisPills.map(m => `<button ${x.A(m.choisir)} style="${pill(m.on)}">${esc(m.nom)}</button>`).join('')}
        <span style="width:12px"></span>
        ${(c.tvShops || []).map(m => `<button ${x.A(m.choisir)} style="${pill(m.on)}">${esc(m.nom)}</button>`).join('')}
      </div>`;

  if (c.tvChargement) {
    return `<div data-screen="ventes"><div style="${carte};padding:20px 22px;font-size:12.5px;color:var(--color-text-muted)">Lecture des tickets et du planning…</div></div>`;
  }
  if (c.tvMotif) {
    return `<div data-screen="ventes"><div style="${carte}">${filtres}<div style="padding:0 22px 20px;font-size:12.5px">${esc(c.tvMotif)}</div></div></div>`;
  }

  const fiche = f => !f ? '' : `
    <tr><td colspan="13" style="padding:0;background:#FBF8F4;border-top:0.5px solid var(--color-border-tertiary)">
      <div style="padding:14px 18px 16px">
        ${f.chargement ? `<div style="font-size:12px;color:var(--color-text-muted)">Lecture de la fiche…</div>`
        : f.err ? `<div style="font-size:12px;color:#8D1D2C">${esc(f.err)}</div>` : `
        <div style="${lbl};margin-bottom:8px">${esc(f.nom)} — ${esc(f.magasin)} · six mois</div>
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          <thead><tr><th style="${th};text-align:left">Mois</th><th style="${th}">Heures</th><th style="${th}">CA</th>
            <th style="${th}">CA / heure</th><th style="${th}">Score</th><th style="${th}">Rang réseau</th><th style="${th}">Panier</th>
            <th style="${th}">Lignes / ticket</th><th style="${th};text-align:left;padding-left:16px">Prime</th></tr></thead>
          <tbody>${f.mois.map(m => `<tr>
            <td style="${td};text-align:left;font-weight:500">${esc(m.lib)}</td>
            <td style="${td}">${esc(m.heures)}</td><td style="${td}">${esc(m.ca)}</td>
            <td style="${td}">${esc(m.caHeure)}</td>
            <td style="${td};font-weight:600;color:var(--color-primary)">${esc(m.score)}</td>
            <td style="${td};font-weight:600">${esc(m.rang)}</td>
            <td style="${td}">${esc(m.panier)}</td><td style="${td}">${esc(m.lignesTicket)}</td>
            <td style="${td};text-align:left;padding-left:16px">${m.prime ? `<span style="${primeOr}">${esc(m.prime)}</span>` : ''}</td>
          </tr>`).join('')}</tbody>
        </table>`}
      </div>
    </td></tr>`;

  const onglets = `
    <div style="display:flex;gap:6px;align-items:center">
      ${(c.tvOnglets || []).map(o2 => `<button ${x.A(o2.choisir)} style="${pill(o2.on)}">${esc(o2.nom)}</button>`).join('')}
    </div>`;

  if (c.tvOnglet === 'targets') {
    const SIM = c.cxSim;
    const thS = 'font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted);padding:7px 9px;border-bottom:0.5px solid var(--color-border-secondary);text-align:right';
    const tdS = 'padding:8px 9px;border-bottom:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums';
    return `
  <div data-screen="ventes" style="display:flex;flex-direction:column;gap:14px;max-width:1380px">
    <div style="display:flex;gap:6px;align-items:center">${(c.tvOnglets || []).map(o2 => `<button ${x.A(o2.choisir)} style="${pill(o2.on)}">${esc(o2.nom)}</button>`).join('')}
      <span style="flex:1"></span>
      <a href="${esc(c.cxPdfEquipe || '')}" target="_blank" style="${pill(false)};text-decoration:none">📄 PDF équipes — le mode d'emploi</a>
    </div>

    <div style="${carte};padding:16px 18px">
      <div style="display:flex;gap:22px;align-items:center;flex-wrap:wrap">
        <div style="flex:1;min-width:320px">
          <div style="${lbl}">1 · Primes au score — la meilleure vendeuse</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">Score = CA ÷ (heures + 20) × coefficient de créneau. Chaque mois révolu — la meilleure du réseau ne cumule pas la prime magasin.</div>
        </div>
        <label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px">🏆 Réseau
          <input type="number" min="1" step="5" value="${esc(c.tvPrimesCfg.reseau)}" ${x.C(c.tvPrimesCfg.poser('reseau'))} style="width:64px;font-family:var(--font-ui);font-size:12.5px;padding:6px 8px;border-radius:8px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);text-align:right"> €</label>
        <label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px">🥇 Magasin
          <input type="number" min="1" step="5" value="${esc(c.tvPrimesCfg.magasin)}" ${x.C(c.tvPrimesCfg.poser('magasin'))} style="width:64px;font-family:var(--font-ui);font-size:12.5px;padding:6px 8px;border-radius:8px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);text-align:right"> €</label>
      </div>
    </div>

    ${!SIM ? `<div style="${carte};padding:16px 18px;font-size:12.5px;color:var(--color-text-muted)">Le simulateur attend un mois de ventes servi par la caisse.</div>` : `
    <div style="${carte};padding:16px 18px">
      <div style="${lbl}">2 · La prime de vente complémentaire — quel étage viser ?</div>
      <div style="font-size:11.5px;color:var(--color-text-muted);margin:3px 0 10px">Sur les chiffres de ${esc(SIM.moisLib)} — dernier mois servi (tickets et moyennes réels, la cible de chaque magasin). Les deux variables s'enregistrent.</div>
      <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
        <label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px">Valeur d'une ligne
          <input value="${esc(SIM.valeurLigne)}" ${x.C(SIM.poserValeur)} style="width:64px;font-family:var(--font-ui);font-size:12.5px;padding:6px 8px;border-radius:8px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);text-align:right"> €</label>
        <span style="font-size:11px;color:var(--color-text-muted)">${esc(SIM.valeurNote)}</span>
        <label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px">Marge récupérée
          <input value="${esc(SIM.marge)}" ${x.C(SIM.poserMarge)} style="width:64px;font-family:var(--font-ui);font-size:12.5px;padding:6px 8px;border-radius:8px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);text-align:right;width:56px"> %</label>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px">
        ${SIM.etages.map(et => `
        <div style="border:${et.premier ? '1.5px solid var(--color-primary)' : '0.5px solid var(--color-border-secondary)'};border-radius:10px;padding:10px 13px${et.premier ? ';background:rgba(141,29,44,0.03)' : ''}">
          <div style="font-size:12px;font-weight:700">${esc(et.nom)} <span style="font-weight:400;color:var(--color-text-muted);font-size:10px">équipe ${esc(et.prime)}</span></div>
          <div style="display:flex;gap:14px;margin-top:6px;flex-wrap:wrap">
            <div><div style="${lbl}">CA en plus</div><div style="font-weight:700;color:#2d7a3e">${esc(et.ca)}</div></div>
            <div><div style="${lbl}">Primes</div><div style="font-weight:700;color:var(--color-primary)">${esc(et.bud)}</div></div>
            <div><div style="${lbl}">Net</div><div style="font-weight:700">${esc(et.net)}</div></div>
          </div>
        </div>`).join('')}
      </div>
    </div>

    <div style="${carte};padding:16px 18px">
      <div style="${lbl}">3 · Le compte, magasin par magasin</div>
      <div style="font-size:11.5px;color:var(--color-text-muted);margin:3px 0 8px">Où en est chaque magasin face à SA cible, ce que l'atteindre rapporterait, ce que les primes coûteraient au maximum. La cible s'édite ici — elle vaut dès ce mois.</div>
      <div style="overflow-x:auto"><table style="border-collapse:collapse;width:100%;min-width:820px;font-size:12px">
        <tr><th style="${thS};text-align:left">Magasin</th><th style="${thS}">Cible</th><th style="${thS}">Moyenne (${esc(SIM.moisLib)})</th><th style="${thS}">Écart</th><th style="${thS}">Tickets/mois</th><th style="${thS}">CA en plus si cible</th><th style="${thS}">Marge</th><th style="${thS}">Primes max</th><th style="${thS}">Coût / CA</th></tr>
        ${SIM.compte.lignes.map(l => `
        <tr>
          <td style="${tdS};text-align:left;font-weight:600">${esc(l.nom)}</td>
          <td style="${tdS}"><input type="number" min="1" max="10" step="0.1" value="${esc(l.cible)}" ${x.C(l.poser)} style="width:56px;font-family:var(--font-ui);font-size:12px;padding:4px 6px;border-radius:7px;border:0.5px solid var(--color-primary);background:var(--color-surface);text-align:right"></td>
          <td style="${tdS}">${esc(l.moyenne)}</td>
          <td style="${tdS};color:var(--color-primary);font-weight:700">${esc(l.ecart)}</td>
          <td style="${tdS}">${esc(l.tickets)}</td>
          <td style="${tdS};font-weight:700;color:#2d7a3e">${esc(l.ca)}</td>
          <td style="${tdS}">${esc(l.margeE)}</td>
          <td style="${tdS}">${esc(l.primes)}</td>
          <td style="${tdS};color:var(--color-text-muted)">${esc(l.cout)}</td>
        </tr>`).join('')}
        <tr style="background:var(--color-background-secondary)">
          <td style="${tdS};text-align:left;font-weight:700">RÉSEAU / mois</td><td style="${tdS}"></td><td style="${tdS}"></td><td style="${tdS}"></td><td style="${tdS}"></td>
          <td style="${tdS};font-family:var(--font-display);font-size:16px;color:#2d7a3e">${esc(SIM.compte.totCa)}</td>
          <td style="${tdS};font-weight:700">${esc(SIM.compte.totMarge)}</td>
          <td style="${tdS};font-weight:700;color:var(--color-primary)">${esc(SIM.compte.totPrimes)}</td>
          <td style="${tdS}"></td>
        </tr>
        <tr>
          <td style="${tdS};text-align:left;font-weight:700">Net pour vous (marge ${esc(SIM.marge)} %)</td>
          <td style="${tdS}" colspan="7"><span style="font-family:var(--font-display);font-size:18px;color:var(--color-primary)">${esc(SIM.compte.totNet)}</span></td>
          <td style="${tdS};color:var(--color-text-muted)">${esc(SIM.compte.totCout)}</td>
        </tr>
      </table></div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:6px">« Primes max » = prime d'équipe + toutes les vendeuses classables à la cible — le pire cas pour votre budget, le meilleur pour le comptoir.</div>
    </div>`}

    <div style="${carte};padding:16px 18px">
      <div style="${lbl}">4 · Les cibles posées — l'année, mois par mois</div>
      <div style="font-size:11.5px;color:var(--color-text-muted);margin:3px 0 10px">Le passé en lecture — l'histoire ne se réécrit pas. Une cible posée (bord bordeaux) vaut pour son mois et les suivants, jusqu'à la prochaine.</div>
      <div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:12px">
        <thead><tr><th style="${th};text-align:left">Magasin</th>
          ${(c.cxAnnee || []).map(m => `<th style="${th}${m.passe ? ';color:#b8b2a8' : ''}">${esc(m.lib)}</th>`).join('')}
        </tr></thead>
        <tbody>
          ${(c.cxLignes || []).map(l => `<tr>
            <td style="${td};text-align:left;font-weight:500">${esc(l.nom)}</td>
            ${(l.annee || []).map(t => `<td style="${td};padding:4px 4px">${t.passe
              ? `<span style="color:${t.pose ? 'var(--color-text)' : '#b8b2a8'}" title="${t.pose ? 'cible posée ce mois-là' : 'héritée — le passé ne se réécrit pas'}">${esc(t.val || '')}</span>`
              : `<input type="number" min="1" max="10" step="0.1" value="${esc(t.val)}" ${x.C(t.poser)}
                  style="width:52px;font-family:var(--font-ui);font-size:11.5px;padding:4px 5px;border-radius:7px;border:0.5px solid ${t.pose ? 'var(--color-primary)' : 'var(--color-border-secondary)'};background:var(--color-surface);text-align:right">`}</td>`).join('')}
          </tr>`).join('')}
        </tbody>
      </table></div>
    </div>

    <div style="${carte};padding:16px 18px">
      <div style="${lbl}">5 · Le barème de la prime de vente complémentaire</div>
      <div style="display:flex;gap:34px;flex-wrap:wrap;align-items:flex-end;margin-top:10px">
        <div><div style="${lbl};margin-bottom:6px">Le geste — vendeuse à la cible</div>
          <label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px">
          <input type="number" min="1" step="5" value="${c.cxMontant != null ? c.cxMontant : ''}" ${x.C(c.cxMontantPoser)} style="width:64px;font-family:var(--font-ui);font-size:12.5px;padding:6px 8px;border-radius:8px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);text-align:right"> €</label></div>
        <div><div style="${lbl};margin-bottom:6px">L'équipe — moyenne du magasin à la cible</div>
          <label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px">
          <input type="number" min="1" step="5" value="${c.cxMontantShop != null ? c.cxMontantShop : ''}" ${x.C(c.cxMontantShopPoser)} style="width:64px;font-family:var(--font-ui);font-size:12.5px;padding:6px 8px;border-radius:8px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);text-align:right"> €
          <span style="font-size:11px;color:var(--color-text-muted)">s'ajoute aux primes personnelles</span></label></div>
        <div style="border-left:0.5px solid var(--color-border-secondary);padding-left:24px">
          <div style="${lbl};margin-bottom:6px">Les crans — la moyenne au-dessus de la cible</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            ${(c.cxPaliers || []).map(pal => `
            <span style="display:inline-flex;align-items:center;gap:5px;background:#FFF7E0;border:0.5px solid #E8C9A0;border-radius:999px;padding:4px 6px 4px 10px;font-size:11.5px">
              cible +<input type="number" min="0.1" max="5" step="0.1" value="${esc(pal.plus)}" ${x.C(pal.poserPlus)} style="width:48px;font-family:var(--font-ui);font-size:11.5px;padding:3px 5px;border-radius:6px;border:0.5px solid #E8C9A0;background:var(--color-surface);text-align:right">
              →
              <input type="number" min="1" step="5" value="${esc(pal.montant)}" ${x.C(pal.poserMontant)} style="width:52px;font-family:var(--font-ui);font-size:11.5px;padding:3px 5px;border-radius:6px;border:0.5px solid #E8C9A0;background:var(--color-surface);text-align:right"> €
              <button ${x.A(pal.retirer)} title="Retirer ce cran" style="border:none;background:none;color:var(--color-text-muted);cursor:pointer;font-size:11px;padding:0 3px">✕</button>
            </span>`).join('')}
            <button ${x.A(c.cxPalierAjouter)} style="${pill(false)}">+ cran</button>
          </div>
        </div>
      </div>
      <div style="font-size:11px;color:var(--color-text-muted);margin-top:10px;line-height:1.55">Le plus haut cran franchi paie — et <b>mieux ne paie jamais moins</b> : un cran saisi sous l'étage d'en dessous est ignoré, le simulateur du haut vous le montre aussitôt. Chaque réglage s'enregistre à la sortie du champ et passe au journal. Les résultats — qui atteint quoi, mois par mois — sont dans l'onglet Résultats.</div>
    </div>
  </div>`;
  }

  return `
  <div data-screen="ventes" style="display:flex;flex-direction:column;gap:14px;max-width:1380px">
    ${onglets}
    ${!c.tvPodium.length ? '' : `
    <div style="display:grid;grid-template-columns:1.35fr repeat(${Math.max(1, c.tvPodium.length - 1)}, 1fr);gap:12px">
      ${c.tvPodium.map((p, i) => `
      <div style="${carte};padding:14px 16px${i === 0 ? ';border-color:var(--color-primary)' : ''}">
        <span style="${lbl}${i === 0 ? ';color:var(--color-primary)' : ''}">${esc(p.titre)}</span>
        <div style="font-family:var(--font-display);font-size:${i === 0 ? 20 : 16}px;font-weight:600;margin:5px 0 3px">${esc(p.nom)}
          <span style="${p.reseau && i === 0 ? primeR : primeOr};margin-left:6px">${esc(p.prime)}</span></div>
        <div style="font-size:11.5px;color:var(--color-text-muted);line-height:1.5">${esc(p.sub)}</div>
      </div>`).join('')}
    </div>`}

    ${!(c.tvTops || []).length ? '' : `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px">
      ${c.tvTops.map(t => `
      <div style="${carte};padding:14px 16px">
        <div style="${lbl}">${esc(t.titre)}</div>
        <div style="font-size:11px;color:var(--color-text-muted);margin:2px 0 8px">${esc(t.note)}</div>
        ${t.lignes.map(l => `
        <div style="display:flex;align-items:baseline;gap:8px;padding:4.5px 0;border-bottom:0.5px solid var(--color-border-tertiary);font-size:12px">
          <span style="width:16px;color:var(--color-text-muted)">${l.rang}</span>
          <span style="font-weight:500">${esc(l.nom)}</span>
          <span style="font-size:10.5px;color:var(--color-text-muted)">${esc(l.magasin)}</span>
          <span style="flex:1"></span>
          <span style="font-weight:600;font-variant-numeric:tabular-nums${l.rang === 1 ? ';color:var(--color-primary)' : ''}">${esc(l.val)}</span>
          <span style="font-size:10.5px;color:var(--color-text-muted);font-variant-numeric:tabular-nums;white-space:nowrap">${esc(l.sub)}</span>
        </div>`).join('')}
      </div>`).join('')}
    </div>`}

    <div style="${carte}">
      <div style="padding:16px 18px 0;display:flex;gap:14px;align-items:baseline;flex-wrap:wrap">
        <div>
          <div style="${lbl}">Le personnel de vente, classé au CA/h pondéré par les heures</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">Cliquez une personne pour ses six derniers mois.</div>
        </div>
        <span style="flex:1"></span>
        ${c.tvPrime.fait
          ? `<span style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.tvPrime.txt)}</span>`
          : `<button ${x.A(c.tvPrime.agir)} style="${pill(true)}">🏆 ${esc(c.tvPrime.txt)}</button>`}
        <a href="${esc(c.tvPdfHref)}" target="_blank" rel="noreferrer" style="font-size:11.5px;font-weight:500;color:var(--color-primary);text-decoration:none;border:0.5px solid var(--color-primary);border-radius:999px;padding:5px 13px">↓ Rapport PDF</a>
        <a href="${esc(c.tvAfficheHref)}" target="_blank" rel="noreferrer" title="Une page par magasin, à épingler en réserve : le système de primes du mois et les montants à gagner" style="font-size:11.5px;font-weight:500;color:var(--color-primary);text-decoration:none;border:0.5px solid var(--color-primary);border-radius:999px;padding:5px 13px">↓ Affiche primes</a>
      </div>
      ${filtres}
      <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:980px">
        <thead><tr>
          <th style="${th};text-align:left;padding-left:18px">#</th>
          <th style="${th};text-align:left">Vendeur·se</th>
          <th style="${th};text-align:left">Magasin</th>
          <th style="${th}">Heures</th><th style="${th}">CA</th><th style="${th}">CA / heure</th>
          <th style="${th}">Coef. h</th><th style="${th}" title="coefficient de créneau">Coef. crén.</th><th style="${th}">Score</th>
          <th style="${th};text-align:left;padding-left:18px"></th>
          <th style="${th}">Panier</th><th style="${th}">Lignes / ticket</th><th style="${th};padding-right:18px">Tickets</th>
        </tr></thead>
        <tbody>
          ${c.tvLignes.map(l => `
          <tr ${x.A(l.basculer)} style="cursor:pointer${l.ouverte ? ';background:#FBF8F4' : ''}${l.classable ? '' : ';color:#9a9186'}">
            <td style="${td};text-align:left;padding-left:18px;color:var(--color-text-muted)">${l.rang != null ? l.rang : ''}</td>
            <td style="${td};text-align:left;font-weight:500">${avatar(l.ini)}${esc(l.nom)}
              ${l.primeReseau ? ` <span style="${primeR}">réseau</span>` : l.primeMagasin ? ` <span style="${primeOr}">${esc(l.magasin)}</span>` : ''}
              ${l.classable ? '' : `<span style="font-size:10.5px;color:#9a9186"> · ${esc(l.motif)}</span>`}</td>
            <td style="${td};text-align:left;color:var(--color-text-muted)">${esc(l.magasin)}</td>
            <td style="${td}">${esc(l.heures)}</td>
            <td style="${td}">${esc(l.ca)}</td>
            <td style="${td}">${esc(l.caHeure)}</td>
            <td style="${td};color:var(--color-text-muted)">${esc(l.coef)}</td>
            <td style="${td};color:var(--color-text-muted)" title="${esc(l.creneauTitre)}">${esc(l.coefCreneau)}</td>
            <td style="${td};font-weight:600${l.classable ? ';color:var(--color-primary)' : ''}">${esc(l.score)}</td>
            <td style="${td};text-align:left;padding-left:18px"><span style="position:relative;display:inline-block;height:8px;border-radius:999px;background:rgba(34,34,34,.06);width:96px;vertical-align:middle;overflow:hidden"><i style="position:absolute;left:0;top:0;height:8px;width:${l.barre}%;background:${l.classable ? 'var(--color-primary)' : '#CFC7BA'};border-radius:999px"></i></span></td>
            <td style="${td}">${esc(l.panier)}</td>
            <td style="${td};font-weight:600">${esc(l.lignesTicket)}</td>
            <td style="${td};padding-right:18px">${esc(l.tickets)}</td>
          </tr>
          ${l.ouverte ? fiche(c.tvFiche) : ''}`).join('')}
        </tbody>
      </table></div>
      <div style="font-size:11px;color:var(--color-text-muted);padding:12px 18px 16px;line-height:1.55">
        Le classement se fait au <b>CA/h pondéré</b> : CA/heure × coefficient d’heures, où coefficient = heures ÷ (heures + 20).
        Au plus d’heures prestées, au plus le coefficient approche 1 — la régularité pèse, et cinq bonnes heures ne battent plus un mois entier.
        S’y multiplie le <b>coefficient de créneau</b> (borné 0,80 – 1,30) : vendre l’après-midi ou en semaine est plus dur que le samedi matin — survolez la colonne pour voir la part d’après-midi et de week-end de chacun. Le CA/heure réel reste affiché.
        ${!c.tvCreneaux ? '' : '<br>' + esc(c.tvCreneaux)}
        Le classement est ouvert à toutes les heures prestées${c.tvSeuil > 0 ? ` dès ${c.tvSeuil} h au planning` : ''} — sans heure au planning ou sans vente à son nom : montré·e, jamais classé·e ni primé·e. Panier = CA ÷ tickets · vente complémentaire = lignes par ticket.
        La meilleure du réseau ne cumule pas la prime magasin. Les primes s’enregistrent d’un clic et passent au journal.
        ${!c.tvSansVendeur ? '' : '<br>' + esc(c.tvSansVendeur)}
      </div>
    </div>

    <div style="${carte}">
      <div style="padding:16px 18px 0;display:flex;gap:14px;align-items:baseline;flex-wrap:wrap">
        <div>
          <div style="${lbl}">La prime de vente complémentaire — target évolutive par magasin</div>
          <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">${esc(c.cxEchelle || '')} Cette prime <b>s’ajoute</b> à celles de la meilleure vendeuse. Les réglages — cibles, montants, paliers — sont dans l’onglet Targets &amp; primes.</div>
        </div>
        <span style="flex:1"></span>
        ${!c.cxPrime ? '' : c.cxPrime.fait
          ? `<span style="font-size:11.5px;color:var(--color-text-muted)">${esc(c.cxPrime.txt)}</span>`
          : `<button ${x.A(c.cxPrime.agir)} style="${pill(true)}">🎯 ${esc(c.cxPrime.txt)}</button>`}
      </div>
      ${c.cxChargement ? `<div style="padding:14px 18px 16px;font-size:12px;color:var(--color-text-muted)">Lecture des six mois…</div>`
      : c.cxMotif ? `<div style="padding:14px 18px 16px;font-size:12px">${esc(c.cxMotif)}</div>` : `
      <div style="overflow-x:auto;padding-top:8px"><table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:860px">
        <thead><tr>
          <th style="${th};text-align:left;padding-left:18px">Magasin</th>
          ${(c.cxEntetes || []).map(m => `<th style="${th}">${esc(m)}</th>`).join('')}
        </tr></thead>
        <tbody>
          ${(c.cxLignes || []).map(l => `<tr>
            <td style="${td};text-align:left;padding-left:18px;font-weight:500">${esc(l.nom)}</td>
            ${l.cells.map(c2 => `<td style="${td}" title="${esc(c2.noms)}">${c2.vide ? '<span style="color:#b8b2a8"></span>'
              : `<span style="color:var(--color-text-muted)">${esc(c2.target)}</span> ${c2.nb > 0
                ? `<span style="font-size:10.5px;font-weight:600;padding:2px 8px;border-radius:999px;background:#E6F2E9;color:#2d7a3e">✓ ${c2.nb} · ${c2.eur} €</span>`
                : `<span style="font-size:10.5px;color:var(--color-primary)">0</span>`}
                <div style="font-size:9.5px;margin-top:2px;color:${c2.shopOk ? '#2d7a3e' : 'var(--color-text-muted)'}">moy ${esc(c2.moyenne)}${c2.primeShop ? ' 🏅 ' + c2.primeShop + ' €' : ''}</div>`}</td>`).join('')}
          </tr>`).join('')}
        </tbody>
      </table></div>
      <div style="font-size:11px;color:var(--color-text-muted);padding:12px 18px 16px;line-height:1.55">
        Chaque cellule : la cible du mois · ✓ les vendeuses qui l’atteignent (prime personnelle de base chacune) · la moyenne du magasin avec 🏅 et la prime d’ÉQUIPE du cran franchi — c’est la moyenne qui gravit l’escalier, pas chaque vendeuse, et elle s’ajoute aux primes personnelles.
        Sans cible posée, rien ne se compte. Les primes s’enregistrent une fois le mois fini et passent au journal ; cibles, montants et paliers se règlent dans l’onglet Targets &amp; primes.
      </div>`}
    </div>
  </div>`;
}

function tplAnm(c, x){
  const { esc } = x;
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const lbl = 'font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--color-text-muted);font-weight:500';
  const th = 'text-align:right;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:10px;border-bottom:0.5px solid var(--color-border-tertiary)';
  const td = 'padding:10px;border-bottom:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums';
  const pill = f => `border:0.5px solid ${f ? 'var(--color-primary)' : 'var(--color-border-tertiary)'};background:${f ? 'var(--color-primary)' : 'var(--color-surface)'};color:${f ? '#fff' : 'var(--color-text-muted)'};border-radius:999px;padding:5px 13px;font-family:var(--font-ui);font-size:11.5px;cursor:pointer`;
  const SEL = 'font-family:var(--font-ui);font-size:13px;padding:8px 12px;border-radius:9px;border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text)';
  const badge = t => `<span style="font-size:10.5px;font-weight:600;padding:2px 9px;border-radius:999px;${
    t === 'Assortiment' ? 'background:rgba(141,29,44,.09);color:var(--color-primary)'
    : t === 'Catégorie' ? 'background:#FBEFE0;color:#8a5a1c'
    : 'background:rgba(45,122,62,.1);color:#2d7a3e'}">${esc(t)}</span>`;

  // La tête du wizard : le magasin, la durée, et les quatre étapes.
  const tete = `
    <div style="${carte}">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:14px 18px 12px">
        <span style="${lbl}">Magasin</span>
        <select ${x.C(c.anmChoisir)} style="${SEL}">
          ${c.anmShops.map(m => `<option value="${esc(m.id)}" ${m.on ? 'selected' : ''}>${esc(m.nom)}</option>`).join('')}
        </select>
        <span style="width:10px"></span>
        ${c.anmDurees.map(t => `<button ${x.A(t.choisir)} style="${pill(t.on)}">${esc(t.nom)}</button>`).join('')}
        <span style="flex:1"></span>
        <span style="font-size:11px;color:var(--color-text-muted)">comparé aux autres magasins, à fréquentation ramenée</span>
      </div>
      <div style="display:flex;gap:0;border-top:0.5px solid var(--color-border-tertiary)">
        ${c.anmEtapes.map((e2, i) => `
        <div ${x.A(e2.choisir)} style="flex:1;text-align:center;padding:11px 6px;cursor:pointer;font-size:12px;
          ${e2.on ? 'font-weight:600;color:var(--color-primary);box-shadow:inset 0 -2px 0 var(--color-primary)'
            : e2.fait ? 'color:var(--color-text)' : 'color:var(--color-text-muted)'}
          ${i ? ';border-left:0.5px solid var(--color-border-tertiary)' : ''}">
          <span style="display:inline-flex;width:17px;height:17px;border-radius:50%;align-items:center;justify-content:center;font-size:10px;margin-right:6px;
            ${e2.on || e2.fait ? 'background:var(--color-primary);color:#fff' : 'background:rgba(34,34,34,.08);color:var(--color-text-muted)'}">${e2.fait ? '✓' : e2.v}</span>${esc(e2.nom)}
        </div>`).join('')}
      </div>
    </div>`;

  if (c.anmChargement) {
    return `<div data-screen="anm" style="display:flex;flex-direction:column;gap:14px;max-width:1360px">${tete}
      <div style="${carte};padding:20px 22px;font-size:12.5px;color:var(--color-text-muted)">Analyse en cours — la caisse est relue sur toute la période…</div></div>`;
  }
  if (c.anmMotif) {
    return `<div data-screen="anm" style="display:flex;flex-direction:column;gap:14px;max-width:1360px">${tete}
      <div style="${carte};padding:20px 22px;font-size:12.5px">${esc(c.anmMotif)}</div></div>`;
  }

  const e1 = `
    <div style="${carte}">
      <div style="display:flex;gap:26px;flex-wrap:wrap;padding:16px 18px">
        ${c.anmKpis.map(k => `<div><span style="${lbl}">${esc(k.lbl)}</span>
          <b style="display:block;font-size:20px;font-weight:600;margin-top:2px${k.accent ? ';color:var(--color-primary)' : ''}">${esc(k.v)}</b>
          <span style="font-size:11px;color:var(--color-text-muted)">${esc(k.sub)}</span></div>`).join('')}
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
      ${c.anmLeviers.map(l => `
      <div style="${carte};padding:16px 18px${l.aller ? ';cursor:pointer' : ''}" ${l.aller ? x.A(l.aller) : ''}>
        <span style="${lbl}">${esc(l.lbl)}</span>
        <b style="display:block;font-size:21px;font-weight:600;color:var(--color-primary);margin:4px 0 1px">${esc(l.moisTxt)}</b>
        <span style="font-size:11.5px;color:var(--color-text-muted)">${esc(l.anTxt)}</span>
        <div style="font-size:11.5px;color:var(--color-text);margin-top:8px;line-height:1.5">${esc(l.note)}</div>
      </div>`).join('')}
    </div>`;

  const e2c = `
    <div style="${carte}">
      <div style="padding:16px 18px 0">
        <div style="${lbl}">${esc(c.anmNom)} — part du CA par catégorie, contre la part réseau</div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">Une catégorie en retrait est un rayon qui existe déjà : la développer ne demande ni référence ni prix nouveaux. Le trait noir marque la part réseau.</div>
      </div>
      <div style="overflow-x:auto;padding-top:6px"><table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:860px">
        <thead><tr>
          <th style="${th};text-align:left;padding-left:18px">Catégorie</th>
          <th style="${th}">Votre part</th><th style="${th}">Part réseau</th>
          <th style="${th};text-align:left;padding-left:22px">Position</th>
          <th style="${th}">Écart</th><th style="${th}">Potentiel / mois</th><th style="${th};padding-right:18px">Par an</th>
        </tr></thead>
        <tbody>
          ${c.anmGroupes.map(g => `<tr>
            <td style="${td};text-align:left;padding-left:18px;font-weight:500">${esc(g.nom)}</td>
            <td style="${td}">${esc(g.part)}</td>
            <td style="${td};color:var(--color-text-muted)">${esc(g.partReseau)}</td>
            <td style="${td};text-align:left;padding-left:22px"><span style="position:relative;display:inline-block;height:8px;border-radius:999px;background:rgba(34,34,34,.07);width:150px;vertical-align:middle">
              <i style="position:absolute;left:0;top:0;height:8px;border-radius:999px;width:${g.barre}%;background:${g.retrait ? 'var(--color-primary)' : '#2d7a3e'}"></i>
              <i style="position:absolute;top:-3px;width:2px;height:14px;background:#26221E;border-radius:2px;left:${g.barreReseau}%"></i></span></td>
            <td style="${td};color:${g.retrait ? 'var(--color-primary)' : '#2d7a3e'};font-weight:600">${esc(g.delta)}</td>
            <td style="${td};font-weight:600;${g.retrait ? 'color:var(--color-primary)' : 'color:var(--color-text-muted);font-weight:400;font-size:11px'}">${esc(g.potMois)}</td>
            <td style="${td};padding-right:18px;color:var(--color-text-muted)">${esc(g.potAn)}</td>
          </tr>`).join('')}
        </tbody>
      </table></div>
      <div style="font-size:11px;color:var(--color-text-muted);padding:12px 18px 16px;line-height:1.55">Le potentiel = revenir à la part médiane des autres magasins, le CA mensuel actuel posé comme assiette — <b>moins ce que le levier 1 compte déjà</b> : une catégorie en retrait l’est souvent parce que des références y manquent, et additionner les deux promettrait deux fois le même euro. Une catégorie au-dessus du réseau n’est pas « à corriger » : c’est l’identité du magasin — elle est marquée comme force, jamais comptée en négatif.</div>
    </div>`;

  const e3 = `
    <div style="${carte}">
      <div style="padding:16px 18px 0">
        <div style="${lbl}">${esc(c.anmNom)} — les prix sous le réseau</div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">Au prix réellement encaissé, remises comprises. Une référence dont le volume dépasse celui des autres n’apparaît pas : son prix bas travaille. Le gain est à volume constant — si le volume tient, c’est de la marge pure.</div>
      </div>
      ${!c.anmPrix.length ? `<div style="font-size:12.5px;color:#2d7a3e;padding:14px 18px 16px">Aucun prix sous le réseau : la grille de ce magasin est au niveau.</div>` : `
      <div style="overflow-x:auto;padding-top:6px"><table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:880px">
        <thead><tr>
          <th style="${th};text-align:left;padding-left:18px">Référence</th>
          <th style="${th};text-align:left">Catégorie</th>
          <th style="${th}">Votre prix</th><th style="${th}">Prix réseau</th><th style="${th}">Écart</th>
          <th style="${th}">Volume / mois</th><th style="${th}">Gain / mois</th><th style="${th};padding-right:18px">Par an</th>
        </tr></thead>
        <tbody>
          ${c.anmPrix.map(r => `<tr>
            <td style="${td};text-align:left;padding-left:18px;font-weight:500">${esc(r.nom)}</td>
            <td style="${td};text-align:left;color:var(--color-text-muted)">${esc(r.groupe)}</td>
            <td style="${td}">${esc(r.prix)}</td>
            <td style="${td};color:var(--color-text-muted)">${esc(r.prixReseau)}</td>
            <td style="${td};color:${r.fort ? 'var(--color-primary)' : '#C17A2A'};font-weight:600">${esc(r.ecart)}</td>
            <td style="${td}">${esc(r.volMois)}</td>
            <td style="${td};font-weight:600;color:var(--color-primary)">${esc(r.gainMois)}</td>
            <td style="${td};padding-right:18px;color:var(--color-text-muted)">${esc(r.gainAn)}</td>
          </tr>`).join('')}
          ${!c.anmPrixReste ? '' : `<tr><td colspan="8" style="${td};text-align:left;padding-left:18px;color:var(--color-text-muted)">${esc(c.anmPrixReste)}</td></tr>`}
        </tbody>
      </table></div>`}
      <div style="font-size:11px;color:var(--color-text-muted);padding:12px 18px 16px;line-height:1.55">Prix comparés sur la même période, entre magasins ayant vendu au moins 5 unités. L’alignement est une décision du franchisé : l’écran chiffre, il ne fixe pas les prix.</div>
    </div>`;

  const e4 = `
    <div style="${carte}">
      <div style="padding:16px 18px 0">
        <div style="${lbl}">${esc(c.anmNom)} — le plan, classé par euros mensuels</div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">Les actions des trois leviers fusionnées. La colonne « cumul » se lit comme un objectif d’équipe : jusqu’où on descend la liste, et ce que ça fait sur l’année.</div>
      </div>
      ${!c.anmPlan.length ? `<div style="font-size:12.5px;color:#2d7a3e;padding:14px 18px 16px">Rien à recommander : ce magasin vend ce que le réseau vend, aux prix du réseau.</div>` : `
      <div style="overflow-x:auto;padding-top:6px"><table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:860px">
        <thead><tr>
          <th style="${th};text-align:left;padding-left:18px">#</th>
          <th style="${th};text-align:left">Action</th><th style="${th};text-align:left">Levier</th>
          <th style="${th}">/ mois</th><th style="${th}">/ an</th><th style="${th};padding-right:18px">Cumul / an</th>
        </tr></thead>
        <tbody>
          ${c.anmPlan.map(a => `<tr>
            <td style="${td};text-align:left;padding-left:18px;color:var(--color-text-muted)">${a.rang}</td>
            <td style="${td};text-align:left;font-weight:500;line-height:1.45">${esc(a.action)}</td>
            <td style="${td};text-align:left">${badge(a.levier)}</td>
            <td style="${td};font-weight:600">${esc(a.mois)}</td>
            <td style="${td};color:var(--color-text-muted)">${esc(a.an)}</td>
            <td style="${td};padding-right:18px;font-weight:600;color:var(--color-primary)">${esc(a.cumul)}</td>
          </tr>`).join('')}
        </tbody>
      </table></div>`}
      <div style="font-size:11px;color:var(--color-text-muted);padding:12px 18px 16px;line-height:1.55"><b>${esc(c.anmPlanTotal)}</b> Les actions d’assortiment sont déjà retenues à la moitié de leur estimation — un plan qui promet le maximum n’est pas un plan.</div>
    </div>`;

  const e5 = `
    <div style="${carte};padding:26px 28px;display:flex;gap:30px;flex-wrap:wrap;align-items:flex-start">
      <div style="flex:1;min-width:320px">
        <div style="${lbl}">${esc(c.anmNom)} — le dossier complet, en deux pages A4</div>
        <div style="font-size:13px;line-height:1.6;margin:10px 0 14px;max-width:640px">Le même moteur que la note de campagne, les mêmes chiffres que les quatre étapes que vous venez de lire — mis en page pour être posés sur la table de l’entretien franchisé.</div>
        ${(c.anmPdfContenu || []).map(t => `<div style="display:flex;gap:9px;font-size:12.5px;padding:5px 0;border-bottom:0.5px solid var(--color-border-tertiary)"><span style="color:#2d7a3e;font-weight:700">✓</span><span>${esc(t)}</span></div>`).join('')}
        <div style="font-size:11px;color:var(--color-text-muted);margin-top:12px;line-height:1.55">Le pied de page porte le magasin, la période et la date d’édition — chaque feuille reste identifiable une fois agrafée à d’autres.</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px;align-items:stretch;min-width:250px">
        <a href="${esc(c.anmPdfHref)}" target="_blank" rel="noreferrer" style="display:block;text-align:center;background:var(--color-primary);color:#fff;border-radius:11px;padding:15px 26px;font-size:14px;font-weight:600;text-decoration:none">↓ Télécharger le PDF</a>
        <span style="text-align:center;font-size:11px;color:var(--color-text-muted)">${esc(c.anmPdfNom || '')} · 2 pages A4</span>
      </div>
    </div>`;

  return `
  <div data-screen="anm" style="display:flex;flex-direction:column;gap:14px;max-width:1360px">
    ${tete}
    ${c.anmEtape === 1 ? e1 : c.anmEtape === 2 ? e2c : c.anmEtape === 3 ? e3 : c.anmEtape === 4 ? e4 : e5}
    <div style="display:flex;align-items:center;gap:10px">
      ${c.anmPrec ? `<button ${x.A(c.anmPrec)} style="${pill(false)}">← Précédent</button>` : ''}
      <span style="flex:1"></span>
      <span style="font-size:10.5px;color:var(--color-text-muted);max-width:760px;text-align:right;line-height:1.5">${esc(c.anmSource || '')}</span>
      ${c.anmSuiv ? `<button ${x.A(c.anmSuiv)} style="${pill(true)}">Suivant →</button>` : ''}
    </div>
  </div>`;
}

function tplManque(c, x){
  const { esc } = x;
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const lbl = 'font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--color-text-muted);font-weight:500';
  const th = 'text-align:right;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:10px;border-bottom:0.5px solid var(--color-border-tertiary)';
  const td = 'padding:10px;border-bottom:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums';
  const pill = f => `border:0.5px solid ${f ? 'var(--color-primary)' : 'var(--color-border-tertiary)'};background:${f ? 'var(--color-primary)' : 'var(--color-surface)'};color:${f ? '#fff' : 'var(--color-text-muted)'};border-radius:999px;padding:5px 13px;font-family:var(--font-ui);font-size:11.5px;cursor:pointer`;
  const jauge = (p, col) => `<span style="position:relative;display:inline-block;height:8px;border-radius:999px;background:rgba(34,34,34,.06);width:120px;vertical-align:middle;overflow:hidden"><i style="position:absolute;left:0;top:0;height:8px;width:${p}%;background:${col};border-radius:999px"></i></span>`;

  if (c.mqChargement) {
    return `<div data-screen="manque"><div style="${carte};padding:20px 22px;font-size:12.5px;color:var(--color-text-muted)">Calcul du manque à gagner…</div></div>`;
  }
  if (c.mqMotif) {
    return `<div data-screen="manque"><div style="${carte};padding:20px 22px;font-size:12.5px">${esc(c.mqMotif)}</div></div>`;
  }

  const detail = d => `
    <tr><td colspan="${c.mqEntetes.length + 4}" style="padding:0;background:#FBF8F4;border-top:0.5px solid var(--color-border-tertiary)">
      <div style="padding:14px 18px 16px">
        <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:4px">
          <span style="${lbl}">${esc(d.titre)}</span>
          <span style="font-size:11.5px;color:var(--color-text-muted)">${esc(d.sousTitre)}</span>
          <span style="flex:1"></span>
          <span style="font-size:11.5px;color:#2d7a3e">Rattraper la moitié : <b>${esc(d.gain)}</b> — ${esc(d.gainMois)} par mois</span>
        </div>
        ${d.vide ? `<div style="font-size:12px;color:var(--color-text-muted);padding:8px 0">Aucune référence en défaut : ce magasin vend tout ce que les autres vendent.</div>` : `
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          <thead><tr>
            <th style="${th};text-align:left">Référence</th>
            <th style="${th};text-align:left">Sous-catégorie</th>
            <th style="${th}">Vendue par</th><th style="${th}">Volume / mois</th>
            <th style="${th}">Prix encaissé</th><th style="${th}">Manque</th>
          </tr></thead>
          <tbody>
            ${d.refs.map(r => `<tr>
              <td style="${td};text-align:left;font-weight:500">${esc(r.nom)}</td>
              <td style="${td};text-align:left;color:var(--color-text-muted)">${esc(r.cat)}</td>
              <td style="${td};color:var(--color-text-muted)">${esc(r.vendeurs)}</td>
              <td style="${td}">${esc(r.unitesMois)}</td>
              <td style="${td};color:var(--color-text-muted)">${esc(r.prix)}</td>
              <td style="${td};font-weight:600;color:var(--color-primary)">${esc(r.total)}</td>
            </tr>`).join('')}
            ${!d.resteN ? '' : `<tr><td colspan="6" style="${td};text-align:left;color:var(--color-text-muted)">+ ${d.resteN} autres références — ${esc(d.resteEur)}</td></tr>`}
          </tbody>
        </table>
        <div style="font-size:11px;color:var(--color-text-muted);padding:10px 0 0;line-height:1.55">Une ligne se traite de deux façons : la mettre en production, ou la sortir de l’assortiment en le disant. Pas la laisser sans décision.</div>`}
      </div>
    </td></tr>`;

  return `
  <div data-screen="manque" style="display:flex;flex-direction:column;gap:14px;max-width:1360px">
    <div style="${carte}">
      <div style="display:flex;gap:26px;flex-wrap:wrap;padding:16px 18px">
        ${c.mqKpis.map(k => `<div><span style="${lbl}">${esc(k.lbl)}</span>
          <b style="display:block;font-size:20px;font-weight:600;margin-top:2px${k.accent ? ';color:var(--color-primary)' : ''}">${esc(k.v)}</b>
          <span style="font-size:11px;color:var(--color-text-muted)">${esc(k.sub)}</span></div>`).join('')}
      </div>
    </div>

    <div style="${carte}">
      <div style="padding:16px 18px 0">
        <div style="${lbl}">${c.mqVue === 'references' ? 'Ce que le réseau laisse sur la table' : 'Manque à gagner par magasin et par mois'}</div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">${c.mqVue === 'references'
          ? 'Absente chez presque tout le réseau : c’est une question de gamme. Absente chez un seul : une question de magasin.'
          : 'Cliquez un magasin pour voir les références qui composent son manque.'}</div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;padding:12px 18px 14px;align-items:center">
        ${c.mqVues.map(v => `<button ${x.A(v.choisir)} style="${pill(v.on)}">${esc(v.nom)}</button>`).join('')}
        <span style="width:12px"></span>
        ${c.mqDurees.map(t => `<button ${x.A(t.choisir)} style="${pill(t.on)}">${esc(t.nom)}</button>`).join('')}
        <span style="width:12px"></span>
        ${c.mqUnites.map(u => `<button ${x.A(u.choisir)} style="${pill(u.on)}">${esc(u.nom)}</button>`).join('')}
      </div>

      ${c.mqVue === 'references' ? `
      <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:820px">
        <thead><tr>
          <th style="${th};text-align:left;padding-left:18px">Référence</th>
          <th style="${th};text-align:left">Groupe</th>
          <th style="${th};text-align:left">Absente chez</th>
          <th style="${th}">Manque réseau</th><th style="${th};padding-right:18px"></th>
        </tr></thead>
        <tbody>
          ${c.mqRefs.map(r => `<tr>
            <td style="${td};text-align:left;padding-left:18px;font-weight:500">${esc(r.nom)}</td>
            <td style="${td};text-align:left;color:var(--color-text-muted)">${esc(r.groupe)}</td>
            <td style="${td};text-align:left">${esc(r.absente)}</td>
            <td style="${td};font-weight:600;color:${r.col}">${esc(r.total)}</td>
            <td style="${td};padding-right:18px">${jauge(r.barre, r.col)}</td>
          </tr>`).join('')}
        </tbody>
      </table></div>
      <div style="font-size:11px;color:var(--color-text-muted);padding:12px 18px 16px;line-height:1.55">
        ${c.mqRefs.length} références affichées sur ${c.mqRefsN} en défaut quelque part dans le réseau.
        ${!c.mqGammes ? '' : '<br>' + esc(c.mqGammes)}<br>${esc(c.mqSource)}
      </div>` : `
      <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:900px">
        <thead><tr>
          <th style="${th};text-align:left;padding-left:18px">Magasin</th>
          ${c.mqEntetes.map(m => `<th style="${th}">${esc(m)}</th>`).join('')}
          <th style="${th}">Sur la période</th><th style="${th}">% du CA</th><th style="${th};padding-right:18px">Réf.</th>
        </tr></thead>
        <tbody>
          ${c.mqLignes.map(l => `
          <tr ${x.A(l.basculer)} style="cursor:pointer${l.ouvert ? ';background:#FBF8F4' : ''}">
            <td style="${td};text-align:left;padding-left:18px;font-weight:500" title="${esc(l.complet)}"><span style="color:var(--color-text-muted);font-size:11px">${l.ouvert ? '▾' : '▸'}</span> ${esc(l.nom)}</td>
            ${l.mois.map(m => `<td style="${td};${m.st}">${esc(m.v)}${m.sub ? `<div style="font-size:9.5px;color:var(--color-text-muted)">${esc(m.sub)}</div>` : ''}</td>`).join('')}
            <td style="${td}"><span style="display:inline-flex;align-items:center;gap:8px;justify-content:flex-end">${jauge(l.barre, 'var(--color-primary)')}<b style="color:var(--color-primary)">${esc(l.total)}</b></span></td>
            <td style="${td};${l.fort ? 'color:var(--color-primary);font-weight:600' : 'color:var(--color-text-muted)'}">${esc(l.part)}</td>
            <td style="${td};padding-right:18px"><span style="font-size:10.5px;font-weight:600;padding:2px 9px;border-radius:999px;background:rgba(141,29,44,.10);color:var(--color-primary)">${l.refs}</span></td>
          </tr>
          ${l.ouvert ? detail(l.detail) : ''}`).join('')}
        </tbody>
      </table></div>
      <div style="font-size:11px;color:var(--color-text-muted);padding:12px 18px 16px;line-height:1.55">
        <b>Comment le chiffre est fait.</b> Une référence n’entre au calcul que si <b>au moins deux magasins</b> l’ont vendue ce mois-là — vendue par un seul, c’est une spécialité, pas un manque.
        Le volume retenu est la <b>médiane</b> des vendeurs par jour d’ouverture, multipliée par les jours d’ouverture du magasin et par le <b>rapport de sa fréquentation</b> à la leur : un petit magasin ne se voit pas attribuer le potentiel d’un grand.
        Le prix est celui <b>réellement encaissé</b> chez les vendeurs, remises comprises. Le mois que la caisse n’a pas encore chargé reste vide plutôt que compté à zéro.
        <br><b>C’est une estimation à assortiment comparable, pas une promesse de chiffre d’affaires.</b>
        ${!c.mqGammes ? '' : '<br>' + esc(c.mqGammes)}<br>${esc(c.mqSource)}
      </div>`}
    </div>
  </div>`;
}

function tplUsage(c, x){
  const { esc } = x;
  const carte = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
  const lbl = 'font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--color-text-muted);font-weight:500';
  const th = 'text-align:right;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:10px 10px 9px;border-bottom:0.5px solid var(--color-border-tertiary)';
  const td = 'padding:10px;border-bottom:0.5px solid var(--color-border-tertiary);text-align:right;font-variant-numeric:tabular-nums';
  const pill = f => `border:0.5px solid ${f ? 'var(--color-primary)' : 'var(--color-border-tertiary)'};background:${f ? 'var(--color-primary)' : 'var(--color-surface)'};color:${f ? '#fff' : 'var(--color-text-muted)'};border-radius:999px;padding:5px 13px;font-family:var(--font-ui);font-size:11.5px;cursor:pointer`;
  const jauge = (p, col) => `<span style="position:relative;display:inline-block;height:8px;border-radius:999px;background:rgba(34,34,34,.06);width:80px;vertical-align:middle;overflow:hidden"><i style="position:absolute;left:0;top:0;height:8px;width:${p}%;background:${col};border-radius:999px"></i></span>`;
  // Une seule grille pour les trois niveaux : les colonnes du groupe et celles
  // de ses sous-catégories doivent tomber l'une sous l'autre, sinon les taux ne
  // se comparent plus d'un coup d'œil.
  const GRILLE = 'display:grid;grid-template-columns:230px 92px 1fr 116px 96px;gap:0 12px;align-items:center';
  const badge = n => n ? `<span style="font-size:10.5px;font-weight:600;padding:2px 9px;border-radius:999px;background:rgba(141,29,44,.10);color:var(--color-primary)">${n}</span>`
    : `<span style="color:#2d7a3e;font-size:11px">à jour</span>`;

  if (c.usChargement) {
    return `<div data-screen="usage"><div style="${carte};padding:20px 22px;font-size:12.5px;color:var(--color-text-muted)">Lecture des lignes de ticket…</div></div>`;
  }
  if (c.usMotif) {
    return `<div data-screen="usage"><div style="${carte};padding:20px 22px;font-size:12.5px">${esc(c.usMotif)}</div></div>`;
  }

  const detail = d => !d ? '' : `
    <tr><td colspan="${c.usEntetes.length + 4}" style="padding:0;background:#FBF8F4;border-top:0.5px solid var(--color-border-tertiary)">
      <div style="padding:14px 18px 16px">
        ${d.chargement ? `<div style="font-size:12px;color:var(--color-text-muted)">Lecture du détail…</div>`
        : d.err ? `<div style="font-size:12px;color:#8D1D2C">${esc(d.err)}</div>` : `
        <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:10px">
          <span style="${lbl}">${esc(d.nom)} — détail par groupe et sous-catégorie</span>
          <span style="font-size:11.5px;color:var(--color-text-muted)">${esc(d.resume)}</span>
          <span style="flex:1"></span>
          ${d.tris.map(t => `<button ${x.A(t.choisir)} style="${pill(t.on)}">${esc(t.nom)}</button>`).join('')}
        </div>
        <div style="${GRILLE};font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-muted);font-weight:500;padding:7px 0;border-bottom:0.5px solid var(--color-border-secondary)">
          <span>Groupe · sous-catégorie</span><span style="text-align:right">Catalogue</span><span></span>
          <span style="text-align:right">Vendues</span><span style="text-align:right">À rattraper</span>
        </div>
        ${d.groupes.map(g => `
          <div ${x.A(g.basculer)} style="${GRILLE};font-size:12.5px;padding:9px 0;border-bottom:0.5px solid var(--color-border-secondary);cursor:pointer${g.ouvert ? ';background:rgba(34,34,34,.025)' : ''}">
            <span style="font-weight:600"><span style="color:var(--color-text-muted);font-size:11px">${g.ouvert ? '▾' : '▸'}</span> ${esc(g.nom)}
              <span style="display:block;font-size:10.5px;font-weight:400;color:var(--color-text-muted);padding-left:14px">${esc(g.sous)}</span></span>
            <span style="text-align:right;color:var(--color-text-muted)">${esc(g.catalogue)}</span>
            <span>${jauge(g.barre, g.col)}</span>
            <span style="text-align:right;color:${g.col};font-weight:600">${esc(g.taux)}</span>
            <span style="text-align:right">${badge(g.aRattraper)}</span>
          </div>
          ${!g.ouvert ? '' : g.categories.map(k => `
          <div ${x.A(k.basculer)} style="${GRILLE};font-size:12px;padding:7px 0 7px 16px;border-bottom:0.5px solid var(--color-border-tertiary);cursor:pointer;background:${k.ouverte ? 'rgba(141,29,44,.035)' : 'transparent'}">
            <span><span style="color:var(--color-text-muted);font-size:11px">${k.ouverte ? '▾' : '▸'}</span> ${esc(k.nom)}</span>
            <span style="text-align:right;color:var(--color-text-muted)">${esc(k.catalogue)}</span>
            <span>${jauge(k.barre, k.col)}</span>
            <span style="text-align:right;color:${k.col};font-weight:600">${esc(k.taux)}</span>
            <span style="text-align:right">${badge(k.aRattraper)}</span>
          </div>
          ${!k.ouverte ? '' : `
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 26px;padding:10px 0 12px 16px;background:rgba(141,29,44,.035);border-bottom:0.5px solid var(--color-border-tertiary)">
            <div>
              <div style="${lbl};margin-bottom:6px">Vendues ici (${esc(k.nVendues)})</div>
              ${k.listeVendues.length ? k.listeVendues.map(r => `<div style="display:flex;justify-content:space-between;gap:10px;font-size:12px;padding:5px 0;border-bottom:0.5px solid var(--color-border-tertiary)"><span>${esc(r.nom)}</span><span style="color:var(--color-text-muted)">${esc(r.droite)}</span></div>`).join('')
                : `<div style="font-size:12px;color:var(--color-text-muted);padding:5px 0">Aucune référence vendue dans cette catégorie.</div>`}
              ${k.resteVendues ? `<div style="font-size:11px;color:var(--color-text-muted);padding:6px 0">+ ${k.resteVendues} autres</div>` : ''}
            </div>
            <div>
              <div style="${lbl};margin-bottom:6px">Absentes (${esc(k.nAbsentes)}) — dont ${esc(k.orphelines)} que personne ne vend</div>
              ${k.listeAbsentes.map(r => `<div style="display:flex;justify-content:space-between;gap:10px;font-size:12px;padding:5px 0;border-bottom:0.5px solid var(--color-border-tertiary)"><span>${esc(r.nom)}</span><span style="${r.st}">${esc(r.droite)}</span></div>`).join('')}
              ${k.resteAbsentes ? `<div style="font-size:11px;color:var(--color-text-muted);padding:6px 0">+ ${k.resteAbsentes} autres</div>` : ''}
            </div>
          </div>`}`).join('')}`).join('')}
        <div style="font-size:11px;color:var(--color-text-muted);padding:12px 0 0;line-height:1.55">Chaque groupe est le cumul de ses sous-catégories : ouvrir ne change jamais le chiffre du dessus. Une référence que <strong>personne</strong> ne vend n’est pas un manque du magasin : c’est une question de catalogue, et elle est comptée à part.</div>`}
      </div>
    </td></tr>`;

  return `
  <div data-screen="usage" style="display:flex;flex-direction:column;gap:14px;max-width:1360px">
    <div style="${carte}">
      <div style="display:flex;gap:26px;flex-wrap:wrap;padding:16px 18px 16px">
        ${c.usKpis.map(k => `<div><span style="${lbl}">${esc(k.lbl)}</span>
          <b style="display:block;font-size:20px;font-weight:600;margin-top:2px${k.accent ? ';color:var(--color-primary)' : ''}">${esc(k.v)}</b>
          <span style="font-size:11px;color:var(--color-text-muted)">${esc(k.sub)}</span>
          ${!k.lien ? '' : `<a href="${esc(k.lien)}" target="_blank" rel="noreferrer" style="display:inline-block;margin-top:5px;font-size:11px;font-weight:500;color:var(--color-primary);text-decoration:none;border:0.5px solid var(--color-primary);border-radius:999px;padding:3px 11px">↓ ${esc(k.lienNom)}</a>`}</div>`).join('')}
      </div>
    </div>

    <div style="${carte}">
      <div style="padding:16px 18px 0">
        <div style="${lbl}">Références vendues, mois par mois</div>
        <div style="font-size:11.5px;color:var(--color-text-muted);margin-top:3px">Cliquez un magasin, puis un groupe, puis une sous-catégorie : la liste des références vendues et absentes.</div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;padding:12px 18px 14px;align-items:center">
        ${c.usDurees.map(t => `<button ${x.A(t.choisir)} style="${pill(t.on)}">${esc(t.nom)}</button>`).join('')}
        <span style="width:12px"></span>
        ${c.usGroupes.map(g => `<button ${x.A(g.choisir)} style="${pill(g.on)}">${esc(g.nom)}</button>`).join('')}
      </div>
      <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:900px">
        <thead><tr>
          <th style="${th};text-align:left;padding-left:18px">Magasin</th>
          ${c.usEntetes.map(m => `<th style="${th}">${esc(m)}</th>`).join('')}
          <th style="${th}">Sur la période</th><th style="${th}">Taux</th><th style="${th};padding-right:18px">Manquantes</th>
        </tr></thead>
        <tbody>
          ${c.usLignes.map(l => `
          <tr ${x.A(l.basculer)} style="cursor:pointer${l.ouvert ? ';background:#FBF8F4' : ''}">
            <td style="${td};text-align:left;padding-left:18px;font-weight:500" title="${esc(l.complet)}"><span style="color:var(--color-text-muted);font-size:11px">${l.ouvert ? '▾' : '▸'}</span> ${esc(l.nom)}</td>
            ${l.mois.map(m => `<td style="${td};${m.st}">${esc(m.v)}<div style="font-size:9.5px;color:var(--color-text-muted)">${esc(m.sub)}</div></td>`).join('')}
            <td style="${td};font-weight:600">${esc(l.refs)}</td>
            <td style="${td}"><span style="display:inline-flex;align-items:center;gap:8px;justify-content:flex-end">${jauge(l.barre, l.tauxCol)}<span style="color:${l.tauxCol};font-weight:600">${esc(l.taux)}</span></span></td>
            <td style="${td};padding-right:18px"><span style="font-size:10.5px;font-weight:600;padding:2px 9px;border-radius:999px;background:rgba(141,29,44,.10);color:var(--color-primary)">${l.manquantes}</span></td>
          </tr>
          ${l.ouvert ? detail(c.usDetail) : ''}`).join('')}
        </tbody>
      </table></div>
      <div style="font-size:11px;color:var(--color-text-muted);padding:12px 18px 16px;line-height:1.55">
        « Sur la période » compte les références vendues au moins une fois — un magasin peut tourner sa gamme sans jamais en vendre beaucoup le même mois.
        « Manquantes » : les références vendues par au moins deux magasins et jamais par celui-ci. Le mois en cours n’est pas encore chargé en caisse : il reste vide plutôt que compté à zéro.
        ${!c.usGammes ? '' : `<br>${esc(c.usGammes)}`}
        <br>${esc(c.usSource || '')}
      </div>
    </div>
  </div>`;
}

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
