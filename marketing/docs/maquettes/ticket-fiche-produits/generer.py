#!/usr/bin/env python3
"""Maquette — la page que le client ouvre en scannant le QR de son ticket de caisse.

    python3 generer.py   →  pour chaque ticket de donnees.json, la page client dans la langue de la boutique
                            ({page}-{langue}.html : corbais-fr.html, halle-nl.html) et dans l'autre langue (le
                            sélecteur FR / NL), et le ticket de caisse avec son QR (ticket-{page}.html).

La langue vient de la boutique du ticket (boutiques.{id}.langue : Corbais en français, Halle en néerlandais) ; le
client peut toujours basculer. Tout le texte produit vient de l'API, sans retouche : donnees.json recopie la fiche
technique du panel (GET /products/{id}/technical-sheet/raw) et le catalogue de chaque boutique (noms et ingrédients
traduits), relus le 21/11/2026 ; seul le ticket est composé. Un bloc sans donnée n'est pas affiché.
Le QR porte le numéro de transaction : {adresse}/t/{boutique}-{transaction}-{clé}.
Sans dépendance ; le QR d'un ticket est lu dans qr-{page}.svg, produit une fois avec le module Python « qrcode ».
Style : les jetons de la page d'accueil d'atelierby.be (papier, lin, sable, rubis, abricot), Gotham comme le site
(400 Regular New, 500 Medium, 600 Regular, 700 Black) et la police d'accent Playwrite DEVA (décision de Sam,
21/11/2026), toutes deux dans shared/public/assets/ds/fonts.
"""
from __future__ import annotations

import html
import json
import os
import re
from datetime import datetime, timedelta

ICI = os.path.dirname(os.path.abspath(__file__))
POLICES = '../../../../shared/public/assets/ds/fonts/'
SITE = 'https://atelierby.be/landing/'
NBSP = ' '

# Les 14 allergènes réglementaires, dans l'ordre du webshop ; les codes sont ceux de l'API (allergen_codes). Les cinq
# codes qu'aucun produit du catalogue ne porte (crustacés, céleri, sulfites, lupin, mollusques) sont supposés.
ALLERGENES = [
    ('cereals_gluten', 'Gluten', 'Gluten'), ('crustaceans', 'Crustacés', 'Schaaldieren'), ('eggs', 'Œufs', 'Eieren'),
    ('fish', 'Poisson', 'Vis'), ('peanuts', 'Arachides', 'Pinda’s'), ('soybeans', 'Soja', 'Soja'),
    ('milk', 'Lait', 'Melk'), ('nuts', 'Fruits à coque', 'Noten'), ('celery', 'Céleri', 'Selderij'),
    ('mustard', 'Moutarde', 'Mosterd'), ('sesame_seeds', 'Sésame', 'Sesam'), ('sulphites', 'Sulfites', 'Sulfieten'),
    ('lupin', 'Lupin', 'Lupine'), ('molluscs', 'Mollusques', 'Weekdieren'),
]
CODES = [a[0] for a in ALLERGENES]
# Travaillés dans les ateliers d'après le catalogue (au moins un produit les déclare) : d'où l'avertissement sur les
# traces.
TRAVAILLES = ['nuts', 'sesame_seeds', 'mustard', 'fish', 'peanuts', 'soybeans']

JOURS = {'fr': ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'],
         'nl': ['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag']}
MOIS = {'fr': ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre',
               'novembre', 'décembre'],
        'nl': ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober',
               'november', 'december']}

# Les textes de l'interface. Le néerlandais suit le ton du site (« uw », relevé sur atelierby.be).
T = {
    'fr': {
        'titre': 'Vos produits en détail · L’Atelier By', 'logo': 'L’Atelier By — sucré, salé, à emporter',
        'sur_titre': 'Votre ticket · {ville}', 'h1': 'Vos produits,', 'h1_script': 'en détail.',
        'chapeau': 'Composition, allergènes, cuisson, finition et conservation : tout sur les produits de votre ticket.',
        'articles': '{n} articles', 'ticket': 'Votre ticket · transaction n° {num}', 'ticket_t': '{p} produits, {n} articles',
        'total': 'Total payé, TVA comprise',
        'alg': 'Allergènes', 'alg_t': 'Ce que contient votre ticket',
        'sans': '<b>Aucun de vos produits</b> ne contient, d’après nos fiches : {liste}.',
        'traces': 'Nos ateliers travaillent aussi {liste} : des traces restent possibles. Une allergie sévère ? '
                  'Parlez-en en boutique avant de déguster.', 'et': 'et',
        'fraich': 'Fraîcheur', 'fraich_t': 'Dans quel ordre les déguster',
        'fiches': 'Fiche par fiche', 'h2': 'Tout ce qu’il faut ', 'h2_script': 'savoir.',
        'fiches_p': 'Glissez d’un produit à l’autre, ou touchez-en un dans la barre.',
        'fiches_p_bureau': 'Touchez un produit dans la barre pour aller à sa fiche.',
        'barre': 'Produits du ticket', 'prec': 'Produit précédent', 'suiv': 'Produit suivant',
        'carrousel': 'Fiches des produits', 'sur': 'sur',
        'composition': 'Composition', 'allergenes': 'Allergènes', 'cuisson': 'Cuisson', 'finition': 'Préparation et finition',
        'conservation': 'Conservation', 'aucun': 'Aucun des 14 allergènes réglementaires, d’après nos fiches.',
        'g14': 'Les 14 allergènes, un par un', 'contient': 'Contient', 'rechauffer': 'Réchauffer au four', 'nous_cuisons': 'Comment nous le cuisons',
        'entre': 'À conserver entre {a} et {b}.', 'lire': 'Lire la suite',
        'vege': 'Végétarien', 'a_deguster': 'À déguster {quand}', 'recommander': 'Le recommander',
        'avis': 'Votre avis', 'avis_t': 'Comment était votre passage à {ville} ?',
        'avis_p': 'Un mot sur Google aide l’équipe de la boutique, et les clients qui hésitent encore.',
        'avis_b': 'Laisser un avis',
        'app': 'Mobile', 'app_t': 'Tous vos tickets, au même endroit',
        'app_p': 'Dans l’application : vos fiches produits, le programme de fidélité et vos commandes.',
        'app_l': ['Fidélité', 'Bundles &amp; promotions', 'Abonnements'], 'app_b': 'Télécharger l’application',
        'shop': 'Votre boutique', 'adresse': 'Adresse', 'horaires': 'Horaires', 'tel': 'Téléphone',
        'commander': 'Commander pour demain',
        'pied': 'Les informations de cette page viennent des fiches techniques de nos produits, à la date de votre '
                'achat. Une recette peut évoluer : en cas d’allergie ou d’intolérance, <b>demandez conseil en '
                'boutique</b>.',
        'pied2': '© {an} L’Atelier By · Mentions légales · Confidentialité',
        'auj': 'aujourd’hui, avant {h}', 'dem_matin': 'demain matin', 'dem': 'demain',
        'jusqua': 'jusqu’à {m}', 'jusquau': 'jusqu’au {d}',
        'r_trans': 'Transaction', 'r_total': 'TOTAL EUR', 'r_tva': 'dont TVA 6 %', 'r_tel': 'Tél.', 'r_merci': 'Merci et à bientôt !',
        'r_scan': 'Allergènes, cuisson, conservation :<br>scannez, tout est là.',
    },
    'nl': {
        'titre': 'Uw producten in detail · L’Atelier By', 'logo': 'L’Atelier By — zoet, hartig, om mee te nemen',
        'sur_titre': 'Uw kasticket · {ville}', 'h1': 'Uw producten,', 'h1_script': 'in detail.',
        'chapeau': 'Samenstelling, allergenen, bereiding, afwerking en bewaren: alles over de producten op uw '
                   'kasticket.',
        'articles': '{n} artikelen', 'ticket': 'Uw kasticket · transactie nr. {num}', 'ticket_t': '{p} producten, {n} artikelen',
        'total': 'Totaal betaald, incl. btw',
        'alg': 'Allergenen', 'alg_t': 'Wat uw kasticket bevat',
        'sans': '<b>Geen enkel product</b> op uw kasticket bevat, volgens onze fiches: {liste}.',
        'traces': 'In onze ateliers verwerken we ook {liste}: sporen zijn mogelijk. Een ernstige allergie? Vraag '
                  'raad in de winkel voor u proeft.', 'et': 'en',
        'fraich': 'Versheid', 'fraich_t': 'Wat eet u best eerst?',
        'fiches': 'Product per product', 'h2': 'Alles wat u moet ', 'h2_script': 'weten.',
        'fiches_p': 'Veeg van het ene product naar het andere, of tik er een aan in de balk.',
        'fiches_p_bureau': 'Klik op een product in de balk om naar de fiche te gaan.',
        'barre': 'Producten op het kasticket', 'prec': 'Vorig product', 'suiv': 'Volgend product',
        'carrousel': 'Productfiches', 'sur': 'van',
        'composition': 'Samenstelling', 'allergenes': 'Allergenen', 'cuisson': 'Bakken en opwarmen', 'finition': 'Bereiding en afwerking',
        'conservation': 'Bewaren', 'aucun': 'Geen van de 14 wettelijke allergenen, volgens onze fiches.',
        'g14': 'De 14 allergenen, een voor een', 'contient': 'Bevat', 'rechauffer': 'Opwarmen in de oven', 'nous_cuisons': 'Hoe wij het bakken',
        'entre': 'Bewaren tussen {a} en {b}.', 'lire': 'Meer lezen',
        'vege': 'Vegetarisch', 'a_deguster': 'Te eten {quand}', 'recommander': 'Opnieuw bestellen',
        'avis': 'Uw mening', 'avis_t': 'Hoe was uw bezoek in {ville}?',
        'avis_p': 'Een woordje op Google helpt het winkelteam, en klanten die nog twijfelen.',
        'avis_b': 'Laat een review achter',
        'app': 'Mobiel', 'app_t': 'Al uw kastickets op één plek',
        'app_p': 'In de app: uw productfiches, het getrouwheidsprogramma en uw bestellingen.',
        'app_l': ['Getrouwheid', 'Bundels &amp; promoties', 'Abonnementen'], 'app_b': 'Download de app',
        'shop': 'Uw winkel', 'adresse': 'Adres', 'horaires': 'Openingsuren', 'tel': 'Telefoon',
        'commander': 'Bestel voor morgen',
        'pied': 'De informatie op deze pagina komt uit de technische fiches van onze producten, op de datum van uw '
                'aankoop. Een recept kan wijzigen: bij een allergie of intolerantie, <b>vraag raad in de '
                'winkel</b>.',
        'pied2': '© {an} L’Atelier By · Wettelijke vermeldingen · Privacy',
        'auj': 'vandaag, vóór {h}', 'dem_matin': 'morgenochtend', 'dem': 'morgen',
        'jusqua': 'tot {m}', 'jusquau': 'tot {d}',
        'r_trans': 'Transactie', 'r_total': 'TOTAAL EUR', 'r_tva': 'waarvan btw 6 %', 'r_tel': 'Tel.', 'r_merci': 'Bedankt en tot ziens!',
        'r_scan': 'Allergenen, bereiding, bewaren:<br>scan, alles staat erop.',
    },
}

