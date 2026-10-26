/* Cockpit CEO — gabarits des écrans « Suivi des franchisés » (24/10/2026) : Tableau de bord, Visite sur
 * tablette, Client mystère, Page du franchisé, Qui fait quoi. Mêmes conventions que templates.js :
 * x = { A, C, I, esc } ; les valeurs viennent de suivi-vals.js (`c.sv`). */

const kpi = (esc, k) => `<div class="gc-kpi"><div class="k">${esc(k[0])}</div><div class="v ${k[3] || ''}">${esc(k[1])}</div><div class="s">${esc(k[2] || '')}</div></div>`;
const sel = (x, esc, fn, opts, style) => `<select ${x.C(fn)} class="mko-sel" style="${style || ''}">${opts.map(o => `<option value="${esc(o.v)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>`;
const msgDe = (esc, m) => m ? `<div class="gc-msg ${m.ko ? 'ko' : ''}">${esc(m.txt)}</div>` : '';
const attente = (esc, t) => `<div class="mko-carte" style="padding:20px 22px;font-size:13px;color:var(--color-text-muted)">${esc(t)}</div>`;
const panne = (esc, t) => `<div class="mko-carte" style="padding:20px 22px;font-size:13px;color:#8D1D2C">${esc(t)}</div>`;
const bascule = (x, on, fn, lib) => `<button ${x.A(fn)} class="sv-bas${on ? ' on' : ''}" role="switch" aria-checked="${on ? 'true' : 'false'}"><i></i>${lib || ''}</button>`;
const duo = (x, esc, a, b) => `<span class="sv-duo"><button ${x.A(a.fn)} class="${a.on ? 'on' : ''}">${esc(a.nom)}</button><button ${x.A(b.fn)} class="${b.on ? 'on ko' : ''}">${esc(b.nom)}</button></span>`;
const carte = (titre, mini, corps, plus) => `<div class="mko-carte"${plus || ''}><div class="mko-ct"><span class="mko-lab">${titre}</span>${mini ? `<span class="mko-mini">${mini}</span>` : ''}</div><div class="mko-corps">${corps}</div></div>`;

/* --- 1. Visites : l'assistant (26/10/2026, maquette C, docs/maquettes/franchises-wizard) --------------------
 * Les étapes à gauche, en accordéon ; la fiche vivante à droite ; un onglet par visite qui attend le bureau. */
const IC = {
  tab: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M10 18h4"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
  lock: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
  plus: '<path d="M12 5v14M5 12h14"/>', ok: '<path d="M5 12l5 5 9-10"/>'
};
const ic = (k, cls) => `<svg viewBox="0 0 24 24" class="sva-ic ${cls || ''}">${IC[k]}</svg>`;
const PHASES = { avant: 'Avant la visite · au bureau', pendant: 'Pendant la visite · sur la tablette', apres: 'Après la visite · au bureau' };

export function tplSvTableau(c, x){
  const { esc } = x, s = c.sv;
  const apercu = s.apercu ? `<div class="sv-voile" ${x.A(s.fermerApercu)}><div class="sv-modal" ${x.A(() => {})}><div class="mko-ct"><span class="mko-lab">${esc(s.apercu.titre)}</span><button ${x.A(s.fermerApercu)} class="mko-pied-btn" style="margin-left:auto">Fermer</button></div>
      ${s.apercu.chargement ? '<div class="mko-corps mko-mu">Mise en page du rapport…</div>' : `<iframe class="sv-ifr" srcdoc="${esc(s.apercu.html)}" title="Aperçu du rapport"></iframe>`}</div></div>` : '';
  if (s.indispo && !s.onglets) { return `<div data-screen="suivi-assistant">${panne(esc, s.indispo)}</div>`; }
  if (s.chargement) { return `<div data-screen="suivi-assistant">${attente(esc, 'Lecture des visites et de ce qui attend le bureau…')}</div>`; }
  const tete = `<div class="sva-tete"><div><h1 class="sva-h1">${esc(s.titre || 'Créer une visite')}</h1><p class="sva-chapo">${esc(s.chapo || '')}</p></div>
    <button ${x.A(s.relire)} class="mko-pied-btn" title="${esc(s.lu)}">Relire</button></div>`;
  const onglets = `<div class="sva-onglets">${s.onglets.map(o => `<button ${x.A(o.ouvrir)} class="sva-og${o.on ? ' on' : ''}">${o.pt ? `<i class="sva-pt ${esc(o.pt)}"></i>` : ''}${o.nom ? esc(o.nom) + (o.court ? ' · ' : '') : ''}<b>${esc(o.court)}</b>${o.quoi ? ` <em class="${o.urgent ? 'att' : ''}">${esc(o.quoi)}</em>` : ''}${o.echeance ? ` <em class="${o.urgent ? 'att' : ''}">${esc(o.echeance)}</em>` : ''}</button>`).join('')}
    ${s.autres ? sel(x, esc, s.setAutre, s.autres, 'height:34px;margin-bottom:2px;max-width:220px') : ''}
    <button ${x.A(s.creer.ouvrir)} class="sva-og plus${s.creer.on ? ' on' : ''}">${ic('plus')}Créer une visite</button>
    ${s.ongletPoints ? `<button ${x.A(s.ongletPoints.ouvrir)} class="sva-og pts${s.ongletPoints.on ? ' on' : ''}">${s.ongletPoints.retard ? '<i class="sva-pt ko"></i>' : ''}Points décidés <b>${esc(s.ongletPoints.n)}</b>${s.ongletPoints.retard ? ` <em class="att">${esc(s.ongletPoints.retard)} en retard</em>` : ''}</button>` : ''}
    <span class="sva-og-aide">Chaque visite qui attend quelque chose de vous garde son onglet.</span></div>`;
  if (s.pointsDecides) { return `<div data-screen="suivi-assistant">${tete}${msgDe(esc, s.msg)}${onglets}<div class="sva-panneau sva-pts">${tplPointsDecides(s.pointsDecides, x)}</div>${apercu}</div>`; }
  let gauche;
  if (s.indispo) { gauche = panne(esc, s.indispo); }
  else if (s.pasChargement) { gauche = `<div class="mko-mu" style="padding:20px">${esc(s.pasChargement)}</div>`; }
  else {
    let phase = '';
    gauche = s.etapes.map(e => {
      const h = e.phase !== phase ? `<div class="sva-phase${e.phase === 'pendant' ? ' tab' : ''}">${esc(PHASES[e.phase])}</div>` : '';
      phase = e.phase;
      const pn = e.etat === 'fait' ? ic('ok') : (e.n ? String(e.n) : ic('tab'));
      const corps = e.etat === 'cur' ? `<div class="sva-ouvert"><div class="sva-pl">${e.n ? e.n + ' · ' : ''}${esc(e.nom)}</div>${pasHtml(s.pas, x)}</div>`
        : `<div class="sva-ferme"><b>${esc(e.titre)}</b>${e.sous ? `<small>${esc(e.sous)}</small>` : ''}${e.modifier ? `<button ${x.A(e.modifier)} class="sva-lien">${e.etat === 'fait' ? 'Modifier' : 'Ouvrir'}</button>` : ''}</div>`;
      return `${h}<div class="sva-pas ${e.etat}${e.n ? '' : ' tab'}${e.der ? ' der' : ''}"><div class="sva-pn">${pn}</div><div class="sva-pc">${corps}</div></div>`;
    }).join('');
  }
  return `<div data-screen="suivi-assistant">${tete}${msgDe(esc, s.msg)}${onglets}<div class="sva-panneau"><div class="sva-gauche">${gauche}</div><div class="sva-droite">${s.fiche ? ficheHtml(s.fiche, x) : ''}</div></div>${apercu}</div>`;
}

