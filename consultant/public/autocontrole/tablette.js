/* L'autocontrôle qualité à la tablette du magasin (24/11/2026) — API : consultant/src/autocontrole.php.
 *
 * Adresse : /consulant_bo/autocontrole/?shop=4[&produit=6700139][&qui=Nathan][&role=manager][&essai=1][&visite=31&cadre=1]
 *   shop    : le magasin (un franchisé n'ouvre que les siens : garde.js) ;
 *   produit : ouvre directement ce produit (le lien de la fiche de l'app Cuisine) ; sans lui, la liste du catalogue ;
 *   qui     : la personne qui contrôle, préremplie ;
 *   role    : qui contrôle — equipe, manager, franchise ou consultant (26/11/2026), choisi aussi sur la page ;
 *   essai   : un contrôle d'essai, enregistré à part (ni historique, ni résultats du réseau, ni journal) ;
 *   visite  : le contrôle d'une visite du consultant (son id ou son client_id) : rôle consultant, jamais un essai,
 *             il entre dans le rapport de la visite ;
 *   cadre   : la page est ouverte dans la visite (visites.js) : « Revenir à la visite » lui écrit {type: 'aq-fermer'},
 *             chaque contrôle enregistré {type: 'aq-fait', id}.
 * Le parcours : 1. Cuisson, 2. Structure, 3. Organoleptique, 4. Présentation, puis le bilan. Le bilan affiché est
 * calculé ici pour répondre tout de suite ; le serveur le recalcule à l'enregistrement, seul il fait foi.
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const ICO = window.AQ_ICO;
  const q = new URLSearchParams(location.search);
  const app = document.getElementById('aq');
  const S = {
    shop: q.get('shop') || '', essai: q.get('essai') === '1' && !q.get('visite'), produitDemande: q.get('produit') || '',
    visite: q.get('visite') || '', cadre: q.get('cadre') === '1', role: q.get('visite') ? 'consultant' : (q.get('role') || ''), clientId: '',
    liste: null, magasin: null, recherche: '', d: null, ecran: 'liste',
    rep: {}, photos: {}, decision: null, prevenir: null, reclamer: null,
    fournee: '', pieces: '', qui: q.get('qui') || '', envoi: false, erreur: '', fait: null,
  };

  /* ── Lire, écrire (même forme que le planogramme : plano.js) ─────────────────────────────────────────── */
  function rep(r) {
    return r.ok ? r.json() : r.json().then(j => Promise.reject(Object.assign(new Error((j && j.error) || ('HTTP ' + r.status)), { j })), () => Promise.reject(new Error('HTTP ' + r.status)));
  }
  const lire = path => fetch(API + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(rep);
  const ecrire = (path, body) => fetch(API + path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) }).then(rep);
  /** Qui contrôle : les mêmes clés que AQ_ROLES (src/autocontrole.php). */
  const ROLES = [['equipe', 'Équipe'], ['manager', 'Manager'], ['franchise', 'Franchisé'], ['consultant', 'Consultant']];
  /** Un identifiant par contrôle, gardé si l'envoi est rejoué : le serveur ne l'enregistre qu'une fois. */
  const nouvelId = () => 'aq-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
  /** Écrire à la visite qui a ouvert la page (même origine seulement). */
  const aLaVisite = m => { if (S.cadre && window.parent !== window) { window.parent.postMessage(m, location.origin); } };
  const esc = s => String(s ?? '').replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
  const NIV = { ok: 'Conforme', mineur: 'À surveiller', majeur: 'Non conforme' };
  const etat = (niv, txt) => `<span class="aq-etat ${niv}">${esc(txt || NIV[niv])}</span>`;
  const ecartsTxt = n => (n ? n + ' écart' + (n > 1 ? 's' : '') : 'aucun écart');
  const hhmm = d => String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');

  /* ── Les règles (les mêmes qu'au serveur : aqEvaluer, aqBilan) ───────────────────────────────────────── */
  const criteres = () => (S.d ? S.d.grille.criteres : []);
  const etapes = () => (S.d ? S.d.etapes : []);
  function cible(c) {
    if (c.type === 'oui_non') return c.attendu;
    if (c.type === 'mesure') return String(c.cible ?? c.minimum);
    return (c.options.find(o => o.cible) || {}).cle;
  }
  function evaluer(c, v) {
    if (v === undefined || v === null || v === '') return null;
    if (c.type === 'oui_non') return v === c.attendu ? { niveau: 'ok', libelle: v === 'oui' ? 'Oui' : 'Non' } : Object.assign({}, c[v], { libelle: v === 'oui' ? 'Oui' : 'Non' });
    if (c.type === 'mesure') {
      const n = Number(String(v).replace(',', '.'));
      if (!isFinite(n)) return null;
      const dedans = c.minimum !== undefined ? n >= c.minimum : Math.abs(n - c.cible) <= c.tolerance;
      return dedans ? { niveau: 'ok', libelle: n + ' ' + c.unite } : Object.assign({}, c.hors, { libelle: n + ' ' + c.unite });
    }
    return c.options.find(o => o.cle === v) || null;
  }
  const RANG = { vente: 0, declasser: 1, jeter: 2 };
  function bilan() {
    const n = { ok: 0, mineur: 0, majeur: 0 }, ecarts = [], actions = [];
    let decision = 'vente', reclamation = false;
    criteres().forEach(c => {
      const e = evaluer(c, S.rep[c.cle]);
      if (!e) return;
      n[e.niveau]++;
      if (e.niveau === 'ok') return;
      ecarts.push({ c, e });
      if (RANG[e.decision] > RANG[decision]) decision = e.decision;
      if (e.reclamation) reclamation = true;
      const a = actions.find(x => x.t === e.prochaine);
      if (a) a.de.push(c.libelle); else if (e.prochaine) actions.push({ t: e.prochaine, de: [c.libelle] });
    });
    return { n, ecarts, actions, decision, reclamation, pire: n.majeur ? 'majeur' : n.mineur ? 'mineur' : 'ok' };
  }
  const obligatoires = e => criteres().filter(c => c.etape === e && !c.facultatif);
  const etapeFaite = e => obligatoires(e).every(c => S.rep[c.cle] !== undefined) && (S.d.grille.photos[e] !== 'obligatoire' || S.photos[e]);
  const pireEtape = e => {
    const niv = criteres().filter(c => c.etape === e).map(c => (evaluer(c, S.rep[c.cle]) || {}).niveau);
    return niv.includes('majeur') ? 'majeur' : niv.includes('mineur') ? 'mineur' : 'ok';
  };
  // Une étape sans critère (épicerie : seulement la présentation) n'est pas montrée.
  const etapesVues = () => etapes().filter(e => criteres().some(c => c.etape === e.cle));

  /* ── Les dessins : ils représentent le produit et gardent ses couleurs (§10.5) ───────────────────────── */
  const OR = '#d98b35', BRUN = '#8a4a24', MIE = '#f6e3b4', FOND = '#faf7f2', SOL = '#d9d1c6';
  const svg = (inner, vb = '0 0 120 66') => `<svg viewBox="${vb}" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">${inner}</svg>`;
  const dome = h => `M10 56 C10 ${56 - h * 1.25}, 110 ${56 - h * 1.25}, 110 56 Z`;
  const sol = `<rect width="120" height="66" fill="${FOND}"/><line x1="4" y1="57" x2="116" y2="57" stroke="${SOL}" stroke-width="2"/>`;
  function alveoles(cx, cy, rx, ry, n, seed) {
    let s = seed, out = '';
    const r = () => ((s = (s * 9301 + 49297) % 233280) / 233280);
    for (let i = 0; i < n; i++) {
      const a = r() * Math.PI * 2, k = Math.sqrt(r());
      out += `<ellipse cx="${(cx + Math.cos(a) * rx * k).toFixed(1)}" cy="${(cy + Math.sin(a) * ry * k).toFixed(1)}" rx="${(1.2 + r() * 2.4).toFixed(1)}" ry="${(1 + r() * 1.6).toFixed(1)}" fill="#e2c486"/>`;
    }
    return out;
  }
  const DESSIN = {
    volume(o) {
      const h = { bas: 16, juste: 30, haut: 42 }[o.dessin] || 30;
      const ref = o.dessin === 'juste' ? '' : `<path d="${dome(30)}" fill="none" stroke="#3f7a52" stroke-width="1.6" stroke-dasharray="4 3"/>`;
      return svg(`${sol}<path d="${dome(h)}" fill="${OR}" stroke="${BRUN}" stroke-width="2"/>${ref}`);
    },
    retombe(v) {
      const d = v === 'oui' ? 'M10 56 C10 18, 44 16, 50 32 Q60 46 70 32 C76 16, 110 18, 110 56 Z' : dome(30);
      return svg(`${sol}<path d="${d}" fill="${OR}" stroke="${BRUN}" stroke-width="2"/>`);
    },
    forme(o) {
      const f = {
        reguliere: '<circle cx="22" cy="33" r="13"/><circle cx="98" cy="33" r="13"/><ellipse cx="60" cy="33" rx="32" ry="21"/>',
        asymetrique: '<circle cx="25" cy="25" r="10"/><circle cx="97" cy="41" r="15"/><ellipse cx="60" cy="33" rx="31" ry="19" transform="rotate(14 60 33)"/>',
        eclatee: '<circle cx="22" cy="33" r="13"/><circle cx="98" cy="33" r="13"/><ellipse cx="60" cy="33" rx="32" ry="21"/>',
      }[o.dessin] || '';
      const fente = o.dessin === 'eclatee' ? `<path d="M40 27 Q52 20 62 31 T84 36" fill="none" stroke="${MIE}" stroke-width="5" stroke-linecap="round"/>` : '';
      return svg(`<rect width="120" height="66" fill="${FOND}"/><g fill="${OR}" stroke="${BRUN}" stroke-width="2">${f}</g>${fente}`);
    },
    croute(o) {
      const e = { 1: 2.5, 2: 5.5, 3: 9.5 }[o.epaisseur] || 2.5;
      return svg(`<rect width="120" height="66" fill="${FOND}"/><ellipse cx="60" cy="34" rx="50" ry="27" fill="${BRUN}"/><ellipse cx="60" cy="34" rx="${50 - e}" ry="${27 - e}" fill="${MIE}"/>` + alveoles(60, 34, 50 - e - 6, 27 - e - 5, 14, 7));
    },
    souplesse(o) {
      const d = { 1: 17, 2: 11, 3: 6, 4: 2.5, 5: 0 }[o.souplesse] ?? 6;
      return svg(`<rect width="120" height="66" fill="${FOND}"/><path d="M6 40 L36 40 Q60 ${40 + d * 2} 84 40 L114 40 L114 62 L6 62 Z" fill="${MIE}" stroke="${BRUN}" stroke-width="2"/>`
        + `<rect x="52" y="${40 + d - 34}" width="16" height="34" rx="8" fill="#e9c7a8" stroke="#a57b5a" stroke-width="1.5"/>`
        + `<path d="M46 ${d} l-6 -4 M74 ${d} l6 -4" stroke="#7a736a" stroke-width="2" stroke-linecap="round"/>`);
    },
  };
  // Les teintes de la photo de référence : pâle, dorée, foncée, brûlée (des vraies photos de défauts les remplaceront).
  const TEINTE = { pale: 'brightness(1.3) saturate(.42) contrast(.88)', dore: 'none', fonce: 'brightness(.66) saturate(1.15) contrast(1.15)', brule: 'brightness(.34) saturate(.7) contrast(1.4)' };
  const photoRef = () => (S.d && S.d.produit.photo ? '../' + S.d.produit.photo : '');
  function visuel(c, o) {
    if (c.visuel === 'teinte') return photoRef() ? `<span class="aq-vis"><img src="${esc(photoRef())}" alt="" style="filter:${TEINTE[o.teinte] || 'none'}"></span>` : '';
    if (DESSIN[c.visuel] && c.visuel !== 'retombe') return `<span class="aq-vis">${DESSIN[c.visuel](o)}</span>`;
    if (o.icone) return ICO(o.icone, 20);
    return '';
  }

  /* ── Les photos : appareil de la tablette, réduite à 1600 px avant l'envoi (comme le planogramme) ─────── */
  function prendrePhoto(etape) {
    const inp = document.createElement('input');
    inp.type = 'file'; inp.accept = 'image/*'; inp.setAttribute('capture', 'environment'); inp.className = 'aq-fichier';
    inp.addEventListener('change', () => {
      const f = inp.files && inp.files[0];
      if (!f) return;
      const img = new Image();
      img.onload = () => {
        const k = Math.min(1, 1600 / Math.max(img.width, img.height));
        const cv = document.createElement('canvas');
        cv.width = Math.round(img.width * k); cv.height = Math.round(img.height * k);
        cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
        S.photos[etape] = { data: cv.toDataURL('image/jpeg', 0.82), le: hhmm(new Date()) };
        URL.revokeObjectURL(img.src); inp.remove(); rendre();
      };
      img.onerror = () => { S.erreur = 'Photo illisible : reprenez-la.'; inp.remove(); rendre(); };
      img.src = URL.createObjectURL(f);
    });
    document.body.append(inp);
    inp.click();
  }
  const figPhoto = (etape, legende) => `<figure class="aq-fig" data-aq-photo="${etape}"><img src="${S.photos[etape].data}" alt=""><figcaption>${esc(legende)} · ${S.photos[etape].le}</figcaption></figure>`;
  const boutonPhoto = (etape, txt, sous) => `<button type="button" class="aq-shot" data-aq-photo="${etape}">${ICO('appareil', 24)}${esc(txt)}${sous || ''}</button>`;
  const figRef = (legende, contenu) => contenu
    ? `<figure class="aq-fig">${contenu}<figcaption>${esc(legende)}</figcaption></figure>`
    : `<figure class="aq-fig vide">Pas de photo de référence au catalogue</figure>`;

  /* ── La liste des produits ───────────────────────────────────────────────────────────────────────────── */
  function rendreListe() {
    const l = S.liste;
    const r = S.recherche.trim().toLowerCase();
    const prods = (l ? l.produits : []).filter(p => !r || (p.nom + ' ' + p.categorie + ' ' + (p.grilleNom || '')).toLowerCase().includes(r));
    const groupes = {};
    prods.forEach(p => { (groupes[p.grilleNom || 'Sans grille : critères communs'] = groupes[p.grilleNom || 'Sans grille : critères communs'] || []).push(p); });
    const carte = p => `<button type="button" class="aq-prod" data-aq-produit="${p.id}">${esc(p.nom)}<small>${esc(p.categorie || '—')}</small>
      ${p.aujourdhui ? etat(p.aujourdhui.niveau, (NIV[p.aujourdhui.niveau]) + ' à ' + p.aujourdhui.le) : ''}</button>`;
    app.innerHTML = `<div class="aq-ov">
      <header class="aq-head">${S.cadre ? `<button type="button" class="aq-btn aq-btn--icon" data-aq="fermer" aria-label="Revenir à la visite">${ICO('retour', 18)}</button>` : ''}
        <div class="aq-titre"><p class="aq-kicker">${S.visite ? 'Contrôle qualité de la visite' : 'Autocontrôle qualité'} · ${esc(l && l.magasin ? l.magasin.nom : 'Magasin ' + S.shop)}</p><h1>Quel produit contrôlez-vous ?</h1></div>
        <span class="aq-duree">${ICO('horloge', 16)} 1 min par produit</span></header>
      <main class="aq-body">${bandeaux(l && l.aValider)}
        <input type="search" class="aq-recherche" placeholder="Rechercher un produit, une catégorie…" value="${esc(S.recherche)}" data-aq-recherche aria-label="Rechercher un produit">
        ${!l ? '<p class="aq-vide">Lecture du catalogue…</p>' : prods.length ? Object.keys(groupes).map(g => `<p class="aq-label aq-groupe">${esc(g)} · ${groupes[g].length}</p><div class="aq-liste">${groupes[g].map(carte).join('')}</div>`).join('')
          : '<p class="aq-vide">Aucun produit ne correspond.</p>'}
      </main></div>`;
  }
  function bandeaux(aValider) {
    return (S.visite ? `<p class="aq-bandeau visite">${ICO('info', 16)} Contrôle de la visite du consultant : il entre dans le rapport de la visite, avec ses photos.</p>` : '')
      + (S.essai ? `<p class="aq-bandeau essai">${ICO('info', 16)} Mode essai : le contrôle est enregistré à part, il ne compte ni dans l'historique ni dans les résultats du réseau.</p>` : '')
      + (aValider ? `<p class="aq-bandeau valider">${ICO('alerte', 16)} Grilles d'exemple : critères et corrections à valider par la production avant usage en magasin.</p>` : '')
      + (S.erreur ? `<p class="aq-bandeau erreur" role="alert">${ICO('alerte', 16)} ${esc(S.erreur)}</p>` : '');
  }

  /* ── Le parcours ─────────────────────────────────────────────────────────────────────────────────────── */
  function rendreParcours() {
    const d = S.d;
    const vues = etapesVues();
    const steps = vues.map((e, i) => {
      const nb = obligatoires(e.cle);
      const n = nb.filter(c => S.rep[c.cle] !== undefined).length;
      const faite = etapeFaite(e.cle) && S.ecran !== e.cle;
      const pire = faite ? pireEtape(e.cle) : '';
      return `<button type="button" class="aq-step${S.ecran === e.cle ? ' is-cur' : ''}${faite ? ' is-done ' + pire : ''}" data-aq-aller="${e.cle}"${faite ? ` title="${NIV[pire]}"` : ''}>
        <span class="aq-num">${faite ? (pire === 'ok' ? ICO('coche', 14) : '!') : i + 1}</span>${esc(e.titre)}<em>${n}/${nb.length}</em></button>`;
    }).join('') + `<button type="button" class="aq-step${S.ecran === 'bilan' ? ' is-cur' : ''}" data-aq-aller="bilan"><span class="aq-num">${ICO('drapeau', 13)}</span>Bilan</button>`;
    const garde = app.querySelector('.aq-body') ? app.querySelector('.aq-body').scrollTop : 0;
    app.innerHTML = `<div class="aq-ov">
      <header class="aq-head">
        <button type="button" class="aq-btn aq-btn--icon" data-aq="liste" aria-label="Revenir à la liste des produits">${ICO('retour', 18)}</button>
        <div class="aq-titre"><p class="aq-kicker">Autocontrôle qualité · ${esc(d.grille.libelle || 'Tous les produits')} · ${esc(d.magasin.nom)}</p><h1>${esc(d.produit.nom)}</h1></div>
        <span class="aq-duree">${ICO('horloge', 16)} 1 min</span>
      </header>
      <nav class="aq-steps">${steps}</nav>
      <div class="aq-ctx"><div class="aq-ctx-champs">
        <label>${ICO('flamme', 16)} Fournée sortie à <input class="aq-champ" type="time" value="${esc(S.fournee)}" data-aq-champ="fournee"></label>
        <label><input class="aq-champ" type="number" min="1" inputmode="numeric" placeholder="—" value="${esc(S.pieces)}" data-aq-champ="pieces"> pièces</label>
        <label>${ICO('personne', 16)} <input class="aq-champ qui" type="text" placeholder="Votre prénom" value="${esc(S.qui)}" data-aq-champ="qui"></label>
        ${S.visite ? '<span class="aq-roles"><span class="aq-role is-on">Consultant</span></span>'
          : `<span class="aq-roles" role="group" aria-label="Qui contrôle">${ROLES.map(([k, n]) => `<button type="button" class="aq-role${S.role === k ? ' is-on' : ''}" data-aq-role="${k}" aria-pressed="${S.role === k}">${n}</button>`).join('')}</span>`}
      </div></div>
      <main class="aq-body">${bandeaux(d.aValider)}${S.ecran === 'bilan' ? corpsBilan() : corpsEtape(S.ecran)}</main>
      <footer class="aq-foot">${pied()}</footer></div>`;
    app.querySelector('.aq-body').scrollTop = garde;
  }

  function corpsEtape(e) {
    const photo = S.d.grille.photos[e];
    let h = '';
    if (photo !== 'non' && e !== 'presentation') {
      const consigne = (S.d.grille.consignes_photo || {})[e] || '';
      const req = S.photos[e] ? etat('ok', 'Photo prise à ' + S.photos[e].le) : photo === 'obligatoire' ? etat('mineur', 'Photo obligatoire') : etat('neutre', 'Photo facultative');
      h += `<section class="aq-card aq-photos">
        ${e === 'cuisson' ? figRef('Référence', photoRef() ? `<img src="${esc(photoRef())}" alt="">` : '') : ''}
        ${S.photos[e] ? figPhoto(e, 'Votre photo') : boutonPhoto(e, 'Prendre la photo')}
        <div class="aq-photos-t"><span>${e === 'cuisson' ? 'Photo du produit' : e === 'structure' ? 'Photo de la coupe' : 'Photo'}</span><p>${esc(consigne)}</p>${req}</div>
      </section>`;
    }
    const liste = criteres().filter(c => c.etape === e);
    const premier = liste.find(c => S.rep[c.cle] === undefined && !c.facultatif);
    liste.forEach((c, i) => { h += critere(c, i + 1, c === premier && liste.some(x => S.rep[x.cle] !== undefined)); });
    return h;
  }

  function critere(c, num, todo) {
    const v = S.rep[c.cle];
    const ev = evaluer(c, v);
    const niv = ev ? ev.niveau : '';
    const marque = ICO('coche', 14).replace('class="aq-ico"', 'class="aq-ico aq-opt-cible"');
    const bouton = (val, contenu, estCible) => `<button type="button" class="aq-opt${v === val ? ' is-sel' : ''}" data-aq-rep="${esc(c.cle)}" data-v="${esc(val)}"${estCible ? ' title="Attendu"' : ''}>${estCible ? marque : ''}${contenu}</button>`;
    let ciblTxt = '', opts = '';
    if (c.type === 'echelle') {
      ciblTxt = (c.options.find(o => o.cible) || {}).libelle || '';
      opts = `<div class="aq-opts" style="--n:${c.options.length}">${c.options.map(o => bouton(o.cle, `${visuel(c, o)}<span>${esc(o.libelle)}</span>`, o.cible)).join('')}</div>`;
    } else if (c.type === 'oui_non') {
      ciblTxt = c.attendu === 'oui' ? 'Oui' : 'Non';
      const b = (val, ico) => bouton(val, `${c.visuel === 'retombe' ? `<span class="aq-vis">${DESSIN.retombe(val)}</span>` : ICO(ico, 20)}<span>${val === 'oui' ? 'Oui' : 'Non'}</span>`, val === c.attendu);
      opts = `<div class="aq-opts" style="--n:2">${b('oui', 'coche')}${b('non', 'croix')}</div>`;
    } else if (c.type === 'mesure') {
      const bas = c.minimum !== undefined ? c.minimum : c.cible - c.tolerance, haut = c.minimum !== undefined ? c.minimum * 1.5 : c.cible + c.tolerance;
      const min = c.minimum !== undefined ? c.minimum * 0.5 : c.cible - c.tolerance * 2, max = c.minimum !== undefined ? c.minimum * 1.5 : c.cible + c.tolerance * 2;
      const pc = x => ((Math.max(min, Math.min(max, x)) - min) / (max - min) * 100).toFixed(1) + '%';
      ciblTxt = c.minimum !== undefined ? `${c.minimum} ${c.unite} minimum` : `${bas} à ${haut} ${c.unite}`;
      const n = Number(String(v || '').replace(',', '.'));
      opts = `<div class="aq-mes"><label class="aq-mes-in"><input type="text" inputmode="decimal" value="${esc(v ?? '')}" placeholder="—" data-aq-mes="${esc(c.cle)}" aria-label="${esc(c.libelle)}"><span>${esc(c.unite)}</span></label>
        <div class="aq-mes-g"><div class="aq-mes-bar"><span class="aq-mes-zone" style="left:${pc(bas)};right:${(100 - parseFloat(pc(haut))).toFixed(1)}%"></span>
          ${v ? `<span class="aq-mes-pt" style="left:${pc(n)}"></span>` : ''}</div>
          <div class="aq-mes-leg"><span>${min}</span><span>${bas}</span><span>${haut}</span><span>${max} ${esc(c.unite)}</span></div>
          <div class="aq-mes-st">${!v ? etat('neutre', c.facultatif ? 'Facultatif' : 'À mesurer') : niv === 'ok' ? etat('ok', 'Dans la tolérance') : etat(niv, 'Hors tolérance')}</div></div></div>`;
    }
    const avant = c.visuel === 'comparaison' ? comparaison() : c.visuel === 'planogramme' ? planogramme() : '';
    const corr = ev && niv !== 'ok' ? `<div class="aq-corr ${niv}">
        <p class="aq-corr-t">${ICO('outil', 15)} Correction</p>
        <dl><dt>Cause probable</dt><dd>${esc(ev.cause)}</dd><dt>Maintenant</dt><dd>${esc(ev.maintenant)}</dd><dt>Prochaine fournée</dt><dd>${esc(ev.prochaine)}</dd></dl>
        ${ev.sop || ev.reclamation ? `<div class="aq-liens">${ev.sop ? `<span class="aq-btn aq-btn--sm">${ICO('lecture', 15)} Revoir le geste : ${esc(ev.sop)}</span>` : ''}${ev.reclamation ? `<span class="aq-btn aq-btn--sm">${ICO('reclamation', 15)} Proposé en réclamation au bilan</span>` : ''}</div>` : ''}
      </div>` : '';
    return `<section class="aq-card${todo ? ' is-todo' : ''}" id="aq-${esc(c.cle)}">
      <div class="aq-crit-h"><span class="aq-num">${num}</span><h3>${esc(c.libelle)}${c.facultatif ? ' <small>facultatif</small>' : ''}${ev ? etat(niv) : ''}</h3>
        <span class="aq-cible">${ICO('cible', 14)} Attendu : ${esc(ciblTxt)}</span></div>
      ${c.aide ? `<p class="aq-aide">${esc(c.aide)}</p>` : ''}${avant}${opts}${corr}</section>`;
  }

  // La photo de la fiche à côté de celle du produit (prise à l'étape Cuisson, ou ici).
  function comparaison() {
    const moi = S.photos.cuisson ? figPhoto('cuisson', 'Votre produit') : boutonPhoto('cuisson', 'Prendre la photo du produit');
    return `<div class="aq-comp">${figRef('Référence · catalogue', photoRef() ? `<img src="${esc(photoRef())}" alt="">` : '')}${moi}</div>`;
  }
  // La section du planogramme standard où le produit est posé, étage par étage ; la sienne liserée de bordeaux.
  function planogramme() {
    const p = S.d.planogramme;
    const photo = S.d.grille.photos.presentation;
    const vitrine = S.photos.presentation ? figPhoto('presentation', 'Vitrine') : boutonPhoto('presentation', 'Photo de la vitrine', etat('neutre', photo === 'obligatoire' ? 'obligatoire' : 'facultative'));
    if (!p) {
      return `<div class="aq-plano" style="grid-template-columns:1fr 120px"><div class="aq-plano-t"><p class="aq-label" style="margin:0">Planogramme du magasin</p>
        <span>Ce produit n'est pas posé au planogramme standard du jour.</span><span>Vérifiez qu'il est en vitrine, à la place prévue par le responsable.</span></div>${vitrine}</div>`;
    }
    const etages = `<div class="aq-etages">${p.etages.map(e => `<div class="aq-etage${e.moi ? ' moi' : ''}"><span>${esc(e.nom)}</span><span>${esc(e.produits.join(' · ') || '—')}</span></div>`).join('')}</div>`;
    return `<div class="aq-plano"><div>${etages}</div>
      <div class="aq-plano-t"><p class="aq-label" style="margin:0">Planogramme du magasin</p><span>${esc(p.zone)}</span><span>Section ${p.section}, ${esc(p.etage.toLowerCase())}</span>
        ${p.facade ? etat('neutre', p.facade + ' pièces en façade') : ''}</div>${vitrine}</div>`;
  }

  function corpsBilan() {
    const r = bilan();
    const dec = S.decision || r.decision;
    const titre = { vente: r.ecarts.length ? 'Vendable, à corriger' : 'Conforme, en vente', declasser: 'Déclasser ce lot', jeter: 'Ne pas vendre ce lot' }[r.decision];
    const prevenir = S.prevenir ?? r.n.majeur > 0, reclamer = S.reclamer ?? r.reclamation;
    const lot = S.pieces ? S.pieces + ' pièces' : 'le lot';
    const decBtn = (k, ico, sous) => `<button type="button" class="aq-opt${dec === k ? ' is-sel' : ''}" data-aq-dec="${k}">${r.decision === k ? '<span class="aq-badge">Suggéré</span>' : ''}${ICO(ico, 20)}<span>${esc((S.d.decisions[k] || k).split(' (')[0])}</span><small>${sous}</small></button>`;
    const photos = ['cuisson', 'structure', 'presentation'].filter(e => S.photos[e]).map(e => `<figure class="aq-fig"><img src="${S.photos[e].data}" alt=""></figure>`);
    const sw = (k, on, t, sous) => `<button type="button" class="aq-switch${on ? ' is-on' : ''}" data-aq-tog="${k}" aria-pressed="${on}"><span class="aq-switch-t">${t}<small>${sous}</small></span><span class="aq-track"></span></button>`;
    return `
      <section class="aq-card"><div class="aq-bilan-h"><div>
          <p class="aq-kicker">Bilan${S.fournee ? ' · fournée de ' + esc(S.fournee) : ''}${S.pieces ? ' · ' + esc(S.pieces) + ' pièces' : ''}</p>
          <h2>${titre} ${etat(r.pire)}</h2>
          <p>${S.qui ? 'Contrôlé par ' + esc(S.qui) + ', ' : ''}${etapesVues().length} étapes, ${r.n.ok + r.n.mineur + r.n.majeur} critères.</p></div></div>
        <div class="aq-kpis">
          <div class="aq-kpi"><b>${r.n.ok}</b><span><i class="aq-dot ok"></i>conformes</span></div>
          <div class="aq-kpi"><b>${r.n.mineur}</b><span><i class="aq-dot mineur"></i>à surveiller</span></div>
          <div class="aq-kpi"><b>${r.n.majeur}</b><span><i class="aq-dot majeur"></i>non conformes</span></div>
        </div></section>
      <section class="aq-card"><p class="aq-label">Que fait-on de ${esc(lot)} ?</p>
        <div class="aq-dec">${decBtn('vente', 'vitrine', 'en vitrine')}${decBtn('declasser', 'etiquette', 'vente du jour, prix réduit')}${decBtn('jeter', 'poubelle', 'à encoder en pertes')}</div></section>
      ${r.actions.length ? `<section class="aq-card"><p class="aq-label">Pour la prochaine fournée</p>
        ${r.actions.map(a => `<div class="aq-act"><span class="aq-case">${ICO('info', 14).replace('class="aq-ico"', 'class="aq-ico" style="color:var(--muted)"')}</span>
          <div class="aq-act-t">${esc(a.t)}<div class="aq-tags">${a.de.map(x => etat('neutre', x)).join('')}</div></div></div>`).join('')}</section>` : ''}
      <div class="aq-2col">
        <section class="aq-card"><p class="aq-label">Les écarts</p>${r.ecarts.length ? r.ecarts.map(l => `<div class="aq-ecart"><i class="aq-dot ${l.e.niveau}"></i>
          <div class="aq-ecart-t">${esc(l.c.libelle)} : ${esc(l.e.libelle)}<p>${esc(l.e.cause)}</p></div>${etat(l.e.niveau)}</div>`).join('') : `<p style="margin:0">${etat('ok', 'Aucun écart : tout est à la cible')}</p>`}</section>
        <section class="aq-card"><p class="aq-label">Photos et suites</p>
          ${photos.length ? `<div class="aq-mini">${photos.join('')}</div>` : ''}
          ${sw('prevenir', prevenir, 'Prévenir le responsable', r.n.majeur ? 'Conseillé : une non-conformité' : 'Pas nécessaire')}
          ${sw('reclamer', reclamer, 'Réclamation à la production centrale', r.reclamation ? 'Conseillé : défaut du produit cru' : 'Défaut lié à la cuisson, pas au cru')}</section>
      </div>`;
  }

  function pied() {
    if (S.ecran === 'bilan') {
      const r = bilan();
      const vues = etapesVues();
      const manque = criteres().filter(c => !c.facultatif && S.rep[c.cle] === undefined).length;
      const photoManque = vues.find(e => S.d.grille.photos[e.cle] === 'obligatoire' && !S.photos[e.cle]);
      const pret = !manque && !photoManque && !S.envoi;
      return `<button type="button" class="aq-btn" data-aq-aller="${vues[vues.length - 1].cle}">${ICO('retour', 16)} Modifier</button>
        <button type="button" class="aq-btn aq-btn--primary" data-aq="valider"${pret ? '' : ' disabled'}>${S.envoi ? 'Enregistrement…' : manque ? `Encore ${manque} critère${manque > 1 ? 's' : ''}` : photoManque ? 'Photo obligatoire manquante' : `Valider l'autocontrôle <small>· ${ecartsTxt(r.ecarts.length)}</small>`}</button>`;
    }
    const vues = etapesVues();
    const i = vues.findIndex(x => x.cle === S.ecran);
    const liste = obligatoires(S.ecran);
    const n = liste.filter(c => S.rep[c.cle] !== undefined).length;
    const reste = liste.length - n;
    const suivant = vues[i + 1];
    const manquePhoto = S.d.grille.photos[S.ecran] === 'obligatoire' && !S.photos[S.ecran];
    const pret = !reste && !manquePhoto;
    return `<div class="aq-prog"><span>${n} / ${liste.length} répondus</span><span class="aq-prog-bar"><i style="width:${liste.length ? (n / liste.length * 100).toFixed(0) : 100}%"></i></span></div>
      ${reste ? `<button type="button" class="aq-btn" data-aq="conforme">${ICO('double-coche', 18)} Le reste est conforme <small>(${reste})</small></button>` : ''}
      <button type="button" class="aq-btn aq-btn--primary" data-aq-aller="${suivant ? suivant.cle : 'bilan'}"${pret ? '' : ' disabled'}>
        ${pret ? `Suivant : ${esc(suivant ? suivant.titre : 'Bilan')} ${ICO('suivant', 18)}` : manquePhoto ? `${ICO('appareil', 18)} Photo obligatoire` : `Encore ${reste} critère${reste > 1 ? 's' : ''}`}</button>`;
  }

  /* ── Après l'enregistrement ──────────────────────────────────────────────────────────────────────────── */
  function rendreFait() {
    const f = S.fait;
    const dec = { vente: 'mis en vente', declasser: 'déclassé', jeter: 'à jeter (à encoder en pertes)' }[f.decision];
    const histo = (S.d.historique || []).slice(0, 7);
    app.innerHTML = `<div class="aq-ov"><main class="aq-body"><div class="aq-fin">
      ${ICO('controle', 40).replace('class="aq-ico"', 'class="aq-ico" style="margin:0 auto;color:var(--green)"')}
      <h2>${f.visite ? 'Contrôle enregistré dans la visite' : 'Autocontrôle enregistré'}${f.essai ? ' (essai)' : ''}</h2>
      <p class="aq-note" style="justify-content:center;margin:0">${esc(S.d.produit.nom)} · lot ${dec} · ${ecartsTxt(f.bilan.ecarts.length)}${(S.prevenir ?? f.bilan.n.majeur > 0) ? ' · le responsable est prévenu' : ''}</p>
      ${f.bilan.actions.length ? `<section class="aq-card" style="margin-top:18px"><p class="aq-label">Pour la prochaine fournée</p>${f.bilan.actions.map(a => `<div class="aq-act"><span class="aq-case" style="background:#fff">${ICO('info', 14).replace('class="aq-ico"', 'class="aq-ico" style="color:var(--muted)"')}</span><div class="aq-act-t">${esc(a.consigne)}</div></div>`).join('')}</section>` : ''}
      ${histo.length ? `<p class="aq-note" style="justify-content:center;margin-top:14px">${histo.length} contrôle${histo.length > 1 ? 's' : ''} de ce produit sur 7 jours${(S.d.recurrents || []).length ? ' · défaut qui revient : ' + esc(S.d.recurrents.map(x => x.libelle + ' (' + x.fois + ' fois)').join(', ')) : ''}</p>` : ''}
      <div class="aq-fin-actions"><button type="button" class="aq-btn" data-aq="refaire">${ICO('repeter', 16)} Recontrôler ce produit</button>
        <button type="button" class="aq-btn${S.cadre ? '' : ' aq-btn--primary'}" data-aq="liste">Contrôler un autre produit ${ICO('suivant', 16)}</button>
        ${S.cadre ? `<button type="button" class="aq-btn aq-btn--primary" data-aq="fermer">Revenir à la visite ${ICO('suivant', 16)}</button>` : ''}</div>
    </div></main></div>`;
  }

  /* ── Rendre, charger, réagir ─────────────────────────────────────────────────────────────────────────── */
  function rendre() {
    if (S.ecran === 'liste') rendreListe();
    else if (S.ecran === 'fait') rendreFait();
    else if (S.d) rendreParcours();
    document.documentElement.dataset.pret = '1';
  }
  function chargerListe() {
    S.ecran = 'liste'; S.d = null; rendre();
    lire('/autocontrole/magasin/produits?shop=' + encodeURIComponent(S.shop))
      .then(l => { S.liste = l; S.erreur = ''; rendre(); const i = app.querySelector('[data-aq-recherche]'); if (i && !S.recherche) i.focus(); })
      .catch(e => { S.erreur = e.message; S.liste = { produits: [], magasin: null }; rendre(); });
  }
  function ouvrir(pid) {
    S.erreur = '';
    lire('/autocontrole/magasin/produit?shop=' + encodeURIComponent(S.shop) + '&produit=' + encodeURIComponent(pid))
      .then(d => {
        S.d = d; S.rep = {}; S.photos = {}; S.decision = null; S.prevenir = S.reclamer = null; S.fait = null; S.clientId = nouvelId();
        // La fournée : sortie il y a 20 minutes, le moment conseillé pour contrôler (corrigeable).
        S.fournee = S.fournee || hhmm(new Date(Date.now() - 20 * 60000));
        S.ecran = (etapesVues()[0] || { cle: 'bilan' }).cle;
        const u = new URL(location.href); u.searchParams.set('produit', pid); history.replaceState(null, '', u);
        rendre(); window.scrollTo(0, 0);
      })
      .catch(e => { S.erreur = e.message; if (!S.liste) chargerListe(); else rendre(); });
  }
  function valider() {
    const r = bilan();
    S.envoi = true; S.erreur = ''; rendre();
    const photos = {};
    Object.keys(S.photos).forEach(e => { photos[e] = S.photos[e].data; });
    ecrire('/autocontrole/magasin/controles', {
      shop: S.shop, produit: S.d.produit.id, fournee: S.fournee, pieces: S.pieces ? Number(S.pieces) : null, auteur: S.qui,
      reponses: S.rep, photos, decision: S.decision || r.decision, prevenir: S.prevenir ?? r.n.majeur > 0, reclamer: S.reclamer ?? r.reclamation,
      essai: S.essai, visite: S.visite || null, role: S.role || null, client_id: S.clientId,
    }).then(res => {
      S.envoi = false; S.fait = res; S.ecran = 'fait';
      aLaVisite({ type: 'aq-fait', id: res.id });
      // L'historique et les défauts qui reviennent, relus avec ce contrôle.
      return lire('/autocontrole/magasin/produit?shop=' + encodeURIComponent(S.shop) + '&produit=' + S.d.produit.id).then(d => { S.d.historique = d.historique; S.d.recurrents = d.recurrents; }, () => {});
    }).then(() => rendre())
      .catch(e => { S.envoi = false; S.erreur = 'Pas enregistré : ' + e.message + '. Vos réponses sont gardées, réessayez.'; rendre(); });
  }

  app.addEventListener('click', ev => {
    const t = ev.target.closest('[data-aq],[data-aq-rep],[data-aq-aller],[data-aq-photo],[data-aq-dec],[data-aq-tog],[data-aq-produit],[data-aq-role]');
    if (!t || t.disabled) return;
    const d = t.dataset;
    if (d.aqProduit) { ouvrir(d.aqProduit); return; }
    if (d.aq === 'fermer') { aLaVisite({ type: 'aq-fermer' }); return; }
    if (d.aqRole) { S.role = d.aqRole; try { localStorage.setItem('aq:role', S.role); } catch (e) { /* navigation privée */ } rendre(); return; }
    if (d.aq === 'liste') { const u = new URL(location.href); u.searchParams.delete('produit'); history.replaceState(null, '', u); chargerListe(); return; }
    if (d.aq === 'refaire') { ouvrir(S.d.produit.id); return; }
    if (d.aq === 'valider') { valider(); return; }
    if (d.aq === 'conforme') {
      criteres().filter(c => c.etape === S.ecran && !c.facultatif && S.rep[c.cle] === undefined).forEach(c => { S.rep[c.cle] = cible(c); });
    } else if (d.aqRep) {
      S.rep[d.aqRep] = d.v;
      const c = criteres().find(x => x.cle === d.aqRep);
      const e = evaluer(c, d.v);
      rendre();
      // Réponse conforme : on file au critère suivant sans faire défiler à la main.
      if (e && e.niveau === 'ok') {
        const suiv = criteres().filter(x => x.etape === c.etape).find(x => S.rep[x.cle] === undefined && !x.facultatif);
        if (suiv) { const el = document.getElementById('aq-' + suiv.cle); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      }
      return;
    } else if (d.aqAller) { S.ecran = d.aqAller; rendre(); app.querySelector('.aq-body').scrollTop = 0; return; }
    else if (d.aqPhoto) { prendrePhoto(d.aqPhoto); return; }
    else if (d.aqDec) { S.decision = d.aqDec; }
    else if (d.aqTog) { const r = bilan(); const cur = S[d.aqTog] ?? (d.aqTog === 'prevenir' ? r.n.majeur > 0 : r.reclamation); S[d.aqTog] = !cur; }
    rendre();
  });
  app.addEventListener('input', ev => {
    const t = ev.target;
    if (t.dataset.aqRecherche !== undefined) {
      S.recherche = t.value; const pos = t.selectionStart; rendreListe();
      const i = app.querySelector('[data-aq-recherche]'); i.focus(); i.setSelectionRange(pos, pos);
    }
  });
  app.addEventListener('change', ev => {
    const t = ev.target;
    if (t.dataset.aqChamp) { S[t.dataset.aqChamp] = t.value.trim(); return; }
    if (t.dataset.aqMes) { const v = t.value.replace(/[^\d.,]/g, ''); if (v) S.rep[t.dataset.aqMes] = v; else delete S.rep[t.dataset.aqMes]; rendre(); }
  });

  /* ── Démarrer : le magasin de l'adresse, sinon le premier de la session (garde.js) ──────────────────── */
  function demarrer(essais) {
    // Qui contrôle, sans choix sur l'adresse : le dernier choix de cet appareil, sinon le franchisé connecté, sinon l'équipe.
    if (!ROLES.some(([k]) => k === S.role)) {
      let r = ''; try { r = localStorage.getItem('aq:role') || ''; } catch (e) { /* navigation privée */ }
      const a = window.ACCES;
      if (!a && essais > 0) { setTimeout(() => demarrer(essais - 1), 150); return; }
      S.role = ROLES.some(([k]) => k === r) ? r : (a && a.role === 'franchise' && a.source !== 'code' ? 'franchise' : 'equipe');
    }
    if (!S.shop) {
      const a = window.ACCES;
      if (a && a.shops && a.shops.length) { S.shop = String(a.shops[0]); if (!S.qui && a.role === 'franchise' && a.source !== 'code') S.qui = a.nom || ''; }
      else if (essais > 0 && !a) { setTimeout(() => demarrer(essais - 1), 150); return; }
      else {
        app.innerHTML = `<div class="aq-ov"><main class="aq-body"><p class="aq-bandeau erreur">${ICO('alerte', 16)} Quel magasin ? Ouvrez cette page avec ?shop= suivi du numéro du magasin.</p></main></div>`;
        document.documentElement.dataset.pret = '1';
        return;
      }
    }
    if (S.produitDemande) { S.liste = null; ouvrir(S.produitDemande); } else { chargerListe(); }
  }
  demarrer(40);
})();
