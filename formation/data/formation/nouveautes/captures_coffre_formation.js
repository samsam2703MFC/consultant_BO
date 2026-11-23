// Captures lecture seule (modèle : formation/data/formation/nouveautes/captures_lecture_seule.js), 1600×1200, bloc entouré.
const { chromium } = require('playwright'); const path=require('path');
const OUT=process.argv[2]; const SEULES=process.argv.slice(3);
const W=1200,H=900;
const clic = s => async p => { await p.locator(s).first().click(); await p.waitForTimeout(2500); };
const PLANS=[
 {nom:'art-dash-coffre-lien', vue:'ops', cibles:['.db-rail-coffre'], pasDeScroll:true, dedans:true},
 {nom:'art-dash-formation-bouton', vue:'ops', cibles:['[data-fcouvrir]:visible'], pasDeScroll:true, dedans:true},
 {nom:'art-dash-coffre-etat', vue:'coffre', cibles:['.cf2-barre','.cf2-etat'], top:true},
 {nom:'art-dash-coffre-contenu', vue:'coffre', cibles:['.cf2-dos','.cf2-cont'], },
 {nom:'art-dash-coffre-legal', vue:'coffre', avant:clic('.cf2-d[data-cf-dos="legal"]'), cibles:['.cf2-cont'], },
 {nom:'art-dash-coffre-depot', vue:'coffre', avant:clic('.cf2-barre [data-cf-depot=""]'), cibles:['.cf2-mod'], pasDeScroll:true},
 {nom:'art-dash-coffre-dates', vue:'coffre', avant:clic('.cf2-d[data-cf-dos="dates"]'), cibles:['.cf2-cont'], pasDeScroll:true},
 {nom:'art-dash-formation-accueil', vue:'formation', cibles:['.sidebar__item[data-vue="formation"]','.fs-acc-h'], top:true},
 {nom:'art-dash-formation-recherche', vue:'formation', avant:async p=>{const i=p.locator('[data-sf-q]').first(); await i.click(); await i.type('croissant',{delay:60}); await p.waitForTimeout(3000);}, cibles:['.fs-sf'], top:true, pasDeScroll:true},
 {nom:'art-dash-formation-dossier', vue:'formation', avant:clic('[data-fs-vue="dossier"]'), cibles:['.fs-lay'], top:true},
];
(async()=>{const b=await chromium.launch();const ctx=await b.newContext({viewport:{width:W,height:H},deviceScaleFactor:4/3});
await ctx.route('**/assets/js/garde.js', async r=>{const res=await r.fetch();let t=await res.text();t=t.replace("location.protocol === 'http:' && !local","false");await r.fulfill({response:res,body:t});});
await ctx.route('**/api/**', r=>r.request().method()==='GET'?r.continue():r.abort());
for(const pl of PLANS){ if(SEULES.length&&!SEULES.includes(pl.nom)) continue;
 const p=await ctx.newPage(); await p.goto('http://185.180.206.46/consulant_bo/dashboard/?shop=4&vue='+pl.vue,{waitUntil:'networkidle',timeout:120000}).catch(()=>{}); await p.waitForTimeout(6000);
 if(pl.avant) await pl.avant(p);
 const boites=[];
 for(const s of pl.cibles){ try{const l=p.locator(s).first(); await l.waitFor({state:'visible',timeout:15000}); await l.evaluate((el,DEDANS)=>{el.style.outline='4px solid #8D1D2C';el.style.outlineOffset=DEDANS?'-4px':'5px';el.style.borderRadius=el.style.borderRadius||'10px';},!!pl.dedans); boites.push(await l.boundingBox());}catch(e){console.log(pl.nom,'absent',s);} }
 let y=0;
 if(!pl.top && boites.length && !pl.pasDeScroll){ const hP=await p.evaluate(()=>document.documentElement.scrollHeight); const top=Math.min(...boites.map(b=>b.y)), bas=Math.max(...boites.map(b=>b.y+b.height)); const h=bas-top; y=h+40>=H?top-30:top-(H-h)/2; y=Math.max(0,Math.min(y,hP-H)); }
 const opts={path:path.join(OUT,pl.nom+'.jpg'),type:'jpeg',quality:82};
 if(pl.pasDeScroll) await p.screenshot(opts); else await p.screenshot({...opts,fullPage:true,clip:{x:0,y,width:W,height:H}});
 console.log('ok',pl.nom,'y='+Math.round(y)); await p.close(); }
await b.close();})();
