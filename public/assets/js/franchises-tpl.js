/* Cockpit CEO — gabarits de la section « Franchisés · évaluation et suivi » (09/10/2026) :
 * Gestion consultant (planning, tâches et contrôles, réseau, cadre de visite), Fiche franchisé,
 * Remarques aux opérateurs. Mêmes conventions que templates.js : x = { A, C, I, esc }. */

const card = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
const kpi = (esc, k) => `<div class="gc-kpi"><div class="k">${esc(k[0])}</div><div class="v ${k[3] || ''}">${esc(k[1])}</div><div class="s">${esc(k[2] || '')}</div></div>`;
const sel = (x, esc, fn, opts, style) => `<select ${x.C(fn)} class="mko-sel" style="${style || ''}">${opts.map(o => `<option value="${esc(o.v)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>`;

/* --- Gestion consultant ------------------------------------------------------------ */
export function tplGC(c, x){
  const { esc } = x, g = c.gc;
  const entete = `<div class="mko-hd" style="margin-bottom:14px">
      <div class="cx-vues">${g.onglets.map(o => `<button ${x.A(o.choisir)} class="${o.on ? 'on' : ''}">${esc(o.nom)}${o.badge ? ` <span class="gc-badge">${o.badge}</span>` : ''}</button>`).join('')}</div>
      <span class="mko-mu" style="margin-left:auto">Consultant</span>${sel(x, esc, g.setCons, g.consultants)}
      <span class="mko-mu">Mois</span><button ${x.A(g.moisPrec)} class="mko-pied-btn">‹</button><b style="font-size:12.5px;min-width:110px;text-align:center">${esc(g.moisLib)}</b><button ${x.A(g.moisSuiv)} class="mko-pied-btn">›</button>
      <button ${x.A(g.ouvrirPlanif)} class="cx-btn prim">Planifier une visite</button><button ${x.A(g.rafraichir)} class="mko-pied-btn">Relire</button>
    </div>`;
  if (g.indispo) { return `<div data-screen="gestion-consultant">${entete}<div class="mko-carte" style="padding:20px 22px;font-size:13px;color:#8D1D2C">${esc(g.indispo)}</div></div>`; }
  if (g.chargement) { return `<div data-screen="gestion-consultant">${entete}<div class="mko-carte" style="padding:20px 22px;font-size:13px;color:var(--color-text-muted)">Lecture des visites, des tâches et du cadre…</div></div>`; }
  const msg = g.msg ? `<div class="gc-msg ${g.msg.ko ? 'ko' : ''}">${esc(g.msg.txt)}</div>` : '';
  const planif = g.planif ? tplGCPlanif(g.planif, x) : '';
  let corps = '';
  if (g.onglet === 'planning') { corps = tplGCPlanning(g, x); }
  else if (g.onglet === 'taches') { corps = tplGCTaches(g, x); }
  else if (g.onglet === 'reseau') { corps = tplGCReseau(g, x); }
  else { corps = tplGCCadre(g, x); }
  return `<div data-screen="gestion-consultant">${entete}${msg}${planif}${corps}</div>`;
}

