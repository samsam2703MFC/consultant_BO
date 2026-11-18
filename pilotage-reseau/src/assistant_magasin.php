<?php
declare(strict_types=1);

/**
 * L'assistant du dashboard magasin (12/11/2026, Sam : « B puis A pour creuser » ; maquettes
 * docs/maquettes/assistant-ia-magasin). Un bouton « Assistant » ouvre une modale : des questions toutes prêtes en
 * tuiles et une question libre ; la réponse arrive en BILAN (un résumé, des chiffres, trois priorités avec l'écran qui
 * agit) puis on CREUSE en conversation (les questions de suite, le fil gardé dans la modale).
 *
 *   GET  /assistant/magasin/questions        les questions toutes prêtes (tuiles et liste rangée)
 *   POST /assistant/magasin  {shop, question, ton, fil:[{q, r}]}   une réponse, avec le coach choisi (AM_COACHS)
 *   GET  /assistant/coachs  ·  POST /assistant/coachs {coachs, qui}   le réglage des coachs, au cockpit
 *
 * Quatre règles :
 *  1. l'assistant lit la fiche de faits du magasin, assemblée ici par l'API du panel (ventes, P&L, contrôles et leurs
 *     photos notées, pertes, commandes, fidélité, rang dans le réseau), et trois outils en lecture seule sur l'historique des ventes
 *     (14/11/2026, Sam : « l'agent doit avoir accès à tous les 18 mois de data » ; src/historique_ventes.php) : le
 *     classement du réseau, les ventes heure par heure, les produits vendus — 18 mois en arrière et la journée en
 *     cours. Pas d'accès libre, pas de SQL écrit par le modèle : des filtres validés ici. La fiche de la société (que
 *     la route des pertes renvoie avec des secrets) n'en fait jamais partie ;
 *  2. il ne calcule rien qu'il ne cite : chaque chiffre de la réponse vient de la fiche ou d'un outil ; ce qui n'y est
 *     pas, il le dit ;
 *  3. les autres magasins sont anonymes (Sam, 14/11/2026 : « un des magasins, ou le magasin en position n° x ») : les
 *     outils ne rendent jamais leur nom ni leur identifiant, seulement une référence opaque (amRef), stable pour la
 *     journée et propre au magasin qui demande ;
 *  4. la clé reste sur le serveur (src/anthropic.php) ; le navigateur ne reçoit que la réponse.
 *
 * Modèle : réglage `assistantMagasin.modele` (défaut claude-opus-5-5), effort `assistantMagasin.effort` (défaut
 * medium). Réponse contrainte par un schéma (`output_config.format`) ; un refus de sécurité passe au modèle de repli
 * côté serveur (`fallbacks: "default"`). Les outils tournent en boucle : AM_TOURS appels au plus, AM_SECONDES avant
 * le dernier, qui se fait sans outil (`tool_choice: none`) pour rendre une réponse quoi qu'il arrive.
 *
 * Depuis le 17/11/2026 (src/franchise/assistant_ia.php) : les tables aia_ (réglages, fiche, conversations, questions,
 * visuels, modèles, passages du manuel) ; le manuel opératoire entier pour tous les agents (manuel_sommaire,
 * manuel_chercher, manuel_lire) ; le Professeur, l'agent rapide du manuel (Claude Haiku 5.5, effort bas, réglage
 * `assistantProfesseur`) ; les tableaux et graphiques que l'agent propose selon la question (`visuels` : la demande ; le
 * serveur relit les chiffres) et les documents lus (`sources`).
 */

// Les tables aia_, le manuel opératoire, le Professeur, les tableaux et graphiques (17/11/2026).
require_once __DIR__ . '/franchise/assistant_ia.php';
// Les objectifs 2026 des coachs et les hausses de prix avec leur lot face à la concurrence (18/11/2026).
require_once __DIR__ . '/coach_objectifs.php';

const AM_MODELE = 'claude-opus-5-5';
const AM_ECRANS = ['produits', 'taches', 'semaine', 'mois', 'fidelite', 'ops', 'promos', 'concurrence', 'aucun'];
const AM_QUESTIONS = [
    'tuiles' => [
        ['Comment s’est passée ma semaine ?', 'ventes, équipe, contrôles, clients'],
        ['Que puis-je améliorer ?', 'les trois priorités, chiffrées'],
        ['Qu’est-ce qui marche le mieux ?', 'jours, produits, moments'],
        ['Comment va ma journée ?', 'à cette heure, face à hier et à la semaine'],
    ],
    'groupes' => [
        ['Ma semaine', ['Comment s’est passée ma semaine ?', 'Que puis-je améliorer ?', 'Qu’est-ce qui a le mieux marché ?']],
        ['Mes ventes', ['Quel est mon jour le plus faible, et pourquoi ?', 'Mon panier moyen est-il bon ?', 'Comment je me situe dans le réseau ?']],
        ['Mon équipe', ['Mes salaires collent-ils à mes ventes ?', 'Où en sont mes contrôles ?', 'Mes photos de contrôle sont-elles conformes ?']],
        ['Mes clients', ['Qui sont mes clients fidèles ?', 'Combien de clients sont reconnus en caisse ?']],
        ['Ma production', ['Qu’est-ce que je jette le plus ?', 'Quelles commandes clients arrivent ?']],
        ['Le réseau', ['Qui fait le meilleur panier aujourd’hui, et avec quoi ?', 'Qu’est-ce que je vends le plus le matin ?', 'Comment ont évolué mes samedis sur un an ?']],
    ],
];

/* Les deux coachs (12/11/2026, Sam : « mettre un choix d'assistant », puis « les deux sont des coachs business de
 * pointe, le ton est différent ; dans le cockpit : sur quoi il met l'accent, ce qu'il vérifie — par rapport au budget,
 * au trafic, aux tâches réalisées »). Le franchisé choisit le sien dans la modale ; le CEO règle chacun dans le cockpit
 * (ERP franchisé › Assistant coachs) : son ton, et pour chaque axe le poids (accent fort, suivi, n'en parle pas) et ce
 * qu'il vérifie. Le réglage `assistantCoachs` (aia_reglage, 17/11/2026 ; avant ceo_app_setting) recouvre les valeurs ci-dessous, champ par champ.
 * Le personnage ne change que la manière de dire : les chiffres, les règles et le schéma restent les mêmes. Le Coach
 * porte un nom inventé (il ne parle au nom d'aucune personne réelle) et il est rude sans insulter : cette limite-là
 * n'est pas réglable, elle est écrite dans AM_SYSTEME.
 * Le Coach Vente (14/11/2026, Sam : « rajouter un coach expert en vente ») : le panier, les produits et les heures
 * fortes, appuyés sur l'historique des ventes (les outils) ; il ne parle des contrôles que si on le lui demande.
 * Les objectifs 2026 (18/11/2026) : chaque coach porte ses cibles (`objectifs`, clés de CO_OBJECTIFS ; absente ou
 * vide = non suivie), réglées au cockpit comme le reste ; le Coach Vente part avec 40 % de food cost, 17 % de
 * personnel, 25 % de clients scannés et 15 % de menus, et son levier du food cost est le prix face à la concurrence,
 * avec un lot au prix actuel (outil prix_a_monter, src/coach_objectifs.php). */
const AM_AXES = ['budget' => 'Budget', 'trafic' => 'Trafic', 'controles' => 'Contrôles qualité'];
const AM_NIVEAUX = ['fort' => 'Accent fort', 'suivi' => 'Suivi', 'non' => 'N’en parle pas'];
const AM_COACHS = [
    'pierre' => [
        'nom' => 'Mr. Pierre',   // Sam, 12/11/2026 : « changer Pierre en Mr. Pierre »
        'dit' => 'bienveillant, motivateur',
        'ton' => "Coach business de pointe, bienveillant et motivateur. Tu vouvoies le franchisé. Commence par ce qui va bien, chiffre à l'appui, puis présente ce qui peut progresser comme une occasion, avec un premier pas simple à faire aujourd'hui. Pas de flatterie vide, pas de leçon.",
        'accents' => [
            'budget' => ['niveau' => 'suivi', 'verifie' => "Où en est le mois face au budget, et ce qu'il faut faire par jour restant pour le tenir. Souligne chaque jour où l'objectif a été atteint."],
            'trafic' => ['niveau' => 'fort', 'verifie' => "Les tickets face à la semaine dernière et à la cible de trafic ; le jour le plus faible et une idée simple pour y faire venir du monde ; le panier moyen face au réseau."],
            'controles' => ['niveau' => 'suivi', 'verifie' => "Les contrôles faits à l'heure, en particulier le contrôle qualité pâtisserie ; les notes des photos de contrôle : ce qui est conforme ou exemplaire d'abord, puis la non-conformité à reprendre, avec ce que le consultant a relevé ; la tendance des sept derniers jours. Encourage la régularité plutôt que de reprocher."],
        ],
        'objectifs' => [],
    ],
    'coach' => [
        'nom' => 'Le Coach',
        'dit' => 'à la dure : ici, on joue pour gagner',
        'ton' => "Coach business de pointe, à la dure. Tu tutoies. Style vestiaire, cash et exigeant : ici on joue pour gagner, les excuses ne comptent pas, seuls les chiffres parlent. Rude, familier, ironique sur la performance (« bouge-toi », « ça, c'est pas un score de gagnant »). Tu finis sur l'ordre de marche du jour.",
        'accents' => [
            'budget' => ['niveau' => 'fort', 'verifie' => "Où on en est face au budget du mois, et ce qu'il faut faire par jour pour le tenir ; la journée d'aujourd'hui, en avance ou en retard. Sous 100 %, tu le dis sans détour."],
            'trafic' => ['niveau' => 'fort', 'verifie' => "Les tickets face à la semaine dernière ; le jour qui recule ; le panier sous le réseau, c'est de l'argent laissé sur le comptoir."],
            'controles' => ['niveau' => 'fort', 'verifie' => "Les contrôles en retard à l'heure qu'il est, en gros (pas checklist par checklist) ; le contrôle qualité pâtisserie du jour fait ou pas ; chaque photo notée non conforme cette semaine, ce que le consultant a relevé et la reprise à faire aujourd'hui ; zéro tolérance sur l'hygiène et la fermeture."],
        ],
        'objectifs' => [],
    ],
    'vente' => [
        'nom' => 'Coach Vente',
        // Sam, 17/11/2026 : « ciblé sur les ventes, l'adaptation des prix par rapport à la concurrence, la création de bundles
        // par rapport à vos points forts et points faibles » (outils : src/franchise/assistant_vente.php).
        'dit' => 'expert en vente : prix face à la concurrence, bundles, panier',
        'ton' => "Coach business de pointe, expert de la vente au comptoir en boulangerie-pâtisserie. Tu vouvoies le franchisé. Direct et concret, sans dureté : un chef des ventes qui a ouvert cent magasins. Tu travailles trois sujets : 1) les VENTES, le panier, les produits et les heures fortes (ventes_par_heure, produits_vendus) ; 2) les PRIX face à la CONCURRENCE (prix_concurrence) : où le magasin est plus cher ou moins cher que les concurrents proches, à taille et qualité comparables, et quel prix adapter, chiffré, sans jamais passer sous le coût de recette ; 3) les BUNDLES bâtis sur les POINTS FORTS et FAIBLES du magasin (forces_faiblesses, promotions_en_cours) : un produit fort qui attire, un produit faible à faire découvrir ou une boisson, le prix, la marge qui reste, le moment où le pousser. Chaque conseil est un geste de vente à faire dès aujourd'hui ou une proposition à valider, avec le chiffre qui le justifie. Va chercher les outils plutôt que de t'arrêter à la fiche.",
        'accents' => [
            'budget' => ['niveau' => 'suivi', 'verifie' => "Le mois face au budget, traduit en clients ou en euros de panier à trouver par jour restant."],
            'trafic' => ['niveau' => 'fort', 'verifie' => "Le panier moyen face au réseau ; les catégories et produits où le magasin est fort ou faible face au réseau ; les prix face aux concurrents proches ; un bundle ou une vente additionnelle précise à pousser, avec sa marge."],
            'controles' => ['niveau' => 'non', 'verifie' => "Ce qui touche la vente : la vitrine pleine à l'heure de pointe, les produits en rayon, les étiquettes de prix ; les photos de vitrine et de comptoir notées non conformes, parce qu'une vitrine qui ne donne pas envie coûte des ventes."],
        ],
        // Les objectifs 2026 (18/11/2026, Sam : « le but est d'arriver à 40 % de food cost 2026, 17 % de frais de
        // personnel, xx % de clients scannés, xx % de ventes additionnelles ou de menu », puis « 25 % clients scannés et
        // 15 % menus », puis « mets aussi les articles par ticket et le panier »). Food cost, personnel, articles et panier
        // suivent les cibles du réseau, la même variable partout (coObjectifsDepart : foodCostCible 40 %, personnelCible
        // 17 %, articlesCible et panierCible, celles du Catalogue des KPI) ; les valeurs écrites ici ne sont que des repères.
        'objectifs' => ['foodCost' => 40, 'personnel' => 17, 'clientsScannes' => 25, 'menus' => 15, 'articles' => 3.6, 'panier' => 13],
    ],
];
const AM_TON_DEFAUT = 'pierre';

