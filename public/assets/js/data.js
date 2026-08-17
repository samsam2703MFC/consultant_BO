// Données de démonstration — Cockpit CEO L'Atelier By (réseau belge, génération déterministe)
function mulberry32(a){return function(){a|=0;a=a+0x6D2B79F5|0;let t=Math.imul(a^a>>>15,1|a);t=t+Math.imul(t^t>>>7,61|t)^t;return((t^t>>>14)>>>0)/4294967296};}
export const MOIS=['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];
export const TODAY='2026-07-31';
export const LEVIERS=[
 {slug:'trafic',nom:'Trafic',type:'Vente',color:'#6366f1',desc:'Faire venir plus de monde : visibilité locale, vitrine, animations, signalétique.'},
 {slug:'recurrence',nom:'Récurrence',type:'Vente',color:'#ec4899',desc:'Faire revenir les clients : fidélisation PWA, qualité constante, loyalty, suivi B2B.'},
 {slug:'xp',nom:'Expérience Client',type:'Vente',color:'#f59e0b',desc:'Qualité du moment en boutique : accueil < 3 s, conseil, ambiance, rapidité.'},
 {slug:'food-cost',nom:'Food Cost',type:'Coût',color:'#10b981',desc:'Coût matière : recettes, contrôle réception ProdAtelier, FIFO, casse & invendus.'},
 {slug:'labour-cost',nom:'Labour Cost',type:'Coût',color:'#8b5cf6',desc:'Coût main d\u2019œuvre : plannings au flux, productivité, ratio CA/ETP, polyvalence.'},
 {slug:'overhead-cost',nom:'Overhead Cost',type:'Coût',color:'#8b5cf6',desc:'Charges fixes : loyer, énergies, abonnements, assurances, maintenance.'}];
export const REPORT_TYPES=['Financier','Commercial','Contrôle qualité','Pilotage projets','Développement réseau'];
// Validation des tâches consultants : les cinq niveaux, le seuil, et le
// référentiel famille → type de problème.
//
// En mode API, cette définition vient de `meta.signalement` (réglage
// `ceo_app_setting`) ; ici elle sert le mode démonstration. C'est la SEULE
// copie côté client : ni templates.js ni app.js ne redéclarent un libellé ou
// une couleur, sinon les deux se mettent à diverger sans que rien ne le dise.
//
// Les mêmes cinq niveaux servent au panel consultant pour les tâches boutique.
// Un « majeur » doit vouloir dire la même chose des deux côtés, sinon les
// chiffres ne s'additionnent pas.
export const SIGNALEMENT={
 seuil:4,
 niveaux:[
  {n:5,nom:'Exemplaire',couleur:'#C9A227',aide:"au-dessus de l'attendu"},
  {n:4,nom:'Conforme',couleur:'#2D7A3E',aide:'livrable accepté'},
  {n:3,nom:'Non conforme — mineur',couleur:'#D97706',aide:'détail à reprendre'},
  {n:2,nom:'Non conforme — majeur',couleur:'#C0182B',aide:'écart net, à reprendre'},
  {n:1,nom:'Non conforme — critique',couleur:'#8D1D2C',aide:'à reprendre immédiatement'}],
 familles:[
  {nom:'Livrable',types:['Incomplet','Non conforme au brief','Sans relecture','Format inexploitable']},
  {nom:'Délai',types:['Rendu hors délai','Sans prévenir','Report répété']},
  {nom:'Qualité de service',types:['Injoignable','Compte rendu absent','Consigne non suivie']},
  {nom:'Budget',types:['Dépassement','Non justifié']},
  {nom:'Autre',types:['À préciser']}]};
// Contrôle des posts Facebook — pack de règles de l'agent, en mode démonstration.
//
// En mode API il vient de `/referentiels/facebook-regles` (réglage
// `ceo_app_setting.fbControle`). Comme SIGNALEMENT ci-dessus, c'est la SEULE
// copie côté client : ni app.js ni templates.js ne redéclarent un libellé, une
// gravité ou une famille.
//
// Les notes ne sont PAS redéfinies ici : l'agent note sur les cinq niveaux de
// SIGNALEMENT. Un « majeur » veut dire la même chose sur un livrable de
// consultant et sur un post de franchisé.
export const FB_CONTROLE={
 seuil:4,
 familles:[
  {nom:'Charte de marque',types:['Marque absente','Casse de marque','Hashtag de marque absent','Magasin non identifié','Ton non conforme']},
  {nom:'Mentions légales',types:['Conditions de promotion absentes','Règlement de concours absent','Allégation santé','Superlatif non justifié']},
  {nom:'Rédaction',types:['Faute de langue','Message trop court','Message trop long','Majuscules excessives','Ponctuation excessive','Hashtags excessifs']},
  {nom:'Visuel',types:['Visuel absent','Texte alternatif absent','Résolution insuffisante']},
  {nom:'Diffusion',types:['Créneau interdit','Lien non sécurisé','Domaine non autorisé','Publication dupliquée']},
  {nom:'Autre',types:['À préciser']}],
 regles:[
  {code:'marque-absente',nom:'Marque citée',famille:'Charte de marque',type:'Marque absente',gravite:3,actif:true,aide:'Le post doit nommer la marque du réseau.'},
  {code:'marque-casse',nom:'Casse officielle de la marque',famille:'Charte de marque',type:'Casse de marque',gravite:1,actif:true,aide:'La marque s\'écrit « L\'Atelier by » — ni tout en capitales, ni sans apostrophe.'},
  {code:'hashtag-marque',nom:'Hashtag de marque',famille:'Charte de marque',type:'Hashtag de marque absent',gravite:1,actif:true,aide:'Le hashtag du réseau rend les posts des magasins retrouvables.'},
  {code:'magasin-absent',nom:'Ancrage local',famille:'Charte de marque',type:'Magasin non identifié',gravite:2,actif:true,aide:'Un post de magasin doit dire de quel magasin il parle (ville ou quartier).'},
  {code:'promo-conditions',nom:'Conditions de promotion',famille:'Mentions légales',type:'Conditions de promotion absentes',gravite:3,actif:true,aide:'Une promotion annoncée sans période de validité engage le réseau.'},
  {code:'concours-reglement',nom:'Règlement de concours',famille:'Mentions légales',type:'Règlement de concours absent',gravite:3,actif:true,aide:'Un jeu-concours exige un règlement accessible.'},
  {code:'allegation-sante',nom:'Allégation santé',famille:'Mentions légales',type:'Allégation santé',gravite:2,actif:true,aide:'Les allégations nutritionnelles et de santé sont encadrées (règlement CE 1924/2006).'},
  {code:'superlatif',nom:'Superlatif non justifié',famille:'Mentions légales',type:'Superlatif non justifié',gravite:1,actif:true,aide:'Un superlatif comparatif doit être prouvé, sinon il relève de la publicité trompeuse.'},
  {code:'longueur-min',nom:'Message trop court',famille:'Rédaction',type:'Message trop court',gravite:1,actif:true,aide:'Sous ce seuil, le post ne porte pas de message.'},
  {code:'longueur-max',nom:'Message trop long',famille:'Rédaction',type:'Message trop long',gravite:1,actif:true,aide:'Au-delà, Facebook replie le texte et le message se perd.'},
  {code:'majuscules',nom:'Majuscules excessives',famille:'Rédaction',type:'Majuscules excessives',gravite:1,actif:true,aide:'Écrire en capitales est hors charte de ton.'},
  {code:'ponctuation',nom:'Ponctuation excessive',famille:'Rédaction',type:'Ponctuation excessive',gravite:1,actif:true,aide:'Trois points d\'exclamation suffisent rarement à convaincre.'},
  {code:'hashtags-nb',nom:'Hashtags excessifs',famille:'Rédaction',type:'Hashtags excessifs',gravite:1,actif:true,aide:'Au-delà, la portée baisse et le post fait « spam ».'},
  {code:'lexique',nom:'Fautes du lexique',famille:'Rédaction',type:'Faute de langue',gravite:1,actif:true,aide:'Liste de fautes fréquentes — à compléter au fil des relectures.'},
  {code:'visuel-absent',nom:'Visuel attendu',famille:'Visuel',type:'Visuel absent',gravite:2,actif:true,aide:'Un post annoncé en photo, carrousel ou vidéo doit porter son média.'},
  {code:'visuel-alt',nom:'Texte alternatif',famille:'Visuel',type:'Texte alternatif absent',gravite:1,actif:true,aide:'Sans texte alternatif, le visuel est invisible aux lecteurs d\'écran.'},
  {code:'visuel-resolution',nom:'Résolution du visuel',famille:'Visuel',type:'Résolution insuffisante',gravite:1,actif:true,aide:'En dessous, Facebook recompresse et le visuel devient flou.'},
  {code:'creneau',nom:'Créneau de publication',famille:'Diffusion',type:'Créneau interdit',gravite:1,actif:true,aide:'Hors de cette plage, le post sort sans audience et sans modération disponible.'},
  {code:'lien-http',nom:'Lien sécurisé',famille:'Diffusion',type:'Lien non sécurisé',gravite:2,actif:true,aide:'Un lien en http:// est signalé « non sécurisé » aux clients.'},
  {code:'lien-domaine',nom:'Domaine autorisé',famille:'Diffusion',type:'Domaine non autorisé',gravite:1,actif:true,aide:'Les liens sortants hors réseau se valident au cas par cas.'},
  {code:'doublon',nom:'Publication dupliquée',famille:'Diffusion',type:'Publication dupliquée',gravite:1,actif:true,aide:'Le même texte publié par plusieurs magasins se voit — et se pénalise.'}]};
// Posts de démonstration, du post publié au post qui vient d'arriver.
//
// Les écarts viennent de l'agent réel (`src/fbcontrole.php`) passé sur ces
// mêmes textes, pas d'une écriture à la main : la maquette montre donc ce que
// le moteur trouve vraiment. `fb11` est laissé « à contrôler » sans écart —
// c'est l'état d'un post non encore relu.
//
// Le contrôle tourne côté serveur (aucune règle n'est dupliquée en JavaScript) :
// en mode démonstration, le bouton « Contrôler » n'a donc rien à appeler et
// l'écran le dit.
export const FB_POSTS=[
 {id:'fb01',magasinId:'cha',magasin:'Bruxelles — Châtelain',auteur:'M. Lambert',format:'Photo',
  message:'Nouvelle fournée de pistolets au levain, sortie du four à 7 h ce matin à L\'Atelier by Châtelain. Notre boulanger Yassine a repris la recette de la maison mère : 18 heures de pousse lente, farine belge de Wallonie, croûte fine et mie serrée. Passez avant midi, il en reste rarement l\'après-midi. #latelierby #chatelain #boulangerieartisanale',lien:null,medias:[{nom:'pistolets-levain.jpg',type:'image',alt:'Pistolets au levain sortant du four, en gros plan',largeur:1600,hauteur:1200}],
  publierLe:'2026-07-21 09:00',soumisLe:'2026-07-20 08:40',statut:'publie',
  agent:{note:5,resume:'Aucun écart détecté — conforme à la charte.',le:'2026-07-20 08:40',passages:1},
  ecarts:[],
  decision:{note:5,famille:null,type:null,commentaire:null,le:'2026-07-20 17:10',par:'CEO'},
  publie:{le:'2026-07-21 09:04',fbId:'1122334455_9001'}},
 {id:'fb02',magasinId:'lie',magasin:'Liège — Le Carré',auteur:'V. Martin',format:'Photo',
  message:'GROSSE PROMO -50% SUR TOUTE LA PATISSERIE CE WEEK-END !!! Venez vite au Carré, c\'est de la folie !!! Le meilleur prix de Liège, imbattable !!!',lien:null,medias:[{nom:'promo-week-end.jpg',type:'image',alt:null,largeur:720,hauteur:720}],
  publierLe:'2026-07-29 08:00',soumisLe:'2026-07-28 21:15',statut:'refuse',
  agent:{note:1,resume:'8 écarts — plus grave : critique · Charte de marque (2) · Mentions légales (2) · Rédaction (2) · Visuel (2)',le:'2026-07-28 21:15',passages:1},
  ecarts:[{id:1,code:'marque-absente',regle:'Marque citée',famille:'Charte de marque',type:'Marque absente',gravite:3,message:'La marque « L\'Atelier by » n\'apparaît pas dans le message.',extrait:null,statut:'ouvert'},
   {id:2,code:'hashtag-marque',regle:'Hashtag de marque',famille:'Charte de marque',type:'Hashtag de marque absent',gravite:1,message:'Le hashtag #latelierby est absent.',extrait:null,statut:'ouvert'},
   {id:3,code:'promo-conditions',regle:'Conditions de promotion',famille:'Mentions légales',type:'Conditions de promotion absentes',gravite:3,message:'Promotion annoncée sans période de validité ni renvoi aux conditions.',extrait:'promo',statut:'ouvert'},
   {id:4,code:'superlatif',regle:'Superlatif non justifié',famille:'Mentions légales',type:'Superlatif non justifié',gravite:1,message:'Superlatif comparatif à retirer ou à prouver.',extrait:'le meilleur',statut:'ouvert'},
   {id:5,code:'majuscules',regle:'Majuscules excessives',famille:'Rédaction',type:'Majuscules excessives',gravite:1,message:'44 % de capitales, maximum 30 %.',extrait:null,statut:'ouvert'},
   {id:6,code:'ponctuation',regle:'Ponctuation excessive',famille:'Rédaction',type:'Ponctuation excessive',gravite:1,message:'9 points d\'exclamation, maximum 3.',extrait:null,statut:'ouvert'},
   {id:7,code:'visuel-alt',regle:'Texte alternatif',famille:'Visuel',type:'Texte alternatif absent',gravite:1,message:'1 visuel sans texte alternatif.',extrait:null,statut:'ouvert'},
   {id:8,code:'visuel-resolution',regle:'Résolution du visuel',famille:'Visuel',type:'Résolution insuffisante',gravite:1,message:'Largeur sous 1080 px : promo-week-end.jpg — 720 px.',extrait:null,statut:'ouvert'}],
  decision:{note:1,famille:'Mentions légales',type:'Conditions de promotion absentes',commentaire:'Reprends le visuel avec les dates de validité et la mention « dans la limite des stocks », et écris la marque en entier. On ne publie pas de -50 % sans période.',le:'2026-07-29 07:20',par:'CEO'},
  publie:{le:null,fbId:null}},
 {id:'fb03',magasinId:'gnd',magasin:'Gand — Korenmarkt',auteur:'J. De Smet',format:'Carrousel',
  message:'Grand concours de rentrée à L\'Atelier by Korenmarkt ! 5 paniers gourmands à gagner : likez la publication, commentez votre viennoiserie préférée et taguez deux amis. Tirage au sort le 15 septembre en boutique, place du Korenmarkt. #latelierby #gand #concours',lien:null,medias:[{nom:'panier-gourmand-1.jpg',type:'image',alt:'Panier gourmand garni de viennoiseries',largeur:1440,hauteur:1440},{nom:'panier-gourmand-2.jpg',type:'image',alt:'Comptoir de la boutique de Gand',largeur:1440,hauteur:1440}],
  publierLe:'2026-08-03 11:30',soumisLe:'2026-07-30 16:05',statut:'a_valider',
  agent:{note:1,resume:'1 écart — plus grave : critique · Mentions légales',le:'2026-07-30 16:05',passages:1},
  ecarts:[{id:9,code:'concours-reglement',regle:'Règlement de concours',famille:'Mentions légales',type:'Règlement de concours absent',gravite:3,message:'Jeu-concours sans règlement cité ni lien vers celui-ci.',extrait:'concours',statut:'ouvert'}],
  decision:{note:null,famille:null,type:null,commentaire:null,le:null,par:null},
  publie:{le:null,fbId:null}},
 {id:'fb04',magasinId:'fla',magasin:'Ixelles — Flagey',auteur:'S. Peeters',format:'Photo',
  message:'Le samedi matin à L\'Atelier by Flagey, c\'est brunch : tartines de pain de campagne, œufs de la ferme de Rebecq et jus pressé devant vous. On vous garde une table côté vitrine, place Flagey, dès 8 h 30.',lien:'https://www.atelierby.be/flagey',medias:[{nom:'brunch-flagey.jpg',type:'image',alt:null,largeur:1350,hauteur:1080}],
  publierLe:'2026-08-01 10:00',soumisLe:'2026-07-29 12:20',statut:'valide',
  agent:{note:4,resume:'2 écarts — plus grave : mineur · Charte de marque · Visuel',le:'2026-07-29 12:20',passages:1},
  ecarts:[{id:10,code:'hashtag-marque',regle:'Hashtag de marque',famille:'Charte de marque',type:'Hashtag de marque absent',gravite:1,message:'Le hashtag #latelierby est absent.',extrait:null,statut:'ouvert'},
   {id:11,code:'visuel-alt',regle:'Texte alternatif',famille:'Visuel',type:'Texte alternatif absent',gravite:1,message:'1 visuel sans texte alternatif.',extrait:null,statut:'ouvert'}],
  decision:{note:4,famille:null,type:null,commentaire:'Bon post. Ajoute le hashtag du réseau et le texte alternatif la prochaine fois — validé pour cette fois.',le:'2026-07-29 18:02',par:'CEO'},
  publie:{le:null,fbId:null}},
 {id:'fb05',magasinId:'nam',magasin:'Namur — Marché',auteur:'A. Rousseau',format:'Texte',
  message:'Nouveauté chez L\'Atelier by Namur : notre pain aux graines germées, sans sucre ajouté, parfait pour une cure détox de rentrée. Il est bon pour la santé et il tient tout le petit-déjeuner. À découvrir au Marché dès lundi. #latelierby #namur',lien:null,medias:[],
  publierLe:'2026-08-04 09:15',soumisLe:'2026-07-30 19:45',statut:'a_valider',
  agent:{note:2,resume:'1 écart — plus grave : majeur · Mentions légales',le:'2026-07-30 19:45',passages:1},
  ecarts:[{id:12,code:'allegation-sante',regle:'Allégation santé',famille:'Mentions légales',type:'Allégation santé',gravite:2,message:'Allégation santé à retirer ou à justifier réglementairement.',extrait:'détox',statut:'ouvert'}],
  decision:{note:null,famille:null,type:null,commentaire:null,le:null,par:null},
  publie:{le:null,fbId:null}},
 {id:'fb06',magasinId:'wat',magasin:'Waterloo — Centre',auteur:'C. Dubois',format:'Photo',
  message:'Parmis nos clients de Waterloo, beaucoup nous demandent la tarte au sucre de L\'ATELIER BY. Elle revient samedi, aujourdhui on prend les commandes. #latelierby',lien:null,medias:[{nom:'tarte-au-sucre.jpg',type:'image',alt:'Tarte au sucre entière sur une planche en bois',largeur:1280,hauteur:960}],
  publierLe:'2026-08-02 14:00',soumisLe:'2026-07-31 07:55',statut:'a_valider',
  agent:{note:3,resume:'3 écarts — plus grave : mineur · Charte de marque · Rédaction (2)',le:'2026-07-31 07:55',passages:1},
  ecarts:[{id:13,code:'marque-casse',regle:'Casse officielle de la marque',famille:'Charte de marque',type:'Casse de marque',gravite:1,message:'La marque n\'est pas écrite « L\'Atelier by ».',extrait:'L\'ATELIER BY',statut:'ouvert'},
   {id:14,code:'lexique',regle:'Fautes du lexique',famille:'Rédaction',type:'Faute de langue',gravite:1,message:'« parmis » → « parmi ».',extrait:'parmis',statut:'ouvert'},
   {id:15,code:'lexique',regle:'Fautes du lexique',famille:'Rédaction',type:'Faute de langue',gravite:1,message:'« aujourdhui » → « aujourd\'hui ».',extrait:'aujourdhui',statut:'ouvert'}],
  decision:{note:null,famille:null,type:null,commentaire:null,le:null,par:null},
  publie:{le:null,fbId:null}},
 {id:'fb07',magasinId:'anv',magasin:'Anvers — Meir',auteur:'J. De Smet',format:'Lien',
  message:'Notre chef pâtissier d\'Anvers — Meir est passé chez Radio 2 pour parler du speculoos maison de L\'Atelier by. L\'interview complète est à écouter ici, ça vaut le détour. #latelierby #anvers',lien:'http://blog-gourmand-anvers.be/interview-speculoos',medias:[],
  publierLe:'2026-08-05 12:00',soumisLe:'2026-07-30 10:10',statut:'a_valider',
  agent:{note:2,resume:'2 écarts — plus grave : majeur · Diffusion (2)',le:'2026-07-30 10:10',passages:1},
  ecarts:[{id:16,code:'lien-http',regle:'Lien sécurisé',famille:'Diffusion',type:'Lien non sécurisé',gravite:2,message:'Lien non sécurisé.',extrait:'http://blog-gourmand-anvers.be/interview-speculoos',statut:'ouvert'},
   {id:17,code:'lien-domaine',regle:'Domaine autorisé',famille:'Diffusion',type:'Domaine non autorisé',gravite:1,message:'Domaine « blog-gourmand-anvers.be » hors liste autorisée.',extrait:'http://blog-gourmand-anvers.be/interview-speculoos',statut:'ouvert'}],
  decision:{note:null,famille:null,type:null,commentaire:null,le:null,par:null},
  publie:{le:null,fbId:null}},
 {id:'fb08',magasinId:'lln',magasin:'Louvain-la-Neuve — Grand-Place',auteur:'C. Dubois',format:'Photo',
  message:'Les étudiants rentrent, les cookies aussi. Trois recettes chez L\'Atelier by cette semaine : chocolat noir 70 %, beurre salé et un nouveau spéculoos-noisette. Fournée toutes les deux heures jusqu\'à 18 h. #latelierby #cookies #rentree',lien:null,medias:[{nom:'cookies-rentree.jpg',type:'image',alt:'Trois cookies alignés sur du papier cuisson',largeur:1440,hauteur:1080}],
  publierLe:'2026-07-28 17:00',soumisLe:'2026-07-27 18:30',statut:'refuse',
  agent:{note:2,resume:'1 écart — plus grave : majeur · Charte de marque',le:'2026-07-27 18:30',passages:1},
  ecarts:[{id:18,code:'magasin-absent',regle:'Ancrage local',famille:'Charte de marque',type:'Magasin non identifié',gravite:2,message:'Ni la ville ni le quartier de « Louvain-la-Neuve — Grand-Place » n\'apparaissent dans le message.',extrait:null,statut:'ouvert'}],
  decision:{note:2,famille:'Charte de marque',type:'Magasin non identifié',commentaire:'Le post pourrait venir de n\'importe quel magasin du réseau. Nomme Louvain-la-Neuve et la Grand-Place, sinon l\'ancrage local ne sert à rien.',le:'2026-07-28 08:15',par:'CEO'},
  publie:{le:null,fbId:null}},
 {id:'fb09',magasinId:'ucc',magasin:'Uccle — Bascule',auteur:'S. Peeters',format:'Photo',
  message:'Rentrée des classes à Uccle : L\'Atelier by Bascule prépare les boîtes à tartines. Sandwich du jour, fruit et biscuit maison pour 6,50 € à emporter, tous les matins de 7 h à 10 h à la Bascule. Commandez la veille par téléphone, c\'est prêt à l\'heure. #latelierby #uccle',lien:'https://www.atelierby.be/uccle/boite-a-tartines',medias:[{nom:'boite-a-tartines.jpg',type:'image',alt:'Boîte à tartines garnie posée sur un comptoir',largeur:1620,hauteur:1080}],
  publierLe:'2026-08-06 09:30',soumisLe:'2026-07-30 09:05',statut:'valide',
  agent:{note:5,resume:'Aucun écart détecté — conforme à la charte.',le:'2026-07-30 09:05',passages:1},
  ecarts:[],
  decision:{note:5,famille:null,type:null,commentaire:null,le:'2026-07-30 14:40',par:'CEO'},
  publie:{le:null,fbId:null}},
 {id:'fb10',magasinId:'nam',magasin:'Namur — Marché',auteur:'A. Rousseau',format:'Photo',
  message:'Nouvelle fournée de pistolets au levain, sortie du four à 7 h ce matin à L\'Atelier by Châtelain. Notre boulanger Yassine a repris la recette de la maison mère : 18 heures de pousse lente, farine belge de Wallonie, croûte fine et mie serrée. Passez avant midi, il en reste rarement l\'après-midi. #latelierby #chatelain #boulangerieartisanale',lien:null,medias:[{nom:'pistolets-namur.jpg',type:'image',alt:'Pistolets au levain en corbeille',largeur:1600,hauteur:1200}],
  publierLe:'2026-08-07 09:00',soumisLe:'2026-07-31 06:20',statut:'a_valider',
  agent:{note:2,resume:'2 écarts — plus grave : majeur · Charte de marque · Diffusion',le:'2026-07-31 06:20',passages:1},
  ecarts:[{id:19,code:'magasin-absent',regle:'Ancrage local',famille:'Charte de marque',type:'Magasin non identifié',gravite:2,message:'Ni la ville ni le quartier de « Namur — Marché » n\'apparaissent dans le message.',extrait:null,statut:'ouvert'},
   {id:20,code:'doublon',regle:'Publication dupliquée',famille:'Diffusion',type:'Publication dupliquée',gravite:1,message:'Texte identique à 100 % à un post de Bruxelles — Châtelain.',extrait:null,statut:'ouvert'}],
  decision:{note:null,famille:null,type:null,commentaire:null,le:null,par:null},
  publie:{le:null,fbId:null}},
 {id:'fb11',magasinId:'kno',magasin:'Knokke — Lippenslaan',auteur:'L. Vermeulen',format:'Événement',
  message:'Save the date : L\'Atelier by ouvre Lippenslaan à Knokke le 3 octobre. Café offert toute la journée d\'ouverture et visite de l\'atelier de production toutes les heures. #latelierby #knokke #ouverture',lien:'https://www.atelierby.be/knokke',medias:[],
  publierLe:'2026-08-10 05:30',soumisLe:'2026-07-31 05:40',statut:'a_controler',
  agent:{note:null,resume:null,le:null,passages:0},
  ecarts:[],
  decision:{note:null,famille:null,type:null,commentaire:null,le:null,par:null},
  publie:{le:null,fbId:null}}];
export const FAMILLES=['Produits','Services','Organisation & coûts','Développement réseau'];
export const KPI_LIST=['CA réseau','Trafic','Panier moyen','Récurrence client','Food cost %','Labour cost %','Overhead cost %','Marge nette franchisé %','Points de vente','Expérience client (mystère)'];
export function buildData(){
 const rnd=mulberry32(20260731);
 const saison=[.92,.88,.98,1.02,1.05,1.08,1.12,.85,1.0,1.04,1.10,1.35];
 const stores=[
  {id:'cha',code:'BXL-CHA',nom:'Bruxelles — Châtelain',fr:'M. Lambert',zone:'Bruxelles',status:'Ouvert',opened:'2019-03',caBase:82000,g:1.09,tg:1.12,panier:11.8,m25:.150,m26:.158,valT:920000,r0:.90,r1:.97,food:29,labour:30.5,overhead:11},
  {id:'fla',code:'BXL-FLA',nom:'Ixelles — Flagey',fr:'S. Peeters',zone:'Bruxelles',status:'Ouvert',opened:'2020-09',caBase:74000,g:1.05,tg:1.09,panier:11.2,m25:.141,m26:.146,valT:840000,r0:.85,r1:.90,food:30,labour:31.5,overhead:11.5},
  {id:'ucc',code:'BXL-UCC',nom:'Uccle — Bascule',fr:'S. Peeters',zone:'Bruxelles',status:'Ouvert',opened:'2022-04',caBase:61000,g:1.07,tg:1.10,panier:12.4,m25:.146,m26:.151,valT:700000,r0:.82,r1:.88,food:29.5,labour:32,overhead:12},
  {id:'wat',code:'WAT-01',nom:'Waterloo — Centre',fr:'C. Dubois',zone:'Brabant wallon',status:'Ouvert',opened:'2021-06',caBase:58000,g:1.03,tg:1.08,panier:10.9,m25:.135,m26:.139,valT:640000,r0:.78,r1:.82,food:31,labour:32.5,overhead:12.5},
  {id:'lln',code:'LLN-01',nom:'Louvain-la-Neuve — Grand-Place',fr:'C. Dubois',zone:'Brabant wallon',status:'Ouvert',opened:'2023-02',caBase:52000,g:1.11,tg:1.13,panier:9.8,m25:.137,m26:.142,valT:560000,r0:.80,r1:.92,food:30.5,labour:31,overhead:12},
  {id:'nam',code:'NAM-01',nom:'Namur — Marché',fr:'A. Rousseau',zone:'Namur',status:'Ouvert',opened:'2022-10',caBase:49000,g:1.04,tg:1.08,panier:10.4,m25:.132,m26:.136,valT:520000,r0:.76,r1:.80,food:31.5,labour:33,overhead:13},
  {id:'lie',code:'LIE-01',nom:'Liège — Le Carré',fr:'V. Martin',zone:'Liège',status:'Ouvert',opened:'2021-11',caBase:56000,g:.97,tg:1.05,panier:10.1,m25:.132,m26:.124,mdrift:-.0015,fdrift:.35,valT:610000,r0:.84,r1:.76,food:33.5,labour:34,overhead:13.5,risk:true},
  {id:'gnd',code:'GND-01',nom:'Gand — Korenmarkt',fr:'J. De Smet',zone:'Flandre',status:'Ouvert',opened:'2023-05',caBase:67000,g:1.16,tg:1.14,panier:12.1,m25:.155,m26:.162,valT:760000,r0:.88,r1:1.02,food:28.5,labour:29.5,overhead:11},
  {id:'anv',code:'ANV-01',nom:'Anvers — Meir',fr:'J. De Smet',zone:'Flandre',status:'Ouvert',opened:'2024-03',caBase:71000,g:1.13,tg:1.12,panier:12.6,m25:.143,m26:.149,valT:800000,r0:.75,r1:.94,food:29.5,labour:30.5,overhead:11.5},
  {id:'kno',code:'KNO-01',nom:'Knokke — Lippenslaan',fr:'L. Vermeulen',zone:'Littoral',status:'En ouverture',opened:'2026-10',caBase:78000,g:1,tg:1,panier:13.2,m25:.15,m26:.15,valT:860000,r0:0,r1:0,food:29,labour:30,overhead:12}];
 for(const s of stores){
  if(s.status!=='Ouvert')continue;
  s.perf={};
  for(const y of [2025,2026]){
   const arr=[];
   for(let m=0;m<12;m++){
    const idx=(y-2025)*12+m;
    const caT=y===2026?Math.round(s.caBase*saison[m]*s.tg/100)*100:null;
    const actual=!(y===2026&&m>6);
    if(!actual){arr.push({ca:null,caT,marge:null,mp:null,tickets:null,panier:null,food:null,labour:null,overhead:null,val:null});continue;}
    const noise=1+(rnd()-.5)*.07;
    const ca=Math.round(s.caBase*saison[m]*(y===2026?s.g:1)*noise/100)*100;
    let mp=y===2026?s.m26:s.m25;
    if(s.mdrift&&y===2026)mp+=s.mdrift*m;
    const marge=Math.round(ca*mp/10)*10;
    const panier=+(s.panier*(y===2026?1:.962)*(1+(rnd()-.5)*.03)).toFixed(2);
    const tickets=Math.round(ca/panier);
    const food=+(s.food+(y===2025?-.4:0)+((s.fdrift&&y===2026)?s.fdrift*m:0)+(rnd()-.5)*.8).toFixed(1);
    const labour=+(s.labour+(y===2025?-.3:0)+(rnd()-.5)*.9).toFixed(1);
    const overhead=+(s.overhead+(rnd()-.5)*.4).toFixed(1);
    const val=Math.round(s.valT*(s.r0+(s.r1-s.r0)*idx/18)/1000)*1000;
    arr.push({ca,caT,marge,mp,tickets,panier,food,labour,overhead,val});
   }
   s.perf[y]=arr;
  }
 }
 const consultants=[
  {visites:[{'date':'2026-07-24','store':'Liège — Carré','objet':'Audit planning équipe — dérive labour cost'},{'date':'2026-07-17','store':'Bruxelles — Châtelain','objet':'Suivi plannings au flux (pilote)'},{'date':'2026-07-10','store':'Ixelles — Flagey','objet':'Relevé contrats énergie (état des lieux)'},{'date':'2026-06-26','store':'Namur — Centre','objet':'Audit planning + entretien franchisé'},{'date':'2026-06-18','store':'Liège — Carré','objet':'Audit plannings 9 magasins — restitution'}],id:'mj',nom:'Marc Janssens',role:'Organisation & RH',tjm:850,charge:78,email:'marc.janssens@conseil.be'},
  {visites:[{'date':'2026-07-22','store':'Anvers — Meir','objet':'Plan trafic rentrée + PLV snacking'},{'date':'2026-07-08','store':'Gand — Veldstraat','objet':'Mesure mystère post-formation'},{'date':'2026-06-30','store':'Bruxelles — Châtelain','objet':'Test PLV gamme snacking été'}],id:'ed',nom:'Élise Dupont',role:'Marketing & trafic',tjm:780,charge:64,email:'elise.dupont@conseil.be'},
  {visites:[{'date':'2026-07-15','store':'Louvain-la-Neuve — Grand-Place','objet':'Déploiement tableau CA/ETP automatisé'},{'date':'2026-06-09','store':'Wavre — Centre','objet':'Recueil données socio-éco (étude Wallonie picarde)'}],id:'tw',nom:'Thomas Wouters',role:'Data & IT',tjm:900,charge:55,email:'t.wouters@conseil.be'},
  {visites:[{'date':'2026-07-29','store':'Knokke — Lippenslaan','objet':'Préparation ouverture — parcours & formation'},{'date':'2026-07-21','store':'Liège — Carré','objet':'Formation accueil < 3 s — rattrapage équipe'},{'date':'2026-07-03','store':'Namur — Centre','objet':'Contrôle standard accueil (client mystère)'},{'date':'2026-06-24','store':'Anvers — Meir','objet':'Formation 9 équipes — session Flandre'}],id:'sr',nom:'Sofia Ricci',role:'Retail ops & formation',tjm:820,charge:71,email:'sofia.ricci@conseil.be'}];
 const suppliers=[
  {id:'pa',nom:'ProdAtelier',perim:'Production & fiches techniques',email:'contact@prodatelier.be'},
  {id:'sn',nom:'Studio Nord',perim:'Design, print & agencement',email:'hello@studionord.be'},
  {id:'at',nom:'AtelierTech',perim:'Développement PWA & IT',email:'dev@ateliertech.be'}];
 const T=(id,nom,ot,oid,due,done,mag)=>({id,nom,owner:{t:ot,id:oid},due,done:done||null,relance:null,magasin:mag||null});
 const projects=[
  {id:'p1',famille:'Produits',nom:'Gamme snacking été',statut:'En cours',prio:'Haute',debut:'2026-03-02',fin:'2026-09-15',axes:['Ventes'],leviers:['trafic','food-cost'],produit:'Snacking',categorie:'Salé',budget:24000,valeurEst:120000,valeurTxt:'CA additionnel réseau, année pleine',kpis:['CA réseau','Panier moyen'],
   jalons:[{nom:'Étude gamme & pricing',cible:'2026-04-10',reel:'2026-04-08'},{nom:'Fiches techniques ProdAtelier',cible:'2026-05-22',reel:'2026-05-30'},{nom:'Test 3 magasins pilotes',cible:'2026-07-10',reel:'2026-07-12'},{nom:'Formation équipes',cible:'2026-08-20',reel:null},{nom:'Lancement réseau',cible:'2026-09-15',reel:null}],
   taches:[T('t11','Benchmark pricing snacking','c','ed','2026-03-27','2026-03-25'),T('t12','Recettes & fiches techniques','s','pa','2026-05-22','2026-05-30'),T('t13','PLV & affiches magasins pilotes','s','sn','2026-06-26','2026-06-24'),T('t14','Bilan test pilotes','c','sr','2026-07-17','2026-07-19'),T('t15','Kit formation équipes','c','sr','2026-08-14')],
   couts:[{poste:'Jours-homme consultants',prevu:12000,reel:10200},{poste:'Fournisseurs — ProdAtelier',prevu:8000,reel:7400},{poste:'Print & PLV',prevu:4000,reel:1800}]},
  {id:'p2',famille:'Services',nom:'Programme fidélité PWA v2',statut:'En cours',prio:'Haute',debut:'2026-05-04',fin:'2026-11-30',axes:['Ventes'],leviers:['recurrence'],produit:null,categorie:null,budget:38000,valeurEst:180000,valeurTxt:'Récurrence +8 % sur clients identifiés',kpis:['Taux de retour client'],
   jalons:[{nom:'Cadrage & specs',cible:'2026-05-29',reel:'2026-05-28'},{nom:'Développement (3 sprints)',cible:'2026-09-30',reel:null},{nom:'Lancement réseau',cible:'2026-11-30',reel:null}],
   taches:[T('t21','Specs fonctionnelles v2','c','tw','2026-05-29','2026-05-28'),T('t22','Sprint 1 — comptes clients','s','at','2026-06-30','2026-06-30'),T('t23','Sprint 2 — carte de fidélité','s','at','2026-07-24'),T('t24','Plan de lancement & CRM','c','ed','2026-09-04')],
   couts:[{poste:'Développement AtelierTech',prevu:28000,reel:16500},{poste:'Jours-homme consultants',prevu:7000,reel:4200},{poste:'Licences & outils',prevu:3000,reel:800}]},
  {id:'p3',famille:'Organisation & coûts',nom:'Refonte planning équipes (CA/ETP)',statut:'En retard',prio:'Haute',debut:'2026-05-18',fin:'2026-07-20',axes:['Marge nette franchisé'],leviers:['labour-cost'],produit:null,categorie:null,budget:15000,valeurEst:95000,valeurTxt:'Gain labour-cost −1,5 pt réseau',kpis:['Ratio CA/ETP'],
   jalons:[{nom:'Audit plannings 9 magasins',cible:'2026-06-12',reel:'2026-06-18'},{nom:'Modèle & outils',cible:'2026-07-20',reel:null},{nom:'Déploiement réseau',cible:'2026-09-30',reel:null}],
   taches:[T('t31','Audit plannings 9 magasins','c','mj','2026-06-12','2026-06-18','lie'),T('t32','Modèle de planning type','c','mj','2026-07-06',null,'lie'),T('t33','Tableau CA/ETP automatisé','c','tw','2026-07-15','2026-07-14')],
   couts:[{poste:'Jours-homme consultants',prevu:13000,reel:15600},{poste:'Licences & outils',prevu:2000,reel:1200}]},
  {id:'p4',famille:'Organisation & coûts',nom:'Renégociation contrats énergie',statut:'En cours',prio:'Moyenne',debut:'2026-06-15',fin:'2026-10-31',axes:['Marge nette franchisé'],leviers:['overhead-cost'],produit:null,categorie:null,budget:6000,valeurEst:54000,valeurTxt:'Économie annuelle réseau (9 sites)',kpis:['Overhead cost %'],
   jalons:[{nom:'État des lieux contrats',cible:'2026-07-10',reel:'2026-07-08'},{nom:'Appel d\u2019offres (9 sites)',cible:'2026-09-18',reel:null},{nom:'Nouveaux contrats signés',cible:'2026-10-31',reel:null}],
   taches:[T('t41','Relevé contrats & échéances','c','mj','2026-07-10','2026-07-08'),T('t42','Appel d\u2019offres énergie (9 sites)','c','mj','2026-09-18')],
   couts:[{poste:'Jours-homme consultants',prevu:6000,reel:2500}]},
  {id:'p5',famille:'Développement réseau',nom:'Ouverture Knokke — Lippenslaan',statut:'En cours',prio:'Haute',debut:'2026-04-01',fin:'2026-10-01',axes:['Développement réseau'],leviers:['trafic'],produit:null,categorie:null,budget:45000,valeurEst:96000,valeurTxt:'Marge année 1 du point de vente',kpis:['Points de vente'],
   jalons:[{nom:'Signature bail',cible:'2026-05-15',reel:'2026-05-12'},{nom:'Travaux & agencement',cible:'2026-08-25',reel:null},{nom:'Recrutement & formation',cible:'2026-09-20',reel:null},{nom:'Ouverture',cible:'2026-10-01',reel:null}],
   taches:[T('t51','Plans & permis agencement','s','sn','2026-06-20','2026-06-19','kno'),T('t52','Travaux & agencement boutique','s','sn','2026-08-25',null,'kno'),T('t53','Recrutement responsable & équipe','c','sr','2026-09-05'),T('t54','Formation initiale (TFB)','c','sr','2026-09-20')],
   couts:[{poste:'Agencement Studio Nord',prevu:32000,reel:24500},{poste:'Jours-homme consultants',prevu:9000,reel:5300},{poste:'Autres (juridique, permis)',prevu:4000,reel:1200}]},
  {id:'p6',famille:'Produits',nom:'Contrôle réception ProdAtelier',statut:'À lancer',prio:'Moyenne',debut:'2026-09-15',fin:'2026-12-15',axes:['Marge nette franchisé'],leviers:['food-cost'],produit:null,categorie:null,budget:9000,valeurEst:70000,valeurTxt:'Réduction casse & écarts réception',kpis:['Food cost %'],
   jalons:[{nom:'Cadrage process réception',cible:'2026-10-09',reel:null},{nom:'Pilote 2 magasins',cible:'2026-11-13',reel:null},{nom:'Déploiement réseau',cible:'2026-12-15',reel:null}],
   taches:[T('t61','Cadrage process réception','c','sr','2026-10-09',null,'cha')],
   couts:[{poste:'Jours-homme consultants',prevu:9000,reel:0}]},
  {id:'p7',famille:'Services',nom:'Parcours accueil < 3 secondes',statut:'Terminé',prio:'Moyenne',debut:'2026-02-02',fin:'2026-05-30',axes:['Ventes'],leviers:['xp'],produit:null,categorie:null,budget:11000,valeurEst:62000,valeurReal:62000,valeurTxt:'Panier moyen +2,1 % constaté post-formation',kpis:['NPS réseau'],
   jalons:[{nom:'Standard accueil & script',cible:'2026-04-17',reel:'2026-04-16'},{nom:'Formation 9 équipes',cible:'2026-05-15',reel:'2026-05-13'},{nom:'Mesure mystère',cible:'2026-05-29',reel:'2026-05-29'}],
   taches:[T('t71','Standard accueil & script','c','sr','2026-04-17','2026-04-16'),T('t72','Formation 9 équipes','c','sr','2026-05-15','2026-05-13'),T('t73','Mesure mystère post-formation','c','ed','2026-05-29','2026-05-29')],
   couts:[{poste:'Jours-homme consultants',prevu:10000,reel:9600},{poste:'Client mystère',prevu:1000,reel:800}]},
  {id:'p8',famille:'Développement réseau',nom:'Étude implantation Wallonie picarde',statut:'En pause',prio:'Basse',debut:'2026-05-01',fin:'2027-02-28',axes:['Développement réseau'],leviers:['trafic'],produit:null,categorie:null,budget:7000,valeurEst:0,valeurTxt:'Étude — valeur portée par les ouvertures futures',kpis:['Points de vente'],
   jalons:[{nom:'Recueil données socio-éco',cible:'2026-06-05',reel:'2026-06-09'},{nom:'Analyse zones',cible:'2027-01-15',reel:null},{nom:'Recommandation',cible:'2027-02-28',reel:null}],
   taches:[T('t81','Recueil données socio-éco','c','tw','2026-06-05','2026-06-09')],
   couts:[{poste:'Jours-homme consultants',prevu:6000,reel:1200},{poste:'Données & études',prevu:1000,reel:0}]}];
 const candidates=[
  {id:'c1',nom:'F. Lejeune',zone:'Arlon (Luxembourg)',stage:'Lead',apport:null,fin:null,source:'Site web',next:'Premier appel de qualification',nextDate:'2026-08-05',last:'2026-07-26'},
  {id:'c2',nom:'C. Vandenberghe',zone:'Ostende (Littoral)',stage:'Lead',apport:null,fin:null,source:'LinkedIn',next:'Relancer — sans réponse depuis 4 sem.',nextDate:'2026-08-03',last:'2026-07-02',inactif:true},
  {id:'c3',nom:'P. Georges',zone:'Charleroi (Hainaut)',stage:'Qualifié',apport:60000,fin:180000,source:'Salon Franchise Bruxelles',next:'Planifier entretien découverte',nextDate:'2026-08-12',last:'2026-07-21'},
  {id:'c4',nom:'A. Mertens',zone:'Bruges (Flandre)',stage:'Qualifié',apport:95000,fin:260000,source:'Recommandation franchisé',next:'Visite magasin Gand',nextDate:'2026-08-08',last:'2026-07-24'},
  {id:'c5',nom:'K. Willems',zone:'Hasselt (Limbourg)',stage:'Entretien',apport:110000,fin:300000,source:'Salon Franchise Bruxelles',next:'Entretien n°2 avec CEO',nextDate:'2026-08-06',last:'2026-07-28'},
  {id:'c6',nom:'N. Haddad',zone:'Mons (Hainaut)',stage:'Business plan',apport:75000,fin:220000,source:'Site web',next:'Revue BP avec expert-comptable',nextDate:'2026-08-14',last:'2026-07-27'},
  {id:'c7',nom:'R. Fontaine',zone:'Wavre (Brabant wallon)',stage:'Business plan',apport:85000,fin:240000,source:'Presse locale',next:'Étude d\u2019implantation zone Wavre',nextDate:'2026-08-20',last:'2026-07-25'},
  {id:'c8',nom:'D. Carlier',zone:'Tournai (Hainaut)',stage:'Validé',apport:90000,fin:280000,source:'Salon Franchise Bruxelles',next:'Préparation contrat de franchise',nextDate:'2026-08-18',last:'2026-07-29',ouverture:'2027-01'},
  {id:'c9',nom:'L. Vermeulen',zone:'Knokke (Littoral)',stage:'Signé',apport:130000,fin:380000,source:'Recommandation franchisé',next:'Converti en point de vente KNO-01',nextDate:null,last:'2026-07-15',ouverture:'2026-10',pdv:'KNO-01'},
  {id:'c10',nom:'J. Moreau',zone:'Charleroi (Hainaut)',stage:'Perdu',apport:55000,fin:150000,source:'Site web',next:'Financement refusé — clôturé',nextDate:null,last:'2026-06-10'}];
 const zones=[
  {nom:'Bruxelles',statut:'Occupée',pot:2400,pdv:3},{nom:'Brabant wallon',statut:'Occupée',pot:1300,pdv:2},{nom:'Namur',statut:'Occupée',pot:700,pdv:1},{nom:'Liège',statut:'Occupée',pot:900,pdv:1},{nom:'Flandre-Orientale (Gand)',statut:'Occupée',pot:1000,pdv:1},{nom:'Anvers',statut:'Occupée',pot:1200,pdv:1},
  {nom:'Littoral (Knokke–Ostende)',statut:'Réservée',pot:1100,pdv:0,note:'Knokke en ouverture 10/2026'},{nom:'Hainaut — Tournai',statut:'Réservée',pot:600,pdv:0,note:'Candidat validé (D. Carlier)'},{nom:'Hainaut — Mons',statut:'En prospection',pot:650,pdv:0},{nom:'Limbourg (Hasselt)',statut:'En prospection',pot:750,pdv:0},
  {nom:'Charleroi',statut:'Libre',pot:800,pdv:0},{nom:'Luxembourg (Arlon)',statut:'Libre',pot:450,pdv:0}];
 const kpis=[
  {id:'k1',nom:'CA réseau — mensuel',unite:'€',cible:640000,levier:'trafic',scope:'Réseau',freq:'Mensuel'},
  {id:'k2',nom:'Marge nette franchisés',unite:'%',cible:15,levier:'food-cost',scope:'Réseau',freq:'Mensuel'},
  {id:'k3',nom:'Points de vente',unite:'nb',cible:11,levier:'trafic',scope:'Réseau',freq:'Trimestriel'},
  {id:'k4',nom:'Panier moyen groupe',unite:'€',cible:11.8,levier:'xp',scope:'Réseau',freq:'Mensuel'},
  {id:'k5',nom:'Tickets groupe — mensuel',unite:'nb',cible:56000,levier:'recurrence',scope:'Réseau',freq:'Mensuel'},
  {id:'k6',nom:'Ratio CA/ETP réseau',unite:'€',cible:14500,levier:'labour-cost',scope:'Réseau',freq:'Mensuel'}];
 const targets={
  ca:{h1:{an:2026,cible:7400000},h3:{an:2028,cible:11500000,note:'Hypothèse : {ouvertures} ouvertures d\u2019ici 2028 (Knokke, Tournai, puis 3 zones à sécuriser), CA moyen de {caMoyen} par nouveau point de vente en année pleine.'},h5:{an:2030,cible:18000000,note:'Hypothèse : {ouvertures} ouvertures d\u2019ici 2030. La cible exige d\u2019ouvrir en moyenne 2 à 3 points de vente par an — le pipeline candidats doit rester ≥ 8 dossiers actifs.'}},
  expansion:{h1:{an:2026,cible:2,reel:1},h3:{an:2028,cible:6,reel:1},h5:{an:2030,cible:12,reel:1}},
  caMoyenOuverture:820000};
 const emailTemplates=[
  {id:'e1',nom:'Rappel J-3 avant échéance',sujet:'[L\u2019Atelier by] Échéance dans 3 jours — {tache}',corps:'Bonjour {destinataire},\n\nPetit rappel : la tâche « {tache} » (projet {projet}) arrive à échéance le {echeance}.\nMerci de confirmer que la livraison est en bonne voie, ou de signaler tout blocage dès maintenant.\n\nBien à vous,\nDirection réseau — L\u2019Atelier by'},
  {id:'e2',nom:'Relance J+1 en retard',sujet:'[L\u2019Atelier by] Tâche en retard — {tache}',corps:'Bonjour {destinataire},\n\nLa tâche « {tache} » (projet {projet}) était attendue le {echeance} et n\u2019est pas livrée.\nMerci de nous transmettre sous 48 h : la nouvelle date ferme, la cause du retard et le plan de rattrapage.\n\nBien à vous,\nDirection réseau — L\u2019Atelier by'},
  {id:'e3',nom:'Candidat sans activité',sujet:'[L\u2019Atelier by] Votre projet de franchise',corps:'Bonjour {destinataire},\n\nNous restons sans nouvelle depuis quelques semaines concernant votre projet d\u2019ouverture ({zone}).\nSouhaitez-vous poursuivre ? Nous pouvons planifier un point téléphonique cette semaine.\n\nBien à vous,\nDirection réseau — L\u2019Atelier by'}];
 const people=[
  {id:'ceo',nom:'Laurent Mertens',role:'CEO',email:'laurent.mertens@latelierby.be'},
  {id:'dir',nom:'Sophie Claes',role:'Directrice réseau',email:'sophie.claes@latelierby.be'},
  {id:'cfo',nom:'Bart Peeters',role:'Comptabilité & finance',email:'bart.peeters@latelierby.be'},
  {id:'exp',nom:'Julie Lambert',role:'Responsable expansion',email:'julie.lambert@latelierby.be'},
  {id:'ass',nom:'Nadia El Amrani',role:'Assistante de direction',email:'nadia.elamrani@latelierby.be'}];
 const reports=[
  {id:'r1',type:'Financier',postes:['p1','p3','p4','p5'],nom:'Rapport de performance réseau',freq:'Mensuel',dernier:'2026-07-02',destId:'ceo',ccId:'dir',actif:true,desc:'CA par magasin, valeur consolidée, cible vs réel, snapshot heatmap'},
  {id:'r2',type:'Financier',postes:['p1','p5'],nom:'Trajectoire objectifs 1/3/5 ans',freq:'Trimestriel',dernier:'2026-07-05',destId:'ceo',ccId:'dir',actif:true,desc:'Position vs cibles de CA, projection fin d\u2019année'},
  {id:'r3',type:'Pilotage projets',postes:['p8'],nom:'Suivi des projets',freq:'Hebdomadaire',dernier:'2026-07-27',destId:'ceo',ccId:'ass',actif:true,desc:'Avancement, jalons, retards, échéances de la semaine'},
  {id:'r4',type:'Contrôle qualité',postes:['p9','p10'],nom:'Contrôle consultants & fournisseurs',freq:'Hebdomadaire',dernier:'2026-07-27',destId:'ceo',ccId:'',actif:true,desc:'Livraisons à temps / en retard, responsables en cause, fiabilité'},
  {id:'r5',type:'Financier',postes:['p11'],nom:'ROI & coûts',freq:'Mensuel',dernier:'2026-07-02',destId:'ceo',ccId:'cfo',actif:true,desc:'ROI par projet, réel vs budget, dépassements'},
  {id:'r6',type:'Commercial',postes:['p1','p7'],nom:'KPI clés réseau',freq:'Mensuel',dernier:'2026-07-02',destId:'ceo',ccId:'dir',actif:true,desc:'Ventes, marge nette, points de vente, variation vs période précédente'},
  {id:'r7',type:'Développement réseau',postes:['p2'],nom:'Expansion & pipeline',freq:'Mensuel',dernier:'2026-07-02',destId:'ceo',ccId:'exp',actif:true,desc:'État du pipeline, ouvertures vs objectif, zones blanches prioritaires'},
  {id:'r8',type:'Financier',postes:['p3','p6'],nom:'Marge & dérives de coûts',freq:'Mensuel',dernier:'2026-07-02',destId:'ceo',ccId:'cfo',actif:true,desc:'Marge nette, dérives food/labour/overhead, magasins à traiter'},
  {id:'r9',type:'Commercial',postes:['p3','p4'],nom:'Top & flop magasins',freq:'Mensuel',dernier:'2026-07-02',destId:'ceo',ccId:'',actif:false,desc:'Classements automatiques, magasins à risque récurrent'}];
 const alertRules=[
  {id:'a1',nom:'Projet en retard',canal:'Push + email',actif:true},
  {id:'a2',nom:'Dépassement de budget projet',canal:'Push + email',actif:true},
  {id:'a3',nom:'Objectif de CA à risque',canal:'Email',actif:true},
  {id:'a4',nom:'Magasin en sous-performance 3 mois consécutifs',canal:'Push + email',actif:true},
  {id:'a5',nom:'Relance restée sans réponse (72 h)',canal:'Push',actif:false}];
 const logs=[
  {ts:'2026-07-31 08:15',qui:'Système',type:'Alerte',projet:'—',msg:'Magasin LIE-01 en sous-performance pour le 3e mois consécutif — food-cost 36,1 %'},
  {ts:'2026-07-30 17:40',qui:'CEO',type:'Statut',projet:'Refonte planning équipes',msg:'Statut passé de « En cours » à « En retard » (jalon Modèle & outils dépassé)'},
  {ts:'2026-07-30 09:12',qui:'Système',type:'Relance',projet:'Programme fidélité PWA v2',msg:'Relance J+1 envoyée à AtelierTech — tâche « Sprint 2 — carte de fidélité »'},
  {ts:'2026-07-29 16:05',qui:'CEO',type:'Coût',projet:'Ouverture Knokke',msg:'Coût ajouté : Agencement Studio Nord — acompte 2, 12 400 €'},
  {ts:'2026-07-28 11:30',qui:'Système',type:'Rapport',projet:'—',msg:'Rapport « Suivi des projets » (hebdo) généré et envoyé au CEO (PDF)'},
  {ts:'2026-07-27 14:22',qui:'CEO',type:'Pipeline',projet:'—',msg:'Candidat D. Carlier déplacé de « Business plan » à « Validé » (zone Tournai réservée)'},
  {ts:'2026-07-24 10:02',qui:'Système',type:'Import',projet:'—',msg:'Import CSV — performances mensuelles de juin validées pour 9 magasins'},
  {ts:'2026-07-22 09:45',qui:'M. Janssens',type:'Tâche',projet:'Renégociation contrats énergie',msg:'Tâche « Relevé contrats & échéances » livrée (2 j d\u2019avance)'},
  {ts:'2026-07-19 15:10',qui:'S. Ricci',type:'Tâche',projet:'Gamme snacking été',msg:'Tâche « Bilan test pilotes » livrée (+2 j vs échéance)'},
  {ts:'2026-07-15 09:00',qui:'CEO',type:'Pipeline',projet:'—',msg:'L. Vermeulen (Signé) converti en point de vente KNO-01 — rétroplanning d\u2019ouverture créé'},
  {ts:'2026-07-12 17:55',qui:'Système',type:'Alerte',projet:'Refonte planning équipes',msg:'Budget dépassé : 16 800 € réel vs 15 000 € prévu (+12 %)'},
  {ts:'2026-07-10 08:30',qui:'Système',type:'Rapport',projet:'—',msg:'Rapport « Trajectoire objectifs 1/3/5 ans » (T2) envoyé au CEO et au Comité'},
  {ts:'2026-07-05 11:18',qui:'CEO',type:'Objectif',projet:'—',msg:'Cible de CA réseau 2026 confirmée à 7,4 M€ après revue S1'},
  {ts:'2026-06-30 16:44',qui:'AtelierTech',type:'Tâche',projet:'Programme fidélité PWA v2',msg:'Tâche « Sprint 1 — comptes clients » livrée à temps'},
  {ts:'2026-06-18 10:25',qui:'M. Janssens',type:'Tâche',projet:'Refonte planning équipes',msg:'Tâche « Audit plannings 9 magasins » livrée (+6 j vs échéance)'},
  {ts:'2026-06-12 09:05',qui:'CEO',type:'Création',projet:'Contrôle réception ProdAtelier',msg:'Projet créé — levier Food Cost, lancement prévu 15/09'},
  {ts:'2026-05-30 14:12',qui:'ProdAtelier',type:'Tâche',projet:'Gamme snacking été',msg:'Tâche « Recettes & fiches techniques » livrée (+8 j vs échéance)'},
  {ts:'2026-05-12 12:00',qui:'CEO',type:'Jalon',projet:'Ouverture Knokke',msg:'Jalon « Signature bail » atteint avec 3 j d\u2019avance'}];
 const crm={
  p1:{gain:'Une gamme snacking propriétaire qui différencie l\u2019enseigne sur le déjeuner nomade',apport:'Panier moyen et fréquence de visite en journée',objectif:'+8 % de panier moyen sur le créneau 11h-14h en réseau',attendu:120000,realise:74000},
  p2:{gain:'Une base client identifiée et adressable, propriété du réseau',apport:'Récurrence des visites et connaissance client',objectif:'25 000 membres actifs et 32 % de visites identifiées',attendu:95000,realise:0},
  p3:{gain:'Un modèle de planning réplicable à chaque ouverture',apport:'Marge nette franchisé via le labour cost',objectif:'Labour cost ramené sous 28 % dans les 9 magasins',attendu:143000,realise:41000},
  p4:{gain:'Un pouvoir de négociation réseau sur les contrats d\u2019énergie',apport:'Baisse des charges fixes magasin',objectif:'-18 % sur la facture énergie annuelle consolidée',attendu:61000,realise:0},
  p5:{gain:'Une implantation littorale à fort trafic saisonnier',apport:'Chiffre d\u2019affaires réseau et notoriété',objectif:'Ouverture au 01/10 avec CA mensuel > 62 k€ dès M+3',attendu:210000,realise:0},
  p6:{gain:'Un process de réception homogène et opposable au fournisseur',apport:'Food cost et qualité produit',objectif:'Écarts de réception sous 1,5 % de la valeur livrée',attendu:48000,realise:0},
  p7:{gain:'Un standard d\u2019accueil mesurable, transposable aux nouvelles équipes',apport:'Expérience client et taux de transformation',objectif:'Accueil sous 3 secondes dans 90 % des visites mystère',attendu:62000,realise:58000},
  p8:{gain:'Une lecture objective du potentiel Wallonie picarde',apport:'Qualité des décisions d\u2019implantation',objectif:'Recommandation argumentée sur 3 zones prioritaires',attendu:0,realise:0}};
 const overheads={
  signatures:{T1:{par:'Laurent Mertens',date:'2026-04-08'},T2:{par:'Laurent Mertens',date:'2026-07-09'},T3:null,T4:null},
  postes:[
   {poste:'Masse salariale siège',t:[{prevu:182000,target:178000,reel:186400,pourquoi:'Recrutement expansion avancé d\u2019un mois'},{prevu:182000,target:180000,reel:181200,pourquoi:''},{prevu:186000,target:182000,reel:null,pourquoi:''},{prevu:190000,target:184000,reel:null,pourquoi:''}]},
   {poste:'Honoraires consultants',t:[{prevu:64000,target:60000,reel:58300,pourquoi:'Mission Wallonie picarde décalée'},{prevu:68000,target:64000,reel:71900,pourquoi:'Renfort audit plannings (9 magasins)'},{prevu:66000,target:62000,reel:null,pourquoi:''},{prevu:60000,target:58000,reel:null,pourquoi:''}]},
   {poste:'IT, licences & PWA',t:[{prevu:31000,target:31000,reel:29800,pourquoi:''},{prevu:38000,target:36000,reel:37400,pourquoi:''},{prevu:42000,target:39000,reel:null,pourquoi:''},{prevu:34000,target:33000,reel:null,pourquoi:''}]},
   {poste:'Marketing réseau & print',t:[{prevu:47000,target:45000,reel:52100,pourquoi:'PLV snacking étendue à 9 magasins'},{prevu:52000,target:48000,reel:49600,pourquoi:''},{prevu:58000,target:54000,reel:null,pourquoi:''},{prevu:44000,target:42000,reel:null,pourquoi:''}]},
   {poste:'Immobilier & charges siège',t:[{prevu:28000,target:28000,reel:27400,pourquoi:''},{prevu:28000,target:28000,reel:28900,pourquoi:'Régularisation charges 2025'},{prevu:29000,target:28000,reel:null,pourquoi:''},{prevu:29000,target:28000,reel:null,pourquoi:''}]},
   {poste:'Déplacements & terrain',t:[{prevu:19000,target:17000,reel:16200,pourquoi:''},{prevu:22000,target:19000,reel:23800,pourquoi:'Visites Knokke hebdomadaires'},{prevu:18000,target:17000,reel:null,pourquoi:''},{prevu:16000,target:15000,reel:null,pourquoi:''}]}]};
 const projTemplates={
  'Ventes':{jalons:[{nom:'Étude & cadrage',j:-90},{nom:'Test magasins pilotes',j:-45},{nom:'Déploiement réseau',j:0}],couts:[{poste:'Jours-homme consultants',prevu:10000},{poste:'Print & PLV',prevu:3000}]},
  'Marge nette franchisé':{jalons:[{nom:'État des lieux',j:-90},{nom:'Plan d\u2019action validé',j:-45},{nom:'Mise en œuvre réseau',j:0}],couts:[{poste:'Jours-homme consultants',prevu:8000},{poste:'Licences & outils',prevu:2000}]},
  'Développement réseau':{jalons:[{nom:'Signature bail',j:-120},{nom:'Travaux & agencement',j:-40},{nom:'Recrutement & formation',j:-10},{nom:'Ouverture',j:0}],couts:[{poste:'Agencement',prevu:30000},{poste:'Jours-homme consultants',prevu:9000},{poste:'Autres (juridique, permis)',prevu:4000}]},
  'Produit — Interne (production)':{jalons:[{nom:'Recette & fiche technique',j:-100},{nom:'Essais production',j:-60},{nom:'Test magasins pilotes',j:-30},{nom:'Lancement réseau',j:0}],couts:[{poste:'Jours-homme consultants',prevu:6000},{poste:'Essais production & matières',prevu:4000},{poste:'Print & PLV',prevu:2500}]},
  'Produit — Externe (achat)':{jalons:[{nom:'Sourcing fournisseurs',j:-100},{nom:'Appel d\u2019offres & dégustation',j:-60},{nom:'Contrat & référencement',j:-30},{nom:'Lancement réseau',j:0}],couts:[{poste:'Jours-homme consultants',prevu:5000},{poste:'Échantillons & tests',prevu:1500},{poste:'Print & PLV',prevu:2500}]}};
 const P=(id,nom,cat,vol,prix,cout,tend,mag)=>({id,nom,categorie:cat,volume:vol,prix,coutUnit:cout,tendVol:tend,magasins:mag});
 const products=[
  P('vi1','Croissant pur beurre','Viennoiserie',48200,1.35,0.47,1.04,9),
  P('vi2','Pain au chocolat','Viennoiserie',39500,1.55,0.58,1.06,9),
  P('vi3','Chausson aux pommes','Viennoiserie',12400,1.95,0.81,0.97,8),
  P('vi4','Croissant aux amandes','Viennoiserie',6100,2.60,1.22,1.12,6),
  P('vi5','Couque suisse','Viennoiserie',4300,2.20,1.15,0.91,4),
  P('pa1','Baguette tradition','Pain',41800,1.30,0.44,1.02,9),
  P('pa2','Pain au levain 500 g','Pain',18900,3.40,1.19,1.09,9),
  P('pa3','Pain multicéréales','Pain',9700,3.60,1.51,1.05,7),
  P('pa4','Pain de mie brioché','Pain',5200,3.20,1.66,0.88,5),
  P('pt1','Flan pâtissier (part,8)','Pâtisserie',14600,3.10,1.02,1.07),
  P('pt2','Éclair café','Pâtisserie',11200,3.40,1.29,0.99,8),
  P('pt3','Tarte au sucre (part,6)','Pâtisserie',8900,3.20,1.12,1.03),
  P('pt4','Merveilleux','Pâtisserie',7400,3.90,1.72,1.18,7),
  P('pt5','Cheesecake spéculoos','Pâtisserie',3600,4.50,2.34,0.94,3),
  P('sn1','Sandwich poulet curry','Snacking salé',16800,6.40,2.30,1.11,9),
  P('sn2','Quiche lorraine (part,7)','Snacking salé',9300,4.80,1.87,1.04),
  P('sn3','Focaccia mozzarella','Snacking salé',6900,5.60,2.63,1.14,5),
  P('sn4','Wrap végétarien','Snacking salé',3100,5.90,3.01,0.93,3),
  P('bo1','Café filtre','Boissons',34500,2.40,0.31,1.03,9),
  P('bo2','Cappuccino','Boissons',21700,3.30,0.62,1.08,9),
  P('bo3','Jus pressé orange','Boissons',7800,3.90,1.68,0.96,6)];
 return {stores,products,consultants,suppliers,projects,candidates,zones,kpis,targets,emailTemplates,reports,alertRules,logs,projTemplates,people,crm,overheads};
}