function tplGCPlanif(p, x){
  const { esc } = x;
  return `<div class="mko-carte gc-planif"><div class="mko-ct"><span class="mko-lab">Planifier une visite — choisir le type charge sa checklist, ses tâches, sa durée</span><button ${x.A(p.fermer)} class="mko-pied-btn" style="margin-left:auto">Fermer</button></div>
    <div class="mko-corps gc-form">
      <div class="f"><label>Magasin</label>${sel(x, esc, p.setShop, p.magasins)}</div>
      <div class="f"><label>Type de visite</label>${sel(x, esc, p.setType, p.types)}<small>${esc(p.typeInfo)}</small></div>
      <div class="f"><label>Consultant</label>${sel(x, esc, p.setCons, p.consultants)}<small>${esc(p.consInfo)}</small></div>
      <div class="f"><label>Motif</label>${sel(x, esc, p.setMotif, p.motifs)}</div>
      <div class="f"><label>Date</label><input type="date" class="mko-inp" value="${esc(p.date)}" ${x.C(p.setDate)}></div>
      <div class="f"><label>Heure</label><input type="time" class="mko-inp" value="${esc(p.heure)}" ${x.C(p.setHeure)}></div>
      <div class="f"><label>Durée (min)</label><input type="number" class="mko-inp" value="${esc(p.duree)}" ${x.C(p.setDuree)}><small>du type, modifiable</small></div>
      <div class="f" style="align-self:end"><button ${x.A(p.envoyer)} class="cx-btn prim" ${p.busy ? 'disabled' : ''}>${p.busy ? 'Planification…' : 'Planifier la visite'}</button></div>
    </div>
    <div class="mko-corps mko-mu" style="padding-top:0">${esc(p.apercu)}</div></div>`;
}

function tplGCPlanning(g, x){
  const { esc } = x;
  const chip = ch => `<span class="gc-chip ${ch.cls}" title="${esc(ch.titre || ch.txt)}">${esc(ch.txt)}</span>`;
  const grille = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">${esc(g.moisLib)} — ${esc(g.grilleSous)}</span><span class="mko-mini">${esc(g.grilleMini)}</span></div>
    <div class="mko-corps"><div class="gc-mois">${g.jours.map(j => `<div class="jh">${j}</div>`).join('')}${g.cases.map(c => `<div class="j${c.hors ? ' hors' : ''}${c.auj ? ' auj' : ''}"><div class="n">${esc(c.num)}</div>${c.chips.map(chip).join('')}</div>`).join('')}</div>
    <div class="gc-leg"><span><i style="background:#E3F0E5"></i>visite faite</span><span><i style="background:#EFE3DE"></i>planifiée</span><span><i style="background:#F2C9A0"></i>confirmée, en cours</span><span><i style="background:#EDE9F7"></i>tâche à échéance</span><span><i style="background:#F7DADA"></i>plan d’action à échéance</span><span><i style="background:#E8E3DD"></i>un autre consultant</span></div></div></div>`;
  const semaine = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">${esc(g.semaineTitre)}</span><span class="mko-mini">${esc(g.semaineMini)}</span></div><div class="mko-corps" style="padding-top:4px">
    ${g.semaine.length ? g.semaine.map(v => `<div class="gc-vis"><div class="h"><b>${esc(v.h)}</b>${esc(v.sous)}</div><div class="c"><b>${esc(v.titre)}</b><div class="mu">${v.mu}${v.google ? ` · <a class="gc-lien" href="${esc(v.google)}" target="_blank" rel="noopener">Ajouter à Google Agenda ›</a>` : ''}</div>${v.prog != null ? `<div class="gc-prog"><i class="${v.progCls || ''}" style="width:${v.prog}%"></i></div>` : ''}</div><span class="gc-st ${v.statutCls}">${esc(v.statut)}</span></div>`).join('') : '<div class="mko-mu">Rien de prévu sur ces sept jours.</div>'}</div></div>`;
  const agenda = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Agenda Google — votre agenda suit le cockpit</span></div><div class="mko-corps gc-agenda">
    ${g.agenda.ics ? `<div class="l"><b>Abonnement — toutes vos visites</b><small>Dans Google Agenda : « Autres agendas › À partir de l’URL », collez cette adresse. Google la relit toutes les 12 à 24 h.</small><div class="cx-ligne" style="margin-top:6px"><input class="mko-inp" readonly value="${esc(g.agenda.ics)}" style="font-size:11px"><a class="mko-pied-btn" href="${esc(g.agenda.webcal)}">Ouvrir (webcal)</a></div></div>` : '<div class="l"><b>Abonnement</b><small>Choisissez un consultant pour obtenir son flux.</small></div>'}
    <div class="l"><b>Invitation à chaque visite planifiée</b><small>Un courriel avec pièce jointe .ics part à la planification, à chaque déplacement, à l’annulation. ${g.agenda.invitations ? (g.agenda.smtp ? '<span class="gc-pill ok">actif</span>' : '<span class="gc-pill att">SMTP non configuré</span>') : '<span class="gc-pill">désactivé (réglages des visites)</span>'}${g.agenda.notePanel ? ' · note VISIT déposée au panel à la clôture' : ''}</small></div>
    <div class="l"><b>Un clic, une visite</b><small>« Ajouter à Google Agenda » sur chaque visite de la liste : l’événement s’ouvre prérempli.</small></div></div></div>`;
  const planifier = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">À planifier — ce que le cadre attend encore ce mois</span></div><div class="mko-corps" style="padding-top:4px">
    ${g.aPlanifier.length ? g.aPlanifier.map(l => `<div class="gc-vis"><div class="h"><b>${esc(l.magasin)}</b>${esc(l.sous)}</div><div class="c"><b>${esc(l.titre)}</b><div class="mu">${esc(l.mu)}</div></div>${l.planifier ? `<button ${x.A(l.planifier)} class="mko-pied-btn">Planifier</button>` : `<span class="gc-st ${l.cls}">${esc(l.statut)}</span>`}</div>`).join('') : '<div class="mko-mu">Le cadre du mois est tenu.</div>'}</div></div>`;
  return `<div class="gc-kpis">${g.kpis.map(k => kpi(esc, k)).join('')}</div>${grille}<div class="gc-trois">${semaine}${agenda}${planifier}</div>`;
}

