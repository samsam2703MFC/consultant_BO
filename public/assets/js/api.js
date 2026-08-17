/* Cockpit CEO — L'Atelier by · couche d'accès unique aux données.
 *
 * Règle : aucune valeur métier n'est écrite dans le HTML. Chaque écran lit un
 * endpoint REST, la réponse est normalisée ici, et le composant ne connaît que
 * la forme normalisée. Contrat JSON + mapping base de données : contrat-api.md
 *
 * Base d'URL : window.COCKPIT_API_BASE (sinon /api/cockpit).
 * Si l'API est injoignable, le jeu de démonstration (data.js) est chargé pour
 * que la maquette reste consultable — la source est indiquée dans p.source.
 */

/* Base d'URL de l'API : surcharge via window.COCKPIT_API_BASE, sinon déduite
 * de l'URL de la page — « api/cockpit » à côté d'index.html, ce qui fonctionne
 * à la racine (/api/cockpit) comme sous un sous-répertoire
 * (/consulant_bo/api/cockpit). */
export const API_BASE = (typeof window !== 'undefined' && window.COCKPIT_API_BASE)
  || (typeof location !== 'undefined' ? location.pathname.replace(/[^/]*$/, '') + 'api/cockpit' : '/api/cockpit');

export const ENDPOINTS = {
  meta:           '/meta',
  leviers:        '/referentiels/leviers',
  kpis:           '/referentiels/kpis',
  emailTemplates: '/referentiels/email-templates',
  projTemplates:  '/referentiels/project-templates',
  stores:         '/stores?statut=tous',
  perf:           '/stores/perf?granularite=mois&annees=2025,2026',
  budgets:        '/stores/budgets?exercice=2026',
  targets:        '/targets',
  consultants:    '/consultants',
  suppliers:      '/fournisseurs',
  projects:       '/projects',
  crm:            '/projects/crm',
  people:         '/people',
  reporting:      '/reporting',
  journal:        '/journal',
  products:       '/products/scoring?periode=2026-07',
  pwaReports:     '/pwa/reports',
  fbRegles:       '/referentiels/facebook-regles',
  fbPosts:        '/facebook/posts'
};

async function get(path, signal){
  const r = await fetch(API_BASE + path, { headers: { Accept: 'application/json' }, signal, credentials: 'same-origin' });
  if (!r.ok) throw new Error(path + ' → HTTP ' + r.status);
  return r.json();
}

/* --- Authentification intégrée ------------------------------------------- */

/** État d'auth : { setup, authed } — ou null si l'API est injoignable
 *  (mode démo : pas d'authentification). */
export async function authStatus(){
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), 4000);
  try {
    const r = await fetch(API_BASE + '/auth/status', { headers: { Accept: 'application/json' }, signal: ctl.signal, credentials: 'same-origin' });
    clearTimeout(t);
    if (!r.ok) return null;
    return await r.json();
  } catch (e) { clearTimeout(t); return null; }
}

/** POST /auth/setup ou /auth/login — renvoie { ok } ou { error }. */
export async function authSubmit(mode, password){
  try {
    const r = await fetch(API_BASE + '/auth/' + (mode === 'setup' ? 'setup' : 'login'), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ password })
    });
    return await r.json();
  } catch (e) { return { error: 'API injoignable — réessayez.' }; }
}

export function authLogout(){
  return fetch(API_BASE + '/auth/logout', { method: 'POST', credentials: 'same-origin' }).catch(() => null);
}

export async function load(opts){
  const timeoutMs = (opts && opts.timeoutMs) || 4000;
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), timeoutMs);
  try {
    const keys = Object.keys(ENDPOINTS);
    const res = await Promise.all(keys.map(k => get(ENDPOINTS[k], ctl.signal)));
    clearTimeout(t);
    const p = {}; keys.forEach((k, i) => { p[k] = res[i]; });
    return shape(p, 'api');
  } catch (e) {
    clearTimeout(t);
    console.info('[cockpit] API indisponible (' + e.message + ') — jeu de démonstration chargé.');
    const mod = await import('./data.js');
    return shape(demoPayload(mod), 'demo');
  }
}

