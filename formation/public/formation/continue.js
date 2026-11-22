/* Module Formation › Formation continue (22/11/2026, maquette formation/docs/maquettes/formation-continue/01).
 *
 *   #/rfcontinue          la formation initiale et la formation continue côte à côte ; les quatre catégories (technique,
 *                         connaissance, nouveau produit, nouvelle technique), les trois statuts (brouillon, à valider,
 *                         validée) ; la liste ; « ＋ Nouvelle formation continue ».
 *   #/rfcontinue/<id>     une formation continue : son statut (brouillon → à valider → validée), son contenu (rédigé dans
 *                         l'éditeur du Manuel, #/sop/<id>/editer), ses jours de photo qualité, sa diffusion aux boutiques,
 *                         l'avancement de chaque boutique et le journal.
 * Routes : GET/POST /formation/continue, GET/PATCH /formation/continue/{id}, POST /formation/continue/{id}/diffuser
 * (formation/src/formation_continue.php). Branché sur window.FormationSop comme l'assistant des SOP ; styles continue.css.
 */
(function (FS) {
  'use strict';
  if (!FS) { return; }
  let C = null;
  const esc = v => (C ? C.esc(v) : String(v == null ? '' : v));
  const dfr = s => s ? String(s).slice(8, 10) + '/' + String(s).slice(5, 7) + '/' + String(s).slice(0, 4) : '—';
  const CAT = { technique: 'tech', connaissance: 'conn', nouveau_produit: 'prod', nouvelle_technique: 'ntech' };
  const STC = { brouillon: 'brou', a_valider: 'aval', publiee: 'val' };
  const STM = { en_cours: 'brou', a_valider: 'aval', validee: 'val' };
  const L = { d: null, err: null, charge: false, fiche: null, ficheId: null, ficheErr: null, cat: '', st: '', nouv: null, diff: null, occupe: false, msg: null };
  const t = v => v && typeof v === 'object' ? (v.fr || v.nl || '') : String(v || '');

  function charger(force) {
    if ((L.d || L.charge) && !force) { return; }
    L.charge = true;
    C.lire('/formation/continue', 30000).then(d => { L.d = d; L.err = null; }, e => { L.err = e.message; }).finally(() => { L.charge = false; C.rendre(true); });
  }
  function chargerFiche(id, force) {
    if (!force && L.ficheId === id && (L.fiche || L.ficheCharge)) { return; }
    L.ficheId = id; L.ficheCharge = true; if (!force) { L.fiche = null; L.diff = null; }
    C.lire('/formation/continue/' + encodeURIComponent(id), 30000).then(f => { L.fiche = f; L.ficheErr = null; }, e => { L.ficheErr = e.message; }).finally(() => { L.ficheCharge = false; C.rendre(true); });
  }
  async function agir(m, p, corps, ok) {
    if (L.occupe) { return; }
    L.occupe = true; L.msg = null; C.rendre(true);
    try { const r = await C.appel(m, p, corps); L.msg = { ok: true, txt: ok(r) }; charger(true); if (L.ficheId) { chargerFiche(L.ficheId, true); } return r; }
    catch (e) { L.msg = { ok: false, txt: e.message }; return null; }
    finally { L.occupe = false; C.rendre(true); }
  }
  const tag = x => `<span class="fcm-tag ${CAT[x.categorie] || ''}"><i></i>${esc(x.categorieLib)}</span>`;
  const st = x => `<span class="fcm-st ${STC[x.statut] || 'brou'}">${esc(x.statutLib)}</span>`;
  const barre = p => `<span class="fcm-av"><span class="b"><i style="width:${Math.max(0, Math.min(100, p || 0))}%"></i></span><span>${p == null ? '—' : p + ' %'}</span></span>`;
  const msg = () => L.msg ? `<div class="fcm-msg ${L.msg.ok ? 'ok' : 'ko'}">${esc(L.msg.txt)}</div>` : '';

  function liste() {
    const D = L.d, T = D.types || {};
    const F = D.formations.filter(x => (!L.cat || x.categorie === L.cat) && (!L.st || x.statut === L.st));
    const n = k => D.formations.filter(x => !L.cat || x.categorie === L.cat).filter(x => !k || x.statut === k).length;
    const nouv = L.nouv ? `<section class="card fcm-nouv"><h3>Nouvelle formation continue</h3><div class="fcm-form">
        <label><span>Titre</span><input class="input" data-fcm-n="titre" value="${esc(L.nouv.titre)}" placeholder="ex. Bûche praliné-noisette — fêtes 2026"></label>
        <label><span>Catégorie</span><select class="input" data-fcm-n="categorie">${D.categories.map(c => `<option value="${c.cle}"${L.nouv.categorie === c.cle ? ' selected' : ''}>${esc(c.nom)}</option>`).join('')}</select></label>
        <label><span>Le produit (facultatif)</span><input class="input" data-fcm-n="produit" value="${esc(L.nouv.produit)}" placeholder="le produit de la fiche technique"></label>
        <label class="l2"><span>Objectif</span><input class="input" data-fcm-n="objectif" value="${esc(L.nouv.objectif)}" placeholder="ce que l’équipe saura faire"></label></div>
        <p class="fcm-n">Un nouveau produit demande 14 jours de photo qualité en magasin (réglable sur la fiche). Le contenu (étapes, photos, vidéo, quiz) s’écrit ensuite dans l’éditeur du Manuel.</p>
        <div class="fcm-bts"><button class="btn btn--primary" data-fcm="creer"${L.occupe ? ' disabled' : ''}>Créer (brouillon)</button><button class="btn" data-fcm="annuler">Annuler</button></div></section>` : '';
    return `${msg()}<div class="fcm-types"><div class="fcm-type"><b>${T.initiale || 0}</b><div><span>Formation initiale</span><small>FO de pré-ouverture et d’ouverture, inchangées</small></div><a class="fcm-lien" href="#/rfformations">Formations ›</a></div>
        <div class="fcm-type on"><b>${T.continue || 0}</b><div><span>Formation continue</span><small>${T.recyclages || 0} recyclages FO · ${T.contenus || 0} contenus · ${T.lesDeux || 0} FO dans les deux</small></div></div></div>
      <div class="fcm-cats"><button class="fcm-cat${!L.cat ? ' on' : ''}" data-fcm="cat" data-v=""><b>${D.formations.length}</b><span>Toutes</span></button>${D.categories.map(c => `<button class="fcm-cat${L.cat === c.cle ? ' on' : ''}" data-fcm="cat" data-v="${c.cle}"><b>${c.n}</b><span class="fcm-tag ${CAT[c.cle]}"><i></i>${esc(c.nom)}</span></button>`).join('')}</div>
      ${nouv}
      <section class="card fcm-liste"><div class="fcm-lh"><span class="seg">${[['', 'Tous'], ['brouillon', 'Brouillon'], ['a_valider', 'À valider'], ['publiee', 'Validée']].map(([k, l]) => `<button class="${L.st === k ? 'is-on' : ''}" data-fcm="st" data-v="${k}">${l} <i>${n(k)}</i></button>`).join('')}</span><span style="flex:1"></span>${L.nouv ? '' : '<button class="btn btn--primary" data-fcm="nouv">＋ Nouvelle formation continue</button>'}</div>
        <table class="fcm-t"><thead><tr><th>Code</th><th>Formation</th><th>Statut</th><th>Boutiques</th><th>Avancement</th><th>Diffusée</th></tr></thead><tbody>
        ${F.map(x => `<tr data-fcm="ouvrir" data-v="${esc(x.id)}"><td class="cd">${esc(x.code)}</td><td><b>${esc(x.titre)}</b><small>${tag(x)} · ${x.etapes} étape(s)${x.photosJours ? ' · ' + x.photosJours + ' jours de photo' : ''}${x.tradNl === 'a_relire' ? ' · NL à relire' : ''}</small></td>
          <td>${st(x)}</td><td>${x.boutiques ? x.validees + ' / ' + x.boutiques + ' validées' : '—'}</td><td>${x.boutiques ? barre(x.avancementMoyen) : '—'}</td><td>${x.diffuseLe ? esc(dfr(x.diffuseLe)) : '—'}</td></tr>`).join('') || '<tr><td colspan="6" class="fcm-vide">Aucune formation continue ici.</td></tr>'}
        </tbody></table></section>`;
  }

  function fiche() {
    const f = L.fiche, D = L.d;
    if (L.ficheErr) { return `<div class="fcm-msg ko">${esc(L.ficheErr)}</div><a class="fcm-lien" href="#/rfcontinue">← Formation continue</a>`; }
    if (!f) { return '<p class="fcm-n">Lecture de la formation…</p>'; }
    const etapes = (f.contenu.sections || []).flatMap(s => s.etapes || []);
    const flux = [['brouillon', '1 · Brouillon'], ['a_valider', '2 · À valider'], ['publiee', '3 · Validée']];
    const i = flux.findIndex(x => x[0] === f.statut);
    const boutons = f.statut === 'brouillon' ? `<button class="btn btn--primary" data-fcm="statut" data-v="a_valider">Envoyer à la validation</button>`
      : (f.statut === 'a_valider' ? `<button class="btn fcm-v" data-fcm="statut" data-v="publiee">✓ Valider</button><button class="btn" data-fcm="statut" data-v="brouillon">Remettre en brouillon</button>`
        : `<button class="btn" data-fcm="statut" data-v="a_valider">Repasser « à valider »</button>`);
    const deja = new Set((f.dossiers || []).map(d => d.shop));
    const diff = L.diff || { shops: (D ? D.magasins : []).filter(m => !deja.has(m.id)).map(m => m.id), echeance: '', photosDu: '' };
    L.diff = diff;
    const diffusion = f.statut !== 'publiee' ? '<p class="fcm-n">La diffusion s’ouvre quand la formation est validée.</p>'
      : `<div class="fcm-mags">${(D ? D.magasins : []).map(m => `<label class="${deja.has(m.id) ? 'deja' : ''}"><input type="checkbox" data-fcm-shop="${esc(m.id)}"${deja.has(m.id) ? ' checked disabled' : (diff.shops.includes(m.id) ? ' checked' : '')}> ${esc(m.nom)}${deja.has(m.id) ? ' <small>reçue</small>' : ''}</label>`).join('')}</div>
        <div class="fcm-form"><label><span>À faire avant</span><input class="input" type="date" data-fcm-d="echeance" value="${esc(diff.echeance)}"></label>${f.photosJours ? `<label><span>Photos à partir du (mise en vente)</span><input class="input" type="date" data-fcm-d="photosDu" value="${esc(diff.photosDu)}"></label>` : ''}</div>
        <div class="fcm-bts"><button class="btn btn--primary" data-fcm="diffuser"${L.occupe || !diff.shops.length ? ' disabled' : ''}>Diffuser à ${diff.shops.length} boutique(s)</button></div>`;
    return `${msg()}<div class="fcm-fh"><a class="fcm-lien" href="#/rfcontinue">← Formation continue</a><div><div class="fcm-kk">${esc(f.code)} · formation continue · v${esc(f.version)}</div><div class="fcm-tags">${tag(f)} ${st(f)}${f.tradNl === 'a_relire' ? ' <span class="fcm-tag">NL à relire</span>' : ''}</div></div></div>
      <div class="fcm-lay"><section class="card fcm-c"><div class="fcm-flux">${flux.map((x, k) => `<span class="${k < i ? 'fait' : (k === i ? 'ici' : '')}">${x[1]}</span>`).join('')}</div><div class="fcm-bts">${boutons}</div>
          <h3>Le contenu <a class="fcm-lien" href="#/sop/${esc(f.id)}/editer">Modifier dans l’éditeur du Manuel ›</a></h3>
          ${t(f.contenu.objectif) ? `<p class="fcm-n">${esc(t(f.contenu.objectif))}</p>` : ''}
          <ol class="fcm-et">${etapes.map(e => `<li class="${e.critique ? 'crit' : ''}">${e.titre ? `<b>${esc(t(e.titre))}</b> — ` : ''}${esc(t(e.texte))}</li>`).join('')}</ol>
          ${(f.contenu.points_critiques || []).length ? `<div class="fcm-crit">${f.contenu.points_critiques.map(p => `<div><b>Point critique</b> ${esc(t(p))}</div>`).join('')}</div>` : ''}
          <h3>La validation en magasin</h3>
          <div class="fcm-form"><label><span>Jours de photo qualité</span><input class="input" type="number" min="0" max="30" data-fcm-p="photosJours" value="${f.photosJours}"></label><label><span>Le produit</span><input class="input" data-fcm-p="produit" value="${esc(f.produit || '')}"></label>
            <label><span>Catégorie</span><select class="input" data-fcm-p="categorie">${(D ? D.categories : []).map(c => `<option value="${c.cle}"${f.categorie === c.cle ? ' selected' : ''}>${esc(c.nom)}</option>`).join('')}</select></label></div>
          <p class="fcm-n">Une photo par jour, photo obligatoire, relue par le consultant ; un jour manqué ou une photo à refaire ajoute un jour. 0 : pas de photo (une connaissance).</p>
          <div class="fcm-bts"><button class="btn" data-fcm="regler">Enregistrer</button></div></section>
        <aside><section class="card fcm-c"><h3>Diffusion aux boutiques</h3>${diffusion}</section>
          <section class="card fcm-c"><h3>Les boutiques <small>${(f.dossiers || []).length}</small></h3>${(f.dossiers || []).map(d => `<div class="fcm-d"><span>${esc(d.magasin)}</span><span class="fcm-st ${d.retard ? 'ret' : STM[d.statut]}">${esc(d.statutLib)}</span>${barre(d.avancement.pct)}</div>`).join('') || '<p class="fcm-n">Pas encore diffusée.</p>'}
            <p class="fcm-n">Le consultant vérifie, adapte et valide dans le cockpit (Franchisés › Formations continues).</p></section>
          <section class="card fcm-c"><h3>Journal</h3>${(f.journal || []).slice(0, 12).map(j => `<div class="fcm-tr"><span>${esc(dfr(j.le))} ${esc(String(j.le).slice(11, 16))}</span><span>${esc(j.detail)}<small>${esc(j.par)}</small></span></div>`).join('')}</section></aside></div>`;
  }

  function ecran() {
    const id = C.S.route.a[0] ? decodeURIComponent(C.S.route.a[0]) : null;
    if (id) { chargerFiche(id, false); } else { L.ficheId = null; }
    charger(false);
    const corps = L.err ? `<div class="fcm-msg ko">La formation continue ne se lit pas : ${esc(L.err)}</div>` : (!L.d ? '<p class="fcm-n">Lecture…</p>' : (id ? fiche() : liste()));
    return { titre: id && L.fiche ? L.fiche.titre : 'Formation continue', kicker: 'Formations', corps: `<div class="fcm">${corps}</div>` };
  }

  function brancher(root) {
    root.addEventListener('click', ev => {
      const b = ev.target.closest && ev.target.closest('[data-fcm]');
      if (!b || !root.querySelector('.fcm') || !root.querySelector('.fcm').contains(b)) { return; }
      const d = b.dataset;
      switch (d.fcm) {
        case 'cat': L.cat = d.v; return C.rendre(true);
        case 'st': L.st = d.v; return C.rendre(true);
        case 'ouvrir': location.hash = '#/rfcontinue/' + encodeURIComponent(d.v); return;
        case 'nouv': L.nouv = { titre: '', categorie: 'nouveau_produit', produit: '', objectif: '' }; L.msg = null; return C.rendre(true);
        case 'annuler': L.nouv = null; return C.rendre(true);
        case 'creer': {
          if (!L.nouv.titre.trim()) { L.msg = { ok: false, txt: 'Un titre, au moins.' }; return C.rendre(true); }
          const n = L.nouv;
          agir('POST', '/formation/continue', { titre: { fr: n.titre.trim(), nl: '' }, categorie: n.categorie, produit: n.produit, objectif: n.objectif }, r => r.code + ' créée en brouillon : écrivez son contenu dans l’éditeur.')
            .then(r => { if (r && r.id) { L.nouv = null; location.hash = '#/rfcontinue/' + encodeURIComponent(r.id); } });
          return;
        }
        case 'statut': agir('PATCH', '/formation/continue/' + encodeURIComponent(L.ficheId), { statut: d.v }, () => ({ a_valider: 'Envoyée à la validation.', publiee: 'Validée : elle peut partir chez les franchisés.', brouillon: 'Remise en brouillon.' })[d.v]); return;
        case 'regler': {
          const v = k => (root.querySelector('[data-fcm-p="' + k + '"]') || {}).value;
          agir('PATCH', '/formation/continue/' + encodeURIComponent(L.ficheId), { photosJours: +v('photosJours') || 0, produit: v('produit') || '', categorie: v('categorie') }, () => 'Enregistré.');
          return;
        }
        case 'diffuser': agir('POST', '/formation/continue/' + encodeURIComponent(L.ficheId) + '/diffuser', { shops: L.diff.shops, echeance: L.diff.echeance || null, photosDu: L.diff.photosDu || null },
          r => (r.diffusees || []).length + ' boutique(s) l’ont reçue dans leur dossier de formation (et dans leurs Nouveautés).').then(() => { L.diff = null; }); return;
        default: return;
      }
    });
    root.addEventListener('input', ev => {
      const n = ev.target.closest && ev.target.closest('[data-fcm-n]');
      if (n && L.nouv) { L.nouv[n.dataset.fcmN] = n.value; }
      const g = ev.target.closest && ev.target.closest('[data-fcm-d]');
      if (g && L.diff) { L.diff[g.dataset.fcmD] = g.value; }
    });
    root.addEventListener('change', ev => {
      const s = ev.target.closest && ev.target.closest('[data-fcm-shop]');
      if (s && L.diff) { const id = s.dataset.fcmShop; L.diff.shops = s.checked ? [...new Set([...L.diff.shops, id])] : L.diff.shops.filter(x => x !== id); C.rendre(true); }
      const n = ev.target.closest && ev.target.closest('select[data-fcm-n]');
      if (n && L.nouv) { L.nouv[n.dataset.fcmN] = n.value; }
    });
  }

  FS.ecrans.rfcontinue = ecran;
  FS.icones.rfcontinue = 'M2 9l10-5 10 5-10 5zM6 11v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5';
  const initAvant = FS.init, apresAvant = FS.apres;
  FS.init = function (ctx, root) { initAvant.call(FS, ctx, root); C = ctx; brancher(root); };
  FS.apres = function (e, a) { apresAvant.call(FS, e, a); };
})(window.FormationSop);