function tplGCTaches(g, x){
  const { esc } = x;
  const t = g.taches;
  const tache = tk => `<div class="gc-tache${tk.late ? ' late' : ''}${tk.on ? ' on' : ''}"><div class="t">${esc(tk.titre)}</div><div class="m"><span class="gc-src ${tk.srcCls}">${esc(tk.src)}</span>${tk.magasin ? `<span class="gc-pill">${esc(tk.magasin)}</span>` : ''}${tk.ech ? `<span class="${tk.late ? 'ko' : ''}" style="font-weight:600">${esc(tk.ech)}</span>` : ''}${tk.extra ? `<span class="mko-mu">${esc(tk.extra)}</span>` : ''}</div>
      <div class="a">${tk.fait ? `<button ${x.A(tk.fait)} class="mko-pied-btn">Fait</button>` : ''}${tk.encours ? `<button ${x.A(tk.encours)} class="mko-pied-btn">En cours</button>` : ''}${tk.reprendre ? `<button ${x.A(tk.reprendre)} class="mko-pied-btn">Reprendre</button>` : ''}${tk.voir ? `<button ${x.A(tk.voir)} class="mko-pied-btn">Checklist</button>` : ''}</div></div>`;
  const filtres = `<div class="cx-puces" style="margin-bottom:12px">${t.filtres.map(f => `<button ${x.A(f.choisir)} class="cx-pu${f.on ? ' on' : ''}">${esc(f.nom)}</button>`).join('')}
    <span style="margin-left:auto" class="cx-ligne"><input class="mko-inp" style="width:260px" placeholder="Nouvelle tâche…" value="${esc(t.nouvelle.titre)}" ${x.I(t.nouvelle.setTitre)}>${sel(x, esc, t.nouvelle.setShop, t.nouvelle.magasins)}<input type="date" class="mko-inp" style="width:140px" value="${esc(t.nouvelle.echeance)}" ${x.C(t.nouvelle.setEcheance)}><button ${x.A(t.nouvelle.creer)} class="cx-btn">Ajouter</button></span></div>`;
  const kanban = `<div class="gc-kanban">${t.colonnes.map(col => `<div class="col"><div class="ch"><b>${esc(col.nom)}</b><span>${esc(col.sous)}</span></div>${col.taches.length ? col.taches.map(tache).join('') : '<div class="mko-mu" style="padding:6px">—</div>'}</div>`).join('')}</div>`;
  const cl = t.checklist;
  const coche = p => `<div class="gc-coche"><div class="cb ${p.etat}">${p.etat === 'on' ? '✓' : p.etat === 'ko' ? '✕' : ''}</div><div>${esc(p.txt)}${p.sous ? `<small>${esc(p.sous)}</small>` : ''}</div></div>`;
  const liste = cl ? `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Liste de contrôle — ${esc(cl.titre)}</span><span class="mko-mini">${esc(cl.sous)}</span></div><div class="mko-corps">
      <div class="gc-typesel"><label>Type de visite</label><span class="sel">${esc(cl.type)}</span><span class="mko-mu">${esc(cl.typeInfo)}</span></div>
      ${cl.taches.length ? `<div class="gc-mod"><b>Tâches à faire</b><span>du type · ${cl.tachesFaites} / ${cl.taches.length}</span></div>${cl.taches.map(coche).join('')}` : ''}
      <div class="gc-mod"><b>Checklist du type</b><span>${esc(cl.pointsSous)}</span></div>
      ${cl.modules.map(m => `<div class="gc-mod"><b style="font-size:11px">${esc(m.nom)}</b><span>${esc(m.sous)}</span></div>${m.points.map(coche).join('')}`).join('')}
      <div class="cx-ligne" style="margin-top:12px">${cl.app ? `<a class="cx-btn prim" href="${esc(cl.app)}" target="_blank" rel="noopener">Ouvrir dans l’app Visites</a>` : ''}${cl.google ? `<a class="gc-lien" href="${esc(cl.google)}" target="_blank" rel="noopener">Ajouter à Google Agenda ›</a>` : ''}</div></div></div>`
    : `<div class="mko-carte"><div class="mko-corps mko-mu">Choisissez une visite (bouton « Checklist » d’une tâche de visite) pour voir sa liste de contrôle et ses tâches.</div></div>`;
  return `<div class="gc-kpis">${g.kpis.map(k => kpi(esc, k)).join('')}</div>${filtres}<div class="cx-deux">${kanban}${liste}</div>`;
}