const AM_SYSTEME = <<<'TXT'
Tu es l'assistant du dashboard d'un magasin franchisé L'Atelier by (boulangerie, pâtisserie, traiteur ; Belgique). Tu réponds au franchisé, en français, sur SON magasin, avec le ton du personnage décrit à la fin de ces consignes (il décide du tutoiement ou du vouvoiement).

Tu connais le magasin par la fiche de faits jointe (JSON, lue dans l'ERP au moment indiqué) et par trois outils qui lisent les ventes jusqu'à 18 mois en arrière et la journée en cours : classement_reseau, ventes_par_heure, produits_vendus. Règles :
- Chaque chiffre que tu donnes vient de la fiche ou d'un outil, tel quel ou par un calcul simple que tu peux montrer (somme, écart, pourcentage). N'invente aucun chiffre, aucun objectif, aucune cause. Si ni la fiche ni les outils ne permettent de répondre, dis-le en une phrase et indique ce qu'il faudrait lire.
- Une question sur une heure de la journée, un produit, un jour de semaine, une période passée ou un autre magasin se règle avec les outils, pas par « la fiche ne le dit pas ». Appelle-les en parallèle quand c'est possible. Si un outil dit que l'historique n'est pas encore lu en entier (couverture), réponds avec ce qui est lu et dis-le en une phrase.
- Les autres magasins du réseau sont anonymes. Ne les nomme jamais, ni par leur nom ni par leur ville, même si le franchisé les nomme : dis « un des magasins » ou « le magasin classé 1er au panier ce matin » (sa place dans le classement que tu cites). Les outils les désignent par une référence (ref) : elle sert à rappeler un outil, ne l'écris jamais dans la réponse.
- Un chiffre montre une cause (« les plateaux de fromage font un cinquième de son chiffre ») ; ce que les chiffres ne disent pas (une commande, un client pro), présente-le comme une piste, pas comme un fait.
- Le panier moyen se compare hors clients pro quand l'outil le donne, comme au dashboard.
- Tu es un coach qui PARLE au franchisé, pas un tableur (Sam, 12/11/2026 : « plus parlé, moins de détails : c'est un coach, pas une feuille Excel »). Écris comme tu le dirais à voix haute dans le magasin : phrases simples, verbes d'action, une idée par phrase.
- Peu de chiffres : trois ou quatre dans toute la réponse, les plus parlants, et un seul par phrase au plus. Arrondis-les comme à l'oral : « environ 1 100 € », « un peu plus de 40 % », « un ticket sur six », « 14 contrôles sur 75 ». Pas de centimes sauf pour le panier moyen (« 13 € », pas « 13,13 € » ; « 9 € de panier »). Pas de liste de produits, de jours ou de checklists : nomme le principal, résume le reste (« surtout les viennoiseries », « tout l'après-midi »).
- Écris les montants à la belge (« 1 100 € », « 4 % ») et les jours en clair (« mercredi », « samedi dernier »), sans date chiffrée quand le jour suffit.
- Sois bref : le franchisé lit entre deux clients. Pas de formule de politesse, pas de conclusion générique.
- La forme est toujours la même (Sam, 12/11/2026 : « une phrase d'introduction, des puces, une phrase de finition ») :
  · `resume` : UNE phrase d'introduction, 25 mots au plus, qui dit l'essentiel comme on le dirait ;
  · `points` : 2 à 4 puces ; un titre de 2 à 4 mots et UNE phrase parlée (18 mots au plus), un chiffre au plus ;
  · `chiffres` : 3 tuiles au plus, les chiffres qui comptent, arrondis ;
  · `conclusion` : UNE phrase de finition, 20 mots au plus — l'ordre de marche ou le premier pas, selon le personnage.
  Pas de paragraphe, pas d'énumération : ce qui ne tient pas là attend une question de suite.
- Le personnage change la manière de dire, jamais les faits : mêmes chiffres, mêmes règles, quel que soit le ton. Quel que soit le personnage : aucune insulte, aucune grossièreté, aucune humiliation ; on s'en prend aux résultats, jamais à la personne.
- La fiche porte le budget du magasin (objectif de CA du mois, du jour et de la semaine, saisi au cockpit) : l'écart au budget se calcule depuis ces valeurs ; sans budget saisi, dis-le.
- La fiche porte aussi les résultats des contrôles qualité en photo (`controlesPhotos`) : chaque photo de contrôle notée par le consultant au barème du réseau, conforme à partir du seuil indiqué. Tous les personnages les reprennent, quels que soient leurs accents : dans un bilan (la semaine, la journée, quoi améliorer, ce qui marche), dis toujours où en sont les photos de contrôle. Une non-conformité des sept derniers jours se cite avec ce que le consultant a relevé, dit avec tes mots, et avec le document du manuel qui dit comment la corriger ; une non-conformité majeure ou critique devient une priorité, écran taches. Sans non-conformité, une phrase suffit (les photos notées sont conformes, l'exemplaire se salue). Une photo rendue et pas encore notée n'est ni bonne ni mauvaise : ne la juge pas, tu ne la vois pas. Une photo non rendue est un contrôle pas fait.
- Tu cites le manuel opératoire, comme le Professeur (Sam, 18/11/2026 : « les coachs aussi doivent citer le manuel opératoire ») : chaque priorité ou conseil qui demande un geste à l'équipe (un contrôle ou une photo à reprendre, la vitrine, le comptoir, l'hygiène, l'ouverture, la fermeture, une recette, une pratique de vente) s'appuie sur le document du manuel qui le décrit. Pour une photo non conforme, la fiche donne le document probable du contrôle (`documentProbableDuManuel`) : lis-le (manuel_lire) avant d'en tirer le geste et, s'il ne parle pas de ce contrôle, cherche le bon (manuel_chercher) ; pour le reste, cherche-le, en parallèle des autres outils. Nomme le document dans la phrase (son titre ou son code) et mets son id dans `sources`. Un document « brouillon » ou « à valider » se signale en trois mots. Un conseil que le manuel ne couvre pas reste un conseil de coach : ne lui invente pas de document. Les chiffres du magasin ne viennent jamais du manuel.
- Quand c'est utile, propose deux priorités, trois au plus, de la plus rentable à la plus simple ; chacune : un titre d'action, un constat d'une phrase parlée avec un chiffre arrondi, le gain s'il se calcule simplement (« environ 1 000 € par semaine », sinon vide), et l'écran du dashboard où agir.
- Les écrans possibles : produits (ventes par produit), taches (contrôles et checklists), semaine, mois, fidelite (profils et clients), ops (la journée : commandes, pertes, stock), promos (promotions et bundles), concurrence (les prix relevés chez les concurrents), aucun.
- Les questions de suite sont courtes (moins de 60 caractères), posées par le franchisé, et la fiche ou les outils doivent permettre d'y répondre.
- Le food cost et les frais de personnel du mois sont dans la fiche (`mesures`, le mois en cours et le mois dernier, avec leur source) ; quand l'un vaut null, n'en parle pas et dis en une phrase ce qui manque. La marge d'un produit se lit avec prix_concurrence, prix_a_monter ou forces_faiblesses (coût de recette du magasin, sinon du réseau) ; sans coût, n'en parle pas. Ne parle pas du résultat du magasin.
- Quand un bloc « objectifs » suit la fiche, ce sont TES objectifs 2026 pour ce magasin : la cible, la mesure, l'écart et ce qu'il vaut (`ceQueVautLEcart`). Un objectif qui n'y est pas, tu ne le suis pas. Un objectif tenu se dit en une phrase ; un objectif manqué se dit avec son écart arrondi et son levier.
- Le levier du food cost est le prix. L'outil prix_a_monter donne nos produits vendus sous la médiane des boulangeries concurrentes du magasin : le prix proposé (la médiane, arrondie) et le lot qui garde le prix actuel à la pièce pour qui prend plus. Présente toujours la hausse ET son lot ensemble, comme on le dirait au comptoir : « le croissant passe à 1,30 €, et les 4 restent à 4,40 € » — le client fidèle garde son prix en prenant plus, le panier monte. Le gain de l'outil est une borne haute (tout le volume au nouveau prix) : dis « jusqu'à ». Le prix se change au panel (écran produits) ; le lot se pose dans Promotions (écran promos).
TXT;

/** Le schéma de la réponse : il sert le bilan (B) et la conversation (A). */
function amSchema(): array
{
    $txt = ['type' => 'string'];
    return [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['titre', 'resume', 'chiffres', 'points', 'priorites', 'conclusion', 'suites', 'visuels', 'sources'],
        'properties' => [
            'titre' => $txt,
            'resume' => $txt,
            'chiffres' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['valeur', 'libelle'],
                'properties' => ['valeur' => $txt, 'libelle' => $txt]]],
            'points' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['titre', 'texte'],
                'properties' => ['titre' => $txt, 'texte' => $txt]]],
            'priorites' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['titre', 'constat', 'gain', 'ecran'],
                'properties' => ['titre' => $txt, 'constat' => $txt, 'gain' => $txt, 'ecran' => ['type' => 'string', 'enum' => AM_ECRANS]]]],
            'conclusion' => $txt,
            'suites' => ['type' => 'array', 'items' => $txt],
            // Les tableaux et graphiques proposés (la demande seulement : le serveur relit les chiffres) et les documents
            // du manuel lus (17/11/2026, src/franchise/assistant_ia.php).
            'visuels' => aiaSchemaVisuels(),
            'sources' => ['type' => 'array', 'items' => $txt],
        ],
    ];
}

