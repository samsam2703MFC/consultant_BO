/* Cockpit CEO — Recettes à corriger : les gabarits de la correction (13/11/2026). Valeurs : verification-correction-vals.js ;
 * style : verification.css (rc-). Maquette : docs/maquettes/recettes-correction. */

const ICO = {
  crayon: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
  roue: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7M21 4v5h-5"/></svg>',
};
const lienA = (x, l, cls) => `<a class="${cls || 'rc-ln'}" href="${x.esc(l.url)}" target="_blank" rel="noopener">${x.esc(l.txt)} ↗</a>`;

/** La cellule « Corriger » : le geste, puis les liens directs. */
export function tplCellule(l, x){
  const g = l.geste;
  let bouton = '';
  if (g && g.type === 'panel') { bouton = l.liens[0] ? lienA(x, { url: l.liens[0].url, txt: 'Ouvrir au panel' }, 'vr-lien') : ''; }
  else if (g) { bouton = `<button class="rc-btn${g.type === 'local' ? ' gris' : (l.ouvert ? ' sec' : '')}" ${x.A(l.agir)}>${g.type === 'local' ? '' : ICO[g.ico || 'crayon']}${x.esc(g.bouton)}${g.type === 'ligne' ? (l.ouvert ? ' ▴' : '') : ''}</button>`; }
  const autres = (l.liens || []).filter((k, i) => !(g && g.type === 'panel' && i === 0));
  const volet = g && g.volet && l.corrigerVolet ? `<button class="rc-btn sec" ${x.A(l.corrigerVolet)}>${ICO.crayon}Corriger la recette</button>` : '';
  return `<div class="rc-act">${bouton}${volet}${autres.length ? `<div class="rc-liens">${autres.map(k => lienA(x, k)).join('')}</div>` : ''}</div>`;
}

/** La proposition, sous « ce qui cloche ». */
export function tplProp(l, x){
  return l.geste && l.geste.prop ? `<span class="rc-prop${l.geste.type === 'panel' ? ' panel' : ''}">${x.esc(l.geste.prop)}</span>` : '';
}

/** La ligne ouverte : la correction en place, l'aperçu, le résultat. */
export function tplOuverte(O, ncol, x){
  const { esc } = x;
  const champs = (O.champs || []).map(c => c.choix
    ? `<label class="rc-chp">${esc(c.lib)} <select class="rc-sel" ${x.C(c.poser)}>${c.choix.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></label>`
    : `<label class="rc-chp">${esc(c.lib)} <input id="${esc(c.id)}" class="rc-in${c.large ? ' l' : ''}" inputmode="decimal" value="${esc(c.val)}" ${x.I(c.poser)}>${c.suf ? ` <span class="vr-mu">${esc(c.suf)}</span>` : ''}</label>`).join('');
  const cand = O.champs && O.champs.candidats !== undefined ? `<div class="rc-cand">${O.champs.candidats ? O.champs.candidats.map(k => `<button class="rc-choix${k.on ? ' on' : ''}" ${x.A(k.choisir)}><b>${k.rid}</b> ${esc(k.nom)}${k.pareil ? ' <span class="rc-tag ok">même nom</span>' : ''}${k.libre ? '' : ' <span class="rc-tag">déjà rattachée</span>'}</button>`).join('') : ''}${O.champs.candMotif ? `<span class="vr-mu">${esc(O.champs.candMotif)}</span>` : ''}</div>` : '';
  const apercu = O.apercu ? `<div class="rc-apercu"><b>Ce qui sera écrit au panel</b> — ${esc(O.apercu.resume)}${O.apercu.ecritures.map(e => `<details><summary><span class="rc-code">${esc(e.route)}</span>${e.annulable === false ? ' <span class="vr-mu">· sans retour possible</span>' : ''}</summary><pre class="rc-pre">${esc(e.corps)}</pre></details>`).join('')}</div>` : '';
  const res = O.resultat ? `<div class="rc-res ${O.resultat.ok ? 'ok' : 'ko'}">${O.resultat.ok ? '✓' : '✕'} ${esc(O.resultat.txt)}</div>` : '';
  return `<tr class="rc-ouvert"><td colspan="${ncol}">
    ${champs || cand ? `<div class="rc-inl">${champs}</div>${cand}` : ''}
    ${O.erreur ? `<div class="vr-mu rc-aide">${esc(O.erreur)}</div>` : ''}
    ${O.refus ? `<div class="rc-res ko">✕ ${esc(O.refus)}</div>` : ''}
    ${apercu}${res}
    <div class="rc-pied">${O.apercu
      ? `<button class="rc-btn ok" ${x.A(O.appliquer)}${O.enCours ? ' disabled' : ''}>${O.enCours ? 'Écriture…' : 'Appliquer au panel'}</button>`
      : `<button class="rc-btn" ${x.A(O.verifier)}${!O.verifier || O.enCours ? ' disabled' : ''}>${O.enCours ? 'Préparation…' : 'Vérifier ce qui sera écrit'}</button>`}
      <button class="rc-btn gris" ${x.A(O.fermer)}>Fermer</button>
      <span class="vr-mu">Rien ne part sans « Appliquer ». Le cockpit écrit avec son compte consultant, relit, et note l’avant pour pouvoir annuler.</span></div>
  </td></tr>`;
}

