/* Visites terrain — l'application du consultant, du franchisé et de l'admin.
 *
 * Un module classique (pas d'import) monté dans un div : la page PWA
 * `visites/` sur le téléphone, et les écrans « Application consultant » du
 * cockpit sur grand écran. Il possède son DOM, ses données et sa file
 * hors-ligne :
 *   - une lecture (`/visites/app`) gardée en IndexedDB, rendue hors réseau ;
 *   - chaque écriture porte un `client_id`, est appliquée tout de suite à
 *     l'écran, mise en file, envoyée dès que le réseau répond — rejouable
 *     sans doublon ;
 *   - les photos sont réduites par l'appareil (bord long 1600 px, JPEG 0,7,
 *     donc sans EXIF) avant d'entrer dans la file.
 *
 *   window.CockpitVisites.mount(host, { role, id, shop, apiBase, mobile, racine, vue, notify })
 */
(function () {
  'use strict';
  // L'adresse de ce script : les cartes du dashboard magasin (cartes-magasin.js) se chargent à côté quand la page ne les a pas.
  const ICI = (document.currentScript && document.currentScript.src) || '';

  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const FEU = { vert: '🟢', orange: '🟡', rouge: '🔴' };
  const ST_PLAN = { ouvert: ['Ouvert', 'st-ouv'], attente: ['En attente de validation', 'st-att'], valide: ['Validé', 'st-val'], reprendre: ['À reprendre', 'st-rep'], ferme: ['Fermé', 'st-fer'], escalade: ['Escaladé', 'st-esc'],
    // Le suivi des franchisés : un point écrit en visite attend l'envoi du rapport par le bureau ; gardé par lui, il reste interne.
    propose: ['Proposé · rapport au bureau', 'st-att'], interne: ['Interne', 'st-fer'] };
  const ST_VISITE = { planifiee: 'planifiée', confirmee: 'confirmée', en_cours: 'en cours', terminee: 'terminée', annulee: 'annulée' };
  const ASSIGNES = { franchise: 'Franchisé', equipe: 'Équipe', consultant: 'Consultant', admin: 'Admin' };
  const MOTIFS = { reguliere: 'régulière', asap: 'ASAP', due: 'due', revisite: 'revisite' };
  const QUAND = { avant: 'Avant la visite', pendant: 'Pendant la visite', apres: 'Après la visite' };
  const JOURS = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];
  const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const GENRES_JOUR = [['jour_facade', 'Façade'], ['jour_interieur', 'Intérieur'], ['jour_arriere', 'Arrière']];
  const RUBRIQUES_MSP = ['Accueil', 'Produits', 'Hygiène', 'Ambiance'];
  const METEO_ECH = [['moral', 'Moral'], ['envie', 'Envie, motivation'], ['equipe', 'Climat de l’équipe'], ['relation', 'Relation avec le réseau']];
  const METEO_I = { 1: '⛈️', 2: '🌧️', 3: '☁️', 4: '🌤️', 5: '☀️' };
  const EXECUTION = { standards: 'Standards appliqués', raccourcis: 'Quelques raccourcis', ecarts: 'Écarts fréquents' };
  const CLIENTS = { satisfaits: 'Satisfaits', mitiges: 'Mitigés', insatisfaits: 'Insatisfaits' };

  const CSS = `
.vi{font-family:var(--font-ui);color:var(--color-text);font-size:14px;line-height:1.45;position:relative;min-height:100%}
.vi *{box-sizing:border-box}
.vi.mob{padding-bottom:84px}
.vi .sc{padding:0 14px 20px}
.vi.desk .sc{max-width:960px;padding:0}
.vi .card{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:14px;padding:12px 14px;margin-bottom:10px}
.vi .card.alerte{border-color:#e8b4bb}
.vi .cap{font:600 10px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:var(--color-text-muted);margin:14px 2px 6px;display:flex;align-items:center;gap:6px}
.vi .row{display:flex;align-items:center;gap:8px}
.vi .sp{flex:1}
.vi .feu{width:12px;height:12px;border-radius:50%;flex:0 0 auto;display:inline-block}
.vi .feu.vert{background:#2d7a3e}.vi .feu.orange{background:#E0A526}.vi .feu.rouge{background:#C0182B}
.vi .pill{display:inline-flex;align-items:center;gap:4px;font:700 9.5px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;padding:3px 8px;border-radius:8px;white-space:nowrap;background:var(--color-background-secondary);color:var(--color-text)}
.vi .api{background:#E6F2E9;color:#2d7a3e}.vi .loc{background:#F7E4E6;color:#C0182B}.vi .mix{background:#FBEFE0;color:#B26A00}
.vi .P0{background:#C0182B;color:#fff}.vi .P1{background:#E0A526;color:#3a2a00}.vi .P2{background:var(--color-background-secondary);color:var(--color-text)}
.vi .st-ouv{background:#FBEFE0;color:#B26A00}.vi .st-att{background:#FFF6D6;color:#7a5a00}.vi .st-val{background:#E6F2E9;color:#2d7a3e}.vi .st-rep{background:#EEE6FA;color:#5b3d9e}.vi .st-fer{background:var(--color-background-secondary);color:var(--color-text-muted)}.vi .st-esc{background:#C0182B;color:#fff}
.vi .k{font-family:var(--font-display);font-size:22px;line-height:1}
.vi .mu{color:var(--color-text-muted)}.vi .sm{font-size:11.5px}.vi .xs{font-size:10.5px}
.vi .up{color:#2d7a3e}.vi .dn{color:#C0182B}
.vi .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:10px;padding:10px 14px;font:600 13px var(--font-ui);border:.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);cursor:pointer;text-decoration:none}
.vi .btn.p{background:var(--color-primary);color:#fff;border-color:var(--color-primary)}
.vi .btn.w{width:100%}.vi .btn.s{padding:6px 10px;font-size:11.5px;border-radius:8px}
.vi .btn:disabled{opacity:.5;cursor:default}
.vi .btns{display:flex;gap:8px;margin-top:10px}
.vi .bas{position:fixed;left:0;right:0;bottom:0;display:grid;background:var(--color-surface);border-top:.5px solid var(--color-border-tertiary);padding:8px 0 max(14px,env(safe-area-inset-bottom));z-index:5}
.vi .bas button{text-align:center;font:600 10px var(--font-ui);color:var(--color-text-muted);background:none;border:none;cursor:pointer;padding:0}
.vi .bas button i{display:block;font-style:normal;font-size:17px;opacity:.55;margin-bottom:2px}
.vi .bas button.on{color:var(--color-primary)}.vi .bas button.on i{opacity:1}
.vi .onglets{display:flex;gap:6px;margin:0 0 14px;flex-wrap:wrap}
.vi .onglets button{font:600 12px var(--font-ui);padding:7px 12px;border-radius:8px;border:.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);cursor:pointer}
.vi .onglets button.on{background:var(--color-text);color:#fff;border-color:var(--color-text)}
.vi .jour{display:grid;grid-template-columns:54px 1fr;gap:10px;padding:10px 0;border-bottom:.5px solid var(--color-border-tertiary)}
.vi .jour .j{font-size:11px;color:var(--color-text-muted);line-height:1.3}.vi .jour .j b{display:block;font-size:14px;color:var(--color-text)}
.vi .jour.auj .j b{color:var(--color-primary)}
.vi .vis{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:9px 11px;margin-bottom:6px}
.vi .vis b{font-size:14px}
.vi .libre{color:var(--color-text-muted);font-size:12px;padding:6px 0}
.vi .kpi{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.vi.desk .kpi{grid-template-columns:repeat(4,1fr)}
.vi .kpi>div{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:10px 12px}
.vi .kc .k{margin-top:4px}
.vi .or{color:#B26A00}
.vi .sens{display:flex;flex-direction:column;gap:6px}
.vi .sens-l{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:10px;border:.5px solid var(--color-border-secondary);background:var(--color-surface)}
.vi .sens-l .gl-h{cursor:grab;touch-action:none;font-size:18px;color:var(--color-text-muted);padding:0 4px;user-select:none}
.vi .sens-l b{width:22px;height:22px;border-radius:6px;background:var(--color-background-secondary);display:inline-flex;align-items:center;justify-content:center;font-size:12px}
.vi .sens-l.glisse{box-shadow:0 8px 20px rgba(0,0,0,.14);border-color:var(--color-primary);background:#FBF4F0}.vi .sens-l .chip{flex:0 0 auto}
.vi .obl .obs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}@media (max-width:760px){.vi .obl .obs{grid-template-columns:1fr}}
.vi .ob{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:10px;border:.5px solid var(--color-border-tertiary);background:var(--color-surface)}
.vi .ob.ok{background:#F0F7F2;border-color:#BFDCC6}.vi .ob.ko{background:#FBEDEF;border-color:#e8b4bb}
.vi .ob .nm{flex:1;min-width:0;display:flex;flex-direction:column}.vi .ob .nm b{font-size:12.5px;line-height:1.25}.vi .ob .nm small{font-size:10.5px}
.vi .ob .yn{display:inline-grid;grid-template-columns:1fr 1fr;gap:2px;background:var(--color-background-secondary);border-radius:9px;padding:2px;flex:none}
.vi .ob .yn button{border:none;background:none;border-radius:7px;padding:6px 12px;font:700 12px var(--font-ui);color:var(--color-text-muted);cursor:pointer;min-width:46px}
.vi .ob .yn button.ok{background:#2d7a3e;color:#fff}.vi .ob .yn button.ko{background:#C0182B;color:#fff}
.vi .bq-t td{vertical-align:middle}.vi .bq{display:inline-grid;grid-template-columns:1fr 1fr;gap:2px;background:var(--color-background-secondary);border-radius:9px;padding:2px;min-width:120px}
.vi .bq button{border:none;background:none;border-radius:7px;padding:5px 12px;font:600 11.5px var(--font-ui);color:var(--color-text-muted);cursor:pointer}.vi .bq button.on{background:var(--color-primary);color:#fff}
.vi .pnl{margin-top:14px;padding-top:12px;border-top:.5px solid var(--color-border-tertiary)}
.vi .pnl-t{margin:6px 0 4px}.vi .pnl-t td.r,.vi .pnl-t th.r{text-align:right}.vi .pnl-t tr.net td{border-top:1px solid var(--color-border-secondary);font-weight:700}
/* Partout sur la tablette (26/10/2026, demande de Sam) : des rectangles aux coins arrondis, répartis sur toute la largeur.
   Les étapes du temps en cours : l'assistant horizontal de la maquette A. */
.vi .tb-sous{display:flex;align-items:center;margin:0 0 16px}
.vi .tb-sous button{position:relative;flex:1 1 0;min-width:0;display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:12px;border:1.5px solid var(--color-border-secondary);background:var(--color-surface);cursor:pointer;text-align:left;font-family:var(--font-ui)}
.vi .tb-sous .n{flex:none;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font:700 13px var(--font-ui);background:var(--color-background-secondary);color:var(--color-text-muted)}
.vi .tb-sous .t{min-width:0;display:flex;flex-direction:column}
.vi .tb-sous b{font:700 13.5px/1.2 var(--font-ui);color:var(--color-text);overflow-wrap:break-word}
.vi .tb-sous small{font:600 11px var(--font-ui);color:var(--color-text-muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.vi .tb-sous button.on{background:var(--color-primary);border-color:var(--color-primary)}.vi .tb-sous button.on b,.vi .tb-sous button.on small{color:#fff}.vi .tb-sous button.on .n{background:rgba(255,255,255,.22);color:#fff}
.vi .tb-sous button.fait{border-color:#BFDCC6;background:#F0F7F2}.vi .tb-sous button.fait .n{background:#2d7a3e;color:#fff}.vi .tb-sous button.fait small{color:#2d7a3e}
.vi .tb-sous button em{position:absolute;top:-7px;right:-5px;font:700 10.5px/18px var(--font-ui);font-style:normal;min-width:18px;height:18px;padding:0 5px;border-radius:5px;background:#C0182B;color:#fff;text-align:center}
.vi .tb-sous i.l{flex:0 0 14px;height:2px;background:var(--color-border-secondary)}.vi .tb-sous i.l.fait{background:#2d7a3e}
.vi .tb-sous.serre{align-items:stretch}.vi .tb-sous.serre i.l{align-self:center}.vi .tb-sous.serre button{flex-direction:column;justify-content:flex-start;align-items:flex-start;padding:8px 9px;gap:5px}.vi .tb-sous.serre .n{width:24px;height:24px;font-size:11.5px}.vi .tb-sous.serre b{font-size:12px;overflow-wrap:normal}.vi .tb-sous.serre i.l{flex-basis:8px}
@media (max-width:760px){.vi .tb-sous{flex-wrap:wrap;gap:6px}.vi .tb-sous i.l{display:none}.vi .tb-sous button{flex:1 1 140px}}
.vi .onglets{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(0,1fr)}.vi .onglets button{border-radius:10px;text-align:center}
.vi .tm-n{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(0,1fr);flex:1 1 100%;width:100%;margin:8px 0 0;border-radius:10px;padding:3px}.vi .tm-n button{border-radius:8px;padding:6px 10px}
.vi .act .chip{flex:1 1 auto;text-align:center}
.vi .chip{border-radius:8px}

.vi .cx-j{position:relative;height:9px;border-radius:5px;background:var(--color-background-secondary);margin-top:6px}
.vi .cx-j i{position:absolute;left:0;top:0;bottom:0;border-radius:5px;background:#2d7a3e}.vi .cx-j i.dn{background:#C0182B}.vi .cx-j i.or{background:#E0A526}
.vi .cx-j em{position:absolute;top:-4px;bottom:-4px;width:2px;margin-left:-1px;background:var(--color-text)}
.vi .cx-l{display:grid;grid-template-columns:150px minmax(0,1fr);gap:10px;align-items:center;margin-top:6px}
.vi .cx-b{display:flex;height:16px;border-radius:5px;overflow:hidden;background:var(--color-background-secondary)}.vi .cx-b i{display:block;height:100%}
.vi .c-comptoir{background:#8D1D2C}.vi .c-b2b{background:#4B74B5}.vi .c-cc{background:#E0A526}.vi .c-liv{background:#2d7a3e}
.vi .cx-lg{display:flex;gap:14px;flex-wrap:wrap;margin-top:8px;font-size:11.5px;color:var(--color-text-muted)}.vi .cx-lg span{display:inline-flex;align-items:center;gap:5px}.vi .cx-lg i{width:10px;height:10px;border-radius:3px;display:inline-block}
.vi .tb-dem{display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap}
.vi .vb{padding:0;overflow:hidden}
.vi .vb-tabs{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:2px;background:var(--color-background-secondary);padding:3px;border-bottom:.5px solid var(--color-border-tertiary)}
.vi .vb-tabs button{border:none;background:none;border-radius:8px;padding:7px 4px 6px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:1px;font-family:var(--font-ui);color:var(--color-text-muted)}
.vi .vb-tabs button span{font:600 10.5px var(--font-ui);letter-spacing:.05em;text-transform:uppercase}
.vi .vb-tabs button b{font:700 14px var(--font-ui)}
.vi .vb-tabs button.on{background:var(--color-surface);color:var(--color-text);box-shadow:0 1px 2px rgba(0,0,0,.08)}
.vi .vb-c{padding:12px 14px 12px}
.vi .vb-big{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:10px 0 8px}
.vi .vb-big .k{font-size:21px}
.vi .vb-j{position:relative;height:10px;border-radius:5px;background:var(--color-background-secondary);margin-top:4px}
.vi .vb-j i{position:absolute;left:0;top:0;bottom:0;border-radius:5px;background:#2d7a3e}.vi .vb-j i.dn{background:#C0182B}.vi .vb-j i.or{background:#E0A526}.vi .vb-j i.mu{background:#a99e92}
.vi .vb-j em{position:absolute;top:-4px;bottom:-4px;width:2px;margin-left:-1px;background:var(--color-text)}
.vi .vb-jl{display:flex;justify-content:space-between;gap:8px;margin-top:4px}
.vi .vb-f .vb-l{font:600 12.5px var(--font-ui);margin:12px 0 5px}
.vi .vb-p{display:flex;align-items:center;gap:8px;margin-bottom:6px}
.vi .vb-p .n{flex:none;width:24px;height:24px;border-radius:50%;background:var(--color-primary);color:#fff;font:700 12px var(--font-ui);display:flex;align-items:center;justify-content:center}
.vi .vb-h{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.vi .vb-h button{border:.5px solid var(--color-border-secondary);background:var(--color-surface);border-radius:10px;padding:10px 12px;text-align:left;cursor:pointer;display:flex;flex-direction:column;gap:2px;font-family:var(--font-ui);color:var(--color-text)}
.vi .vb-h button b{font-size:13.5px}.vi .vb-h button span{font-size:11.5px;color:var(--color-text-muted)}
.vi .vb-h button.on{border-color:var(--color-primary);box-shadow:inset 0 0 0 1.5px var(--color-primary);background:#FBF4F0}
.vi .tm-t{width:100%;border-collapse:collapse;font-size:12.5px}
.vi .tm-t th{text-align:left;font:600 10px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted);padding:6px 8px;border-bottom:.5px solid var(--color-border-tertiary)}
.vi .tm-t td{padding:8px;border-bottom:.5px solid var(--color-border-tertiary);vertical-align:middle}
.vi .tm-t tr[data-a]{cursor:pointer}.vi .tm-t tr.on td{background:#FBF4F0}.vi .tm-t tr.on td:first-child{box-shadow:inset 3px 0 0 var(--color-primary);font-weight:600}
.vi .tm-t .bar{width:90px;display:inline-block;vertical-align:middle;margin:0 0 0 8px}
.vi .tm-n{display:inline-flex;background:var(--color-background-secondary);border-radius:8px;padding:2px;gap:2px;margin-left:auto}
.vi .tm-n button{border:none;background:none;border-radius:6px;padding:4px 10px;font:600 11px var(--font-ui);color:var(--color-text-muted);cursor:pointer}.vi .tm-n button.on{background:var(--color-surface);color:var(--color-text);box-shadow:0 1px 2px rgba(0,0,0,.08)}
.vi .tm-nc{padding:9px 0;border-bottom:.5px solid var(--color-border-tertiary);display:grid;grid-template-columns:150px minmax(0,1fr);gap:12px;align-items:start}.vi .tm-nc:last-child{border:none}
.vi .tm-ph{position:relative;display:block;width:150px;min-height:60px;border:none;padding:0;border-radius:8px;overflow:hidden;background:#d8cfc3;cursor:pointer}
.vi .tm-ph img{display:block;width:100%;height:auto}
.vi .tm-ph .vide{display:flex;align-items:center;justify-content:center;height:100px;font-size:10.5px;color:#6b5f52;padding:0 8px;text-align:center}
.vi .tm-rp{position:absolute;border:2px solid #D97706;border-radius:4px;box-shadow:0 0 0 1px rgba(0,0,0,.25)}
.vi .tm-rp b{position:absolute;left:-2px;top:-2px;transform:translate(-40%,-40%);min-width:16px;height:16px;border-radius:999px;background:inherit;color:#fff;font:700 9.5px/16px var(--font-ui);text-align:center;padding:0 3px}
.vi .tm-ph .tm-note{position:absolute;right:5px;bottom:5px}
.vi .tm-sm{position:relative;display:block}
.vi .tm-big{position:relative;display:inline-block;max-width:100%}.vi .cq-l .ph .tm-big img{display:block;max-width:100%;max-height:62dvh;border-radius:10px;object-fit:initial}
.vi .tm-note{display:inline-block;min-width:34px;text-align:center;font:700 11px var(--font-ui);border-radius:6px;padding:2px 6px;color:#fff;background:#D97706}.vi .tm-note.n1,.vi .tm-note.n2{background:#C0182B}.vi .tm-note.ref{background:#8C857E}
.vi .cm .db-card{margin-bottom:0}
.vi .kc .lg{padding:6px 0;border-bottom:.5px solid var(--color-border-tertiary)}.vi .kc .lg:last-child{border:none}
.vi .kb{height:5px;border-radius:4px;background:var(--color-background-secondary);overflow:hidden;margin-top:3px}
.vi .kb i{display:block;height:100%;background:var(--color-primary)}.vi .kb.r i{background:#b9ad9e}
.vi .ec{font:600 11px var(--font-ui);padding:1px 6px;border-radius:6px;background:var(--color-background-secondary);color:var(--color-text-muted);white-space:nowrap}
.vi .ec.up{background:#E3F1E6;color:#2d7a3e}.vi .ec.dn{background:#F7E4E6;color:#C0182B}
.vi .ksc{overflow-x:auto}.vi .ksc .tbl td,.vi .ksc .tbl th{padding:6px;white-space:nowrap}
.vi .al{display:flex;gap:8px;align-items:flex-start;padding:7px 0;border-bottom:.5px solid var(--color-border-tertiary);font-size:12.5px}
.vi .al:last-child{border:none}
.vi .ph{height:90px;border-radius:10px;background:#d8cfc3 center/cover no-repeat;display:flex;align-items:center;justify-content:center;color:#6b5f52;font-size:12px;position:relative;cursor:pointer;overflow:hidden}
.vi .ph.vide{background:var(--color-background-secondary);border:1.5px dashed var(--color-border-secondary);color:var(--color-primary);font-weight:600}
.vi .ph small{position:absolute;left:6px;bottom:5px;font-size:10px;background:rgba(0,0,0,.5);color:#fff;padding:2px 6px;border-radius:6px}
.vi .ph .att{position:absolute;right:6px;top:5px;font-size:10px;background:#E0A526;color:#3a2a00;padding:2px 6px;border-radius:6px}
.vi .phs{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
.vi .phs.n2{grid-template-columns:1fr 2fr}
.vi .mod{margin-top:14px}
.vi .mod .th{display:flex;align-items:center;gap:8px;margin-bottom:6px}
.vi .mod .th b{font-size:14px}
.vi .pt{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:9px 11px;margin-bottom:6px}
.vi .pt.ko{border-color:#e8b4bb}
.vi .pt .l1{display:flex;align-items:center;gap:8px}
.vi .cb{width:22px;height:22px;border:1.5px solid var(--color-border-secondary);border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;flex:0 0 auto;background:var(--color-surface);cursor:pointer;color:var(--color-text-muted)}
.vi .cb.ok{background:#2d7a3e;border-color:#2d7a3e;color:#fff}.vi .cb.ko{background:#C0182B;border-color:#C0182B;color:#fff}.vi .cb.na{background:var(--color-background-secondary);color:var(--color-text-muted)}
.vi .notes{display:flex;gap:3px}
.vi .notes button{width:24px;height:24px;border-radius:6px;background:var(--color-background-secondary);border:none;display:inline-flex;align-items:center;justify-content:center;font:600 11px var(--font-ui);color:var(--color-text-muted);cursor:pointer;padding:0}
.vi .notes button.on{background:var(--color-text);color:#fff}.vi .notes button.bad{background:#C0182B;color:#fff}
.vi .act{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;align-items:center}
.vi .chip{font:600 11px var(--font-ui);padding:5px 9px;border-radius:8px;border:.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);cursor:pointer}
.vi .chip.on{background:var(--color-text);color:#fff;border-color:var(--color-text)}
.vi .chip.ko{border-color:#C0182B;color:#C0182B}
.vi .bar{height:6px;border-radius:8px;background:var(--color-background-secondary);overflow:hidden;margin-top:4px}
.vi .bar i{display:block;height:100%;background:var(--color-primary)}
.vi .spark{display:flex;align-items:flex-end;gap:4px;height:44px;margin-top:6px}
.vi .spark i{flex:1;background:#d8cfc3;border-radius:3px 3px 0 0;min-height:2px}
.vi .spark i.o{background:var(--color-primary)}.vi .spark i.f{background:#e9e2d8}
.vi .tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.vi .tbl th{text-align:left;font:600 10px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted);padding:8px 8px;border-bottom:.5px solid var(--color-border-tertiary)}
.vi .tbl td{padding:9px 8px;border-bottom:.5px solid var(--color-border-tertiary);vertical-align:top}
.vi .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.vi.mob .grid2{grid-template-columns:1fr}
.vi .plan{border-left:3px solid var(--color-border-secondary);padding-left:10px;margin:8px 0}
.vi .plan.P0b{border-color:#C0182B}.vi .plan.P1b{border-color:#E0A526}.vi .plan.P2b{border-color:#9a8c6a}
.vi .sigs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.vi .sigs .cap{display:flex;align-items:center;gap:6px}
@media (max-width:700px){.vi .sigs{grid-template-columns:1fr}}
.vi .sig{width:100%;height:170px;background:#fff;border:1.5px dashed var(--color-border-secondary);border-radius:12px;touch-action:none;display:block;cursor:crosshair}
.vi .pill.bur{background:#F7E4E6;color:#8D1D2C}.vi .pill.int{background:#EEE6FA;color:#5b3d9e}
.vi .ckb{display:flex;gap:4px;flex-wrap:wrap}.vi .ckb button{border:.5px solid var(--color-border-secondary);background:var(--color-surface);border-radius:8px;padding:6px 10px;font:600 11.5px var(--font-ui);cursor:pointer;color:var(--color-text)}
.vi .ckb button.fait{background:#2d7a3e;color:#fff;border-color:#2d7a3e}.vi .ckb button.pas_fait{background:#C0182B;color:#fff;border-color:#C0182B}.vi .ckb button.na{background:var(--color-text-muted);color:#fff}
.vi .stat{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.vi .stat>div{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:10px 12px;text-align:center}
.vi input[type=text],.vi input[type=date],.vi input[type=time],.vi input[type=number],.vi input[type=email],.vi select,.vi textarea{font-family:var(--font-ui);font-size:13px;padding:8px 10px;border-radius:9px;border:.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);width:100%}
.vi textarea{min-height:64px;resize:vertical}
.vi .champ{margin-top:8px}.vi .champ label{display:block;font-size:11px;color:var(--color-text-muted);margin-bottom:3px}
.vi .l2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.vi .toast{position:fixed;left:14px;right:14px;bottom:86px;background:var(--color-text);color:#fff;border-radius:12px;padding:10px 12px;font-size:12px;z-index:6}
.vi.desk .toast{position:absolute;bottom:auto;top:8px}
.vi .etat{display:flex;align-items:center;gap:8px;font-size:11px;color:var(--color-text-muted);margin:4px 2px 8px;flex-wrap:wrap}
.vi .etat .pt-{width:8px;height:8px;border-radius:50%;background:#2d7a3e;display:inline-block}
.vi .etat .off .pt-{background:#C0182B}
.vi .voir{position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:20;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:14px}
.vi .voir img{max-width:100%;max-height:80vh;border-radius:10px}
.vi .voir .txt{color:#fff;font-size:12px;margin-top:10px;text-align:center}
.vi .hd{display:flex;align-items:center;gap:8px;margin:6px 0 8px}
.vi .hd h2{font-family:var(--font-display);font-size:19px;line-height:1.15;margin:0;font-weight:400}
.vi .hd .d{font-size:11px;color:var(--color-text-muted)}
.vi .retour{border:none;background:none;font-size:20px;cursor:pointer;padding:0 6px 0 0;color:var(--color-text)}
.vi .rub{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}
.vi .et{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:14px;margin-bottom:8px;overflow:hidden}
.vi .et.on{border-color:var(--color-primary)}
.vi .et-h{display:flex;align-items:center;gap:10px;padding:11px 14px;cursor:pointer}
.vi .et-h .num{width:26px;height:26px;border-radius:50%;background:var(--color-background-secondary);display:inline-flex;align-items:center;justify-content:center;font:700 12px var(--font-ui);color:var(--color-text-muted);flex:0 0 auto}
.vi .et.on .et-h .num{background:var(--color-primary);color:#fff}
.vi .et.ok .et-h .num{background:#2d7a3e;color:#fff}
.vi .et-c{padding:0 14px 14px;border-top:.5px solid var(--color-border-tertiary)}
.vi .et-c .mod{margin-top:8px}
.vi .et-c .card{background:var(--color-bg)}
/* Objectif de campagne : trois chiffres, la trajectoire, le CA. Toi en couleur,
   le N-1 en gris, l'objectif en trait. */
.vi .pc-lien{display:inline-flex;align-items:center;gap:6px;font:600 10.5px var(--font-ui);color:var(--color-primary);background:#fdf4f5;border-radius:8px;padding:3px 9px;margin-top:8px}
.vi .pc-lien.mu{color:var(--color-text-muted);background:var(--color-background-secondary)}
.vi .pc-g{display:block;width:100%;height:auto;margin-top:8px}
.vi .pc-g text{font-family:var(--font-ui);font-size:9px;fill:var(--color-text-muted);paint-order:stroke;stroke:var(--color-surface);stroke-width:3px;stroke-linejoin:round}
.vi .pc-g text.p{font-weight:700;fill:var(--color-primary)}.vi .pc-g text.o{font-weight:700;fill:var(--color-text)}.vi .pc-g text.n{fill:#7d756a}
.vi .pc-g .reel{fill:var(--color-primary)}.vi .pc-g .n1{fill:#c9c2b8}
.vi .pc-g .n1l{fill:none;stroke:#9a9184;stroke-width:1.6}.vi .pc-g .reell{fill:none;stroke:var(--color-primary);stroke-width:2.2;stroke-linejoin:round}
.vi .pc-g .objl{fill:none;stroke:var(--color-text);stroke-width:1.2;stroke-dasharray:3 3}.vi .pc-g .axe{stroke:var(--color-border-tertiary);stroke-width:.5}
.vi .pc-leg{display:flex;gap:12px;flex-wrap:wrap;font:500 10px var(--font-ui);color:var(--color-text-muted);margin-top:4px}
.vi .pc-leg span{display:inline-flex;align-items:center;gap:5px}.vi .pc-leg i{width:10px;height:10px;border-radius:2px;display:inline-block}
.vi .pc-leg i.r{background:var(--color-primary)}.vi .pc-leg i.n{background:#c9c2b8}.vi .pc-leg i.o{width:14px;height:0;border-top:2px dashed var(--color-text);border-radius:0}
.vi .pc-camp .t{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.vi .pc-camp .t b{font-size:13.5px}
.vi .pc-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:10px}.vi .pc-3>div{min-width:0}
.vi .pc-3 .k{font:600 8.5px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted)}
.vi .pc-3 .v{font-family:var(--font-display);font-size:24px;line-height:1.05;margin-top:2px;font-variant-numeric:tabular-nums}
.vi .pc-3 .v.p{color:var(--color-primary)}.vi .pc-3 .v.n{color:#7d756a}.vi .pc-3 .v.w{color:#B26A00;font-size:18px;padding-top:4px}
.vi .pc-3 .s{font-size:9.5px;color:var(--color-text-muted);line-height:1.3}
.vi .pc-ca{display:flex;justify-content:space-between;gap:8px;font-size:11px;color:var(--color-text-muted);margin-top:10px;padding-top:8px;border-top:.5px dashed var(--color-border-tertiary)}
.vi .pc-ca b{color:var(--color-text)}.vi .pc-ca.s{border-top:none;padding-top:4px}.vi .pc-ca .w{color:#B26A00;font-weight:600;white-space:nowrap}
/* Les contrôles en photo, dans le contrôle guidé : les cartes viennent de
   controles-photo.css (partagé avec le dashboard et le cockpit). Ici la bande
   déborde jusqu'aux bords de la carte, la note rapide vit sous la photo, la
   loupe prend tout l'écran. */
.vi .cq-res{font-size:11px;color:var(--color-text-muted);line-height:1.35;margin:10px 0 8px}
.vi .cq-res em{font-style:normal;color:#2F5D8A;font-weight:600}
.vi .cq-fs{flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none;margin:0 -14px;padding:0 14px 2px}
.vi .cq-fs::-webkit-scrollbar{display:none}.vi .cq-fs button{flex:0 0 auto}
.vi .cq-piste{gap:10px;margin:10px -14px 0;padding:0 14px;scroll-padding:0 14px}
.vi .cq-piste .db-cqc{flex:0 0 158px;min-width:0}
.vi .db-cqc .n{white-space:normal;line-height:1.2;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:11.5px;margin-top:6px}
.vi .db-cqc .m{font-size:10px}.vi .db-cqc .c{font-size:10px;white-space:normal;line-height:1.25}
.vi .cq-ouvrir{display:flex;flex-direction:column;width:100%;border:none;background:none;padding:0;text-align:left;font:inherit;color:inherit;cursor:pointer}
.vi .cq-acts{display:flex;align-items:center;gap:6px;margin-top:auto;padding-top:7px}
.vi .cq-rapide{min-width:34px;height:30px;border-radius:8px;border:1.5px solid var(--c,#2d7a3e);background:transparent;color:var(--c,#2d7a3e);font:700 13px var(--font-ui);padding:0 10px;cursor:pointer}
.vi .cq-rapide.lg{flex:1;height:40px;font-size:13px}
.vi .cq-pied{font-size:10.5px;color:var(--color-text-muted);margin:8px 0 2px}
.vi .cq-l{position:fixed;inset:0;background:#0d0b09;z-index:45;display:flex;flex-direction:column;color:#fff}
.vi .cq-l .hd{display:flex;align-items:center;gap:10px;padding:12px 14px;font:600 13px var(--font-ui)}
.vi .cq-l .hd .x{width:36px;height:36px;border-radius:50%;border:none;background:rgba(255,255,255,.14);color:#fff;font-size:16px}
.vi .cq-l .ph{flex:1;min-height:0;display:flex;align-items:center;justify-content:center;padding:0 12px;height:auto;background:transparent;cursor:default}
.vi .cq-l .ph img{max-width:100%;max-height:100%;border-radius:10px;object-fit:contain}
.vi .cq-l .fi{flex:0 0 auto;max-height:52dvh;overflow-y:auto;margin:10px 12px calc(12px + env(safe-area-inset-bottom,0px));background:var(--color-surface);color:var(--color-text);border-radius:14px;padding:12px 14px}
.vi .cq-l .fi .t{display:block;font-family:var(--font-display);font-size:18px;font-weight:400;margin:6px 0 2px}
.vi .cq-l .fi .q{font-size:11.5px;color:var(--color-text-muted);line-height:1.4}
.vi .cq-l .fi .cq-acts{margin-top:10px;padding-top:0}.vi .cq-l .fi .act{margin-top:10px}.vi .cq-l .fi .act .btn{flex:1}
.vi .meteo{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px}
.vi .meteo button{display:flex;flex-direction:column;align-items:center;gap:3px;padding:10px 4px;border-radius:12px;border:.5px solid var(--color-border-secondary);background:var(--color-surface);cursor:pointer;font:600 12px var(--font-ui);color:var(--color-text);text-align:center;line-height:1.2}
.vi .meteo button .i{font-size:28px;line-height:1.1}
.vi .meteo button small{font:500 10.5px var(--font-ui);color:var(--color-text-muted)}
.vi .meteo button.on{border:1.5px solid var(--color-primary);background:#F7E4E6;color:var(--color-primary)}
.vi.mob .meteo button small{display:none}
.vi.tab .meteo button{min-height:104px;padding:14px 6px}
.vi.tab .meteo button .i{font-size:36px}
/* ── Tablette (12/10/2026) : la liste des boutiques ou l'agenda, puis le contrôle étape par étape,
   les étapes en barre de menu en bas. Écran plein, cibles tactiles de 44 px au moins. */
.vi.tab{min-height:100vh;font-size:15px}
.vi.tab .sc{padding:0;max-width:none}
.vi.tab .tb-top{position:sticky;top:0;z-index:30;display:flex;align-items:center;gap:14px;min-height:66px;padding:8px 24px;background:var(--color-surface);border-bottom:.5px solid var(--color-border-tertiary)}
.vi.tab .tb-top img{height:24px}
.vi.tab .tb-top .sep{width:1px;height:28px;background:var(--color-border-secondary)}
.vi.tab .tb-top .t{font-family:var(--font-display);font-size:21px;line-height:1.05}
.vi.tab .tb-top .d{font-size:11.5px;color:var(--color-text-muted);margin-top:3px}
.vi.tab .tb-ret{height:44px;padding:0 14px;border-radius:12px;border:.5px solid var(--color-border-secondary);background:var(--color-surface);font:600 13px var(--font-ui);color:var(--color-text);cursor:pointer;white-space:nowrap}
.vi.tab .tb-ch{display:inline-flex;align-items:center;gap:6px;font:600 11.5px var(--font-ui);padding:7px 11px;border-radius:8px;background:var(--color-background-secondary);color:var(--color-text);white-space:nowrap;border:none}
.vi.tab .tb-ch i{width:7px;height:7px;border-radius:50%;background:#2d7a3e;display:inline-block}
.vi.tab .tb-ch.off i{background:#C0182B}
.vi.tab .tb-av{width:38px;height:38px;border-radius:50%;background:var(--color-primary);color:#fff;display:flex;align-items:center;justify-content:center;font:700 12px var(--font-ui);border:none;cursor:pointer;flex:0 0 auto}
.vi.tab .tb-pr{text-align:right;font:700 13px var(--font-ui)}
.vi.tab .tb-pr .bar{width:120px;margin-top:5px}
.vi.tab .tb-main{padding:20px 24px 28px;max-width:1240px;margin:0 auto}
.vi.tab .tb-h{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.vi.tab .tb-h h1{font-family:var(--font-display);font-size:30px;line-height:1.05;margin:6px 0 0;font-weight:400}
.vi.tab .tb-tog{display:inline-flex;padding:4px;border-radius:14px;background:var(--color-background-secondary);gap:4px}
.vi.tab .tb-tog button{height:42px;padding:0 18px;border-radius:10px;border:none;background:none;font:600 14px var(--font-ui);color:var(--color-text-muted);cursor:pointer}
.vi.tab .tb-tog button.on{background:var(--color-surface);color:var(--color-text);box-shadow:0 1px 4px rgba(34,34,34,.12)}
.vi.tab .tb-shops{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px}
.vi.tab .tb-shop{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:18px;padding:18px 18px 16px;display:flex;flex-direction:column;gap:12px}
.vi.tab .tb-shop.cours{border-color:var(--color-primary);box-shadow:0 0 0 1.5px var(--color-primary) inset}
.vi.tab .tb-shop .n{display:flex;align-items:center;gap:10px}
.vi.tab .tb-shop .n b{font-family:var(--font-display);font-size:24px;font-weight:400;line-height:1}
.vi.tab .tb-quand{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:14px;background:var(--color-background-secondary)}
.vi.tab .tb-quand .cal{width:52px;flex:0 0 auto;text-align:center;border-radius:10px;background:var(--color-surface);overflow:hidden;border:.5px solid var(--color-border-tertiary)}
.vi.tab .tb-quand .cal i{display:block;font:700 9.5px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;background:var(--color-primary);color:#fff;padding:3px 0;font-style:normal}
.vi.tab .tb-quand .cal b{display:block;font:700 20px var(--font-ui);padding:3px 0 4px}
.vi.tab .tb-quand .cal.vide i{background:var(--color-text-muted)}
.vi.tab .tb-quand .l{font:700 14px var(--font-ui)}
.vi.tab .tb-quand .m{font-size:12px;color:var(--color-text-muted);margin-top:2px}
.vi.tab .tb-meta{display:flex;gap:6px;flex-wrap:wrap}
.vi.tab .tb-meta span{font:600 11.5px var(--font-ui);padding:5px 9px;border-radius:8px;background:var(--color-background-secondary)}
.vi.tab .tb-meta span.ko{background:#F7E4E6;color:#C0182B}
.vi.tab .tb-act{display:flex;gap:8px;margin-top:auto}
.vi.tab .tb-b{height:50px;padding:0 18px;border-radius:13px;border:.5px solid var(--color-border-secondary);background:var(--color-surface);font:600 14.5px var(--font-ui);color:var(--color-text);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px}
.vi.tab .tb-b.p{background:var(--color-primary);border-color:var(--color-primary);color:#fff;flex:1}
.vi.tab .tb-b:disabled{opacity:.45;cursor:default}
.vi.tab .tb-cal{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:18px;overflow:hidden}
.vi.tab .tb-cal .g{display:grid;grid-template-columns:repeat(7,1fr)}
.vi.tab .tb-cal .wd{font:600 10.5px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:var(--color-text-muted);padding:10px 10px 8px;border-bottom:.5px solid var(--color-border-tertiary)}
.vi.tab .tb-cal .j{min-height:104px;padding:8px;border-right:.5px solid var(--color-border-tertiary);border-bottom:.5px solid var(--color-border-tertiary);display:flex;flex-direction:column;gap:4px}
.vi.tab .tb-cal .j:nth-child(7n){border-right:none}
.vi.tab .tb-cal .j .d{font:700 13px var(--font-ui);color:var(--color-text);margin-bottom:2px}
.vi.tab .tb-cal .j.hors{background:var(--color-bg);opacity:.55}
.vi.tab .tb-cal .j.auj .d{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:var(--color-primary);color:#fff}
.vi.tab .tb-cal .j.we{background:#fbf9f6}
.vi.tab .tb-cal .v{display:block;width:100%;text-align:left;border:none;border-radius:8px;padding:6px 7px;font:600 11.5px var(--font-ui);line-height:1.2;cursor:pointer;background:var(--color-background-secondary);color:var(--color-text)}
.vi.tab .tb-cal .v small{display:block;font:500 10.5px var(--font-ui);color:var(--color-text-muted)}
.vi.tab .tb-cal .v.en_cours{background:var(--color-primary);color:#fff}.vi.tab .tb-cal .v.en_cours small{color:rgba(255,255,255,.8)}
.vi.tab .tb-cal .v.terminee{background:#E6F2E9;color:#2d7a3e}
.vi.tab .tb-cal .v.confirmee{background:#FBEFE0;color:#7a4a00}
.vi.tab .tb-nav{display:flex;gap:10px;margin-top:18px}
.vi.tab .tb-nav{align-items:flex-end}.vi.tab .tb-nav .tb-b{flex:0 0 auto}
.vi.tab .tb-suiv{display:flex;flex-direction:column;align-items:flex-end;gap:6px}
.vi.tab .tb-nav .tb-b.p{flex:0 0 auto;min-width:260px;display:flex;flex-direction:column;align-items:flex-end;gap:1px;text-align:right}
.vi.tab .tb-nav .tb-b.p small{font:600 10.5px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;opacity:.8}
.vi.tab .tb-nav .tb-b.p.bloque{background:var(--color-background-secondary);border-color:var(--color-border-secondary);color:var(--color-text-muted);cursor:not-allowed}
.vi.tab .tb-ctl{padding-bottom:132px}
.vi.tab .tb-pills{display:flex;gap:6px;flex-wrap:wrap}
.vi.tab .tb-pills span{font:700 10.5px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;padding:5px 10px;border-radius:8px;background:var(--color-background-secondary);color:var(--color-text-muted)}
.vi.tab .tb-pills span.ok{background:#E6F2E9;color:#2d7a3e}.vi.tab .tb-pills span.ko{background:#F7E4E6;color:#C0182B}
.vi.tab .tb-bar{position:fixed;left:0;right:0;bottom:0;z-index:40;display:grid;gap:2px;background:var(--color-surface);border-top:.5px solid var(--color-border-tertiary);box-shadow:0 -6px 18px rgba(34,34,34,.06);padding:8px 8px max(14px,env(safe-area-inset-bottom))}
.vi.tab .tb-bar button{position:relative;display:flex;flex-direction:column;align-items:center;gap:5px;padding:6px 1px 2px;border:none;background:none;cursor:pointer;font:600 10.5px var(--font-ui);color:var(--color-text-muted);text-align:center;line-height:1.15;min-width:0}
.vi.tab .tb-bar .ic{position:relative;width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;background:var(--color-background-secondary)}
.vi.tab .tb-bar svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.vi.tab .tb-bar .lb{max-width:100%;overflow:hidden;text-overflow:ellipsis}
.vi.tab .tb-bar .es{font:600 9.5px var(--font-ui);color:var(--color-text-muted);white-space:nowrap;max-width:100%;overflow:hidden;text-overflow:ellipsis}
.vi.tab .tb-bar button.fait{color:var(--color-text)}
.vi.tab .tb-bar button.fait .ic{background:#E6F2E9;color:#2d7a3e}
.vi.tab .tb-bar button.on{color:var(--color-primary)}
.vi.tab .tb-bar button.on .ic{background:var(--color-primary);color:#fff;box-shadow:0 4px 12px rgba(141,29,44,.3)}
.vi.tab .tb-bar button.on::after{content:'';position:absolute;top:-8px;left:24%;right:24%;height:3px;border-radius:0 0 3px 3px;background:var(--color-primary)}
.vi.tab .tb-bar button.on .es{color:var(--color-primary)}
.vi.tab .tb-bar .ok{position:absolute;right:-5px;bottom:-5px;width:17px;height:17px;border-radius:50%;background:#2d7a3e;color:#fff;font:700 10px/17px var(--font-ui);text-align:center;border:2px solid #fff}
.vi.tab .tb-bar .ko{position:absolute;right:-6px;top:-6px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:#C0182B;color:#fff;font:700 10px/18px var(--font-ui);text-align:center;border:2px solid #fff}
.vi.tab .tb-step .mod{margin:0}
.vi.tab .tb-step .card{border-radius:16px}
.vi.tab .pt.t{padding:14px 16px;border-radius:16px;margin-bottom:10px}
.vi.tab .pt.t .l1{gap:14px}
.vi.tab .pt.t .l1 b{font-size:15px !important;line-height:1.3}
.vi.tab .pt.t.ko{background:linear-gradient(90deg,rgba(192,24,43,.05),var(--color-surface) 60%)}
.vi.tab .pt.t .act{margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.vi.tab .pt.t .act .chip{min-height:38px;padding:0 12px;font-size:12.5px}
.vi.tab .pt.t .act input[type=text]{min-height:40px;font-size:13.5px !important;border-radius:10px}
.vi.tab .pt.t input[type=number]{height:44px;width:84px !important;font-size:15px}
.vi.tab .tri{display:inline-flex;border-radius:12px;overflow:hidden;border:.5px solid var(--color-border-secondary);flex:0 0 auto}
.vi.tab .tri button{min-width:68px;padding:0 12px;height:46px;border:none;border-left:.5px solid var(--color-border-secondary);background:var(--color-surface);font:700 13px var(--font-ui);color:var(--color-text-muted);cursor:pointer}
.vi.tab .tri button:first-child{border-left:none}
.vi.tab .tri button.ok{background:#2d7a3e;color:#fff}.vi.tab .tri button.ko{background:#C0182B;color:#fff}.vi.tab .tri button.na{background:var(--color-text-muted);color:#fff}
.vi .tri{display:inline-flex;border-radius:10px;overflow:hidden;border:.5px solid var(--color-border-secondary);flex:0 0 auto}
.vi .tri button{min-width:52px;height:34px;border:none;border-left:.5px solid var(--color-border-secondary);background:var(--color-surface);font:700 12px var(--font-ui);color:var(--color-text-muted);cursor:pointer}
.vi .tri button:first-child{border-left:none}.vi .tri button.ok{background:#2d7a3e;color:#fff}.vi .tri button.ko{background:#C0182B;color:#fff}.vi .tri button.na{background:var(--color-text-muted);color:#fff}
/* Les questions génériques (26/10/2026) : choix en rectangles arrondis sur toute la largeur, nombre, texte ; la bascule FR | NL. */
.vi .lng{display:inline-flex;border:.5px solid var(--color-border-secondary);border-radius:9px;overflow:hidden;flex:0 0 auto}
.vi .lng button{border:none;background:var(--color-surface);font:700 11px var(--font-ui);letter-spacing:.04em;color:var(--color-text-muted);padding:6px 10px;cursor:pointer;min-width:40px}
.vi .lng button.on{background:var(--color-primary);color:#fff}
.vi.tab .lng button{padding:9px 14px;font-size:12.5px;min-width:52px}
.vi .pt b small.aide{display:block;font-weight:500;font-size:11.5px;color:var(--color-text-muted);margin-top:2px}
.vi .rp-ch{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:8px}
.vi .rp-ch button{border:.5px solid var(--color-border-secondary);background:var(--color-surface);border-radius:11px;min-height:40px;padding:8px 12px;font:600 13px var(--font-ui);color:var(--color-text);cursor:pointer;text-align:left;display:flex;align-items:center;gap:8px}
.vi .rp-ch button i{font-style:normal;width:18px;height:18px;border-radius:5px;border:1.5px solid var(--color-border-secondary);display:inline-flex;align-items:center;justify-content:center;font-size:12px;flex:0 0 auto}
.vi .rp-ch button.on{background:#2d7a3e;border-color:#2d7a3e;color:#fff}.vi .rp-ch button.on i{border-color:#fff}
.vi .rp-ch button.on.ko{background:#C0182B;border-color:#C0182B}
.vi.tab .rp-ch button{min-height:48px;font-size:14px}
.vi .rp-nb{display:inline-flex;align-items:center;gap:6px;flex:0 0 auto}.vi .rp-nb input{width:96px !important}.vi .rp-nb span{font:600 12px var(--font-ui);color:var(--color-text-muted)}
.vi .rp-tx{width:100%;margin-top:8px;border-radius:10px;padding:8px 10px;font:500 13.5px var(--font-ui);min-height:56px;resize:vertical}
.vi .rg-q{border-top:.5px solid var(--color-border-tertiary);padding:8px 0 4px;display:flex;flex-direction:column;gap:6px}.vi .rg-q:first-of-type{border-top:none}
.vi .rg-q .row,.vi .rg-mod>.row{gap:6px;flex-wrap:wrap}.vi .rg-q .row>input[type=text]{flex:1 1 220px;min-width:0}
.vi .rg-q select{flex:0 0 auto;min-width:170px}.vi .rg-q input.rg-aide{flex:1 1 160px !important;font-size:12px}
.vi .rg-mod input.rg-nl{flex:0 1 220px;font-size:12px}
.vi .rg-ch{padding-left:12px;border-left:2px solid var(--color-border-tertiary);display:flex;flex-direction:column;gap:5px}
.vi .rg-sc{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:var(--color-background-secondary);border-radius:10px;padding:6px 10px}
.vi .rg-sc .cap{margin:0;color:var(--color-primary)}.vi .rg-sc label{font:600 11px var(--font-ui);color:var(--color-text-muted)}
.vi .rg-sc .seg{display:grid;grid-auto-flow:column;grid-auto-columns:1fr;gap:4px;flex:1 1 140px}.vi .rg-sc .seg button{border:.5px solid var(--color-border-secondary);background:var(--color-surface);border-radius:8px;height:32px;font:700 12px var(--font-ui);color:var(--color-text-muted);cursor:pointer}
.vi .rg-sc .seg button.on{background:var(--color-primary);border-color:var(--color-primary);color:#fff}.vi .rg-sc .seg.off{opacity:.45}
.vi .sc-fin{border-color:var(--color-primary)}
.vi .sc-duo{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);gap:12px;align-items:center;margin:10px 0}
.vi .sc-c{background:var(--color-background-secondary);border-radius:12px;padding:10px 14px;display:flex;flex-direction:column;align-items:flex-start;text-align:left;gap:2px}.vi .sc-n{display:flex;align-items:baseline;gap:10px}.vi .sc-n span{font-size:15px}.vi .sc-c.act{background:var(--color-surface);border:1.5px solid var(--color-primary)}
.vi .sc-c small{font:600 11px var(--font-ui);color:var(--color-text-muted)}.vi .sc-c b{font:400 34px/1.05 var(--font-display)}.vi .sc-fl{font-size:22px;color:var(--color-text-muted)}
.vi .sc-c b.ok,.vi .sc-mods b.ok{color:#2d7a3e}.vi .sc-c b.att,.vi .sc-mods b.att{color:#B45309}.vi .sc-c b.ko,.vi .sc-mods b.ko{color:#C0182B}
.vi .sc-mods{display:grid;grid-template-columns:minmax(0,1fr) 84px 72px 64px;gap:4px 10px;font-size:13px;align-items:center}.vi .sc-mods .h{font:600 10.5px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;color:var(--color-text-muted)}
.vi .sc-mods .n{text-align:right}.vi .sc-mods .up,.vi .sc-c .up{color:#2d7a3e;font-weight:700}.vi .sc-mods .dn,.vi .sc-c .dn{color:#C0182B;font-weight:700}
.vi .rg-nb{align-items:center}.vi .rg-nb label{font:600 11px var(--font-ui);color:var(--color-text-muted)}.vi .rg-nb input{width:90px !important;flex:0 0 auto !important}.vi .rg-nb input[type=text]{width:120px !important}
.vi.tab .tb-step > .cap:first-child{display:none}
.vi.tab .tb-step .phs{gap:12px}
.vi.tab .tb-step .phs .ph{height:210px;border-radius:14px;font-size:15px}
.vi.tab .tb-vide{padding:30px;text-align:center;color:var(--color-text-muted);font-size:14px}
@media (orientation:portrait){.vi.tab .tb-shops{grid-template-columns:1fr 1fr}.vi.tab .tb-cal .j{min-height:92px}}
@media (max-width:700px){.vi.tab .tb-shops{grid-template-columns:1fr}.vi.tab .tb-bar .lb,.vi.tab .tb-bar .es{display:none}}
`;

  /* --- IndexedDB : une réserve de lectures, une file d'écritures --------- */
  const Idb = {
    db: null,
    ouvrir() {
      if (this.db) { return Promise.resolve(this.db); }
      return new Promise((ok, ko) => {
        if (!window.indexedDB) { return ko(new Error('pas d’IndexedDB')); }
        const r = indexedDB.open('cockpit-visites', 1);
        r.onupgradeneeded = () => { const d = r.result; d.createObjectStore('cache'); d.createObjectStore('file', { keyPath: 'id' }); };
        r.onsuccess = () => { this.db = r.result; ok(this.db); };
        r.onerror = () => ko(r.error);
      });
    },
    async op(store, mode, fn) {
      try {
        const db = await this.ouvrir();
        return await new Promise((ok, ko) => {
          const t = db.transaction(store, mode); const s = t.objectStore(store); const q = fn(s);
          q.onsuccess = () => ok(q.result); q.onerror = () => ko(q.error);
        });
      } catch (e) { return null; }
    },
    get(store, cle) { return this.op(store, 'readonly', s => s.get(cle)); },
    put(store, val, cle) { return this.op(store, 'readwrite', s => cle !== undefined ? s.put(val, cle) : s.put(val)); },
    del(store, cle) { return this.op(store, 'readwrite', s => s.delete(cle)); },
    tous(store) { return this.op(store, 'readonly', s => s.getAll()).then(r => r || []); },
    vider(store) { return this.op(store, 'readwrite', s => s.clear()); },
  };

  const uuid = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'x' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  const auj = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const plusJours = (iso, n) => { const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };
  const maintenant = () => { const d = new Date(); return auj() + ' ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
  const fmtD = iso => { if (!iso) { return '—'; } const d = new Date(String(iso).slice(0, 10) + 'T12:00:00'); return d.getDate() + ' ' + MOIS[d.getMonth()]; };
  const fmtDJ = iso => { if (!iso) { return '—'; } const d = new Date(String(iso).slice(0, 10) + 'T12:00:00'); return JOURS[d.getDay()] + ' ' + d.getDate() + ' ' + MOIS[d.getMonth()]; };
  const fmtH = dt => dt ? String(dt).slice(11, 16) : '';
  const eur = n => n == null ? '—' : Math.round(n).toLocaleString('fr-BE').replace(/ | /g, ' ') + ' €';
  // Un écart en % au dixième, signé : « −14,2 % », « +3 % ».
  const vbPct = e => e == null ? '—' : (e > 0 ? '+' : e < 0 ? '−' : '') + String(Math.abs(Math.round(e * 10) / 10)).replace('.', ',') + ' %';
  const cap1 = t => t ? t.charAt(0).toUpperCase() + t.slice(1) : '';
  const pct = n => n == null ? '' : (n > 0 ? '+' : n < 0 ? '−' : '') + Math.abs(n) + ' %';
  const note1 = n => n == null ? '—' : String(Number(n).toFixed(1)).replace('.', ',');
  const nf = n => n == null ? '—' : eur(n).replace(/\s*€$/, '');
  const sg = n => (n >= 0 ? '+' : '−') + nf(Math.abs(Math.round(n)));
  /* --- Les clients d'une campagne : la trajectoire cumulée ---
   * Trois séries : cette année (couleur), N-1 aux mêmes jours de semaine
   * (gris), l'objectif (trait). Étiquettes écrites, pas devinées. */
  const PC_W = 330;
  const viGCumul = (serie, prevus, labs) => {
    const H = 150, top = 14, bas = 18, gL = 4, gR = 68, W = PC_W, jours = Math.max(2, serie.length);
    const cumR = [], cumN = []; let r = 0, n = 0, dernier = -1;
    serie.forEach((j, i) => { if (j.tickets != null) { r += j.tickets; dernier = i; } n += j.n1 || 0; cumR.push(r); cumN.push(n); });
    const nFin = cumN[cumN.length - 1] || 0;
    const max = Math.max(1, nFin, prevus || 0, dernier >= 0 ? cumR[dernier] : 0) * 1.05;
    const x = i => gL + (W - gL - gR) * i / (jours - 1), y = v => top + (H - top - bas) * (1 - v / max);
    let h = `<svg class="pc-g" viewBox="0 0 ${W} ${H}" role="img" aria-label="Clients cumulés depuis le début de la campagne">`;
    h += `<line class="axe" x1="${gL}" x2="${W - gR}" y1="${H - bas}" y2="${H - bas}"/>`;
    for (let i = 7; i < jours; i += 7) { h += `<line class="axe" x1="${x(i).toFixed(1)}" x2="${x(i).toFixed(1)}" y1="${H - bas}" y2="${H - bas + 3}"/>`; }
    if (prevus) { h += `<line class="objl" x1="${x(0)}" y1="${y(0).toFixed(1)}" x2="${x(jours - 1).toFixed(1)}" y2="${y(prevus).toFixed(1)}"/>`; }
    if (nFin) { h += `<path class="n1l" d="${cumN.map((v, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1)).join(' ')}"/>`; }
    if (dernier >= 0) { h += `<path class="reell" d="${cumR.slice(0, dernier + 1).map((v, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1)).join(' ')}"/><circle cx="${x(dernier).toFixed(1)}" cy="${y(cumR[dernier]).toFixed(1)}" r="3.5" class="reel"/>`; }
    // Les étiquettes de fin, écartées d'au moins 10 px, dans l'ordre des valeurs.
    const L = [];
    if (prevus) { L.push({ y: y(prevus), c: 'o', t: nf(prevus) + ' visés', x: x(jours - 1) + 7 }); }
    if (nFin) { L.push({ y: y(nFin), c: 'n', t: nf(nFin) + ' N-1', x: x(jours - 1) + 7 }); }
    if (dernier >= 0) { L.push({ y: y(cumR[dernier]), c: 'p', t: nf(cumR[dernier]) + (dernier === jours - 1 ? '' : ' au j' + (dernier + 1)), x: x(dernier) + 7 }); }
    L.sort((a, b) => a.y - b.y);
    for (let i = 1; i < L.length; i++) { if (L[i].y - L[i - 1].y < 10) { L[i].y = L[i - 1].y + 10; } }
    for (let i = L.length - 1; i >= 0; i--) { if (L[i].y > H - bas - 2) { L[i].y = H - bas - 2; } if (i < L.length - 1 && L[i + 1].y - L[i].y < 10) { L[i].y = L[i + 1].y - 10; } }
    L.forEach(l => { h += `<text class="${l.c}" x="${l.x.toFixed(1)}" y="${(l.y + 3).toFixed(1)}">${l.t}</text>`; });
    h += `<text x="${gL}" y="${H - 4}">${esc(labs[0])}</text><text x="${(W - gR).toFixed(1)}" y="${H - 4}" text-anchor="end">${esc(labs[1])}</text>`;
    return h + '</svg>';
  };
  // Les contrôles en photo : le nom court d'une tâche, sa checklist sans son code.
  const cqNom = t => String(t.tache || ('Tâche #' + t.taskId)).replace(/^Photo du comptoir\s*-\s*/i, 'Comptoir · ').replace(/^Contrôle Qualité\s*[–-]\s*/i, 'CQ · ');
  const cqCl = t => String(t.checklist || '').replace(/\.$/, '').replace(/^[A-Z]{2}-?[A-Z0-9]+\s*[—–-]\s*/i, '');
  const hm = v => v ? String(v).slice(11, 16) : '';
  const pcLeg = avecObj => '<div class="pc-leg"><span><i class="r"></i>cette année</span><span><i class="n"></i>N-1, mêmes jours</span>' + (avecObj ? '<span><i class="o"></i>objectif</span>' : '') + '</div>';
  // Les leviers que le consultant vérifie sur place (26/10/2026, demande de Sam) : un module de checklist comme les
  // autres (état, note, photo, remarque), ajouté à toute visite qui n'est pas un suivi ou une revisite ; au-dessus de
  // ses points, la carte des six leviers du dashboard magasin et les derniers avis Google.
  // Les leviers (26/10/2026) en deux modules depuis la visite en six temps : l'expérience client se vit à
  // l'intérieur, le trafic et Google se vérifient assis avec le franchisé, à l'analyse. Mêmes références de points.
  const MOD_XP = { id: 'xp', nom: 'Expérience client', nom_nl: 'Klantbeleving', points: [
    { ref: 'lev_xp', libelle: 'Expérience client — elle est présente : on la vit en entrant', libelle_nl: 'Klantbeleving — ze is aanwezig: je voelt het bij het binnenkomen', photo: true, pct: false },
    { ref: 'lev_cuisson', libelle: 'Expérience client — la cuisson : produits chauds, bien cuits, au bon moment', libelle_nl: 'Klantbeleving — het bakken: warme producten, goed gebakken, op het juiste moment', photo: true, pct: false },
    { ref: 'lev_accueil', libelle: 'Expérience client — l’accueil : bonjour, sourire, conseil', libelle_nl: 'Klantbeleving — het onthaal: goeiedag, glimlach, advies', photo: false, pct: false }] };
  const MOD_LEVIERS = { id: 'leviers', nom: 'Les leviers', nom_nl: 'De hefbomen', leviers: true, points: [
    { ref: 'lev_trafic', libelle: 'Trafic — les KPI de trafic vérifiés avec le franchisé (clients par jour face au N-1 et au réseau)', libelle_nl: 'Traffic — de traffic-KPI’s nagekeken met de franchisenemer (klanten per dag t.o.v. vorig jaar en het netwerk)', photo: false, pct: false },
    { ref: 'lev_google', libelle: 'Note Google — la note et les derniers avis vérifiés avec le franchisé', libelle_nl: 'Google-score — de score en de laatste reviews nagekeken met de franchisenemer', photo: false, pct: false }] };
  /*
   * Bilingue (26/10/2026, Sam : « tout doit être possible en bilingue ») : la checklist se lit en français ou en
   * néerlandais — la langue du magasin (Qui fait quoi, sinon sa région), ou celle que le consultant choisit d'un toucher
   * en tête de l'étape. Une question sans néerlandais garde son français. Ce qui s'enregistre reste le libellé français :
   * c'est la référence du rapport et des plans.
   */
  const LNG = {
    fr: { ok: 'OK', ko: 'Écart', na: 'N/A', oui: 'Oui', non: 'Non', rem: 'Remarque…', rep: 'La réponse…', photo: 'Photo', photos: 'photos', multi: 'plusieurs choix possibles', points: 'points', tous: '✓ Toutes les autres sont là', bornes: 'attendu', nc: 'non conforme' },
    nl: { ok: 'OK', ko: 'Afwijking', na: 'N.v.t.', oui: 'Ja', non: 'Nee', rem: 'Opmerking…', rep: 'Het antwoord…', photo: 'Foto', photos: 'foto’s', multi: 'meerdere keuzes mogelijk', points: 'punten', tous: '✓ Alle andere zijn aanwezig', bornes: 'verwacht', nc: 'niet conform' }
  };
  const libDe = (x, l) => (l === 'nl' && x.libelle_nl) ? x.libelle_nl : x.libelle;
  const nomDe = (m, l) => (l === 'nl' && m.nom_nl) ? m.nom_nl : m.nom;
  const choixDe = (c, l) => (l === 'nl' && c.nl) ? c.nl : c.fr;
  // Le type de réponse d'une question (src/visites_questions.php) : sans type, l'ancienne règle (% ou OK / Écart / N/A).
  const typeQ = pt => pt.type || (pt.pct ? 'pct' : 'etat');
  const TYPES_Q = { etat: 'OK / Écart / N/A', ouinon: 'Oui / Non', note: 'Note de 1 à 5', pct: 'Pourcentage', nombre: 'Nombre', choix: 'Choix unique', multi: 'Choix multiples', texte: 'Texte libre' };
  const nbTxt = n => n == null || n === '' ? '' : String(Math.round(Number(n) * 100) / 100).replace('.', ',');
  /*
   * La visite en six temps (26/10/2026, ordre validé par Sam) : on fait le tour debout — l'extérieur, l'intérieur —,
   * on vérifie sur place ce qui avait été décidé, puis on s'assoit avec le franchisé pour les chiffres, qui mènent
   * aux décisions ; la météo et les signatures ferment. Chaque étape porte son temps ; la barre du bas montre les six.
   */
  const TEMPS = [['ext', 'Extérieur'], ['int', 'Intérieur'], ['suivi', 'Suivi de projet'], ['analyse', 'Analyse'], ['dev', 'Développement'], ['fin', 'Météo et fin']];
  // Les étapes bloquantes livrées (26/10/2026) : ce qui se remplit sur place bloque ; ce qui dépend d'une lecture extérieure
  // (contrôles du jour de l'équipe, checklist du panel) ne bloque pas. Réglages › Contrôle guidé les change étape par étape.
  const BLOQUE_DEFAUT = { photos_ext: true, photos_int: true, 'mod:*': true, controles: false, checklist: false, pistes: true, constat: true, meteo: true };
  // Les points d'une checklist qui se regardent dehors : ils forment le module « L'extérieur ».
  const RX_EXT = /fa[cç]ade|enseigne|vitrine ext|ext[ée]rieur|terrasse|abords|parking|trottoir/i;
  // Le module Produit commence par les produits obligatoires (26/10/2026), avec ce que le cockpit en sait (vus en caisse).
  const PT_OBLIGATOIRES = { ref: 'produits_obligatoires', libelle: 'Les produits obligatoires sont là', libelle_nl: 'De verplichte producten zijn aanwezig', photo: true, pct: false };
  const TAB_IC = {
    leviers: '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
    photo: '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
    controles: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
    chiffres: '<path d="M5 20V11M11 20V5M17 20v-8M2 20h20"/>',
    produit: '<path d="M3 15c2.5-7 15.5-7 18 0"/><path d="M3 15h18"/><path d="M8 10.5l1.2 4.5M12 9.5v5.5M16 10.5l-1.2 4.5"/>',
    hygiene: '<path d="M12 3s6 7 6 11a6 6 0 0 1-12 0c0-4 6-11 6-11z"/><path d="M9.5 14.5a2.5 2.5 0 0 0 2.5 2.5"/>',
    visuel: '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    planogramme: '<rect x="3" y="4" width="18" height="16" rx="1.5"/><path d="M3 10h18M3 15h18M9 4v16M15 4v16"/>',
    assortiment: '<path d="M10 6h10M10 12h10M10 18h10"/><path d="M3 6l1.5 1.5L7 5M3 12l1.5 1.5L7 11M3 18l1.5 1.5L7 17"/>',
    msp: '<circle cx="12" cy="10" r="3.5"/><path d="M5 20c1-4 4-6 7-6s6 2 7 6"/><path d="M5 6.5h14M8 6.5l1-3h6l1 3"/>',
    constat: '<path d="M4 20l4-1 11-11-3-3L5 16z"/><path d="M14 6l3 3"/>',
    plan: '<path d="M5 21V4h12l-2.5 4L17 12H5"/>',
    suivi: '<path d="M5 21V4h12l-2.5 4L17 12H5"/>',
    liste: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
    // Suivi des franchisés (24/10/2026) : le dossier et les points du bureau, la météo, la fin de visite signée.
    dossier: '<path d="M3 7h6l2 2h10v10H3z"/><path d="M3 7V5h6l2 2"/>',
    bureau: '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9zM9 12l2 2 4-4"/>',
    meteo: '<circle cx="9" cy="9" r="3.5"/><path d="M9 2v2M2 9h2M4 4l1.4 1.4M14 4l-1.4 1.4"/><path d="M8 19h10a3.5 3.5 0 0 0 0-7 5 5 0 0 0-9.5 1.5A2.8 2.8 0 0 0 8 19z"/>',
    fin: '<path d="M3 17c3-1 4-6 6-6s1 5 3 5 2-3 4-3 2 2 5 2"/><path d="M3 21h18"/>',
    // La visite en six temps (26/10/2026).
    t_ext: '<path d="M3 10l1.5-5h15L21 10"/><path d="M3 10c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 3 3s3-1.3 3-3"/><path d="M5 13v8h14v-8M10 21v-5h4v5"/>',
    t_int: '<path d="M3 15c2.5-7 15.5-7 18 0"/><path d="M3 15h18"/><path d="M5 19h14"/>',
    t_suivi: '<path d="M4 6l2 2 3-3M4 12l2 2 3-3M4 18l2 2 3-3"/><path d="M12 7h8M12 13h8M12 19h8"/>',
    t_analyse: '<path d="M5 20V11M11 20V5M17 20v-8M2 20h20"/>',
    t_dev: '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
    dev: '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
    t_fin: '<circle cx="9" cy="9" r="3.5"/><path d="M9 2v2M2 9h2M4 4l1.4 1.4M14 4l-1.4 1.4"/><path d="M8 19h10a3.5 3.5 0 0 0 0-7 5 5 0 0 0-9.5 1.5A2.8 2.8 0 0 0 8 19z"/>',
  };
  // La météo du franchisé, notée en fin de contrôle (12/10/2026).
  const METEO = [[5, '☀️', 'Grand soleil', 'motivé, moteur'], [4, '🌤️', 'Éclaircies', 'plutôt confiant'], [3, '⛅', 'Nuageux', 'mitigé'], [2, '🌧️', 'Pluie', 'découragé'], [1, '⛈️', 'Orage', 'tendu, en conflit']];
  const meteoDe = n => METEO.find(m => m[0] === Number(n)) || null;
  const MOIS_L = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const jours = (a, b) => Math.round((new Date(b + 'T12:00:00') - new Date(a + 'T12:00:00')) / 86400000);
  const lundiDe = (iso, dec) => { const d = new Date(iso + 'T12:00:00'); const j = (d.getDay() + 6) % 7; d.setDate(d.getDate() - j + 7 * (dec || 0)); return d.toISOString().slice(0, 10); };

  /* --- photos : réduire sur l'appareil, ré-encoder (donc sans EXIF) ------- */
  function compresser(fichier) {
    return new Promise((ok, ko) => {
      const url = URL.createObjectURL(fichier);
      const im = new Image();
      im.onload = () => {
        URL.revokeObjectURL(url);
        const max = 1600; let w = im.naturalWidth, h = im.naturalHeight;
        const r = Math.min(1, max / Math.max(w, h)); w = Math.round(w * r); h = Math.round(h * r);
        const c = document.createElement('canvas'); c.width = w; c.height = h;
        c.getContext('2d').drawImage(im, 0, 0, w, h);
        let q = 0.7; let data = c.toDataURL('image/jpeg', q);
        while (data.length > 2 * 1024 * 1024 * 1.37 && q > 0.4) { q -= 0.1; data = c.toDataURL('image/jpeg', q); }
        ok({ data, largeur: w, hauteur: h });
      };
      im.onerror = () => { URL.revokeObjectURL(url); ko(new Error('image illisible')); };
      im.src = url;
    });
  }
  function position() {
    return new Promise(ok => {
      if (!navigator.geolocation) { return ok(null); }
      navigator.geolocation.getCurrentPosition(p => ok({ lat: p.coords.latitude, lng: p.coords.longitude }), () => ok(null), { timeout: 4000, maximumAge: 600000 });
    });
  }

  /* --- l'hôte ---------------------------------------------------------------- */
  class Hote {
    constructor(host, o) {
      this.host = host; this.o = o;
      this.role = o.role || 'consultant';
      this.shop = o.shop ? String(o.shop) : null;
      this.moi = o.id || (this.role === 'consultant' ? (localStorage.getItem('vi.moi') || '') : '');
      this.D = null; this.B = {}; this.C = {}; this.K = {}; this.KS = {}; this.VB = {}; this.vbVue = null; this.AN = {}; this.LV = {}; this.TM = {}; this.tmN = 6; this.TMP = {}; this.tmFile = []; this.tmV = null; this.anm = null; this.levVue = 'mois'; this.T = {}; this.cqF = {}; this.cqV = null; this.S = null; this.R = null;
      this.v = o.vue || (o.tablette ? 'shops' : this.role === 'franchise' ? 'plans' : this.role === 'admin' ? 'admin' : 'agenda');
      this.tabVue = (() => { try { return localStorage.getItem('vi.tabVue') || 'liste'; } catch (e) { return 'liste'; } })(); this.mois = 0;
      this.p = null; this.sem = 0; this.form = {}; this.file = []; this.enLigne = navigator.onLine; this.sync = null; this.busy = false;
      this.toast = null; this.voir = null; this.ouvert = {}; this.CK = null; this.ckF = {};
      if (!document.getElementById('vi-css')) { const st = document.createElement('style'); st.id = 'vi-css'; st.textContent = CSS; document.head.appendChild(st); }
      host.innerHTML = '<div class="vi ' + (o.tablette ? 'tab' : o.mobile ? 'mob' : 'desk') + '"><div class="sc"><div class="mu sm" style="padding:20px 0">Lecture…</div></div></div>';
      host.addEventListener('click', e => this.clic(e));
      host.addEventListener('input', e => this.saisie(e));
      host.addEventListener('change', e => this.change(e));
      this.ecouterSignature(host); this.ecouterGlisse(host);
      window.addEventListener('online', () => { this.enLigne = true; this.rejouer(); this.rendre(); });
      window.addEventListener('offline', () => { this.enLigne = false; this.rendre(); });
      if (o.mobile || o.tablette) { window.addEventListener('hashchange', () => this.deHash()); this.deHash(true); }
      this.demarrer();
    }

    /* --- réseau et file --------------------------------------------------- */
    api(path) { return this.o.apiBase.replace(/\/$/, '') + path; }
    async lire(path, cle) {
      cle = cle || path;
      try {
        const r = await fetch(this.api(path), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!r.ok) { throw new Error('http ' + r.status); }
        const j = await r.json();
        this.enLigne = true;
        await Idb.put('cache', { quand: maintenant(), val: j }, cle);
        this.sync = maintenant();
        return j;
      } catch (e) {
        const c = await Idb.get('cache', cle);
        if (c) { this.sync = c.quand; return c.val; }
        throw e;
      }
    }
    async demarrer() {
      this.file = await Idb.tous('file');
      try {
        await this.charger();
      } catch (e) { this.host.innerHTML = '<div class="vi"><div class="sc"><div class="card">Le serveur ne répond pas et rien n’est encore en réserve sur cet appareil. Réessayez avec du réseau.</div></div></div>'; return; }
      this.rendre();
      this.suivreConformite(this.v, this.p);
      if (this.file.length) { this.rejouer(); }
    }
    async charger() {
      const q = this.role === 'franchise' ? '?shop=' + encodeURIComponent(this.shop) : '?role=' + this.role + (this.moi ? '&id=' + encodeURIComponent(this.moi) : '');
      this.D = await this.lire('/visites/app' + q, 'app:' + this.role + ':' + (this.shop || ''));
      this.appliquerFile();
    }
    async recharger() { try { await this.charger(); } catch (e) { /* on garde ce qu'on a */ } this.rendre(); }
    /** Une écriture : appliquée à l'écran, mise en file, envoyée. */
    async ecrire(op) {
      op.id = op.id || uuid(); op.quand = maintenant();
      this.file.push(op);
      await Idb.put('file', op);
      this.rendre();
      return this.rejouer();
    }
    async rejouer() {
      if (this.busy || !this.file.length) { return; }
      this.busy = true;
      try {
        while (this.file.length) {
          const op = this.file[0];
          // En vol : un point noté pendant l'envoi ne s'ajoute plus à ce lot (il était perdu au retour du serveur,
          // mesuré le 26/10/2026 en notant vite plusieurs points) ; il part dans le lot suivant.
          op.envoi = true;
          let r;
          try {
            r = await fetch(this.api(op.path), { method: op.method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(op.body || {}) });
          } catch (e) { this.enLigne = false; op.envoi = false; break; }
          this.enLigne = true;
          let j = null; try { j = await r.json(); } catch (e) { j = {}; }
          if (!r.ok && r.status >= 500) { op.envoi = false; break; }
          this.file.shift(); await Idb.del('file', op.id);
          if (!r.ok) { this.dire('Refusé par le serveur : ' + ((j && j.error) || r.status)); if (op.apres === 'controle') { this.cqAnnule(op.body); } }
          else if (op.apres) { this.apres(op, j); }
        }
      } finally { this.busy = false; }
      if (!this.file.length) { await this.recharger(); } else { this.rendre(); }
    }
    /** Ce que le serveur rend après une écriture vient remplacer la version locale. */
    apres(op, j) {
      const D = this.D; if (!D) { return; }
      if (op.apres === 'visite' && j.visite) { const i = D.visites.findIndex(v => v.client_id === j.visite.client_id || v.id === j.visite.id); if (i >= 0) { D.visites[i] = j.visite; } else { D.visites.push(j.visite); } if (this.v === 'fiche' && String(this.p) === String(op.body.client_id)) { this.p = j.visite.id; } }
      if (op.apres === 'plans' && j.plans) { j.plans.forEach(p => { const i = D.plans.findIndex(x => x.client_id === p.client_id || x.id === p.id); if (i >= 0) { D.plans[i] = p; } else { D.plans.push(p); } }); }
      if (op.apres === 'plan' && j.plan) { const i = D.plans.findIndex(x => x.id === j.plan.id || (x.client_id && x.client_id === j.plan.client_id)); if (i >= 0) { D.plans[i] = j.plan; } }
      if (op.apres === 'photo' && j.photo) { const i = D.photos.findIndex(x => x.client_id === j.photo.client_id); if (i >= 0) { D.photos[i] = j.photo; } else { D.photos.unshift(j.photo); } }
      if (op.apres === 'tache' && j.tache) { const i = (D.taches || []).findIndex(x => x.id === j.tache.id); if (i >= 0) { D.taches[i] = j.tache; } }
      if (op.apres === 'controle') { const t = this.cqTache(op.body); if (t) { t.attente = false; delete t._avant; } this.dire('Note ' + (op.body || {}).note + '/5 enregistrée.'); }
    }
    /** Les écritures en file, rejouées sur la lecture fraîche : l'écran ne recule pas. */
    appliquerFile() {
      const D = this.D;
      this.file.forEach(op => {
        const b = op.body || {};
        if (op.apres === 'visite' && op.method === 'POST' && !D.visites.some(v => v.client_id === b.client_id)) {
          D.visites.push({ id: b.client_id, client_id: b.client_id, shop: b.shop, consultant: b.consultant, consultantNom: b.consultant_nom, prevu_le: b.prevu_le, debut_h: b.debut_h, duree_min: b.duree_min, motif: b.motif, type: b.type || 'reguliere', statut: 'planifiee', attente: true });
        }
        if (op.apres === 'visite' && op.method === 'PUT') { const v = this.visite(op.path.split('/')[2]); if (v) { Object.assign(v, b, { attente: true }); } }
        if (op.apres === 'points') { (b.points || []).forEach(p => { const i = D.points.findIndex(x => String(x.visite_id) === String(op.path.split('/')[2]) && x.ref === p.ref); const l = Object.assign({ visite_id: op.path.split('/')[2] }, p); if (i >= 0) { D.points[i] = Object.assign(D.points[i], l); } else { D.points.push(l); } }); }
        if (op.apres === 'photo' && !D.photos.some(x => x.client_id === b.client_id)) { D.photos.unshift({ client_id: b.client_id, visite_id: b.visite_id || null, shop: b.shop, plan_id: b.plan_id || null, ref: b.ref || null, genre: b.genre, data: b.data, prise_a: b.prise_a, attente: true }); }
        if (op.apres === 'plans') { (b.plans || []).forEach(p => { if (!D.plans.some(x => x.client_id === p.client_id)) { D.plans.push(Object.assign({ id: p.client_id, statut: 'ouvert', cree_le: op.quand, maj_le: op.quand, retard: 0, age: 0, attente: true }, p)); } }); }
        if (op.apres === 'plan') { const p = this.plan(op.path.split('/')[2]); if (p) { Object.assign(p, b, { attente: true }); } }
      });
    }

    /* --- accès aux données ----------------------------------------------- */
    boutique(id) { return (this.D.boutiques || []).find(b => String(b.id) === String(id)) || null; }
    visite(id) { return (this.D.visites || []).find(v => String(v.id) === String(id) || (v.client_id && v.client_id === id)) || null; }
    plan(id) { return (this.D.plans || []).find(p => String(p.id) === String(id) || (p.client_id && p.client_id === id)) || null; }
    derniereVisiteDe(shop) { return (this.D.visites || []).filter(v => String(v.shop) === String(shop) && v.statut === 'terminee').sort((a, c) => a.prevu_le < c.prevu_le ? 1 : -1)[0] || null; }
    plansDe(shop, ouverts) { return (this.D.plans || []).filter(p => String(p.shop) === String(shop) && (!ouverts || /^(ouvert|reprendre|escalade)$/.test(p.statut))); }
    pointsDe(vid) { const m = {}; (this.D.points || []).filter(p => String(p.visite_id) === String(vid)).forEach(p => { m[p.ref] = p; }); return m; }
    photosDe(vid) { return (this.D.photos || []).filter(p => String(p.visite_id) === String(vid)); }
    photosJour(shop) { return (this.D.photos || []).filter(p => String(p.shop) === String(shop) && /^jour_/.test(p.genre)); }
    photoSrc(p) { return p.data ? p.data : (this.o.racine || '') + p.chemin; }
    consultantNom(id) { const c = (this.D.consultants || []).find(x => x.id === id); return c ? c.nom : (id || ''); }
    mesVisites() { return (this.D.visites || []).filter(v => this.role !== 'consultant' || !this.moi || v.consultant === this.moi || !v.consultant); }
    /* --- le cadre de visite : le type de chaque visite, sa checklist, ses tâches ---------- */
    typeDe(code) { return (this.D.types || []).find(t => t.code === (code || 'reguliere')) || null; }
    typeNom(code) { const t = this.typeDe(code); return t ? t.nom : (!code || code === 'reguliere' ? 'Régulière' : code); }
    /** Le consultant que le type appelle : le premier du profil demandé, sinon personne. */
    consultantParDefaut(t) { if (!t || !t.profil) { return ''; } const c = (this.D.consultants || []).find(x => x.profil === t.profil); return c ? c.id : ''; }
    derniereTerminee(shop, saufId) { return (this.D.visites || []).filter(v => String(v.shop) === String(shop) && v.statut === 'terminee' && String(v.id) !== String(saufId)).sort((a, b) => a.prevu_le < b.prevu_le ? 1 : -1)[0] || null; }
    /**
     * La checklist d'une visite : celle de son type. Deux types sont dynamiques —
     * le suivi de plan d'action reprend les plans ouverts de la boutique, la
     * revisite reprend les points non conformes de la dernière visite terminée.
     * Sans type (visites d'avant le cadre), la liste standard.
     */
    /** La checklist d'une visite : les points demandés par le bureau en tête, puis celle de son type. */
    checklistDe(v) {
      const t = this.typeDe(v.type);
      const cl = this.avecExterieur((t && t.dynamique ? [] : [MOD_XP]).concat(this.avecObligatoires(this.checklistType(v), v.shop), t && t.dynamique ? [] : [MOD_LEVIERS]));
      const pts = (v.prep && v.prep.points) || [];
      if (!pts.length) { return cl; }
      return [{ id: 'bureau', nom: 'Demandé par le bureau', nom_nl: 'Gevraagd door het kantoor', bureau: true, points: pts.map(p => ({ ref: p.ref, libelle: p.libelle, photo: true, pct: false })) }].concat(cl);
    }
    /** Les points qui se regardent dehors (façade, enseigne, terrasse…) sortent de leur module : ils forment « L'extérieur », en tête. */
    avecExterieur(cl) {
      const ext = [];
      const reste = cl.map(m => {
        if (m.revisite || m.id === 'suivi') { return m; }
        const e = m.points.filter(pt => RX_EXT.test(pt.libelle || '') || RX_EXT.test(pt.ref || ''));
        if (!e.length) { return m; }
        ext.push(...e);
        return Object.assign({}, m, { points: m.points.filter(pt => !e.includes(pt)) });
      }).filter(m => m.points.length || m.revisite || m.id === 'suivi');
      return ext.length ? [{ id: 'exterieur', nom: 'L’extérieur', nom_nl: 'De buitenkant', points: ext }].concat(reste) : reste;
    }
    /** Le temps d'un module de checklist dans la visite en six temps. */
    tempsModule(m) {
      if (m.bureau || m.revisite || m.id === 'suivi') { return 'suivi'; }
      if (m.leviers || m.id === 'msp') { return 'analyse'; }
      return m.id === 'exterieur' ? 'ext' : 'int';
    }
    /**
     * Le module Produit marqué `obligatoires`. Les obligatoires du magasin lues (conformité : l'assortiment obligatoire
     * exigé, 26/10/2026) : une ligne par produit, un Oui / Non du consultant — et le point « Références obligatoires
     * toutes présentes » de l'Assortiment s'efface, c'est le même contrôle. Pas encore lues : le point d'ensemble.
     */
    avecObligatoires(cl, shop) {
      const as = shop != null ? ((this.C[shop] || {}).assortiment || null) : null;
      const toutes = as && Array.isArray(as.toutes) && as.toutes.length ? as.toutes : null;
      const aProduit = cl.some(m => m.id === 'produit' || /^produits?$/i.test(String(m.nom || '').trim()));
      return cl.map(m => {
        if (toutes && aProduit && m.id === 'assortiment') { return Object.assign({}, m, { points: m.points.filter(pt => pt.ref !== 'obligatoires') }); }
        if (m.id !== 'produit' && !/^produits?$/i.test(String(m.nom || '').trim())) { return m; }
        const sans = m.points.filter(pt => pt.ref !== PT_OBLIGATOIRES.ref && !/obligatoire/i.test(pt.libelle || ''));
        const obl = toutes ? toutes.map(o => ({ ref: 'oblig_' + o.ref, libelle: 'Produit obligatoire : ' + o.nom, libelle_nl: 'Verplicht product: ' + o.nom, nom: o.nom, photo: false, pct: false, oblig: true, vue: o.vue, auComptoir: o.auComptoir }))
          : [m.points.find(pt => pt.ref === PT_OBLIGATOIRES.ref || /obligatoire/i.test(pt.libelle || '')) || PT_OBLIGATOIRES];
        return Object.assign({}, m, { obligatoires: true, points: obl.concat(sans) });
      });
    }
    checklistType(v) {
      const t = this.typeDe(v.type); const s = this.D.seuils || {};
      if (t && t.dynamique === 'plans') {
        const pl = this.plansDe(v.shop, true);
        return [{ id: 'suivi', nom: 'Plans d’action ouverts', points: pl.map(p => ({ ref: 'plan' + p.id, libelle: p.priorite + ' · ' + p.titre, photo: true, pct: false })) }];
      }
      if (t && t.dynamique === 'ko') {
        const der = this.derniereTerminee(v.shop, v.id); const pts = der ? this.pointsDe(der.id) : {};
        const base = der && !(this.typeDe(der.type) || {}).dynamique ? this.checklistDe(der) : (this.D.checklist || []);
        const mods = [];
        base.forEach(m => {
          const ko = m.points.filter(pt => { const p = pts[pt.ref]; return p && (p.etat === 'ko' || (p.note != null && p.note <= 2) || (pt.pct && p.valeur != null && p.valeur < (s.planoOrange || 80))); });
          if (ko.length) { mods.push({ id: m.id, nom: m.nom, points: ko, revisite: true }); }
        });
        return mods.length ? mods : [{ id: 'revisite', nom: 'Revisite — rien de non conforme à la dernière visite', points: [], revisite: true }];
      }
      return t && t.checklist && t.checklist.length ? t.checklist : (this.D.checklist || []);
    }
    /** Le lien « Ajouter à Google Agenda » d'une visite, bâti ici : pas de serveur à attendre. */
    gcal(v) {
      const b = this.boutique(v.shop) || { nom: String(v.shop), court: String(v.shop), ville: '' };
      const d = String(v.prevu_le || '').replace(/-/g, ''); const h = String(v.debut_h || '09:00').replace(':', '') + '00';
      const fin = new Date(v.prevu_le + 'T' + (v.debut_h || '09:00') + ':00'); fin.setMinutes(fin.getMinutes() + Number(v.duree_min || 90));
      const p = n => String(n).padStart(2, '0');
      const f = fin.getFullYear() + p(fin.getMonth() + 1) + p(fin.getDate()) + 'T' + p(fin.getHours()) + p(fin.getMinutes()) + '00';
      return 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' + encodeURIComponent('Visite ' + (b.court || b.nom) + ' — ' + this.typeNom(v.type)) + '&dates=' + d + 'T' + h + '/' + f + '&ctz=Europe%2FBrussels&location=' + encodeURIComponent((b.nom || '') + (b.ville ? ', ' + b.ville : ''));
    }
    /** Les tâches du type sur la fiche de visite, à cocher ; et le lien vers l'agenda Google. */
    tachesVisiteHtml(v) {
      const ts = (this.D.taches || []).filter(t => String(t.visite_id) === String(v.id));
      const lien = `<div class="act"><a class="chip" target="_blank" rel="noopener" href="${esc(this.gcal(v))}">📅 Ajouter à Google Agenda</a></div>`;
      if (!ts.length) { return `<div class="card sm">${lien}</div>`; }
      const faites = ts.filter(t => t.statut === 'fait').length;
      return `<div class="cap">Tâches à faire — ${esc(this.typeNom(v.type))} · ${faites} / ${ts.length}</div><div class="card">${ts.map(t => `<div class="row" style="margin-top:4px;align-items:flex-start"><button class="cb ${t.statut === 'fait' ? 'ok' : ''}" data-a="tache" data-v="${esc(t.id)}|${t.statut === 'fait' ? 'a_faire' : 'fait'}">${t.statut === 'fait' ? '✓' : ''}</button><div class="sm" style="flex:1">${esc(t.titre)}<div class="xs mu">${esc(QUAND[t.quand] || '')}${t.echeance ? ' · ' + fmtD(t.echeance) : ''}${t.retard ? ' · <span class="dn">retard ' + t.retard + ' j</span>' : ''}${t.attente ? ' ⏳' : ''}</div></div></div>`).join('')}${lien}</div>`;
    }
    qui() { return this.role === 'consultant' ? (this.consultantNom(this.moi) || 'Consultant') : this.role === 'admin' ? 'Admin' : ('Franchisé ' + ((this.boutique(this.shop) || {}).court || '')); }
    ecartsDe(v) {
      const pts = this.pointsDe(v.id); const out = [];
      const s = this.D.seuils || {};
      // Le point proposé parle la langue de la visite : il part au franchisé tel quel.
      const l = this.lngDe(v); const T = LNG[l];
      this.checklistDe(v).forEach(m => m.points.forEach(pt => {
        const p = pts[pt.ref]; if (!p) { return; }
        const titre = libDe(pt, l); const rep = this.reponseTxt(pt, p.reponse, l);
        if (pt.pct && p.valeur != null && p.valeur < (s.planoOrange || 80)) { out.push({ ref: pt.ref, module: m.id, titre, detail: p.valeur + ' % de conformité' + (p.causes && p.causes.length ? ' · ' + p.causes.map(c => (this.D.causes || {})[c] || c).join(', ') : ''), grave: p.valeur < (s.planoRouge || 60) }); }
        else if (p.etat === 'ko' || (p.note != null && p.note <= 2)) { out.push({ ref: pt.ref, module: m.id, titre, detail: (p.note != null ? p.note + '/5' : (rep || T.nc)) + (p.commentaire ? ' · ' + p.commentaire : ''), grave: m.id === 'hygiene' || (p.note != null && p.note <= 1) }); }
      }));
      return out;
    }

    /* --- navigation --------------------------------------------------------- */
    go(v, p) {
      // Sur la tablette, l'agenda et la liste des boutiques ne font qu'un écran : l'accueil.
      if (this.o.tablette && (v === 'agenda' || v === 'portfolio')) { v = 'shops'; p = null; }
      this.v = v; this.p = p == null ? null : p; this.form = {};
      if (this.o.mobile || this.o.tablette) { const h = '#' + v + (p != null ? '/' + p : ''); if (location.hash !== h) { history.pushState(null, '', h); } }
      window.scrollTo(0, 0);
      this.rendre();
      if (v === 'tb' || v === 'historique') { this.chargerBoutique(p); }
      this.suivreConformite(v, p);
    }
    /** La conformité suit l'écran : le tableau de bord et la checklist la lisent. */
    suivreConformite(v, p) {
      if (v === 'tb' || v === 'historique') { this.chargerConformite(p); if (v === 'tb') { this.chargerSante(p); } return; }
      if (v === 'checklist' || v === 'review' || v === 'fiche' || v === 'controle') {
        const vi = this.visite(p);
        if (vi) { this.chargerConformite(vi.shop); if (v === 'review') { this.chargerCampagnes(vi.shop); } if (v === 'controle') { this.chargerControles(vi.shop); } if (v === 'controle' || v === 'fiche') { this.chargerSante(vi.shop); } }
      }
    }
    deHash(init) {
      const m = /^#([a-z]+)(?:\/([^/]+))?/.exec(location.hash || '');
      if (!m) { if (!init) { this.rendre(); } return; }
      this.v = m[1]; this.p = m[2] != null ? decodeURIComponent(m[2]) : null; this.form = {};
      if (!init) { this.rendre(); if (this.v === 'tb' || this.v === 'historique') { this.chargerBoutique(this.p); } this.suivreConformite(this.v, this.p); }
    }
    async chargerBoutique(shop) {
      if (!shop) { return; }
      if (this.B[shop] && this.B[shop].lu === this.sync) { return; }
      try { this.B[shop] = await this.lire('/visites/boutique/' + encodeURIComponent(shop), 'boutique:' + shop); } catch (e) { return; }
      this.rendre();
    }
    /**
     * Ce que le cockpit sait du comptoir : planogramme dessiné et assortiment
     * obligatoire. Lu une fois par boutique et par session — ni le comptoir ni
     * la liste des obligatoires ne bougent pendant une visite.
     */
    async chargerConformite(shop) {
      if (!shop || this.C[shop]) { return; }
      try { this.C[shop] = await this.lire('/visites/conformite?shop=' + encodeURIComponent(shop), 'conformite:' + shop); } catch (e) { return; }
      this.rendre();
    }
    /**
     * KPI et santé du magasin, les leviers (26/10/2026) : quatre lectures, chacune rendue dès qu'elle arrive — le CA face
     * au budget sur la semaine, le mois, le trimestre et l'année (/visites/budget, gardé une demi-heure), le CA par
     * ETP et la structure des ventes (/visites/kpi, gardés une heure), l'année mois par mois face à l'an passé et les
     * six leviers du mois aux routes du dashboard magasin (/ventes/semaines, /leviers/magasins, même cache que lui).
     * Lues une fois par boutique et par session, dès l'ouverture de la fiche ou du contrôle : avant d'arriver à
     * l'étape, et pour les garder hors ligne.
     */
    chargerSante(shop) {
      if (!shop || this.KS[shop]) { return; }
      this.chargerCartes();
      const an = Number(auj().slice(0, 4)), ym = auj().slice(0, 7);
      const lire = (path, cle, poser) => this.lire(path, cle).then(d => poser(d), () => poser({ erreur: true })).then(() => this.rendre());
      this.KS[shop] = { chargement: true }; this.AN[shop] = { chargement: true }; this.VB[shop] = { chargement: true };
      lire('/visites/kpi/' + encodeURIComponent(shop), 'kpi:' + shop, d => { this.KS[shop] = d; });
      lire('/visites/budget/' + encodeURIComponent(shop), 'vb:' + shop, d => { this.VB[shop] = d; });
      lire('/ventes/semaines?shop=' + encodeURIComponent(shop) + '&vue=annee&date=' + an + '-12-31&articles=1&_cache=900', 'an12:' + shop + ':' + an, d => { this.AN[shop] = d; });
      if (!this.LV[ym] || this.LV[ym].erreur) {
        this.LV[ym] = { chargement: true };
        lire('/leviers/magasins?annee=' + an + '&mois=' + Number(ym.slice(5, 7)) + '&_cache=900', 'lev:' + ym, d => { this.LV[ym] = d; });
      }
    }
    /**
     * Les tâches du magasin mois par mois (26/10/2026) : faites sur demandées, les notes du contrôle qualité, et les
     * tâches notées sous 4 du mois choisi, avec le commentaire du contrôleur. Un mois se lit une fois par session.
     */
    chargerTachesMois(shop, mois) {
      if (!shop) { return; }
      const T = this.TM[shop] || (this.TM[shop] = { parMois: {} });
      const cle = mois || '';
      if (T.parMois[cle] && !T.parMois[cle].erreur) { T.sel = cle; return; }
      T.sel = cle; T.parMois[cle] = { chargement: true };
      this.lire('/visites/taches/' + encodeURIComponent(shop) + (mois ? '?mois=' + mois : ''), 'tm:' + shop + ':' + cle)
        .then(d => { T.parMois[cle] = d; if (!cle && d && d.choisi) { T.parMois[d.choisi] = d; } }, () => { T.parMois[cle] = { erreur: true }; }).then(() => this.rendre());
    }
    /**
     * La photo d'une tâche notée sous 4 et les repères du contrôleur (/pwa/tasks/detail) : lue à la demande, quatre à
     * la fois, relue passé quinze minutes (l'adresse signée de la photo expire à vingt).
     */
    chargerPhotoNc(shop, taskId, jour) {
      const k = shop + '|' + taskId + '|' + jour; const P = this.TMP[k];
      if (P && (P.attente || P.chargement || Date.now() - (P.lu || 0) < 15 * 60 * 1000)) { return P; }
      this.TMP[k] = { attente: true }; this.tmFile.push(k); this.tmPompe();
      return this.TMP[k];
    }
    tmPompe() {
      while ((this._tmEnCours || 0) < 4 && this.tmFile.length) {
        const k = this.tmFile.shift(); const [shop, taskId, jour] = k.split('|');
        this._tmEnCours = (this._tmEnCours || 0) + 1; this.TMP[k] = { chargement: true };
        this.lire('/pwa/tasks/detail?shop=' + encodeURIComponent(shop) + '&task=' + encodeURIComponent(taskId) + '&date=' + jour, 'tmph:' + k)
          .then(d => { this.TMP[k] = { lu: Date.now(), url: d && /^https?:\/\//.test(String(d.photo || '')) ? d.photo : null, reperes: d && d.reperes && Array.isArray(d.reperes.liste) ? d.reperes.liste : [], faitPar: d ? d.faitPar : null, faitLe: d ? d.faitLe : null }; },
            () => { this.TMP[k] = { lu: Date.now() - 14 * 60 * 1000, url: null, reperes: [], erreur: true }; })
          .then(() => { this._tmEnCours--; this.rendre(); this.tmPompe(); });
      }
    }
    /** La photo avec les repères du contrôleur : chaque zone numérotée, à la couleur de son niveau. */
    tmPhotoHtml(P, t, grand) {
      const coul = n => n <= 2 ? '#C0182B' : n === 3 ? '#D97706' : '#2F5D8A';
      const rp = (P.reperes || []).map((r, i) => { const c = coul(+(r.niveau || t.note || 3)); return `<i class="tm-rp" style="left:${(r.x * 100).toFixed(1)}%;top:${(r.y * 100).toFixed(1)}%;width:${(r.l * 100).toFixed(1)}%;height:${(r.h * 100).toFixed(1)}%;border-color:${c}"><b style="background:${c}">${esc(r.n || i + 1)}</b></i>`; }).join('');
      return `<span class="${grand ? 'tm-big' : 'tm-sm'}"><img src="${esc(P.url)}" alt=""${grand ? '' : ' loading="lazy"'}>${rp}</span>`;
    }
    /** La photo en grand, ses repères, la note et le commentaire du contrôleur ; ‹ › dans l'ordre de la liste. */
    tmLoupe() {
      const V = this.tmV; const T = V && this.TM[V.shop]; const D = T && T.parMois[T.sel || ''];
      const L = D && D.taches; const t = L && L[V.i];
      if (!t) { this.tmV = null; return ''; }
      const P = this.chargerPhotoNc(V.shop, t.taskId, t.jour);
      const ph = P.url ? this.tmPhotoHtml(P, t, true) : `<span class="xs" style="color:#bbb">${P.chargement || P.attente ? 'lecture de la photo…' : 'pas de photo pour cette tâche'}</span>`;
      return `<div class="cq-l" role="dialog" aria-label="${esc(t.tache)}"><div class="hd"><span>${V.i + 1} / ${L.length}</span><span class="sp"></span><button type="button" class="x" data-a="tm-fermer" aria-label="fermer">✕</button></div>
        <div class="ph">${ph}</div>
        <div class="fi"><span class="tm-note ${t.note == null ? 'ref' : 'n' + t.note}">${t.note == null ? 'refusée' : t.note + '/5'}</span><b class="t">${esc(t.tache)}</b>
          <div class="q">${fmtD(t.jour)}${P.faitPar ? ' · faite par ' + esc(P.faitPar) + (P.faitLe ? ' à ' + esc(String(P.faitLe).slice(11, 16)) : '') : ''}</div>
          ${t.commentaire ? `<div class="sm" style="margin-top:6px">« ${esc(t.commentaire)} »</div>` : ''}
          <div class="q" style="margin-top:4px">${t.controleur ? 'Contrôle qualité : ' + esc(t.controleur) : 'Contrôle qualité'}${t.releveeLe ? ' · le ' + fmtD(t.releveeLe) + ' ' + esc(String(t.releveeLe).slice(11, 16)) : ''}</div>
          <div class="act"><button type="button" class="btn" data-a="tm-nav" data-v="-1"${V.i ? '' : ' disabled'}>‹ Précédente</button><button type="button" class="btn p" data-a="tm-nav" data-v="1"${V.i < L.length - 1 ? '' : ' disabled'}>Suivante ›</button></div></div></div>`;
    }
    /** Les cartes du dashboard magasin (cartes-magasin.js et son style) : déjà dans la page sur la tablette et le dashboard, ajoutées ailleurs. */
    chargerCartes() {
      if (window.CartesMagasin || this._cartes) { return; }
      this._cartes = true;
      const base = ICI || new URL((this.o.racine || '') + 'assets/js/visites.js', location.href).href;
      if (!document.querySelector('link[href*="cartes-magasin.css"]')) { const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = new URL('../css/cartes-magasin.css', base).href; document.head.appendChild(l); }
      const sc = document.createElement('script'); sc.src = new URL('cartes-magasin.js', base).href; sc.onload = () => this.rendre(); document.head.appendChild(sc);
    }
    /**
     * Les campagnes marketing d'une boutique, avec les clients face au N-1 et
     * à l'objectif. Lues une fois par boutique et par session ; le serveur les
     * garde vingt minutes.
     */
    async chargerCampagnes(shop) {
      if (!shop || this.K[shop]) { return; }
      this.K[shop] = { enCours: true };
      try { this.K[shop] = await this.lire('/visites/campagnes?shop=' + encodeURIComponent(shop), 'campagnes:' + shop); } catch (e) { delete this.K[shop]; return; }
      this.rendre();
    }
    campagnes(shop) { const K = this.K[shop]; return K && Array.isArray(K.campagnes) ? K.campagnes : null; }
    campagne(shop, id) { return (this.campagnes(shop) || []).find(c => String(c.id) === String(id)) || null; }
    /** La campagne mise en avant : celle qui a un objectif, en cours d'abord, sinon la dernière close. */
    campagnePrincipale(shop) {
      const L = this.campagnes(shop) || [];
      const rang = c => (c.pct != null ? 0 : 3) + (c.statut === 'encours' ? 0 : (c.statut === 'close' ? 1 : 2));
      return L.slice().sort((a, b) => rang(a) - rang(b) || String(b.debut).localeCompare(String(a.debut)))[0] || null;
    }
    /** Le mot sur l'état d'une campagne : « sept. · clos », « jour 3 / 60 », « dès le 1 déc. ». */
    campagneEtat(c) {
      if (c.statut === 'close') { return [MOIS[new Date(c.debut + 'T12:00:00').getMonth()] + ' · clos', 'st-fer']; }
      if (c.statut === 'encours') { return ['jour ' + c.jourCourant + ' / ' + c.nbJours, 'st-ouv']; }
      return ['dès le ' + fmtD(c.debut), 'st-att'];
    }
    /** La carte « Objectif de campagne » du plan d'action. */
    campagneCarte(shop) {
      const K = this.K[shop];
      if (!K || K.enCours) { return '<div class="card sm mu">lecture des campagnes…</div>'; }
      if (K.indispo) { return `<div class="card sm mu">${esc((K.motifs || []).join(' · ') || 'campagnes indisponibles')}</div>`; }
      const c = this.campagnePrincipale(shop);
      if (!c) { return '<div class="card sm mu">Aucune campagne en cours pour la boutique.</div>'; }
      const et = this.campagneEtat(c);
      const vsN1 = c.reel != null && c.n1Ecoule != null ? c.reel - c.n1Ecoule : null;
      const manque = c.reel != null && c.clientsPrevus != null ? c.clientsPrevus - c.reel : null;
      const sousR = c.statut === 'close' && c.clientsPrevus ? Math.round(100 * c.reel / c.clientsPrevus) + ' % de l’objectif'
        : (c.statut === 'encours' ? 'jour ' + c.jourCourant + (c.objectifJour ? ' · ' + nf(c.objectifJour) + ' / jour visés' : '') : 'pas commencée');
      const sousN = vsN1 != null ? sg(vsN1) + (c.n1Ecoule ? ' · ' + (vsN1 < 0 ? '−' : '+') + Math.abs(100 * vsN1 / c.n1Ecoule).toFixed(1).replace('.', ',') + ' %' : '') + (c.statut === 'encours' ? ' aux mêmes jours' : '') : (c.clientsJour ? c.clientsJour + ' par jour' : '');
      const obj = c.clientsPrevus != null ? `<div class="v">${nf(c.clientsPrevus)}</div><div class="s">${manque != null ? (manque > 0 ? 'il en ' + (c.statut === 'close' ? 'a manqué ' : 'manque ') + nf(manque) : 'atteint, ' + sg(-manque)) : (c.objectifJour ? nf(c.objectifJour) + ' par jour' : '')}</div>`
        : `<div class="v w">à fixer</div><div class="s">${c.clientsBudget ? nf(c.clientsBudget) + ' si le budget fait foi' : 'pas de « + x % »'}</div>`;
      const autres = (this.campagnes(shop) || []).filter(x => x !== c).map(x => {
        const e = this.campagneEtat(x);
        return `<div class="pc-ca s"><span>${x.statut === 'avenir' ? 'Suivante' : (x.statut === 'encours' ? 'En cours' : 'Précédente')} : <b>${esc(x.court)}</b> · ${fmtD(x.debut)} → ${fmtD(x.fin)}${x.clientsA1 ? ' · N-1 ' + nf(x.clientsA1) + ' clients' : ''}${x.statut === 'encours' && x.reel != null ? ' · ' + e[0] + ', ' + nf(x.reel) + ' clients' : ''}</span><span class="${x.clientsPrevus != null ? '' : 'w'}">${x.clientsPrevus != null ? 'objectif ' + nf(x.clientsPrevus) : 'objectif à fixer'}</span></div>`;
      }).join('');
      return `<div class="card pc-camp"><div class="t"><b>${esc(c.court)}</b><span class="pill ${et[1]}">${esc(et[0])}</span>${c.pct != null ? `<span class="pill">+${nf(c.pct)} % de clients</span>` : ''}</div>
        <div class="pc-3"><div><div class="k">Clients</div><div class="v p">${c.reel != null ? nf(c.reel) : '—'}</div><div class="s">${sousR}</div></div>
          <div><div class="k">N-1</div><div class="v n">${c.statut === 'encours' && c.n1Ecoule != null ? nf(c.n1Ecoule) : nf(c.clientsA1)}</div><div class="s">${sousN}${c.statut === 'encours' && c.clientsA1 ? ' · ' + nf(c.clientsA1) + ' sur la période' : ''}</div></div>
          <div><div class="k">Objectif</div>${obj}</div></div>
        ${(c.serie || []).length ? viGCumul(c.serie, c.clientsPrevus, [fmtD(c.debut), fmtD(c.fin)]) + pcLeg(!!c.clientsPrevus) : ''}
        ${c.caReel != null ? `<div class="pc-ca"><span>CA <b>${eur(c.caReel)}</b>${c.objectifCA ? ' sur ' + eur(c.objectifCA) + ' visés (' + Math.round(100 * c.caReel / c.objectifCA) + ' %)' : ''}</span><span>${c.caN1 != null ? 'N-1 ' + eur(c.caN1) : ''}</span></div>` : ''}
        ${autres}</div>`;
    }
    /** Sur une action (consultant, admin) : la campagne qu'elle sert. */
    campagneBloc(p) {
      if (!p.campagne_id) { return ''; }
      const c = this.campagne(p.shop, p.campagne_id);
      return c ? `<span class="pc-lien">📣 ${esc(c.court)} · ${esc(this.campagneEtat(c)[0])}</span>` : '<span class="pc-lien mu">📣 campagne</span>';
    }
    /* --- Les contrôles en photo : ce que l'équipe a rendu aujourd'hui -------------
     * Deux lectures par boutique (les tâches du jour, les photos signées) fondues
     * en une liste ; relues passé quinze minutes, les URL expirant à vingt. La
     * note rapide part dans la file, comme toute écriture : elle survit au
     * hors-ligne et se rejoue au retour du réseau. */
    async chargerControles(shop, force) {
      if (!shop) { return; }
      const T = this.T[shop];
      if (T && (T.enCours || (!force && Date.now() - (T.lu || 0) < 15 * 60 * 1000))) { return; }
      this.T[shop] = Object.assign({}, T, { enCours: true });
      const d = auj();
      let pt = null, ph = null;
      try {
        [pt, ph] = await Promise.all([
          this.lire('/pwa/tasks?date=' + d + '&shop=' + encodeURIComponent(shop), 'ctl:' + shop + ':' + d),
          this.lire('/pwa/tasks/photos?shop=' + encodeURIComponent(shop) + '&date=' + d, 'ctlph:' + shop + ':' + d)]);
      } catch (e) { this.T[shop] = Object.assign({}, T, { enCours: false, erreur: true, lu: Date.now() - 14 * 60 * 1000 }); this.rendre(); return; }
      const sh = ((pt && pt.shops) || []).find(x => String(x.shopId) === String(shop));
      const photos = {}; ((ph && ph.photos) || []).forEach(p => { if (/^https:\/\//.test(String(p.photo || ''))) { photos[String(p.taskId)] = p; } });
      const taches = ((sh && sh.taches) || []).map(t => {
        const p = photos[String(t.taskId)] || null;
        return Object.assign({}, t, { photoUrl: p ? p.photo : null, reperes: p && Array.isArray(p.reperes) ? p.reperes : [], checklistId: p ? p.checklistId : null, completionId: p ? p.completionId : null });
      });
      this.T[shop] = { lu: Date.now(), date: d, taches, seuil: (pt && pt.seuil) || 4, niveaux: (pt && pt.repartition) || [] };
      // Les notes encore en file (hors ligne) comptent déjà.
      this.file.filter(op => op.apres === 'controle' && String((op.body || {}).shopId) === String(shop)).forEach(op => this.cqApplique(op.body, true));
      this.rendre();
    }
    /** L'état d'une tâche, cinq mots : écart, à contrôler, conforme, non rendue, sans photo. */
    cqEtat(t, seuil) {
      if (t.note != null) {
        if (t.note >= seuil && t.accepte !== false) { return { c: 'ok', bd: t.note + '/5' }; }
        const lv = this.cqNiveau(t.note); return { c: 'nc', bd: t.note + '/5 · ' + (lv && lv.nom ? String(lv.nom).split(/[—–-]/).pop().trim().toLowerCase() : 'écart') };
      }
      if (t.statut === 'aControler' || t.statut === 'aValider') { return { c: 'ctl', bd: 'à contrôler' }; }
      if (t.statut === 'sansPhoto') { return { c: 'mu', bd: 'sans photo' }; }
      return { c: 'ko', bd: 'non rendue' };
    }
    cqNiveau(n) { const T = this.T[this._cqShop] || {}; return (T.niveaux || []).find(x => +x.n === +n) || null; }
    /** La liste triée d'une boutique : les écarts d'abord, les non rendues en fin de piste. */
    cqListe(shop) {
      const T = this.T[shop]; if (!T || !T.taches) { return null; }
      this._cqShop = shop;
      const RANG = { nc: 0, ctl: 1, ok: 2, ko: 3, mu: 4 };
      const L = T.taches.map(t => Object.assign({ e: this.cqEtat(t, T.seuil) }, t))
        .sort((a, b) => (RANG[a.e.c] - RANG[b.e.c]) || String(a.faitLe || '9').localeCompare(String(b.faitLe || '9')) || cqNom(a).localeCompare(cqNom(b)));
      const nb = c => L.filter(t => t.e.c === c).length;
      return { L, nb, total: L.length, avec: L.filter(t => t.photoUrl).length, aNoter: nb('ctl'), seuil: T.seuil,
        rapides: (T.niveaux || []).filter(x => +x.n >= T.seuil).sort((a, b) => a.n - b.n) };
    }
    /** Les cartes visibles, selon le filtre de la boutique. */
    cqVisibles(shop, cq) {
      const sel0 = this.cqF[shop] || 'tout'; const sel = sel0 !== 'tout' && !cq.nb(sel0) ? 'tout' : sel0;
      return { sel, F: sel === 'tout' ? cq.L : cq.L.filter(t => t.e.c === sel) };
    }
    /**
     * Sous les contrôles du jour : les derniers mois, tâches faites sur demandées (6 ou 12 mois, un mois se choisit
     * d'un toucher), puis les tâches notées sous 4 du mois choisi avec la note et le commentaire du contrôleur qualité.
     */
    tachesMoisHtml(shop) {
      const T = this.TM[shop]; if (!T) { this.chargerTachesMois(shop); return '<div class="cap">Les derniers mois</div><div class="card sm mu">Lecture des tâches des derniers mois…</div>'; }
      const D = T.parMois[T.sel || ''] || {};
      if (D.chargement) { return '<div class="cap">Les derniers mois</div><div class="card sm mu">Lecture des tâches des derniers mois…</div>'; }
      if (D.erreur || D.error || !D.mois) { return `<div class="cap">Les derniers mois</div><div class="card sm mu">Les tâches des derniers mois n’ont pas pu être lues. <button class="chip" data-a="tm-mois" data-v="${esc(shop)}|${esc(T.sel || '')}">Relire</button></div>`; }
      const n1 = n => n == null ? '—' : String(n).replace('.', ',');
      const lignes = D.mois.slice(0, this.tmN).map(m => {
        const vide = !m.demandees && !m.notees;
        return `<tr data-a="tm-mois" data-v="${esc(shop)}|${esc(m.mois)}" class="${m.mois === D.choisi ? 'on' : ''}"><td>${esc(m.lab)}${m.enCours ? ' <span class="xs mu">à hier</span>' : ''}</td>
          <td>${vide ? '<span class="mu">—</span>' : `<b>${m.faites}</b> / ${m.demandees}${m.pct != null ? `<span class="bar"><i style="width:${Math.max(2, m.pct)}%"></i></span> <span class="xs ${m.pct < 80 ? 'dn' : 'mu'}">${m.pct} %</span>` : ''}`}</td>
          <td>${m.notees || '—'}</td><td class="${m.sous ? 'dn' : 'mu'}"><b>${m.sous || '—'}</b></td><td>${n1(m.moyenne)}</td></tr>`;
      }).join('');
      const nc = D.taches || [];
      const liste = nc.length ? nc.map((t, i) => { const P = this.chargerPhotoNc(shop, t.taskId, t.jour); return `<div class="tm-nc"><button type="button" class="tm-ph" data-a="tm-voir" data-v="${esc(shop)}|${i}">${P.url ? this.tmPhotoHtml(P, t, false) : `<span class="vide">${P.chargement || P.attente ? 'lecture de la photo…' : 'pas de photo'}</span>`}<span class="tm-note ${t.note == null ? 'ref' : 'n' + t.note}">${t.note == null ? 'refusée' : t.note + '/5'}</span></button><div><div class="row" style="gap:8px"><span class="tm-note ${t.note == null ? 'ref' : 'n' + t.note}">${t.note == null ? 'refusée' : t.note + '/5'}</span><b class="sm" style="flex:1">${esc(t.tache)}</b><span class="xs mu">${fmtD(t.jour)}</span></div>
          ${t.commentaire ? `<div class="sm" style="margin-top:4px">« ${esc(t.commentaire)} »</div>` : '<div class="xs mu" style="margin-top:4px">sans commentaire du contrôleur</div>'}
          <div class="xs mu" style="margin-top:3px">${t.controleur ? 'Contrôle qualité : ' + esc(t.controleur) : 'Contrôle qualité'}${t.releveeLe ? ' · le ' + fmtD(t.releveeLe) + ' ' + esc(String(t.releveeLe).slice(11, 16)) : ''}${t.recidive ? ' · <span class="dn">' + t.recidive + ' jours non conformes sur 7</span>' : ''}${t.suite ? ' · re-notée ' + t.suite.note + '/5 le ' + fmtD(t.suite.jour) + (t.suite.conforme ? ' <span class="up">✓</span>' : '') : ' · pas encore re-notée'}</div></div></div>`; }).join('')
        : `<div class="sm mu">Aucune tâche notée sous ${D.seuil} en ${esc(D.choisiLab)}.</div>`;
      return `<div class="cap">Les derniers mois — tâches faites sur demandées</div>
        <div class="card"><div class="row" style="margin-bottom:6px;flex-wrap:wrap"><span class="xs mu">Touchez un mois pour voir ses tâches sous ${D.seuil}</span><span class="tm-n">${[6, 12].map(k => `<button class="${this.tmN === k ? 'on' : ''}" data-a="tm-n" data-v="${k}">${k} mois</button>`).join('')}</span></div>
          <div style="overflow-x:auto"><table class="tm-t"><tr><th>Mois</th><th>Faites / demandées</th><th>Notées</th><th>Sous ${D.seuil}</th><th>Moyenne</th></tr>${lignes}</table></div>
          <div class="xs mu" style="margin-top:6px">${esc(D.source || '')}</div></div>
        <div class="cap">Les tâches notées sous ${D.seuil} — ${esc(D.choisiLab)}${nc.length ? ' · ' + nc.length : ''}</div><div class="card">${D.indispo ? '<div class="sm mu">Les notes du contrôle qualité ne se lisent pas.</div>' : liste}</div>`;
    }
    controlesHtml(shop) {
      this.chargerControles(shop);
      const T = this.T[shop]; const cq = this.cqListe(shop);
      if (!cq) { return `<div class="card sm mu">${T && T.erreur ? 'Contrôles du jour indisponibles — hors ligne ?' : 'Lecture des contrôles du jour…'}</div>`; }
      const { sel, F } = this.cqVisibles(shop, cq);
      const avec = cq.L.filter(t => t.photoUrl);
      const heures = avec.map(t => hm(t.faitLe)).filter(Boolean).sort();
      const qui = [...new Set(avec.map(t => t.faitePar).filter(Boolean))].join(', ');
      const notees = cq.L.filter(t => t.note != null).length;
      const res = avec.length ? `<em>${avec.length} photo${avec.length > 1 ? 's' : ''}</em> rendue${avec.length > 1 ? 's' : ''}${heures.length ? ' ' + (heures[0] === heures[heures.length - 1] ? 'à ' + heures[0] : 'de ' + heures[0] + ' à ' + heures[heures.length - 1]) : ''}${qui ? ' par ' + esc(qui) : ''} · ${notees ? notees + ' notée' + (notees > 1 ? 's' : '') : 'pas encore notées'}` : 'aucune photo rendue pour l’instant';
      const puces = [['tout', 'Tout', null, cq.total], ['nc', 'Écarts', '#D97706', cq.nb('nc')], ['ctl', 'À contrôler', '#2F5D8A', cq.nb('ctl')], ['ok', 'Conformes', '#2d7a3e', cq.nb('ok')], ['ko', 'Non rendues', '#C0182B', cq.nb('ko')], ['mu', 'Sans photo', '#a59d93', cq.nb('mu')]]
        .filter(f => f[3]).map(f => `<button type="button" class="${sel === f[0] ? 'on' : ''}" data-a="cq-f" data-v="${esc(shop)}|${f[0]}">${f[2] ? `<i style="background:${f[2]}"></i>` : ''}${f[1]}<b>${f[3]}</b></button>`).join('');
      return `<div class="cq-res">${res}${cq.nb('ko') ? ' · ' + cq.nb('ko') + ' non rendue' + (cq.nb('ko') > 1 ? 's' : '') : ''}</div><div class="db-cqf cq-fs">${puces}</div>`
        + (F.length ? `<div class="db-cqpiste cq-piste">${F.map(t => this.cqCarte(shop, t, cq)).join('')}</div><div class="cq-pied">${F.length} contrôle${F.length > 1 ? 's' : ''}${sel === 'tout' ? ' · les écarts d’abord' : ''} · 4 et 5 notent sans commentaire · toucher une photo l’ouvre en grand</div>`
          : '<div class="sm mu" style="margin-top:8px">Aucune tâche dans ce filtre.</div>');
    }
    cqRepere(r, t) { const lv = this.cqNiveau(r.niveau || t.note || 3); return `<i style="left:${(r.x * 100).toFixed(1)}%;top:${(r.y * 100).toFixed(1)}%;width:${(r.l * 100).toFixed(1)}%;height:${(r.h * 100).toFixed(1)}%;border-color:${esc((lv && lv.couleur) || '#D97706')}"></i>`; }
    cqConstat(t) {
      const quand = t.valideeLe ? ' · noté ' + (/\d{2}:\d{2}/.test(String(t.valideeLe)) ? hm(t.valideeLe) : t.valideeLe) : '';
      if (t.e.c === 'nc') { return ((t.reperes || []).map(r => r.txt).filter(Boolean).join(', ') || t.comment || 'écart relevé') + quand; }
      if (t.e.c === 'ok') { return 'conforme' + quand; }
      if (t.e.c === 'ctl') { return 'photo déposée, pas encore notée'; }
      if (t.e.c === 'mu') { return 'rendue sans photo'; }
      return cqCl(t) || 'non rendue';
    }
    cqRapides(shop, t, cq, lg) {
      if (t.attente) { return '<div class="cq-acts"><span class="xs mu">envoi de la note…</span></div>'; }
      if (t.e.c !== 'ctl' || !cq.rapides.length) { return ''; }
      return `<div class="cq-acts">${cq.rapides.map(n => `<button type="button" class="cq-rapide${lg ? ' lg' : ''}" style="--c:${esc(n.couleur || '#2d7a3e')}" data-a="cq-note" data-v="${esc(shop)}|${esc(t.taskId)}|${n.n}" title="${esc(n.nom || '')} — note rapide, sans commentaire">${n.n}${lg ? ' · ' + esc(n.nom || '') : ''}</button>`).join('')}</div>`;
    }
    cqCarte(shop, t, cq) {
      const ph = !t.photoUrl ? `<span class="db-cqph vide ${t.e.c}"><b>${t.e.c === 'ko' ? '✗' : '—'}</b>${t.e.c === 'ko' ? 'pas encore rendue' : (t.e.c === 'mu' ? 'sans photo' : 'photo indisponible')}</span>`
        : `<span class="db-cqph"><img src="${esc(t.photoUrl)}" alt="" loading="lazy">${(t.reperes || []).map(r => this.cqRepere(r, t)).join('')}${hm(t.faitLe) ? `<em class="h">${hm(t.faitLe)}</em>` : ''}<em class="cqbd ${t.e.c}">${esc(t.e.bd)}</em></span>`;
      const meta = t.e.c === 'ko' ? 'pas encore rendue' : (t.e.c === 'mu' ? 'clôturée ' + hm(t.faitLe) : hm(t.faitLe) + (t.faitePar ? ' · ' + t.faitePar : ''));
      return `<div class="db-cqc"><button type="button" class="cq-ouvrir" data-a="cq-voir" data-v="${esc(shop)}|${esc(t.taskId)}">${ph}<span class="n">${esc(cqNom(t))}</span><span class="m">${esc(meta)}</span><span class="c ${t.e.c}">${esc(this.cqConstat(t))}</span></button>${this.cqRapides(shop, t, cq, false)}</div>`;
    }
    /** La photo en grand : sa fiche, la note rapide, ‹ › dans l'ordre de la bande. */
    cqLoupe() {
      const V = this.cqV; const cq = V ? this.cqListe(V.shop) : null;
      if (!cq) { this.cqV = null; return ''; }
      const { F } = this.cqVisibles(V.shop, cq);
      const i = F.findIndex(t => String(t.taskId) === String(V.taskId));
      if (i < 0) { this.cqV = null; return ''; }
      const t = F[i];
      const rendue = t.e.c === 'ko' ? 'pas encore rendue' : (hm(t.faitLe) ? 'à ' + hm(t.faitLe) + (t.faitePar ? ' par ' + t.faitePar : '') : 'rendue');
      const notee = t.note != null ? t.note + '/5' + (t.valideeLe ? ' · ' + (/\d{2}:\d{2}/.test(String(t.valideeLe)) ? hm(t.valideeLe) : t.valideeLe) : '') + (t.valideePar ? ' par ' + t.valideePar : '') : 'pas encore notée';
      return `<div class="cq-l" role="dialog" aria-label="${esc(cqNom(t))}"><div class="hd"><span>${i + 1} / ${F.length}</span><span class="sp"></span><button type="button" class="x" data-a="cq-fermer" aria-label="fermer">✕</button></div>
        <div class="ph">${t.photoUrl ? `<img src="${esc(t.photoUrl)}" alt="">` : `<span class="xs" style="color:#bbb">${t.e.c === 'ko' ? 'pas encore rendue' : 'pas de photo'}</span>`}</div>
        <div class="fi"><em class="cqbd ${t.e.c}">${esc(t.e.bd)}</em><b class="t">${esc(cqNom(t))}</b>${cqCl(t) ? `<div class="q">${esc(cqCl(t))}</div>` : ''}
          <div class="q" style="margin-top:6px">Rendue ${esc(rendue)}<br>Notée ${esc(notee)}${t.e.c === 'nc' ? '<br>' + esc(this.cqConstat(t)) : ''}</div>
          ${this.cqRapides(V.shop, t, cq, true)}
          <div class="act"><button type="button" class="btn" data-a="cq-nav" data-v="-1"${i ? '' : ' disabled'}>‹ Précédente</button><button type="button" class="btn p" data-a="cq-nav" data-v="1"${i < F.length - 1 ? '' : ' disabled'}>Suivante ›</button></div></div></div>`;
    }
    cqAvance(dir) {
      const V = this.cqV; const cq = V ? this.cqListe(V.shop) : null; if (!cq) { return; }
      const { F } = this.cqVisibles(V.shop, cq);
      const i = F.findIndex(t => String(t.taskId) === String(V.taskId)); const j = i + dir;
      if (j < 0 || j >= F.length) { return; }
      this.cqV = { shop: V.shop, taskId: F[j].taskId }; this.rendre();
    }
    cqTache(b) { const T = b && this.T[String(b.shopId)]; return T && T.taches ? T.taches.find(x => String(x.taskId) === String(b.taskId)) || null : null; }
    /** La note posée d'avance sur la carte ; `_avant` permet de la reprendre si le serveur refuse. */
    cqApplique(b, attente) {
      const t = this.cqTache(b); if (!t) { return; }
      const T = this.T[String(b.shopId)];
      if (!t._avant) { t._avant = { note: t.note, accepte: t.accepte, statut: t.statut, valideeLe: t.valideeLe, valideePar: t.valideePar }; }
      Object.assign(t, { note: b.note, accepte: b.note >= (T.seuil || 4), statut: 'notee', valideeLe: 'à l’instant', valideePar: b.auteur || this.qui(), attente: !!attente });
    }
    cqAnnule(b) { const t = this.cqTache(b); if (!t) { return; } if (t._avant) { Object.assign(t, t._avant); delete t._avant; } t.attente = false; this.rendre(); }
    /** La note rapide : 4 ou 5 en un clic, sans commentaire ni repère, dans la file. */
    cqNoter(shop, taskId, n) {
      const T = this.T[shop]; const t = T && T.taches ? T.taches.find(x => String(x.taskId) === String(taskId)) : null;
      if (!t || t.attente || !(n >= (T.seuil || 4))) { return; }
      const body = { shopId: shop, taskId, date: T.date, note: n, comment: '', checklistId: t.checklistId || null, completionId: t.completionId || null, role: 'consultant', auteur: this.qui() };
      this.cqApplique(body, true);
      this.ecrire({ method: 'POST', path: '/pwa/tasks/review', body, apres: 'controle' });
    }
    dire(msg) { this.toast = msg; this.rendre(); clearTimeout(this._tt); this._tt = setTimeout(() => { this.toast = null; this.rendre(); }, 3200); }

    /* --- rendu -------------------------------------------------------------- */
    rendre() {
      if (!this.D) { return; }
      // Le cockpit fusionne son DOM à chaque rendu et peut vider le div hôte : on le repeuple.
      let vi = this.host.querySelector('.vi');
      if (!vi) { this.host.innerHTML = '<div class="vi ' + (this.o.tablette ? 'tab' : this.o.mobile ? 'mob' : 'desk') + '"></div>'; vi = this.host.querySelector('.vi'); }
      const actif = document.activeElement; const focus = actif && this.host.contains(actif) && actif.dataset && actif.dataset.f ? { f: actif.dataset.f, pos: actif.selectionStart } : null;
      const V = this['v_' + this.v] ? this['v_' + this.v]() : this.v_agenda();
      const onglets = this.onglets();
      // Les bandes de photos gardent leur défilement d'un rendu à l'autre.
      const pistes = [...vi.querySelectorAll('.db-cqpiste')].map(e => e.scrollLeft);
      const tab = !!this.o.tablette;
      vi.innerHTML = (tab ? this.tabCadre(V) : this.o.mobile || this.o.sansOnglets ? '' : `<div class="onglets">${onglets.map(t => `<button data-a="go" data-v="${t[0]}" class="${t[0] === this.v || (t[3] || []).includes(this.v) ? 'on' : ''}">${t[2]}</button>`).join('')}</div>`)
        + (tab ? '' : `<div class="sc">${this.etat()}${V}</div>`)
        + (this.o.mobile && !this.o.sansOnglets && !tab ? `<div class="bas" style="grid-template-columns:repeat(${onglets.length},1fr)">${onglets.map(t => `<button data-a="go" data-v="${t[0]}" class="${t[0] === this.v || (t[3] || []).includes(this.v) ? 'on' : ''}"><i>${t[1]}</i>${t[2]}</button>`).join('')}</div>` : '')
        + (this.toast ? `<div class="toast">${esc(this.toast)}</div>` : '')
        + (this.voir ? `<div class="voir" data-a="fermer-voir"><img src="${esc(this.voir.src)}" alt=""><div class="txt">${esc(this.voir.txt)}</div></div>` : '')
        + (this.cqV ? this.cqLoupe() : '') + (this.tmV ? this.tmLoupe() : '');
      vi.querySelectorAll('.db-cqpiste').forEach((e, i) => { if (pistes[i]) { e.scrollLeft = pistes[i]; } });
      vi.querySelectorAll('canvas[data-sig]').forEach(sc => { const d = this.form[sc.dataset.sig]; if (d) { const im = new Image(); im.onload = () => sc.getContext('2d').drawImage(im, 0, 0); im.src = d; } });
      if (focus) { const el = this.host.querySelector(`[data-f="${focus.f.replace(/"/g, '\\"')}"]`); if (el) { el.focus(); try { if (focus.pos != null && el.setSelectionRange) { el.setSelectionRange(focus.pos, focus.pos); } } catch (e) { /* select */ } } }
    }
    onglets() {
      if (this.role === 'franchise') { return [['plans', '✅', 'Plan d’action', ['photo']], ['tb', '📊', 'Ma boutique', ['historique']], ['reglages', '⚙️', 'Réglages']]; }
      if (this.role === 'admin') { return [['admin', '✅', 'À valider'], ['portfolio', '🏪', 'Boutiques', ['tb', 'historique', 'fiche', 'planifier', 'checklist', 'review', 'controle', 'msp']], ['agenda', '📅', 'Agenda'], ['synthese', '📋', 'Synthèse'], ['reglages', '⚙️', 'Réglages']]; }
      return [['agenda', '📅', 'Agenda', ['fiche', 'planifier', 'checklist', 'review', 'controle']], ['portfolio', '🏪', 'Boutiques', ['tb', 'historique', 'msp']], ['plans', '✅', 'Plans', ['admin']], ['reglages', '⚙️', 'Réglages']];
    }
    etat() {
      const n = this.file.length;
      return `<div class="etat ${this.enLigne ? '' : 'off'}"><span class="pt-"></span><span>${this.enLigne ? 'en ligne' : 'hors ligne'}${this.sync ? ' · sync ' + esc(this.sync.slice(11)) : ''}</span>${n ? `<button class="chip" data-a="sync">📤 ${n} en attente</button>` : ''}<span class="sp"></span><button class="chip" data-a="recharger">↻</button></div>`;
    }
    hd(titre, sous, retour, droite) {
      return `<div class="hd">${retour ? `<button class="retour" data-a="go" data-v="${retour}">‹</button>` : ''}<div><h2>${esc(titre)}</h2>${sous ? `<div class="d">${sous}</div>` : ''}</div><span class="sp"></span>${droite || ''}</div>`;
    }
    feuDot(b) { return `<span class="feu ${b.feu}"></span>`; }
    /**
     * Le comptoir d'après le cockpit — ce que le consultant n'a pas à compter.
     *
     * Deux constats lus, jamais saisis : le planogramme (emplacements dessinés
     * et tenus, comptoirs photographiés montés ce jour, obligatoires sans place)
     * et l'assortiment obligatoire (celles qui n'ont pas passé un ticket sur la
     * fenêtre). Tant que la lecture n'est pas revenue, la carte le dit ; quand
     * une source manque, elle rend son motif plutôt qu'un chiffre inventé.
     */
    carteConformite(shop) {
      const C = this.C[shop];
      if (!C) { return '<div class="cap">Le comptoir d’après le cockpit ' + this.src('mix') + '</div><div class="card sm mu">Lecture du planogramme et de l’assortiment…</div>'; }
      const pl = C.planogramme || {}; const as = C.assortiment || {};
      const cle = 'cf:' + shop;
      const ouvert = !!this.ouvert[cle];
      const manq = (as.liste || []);
      const sansPlace = (pl.sansPlace || []);
      const feuPl = pl.pct == null ? '' : pl.pct < (this.D.seuils.planoRouge || 60) ? 'rouge' : pl.pct < (this.D.seuils.planoOrange || 80) ? 'orange' : 'vert';
      const feuAs = as.pct == null ? '' : as.pct < 90 ? (as.pct < 75 ? 'rouge' : 'orange') : 'vert';
      return '<div class="cap">Le comptoir d’après le cockpit ' + this.src('mix') + '</div>'
        + `<div class="card sm">
          <div class="row">${feuPl ? `<span class="feu ${feuPl}"></span>` : ''}<b>Planogramme</b><span class="sp"></span><b>${pl.pct != null ? pl.pct + ' %' : '—'}</b></div>
          <div class="mu">${pl.motif ? esc(pl.motif) : `${pl.tenus} emplacement${pl.tenus > 1 ? 's' : ''} tenu${pl.tenus > 1 ? 's' : ''} sur ${pl.emplacements} · ${pl.zones} comptoir${pl.zones > 1 ? 's' : ''} dessiné${pl.zones > 1 ? 's' : ''} · ${pl.comptoirsMontes ? pl.comptoirsMontes + ' photographié' + (pl.comptoirsMontes > 1 ? 's' : '') + ' monté' + (pl.comptoirsMontes > 1 ? 's' : '') + ' aujourd’hui' : 'aucune photo de montage aujourd’hui'}`}</div>
          ${sansPlace.length ? `<div class="xs" style="margin-top:4px">⚠ ${pl.obligatoiresSansPlace} obligatoire${pl.obligatoiresSansPlace > 1 ? 's' : ''} sans place au comptoir : <span class="mu">${sansPlace.slice(0, 6).map(x => esc(x.nom)).join(' · ')}${sansPlace.length > 6 ? ' …' : ''}</span></div>` : ''}
          <div class="row" style="margin-top:10px">${feuAs ? `<span class="feu ${feuAs}"></span>` : ''}<b>Assortiment obligatoire</b><span class="sp"></span><b>${as.pct != null ? as.pct + ' %' : '—'}</b></div>
          <div class="mu">${as.motif ? esc(as.motif) : `${as.presentes} des ${as.lisibles} obligatoires vues en caisse sur les ${as.jours} j jusqu’au ${fmtD(as.au)}${as.sansIdentifiant ? ' · ' + as.sansIdentifiant + ' sans identifiant de caisse' : ''}`}</div>
          ${manq.length ? `<div class="act" style="margin-top:6px"><button class="chip ${ouvert ? 'on' : ''}" data-a="drop" data-v="${esc(cle)}">${as.manquantes} manquante${as.manquantes > 1 ? 's' : ''} ${ouvert ? '▴' : '▾'}</button></div>` : as.motif ? '' : '<div class="xs up" style="margin-top:4px">Toutes les obligatoires sont passées en caisse.</div>'}
          ${ouvert && manq.length ? `<div class="sm" style="margin-top:6px">${manq.map(x => `<div class="row" style="padding:2px 0"><span>${esc(x.nom)}</span><span class="sp"></span><span class="xs mu">${x.auComptoir ? 'a sa place au comptoir' : 'pas de place au comptoir'}</span></div>`).join('')}${as.manquantes > manq.length ? `<div class="xs mu">… et ${as.manquantes - manq.length} autre(s)</div>` : ''}</div>` : ''}
          <div class="xs mu" style="margin-top:6px">Planogramme : comptoir dessiné dans le cockpit. Assortiment : références obligatoires × lignes de ticket du magasin, ${fmtD(as.du)} – ${fmtD(as.au)}.${as.retard > 2 ? ' La caisse s’arrête au ' + fmtD(as.derniereVente) + ' (' + as.retard + ' j de retard) : la fenêtre se cale dessus.' : ''}</div></div>`;
    }
    src(s) { return s === 'api' ? '<span class="pill api">API</span>' : s === 'mix' ? '<span class="pill mix">API + local</span>' : '<span class="pill loc">Local</span>'; }
    kpis(b, court) {
      const ca = b.ca, g = b.google, eq = b.equipe, msp = b.msp;
      const ouverts = this.plansDe(b.id, true); const p0 = ouverts.filter(p => p.priorite === 'P0');
      const retard = Math.max(0, ...ouverts.map(p => p.retard || 0));
      return `<div class="kpi">
        <div><div class="row xs mu">CA semaine ${ca ? this.src('api') : ''}</div><div class="k" style="margin-top:4px">${ca ? eur(ca.ca) : '—'}</div><div class="sm ${ca && ca.pct < 0 ? 'dn' : 'up'}">${ca ? (ca.pct != null ? pct(ca.pct) + ' vs ' + eur(ca.objectif) + ' objectif' : 'pas d’objectif') : 'ERP absent'}</div>${ca && ca.objectif ? `<div class="bar"><i style="width:${Math.max(2, Math.min(100, Math.round(ca.ca / ca.objectif * 100)))}%"></i></div>` : ''}</div>
        <div><div class="row xs mu">Clients ${msp ? this.src('mix') : this.src('api')}</div><div class="sm" style="margin-top:4px"><span class="pill api">Google</span> <b>${g ? note1(g.note) : '—'}</b>${g && g.avis != null ? ' · ' + g.avis + ' avis' : ''}</div><div class="sm" style="margin-top:3px"><span class="pill loc">MSP</span> ${msp && msp.total != null ? '<b>' + note1(msp.total) + '/20</b> · ' + esc(msp.mois) : '<span class="mu">pas de rapport</span>'}</div></div>
        <div><div class="row xs mu">Actions ouvertes ${this.src('local')}</div><div class="k" style="margin-top:4px;${p0.length ? 'color:#C0182B' : ''}">${ouverts.length}</div><div class="sm mu">${p0.length ? p0.length + ' P0' : 'aucun P0'}${retard ? ' · retard ' + retard + ' j' : ''}${court ? '' : ''}</div></div>
        <div><div class="row xs mu">Équipe ${this.src('local')}</div><div class="k" style="margin-top:4px">${eq && eq.effectif != null ? eq.effectif + (eq.prevu != null ? ' / ' + eq.prevu : '') : '—'}</div><div class="sm mu">${eq ? (eq.departs ? eq.departs + ' départ(s) · ' : '') + 'relevé ' + fmtD(eq.releve_le) : 'à relever'}</div></div>
      </div>`;
    }
    /**
     * KPI et santé du magasin (26/10/2026) : le CA par ETP des trois derniers mois clos face au réseau et au seuil,
     * l'année mois par mois face à l'an passé (la carte du dashboard magasin, vue Année) et la structure des ventes
     * par catégorie face au reste du réseau.
     */
    santeHtml(shop) {
      this.chargerSante(shop);
      const K = this.KS[shop];
      const cap = t => `<div class="cap">${t} ${this.src('api')}</div>`;
      const lu = K && !K.chargement && !K.erreur && !K.error && !K.indispo;
      const attente = !K || K.chargement ? '<div class="card sm mu">Lecture du CA par ETP et des ventes par catégorie au panel…</div>'
        : K.indispo ? `<div class="card sm mu">${esc(K.motif || 'indisponible')}</div>`
        : `<div class="card sm mu">Le CA par ETP et les ventes par catégorie n’ont pas pu être lus. <button class="chip" data-a="sante-relire" data-v="${esc(shop)}">Relire</button></div>`;
      return cap('CA par ETP') + (lu ? this.caEtpHtml(K.caEtp) : attente)
        + cap('L’année face à l’an passé') + this.anneeCarteHtml(shop)
        + (lu ? cap('Structure des ventes face au réseau') + this.structureHtml(K.structure, shop)
          + cap('Structure du CA : comptoir, B2B, click & collect, livraison bureau') + this.canauxHtml(K.canaux)
          + `<div class="xs mu" style="margin:2px 2px 0">Lu le ${fmtD(K.lu)} à ${esc(String(K.lu || '').slice(11, 16))}${(K.manques || []).length ? ' · relu dans quelques minutes : ' + esc(K.manques.join(', ')) + ' incomplet' : ''}.</div>` : '');
    }
    /**
     * Le CA face au budget (26/10/2026) : quatre onglets — la semaine, le mois, le trimestre, l'année, tous arrêtés à
     * la veille — le réalisé face à l'attendu à date, l'écart, ce qui manque, la place dans le réseau. Une étendue à
     * plus de 10 % sous l'attendu (le réglage caOrange) ouvre le formulaire : le pourquoi, noté par le consultant avec
     * le franchisé, ses trois pistes de développement et l'horizon (le mois prochain ou la fin du trimestre).
     */
    budgetHtml(v) {
      const shop = v.shop; this.chargerSante(shop);
      const B = this.VB[shop];
      const cap = `<div class="cap">Le CA face au budget ${this.src('api')} <button class="chip" data-a="sante-relire" data-v="${esc(shop)}" style="margin-left:6px">↻ Relire</button>${B && B.lu ? `<span class="xs mu" style="margin-left:6px;text-transform:none;letter-spacing:0">calculé à ${esc(String(B.lu).slice(11, 16))}</span>` : ''}</div>`;
      if (!B || B.chargement) { return cap + '<div class="card sm mu">Lecture du CA et du budget de la semaine, du mois, du trimestre et de l’année…</div>'; }
      if (B.indispo) { return cap + `<div class="card sm mu">${esc(B.motif || 'indisponible')}</div>`; }
      if (B.erreur || B.error || !B.periodes) { return cap + `<div class="card sm mu">Le CA face au budget n’a pas pu être lu. <button class="chip" data-a="sante-relire" data-v="${esc(shop)}">Relire</button></div>`; }
      const P = B.periodes; const ORD = ['jour', 'semaine', 'mois', 'trimestre', 'annee'].filter(k => P[k]); const NOM = { jour: 'Jour', semaine: 'Semaine', mois: 'Mois', trimestre: 'Trimestre', annee: 'Année' };
      const vue = P[this.vbVue] ? this.vbVue : ((B.sous || [])[0] || 'mois');
      const cls = st => st === 'sous' ? 'dn' : st === 'proche' ? 'or' : st === 'ok' ? 'up' : 'mu';
      const tabs = `<div class="vb-tabs" role="tablist" style="grid-template-columns:repeat(${ORD.length},minmax(0,1fr))">${ORD.map(k => { const x = P[k] || {}; return `<button role="tab" aria-selected="${k === vue}" class="${k === vue ? 'on' : ''}" data-a="vb-vue" data-v="${k}"><span>${NOM[k]}</span><b class="${cls(x.statut)}">${x.ecartPct != null ? vbPct(x.ecartPct) : '—'}</b></button>`; }).join('')}</div>`;
      const x = P[vue];
      const SRC = { budget: 'budget validé', theorique: 'CA théorique de l’étude', mixte: 'budget validé et CA théorique' };
      const arr = B.arrete ? fmtDJ(B.arrete) : '';
      let corps;
      if (x.statut === 'sans') {
        corps = `<div class="row"><b>${esc(cap1(x.lab))}</b><span class="xs mu">arrêté au ${esc(arr)}</span></div><div class="sm mu" style="margin-top:6px">Pas d’écart à montrer : ${esc(x.motif || 'pas de budget')}.${x.realise != null ? ' Réalisé ' + eur(x.realise) + '.' : ''}</div>`;
      } else {
        const obj = x.objectif || 0;
        const rempl = obj > 0 ? Math.max(1, Math.min(100, Math.round(x.realise / obj * 100))) : 0;
        const repere = obj > 0 ? Math.max(0, Math.min(100, Math.round(x.attendu / obj * 100))) : null;
        corps = `<div class="row"><b>${esc(cap1(x.lab))}</b><span class="xs mu">arrêté au ${esc(arr)}, jour clos</span><span class="sp"></span>${x.rang ? `<span class="pill">${x.rang}${x.rang === 1 ? 'er' : 'e'} sur ${x.sur} magasins</span>` : ''}</div>
          <div class="vb-big"><div><div class="xs mu">Réalisé</div><div class="k">${eur(x.realise)}</div></div><div><div class="xs mu">Attendu à date</div><div class="k mu">${eur(x.attendu)}</div></div>
            <div><div class="xs mu">Écart</div><div class="k ${cls(x.statut)}">${vbPct(x.ecartPct)}</div><div class="xs ${cls(x.statut)}">${x.manque > 0 ? 'il manque ' + eur(x.manque) : 'd’avance de ' + eur(-x.manque)}</div></div></div>
          ${obj > 0 ? `<div class="vb-j" title="réalisé ${eur(x.realise)} sur un objectif de ${eur(obj)}"><i class="${cls(x.statut)}" style="width:${rempl}%"></i>${repere != null ? `<em style="left:${repere}%"></em>` : ''}</div>
          <div class="xs mu vb-jl"><span>${x.atteintePct != null ? String(x.atteintePct).replace('.', ',') + ' % de l’objectif' : ''}</span><span>┃ l’attendu à date</span><span>objectif ${eur(obj)}</span></div>` : ''}
          <div class="xs mu" style="margin-top:6px">${SRC[x.source] ? esc(SRC[x.source]) : 'budget'}${x.reseau != null ? ' · le réseau ' + vbPct(x.reseau) : ''}${(x.sansBudget || []).length ? ' · ' + x.sansBudget.length + ' mois sans budget ou sans vente hors comparaison' : ''}</div>`;
      }
      // Le formulaire (pourquoi, trois pistes) se remplit au développement ; ici, l'appel à y aller.
      const E = this._etapes; const sousB = (B.sous || []).length;
      const renvoi = sousB ? `<div class="card ${this.vbExige(v) === 'fait' ? '' : 'alerte'} sm"><b class="${this.vbExige(v) === 'fait' ? 'up' : 'dn'}">CA à plus de ${String(B.seuil).replace('.', ',')} % sous le budget</b> — ${this.vbExige(v) === 'fait' ? 'le pourquoi et les trois pistes sont notés.' : 'le pourquoi et les trois pistes du franchisé se notent ensemble au développement.'}${E && E.pistes >= 0 ? ` <button class="chip" data-a="etape" data-v="${E.pistes}">${this.vbExige(v) === 'fait' ? 'Revoir' : 'Y aller'}</button>` : ''}</div>` : '';
      return cap + `<div class="card vb">${tabs}<div class="vb-c">${corps}${this.pnlHtml(x.pnl, B.seuilsPnl || {}, B.arrete)}</div></div>` + renvoi
        + `<div class="xs mu" style="margin:2px 2px 10px">${esc(B.source || '')}${(B.manques || []).length ? ' · relu dans quelques minutes : ' + esc(B.manques.join(', ')) : ''}.</div>`;
    }
    /**
     * Le mini P&L de l'étendue (26/10/2026) : le CA, le coût matière, la marge brute, les invendus (jour, semaine, mois),
     * le personnel, les frais généraux et le résultat net, en euros et en % du CA, face aux seuils de la Table KPI.
     */
    pnlHtml(P, S, arrete) {
      if (!P) { return '<div class="xs mu" style="margin-top:10px">Mini P&L indisponible sur cette étendue.</div>'; }
      const p1 = x => x == null ? '—' : String(x).replace('.', ',') + ' %';
      const L = [['CA', P.ca, 100, null], ['Coût matière', P.coutMatiere, P.coutMatierePct, S.food], ['Marge brute', P.margeBrute, P.margeBrutePct, null],
        P.avecInvendus ? ['Invendus', P.invendus, P.invendusPct, null] : null, ['Personnel', P.labour, P.labourPct, S.labour], ['Frais généraux', P.overhead, P.overheadPct, S.overhead],
        ['Résultat net', P.net, P.netPct, null]].filter(Boolean);
      const rows = L.map(([lab, v, pc, seuil], i) => { const net = i === L.length - 1; const ko = seuil != null && pc != null && pc > seuil;
        return `<tr class="${net ? 'net' : ''}"><td>${lab}</td><td class="r"><b class="${net && v != null ? (v < 0 ? 'dn' : 'up') : ''}">${eur(v)}</b></td><td class="r ${ko ? 'dn' : net && pc != null ? (pc < 0 ? 'dn' : 'up') : 'mu'}">${lab === 'CA' ? '' : p1(pc)}</td><td class="r xs mu">${seuil != null ? '≤ ' + p1(seuil) : ''}</td></tr>`; }).join('');
      return `<div class="pnl"><div class="row"><b class="sm">Mini P&L</b><span class="xs mu">du ${fmtD(P.du)} au ${fmtD(P.au)}${P.mois ? ' · ' + P.mois + ' mois' : ''}</span></div>
        <table class="tbl pnl-t"><tr><th></th><th class="r">€</th><th class="r">% du CA</th><th class="r">seuil</th></tr>${rows}</table>
        <div class="xs mu">${P.avecInvendus ? 'Tel que Résultat le calcule : net = marge brute − invendus − personnel − frais généraux' : 'Mois clos au P&L mensuel du panel (sans les invendus, qu’il ne porte pas), le mois en cours à Résultat'}${arrete && P.au > arrete ? ' · à date, aujourd’hui compris (le budget s’arrête à la veille)' : ''}${P.motif ? ' · ' + esc(P.motif) : ''}.</div></div>`;
    }
    /** 'manque' : une étendue à plus du seuil sous le budget et le formulaire incomplet ; 'fait' : complet ; null : rien à justifier (ou pas encore lu). */
    vbExige(v) {
      const B = this.VB[v.shop];
      if (!B || !B.periodes || !(B.sous || []).length) { return null; }
      const bc = v.budgetCa;
      return bc && bc.pourquoi && (bc.pistes || []).filter(p => p && p.trim()).length === 3 && bc.horizon ? 'fait' : 'manque';
    }
    /** L'horizon des pistes : la fin du mois prochain, ou la fin du trimestre en cours. */
    vbHorizons() {
      const t = auj(); const a = Number(t.slice(0, 4)); const m = Number(t.slice(5, 7));
      const fin = mm => new Date(Date.UTC(a, mm, 0)).toISOString().slice(0, 10);
      return { mois: { lab: 'Le mois prochain', echeance: fin(m + 1) }, trimestre: { lab: 'La fin du trimestre', echeance: fin(Math.ceil(m / 3) * 3) } };
    }
    /** Le suivi de projet : les points décidés aux visites précédentes, encore ouverts, vérifiés sur place. */
    pointsDecidesHtml(v) {
      const pl = this.plansDe(v.shop, true).slice().sort((a, b) => (b.retard || 0) - (a.retard || 0) || String(a.echeance || '9').localeCompare(String(b.echeance || '9')));
      if (!pl.length) { return '<div class="card sm mu">Aucun point ouvert chez ce franchisé : tout ce qui avait été décidé est fait.</div>'; }
      return `<div class="card sm mu">Ce qui avait été décidé et envoyé au franchisé, le plus en retard d’abord. Sur place : photographiez la correction, confirmez ce qui est fait, escaladez ce qui bloque.</div>`
        + pl.map(p => this.planLigne(p, this.actionsPlan(p))).join('');
    }
    /** L'étape des pistes (développement) : les étendues sous le budget, puis le formulaire. */
    vbPistesHtml(v) {
      const B = this.VB[v.shop]; if (!B || !B.periodes) { return '<div class="card sm mu">Lecture du CA et du budget…</div>'; }
      const lignes = (B.sous || []).map(k => { const x = B.periodes[k]; return `<div class="row"><b>${esc(cap1(x.lab))}</b><span class="sp"></span><b class="dn">${vbPct(x.ecartPct)}</b><span class="xs mu">il manque ${eur(x.manque)} · ${x.rang ? x.rang + (x.rang === 1 ? 'er' : 'e') + ' sur ' + x.sur : ''}</span></div>`; }).join('');
      return `<div class="card sm">${lignes}<div class="xs mu" style="margin-top:4px">Arrêté au ${esc(fmtDJ(B.arrete))} · le détail est à l’analyse, étape KPI.</div></div>` + this.vbFormHtml(v, B);
    }
    vbFormHtml(v, B) {
      if (!(B.sous || []).length) { return ''; }
      const f = this.form; const bc = v.budgetCa || {};
      if (!f.vb) { f.vb = { pourquoi: bc.pourquoi || '', pistes: [0, 1, 2].map(i => (bc.pistes || [])[i] || ''), horizon: bc.horizon || '' }; }
      const vb = f.vb; const H = this.vbHorizons();
      const NOM = { semaine: 'la semaine', mois: 'le mois', trimestre: 'le trimestre', annee: 'l’année' };
      const ou = B.sous.map(k => NOM[k] + ' ' + vbPct(B.periodes[k].ecartPct)).join(', ');
      const ok = this.vbExige(v) === 'fait';
      const nP = vb.pistes.filter(p => p.trim()).length;
      const manque = [!vb.pourquoi.trim() && 'le pourquoi', nP < 3 && (3 - nP) + ' piste' + (3 - nP > 1 ? 's' : ''), !vb.horizon && 'l’horizon'].filter(Boolean);
      return `<div class="card vb-f ${ok ? '' : 'alerte'}">
        <div class="row"><span class="feu ${ok ? 'vert' : 'rouge'}"></span><b>CA à plus de ${String(B.seuil).replace('.', ',')} % sous le budget</b>${ok ? '<span class="sp"></span><span class="pill st-val">noté ✓</span>' : ''}</div>
        <div class="sm" style="margin-top:4px">Sur ${esc(ou)}. À remplir ensemble : le consultant note pourquoi avec le franchisé ; le franchisé donne trois pistes de développement.</div>
        <div class="vb-l">Pourquoi ? <span class="xs mu">noté par le consultant avec le franchisé</span></div>
        <textarea data-f="vb|pourquoi" placeholder="Ce qui explique l’écart : trafic, panier, ruptures, équipe, travaux, météo…">${esc(vb.pourquoi)}</textarea>
        <div class="vb-l">Trois pistes de développement <span class="xs mu">données par le franchisé</span></div>
        ${[0, 1, 2].map(i => `<div class="vb-p"><span class="n">${i + 1}</span><input type="text" data-f="vb|p|${i}" value="${esc(vb.pistes[i])}" maxlength="190" placeholder="${['Ex. : relancer la carte de fidélité en caisse', 'Ex. : vitrine traiteur pleine dès 11 h', 'Ex. : une dégustation le samedi matin'][i]}"></div>`).join('')}
        <div class="vb-l">À mener pour…</div>
        <div class="vb-h">${['mois', 'trimestre'].map(k => `<button class="${vb.horizon === k ? 'on' : ''}" data-a="vb-h" data-v="${k}"><b>${H[k].lab}</b><span>d’ici le ${fmtD(H[k].echeance)}</span></button>`).join('')}</div>
        <div class="btns"><button class="btn p w" data-a="vb-save" data-v="${esc(v.id)}">💾 Enregistrer · les pistes passent dans « Points à travailler »</button></div>
        <div class="xs ${manque.length ? 'dn' : 'mu'}" style="margin-top:6px">${manque.length ? 'À compléter : ' + manque.join(', ') + '.' : bc.le ? 'Enregistré le ' + fmtD(bc.le) + ' à ' + esc(String(bc.le).slice(11, 16)) + '.' : 'Prêt à enregistrer.'}</div>
      </div>`;
    }
    /** Le formulaire posé sur la visite (PUT /visites/{id}, champ budgetCa), et ses pistes proposées au plan d'action. */
    vbSauver(vid) {
      const v = this.visite(vid); const vb = this.form.vb; const B = v && this.VB[v.shop];
      if (!v || !vb || !B || !B.periodes) { return; }
      const H = this.vbHorizons(); const ecarts = {};
      (B.sous || []).forEach(k => { ecarts[k] = B.periodes[k].ecartPct; });
      const bc = { pourquoi: vb.pourquoi.trim(), pistes: vb.pistes.map(p => p.trim()), horizon: vb.horizon || null, echeance: vb.horizon ? H[vb.horizon].echeance : null, ecarts, arrete: B.arrete || null, le: maintenant().replace('T', ' ') };
      this.majVisite(vid, { budgetCa: bc });
      this.vbPistesDansPlan(bc);
      const complet = this.vbExige(v) === 'fait';
      this.dire(complet ? 'Enregistré : les trois pistes sont proposées dans « Points à travailler », à mener d’ici le ' + fmtD(bc.echeance) + '.' : 'Enregistré — le formulaire reste à compléter.');
      this.rendre();
    }
    /** Les pistes du franchisé dans le plan d'action proposé : une action par piste, assignée au franchisé, à l'échéance choisie. */
    vbPistesDansPlan(bc) {
      const f = this.form; if (!f.pa || !bc) { return; }
      const NOM = { semaine: 'semaine', mois: 'mois', trimestre: 'trimestre', annee: 'année' };
      const ou = Object.keys(bc.ecarts || {}).map(k => NOM[k] + ' ' + vbPct(bc.ecarts[k])).join(', ');
      (bc.pistes || []).forEach((t, i) => {
        const ref = 'budget:' + (i + 1); const j = f.pa.findIndex(a => a.ref === ref);
        if (!t || !t.trim()) { if (j >= 0) { f.pa.splice(j, 1); } return; }
        const a = { cid: j >= 0 ? f.pa[j].cid : uuid(), ref, titre: t.trim(), detail: 'Piste de développement du franchisé — CA sous le budget' + (ou ? ' (' + ou + ')' : ''), priorite: 'P1', assigne: 'franchise', echeance: bc.echeance || plusJours(auj(), 30), garde: true };
        if (j >= 0) { f.pa[j] = a; } else { f.pa.push(a); }
      });
    }
    /** La carte « l'année, mois par mois, face à l'an passé » du dashboard magasin (cartes-magasin.js), telle quelle. */
    anneeCarteHtml(shop) {
      if (!window.CartesMagasin) { return '<div class="card sm mu">Préparation du graphique de l’année…</div>'; }
      const A = this.AN[shop]; const pret = A && !A.chargement && !A.erreur;
      return '<div class="cm">' + window.CartesMagasin.annee(pret ? A : null, { annee: Number(auj().slice(0, 4)), mesure: this.anm, err: A && A.erreur ? 'les douze mois n’ont pas pu être lus au panel' : null }) + '</div>';
    }
    /**
     * Au-dessus des points du module « Les leviers » : la carte des six leviers du dashboard magasin (le mois à date,
     * ou l'évolution de l'année, mois par mois) et les derniers avis Google, pour vérifier la note avec le franchisé.
     */
    leviersHtml(shop) {
      this.chargerSante(shop);
      const L = this.LV[auj().slice(0, 7)]; const pret = L && !L.chargement && !L.erreur;
      const carte = !window.CartesMagasin ? '<div class="card sm mu">Préparation des six leviers…</div>'
        : '<div class="cm">' + window.CartesMagasin.leviers(pret ? L : null, { shop: String(shop), vue: this.levVue, err: L && L.erreur ? 'pas de lecture au panel' : null }) + '</div>';
      const g = (this.boutique(shop) || {}).google;
      const avis = g && g.note != null ? `<div class="card sm"><div class="row"><b>Google ${note1(g.note)} ★</b><span class="xs mu">${g.avis != null ? g.avis + ' avis' : ''}${g.faibles ? ' · ' + g.faibles + ' avis ≤ 2/5 sur 30 j' : ''}</span></div>${(g.derniers || []).slice(0, 3).map(d => `<div class="xs" style="margin-top:5px"><b class="${d.note != null && d.note <= 2 ? 'dn' : ''}">${d.note != null ? d.note + ' ★' : ''}</b> <span class="mu">${fmtD(d.le)}</span>${d.extrait ? ' · « ' + esc(d.extrait) + ' »' : ''}</div>`).join('')}</div>` : '';
      return carte + avis + '<div class="xs mu" style="margin:6px 2px 8px">Sur place, levier par levier : chaque point se note, se photographie et part dans le rapport s’il n’est pas conforme.</div>';
    }
    /** Les produits obligatoires du module Produit, un Oui / Non chacun (présent en rayon ou non), et « Toutes présentes ». */
    obligToggleHtml(v, m, pts, cnt) {
      const obl = m.points.filter(pt => pt.oblig); if (!obl.length) { return ''; }
      const l = this.lngDe(v); const nl = l === 'nl'; const T = LNG[l];
      let oui = 0, non = 0;
      const lignes = obl.map(pt => { cnt.total++; const p = pts[pt.ref] || {}; if (p.etat) { cnt.faits++; if (p.etat === 'ok') { oui++; } else if (p.etat === 'ko') { non++; } }
        const k = `${esc(v.id)}|${esc(pt.ref)}|${m.id}`;
        return `<div class="ob ${p.etat === 'ko' ? 'ko' : p.etat === 'ok' ? 'ok' : ''}"><div class="nm"><b>${esc(pt.nom || pt.libelle)}</b><small class="${pt.vue === false ? 'dn' : 'mu'}">${pt.vue === true ? (nl ? 'verkocht aan de kassa' : 'vue en caisse') : pt.vue === false ? (nl ? 'niet verkocht in de periode' : 'pas vendue sur la période') : (nl ? 'zonder kassacode' : 'sans identifiant de caisse')}${pt.auComptoir === false ? (nl ? ' · geen plaats op de toonbank' : ' · sans place au comptoir') : ''}</small></div>
          <span class="yn">${[['ok', T.oui], ['ko', T.non]].map(([e, lab]) => `<button data-a="etat-set" data-v="${k}|${e}" class="${p.etat === e ? e : ''}">${lab}</button>`).join('')}</span></div>`; }).join('');
      const reste = obl.length - oui - non;
      return `<div class="card obl"><div class="row"><b>${nl ? 'Zijn de verplichte producten aanwezig?' : 'Les produits obligatoires sont là ?'}</b><span class="sp"></span><span class="xs ${non ? 'dn' : 'mu'}">${oui} ${T.oui.toLowerCase()} · ${non} ${T.non.toLowerCase()}${reste ? ' · ' + reste + (nl ? ' na te kijken' : ' à voir') : ''}</span></div>
        <div class="xs mu" style="margin:2px 0 8px">${nl ? 'Eén voor één, in het rek: Ja het is er, Nee het ontbreekt. « Verkocht aan de kassa »: wat de kassa ervan zegt.' : 'Un par un, en rayon : Oui il est là, Non il manque. « Vue en caisse » : ce que la caisse en dit.'}</div>
        <div class="obs">${lignes}</div>${reste ? `<div class="act"><button class="chip" data-a="oblig-tous" data-v="${esc(v.id)}|${esc(m.id)}">${T.tous} (${reste})</button></div>` : ''}</div>`;
    }
    /** Au-dessus des points du module Produit : les produits obligatoires d'après le cockpit (vus en caisse, ceux à chercher en rayon). */
    obligatoiresHtml(shop) {
      const C = this.C[shop]; const as = C && C.assortiment;
      if (!C) { return '<div class="card sm mu">Lecture des produits obligatoires…</div>'; }
      if (!as || as.motif) { return `<div class="card sm mu">Produits obligatoires : ${esc((as && as.motif) || 'aucune liste dans le cockpit')}.</div>`; }
      const manq = as.liste || [];
      return `<div class="card sm"><div class="row"><b>Produits obligatoires</b><span class="sp"></span><b>${as.presentes} / ${as.lisibles}</b></div>
        <div class="xs mu">vus en caisse sur les ${as.jours} derniers jours, jusqu’au ${fmtD(as.au)}</div>
        ${manq.length ? `<div class="sm" style="margin-top:6px"><b class="dn">À chercher en rayon</b> · ${manq.map(x => esc(x.nom)).join(' · ')}${as.manquantes > manq.length ? ' …' : ''}</div>` : '<div class="xs up" style="margin-top:4px">Toutes les obligatoires sont passées en caisse.</div>'}</div>`;
    }
    /** L'écart en pastille : vert dès +3 %, rouge dès −3 % (les pastilles de Résultat › Année) ; `pts` pour des points de part. */
    ecartPill(e, pts) {
      if (e == null) { return '<span class="ec">—</span>'; }
      const seuil = pts ? 2 : 3;
      const t = (e > 0 ? '+' : e < 0 ? '−' : '') + String(Math.abs(e)).replace('.', ',') + (pts ? ' pt' + (Math.abs(e) >= 2 ? 's' : '') : ' %');
      return `<span class="ec ${e >= seuil ? 'up' : e <= -seuil ? 'dn' : ''}">${t}</span>`;
    }
    caEtpHtml(E) {
      if (!E || !(E.mois || []).length) { return '<div class="card sm mu">CA par ETP indisponible : le panel n’a rendu ni le CA ni le planning.</div>'; }
      const d = E.mois[E.mois.length - 1];
      const sous = d.caEtp != null && E.seuil ? Math.round((d.caEtp / E.seuil - 1) * 100) : null;
      const etp = n => n == null ? '—' : String(n).replace('.', ',');
      return `<div class="card kc">
        <div class="row"><b class="sm">${esc(d.lab)}</b><span class="xs mu">mois clos</span><span class="sp"></span>${d.rang ? `<span class="xs mu">${d.rang}${d.rang === 1 ? 'er' : 'e'} sur ${d.sur}</span>` : ''}</div>
        <div class="row" style="align-items:baseline;gap:10px"><span class="k ${sous != null && sous < 0 ? 'dn' : ''}">${d.caEtp != null ? eur(d.caEtp) : '—'}</span><span class="sm mu">par ETP</span>${sous != null ? this.ecartPill(sous) + '<span class="xs mu">vs seuil ' + eur(E.seuil) + '</span>' : ''}</div>
        <div class="sm mu" style="margin-top:2px">${d.caEtp == null ? (d.ca == null ? 'CA du mois non lu' : 'pas de planning ce mois : ETP inconnus, jamais devinés') + ' · ' : ''}Réseau ${eur(d.reseau)}${d.etp != null ? ' · ' + etp(d.etp) + ' ETP planifiés' : ''}</div>
        <div class="ksc" style="margin-top:6px"><table class="tbl"><tr><th>Mois</th><th>CA</th><th>ETP</th><th>CA / ETP</th><th>Réseau</th></tr>${E.mois.map(m => `<tr><td>${esc(m.lab)}</td><td>${eur(m.ca)}</td><td>${etp(m.etp)}</td><td><b class="${m.caEtp != null && m.caEtp < E.seuil ? 'dn' : ''}">${eur(m.caEtp)}</b></td><td class="mu">${eur(m.reseau)}</td></tr>`).join('')}</table></div>
        <div class="xs mu" style="margin-top:4px">${esc(E.source || '')}</div></div>`;
    }
    structureHtml(S, shop) {
      if (!S || !(S.categories || []).length) { return '<div class="card sm mu">La structure des ventes est indisponible : le panel n’a pas rendu les ventes par catégorie de ces mois.</div>'; }
      const cats = S.categories;
      const max = Math.max(1, ...cats.map(c => Math.max(c.part || 0, c.partReste || 0)));
      const p1 = x => x == null ? '—' : String(x).replace('.', ',') + ' %';
      const nomEc = c => esc(c.categorie) + ' (' + (c.ecart > 0 ? '+' : '−') + String(Math.abs(c.ecart)).replace('.', ',') + ' pts)';
      const retrait = cats.filter(c => c.ecart != null && c.ecart <= -2).sort((a, b) => a.ecart - b.ecart);
      const avance = cats.filter(c => c.ecart != null && c.ecart >= 2).sort((a, b) => b.ecart - a.ecart);
      const cle = 'ks-cats:' + shop; const tout = !!this.ouvert[cle];
      const vus = tout ? cats : cats.slice(0, 8);
      return `<div class="card kc">
        ${retrait.length || avance.length ? `<div class="sm" style="margin-bottom:4px">${retrait.length ? '<b class="dn">En retrait</b> ' + retrait.slice(0, 3).map(nomEc).join(' · ') : ''}${retrait.length && avance.length ? '<br>' : ''}${avance.length ? '<b class="up">En avance</b> ' + avance.slice(0, 3).map(nomEc).join(' · ') : ''}</div>` : '<div class="sm mu" style="margin-bottom:4px">Le magasin vend comme le reste du réseau : aucune catégorie à plus de 2 points.</div>'}
        ${vus.map(c => `<div class="lg"><div class="row" style="gap:8px"><span class="sm" style="flex:1">${esc(c.categorie)}</span><b class="sm">${p1(c.part)}</b><span class="xs mu">réseau ${p1(c.partReste)}</span>${this.ecartPill(c.ecart, true)}</div>
          <div class="kb"><i style="width:${Math.round((c.part || 0) / max * 100)}%"></i></div><div class="kb r"><i style="width:${Math.round((c.partReste || 0) / max * 100)}%"></i></div></div>`).join('')}
        ${cats.length > 8 ? `<div class="act"><button class="chip" data-a="drop" data-v="${esc(cle)}">${tout ? 'Les 8 premières ▴' : 'Les ' + cats.length + ' catégories ▾'}</button></div>` : ''}
        <div class="xs mu" style="margin-top:6px">Part de chaque catégorie dans le CA : le magasin (barre pleine) face au reste du réseau, ${S.autres} magasin${S.autres > 1 ? 's' : ''} (barre grise) · du ${fmtD(S.du)} au ${fmtD(S.au)}${S.moisLus < S.moisDemandes ? ' (' + S.moisLus + ' mois lus sur ' + S.moisDemandes + ')' : ''} · CA du magasin ${eur(S.ca)}, du reste ${eur(S.caReste)}</div></div>`;
    }
    /**
     * La structure du CA par canal (26/10/2026) : sur les trois derniers mois clos, le comptoir, le B2B (face à son
     * plafond, le réglage b2bMax — 40 %), le click & collect et la livraison bureau, face au reste du réseau.
     */
    canauxHtml(C) {
      if (!C) { return '<div class="card sm mu">La structure du CA par canal se calcule : elle arrive à la prochaine lecture.</div>'; }
      if (!C.magasin) { return `<div class="card sm mu">Structure du CA par canal indisponible : ${esc(C.motif || 'rien à lire')}.</div>`; }
      const M = C.magasin, R = C.reste; const K = ['comptoir', 'b2b', 'cc', 'liv'];
      const NOM = { comptoir: 'Comptoir', b2b: 'B2B · clients pro', cc: 'Click & collect', liv: 'Livraison bureau' };
      const p1 = x => x == null ? '—' : String(x).replace('.', ',') + ' %';
      const b2b = M.parts.b2b; const max = C.b2bMax || 40;
      const st = C.b2bStatut === 'au-dessus' ? ['dn', 'au-dessus du plafond'] : C.b2bStatut === 'proche' ? ['or', 'proche du plafond'] : ['up', 'sous le plafond'];
      const barre = (P, lab) => `<div class="cx-l"><span class="xs mu">${lab}</span><div class="cx-b">${K.map(k => P.parts[k] > 0 ? `<i class="c-${k}" style="width:${P.parts[k]}%" title="${esc(NOM[k])} ${p1(P.parts[k])}"></i>` : '').join('')}</div></div>`;
      return `<div class="card kc">
        <div class="row" style="align-items:baseline;gap:10px"><span class="sm">Part B2B</span><span class="k ${st[0]}">${p1(b2b)}</span><span class="xs ${st[0]}">${st[1]} · ${p1(max)} au plus</span><span class="sp"></span>${R ? `<span class="xs mu">réseau ${p1(R.parts.b2b)}</span>` : ''}</div>
        <div class="cx-j"><i class="${st[0]}" style="width:${Math.min(100, b2b)}%"></i><em style="left:${max}%"></em></div>
        <div class="xs mu" style="margin:3px 0 10px">┃ le plafond de ${p1(max)} du CA</div>
        ${M.proSansTva > 0 ? `<div class="card sm ${M.b2bAvecSansTva > max ? 'alerte' : ''}" style="margin:0 0 10px;padding:8px 10px">Clients marqués pro sans TVA valide : <b>${eur(M.proSansTva)}</b>, comptés au comptoir. S’ils comptaient, la part B2B serait de <b class="${M.b2bAvecSansTva > max ? 'dn' : ''}">${p1(M.b2bAvecSansTva)}</b>${M.b2bAvecSansTva > max ? ' — au-dessus du plafond' : ''}. <span class="mu">Faire compléter leur fiche au panel.</span></div>` : ''}
        ${barre(M, 'Le magasin')}${R ? barre(R, 'Le reste du réseau · ' + R.magasins + ' magasin' + (R.magasins > 1 ? 's' : '')) : ''}
        <div class="cx-lg">${K.map(k => `<span><i class="c-${k}"></i>${esc(NOM[k])}</span>`).join('')}</div>
        <div class="ksc" style="margin-top:6px"><table class="tbl"><tr><th>Canal</th><th>CA</th><th>Part</th><th>Réseau</th><th>Écart</th></tr>${K.map(k => `<tr><td>${esc(NOM[k])}</td><td>${eur(M.ca[k])}</td><td><b class="${k === 'b2b' && b2b > max ? 'dn' : ''}">${p1(M.parts[k])}</b></td><td class="mu">${R ? p1(R.parts[k]) : '—'}</td><td>${R ? (k === 'b2b' ? this.ecartPill(Math.round((M.parts[k] - R.parts[k]) * 10) / 10, true).replace(/ec (up|dn)/, 'ec') : this.ecartPill(Math.round((M.parts[k] - R.parts[k]) * 10) / 10, true)) : ''}</td></tr>`).join('')}<tr><td><b>Total</b></td><td><b>${eur(M.total)}</b></td><td></td><td></td><td></td></tr></table></div>
        <div class="xs mu" style="margin-top:4px">Du ${fmtD(C.du)} au ${fmtD(C.au)}${C.joursLus < C.jours ? ' · ' + C.joursLus + ' jours de caisse lus sur ' + C.jours : ''} · ${esc(C.source || '')}</div></div>`;
    }
    planLigne(p, actions) {
      const st = ST_PLAN[p.statut] || [p.statut, ''];
      const photo = p.photo_id ? (this.D.photos || []).find(x => x.id === p.photo_id) : (this.D.photos || []).find(x => String(x.plan_id) === String(p.id) || (x.client_id && x.client_id === p.photo_client_id));
      return `<div class="card ${p.priorite === 'P0' && /^(ouvert|reprendre|escalade)$/.test(p.statut) ? 'alerte' : ''}">
        <div class="row"><span class="pill ${st[1]}">${st[0]}</span><span class="pill ${p.priorite}">${p.priorite}</span><span class="sp"></span><span class="xs ${p.retard ? 'dn' : 'mu'}">${p.retard ? 'retard ' + p.retard + ' j' : p.echeance ? 'délai ' + fmtD(p.echeance) : ''}${p.attente ? ' · à envoyer' : ''}</span></div>
        <b style="display:block;margin-top:6px">${esc(p.titre)}</b>
        <div class="sm mu">${esc(ASSIGNES[p.assigne] || p.assigne)} · ${esc(p.cree_par || '')} le ${fmtD(p.cree_le)}${p.detail ? ' · « ' + esc(p.detail) + ' »' : ''}</div>
        ${p.retour ? `<div class="sm" style="margin-top:4px">Retour : « ${esc(p.retour)} »</div>` : ''}
        ${p.escalade_motif ? `<div class="sm dn" style="margin-top:4px">Escalade : ${esc(p.escalade_motif)}</div>` : ''}
        ${this.campagneBloc(p)}
        ${photo ? `<div class="act"><button class="chip" data-a="voir" data-v="${esc(photo.client_id || photo.id)}">📷 Photo du ${fmtD(photo.prise_a)} ${fmtH(photo.prise_a)}</button></div>` : ''}
        ${actions || ''}
      </div>`;
    }

    /* --- écrans ---------------------------------------------------------------- */
    v_agenda() {
      const D = this.D; const t = auj();
      const lundi = lundiDe(t, this.sem);
      const vs = this.mesVisites().filter(v => v.statut !== 'annulee');
      const jrs = [];
      for (let i = 0; i < 7; i++) {
        const d = plusJours(lundi, i);
        const du = vs.filter(v => v.prevu_le === d).sort((a, b) => a.debut_h < b.debut_h ? -1 : 1);
        jrs.push(`<div class="jour ${d === t ? 'auj' : ''}"><div class="j"><b>${JOURS[(i + 1) % 7]} ${d.slice(8)}</b>${du.map(v => v.debut_h).join('<br>')}</div><div>${du.length ? du.map(v => this.carteVisite(v)).join('') : '<div class="libre">Libre</div>'}</div></div>`);
      }
      const aPlanifier = D.boutiques.filter(b => !b.prochaineVisite && (b.due || b.feu === 'rouge' || this.plansDe(b.id).some(p => p.statut === 'valide')));
      return this.hd(this.role === 'consultant' ? (this.consultantNom(this.moi) || 'Agenda') : 'Agenda des visites', 'semaine du ' + fmtD(lundi) + ' au ' + fmtD(plusJours(lundi, 6)))
        + `<div class="row" style="margin:0 0 8px"><button class="chip" data-a="sem" data-v="-1">‹ semaine</button><button class="chip ${this.sem === 0 ? 'on' : ''}" data-a="sem" data-v="0">aujourd’hui</button><button class="chip" data-a="sem" data-v="1">semaine ›</button><span class="sp"></span><button class="btn s p" data-a="go" data-v="planifier">+ Planifier</button></div>`
        + (this.role === 'consultant' && !this.moi ? '<div class="card sm">Dites qui vous êtes dans Réglages : l’agenda montrera vos visites.</div>' : '')
        + jrs.join('')
        + (aPlanifier.length ? '<div class="cap">À planifier</div>' + aPlanifier.map(b => `<div class="card"><div class="row">${this.feuDot(b)}<b>${esc(b.court)}</b><span class="sp"></span><span class="pill ${b.feu === 'rouge' ? 'P0' : 'st-att'}">${b.feu === 'rouge' ? 'ASAP' : esc(b.due || 'à revoir')}</span></div><div class="sm mu">${esc((b.motifs || []).slice(0, 2).join(' · ') || 'dernière visite ' + (b.derniereVisite ? fmtD(b.derniereVisite.le) : 'jamais'))}</div><div class="act"><button class="btn s p" data-a="go" data-v="planifier/${esc(b.id)}">📅 Planifier</button><button class="btn s" data-a="go" data-v="tb/${esc(b.id)}">Tableau de bord</button></div></div>`).join('') : '');
    }
    carteVisite(v) {
      const b = this.boutique(v.shop) || { court: v.shop, feu: 'vert', motifs: [] };
      const ouverts = this.plansDe(v.shop, true); const p0 = ouverts.find(p => p.priorite === 'P0');
      return `<div class="vis ${p0 ? 'card alerte' : ''}" style="margin:0 0 6px;padding:9px 11px"><div class="row">${this.feuDot(b)}<b>${esc(b.court)}</b><span class="sp"></span><span class="pill ${p0 ? 'P0' : ''}">${p0 ? 'prioritaire' : esc(ST_VISITE[v.statut] || v.statut)}</span></div>
        ${this.role !== 'consultant' || !this.moi ? `<div class="xs mu">${esc(v.consultantNom || '')}</div>` : ''}
        ${p0 ? `<div class="sm dn" style="margin-top:3px">🚨 P0 ${esc(p0.titre)}${p0.retard ? ' · retard ' + p0.retard + ' j' : ''}</div>` : ''}
        <div class="sm mu" style="margin-top:2px">${esc(this.typeNom(v.type))}${b.ca ? ' · CA ' + eur(b.ca.ca) + (b.ca.pct != null ? ' · ' + pct(b.ca.pct) : '') : ''}${b.google && b.google.note != null ? ' · Google ' + note1(b.google.note) : ''}${ouverts.length && !p0 ? ' · ' + ouverts.length + ' action(s)' : ''}</div>
        <div class="act"><button class="btn s p" data-a="go" data-v="${v.statut === 'terminee' ? 'historique/' + esc(v.shop) : v.statut === 'en_cours' ? 'controle/' + esc(v.id) : 'fiche/' + esc(v.id)}">${v.statut === 'terminee' ? 'Historique' : v.statut === 'en_cours' ? 'Reprendre le contrôle' : 'Ouvrir la fiche'}</button></div></div>`;
    }
    v_portfolio() {
      const D = this.D; const s = D.seuils || {};
      return this.hd(this.role === 'franchise' ? 'Ma boutique' : 'Les boutiques', D.boutiques.length + ' ouvertes · feu par boutique')
        + D.boutiques.map(b => `<div class="card"><div class="row">${this.feuDot(b)}<b style="font-size:16px">${esc(b.court)}</b><span class="sp"></span><span class="xs mu">${b.prochaineVisite ? 'visite ' + fmtDJ(b.prochaineVisite.le) + ' ' + b.prochaineVisite.h : b.derniereVisite ? 'vue le ' + fmtD(b.derniereVisite.le) : 'jamais visitée'}</span></div>
          <div class="sm mu" style="margin-top:4px">${esc((b.motifs || []).slice(0, 3).join(' · ') || ((b.ca ? 'CA ' + pct(b.ca.pct) : '') + (b.google ? ' · Google ' + note1(b.google.note) : '') + ' · aucune alerte'))}</div>
          <div class="row" style="margin-top:8px;gap:6px"><span class="pill api">CA · Google</span><span class="pill loc">Actions · plano</span><span class="sp"></span><button class="btn s" data-a="go" data-v="tb/${esc(b.id)}">Tableau de bord</button></div></div>`).join('')
        + `<div class="cap">Comment le feu se calcule</div><div class="card sm mu">🔴 P0 ouvert plus de ${s.p0Jours} j, Google sous ${note1((s.googleCible || 4.5) - (s.googleRouge || 0.5))}, planogramme sous ${s.planoRouge} %, rubrique MSP sous ${s.mspAlerte}/20<br>🟡 P0 ou P1 ouvert, Google sous ${note1(s.googleCible)}, planogramme sous ${s.planoOrange} %, visite due${s.caSeul ? ', CA sous −' + s.caOrange + ' %' : ''}<br>🟢 sinon. ${s.caSeul ? '' : 'Le CA colore la carte, il ne déclenche pas le feu seul.'}</div>`;
    }
    v_planifier() {
      const D = this.D; const f = this.form; const shop = f.shop || this.p || (D.boutiques[0] || {}).id;
      const t = this.typeDe(f.type); const types = (D.types || []).filter(x => x.actif !== false);
      // Les consultants du profil que le type demande passent en tête ; les autres restent possibles.
      const cs = (D.consultants || []).slice().sort((a, b) => (t && t.profil ? ((b.profil === t.profil) - (a.profil === t.profil)) : 0));
      const cons = this.role === 'consultant' && this.moi ? this.moi : (f.consultant || this.consultantParDefaut(t));
      const cl = t ? (t.dynamique === 'plans' ? 'checklist : les plans d’action ouverts' : t.dynamique === 'ko' ? 'checklist : les points non conformes de la dernière visite' : 'checklist ' + t.points + ' point' + (t.points > 1 ? 's' : '')) : '';
      return this.hd('Planifier une visite', '', this.role === 'franchise' ? 'plans' : 'agenda')
        + `<div class="card">
          <div class="champ"><label>Boutique</label><select data-c="form|shop">${D.boutiques.map(b => `<option value="${esc(b.id)}" ${String(b.id) === String(shop) ? 'selected' : ''}>${esc(b.court)}</option>`).join('')}</select></div>
          ${types.length ? `<div class="champ"><label>Type de visite</label><select data-c="form|type">${types.map(x => `<option value="${esc(x.code)}" ${(f.type || 'reguliere') === x.code ? 'selected' : ''}>${esc(x.nom)}</option>`).join('')}</select>${t ? `<div class="xs mu" style="margin-top:4px">${t.duree} min · ${cl} · ${t.nbTaches} tâche${t.nbTaches > 1 ? 's' : ''} à faire${t.profilNom ? ' · profil ' + esc(t.profilNom) : ''}</div>` : ''}</div>` : ''}
          ${this.role === 'consultant' && this.moi ? '' : `<div class="champ"><label>Consultant</label><select data-c="form|consultant"><option value="">—</option>${cs.map(c => `<option value="${esc(c.id)}" ${c.id === cons ? 'selected' : ''}>${esc(c.nom)}${c.profilNom ? ' · ' + esc(c.profilNom) : ''}</option>`).join('')}</select></div>`}
          <div class="l2"><div class="champ"><label>Date</label><input type="date" data-f="form|prevu_le" value="${esc(f.prevu_le || plusJours(auj(), 1))}"></div><div class="champ"><label>Heure</label><input type="time" data-f="form|debut_h" value="${esc(f.debut_h || '09:00')}"></div></div>
          <div class="l2"><div class="champ"><label>Durée (min)</label><input type="number" data-f="form|duree_min" value="${esc(f.duree_min || (t ? t.duree : 90))}"></div><div class="champ"><label>Motif</label><select data-c="form|motif">${Object.keys(MOTIFS).map(m => `<option value="${m}" ${(f.motif || 'reguliere') === m ? 'selected' : ''}>${MOTIFS[m]}</option>`).join('')}</select></div></div>
          <div class="btns"><button class="btn p w" data-a="planifier">📅 Planifier</button></div></div>`;
    }
    v_fiche() {
      const v = this.visite(this.p); if (!v) { return this.hd('Visite introuvable', '', 'agenda'); }
      const b = this.boutique(v.shop) || { court: v.shop, motifs: [] };
      const ouverts = this.plansDe(v.shop, true).slice(0, 5);
      const g = b.google; const msp = b.msp; const f = this.form;
      const enCours = v.statut === 'en_cours'; const finie = v.statut === 'terminee';
      return this.hd('Visite — ' + b.court, this.typeNom(v.type) + ' · ' + fmtDJ(v.prevu_le) + ' · ' + v.debut_h + ' · ' + v.duree_min + ' min · ' + (ST_VISITE[v.statut] || v.statut), 'agenda', ouverts.some(p => p.priorite === 'P0') ? '<span class="pill P0">prioritaire</span>' : this.feuDot(b))
        + `<div class="card"><div class="row"><span>📍</span><div><b>${esc(b.nom)}</b><div class="sm mu">${esc(b.ville || '')}${b.fr ? ' · ' + esc(b.fr) : ''}</div></div><span class="sp"></span><a class="btn s" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(b.nom + ' ' + (b.ville || ''))}">GPS</a></div>
          <div class="row" style="margin-top:8px"><span>🕐</span><div class="sm">${esc(v.consultantNom || 'consultant à choisir')} · ${v.debut_h} · ${v.duree_min} min</div><span class="sp"></span>${finie ? '' : `<button class="btn s" data-a="form" data-v="reprog|1">Reprogrammer</button>`}</div>
          ${f.reprog ? `<div class="l2" style="margin-top:8px"><div class="champ"><label>Date</label><input type="date" data-f="form|prevu_le" value="${esc(f.prevu_le || v.prevu_le)}"></div><div class="champ"><label>Heure</label><input type="time" data-f="form|debut_h" value="${esc(f.debut_h || v.debut_h)}"></div></div><div class="btns"><button class="btn s p" data-a="reprog" data-v="${esc(v.id)}">Enregistrer</button><button class="btn s" data-a="annuler" data-v="${esc(v.id)}">Annuler la visite</button></div>` : ''}</div>`
        + this.tachesVisiteHtml(v)
        + '<div class="cap">Tableau de bord — aperçu</div>' + this.kpis(b)
        + (ouverts.length ? '<div class="cap">Priorités du jour</div><div class="card">' + ouverts.map(p => `<div class="plan ${p.priorite}b"><div class="row"><span class="pill ${p.priorite}">${p.priorite}</span><b>${esc(p.titre)}</b></div><div class="sm mu">${esc(ASSIGNES[p.assigne] || '')} · ouvert le ${fmtD(p.cree_le)}${p.retard ? ' · délai dépassé de ' + p.retard + ' j' : p.echeance ? ' · délai ' + fmtD(p.echeance) : ''}${p.statut === 'reprendre' ? ' · à reprendre' : ''}${p.photo_id ? '' : ' · pas de photo'}</div></div>`).join('') + '</div>' : '')
        + `<div class="card sm"><b>Avant d’entrer</b><div class="mu">${g && g.faibles ? 'Google : ' + g.faibles + ' avis ≤ 2/5 sur 30 jours. ' : ''}${msp ? 'MSP ' + esc(msp.mois) + ' : ' + note1(msp.total) + '/20' + (Object.keys(msp.rubriques || {}).filter(k => msp.rubriques[k] < (this.D.seuils.mspAlerte || 12)).map(k => ' — ' + k + ' ' + msp.rubriques[k]).join('')) + '. ' : ''}${(b.motifs || []).length ? esc(b.motifs.slice(0, 3).join(' · ')) + '.' : 'Aucune alerte en cours.'}</div></div>`
        + (finie ? `<div class="btns"><button class="btn w" data-a="go" data-v="historique/${esc(v.shop)}">Historique</button><button class="btn w" data-a="go" data-v="review/${esc(v.id)}">Relire la review</button></div>`
          : `<div class="btns"><button class="btn p w" data-a="demarrer" data-v="${esc(v.id)}">${enCours ? '▶ Reprendre la visite' : '▶ Démarrer la visite'}</button></div>${v.statut === 'planifiee' ? `<div class="btns"><button class="btn w" data-a="confirmer" data-v="${esc(v.id)}">✓ Confirmer</button><button class="btn w" data-a="go" data-v="tb/${esc(v.shop)}">Tableau de bord</button></div>` : `<div class="btns"><button class="btn w" data-a="go" data-v="tb/${esc(v.shop)}">Tableau de bord</button></div>`}`);
    }
    /** Les trois photos du jour : prises, ou à prendre. */
    photosJourHtml(shop, vEnCours, genres) {
      const pj = this.photosJour(shop); const t = auj();
      const jour = GENRES_JOUR.filter(([g]) => !genres || genres.includes(g)).map(([g, l]) => { const p = pj.find(x => x.genre === g && String(x.prise_a).slice(0, 10) === t); return p ? `<div class="ph" style="background-image:url('${esc(this.photoSrc(p))}')" data-a="voir" data-v="${esc(p.client_id || p.id)}"><small>${fmtH(p.prise_a)}</small>${p.attente ? '<span class="att">à envoyer</span>' : ''}</div>` : `<div class="ph vide" data-a="photo" data-v="${g}|${esc(shop)}${vEnCours ? '|' + vEnCours.id : ''}">📷 ${l}</div>`; }).join('');
      return `<div class="cap">${genres && genres.length === 1 ? 'La photo du jour' : 'Les photos du jour'}</div><div class="phs">${jour}</div><div class="xs mu" style="margin:4px 2px">Horodatée, position si consentie, EXIF retiré à la réduction · ${pj.length ? pj.length + ' photos sur 90 j' : 'première photo'}</div>`;
    }
    /** Ce qui cloche dans la boutique, d'après les actions, le planogramme, le comptoir, Google et le panel. */
    alertesDe(shop, b, B) {
      const alertes = [];
      this.plansDe(shop, true).forEach(p => alertes.push([p.priorite === 'P0' ? 'rouge' : 'orange', p.priorite + ' ' + p.titre + (p.retard ? ' — retard ' + p.retard + ' j' : ''), (ASSIGNES[p.assigne] || '') + (p.photo_id ? '' : ' · pas de photo')]));
      if (b.plano && b.plano.pct < (this.D.seuils.planoOrange || 80)) { alertes.push([b.plano.pct < (this.D.seuils.planoRouge || 60) ? 'rouge' : 'orange', 'Planogramme ' + b.plano.pct + ' %', 'relevé du ' + fmtD(b.plano.le) + (b.plano.ruptures ? ' · ' + b.plano.ruptures + ' rupture(s)' : '')]); }
      const cf = this.C[shop];
      if (cf && cf.assortiment && cf.assortiment.manquantes) {
        const as = cf.assortiment;
        alertes.push([as.pct != null && as.pct < 75 ? 'rouge' : 'orange',
          as.manquantes + ' référence(s) obligatoire(s) sans une seule vente sur ' + as.jours + ' j (au ' + fmtD(as.au) + ')',
          (as.liste || []).slice(0, 4).map(x => x.nom + (x.auComptoir ? '' : ' (pas de place au comptoir)')).join(' · ')]);
      }
      if (cf && cf.planogramme && cf.planogramme.obligatoiresSansPlace) {
        alertes.push(['orange', cf.planogramme.obligatoiresSansPlace + ' obligatoire(s) sans place au comptoir',
          (cf.planogramme.sansPlace || []).slice(0, 4).map(x => x.nom).join(' · ')]);
      }
      if (b.google && b.google.faibles >= 1) { alertes.push([b.google.faibles >= 2 ? 'orange' : 'vert', 'Google — ' + b.google.faibles + ' avis ≤ 2/5 sur 30 j', (b.google.derniers || []).filter(d => d.note <= 2).map(d => d.extrait).join(' · ')]); }
      if (B && B.nc && !B.nc.indispo && B.nc.ouvertes) { alertes.push(['orange', B.nc.ouvertes + ' non-conformité(s) du panel non corrigée(s) sur 30 j', B.nc.liste.filter(n => !n.corrigee).slice(0, 3).map(n => n.tache + ' (' + fmtD(n.jour) + ')').join(' · ')]); }
      return alertes;
    }
    alertesHtml(alertes, panelLu) {
      return `<div class="cap">Alertes ${panelLu ? '' : '<span class="xs mu">· panel en lecture…</span>'}</div><div class="card">${alertes.length ? alertes.map(a => `<div class="al"><span class="feu ${a[0]}" style="margin-top:4px"></span><div><b>${esc(a[1])}</b><div class="xs mu">${esc(a[2])}</div></div></div>`).join('') : '<div class="sm mu">Aucune alerte.</div>'}</div>`;
    }
    v_tb() {
      const shop = this.p || this.shop; const b = this.boutique(shop); if (!b) { return this.hd('Boutique introuvable', '', 'portfolio'); }
      const B = this.B[shop]; const vEnCours = b.visiteEnCours ? this.visite(b.visiteEnCours) : null;
      const t = auj();
      const alertes = this.alertesDe(shop, b, B);
      return this.hd(b.court, (vEnCours ? 'visite en cours depuis ' + fmtH(vEnCours.commence_a) + ' · ' : '') + fmtDJ(t), this.role === 'franchise' ? null : 'portfolio', vEnCours ? '<span class="pill" style="background:#F7E4E6;color:#C0182B">● en visite</span>' : this.feuDot(b))
        + this.photosJourHtml(shop, vEnCours)
        + '<div class="cap">4 chiffres clés</div>' + this.kpis(b)
        + this.santeHtml(shop)
        + this.alertesHtml(alertes, !!B)
        + (this.role === 'franchise' ? `<div class="btns"><button class="btn p w" data-a="go" data-v="plans">Mon plan d’action</button><button class="btn w" data-a="go" data-v="historique/${esc(shop)}">Historique</button></div>`
          : `<div class="btns">${vEnCours ? `<button class="btn p w" data-a="go" data-v="controle/${esc(vEnCours.id)}">▶ Contrôle guidé</button>` : `<button class="btn p w" data-a="go" data-v="planifier/${esc(shop)}">📅 Planifier</button>`}<button class="btn w" data-a="go" data-v="historique/${esc(shop)}">Historique 3 mois</button></div>
             <div class="btns"><button class="btn w" data-a="go" data-v="msp/${esc(shop)}">MSP et équipe</button>${vEnCours ? '' : `<button class="btn w" data-a="demarrer-ici" data-v="${esc(shop)}">▶ Visite non planifiée</button>`}</div>`);
    }
    /** Un module de la checklist : ses points, leurs notes, photos, causes et remarques. */
    /** La langue de la checklist d'une visite : choisie d'un toucher, sinon celle du magasin (fr, nl). */
    lngDe(v) {
      const k = v ? String(v.id) : '';
      if (this.lngV && this.lngV[k]) { return this.lngV[k]; }
      try { const x = localStorage.getItem('vi.lng.' + k); if (x === 'fr' || x === 'nl') { return x; } } catch (e) { /* privé */ }
      return v && (this.D.langues || {})[v.shop] === 'nl' ? 'nl' : 'fr';
    }
    /** La bascule FR | NL de la checklist, en tête d'une étape de module. */
    lngTog(v) {
      const l = this.lngDe(v);
      return `<span class="lng">${['fr', 'nl'].map(x => `<button data-a="lng" data-v="${esc(v.id)}|${x}" class="${l === x ? 'on' : ''}">${x.toUpperCase()}</button>`).join('')}</span>`;
    }
    /** La définition d'une question, pour lire ses choix et ses bornes au moment d'y répondre. */
    ptDef(vid, module, ref) {
      const vis = this.visite(vid);
      const m = ((vis ? this.checklistDe(vis) : null) || this.D.checklist || []).find(x => x.id === module);
      return (m ? m.points.find(x => x.ref === ref) : null) || {};
    }
    /** La réponse lue : le libellé des choix, le nombre et son unité, le texte. */
    reponseTxt(pt, r, l) {
      if (r == null || r === '' || (Array.isArray(r) && !r.length)) { return ''; }
      const t = typeQ(pt);
      if (t === 'choix' || t === 'multi') { return [].concat(r).map(code => { const c = (pt.choix || []).find(x => x.code === code); return c ? choixDe(c, l) : code; }).join(', '); }
      if (t === 'nombre') { return nbTxt(r) + (pt.unite ? ' ' + pt.unite : ''); }
      return String(r);
    }
    /** L'état que donne une réponse : un choix marqué écart, un nombre hors des bornes ; sinon conforme. */
    etatDeReponse(pt, r) {
      const t = typeQ(pt);
      if (r == null || r === '' || (Array.isArray(r) && !r.length)) { return ''; }
      if (t === 'choix' || t === 'multi') { return [].concat(r).some(code => ((pt.choix || []).find(x => x.code === code) || {}).ko) ? 'ko' : 'ok'; }
      if (t === 'nombre') { const n = Number(r); return (pt.min != null && n < pt.min) || (pt.max != null && n > pt.max) ? 'ko' : 'ok'; }
      return 'ok';
    }
    /** Les boutons d'une question selon son type : [ce qui va sur la ligne du libellé, ce qui va dessous]. */
    reponseHtml(pt, p, k, l, tab) {
      const T = LNG[l]; const t = typeQ(pt);
      const tri = (opts, cls) => `<span class="${cls || 'tri'}">${opts.map(([e, lab]) => `<button data-a="etat-set" data-v="${k}|${e}" class="${p.etat === e ? e : ''}">${lab}</button>`).join('')}</span>`;
      const notes = `<span class="notes">${[1, 2, 3, 4, 5].map(n => `<button data-a="note-q" data-v="${k}|${n}" class="${p.note === n ? (n <= 2 ? 'bad' : 'on') : ''}">${n}</button>`).join('')}</span>`;
      if (t === 'ouinon') { return [tri([['ok', T.oui], ['ko', T.non]]), '']; }
      if (t === 'note') { return [notes, '']; }
      if (t === 'nombre') {
        const bornes = pt.min != null || pt.max != null ? `<span class="xs mu">${T.bornes} ${pt.min != null && pt.max != null ? nbTxt(pt.min) + ' – ' + nbTxt(pt.max) : (pt.min != null ? '≥ ' + nbTxt(pt.min) : '≤ ' + nbTxt(pt.max))}${pt.unite ? ' ' + esc(pt.unite) : ''}</span>` : '';
        return [`<span class="rp-nb"><input type="number" step="any" inputmode="decimal" data-f="rep-nb|${k}" value="${p.reponse != null ? esc(p.reponse) : ''}">${pt.unite ? `<span>${esc(pt.unite)}</span>` : ''}</span>${bornes}`, ''];
      }
      if (t === 'choix' || t === 'multi') {
        const pris = [].concat(p.reponse == null ? [] : p.reponse);
        return ['', `<div class="rp-ch${t === 'multi' ? ' multi' : ''}">${(pt.choix || []).map(c => { const on = pris.indexOf(c.code) >= 0;
          return `<button data-a="${t === 'multi' ? 'rep-mu' : 'rep-ch'}" data-v="${k}|${esc(c.code)}" class="${on ? (c.ko ? 'on ko' : 'on') : ''}">${t === 'multi' ? `<i>${on ? '✓' : ''}</i>` : ''}${esc(choixDe(c, l))}</button>`; }).join('')}</div>${t === 'multi' ? `<div class="xs mu" style="margin-top:3px">${T.multi}</div>` : ''}`];
      }
      if (t === 'texte') { return ['', `<textarea class="rp-tx" data-f="rep-tx|${k}" rows="2" placeholder="${T.rep}">${esc(p.reponse || '')}</textarea>`]; }
      // OK / Écart / N/A, et le pourcentage du planogramme : comme avant.
      return [(pt.pct ? `<input type="number" data-f="pct|${k}" value="${p.valeur != null ? p.valeur : ''}" placeholder="%"${tab ? '' : ' style="width:64px;padding:5px 7px"'}>` : '') + (tab ? tri([['ok', T.ok], ['ko', T.ko], ['na', T.na]]) : (pt.pct ? '' : notes.replace(/note-q/g, 'note'))), ''];
    }
    /** Un module de la checklist : ses points, leurs réponses, photos, causes et remarques. */
    moduleHtml(v, m, pts, photos, cnt, sansTitre) {
      const b = this.boutique(v.shop) || { court: v.shop };
      const causes = this.D.causes || {};
      const l = this.lngDe(v); const T = LNG[l];

        const msp = m.id === 'msp' ? b.msp : null;
        const avant = m.leviers ? this.leviersHtml(v.shop) : m.obligatoires && !m.points.some(pt => pt.oblig) ? this.obligatoiresHtml(v.shop) : '';
        return `<div class="mod">${sansTitre ? (m.bureau ? '<div class="xs mu" style="margin:0 2px 6px">Les points que le bureau a ajoutés en préparant la visite.</div>' : '') : `<div class="th"><b>${esc(nomDe(m, l))}</b>${m.bureau ? '<span class="pill bur">bureau</span>' : this.src(m.id === 'planogramme' || m.id === 'assortiment' ? 'mix' : 'local')}<span class="sp"></span>${this.lngTog(v)}<span class="xs mu">${m.points.length} ${T.points}</span></div>`}
          ${avant}${msp ? `<div class="card" style="margin-bottom:6px"><div class="row"><b>${note1(msp.total)} / 20</b><span class="sp"></span><span class="xs mu">MSP ${esc(msp.mois)} · ${esc(msp.par)}</span></div><div class="sm" style="margin-top:4px">${Object.keys(msp.rubriques || {}).map(k => (msp.rubriques[k] < (this.D.seuils.mspAlerte || 12) ? '🔴 ' : msp.rubriques[k] < 16 ? '🟡 ' : '🟢 ') + esc(k) + ' ' + msp.rubriques[k]).join(' · ')}</div>${msp.commentaires ? `<div class="xs mu" style="margin-top:4px">« ${esc(msp.commentaires)} »</div>` : ''}${msp.fichier ? `<div class="act"><a class="chip" target="_blank" rel="noopener" href="${esc((this.o.racine || '') + msp.fichier)}">📄 Rapport PDF</a></div>` : ''}</div>` : m.id === 'msp' ? '<div class="sm mu" style="margin:0 2px 6px">Pas de rapport mystery shopper pour cette boutique (à saisir dans MSP et équipe).</div>' : ''}
          ${this.obligToggleHtml(v, m, pts, cnt)}${m.points.filter(pt => !pt.oblig).map(pt => {
            cnt.total++; const p = pts[pt.ref] || {}; if (p.etat || p.note != null || p.valeur != null) { cnt.faits++; }
            const phs = photos.filter(x => x.ref === pt.ref);
            const k = `${esc(v.id)}|${esc(pt.ref)}|${m.id}`;
            const tab = !!this.o.tablette; const t = typeQ(pt);
            const [ligne, dessous] = this.reponseHtml(pt, p, k, l, tab);
            const aide = libDe({ libelle: pt.aide, libelle_nl: pt.aide_nl }, l);
            const lib = `<b${tab ? '' : ' class="sm"'} style="flex:1">${esc(libDe(pt, l))}${aide ? `<small class="aide">${esc(aide)}</small>` : ''}</b>`;
            const photoBtn = pt.photo || phs.length ? `<button class="chip" data-a="photo" data-v="point|${esc(v.shop)}|${esc(v.id)}|${esc(pt.ref)}">📷 ${phs.length ? phs.length + ' ' + (phs.length > 1 ? T.photos : T.photo.toLowerCase()) : T.photo}</button>` : '';
            const act = `<div class="act">${photoBtn}${phs.map(x => `<button class="chip" data-a="voir" data-v="${esc(x.client_id || x.id)}">${fmtH(x.prise_a)}${x.attente ? ' ⏳' : ''}</button>`).join('')}
              ${pt.pct ? Object.keys(causes).map(c => `<button class="chip ${(p.causes || []).includes(c) ? 'on' : ''}" data-a="cause" data-v="${k}|${c}">${esc(causes[c])}</button>`).join('') : ''}
              ${t === 'texte' ? '' : `<input type="text" data-f="comm|${k}" value="${esc(p.commentaire || '')}" placeholder="${T.rem}" style="flex:1;${tab ? 'min-width:180px;padding:6px 10px' : 'min-width:120px;padding:5px 8px;font-size:12px'}">`}</div>`;
            if (tab) { return `<div class="pt t ${p.etat === 'ko' ? 'ko' : ''}"><div class="l1">${lib}${ligne}</div>${dessous}${act}</div>`; }
            // Sur le téléphone, OK / Écart / N/A garde sa case à toucher en tête de ligne.
            const cb = t === 'etat' || t === 'pct' ? `<button class="cb ${p.etat || ''}" data-a="etat" data-v="${k}">${p.etat === 'ok' ? '✓' : p.etat === 'ko' ? '!' : p.etat === 'na' ? '–' : ''}</button>` : '';
            return `<div class="pt ${p.etat === 'ko' ? 'ko' : ''}"><div class="l1">${cb}${lib}${ligne}</div>${dessous}${act}</div>`;
          }).join('')}</div>`;
          }
    v_checklist() {
      const v = this.visite(this.p); if (!v) { return this.hd('Visite introuvable', '', 'agenda'); }
      const b = this.boutique(v.shop) || { court: v.shop }; const pts = this.pointsDe(v.id); const photos = this.photosDe(v.id);
      const cl = this.checklistDe(v); const causes = this.D.causes || {};
      let cnt = { total: 0, faits: 0 };
      const mods = cl.map(m => this.moduleHtml(v, m, pts, photos, cnt, false)).join('');
      const total = cnt.total, faits = cnt.faits;
      return this.hd('Checklist — ' + b.court, fmtDJ(v.prevu_le) + (v.commence_a ? ' · depuis ' + fmtH(v.commence_a) : ''), 'fiche/' + v.id, `<button class="chip" data-a="go" data-v="controle/${esc(v.id)}">Vue guidée</button> <span class="pill">${faits} / ${total}</span>`)
        + this.carteConformite(v.shop)
        + mods
        + `<div class="btns" style="margin-top:16px"><button class="btn p w" data-a="go" data-v="review/${esc(v.id)}">Review et plan d’action ›</button></div><div class="btns"><button class="btn w" data-a="go" data-v="tb/${esc(v.shop)}">Tableau de bord</button></div>`;
    }
    v_review() {
      const v = this.visite(this.p); if (!v) { return this.hd('Visite introuvable', '', 'agenda'); }
      const b = this.boutique(v.shop) || { court: v.shop }; const f = this.form; const finie = v.statut === 'terminee';
      const ecarts = this.ecartsDe(v);
      this.reviewInit(v, finie, ecarts);
      const dejaPlans = this.plansDe(v.shop).filter(p => String(p.visite_id) === String(v.id));
      const attente = this.file.filter(o => o.apres === 'photo').length;
      return this.hd('Review — ' + b.court, 'visite du ' + fmtDJ(v.prevu_le) + (v.commence_a ? ' · ' + fmtH(v.commence_a) + (v.termine_a ? ' – ' + fmtH(v.termine_a) : '') : ''), 'checklist/' + v.id)
        + `<div class="cap">Écarts relevés</div><div class="card">${ecarts.length ? ecarts.map(e => `<div class="plan ${e.grave ? 'P0b' : 'P1b'}"><div class="row"><span class="feu ${e.grave ? 'rouge' : 'orange'}"></span><b>${esc(e.titre)}</b></div><div class="sm mu">${esc(e.detail)}</div></div>`).join('') : '<div class="sm mu">Aucun écart dans la checklist.</div>'}</div>`
        + (finie ? `${this.vuSurPlace(v)}<div class="cap">Plan d’action de cette visite</div>${dejaPlans.length ? dejaPlans.map(p => this.planLigne(p)).join('') : '<div class="card sm mu">Aucune action.</div>'}`
          : `${this.planFormHtml(v, f)}${this.reviewFormHtml(v, b, f)}            <div class="btns"><button class="btn p w" data-a="terminer" data-v="${esc(v.id)}">💾 Terminer · le brouillon part au bureau</button></div>`)
        + (attente ? `<div class="btns"><button class="btn w" data-a="sync">📤 Synchroniser (${attente} photo${attente > 1 ? 's' : ''} en attente)</button></div>` : '');
    }
    /** Le brouillon de la review : actions proposées d'après les écarts, champs relus depuis la visite. */
    reviewInit(v, finie, ecarts) {
      const f = this.form;
      if (f.pa) { return; }
      f.pa = finie ? [] : (ecarts || this.ecartsDe(v)).map(e => ({ cid: uuid(), ref: e.ref, titre: e.titre, detail: e.detail, priorite: e.grave ? 'P0' : 'P1', assigne: 'franchise', echeance: plusJours(auj(), e.grave ? 1 : 3), garde: true }));
      if (!finie && v.budgetCa) { this.vbPistesDansPlan(v.budgetCa); }
      f.positif = v.positif || ''; f.sentiment = v.sentiment || 0; f.meteo = v.meteo || 0; f.notes = v.notes || ''; f.execution = v.execution || ''; f.clients = v.clients || ''; f.causes = (v.causes || []).slice(); f.diagnostic = v.diagnostic || ''; f.reco = v.reco || '';
    }
    /** La météo du franchisé, en fin de contrôle : cinq temps, un toucher. */
    meteoHtml(f) {
      return `<div class="cap">Météo du franchisé</div><div class="card"><div class="meteo">${METEO.map(([n, ic, l, aide]) => `<button data-a="form" data-v="meteo|${n}" class="${f.meteo === n ? 'on' : ''}"><span class="i">${ic}</span><b>${esc(l)}</b><small>${esc(aide)}</small></button>`).join('')}</div></div>`;
    }
    /** Le plan d'action en cours de rédaction. */
    planFormHtml(v, f) {
      // Une action peut servir une campagne : le plan du franchisé montre alors
      // ses clients face au N-1 et à l'objectif. Campagnes en cours ou à venir.
      const camps = (this.campagnes(v.shop) || []).filter(c => c.statut !== 'close');
      return `<div class="cap">Plan d’action</div><div class="card">${f.pa.map((a, i) => `<div class="plan ${a.priorite}b" style="padding-bottom:6px"><input type="text" data-f="pa|${i}|titre" value="${esc(a.titre)}" placeholder="Action…" style="font-weight:600"><input type="text" data-f="pa|${i}|detail" value="${esc(a.detail || '')}" placeholder="Détail pour le franchisé…" style="margin-top:4px;font-size:12px">
              <div class="act">${['P0', 'P1', 'P2'].map(p => `<button class="chip ${a.priorite === p ? 'on' : ''}" data-a="pa" data-v="${i}|priorite|${p}">${p}</button>`).join('')}<span style="width:6px"></span>${Object.keys(ASSIGNES).map(k => `<button class="chip ${a.assigne === k ? 'on' : ''}" data-a="pa" data-v="${i}|assigne|${k}">${ASSIGNES[k]}</button>`).join('')}</div>
              <div class="act">${[[1, '24 h'], [2, '48 h'], [7, '1 sem.'], [14, '2 sem.']].map(([n, l]) => `<button class="chip ${a.echeance === plusJours(auj(), n) ? 'on' : ''}" data-a="pa" data-v="${i}|echeance|${plusJours(auj(), n)}">${l}</button>`).join('')}<input type="date" data-f="pa|${i}|echeance" value="${esc(a.echeance || '')}" style="width:150px;padding:5px 7px"><span class="sp"></span><button class="chip ko" data-a="pa-del" data-v="${i}">retirer</button></div>
              ${camps.length ? `<div class="act"><span class="xs mu">Campagne</span><button class="chip ${!a.campagne_id || a.campagne_id === '0' ? 'on' : ''}" data-a="pa" data-v="${i}|campagne_id|0">aucune</button>${camps.map(c => `<button class="chip ${String(a.campagne_id || '') === String(c.id) ? 'on' : ''}" data-a="pa" data-v="${i}|campagne_id|${c.id}">📣 ${esc(c.court)} · ${esc(this.campagneEtat(c)[0])}</button>`).join('')}</div>` : ''}</div>`).join('')}
            <div class="act"><button class="chip" data-a="pa-add">+ Ajouter une action</button></div></div>`;
    }
    /** Vu sur place, le vrai problème, la recommandation, l'observé positif. */
    reviewFormHtml(v, b, f) {
      const CD = this.D.causesDiag || {}; const baisse = b.feu === 'rouge' || (b.ca && b.ca.pct != null && b.ca.pct < 0);
      return `            <div class="cap">Vu sur place <button class="chip" data-a="drop" data-v="pourquoi" style="margin-left:6px">${this.ouvert.pourquoi ? 'pourquoi ▴' : 'pourquoi ▾'}</button></div>
            ${this.ouvert.pourquoi ? `<div class="card sm mu"><b>Voir la réalité.</b> Sur le papier ou en visio, c’est utile mais incomplet. Sur place, on voit comment ça tourne vraiment : l’énergie de l’équipe, l’exécution face au process, l’écart entre le protocole et ce qu’on fait. Le client sort-il satisfait ? Les standards sont-ils appliqués, ou raccourcis ?<br><br><b>Diagnostiquer le vrai problème.</b> Si une boutique perd du chiffre ou que la qualité se dégrade, de près on nomme le vrai coupable — production mal synchronisée, équipe démotivée, décor qui n’invite pas, prix mal positionnés. Pas la même analyse depuis le bureau.<br><br><b>Donner du crédit à la recommandation.</b> Quand on a vu, mesuré, touché, on peut dire : « voilà ce qui ne marche pas, et voilà pourquoi ». Les équipes écoutent mieux, et vous avez des données, pas une opinion.</div>` : ''}
            <div class="card">
              <div class="row"><span>Énergie de l’équipe</span><span class="sp"></span><span class="notes">${[1, 2, 3, 4, 5].map(n => `<button data-a="form" data-v="sentiment|${n}" class="${f.sentiment >= n ? 'on' : ''}">★</button>`).join('')}</span></div>
              <div class="sm" style="margin-top:8px">Exécution face au protocole</div><div class="act">${Object.keys(EXECUTION).map(k => `<button class="chip ${f.execution === k ? 'on' : ''}" data-a="form" data-v="execution|${k}">${EXECUTION[k]}</button>`).join('')}</div>
              <div class="sm" style="margin-top:8px">Les clients sortent…</div><div class="act">${Object.keys(CLIENTS).map(k => `<button class="chip ${f.clients === k ? 'on' : ''}" data-a="form" data-v="clients|${k}">${CLIENTS[k]}</button>`).join('')}</div>
              <textarea data-f="form|notes" placeholder="Ce que je vois tourner réellement — notes du consultant (historique)…" style="margin-top:8px">${esc(f.notes)}</textarea></div>
            <div class="cap">Le vrai problème ${baisse ? '<span class="pill P0">chiffre ou qualité en baisse</span>' : ''}</div><div class="card">
              <div class="sm mu">${baisse ? 'Le chiffre ou la qualité baisse : qui est le vrai coupable ?' : 'Si quelque chose cloche, nommez la cause — pas la même analyse depuis le bureau.'}</div>
              <div class="act">${Object.keys(CD).map(k => `<button class="chip ${f.causes.includes(k) ? 'on' : ''}" data-a="cause-diag" data-v="${k}">${esc(CD[k])}</button>`).join('')}</div>
              <textarea data-f="form|diagnostic" placeholder="Pourquoi — ce que j’ai vu, mesuré, touché…" style="margin-top:8px">${esc(f.diagnostic)}</textarea></div>
            <div class="cap">Recommandation</div><div class="card"><div class="sm mu">« Voilà ce qui ne marche pas et voilà pourquoi. » Le franchisé la lit ; Sam la retrouve dans la synthèse.</div><textarea data-f="form|reco" placeholder="Ce qui ne marche pas, pourquoi, et ce qu’on fait…" style="margin-top:8px">${esc(f.reco)}</textarea></div>
            <div class="cap">Observé positif</div><div class="card"><textarea data-f="form|positif" placeholder="Ce qui va bien — dit au franchisé aussi">${esc(f.positif)}</textarea></div>`;
    }
    /** Le brouillon de la review posé au serveur (sans terminer la visite) : rien ne se perd entre deux écrans. */
    sauverReview(v) {
      const f = this.form; if (!f.pa) { return; }
      const champs = { sentiment: f.sentiment || null, positif: f.positif || '', notes: f.notes || '', execution: f.execution || '', clients: f.clients || '', causes: f.causes || [], diagnostic: f.diagnostic || '', reco: f.reco || '' };
      const change = Object.keys(champs).some(k => JSON.stringify(champs[k] || '') !== JSON.stringify(v[k] || (k === 'causes' ? [] : k === 'sentiment' ? null : '')));
      if (change) { this.majVisite(v.id, champs); }
    }
    /**
     * Le contrôle guidé : toute la visite en un seul écran, étape après étape —
     * photo du jour, chiffres et alertes, un module de checklist par étape, ce
     * qu'on a vu et la recommandation, le plan d'action et la fin de visite.
     * Chaque étape se replie une fois faite ; on peut rouvrir n'importe laquelle.
     */
    v_controle() {
      const v = this.visite(this.p); if (!v) { return this.hd('Visite introuvable', '', 'agenda'); }
      if (v.statut === 'terminee') { return this.v_review(); }
      const b = this.boutique(v.shop) || { court: v.shop, motifs: [] }; const f = this.form;
      this.reviewInit(v, false);
      const pts = this.pointsDe(v.id); const photos = this.photosDe(v.id); const t = auj();
      const B = this.B[v.shop];
      const etapes = [];
      const jourPris = g => this.photosJour(v.shop).some(x => x.genre === g && String(x.prise_a).slice(0, 10) === t);
      const mods = this.checklistDe(v).map(m => { const cnt = { total: 0, faits: 0 }; const html = this.moduleHtml(v, m, pts, photos, cnt, true);
        return { cle: 'mod:' + m.id, ic: m.id, court: nomDe(m, this.lngDe(v)), module: true, tps: this.tempsModule(m), ko: Object.keys(pts).filter(r => pts[r].etat === 'ko' && m.points.some(x => x.ref === r)).length, n: cnt, titre: nomDe(m, this.lngDe(v)), etat: cnt.faits + ' / ' + cnt.total, fait: cnt.total > 0 && cnt.faits >= cnt.total, html: () => html }; });
      const modsDe = tps => mods.filter(e => e.tps === tps);
      // 1. L'extérieur : la façade et l'arrière en photo, les points qui se regardent dehors.
      const nExt = ['jour_facade', 'jour_arriere'].filter(jourPris).length;
      etapes.push({ cle: 'photos_ext', tps: 'ext', ic: 'photo', court: 'Photos', titre: 'L’extérieur en photo', etat: nExt + ' / 2', fait: nExt >= 2, html: () => this.photosJourHtml(v.shop, v, ['jour_facade', 'jour_arriere']) });
      etapes.push(...modsDe('ext'));
      // 2. L'intérieur : la photo, l'expérience client, les modules de la checklist, les contrôles en photo du jour
      // (la bande du dashboard, la note rapide sous chaque photo ; faite quand plus rien n'attend une note).
      etapes.push({ cle: 'photos_int', tps: 'int', ic: 'photo', court: 'Photo', titre: 'L’intérieur en photo', etat: jourPris('jour_interieur') ? '1 / 1' : 'à prendre', fait: jourPris('jour_interieur'), html: () => this.photosJourHtml(v.shop, v, ['jour_interieur']) });
      etapes.push(...modsDe('int'));
      const cq = this.cqListe(v.shop); const Tc = this.T[v.shop];
      etapes.push({ cle: 'controles', tps: 'int', ic: 'controles', court: 'Contrôles du jour', titre: 'Les contrôles en photo du jour', etat: cq ? cq.avec + ' photo' + (cq.avec > 1 ? 's' : '') + ' / ' + cq.total + (cq.aNoter ? ' · ' + cq.aNoter + ' à noter' : '') : (Tc && Tc.erreur ? 'indisponible' : 'lecture…'),
        fait: !!cq && cq.aNoter === 0, html: () => this.controlesHtml(v.shop) });
      // 3. Le suivi de projet : le dossier du bureau (seulement s'il est arrivé), ses demandes, ce qui avait été décidé
      // aux visites précédentes — vérifié sur place —, la checklist du consultant lue dans le panel.
      const P = v.prep;
      if (P) { etapes.push({ tps: 'suivi', ic: 'dossier', court: 'Dossier', titre: 'Dossier du bureau', etat: P.points.length ? P.points.length + ' point' + (P.points.length > 1 ? 's' : '') + ' demandé' + (P.points.length > 1 ? 's' : '') : 'lu', fait: !!this.ouvert['dos:' + v.id], lu: 'dos:' + v.id, html: () => this.dossierHtml(v) }); }
      etapes.push(...modsDe('suivi'));
      if (!((this.typeDe(v.type) || {}).dynamique === 'plans')) {
        const ouverts = this.plansDe(v.shop, true); const nR = ouverts.filter(p => p.retard).length;
        etapes.push({ tps: 'suivi', ic: 'plan', court: 'Points décidés', titre: 'Les points décidés aux visites précédentes', etat: ouverts.length ? ouverts.length + ' ouvert' + (ouverts.length > 1 ? 's' : '') + (nR ? ' · ' + nR + ' en retard' : '') : 'aucun ouvert',
          ko: nR, fait: !ouverts.length || !!this.ouvert['pd:' + v.id], lu: 'pd:' + v.id, html: () => this.pointsDecidesHtml(v) });
      }
      if (this.role === 'consultant') { this.chargerCk(v.shop); const ck = this.ckEtat(); etapes.push({ cle: 'checklist', tps: 'suivi', ic: 'liste', court: 'Checklist', titre: 'Checklist du consultant', etat: ck.etat, fait: ck.fait, html: () => this.ckHtml(v) }); }
      // 4. L'analyse, assis avec le franchisé : les chiffres, les leviers et Google, le client mystère, les tâches des mois passés.
      const vbx = this.vbExige(v);
      etapes.push({ tps: 'analyse', ic: 'chiffres', court: 'KPI', chiffres: true, titre: 'KPI et santé du magasin', etat: vbx === 'manque' ? 'CA sous le budget' : 'à lire',
        fait: !!this.ouvert['lu:' + v.id], lu: 'lu:' + v.id, html: () => this.kpis(b) + this.budgetHtml(v) + this.santeHtml(v.shop) + this.carteConformite(v.shop) + this.alertesHtml(this.alertesDe(v.shop, b, B), !!B) });
      // Les six leviers et Google, puis les tâches des mois passés, puis le client mystère.
      etapes.push(...modsDe('analyse').filter(e => e.ic === 'leviers'));
      etapes.push({ tps: 'analyse', ic: 'controles', court: 'Tâches', titre: 'Les tâches des derniers mois', etat: 'à lire', fait: !!this.ouvert['tm:' + v.id], lu: 'tm:' + v.id, html: () => this.tachesMoisHtml(v.shop) });
      etapes.push(...modsDe('analyse').filter(e => e.ic !== 'leviers'));
      // 5. Le développement : le CA sous le budget (pourquoi, trois pistes : l'étape reste ouverte tant que ce n'est pas
      // noté), ce qu'on a vu et la recommandation, les points à travailler.
      if (vbx) { etapes.push({ cle: 'pistes', tps: 'dev', ic: 'dev', court: 'Pistes', pistes: true, titre: 'Le CA sous le budget : pourquoi et trois pistes', etat: vbx === 'manque' ? 'à noter' : 'notées', ko: vbx === 'manque' ? '!' : 0, fait: vbx === 'fait', html: () => this.vbPistesHtml(v) }); }
      const revueFaite = !!(f.reco && (f.execution || f.clients || (f.causes || []).length));
      etapes.push({ cle: 'constat', tps: 'dev', ic: 'constat', court: 'Constat', titre: 'Vu sur place et recommandation', etat: revueFaite ? 'rédigée' : f.reco || f.execution ? 'en cours' : 'à écrire', fait: revueFaite, revue: true, html: () => this.reviewFormHtml(v, b, f) });
      const npa = (f.pa || []).filter(a => a.titre && a.titre.trim()).length;
      etapes.push({ tps: 'dev', ic: 'plan', court: 'Points', titre: 'Points à travailler', etat: npa + ' proposé' + (npa > 1 ? 's' : ''), fait: !!this.ouvert['pa:' + v.id], lu: 'pa:' + v.id, html: () => '<div class="card sm mu">Proposés d’après les non-conformes et les pistes du franchisé. Ils partent chez lui avec le rapport, si le bureau les garde.</div>' + this.planFormHtml(v, f) });
      // 6. La météo du franchisé (le temps en un toucher, gardé sur la visite, puis le détail facultatif), la fin signée.
      const mt = meteoDe(f.meteo);
      etapes.push({ cle: 'meteo', tps: 'fin', ic: 'meteo', court: 'Météo', titre: 'Météo du franchisé', etat: mt ? mt[2] + (f.mt_ok ? ' · détail enregistré' : '') : (f.mt_ok ? 'détail enregistré' : 'interne'), fait: !!(mt || f.mt_ok), html: () => this.meteoHtml(f) + this.meteoDetailHtml(v) });
      etapes.push({ tps: 'fin', ic: 'fin', court: 'Fin', titre: 'Fin de visite', etat: f.sig && f.sigC ? 'signée des deux' : f.sig || f.sigC ? '1 signature sur 2' : 'signatures, envoi au bureau', fait: false, html: () => this.finHtml(v) });
      const ORD = this.tempsOrdre(); const rangT = k => ORD.findIndex(t => t[0] === k);
      etapes.sort((a, b) => rangT(a.tps) - rangT(b.tps));
      // Ce qui reste à remplir dans chaque étape qui peut l'être (26/10/2026) : « Suivant » refuse tant qu'il en reste
      // (réglage etapesBloquantes, étape par étape) ; les photos, la checklist et les contrôles du jour passent d'eux-mêmes à l'étape
      // suivante une fois remplis (réglage etapeAuto). Les étapes à lire, le constat, les pistes, la météo et la fin
      // ne passent jamais seuls ; les étapes à lire ne bloquent jamais.
      etapes.forEach(e => {
        if (e.fait || e.lu) { return; }
        if (e.module && e.n && e.n.total > 0) { const r = e.n.total - e.n.faits; e.manque = r + ' point' + (r > 1 ? 's' : '') + ' à remplir'; e.auto = true; }
        else if (e.ic === 'photo') { e.manque = e.tps === 'ext' ? (2 - nExt) + ' photo' + (2 - nExt > 1 ? 's' : '') + ' à prendre' : 'la photo à prendre'; e.auto = true; }
        else if (e.ic === 'controles' && cq && cq.aNoter) { e.manque = cq.aNoter + ' contrôle' + (cq.aNoter > 1 ? 's' : '') + ' à noter'; e.auto = true; }
        else if (e.pistes) { e.manque = 'le pourquoi, les trois pistes et l’horizon'; }
        else if (e.revue) { e.manque = 'ce que vous avez vu et la recommandation'; }
        else if (e.ic === 'meteo') { e.manque = 'la météo du franchisé'; }
        else if (e.cle === 'checklist') { e.manque = 'la checklist du consultant'; }
      });
      etapes.forEach(e => { if (e.fait) { e.auto = true; } e.bloquante = !!e.manque && this.etapeBloquante(e.cle); });
      const cle = 'et:' + v.id;
      let cur = this.ouvert[cle]; if (cur == null) { cur = etapes.findIndex(e => !e.fait); if (cur < 0) { cur = etapes.length - 1; } }
      cur = Math.max(0, Math.min(etapes.length - 1, cur));
      // Une étape bloquante pas encore remplie arrête tout ce qui la suit : on n'ouvre pas une étape au-delà.
      const bloq = etapes.findIndex(e => e.bloquante);
      if (bloq >= 0 && cur > bloq && this.ouvert[cle] != null) { cur = bloq; this.ouvert[cle] = bloq; }
      this._etapes = { cle, n: etapes.length, revue: etapes.findIndex(e => e.revue), chiffres: etapes.findIndex(e => e.chiffres), pistes: etapes.findIndex(e => e.pistes), visite: v.id, cur, lus: etapes.map(e => e.lu || null),
        bloq: etapes.map(e => e.bloquante ? '« ' + e.court + ' » : ' + e.manque : null) };
      const faits = etapes.filter(e => e.fait).length;
      this.etapeAutoSuivre(v, etapes, cur);
      if (this.o.tablette) { return this.tabControle(v, b, etapes, cur, faits); }
      return this.hd('Contrôle — ' + b.court, fmtDJ(v.prevu_le) + (v.commence_a ? ' · depuis ' + fmtH(v.commence_a) : '') + ' · ' + esc(v.consultantNom || ''), 'fiche/' + v.id, `<span class="pill">${cur + 1} / ${etapes.length}</span>`)
        + `<div class="bar" style="margin:2px 0 12px"><i style="width:${Math.round(faits / etapes.length * 100)}%"></i></div>`
        + etapes.map((e, i) => (i === 0 || etapes[i - 1].tps !== e.tps ? `<div class="cap">${rangT(e.tps) + 1} · ${esc((TEMPS.find(x => x[0] === e.tps) || ['', ''])[1])}</div>` : '') + `<div class="et ${i === cur ? 'on' : ''} ${e.fait ? 'ok' : ''}" id="et-${i}"><div class="et-h" data-a="etape" data-v="${i}"><span class="num">${e.fait ? '✓' : i + 1}</span><b>${esc(e.titre)}</b><span class="sp"></span><span class="xs mu">${esc(e.etat)}</span></div>${i === cur ? `<div class="et-c">${e.html()}${i < etapes.length - 1 ? `<div class="btns"><button class="btn p w" data-a="etape" data-v="${i + 1}">Suivant ›</button><button class="btn" data-a="etape" data-v="${i - 1}" ${i === 0 ? 'disabled' : ''}>‹</button></div>` : ''}</div>` : ''}</div>`).join('');
    }
    /** Le sens du contrôle (réglage ordreTemps) : les six temps dans l'ordre choisi, ceux qui manquent à leur place d'origine. */
    tempsOrdre(o) {
      const vus = {}; const k = String(o != null ? o : ((this.D && this.D.seuils) || {}).ordreTemps || '').split(',').map(x => x.trim()).filter(x => TEMPS.some(t => t[0] === x) && !vus[x] && (vus[x] = true));
      return k.concat(TEMPS.map(t => t[0]).filter(x => !vus[x])).map(x => TEMPS.find(t => t[0] === x));
    }
    /** Une étape est-elle bloquante ? Le réglage de l'étape, à défaut celui des modules (« mod:* »), à défaut le choix livré. */
    etapeBloquante(cle, R) {
      const B = R || (this.D.seuils || {}).etapesBloquantes; const r = B && !Array.isArray(B) ? B : {};
      if (cle in r) { return !!r[cle]; }
      if (String(cle).startsWith('mod:')) { return 'mod:*' in r ? !!r['mod:*'] : BLOQUE_DEFAUT['mod:*']; }
      return !!BLOQUE_DEFAUT[cle];
    }
    /** Aller à une étape du contrôle : marquer lue celle qu'on quitte, garder la review, défiler en haut. */
    allerEtape(n) {
      const E = this._etapes; if (!E) { return; }
      const vis = this.visite(E.visite); let vers = Math.max(0, Math.min(E.n - 1, n));
      // En avant, la première étape bloquante pas remplie arrête le saut (Suivant, la barre du bas, les étapes en tête).
      if (vers > E.cur && E.bloq) {
        const i = E.bloq.findIndex((b, k) => b && k >= E.cur && k < vers);
        if (i >= 0) { this.dire('Avant de continuer : ' + E.bloq[i] + '.'); if (i === E.cur) { return; } vers = i; }
      }
      if (E.lus && E.lus[E.cur]) { this.ouvert[E.lus[E.cur]] = true; }
      if (E.cur === E.chiffres) { this.ouvert['lu:' + E.visite] = true; }
      if (E.cur === E.revue && vers !== E.revue && vis) { this.sauverReview(vis); }
      this.ouvert[E.cle] = vers; this.rendre();
      if (this.o.tablette) { window.scrollTo(0, 0); return; }
      const el = document.getElementById('et-' + vers); if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
    }
    /**
     * Le passage automatique (réglage etapeAuto) : l'étape ouverte vient d'être remplie pendant qu'on y était — la
     * dernière photo, le dernier point — : la suivante s'ouvre une seconde plus tard, sauf si l'on touche l'écran d'ici là.
     */
    etapeAutoSuivre(v, etapes, cur) {
      const e = etapes[cur]; const A = this._auto; const S = this.D.seuils || {};
      this._etapes.manque = e.manque || null;
      if (S.etapeAuto !== false && A && A.visite === v.id && A.cur === cur && !A.fait && e.fait && e.auto && cur < etapes.length - 1) {
        if (this._autoT) { clearTimeout(this._autoT); }
        this._autoT = setTimeout(() => { this._autoT = null; const E = this._etapes; if (this.v === 'controle' && E && E.visite === v.id && E.cur === cur) { this.dire('Étape remplie : la suite.'); this.allerEtape(cur + 1); } }, 1200);
      }
      this._auto = { visite: v.id, cur, fait: !!e.fait };
    }
    /* --- la tablette (12/10/2026) --------------------------------------------------------------
     * L'accueil n'a pas de barre : la liste des boutiques avec leur date de contrôle, ou l'agenda en
     * calendrier (bascule en haut). Une boutique ouvre le contrôle : une étape à l'écran, toutes les
     * étapes dans la barre du bas. Les autres écrans (fiche, planifier, réglages…) gardent leur
     * rendu, sous l'en-tête de la tablette. */
    tabCadre(V) {
      if (V.startsWith('<div class="tb-top"')) { return V; }
      return this.tabTop({ titre: 'Contrôles franchisés', retour: true }) + `<div class="tb-main">${V}</div>`;
    }
    tabTop(o) {
      const n = this.file.length;
      const ini = this.role === 'consultant' && this.moi ? (this.consultantNom(this.moi) || '?').split(/\s+/).map(x => x[0] || '').join('').slice(0, 2).toUpperCase() : 'C';
      return `<div class="tb-top">${o.retour ? '<button class="tb-ret" data-a="go" data-v="shops">‹ Contrôles</button>' : `<img src="${esc((this.o.racine || '') + 'assets/img/logo.png')}" alt="L’Atelier by">`}<span class="sep"></span>`
        + `<div style="min-width:0"><div class="t">${esc(o.titre)}</div>${o.sous ? `<div class="d">${o.sous}</div>` : ''}</div><span style="flex:1"></span>${o.droite || ''}`
        + `<button class="tb-ch ${this.enLigne ? '' : 'off'}" data-a="sync"><i></i>${this.enLigne ? 'en ligne' : 'hors ligne'}${n ? ' · ' + n + ' à envoyer' : ''}</button>`
        + `<button class="tb-av" data-a="go" data-v="reglages" title="Réglages">${esc(ini)}</button></div>`;
    }
    /** La visite qui compte pour une boutique : celle en cours, sinon la prochaine planifiée. */
    tabVisites(shop, vs) {
      const t = auj();
      const ici = vs.filter(v => String(v.shop) === String(shop));
      const cours = ici.find(v => v.statut === 'en_cours') || null;
      const proch = ici.filter(v => (v.statut === 'planifiee' || v.statut === 'confirmee') && v.prevu_le >= t).sort((a, c) => (a.prevu_le + a.debut_h) < (c.prevu_le + c.debut_h) ? -1 : 1)[0] || null;
      const der = ici.filter(v => v.statut === 'terminee').sort((a, c) => a.prevu_le < c.prevu_le ? 1 : -1)[0] || null;
      return { cours, proch, der };
    }
    v_shops() {
      const D = this.D; const t = auj();
      const vs = this.mesVisites().filter(v => v.statut !== 'annulee');
      const enCours = vs.filter(v => v.statut === 'en_cours').length;
      const sem = vs.filter(v => v.prevu_le >= t && v.prevu_le <= plusJours(t, 6) && (v.statut === 'planifiee' || v.statut === 'confirmee')).length;
      const qui = this.role === 'consultant' && this.moi ? this.consultantNom(this.moi) : 'tous les consultants';
      const ag = this.tabVue === 'agenda';
      const tog = `<span class="tb-tog"><button data-a="tab-vue" data-v="liste" class="${ag ? '' : 'on'}">Boutiques</button><button data-a="tab-vue" data-v="agenda" class="${ag ? 'on' : ''}">Agenda</button></span>`;
      return this.tabTop({ titre: 'Contrôles franchisés', sous: esc(fmtDJ(t) + ' · ' + qui) })
        + `<div class="tb-main"><div class="tb-h"><div><div class="cap" style="margin:0">${(D.boutiques || []).length} boutiques${enCours ? ' · ' + enCours + ' en cours' : ''}${sem ? ' · ' + sem + ' cette semaine' : ''}</div><h1>${ag ? 'Agenda des contrôles' : 'Les boutiques'}</h1></div><span style="flex:1"></span>${tog}</div>`
        + (ag ? this.tabAgenda(vs) : this.tabListe(vs)) + '</div>';
    }
    tabListe(vs) {
      const t = auj(); const ordreFeu = { rouge: 0, orange: 1, vert: 2 };
      const L = (this.D.boutiques || []).map(b => Object.assign({ b }, this.tabVisites(b.id, vs)));
      L.sort((x, y) => (x.cours ? 0 : x.proch ? 1 : 2) - (y.cours ? 0 : y.proch ? 1 : 2)
        || (x.proch && y.proch ? ((x.proch.prevu_le + x.proch.debut_h) < (y.proch.prevu_le + y.proch.debut_h) ? -1 : 1) : 0)
        || (ordreFeu[x.b.feu] ?? 3) - (ordreFeu[y.b.feu] ?? 3));
      if (!L.length) { return '<div class="tb-vide">Aucune boutique à contrôler.</div>'; }
      return '<div class="tb-shops">' + L.map(({ b, cours, proch, der }) => {
        const v = cours || proch;
        const cal = v ? `<div class="cal"><i>${esc(MOIS[Number(v.prevu_le.slice(5, 7)) - 1])}</i><b>${Number(v.prevu_le.slice(8, 10))}</b></div>` : '<div class="cal vide"><i>à fixer</i><b>—</b></div>';
        const ligne = cours ? 'Contrôle en cours' : proch ? (proch.prevu_le === t ? 'Aujourd’hui' : fmtDJ(proch.prevu_le)) + ' · ' + esc(proch.debut_h || '') : 'Pas de contrôle planifié';
        const sous = cours ? 'commencé ' + (cours.commence_a ? 'le ' + fmtDJ(cours.commence_a) + ' à ' + fmtH(cours.commence_a) : 'le ' + fmtDJ(cours.prevu_le)) + ' · ' + esc(this.typeNom(cours.type))
          : proch ? esc(this.typeNom(proch.type)) + (proch.statut === 'confirmee' ? ' · confirmé' : ' · planifié') : esc(b.due || 'à planifier');
        const derLe = der ? der.prevu_le : (b.derniereVisite && b.derniereVisite.le);
        const meta = [derLe ? `<span>dernier contrôle ${esc(fmtD(derLe))}</span>` : '',
          b.plansOuverts ? `<span class="${b.p0 ? 'ko' : ''}">${b.plansOuverts} action${b.plansOuverts > 1 ? 's' : ''} ouverte${b.plansOuverts > 1 ? 's' : ''}${b.p0 ? ' · P0' : ''}</span>` : '',
          b.google && b.google.note != null ? `<span>Google ${note1(b.google.note)}</span>` : '',
          der && meteoDe(der.meteo) ? `<span>météo ${meteoDe(der.meteo)[1]} ${esc(meteoDe(der.meteo)[2].toLowerCase())}</span>` : ''].join('');
        const act = cours ? `<button class="tb-b p" data-a="go" data-v="controle/${esc(cours.id)}">Reprendre le contrôle ›</button>`
          : proch ? `<button class="tb-b" data-a="go" data-v="fiche/${esc(proch.id)}">Fiche</button><button class="tb-b p" data-a="demarrer" data-v="${esc(proch.id)}">Démarrer le contrôle ›</button>`
          : `<button class="tb-b" data-a="go" data-v="planifier/${esc(b.id)}">Planifier</button><button class="tb-b p" data-a="demarrer-ici" data-v="${esc(b.id)}">Démarrer maintenant ›</button>`;
        return `<div class="tb-shop ${cours ? 'cours' : ''}"><div class="n">${this.feuDot(b)}<b>${esc(b.court)}</b><span style="flex:1"></span><span class="xs mu">${b.ville && b.ville !== b.court ? esc(b.ville) : ''}</span></div>
          <div class="tb-quand">${cal}<div><div class="l">${ligne}</div><div class="m">${sous}</div></div></div>
          <div class="tb-meta">${meta}</div><div class="tb-act">${act}</div></div>`;
      }).join('') + '</div>';
    }
    tabAgenda(vs) {
      const t = auj();
      const d0 = new Date(t.slice(0, 7) + '-01T12:00:00'); d0.setMonth(d0.getMonth() + this.mois);
      const ym = d0.getFullYear() + '-' + String(d0.getMonth() + 1).padStart(2, '0');
      const finM = ym + '-' + String(new Date(d0.getFullYear(), d0.getMonth() + 1, 0).getDate()).padStart(2, '0');
      const debut = lundiDe(ym + '-01', 0);
      const cases = [];
      for (let i = 0; i < 42; i++) { const d = plusJours(debut, i); if (i % 7 === 0 && i > 0 && d > finM) { break; } cases.push(d); }
      const par = {}; vs.forEach(v => { (par[v.prevu_le] = par[v.prevu_le] || []).push(v); });
      const ST = { planifiee: 'planifié', confirmee: 'confirmé', en_cours: 'en cours', terminee: 'fait' };
      const cible = v => v.statut === 'en_cours' ? 'controle/' + v.id : v.statut === 'terminee' ? 'historique/' + v.shop : 'fiche/' + v.id;
      const tete = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'].map(j => `<div class="wd">${j}</div>`).join('');
      const corps = cases.map((d, i) => {
        const du = (par[d] || []).slice().sort((a, c) => (a.debut_h || '') < (c.debut_h || '') ? -1 : 1);
        return `<div class="j ${d.slice(0, 7) !== ym ? 'hors' : ''} ${d === t ? 'auj' : ''} ${i % 7 >= 5 ? 'we' : ''}"><span class="d">${Number(d.slice(8, 10))}</span>`
          + du.map(v => `<button class="v ${esc(v.statut)}" data-a="go" data-v="${esc(cible(v))}">${esc(v.debut_h || '')} ${esc((this.boutique(v.shop) || { court: v.shop }).court)}<small>${esc(ST[v.statut] || v.statut)}</small></button>`).join('') + '</div>';
      }).join('');
      const sans = (this.D.boutiques || []).filter(b => { const x = this.tabVisites(b.id, vs); return !x.cours && !x.proch; });
      return `<div class="tb-h" style="margin:-4px 0 12px;align-items:center"><button class="tb-b" data-a="mois" data-v="-1">‹</button><b style="font:700 18px var(--font-ui);min-width:170px;text-align:center">${MOIS_L[d0.getMonth()]} ${d0.getFullYear()}</b><button class="tb-b" data-a="mois" data-v="1">›</button>${this.mois ? '<button class="tb-b" data-a="mois" data-v="0">Aujourd’hui</button>' : ''}</div>`
        + `<div class="tb-cal"><div class="g">${tete}${corps}</div></div>`
        + (sans.length ? `<div class="cap" style="margin:20px 2px 10px">Sans contrôle planifié · ${sans.length}</div><div class="tb-shops">` + sans.map(b => `<div class="tb-shop"><div class="n">${this.feuDot(b)}<b>${esc(b.court)}</b><span style="flex:1"></span><span class="xs mu">${esc(b.due || '')}</span></div><div class="tb-act"><button class="tb-b" data-a="go" data-v="planifier/${esc(b.id)}">Planifier</button><button class="tb-b p" data-a="demarrer-ici" data-v="${esc(b.id)}">Démarrer maintenant ›</button></div></div>`).join('') + '</div>' : '');
    }
    /** Le contrôle à la tablette : l'étape choisie à l'écran, toutes les étapes dans la barre du bas. */
    tabControle(v, b, etapes, cur, faits) {
      const e = etapes[cur]; const n = etapes.length;
      const prog = `<div class="tb-pr">${faits} / ${n} étapes<div class="bar"><i style="width:${Math.round(faits / n * 100)}%"></i></div></div>`;
      let pills = '';
      if (e.module && e.n) { const reste = e.n.total - e.n.faits; pills = `<span class="ok">${e.n.faits} / ${e.n.total} vus</span>${e.ko ? `<span class="ko">${e.ko} écart${e.ko > 1 ? 's' : ''}</span>` : ''}${reste ? `<span>${reste} à voir</span>` : ''}`; }
      const prec = cur > 0 ? etapes[cur - 1] : null; const suiv = cur < n - 1 ? etapes[cur + 1] : null;
      const bloque = !!e.bloquante;
      const nomSuiv = suiv ? (suiv.tps !== e.tps ? (TEMPS.find(x => x[0] === suiv.tps) || ['', suiv.court])[1] + ' · ' + suiv.court : suiv.court) : '';
      const nav = `<div class="tb-nav"><button class="tb-b" data-a="etape" data-v="${cur - 1}" ${prec ? '' : 'disabled'}>‹ ${esc(prec ? prec.court : 'Début')}</button><span class="sp"></span>`
        + (suiv ? `<div class="tb-suiv">${e.manque ? `<span class="xs ${bloque ? 'dn' : 'mu'}">Reste : ${esc(e.manque)}</span>` : ''}<button class="tb-b p ${bloque ? 'bloque' : ''}" data-a="${bloque ? 'etape-bloque' : 'etape'}" data-v="${cur + 1}"><small>Suivant</small>${esc(nomSuiv)} ›</button></div>` : '') + '</div>';
      // La barre du bas : les six temps. Un temps ouvre sa première étape pas encore faite.
      const ORD = this.tempsOrdre();
      const temps = ORD.map(([k, nom]) => { const idx = etapes.map((x, i) => x.tps === k ? i : -1).filter(i => i >= 0); return { k, nom, idx, faits: idx.filter(i => etapes[i].fait).length, ko: idx.reduce((a, i) => etapes[i].ko === '!' ? '!' : (a === '!' ? a : a + (Number(etapes[i].ko) || 0)), 0) }; }).filter(x => x.idx.length);
      const ici = temps.find(x => x.k === e.tps) || temps[0];
      const barre = `<div class="tb-bar" style="grid-template-columns:repeat(${temps.length},minmax(0,1fr))">` + temps.map((x, ti) => { const vers = x.idx.find(i => !etapes[i].fait); const tout = x.faits === x.idx.length;
        return `<button data-a="etape" data-v="${vers != null ? vers : x.idx[0]}" class="${x === ici ? 'on' : tout ? 'fait' : ''}"><span class="ic"><svg viewBox="0 0 24 24">${TAB_IC['t_' + x.k] || TAB_IC.liste}</svg>${tout && x !== ici ? '<span class="ok">✓</span>' : ''}${x.ko ? `<span class="ko">${x.ko}</span>` : ''}</span><span class="lb">${ti + 1} · ${esc(x.nom)}</span><span class="es">${x.faits} / ${x.idx.length}</span></button>`; }).join('') + '</div>';
      // En tête du temps en cours : ses étapes, d'un toucher.
      // Les étapes du temps en cours, en assistant horizontal (maquette A choisie par Sam, 26/10/2026) : numérotées,
      // reliées par un trait, la faite en vert, l'étape ouverte pleine.
      const sous = ici.idx.length > 1 ? `<div class="tb-sous ${ici.idx.length > 5 ? 'serre' : ''}">${ici.idx.map((i, j) => { const x = etapes[i]; const st = i === cur ? 'on' : x.fait ? 'fait' : '';
        return (j ? `<i class="l ${etapes[ici.idx[j - 1]].fait ? 'fait' : ''}"></i>` : '') + `<button data-a="etape" data-v="${i}" class="${st}"><span class="n">${x.fait && i !== cur ? '✓' : j + 1}</span><span class="t"><b>${esc(x.court)}</b><small>${esc(x.module && /^\d+ \/ \d+$/.test(x.etat) ? x.etat + ' vus' : x.etat)}</small></span>${x.ko ? `<em>${x.ko}</em>` : ''}</button>`; }).join('')}</div>` : '';
      const pos = ici.idx.indexOf(cur) + 1;
      return this.tabTop({ titre: 'Contrôle · ' + b.court, sous: esc(fmtDJ(v.prevu_le) + (v.commence_a ? ' · commencé à ' + fmtH(v.commence_a) : '') + ' · ' + this.typeNom(v.type)), retour: true, droite: prog })
        + `<div class="tb-main tb-ctl">${['planifiee', 'confirmee'].includes(v.statut) ? `<div class="card alerte tb-dem"><div><b>Visite pas encore démarrée</b><div class="sm mu">prévue ${esc(fmtDJ(v.prevu_le))} à ${esc(v.debut_h || '')} — ouverte depuis le cockpit ou son QR code</div></div><span style="flex:1"></span><button class="btn p" data-a="demarrer" data-v="${esc(v.id)}">▶ Démarrer la visite</button></div>` : ''}${sous}<div class="tb-h"><div><div class="cap" style="margin:0">${ORD.findIndex(x => x[0] === ici.k) + 1} · ${esc(ici.nom)} · étape ${pos} sur ${ici.idx.length}${e.module ? ' · checklist' : ''}</div><h1>${esc(e.titre)}</h1></div><span style="flex:1"></span>${e.module ? this.lngTog(v) : ''}<div class="tb-pills">${pills}</div></div>`
        + `<div class="tb-step">${e.html()}</div>${nav}</div>` + barre;
    }
    v_historique() {
      const shop = this.p || this.shop; const b = this.boutique(shop); if (!b) { return this.hd('Boutique introuvable', '', 'portfolio'); }
      const B = this.B[shop]; const R = this.D.reseau || {};
      const pj = this.photosJour(shop); const parJour = {}; pj.forEach(p => { const d = String(p.prise_a).slice(0, 10); (parJour[d] = parJour[d] || []).push(p); });
      const jrs = Object.keys(parJour).sort().reverse().slice(0, 3);
      const serie = b.ca ? b.ca.serie : [];
      const maxCa = Math.max(1, ...serie.map(d => Math.max(d.ca || 0, d.objectif || 0)));
      const msps = B ? B.msp : (this.D.msp && this.D.msp[shop]) || [];
      const visites = B ? B.visites : (this.D.visites || []).filter(v => String(v.shop) === String(shop) && v.statut === 'terminee').sort((a, c) => a.prevu_le < c.prevu_le ? 1 : -1);
      const pm = B ? B.plansParMois : null;
      return this.hd('Historique — ' + b.court, '3 derniers mois · ' + visites.length + ' visite' + (visites.length > 1 ? 's' : ''), this.role === 'franchise' ? 'tb' : 'tb/' + shop)
        + `<div class="cap">Photos du jour</div><div class="card">${jrs.length ? `<div class="phs">${jrs.map(d => { const p = parJour[d].find(x => x.genre === 'jour_facade') || parJour[d][0]; return `<div class="ph" style="background-image:url('${esc(this.photoSrc(p))}')" data-a="voir" data-v="${esc(p.client_id || p.id)}"><small>${fmtD(d)}</small></div>`; }).join('')}</div><div class="act">${GENRES_JOUR.map(([g, l]) => `<button class="chip" data-a="voir-serie" data-v="${esc(shop)}|${g}">${l} (${pj.filter(x => x.genre === g).length})</button>`).join('')}</div>` : '<div class="sm mu">Pas encore de photo du jour.</div>'}</div>`
        + `<div class="cap">CA de la semaine vs objectif ${this.src('api')}</div><div class="card">${serie.length ? `<div class="spark">${serie.map(d => `<i class="${d.vu ? (d.aujourdhui ? 'o' : '') : 'f'}" style="height:${Math.round((d.ca || 0) / maxCa * 100)}%" title="${esc(d.date)}"></i>`).join('')}</div><div class="row xs mu" style="margin-top:4px"><span>${fmtD(serie[0].date)}</span><span class="sp"></span><span>${fmtD(serie[serie.length - 1].date)}</span></div><div class="sm ${b.ca.pct < 0 ? 'dn' : 'up'}" style="margin-top:4px">${eur(b.ca.ca)} sur ${b.ca.jours} j · ${pct(b.ca.pct)} vs objectif${b.ca.caDelta != null ? ' · ' + pct(Math.round(b.ca.caDelta)) + ' vs référence' : ''}</div>` : '<div class="sm mu">ERP absent.</div>'}</div>`
        + `<div class="cap">Clients</div><div class="card"><div class="row sm"><span class="pill api">Google</span><span>${b.google ? note1(b.google.note) + ' · ' + b.google.avis + ' avis' + (b.google.faibles ? ' · ' + b.google.faibles + ' avis ≤ 2/5 sur 30 j' : '') : '—'}</span></div><div class="row sm" style="margin-top:6px"><span class="pill loc">MSP</span><span>${msps.length ? msps.slice().reverse().map(m => esc(MOIS[Number(m.mois.slice(5)) - 1] || m.mois) + ' ' + note1(m.total)).join(' → ') : 'aucun rapport'}</span></div></div>`
        + `<div class="cap">Plans d’action ${this.src('local')}</div><div class="card sm">${pm ? (Object.keys(pm).sort().map(m => { const x = pm[m]; const tot = Object.values(x).reduce((a, c) => a + c, 0); return esc(m) + ' : ' + tot + ' créé' + (tot > 1 ? 's' : '') + ' · ' + (x.ferme || 0) + ' fermé' + ((x.ferme || 0) > 1 ? 's' : ''); }).join('<br>') || 'aucun') : (B ? 'aucun' : 'lecture…')}<br>${this.plansDe(shop, true).length} ouvert(s) aujourd’hui</div>`
        + `<div class="cap">Planogramme ${this.src('mix')}</div><div class="card">${B && B.planoParVisite.length ? `<div class="row sm">${B.planoParVisite.map(p => esc(fmtD(p.le)) + ' ' + p.pct + ' %').join(' <span class="mu">·</span> ')}</div><div class="bar"><i style="width:${B.planoParVisite[B.planoParVisite.length - 1].pct}%"></i></div>` : '<div class="sm mu">Pas encore de relevé.</div>'}${b.plano && b.plano.ruptures ? `<div class="xs mu" style="margin-top:4px">${b.plano.ruptures} rupture(s) au dernier relevé</div>` : ''}</div>`
        + `<div class="cap">Vu sur place, visite après visite</div>${visites.length ? visites.slice(0, 4).map(v => this.vuSurPlace(v, fmtDJ(v.prevu_le) + ' · ' + (v.consultantNom || '')) || `<div class="card sm mu">${fmtDJ(v.prevu_le)} · ${esc(v.consultantNom || '')} — sans notes</div>`).join('') : '<div class="card sm mu">aucune visite terminée</div>'}`
        + (B && B.nc && !B.nc.indispo ? `<div class="cap">Non-conformités du panel (30 j) ${this.src('api')}</div><div class="card sm">${B.nc.total} relevée(s) · <b class="${B.nc.ouvertes ? 'dn' : ''}">${B.nc.ouvertes} non corrigée(s)</b>${B.nc.liste.length ? '<br>' + B.nc.liste.slice(0, 4).map(n => (n.corrigee ? '✓ ' : '! ') + esc(n.tache) + ' (' + fmtD(n.jour) + ', ' + n.note + '/5)').join('<br>') : ''}</div>` : '')
        + `<div class="cap">Face au réseau (${R.boutiques || 0} boutiques)</div><div class="card sm">CA vs objectif : <b class="${b.ca && b.ca.pct < 0 ? 'dn' : ''}">${b.ca ? pct(b.ca.pct) : '—'}</b> · réseau ${pct(R.caPct)}<br>Google : ${b.google ? note1(b.google.note) : '—'} · réseau ${note1(R.google)}<br>Planogramme : ${b.plano ? b.plano.pct + ' %' : '—'} · réseau ${R.plano != null ? R.plano + ' %' : '—'}</div>`;
    }
    /** L'onglet Campagne du franchisé : l'objectif de campagne, sa boutique cette semaine. */
    v_campagne() {
      if (this.role !== 'franchise') { return this.v_plans(); }
      const b = this.boutique(this.shop) || { court: '' };
      this.chargerCampagnes(this.shop);
      return this.hd('Ma campagne', b.court)
        + '<div class="cap">Objectif de campagne</div>' + this.campagneCarte(this.shop);
    }
    v_plans() {
      const D = this.D;
      if (this.role === 'franchise') {
        const b = this.boutique(this.shop) || { court: '' };
        // Le franchisé CONSULTE sa liste : les actions à faire d'abord, les
        // fermées ensuite ; la campagne qu'une action sert, en pastille. Les
        // photos et les statuts restent au consultant et à l'admin.
        const ps = this.plansDe(this.shop).sort((a, c) => (a.statut === 'ferme') - (c.statut === 'ferme'));
        const enCours = ps.filter(p => p.statut !== 'ferme').length;
        this.chargerCampagnes(this.shop);
        return this.hd('Mon plan d’action', b.court + ' · ' + enCours + ' à faire · ' + (ps.length - enCours) + ' fermée' + (ps.length - enCours > 1 ? 's' : ''))
          + '<div class="cap">Les actions à faire</div>'
          + (ps.length ? ps.map(p => this.planLigne(p, '')).join('') : '<div class="card sm mu">Aucune action en cours. 🎉</div>')
          + this.vuSurPlace(this.derniereVisiteDe(this.shop), 'Ce que le consultant a vu')
          + (this.o.sansOnglets ? `<div class="btns"><button class="btn w" data-a="go" data-v="historique/${esc(this.shop)}">Historique des visites</button></div>` : '');
      }
      const ouverts = (D.plans || []).filter(p => p.statut !== 'ferme' && p.statut !== 'interne');
      const parShop = {}; ouverts.forEach(p => { (parShop[p.shop] = parShop[p.shop] || []).push(p); });
      return this.hd('Plans d’action', ouverts.length + ' en cours · ' + ouverts.filter(p => p.statut === 'attente').length + ' à valider')
        + (this.role !== 'franchise' ? `<div class="row" style="margin-bottom:8px"><button class="btn s" data-a="go" data-v="admin">Corrections à valider</button></div>` : '')
        + (Object.keys(parShop).length ? Object.keys(parShop).map(s => { const b = this.boutique(s) || { court: s }; return `<div class="cap">${this.feuDot(b)} ${esc(b.court)}</div>` + parShop[s].map(p => this.planLigne(p, this.actionsPlan(p))).join(''); }).join('') : '<div class="card sm mu">Aucun plan d’action ouvert.</div>');
    }
    actionsPlan(p) {
      const f = this.form; const k = String(p.id);
      const btn = (st, l, cls) => `<button class="btn s ${cls || ''}" data-a="statut" data-v="${esc(k)}|${st}">${l}</button>`;
      const out = [];
      if (p.statut === 'attente') { out.push(btn('valide', '✅ Accepter', 'p')); out.push(`<button class="btn s" data-a="form" data-v="rep|${esc(k)}">🔄 À reprendre</button>`); }
      if (p.statut === 'valide') { out.push(btn('ferme', '✓ Confirmé sur place · fermer', 'p')); }
      if (p.statut === 'ouvert' || p.statut === 'reprendre') { out.push(`<button class="btn s" data-a="photo" data-v="correction|${esc(p.shop)}||${esc(p.ref || '')}|${esc(p.id)}">📷 Corrigé devant moi</button>`); out.push(`<button class="btn s" data-a="form" data-v="esc|${esc(k)}">🚨 Escalader</button>`); }
      if (p.statut === 'escalade') { out.push(btn('ouvert', 'Rouvrir')); out.push(btn('ferme', 'Fermer')); }
      let form = '';
      if (String(f.rep) === k) { form = `<div class="champ"><input type="text" data-f="form|retour" placeholder="Ce qui manque…" value="${esc(f.retour || '')}"></div><div class="l2" style="margin-top:6px"><input type="date" data-f="form|echeance" value="${esc(f.echeance || plusJours(auj(), 2))}"><button class="btn s p" data-a="statut" data-v="${esc(k)}|reprendre">Renvoyer au franchisé</button></div>`; }
      if (String(f.esc) === k) { form = `<div class="champ"><input type="text" data-f="form|motif" placeholder="Pourquoi escalader…" value="${esc(f.motif || '')}"></div><div class="btns" style="margin-top:6px"><button class="btn s p" data-a="statut" data-v="${esc(k)}|escalade">Escalader à Sam</button></div>`; }
      return out.length ? `<div class="btns" style="flex-wrap:wrap">${out.join('')}</div>${form}` : form;
    }
    v_admin() {
      const D = this.D;
      const att = (D.plans || []).filter(p => p.statut === 'attente');
      const escs = (D.plans || []).filter(p => p.statut === 'escalade' || (p.priorite === 'P0' && p.retard > 0 && p.statut !== 'ferme'));
      const lundi = lundiDe(auj(), 0);
      const sem = (D.plans || []).filter(p => String(p.maj_le).slice(0, 10) >= lundi);
      return this.hd('Corrections à valider', att.length + ' photo' + (att.length > 1 ? 's' : '') + ' reçue' + (att.length > 1 ? 's' : '') + ' · vue ' + this.role)
        + (att.length ? att.map(p => { const b = this.boutique(p.shop) || { court: p.shop }; const apres = (D.photos || []).find(x => x.id === p.photo_id || String(x.plan_id) === String(p.id)); const avant = p.visite_id ? this.photosDe(p.visite_id).find(x => x.ref === p.ref) : null;
          return `<div class="card"><div class="row"><b>${esc(b.court)}</b><span class="pill ${p.priorite}">${p.priorite}</span><span class="sp"></span><span class="xs mu">${fmtD(p.maj_le)} ${fmtH(p.maj_le)}</span></div><div class="sm" style="margin-top:3px">${esc(p.titre)}${p.detail ? ' · <span class="mu">' + esc(p.detail) + '</span>' : ''}</div>
            <div class="phs n2" style="margin-top:8px">${avant ? `<div class="ph" style="background-image:url('${esc(this.photoSrc(avant))}')" data-a="voir" data-v="${esc(avant.client_id || avant.id)}"><small>avant · visite</small></div>` : '<div class="ph vide" style="color:var(--color-text-muted)">pas de photo avant</div>'}${apres ? `<div class="ph" style="background-image:url('${esc(this.photoSrc(apres))}')" data-a="voir" data-v="${esc(apres.client_id || apres.id)}"><small>franchisé · ${fmtD(apres.prise_a)} ${fmtH(apres.prise_a)}</small></div>` : '<div class="ph vide" style="color:var(--color-text-muted)">photo non reçue</div>'}</div>
            ${this.actionsPlan(p)}</div>`; }).join('') : '<div class="card sm mu">Rien à valider.</div>')
        + (escs.length ? '<div class="cap">Escalades et retards</div>' + escs.map(p => this.planLigne(p, this.actionsPlan(p))).join('') : '')
        + `<div class="cap">Cette semaine</div><div class="card sm">✅ ${sem.filter(p => p.statut === 'valide' || p.statut === 'ferme').length} validées ou fermées · 🔄 ${sem.filter(p => p.statut === 'reprendre').length} à reprendre · 🚨 ${sem.filter(p => p.statut === 'escalade').length} escaladée(s)</div>`;
    }
    v_synthese() {
      const S = this.S;
      if (!S) { this.chargerSynthese(); return this.hd('Synthèse réseau', 'lecture…', null); }
      const c = S.compteurs; const R = S.reseau || {};
      return this.hd('Synthèse réseau', fmtDJ(S.date) + ' · ' + S.boutiques.length + ' boutiques · ' + Object.keys(S.consultants || {}).length + ' consultants actifs', null, `<button class="chip" data-a="synthese">↻</button>`)
        + `<div class="stat"><div><div class="xs mu">Plans d’action en cours</div><div class="k" style="margin-top:4px">${c.enCours}</div><div class="xs mu">${c.p0} P0 · ${c.p1} P1 · ${c.p2} P2</div></div><div><div class="xs mu">En attente de validation</div><div class="k" style="margin-top:4px">${c.attente}</div><div class="xs mu">photos à contrôler</div></div><div><div class="xs mu">Fermés cette semaine</div><div class="k" style="margin-top:4px;color:#2d7a3e">${c.fermes}</div><div class="xs mu">validés et confirmés</div></div></div>
          <div class="cap">Les boutiques</div><div class="card" style="padding:4px 6px;overflow:auto"><table class="tbl"><tr><th>Boutique</th><th>Feu</th><th>Dernière visite</th><th>CA semaine ${this.src('api')}</th><th>Google ${this.src('api')}</th><th>Plano</th><th>Actions</th><th>Signal</th></tr>${S.boutiques.map(b => `<tr><td><b>${esc(b.court)}</b></td><td>${this.feuDot(b)}</td><td>${b.derniereVisite ? fmtD(b.derniereVisite.le) + ' · ' + esc(b.derniereVisite.consultant) : '<span class="mu">jamais</span>'}</td><td>${b.ca ? eur(b.ca.ca) + ' · <span class="' + (b.ca.pct < 0 ? 'dn' : 'up') + '">' + pct(b.ca.pct) + '</span>' : '—'}</td><td>${b.google ? note1(b.google.note) + ' (' + b.google.avis + ')' : '—'}</td><td>${b.plano ? b.plano.pct + ' %' : '—'}</td><td class="${b.p0 ? 'dn' : ''}">${b.plansOuverts}${b.p0 ? ' · ' + b.p0 + ' P0' : ''}</td><td class="${b.feu === 'rouge' ? 'dn' : 'mu'}">${esc((b.motifs || []).slice(0, 2).join(' · ') || '—')}</td></tr>`).join('')}</table></div>
          <div class="grid2"><div><div class="cap">Escalades possibles</div><div class="card sm">${S.escalades.length ? S.escalades.map(e => `<div class="al"><span class="feu ${e.feu}" style="margin-top:4px"></span><div><b>${esc(e.titre)}</b><div class="xs mu">${esc(e.detail)}</div></div></div>`).join('') : '<span class="mu">Aucune.</span>'}</div></div>
          <div><div class="cap">Actions requises aujourd’hui</div><div class="card sm">${S.actions.length ? S.actions.map((a, i) => (i + 1) + '. ' + esc(a)).join('<br>') : '<span class="mu">Rien d’urgent.</span>'}</div><div class="cap">Consultants cette semaine</div><div class="card sm">${Object.keys(S.consultants || {}).length ? Object.keys(S.consultants).map(k => esc(k) + ' ' + S.consultants[k]).join(' · ') : '<span class="mu">aucune visite planifiée</span>'}<div class="xs mu" style="margin-top:4px">${S.visitesSemaine} visites · ${S.checklists} terminées · ${S.photosSemaine} photos</div></div></div></div>
          ${S.boutiques.some(b => b.derniereVisite && (b.derniereVisite.reco || (b.derniereVisite.causes || []).length)) ? `<div class="cap">Vu sur place — le vrai problème</div><div class="card sm">${S.boutiques.filter(b => b.derniereVisite && (b.derniereVisite.reco || (b.derniereVisite.causes || []).length)).map(b => { const d = b.derniereVisite; const CD = this.D.causesDiag || {}; return `<div class="al"><span class="feu ${b.feu}" style="margin-top:4px"></span><div><b>${esc(b.court)}</b> · ${fmtD(d.le)} · ${esc(d.consultant)}${(d.causes || []).length ? '<div>' + esc(d.causes.map(c => CD[c] || c).join(', ')) + '</div>' : ''}${d.reco ? `<div class="mu">« ${esc(d.reco)} »</div>` : ''}</div></div>`; }).join('')}</div>` : ''}
          <div class="card sm mu">Réseau : CA ${pct(R.caPct)} vs objectif · Google ${note1(R.google)} · planogramme ${R.plano != null ? R.plano + ' %' : '—'}. La synthèse part aussi par mail le matin si une adresse est réglée (Réglages).</div>`;
    }
    /** Ce que le consultant a vu, diagnostiqué et recommandé — lu par le franchisé, l'admin, l'historique. */
    vuSurPlace(v, titre) {
      if (!v || !(v.reco || v.diagnostic || (v.causes || []).length || v.execution || v.clients || v.sentiment || v.positif || v.meteo)) { return ''; }
      const CD = this.D.causesDiag || {};
      return `<div class="cap">${titre || 'Vu sur place'}</div><div class="card sm">
        ${v.sentiment || v.execution || v.clients || v.meteo ? `<div class="row" style="flex-wrap:wrap;gap:6px">${meteoDe(v.meteo) ? `<span class="pill">météo du franchisé ${meteoDe(v.meteo)[1]} ${esc(meteoDe(v.meteo)[2])}</span>` : ''}${v.sentiment ? `<span class="pill">équipe ${'★'.repeat(v.sentiment)}</span>` : ''}${v.execution ? `<span class="pill ${v.execution === 'standards' ? 'api' : v.execution === 'ecarts' ? 'loc' : 'mix'}">${EXECUTION[v.execution] || v.execution}</span>` : ''}${v.clients ? `<span class="pill ${v.clients === 'satisfaits' ? 'api' : v.clients === 'insatisfaits' ? 'loc' : 'mix'}">clients ${(CLIENTS[v.clients] || v.clients).toLowerCase()}</span>` : ''}</div>` : ''}
        ${(v.causes || []).length ? `<div style="margin-top:6px"><b>Le vrai problème :</b> ${esc(v.causes.map(c => CD[c] || c).join(', '))}</div>` : ''}
        ${v.diagnostic ? `<div class="mu" style="margin-top:2px">${esc(v.diagnostic)}</div>` : ''}
        ${v.reco ? `<div style="margin-top:6px"><b>Recommandation :</b> ${esc(v.reco)}</div>` : ''}
        ${v.positif ? `<div style="margin-top:6px">🟢 ${esc(v.positif)}</div>` : ''}
        ${v.notes ? `<div class="mu" style="margin-top:4px">${esc(v.notes)}</div>` : ''}
        <div class="xs mu" style="margin-top:6px">${esc(v.consultantNom || '')} · visite du ${fmtDJ(v.prevu_le)}</div></div>`;
    }
    async chargerSynthese() { try { this.S = await this.lire('/visites/synthese', 'synthese'); } catch (e) { this.S = null; this.dire('Synthèse indisponible hors ligne.'); return; } this.rendre(); }
    v_msp() {
      const shop = this.p || this.shop; const b = this.boutique(shop); if (!b) { return this.hd('Boutique introuvable', '', 'portfolio'); }
      const f = this.form; const msps = (this.D.msp && this.D.msp[shop]) || []; const eq = b.equipe;
      return this.hd('MSP et équipe — ' + b.court, 'rapport mystery shopper, relevé d’effectif', 'tb/' + shop)
        + `<div class="cap">Rapport mystery shopper ${this.src('local')}</div><div class="card">${msps.length ? msps.map(m => `<div class="row sm" style="padding:4px 0"><b>${esc(m.mois)}</b><span>${note1(m.total)}/20</span><span class="mu">${Object.keys(m.rubriques || {}).map(k => k + ' ' + m.rubriques[k]).join(' · ')}</span><span class="sp"></span>${m.fichier ? `<a class="chip" target="_blank" rel="noopener" href="${esc((this.o.racine || '') + m.fichier)}">PDF</a>` : ''}</div>`).join('') : '<div class="sm mu">Aucun rapport.</div>'}
          <div class="champ" style="margin-top:10px"><label>Mois</label><input type="text" data-f="form|mois" value="${esc(f.mois || auj().slice(0, 7))}" placeholder="AAAA-MM"></div>
          <div class="rub">${RUBRIQUES_MSP.map(r => `<div class="champ"><label>${r}</label><input type="number" data-f="form|r_${r}" value="${esc(f['r_' + r] || '')}" placeholder="/20"></div>`).join('')}</div>
          <div class="champ"><label>Commentaires du rapport</label><textarea data-f="form|commentaires">${esc(f.commentaires || '')}</textarea></div>
          <div class="champ"><label>Rapport PDF (facultatif, 8 Mo max)</label><input type="file" accept="application/pdf" data-c="fichier|msp"></div>
          <div class="btns"><button class="btn p w" data-a="msp" data-v="${esc(shop)}">💾 Enregistrer le rapport</button></div></div>
          <div class="cap">Équipe ${this.src('local')}</div><div class="card">${eq ? `<div class="sm mu" style="margin-bottom:6px">Dernier relevé ${fmtD(eq.releve_le)} : ${eq.effectif != null ? eq.effectif : '—'} présents${eq.prevu != null ? ' / ' + eq.prevu + ' prévus' : ''}${eq.departs ? ' · ' + eq.departs + ' départ(s)' : ''}</div>` : ''}
          <div class="rub" style="grid-template-columns:repeat(3,1fr)"><div class="champ"><label>Effectif</label><input type="number" data-f="form|effectif" value="${esc(f.effectif || '')}"></div><div class="champ"><label>Prévu</label><input type="number" data-f="form|prevu" value="${esc(f.prevu || '')}"></div><div class="champ"><label>Départs (mois)</label><input type="number" data-f="form|departs" value="${esc(f.departs || '')}"></div></div>
          <div class="btns"><button class="btn w" data-a="equipe" data-v="${esc(shop)}">💾 Relever l’effectif</button></div></div>`;
    }
    /**
     * Réglages › Contrôle guidé : les étapes bloquantes, étape par étape (26/10/2026). Une étape bloquante pas encore remplie
     * empêche d'aller plus loin — Suivant, la barre du bas, les étapes en tête. Les étapes à lire ne bloquent jamais.
     */
    reglagesEtapesHtml(B) {
      const D = this.D; const mods = []; const vus = {};
      const ajoute = (id, nom, tps) => { if (!id || vus[id]) { return; } vus[id] = true; mods.push(['mod:' + id, nom, tps]); };
      ajoute('exterieur', 'L’extérieur (points de checklist)', 'ext'); ajoute('xp', MOD_XP.nom, 'int');
      [].concat(D.checklist || [], ...((D.types || []).map(t => t.checklist || []))).forEach(m => ajoute(m.id, m.nom, m.id === 'msp' ? 'analyse' : 'int'));
      ajoute('bureau', 'Demandé par le bureau', 'suivi'); ajoute('leviers', MOD_LEVIERS.nom, 'analyse');
      const lignes = [['ext', 'photos_ext', 'L’extérieur en photo'], ...mods.filter(m => m[2] === 'ext').map(m => [m[2], m[0], m[1]]),
        ['int', 'photos_int', 'L’intérieur en photo'], ...mods.filter(m => m[2] === 'int').map(m => [m[2], m[0], m[1]]), ['int', 'controles', 'Les contrôles en photo du jour (photos de l’équipe)'],
        ['suivi', null, 'Dossier du bureau'], ...mods.filter(m => m[2] === 'suivi').map(m => [m[2], m[0], m[1]]), ['suivi', null, 'Les points décidés aux visites précédentes'], ['suivi', 'checklist', 'Checklist du consultant (panel)'],
        ['analyse', null, 'KPI et santé du magasin'], ...mods.filter(m => m[2] === 'analyse').map(m => [m[2], m[0], m[1]]), ['analyse', null, 'Les tâches des derniers mois'],
        ['dev', 'pistes', 'Le CA sous le budget : pourquoi et trois pistes'], ['dev', 'constat', 'Vu sur place et recommandation'], ['dev', null, 'Points à travailler'],
        ['fin', 'meteo', 'Météo du franchisé'], ['fin', null, 'Fin de visite'], ['*', 'mod:*', 'Les autres modules de checklist (nouveaux types, revisites)']];
      const ORD = this.tempsOrdre(this.form.seuils && this.form.seuils.ordreTemps);
      const nomT = k => k === '*' ? '—' : (ORD.findIndex(x => x[0] === k) + 1) + ' · ' + (TEMPS.find(x => x[0] === k) || ['', ''])[1];
      lignes.sort((a, b) => (a[0] === '*' ? 99 : ORD.findIndex(x => x[0] === a[0])) - (b[0] === '*' ? 99 : ORD.findIndex(x => x[0] === b[0])));
      const sens = `<div class="sm" style="margin:12px 0 6px"><b>Le sens du contrôle</b> <span class="xs mu">— faites glisser un temps (⠿) ou utilisez ↑ ↓</span></div>
        <div class="sens" data-glisse="ordreTemps">${ORD.map(([k, nom], i) => `<div class="sens-l" data-k="${k}"><span class="gl-h" title="Glisser">⠿</span><b>${i + 1}</b><span class="nm">${esc(nom)}</span><span class="sp"></span>
          <button class="chip" data-a="sens-mv" data-v="${k}|-1" ${i ? '' : 'disabled'}>↑</button><button class="chip" data-a="sens-mv" data-v="${k}|1" ${i < ORD.length - 1 ? '' : 'disabled'}>↓</button></div>`).join('')}</div>
        <div class="act"><button class="chip" data-a="sens-raz">Remettre le sens livré</button></div>`;
      return sens + `<div class="ksc" style="margin-top:10px"><table class="tbl bq-t"><tr><th>Temps</th><th>Étape</th><th>Bloquante</th></tr>${lignes.map(([t, k, nom], i) => `<tr>
        <td class="xs mu">${i && lignes[i - 1][0] === t ? '' : esc(nomT(t))}</td><td>${esc(nom)}</td>
        <td>${k ? `<span class="bq">${[[true, 'Oui'], [false, 'Non']].map(([o, l]) => `<button class="${this.etapeBloquante(k, B) === o ? 'on' : ''}" data-a="${this.etapeBloquante(k, B) === o ? '' : 'bloq-tog'}" data-v="${esc(k)}">${l}</button>`).join('')}</span>` : '<span class="xs mu">à lire — jamais bloquante</span>'}</td></tr>`).join('')}</table></div>
        <div class="xs mu" style="margin-top:6px">Bloquante : tant qu’elle n’est pas remplie, on ne passe pas à la suite — ni par « Suivant », ni par la barre du bas, ni par les étapes en tête. Revenir en arrière reste libre.</div>`;
    }
    /**
     * Réglages › la checklist, générique et bilingue (26/10/2026) : chaque question a son type de réponse (OK / Écart /
     * N/A, Oui / Non, note, pourcentage, nombre, choix unique, choix multiples, texte), son libellé en français et en
     * néerlandais, une précision facultative, la photo attendue ; les choix ont leurs deux libellés et disent s'ils sont
     * un écart ; un nombre a ses bornes et son unité. Le serveur normalise (src/visites_questions.php).
     */
    reglagesChecklistHtml(cl) {
      const n = cl.reduce((a, m) => a + m.points.length, 0);
      const opt = (val, v, lab) => `<option value="${v}" ${val === v ? 'selected' : ''}>${esc(lab)}</option>`;
      return `<div class="cap">Checklist (${n} questions) · français et néerlandais</div>` + cl.map((m, mi) => `<div class="card rg-mod"><div class="row"><b>${esc(m.nom)}</b><input type="text" class="rg-nl" data-f="clm|${mi}|nom_nl" value="${esc(m.nom_nl || '')}" placeholder="nom en néerlandais"><span class="sp"></span><button class="chip" data-a="cl-add" data-v="${mi}">+ question</button></div>
        ${m.points.map((p, pi) => { const t = typeQ(p); const k = mi + '|' + pi;
          const choix = t === 'choix' || t === 'multi' ? `<div class="rg-ch">${(p.choix || []).map((c, ci) => `<div class="row"><input type="text" data-f="clc|${k}|${ci}|fr" value="${esc(c.fr || '')}" placeholder="réponse en français"><input type="text" data-f="clc|${k}|${ci}|nl" value="${esc(c.nl || '')}" placeholder="antwoord in het Nederlands"><button class="chip ${c.ko ? 'ko on' : ''}" data-a="clc-ko" data-v="${k}|${ci}" title="cette réponse est un écart">écart</button><button class="chip ko" data-a="clc-del" data-v="${k}|${ci}">×</button></div>`).join('')}
            <button class="chip" data-a="clc-add" data-v="${k}">+ réponse</button><span class="xs mu"> « écart » : cette réponse rend le point non conforme.</span></div>` : '';
          const nombre = t === 'nombre' ? `<div class="row rg-nb"><label>min</label><input type="number" step="any" data-f="cl|${k}|min" value="${p.min != null ? esc(p.min) : ''}"><label>max</label><input type="number" step="any" data-f="cl|${k}|max" value="${p.max != null ? esc(p.max) : ''}"><label>unité</label><input type="text" data-f="cl|${k}|unite" value="${esc(p.unite || '')}" placeholder="kg, °C, pièces…"><span class="xs mu">hors des bornes : écart</span></div>` : '';
          return `<div class="rg-q"><div class="row"><input type="text" data-f="cl|${k}|libelle" value="${esc(p.libelle)}" placeholder="la question en français"><input type="text" data-f="cl|${k}|libelle_nl" value="${esc(p.libelle_nl || '')}" placeholder="de vraag in het Nederlands"></div>
            <div class="row"><select data-c="cl-type|${k}">${Object.keys(TYPES_Q).map(x => opt(t, x, TYPES_Q[x])).join('')}</select><button class="chip ${p.photo ? 'on' : ''}" data-a="cl-tog" data-v="${k}|photo" title="photo attendue">📷 photo</button>
              <input type="text" class="rg-aide" data-f="cl|${k}|aide" value="${esc(p.aide || '')}" placeholder="précision (facultatif)"><input type="text" class="rg-aide" data-f="cl|${k}|aide_nl" value="${esc(p.aide_nl || '')}" placeholder="toelichting (optioneel)"><button class="chip ko" data-a="cl-del" data-v="${k}">×</button></div>${choix}${nombre}
            <div class="rg-sc"><span class="cap">Score</span>${t === 'texte' ? '<span class="xs mu">le texte libre ne compte jamais dans le score</span>'
              : `<label>Compte</label><span class="seg">${[[1, 'Oui'], [0, 'Non']].map(([x, lab]) => `<button data-a="cl-score" data-v="${k}|${x}" class="${(p.score !== false) === !!x ? 'on' : ''}">${lab}</button>`).join('')}</span>
              <label>Poids</label><span class="seg${p.score === false ? ' off' : ''}">${[1, 2, 3].map(x => `<button data-a="cl-poids" data-v="${k}|${x}" class="${(Number(p.poids) || 1) === x && p.score !== false ? 'on' : ''}" ${p.score === false ? 'disabled' : ''}>×${x}</button>`).join('')}</span>`}</div></div>`; }).join('')}</div>`).join('');
    }
    v_reglages() {
      const D = this.D; const f = this.form; const s = D.seuils || {};
      const moi = this.role === 'consultant' ? `<div class="cap">Qui suis-je</div><div class="card"><select data-c="moi">${[['', '— choisir —']].concat((D.consultants || []).map(c => [c.id, c.nom])).map(([v, l]) => `<option value="${esc(v)}" ${v === this.moi ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select><div class="xs mu" style="margin-top:6px">L’agenda montre les visites de ce consultant ; les visites planifiées ici lui sont attribuées.</div></div>` : '';
      const push = `<div class="cap">Notifications</div><div class="card"><div class="sm mu">${this.o.mobile ? 'Sur ce téléphone : rappels J-1 et jour J, plans d’action, corrections reçues, escalades.' : 'À activer sur le téléphone (page Application mobile).'}</div>${this.o.mobile ? `<div class="btns"><button class="btn w" data-a="push">${this.pushEtat === 'abonne' ? '🔕 Désactiver' : '🔔 Activer les notifications'}</button></div>${this.pushMotif ? `<div class="xs dn" style="margin-top:4px">${esc(this.pushMotif)}</div>` : ''}` : ''}</div>`;
      const hors = `<div class="cap">Hors ligne</div><div class="card sm"><div>${this.file.length} écriture(s) en attente · dernière sync ${esc(this.sync || '—')}</div><div class="btns"><button class="btn s" data-a="sync">📤 Synchroniser</button><button class="btn s" data-a="recharger">↻ Recharger</button><button class="btn s" data-a="raz">Vider la réserve</button></div></div>`;
      if (this.role !== 'admin' && !(this.role === 'consultant' && !this.o.mobile && !this.o.tablette)) { return this.hd('Réglages', '') + moi + push + hors; }
      const R = this.R;
      if (!R) { this.chargerReglages(); }
      const cl = f.checklist || (R ? R.checklist : D.checklist) || [];
      const se = f.seuils || Object.assign({}, s, { etapesBloquantes: Object.assign({}, Array.isArray(s.etapesBloquantes) ? {} : s.etapesBloquantes) });
      const fq = f.frequence || Object.assign({}, D.frequence || {});
      if (!f.checklist) { f.checklist = JSON.parse(JSON.stringify(cl)); f.seuils = se; f.frequence = fq; }
      const num = (k, l, aide) => `<div class="champ"><label>${l}</label><input type="number" step="${/google/i.test(k) ? '0.1' : '1'}" data-f="se|${k}" value="${esc(f.seuils[k])}">${aide ? `<div class="xs mu">${aide}</div>` : ''}</div>`;
      return this.hd('Réglages visites', 'checklist, seuils du feu, fréquences, horloge') + moi + push
        + '<div class="card sm mu">La checklist ci-dessous est celle de la visite régulière.</div>'
        + this.reglagesChecklistHtml(f.checklist) + `
          <div class="cap">Feu tricolore</div><div class="card"><div class="l2">${num('p0Jours', 'P0 ouvert plus de (jours) → rouge')}${num('escaladeJours', 'Échéance dépassée de (jours) → escalade auto')}${num('googleCible', 'Cible Google')}${num('googleRouge', 'Sous la cible de (points) → rouge')}${num('planoOrange', 'Planogramme sous (%) → orange')}${num('planoRouge', 'Planogramme sous (%) → rouge')}${num('mspAlerte', 'Rubrique MSP sous (/20) → rouge')}${num('caOrange', 'CA sous l’objectif de (%) → orange, et à justifier à la visite')}${num('b2bMax', 'Part B2B du CA au plus (%)')}</div><div class="act"><button class="chip ${f.seuils.caSeul ? 'on' : ''}" data-a="se-tog" data-v="caSeul">Le CA seul peut colorer le feu</button></div></div>
          <div class="cap">Contrôle guidé</div><div class="card"><div class="act"><button class="chip ${f.seuils.etapeAuto !== false ? 'on' : ''}" data-a="se-tog" data-v="etapeAuto">Une étape remplie passe d’elle-même à la suivante</button></div>
            <div class="xs mu" style="margin-top:6px">Passent d’elles-mêmes : les photos, la checklist, les contrôles du jour (un toucher dans la seconde annule).</div>${this.reglagesEtapesHtml(f.seuils.etapesBloquantes)}</div>
          <div class="cap">Fréquence de visite (jours)</div><div class="card"><div class="l2">${D.boutiques.map(b => `<div class="champ"><label>${esc(b.court)}</label><input type="number" data-f="fq|${esc(b.id)}" value="${esc(f.frequence[b.id] || '')}" placeholder="${esc(s.visiteJours || 7)}"></div>`).join('')}</div></div>
          <div class="cap">Horloge et mails</div><div class="card"><div class="l2"><div class="champ"><label>Rappel J-1 à</label><input type="time" data-f="se|rappelJ1" value="${esc(f.seuils.rappelJ1)}"></div><div class="champ"><label>Rappel jour J à</label><input type="time" data-f="se|rappelJour" value="${esc(f.seuils.rappelJour)}"></div><div class="champ"><label>Synthèse à</label><input type="time" data-f="se|syntheseHeure" value="${esc(f.seuils.syntheseHeure)}"></div><div class="champ"><label>Adresse de la synthèse</label><input type="email" data-f="se|mailSynthese" value="${esc(f.seuils.mailSynthese || '')}" placeholder="—"></div></div>
            <div class="act"><button class="chip ${f.seuils.mails ? 'on' : ''}" data-a="se-tog" data-v="mails">Les mails partent (rappels aux consultants, synthèse)${R && !R.smtp ? ' — SMTP non configuré' : ''}</button></div>
            <div class="xs mu" style="margin-top:8px">${R ? 'Dernier passage de l’horloge : ' + esc((R.cron && R.cron.dernier) || 'jamais') + ' · J-1 ' + esc((R.cron && R.cron.j1) || '—') + ' · jour ' + esc((R.cron && R.cron.jour) || '—') + ' · synthèse ' + esc((R.cron && R.cron.synthese) || '—') : ''}</div>
            <div class="btns"><button class="btn s" data-a="tick">⏱ Passer l’horloge maintenant</button></div></div>
          <div class="btns"><button class="btn p w" data-a="save-reglages">💾 Enregistrer les réglages</button></div>` + hors;
    }
    async chargerReglages() { try { this.R = await this.lire('/visites/reglages', 'reglages'); this.rendre(); } catch (e) { /* hors ligne */ } }

    /* --- événements --------------------------------------------------------- */
    clic(e) {
      // Un toucher annule le passage automatique à l'étape suivante en attente.
      if (this._autoT) { clearTimeout(this._autoT); this._autoT = null; }
      // Les boutons des cartes du dashboard magasin (cartes-magasin.js) : la mesure de l'année, la vue des leviers.
      const cm = e.target.closest('[data-anm],[data-levvue]');
      if (cm && this.host.contains(cm)) { if (cm.dataset.anm) { this.anm = cm.dataset.anm; } else if (!cm.disabled) { this.levVue = cm.dataset.levvue; } this.rendre(); return; }
      const el = e.target.closest('[data-a]'); if (!el || !this.host.contains(el)) { return; }
      const a = el.dataset.a; const v = el.dataset.v || ''; const parts = v.split('|');
      if (a === 'go') { const [vue, p] = v.split('/'); this.go(vue, p); return; }
      if (a === 'fermer-voir') { this.voir = null; this.rendre(); return; }
      if (a === 'voir') { const p = (this.D.photos || []).find(x => String(x.id) === v || x.client_id === v); if (p) { this.voir = { src: this.photoSrc(p), txt: (this.boutique(p.shop) || {}).court + ' · ' + fmtDJ(p.prise_a) + ' ' + fmtH(p.prise_a) + ' · ' + p.genre.replace('jour_', '') + (p.attente ? ' · pas encore envoyée' : '') }; this.rendre(); } return; }
      if (a === 'cq-f') { this.cqF[parts[0]] = parts[1]; this.rendre(); return; }
      if (a === 'cq-voir') { this.cqV = { shop: parts[0], taskId: parts[1] }; this.rendre(); return; }
      if (a === 'cq-fermer') { this.cqV = null; this.rendre(); return; }
      if (a === 'cq-nav') { this.cqAvance(Number(v)); return; }
      if (a === 'cq-note') { this.cqNoter(parts[0], parts[1], Number(parts[2])); return; }
      if (a === 'voir-serie') { const ps = this.photosJour(parts[0]).filter(x => x.genre === parts[1]); if (ps.length) { this.voir = { src: this.photoSrc(ps[0]), txt: ps.length + ' photo(s) · la plus récente ' + fmtDJ(ps[0].prise_a) }; this.rendre(); } return; }
      if (a === 'sem') { this.sem = v === '0' ? 0 : this.sem + Number(v); this.rendre(); return; }
      if (a === 'tache') { const t = (this.D.taches || []).find(x => String(x.id) === parts[0]); if (t) { t.statut = parts[1]; t.attente = true; } this.ecrire({ method: 'PUT', path: '/consultants/taches/' + parts[0], body: { statut: parts[1] }, apres: 'tache' }); return; }
      if (a === 'drop') { this.ouvert[v] = !this.ouvert[v]; this.rendre(); return; }
      if (a === 'tm-mois') { this.chargerTachesMois(parts[0], parts[1]); this.rendre(); return; }
      if (a === 'tm-voir') { this.tmV = { shop: parts[0], i: Number(parts[1]) || 0 }; this.rendre(); return; }
      if (a === 'tm-fermer') { this.tmV = null; this.rendre(); return; }
      if (a === 'tm-nav') { if (this.tmV) { this.tmV = { shop: this.tmV.shop, i: Math.max(0, this.tmV.i + Number(v)) }; } this.rendre(); return; }
      if (a === 'tm-n') { this.tmN = Number(v) === 12 ? 12 : 6; this.rendre(); return; }
      if (a === 'sante-relire') { delete this.KS[v]; delete this.VB[v]; this.chargerSante(v); this.rendre(); return; }
      if (a === 'vb-vue') { this.vbVue = v; this.rendre(); return; }
      if (a === 'vb-h') { const f = this.form; if (f.vb) { f.vb.horizon = f.vb.horizon === v ? '' : v; } this.rendre(); return; }
      if (a === 'vb-save') { this.vbSauver(v); return; }
      if (a === 'sync') { this.rejouer(); return; }
      if (a === 'recharger') { this.recharger(); return; }
      if (a === 'synthese') { this.S = null; this.rendre(); return; }
      if (a === 'raz') { Idb.vider('cache').then(() => this.recharger()); return; }
      if (a === 'form') { const val = parts.length > 1 ? (isNaN(parts[1]) ? parts[1] : Number(parts[1])) : true; this.form[parts[0]] = (parts[0] === 'execution' || parts[0] === 'clients') && this.form[parts[0]] === val ? '' : val; this.rendre(); return; }
      if (a === 'planifier') { this.planifier(); return; }
      if (a === 'reprog') { const f = this.form; this.ecrire({ method: 'PUT', path: '/visites/' + encodeURIComponent(v), body: { prevu_le: f.prevu_le, debut_h: f.debut_h, qui: this.qui() }, apres: 'visite' }); const vis = this.visite(v); if (vis) { if (f.prevu_le) { vis.prevu_le = f.prevu_le; } if (f.debut_h) { vis.debut_h = f.debut_h; } } this.form = {}; this.dire('Visite reprogrammée.'); return; }
      if (a === 'annuler') { if (!confirm('Annuler cette visite ?')) { return; } this.majVisite(v, { statut: 'annulee' }); this.go('agenda'); return; }
      if (a === 'confirmer') { this.majVisite(v, { statut: 'confirmee' }); this.dire('Visite confirmée.'); return; }
      if (a === 'demarrer') { const vis = this.visite(v); if (vis && vis.statut !== 'en_cours') { this.majVisite(v, { statut: 'en_cours' }); const b = this.boutique(vis.shop); if (b) { b.visiteEnCours = vis.id; } } if (vis) { this.chargerBoutique(vis.shop); this.go('controle', vis.id); } else { this.go('agenda'); } return; }
      if (a === 'demarrer-ici') { this.form = { shop: v, prevu_le: auj(), debut_h: maintenant().slice(11), duree_min: 60, motif: 'asap' }; this.planifier(true); return; }
      if (a === 'photo') { this.prendrePhoto(parts[0], parts[1], parts[2], parts[3], parts[4]); return; }
      if (a === 'oblig-tous') { const vis = this.visite(parts[0]); if (!vis) { return; } const mod = this.checklistDe(vis).find(x => x.id === parts[1]); const pts = this.pointsDe(vis.id);
        (mod ? mod.points.filter(pt => pt.oblig && !(pts[pt.ref] || {}).etat) : []).forEach(pt => { const p = this.point(vis.id, pt.ref, mod.id); p.etat = 'ok'; this.point_save(vis.id, p); }); this.rendre(); return; }
      if (a === 'etat-set') { const p = this.point(parts[0], parts[1], parts[2]); p.etat = p.etat === parts[3] ? '' : parts[3]; this.point_save(parts[0], p); return; }
      if (a === 'tab-vue') { this.tabVue = v; try { localStorage.setItem('vi.tabVue', v); } catch (e) { /* privé */ } this.rendre(); return; }
      if (a === 'mois') { this.mois = v === '0' ? 0 : this.mois + Number(v); this.rendre(); return; }
      if (a === 'etat') { const cyc = { '': 'ok', ok: 'ko', ko: 'na', na: '' }; const p = this.point(parts[0], parts[1], parts[2]); p.etat = cyc[p.etat || ''] || ''; this.point_save(parts[0], p); return; }
      if (a === 'lng') { this.lngV = this.lngV || {}; this.lngV[parts[0]] = parts[1] === 'nl' ? 'nl' : 'fr'; try { localStorage.setItem('vi.lng.' + parts[0], this.lngV[parts[0]]); } catch (e) { /* privé */ } this.rendre(); return; }
      // Les réponses génériques : la note pose toujours l'état, un choix ou des choix aussi (src/visites_questions.php).
      if (a === 'note-q') { const p = this.point(parts[0], parts[1], parts[2]); p.note = p.note === Number(parts[3]) ? null : Number(parts[3]); p.etat = p.note == null ? '' : (p.note <= 2 ? 'ko' : 'ok'); this.point_save(parts[0], p); return; }
      if (a === 'rep-ch' || a === 'rep-mu') {
        const p = this.point(parts[0], parts[1], parts[2]); const pt = this.ptDef(parts[0], parts[2], parts[1]); const code = parts[3];
        if (a === 'rep-ch') { p.reponse = p.reponse === code ? null : code; }
        else { const l = [].concat(p.reponse == null ? [] : p.reponse); const i = l.indexOf(code); if (i >= 0) { l.splice(i, 1); } else { l.push(code); } p.reponse = l.length ? l : null; }
        p.etat = this.etatDeReponse(pt, p.reponse); this.point_save(parts[0], p); return;
      }
      if (a === 'note') { const p = this.point(parts[0], parts[1], parts[2]); p.note = p.note === Number(parts[3]) ? null : Number(parts[3]); if (p.note != null && !p.etat) { p.etat = p.note <= 2 ? 'ko' : 'ok'; } this.point_save(parts[0], p); return; }
      if (a === 'cause') { const p = this.point(parts[0], parts[1], parts[2]); p.causes = p.causes || []; const i = p.causes.indexOf(parts[3]); if (i >= 0) { p.causes.splice(i, 1); } else { p.causes.push(parts[3]); } this.point_save(parts[0], p); return; }
      if (a === 'cause-diag') { const c = this.form.causes || (this.form.causes = []); const i = c.indexOf(v); if (i >= 0) { c.splice(i, 1); } else { c.push(v); } this.rendre(); return; }
      if (a === 'etape') { this.allerEtape(Number(v)); return; }
      if (a === 'etape-bloque') { const E = this._etapes; this.dire('Avant de continuer : ' + ((E && E.manque) || 'cette étape n’est pas remplie') + '.'); return; }
      if (a === 'sens-mv') { const f = this.form; if (!f.seuils) { return; } const o = this.tempsOrdre(f.seuils.ordreTemps).map(t => t[0]); const i = o.indexOf(parts[0]); const j = i + Number(parts[1]);
        if (i >= 0 && j >= 0 && j < o.length) { [o[i], o[j]] = [o[j], o[i]]; f.seuils.ordreTemps = o.join(','); this.rendre(); } return; }
      if (a === 'sens-raz') { if (this.form.seuils) { this.form.seuils.ordreTemps = ''; this.rendre(); } return; }
      if (a === 'bloq-tog') { const B = this.form.seuils && this.form.seuils.etapesBloquantes; if (B) { B[v] = !this.etapeBloquante(v, B); this.rendre(); } return; }
      if (a === 'pa') { const i = Number(parts[0]); if (this.form.pa && this.form.pa[i]) { this.form.pa[i][parts[1]] = parts[2]; this.rendre(); } return; }
      if (a === 'pa-del') { this.form.pa.splice(Number(v), 1); this.rendre(); return; }
      if (a === 'pa-add') { this.form.pa.push({ cid: uuid(), titre: '', detail: '', priorite: 'P1', assigne: 'franchise', echeance: plusJours(auj(), 3) }); this.rendre(); return; }
      if (a === 'terminer') { this.terminer(v); return; }
      if (a === 'meteo') { this.meteo(v); this.rendre(); return; }
      if (a === 'sig-effacer') { this.form[v === 'sigC' ? 'sigC' : 'sig'] = null; this.rendre(); return; }
      if (a === 'ck') { this.ckF[parts[0]] = Object.assign(this.ckF[parts[0]] || {}, { etat: (this.ckF[parts[0]] || {}).etat === parts[1] ? '' : parts[1] }); this.rendre(); return; }
      if (a === 'ck-save') { this.ckSauver(v); return; }
      if (a === 'statut') { this.statut(parts[0], parts[1]); return; }
      if (a === 'msp') { this.msp(v); return; }
      if (a === 'equipe') { const f = this.form; this.ecrire({ method: 'PUT', path: '/equipe/' + encodeURIComponent(v), body: { effectif: f.effectif, prevu: f.prevu, departs: f.departs, qui: this.qui() } }); const b = this.boutique(v); if (b) { b.equipe = { effectif: f.effectif != null && f.effectif !== '' ? Number(f.effectif) : null, prevu: f.prevu ? Number(f.prevu) : null, departs: f.departs ? Number(f.departs) : null, releve_le: auj() }; } this.form = {}; this.dire('Effectif relevé.'); return; }
      if (a === 'push') { this.pushBasculer(); return; }
      if (a === 'cl-add') { const plano = this.form.checklist[Number(v)].id === 'planogramme'; this.form.checklist[Number(v)].points.push({ ref: 'p' + Date.now().toString(36), libelle: '', libelle_nl: '', photo: false, type: plano ? 'pct' : 'etat', pct: plano }); this.rendre(); return; }
      if (a === 'cl-score') { const p = this.form.checklist[Number(parts[0])].points[Number(parts[1])]; p.score = parts[2] === '1'; this.rendre(); return; }
      if (a === 'cl-poids') { const p = this.form.checklist[Number(parts[0])].points[Number(parts[1])]; p.poids = Number(parts[2]); this.rendre(); return; }
      if (a === 'clc-add') { const p = this.form.checklist[Number(parts[0])].points[Number(parts[1])]; (p.choix = p.choix || []).push({ code: 'c' + Date.now().toString(36), fr: '', nl: '' }); this.rendre(); return; }
      if (a === 'clc-del') { const p = this.form.checklist[Number(parts[0])].points[Number(parts[1])]; (p.choix || []).splice(Number(parts[2]), 1); this.rendre(); return; }
      if (a === 'clc-ko') { const c = (this.form.checklist[Number(parts[0])].points[Number(parts[1])].choix || [])[Number(parts[2])]; if (c) { c.ko = !c.ko; } this.rendre(); return; }
      if (a === 'cl-del') { this.form.checklist[Number(parts[0])].points.splice(Number(parts[1]), 1); this.rendre(); return; }
      if (a === 'cl-tog') { const p = this.form.checklist[Number(parts[0])].points[Number(parts[1])]; p[parts[2]] = !p[parts[2]]; this.rendre(); return; }
      if (a === 'se-tog') { this.form.seuils[v] = !this.form.seuils[v]; this.rendre(); return; }
      if (a === 'save-reglages') { const f = this.form; this.ecrire({ method: 'PUT', path: '/visites/reglages', body: { checklist: f.checklist, seuils: f.seuils, frequence: f.frequence } }).then(() => { this.R = null; this.form = {}; this.recharger(); }); this.dire('Réglages enregistrés.'); return; }
      if (a === 'tick') { fetch(this.api('/visites/tick'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: '{"force":true}' }).then(r => r.json()).then(j => { const f = j.fait || {}; this.dire('Horloge : ' + (f.escalades || 0) + ' escalade(s), ' + (f.rappelsJ1 || 0) + ' rappel(s) J-1, ' + (f.rappelsJour || 0) + ' du jour' + (f.synthese ? ', synthèse' : '') + (f.mails ? ', ' + f.mails + ' mail(s)' : '') + '.'); this.R = null; this.rendre(); }).catch(() => this.dire('Horloge injoignable.')); return; }
    }
    saisie(e) {
      if (this._autoT) { clearTimeout(this._autoT); this._autoT = null; }
      const el = e.target; const f = el.dataset && el.dataset.f; if (!f) { return; }
      const parts = f.split('|');
      if (parts[0] === 'form') { this.form[parts[1]] = el.value; return; }
      if (parts[0] === 'vb') { const vb = this.form.vb; if (vb) { if (parts[1] === 'p') { vb.pistes[Number(parts[2])] = el.value; } else { vb[parts[1]] = el.value; } } return; }
      if (parts[0] === 'pa') { if (this.form.pa && this.form.pa[Number(parts[1])]) { this.form.pa[Number(parts[1])][parts[2]] = el.value; } return; }
      if (parts[0] === 'ckc') { this.ckF[parts[1]] = Object.assign(this.ckF[parts[1]] || {}, { commentaire: el.value }); return; }
      if (parts[0] === 'se') { this.form.seuils[parts[1]] = el.type === 'number' ? Number(el.value) : el.value; return; }
      if (parts[0] === 'fq') { this.form.frequence[parts[1]] = el.value; return; }
      if (parts[0] === 'cl') { this.form.checklist[Number(parts[1])].points[Number(parts[2])][parts[3]] = el.value; return; }
      if (parts[0] === 'clm') { this.form.checklist[Number(parts[1])][parts[2]] = el.value; return; }
      if (parts[0] === 'clc') { const c = (this.form.checklist[Number(parts[1])].points[Number(parts[2])].choix || [])[Number(parts[3])]; if (c) { c[parts[4]] = el.value; } return; }
      if (parts[0] === 'rep-nb' || parts[0] === 'rep-tx') {
        const p = this.point(parts[1], parts[2], parts[3]); const pt = this.ptDef(parts[1], parts[3], parts[2]);
        p.reponse = parts[0] === 'rep-nb' ? (el.value === '' || !isFinite(Number(el.value)) ? null : Number(el.value)) : (el.value.trim() ? el.value : null);
        p.etat = this.etatDeReponse(pt, p.reponse);
        clearTimeout(this._pt); this._pt = setTimeout(() => this.point_save(parts[1], p), 700);
        return;
      }
      if (parts[0] === 'pct' || parts[0] === 'comm') {
        const p = this.point(parts[1], parts[2], parts[3]);
        if (parts[0] === 'pct') { p.valeur = el.value === '' ? null : Math.max(0, Math.min(100, Number(el.value))); } else { p.commentaire = el.value; }
        clearTimeout(this._pt); this._pt = setTimeout(() => this.point_save(parts[1], p), 700);
      }
    }
    change(e) {
      const el = e.target; const c = el.dataset && el.dataset.c; if (!c) { return; }
      const parts = c.split('|');
      if (parts[0] === 'form') { this.form[parts[1]] = el.value; if (parts[1] === 'type') { this.form.duree_min = ''; this.form.consultant = ''; } this.rendre(); return; }
      if (parts[0] === 'cl-type') {
        const p = this.form.checklist[Number(parts[1])].points[Number(parts[2])]; p.type = el.value; p.pct = el.value === 'pct';
        if ((el.value === 'choix' || el.value === 'multi') && !(p.choix && p.choix.length)) { const t = Date.now().toString(36); p.choix = [{ code: 'c' + t + 'a', fr: '', nl: '' }, { code: 'c' + t + 'b', fr: '', nl: '', ko: true }]; }
        this.rendre(); return;
      }
      if (parts[0] === 'moi') { this.moi = el.value; localStorage.setItem('vi.moi', this.moi); this.recharger(); return; }
      if (parts[0] === 'fichier' && el.files && el.files[0]) { const r = new FileReader(); r.onload = () => { this.form.fichier = r.result; this.dire('PDF prêt à envoyer.'); }; r.readAsDataURL(el.files[0]); }
    }

    /* --- actions ------------------------------------------------------------- */
    point(vid, ref, module) {
      let p = (this.D.points || []).find(x => String(x.visite_id) === String(vid) && x.ref === ref);
      if (!p) { const vis = this.visite(vid); const lib = (((vis ? this.checklistDe(vis) : null) || this.D.checklist || []).find(m => m.id === module) || { points: [] }).points.find(x => x.ref === ref); p = { visite_id: vid, module, ref, libelle: lib ? lib.libelle : ref, etat: '', note: null, valeur: null, commentaire: '', causes: [], reponse: null }; this.D.points.push(p); }
      return p;
    }
    point_save(vid, p) {
      const body = { points: [{ ref: p.ref, module: p.module, libelle: p.libelle, etat: p.etat || '', note: p.note, valeur: p.valeur, commentaire: p.commentaire || '', causes: p.causes || [], reponse: p.reponse == null ? null : p.reponse }] };
      const deja = this.file.find(o => o.apres === 'points' && o.path === '/visites/' + encodeURIComponent(vid) + '/points' && !o.envoi);
      if (deja) { deja.body.points = deja.body.points.filter(x => x.ref !== p.ref).concat(body.points); Idb.put('file', deja); this.rendre(); this.rejouer(); return; }
      this.ecrire({ method: 'PUT', path: '/visites/' + encodeURIComponent(vid) + '/points', body, apres: 'points' });
    }
    majVisite(id, champs) {
      const v = this.visite(id); if (v) { Object.assign(v, champs); if (champs.statut === 'en_cours' && !v.commence_a) { v.commence_a = maintenant(); } if (champs.statut === 'terminee') { v.termine_a = maintenant(); } }
      return this.ecrire({ method: 'PUT', path: '/visites/' + encodeURIComponent(id), body: Object.assign({ qui: this.qui() }, champs), apres: 'visite' });
    }
    planifier(demarrer) {
      const f = this.form; const D = this.D;
      const shop = f.shop || this.p || (D.boutiques[0] || {}).id;
      const t = this.typeDe(f.type);
      const cons = this.role === 'consultant' && this.moi ? this.moi : (f.consultant || this.consultantParDefaut(t));
      const cid = uuid();
      const body = { client_id: cid, shop, consultant: cons, consultant_nom: this.consultantNom(cons), prevu_le: f.prevu_le || plusJours(auj(), 1), debut_h: f.debut_h || '09:00', duree_min: Number(f.duree_min || (t ? t.duree : 90)), motif: f.motif || 'reguliere', type: f.type || 'reguliere', qui: this.qui() };
      D.visites.push({ id: cid, client_id: cid, shop, consultant: cons, consultantNom: body.consultant_nom, prevu_le: body.prevu_le, debut_h: body.debut_h, duree_min: body.duree_min, motif: body.motif, type: body.type, statut: 'planifiee', attente: true });
      const b = this.boutique(shop); if (b && !b.prochaineVisite) { b.prochaineVisite = { id: cid, le: body.prevu_le, h: body.debut_h, consultant: body.consultant_nom }; b.due = null; }
      const p = this.ecrire({ method: 'POST', path: '/visites', body, apres: 'visite' });
      if (demarrer) { p.then(() => { const v = this.visite(cid) || this.D.visites.find(x => x.client_id === cid); if (v) { this.majVisite(v.id, { statut: 'en_cours' }); const bb = this.boutique(shop); if (bb) { bb.visiteEnCours = v.id; } this.chargerBoutique(shop); this.go('controle', v.id); } }); this.dire('Visite ouverte.'); return; }
      this.sem = Math.round(jours(lundiDe(auj(), 0), lundiDe(body.prevu_le, 0)) / 7);
      this.go('agenda'); this.dire('Visite planifiée ' + fmtDJ(body.prevu_le) + ' ' + body.debut_h + '.');
    }
    prendrePhoto(genre, shop, visiteId, ref, planId) {
      const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/*'; inp.capture = 'environment';
      inp.onchange = async () => {
        const fichier = inp.files && inp.files[0]; if (!fichier) { return; }
        this.dire('Réduction de la photo…');
        let img; try { img = await compresser(fichier); } catch (e) { this.dire('Photo illisible.'); return; }
        const pos = await position();
        const cid = uuid();
        const body = { client_id: cid, shop, visite_id: visiteId || null, ref: ref || null, plan_id: planId || null, genre, data: img.data, prise_a: maintenant(), lat: pos ? pos.lat : null, lng: pos ? pos.lng : null, par: this.role };
        this.D.photos.unshift({ client_id: cid, visite_id: visiteId || null, shop, plan_id: planId || null, ref: ref || null, genre, data: img.data, prise_a: body.prise_a, attente: true });
        const p = this.ecrire({ method: 'POST', path: '/visites/photos', body, apres: 'photo' });
        if (genre === 'correction' && planId) {
          const plan = this.plan(planId); if (plan) { plan.statut = 'attente'; plan.photo_client_id = cid; plan.attente = true; plan.maj_le = maintenant(); }
          p.then(() => this.ecrire({ method: 'PUT', path: '/plans/' + encodeURIComponent(planId), body: { statut: 'attente', photo_client_id: cid, role: this.role, qui: this.qui(), commentaire: 'photo de la correction' }, apres: 'plan' }));
          this.dire('Photo envoyée : en attente de validation.');
        } else { this.dire('Photo prise' + (this.enLigne ? '' : ' — envoyée au retour du réseau') + '.'); }
      };
      inp.click();
    }
    /**
     * Terminer : la visite se ferme, rien ne part au franchisé. Les points sont
     * PROPOSÉS, le brouillon du rapport attend au bureau, qui l'adapte et l'envoie.
     * La météo remplie et la signature partent avec.
     */
    terminer(vid) {
      const v = this.visite(vid); if (!v) { return; }
      const f = this.form;
      if (this.vbExige(v) === 'manque') {
        const E = this._etapes; if (E && E.pistes >= 0) { this.ouvert[E.cle] = E.pistes; }
        this.dire('Le CA est à plus de ' + String(this.VB[v.shop].seuil).replace('.', ',') + ' % sous le budget : notez d’abord pourquoi, les trois pistes du franchisé et l’horizon.');
        this.rendre(); return;
      }
      const plans = (f.pa || []).filter(a => a.titre && a.titre.trim()).map(a => ({ client_id: a.cid, shop: v.shop, visite_id: v.id, ref: a.ref || null, titre: a.titre.trim(), detail: a.detail || '', priorite: a.priorite, assigne: a.assigne, echeance: a.echeance || null, campagne_id: Number(a.campagne_id) > 0 ? Number(a.campagne_id) : null, statut: 'propose' }));
      if (plans.length) {
        plans.forEach(p => this.D.plans.push(Object.assign({ id: p.client_id, cree_par: this.qui(), cree_le: maintenant(), maj_le: maintenant(), retard: 0, age: 0, attente: true }, p)));
        this.ecrire({ method: 'POST', path: '/plans', body: { plans, qui: this.qui() }, apres: 'plans' });
      }
      if (!f.mt_ok) { this.meteo(vid, true); }
      // Les deux signatures : le franchisé (genre « signature », comme avant) et le consultant (« sig_consultant »).
      if (f.sigC) {
        const cidC = uuid();
        this.D.photos.unshift({ client_id: cidC, visite_id: v.id, shop: v.shop, genre: 'sig_consultant', data: f.sigC, prise_a: maintenant(), attente: true });
        this.ecrire({ method: 'POST', path: '/visites/photos', body: { client_id: cidC, shop: v.shop, visite_id: v.id, genre: 'sig_consultant', data: f.sigC, prise_a: maintenant(), par: this.role }, apres: 'photo' });
      }
      if (f.sig) {
        const cid = uuid();
        this.D.photos.unshift({ client_id: cid, visite_id: v.id, shop: v.shop, genre: 'signature', data: f.sig, prise_a: maintenant(), attente: true });
        this.ecrire({ method: 'POST', path: '/visites/photos', body: { client_id: cid, shop: v.shop, visite_id: v.id, genre: 'signature', data: f.sig, prise_a: maintenant(), par: this.role }, apres: 'photo' });
      }
      this.majVisite(vid, { statut: 'terminee', sentiment: f.sentiment || null, meteo: f.meteo || null, positif: f.positif || '', notes: f.notes || '', execution: f.execution || '', clients: f.clients || '', causes: f.causes || [], diagnostic: f.diagnostic || '', reco: f.reco || '' });
      const b = this.boutique(v.shop); if (b) { b.visiteEnCours = null; b.derniereVisite = { id: v.id, le: v.prevu_le, consultant: v.consultantNom }; if (b.prochaineVisite && String(b.prochaineVisite.id) === String(v.id)) { b.prochaineVisite = null; } }
      v.rapportStatut = 'brouillon';
      this.form = {};
      this.go(this.o.tablette ? 'shops' : 'historique', v.shop);
      this.dire('Visite terminée · le brouillon part au bureau' + (plans.length ? ' avec ' + plans.length + ' point' + (plans.length > 1 ? 's' : '') + ' proposé' + (plans.length > 1 ? 's' : '') : '') + '.');
    }
    /** La météo du franchisé : moral, envie, équipe, relation, ses demandes (chacune devient une tâche du consultant à J+7). */
    meteo(vid, silencieux) {
      const v = this.visite(vid); if (!v) { return false; } const f = this.form;
      const body = { client_id: f.mt_cid || (f.mt_cid = uuid()), shop: v.shop, consultant: this.moi || v.consultant || '', qui: this.qui(),
        demandes: f.mt_demandes || '', envies: f.mt_envies || '', inquietudes: f.mt_inquietudes || '', note: f.mt_note || '' };
      METEO_ECH.forEach(([k]) => { body[k] = f['mt_' + k] || null; });
      const vide = !METEO_ECH.some(([k]) => body[k]) && !['demandes', 'envies', 'inquietudes', 'note'].some(k => body[k].trim());
      if (vide) { if (!silencieux) { this.dire('La météo est vide.'); } return false; }
      this.ecrire({ method: 'POST', path: '/franchises/meteo', body });
      f.mt_ok = true; if (!silencieux) { this.dire('Météo enregistrée.'); }
      return true;
    }
    /* --- le glisser-déposer du sens du contrôle (réglages), au doigt ou à la souris ------------ */
    ecouterGlisse(host) {
      host.addEventListener('pointerdown', e => {
        const h = e.target.closest && e.target.closest('.gl-h'); if (!h) { return; }
        const l = h.closest('.sens-l'); const box = l && l.parentElement; if (!l || !box) { return; }
        e.preventDefault(); try { h.setPointerCapture(e.pointerId); } catch (x) { /* rien */ }
        l.classList.add('glisse'); this._gl = { l, box };
      });
      host.addEventListener('pointermove', e => {
        const g = this._gl; if (!g) { return; } e.preventDefault();
        const autres = [...g.box.querySelectorAll('.sens-l')].filter(x => x !== g.l);
        const apres = autres.find(x => { const r = x.getBoundingClientRect(); return e.clientY < r.top + r.height / 2; });
        if (apres) { g.box.insertBefore(g.l, apres); } else { g.box.appendChild(g.l); }
      });
      const fin = () => { const g = this._gl; if (!g) { return; } this._gl = null; g.l.classList.remove('glisse');
        if (this.form.seuils) { this.form.seuils.ordreTemps = [...g.box.querySelectorAll('.sens-l')].map(x => x.dataset.k).join(','); } this.rendre(); };
      host.addEventListener('pointerup', fin); host.addEventListener('pointercancel', fin);
    }
    /* --- la signature au doigt, sur la dernière étape -------------------------------- */
    ecouterSignature(host) {
      host.addEventListener('pointerdown', e => {
        const c = e.target; if (!c || !c.dataset || !c.dataset.sig) { return; }
        e.preventDefault(); if (c.setPointerCapture) { try { c.setPointerCapture(e.pointerId); } catch (x) { /* rien */ } }
        const r = c.getBoundingClientRect(); const x = c.getContext('2d');
        this._sig = { c, x, r, k: c.dataset.sig }; x.lineWidth = 2.6; x.lineCap = 'round'; x.lineJoin = 'round'; x.strokeStyle = '#222';
        x.beginPath(); x.moveTo((e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height);
      });
      host.addEventListener('pointermove', e => { const s = this._sig; if (!s) { return; } e.preventDefault(); s.x.lineTo((e.clientX - s.r.left) * s.c.width / s.r.width, (e.clientY - s.r.top) * s.c.height / s.r.height); s.x.stroke(); });
      const fin = () => { const s = this._sig; if (!s) { return; } this._sig = null; this.form[s.k] = s.c.toDataURL('image/png'); this.rendre(); };
      host.addEventListener('pointerup', fin); host.addEventListener('pointercancel', fin);
    }
    /* --- le dossier du bureau, la checklist du consultant, la météo, la fin ----------- */
    dossierHtml(v) {
      const P = v.prep;
      if (!P) { return '<div class="card sm mu">Le bureau n’a pas encore envoyé le dossier de cette visite. Les chiffres du magasin sont à l’étape suivante.</div>'; }
      const quand = P.figeLe || v.prepEnvoyeeLe;
      return (P.points.length ? `<div class="cap">Points demandés par le bureau · ${P.points.length}</div><div class="card sm">${P.points.map(p => '· ' + esc(p.libelle) + (p.odj ? '' : ' <span class="xs mu">(pas dans l’ordre du jour)</span>')).join('<br>')}<div class="xs mu" style="margin-top:4px">Ils ouvrent la checklist.</div></div>` : '')
        + (P.note ? `<div class="card sm">${esc(P.note)}</div>` : '')
        + (P.dossier || []).map(d => `<div class="card ${d.alerte ? 'alerte' : ''}"><div class="row"><b class="sm">${esc(d.titre)}</b>${d.interne ? '<span class="pill int">interne</span>' : ''}</div>${(d.lignes || []).length ? '<div class="sm" style="margin-top:4px">' + d.lignes.map(l => '· ' + esc(l)).join('<br>') + '</div>' : '<div class="sm mu">—</div>'}</div>`).join('')
        + `<div class="xs mu" style="margin:2px 2px 0">Préparé le ${fmtDJ(quand)} ${fmtH(quand)} · ${v.annoncee ? 'visite annoncée' : 'visite inopinée : le franchisé n’a pas été prévenu'}</div>`;
    }
    async chargerCk(shop, force) {
      if (!force && this.CK && this.CK.shop === shop) { return; }
      this.CK = { shop, chargement: true }; this.ckF = this.ckF || {};
      const mid = String(this.moi || '').replace(/^u/, '');
      try { const d = await this.lire('/consultants/checklists?consultant=' + encodeURIComponent(mid) + '&shop=' + encodeURIComponent(shop), 'ck:' + shop + ':' + mid); this.CK = { shop, d }; }
      catch (e) { this.CK = { shop, erreur: true }; }
      this.rendre();
    }
    ckEtat() {
      const C = this.CK;
      if (!C || C.chargement) { return { etat: 'lecture…', fait: false }; }
      if (C.erreur || !C.d || !C.d.lu) { return { etat: 'non lue', fait: true }; }
      const ts = []; (C.d.checklists || []).forEach(c => (c.taches || []).forEach(t => ts.push(t)));
      const sa = C.d.saisies || {}; const faites = ts.filter(t => (this.ckF[t.id] || {}).etat || sa[t.id]).length;
      return { etat: faites + ' / ' + ts.length, fait: ts.length > 0 && faites >= ts.length };
    }
    ckHtml(v) {
      const C = this.CK; this.ckF = this.ckF || {};
      if (!C || C.chargement) { return '<div class="card sm mu">Lecture de la checklist du consultant dans le panel…</div>'; }
      if (C.erreur || !C.d) { return '<div class="card sm mu">Le panel ne répond pas : la checklist du consultant se remplira au retour du réseau.</div>'; }
      if (!C.d.lu) { return `<div class="card sm mu">La checklist du consultant se lit dans le panel : ${esc(C.d.motif || 'indisponible')}.</div>`; }
      const sa = C.d.saisies || {};
      return (C.d.checklists || []).map(c => `<div class="cap">${esc(c.nom)}</div><div class="card">${(c.taches || []).map(t => { const e = (this.ckF[t.id] || {}).etat || (sa[t.id] || {}).etat || ''; const com = (this.ckF[t.id] || {}).commentaire != null ? this.ckF[t.id].commentaire : ((sa[t.id] || {}).commentaire || ''); return `<div class="pt"><div class="l1"><b class="sm" style="flex:1">${esc(t.nom)}${t.obligatoire ? ' <span class="pill P0">obligatoire</span>' : ''}</b></div>${t.description ? `<div class="xs mu">${esc(t.description)}</div>` : ''}<div class="act"><span class="ckb">${[['fait', 'Fait'], ['pas_fait', 'Pas fait'], ['na', 'N/A']].map(([k, l]) => `<button class="${e === k ? k : ''}" data-a="ck" data-v="${esc(t.id)}|${k}">${l}</button>`).join('')}</span><input type="text" data-f="ckc|${esc(t.id)}" value="${esc(com)}" placeholder="Commentaire…" style="flex:1;min-width:120px;padding:5px 8px;font-size:12px"></div>${(sa[t.id] || {}).panel ? `<div class="xs mu">Panel : ${esc(sa[t.id].panel)}</div>` : ''}</div>`; }).join('')}</div>`).join('')
        + `<div class="btns"><button class="btn w" data-a="ck-save" data-v="${esc(v.id)}">💾 Enregistrer la checklist${C.d.moi ? ' · les tâches faites partent au panel' : ''}</button></div>`;
    }
    ckSauver(vid) {
      const v = this.visite(vid); const C = this.CK; if (!v || !C || !C.d || !C.d.lu) { return; }
      const ts = []; (C.d.checklists || []).forEach(c => (c.taches || []).forEach(t => { const x = this.ckF[t.id]; if (x && x.etat) { ts.push({ task_id: t.id, nom: t.nom, etat: x.etat, commentaire: x.commentaire || '' }); } }));
      if (!ts.length) { this.dire('Rien de rempli.'); return; }
      this.ecrire({ method: 'POST', path: '/consultants/checklists', body: { shop: v.shop, consultant: C.d.choisi, consultant_nom: this.consultantNom(this.moi), moi: !!C.d.moi, panel: true, taches: ts, qui: this.qui() } });
      ts.forEach(t => { (C.d.saisies = C.d.saisies || {})[t.task_id] = { etat: t.etat, commentaire: t.commentaire }; });
      this.dire(ts.length + ' tâche(s) de la checklist enregistrée(s).');
    }
    meteoDetailHtml(v) {
      const f = this.form;
      return `<div class="cap">Le détail, si besoin</div><div class="card sm mu">Reste entre le consultant et le bureau, jamais dans le rapport ; chaque demande devient une tâche du consultant à J+7.</div>
        <div class="card">${METEO_ECH.map(([k, l]) => `<div class="row" style="margin:5px 0"><span class="sm" style="flex:1">${l}</span><span class="notes">${[1, 2, 3, 4, 5].map(n => `<button data-a="form" data-v="mt_${k}|${n}" class="${f['mt_' + k] === n ? 'on' : ''}" title="${n} / 5" style="width:34px;height:30px;font-size:15px">${METEO_I[n]}</button>`).join('')}</span></div>`).join('')}
          <textarea data-f="form|mt_demandes" placeholder="Ses demandes — une par ligne : chacune devient une tâche">${esc(f.mt_demandes || '')}</textarea>
          <textarea data-f="form|mt_envies" placeholder="Ses envies, ses projets" style="margin-top:6px">${esc(f.mt_envies || '')}</textarea>
          <textarea data-f="form|mt_inquietudes" placeholder="Ses inquiétudes, ce qui bloque" style="margin-top:6px">${esc(f.mt_inquietudes || '')}</textarea>
          <textarea data-f="form|mt_note" placeholder="Ce que j’en retiens — pour le consultant seulement" style="margin-top:6px">${esc(f.mt_note || '')}</textarea></div>
        ${f.mt_ok ? '<div class="card sm up">Météo enregistrée.</div>' : `<div class="btns"><button class="btn w" data-a="meteo" data-v="${esc(v.id)}">☀️ Enregistrer la météo</button></div>`}`;
    }
    /**
     * Le score d'une visite (26/10/2026, maquette A) — LA MÊME RÈGLE que viScore (src/visites_questions.php), à garder
     * identiques : Σ poids × réussite / Σ poids sur 100, sur les questions qui comptent et qui ont une réponse ; une note
     * au prorata, un pourcentage pour sa valeur ; hors score le texte libre, une question qui ne compte pas, N/A, sans
     * réponse ; les produits obligatoires comptent ensemble pour une question. Pas de score pour un suivi ou une revisite.
     */
    scoreDe(v) {
      if (!v) { return null; }
      const t = this.typeDe(v.type); if (t && t.dynamique) { return null; }
      const base = t && t.checklist && t.checklist.length ? t.checklist : (this.D.checklist || []);
      const HORS = { xp: [MOD_XP.nom, MOD_XP.nom_nl], leviers: [MOD_LEVIERS.nom, MOD_LEVIERS.nom_nl], bureau: ['Demandé par le bureau', 'Gevraagd door het kantoor'], exterieur: ['L’extérieur', 'De buitenkant'] };
      const defs = {}, noms = {}, ordre = [];
      base.forEach(m => { noms[m.id] = [m.nom, m.nom_nl || '']; ordre.push(m.id); (m.points || []).forEach(pt => { defs[pt.ref] = pt; }); });
      const reussite = (def, p) => {
        const ty = def.type || (def.pct ? 'pct' : 'etat');
        if (ty === 'texte' || def.score === false) { return null; }
        if (p.etat === 'na') { return null; }
        if (ty === 'note' && p.note != null) { return Math.max(0, Math.min(1, (p.note - 1) / 4)); }
        if (ty === 'pct' && p.valeur != null) { return Math.max(0, Math.min(1, p.valeur / 100)); }
        if (p.etat === 'ok') { return 1; }
        if (p.etat === 'ko') { return 0; }
        if (p.note != null) { return Math.max(0, Math.min(1, (p.note - 1) / 4)); }
        return null;
      };
      const par = {}, obl = {}, vus = [];
      (this.D.points || []).filter(p => String(p.visite_id) === String(v.id)).forEach(p => {
        if (!p.ref || !p.module) { return; }
        if (String(p.ref).indexOf('oblig_') === 0) { if (p.etat === 'ok' || p.etat === 'ko') { const o = obl[p.module] || (obl[p.module] = { ok: 0, ko: 0 }); o[p.etat]++; } return; }
        const def = defs[p.ref] || {}; const r = reussite(def, p); if (r == null) { return; }
        const w = [1, 2, 3].indexOf(Number(def.poids)) >= 0 ? Number(def.poids) : 1;
        (par[p.module] || (par[p.module] = [], vus.push(p.module), par[p.module])).push([w, r]);
      });
      Object.keys(obl).forEach(mod => { const n = obl[mod].ok + obl[mod].ko; if (n) { if (!par[mod]) { par[mod] = []; vus.push(mod); } par[mod].push([1, obl[mod].ok / n]); } });
      const somme = l => l.reduce((a, [w, r]) => [a[0] + w, a[1] + w * r], [0, 0]);
      const modules = []; let tous = [];
      ordre.concat(vus.filter(m => ordre.indexOf(m) < 0)).forEach(id => {
        if (!par[id] || !par[id].length) { return; }
        const [w, x] = somme(par[id]); const nom = noms[id] || HORS[id] || [id, ''];
        modules.push({ id, nom: nom[0], nom_nl: nom[1], score: Math.round(x / w * 100), n: par[id].length }); tous = tous.concat(par[id]);
      });
      if (!tous.length) { return null; }
      const [w, x] = somme(tous);
      return { score: Math.round(x / w * 100), n: tous.length, modules };
    }
    /** La visite d'avant, du même type (les mêmes questions), et son score : figé par le serveur s'il l'a été, sinon calculé ici. */
    scorePrecedent(v) {
      const av = (this.D.visites || []).filter(x => String(x.shop) === String(v.shop) && x.statut === 'terminee' && String(x.id) !== String(v.id) && x.prevu_le <= v.prevu_le && (x.type || 'reguliere') === (v.type || 'reguliere'))
        .sort((a, b) => a.prevu_le < b.prevu_le ? 1 : (a.prevu_le > b.prevu_le ? -1 : Number(b.id) - Number(a.id)));
      for (const x of av) { const s = x.score && x.score.score != null ? x.score : this.scoreDe(x); if (s) { return { le: x.prevu_le, score: s }; } }
      return null;
    }
    /**
     * Avant de signer (26/10/2026, Sam : « comparaison avant signature avec visite précédente et visite actuelle ») : le
     * seul endroit de la tablette où le score se montre. Dans la langue de la visite.
     */
    scoreFinHtml(v) {
      const a = this.scoreDe(v); if (!a) { return ''; }
      const nl = this.lngDe(v) === 'nl';
      const P = this.scorePrecedent(v); const p = P ? P.score : null;
      const avant = {}; ((p && p.modules) || []).forEach(m => { avant[m.id] = m.score; });
      const ec = d => d == null ? '<span class="mu">—</span>' : `<span class="${d > 0 ? 'up' : d < 0 ? 'dn' : 'mu'}">${d > 0 ? '+' : d < 0 ? '−' : '±'}${Math.abs(d)}</span>`;
      const ton = x => x >= 85 ? 'ok' : x >= 70 ? 'att' : 'ko';
      return `<div class="card sc-fin"><div class="row"><b>${nl ? 'Score van het bezoek' : 'Le score de la visite'}</b><span class="sp"></span><span class="xs mu">${nl ? 'getoond vóór de handtekening · daarna in het verslag' : 'montré avant de signer · puis dans le rapport'}</span></div>
        <div class="sc-duo"><div class="sc-c"><small>${nl ? 'Vorig bezoek' : 'Visite précédente'}${P ? ' · ' + String(P.le).slice(8, 10) + '/' + String(P.le).slice(5, 7) : ''}</small><div class="sc-n"><b>${p ? p.score : '—'}</b>${p ? '' : `<span class="xs mu">${nl ? 'geen eerder gescoord bezoek' : 'pas de visite précédente notée'}</span>`}</div></div>
          <div class="sc-fl">→</div><div class="sc-c act"><small>${nl ? 'Dit bezoek' : 'Cette visite'}</small><div class="sc-n"><b class="${ton(a.score)}">${a.score}</b>${p ? ec(a.score - p.score) : ''}</div></div></div>
        <div class="sc-mods"><span class="h">${nl ? 'Module' : 'Module'}</span><span class="h n">${nl ? 'Vorig' : 'Précédente'}</span><span class="h n">${nl ? 'Nu' : 'Actuelle'}</span><span class="h n">${nl ? 'Verschil' : 'Écart'}</span>
          ${a.modules.map(m => `<span>${esc(nomDe(m, nl ? 'nl' : 'fr'))}</span><span class="n mu">${avant[m.id] != null ? avant[m.id] : '—'}</span><span class="n"><b class="${ton(m.score)}">${m.score}</b></span><span class="n">${avant[m.id] != null ? ec(m.score - avant[m.id]) : ''}</span>`).join('')}</div>
        <div class="xs mu" style="margin-top:6px">${nl ? 'Op ' + a.n + ' gescoorde vragen: elk punt in orde telt volgens zijn gewicht. Tellen niet mee: N.v.t., vrije tekst, vragen buiten de score.' : 'Sur ' + a.n + ' questions notées : chaque point conforme compte selon son poids. Ne comptent pas : N/A, texte libre, questions hors score.'}</div></div>`;
    }
    finHtml(v) {
      const pts = this.pointsDe(v.id); let total = 0, faits = 0, ko = 0;
      this.checklistDe(v).forEach(m => m.points.forEach(pt => { total++; const p = pts[pt.ref]; if (p && (p.etat || p.note != null || p.valeur != null)) { faits++; if (p.etat === 'ko' || (p.note != null && p.note <= 2)) { ko++; } } }));
      const pa = (this.form.pa || []).filter(a => a.titre && a.titre.trim());
      const h = this.D.delaiRapport;
      return `<div class="stat"><div><div class="xs mu">Checklist</div><div class="k" style="margin-top:4px">${faits} / ${total}</div><div class="xs mu">${ko} non conforme${ko > 1 ? 's' : ''}</div></div><div><div class="xs mu">Points proposés</div><div class="k" style="margin-top:4px">${pa.length}</div><div class="xs mu">${pa.filter(a => a.priorite === 'P0').length} P0</div></div><div><div class="xs mu">Photos</div><div class="k" style="margin-top:4px">${this.photosDe(v.id).length}</div><div class="xs mu">${this.file.filter(o => o.apres === 'photo').length} en attente d’envoi</div></div></div>
        ${this.vbExige(v) === 'manque' ? `<div class="card alerte sm"><b class="dn">CA à plus de ${String(this.VB[v.shop].seuil).replace('.', ',')} % sous le budget</b> — le pourquoi, les trois pistes du franchisé et l’horizon restent à noter au développement. <button class="chip" data-a="etape" data-v="${this._etapes && this._etapes.pistes >= 0 ? this._etapes.pistes : 0}">Y aller</button></div>` : ''}
        ${this.scoreFinHtml(v)}
        <div class="sigs">${[['sigC', 'Le consultant', v.consultantNom || this.consultantNom(this.moi) || ''], ['sig', 'Le franchisé a vu le résumé', (this.boutique(v.shop) || {}).court || '']].map(([k, lab, qui]) => `<div class="sg">
          <div class="cap">${lab}${qui ? ' · ' + esc(qui) : ''} ${this.form[k] ? '<span class="pill st-val">signé</span>' : ''}<button class="chip" data-a="sig-effacer" data-v="${k}" style="margin-left:auto">effacer</button></div>
          <canvas data-sig="${k}" class="sig" width="640" height="180" aria-label="${k === 'sig' ? 'Signature du franchisé' : 'Signature du consultant'}"></canvas></div>`).join('')}</div>
        <div class="card sm mu" style="margin-top:8px">Terminer n’envoie rien au franchisé : le brouillon du rapport part au bureau, qui le relit et l’envoie${h ? ' sous ' + h + ' h' : ''}. Les points proposés ne s’ouvrent chez lui qu’à ce moment-là.</div>
        <div class="btns"><button class="btn p w" data-a="terminer" data-v="${esc(v.id)}">💾 Terminer · le brouillon part au bureau</button></div>`;
    }
    statut(id, vers) {
      const p = this.plan(id); if (!p) { return; }
      const f = this.form;
      const body = { statut: vers, role: this.role, qui: this.qui() };
      if (vers === 'reprendre') { body.retour = f.retour || ''; body.echeance = f.echeance || plusJours(auj(), 2); }
      if (vers === 'escalade') { body.escalade_motif = f.motif || ''; }
      Object.assign(p, { statut: vers, attente: true, maj_le: maintenant() }, vers === 'reprendre' ? { retour: body.retour, echeance: body.echeance } : {}, vers === 'escalade' ? { escalade_motif: body.escalade_motif } : {}, vers === 'ferme' ? { ferme_le: maintenant() } : {});
      // Seulement les champs du renvoi et de l'escalade : pendant un contrôle, le reste du formulaire (points proposés,
      // pistes, signatures) doit rester (26/10/2026).
      ['rep', 'esc', 'retour', 'echeance', 'motif'].forEach(k => { delete f[k]; });
      this.ecrire({ method: 'PUT', path: '/plans/' + encodeURIComponent(id), body, apres: 'plan' });
      this.dire({ valide: 'Correction acceptée.', reprendre: 'Renvoyé au franchisé.', ferme: 'Fermé.', escalade: 'Escaladé à Sam.', ouvert: 'Rouvert.', attente: 'En attente.' }[vers] || 'Enregistré.');
    }
    msp(shop) {
      const f = this.form; const rub = {};
      RUBRIQUES_MSP.forEach(r => { if (f['r_' + r] !== undefined && f['r_' + r] !== '') { rub[r] = Number(f['r_' + r]); } });
      if (!Object.keys(rub).length) { this.dire('Saisissez au moins une rubrique.'); return; }
      const mois = f.mois || auj().slice(0, 7);
      const total = Math.round(Object.values(rub).reduce((a, c) => a + c, 0) / Object.keys(rub).length * 10) / 10;
      const b = this.boutique(shop); if (b) { const m = { shop, mois, total, rubriques: rub, commentaires: f.commentaires || '', par: this.qui(), le: maintenant() }; this.D.msp[shop] = [m].concat((this.D.msp[shop] || []).filter(x => x.mois !== mois)); b.msp = m; }
      this.ecrire({ method: 'POST', path: '/msp', body: { shop, mois, rubriques: rub, commentaires: f.commentaires || '', fichier: f.fichier || null, qui: this.qui() } });
      this.form = {}; this.dire('Rapport MSP enregistré.');
    }

    /* --- notifications push (téléphone) --------------------------------------- */
    async pushBasculer() {
      if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { this.pushMotif = window.isSecureContext === false ? 'les notifications demandent une connexion sécurisée (https)' : 'ce navigateur ne sait pas recevoir de notifications'; this.rendre(); return; }
      try {
        const reg = await navigator.serviceWorker.register('sw.js', { scope: './' }); await navigator.serviceWorker.ready;
        const deja = await reg.pushManager.getSubscription();
        if (deja) { await fetch(this.api('/push/abonnements'), { method: 'DELETE', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: deja.endpoint }) }).catch(() => {}); await deja.unsubscribe().catch(() => {}); this.pushEtat = 'possible'; this.dire('Notifications désactivées.'); this.rendre(); return; }
        const perm = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
        if (perm !== 'granted') { this.pushMotif = 'permission refusée'; this.rendre(); return; }
        const c = await (await fetch(this.api('/push/cle'), { credentials: 'same-origin' })).json();
        if (!c || !c.pret) { this.pushMotif = (c && c.motif) || 'le serveur n’est pas prêt'; this.rendre(); return; }
        const b64 = c.cle; const pad = '='.repeat((4 - b64.length % 4) % 4); const t = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/')); const u = new Uint8Array(t.length); for (let i = 0; i < t.length; i++) { u[i] = t.charCodeAt(i); }
        const ab = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: u }); const j = ab.toJSON();
        const cible = this.role === 'franchise' ? this.shop : this.role === 'admin' ? 'admin' : 'c:' + this.moi;
        const r = await fetch(this.api('/push/abonnements'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ shop: cible, endpoint: ab.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth }) });
        this.pushEtat = r.ok ? 'abonne' : 'possible'; this.pushMotif = r.ok ? '' : 'le serveur a refusé l’abonnement';
        this.dire(r.ok ? 'Notifications activées.' : 'Abonnement refusé.');
      } catch (e) { this.pushMotif = e.message || String(e); }
      this.rendre();
    }
  }

  const instances = new Map();
  window.CockpitVisites = {
    mount(host, opts) {
      if (!host) { return null; }
      const cle = (opts.role || 'consultant') + '|' + (opts.shop || '') + '|' + (opts.vue || '');
      let h = instances.get(cle);
      if (h && h.host === host) { h.rendre(); return h; }
      if (h && !opts.mobile) { h.host = host; host.innerHTML = '<div class="vi desk"></div>'; host.addEventListener('click', e => h.clic(e)); host.addEventListener('input', e => h.saisie(e)); host.addEventListener('change', e => h.change(e)); h.ecouterSignature(host); h.ecouterGlisse(host); h.v = opts.vue || h.v; h.p = null; h.rendre(); if (h.D) { h.recharger(); } return h; }
      h = new Hote(host, opts); instances.set(cle, h); return h;
    },
    compresser, Idb,
  };
})();
