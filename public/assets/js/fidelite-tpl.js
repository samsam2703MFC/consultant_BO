/* Cockpit CEO — gabarit de l'écran Fidélité (ERP franchisé › Fidélité, #/fidelity). Les valeurs viennent de
 * fidelite-vals.js (`c.fid`) ; le style de fidelite.css (préfixe fi-). Programme : maquette C ; Clients : maquette B. */

const JOURS_L = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const JOURS_C = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];

/** Une phrase de règle : du texte, et des valeurs modifiables sur place. */
function phraseHtml(M, x){
  const { esc } = x;
  let h = '';
  M.forEach((m, i) => {
    let s = '';
    if (m.t === 'txt') { s = `<span class="fi-t">${esc(m.v)}</span>`; }
    else if (m.t === 'num') { s = `<span class="fi-champ"><input type="number" class="fi-in ${m.cls || ''}" style="width:${m.w}px" value="${esc(m.v)}" step="${m.pas || 1}" min="0" ${x.I(m.set)}>${m.u ? `<span class="fi-u">${esc(m.u)}</span>` : ''}</span>`; }
    else if (m.t === 'txtin') { s = `<input type="text" class="fi-in t" style="width:${m.w}px" value="${esc(m.v)}" placeholder="${esc(m.ph)}" ${x.I(m.set)}>`; }
    else if (m.t === 'sel') { s = `<select class="fi-sel" ${x.C(m.set)}>${m.opts.map(o => `<option value="${esc(o[0])}"${String(o[0]) === String(m.v) ? ' selected' : ''}>${esc(o[1])}</option>`).join('')}</select>`; }
    else if (m.t === 'jours') { s = `<span class="fi-jours">${JOURS_C.map((j, k) => `<button ${x.A(() => m.basculer(k))} class="${m.v.includes(k) ? 'on' : ''}" title="${JOURS_L[k]}">${j}</button>`).join('')}</span>`; }
    else if (m.t === 'heure') { s = `<input type="text" inputmode="numeric" maxlength="5" class="fi-in h" value="${esc(m.v)}" placeholder="hh:mm" ${x.C(m.set)}>`; }
    else if (m.t === 'date') { s = `<input type="date" class="fi-in d" value="${esc(m.v)}" ${x.C(m.set)}>`; }
    else if (m.t === 'pastille') { s = `<i class="fi-pastille" style="background:${esc(m.v)}"></i>`; }
    // La ponctuation se colle au mot qui précède.
    const colle = m.t === 'txt' && /^[,.]/.test(m.v);
    h += (i && !colle ? ' ' : '') + s;
  });
  return h;
}