e = html.escape


def maj(t: str) -> str:
    return t[:1].upper() + t[1:]


def euros(x: float, lg: str) -> str:
    v = f'{x:,.2f}'.replace(',', NBSP).replace('.', ',')
    return f'€{NBSP}{v}' if lg == 'nl' else f'{v}{NBSP}€'


def jour_long(d: datetime, lg: str) -> str:
    n = '1er' if lg == 'fr' and d.day == 1 else str(d.day)
    return f'{JOURS[lg][d.weekday()]} {n}{NBSP}{MOIS[lg][d.month - 1]}'


def heure(d: datetime, lg: str) -> str:
    # espaces insécables : « 20 h 45 » / « 21.00 u » ne se coupent pas
    return f'{d.hour}.{d.minute:02d}{NBSP}u' if lg == 'nl' else f'{d.hour}{NBSP}h{NBSP}{d.minute:02d}'


def limite(vente: datetime, minutes: int, lg: str) -> tuple[datetime, bool, str, str]:
    """La date limite (vente + durée de conservation du panel) dite au client : (date, urgent, court, long)."""
    t = T[lg]
    fin = vente + timedelta(minutes=minutes)
    if fin.date() == vente.date():
        q = fin.replace(minute=fin.minute - fin.minute % 15)  # « avant 20 h 45 » plutôt que 20 h 47
        txt = t['auj'].format(h=heure(q, lg))
        return fin, True, maj(txt), txt
    if fin.date() == (vente + timedelta(days=1)).date():
        m = t['dem_matin'] if fin.hour < 12 else t['dem']
        return fin, False, maj(t['jusqua'].format(m=m)), t['jusqua'].format(m=f'{m}, {jour_long(fin, lg)}')
    n = '1er' if lg == 'fr' and fin.day == 1 else str(fin.day)
    return (fin, False, maj(t['jusquau'].format(d=f'{n}{NBSP}{MOIS[lg][fin.month - 1]}')),
            t['jusquau'].format(d=jour_long(fin, lg)))


def liste(mots: list[str], lg: str) -> str:
    return f'{", ".join(mots[:-1])} {T[lg]["et"]} {mots[-1]}' if len(mots) > 1 else ''.join(mots)


ICONES = {
    'four': '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><circle cx="7" cy="6.5" r=".6"/>'
            '<circle cx="10" cy="6.5" r=".6"/><rect x="6.5" y="12" width="11" height="5" rx="1"/>',
    'micro': '<rect x="2.5" y="5" width="19" height="14" rx="2"/><rect x="5" y="8" width="10" height="8" rx="1"/>'
             '<path d="M18 9v.01M18 12v.01M18 15v.01"/>',
    'casserole': '<path d="M4 10h14v6a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3z"/><path d="M18 12h3M8 6c0-1 1-1 1-2M12 6c0-1 1-1 1-2"/>',
    'froid': '<path d="M12 3v18M4.2 7.5l15.6 9M4.2 16.5l15.6-9"/><path d="M9.5 4.5 12 6l2.5-1.5M9.5 19.5 12 18l2.5 1.5"/>',
    'frais': '<path d="M12 3c3 3 5 6 5 9a5 5 0 0 1-10 0c0-3 2-6 5-9z"/>',
    'horloge': '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    'feuille': '<path d="M5 19c0-8 5-13 14-14-1 9-6 14-14 14z"/><path d="M5 19 13 11"/>',
    'repere': '<path d="M12 21s-6.5-5.8-6.5-11a6.5 6.5 0 0 1 13 0c0 5.2-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.2"/>',
    'poids': '<path d="M6 8h12l2 12H4z"/><circle cx="12" cy="5.5" r="2"/>',
    'fleche': '<path d="M5 12h14M13 6l6 6-6 6"/>',
    'gauche': '<path d="M19 12H5M11 6l-6 6 6 6"/>',
    'alerte': '<path d="M12 4 21 19H3z"/><path d="M12 10v4M12 16.5v.01"/>',
    'etoile': '<path d="m12 4 2.4 4.9 5.4.8-3.9 3.8.9 5.4L12 16.4l-4.8 2.5.9-5.4-3.9-3.8 5.4-.8z"/>',
}


def ic(nom: str, cls: str = 'ic') -> str:
    return f'<svg class="{cls}" viewBox="0 0 24 24" aria-hidden="true">{ICONES[nom]}</svg>'


