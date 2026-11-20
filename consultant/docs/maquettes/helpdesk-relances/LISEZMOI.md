# Relances SMS des demandes au bureau — maquette

**Statut : maquette v1 du 20/11/2026, à valider. Rien n'est codé.**

Demande de Sam (20/11/2026) : « renvoyer un SMS à Sam ou Pierre tous les 7 jours (faire une maquette pour gérer ?) ».
Elle prolonge le SMS de chaque nouvelle demande, codé le même jour (`consultant/src/helpdesk.php`, `hdkSmsBureau`, réglage
`helpdeskSms`, voir `../helpdesk-franchises/LISEZMOI.md`).

Où : cockpit › Franchisés › Demandes des franchisés (`#/franchises/demandes`), dans le bloc « SMS au bureau » sous la
liste, et sur la fiche de chaque demande. La maquette reprend les styles du cockpit (`shared/public/assets`). Elle montre
une projection au lundi 07/12/2026, trois semaines après une mise en route le lundi 23/11. Les demandes de Sombreffe sont
celles de la capture du 20/11 ; celles de Gosselies et de Halle sont inventées.

| Écran | Capture | Ce qu'il montre |
|---|---|---|
| A · Réglages | `a-reglages.png` | les questions à valider ; les numéros, avec le consultant du panel de chacun (nouveau) ; les relances : bascule, rythme et heure, quelles demandes, à qui, regroupement, fin ; l'aperçu du SMS ; les prochaines relances |
| B · Liste et fiche | `b-liste-fiche.png` | la colonne « Relance » (prochaine, envoyées, arrêtée, suspendue), l'indicateur « Relances », la fiche d'une demande relancée trois fois : ses envois, la prochaine, « Suspendre les relances », « Relancer maintenant » |
| C · Les SMS | `c-sms-journal.png` | ce que reçoivent Sam et Pierre ; le journal des trois semaines (8 SMS) ; l'autre choix : un récapitulatif chaque lundi |

## La règle proposée (● dans la maquette)

1. **Quelles demandes** : celles qui attendent une réponse du bureau (nouvelles ou en cours, sans `admin_reply`).
   Autres choix : toutes les ouvertes, ou seulement celles dont la date limite est dépassée.
2. **À qui** : le consultant de la demande, rapproché d'un numéro par le nouveau champ « consultant du panel » (Sam V. → Sam,
   Pierre B. → Pierre Boseret). Une demande sans consultant, ou dont le consultant n'a pas de numéro (Loïc V.), va à tous
   les numéros. Autre choix : toujours les deux.
3. **Le rythme** : 7 jours après l'envoi de la demande, puis tous les 7 jours, à 08:30, les jours ouvrés seulement (une
   relance qui tombe un samedi ou un dimanche part le lundi). Le rythme se règle à 3, 7 ou 14 jours. Autre choix : un
   récapitulatif chaque lundi.
4. **Plusieurs demandes le même jour pour la même personne** : un seul SMS qui les liste (comme les rappels des contrôles).
5. **La fin** : la réponse du bureau, le statut Résolue, Fermée ou Annulée, ou « Suspendre les relances » sur la fiche.
   Pas de plafond. Autre choix : 6 relances au plus.
6. **La mise en route** : les demandes déjà en attente (Sombreffe : 44, 66 et 185 jours le 20/11) sont relancées le premier
   lundi, puis tous les 7 jours.

À fournir : **le numéro de Pierre Boseret** (aujourd'hui vide, rien ne lui parvient).

## Ce que le code demandera (après validation)

- Un cron (toutes les 10 minutes, comme les autres) qui lit les demandes du panel (`GET /cases`) et envoie les relances dues.
- Une table `con_hdk_relance` (`case_id`, `le`, `a`, `ok`, `motif`) pour le journal et les « déjà envoyées », et une colonne
  `relance_suspendue` sur `con_hdk_demande`.
- Le réglage `helpdeskSms` enrichi d'un bloc `relances` et du champ `consultant` de chaque numéro.
- Le texte, en alphabet SMS (`hdkGsm`), le lien du cockpit et la référence, comme le SMS de la nouvelle demande.

Captures : depuis la racine du dépôt, `php -S 127.0.0.1:8099 -t .`, puis `node consultant/docs/maquettes/helpdesk-relances/capturer.cjs`.