function tplGCReseau(g, x){
  const { esc } = x; const r = g.reseau;
  const cel = c => c ? `<div class="gc-cel"><b>${c.feu ? `<span class="gc-feu ${c.feu}"></span>` : ''}${esc(c.derniere)}</b><small>${esc(c.prochaine)}</small>${c.resp ? '<small><span class="gc-pill ok">responsable</span></small>' : ''}</div>` : '<span class="mko-mu">—</span>';
  const matrice = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Consultants × magasins — dernière visite faite · prochaine planifiée · responsable au cadre</span><span class="mko-mini">${esc(g.moisLib)}</span></div>
    <div style="overflow-x:auto"><table class="mko-tab"><thead><tr><th>Consultant</th>${r.cols.map(m => `<th>${esc(m.nom)}<small>${esc(m.sous)}</small></th>`).join('')}<th>Ce mois</th></tr></thead><tbody>
    ${r.rows.map(row => `<tr><td class="nom">${esc(row.nom)}<small>${esc(row.sous)}</small></td>${row.cells.map(cel).join('')}<td><div class="gc-cel"><b>${esc(row.visites)}</b><small>${esc(row.taches)}</small><small>${esc(row.agenda)}</small></div></td></tr>`).join('')}
    <tr class="tot"><td>Réseau</td>${r.pied.map(p => `<td><div class="gc-cel"><b>${p.feu ? `<span class="gc-feu ${p.feu}"></span>` : ''}${esc(p.b)}</b><small>${esc(p.s)}</small></div></td>`).join('')}<td><div class="gc-cel"><b>${esc(r.total.b)}</b><small>${esc(r.total.s)}</small></div></td></tr></tbody></table></div></div>`;
  const couverture = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Couverture — le cadre de visite face au réel, ce mois</span></div><div class="mko-corps" style="padding-top:4px">
    ${r.couverture.map(l => `<div class="gc-barre"><span class="n">${esc(l.nom)}</span><div class="b"><i style="width:${l.pct}%;background:${l.coul}"></i></div><span class="v">${esc(l.txt)}</span></div>`).join('')}
    <div class="mko-mu" style="margin-top:8px">Le suivi de plan d’action et la revisite ne comptent pas : un P0 ouvert ou un point non conforme les déclenche.</div></div></div>`;
  const regles = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Règles</span></div><div class="mko-corps" style="padding-top:4px">${r.regles.map(l => `<div class="gc-regle"><b>${esc(l[0])}</b><span>${esc(l[1])}</span></div>`).join('')}</div></div>`;
  return `<div class="gc-kpis">${g.kpis.map(k => kpi(esc, k)).join('')}</div>${matrice}<div class="cx-deux" style="margin-top:12px">${couverture}${regles}</div>`;
}

function tplGCCadre(g, x){
  const { esc } = x; const k = g.cadre;
  if (k.chargement) { return `<div class="mko-carte"><div class="mko-corps mko-mu">Lecture du cadre…</div></div>`; }
  const plan = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Plan de visites par magasin — combien, de quel type, par quel profil, avec quelle checklist, par qui</span><span class="mko-mini">réalisé ce mois · le suivi et la revisite sont hors cadre</span></div>
    <div style="overflow-x:auto"><table class="mko-tab"><thead><tr><th>Magasin</th><th>Type de visite</th><th>Nombre</th><th>Par</th><th>Profil demandé</th><th>Checklist</th><th>Consultant</th><th>Réalisé</th><th></th></tr></thead><tbody>
    ${k.lignes.map(l => `<tr><td>${sel(x, esc, l.setShop, l.magasins)}</td><td>${sel(x, esc, l.setType, l.types)}</td><td><input type="number" min="1" max="31" class="mko-inp" style="width:64px" value="${esc(l.nb)}" ${x.C(l.setNb)}></td><td>${sel(x, esc, l.setPar, l.pars)}</td><td class="mu">${esc(l.profil)}</td><td class="mu">${esc(l.checklist)}</td><td>${sel(x, esc, l.setCons, l.consultants)}</td><td>${l.realise ? `<span class="gc-pill ${l.realiseCls}">${esc(l.realise)}</span>` : '<span class="mko-mu">—</span>'}</td><td><button ${x.A(l.retirer)} class="mko-pied-btn">×</button></td></tr>`).join('')}
    </tbody></table></div>
    <div class="mko-corps cx-ligne"><button ${x.A(k.ajouter)} class="mko-pied-btn">+ Ajouter une ligne</button><button ${x.A(k.sauver)} class="cx-btn prim" ${k.busy ? 'disabled' : ''}>Enregistrer le cadre</button><span class="mko-mu">${esc(k.plansMini)}</span></div></div>`;
  const types = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Types de visite — la liste déroulante</span><span class="mko-mini">chaque type porte sa durée, son profil, sa checklist et ses tâches à faire</span></div>
    <div class="mko-corps" style="padding-top:4px">${k.types.map(t => `<div class="gc-typ${t.on ? ' on' : ''}" ${x.A(t.choisir)}><div><b>${esc(t.nom)}</b><small>${esc(t.sous)}</small></div><span class="n">${esc(t.n)}</span></div>`).join('')}</div></div>`;
  const t = k.typeSel;
  const def = t ? `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">${esc(t.nom)} — définition du type</span><span class="mko-mini">${esc(t.code)}</span></div><div class="mko-corps">
      <div class="gc-form" style="grid-template-columns:repeat(3,minmax(0,1fr))">
        <div class="f"><label>Nom</label><input class="mko-inp" value="${esc(t.nom)}" ${x.I(t.setNom)}></div>
        <div class="f"><label>Durée (min)</label><input type="number" class="mko-inp" value="${esc(t.duree)}" ${x.I(t.setDuree)}></div>
        <div class="f"><label>Profil demandé</label>${sel(x, esc, t.setProfil, t.profils)}</div></div>
      <div class="gc-mod"><b>Tâches à faire</b><span>une par ligne : avant | 2 | libellé — pendant | 0 | libellé — apres | 1 | libellé</span></div>
      <textarea class="mko-inp" style="height:${Math.max(90, 22 * (t.tachesTxt.split('\n').length + 1))}px;padding:8px 10px" ${x.I(t.setTaches)}>${esc(t.tachesTxt)}</textarea>
      <div class="gc-mod"><b>Checklist</b><span>${esc(t.checklistSous)}</span></div>
      ${t.modules.map(m => `<div class="gc-mod"><b style="font-size:11px">${esc(m.nom)}</b><span>${m.points.length} point${m.points.length > 1 ? 's' : ''}</span></div><div class="mko-mu" style="font-size:12px;line-height:1.5">${m.points.map(p => esc(p.libelle) + (p.photo ? ' 📷' : '') + (p.pct ? ' %' : '')).join(' · ')}</div>`).join('')}
      ${t.dynamique ? `<div class="mko-mu" style="margin-top:6px">${esc(t.dynamique)}</div>` : ''}
      <div class="cx-ligne" style="margin-top:12px"><button ${x.A(k.sauverTypes)} class="cx-btn prim" ${k.busy ? 'disabled' : ''}>Enregistrer les types</button><span class="mko-mu">${esc(t.note)}</span></div></div></div>` : '';
  const profils = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Profil de chaque consultant</span><span class="mko-mini">le planning propose d’abord les consultants du profil que le type demande</span></div><div class="mko-corps cx-ligne">${k.consultants.map(c => `<span class="cx-ligne" style="gap:6px"><b style="font-size:12.5px">${esc(c.nom)}</b>${sel(x, esc, c.setProfil, c.profils)}</span>`).join('')}<button ${x.A(k.sauverTypes)} class="mko-pied-btn" ${k.busy ? 'disabled' : ''}>Enregistrer</button></div></div>`;
  return `<div class="gc-kpis">${k.kpis.map(kk => kpi(esc, kk)).join('')}</div>${plan}<div class="cx-deux" style="margin-top:12px;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr)">${types}${def}</div><div style="margin-top:12px">${profils}</div>`;
}

