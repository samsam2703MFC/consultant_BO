/* Module Formation › Examens théoriques des candidats franchisés (26/11/2026, maquette du CRM
 * docs/maquettes/examens-theoriques/).
 *
 *   #/rfexamens                 les thèmes (questions validées, brouillons, à revoir, tirées, seuil, bloquantes, actif)
 *   #/rfexamens/questions[/T]   la banque de questions (filtre thème T, puces de statut, cases + « Valider les N »)
 *   #/rfexamens/q/<id>          une question : énoncé FR | NL, type, réponses, explication, source, bloquante ; à revoir :
 *                               la source avant / après
 *   #/rfexamens/nouvelle[/T]    une question nouvelle (brouillon)
 *   #/rfexamens/releve          la relecture mensuelle (la routine) : dernière relecture, à qui part le rapport, « Relire »
 * Routes : formation/src/examens.php. Styles : examens.css. Branché sur window.FormationSop comme continue.js.
 */
(function (FS) {
  'use strict';
  if (!FS) { return; }
  let C = null;
  const esc = v => (C ? C.esc(v) : String(v == null ? '' : v));
  const dfr = s => s ? String(s).slice(8, 10) + '/' + String(s).slice(5, 7) + '/' + String(s).slice(0, 4) : '—';
  const ST = { brouillon: ['Brouillon', 'br'], validee: ['Validée', 'ok'], a_revoir: ['À revoir', 'rv'], ecartee: ['Écartée', 'ec'] };
  const TY = { qcm: 'QCM · 1 bonne', vf: 'Vrai / faux', multi: 'Plusieurs bonnes', situation: 'Mise en situation' };
  const SRC = { contrat: 'Contrat de franchise', sop: 'Standard (SOP)', fidelite: 'Programme de fidélité', guide: 'Guide', autre: 'Autre' };
  const L = { d: null, err: null, charge: false, st: '', coches: new Set(), ed: null, edId: null, lang: 'fr', occupe: false, msg: null, rapportA: null };

  function charger(force) {
    if ((L.d || L.charge) && !force) { return; }
    L.charge = true;
    C.lire('/formation/examens', 60000).then(d => { L.d = d; L.err = null; if (L.rapportA === null) { L.rapportA = d.reglages.rapportA; } }, e => { L.err = e.message; })
      .finally(() => { L.charge = false; C.rendre(true); });
  }
  async function agir(m, p, corps, ok) {
    if (L.occupe) { return null; }
    L.occupe = true; L.msg = null; C.rendre(true);
    try { const r = await C.appel(m, p, corps); L.msg = { ok: true, txt: ok(r) }; charger(true); return r; }
    catch (e) { L.msg = { ok: false, txt: e.message }; return null; }
    finally { L.occupe = false; C.rendre(true); }
  }
  const msg = () => L.msg ? `<div class="exm-msg ${L.msg.ok ? 'ok' : 'ko'}">${esc(L.msg.txt)}</div>` : '';
  const st = s => `<span class="exm-st ${ST[s] ? ST[s][1] : ''}">${esc(ST[s] ? ST[s][0] : s)}</span>`;
  const theme = c => (L.d.themes.find(t => t.code === c) || { nom: { fr: c } }).nom.fr;
  const onglets = cur => {
    const rv = L.d.questions.filter(q => q.statut === 'a_revoir').length;
    return `<div class="exm-ong"><span class="seg">${[['', 'Thèmes'], ['questions', 'Banque de questions'], ['revoir', 'À revoir' + (rv ? ' ' + rv : '')], ['releve', 'Relecture mensuelle']]
      .map(([k, l]) => `<button class="${cur === k ? 'is-on' : ''}" data-exm="aller" data-v="${k}">${esc(l)}</button>`).join('')}</span><span style="flex:1"></span>
      <a class="btn" href="#/rfexamens/nouvelle">＋ Question</a></div>`;
  };

  function themes() {
    const D = L.d, E = D.examen;
    return `${msg()}${onglets('')}<div class="exm-kpis">
        <div class="exm-kpi"><small>Questions validées</small><b>${D.questions.filter(q => q.statut === 'validee').length}</b></div>
        <div class="exm-kpi"><small>Brouillons à valider</small><b>${D.questions.filter(q => q.statut === 'brouillon').length}</b></div>
        <div class="exm-kpi"><small>À revoir (source changée)</small><b class="${D.questions.some(q => q.statut === 'a_revoir') ? 'or' : ''}">${D.questions.filter(q => q.statut === 'a_revoir').length}</b></div>
        <div class="exm-kpi"><small>Examen final</small><b>${E.questions}</b><small>questions · ${E.themes} thèmes</small></div>
        ${D.candidats ? `<div class="exm-kpi"><small>Candidats</small><b>${D.candidats.reussis} / ${D.candidats.dossiers}</b><small>examen réussi</small></div>` : ''}</div>
      ${E.manque.length ? `<div class="exm-msg av">Pas assez de questions validées pour tirer l’examen complet : ${E.manque.map(c => esc(theme(c))).join(', ')}. Un thème sans question validée n’est pas encore dans l’examen ; les autres tirent ce qu’ils ont.</div>` : ''}
      <section class="card exm-t"><table><thead><tr><th>Thème</th><th class="n">Validées</th><th class="n">Brouillons</th><th class="c">À revoir</th><th class="n">Tirées</th><th class="n">Seuil</th><th class="c">Bloquantes</th><th class="c">Actif</th></tr></thead><tbody>
      ${D.themes.map(t => `<tr><td><a href="#/rfexamens/questions/${t.code}">${esc(t.nom.fr)}</a><small>${esc(t.code)}${t.nom.nl ? ' · ' + esc(t.nom.nl) : ''}</small></td>
        <td class="n">${t.validees}</td><td class="n">${t.brouillons}</td><td class="c">${t.aRevoir ? `<span class="exm-st rv">${t.aRevoir} à revoir</span>` : '—'}</td>
        <td class="n"><input class="input exm-n" type="number" min="1" max="40" value="${t.tirees}" data-exm-th="${t.code}" data-k="tirees"></td>
        <td class="n"><input class="input exm-n" type="number" min="50" max="100" value="${t.seuil}" data-exm-th="${t.code}" data-k="seuil"> %</td>
        <td class="c">${t.bloquantes ? `<span class="exm-st bq">${t.bloquantes} · 100 %</span>` : '—'}</td>
        <td class="c"><input type="checkbox" ${t.actif ? 'checked' : ''} data-exm-th="${t.code}" data-k="actif"></td></tr>`).join('')}
      </tbody></table><p class="exm-note">Tirées : questions par thème dans un examen, au hasard parmi les validées. Une question bloquante ratée fait échouer le thème, quel que soit le score. Deux essais ; une question « à revoir » n’est jamais posée.</p></section>`;
  }

  function questions(filtreTheme, revoir) {
    const D = L.d;
    const base = D.questions.filter(q => (!filtreTheme || q.theme === filtreTheme) && (revoir ? q.statut === 'a_revoir' : (L.st ? q.statut === L.st : q.statut !== 'ecartee')));
    const n = k => D.questions.filter(q => (!filtreTheme || q.theme === filtreTheme) && (k ? q.statut === k : q.statut !== 'ecartee')).length;
    const coches = base.filter(q => L.coches.has(q.id) && q.statut !== 'validee');
    return `${msg()}${onglets(revoir ? 'revoir' : 'questions')}
      ${revoir ? '' : `<div class="exm-filtres"><select class="input" data-exm="theme"><option value="">Tous les thèmes</option>${D.themes.map(t => `<option value="${t.code}"${filtreTheme === t.code ? ' selected' : ''}>${esc(t.nom.fr)}</option>`).join('')}</select>
        <span class="seg">${[['', 'Toutes'], ['brouillon', 'Brouillons'], ['validee', 'Validées'], ['a_revoir', 'À revoir'], ['ecartee', 'Écartées']].map(([k, l]) => `<button class="${L.st === k ? 'is-on' : ''}" data-exm="st" data-v="${k}">${l} <i>${n(k)}</i></button>`).join('')}</span>
        <span style="flex:1"></span>${coches.length ? `<button class="btn btn--primary" data-exm="valider-coches"${L.occupe ? ' disabled' : ''}>Valider les ${coches.length} questions cochées</button>` : ''}</div>`}
      <section class="card exm-t"><table><thead><tr><th class="cb"></th><th class="cd">Code</th><th>Question</th><th class="ty">Type</th><th class="so">Source</th><th class="c">Statut</th><th class="n">Posée</th></tr></thead><tbody>
      ${base.map(q => `<tr class="exm-l" data-exm="ouvrir" data-v="${q.id}"><td class="cb">${q.statut === 'validee' ? '' : `<input type="checkbox" data-exm-c="${q.id}"${L.coches.has(q.id) ? ' checked' : ''}>`}</td>
        <td class="cd">${esc(q.code)}</td><td>${esc(q.enonce.fr)}${q.bloquante ? ' <span class="exm-st bq">bloquante</span>' : ''}<small>${esc(theme(q.theme))}${q.revoir ? ' · ' + esc(q.revoir.raison) : ''}${!q.enonce.nl ? ' · NL à traduire' : ''}</small></td>
        <td class="ty">${esc(TY[q.type] || q.type)}</td><td class="so">${esc(q.source.lib || SRC[q.source.type] || '—')}</td><td class="c">${st(q.statut)}</td>
        <td class="n">${q.posee == null ? '—' : (q.posee ? Math.round(100 * q.reussie / q.posee) + ' % · ' + q.posee : '0')}</td></tr>`).join('') || '<tr><td colspan="7" class="exm-vide">Aucune question ici.</td></tr>'}
      </tbody></table></section>`;
  }

  function editeur(id, themeDefaut) {
    const D = L.d;
    if (L.edId !== (id || 'n')) {
      const q = id ? D.questions.find(x => x.id === id) : null;
      if (id && !q) { return `${onglets('questions')}<div class="exm-msg ko">Question introuvable.</div>`; }
      L.ed = q ? JSON.parse(JSON.stringify(q)) : { theme: themeDefaut || D.themes[0].code, type: 'qcm', enonce: { fr: '', nl: '' }, choix: [{ fr: '', nl: '', bon: true }, { fr: '', nl: '', bon: false }, { fr: '', nl: '', bon: false }, { fr: '', nl: '', bon: false }],
        explication: { fr: '', nl: '' }, source: { type: 'contrat', ref: '', lib: '' }, bloquante: false, statut: 'brouillon' };
      L.edId = id || 'n';
    }
    const q = L.ed, l = L.lang, unique = q.type !== 'multi';
    const rv = q.revoir ? `<div class="exm-msg av"><b>${esc(q.revoir.raison)}.</b> La question n’est plus posée tant qu’elle n’est pas revalidée.
        <div class="exm-diff"><div><small>La source avant</small><pre>${esc(q.revoir.avant || '—')}</pre></div><div><small>La source maintenant</small><pre>${esc(q.revoir.apres || '—')}</pre></div></div></div>` : '';
    const boutons = !id ? `<button class="btn btn--primary" data-exm="creer"${L.occupe ? ' disabled' : ''}>Créer (brouillon)</button>`
      : `<button class="btn" data-exm="enregistrer"${L.occupe ? ' disabled' : ''}>Enregistrer</button>${q.statut !== 'validee' ? `<button class="btn btn--primary" data-exm="statut" data-v="validee"${L.occupe ? ' disabled' : ''}>Valider la question</button>` : ''}
        ${q.statut !== 'ecartee' ? `<button class="btn btn--ghost" data-exm="statut" data-v="ecartee">Écarter</button>` : `<button class="btn" data-exm="statut" data-v="brouillon">Remettre en brouillon</button>`}
        ${q.statut === 'brouillon' || q.statut === 'ecartee' ? `<button class="btn btn--danger" data-exm="supprimer">Supprimer</button>` : ''}`;
    return `${msg()}${onglets('questions')}<div class="exm-eh"><a class="exm-lien" href="#/rfexamens/questions/${esc(q.theme)}">← ${esc(theme(q.theme))}</a><b>${esc(q.code || 'Nouvelle question')}</b>${id ? st(q.statut) : ''}<span style="flex:1"></span>
        <span class="seg">${['fr', 'nl'].map(x => `<button class="${l === x ? 'is-on' : ''}" data-exm="lang" data-v="${x}">${x.toUpperCase()}</button>`).join('')}</span>${boutons}</div>${rv}
      <div class="exm-ed"><section class="card"><label class="exm-lb">Question${l === 'nl' ? ' (néerlandais)' : ''}</label><textarea class="input" rows="2" data-exm-f="enonce">${esc(q.enonce[l] || '')}</textarea>
          <label class="exm-lb">Réponses · ${unique ? 'une seule bonne' : 'plusieurs bonnes possibles'}</label>
          ${q.choix.map((c, i) => `<div class="exm-ch${c.bon ? ' bon' : ''}"><input type="${unique ? 'radio' : 'checkbox'}" name="exm-bon" ${c.bon ? 'checked' : ''} data-exm-bon="${i}" title="Bonne réponse"><input class="input" data-exm-ch="${i}" value="${esc(c[l] || '')}" placeholder="${l === 'nl' ? esc(c.fr) : 'Réponse ' + (i + 1)}">${q.choix.length > 2 ? `<button class="btn btn--icon" data-exm="retirer" data-v="${i}" title="Retirer">✕</button>` : ''}</div>`).join('')}
          ${q.choix.length < 6 && q.type !== 'vf' ? '<button class="btn btn--sm" data-exm="ajouter">＋ Une réponse</button>' : ''}
          <label class="exm-lb">Après correction, le candidat lit</label><textarea class="input" rows="2" data-exm-f="explication">${esc(q.explication[l] || '')}</textarea></section>
        <section class="card"><label class="exm-lb">Thème</label><select class="input" data-exm-p="theme">${D.themes.map(t => `<option value="${t.code}"${q.theme === t.code ? ' selected' : ''}>${esc(t.nom.fr)}</option>`).join('')}</select>
          <label class="exm-lb">Type</label><select class="input" data-exm-p="type">${Object.keys(TY).map(k => `<option value="${k}"${q.type === k ? ' selected' : ''}>${esc(TY[k])}</option>`).join('')}</select>
          <label class="exm-lb">Source</label><select class="input" data-exm-s="type">${Object.keys(SRC).map(k => `<option value="${k}"${q.source.type === k ? ' selected' : ''}>${esc(SRC[k])}</option>`).join('')}</select>
          <input class="input" data-exm-s="ref" value="${esc(q.source.ref)}" placeholder="${q.source.type === 'contrat' ? 'article, ex. 8.2.3' : (q.source.type === 'sop' ? 'code du standard, ex. AC-01' : 'référence')}">
          <input class="input" data-exm-s="lib" value="${esc(q.source.lib)}" placeholder="libellé montré au candidat, ex. Contrat, art. 8.2.3">
          <p class="exm-note">${q.source.type === 'contrat' || q.source.type === 'sop' || q.source.type === 'fidelite' ? 'Source suivie : si son texte change, la question passe « à revoir ».' : 'Source non suivie : la relecture ne la signale pas.'}</p>
          <label class="exm-cb"><input type="checkbox" data-exm-p="bloquante" ${q.bloquante ? 'checked' : ''}> Bloquante : ratée, le thème est raté</label>
          ${id ? `<p class="exm-note">Créée le ${esc(dfr(q.creeLe))} par ${esc(q.creePar || '—')}${q.valideLe ? ` · validée le ${esc(dfr(q.valideLe))} par ${esc(q.validePar)}` : ''} · version ${q.version}${q.posee != null ? ` · posée ${q.posee} fois, réussie ${q.reussie} fois` : ''}.</p>` : ''}</section></div>`;
  }

  function releve() {
    const D = L.d, R = D.releve, det = R && R.detail ? R.detail : {};
    return `${msg()}${onglets('releve')}<section class="card exm-c"><div class="exm-eh"><b>${R ? 'Dernière relecture le ' + esc(dfr(R.le)) + ' à ' + esc(R.le.slice(11, 16)) : 'Pas encore de relecture'}</b>${R ? `<span class="exm-note">${esc(R.par)}</span>` : ''}<span style="flex:1"></span>
        <button class="btn btn--primary" data-exm="relire"${L.occupe ? ' disabled' : ''}>${L.occupe ? 'Relecture…' : 'Relire maintenant'}</button></div>
        <p class="exm-note">Le 1er de chaque mois, les sources sont relues (articles du contrat avec les conditions du réseau, standards, programme de fidélité). Une question dont la source a changé passe « à revoir » ; des questions nouvelles sont proposées en brouillon. Rien n’entre dans un examen sans validation.</p>
        ${R && R.erreur ? `<div class="exm-msg av">${esc(R.erreur)}</div>` : ''}${det.contratErreur ? `<div class="exm-msg av">Contrat : ${esc(det.contratErreur)}.</div>` : ''}
        ${R ? `<div class="exm-kpis"><div class="exm-kpi"><small>Passées à revoir</small><b>${R.aRevoir}</b></div><div class="exm-kpi"><small>Proposées</small><b>${R.propositions}</b></div><div class="exm-kpi"><small>Sources illisibles</small><b>${det.nonLues || 0}</b></div></div>` : ''}
        ${(det.revoir || []).length ? `<h3>À revoir</h3><table><tbody>${det.revoir.map(q => `<tr class="exm-l" data-exm="ouvrir" data-v="${q.id}"><td class="cd">${esc(q.code)}</td><td>${esc(q.enonce)}<small>${esc(q.raison)}</small></td></tr>`).join('')}</tbody></table>` : ''}
        ${(det.propositions || []).length ? `<h3>Proposées (brouillons)</h3><table><tbody>${det.propositions.map(q => `<tr><td class="cd">${esc(q.code)}</td><td>${esc(q.enonce)}<small>${esc(theme(q.theme))} · ${esc(q.source || '')}</small></td></tr>`).join('')}</tbody></table>` : ''}
        <label class="exm-lb">Le rapport part à</label><div class="exm-fl"><input class="input" data-exm="rapportA" value="${esc(L.rapportA || '')}" placeholder="adresse, adresse"><button class="btn" data-exm="reglages">Enregistrer</button></div>
        ${R && R.envoyeA ? `<p class="exm-note">Dernier rapport envoyé à ${esc(R.envoyeA)}.</p>` : ''}</section>`;
  }

  function ecran() {
    const a = C.S.route.a;
    charger(false);
    let corps;
    if (L.err) { corps = `<div class="exm-msg ko">Les examens ne se lisent pas : ${esc(L.err)}</div>`; }
    else if (!L.d) { corps = '<p class="exm-note">Lecture des examens…</p>'; }
    else if (a[0] === 'questions') { corps = questions(a[1] || ''); }
    else if (a[0] === 'revoir') { corps = questions('', true); }
    else if (a[0] === 'releve') { corps = releve(); }
    else if (a[0] === 'q' && /^\d+$/.test(a[1] || '')) { corps = editeur(+a[1]); }
    else if (a[0] === 'nouvelle') { corps = editeur(null, a[1]); }
    else { corps = themes(); }
    if (!(a[0] === 'q' || a[0] === 'nouvelle')) { L.edId = null; }
    return { titre: 'Examens théoriques', kicker: 'Formations · candidats franchisés', corps: `<div class="exm">${corps}</div>` };
  }

  function corpsQuestion() {
    const q = L.ed;
    return { theme: q.theme, type: q.type, enonce: q.enonce, choix: q.choix, explication: q.explication, source: q.source, bloquante: q.bloquante };
  }

  function brancher(root) {
    root.addEventListener('click', ev => {
      const cb = ev.target.closest && ev.target.closest('[data-exm-c]');
      if (cb) { ev.stopPropagation(); return; }
      const b = ev.target.closest && ev.target.closest('[data-exm]');
      if (!b || !root.querySelector('.exm') || !root.querySelector('.exm').contains(b) || b.tagName === 'INPUT' || b.tagName === 'SELECT') { return; }
      const d = b.dataset;
      switch (d.exm) {
        case 'aller': location.hash = '#/rfexamens' + (d.v ? '/' + d.v : ''); return;
        case 'st': L.st = d.v; return C.rendre(true);
        case 'lang': L.lang = d.v; return C.rendre(true);
        case 'ouvrir': location.hash = '#/rfexamens/q/' + d.v; return;
        case 'ajouter': L.ed.choix.push({ fr: '', nl: '', bon: false }); return C.rendre(true);
        case 'retirer': L.ed.choix.splice(+d.v, 1); if (!L.ed.choix.some(c => c.bon)) { L.ed.choix[0].bon = true; } return C.rendre(true);
        case 'creer': agir('POST', '/formation/examens/questions', corpsQuestion(), r => r.code + ' créée en brouillon.').then(r => { if (r && r.id) { L.edId = null; location.hash = '#/rfexamens/q/' + r.id; } }); return;
        case 'enregistrer': agir('PUT', '/formation/examens/questions/' + L.ed.id, corpsQuestion(), () => 'Enregistré.').then(r => { if (r) { L.edId = null; } }); return;
        case 'statut': {
          const id = L.ed.id;
          C.appel('PUT', '/formation/examens/questions/' + id, corpsQuestion()).then(() => agir('POST', '/formation/examens/questions/' + id + '/statut', { statut: d.v },
            r => r.refus && r.refus.length ? 'Pas validée : ' + r.refus[0].raison + '.' : ({ validee: 'Validée : elle peut être posée.', ecartee: 'Écartée.', brouillon: 'Remise en brouillon.' })[d.v]))
            .then(() => { L.edId = null; }, e => { L.msg = { ok: false, txt: e.message }; C.rendre(true); });
          return;
        }
        case 'supprimer': agir('DELETE', '/formation/examens/questions/' + L.ed.id, null, () => 'Supprimée.').then(r => { if (r) { L.edId = null; location.hash = '#/rfexamens/questions'; } }); return;
        case 'valider-coches': agir('POST', '/formation/examens/valider', { ids: [...L.coches] }, r => r.ok.length + ' validée(s)' + (r.refus.length ? ' ; ' + r.refus.length + ' refusée(s) : ' + r.refus.map(x => x.code + ' (' + x.raison + ')').join(', ') : '') + '.').then(() => L.coches.clear()); return;
        case 'relire': agir('POST', '/formation/examens/releve', { proposer: true }, r => `Relu : ${r.aRevoir} à revoir, ${r.propositions} proposée(s)${r.envoyeA ? ', rapport envoyé à ' + r.envoyeA : ''}.`); return;
        case 'reglages': agir('PUT', '/formation/examens/reglages', { rapportA: L.rapportA || '' }, r => 'Le rapport partira à ' + (r.rapportA || 'personne') + '.'); return;
        default: return;
      }
    });
    root.addEventListener('input', ev => {
      const t = ev.target;
      if (!L.ed && !t.dataset.exm) { return; }
      if (t.dataset.exm === 'rapportA') { L.rapportA = t.value; return; }
      if (!L.ed) { return; }
      if (t.dataset.exmF) { L.ed[t.dataset.exmF][L.lang] = t.value; }
      if (t.dataset.exmCh) { L.ed.choix[+t.dataset.exmCh][L.lang] = t.value; }
      if (t.dataset.exmS && t.tagName === 'INPUT') { L.ed.source[t.dataset.exmS] = t.value; }
    });
    root.addEventListener('change', ev => {
      const t = ev.target;
      if (t.dataset.exmC) { const id = +t.dataset.exmC; if (t.checked) { L.coches.add(id); } else { L.coches.delete(id); } return C.rendre(true); }
      if (t.dataset.exmTh) {
        const corps = { [t.dataset.k]: t.type === 'checkbox' ? t.checked : +t.value };
        return void agir('PUT', '/formation/examens/themes/' + t.dataset.exmTh, corps, () => 'Thème enregistré.');
      }
      if (t.dataset.exm === 'theme') { location.hash = '#/rfexamens/questions' + (t.value ? '/' + t.value : ''); return; }
      if (!L.ed) { return; }
      if (t.dataset.exmBon != null) {
        const i = +t.dataset.exmBon;
        if (L.ed.type === 'multi') { L.ed.choix[i].bon = t.checked; } else { L.ed.choix.forEach((c, k) => { c.bon = k === i; }); }
        return C.rendre(true);
      }
      if (t.dataset.exmP === 'bloquante') { L.ed.bloquante = t.checked; return; }
      if (t.dataset.exmP) {
        L.ed[t.dataset.exmP] = t.value;
        if (t.dataset.exmP === 'type' && t.value === 'vf') { L.ed.choix = [{ fr: 'Vrai', nl: 'Waar', bon: true }, { fr: 'Faux', nl: 'Onwaar', bon: false }]; }
        if (t.dataset.exmP === 'type' && t.value !== 'multi' && L.ed.choix.filter(c => c.bon).length !== 1) { L.ed.choix.forEach((c, k) => { c.bon = k === Math.max(0, L.ed.choix.findIndex(x => x.bon)); }); }
        return C.rendre(true);
      }
      if (t.dataset.exmS && t.tagName === 'SELECT') { L.ed.source.type = t.value; return C.rendre(true); }
    });
  }

  FS.ecrans.rfexamens = ecran;
  FS.icones.rfexamens = 'M9 11l3 3 8-8M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11';
  const initAvant = FS.init;
  FS.init = function (ctx, root) { initAvant.call(FS, ctx, root); C = ctx; brancher(root); };
})(window.FormationSop);