/** Une correction faite (en tête de liste, et dans le registre). */
function tplFaite(e, x, avecRoute){
  const { esc } = x;
  return `<tr class="${e.annule ? 'rc-annulee' : (e.ok ? 'rc-fait' : 'rc-rate')}"><td class="g">${esc(e.quand)}</td><td class="g nom"><b>${esc(e.nom)}</b></td>
    <td class="g rc-w">${esc(e.resume)}</td><td>${esc(e.cout)}</td>${avecRoute ? `<td class="g"><span class="rc-code">${esc(e.route)}</span></td>` : ''}
    <td class="g rc-w">${e.ok ? `<span class="rc-ok"><i>✓</i>${esc(e.resultat)}</span>` : `<span class="${e.annule ? 'vr-mu' : 'rc-ko'}">${e.annule ? '' : '✕ '}${esc(e.resultat)}${e.message ? ' : ' + esc(e.message) : ''}</span>`}${e.annulErreur ? `<span class="rc-ko"> — ${esc(e.annulErreur)}</span>` : ''}</td>
    <td>${e.annuler ? `<button class="rc-btn gris" ${x.A(e.annuler)}${e.annulEnCours ? ' disabled' : ''}>${e.annulEnCours ? 'Annulation…' : 'Annuler'}</button>` : (e.lien ? `<a class="vr-lien" href="${esc(e.lien)}" target="_blank" rel="noopener">Ouvrir au panel ↗</a>` : '')}</td></tr>`;
}

/** Les corrections de cette visite, en tête de la carte. */
export function tplRecentes(R, x){
  if (!R || !R.length) { return ''; }
  return `<div class="rc-recentes"><div class="rc-recentes-t">Corrigé depuis cette page</div><div class="vr-tw"><table class="vr-t"><tbody>${R.map(e => tplFaite(e, x, false)).join('')}</tbody></table></div></div>`;
}

/** Le registre : replié sous la liste ; les adresses du panel et la route du prix s'y règlent. */
export function tplRegistre(G, x){
  if (!G) { return ''; }
  const { esc } = x;
  const L = G.liens;
  const form = L && L.ouvert ? `<div class="rc-liens-f">
      ${L.champs.map(c => `<label><span>${esc(c.lib)}</span><input class="rc-in l" style="width:100%" placeholder="https://… ${esc(c.ph)}" value="${esc(c.val)}" ${x.I(c.poser)}></label>`).join('')}
      <label><span>Prix d’une matière</span><span class="rc-prix-f"><select class="rc-sel" ${x.C(L.prix.poser('prixMethode'))}>${['POST', 'PATCH', 'PUT'].map(m => `<option${m === L.prix.methode ? ' selected' : ''}>${m}</option>`).join('')}</select>
        <input class="rc-in l" style="width:260px" value="${esc(L.prix.route)}" ${x.I(L.prix.poser('prixRoute'))}><input class="rc-in l" style="width:260px" value="${esc(L.prix.corps)}" ${x.I(L.prix.poser('prixCorps'))}></span></label>
      <div class="vr-mu" style="font-size:11px">Modèles : {pid} produit, {rid} recette, {mid} matière, {sid} magasin, {sous} sous-recette. Corps du prix : {sid}, {mid}, {prix}. Vide : l’adresse par défaut.</div>
      ${L.erreur ? `<div class="rc-ko">${esc(L.erreur)}</div>` : ''}
      <div class="rc-pied"><button class="rc-btn" ${x.A(L.enregistrer)}>Enregistrer</button><button class="rc-btn gris" ${x.A(L.fermer)}>Fermer</button></div></div>` : '';
  return `<div class="vr-card rc-reg">
    <div class="vx-ct"><h3>Le registre des corrections</h3><span class="vr-lu">chaque écriture au panel faite depuis cette page, avec l’avant et l’après</span><span class="vr-sp"></span>
      ${L && !L.ouvert ? `<button class="vr-btn" ${x.A(L.ouvrir)}>Adresses du panel</button>` : ''}<button class="vr-btn" ${x.A(G.basculer)}>${G.ouvert ? 'Replier' : 'Ouvrir'}${G.n != null ? ' (' + G.n + ')' : ''}</button></div>
    ${G.ouvert ? (G.chargement ? `<div class="vr-etat"><span class="vr-roue"></span>Lecture du registre…</div>`
      : `${form}${G.lignes.length ? `<div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Quand</th><th class="g">Produit</th><th class="g">Correction</th><th>Coût de la pièce</th><th class="g">Route</th><th class="g">Résultat</th><th></th></tr></thead><tbody>${G.lignes.map(e => tplFaite(e, x, true)).join('')}</tbody></table></div>`
        : `<div class="vr-vide">Aucune correction faite d’ici pour l’instant.</div>`}
      <div class="rc-note"><b>Annuler</b> réécrit l’avant au panel, par la même route, et le relit. Une correction refusée ou sans effet ne change rien : le panel garde son état, le bouton y mène.</div>`) : ''}
  </div>`;
}

