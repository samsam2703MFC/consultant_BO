/* Cockpit CEO — gabarit de Catalogue › Structure de gamme (valeurs : structure-gamme.js).
 * Design system commun : rectangles aux coins arrondis, pas de gras hors chiffres clés, une seule action
 * principale par vue, un état toujours dit par un mot. La répartition « 80 % » se lit sur une barre
 * segmentée (un tout et ses parts), jamais sur un graphique à deux échelles. */
import { nb, eur, keur, pc, pl } from './structure-gamme.js';

/** Douze mois en barres fines : en rouge réalisé les mois où la référence est retenue (saison, période). */
function spark(g, p, esc){
  if (!p.u) { return p.recente ? `<span class="sg-mini">${nb(p.v1)} pièces le mois dernier</span>` : `<span class="sg-mini">moins de ${g.plancher} pièces</span>`; }
  const max = Math.max(...p.profil) || 1, w = 7, gap = 2, h = 22;
  const on = new Set(p.nature === 'permanent' ? [] : (p.mois || []));
  let s = `<svg width="${12 * (w + gap)}" height="${h}" viewBox="0 0 ${12 * (w + gap)} ${h}" aria-hidden="true">`;
  p.profil.forEach((v, i) => {
    const bh = Math.max(v > 0 ? 1.5 : 0, v / max * (h - 2));
    s += `<rect x="${i * (w + gap)}" y="${h - bh}" width="${w}" height="${bh}" rx="1.5" fill="${on.has(i) ? 'var(--chart-real)' : (on.size ? 'var(--chart-prev)' : '#C9868F')}"><title>${esc(g.mois[i] || '')} : ${nb(p.u[i])} pièces</title></rect>`;
  });
  return s + '</svg>';
}

function statut(g, p){
  const t = g.mot(p, p.statut);
  const cls = { standard: 'std', saison: 'sai', periode: 'sai', desactiver: 'off', arbitrer: 'arb', observation: 'obs', garder: 'obs' }[p.statut] || 'hors';
  return `<span class="sg-st sg-st--${cls}">${t}</span>`;
}

/** Les trois commandes d'une ligne : « Désactiver » et « Obligatoire » en interrupteurs, la saison en liste.
 *  Désactiver n'existe que pour une référence proposée sous le seuil ; une référence désactivée n'a ni
 *  obligatoire ni saison ; la saison ne compte que si la référence est obligatoire. */
function interrupteur(on, actif, fn, titre, x){
  return `<button type="button" class="sg-tog${on ? ' is-on' : ''}" role="switch" aria-checked="${on}" ${actif ? x.A(fn) : 'disabled'} title="${x.esc(titre)}"><span class="sg-tog__piste"></span><span class="sg-tog__mot">${on ? 'Oui' : 'Non'}</span></button>`;
}
function commandes(g, p, x){
  const { esc } = x;
  if (!p.actif) { return `<td colspan="3" class="muted" style="font-size:11.5px">inactive au catalogue${p.statut === 'periode' ? ' — à réactiver pour ' + esc(g.PE[p.periode].nom) : ''}</td>`; }
  const e = g.etat(p);
  const o = (v, l) => `<option value="${v}"${e.saison === v ? ' selected' : ''}>${esc(l)}</option>`;
  return `<td>${interrupteur(e.desactiver, e.desactivable, g.basculerDesactiver(p), e.desactivable ? (e.desactiver ? 'Garder au catalogue' : 'Désactiver (la proposition)') : 'Au-dessus du seuil : le retrait n’est pas proposé', x)}</td>
    <td>${interrupteur(e.obligatoire, !e.desactiver, g.basculerObligatoire(p), e.desactiver ? 'Une référence désactivée n’est pas obligatoire' : (e.obligatoire ? 'Ne plus la rendre obligatoire' : 'La rendre obligatoire'), x)}</td>
    <td><select class="sg-choix${p.decide ? ' chg' : ''}" ${e.desactiver || !e.obligatoire ? 'disabled' : x.C(g.choisirSaison(p))} aria-label="Saison de ${esc(p.nom)}">
      ${o('', 'Toute l’année')}<optgroup label="Saisons">${g.R.saisons.map(s => o(s.id, s.nom)).join('')}</optgroup><optgroup label="Périodes">${g.R.periodes.map(z => o(z.id, z.nom)).join('')}</optgroup></select></td>`;
}

/** Les pièces vendues par saison, la plus forte en évidence. */
function saisonsQte(g, p){
  if (!p.parSaison) { return g.R.saisons.map(() => '<td class="num muted">—</td>').join(''); }
  const max = Math.max(...Object.values(p.parSaison));
  return g.R.saisons.map(s => { const v = p.parSaison[s.id]; return `<td class="num"><span class="${v && v === max ? 'sg-fort-sai' : v ? '' : 'muted'}">${v ? nb(v) : '0'}</span></td>`; }).join('');
}

/** Une part sous le seuil ne s'arrondit jamais au seuil : 2,98 % s'écrit 2,9 %, pas 3,0 %. */
const pt = (g, x) => x == null ? null : (x < g.regles.seuil / 100 ? Math.floor(x * 1000) / 1000 : x);

