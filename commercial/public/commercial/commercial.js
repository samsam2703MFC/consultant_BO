/* Le portail Commercial (19/11/2026) — une page, une vue par adresse (#tableau, #leads, #nouveau, #bureaux, #offres,
 * #echantillons, #suivi, #gains, #modeles, #reglages). L'API est à côté de la page : api/cockpit/commercial/…
 * (commercial/src/commercial.php). Design system de la marque (commercial.css) ; maquettes validées :
 * docs/maquettes/portail-commercial/. Aucune donnée inventée : ce qui n'a pas encore d'API est dit « à brancher ». */
(function () {
  'use strict';
  const API = 'api/cockpit/commercial';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const fE = n => n == null ? '—' : nf(Math.round(n), 0) + ' €';
  const fE2 = n => n == null ? '—' : nf(n, 2) + ' €';
  const fP = n => n == null ? '—' : nf(n, n % 1 ? 1 : 0) + ' %';
  const fD = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : '—';
  const fDc = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) : '—';
  const page = document.getElementById('page');
  let CFG = null;
  let ACT = {};

  function api(method, path, body) {
    return fetch(API + path, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
      .then(r => r.text().then(t => {
        // Une page HTML au lieu des données (session expirée, mauvaise adresse : 28/11/2026) se dit, au lieu de passer pour une réponse vide.
        let j = {};
        if (t.trim()) { try { j = JSON.parse(t); } catch (x) { return Promise.reject(new Error(/^\s*</.test(t) ? 'Le serveur a renvoyé une page au lieu des données (session expirée ou mauvaise adresse) : rechargez la page.' : 'Réponse illisible du serveur (HTTP ' + r.status + ').')); } }
        if (r.ok) return j;
        const e = j && j.error; const m = typeof e === 'string' ? e : (e && e.message) || j.erreur || ('HTTP ' + r.status);
        return Promise.reject(new Error(m));
      }));
  }
  function toast(t, ko) {
    const d = document.createElement('div'); d.className = 'toast' + (ko ? ' ko' : ''); d.textContent = t; document.body.appendChild(d);
    setTimeout(() => d.remove(), ko ? 7000 : 3500);
  }
  function erreur(e) { page.innerHTML = `<div class="card"><p class="erreur">${esc(e.message || e)}</p></div>`; }
  const btn = (txt, a, cls, data) => `<button class="btn ${cls || ''}" data-a="${a}"${data != null && data !== '' ? ` data-v="${esc(data)}"` : ''}>${txt}</button>`;
  const selMagasins = (val, tous, nom) => `<select class="select-native" ${nom ? `name="${nom}"` : 'data-c="shop"'}>${tous ? `<option value="">${esc(tous)}</option>` : ''}${CFG.magasins.map(m => `<option value="${esc(m.id)}" ${String(val) === String(m.id) ? 'selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>`;
  const selSecteurs = (val, nom) => `<select class="select-native" name="${nom || 'secteur'}"><option value="">—</option>${CFG.secteurs.map(s => `<option value="${esc(s.id)}" ${val === s.id ? 'selected' : ''}>${esc(s.nom)}</option>`).join('')}</select>`;
  const magPourCp = cp => { const c = String(cp || '').replace(/\D/g, ''); return c.length === 4 ? (CFG.magasins.find(m => (m.cps || []).includes(c)) || null) : null; };
  const nomMag = id => (CFG.magasins.find(m => String(m.id) === String(id)) || {}).nom || id || '—';
  const nomSec = id => (CFG.secteurs.find(s => s.id === id) || {}).nom || '';
  function lireForm(f) {
    const o = {};
    f.querySelectorAll('[name]').forEach(el => { o[el.name] = el.type === 'checkbox' ? el.checked : el.value; });
    return o;
  }
  function entete(kicker, titre, actions) {
    document.getElementById('kicker').textContent = kicker;
    document.getElementById('titre').textContent = titre;
    document.getElementById('actions').innerHTML = actions || '';
    document.title = titre + ' — Commercial';
  }

  /* --- le rail --------------------------------------------------------------------------------------------------- */
  const ICO = {
    retour: '<path d="M10 4 4 8l6 4M4 8h9"/>', plus: '<path d="M8 3v10M3 8h10"/>',
    grille: '<rect x="2.5" y="2.5" width="4.5" height="4.5" rx="1"/><rect x="9" y="2.5" width="4.5" height="4.5" rx="1"/><rect x="2.5" y="9" width="4.5" height="4.5" rx="1"/><rect x="9" y="9" width="4.5" height="4.5" rx="1"/>',
    leads: '<path d="M2.5 4.5h11v8h-11z"/><path d="m2.5 5 5.5 4 5.5-4"/>', bureau: '<path d="M3 13.5V3.5h6v10M9 6.5h4v7M5 6h2M5 8.5h2M5 11h2"/>',
    offre: '<path d="M3 3h5l5 5-5 5-5-5z"/><circle cx="5.8" cy="5.8" r=".8"/>', boite: '<path d="M2.5 5.5 8 3l5.5 2.5v6L8 14l-5.5-2.5z"/><path d="M2.5 5.5 8 8l5.5-2.5M8 8v6"/>',
    courbe: '<path d="M2.5 13.5h11M3.5 11l3-3.5 2.5 2 4-5"/>', euro: '<path d="M11.5 4.2A4.5 4.5 0 1 0 11.5 11.8M3 7h6M3 9h6"/>',
    carte: '<path d="M2.5 4.5 6 3l4 1.5 3.5-1.5v8.5L10 13l-4-1.5-3.5 1.5z"/><path d="M6 3v8.5M10 4.5V13"/>',
    mail: '<rect x="2.5" y="3.5" width="11" height="9" rx="1.5"/><path d="m3 4.5 5 4 5-4"/>', cle: '<circle cx="5.5" cy="10.5" r="2.5"/><path d="m7.3 8.7 5.2-5.2M11 5l1.5 1.5"/>',
  };
  const ico = n => `<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">${ICO[n]}</svg>`;
  const RAIL = [
    ['Ma semaine', [['semaine', 'carte', 'Ma semaine'], ['mobile', 'leads', 'Suivi mobile ↗']]],
    ['Bureaux · webshop', [['tableau', 'grille', 'Tableau de bord'], ['leads', 'leads', 'Leads Meta'], ['bureaux', 'bureau', 'Bureaux et clients'], ['offres', 'offre', 'Offres, bons et catalogues'], ['echantillons', 'boite', 'Échantillons']]],
    ['B2B', [['suivi', 'courbe', 'Suivi des offres'], ['gains', 'euro', 'Mes gains'], ['prospection', 'carte', 'Prospection']]],
    ['Réglages', [['modeles', 'mail', 'Modèles d’e-mail'], ['reglages', 'cle', 'Paramètres']]],
  ];
  let compteLeads = null, compteBureaux = null;
  const alertes = () => api('GET', '/alertes').then(a => { compteLeads = a.leads; compteBureaux = a.bureaux; const v = (location.hash.slice(1) || 'semaine').split('?')[0]; rail(VUES[v] ? v : 'semaine'); }).catch(() => {});
  function rail(vue) {
    // La Prospection (prospection/, page du portail depuis le 19/11/2026) s'affiche à côté du rail, dans la vue #prospection.
    const it = ([k, i, l]) => k === 'mobile'
      ? `<a class="sidebar__item" href="mobile/" target="_blank" rel="noopener">${ico(i)}<span>${l}</span></a>`
      : `<a class="sidebar__item ${vue === k ? 'is-active' : ''}" href="#${k}">${ico(i)}<span>${l}</span>${k === 'leads' && compteLeads ? `<span class="count" title="leads Meta à traiter" style="background:var(--accent);color:#fff">${compteLeads}</span>` : ''}${k === 'bureaux' && compteBureaux ? `<span class="count" title="bureaux à valider (compte webshop pas encore connecté)">${compteBureaux}</span>` : ''}</a>`;
    document.getElementById('rail').innerHTML = `
      <div class="sidebar__head"><img class="sidebar__logo" src="img/logo.png" alt="L’Atelier"><div class="sidebar__kicker">Module commercial</div></div>
      <div class="sidebar__back"><a href="${esc(CFG.cockpit)}" style="text-decoration:none"><span>${ico('retour')}Retour au cockpit</span></a></div>
      <div class="sidebar__brand">Réseau · ${CFG.magasins.length} magasins<span class="sub">${esc(CFG.magasins.map(m => m.nom).join(', '))}</span></div>
      <a class="sidebar__action ${vue === 'nouveau' ? 'is-active' : ''}" href="#nouveau" style="text-decoration:none">${ico('plus')}Nouveau bureau</a>
      <div class="sidebar__groups">${RAIL.map(([g, items]) => `<div class="sidebar__group"><div class="sidebar__group-label">${g}</div>${items.map(it).join('')}</div>`).join('')}</div>
      <div class="sidebar__account"><span>Envoi des e-mails</span><span class="muted">${esc(CFG.smtp.expediteur)}</span><span class="small" style="color:${CFG.smtp.configure ? 'var(--green)' : 'var(--danger)'}">${CFG.smtp.configure ? 'serveur d’envoi réglé' : 'serveur d’envoi non réglé'}</span></div>`;
  }

  /* --- l'envoi d'un e-mail (modale commune) ---------------------------------------------------------------------- */
  let CLIENTS = null, BONS = null;
  const charger = () => Promise.all([CLIENTS ? Promise.resolve() : api('GET', '/clients').then(r => { CLIENTS = r.clients; }),
    BONS ? Promise.resolve() : api('GET', '/bons').then(r => { BONS = r.bons; })]);
  function modaleEnvoi(o) {
    charger().then(() => {
      const m = document.createElement('div'); m.className = 'modal';
      const cli = o.clientId ? CLIENTS.find(c => c.id === Number(o.clientId)) : null;
      const st = { modele: o.modele || 'bienvenue', langue: (cli && cli.langue) || 'fr', catalogue: o.catalogue || null, url: null };
      m.innerHTML = `<div class="modal__box"><h3>Envoyer un e-mail depuis ${esc(CFG.smtp.expediteur)}</h3>
        <div class="grid" style="grid-template-columns: 1fr 1.2fr">
          <form class="form" onsubmit="return false">
            <label class="plein"><span class="field-label">Modèle</span><select name="modele">${Object.entries(CFG.modeles).map(([k, v]) => `<option value="${k}" ${k === st.modele ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
            <label class="plein"><span class="field-label">Client du portail</span><select name="clientId"><option value="">— un prospect (adresse ci-dessous) —</option>${CLIENTS.map(c => `<option value="${c.id}" ${cli && cli.id === c.id ? 'selected' : ''}>${esc(c.entreprise)} · ${esc(c.magasin)}</option>`).join('')}</select></label>
            <label class="plein"><span class="field-label">Adresse</span><input name="a" type="email" value="${esc(o.a || (cli && cli.email) || '')}" placeholder="adresse@entreprise.be"></label>
            <label><span class="field-label">Entreprise (prospect)</span><input name="entreprise" value="${esc(o.entreprise || '')}"></label>
            <label><span class="field-label">Contact (prospect)</span><input name="contact" value="${esc(o.contact || '')}"></label>
            <label><span class="field-label">Magasin</span>${selMagasins(o.shopId || (cli && cli.shopId) || '', null, 'shopId')}</label>
            <label><span class="field-label">Langue</span><select name="langue"><option value="fr" ${st.langue === 'fr' ? 'selected' : ''}>Français</option><option value="nl" ${st.langue === 'nl' ? 'selected' : ''}>Nederlands</option></select></label>
            <label><span class="field-label">Offre</span><select name="offre"><option value="">—</option>${CFG.secteurs.map(s => `<option value="${s.id}" ${o.offre === s.id ? 'selected' : ''}>${esc(s.nom)}</option>`).join('')}</select></label>
            <label><span class="field-label">Bon</span><select name="bon"><option value="">—</option>${BONS.filter(b => b.actif).map(b => `<option value="${esc(b.code)}" ${(o.bon || (cli && cli.bon)) === b.code ? 'selected' : ''}>${esc(b.code)} · ${esc(b.avantage)}</option>`).join('')}</select></label>
            ${st.catalogue ? `<div class="plein info">Catalogue joint par un lien public : ${esc(st.catalogue.nom)}</div>` : ''}
            <p class="erreur plein" data-err></p>
          </form>
          <div><iframe class="apercu" data-apercu title="Aperçu de l’e-mail"></iframe></div>
        </div>
        <div class="modal__actions"><button class="btn btn--ghost" data-m="fermer">Fermer</button><button class="btn" data-m="apercu">Aperçu</button><button class="btn btn--primary" data-m="envoyer">Envoyer</button></div></div>`;
      document.body.appendChild(m);
      const f = m.querySelector('form'); const err = m.querySelector('[data-err]');
      const corps = () => {
        const x = lireForm(f);
        const b = { modele: x.modele, langue: x.langue, a: x.a, offre: x.offre, bon: x.bon, shopId: x.shopId, entreprise: x.entreprise, contact: x.contact };
        if (x.clientId) b.clientId = Number(x.clientId);
        if (st.catalogue) b.catalogue = { nom: st.catalogue.nom, url: st.url };
        return b;
      };
      const lien = () => !st.catalogue || st.url ? Promise.resolve()
        : api('POST', '/catalogues/lien', { shopId: st.catalogue.shopId, id: st.catalogue.id }).then(r => { st.url = r.url; });
      const apercu = () => { err.textContent = ''; return lien().then(() => api('POST', '/apercu', corps())).then(r => { m.querySelector('[data-apercu]').srcdoc = r.html; }).catch(e => { err.textContent = e.message; }); };
      f.addEventListener('change', e => { if (e.target.name === 'clientId' && e.target.value) { const c = CLIENTS.find(x => x.id === Number(e.target.value)); if (c) { f.a.value = c.email; f.shopId.value = c.shopId; f.langue.value = c.langue; } } apercu(); });
      m.addEventListener('click', e => {
        const a = e.target.closest('[data-m]'); if (!a) { if (e.target === m) m.remove(); return; }
        if (a.dataset.m === 'fermer') m.remove();
        if (a.dataset.m === 'apercu') apercu();
        if (a.dataset.m === 'envoyer') {
          a.disabled = true; err.textContent = '';
          lien().then(() => api('POST', '/envoyer', corps())).then(r => { toast('Envoyé à ' + r.a); m.remove(); if (o.apres) o.apres(); })
            .catch(e2 => { err.textContent = 'Non envoyé : ' + e2.message; a.disabled = false; });
        }
      });
      apercu();
    }).catch(e => toast(e.message, true));
  }


  /* --- 0. ma semaine : l'assistant horizontal ---------------------------------------------------------------------- */
  const JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
  const CONF = { '': ['à confirmer', 'tag-warn'], envoyee: ['e-mail envoyé', 'chip'], confirme: ['confirmé', 'badge badge--ok'], sans_reponse: ['pas de réponse', 'tag-warn'], refuse: ['refusé', 'badge badge--n'] };
  const ETAPES = ['Magasins', 'Sociétés et créneaux', 'Confirmations', 'Échantillons', 'Appel aux franchisés', 'La semaine'];
  const lundi = d => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x.toISOString().slice(0, 10); };
  const plusJ = (d, n) => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
  const hm = s => (s || '').slice(11, 16);
  function vueSemaine(q) {
    const du = lundi(q.get('du') || new Date().toISOString().slice(0, 10));
    const etape = Math.min(6, Math.max(1, Number(q.get('etape') || 1)));
    const lien = (o) => '#semaine?' + new URLSearchParams({ du, etape, ...o });
    entete('Ma semaine', 'Semaine du ' + fD(du), `<a class="btn" href="${lien({ du: plusJ(du, -7) })}">← Semaine précédente</a><a class="btn" href="${lien({ du: plusJ(du, 7) })}">Semaine suivante →</a><a class="btn" href="mobile/" target="_blank" rel="noopener">Suivi mobile ↗</a>`);
    page.innerHTML = '<div class="vide">Lecture de la semaine…</div>';
    const cleMags = 'smSemaineMags:' + du;
    api('GET', '/semaine?du=' + du).then(d => {
      let mags = JSON.parse(sessionStorage.getItem(cleMags) || 'null');
      if (!mags) mags = d.magasins.filter(m => m.rdv || m.candidats).map(m => String(m.id));
      if (!mags.length) mags = d.magasins.map(m => String(m.id));
      const dansMags = x => mags.includes(String(x.shopId));
      const rdv = d.rdv.filter(r => r.statut !== 'annule');
      const visites = rdv.filter(r => r.type === 'visite');
      const stepper = `<div class="stepper">${ETAPES.map((e, i) => `<a href="${lien({ etape: i + 1 })}" style="text-decoration:none" class="${i + 1 === etape ? 'is-on' : i + 1 < etape ? 'is-fait' : ''}"><span class="small">${i + 1 < etape ? '✓ ' : ''}Étape ${i + 1}</span><div>${e}</div></a>`).join('')}</div>`;
      const nav = `<div style="display:flex;justify-content:space-between;margin-top:16px">${etape > 1 ? `<a class="btn btn--ghost" href="${lien({ etape: etape - 1 })}">← ${ETAPES[etape - 2]}</a>` : '<span></span>'}${etape < 6 ? `<a class="btn btn--primary" href="${lien({ etape: etape + 1 })}">${ETAPES[etape]} →</a>` : ''}</div>`;
      const ligneRdv = (r, actions) => `<tr><td class="ink2" style="white-space:nowrap">${JOURS[(new Date(r.le.slice(0, 10) + 'T12:00:00').getDay() + 6) % 7] || ''} ${fDc(r.le)}<span class="sub">${hm(r.le)}</span></td><td>${esc(r.prospect)}<span class="sub">${esc([r.contact, r.telephone, r.email].filter(Boolean).join(' · '))}</span></td><td>${esc(r.magasin)}<span class="sub">${esc(r.typeNom)}${r.degustation ? ' · dégustation' : ''}</span></td>${actions}</tr>`;
      let corps = '';
      if (etape === 1) {
        corps = `<div class="grid" style="grid-template-columns:repeat(${Math.max(1, d.magasins.length)},1fr)">${d.magasins.map(m => { const on = mags.includes(String(m.id));
          return `<label class="card" style="cursor:pointer;${on ? 'border:2px solid var(--accent);background:rgba(141,29,44,.03)' : ''}"><div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:15px">${esc(m.nom)}</span><input type="checkbox" data-c="mag" data-v="${esc(m.id)}" ${on ? 'checked' : ''}></div>
            <div class="small muted" style="margin-top:10px;line-height:1.8">${m.rdv} rendez-vous cette semaine<br>${m.candidats} sociétés à contacter<br>${m.degustations} dégustations<br>appel au franchisé : ${m.appel.ok ? '<span style="color:var(--green)">validé</span>' : 'à faire'}</div>
            <div class="small muted" style="margin-top:8px">${esc(m.zone.slice(0, 6).join(', '))}${m.zone.length > 6 ? '…' : ''}</div></label>`; }).join('')}</div>
          ${d.enRetard.length ? `<div class="card" style="margin-top:16px;padding:0;overflow:hidden"><div style="padding:16px 18px 6px"><h2>En retard <span class="aside">prévus avant cette semaine, pas encore faits</span></h2></div><table><tbody>${d.enRetard.map(r => ligneRdv(r, `<td class="num">${btn('Cette semaine', 'deplacer', 'btn--sm', r.id)}${btn('Annuler', 'annuler', 'btn--sm btn--ghost', r.id)}</td>`)).join('')}</tbody></table></div>` : ''}`;
      }
      if (etape === 2) {
        const cands = d.candidats.filter(dansMags);
        const optJours = JOURS.map((j, i) => `<option value="${plusJ(du, i)}">${j} ${fDc(plusJ(du, i))}</option>`).join('');
        corps = `<div class="grid" style="grid-template-columns:1.1fr 1fr">
          <div class="card" style="padding:0;overflow:hidden"><div style="padding:16px 18px 6px"><h2>À contacter <span class="aside">${cands.length} dans les magasins choisis</span></h2></div>
            <table><tbody>${cands.length ? cands.map((c, i) => `<tr><td>${esc(c.prospect)}<span class="sub">${esc(c.pourquoi)} · ${esc(c.magasin)}</span></td><td class="num" style="white-space:nowrap"><select class="select-native" data-k="j${i}" style="height:30px;font-size:12px">${optJours}</select> <input type="time" value="10:00" data-k="h${i}" style="padding:4px 6px;font-size:12px"> <select class="select-native" data-k="t${i}" style="height:30px;font-size:12px"><option value="visite">Visite</option><option value="appel">Appel</option></select> <label class="small"><input type="checkbox" data-k="g${i}"> dég.</label> ${btn('Planifier', 'planifier', 'btn--sm btn--primary', i)}</td></tr>`).join('') : '<tr><td class="vide">Rien à contacter dans ces magasins.</td></tr>'}</tbody></table>
            <form class="form" id="fs" onsubmit="return false" style="padding:14px 18px;border-top:1px solid var(--line)"><h2 class="plein">Ajouter une société</h2>
              <label><span class="field-label">Société</span><input name="prospect"></label><label><span class="field-label">Code postal (le magasin s’en déduit)</span><input name="cp" inputmode="numeric" maxlength="4" data-c="cpSemaine"></label>
              <label><span class="field-label">Contact</span><input name="contact"></label>
              <label><span class="field-label">E-mail</span><input name="email" type="email"></label><label><span class="field-label">Téléphone</span><input name="telephone"></label>
              <label><span class="field-label">Magasin</span>${selMagasins(mags[0] || '', null, 'shopId')}</label><label><span class="field-label">Secteur</span>${selSecteurs('')}</label>
              <label><span class="field-label">Jour</span><select name="jour">${optJours}</select></label><label><span class="field-label">Heure</span><input name="heure" type="time" value="10:00"></label>
              <label><span class="field-label">Type</span><select name="type"><option value="visite">Visite</option><option value="appel">Appel</option></select></label><label><span class="field-label"><input type="checkbox" name="degustation" style="width:auto"> Avec dégustation</span></label>
              <div class="plein" style="display:flex;justify-content:flex-end">${btn('Ajouter au planning', 'ajouter', 'btn--primary')}</div></form></div>
          <div class="card" style="padding:0;overflow:hidden"><div style="padding:16px 18px 6px"><h2>Le planning <span class="aside">${rdv.length} rendez-vous</span></h2></div>
            <table><tbody>${rdv.length ? rdv.map(r => ligneRdv(r, `<td class="num">${btn('Retirer', 'annuler', 'btn--sm btn--ghost', r.id)}</td>`)).join('') : '<tr><td class="vide">Rien de planifié.</td></tr>'}</tbody></table></div></div>
          <div class="card" style="margin-top:16px"><h2>Ce que rapporte un client <span class="aside">la simulation sur un an, à la règle de son secteur</span></h2>
            <form class="form" id="fsim" onsubmit="return false" style="grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;align-items:end">
              <label><span class="field-label">Secteur</span>${selSecteurs('bureau')}</label>
              <label><span class="field-label">Personnes</span><input name="personnes" type="number" min="1" value="20"></label>
              <label><span class="field-label">€ par personne</span><input name="depense" type="number" step="0.5" min="0" placeholder="de l’offre"></label>
              <label><span class="field-label">Commandes / semaine</span><input name="commandes" type="number" step="0.25" min="0" placeholder="de l’offre"></label>
              <label><span class="field-label">ou CA HT / mois</span><input name="ht" type="number" step="50" min="0"></label>
              <div>${btn('Simuler', 'simuler', 'btn--primary')}</div></form><div id="sim"></div></div>`;
        ACT.planifier = el => { const i = el.dataset.v, c = cands[i]; const v = k => page.querySelector(`[data-k="${k}${i}"]`);
          api('POST', '/rdv', { clientId: c.clientId, leadId: c.leadId, prospect: c.prospect, contact: c.contact, email: c.email, telephone: c.telephone, shopId: c.shopId, secteur: c.secteur,
            type: v('t').value, le: v('j').value + ' ' + v('h').value, degustation: v('g').checked }).then(() => vueSemaine(q)).catch(e => toast(e.message, true)); };
        ACT.simuler = () => {
          const x = lireForm(document.getElementById('fsim')); const qs = new URLSearchParams(); Object.entries(x).forEach(([k, v]) => { if (v !== '') qs.set(k, v); });
          api('GET', '/simulation?' + qs).then(s => {
            const max = Math.max(1, ...s.mois.map(m => m.gain));
            document.getElementById('sim').innerHTML = `<div class="kpi-row" style="margin-top:14px"><div class="kpi"><div class="kpi__value">${fE(s.htMois)}</div><div class="kpi__label">CA HT du client par mois</div><div class="kpi__note">${s.estime ? s.personnes + ' pers. × ' + nf(s.depense, 2) + ' € × ' + nf(s.commandes, 2) + ' cmd/sem. × 4,33' : 'saisi'}</div></div>
              <div class="kpi"><div class="kpi__value">${fE(s.total)}</div><div class="kpi__label">Gagné sur l’année</div><div class="kpi__note">${regleTxt(s.regle)} · ${regleSub(s.regle)}</div></div>
              <div class="kpi"><div class="kpi__value">${fE(s.franchise)}</div><div class="kpi__label">Payé par le franchisé</div><div class="kpi__note">le taux de départ, avec ses royalties</div></div>
              <div class="kpi"><div class="kpi__value">${fE(s.marque)}</div><div class="kpi__label">Payé par la marque</div></div></div>
              <div class="histo" style="height:110px">${s.mois.map(m => `<div><span style="color:var(--ink)">${fE(m.gain)}</span><i style="height:${Math.round(m.gain / max * 70)}px;${m.phase === 'depart' ? '' : 'background:#EBD3D8'}"></i>M${m.mois}<span class="sub">${fP(m.taux)}</span></div>`).join('')}</div>`;
          }).catch(e => toast(e.message, true));
        };
        ACT.cpSemaine = el => { const m = magPourCp(el.value); if (m) document.getElementById('fs').shopId.value = m.id; };
        ACT.ajouter = () => { const x = lireForm(document.getElementById('fs')); if (!x.prospect.trim()) { toast('La société manque', true); return; }
          api('POST', '/rdv', { ...x, le: x.jour + ' ' + x.heure }).then(() => vueSemaine(q)).catch(e => toast(e.message, true)); };
      }
      if (etape === 3) {
        const conf = visites.filter(r => r.confirmation === 'confirme').length;
        corps = `<div class="kpi-row"><div class="kpi"><div class="kpi__value">${visites.length}</div><div class="kpi__label">Visites</div></div><div class="kpi"><div class="kpi__value" style="color:var(--green)">${conf}</div><div class="kpi__label">Confirmées</div></div><div class="kpi"><div class="kpi__value">${visites.length - conf}</div><div class="kpi__label">Pas encore confirmées</div></div></div>
          <div class="card" style="padding:0;overflow:hidden"><table><thead><tr><th>Quand</th><th>Société</th><th>Magasin</th><th>Confirmation</th><th></th></tr></thead><tbody>${visites.length ? visites.map(r => ligneRdv(r,
            `<td><span class="${CONF[r.confirmation][1]}">${CONF[r.confirmation][0]}</span></td><td class="num" style="white-space:nowrap">${r.email ? btn(r.confirmation ? 'Renvoyer l’e-mail' : 'Envoyer l’e-mail', 'confirmer', 'btn--sm', r.id) : '<span class="small muted">pas d’e-mail</span>'}
            <select class="select-native" data-c="conf" data-v="${r.id}" style="height:30px;font-size:12px"><option value="">— la réponse —</option><option value="confirme" ${r.confirmation === 'confirme' ? 'selected' : ''}>Confirmé</option><option value="sans_reponse" ${r.confirmation === 'sans_reponse' ? 'selected' : ''}>Pas de réponse</option><option value="refuse" ${r.confirmation === 'refuse' ? 'selected' : ''}>Refusé</option></select></td>`)).join('') : '<tr><td class="vide" colspan="5">Aucune visite planifiée.</td></tr>'}</tbody></table></div>`;
      }
      if (etape === 4) {
        const aDeguster = visites.filter(r => r.degustation || r.echantillonId);
        corps = `<div class="info">${esc(CFG.aBrancher.echantillon)}</div><div class="card" style="padding:0;overflow:hidden"><table><thead><tr><th>Quand</th><th>Société</th><th>Magasin</th><th>Échantillon</th><th></th></tr></thead><tbody>${aDeguster.length ? aDeguster.map(r => { const e = d.echantillons[r.echantillonId];
          return ligneRdv(r, `<td>${e ? `<span class="${ECH[e.statut][1]}">${ECH[e.statut][0]}</span><span class="sub">${esc(e.contenu.map(x => x.q + ' × ' + x.produit).join(', '))}</span>` : '<span class="tag-warn">à commander</span>'}</td>
            <td class="num">${e ? (e.commande ? `<span class="small muted">TF Buddy n° ${e.commande}</span>` : '') : btn('Choisir les produits et commander', 'commander', 'btn--sm btn--primary', r.id)}</td>`); }).join('')
          : '<tr><td class="vide" colspan="5">Aucune visite avec dégustation : cochez « dég. » au planning, ou ajoutez-en une.</td></tr>'}</tbody></table></div>`;
        ACT.commander = el => { const r = rdv.find(x => x.id === Number(el.dataset.v));
          choisirProduits(r.shopId, []).then(v => {
            if (!v || !v.lignes.length) return;
            el.disabled = true;
            api('POST', '/echantillons', { prospect: r.prospect, contact: r.contact, clientId: r.clientId, secteur: r.secteur, shopId: r.shopId, contenu: v.manuel ? v.lignes : v.lignes.map(l => ({ id: l.id, q: l.q })),
              le: r.le.slice(0, 16), mode: 'livraison', adresse: r.adresse, commander: !v.manuel, employe: v.employe || null, envoyer: !!v.manuel, note: 'Pour la dégustation du ' + fDc(r.le) + ' à ' + hm(r.le) })
              .then(x => api('PUT', '/rdv/' + r.id, { echantillonId: x.echantillon.id }).then(() => {
                const ko = (x.commande && !x.commande.ok) || (x.envoi && !x.envoi.ok);
                toast(x.commande ? (x.commande.ok ? 'Commande TF Buddy n° ' + x.commande.commande + ' passée au magasin' : 'Échantillon noté, commande pas passée : ' + x.commande.erreur) : x.envoi && x.envoi.ok ? 'Demande envoyée au magasin par e-mail' : 'Échantillon noté, pas envoyé : ' + (x.envoi ? x.envoi.erreur : ''), ko); vueSemaine(q); }))
              .catch(e => { toast(e.message, true); el.disabled = false; });
          }); };
      }
      if (etape === 5) {
        corps = `<div class="grid" style="grid-template-columns:repeat(2,1fr)">${d.magasins.filter(m => mags.includes(String(m.id))).map(m => { const duMag = rdv.filter(r => String(r.shopId) === String(m.id));
          return `<div class="card"><div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:15px">${esc(m.nom)}</span>${m.tel ? `<a class="btn btn--sm" href="tel:${esc(m.tel.replace(/[^\d+]/g, ''))}">Appeler ${esc(m.tel)}</a>` : '<span class="small muted">téléphone inconnu</span>'}</div>
            <p class="small muted" style="margin:10px 0 6px">À valider avec le franchisé : les rendez-vous, les dégustations et les échantillons, les bureaux sur ses tournées.</p>
            <table class="mini"><tbody>${duMag.length ? duMag.map(r => `<tr><td>${fDc(r.le)} ${hm(r.le)}</td><td>${esc(r.prospect)}</td><td>${esc(r.typeNom)}${r.degustation ? ' · dégustation' : ''}</td><td>${r.echantillonId ? 'échantillon commandé' : ''}</td></tr>`).join('') : '<tr><td class="muted">Rien cette semaine.</td></tr>'}</tbody></table>
            <label class="small" style="display:block;margin-top:12px"><input type="checkbox" data-c="appel" data-v="${esc(m.id)}" ${m.appel.ok ? 'checked' : ''}> Appel fait, semaine validée avec le franchisé${m.appel.le ? ' (' + fD(m.appel.le.slice(0, 10)) + ')' : ''}</label>
            <input data-k="note${esc(m.id)}" value="${esc(m.appel.note)}" placeholder="Ce qui a été dit, ce qui change" style="width:100%;margin-top:8px;padding:7px 9px;font-size:12.5px"></div>`; }).join('')}</div>`;
      }
      if (etape === 6) {
        const parJour = JOURS.map((j, i) => [j, plusJ(du, i), rdv.filter(r => r.le.slice(0, 10) === plusJ(du, i))]);
        corps = `<div class="kpi-row"><div class="kpi"><div class="kpi__value">${rdv.length}</div><div class="kpi__label">Rendez-vous</div></div><div class="kpi"><div class="kpi__value">${visites.filter(r => r.confirmation === 'confirme').length} / ${visites.length}</div><div class="kpi__label">Visites confirmées</div></div>
          <div class="kpi"><div class="kpi__value">${rdv.filter(r => r.echantillonId).length}</div><div class="kpi__label">Échantillons commandés</div></div><div class="kpi"><div class="kpi__value">${d.magasins.filter(m => mags.includes(String(m.id)) && m.appel.ok).length} / ${mags.length}</div><div class="kpi__label">Franchisés appelés</div></div></div>
          <div class="grid" style="grid-template-columns:repeat(3,1fr)">${parJour.map(([j, date, l]) => `<div class="card" style="padding:14px 16px"><h2>${j} ${fDc(date)}</h2>${l.length ? l.map(r => `<div style="padding:7px 0;border-top:1px solid var(--row-line)"><span class="ink2">${hm(r.le)}</span> ${esc(r.prospect)}<span class="sub">${esc(r.magasin)} · ${esc(r.typeNom)}${r.degustation ? ' · dégustation' : ''} · ${CONF[r.confirmation][0]}</span></div>`).join('') : '<p class="small muted">Rien de prévu.</p>'}</div>`).join('')}</div>
          <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px"><a class="btn" href="mobile/" target="_blank" rel="noopener">Ouvrir le suivi mobile ↗</a><button class="btn" onclick="window.print()">Imprimer</button></div>`;
      }
      page.innerHTML = stepper + corps + nav;
    }).catch(erreur);
    ACT = {
      mag: el => { const s = new Set(JSON.parse(sessionStorage.getItem(cleMags) || '[]')); page.querySelectorAll('[data-c=mag]').forEach(c => { if (c.checked) s.add(c.dataset.v); else s.delete(c.dataset.v); }); sessionStorage.setItem(cleMags, JSON.stringify([...s])); vueSemaine(q); },
      deplacer: el => api('PUT', '/rdv/' + el.dataset.v, { le: du + ' 09:00' }).then(() => vueSemaine(q)).catch(e => toast(e.message, true)),
      annuler: el => api('PUT', '/rdv/' + el.dataset.v, { statut: 'annule' }).then(() => vueSemaine(q)).catch(e => toast(e.message, true)),
      confirmer: el => { el.disabled = true; api('POST', '/rdv/' + el.dataset.v + '/confirmer', {}).then(r => { toast('E-mail envoyé à ' + r.a); vueSemaine(q); }).catch(e => { toast('Non envoyé : ' + e.message, true); el.disabled = false; }); },
      conf: el => api('PUT', '/rdv/' + el.dataset.v, { confirmation: el.value }).then(() => vueSemaine(q)).catch(e => toast(e.message, true)),
      appel: el => api('PUT', '/semaine/franchise', { shopId: el.dataset.v, du, ok: el.checked, note: (page.querySelector(`[data-k="note${el.dataset.v}"]`) || {}).value || '' }).then(() => toast(el.checked ? 'Semaine validée avec le franchisé' : 'Appel remis à faire')).catch(e => toast(e.message, true)),
    };
  }
  /* --- 1. tableau de bord ---------------------------------------------------------------------------------------- */
  function vueTableau(q) {
    const jours = Number(q.get('jours') || 30);
    entete('Bureaux · webshop', 'Tableau de bord', btn(ico('plus') + 'Nouveau bureau', 'aller', 'btn--primary', 'nouveau'));
    page.innerHTML = '<div class="vide">Lecture des tickets pro du panel…</div>';
    api('GET', '/tableau?jours=' + jours).then(d => {
      const t = d.total;
      const lignes = d.parMagasin.map(l => `<tr><td>${esc(l.magasin)}</td><td class="num">${l.nouveaux}</td><td class="num">${l.anciens}</td><td class="num">${l.commandes}</td><td class="num">${fE(l.caHt)}</td><td class="num">${fE2(l.panierHt)}</td><td class="num">${l.portail}<span class="sub">${l.portailActifs} actifs</span></td></tr>`).join('');
      const max = Math.max(1, ...d.semaines.map(s => s.portail + s.autres));
      const W = 520, H = 180, bw = (W - 30) / d.semaines.length;
      const barres = d.semaines.map((s, i) => {
        const x = 30 + i * bw + 8, w = bw - 16, ha = s.autres / max * (H - 40), hp = s.portail / max * (H - 40), ya = H - 20 - ha, yp = ya - hp;
        return `<rect x="${x}" y="${ya}" width="${w}" height="${ha}" fill="#EBD3D8"/><rect x="${x}" y="${yp}" width="${w}" height="${hp}" fill="#B03048"/><text x="${x + w / 2}" y="${H - 6}" font-size="10" fill="#7a736a" text-anchor="middle">${s.semaine}</text><text x="${x + w / 2}" y="${yp - 5}" font-size="10" fill="#5c5852" text-anchor="middle">${s.portail + s.autres}</text>`;
      }).join('');
      const suivre = d.aSuivre.length ? d.aSuivre.map(s => `<tr><td>${esc(s.entreprise)}<span class="sub">${esc(s.magasin)}</span></td><td class="ink2">${esc(s.quoi)}</td><td class="num">${
        s.action === 'rattacher' ? btn('Rattacher', 'aller', 'btn--sm', 'bureaux?id=' + s.id) : s.action === 'relancer' ? btn('Relancer', 'relancer', 'btn--sm', s.id) : btn('À brancher', 'webshop', 'btn--sm')}</td></tr>`).join('')
        : '<tr><td class="vide">Rien à suivre pour l’instant.</td></tr>';
      page.innerHTML = `
        <div class="toolbar"><span class="seg">${[30, 60, 90].map(j => `<span data-a="jours" data-v="${j}" class="${j === jours ? 'is-on' : ''}">${j} jours</span>`).join('')}</span></div>
        <div class="kpi-row">
          <div class="kpi"><div class="kpi__value">${t.nouveaux + t.anciens}</div><div class="kpi__label">Clients pro actifs</div><div class="kpi__note">au moins une commande en ${jours} jours</div></div>
          <div class="kpi"><div class="kpi__value">${t.nouveaux}</div><div class="kpi__label">Nouveaux clients</div><div class="kpi__note">1re commande en ${jours} jours</div></div>
          <div class="kpi"><div class="kpi__value">${t.commandes}</div><div class="kpi__label">Commandes pro</div><div class="kpi__note">panier moyen ${fE2(t.panierHt)} HT</div></div>
          <div class="kpi"><div class="kpi__value">${fE(t.caHt)}</div><div class="kpi__label">CA pro HT</div><div class="kpi__note">estimé, TVA ${fP(d.tva)}</div></div>
          <div class="kpi"><div class="kpi__value">${t.portail}</div><div class="kpi__label">Bureaux du portail</div><div class="kpi__note">${t.portailActifs} ont commandé · cartes office : à brancher</div></div>
        </div>
        <div class="card" style="padding:0;overflow:hidden;margin-bottom:16px"><div style="padding:16px 18px 6px"><h2>Par magasin <span class="aside">nouveau client : 1re commande dans la période${d.manquants ? ' · ' + d.manquants + ' jours pas encore lus au panel' : ''}${d.histoManquants ? ' · ' + d.histoManquants + ' jours d’historique non lus : les nouveaux peuvent être surestimés' : ''}</span></h2></div>
          <table><thead><tr><th>Magasin</th><th class="num">Nouveaux clients</th><th class="num">Anciens clients</th><th class="num">Commandes</th><th class="num">CA HT</th><th class="num">Panier HT</th><th class="num">Bureaux du portail</th></tr></thead>
          <tbody>${lignes}<tr class="total"><td>Réseau</td><td class="num">${t.nouveaux}</td><td class="num">${t.anciens}</td><td class="num">${t.commandes}</td><td class="num">${fE(t.caHt)}</td><td class="num">${fE2(t.panierHt)}</td><td class="num">${t.portail}</td></tr></tbody></table></div>
        <div class="grid" style="grid-template-columns: 1fr 1.25fr">
          <div class="card"><h2>Commandes pro par semaine</h2><svg viewBox="0 0 ${W} ${H}" width="100%" style="display:block">${barres}</svg>
            <div class="small muted" style="display:flex;gap:16px;margin-top:6px"><span><i class="dot" style="background:#B03048"></i> clients du portail</span><span><i class="dot" style="background:#EBD3D8"></i> autres clients pro</span></div></div>
          <div class="card" style="padding:0;overflow:hidden"><div style="padding:16px 18px 6px"><h2>Bureaux à suivre</h2></div><table><tbody>${suivre}</tbody></table></div>
        </div>
        <p class="pied">${esc(d.source)}.</p>`;
    }).catch(erreur);
    ACT = {
      jours: el => { location.hash = 'tableau?jours=' + el.dataset.v; },
      relancer: el => modaleEnvoi({ modele: 'relance', clientId: el.dataset.v }),
      webshop: () => toast(CFG.aBrancher.webshop),
    };
  }

  /* --- 2. leads Meta --------------------------------------------------------------------------------------------- */
  const FAM = { a_traiter: ['À traiter', 'tag-warn'], contacte: ['Contacté', 'chip'], cree: ['Bureau créé', 'chip'], connecte: ['Bureau connecté', 'badge badge--ok'], perdu: ['Perdu', 'badge badge--n'] };
  function vueLeads(q) {
    const shop = q.get('shop') || '', fam = q.get('famille') || '', jours = q.get('jours') || '30';
    entete('Bureaux · webshop', 'Leads Meta', `<select class="select-native" data-c="jours">${[7, 30, 90].map(j => `<option value="${j}" ${String(j) === jours ? 'selected' : ''}>${j} derniers jours</option>`).join('')}</select>`);
    page.innerHTML = '<div class="vide">Lecture des leads…</div>';
    const url = (o) => { const p = new URLSearchParams({ jours, shop, famille: fam, ...o }); [...p.keys()].forEach(k => { if (!p.get(k)) p.delete(k); }); return '#leads?' + p; };
    api('GET', `/leads?jours=${jours}&shop=${encodeURIComponent(shop)}&famille=${encodeURIComponent(fam)}`).then(d => {
      compteLeads = d.parMagasin.reduce((s, m) => s + m.a_traiter, 0); rail('leads');
      const cartes = d.parMagasin.map(m => `<a class="card" href="${url({ shop: String(m.id) === shop ? '' : m.id })}" style="padding:14px 16px;text-decoration:none;${String(m.id) === shop ? 'border:2px solid var(--accent)' : ''}">
        <div style="display:flex;justify-content:space-between"><span style="font-size:15px">${esc(m.nom)}</span><span class="muted small">${m.leads} leads</span></div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:10px;font-size:12px">
          <div><div style="font-size:18px;font-weight:700;color:${m.a_traiter ? 'var(--prize-ink)' : 'var(--ink)'}">${m.a_traiter}</div><span class="muted">à traiter</span></div>
          <div><div style="font-size:18px;font-weight:700">${m.contacte}</div><span class="muted">contactés</span></div>
          <div><div style="font-size:18px;font-weight:700">${m.cree}</div><span class="muted">créés</span></div>
          <div><div style="font-size:18px;font-weight:700;color:var(--green)">${m.connecte}</div><span class="muted">connectés</span></div></div>
        <div class="small muted" style="margin-top:8px">${m.perdu} perdus · ${m.leads ? Math.round(m.connecte / m.leads * 100) : 0} % connectés</div></a>`).join('');
      const filtres = [['', 'Tous', d.comptes.tous], ['a_traiter', 'À traiter', d.comptes.a_traiter], ['contacte', 'Contactés', d.comptes.contacte], ['cree', 'Bureaux créés', d.comptes.cree], ['connecte', 'Connectés', d.comptes.connecte], ['perdu', 'Perdus', d.comptes.perdu]]
        .map(([k, l, n]) => `<a class="filter ${fam === k ? 'is-on' : ''}" href="${url({ famille: k })}" style="text-decoration:none">${l}<em>${n}</em></a>`).join('');
      const pf = p => `<span style="display:inline-flex;width:18px;height:18px;border-radius:5px;align-items:center;justify-content:center;font-size:9px;color:#fff;background:${p === 'ig' ? 'var(--mc-ig)' : 'var(--mc-fb)'}">${p === 'ig' ? 'IG' : 'f'}</span>`;
      const lignes = d.leads.length ? d.leads.map(l => `<tr><td class="ink2" style="white-space:nowrap">${esc((l.recu || '').slice(8, 10) + '/' + (l.recu || '').slice(5, 7))}<span class="sub">${esc((l.recu || '').slice(11, 16))}</span></td><td style="width:22px">${pf(l.plateforme)}</td>
        <td>${esc(l.entreprise || '—')}<span class="sub">${esc([l.contact, l.telephone, l.email].filter(Boolean).join(' · '))}</span></td>
        <td>${esc(l.magasin || 'à attribuer')}<span class="sub">${esc(l.cp ? 'CP ' + l.cp : '')}</span></td>
        <td><span class="${FAM[l.famille][1]}">${FAM[l.famille][0]}</span>${l.retard ? `<span class="sub">${esc(l.retard)}</span>` : ''}</td>
        <td class="num" style="white-space:nowrap">${l.clientId ? btn('Voir le bureau', 'aller', 'btn--sm', 'bureaux?id=' + l.clientId) : (l.famille === 'perdu' ? '' : btn('Créer le bureau', 'creer', 'btn--sm btn--primary', l.id))}
          ${l.lien ? `<a class="btn btn--sm btn--ghost" href="${esc(l.lien)}" target="_blank" rel="noopener">ERP ↗</a>` : ''}</td></tr>`).join('')
        : '<tr><td class="vide" colspan="6">Aucun lead sur la période.</td></tr>';
      page.innerHTML = `<div class="grid" style="grid-template-columns:repeat(${Math.max(1, d.parMagasin.length)},1fr);margin-bottom:16px">${cartes}</div>
        <div class="toolbar">${filtres}</div>
        <div class="card" style="padding:0;overflow:hidden"><table><thead><tr><th>Reçu</th><th></th><th>Lead</th><th>Magasin</th><th>Statut dans l’ERP</th><th></th></tr></thead><tbody>${lignes}</tbody></table></div>
        <div class="card" style="margin-top:16px" id="campagne"><div class="vide">Lecture des formulaires et de la publicité…</div></div>
        <details class="card autres-form" id="autres" hidden></details>
        <p class="pied">${esc(d.source)}. Un lead sans magasin prend celui de son code postal.</p>`;
      // L'aperçu du formulaire tel que Meta le montre : l'accueil, les champs (préremplis ou à choix), la confidentialité, le merci.
      const apercuForm = (a, langue) => !a ? '' : `<div class="pub-form"><div class="pub-form__tete">${esc(a.page)} · <span>${esc(a.nom)}</span></div>
        <div class="pub-form__etape">1 · ${langue === 'nl' ? 'Intro' : 'Accueil'}</div><div class="pub-form__bloc"><div class="pub-form__titre">${esc(a.accueil.titre)}</div><div class="small">${esc(a.accueil.texte)}</div></div>
        <div class="pub-form__etape">2 · ${langue === 'nl' ? 'Vragen' : 'Questions'} (${a.questions.length})</div>
        <div class="pub-form__bloc">${a.questions.map(q => `<div class="pub-form__champ"><div class="pub-form__label">${esc(q.label)}${q.prerempli ? `<em>${langue === 'nl' ? 'vooraf ingevuld' : 'prérempli par Meta'}</em>` : ''}</div>
          ${q.choix.length ? `<div class="pub-form__choix">${q.choix.map(c => `<span>${esc(c)}</span>`).join('')}</div>` : '<div class="pub-form__saisie"></div>'}</div>`).join('')}</div>
        <div class="pub-form__etape">3 · ${langue === 'nl' ? 'Privacy' : 'Confidentialité'}</div><div class="pub-form__bloc small">${esc(a.confidentialite)}</div>
        <div class="pub-form__etape">4 · ${langue === 'nl' ? 'Bedankt' : 'Remerciement'}</div><div class="pub-form__bloc"><div class="pub-form__titre">${esc(a.merci.titre)}</div><div class="small">${esc(a.merci.texte)}</div><div class="pub-form__bouton">${esc(a.merci.bouton)}</div></div></div>`;
      /* La campagne « Lunch au bureau » (20/11/2026 : « use all the page, no collision, understandable UX ») : un onglet par
       * langue — FR pour tous les magasins (magasin par code postal), NL pour Halle — et, dans chacun, où en est le
       * formulaire (sur la Page, actif, suivi, publicité), l'action qui fait avancer, puis l'aperçu, les vidéos et le post. */
      let FO = null; const PUB = {}; let onglet = q.get('langue') === 'nl' ? 'nl' : 'fr';
      const halle = () => CFG.magasins.find(m => /halle/i.test(m.nom));
      function campagne() {
        const el = document.getElementById('campagne'); const x = PUB[onglet]; if (!el || !FO || !x) return;
        const f = FO.formulaires.find(y => y.nom === x.formulaire);
        const pourHalle = onglet === 'nl' && halle();
        const cible = pourHalle ? 'leads attribués à ' + halle().nom : 'magasin du lead par son code postal';
        const sur = !!f, actif = sur && f.statutMeta !== 'ARCHIVED', suivi = actif && f.suivi;
        const etapes = [['Sur la Page Meta', sur], ['Actif', actif], ['Suivi ici', suivi], ['Publicité lancée chez Meta', null]];
        const action = !sur ? btn('Créer sur la Page', 'creerForm', 'btn--sm btn--primary', onglet + (pourHalle ? '|' + halle().id : ''))
          : !actif ? btn('Réactiver', 'actif', 'btn--sm btn--primary', f.id + '|1')
          : (!suivi ? btn('Suivre (' + cible + ')', 'suivre', 'btn--sm btn--primary', f.id + '|1' + (pourHalle ? '|' + halle().id : ''))
            : btn('Récupérer 90 jours', 'histo', 'btn--sm', f.id) + btn('Ne plus suivre', 'suivre', 'btn--sm btn--ghost', f.id + '|0'))
            + btn('Mettre en inactif', 'actif', 'btn--sm btn--ghost', f.id + '|0');
        const etat = !sur ? 'Pas encore créé sur la Page.' : !actif ? 'Inactif : archivé chez Meta, aucune publicité ne peut l’utiliser.'
          : !suivi ? 'Actif, mais pas suivi : ses leads n’arrivent pas ici.' : 'Actif et suivi : ' + cible + (f.dernierLead ? ' · dernier lead le ' + fDc(String(f.dernierLead).slice(0, 10)) : ' · aucun lead pour l’instant') + '.';
        const champ = (k, l, v) => `<div class="pub-champ"><div class="field-label">${l}${btn('Copier', 'copier', 'btn--sm btn--ghost', k)}</div><div class="pub-texte" data-pub="${k}">${esc(v)}</div></div>`;
        el.innerHTML = `<div class="campagne-tete"><div><h2 style="margin:0">Campagne « Lunch au bureau »</h2><div class="small muted" style="margin-top:4px">Le formulaire Meta, la vidéo et le post. Un lead n’arrive ici que d’un formulaire actif et suivi.</div></div>
            <div class="campagne-onglets">${[['fr', 'FR · tous les magasins'], ['nl', 'NL · ' + (halle() ? halle().nom : 'Halle')]].map(([k, l]) => `<button type="button" class="filter ${onglet === k ? 'is-on' : ''}" data-a="onglet" data-v="${k}">${esc(l)}</button>`).join('')}</div></div>
          <div class="campagne-etat">
            <ol class="campagne-etapes">${etapes.map(([l, ok], i) => `<li class="${ok ? 'fait' : ok === null ? 'dehors' : i === etapes.findIndex(e => e[1] === false) ? 'a-faire' : 'plus-tard'}"><span>${ok ? '✓' : i + 1}</span>${esc(l)}</li>`).join('')}</ol>
            <div class="campagne-action"><div><div>${esc(x.formulaire)}</div><div class="small muted">${esc(etat)}</div></div><div class="campagne-boutons">${action}</div></div></div>
          <div class="pub">
            <section><div class="pub-titre">Le formulaire</div>${apercuForm(x.apercu, onglet)}</section>
            <section><div class="pub-titre">Les vidéos</div><div class="pub-videos">${x.videos.length ? x.videos.map(v => `<figure style="flex:${v.format === '4x5' ? 0.8 : 0.5625} 1 0"><video src="${esc(v.mp4)}" poster="${esc(v.affiche)}" controls muted playsinline preload="none"></video>
              <figcaption>${esc(v.placement)} · ${v.duree} s · ${Math.round(v.octets / 1048576 * 10) / 10} Mo · <a href="${esc(v.mp4)}" download>Télécharger</a></figcaption></figure>`).join('')
              : `<div class="info">Vidéo pas encore rendue dans cette langue : <code>${esc(x.aRendre || '')}</code></div>`}</div></section>
            <section class="pub-post"><div class="pub-titre">Le post</div>${champ('texte', 'Texte principal', x.texte)}${champ('titre', 'Titre', x.titre)}${champ('description', 'Description', x.description)}
              <div class="pub-champ"><div class="field-label">Bouton</div><div class="pub-texte">${esc(x.bouton)}</div></div>
              <p class="small muted" style="margin-top:12px">Dans le Gestionnaire de publicités : objectif Prospects, formulaire instantané « ${esc(x.formulaire)} », la vidéo 9:16 pour les Stories et Reels, la 4:5 pour le fil. Sans son : une musique s’ajoute chez Meta.</p></section>
          </div>`;
        ACT.copier = b => { const t = el.querySelector(`[data-pub="${b.dataset.v}"]`).textContent; navigator.clipboard.writeText(t).then(() => toast('Copié')).catch(() => toast('Copie impossible : sélectionnez le texte', true)); };
        ACT.onglet = b => { onglet = b.dataset.v; campagne(); };
      }
      // Les autres formulaires de la Page (recrutement surtout) : repliés, ils ne se suivent pas d'ici.
      function autres() {
        const el = document.getElementById('autres'); if (!el || !FO) return;
        const noms = Object.values(PUB).map(x => x.formulaire);
        const l = FO.formulaires.filter(x => !x.lunch && !noms.includes(x.nom));
        el.hidden = !l.length; if (!l.length) return;
        const rec = l.filter(x => x.recrutement).length;
        el.innerHTML = `<summary>Les autres formulaires de la Page · ${l.length}<span class="small muted">${rec ? rec + ' de recrutement (candidats, franchisés) : pas des bureaux, ils ne se suivent pas d’ici' : ''}</span></summary>
          <table><tbody>${l.map(x => `<tr><td>${esc(x.nom)}<span class="sub">${esc(x.page)}${x.dernierLead ? ' · dernier lead ' + esc(String(x.dernierLead).slice(0, 10)) : ''}</span></td>
            <td>${x.statutMeta === 'ARCHIVED' ? '<span class="badge badge--n">inactif chez Meta</span>' : x.recrutement ? '<span class="badge badge--n">recrutement</span>' : x.suivi ? '<span class="badge badge--ok">suivi</span>' : '<span class="tag-warn">pas suivi</span>'}</td>
            <td class="num" style="white-space:nowrap">${x.recrutement || x.statutMeta === 'ARCHIVED' ? '' : x.suivi ? btn('Ne plus suivre', 'suivre', 'btn--sm btn--ghost', x.id + '|0') : btn('Suivre (magasin par code postal)', 'suivre', 'btn--sm', x.id + '|1')}</td></tr>`).join('')}</tbody></table>`;
      }
      Promise.all([api('GET', '/formulaires'), api('GET', '/formulaires/pub?langue=fr'), api('GET', '/formulaires/pub?langue=nl')])
        .then(([fo, fr, nl]) => { FO = fo; PUB.fr = fr; PUB.nl = nl; campagne(); autres(); })
        .catch(e => { const el = document.getElementById('campagne'); if (el) el.innerHTML = `<div class="erreur">${esc(e.message)}</div>`; });
      ACT.creerForm = el => {
        const [langue, shopSuivi] = el.dataset.v.split('|');
        api('GET', '/formulaires/modele?langue=' + langue).then(m => {
          const f = m.formulaire; const md = document.createElement('div'); md.className = 'modal';
          const libQ = x => x.label || ({ COMPANY_NAME: 'Nom de l’entreprise', FULL_NAME: 'Nom complet', JOB_TITLE: 'Fonction', EMAIL: 'E-mail', PHONE: 'Téléphone', POST_CODE: 'Code postal' })[x.type] || x.type;
          md.innerHTML = `<div class="modal__box" style="width:min(640px,100%)"><h3>Créer « ${esc(f.name)} » sur la Page Meta</h3>
            <p class="small muted">${esc(f.context_card.title)} — ${esc(f.context_card.content[0])}</p>
            <table class="mini"><tbody>${f.questions.map((x, i) => `<tr><td>${i + 1}</td><td>${esc(libQ(x))}${x.options ? `<span class="sub">${esc(x.options.map(o => o.value).join(' · '))}</span>` : ''}</td></tr>`).join('')}</tbody></table>
            <p class="small" style="margin-top:12px">Remerciement : ${esc(f.thank_you_page.title)}<br>Politique de confidentialité : ${m.politique ? `<a href="${esc(m.politique)}" target="_blank" rel="noopener">${esc(m.politique)}</a>` : '<span class="erreur">introuvable sur les formulaires existants</span>'}<br>Langue : ${esc(m.locale)} · site : ${esc(m.site)}</p>
            <p class="small muted">Le formulaire est créé dans la bibliothèque de la Page, sans publicité : il sert quand une publicité l’utilise. Il est ensuite suivi ici${shopSuivi ? ', ses leads attribués à ' + esc(nomMag(shopSuivi)) : ', le magasin de chaque lead pris d’après son code postal'}.</p>
            <p class="erreur" data-err></p>
            <div class="modal__actions"><button class="btn btn--ghost" data-m="0">Annuler</button><button class="btn btn--primary" data-m="1" ${m.politique ? '' : 'disabled'}>Créer sur la Page</button></div></div>`;
          document.body.appendChild(md);
          md.addEventListener('click', ev => { const b = ev.target.closest('[data-m]'); if (!b) return; if (b.dataset.m === '0') { md.remove(); return; }
            b.disabled = true;
            api('POST', '/formulaires/creer', { langue }).then(r => (r.id ? api('PUT', '/formulaires/' + r.id, shopSuivi ? { suivi: true, affectation: 'magasin', shopId: shopSuivi } : { suivi: true, affectation: 'cp' }).then(() => r) : r))
              .then(r => { md.remove(); toast((r.deja ? 'Déjà sur la Page : ' : 'Créé et suivi : ') + r.nom); vueLeads(q); })
              .catch(e => { b.disabled = false; md.querySelector('[data-err]').textContent = e.message; }); });
        }).catch(e => toast(e.message, true));
      };
      ACT.suivre = el => { const [id, on, shopSuivi] = el.dataset.v.split('|'); el.disabled = true;
        api('PUT', '/formulaires/' + id, on === '1' && shopSuivi ? { suivi: true, affectation: 'magasin', shopId: shopSuivi } : { suivi: on === '1', affectation: 'cp' })
          .then(() => { toast(on === '1' ? 'Formulaire suivi : ses leads arrivent' + (shopSuivi ? ' à ' + nomMag(shopSuivi) : '') : 'Formulaire plus suivi'); vueLeads(new URLSearchParams({ ...Object.fromEntries(q), langue: onglet })); })
          .catch(e => { toast(e.message, true); el.disabled = false; }); };
      ACT.actif = el => {
        const [id, on] = el.dataset.v.split('|');
        if (on !== '1' && !confirm('Mettre ce formulaire en inactif ? Il est archivé chez Meta : aucune publicité ne peut plus s’en servir, et il n’est plus suivi. Il se réactive d’ici.')) return;
        el.disabled = true;
        api('POST', '/formulaires/' + id + '/actif', { actif: on === '1' })
          .then(() => on === '1' ? null : api('PUT', '/formulaires/' + id, { suivi: false }))
          .then(() => { toast(on === '1' ? 'Formulaire réactivé chez Meta : suivez-le pour recevoir ses leads' : 'Formulaire inactif'); vueLeads(new URLSearchParams({ ...Object.fromEntries(q), langue: onglet })); })
          .catch(e => { toast(e.message, true); el.disabled = false; });
      };
      ACT.histo = el => { el.disabled = true; api('POST', '/formulaires/' + el.dataset.v + '/historique', { jours: 90 }).then(r => { toast('Historique relu' + (r && r.nouveaux != null ? ' : ' + r.nouveaux + ' lead(s)' : '')); vueLeads(q); }).catch(e => { toast(e.message, true); el.disabled = false; }); };
      ACT.creer = el => { const l = d.leads.find(x => x.id === Number(el.dataset.v)); sessionStorage.setItem('smLead', JSON.stringify(l)); location.hash = 'nouveau?lead=' + l.id; };
    }).catch(erreur);
    ACT = { creer: () => {} };
  }

  /* --- 3. nouveau bureau ----------------------------------------------------------------------------------------- */
  function vueNouveau(q) {
    entete('Bureaux · webshop', 'Nouveau bureau', btn('Fermer', 'aller', 'btn--ghost', 'bureaux'));
    let lead = null;
    try { lead = q.get('lead') ? JSON.parse(sessionStorage.getItem('smLead') || 'null') : null; } catch (e) { lead = null; }
    const v = lead || {};
    page.innerHTML = `
      <div class="stepper"><div class="is-on"><span class="small">Étape 1</span><div>Le bureau</div></div><div><span class="small">Étape 2</span><div>E-mail de bienvenue</div></div></div>
      <div class="info">${esc(CFG.aBrancher.webshop)} Le bureau est créé ici, son compte webshop reste « à créer ».</div>
      <form class="card form" id="fb" onsubmit="return false">
        <h2 class="plein">Le bureau et son magasin ${lead ? `<span class="aside">depuis le lead Meta${lead.erpId ? ' ' + esc(lead.erpId) : ''}</span>` : ''}</h2>
        <label><span class="field-label">Nom du bureau</span><input name="entreprise" required value="${esc(v.entreprise || '')}"></label>
        <label><span class="field-label">Code postal</span><input name="cp" inputmode="numeric" maxlength="4" value="${esc(v.cp || '')}"></label>
        <div class="plein" style="display:flex;gap:10px;align-items:center">${btn('Chercher son adresse', 'chercherNom', '')}<span class="small muted" data-mag></span></div>
        <div class="plein" data-lieux></div>
        <label><span class="field-label">Magasin (déduit du code postal)</span>${selMagasins(v.shopId || '', null, 'shopId')}</label>
        <label><span class="field-label">Adresse</span><input name="adresse"></label>
        <label><span class="field-label">Numéro de TVA</span><input name="tva" placeholder="BE 0…"></label>
        <label><span class="field-label">Type</span><select name="type"><option value="bureau">Bureau (livraison au bureau)</option><option value="b2b">Client B2B</option></select></label>
        <label><span class="field-label">Secteur</span>${selSecteurs('bureau')}</label>
        <label><span class="field-label">Personnes</span><input name="personnes" type="number" min="1"></label>
        <h2 class="plein" style="margin-top:8px">Contact</h2>
        <label><span class="field-label">Nom</span><input name="contact" value="${esc(v.contact || '')}"></label>
        <label><span class="field-label">Fonction</span><input name="fonction"></label>
        <label><span class="field-label">E-mail</span><input name="email" type="email" value="${esc(v.email || '')}"></label>
        <label><span class="field-label">Téléphone</span><input name="telephone" value="${esc(v.telephone || '')}"></label>
        <label><span class="field-label">Langue des e-mails</span><select name="langue"><option value="fr">Français</option><option value="nl">Nederlands</option></select></label>
        <h2 class="plein" style="margin-top:8px">Magasin et tournée</h2>
        <label><span class="field-label">Tournée</span><input name="tournee" placeholder="mardi et jeudi, avant 11:30 — fixée à la réunion du mois"></label>
        <div class="plein small muted" data-zone></div>
        <h2 class="plein" style="margin-top:8px">Bon et carte office</h2>
        <label><span class="field-label"><input type="checkbox" name="bonOn" checked style="width:auto"> Bon de bienvenue</span><input name="bonAvantage" value="−10 % sur la 1re commande"></label>
        <label><span class="field-label">Valable (jours)</span><input name="bonJours" type="number" min="1" value="30"></label>
        <label class="plein"><span class="field-label"><input type="checkbox" name="carteOffice" style="width:auto"> Carte de fidélité office</span><span class="small muted">${esc(CFG.aBrancher.cartes)}</span></label>
        <label class="plein"><span class="field-label">Note</span><textarea name="note"></textarea></label>
        <p class="erreur plein" data-err></p>
        <div class="plein" style="display:flex;justify-content:flex-end;gap:10px"><button class="btn btn--primary" data-a="creerBureau">Créer le bureau</button></div>
      </form>`;
    const f = document.getElementById('fb');
    const zone = () => { const m = CFG.magasins.find(x => String(x.id) === f.shopId.value); page.querySelector('[data-zone]').textContent = m && m.zone.length ? 'Zone du magasin : ' + m.zone.join(', ') + '.' : ''; };
    const parCp = () => { const m = magPourCp(f.cp.value), o = page.querySelector('[data-mag]');
      if (m) { f.shopId.value = m.id; o.innerHTML = 'Magasin : <b style="font-weight:700">' + esc(m.nom) + '</b> (code postal ' + esc(f.cp.value) + ')'; zone(); }
      else o.textContent = f.cp.value.replace(/\D/g, '').length === 4 ? 'Code postal hors des zones : choisissez le magasin.' : ''; };
    f.shopId.addEventListener('change', zone); f.cp.addEventListener('input', parCp); zone(); if (!v.shopId) parCp();
    ACT = {
      chercherNom: () => {
        const box = page.querySelector('[data-lieux]'); box.innerHTML = '<span class="small muted">Recherche…</span>';
        api('GET', '/trouver?q=' + encodeURIComponent(f.entreprise.value) + '&cp=' + encodeURIComponent(f.cp.value)).then(r => {
          if (!r.google) { box.innerHTML = '<span class="small muted">La recherche par le nom demande la clé Google (Paramètres du cockpit) : tapez le code postal.</span>'; return; }
          box.innerHTML = r.lieux.length ? `<table class="mini"><tbody>${r.lieux.map((l, i) => `<tr><td>${esc(l.nom)}<span class="sub">${esc(l.adresse)}</span></td><td>${l.magasin ? esc(l.magasin) : '<span class="tag-warn">hors zone</span>'}</td><td class="num">${btn('Choisir', 'prendreLieu', 'btn--sm', i)}</td></tr>`).join('')}</tbody></table>` : '<span class="small muted">Rien trouvé : tapez le code postal.</span>';
          ACT.prendreLieu = el => { const l = r.lieux[Number(el.dataset.v)]; f.entreprise.value = l.nom; f.adresse.value = l.adresse; if (l.cp) { f.cp.value = l.cp; parCp(); } box.innerHTML = ''; };
        }).catch(e => { box.innerHTML = `<span class="erreur">${esc(e.message)}</span>`; });
      },
      creerBureau: el => {
        const x = lireForm(f); const err = f.querySelector('[data-err]'); err.textContent = '';
        if (!x.entreprise.trim()) { err.textContent = 'Le nom de l’entreprise manque.'; return; }
        el.disabled = true;
        const b = { type: x.type, shopId: x.shopId, secteur: x.secteur, entreprise: x.entreprise, tva: x.tva, contact: x.contact, fonction: x.fonction, email: x.email,
          telephone: x.telephone, adresse: x.adresse, cp: x.cp, personnes: x.personnes, tournee: x.tournee, langue: x.langue, carteOffice: x.carteOffice, note: x.note,
          leadId: lead ? lead.id : null, leadErp: lead && lead.erpId ? String(lead.erpId) : '' };
        if (x.bonOn && x.bonAvantage.trim()) b.bonCreer = { avantage: x.bonAvantage, jours: x.bonJours };
        api('POST', '/clients', b).then(r => { CLIENTS = null; BONS = null; sessionStorage.removeItem('smLead'); etapeMail(r.client); })
          .catch(e => { err.textContent = e.message; el.disabled = false; });
      },
    };
  }
  function etapeMail(c) {
    page.querySelector('.stepper').innerHTML = `<div class="is-fait"><span class="small">✓ Étape 1</span><div>Le bureau</div></div><div class="is-on"><span class="small">Étape 2</span><div>E-mail de bienvenue</div></div>`;
    page.querySelector('#fb').outerHTML = `<div class="grid" style="grid-template-columns: 1fr 1.2fr">
      <div class="card"><h2>Le bureau est créé</h2><table><tbody>
        ${[['Entreprise', c.entreprise, c.tva], ['Contact', c.contact, [c.fonction, c.email, c.telephone].filter(Boolean).join(' · ')], ['Magasin', c.magasin, c.tournee],
          ['Compte webshop', 'à créer', 'API TF Buddy à définir avec Szymon'], ['Bon', c.bon || '—', ''], ['Carte office', c.carteOffice ? 'demandée' : 'non', c.carteOffice ? 'à brancher' : '']]
          .map(([k, a, s]) => `<tr><td class="muted" style="width:130px;padding:9px 0">${k}</td><td style="padding:9px 0">${esc(a)}<span class="sub">${esc(s || '')}</span></td></tr>`).join('')}</tbody></table>
        <div style="margin-top:14px;display:flex;gap:10px">${btn('Voir le bureau', 'aller', '', 'bureaux?id=' + c.id)}${btn('Commander un échantillon', 'aller', '', 'echantillons?client=' + c.id)}</div></div>
      <div class="card"><h2>E-mail de bienvenue</h2>${c.email ? `<iframe class="apercu" id="ap"></iframe><div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">${btn('Modifier et envoyer…', 'mailModale', '', c.id)}${btn('Envoyer à ' + esc(c.email), 'mailEnvoyer', 'btn--primary', c.id)}</div>` : '<p class="muted">Pas d’adresse e-mail : ajoutez-la sur la fiche du bureau pour envoyer la bienvenue.</p>'}<p class="erreur" data-err></p></div></div>`;
    if (c.email) api('POST', '/apercu', { modele: 'bienvenue', clientId: c.id }).then(r => { document.getElementById('ap').srcdoc = r.html; }).catch(e => { page.querySelector('[data-err]').textContent = e.message; });
    ACT = {
      mailModale: el => modaleEnvoi({ modele: 'bienvenue', clientId: el.dataset.v }),
      mailEnvoyer: el => { el.disabled = true; api('POST', '/envoyer', { modele: 'bienvenue', clientId: Number(el.dataset.v), a: c.email }).then(r => { toast('Envoyé à ' + r.a); el.textContent = 'Envoyé'; })
        .catch(e => { page.querySelector('[data-err]').textContent = 'Non envoyé : ' + e.message; el.disabled = false; }); },
    };
  }

  /* --- 4. bureaux et clients ------------------------------------------------------------------------------------- */
  const STATUTS = { prospect: 'Prospect', bureau_cree: 'Bureau créé', client: 'Client', perdu: 'Perdu' };
  const WEBSHOP = { a_creer: 'à créer', demande: 'demandé', cree: 'créé', connecte: 'connecté' };
  function vueBureaux(q) {
    const ouvert = Number(q.get('id') || 0);
    entete('Bureaux · webshop', 'Bureaux et clients', btn(ico('plus') + 'Nouveau bureau', 'aller', 'btn--primary', 'nouveau'));
    CLIENTS = null;
    api('GET', '/clients').then(d => {
      CLIENTS = d.clients;
      const ligne = c => `<tr class="row-link" data-a="ouvrir" data-v="${c.id}"><td>${esc(c.entreprise)}<span class="sub">${esc(c.secteurNom || (c.type === 'b2b' ? 'Client B2B' : 'Bureau'))}</span></td><td>${esc(c.magasin)}<span class="sub">${esc(c.tournee)}</span></td>
        <td>${esc(c.contact)}<span class="sub">${esc(c.email)}</span></td><td><span class="${c.statut === 'client' ? 'badge badge--ok' : c.statut === 'perdu' ? 'badge badge--n' : 'chip'}">${STATUTS[c.statut]}</span></td>
        <td>${c.clePanel ? '<span class="badge badge--ok">rattaché</span>' : '<span class="tag-warn">à rattacher</span>'}<span class="sub">webshop : ${WEBSHOP[c.webshop]}</span></td><td class="ink2">${fD(c.debut)}</td></tr>`
        + (c.id === ouvert ? `<tr class="detail"><td colspan="6">${ficheBureau(c)}</td></tr>` : '');
      page.innerHTML = d.clients.length ? `<div class="card" style="padding:0;overflow:hidden"><table><thead><tr><th>Entreprise</th><th>Magasin</th><th>Contact</th><th>Statut</th><th>Panel · webshop</th><th>Depuis</th></tr></thead><tbody>${d.clients.map(ligne).join('')}</tbody></table></div>`
        : `<div class="card vide">Aucun bureau pour l’instant. ${btn('Créer le premier', 'aller', 'btn--primary', 'nouveau')}</div>`;
      if (ouvert) { const f = document.getElementById('fc' + ouvert); if (f) f.scrollIntoView({ block: 'center' }); }
    }).catch(erreur);
    ACT = {
      ouvrir: el => { location.hash = 'bureaux' + (Number(el.dataset.v) === ouvert ? '' : '?id=' + el.dataset.v); },
      enregistrer: el => {
        const f = document.getElementById('fc' + el.dataset.v); const x = lireForm(f);
        api('PUT', '/clients/' + el.dataset.v, x).then(() => { toast('Enregistré'); CLIENTS = null; vueBureaux(q); }).catch(e => toast(e.message, true));
      },
      choisirPanel: el => {
        const c = CLIENTS.find(x => x.id === Number(el.dataset.v)); const box = document.getElementById('cp' + c.id);
        box.innerHTML = '<span class="muted small">Lecture des clients pro du magasin…</span>';
        api('GET', '/clients-panel?shop=' + encodeURIComponent(c.shopId)).then(r => {
          box.innerHTML = r.clients.length ? `<table class="mini"><tbody>${r.clients.map(p => `<tr><td>${esc(p.nom || p.cle)}</td><td class="ink2">dernière ${fDc(p.derniere)}</td><td class="num">${p.commandes} cmd · ${fE(p.ca)}</td><td class="num">${btn('Choisir', 'prendrePanel', 'btn--sm', c.id + '|' + p.cle)}</td></tr>`).join('')}</tbody></table>`
            : '<span class="muted small">Aucun client pro vu au magasin sur 90 jours.</span>';
        }).catch(e => { box.innerHTML = `<span class="erreur">${esc(e.message)}</span>`; });
      },
      prendrePanel: el => { const [id, cle] = el.dataset.v.split('|'); document.getElementById('fc' + id).clePanel.value = cle; toast('Compte ' + cle + ' choisi : enregistrez la fiche.'); },
      mail: el => modaleEnvoi({ modele: 'relance', clientId: el.dataset.v }),
      aller: el => { location.hash = el.dataset.v; },
    };
  }
  function ficheBureau(c) {
    return `<form class="form" id="fc${c.id}" onsubmit="return false" style="padding:6px 0">
      <label><span class="field-label">Entreprise</span><input name="entreprise" value="${esc(c.entreprise)}"></label>
      <label><span class="field-label">TVA</span><input name="tva" value="${esc(c.tva)}"></label>
      <label><span class="field-label">Secteur</span>${selSecteurs(c.secteur)}</label>
      <label><span class="field-label">Magasin</span>${selMagasins(c.shopId, null, 'shopId')}</label>
      <label><span class="field-label">Contact</span><input name="contact" value="${esc(c.contact)}"></label>
      <label><span class="field-label">E-mail</span><input name="email" value="${esc(c.email)}"></label>
      <label><span class="field-label">Téléphone</span><input name="telephone" value="${esc(c.telephone)}"></label>
      <label><span class="field-label">Tournée</span><input name="tournee" value="${esc(c.tournee)}"></label>
      <label><span class="field-label">Statut</span><select name="statut">${Object.entries(STATUTS).map(([k, l]) => `<option value="${k}" ${c.statut === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
      <label><span class="field-label">Compte webshop (à la main, en attendant l’API)</span><select name="webshop">${Object.entries(WEBSHOP).map(([k, l]) => `<option value="${k}" ${c.webshop === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
      <label><span class="field-label">Compte client du panel (ses commandes comptent)</span><input name="clePanel" value="${esc(c.clePanel)}" placeholder="c12345"></label>
      <label><span class="field-label">Langue</span><select name="langue"><option value="fr" ${c.langue === 'fr' ? 'selected' : ''}>Français</option><option value="nl" ${c.langue === 'nl' ? 'selected' : ''}>Nederlands</option></select></label>
      <div class="plein" id="cp${c.id}"></div>
      <label class="plein"><span class="field-label">Note</span><textarea name="note">${esc(c.note)}</textarea></label>
      <div class="plein" style="display:flex;gap:10px;justify-content:flex-end">${btn('Choisir le compte du panel', 'choisirPanel', '', c.id)}${btn('Envoyer un e-mail', 'mail', '', c.id)}${btn('Échantillon', 'aller', '', 'echantillons?client=' + c.id)}${btn('Enregistrer', 'enregistrer', 'btn--primary', c.id)}</div></form>`;
  }

  /* --- 5. offres, bons et catalogues ----------------------------------------------------------------------------- */
  let OFF = null;
  function vueOffres(q) {
    const shopCat = q.get('shop') || (CFG.magasins[0] || {}).id || '';
    entete('Bureaux · webshop', 'Offres, bons et catalogues', '');
    Promise.all([api('GET', '/offres'), api('GET', '/bons')]).then(([o, b]) => {
      BONS = b.bons; OFF = o;
      const cell = (of, m) => { const x = of.magasins[m.id]; return `<td class="cell-edit" data-a="editOffre" data-v="${of.id}|${m.id}">${x ? (x.statut === 'brouillon' ? '<span class="tag-warn">brouillon</span> ' : '') + esc(x.prix) + (x.conditions ? `<span class="sub">${esc(x.conditions)}</span>` : '') : '<span class="muted">—</span>'}</td>`; };
      const offres = o.offres.map(of => `<tr><td>${esc(of.nom)}<span class="sub">${esc(of.contenu)}</span></td>${o.magasins.map(m => cell(of, m)).join('')}<td class="num">${of.envoyees}</td><td class="num">${btn('Envoyer', 'envoyerOffre', 'btn--sm', of.id)}</td></tr>`).join('');
      const bons = b.bons.length ? b.bons.map(x => `<tr><td><span class="chip">${esc(x.code)}</span></td><td>${esc(x.magasin)}</td><td>${esc(x.avantage)}</td><td>${x.jusquAu ? fD(x.jusquAu) : x.jours ? x.jours + ' jours' : '—'}</td><td class="num">${x.envoyes}</td>
        <td class="num"><input type="number" min="0" value="${x.utilises}" data-c="utilises" data-v="${x.id}" style="width:70px;padding:5px 8px;text-align:right"></td><td class="num">${btn(x.actif ? 'Retirer' : 'Remettre', 'bonActif', 'btn--sm btn--ghost', x.id + '|' + (x.actif ? 0 : 1))}</td></tr>`).join('')
        : '<tr><td class="vide" colspan="7">Aucun bon.</td></tr>';
      page.innerHTML = `
        <div class="card" style="padding:0;overflow:hidden;margin-bottom:16px"><div style="padding:16px 18px 6px"><h2>Offres par magasin <span class="aside">un clic sur une case : le prix et les conditions du magasin</span></h2></div>
          <table><thead><tr><th>Offre</th>${o.magasins.map(m => `<th>${esc(m.nom)}</th>`).join('')}<th class="num">Envoyées</th><th></th></tr></thead><tbody>${offres}</tbody></table></div>
        <div class="card" style="padding:0;overflow:hidden;margin-bottom:16px"><div style="padding:16px 18px 6px"><h2>Bons <span class="aside">${esc(CFG.aBrancher.bons)}</span></h2></div>
          <table><thead><tr><th>Code</th><th>Magasin</th><th>Avantage</th><th>Validité</th><th class="num">Envoyés</th><th class="num">Utilisés</th><th></th></tr></thead><tbody>${bons}</tbody></table>
          <form class="form" id="fbon" onsubmit="return false" style="padding:14px 18px;border-top:1px solid var(--line);grid-template-columns:1fr 1fr 2fr 1fr auto">
            <label><span class="field-label">Code</span><input name="code" placeholder="FETES-2026"></label><label><span class="field-label">Magasin</span>${selMagasins('', 'Tous', 'shopId')}</label>
            <label><span class="field-label">Avantage</span><input name="avantage" placeholder="−5 € dès 50 € HT"></label><label><span class="field-label">Jusqu’au</span><input name="jusquAu" type="date"></label>
            <div style="align-self:end">${btn('Créer le bon', 'creerBon', 'btn--primary')}</div></form></div>
        <div class="card" style="padding:0;overflow:hidden"><div style="padding:16px 18px 6px;display:flex;justify-content:space-between;align-items:center"><h2 style="margin:0">Catalogues du magasin</h2>${selMagasins(shopCat)}</div><div id="cats" class="vide">Lecture des catalogues…</div></div>`;
      api('GET', '/catalogues?shop=' + encodeURIComponent(shopCat)).then(c => {
        document.getElementById('cats').outerHTML = c.indispo ? `<div class="vide">Catalogues indisponibles : ${esc(c.motif)}</div>`
          : c.catalogues.length ? `<table><tbody>${c.catalogues.map(x => `<tr><td>${esc(x.nom)}<span class="sub">${esc([x.statut, x.maj ? 'mis à jour ' + fD(String(x.maj).slice(0, 10)) : ''].filter(Boolean).join(' · '))}</span></td><td class="num">${x.lien ? `<a class="btn btn--sm btn--ghost" href="${esc(x.lien)}" target="_blank" rel="noopener">Aperçu</a>` : ''}${btn('Envoyer', 'envoyerCat', 'btn--sm', x.id + '|' + x.nom)}</td></tr>`).join('')}</tbody></table>`
            : '<div class="vide">Ce magasin n’a pas encore de catalogue (Dashboard magasin › Mon catalogue).</div>';
      }).catch(e => { const el = document.getElementById('cats'); if (el) el.textContent = e.message; });
    }).catch(erreur);
    ACT = {
      shop: el => { location.hash = 'offres?shop=' + el.value; },
      editOffre: el => {
        const [of, sid] = el.dataset.v.split('|');
        const m = document.createElement('div'); m.className = 'modal';
        m.innerHTML = `<div class="modal__box" style="width:min(520px,100%)"><h3>${esc(nomSec(of))} · ${esc(nomMag(sid))}</h3><form class="form" onsubmit="return false">
          <label class="plein"><span class="field-label">Prix (vide : l’offre n’est pas proposée par ce magasin)</span><input name="prix" placeholder="59 € · 10 pers."></label>
          <label class="plein"><span class="field-label">Conditions</span><input name="conditions" placeholder="livraison offerte dès 2 plateaux"></label>
          <label><span class="field-label">État</span><select name="statut"><option value="actif">Active</option><option value="brouillon">Brouillon</option></select></label></form>
          <div class="modal__actions"><button class="btn btn--ghost" data-m="0">Annuler</button><button class="btn btn--primary" data-m="1">Enregistrer</button></div></div>`;
        document.body.appendChild(m);
        const cur = ((OFF && OFF.offres.find(x => x.id === of)) || { magasins: {} }).magasins[sid];
        if (cur) { const f0 = m.querySelector('form'); f0.prix.value = cur.prix; f0.conditions.value = cur.conditions; f0.statut.value = cur.statut; }
        m.addEventListener('click', e => { const b = e.target.closest('[data-m]'); if (!b) return; if (b.dataset.m === '1') { const x = lireForm(m.querySelector('form')); api('PUT', '/offres', { offre: of, shopId: sid, ...x }).then(() => { m.remove(); vueOffres(q); }).catch(e2 => toast(e2.message, true)); } else m.remove(); });
      },
      envoyerOffre: el => modaleEnvoi({ modele: 'offre', offre: el.dataset.v }),
      envoyerCat: el => { const [id, nom] = el.dataset.v.split('|'); modaleEnvoi({ modele: 'catalogue', shopId: shopCat, catalogue: { shopId: shopCat, id, nom } }); },
      creerBon: () => { const x = lireForm(document.getElementById('fbon')); api('POST', '/bons', { ...x, usageUnique: false }).then(r => { toast('Bon ' + r.bon.code + ' créé'); BONS = null; vueOffres(q); }).catch(e => toast(e.message, true)); },
      bonActif: el => { const [id, a] = el.dataset.v.split('|'); api('PUT', '/bons/' + id, { actif: a === '1' }).then(() => { BONS = null; vueOffres(q); }).catch(e => toast(e.message, true)); },
      utilises: el => api('PUT', '/bons/' + el.dataset.v, { utilises: Number(el.value) }).then(() => toast('Enregistré')).catch(e => toast(e.message, true)),
    };
  }

  /* --- 6. échantillons ------------------------------------------------------------------------------------------- */
  const ECH = { a_envoyer: ['à envoyer', 'tag-warn'], commande: ['commandé', 'chip'], recu: ['reçu', 'chip'], livre: ['livré', 'chip'], converti: ['converti', 'badge badge--ok'], sans_suite: ['sans suite', 'badge badge--n'] };
  /* Le choix des produits du magasin, comme une commande du magasin (commercial_commande.php) : les catégories et les
   * produits de TF Buddy, la recherche, les quantités. Rend { lignes: [{id, q, nom, prix, cout}] } ou, quand le catalogue
   * ne se lit pas, { manuel: true, lignes: [{produit, q}] } ; null si on annule. */
  const CATALOGUES = {};
  const catalogueMag = shopId => CATALOGUES[shopId] || (CATALOGUES[shopId] = api('GET', '/catalogue-magasin?shop=' + encodeURIComponent(shopId)).catch(e => { delete CATALOGUES[shopId]; throw e; }));
  function choisirProduits(shopId, depart) {
    return new Promise(fin => {
      const md = document.createElement('div'); md.className = 'modal';
      md.innerHTML = `<div class="modal__box cmd-box"><h3>Commander un échantillon · ${esc(nomMag(shopId))}</h3><div class="vide">Lecture du catalogue du magasin…</div></div>`;
      document.body.appendChild(md);
      const sel = new Map((depart || []).filter(l => l.id).map(l => [l.id, Number(l.q) || 1]));
      let cat = '', cherche = '', bureau = true, C = null, IDX = {}, EQ = null, employe = null;
      const fermer = v => { if (v && employe) { try { localStorage.setItem('smEmploye:' + shopId, String(employe)); } catch (e) { /* navigation privée */ } } md.remove(); fin(v); };
      const lignes = () => [...sel.entries()].filter(([, q]) => q > 0).map(([id, q]) => ({ id, q, nom: IDX[id].nom, prix: IDX[id].prix, cout: IDX[id].cout, cat: IDX[id].cat }));
      function rendre() {
        const vis = p => (!bureau || p.bureau) && (!cherche || p.nom.toLowerCase().includes(cherche));
        const cats = C.categories.map(c => ({ ...c, vis: c.produits.filter(vis) })).filter(c => c.vis.length);
        if (!cherche && cat && !cats.find(c => c.nom === cat)) cat = '';
        if (!cherche && !cat && cats.length) cat = cats[0].nom;
        const prods = cherche ? cats.flatMap(c => c.vis) : ((cats.find(c => c.nom === cat) || {}).vis || []);
        const L = lignes(); const valeur = L.reduce((s, l) => s + (l.prix || 0) * l.q, 0); const cout = L.reduce((s, l) => s + (l.cout || 0) * l.q, 0);
        md.querySelector('.modal__box').innerHTML = `<h3>Commander un échantillon · ${esc(nomMag(shopId))} <span class="aside" style="float:none;margin-left:8px">les produits du magasin, comme une commande · offert</span></h3>
          <div class="toolbar"><input class="search" data-k="cherche" placeholder="Chercher un produit du magasin" value="${esc(cherche)}"><label class="small muted" style="display:flex;gap:6px;align-items:center"><input type="checkbox" data-k="bureau" ${bureau ? 'checked' : ''} style="width:auto"> livrables au bureau</label></div>
          <div class="cmd">
            <div class="cmd__cats">${cats.map(c => `<button type="button" class="cmd__cat ${!cherche && c.nom === cat ? 'is-on' : ''}" data-cat="${esc(c.nom)}">${esc(c.nom)}<em>${c.vis.length}</em></button>`).join('') || '<div class="small muted" style="padding:10px">Aucune catégorie.</div>'}</div>
            <div class="cmd__prods">${prods.map(p => { const q = sel.get(p.id) || 0; return `<div class="cmd__prod ${q ? 'is-on' : ''}"><div>${esc(p.nom)}<span class="sub">${p.prix != null ? fE2(p.prix) : 'prix non renseigné'}${cherche ? ' · ' + esc(IDX[p.id].cat) : ''}</span></div>
              <div class="cmd__q"><button type="button" data-q="${p.id}|-1" ${q ? '' : 'disabled'}>−</button><span>${q || ''}</span><button type="button" data-q="${p.id}|1">+</button></div></div>`; }).join('') || '<div class="vide">Aucun produit.</div>'}</div>
            <div class="cmd__panier"><div class="field-label">L’échantillon</div>${L.length ? `<table class="mini"><tbody>${L.map(l => `<tr><td>${l.q} ×</td><td>${esc(l.nom)}</td></tr>`).join('')}</tbody></table>
              <div class="small" style="margin-top:10px">Valeur de vente ${fE2(valeur)} · offert<br><span class="muted">Coût matière ${cout ? fE2(cout) : '—'}</span></div>` : '<div class="small muted">Choisissez les produits avec +.</div>'}
              <div class="field-label" style="margin-top:16px">Reçue au magasin par</div>${EQ && EQ.length ? `<select class="select-native" data-k="employe" style="width:100%"><option value="">— l’équipe du magasin —</option>${EQ.map(x => `<option value="${x.id}" ${employe === x.id ? 'selected' : ''}>${esc(x.nom)}</option>`).join('')}</select>
              <div class="small muted" style="margin-top:4px">TF Buddy l’exige : la personne qui accepte la commande.</div>` : `<div class="small muted">${EQ ? 'Équipe du magasin illisible : le dernier choix pour ce magasin servira.' : 'Lecture de l’équipe…'}</div>`}</div>
          </div>
          <div class="modal__actions"><button class="btn btn--ghost" data-m="0">Annuler</button><button class="btn btn--primary" data-m="1" ${L.length && (employe || (EQ && !EQ.length)) ? '' : 'disabled'}>Valider ${L.length ? L.length + ' produit' + (L.length > 1 ? 's' : '') : ''}</button></div>`;
        const s = md.querySelector('[data-k=cherche]'); if (cherche) { s.focus(); s.setSelectionRange(s.value.length, s.value.length); }
      }
      md.addEventListener('click', ev => {
        const b = ev.target.closest('[data-m],[data-cat],[data-q]'); if (!b) { if (ev.target === md) fermer(null); return; }
        if (b.dataset.m === '0') return fermer(null);
        if (b.dataset.m === '1') return fermer(C ? { lignes: lignes(), employe } : { manuel: true, lignes: md.querySelector('textarea').value.split(/[,\n]/).map(x => x.trim()).filter(Boolean).map(x => { const m = x.match(/^(\d+)\s*(?:×|x)?\s*(.+)$/); return m ? { q: m[1], produit: m[2] } : { q: '1', produit: x }; }) });
        if (b.dataset.cat != null) { cat = b.dataset.cat; cherche = ''; rendre(); return; }
        if (b.dataset.q) { const [id, d] = b.dataset.q.split('|').map(Number); sel.set(id, Math.max(0, (sel.get(id) || 0) + d)); rendre(); }
      });
      md.addEventListener('input', ev => { if (ev.target.dataset.k === 'cherche') { cherche = ev.target.value.trim().toLowerCase(); rendre(); } });
      md.addEventListener('change', ev => { if (ev.target.dataset.k === 'bureau') { bureau = ev.target.checked; rendre(); } if (ev.target.dataset.k === 'employe') { employe = Number(ev.target.value) || null; rendre(); } });
      // L'équipe du magasin (qui accepte la commande dans TF Buddy) : le dernier choix de ce poste, sinon celui du serveur.
      api('GET', '/equipe-magasin?shop=' + encodeURIComponent(shopId)).then(e => {
        EQ = e.employes; let m = null; try { m = Number(localStorage.getItem('smEmploye:' + shopId)) || null; } catch (x) { /* navigation privée */ }
        employe = EQ.some(x => x.id === m) ? m : (EQ.some(x => x.id === e.dernier) ? e.dernier : null); if (C) rendre();
      }).catch(() => { EQ = []; if (C) rendre(); });
      catalogueMag(shopId).then(c => { C = c; c.categories.forEach(x => x.produits.forEach(p => { IDX[p.id] = { ...p, cat: x.nom }; })); rendre(); })
        .catch(e => { md.querySelector('.modal__box').innerHTML = `<h3>Commander un échantillon · ${esc(nomMag(shopId))}</h3><p class="erreur">${esc(e.message)}</p>
          <label><span class="field-label">Noter les produits à la main (la demande part alors par e-mail au magasin)</span><textarea rows="3" placeholder="6 Croissant, 6 Pain au chocolat, 1 Tarte du jour"></textarea></label>
          <div class="modal__actions"><button class="btn btn--ghost" data-m="0">Annuler</button><button class="btn btn--primary" data-m="1">Valider</button></div>`; });
    });
  }

  // La photo de l'échantillon reçu (commercial_photos.php) : réduite à 1600 px en JPEG par le navigateur avant l'envoi.
  function reduirePhoto(file) {
    return new Promise((ok, ko) => {
      const img = new Image(); const u = URL.createObjectURL(file);
      img.onload = () => { const s = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight)); const c = document.createElement('canvas');
        c.width = Math.round(img.naturalWidth * s); c.height = Math.round(img.naturalHeight * s); c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(u); ok(c.toDataURL('image/jpeg', 0.82)); };
      img.onerror = () => { URL.revokeObjectURL(u); ko(new Error('photo illisible : ' + file.name)); };
      img.src = u;
    });
  }
  const photosEch = e => `<div class="ech-photos">${(e.photos || []).map(p => `<a href="${API}/echantillons/photos/${p.id}" target="_blank" rel="noopener" title="Reçu le ${esc(fDc((p.le || '').slice(0, 10)))} à ${esc((p.le || '').slice(11, 16))}"><img src="${API}/echantillons/photos/${p.id}" alt="Photo de l’échantillon reçu" loading="lazy"></a>`).join('')}
    <label class="btn btn--sm ${(e.photos || []).length ? 'btn--ghost' : ''}" title="Photographier l’échantillon reçu">${(e.photos || []).length ? '+ Photo' : 'Photo à la réception'}<input type="file" accept="image/*" capture="environment" multiple hidden data-c="photoEch" data-v="${e.id}"></label></div>`;
  function vueEchantillons(q) {
    entete('Bureaux · webshop', 'Échantillons', '');
    Promise.all([api('GET', '/echantillons'), charger()]).then(([d]) => {
      DECH = d.echantillons;
      const cli = q.get('client') ? CLIENTS.find(c => c.id === Number(q.get('client'))) : null;
      const liste = d.echantillons.length ? d.echantillons.map(e => `<tr><td class="ink2">${fDc((e.creeLe || '').slice(0, 10))}</td><td>${esc(e.prospect)}<span class="sub">${esc(e.contenu.map(x => x.q + ' × ' + x.produit).join(', '))}</span></td><td>${esc(e.magasin)}<span class="sub">${e.le ? esc(fDc(e.le.slice(0, 10)) + ' ' + e.le.slice(11, 16)) + ' · ' + esc(e.mode) : ''}</span></td>
        <td><select class="select-native" data-c="statutEch" data-v="${e.id}" style="height:30px;font-size:12px">${Object.entries(ECH).map(([k, [l]]) => `<option value="${k}" ${e.statut === k ? 'selected' : ''}>${l}</option>`).join('')}</select>${e.commande ? `<span class="sub">commande TF Buddy n° ${e.commande}</span>` : e.commandeErreur ? `<span class="sub erreur">${esc(e.commandeErreur)}</span>` : e.envoyeLe ? `<span class="sub">e-mail au magasin ${fDc(e.envoyeLe.slice(0, 10))}</span>` : ''}</td>
        <td>${photosEch(e)}</td>
        <td class="num" style="white-space:nowrap">${!e.commande && e.contenu.some(x => x.id) ? btn('Commander dans TF Buddy', 'commanderEch', 'btn--sm btn--primary', e.id) : ''}${e.commande ? btn('Annuler la commande', 'annulerCmd', 'btn--sm btn--ghost', e.id) : ''}${btn(e.envoyeLe && !e.commande ? 'Renvoyer l’e-mail' : 'E-mail au magasin', 'envoyerEch', 'btn--sm btn--ghost', e.id)}</td></tr>`).join('')
        : '<tr><td class="vide" colspan="6">Aucun échantillon sur 60 jours.</td></tr>';
      page.innerHTML = `
        <div class="kpi-row"><div class="kpi"><div class="kpi__value">${d.mois.n}</div><div class="kpi__label">Échantillons ce mois</div></div>
          <div class="kpi"><div class="kpi__value">${fE2(d.mois.cout)}</div><div class="kpi__label">Coût matière</div><div class="kpi__note">saisi à la commande</div></div>
          <div class="kpi"><div class="kpi__value">${d.mois.convertis}</div><div class="kpi__label">Convertis</div><div class="kpi__note">${d.mois.n ? Math.round(d.mois.convertis / d.mois.n * 100) : 0} % des échantillons du mois</div></div></div>
        <div class="info">${esc(CFG.aBrancher.echantillon)}</div>
        <div class="grid" style="grid-template-columns: 1fr 1.45fr">
          <form class="card form" id="fe" onsubmit="return false">
            <h2 class="plein">Commander un échantillon</h2>
            <label class="plein"><span class="field-label">Client du portail</span><select name="clientId"><option value="">— un prospect —</option>${CLIENTS.map(c => `<option value="${c.id}" ${cli && cli.id === c.id ? 'selected' : ''}>${esc(c.entreprise)}</option>`).join('')}</select></label>
            <label><span class="field-label">Prospect</span><input name="prospect" value="${esc(cli ? cli.entreprise : '')}"></label>
            <label><span class="field-label">Contact</span><input name="contact" value="${esc(cli ? cli.contact : '')}"></label>
            <label><span class="field-label">Magasin</span>${selMagasins(cli ? cli.shopId : '', null, 'shopId')}</label>
            <label><span class="field-label">Secteur</span>${selSecteurs(cli ? cli.secteur : '')}</label>
            <div class="plein"><span class="field-label">Les produits du magasin</span><div id="panier" class="small muted" style="margin:4px 0 8px">Aucun produit choisi.</div>${btn('Choisir les produits du magasin', 'produits', 'btn--sm')}</div>
            <label><span class="field-label">Le</span><input name="le" type="datetime-local"></label>
            <label><span class="field-label">Mode</span><select name="mode"><option value="livraison">Livraison</option><option value="retrait">Retrait au magasin</option></select></label>
            <label class="plein"><span class="field-label">Adresse de livraison</span><input name="adresse" value="${esc(cli ? [cli.adresse, cli.cp].filter(Boolean).join(', ') : '')}"></label>
            <label><span class="field-label">Coût matière (€)</span><input name="cout" type="number" step="0.01" min="0" placeholder="auto (recettes)"></label>
            <label><span class="field-label">Note pour le magasin</span><input name="note"></label>
            <label class="plein"><span class="field-label"><input type="checkbox" name="commander" checked style="width:auto"> Passer la commande dans le système du magasin (TF Buddy, « ÉCHANTILLON COMMERCIAL », offerte)</span></label>
            <label class="plein"><span class="field-label"><input type="checkbox" name="envoyer" style="width:auto"> Prévenir aussi le magasin par e-mail</span></label>
            <div class="plein" style="display:flex;justify-content:flex-end">${btn('Commander au magasin', 'creerEch', 'btn--primary')}</div></form>
          <div class="card" style="padding:0;overflow:hidden"><div style="padding:16px 18px 6px"><h2>Les échantillons <span class="aside">60 derniers jours</span></h2></div>
            <table><thead><tr><th>Le</th><th>Prospect</th><th>Magasin</th><th>Statut</th><th>Reçu</th><th></th></tr></thead><tbody>${liste}</tbody></table></div></div>`;
      const f = document.getElementById('fe');
      f.clientId.addEventListener('change', () => { const c = CLIENTS.find(x => x.id === Number(f.clientId.value)); if (c) { f.prospect.value = c.entreprise; f.contact.value = c.contact; if (String(f.shopId.value) !== String(c.shopId)) { f.shopId.value = c.shopId; PANIER = null; panier(); } f.secteur.value = c.secteur; f.adresse.value = [c.adresse, c.cp].filter(Boolean).join(', '); } });
      f.shopId.addEventListener('change', () => { PANIER = null; panier(); });
    }).catch(erreur);
    let PANIER = null;   // { lignes, employe, manuel } : ce que « Choisir les produits du magasin » a rendu
    let DECH = null;     // les échantillons affichés (relancer une commande : son magasin, ses produits, son employé)
    const panier = () => { const el = document.getElementById('panier'); if (!el) return;
      el.innerHTML = PANIER && PANIER.lignes.length ? PANIER.lignes.map(l => esc(l.q + ' × ' + (l.nom || l.produit))).join(' · ') + (PANIER.manuel ? ' <span class="tag-warn">à la main : par e-mail seulement</span>' : '') : 'Aucun produit choisi.'; };
    const resultat = r => [r.commande ? (r.commande.ok ? 'Commande TF Buddy n° ' + r.commande.commande + ' passée au magasin' : 'Commande pas passée : ' + r.commande.erreur) : '',
      r.envoi ? (r.envoi.ok ? 'e-mail envoyé (' + r.envoi.a + ')' : 'e-mail pas envoyé : ' + r.envoi.erreur) : ''].filter(Boolean).join(' · ') || 'Échantillon noté';
    const rate = r => (r.commande && !r.commande.ok) || (r.envoi && !r.envoi.ok);
    ACT = {
      produits: () => { const f = document.getElementById('fe'); choisirProduits(f.shopId.value, PANIER && !PANIER.manuel ? PANIER.lignes : []).then(v => { if (v) { PANIER = v; panier(); } }); },
      creerEch: el => {
        const f = document.getElementById('fe'); const x = lireForm(f);
        if (!PANIER || !PANIER.lignes.length) { toast('Choisissez les produits du magasin', true); return; }
        const contenu = PANIER.manuel ? PANIER.lignes : PANIER.lignes.map(l => ({ id: l.id, q: l.q }));
        el.disabled = true;
        api('POST', '/echantillons', { ...x, clientId: x.clientId ? Number(x.clientId) : null, contenu, commander: !PANIER.manuel && f.commander.checked, employe: PANIER.employe || null, envoyer: f.envoyer.checked || !!PANIER.manuel }).then(r => {
          toast(resultat(r), rate(r)); vueEchantillons(new URLSearchParams());
        }).catch(e => { toast(e.message, true); el.disabled = false; });
      },
      commanderEch: el => {
        const e = (DECH || []).find(x => x.id === Number(el.dataset.v));
        const envoyer = employe => { el.disabled = true; api('PUT', '/echantillons/' + el.dataset.v, { commander: true, employe }).then(r => { toast(resultat(r), rate(r)); vueEchantillons(q); }).catch(x => { toast(x.message, true); el.disabled = false; }); };
        if (e && e.employe) return envoyer(e.employe);
        // L'employé qui accepte la commande manque : on le choisit, dans le même écran que la commande.
        choisirProduits(e ? e.shopId : '', e ? e.contenu.filter(x => x.id).map(x => ({ id: x.id, q: Number(x.q) || 1 })) : []).then(v => { if (v && !v.manuel) envoyer(v.employe || null); });
      },
      annulerCmd: el => {
        if (!confirm('Annuler la commande TF Buddy de cet échantillon ? Elle est supprimée des commandes du magasin.')) return;
        el.disabled = true; api('DELETE', '/echantillons/' + el.dataset.v + '/commande').then(r => { toast('Commande TF Buddy n° ' + r.annulee + ' annulée'); vueEchantillons(q); }).catch(x => { toast(x.message, true); el.disabled = false; });
      },
      envoyerEch: el => api('PUT', '/echantillons/' + el.dataset.v, { envoyer: true }).then(r => { toast(r.envoi.ok ? 'Envoyé au magasin (' + r.envoi.a + ')' : 'Pas envoyé : ' + r.envoi.erreur, !r.envoi.ok); vueEchantillons(q); }).catch(e => toast(e.message, true)),
      statutEch: el => api('PUT', '/echantillons/' + el.dataset.v, { statut: el.value }).then(() => toast('Enregistré')).catch(e => toast(e.message, true)),
      photoEch: el => {
        if (!el.files.length) return;
        const lab = el.closest('label'); if (lab) lab.firstChild.textContent = 'Envoi…';
        Promise.all([...el.files].slice(0, 6).map(reduirePhoto)).then(l => api('POST', '/echantillons/' + el.dataset.v + '/photos', { photos: l.map(data => ({ data })) }))
          .then(() => { toast('Photo enregistrée : échantillon reçu'); vueEchantillons(q); }).catch(e => { toast(e.message, true); vueEchantillons(q); });
      },
    };
  }

  /* --- 7. suivi des offres (B2B) --------------------------------------------------------------------------------- */
  function vueSuivi(q) {
    const du = q.get('du') || new Date(Date.now() - 90 * 864e5).toISOString().slice(0, 10);
    entete('B2B', 'Suivi des offres', `<label class="small muted">Depuis le <input type="date" value="${du}" data-c="du" style="padding:6px 8px"></label>`);
    api('GET', '/suivi?du=' + du).then(d => {
      const sec = d.secteurs.length ? d.secteurs.map(s => `<tr><td>${esc(s.nom)}</td><td class="num">${s.total.offres}</td><td class="num">${s.total.echantillons}</td><td class="num">${s.total.convertis}</td><td class="num">${s.total.clients}</td></tr>`).join('')
        : '<tr><td class="vide" colspan="5">Rien depuis cette date : les offres, échantillons et bureaux du portail s’afficheront ici.</td></tr>';
      const mx = d.secteurs.map(s => `<tr><td>${esc(s.nom)}</td>${d.magasins.map(m => { const x = s.magasins[m.id] || {}; const k = x.clients || 0, o = (x.offres || 0) + (x.echantillons || 0);
        return o + k === 0 ? '<td class="num muted">—</td>' : `<td class="num" style="${k ? `background:rgba(63,122,82,${Math.min(.08 + k * .07, .5).toFixed(2)})` : ''}">${k}<span class="sub">${x.offres || 0} offres · ${x.echantillons || 0} éch.</span></td>`; }).join('')}</tr>`).join('');
      const pr = d.prospection.map(p => `<tr><td>${esc(p.magasin)}</td><td class="num">${p.liste}</td><td class="num">${p.contactes}</td><td class="num">${p.rdv}</td><td class="num">${p.clients}</td><td class="num">${p.refus}</td></tr>`).join('');
      page.innerHTML = `
        <div class="card" style="padding:0;overflow:hidden;margin-bottom:16px"><div style="padding:16px 18px 6px"><h2>Par secteur</h2></div>
          <table><thead><tr><th>Secteur</th><th class="num">Offres et catalogues envoyés</th><th class="num">Échantillons</th><th class="num">Échantillons convertis</th><th class="num">Bureaux et clients</th></tr></thead><tbody>${sec}</tbody></table></div>
        ${d.secteurs.length ? `<div class="card" style="padding:0;overflow:hidden;margin-bottom:16px"><div style="padding:16px 18px 6px"><h2>Par secteur et par magasin <span class="aside">bureaux et clients · offres et échantillons</span></h2></div>
          <table><thead><tr><th>Secteur</th>${d.magasins.map(m => `<th class="num">${esc(m.nom)}</th>`).join('')}</tr></thead><tbody>${mx}</tbody></table></div>` : ''}
        <div class="card" style="padding:0;overflow:hidden"><div style="padding:16px 18px 6px"><h2>Listes de démarchage <span class="aside">Prospection, par magasin</span></h2></div>
          <table><thead><tr><th>Magasin</th><th class="num">Dans la liste</th><th class="num">Contactés</th><th class="num">Rendez-vous</th><th class="num">Clients</th><th class="num">Refus</th></tr></thead><tbody>${pr}</tbody></table></div>
        <p class="pied">${esc(d.source)}.</p>`;
    }).catch(erreur);
    ACT = { du: el => { location.hash = 'suivi?du=' + el.value; } };
  }

  /* --- 8. mes gains ---------------------------------------------------------------------------------------------- */
  const PHASE = { depart: 'départ', palier: 'palier', suite: 'ensuite', termine: 'terminé', min: 'sous le minimum' };
  const regleTxt = r => `${fP(r.taux1)} · ${fP(r.taux2)} · ${fP(r.taux3)}`;
  const regleSub = r => `${r.mois1} mois, puis jusqu’au ${r.mois2}e, puis jusqu’au ${r.duree ? r.duree + 'e mois' : 'bout'}${r.min ? ', min ' + fE(r.min) : ''}`;
  function vueGains(q) {
    const auj = new Date(); const moisAuj = auj.toISOString().slice(0, 7);
    const mois = q.get('mois') || moisAuj;
    const nomMois = m => new Date(m + '-15').toLocaleDateString('fr-BE', { month: 'long', year: 'numeric' });
    entete('B2B', 'Mes gains', `<input type="month" value="${mois}" max="${moisAuj}" data-c="mois" style="padding:6px 8px">`);
    page.innerHTML = '<div class="vide">Calcul des gains…</div>';
    api('GET', '/gains?mois=' + mois).then(d => {
      const r = d.regles.defaut;
      const maxH = Math.max(1, ...d.historique.map(h => h.du));
      const histo = d.historique.map(h => `<div><span style="color:var(--ink)">${fE(h.du)}</span>${h.complement ? `<i class="plancher" style="height:${Math.round(h.complement / maxH * 90)}px;margin-bottom:0;border-radius:4px 4px 0 0"></i>` : ''}<i style="height:${Math.round(h.gain / maxH * 90)}px;${h.complement ? 'border-radius:0' : ''}"></i>${esc(nomMois(h.mois).replace(/ \d{4}$/, ''))}<span class="sub">${h.atteinte == null ? '' : 'obj. ' + nf(h.atteinte, 0) + ' %'}${h.roi == null ? '' : ' · ROI ' + nf(h.roi, 0) + ' %'}</span></div>`).join('');
      const ouvert = Number(q.get('client') || 0);
      const lignes = d.clients.length ? d.clients.map(c => `<tr class="row-link" data-a="detail" data-v="${c.id}"><td>${esc(c.entreprise)}<span class="sub">${esc(c.secteurNom || 'sans secteur')} · ${esc(c.magasin)}</span></td>
        <td class="ink2">${c.premiere ? fD(c.premiere) : '—'}<span class="sub">${c.finDepart ? 'taux de départ jusqu’au ' + fD(c.finDepart) : 'pas encore de commande'}</span></td>
        <td class="ink2">${regleTxt(c.regle)}<span class="sub">${regleSub(c.regle)}</span></td>
        <td class="num">${c.n}</td><td class="num">${fE2(c.ht)}</td><td class="num">${fE2(c.gain)}</td></tr>`
        + (c.id === ouvert ? `<tr class="detail"><td colspan="6"><table class="mini"><tbody>${c.commandes.length ? c.commandes.map(k => `<tr><td>${fD(k.j)} ${esc(k.h)}</td><td class="num">${fE2(k.ttc)} TTC</td><td class="num">${fE2(k.ht)} HT</td><td>${PHASE[k.phase]}${k.payeur ? ' · ' + (k.payeur === 'franchise' ? 'payé par le franchisé' : 'payé par la marque') : ''}</td><td class="num">${fP(k.taux)}</td><td class="num">${fE2(k.gain)}</td></tr>`).join('') : '<tr><td class="muted">Aucune commande ce mois.</td></tr>'}</tbody></table></td></tr>` : '')).join('')
        : '<tr><td class="vide" colspan="6">Aucun client rattaché à son compte du panel : ses commandes ne peuvent pas encore compter.</td></tr>';
      const parSec = d.parSecteur.map(s => `<tr><td>${esc(s.nom)}</td><td class="num">${s.clients}</td><td class="num">${fE2(s.ht)}</td><td class="num">${fE2(s.gain)}</td></tr>`).join('');
      const roiTxt = d.roi == null ? '—' : (d.roi > 0 ? '+' : '') + nf(d.roi, 0) + ' %';
      const barre = Math.min(100, d.atteinte || 0);
      const fr = d.franchises || [];
      page.innerHTML = `
        <div class="kpi-row">
          <div class="kpi"><div class="kpi__value">${fE2(d.du)}</div><div class="kpi__label">À payer au commercial · ${esc(nomMois(mois))}</div><div class="kpi__note">${d.complement ? 'dont ' + fE2(d.complement) + ' de complément au plancher' : 'commissions du mois'}</div></div>
          <div class="kpi"><div class="kpi__value">${fE2(d.aLaChargeFranchises)}</div><div class="kpi__label">Payé par les franchisés</div><div class="kpi__note">taux de départ, prélevé avec les royalties</div></div>
          <div class="kpi"><div class="kpi__value">${fE2(d.aLaChargeMarque)}</div><div class="kpi__label">Payé par la marque</div><div class="kpi__note">taux ensuite${d.complement ? ' et complément au plancher' : ''}</div></div>
          <div class="kpi"><div class="kpi__value">${fE2(d.plancher)}</div><div class="kpi__label">Plancher mensuel</div><div class="kpi__note">${d.plancher ? (d.complement ? 'atteint grâce au complément' : 'dépassé') : 'aucun plancher réglé'}</div></div>
        </div>
        <div class="kpi-row">
          <div class="kpi" style="flex:1.6"><div class="kpi__value" style="color:${d.objectifAtteint ? 'var(--green)' : 'var(--ink)'}">${fE(d.ht)} <span style="font-size:13px;font-weight:400;color:var(--muted)">/ ${fE(d.objectif)}</span></div><div class="kpi__label">Objectif : CA HT apporté par ses clients</div>
            <div style="height:6px;border-radius:3px;background:var(--chip);margin-top:8px;overflow:hidden"><i style="display:block;height:6px;width:${barre}%;background:${d.objectifAtteint ? 'var(--green)' : 'var(--prize)'}"></i></div>
            <div class="kpi__note">${d.atteinte == null ? 'pas d’objectif réglé' : nf(d.atteinte, 0) + ' %' + (d.manque ? ' · il manque ' + fE(d.manque) : ' · atteint')} · ${d.nouveauxDuMois} nouveau${d.nouveauxDuMois > 1 ? 'x' : ''} client${d.nouveauxDuMois > 1 ? 's' : ''} ce mois (${fE(d.htNouveauxDuMois)})</div></div>
          <div class="kpi"><div class="kpi__value">${fE(d.coutTotal)}</div><div class="kpi__label">Coût pour la marque</div><div class="kpi__note">${d.cout ? fE(d.cout) + ' de coût mensuel + ' + fE2(d.aLaChargeMarque) : 'coût mensuel à régler (Paramètres)'}</div></div>
          <div class="kpi"><div class="kpi__value" style="color:${d.roi == null ? 'var(--ink)' : d.roi >= 0 ? 'var(--green)' : 'var(--stop)'}">${roiTxt}</div><div class="kpi__label">ROI du commercial</div><div class="kpi__note">${d.roi == null ? 'réglez son coût mensuel' : 'retour ' + fE(d.retour) + (d.marge ? ' (marge ' + nf(d.marge, 0) + ' %)' : ' (CA HT)') + ' · ×' + nf(d.multiple, 2)}</div></div>
        </div>
        <div class="card" style="padding:0;overflow:hidden;margin-bottom:16px"><div style="padding:16px 18px 6px;display:flex;justify-content:space-between;align-items:center"><h2 style="margin:0">À prélever aux franchisés avec les royalties de ${esc(nomMois(mois))} <span class="aside" style="float:none;margin-left:8px">commission commerciale B2B, HT</span></h2>${fr.length ? btn('Exporter (CSV)', 'exportCommissions', 'btn--sm') : ''}</div>
          <table><tbody>${fr.length ? fr.map(x => `<tr><td>${esc(x.magasin)}</td><td class="ink2">${esc(d.clients.filter(c => String(c.shopId) === String(x.shopId) && c.gainFranchise > 0).map(c => c.entreprise + ' (' + fE2(c.gainFranchise) + ')').join(', '))}</td><td class="num">${fE2(x.montant)}</td></tr>`).join('')
            + `<tr class="total"><td>Total</td><td></td><td class="num">${fE2(d.aLaChargeFranchises)}</td></tr>` : '<tr><td class="vide">Rien à prélever ce mois.</td></tr>'}</tbody></table></div>
        <div class="grid" style="grid-template-columns: 1.4fr 1fr;margin-bottom:16px">
          <div class="card"><h2>Les ${d.historique.length} derniers mois <span class="aside">à payer au commercial ; l’objectif atteint et le ROI</span></h2><div class="histo">${histo}</div></div>
          <div class="card" style="padding:0;overflow:hidden"><div style="padding:16px 18px 6px"><h2>Par secteur</h2></div><table><thead><tr><th>Secteur</th><th class="num">Clients</th><th class="num">CA HT</th><th class="num">Gains</th></tr></thead><tbody>${parSec || '<tr><td class="vide" colspan="4">—</td></tr>'}</tbody></table></div>
        </div>
        <div class="card" style="padding:0;overflow:hidden"><div style="padding:16px 18px 6px"><h2>Le détail par client <span class="aside">un clic : ses commandes du mois</span></h2></div>
          <table><thead><tr><th>Client</th><th>1re commande</th><th>Règle</th><th class="num">Commandes</th><th class="num">CA HT</th><th class="num">Gains</th></tr></thead><tbody>${lignes}</tbody></table></div>
        ${d.nonRattaches.length ? `<div class="card" style="margin-top:16px"><h2>Pas encore comptés <span class="aside">rattachez leur compte client du panel</span></h2><p class="small">${d.nonRattaches.map(c => `<a href="#bureaux?id=${c.id}">${esc(c.entreprise)}</a> (${esc(c.magasin)})`).join(' · ')}</p></div>` : ''}
        <p class="pied">${esc(d.source)}${d.manquants ? ' · ' + d.manquants + ' jours du mois pas encore lus au panel' : ''}.</p>`;
      ACT.detail = el => { location.hash = 'gains?mois=' + mois + (Number(el.dataset.v) === ouvert ? '' : '&client=' + el.dataset.v); };
      ACT.exportCommissions = () => {
        const lignes = [['Mois', 'Magasin', 'Client', '1re commande', 'Commission HT']];
        d.clients.filter(c => c.gainFranchise > 0).forEach(c => lignes.push([mois, c.magasin, c.entreprise, c.premiere || '', String(c.gainFranchise).replace('.', ',')]));
        const csv = '\ufeff' + lignes.map(l => l.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(';')).join('\r\n');
        const a2 = document.createElement('a'); a2.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); a2.download = 'commission-commerciale-' + mois + '.csv'; a2.click();
      };
    }).catch(erreur);
    ACT = { mois: el => { location.hash = 'gains?mois=' + el.value; }, detail: () => {} };
  }

  /* --- 9. modèles d'e-mail --------------------------------------------------------------------------------------- */
  function vueModeles(q) {
    const id = q.get('id') || 'bienvenue', langue = q.get('langue') || 'fr';
    entete('Réglages', 'Modèles d’e-mail', '');
    Promise.all([api('GET', '/modeles'), charger()]).then(([d]) => {
      const m = d.modeles.find(x => x.id === id && x.langue === langue) || d.modeles[0];
      page.innerHTML = `<div class="toolbar">${Object.entries(CFG.modeles).map(([k, l]) => `<a class="filter ${k === id ? 'is-on' : ''}" href="#modeles?id=${k}&langue=${langue}" style="text-decoration:none">${esc(l)}</a>`).join('')}<span style="flex:1"></span>
        <span class="seg"><a href="#modeles?id=${id}&langue=fr" style="text-decoration:none"><span class="${langue === 'fr' ? 'is-on' : ''}">FR</span></a><a href="#modeles?id=${id}&langue=nl" style="text-decoration:none"><span class="${langue === 'nl' ? 'is-on' : ''}">NL</span></a></span></div>
        <div class="grid" style="grid-template-columns: 1fr 1.1fr"><form class="card form" id="fm" onsubmit="return false" style="grid-template-columns:1fr">
          <h2>${esc(m.nom)} · ${langue.toUpperCase()} ${m.regle ? '<span class="aside">modifié</span>' : '<span class="aside">modèle d’origine</span>'}</h2>
          <label><span class="field-label">Objet</span><input name="sujet" value="${esc(m.sujet)}"></label>
          <label><span class="field-label">Texte (**gras** pour un nom, un montant, une date)</span><textarea name="corps" style="min-height:260px">${esc(m.corps)}</textarea></label>
          <label><span class="field-label">Bouton (vide : pas de bouton)</span><input name="bouton" value="${esc(m.bouton)}"></label>
          <p class="small muted">Variables : ${d.variables.map(v => '{{' + v + '}}').join(' ')}</p>
          <div style="display:flex;gap:10px;justify-content:flex-end">${m.regle ? btn('Revenir au modèle d’origine', 'reset', 'btn--ghost') : ''}${btn('Enregistrer', 'sauver', 'btn--primary')}</div></form>
          <div class="card"><h2>Aperçu <span class="aside">${CLIENTS.length ? 'avec ' + esc(CLIENTS[0].entreprise) : 'sans client'}</span></h2><iframe class="apercu" id="apm"></iframe></div></div>`;
      api('POST', '/apercu', { modele: id, langue, clientId: CLIENTS.length ? CLIENTS[0].id : null }).then(r => { document.getElementById('apm').srcdoc = r.html; }).catch(() => {});
    }).catch(erreur);
    ACT = {
      sauver: () => api('PUT', `/modeles/${id}/${langue}`, lireForm(document.getElementById('fm'))).then(() => { toast('Modèle enregistré'); vueModeles(q); }).catch(e => toast(e.message, true)),
      reset: () => api('PUT', `/modeles/${id}/${langue}`, { corps: '' }).then(() => { toast('Modèle d’origine remis'); vueModeles(q); }).catch(e => toast(e.message, true)),
    };
  }

  /* --- 10. paramètres -------------------------------------------------------------------------------------------- */
  function vueReglages() {
    entete('Réglages', 'Paramètres', '');
    const r = CFG.reglages, g = r.gains, d = g.defaut;
    const champsRegle = (x, pre, vide) => ['taux1', 'mois1', 'taux2', 'mois2', 'taux3', 'duree', 'min'].map(k => `<td><input type="number" step="${k.startsWith('taux') || k === 'min' ? '0.5' : '1'}" min="0" name="${pre}${k}" value="${x && x[k] != null ? x[k] : ''}" ${vide ? `placeholder="${d[k]}"` : ''} style="width:70px;padding:5px 8px"></td>`).join('');
    page.innerHTML = `<form id="fr" onsubmit="return false">
      <div class="card" style="padding:0;overflow:hidden;margin-bottom:16px"><div style="padding:16px 18px 6px"><h2>Les gains du commercial <span class="aside">case vide : la règle par défaut</span></h2></div>
        <table><thead><tr><th>Secteur</th><th>Départ (%)</th><th>Jusqu’au mois</th><th>Palier (%)</th><th>Jusqu’au mois</th><th>Ensuite (%)</th><th>Fin au mois (0 = jamais)</th><th>Commande min. (€ HT)</th></tr></thead>
        <tbody><tr class="total"><td>Par défaut</td>${champsRegle(d, 'd_', false)}</tr>${CFG.secteurs.map(s => `<tr><td>${esc(s.nom)}</td>${champsRegle(g.secteurs[s.id], 's_' + s.id + '_', true)}</tr>`).join('')}</tbody></table>
        <div class="form" style="padding:14px 18px;border-top:1px solid var(--line);grid-template-columns:1fr 1fr 1fr">
          <label><span class="field-label">Plancher mensuel (€)</span><input type="number" step="1" min="0" name="plancher" value="${g.plancher}"></label>
          <label><span class="field-label">TVA pour le HT (%)</span><input type="number" step="0.5" min="0" name="tva" value="${g.tva}"></label>
          <label><span class="field-label">Objectif mensuel : CA HT apporté (€)</span><input type="number" step="100" min="0" name="objectif" value="${g.objectif}"></label>
          <label><span class="field-label">Coût mensuel du commercial (€)</span><input type="number" step="50" min="0" name="cout" value="${g.cout}"></label>
          <label><span class="field-label">Marge pour le ROI (%, vide : sur le CA HT)</span><input type="number" step="1" min="0" max="100" name="marge" value="${g.marge || ''}"></label>
          <label class="plein"><span class="field-label"><input type="checkbox" name="departFranchise" ${g.departFranchise !== false ? 'checked' : ''} style="width:auto"> Le taux de départ est à la charge du franchisé (prélevé avec ses royalties)</span></label>
          <p class="small muted" style="align-self:end">Plancher : si les commissions du mois sont plus basses, le commercial touche le plancher. Une commande sous le minimum ne rapporte rien.</p></div></div>
      <div class="card form" style="margin-bottom:16px"><h2 class="plein">E-mails</h2>
        <label class="plein"><span class="field-label">Expéditeur</span><input name="expediteur" value="${esc(r.expediteur)}"></label>
        <label><span class="field-label">Signature · nom</span><input name="sig_nom" value="${esc(r.signature.nom)}"></label>
        <label><span class="field-label">Signature · fonction</span><input name="sig_fonction" value="${esc(r.signature.fonction)}"></label>
        <label><span class="field-label">Signature · téléphone</span><input name="sig_telephone" value="${esc(r.signature.telephone)}"></label>
        <label><span class="field-label">Signature · site</span><input name="sig_site" value="${esc(r.signature.site)}"></label>
        <label class="plein"><span class="field-label">Adresse du webshop (bouton des e-mails)</span><input name="webshop" value="${esc(r.webshop)}" placeholder="https://…"></label>
        <p class="plein small ${CFG.smtp.configure ? 'muted' : 'erreur'}">${CFG.smtp.configure ? 'Le serveur d’envoi du cockpit est réglé ; les e-mails partent avec l’expéditeur ci-dessus.' : 'Le serveur d’envoi n’est pas réglé (Paramètres du cockpit, ou clés smtp_* d’erp_cles) : aucun e-mail ne part.'}</p></div>
      <div class="card" style="margin-bottom:16px"><h2>Codes postaux des magasins <span class="aside">ils déterminent le magasin d’un bureau et d’un lead</span></h2>
        <div class="grid" style="grid-template-columns:repeat(${Math.max(1, CFG.magasins.length)},1fr)">${CFG.magasins.map(m => `<label><span class="field-label">${esc(m.nom)}</span><textarea name="cp_${esc(m.id)}" style="width:100%;min-height:120px;font:12.5px 'Gotham',sans-serif">${esc((m.cps || []).join(' '))}</textarea></label>`).join('')}</div></div>
      <div class="card" style="margin-bottom:16px"><h2>Zones des magasins <span class="aside">une commune par ligne</span></h2>
        <div class="grid" style="grid-template-columns:repeat(${Math.max(1, CFG.magasins.length)},1fr)">${CFG.magasins.map(m => `<label><span class="field-label">${esc(m.nom)}</span><textarea name="z_${esc(m.id)}" style="width:100%;min-height:200px;font:12.5px 'Gotham',sans-serif">${esc(m.zone.join('\n'))}</textarea></label>`).join('')}</div></div>
      <div style="display:flex;justify-content:flex-end">${btn('Enregistrer les paramètres', 'sauver', 'btn--primary')}</div></form>`;
    ACT = {
      sauver: () => {
        const x = lireForm(document.getElementById('fr'));
        const regle = pre => { const o = {}; ['taux1', 'mois1', 'taux2', 'mois2', 'taux3', 'duree', 'min'].forEach(k => { if (x[pre + k] !== '') o[k] = Number(x[pre + k]); }); return o; };
        const secteurs = {}; CFG.secteurs.forEach(s => { const o = regle('s_' + s.id + '_'); if (Object.keys(o).length) secteurs[s.id] = o; });
        const zones = {}; CFG.magasins.forEach(m => { zones[m.id] = (x['z_' + m.id] || '').split('\n').map(s => s.trim()).filter(Boolean); });
        const cps = {}; CFG.magasins.forEach(m => { cps[m.id] = (x['cp_' + m.id] || '').split(/[^\d]+/).filter(c => c.length === 4); });
        api('PUT', '/reglages', { gains: { defaut: regle('d_'), secteurs, plancher: Number(x.plancher || 0), tva: Number(x.tva || 6), objectif: Number(x.objectif || 0), cout: Number(x.cout || 0), marge: Number(x.marge || 0), departFranchise: !!x.departFranchise }, expediteur: x.expediteur,
          signature: { nom: x.sig_nom, fonction: x.sig_fonction, telephone: x.sig_telephone, site: x.sig_site }, webshop: x.webshop, zones, cps })
          .then(() => api('GET', '/config')).then(c => { CFG = c; toast('Paramètres enregistrés'); rail('reglages'); vueReglages(); }).catch(e => toast(e.message, true));
      },
    };
  }

  /* --- la Prospection, à côté du rail (19/11/2026 : « mettre la prospection à côté du rail, ne pas ouvrir de nouvelle
   * feuille ») : la page prospection/ dans un cadre, sans son propre en-tête (?embed=1) — un seul titre « Prospection »,
   * celui du portail, et le magasin choisi dans sa barre. ------------------------------------------------------------ */
  function vueProspection(q) {
    const shop = q.get('shop') || String((CFG.magasins[0] || {}).id || '');
    entete('B2B', 'Prospection', `<select class="select-native" data-c="prosShop" title="Magasin">${CFG.magasins.map(m => `<option value="${esc(m.id)}" ${String(m.id) === shop ? 'selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>`);
    page.innerHTML = `<iframe class="cadre-prospection" src="prospection/?embed=1${shop ? '&shop=' + encodeURIComponent(shop) : ''}" title="Prospection : la liste de démarchage du magasin"></iframe>`;
    ACT.prosShop = el => { location.hash = 'prospection?shop=' + encodeURIComponent(el.value); };
  }

  /* --- le routeur ------------------------------------------------------------------------------------------------ */
  const VUES = { prospection: vueProspection, semaine: vueSemaine, tableau: vueTableau, leads: vueLeads, nouveau: vueNouveau, bureaux: vueBureaux, offres: vueOffres, echantillons: vueEchantillons, suivi: vueSuivi, gains: vueGains, modeles: vueModeles, reglages: vueReglages };
  function aller() {
    const [v, qs] = (location.hash.slice(1) || 'semaine').split('?');
    const vue = VUES[v] ? v : 'semaine';
    rail(vue);
    ACT = {};
    window.scrollTo(0, 0);
    VUES[vue](new URLSearchParams(qs || ''));
  }
  document.addEventListener('click', e => {
    const el = e.target.closest('[data-a]'); if (!el || el.closest('.modal')) return;
    if (el.dataset.a === 'aller') { e.preventDefault(); location.hash = el.dataset.v; return; }
    if (ACT[el.dataset.a]) { if (el.tagName === 'BUTTON') e.preventDefault(); ACT[el.dataset.a](el, e); }
  });
  document.addEventListener('change', e => { const el = e.target.closest('[data-c]'); if (el && !el.closest('.modal') && ACT[el.dataset.c]) ACT[el.dataset.c](el); });
  window.addEventListener('hashchange', aller);
  api('GET', '/config').then(c => { CFG = c; aller(); alertes(); }).catch(e => { document.getElementById('rail').innerHTML = ''; erreur(e); });
})();