def prefixe_ingredients(t: str) -> str:
    """« Ingrédients: A, B. » → « A, B » (l'étiquette du panel commence par le mot, dans sa langue)."""
    t = re.sub(r'^\s*(Ingrédients|Ingrediënten|Ingredients)\s*:\s*', '', t or '', flags=re.I).strip()
    return t[:-1].strip() if t.endswith('.') else t


def morceaux(t: str) -> list[str]:
    """Les ingrédients d'une étiquette, séparés aux virgules hors parenthèses."""
    out, prof, cur = [], 0, ''
    for ch in t:
        prof += (ch == '(') - (ch == ')')
        if ch == ',' and prof == 0:
            out.append(cur.strip()); cur = ''
        else:
            cur += ch
    return [x for x in out + [cur.strip()] if x]


def composition(p: dict, lg: str) -> tuple[list[tuple[str, list[str]]], bool]:
    """La composition dite au client : [(ingrédient, codes d'allergènes)], et vrai si le texte est dans la langue.

    Source : l'étiquette du catalogue (label_ingredients, traduite par les alias du panel) ; ses ingrédients sont ceux
    de la recette de la fiche technique, dans le même ordre d'une langue à l'autre : les allergènes de chaque
    ingrédient se lisent sur la recette (nom français), puis se reportent par position. Sans étiquette au catalogue,
    les ingrédients de la recette de la fiche technique.
    """
    ft, cat = p['fiche_technique'], p['catalogue']
    allerg = {e['nom'].lower(): e['allergenes'] for e in ft['recette']}
    fr = morceaux(prefixe_ingredients((cat.get('fr') or {}).get('label_ingredients', '')))
    if not fr:
        items = [(e['nom'], e['allergenes']) for e in ft['recette']]
        return items, lg == 'fr'
    codes = [allerg.get(x.lower(), []) for x in fr]
    if lg == 'fr':
        return list(zip(fr, codes)), True
    autre = morceaux(prefixe_ingredients((cat.get(lg) or {}).get('label_ingredients', '')))
    if len(autre) == len(fr):
        return list(zip(autre, codes)), True
    return list(zip(fr, codes)), False


def charger() -> tuple[dict, list[dict]]:
    """Les tickets, chacun avec sa boutique et ses lignes prêtes à écrire (dans les deux langues)."""
    with open(os.path.join(ICI, 'donnees.json'), encoding='utf-8') as f:
        d = json.load(f)
    tickets = []
    for t in d['tickets']:
        b = d['boutiques'][str(t['boutique'])]
        vente = datetime.fromisoformat(t['date'])
        lignes = []
        for l in t['lignes']:
            p = d['produits'][str(l['id'])]
            ft, cat = p['fiche_technique'], p['catalogue']
            # Le prix : celui du catalogue de la boutique du ticket, sinon le prix conseillé de la fiche technique.
            prix = next((float(c['portion_price_gross']) for c in cat.values() if c['boutique'] == b['id']),
                        float(ft['suggested_sale_price']))
            nom = {lg: ((cat.get(lg) or cat.get('fr') or {}).get('name') or ft['name']).strip() for lg in ('fr', 'nl')}
            nom = {lg: maj(n) for lg, n in nom.items()}
            codes = {a for e in ft['recette'] for a in e['allergenes']}
            for c in cat.values():
                codes |= set(c.get('allergen_codes') or [])
            lignes.append({
                'id': l['id'], 'qte': l['qte'], 'prix': prix, 'total': prix * l['qte'], 'ft': ft,
                'nom': nom,
                'famille': {lg: (cat.get(lg) or cat.get('fr') or {}).get('groupe') or ft['category_name'] for lg in ('fr', 'nl')},
                'image': next((c['image'] for c in cat.values() if c.get('image')), None) or p.get('image_locale'),
                'composition': {lg: composition(p, lg) for lg in ('fr', 'nl')},
                'allergenes': [a for a in CODES if a in codes],
                'limite': {lg: limite(vente, int(ft['shelf_life_minutes']), lg) for lg in ('fr', 'nl')},
            })
        tickets.append({**t, 'b': b, 'vente': vente, 'L': lignes})
    return d, tickets


# --------------------------------------------------------------------------------------------------------------------
# La page client
# --------------------------------------------------------------------------------------------------------------------
# Mobile d'abord : une colonne, les fiches en carrousel à glisser (défilement horizontal aimanté, une fiche par écran,
# la suivante dépasse pour inviter au geste), une barre des produits collante pour sauter de l'une à l'autre, des
# points et des flèches comme sur le site. Pas de grande photo : des vignettes, à côté du nom. Au-delà de 860 px, les
# fiches redeviennent une grille à deux colonnes.

