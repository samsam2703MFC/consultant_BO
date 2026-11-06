/* Maquette « Portail de formation restructuré » (06/11/2026). Données réelles (donnees.js) : 53 formations FO du
   référentiel v25 avec leurs postes (O obligatoire / R recommandée), 56 compétences C-…, la correspondance panel
   faite le 06/11, les 25 standards du Cockpit. La phase de 32 formations vient des blocs du Guide des formations ;
   celle des 21 autres est une PROPOSITION (à valider). */
const D = window.DONNEES;
const PH = { pre: 'Pré-ouverture', ouv: 'Ouverture', con: 'Continue' };
const FAMS = ['Franchise & gestion', 'Production', 'Vente & service', 'Traiteur & snacking', 'Hygiène & réglementation', 'Encadrement'];
const P = D.postes.map(p => p[0]);
const NOMP = Object.fromEntries(D.postes);
const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const nStd = fo => new Set(fo.comp.flatMap(c => (D.comp[c] || { std: [] }).std.map(s => s.id))).size;

function rail(on) {
  const e = [['formations', 'Formations', 53, 1], ['postes', 'Postes', 12, 2], ['competences', 'Compétences', 56, 3], ['manuel', 'Manuel opérationnel', 25, 4]];
  return `<nav class="rail"><div class="logo">L’Atelier By</div><div class="k">Portail de formation</div>
    ${e.map(([k, l, n, i]) => `<a class="${k === on ? 'on' : ''}"><span class="num">${i}</span>${l}<span class="n">${n}</span></a>`).join('')}
    <div class="sep"></div>
    <a class="${on === 'equipes' ? 'on' : ''}">Suivi des équipes</a>
    <div class="sep"></div><div class="g">Réglages</div>
    <a class="petit">Correspondance panel</a><a class="petit">Tablettes</a><a class="petit">Rédaction des SOP</a><a class="petit">Données du panel</a>
    <div class="bas">← Cockpit</div></nav>`;
}

function chaine(on) {
  const t = [['postes', 'Postes', 'qui', 12], ['formations', 'Formations', 'quand on apprend', 53], ['competences', 'Compétences', 'ce qu’on sait faire', 56], ['manuel', 'Manuel opérationnel', 'comment on le fait (SOP)', 25]];
  return `<div class="chaine">${t.map(([k, l, s, n]) => `<div class="${k === on ? 'on' : ''}"><span class="x">${n}</span><b>${l}</b><small>${s}</small></div>`).join('')}</div>`;
}

function qui(att) {
  return `<span class="qui">${P.map(p => `<span class="${att[p] === 'O' ? 'o' : att[p] === 'R' ? 'r' : ''}" title="${p}"></span>`).join('')}</span>`;
}