const kpi = (lbl, v, note, petit) => `<div class="kpi"><div class="kpi__value">${v}${petit ? `<small class="sg-petit">${petit}</small>` : ''}</div><div class="kpi__label">${lbl}</div><div class="kpi__note">${note}</div></div>`;

export function tplStructure(c, x){
  const { esc } = x;
  const g = c.sg;
  if (!g) { return ''; }
  if (g.chargement) { return `<div class="card sg-vide">Lecture des ventes de douze mois, du catalogue et des coûts…</div>`; }
  if (g.indispo) { return `<div class="card sg-vide">Structure indisponible — ${esc(g.indispo)}.</div>`; }
  const r = g.regles, sa = g.saisie;
  const champ = (k, mode) => `<input class="sg-champ${sa.change(k) ? ' chg' : ''}" inputmode="${mode}" value="${esc(String(sa[k]).replace('.', ','))}" ${x.C(sa.set(k))} aria-label="${k}">`;
  const primaire = g.vue === '4'
    ? `<button class="btn btn--primary" ${x.A(g.demanderDesactiver)} ${g.demanderDesactiver && !g.busy ? '' : 'disabled'}>Désactiver ${pl(g.aDesactiver.length, 'référence')}</button>`
    : `<button class="btn btn--primary" ${x.A(g.demanderAppliquer)} ${g.busy ? 'disabled' : ''}>Appliquer à l’assortiment</button>`;
  let h = `<div class="sg">
    <div class="sg-barre">
      <div class="seg">${g.vues.map(v => `<button class="${v.on ? 'is-on' : ''}" ${x.A(v.choisir)}>${esc(v.nom)}${v.n != null ? ' · ' + v.n : ''}</button>`).join('')}</div>
      <span class="sg-sp"></span>
      <button class="btn" ${x.A(g.exporter)}>Exporter</button>${primaire}
    </div>
    <div class="sg-barre sg-regles">
      <div class="seg"><button class="${r.base === 'ca' ? 'is-on' : ''}" ${x.A(sa.base('ca'))}>Chiffre d’affaires</button><button class="${r.base === 'mg' ? 'is-on' : ''}" ${x.A(sa.base('mg'))}>Marge nette</button></div>
      <span class="sg-regle" style="margin-left:4px">Standard&nbsp;: ${champ('std', 'decimal')} % ${g.baseCourt}, ${champ('max', 'numeric')} max</span>
      <span class="sg-regle">Saisons, périodes&nbsp;: ${champ('sai', 'numeric')} max</span>
      <span class="sg-regle">Désactiver sous ${champ('seuil', 'decimal')} % de la catégorie</span>
      <button class="btn btn--sm" ${x.A(g.rafraichir)} ${g.lecture ? 'disabled' : ''} title="Relire les ventes et recalculer avec ces valeurs">${g.lecture ? 'Lecture…' : 'Rafraîchir'}</button>
    </div>
    <div class="sg-barre sg-src">
      ${g.essai ? `<span class="tag-warn">Essai — le réseau garde : désactiver sous ${g.vx(g.enreg.seuil)}, standard ${g.vx(g.enreg.std)} ${g.enreg.base === 'mg' ? 'de la marge' : 'du CA'}, ${g.enreg.max} max, saisons ${g.enreg.sai} max</span>
        <button class="linklike" ${x.A(g.enregistrer)} ${g.busy ? 'disabled' : ''}>Enregistrer ces règles pour le réseau</button><button class="linklike" ${x.A(g.revenir)}>Revenir aux règles du réseau</button>` : ''}
      ${g.msg ? `<span class="${g.msg.ko ? 'error' : 'sg-ok'}">${esc(g.msg.txt)}</span>` : ''}
      <span class="sg-sp"></span>
      <span>Ventes réseau du ${esc(g.du)} au ${esc(g.au)} · ${g.magasins} magasins · lu le ${esc(g.lu)}</span>
    </div>
    ${g.resultat ? `<div class="sg-encart sg-encart--neutre"><span><span class="sg-fort">${g.resultat.quoi === 'desactiver' ? 'Retrait du catalogue' : 'Assortiment obligatoire'} : ${esc(g.resultat.resume || 'fait')}.</span>
      ${(g.resultat.refuses || []).length ? ' Refusées : ' + g.resultat.refuses.slice(0, 12).map(f => esc(f.nom) + ' (' + esc(f.motif) + ')').join(', ') + (g.resultat.refuses.length > 12 ? '…' : '') + '.' : ''}</span>
      <span class="sg-sp"></span><button class="linklike" ${x.A(g.resultat.fermer)}>Fermer</button></div>` : ''}`;
  h += g.vue === '2' ? vue2(g, x) : g.vue === '3' ? vue3(g, x) : g.vue === '4' ? vue4(g, x) : vue1(g, x);
  h += confirmation(g, x);
  return h + '</div>';
}