/* L'onglet des points décidés : filtres, puis un tableau par échéance. */
function tplPointsDecides(P, x){
  const { esc } = x;
  const filtres = `<div class="sva-ptf">${sel(x, esc, P.setShop, P.magasins, 'height:34px;max-width:260px')}<span class="sva-seg">${P.filtres.map(f => `<button ${x.A(f.choisir)} class="${f.on ? 'on lu' : ''}">${esc(f.nom)}</button>`).join('')}</span></div>`;
  if (!P.groupes.length) { return filtres + `<div class="mko-mu" style="padding:18px 2px">${P.total ? 'Aucun point pour ce filtre.' : 'Aucun point ouvert : tout ce qui a été décidé est fait.'}</div>`; }
  return filtres + P.groupes.map(g => `<div class="sva-sect"><h3 class="${esc(g.cls)}">${esc(g.nom)}</h3><span class="mini">${g.lignes.length}</span></div>
    <div style="overflow-x:auto"><table class="mko-tab sv-tab sva-ptab"><thead><tr><th>Prio</th><th>Le point décidé</th><th>Magasin</th><th>Qui le fait</th><th>Pour quand</th><th>Statut</th></tr></thead>
    <tbody>${g.lignes.map(l => `<tr${l.ouvrir ? ' class="clic" ' + x.A(l.ouvrir) + ' title="Ouvrir la visite"' : ''}><td><span class="gc-st ${l.prio === 'P0' ? 'p0' : ''}">${esc(l.prio)}</span></td>
      <td><b>${esc(l.titre)}</b>${l.piste ? ' <span class="gc-pill info">piste CA</span>' : ''}${l.detail ? `<small>${esc(l.detail)}</small>` : ''}</td>
      <td>${esc(l.court)}<small>${esc(l.origine)}</small></td><td>${esc(l.qui)}</td>
      <td><b>${esc(l.echeance)}</b><small class="${l.delaiKo ? 'ko' : ''}">${esc(l.delai)}</small></td><td><span class="gc-pill ${esc(l.statutCls)}">${esc(l.statut)}</span></td></tr>`).join('')}</tbody></table></div>`).join('');
}

/* L'étape ouverte. */
function pasHtml(P, x){
  const { esc } = x;
  if (!P) { return ''; }
  const tete = `<h2 class="sva-q">${esc(P.question)}</h2>${P.lead ? `<p class="sva-lead">${esc(P.lead)}</p>` : ''}`;
  if (P.cle === 'magasin') {
    return tete + `<div class="sva-mags">${P.magasins.map(m => `<button ${x.A(m.choisir)} class="sva-mag${m.on ? ' on' : ''}"><span class="t"><i class="gc-feu ${esc(m.feu)}"></i>${esc(m.court)}</span><span class="l">${esc(m.l1)}</span>
      <span class="l mu">${esc(m.derniere)}</span><span class="l ${m.pointsKo ? 'ko' : 'mu'}">${esc(m.points)}</span>${m.prochaine ? `<span class="l mu">${esc(m.prochaine)}</span>` : ''}</button>`).join('')}</div>`;
  }
  if (P.cle === 'visite') {
    return tete + `<div class="sva-sect"><h3>Le type de visite</h3></div><div class="sva-liste">${P.types.map(t => `<button ${x.A(t.choisir)} class="sva-ln${t.on ? ' on' : ''}"><span class="sva-rad${t.on ? ' on' : ''}"></span><span><b>${esc(t.nom)}</b><small>${esc(t.sous)}</small></span><span class="r">${esc(t.defaut)}</span></button>`).join('')}</div>
      <div class="sva-sect"><h3>Le franchisé</h3></div><div class="sva-duo">${duo(x, esc, { nom: 'Annoncée', on: P.annoncee, fn: P.annoncer }, { nom: 'Inopinée', on: !P.annoncee, fn: P.inopiner })}<small>${esc(P.annonceeSous)}</small></div>
      <div class="sva-sect"><h3>Quand, et qui y va</h3></div><div class="gc-form sva-f4">
        <div class="f"><label>Date</label><input type="date" class="mko-inp" value="${esc(P.date)}" ${x.C(P.setDate)}></div>
        <div class="f"><label>Heure</label><input type="time" class="mko-inp" value="${esc(P.heure)}" ${x.C(P.setHeure)}></div>
        <div class="f"><label>Durée (min)</label><input type="number" class="mko-inp" value="${esc(P.duree)}" ${x.C(P.setDuree)}></div>
        <div class="f"><label>Consultant</label>${sel(x, esc, P.setCons, P.consultants)}</div></div>
      <div class="sva-act"><button ${x.A(P.valider)} class="cx-btn prim sva-lg" ${P.busy ? 'disabled' : ''}>${P.busy ? 'Un instant…' : esc(P.action) + ' →'}</button>${P.nouvelle ? '<span class="sva-aide">Rien n’est créé avant ce bouton.</span>' : ''}</div>`;
  }
  if (P.cle === 'dossier') {
    const p = P.prep;
    if (!p || p.chargement) { return tete + '<div class="mko-mu">Assemblage du dossier…</div>'; }
    if (p.indispo) { return tete + `<div class="ko">${esc(p.indispo)}</div>`; }
    return tete + `<div class="sva-sect"><h3>Le dossier du magasin</h3><span class="mini">${p.dossier.filter(b => b.inclus).length} sur ${p.dossier.length} partent</span></div>
      <div class="sva-liste">${p.dossier.map(b => `<button ${x.A(b.basculer)} class="sva-ln${b.inclus ? '' : ' off'}${b.interne ? ' int' : ''}"><span class="sva-cb${b.inclus ? ' on' : ''}"></span><span><b>${esc(b.titre)}</b>${b.interne ? ' <span class="sva-int">interne</span>' : ''}${b.alerte ? ` <span class="gc-pill ko">${esc(b.alerte)}</span>` : ''}
        <small>${esc(b.lignes.length ? b.lignes.slice(0, 2).join(' · ') : (b.motif || 'rien à signaler'))}</small></span><span class="r">${b.inclus ? (b.interne ? 'jamais montré au franchisé' : '') : 'retiré'}</span></button>`).join('')}</div>
      <div class="sva-sect"><h3>Vos demandes à ${esc(P.consultant)}</h3><span class="mini">elles ouvrent sa checklist · le franchisé lit celles de l’ordre du jour</span></div>
      <div class="sva-liste">${p.points.map(q => `<div class="sva-dm"><input class="mko-inp" value="${esc(q.libelle)}" ${x.I(q.setLib)} placeholder="Ce que ${esc(P.consultant)} doit regarder">
          <span class="sva-seg"><button ${q.odj ? '' : x.A(q.basculerOdj)} class="${q.odj ? 'on lu' : ''}">${ic('mail')}Ordre du jour</button><button ${q.odj ? x.A(q.basculerOdj) : ''} class="${q.odj ? '' : 'on in'}">${ic('lock')}Interne</button></span>
          <button ${x.A(q.retirer)} class="sva-x" title="retirer">✕</button></div>`).join('')}
        <div class="sva-ajout"><button ${x.A(p.ajouter)} class="mko-pied-btn">+ Ajouter une demande</button></div></div>
      <div class="sva-sect"><h3>Un mot pour ${esc(P.consultant)}</h3><span class="sva-int">interne</span><span class="mini">facultatif</span></div>
      <textarea class="mko-inp sv-ta" rows="2" ${x.I(p.setNote)} placeholder="Le contexte, ce qu’il faut savoir avant d’entrer">${esc(p.note)}</textarea>
      <div class="sva-act"><button ${x.A(P.continuer)} class="cx-btn prim sva-lg" ${P.busy ? 'disabled' : ''}>${P.busy ? 'Enregistrement…' : 'Continuer : l’envoi →'}</button><button ${x.A(P.enregistrer)} class="cx-btn" ${P.busy ? 'disabled' : ''}>Finir plus tard</button><span class="sva-aide">Rien ne part avant l’étape 4.</span></div>`;
  }
  if (P.cle === 'envoi') {
    return tete + `<div class="sva-liste">${P.recap.map(r => `<div class="sva-ln"><span class="sva-di">${ic(r.ic)}</span><span><b>${esc(r.t)}</b><small class="${r.ko ? 'ko' : ''}">${esc(r.s)}</small></span><span></span></div>`).join('')}</div>
      ${P.sansAdresse ? '<div class="ko" style="margin-top:8px;font-size:12px">Aucune adresse pour le franchisé : réglez-la dans Qui fait quoi.</div>' : ''}
      <div class="sva-act"><button ${x.A(P.envoyer)} class="cx-btn prim sva-lg" ${P.busy ? 'disabled' : ''}>${P.busy ? 'Envoi…' : esc(P.libelle) + ' →'}</button>${P.odjMaintenant ? `<button ${x.A(P.odjMaintenant)} class="cx-btn" ${P.busy ? 'disabled' : ''}>Envoyer l’ordre du jour maintenant</button>` : ''}</div>`;
  }
  if (P.cle === 'tablette') {
    return tete.replace('class="sva-lead"', `class="sva-lead${P.ko ? ' ko' : ''}"`) + `<div class="sva-act">${P.suite ? `<button ${x.A(P.suite)} class="cx-btn prim sva-lg">Adapter le rapport →</button>` : ''}<button ${x.A(P.tablette)} class="cx-btn">Ouvrir la visite sur tablette</button></div>`;
  }
  if (P.cle === 'rapport') { return tete + tplRapport(P.rap, x); }
  return tete + tplCorrections(P.corrections || [], P.points || [], x);
}

