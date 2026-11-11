/* Cockpit CEO — gabarit de l'écran Fidélité (ERP franchisé › Fidélité, #/fidelity). Les valeurs viennent de
 * fidelite-vals.js (`c.fid`) ; le style de fidelite.css (préfixe fi-).
 *
 * Écriture sobre, à la manière des Réglages d'Apple (demande du 24/10/2026) : des listes groupées, une ligne
 * par règle (titre court, résumé en clair, interrupteur) qui s'ouvre au clic pour se modifier ; les brouillons
 * se publient depuis une barre flottante. Programme : maquette C ; Clients : maquette B. */

const JOURS_L = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const JOURS_C = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];

/** La phrase d'une règle ouverte : du texte, et des valeurs modifiables sur place. */
function phraseHtml(M, x){
  const { esc } = x;
  let h = '';
  M.forEach((m, i) => {
    let s = '';
    if (m.t === 'txt') { s = `<span class="fi-t">${esc(m.v)}</span>`; }
    else if (m.t === 'num') { s = `<span class="fi-champ"><input type="number" class="fi-in" style="width:${Math.max(36, m.w - 6)}px" value="${esc(m.v)}" step="${m.pas || 1}" min="0" ${x.I(m.set)}>${m.u ? `<span class="fi-u">${esc(m.u)}</span>` : ''}</span>`; }
    else if (m.t === 'txtin') { s = `<input type="text" class="fi-in t" style="width:${m.w}px" value="${esc(m.v)}" placeholder="${esc(m.ph)}" ${x.I(m.set)}>`; }
    else if (m.t === 'sel') { s = `<select class="fi-in s" ${x.C(m.set)}>${m.opts.map(o => `<option value="${esc(o[0])}"${String(o[0]) === String(m.v) ? ' selected' : ''}>${esc(o[1])}</option>`).join('')}</select>`; }
    else if (m.t === 'jours') { s = `<span class="fi-jours">${JOURS_C.map((j, k) => `<button ${x.A(() => m.basculer(k))} class="${m.v.includes(k) ? 'on' : ''}" title="${JOURS_L[k]}">${j}</button>`).join('')}</span>`; }
    else if (m.t === 'heure') { s = `<input type="text" inputmode="numeric" maxlength="5" class="fi-in h" value="${esc(m.v)}" placeholder="hh:mm" ${x.C(m.set)}>`; }
    else if (m.t === 'date') { s = `<input type="date" class="fi-in d" value="${esc(m.v)}" ${x.C(m.set)}>`; }
    else if (m.t === 'pastille') { s = `<i class="fi-pastille" style="background:${esc(m.v)}"></i>`; }
    const colle = m.t === 'txt' && /^[,.]/.test(m.v);
    h += (i && !colle ? ' ' : '') + s;
  });
  return h;
}

function ligneRegle(r, x){
  const { esc } = x;
  const etat = (r.actif ? '' : ' off') + (r.supprimer ? ' sup' : '') + (r.ouvert ? ' ouvert' : '');
  const tog = r.basculer ? `<button class="fi-tog${r.actif ? ' on' : ''}" ${x.A(r.basculer)} role="switch" aria-checked="${r.actif}" title="${r.actif ? 'Désactiver' : 'Activer'}"></button>` : '<span class="fi-tog on fixe" title="Toujours active"></span>';
  const edit = r.ouvert ? `<div class="fi-edit"><div class="fi-ph">${phraseHtml(r.morceaux, x)}</div>
    <div class="fi-edit-pied">${r.portee ? `<label class="fi-portee">S’applique à <select class="fi-in s" ${x.C(r.portee.set)}>${r.portee.opts.map(o => `<option value="${esc(o[0])}"${o[0] === r.portee.v ? ' selected' : ''}>${esc(o[1])}</option>`).join('')}</select></label>` : '<span></span>'}
      <span class="fi-edit-act">${r.retirer ? `<button class="fi-lien rouge" ${x.A(r.retirer)}>${r.supprimer ? 'Garder la règle' : (r.nouvelle ? 'Annuler' : 'Retirer la règle')}</button>` : ''}<button class="fi-lien fort" ${x.A(r.fermer)}>OK</button></span></div></div>` : '';
  return `<div class="fi-row${etat}">
    <button class="fi-row-main" ${x.A(r.ouvrir)} aria-expanded="${r.ouvert}"><span class="fi-ic" style="--c:${esc(r.ic.couleur)}">${esc(r.ic.txt)}</span>
      <span class="fi-row-txt"><b>${r.modifiee ? '<i class="fi-pt" title="modifiée, pas encore publiée"></i>' : ''}${esc(r.titre)}</b><span>${esc(r.resume)}</span></span>
      <span class="fi-row-stat${r.statCls === 'ko' ? ' ko' : ''}">${esc(r.stat)}</span><span class="fi-chev">›</span></button>
    ${tog}</div>${edit}`;
}