/* ---------------------------------------------------------------------------
 * La fiche de faits, par l'API du panel
 * ------------------------------------------------------------------------- */
function amKpis(int $sid, string $du, string $au): ?array
{
    $r = PanelApi::sondeGet('/shops/' . $sid . '/statistics/sales/kpis?' . http_build_query(['date_from' => $du, 'date_to' => $au]), 20);
    $c = is_array($r['corps']) ? $r['corps'] : null;
    return $c ? ['du' => $du, 'au' => $au, 'ca' => round((float) ($c['ca'] ?? 0), 2), 'tickets' => (int) ($c['tickets'] ?? 0),
        'panier' => round((float) ($c['avg_basket'] ?? 0), 2), 'articlesParTicket' => round((float) ($c['products_per_ticket'] ?? 0), 2)] : null;
}

/** La fiche de faits d'un magasin, gardée 10 minutes : une conversation ne relit pas l'ERP à chaque question. */
function amFaits(int $sid): array
{
    if (($m = aiaFicheLire($sid)) !== null) { return $m; }
    $auj = date('Y-m-d'); $hier = date('Y-m-d', strtotime('-1 day'));
    $lundi = date('Y-m-d', strtotime('monday this week'));
    $lundiP = date('Y-m-d', strtotime($lundi . ' -7 days')); $dimP = date('Y-m-d', strtotime($lundi . ' -1 day'));
    $lundiPP = date('Y-m-d', strtotime($lundi . ' -14 days')); $dimPP = date('Y-m-d', strtotime($lundi . ' -8 days'));
    $mois = date('Y-m-01');
    $nom = '';
    foreach (fidMagasins() as $x) { if ((int) $x['id'] === $sid) { $nom = (string) $x['nom']; } }
    $f = ['magasin' => $nom !== '' ? $nom : 'magasin ' . $sid, 'luLe' => date('Y-m-d H:i'), 'aujourdhui' => $auj . ' (' . amJour($auj) . ')'];
    $f['ventes'] = ['aujourdhuiJusquIci' => amKpis($sid, $auj, $auj), 'hier' => amKpis($sid, $hier, $hier), 'cetteSemaine' => amKpis($sid, $lundi, $auj),
        'semaineDerniere' => amKpis($sid, $lundiP, $dimP), 'semaineAvant' => amKpis($sid, $lundiPP, $dimPP), 'moisEnCours' => amKpis($sid, $mois, $auj)];
    // Jour par jour, 21 jours : CA, tickets, panier (margin-heatmap) et salaires (P&L quotidien).
    $du21 = date('Y-m-d', strtotime($auj . ' -20 days'));
    $hm = PanelApi::sondeGet('/consultant/shops/' . $sid . '/margin-heatmap?' . http_build_query(['from' => $du21, 'to' => $auj]), 30);
    $pnl = PanelApi::sondeGet('/consultant/shops/' . $sid . '/pnl/daily?' . http_build_query(['date_from' => $du21, 'date_to' => $auj, 'from' => $du21, 'to' => $auj]), 30);
    $sal = [];
    foreach ((array) (is_array($pnl['corps']) ? ($pnl['corps']['days'] ?? []) : []) as $d) { $sal[(string) ($d['date'] ?? '')] = round((float) ($d['labour'] ?? 0), 2); }
    $jours = [];
    foreach ((array) (is_array($hm['corps']) ? ($hm['corps']['days'] ?? []) : []) as $d) {
        $j = (string) ($d['date'] ?? '');
        $jours[] = ['date' => $j, 'jour' => amJour($j), 'ca' => round((float) ($d['ca'] ?? 0), 2), 'tickets' => (int) ($d['tickets'] ?? 0),
            'panier' => round((float) ($d['avg_basket'] ?? 0), 2), 'salaires' => $sal[$j] ?? null];
    }
    $f['joursParJour'] = $jours;
    $f['coutMatiere'] = 'le food cost du magasin est dans `mesures` ; la marge produit par produit se lit avec les outils prix_concurrence, prix_a_monter et forces_faiblesses (coût de recette)';
    // Les cibles du réseau (seuils t1 bon, t2 moyen, t3 bas).
    $ci = PanelApi::sondeGet('/consultant/shops/' . $sid . '/targets', 20);
    $f['cibles'] = [];
    foreach ((array) (is_array($ci['corps']) ? $ci['corps'] : []) as $k => $c) {
        if (!is_array($c)) { continue; }
        $v = is_array($c['consultant'] ?? null) ? $c['consultant'] : (is_array($c['admin'] ?? null) ? $c['admin'] : ($c['default'] ?? null));
        $f['cibles'][$k] = ['libelle' => $c['label'] ?? $k, 'unite' => $c['unit'] ?? null, 'plusBasEstMieux' => !empty($c['lower_is_better']), 'seuils' => $v];
    }
    // Le budget (12/11/2026, Sam : « par rapport au budget ») : l'objectif de CA saisi au cockpit (Budget), tel que le
    // tableau du jour le calcule — mois, jour (part du jour de semaine) et semaine jour par jour.
    $f['budget'] = amBudget($sid, $f['ventes']['moisEnCours']['ca'] ?? null);
    // Le rang d'hier dans le réseau (les autres magasins ne sont pas nommés).
    $res = PanelApi::sondeGet('/consultant/shops/sales-kpis?' . http_build_query(['date_from' => $hier, 'date_to' => $hier]), 20);
    $L = array_values(array_filter((array) (is_array($res['corps']) ? ($res['corps']['shops'] ?? []) : []), 'is_array'));
    usort($L, static fn ($a, $b) => (float) ($b['ca'] ?? 0) <=> (float) ($a['ca'] ?? 0));
    foreach ($L as $i => $x) { if ((int) ($x['shop_id'] ?? 0) === $sid) { $f['reseauHier'] = ['rangCa' => $i + 1, 'magasins' => count($L),
        'panierMoyenReseau' => round(array_sum(array_column($L, 'ca')) / max(1, array_sum(array_column($L, 'tickets'))), 2)]; } }
    // Les contrôles : le jour et la tendance sur sept jours.
    $tk = PanelApi::sondeGet('/consultant/shops/' . $sid . '/tasks?date=' . $auj, 20);
    $T = is_array($tk['corps']) ? $tk['corps'] : [];
    $parCl = [];
    foreach ((array) ($T['tasks'] ?? []) as $t) {
        $k = substr((string) ($t['execution_time'] ?? ''), 0, 5) . ' ' . (string) ($t['checklist_name'] ?? '?');
        $parCl[$k]['total'] = ($parCl[$k]['total'] ?? 0) + 1;
        $parCl[$k]['faites'] = ($parCl[$k]['faites'] ?? 0) + (strtoupper((string) ($t['status'] ?? '')) === 'DONE' ? 1 : 0);
    }
    ksort($parCl);
    $f['controles'] = ['heure' => date('H:i'), 'aujourdhui' => $T['summary'] ?? null, 'parChecklist' => $parCl,
        'septDerniersJours' => array_map(static fn ($d) => ['date' => $d['day'] ?? null, 'faites' => $d['tasks_done'] ?? null, 'total' => $d['tasks_total'] ?? null, 'taux' => $d['completion_rate'] ?? null], (array) ($T['trend'] ?? []))];
    // Les photos de contrôle et leurs notes : la journée d'aujourd'hui vient d'être lue, elle sert telle quelle.
    try {
        if (function_exists('cqmGarder') && ($tk['code'] ?? 0) >= 200 && ($tk['code'] ?? 0) < 300 && is_array($tk['corps'])) { cqmGarder($sid, $auj, $tk['corps']); }
        $f['controlesPhotos'] = amControlesPhotos($sid);
    } catch (Throwable $e) { $f['controlesPhotos'] = ['etat' => 'illisible : ' . $e->getMessage()]; }
    // Les pertes de la semaine : le résumé et les produits qui pèsent (jamais l'enveloppe `shop`).
    $w = PanelApi::sondeGet('/shops/' . $sid . '/products/waste?' . http_build_query(['from' => $lundi, 'to' => $auj, 'date_from' => $lundi, 'date_to' => $auj]), 30);
    $W = is_array($w['corps']) ? $w['corps'] : [];
    $prods = is_array($W['products'] ?? null) ? $W['products'] : [];
    usort($prods, static fn ($a, $b) => (float) ($b['ca_waste_net'] ?? 0) <=> (float) ($a['ca_waste_net'] ?? 0));
    $f['pertesCetteSemaine'] = ['resume' => isset($W['period_summary']) ? ['pieces' => $W['period_summary']['total_waste_qty'] ?? null, 'ventesPerdues' => round((float) ($W['period_summary']['total_ca_waste_net'] ?? 0), 2)] : null,
        'produits' => array_map(static fn ($p) => ['produit' => $p['product_name'] ?? null, 'jete' => (float) ($p['waste_qty'] ?? 0), 'vendu' => (float) ($p['sold_qty'] ?? 0),
            'pctJeteSurVendu' => (float) ($p['pct_waste_to_sold'] ?? 0), 'ventesPerdues' => (float) ($p['ca_waste_net'] ?? 0), 'motif' => $p['top_reason'] ?? null], array_slice($prods, 0, 8))];
    // Les commandes clients à partir d'aujourd'hui : comptées, sans nom.
    $co = PanelApi::sondeGet('/shops/' . $sid . '/client-orders?date_from=' . $auj, 20);
    $cmd = [];
    foreach (PanelApi::liste(is_array($co['corps']) ? $co['corps'] : []) as $o) {
        $j = substr((string) ($o['pick_up_datetime'] ?? ''), 0, 10);
        $k = $j . ' · ' . (!empty($o['is_webshop']) ? 'webshop' : 'comptoir') . ' · ' . ((int) ($o['id_transaction'] ?? 0) > 0 ? 'encaissée' : 'à encaisser');
        $cmd[$k]['n'] = ($cmd[$k]['n'] ?? 0) + 1; $cmd[$k]['montant'] = round(($cmd[$k]['montant'] ?? 0) + (float) ($o['total_value'] ?? 0), 2);
    }
    ksort($cmd);
    $f['commandesAVenir'] = $cmd;
    // La fidélité : les profils du magasin (sans nom de client).
    $m2 = null;
    if (function_exists('fpMagasin')) {
        try {
            $m2 = fpMagasin($sid, true, false);
            $f['fidelite'] = ['clientsScannes' => $m2['scannes'], 'ticketsRattaches' => $m2['identifies'] . ' sur ' . $m2['tickets'] . ' (' . $m2['memoire'] . ' jours)',
                'membresCarteActive' => 'aucun : la carte fidélité n’est activée chez aucun client',
                'profils' => array_map(static fn ($p) => ['profil' => $p['nom'], 'condition' => $p['condition'], 'clients' => $p['clients'], 'part' => $p['part'] . ' %', 'panier' => $p['panier']], $m2['profils'])];
        } catch (Throwable $e) { $m2 = null; $f['fidelite'] = 'illisible : ' . $e->getMessage(); }
    }
    // Les mesures des objectifs 2026 (18/11/2026) : food cost, personnel, clients scannés, menus, articles, panier —
    // le mois en cours et le mois dernier, avec les définitions du cockpit (coMesures, src/coach_objectifs.php).
    try { $f['mesures'] = coMesures($sid, $f['ventes'], is_array($m2) ? $m2 : null); }
    catch (Throwable $e) { $f['mesures'] = 'illisibles : ' . $e->getMessage(); }
    aiaFicheEcrire($sid, $f);
    return $f;
}