/* La fiche vivante. */
function ficheHtml(F, x){
  const { esc } = x;
  const puces = F.puces.map(p => typeof p === 'string' ? `<span>${esc(p)}</span>` : `<span class="${p.neuf ? 'neuf' : ''}">${esc(p.t)}</span>`).join('');
  const dest = F.dest.length ? `<div class="sva-fsec"><h4>Qui reçoit quoi</h4>${F.dest.map(d => `<div class="sva-dest${d.off ? ' off' : ''}"><span class="sva-di ${d.ic}">${ic(d.ic)}</span><span><b>${esc(d.qui)}</b><small class="${d.ko ? 'ko' : ''}">${esc(d.quoi)}</small></span><span class="q">${esc(d.quand || '')}</span></div>`).join('')}</div>` : '';
  let odj = '';
  if (F.odj && F.odj.texte) {
    let t = esc(F.odj.texte);
    (F.odj.sujets || []).filter(Boolean).forEach(su => { const e = esc(su); if (e && t.includes(e)) { t = t.split(e).join('<mark>' + e + '</mark>'); } });
    odj = `<div class="sva-fsec"><h4>L’ordre du jour, tel qu’il partira<span class="lang">${esc(F.odj.langue || '')}</span></h4><div class="sva-mail"><b>${esc(F.odj.sujet)}</b><pre>${t}</pre></div></div>`;
  }
  const suite = F.suite.length ? `<div class="sva-fsec"><h4>Et ensuite</h4>${F.suite.map(l => `<div class="sva-tl${l.fait ? ' fait' : ''}${l.cur ? ' cur' : ''}"><i></i><b>${esc(l.quand)}</b><span>${esc(l.quoi)}</span></div>`).join('')}</div>` : '';
  const Q = F.qr;
  const qr = Q ? `<div class="sva-fsec"><h4>Sur la tablette · visite n° ${esc(Q.id)}</h4><div class="sva-qr"><div class="sva-qrc">${Q.svg || '<span class="mko-mu">QR…</span>'}</div>
      <div><b>Scannez avec la tablette</b><small>${Q.faite ? 'elle ouvre cette visite : la review et le plan d’action.' : 'elle ouvre directement le contrôle de cette visite, sans chercher le magasin.'}</small>
      <button ${x.A(Q.copier)} class="sva-lien" title="${esc(Q.lien)}">Copier le lien</button></div></div></div>` : '';
  return `<div class="sva-fiche"><div class="sva-fh"><div class="sva-fk">Fiche de la visite<em>se remplit à chaque étape</em></div><div class="sva-ft">${esc(F.titre)}</div><div class="sva-fs">${esc(F.sous)}</div>${puces ? `<div class="sva-fb">${puces}</div>` : ''}</div>
    ${qr}${dest}${odj}${suite}<div class="sva-fpied">${ic('ok')}${esc(F.pied)}</div></div>`;
}

/* L'étape 5 : le brouillon de la tablette, relu au bureau, puis envoyé. */
function tplRapport(r, x){
  const { esc } = x;
  if (!r || r.chargement) { return '<div class="mko-mu">Lecture du brouillon…</div>'; }
  if (r.indispo) { return `<div class="ko">${esc(r.indispo)}</div>`; }
  const ro = r.envoye ? 'readonly' : '';
  const puces = l => `<div class="cx-puces">${l.map(o => `<button ${r.envoye ? '' : x.A(o.choisir)} class="cx-pu${o.on ? ' on' : ''}"${r.envoye ? ' disabled' : ''}>${esc(o.nom != null ? o.nom : o.n)}</button>`).join('')}</div>`;
  const champ = (lab, val, fn, rows) => `<div class="f sv-f1"><label>${lab}</label><textarea class="mko-inp sv-ta" rows="${rows}" ${ro} ${x.I(fn)}>${esc(val)}</textarea></div>`;
  const texte = carte('Le rapport, partie par partie', r.langueFr ? 'en français' : 'rédigez en ' + esc(r.langue.toLowerCase()) + ' : le franchisé le lit tel quel',
    `${champ('En une phrase', r.phrase, r.setPhrase, 2)}${champ('Ce qui va bien', r.positif, r.setPositif, 2)}
    <div class="gc-form sv-f2"><div class="f"><label>Ressenti</label>${puces(r.sentiment)}</div><div class="f"><label>Exécution</label>${puces(r.execution)}</div><div class="f"><label>Clients</label>${puces(r.clients)}</div></div>
    <div class="f sv-f1"><label>Le vrai problème · causes</label>${puces(r.causes)}</div>
    ${champ('Vu sur place', r.diagnostic, r.setDiagnostic, 3)}${champ('Recommandation', r.reco, r.setReco, 3)}`);
  const plans = carte('Les points', r.nInclus + ' partent au franchisé · ' + r.nInternes + ' restent internes',
    r.plans.length ? r.plans.map(p => `<div class="sv-plan${p.inclus ? '' : ' off'}"><div class="h">${p.modifiable ? bascule(x, p.inclus, p.basculer) : ''}<span class="gc-st ${p.priorite === 'P0' ? 'p0' : ''}">${esc(p.priorite)}</span>
        ${p.modifiable ? `<input class="mko-inp" value="${esc(p.titre)}" ${x.I(p.setTitre)}>` : `<b>${esc(p.titre)}</b>`}<span class="gc-pill ${p.statutCls}">${esc(p.statut)}</span></div>
      ${p.modifiable ? `<div class="sv-plan-d"><input class="mko-inp" value="${esc(p.detail)}" placeholder="Le détail, ce qui est attendu" ${x.I(p.setDetail)}>${sel(x, esc, p.setPrio, p.prios, 'width:70px')}<input type="date" class="mko-inp" style="width:140px" value="${esc(p.echeance)}" ${x.C(p.setEcheance)}></div>` : (p.detail ? `<div class="mko-mu">${esc(p.detail)}</div>` : '')}</div>`).join('')
      : '<div class="mko-mu">Aucun point proposé par la tablette.</div>');
  const photos = carte('Les photos', 'cochées = dans le rapport',
    r.photos.length ? `<div class="sv-photos">${r.photos.map(p => `<button ${x.A(p.basculer)} class="sv-ph${p.inclus ? ' on' : ''}"${p.basculer ? '' : ' disabled'}><img src="${esc(p.src)}" alt="" loading="lazy"><span>${esc(p.genre)}</span></button>`).join('')}</div>` : '<div class="mko-mu">Aucune photo prise pendant la visite.</div>');
  const B = r.budget;
  const budget = B ? carte('Le chiffre face au budget', esc(B.ecarts + (B.arrete ? ' · ' + B.arrete : '')),
    `${B.pourquoi ? `<div class="f sv-f1"><label>Pourquoi · noté avec le franchisé</label><div class="sv-com">${esc(B.pourquoi)}</div></div>` : ''}
    ${B.pistes.length ? `<div class="f sv-f1"><label>Ses trois pistes de développement · ${esc(B.horizon)} ${esc(B.echeance)}</label><ol class="sv-ul">${B.pistes.map(p => `<li>${esc(p)}</li>`).join('')}</ol></div>` : ''}
    ${B.complet ? '' : '<div class="gc-pill att">incomplet à la tablette</div>'}<div class="mko-mu">Dans le rapport tel quel ; les pistes sont aussi parmi les points proposés.</div>`) : '';
  const ck = carte('La checklist', esc(r.checklist), r.ecarts.length ? `<ul class="sv-ul">${r.ecarts.map(e => `<li>${esc(e)}</li>`).join('')}</ul>` : '<div class="mko-mu">Aucun écart relevé.</div>');
  const envoi = carte('L’envoi', esc(r.statut),
    `<div class="sv-kv"><span>À</span><b class="${r.a ? '' : 'ko'}">${esc(r.a || 'aucune adresse — réglez-la dans Qui fait quoi')}</b><span>Langue</span><b>${esc(r.langue)}</b><span>En copie</span><b>${esc(r.copies)}</b><span>Envoie</span><b>${esc(r.envoie || '—')}</b></div>
    ${r.delai ? `<div class="${r.delaiKo ? 'ko' : 'att'}" style="font-weight:600;font-size:12.5px;margin-top:8px">${esc(r.delai)}</div>` : ''}
    ${!r.smtp ? '<div class="gc-pill att" style="margin-top:8px">SMTP non configuré : le rapport ira sur la page du franchisé, sans courriel</div>' : ''}
    ${r.dernier ? `<div class="mko-mu" style="margin-top:8px">${esc(r.dernier)}</div>` : ''}`);
  return `<div class="sva-rap"><div class="sv-pile">${texte}${plans}</div><div class="sv-pile">${envoi}${budget}${photos}${ck}</div></div>
    <div class="sva-act"><button ${x.A(r.voir)} class="cx-btn">Voir le rapport</button>
      ${r.envoye ? `<button ${x.A(r.renvoyer)} class="cx-btn" ${r.busy ? 'disabled' : ''}>Renvoyer le courriel</button>`
        : `<button ${x.A(r.envoyer)} class="cx-btn prim sva-lg" ${r.busy ? 'disabled' : ''}>${r.busy ? 'Envoi…' : 'Envoyer au franchisé →'}</button><button ${x.A(r.enregistrer)} class="cx-btn" ${r.busy ? 'disabled' : ''}>Enregistrer</button>`}</div>`;
}

