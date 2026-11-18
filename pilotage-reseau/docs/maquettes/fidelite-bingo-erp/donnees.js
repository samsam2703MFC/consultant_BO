// Données de démonstration et composants partagés de la maquette.
// Règle : uniquement la structure existante de l'ERP (champs de form.twig / points.twig, points_config tel quel).
// Aucune table, aucun champ, aucune clé ajoutés. Le handoff Claude Design ne fournit que l'habillage.
'use strict';

const ILL = '../../../public/fidelite-test/ill/';
const LOGO = '../../../public/assets/img/logo.png';
const SHOPS = [['Corbais', 2], ['Gosselies', 3], ['Halle', 4], ['Sombreffe', 5]];

// La carte de démonstration, telle que le formulaire l'enverrait (noms des champs de l'ERP).
const CARD = {
  name: 'Bingo d’hiver', code: 'fid-3f9a2c71b0e4d58a91c6e207', sort_order: 0,
  stamps_required: 13, reward_label: 'Une pâtisserie offerte', reward_validity_days: 30,
  starts_at: '2026-11-22 23:00:00', ends_at: '2027-02-21 23:00:00', shop_selection: 'ALL',
  color_tint: '#F4EFE8', color_fill: '#8D1D2C', color_accent: '#F2C9A0', image_path: '/franchise-images/sidebar-logo.jpg',
  points_config: { stamps_enabled: true, points_enabled: true, points_per_slice: 1, slice_cents: 100, voucher_validity_days: 30, points_validity_days: 365,
    shop_coefficients: { 2: '1', 3: '1', 4: '1', 5: '1' }, bonuses: [{ name: 'Après-midi calme', days: [2], from: '14:00', to: '17:00', coefficient: '2' }], tiers: [{ points: 100, voucher_cents: 500 }] },
};

// ── Annotations ────────────────────────────────────────────────────────
const v = (k) => `<code class="var">${k}</code>`;   // variable ERP existante