function tplProgramme(f, x){
  const { esc } = x;
  if (f.chargement) { return '<div class="fi-vide">Lecture du programme…</div>'; }
  if (f.indispo) { return `<div class="fi-vide">${esc(f.indispo)}</div>`; }
  const groupes = f.blocs.map(b => `<section class="fi-grp"><h3>${esc(b.titre)}</h3><div class="fi-liste">
    ${b.regles.length ? b.regles.map(r => ligneRegle(r, x)).join('') : '<div class="fi-row vide">Aucune règle pour ce magasin.</div>'}
    ${b.ajouts.map(([t, fn]) => `<button class="fi-add" ${x.A(fn)}><span>+</span>${esc(t)}</button>`).join('')}
    ${b.declencheurs ? `<label class="fi-add"><span>+</span><select ${x.C(b.declencheurs.choisir)}>${b.declencheurs.opts.map(o => `<option value="${esc(o[0])}">${esc(o[1])}</option>`).join('')}</select></label>` : ''}
  </div><p class="fi-grp-pied">${esc(b.sous)}</p></section>`).join('');
  const A = f.app || {};
  const tel = `<div class="fi-tel"><div class="fi-ecran">
    <div class="fi-pass"><div class="fi-pass-h"><span>L’Atelier by</span><span>Fidélité</span></div>
      <div class="fi-pass-v">${A.solde}<small> / ${A.jauge} points</small></div>
      <div class="fi-pass-j"><i style="width:${A.pct}%"></i></div><div class="fi-pass-s">Encore ${A.reste} points pour un bon de ${esc(A.bon)}</div></div>
    ${A.creneau ? `<div class="fi-notif"><b>Aujourd’hui</b>${esc(A.creneau.replace(/^Aujourd’hui /, ''))}</div>` : ''}
    <div class="fi-tel-k">Cartes</div>
    ${(A.cartes || []).map(k => `<div class="fi-tel-carte" style="--c:${esc(k.couleur)}"><b>${esc(k.nom)}</b><span>${Array.from({ length: k.n }, (_, i) => `<i class="${i < k.faits ? 'f' : ''}"></i>`).join('')}</span></div>`).join('') || '<div class="fi-tel-carte vide">Aucune carte active</div>'}
    <div class="fi-tel-k">Portefeuille</div>
    <div class="fi-tel-p"><div><b>${esc(A.bon)}</b><span>jusqu’au ${esc(A.bonFin)}</span></div>${A.cadeau ? `<div><b>Cadeau</b><span>${esc(A.cadeau)}</span></div>` : ''}</div>
  </div></div>`;
  const B = f.budget || {};
  const R = 34, C = 2 * Math.PI * R;
  const anneau = `<svg viewBox="0 0 84 84" width="84" height="84" aria-hidden="true"><circle cx="42" cy="42" r="${R}" fill="none" stroke="var(--fi-fill)" stroke-width="8"/>
    <circle cx="42" cy="42" r="${R}" fill="none" stroke="${B.depasse ? 'var(--fi-rouge)' : 'var(--color-primary)'}" stroke-width="8" stroke-linecap="round" stroke-dasharray="${(C * (B.part || 0)).toFixed(1)} ${C.toFixed(1)}" transform="rotate(-90 42 42)"/></svg>`;
  return `<div class="fi-g21"><div class="fi-col">${groupes}</div><aside class="fi-col fi-aside">
    <section class="fi-grp"><h3>Ce que voit le client</h3>${tel}</section>
    <section class="fi-grp"><h3>Simulateur</h3><div class="fi-liste">
      ${f.profils.length ? f.profils.map(p => `<div class="fi-sim"><div><b>${esc(p.nom)}</b><span>${esc(p.mission)} · ${esc(p.sous)}</span></div><div class="fi-sim-v"><b>${esc(p.jours)} j</b><span>entre deux bons · rend <em class="${p.fort ? 'fort' : ''}">${esc(p.rend)}</em></span></div></div>`).join('') : '<div class="fi-row vide">Pas assez de clients pour dégager des profils.</div>'}
    </div><p class="fi-grp-pied">Trois clients types du réseau, recalculés sur les règles en cours d’écriture.</p></section>
    <section class="fi-grp"><h3>Budget</h3><div class="fi-liste"><div class="fi-bud">${anneau}<div><b>${esc(B.pct)}</b><span>du chiffre des clients carte · plafond ${esc(B.plafond)}</span><span>${esc(B.cout)} sur 30 jours${B.depasse ? ' — <em class="ko">au-dessus du plafond</em>' : ''}</span></div></div></div>
      <p class="fi-grp-pied">Règles publiées. Hypothèses : ${esc(B.hypotheses)}.</p></section>
  </aside></div>`;
}

