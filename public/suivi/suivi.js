/* La page du franchisé (24/10/2026). Une lecture : GET ../api/cockpit/suivi/franchise?shop=&jeton= ; deux
 * écritures : POST …/suivi/franchise/correction (la photo d'un point, qui attend la validation du bureau) et
 * POST …/suivi/franchise/lu (le rapport ouvert). La page parle la langue du magasin (réglée dans « Qui fait
 * quoi » du cockpit, sinon sa région). ?apercu=1 : la vue du bureau, qui n'envoie rien et ne marque rien lu. */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const q = new URLSearchParams(location.search);
  const SHOP = q.get('shop') || '', JETON = q.get('jeton') || '', APERCU = q.get('apercu') === '1';
  const $ = document.getElementById('fr');
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const LOC = { fr: 'fr-BE', nl: 'nl-BE', en: 'en-GB', de: 'de-BE' };
  const T = {
    fr: { titre: 'Mon suivi', maj: 'mis à jour le', pro: 'Votre prochaine visite', avec: 'avec', sujets: 'Ce que nous regarderons ensemble', aucunePro: 'Aucune visite annoncée pour le moment.',
      points: 'Vos points à travailler', aucunPoint: 'Aucun point ouvert : bravo.', pour: 'pour le', retard: 'en retard de {n} j', st_ouvert: 'à corriger', st_reprendre: 'à reprendre', st_escalade: 'urgent',
      st_attente: 'photo envoyée, en attente de validation', retour: 'Le bureau demande', photo: 'Prendre la photo de la correction', autre: 'Changer de photo', mot: 'Un mot pour le bureau (facultatif)',
      envoyer: 'Envoyer la correction', envoi: 'Envoi…', envoye: 'Envoyé : le bureau valide votre photo.', vide: 'Ajoutez une photo ou un mot.', visite: 'visite du', msp: 'client mystère',
      valides: 'Validés ce mois-ci', rapports: 'Vos rapports de visite', aucunRap: 'Pas encore de rapport.', conformes: 'points conformes', nouveau: 'nouveau', positif: 'Ce qui va bien', vu: 'Vu sur place',
      causes: 'Les causes', reco: 'Recommandation', ptsRap: 'Les points à travailler', photos: 'Les photos', ancien: 'Visite faite avant les rapports en ligne : demandez le détail à votre consultant.',
      mspT: 'Client mystère', pdf: 'Le rapport (PDF)', scoring: 'Scoring du trimestre', erreur: 'La page ne répond pas. Réessayez dans un instant.', min: 'min' },
    nl: { titre: 'Mijn opvolging', maj: 'bijgewerkt op', pro: 'Uw volgende bezoek', avec: 'met', sujets: 'Wat we samen bekijken', aucunePro: 'Momenteel geen aangekondigd bezoek.',
      points: 'Uw werkpunten', aucunPoint: 'Geen open punten: proficiat.', pour: 'tegen', retard: '{n} d. te laat', st_ouvert: 'te verbeteren', st_reprendre: 'opnieuw te doen', st_escalade: 'dringend',
      st_attente: 'foto verstuurd, wacht op goedkeuring', retour: 'Het kantoor vraagt', photo: 'Foto van de verbetering nemen', autre: 'Andere foto', mot: 'Een woordje voor het kantoor (optioneel)',
      envoyer: 'Verbetering versturen', envoi: 'Versturen…', envoye: 'Verstuurd: het kantoor keurt uw foto goed.', vide: 'Voeg een foto of een woordje toe.', visite: 'bezoek van', msp: 'mystery shopper',
      valides: 'Goedgekeurd deze maand', rapports: 'Uw bezoekverslagen', aucunRap: 'Nog geen verslag.', conformes: 'punten in orde', nouveau: 'nieuw', positif: 'Wat goed gaat', vu: 'Ter plaatse gezien',
      causes: 'De oorzaken', reco: 'Aanbeveling', ptsRap: 'De werkpunten', photos: 'De foto’s', ancien: 'Bezoek van vóór de online verslagen: vraag de details aan uw consultant.',
      mspT: 'Mystery shopper', pdf: 'Het verslag (PDF)', scoring: 'Kwartaalscore', erreur: 'De pagina antwoordt niet. Probeer het zo meteen opnieuw.', min: 'min' },
    en: { titre: 'My follow-up', maj: 'updated', pro: 'Your next visit', avec: 'with', sujets: 'What we will look at together', aucunePro: 'No visit announced for now.',
      points: 'Your points to work on', aucunPoint: 'No open points: well done.', pour: 'by', retard: '{n} d late', st_ouvert: 'to fix', st_reprendre: 'to redo', st_escalade: 'urgent',
      st_attente: 'photo sent, awaiting approval', retour: 'The office asks', photo: 'Take a photo of the fix', autre: 'Change photo', mot: 'A note for the office (optional)',
      envoyer: 'Send the fix', envoi: 'Sending…', envoye: 'Sent: the office will review your photo.', vide: 'Add a photo or a note.', visite: 'visit of', msp: 'mystery shopper',
      valides: 'Approved this month', rapports: 'Your visit reports', aucunRap: 'No report yet.', conformes: 'points compliant', nouveau: 'new', positif: 'What is going well', vu: 'Seen on site',
      causes: 'The causes', reco: 'Recommendation', ptsRap: 'Points to work on', photos: 'Photos', ancien: 'Visit made before online reports: ask your consultant for the details.',
      mspT: 'Mystery shopper', pdf: 'The report (PDF)', scoring: 'Quarterly score', erreur: 'The page is not responding. Try again in a moment.', min: 'min' },
    de: { titre: 'Meine Betreuung', maj: 'aktualisiert am', pro: 'Ihr nächster Besuch', avec: 'mit', sujets: 'Was wir gemeinsam ansehen', aucunePro: 'Derzeit kein angekündigter Besuch.',
      points: 'Ihre Arbeitspunkte', aucunPoint: 'Keine offenen Punkte: bravo.', pour: 'bis zum', retard: '{n} T. überfällig', st_ouvert: 'zu beheben', st_reprendre: 'nachzubessern', st_escalade: 'dringend',
      st_attente: 'Foto gesendet, wartet auf Freigabe', retour: 'Das Büro bittet', photo: 'Foto der Korrektur aufnehmen', autre: 'Anderes Foto', mot: 'Eine Notiz für das Büro (optional)',
      envoyer: 'Korrektur senden', envoi: 'Senden…', envoye: 'Gesendet: das Büro prüft Ihr Foto.', vide: 'Fügen Sie ein Foto oder eine Notiz hinzu.', visite: 'Besuch vom', msp: 'Testkauf',
      valides: 'Diesen Monat freigegeben', rapports: 'Ihre Besuchsberichte', aucunRap: 'Noch kein Bericht.', conformes: 'Punkte konform', nouveau: 'neu', positif: 'Was gut läuft', vu: 'Vor Ort gesehen',
      causes: 'Die Ursachen', reco: 'Empfehlung', ptsRap: 'Die Arbeitspunkte', photos: 'Die Fotos', ancien: 'Besuch vor den Online-Berichten: fragen Sie Ihren Berater nach den Details.',
      mspT: 'Testkauf', pdf: 'Der Bericht (PDF)', scoring: 'Quartalsbewertung', erreur: 'Die Seite antwortet nicht. Versuchen Sie es gleich noch einmal.', min: 'Min.' }
  };
  const LIEN_KO = ['Ce lien n’est pas (ou plus) valable. Demandez le lien de votre page à votre consultant.', 'Deze link is niet (meer) geldig. Vraag de link van uw pagina aan uw consultant.',
    'This link is not (or no longer) valid. Ask your consultant for the link to your page.', 'Dieser Link ist nicht (mehr) gültig. Fragen Sie Ihren Berater nach dem Link zu Ihrer Seite.'];
  let L = T.fr, LG = 'fr', D = null;
  const etat = { photo: {}, mot: {}, busy: {}, msg: {}, flash: null };
  const fD = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';
  const fDL = d => { if (!d) { return ''; } const t = new Date(d.slice(0, 10) + 'T12:00:00'); return t.toLocaleDateString(LOC[LG], { weekday: 'long', day: 'numeric', month: 'long' }); };
  const nf = (n, dd) => n == null ? '—' : Number(n).toLocaleString(LOC[LG], { minimumFractionDigits: dd || 0, maximumFractionDigits: dd || 0 });
  const src = c => '../' + String(c || '').replace(/^\/+/, '');

  function lire() {
    return fetch(API + '/suivi/franchise?shop=' + encodeURIComponent(SHOP) + '&jeton=' + encodeURIComponent(JETON), { headers: { Accept: 'application/json' } })
      .then(r => r.json().then(j => ({ ok: r.ok, status: r.status, j }), () => ({ ok: false, status: r.status, j: null })));
  }
  function poster(path, corps) {
    return fetch(API + path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(Object.assign({ shop: SHOP, jeton: JETON }, corps)) })
      .then(r => r.json().catch(() => ({ error: 'HTTP ' + r.status })));
  }
  function charger() {
    lire().then(r => {
      if (r.status === 403) { $.innerHTML = '<div class="fr-carte">' + LIEN_KO.map(t => '<p class="fr-mu" style="margin:4px 0">' + esc(t) + '</p>').join('') + '</div>'; return; }
      if (!r.ok || !r.j) { $.innerHTML = '<div class="fr-carte fr-mu">' + esc(L.erreur) + '</div>'; return; }
      D = r.j; LG = T[D.langue] ? D.langue : 'fr'; L = T[LG];
      document.documentElement.lang = LG; document.title = L.titre + ' — ' + D.magasin.court;
      rendre();
    }, () => { $.innerHTML = '<div class="fr-carte fr-mu">' + esc(L.erreur) + '</div>'; });
  }

  function rendre() {
    const V = D.voit || {};
    let h = `<div class="fr-hd"><img src="../assets/img/logo.png" alt="L'Atelier by"><div><h1>${esc(D.magasin.court)} — ${esc(L.titre)}</h1><small>${esc(L.maj)} ${esc(fDL(D.lu))} · ${esc(D.lu.slice(11, 16))}</small></div></div>`;
    if (etat.flash) { h += `<div class="fr-carte fr-msg ok">${esc(etat.flash)}</div>`; }
    if (APERCU) { h += '<div class="fr-apercu">Aperçu du bureau : rien n’est envoyé, rien n’est marqué comme lu. La page parle la langue du magasin.</div>'; }
    if (V.prochaine) {
      const p = D.prochaine;
      h += `<div class="fr-carte fr-pro"><h2>${esc(L.pro)}</h2>${p ? `<b class="d">${esc(fDL(p.le))} · ${esc(p.h)}</b><div class="fr-mu">${esc(p.type)} · ${esc(L.avec)} ${esc(p.consultant)} · ${esc(p.duree)} ${esc(L.min)}</div>
        ${p.sujets && p.sujets.length ? `<div style="margin-top:8px;font-weight:600;font-size:13px">${esc(L.sujets)}</div><ul>${p.sujets.map(s => '<li>' + esc(s) + '</li>').join('')}</ul>` : ''}` : `<div class="fr-mu">${esc(L.aucunePro)}</div>`}</div>`;
    }
    if (V.points) {
      const pts = D.points || [];
      h += `<div class="fr-carte"><h2>${esc(L.points)}</h2>${pts.length ? pts.map(point).join('') : `<div class="fr-mu">${esc(L.aucunPoint)}</div>`}
        ${(D.valides || []).length ? `<div style="margin-top:12px;font-weight:600;font-size:13px">${esc(L.valides)}</div><ul class="fr-val">${D.valides.map(v => '<li>✓ ' + esc(v.titre) + ' · ' + esc(fD(v.le)) + '</li>').join('')}</ul>` : ''}</div>`;
    }
    if (V.rapports) {
      const raps = D.rapports || [];
      h += `<div class="fr-carte"><h2>${esc(L.rapports)}</h2>${raps.length ? raps.map(rapport).join('') : `<div class="fr-mu">${esc(L.aucunRap)}</div>`}</div>`;
    }
    if (V.msp && (D.msp || []).length) {
      h += `<div class="fr-carte"><h2>${esc(L.mspT)}</h2>${D.msp.map(m => `<div class="fr-msp"><div><b>${esc(nf(m.obtenu))} / ${esc(nf(m.maximum))}</b><div class="fr-mu">${esc(fDL(m.passage))}</div></div>
        <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end"><div class="fr-rub">${Object.keys(m.rubriques || {}).map(k => `<span class="${m.rubriques[k] < 12 ? 'ko' : ''}">${esc(k)} ${esc(nf(m.rubriques[k], m.rubriques[k] % 1 ? 1 : 0))}/20</span>`).join('')}</div>
        ${m.fichier ? `<a class="fr-lien" href="${esc(src(m.fichier))}" target="_blank" rel="noopener">${esc(L.pdf)}</a>` : ''}</div></div>`).join('')}</div>`;
    }
    if (V.scoring && D.scoring) {
      const S = D.scoring; const P = S.postes || {};
      h += `<div class="fr-carte"><h2>${esc(L.scoring)} · ${esc(S.trimestre)}</h2><div style="font:400 26px var(--font-display);margin-bottom:8px">${esc(nf(S.total, 1))} / ${esc(S.sur)}</div>
        <div class="fr-sc">${Object.keys(P).map(k => `<div><small>${esc(k)}</small><b>${esc(P[k] && P[k].v != null ? nf(P[k].v, 1) : '—')}</b></div>`).join('')}</div></div>`;
    }
    $.innerHTML = h;
    brancher();
  }

  function point(p) {
    const cls = p.priorite === 'P0' ? 'p0' : p.priorite === 'P1' ? 'p1' : '';
    const st = p.statut === 'attente' ? `<span class="fr-st att">${esc(L.st_attente)}</span>` : `<span class="fr-st ${p.statut === 'reprendre' ? 'vio' : (p.statut === 'escalade' || p.retard ? 'ko' : '')}">${esc(L['st_' + p.statut] || p.statut)}</span>`;
    const quand = [p.echeance ? L.pour + ' ' + fD(p.echeance) : '', p.retard ? L.retard.replace('{n}', p.retard) : '', p.source === 'msp' ? (p.detail ? '' : L.msp) : (p.visiteLe ? L.visite + ' ' + fD(p.visiteLe) : '')].filter(Boolean).join(' · ');
    const peut = ['ouvert', 'reprendre', 'escalade'].includes(p.statut);
    const ph = etat.photo[p.id];
    const m = etat.msg[p.id];
    return `<div class="fr-pt"><div class="h"><span class="fr-p ${cls}">${esc(p.priorite)}</span><b>${esc(p.titre)}</b></div>${st}
      ${p.detail ? `<div style="font-size:13px">${esc(p.detail)}</div>` : ''}<div class="fr-mu">${esc(quand)}</div>
      ${p.retour ? `<div class="fr-retour"><b>${esc(L.retour)} :</b> ${esc(p.retour)}</div>` : ''}
      ${p.statut === 'attente' && p.photo ? `<img class="ph" src="${esc(src(p.photo.chemin))}" alt="">` : ''}
      ${peut ? `<div class="fr-form" data-plan="${p.id}">
        <div class="lig"><label class="fr-btn" style="display:inline-flex;align-items:center">${esc(ph ? L.autre : L.photo)}<input type="file" accept="image/*" capture="environment" data-photo="${p.id}" hidden></label>${ph ? `<img class="prev" src="${ph}" alt="">` : ''}</div>
        <textarea data-mot="${p.id}" placeholder="${esc(L.mot)}">${esc(etat.mot[p.id] || '')}</textarea>
        <div class="lig"><button class="fr-btn prim" data-envoyer="${p.id}" ${etat.busy[p.id] || APERCU ? 'disabled' : ''}>${esc(etat.busy[p.id] ? L.envoi : L.envoyer)}</button>${m ? `<span class="fr-msg ${m.ko ? 'ko' : 'ok'}">${esc(m.t)}</span>` : ''}</div></div>` : ''}</div>`;
  }

  function rapport(r) {
    const nouveau = !r.ancien && !r.luLe;
    const sum = `<summary><b>${esc(fDL(r.le))} · ${esc(r.type)}</b>${nouveau ? `<span class="fr-new" style="display:inline">${esc(L.nouveau)}</span>` : ''}<span>${esc(L.avec)} ${esc(r.consultant)} · ${r.checklist.total ? esc(r.checklist.ok + ' / ' + r.checklist.total + ' ' + L.conformes) : ''}${r.phrase ? ' — ' + esc(r.phrase) : ''}</span></summary>`;
    if (r.ancien) { return `<details class="fr-rap">${sum}<div class="c"><p class="fr-mu">${esc(L.ancien)}</p>${r.reco ? `<div><h3>${esc(L.reco)}</h3><p>${esc(r.reco)}</p></div>` : ''}</div></details>`; }
    const bloc = (t, v) => v ? `<div><h3>${esc(t)}</h3><p>${esc(v).replace(/\n/g, '<br>')}</p></div>` : '';
    return `<details class="fr-rap" data-rap="${r.id}"${nouveau ? ' data-nouveau="1"' : ''}>${sum}<div class="c">${bloc(L.positif, r.positif)}${bloc(L.vu, r.diagnostic)}
      ${r.causes && r.causes.length ? `<div><h3>${esc(L.causes)}</h3><p>${r.causes.map(esc).join(' · ')}</p></div>` : ''}${bloc(L.reco, r.reco)}
      ${r.points && r.points.length ? `<div><h3>${esc(L.ptsRap)}</h3><ul>${r.points.map(p => `<li><b>${esc(p.priorite)}</b> · ${esc(p.titre)}${p.echeance ? ' — ' + esc(L.pour) + ' ' + esc(fD(p.echeance)) : ''}</li>`).join('')}</ul></div>` : ''}
      ${r.photos && r.photos.length ? `<div><h3>${esc(L.photos)}</h3><div class="fr-phs">${r.photos.map(p => `<a href="${esc(src(p.chemin))}" target="_blank" rel="noopener"><img src="${esc(src(p.chemin))}" alt="" loading="lazy"></a>`).join('')}</div></div>` : ''}</div></details>`;
  }

  // La photo réduite avant l'envoi : 1600 px au plus grand côté, JPEG — une photo de téléphone passe de 4 Mo à ~300 Ko.
  function reduire(fichier) {
    return new Promise((ok, ko) => {
      const lecteur = new FileReader();
      lecteur.onerror = ko;
      lecteur.onload = () => { const img = new Image(); img.onerror = ko; img.onload = () => {
        const k = Math.min(1, 1600 / Math.max(img.width, img.height)); const c = document.createElement('canvas');
        c.width = Math.round(img.width * k); c.height = Math.round(img.height * k); c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        ok(c.toDataURL('image/jpeg', 0.82)); }; img.src = lecteur.result; };
      lecteur.readAsDataURL(fichier);
    });
  }

  function brancher() {
    $.querySelectorAll('[data-photo]').forEach(inp => inp.addEventListener('change', () => {
      const id = inp.getAttribute('data-photo'); const f = inp.files && inp.files[0]; if (!f) { return; }
      reduire(f).then(d => { etat.photo[id] = d; etat.msg[id] = null; rendre(); }, () => { etat.msg[id] = { t: L.erreur, ko: true }; rendre(); });
    }));
    $.querySelectorAll('[data-mot]').forEach(t => t.addEventListener('input', () => { etat.mot[t.getAttribute('data-mot')] = t.value; }));
    $.querySelectorAll('[data-envoyer]').forEach(b => b.addEventListener('click', () => {
      const id = b.getAttribute('data-envoyer');
      if (APERCU) { return; }
      if (!etat.photo[id] && !(etat.mot[id] || '').trim()) { etat.msg[id] = { t: L.vide, ko: true }; rendre(); return; }
      etat.busy[id] = true; rendre();
      poster('/suivi/franchise/correction', { plan: Number(id), data: etat.photo[id] || null, commentaire: etat.mot[id] || '', client_id: 'fr-' + id + '-' + Date.now().toString(36) }).then(r => {
        etat.busy[id] = false;
        if (r && r.ok) { delete etat.photo[id]; delete etat.mot[id]; etat.msg[id] = null; etat.flash = L.envoye; charger(); return; }
        etat.msg[id] = { t: (r && r.error) || L.erreur, ko: true }; rendre();
      }, () => { etat.busy[id] = false; etat.msg[id] = { t: L.erreur, ko: true }; rendre(); });
    }));
    $.querySelectorAll('details[data-nouveau]').forEach(d => d.addEventListener('toggle', () => {
      if (!d.open || APERCU || d._lu) { return; }
      d._lu = true;
      const id = Number(d.getAttribute('data-rap'));
      poster('/suivi/franchise/lu', { visite: id }).then(() => { const r = (D.rapports || []).find(x => x.id === id); if (r) { r.luLe = new Date().toISOString(); } const n = d.querySelector('.fr-new'); if (n) { n.remove(); } });
    }));
  }

  if (!SHOP || !JETON) { $.innerHTML = '<div class="fr-carte">' + LIEN_KO.map(t => '<p class="fr-mu" style="margin:4px 0">' + esc(t) + '</p>').join('') + '</div>'; return; }
  charger();
})();