// ── Icônes (traits simples) ────────────────────────────────────────────
const P = {
  grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
  card: '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 9.5h19M6 15h4"/>',
  shop: '<path d="M4 10v10h16V10M3 10l2-6h14l2 6zM9 20v-6h6v6"/>',
  gear: '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>',
  users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.8c1.8.7 3 2.4 3.5 5.2"/>',
  tag: '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.4"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  book: '<path d="M4 4h6a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2H4zM20 4h-6a2 2 0 0 0-2 2v14a2 2 0 0 1 2-2h6z"/>',
  mega: '<path d="M3 10v4h4l7 5V5L7 10zM18 8.5a5 5 0 0 1 0 7"/>',
  search: '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  eye: '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  sliders: '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
  pencil: '<path d="M4 20l4-1 11-11-3-3L5 16zM14 6l3 3"/>',
  dots: '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>',
  info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/>',
  left: '<path d="M15 5l-7 7 7 7"/>', right: '<path d="M9 5l7 7-7 7"/>',
  gift: '<rect x="3.5" y="9" width="17" height="11" rx="1.5"/><path d="M3 9h18M12 9v11M12 9c-1.5-4-6-4-5-1.5S12 9 12 9zM12 9c1.5-4 6-4 5-1.5S12 9 12 9z"/>',
  award: '<circle cx="12" cy="9" r="5.5"/><path d="M8.5 13.5L7 21l5-2.5 5 2.5-1.5-7.5"/>',
};
const ico = (n, s = 15) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${P[n]}</svg>`;

const dm = (sql) => { const d = new Date(sql.replace(' ', 'T') + 'Z'); d.setUTCHours(d.getUTCHours() + 1); return String(d.getUTCDate()).padStart(2, '0') + '/' + String(d.getUTCMonth() + 1).padStart(2, '0'); };
const dmy = (sql) => { const d = new Date(sql.replace(' ', 'T') + 'Z'); d.setUTCHours(d.getUTCHours() + 1); return dm(sql) + '/' + d.getUTCFullYear(); };
const veille = (sql) => { const d = new Date(sql.replace(' ', 'T') + 'Z'); d.setUTCHours(d.getUTCHours() - 23); return d.toISOString().slice(0, 19).replace('T', ' '); };

// ── Aperçu de la carte (form.twig › .lw-card-preview), habillé comme la carte imprimée du handoff ──
// Tout vient des champs existants : name, image_path, couleurs, stamps_required, reward_label,
// reward_validity_days, starts_at / ends_at, points_config. Les illustrations des cases sont décoratives
// (fichiers statiques, comme pattern.svg et stamp.svg aujourd'hui), elles ne portent aucune donnée.
const DECOR = ['croissant', 'bread-1', 'cake-slice', 'chocolat', 'cookies', 'tartelette', 'bread-2', 'cupcake', 'crepes', 'tarte', 'galette', 'cake', 'croissant'];
function carte(c = CARD, { exemple = 5 } = {}) {
  const [mot, ...reste] = c.name.split(' ');
  const N = Math.min(c.stamps_required, 20), cols = 4, span = cols - (N % cols || cols) || cols;
  let cells = '';
  for (let i = 1; i <= N; i++) {
    const on = i <= exemple;
    cells += `<div class="w ${on ? 'on' : ''}"><div class="w-top"><b>${i}</b></div><img src="${ILL}${DECOR[(i - 1) % DECOR.length]}.png" alt="">${on ? '<span class="tampon">✓</span>' : ''}</div>`;
  }
  const pc = c.points_config, pts = pc.points_enabled;
  cells += `<div class="sup" style="grid-column:span ${N % cols ? span : cols}"><div class="fr"><img src="${ILL}cake.png" alt=""><div><span class="lab">${c.stamps_required} tampons</span><h5>VOTRE CADEAU</h5><span class="rl">${c.reward_label}</span>Valable ${c.reward_validity_days} jours.</div></div></div>`;
  return `<div class="bc" style="--tint:${c.color_tint};--fill:${c.color_fill};--acc:${c.color_accent}">
    <div class="bc-head"><img src="${LOGO}" alt=""><div class="bc-kick">Carte fidélité<br>${dmy(c.starts_at)} – ${dmy(veille(c.ends_at))}</div></div>
    <div class="bc-title"><span class="bc-bingo">${mot.toUpperCase()}</span><span class="bc-script">${reste.join(' ')}</span></div>
    <p class="bc-lead">PAIN • CAFÉ • BELLES JOURNÉES · <em>du ${dm(c.starts_at)} au ${dm(veille(c.ends_at))}</em></p>
    <div class="bc-grid" style="grid-template-columns:repeat(${cols},1fr)">${cells}</div>
    <div class="bc-foot"><div><h4>Comment ça marche</h4>Un tampon pour chaque produit du programme payé. Au ${c.stamps_required}e tampon : <em>${c.reward_label.toLowerCase()}</em>, valable ${c.reward_validity_days} jours.${pts ? `<br><em>Et vos points :</em> ${pc.points_per_slice} point par ${(pc.slice_cents / 100).toFixed(2).replace('.', ',')} €. ${pc.tiers.map((t) => `${t.points} points = ${t.voucher_cents / 100} € offerts`).join(', ')}.` : ''}</div>
    <div class="bc-merci">Merci de votre fidélité ♡</div></div>
  </div>`;
}

// ── Aperçu « Carte à points » (loyalty-points.js › board), habillé comme l'onglet Points du handoff ──
function apercuPoints(c = CARD, solde = 78) {
  const pc = c.points_config, tiers = [...pc.tiers].sort((a, b) => a.points - b.points), max = tiers.at(-1).points;
  const J = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
  const jours = J.map((d, i) => { const b = pc.bonuses.find((x) => x.days.includes(i)); const k = b ? Number(b.coefficient) : 1;
    return `<div class="jour ${k > 1 ? 'c2' : ''}">${d}<b><i>×</i>${String(k).replace('.', ',')}</b>${b ? `<span>${b.from}–${b.to}</span>` : '<span>&nbsp;</span>'}</div>`; }).join('');
  const N = 30, vals = Array.from({ length: N }, (_, i) => Math.round((max * i) / (N - 1)));
  let cur = 0; vals.forEach((x, i) => { if (x <= solde) cur = i; });
  let board = '';
  for (let r = 5; r >= 0; r--) for (let col = 0; col < 5; col++) {
    const i = r * 5 + (r % 2 ? 4 - col : col), rw = i === N - 1;
    board += `<span class="${i === cur ? 'cur' : rw ? 'rw' : vals[i] <= solde ? 'e' : ''}">${vals[i]}${rw ? ' · ' + tiers.at(-1).voucher_cents / 100 + ' €' : ''}</span>`;
  }
  return `<div class="pp">
    <div class="pp-h"><div><span class="pp-t">POINTS</span><span class="pp-s">simulation</span></div><div class="pp-r"><b>${solde} pts</b><small>encore ${max - solde} pts pour ${tiers.at(-1).voucher_cents / 100} €</small></div></div>
    <div class="bar ab"><i style="width:${(100 * solde) / max}%"></i></div>
    <div class="pp-card"><div class="k">Bonus jour et heure <span>${v('bonuses')}</span></div><div class="jours">${jours}</div>
      <div class="expl"><span class="x" style="background:var(--abricot);color:var(--rubis)">×2</span><span>${pc.bonuses[0].name} : mercredi ${pc.bonuses[0].from}–${pc.bonuses[0].to}, points ×${pc.bonuses[0].coefficient}.</span></div></div>
    <div class="pp-card"><div class="k">Paliers de récompense <span>${v('tiers')}</span></div><div class="board">${board}</div>
      <div style="display:flex;gap:8px;align-items:center;margin-top:10px"><span class="btn sm dis">Échanger ${tiers[0].points} → ${tiers[0].voucher_cents / 100} €</span><span class="hint" style="margin:0">Solde de la simulation.</span></div></div>
  </div>`;
}
