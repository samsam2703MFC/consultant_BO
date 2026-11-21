# Maquettes « la boîte mail de demain » (21/11/2026)

Demande : « donne-moi 3 options dingues et futuristes pour la boîte mail ». Trois écrans à comparer, au design system de
la marque, dans le dashboard du magasin (rail, « Boîte mail » actif). Chacun est un **mode** de la même boîte, au choix
dans l'en-tête : Classique · Briefing · Pilote · Radar. À valider avant tout code.

| Écran | L'idée | Ce qu'il montre |
|---|---|---|
| `1-briefing-du-matin` | La nuit, l'IA lit tout et prépare. Le matin, la boîte est une pile de cartes. | Le résumé de la nuit (31 lus, 14 à valider, ~2 min) ; une carte = un e-mail résumé en une ligne, avec la réponse rédigée, l'offre TF Buddy pré-remplie et le dossier choisis d'avance ; → valider, ✎ modifier, ← plus tard ; « Écouter le briefing », le mode « mains dans la farine » où l'on répond à la voix ; la file du matin. |
| `2-pilote-automatique` | L'IA répond seule aux e-mails simples, dans des garde-fous. | Le curseur d'autonomie (Off · Brouillons · Simples · Tout sauf les prix) ; le fil de la nuit (17 réponses, la confiance et la source de chacune, « Annuler l'envoi » pendant 10 min) ; ce qui attend la gérante et pourquoi (réclamation, remise, B2B > 500 €, pas assez sûr) ; les règles « jamais » et « toujours » ; le temps gagné. |
| `3-radar-de-la-demande` | Les e-mails deviennent une prévision de production. | Les alertes (3 demandes Saint-Nicolas sans réponse pour le 6/12 : 640 € en jeu ; un samedi à 92 % de la capacité ; 2 mécontents) ; la frise des deux semaines, demandé et confirmé, par famille ; la production du 6/12 face à la demande ; les demandes repérées ; la fiche client 360 ; le thermomètre d'humeur. |

**Réel** : le magasin (L'Atelier By Halle), son adresse, le rail du dashboard. **Illustré** : clients, e-mails,
produits, montants, capacités et compteurs.

## Ce qui existe déjà dans le code

- La boîte elle-même (`panel/src/boite_mail.php`, `panel/public/dashboard/boite.js`) : lire, répondre, ranger, les
  modèles de réponse, les messages automatiques (accusé de réception, relance d'une offre, rappel la veille ; horloge
  `panel/bin/boite_cron.sh` toutes les 10 min).
- « Créer une offre » (`boite.js`) : le client retrouvé dans TF Buddy, le jour et les produits repérés dans le texte.
- L'assistant IA du magasin (`panel/src/assistant_magasin.php`) et le client Anthropic commun (`shared/src/anthropic.php`,
  sorties structurées), la clé dans `erp_cles`.
- La production du magasin (`/production`), les offres et les commandes par l'API de TF Buddy.

## Ce qu'il manque, option par option

1. **Briefing du matin** : une passe de nuit sur la boîte (le cron existe) qui garde, par e-mail, le résumé, la catégorie,
   la réponse et l'offre proposées (une table `panel_boite_*`) ; le « Créer une offre » rendu côté serveur, pour être
   fait d'avance ; le ton du magasin appris sur ses envois. Pour la voix : la synthèse et la reconnaissance vocales du
   navigateur (Web Speech), à essayer sur la tablette du magasin, dans le bruit du labo.
2. **Pilote automatique** : les réponses du pilote doivent s'appuyer sur des sources sûres et lisibles par le serveur :
   horaires du magasin, fiches allergènes, stock du jour et commandes de TF Buddy (routes à confirmer). Un envoi
   **différé de 10 minutes** (file d'envoi, annulable), un journal de ce qui est parti (`panel_boite_journal` existe), les
   règles « jamais » appliquées par le code et non par le modèle, un seuil de confiance. À décider : qui peut monter le
   curseur, et ce que le réseau impose à tous les magasins.
3. **Radar de la demande** : l'extraction de chaque demande (jour, produits, quantités, client) gardée en base et
   rapprochée des offres et commandes de TF Buddy ; une **capacité de production** par famille et par jour, qui n'existe
   nulle part aujourd'hui (à saisir par le magasin ou à déduire de l'historique) ; le ton de chaque e-mail (même appel
   que le résumé). La fiche client 360 lit TF Buddy par son API.

Les trois partagent la même brique : une lecture IA de chaque e-mail entrant (résumé, catégorie, demande extraite, ton),
faite une fois et gardée. C'est par elle qu'il faut commencer ; le briefing est le premier écran qu'elle rend possible.

    python3 docs/maquettes/boite-mail-futur/generer.py
    node docs/maquettes/boite-mail-futur/capturer.cjs
