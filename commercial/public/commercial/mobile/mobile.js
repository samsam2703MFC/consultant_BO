/* L'app de terrain du commercial (19/11/2026) : la journée, les retards, la semaine ; pour chaque client, un assistant
 * horizontal — le contact (vu, absent, no show, pas le temps…), ce qui a été fait, l'offre, la prochaine action et sa
 * date, le récapitulatif — puis les gestes qui suivent (écrire, envoyer un bon, créer le bureau). API : ../api/cockpit/commercial
 * (commercial/src/commercial_semaine.php). La règle de la suite proposée est celle du serveur (smSuiteProposee). */
(function () {
  'use strict';
  const API = '../api/cockpit/commercial';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const m = document.getElementById('m');
  const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  const auj = () => new Date().toISOString().slice(0, 10);
  const plusJ = (d, n) => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
  const jourNom = d => { const x = new Date(d + 'T12:00:00'); return JOURS[x.getDay()] + ' ' + d.slice(8, 10) + '/' + d.slice(5, 7); };
  const hm = s => (s || '').slice(11, 16);
  let CFG = null, L = null, CLIENTS = [], BONS = [], jour = auj();

  function api(method, path, body) {
    return fetch(API + path, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
      .then(r => r.json().catch(() => ({})).then(j => { if (r.ok) return j; const e = j && j.error; return Promise.reject(new Error(typeof e === 'string' ? e : (e && e.message) || j.erreur || ('HTTP ' + r.status))); }));
  }
  function toast(t, ko) { const d = document.createElement('div'); d.className = 'toast' + (ko ? ' ko' : ''); d.textContent = t; d.style.bottom = '84px'; document.body.appendChild(d); setTimeout(() => d.remove(), ko ? 6000 : 3000); }

  /* --- la suite proposée : la règle du serveur ------------------------------------------------------------------- */
  function suiteProposee(issue, offre, faits, estClient) {
    if (['absent', 'no_show', 'pas_joint', 'pas_le_temps', 'reporte'].includes(issue)) return 'appeler';
    if (issue === 'mauvais_contact') return 'ecrire';
    if (issue === 'refus') return 'rien';
    if (offre === 'validee') return estClient ? 'rappel_commande' : 'bureau';
    if (offre === 'reflechir') return faits.includes('degustation') || faits.includes('echantillon') ? 'appeler' : 'echantillon';
    if (offre === 'refusee') return 'rien';
    return faits.includes('presentation') ? 'devis' : 'appeler';
  }
  const SUITES = { appeler: 'Appeler', ecrire: 'Écrire un e-mail', voucher: 'Envoyer un bon', rappel_commande: 'Rappeler après la 1re commande', repasser: 'Repasser',
    echantillon: 'Envoyer un échantillon', devis: 'Envoyer un devis', bureau: 'Créer le bureau', rien: 'Plus rien (classer)' };
  const dateSuite = (s) => { const j = (L.suites[s] || {}).jours || 0; let d = plusJ(auj(), j); if (new Date(d + 'T12:00:00').getDay() === 0) d = plusJ(d, 1); return d; };

  /* --- la journée ------------------------------------------------------------------------------------------------- */
  function carte(r) {
    const tel = r.telephone ? `<a class="btn" href="tel:${esc(r.telephone.replace(/[^\d+]/g, ''))}">Appeler</a>` : '';
    const nav = r.adresse ? `<a class="btn" href="https://maps.google.com/?q=${encodeURIComponent(r.adresse)}" target="_blank" rel="noopener">Itinéraire</a>` : '';
    const fait = r.statut !== 'a_faire';
    return `<div class="m-carte ${fait ? 'fait' : ''}"><div class="m-ligne"><span class="m-heure">${r.le.slice(0, 10) === jour ? hm(r.le) : jourNom(r.le.slice(0, 10)) + ' ' + hm(r.le)}</span><span class="small muted">${esc(r.typeNom)}${r.degustation ? ' · dégustation' : ''}</span></div>
      <div class="m-nom">${esc(r.prospect)}</div><div class="small muted">${esc([r.contact, r.magasin].filter(Boolean).join(' · '))}${r.confirmation === 'confirme' ? ' · <span style="color:var(--green)">confirmé</span>' : r.type === 'visite' && r.confirmation !== 'confirme' ? ' · pas confirmé' : ''}</div>
      ${r.note ? `<div class="small" style="margin-top:6px;color:var(--muted-2)">${esc(r.note)}</div>` : ''}
      ${fait ? `<div class="small" style="margin-top:8px">${esc((L.issues[r.issue] || r.statut))}</div>` : `<div class="m-actions">${tel}${nav}<button class="btn btn--primary" data-a="cr" data-v="${r.id}">Compte rendu</button></div>`}</div>`;
  }
  let DATA = null;
  function journee() {
    m.innerHTML = '<div class="vide">Lecture de la journée…</div>';
    api('GET', '/mobile?jour=' + jour).then(d => {
      DATA = d; L = d.listes;
      const titre = jour === auj() ? 'Aujourd’hui' : jourNom(jour);
      m.innerHTML = `<div class="m-jour"><button class="btn btn--sm" data-a="jour" data-v="-1">◀</button><h1>${titre}</h1><button class="btn btn--sm" data-a="jour" data-v="1">▶</button></div>
        ${d.enRetard.length && jour === auj() ? `<div class="m-sec">En retard · ${d.enRetard.length}</div>${d.enRetard.map(carte).join('')}` : ''}
        <div class="m-sec">${jour === auj() ? 'La journée' : 'Ce jour-là'} · ${d.aujourdhui.length}</div>${d.aujourdhui.length ? d.aujourdhui.map(carte).join('') : '<div class="m-carte small muted">Rien de prévu. Planifiez la semaine depuis la version bureau, ou notez un contact imprévu.</div>'}
        ${d.aVenir.length ? `<div class="m-sec">La suite de la semaine · ${d.aVenir.length}</div>${d.aVenir.map(carte).join('')}` : ''}
        <div class="m-flottant"><button class="btn btn--primary" data-a="imprevu">+ Un contact imprévu</button></div>`;
    }).catch(e => { m.innerHTML = `<div class="m-carte erreur">${esc(e.message)}</div>`; });
  }

  /* --- l'assistant ------------------------------------------------------------------------------------------------ */
  function assistant(rdv) {
    const estClient = !!(rdv && rdv.clientId);
    const st = { issue: '', faits: [], offre: (rdv && rdv.secteur) || '', offreStatut: '', motif: '', suite: '', suiteLe: '', note: '',
      prospect: rdv ? rdv.prospect : '', clientId: rdv ? rdv.clientId : null, shopId: rdv ? rdv.shopId : (CFG.magasins[0] || {}).id, contact: rdv ? rdv.contact : '', email: rdv ? rdv.email : '', telephone: rdv ? rdv.telephone : '' };
    const appel = rdv && rdv.type === 'appel';
    const w = document.createElement('div'); w.className = 'w'; document.body.appendChild(w);
    const etapes = () => {
      const vu = st.issue === 'vu' || st.issue === 'joint';
      return [rdv ? null : 'qui', 'contact', vu ? 'faits' : null, vu ? 'offre' : null, 'suite', 'recap'].filter(Boolean);
    };
    let i = 0;
    const NOMS = { qui: 'La société', contact: 'Le contact', faits: 'Ce que j’ai fait', offre: 'L’offre', suite: 'La prochaine action', recap: 'Le récapitulatif' };
    const choix = (liste, val, attr, un) => `<div class="w-choix ${un ? 'un' : ''}">${Object.entries(liste).map(([k, l]) => `<button data-${attr}="${k}" class="${(Array.isArray(val) ? val.includes(k) : val === k) ? 'is-on' : ''}">${esc(l)}</button>`).join('')}</div>`;
    function rendre() {
      const E = etapes(); if (i >= E.length) i = E.length - 1;
      const e = E[i];
      let c = '';
      if (e === 'qui') {
        c = `<h2>Avec qui ?</h2><label><span class="field-label">Un client du portail</span><select data-w="clientId"><option value="">— une autre société —</option>${CLIENTS.map(x => `<option value="${x.id}" ${st.clientId === x.id ? 'selected' : ''}>${esc(x.entreprise)} · ${esc(x.magasin)}</option>`).join('')}</select></label>
          <label><span class="field-label">Société</span><input data-w="prospect" value="${esc(st.prospect)}"></label><label><span class="field-label">Contact</span><input data-w="contact" value="${esc(st.contact)}"></label>
          <label><span class="field-label">Téléphone</span><input data-w="telephone" type="tel" value="${esc(st.telephone)}"></label><label><span class="field-label">E-mail</span><input data-w="email" type="email" value="${esc(st.email)}"></label>
          <label><span class="field-label">Magasin</span><select data-w="shopId">${CFG.magasins.map(x => `<option value="${esc(x.id)}" ${String(st.shopId) === String(x.id) ? 'selected' : ''}>${esc(x.nom)}</option>`).join('')}</select></label>`;
      }
      if (e === 'contact') {
        const issues = appel ? { joint: L.issues.joint, pas_joint: L.issues.pas_joint, reporte: L.issues.reporte, refus: L.issues.refus, mauvais_contact: L.issues.mauvais_contact }
          : { vu: L.issues.vu, absent: L.issues.absent, no_show: L.issues.no_show, pas_le_temps: L.issues.pas_le_temps, reporte: L.issues.reporte, refus: L.issues.refus, mauvais_contact: L.issues.mauvais_contact, joint: L.issues.joint };
        c = `<h2>${appel ? 'L’appel' : 'Le rendez-vous'} s’est passé comment ?</h2>${choix(issues, st.issue, 'issue')}`;
      }
      if (e === 'faits') c = `<h2>Qu’avez-vous fait ?</h2><p class="small muted" style="margin:-6px 0 12px">Plusieurs choix possibles.</p>${choix(L.faits, st.faits, 'fait')}`;
      if (e === 'offre') {
        c = `<h2>L’offre</h2><label style="margin-top:0"><span class="field-label">L’offre présentée</span><select data-w="offre"><option value="">—</option>${CFG.secteurs.map(s => `<option value="${s.id}" ${st.offre === s.id ? 'selected' : ''}>${esc(s.nom)}</option>`).join('')}</select></label>
          <div class="field-label" style="margin-top:14px">La réponse</div>${choix(L.offres, st.offreStatut, 'statut')}
          ${st.offreStatut === 'refusee' ? `<div class="field-label" style="margin-top:14px">Pourquoi ?</div>${choix(L.motifs, st.motif, 'motif')}` : ''}`;
      }
      if (e === 'suite') {
        const p = suiteProposee(st.issue, st.offreStatut, st.faits, estClient || !!st.clientId);
        if (!st.suite) { st.suite = p; st.suiteLe = dateSuite(p); }
        c = `<h2>La prochaine action</h2><div class="w-propose">Proposé d’après le contact : ${esc(SUITES[p])}${p !== 'rien' ? ', le ' + esc(jourNom(dateSuite(p))) : ''}.</div>
          <div class="w-choix un">${Object.entries(SUITES).sort((x, y) => (y[0] === p) - (x[0] === p)).map(([k, l]) => `<button data-suite="${k}" class="${st.suite === k ? 'is-on' : ''} ${p === k ? 'propose' : ''}">${esc(l)}${k !== 'rien' ? `<small>${(L.suites[k] || {}).jours ? 'dans ' + L.suites[k].jours + ' jour' + (L.suites[k].jours > 1 ? 's' : '') : 'aujourd’hui'}</small>` : '<small>offre refusée, pas de suite</small>'}</button>`).join('')}</div>
          ${st.suite !== 'rien' ? `<label><span class="field-label">Le</span><input type="date" data-w="suiteLe" value="${esc(st.suiteLe)}"></label>` : ''}`;
      }
      if (e === 'recap') {
        const lignes = [['Société', st.prospect], ['Contact', L.issues[st.issue] || '—']];
        if (st.faits.length) lignes.push(['Fait', st.faits.map(f => L.faits[f]).join(', ')]);
        if (st.offreStatut || st.offre) lignes.push(['Offre', [(CFG.secteurs.find(s => s.id === st.offre) || {}).nom, L.offres[st.offreStatut], st.motif ? L.motifs[st.motif] : ''].filter(Boolean).join(' · ')]);
        lignes.push(['Ensuite', SUITES[st.suite] + (st.suite !== 'rien' && st.suiteLe ? ', ' + jourNom(st.suiteLe) : '')]);
        c = `<h2>Le récapitulatif</h2><table class="w-recap" style="width:100%"><tbody>${lignes.map(([k, v]) => `<tr><td>${k}</td><td>${esc(v)}</td></tr>`).join('')}</tbody></table>
          <label><span class="field-label">Note (ce qui a été dit, les chiffres, les noms)</span><textarea data-w="note" placeholder="Dictez ou tapez…">${esc(st.note)}</textarea></label><p class="erreur" data-err></p>`;
      }
      const ok = e === 'qui' ? !!st.prospect.trim() : e === 'contact' ? !!st.issue : e === 'suite' ? !!st.suite : true;
      w.innerHTML = `<div class="w-tete"><div class="m-ligne"><span class="w-qui">${esc(st.prospect || 'Nouveau contact')}</span><button class="btn btn--sm btn--ghost" data-w-a="fermer">Fermer</button></div>
          <div class="w-prog">${E.map((_, k) => `<i class="${k === i ? 'on' : k < i ? 'fait' : ''}"></i>`).join('')}</div><div class="w-etape">Étape ${i + 1} sur ${E.length} · ${NOMS[e]}</div></div>
        <div class="w-corps">${c}</div>
        <div class="w-pied">${i > 0 ? '<button class="btn" data-w-a="prec">← Retour</button>' : ''}${e === 'recap' ? '<button class="btn btn--primary" data-w-a="enregistrer">Enregistrer</button>' : `<button class="btn btn--primary" data-w-a="suiv" ${ok ? '' : 'disabled'}>Suivant →</button>`}</div>`;
    }
    w.addEventListener('input', ev => { const k = ev.target.dataset.w; if (!k) return; st[k] = k === 'clientId' ? (ev.target.value ? Number(ev.target.value) : null) : ev.target.value;
      if (k === 'clientId' && st.clientId) { const x = CLIENTS.find(y => y.id === st.clientId); if (x) { Object.assign(st, { prospect: x.entreprise, contact: x.contact, email: x.email, telephone: x.telephone, shopId: x.shopId, offre: x.secteur }); rendre(); } }
      if (k === 'prospect') { const b = w.querySelector('[data-w-a=suiv]'); if (b) b.disabled = !st.prospect.trim(); } });
    w.addEventListener('change', ev => { const k = ev.target.dataset.w; if (k === 'offre' || k === 'shopId' || k === 'suiteLe') st[k] = ev.target.value; });
    w.addEventListener('click', ev => {
      const b = ev.target.closest('button'); if (!b) return;
      if (b.dataset.issue) { st.issue = b.dataset.issue; st.suite = ''; i++; rendre(); return; }
      if (b.dataset.fait) { const f = b.dataset.fait; st.faits = st.faits.includes(f) ? st.faits.filter(x => x !== f) : [...st.faits, f]; st.suite = ''; rendre(); return; }
      if (b.dataset.statut !== undefined) { st.offreStatut = b.dataset.statut; if (st.offreStatut !== 'refusee') st.motif = ''; st.suite = ''; rendre(); return; }
      if (b.dataset.motif) { st.motif = b.dataset.motif; rendre(); return; }
      if (b.dataset.suite) { st.suite = b.dataset.suite; st.suiteLe = dateSuite(st.suite); rendre(); return; }
      const a = b.dataset.wA;
      if (a === 'fermer') { if (!st.issue || confirm('Fermer sans enregistrer ?')) w.remove(); }
      if (a === 'prec') { i = Math.max(0, i - 1); rendre(); }
      if (a === 'suiv') { i++; rendre(); }
      if (a === 'enregistrer') {
        b.disabled = true;
        api('POST', '/cr', { rdvId: rdv ? rdv.id : null, clientId: st.clientId, prospect: st.prospect, contact: st.contact, email: st.email, telephone: st.telephone, shopId: st.shopId,
          issue: st.issue, faits: st.faits, offre: st.offre, offreStatut: st.offreStatut, motif: st.motif, suite: st.suite, suiteLe: st.suite !== 'rien' ? st.suiteLe : null, note: st.note })
          .then(r => apres(w, st, r)).catch(e => { b.disabled = false; w.querySelector('[data-err]').textContent = e.message; });
      }
    });
    rendre();
  }

  /* --- après l'enregistrement : les gestes qui suivent ------------------------------------------------------------ */
  function apres(w, st, r) {
    const gestes = [];
    if (['ecrire', 'voucher', 'devis'].includes(st.suite) && (st.email || st.clientId)) gestes.push(['ecrire', st.suite === 'voucher' ? 'Envoyer le bon maintenant' : 'Écrire maintenant']);
    if (st.suite === 'bureau' && !st.clientId) gestes.push(['bureau', 'Créer le bureau maintenant']);
    if (st.suite === 'echantillon') gestes.push(['echantillon', 'Commander l’échantillon (version bureau)']);
    w.innerHTML = `<div class="w-tete"><div class="m-ligne"><span class="w-qui">${esc(st.prospect)}</span></div><div class="w-prog"><i class="fait"></i></div><div class="w-etape">Enregistré</div></div>
      <div class="w-corps"><h2>C’est noté.</h2><p>${r.suivant ? `Prochaine action : <b style="font-weight:700">${esc(SUITES[r.suite])}</b>, ${esc(jourNom(r.suiteLe))}. Elle apparaît dans la journée de ce jour-là.` : 'Pas de suite : le contact est classé.'}</p>
        <div class="w-choix un" style="margin-top:16px">${gestes.map(([k, l]) => `<button data-g="${k}">${esc(l)}</button>`).join('')}</div><p class="erreur" data-err></p></div>
      <div class="w-pied"><button class="btn btn--primary" data-g="fin">Retour à la journée</button></div>`;
    w.addEventListener('click', ev => {
      const b = ev.target.closest('[data-g]'); if (!b) return;
      const g = b.dataset.g;
      if (g === 'fin') { w.remove(); journee(); }
      if (g === 'echantillon') { window.open('../?bureau=1#echantillons' + (st.clientId ? '?client=' + st.clientId : ''), '_blank'); }
      if (g === 'bureau') {
        b.disabled = true;
        api('POST', '/clients', { type: 'bureau', shopId: st.shopId, secteur: st.offre, entreprise: st.prospect, contact: st.contact, email: st.email, telephone: st.telephone, langue: 'fr', bonCreer: { avantage: '−10 % sur la 1re commande', jours: 30 } })
          .then(x => { st.clientId = x.client.id; CLIENTS.push(x.client); b.textContent = 'Bureau créé : ' + x.client.entreprise; toast('Bureau créé'); })
          .catch(e => { b.disabled = false; w.querySelector('[data-err]').textContent = e.message; });
      }
      if (g === 'ecrire') ecrire(st);
    });
  }
  function ecrire(st) {
    const f = document.createElement('div'); f.className = 'w'; document.body.appendChild(f);
    const modele = st.suite === 'voucher' ? 'relance' : st.suite === 'devis' ? 'offre' : (st.issue === 'vu' ? 'offre' : 'relance');
    f.innerHTML = `<div class="w-tete"><div class="m-ligne"><span class="w-qui">E-mail à ${esc(st.prospect)}</span><button class="btn btn--sm btn--ghost" data-e="fermer">Fermer</button></div><div class="w-etape">Depuis ${esc(CFG.smtp.expediteur)}</div></div>
      <div class="w-corps"><label style="margin-top:0"><span class="field-label">Adresse</span><input data-e="a" type="email" value="${esc(st.email)}"></label>
        <label><span class="field-label">Modèle</span><select data-e="modele">${Object.entries(CFG.modeles).map(([k, l]) => `<option value="${k}" ${k === modele ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
        <label><span class="field-label">Bon</span><select data-e="bon"><option value="">—</option>${BONS.filter(b => b.actif).map(b => `<option value="${esc(b.code)}">${esc(b.code)} · ${esc(b.avantage)}</option>`).join('')}</select></label>
        <label><span class="field-label">Langue</span><select data-e="langue"><option value="fr">Français</option><option value="nl">Nederlands</option></select></label><p class="erreur" data-err></p></div>
      <div class="w-pied"><button class="btn btn--primary" data-e="envoyer">Envoyer</button></div>`;
    f.addEventListener('click', ev => {
      const b = ev.target.closest('button[data-e]'); if (!b) return;
      if (b.dataset.e === 'fermer') f.remove();
      if (b.dataset.e === 'envoyer') {
        const v = k => f.querySelector(`[data-e="${k}"]`).value;
        b.disabled = true;
        api('POST', '/envoyer', { modele: v('modele'), a: v('a'), bon: v('bon'), langue: v('langue'), clientId: st.clientId, shopId: st.shopId, entreprise: st.prospect, contact: st.contact, offre: st.offre })
          .then(r => { toast('Envoyé à ' + r.a); f.remove(); }).catch(e => { b.disabled = false; f.querySelector('[data-err]').textContent = 'Non envoyé : ' + e.message; });
      }
    });
  }

  document.addEventListener('click', e => {
    const a = e.target.closest('[data-a]'); if (!a || a.closest('.w')) return;
    if (a.dataset.a === 'jour') { jour = plusJ(jour, Number(a.dataset.v)); journee(); }
    if (a.dataset.a === 'cr') { const r = [...DATA.enRetard, ...DATA.aujourdhui, ...DATA.aVenir].find(x => x.id === Number(a.dataset.v)); if (r) assistant(r); }
    if (a.dataset.a === 'imprevu') assistant(null);
  });
  Promise.all([api('GET', '/config'), api('GET', '/clients'), api('GET', '/bons')])
    .then(([c, cl, b]) => { CFG = c; CLIENTS = cl.clients; BONS = b.bons; journee(); })
    .catch(e => { m.innerHTML = `<div class="m-carte erreur">${esc(e.message)}</div>`; });
})();
