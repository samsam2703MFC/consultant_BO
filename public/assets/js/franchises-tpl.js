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
  const entete = `<div class="mko-hd" style="margin-bottom:14px"><button type="button" class="ff-menu" ${x.A(f.menu)} aria-label="Menu" title="Menu">☰</button><div class="cx-vues">${f.onglets.map(o => `<button ${x.A(o.choisir)} class="${o.on ? 'on' : ''}">${esc(o.nom)}</button>`).join('')}</div>
    <span class="mko-mu" style="margin-left:auto">Franchisé</span>${sel(x, esc, f.setShop, f.magasins)}<button ${x.A(f.rafraichir)} class="mko-pied-btn">Relire</button>${f.pageFranchise ? `<a class="mko-pied-btn" href="${esc(f.pageFranchise)}" target="_blank" rel="noopener">La page du franchisé ↗</a>` : ''}</div>`;
  const tableau = f.tableau ? `<div class="mko-carte" style="margin-bottom:12px"><div class="mko-ct"><span class="mko-lab">Benchmark réseau — les franchisés côte à côte</span><span class="mko-mini">${esc(f.tableau.mini)}</span></div>
    <div style="overflow-x:auto"><table class="mko-tab"><thead><tr><th></th><th colspan="8" class="ff-grp j">Suivi journalier et opérations</th><th colspan="4" class="ff-grp t">Suivi de terrain</th></tr>
    <tr><th>Franchisé</th><th>Scoring</th><th>Tâches 30 j</th><th>Points moyens</th><th>Invendus 7 j</th><th>Réclamations 30 j</th><th>Note Google</th><th>CA / budget mois</th><th>B2B mois</th><th>Dernière visite</th><th>Plans ouverts</th><th>Planogramme</th><th>Client mystère</th></tr></thead><tbody>
    ${f.tableau.rows.map(r => `<tr class="${r.on ? 'on' : ''}" ${x.A(r.choisir)} style="cursor:pointer"><td class="nom"><span class="gc-feu ${r.feu}"></span>${esc(r.court)}<small>${esc(r.sous)}</small></td><td><b>${esc(r.scoring)}</b><small>${esc(r.scoringSous)}</small></td><td class="${r.tachesCls}">${esc(r.taches)}<small>${esc(r.tachesSous)}</small></td><td class="${r.revuesCls}">${esc(r.revues)}<small>${esc(r.revuesSous)}</small></td><td class="${r.invendusCls}">${esc(r.invendus)}</td><td class="${r.reclamationsCls}">${esc(r.reclamations)}<small>${esc(r.reclamationsSous)}</small></td><td>${esc(r.google)}</td><td class="${r.moisCls}">${esc(r.mois)}<small>${esc(r.moisSous)}</small></td><td class="${r.b2bCls}">${esc(r.b2b)}<small>${esc(r.b2bSous)}</small></td><td>${esc(r.derniere)}<small>${esc(r.derniereSous)}</small></td><td class="${r.plansCls}">${esc(r.plans)}</td><td>${esc(r.plano)}</td><td>${esc(r.msp)}</td></tr>`).join('')}
    </tbody></table></div><div class="mko-corps mko-mu" style="padding-top:8px">Le feu est celui du module Visites. Le scoring du trimestre est la synthèse : Note Google et Tâches viennent du volet journalier, Client mystère du terrain, Budget du pilotage.</div></div>` : (f.tableauChargement ? `<div class="mko-carte" style="margin-bottom:12px"><div class="mko-corps mko-mu">Lecture des franchisés…</div></div>` : '');
  // Trois niveaux : la tuile (le chiffre), le dépliant (son détail), la modale (« Tout voir »).
  const tuile = t => `<div class="ff-k ${t.cls || ''}${t.on ? ' on' : ''}"><button class="ff-kh" ${x.A(t.toggle)}><small>${esc(t.lab)}</small><b>${esc(t.v)}</b><span>${esc(t.sous)}</span><i>${t.on ? '▴' : '▾'}</i></button>${t.on ? `<div class="ff-kd">${t.html}<div class="ff-kl">${t.modal ? `<button ${x.A(t.modal)} class="gc-lien">Tout voir ›</button>` : ''}${t.lien ? `<button ${x.A(t.lien)} class="gc-lien">${esc(t.lienTxt)} ›</button>` : (t.href ? `<a class="gc-lien" href="${esc(t.href)}" target="_blank" rel="noopener">${esc(t.lienTxt)} ›</a>` : '')}</div></div>` : ''}</div>`;
  const volet = (cls, titre, tuiles) => `<div class="mko-carte ff-volet ${cls}"><div class="mko-corps"><h2>${esc(titre)}</h2><div class="ff-grille">${tuiles.map(tuile).join('')}</div></div></div>`;
  const modal = f.modal ? `<div class="ff-modal" ${x.A(f.fermerModal)}><div class="ff-modal-c"><button class="ff-x" ${x.A(f.fermerModal)} title="Fermer">✕</button><h3>${esc(f.modal.titre)}</h3>${f.modal.html}</div></div>` : '';
  const pied = f.etapes ? `<div class="ff-etapes">${f.etapes.prec ? `<button ${x.A(f.etapes.prec.aller)} class="mko-pied-btn">‹ ${esc(f.etapes.prec.nom)}</button>` : '<span></span>'}${f.etapes.suiv ? `<button ${x.A(f.etapes.suiv.aller)} class="cx-btn prim">${esc(f.etapes.suiv.nom)} ›</button>` : ''}</div>` : '';
  const P = f.app;
  const appHd = P ? `<div class="ffa-hd"><button type="button" class="ffa-menu" ${x.A(f.menu)} aria-label="Menu">☰</button><img src="assets/img/logo.png" alt=""><div class="ffa-t"><b>${esc(P.titre)}</b><small>${esc(P.sous)}</small></div><span class="ffa-sp"></span>${sel(x, esc, f.setShop, f.magasins)}<button type="button" class="ffa-ic" ${x.A(f.rafraichir)} title="Relire" aria-label="Relire">↻</button>${f.pageFranchise ? `<a class="ffa-ic" href="${esc(f.pageFranchise)}" target="_blank" rel="noopener" title="La page du franchisé" aria-label="La page du franchisé">↗</a>` : ''}<span class="ffa-av">${esc(P.init)}</span></div>` : '';
  const appEt = P ? `<div class="ffa-vhd">${f.etapes && f.etapes.prec ? `<button type="button" class="ffa-ret" ${x.A(f.etapes.prec.aller)} aria-label="Étape précédente">‹</button>` : ''}<div><h2>${esc(P.etape.nom)}</h2><small>${esc(P.etape.aide)}</small></div><span class="ffa-sp"></span><span class="ffa-n">étape ${P.etape.num} / ${P.etape.total}</span></div>` : '';
  const appBas = P ? `<nav class="ffa-bas">${P.nav.map(n => `<button type="button" class="${n.on ? 'on' : ''}" ${x.A(n.choisir)}><i>${n.icone}</i>${esc(n.nom)}</button>`).join('')}</nav>` : '';
  const page = corps => `<div data-screen="fiche-franchise">${appHd}${entete}${appEt}${corps}${pied}${appBas}${modal}</div>`;
  const titre = `<div class="gc-titre"><h1>${esc(f.shopNom)}</h1><span class="ss">${f.sous}</span></div>`;
  const attente = txt => `<div class="mko-carte" style="padding:20px 22px;color:var(--color-text-muted);font-size:13px">${esc(txt)}</div>`;
  const carte = (h, corps, style) => `<div class="mko-carte"${style ? ` style="${style}"` : ''}><div class="mko-corps"><h2 class="ff-h2">${esc(h)}</h2>${corps}</div></div>`;
  const A = f.agenda, K = f.ck;
  const choixMag = h => carte(h, `<div class="ff-mags">${A.magasins.map(m => `<button ${x.A(m.choisir)} class="ff-mag${m.on ? ' on' : ''}"><span class="gc-feu ${esc(m.feu)}"></span><b>${esc(m.court)}</b><small>${esc(m.sous)}</small></button>`).join('')}</div>`);
  if (f.onglet === 'reseau') { return page(tableau); }
  if (f.onglet === 'agenda') {
    const jours = A.chargement ? attente('Lecture de l’agenda…') : (A.indispo ? attente(A.indispo) : carte('Mes visites · 14 jours', (A.jours.length ? A.jours.map(j => `<div class="ff-ag-j${j.auj ? ' auj' : ''}"><div class="ff-ag-d">${esc(j.lib)}</div>${j.visites.map(v => `<button ${x.A(v.choisir)} class="ff-ag-v ${esc(v.cls)}"><b>${esc(v.heure)}</b><span><b>${esc(v.magasin)}</b> · ${esc(v.type)}<small>${esc(v.consultant)}${v.statut ? ' · ' + esc(v.statut) : ''}</small></span><i>Commencer ›</i></button>`).join('')}</div>`).join('') : '<div class="mko-mu">Aucune visite planifiée sur 14 jours.</div>') + `<div class="cx-ligne" style="margin-top:10px"><button ${x.A(A.planifier)} class="mko-pied-btn">Planifier une visite</button></div>`));
    return page(`<div class="ff-deux ff-ag">${jours}${choixMag('Choisir le magasin')}</div>`);
  }
  if (f.aChoisir) { return page(choixMag('Choisissez d’abord le magasin de la visite')); }
  if (f.onglet === 'checklist') {
    if (K.chargement) { return page(titre + attente('Lecture des checklists du consultant dans le panel…')); }
    if (K.indispo) { return page(titre + attente(K.indispo)); }
    const cons = `<div class="ff-cons">${K.consultants.map(c => `<button ${x.A(c.choisir)} class="${c.on ? 'on' : ''}"><b>${esc(c.nom)}</b><small>${esc(c.sous)}</small></button>`).join('')}</div>`;
    const msg = K.msg ? `<div class="ff-msg ${K.msg.ko ? 'ko' : 'ok'}" style="margin:8px 0">${esc(K.msg.txt)}</div>` : '';
    if (!K.sel) {
      return page(titre + carte('Checklist du consultant', `${cons}<div class="mko-mu" style="margin:6px 0 10px">${esc(K.source)} · le cadre opérationnel du poste</div>${msg}<div class="ff-cls">${K.liste.length ? K.liste.map(c => `<button ${x.A(c.choisir)} class="ff-cl${c.remplies === c.n && c.n ? ' ok' : ''}"><b>${esc(c.nom)}</b><small>${esc(c.sous)}</small><span>${c.n} tâche${c.n > 1 ? 's' : ''}${c.oblig ? ' · ' + c.oblig + ' obligatoire' + (c.oblig > 1 ? 's' : '') : ''}</span><em>${c.remplies} / ${c.n} remplie${c.remplies > 1 ? 's' : ''}</em><i>›</i></button>`).join('') : '<div class="mko-mu">Aucune checklist au cadre opérationnel de ce poste.</div>'}</div>`));
    }
    const L = K.sel;
    return page(titre + carte(L.nom, `<div class="cx-ligne" style="margin-bottom:8px;gap:10px;flex-wrap:wrap"><button ${x.A(L.retour)} class="mko-pied-btn">‹ Les checklists</button><span class="mko-mu">${L.faites} / ${L.taches.length} remplie${L.faites > 1 ? 's' : ''}${L.description ? ' · ' + esc(L.description) : ''}</span></div>${msg}
      <div class="ff-taches">${L.taches.map(t => `<div class="ff-t"><div class="ff-t-h"><b>${esc(t.nom)}</b>${t.groupe ? `<small>${esc(t.groupe)}</small>` : ''}${t.description ? `<div class="mu">${esc(t.description)}</div>` : ''}<div class="ff-bad">${t.badges.map(b => `<span>${esc(b)}</span>`).join('')}${t.deja ? `<span class="ok">${esc(t.deja)}</span>` : ''}</div></div>
        <div class="ff-t-c">${t.choix.map(ch => `<button type="button" ${x.A(ch.choisir)} class="${ch.v}${ch.on ? ' on' : ''}">${esc(ch.nom)}</button>`).join('')}</div>
        <textarea class="mko-inp ff-t-com" rows="2" placeholder="Commentaire" ${x.I(t.setCom)}>${esc(t.commentaire)}</textarea></div>`).join('')}</div>
      <div class="cx-ligne" style="margin-top:12px;gap:14px;flex-wrap:wrap">${L.moi ? `<label class="ff-chk"><input type="checkbox" ${L.panel ? 'checked' : ''} ${x.C(L.setPanel)}> marquer aussi « fait » dans le panel</label>` : '<span class="mko-mu">Enregistré dans le cockpit : seul le compte du cockpit peut marquer une tâche faite dans le panel.</span>'}<button ${x.A(L.enregistrer)} class="cx-btn prim" ${K.busy ? 'disabled' : ''}>${K.busy ? 'Enregistrement…' : 'Enregistrer la checklist'}</button></div>`));
  }
  if (f.onglet === 'meteo') {
    const M = f.meteo;
    const form = carte('Comment se sent le franchisé', `<div class="ff-mf">${M.echelles.map(e => `<div class="ff-ech"><label>${esc(e.nom)}</label><div class="ff-ech-b">${e.choix.map(ch => `<button type="button" ${x.A(ch.choisir)} class="${ch.on ? 'on' : ''}" title="${esc(ch.titre)}"><span>${ch.icone}</span><small>${ch.n}</small></button>`).join('')}</div></div>`).join('')}</div>
      <div class="ff-mt">${M.textes.map(t => `<div class="f"><label>${esc(t.nom)}</label><textarea class="mko-inp" rows="3" placeholder="${esc(t.aide)}" ${x.I(t.set)}>${esc(t.v)}</textarea></div>`).join('')}</div>
      <div class="cx-ligne" style="margin-top:10px;gap:14px;flex-wrap:wrap"><label class="ff-chk"><input type="checkbox" ${M.taches ? 'checked' : ''} ${x.C(M.setTaches)}> une tâche par demande, échéance J+7</label><button ${x.A(M.enregistrer)} class="cx-btn prim" ${M.busy ? 'disabled' : ''}>${M.busy ? 'Enregistrement…' : 'Enregistrer la météo'}</button>${M.msg ? `<span class="ff-msg ${M.msg.ko ? 'ko' : 'ok'}">${esc(M.msg.txt)}</span>` : ''}</div>`);
    const der = M.derniere ? carte('Dernière météo · ' + M.derniere.le + (M.derniere.qui ? ' · ' + M.derniere.qui : ''), `<div class="ff-mc">${M.derniere.cases.map(k => `<div class="${k.cls}"><span>${k.icone}</span><small>${esc(k.nom)}</small><b>${esc(k.v)}</b></div>`).join('')}</div>${M.tendance.some(t => t.points.length > 1) ? `<div class="ff-tend">${M.tendance.map(t => `<div><small>${esc(t.nom)}</small><span>${t.points.map(pt => `<i title="${esc(pt.titre)}">${pt.icone}</i>`).join('')}</span></div>`).join('')}</div>` : ''}${M.ouvertes ? `<div class="mko-mu" style="margin-top:6px">Demandes encore ouvertes dans votre liste : ${M.ouvertes}</div>` : ''}`) : '';
    const hist = M.historique.length ? carte('Les météos', `<div class="ff-grille ff-g2">${M.historique.map(tuile).join('')}</div>`, der ? 'margin-top:12px' : '') : (M.chargement ? attente('Lecture des météos…') : attente('Aucune météo relevée pour ce franchisé.'));
    return page(titre + `<div class="ff-deux">${form}<div>${der}${hist}</div></div>`);
  }
  if (f.indispo) { return page(titre + `<div class="mko-carte" style="padding:20px 22px;color:#8D1D2C;font-size:13px">${esc(f.indispo)}</div>`); }
  if (f.chargement) { return page(titre + `<div class="mko-carte" style="padding:20px 22px;color:var(--color-text-muted);font-size:13px">Lecture de la fiche de ${esc(f.shopNom)} : tâches et leurs notes, invendus, réclamations, objectifs, visites, plans, scoring…</div>`); }
  const journal = `<div class="mko-carte" style="margin-top:12px"><div class="mko-ct"><span class="mko-lab">Journal — constaté, noté ou décidé</span><span class="mko-mini">60 jours</span></div><div class="mko-corps" style="padding-top:4px">${f.journal.length ? f.journal.map(e => `<div class="ff-jl"><span class="d">${esc(e.le)}</span><span class="gc-src ${e.volet === 'terrain' ? 't' : 'j'}">${esc(e.volet)}</span><span>${esc(e.texte)}</span></div>`).join('') : '<div class="mko-mu">Rien sur la période.</div>'}</div></div>`;
  return page(titre + carte('Santé du magasin', `<div class="ff-grille ff-g4">${f.sante.map(tuile).join('')}</div>`)
    + `<div class="ff-deux" style="margin-top:12px">${volet('j', 'Suivi journalier et opérations', f.journalier)}${volet('t', 'Suivi de terrain', f.terrain)}</div>${journal}`);
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