/* L'étape 6 : les photos de correction du magasin, puis ses points encore ouverts. */
function tplCorrections(corrections, points, x){
  const { esc } = x;
  const corr = corrections.length ? '<div class="sv-corrs">' + corrections.map(p => `<div class="sv-corr"><div class="h"><span class="gc-st ${p.priorite === 'P0' ? 'p0' : ''}">${esc(p.priorite)}</span><b>${esc(p.titre)}</b><span class="${p.horsDelai ? 'ko' : 'mko-mu'}">${esc(p.recu)}</span></div>
      <div class="sv-av"><figure>${p.avant ? `<img src="${esc(p.avant)}" alt="">` : '<div class="sv-noimg">pas de photo</div>'}<figcaption>${esc(p.avantLib)}</figcaption></figure><figure>${p.apres ? `<img src="${esc(p.apres)}" alt="">` : '<div class="sv-noimg">pas de photo</div>'}<figcaption>${esc(p.apresLib)}</figcaption></figure></div>
      ${p.commentaire ? `<div class="sv-com">« ${esc(p.commentaire)} »</div>` : ''}<div class="mko-mu">${esc(p.valide)}</div>
      ${p.rep ? `<div class="sv-rep"><input class="mko-inp" placeholder="Ce qui ne va pas encore" value="${esc(p.rep.retour)}" ${x.I(p.rep.setRetour)}><input type="date" class="mko-inp" style="width:150px" value="${esc(p.rep.echeance)}" ${x.C(p.rep.setEcheance)}><button ${x.A(p.rep.renvoyer)} class="cx-btn prim">Renvoyer au franchisé</button><button ${x.A(p.rep.annuler)} class="mko-pied-btn">Annuler</button></div>`
        : `<div class="sv-pied"><button ${x.A(p.reprendre)} class="cx-btn">À reprendre</button><button ${x.A(p.accepter)} class="cx-btn prim">Accepter</button></div>`}</div>`).join('') + '</div>' : '';
  const tous = `<div class="sva-sect"><h3>Les points encore ouverts</h3><span class="mini">les escaladés, puis par urgence</span></div><div style="overflow-x:auto"><table class="mko-tab sv-tab">
      <thead><tr><th>Prio</th><th>Point</th><th>Échéance</th><th>Statut</th></tr></thead>
      <tbody>${points.length ? points.map(p => `<tr><td><span class="gc-st ${p.priorite === 'P0' ? 'p0' : ''}">${esc(p.priorite)}</span></td><td>${esc(p.titre)}<small>${esc(p.sous)}</small></td>
        <td>${esc(p.echeance)}${p.retard ? `<small class="ko">${esc(p.retard)}</small>` : ''}</td><td><span class="gc-pill ${p.statutCls}">${esc(p.statut)}</span></td></tr>`).join('') : '<tr><td colspan="4" class="mu">Aucun point ouvert.</td></tr>'}</tbody></table></div>`;
  return corr + tous;
}

/* --- 2. Visite sur tablette ---------------------------------------------------------- */
export function tplSvTablette(c, x){
  const { esc } = x, s = c.sv;
  const circuit = [['Préparer', 'au bureau, sur l’ordinateur : dossier, points du bureau, type de visite'], ['Ordre du jour', 'part tout seul au franchisé si la visite est annoncée'],
    ['Visiter', 'sur la tablette, même hors ligne : photo du jour, checklist, contrôles, points, météo, signature'], ['Brouillon', 'à la fin de la visite, il part au bureau avec les points proposés'],
    ['Adapter et envoyer', 'au bureau, sous le délai réglé : le franchisé reçoit son rapport et ses points'], ['Corriger', 'le franchisé envoie sa photo, le bureau valide, le consultant ferme sur place']];
  return `<div data-screen="suivi-tablette"><div class="mko-hd sv-hd"><span class="mko-mu">Tablette de</span>${sel(x, esc, s.setCons, s.consultants)}
      <span class="sv-hd-d"><button ${x.A(s.copier)} class="cx-btn">Copier le lien d’installation</button><a class="cx-btn prim" href="${esc(s.src)}" target="_blank" rel="noopener">Ouvrir en plein écran</a></span></div>
    <div class="sv-tab2"><div class="sv-tabl"><iframe src="${esc(s.src)}" title="Application Visites${s.nom ? ' de ' + esc(s.nom) : ''}"></iframe></div>
    <div class="sv-pile">${carte('Installer sur la tablette', '', `<ol class="sv-ol"><li>Ouvrez ce lien sur la tablette du consultant :<input class="mko-inp" readonly value="${esc(s.lien)}" style="font-size:11px;margin-top:4px"></li><li>Dans le navigateur : « Ajouter à l’écran d’accueil ».</li><li>L’application garde les visites de la semaine : sans réseau, les photos et les réponses attendent dans la file et partent au retour du signal.</li></ol>`)}
      ${carte('Le circuit d’une visite', '', `<ol class="sv-circ">${circuit.map(([t, d]) => `<li><b>${t}</b><span>${d}</span></li>`).join('')}</ol>`)}
      ${carte('Les validations', '', `<div style="font-size:12.5px">Les photos de correction se valident au tableau de bord, onglet « Points et corrections ». La vue admin de l’application reste là : <a class="gc-lien" href="${esc(s.admin)}" target="_blank" rel="noopener">visites/?role=admin ›</a></div>`)}</div></div></div>`;
}