/* --- 1. Par catégorie ------------------------------------------------------------------ */
function vue1(g, x){
  const { esc } = x;
  const R = g.R, T = g.T, r = g.regles, L = g.lecture1;
  const cases = (l, ids) => '<span class="sg-cases">' + ids.map(s => { const n = l[s.id].length; return `<span class="${n ? 'on' : ''}" title="${esc(s.nom)}">${n || '–'}</span>`; }).join('') + '</span>';
  let lignes = '';
  R.groupes.forEach(gr => {
    lignes += `<tr class="sg-grp"><td>${esc(gr.grp)}</td><td class="num">${eur(gr.ca)}</td><td colspan="6"></td></tr>`;
    gr.cats.forEach(C => {
      const off = C.desactiver, caOff = off.reduce((s, p) => s + (p.caPer || 0), 0), cv = C.couverture;
      lignes += `<tr class="row-link" ${x.A(g.ouvrir(C.cat))}>
        <td class="sg-nom">${esc(C.cat)}<span class="sub">${pl(C.actives, 'référence active', 'références actives')}${C.refs.some(p => p.doublon) ? ' · doublon' : ''}${C.refs.some(p => p.decide) ? ' · ' + C.refs.filter(p => p.decide).length + ' décidée(s)' : ''}</span></td>
        <td class="num">${eur(C.ca)}</td>
        <td>${C.std.length ? `${C.std.length}<span class="muted" style="font-size:11px"> / ${r.max}</span>&nbsp;&nbsp;<span class="sg-couv"><i style="width:${Math.min(100, (cv || 0) * 100)}%"></i><b style="left:${r.std}%"></b></span>&nbsp; <span class="muted" style="font-size:11.5px">${pc(cv)}</span>${C.plafonne ? ' <span class="sg-mini warn">plafond</span>' : ''}` : '<span class="muted">aucune vendue toute l’année</span>'}</td>
        <td>${cases(C.saisons, g.R.saisons)}</td>
        <td>${cases(C.periodes, g.R.periodes)}</td>
        <td class="num">${off.length ? off.length + '<span class="sub">' + (C.ca ? pc(caOff / C.ca) + ' du CA' : '') + '</span>' : '<span class="muted">—</span>'}</td>
        <td class="num">${C.arbitrer.length + C.observation.length ? (C.arbitrer.length || '—') + (C.observation.length ? '<span class="sub">+ ' + C.observation.length + ' en observation</span>' : '') : '<span class="muted">—</span>'}</td>
        <td class="sg-chev">›</td></tr>`;
    });
  });
  return `
    <div class="kpi-row sg-kpis">
      ${kpi('Références actives', nb(T.actives), 'dont ' + T.hors + ' hors structure : B2B, bundles, extras')}
      ${kpi('Standard', nb(T.standard), pc(T.caStandard / T.caStructure) + ' du CA · ' + r.max + ' max par catégorie')}
      ${kpi('Saisons', nb(T.saison), '4 saisons · ' + r.sai + ' max par catégorie')}
      ${kpi('Périodes', nb(T.periode), 'Noël, Nouvel An, Pâques' + (T.aReactiver ? ' · ' + T.aReactiver + ' à réactiver' : ''))}
      ${kpi('À désactiver', nb(T.desactiver), 'sous ' + g.vx(r.seuil) + ' · ' + pc(T.caDesactiver / T.caStructure) + ' du CA')}
      ${kpi('À arbitrer', nb(T.arbitrer), '+ ' + T.observation + ' en observation' + (T.garder ? ' · ' + T.garder + ' gardée(s) hors standard' : ''))}
    </div>
    <div class="sg-encart sg-encart--neutre"><span>
      <span class="sg-fort">Ce que la lecture corrige.</span> ${L.fusion ? L.fusion + ' références ont changé d’identifiant (les tartes Ø 28 cm en octobre) : leur historique est repris. ' : ''}${L.doublons.length ? 'Doublons à fusionner : ' + L.doublons.length + ' noms portés par deux références actives (' + esc(L.doublons.join(', ')) + '). ' : ''}Assortiment obligatoire d’aujourd’hui : ${L.musts} références, dont ${L.mustStd} retenues au standard.
    </span></div>
    <section class="card table-card sg-table"><div class="table-scroll"><table>
      <thead><tr><th>Catégorie</th><th class="num">CA 12 mois</th><th>Standard · part couverte</th>
        <th><span class="sg-cases-tete">${['Hiv.', 'Pri.', 'Été', 'Aut.'].map(s => '<span>' + s + '</span>').join('')}</span></th>
        <th><span class="sg-cases-tete">${['Noël', 'N.An', 'Pâq.'].map(s => '<span>' + s + '</span>').join('')}</span></th>
        <th class="num">À désactiver</th><th class="num">À arbitrer</th><th></th></tr></thead>
      <tbody>${lignes}</tbody></table></div></section>
    <p class="sg-note">Standard : les références vendues toute l’année, de la plus forte à la plus faible ${g.baseCourt}, jusqu’à ${g.vx(r.std)} de leur catégorie, ${r.max} au plus — une référence sous ${g.vx(r.seuil)} n’y entre pas. Saisons et périodes : la même règle sur les mois de la saison, ${r.sai} au plus. À désactiver : sous ${g.vx(r.seuil)} du CA de la catégorie sur les mois où la référence se vend ; une nouveauté de moins de six mois est observée, jamais retirée. Une référence est saisonnière quand trois mois de suite font presque rien de son volume et qu’une ou deux saisons voisines en font 70 % ; une période, quand un mois daté (décembre, janvier, mars-avril) en fait l’essentiel. Les mois sont lus en part du volume réseau : un magasin qui ouvre et le mois en cours ne déforment pas les saisons.</p>`;
}