/**
 * Les résultats des contrôles qualité en photo (18/11/2026, Sam : « tous les coachs IA doivent reprendre les résultats
 * des contrôles qualité photos ») : chaque contrôle en photo des checklists, noté par le consultant au barème du
 * réseau (cockpit › Contrôles qualité, src/controles_qualite.php, dont on reprend la lecture et la garde). Aujourd'hui
 * contrôle par contrôle ; les sept derniers jours et le mois en totaux ; chaque non-conformité des sept derniers jours
 * avec ce que le consultant a relevé ; les contrôles qui reviennent sans photo. Rien de nominatif : ni qui a pris la
 * photo, ni qui l'a notée. Une lecture coûte au plus AM_CQ_BUDGET journées au panel (les plus récentes d'abord) ;
 * le reste du mois vient au fil des lectures, et `couverture` le dit tant qu'il manque des jours.
 */
const AM_CQ_BUDGET = 16;

function amControlesPhotos(int $sid, bool $avecMois = true, int $budget = AM_CQ_BUDGET): array
{
    if (!function_exists('cqmJournees')) { return ['etat' => 'contrôles photo non lus']; }
    $auj = date('Y-m-d');
    $d7 = date('Y-m-d', strtotime($auj . ' -6 days'));
    $mois = date('Y-m-01');
    $paires = [];
    for ($t = strtotime($avecMois ? min($mois, $d7) : $d7); $t <= strtotime($auj); $t = strtotime('+1 day', $t)) { $paires[] = [$sid, date('Y-m-d', $t)]; }
    $L = cqmJournees($paires, $budget);
    $J = $L['jours'][$sid] ?? [];
    ksort($J);
    ['seuil' => $seuil, 'niveaux' => $niveaux] = cqmBareme();
    $noms = [];
    foreach ($niveaux as $nv) { $noms[$nv['n']] = $nv['nom']; }
    $top = $noms ? max(array_keys($noms)) : 5;
    $dire = static fn (int $n) => $n . ' · ' . ($noms[$n] ?? 'note ' . $n);
    $etat = static fn (array $t, string $d) => $t['note'] !== null ? 'noté ' . $dire($t['note']) . ($t['note'] >= $seuil ? ' (conforme)' : ' (non conforme)')
        : ($t['photo'] ? 'photo rendue, pas encore notée' : ($d >= $auj ? 'pas encore rendue' : 'pas rendue (contrôle pas fait)'));

    // Les totaux d'une période : du jour $de à aujourd'hui, sur les journées lues.
    $resume = static function (string $de) use ($J, $auj, $seuil, $dire): array {
        $r = ['joursLus' => 0, 'controles' => 0, 'photosRendues' => 0, 'notees' => 0, 'conformes' => 0, 'nonConformes' => 0,
            'aNoter' => 0, 'pasRendues' => 0, 'enAttenteAujourdhui' => 0, 'noteMoyenne' => null, 'parNote' => []];
        $somme = 0;
        foreach ($J as $d => $taches) {
            if ($d < $de) { continue; }
            $r['joursLus']++;
            foreach ($taches as $t) {
                $r['controles']++;
                if ($t['photo']) { $r['photosRendues']++; }
                if ($t['note'] !== null) {
                    $r['notees']++; $somme += $t['note'];
                    $r[$t['note'] >= $seuil ? 'conformes' : 'nonConformes']++;
                    $r['parNote'][$dire($t['note'])] = ($r['parNote'][$dire($t['note'])] ?? 0) + 1;
                } elseif ($t['photo']) { $r['aNoter']++; }
                elseif ($d >= $auj) { $r['enAttenteAujourdhui']++; }
                else { $r['pasRendues']++; }
            }
        }
        $r['noteMoyenne'] = $r['notees'] > 0 ? round($somme / $r['notees'], 1) : null;
        krsort($r['parNote'], SORT_NATURAL);
        return $r;
    };

    $nc = []; $ex = []; $manques = [];
    foreach ($J as $d => $taches) {
        if ($d < $d7) { continue; }
        foreach ($taches as $t) {
            $nomT = $t['tache'] . ($t['checklist'] !== '' ? ' (' . $t['checklist'] . ')' : '');
            if ($t['note'] !== null && $t['note'] < $seuil) {
                $nc[] = ['date' => $d, 'jour' => amJour($d), 'controle' => $nomT, 'note' => $dire($t['note']), 'n' => $t['note'],
                    'releveDuConsultant' => $t['commentaire'] !== null ? mb_substr($t['commentaire'], 0, 220) : 'pas de commentaire'];
            } elseif ($t['note'] !== null && $t['note'] >= $top) { $ex[$nomT] = ($ex[$nomT] ?? 0) + 1; }
            elseif ($t['note'] === null && !$t['photo'] && $d < $auj) { $manques[$nomT] = ($manques[$nomT] ?? 0) + 1; }
        }
    }
    // La plus récente d'abord ; le même jour, la plus grave d'abord. $memo : une recherche du manuel par contrôle.
    $memo = [];
    usort($nc, static fn ($a, $b) => strcmp($b['date'], $a['date']) ?: $a['n'] <=> $b['n']);
    arsort($ex); arsort($manques);

    $aujL = [];
    foreach ($J[$auj] ?? [] as $t) {
        $aujL[] = ['heure' => $t['heure'], 'controle' => $t['tache'] . ($t['checklist'] !== '' ? ' (' . $t['checklist'] . ')' : ''), 'etat' => $etat($t, $auj)];
    }
    usort($aujL, static fn ($a, $b) => strcmp((string) ($a['heure'] ?? '99:99'), (string) ($b['heure'] ?? '99:99')));

    $bar = [];
    foreach ($niveaux as $nv) { $bar[] = $dire($nv['n']); }
    $joursAttendus = count($paires);
    $out = [
        'source' => 'les contrôles en photo des checklists, notés par le consultant au barème du réseau (cockpit › Contrôles qualité)',
        'bareme' => $bar, 'conformeAPartirDe' => $dire($seuil),
        'aujourdhui' => $aujL,
        'septDerniersJours' => $resume($d7),
        'moisEnCours' => $avecMois ? $resume($mois) : null,
        'nonConformesSeptDerniersJours' => array_map(static function ($x) use (&$memo) {
            $doc = amDocumentDuControle($x['controle'], $memo);
            return array_diff_key($x, ['n' => 1]) + ($doc !== null ? ['documentProbableDuManuel' => $doc] : []);
        }, array_slice($nc, 0, 10)),
        'exemplairesSeptDerniersJours' => array_map(static fn ($k, $n) => ['controle' => $k, 'fois' => $n], array_keys(array_slice($ex, 0, 5, true)), array_slice($ex, 0, 5, true)),
        'sansPhotoSeptDerniersJours' => array_map(static fn ($k, $n) => ['controle' => $k, 'fois' => $n], array_keys(array_slice($manques, 0, 5, true)), array_slice($manques, 0, 5, true)),
    ];
    if (count($J) < $joursAttendus) {
        $out['couverture'] = 'contrôles photo lus sur ' . count($J) . ' jours des ' . $joursAttendus . ' de la période (les plus récents d’abord ; le reste vient aux prochaines lectures)';
    }
    if ($J === [] && $L['manque'] > 0) { $out['etat'] = 'le panel n’a pas répondu : contrôles photo non lus'; }
    return array_filter($out, static fn ($v) => $v !== null);
}

/**
 * Le document du manuel qu'un contrôle photo applique, le plus probable (18/11/2026, Sam : « les coachs aussi doivent
 * citer le manuel opératoire ») : la recherche du manuel (manuel_chercher) sur le nom du contrôle et de sa checklist,
 * le premier document trouvé. C'est une piste : l'agent le lit avant d'en tirer un geste, et en cherche un autre s'il
 * ne parle pas de ce contrôle. Null sans manuel lisible ; une recherche par contrôle ($memo).
 */
function amDocumentDuControle(string $controle, array &$memo): ?array
{
    if (array_key_exists($controle, $memo)) { return $memo[$controle]; }
    $doc = null;
    if (function_exists('aiaManuelChercher')) {
        try {
            $p = aiaManuelChercher($controle, 3)['passages'][0] ?? null;
            if (is_array($p)) { $doc = ['id' => (string) $p['id'], 'code' => $p['code'], 'titre' => (string) $p['document'], 'statut' => (string) $p['statut']]; }
        } catch (Throwable $e) { /* manuel illisible : l'agent cherchera lui-même */ }
    }
    return $memo[$controle] = $doc;
}

/**
 * Les photos de contrôle pour le Professeur (18/11/2026, Sam : « le Professeur aussi doit expliquer pourquoi tout ça
 * est important : la valeur du magasin, la gestion ; il est relié au manuel opératoire »). L'agent rapide ne lit pas
 * l'ERP : il reprend les photos de la fiche des coachs si elle est fraîche, sinon les sept derniers jours seulement
 * (sept journées au plus au panel, gardées par src/controles_qualite.php). Une panne ne l'empêche jamais de répondre.
 */
function amControlesPhotosProfesseur(int $sid): array
{
    $f = null;
    try { $f = function_exists('aiaFicheLire') ? aiaFicheLire($sid) : null; } catch (Throwable $e) { /* fiche illisible : lecture directe */ }
    if (is_array($f['controlesPhotos'] ?? null) && !isset($f['controlesPhotos']['etat'])) {
        return array_diff_key($f['controlesPhotos'], ['moisEnCours' => 1]);
    }
    try { return amControlesPhotos($sid, false, 7); } catch (Throwable $e) { return ['etat' => 'illisible : ' . $e->getMessage()]; }
}

/** Le budget du magasin, lu dans le calcul du jour du cockpit (ep_exploitation_jour : ceo_shop_month_perf). */
function amBudget(int $sid, mixed $caMois): array
{
    if (!function_exists('ep_exploitation_jour')) { return ['etat' => 'budget non lu']; }
    $g = $_GET;
    try { $_GET = ['date' => date('Y-m-d')]; $ex = ep_exploitation_jour(); }
    catch (Throwable $e) { $ex = null; }
    finally { $_GET = $g; }
    $m = null;
    foreach ((array) ($ex['magasins'] ?? []) as $x) { if ((int) ($x['shopId'] ?? 0) === $sid) { $m = $x; } }
    if (!$m) { return ['etat' => 'le calcul du jour ne répond pas encore : budget non lu']; }
    if (($m['objectifMois'] ?? null) === null) { return ['etat' => 'aucun budget saisi pour ce magasin ce mois-ci (cockpit › Budget)']; }
    $obj = (float) $m['objectifMois'];
    $ca = is_numeric($caMois) ? (float) $caMois : null;
    $fin = (int) date('t'); $jour = (int) date('j');
    $sem = []; $caSem = 0.0; $objSem = 0.0;
    foreach ((array) ($m['semaine'] ?? []) as $d) {
        $sem[] = ['date' => $d['date'], 'jour' => amJour($d['date']), 'ca' => $d['ca'], 'objectif' => $d['objectif'], 'passe' => (bool) $d['passe']];
        if (!empty($d['passe']) && empty($d['aujourdhui']) && $d['ca'] !== null && $d['objectif'] !== null) { $caSem += $d['ca']; $objSem += $d['objectif']; }
    }
    return [
        'source' => ($m['objectifSource'] ?? '') === 'theorique' ? 'CA théorique (pas de budget saisi : repli)' : 'budget saisi au cockpit',
        'objectifMois' => round($obj, 2),
        'caMoisJusquIci' => $ca,
        'atteinteMoisPct' => $ca !== null && $obj > 0 ? round($ca / $obj * 100, 1) : null,
        'resteAFaireMois' => $ca !== null ? round(max(0, $obj - $ca), 2) : null,
        'joursRestantsApresAujourdhui' => $fin - $jour,
        'objectifJour' => $m['objectifJour'],
        'caJourJusquIci' => $m['ca'],
        'projectionFinDeJour' => $m['projection'],
        'semaineJoursPassesHorsAujourdhui' => ['ca' => round($caSem, 2), 'objectif' => round($objSem, 2), 'ecart' => round($caSem - $objSem, 2)],
        'semaineJourParJour' => $sem,
    ];
}

