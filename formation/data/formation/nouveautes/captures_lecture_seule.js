// Captures en lecture seule du dashboard en ligne (Halle, shop=4), 1600×1200, bloc entouré en bordeaux.
// garde.js : seule la redirection http→https est neutralisée (le https du serveur coupe la connexion ; l'API répond « ouvert ») ;
// toute requête autre que GET vers l'API est bloquée : rien n'est écrit.
const { chromium } = require('playwright'); const path=require('path');
const OUT=process.argv[2]; const SEULES=process.argv.slice(3);
const W=1200,H=900;
const flouHist = async p => p.evaluate(()=>{document.querySelectorAll('.hd-hist .rc-carte').forEach(c=>[...c.children].forEach((e,i)=>{ if(i>1) e.style.filter='blur(6px)'; }));});
const ouvrirDemande = async p => { await p.locator('[data-iaouvrir="d"]').first().click(); await p.waitForTimeout(4000); };
const PLANS=[
 {nom:'art-dash-frais-onglet', vue:'frais', cibles:['.sidebar__item[data-vue="frais"]','.fx-nav'], top:true},
 {nom:'art-dash-frais-tuiles', vue:'frais', cibles:['.fx-tuiles'], top:true},
 {nom:'art-dash-frais-tableau', vue:'frais', cibles:['.fx-t']},
 {nom:'art-dash-frais-ajout', vue:'frais', cibles:['.fx-aj']},
 {nom:'art-dash-frais-pnl', vue:'frais', cibles:['.fx-g2']},
 {nom:'art-demandes-bureau-bouton', vue:'ops', cibles:['[data-iaouvrir="d"]:visible'], top:true},
 {nom:'art-demandes-bureau-formulaire', vue:'ops', avant:async p=>{await ouvrirDemande(p); await flouHist(p);}, cibles:['.hd-form'], top:true, pasDeScroll:true},
 {nom:'art-demandes-bureau-liste', vue:'ops', avant:async p=>{await ouvrirDemande(p); await flouHist(p);}, cibles:['.hd-hist'], top:true, pasDeScroll:true},
 {nom:'art-boite-mail-relier', vue:'boite', cibles:['.sidebar__item[data-vue="boite"]','.bx-porte-c'], top:true},
];
(async()=>{const b=await chromium.launch();const ctx=await b.newContext({viewport:{width:W,height:H},deviceScaleFactor:4/3});
await ctx.route('**/assets/js/garde.js', async r=>{const res=await r.fetch();let t=await res.text();t=t.replace("location.protocol === 'http:' && !local","false");await r.fulfill({response:res,body:t});});
await ctx.route('**/api/**', r=>r.request().method()==='GET'?r.continue():r.abort());
for(const pl of PLANS){ if(SEULES.length&&!SEULES.includes(pl.nom)) continue;
 const p=await ctx.newPage(); await p.goto('http://185.180.206.46/consulant_bo/dashboard/?shop=4&vue='+pl.vue,{waitUntil:'networkidle',timeout:120000}).catch(()=>{}); await p.waitForTimeout(6000);
 if(pl.avant) await pl.avant(p);
 const boites=[];
 const sels=[...pl.cibles]; 
 for(const s of sels){ try{const l=p.locator(s).first(); await l.waitFor({state:'visible',timeout:15000}); await l.evaluate(el=>{el.style.outline='4px solid #8D1D2C';el.style.outlineOffset='5px';el.style.borderRadius=el.style.borderRadius||'10px';}); boites.push(await l.boundingBox());}catch(e){console.log(pl.nom,'absent',s);} }
 if(pl.cadre){ try{const l=await pl.cadre(p); await l.evaluate(el=>{el.style.outline='4px solid #8D1D2C';el.style.outlineOffset='5px';}); }catch(e){console.log(pl.nom,'cadre absent',e.message.slice(0,80));} }
 let y=0;
 if(!pl.top && boites.length && !pl.pasDeScroll){ const hP=await p.evaluate(()=>document.documentElement.scrollHeight); const top=Math.min(...boites.map(b=>b.y)), bas=Math.max(...boites.map(b=>b.y+b.height)); const h=bas-top; y=h+40>=H?top-30:top-(H-h)/2; y=Math.max(0,Math.min(y,hP-H)); }
 const opts={path:path.join(OUT,pl.nom+'.jpg'),type:'jpeg',quality:82};
 if(pl.pasDeScroll) await p.screenshot(opts); else await p.screenshot({...opts,fullPage:true,clip:{x:0,y,width:W,height:H}});
 console.log('ok',pl.nom,'y='+Math.round(y)); await p.close(); }
await b.close();})();
