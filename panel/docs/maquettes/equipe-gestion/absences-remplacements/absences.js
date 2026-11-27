/* Absences, congés et remplacements dans Mon équipe › Planning (maquette du 27/11/2026).
 * Sam : « lorsqu'une personne est engagée sous un contrat de travail, lorsqu'elle est absente ou en maladie, les frais
 * doivent rester et on doit pouvoir, aux mêmes heures, rajouter une tierce personne avec les frais supplémentaires que
 * ça apporte. Et congé payé. »
 *
 * Posé sur le vrai écran Semaine (panel/public/dashboard/equipe.js, rendTableau), déjà rendu avec la fausse API des
 * vidéos (Magasin démo). Les remplaçants sont de vrais services (le capteur les a créés par la fausse API) : le vrai code
 * compte déjà leur coût. Le service de la personne absente reste au planning : le vrai code compte son coût (il reste,
 * c'est la demande) ; la maquette le montre absent, le retire de la présence (les linéaires, le comptoir) et dit la part
 * des absences dans le budget. Tout est relu ou recalculé ici à partir de /dashboard/equipe/planning.
 *
 * window.MAQ_SC (le scénario) : { semaine, absences: [{emp, du, au, motif, certificat}], remplacements: [{emp, date,
 * remplace}], choix, interim }. window.MAQ.semaine() habille la semaine ; MAQ.absence(emp, date) ouvre la fenêtre
 * d'une absence ; MAQ.conge(emp, du, au) celle d'un congé sur plusieurs jours. */