/** Écriture non bloquante : ignorée en mode démo, best-effort en mode API. */
export function write(source, method, path, payload){
  if (source !== 'api') return Promise.resolve(null);
  return fetch(API_BASE + path, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: payload === undefined ? undefined : JSON.stringify(payload)
  }).catch(e => console.warn('[cockpit] écriture ' + path + ' échouée :', e.message));
}

/* Normalisation : une seule forme consommée par le composant. */
function shape(p, source){
  const meta = p.meta;
  return {
    source, meta,
    M: { MOIS: meta.moisLabels, TODAY: meta.aujourdhui, LEVIERS: p.leviers, KPI_LIST: p.kpis.map(k => k.nom || k),
      FAMILLES: p.familles || meta.familles, REPORT_TYPES: p.reportTypes || meta.reportTypes,
      // Niveaux, seuil et référentiel du signalement — réglage serveur,
      // jamais une constante d'écran.
      SIGNAL: meta.signalement || { seuil: 4, niveaux: [], familles: [] },
      // Pack de règles de l'agent de contrôle Facebook. Les cinq niveaux de
      // notes ne sont PAS ici : ce sont ceux de SIGNAL, une seule échelle de
      // conformité pour les tâches consultants comme pour les posts.
      FB: p.fbRegles || { seuil: 4, familles: [], regles: [] } },
    D: Object.assign({}, p.raw || {}, {
      stores: joinPerf(p.stores, p.perf),
      budgets: p.budgets,
      targets: p.targets,
      consultants: p.consultants,
      suppliers: p.suppliers,
      projects: p.projects,
      projTemplates: p.projTemplates,
      emailTemplates: p.emailTemplates,
      crm: p.crm,
      people: p.people,
      reports: (p.reporting || {}).reports,
      alertRules: (p.reporting || {}).alertRules,
      logs: p.journal,
      products: p.products,
      pwaReports: p.pwaReports,
      fbPosts: p.fbPosts
    })
  };
}

/* /stores/perf renvoie des lignes plates (storeId, annee, mois, …) —
   le composant lit store.perf[annee][mois]. */
function joinPerf(stores, perf){
  if (!perf || !perf.length) return stores;
  const by = {};
  for (const r of perf){
    const s = (by[r.storeId] = by[r.storeId] || {});
    const y = (s[r.annee] = s[r.annee] || new Array(12).fill(null));
    y[r.mois - 1] = { ca: r.ca, caT: r.caBudget, marge: r.margeNette, mp: r.margePct, tickets: r.tickets,
      panier: r.panierMoyen, food: r.foodCostPct, labour: r.labourCostPct, overhead: r.overheadPct, val: r.valorisation };
  }
  return stores.map(s => Object.assign({}, s, { perf: by[s.id] || {} }));
}