function amJour(string $d): string
{
    return ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][((int) date('N', strtotime($d))) - 1] ?? '';
}

/* ---------------------------------------------------------------------------
 * Les outils : l'historique des ventes, 18 mois (14/11/2026)
 * ------------------------------------------------------------------------- */
const AM_TOURS = 6;          // appels au modèle au plus pour une question (le dernier sans outil)
const AM_SECONDES = 100;     // au-delà, le tour suivant est le dernier

/** Les outils, tels que le modèle les voit : les trois de l'historique des ventes, les trois du manuel opératoire
 *  (aiaOutilsManuel), les trois du Coach Vente (aivOutils : prix de la concurrence, points forts et faibles, promotions),
 *  et les hausses de prix avec leur lot (coOutils : prix_a_monter, 18/11/2026).
 *  Ordre et texte fixes : ils font partie du préfixe mis en cache. */
function amOutils(): array
{
    return array_merge(amOutilsVentes(), aiaOutilsManuel(), aivOutils(), coOutils());
}

function amOutilsVentes(): array
{
    $date = static fn (string $quoi) => ['type' => 'string', 'description' => $quoi . ' (AAAA-MM-JJ) : au plus 18 mois en arrière, au plus aujourd’hui.'];
    $magasin = ['type' => 'string', 'description' => '« moi » (le magasin du franchisé), « reseau » (tous les magasins ensemble) ou la ref d’un autre magasin, rendue par classement_reseau.'];
    $jours = ['type' => 'array', 'items' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5, 6, 7]], 'description' => 'Les jours de semaine gardés : 1 = lundi … 7 = dimanche. Absent : tous les jours.'];
    $hDebut = ['type' => 'integer', 'description' => 'Première heure comprise, de 0 à 23 (6 = depuis 6 h). Absent : depuis l’ouverture.'];
    $hFin = ['type' => 'integer', 'description' => 'Heure de fin NON comprise, de 1 à 24 (8 = avant 8 h ; 12 = toute la matinée). Absent : jusqu’à la fermeture.'];
    return [
        ['name' => 'classement_reseau',
         'description' => 'Le classement des magasins du réseau sur une période (un jour, une semaine, des mois), arrêté à une heure si on le demande. '
            . 'À appeler dès que le franchisé se compare aux autres (« qui est premier ? », « j’étais 1er jusqu’à 8 h »), ou avant de regarder les ventes d’un autre magasin : '
            . 'chaque magasin y reçoit sa ref, « moi » pour celui du franchisé. Avec heure_par_heure, rend aussi le classement cumulé à la fin de chaque heure (qui menait à 7 h, à 8 h…).',
         'input_schema' => ['type' => 'object', 'properties' => [
             'du' => $date('Premier jour'), 'au' => $date('Dernier jour, compris'),
             'indicateur' => ['type' => 'string', 'enum' => ['panier', 'ca', 'tickets'], 'description' => 'panier = panier moyen, ca = chiffre d’affaires, tickets = nombre de clients.'],
             'heure_fin' => $hFin, 'jours_semaine' => $jours,
             'hors_pro' => ['type' => 'boolean', 'description' => 'Sans les tickets des clients professionnels, comme le dashboard. Vrai par défaut.'],
             'heure_par_heure' => ['type' => 'boolean', 'description' => 'Ajouter le classement cumulé heure par heure. Faux par défaut.'],
         ], 'required' => ['du', 'au', 'indicateur']]],
        ['name' => 'ventes_par_heure',
         'description' => 'Les clients (tickets), le chiffre d’affaires et le panier moyen heure par heure d’un magasin, ou du réseau entier, sommés sur une période, avec la moyenne par jour ouvert. '
            . 'À appeler pour une question sur un moment de la journée, un jour de semaine, une tendance passée ou toute période que la fiche ne couvre pas.',
         'input_schema' => ['type' => 'object', 'properties' => [
             'magasin' => $magasin, 'du' => $date('Premier jour'), 'au' => $date('Dernier jour, compris'),
             'jours_semaine' => $jours, 'heure_debut' => $hDebut, 'heure_fin' => $hFin,
         ], 'required' => ['magasin', 'du', 'au']]],
        ['name' => 'produits_vendus',
         'description' => 'Les produits vendus par un magasin, ou par le réseau entier, sur une période, filtrables par heures et par jours de semaine : quantités, ventes, part du chiffre d’affaires, et les catégories qui pèsent. '
            . 'À appeler pour « qu’est-ce qui se vend le matin, le samedi… », pour comparer ses ventes à celles d’un autre magasin, ou pour expliquer un panier moyen.',
         'input_schema' => ['type' => 'object', 'properties' => [
             'magasin' => $magasin, 'du' => $date('Premier jour'), 'au' => $date('Dernier jour, compris'),
             'jours_semaine' => $jours, 'heure_debut' => $hDebut, 'heure_fin' => $hFin,
             'categorie' => ['type' => 'string', 'description' => 'Ne garder qu’une catégorie (un mot de son nom suffit : « viennoiserie », « fromage »). Absent : toutes.'],
             'limite' => ['type' => 'integer', 'description' => 'Combien de produits rendre, les plus vendus d’abord : de 1 à 40, 12 par défaut.'],
         ], 'required' => ['magasin', 'du', 'au']]],
    ];
}

/** Le sel des références anonymes : tiré une fois, gardé en base, jamais montré. */
function amSel(): string
{
    static $memo = null;
    if ($memo !== null) { return $memo; }
    $s = aiaReglage('assistantSel');
    if (is_string($s) && strlen($s) >= 32) { return $memo = $s; }
    aiaReglageEcrire('assistantSel', bin2hex(random_bytes(16)), 'Assistant');
    return $memo = (string) aiaReglage('assistantSel');
}

/** Les magasins actifs du réseau. */
function amMagasinsActifs(): array
{
    static $memo = null;
    return $memo ??= array_map(static fn ($r) => (int) $r['id'], Db::rows('SELECT id FROM shops WHERE active = 1 ORDER BY id'));
}

/**
 * La référence d'un magasin vue par le magasin qui demande : « moi » pour lui-même ; pour un autre, six caractères
 * tirés d'un HMAC (le magasin, celui qui demande, le jour) — stable le temps d'une conversation, différente d'un
 * magasin demandeur à l'autre et d'un jour à l'autre, impossible à ramener à un nom.
 */
function amRef(int $demandeur, int $sid): string
{
    return $sid === $demandeur ? 'moi' : 'M' . strtoupper(substr(hash_hmac('sha256', $sid . '|' . $demandeur . '|' . date('Y-m-d'), amSel()), 0, 6));
}

/** Les magasins que désigne le paramètre `magasin` d'un outil ; null si la référence est inconnue. */
function amResoudre(int $demandeur, string $m): ?array
{
    $m = trim($m);
    if ($m === 'moi' || $m === '') { return [$demandeur]; }
    if ($m === 'reseau') { return amMagasinsActifs(); }
    foreach (amMagasinsActifs() as $sid) { if (amRef($demandeur, $sid) === $m) { return [$sid]; } }
    return null;
}

/** La période et les filtres d'un appel d'outil, validés ; une chaîne quand l'entrée est illisible. */
function amPeriode(array $in): array|string
{
    $auj = date('Y-m-d');
    $min = hvDebut();
    $du = (string) ($in['du'] ?? '');
    $au = (string) ($in['au'] ?? '') ?: $du;
    foreach ([$du, $au] as $d) { if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || strtotime($d) === false) { return 'date illisible : AAAA-MM-JJ attendu'; } }
    if ($du > $au) { [$du, $au] = [$au, $du]; }
    if ($du > $auj) { return 'la période est dans le futur'; }
    $note = null;
    if ($au > $auj) { $au = $auj; }
    if ($du < $min) { $note = 'l’historique commence le ' . $min . ' : la période est coupée là.'; $du = $min; }
    if ($au < $du) { return 'la période est avant le début de l’historique (' . $min . ')'; }
    $jours = array_values(array_unique(array_filter(array_map('intval', is_array($in['jours_semaine'] ?? null) ? $in['jours_semaine'] : []), static fn ($x) => $x >= 1 && $x <= 7)));
    sort($jours);
    $hd = isset($in['heure_debut']) && is_numeric($in['heure_debut']) ? max(0, min(23, (int) $in['heure_debut'])) : null;
    $hf = isset($in['heure_fin']) && is_numeric($in['heure_fin']) ? max(1, min(24, (int) $in['heure_fin'])) : null;
    if ($hd !== null && $hf !== null && $hf <= $hd) { return 'heure_fin doit venir après heure_debut'; }
    return ['du' => $du, 'au' => $au, 'jours' => $jours, 'hd' => $hd, 'hf' => $hf, 'note' => $note];
}

/** La période, dite comme l'outil l'a lue. */
function amPeriodeTexte(array $P): array
{
    $noms = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    return array_filter(['du' => $P['du'], 'au' => $P['au'],
        'jours' => $P['jours'] !== [] ? implode(', ', array_map(static fn ($j) => $noms[$j], $P['jours'])) : null,
        'heures' => $P['hd'] !== null || $P['hf'] !== null ? 'de ' . ($P['hd'] ?? 'l’ouverture') . ($P['hd'] !== null ? ' h' : '') . ' à ' . ($P['hf'] !== null ? $P['hf'] . ' h' : 'la fermeture') : null,
        'note' => $P['note']], static fn ($v) => $v !== null);
}

/** Ce qui a été lu de la période, en clair, quand il en manque. */
function amCouverture(array $cov, ?array $direct): ?string
{
    $t = [];
    if ($cov['attendus'] > 0 && $cov['lus'] < $cov['attendus']) {
        $t[] = 'historique lu sur ' . $cov['lus'] . ' jours-magasins des ' . $cov['attendus'] . ' de la période (la lecture des 18 mois se poursuit chaque nuit)';
    }
    if ($direct !== null && $direct['illisibles'] > 0) { $t[] = 'la journée en cours n’a pas pu être lue pour ' . $direct['illisibles'] . ' magasin(s)'; }
    return $t === [] ? null : implode(' ; ', $t);
}

