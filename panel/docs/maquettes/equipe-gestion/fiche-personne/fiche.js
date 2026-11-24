/* Maquette « la fiche d'une personne » (Mon équipe, 24/11/2026 : « dans Mon équipe je n'ai pas accès à l'édition de
 * chaque membre du personnel, évaluation, coût… fais une maquette (2) screenshot »).
 * Posée sur le vrai dashboard servi avec la fausse API des vidéos (données FICTIVES : Magasin démo, Camille Roussel).
 * Les champs sont ceux que TF Buddy garde (swagger : Employee, EmployeeWrite, EmployeeEvaluationRaw) ; rien de plus.
 * Onglet Ventes (24/11/2026, « rajouter un onglet avec ses KPI de vente et un graphique sur les 12 dernières semaines,
 * toggle ventes en euros / panier moyen / pièces par ticket ») : les barres pour un total (les ventes), la courbe pour
 * une moyenne (panier, pièces) avec deux repères nommés sur la ligne (moyenne de l'équipe, cible du réseau) ; une seule
 * série, pas de légende ; couleurs du design system (--chart-real, --chart-goal), validées.
 *   window.FP.monter('a-identite' | 'a-evaluations' | 'a-ventes-euros' | 'a-ventes-panier' | 'a-ventes-pieces' | 'b-page') */