/* --- 2. Une catégorie ouverte ---------------------------------------------------------------- */
function vue2(g, x){
  const { esc } = x;
  const C = g.cat, r = g.regles;
  const perm = C.refs.filter(p => p.nature === 'permanent').sort((a, b) => b.val - a.val);
  const tot = perm.reduce((s, p) => s + p.val, 0) || 1;
  const segs = perm.map(p => `<i class="${p.statut === 'standard' ? 'std' : ''}" style="width:${p.val / tot * 100}%" title="${esc(p.nom)} : ${pc(p.val / tot, 1)}"></i>`).join('');
  const off = C.desactiver, caOff = off.reduce((s, p) => s + (p.caPer || 0), 0);
  const chg = C.refs.filter(p => g.alt.get(p.id) && g.alt.get(p.id).statut !== p.statut);
  const ordre = { permanent: 0, saison: 1, periode: 2, observation: 3, faible: 4 };
  const liste = C.refs.slice().sort((a, b) => (ordre[a.nature] - ordre[b.nature]) || ((a.rang || 99) - (b.rang || 99)) || ((b.caPer || 0) - (a.caPer || 0)));
  const nVendues = C.refs.filter(p => p.u).length;
  const lignes = liste.map(p => {
    const a = g.alt.get(p.id), notes = [];
    if ((p.anciens || []).length) { notes.push('reprend l’historique de la réf. ' + p.anciens.map(o => o.id).join(', ')); }
    if (p.doublon) { notes.push('<span class="sg-lien">doublon</span> : même nom dans ' + esc(p.doublon.map(d => d.cat).join(', '))); }
    if (p.nature === 'observation') { notes.push(p.recente ? 'lancée le mois dernier — jugée à 6 mois' : 'vendue depuis ' + esc((g.mois[p.debut] || '').toLowerCase()) + ' — jugée à 6 mois'); }
    if (p.lance && p.nature === 'permanent') { notes.push('lancée en ' + esc((g.mois[p.lance] || '').toLowerCase()) + ' — part mesurée depuis'); }
    if (p.must) { notes.push('obligatoire aujourd’hui'); }
    if (!p.actif) { notes.push('inactive au catalogue'); }
    if (p.nature === 'saison') { notes.push('saison : ' + p.saisons.map(s => g.SA[s].nom.toLowerCase()).join(' et ')); }
    if (p.mgEst) { notes.push('marge estimée (coût absent)'); }
    return `<tr>
      <td class="num muted">${p.rang && p.nature === 'permanent' ? p.rang : ''}</td>
      <td class="sg-nom">${esc(p.nom)}${notes.length ? '<span class="sub">' + notes.join(' · ') + '</span>' : ''}</td>
      <td>${spark(g, p, esc)}</td>
      <td class="num">${p.u ? eur(p.ca) : p.recente ? '<span class="muted">—</span>' : '<span class="muted">≤ ' + eur(g.plancher * (p.prix || 0)) + '</span>'}</td>
      <td class="num">${p.part != null ? pc(pt(g, p.part), 1) : p.recente ? '<span class="muted">—</span>' : '<span class="muted">≤ ' + pc(Math.min(p.partMax, 1), 1) + '</span>'}</td>
      <td class="num muted">${p.nature === 'permanent' ? pc(p.cum) : ''}</td>
      <td class="num">${p.mg != null ? eur(p.mg) : '<span class="muted">—</span>'}</td>
      <td>${p.decide ? `<span class="sg-mini">proposé : ${esc(g.mot(p, p.propose, true))}</span><br>` : ''}${statut(g, p)}</td>
      <td>${a && a.statut !== p.statut ? `<span class="sg-mini warn">${esc(g.mot(a, a.statut))}</span>` : '<span class="muted" style="font-size:11.5px">idem</span>'}</td>
      ${commandes(g, p, x)}</tr>`;
  }).join('');
  return `
    <div class="sg-barre"><button class="linklike" style="padding:0" ${x.A(g.fermerCat)}>‹ Toutes les catégories</button></div>
    <div><div class="screen-subtitle" style="margin:0">${esc(C.cat)}</div>
      <p class="muted" style="font-size:12.5px;margin-top:2px">Groupe ${esc(C.grp)} · ${pl(C.actives, 'référence active', 'références actives')} · CA 12 mois ${eur(C.ca)} · marge nette ${eur(C.mg)}${C.mgEstimees ? ' (dont ' + C.mgEstimees + ' estimée' + (C.mgEstimees > 1 ? 's' : '') + ')' : ''}</p></div>
    <div class="sg-deux">
      <section class="card">
        <h2>Le ${g.baseNom} des ${perm.length} références vendues toute l’année</h2>
        ${perm.length ? `<div class="sg-seg100">${segs}<div class="rep" style="left:${r.std}%"><span>${g.vx(r.std)}</span></div>${C.std.length ? `<div class="lab">Standard (${C.std.length}) : ${pc(C.couverture)}</div>` : ''}</div>
        <div class="sg-legende"><span><i style="background:var(--chart-real)"></i>Standard (${C.std.length} / ${r.max})</span><span><i style="background:var(--chart-prev)"></i>Les autres références vendues toute l’année</span><span><i style="background:var(--chart-goal);width:2px"></i>${g.vx(r.std)} ${g.baseCourt}</span></div>
        <p class="sg-note" style="margin-top:10px">${C.plafonne ? `Le plafond de ${r.max} arrive avant les ${g.vx(r.std)} : les ${r.max} premières font ${pc(C.couverture)}. Il en faudrait ${perm.findIndex(p => p.cum >= r.std / 100) + 1} pour atteindre ${g.vx(r.std)}.` : `Le standard couvre ${pc(C.couverture)}.`}</p>` : '<p class="sg-note">Aucune référence de cette catégorie ne se vend toute l’année : elle vit de ses saisons et périodes.</p>'}
      </section>
      <div class="sg-pile">
        <section class="card sg-carte"><div class="kpi__label">Sous ${g.vx(r.seuil)} de la catégorie</div><div class="kpi__value">${off.length}<small class="sg-petit">${C.ca ? pc(caOff / C.ca) : '—'} du CA</small></div></section>
        <section class="card sg-carte"><div class="kpi__label">Si l’on trie par ${g.autreBase}</div><div class="kpi__value">${chg.length}<small class="sg-petit">${chg.length > 1 ? 'changements' : 'changement'}</small></div><div class="kpi__note">${esc(chg.slice(0, 3).map(p => p.nom).join(', '))}</div></section>
      </div>
    </div>
    ${off.length && C.ca && caOff / C.ca > 0.15 ? `<div class="sg-encart"><span><span class="sg-fort">À trancher pour cette catégorie.</span> Avec ${nVendues} références vendues, la part moyenne d’une référence est de ${pc(1 / nVendues, 1)} : la règle des ${g.vx(r.seuil)} en sort ${off.length}, qui font ensemble ${pc(caOff / C.ca)} du CA de la catégorie. Les garder hors standard ou les retirer, c’est la décision.</span></div>` : ''}
    <section class="card table-card sg-table"><div class="table-scroll"><table>
      <thead><tr><th class="num">#</th><th>Référence</th><th>12 mois</th><th class="num">CA 12 mois</th><th class="num">Part</th><th class="num">Cumul</th><th class="num">Marge nette</th><th>Statut</th><th>Selon ${g.autreBase}</th><th>Désactiver</th><th>Obligatoire</th><th>Saison</th></tr></thead>
      <tbody>${lignes}</tbody></table></div></section>
    <p class="sg-note">Part : le CA de la référence sur les mois où elle se vend, rapporté à celui de la catégorie sur les mêmes mois. Cumul : parmi les références vendues toute l’année, dans l’ordre ${g.baseCourt}. Sous ${g.plancher} pièces en 12 mois, une référence n’est pas lue mois par mois : sa part est un plafond. Une décision s’enregistre pour le réseau et passe au journal.</p>`;
}