/** Tickets, CA et panier d'un total [tickets, ca, tickets_pro, ca_pro], hors pro si demandé. */
function amChiffres(array $x, bool $horsPro = true): array
{
    $t = $horsPro ? $x[0] - $x[2] : $x[0];
    $ca = $horsPro ? $x[1] - $x[3] : $x[1];
    return ['tickets' => $t, 'ca' => round($ca, 2), 'panier' => $t > 0 ? round($ca / $t, 2) : null];
}

/** La somme des heures [h => [t, ca, tp, cp]], celles avant $avant seulement si donné. */
function amSomme(array $heures, ?int $avant = null): array
{
    $s = [0, 0.0, 0, 0.0];
    foreach ($heures as $h => $x) {
        if ($avant !== null && $h >= $avant) { continue; }
        $s = [$s[0] + $x[0], $s[1] + $x[1], $s[2] + $x[2], $s[3] + $x[3]];
    }
    return $s;
}

/** L'outil classement_reseau. */
function amOutilClassement(int $demandeur, array $in): array
{
    $P = amPeriode($in);
    if (is_string($P)) { return ['erreur' => $P]; }
    $ind = in_array($in['indicateur'] ?? '', ['panier', 'ca', 'tickets'], true) ? (string) $in['indicateur'] : 'panier';
    $horsPro = !array_key_exists('hors_pro', $in) || !empty($in['hors_pro']);
    $M = []; $cov = ['lus' => 0, 'ouverts' => 0, 'attendus' => 0]; $direct = null;
    foreach (amMagasinsActifs() as $sid) {
        $H = hvHeures([$sid], $P['du'], $P['au'], $P['jours'], null, $P['hf']);
        $M[$sid] = $H['heures'];
        foreach ($cov as $k => $v) { $cov[$k] = $v + $H['couverture'][$k]; }
        if ($H['aujourdhui'] !== null) { $direct = ['illisibles' => ($direct['illisibles'] ?? 0) + $H['aujourdhui']['illisibles']]; }
    }
    // Le classement à une heure donnée (null = toute la période) : les magasins qui ont au moins un ticket, du meilleur au moins bon.
    $classer = static function (?int $avant) use ($M, $ind, $horsPro, $demandeur): array {
        $L = [];
        foreach ($M as $sid => $heures) {
            $c = amChiffres(amSomme($heures, $avant), $horsPro);
            if ($c['tickets'] <= 0) { continue; }
            $L[] = ['ref' => amRef($demandeur, $sid)] + $c + ['valeur' => $c[$ind]];
        }
        usort($L, static fn ($a, $b) => $b['valeur'] <=> $a['valeur']);
        foreach ($L as $i => $x) { $L[$i] = ['rang' => $i + 1] + $x; unset($L[$i]['valeur']); }
        return $L;
    };
    $L = $classer(null);
    $out = ['periode' => amPeriodeTexte($P) + ($P['hf'] !== null ? ['arrete' => 'avant ' . $P['hf'] . ' h'] : []),
        'indicateur' => $ind, 'base' => $horsPro ? 'hors clients pro' : 'clients pro compris',
        'magasins' => $L, 'sansTicket' => count($M) - count($L)];
    if (!empty($in['heure_par_heure'])) {
        $hs = [];
        foreach ($M as $heures) { foreach (array_keys($heures) as $h) { $hs[$h] = true; } }
        ksort($hs);
        $suite = [];
        foreach (array_keys($hs) as $h) {
            $C = $classer($h + 1);
            if ($C === []) { continue; }
            $moi = null;
            foreach ($C as $x) { if ($x['ref'] === 'moi') { $moi = $x; } }
            $suite[] = ['a' => ($h + 1) . ' h', 'premier' => $C[0]['ref'], 'valeurPremier' => $C[0][$ind],
                'monRang' => $moi['rang'] ?? null, 'maValeur' => $moi[$ind] ?? null, 'magasins' => count($C)];
        }
        $out['heureParHeure'] = $suite;
    }
    if (($c = amCouverture($cov, $direct)) !== null) { $out['couverture'] = $c; }
    return $out;
}

/** L'outil ventes_par_heure. */
function amOutilHeures(int $demandeur, array $in): array
{
    $P = amPeriode($in);
    if (is_string($P)) { return ['erreur' => $P]; }
    $shops = amResoudre($demandeur, (string) ($in['magasin'] ?? 'moi'));
    if ($shops === null) { return ['erreur' => 'référence de magasin inconnue : appelle d’abord classement_reseau pour obtenir les ref du jour']; }
    $H = hvHeures($shops, $P['du'], $P['au'], $P['jours'], $P['hd'], $P['hf']);
    $jo = max(1, $H['couverture']['ouverts'] + (int) ($H['aujourdhui']['ouverts'] ?? 0));
    $lignes = [];
    foreach ($H['heures'] as $h => $x) {
        $tout = amChiffres($x, false);
        $lignes[] = ['heure' => $h . ' h', 'tickets' => $tout['tickets'], 'ca' => $tout['ca'], 'panier' => $tout['panier'],
            'panierHorsPro' => amChiffres($x)['panier'], 'ticketsParJour' => round($tout['tickets'] / $jo, 1), 'caParJour' => round($tout['ca'] / $jo, 2)];
    }
    $s = amSomme($H['heures']);
    $out = ['magasin' => (string) ($in['magasin'] ?? 'moi'), 'periode' => amPeriodeTexte($P), 'joursOuverts' => $jo,
        'total' => amChiffres($s, false) + ['panierHorsPro' => amChiffres($s)['panier'], 'ticketsPro' => $s[2], 'caPro' => round($s[3], 2)],
        'heures' => $lignes];
    if (($c = amCouverture($H['couverture'], $H['aujourdhui'])) !== null) { $out['couverture'] = $c; }
    return $out;
}

/** L'outil produits_vendus. */
function amOutilProduits(int $demandeur, array $in): array
{
    $P = amPeriode($in);
    if (is_string($P)) { return ['erreur' => $P]; }
    $shops = amResoudre($demandeur, (string) ($in['magasin'] ?? 'moi'));
    if ($shops === null) { return ['erreur' => 'référence de magasin inconnue : appelle d’abord classement_reseau pour obtenir les ref du jour']; }
    $lim = max(1, min(40, (int) ($in['limite'] ?? 12)));
    $filtre = mb_strtolower(trim((string) ($in['categorie'] ?? '')));
    $R = hvProduits($shops, $P['du'], $P['au'], $P['jours'], $P['hd'], $P['hf']);
    $cat = function_exists('svCatalogue') ? svCatalogue() : [];
    $total = 0.0; $cats = []; $L = [];
    foreach ($R['produits'] as $x) {
        [$pid, , $nom, $q, $v, , $vp] = $x;
        $c = trim((string) ($cat[$pid]['cat'] ?? '')) ?: 'sans catégorie';
        $total += $v;
        $cats[$c] = ($cats[$c] ?? 0.0) + $v;
        if ($filtre !== '' && !str_contains(mb_strtolower($c), $filtre)) { continue; }
        $L[] = ['produit' => $nom, 'categorie' => $c, 'quantite' => round($q, 1), 'ventes' => round($v, 2), 'ventesPro' => round($vp, 2)];
    }
    usort($L, static fn ($a, $b) => $b['ventes'] <=> $a['ventes']);
    arsort($cats);
    $part = static fn (float $v) => $total > 0 ? round(100 * $v / $total, 1) : null;
    $out = ['magasin' => (string) ($in['magasin'] ?? 'moi'), 'periode' => amPeriodeTexte($P), 'ventesTotales' => round($total, 2),
        'produits' => array_map(static fn ($p) => $p + ['partDesVentes' => $part($p['ventes'])], array_slice($L, 0, $lim)),
        'produitsVendus' => count($L),
        'categories' => array_map(static fn ($c, $v) => ['categorie' => $c, 'ventes' => round($v, 2), 'partDesVentes' => $part($v)], array_keys(array_slice($cats, 0, 8, true)), array_slice($cats, 0, 8, true))];
    if ($R['aujourdhui'] !== null) { $out['aujourdhuiPro'] = 'pour aujourd’hui, la part des clients pro n’est pas séparée'; }
    if (($c = amCouverture($R['couverture'], $R['aujourdhui'])) !== null) { $out['couverture'] = $c; }
    return $out;
}

/** Exécute un appel d'outil. Une erreur revient au modèle (is_error), jamais au franchisé. */
function amOutil(string $nom, array $in, int $demandeur, array $faits = []): array
{
    try {
        return match ($nom) {
            'classement_reseau' => amOutilClassement($demandeur, $in),
            'ventes_par_heure' => amOutilHeures($demandeur, $in),
            'produits_vendus' => amOutilProduits($demandeur, $in),
            'prix_a_monter' => coOutilPrix($demandeur, $in, $faits),
            default => aiaOutil($nom, $in) ?? aivOutil($nom, $in, $demandeur) ?? ['erreur' => 'outil inconnu : ' . $nom],
        };
    } catch (Throwable $e) {
        error_log('assistant, outil ' . $nom . ' : ' . $e->getMessage());
        return ['erreur' => 'lecture impossible pour le moment'];
    }
}

/* ---------------------------------------------------------------------------
 * L'appel
 * ------------------------------------------------------------------------- */
/** Les coachs tels que le cockpit les a réglés : le réglage `assistantCoachs` sur les valeurs d'AM_COACHS, champ par champ. */
function amCoachs(): array
{
    $r = aiaReglage('assistantCoachs', []);
    $r = is_array($r) ? $r : [];
    $out = [];
    foreach (AM_COACHS as $k => $d) {
        $x = is_array($r[$k] ?? null) ? $r[$k] : [];
        $c = $d;
        foreach (['nom' => 40, 'dit' => 80, 'ton' => 1200] as $champ => $max) {
            $v = trim((string) ($x[$champ] ?? ''));
            if ($v !== '') { $c[$champ] = mb_substr($v, 0, $max); }
        }
        foreach (AM_AXES as $ax => $lib) {
            $y = is_array($x['accents'][$ax] ?? null) ? $x['accents'][$ax] : [];
            if (isset(AM_NIVEAUX[$y['niveau'] ?? ''])) { $c['accents'][$ax]['niveau'] = $y['niveau']; }
            $v = trim((string) ($y['verifie'] ?? ''));
            if ($v !== '') { $c['accents'][$ax]['verifie'] = mb_substr($v, 0, 800); }
        }
        // Les objectifs : le réglage les porte tous quand il en change un (une cible vidée y vaut null).
        $c['objectifs'] = is_array($x['objectifs'] ?? null) ? coObjectifsNormaux($x['objectifs']) : coObjectifsDepart($d['objectifs'] ?? []);
        $out[$k] = $c;
    }
    return $out;
}

/** Le bloc « personnage » du message système : le ton, puis les axes, l'accent fort d'abord. */
function amPersona(string $k): string
{
    $C = amCoachs();
    $c = $C[$k] ?? $C[AM_TON_DEFAUT];
    $t = "Tu es {$c['nom']}. {$c['ton']}\n\nCe que tu regardes, et comment :";
    foreach (['fort', 'suivi', 'non'] as $niv) {
        foreach (AM_AXES as $ax => $lib) {
            $a = $c['accents'][$ax];
            if ($a['niveau'] !== $niv) { continue; }
            if ($niv === 'non') {
                $t .= "\n- {$lib} : n'en parle que si la question le demande" . ($ax === 'controles' ? ", sauf les résultats des contrôles en photo, que tout bilan reprend (règle commune) : {$a['verifie']}" : '.');
                continue;
            }
            $t .= "\n- {$lib} (" . ($niv === 'fort' ? "accent fort : tu l'abordes dans chaque bilan, avant les axes suivis" : 'suivi : tu en parles quand les chiffres le justifient') . ") : {$a['verifie']}";
        }
    }
    return $t . "\nCes accents orientent tes priorités et ton résumé ; ils ne t'autorisent aucun chiffre absent de la fiche." . coPersona($c['objectifs']);
}