function pageFormations() {
  const ph = 'pre';
  const liste = D.fo.filter(f => f.ph.includes(ph));
  const sel = D.fo.find(f => f.code === 'FO-01');
  const cnt = k => D.fo.filter(f => f.ph.includes(k)).length;
  const heures = { pre: '172 h · blocs 1 à 9', ouv: '12 h · bloc 10', con: 'recyclages · bloc 11' };
  const lignes = FAMS.map(fam => {
    const fs = liste.filter(f => f.fam === fam);
    if (!fs.length) return '';
    return `<div class="grp">${fam} <i>${fs.length}</i></div>` + fs.map(f => {
      const ns = nStd(f);
      return `<div class="ligne ${f.code === sel.code ? 'sel' : ''}"><span class="code">${f.code}</span><span class="nom" title="${esc(f.nom)}">${esc(f.nom.replace(/^Formation /, ''))}</span>
        ${qui(f.att)}<span class="nb ${f.comp.length ? '' : 'z'}">${f.comp.length ? f.comp.length + ' compét.' : '—'}</span>
        <span class="nb ${ns ? '' : 'z'}">${ns ? ns + ' SOP' : '—'}</span><span class="freq">${f.ph.length > 1 ? '↻ ' : ''}${esc(f.freq || '')}</span></div>`;
    }).join('');
  }).join('');
  const comps = sel.comp.map(c => {
    const x = D.comp[c];
    return `<div class="it"><span class="cd">${c}</span><span class="tx">${esc(x.nom)}<small>${x.panel ? 'Panel : ' + esc(x.panel) : '<span style="color:var(--prize-ink)">pas de compétence au panel</span>'}</small></span></div>`;
  }).join('');
  const stds = [...new Map(sel.comp.flatMap(c => D.comp[c].std).map(s => [s.id, s])).values()];
  const att = Object.entries(sel.att);
  return `<div class="app">${rail('formations')}<main class="main">
    <div class="top"><h1>Formations</h1><span class="sp"></span><span class="q">Rechercher une formation, une compétence, une SOP…</span>
      <span class="seg"><span class="on">FR</span><span>NL</span></span></div>
    ${chaine('formations')}
    <div class="phases">${['pre', 'ouv', 'con'].map(k => `<div class="phase ${k === ph ? 'on' : ''}"><span class="c">${cnt(k)}</span>
      <div><div class="t">${PH[k]}</div><div class="s">${heures[k]}</div></div>${k !== 'con' ? '<span class="fl">›</span>' : ''}</div>`).join('')}</div>
    <div class="lay"><div class="carte">
      <div class="filtres"><span class="l">Pour le poste</span><span class="puce on">Tous</span>
        ${['Production', 'Vente', 'Traiteur', 'Encadrement', 'Franchisé'].map(x => `<span class="puce">${x}</span>`).join('')}
        <span style="flex:1"></span><span class="leg"><span class="fo"><span class="pt"></span>obligatoire</span><span class="fo"><span class="pt r"></span>recommandée</span></span></div>
      <div class="tete"><span class="code">Code</span><span class="nom">Formation · ${PH[ph]}</span><span class="qui" style="gap:3px;font-size:9px;letter-spacing:0">${P.map(p => `<span style="border:0;width:12px;text-align:center">${p.slice(1)}</span>`).join('')}</span>
        <span class="nb">Compét.</span><span class="nb">Manuel</span><span class="freq">Fréquence</span></div>
      ${lignes}</div>
      <div class="carte fiche"><div class="k">${sel.code} · formation</div><div class="titre">${esc(sel.nom)}</div>
        <div class="meta"><span class="bd pre">Pré-ouverture</span><span class="bd con">↻ recyclage ${esc(sel.freq.toLowerCase())}</span><span class="bd">${esc(sel.ctrl)}</span></div>
        <div class="mini"><span>${att.length} postes</span><span class="on">Formation</span><span>${sel.comp.length} compétences</span><span>${stds.length} SOP</span></div>
        <div class="bloc"><h3>Pour qui <i>obligatoire</i></h3><div class="postes">${att.map(([p, x]) => `<span class="bd ${x === 'O' ? 'pre' : 'r'}">${p} · ${esc(NOMP[p])}</span>`).join('')}</div></div>
        <div class="bloc"><h3>Ce qu’on apprend <i>${sel.comp.length} compétences</i></h3>${comps}</div>
        <div class="bloc"><h3>Dans le manuel <i>à lire avant, « vu faire » après</i></h3>
          ${stds.map(s => `<div class="it"><span class="cd">${s.code}</span><span class="tx">${esc(s.fr)}</span></div>`).join('')}</div>
        <div class="bloc"><h3>Évaluation</h3><div class="it"><span class="cd">Panel</span><span class="tx">[FO-01] Évaluation théorique vente · 5 évaluations</span></div></div>
        <div style="display:flex;gap:8px;padding:6px 0 10px"><span class="btn">Modifier</span><span class="btn">Imprimer la fiche</span></div>
      </div></div></main></div>`;
}

