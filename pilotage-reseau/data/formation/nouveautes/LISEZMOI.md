# Nouveaux thèmes du Manuel opératoire — la source de la routine

Demande de Sam (18/11/2026) : « créer une routine pour encoder, après validation de Sam, les nouveaux thèmes dans le
Manuel opératoire ». Choix de Sam : les thèmes viennent **des nouveautés mises en ligne** ; Sam valide **en répondant
dans la session** de la routine. Le prompt de la tâche planifiée : `ROUTINE.md` (ce dossier).

## Le cycle

1. **Repérer** (chaque passe) : les « Mise en ligne : … » de la branche en ligne
   (`claude/deploiement-application-serveur-lmz1bm`, celle que `.github/workflows/deploy.yml` livre) depuis
   `ETAT.json › dernierCommitLu`. Une nouveauté qu'une équipe de magasin, un franchisé ou un consultant voit, et qu'aucun
   document du Manuel ne couvre encore (`GET /formation/sop/liste`), devient un **thème**.
2. **Rédiger** : un article par thème, `<id>.json` dans ce dossier (le format d'`encoder_sop.py`, comme
   `data/formation/dashboard/art-pil-01.json`), FR et NL de travail, classé (formation, sous-catégorie, phases,
   postes, compétences du référentiel). Photos : `photos/<id>-*.jpg`, 1600 × 1200, prises en lecture seule sur l'écran
   en ligne.
3. **Montrer** : `tools/formation/apercu_tablette.js` rend l'article sur la vraie tablette (injecté dans le navigateur,
   rien n'est écrit au serveur) ; les captures sont envoyées à Sam avec un résumé numéroté.
4. **Attendre Sam** : rien n'est encodé sans sa réponse dans la session.
5. **Encoder** ce que Sam a validé : `tools/formation/encoder_sop.py --ecrire`, puis consigner (`ETAT.json`,
   `photos.json`, `docs/formation.md`).

## Les réponses de Sam

| Réponse | Effet |
|---|---|
| `encoder` · `encoder 1, 3` | encodés en v1.0 « à valider » : lisibles sur la tablette, sans quiz ni « vu faire » |
| `publier` · `publier 2` | encodés puis publiés (`statut` publiée) : quiz et « vu faire » ouverts |
| `corriger 2 : …` | l'article est réécrit, l'aperçu refait, la question reposée ; rien n'est encodé |
| `écarter 3` | le thème n'est plus proposé (noté dans `ecartes`) |
| pas de réponse | les thèmes restent « proposé » et sont représentés à la passe suivante, sans être réécrits |

Sans numéro, le mot vaut pour tous les thèmes encore « proposé ».

## `ETAT.json`

| Clé | Contenu |
|---|---|
| `brancheEnLigne` | la branche livrée au serveur |
| `dernierCommitLu`, `dernierCommitLuLe` | le dernier commit de cette branche examiné, et sa date |
| `passeLe` | la date de la dernière passe |
| `prochainNumero` | le numéro du prochain thème (les numéros ne se réutilisent pas : Sam répond par eux) |
| `themes[]` | `{n, theme, id, fichier, sources[], etat, proposeLe, valideLe, reponse, encodeLe, code, version, statut, note}` ; `etat` : `a_rediger` → `propose` → `encode` ou `publie` ; `a_corriger` ; `ecarte` |
| `ecartes[]` | `{sujet, sources[], raison, le}` : ce qui n'entre pas au Manuel (écran du siège seul, app de production, changement interne), ou ce que Sam a écarté |

`photos.json` (écrit par l'encodeur) : empreinte de chaque photo → son nom au serveur `u/…` ; une photo inchangée n'est
jamais renvoyée.