(function () {
  const et = (n, gros) => `<span class="fp-et${gros ? ' gros' : ''}">${'★'.repeat(n)}<i>${'★'.repeat(5 - n)}</i></span>`;
  const P = {
    nom: 'Camille Roussel', ini: 'CR', fonction: 'Vendeuse', postes: ['Vente comptoir', 'Caisse'], taux: '15,80',
    heures: '104 h', services: 19, cout: '1 643 €', part: '16 %', moy: '4,3', notes: 12, anoter: 3,
    ca: '7 940 €', tickets: 548, primes: '75 €',
  };
  const tete = (fermer) => `<div class="fp-tete"><span class="fp-av">${P.ini}</span><div><div class="fp-nm">${P.nom}</div>
      <div class="fp-sous"><span class="chip chip--neutral">${P.fonction}</span><span class="chip chip--neutral">Contrat de travail</span><span class="chip chip--ok">Active</span><span class="chip chip--neutral">App employés : accès prêt</span></div></div>
      ${fermer ? '<button class="fp-x" aria-label="Fermer">×</button>' : ''}</div>`;
  const onglets = on => `<nav class="page-tabs" aria-label="La fiche">${[['identite', 'Identité et contrat'], ['cout', 'Heures et coût'], ['evaluations', 'Évaluations <span class="page-tabs__count">3 à noter</span>'], ['competences', 'Compétences et postes'], ['ventes', 'Ventes'], ['primes', 'Primes']]
    .map(([k, l]) => `<button type="button" class="page-tabs__tab${k === on ? ' is-on' : ''}">${l}</button>`).join('')}</nav>`;

  /* ── A · la fenêtre, onglet Identité et contrat ── */
  const aIdentite = () => `<div class="fp-cols">
      <div class="fp-bloc"><h4>Identité</h4>
        <div class="fp-ch"><label>Prénom</label><input class="input" value="Camille"></div>
        <div class="fp-ch"><label>Nom</label><input class="input" value="Roussel"></div>
        <div class="fp-ch"><label>Nom affiché</label><input class="input" value="Camille R."><div class="aide">sur la caisse, l’app employés et le planning imprimé</div></div>
        <div class="fp-ch"><label>Langue</label><select class="input"><option>Français</option></select></div>
        <div class="fp-ch"><label>Fonction</label><select class="input"><option>Vendeuse</option></select></div>
      </div>
      <div class="fp-bloc"><h4>Contrat et coût</h4>
        <div class="fp-ch"><label>Type de contrat</label><select class="input"><option>Contrat de travail</option><option>Contrat civil</option><option>B2B</option><option>Intérim</option><option>Stage</option><option>Bénévole</option><option>Autre</option></select></div>
        <div class="fp-ch"><label>Rôle</label><select class="input"><option>Employée</option><option>Franchisé·e (hors primes)</option><option>Associé·e</option></select></div>
        <div class="fp-ch"><label>Taux horaire</label><input class="input fp-mod-chg" value="16,30 €/h"><div class="aide">était <b>15,80 €/h</b> · moyenne de l’équipe 15,77 €/h</div></div>
        <h4 style="margin-top:16px">Accès</h4>
        <div class="fp-ch"><label>Téléphone</label><input class="input" value="+32 4·· ·· ·· 56"><div class="aide">son identifiant pour l’app employés</div></div>
        <div class="fp-ch"><label>PIN de caisse</label><span class="val">défini · <button class="linklike" type="button">réinitialiser</button></span></div>
      </div>
      <div class="fp-effet"><span>Avec <b>16,30 €/h</b> : novembre au planning (104 h) coûte <b>1 695 €</b>, soit <b>+52 €</b></span><span>personnel / CA du mois : <b>16,3 % → 16,4 %</b> (cible 17 %)</span></div>
    </div>`;

  /* ── A · la fenêtre, onglet Évaluations ── */
  const histo = [[5, 6], [4, 4], [3, 2], [2, 0], [1, 0]];
  const aEvaluations = () => `<div class="fp-ev">
      <div><div class="fp-moy">${P.moy}<small>/ 5</small></div><div style="margin-top:6px">${et(4, true)}</div>
        <div class="fp-api" style="margin-top:6px">${P.notes} services notés en novembre · ${P.anoter} à noter</div>
        <div class="fp-histo">${histo.map(([n, k]) => `<div><span>${n} ★</span><span class="b"><i style="width:${k / 6 * 100}%"></i></span><span>${k}</span></div>`).join('')}</div>
        <p class="fp-api" style="margin-top:14px">Une note par service du planning, de 1 à 5, avec un mot. La personne la voit dans l’app employés (« Mon profil »).</p></div>
      <div>
        <div class="fp-titre-l"><h4 class="fp-bloc" style="margin:0"><span style="font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)">À noter</span></h4><span class="fp-tag">3 services passés</span></div>
        <div class="fp-liste" style="margin-bottom:16px">
          <div class="fp-li ouvert"><div class="d">Mer. 18 novembre<small>14:30–19:15 · Vente comptoir</small></div>
            <div class="form"><span class="fp-et clic"><span>★</span><span>★</span><span>★</span><span>★</span><span><i>★</i></span></span>
              <textarea>Bon accueil, vitrine tenue jusqu’à la fermeture.</textarea>
              <div style="display:flex;gap:8px"><button class="btn btn--sm btn--primary" type="button">Enregistrer la note</button><button class="btn btn--sm" type="button">Passer</button></div></div></div>
          <div class="fp-li anoter"><div class="d">Lun. 16 novembre<small>12:45–19:15 · Caisse</small></div><div>${et(0)}</div><div class="c"><button class="linklike" type="button">Noter ce service</button></div></div>
          <div class="fp-li anoter"><div class="d">Sam. 14 novembre<small>07:15–13:45 · Vente comptoir</small></div><div>${et(0)}</div><div class="c"><button class="linklike" type="button">Noter ce service</button></div></div>
        </div>
        <div class="fp-titre-l"><span style="font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)">Historique</span><span class="n">novembre 2026</span></div>
        <div class="fp-liste">
          <div class="fp-li"><div class="d">Ven. 13 novembre<small>13:00–19:15</small></div><div>${et(5)}</div><div class="c">« Très bonne vente des tartes, comptoir rangé. »<small>notée par Fanny Leroy</small></div></div>
          <div class="fp-li"><div class="d">Jeu. 12 novembre<small>12:45–19:15</small></div><div>${et(4)}</div><div class="c">« Un peu lente au rush de midi. »<small>notée par Fanny Leroy</small></div></div>
          <div class="fp-li"><div class="d">Mar. 10 novembre<small>14:30–19:15</small></div><div>${et(5)}</div><div class="c">—<small>notée par Fanny Leroy</small></div></div>
          <div class="fp-li"><div class="d">Dim. 8 novembre<small>07:15–13:45</small></div><div>${et(3)}</div><div class="c">« Ouverture en retard de 10 min. »<small>notée par le franchisé</small></div></div>
        </div>
      </div></div>`;

  /* ── A · la fenêtre, onglet Ventes : ses chiffres et ses 12 dernières semaines ── */
  const SEM = ['31/8', '7/9', '14/9', '21/9', '28/9', '5/10', '12/10', '19/10', '26/10', '2/11', '9/11', '16/11'];
  const SEM_L = ['31 août', '7 sept.', '14 sept.', '21 sept.', '28 sept.', '5 oct.', '12 oct.', '19 oct.', '26 oct.', '2 nov.', '9 nov.', '16 nov.'];
  const VTE = [1720, 1810, 1765, 1890, 1840, 1955, 2010, 1930, 2085, 1980, 2140, 2210];
  const TK = [124, 129, 125, 133, 128, 135, 139, 132, 141, 136, 145, 148];
  const PCS = [2.08, 2.12, 2.10, 2.18, 2.15, 2.21, 2.19, 2.24, 2.27, 2.22, 2.29, 2.33];
  const HRS = [25, 26, 25.5, 26.5, 26, 26, 27, 25.5, 26.5, 26, 26.5, 26];
  const PAN = VTE.map((v, i) => v / TK[i]);
  const sum = a => a.reduce((x, y) => x + y, 0);
  const fE0 = n => Math.round(n).toLocaleString('fr-BE').replace(/\s/g, ' ') + ' €';
  const fD = (n, d) => n.toFixed(d).replace('.', ',');
  const EQUIPE = { panier: 14.05, pieces: 2.14 }, CIBLE = { panier: 13, pieces: 3.6 };
  function graphe(mode) {
    const W = 936, H = 268, g = 58, d = 150, h = 18, b = 236, pw = W - g - d, bande = pw / 12, x = i => g + bande * (i + 0.5);
    const val = mode === 'euros' ? VTE : mode === 'panier' ? PAN : PCS;
    const [lo, hi, pas] = mode === 'euros' ? [0, 3000, 500] : mode === 'panier' ? [12, 16, 1] : [2, 3.8, 0.4];
    const y = v => b - (v - lo) / (hi - lo) * (b - h);
    const fmt = v => mode === 'euros' ? fE0(v) : mode === 'panier' ? fD(v, 2) + ' €' : fD(v, 2);
    let s = '';
    for (let v = lo; v <= hi + 1e-9; v += pas) { s += `<line x1="${g}" x2="${W - d}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="${v === lo ? '#d8d1c6' : 'rgba(34,34,34,.07)'}"/><text x="${g - 8}" y="${(y(v) + 4).toFixed(1)}" text-anchor="end">${mode === 'euros' ? fE0(v) : fD(v, mode === 'panier' ? 0 : 1) + (mode === 'panier' ? ' €' : '')}</text>`; }
    SEM.forEach((l, i) => { s += `<text x="${x(i).toFixed(1)}" y="${b + 18}" text-anchor="middle">${l}</text>`; });
    const sel = 11;
    if (mode === 'euros') {
      const bw = bande * 0.56;
      val.forEach((v, i) => { const x0 = x(i) - bw / 2, y0 = y(v), r = 4;
        s += `<path d="M${x0.toFixed(1)},${b} V${(y0 + r).toFixed(1)} Q${x0.toFixed(1)},${y0.toFixed(1)} ${(x0 + r).toFixed(1)},${y0.toFixed(1)} H${(x0 + bw - r).toFixed(1)} Q${(x0 + bw).toFixed(1)},${y0.toFixed(1)} ${(x0 + bw).toFixed(1)},${(y0 + r).toFixed(1)} V${b} Z" fill="#B03048" opacity="${i === sel ? 1 : 0.78}"/>`; });
      s += `<text class="lab" x="${(x(sel) + bande * 0.36).toFixed(1)}" y="${(y(val[sel]) + 4).toFixed(1)}">${fE0(val[sel])}</text>`;
      const moy = sum(VTE) / 12;
      s += `<line x1="${g}" x2="${W - d}" y1="${y(moy).toFixed(1)}" y2="${y(moy).toFixed(1)}" stroke="#7a736a" stroke-width="1.5" stroke-dasharray="5 4"/><text class="ref" x="${W - d + 8}" y="${(y(moy) + 4).toFixed(1)}">sa moyenne ${fE0(moy)}</text>`;
    } else {
      const ref = (v, c, t) => `<line x1="${g}" x2="${W - d}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="${c}" stroke-width="1.5" stroke-dasharray="5 4"/><text class="ref" x="${W - d + 8}" y="${(y(v) + 4).toFixed(1)}" fill="${c}">${t}</text>`;
      s += ref(EQUIPE[mode], '#7a736a', 'équipe ' + fmt(EQUIPE[mode]));
      s += ref(CIBLE[mode], '#1B6FA8', 'cible du réseau ' + fmt(CIBLE[mode]));
      s += `<line x1="${x(sel).toFixed(1)}" x2="${x(sel).toFixed(1)}" y1="${h}" y2="${b}" stroke="#d8d1c6"/>`;
      s += `<polyline fill="none" stroke="#B03048" stroke-width="2" stroke-linejoin="round" points="${val.map((v, i) => x(i).toFixed(1) + ',' + y(v).toFixed(1)).join(' ')}"/>`;
      val.forEach((v, i) => { s += `<circle cx="${x(i).toFixed(1)}" cy="${y(v).toFixed(1)}" r="${i === sel ? 5 : 4}" fill="#B03048" stroke="#fff" stroke-width="2"/>`; });
    }
    // L'infobulle de la semaine survolée : à gauche du trait, dans une zone vide du graphique (au-dessus des barres ;
    // sous la cible pour le panier ; entre la courbe et la cible pour les pièces) — elle ne cache aucune donnée.
    const haut = mode === 'euros' ? y(2960) : mode === 'panier' ? y(CIBLE.panier) + 5 : y(3.35);
    const tip = `<div class="fp-tip" style="left:${((x(sel) - 300) / W * 100).toFixed(2)}%;top:${(haut / H * 100).toFixed(2)}%"><b>Semaine du ${SEM_L[sel]} · ${fE0(VTE[sel])}</b><span>${TK[sel]} tickets · ${fD(HRS[sel], 0)} h · panier ${fD(PAN[sel], 2)} € · ${fD(PCS[sel], 2)} pièces</span></div>`;
    return `<div class="fp-gr"><svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${mode === 'euros' ? 'Ventes en euros' : mode === 'panier' ? 'Panier moyen' : 'Pièces par ticket'}, 12 dernières semaines">${s}</svg>${tip}</div>`;
  }
  const aVentes = mode => {
    const v = sum(VTE), t = sum(TK), h = sum(HRS), pan = v / t, pcs = sum(PCS.map((p, i) => p * TK[i])) / t;
    const titre = { euros: 'Ventes en euros, par semaine', panier: 'Panier moyen, par semaine', pieces: 'Pièces par ticket, par semaine' }[mode];
    return `<section class="card eqp-kpis fp-kpis"><div class="kpi-row">
        <div class="kpi"><div class="kpi__value">${fE0(v)}</div><div class="kpi__label">Vendu · 12 semaines</div><div class="kpi__note">+18 % sur les 12 d’avant</div></div>
        <div class="kpi"><div class="kpi__value">${fD(pan, 2)} €</div><div class="kpi__label">Panier moyen</div><div class="kpi__note">équipe ${fD(EQUIPE.panier, 2)} € · cible ${fD(CIBLE.panier, 0)} €</div></div>
        <div class="kpi"><div class="kpi__value">${fD(pcs, 2)}</div><div class="kpi__label">Pièces par ticket</div><div class="kpi__note">équipe ${fD(EQUIPE.pieces, 2)} · cible ${fD(CIBLE.pieces, 1)}</div></div>
        <div class="kpi"><div class="kpi__value">${t.toLocaleString('fr-BE').replace(/\s/g, ' ')}</div><div class="kpi__label">Tickets</div><div class="kpi__note">${fD(t / 12, 0)} par semaine</div></div>
        <div class="kpi"><div class="kpi__value">${fE0(v / h)}<span class="u">/ h</span></div><div class="kpi__label">Vendu par heure</div><div class="kpi__note">équipe 71 € / h · ${fD(h, 0)} h au planning</div></div>
        <div class="kpi"><div class="kpi__value">2<sup>e</sup><span class="u">/ 6</span></div><div class="kpi__label">Rang dans l’équipe</div><div class="kpi__note">au vendu par heure</div></div>
      </div></section>
      <div class="fp-gr-tete"><div><h4>${titre}</h4><span class="fp-api">les 12 dernières semaines complètes, du 31 août au 22 novembre</span></div><span class="sp"></span>
        <div class="seg" role="group" aria-label="Mesure">${[['euros', 'Ventes en €'], ['panier', 'Panier moyen'], ['pieces', 'Pièces par ticket']].map(([k, l]) => `<button type="button" class="${k === mode ? 'is-on' : ''}">${l}</button>`).join('')}</div>
        <button class="linklike" type="button">Voir en tableau</button></div>
      ${graphe(mode)}
      <p class="fp-api" style="margin:6px 0 0">${mode === 'euros' ? 'Le trait gris : sa moyenne sur les 12 semaines. La dernière semaine est survolée.' : 'Les traits : la moyenne de l’équipe (gris) et la cible du réseau (bleu). La dernière semaine est survolée.'} Pièces par ticket = lignes par ticket, la règle du cockpit.</p>`;
  };
  const piedVt = `<div class="fp-pied"><span class="fp-api">Lu dans TF Buddy : <code>GET /shops/{id}/statistics/sales/employees/{date}</code> (vendu, tickets, jour par jour) et la moisson des tickets du cockpit (lignes par ticket, depuis août 2026)</span><span class="sp"></span><button class="btn" type="button">Fermer</button></div>`;

  const fenetre = (on, corps, pied) => `<div class="fp-voile" id="fp"><div class="card fp-mod" role="dialog" aria-label="Fiche de ${P.nom}">${tete(true)}${onglets(on)}<div class="fp-corps">${corps}</div>${pied}</div></div>`;
  const piedId = `<div class="fp-pied"><button class="linklike danger" type="button">Désactiver Camille</button><span class="fp-api">Écrit dans TF Buddy (<code>PATCH /employees/{id}</code>) · rien n’est envoyé avant « Enregistrer »</span><span class="sp"></span><button class="btn" type="button">Annuler</button><button class="btn btn--primary" type="button">Enregistrer</button></div>`;
  const piedEv = `<div class="fp-pied"><span class="fp-api">Lu et écrit dans TF Buddy (<code>GET /employees/{id}/evaluations</code>, <code>POST /schedule/{service}/evaluation</code>)</span><span class="sp"></span><button class="btn" type="button">Fermer</button></div>`;

  /* ── B · la page de la personne ── */
  const bPage = () => `<div class="fp-page">
      <div class="fp-fil"><button class="linklike" type="button">‹ L’équipe</button><span>Mon équipe › ${P.nom}</span></div>
      <section class="card fp-ptete"><span class="fp-av">${P.ini}</span><div><div class="fp-nm">${P.nom}</div>
        <div class="fp-sous"><span class="chip chip--neutral">${P.fonction}</span><span class="chip chip--neutral">Contrat de travail</span><span class="chip chip--ok">Active</span><span class="chip chip--neutral">${P.postes.join(' · ')}</span></div></div>
        <div class="act"><button class="btn btn--sm" type="button">Noter un service</button><button class="btn btn--sm btn--primary" type="button">Modifier</button></div></section>
      <section class="card eqp-kpis" style="margin-top:14px"><div class="kpi-row">
        <div class="kpi"><div class="kpi__value">15,80<span class="u">€/h</span></div><div class="kpi__label">Taux horaire</div><div class="kpi__note">moyenne de l’équipe 15,77 €/h</div></div>
        <div class="kpi"><div class="kpi__value">${P.heures}</div><div class="kpi__label">Heures de novembre</div><div class="kpi__note">${P.services} services au planning</div></div>
        <div class="kpi"><div class="kpi__value">${P.cout}</div><div class="kpi__label">Coût du mois</div><div class="kpi__note">${P.part} du personnel du magasin</div></div>
        <div class="kpi"><div class="kpi__value">${P.moy}<span class="u">/ 5</span></div><div class="kpi__label">Note moyenne</div><div class="kpi__note">${P.notes} services notés · <span style="color:var(--prize-ink)">${P.anoter} à noter</span></div></div>
        <div class="kpi"><div class="kpi__value">${P.ca}</div><div class="kpi__label">Vendu en novembre</div><div class="kpi__note">${P.tickets} tickets · 76 € / h</div></div>
        <div class="kpi"><div class="kpi__value">${P.primes}</div><div class="kpi__label">Primes acquises</div><div class="kpi__note">en tête de Queen of Tartes</div></div>
      </div></section>
      <div class="fp-grille">
        <div style="display:grid;gap:14px">
          <section class="card"><h3>Identité et contrat <button class="linklike" type="button">Modifier</button></h3>
            <dl class="fp-dl"><dt>Prénom, nom</dt><dd>Camille Roussel</dd><dt>Nom affiché</dt><dd>Camille R.</dd><dt>Fonction</dt><dd>Vendeuse</dd><dt>Type de contrat</dt><dd>Contrat de travail</dd><dt>Rôle</dt><dd>Employée (dans les primes)</dd><dt>Taux horaire</dt><dd>15,80 €/h</dd><dt>Langue</dt><dd>Français</dd><dt>Téléphone</dt><dd>+32 4·· ·· ·· 56</dd><dt>PIN de caisse</dt><dd>défini · <button class="linklike" type="button" style="padding:0">réinitialiser</button></dd></dl></section>
          <section class="card"><h3>Heures et coût, semaine par semaine</h3>
            <table class="fp-sem"><thead><tr><th>Semaine</th><th class="num">Services</th><th class="num">Heures</th><th class="num">Coût</th></tr></thead><tbody>
              <tr><td>du 2 novembre</td><td class="num">5</td><td class="num">26 h</td><td class="num">411 €</td></tr>
              <tr><td>du 9 novembre</td><td class="num">5</td><td class="num">26,5 h</td><td class="num">419 €</td></tr>
              <tr><td>du 16 novembre</td><td class="num">5</td><td class="num">26 h</td><td class="num">411 €</td></tr>
              <tr><td>du 23 novembre</td><td class="num">4</td><td class="num">25,5 h</td><td class="num">403 €</td></tr>
              <tr class="tot"><td>Novembre</td><td class="num">19</td><td class="num">104 h</td><td class="num">1 643 €</td></tr></tbody></table>
            <p class="fp-note">Coût = heures du planning × taux horaire. TF Buddy ne garde pas le coût employeur (brut, charges) : il n’est pas ici.</p></section>
          <section class="card"><h3>Compétences et postes <button class="linklike" type="button">Modifier</button></h3>
            <div style="font-size:12.5px">Vendeuse · <b style="font-weight:700">niveau 2</b> sur 4</div><div class="fp-niv"><i class="on"></i><i class="on"></i><i></i><i></i></div>
            <div class="fp-note" style="margin:0 0 10px">Pour le niveau 3 : ouvrir le magasin seule, former une nouvelle.</div>
            <div class="fp-comp"><span class="chip chip--ok">✓ Encaissement</span><span class="chip chip--ok">✓ Conseil client</span><span class="chip chip--ok">✓ Mise en vitrine</span><span class="chip chip--ok">✓ Hygiène (HACCP)</span><span class="chip non">Ouverture du magasin</span><span class="chip non">Former une nouvelle</span></div>
            <h3 style="margin-top:14px">Postes de travail</h3><div class="fp-comp"><span class="chip chip--neutral">Vente comptoir</span><span class="chip chip--neutral">Caisse</span></div></section>
        </div>
        <div style="display:grid;gap:14px">
          <section class="card"><h3>Évaluations <span class="fp-tag" style="text-transform:none;letter-spacing:0">3 à noter</span><button class="linklike" type="button">Tout l’historique</button></h3>
            <div class="fp-ev-b"><div><div class="fp-moy">${P.moy}<small>/ 5</small></div><div style="margin-top:6px">${et(4)}</div></div>
              <div class="fp-histo" style="margin:0">${histo.map(([n, k]) => `<div><span>${n} ★</span><span class="b"><i style="width:${k / 6 * 100}%"></i></span><span>${k}</span></div>`).join('')}</div></div>
            <div class="fp-liste">
              <div class="fp-li anoter"><div class="d">Mer. 18 novembre<small>14:30–19:15</small></div><div>${et(0)}</div><div class="c"><button class="linklike" type="button" style="padding:0">Noter ce service</button></div></div>
              <div class="fp-li"><div class="d">Ven. 13 novembre<small>13:00–19:15</small></div><div>${et(5)}</div><div class="c">« Très bonne vente des tartes, comptoir rangé. »<small>notée par Fanny Leroy</small></div></div>
              <div class="fp-li"><div class="d">Jeu. 12 novembre<small>12:45–19:15</small></div><div>${et(4)}</div><div class="c">« Un peu lente au rush de midi. »<small>notée par Fanny Leroy</small></div></div>
              <div class="fp-li"><div class="d">Dim. 8 novembre<small>07:15–13:45</small></div><div>${et(3)}</div><div class="c">« Ouverture en retard de 10 min. »<small>notée par le franchisé</small></div></div>
            </div></section>
          <section class="card"><h3>Ventes et primes · novembre <button class="linklike" type="button">Les primes de l’équipe</button></h3>
            <div class="fp-pr">
              <div><span>Ventes croisées</span><span class="ok">✓ 50 € · 51 %</span></div>
              <div><span>Bats ton record</span><span>2,22 art./ticket · cible 2,35</span></div>
              <div><span>Défi du magasin · ventes croisées</span><span>51 % · cible 52 %</span></div>
              <div><span>Meilleure vendeuse</span><span>3<sup>e</sup> / 6 · score 79,6</span></div>
              <div><span>Queen of Tartes</span><span class="t">en tête · 64 pcs</span></div>
              <div><span>Le magasin, ensemble (objectif, note Google)</span><span class="ok">+25 € si tout est atteint</span></div>
            </div>
            <p class="fp-note">Lu dans la page des primes (Mon équipe › Primes), comme Camille le voit dans « Mes primes ».</p></section>
        </div>
      </div>
    </div>`;

  window.FP = {
    monter(etat) {
      const vieux = document.getElementById('fp'); if (vieux) { vieux.remove(); }
      const eqp = document.querySelector('.eqp');
      if (etat === 'a-identite') { document.body.insertAdjacentHTML('beforeend', fenetre('identite', aIdentite(), piedId)); }
      if (etat === 'a-evaluations') { document.body.insertAdjacentHTML('beforeend', fenetre('evaluations', aEvaluations(), piedEv)); }
      if (etat.startsWith('a-ventes-')) { document.body.insertAdjacentHTML('beforeend', fenetre('ventes', aVentes(etat.slice(9)), piedVt)); }
      if (etat === 'b-page' && eqp) {
        [...eqp.children].forEach(c => { if (!c.matches('nav')) { c.style.display = 'none'; } });
        eqp.querySelectorAll('nav .page-tabs__tab').forEach(t => t.classList.toggle('is-on', /Responsabilit/.test(t.textContent)));
        eqp.insertAdjacentHTML('beforeend', '<div id="fp">' + bPage() + '</div>');
      }
      return etat;
    },
  };
})();
