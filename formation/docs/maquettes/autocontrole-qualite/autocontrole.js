/* Autocontrôle qualité — maquette interactive.
 *
 * Au design system L'Atelier By (docs/DESIGN_SYSTEM.md) : voir autocontrole.css.
 * Greffée sur la fiche produit réelle de l'app Cuisine (contexte/fiche-corps.html, relevée sur une instance locale
 * du dépôt ThaiZu/TFB-Kitchen). La grille vient de grilles.json : une grille par catégorie de produit, surchargée
 * au besoin par produit (ici le poids du Cougnou Nature 400 g).
 *
 * L'écran se choisit dans l'adresse, pour les captures : fiche.html#ecran=cuisson&demo=ecarts
 *   ecran : fiche | cuisson | structure | organoleptique | presentation | bilan | fait
 *   demo  : (vide) aucune réponse · ecarts : un contrôle avec cinq écarts · conforme : tout est conforme
 *   defiler : clé d'un critère ou d'un bloc à amener en haut de l'écran
 * Sans paramètre, tout se clique : bouton, réponses, photos, « le reste est conforme », bilan, validation.
 */
(async function () {
  'use strict';

  /* ── Le contexte : la fiche réelle, puis la grille ─────────────────────── */
  const corps = await (await fetch('contexte/fiche-corps.html')).text();
  document.body.innerHTML = new DOMParser().parseFromString(corps, 'text/html').body.innerHTML;
  const G = await (await fetch('grilles.json')).json();

  const PID = '1700400';
  const PRODUIT = G.produits[PID];
  const CAT = G.categories.find(c => c.cle === PRODUIT.categorie);
  // Les critères de la catégorie, puis ceux de tous les produits (photo de référence, planogramme).
  const CRITERES = CAT.criteres.concat(G.criteres_communs || []).map(c => Object.assign({}, c, (PRODUIT.surcharges || {})[c.cle] || {}));
  const PLANO = PRODUIT.planogramme;
  const ETAPES = G.etapes;
  const PHOTO = 'contexte/cougnou.jpg';
  const ICO = window.AQ_ICO;

  // Ce que l'app connaît déjà : les fournées du jour (module Cuisson) et l'équipe en poste.
  const FOURNEES = [{ h: '07:40', n: 30, note: 'sortie du four il y a 25 min' }, { h: '10:15', n: 18, note: 'au four' }];
  const EQUIPE = ['Nathan', 'Aïcha', 'Ali'];
  // Les sept derniers jours de ce produit dans ce magasin (exemple).
  const HISTO = [
    { j: 'mar.', r: 'ok' }, { j: 'mer.', r: 'ok' }, { j: 'jeu.', r: 'mineur', d: 'mie_toucher' }, { j: 'ven.', r: 'ok' },
    { j: 'sam.', r: 'mineur', d: 'mie_toucher' }, { j: 'dim.', r: 'ok' }, { j: 'lun.', r: 'ok' },
  ];

  const DEMOS = {
    ecarts: {
      rep: {
        coloration_dessus: 'dore', coloration_dessous: 'dore', dorure: 'irreguliere', cuisson_coeur: 'cuit', sonne_creux: 'oui',
        volume: 'juste', retombe: 'oui', mie_toucher: 'mou', croute: 'fine', forme: 'reguliere', poids: '412',
        odeur: 'brioche', gout: 'conforme', mie_bouche: 'pateuse', vendeur: 'oui',
        ressemblance: 'proche', planogramme: 'oui',
      },
      photos: { cuisson: true, structure: true },
    },
    conforme: { rep: null, photos: { cuisson: true, structure: true } },
  };

  const p = new URLSearchParams(location.hash.slice(1));
  const S = {
    ecran: p.get('ecran') || 'fiche', rep: {}, photos: {}, fournee: 0, qui: 0,
    decision: null, coches: {}, prevenir: null, reclamer: null, resultat: null,
  };
  const demo = DEMOS[p.get('demo')];
  if (demo) {
    if (demo.rep) Object.assign(S.rep, demo.rep);
    else CRITERES.forEach(c => { S.rep[c.cle] = cible(c); });
    Object.assign(S.photos, demo.photos);
    // Sur l'écran d'une étape, les étapes suivantes ne sont pas encore faites ; sur la cuisson, il reste un critère.
    const i = ETAPES.findIndex(e => e.cle === S.ecran);
    if (i >= 0) {
      CRITERES.filter(c => ETAPES.findIndex(e => e.cle === c.etape) > i).forEach(c => delete S.rep[c.cle]);
      ETAPES.slice(i + 1).forEach(e => delete S.photos[e.cle]);
      if (S.ecran === 'cuisson') delete S.rep.sonne_creux;
    }
  }

  /* ── Les règles ────────────────────────────────────────────────────────── */
  function cible(c) {
    if (c.type === 'oui_non') return c.attendu;
    if (c.type === 'mesure') return String(c.cible ?? c.minimum);
    return (c.options.find(o => o.cible) || {}).cle;
  }
  // Ce que vaut une réponse : son niveau et sa correction.
  function eval_(c, v) {
    if (v === undefined || v === '') return null;
    if (c.type === 'oui_non') return v === c.attendu ? { niveau: 'ok' } : Object.assign({ libelle: v === 'oui' ? 'Oui' : 'Non' }, c[v]);
    if (c.type === 'mesure') {
      const n = Number(v);
      const dedans = c.minimum !== undefined ? n >= c.minimum : Math.abs(n - c.cible) <= c.tolerance;
      return dedans ? { niveau: 'ok', libelle: n + ' ' + c.unite } : Object.assign({ libelle: n + ' ' + c.unite }, c.hors);
    }
    return c.options.find(o => o.cle === v) || null;
  }
  const RANG = { vente: 0, declasser: 1, jeter: 2 };
  function bilan() {
    const lignes = CRITERES.map(c => ({ c, e: eval_(c, S.rep[c.cle]) })).filter(l => l.e);
    const ecarts = lignes.filter(l => l.e.niveau !== 'ok');
    const n = { ok: lignes.length - ecarts.length, mineur: 0, majeur: 0 };
    ecarts.forEach(l => { n[l.e.niveau]++; });
    const decision = ecarts.reduce((d, l) => (RANG[l.e.decision || 'vente'] > RANG[d] ? l.e.decision : d), 'vente');
    // Les réglages de la prochaine fournée, sans doublon : une même consigne regroupe ses critères.
    const actions = [];
    ecarts.forEach(l => {
      const a = actions.find(x => x.t === l.e.prochaine);
      if (a) a.de.push(l.c.libelle); else actions.push({ t: l.e.prochaine, de: [l.c.libelle] });
    });
    const pire = n.majeur ? 'majeur' : n.mineur ? 'mineur' : 'ok';
    return { lignes, ecarts, n, decision, actions, pire, reclamation: ecarts.some(l => l.e.reclamation) };
  }
  const etapeFaite = e => CRITERES.filter(c => c.etape === e.cle && !c.facultatif).every(c => S.rep[c.cle] !== undefined)
    && (CAT.photos[e.cle] !== 'obligatoire' || S.photos[e.cle]);
  const pireEtape = e => {
    const niv = CRITERES.filter(c => c.etape === e.cle).map(c => (eval_(c, S.rep[c.cle]) || {}).niveau);
    return niv.includes('majeur') ? 'majeur' : niv.includes('mineur') ? 'mineur' : 'ok';
  };

  /* ── Les dessins (à remplacer par des photos de référence du réseau) ──── */
  // Ils représentent le produit : ils gardent ses couleurs (§10.5) ; leur fond est le papier du système.
  const TEINTE = {
    pale: 'brightness(1.3) saturate(.42) contrast(.88)', dore: 'none',
    fonce: 'brightness(.66) saturate(1.15) contrast(1.15)', brule: 'brightness(.34) saturate(.7) contrast(1.4)',
  };
  const OR = '#d98b35', BRUN = '#8a4a24', MIE = '#f6e3b4', FOND = '#faf7f2', SOL = '#d9d1c6';
  const svg = (inner, vb = '0 0 120 66') => `<svg viewBox="${vb}" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">${inner}</svg>`;
  const dome = h => `M10 56 C10 ${56 - h * 1.25}, 110 ${56 - h * 1.25}, 110 56 Z`;
  const sol = `<rect width="120" height="66" fill="${FOND}"/><line x1="4" y1="57" x2="116" y2="57" stroke="${SOL}" stroke-width="2"/>`;
  const DESSIN = {
    volume(o) {
      const h = { bas: 16, juste: 30, haut: 42 }[o.dessin];
      const ref = o.dessin === 'juste' ? '' : `<path d="${dome(30)}" fill="none" stroke="#3f7a52" stroke-width="1.6" stroke-dasharray="4 3"/>`;
      return svg(`${sol}<path d="${dome(h)}" fill="${OR}" stroke="${BRUN}" stroke-width="2"/>${ref}`);
    },
    retombe(v) {
      const d = v === 'oui' ? 'M10 56 C10 18, 44 16, 50 32 Q60 46 70 32 C76 16, 110 18, 110 56 Z' : dome(30);
      return svg(`${sol}<path d="${d}" fill="${OR}" stroke="${BRUN}" stroke-width="2"/>`);
    },
    forme(o) {
      const f = {
        reguliere: `<circle cx="22" cy="33" r="13"/><circle cx="98" cy="33" r="13"/><ellipse cx="60" cy="33" rx="32" ry="21"/>`,
        asymetrique: `<circle cx="25" cy="25" r="10"/><circle cx="97" cy="41" r="15"/><ellipse cx="60" cy="33" rx="31" ry="19" transform="rotate(14 60 33)"/>`,
        eclatee: `<circle cx="22" cy="33" r="13"/><circle cx="98" cy="33" r="13"/><ellipse cx="60" cy="33" rx="32" ry="21"/>`,
      }[o.dessin];
      const fente = o.dessin === 'eclatee' ? `<path d="M40 27 Q52 20 62 31 T84 36" fill="none" stroke="${MIE}" stroke-width="5" stroke-linecap="round"/>` : '';
      return svg(`<rect width="120" height="66" fill="${FOND}"/><g fill="${OR}" stroke="${BRUN}" stroke-width="2">${f}</g>${fente}`);
    },
    croute(o) {
      const e = { 1: 2.5, 2: 5.5, 3: 9.5 }[o.epaisseur];
      return svg(`<rect width="120" height="66" fill="${FOND}"/><ellipse cx="60" cy="34" rx="50" ry="27" fill="${BRUN}"/><ellipse cx="60" cy="34" rx="${50 - e}" ry="${27 - e}" fill="${MIE}"/>`
        + alveoles(60, 34, 50 - e - 6, 27 - e - 5, 14, 7));
    },
    souplesse(o) {
      const d = { 1: 17, 2: 11, 3: 6, 4: 2.5, 5: 0 }[o.souplesse];
      return svg(`<rect width="120" height="66" fill="${FOND}"/><path d="M6 40 L36 40 Q60 ${40 + d * 2} 84 40 L114 40 L114 62 L6 62 Z" fill="${MIE}" stroke="${BRUN}" stroke-width="2"/>`
        + `<rect x="52" y="${40 + d - 34}" width="16" height="34" rx="8" fill="#e9c7a8" stroke="#a57b5a" stroke-width="1.5"/>`
        + `<path d="M46 ${30 + d - 30} l-6 -4 M74 ${30 + d - 30} l6 -4" stroke="#7a736a" stroke-width="2" stroke-linecap="round"/>`);
    },
  };
  // Des alvéoles placées toujours de la même façon (pas de hasard d'une capture à l'autre).
  function alveoles(cx, cy, rx, ry, n, seed) {
    let s = seed, out = '';
    const r = () => ((s = (s * 9301 + 49297) % 233280) / 233280);
    for (let i = 0; i < n; i++) {
      const a = r() * Math.PI * 2, k = Math.sqrt(r());
      out += `<ellipse cx="${(cx + Math.cos(a) * rx * k).toFixed(1)}" cy="${(cy + Math.sin(a) * ry * k).toFixed(1)}" rx="${(1.2 + r() * 2.4).toFixed(1)}" ry="${(1 + r() * 1.6).toFixed(1)}" fill="#e2c486"/>`;
    }
    return out;
  }
  // La photo de la coupe : un dessin tant que le réseau n'a pas de photo de mie de référence.
  const coupe = (seed, filtre = '') => svg(`<rect width="132" height="132" fill="${FOND}"/><g style="filter:${filtre}"><path d="M10 104 C10 30, 122 30, 122 104 Z" fill="${BRUN}"/><path d="M15 102 C15 38, 117 38, 117 102 Z" fill="${MIE}"/>`
    + alveoles(66, 78, 40, 18, 26, seed) + `<rect x="10" y="102" width="112" height="5" rx="2" fill="#6e3a1c"/></g>`, '0 0 132 132');
  const maPhoto = () => `<img src="${PHOTO}" alt="" style="transform:rotate(-9deg) scale(1.18);filter:brightness(1.04) saturate(1.06)">`;

  function visuel(c, o) {
    if (c.visuel === 'teinte') return `<span class="aq-vis"><img src="${PHOTO}" alt="" style="filter:${TEINTE[o.teinte]}"></span>`;
    if (DESSIN[c.visuel] && c.visuel !== 'retombe') return `<span class="aq-vis">${DESSIN[c.visuel](o)}</span>`;
    if (o.icone) return ICO(o.icone, 20);
    return '';
  }

  /* ── Petits morceaux du système ────────────────────────────────────────── */
  const esc = s => String(s ?? '').replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
  const NIV = { ok: 'Conforme', mineur: 'À surveiller', majeur: 'Non conforme' };
  // L'état se dit par un mot (§3) : pastille verte, étiquette or, étiquette d'arrêt.
  const etat = (niv, txt) => `<span class="aq-etat ${niv}">${esc(txt || NIV[niv])}</span>`;
  const ecartsTxt = n => (n ? n + ' écart' + (n > 1 ? 's' : '') : 'aucun écart');

  /* ── Sur la fiche ──────────────────────────────────────────────────────── */
  function rendreFiche() {
    let wrap = document.querySelector('.aq-launch-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'aq-launch-wrap';
      document.querySelector('.fiche-tabs').before(wrap);
    }
    const r = S.resultat;
    const f = FOURNEES[S.fournee];
    const jours = HISTO.concat([{ j: 'auj.', r: r ? r.pire : 'vide', auj: true }]);
    const histo = `<div class="aq-histo"><span>7 derniers jours</span>
      <div class="aq-jours">${jours.map(x => `<span class="${x.r}${x.auj ? ' auj' : ''}" title="${esc(NIV[x.r] || 'Pas de contrôle')}"><i class="aq-dot ${x.r}"></i>${x.j}</span>`).join('')}</div>
      <div class="aq-legende">${['ok', 'mineur', 'majeur'].map(n => `<span><i class="aq-dot ${n}"></i>${NIV[n].toLowerCase()}</span>`).join('')}</div></div>`;
    if (!r) {
      wrap.innerHTML = `<button type="button" class="aq-launch" data-aq="ouvrir">${ICO('controle', 24)}
          <span class="aq-launch-t"><span>Autocontrôle qualité</span>
            <small>Fournée de ${f.h} pas encore contrôlée · ${ETAPES.length} étapes · 1 min</small></span>
          ${ICO('chevron-droite', 20)}</button>${histo}`;
      return;
    }
    const dec = { vente: 'mis en vente', declasser: 'déclassé', jeter: 'jeté' }[S.decision || r.decision];
    // Défaut qui revient : la même réponse hors cible plusieurs fois sur la semaine.
    const rec = r.ecarts.map(l => l.c).find(c => HISTO.filter(h => h.d === c.cle).length >= 2);
    const recurrent = rec ? `<div class="aq-alerte">${ICO('repeter', 18)}<div>${esc(rec.libelle)} hors cible 3 fois en 7 jours (jeu., sam., auj.).
        La correction « ${esc(eval_(rec, S.rep[rec.cle]).prochaine.replace(/\.$/, ''))} » n'a pas été reportée dans les Paramètres de cuisson : à voir avec le responsable.</div></div>` : '';
    wrap.innerHTML = `<div class="aq-fait">
          <span class="aq-fait-t"><span>Autocontrôle qualité · fournée de ${f.h}</span>
            <small>${etat(r.pire)} Contrôlée à 08:05 par ${EQUIPE[S.qui]} · lot ${dec} · ${ecartsTxt(r.ecarts.length)}</small></span>
          <button type="button" class="aq-btn" data-aq="ouvrir">${ICO('ajouter', 16)} Nouveau contrôle</button></div>${histo}${recurrent}`;
  }

  /* ── L'écran d'autocontrôle ────────────────────────────────────────────── */
  function rendreOverlay() {
    let ov = document.querySelector('.aq-ov');
    if (!ETAPES.some(e => e.cle === S.ecran) && S.ecran !== 'bilan') { if (ov) ov.remove(); return; }
    const garde = ov ? ov.querySelector('.aq-body').scrollTop : 0;
    if (!ov) { ov = document.createElement('div'); ov.className = 'aq-ov'; ov.setAttribute('role', 'dialog'); document.body.append(ov); }
    const f = FOURNEES[S.fournee];
    const steps = ETAPES.map((e, i) => {
      const nb = CRITERES.filter(c => c.etape === e.cle && !c.facultatif);
      const rep = nb.filter(c => S.rep[c.cle] !== undefined).length;
      const faite = etapeFaite(e) && S.ecran !== e.cle;
      const pire = faite ? pireEtape(e) : '';
      const signe = faite ? (pire === 'ok' ? ICO('coche', 14) : '!') : i + 1;
      return `<button type="button" class="aq-step${S.ecran === e.cle ? ' is-cur' : ''}${faite ? ' is-done ' + pire : ''}" data-aq-aller="${e.cle}"${faite ? ` title="${NIV[pire]}"` : ''}>
        <span class="aq-num">${signe}</span>${e.titre}<em>${rep}/${nb.length}</em></button>`;
    }).join('') + `<button type="button" class="aq-step${S.ecran === 'bilan' ? ' is-cur' : ''}" data-aq-aller="bilan"><span class="aq-num">${ICO('drapeau', 13)}</span>Bilan</button>`;
    ov.innerHTML = `
      <header class="aq-head">
        <button type="button" class="aq-btn aq-btn--icon" data-aq="fermer" aria-label="Fermer">${ICO('croix', 18)}</button>
        <div class="aq-titre"><p class="aq-kicker">Autocontrôle qualité · ${esc(CAT.libelle)}</p><h1>${esc(PRODUIT.nom)}</h1></div>
        <span class="aq-duree">${ICO('horloge', 16)} 1 min</span>
      </header>
      <nav class="aq-steps">${steps}</nav>
      <div class="aq-ctx">
        <span class="aq-sel">${ICO('flamme', 16)} Fournée ${f.h} · ${f.n} pcs ${ICO('chevron-bas', 14)}</span>
        <span class="aq-sel">${ICO('personne', 16)} ${EQUIPE[S.qui]} ${ICO('chevron-bas', 14)}</span>
        <span class="aq-note">${ICO('horloge', 14)} ${f.note} : bon moment pour contrôler</span>
      </div>
      <main class="aq-body">${S.ecran === 'bilan' ? corpsBilan() : corpsEtape(ETAPES.find(e => e.cle === S.ecran))}</main>
      <footer class="aq-foot">${pied()}</footer>`;
    ov.querySelector('.aq-body').scrollTop = garde;
  }

  function corpsEtape(e) {
    const photo = CAT.photos[e.cle];
    let h = '';
    if (photo !== 'non' && e.cle !== 'presentation') {
      const fait = S.photos[e.cle];
      const ref = e.cle === 'cuisson' ? `<img src="${PHOTO}" alt="">` : coupe(7);
      const moi = e.cle === 'cuisson' ? maPhoto() : coupe(11, 'brightness(1.03) saturate(.95)');
      const req = fait ? etat('ok', 'Photo prise à 07:58') : photo === 'obligatoire' ? etat('mineur', 'Photo obligatoire') : etat('neutre', 'Photo facultative');
      h += `<section class="aq-card aq-photos">
        <figure class="aq-fig">${ref}<figcaption>Référence</figcaption></figure>
        ${fait ? `<figure class="aq-fig" data-aq-photo="${e.cle}">${moi}<figcaption>Votre photo</figcaption></figure>`
          : `<button type="button" class="aq-shot" data-aq-photo="${e.cle}">${ICO('appareil', 24)}Prendre la photo</button>`}
        <div class="aq-photos-t"><span>${e.cle === 'cuisson' ? 'Photo du produit' : 'Photo de la coupe'}</span>
          <p>${esc((CAT.consignes_photo || {})[e.cle] || '')}</p>${req}</div>
      </section>`;
    }
    const liste = CRITERES.filter(c => c.etape === e.cle);
    const premier = liste.find(c => S.rep[c.cle] === undefined && !c.facultatif);
    liste.forEach((c, i) => { h += critere(c, i + 1, c === premier && liste.some(x => S.rep[x.cle] !== undefined)); });
    return h;
  }

  function critere(c, num, todo) {
    const v = S.rep[c.cle];
    const ev = eval_(c, v);
    const niv = ev ? ev.niveau : '';
    const marque = ICO('coche', 14).replace('class="aq-ico"', 'class="aq-ico aq-opt-cible"');
    const bouton = (val, contenu, estCible) => `<button type="button" class="aq-opt${v === val ? ' is-sel' : ''}" data-aq-rep="${c.cle}" data-v="${val}"${estCible ? ' title="Attendu"' : ''}>
      ${estCible ? marque : ''}${contenu}</button>`;
    let ciblTxt = '', opts = '';
    if (c.type === 'echelle') {
      ciblTxt = c.options.find(o => o.cible).libelle;
      opts = `<div class="aq-opts" style="--n:${c.options.length}">${c.options.map(o => bouton(o.cle, `${visuel(c, o)}<span>${esc(o.libelle)}</span>`, o.cible)).join('')}</div>`;
    } else if (c.type === 'oui_non') {
      ciblTxt = c.attendu === 'oui' ? 'Oui' : 'Non';
      const b = (val, ico) => bouton(val, `${c.visuel === 'retombe' ? `<span class="aq-vis">${DESSIN.retombe(val)}</span>` : ICO(ico, 20)}<span>${val === 'oui' ? 'Oui' : 'Non'}</span>`, val === c.attendu);
      opts = `<div class="aq-opts" style="--n:2">${b('oui', 'coche')}${b('non', 'croix')}</div>`;
    } else if (c.type === 'mesure') {
      const min = c.cible - c.tolerance * 2, max = c.cible + c.tolerance * 2, pc = x => ((x - min) / (max - min) * 100).toFixed(1) + '%';
      ciblTxt = `${c.cible - c.tolerance} à ${c.cible + c.tolerance} ${c.unite}`;
      const n = Number(v);
      opts = `<div class="aq-mes"><label class="aq-mes-in"><input type="text" inputmode="numeric" value="${esc(v ?? '')}" placeholder="—" data-aq-mes="${c.cle}"><span>${c.unite}</span></label>
        <div class="aq-mes-g"><div class="aq-mes-bar"><span class="aq-mes-zone" style="left:${pc(c.cible - c.tolerance)};right:${(100 - parseFloat(pc(c.cible + c.tolerance))).toFixed(1)}%"></span>
          ${v ? `<span class="aq-mes-pt" style="left:${pc(Math.max(min, Math.min(max, n)))}"></span>` : ''}</div>
          <div class="aq-mes-leg"><span>${min}</span><span>${c.cible - c.tolerance}</span><span>${c.cible}</span><span>${c.cible + c.tolerance}</span><span>${max} ${c.unite}</span></div>
          <div class="aq-mes-st">${!v ? etat('neutre', 'Peser une pièce') : niv === 'ok' ? etat('ok', 'Dans la tolérance') : etat(niv, 'Hors tolérance')}</div></div></div>`;
    }
    const avant = c.visuel === 'comparaison' ? comparaison() : c.visuel === 'planogramme' ? planogramme() : '';
    const corr = ev && niv !== 'ok' ? `<div class="aq-corr ${niv}">
        <p class="aq-corr-t">${ICO('outil', 15)} Correction</p>
        <dl><dt>Cause probable</dt><dd>${esc(ev.cause)}</dd><dt>Maintenant</dt><dd>${esc(ev.maintenant)}</dd><dt>Prochaine fournée</dt><dd>${esc(ev.prochaine)}</dd></dl>
        ${ev.sop || ev.reclamation ? `<div class="aq-liens">${ev.sop ? `<span class="aq-btn aq-btn--sm">${ICO('lecture', 15)} Revoir le geste : ${esc(ev.sop)}</span>` : ''}${ev.reclamation ? `<span class="aq-btn aq-btn--sm">${ICO('reclamation', 15)} Proposé en réclamation au bilan</span>` : ''}</div>` : ''}
      </div>` : '';
    return `<section class="aq-card${todo ? ' is-todo' : ''}" id="aq-${c.cle}">
      <div class="aq-crit-h"><span class="aq-num">${num}</span><h3>${esc(c.libelle)}${c.facultatif ? ' <small>facultatif</small>' : ''}${ev ? etat(niv) : ''}</h3>
        <span class="aq-cible">${ICO('cible', 14)} Attendu : ${esc(ciblTxt)}</span></div>
      ${c.aide ? `<p class="aq-aide">${esc(c.aide)}</p>` : ''}${avant}${opts}${corr}</section>`;
  }

  // La photo de la fiche à côté de celle prise à l'étape Cuisson, en grand : on compare d'un coup d'œil.
  function comparaison() {
    const moi = S.photos.cuisson
      ? `<figure class="aq-fig">${maPhoto()}<figcaption>Votre produit · 07:58</figcaption></figure>`
      : `<button type="button" class="aq-shot" data-aq-photo="cuisson">${ICO('appareil', 24)}Prendre la photo</button>`;
    return `<div class="aq-comp"><figure class="aq-fig"><img src="${PHOTO}" alt=""><figcaption>Référence · fiche produit</figcaption></figure>${moi}</div>`;
  }
  // L'emplacement du produit au planogramme du magasin : le meuble vu de face, sa case liserée de bordeaux (la sélection).
  function planogramme() {
    if (!PLANO) return '';
    const v = PLANO.voisins || {};
    const pieces = n => Array.from({ length: n }, (_, i) => `<ellipse cx="${18 + i * 13.5}" cy="132" rx="6.5" ry="4.6" fill="${OR}" stroke="${BRUN}" stroke-width="1"/>`).join('');
    const caseP = (x, y, w, h, nom, moi) => `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="4" fill="${moi ? 'rgba(141,29,44,0.05)' : '#fff'}" stroke="${moi ? '#8D1D2C' : '#e6e0d8'}" stroke-width="${moi ? 2 : 1}"/>`
      + `<text x="${x + w / 2}" y="${y + 16}" text-anchor="middle" font-size="9" fill="${moi ? '#8D1D2C' : '#7a736a'}" font-family="'Gotham DS', sans-serif">${esc(nom)}</text>`;
    const plan = svg(`<rect x="1" y="1" width="198" height="148" rx="8" fill="#EDE7DE" stroke="#e6e0d8"/>`
      + caseP(6, 6, 188, 40, v.e3 || '') + caseP(6, 52, 188, 40, v.e2 || '')
      + caseP(6, 98, 94, 46, PRODUIT.nom.replace(/ - 400 g\.?/, ''), true) + caseP(104, 98, 90, 46, v.e1b || '')
      + pieces(Math.min(PLANO.facade, 6)), '0 0 200 150');
    const fait = S.photos.presentation;
    const photo = CAT.photos.presentation;
    return `<div class="aq-plano"><div class="aq-plano-svg">${plan}</div>
      <div class="aq-plano-t"><p class="aq-label" style="margin:0">Planogramme du magasin</p><span>${esc(PLANO.zone)}</span><span>${esc(PLANO.libelle)}</span>
        ${etat('neutre', PLANO.facade + ' pièces en façade')}
        <span class="aq-btn aq-btn--sm">${ICO('plan', 15)} Voir le plan du magasin</span></div>
      ${fait ? `<figure class="aq-fig" data-aq-photo="presentation">${maPhoto()}<figcaption>Vitrine</figcaption></figure>`
        : `<button type="button" class="aq-shot" data-aq-photo="presentation">${ICO('appareil', 24)}Photo de la vitrine${etat('neutre', photo === 'obligatoire' ? 'obligatoire' : 'facultative')}</button>`}</div>`;
  }

  function corpsBilan() {
    const r = bilan();
    const dec = S.decision || r.decision;
    const titre = { vente: r.ecarts.length ? 'Vendable, à corriger' : 'Conforme, en vente', declasser: 'Déclasser ce lot', jeter: 'Ne pas vendre ce lot' }[r.decision];
    const prevenir = S.prevenir ?? r.n.majeur > 0, reclamer = S.reclamer ?? r.reclamation;
    const f = FOURNEES[S.fournee];
    const decBtn = (k, ico, sous) => `<button type="button" class="aq-opt${dec === k ? ' is-sel' : ''}" data-aq-dec="${k}">${r.decision === k ? '<span class="aq-badge">Suggéré</span>' : ''}${ICO(ico, 20)}<span>${G.decisions[k].split(' (')[0]}</span><small>${sous}</small></button>`;
    const photos = [S.photos.cuisson ? maPhoto() : '', S.photos.structure ? coupe(11) : '', S.photos.presentation ? maPhoto() : ''].filter(Boolean);
    const sw = (k, on, t, sous) => `<button type="button" class="aq-switch${on ? ' is-on' : ''}" data-aq-tog="${k}" aria-pressed="${on}"><span class="aq-switch-t">${t}<small>${sous}</small></span><span class="aq-track"></span></button>`;
    return `
      <section class="aq-card"><div class="aq-bilan-h"><div>
          <p class="aq-kicker">Bilan · fournée de ${f.h} · ${f.n} pièces</p>
          <h2>${titre} ${etat(r.pire)}</h2>
          <p>Contrôlée par ${EQUIPE[S.qui]}, ${ETAPES.length} étapes, ${r.lignes.length} critères.</p></div></div>
        <div class="aq-kpis">
          <div class="aq-kpi"><b>${r.n.ok}</b><span><i class="aq-dot ok"></i>conformes</span></div>
          <div class="aq-kpi"><b>${r.n.mineur}</b><span><i class="aq-dot mineur"></i>à surveiller</span></div>
          <div class="aq-kpi"><b>${r.n.majeur}</b><span><i class="aq-dot majeur"></i>non conformes</span></div>
        </div></section>
      <section class="aq-card" id="aq-decision"><p class="aq-label">Que fait-on des ${f.n} pièces ?</p>
        <div class="aq-dec">${decBtn('vente', 'vitrine', 'en vitrine')}${decBtn('declasser', 'etiquette', 'vente du jour, prix réduit')}${decBtn('jeter', 'poubelle', 'encodé en pertes')}</div></section>
      ${r.actions.length ? `<section class="aq-card" id="aq-actions"><p class="aq-label">Pour la prochaine fournée (${FOURNEES[1].h})</p>
        ${r.actions.map((a, i) => `<div class="aq-act${S.coches[i] ? ' is-coche' : ''}" data-aq-coche="${i}"><span class="aq-case">${ICO('coche', 14)}</span>
          <div class="aq-act-t">${esc(a.t)}<div class="aq-tags">${a.de.map(d => etat('neutre', d)).join('')}</div></div></div>`).join('')}
        <p class="aq-note" style="margin:10px 0 0">${ICO('info', 14)} Ces consignes s'affichent au lancement de la fournée de ${FOURNEES[1].h} (module Cuisson).</p></section>` : ''}
      <div class="aq-2col">
        <section class="aq-card"><p class="aq-label">Les écarts</p>${r.ecarts.length ? r.ecarts.map(l => `<div class="aq-ecart"><i class="aq-dot ${l.e.niveau}"></i>
          <div class="aq-ecart-t">${esc(l.c.libelle)} : ${esc(l.e.libelle)}<p>${esc(l.e.cause)}</p></div>${etat(l.e.niveau)}</div>`).join('') : `<p style="margin:0">${etat('ok', 'Aucun écart : tout est à la cible')}</p>`}</section>
        <section class="aq-card"><p class="aq-label">Photos et suites</p>
          <div class="aq-mini">${photos.map(x => `<figure class="aq-fig">${x}</figure>`).join('')}</div>
          ${sw('prevenir', prevenir, 'Prévenir le responsable', r.n.majeur ? 'Conseillé : une non-conformité' : 'Pas nécessaire')}
          ${sw('reclamer', reclamer, 'Réclamation à la production centrale', r.reclamation ? 'Conseillé : défaut du produit cru' : 'Défaut lié à la cuisson, pas au cru')}</section>
      </div>`;
  }

  function pied() {
    if (S.ecran === 'bilan') {
      const r = bilan();
      const ok = CRITERES.filter(c => !c.facultatif).every(c => S.rep[c.cle] !== undefined);
      return `<button type="button" class="aq-btn" data-aq-aller="${ETAPES[ETAPES.length - 1].cle}">${ICO('retour', 16)} Modifier</button>
        <button type="button" class="aq-btn aq-btn--primary" data-aq="valider"${ok ? '' : ' disabled'}>Valider l'autocontrôle <small>· ${ecartsTxt(r.ecarts.length)}</small></button>`;
    }
    const e = ETAPES.find(x => x.cle === S.ecran);
    const i = ETAPES.indexOf(e);
    const liste = CRITERES.filter(c => c.etape === e.cle && !c.facultatif);
    const rep = liste.filter(c => S.rep[c.cle] !== undefined).length;
    const reste = liste.length - rep;
    const suivant = ETAPES[i + 1];
    const manquePhoto = CAT.photos[e.cle] === 'obligatoire' && !S.photos[e.cle];
    const pret = !reste && !manquePhoto;
    return `<div class="aq-prog"><span>${rep} / ${liste.length} répondus</span><span class="aq-prog-bar"><i style="width:${(rep / liste.length * 100).toFixed(0)}%"></i></span></div>
      ${reste ? `<button type="button" class="aq-btn" data-aq="conforme">${ICO('double-coche', 18)} Le reste est conforme <small>(${reste})</small></button>` : ''}
      <button type="button" class="aq-btn aq-btn--primary" data-aq-aller="${suivant ? suivant.cle : 'bilan'}"${pret ? '' : ' disabled'}>
        ${pret ? `Suivant : ${suivant ? suivant.titre : 'Bilan'} ${ICO('suivant', 18)}` : manquePhoto ? `${ICO('appareil', 18)} Photo obligatoire` : `Encore ${reste} critère${reste > 1 ? 's' : ''}`}</button>`;
  }

  /* ── Les gestes ────────────────────────────────────────────────────────── */
  function rendre() { rendreFiche(); rendreOverlay(); }

  document.addEventListener('click', ev => {
    const t = ev.target.closest('[data-aq],[data-aq-rep],[data-aq-aller],[data-aq-photo],[data-aq-dec],[data-aq-coche],[data-aq-tog]');
    if (!t) return;
    const d = t.dataset;
    if (d.aq === 'ouvrir') { S.rep = {}; S.photos = {}; S.decision = null; S.coches = {}; S.prevenir = S.reclamer = null; S.ecran = 'cuisson'; }
    else if (d.aq === 'fermer') { S.ecran = S.resultat ? 'fait' : 'fiche'; }
    else if (d.aq === 'conforme') {
      CRITERES.filter(c => c.etape === S.ecran && !c.facultatif && S.rep[c.cle] === undefined).forEach(c => { S.rep[c.cle] = cible(c); });
    } else if (d.aq === 'valider') {
      S.resultat = bilan(); S.ecran = 'fait';
      setTimeout(toast, 50);
    } else if (d.aqRep) {
      S.rep[d.aqRep] = d.v;
      // Réponse conforme : on file au critère suivant sans faire défiler à la main.
      const c = CRITERES.find(x => x.cle === d.aqRep);
      const ev2 = eval_(c, d.v);
      rendre();
      if (ev2 && ev2.niveau === 'ok') {
        const suiv = CRITERES.filter(x => x.etape === c.etape).find(x => S.rep[x.cle] === undefined && !x.facultatif);
        if (suiv) document.getElementById('aq-' + suiv.cle)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      return;
    } else if (d.aqAller) { S.ecran = d.aqAller; rendre(); document.querySelector('.aq-body').scrollTop = 0; return; }
    else if (d.aqPhoto) { S.photos[d.aqPhoto] = !S.photos[d.aqPhoto]; }
    else if (d.aqDec) { S.decision = d.aqDec; }
    else if (d.aqCoche !== undefined) { S.coches[d.aqCoche] = !S.coches[d.aqCoche]; }
    else if (d.aqTog) { const r = bilan(); const cur = S[d.aqTog] ?? (d.aqTog === 'prevenir' ? r.n.majeur > 0 : r.reclamation); S[d.aqTog] = !cur; }
    rendre();
  });
  document.addEventListener('change', ev => {
    const k = ev.target.dataset && ev.target.dataset.aqMes;
    if (k) { S.rep[k] = ev.target.value.replace(/[^\d.,]/g, '').replace(',', '.') || undefined; if (S.rep[k] === undefined) delete S.rep[k]; rendre(); }
  });

  function toast() {
    const r = S.resultat;
    const dec = { vente: 'mis en vente', declasser: 'déclassé', jeter: 'jeté (encodé en pertes)' }[S.decision || r.decision];
    const el = document.createElement('div');
    el.className = 'aq-toast';
    el.innerHTML = `${ICO('coche', 18)} Autocontrôle enregistré · lot ${dec}${(S.prevenir ?? r.n.majeur > 0) ? ' · le responsable est prévenu' : ''}`;
    document.body.append(el);
    if (!p.get('ecran')) setTimeout(() => el.remove(), 4000);
  }

  if (demo && S.ecran === 'fait') S.resultat = bilan();
  rendre();
  if (S.ecran === 'fait') toast();
  const cibleDef = p.get('defiler');
  if (cibleDef) {
    const el = document.getElementById('aq-' + cibleDef) || document.querySelector(cibleDef);
    if (el) el.scrollIntoView({ block: 'start' });
  }
  document.documentElement.dataset.pret = '1';
})();