CSS = """
@font-face{font-family:'Gotham';src:url('%(p)sGotham_Regular_New.otf') format('opentype');font-weight:400;font-display:swap}
@font-face{font-family:'Gotham';src:url('%(p)sGotham_Medium.otf') format('opentype');font-weight:500;font-display:swap}
@font-face{font-family:'Gotham';src:url('%(p)sGotham_Regular.otf') format('opentype');font-weight:600;font-display:swap}
@font-face{font-family:'Gotham';src:url('%(p)sGotham_Black.otf') format('opentype');font-weight:700;font-display:swap}
@font-face{font-family:'Playwrite DEVA';src:url('%(p)sPlaywriteDEVA-Light.ttf') format('truetype');font-weight:300;font-display:swap}

/* Jetons de la page d'accueil d'atelierby.be (relevés le 21/11/2026) */
:root{
  --paper:#FCFBF8; --ink:#232220; --muted:#8A847C; --faint:#B8B2A8;
  --hair:rgba(35,34,32,.08); --hair-2:rgba(35,34,32,.12);
  --ruby:#8D1D2C; --apricot:#F2C9A0; --lin:#EAE4DC; --sand:#F3EEE6;
  --gutter:clamp(20px,4vw,56px); --ease:cubic-bezier(.2,.8,.2,1); --nav:63px;
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{scroll-behavior:smooth}
body{margin:0;font-family:'Gotham',system-ui,sans-serif;color:var(--ink);background:var(--paper);-webkit-font-smoothing:antialiased;overflow-x:hidden}
a{color:inherit;text-decoration:none}
button{font:inherit;color:inherit;cursor:pointer}
h1,h2,h3,h4,p,ul{margin:0}
ul{padding:0;list-style:none}
.wrap{padding-left:var(--gutter);padding-right:var(--gutter);max-width:1240px;margin:0 auto;width:100%%}
.eyebrow{font-size:11px;letter-spacing:.26em;text-transform:uppercase;color:var(--muted);font-weight:600}
.eyebrow--ruby{color:var(--ruby)}
.script{font-family:'Playwrite DEVA','Gotham',cursive;font-weight:300;letter-spacing:0;color:var(--ruby)}
.ic{width:16px;height:16px;flex:none;stroke:currentColor;stroke-width:1.6;fill:none;stroke-linecap:round;stroke-linejoin:round}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:12px;padding:16px 26px;border-radius:10px;background:var(--ink);color:var(--paper);font-size:14px;font-weight:600}
.btn .ic{width:15px;height:15px}
.link{font-size:14px;font-weight:600;border-bottom:1px solid var(--ink);padding-bottom:3px;display:inline-flex;align-items:center;gap:8px}
.link .ic{width:14px;height:14px}
.th{flex:none;background:#ececec center/cover no-repeat}

/* barre du haut */
.nav{position:sticky;top:0;z-index:30;height:var(--nav);display:flex;align-items:center;justify-content:space-between;gap:16px;background:rgba(252,251,248,.92);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border-bottom:1px solid var(--hair)}
.nav.wrap{max-width:none;padding-left:max(var(--gutter),calc((100%% - 1240px) / 2 + var(--gutter)));padding-right:max(var(--gutter),calc((100%% - 1240px) / 2 + var(--gutter)))}
.nav__logo img{height:28px;width:auto;display:block}
.lang{display:flex;gap:2px;border:1px solid var(--hair-2);border-radius:9px;padding:3px}
.lang span,.lang a{font-size:11px;font-weight:600;letter-spacing:.08em;padding:6px 10px;border-radius:6px;color:var(--muted)}
.lang .on{background:var(--ink);color:var(--paper)}

/* en-tête : compact, sans photo */
.head{padding-top:26px;padding-bottom:24px}
.head h1{font-size:40px;line-height:.98;letter-spacing:-.035em;font-weight:700;margin-top:12px}
.head h1 .script{display:block;font-size:.7em;line-height:1.4;margin-top:2px}
.head__lede{margin-top:10px;color:var(--muted);font-size:14.5px;line-height:1.55;font-weight:500;max-width:56ch}
.meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:16px}
.meta span{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:8px;background:var(--sand);font-size:12px;font-weight:600;color:#4a4540}
.meta .ic{width:13px;height:13px}

/* fond sable et cartes */
.sand{background:var(--sand);padding:20px 0 40px}
.stack{display:grid;gap:12px}
.card{background:var(--paper);border-radius:18px;padding:20px}
.card__t{font-size:22px;line-height:1.1;letter-spacing:-.02em;font-weight:700;margin-top:8px}
.card__p{margin-top:8px;color:var(--muted);font-size:14px;line-height:1.55;font-weight:500}

/* le ticket */
.rows{display:grid;gap:8px;margin-top:14px}
.row{display:grid;grid-template-columns:44px minmax(0,1fr) auto 14px;gap:12px;align-items:center;min-height:60px;padding:8px 14px 8px 8px;border-radius:12px;background:var(--sand)}
.row .th{width:44px;height:44px;border-radius:10px}
.row b{display:block;font-size:14.5px;font-weight:600;line-height:1.25}
.row small{display:block;font-size:12px;color:var(--muted);margin-top:3px;font-weight:500}
.row em{font-style:normal;font-size:14px;font-weight:600}
.row .ic{width:14px;height:14px}
.total{display:flex;justify-content:space-between;align-items:baseline;margin-top:16px;padding-top:14px;border-top:1px solid var(--hair-2)}
.total span{font-size:13px;color:var(--muted);font-weight:500}
.total b{font-size:24px;font-weight:700;letter-spacing:-.02em}
.fine{margin-top:6px;font-size:11.5px;color:var(--faint);font-weight:500}

/* allergènes du ticket */
.alg-l{display:grid;gap:10px;margin-top:14px}
.alg-r{display:grid;grid-template-columns:96px minmax(0,1fr);gap:12px;align-items:start}
.alg-r .chip{justify-self:start}
.chip{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border-radius:6px;background:rgba(141,29,44,.08);color:var(--ruby);font-size:13px;font-weight:600;white-space:nowrap}
.chip::before{content:"";width:7px;height:7px;border-radius:50%%;background:var(--ruby)}
.alg-r p{font-size:13px;line-height:1.45;color:var(--muted);font-weight:500;padding-top:7px}
.sans{margin-top:14px;font-size:13px;line-height:1.55;font-weight:500}
.sans b{font-weight:600}
.note{display:flex;gap:10px;margin-top:14px;padding:12px 14px;border-radius:12px;background:var(--sand);font-size:12.5px;line-height:1.5;color:#5d574f;font-weight:500}
.note .ic{color:var(--ruby);margin-top:1px}

/* ordre de fraîcheur */
.tl{margin-top:16px}
.tl li{position:relative;padding:0 0 16px 26px}
.tl li::before{content:"";position:absolute;left:4px;top:4px;width:10px;height:10px;border-radius:50%%;background:var(--paper);box-shadow:inset 0 0 0 2px var(--faint)}
.tl li::after{content:"";position:absolute;left:8.5px;top:18px;bottom:2px;width:1px;background:var(--hair-2)}
.tl li:last-child{padding-bottom:0}.tl li:last-child::after{display:none}
.tl li.now::before{background:var(--ruby);box-shadow:none}
.tl b{display:block;font-size:14px;font-weight:600}
.tl li.now b{color:var(--ruby)}
.tl span{display:block;margin-top:3px;font-size:13px;color:var(--muted);font-weight:500;line-height:1.45}

/* chapitre des fiches */
.chap{padding-top:36px}
.h2{font-size:34px;line-height:1;letter-spacing:-.03em;font-weight:700;margin-top:10px}
.h2 .script{font-size:.72em}
.bureau{display:none}
.chap__lede{margin-top:10px;color:var(--muted);font-size:14.5px;line-height:1.55;font-weight:500;max-width:60ch;display:flex;gap:8px;align-items:center}

/* barre des produits, collante sous la barre du haut */
.pick{position:sticky;top:var(--nav);z-index:20;margin-top:14px;padding:10px 0;background:rgba(243,238,230,.94);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)}
.pick__in{display:flex;gap:6px;overflow-x:auto;padding:0 20px;scrollbar-width:none;scroll-behavior:smooth}
.pick__in::-webkit-scrollbar{display:none}
.pick__b{display:inline-flex;align-items:center;gap:8px;padding:4px 12px 4px 4px;border:0;border-radius:8px;background:var(--paper);font-size:13px;font-weight:600;white-space:nowrap;transition:background .25s,color .25s}
.pick__b .th{width:30px;height:30px;border-radius:6px}
.pick__b em{font-style:normal;font-size:11.5px;color:var(--muted);font-weight:600}
.pick__b.on{background:var(--ink);color:var(--paper)}
.pick__b.on em{color:var(--apricot)}

/* commandes du carrousel : position, points, flèches (comme le carrousel du site) */
.ctrl{display:flex;align-items:center;gap:14px;padding:4px 20px 12px}
.ctrl__n{font-size:13px;font-weight:600;min-width:44px}
.ctrl__n span{color:var(--muted)}
.ctrl__dots{display:flex;gap:6px;align-items:center;flex:1}
.ctrl__dots i{width:6px;height:6px;border-radius:2px;background:var(--faint);opacity:.55;transition:width .3s var(--ease),background .3s,opacity .3s}
.ctrl__dots i.on{width:26px;background:var(--ruby);opacity:1}
.arr{width:44px;height:44px;border-radius:10px;border:0;background:var(--paper);display:flex;align-items:center;justify-content:center;box-shadow:0 1px 2px rgba(35,34,32,.08)}
.arr:disabled{opacity:.4;cursor:default}
.arr .ic{width:16px;height:16px}

/* le carrousel */
.track{display:grid;grid-auto-flow:column;grid-auto-columns:calc(100%% - 44px);gap:12px;align-items:start;overflow-x:auto;overflow-y:hidden;scroll-snap-type:x mandatory;scroll-padding-inline:20px;padding:0 24px 4px 20px;scrollbar-width:none;overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch;transition:height .35s var(--ease)}
.track::-webkit-scrollbar{display:none}
.track>.prod{scroll-snap-align:start;scroll-snap-stop:always}

/* une fiche produit */
.prod{background:var(--paper);border-radius:18px;padding:18px 18px 22px;scroll-margin-top:calc(var(--nav) + 70px)}
.prod__head{display:grid;grid-template-columns:76px minmax(0,1fr);gap:14px;align-items:center}
.prod__th{position:relative;width:76px;height:76px;border-radius:14px}
.prod__qty{position:absolute;right:-6px;top:-6px;min-width:26px;height:26px;padding:0 7px;border-radius:6px;background:var(--ink);color:var(--paper);display:flex;align-items:center;justify-content:center;font-size:11.5px;font-weight:700;box-shadow:0 0 0 2px var(--paper)}
.prod__name{font-size:25px;line-height:1.04;letter-spacing:-.03em;font-weight:700;margin-top:6px}
.prod__px{margin-top:5px;font-size:13px;color:var(--muted);font-weight:600}
.prod__hook{margin-top:14px;font-size:14px;line-height:1.5;color:var(--muted);font-weight:500}
.tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
.tag{display:inline-flex;align-items:center;gap:6px;padding:7px 11px;border-radius:6px;background:var(--sand);font-size:12px;font-weight:600;color:#4a4540}
.tag .ic{width:13px;height:13px}
.tag--now{background:var(--ruby);color:#fff}
.tag--abr{background:var(--apricot);color:#6b4420}
.blk{margin-top:18px;padding-top:16px;border-top:1px solid var(--hair)}
.blk>.eyebrow{display:block;margin-bottom:10px}
.blk__txt{font-size:14px;line-height:1.6;font-weight:500;color:#3a3631}
.blk__txt b{font-weight:700;color:var(--ink)}
.chips{display:flex;flex-wrap:wrap;gap:6px}
.none{font-size:14px;font-weight:500;color:#3a3631}
details.g14{margin-top:12px}
details.g14 summary{cursor:pointer;list-style:none;font-size:12.5px;font-weight:600;color:var(--muted);display:inline-flex;gap:6px;align-items:center;padding:4px 0}
details.g14 summary::-webkit-details-marker{display:none}
details.g14 summary::after{content:"+";font-size:15px;line-height:1}
details.g14[open] summary::after{content:"–"}
.g14 ul{display:grid;grid-template-columns:1fr 1fr;gap:2px 16px;margin-top:8px}
.g14 li{display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid var(--hair);font-size:12.5px;font-weight:500;color:var(--faint)}
.g14 li.oui{color:var(--ink);font-weight:600}
.g14 li.oui span{color:var(--ruby)}
.how{display:grid;gap:8px}
.how__i{display:grid;grid-template-columns:40px minmax(0,1fr);gap:12px;padding:14px;border-radius:14px;background:var(--sand)}
.how__ic{width:40px;height:40px;border-radius:10px;background:var(--paper);display:flex;align-items:center;justify-content:center}
.how__ic .ic{width:20px;height:20px;stroke-width:1.5}
.how__t{font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);font-weight:600}
.how__big{margin-top:4px;font-size:22px;line-height:1.05;letter-spacing:-.02em;font-weight:700}
.how__big span{font-size:14px;letter-spacing:0;font-weight:600;color:var(--ruby);margin-left:4px;white-space:nowrap}
.how__d{margin-top:6px;font-size:13px;line-height:1.5;color:#5d574f;font-weight:500}
.how__d p{margin:0}
.num{margin:0;padding-left:18px;display:grid;gap:6px}
details.long summary{list-style:none;cursor:pointer;display:block}
details.long summary::-webkit-details-marker{display:none}
details.long:not([open]) .how__d{max-height:7.6em;overflow:hidden;-webkit-mask:linear-gradient(#000 55%%,transparent);mask:linear-gradient(#000 55%%,transparent)}
.plus{display:inline-block;margin-top:6px;font-size:12.5px;font-weight:600;color:var(--ruby)}
details.long[open] .plus{display:none}
.steps{counter-reset:s;display:grid;gap:10px;margin:0;padding:0;list-style:none}
.steps li{position:relative;padding-left:34px;font-size:14px;line-height:1.5;font-weight:500;color:#3a3631;counter-increment:s}
.steps li::before{content:counter(s);position:absolute;left:0;top:0;width:24px;height:24px;border-radius:6px;background:rgba(141,29,44,.08);color:var(--ruby);font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center}
.th[data-vide]{background:var(--lin)}
.avoid{margin-top:10px;font-size:12.5px;color:var(--muted);font-weight:500;display:flex;gap:8px;align-items:flex-start}
.avoid .ic{width:14px;height:14px;color:var(--ruby);margin-top:1px}
.tips{display:grid;gap:8px}
.tips li{position:relative;padding-left:18px;font-size:14px;line-height:1.5;font-weight:500;color:#3a3631}
.tips li::before{content:"";position:absolute;left:2px;top:8px;width:6px;height:6px;border-radius:50%%;background:var(--apricot);box-shadow:0 0 0 2px rgba(242,201,160,.35)}
.keep{display:grid;gap:10px}
.until{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;background:rgba(141,29,44,.06);font-size:13.5px;font-weight:600;color:var(--ruby)}
.prod__cta{margin-top:20px}
.prod__cta .btn{width:100%%}

/* avis, application, boutique */
.end{display:grid;gap:12px;margin-top:28px}
.abr{background:var(--apricot);border-radius:18px;padding:24px 22px 26px}
.abr .card__t{font-size:28px;line-height:1.05;letter-spacing:-.03em}
.abr .card__p{color:#5b4632}
.stars{display:flex;gap:4px;margin-top:14px;color:var(--ruby)}
.stars .ic{width:26px;height:26px;stroke-width:1.4}
.abr .btn{margin-top:16px}
.dots{display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:12px}
.dots li{font-size:12.5px;color:var(--muted);font-weight:500;display:flex;align-items:center;gap:7px}
.dots li::before{content:"";width:5px;height:5px;border-radius:50%%;background:var(--faint)}
.card .link{margin-top:16px}
.shop dl{display:grid;grid-template-columns:auto 1fr;gap:8px 14px;margin:14px 0 0;font-size:13.5px;font-weight:500}
.shop dt{color:var(--muted)}
.shop dd{margin:0}
.foot{padding-top:28px;padding-bottom:40px}
.foot p{font-size:12px;line-height:1.6;color:var(--muted);font-weight:500;max-width:70ch}
.foot p+p{margin-top:10px}
.foot b{font-weight:600;color:var(--ink)}

/* tablette et bureau : plus de carrousel, une grille */
@media (min-width:860px){
  :root{--nav:68px}
  .head{padding-top:44px;padding-bottom:36px}
  .head h1{font-size:64px}
  .head__lede{font-size:16px}
  .top{display:grid;grid-template-columns:1.15fr 1fr;gap:16px;align-items:start}
  .top .stack{gap:16px}
  .h2{font-size:48px}
  .pick__in{max-width:1240px;margin:0 auto;padding:0 var(--gutter)}
  .ctrl,.mobile{display:none}
  .bureau{display:inline}
  .track{grid-auto-flow:row;grid-template-columns:repeat(2,minmax(0,1fr));grid-auto-columns:auto;overflow:visible;height:auto!important;max-width:1240px;margin:8px auto 0;padding:0 var(--gutter);gap:16px}
  .prod{padding:26px 28px 30px}
  .prod__head{grid-template-columns:96px minmax(0,1fr)}
  .prod__th{width:96px;height:96px}
  .prod__name{font-size:30px}
  .prod__cta .btn{width:auto}
  .end{grid-template-columns:1fr 1fr 1fr;gap:16px;align-items:stretch}
}
"""

