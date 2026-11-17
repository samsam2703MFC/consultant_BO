# Maquette — la promotion proposée par le Coach Vente (17/11/2026)

Demande de Sam : « enlever le tableau [la liste des promotions de l'onglet Promotions] et mettre là un assistant qui vient
de l'Assistant IA, avec une promotion pré-encodée ; si le franchisé valide, la promotion part pour 1 mois à partir de
lundi prochain (standard), et on en fait un PDF envoyé par mail à l'équipe. »

Les écrans A et B sont posés sur l'onglet Promotions **en ligne** (Halle) : la carte « Où les promotions sont utilisées »
reste, la liste des promotions est retirée et remplacée par la carte du Coach Vente.

| Écran | Ce qu'il montre |
|---|---|
| `a-proposition.png` | Le Coach Vente propose une promotion déjà encodée (proposition 1 sur 3) : le nom, ce que le client achète, ce qu'il paie, les canaux, la période (du lundi qui suit la validation, 1 mois), la caisse ; à droite ce qu'elle laisse (marge du duo, coefficient, le volume à gagner pour garder la marge) et pourquoi ce duo. En bas : ce qui se passe à la validation, puis « Valider et lancer lundi 23/11 », « Modifier », « Une autre proposition », « En discuter avec le Coach Vente ». |
| `b-validee.png` | Après la validation : où en est la promotion (créée au panel « à venir », en caisse lundi, fin, bilan du Coach Vente le lendemain de la fin), la fiche envoyée à l'équipe (destinataires, envoyé), télécharger / renvoyer, annuler avant lundi ; la liste des promotions lancées avec le Coach Vente. |
| `c-equipe.png` | L'e-mail reçu par l'équipe et la fiche PDF A4 jointe : le duo, les dates, la caisse, les phrases pour le proposer, la mise en place. **Ni marge ni coefficient** dans la fiche de l'équipe. |

Chiffres : les prix et les coûts de recette sont ceux de Halle au panel (croissant 1,80 € TTC, coût 0,68 € ; café 2,30 €
TTC, coût 0,15 €). Duo à 3,50 € : marge 2,47 € HT (74,8 %), coefficient ×3,97 ; séparés, ils laissent 3,04 € HT, d'où
+23 % de duos pour garder la marge. Prénoms d'équipe d'exemple, adresses masquées.

## À trancher avant de coder

1. **Les adresses de l'équipe** : d'où viennent-elles ? (les fiches du personnel au panel, ou une liste saisie dans
   Paramètres › Mails et automatisations).
2. **La création au panel** : le franchisé crée-t-il la promotion lui-même (le dashboard écrit au panel, comme l'assistant
   des promotions du cockpit), ou la validation part-elle au bureau, qui la crée ?
3. **« 1 mois »** : du lundi au même quantième le mois suivant moins un jour (23/11 → 22/12, montré ici), ou 4 semaines
   pleines (23/11 → 20/12, du lundi au dimanche) ?
4. **La liste des promotions** retirée de l'onglet : disparaît-elle tout à fait, ou reste-t-elle derrière un lien
   « Voir les 32 promotions en caisse » ?

Captures : `php -S 127.0.0.1:8099 -t pilotage-reseau` (racine du dépôt), puis
`node pilotage-reseau/docs/maquettes/promo-coach/capturer.cjs`.