function tplProgramme(f, x){
  const { esc } = x;
  if (f.chargement) { return '<div class="fi-carte fi-attente">Lecture du programme…</div>'; }
  if (f.indispo) { return `<div class="fi-carte fi-attente">${esc(f.indispo)}</div>`; }
  const blocs = f.blocs.map(b => `<div class="fi-carte">
    <div class="fi-ch"><span class="fi-num">${b.n}</span><span class="fi-titre">${esc(b.titre)}</span><span class="fi-mini">${esc(b.sous)}</span>
      <span class="fi-dr">${b.ajouts.map(([t, fn]) => `<button class="fi-btn" ${x.A(fn)}>${esc(t)}</button>`).join('')}${b.declencheurs ? `<select class="fi-btn sel" ${x.C(b.declencheurs.choisir)}>${b.declencheurs.opts.map(o => `<option value="${esc(o[0])}">${esc(o[1])}</option>`).join('')}</select>` : ''}</span></div>
    ${b.regles.length ? b.regles.map(r => `<div class="fi-regle${r.actif ? '' : ' off'}${r.supprimer ? ' sup' : ''}${r.modifiee ? ' mod' : ''}">
      <div class="fi-ph">${phraseHtml(r.morceaux, x)}${r.portee ? ` <select class="fi-sel portee${r.portee.v ? ' mag' : ''}" ${x.C(r.portee.set)} title="Portée de la règle">${r.portee.opts.map(o => `<option value="${esc(o[0])}"${o[0] === r.portee.v ? ' selected' : ''}>${esc(o[1])}</option>`).join('')}</select>` : ''}</div>
      <div class="fi-st ${r.statCls}">${esc(r.stat)}</div>
      ${r.basculer ? `<button class="fi-tog${r.actif ? ' on' : ''}" ${x.A(r.basculer)} title="${r.actif ? 'Désactiver' : 'Activer'}" aria-pressed="${r.actif}"></button>` : '<span class="fi-tog fixe on" title="La règle de base reste active"></span>'}
      ${r.retirer ? `<button class="fi-x" ${x.A(r.retirer)} title="${r.supprimer ? 'Garder' : 'Retirer'}">${r.supprimer ? '↺' : '×'}</button>` : '<span class="fi-x vide"></span>'}
    </div>`).join('') : '<div class="fi-regle vide">Aucune règle pour ce magasin.</div>'}
  </div>`).join('');
  const A = f.app || {};
  const phone = `<div class="fi-tel"><div class="fi-ecran">
    <div class="fi-tel-h"><div class="fi-tel-k">Bonjour Martine</div><div class="fi-tel-v">${A.solde} <small>/ ${A.jauge} points</small></div>
      <div class="fi-tel-j"><i style="width:${A.pct}%"></i></div><div class="fi-tel-s">Encore ${A.reste} points pour votre bon de ${esc(A.bon)}</div>
      ${A.creneau ? `<div class="fi-tel-x">✦ ${esc(A.creneau)}</div>` : ''}</div>
    <div class="fi-tel-c"><div class="fi-tel-k sombre">Mes cartes</div>
      ${(A.cartes || []).map(k => `<div class="fi-tel-carte" style="--c:${esc(k.couleur)}"><b>${esc(k.nom)}</b><span>${Array.from({ length: k.n }, (_, i) => `<i class="${i < k.faits ? 'f' : ''}"></i>`).join('')}</span></div>`).join('') || '<div class="fi-tel-carte vide">Aucune carte à tampons active.</div>'}
      <div class="fi-tel-k sombre" style="margin-top:10px">Mon portefeuille</div>
      <div class="fi-tel-p"><div class="bon"><b>${esc(A.bon)}</b><span>jusqu’au ${esc(A.bonFin)}</span></div>${A.cadeau ? `<div class="cad"><b>🎁</b><span>${esc(A.cadeau)}</span></div>` : ''}</div>
    </div></div></div><div class="fi-legende">l’app du client telle que les règles la montrent</div>`;
  const B = f.budget || {};
  return `<div class="fi-g21"><div class="fi-col">${blocs}</div><div class="fi-col">
    <div class="fi-carte"><div class="fi-ch"><span class="fi-titre">Ce que voit le client</span></div><div class="fi-cb">${phone}</div></div>
    <div class="fi-carte"><div class="fi-ch"><span class="fi-titre">Simulateur</span><span class="fi-mini">trois clients types du réseau, sur les règles en cours d’écriture</span></div><div class="fi-cb">
      ${f.profils.length ? f.profils.map(p => `<div class="fi-prof"><b>${esc(p.nom)}</b><small>${esc(p.sous)}</small><div><span><b>${esc(p.pts)}</b> points / mois</span><span>un bon tous les <b>${esc(p.jours)}</b> j</span><span class="${p.fort ? 'att' : ''}">rend <b>${esc(p.rend)}</b></span></div></div>`).join('') : '<div class="fi-mu">Pas assez de clients pour dégager des profils.</div>'}</div></div>
    <div class="fi-carte"><div class="fi-ch"><span class="fi-titre">Budget</span><span class="fi-mini">30 derniers jours, règles publiées</span></div><div class="fi-cb">
      <div class="fi-bud-l"><span>Coût estimé <b>${esc(B.cout)}</b></span><span class="fi-mu">plafond : ${esc(B.plafond)} du CA carte</span></div>
      <div class="fi-bud"><i class="${B.depasse ? 'ko' : ''}" style="width:${B.largeur}%"></i><u style="left:${B.repere}%"></u></div>
      <div class="fi-mu s">${esc(B.pct)} des ${esc(B.ca)} des clients carte${B.depasse ? ' — <b class="ko">au-dessus du plafond</b>' : ''}. Hypothèses : ${esc(B.hypotheses)}.${B.brouillon ? ' Les brouillons ne comptent qu’une fois publiés.' : ''}</div></div></div>
  </div></div>`;
}