/** Le volet en correction : la recette et ses sous-recettes, modifiables, l'avant et l'après, l'écriture. */
export function tplEdition(E, x){
  const { esc } = x;
  if (!E) { return ''; }
  if (E.chargement) { return `<div class="vr-etat"><span class="vr-roue"></span>Lecture de la recette et de ses sous-recettes au panel…</div>`; }
  if (E.erreur) { return `<div class="vr-etat ko">${esc(E.erreur)}</div>`; }
  const liens = E.liens.length ? `<div class="rc-fiche-act"><span class="vr-mu">Liens directs au panel :</span>${E.liens.map(l => lienA(x, l)).join('')}</div>` : '';
  const mags = E.magasins.length ? `<div class="vr-fi-h">Dans chaque magasin — avant et après${E.total.estime ? ' (estimé : une ligne ajoutée se chiffre au panel)' : ''}</div>
    <table class="vr-t"><thead><tr><th class="g">Magasin</th><th>Coût<small>avant</small></th><th>Coût<small>après</small></th><th>Prix HT</th><th>Matière<small>% du prix, après</small></th></tr></thead><tbody>
    ${E.magasins.map(m => `<tr><td class="g nom"><b>${esc(m.nom)}</b></td><td>${E.total.change ? `<span class="rc-av">${m.avant}</span>` : m.avant}</td><td>${E.total.change ? `<b class="rc-ap">${m.apres}</b>` : m.apres}</td><td>${m.prix}</td><td><span class="vx-p ${m.ton}">${m.food}</span></td></tr>`).join('')}</tbody></table>` : '';
  const copie = E.modeles ? `<div class="vr-fi-h">Recette vide : copier les lignes d’une recette proche</div><div class="rc-cand" style="padding:0 20px 8px">${E.modeles.map(m => `<button class="rc-choix${m.on ? ' on' : ''}" ${x.A(m.choisir)}>${esc(m.nom)}</button>`).join('')}</div>` : '';
  const rend = E.rendement.poser ? `<div class="rc-rend">Rendement <input id="vr-ed-rend" class="rc-in${E.rendement.change ? ' mod' : ''}" inputmode="decimal" value="${esc(E.rendement.val)}" ${x.I(E.rendement.poser)}> pièce(s) par recette</div>` : '';
  const blocs = E.blocs.map(b => `<div class="vr-fi-h">${esc(b.titre)}${b.lien ? ' ' + lienA(x, { url: b.lien, txt: 'au panel' }) : ''}</div>
    ${b.modifiable ? '' : `<div class="rc-aide vr-mu" style="padding:0 20px 6px">Le panel ne rend pas de quantité lisible pour ces lignes (clés : ${esc(b.cles)}) : à corriger au panel.</div>`}
    ${b.lignes.length ? `<div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Matière</th><th>Quantité<small>dans la recette</small></th><th class="g">Unité</th><th>Coût<small>pièce, avant</small></th><th>Coût<small>pièce, après</small></th><th></th></tr></thead><tbody>
    ${b.lignes.map(l => `<tr class="${l.retire ? 'rc-retiree' : (l.change ? 'rc-mod' : (l.lourde ? 'vr-lourde' : ''))}"><td class="g nom"><b>${esc(l.nom)}</b>${l.sous ? '<small>sous-recette</small>' : ''}</td>
      <td>${l.change ? `<span class="rc-av">${esc(l.qAvant)}</span> ` : ''}${l.poser ? `<input id="${esc(l.id)}" class="rc-in${l.change ? ' mod' : ''}${l.invalide && !l.retire ? ' ko' : ''}" inputmode="decimal" value="${esc(l.val)}" ${x.I(l.poser)}${l.retire ? ' disabled' : ''}>` : esc(l.qAvant)}</td>
      <td class="g">${esc(l.u)}</td><td>${l.change || l.retire ? `<span class="rc-av">${l.coutAvant}</span>` : l.coutAvant}</td><td>${l.change || l.retire ? `<b class="rc-ap">${l.coutApres}</b>` : l.coutApres}</td>
      <td>${l.retirer ? `<button class="rc-x" ${x.A(l.retirer)} title="${l.retire ? 'Garder la ligne' : 'Retirer la ligne'}">${l.retire ? '↺' : '×'}</button>` : ''}</td></tr>`).join('')}
    </tbody></table></div>` : `<div class="vr-vide">Aucune ligne.</div>`}`).join('');
  const ajouts = E.ajouts.length ? `<div class="rc-ajouts">${E.ajouts.map(a => `<span class="rc-tag ok">+ ${esc(a.nom)} · ${esc(a.q)} <button class="rc-x" ${x.A(a.retirer)}>×</button></span>`).join('')}</div>` : '';
  const ajout = E.ajout ? `<div class="rc-plus">+ <select class="rc-sel" ${x.C(E.ajout.poserM)}><option value="">Ajouter une matière (celles des recettes du réseau)…</option>${E.ajout.matieres.map(m => `<option value="${esc(m.id)}"${m.on ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>
    <input id="vr-ed-ajq" class="rc-in" inputmode="decimal" placeholder="quantité" value="${esc(E.ajout.q)}" ${x.I(E.ajout.poserQ)}> <span class="vr-mu">${esc(E.ajout.u)}</span>
    <button class="rc-btn gris" ${x.A(E.ajout.ajouter)}${E.ajout.ajouter ? '' : ' disabled'}>Ajouter</button></div>` : '';
  const apercu = E.apercu ? `<div class="rc-apercu" style="margin:10px 20px"><b>Ce qui sera écrit au panel</b> — ${esc(E.apercu.resume)}${E.apercu.ecritures.map(e => `<details><summary><span class="rc-code">${esc(e.route)}</span> ${esc(e.resume)}</summary><pre class="rc-pre">${esc(e.corps)}</pre></details>`).join('')}</div>` : '';
  const res = E.resultat ? `<div class="rc-res ${E.resultat.ok ? 'ok' : 'ko'}" style="margin:10px 20px">${E.resultat.lignes.map(t => (E.resultat.ok ? '✓ ' : '• ') + esc(t)).join('<br>')}${E.resultat.erreur ? '✕ ' + esc(E.resultat.erreur) : ''}</div>` : '';
  const barre = `<div class="rc-barre"><div><b>${E.copie ? 'Copier « ' + esc(E.copie) + ' »' : (E.nChangements ? E.nChangements + ' changement' + (E.nChangements > 1 ? 's' : '') : 'Aucun changement')}</b>${E.total.change ? ` — la pièce <span class="rc-av">${E.total.avant}</span> <b class="rc-ap">${E.total.apres}</b>${E.total.estime ? ' (estimé)' : ''}` : ''}
      <small>${E.refus ? '✕ ' + esc(E.refus) : (E.invalide ? 'Une quantité ou le rendement n’est pas un nombre positif.' : 'Écrit au panel par le compte consultant, relu aussitôt, noté au registre avec l’avant : « Annuler » le remet.')}</small></div><span class="vr-sp"></span>
    <button class="rc-btn gris" ${x.A(E.annulerTout)}>Annuler les changements</button>
    ${E.apercu ? `<button class="rc-btn ok" ${x.A(E.enregistrer)}${E.enCours ? ' disabled' : ''}>${E.enCours ? 'Écriture…' : 'Enregistrer au panel'}</button>`
      : `<button class="rc-btn" ${x.A(E.verifier)}${!E.verifier || E.enCours ? ' disabled' : ''}>${E.enCours ? 'Préparation…' : 'Vérifier ce qui sera écrit'}</button>`}</div>`;
  return `${liens}${mags}${copie}${rend}${blocs}${ajouts}${ajout}${apercu}${res}<div style="height:16px"></div>${barre}`;
}