(() => {
  const SC = window.MAQ_SC, API = location.origin + '/api/cockpit';
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const mn = t => { const m = /^(\d{1,2}):(\d{2})/.exec(String(t || '')); return m ? 60 * +m[1] + +m[2] : null; };
  const hh = x => String(Math.floor(x / 60)).padStart(2, '0') + ':' + String(x % 60).padStart(2, '0');
  const hcl = x => Math.floor(x / 60) + ' h' + (x % 60 ? ' ' + String(x % 60).padStart(2, '0') : '');
  const fE = n => (Math.round(n) || 0).toLocaleString('fr-BE').replace(/\s/g, ' ') + ' €';
  const fE2 = n => n.toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).replace(/\s/g, ' ') + ' €';
  const fH = h => { const r = Math.round(h * 4) / 4; return String(r).replace('.', ',') + '\u00a0h'; };
  const fT = t => t.toFixed(2).replace('.', ',') + '\u00a0€/h';
  const fP = x => x.toFixed(1).replace('.', ',') + ' %';
  const fPt = x => x.toFixed(1).replace('.', ',') + ' pt' + (x >= 2 ? 's' : '');
  const sE = x => (x >= 0 ? '+' : '−') + fE(Math.abs(x));
  const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const dt = s => new Date(s + 'T12:00:00');
  const plus = (s, n) => { const d = dt(s); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const jour = s => JOURS[dt(s).getDay()];
  const j3 = s => jour(s).slice(0, 3) + '.';
  const Jour = s => jour(s).replace(/^./, c => c.toUpperCase());
  const dCourt = s => j3(s) + ' ' + dt(s).getDate();
  const dLong = s => jour(s) + ' ' + dt(s).getDate() + ' ' + MOIS[dt(s).getMonth()];
  const wd = s => { const g = dt(s).getDay(); return String(g === 0 ? 7 : g); };
  const ini = n => String(n || '?').split(/\s+/).filter(Boolean).map(x => x[0]).slice(0, 2).join('').toUpperCase();
  const prenom = e => e.nom.split(' ')[0];
  const COMPTOIR = /vente|vendeu|comptoir|caisse/i;
  const auComptoir = e => (e.postes || []).some(p => !p.secteur && COMPTOIR.test(p.nom || ''));
  const niveau = (P, x) => { const b = P.budget; x = Math.round(x * 10) / 10; return x <= b.cible ? 'ok' : x <= b.rouge ? 'orange' : x <= b.noir ? 'rouge' : 'noir'; };
  const marq = (P, x) => `<span class="eqp-m eqp-m-${niveau(P, x)}">${fP(x)}</span>`;
  const fusion = L => { const r = []; L.slice().sort((a, b) => a[0] - b[0]).forEach(([a, b]) => { const d = r[r.length - 1]; if (d && a <= d[1]) { d[1] = Math.max(d[1], b); } else { r.push([a, b]); } }); return r; };
  const trous = (L, a, b) => { const r = []; let c = a; fusion(L).forEach(([x, y]) => { if (x > c) { r.push([c, Math.min(x, b)]); } c = Math.max(c, y); }); if (c < b) { r.push([c, b]); } return r.filter(([x, y]) => y > x); };

  /* Les motifs d'absence : payée (le coût reste au planning) ou non. Une personne sans contrat de travail (intérim,
   * B2B, contrat civil, bénévole, stage) n'est payée que pour ses heures prestées : son absence sort du coût. */
  const MOTIFS = {
    maladie: { nom: 'Maladie', court: 'Malade', case: 'Malade · payée', paye: true, dit: 'Salaire garanti : le coût reste' },
    cp: { nom: 'Congé payé', court: 'Congé payé', case: 'Congé payé', paye: true, dit: 'Ses heures restent payées' },
    circ: { nom: 'Congé de circonstance', court: 'Circonstance', case: 'Circonstance · payée', paye: true, dit: 'Mariage, décès, déménagement… : le coût reste' },
    nonpaye: { nom: 'Absence non payée', court: 'Absente', case: 'Absente · non payée', paye: false, dit: 'Sans solde, injustifiée : le coût sort' },
  };
  const CONTRAT_PAYE = { employment_contract: true };

  const lire = (du, n) => fetch(`${API}/dashboard/equipe/planning?shop=4&du=${du}&jours=${n}`, { headers: { Accept: 'application/json' } }).then(r => r.json());
  const lireFiche = id => fetch(`${API}/dashboard/equipe/personne?shop=4&id=${id}`, { headers: { Accept: 'application/json' } }).then(r => r.json()).then(r => r.personne).catch(() => null);

  /* ── Le calcul : un jour, avec ses absences ── */
  const absenceDe = (emp, date) => SC.absences.find(a => a.emp === emp && date >= a.du && date <= a.au) || null;
  const rempDe = (emp, date) => SC.remplacements.find(r => r.emp === emp && r.date === date) || null;
  function jourCalc(P, J, extra) {
    const eq = new Map(P.employes.map(e => [e.id, e]));
    const sv = J.services.map(s => ({ id: s.id, employe: s.employe, de: mn(s.de), a: mn(s.a), abs: absenceDe(s.employe, J.date) }))
      .concat(extra ? [Object.assign({ id: 0, abs: null }, extra)] : []);
    let cout = 0, coutAbs = 0, h = 0, hAbs = 0;
    sv.forEach(s => {
      const e = eq.get(s.employe) || { taux: s.taux || 0 }, d = (s.a - s.de) / 60, t = s.taux != null ? s.taux : (e.taux || 0);
      if (s.abs) { hAbs += d; if (MOTIFS[s.abs.motif].paye) { coutAbs += d * t; cout += d * t; } return; }
      h += d; cout += d * t;
    });
    const presents = sv.filter(s => !s.abs);
    const ca = J.ca > 0 ? J.ca : J.objectif;
    const ouv = (P.ouvertures || {})[wd(J.date)] || null;
    const lin = lignes(P).map(l => {
      const cv = fusion(presents.filter(s => l.f(eq.get(s.employe) || s)).map(s => [s.de, s.a]));
      return { nom: l.nom, comptoir: l.comptoir, cv, vide: !cv.length, trous: l.comptoir && ouv && cv.length ? trous(cv, ouv[0], ouv[1]) : [] };
    });
    return { J, sv, eq, cout, coutAbs, h, hAbs, ca, pct: ca ? cout / ca * 100 : null, pctSans: ca ? (cout - coutAbs) / ca * 100 : null, ouv, lin,
      nPres: new Set(presents.map(s => s.employe)).size, nAbs: new Set(sv.filter(s => s.abs).map(s => s.employe)).size };
  }
  function lignes(P) {
    const E = P.employes || [];
    const L = (P.secteurs || []).filter(s => E.some(e => (e.secteurs || []).includes(s.id))).map(s => ({ nom: s.nom, f: e => (e.secteurs || []).includes(s.id), comptoir: false }));
    if (E.some(auComptoir)) { L.push({ nom: 'Comptoir', f: e => auComptoir(e) || e.comptoir, comptoir: true }); }
    return L;
  }

  /* ── 1. La semaine ── */
  async function semaine() {
    const tab = $('section.eqp-tab.eqp-sem'); if (!tab) { throw new Error('pas de semaine à l’écran'); }
    const P = await lire(SC.semaine, 7), CJ = P.jours.map(J => jourCalc(P, J)), eq = new Map(P.employes.map(e => [e.id, e]));
    const allSv = P.jours.flatMap(J => J.services.map(s => [mn(s.de), mn(s.a)]));
    const H0 = Math.min(240, ...allSv.map(([a]) => Math.floor(a / 60) * 60)), H1 = Math.max(1200, ...allSv.map(([, b]) => Math.ceil(b / 60) * 60));
    const X = m => ((Math.min(H1, Math.max(H0, m)) - H0) / (H1 - H0) * 100).toFixed(2) + '%', W = (a, b) => ((Math.min(H1, b) - Math.max(H0, a)) / (H1 - H0) * 100).toFixed(2) + '%';

    // Les services : absents (hachurés, le motif, qui remplace ou « À remplacer ») et remplaçants.
    const remplacants = {};   // date|emp absent → nom du remplaçant
    SC.remplacements.forEach(r => { remplacants[r.date + '|' + r.remplace] = eq.get(r.emp); });
    CJ.forEach(C => C.sv.forEach(s => {
      const b = $(`td[data-eqp-cell="${C.J.date}"][data-emp="${s.employe}"] [data-eqp-svc="${s.id}"]`); if (!b) { return; }
      if (s.abs) {
        const M = MOTIFS[s.abs.motif], r = remplacants[C.J.date + '|' + s.employe];
        b.classList.add('maq-abs'); b.title = M.nom + ' : ' + (M.paye ? 'ses heures restent payées' : 'non payée');
        const sm = $('small', b); if (sm) { sm.textContent = M.case; }
        b.insertAdjacentHTML('beforeend', r ? `<span class="maq-par">→ ${esc(prenom(r))} la remplace</span>` : '<span class="tag-warn maq-ar">À remplacer</span>');
      }
      const rp = rempDe(s.employe, C.J.date);
      if (rp) { b.classList.add('maq-rp'); b.insertAdjacentHTML('beforeend', `<span class="maq-rp-t">remplace ${esc(prenom(eq.get(rp.remplace)))}</span>`); }
    }));
    // Les totaux de la ligne : la part d'absence dans les heures et le coût de la personne.
    SC.absences.forEach(a => {
      const tr = $(`td[data-emp="${a.emp}"]`, tab) && $(`td[data-emp="${a.emp}"]`, tab).closest('tr'); if (!tr) { return; }
      let h = 0, c = 0; const e = eq.get(a.emp);
      CJ.forEach(C => C.sv.filter(s => s.employe === a.emp && s.abs).forEach(s => { h += (s.a - s.de) / 60; if (MOTIFS[s.abs.motif].paye) { c += (s.a - s.de) / 60 * e.taux; } }));
      const qui = $('.eqp-pers > span:last-child', tr); if (!qui || !h) { return; }
      qui.insertAdjacentHTML('beforeend', `<span class="sub maq-sub"><i></i>${esc(MOTIFS[a.motif].nom)} ${a.du === a.au ? esc(dCourt(a.du)) : esc(dCourt(a.du)) + ' → ' + esc(dCourt(a.au))} · ${fH(h)} payées, ${fE(c)}</span>`);
    });
    // La tête : chaque jour, la part des absences dans son budget, et qui est vraiment là.
    const pd = $$('thead tr.eqp-pal th.eqp-pd', tab);
    CJ.forEach((C, i) => {
      const th = pd[i]; if (!th || !C.nAbs) { return; }
      const w = $('.w', th), s = $('.s', th);
      if (w && C.coutAbs && C.ca) { w.insertAdjacentHTML('afterend', `<span class="maq-pd-a">dont ${fPt(C.coutAbs / C.ca * 100)} d’absence</span>`); }
      if (s) { s.textContent = `${C.nPres} pers. (+${C.nAbs} abs.), ${fH(C.h)}`; s.classList.add('maq-pd-s'); }
    });
    // Les linéaires des jours d'absence : la présence sans la personne absente (le trou du comptoir revient).
    const lins = $$('thead tr.eqp-lin', tab);
    CJ.forEach((C, i) => {
      if (!C.nAbs) { return; }
      const ouv = C.ouv ? `<span class="eqp-ouv" style="left:${X(C.ouv[0])};width:${W(C.ouv[0], C.ouv[1])}"></span>` : '';
      lins.forEach(tr => {
        const nom = $('td', tr).textContent.trim(), x = C.lin.find(l => l.nom === nom), td = $$('td', tr)[i + 1]; if (!x || !td) { return; }
        td.classList.toggle('trou-c', x.vide || x.trous.length > 0);
        td.innerHTML = `<span class="eqp-mini l">${ouv}${x.cv.map(([a, b]) => `<span class="eqp-cv" style="left:${X(a)};width:${W(a, b)}"></span>`).join('')}${x.vide ? '<span class="eqp-trou" style="left:0;width:100%"></span>' : ''}${x.trous.map(([a, b]) => `<span class="eqp-trou" style="left:${X(a)};width:${W(a, b)}"></span>`).join('')}</span>${x.vide ? '<span class="eqp-tr">personne</span>' : x.trous.length ? `<span class="eqp-tr">${x.trous.map(([a, b]) => hcl(a) + '–' + hcl(b)).join(', ')}</span>` : ''}`;
      });
    });
    // Le résumé : la part des absences, la semaine sans elles, puis chaque absence ; « À corriger » dit le remplacement à faire.
    const cout = CJ.reduce((a, C) => a + C.cout, 0), coutAbs = CJ.reduce((a, C) => a + C.coutAbs, 0), ca = CJ.reduce((a, C) => a + (C.ca || 0), 0);
    const hAbs = CJ.reduce((a, C) => a + C.hAbs, 0), hPres = CJ.reduce((a, C) => a + C.h, 0);
    const rs = $('section.eqp-rs');
    const meta = rs && $('.eqp-rs-meta', rs);
    if (meta) { meta.innerHTML = `${fH(hPres + hAbs)} payées, <b>dont ${fH(hAbs)} d’absence</b> : ${fH(hPres)} au magasin${/pour un budget de ([\d,]+ h)/.test(meta.textContent) ? ' (budget ' + /pour un budget de ([\d,]+ h)/.exec(meta.textContent)[1] + ')' : ''}`; }
    const parPersonne = SC.absences.map(a => {
      const e = eq.get(a.emp), M = MOTIFS[a.motif];
      const L = CJ.flatMap(C => C.sv.filter(s => s.employe === a.emp && s.abs).map(s => ({ date: C.J.date, s })));
      const h = L.reduce((x, y) => x + (y.s.a - y.s.de) / 60, 0), c = M.paye ? h * e.taux : 0;
      const etats = L.map(({ date }) => { const r = remplacants[date + '|' + a.emp], rs2 = r && CJ.find(C => C.J.date === date).sv.find(s => s.employe === r.id);
        return r ? `<span class="maq-ab-j ok">${esc(dCourt(date))} : ${esc(prenom(r))} <em>${sE((rs2.a - rs2.de) / 60 * r.taux)}</em></span>` : `<span class="maq-ab-j ko">${esc(dCourt(date))} : à remplacer</span>`; }).join('');
      return `<div class="maq-ab-l"><span class="eqp-av">${esc(ini(e.nom))}</span><div><b>${esc(e.nom)}</b><span class="chip chip--neutral">${esc(M.nom)}${a.certificat ? ' · certificat' : ''}</span>
        <span class="maq-ab-p">${a.du === a.au ? esc(dLong(a.du)) : 'du ' + esc(dCourt(a.du)) + ' au ' + esc(dCourt(a.au))} · ${fH(h)} ${M.paye ? 'payées : ' + fE(c) + ' qui restent' : 'non payées'}</span></div><div class="maq-ab-r">${etats}</div></div>`;
    }).join('');
    const remps = SC.remplacements.reduce((a, r) => { const C = CJ.find(x => x.J.date === r.date), s = C && C.sv.find(x => x.employe === r.emp); return s ? a + (s.a - s.de) / 60 * eq.get(r.emp).taux : a; }, 0);
    if (rs) {
      const bloc = document.createElement('div'); bloc.className = 'maq-rs-abs';
      bloc.innerHTML = `<div class="maq-rs-t"><span class="maq-rs-k">Absences et congés</span><b>${fE(coutAbs)}</b><span>payés sans présence · ${fPt(coutAbs / ca * 100)} du budget</span></div>
        <div class="maq-rs-ls">${parPersonne}</div>
        <div class="maq-rs-s"><span>Sans les absences</span><b>${marq(P, (cout - coutAbs) / ca * 100)}</b><span>Remplacements : ${sE(remps)}</span></div>`;
      const l2 = $('.eqp-rs-l2', rs); rs.insertBefore(bloc, l2 || null);
      // « À corriger » : le trou laissé par une absence non remplacée, et la part d'absence d'un jour hors cible.
      CJ.forEach(C => {
        const b = $(`.eqp-rs-b[data-eqp-jour="${C.J.date}"]`, rs);
        const nonR = C.sv.filter(s => s.abs && !remplacants[C.J.date + '|' + s.employe]);
        const tr = (C.lin.find(l => l.comptoir) || { trous: [] }).trous;
        if (nonR.length) {
          const txt = `${nonR.map(s => prenom(eq.get(s.employe))).join(', ')} ${MOTIFS[nonR[0].abs.motif].court.toLowerCase()}${tr.length ? ' : comptoir vide de ' + tr.map(([a, c]) => hcl(a) + ' à ' + hcl(c)).join(', ') : ''}`;
          const html = `<span class="s"><i class="eqp-ic-trou" aria-hidden="true"></i>${esc(txt)}</span><span class="tag-warn">à remplacer</span>`;
          if (b) { b.insertAdjacentHTML('beforeend', html); } else if (l2) { l2.insertAdjacentHTML('beforeend', `<button type="button" class="btn btn--sm eqp-rs-b"><span class="j">${esc(Jour(C.J.date))}</span>${html}</button>`); }
        } else if (b && C.coutAbs && C.ca) { b.insertAdjacentHTML('beforeend', `<span class="s maq-s">${fP(C.pctSans)} sans ${C.sv.some(s => s.abs && s.abs.motif === 'cp') ? 'le congé' : 'l’absence'}</span>`); }
      });
    }
    // La légende, et l'entrée « Absence ou congé » dans la barre.
    const leg = $('p.eqp-note .eqp-leg');
    if (leg) { leg.insertAdjacentHTML('afterbegin', '<span><i class="maq-lg-a"></i>absence payée : le coût reste au budget</span><span><i class="maq-lg-r"></i>remplaçant·e : son coût s’ajoute</span>'); }
    const aj = $('[data-eqp-ajouter]');
    if (aj && !$('.maq-btn-abs')) { aj.insertAdjacentHTML('beforebegin', '<button type="button" class="btn maq-btn-abs">Absence ou congé</button>'); }
    return { cout, coutAbs, ca, pct: cout / ca * 100, pctSans: (cout - coutAbs) / ca * 100, hAbs, remps };
  }

  /* ── La fenêtre commune ── */
  function fenetre(html, large) {
    fermer();
    const w = document.createElement('div'); w.className = 'eqp maq-wrap';
    w.innerHTML = `<div class="eqp-voile"><div class="eqp-fo card maq-fo${large ? ' maq-fo-l' : ''}" role="dialog" aria-modal="true">${html}</div></div>`;
    document.body.appendChild(w); return w;
  }
  function fermer() { $$('.maq-wrap').forEach(n => n.remove()); }
  const tete = (e, f, sous) => `<div class="eqp-fo-tete"><span class="eqp-av">${esc(ini(e.nom))}</span><div><b>${esc(e.nom)}</b> <span class="chip chip--neutral maq-ct">${esc(f && f.contrat === 'employment_contract' ? 'Contrat de travail' : f && f.contrat === 'internship' ? 'Stage' : 'Contrat')}</span><span class="sub">${sous}</span></div><button type="button" class="eqp-fo-x" aria-label="Fermer">×</button></div>`;
  const radio = () => '<span class="eqp-fo-radio"></span>';

  /* ── 2. Une absence, depuis un service : le motif, la période, le remplacement ── */
  async function absence(emp, date) {
    const P = await lire(SC.semaine, 7), eq = new Map(P.employes.map(e => [e.id, e])), e = eq.get(emp), f = await lireFiche(emp);
    const J = P.jours.find(x => x.date === date), C0 = jourCalc(P, J), s0 = C0.sv.find(s => s.employe === emp), a = absenceDe(emp, date);
    const M = MOTIFS[a.motif], d0 = (s0.a - s0.de) / 60, cS = d0 * e.taux;
    const semCJ = P.jours.map(x => jourCalc(P, x)), semCout = semCJ.reduce((x, C) => x + C.cout, 0), semCa = semCJ.reduce((x, C) => x + (C.ca || 0), 0);
    const hSem = id => semCJ.reduce((x, C) => x + C.sv.filter(s => s.employe === id && !s.abs).reduce((y, s) => y + (s.a - s.de) / 60, 0), 0);
    // Les remplacements possibles : ne pas remplacer, étendre quelqu'un déjà là, quelqu'un de l'équipe libre ces heures, quelqu'un d'extérieur.
    const cands = [{ k: 'rien', nom: 'Ne pas remplacer', sous: 'le service reste vide', extra: null }];
    C0.sv.filter(s => !s.abs && s.employe !== emp && auComptoir(eq.get(s.employe)) && s.de > s0.de && s.de < s0.a && s.a >= s0.a).forEach(s => {
      const x = eq.get(s.employe); cands.push({ k: 'et' + x.id, nom: 'Étendre ' + prenom(x), sous: `${hh(s.de)} → ${hh(s0.de)} · déjà là jusqu’à ${hh(s.a)}`, extra: { employe: x.id, de: s0.de, a: s.de, taux: x.taux }, emp: x, seule: true });
    });
    const occupes = new Set(C0.sv.map(s => s.employe));
    P.employes.filter(x => x.actif !== false && x.id !== emp && !occupes.has(x.id) && auComptoir(x)).sort((x, y) => x.taux - y.taux).slice(0, 3)
      .forEach(x => cands.push({ k: 'p' + x.id, nom: x.nom, sous: `${(x.postes || []).map(p => p.nom).slice(0, 2).join(', ')} · ${fT(x.taux)}`, extra: { employe: x.id, de: s0.de, a: s0.a, taux: x.taux }, emp: x }));
    cands.push({ k: 'ext', nom: 'Quelqu’un d’extérieur', sous: 'intérim, extra, étudiant·e d’agence', extra: { employe: -1, de: s0.de, a: s0.a, taux: SC.interim, comptoir: true }, ext: true });
    const res = cands.map(c => {
      const C = jourCalc(P, J, c.extra), dC = C.cout - C0.cout, cp = C.lin.find(l => l.comptoir), cp0 = C0.lin.find(l => l.comptoir);
      return Object.assign(c, { C, dC, pct: C.pct, trous: cp ? cp.trous : [], comble: cp0 && cp0.trous.length && cp && !cp.trous.length,
        hAv: c.emp ? hSem(c.emp.id) : null, hAp: c.emp ? hSem(c.emp.id) + (c.extra.a - c.extra.de) / 60 : null });
    });
    const choisi = res.find(c => c.k === 'p' + SC.choix) || res[1];
    const carte = c => `<div class="eqp-fo-carte maq-rc${c === choisi ? ' on' : ''}">${radio()}<span class="t">${esc(c.nom)}<span>${c.extra ? hh(c.extra.de) + '–' + hh(c.extra.a) : ''}</span></span><span class="prix">${c.extra ? sE(c.dC) : '0 €'}</span>
      <span class="d"><span>${esc(c.sous)}</span>${c.trous.length ? `<span class="ko">comptoir vide de ${c.trous.map(([x, y]) => hcl(x) + ' à ' + hcl(y)).join(', ')}</span>` : `<span class="bon">✓ comptoir tenu${c.seule ? ' (seule l’après-midi)' : ''}</span>`}
      <span>${Jour(date)} ${marq(P, c.pct)}${c.hAv != null ? ` · sa semaine ${fH(c.hAv)} → ${fH(c.hAp)}` : ''}</span>${c.ext ? `<span class="maq-tx">taux <b>${fT(SC.interim)}</b> (facture de l’agence)</span>` : ''}</span></div>`;
    // La période : ses services du premier au dernier jour, et ce qu'il en est de chacun.
    const periode = [];
    for (let d = a.du; d <= a.au; d = plus(d, 1)) {
      const C = semCJ.find(x => x.J.date === d), s = C && C.sv.find(x => x.employe === emp);
      const r = s && SC.remplacements.find(x => x.date === d && x.remplace === emp);
      periode.push(`<div class="maq-pj${d === date ? ' ici' : ''}"><b>${esc(dCourt(d))}</b>${s ? `<span>${hh(s.de)}–${hh(s.a)} · ${fH((s.a - s.de) / 60)} payées</span>${d === date ? '<em class="maq-pj-e">ce service</em>' : r ? `<em class="ok">${esc(prenom(eq.get(r.emp)))} la remplace</em>` : '<em class="ko">à remplacer</em>'}` : '<span class="mu">repos</span>'}</div>`);
    }
    const motifs = Object.entries(MOTIFS).map(([k, m]) => `<div class="eqp-fo-carte maq-mc${k === a.motif ? ' on' : ''}">${radio()}<span class="t">${esc(m.nom)}</span><span class="prix ${m.paye ? '' : 'moins'}">${m.paye ? fE2(cS) + '<small>reste au budget</small>' : '−' + fE2(cS) + '<small>sort du budget</small>'}</span><span class="d"><span>${esc(m.dit)}</span></span></div>`).join('');
    // Qui est là ce jour : la personne absente hachurée, le remplaçant choisi, le comptoir.
    const sv = choisi.C.sv.slice().sort((x, y) => x.de - y.de || x.a - y.a);
    const Hd = Math.floor(Math.min(...sv.map(s => s.de)) / 60) * 60, Hf = Math.ceil(Math.max(...sv.map(s => s.a)) / 60) * 60;
    const X = m => ((Math.min(Hf, Math.max(Hd, m)) - Hd) / (Hf - Hd) * 100).toFixed(2) + '%', W = (x, y) => ((Math.min(Hf, y) - Math.max(Hd, x)) / (Hf - Hd) * 100).toFixed(2) + '%';
    const ouv = C0.ouv ? `<span class="eqp-fo-ouv" style="left:${X(C0.ouv[0])};width:${W(C0.ouv[0], C0.ouv[1])}"></span>` : '';
    let axe = ''; for (let t = Hd; t <= Hf; t += 60) { axe += `<i style="left:${X(t)}">${t / 60} h</i>`; }
    const nomDe = s => s.employe === -1 ? 'Intérim' : eq.get(s.employe).nom;
    const parPers = new Map(); sv.forEach(s => { const k = s.employe; if (!parPers.has(k)) { parPers.set(k, []); } parPers.get(k).push(s); });
    const lignesQui = [...parPers.entries()].map(([k, L]) => {
      const nouv = L.some(s => s.id === 0), abs = L.some(s => s.abs);
      return `<div class="eqp-fo-l${nouv ? ' maq-nouv' : ''}"><span>${esc(nomDe(L[0]))}</span><div class="eqp-fo-p">${ouv}${L.map(s => `<span class="eqp-fo-b${s.abs ? ' maq-b-abs' : ''}${s.id === 0 ? ' maq-b-new' : ''}" style="left:${X(s.de)};width:${W(s.de, s.a)}">${hh(s.de)}–${hh(s.a)}${s.abs ? ' · ' + esc(MOTIFS[s.abs.motif].case.toLowerCase()) : s.id === 0 ? ' · remplace ' + esc(prenom(e)) : ''}</span>`).join('')}</div></div>`;
    }).join('');
    const cpC = choisi.C.lin.find(l => l.comptoir), cp0 = C0.lin.find(l => l.comptoir), comble = cp0.trous.flatMap(([x, y]) => trous(cpC.trous, x, y));
    const ligneCpt = `<div class="eqp-fo-l cpt"><span>Comptoir</span><div class="eqp-fo-p">${ouv}${cpC.cv.map(([x, y]) => `<span class="eqp-fo-b cv" style="left:${X(x)};width:${W(x, y)}"></span>`).join('')}${comble.map(([x, y]) => `<span class="eqp-fo-b comble" style="left:${X(x)};width:${W(x, y)}">comblé</span>`).join('')}${cpC.trous.map(([x, y]) => `<span class="eqp-fo-b trou" style="left:${X(x)};width:${W(x, y)}"></span>`).join('')}</div></div>`;
    const semAp = (semCout + choisi.dC) / semCa * 100, semAv = semCout / semCa * 100;
    const coutPeriode = semCJ.reduce((y, C) => y + C.sv.filter(s => s.employe === emp && s.abs && MOTIFS[s.abs.motif].paye).reduce((z, s) => z + (s.a - s.de) / 60 * e.taux, 0), 0);
    const rempsAv = SC.remplacements.filter(r => r.remplace === emp).reduce((x, r) => { const C = semCJ.find(c => c.J.date === r.date), s = C.sv.find(y => y.employe === r.emp); return x + (s.a - s.de) / 60 * eq.get(r.emp).taux; }, 0);
    const html = tete(e, f, `${esc((e.postes || []).map(p => p.nom).join(', '))} · ${esc(dLong(date))} · ${hh(s0.de)}–${hh(s0.a)} au planning`)
      + `<div class="eqp-fo-sec maq-q"><span class="maq-ql">${esc(prenom(e))} vient-elle ?</span><div class="seg maq-seg" role="group"><button type="button">Elle travaille</button><button type="button" class="is-on">Absente</button></div>
          <span class="maq-qn">Le service n’est plus une présence : le comptoir et les linéaires ne la comptent plus. ${CONTRAT_PAYE[f && f.contrat] ? `<b>Contrat de travail : ses heures restent payées</b> et restent au budget, sauf absence non payée.` : 'Sans contrat de travail, une absence ne coûte rien.'}</span></div>
        <div class="eqp-fo-sec"><div class="eqp-opt-g">Pourquoi</div><div class="maq-motifs">${motifs}</div></div>
        <div class="eqp-fo-sec maq-per"><div class="eqp-opt-g">Pendant combien de temps</div>
          <div class="maq-per-l"><label class="maq-d">Du <span class="input">${esc(dCourt(a.du))} nov.</span></label><label class="maq-d">au <span class="input">${esc(dCourt(a.au))} nov.</span></label>
            <label class="maq-cb"><span class="maq-box on">✓</span>Certificat médical reçu</label><span class="maq-per-n">${fE(coutPeriode)} payés sur la période (${fH(coutPeriode / e.taux)})</span></div>
          <div class="maq-pjs">${periode.join('')}</div></div>
        <div class="eqp-fo-sec maq-rem"><div class="eqp-opt-g">Remplacer ${esc(prenom(e))} ${esc(jour(date))} · ${hh(s0.de)}–${hh(s0.a)} <span>chacun avec ce qu’il ajoute ; ${esc(prenom(e))} reste payée</span></div><div class="maq-cands">${res.map(carte).join('')}</div></div>
        <div class="eqp-fo-plan"><div class="eqp-fo-axe">${axe}</div>${lignesQui}${ligneCpt}</div>
        <div class="eqp-fo-effs">
          <div class="${choisi.trous.length ? 'ko' : 'ok'}"><span>Comptoir</span><b>${choisi.trous.length ? 'vide de ' + choisi.trous.map(([x, y]) => hcl(x) + ' à ' + hcl(y)).join(', ') : '<i class="eqp-fo-ok">✓</i>tenu toute l’ouverture'}</b><em>${cp0.trous.length ? 'sans remplaçant : vide de ' + cp0.trous.map(([x, y]) => hcl(x) + ' à ' + hcl(y)).join(', ') : ''}</em></div>
          <div><span>Budget du ${esc(jour(date))}</span><b>${marq(P, C0.pct)} → ${marq(P, choisi.pct)}</b><em>${sE(choisi.dC)} · dont ${fPt(C0.coutAbs / C0.ca * 100)} d’absence payée</em></div>
          <div><span>Budget de la semaine</span><b>${marq(P, semAv)} → ${marq(P, semAp)}</b><em>${fP((semCout + choisi.dC - semCJ.reduce((x, C) => x + C.coutAbs, 0)) / semCa * 100)} sans les absences</em></div>
          <div><span>L’absence de ${esc(prenom(e))}</span><b class="eqp-num">${fE(coutPeriode + rempsAv + choisi.dC)}</b><em>${fE(coutPeriode)} payés à ${esc(prenom(e))} + ${fE(rempsAv + choisi.dC)} de remplacements</em></div></div>
        <div class="eqp-fo-pied"><span class="eqp-fo-res"><b>${esc(prenom(e))}</b> · ${esc(MOTIFS[a.motif].court.toLowerCase())} du ${esc(dCourt(a.du))} au ${esc(dCourt(a.au))} · ${esc(choisi.emp ? prenom(choisi.emp) : 'personne')} la remplace ${esc(j3(date))} ${hh(choisi.extra.de)}–${hh(choisi.extra.a)} · ${sE(choisi.dC)}</span>
          <button type="button" class="btn btn--ghost">Annuler</button><button type="button" class="btn btn--primary">Enregistrer l’absence et le remplacement</button></div>`;
    fenetre(html, true);
    return { avant: fP(C0.pct), apres: fP(choisi.pct), choisi: choisi.nom, dC: fE2(choisi.dC), semaine: fP(semAv) + ' → ' + fP(semAp), cands: res.map(c => c.nom + ' ' + (c.extra ? sE(c.dC) : '0') + ' ' + fP(c.pct)).join(' | ') };
  }

  /* ── 3. Un congé sur une période : chaque jour de travail devient payé, un remplaçant proposé pour chacun ── */
  async function conge(emp, du, au) {
    const n = Math.round((dt(au) - dt(du)) / 864e5) + 1, P = await lire(du, n), eq = new Map(P.employes.map(e => [e.id, e])), e = eq.get(emp), f = await lireFiche(emp);
    SC.absences.push({ emp, du, au, motif: 'cp' });
    const CJ = P.jours.map(J => jourCalc(P, J));
    const lignesJ = [], repos = []; let hT = 0, cT = 0, rT = 0, dT = 0;
    const choix = {};
    CJ.forEach(C => {
      const s = C.sv.find(x => x.employe === emp);
      if (!s) { repos.push(j3(C.J.date)); return; }
      const h = (s.a - s.de) / 60, c = h * e.taux; hT += h; cT += c;
      const occ = new Set(C.sv.map(x => x.employe)), libres = P.employes.filter(x => x.actif !== false && x.id !== emp && !occ.has(x.id) && auComptoir(x)).sort((x, y) => x.taux - y.taux);
      const r = libres[0], Ca = r ? jourCalc(P, C.J, { employe: r.id, de: s.de, a: s.a, taux: r.taux }) : C, dC = Ca.cout - C.cout; rT += dC; dT++;
      choix[C.J.date] = { r, s };
      const C00 = jourCalc(P, C.J); // le jour tel qu'au planning, le congé posé (le coût reste)
      lignesJ.push(`<tr><td><b>${esc(Jour(C.J.date))} ${dt(C.J.date).getDate()}</b></td><td class="mu">${hh(s.de)}–${hh(s.a)}</td><td><span class="maq-cp">Congé payé</span></td><td class="num">${fH(h)}</td><td class="num">${fE2(c)}</td>
        <td><span class="maq-sel">${r ? esc(r.nom) + ' · ' + hh(s.de) + '–' + hh(s.a) : '— personne de libre'}<i>▾</i></span><span class="sub">${r ? esc(r.postes.map(p => p.nom).slice(0, 1).join('')) + ' · ' + fT(r.taux) + ' · le moins cher des libres' : ''}</span></td>
        <td class="num">${r ? sE(dC) : '—'}</td><td>${C00.ca ? `${marq(P, C00.pct)} → ${marq(P, Ca.pct)}` : '—'}</td></tr>`);
    });
    const tot0 = CJ.reduce((a, C) => a + C.cout, 0), ca = CJ.reduce((a, C) => a + (C.ca || 0), 0), abs = CJ.reduce((a, C) => a + C.coutAbs, 0);
    // La semaine telle qu'avant le congé (le service au planning) : le même coût ; après les remplacements : + rT.
    const SEG = { maladie: 'Maladie', cp: 'Congé payé', circ: 'Circonstance', nonpaye: 'Non payée' };
    const motifs = Object.keys(MOTIFS).map(k => `<button type="button" class="${k === 'cp' ? 'is-on' : ''}">${SEG[k]}</button>`).join('');
    const html = `<div class="eqp-fo-tete"><span class="eqp-av">${esc(ini(e.nom))}</span><div><b>Absence ou congé</b><span class="sub">Mon équipe › Planning · ou depuis la fiche de la personne</span></div><button type="button" class="eqp-fo-x" aria-label="Fermer">×</button></div>
      <div class="eqp-fo-sec maq-cg">
        <label class="maq-d maq-d-p">Qui <span class="input">${esc(e.nom)} <i>▾</i></span><small>${esc((e.fonctions || []).join(', '))} · ${f && f.contrat === 'employment_contract' ? 'contrat de travail' : 'contrat'} · ${fT(e.taux)}</small></label>
        <div class="maq-d"><span class="maq-dl">Quoi</span><div class="seg maq-seg">${motifs}</div></div>
        <div class="maq-d"><span class="maq-dl">Quand</span><span class="maq-dates"><span class="input">${esc(j3(du))} ${dt(du).getDate()} nov.</span>au<span class="input">${esc(j3(au))} ${dt(au).getDate()} nov.</span></span></div></div>
      <div class="eqp-fo-sec"><p class="maq-cg-n"><b>Contrat de travail : les jours de congé restent payés.</b> Chaque jour où ${esc(prenom(e))} travaillait devient « Congé payé » : ses heures restent au budget, elle ne compte plus au comptoir. Ses jours de repos (${esc(repos.join(', '))}) ne changent rien.</p>
        <div class="eqp-table maq-cg-t"><table><thead><tr><th>Jour</th><th>Son service</th><th>Devient</th><th class="num">Payé</th><th class="num">Coût qui reste</th><th>Remplacer par</th><th class="num">Ajout</th><th>Budget du jour</th></tr></thead>
          <tbody>${lignesJ.join('')}<tr class="tot"><td colspan="3">${dT} jour${dT > 1 ? 's' : ''} de congé payé</td><td class="num">${fH(hT)}</td><td class="num">${fE2(cT)}</td><td>${dT} remplacement${dT > 1 ? 's' : ''}</td><td class="num">${sE(rT)}</td><td></td></tr></tbody></table></div></div>
      <div class="eqp-fo-effs">
        <div><span>Le congé de ${esc(prenom(e))}</span><b class="eqp-num">${fE(cT)}</b><em>${fH(hT)} payées sans présence</em></div>
        <div><span>Les remplacements</span><b class="eqp-num">${sE(rT)}</b><em>${dT} service${dT > 1 ? 's' : ''} aux mêmes heures</em></div>
        <div><span>Budget de la semaine</span><b>${marq(P, tot0 / ca * 100)} → ${marq(P, (tot0 + rT) / ca * 100)}</b><em>${fP((tot0 + rT - abs) / ca * 100)} sans le congé</em></div>
        <div><span>Congés payés 2026</span><b class="eqp-num">8 j posés au planning</b><em>le solde légal : <span class="tag-warn">secrétariat social, à relier</span></em></div></div>
      <div class="eqp-fo-pied"><span class="eqp-fo-res"><b>${esc(prenom(e))}</b> · congé payé du ${esc(j3(du))} ${dt(du).getDate()} au ${esc(j3(au))} ${dt(au).getDate()} nov. · ${dT} jours, ${fH(hT)} payées · ${dT} remplacements ${sE(rT)}</span>
        <button type="button" class="btn btn--ghost">Annuler</button><button type="button" class="btn btn--primary">Poser le congé et ${dT} remplacement${dT > 1 ? 's' : ''}</button></div>`;
    fenetre(html, true);
    return { jours: dT, heures: fH(hT), cout: fE2(cT), remps: sE(rT), semaine: fP(tot0 / ca * 100) + ' → ' + fP((tot0 + rT) / ca * 100), choix: Object.entries(choix).map(([d, x]) => d + ' ' + (x.r ? x.r.nom : '—')).join(' | ') };
  }

  window.MAQ = { semaine, absence, conge, fermer };
})();