# Le carrousel : la position suit le défilement (aimanté par le CSS), la barre des produits, les points et les flèches
# le pilotent, la hauteur suit la fiche affichée (sinon la plus longue laisserait un blanc sous les autres).
JS = """
(function(){
  var track=document.getElementById('track'); if(!track) return;
  var cards=[].slice.call(track.children), picks=[].slice.call(document.querySelectorAll('.pick__b'));
  var dots=[].slice.call(document.querySelectorAll('.ctrl__dots i')), n=document.querySelector('.ctrl__n b');
  var prev=document.querySelector('.arr--prev'), next=document.querySelector('.arr--next'), pickIn=document.querySelector('.pick__in');
  var bureau=window.matchMedia('(min-width:860px)'), i=0, t=null;
  function haut(){ return document.querySelector('.nav').offsetHeight + document.querySelector('.pick').offsetHeight + 8; }
  function marquer(k){
    i=k;
    picks.forEach(function(b,j){ b.classList.toggle('on', j===k); b.setAttribute('aria-current', j===k ? 'true' : 'false'); });
    dots.forEach(function(d,j){ d.classList.toggle('on', j===k); });
    if(n) n.textContent=k+1;
    if(prev) prev.disabled = k===0; if(next) next.disabled = k===cards.length-1;
    var b=picks[k]; if(b) pickIn.scrollLeft = b.offsetLeft - (pickIn.clientWidth - b.offsetWidth)/2;
    if(!bureau.matches) track.style.height = cards[k].offsetHeight + 'px';
  }
  function remonter(){ var y=track.getBoundingClientRect().top; if(y < haut()) window.scrollBy({top: y - haut(), behavior:'smooth'}); }
  function aller(k){
    k=Math.max(0, Math.min(cards.length-1, k));
    if(bureau.matches){ cards[k].scrollIntoView({behavior:'smooth', block:'start'}); marquer(k); return; }
    track.scrollTo({left: cards[k].offsetLeft - cards[0].offsetLeft, behavior:'smooth'});
    marquer(k); remonter();
  }
  track.addEventListener('scroll', function(){
    clearTimeout(t);
    t=setTimeout(function(){
      var pas = cards.length > 1 ? cards[1].offsetLeft - cards[0].offsetLeft : 1;
      var k=Math.round(track.scrollLeft / pas);
      if(k!==i){ marquer(k); remonter(); }
    }, 90);
  }, {passive:true});
  picks.forEach(function(b,j){ b.addEventListener('click', function(){ aller(j); }); });
  if(prev) prev.addEventListener('click', function(){ aller(i-1); });
  if(next) next.addEventListener('click', function(){ aller(i+1); });
  // Les lignes du ticket mènent à la fiche du produit, dans le carrousel.
  [].slice.call(document.querySelectorAll('a.row')).forEach(function(a){
    a.addEventListener('click', function(ev){
      var k=cards.findIndex(function(c){ return '#'+c.id === a.getAttribute('href'); }); if(k<0) return;
      ev.preventDefault(); document.getElementById('fiches').scrollIntoView({behavior:'smooth'}); setTimeout(function(){ aller(k); }, 350);
    });
  });
  // Les flèches du clavier, pour qui consulte la page sur un ordinateur étroit.
  track.addEventListener('keydown', function(ev){ if(ev.key==='ArrowRight') aller(i+1); if(ev.key==='ArrowLeft') aller(i-1); });
  window.addEventListener('resize', function(){ marquer(i); });
  if('ResizeObserver' in window) new ResizeObserver(function(){ marquer(i); }).observe(cards[0].parentNode);
  [].slice.call(document.querySelectorAll('details')).forEach(function(d){ d.addEventListener('toggle', function(){ marquer(i); }); });
  window.carrousel = {aller: aller, marquer: marquer};
  marquer(0);
})();
"""