/* --- Fiche franchisé ----------------------------------------------------------------- */
export function tplFF(c, x){
  const { esc } = x, f = c.ff;
  const entete = `<div class="mko-hd" style="margin-bottom:14px"><div class="cx-vues">${f.onglets.map(o => `<button ${x.A(o.choisir)} class="${o.on ? 'on' : ''}">${esc(o.nom)}</button>`).join('')}</div>
    <span class="mko-mu" style="margin-left:auto">Franchisé</span>${sel(x, esc, f.setShop, f.magasins)}<button ${x.A(f.rafraichir)} class="mko-pied-btn">Relire</button>${f.pageFranchise ? `<a class="mko-pied-btn" href="${esc(f.pageFranchise)}" target="_blank" rel="noopener">La page du franchisé ↗</a>` : ''}</div>`;
  const tableau = f.tableau ? `<div class="mko-carte" style="margin-bottom:12px"><div class="mko-ct"><span class="mko-lab">Les franchisés — ce que les données disent chaque jour · ce que le consultant constate sur place</span><span class="mko-mini">${esc(f.tableau.mini)}</span></div>
    <div style="overflow-x:auto"><table class="mko-tab"><thead><tr><th></th><th colspan="5" class="ff-grp j">Suivi journalier et opérations</th><th colspan="4" class="ff-grp t">Suivi de terrain</th></tr>
    <tr><th>Franchisé</th><th>Scoring</th><th>Tâches 30 j</th><th>Points moyens</th><th>Invendus 7 j</th><th>Réclamations 30 j</th><th>Note Google</th><th>CA semaine</th><th>Dernière visite</th><th>Plans ouverts</th><th>Planogramme</th><th>Client mystère</th></tr></thead><tbody>
    ${f.tableau.rows.map(r => `<tr class="${r.on ? 'on' : ''}" ${x.A(r.choisir)} style="cursor:pointer"><td class="nom"><span class="gc-feu ${r.feu}"></span>${esc(r.court)}<small>${esc(r.sous)}</small></td><td><b>${esc(r.scoring)}</b><small>${esc(r.scoringSous)}</small></td><td class="${r.tachesCls}">${esc(r.taches)}<small>${esc(r.tachesSous)}</small></td><td class="${r.revuesCls}">${esc(r.revues)}<small>${esc(r.revuesSous)}</small></td><td class="${r.invendusCls}">${esc(r.invendus)}</td><td class="${r.reclamationsCls}">${esc(r.reclamations)}<small>${esc(r.reclamationsSous)}</small></td><td>${esc(r.google)}</td><td class="${r.caCls}">${esc(r.ca)}</td><td>${esc(r.derniere)}<small>${esc(r.derniereSous)}</small></td><td class="${r.plansCls}">${esc(r.plans)}</td><td>${esc(r.plano)}</td><td>${esc(r.msp)}</td></tr>`).join('')}
    </tbody></table></div><div class="mko-corps mko-mu" style="padding-top:8px">Le feu est celui du module Visites. Le scoring du trimestre est la synthèse : Note Google et Tâches viennent du volet journalier, Client mystère du terrain, Budget du pilotage.</div></div>` : (f.tableauChargement ? `<div class="mko-carte" style="margin-bottom:12px"><div class="mko-corps mko-mu">Lecture des franchisés…</div></div>` : '');
  if (f.onglet === 'tableau') { return `<div data-screen="fiche-franchise">${entete}${tableau}</div>`; }
  if (f.indispo) { return `<div data-screen="fiche-franchise">${entete}<div class="mko-carte" style="padding:20px 22px;color:#8D1D2C;font-size:13px">${esc(f.indispo)}</div></div>`; }
  if (f.chargement) { return `<div data-screen="fiche-franchise">${entete}<div class="mko-carte" style="padding:20px 22px;color:var(--color-text-muted);font-size:13px">Lecture de la fiche de ${esc(f.shopNom)} : tâches et leurs notes, invendus, réclamations, objectifs, visites, plans, scoring…</div></div>`; }
  const bloc = b => `<div class="ff-bloc"><div class="bt"><b>${esc(b.titre)}</b>${b.lien ? `<button ${x.A(b.lien)} class="gc-lien">${esc(b.lienTxt)} ›</button>` : (b.href ? `<a class="gc-lien" href="${esc(b.href)}" target="_blank" rel="noopener">${esc(b.lienTxt)} ›</a>` : '')}</div><div class="l">${b.html}</div></div>`;
  const volet = (cls, titre, def, blocs) => `<div class="mko-carte ff-volet ${cls}"><div class="mko-corps"><h2>${esc(titre)}</h2><div class="def">${esc(def)}</div>${blocs.map(bloc).join('')}</div></div>`;
  const journal = `<div class="mko-carte" style="margin-top:12px"><div class="mko-ct"><span class="mko-lab">Journal de l’évaluation — tout ce qui a été constaté, noté ou décidé, les deux volets mêlés</span><span class="mko-mini">60 jours</span></div><div class="mko-corps" style="padding-top:4px">${f.journal.length ? f.journal.map(e => `<div class="ff-jl"><span class="d">${esc(e.le)}</span><span class="gc-src ${e.volet === 'terrain' ? 't' : 'j'}">${esc(e.volet)}</span><span>${esc(e.texte)}</span></div>`).join('') : '<div class="mko-mu">Rien sur la période.</div>'}</div></div>`;
  return `<div data-screen="fiche-franchise">${entete}${tableau}
    <div class="gc-titre"><h1>${esc(f.shopNom)}</h1><span class="ss">${f.sous}</span></div>
    <div class="gc-kpis">${f.kpis.map(k => kpi(esc, k)).join('')}</div>
    <div class="ff-deux">${volet('j', 'Suivi journalier et opérations', 'Ce que les données disent chaque jour, sans aller sur place : le panel, la caisse, la production, les avis.', f.journalier)}${volet('t', 'Suivi de terrain', 'Ce que le consultant constate sur place : les visites et leur checklist, les plans d’action, le client mystère, le comptoir.', f.terrain)}</div>${journal}</div>`;
}