/* --- 3. Saisons et périodes ---------------------------------------------------------------- */
function vue3(g, x){
  const { esc } = x;
  const R = g.R;
  const AUJ = new Date(), Y = AUJ.getFullYear(), M0 = AUJ.getMonth();
  const j0 = new Date(Y, M0, 1), j1 = new Date(Y + 1, M0, 1);
  const pos = d => Math.max(0, Math.min(100, (d - j0) / (j1 - j0) * 100));
  const dt = s => new Date(s + 'T12:00:00');
  const paques = annee => { const a = annee % 19, b = Math.floor(annee / 100), c = annee % 100, d = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25), gg = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - gg + 15) % 30, i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7, m = Math.floor((a + 11 * h + 22 * l) / 451), mo = Math.floor((h + l - 7 * m + 114) / 31), jo = ((h + l - 7 * m + 114) % 31) + 1; return new Date(annee, mo - 1, jo, 12); };
  let pq = paques(Y); if (pq < AUJ) { pq = paques(Y + 1); }
  const pq0 = new Date(pq - 21 * 864e5), pq1 = new Date(+pq + 864e5);
  const rec = (a, b) => [Y, Y + 1].flatMap(y => { const u = dt(y + '-' + a), z = dt((b < a ? y + 1 : y) + '-' + b); return z < j0 || u >= j1 ? [] : [[u < j0 ? j0 : u, z > j1 ? j1 : z]]; });
  const bandes = { hiver: rec('12-01', '02-28'), printemps: rec('03-01', '05-31'), ete: rec('06-01', '08-31'), automne: rec('09-01', '11-30'),
    noel: rec('11-15', '12-26'), nouvelan: rec('12-27', '01-15'), paques: [[pq0, pq1]] };
  const NM = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const fdm = NM.map((m, i) => NM[(M0 + i) % 12]);
  const auj = `<div class="auj" style="left:${pos(AUJ)}%"></div>`;
  const n = (k, per) => R.cats.reduce((s, C) => s + (per ? C.periodes[k] : C.saisons[k]).length, 0);
  const nc = (k, per) => R.cats.filter(C => (per ? C.periodes[k] : C.saisons[k]).length).length;
  const dans = (d) => { const j = Math.round((d - AUJ) / 864e5); return j > 0 ? 'ouvre dans ' + j + ' j' : 'en cours'; };
  const etat = k => { const b = bandes[k] && bandes[k][0]; if (!b) { return ''; } return b[0] <= AUJ && AUJ <= b[1] ? 'en cours' : (k === 'paques' ? 'Pâques le ' + pq.toLocaleDateString('fr-BE', { day: 'numeric', month: 'long' }) : dans(b[0])); };
  const ligne = (k, nom, lib, per) => `
    <div class="c">${nom}<span class="muted" style="font-size:11px">${lib}</span></div>
    <div class="c"><div class="sg-piste">${fdm.map((m, i) => `<div class="m" style="left:${i / 12 * 100}%"></div>`).join('')}${(bandes[k] || []).map(([a, b]) => `<div class="b${per ? ' per' : ''}" style="left:${pos(a)}%;width:${Math.max(0.8, pos(b) - pos(a))}%"></div>`).join('')}${auj}</div></div>
    <div class="c" style="justify-content:space-between"><span>${n(k, per)} réf. · ${pl(nc(k, per), 'catégorie')}</span><span class="muted" style="font-size:11.5px">${etat(k)}</span></div>`;
  const cats = R.groupes.flatMap(gr => gr.cats).filter(C => R.saisons.some(s => C.saisons[s.id].length) || R.periodes.some(s => C.periodes[s.id].length));
  const cell = (l, per) => l.length ? `<td class="cel${per ? ' per' : ''}">${l.map(p => `<div>${esc(p.nom)}${p.actif ? '' : ' <span class="sg-mini warn">à réactiver</span>'}</div>`).join('')}</td>` : `<td class="cel vide${per ? ' per' : ''}">—</td>`;
  return `
    <section class="card">
      <h2>Les douze mois qui viennent — quatre saisons, trois périodes</h2>
      <div class="sg-frise">
        <div class="h">Saison ou période</div><div class="h"><div class="sg-mois">${fdm.map((m, i) => `<span style="left:${i / 12 * 100}%">${m}</span>`).join('')}</div></div><div class="h">Retenues</div>
        ${R.saisons.map(s => ligne(s.id, s.nom, s.lib, false)).join('')}
        ${R.periodes.map(s => ligne(s.id, s.nom, s.id === 'paques' ? pq0.toLocaleDateString('fr-BE', { day: 'numeric', month: 'short' }) + ' – ' + pq.toLocaleDateString('fr-BE', { day: 'numeric', month: 'short' }) : s.lib, true)).join('')}
      </div>
      <p class="sg-note" style="margin-top:10px">Trait bordeaux : aujourd’hui. « Appliquer à l’assortiment » rend une référence de saison obligatoire pendant la gamme du panel qui la porte (Hivernale, Printanière, Estivale, Automnale, Noël &amp; Nouvel An, Épiphanie, Pascale) ; une référence de période inactive au catalogue se réactive dans le panel avant sa période.</p>
    </section>
    <section class="card table-card sg-table sg-mat"><div class="table-scroll"><table>
      <thead><tr><th>Catégorie</th>${R.saisons.map(s => `<th>${s.nom}</th>`).join('')}${R.periodes.map(s => `<th class="per">${s.nom}</th>`).join('')}</tr></thead>
      <tbody>${cats.map(C => `<tr><td class="sg-nom">${esc(C.cat)}<span class="sub">${esc(C.grp)}</span></td>${R.saisons.map(s => cell(C.saisons[s.id], false)).join('')}${R.periodes.map(s => cell(C.periodes[s.id], true)).join('')}</tr>`).join('') || '<tr><td colspan="8" class="muted">Aucune référence de saison ou de période retenue.</td></tr>'}</tbody>
    </table></div></section>
    <p class="sg-note">Les ventes sont lues par mois, sur un an : Noël et Nouvel An se séparent par le mois du pic (décembre ou janvier) — les galettes tombent au Nouvel An, les bûches et cougnous à Noël, Saint-Nicolas avec Noël. Une référence de saison sous ${g.vx(g.regles.seuil)} de sa catégorie pendant la saison n’est pas retenue.</p>`;
}