def nom_allergene(code: str, lg: str) -> str:
    return next(a[1] if lg == 'fr' else a[2] for a in ALLERGENES if a[0] == code)


def bloc_composition(l: dict, lg: str) -> str:
    items, traduit = l['composition'][lg]
    txt = ', '.join(e(n) + (' (' + ', '.join(f'<b>{e(nom_allergene(a, lg).lower())}</b>' for a in al) + ')' if al else '')
                    for n, al in items)
    attr = '' if traduit else ' lang="fr"'
    return f'<div class="blk"><span class="eyebrow">{e(T[lg]["composition"])}</span><p class="blk__txt"{attr}>{txt}.</p></div>'


def bloc_allergenes(l: dict, lg: str) -> str:
    t, al = T[lg], l['allergenes']
    if al:
        tete = '<div class="chips">' + ''.join(f'<span class="chip">{e(nom_allergene(a, lg))}</span>' for a in al) + '</div>'
    else:
        tete = f'<p class="none">{e(t["aucun"])}</p>'
    g = ''.join(
        f'<li class="{"oui" if a in al else ""}">{e(nom_allergene(a, lg))}<span>{e(t["contient"]) if a in al else "—"}</span></li>'
        for a in CODES)
    return (f'<div class="blk"><span class="eyebrow">{e(t["allergenes"])}</span>{tete}'
            f'<details class="g14"><summary>{e(t["g14"])}</summary><ul>{g}</ul></details></div>')


def texte_long(s: str, lg: str) -> str:
    """Un texte de la fiche technique, tel quel : en liste s'il est numéroté (« 1. … 2. … »), replié au-delà de
    quatre lignes (« Lire la suite »). Les textes du panel ne sont qu'en français : en néerlandais, lang="fr"."""
    parts = [x.strip() for x in re.split(r'\s(?=\d{1,2}\.\s)', s) if x.strip()]
    corps = ('<ol class="num">' + ''.join(f'<li>{e(re.sub(r"^\d{1,2}\.\s*", "", x))}</li>' for x in parts) + '</ol>'
             if len(parts) > 1 else f'<p>{e(s)}</p>')
    attr = ' lang="fr"' if lg != 'fr' else ''
    if len(s) < 260:
        return f'<div class="how__d"{attr}>{corps}</div>'
    return (f'<details class="long"{attr}><summary><div class="how__d">{corps}</div>'
            f'<span class="plus">{e(T[lg]["lire"])}</span></summary></details>')


def bloc_cuisson(l: dict, lg: str) -> str:
    """Le réchauffage (reheating_* du produit) et les compétences de cuisson de la fiche technique."""
    t, ft = T[lg], l['ft']
    items = ''
    if ft.get('reheating_temperature_celsius') or ft.get('reheating_time_minutes'):
        val = f'{ft["reheating_temperature_celsius"]}{NBSP}°C' if ft.get('reheating_temperature_celsius') else ''
        duree = f'{ft["reheating_time_minutes"]}{NBSP}min' if ft.get('reheating_time_minutes') else ''
        items += (f'<div class="how__i"><span class="how__ic">{ic("four")}</span><div><p class="how__t">{e(t["rechauffer"])}</p>'
                  f'<p class="how__big">{val}<span>· {duree}</span></p></div></div>')
    for c in ft['competences']:
        if 'cuisson' in c['nom'].lower() and c['description']:
            items += (f'<div class="how__i"><span class="how__ic">{ic("four")}</span><div><p class="how__t">{e(t["nous_cuisons"])}</p>'
                      f'{texte_long(c["description"], lg)}</div></div>')
    if not items:
        return ''
    return f'<div class="blk"><span class="eyebrow">{e(t["cuisson"])}</span><div class="how">{items}</div></div>'


def bloc_finition(l: dict, lg: str) -> str:
    """Les étapes de préparation de la fiche technique, telles quelles."""
    etapes = l['ft']['etapes']
    if not etapes:
        return ''
    attr = ' lang="fr"' if lg != 'fr' else ''
    li = ''.join(f'<li>{e(s)}</li>' for s in etapes)
    return f'<div class="blk"><span class="eyebrow">{e(T[lg]["finition"])}</span><ol class="steps"{attr}>{li}</ol></div>'


def bloc_conservation(l: dict, lg: str) -> str:
    t, ft = T[lg], l['ft']
    _, _, _, long_ = l['limite'][lg]
    m = re.match(r'\s*(-?\d+)\s*°C\s*[–-]\s*(-?\d+)\s*°C', ft.get('storage_description') or '')
    temp = (f'<p class="blk__txt">{e(t["entre"].format(a=f"{m.group(1)}{NBSP}°C", b=f"{m.group(2)}{NBSP}°C"))}</p>'
            if m else '')
    return (f'<div class="blk"><span class="eyebrow">{e(t["conservation"])}</span><div class="keep">{temp}'
            f'<p class="until">{ic("horloge")}<span>{e(t["a_deguster"].format(quand=long_))}</span></p></div></div>')


