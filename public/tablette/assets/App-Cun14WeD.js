import{a as e,i as t,n,o as r,r as i,t as a}from"./index-CGBKMn8j.js";var o=r(),s=(e=n.defaultLang)=>({view:`home`,lang:e,q:``,cat:`all`,vegan:!1,ex:[],faqCat:`all`,faqOpen:0,sel:null,stack:[],selFaq:-1,stSel:`team`,stPer:`week`,onbMod:-1,onbFull:!1,more:!1}),c=()=>{typeof window<`u`&&window.scrollTo(0,0)},l={go:(e,t)=>()=>({view:e,q:``,sel:null,stack:[],more:!1,onbMod:-1,...t}),openProduct:e=>t=>({sel:e,selFaq:-1,more:!1,stack:t.sel&&t.sel!==e?[...t.stack,t.sel]:t.stack}),closeProduct:()=>()=>({sel:null,stack:[]}),back:()=>e=>e.stack.length?{sel:e.stack[e.stack.length-1],stack:e.stack.slice(0,-1)}:{},toggleAllergen:e=>t=>({ex:t.ex.includes(e)?t.ex.filter(t=>t!==e):[...t.ex,e]}),setFaqCat:e=>()=>({faqCat:e,faqOpen:-1}),toggleFaq:e=>t=>({faqOpen:t.faqOpen===e?-1:e}),toggleSelFaq:e=>t=>({selFaq:t.selFaq===e?-1:e}),pickSellerRow:e=>t=>({stSel:t.stSel===e?`team`:e}),openModule:e=>()=>({onbMod:e,onbFull:!1})},u=(0,o.createContext)(null);function d(){let e=(0,o.useContext)(u);if(!e)throw Error(`useApp must be used inside <AppProvider>`);return e}var f=[{book:`Book vendeuses`,search:`Rechercher un produit, un ingrédient, une question…`,clear:`Effacer`,sample:`Données d'exemple — à remplacer par les fiches produit officielles.`,hello:`Bonjour !`,homeIntro:`Ce qu'il faut savoir aujourd'hui en boutique.`,now:`En ce moment`,next:`À préparer`,best:`Les plus vendus`,tipL:`Consigne`,top:`Top vente`,vege:`Végétarien`,vegeS:`VÉGÉ`,gammeTitle:`La gamme`,all:`Tout`,say:`À dire au client`,ingr:`Ingrédients`,alg:`Allergènes`,contains:`Contient`,traces:`Traces possibles`,trS:`Traces`,keep:`Conservation`,dlc:`Durée`,also:`Proposez aussi`,allYear:`Toute l'année`,close:`Fermer`,alTitle:`Allergènes`,alIntro:`Le client est allergique à :`,alReset:`Effacer`,alOk:`produits compatibles`,alWarn:`avec traces possibles`,alNote:`En cas d'allergie sévère : montrer la fiche, signaler les traces possibles et laisser le client décider. Ne jamais garantir qu'un produit est « sans ». En cas de doute, appeler la responsable.`,calTitle:`Saisons`,ventesTitle:`Vendre plus`,combos:`Formules`,reflexes:`Les bons réflexes`,pairs:`Associations par produit`,faqTitle:`Questions fréquentes`,selFaqT:`Ce que les clients demandent`,linkedP:`Produits concernés`,svcTitle:`Services`,how:`Comment ça marche`,delay:`Délai`,consTitle:`Conservation & DLC`,asks:`Le client demande…`,results:`Résultats pour`,noRes:`Aucun résultat.`,questions:`Questions`,d0:`Immédiat`,d1:`Jour même`,dn:`jours`,alUnk:`À vérifier`,alCheck:`À vérifier sur l'étiquette`,alUnkN:`à vérifier sur l'étiquette`,alUnkText:`Les allergènes de ce produit ne sont pas encore vérifiés : ne jamais garantir qu'il est « sans ». En cas de doute, appeler la responsable.`,alRawL:`Mention de la fiche :`,trUnk:`Traces non renseignées : à vérifier sur l'étiquette.`,srcSample:`Données d'exemple`,srcOffline:`Hors ligne`,srcOfflineOf:`données du`,srcNetwork:`réseau`},{book:`Verkoopsboek`,search:`Zoek een product, ingrediënt, vraag…`,clear:`Wissen`,sample:`Voorbeeldgegevens — te vervangen door de officiële productfiches.`,hello:`Goedendag!`,homeIntro:`Wat u vandaag moet weten in de winkel.`,now:`Nu`,next:`Voor te bereiden`,best:`Topverkopers`,tipL:`Richtlijn`,top:`Topper`,vege:`Vegetarisch`,vegeS:`VEGGIE`,gammeTitle:`Het assortiment`,all:`Alles`,say:`Tegen de klant`,ingr:`Ingrediënten`,alg:`Allergenen`,contains:`Bevat`,traces:`Mogelijke sporen`,trS:`Sporen`,keep:`Bewaring`,dlc:`Houdbaar`,also:`Stel ook voor`,allYear:`Het hele jaar`,close:`Sluiten`,alTitle:`Allergenen`,alIntro:`De klant is allergisch voor:`,alReset:`Wissen`,alOk:`geschikte producten`,alWarn:`met mogelijke sporen`,alNote:`Bij een ernstige allergie: toon de fiche, meld mogelijke sporen en laat de klant beslissen. Nooit garanderen dat een product "vrij van" is. Bij twijfel de verantwoordelijke bellen.`,calTitle:`Seizoenen`,ventesTitle:`Meer verkopen`,combos:`Formules`,reflexes:`De juiste reflexen`,pairs:`Combinaties per product`,faqTitle:`Veelgestelde vragen`,selFaqT:`Wat klanten vragen`,linkedP:`Betrokken producten`,svcTitle:`Diensten`,how:`Hoe werkt het`,delay:`Termijn`,consTitle:`Bewaring & houdbaarheid`,asks:`De klant vraagt…`,results:`Resultaten voor`,noRes:`Geen resultaten.`,questions:`Vragen`,d0:`Onmiddellijk`,d1:`Dezelfde dag`,dn:`dagen`,alUnk:`Nakijken`,alCheck:`Te controleren op het etiket`,alUnkN:`te controleren op het etiket`,alUnkText:`De allergenen van dit product zijn nog niet nagekeken: nooit garanderen dat het "vrij van" is. Bij twijfel de verantwoordelijke bellen.`,alRawL:`Vermelding op de fiche:`,trUnk:`Sporen niet ingevuld: te controleren op het etiket.`,srcSample:`Voorbeeldgegevens`,srcOffline:`Offline`,srcOfflineOf:`gegevens van`,srcNetwork:`netwerk`}],p=e=>f[e],ee=(e,t)=>[e,t],te=ee({ca:`Chiffre d'affaires`,tk:`Tickets`,pan:`Panier moyen`,cross:`Vente additionnelle`,sais:`Produits de saison`,obj:`Objectif`,team:`Équipe`,per:[[`day`,`Aujourd'hui`],[`week`,`Cette semaine`],[`month`,`Ce mois`]],rank:`Classement de l'équipe`,top:`Les plus vendus`,days:`7 derniers jours`,reached:`Atteint`,toGo:`À atteindre`,units:`pcs`,seller:`Vendeuse`,title:`Statistiques`,note:`Chiffres d’exemple — à connecter à la caisse.`,sample:`Données d'exemple`,sampleText:`Vendeuses, chiffres et objectifs fictifs, en attendant la connexion à la caisse.`},{ca:`Omzet`,tk:`Tickets`,pan:`Gemiddeld ticket`,cross:`Bijverkoop`,sais:`Seizoensproducten`,obj:`Doel`,team:`Team`,per:[[`day`,`Vandaag`],[`week`,`Deze week`],[`month`,`Deze maand`]],rank:`Teamoverzicht`,top:`Meest verkocht`,days:`7 laatste dagen`,reached:`Gehaald`,toGo:`Nog te gaan`,units:`st.`,seller:`Verkoopster`,title:`Statistieken`,note:`Voorbeeldcijfers — te koppelen aan de kassa.`,sample:`Voorbeeldgegevens`,sampleText:`Fictieve verkoopsters, cijfers en doelen, in afwachting van de koppeling met de kassa.`}),m=e=>te[e],ne=ee({rule:`La règle`,scripts:`À dire à voix haute`,exo:`Exercice`,readMin:`1 min de lecture`,full:`Lire le module complet`,short:`Revenir à la version courte`,fullTag:`Version complète`,title:`Onboarding`,intro:`Votre formation vente, module par module. À suivre dans l’ordre, en réunion d’équipe.`,back:`Tous les modules`,prev:`Précédent`,next:`Suivant`,bad:`À éviter`,good:`À dire`,homeT:e=>`Formation vente en ${e} modules · 1 min de lecture par module`},{rule:`De regel`,scripts:`Luidop te zeggen`,exo:`Oefening`,readMin:`1 min lezen`,full:`Volledige module lezen`,short:`Terug naar de korte versie`,fullTag:`Volledige versie`,title:`Onboarding`,intro:`Uw verkoopopleiding, module per module. Volg ze in volgorde, tijdens een teamvergadering.`,back:`Alle modules`,prev:`Vorige`,next:`Volgende`,bad:`Niet zo`,good:`Wel zo`,homeT:e=>`Verkoopopleiding in ${e} modules · 1 min lezen per module`}),re=e=>ne[e],ie=[[`home`,`Accueil`,`Start`,`v`],[`gamme`,`La gamme`,`Assortiment`,`v`],[`saisons`,`Saisons`,`Seizoenen`,`v`],[`al`,`Allergènes`,`Allergenen`,`v`],[`ventes`,`Vendre plus`,`Meer verkopen`,`v`],[`faq`,`FAQ clients`,`FAQ klanten`,`v`],[`svc`,`Services`,`Diensten`,`v`],[`cons`,`Conservation`,`Bewaring`,`v`],[`stats`,`Statistiques`,`Statistieken`,`v`],[`onb`,`Onboarding`,`Onboarding`,`f`]],h=(e,t)=>{let n=ie.find(t=>t[0]===e);return t?n[2]:n[1]},ae=(e,t)=>e===`v`?t?`Verkoop`:`Vente`:t?`Opleiding`:`Formation`,oe=e=>e?[`Jan`,`Feb`,`Mrt`,`Apr`,`Mei`,`Jun`,`Jul`,`Aug`,`Sep`,`Okt`,`Nov`,`Dec`]:[`Jan`,`Fév`,`Mar`,`Avr`,`Mai`,`Juin`,`Juil`,`Aoû`,`Sep`,`Oct`,`Nov`,`Déc`],se=e=>e?[`Ma`,`Di`,`Wo`,`Do`,`Vr`,`Za`,`Zo`]:[`Lun`,`Mar`,`Mer`,`Jeu`,`Ven`,`Sam`,`Dim`],ce=e=>e?`nl-BE`:`fr-BE`,le=`(min-width: 1000px)`,ue=()=>window.matchMedia(le),de=e=>{let t=ue();return t.addEventListener(`change`,e),()=>t.removeEventListener(`change`,e)},fe=()=>(0,o.useSyncExternalStore)(de,()=>!ue().matches,()=>!1),g=a();function pe({children:e,initial:t}){let[n,r]=(0,o.useState)(()=>({...s(),...t})),i=fe(),a=(0,o.useCallback)(e=>{r(t=>({...t,...typeof e==`function`?e(t):e}))},[]),d=(0,o.useMemo)(()=>({set:a,go:(e,t)=>{a(l.go(e,t)),c()},setLang:e=>a({lang:e}),setQ:e=>a({q:e}),clearQ:()=>a({q:``}),setCat:e=>a({cat:e}),toggleVegan:()=>a(e=>({vegan:!e.vegan})),toggleAllergen:e=>a(l.toggleAllergen(e)),resetEx:()=>a({ex:[]}),setFaqCat:e=>a(l.setFaqCat(e)),toggleFaq:e=>a(l.toggleFaq(e)),openProduct:e=>a(l.openProduct(e)),closeProduct:()=>a(l.closeProduct()),back:()=>a(l.back()),toggleSelFaq:e=>a(l.toggleSelFaq(e)),setStSel:e=>a({stSel:e}),pickSellerRow:e=>a(l.pickSellerRow(e)),setStPer:e=>a({stPer:e}),openModule:e=>{a(l.openModule(e)),c()},backToModules:()=>{a({onbMod:-1}),c()},toggleFull:()=>{a(e=>({onbFull:!e.onbFull})),c()},toggleMore:()=>a(e=>({more:!e.more})),closeMore:()=>a({more:!1})}),[a]),f=(0,o.useMemo)(()=>({state:n,actions:d,lang:n.lang,L:p(n.lang),compact:i}),[n,d,i]);return(0,g.jsx)(u.Provider,{value:f,children:e})}var _={row:`_row_3l3fg_1`,pill:`_pill_3l3fg_6`,hover:`_hover_3l3fg_16`,img:`_img_3l3fg_19`,name:`_name_3l3fg_24`,price:`_price_3l3fg_29`,lg:`_lg_3l3fg_34`,md:`_md_3l3fg_44`,sm:`_sm_3l3fg_53`};function v({p:e,size:t=`lg`,price:n=!0,hover:r=!1}){let{actions:i}=d();return(0,g.jsxs)(`button`,{type:`button`,className:`${_.pill} ${_[t]}${r?` `+_.hover:``}`,onClick:()=>i.openProduct(e.id),children:[(0,g.jsx)(`img`,{src:e.img,alt:``,loading:`lazy`,decoding:`async`,className:_.img}),(0,g.jsx)(`span`,{className:_.name,children:e.name}),n&&(0,g.jsx)(`span`,{className:_.price,children:e.price})]})}function y({children:e,gap:t=10}){return(0,g.jsx)(`div`,{className:_.row,style:{gap:t},children:e})}var me=(e,t=n.showPrices)=>e==null||!t?``:e.toFixed(2).replace(`.`,`,`)+` €`,he=(e,t)=>Math.round(e).toLocaleString(ce(t))+` €`,ge=e=>e.toFixed(2).replace(`.`,`,`)+` €`,_e=(e,t)=>e===0?t.d0:e===1?t.d1:e+` `+t.dn,b=e=>Object.fromEntries(e.map(e=>[e.id,e])),ve=new WeakMap,ye=e=>{let t=ve.get(e);return t||(t={allergens:b(e.allergens),seasons:b(e.seasons),categories:b(e.categories),products:b(e.products)},ve.set(e,t)),t},x=ye(t);x.allergens;var be=x.seasons;x.categories;var xe=x.products,S=(e,t)=>e?e[t]||e[0]:``,C=e=>e.alKnown===!1,w=(e,t,n=x)=>({id:e.id,name:S(e.name,t),img:i(e.img),price:me(e.price),unit:S(e.unit,t),als:e.al.flatMap(e=>n.allergens[e]?.s??[]),alUnknown:C(e),best:!!e.best,seasonal:!!e.season,seasonName:S(e.season?n.seasons[e.season]?.n:null,t),vegan:e.diet===`vegan`,vege:e.diet===`vege`}),Se=(e,t=xe)=>e.flatMap(e=>t[e]??[]),Ce=(e,t,n=x)=>Se(e,n.products).map(e=>w(e,t,n)),we=(e,t,n=x)=>Ce([e],t,n)[0]??null,Te=(e,t,n=be)=>{let r=e.season?n[e.season]:void 0;return r?S(r.n,t)+` · `+S(r.dates,t):p(t).allYear},Ee=(e,t)=>e.al.includes(t)?`contains`:e.tr.includes(t)?`traces`:`absent`,De=(e,n,r=t.allergens)=>r.map(t=>({id:t.id,n:S(t.n,n),state:Ee(e,t.id)})).filter(t=>t.state!==`absent`||!C(e)),Oe=(e,n,r,i=t.faq)=>i.map((e,t)=>({f:e,index:t})).filter(({f:t})=>!!t.p?.includes(e)).map(({f:e,index:t})=>{let i=n===t;return{index:t,q:S(e.q,r),a:S(e.a,r),open:i,sign:i?`−`:`+`}}),ke=(e,t,n=xe)=>{let r=e[e.length-1];return S(r?n[r]?.name:null,t)},Ae=(e,n,r,i=t)=>{let a=ye(i),o=e?a.products[e]:void 0;return o?{...w(o,r,a),cat:S(a.categories[o.cat]?.n,r),desc:S(o.desc,r),pitch:S(o.pitch,r),ingr:S(o.ingr,r),keep:S(o.keep,r),dlc:_e(o.dlc,p(r)),crossLine:S(o.crossLine,r),avail:Te(o,r,a.seasons),alKnown:!C(o),trKnown:o.trKnown!==!1,alRaw:o.alRaw?.trim()??``,grid:De(o,r,i.allergens),faq:Oe(o.id,n,r,i.faq),cross:Ce(o.cross,r,a)}:null},je=(e,t,n)=>e?n>70&&n>Math.abs(t):t>80&&t>Math.abs(n),T={overlay:`_overlay_1pnq5_3`,scrim:`_scrim_1pnq5_12`,panel:`_panel_1pnq5_19`,compact:`_compact_1pnq5_40`,header:`_header_1pnq5_49`,handleRow:`_handleRow_1pnq5_59`,handle:`_handle_1pnq5_59`,bar:`_bar_1pnq5_73`,lead:`_lead_1pnq5_80`,back:`_back_1pnq5_87`,close:`_close_1pnq5_88`,cat:`_cat_1pnq5_112`,body:`_body_1pnq5_121`,eyebrow:`_eyebrow_1pnq5_132`,hero:`_hero_1pnq5_141`,tile:`_tile_1pnq5_148`,tileImg:`_tileImg_1pnq5_158`,heroText:`_heroText_1pnq5_165`,name:`_name_1pnq5_172`,priceRow:`_priceRow_1pnq5_181`,price:`_price_1pnq5_181`,unit:`_unit_1pnq5_193`,badges:`_badges_1pnq5_200`,badge:`_badge_1pnq5_200`,avail:`_avail_1pnq5_213`,vegan:`_vegan_1pnq5_218`,vege:`_vege_1pnq5_223`,best:`_best_1pnq5_229`,say:`_say_1pnq5_235`,sayLabel:`_sayLabel_1pnq5_245`,pitch:`_pitch_1pnq5_253`,desc:`_desc_1pnq5_260`,allergens:`_allergens_1pnq5_267`,alHead:`_alHead_1pnq5_273`,legend:`_legend_1pnq5_279`,legendItem:`_legendItem_1pnq5_286`,dot:`_dot_1pnq5_292`,ring:`_ring_1pnq5_300`,alGrid:`_alGrid_1pnq5_308`,al:`_al_1pnq5_267`,contains:`_contains_1pnq5_327`,traces:`_traces_1pnq5_333`,absent:`_absent_1pnq5_338`,alCheck:`_alCheck_1pnq5_344`,alCheckTitle:`_alCheckTitle_1pnq5_356`,alRaw:`_alRaw_1pnq5_361`,trUnk:`_trUnk_1pnq5_368`,ingredients:`_ingredients_1pnq5_375`,ingr:`_ingr_1pnq5_375`,keepBlock:`_keepBlock_1pnq5_386`,keepCol:`_keepCol_1pnq5_395`,dlc:`_dlc_1pnq5_401`,keep:`_keep_1pnq5_386`,faqList:`_faqList_1pnq5_413`,faq:`_faq_1pnq5_413`,faqBtn:`_faqBtn_1pnq5_425`,sign:`_sign_1pnq5_447`,answer:`_answer_1pnq5_453`,also:`_also_1pnq5_460`,crossLine:`_crossLine_1pnq5_466`},Me=[`Retour à`,`Terug naar`],Ne={contains:T.contains,traces:T.traces,absent:T.absent},Pe=`button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])`;function Fe(){let{state:e}=d();return!e.sel||!xe[e.sel]?null:(0,g.jsx)(Ie,{})}function Ie(){let{state:e,actions:t,lang:n,L:r,compact:i}=d(),a=(0,o.useMemo)(()=>Ae(e.sel,e.selFaq,n),[e.sel,e.selFaq,n]),s=ke(e.stack,n),c=(0,o.useRef)(null),l=(0,o.useRef)(null),u=(0,o.useId)(),f=`${u}-title`,[p]=(0,o.useState)(()=>document.activeElement instanceof HTMLElement?document.activeElement:null);return(0,o.useEffect)(()=>{let e=document.documentElement,{overflow:n,scrollbarGutter:r}=e.style;window.innerWidth>e.clientWidth&&(e.style.scrollbarGutter=`stable`),e.style.overflow=`hidden`;let i=e=>{e.key===`Escape`&&t.closeProduct()};return window.addEventListener(`keydown`,i),()=>{window.removeEventListener(`keydown`,i),e.style.overflow=n,e.style.scrollbarGutter=r,p?.isConnected&&p.focus({preventScroll:!0})}},[t,p]),(0,o.useLayoutEffect)(()=>{c.current&&(c.current.scrollTop=0)},[e.sel]),(0,o.useEffect)(()=>{let e=c.current;e&&!e.contains(document.activeElement)&&e.focus({preventScroll:!0})},[e.sel]),a?(0,g.jsxs)(`div`,{className:i?`${T.overlay} ${T.compact}`:T.overlay,onKeyDown:e=>{let t=c.current;if(e.key!==`Tab`||!t)return;let n=[...t.querySelectorAll(Pe)];if(!n.length)return;let r=n[0],i=n[n.length-1],a=document.activeElement;e.shiftKey&&(a===r||a===t)?(e.preventDefault(),i.focus()):!e.shiftKey&&a===i&&(e.preventDefault(),r.focus())},children:[(0,g.jsx)(`div`,{className:T.scrim,onClick:t.closeProduct,"aria-hidden":`true`}),(0,g.jsxs)(`div`,{ref:c,className:T.panel,role:`dialog`,"aria-modal":`true`,"aria-labelledby":f,tabIndex:-1,children:[(0,g.jsxs)(`div`,{className:T.header,onTouchStart:e=>{let t=e.touches[0];l.current={x:t.clientX,y:t.clientY}},onTouchEnd:e=>{let n=l.current;if(!n)return;l.current=null;let r=e.changedTouches[0];je(i,r.clientX-n.x,r.clientY-n.y)&&t.closeProduct()},children:[i&&(0,g.jsx)(`div`,{className:T.handleRow,children:(0,g.jsx)(`span`,{className:T.handle})}),(0,g.jsxs)(`div`,{className:T.bar,children:[(0,g.jsxs)(`div`,{className:T.lead,children:[e.stack.length>0&&(0,g.jsxs)(`button`,{type:`button`,className:T.back,onClick:t.back,"aria-label":`${Me[n]} ${s}`,children:[`← `,s]}),(0,g.jsx)(`span`,{className:T.cat,children:a.cat})]}),(0,g.jsx)(`button`,{type:`button`,className:T.close,onClick:t.closeProduct,children:r.close})]})]}),(0,g.jsxs)(`div`,{className:T.body,children:[(0,g.jsxs)(`div`,{className:T.hero,children:[(0,g.jsx)(`div`,{className:T.tile,children:(0,g.jsx)(`img`,{src:a.img,alt:``,className:T.tileImg})}),(0,g.jsxs)(`div`,{className:T.heroText,children:[(0,g.jsx)(`h2`,{id:f,className:T.name,children:a.name}),(a.price||a.unit)&&(0,g.jsxs)(`div`,{className:T.priceRow,children:[(0,g.jsx)(`span`,{className:T.price,children:a.price}),(0,g.jsx)(`span`,{className:T.unit,children:a.unit})]}),(0,g.jsxs)(`div`,{className:T.badges,children:[(0,g.jsx)(`span`,{className:`${T.badge} ${T.avail}`,children:a.avail}),a.vegan&&(0,g.jsx)(`span`,{className:`${T.badge} ${T.vegan}`,children:`VEGAN`}),a.vege&&(0,g.jsx)(`span`,{className:`${T.badge} ${T.vege}`,children:r.vege}),a.best&&(0,g.jsx)(`span`,{className:`${T.badge} ${T.best}`,children:r.top})]})]})]}),a.pitch&&(0,g.jsxs)(`div`,{className:T.say,children:[(0,g.jsx)(`h3`,{className:T.sayLabel,children:r.say}),(0,g.jsxs)(`span`,{className:T.pitch,children:[`« `,a.pitch,` »`]})]}),a.desc&&(0,g.jsx)(`p`,{className:T.desc,children:a.desc}),(0,g.jsxs)(`div`,{className:T.allergens,children:[(0,g.jsxs)(`div`,{className:T.alHead,children:[(0,g.jsx)(`h3`,{className:T.eyebrow,children:r.alg}),a.grid.length>0&&(0,g.jsxs)(`span`,{className:T.legend,children:[(0,g.jsxs)(`span`,{className:T.legendItem,children:[(0,g.jsx)(`span`,{className:T.dot,"aria-hidden":`true`}),r.contains]}),(0,g.jsxs)(`span`,{className:T.legendItem,children:[(0,g.jsx)(`span`,{className:T.ring,"aria-hidden":`true`}),r.traces]})]})]}),!a.alKnown&&(0,g.jsxs)(`div`,{className:T.alCheck,children:[(0,g.jsx)(`strong`,{className:T.alCheckTitle,children:r.alCheck}),(0,g.jsx)(`span`,{children:r.alUnkText}),a.alRaw&&(0,g.jsxs)(`span`,{className:T.alRaw,children:[r.alRawL,` `,a.alRaw]})]}),a.grid.length>0&&(0,g.jsx)(`ul`,{className:T.alGrid,children:a.grid.map(e=>(0,g.jsxs)(`li`,{className:`${T.al} ${Ne[e.state]}`,children:[e.n,e.state!==`absent`&&(0,g.jsxs)(`span`,{className:`sr-only`,children:[`: `,e.state===`contains`?r.contains:r.traces]})]},e.id))}),a.alKnown&&!a.trKnown&&(0,g.jsx)(`p`,{className:T.trUnk,children:r.trUnk})]}),a.ingr&&(0,g.jsxs)(`div`,{className:T.ingredients,children:[(0,g.jsx)(`h3`,{className:T.eyebrow,children:r.ingr}),(0,g.jsx)(`span`,{className:T.ingr,children:a.ingr})]}),(0,g.jsxs)(`div`,{className:T.keepBlock,children:[(0,g.jsxs)(`div`,{className:T.keepCol,children:[(0,g.jsx)(`h3`,{className:T.eyebrow,children:r.dlc}),(0,g.jsx)(`span`,{className:T.dlc,children:a.dlc})]}),a.keep&&(0,g.jsxs)(`div`,{className:T.keepCol,children:[(0,g.jsx)(`h3`,{className:T.eyebrow,children:r.keep}),(0,g.jsx)(`span`,{className:T.keep,children:a.keep})]})]}),a.faq.length>0&&(0,g.jsxs)(`div`,{className:T.faqList,children:[(0,g.jsx)(`h3`,{className:T.eyebrow,children:r.selFaqT}),a.faq.map(e=>{let n=`${u}-faq${e.index}`;return(0,g.jsxs)(`div`,{className:T.faq,children:[(0,g.jsxs)(`button`,{type:`button`,className:T.faqBtn,"aria-expanded":e.open,"aria-controls":n,onClick:()=>t.toggleSelFaq(e.index),children:[(0,g.jsx)(`span`,{children:e.q}),(0,g.jsx)(`span`,{className:T.sign,"aria-hidden":`true`,children:e.sign})]}),(0,g.jsx)(`div`,{id:n,className:T.answer,hidden:!e.open,children:e.a})]},e.index)})]}),(a.crossLine||a.cross.length>0)&&(0,g.jsxs)(`div`,{className:T.also,children:[(0,g.jsx)(`h3`,{className:T.eyebrow,children:r.also}),a.crossLine&&(0,g.jsxs)(`span`,{className:T.crossLine,children:[`« `,a.crossLine,` »`]}),a.cross.length>0&&(0,g.jsx)(y,{children:a.cross.map((e,t)=>(0,g.jsx)(v,{p:e,size:`lg`,hover:!0},t+`-`+e.id))})]})]})]})]}):null}var E={toggle:`_toggle_q4onw_1`,btn:`_btn_q4onw_8`,active:`_active_q4onw_26`,sidebar:`_sidebar_q4onw_31`,compact:`_compact_q4onw_45`},Le=[[0,`FR`,`fr`],[1,`NL`,`nl`]];function Re({variant:e}){let{lang:t,actions:n}=d();return(0,g.jsx)(`div`,{className:`${E.toggle} ${E[e]}`,role:`group`,"aria-label":t?`Taal`:`Langue`,children:Le.map(([e,r,i])=>(0,g.jsx)(`button`,{type:`button`,lang:i,className:e===t?`${E.btn} ${E.active}`:E.btn,"aria-pressed":e===t,onClick:()=>n.setLang(e),children:r},r))})}var ze=(e,t,n)=>t===e&&!n,Be=e=>[`v`,`f`].map(t=>({key:t,title:ae(t,e),items:ie.filter(e=>e[3]===t).map(([t])=>({id:t,label:h(t,e)}))})),Ve=e=>e?`Navigatie`:`Navigation`,He=[`home`,`gamme`,`al`,`faq`],Ue=(e,t,n)=>e===`more`?n||!He.includes(t):t===e&&!n,We=(e,t)=>e===`more`?t?`Meer`:`Plus`:e===`faq`?`FAQ`:h(e,t),Ge=[[`v`,[[`saisons`,`img/s/autumn-range.png`],[`ventes`,`img/p/sandwiches.png`],[`svc`,`img/svc/click-collect.png`],[`cons`,`img/s/winter-range.png`],[`stats`,`img/svc/b2b.png`]]],[`f`,[[`onb`,`img/onb/croissant.png`]]]],Ke=e=>Ge.map(([t,n])=>({key:t,title:ae(t,e),items:n.map(([t,n])=>({id:t,label:h(t,e),img:n}))})),qe=(e,t)=>{let n=e?new Date(e):null;if(!n||Number.isNaN(n.getTime()))return``;let r=ce(t);return n.toLocaleDateString(r,{day:`numeric`,month:`short`})+` `+n.toLocaleTimeString(r,{hour:`2-digit`,minute:`2-digit`})},Je=(e,t,n=!1)=>{let r=p(t),i=qe(e.generatedAt,t);switch(e.kind){case`sample`:return n?r.sample:r.srcSample;case`cache`:return i?`${r.srcOffline} · ${r.srcOfflineOf} ${i}`:r.srcOffline;case`bo`:return[`BO`,e.shop?.name||r.srcNetwork,i].filter(Boolean).join(` · `)}},D={bar:`_bar_kujiw_1`,brand:`_brand_kujiw_8`,logo:`_logo_kujiw_15`,book:`_book_kujiw_20`,source:`_source_kujiw_28`};function Ye(){let{L:t,lang:n}=d();return(0,g.jsxs)(`div`,{className:D.bar,children:[(0,g.jsxs)(`div`,{className:D.brand,children:[(0,g.jsx)(`img`,{src:i(`img/logo.png`),alt:`L'Atelier By`,className:D.logo}),(0,g.jsx)(`span`,{className:D.book,children:t.book}),(0,g.jsx)(`span`,{className:D.source,children:Je(e,n)})]}),(0,g.jsx)(Re,{variant:`compact`})]})}var O={overlay:`_overlay_1ti41_1`,scrim:`_scrim_1ti41_9`,sheet:`_sheet_1ti41_16`,handleRow:`_handleRow_1ti41_32`,handle:`_handle_1ti41_32`,group:`_group_1ti41_45`,groupTitle:`_groupTitle_1ti41_51`,grid:`_grid_1ti41_59`,tile:`_tile_1ti41_65`,img:`_img_1ti41_81`,label:`_label_1ti41_88`};function Xe({returnFocus:e}){let{lang:t,actions:n}=d(),r=(0,o.useRef)(null);return(0,o.useEffect)(()=>{let t=e?.current;r.current?.focus({preventScroll:!0});let i=e=>{e.key===`Escape`&&n.closeMore()};return window.addEventListener(`keydown`,i),()=>{window.removeEventListener(`keydown`,i),t?.isConnected&&t.focus({preventScroll:!0})}},[n,e]),(0,g.jsxs)(`div`,{className:O.overlay,children:[(0,g.jsx)(`div`,{className:O.scrim,onClick:n.closeMore,"aria-hidden":`true`}),(0,g.jsxs)(`div`,{ref:r,className:O.sheet,role:`dialog`,"aria-modal":`true`,"aria-label":We(`more`,t),tabIndex:-1,children:[(0,g.jsx)(`div`,{className:O.handleRow,children:(0,g.jsx)(`span`,{className:O.handle})}),Ke(t).map(e=>(0,g.jsxs)(`div`,{className:O.group,children:[(0,g.jsx)(`div`,{className:O.groupTitle,children:e.title}),(0,g.jsx)(`div`,{className:O.grid,children:e.items.map(e=>(0,g.jsxs)(`button`,{type:`button`,className:O.tile,onClick:()=>n.go(e.id),children:[(0,g.jsx)(`img`,{src:i(e.img),alt:``,className:O.img}),(0,g.jsx)(`span`,{className:O.label,children:e.label})]},e.id))})]},e.key))]})]})}var Ze=()=>n.date?new Date(n.date):new Date,Qe=()=>Ze().getMonth()+1,$e=e=>Ze().toLocaleDateString(ce(e),{weekday:`long`,day:`numeric`,month:`long`}),et=e=>e.trim().toLowerCase(),k=(e,t)=>!!e&&e.some(e=>e.toLowerCase().includes(t));function tt(e,n=t){let r=et(e);if(!r)return{products:[],faq:[]};let i=Object.fromEntries(n.allergens.map(e=>[e.id,e]));return{products:n.products.filter(e=>k(e.name,r)||k(e.desc,r)||k(e.ingr,r)||e.al.some(e=>k(i[e]?.n,r))),faq:n.faq.map((e,t)=>({f:e,i:t})).filter(({f:e})=>k(e.q,r)||k(e.a,r))}}var nt=({products:e,faq:t},n,r)=>!e.length&&!t.length?r:n?`${e.length} ${e.length===1?`product`:`producten`}, ${t.length} ${t.length===1?`vraag`:`vragen`}`:`${e.length} produit${e.length>1?`s`:``}, ${t.length} question${t.length>1?`s`:``}`;function rt(){return(0,g.jsxs)(`svg`,{width:`18`,height:`18`,viewBox:`0 0 24 24`,fill:`none`,stroke:`#666`,strokeWidth:`1.6`,"aria-hidden":`true`,focusable:`false`,children:[(0,g.jsx)(`circle`,{cx:`11`,cy:`11`,r:`7`}),(0,g.jsx)(`path`,{d:`M20 20l-4-4`})]})}var A={width:24,height:24,viewBox:`0 0 24 24`,"aria-hidden":!0,focusable:!1};function it(){return(0,g.jsx)(`svg`,{...A,fill:`none`,stroke:`currentColor`,strokeWidth:`1.6`,strokeLinejoin:`round`,children:(0,g.jsx)(`path`,{d:`M4 10.5L12 4l8 6.5V20h-5v-6h-6v6H4z`})})}function at(){return(0,g.jsxs)(`svg`,{...A,fill:`none`,stroke:`currentColor`,strokeWidth:`1.6`,children:[(0,g.jsx)(`rect`,{x:`4`,y:`4`,width:`7`,height:`7`,rx:`1.5`}),(0,g.jsx)(`rect`,{x:`13`,y:`4`,width:`7`,height:`7`,rx:`1.5`}),(0,g.jsx)(`rect`,{x:`4`,y:`13`,width:`7`,height:`7`,rx:`1.5`}),(0,g.jsx)(`rect`,{x:`13`,y:`13`,width:`7`,height:`7`,rx:`1.5`})]})}function ot(){return(0,g.jsxs)(`svg`,{...A,fill:`none`,stroke:`currentColor`,strokeWidth:`1.6`,strokeLinejoin:`round`,children:[(0,g.jsx)(`path`,{d:`M12 3l7 3v5c0 4.5-3 8.2-7 10-4-1.8-7-5.5-7-10V6z`}),(0,g.jsx)(`path`,{d:`M12 8v5M12 16v.5`})]})}function st(){return(0,g.jsxs)(`svg`,{...A,fill:`none`,stroke:`currentColor`,strokeWidth:`1.6`,strokeLinejoin:`round`,children:[(0,g.jsx)(`path`,{d:`M4 5h16v11H9l-5 4z`}),(0,g.jsx)(`path`,{d:`M10 9a2 2 0 114 0c0 1.2-2 1.4-2 2.6M12 13.5v.2`})]})}function ct(){return(0,g.jsxs)(`svg`,{...A,fill:`currentColor`,children:[(0,g.jsx)(`circle`,{cx:`5.5`,cy:`12`,r:`1.7`}),(0,g.jsx)(`circle`,{cx:`12`,cy:`12`,r:`1.7`}),(0,g.jsx)(`circle`,{cx:`18.5`,cy:`12`,r:`1.7`})]})}var j={header:`_header_rugsw_1`,field:`_field_rugsw_7`,input:`_input_rugsw_23`,clear:`_clear_rugsw_33`,today:`_today_rugsw_43`};function lt(){let{state:e,lang:t,L:n,compact:r,actions:i}=d(),a=(0,o.useRef)(null),s=!!e.q.trim(),c=(0,o.useMemo)(()=>tt(e.q),[e.q]);return(0,g.jsxs)(`header`,{className:j.header,children:[(0,g.jsxs)(`div`,{className:j.field,role:`search`,children:[(0,g.jsx)(rt,{}),(0,g.jsx)(`input`,{ref:a,type:`text`,className:j.input,value:e.q,onChange:e=>i.setQ(e.target.value),onKeyDown:e=>{e.key===`Enter`&&!e.nativeEvent.isComposing&&e.currentTarget.blur()},placeholder:n.search,"aria-label":n.search,enterKeyHint:`search`,autoComplete:`off`,autoCorrect:`off`,spellCheck:!1}),s&&(0,g.jsx)(`button`,{type:`button`,className:j.clear,onClick:e=>{i.clearQ(),e.detail===0&&a.current?.focus()},children:n.clear})]}),!r&&(0,g.jsx)(`div`,{className:j.today,children:$e(t)}),(0,g.jsx)(`p`,{className:`sr-only`,role:`status`,children:s?nt(c,t,n.noRes):``})]})}var M={aside:`_aside_16o6m_1`,brand:`_brand_16o6m_15`,logo:`_logo_16o6m_22`,book:`_book_16o6m_28`,nav:`_nav_16o6m_35`,group:`_group_16o6m_47`,groupTitle:`_groupTitle_16o6m_53`,item:`_item_16o6m_62`,active:`_active_16o6m_75`,foot:`_foot_16o6m_84`,sample:`_sample_16o6m_91`};function ut({inert:t}){let{state:n,lang:r,L:a,actions:o}=d();return(0,g.jsxs)(`aside`,{className:M.aside,inert:t,children:[(0,g.jsxs)(`div`,{className:M.brand,children:[(0,g.jsx)(`img`,{src:i(`img/logo.png`),alt:`L'Atelier By`,className:M.logo}),(0,g.jsx)(`div`,{className:M.book,children:a.book})]}),(0,g.jsx)(`nav`,{className:M.nav,"aria-label":Ve(r),children:Be(r).map(e=>(0,g.jsxs)(`div`,{className:M.group,children:[(0,g.jsx)(`div`,{className:M.groupTitle,children:e.title}),e.items.map(e=>{let t=ze(e.id,n.view,n.q);return(0,g.jsx)(`button`,{type:`button`,className:t?`${M.item} ${M.active}`:M.item,"aria-current":t?`page`:void 0,onClick:()=>o.go(e.id),children:e.label},e.id)})]},e.key))}),(0,g.jsxs)(`div`,{className:M.foot,children:[(0,g.jsx)(Re,{variant:`sidebar`}),(0,g.jsx)(`div`,{className:M.sample,children:Je(e,r,!0)})]})]})}var N={bar:`_bar_ugz8f_1`,tab:`_tab_ugz8f_14`,on:`_on_ugz8f_30`},dt=[[`home`,it],[`gamme`,at],[`al`,ot],[`faq`,st],[`more`,ct]];function ft({moreRef:e,inert:t}){let{state:n,lang:r,actions:i}=d();return(0,g.jsx)(`nav`,{className:N.bar,"aria-label":Ve(r),inert:n.more||t,children:dt.map(([t,a])=>{let o=Ue(t,n.view,n.more),s=t===`more`;return(0,g.jsxs)(`button`,{ref:s?e:void 0,type:`button`,className:o?`${N.tab} ${N.on}`:N.tab,"aria-current":!s&&o?`page`:void 0,"aria-expanded":s?n.more:void 0,"aria-haspopup":s?`dialog`:void 0,onClick:s?i.toggleMore:()=>i.go(t),children:[(0,g.jsx)(a,{}),We(t,r)]},t)})})}var P={app:`_app_10hqu_1`,main:`_main_10hqu_10`,compact:`_compact_10hqu_19`,top:`_top_10hqu_27`};function pt({children:e}){let{state:t,lang:n,compact:r}=d(),i=r&&t.more,a=!!t.sel&&!!xe[t.sel],s=(0,o.useRef)(null),c=(0,o.useRef)(null),l=(0,o.useRef)(t.view);return(0,o.useEffect)(()=>{document.documentElement.lang=n?`nl`:`fr`},[n]),(0,o.useEffect)(()=>{if(l.current===t.view)return;l.current=t.view;let e=document.activeElement;if(e&&e!==document.body)return;let n=c.current?.querySelector(`h1`);n&&(n.tabIndex=-1,n.focus({preventScroll:!0}))},[t.view]),(0,g.jsxs)(`div`,{className:r?`${P.app} ${P.compact}`:P.app,children:[!r&&(0,g.jsx)(ut,{inert:a}),(0,g.jsxs)(`main`,{ref:c,className:P.main,inert:i||a,children:[(0,g.jsxs)(`div`,{className:P.top,children:[r&&(0,g.jsx)(Ye,{}),(0,g.jsx)(lt,{})]}),e]}),r&&(0,g.jsx)(ft,{moreRef:s,inert:a}),i&&(0,g.jsx)(Xe,{returnFocus:s}),(0,g.jsx)(Fe,{})]})}var F={grid:`_grid_bjus1_1`,card:`_card_bjus1_7`,media:`_media_bjus1_29`,img:`_img_bjus1_38`,badgesL:`_badgesL_bjus1_45`,badgesR:`_badgesR_bjus1_54`,season:`_season_bjus1_61`,best:`_best_bjus1_62`,vegan:`_vegan_bjus1_71`,vege:`_vege_bjus1_72`,body:`_body_bjus1_82`,name:`_name_bjus1_90`,priceRow:`_priceRow_bjus1_97`,price:`_price_bjus1_97`,unit:`_unit_bjus1_110`,codes:`_codes_bjus1_115`,code:`_code_bjus1_115`,unk:`_unk_bjus1_133`};function mt({p:e,details:t=!0}){let{L:n,actions:r}=d(),i=(0,o.useId)(),a=[`${i}-n`,`${i}-p`,`${i}-b`,t&&`${i}-d`].filter(Boolean).join(` `);return(0,g.jsxs)(`button`,{type:`button`,className:F.card,onClick:()=>r.openProduct(e.id),"aria-labelledby":a,"aria-describedby":t&&(e.als.length||e.alUnknown)?`${i}-a`:void 0,children:[(0,g.jsxs)(`span`,{className:F.media,children:[(0,g.jsx)(`img`,{src:e.img,alt:``,loading:`lazy`,decoding:`async`,className:F.img}),(0,g.jsxs)(`span`,{className:F.badgesL,id:`${i}-b`,children:[e.seasonal&&(0,g.jsx)(`span`,{className:F.season,children:e.seasonName}),e.best&&(0,g.jsx)(`span`,{className:F.best,children:n.top})]}),t&&(0,g.jsxs)(`span`,{className:F.badgesR,id:`${i}-d`,children:[e.vegan&&(0,g.jsx)(`span`,{className:F.vegan,children:`VEGAN`}),e.vege&&(0,g.jsx)(`span`,{className:F.vege,children:n.vegeS})]})]}),(0,g.jsxs)(`span`,{className:F.body,children:[(0,g.jsx)(`span`,{className:F.name,id:`${i}-n`,children:e.name}),(0,g.jsxs)(`span`,{className:F.priceRow,id:`${i}-p`,children:[(0,g.jsx)(`span`,{className:F.price,children:e.price}),(0,g.jsx)(`span`,{className:F.unit,children:e.unit})]}),t&&(0,g.jsxs)(`span`,{className:F.codes,id:`${i}-a`,children:[e.als.map(e=>(0,g.jsx)(`span`,{className:F.code,children:e},e)),e.alUnknown&&(0,g.jsx)(`span`,{className:F.unk,children:n.alCheck})]})]})]})}function ht({children:e}){return(0,g.jsx)(`div`,{className:F.grid,children:e})}var I={section:`_section_xi7tp_1`,title:`_title_xi7tp_7`,none:`_none_xi7tp_18`,faq:`_faq_xi7tp_23`,eyebrow:`_eyebrow_xi7tp_30`,card:`_card_xi7tp_40`,q:`_q_xi7tp_49`,a:`_a_xi7tp_54`};function gt(){let{state:e,lang:t,L:n}=d(),r=(0,o.useMemo)(()=>tt(e.q),[e.q]),i=!r.products.length&&!r.faq.length;return(0,g.jsxs)(`section`,{className:I.section,children:[(0,g.jsxs)(`h1`,{className:I.title,children:[n.results,` « `,e.q,` »`]}),i&&(0,g.jsx)(`p`,{className:I.none,children:n.noRes}),r.products.length>0&&(0,g.jsx)(ht,{children:r.products.map(e=>(0,g.jsx)(mt,{p:w(e,t),details:!1},e.id))}),r.faq.length>0&&(0,g.jsxs)(`div`,{className:I.faq,children:[(0,g.jsx)(`h2`,{className:I.eyebrow,children:n.questions}),r.faq.map(({f:e,i:n})=>(0,g.jsxs)(`div`,{className:I.card,children:[(0,g.jsx)(`div`,{className:I.q,children:S(e.q,t)}),(0,g.jsx)(`div`,{className:I.a,children:S(e.a,t)})]},n))]})]})}var L={h1:`_h1_1lokr_1`,h2:`_h2_1lokr_10`};function R({children:e,className:t}){return(0,g.jsx)(`h1`,{className:t?`${L.h1} ${t}`:L.h1,children:e})}function z({children:e,className:t}){return(0,g.jsx)(`h2`,{className:t?`${L.h2} ${t}`:L.h2,children:e})}var _t=[{rule:[`Vendre est un métier, avec ses gestes, ses mots et ses techniques. La passion vient en le pratiquant.`,`Verkopen is een vak, met zijn gebaren, zijn woorden en zijn technieken. De passie komt al doende.`],points:[[`Le client ne vient pas seulement acheter du pain : il vient chercher un moment, qui se réussit ou se rate au comptoir.`,`De klant komt niet alleen brood kopen: hij komt een moment halen, en aan de toog wordt beslist of dat lukt.`],[`Une boutique vit de ce qu'elle vend. Ce que chaque client emporte en sortant dépend de vous.`,`Een winkel leeft van wat ze verkoopt. Wat elke klant meeneemt, hangt van u af.`],[`Dans votre application, vous suivez votre chiffre d'affaires vendu et vos ventes additionnelles, pour voir vos progrès semaine après semaine.`,`In uw app volgt u uw verkochte omzet en uw bijverkopen, om uw vooruitgang week na week te zien.`]],scripts:[],exo:[`Votre responsable remplit avec vous la cible de la boutique : chiffre d'affaires vendu et ventes additionnelles.`,`Uw verantwoordelijke vult samen met u het doel van de winkel in: verkochte omzet en bijverkopen.`],gain:null},{rule:[`Le client oublie vite ce qu'il a acheté. Il se souvient longtemps de ce qu'il a ressenti : soignez l'entrée, et surtout la sortie.`,`Een klant vergeet snel wat hij gekocht heeft. Wat hij gevoeld heeft, onthoudt hij lang: verzorg het binnenkomen, en vooral het buitengaan.`],points:[[`Règle des 3 secondes : chaque client qui entre est regardé et salué dans les 3 secondes, même si vous êtes occupé.`,`Regel van 3 seconden: elke klant wordt binnen 3 seconden aangekeken en begroet, ook als u bezig bent.`],[`« Bonjour », dit en premier, par vous. Puis « Qu'est-ce qui vous ferait plaisir ? »`,`« Goeiedag », als eerste gezegd, door u. Dan « Waarmee kan ik u een plezier doen? »`],[`Pendant la vente : toute votre attention à la personne en face de vous, pas à la file.`,`Tijdens de verkoop: al uw aandacht voor de persoon tegenover u, niet voor de rij.`],[`En sortant : produit remis en main, un conseil utile, reprendre ce que le client a dit, et « Au revoir, à bientôt » en le regardant.`,`Bij het buitengaan: product in de hand gegeven, een nuttige tip, herhalen wat de klant zei, en « Tot ziens, tot binnenkort » terwijl u hem aankijkt.`]],scripts:[{ctx:[`Une cliente a acheté une grande tarte pour un anniversaire.`,`Een klant kocht een grote taart voor een verjaardag.`],bad:[`« 24 €. Merci, au revoir. » en se tournant déjà vers le client suivant.`,`« 24 €. Bedankt, dag. » terwijl u zich al naar de volgende klant draait.`],good:[`« Voilà votre tarte, je vous la donne à plat. Gardez-la au frais jusqu'au dessert. Très bon anniversaire dimanche, et à bientôt ! »`,`« Alstublieft, uw taart, ik geef ze u plat mee. Bewaar ze koel tot het dessert. Een heel fijne verjaardag zondag, en tot binnenkort! »`]}],exo:[`En équipe, 15 min : une personne entre comme un client, achète, ressort, puis dit ce qu'elle a ressenti. Vous changez les rôles.`,`In team, 15 min: iemand komt binnen als klant, koopt, gaat buiten en vertelt wat hij voelde. Daarna wisselt u.`],gain:[`Ce que ça fait progresser : le retour des clients, et les avis 5★.`,`Wat het doet groeien: de terugkeer van de klanten, en de 5★-reviews.`]},{rule:[`On ouvre pour découvrir, on ferme pour conclure. Dans cet ordre.`,`Open om te ontdekken, gesloten om af te sluiten. In die volgorde.`],points:[[`« Ce sera tout ? » est une question fermée : le client dit oui, paie, et repart avec un seul article.`,`« Was dat alles? » is een gesloten vraag: de klant zegt ja, betaalt en vertrekt met één artikel.`],[`Question ouverte (quoi, comment, pour qui, pour quelle occasion) : le client parle, et vous proposez le bon article. Deux maximum.`,`Open vraag (wat, hoe, voor wie, voor welke gelegenheid): de klant praat, en u stelt het juiste artikel voor. Maximum twee.`],[`Proposition : vous partez de ce que le client vient de dire, et vous faites goûter quand c'est possible.`,`Voorstel: u vertrekt van wat de klant net zei, en u laat proeven als het kan.`],[`Conclusion : une fermée à choix. « La grande ou la moyenne ? » Vous ne demandez pas s'il en veut, mais lequel.`,`Afsluiten: een gesloten vraag met keuze. « De grote of de middelgrote? » U vraagt niet óf, maar wélk.`]],scripts:[{ctx:[`Un client entre et demande une baguette.`,`Een klant komt binnen en vraagt een baguette.`],bad:[`« Une baguette. Ce sera tout ? » → « Oui. »`,`« Een baguette. Was dat alles? » → « Ja. »`],good:[`« Une baguette, parfait. Elle accompagne quoi ce midi ? » → « Une salade. » → « Alors goûtez notre quiche feta, elle va très bien avec une salade. Je vous mets une part ou la demi ? »`,`« Een baguette, perfect. Waarbij eet u ze vanmiddag? » → « Een salade. » → « Proef dan onze quiche met feta, die past heel goed bij een salade. Mag het een stuk of de helft zijn? »`]}],exo:[`Par deux, 10 min : une question ouverte, une proposition liée à la réponse, une fermée à choix. Trois situations : une baguette, un gâteau d'anniversaire, le café du matin.`,`Met twee, 10 min: één open vraag, een passend voorstel, een gesloten vraag met keuze. Drie situaties: een baguette, een verjaardagstaart, de ochtendkoffie.`],gain:[`Ce que ça fait progresser : vos ventes additionnelles.`,`Wat het doet groeien: uw bijverkopen.`]},{rule:[`À chaque client, une proposition, liée à ce qu'il prend, au bon moment : quand le premier produit est servi, avant d'annoncer le prix.`,`Bij elke klant één voorstel, passend bij wat hij neemt, op het juiste moment: als het eerste product geserveerd is, voor u de prijs zegt.`],points:[[`Le panier moyen du réseau tourne autour de 13 €. Un client qui repart avec un seul article, c'est une vente à moitié faite.`,`Het gemiddelde mandje in het netwerk ligt rond 13 €. Een klant die met één artikel vertrekt, is een half gedane verkoop.`],[`Un seul article, nommé, en choix entre deux : « Avec votre café, un croissant ou un pain au chocolat ? »`,`Eén artikel, bij naam, als keuze tussen twee: « Bij uw koffie, een croissant of een chocoladebroodje? »`],[`Café → viennoiserie. Viennoiserie → café ou jus. Sandwich → la formule. Baguette → une part de quiche ou de tarte. Gâteau → une boisson ou des bougies.`,`Koffie → viennoiserie. Viennoiserie → koffie of sap. Sandwich → de formule. Baguette → een stuk quiche of taart. Taart → een drank of kaarsjes.`],[`Si le client dit non, vous encaissez sans insister : « Avec plaisir, bonne journée. »`,`Zegt de klant nee, dan rekent u af zonder aan te dringen: « Met plezier, nog een fijne dag. »`]],scripts:[{ctx:[`Une cliente prend un sandwich à midi.`,`Een klant neemt om 12 u een sandwich.`],bad:[`« Autre chose ? »`,`« Nog iets? »`],good:[`« En formule, vous avez la boisson et le dessert. Plutôt eau ou limonade ? »`,`« Als formule hebt u de drank en het dessert erbij. Liever water of limonade? »`]}],exo:[`Par deux, 10 min : à chaque passage, une proposition liée, en choix entre deux.`,`Met twee, 10 min: bij elke klant een passend voorstel, als keuze tussen twee.`],gain:[`Ce que ça fait progresser : vos ventes additionnelles et votre chiffre d'affaires vendu.`,`Wat het doet groeien: uw bijverkopen en uw verkochte omzet.`]},{rule:[`On dit ce qu'on a, ce qu'on peut faire, et quand. Jamais ce qu'on n'a pas, ce qu'on ne peut pas faire, ou pourquoi c'est compliqué.`,`U zegt wat u hebt, wat u kunt doen, en wanneer. Nooit wat u niet hebt, wat u niet kunt doen, of waarom het moeilijk is.`],points:[[`Le cerveau du client entend le mot, pas la négation. « Pas de souci » fait entendre souci.`,`Het brein van de klant hoort het woord, niet de ontkenning. « Geen probleem » laat probleem horen.`],[`Les mots à sortir du comptoir : pas, rien, plus, jamais, problème, souci, désolé(e), malheureusement, impossible, normalement.`,`De woorden die weg moeten van de toog: niet, niets, geen … meer, nooit, probleem, zorgen, sorry, helaas, onmogelijk, normaal gezien.`],[`Rupture, en trois temps : ce que j'ai maintenant, ce que je vous propose, quand l'autre revient.`,`Product op, in drie stappen: wat ik nu heb, wat ik u voorstel, wanneer het andere terugkomt.`],[`Positif ne veut pas dire mentir : on ne promet pas un délai qu'on ne tient pas, et on vérifie la fiche allergènes avant de répondre.`,`Positief betekent niet liegen: u belooft geen termijn die u niet haalt, en u controleert de allergenenfiche voor u antwoordt.`]],scripts:[{ctx:[`Il n'y a plus de tarte framboise.`,`De frambozentaart is op.`],bad:[`« Désolée, il n'y a plus de framboise. »`,`« Sorry, de framboos is op. »`],good:[`« Il me reste la tarte aux pommes, elle sort du four. Et si vous tenez à la framboise, je vous en réserve une pour demain. »`,`« Ik heb nog de appeltaart, die komt net uit de oven. En als u echt framboos wilt, reserveer ik er een voor u voor morgen. »`]}],exo:[`Votre responsable lit dix phrases négatives ; vous reformulez chacune en moins de 5 secondes. Puis pendant une semaine, chaque « pas de souci » coûte un café à l'équipe.`,`Uw verantwoordelijke leest tien negatieve zinnen voor; u herformuleert elke zin in minder dan 5 seconden. Daarna kost elke « geen probleem » een week lang een koffie aan het team.`],gain:[`Ce que ça fait progresser : tout le reste.`,`Wat het doet groeien: al de rest.`]},{rule:[`Expliquer en une phrase pourquoi ce produit est chez nous et pas un autre. Sinon le client compare le prix, et sur le prix, on perd.`,`In één zin uitleggen waarom dit product bij ons is en geen ander. Anders vergelijkt de klant de prijs, en op de prijs verliezen we.`],points:[[`Ce que vous pouvez dire, parce que c'est vrai : préparé cru dans notre atelier et cuit ici toute la journée ; levain doux, sans améliorant ni conservateur ; farine Label Rouge pour la baguette et le pain tradition ; se congèle chez vous.`,`Wat u mag zeggen, omdat het waar is: rauw bereid in ons atelier en hier de hele dag gebakken; zacht desem, zonder verbeteraar of bewaarmiddel; Label Rouge-bloem voor baguette en traditiebrood; thuis in te vriezen.`],[`La phrase produit : ce que c'est, ce qui le rend différent, ce que le client y gagne. Moins de 10 secondes.`,`De productzin: wat het is, wat het anders maakt, wat de klant eraan heeft. Minder dan 10 seconden.`],[`« Du gluten, des fruits à coque ? » → « Je vérifie la fiche allergènes pour vous. » Toujours sur la fiche, jamais de mémoire.`,`« Gluten, noten? » → « Ik kijk de allergenenfiche voor u na. » Altijd op de fiche, nooit uit het hoofd.`],[`« C'est plus cher qu'à côté. » → la phrase produit. Vous ne parlez jamais de l'autre boutique.`,`« Het is duurder dan hiernaast. » → de productzin. U spreekt nooit over de andere winkel.`]],scripts:[{ctx:[`Un client hésite devant la baguette.`,`Een klant twijfelt bij de baguette.`],bad:null,good:[`« Notre baguette, c'est de la farine Label Rouge et un levain doux, et elle est sortie du four il y a une heure. »`,`« Onze baguette is gemaakt met Label Rouge-bloem en zacht desem, en ze kwam een uur geleden uit de oven. »`]}],exo:[`Dégustation en équipe, puis cinq produits, cinq phrases de moins de 10 secondes. Votre responsable joue le client : « C'est plus cher qu'à côté. »`,`Proeverij in team, dan vijf producten, vijf zinnen van minder dan 10 seconden. Uw verantwoordelijke speelt de klant: « Het is duurder dan hiernaast. »`],gain:[`Ce que ça fait progresser : votre chiffre d'affaires vendu.`,`Wat het doet groeien: uw verkochte omzet.`]},{rule:[`Un client qui appelle a déjà envie d'acheter. Il raccroche avec une commande ou un moment de passage prévu, jamais avec une simple information.`,`Een klant die belt, heeft al zin om te kopen. Hij hangt op met een bestelling of een afgesproken bezoek, nooit met alleen een inlichting.`],points:[[`Décrocher avant la troisième sonnerie : « L'Atelier By [boutique], bonjour, [prénom] à l'appareil. Qu'est-ce qui vous ferait plaisir ? »`,`Opnemen voor de derde beltoon: « L'Atelier By [winkel], goeiedag, met [voornaam]. Waarmee kan ik u een plezier doen? »`],[`Découvrir (occasion, nombre, jour), proposer avec la phrase produit, conclure : « Je vous envoie le lien du webshop, ou je note la commande ? » Puis récapituler.`,`Ontdekken (gelegenheid, aantal, dag), voorstellen met de productzin, afsluiten: « Stuur ik u de link van de webshop, of noteer ik de bestelling? » Dan samenvatten.`],[`Avis 5★ : demander quand le client fait un compliment ou qu'un habitué repart content. Jamais à un client pressé ou mécontent, jamais contre un cadeau.`,`5★-reviews: vragen als de klant een compliment geeft of een vaste klant tevreden vertrekt. Nooit aan een klant met haast of ontevreden, nooit in ruil voor iets.`],[`Avis négatif ou client mécontent : vous prévenez votre responsable le jour même. C'est lui qui répond, sous 48 h.`,`Negatieve review of ontevreden klant: u verwittigt uw verantwoordelijke dezelfde dag. Hij antwoordt, binnen 48 u.`]],scripts:[{ctx:[`Un client vous dit : « Elle était délicieuse, votre tarte de dimanche. »`,`Een klant zegt: « Uw taart van zondag was heerlijk. »`],bad:null,good:[`« Merci, ça fait plaisir à toute l'équipe. Vous nous le dites sur Google ? Le QR code est juste là, ça prend trente secondes. »`,`« Dank u, dat doet het hele team plezier. Zegt u het ons op Google? De QR-code ligt hier, het duurt dertig seconden. »`]}],exo:[`Par deux, dos à dos, 15 min : trois appels qui finissent chacun par une commande ou un passage prévu, avec le récapitulatif. Puis deux demandes d'avis au comptoir.`,`Met twee, rug aan rug, 15 min: drie gesprekken die elk eindigen met een bestelling of een afgesproken bezoek, met samenvatting. Dan twee reviewvragen aan de toog.`],gain:[`Ce que ça fait progresser : votre chiffre d'affaires vendu, et la note Google de la boutique.`,`Wat het doet groeien: uw verkochte omzet, en de Google-score van de winkel.`]}],vt=(e,n=t.seasons)=>n.filter(t=>t.m.includes(e)),yt=(e,n=t.seasons)=>n.find(t=>t.m[0]>e&&!t.m.includes(e))||n[0]||null,bt=(e,n,r=t.products,a=x)=>({id:e.id,name:S(e.n,n),img:i(e.img),dates:S(e.dates,n),tip:S(e.tip,n),products:r.filter(t=>t.season===e.id).map(e=>w(e,n,a))}),xt=(e,n=t.products)=>n.filter(e=>e.best).map(t=>w(t,e)),St=[{id:`gluten`,label:[`Sans gluten ?`,`Glutenvrij?`],view:`al`,extra:{ex:[`gluten`]}},{id:`lait`,label:[`Sans lait ?`,`Zonder melk?`],view:`al`,extra:{ex:[`lait`]}},{id:`noix`,label:[`Sans fruits à coque ?`,`Zonder noten?`],view:`al`,extra:{ex:[`noix`,`arach`]}},{id:`vegan`,label:[`Quelque chose de vegan ?`,`Iets veganistisch?`],view:`gamme`,extra:{vegan:!0,cat:`all`}},{id:`gateau`,label:[`Commander un gâteau`,`Een taart bestellen`],view:`svc`},{id:`lunch`,label:[`Un lunch rapide`,`Een snelle lunch`],view:`ventes`}],Ct=e=>St.map(t=>({id:t.id,label:S(t.label,e),sub:h(t.view,e),view:t.view,extra:t.extra})),wt=_t.length,Tt=(e,t)=>{let n=yt(t);return{quick:Ct(e),now:vt(t).map(t=>bt(t,e)),next:n&&bt(n,e),best:xt(e)}},B={page:`_page_zgttk_3`,intro:`_intro_zgttk_10`,h1:`_h1_zgttk_16`,lead:`_lead_zgttk_25`,asks:`_asks_zgttk_32`,eyebrow:`_eyebrow_zgttk_38`,askGrid:`_askGrid_zgttk_45`,ask:`_ask_zgttk_32`,askLabel:`_askLabel_zgttk_72`,askSub:`_askSub_zgttk_79`,onb:`_onb_zgttk_85`,onbThumb:`_onbThumb_zgttk_104`,onbText:`_onbText_zgttk_120`,onbEyebrow:`_onbEyebrow_zgttk_127`,onbTitle:`_onbTitle_zgttk_134`,onbArrow:`_onbArrow_zgttk_140`,season:`_season_zgttk_145`,seasonImg:`_seasonImg_zgttk_155`,seasonBody:`_seasonBody_zgttk_161`,seasonMeta:`_seasonMeta_zgttk_168`,badge:`_badge_zgttk_175`,dates:`_dates_zgttk_186`,h2:`_h2_zgttk_191`,tip:`_tip_zgttk_200`,tipLabel:`_tipLabel_zgttk_208`,next:`_next_zgttk_215`,nextImg:`_nextImg_zgttk_225`,nextText:`_nextText_zgttk_232`,nextEyebrow:`_nextEyebrow_zgttk_240`,nextTip:`_nextTip_zgttk_247`,best:`_best_zgttk_253`,bestGrid:`_bestGrid_zgttk_259`,bestTile:`_bestTile_zgttk_265`,bestImg:`_bestImg_zgttk_281`,bestName:`_bestName_zgttk_288`,bestPrice:`_bestPrice_zgttk_296`};function Et(){let{L:e,lang:t,actions:n}=d(),r=Qe(),a=(0,o.useMemo)(()=>Tt(t,r),[t,r]);return(0,g.jsxs)(`section`,{className:B.page,children:[(0,g.jsxs)(`div`,{className:B.intro,children:[(0,g.jsx)(`h1`,{className:B.h1,children:e.hello}),(0,g.jsx)(`p`,{className:B.lead,children:e.homeIntro})]}),(0,g.jsxs)(`div`,{className:B.asks,children:[(0,g.jsx)(`span`,{id:`home-asks`,className:B.eyebrow,children:e.asks}),(0,g.jsx)(`div`,{className:B.askGrid,role:`group`,"aria-labelledby":`home-asks`,children:a.quick.map(e=>(0,g.jsxs)(`button`,{type:`button`,className:B.ask,onClick:()=>n.go(e.view,e.extra),children:[(0,g.jsx)(`span`,{className:B.askLabel,children:e.label}),(0,g.jsxs)(`span`,{className:B.askSub,children:[e.sub,` `,(0,g.jsx)(`span`,{"aria-hidden":`true`,children:`→`})]})]},e.id))})]}),(0,g.jsxs)(`button`,{type:`button`,className:B.onb,onClick:()=>n.go(`onb`),children:[(0,g.jsx)(`span`,{className:B.onbThumb,children:(0,g.jsx)(`img`,{src:i(`img/onb/croissant.png`),alt:``})}),(0,g.jsxs)(`span`,{className:B.onbText,children:[(0,g.jsx)(`span`,{className:B.onbEyebrow,children:`Onboarding`}),(0,g.jsx)(`span`,{className:B.onbTitle,children:re(t).homeT(wt)})]}),(0,g.jsx)(`span`,{className:B.onbArrow,"aria-hidden":`true`,children:`→`})]}),a.now.map(e=>(0,g.jsx)(Dt,{season:e},e.id)),a.next&&(0,g.jsxs)(`div`,{className:B.next,children:[(0,g.jsx)(`img`,{src:a.next.img,alt:``,className:B.nextImg}),(0,g.jsxs)(`div`,{className:B.nextText,children:[(0,g.jsxs)(`span`,{className:B.nextEyebrow,children:[e.next,` · `,a.next.name,a.next.dates&&(0,g.jsxs)(g.Fragment,{children:[` · `,a.next.dates]})]}),a.next.tip&&(0,g.jsx)(`span`,{className:B.nextTip,children:a.next.tip})]})]}),a.best.length>0&&(0,g.jsxs)(`div`,{className:B.best,children:[(0,g.jsx)(z,{children:e.best}),(0,g.jsx)(`div`,{className:B.bestGrid,children:a.best.map(e=>(0,g.jsxs)(`button`,{type:`button`,className:B.bestTile,onClick:()=>n.openProduct(e.id),children:[(0,g.jsx)(`img`,{src:e.img,alt:``,loading:`lazy`,decoding:`async`,className:B.bestImg}),(0,g.jsx)(`span`,{className:B.bestName,children:e.name}),(0,g.jsx)(`span`,{className:B.bestPrice,children:e.price})]},e.id))})]})]})}function Dt({season:e}){let{L:t}=d(),n=`home-season-${e.id}`;return(0,g.jsxs)(`article`,{className:B.season,"aria-labelledby":n,children:[(0,g.jsx)(`img`,{src:e.img,alt:``,className:B.seasonImg}),(0,g.jsxs)(`div`,{className:B.seasonBody,children:[(0,g.jsxs)(`div`,{className:B.seasonMeta,children:[(0,g.jsx)(`span`,{className:B.badge,children:t.now}),(0,g.jsx)(`span`,{className:B.dates,children:e.dates})]}),(0,g.jsx)(`h2`,{id:n,className:B.h2,children:e.name}),e.tip&&(0,g.jsxs)(`div`,{className:B.tip,children:[(0,g.jsx)(`span`,{className:B.tipLabel,children:t.tipL}),(0,g.jsx)(`span`,{children:e.tip})]}),e.products.length>0&&(0,g.jsx)(y,{children:e.products.map(e=>(0,g.jsx)(v,{p:e,size:`lg`},e.id))})]})]})}var V={row:`_row_wcumf_1`,chip:`_chip_wcumf_12`,active:`_active_wcumf_26`};function Ot({children:e,label:t}){return(0,g.jsx)(`div`,{className:`noscroll ${V.row}`,role:`group`,"aria-label":t,children:e})}function kt({active:e,onClick:t,children:n}){return(0,g.jsx)(`button`,{type:`button`,className:e?`${V.chip} ${V.active}`:V.chip,"aria-pressed":e,onClick:t,children:n})}var At=(e,n=t.categories)=>[{id:`all`,label:p(e).all},...n.map(t=>({id:t.id,label:S(t.n,e)}))],jt=(e,t)=>!t||e.diet===`vegan`,Mt=(e,n,r,i=t.categories,a=t.products)=>i.filter(t=>e===`all`||e===t.id).map(e=>{let t=a.filter(t=>t.cat===e.id&&jt(t,n)).map(e=>w(e,r));return{id:e.id,name:S(e.n,r),count:t.length,items:t}}).filter(e=>e.items.length>0),H={page:`_page_12tmf_1`,vegan:`_vegan_12tmf_8`,veganOn:`_veganOn_12tmf_23`,sep:`_sep_12tmf_29`,group:`_group_12tmf_35`,head:`_head_12tmf_41`,count:`_count_12tmf_47`};function Nt(){let{state:e,lang:t,L:n,actions:r}=d(),i=(0,o.useMemo)(()=>At(t),[t]),a=(0,o.useMemo)(()=>Mt(e.cat,e.vegan,t),[e.cat,e.vegan,t]);return(0,g.jsxs)(`section`,{className:H.page,children:[(0,g.jsx)(R,{children:n.gammeTitle}),(0,g.jsxs)(Ot,{label:n.gammeTitle,children:[(0,g.jsxs)(`button`,{type:`button`,className:e.vegan?`${H.vegan} ${H.veganOn}`:H.vegan,"aria-pressed":e.vegan,onClick:r.toggleVegan,children:[`VEGAN`,e.vegan&&(0,g.jsx)(`span`,{"aria-hidden":`true`,children:` ✕`})]}),(0,g.jsx)(`span`,{className:H.sep,"aria-hidden":`true`}),i.map(t=>(0,g.jsx)(kt,{active:e.cat===t.id,onClick:()=>r.setCat(t.id),children:t.label},t.id))]}),a.map(e=>(0,g.jsxs)(`div`,{className:H.group,children:[(0,g.jsxs)(`div`,{className:H.head,children:[(0,g.jsx)(z,{children:e.name}),(0,g.jsx)(`span`,{className:H.count,children:e.count})]}),(0,g.jsx)(ht,{children:e.items.map(e=>(0,g.jsx)(mt,{p:e},e.id))})]},e.id))]})}var Pt=(e,t)=>oe(e).map((e,n)=>({label:e,month:n+1,current:n+1===t})),Ft=(e,n,r=t.seasons)=>r.map(t=>({id:t.id,name:S(t.n,e),cells:Array.from({length:12},(e,r)=>({on:t.m.includes(r+1),current:r+1===n}))})),It=(e,n,r=t.seasons,i=t.products)=>r.map(t=>({...bt(t,e,i),isNow:t.m.includes(n)})),U={page:`_page_1x5bp_1`,calCard:`_calCard_1x5bp_8`,cal:`_cal_1x5bp_8`,row:`_row_1x5bp_22`,corner:`_corner_1x5bp_27`,month:`_month_1x5bp_31`,monthNow:`_monthNow_1x5bp_42`,name:`_name_1x5bp_48`,cell:`_cell_1x5bp_57`,cellNow:`_cellNow_1x5bp_64`,bar:`_bar_1x5bp_68`,cards:`_cards_1x5bp_78`,card:`_card_1x5bp_78`,cardHead:`_cardHead_1x5bp_93`,cardImg:`_cardImg_1x5bp_99`,cardText:`_cardText_1x5bp_105`,titleRow:`_titleRow_1x5bp_112`,cardTitle:`_cardTitle_1x5bp_119`,now:`_now_1x5bp_127`,dates:`_dates_1x5bp_136`,tip:`_tip_1x5bp_141`,tipL:`_tipL_1x5bp_146`};function Lt(){let{lang:e,L:t}=d(),n=Qe(),r=(0,o.useMemo)(()=>Pt(e,n),[e,n]),i=(0,o.useMemo)(()=>Ft(e,n),[e,n]),a=(0,o.useMemo)(()=>It(e,n),[e,n]);return(0,g.jsxs)(`section`,{className:U.page,children:[(0,g.jsx)(R,{children:t.calTitle}),i.length>0&&(0,g.jsx)(`div`,{className:U.calCard,children:(0,g.jsxs)(`div`,{className:U.cal,role:`table`,"aria-label":t.calTitle,children:[(0,g.jsxs)(`div`,{className:U.row,role:`row`,children:[(0,g.jsx)(`div`,{role:`columnheader`,className:U.corner,children:(0,g.jsx)(`span`,{className:`sr-only`,children:t.calTitle})}),r.map(e=>(0,g.jsx)(`div`,{role:`columnheader`,className:e.current?`${U.month} ${U.monthNow}`:U.month,"aria-current":e.current?`date`:void 0,children:e.label},e.month))]}),i.map(e=>(0,g.jsxs)(`div`,{className:U.row,role:`row`,children:[(0,g.jsx)(`div`,{role:`rowheader`,className:U.name,children:e.name}),e.cells.map((e,t)=>(0,g.jsx)(`div`,{role:`cell`,className:e.current?`${U.cell} ${U.cellNow}`:U.cell,children:e.on&&(0,g.jsx)(`div`,{className:U.bar,children:(0,g.jsx)(`span`,{className:`sr-only`,children:r[t].label})})},t))]},e.id))]})}),(0,g.jsx)(`div`,{className:U.cards,children:a.map(e=>(0,g.jsx)(Rt,{season:e},e.id))})]})}function Rt({season:e}){let{L:t}=d();return(0,g.jsxs)(`div`,{className:U.card,children:[(0,g.jsxs)(`div`,{className:U.cardHead,children:[(0,g.jsx)(`img`,{src:e.img,alt:``,className:U.cardImg}),(0,g.jsxs)(`div`,{className:U.cardText,children:[(0,g.jsxs)(`div`,{className:U.titleRow,children:[(0,g.jsx)(`h2`,{className:U.cardTitle,children:e.name}),e.isNow&&(0,g.jsx)(`span`,{className:U.now,children:t.now})]}),(0,g.jsx)(`span`,{className:U.dates,children:e.dates})]})]}),e.tip&&(0,g.jsxs)(`div`,{className:U.tip,children:[(0,g.jsxs)(`span`,{className:U.tipL,children:[t.tipL,` · `]}),e.tip]}),e.products.length>0&&(0,g.jsx)(y,{gap:8,children:e.products.map(e=>(0,g.jsx)(v,{p:e,size:`md`},e.id))})]})}function zt(e,t){let n=t.some(t=>e.al.includes(t)),r=!n&&C(e),i=!n&&!r&&t.length>0&&(e.trKnown===!1||t.some(t=>e.tr.includes(t)));return{bad:n,warn:i,ok:t.length>0&&!n&&!i&&!r,unknown:r}}function Bt(e,n,r=t.products,i=t.allergens){let a=e=>n.includes(e.id),o=i.map(t=>({id:t.id,name:S(t.n,e),on:a(t)})),s=i.map(t=>({id:t.id,code:t.s,name:S(t.n,e),on:a(t)})),c=0,l=0,u=0,d=r.map(t=>{let r=zt(t,n);r.ok&&c++,r.warn&&l++,r.unknown&&u++;let o=C(t);return{id:t.id,name:S(t.name,e),...r,cells:i.map(e=>{let n=t.al.includes(e.id),r=t.tr.includes(e.id);return{id:e.id,contains:n,traces:r,unknown:o&&!n&&!r,on:a(e)}})}});return{hasEx:n.length>0,chips:o,headers:s,rows:d,okCount:c,warnCount:l,unknownCount:u}}var Vt=[{status:`Statut`,product:`Produit`,bad:`Ne convient pas`},{status:`Status`,product:`Product`,bad:`Niet geschikt`}],Ht=e=>Vt[e],W={page:`_page_6z37d_1`,filter:`_filter_6z37d_8`,filterHead:`_filterHead_6z37d_17`,intro:`_intro_6z37d_24`,reset:`_reset_6z37d_29`,chips:`_chips_6z37d_43`,chip:`_chip_6z37d_43`,chipOn:`_chipOn_6z37d_61`,counts:`_counts_6z37d_68`,okN:`_okN_6z37d_76`,warnN:`_warnN_6z37d_81`,unkN:`_unkN_6z37d_86`,matrixCard:`_matrixCard_6z37d_92`,grid:`_grid_6z37d_99`,row:`_row_6z37d_107`,code:`_code_6z37d_111`,codeOn:`_codeOn_6z37d_120`,sticky:`_sticky_6z37d_130`,stickyName:`_stickyName_6z37d_138`,status:`_status_6z37d_142`,okPill:`_okPill_6z37d_150`,warnPill:`_warnPill_6z37d_151`,unkPill:`_unkPill_6z37d_152`,name:`_name_6z37d_177`,cell:`_cell_6z37d_211`,cellOn:`_cellOn_6z37d_220`,dim:`_dim_6z37d_225`,dot:`_dot_6z37d_230`,unk:`_unk_6z37d_86`,ring:`_ring_6z37d_247`,legend:`_legend_6z37d_255`,legendItem:`_legendItem_6z37d_265`,note:`_note_6z37d_271`},G=(...e)=>e.filter(Boolean).join(` `);function Ut(){let{state:e,lang:t,L:n,actions:r}=d(),i=(0,o.useMemo)(()=>Bt(t,e.ex),[t,e.ex]),a=Ht(t),s=(0,o.useId)(),c=(0,o.useRef)(null);return(0,g.jsxs)(`section`,{className:W.page,children:[(0,g.jsx)(R,{children:n.alTitle}),(0,g.jsxs)(`div`,{className:W.filter,children:[(0,g.jsxs)(`div`,{className:W.filterHead,children:[(0,g.jsx)(`span`,{id:s,className:W.intro,children:n.alIntro}),i.hasEx&&(0,g.jsx)(`button`,{type:`button`,className:W.reset,onClick:()=>{r.resetEx(),c.current?.querySelector(`button`)?.focus({preventScroll:!0})},children:n.alReset})]}),(0,g.jsx)(`div`,{ref:c,className:W.chips,role:`group`,"aria-labelledby":s,children:i.chips.map(e=>(0,g.jsx)(`button`,{type:`button`,className:G(W.chip,e.on&&W.chipOn),"aria-pressed":e.on,onClick:()=>r.toggleAllergen(e.id),children:e.name},e.id))}),i.hasEx&&(0,g.jsxs)(`div`,{className:W.counts,"aria-hidden":`true`,children:[(0,g.jsxs)(`span`,{children:[(0,g.jsx)(`b`,{className:W.okN,children:i.okCount}),` `,n.alOk]}),(0,g.jsxs)(`span`,{children:[(0,g.jsx)(`b`,{className:W.warnN,children:i.warnCount}),` `,n.alWarn]}),i.unknownCount>0&&(0,g.jsxs)(`span`,{children:[(0,g.jsx)(`b`,{className:W.unkN,children:i.unknownCount}),` `,n.alUnkN]})]}),(0,g.jsx)(`p`,{className:`sr-only`,role:`status`,children:i.hasEx?`${i.okCount} ${n.alOk}, ${i.warnCount} ${n.alWarn}`+(i.unknownCount>0?`, ${i.unknownCount} ${n.alUnkN}`:``):``})]}),(0,g.jsxs)(`div`,{className:W.matrixCard,role:`region`,"aria-label":n.alTitle,tabIndex:0,children:[(0,g.jsxs)(`div`,{className:W.grid,role:`table`,"aria-label":n.alTitle,children:[(0,g.jsxs)(`div`,{className:W.row,role:`row`,children:[(0,g.jsx)(`div`,{role:`columnheader`,"aria-label":a.status}),(0,g.jsx)(`div`,{role:`columnheader`,"aria-label":a.product}),i.headers.map(e=>(0,g.jsx)(`div`,{role:`columnheader`,title:e.name,"aria-label":e.name,className:e.on?`${W.code} ${W.codeOn}`:W.code,children:e.code},e.id))]}),i.rows.map(e=>(0,g.jsxs)(`div`,{className:W.row,role:`row`,children:[(0,g.jsx)(`div`,{role:`cell`,className:W.sticky,children:(0,g.jsxs)(`div`,{className:G(W.status,e.bad&&W.dim),children:[e.ok&&(0,g.jsx)(`span`,{className:W.okPill,children:`OK`}),e.warn&&(0,g.jsx)(`span`,{className:W.warnPill,children:n.trS}),e.unknown&&(0,g.jsx)(`span`,{className:W.unkPill,title:n.alCheck,children:n.alUnk}),e.bad&&(0,g.jsx)(`span`,{className:`sr-only`,children:a.bad})]})}),(0,g.jsx)(`div`,{role:`rowheader`,className:`${W.sticky} ${W.stickyName}`,children:(0,g.jsx)(`button`,{type:`button`,className:G(W.name,e.bad&&W.dim),onClick:()=>r.openProduct(e.id),children:e.name})}),e.cells.map(t=>(0,g.jsxs)(`div`,{role:`cell`,className:G(W.cell,t.on&&W.cellOn,e.bad&&W.dim),children:[t.contains&&(0,g.jsx)(`span`,{role:`img`,"aria-label":n.contains,className:W.dot}),t.traces&&(0,g.jsx)(`span`,{role:`img`,"aria-label":n.traces,className:W.ring}),t.unknown&&(0,g.jsx)(`span`,{role:`img`,"aria-label":n.alCheck,className:W.unk,children:`?`})]},t.id))]},e.id))]}),(0,g.jsxs)(`div`,{className:W.legend,children:[(0,g.jsxs)(`span`,{className:W.legendItem,children:[(0,g.jsx)(`span`,{className:W.dot,"aria-hidden":`true`}),n.contains]}),(0,g.jsxs)(`span`,{className:W.legendItem,children:[(0,g.jsx)(`span`,{className:W.ring,"aria-hidden":`true`}),n.traces]}),i.unknownCount>0&&(0,g.jsxs)(`span`,{className:W.legendItem,children:[(0,g.jsx)(`span`,{className:W.unk,"aria-hidden":`true`,children:`?`}),n.alCheck]})]})]}),(0,g.jsx)(`p`,{className:W.note,children:n.alNote})]})}var Wt=(e,n=t.combos,r,i=x)=>n.map((t,n)=>({id:String(n),name:S(t.n,e),when:S(t.when,e),price:me(t.price,r),items:Ce(t.items,e,i)})),Gt=(e,n=t.reflexes)=>n.map((t,n)=>({n:String(n+1),text:S(t,e)})),Kt=(e,n=t.products,r=x)=>n.map(t=>({id:t.id,name:S(t.name,e),cross:Se(t.cross,r.products).map(t=>S(t.name,e)).join(` · `),line:S(t.crossLine,e)})).filter(e=>e.cross||e.line),K={page:`_page_2i3vb_1`,block:`_block_2i3vb_8`,combos:`_combos_2i3vb_15`,combo:`_combo_2i3vb_15`,comboHead:`_comboHead_2i3vb_30`,comboTitle:`_comboTitle_2i3vb_37`,comboName:`_comboName_2i3vb_43`,comboWhen:`_comboWhen_2i3vb_48`,comboPrice:`_comboPrice_2i3vb_53`,tiles:`_tiles_2i3vb_60`,tile:`_tile_2i3vb_60`,tileImg:`_tileImg_2i3vb_83`,tileName:`_tileName_2i3vb_90`,reflexes:`_reflexes_2i3vb_98`,reflex:`_reflex_2i3vb_98`,reflexN:`_reflexN_2i3vb_118`,list:`_list_2i3vb_125`,pair:`_pair_2i3vb_131`,name:`_name_2i3vb_142`,cross:`_cross_2i3vb_159`,line:`_line_2i3vb_164`};function qt(){let{lang:e,L:t,actions:n}=d(),r=(0,o.useMemo)(()=>({combos:Wt(e),reflexes:Gt(e),pairs:Kt(e)}),[e]);return(0,g.jsxs)(`section`,{className:K.page,children:[(0,g.jsx)(R,{children:t.ventesTitle}),r.combos.length>0&&(0,g.jsxs)(`div`,{className:K.block,children:[(0,g.jsx)(z,{children:t.combos}),(0,g.jsx)(`div`,{className:K.combos,children:r.combos.map(e=>(0,g.jsxs)(`div`,{className:K.combo,children:[(0,g.jsxs)(`div`,{className:K.comboHead,children:[(0,g.jsxs)(`div`,{className:K.comboTitle,children:[(0,g.jsx)(`span`,{className:K.comboName,children:e.name}),(0,g.jsx)(`span`,{className:K.comboWhen,children:e.when})]}),(0,g.jsx)(`span`,{className:K.comboPrice,children:e.price})]}),(0,g.jsx)(`div`,{className:K.tiles,children:e.items.map((e,t)=>(0,g.jsxs)(`button`,{type:`button`,className:K.tile,title:e.name,onClick:()=>n.openProduct(e.id),children:[(0,g.jsx)(`img`,{src:e.img,alt:``,loading:`lazy`,decoding:`async`,className:K.tileImg}),(0,g.jsx)(`span`,{className:K.tileName,children:e.name})]},t+`-`+e.id))})]},e.id))})]}),r.reflexes.length>0&&(0,g.jsxs)(`div`,{className:K.block,children:[(0,g.jsx)(z,{children:t.reflexes}),(0,g.jsx)(`ol`,{className:K.reflexes,role:`list`,children:r.reflexes.map(e=>(0,g.jsxs)(`li`,{className:K.reflex,children:[(0,g.jsx)(`span`,{className:K.reflexN,children:e.n}),(0,g.jsx)(`span`,{children:e.text})]},e.n))})]}),r.pairs.length>0&&(0,g.jsxs)(`div`,{className:K.block,children:[(0,g.jsx)(z,{children:t.pairs}),(0,g.jsx)(`div`,{className:K.list,children:r.pairs.map(e=>(0,g.jsxs)(`div`,{className:K.pair,children:[(0,g.jsx)(`button`,{type:`button`,className:K.name,onClick:()=>n.openProduct(e.id),children:e.name}),(0,g.jsx)(`span`,{className:K.cross,children:e.cross&&(0,g.jsxs)(g.Fragment,{children:[(0,g.jsx)(`span`,{"aria-hidden":`true`,children:`→ `}),e.cross]})}),(0,g.jsx)(`span`,{className:K.line,children:e.line&&(0,g.jsxs)(g.Fragment,{children:[`« `,e.line,` »`]})})]},e.id))})]})]})}var Jt=(e,n=t.faqCats)=>[{id:`all`,label:p(e).all},...n.map(t=>({id:t.id,label:S(t.n,e)}))],Yt=(e,t)=>t===`all`||e.cat===t,Xt=(e,n,r,i=t.faq,a=x)=>i.map((e,t)=>({f:e,index:t})).filter(({f:t})=>Yt(t,e)).map(({f:e,index:t})=>{let i=n===t;return{index:t,q:S(e.q,r),a:S(e.a,r),open:i,sign:i?`−`:`+`,hasProds:!!e.p?.length,prods:Ce(e.p??[],r,a)}}),q={page:`_page_1puno_1`,list:`_list_1puno_7`,item:`_item_1puno_14`,heading:`_heading_1puno_21`,head:`_head_1puno_21`,sign:`_sign_1puno_51`,answer:`_answer_1puno_57`,text:`_text_1puno_67`,linked:`_linked_1puno_72`,eyebrow:`_eyebrow_1puno_78`};function Zt(){let{state:e,lang:t,L:n,actions:r}=d(),i=(0,o.useId)(),a=(0,o.useMemo)(()=>Jt(t),[t]),s=(0,o.useMemo)(()=>Xt(e.faqCat,e.faqOpen,t),[e.faqCat,e.faqOpen,t]);return(0,g.jsxs)(`section`,{className:q.page,children:[(0,g.jsx)(R,{children:n.faqTitle}),(0,g.jsx)(Ot,{label:n.faqTitle,children:a.map(t=>(0,g.jsx)(kt,{active:e.faqCat===t.id,onClick:()=>r.setFaqCat(t.id),children:t.label},t.id))}),(0,g.jsx)(`div`,{className:q.list,children:s.map(e=>{let t=`${i}-a${e.index}`;return(0,g.jsxs)(`div`,{className:q.item,children:[(0,g.jsx)(`h2`,{className:q.heading,children:(0,g.jsxs)(`button`,{type:`button`,className:q.head,"aria-expanded":e.open,"aria-controls":t,onClick:()=>r.toggleFaq(e.index),children:[(0,g.jsx)(`span`,{children:e.q}),(0,g.jsx)(`span`,{className:q.sign,"aria-hidden":`true`,children:e.sign})]})}),(0,g.jsxs)(`div`,{id:t,className:q.answer,hidden:!e.open,children:[(0,g.jsx)(`div`,{className:q.text,children:e.a}),e.hasProds&&(0,g.jsxs)(`div`,{className:q.linked,children:[(0,g.jsx)(`span`,{className:q.eyebrow,children:n.linkedP}),(0,g.jsx)(y,{gap:8,children:e.prods.map((e,t)=>(0,g.jsx)(v,{p:e,size:`sm`,price:!1,hover:!0},t+`-`+e.id))})]})]})]},e.index)})})]})}var Qt=(e,n=t.services)=>n.map(t=>({id:t.id,name:S(t.n,e),img:i(t.img),how:S(t.how,e),delay:S(t.delay,e),say:S(t.say,e)})),J={page:`_page_1r0ol_1`,grid:`_grid_1r0ol_7`,card:`_card_1r0ol_13`,head:`_head_1r0ol_22`,img:`_img_1r0ol_28`,name:`_name_1r0ol_35`,field:`_field_1r0ol_44`,eyebrow:`_eyebrow_1r0ol_50`,value:`_value_1r0ol_57`,delay:`_delay_1r0ol_62`,say:`_say_1r0ol_67`};function $t(){let{lang:e,L:t}=d(),n=(0,o.useMemo)(()=>Qt(e),[e]);return(0,g.jsxs)(`section`,{className:J.page,children:[(0,g.jsx)(R,{children:t.svcTitle}),(0,g.jsx)(`div`,{className:J.grid,children:n.map(e=>(0,g.jsxs)(`div`,{className:J.card,children:[(0,g.jsxs)(`div`,{className:J.head,children:[(0,g.jsx)(`img`,{src:e.img,alt:``,className:J.img}),(0,g.jsx)(`h2`,{className:J.name,children:e.name})]}),(0,g.jsxs)(`div`,{className:J.field,children:[(0,g.jsx)(`span`,{className:J.eyebrow,children:t.how}),(0,g.jsx)(`span`,{className:J.value,children:e.how})]}),(0,g.jsxs)(`div`,{className:J.field,children:[(0,g.jsx)(`span`,{className:J.eyebrow,children:t.delay}),(0,g.jsx)(`span`,{className:`${J.value} ${J.delay}`,children:e.delay})]}),(0,g.jsxs)(`div`,{className:J.say,children:[`« `,e.say,` »`]})]},e.id))})]})}var en=(e,n=t.categories,r=t.products)=>{let i=p(e);return n.map(t=>({id:t.id,name:S(t.n,e),rows:r.filter(e=>e.cat===t.id).map(t=>({id:t.id,name:S(t.name,e),dlc:_e(t.dlc,i),keep:S(t.keep,e)}))})).filter(e=>e.rows.length>0)},Y={page:`_page_19g5a_1`,group:`_group_19g5a_7`,title:`_title_19g5a_14`,list:`_list_19g5a_22`,row:`_row_19g5a_28`,name:`_name_19g5a_39`,dlc:`_dlc_19g5a_56`,keep:`_keep_19g5a_62`};function tn(){let{lang:e,L:t,actions:n}=d(),r=(0,o.useMemo)(()=>en(e),[e]);return(0,g.jsxs)(`section`,{className:Y.page,children:[(0,g.jsx)(R,{children:t.consTitle}),r.map(e=>(0,g.jsxs)(`div`,{className:Y.group,children:[(0,g.jsx)(`h2`,{className:Y.title,children:e.name}),(0,g.jsx)(`div`,{className:Y.list,children:e.rows.map(e=>(0,g.jsxs)(`div`,{className:Y.row,children:[(0,g.jsx)(`button`,{type:`button`,className:Y.name,onClick:()=>n.openProduct(e.id),children:e.name}),(0,g.jsx)(`span`,{className:Y.dlc,children:e.dlc}),(0,g.jsx)(`span`,{className:Y.keep,children:e.keep})]},e.id))})]},e.id))]})}var nn=(e,n=t.stats)=>e===`team`?n.sellers:n.sellers.filter(t=>t.id===e),rn=(e,t)=>t>0?e/t:0,an=(e,t)=>{let n=e.reduce((e,n)=>e+n[t].tickets,0);return{ca:e.reduce((e,n)=>e+n[t].ca,0),tickets:n,cross:Math.round(rn(e.reduce((e,n)=>e+n[t].cross*n[t].tickets,0),n)),saison:e.reduce((e,n)=>e+n[t].saison,0)}},on=(e,t)=>t>0?Math.max(4,Math.min(100,Math.round(e/t*100))):100,sn=(e,n,r,i=t.stats)=>{let a=m(e),o=nn(n,i),s=an(o,r),{obj:c}=i,l=c.saison[r]*o.length,u=rn(s.ca,s.tickets);return[{id:`ca`,label:a.ca,value:he(s.ca,e),sub:s.tickets+` `+a.tk.toLowerCase(),pct:null,hit:null},{id:`pan`,label:a.pan,value:ge(u),sub:a.obj+` `+ge(c.panier),pct:on(u,c.panier),hit:u>=c.panier},{id:`cross`,label:a.cross,value:s.cross+` %`,sub:a.obj+` `+c.cross+` %`,pct:on(s.cross,c.cross),hit:s.cross>=c.cross},{id:`sais`,label:a.sais,value:s.saison+` `+a.units,sub:a.obj+` `+l+` `+a.units,pct:on(s.saison,l),hit:s.saison>=l}]},cn=(e,n,r=t.stats)=>{let i=nn(n,r),a=[0,1,2,3,4,5,6].map(e=>i.reduce((t,n)=>t+n.bars[e],0)),o=Math.max(...a),s=se(e);return a.map((t,n)=>({label:s[n],value:he(t,e),pct:Math.round(rn(t,o)*100),last:n===6}))},ln=e=>{let t=new Map;for(let n of e)for(let[e,r]of n.top)t.set(e,(t.get(e)??0)+r);return[...t.entries()].sort((e,t)=>t[1]-e[1])},un=(e,n,r=t.stats,i=x)=>{let a=m(e).units;return ln(nn(n,r)).flatMap(([t,n])=>{let r=we(t,e,i);return r?[{product:r,qty:n}]:[]}).slice(0,5).map(({product:e,qty:t},n)=>({rank:n+1,product:e,qty:t,qtyLabel:t+` `+a}))},dn=(e,n,r,i=t.stats)=>[...i.sellers].sort((e,t)=>t[r].ca-e[r].ca).map((t,a)=>{let o=t[r];return{id:t.id,rank:a+1,name:t.name,ca:he(o.ca,e),pan:ge(rn(o.ca,o.tickets)),cross:o.cross+` %`,crossHit:o.cross>=i.obj.cross,saison:String(o.saison),on:n===t.id}}),fn=(e,n,r,i=t.stats,a=x)=>{let o=m(e);return{periods:o.per.map(([e,t])=>({id:e,label:t,active:r===e})),sellers:[{id:`team`,name:o.team},...i.sellers].map(e=>({id:e.id,label:e.name,active:n===e.id})),kpis:sn(e,n,r,i),days:cn(e,n,i),top:un(e,n,i,a),rank:dn(e,n,r,i)}},pn=ee({period:`Période`,rankCol:`Rang`,sellers:`Vendeuses`},{period:`Periode`,rankCol:`Positie`,sellers:`Verkoopsters`}),mn=e=>pn[e],X={page:`_page_ke634_3`,head:`_head_ke634_10`,titles:`_titles_ke634_18`,note:`_note_ke634_24`,periods:`_periods_ke634_29`,period:`_period_ke634_29`,periodOn:`_periodOn_ke634_50`,sample:`_sample_ke634_57`,sampleTitle:`_sampleTitle_ke634_71`,kpis:`_kpis_ke634_79`,kpi:`_kpi_ke634_79`,eyebrow:`_eyebrow_ke634_94`,value:`_value_ke634_101`,track:`_track_ke634_108`,fill:`_fill_ke634_115`,fillHit:`_fillHit_ke634_121`,kpiFoot:`_kpiFoot_ke634_125`,hit:`_hit_ke634_133`,miss:`_miss_ke634_138`,duo:`_duo_ke634_144`,panel:`_panel_ke634_150`,chart:`_chart_ke634_159`,day:`_day_ke634_170`,dayValue:`_dayValue_ke634_179`,bar:`_bar_ke634_185`,barLast:`_barLast_ke634_192`,dayLabel:`_dayLabel_ke634_196`,topPanel:`_topPanel_ke634_201`,topTitle:`_topTitle_ke634_205`,topItem:`_topItem_ke634_209`,topRank:`_topRank_ke634_223`,topImg:`_topImg_ke634_230`,topName:`_topName_ke634_237`,topQty:`_topQty_ke634_244`,rankBlock:`_rankBlock_ke634_250`,rankCard:`_rankCard_ke634_256`,rankHead:`_rankHead_ke634_263`,row:`_row_ke634_264`,rowOn:`_rowOn_ke634_300`,pick:`_pick_ke634_306`,num:`_num_ke634_311`,rank:`_rank_ke634_250`,name:`_name_ke634_321`,crossHit:`_crossHit_ke634_339`,crossMiss:`_crossMiss_ke634_340`},Z=(...e)=>e.filter(Boolean).join(` `);function hn(){let{state:e,lang:t,actions:n}=d(),r=m(t),i=mn(t),a=(0,o.useMemo)(()=>fn(t,e.stSel,e.stPer),[t,e.stSel,e.stPer]),s=(0,o.useId)(),c=(0,o.useId)(),l=(0,o.useId)();return(0,g.jsxs)(`section`,{className:X.page,children:[(0,g.jsxs)(`div`,{className:X.head,children:[(0,g.jsxs)(`div`,{className:X.titles,children:[(0,g.jsx)(R,{children:r.title}),(0,g.jsx)(`span`,{className:X.note,children:r.note})]}),(0,g.jsx)(`div`,{className:X.periods,role:`group`,"aria-label":i.period,children:a.periods.map(e=>(0,g.jsx)(`button`,{type:`button`,className:Z(X.period,e.active&&X.periodOn),"aria-pressed":e.active,onClick:()=>n.setStPer(e.id),children:e.label},e.id))})]}),(0,g.jsxs)(`p`,{className:X.sample,children:[(0,g.jsx)(`span`,{className:X.sampleTitle,children:r.sample}),(0,g.jsx)(`span`,{children:r.sampleText})]}),(0,g.jsx)(Ot,{label:i.sellers,children:a.sellers.map(e=>(0,g.jsx)(kt,{active:e.active,onClick:()=>n.setStSel(e.id),children:e.label},e.id))}),(0,g.jsx)(`div`,{className:X.kpis,children:a.kpis.map(e=>(0,g.jsxs)(`div`,{className:X.kpi,children:[(0,g.jsx)(`span`,{className:X.eyebrow,children:e.label}),(0,g.jsx)(`span`,{className:X.value,children:e.value}),e.pct!=null&&(0,g.jsx)(`div`,{className:X.track,children:(0,g.jsx)(`div`,{className:Z(X.fill,!!e.hit&&X.fillHit),style:{width:e.pct+`%`}})}),(0,g.jsxs)(`div`,{className:X.kpiFoot,children:[(0,g.jsx)(`span`,{children:e.sub}),e.hit===!0&&(0,g.jsx)(`span`,{className:X.hit,children:r.reached}),e.hit===!1&&(0,g.jsx)(`span`,{className:X.miss,children:r.toGo})]})]},e.id))}),(0,g.jsxs)(`div`,{className:X.duo,children:[(0,g.jsxs)(`div`,{className:X.panel,children:[(0,g.jsxs)(`span`,{id:s,className:X.eyebrow,children:[r.ca,` · `,r.days]}),(0,g.jsx)(`ul`,{className:X.chart,"aria-labelledby":s,children:a.days.map(e=>(0,g.jsxs)(`li`,{className:X.day,children:[(0,g.jsx)(`span`,{className:X.dayValue,children:e.value}),(0,g.jsx)(`div`,{className:Z(X.bar,e.last&&X.barLast),style:{height:e.pct+`%`},"aria-hidden":`true`}),(0,g.jsx)(`span`,{className:X.dayLabel,children:e.label})]},e.label))})]}),a.top.length>0&&(0,g.jsxs)(`div`,{className:Z(X.panel,X.topPanel),role:`group`,"aria-labelledby":c,children:[(0,g.jsx)(`span`,{id:c,className:Z(X.eyebrow,X.topTitle),children:r.top}),a.top.map(e=>(0,g.jsxs)(`button`,{type:`button`,className:X.topItem,onClick:()=>n.openProduct(e.product.id),children:[(0,g.jsx)(`span`,{className:X.topRank,children:e.rank}),(0,g.jsx)(`img`,{src:e.product.img,alt:``,loading:`lazy`,decoding:`async`,className:X.topImg}),(0,g.jsx)(`span`,{className:X.topName,children:e.product.name}),(0,g.jsx)(`span`,{className:X.topQty,children:e.qtyLabel})]},e.product.id))]})]}),(0,g.jsxs)(`div`,{className:X.rankBlock,children:[(0,g.jsx)(z,{children:(0,g.jsx)(`span`,{id:l,children:r.rank})}),(0,g.jsxs)(`div`,{className:X.rankCard,role:`table`,"aria-labelledby":l,children:[(0,g.jsxs)(`div`,{className:X.rankHead,role:`row`,children:[(0,g.jsx)(`span`,{role:`columnheader`,"aria-label":i.rankCol}),(0,g.jsx)(`span`,{role:`columnheader`,children:r.seller}),(0,g.jsx)(`span`,{role:`columnheader`,className:X.num,children:r.ca}),(0,g.jsx)(`span`,{role:`columnheader`,className:X.num,children:r.pan}),(0,g.jsx)(`span`,{role:`columnheader`,className:X.num,children:r.cross}),(0,g.jsx)(`span`,{role:`columnheader`,className:X.num,children:r.sais})]}),a.rank.map(e=>(0,g.jsxs)(`div`,{className:Z(X.row,e.on&&X.rowOn),role:`row`,onClick:()=>n.pickSellerRow(e.id),children:[(0,g.jsx)(`span`,{role:`cell`,className:X.rank,children:e.rank}),(0,g.jsx)(`span`,{role:`rowheader`,className:X.name,children:(0,g.jsx)(`button`,{type:`button`,className:X.pick,"aria-pressed":e.on,children:e.name})}),(0,g.jsx)(`span`,{role:`cell`,className:X.num,children:e.ca}),(0,g.jsx)(`span`,{role:`cell`,className:X.num,children:e.pan}),(0,g.jsx)(`span`,{role:`cell`,className:Z(X.num,e.crossHit?X.crossHit:X.crossMiss),children:e.cross}),(0,g.jsx)(`span`,{role:`cell`,className:X.num,children:e.saison})]},e.id))]})]})]})}var gn=`# Formation vente — L'Atelier By

Sep 29, 2026 · @Sam

## L'Atelier By \\_\\_\\_\\_\\_\\_\\_\\_ · Votre livret de formation

Formation vente · 09-2026 · Recto FR, verso NL (onglet « Livret NL »)

| Partie | Ce que vous saurez faire à la fin | Durée |
| --- | --- | --- |
| Ouverture · Vendre est un métier | Comprendre pourquoi votre travail au comptoir fait vivre la boutique | 5 min |
| Module 1 · L'expérience client | Soigner ce que le client ressent en entrant, et surtout en sortant | 30 min |
| Module 2 · Question ouverte, question fermée | Faire parler le client, puis conclure la vente | 45 min |
| Module 3 · Vente additionnelle | Proposer à chaque client un deuxième article lié à ce qu'il prend | 45 min |
| Module 4 · Vocabulaire positif | Parler sans « pas », « rien », « problème », « désolé » | 30 min |
| Module 5 · FAQ produits | Expliquer en une phrase pourquoi ce produit est chez nous et pas un autre | 60 min + dégustation |
| Module 6 · Téléphone et avis Google | Transformer un appel en commande ou en visite, et collecter des avis 5★ | 60 min |

Méthode : chaque module se fait en réunion d'équipe. Vous lisez, vous dites les scripts à voix haute à deux, puis vous faites l'exercice. Les modules se suivent dans l'ordre, chacun s'appuie sur le précédent.

## Ouverture · 5 min — Vendre est un métier

Vendre est un métier, avec ses gestes, ses mots et ses techniques, et la passion vient en le pratiquant. Le client ne vient pas seulement acheter du pain : il vient chercher un moment, et c'est au comptoir que ce moment se réussit ou se rate. Une boutique vit de ce qu'elle vend : les frais tombent chaque mois, et ce que chaque client emporte en sortant dépend de vous. Dans votre application, vous suivez votre chiffre d'affaires vendu et vos ventes additionnelles, pas pour vous surveiller, mais pour que vous voyiez vos progrès semaine après semaine. Ce livret vous donne les techniques ; le sourire, l'envie et la fierté du travail bien fait, c'est vous qui les apportez.

La cible de votre boutique, à remplir par votre responsable :

| Indicateur | Cible |
| --- | --- |
| Chiffre d'affaires vendu |  |
| Ventes additionnelles |  |

## Module 1 · 30 min — L'expérience client : ce que le client ressent en entrant, et surtout en sortant

Un client oublie vite ce qu'il a acheté. Il se souvient longtemps de ce qu'il a ressenti. Tous les bons commerçants le savent : d'une visite, on retient surtout le meilleur moment, et le dernier. Le client qui sort de l'Atelier emporte deux choses : ce qu'il a dans son sac, et l'impression qui décidera s'il revient. Votre métier, c'est de soigner ces deux moments.

### En entrant : « Je suis attendu, et j'ai envie de tout goûter. »

Tout se joue avant le premier mot : une vitrine pleine et belle, un comptoir propre, une équipe disponible, l'odeur du four. Puis viennent les trois premiers gestes de notre charte d'accueil.

1. La règle des 3 secondes : chaque client qui entre est regardé et salué dans les 3 secondes, même si vous êtes occupé avec quelqu'un d'autre. Un regard, un sourire et « Bonjour, je suis à vous dans un instant » suffisent. Le client qui sait qu'on l'a vu attend avec plaisir ; celui qu'on n'a pas vu compte les secondes.
2. « Bonjour », dit en premier, par vous. C'est vous qui recevez chez vous, c'est à vous d'ouvrir.
3. « Qu'est-ce qui vous ferait plaisir ? » C'est une invitation, pas un « Je vous écoute » : vous ne prenez pas une commande, vous commencez une conversation.

### Pendant la vente : « On s'occupe de moi, pas de la file. »

Vous regardez le client, vous l'écoutez, vous le conseillez, vous lui faites goûter quand c'est possible. Même quand la file s'allonge, la personne en face de vous a droit à toute votre attention : c'est comme ça qu'on sert vite et bien à la fois.

### En sortant : « J'ai fait le bon choix, et je reviendrai. »

C'est le moment le plus important, et celui qu'on bâcle le plus souvent. La caisse est faite, la personne suivante attend, on dit au revoir en rangeant la monnaie, et tout ce qui a été construit avant s'efface en deux secondes. Une sortie réussie tient en quelques gestes :

- Le produit est emballé avec soin et remis en main, jamais posé ni glissé sur le comptoir.
- Vous donnez un conseil utile : comment le conserver, comment le réchauffer, ce qui l'accompagne bien. Toujours ce qui est écrit sur la fiche produit.
- Vous reprenez ce que le client vous a dit : « Bon anniversaire à votre fille dimanche ! », « Bon appétit pour votre salade ! » Il comprend qu'on l'a vraiment écouté.
- Vous dites le quatrième geste de la charte, en le regardant : « Au revoir, à bientôt. » Avec son prénom si c'est un habitué.

Un client qui sort de l'Atelier avec le sourire revient, en parle autour de lui, et laisse un avis 5★ quand vous le lui demandez (module 6).

### Scripts à dire à voix haute

Vous servez un client quand une cliente entre.

- ✕ Vous continuez sans lever les yeux.
- ✓ Un regard, un sourire : « Bonjour, je suis à vous dans un instant. »

Une cliente a acheté une grande tarte pour un anniversaire.

- ✕ « 24 €. Merci, au revoir. » en se tournant déjà vers le client suivant.
- ✓ « Voilà votre tarte, je vous la donne à plat. Gardez-la au frais jusqu'au dessert. Très bon anniversaire dimanche, et à bientôt ! »

Un habitué prend son café et son croissant.

- ✓ « Voilà, Monsieur Dubois. Belle journée, et à demain ! »

### Les pièges

Le « au revoir » dit dos tourné, ou pendant qu'on ouvre le tiroir-caisse. Une conversation entre collègues pendant qu'un client attend. Un emballage fait à la va-vite. Le téléphone personnel visible au comptoir. Aucun de ces gestes n'est grave pris seul. Mis bout à bout, c'est ce qui fait dire au client « c'était bon, mais… », et on ne revient pas pour un « mais ».

### Exercice

En équipe, 15 minutes. Une personne sort de la boutique, entre comme un client, achète un produit et ressort. Les autres observent. Elle dit ensuite ce qu'elle a ressenti en entrant, puis en sortant, avec ses propres mots. Vous recommencez en changeant les rôles. Pour finir, chacun écrit les trois mots qu'il aimerait entendre de la bouche d'un client qui quitte l'Atelier, et vous les affichez derrière le comptoir.

Ce que ça fait progresser : le retour des clients, et les avis 5★ de la boutique.

## Module 2 · 45 min — Question ouverte, question fermée

On va être clair : au comptoir, la question la plus posée, c'est « Ce sera tout ? ». C'est une question fermée, et elle ferme surtout la caisse. Le client répond oui, il paie, il repart avec un seul article.

Une question ouverte fait parler le client. Elle commence par quoi, comment, pour qui, pour quelle occasion, qu'est-ce que. Le client ne peut pas y répondre par oui ou par non : il vous donne de l'information, et c'est avec elle que vous proposez le bon article.

Une question fermée fait décider le client. Elle se répond par oui, par non, ou par un choix entre deux. Elle sert à conclure, jamais à commencer.

La règle : on ouvre pour découvrir, on ferme pour conclure. Dans cet ordre.

### Le déroulé d'une vente

1. Accueil, avec la charte (module 1) : « Bonjour, qu'est-ce qui vous ferait plaisir ? » C'est déjà une question ouverte.
2. Découverte, une ou deux questions ouvertes : « C'est pour quelle occasion ? », « Vous serez combien ? », « Plutôt sucré ou salé ce matin ? »
3. Proposition : vous partez de ce que le client vient de dire, pas de ce que vous avez envie de vendre. Et vous faites goûter chaque fois que c'est possible.
4. Conclusion, une fermée à choix : « Je vous mets la grande ou la moyenne ? », « Une part ou la demi ? »

Le choix entre deux est la meilleure question fermée qui existe : vous ne demandez pas si le client en veut, vous lui demandez lequel.

### Scripts à dire à voix haute

Un client entre et demande une baguette.

- ✕ « Une baguette. Ce sera tout ? » → « Oui. »
- ✓ « Une baguette, parfait. Elle accompagne quoi ce midi ? » → « Une salade. » → « Alors goûtez notre quiche feta, elle va très bien avec une salade. Je vous mets une part ou la demi ? »

Une cliente regarde les tartes sans rien dire.

- ✕ « Je peux vous aider ? » → « Non merci, je regarde. »
- ✓ « Vous cherchez pour quelle occasion ? » → « Un anniversaire dimanche. » → « Vous serez combien à table ? » → « Huit. » → « Pour huit, la grande tarte est parfaite. Vous la préférez aux fruits ou au chocolat ? »

Un habitué prend son café tous les matins.

- ✓ « Qu'est-ce qui vous ferait plaisir avec votre café aujourd'hui ? » Et s'il hésite : « Croissant ou pain au chocolat ? »

### Les pièges

Trop de questions ouvertes, et la vente devient un interrogatoire : deux au maximum, puis vous proposez. Une fermée trop tôt tue la vente : « Vous voulez autre chose ? » en début de vente, c'est non à tous les coups. Et une question sans proposition derrière ne sert à rien : le client vous a répondu, à vous de lui donner quelque chose.

### Exercice

Par deux, 10 minutes. Une personne joue le client, l'autre tient le comptoir, puis vous inversez. Au comptoir, vous placez au moins une question ouverte, une proposition liée à la réponse et une fermée à choix. Votre responsable vous évalue sur trois situations : une baguette seule, un gâteau d'anniversaire, le café du matin.

Ce que ça fait progresser : vos ventes additionnelles. La semaine suivante, vous les suivez dans votre application.

## Module 3 · 45 min — Vente additionnelle

Le panier moyen du réseau tourne autour de 13 €. Un client qui repart avec un seul article, c'est une vente à moitié faite. Proposer le café avec le croissant ou la boisson avec le sandwich, ce n'est pas forcer la main : c'est donner au client ce qu'il aurait pris s'il y avait pensé.

La règle : à chaque client, une proposition, liée à ce qu'il prend, au bon moment. Le bon moment, c'est quand le premier produit est servi, avant d'annoncer le prix.

### Le déroulé

1. Servez le premier produit, avec le sourire.
2. Reliez votre proposition à ce que le client a choisi : « Avec votre café… »
3. Proposez un seul article, nommé, en choix entre deux : « … un croissant ou un pain au chocolat ? »
4. Encaissez sans insister si le client dit non : « Avec plaisir, bonne journée. »

### Les associations qui marchent

| Le client prend | Vous proposez |
| --- | --- |
| Un café | Une viennoiserie |
| Une viennoiserie | Un café ou un jus |
| Un sandwich | La formule, avec boisson et dessert |
| Une baguette | Une part de quiche ou de tarte |
| Un gâteau | Une boisson ou des bougies |

### Scripts à dire à voix haute

Un client commande un café.

- ✕ « Un café. Ce sera tout ? »
- ✓ « Votre café, voilà. Avec un croissant tout chaud ou un pain au chocolat ? »

Une cliente prend un sandwich à midi.

- ✕ « Autre chose ? »
- ✓ « En formule, vous avez la boisson et le dessert. Plutôt eau ou limonade ? »

Un client achète une baguette.

- ✕ Encaisser sans rien dire.
- ✓ « Votre baguette. La quiche sort du four : je vous mets une part ou la demi pour ce midi ? »

### Les pièges

Réciter tout le comptoir : une seule proposition suffit. Proposer au hasard, sans lien avec l'achat. Insister après un non : le client doit repartir content, avec ou sans deuxième article, parce que c'est comme ça qu'il revient demain.

### Exercice

Par deux, 10 minutes. Une personne joue le client avec un produit, l'autre tient le comptoir, puis vous inversez. À chaque passage, vous faites une proposition liée, en choix entre deux.

Ce que ça fait progresser : vos ventes additionnelles et votre chiffre d'affaires vendu.

## Module 4 · 30 min — Vocabulaire positif, zéro négation

Le cerveau du client entend le mot, pas la négation. « Pas de souci » fait entendre souci. « Ce n'est pas cher » fait entendre cher. « Il n'y a plus de croissants » fait entendre qu'il n'y a rien. Vous pensez rassurer, et vous installez un doute. Chez nous, chaque phrase doit donner envie.

La règle : on dit ce qu'on a, ce qu'on peut faire, et quand. On ne dit jamais ce qu'on n'a pas, ce qu'on ne peut pas faire, ou pourquoi c'est compliqué.

Les mots à sortir du comptoir : pas, rien, plus, jamais, problème, souci, désolé(e), malheureusement, impossible, normalement.

### Le cas de la rupture

C'est là que tout se joue. Un produit manque, vous vous excusez, et le client repart les mains vides. La bonne réponse se fait toujours en trois temps : ce que j'ai maintenant, ce que je vous propose, quand l'autre revient.

- ✓ « Il me reste la tarte aux pommes, elle sort du four. Et si vous tenez à la framboise, je vous en réserve une pour demain. »

### Attention

Positif ne veut pas dire mentir. On ne promet pas un délai qu'on ne tient pas. On ne dit pas qu'un produit convient à une allergie sans avoir vérifié la fiche. Positif veut dire orienté solution.

### Table de remplacement

| On ne dit plus | On dit |
| --- | --- |
| Pas de souci | Avec plaisir / Bien sûr |
| Il n'y a plus de croissants | Il me reste des pains au chocolat tout chauds, et les croissants reviennent demain dès l'ouverture |
| Ce n'est pas cher | C'est \\[prix\\], au levain doux, et ça sort de notre four |
| Je ne sais pas | Je vérifie pour vous tout de suite |
| Ce n'est pas possible aujourd'hui | Je peux vous la préparer pour demain 10 h |
| Désolé pour l'attente | Merci de votre patience, je suis à vous |
| Ce n'est pas trop sucré | C'est léger en sucre, on sent surtout le fruit |
| Il n'y a pas de problème | C'est réglé |
| Vous ne voulez rien d'autre ? | Qu'est-ce qui vous ferait plaisir avec ça ? |
| On ne fait pas de sans gluten | Pour vous, je vous conseille… (vous proposez ce qui convient, après vérification de la fiche allergènes) |

### Exercice

Votre responsable lit dix phrases négatives, une par une. Vous reformulez chacune en moins de 5 secondes, avec la table sous les yeux la première fois, puis sans. Ensuite, pendant une semaine, chaque « pas de souci » entendu au comptoir coûte un café à l'équipe. Le premier jour, tout le monde rit. Le troisième jour, plus personne ne le dit.

Ce que ça fait progresser : tout le reste. Chaque script des modules 5 et 6 se dit sans aucun mot de la liste.

## Module 5 · 60 min + dégustation — FAQ produits

Le client compare, avec la boulangerie d'à côté et avec le supermarché. S'il ne sait pas ce qui rend notre produit différent, il compare le prix, et sur le prix, on perd. Vous devez pouvoir expliquer en une phrase pourquoi ce produit est chez nous et pas un autre.

### Ce que vous pouvez dire, parce que c'est vrai

- Tout est préparé cru dans notre atelier, puis cuit ici, en boutique, tout au long de la journée.
- Nos produits de boulangerie sont au levain lactique doux, sans améliorant ni conservateur.
- La baguette et le pain tradition sont faits avec de la farine Label Rouge.
- Nos produits arrivent crus et frais en boutique : une fois achetés, vous pouvez les congeler chez vous.

Au-delà de ces quatre phrases, vous vérifiez avec votre responsable avant de dire quoi que ce soit à un client.

### La phrase produit

Elle dit ce que c'est, ce qui le rend différent, et ce que le client y gagne. Elle dure moins de 10 secondes, sans aucun mot du module 4. Elle sert au comptoir, au téléphone, et chaque fois qu'un client dit « c'est plus cher qu'à côté ».

Exemple : « Notre baguette, c'est de la farine Label Rouge et un levain doux, et elle est sortie du four il y a une heure. »

### Les questions des clients

| Le client demande | Vous répondez |
| --- | --- |
| « C'est fait ici ? » | « C'est préparé dans notre atelier et cuit ici, ce matin. » Si vous le savez, vous donnez l'heure de la prochaine fournée. |
| « Qu'est-ce qu'il y a dedans ? » | Les deux ou trois ingrédients qui font le goût, puis la phrase produit. |
| « Du gluten, des fruits à coque ? » | « Je vérifie la fiche allergènes pour vous. » Toujours sur la fiche, jamais de mémoire. |
| « Ça se garde combien de temps ? » | La durée de la fiche produit, puis : « Et si vous voulez en garder, vous pouvez le congeler chez vous. Il arrive frais chez nous et il est cuit ici. » |
| « C'est plus cher qu'à côté. » | La phrase produit. Vous ne parlez jamais de l'autre boutique. |

### Dégustation

Avec l'équipe, vous goûtez les produits de la semaine, un par un. Après chaque bouchée, vous dites ce que vous sentez, avec des mots qui donnent envie : croustillant, fondant, on sent le beurre, on sent le fruit. Vous notez les meilleures formules dans la fiche.

### Fiche à compléter en équipe

Avec les fiches produits de la boutique, pendant la dégustation.

| Produit | Ce qui le rend différent | Votre phrase |
| --- | --- | --- |
| Baguette | Farine Label Rouge, levain doux |  |
| Pain tradition | Farine Label Rouge, levain doux |  |
| Croissant |  |  |
| Quiche feta |  |  |
| Grande tarte |  |  |
| Café |  |  |

### Exercice

Votre responsable annonce un produit, et vous dites votre phrase en moins de 10 secondes. Puis il joue le client : « C'est plus cher qu'à côté. » Vous répondez sans parler de l'autre boutique, et vous terminez par une fermée à choix. Cinq produits, cinq phrases.

Ce que ça fait progresser : votre chiffre d'affaires vendu.

## Module 6 · 60 min — Téléphone et avis Google

Un client qui appelle a déjà envie d'acheter. S'il raccroche avec une simple information, il peut très bien aller ailleurs. S'il raccroche avec une commande sur le webshop ou un moment de passage prévu, il vient chez nous.

Au téléphone, la règle du module 2 reste la même : on ouvre pour découvrir, on ferme pour conclure. Le client ne voit ni la vitrine ni votre sourire. Tout passe par la voix, donc le vocabulaire du module 4 compte double.

### Le déroulé d'un appel

1. Décrocher avant la troisième sonnerie : « L'Atelier By \\[boutique\\], bonjour, \\[prénom\\] à l'appareil. Qu'est-ce qui vous ferait plaisir ? »
2. Découvrir : « Pour quelle occasion ? », « Pour combien de personnes ? », « Pour quel jour ? »
3. Proposer un ou deux produits liés à la réponse, avec la phrase produit du module 5.
4. Conclure : « Je vous envoie le lien du webshop pour la commander, ou je note la commande et vous passez la chercher ? »
5. Récapituler : « C'est noté : une grande tarte pour dimanche 10 h, au nom de Martin. »

### Scripts à dire à voix haute

Un client demande si vous faites des tartes.

- ✕ « Oui, on en a. » → « D'accord, merci. » Rien n'est commandé.
- ✓ « Oui, aux fruits et au chocolat. C'est pour quelle occasion ? » → « Un repas dimanche. » → « Vous serez combien ? » → « Six. » → « Pour six, la grande tarte est parfaite. Je vous envoie le lien du webshop pour la commander, ou je note la commande et vous passez dimanche matin ? »

À 17 h, un client demande 30 croissants pour le lendemain.

- ✕ « Je ne sais pas, normalement il faut prévenir avant. »
- ✓ « Je vérifie pour vous tout de suite. » (Vous vérifiez avec votre responsable.) → « Je peux vous les préparer pour demain 10 h. Vous validez sur le webshop avec le lien, ou je note la commande à votre nom ? »

Un client demande les horaires.

- ✕ « On est fermés le lundi. »
- ✓ « On vous accueille du \\[jour\\] au \\[jour\\], dès \\[heure\\]. Vous passez plutôt le matin ou l'après-midi ? »

### Les pièges

Donner un prix ou un horaire et laisser le client raccrocher. Promettre sans avoir vérifié. Oublier le récapitulatif.

### Collecter des avis 5★

Chaque avis 5★ amène des clients qui ne nous connaissent pas encore. L'objectif est d'en collecter le plus possible, dans chaque boutique. Le bon moment pour demander, c'est quand le client vous fait un compliment, ou quand un habitué repart content.

Un client vous dit : « Elle était délicieuse, votre tarte de dimanche. »

- ✓ « Merci, ça fait plaisir à toute l'équipe. Vous nous le dites sur Google ? Le QR code est juste là, ça prend trente secondes. »

Un habitué, au moment de payer.

- ✓ « Vous venez souvent, et ça nous fait vraiment plaisir. Un petit mot sur Google nous aiderait beaucoup : je vous montre le QR code ? »

Les pièges : demander à un client pressé ou mécontent. Demander à tout le monde de façon mécanique. Offrir quelque chose en échange d'un avis : Google l'interdit, et l'avis peut être supprimé.

### Un avis négatif ou un client mécontent

Vous ne répondez pas vous-même aux avis Google : c'est le rôle de votre responsable. Si vous voyez un avis négatif, ou si un client vous fait une remarque de mécontentement, vous prévenez votre responsable le jour même.

### Pour les responsables de boutique : répondre à un avis négatif

Sous 48 h, jamais à chaud, en quatre temps : remercier en utilisant le prénom, reformuler sans se justifier, dire ce que la boutique met en place, inviter à revenir. Sans aucun mot du module 4.

Avis 2★ : « Attente très longue samedi, et plus de pain aux céréales. »

- ✕ « Désolés, le samedi il y a beaucoup de monde et malheureusement on ne peut pas tout prévoir. »
- ✓ « Merci Claire pour votre retour. Samedi matin, l'attente a été longue et le pain aux céréales était parti. \\[Ce que la boutique met en place.\\] Votre pain aux céréales se commande la veille sur notre webshop : il vous attendra au comptoir. À très vite. »

### Exercice

Par deux, dos à dos, 15 minutes. Vous jouez les trois appels ci-dessus : chacun se termine par une commande ou un moment de passage prévu, avec le récapitulatif. Ensuite, face à face, vous jouez deux demandes d'avis au comptoir. Les responsables rédigent en plus la réponse à un avis réel de la boutique, en quatre temps.

Ce que ça fait progresser : votre chiffre d'affaires vendu, et la note Google de la boutique.
`,_n=`# Verkoopopleiding — L'Atelier By

## L'Atelier By \\_\\_\\_\\_\\_\\_\\_\\_ · Uw opleidingsboekje

Verkoopopleiding · 09-2026

| Deel | Wat u op het einde kunt | Duur |
| --- | --- | --- |
| Opening · Verkopen is een vak | Begrijpen waarom uw werk aan de toog de winkel doet leven | 5 min |
| Module 1 · De klantervaring | Verzorgen wat de klant voelt als hij binnenkomt, en vooral als hij buitengaat | 30 min |
| Module 2 · Open vraag, gesloten vraag | De klant laten praten, en dan de verkoop afsluiten | 45 min |
| Module 3 · Bijverkoop | Elke klant een tweede artikel voorstellen dat past bij wat hij neemt | 45 min |
| Module 4 · Positieve woordenschat | Spreken zonder « niet », « niets », « probleem », « sorry » | 30 min |
| Module 5 · FAQ producten | In één zin uitleggen waarom dit product bij ons ligt en geen ander | 60 min + proeverij |
| Module 6 · Telefoon en Google-reviews | Een telefoontje omzetten in een bestelling of een bezoek, en 5★-reviews verzamelen | 60 min |

Methode: elke module volgt u tijdens een teamvergadering. U leest, u zegt de scripts luidop met twee, en daarna doet u de oefening. De modules volgen elkaar op in deze volgorde, elke module bouwt voort op de vorige.

## Opening · 5 min — Verkopen is een vak

Verkopen is een vak, met zijn gebaren, zijn woorden en zijn technieken, en de passie komt al doende. De klant komt niet alleen brood kopen: hij komt een moment halen, en aan de toog wordt beslist of dat moment slaagt of mislukt. Een winkel leeft van wat hij verkoopt: de kosten vallen elke maand, en wat elke klant meeneemt als hij buitengaat, hangt af van u. In uw app volgt u uw verkochte omzet en uw bijverkopen, niet om u te controleren, maar zodat u uw vooruitgang ziet, week na week. Dit boekje geeft u de technieken; de glimlach, de goesting en de fierheid over goed werk, die brengt u zelf mee.

De doelstelling van uw winkel, in te vullen door uw verantwoordelijke:

| Indicator | Doelstelling |
| --- | --- |
| Verkochte omzet |  |
| Bijverkopen |  |

## Module 1 · 30 min — De klantervaring: wat de klant voelt als hij binnenkomt, en vooral als hij buitengaat

Een klant vergeet snel wat hij gekocht heeft. Wat hij gevoeld heeft, onthoudt hij lang. Alle goede handelaars weten het: van een bezoek onthoudt men vooral het beste moment, en het laatste. De klant die het Atelier verlaat, neemt twee dingen mee: wat in zijn zak zit, en de indruk die beslist of hij terugkomt. Uw vak is het om die twee momenten te verzorgen.

### Bij het binnenkomen: « Ik word verwacht, en ik heb zin om alles te proeven. »

Alles speelt zich af nog voor het eerste woord: een volle, mooie vitrine, een propere toog, een team dat beschikbaar is, de geur van de oven. Dan komen de eerste drie gebaren van ons onthaalcharter.

1. De regel van 3 seconden: elke klant die binnenkomt, wordt binnen 3 seconden aangekeken en begroet, ook als u met iemand anders bezig bent. Een blik, een glimlach en « Goeiedag, ik help u zo meteen » volstaan. Een klant die weet dat hij gezien is, wacht met plezier; een klant die niet gezien is, telt de seconden.
2. « Goeiedag », als eerste gezegd, door u. U ontvangt bij u thuis, dus u opent het gesprek.
3. « Waarmee kan ik u een plezier doen? » Dat is een uitnodiging, geen « Zeg het maar »: u neemt geen bestelling op, u begint een gesprek.

### Tijdens de verkoop: « Hier zorgt men voor mij, niet voor de rij. »

U kijkt de klant aan, u luistert, u geeft advies, u laat proeven telkens het kan. Ook als de rij langer wordt, heeft de persoon tegenover u recht op al uw aandacht: zo bedient u snel en goed tegelijk.

### Bij het buitengaan: « Ik heb de juiste keuze gemaakt, en ik kom terug. »

Dat is het belangrijkste moment, en het moment dat het vaakst afgeraffeld wordt. De kassa is gedaan, de volgende wacht, we zeggen tot ziens terwijl we het wisselgeld opbergen, en alles wat eerder opgebouwd is, is in twee seconden weg. Een geslaagd vertrek zit in een paar gebaren:

- Het product wordt met zorg ingepakt en in de hand gegeven, nooit neergelegd of over de toog geschoven.
- U geeft een nuttige tip: hoe het te bewaren, hoe het op te warmen, waar het goed bij past. Altijd wat op de productfiche staat.
- U herneemt wat de klant u gezegd heeft: « Een fijne verjaardag voor uw dochter zondag! », « Smakelijk bij uw salade! » Zo weet hij dat er echt naar hem geluisterd is.
- U zegt het vierde gebaar van het charter terwijl u hem aankijkt: « Tot ziens, tot binnenkort. » Met zijn naam als het een vaste klant is.

Een klant die het Atelier met een glimlach verlaat, komt terug, vertelt erover, en laat een 5★-review achter als u het hem vraagt (module 6).

### Scripts om luidop te zeggen

U bedient een klant wanneer een klant binnenkomt.

- ✕ U gaat verder zonder op te kijken.
- ✓ Een blik, een glimlach: « Goeiedag, ik help u zo meteen. »

Een klant kocht een grote taart voor een verjaardag.

- ✕ « 24 €. Bedankt, dag. » terwijl u zich al naar de volgende klant draait.
- ✓ « Alstublieft, uw taart, ik geef ze u plat mee. Bewaar ze koel tot het dessert. Een heel fijne verjaardag zondag, en tot binnenkort! »

Een vaste klant neemt zijn koffie en zijn croissant.

- ✓ « Alstublieft, meneer Dubois. Een fijne dag, en tot morgen! »

### De valkuilen

Het « tot ziens » dat met de rug naar de klant gezegd wordt, of terwijl de kassa opengaat. Een gesprek tussen collega's terwijl een klant wacht. Een haastig ingepakt product. De persoonlijke gsm zichtbaar aan de toog. Geen van die gebaren is op zich erg. Samen zorgen ze ervoor dat de klant zegt « het was lekker, maar… », en voor een « maar » komt niemand terug.

### Oefening

In team, 15 minuten. Iemand gaat naar buiten, komt binnen als klant, koopt een product en gaat weer buiten. De anderen observeren. Daarna vertelt die persoon wat hij voelde bij het binnenkomen, en daarna bij het buitengaan, in zijn eigen woorden. U herbegint met andere rollen. Tot slot schrijft iedereen de drie woorden op die hij graag zou horen uit de mond van een klant die het Atelier verlaat, en u hangt ze op achter de toog.

Wat het doet groeien: de terugkeer van de klanten, en de 5★-reviews van de winkel.

## Module 2 · 45 min — Open vraag, gesloten vraag

Laten we eerlijk zijn: de vraag die aan de toog het meest gesteld wordt, is « Was dat alles? ». Dat is een gesloten vraag, en vooral sluit ze de kassa. De klant zegt ja, betaalt en vertrekt met één artikel.

Een open vraag laat de klant praten. Ze begint met wat, hoe, voor wie, voor welke gelegenheid. De klant kan niet met ja of nee antwoorden: hij geeft u informatie, en daarmee stelt u het juiste artikel voor.

Een gesloten vraag laat de klant beslissen. Het antwoord is ja, nee, of een keuze tussen twee. Ze dient om af te sluiten, nooit om te beginnen.

De regel: open om te ontdekken, gesloten om af te sluiten. In die volgorde.

### Het verloop van een verkoop

1. Onthaal, met het charter (module 1): « Goeiedag, waarmee kan ik u een plezier doen? » Dat is al een open vraag.
2. Ontdekking, één of twee open vragen: « Voor welke gelegenheid is het? », « Met hoeveel bent u? », « Eerder zoet of hartig vanochtend? »
3. Voorstel: u vertrekt van wat de klant net zei, niet van wat u graag wilt verkopen. En u laat proeven telkens het kan.
4. Afsluiting, een gesloten keuzevraag: « Mag het de grote of de middelgrote zijn? », « Een stuk of de helft? »

De keuze tussen twee is de beste gesloten vraag die er bestaat: u vraagt niet óf de klant iets wil, u vraagt wélk.

### Scripts om luidop te zeggen

Een klant komt binnen en vraagt een baguette.

- ✕ « Een baguette. Was dat alles? » → « Ja. »
- ✓ « Een baguette, perfect. Waarbij eet u ze vanmiddag? » → « Een salade. » → « Proef dan onze quiche met feta, die past heel goed bij een salade. Mag het een stuk of de helft zijn? »

Een klant bekijkt de taarten zonder iets te zeggen.

- ✕ « Kan ik u helpen? » → « Nee dank u, ik kijk even. »
- ✓ « Voor welke gelegenheid zoekt u iets? » → « Een verjaardag zondag. » → « Met hoeveel bent u aan tafel? » → « Acht. » → « Voor acht is de grote taart perfect. Hebt u ze liever met fruit of met chocolade? »

Een vaste klant neemt elke ochtend zijn koffie.

- ✓ « Waarmee kan ik u vandaag een plezier doen bij uw koffie? » En als hij twijfelt: « Croissant of chocoladebroodje? »

### De valkuilen

Te veel open vragen, en de verkoop wordt een verhoor: maximum twee, dan stelt u iets voor. Een gesloten vraag te vroeg doodt de verkoop: « Wilt u nog iets anders? » in het begin, dat is elke keer nee. En een vraag zonder voorstel erachter heeft geen zin: de klant heeft geantwoord, nu moet u hem iets geven.

### Oefening

Met twee, 10 minuten. De ene speelt de klant, de andere staat aan de toog, daarna wisselt u. Aan de toog stelt u minstens één open vraag, doet u een voorstel dat past bij het antwoord, en stelt u een gesloten keuzevraag. Uw verantwoordelijke beoordeelt u op drie situaties: alleen een baguette, een verjaardagstaart, de ochtendkoffie.

Wat het doet groeien: uw bijverkopen. De week daarna volgt u ze in uw app.

## Module 3 · 45 min — Bijverkoop

Het gemiddelde mandje in het netwerk ligt rond 13 €. Een klant die met één artikel vertrekt, is een half gedane verkoop. Koffie voorstellen bij de croissant of een drank bij de sandwich is niet opdringerig: u geeft de klant wat hij genomen had als hij eraan gedacht had.

De regel: bij elke klant één voorstel, passend bij wat hij neemt, op het juiste moment. Het juiste moment is als het eerste product geserveerd is, voor u de prijs zegt.

### Het verloop

1. Serveer het eerste product, met een glimlach.
2. Verbind uw voorstel met wat de klant koos: « Bij uw koffie… »
3. Stel één artikel voor, bij naam, als keuze tussen twee: « … een croissant of een chocoladebroodje? »
4. Reken af zonder aan te dringen als de klant nee zegt: « Met plezier, nog een fijne dag. »

### Combinaties die werken

| De klant neemt | U stelt voor |
| --- | --- |
| Een koffie | Een koek |
| Een koek | Een koffie of een sap |
| Een sandwich | De formule, met drank en dessert |
| Een baguette | Een stuk quiche of taart |
| Een taart | Een drank of kaarsjes |

### Scripts om luidop te zeggen

Een klant bestelt een koffie.

- ✕ « Een koffie. Was dat alles? »
- ✓ « Uw koffie, alstublieft. Met een warme croissant of een chocoladebroodje? »

Een klant neemt om 12 u een sandwich.

- ✕ « Nog iets? »
- ✓ « Als formule hebt u de drank en het dessert erbij. Liever water of limonade? »

Een klant koopt een baguette.

- ✕ Afrekenen zonder iets te zeggen.
- ✓ « Uw baguette. De quiche komt net uit de oven: mag het een stuk of de helft zijn voor vanmiddag? »

### De valkuilen

De hele toog opsommen: één voorstel volstaat. Lukraak iets voorstellen, zonder verband met de aankoop. Aandringen na een nee: de klant moet tevreden vertrekken, met of zonder tweede artikel, want zo komt hij morgen terug.

### Oefening

Met twee, 10 minuten. De ene speelt de klant met een product, de andere staat aan de toog, daarna wisselt u. Bij elke klant doet u een passend voorstel, als keuze tussen twee.

Wat het doet groeien: uw bijverkopen en uw verkochte omzet.

## Module 4 · 30 min — Positieve woordenschat, nul ontkenning

Het brein van de klant hoort het woord, niet de ontkenning. « Geen probleem » laat probleem horen. « Het is niet duur » laat duur horen. « Er zijn geen croissants meer » laat horen dat er niets is. U denkt dat u geruststelt, maar u zaait twijfel. Bij ons moet elke zin goesting geven.

De regel: u zegt wat u hebt, wat u kunt doen, en wanneer. Nooit wat u niet hebt, wat u niet kunt doen, of waarom het moeilijk is.

De woorden die weg moeten van de toog: niet, niets, geen … meer, nooit, probleem, zorgen, sorry, helaas, onmogelijk, normaal gezien.

### Als een product op is

Hier speelt alles zich af. Een product ontbreekt, u verontschuldigt zich, en de klant vertrekt met lege handen. Het juiste antwoord heeft altijd drie stappen: wat ik nu heb, wat ik u voorstel, wanneer het andere terug is.

- ✓ « Ik heb nog de appeltaart, die komt net uit de oven. En als u echt framboos wilt, reserveer ik er een voor u voor morgen. »

### Let op

Positief betekent niet liegen. U belooft geen termijn die u niet haalt. U zegt niet dat een product geschikt is bij een allergie zonder de fiche te controleren. Positief betekent gericht op een oplossing.

### Vervangingstabel

| Zegt u niet meer | Zegt u wel |
| --- | --- |
| Geen probleem | Met plezier / Natuurlijk |
| Er zijn geen croissants meer | Ik heb nog warme chocoladebroodjes, en de croissants zijn morgen terug vanaf de opening |
| Het is niet duur | Het is \\[prijs\\], op zacht desem, en het komt uit onze oven |
| Ik weet het niet | Ik kijk het meteen voor u na |
| Dat gaat vandaag niet | Ik kan ze voor u klaarmaken voor morgen 10 u |
| Sorry voor het wachten | Bedankt voor uw geduld, ik help u nu |
| Het is niet te zoet | Het is licht gezoet, u proeft vooral het fruit |
| Er is geen probleem | Dat is geregeld |
| Wilt u niets anders? | Waarmee kan ik u daarbij een plezier doen? |
| We hebben niets glutenvrij | Voor u raad ik … aan (u stelt voor wat past, na controle van de allergenenfiche) |

### Oefening

Uw verantwoordelijke leest tien negatieve zinnen voor, één voor één. U herformuleert elke zin in minder dan 5 seconden, de eerste keer met de tabel voor u, daarna zonder. Daarna kost elke « geen probleem » aan de toog een week lang een koffie voor het team. De eerste dag wordt er gelachen. De derde dag zegt niemand het nog.

Wat het doet groeien: al de rest. Elk script van module 5 en 6 zegt u zonder één woord uit de lijst.

## Module 5 · 60 min + proeverij — FAQ producten

De klant vergelijkt, met de bakker om de hoek en met de supermarkt. Weet hij niet wat ons product anders maakt, dan vergelijkt hij de prijs, en op de prijs verliezen we. U moet in één zin kunnen uitleggen waarom dit product bij ons ligt en geen ander.

### Wat u mag zeggen, omdat het waar is

- Alles wordt rauw bereid in ons atelier en daarna hier, in de winkel, gebakken, de hele dag door.
- Onze bakkerijproducten zijn gemaakt op zacht melkzuurdesem, zonder broodverbeteraars of bewaarmiddelen.
- De baguette en het traditiebrood zijn gemaakt met Label Rouge-bloem.
- Onze producten komen rauw en vers in de winkel aan: eens gekocht, kunt u ze thuis invriezen.

Voor alles wat verder gaat dan deze vier zinnen, kijkt u het na bij uw verantwoordelijke voor u iets aan een klant zegt.

### De productzin

Ze zegt wat het is, wat het anders maakt, en wat de klant eraan heeft. Ze duurt minder dan 10 seconden, zonder één woord uit module 4. U gebruikt ze aan de toog, aan de telefoon, en telkens een klant zegt « het is duurder dan hiernaast ».

Voorbeeld: « Onze baguette is gemaakt met Label Rouge-bloem en zacht desem, en ze kwam een uur geleden uit de oven. »

### De vragen van klanten

| De klant vraagt | U antwoordt |
| --- | --- |
| « Is het hier gemaakt? » | « Het wordt bereid in ons atelier en hier gebakken, vanochtend. » Als u het weet, zegt u wanneer de volgende ovenlading klaar is. |
| « Wat zit erin? » | De twee of drie ingrediënten die de smaak maken, dan de productzin. |
| « Gluten, noten? » | « Ik kijk de allergenenfiche voor u na. » Altijd op de fiche, nooit uit het hoofd. |
| « Hoelang blijft het goed? » | De termijn op de productfiche, en dan: « En als u er wilt bewaren, kunt u het thuis invriezen. Het komt vers bij ons aan en wordt hier gebakken. » |
| « Het is duurder dan hiernaast. » | De productzin. U spreekt nooit over de andere winkel. |

### Proeverij

Met het team proeft u de producten van de week, één voor één. Na elke hap zegt u wat u proeft, met woorden die goesting geven: krokant, smeuïg, u proeft de boter, u proeft het fruit. U noteert de beste formuleringen in de fiche.

### Fiche om samen in te vullen

Met de productfiches van de winkel, tijdens de proeverij.

| Product | Wat het anders maakt | Uw zin |
| --- | --- | --- |
| Baguette | Label Rouge-bloem, zacht desem |  |
| Traditiebrood | Label Rouge-bloem, zacht desem |  |
| Croissant |  |  |
| Quiche met feta |  |  |
| Grote taart |  |  |
| Koffie |  |  |

### Oefening

Uw verantwoordelijke noemt een product, en u zegt uw zin in minder dan 10 seconden. Dan speelt hij de klant: « Het is duurder dan hiernaast. » U antwoordt zonder over de andere winkel te spreken, en u sluit af met een gesloten keuzevraag. Vijf producten, vijf zinnen.

Wat het doet groeien: uw verkochte omzet.

## Module 6 · 60 min — Telefoon en Google-reviews

Een klant die belt, heeft al zin om te kopen. Hangt hij op met alleen een inlichting, dan kan hij net zo goed ergens anders gaan. Hangt hij op met een bestelling op de webshop of een afgesproken moment, dan komt hij bij ons.

Aan de telefoon blijft de regel van module 2 dezelfde: open om te ontdekken, gesloten om af te sluiten. De klant ziet de vitrine en uw glimlach niet. Alles gaat via uw stem, dus de woordenschat van module 4 telt dubbel.

### Het verloop van een gesprek

1. Opnemen voor de derde beltoon: « L'Atelier By \\[winkel\\], goeiedag, met \\[voornaam\\]. Waarmee kan ik u een plezier doen? »
2. Ontdekken: « Voor welke gelegenheid? », « Voor hoeveel personen? », « Voor welke dag? »
3. Voorstellen: één of twee producten die passen bij het antwoord, met de productzin uit module 5.
4. Afsluiten: « Zal ik u de link naar de webshop sturen om te bestellen, of noteer ik de bestelling en komt u ze halen? »
5. Samenvatten: « Genoteerd: een grote taart voor zondag 10 u, op naam van Martin. »

### Scripts om luidop te zeggen

Een klant vraagt of u taarten maakt.

- ✕ « Ja, die hebben we. » → « Oké, bedankt. » Niets besteld.
- ✓ « Ja, met fruit en met chocolade. Voor welke gelegenheid is het? » → « Een etentje zondag. » → « Met hoeveel bent u? » → « Zes. » → « Voor zes is de grote taart perfect. Zal ik u de link naar de webshop sturen om ze te bestellen, of noteer ik de bestelling en komt u zondagochtend langs? »

Om 17 u vraagt een klant 30 croissants voor de volgende dag.

- ✕ « Ik weet het niet, normaal gezien moet u dat vooraf laten weten. »
- ✓ « Ik kijk het meteen voor u na. » (U kijkt het na bij uw verantwoordelijke.) → « Ik kan ze voor u klaarmaken voor morgen 10 u. Bevestigt u op de webshop via de link, of noteer ik de bestelling op uw naam? »

Een klant vraagt de openingsuren.

- ✕ « Op maandag zijn we gesloten. »
- ✓ « We verwelkomen u van \\[dag\\] tot \\[dag\\], vanaf \\[uur\\]. Komt u eerder in de voormiddag of in de namiddag? »

### De valkuilen

Een prijs of een uur geven en de klant laten ophangen. Iets beloven zonder het na te kijken. De samenvatting vergeten.

### 5★-reviews verzamelen

Elke 5★-review brengt klanten die ons nog niet kennen. Het doel is er zoveel mogelijk te verzamelen, in elke winkel. Het juiste moment om te vragen is wanneer de klant u een compliment geeft, of wanneer een vaste klant tevreden vertrekt.

Een klant zegt: « Uw taart van zondag was heerlijk. »

- ✓ « Dank u, dat doet het hele team plezier. Zegt u het ons op Google? De QR-code ligt hier, het duurt dertig seconden. »

Een vaste klant, bij het afrekenen.

- ✓ « U komt vaak, en dat doet ons echt plezier. Een woordje op Google zou ons veel helpen: zal ik u de QR-code tonen? »

De valkuilen: het vragen aan een klant die haast heeft of ontevreden is. Het mechanisch aan iedereen vragen. Iets aanbieden in ruil voor een review: Google verbiedt dat, en de review kan verwijderd worden.

### Een negatieve review of een ontevreden klant

U antwoordt zelf niet op Google-reviews: dat is de rol van uw verantwoordelijke. Ziet u een negatieve review, of maakt een klant een ontevreden opmerking, dan verwittigt u uw verantwoordelijke dezelfde dag.

### Voor de winkelverantwoordelijken: een negatieve review beantwoorden

Binnen 48 u, nooit in een opwelling, in vier stappen: bedanken met de voornaam, herformuleren zonder zich te verantwoorden, zeggen wat de winkel onderneemt, uitnodigen om terug te komen. Zonder één woord uit module 4.

Review 2★: « Heel lang wachten zaterdag, en geen meergranenbrood meer. »

- ✕ « Sorry, op zaterdag is het heel druk en helaas kunnen we niet alles voorzien. »
- ✓ « Bedankt Claire voor uw reactie. Zaterdagochtend was het lang wachten en was het meergranenbrood uitverkocht. \\[Wat de winkel onderneemt.\\] Uw meergranenbrood kunt u de dag ervoor op onze webshop bestellen: het ligt dan voor u klaar aan de toog. Tot snel. »

### Oefening

Met twee, rug aan rug, 15 minuten. U speelt de drie gesprekken hierboven: elk eindigt met een bestelling of een afgesproken bezoek, met de samenvatting. Daarna speelt u, van aangezicht tot aangezicht, twee reviewvragen aan de toog. De verantwoordelijken schrijven daarbovenop het antwoord op een echte review van de winkel, in vier stappen.

Wat het doet groeien: uw verkochte omzet, en de Google-score van de winkel.
`,Q=e=>e.replace(/\\([_[\]*])/g,`$1`).replace(/\*\*/g,``).trim(),vn=e=>e.split(`|`).slice(1,-1).map(Q),yn=/^(.*?) · (.*?) — (.*)$/,bn=/^(Ce que ça fait progresser|Wat het doet groeien)/;function xn(e){let t=[],n={rows:[],method:``},r=null,i=null;for(let a of e.split(`
`)){let e=a.trim();if(e.startsWith(`|`)||(i=null),e.startsWith(`# `))continue;if(e.startsWith(`## `)){let n=Q(e.slice(3)).match(yn);n?(r={label:n[1],dur:n[2],title:n[3],blocks:[]},t.push(r)):r=null;continue}if(!e)continue;if(!r){if(e.startsWith(`|`)){let t=vn(e);!/^-+$/.test(t[0])&&t.length===3&&n.rows.push(t)}else/^(Méthode|Methode)/.test(e)&&(n.method=Q(e));continue}let o=r.blocks,s=o[o.length-1];if(e.startsWith(`### `)){o.push({type:`h`,text:Q(e.slice(4))});continue}if(e.startsWith(`|`)){let t=vn(e);if(t.every(e=>/^-+$/.test(e.replace(/\s/g,``))))continue;i?i.rows.push(t):(i={type:`table`,head:t,rows:[]},o.push(i));continue}let c;if(c=e.match(/^(\d+)\.\s+(.*)$/)){let e={n:c[1],text:Q(c[2])};s?.type===`ol`?s.items.push(e):o.push({type:`ol`,items:[e]});continue}if(c=e.match(/^-\s+(.*)$/)){let e=c[1],t=e.startsWith(`✕`)?`bad`:e.startsWith(`✓`)?`good`:`plain`,n={kind:t,text:Q(t===`plain`?e:e.slice(1))};s?.type===`ul`?s.items.push(n):o.push({type:`ul`,items:[n]});continue}let l=Q(e);o.push(bn.test(l)?{type:`gain`,text:l}:{type:`p`,text:l})}return{mods:t,intro:n}}var Sn=[xn(gn),xn(_n)],Cn=e=>Sn[e],wn=[`bread`,`cake`,`hot-drink`,`croissant`,`sweet-tart`,`savoury-tart`,`phone-orders`],Tn=e=>e.mods.map((t,n)=>({index:n,label:t.label,dur:t.dur,title:t.title,goal:e.intro.rows[n+1]?.[1]||``,icon:`img/onb/${wn[n%wn.length]}.png`})),En=(e,t)=>e?{rule:S(e.rule,t),points:e.points.map(e=>S(e,t)),scripts:e.scripts.map(e=>({ctx:S(e.ctx,t),bad:e.bad?S(e.bad,t):null,good:S(e.good,t)})),gain:e.gain?S(e.gain,t):null}:null,Dn=/^(Exercice|Oefening)$/,On=e=>!!e&&e.type===`h`&&Dn.test(e.text),kn=e=>e.filter((e,t,n)=>{if(On(e))return!1;for(let e=t-1;e>=0;e--)if(n[e].type===`h`)return!On(n[e]);return!0}),An=e=>({cols:`repeat(${e},minmax(140px,1fr))`,minW:`${e*160}px`}),jn=e=>kn(e).map(e=>e.type===`table`?{...e,...An(e.head.length)}:e);function Mn(e,t,n,r=_t){let i=e.mods[t];if(t<0||!i)return null;let a=t=>e.mods[t]?{index:t,label:e.mods[t].label}:null;return{...Tn(e)[t],short:En(r[t],n),blocks:jn(i.blocks),prev:t>0?a(t-1):null,next:a(t+1)}}var $={page:`_page_t5357_3`,listHead:`_listHead_t5357_11`,intro:`_intro_t5357_17`,list:`_list_t5357_11`,card:`_card_t5357_31`,thumb:`_thumb_t5357_56`,thumbImg:`_thumbImg_t5357_66`,cardText:`_cardText_t5357_73`,cardEyebrow:`_cardEyebrow_t5357_80`,cardTitle:`_cardTitle_t5357_88`,cardGoal:`_cardGoal_t5357_95`,arrow:`_arrow_t5357_101`,method:`_method_t5357_113`,module:`_module_t5357_123`,backBtn:`_backBtn_t5357_131`,pagerBtn:`_pagerBtn_t5357_132`,modHead:`_modHead_t5357_149`,modThumb:`_modThumb_t5357_156`,modThumbImg:`_modThumbImg_t5357_166`,modTitles:`_modTitles_t5357_173`,modEyebrow:`_modEyebrow_t5357_180`,modTitle:`_modTitle_t5357_173`,link:`_link_t5357_204`,linkStart:`_linkStart_t5357_218`,pager:`_pager_t5357_132`,spacer:`_spacer_t5357_233`,short:`_short_t5357_239`,readMin:`_readMin_t5357_245`,rule:`_rule_t5357_256`,ruleEyebrow:`_ruleEyebrow_t5357_266`,ruleText:`_ruleText_t5357_274`,points:`_points_t5357_282`,point:`_point_t5357_282`,dot:`_dot_t5357_303`,script:`_script_t5357_312`,scriptEyebrow:`_scriptEyebrow_t5357_321`,gain:`_gain_t5357_329`,say:`_say_t5357_338`,bad:`_bad_t5357_349`,good:`_good_t5357_354`,badTag:`_badTag_t5357_359`,goodTag:`_goodTag_t5357_360`,badText:`_badText_t5357_379`,goodText:`_goodText_t5357_384`,fullBar:`_fullBar_t5357_390`,fullTag:`_fullTag_t5357_398`,fullCard:`_fullCard_t5357_406`,h2:`_h2_t5357_415`,p:`_p_t5357_3`,blockGain:`_blockGain_t5357_433`,ol:`_ol_t5357_443`,olItem:`_olItem_t5357_452`,num:`_num_t5357_461`,ul:`_ul_t5357_474`,plain:`_plain_t5357_483`,plainDot:`_plainDot_t5357_492`,tableWrap:`_tableWrap_t5357_501`,table:`_table_t5357_501`,row:`_row_t5357_513`,th:`_th_t5357_56`,td:`_td_t5357_527`,tdEmpty:`_tdEmpty_t5357_535`,blank:`_blank_t5357_540`};function Nn(){let{state:e,lang:t}=d(),n=re(t),r=Cn(t),i=(0,o.useMemo)(()=>Mn(r,e.onbMod,t),[r,e.onbMod,t]),a=(0,o.useRef)(null);return Pn(a,i?e.onbMod:-1,!!i&&e.onbFull),(0,g.jsx)(`section`,{ref:a,className:$.page,children:i?(0,g.jsx)(Ln,{mod:i,full:e.onbFull,LO:n}):(0,g.jsx)(Fn,{LO:n})})}function Pn(e,t,n){let r=(0,o.useRef)({onbMod:t,full:n});(0,o.useEffect)(()=>{let i=r.current;if(i.onbMod===t&&i.full===n)return;r.current={onbMod:t,full:n};let a=e.current,o=document.activeElement;!a||o&&o!==document.body&&!a.contains(o)||a.querySelector(t>=0?`h1`:`[data-module="${i.onbMod}"]`)?.focus({preventScroll:!0})},[e,t,n])}function Fn({LO:e}){let{lang:t,actions:n}=d(),r=Cn(t),i=(0,o.useMemo)(()=>Tn(r),[r]);return(0,g.jsxs)(g.Fragment,{children:[(0,g.jsxs)(`div`,{className:$.listHead,children:[(0,g.jsx)(R,{children:e.title}),(0,g.jsx)(`p`,{className:$.intro,children:e.intro})]}),(0,g.jsx)(`div`,{className:$.list,children:i.map(t=>(0,g.jsx)(In,{m:t,LO:e,onOpen:()=>n.openModule(t.index)},t.index))}),(0,g.jsx)(`p`,{className:$.method,children:r.intro.method})]})}function In({m:e,LO:t,onOpen:n}){return(0,g.jsxs)(`button`,{type:`button`,className:$.card,onClick:n,"data-module":e.index,children:[(0,g.jsx)(`span`,{className:$.thumb,children:(0,g.jsx)(`img`,{src:i(e.icon),alt:``,loading:`lazy`,decoding:`async`,className:$.thumbImg})}),(0,g.jsxs)(`span`,{className:$.cardText,children:[(0,g.jsxs)(`span`,{className:$.cardEyebrow,children:[e.label,` · `,t.readMin]}),(0,g.jsx)(`span`,{className:$.cardTitle,children:e.title}),(0,g.jsx)(`span`,{className:$.cardGoal,children:e.goal})]}),(0,g.jsx)(`span`,{className:$.arrow,"aria-hidden":`true`,children:`→`})]})}function Ln({mod:e,full:t,LO:n}){let{actions:r}=d(),{prev:a,next:o}=e;return(0,g.jsxs)(`div`,{className:$.module,children:[(0,g.jsxs)(`button`,{type:`button`,className:$.backBtn,onClick:r.backToModules,children:[(0,g.jsx)(`span`,{"aria-hidden":`true`,children:`←`}),` `,n.back]}),(0,g.jsxs)(`div`,{className:$.modHead,children:[(0,g.jsx)(`div`,{className:$.modThumb,children:(0,g.jsx)(`img`,{src:i(e.icon),alt:``,className:$.modThumbImg})}),(0,g.jsxs)(`div`,{className:$.modTitles,children:[(0,g.jsxs)(`span`,{className:$.modEyebrow,children:[e.label,` · `,e.dur]}),(0,g.jsx)(`h1`,{className:$.modTitle,tabIndex:-1,children:e.title})]})]}),!t&&e.short&&(0,g.jsx)(zn,{sh:e.short,LO:n,onFull:r.toggleFull}),t&&(0,g.jsxs)(g.Fragment,{children:[(0,g.jsxs)(`div`,{className:$.fullBar,children:[(0,g.jsx)(`span`,{className:$.fullTag,children:n.fullTag}),(0,g.jsxs)(`button`,{type:`button`,className:$.link,onClick:r.toggleFull,children:[(0,g.jsx)(`span`,{"aria-hidden":`true`,children:`←`}),` `,n.short]})]}),(0,g.jsx)(`div`,{className:$.fullCard,children:e.blocks.map((e,t)=>(0,g.jsx)(Hn,{b:e,LO:n},t))})]}),(0,g.jsxs)(`div`,{className:$.pager,children:[a&&(0,g.jsxs)(`button`,{type:`button`,className:$.pagerBtn,onClick:()=>r.openModule(a.index),"aria-label":`${a.label} (${n.prev.toLowerCase()})`,children:[(0,g.jsx)(`span`,{"aria-hidden":`true`,children:`←`}),` `,a.label]}),(0,g.jsx)(`span`,{className:$.spacer}),o&&(0,g.jsxs)(`button`,{type:`button`,className:$.pagerBtn,onClick:()=>r.openModule(o.index),"aria-label":`${o.label} (${n.next.toLowerCase()})`,children:[o.label,` `,(0,g.jsx)(`span`,{"aria-hidden":`true`,children:`→`})]})]})]})}function Rn(){return(0,g.jsxs)(`svg`,{width:`16`,height:`16`,viewBox:`0 0 24 24`,fill:`none`,stroke:`currentColor`,strokeWidth:`1.8`,"aria-hidden":`true`,children:[(0,g.jsx)(`circle`,{cx:`12`,cy:`12`,r:`8`}),(0,g.jsx)(`path`,{d:`M12 8v4l3 2`})]})}function zn({sh:e,LO:t,onFull:n}){return(0,g.jsxs)(`div`,{className:$.short,children:[(0,g.jsxs)(`div`,{className:$.readMin,children:[(0,g.jsx)(Rn,{}),t.readMin]}),(0,g.jsxs)(`div`,{className:$.rule,children:[(0,g.jsx)(`span`,{className:$.ruleEyebrow,children:t.rule}),(0,g.jsx)(`p`,{className:$.ruleText,children:e.rule})]}),(0,g.jsx)(`ul`,{className:$.points,children:e.points.map((e,t)=>(0,g.jsxs)(`li`,{className:$.point,children:[(0,g.jsx)(`span`,{className:$.dot,"aria-hidden":`true`}),(0,g.jsx)(`span`,{children:e})]},t))}),e.scripts.map((e,n)=>(0,g.jsxs)(`div`,{className:$.script,children:[(0,g.jsxs)(`span`,{className:$.scriptEyebrow,children:[t.scripts,` · `,e.ctx]}),e.bad!=null&&(0,g.jsx)(Bn,{kind:`bad`,text:e.bad,LO:t}),(0,g.jsx)(Bn,{kind:`good`,text:e.good,LO:t})]},n)),e.gain!=null&&(0,g.jsx)(`p`,{className:$.gain,children:e.gain}),(0,g.jsxs)(`button`,{type:`button`,className:`${$.link} ${$.linkStart}`,onClick:n,children:[t.full,` `,(0,g.jsx)(`span`,{"aria-hidden":`true`,children:`→`})]})]})}function Bn({kind:e,text:t,LO:n,as:r=`div`}){let i=e===`bad`,a=i?n.bad:n.good;return(0,g.jsxs)(r,{className:`${$.say} ${i?$.bad:$.good}`,children:[(0,g.jsxs)(`span`,{className:i?$.badTag:$.goodTag,"aria-hidden":`true`,children:[i?`✕ `:`✓ `,a]}),(0,g.jsx)(`span`,{className:`sr-only`,children:`${a}: `}),(0,g.jsx)(`span`,{className:i?$.badText:$.goodText,children:t})]})}function Vn({it:e,LO:t}){return e.kind===`plain`?(0,g.jsxs)(`li`,{className:$.plain,children:[(0,g.jsx)(`span`,{className:$.plainDot,"aria-hidden":`true`}),(0,g.jsx)(`span`,{children:e.text})]}):(0,g.jsx)(Bn,{kind:e.kind,text:e.text,LO:t,as:`li`})}function Hn({b:e,LO:t}){switch(e.type){case`h`:return(0,g.jsx)(`h2`,{className:$.h2,children:e.text});case`p`:return(0,g.jsx)(`p`,{className:$.p,children:e.text});case`gain`:return(0,g.jsx)(`p`,{className:$.blockGain,children:e.text});case`ol`:return(0,g.jsx)(`ol`,{className:$.ol,children:e.items.map((e,t)=>(0,g.jsxs)(`li`,{className:$.olItem,children:[(0,g.jsx)(`span`,{className:$.num,children:e.n}),(0,g.jsx)(`span`,{children:e.text})]},t))});case`ul`:return(0,g.jsx)(`ul`,{className:$.ul,children:e.items.map((e,n)=>(0,g.jsx)(Vn,{it:e,LO:t},n))});case`table`:return(0,g.jsx)(`div`,{className:$.tableWrap,children:(0,g.jsxs)(`div`,{role:`table`,className:$.table,style:{gridTemplateColumns:e.cols,minWidth:e.minW},children:[(0,g.jsx)(`div`,{role:`row`,className:$.row,children:e.head.map((e,t)=>(0,g.jsx)(`div`,{role:`columnheader`,className:$.th,children:e},t))}),e.rows.map((e,t)=>(0,g.jsx)(`div`,{role:`row`,className:$.row,children:e.map((e,t)=>e?(0,g.jsx)(`div`,{role:`cell`,className:$.td,children:e},t):(0,g.jsx)(`div`,{role:`cell`,className:$.tdEmpty,children:(0,g.jsx)(`div`,{className:$.blank})},t))},t))]})})}}function Un(){let{state:e}=d();if(e.q.trim())return(0,g.jsx)(gt,{});switch(e.view){case`home`:return(0,g.jsx)(Et,{});case`gamme`:return(0,g.jsx)(Nt,{});case`saisons`:return(0,g.jsx)(Lt,{});case`al`:return(0,g.jsx)(Ut,{});case`ventes`:return(0,g.jsx)(qt,{});case`faq`:return(0,g.jsx)(Zt,{});case`svc`:return(0,g.jsx)($t,{});case`cons`:return(0,g.jsx)(tn,{});case`stats`:return(0,g.jsx)(hn,{});case`onb`:return(0,g.jsx)(Nn,{})}}function Wn(){return(0,g.jsx)(pe,{children:(0,g.jsx)(pt,{children:(0,g.jsx)(Un,{})})})}export{Wn as default};