/* --- 4. À désactiver ------------------------------------------------------------------------
 * Les références proposées sous le seuil, catégorie par catégorie. Chaque ligne dit ce qu'elle vend dans
 * chaque saison, et se décide à la liste : désactiver (la proposition), ou la garder — en standard, hors
 * standard, dans une saison ou une période. Une référence gardée reste dans la liste, sa décision affichée. */
function vue4(g, x){
  const { esc } = x;
  const R = g.R, T = g.T, off = g.aDesactiver;
  const proposees = R.produits.filter(p => p.actif && p.propose === 'desactiver');
  const tout = g.deplie === '*';
  let lignes = '';
  R.groupes.forEach(gr => {
    const cs = gr.cats.filter(C => C.refs.some(p => p.actif && p.propose === 'desactiver'));
    if (!cs.length) { return; }
    lignes += `<tr class="sg-grp"><td colspan="10">${esc(gr.grp)}</td></tr>`;
    cs.forEach(C => {
      const l = C.refs.filter(p => p.actif && p.propose === 'desactiver').sort((a, b) => (b.caPer || 0) - (a.caPer || 0));
      const sortent = l.filter(p => p.statut === 'desactiver'), gardees = l.length - sortent.length;
      const ca = sortent.reduce((s, p) => s + (p.caPer || 0), 0);
      const ouv = tout || g.deplie === C.cat;
      lignes += `<tr class="row-link${ouv ? ' sg-sel' : ''}" ${x.A(g.deplier(C.cat))}>
        <td class="sg-nom">${esc(C.cat)}<span class="sub">${ouv ? pl(C.actives, 'référence active', 'références actives') : esc(l.slice(0, 4).map(p => p.nom).join(', ')) + (l.length > 4 ? '…' : '')}</span></td>
        <td></td><td colspan="4"></td>
        <td class="num">${eur(ca)}<span class="sub">${C.ca ? pc(ca / C.ca) + ' de la catégorie' : ''}</span></td>
        <td colspan="2">${sortent.length} à désactiver${gardees ? `<span class="sub">${gardees} gardée${gardees > 1 ? 's' : ''}</span>` : ''}</td>
        <td class="sg-chev">${ouv ? '⌄' : '›'}</td></tr>`;
      if (ouv) {
        lignes += l.map(p => `<tr class="${p.statut === 'desactiver' ? '' : 'sg-gardee'}">
          <td class="sg-nom" style="padding-left:24px">${esc(p.nom)}<span class="sub">${p.u ? vie(g, p) : 'moins de ' + g.plancher + ' pièces en 12 mois'}${p.must ? ' · obligatoire aujourd’hui' : ''}</span></td>
          <td>${spark(g, p, esc)}</td>
          ${saisonsQte(g, p)}
          <td class="num">${p.u ? eur(p.caPer) : '<span class="muted">≤ ' + eur(g.plancher * (p.prix || 0)) + '</span>'}<span class="sub">${p.part != null ? pc(pt(g, p.part), 1) : '≤ ' + pc(p.partMax, 1)} de la catégorie</span></td>
          ${commandes(g, p, x)}</tr>`).join('');
      }
    });
  });
  const nGardees = proposees.length - off.length;
  return `
    <div class="kpi-row sg-kpis">
      ${kpi('À désactiver', nb(off.length), nGardees ? 'sur ' + proposees.length + ' proposées · ' + nGardees + ' gardée' + (nGardees > 1 ? 's' : '') : 'sur ' + T.structure + ' références structurées')}
      ${kpi('Leur chiffre d’affaires', keur(g.offCa), pc(g.offCa / T.caStructure) + ' du CA des catégories, sur leurs mois de vente')}
      ${kpi('Encore vendues', nb(g.offVendues), 'au-dessus de ' + g.plancher + ' pièces en 12 mois')}
      ${kpi('Sous le plancher', nb(off.length - g.offVendues), 'moins de ' + g.plancher + ' pièces en 12 mois')}
      ${kpi('Obligatoires aujourd’hui', nb(g.offMusts), 'sortent aussi de l’assortiment')}
    </div>
    <div class="sg-encart"><span><span class="sg-fort">Le retrait du catalogue a un garde-fou.</span> « Retirer du catalogue » refuse toute référence vendue sur les douze derniers mois. Ici, pour ce seul motif — moins de ${g.vx(g.regles.seuil)} de sa catégorie —, il l’accepte : le serveur relit les ventes, recalcule la part de chaque référence et refuse celles qui pèsent le seuil ou plus, les nouveautés et ce qui n’est pas un produit. Une ligne de journal par référence ; « Remettre au catalogue » défait le geste.</span></div>
    <div class="sg-barre"><span class="sg-note">Pour chaque référence : ce qu’elle vend par saison. « Désactiver » éteint, elle reste au catalogue ; « Obligatoire » allumé, elle entre dans l’assortiment pour la saison choisie. Chaque geste s’enregistre pour le réseau.</span><span class="sg-sp"></span><button class="linklike" ${x.A(g.deplierTout)}>${tout ? 'Tout replier' : 'Tout déplier'}</button></div>
    <section class="card table-card sg-table sg-t4"><div class="table-scroll"><table>
      <thead><tr><th>Catégorie · référence</th><th>12 mois</th>${R.saisons.map(s => `<th class="num" title="${esc(s.lib)}">${esc(s.nom)}</th>`).join('')}<th class="num">CA 12 mois</th><th>Désactiver</th><th>Obligatoire</th><th>Saison</th></tr></thead>
      <tbody>${lignes || '<tr><td colspan="10" class="muted">Aucune référence sous le seuil.</td></tr>'}</tbody></table></div></section>
    <p class="sg-note">Pièces vendues par saison, tous magasins, sur les douze mois lus (${esc(g.mois[0] || '')} – ${esc(g.mois[g.mois.length - 1] || '')}, le dernier en cours) : hiver = décembre à février, printemps = mars à mai, été = juin à août, automne = septembre à novembre. Une référence de saison est comparée à sa catégorie pendant sa saison, pas sur l’année ; les nouveautés de moins de six mois ne sont jamais dans cette liste.</p>`;
}

