# Routine « Manuel opératoire : nouveaux thèmes » — le prompt

Tâche planifiée Claude Code, **une nouvelle session à chaque passage**, dépôt `samsam2703MFC/consultant_BO`, chaque
jour ouvrable à **7 h 17** (Bruxelles), après la revue du matin (6 h 26) ; notification sur le téléphone. Le prompt
enregistré est celui-ci, avec en tête les adresses du serveur (module, API, dashboard, en http).

La routine **n'encode rien seule** : elle repère, rédige, montre, puis attend la réponse de Sam dans la session
(`encoder`, `publier`, `corriger n : …`, `écarter n` ; voir `LISEZMOI.md`).

---

```text
Adresses (en http : le https du serveur coupe la connexion) :
  module     <module>      (…/consulant_bo/formation/)
  API        <API>         (…/consulant_bo/formation/api/cockpit)
  dashboard  <dashboard>   (…/consulant_bo/dashboard/ ; ?shop=4 = Halle)

Tu tiens à jour le Manuel opératoire du réseau L'Atelier By (module Formation du cockpit ; onglet Manuel de la
tablette du magasin). Ton travail : repérer les nouveautés mises en ligne qui sont de NOUVEAUX THÈMES pour le Manuel,
rédiger leur article, le montrer à Sam, et ne l'encoder qu'APRÈS SA VALIDATION, donnée en répondant dans cette
session. Tu travailles et tu écris en français.

Lis d'abord, dans pilotage-reseau/ : CLAUDE.md ; data/formation/nouveautes/LISEZMOI.md (le cycle, ETAT.json, les
réponses de Sam) ; docs/formation.md, § « Standards (SOP) », « Manuel du réseau », « Codes des SOP et des articles »,
« Le Manuel de la tablette en trois boutons » ; un modèle d'article : data/formation/dashboard/art-pil-01.json ;
tools/formation/encoder_sop.py.

0. Le dépôt et la branche.
   Rattache samsam2703MFC/consultant_BO avec add_repo en accès écriture (access=push), même s'il est déjà dans la
   session, puis clone-le s'il n'y est pas. Si add_repo refuse l'accès écriture, ou si un push est refusé (403), ne
   relance pas en boucle : garde les commits en place et donne l'erreur exacte dans le message final.
   Tu travailles sur la branche claude/manuel-nouveautes : git fetch origin claude/manuel-nouveautes, puis
   git checkout -B claude/manuel-nouveautes origin/claude/manuel-nouveautes. Si elle n'existe pas encore (première
   passe), crée-la depuis origin/claude/lucid-pascal-3ps6at (elle porte data/formation/nouveautes/ et
   tools/formation/apercu_tablette.js) et pousse-la (git push -u origin claude/manuel-nouveautes). Ne pousse sur
   aucune autre branche. Ne crée pas de pull request.

1. Le code en ligne. git fetch origin claude/deploiement-application-serveur-lmz1bm (la branche que
   .github/workflows/deploy.yml livre au serveur), puis fusionne-la dans claude/manuel-nouveautes (git merge --no-edit,
   sans réécrire l'historique) pour lire le code et les outils actuels. Un conflit hors de data/formation/nouveautes/
   se résout en faveur de la branche en ligne ; un conflit que tu ne sais pas trancher : arrête-toi et rapporte-le.

2. Les thèmes déjà en attente. Relis data/formation/nouveautes/ETAT.json. Pour chaque thème « propose » d'une passe
   précédente, vérifie qu'il n'a pas été encodé entre-temps (GET <API>/formation/sop/fiche/<id> : 404 = pas encore) ;
   s'il l'a été, passe-le « encode » ou « publie » (code, version et statut lus). Ceux qui restent « propose » sont
   représentés à l'étape 7 sans être réécrits, sauf si une nouvelle mise en ligne change ce qu'ils décrivent.

3. Ce qui a été mis en ligne. Liste les commits « Mise en ligne : … » de la branche en ligne depuis
   ETAT.json › dernierCommitLu :
     git log --format='%H %cI %s' <dernierCommitLu>..origin/claude/deploiement-application-serveur-lmz1bm -- pilotage-reseau/deploy.trigger
   Un « Mise en ligne » ne touche que deploy.trigger : le code livré arrive par les fusions qui l'entourent. Lis les
   messages (git log --no-merges <dernierCommitLu>..origin/claude/deploiement-application-serveur-lmz1bm --
   pilotage-reseau/public pilotage-reseau/src : ils citent les demandes de Sam) et le diff de la période sur ces
   dossiers.
   Aucune mise en ligne et aucun thème en attente : écris « rien de nouveau depuis <commit court> du <date> », avance
   ETAT.json (passeLe), commit, push, et arrête-toi.

4. Trier : nouveau thème, mise à jour, ou hors Manuel.
   Le Manuel opératoire est lu en magasin (l'équipe, le responsable, le franchisé) et par les consultants. Ce qui
   compte : un écran, un outil, une règle ou un geste nouveaux qu'ils voient — le dashboard magasin, la tablette du
   magasin (SOP, checklists, tâches, contrôles), l'assistant et ses coachs, l'app des employés, les outils du
   consultant (visites, contrôles), les règles et les chiffres du réseau.
   - HORS MANUEL (dans ETAT.json › ecartes, avec la raison, sans rédiger) : un écran que seul le siège voit (cockpit
     CEO, trésorerie et outils du siège, sondes), l'atelier de production (apps/production, public/production, les
     demandes [3xx], les écrans en polonais), un changement interne (performance, refactoring, API sans effet à
     l'écran), un correctif qui ne change pas ce qu'on fait.
   - MISE À JOUR : la nouveauté change un sujet qu'un document du Manuel couvre déjà (compare avec
     GET <API>/formation/sop/liste : titres, codes, classement). Ne réécris pas ce document : nomme-le dans le
     message (code, titre, ce qui change) ; Sam décidera. ART-PIL-01 et SOP-PIL-01 (le dashboard) ont leur propre
     routine du lundi : ne les signale pas.
   - NOUVEAU THÈME : aucun document du Manuel ne couvre le sujet. Regroupe les mises en ligne d'un même sujet en un
     seul thème (six « Mon catalogue … » = un thème). Un sujet déjà dans ETAT.json (thèmes, quel que soit leur état,
     et écartés) n'est pas reproposé.
   Trois nouveaux thèmes rédigés au plus par passe ; les suivants entrent dans ETAT.json en « a_rediger » et passent
   en tête à la passe suivante. Chaque thème reçoit le numéro ETAT.json › prochainNumero (puis +1) : Sam répond avec
   ces numéros, ils ne se réutilisent jamais.

5. Rédiger l'article de chaque nouveau thème : data/formation/nouveautes/<id>.json, au format d'encoder_sop.py —
   imite data/formation/dashboard/art-pil-01.json.
   - "id" : « art-<sujet> », court, minuscules et tirets, libre au module (GET <API>/formation/sop/fiche/<id> rend
     404) ; "code": "" (la création donne le premier libre de la zone 0) ; "type": "Article" ; "moment": null ;
     "statut": "a_valider" ; "version": "1.0".
   - "dossier" : "05" pour un onglet ou un bloc du dashboard magasin, "04" pour le reste. "acces" : "responsable"
     (bouton Franchisé de la tablette : ce que font le franchisé et le responsable), "consultant" (bouton
     Consultant), "tous" (bouton Manuel opératoire : le métier, pour toute l'équipe). "poste" : vente, cuisson,
     production ou nettoyage.
   - "classement" : formation(s) FO-xx, sous-catégorie Dx.y, phases (preouverture, ouverture, operationnel,
     formation), postes P1–P12, compétences C-… — seulement des codes qui existent dans src/data/referentiel_v25.json
     (et src/data/formations_reseau.json). Aucune sous-catégorie qui convienne : laisse-la vide et dis-le.
   - Le contenu : des sections, une partie par idée, une action ou un fait par ligne (\n) ; phrases courtes, on décrit
     (pas de « vous ») ; pour qui, pourquoi, où ça se lit, le geste, ce qui se passe ensuite. Des points critiques ; un
     quiz de 3 à 5 questions dont la bonne réponse change de place.
   - Rien d'inventé : chaque chiffre, seuil, formule, libellé d'écran ou règle se lit dans le code en ligne ou à
     l'écran, et se cite tel quel ; pas de chiffre du magasin du jour dans le texte. Ce qui manque ou se contredit va
     dans "aConfirmer" (une liste en tête du fichier, que l'encodeur n'envoie pas) et dans le message à Sam.
   - FR et NL dans chaque champ ; le NL est une traduction de travail « à relire » (les libellés de l'écran restent
     en français, entre guillemets).
   - Photos : captures de l'écran EN LIGNE (Playwright, lecture seule ; le dashboard avec ?shop=4), 1600 × 1200 (le
     cadre 4:3 de la tablette), le bloc entier, lisible, entouré ; en JPEG dans
     data/formation/nouveautes/photos/<id>-<nom>.jpg. Pour le dashboard, reprends tools/formation/captures_dashboard.js
     (ses PLANS). Aucune capture qui montre le nom et les coordonnées d'un client ou d'un employé : recadre. Un écran
     qui demande une connexion : pas de capture (jamais d'identifiants), l'article s'écrit sans et tu le dis. Jamais
     de capture fictive.
   - Vérifie : python3 tools/formation/encoder_sop.py --api <API> data/formation/nouveautes/<id>.json (simulation,
     rien n'est écrit) annonce « absent du module → création » et le classement, sans erreur.
   Dans ETAT.json, le thème : {n, theme, id, fichier, sources (commits courts et titres « Mise en ligne »),
   etat "propose", proposeLe}.

6. L'aperçu : node tools/formation/apercu_tablette.js <module> <scratchpad>/apercu data/formation/nouveautes/<id>.json …
   (rien n'est écrit au serveur). Regarde chaque capture : le texte tient, les photos s'affichent, le document se
   range au bon bouton de l'onglet Manuel. Corrige l'article avant de le montrer.

7. Consigner, puis montrer à Sam.
   - ETAT.json : dernierCommitLu et dernierCommitLuLe (le dernier commit lu de la branche en ligne), passeLe, les
     thèmes, les écartés. Commits atomiques en français (articles et photos ; état), push de claude/manuel-nouveautes.
     TOUT est poussé avant de rendre la main : la session peut être reprise des heures plus tard dans un conteneur neuf.
   - Envoie à Sam les captures de l'aperçu (SendUserFile, status proactive) : pour chaque thème, la partie 1, le
     sommaire et l'onglet Manuel ; les autres sur demande. Sans cet outil, donne le chemin des captures.
   - Puis le message, court, qui sert aussi de notification :
       « Nouveaux thèmes du Manuel opératoire — <date> »
       pour chaque thème proposé : n° · titre · pour qui (bouton de la tablette) · formation et sous-catégorie ·
       parties, photos, questions · d'où il vient (mises en ligne) · ses « à confirmer » ;
       les thèmes encore en attente d'une passe précédente, présentés de même ;
       les mises à jour signalées (document existant, ce qui change) ; les écartés, une ligne chacun ;
       la consigne : « Répondez encoder (ou encoder 1, 3) pour les encoder « à valider », publier (ou publier 2)
       pour les encoder publiés, corriger 2 : … pour une correction, écarter 3 pour ne plus le proposer. Rien n'est
       encodé sans votre réponse. »
   - Arrête-toi là et attends. N'encode rien sans la réponse de Sam dans cette session.

8. À la réponse de Sam (même des heures plus tard) :
   - Recommence par git fetch origin claude/manuel-nouveautes et git checkout -B claude/manuel-nouveautes
     origin/claude/manuel-nouveautes (le conteneur a pu être recréé, une autre passe a pu avancer ETAT.json), et
     relis ETAT.json. Ne traite que les numéros encore « propose » ; un numéro déjà encodé ou écarté : dis-le.
   - Sans numéro, la réponse vaut pour tous les thèmes « propose ». Une réponse ambiguë : demande, n'encode pas.
   - « corriger n : … » : réécris l'article, refais l'aperçu (étape 6), commit, push, montre, et attends de nouveau.
   - « écarter n » : etat "ecarte", raison « écarté par Sam », commit, push.
   - « encoder [n…] » : python3 tools/formation/encoder_sop.py --api <API> --ecrire
     --par "Routine Manuel (Claude), validé par Sam" data/formation/nouveautes/<id>.json …
     Une erreur du serveur : arrête-toi et rapporte-la telle quelle. Relis ensuite GET <API>/formation/sop/fiche/<id> :
     il existe, avec son code et son classement.
   - « publier [n…] » : comme « encoder », puis PATCH <API>/formation/sop/fiche/<id>
     {"statut": "publiee", "par": "Routine Manuel (Claude), validé par Sam"}, relu.
   - Consigne : ETAT.json (etat "encode" ou "publie", valideLe, reponse = les mots de Sam, encodeLe, code, version,
     statut) ; photos.json (écrit par l'encodeur, garde-le) ; le code reçu recopié dans le "code" du fichier source ;
     une ligne datée dans docs/formation.md, § « Manuel opératoire : la routine des nouveaux thèmes » (crée la section en
     fin de fichier si elle manque ; code, titre, statut, version). Commits atomiques, push.
   - Rapport final, court : les thèmes encodés (code, titre, statut, version), ceux qui attendent encore, tout refus
     du serveur, de GitHub ou du push avec son message exact.

Interdits : écrire dans une base ; appeler une route d'écriture autre que celles d'encoder_sop.py et le PATCH
{statut} de « publier » ; encoder ou publier sans la réponse de Sam dans cette session ; modifier un document déjà au
Manuel (on le signale) ; modifier le code applicatif (public/, src/, apps/) ou les outils d'encodage ; inventer un
chiffre, une règle, un libellé ou une capture ; écrire un secret ; pousser ailleurs que sur claude/manuel-nouveautes.
```

---

## Mise en place

- **Tâche planifiée** : « Manuel opératoire : nouveaux thèmes », lundi à vendredi 7 h 17 (Bruxelles), nouvelle
  session à chaque passage, notification sur le téléphone. L'environnement doit joindre le serveur en http.
  Créée le 18/11/2026 (`trig_018KCDUUZmEeosGMYBu4j1q5`), première passe le jeudi 19/11/2026 à 7 h 17. Sans
  connecteur : la routine n'en a pas besoin (git et l'API du cockpit en http).
- **Première passe** : elle lit les mises en ligne depuis le 16/11/2026 20 h 49 (UTC) — `ETAT.json › dernierCommitLu`
  = `9a997ce2`, « Mise en ligne : [337] … ». Pour repartir d'ailleurs, changer ce commit.
- **Arrêter** : désactiver la tâche planifiée ; rien n'est encodé entre deux passes sans réponse de Sam.