function pagePoste() {
  const p = 'P3';
  const fos = D.fo.filter(f => f.att[p]);
  const col = k => {
    const fs = fos.filter(f => f.ph.includes(k)).sort((a, b) => (a.att[p] === 'O' ? 0 : 1) - (b.att[p] === 'O' ? 0 : 1));
    return `<div class="col"><h3><span class="bd ${k}">${PH[k]}</span><i>${fs.filter(f => f.att[p] === 'O').length} obligatoires · ${fs.filter(f => f.att[p] === 'R').length} recomm.</i></h3>
      ${fs.map(f => `<div class="fo"><span class="pt ${f.att[p] === 'R' ? 'r' : ''}"></span><span class="cd">${f.code}</span><span>${esc(f.nom.replace(/^Formation /, ''))}</span></div>`).join('') || '<div class="fo" style="color:var(--faint)">—</div>'}</div>`;
  };
  const comps = [...new Set(fos.filter(f => f.att[p] === 'O').flatMap(f => f.comp))];
  const stds = D.std.filter(s => s.postes.includes(p));
  const MOM = { ouverture: 'Ouverture du magasin', journee: 'Pendant la journée', 'apres-midi': 'Après-midi', fermeture: 'Fermeture' };
  const parMom = Object.keys(MOM).map(m => [m, stds.filter(s => s.moment === m)]).filter(x => x[1].length);
  const grp = [['Production', ['P1', 'P5']], ['Vente', ['P3', 'P4']], ['Traiteur', ['P2', 'P6']], ['Encadrement', ['P7', 'P8']], ['Franchisé', ['P9', 'P10', 'P11', 'P12']]];
  return `<div class="app">${rail('postes')}<main class="main">
    <div class="top"><h1>Postes</h1><span class="sp"></span><span class="q">Rechercher un poste…</span><span class="seg"><span class="on">FR</span><span>NL</span></span></div>
    ${chaine('postes')}
    <div class="pl"><div class="carte liste">${grp.map(([g, ps]) => `<div class="grp">${g}</div>` + ps.map(x =>
      `<div class="lp ${x === p ? 'sel' : ''}"><span class="pc">${x}</span>${esc(NOMP[x])}<span class="n">${D.fo.filter(f => f.att[x] === 'O').length}</span></div>`).join('')).join('')}</div>
      <div><div class="hdp"><div><div class="k">${p} · poste</div><div class="titre">${esc(NOMP[p])}</div></div><span style="flex:1"></span>
        <span class="leg"><span class="fo"><span class="pt"></span>obligatoire</span><span class="fo"><span class="pt r"></span>recommandée</span></span>
        <span class="btn">Imprimer le plan de formation</span></div>
        <div class="cols">${col('pre')}${col('ouv')}${col('con')}</div>
        <div class="deux"><div class="carte fiche" style="padding-top:12px"><div class="bloc" style="border-top:0;padding-top:0"><h3>Ses compétences <i>${comps.length} · des formations obligatoires</i></h3>
          ${comps.map(c => { const x = D.comp[c]; return `<div class="it"><span class="cd">${c}</span><span class="tx">${esc(x.nom)}</span><span class="vf ${x.panel ? '' : 'n'}">${x.panel ? '● panel' : '○ hors panel'}</span></div>`; }).join('')}</div></div>
          <div class="carte fiche" style="padding-top:12px"><div class="bloc" style="border-top:0;padding-top:0"><h3>Son manuel <i>${stds.length} SOP · « Ma journée » sur la tablette</i></h3>
            ${parMom.map(([m, ss]) => `<div class="mom">${MOM[m]}</div>` + ss.map(s => `<div class="it"><span class="cd">${s.code}</span><span class="tx">${esc(s.fr)}</span></div>`).join('')).join('')}</div>
            <div class="note">Le niveau de chaque personne (sous-postes du panel) et ce qu’elle a déjà validé se lisent dans « Suivi des équipes ».</div></div></div>
      </div></div></main></div>`;
}

document.body.innerHTML = document.body.dataset.page === 'poste' ? pagePoste() : pageFormations();