/** Ce que la courbe dit en une ligne. */
function vie(g, p){
  const n = g.mois.length, l = [];
  if (p.nature === 'saison') { l.push('saison ' + p.saisons.map(s => g.SA[s].nom.toLowerCase()).join(' et ')); }
  else if (p.nature === 'periode') { l.push(g.PE[p.periode].nom); }
  else if (p.lance) { l.push('lancée en ' + String(g.mois[p.lance] || '').toLowerCase() + ' — part mesurée depuis'); }
  if (p.nature !== 'saison' && p.nature !== 'periode' && p.fin < n - 2) { l.push('plus vendue depuis ' + String(g.mois[p.fin + 1] || '').toLowerCase()); }
  return l.length ? l.join(' · ') : 'vendue toute l’année';
}

/* --- Confirmations ------------------------------------------------------------------------ */
function confirmation(g, x){
  const { esc } = x;
  if (!g.confirm) { return ''; }
  if (g.confirm === 'desactiver') {
    return `<div class="confirm" role="dialog" aria-modal="true"><div class="card confirm__box">
      <h3>Retirer ${pl(g.aDesactiver.length, 'référence')} du catalogue ?</h3>
      <p class="muted" style="font-size:13px;line-height:1.5">Elles pèsent chacune moins de ${g.vx(g.regles.seuil)} de leur catégorie, ${keur(g.offCa)} de CA sur douze mois. Elles disparaissent de la caisse de tous les magasins. ${g.offMusts ? g.offMusts + ' sont obligatoires aujourd’hui et sortent aussi de l’assortiment. ' : ''}Le serveur revérifie chaque part avant d’écrire.</p>
      <div class="confirm__actions"><button class="btn btn--ghost" ${x.A(g.annuler)}>Annuler</button><button class="btn confirm__danger" ${x.A(g.desactiver)} ${g.busy ? 'disabled' : ''}>${g.busy ? 'Retrait…' : 'Retirer du catalogue'}</button></div>
    </div></div>`;
  }
  const P = g.plan;
  return `<div class="confirm" role="dialog" aria-modal="true"><div class="card confirm__box" style="width:min(540px,100%)">
    <h3>Aligner l’assortiment obligatoire sur la structure ?</h3>
    <ul class="sg-liste">
      <li>${pl(P.standard, 'référence')} obligatoire${P.standard > 1 ? 's' : ''} toute l’année (le standard) ;</li>
      <li>${pl(P.saisons, 'référence')} pendant leur saison et ${pl(P.periodes, 'référence')} pendant leur période, sur la gamme du panel qui la porte ;</li>
      ${P.inactives.length ? `<li>${P.inactives.length} référence${P.inactives.length > 1 ? 's' : ''} de période inactive${P.inactives.length > 1 ? 's' : ''} au catalogue (${esc(P.inactives.join(', '))}) : à réactiver dans le panel, elles ne sont pas rendues obligatoires ici.</li>` : ''}
    </ul>
    ${P.sortent ? `<label class="sg-coche"><input type="checkbox" ${P.retirer ? 'checked' : ''} ${x.C(P.basculerRetirer)}><span>Retirer aussi de l’obligatoire les ${P.sortent} références d’aujourd’hui que la structure ne retient pas<span class="sub">${esc(P.sortentNoms.join(', '))}${P.sortent > P.sortentNoms.length ? '…' : ''}</span></span></label>` : ''}
    <p class="muted" style="font-size:12px;line-height:1.5;margin-top:10px">Même écriture que l’assortiment obligatoire : la quantité minimale en place est gardée, ce qui est déjà en place n’est pas réécrit, une ligne de journal par référence. Les magasins seront contrôlés sur ce nouvel assortiment.</p>
    <div class="confirm__actions"><button class="btn btn--ghost" ${x.A(g.annuler)}>Annuler</button><button class="btn btn--primary" ${x.A(g.appliquer)} ${g.busy ? 'disabled' : ''}>${g.busy ? 'Écriture…' : 'Appliquer'}</button></div>
  </div></div>`;
}