def fiche(k: int, tk: dict, l: dict, lg: str) -> str:
    t, b, ft = T[lg], tk['b'], l['ft']
    _, urgent, court, _ = l['limite'][lg]
    tags = [f'<li class="tag{" tag--now" if urgent else ""}">{ic("horloge")}{e(court)}</li>']
    if ft.get('is_vegetarian'):
        tags.append(f'<li class="tag tag--abr">{ic("feuille")}{e(t["vege"])}</li>')
    if ft.get('single_weight'):
        tags.append(f'<li class="tag">{ic("poids")}{ft["single_weight"]}{NBSP}g</li>')
    qte = (f'{l["qte"]} × {euros(l["prix"], lg)} · {euros(l["total"], lg)}' if l['qte'] > 1
           else euros(l['prix'], lg))
    vignette = (f'style="background-image:url(\'{e(l["image"])}\')"' if l['image'] else 'data-vide="1"')
    cta = ''
    if ft.get('webshop_active'):
        lien = f'{b["webshop"]}&product={l["id"]}&open=product&lang={lg}'
        cta = f'<div class="prod__cta"><a class="btn" href="{e(lien)}">{e(t["recommander"])} {ic("fleche")}</a></div>'
    return f"""
<article class="prod" id="p-{l['id']}" aria-roledescription="fiche" aria-label="{k + 1} {t['sur']} {len(tk['L'])} : {e(l['nom'][lg])}">
  <header class="prod__head">
    <span class="th prod__th" role="img" aria-label="{e(l['nom'][lg])}" {vignette}><span class="prod__qty">×{l['qte']}</span></span>
    <div><p class="eyebrow eyebrow--ruby">{e(l['famille'][lg])}</p><h3 class="prod__name">{e(l['nom'][lg])}</h3><p class="prod__px">{qte}</p></div>
  </header>
  <ul class="tags">{''.join(tags)}</ul>
  {bloc_composition(l, lg)}
  {bloc_allergenes(l, lg)}
  {bloc_cuisson(l, lg)}
  {bloc_finition(l, lg)}
  {bloc_conservation(l, lg)}
  {cta}
</article>"""


def fichier_page(tk: dict, lg: str) -> str:
    return f'{tk["page"]}-{lg}.html'


def page(tk: dict, lg: str) -> str:
    t, b, L, vente = T[lg], tk['b'], tk['L'], tk['vente']
    nom = lambda l: l['nom'][lg]  # noqa: E731
    vign = lambda l: f'style="background-image:url(\'{e(l["image"])}\')"' if l['image'] else 'data-vide="1"'  # noqa: E731
    n_art = sum(l['qte'] for l in L)
    total = sum(l['total'] for l in L)
    num = f'{int(tk["transaction"]):,}'.replace(',', NBSP)
    jour = f'{maj(jour_long(vente, lg))} {vente.year}'
    quand = f'{jour} · {heure(vente, lg)}'

    rows = ''.join(
        f'<a class="row" href="#p-{l["id"]}"><span class="th" {vign(l)}></span>'
        f'<span><b>{e(nom(l))}</b><small>{l["qte"]} × {euros(l["prix"], lg)}</small></span>'
        f'<em>{euros(l["total"], lg)}</em>{ic("fleche")}</a>' for l in L)

    # Allergènes du ticket : chaque allergène présent, avec les produits qui le portent.
    presents = [(a, [l for l in L if a in l['allergenes']]) for a in CODES]
    presents = [x for x in presents if x[1]]
    alg = ''.join(
        f'<div class="alg-r"><span class="chip">{e(nom_allergene(a, lg))}</span><p>{e(", ".join(nom(l) for l in ls))}</p></div>'
        for a, ls in presents)
    absents = sorted(nom_allergene(a, lg).lower() for a in CODES if a not in {x[0] for x in presents})
    travailles = [nom_allergene(a, lg).lower() for a in TRAVAILLES]

    # Ordre de fraîcheur : regroupé par date limite dite au client.
    groupes: dict[str, list] = {}
    for l in sorted(L, key=lambda x: x['limite'][lg][0]):
        groupes.setdefault(l['limite'][lg][3], []).append(l)
    tl = ''
    for k, (qd, ls) in enumerate(groupes.items()):
        noms = ', '.join(f'{nom(x)}{" × " + str(x["qte"]) if x["qte"] > 1 else ""}' for x in ls)
        tl += f'<li class="{"now" if k == 0 else ""}"><b>{e(maj(qd))}</b><span>{e(noms)}</span></li>'

    picks = ''.join(
        f'<button type="button" class="pick__b{" on" if k == 0 else ""}" aria-controls="p-{l["id"]}">'
        f'<span class="th" {vign(l)}></span>{e(nom(l))}'
        f'{"<em>×" + str(l["qte"]) + "</em>" if l["qte"] > 1 else ""}</button>' for k, l in enumerate(L))
    points = ''.join(f'<i class="{"on" if k == 0 else ""}"></i>' for k in range(len(L)))
    fiches = ''.join(fiche(k, tk, l, lg) for k, l in enumerate(L))
    langues = ''.join(
        f'<span class="on">{x.upper()}</span>' if x == lg else
        f'<a href="{fichier_page(tk, x)}" hreflang="{x}" lang="{x}">{x.upper()}</a>' for x in ('fr', 'nl'))
    horaires = (f'<dt>{e(t["horaires"])}</dt><dd>{e(b["horaires"])}</dd>' if b.get('horaires') else '')
    css = CSS % {'p': POLICES}
    return f"""<!DOCTYPE html>
<html lang="{lg}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#FCFBF8">
<title>{e(t['titre'])}</title>
<style>{css}</style>
</head>
<body>
<header class="nav wrap">
  <a class="nav__logo" href="https://atelierby.be/"><img src="{SITE}img/brand/logo.png" alt="{e(t['logo'])}"></a>
  <div class="lang" aria-label="Langue · Taal">{langues}</div>
</header>
<main>
  <section class="head wrap">
    <p class="eyebrow eyebrow--ruby">{e(t['sur_titre'].format(ville=b['ville']))}</p>
    <h1>{e(t['h1'])}<span class="script">{e(t['h1_script'])}</span></h1>
    <p class="head__lede">{e(t['chapeau'])}</p>
    <div class="meta"><span>{ic('horloge')}{e(quand)}</span><span>{e(t['articles'].format(n=n_art))} · {euros(total, lg)}</span></div>
  </section>

  <div class="sand">
    <div class="wrap top">
      <section class="card">
        <p class="eyebrow">{e(t['ticket'].format(num=num))}</p>
        <h2 class="card__t">{e(t['ticket_t'].format(p=len(L), n=n_art))}</h2>
        <div class="rows">{rows}</div>
        <div class="total"><span>{e(t['total'])}</span><b>{euros(total, lg)}</b></div>
        <p class="fine">{e(b['nom'])} – {e(b['ville'])} · {e(tk['caisse'])} · {e(quand)}</p>
      </section>
      <div class="stack">
        <section class="card">
          <p class="eyebrow eyebrow--ruby">{e(t['alg'])}</p>
          <h2 class="card__t">{e(t['alg_t'])}</h2>
          <div class="alg-l">{alg}</div>
          <p class="sans">{t['sans'].format(liste=e(', '.join(absents)))}</p>
          <p class="note">{ic('alerte')}<span>{e(t['traces'].format(liste=liste(travailles, lg)))}</span></p>
        </section>
        <section class="card">
          <p class="eyebrow">{e(t['fraich'])}</p>
          <h2 class="card__t">{e(t['fraich_t'])}</h2>
          <ul class="tl">{tl}</ul>
        </section>
      </div>
    </div>

    <section id="fiches" class="fiches">
      <div class="wrap chap">
        <p class="eyebrow">{e(t['fiches'])}</p>
        <h2 class="h2">{e(t['h2'])}<span class="script">{e(t['h2_script'])}</span></h2>
        <p class="chap__lede"><span class="mobile">{e(t['fiches_p'])}</span><span class="bureau">{e(t['fiches_p_bureau'])}</span></p>
      </div>
      <nav class="pick" aria-label="{e(t['barre'])}"><div class="pick__in">{picks}</div></nav>
      <div class="ctrl">
        <p class="ctrl__n"><b>1</b><span> / {len(L)}</span></p>
        <div class="ctrl__dots" aria-hidden="true">{points}</div>
        <button type="button" class="arr arr--prev" aria-label="{e(t['prec'])}" disabled>{ic('gauche')}</button>
        <button type="button" class="arr arr--next" aria-label="{e(t['suiv'])}">{ic('fleche')}</button>
      </div>
      <div class="track" id="track" tabindex="0" aria-roledescription="carrousel" aria-label="{e(t['carrousel'])}">{fiches}</div>
    </section>

    <div class="wrap end">
      <section class="abr">
        <p class="eyebrow eyebrow--ruby">{e(t['avis'])}</p>
        <h2 class="card__t">{e(t['avis_t'].format(ville=b['ville']))}</h2>
        <p class="card__p">{e(t['avis_p'])}</p>
        <div class="stars">{ic('etoile') * 5}</div>
        <a class="btn" href="#">{e(t['avis_b'])} {ic('fleche')}</a>
      </section>
      <section class="card">
        <p class="eyebrow">{e(t['app'])}</p>
        <h2 class="card__t">{e(t['app_t'])}</h2>
        <p class="card__p">{e(t['app_p'])}</p>
        <ul class="dots">{''.join(f'<li>{x}</li>' for x in t['app_l'])}</ul>
        <a class="link" href="#">{e(t['app_b'])} {ic('fleche')}</a>
      </section>
      <section class="card shop">
        <p class="eyebrow">{e(t['shop'])}</p>
        <h2 class="card__t">{e(b['nom'])} – {e(b['ville'])}</h2>
        <dl><dt>{e(t['adresse'])}</dt><dd>{e(b['rue'])}, {e(b['cp'])} {e(b['ville'])}</dd>{horaires}<dt>{e(t['tel'])}</dt><dd>{e(b['tel'])}</dd></dl>
        <a class="link" href="{e(b['webshop'])}&lang={lg}">{e(t['commander'])} {ic('fleche')}</a>
      </section>
    </div>
  </div>

  <footer class="foot wrap">
    <p>{t['pied']}</p>
    <p>{e(t['pied2'].format(an=vente.year))}</p>
  </footer>
</main>
<script>{JS}</script>
</body>
</html>
"""