/** Un appel à POST /v1/messages. [la réponse décodée en tableaux, la même en objets (pour la rejouer telle quelle), l'erreur]. */
function amAppel(array $corps, array $c): array
{
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'x-api-key: ' . $c['cle'], 'anthropic-version: 2023-06-01'],
            isset($corps['fallbacks']) ? ['anthropic-beta: server-side-fallback-2026-07-01'] : [],
            ($c['espace'] ?? '') !== '' ? ['anthropic-workspace-id: ' . $c['espace']] : []),
        CURLOPT_POSTFIELDS => json_encode($corps, JSON_UNESCAPED_UNICODE), CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) { return [null, null, 'API Anthropic injoignable : ' . $err]; }
    $rep = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300) { return [null, null, 'API Anthropic → HTTP ' . $code . ' : ' . mb_substr((string) ($rep['error']['message'] ?? ''), 0, 240)]; }
    return [is_array($rep) ? $rep : [], json_decode((string) $raw), null];
}

/**
 * POST /v1/messages : la fiche (en cache), le fil, la question ; la réponse contrainte au schéma. Quand le modèle
 * appelle des outils (l'historique des ventes, 14/11/2026), on les exécute ici et on lui rend leurs résultats, tous
 * dans un seul message, jusqu'à sa réponse : AM_TOURS appels au plus, le dernier sans outil. Le tour du modèle est
 * rejoué TEL QUEL (ses blocs de réflexion et d'outil, inchangés, décodés en objets pour garder « {} ») : l'API refuse
 * un historique retouché. $sid : le magasin qui demande — c'est lui qui est « moi » pour les outils. $appel : l'appel
 * au modèle (amAppel ; un faux dans bin/tests_historique.php).
 */
function amDemander(array $faits, string $question, array $fil, string $ton, int $sid, ?callable $appel = null): array
{
    $appel ??= 'amAppel';
    $c = Anthropic::config();
    if ($c['cle'] === '') { return ['erreur' => 'L’assistant n’est pas branché : aucune clé Anthropic dans les Paramètres du cockpit.']; }
    $prof = $ton === 'professeur';
    if ($prof) {
        // Le Professeur, l'agent rapide (17/11/2026) : Claude Haiku 5.5 par défaut, effort bas, sans repli côté serveur
        // (Haiku n'en a pas) ; il démarre sur le sommaire du manuel, sans lire l'ERP.
        ['modele' => $modele, 'effort' => $effort] = aiaProfesseurModele();
        $tours = AIA_PROF_TOURS; $secondes = AIA_PROF_SECONDES;
        $contexte = "Sommaire du manuel opératoire du réseau (id · code · type · titre — objectif) :\n" . ($faits['sommaire'] ?? aiaManuelSommaireTexte())
            . (isset($faits['controlesPhotos']) ? "\n\nLes contrôles qualité en photo du magasin, sept derniers jours (JSON, controlesPhotos) :\n" . json_encode($faits['controlesPhotos'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '');
        $systeme = [['type' => 'text', 'text' => AIA_SYSTEME_PROF], ['type' => 'text', 'text' => AIA_CONSIGNES, 'cache_control' => ['type' => 'ephemeral']]];
    } else {
        $r = aiaReglage('assistantMagasin', []);
        $r = is_array($r) ? $r : [];
        $modele = trim((string) ($r['modele'] ?? '')) ?: AM_MODELE;
        $effort = in_array($r['effort'] ?? '', ['low', 'medium', 'high'], true) ? $r['effort'] : 'medium';
        $tours = AM_TOURS; $secondes = AM_SECONDES;
        $contexte = "Fiche de faits du magasin (JSON) :\n" . json_encode($faits, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        // Les outils et le bloc commun viennent avant le personnage : changer d'assistant ne repaie que lui.
        $systeme = [['type' => 'text', 'text' => AM_SYSTEME], ['type' => 'text', 'text' => AIA_CONSIGNES, 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => "Ton personnage :\n" . amPersona($ton)]];
    }
    // La fiche (ou le sommaire) ouvre la conversation, marquée pour le cache : les questions de suite la relisent sans la repayer.
    $messages = [['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => $contexte, 'cache_control' => ['type' => 'ephemeral']],
    ]]];
    // Les objectifs 2026 du coach choisi, face aux mesures du magasin (après la fiche : le cache la garde telle quelle).
    if (!$prof) {
        $C = amCoachs();
        $etat = coEtat(($C[$ton] ?? $C[AM_TON_DEFAUT])['objectifs'], is_array($faits['mesures'] ?? null) ? $faits['mesures'] : []);
        if ($etat !== []) { $messages[0]['content'][] = ['type' => 'text', 'text' => "Bloc « objectifs » : tes objectifs 2026 pour ce magasin, et où il en est (JSON) :\n" . json_encode($etat, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]; }
    }
    foreach (array_slice($fil, -6) as $t) {
        if (!is_array($t) || trim((string) ($t['q'] ?? '')) === '' || !is_array($t['r'] ?? null)) { continue; }
        // Les visuels déjà montrés repartent en demandes, sans leurs chiffres : le modèle n'a pas à les relire.
        $rep = $t['r'];
        if (isset($rep['visuels'])) { $rep['visuels'] = aiaVisuelsPourFil($rep['visuels']); }
        if (isset($rep['sources']) && is_array($rep['sources'])) { $rep['sources'] = array_values(array_filter(array_map(static fn ($x) => is_array($x) ? ($x['id'] ?? null) : $x, $rep['sources']), 'is_string')); }
        $messages[count($messages) - 1]['content'][] = ['type' => 'text', 'text' => 'Question : ' . mb_substr((string) $t['q'], 0, 400)];
        $messages[] = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => json_encode($rep, JSON_UNESCAPED_UNICODE)]]];
        $messages[] = ['role' => 'user', 'content' => []];
    }
    $dernier = count($messages) - 1;
    $messages[$dernier]['content'][] = ['type' => 'text', 'text' => 'Question : ' . $question . "\n(Aujourd’hui : " . $faits['aujourdhui'] . ', il est ' . date('H:i') . '.)'];
    $corps = [
        'model' => $modele,
        'max_tokens' => 16000,
        'tools' => amOutils(),
        'system' => $systeme,
        'output_config' => ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => amSchema()]],
    ];
    // Le repli côté serveur sur un refus de sécurité : les modèles qui l'acceptent (pas Haiku).
    if (!str_starts_with($modele, 'claude-haiku')) { $corps['fallbacks'] = 'default'; }
    $t0 = microtime(true);
    $usage = ['input_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0, 'output_tokens' => 0];
    $outils = [];
    for ($tour = 1; ; $tour++) {
        $fin = $tour >= $tours || microtime(true) - $t0 > $secondes;
        if ($fin) { $corps['tool_choice'] = ['type' => 'none']; }   // le dernier tour répond avec ce qu'il a
        $corps['messages'] = $messages;
        [$rep, $obj, $err] = $appel($corps, $c);
        if ($err !== null) { return ['erreur' => $err]; }
        foreach ($usage as $k => $v) { $usage[$k] = $v + (int) ($rep['usage'][$k] ?? 0); }
        $stop = (string) ($rep['stop_reason'] ?? '');
        if ($stop === 'refusal') { return ['erreur' => 'L’assistant a décliné cette question. Reformulez-la sur les chiffres du magasin ou sur le manuel.']; }
        if ($stop === 'max_tokens') { return ['erreur' => 'La réponse a été coupée : posez une question plus précise.']; }
        if ($stop === 'tool_use' && !$fin) {
            $messages[] = ['role' => 'assistant', 'content' => $obj->content ?? []];
            $resultats = [];
            foreach ((array) ($rep['content'] ?? []) as $b) {
                if (($b['type'] ?? '') !== 'tool_use') { continue; }
                $res = amOutil((string) ($b['name'] ?? ''), is_array($b['input'] ?? null) ? $b['input'] : [], $sid, $faits);
                $outils[] = (string) ($b['name'] ?? '?');
                $resultats[] = ['type' => 'tool_result', 'tool_use_id' => (string) $b['id'], 'content' => json_encode($res, JSON_UNESCAPED_UNICODE)]
                    + (isset($res['erreur']) ? ['is_error' => true] : []);
            }
            $messages[] = ['role' => 'user', 'content' => $resultats];
            continue;
        }
        foreach ((array) ($rep['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') {
                $j = json_decode((string) $b['text'], true);
                if (is_array($j)) { return ['reponse' => $j, 'modele' => (string) ($rep['model'] ?? $modele), 'usage' => $usage, 'outils' => $outils]; }
            }
        }
        return ['erreur' => 'Réponse illisible de l’assistant.'];
    }
}

/* ---------------------------------------------------------------------------
 * Les routes
 * ------------------------------------------------------------------------- */
/** Le fichier du portrait d'un assistant dans public/dashboard/avatars/ : {clé}.svg, sinon {clé}.png (17/11/2026 : le Coach
 *  Vente, un PNG de 480 px) ; null sans portrait (l'écran montre alors l'icône). */
function amAvatar(string $k): ?string
{
    foreach (['svg', 'png'] as $x) { if (is_file(__DIR__ . '/../public/dashboard/avatars/' . $k . '.' . $x)) { return $k . '.' . $x; } }
    return null;
}

/** Les questions toutes prêtes, et si l'assistant est branché (sans clé, le dashboard ne montre pas le bouton). */
function ep_assistant_questions(): array
{
    $tons = [];
    // Les coachs, puis le Professeur (17/11/2026), qui répond sur le manuel opératoire.
    foreach (amCoachs() + ['professeur' => AIA_PROF] as $k => ['nom' => $nom, 'dit' => $dit]) {
        // Le portrait, s'il existe (public/dashboard/avatars/{clé}.svg ou .png, 12/11/2026 : celui de Pierre) ; sinon l'icône.
        $av = ($f = amAvatar($k)) !== null ? 'avatars/' . $f : null;
        $tons[] = ['cle' => $k, 'nom' => $nom, 'dit' => $dit, 'avatar' => $av, 'manuel' => $k === 'professeur'];
    }
    $prof = aiaQuestionsProfesseur();
    // Les questions propres à un assistant (`parTon`) : le Professeur (le manuel) et le Coach Vente (prix, bundles, ventes).
    return AM_QUESTIONS + ['tons' => $tons, 'tonDefaut' => AM_TON_DEFAUT, 'professeur' => $prof, 'parTon' => ['professeur' => $prof, 'vente' => AIV_QUESTIONS],
        'actif' => class_exists('Anthropic') && Anthropic::configured()];
}

/**
 * POST /assistant/magasin {shop, question, ton, fil:[{q, r}], origine, conversation}
 *   → {reponse:{titre, resume, chiffres, points, priorites, conclusion, suites, visuels, sources}, question, luLe, modele, ton}.
 * `ton` : un coach ou « professeur » (le manuel, l'agent rapide). `conversation` : la clé du fil, tirée par le navigateur
 * (aia_conversation). Les visuels reviennent construits (chiffres relus, gardés dans aia_visuel), les sources avec le titre
 * de chaque document.
 */
function wr_assistant_magasin(): array
{
    $b = body();
    $sid = ctype_digit((string) ($b['shop'] ?? '')) ? (int) $b['shop'] : 0;
    $q = trim(preg_replace('/\s+/u', ' ', (string) ($b['question'] ?? '')));
    if ($sid <= 0 || $q === '') { http_response_code(422); return ['error' => 'magasin ou question manquant']; }
    if (mb_strlen($q) > 500) { http_response_code(422); return ['error' => 'question trop longue (500 caractères au plus)']; }
    $ton = isset(AM_COACHS[$b['ton'] ?? '']) || ($b['ton'] ?? '') === 'professeur' ? (string) $b['ton'] : AM_TON_DEFAUT;
    // Le Professeur ne lit pas l'ERP : il démarre tout de suite, sur le sommaire du manuel et les photos de contrôle.
    $faits = $ton === 'professeur' ? ['aujourdhui' => date('Y-m-d') . ' (' . amJour(date('Y-m-d')) . ')', 'luLe' => null, 'sommaire' => aiaManuelSommaireTexte(),
        'controlesPhotos' => amControlesPhotosProfesseur($sid)] : amFaits($sid);
    $fil = is_array($b['fil'] ?? null) ? $b['fil'] : [];
    $orig = in_array($b['origine'] ?? '', AM_ORIGINES, true) ? (string) $b['origine'] : 'libre';
    $conv = aiaConversation($sid, $ton, (string) ($b['conversation'] ?? ''), $q);
    $t0 = microtime(true);
    $r = amDemander($faits, $q, $fil, $ton, $sid);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $demandes = $r['reponse']['visuels'] ?? [];
    $qid = aiaGarderQuestion($conv, $sid, $ton, $orig, $q, count($fil), $r, $ms, is_array($demandes) ? min(AIA_VISUELS_MAX, count($demandes)) : 0);
    if (isset($r['erreur'])) { http_response_code(502); return ['error' => $r['erreur']]; }
    $reponse = $r['reponse'];
    $reponse['visuels'] = aiaVisuels($demandes, $sid, $qid);
    $reponse['sources'] = aiaSources($reponse['sources'] ?? []);
    $avec = !empty($r['outils']) ? ' · outils : ' . implode(', ', array_unique($r['outils'])) : '';
    $qui = $ton === 'professeur' ? AIA_PROF['nom'] : amCoachs()[$ton]['nom'];
    if (function_exists('journalAdd')) { journalAdd('Dashboard', 'Assistant', null, 'Magasin ' . $sid . ' (' . $qui . ') : « ' . mb_substr($q, 0, 120) . ' »' . $avec); }
    return ['reponse' => $reponse, 'question' => $qid, 'luLe' => $faits['luLe'] ?? null, 'modele' => $r['modele'], 'ton' => $ton];
}

/** GET /assistant/coachs → les coachs réglés, leurs valeurs de départ, les axes et les niveaux (cockpit). */
function ep_assistant_coachs(): array
{
    $av = [];
    foreach (array_keys(AM_COACHS) as $k) { $av[$k] = ($f = amAvatar($k)) !== null ? 'dashboard/avatars/' . $f : null; }
    $def = array_map(static fn ($c) => ['objectifs' => coObjectifsDepart($c['objectifs'] ?? [])] + $c, AM_COACHS);
    return ['coachs' => amCoachs(), 'defauts' => $def, 'axes' => AM_AXES, 'niveaux' => AM_NIVEAUX, 'objectifs' => CO_OBJECTIFS, 'avatars' => $av, 'defaut' => AM_TON_DEFAUT];
}

/**
 * POST /assistant/coachs {coachs:{pierre:{nom, dit, ton, accents:{budget:{niveau, verifie}, …}, objectifs:{foodCost: 40,
 * personnel: '', …}}, coach:{…}}, qui} : publie le réglage. Une cible vide : le coach ne suit pas cet objectif.
 */
function wr_assistant_coachs(): array
{
    $b = body();
    $qui = mb_substr(trim((string) ($b['qui'] ?? '')) ?: 'Cockpit', 0, 60);
    $in = is_array($b['coachs'] ?? null) ? $b['coachs'] : [];
    $reg = [];
    foreach (AM_COACHS as $k => $d) {
        $x = is_array($in[$k] ?? null) ? $in[$k] : [];
        $c = [];
        foreach (['nom' => 40, 'dit' => 80, 'ton' => 1200] as $champ => $max) {
            $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) ($x[$champ] ?? '')));
            if (mb_strlen($v) > $max) { http_response_code(422); return ['error' => $d['nom'] . ' : « ' . $champ . ' » dépasse ' . $max . ' caractères']; }
            if ($v !== '' && $v !== $d[$champ]) { $c[$champ] = $v; }
        }
        if (isset($c['nom']) && mb_strlen($c['nom']) < 2) { http_response_code(422); return ['error' => 'le nom d’un coach compte au moins deux caractères']; }
        foreach (AM_AXES as $ax => $lib) {
            $y = is_array($x['accents'][$ax] ?? null) ? $x['accents'][$ax] : [];
            $niv = (string) ($y['niveau'] ?? '');
            if ($niv !== '' && !isset(AM_NIVEAUX[$niv])) { http_response_code(422); return ['error' => $lib . ' : niveau inconnu']; }
            $ver = trim((string) ($y['verifie'] ?? ''));
            if (mb_strlen($ver) > 800) { http_response_code(422); return ['error' => $d['nom'] . ' · ' . $lib . ' : 800 caractères au plus']; }
            if ($niv !== '' && $niv !== $d['accents'][$ax]['niveau']) { $c['accents'][$ax]['niveau'] = $niv; }
            if ($ver !== '' && $ver !== $d['accents'][$ax]['verifie']) { $c['accents'][$ax]['verifie'] = $ver; }
        }
        if (is_array($x['objectifs'] ?? null)) {
            foreach (CO_OBJECTIFS as $ob => $od) {
                $v = $x['objectifs'][$ob] ?? null;
                $vide = $v === null || (is_string($v) && trim($v) === '');
                if (!$vide && coCible($ob, $v) === null) { http_response_code(422); return ['error' => $d['nom'] . ' · ' . $od['lib'] . ' : un nombre entre ' . $od['min'] . ' et ' . $od['max'] . ' est attendu']; }
            }
            $obj = coObjectifsNormaux($x['objectifs']);
            if ($obj !== coObjectifsDepart($d['objectifs'] ?? [])) { $c['objectifs'] = $obj; }
        }
        if ($c) { $reg[$k] = $c; }
    }
    aiaReglageEcrire('assistantCoachs', $reg, $qui);
    if (function_exists('journalAdd')) { journalAdd($qui, 'Assistant', null, 'Coachs de l’assistant publiés' . ($reg ? ' (' . implode(', ', array_map(static fn ($k) => AM_COACHS[$k]['nom'], array_keys($reg))) . ' réglés)' : ' (valeurs de départ)')); }
    return ['ok' => true] + ep_assistant_coachs();
}