/* --- Remarques aux opérateurs -------------------------------------------------------- */
export function tplRO(c, x){
  const { esc } = x, r = c.ro;
  const entete = `<div class="mko-hd" style="margin-bottom:14px"><span class="mko-mu">Magasin</span>${sel(x, esc, r.setShop, r.magasins)}<span class="mko-mu">Période</span>${sel(x, esc, r.setPeriode, r.periodes)}
    <div class="cx-puces" style="margin-left:8px">${r.operateurs.map(o => `<button ${x.A(o.choisir)} class="cx-pu${o.on ? ' on' : ''}">${esc(o.nom)}${o.n ? ' · ' + o.n : ''}</button>`).join('')}</div><button ${x.A(r.rafraichir)} class="mko-pied-btn" style="margin-left:auto">Relire</button></div>`;
  if (r.chargement) { return `<div data-screen="remarques-operateurs">${entete}<div class="mko-carte" style="padding:20px 22px;color:var(--color-text-muted);font-size:13px">Lecture des remarques…</div></div>`; }
  if (r.indispo) { return `<div data-screen="remarques-operateurs">${entete}<div class="mko-carte" style="padding:20px 22px;color:#8D1D2C;font-size:13px">${esc(r.indispo)}</div></div>`; }
  const liste = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">${esc(r.titre)}</span><span class="mko-mini">${esc(r.mini)}</span></div>
    ${r.lignes.length ? `<div style="overflow-x:auto"><table class="mko-tab"><thead><tr><th>Date</th><th>Opérateur</th><th>Produit</th><th class="n">Pièces</th><th>Motif</th><th>Remarque</th><th>Par</th></tr></thead><tbody>
    ${r.lignes.map(l => `<tr><td>${esc(l.le)}${l.heure ? ` <small>${esc(l.heure)}</small>` : ''}</td><td class="nom">${esc(l.employe)}</td><td>${esc(l.produit)}</td><td class="n">${esc(l.pieces)}</td><td>${esc(l.motif)}</td><td style="max-width:420px;white-space:normal">${esc(l.texte)}</td><td class="mu">${esc(l.auteur)}</td></tr>`).join('')}</tbody></table></div>` : '<div class="mko-corps mko-mu">Aucune remarque sur la période.</div>'}
    <div class="mko-corps mko-mu" style="padding-top:8px">Une remarque se crée depuis la modale des invendus du dashboard magasin, sur une pièce jetée pour un problème de qualité : elle nomme l’opérateur qui a produit, la pièce, l’heure et le motif, pour l’entretien d’évaluation.</div></div>`;
  const par = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Par opérateur</span></div><div class="mko-corps" style="padding-top:4px">${r.parOperateur.length ? r.parOperateur.map(o => `<div class="gc-barre"><span class="n" style="width:140px">${esc(o.nom)}</span><div class="b"><i style="width:${o.pct}%;background:#8D1D2C"></i></div><span class="v">${esc(o.txt)}</span></div>`).join('') : '<div class="mko-mu">—</div>'}</div></div>`;
  return `<div data-screen="remarques-operateurs">${entete}<div class="gc-kpis">${r.kpis.map(k => kpi(esc, k)).join('')}</div><div class="cx-deux">${liste}${par}</div></div>`;
}