function tplClients(f, x){
  const { esc } = x;
  if (f.chargementC) { return '<div class="fi-vide">Lecture des clients…</div>'; }
  if (f.indispoC) { return `<div class="fi-vide">${esc(f.indispoC)}</div>`; }
  if (!f.rf) { return ''; }
  const chiffres = `<div class="fi-chiffres">${f.tuiles.map(([k, v, s, cls]) => `<div class="${cls || ''}"><span>${esc(k)}</span><b>${esc(v)}</b><small>${esc(s)}</small></div>`).join('')}</div>`;
  const R = f.rf;
  const grille = `<div class="fi-rf"><span></span>${R.colonnes.map(c => `<span class="fi-rf-h">${esc(c)}</span>`).join('')}
    ${R.cases.map((ligne, l) => `<span class="fi-rf-l">${esc(R.lignes[l])}</span>${ligne.map(k => `<button ${x.A(k.choisir)} title="${esc(k.titre)}" class="fi-rf-c${k.on ? ' on' : ''}${k.t > 0.55 ? ' fonce' : ''}" style="--t:${k.t.toFixed(3)}">${esc(k.n)}${k.ar ? '<i></i>' : ''}</button>`).join('')}`).join('')}</div>
    <div class="fi-rf-axe"><span>vu récemment</span><span>dernière visite →</span></div>
    <p class="fi-note-s">En ligne, les visites par mois (moyenne sur 90 jours). Point rouge : à risque. Les ${esc(R.nouveaux)} nouveaux sont lus à part.</p>`;
  const D = f.detail;
  const detail = `<div class="fi-det">
    <div class="fi-segs">${f.segPuces.map(p => `<button ${x.A(p.choisir)} class="${p.on ? 'on' : ''}">${esc(p.nom)}</button>`).join('')}</div>
    <div class="fi-det-n"><b>${esc(D.n)}</b><span>${esc(D.nom).toLowerCase()} · ${esc(D.joursSans)}</span></div>
    <p class="fi-note-s">${esc(D.def)}</p>
    <div class="fi-mix">${D.mix.map(m => m.v ? `<i class="${m.cls}" style="flex:${m.v}" title="${esc(m.nom)} ${esc(m.txt)}"></i>` : '').join('')}</div>
    <div class="fi-leg">${D.mix.filter(m => m.v >= 12).map(m => `<span><i class="${m.cls}"></i>${esc(m.nom)} ${esc(m.txt)}</span>`).join('')}</div>
    <div class="fi-kv">${D.lignes.map(([k, v]) => `<div><span>${esc(k)}</span><b>${esc(v)}</b></div>`).join('')}</div>
    ${D.lecture ? `<p class="fi-lecture">${esc(D.lecture)}</p>` : ''}
    <div class="fi-act">${D.avancer ? `<button class="fi-cta" ${x.A(D.avancer)}${D.envoi ? ' disabled' : ''}>Avancer le bon à 21 jours</button>` : ''}<button class="fi-lien fort" ${x.A(D.versProgramme)}>Voir les règles ›</button></div></div>`;
  const flux = `<div class="fi-liste">${(f.flux || []).map(l => `<div class="fi-flux"><div><b>${esc(l.nom)}</b><span>${esc(l.total)} clients il y a 30 jours</span></div>
    <div class="fi-flux-b"><i class="m" style="flex:${l.monte.toFixed(2)}"></i><i class="r" style="flex:${l.reste.toFixed(2)}"></i><i class="d" style="flex:${l.descend.toFixed(2)}"></i></div>
    <div class="fi-flux-v"><span class="vert">${esc(l.monteTxt)}</span><span>${esc(l.resteTxt)}</span><span class="rouge">${esc(l.descendTxt)}</span></div></div>`).join('')}</div>
    <p class="fi-grp-pied">Vert : passés à un segment plus fidèle. Rouge : descendus. ${esc(f.trans.lecture)}</p>`;
  const mix = `<div class="fi-liste">${f.mixSeg.map(s => `<div class="fi-mixl"><b>${esc(s.nom)}</b><div class="fi-mix">${s.mix.map(m => m.v ? `<i class="${m.cls}" style="flex:${m.v}" title="${esc(m.titre)}"></i>` : '').join('')}</div><span>${esc(s.panier)}</span></div>`).join('')}
    <div class="fi-leg pad">${f.missions.map(m => `<span title="${esc(m.def)}"><i class="${m.cls}"></i>${esc(m.nom)}</span>`).join('')}</div></div>
    <p class="fi-grp-pied">${esc(f.mixLecture)}</p>`;
  return `<div class="fi-col">${chiffres}
    <section class="fi-grp"><h3>Récence × fréquence</h3><div class="fi-liste fi-rfg"><div>${grille}</div>${detail}</div></section>
    <div class="fi-g2"><section class="fi-grp"><h3>D’un mois à l’autre</h3>${flux}</section><section class="fi-grp"><h3>Ce qu’achète chaque segment</h3>${mix}</section></div>
    <p class="fi-note-s centre">${esc(f.resume)}</p></div>`;
}

