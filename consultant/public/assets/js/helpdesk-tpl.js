/* Cockpit CEO — Franchisés › Demandes des franchisés (15/11/2026) : le gabarit. Valeurs : helpdesk-vals.js ;
 * style : helpdesk.css (hd-). Maquettes : docs/maquettes/helpdesk-franchises (A, B). */

export function tplHelpdesk(c, x){
  const { esc } = x;
  const V = c.hd;
  if (!V) { return ''; }
  const onglets = `<div class="hd-onglets">${V.onglets.map(o => `<button class="${o.on ? 'on' : ''}" ${x.A(o.choisir)}>${esc(o.nom)}${o.sous ? `<small>${esc(o.sous)}</small>` : ''}</button>`).join('')}</div>`;
  if (V.vue === 'reclamations') { return `<div data-screen="helpdesk" class="vr hd">${onglets}${tplReclamations(V.rc, x)}</div>`; }
  if (V.chargement) { return `<div data-screen="helpdesk" class="vr hd">${onglets}<div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture des demandes du helpdesk au panel…</div></div>`; }
  if (V.erreur) { return `<div data-screen="helpdesk" class="vr hd">${onglets}<div class="vr-card vr-etat ko">${esc(V.erreur)}</div></div>`; }
  const kpis = `<div class="vm-kpis">${V.kpis.map(k => `<div class="vm-kpi ${k[3]}"><div class="k">${esc(k[0])}</div><div class="v">${esc(String(k[1]))}</div><div class="s">${esc(k[2])}</div></div>`).join('')}</div>`;
  const fil = `<div class="vt-fil"><div class="vr-chips hd-chips">${V.chips.map(ch => `<button class="${ch.on ? 'on' : ''}" ${x.A(ch.choisir)}>${esc(ch.nom)} <b>${ch.n}</b></button>`).join('')}</div>
    <select class="vr-sel" ${x.C(V.poserDom)}>${V.domaines.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>
    <select class="vr-sel" ${x.C(V.poserMag)}>${V.magasins.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>
    <input id="hd-q" class="vm-q" type="search" placeholder="Chercher une demande…" value="${esc(V.q)}" ${x.I(V.poserQ)}><span class="vr-sp"></span>
    <button class="vr-btn" ${x.A(V.relire)}${V.relecture ? ' disabled' : ''}>${V.relecture ? 'Lecture…' : 'Relire'}</button></div>`;
  const ligne = l => `<tr class="${l.on ? 'hd-on' : ''}" ${x.A(l.choisir)}><td class="hd-lis ${l.ton}"></td>
    <td class="g"><span class="hd-ref">${esc(l.ref)}</span><small>${esc(l.le)}</small></td><td class="g">${esc(l.magasin)}</td><td class="g"><span class="hd-dom">${esc(l.domaine)}</span></td>
    <td class="g hd-titre"><b>${esc(l.titre)}</b><small>${esc(l.extrait)}</small></td><td class="g">${esc(l.consultant)}</td>
    <td><span class="hd-st ${l.ton}">${esc(l.statut)}</span>${l.rep ? `<small class="${l.repKo ? 'ko' : ''}">${esc(l.rep)}</small>` : ''}</td>
    <td class="hd-ech ${l.echTon}">${l.ech ? `<span>${esc(l.ech)}</span>${l.echSous ? `<small>${esc(l.echSous)}</small>` : ''}` : ''}</td><td class="${l.vieux ? 'hd-vieux' : ''}">${esc(l.age)}</td><td class="g">${l.visite ? `<span class="hd-vis">${esc(l.visite)}</span>` : ''}</td></tr>`;
  const table = V.lignes.length ? `<div class="vr-tw"><table class="vr-t hd-t"><thead><tr><th></th><th class="g">Réf.</th><th class="g">Magasin</th><th class="g">Domaine</th><th class="g">Demande</th><th class="g">Consultant</th><th>Statut</th><th>Date limite</th><th>Âge</th><th class="g">Visite</th></tr></thead>
    <tbody>${V.lignes.map(ligne).join('')}</tbody></table></div>` : `<div class="vr-vide">Aucune demande pour ce filtre.</div>`;
  const pied = `<div class="vr-pied"><b>Les demandes des franchisés</b> : le helpdesk du panel (${V.compte === 'admin' ? 'compte administrateur du cockpit' : 'compte consultant : seules les demandes d’un magasin'}). Une demande se lit, s’attribue, reçoit une réponse — le franchisé la lit dans son dashboard — et, quand elle demande un passage, devient une <b>mission de la visite</b> du magasin : deux tâches du consultant (contrôler, puis répondre), que l’application terrain montre sur la fiche de la visite, et ses points à contrôler. À la clôture de la mission, le constat repart en réponse et la demande passe « Résolue » si tout est conforme. <span class="vr-mu">${esc(V.source)}</span></div>`;
  return `<div data-screen="helpdesk" class="vr hd">${onglets}<div class="vr-card">${kpis}${fil}<div class="hd-cols">${table}${fiche(V.fiche, x)}</div>${sms(V.sms, x)}${pied}</div></div>`;
}