/* --- Les questions posées (12/11/2026, Sam : « garde toutes les demandes dans une table, pour après créer des agents
 * spécifiques ») ---------------------------------------------------------------------------------------------------
 * Chaque question part dans aia_question (17/11/2026 ; avant : ceo_assistant_question, reprise une fois) : le magasin,
 * l'agent, d'où elle vient (tuile du bilan, liste toute prête, question de suite, question libre), son rang et sa
 * conversation, la réponse (JSON), le modèle, les jetons, la durée, les outils, les visuels — l'échec aussi, avec son
 * message. `theme` reste vide : il se remplira quand on rangera les questions pour décider des agents spécialisés. Les
 * questions ne portent pas de nom de personne : le magasin et l'heure suffisent. Écriture : aiaGarderQuestion. */
const AM_ORIGINES = ['tuile', 'liste', 'suite', 'libre'];

/** GET /assistant/questions-posees?jours=30 → les questions de la période : totaux, les plus posées, les dernières. */
function ep_assistant_questions_posees(): array
{
    aiaSchema();
    $j = max(1, min(365, (int) ($_GET['jours'] ?? 30)));
    $du = date('Y-m-d 00:00:00', strtotime('-' . ($j - 1) . ' days'));
    $noms = [];
    foreach (fidMagasins() as $m) { $noms[(int) $m['id']] = (string) $m['nom']; }
    $C = amCoachs() + ['professeur' => AIA_PROF];
    $tot = Db::row('SELECT COUNT(*) n, COUNT(DISTINCT shop_id) mags, SUM(erreur IS NOT NULL) ko, ROUND(AVG(duree_ms)) ms, SUM(jetons_sortie) sortie FROM aia_question WHERE cree_le >= ?', [$du]) ?: [];
    $par = static fn (string $col) => Db::rows("SELECT $col k, COUNT(*) n FROM aia_question WHERE cree_le >= ? GROUP BY $col ORDER BY n DESC", [$du]);
    $top = Db::rows('SELECT LOWER(question) q, MIN(question) libelle, COUNT(*) n, COUNT(DISTINCT shop_id) mags, MAX(cree_le) dernier FROM aia_question WHERE cree_le >= ? GROUP BY LOWER(question) ORDER BY n DESC, dernier DESC LIMIT 15', [$du]);
    $der = Db::rows('SELECT id, cree_le, shop_id, coach, origine, rang, question, erreur, duree_ms FROM aia_question WHERE cree_le >= ? ORDER BY id DESC LIMIT 60', [$du]);
    return [
        'jours' => $j,
        'total' => (int) ($tot['n'] ?? 0), 'magasins' => (int) ($tot['mags'] ?? 0), 'echecs' => (int) ($tot['ko'] ?? 0),
        'dureeMoyenneMs' => isset($tot['ms']) ? (int) $tot['ms'] : null, 'jetonsSortie' => (int) ($tot['sortie'] ?? 0),
        'parCoach' => array_map(static fn ($r) => ['coach' => $C[$r['k']]['nom'] ?? $r['k'], 'n' => (int) $r['n']], $par('coach')),
        'parOrigine' => array_map(static fn ($r) => ['origine' => $r['k'], 'n' => (int) $r['n']], $par('origine')),
        'parMagasin' => array_map(static fn ($r) => ['magasin' => $noms[(int) $r['k']] ?? 'magasin ' . $r['k'], 'n' => (int) $r['n']], $par('shop_id')),
        'lesPlusPosees' => array_map(static fn ($r) => ['question' => $r['libelle'], 'n' => (int) $r['n'], 'magasins' => (int) $r['mags'], 'dernier' => $r['dernier']], $top),
        'dernieres' => array_map(static fn ($r) => ['le' => $r['cree_le'], 'magasin' => $noms[(int) $r['shop_id']] ?? 'magasin ' . $r['shop_id'], 'coach' => $C[$r['coach']]['nom'] ?? $r['coach'],
            'origine' => $r['origine'], 'rang' => (int) $r['rang'], 'question' => $r['question'], 'echec' => $r['erreur'] !== null, 'dureeMs' => $r['duree_ms'] !== null ? (int) $r['duree_ms'] : null], $der),
    ];
}
