/* Cockpit CEO — gabarits de la section « Franchisés · évaluation et suivi » (09/10/2026) :
 * Fiche franchisé, Contrôles qualité du mois (Gestion consultant retirée le 18/10/2026).
 * Mêmes conventions que templates.js : x = { A, C, I, esc }. */

const kpi = (esc, k) => `<div class="gc-kpi"><div class="k">${esc(k[0])}</div><div class="v ${k[3] || ''}">${esc(k[1])}</div><div class="s">${esc(k[2] || '')}</div></div>`;
const sel = (x, esc, fn, opts, style) => `<select ${x.C(fn)} class="mko-sel" style="${style || ''}">${opts.map(o => `<option value="${esc(o.v)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>`;

/* --- Fiche franchisé ----------------------------------------------------------------- */
export function tplFF(c, x){
  const { esc } = x, f = c.ff;
  const entete = `<div class="mko-hd" style="margin-bottom:14px"><button type="button" class="ff-menu" ${x.A(f.menu)} aria-label="Menu" title="Menu">☰</button><div class="cx-vues">${f.onglets.map(o => `<button ${x.A(o.choisir)} class="${o.on ? 'on' : ''}">${esc(o.nom)}</button>`).join('')}</div>
    <span class="mko-mu" style="margin-left:auto">Franchisé</span>${sel(x, esc, f.setShop, f.magasins)}<button ${x.A(f.rafraichir)} class="mko-pied-btn">Relire</button>${f.pageFranchise ? `<a class="mko-pied-btn" href="${esc(f.pageFranchise)}" target="_blank" rel="noopener">La page du franchisé ↗</a>` : ''}</div>`;
  // Trois niveaux : la tuile (le chiffre), le dépliant (son détail), la modale (« Tout voir »).
  const tuile = t => `<div class="ff-k ${t.cls || ''}${t.on ? ' on' : ''}"><button class="ff-kh" ${x.A(t.toggle)}><small>${esc(t.lab)}</small><b>${esc(t.v)}</b><span>${esc(t.sous)}</span><i>${t.on ? '▴' : '▾'}</i></button>${t.on ? `<div class="ff-kd">${t.html}<div class="ff-kl">${t.modal ? `<button ${x.A(t.modal)} class="gc-lien">Tout voir ›</button>` : ''}${t.lien ? `<button ${x.A(t.lien)} class="gc-lien">${esc(t.lienTxt)} ›</button>` : (t.href ? `<a class="gc-lien" href="${esc(t.href)}" target="_blank" rel="noopener">${esc(t.lienTxt)} ›</a>` : '')}</div></div>` : ''}</div>`;
  const volet = (cls, titre, tuiles) => `<div class="mko-carte ff-volet ${cls}"><div class="mko-corps"><h2>${esc(titre)}</h2><div class="ff-grille">${tuiles.map(tuile).join('')}</div></div></div>`;
  const modal = f.modal ? `<div class="ff-modal" ${x.A(f.fermerModal)}><div class="ff-modal-c"><button class="ff-x" ${x.A(f.fermerModal)} title="Fermer">✕</button><h3>${esc(f.modal.titre)}</h3>${f.modal.html}</div></div>` : '';
  const page = corps => `<div data-screen="fiche-franchise">${entete}${corps}${modal}</div>`;
  const titre = `<div class="gc-titre"><h1>${esc(f.shopNom)}</h1><span class="ss">${f.sous}</span></div>`;
  const attente = txt => `<div class="mko-carte" style="padding:20px 22px;color:var(--color-text-muted);font-size:13px">${esc(txt)}</div>`;
  const carte = (h, corps, style) => `<div class="mko-carte"${style ? ` style="${style}"` : ''}><div class="mko-corps"><h2 class="ff-h2">${esc(h)}</h2>${corps}</div></div>`;
  // B2 : le réseau — le classement du trimestre et le benchmark dans une seule table, puis les
  // règles du scoring et l'envoi du rapport A4.
  const st = x.sqSt;
  // Documents (22/10/2026) : déposer en tête, puis les documents du magasin par sorte.
  if (f.onglet === 'docs') {
    const D = f.docs || {};
    const tete = `<div class="gc-titre"><h1>${esc(f.shopNom)}</h1><span class="ss">Documents · rapports de réunion, contrats de mise à disposition et autres pièces du magasin</span></div>`;
    if (D.erreur) { return page(tete + attente(D.erreur)); }
    if (D.chargement || !D.sortes) { return page(tete + attente('Lecture des documents…')); }
    const fm = D.form;
    const champ = (lab, html, cls) => `<label class="ffd-c${cls ? ' ' + cls : ''}"><span class="mko-lab">${esc(lab)}</span>${html}</label>`;
    const depot = `<div class="mko-carte" style="margin-bottom:12px"><div class="mko-corps"><h2 class="ff-h2">Déposer un document</h2>
      <div class="ffd-form">
        ${champ('Sorte', `<select ${x.C(D.setSorte)} class="mko-sel">${D.sortes.map(o => `<option value="${esc(o.v)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>`)}
        ${champ('Titre', `<input class="mko-inp" value="${esc(fm.titre)}" placeholder="ex. Réunion du 21/10 — plan d’action" ${x.C(D.setTitre)}>`)}
        ${champ('Date du document', `<input class="mko-inp" type="date" value="${esc(fm.date)}" ${x.C(D.setDate)}>`)}
        ${champ('Note', `<input class="mko-inp" value="${esc(fm.note)}" placeholder="facultatif" ${x.C(D.setNote)}>`)}
      </div>
      <div class="ffd-bas"><label class="sq-drop ffd-drop"><b>${D.choisis.length ? esc(D.choisis.length > 1 ? D.choisis.length + ' fichiers choisis' : D.choisis[0]) : 'Choisir le ou les fichiers'}</b>${D.choisis.length > 1 ? esc(D.choisis.join(' · ')) : 'PDF, Word, Excel, photos… · ' + esc(D.max) + ' au plus par fichier'}<input type="file" multiple accept="${esc(D.accept)}" ${x.C(D.setFichiers)} style="display:none"></label>
        <button ${x.A(D.deposer)} class="cx-btn prim ffd-ok" ${D.peutDeposer ? '' : 'disabled'}>${D.envoi ? 'Envoi…' : 'Déposer'}</button></div>
      ${D.msg ? `<div class="ffd-msg ${D.msg.ko ? 'ko' : 'ok'}">${esc(D.msg.txt)}</div>` : ''}
    </div></div>`;
    // Le mail « matériel mis à disposition » : relu dans l'aperçu, envoyé au franchisé, la copie rangée ici.
    const M = D.mat;
    const msgM = M.msg ? `<div class="ffd-msg ${M.msg.ko ? 'ko' : 'ok'}">${esc(M.msg.txt)}</div>` : '';
    const mail = `<div class="mko-carte" style="margin-bottom:12px"><div class="mko-corps"><h2 class="ff-h2">Courriers au franchisé</h2>
      ${!M.ouvert ? `<div class="ffd-bas" style="align-items:center"><button ${x.A(M.ouvrir)} class="cx-btn prim">Mail : matériel mis à disposition</button><span class="mko-mu" style="flex:1;min-width:220px">Matériel neuf ; l’entretien et les pièces qui cassent à la charge du franchisé. Vous relisez avant l’envoi ; une copie est rangée dans « Courrier envoyé ».</span></div>${msgM}`
      : `<div class="ffd-mail"><div class="ffd-mail-f">
          ${champ('Matériel concerné (facultatif)', `<textarea class="mko-inp ffd-ta" placeholder="ex. Four ventilé 10 niveaux · Vitrine réfrigérée 1,50 m" ${x.C(M.setMateriel)}>${esc(M.materiel)}</textarea>`)}
          ${champ('Contrat joint', `<select class="mko-sel" ${x.C(M.setContrat)}>${M.contrats.map(o => `<option value="${esc(o.v)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>`)}
          ${champ('Destinataire', `<select class="mko-sel" ${x.C(M.setA)}>${M.adresses.map(o => `<option value="${esc(o.v)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>`)}
          ${M.autreVisible ? champ('Adresse du franchisé', `<input class="mko-inp" type="email" value="${esc(M.autre)}" placeholder="franchise@exemple.be" ${x.C(M.setAutre)}>`) : ''}
          <div class="ffd-bas"><button ${x.A(M.envoyer)} class="cx-btn prim ffd-ok" ${M.pret ? '' : 'disabled'}>${M.envoi ? 'Envoi…' : 'Envoyer au franchisé'}</button><button ${x.A(M.fermer)} class="mko-pied-btn">Fermer</button></div>
          ${M.smtp ? '' : '<div class="ffd-msg ko">SMTP non configuré (Paramètres) : rien ne peut partir.</div>'}${msgM}
          ${M.sujet ? `<div class="mko-mu">Objet : ${esc(M.sujet)}</div>` : ''}
        </div>
        <div class="ffd-mail-v">${M.apErreur ? `<div class="ffd-msg ko">${esc(M.apErreur)}</div>` : (!M.html ? `<div class="mko-mu" style="padding:20px">Préparation de l’aperçu…</div>` : `<iframe class="ffd-ap${M.apChargement ? ' maj' : ''}" sandbox="" srcdoc="${esc(M.html)}" title="Aperçu du mail"></iframe>`)}</div></div>`}
    </div></div>`;
    const liste = D.groupes.length ? D.groupes.map(g => `<div class="mko-carte" style="margin-bottom:12px"><div class="mko-ct"><span class="mko-lab">${esc(g.nom)}</span><span class="mko-mini">${g.docs.length}</span></div>
      <div style="overflow-x:auto"><table class="mko-tab ffd-tab"><thead><tr><th>Titre</th><th>Fichier</th><th>Date</th><th></th></tr></thead><tbody>
      ${g.docs.map(d => `<tr><td><b>${esc(d.titre)}</b>${d.note ? `<small>${esc(d.note)}</small>` : ''}</td><td>${esc(d.fichier)}<small>${esc(d.depose)}</small></td><td>${esc(d.date)}</td>
        <td class="ffd-act"><a class="mko-pied-btn" href="${esc(d.ouvrir)}" target="_blank" rel="noopener">Ouvrir ↗</a><a class="mko-pied-btn" href="${esc(d.telecharger)}">Télécharger</a><button ${x.A(d.retirer)} class="mko-pied-btn">Retirer</button></td></tr>`).join('')}
      </tbody></table></div></div>`).join('')
      : attente('Aucun document pour ' + f.shopNom + ' : déposez le premier rapport de réunion ou contrat ci-dessus.');
    return page(tete + depot + mail + liste);
  }
  if (f.onglet === 'reseau') {
    if (c.sqIndispo && !f.reseau) { return page(attente(c.sqIndispo)); }
    if (!f.reseau) { return page(attente(f.tableauChargement ? 'Lecture du réseau…' : 'Le benchmark du réseau ne se lit pas.')); }
    const outils = `<div class="mko-carte" style="margin-bottom:12px"><div class="mko-corps" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
      <span class="mko-mu">Trimestre</span><select ${x.C(c.setSqTrimestre)} class="mko-sel">${(c.sqTrimestres || []).map(t => `<option value="${esc(t.cle)}"${t.on ? ' selected' : ''}>${esc(t.lib)}</option>`).join('')}</select>
      <span style="flex:1"></span>
      ${c.sqTri ? `<a class="mko-pied-btn" href="${esc(c.sqRapportHref)}" target="_blank" rel="noopener">Rapport A4</a><a class="mko-pied-btn" href="${esc(c.sqPdfHref)}" target="_blank" rel="noopener">PDF</a><button ${x.A(c.sqEnvoyer)} class="cx-btn prim" ${c.sqBusy || !c.sqEnvoi || !c.sqEnvoi.smtp ? 'disabled' : ''}>${c.sqBusy ? 'Envoi…' : 'Envoyer aux magasins'}</button>` : ''}
    </div></div>`;
    const sqTete = c.sqTri ? 'Scoring ' + c.sqTri.lib + (c.sqTri.enCours ? ' · en cours, au ' + c.sqTri.arrete : '') : 'Scoring du trimestre';
    const postesTete = (c.sqPostes || []).map(p => `<th>${esc(p.nom)}</th>`).join('');
    const nP = (c.sqPostes || []).length || 4;
    const table = `<div class="mko-carte" style="margin-bottom:12px"><div class="mko-ct"><span class="mko-lab">Le réseau — le classement du trimestre et le benchmark, côte à côte</span><span class="mko-mini">${esc(f.tableau.mini)}</span></div>
      <div style="overflow-x:auto"><table class="mko-tab"><thead>
        <tr><th colspan="3"></th><th colspan="${nP}" class="ff-grp">${esc(sqTete)} · /5</th><th colspan="6" class="ff-grp j">Suivi journalier et opérations</th><th colspan="1" class="ff-grp t">Comptoir</th></tr>
        <tr><th></th><th>Franchisé</th><th>Note</th>${postesTete}<th>Tâches 30 j</th><th>Points moyens</th><th>Invendus 7 j</th><th>Réclamations 30 j</th><th>CA / budget mois</th><th>B2B mois</th><th>Planogramme</th></tr></thead><tbody>
        ${f.reseau.map(r => { const q = r.sq; return `<tr class="${r.on ? 'on' : ''}" ${x.A(r.choisir)} style="cursor:pointer">
          <td>${q ? `<span class="sq-rang${q.top ? ' or' : ''}">${q.rang}</span>` : ''}</td>
          <td class="nom"><span class="gc-feu ${r.feu}"></span>${esc(r.court)}<small>${esc(r.sous)}</small></td>
          <td>${q ? `<b>${esc(q.total)}</b><small> / ${q.sur}</small><div>${st(q.pct, q.na, 'sm')}</div><small class="${q.deltaCls}">${esc(q.delta)}</small>` : '—'}</td>
          ${q ? q.postes.map(p => `<td><b>${esc(p.v)}</b><div>${st(p.pct, p.na, 'sm')}</div><small>${esc(p.d)}</small>${p.alerte ? `<small class="ko">${esc(p.alerte)}</small>` : ''}</td>`).join('') : `<td colspan="${nP}" class="mko-mu">pas de scoring calculé</td>`}
          <td><b>${esc(r.taches)}</b><small>${esc(r.tachesSous)}</small></td>
          <td><b>${esc(r.revues)}</b><small>${esc(r.revuesSous || '')}</small></td>
          <td><b class="${r.invendusCls || ''}">${esc(r.invendus)}</b></td>
          <td><b>${esc(r.reclamations)}</b><small>${esc(r.reclamationsSous || '')}</small></td>
          <td><b>${esc(r.mois)}</b><small>${esc(r.moisSous || '')}</small></td>
          <td><b class="${r.b2bCls || ''}">${esc(r.b2b)}</b><small>${esc(r.b2bSous || '')}</small></td>
          <td><b>${esc(r.plano)}</b></td></tr>`; }).join('')}
      </tbody></table></div>
      <div class="mko-corps mko-mu" style="padding-top:8px">Un clic sur une ligne ouvre la fiche du magasin. Le scoring : Note Google et Tâches viennent du volet journalier, Client mystère du terrain, Budget du pilotage${c.sqReseau ? ` · réseau ${esc(c.sqReseau.sur20)} / 20 en moyenne` : ''}.</div></div>`;
    return page(outils + table + (c.sqTri ? x.sqCartes(c, x) : ''));
  }
  if (f.indispo) { return page(titre + `<div class="mko-carte" style="padding:20px 22px;color:#8D1D2C;font-size:13px">${esc(f.indispo)}</div>`); }
  if (f.chargement) { return page(titre + `<div class="mko-carte" style="padding:20px 22px;color:var(--color-text-muted);font-size:13px">Lecture de la fiche de ${esc(f.shopNom)} : tâches et leurs notes, invendus, réclamations, objectifs, visites, plans, scoring…</div>`); }
  const journal = `<div class="mko-carte" style="margin-top:12px"><div class="mko-ct"><span class="mko-lab">Journal — constaté, noté ou décidé</span><span class="mko-mini">60 jours</span></div><div class="mko-corps" style="padding-top:4px">${f.journal.length ? f.journal.map(e => `<div class="ff-jl"><span class="d">${esc(e.le)}</span><span class="gc-src ${e.volet === 'terrain' ? 't' : 'j'}">${esc(e.volet)}</span><span>${esc(e.texte)}</span></div>`).join('') : '<div class="mko-mu">Rien sur la période.</div>'}</div></div>`;
  // La fiche s'ouvre désormais sur ce magasin (B2) : avant sa première lecture, on attend.
  if (!f.sante) { return page(titre + attente('Lecture de la fiche de ' + f.shopNom + '…')); }
  // Le scoring du trimestre en bandeau (B2) : la note, le rang, les quatre postes.
  const S2 = f.score;
  const bandeau = `<div class="mko-carte" style="margin-bottom:12px"><div class="mko-corps" style="display:flex;flex-wrap:wrap;gap:18px;align-items:stretch">
    <div style="flex:1 1 220px;display:flex;flex-direction:column;gap:6px;justify-content:center">
      <span class="mko-lab">Scoring · ${esc(S2 ? S2.tri : 'du trimestre')}${S2 && S2.enCours ? ' · en cours' : ''}</span>
      ${S2 ? `<div style="display:flex;align-items:baseline;gap:8px"><span style="font-family:var(--font-display);font-size:46px;line-height:1">${esc(S2.total)}</span><span class="mko-mu" style="font-size:15px">/ ${S2.sur}</span></div>
      <div>${st(S2.pct, S2.na, 'gd')}</div>
      <div class="mko-mu" style="font-size:12px">${S2.rang}<sup>e</sup> sur ${S2.magasins} · <span class="${S2.deltaCls}">${esc(S2.delta)}</span>${S2.manque ? ` · ${S2.manque} poste(s) sans donnée, compté(s) 0` : ''}${S2.rapport ? ` · <a href="${esc(S2.rapport)}" target="_blank" rel="noopener">rapport A4</a>` : ''}</div>` : '<div class="mko-mu">Pas de scoring calculé pour ce trimestre.</div>'}
    </div>
    ${S2 ? `<div style="flex:3 1 560px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">${S2.postes.map(p => `<div style="background:var(--color-background-secondary);border-radius:10px;padding:12px 14px;display:flex;flex-direction:column;gap:4px">
      <span class="mko-lab">${esc(p.nom)}</span><div>${st(p.pct, p.na, 'sm')}</div><b style="font-size:16px">${esc(p.v)}${p.na ? '' : '<small class="mko-mu"> / 5</small>'}</b>
      <span class="mko-mu" style="font-size:11.5px">${esc(p.d)}</span>${p.alerte ? `<span class="ko" style="font-size:11.5px;font-weight:500">${esc(p.alerte)}</span>` : ''}${p.encoder ? `<button ${x.A(p.encoder)} class="mko-pied-btn" style="align-self:flex-start;margin-top:2px">${p.na ? 'encoder' : 'modifier'}</button>` : ''}</div>`).join('')}</div>` : ''}
  </div></div>`;
  return page(titre + bandeau + carte('Santé du magasin', `<div class="ff-grille ff-g4">${f.sante.map(tuile).join('')}</div>`)
    + `<div class="ff-deux" style="margin-top:12px">${volet('j', 'Suivi journalier et opérations', f.journalier)}${volet('t', 'Suivi de terrain', f.terrain)}</div>${journal}`);
}


/* --- Contrôles qualité du mois (12/10/2026) ------------------------------------------------ */
const cqmB = (esc, b, gros) => `<span class="cqm-b${gros ? ' g' : ''}" style="background:${b.bg};color:${b.fg}" title="${esc(b.long)}">${esc(b.txt)}</span>`;
export function tplCQM(c, x){
  const { esc } = x, r = c.cqm;
  const entete = `<div class="mko-hd" style="margin-bottom:14px;flex-wrap:wrap">
      <span class="mko-mu">Mois</span><button ${x.A(r.moisPrec)} class="mko-pied-btn">‹</button><b style="font-size:12.5px;min-width:120px;text-align:center">${esc(r.moisLib)}</b>${r.moisSuiv ? `<button ${x.A(r.moisSuiv)} class="mko-pied-btn">›</button>` : '<span style="width:28px"></span>'}
      ${r.magasins.length ? `<span class="mko-mu" style="margin-left:10px">Magasin</span>${sel(x, esc, r.setShop, r.magasins)}<span class="mko-mu">Checklist</span>${sel(x, esc, r.setCl, r.checklists, 'max-width:260px')}` : ''}
      <span style="flex:1"></span>${r.lecture ? `<span class="mko-mu">${esc(r.lecture)}</span>` : ''}<button ${x.A(r.rafraichir)} class="mko-pied-btn">Relire</button></div>`;
  const modal = r.modal ? tplCQMModal(r.modal, x) : '';
  if (r.indispo) { return `<div data-screen="controles-qualite">${entete}<div class="mko-carte" style="padding:20px 22px;color:#8D1D2C;font-size:13px">${esc(r.indispo)}</div>${modal}</div>`; }
  if (r.chargement) { return `<div data-screen="controles-qualite">${entete}<div class="mko-carte" style="padding:20px 22px;color:var(--color-text-muted);font-size:13px">Lecture des contrôles du mois au panel, magasin par magasin et jour par jour…</div>${modal}</div>`; }
  const puces = `<div class="cx-puces" style="margin:0 0 12px">${r.puces.map(p => `<button ${x.A(p.choisir)} class="cx-pu${p.on ? ' on' : ''}">${p.badge ? `<i class="cqm-pt" style="background:${p.badge.bg};border-color:${p.badge.fg}"></i>` : ''}${esc(p.nom)} · ${p.n}</button>`).join('')}</div>`;
  const shops = `<div class="mko-carte" style="margin-bottom:12px"><div class="mko-ct"><span class="mko-lab">Par magasin</span></div><div style="overflow-x:auto"><table class="mko-tab cqm-tab" style="min-width:760px"><thead><tr><th>Magasin</th><th class="n">Contrôles</th><th class="n">Rendus</th>${(r.parShop[0] ? r.parShop[0].niveaux : []).map(n => `<th class="n">${cqmB(esc, n.b)}</th>`).join('')}<th class="n">À noter</th><th class="n">Non rendues</th></tr></thead><tbody>
    ${r.parShop.map(s => `<tr><td class="nom">${esc(s.nom)}</td><td class="n">${esc(s.total)}</td><td class="n">${esc(s.rendus)}</td>${s.niveaux.map(n => `<td class="n"${n.n && n.b.n <= 3 ? ' style="color:#C0182B;font-weight:700"' : ''}>${n.n || '·'}</td>`).join('')}<td class="n">${s.aNoter || '·'}</td><td class="n">${s.nonRendues || '·'}</td></tr>`).join('')}</tbody></table></div></div>`;
  const lignes = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">${esc(r.titre || '')}</span></div>
    ${r.lignes.length ? `<div style="overflow-x:auto"><table class="mko-tab cqm-tab"><thead><tr><th>Date</th><th>Magasin</th><th>Checklist</th><th>Contrôle</th><th class="n">Prévu</th><th class="n">Fait</th><th>Note</th><th>Motif</th><th></th></tr></thead><tbody>
    ${r.lignes.map(l => `<tr ${x.A(l.ouvrir)} class="cqm-l"><td style="white-space:nowrap">${esc(l.date)}</td><td class="nom">${esc(l.shop)}</td><td class="mu">${esc(l.checklist)}</td><td>${esc(l.tache)}</td><td class="n">${esc(l.heure)}</td><td class="n">${esc(l.fait)}</td><td>${cqmB(esc, l.badge)}</td><td class="mu" style="max-width:260px">${esc(l.commentaire)}</td><td class="n">${l.photo ? '<span class="cqm-voir">📷 Voir</span>' : ''}</td></tr>`).join('')}
    </tbody></table></div>${r.plus ? `<div style="padding:10px 14px"><button ${x.A(r.plus)} class="mko-pied-btn">Voir les ${r.reste} suivants</button></div>` : ''}` : '<div class="mko-corps mko-mu">Aucun contrôle pour ce choix.</div>'}</div>`;
  return `<div data-screen="controles-qualite">${entete}${r.erreur ? `<div class="gc-msg ko">${esc(r.erreur)}</div>` : ''}<div class="gc-kpis">${r.kpis.map(k => kpi(esc, k)).join('')}</div>${puces}${shops}${lignes}${modal}</div>`;
}
function tplCQMModal(m, x){
  const { esc } = x;
  const photo = m.chargement ? '<div class="cqm-ph vide">lecture de la photo…</div>' : m.indispo ? `<div class="cqm-ph vide" style="color:#8D1D2C">${esc(m.indispo)}</div>`
    : m.photo ? `<a href="${esc(m.photo)}" target="_blank" rel="noopener" class="cqm-ph"><img src="${esc(m.photo)}" alt=""></a>` : '<div class="cqm-ph vide">Pas de photo rendue pour ce contrôle.</div>';
  // La photo type du rayon À CÔTÉ de la photo rendue : le comptoir attendu en face du comptoir montré.
  const ry = m.rayon;
  const photos = ry && ry.photo ? `<div class="cqm-duo"><figure>${photo}<figcaption>Photo rendue</figcaption></figure><figure><a href="${esc(ry.photo)}" target="_blank" rel="noopener" class="cqm-ph type"><img src="${esc(ry.photo)}" alt="Photo type ${esc(ry.nom)}"></a><figcaption>Photo type · ${esc(ry.nom)}</figcaption></figure></div>` : photo;
  return `<div ${x.A(m.fermer)} class="cqm-voile"></div><div class="cqm-mod" role="dialog" aria-modal="true">
    <div class="cqm-mh"><div style="min-width:0"><b>${esc(m.titre)}</b><small>${esc(m.sous)}${m.faitLe ? ' · ' + esc(m.faitLe) : ''}</small></div><span style="flex:1"></span>${cqmB(esc, m.badge, true)}<button ${x.A(m.fermer)} class="cqm-x" aria-label="Fermer">✕</button></div>
    <div class="cqm-mc">${photos}
      <div class="cqm-side">${ry && ry.consigne ? `<div class="cqm-com"><span>Attendu · ${esc(ry.nom)}</span>${esc(ry.consigne)}</div>` : ''}${m.commentaire ? `<div class="cqm-com"><span>Motif noté</span>${esc(m.commentaire)}</div>` : ''}
        ${m.peutNoter ? `<div class="mko-mu" style="margin:2px 0 8px">Noter ce contrôle</div><div class="cqm-choix">${m.choix.map(o => `<button ${x.A(o.choisir)} class="${o.on ? 'on' : ''}" style="--c:${o.b.bg}"><i style="background:${o.b.bg}"></i>${esc(o.b.long)}</button>`).join('')}</div>
        <textarea ${x.I(m.setComment)} class="mko-inp" rows="3" placeholder="${m.motifRequis ? 'Motif obligatoire sous « Conforme »' : 'Motif (facultatif)'}" style="width:100%;margin-top:10px;resize:vertical">${esc(m.comment)}</textarea>
        <button ${x.A(m.enregistrer)} class="cx-btn prim" style="width:100%;margin-top:10px" ${m.envoi ? 'disabled' : ''}>${m.envoi ? 'Envoi au panel…' : 'Enregistrer la note'}</button>` : ''}
        ${m.msg ? `<div class="gc-msg ${m.msg.ko ? 'ko' : ''}" style="margin-top:10px">${esc(m.msg.txt)}</div>` : ''}</div></div></div>`;
}