# --------------------------------------------------------------------------------------------------------------------
# Le ticket de caisse et son QR, dans la langue de la boutique
# --------------------------------------------------------------------------------------------------------------------

def qr_svg(tk: dict) -> str:
    chemin = os.path.join(ICI, f'qr-{tk["page"]}.svg')
    if not os.path.exists(chemin):
        import qrcode  # une seule fois, pour produire le fichier
        import qrcode.image.svg
        img = qrcode.make(tk['adresse_qr'], image_factory=qrcode.image.svg.SvgPathImage, box_size=10, border=0)
        img.save(chemin)
    with open(chemin, encoding='utf-8') as f:
        s = f.read()
    return s[s.find('<svg'):]


def ticket(tk: dict) -> str:
    b, L, vente = tk['b'], tk['L'], tk['vente']
    lg = b['langue']
    t = T[lg]
    total = sum(l['total'] for l in L)
    tva = total - total / 1.06
    mt = lambda x: f'{x:.2f}'.replace('.', ',')  # noqa: E731
    lignes = ''.join(
        f'<tr><td>{l["qte"]} x {e(l["nom"][lg])}</td><td>{mt(l["prix"]) if l["qte"] > 1 else ""}</td>'
        f'<td>{mt(l["total"])}</td></tr>' for l in L)
    court = tk['adresse_qr'].replace('https://', '')
    return f"""<!DOCTYPE html>
<html lang="{lg}"><head><meta charset="utf-8"><title>Transaction {e(tk['transaction'])} — QR</title>
<style>
@font-face{{font-family:'Gotham';src:url('{POLICES}Gotham_Regular.otf') format('opentype');font-weight:600}}
body{{margin:0;background:#EAE4DC;display:flex;justify-content:center;padding:36px 0;font-family:ui-monospace,'DejaVu Sans Mono',Menlo,monospace;color:#1d1c1a}}
.t{{width:330px;background:#fff;padding:26px 22px 30px;font-size:12px;line-height:1.5;
  -webkit-mask:linear-gradient(#000 0 0) top/100% calc(100% - 8px) no-repeat,radial-gradient(circle at 6px 8px,transparent 5px,#000 5.5px) bottom/12px 8px repeat-x;
  mask:linear-gradient(#000 0 0) top/100% calc(100% - 8px) no-repeat,radial-gradient(circle at 6px 8px,transparent 5px,#000 5.5px) bottom/12px 8px repeat-x}}
.c{{text-align:center}}
.logo{{display:block;width:170px;margin:0 auto 12px;filter:grayscale(1) contrast(1.4)}}
hr{{border:0;border-top:1px dashed #9a948c;margin:10px 0}}
table{{width:100%;border-collapse:collapse}}td{{padding:1px 0;vertical-align:top}}td:nth-child(2){{text-align:right;padding-right:10px;color:#555}}td:last-child{{text-align:right;white-space:nowrap}}
.tot td{{font-size:15px;font-weight:700;padding-top:4px}}
.qr{{width:150px;height:150px;margin:14px auto 10px}}.qr svg{{width:100%;height:100%}}
.scan{{font-family:'Gotham',sans-serif;font-weight:600;font-size:13px;line-height:1.35;margin:0}}
.url{{font-size:11px;color:#444;margin-top:4px;word-break:break-all}}
</style></head><body>
<div class="t">
  <div class="c"><img class="logo" src="{SITE}img/brand/logo.png" alt="L’Atelier By">
  {e(b['nom'])} – {e(b['ville'])}<br>{e(b['rue'])} · {e(b['cp'])} {e(b['ville'])}<br>{t['r_tel']} {e(b['tel'])}</div>
  <hr>
  <table><tr><td>{t['r_trans']} {e(tk['transaction'])}</td><td></td><td>{vente:%d/%m/%Y %H:%M}</td></tr><tr><td>{e(tk['caisse'])}</td><td></td><td></td></tr></table>
  <hr>
  <table>{lignes}</table>
  <hr>
  <table><tr class="tot"><td>{t['r_total']}</td><td></td><td>{mt(total)}</td></tr>
  <tr><td>{t['r_tva']}</td><td></td><td>{mt(tva)}</td></tr></table>
  <hr>
  <div class="c"><div class="qr">{qr_svg(tk)}</div>
  <p class="scan">{t['r_scan']}</p>
  <p class="url">{e(court)}</p>
  <p style="margin:10px 0 0">{e(t['r_merci'])}</p></div>
</div>
</body></html>
"""


def main() -> None:
    _, tickets = charger()
    for tk in tickets:
        sorties = [(fichier_page(tk, lg), page(tk, lg)) for lg in ('fr', 'nl')]
        sorties.append((f'ticket-{tk["page"]}.html', ticket(tk)))
        for nom, contenu in sorties:
            with open(os.path.join(ICI, nom), 'w', encoding='utf-8') as f:
                f.write(contenu)
            print(nom, len(contenu), 'octets')


if __name__ == '__main__':
    main()