function tplClients(f, x){
  const { esc } = x;
  if (f.chargementC) { return '<div class="fi-carte fi-attente">Lecture des clients…</div>'; }
  if (f.indispoC) { return `<div class="fi-carte fi-attente">${esc(f.indispoC)}</div>`; }
  if (!f.rf) { return ''; }
  const tuiles = `<div class="fi-tuiles">${f.tuiles.map(([k, v, s, cls]) => `<div class="fi-tuile ${cls || ''}"><div class="k">${esc(k)}</div><div class="v">${esc(v)}</div><div class="s">${esc(s)}</div></div>`).join('')}</div>`;
  const R = f.rf;
  const grille = `<div class="fi-rf"><span></span>${R.colonnes.map(c => `<span class="fi-rf-h">${esc(c)}</span>`).join('')}
    ${R.cases.map((ligne, l) => `<span class="fi-rf-l">${esc(R.lignes[l])}</span>${ligne.map(k => `<button ${x.A(k.choisir)} title="${esc(k.titre)}" class="fi-rf-c${k.on ? ' on' : ''}${k.ar ? ' ar' : ''}${k.t > 0.55 ? ' fonce' : ''}" style="--t:${k.t.toFixed(3)}"><b>${esc(k.n)}</b>${k.lab ? `<small>${esc(k.lab)}</small>` : ''}</button>`).join('')}`).join('')}</div>
    <div class="fi-rf-axe"><span>← vu récemment</span><span>dernière visite</span><span>plus vu depuis longtemps →</span></div>
    <div class="fi-mu s" style="margin:8px 0 0 92px">en ligne : visites par mois (moyenne sur 90 jours) · les ${esc(R.nouveaux)} nouveaux (moins de 30 jours) sont lus à part</div>`;
  const D = f.detail;
  const detail = `<div class="fi-det"><div class="fi-puces">${f.segPuces.map(p => `<button ${x.A(p.choisir)} class="fi-chip ${p.cls}${p.on ? ' on' : ''}">${esc(p.nom)} · ${esc(p.n)}</button>`).join('')}</div>
    <div class="fi-det-t"><span class="fi-chip ${D.cls} on">${esc(D.nom)}</span><b>${esc(D.n)}</b><span class="fi-mu">clients · ${esc(D.joursSans)}</span></div>
    <div class="fi-mu s">${esc(D.def)}</div>
    <div class="fi-cap">Ce qu’ils achètent</div><div class="fi-mix">${D.mix.map(m => m.v ? `<i class="${m.cls}" style="flex:${m.v}" title="${esc(m.nom)} ${esc(m.txt)}"></i>` : '').join('')}</div>
    <div class="fi-leg">${D.mix.filter(m => m.v >= 12).map(m => `<span><i class="${m.cls}"></i>${esc(m.nom)} ${esc(m.txt)}</span>`).join('')}</div>
    <table class="fi-tab mini"><tbody>${D.lignes.map(([k, v]) => `<tr><td class="fi-mu">${esc(k)}</td><td class="n">${esc(v)}</td></tr>`).join('')}</tbody></table>
    ${D.lecture ? `<div class="fi-lecture">${esc(D.lecture)}</div>` : ''}
    <div class="fi-act">${D.avancer ? `<button class="fi-btn p" ${x.A(D.avancer)}${D.envoi ? ' disabled' : ''}>Avancer le bon à 21 jours</button>` : ''}<button class="fi-btn" ${x.A(D.versProgramme)}>Voir les règles</button></div></div>`;
  const T = f.trans;
  const trans = `<table class="fi-coh"><thead><tr><th class="g">Il y a 30 jours ↓ · aujourd’hui →</th>${T.noms.map(n => `<th>${esc(n)}</th>`).join('')}</tr></thead><tbody>
    ${T.lignes.map(l => `<tr><td class="g">${esc(l.nom)}<small>${esc(l.total)}</small></td>${l.cases.map(k => `<td title="${esc(k.titre)}" class="${k.sens}${k.sens !== 'reste' && k.a > 0.6 ? ' fonce' : ''}" style="--a:${k.a.toFixed(3)}">${esc(k.txt)}</td>`).join('')}</tr>`).join('')}</tbody></table>
    <div class="fi-leg" style="margin-top:8px"><span><i class="reste"></i>restés</span><span><i class="monte"></i>montés</span><span><i class="descend"></i>descendus</span></div>
    ${T.lecture ? `<div class="fi-lecture">${esc(T.lecture)}</div>` : ''}`;
  const mix = `${f.mixSeg.map(s => `<div class="fi-mixl"><b>${esc(s.nom)}</b><div class="fi-mix">${s.mix.map(m => m.v ? `<i class="${m.cls}" style="flex:${m.v}" title="${esc(m.titre)}"></i>` : '').join('')}</div><span>${esc(s.panier)}</span></div>`).join('')}
    <div class="fi-leg">${f.missions.map(m => `<span title="${esc(m.def)}"><i class="${m.cls}"></i>${esc(m.nom)}</span>`).join('')}</div>
    ${f.mixLecture ? `<div class="fi-lecture">${esc(f.mixLecture)}</div>` : ''}`;
  return `<div class="fi-col">${tuiles}
    <div class="fi-carte"><div class="fi-ch"><span class="fi-titre">Récence × fréquence</span><span class="fi-mini">chaque case : des clients — une case ouvre son segment</span></div>
      <div class="fi-cb fi-rfg"><div>${grille}</div>${detail}</div></div>
    <div class="fi-g2"><div class="fi-carte"><div class="fi-ch"><span class="fi-titre">D’un mois à l’autre</span><span class="fi-mini">qui monte, qui descend</span></div><div class="fi-cb">${trans}</div></div>
      <div class="fi-carte"><div class="fi-ch"><span class="fi-titre">Ce qu’achète chaque segment</span><span class="fi-mini">part des tickets par type d’achat · panier moyen</span></div><div class="fi-cb">${mix}</div></div></div>
    <div class="fi-mu s">${esc(f.resume)}</div></div>`;
}