/** Le SMS au bureau (20/11/2026) : à qui part le résumé de chaque nouvelle demande ; « Régler » ouvre la liste des numéros. */
function sms(M, x){
  const { esc } = x;
  if (!M) { return ''; }
  if (M.chargement) { return '<div class="hd-sms"><span class="vr-mu">Lecture du réglage des SMS…</span></div>'; }
  if (M.erreur) { return `<div class="hd-sms"><span class="hd-sms-ko">SMS au bureau : ${esc(M.erreur)}</span></div>`; }
  const tete = `<div class="hd-sms-t"><b>SMS au bureau</b><span class="${M.alerte ? 'hd-sms-ko' : ''}">${esc(M.resume)}</span><span class="vr-sp"></span><button class="mko-pied-btn" ${x.A(M.basculer)}>${M.ouvert ? 'Fermer' : 'Régler'}</button></div>`;
  const sansFournisseur = M.fournisseur ? '' : '<div class="hd-sms-ko">Aucun fournisseur SMS réglé : réglez SMSAPI dans MétaDonnée › Alertes SMS. Rien ne part en attendant.</div>';
  if (!M.ouvert) { return `<div class="hd-sms">${tete}${M.actif ? sansFournisseur : ''}<div class="vr-mu">${esc(M.dernier)}</div></div>`; }
  const lignes = M.lignes.map(l => `<div class="hd-sms-l"><input class="mko-inp" placeholder="Nom" value="${esc(l.nom)}" ${x.C(l.setNom)}><input class="mko-inp${l.ko ? ' hd-sms-vide' : ''}" type="tel" placeholder="+32 470 12 34 56" value="${esc(l.tel)}" ${x.C(l.setTel)}><button class="mko-pied-btn" ${x.A(l.retirer)}>Retirer</button></div>`).join('');
  return `<div class="hd-sms on">${tete}
    <p class="vr-mu">À chaque demande envoyée depuis le dashboard d’un franchisé, un SMS part à ces numéros : le magasin, le sujet, le titre, le début de la demande, le passage souhaité, les photos et la référence. Le franchisé ne voit pas ces numéros.</p>
    <div class="hd-seg"><span>SMS</span><button class="${M.fActif ? 'on' : ''}" ${x.A(M.allumer)}>envoyés</button><button class="${M.fActif ? '' : 'on'}" ${x.A(M.couper)}>coupés</button></div>
    ${lignes || '<div class="vr-mu">Aucun numéro.</div>'}
    <div class="hd-act">${M.ajouter ? `<button class="mko-pied-btn" ${x.A(M.ajouter)}>+ Ajouter un numéro</button>` : ''}<span class="vr-sp"></span>${M.msg ? `<span class="${M.msgOk ? 'hd-okmsg' : 'hd-sms-ko'}">${esc(M.msg)}</span>` : ''}<button class="rc-btn ok" ${x.A(M.enregistrer)}${M.enregistrer ? '' : ' disabled'}>${M.enCours ? 'Enregistrement…' : 'Enregistrer'}</button></div>
    ${sansFournisseur}<div class="vr-mu">${esc(M.dernier)}</div></div>`;
}