/* --- 3. Client mystère -------------------------------------------------------------------- */
export function tplSvMsp(c, x){
  const { esc } = x, s = c.sv;
  const entete = `<div class="mko-hd sv-hd"><span class="sv-hd-d"><button ${x.A(s.relire)} class="mko-pied-btn">Relire</button></span></div>`;
  if (s.indispo) { return `<div data-screen="suivi-client-mystere">${entete}${panne(esc, s.indispo)}</div>`; }
  if (s.chargement) { return `<div data-screen="suivi-client-mystere">${entete}${attente(esc, 'Lecture des passages du client mystère…')}</div>`; }
  const tab = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Magasin par magasin</span><span class="mko-mini">les rubriques sur 20 · en rouge sous le seuil d’alerte</span></div><div style="overflow-x:auto"><table class="mko-tab sv-tab">
      <thead><tr><th>Magasin</th><th>Dernier passage</th><th class="n">Note</th>${s.rubriques.map(r => `<th class="n">${esc(r)}</th>`).join('')}<th>Précédent</th><th>Ce qui a suivi</th><th></th></tr></thead>
      <tbody>${s.lignes.map(l => `<tr><td class="nom">${esc(l.court)}<small>${esc(l.sous)}</small></td><td>${esc(l.passage)}<small>${esc(l.passageSous)}</small></td><td class="n"><b>${esc(l.note)}</b><small>${esc(l.noteSous)}</small></td>
        ${l.rub.map(r => `<td class="n ${r.cls}">${esc(r.v)}</td>`).join('')}<td class="${l.precCls}">${esc(l.prec)}<small>${esc(l.precSous)}</small></td><td>${esc(l.suite)}<small>${esc(l.suiteSous)}</small></td>
        <td class="sv-act">${l.pdf ? `<a class="gc-lien" href="${esc(l.pdf)}" target="_blank" rel="noopener">PDF</a>` : ''}${l.reencoder ? `<button ${x.A(l.reencoder)} class="mko-pied-btn">Réencoder</button>` : ''}</td></tr>`).join('')}</tbody></table></div></div>`;
  const f = s.form;
  const enc = carte('Encoder un passage', 'réencoder le même trimestre remplace le précédent',
    `<div class="gc-form sv-f3"><div class="f"><label>Magasin</label>${sel(x, esc, f.setShop, f.magasins)}</div><div class="f"><label>Date du passage</label><input type="date" class="mko-inp" value="${esc(f.passage)}" ${x.C(f.setPassage)}></div>
      <div class="f"><label>Note obtenue</label><span class="cx-ligne"><input class="mko-inp" inputmode="decimal" style="width:80px" value="${esc(f.obtenu)}" ${x.I(f.setObtenu)}> / <input class="mko-inp" inputmode="numeric" style="width:70px" value="${esc(f.maximum)}" ${x.I(f.setMaximum)}></span><small>${f.sur5 ? esc(f.sur5) + ' / 5 au scoring' : 'le maximum du rapport reçu'}</small></div></div>
    <div class="gc-form sv-f4">${f.rubriques.map(r => `<div class="f"><label>${esc(r.nom)}</label><input class="mko-inp${r.ko ? ' ko' : ''}" inputmode="decimal" placeholder="/ 20" value="${esc(r.v)}" ${x.I(r.set)}></div>`).join('')}</div>
    ${f.somme ? `<div class="mko-mu" style="margin:-4px 0 8px">rubriques : ${esc(f.somme)}</div>` : ''}
    <div class="f sv-f1"><label>Commentaire du rapport</label><textarea class="mko-inp sv-ta" rows="2" ${x.I(f.setCommentaire)}>${esc(f.commentaire)}</textarea></div>
    <div class="f sv-f1"><label>Le rapport reçu (PDF)</label><input type="file" accept="application/pdf" ${x.C(f.setFichier)}><small>${esc(f.fichierNom || 'facultatif')}</small></div>
    <div class="sv-ligne">${bascule(x, f.montrer, f.basculerMontrer, 'Montré au franchisé sur sa page')}</div>`);
  const suites = carte('Ce qui le suit', esc(f.delais),
    `<div class="gc-mod"><b>Points à travailler</b><span>ouverts chez le franchisé</span></div>
    ${f.points.map(p => `<div class="sv-pt">${sel(x, esc, p.setPrio, p.prios, 'width:70px')}<input class="mko-inp" value="${esc(p.titre)}" ${x.I(p.setTitre)} placeholder="Ce qu’il doit corriger"><button ${x.A(p.retirer)} class="mko-pied-btn">✕</button></div>`).join('')}
    <div class="cx-ligne" style="margin:6px 0 10px"><button ${x.A(f.ajouterPoint)} class="mko-pied-btn">+ Ajouter un point</button><button ${x.A(f.proposer)} class="mko-pied-btn">Proposer depuis les rubriques faibles</button></div>
    <div class="sv-ligne">${bascule(x, f.controle, f.basculerControle, 'Contrôle à la visite suivante')}<small class="mko-mu">${esc(f.prochaine ? 'visite du ' + f.prochaine : 'pas de visite planifiée : le contrôle attendra la prochaine')}</small></div>
    <div class="sv-ligne">${bascule(x, f.tache, f.basculerTache, 'Tâche au consultant responsable')}<small class="mko-mu">${esc(f.responsable || 'pas de responsable au cadre')}</small></div>
    <div class="sv-pied"><button ${x.A(f.vider)} class="cx-btn">Vider</button><button ${x.A(f.enregistrer)} class="cx-btn prim" ${f.busy ? 'disabled' : ''}>${f.busy ? 'Encodage…' : 'Encoder le passage'}</button></div>`);
  const ou = carte('Où il se lit ensuite', '', `<ul class="sv-ul"><li>Le feu du magasin et le dossier de la visite suivante.</li><li>Le scoring du trimestre (note ramenée sur 5).</li><li>Sur la tablette : le module « Client mystère » contrôle les rubriques relevées.</li><li>Sur la page du franchisé, s’il est montré.</li></ul>`);
  return `<div data-screen="suivi-client-mystere">${entete}${msgDe(esc, s.msg)}<div class="gc-kpis">${s.kpis.map(k => kpi(esc, k)).join('')}</div>${tab}<div class="cx-deux" style="margin-top:12px">${enc}<div class="sv-pile">${suites}${ou}</div></div></div>`;
}

/* --- 4. Page du franchisé ----------------------------------------------------------------- */
export function tplSvFranchise(c, x){
  const { esc } = x, s = c.sv;
  const entete = `<div class="mko-hd sv-hd">${sel(x, esc, s.setShop, s.magasins)}${s.url ? `<span class="sv-hd-d"><button ${x.A(s.copier)} class="cx-btn">Copier son lien</button><button ${x.A(s.envoyer)} class="cx-btn prim" ${s.busy ? 'disabled' : ''}>Lui envoyer le lien</button></span>` : ''}</div>`;
  if (s.indispo) { return `<div data-screen="suivi-franchise">${entete}${panne(esc, s.indispo)}</div>`; }
  if (s.chargement) { return `<div data-screen="suivi-franchise">${entete}${attente(esc, 'Lecture de la page du franchisé…')}</div>`; }
  const voit = carte('Ce qu’il voit', '<button ' + x.A(s.regler) + ' class="gc-lien">régler dans Qui fait quoi ›</button>', `<ul class="sv-voit">${s.voit.map(v => `<li class="${v.on ? 'ok' : 'off'}">${v.on ? '✓' : '—'} ${esc(v.l)}</li>`).join('')}</ul>`);
  const pas = carte('Ce qu’il ne voit pas', '', `<ul class="sv-ul"><li>Les brouillons : un rapport n’apparaît qu’une fois envoyé par le bureau.</li><li>Les points gardés en interne et les points proposés pas encore envoyés.</li><li>Les visites inopinées, les notes du bureau, le dossier de préparation.</li><li>La météo du franchisé notée par le consultant.</li></ul>`);
  const retour = carte('Ce qui revient au bureau', '', `<ul class="sv-ul"><li>Sa photo de correction : au tableau de bord, « Points et corrections », pour validation.</li><li>Le rapport lu : la date s’affiche au tableau de bord ; sinon une relance part au bout du délai réglé.</li></ul>`);
  const lien = carte('Son lien', esc(s.langue), `<input class="mko-inp" readonly value="${esc(s.url)}" style="font-size:11px"><div class="mko-mu" style="margin-top:6px">Sans mot de passe : le lien porte une clé propre au magasin. Courriel : ${esc(s.adresse || 'aucune adresse')}${s.source ? ' (' + esc(s.source) + ')' : ''}${s.smtp ? '' : ' · SMTP non configuré'}.</div>
    <button ${x.A(s.basculer)} class="gc-lien" style="margin-top:6px">${s.ouvert ? 'Masquer le courriel' : 'Voir le courriel'}</button>${s.ouvert ? `<div class="sv-mail"><b>${esc(s.sujet)}</b><pre>${esc(s.texte)}</pre></div>` : ''}`);
  return `<div data-screen="suivi-franchise">${entete}${msgDe(esc, s.msg)}<div class="sv-tab2"><div class="sv-tel"><iframe src="${esc(s.src)}" title="Page du franchisé (aperçu)"></iframe></div><div class="sv-pile">${lien}${voit}${pas}${retour}</div></div></div>`;
}

/* --- 5. Qui fait quoi ---------------------------------------------------------------------- */
export function tplSvAdmin(c, x){
  const { esc } = x, s = c.sv;
  if (s.indispo) { return `<div data-screen="suivi-qui-fait-quoi">${panne(esc, s.indispo)}</div>`; }
  if (s.chargement) { return `<div data-screen="suivi-qui-fait-quoi">${attente(esc, 'Lecture des règles du suivi…')}</div>`; }
  const barre = `<div class="sv-barre${s.modifie ? ' on' : ''}"><span>${s.modifie ? 'Modifications non enregistrées.' : 'Les règles valent pour tout le réseau ; un magasin peut avoir les siennes.'}</span>
    ${s.annuler ? `<button ${x.A(s.annuler)} class="cx-btn">Annuler</button>` : ''}<button ${x.A(s.enregistrer)} class="cx-btn prim" ${s.busy || !s.modifie ? 'disabled' : ''}>${s.busy ? 'Enregistrement…' : 'Enregistrer'}</button></div>`;
  const mags = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Par magasin — qui fait quoi</span><span class="mko-mini">sous chaque magasin : son responsable et son cadre de visite</span></div><div style="overflow-x:auto"><table class="mko-tab sv-tab sv-qfq">
      <thead><tr><th>Magasin</th><th>Prépare</th><th>Adapte le rapport</th><th>Envoie</th><th>Valide les corrections</th><th>En copie</th><th>Courriel du franchisé</th><th>Langue</th></tr></thead>
      <tbody>${s.magasins.map(m => `<tr><td class="nom">${esc(m.court)}<small>${esc(m.responsable)}</small><small>${esc(m.cadre)}</small></td>
        <td>${sel(x, esc, m.setPrepare, m.prepare)}</td><td>${sel(x, esc, m.setAdapte, m.adapte)}</td><td>${sel(x, esc, m.setEnvoie, m.envoie)}</td><td>${sel(x, esc, m.setCorrections, m.corrections)}</td>
        <td><div class="sv-cc">${m.copie.map(p => `<button ${x.A(p.basculer)} class="cx-pu${p.on ? ' on' : ''}">${esc(p.nom)}</button>`).join('')}</div></td>
        <td><input class="mko-inp" type="email" style="min-width:190px" placeholder="adresse du franchisé" value="${esc(m.email)}" ${x.C(m.setEmail)}><small class="${m.emailKo ? 'ko' : ''}">${esc(m.emailAide)}</small></td>
        <td>${sel(x, esc, m.setLangue, m.langues)}</td></tr>`).join('')}</tbody></table></div></div>`;
  const types = carte('Les types de visite — annoncée ou inopinée', 'annoncée : l’ordre du jour part au franchisé',
    `<div class="sv-types">${s.types.map(t => `<div class="sv-type"><b>${esc(t.nom)}</b>${duo(x, esc, { nom: 'Annoncée', on: t.annoncee, fn: t.annoncer }, { nom: 'Inopinée', on: !t.annoncee, fn: t.inopiner })}</div>`).join('')}</div>
    <div class="mko-mu" style="margin-top:8px">Une visite inopinée n’envoie rien et n’apparaît pas sur la page du franchisé. Le réglage se change visite par visite au moment de la préparer.</div>`);
  const pers = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Les personnes et leurs droits</span><span class="mko-mini">les consultants viennent du panel</span></div><div style="overflow-x:auto"><table class="mko-tab sv-tab" style="min-width:640px">
      <thead><tr><th>Personne</th>${s.droits.map(d => `<th class="c">${esc(d)}</th>`).join('')}<th></th></tr></thead>
      <tbody>${s.personnes.map(p => `<tr><td class="nom">${esc(p.nom)}<small>${esc(p.sous)}</small></td>${p.droits.map(d => `<td class="c"><input type="checkbox" ${d.on ? 'checked' : ''} ${x.C(d.basculer)}></td>`).join('')}<td>${p.retirer ? `<button ${x.A(p.retirer)} class="mko-pied-btn">Retirer</button>` : ''}</td></tr>`).join('')}
      ${s.nouveauxAdmins.map(n => `<tr><td class="nom mu" colspan="${s.droits.length + 2}">${esc(n)}</td></tr>`).join('')}</tbody></table></div>
    <div class="mko-corps sv-nouv"><input class="mko-inp" placeholder="Nom (admin, responsable réseau…)" value="${esc(s.nouvelAdmin.nom)}" ${x.I(s.nouvelAdmin.setNom)}><input class="mko-inp" type="email" placeholder="Courriel" value="${esc(s.nouvelAdmin.email)}" ${x.I(s.nouvelAdmin.setEmail)}><input class="mko-inp" placeholder="Rôle" value="${esc(s.nouvelAdmin.role)}" ${x.I(s.nouvelAdmin.setRole)}><button ${x.A(s.nouvelAdmin.ajouter)} class="cx-btn">Ajouter</button></div></div>`;
  const D = s.delais;
  const num = (v, fn, u) => `<span class="cx-ligne"><input class="mko-inp" type="number" min="0" style="width:72px" value="${esc(v)}" ${x.C(fn)}><span class="mko-mu">${u}</span></span>`;
  const regle = (lab, aide, ctrl) => `<div class="sv-regle"><div><b>${lab}</b><small>${aide}</small></div><div>${ctrl}</div></div>`;
  const delais = carte('Les règles et les délais', '',
    regle('Dossier de visite', 'prêt avant la visite', num(D.prepJours, D.setPrepJours, 'jours avant'))
    + regle('Ordre du jour au franchisé', 'visites annoncées seulement', `${duo(x, esc, { nom: 'Tout seul', on: D.odjAuto, fn: D.setOdjAuto(true) }, { nom: 'À la main', on: !D.odjAuto, fn: D.setOdjAuto(false) })} ${D.odjAuto ? num(D.odjJours, D.setOdjJours, 'jours avant') : ''}`)
    + regle('Rapport envoyé', 'après la fin de la visite', num(D.rapportHeures, D.setRapportHeures, 'heures'))
    + regle('Délai dépassé', 'si le rapport n’est pas parti', duo(x, esc, { nom: 'Rappel au bureau', on: D.rapportTard === 'rappel', fn: D.setRapportTard('rappel') }, { nom: 'Envoyé tel quel', on: D.rapportTard === 'tel_quel', fn: D.setRapportTard('tel_quel') }))
    + regle('Délais des points', 'échéance proposée selon la priorité', `<span class="cx-ligne">P0 ${num(D.p0, D.setP0, 'j')} P1 ${num(D.p1, D.setP1, 'j')} P2 ${num(D.p2, D.setP2, 'j')}</span>`)
    + regle('Photo de correction validée', 'par la personne réglée au magasin', num(D.validationHeures, D.setValidationHeures, 'heures'))
    + regle('Escalade', 'point en retard remonté à l’admin', num(D.escaladeJours, D.setEscaladeJours, 'jours de retard'))
    + regle('Relance du rapport pas lu', 'un courriel au franchisé', num(D.relanceLuJours, D.setRelanceLuJours, 'jours après l’envoi')));
  const voit = carte('Ce que voit le franchisé', 'sur sa page', `<div class="sv-pile" style="gap:8px">${s.voit.map(v => `<div class="sv-ligne">${bascule(x, v.on, v.basculer, esc(v.l))}</div>`).join('')}</div>`);
  const M = s.modele;
  const modeles = `<div class="mko-carte" style="margin-top:12px"><div class="mko-ct"><span class="mko-lab">Les courriels aux franchisés</span><span class="mko-mini">un modèle par langue · la langue suit le magasin</span></div>
    <div class="mko-corps sv-mod"><div class="sv-modl">${s.modeles.map(m => `<button ${x.A(m.choisir)} class="${m.on ? 'on' : ''}">${esc(m.nom)}${m.modifie ? ' <i title="modifié">•</i>' : ''}</button>`).join('')}</div>
    <div class="sv-pile"><div class="cx-vues">${s.langues.map(l => `<button ${x.A(l.choisir)} class="${l.on ? 'on' : ''}">${esc(l.nom)}${l.modifie ? ' •' : ''}</button>`).join('')}</div>
      <div class="mko-mu">Part ${esc(M.quand)}. Variables : ${M.vars.map(v => `<code>${esc(v)}</code>`).join(' ')}</div>
      <div class="f sv-f1"><label>Objet</label><input class="mko-inp" value="${esc(M.sujet)}" ${x.I(M.setSujet)}></div>
      <div class="f sv-f1"><label>Texte</label><textarea class="mko-inp sv-ta" rows="12" ${x.I(M.setCorps)}>${esc(M.corps)}</textarea><small>Une ligne vide sépare les paragraphes. {sujets} et {points} seuls sur leur ligne deviennent une liste.</small></div>
      <div class="sv-pied" style="justify-content:flex-start"><button ${x.A(M.voir)} class="cx-btn">Aperçu</button>${M.remettre ? `<button ${x.A(M.remettre)} class="cx-btn">Remettre le texte livré</button>` : ''}<button ${x.A(M.enregistrer)} class="cx-btn prim" ${M.change ? '' : 'disabled'}>Enregistrer le modèle</button>
        <span class="cx-ligne" style="margin-left:auto"><input class="mko-inp" type="email" placeholder="adresse d’essai" style="width:200px" value="${esc(M.essaiA)}" ${x.I(M.setEssaiA)}><button ${x.A(M.essai)} class="cx-btn" ${M.smtp ? '' : 'disabled title="SMTP non configuré"'}>Envoyer un essai</button></span></div>
      ${M.apercu ? `<div class="sv-mail"><b>${esc(M.apercu.sujet)}</b><iframe class="sv-ifr court" srcdoc="${esc(M.apercu.html)}" title="Aperçu du courriel"></iframe></div>` : ''}</div></div></div>`;
  return `<div data-screen="suivi-qui-fait-quoi">${msgDe(esc, s.msg)}<div class="gc-kpis">${s.kpis.map(k => kpi(esc, k)).join('')}</div>${barre}${mags}<div class="cx-deux" style="margin-top:12px"><div class="sv-pile">${types}${pers}</div><div class="sv-pile">${delais}${voit}</div></div>${modeles}</div>`;
}

/* --- 6. Sources et API ---------------------------------------------------------------------- */
const ssNf = n => Number(n).toLocaleString('fr-BE');
const ssPl = (n, u) => ssNf(n) + ' ' + u + (n > 1 ? 's' : '');
const ssVerbe = (esc, m) => `<span class="ss-v ${esc(String(m).toLowerCase())}">${esc(m)}</span>`;
const ssChips = (esc, l, cls) => l.length ? l.map(t => `<span class="ss-t ${cls || ''}">${esc(t.nom)}${t.mode ? ` <i>${esc(t.mode)}</i>` : ''}</span>`).join('') : '<span class="mko-mu">—</span>';
export function tplSvSources(c, x){
  const { esc } = x, s = c.sv;
  if (s.indispo) { return `<div data-screen="suivi-sources">${panne(esc, s.indispo)}</div>`; }
  if (s.chargement) { return `<div data-screen="suivi-sources">${attente(esc, 'Lecture du code et de la base : routes, appels au panel, tables…')}</div>`; }
  const tete = `<div class="ss-barre"><span class="mko-mu">Lu dans le code et dans la base ${esc(s.base || '')} le ${esc(s.genereLib)} — rien n’est gardé, chaque ouverture relit tout.</span>
    <div class="ss-act"><button ${x.A(s.relire)} class="mko-pied-btn">${s.enCours ? 'Lecture…' : 'Relire'}</button><button ${x.A(s.copierTout)} class="cx-btn">Copier la spec (Markdown)</button><button ${x.A(s.telecharger)} class="cx-btn prim">Télécharger .md</button></div></div>`;
  const onglets = `<div class="ss-og">${s.onglets.map(o => `<button ${x.A(o.ouvrir)} class="${o.on ? 'on' : ''}">${esc(o.nom)}${o.n !== '' ? ` <b>${esc(o.n)}</b>` : ''}</button>`).join('')}</div>`;
  const filtre = s.og === 'ensemble' ? '' : `<div class="ss-filtre"><input class="mko-inp" placeholder="Filtrer : une route, une table, un chemin…" value="${esc(s.q)}" ${x.I(s.setQ)}></div>`;
  let corps = '';
  if (s.og === 'ensemble') {
    const E = s.ensemble;
    const panelC = carte(`Panel ${esc(s.hote)}/api/v1`, s.panelConfigure ? 'compte consultant configuré' : 'compte non configuré ici',
      `<div class="ss-liste">${E.panel.map(p => `<div>${ssVerbe(esc, p.m)}<code>${esc(p.chemin)}</code><small>${esc(ssPl(p.n, 'route'))}</small></div>`).join('')}</div>
      ${E.panelReste ? `<button ${x.A(E.allerPanel)} class="gc-lien">+ ${esc(E.panelReste)} autres chemins ›</button>` : ''}${E.erp.length ? `<div class="mko-mu" style="margin-top:8px">ERP admin : ${E.erp.map(p => esc(p.m + ' ' + p.chemin)).join(', ')}</div>` : ''}`);
    const conC = carte('Tables locales <code>con_</code>', 'lues et écrites par le cockpit, sans API',
      `${E.familles.map(f => `<div class="ss-fam"><b>${esc(f.nom)}</b><div>${f.tables.map(t => `<span class="ss-t con">${esc(t.nom)} <i>${t.existe === false ? 'pas créée' : esc(ssNf(t.lignes || 0))}</i></span>`).join('')}</div></div>`).join('')}
      <div class="mko-mu" style="margin-top:8px">${esc(s.migration)} ${E.autres ? esc(ssPl(E.autres, 'autre table') + ' du cockpit (réglages, journal, rapports, autres modules) sont lues ou écrites au passage.') : ''}</div>
      <button ${x.A(E.allerTables)} class="gc-lien" style="margin-top:6px">Structure des tables ›</button>`);
    const tfbC = carte('Base TFBuddy, lue sans API', 'à remplacer par une lecture du panel',
      `<div class="ss-liste">${E.directes.map(t => `<div><span class="ss-t tfb">${esc(t.nom)}</span><span class="ss-q">${esc(t.quoi)}</span>${t.vers ? `<small class="${t.existe ? 'ok' : 'att'}">${esc(t.existe ? 'existe : ' : 'à ouvrir : ')}${esc(t.vers)}</small>` : ''}</div>`).join('')}</div>
      ${E.directesReste ? `<div class="mko-mu">+ ${esc(ssPl(E.directesReste, 'autre table'))}</div>` : ''}`);
    const cible = carte('Pour que tout passe par l’API', '', `<ol class="sv-ol"><li>Le panel ouvre les <b>ressources des tables <code>con_</code></b>, en lecture et en écriture (liste, lecture, création, modification, suppression) : onglet « API manquantes », spécification prête à envoyer.</li>
      <li>Les <b>tables TFBuddy lues en direct</b> passent par une lecture du panel : la route existe déjà pour certaines, il faut l’ouvrir pour les autres.</li>
      <li>Le cockpit bascule route par route : chaque table dit les routes qui changeraient.</li></ol>
      <button ${x.A(E.allerManquantes)} class="cx-btn prim" style="margin-top:8px">Voir les API manquantes</button>`);
    corps = `<div class="ss-g2">${panelC}${conC}${tfbC}${cible}</div><div class="mko-mu" style="margin-top:10px">${esc(s.limites)}</div>`;
  } else if (s.og === 'ecrans') {
    corps = s.ecrans.map(e => `<div class="mko-carte ss-bloc"><div class="mko-ct"><span class="mko-lab">${esc(e.nom)}</span><span class="mko-mini">${esc(ssPl(e.routes.length, 'route'))}</span></div>
      <div style="overflow-x:auto"><table class="mko-tab ss-tab"><thead><tr><th>Route du cockpit</th><th>Ce qu’elle fait</th><th class="c">Source</th><th class="n">Panel</th><th class="n">con_</th><th class="n">TFBuddy direct</th><th class="n">Cockpit</th><th>Autres</th></tr></thead>
      <tbody>${e.routes.map(r => `<tr class="ss-ligne${r.ouvert ? ' on' : ''}" ${x.A(r.basculer)}><td class="nom">${ssVerbe(esc, r.m)}<code>${esc(r.chemin)}</code>${r.existe ? '' : ' <span class="ko">absente</span>'}</td><td>${esc(r.quoi)}</td>
        <td class="c"><span class="ss-src ${esc(r.source)}">${esc({ panel: 'panel', locale: 'locale', mixte: 'panel + locale' }[r.source])}</span></td><td class="n">${r.nPanel || '·'}</td><td class="n">${r.nCon || '·'}</td><td class="n${r.nTfb ? ' att' : ''}">${r.nTfb || '·'}</td><td class="n">${r.nCk || '·'}</td><td>${esc(r.externes.join(', '))}</td></tr>
        ${r.detail ? `<tr class="ss-det"><td colspan="8"><div class="ss-det-g"><div><b>Panel</b>${r.detail.panel.length ? r.detail.panel.map(p => `<div>${ssVerbe(esc, p.m)}<code>${esc(p.chemin)}</code>${p.erp ? ' <small>ERP</small>' : ''}</div>`).join('') : '<div class="mko-mu">aucun appel</div>'}</div>
          <div><b>Tables con_</b><div>${ssChips(esc, r.detail.con, 'con')}</div><b>Base TFBuddy, sans API</b><div>${ssChips(esc, r.detail.tfbuddy, 'tfb')}</div><b>Autres tables du cockpit</b><div>${ssChips(esc, r.detail.cockpit)}</div>
          ${r.detail.installe.length ? `<small class="mko-mu">Créées au besoin (installation) : ${esc(r.detail.installe.join(', '))}</small>` : ''}<small class="mko-mu">${esc(r.fonction)} · ${esc(r.fichier || '')}</small></div></div></td></tr>` : ''}`).join('')}</tbody></table></div></div>`).join('') || attente(esc, 'Aucune route ne correspond au filtre.');
  } else if (s.og === 'panel') {
    corps = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Ce que le module appelle au panel ${esc(s.hote)}/api/v1</span><span class="mko-mini">${s.panelConfigure ? 'compte consultant configuré' : 'compte non configuré sur ce serveur'}</span></div>
      <div style="overflow-x:auto"><table class="mko-tab ss-tab"><thead><tr><th>Chemin</th><th>Usage</th><th class="n">Routes</th></tr></thead><tbody>
      ${s.chemins.map(p => `<tr class="ss-ligne${p.ouvert ? ' on' : ''}" ${x.A(p.basculer)}><td class="nom">${ssVerbe(esc, p.m)}<code>${esc(p.chemin)}</code>${p.erp ? ' <small>ERP admin</small>' : ''}</td><td>${p.ecriture ? '<span class="att">écriture</span>' : 'lecture'}</td><td class="n">${esc(p.n)}</td></tr>
        ${p.ouvert ? `<tr class="ss-det"><td colspan="3">${p.routes.map(r => `<code class="ss-r">${esc(r)}</code>`).join(' ')}</td></tr>` : ''}`).join('')}</tbody></table></div></div>`;
  } else if (s.og === 'tables') {
    corps = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Les tables du module, sous <code>con_</code></span><span class="mko-mini">${esc(s.migration)}</span></div>
      <div style="overflow-x:auto"><table class="mko-tab ss-tab"><thead><tr><th>Table</th><th>Famille</th><th>Ce qu’elle porte</th><th class="n">Lignes</th><th>Ancien nom</th><th class="n">Routes</th></tr></thead><tbody>
      ${s.tables.map(t => `<tr class="ss-ligne${t.ouvert ? ' on' : ''}" ${x.A(t.basculer)}><td class="nom"><code>${esc(t.nom)}</code></td><td>${esc(t.famille)}</td><td>${esc(t.quoi)}</td><td class="n">${t.lignes == null ? '—' : esc(ssNf(t.lignes))}</td>
        <td><code class="mu">${esc(t.ancien)}</code> <small class="${esc(t.etatCls)}">${esc(t.etat)}</small></td><td class="n">${esc(t.n)}</td></tr>
        ${t.ouvert ? `<tr class="ss-det"><td colspan="6"><div class="ss-det-g"><div><b>Colonnes</b>${t.champs.length ? `<table class="ss-cols"><tr><th>Champ</th><th>Type</th><th>Obligatoire</th><th>Défaut</th></tr>${t.champs.map(ch => `<tr><td><code>${esc(ch.nom)}</code>${ch.cle === 'PRI' ? ' <small>clé</small>' : ''}${ch.auto ? ' <small>auto</small>' : ''}</td><td>${esc(ch.sql)}</td><td>${ch.requis ? 'oui' : ''}</td><td>${ch.defaut == null ? '' : (ch.defaut === '' ? '<span class="mko-mu">chaîne vide</span>' : `<code>${esc(ch.defaut)}</code>`)}</td></tr>`).join('')}</table>` : '<div class="mko-mu">table pas encore créée sur ce serveur</div>'}</div>
          <div><b>Routes</b><div>${t.routes.map(r => `<code class="ss-r">${esc(r)}</code>`).join(' ') || '—'}</div>${t.ddl ? `<b>CREATE TABLE</b><pre class="ss-pre">${esc(t.ddl)}</pre>` : ''}</div></div></td></tr>` : ''}`).join('')}</tbody></table></div></div>
      <div class="mko-carte" style="margin-top:12px"><div class="mko-ct"><span class="mko-lab">Autres tables du cockpit touchées au passage</span><span class="mko-mini">réglages, journal, rapports, autres modules — locales aussi</span></div>
      <div class="mko-corps"><div class="ss-chips">${s.autres.map(t => `<span class="ss-t">${esc(t.nom)} <i>${t.ecrite ? 'écrite' : 'lue'} · ${esc(ssPl(t.n, 'route'))}</i></span>`).join('')}</div></div></div>`;
  } else {
    const verbe = e => `<div class="ss-ep">${ssVerbe(esc, e.m)}<code>${esc(e.chemin)}</code><span>${esc(e.dit)}</span></div>`;
    corps = `<div class="ss-intro mko-carte"><div class="mko-corps"><b>Pour que tout passe par le panel, en lecture et en écriture :</b> chaque table <code>con_</code> devient une ressource du panel (cinq verbes, le corps JSON tiré de ses colonnes), et chaque table TFBuddy lue en direct, une lecture. « Copier la spec » donne le Markdown d’une ressource ; « Télécharger .md », tout.</div></div>
      ${s.manquantes.map(m => `<div class="mko-carte ss-bloc"><div class="mko-ct"><span class="mko-lab"><code>${esc(m.ressource)}</code></span><span class="mko-mini">table <code>${esc(m.table)}</code> · ${esc(m.famille)} · ${esc(ssPl(m.n, 'route'))} du cockpit</span>
          <span class="ss-ct-d"><button ${x.A(m.copier)} class="mko-pied-btn">Copier la spec</button><button ${x.A(m.basculer)} class="mko-pied-btn">${m.ouvert ? 'Replier' : 'Structure'}</button></span></div>
        <div class="mko-corps"><div class="mko-mu" style="margin-bottom:6px">${esc(m.quoi)}.</div>${m.endpoints.length ? m.endpoints.map(verbe).join('') : '<div class="mko-mu">La table n’existe pas encore sur ce serveur : sa structure se lira quand elle aura servi.</div>'}
        ${m.ouvert ? `<div class="ss-det-g" style="margin-top:10px">${m.corps ? `<div><b>Corps JSON (création)</b><pre class="ss-pre">${esc(m.corps)}</pre></div>` : ''}<div><b>Champs</b><table class="ss-cols"><tr><th>Champ</th><th>Type</th><th>Obligatoire</th></tr>${m.champs.map(ch => `<tr><td><code>${esc(ch.nom)}</code>${ch.auto ? ' <small>auto</small>' : ''}</td><td>${esc(ch.type)} <small class="mko-mu">${esc(ch.sql)}</small></td><td>${ch.requis ? 'oui' : ''}</td></tr>`).join('')}</table>${m.ddl ? `<b>Structure actuelle</b><pre class="ss-pre">${esc(m.ddl)}</pre>` : ''}</div></div>` : ''}</div></div>`).join('')}
      <div class="mko-carte ss-bloc"><div class="mko-ct"><span class="mko-lab">Lectures à ouvrir — la base TFBuddy lue sans API</span><span class="mko-mini">${esc(ssPl(s.directes.length, 'table'))}</span></div>
        <div style="overflow-x:auto"><table class="mko-tab ss-tab"><thead><tr><th>Table</th><th>Ce qu’elle porte</th><th>Au panel aujourd’hui</th><th>À ouvrir</th><th class="n">Routes</th></tr></thead><tbody>
        ${s.directes.map(t => `<tr class="ss-ligne${t.ouvert ? ' on' : ''}" ${x.A(t.basculer)}><td class="nom"><code>${esc(t.nom)}</code></td><td>${esc(t.quoi)}</td><td>${t.existe ? `<code class="ok">${esc(t.existe)}</code>` : '<span class="mko-mu">—</span>'}</td><td>${t.propose ? `<code class="att">${esc(t.propose)}</code>` : '<span class="mko-mu">basculer sur la route existante</span>'}</td><td class="n">${esc(t.n)}</td></tr>
          ${t.ouvert ? `<tr class="ss-det"><td colspan="5"><div class="ss-det-g"><div><b>Routes du cockpit</b><div>${t.routes.map(r => `<code class="ss-r">${esc(r)}</code>`).join(' ')}</div></div><div><b>Colonnes${t.lignes != null ? ' · ' + esc(ssNf(t.lignes)) + ' lignes (estimées)' : ''}</b><div class="ss-chips">${t.colonnes.length ? t.colonnes.map(ch => `<span class="ss-t">${esc(ch.nom)} <i>${esc(ch.sql)}</i></span>`).join('') : '<span class="mko-mu">pas dans la base de ce serveur</span>'}</div></div></div></td></tr>` : ''}`).join('')}</tbody></table></div></div>`;
  }
  return `<div data-screen="suivi-sources">${tete}${msgDe(esc, s.msg)}<div class="gc-kpis">${s.kpis.map(k => kpi(esc, k)).join('')}</div>${onglets}${filtre}${corps}</div>`;
}