export function tplFidelite(c, x){
  const { esc } = x, f = c.fid;
  if (!f) { return ''; }
  const barre = `<div class="fi-barre"><div class="fi-ongl"><button ${x.A(f.ongletP)} class="${f.onglet === 'p' ? 'on' : ''}">Programme${f.nbRegles != null ? ` <i>${f.nbRegles} règles</i>` : ''}</button><button ${x.A(f.ongletC)} class="${f.onglet === 'c' ? 'on' : ''}">Clients · récurrence et type d’achat${f.nbActifs != null ? ` <i>${f.nbActifs} actifs</i>` : ''}</button></div>
    <span class="fi-esp"></span><div class="fi-seg">${f.portees.map(p => `<button ${x.A(p.choisir)} class="${p.on ? 'on' : ''}">${esc(p.nom)}</button>`).join('')}</div>
    ${f.onglet === 'p' && f.nbModifs ? `<button class="fi-btn" ${x.A(f.annuler)}>Annuler</button><button class="fi-btn p" ${x.A(f.publier)}${f.envoi ? ' disabled' : ''}>${f.envoi ? 'Publication…' : 'Publier ' + f.nbModifs + ' modification' + (f.nbModifs > 1 ? 's' : '')}</button>` : ''}</div>`;
  const essai = f.test ? '<div class="fi-essai">Jeu d’essai : aucune visite carte en base (table ceo_fid_visite). Les chiffres viennent de clients générés, passés par les mêmes calculs que les vraies visites ; ils deviennent réels dès que les visites arrivent.</div>' : '';
  const msg = f.msg ? `<div class="fi-msg${f.msg.ko ? ' ko' : ''}">${esc(f.msg.txt)}<button ${x.A(f.fermerMsg)} aria-label="Fermer">×</button></div>` : '';
  return `<div data-screen="fidelite" class="fi">${barre}${essai}${msg}${f.onglet === 'c' ? tplClients(f, x) : tplProgramme(f, x)}</div>`;
}