export function tplFidelite(c, x){
  const { esc } = x, f = c.fid;
  if (!f) { return ''; }
  const shop = f.portees.find(p => p.on) || f.portees[0];
  const haut = `<div class="fi-haut"><div class="fi-segc"><button ${x.A(f.ongletP)} class="${f.onglet === 'p' ? 'on' : ''}">Programme</button><button ${x.A(f.ongletC)} class="${f.onglet === 'c' ? 'on' : ''}">Clients</button></div>
    <label class="fi-popup"><select ${x.C(e => { const p = f.portees.find(q => q.id === e.target.value); if (p) { p.choisir(); } })}>${f.portees.map(p => `<option value="${esc(p.id)}"${p.on ? ' selected' : ''}>${esc(p.nom)}</option>`).join('')}</select><span>${esc(shop ? shop.nom : 'Réseau')}</span></label></div>`;
  const essai = f.test ? '<p class="fi-essai"><i></i>Données d’essai — aucune visite carte en base pour l’instant. Les calculs sont ceux qui tourneront sur les vraies visites.</p>' : '';
  const msg = f.msg ? `<div class="fi-toast${f.msg.ko ? ' ko' : ''}">${esc(f.msg.txt)}<button ${x.A(f.fermerMsg)} aria-label="Fermer">×</button></div>` : '';
  const dock = f.onglet === 'p' && f.nbModifs ? `<div class="fi-dock"><span>${f.nbModifs} modification${f.nbModifs > 1 ? 's' : ''} non publiée${f.nbModifs > 1 ? 's' : ''}</span><button class="fi-lien" ${x.A(f.annuler)}>Annuler</button><button class="fi-cta" ${x.A(f.publier)}${f.envoi ? ' disabled' : ''}>${f.envoi ? 'Publication…' : 'Publier'}</button></div>` : '';
  return `<div data-screen="fidelite" class="fi">${haut}${essai}${msg}${f.onglet === 'c' ? tplClients(f, x) : tplProgramme(f, x)}${dock}</div>`;
}