/* --- Jeu de démonstration : même forme que les endpoints ------------------ */
function demoPayload(m){
  const D = m.buildData();
  const seuils = { food: 32, labour: 33, overhead: 13.5, royalties: 3, financieres: 2.2, caEtp: 13000 };
  const meta = {
    reseau: { nom: "L'Atelier by", sousTitre: 'Cockpit CEO — Réseau' },
    utilisateur: { initiales: 'GB', nom: 'G. Baert', role: 'CEO · admin' },
    aujourdhui: m.TODAY,
    dateLabel: 'Vendredi 31 juillet 2026',
    periodeLabel: 'Données : juillet 2026',
    exercice: 2026,
    moisLabels: m.MOIS,
    seuils: seuils,
    contribOuverture: 210000,
    signalement: m.SIGNALEMENT,
    notes: { objectifsOuverture: "Dont contribution attendue de l'ouverture de Knokke (oct.–déc.) : env. 210 k€." }
  };
  const budgets = D.stores.filter(s => s.status === 'Ouvert').map(s => {
    const budAn = (s.perf && s.perf[2026] || []).reduce((t, r) => t + (r.caT || 0), 0);
    const seed = s.id.split('').reduce((t, ch) => t + ch.charCodeAt(0), 0);
    const coef = 0.92 + ((seed % 21) / 100);   // 0,92 → 1,12 du budget validé
    return {
    storeId: s.id, exercice: 2026, moisEncodes: 7, moisTotal: 12, dernierEncodage: '2026-08-04',
    panierEngagement: +(s.panier * 1.08).toFixed(2),
    caTheoriqueAn: Math.round(budAn * coef / 1000) * 1000,
    etudeMarche: { date: '2025-09-15', source: 'Étude de marché — zone de chalandise 10 min', potentielMenages: 4200 + (seed % 17) * 130 },
    charges: [
      { poste: 'Matières premières', levier: 'food-cost', pctBudget: seuils.food, champReel: 'food' },
      { poste: 'Rémunérations & charges sociales', levier: 'labour-cost', pctBudget: seuils.labour, champReel: 'labour' },
      { poste: 'Services & biens divers', levier: 'overhead-cost', pctBudget: seuils.overhead, champReel: 'overhead' },
      { poste: 'Royalties', levier: '', pctBudget: seuils.royalties, champReel: null },
      { poste: 'Charges financières', levier: '', pctBudget: seuils.financieres, champReel: null }
    ]
  }; });
  // Rapports du panel consultant (pwa_consultant) — même forme que /pwa/reports.
  const pwaBase = 'https://panel.atelierby.be';
  const ouverts = D.stores.filter(s => s.status === 'Ouvert');
  const pwaReports = {
    base: pwaBase,
    magasins: ouverts.map((s, i) => ({ id: s.id, nom: s.nom, pwaId: i + 1 })),
    partages: [
      { label: 'Rapport mensuel — Bruxelles Châtelain — juin 2026', ym: '2026-06', magasin: 'Bruxelles — Châtelain', consultant: 'Marc Janssens',
        url: pwaBase + '/r/tok_demo_cha_2026-06_aK3xW9pQvR7mZsL0dF2gH4jN8bYcE6tU1', cree: '2026-07-03', expire: '2026-07-17', etat: 'Expiré', opens: 7, derniereOuverture: '2026-07-15' },
      { label: 'Rapport mensuel — Liège Le Carré — juin 2026', ym: '2026-06', magasin: 'Liège — Le Carré', consultant: 'Marc Janssens',
        url: pwaBase + '/r/tok_demo_lie_2026-06_bT5yV2nM8cX4kP0qW7rZ1sD9fG3hJ6lA2', cree: '2026-07-04', expire: '2026-07-18', etat: 'Expiré', opens: 12, derniereOuverture: '2026-07-16' },
      { label: 'Rapport mensuel — Gand Korenmarkt — juillet 2026', ym: '2026-07', magasin: 'Gand — Korenmarkt', consultant: 'Sofia Ricci',
        url: pwaBase + '/r/tok_demo_gnd_2026-07_cU8wQ4rN1mY6zT3vK9xB5dH0jF7gL2pS3', cree: '2026-08-02', expire: '2026-08-16', etat: 'Actif', opens: 3, derniereOuverture: '2026-08-05' },
      { label: 'Rapport mensuel — Namur Marché — mai 2026', ym: '2026-05', magasin: 'Namur — Marché', consultant: 'Élise Dupont',
        url: pwaBase + '/r/tok_demo_nam_2026-05_dV1zX7sP4nW2qM9rT5yK8cB3fJ6hG0lD4', cree: '2026-06-05', expire: '2026-06-19', etat: 'Révoqué', opens: 1, derniereOuverture: '2026-06-06' }
    ]
  };
  return {
    raw: D, meta, leviers: m.LEVIERS, kpis: m.KPI_LIST, familles: m.FAMILLES, reportTypes: m.REPORT_TYPES, emailTemplates: D.emailTemplates, projTemplates: D.projTemplates,
    stores: D.stores, perf: null, budgets, targets: D.targets, consultants: D.consultants, suppliers: D.suppliers,
    projects: D.projects, crm: D.crm, people: D.people,
    reporting: { reports: D.reports, alertRules: D.alertRules }, journal: D.logs, products: D.products,
    pwaReports,
    // Contrôle Facebook : le pack de règles et les posts, même forme que
    // /referentiels/facebook-regles et /facebook/posts.
    fbRegles: m.FB_CONTROLE, fbPosts: m.FB_POSTS
  };
}