function fiche(F, x){
  const { esc } = x;
  if (!F) { return '<aside class="vr-card hd-fiche"><div class="vr-mu">Choisissez une demande.</div></aside>'; }
  const E = F.envoi;
  const msg = E && !E.enCours && E.msg ? `<div class="${E.ok ? 'hd-okmsg' : 'rc-ko'}">${E.ok ? '' : '✕ '}${esc(E.msg)}</div>` : (E && E.enCours ? '<div class="vr-mu">Envoi au panel…</div>' : '');
  const mission = m => `<div class="hd-mission${m.cloture ? ' close' : ''}"><div class="hd-mt"><span class="hd-src">Mission de visite</span><b>${esc(m.visite)} · ${esc(m.quand)}</b>${m.cloture ? `<span class="hd-st ok">clôturée le ${esc(m.cloture)}</span>` : ''}</div>
    ${m.points.map(p => `<div class="hd-pt"><div><b>${esc(p.nom)}</b></div><div class="hd-ok3">${p.etats.map(e => `<button class="${e.on ? 'on ' + e.k : ''}" ${x.A(e.choisir)}${e.choisir ? '' : ' disabled'}>${esc(e.nom)}</button>`).join('')}</div>
      <input id="${esc(p.id)}" class="pp-in" placeholder="Constat…" value="${esc(p.constat)}" ${x.I(p.poser)}${m.cloture ? ' disabled' : ''}></div>`).join('')}
    ${m.cloture ? (m.reponse ? `<pre class="hd-rep">${esc(m.reponse)}</pre>` : '') : `<div class="hd-act"><button class="vx-tog${m.repondre ? ' on' : ''}" ${x.A(m.basculerRepondre)}><i></i>à la clôture, envoyer le constat en réponse au franchisé</button><span class="vr-sp"></span>
      <button class="rc-btn gris" ${x.A(m.enregistrer)}>Enregistrer</button><button class="rc-btn ok" ${x.A(m.cloturer)}${m.cloturer ? '' : ' disabled'} title="${m.manque ? m.manque + ' point(s) à voir' : ''}">Clôturer la mission</button></div>`}</div>`;
  return `<aside class="vr-card hd-fiche"><div class="hd-ft"><div><span class="hd-ref">${esc(F.ref)}</span><h3>${esc(F.titre)}</h3><small>${esc(F.sous)}</small></div><span class="hd-st ${F.ton}">${esc(F.statut)}</span></div>
    <div class="hd-desc">${esc(F.description)}</div>
    <div class="hd-delai ${F.delaiTon}"><div class="hd-seg"><span>Date limite</span>${F.delais.map(d => `<button class="${d.on ? 'on' : ''}" ${x.A(d.choisir)}${d.choisir ? '' : ' disabled'}>${esc(d.nom)}</button>`).join('')}${F.retirerDelai ? `<button class="hd-delai-x" ${x.A(F.retirerDelai)} title="Retirer la date limite">✕</button>` : ''}</div><small>${esc(F.delaiNote)}</small></div>
    ${F.reponse ? `<div class="hd-dejarep"><b>Réponse du bureau${F.reponseLe ? ' · ' + esc(F.reponseLe) : ''}</b>${esc(F.reponse)}</div>` : ''}
    ${msg}
    <div class="hd-grille"><label>Statut<select class="pp-in" ${x.C(F.poserStatut)}>${F.statuts.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></label>
      <label>Consultant<select class="pp-in" ${x.C(F.poserConsultant)}>${F.consultants.map(o => `<option value="${esc(o.id)}">${esc(o.nom)}</option>`).join('')}</select><small>${esc(F.consNote)}</small></label></div>
    ${F.missions.map(mission).join('')}
    <div class="hd-bloc"><h4>Reprendre dans une visite <span>— la demande devient une mission de la visite, à contrôler sur place</span></h4>
      ${F.sansVisite ? '<div class="vr-mu">Aucune visite planifiée pour ce magasin : planifiez-la dans Gestion consultant, puis revenez.</div>' : `
      <label>Visite<select class="pp-in" ${x.C(F.poserVisite)}>${F.visites.map(o => `<option value="${o.id}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></label>
      <div class="hd-seg"><span>Quand</span>${F.quand.map(q => `<button class="${q.on ? 'on' : ''}" ${x.A(q.choisir)}>${esc(q.nom)}</button>`).join('')}</div>
      <label>Ce qu’il faut vérifier sur place <small>un point par ligne</small><textarea id="hd-pts" class="pp-in" rows="3" placeholder="Un point à contrôler par ligne…" ${x.I(F.poserPoints)}>${esc(F.points)}</textarea></label>
      <div class="hd-act"><button class="rc-btn" ${x.A(F.ajouter)}${F.ajouter ? '' : ' disabled'}>Ajouter à la visite</button><span class="vr-mu">la demande passe « En cours » ; deux tâches s’ajoutent à la visite</span></div>`}</div>
    <div class="hd-bloc"><h4>Répondre au franchisé <span>— il la lit dans son dashboard</span></h4>
      <textarea id="hd-rep" class="pp-in" rows="3" placeholder="La réponse du bureau…" ${x.I(F.poserRep)}>${esc(F.rep)}</textarea>
      <div class="hd-act"><select class="vr-sel" ${x.C(F.poserRepStatut)}>${F.repStatuts.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select><span class="vr-sp"></span>
        <button class="rc-btn ok" ${x.A(F.repondre)}${F.repondre ? '' : ' disabled'}>Envoyer la réponse</button></div></div></aside>`;
}

/** Les réclamations fournisseur de tous les magasins (20/11/2026) : lecture seule, le fournisseur y répond. */
function tplReclamations(R, x){
  const { esc } = x;
  if (R.chargement) { return '<div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture des réclamations fournisseur de tous les magasins au panel…</div>'; }
  if (R.erreur) { return `<div class="vr-card vr-etat ko">${esc(R.erreur)}</div>`; }
  const kpis = `<div class="vm-kpis">${R.kpis.map(k => `<div class="vm-kpi ${k[3]}"><div class="k">${esc(k[0])}</div><div class="v">${esc(String(k[1]))}</div><div class="s">${esc(k[2])}</div></div>`).join('')}</div>`;
  const sel = (opts, fn) => `<select class="vr-sel" ${x.C(fn)}>${opts.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>`;
  const fil = `<div class="vt-fil"><div class="vr-chips hd-chips">${R.chips.map(ch => `<button class="${ch.on ? 'on' : ''}" ${x.A(ch.choisir)}>${esc(ch.nom)} <b>${ch.n}</b></button>`).join('')}</div>
    ${sel(R.magasins, R.poserMag)}${sel(R.fournisseurs, R.poserFour)}${sel(R.periodes, R.poserPeriode)}
    <input id="hd-rc-q" class="vm-q" type="search" placeholder="Chercher une référence, un motif…" value="${esc(R.q)}" ${x.I(R.poserQ)}><span class="vr-sp"></span>
    <button class="vr-btn" ${x.A(R.relire)}${R.relecture ? ' disabled' : ''}>${R.relecture ? 'Lecture…' : 'Relire'}</button></div>`;
  const ligne = l => `<tr class="${l.on ? 'hd-on' : ''}" ${x.A(l.choisir)}><td class="hd-lis ${l.ton}"></td>
    <td class="g">${esc(l.le)}<small class="${l.vieux ? 'ko' : ''}">${esc(l.age)}</small></td><td class="g">${esc(l.magasin)}</td><td class="g">${esc(l.fournisseur)}</td>
    <td class="g hd-titre"><b>${esc(l.reference)}</b><small>${esc(l.qte)}${l.sku ? ' · SKU ' + esc(l.sku) : ''}</small></td>
    <td class="g hd-titre"><span class="hd-dom">${esc(l.motif)}</span>${l.extrait ? `<small>${esc(l.extrait)}</small>` : ''}</td>
    <td><span class="hd-st ${l.ton}">${esc(l.statut)}</span>${l.sousStatut ? `<small>${esc(l.sousStatut)}</small>` : ''}${l.reponse ? `<small>répondue${l.reponseLe ? ' le ' + esc(l.reponseLe) : ''}</small>` : ''}</td><td>${esc(l.montant)}</td></tr>`;
  const table = R.lignes.length ? `<div class="vr-tw"><table class="vr-t hd-t"><thead><tr><th></th><th class="g">Date</th><th class="g">Magasin</th><th class="g">Fournisseur</th><th class="g">Référence</th><th class="g">Motif</th><th>Statut</th><th>Montant</th></tr></thead>
    <tbody>${R.lignes.map(ligne).join('')}</tbody></table></div>` : '<div class="vr-vide">Aucune réclamation pour ce filtre.</div>';
  const F = R.fiche;
  const fiche = F ? `<aside class="vr-card hd-fiche"><div class="hd-ft"><div><span class="hd-ref">${esc(F.ref)}</span><h3>${esc(F.titre)}</h3><small>${esc(F.sous)}</small></div><span class="hd-st ${F.ton}">${esc(F.statut)}</span></div>
    ${F.texte ? `<div class="hd-desc">${esc(F.texte)}</div>` : ''}
    <div class="hd-rc-kv">${F.champs.map(([k, val]) => `<span>${esc(k)}</span><b>${esc(val)}</b>`).join('')}</div>
    ${F.reponse ? `<div class="hd-dejarep"><b>Réponse du fournisseur${F.reponseLe ? ' · ' + esc(F.reponseLe) : ''}</b>${esc(F.reponse)}</div>` : '<div class="vr-mu" style="margin-top:10px">Pas encore de réponse du fournisseur.</div>'}</aside>`
    : '<aside class="vr-card hd-fiche"><div class="vr-mu">Choisissez une réclamation.</div></aside>';
  const pied = `<div class="vr-pied"><b>Les réclamations fournisseur de tous les magasins</b>, déposées depuis le dashboard des franchisés (contrôle, invendus) ou au panel. Une réclamation est <b>ouverte</b> tant que le fournisseur n’a pas répondu (et ne l’a pas refusée). ${esc(R.lecture || '')}${R.ecartees ? ' ' + R.ecartees + ' plus ancienne' + (R.ecartees > 1 ? 's' : '') + ' hors période.' : ''} <span class="vr-mu">${esc(R.source || '')}</span></div>`;
  return `<div class="vr-card">${kpis}${fil}<div class="hd-cols">${table}${fiche}</div>${pied}</div>`;
}
