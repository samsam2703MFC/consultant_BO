#!/usr/bin/env python3
"""Rapport magasin — Sombreffe, comptoir et B2B, mai 2025 → octobre 2026 (les 18 mois pleins depuis l'ouverture).

Écrit `rapport.html` à côté de ce script. Aucun chiffre n'est retapé quand une source le donne :
- les mois et les semaines viennent de `docs/maquettes/evolution-magasin/donnees.json`, lus au panel le 11/11/2026
  (`GET /ventes/evolution`, `GET /ventes/semaines`) ;
- le bloc « Évolution magasin » (faits, tuiles, novembre en cours, part B2B) est celui du cockpit, vue « Tout »,
  relevé le 13/11/2026 et collé par Sam : ses valeurs sont reprises telles quelles ci-dessous (COCKPIT).

    python3 docs/dossiers/sombreffe-2026-11/generer.py

La page charge Gotham dans `fonts/` (Gotham_Medium.otf en 400, Gotham_Regular.otf en 700, docs/DESIGN_SYSTEM.md §4.1) :
pour la publier, joindre les deux fichiers de `public/assets/ds/fonts/` sous ce chemin. Ouverte telle quelle depuis le
dépôt, elle tombe sur Helvetica.
"""
import csv
import json
import re
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path

ICI = Path(__file__).resolve().parent
SOURCE = ICI.parent.parent / 'maquettes' / 'evolution-magasin' / 'donnees.json'
# Les temps morts : 8 semaines (07/09 → 01/11/2026), lues au panel le 07/11/2026 (docs/maquettes/temps-morts/LISEZMOI.md).
TEMPS_MORTS = ICI.parent.parent / 'maquettes' / 'temps-morts' / 'data.json'
TM_SEUIL = '10'                         # un temps mort : un trou d'au moins 10 minutes entre deux tickets
# Les checklists : la nomenclature unique (src/checklists_codes.php) et un relevé réel des tâches du jeudi 01/10/2026
# (docs/maquettes/controle-taches-photos/donnees.json, lu par /pwa/tasks?date=).
CHECKLISTS = ICI.parent.parent.parent / 'src' / 'checklists_codes.php'
TACHES_JOUR = ICI.parent.parent / 'maquettes' / 'controle-taches-photos' / 'donnees.json'
# Les données que le bureau dépose à côté de ce script, si elles existent (sinon le rapport les marque « à compléter ») :
# - pnl-mois.json : la réponse de GET /api/cockpit/analyse/pnl/mois?shop=5&du=2025-05&au=2026-10 (P&L du panel par mois) ;
# - b2b-clients.csv : l'export de la requête des clients B2B année par année (LISEZMOI.md).
PNL_MOIS = ICI / 'pnl-mois.json'
B2B_CLIENTS = ICI / 'b2b-clients.csv'
# - ventes-evolution.json : la réponse de GET /api/cockpit/ventes/evolution (le B2B de chaque mois et de son N-1).
EVOLUTION_PRO = ICI / 'ventes-evolution.json'
# - b2b-detail.json : le B2B par société et par mois, regroupé par regrouper_b2b.py depuis /exploitation/pro/detail.
#   Il prime sur /ventes/evolution pour les mois qu'il couvre en entier : relu ticket par ticket, il corrige les mois
#   que le relevé gravé du cockpit compte à zéro (août et septembre 2026, constaté le 13/11/2026).
B2B_DETAIL = ICI / 'b2b-detail.json'
SHOP = '5'

# Le bloc du cockpit (Résultat › Évolution magasin, ventes : Tout), relevé le 13/11/2026.
COCKPIT = {
    'releve': '13/11/2026',
    'faits': 'depuis avril 2025 (premières ventes, 6 j) · 18 mois pleins · 83 863 clients · 983,4 k€ de CA · '
             'panier 11,73 € · dont B2B 5,9 % sur 12 mois · meilleur mois : décembre 2025 (62,4 k€)',
    'partB2b': 5.9,                      # part du CA des 12 derniers mois clos (nov. 25 → oct. 26), clients pro à TVA valide
    'tuiles': [
        {'k': '6 mois vs N-1', 'v': '−1 %', 's': '313,2 k€ contre 315,6 k€ · mai 26 → oct. 26', 'cls': 'bad'},
        {'k': '3 derniers mois vs N-1', 'v': '+3 %', 's': 'août → oct. : 161,1 k€ contre 157,0 k€', 'cls': 'good'},
        {'k': 'Mois au-dessus du N-1', 'v': '3 / 6', 's': 'juin 26, sept. 26, oct. 26', 'cls': ''},
        {'k': 'nov. en cours · 13 j', 'v': '26,8 k€', 's': '+5 % face aux mêmes 13 jours de novembre 2025 (25,5 k€)', 'cls': 'good'},
    ],
}
PLAFOND_B2B = 40.0                       # plafond de la part B2B fixé aux visites
SEUIL_PERSONNEL = 33.0                   # main-d'œuvre en % du CA : le repli du cockpit (seuilsCharges, src/endpoints.php)

MOIS_C = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']
MOIS_L = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']


def arr(x, n=0):
    """Arrondi commercial (0,5 vers le haut), pas l'arrondi bancaire de Python."""
    q = Decimal(1).scaleb(-n)
    return float(Decimal(str(x)).quantize(q, rounding=ROUND_HALF_UP))


def pc(a, b):
    return None if not b else (a / b - 1) * 100


def main():
    d = json.loads(SOURCE.read_text(encoding='utf-8'))
    mag = next(x for x in d['magasins'] if x['id'] == SHOP)
    mois = {x['mois']: x for x in mag['mois']}

    # Le B2B de chaque mois (et de son N-1), si la réponse du cockpit est déposée : la règle du cockpit, un mois dont le
    # B2B n'est pas lu sur tous ses jours vendus sort des courbes comptoir et B2B.
    pro = {}
    if EVOLUTION_PRO.exists():
        ev = json.loads(EVOLUTION_PRO.read_text(encoding='utf-8'))
        m5 = next((x for x in ev.get('magasins', []) if str(x.get('id')) == SHOP), None)
        for x in (m5 or {}).get('mois', []):
            def lu(p, jours):
                return {'ca': p['ca'], 'clients': p['clients'], 'complet': int(p.get('joursLus') or 0) >= int(jours or 0)} if p else None
            n1 = x.get('n1') or {}
            pro[x['mois']] = {'n': lu(x.get('pro'), x.get('jours')), 'n1': lu(n1.get('pro'), n1.get('jours')) if n1 else None}
    detail = json.loads(B2B_DETAIL.read_text(encoding='utf-8')) if B2B_DETAIL.exists() else None
    if detail:
        for ym, m in detail['mois'].items():
            if m['jours'] >= m['joursMois']:
                pro.setdefault(ym, {'n': None, 'n1': None})['n'] = {'ca': m['ca'], 'clients': m['tickets'], 'complet': True, 'source': 'detail'}
    # Les mois du graphique : de janvier 2025 (avant l'ouverture) à octobre 2026.
    serie = []
    for an in (2025, 2026):
        for mo in range(1, 13):
            k = f'{an}-{mo:02d}'
            if k > '2026-10':
                break
            x = mois.get(k)
            n1 = (x or {}).get('n1') if k >= '2026-05' else None   # le N-1 se compare dès mai 2026 (avril 2025 : 6 jours)
            serie.append({
                'k': k, 'lab': MOIS_C[mo - 1], 'an': an,
                'ca': x['ca'] if x else None, 'clients': x['clients'] if x else None, 'jours': x['jours'] if x else None,
                'ouverture': bool(x) and k == '2025-04',
                'n1': {'ca': n1['ca'], 'clients': n1['clients'], 'jours': n1['jours']} if n1 else None,
                'pro': (pro.get(k) or {}).get('n'), 'n1pro': (pro.get(k) or {}).get('n1') if n1 else None,
            })

    # La période : mai 2025 → octobre 2026, les 18 mois pleins (avril 2025, mois d'ouverture, n'a que 6 jours).
    periode = [k for k in sorted(mois) if '2025-05' <= k <= '2026-10']
    lignes = []
    for k in periode:
        x = mois[k]; n1 = x.get('n1') if k >= '2026-05' else None
        an, mo = int(k[:4]), int(k[5:])
        l = {'k': k, 'lab': f'{MOIS_C[mo - 1]} {str(an)[2:]}', 'jours': x['jours'], 'clients': x['clients'], 'ca': x['ca'],
             'cliJour': x['clients'] / x['jours'], 'caJour': x['ca'] / x['jours'], 'panier': x['ca'] / x['clients'],
             'vsCa': None, 'vsCli': None, 'vsPanier': None, 'vsCaJour': None, 'n1Fermes': False}
        if n1:
            l['vsCa'] = pc(x['ca'], n1['ca']); l['vsCli'] = pc(x['clients'], n1['clients'])
            l['vsPanier'] = pc(x['ca'] / x['clients'], n1['ca'] / n1['clients'])
            l['vsCaJour'] = pc(x['ca'] / x['jours'], n1['ca'] / n1['jours'])
            # La règle du cockpit (app.js, n1Court) : le N-1 est « court » s'il a plus de 2 jours de moins.
            l['n1Fermes'] = n1['jours'] < x['jours'] - 2
        lignes.append(l)

    def somme(ks):
        ca = sum(mois[k]['ca'] for k in ks); cl = sum(mois[k]['clients'] for k in ks); j = sum(mois[k]['jours'] for k in ks)
        return {'ca': ca, 'clients': cl, 'jours': j, 'panier': ca / cl, 'caJour': ca / j, 'cliJour': cl / j}

    total = somme(periode)
    trimestres = [
        ('mai → juin 25', ['2025-05', '2025-06']),
        ('juil. → sept. 25', ['2025-07', '2025-08', '2025-09']),
        ('oct. → déc. 25', ['2025-10', '2025-11', '2025-12']),
        ('janv. → mars 26', ['2026-01', '2026-02', '2026-03']),
        ('avr. → juin 26', ['2026-04', '2026-05', '2026-06']),
        ('juil. → sept. 26', ['2026-07', '2026-08', '2026-09']),
        ('oct. 26', ['2026-10']),
    ]
    trim = [dict(lab=lab, **somme(ks)) for lab, ks in trimestres]
    # Les six mois présents dans les deux années : mai → octobre 2025 face à mai → octobre 2026.
    comparable = {'n1': somme(['2025-05', '2025-06', '2025-07', '2025-08', '2025-09', '2025-10']),
                  'n': somme(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'])}

    # Le B2B : la part du cockpit appliquée au CA des 12 mois qu'elle couvre (nov. 25 → oct. 26).
    douze = [k for k in sorted(mois) if '2025-11' <= k <= '2026-10']
    ca12 = sum(mois[k]['ca'] for k in douze)
    b2b12 = ca12 * COCKPIT['partB2b'] / 100
    # Corrigée si le B2B des 12 mois est lu en entier (copie de caisse, puis détail jour par jour) : le cockpit compte
    # août et septembre 2026 à zéro alors que les tickets du panel en portent (constaté le 13/11/2026).
    lus12 = [(pro.get(k) or {}).get('n') for k in douze]
    b2bCorrige = all(p and p['complet'] for p in lus12)
    partB2b = COCKPIT['partB2b']
    if b2bCorrige:
        b2b12 = sum(p['ca'] for p in lus12); partB2b = b2b12 / ca12 * 100
    # L'écart de septembre-octobre : le CA réel moins celui qu'auraient fait les mêmes clients au panier du N-1.
    ecarts = []
    for k in ('2026-09', '2026-10'):
        x = mois[k]; n1 = x['n1']
        ecarts.append(x['ca'] - x['clients'] * n1['ca'] / n1['clients'])
    ecart = sum(ecarts)
    panier12 = ca12 / sum(mois[k]['clients'] for k in douze)

    # Les dix dernières semaines (la 46e en cours, 3 jours au 11/11).
    semaines = []
    for w in mag['semaines'][-10:]:
        n1 = w['n1']
        semaines.append({'lab': w['lab'], 'du': w['du'], 'au': w['au'], 'enCours': w['enCours'], 'jours': w['jours'],
                         'clients': w['clients'], 'ca': w['ca'], 'panier': w['ca'] / w['clients'],
                         'n1ca': n1['ca'], 'n1panier': n1['ca'] / n1['clients'],
                         'vsCa': pc(w['ca'], n1['ca']), 'vsCli': pc(w['clients'], n1['clients'])})

    # Le point de départ des soins intensifs (lundi 16/11/2026) : les quatre dernières semaines complètes, face au N-1.
    pleines = [w for w in mag['semaines'] if not w['enCours'] and w['jours'] == 7 and w['n1']][-4:]
    def rep(ws, cle):
        ca = sum((w[cle] if cle else w)['ca'] for w in ws); cl = sum((w[cle] if cle else w)['clients'] for w in ws)
        j = sum((w[cle] if cle else w)['jours'] for w in ws)
        return {'ca': ca, 'clients': cl, 'jours': j, 'cliJour': cl / j, 'caJour': ca / j, 'panier': ca / cl}
    depart = {'du': pleines[0]['du'], 'au': pleines[-1]['au'], 'labs': [w['lab'] for w in pleines],
              'n': rep(pleines, None), 'n1': rep(pleines, 'n1')}

    # Les temps morts, seuil 10 min : par jour de semaine (0 = lundi), par heure, et le croisement des deux.
    tmD = json.loads(TEMPS_MORTS.read_text(encoding='utf-8'))
    tm = tmD['magasins'][SHOP]
    def tmJour(j, bords=None):
        t = j['seuils'][TM_SEUIL]
        return {'nb': t['nb'], 'min': t['min'], 'pct': t['pct'], 'minBords': t['minBords'], 'tickets': j['tickets'],
                'ouvert': j['ouvert'], 'plusLong': j['plusLong'], 'plusLongHeure': j.get('plusLongHeure'), 'bords': bords,
                'heures': {h: {'pct': v['pct'], 'min': v['min'], 'nb': v['nb'], 'tk': j['heures'][h]['tk'], 'ca': j['heures'][h]['ca'],
                               'ouvert': j['heures'][h]['ouvert']} for h, v in t['heures'].items()}}
    tempsMorts = {
        'du': tmD['meta']['du'], 'au': tmD['meta']['au'], 'semaines': tmD['meta']['semaines'], 'seuil': int(TM_SEUIL),
        'luLe': tmD['meta']['source'].rsplit('lu le ', 1)[-1],
        'semaine': tmJour(tm['semaine']),
        'jours': [tmJour(tm['jours'][str(i)], tm['bords'][str(i)]) for i in range(7)],
        'reseau': [{'nom': m['nom'], 'shop': sid, 'nb': m['semaine']['seuils'][TM_SEUIL]['nb'],
                    'min': m['semaine']['seuils'][TM_SEUIL]['min'], 'pct': m['semaine']['seuils'][TM_SEUIL]['pct'],
                    'tickets': m['semaine']['tickets']} for sid, m in tmD['magasins'].items()],
    }
    # L'après-midi (14 h → 18 h) : la part sans client, pondérée par les minutes ouvertes, et les tickets par heure.
    hs = tempsMorts['semaine']['heures']; apm = [h for h in ('14', '15', '16', '17') if h in hs]
    tempsMorts['apresMidi'] = {'pct': sum(hs[h]['min'] for h in apm) / sum(hs[h]['ouvert'] for h in apm) * 100,
                               'tk': sum(hs[h]['tk'] for h in apm) / len(apm), 'ca': sum(hs[h]['ca'] for h in apm) / len(apm),
                               'heures': len(apm)}

    # Les checklists de la journée : code, nom, heure, lus dans la source unique ; les moments 5 et 6 sont la semaine et le mois.
    ck = []
    for m in re.finditer(r"\['code' => '(\d\.\d\.\d{2})', 'fr' => '((?:[^'\\]|\\.)*)'.*?'heure' => (null|'[\d:]+')", CHECKLISTS.read_text(encoding='utf-8')):
        code, nom, heure = m.group(1), m.group(2).replace("\\'", "'"), m.group(3).strip("'")
        ck.append({'code': code, 'nom': nom, 'heure': None if heure == 'null' else heure, 'moment': int(code[2])})
    jour = sorted([c for c in ck if c['moment'] <= 4], key=lambda c: (c['heure'] or '99', c['code']))
    # Le relevé des tâches du 01/10/2026, magasin par magasin : rendues, obligatoires rendues, photos.
    tj = json.loads(TACHES_JOUR.read_text(encoding='utf-8'))
    releve = [{'shop': str(x['shopId']), 'nom': x['shop'].split(' - ')[-1], 'taches': len(x['taches']),
               'rendues': sum(t['statut'] != 'nonRendue' for t in x['taches']),
               'obligatoires': sum(bool(t['obligatoire']) for t in x['taches']),
               'obligatoiresRendues': sum(bool(t['obligatoire']) and t['statut'] != 'nonRendue' for t in x['taches']),
               'photos': sum(bool(t.get('photo')) for t in x['taches'])} for x in tj['shops']]
    taches = {'journee': jour, 'semaineMois': [c for c in ck if c['moment'] >= 5], 'releve': releve, 'releveLe': tj['date'],
              'seuil': tj['seuil']}

    # Les frais de personnel face au CA, mois par mois, dans le P&L du panel (s'il a été déposé).
    personnel = None
    if PNL_MOIS.exists():
        pm = json.loads(PNL_MOIS.read_text(encoding='utf-8'))
        m5 = next((x for x in pm.get('magasins', []) if str(x.get('id')) == SHOP), None)
        if m5:
            lignesP = []
            for x in m5['mois']:
                if x.get('indispo') or not x.get('ca'):
                    lignesP.append({'mois': x['mois'], 'indispo': True}); continue
                lignesP.append({'mois': x['mois'], 'ca': x['ca'], 'labour': x['labour'], 'labourPct': x.get('labourPct'),
                                'overhead': x.get('overhead'), 'overheadPct': x.get('overheadPct'),
                                'sansValeur': (x.get('sansValeur') or {}).get('labour', 0)})
            lus = [l for l in lignesP if not l.get('indispo')]
            ca_p = sum(l['ca'] for l in lus); lab_p = sum(l['labour'] for l in lus)
            personnel = {'du': pm.get('du'), 'au': pm.get('au'), 'source': pm.get('source'), 'mois': lignesP,
                         'cumul': {'mois': len(lus), 'ca': ca_p, 'labour': lab_p, 'labourPct': lab_p / ca_p * 100 if ca_p else None}}

    # Les clients B2B année par année (s'ils ont été déposés) : l'export de la requête, séparateur deviné.
    b2bClients = None
    if B2B_CLIENTS.exists():
        texte = B2B_CLIENTS.read_text(encoding='utf-8-sig')
        dialecte = csv.Sniffer().sniff(texte.splitlines()[0], delimiters=',;\t')
        rangs = list(csv.DictReader(texte.splitlines(), dialect=dialecte))
        num = lambda v: float(str(v).replace(',', '.')) if str(v or '').strip() not in ('', 'NULL') else 0.0
        colTva = next((c for c in (rangs[0].keys() if rangs else []) if re.search(r'vat|tva', c, re.I)), None)
        clients = []
        for r in rangs:
            nom = (r.get('societe') or '').strip()
            if not nom or nom == 'NULL':
                nom = ' '.join(x for x in ((r.get('firstname') or r.get('name') or ''), (r.get('lastname') or r.get('surname') or '')) if x and x != 'NULL').strip() or f"Client {r.get('id_client')}"
            clients.append({'id': r.get('id_client'), 'nom': nom, 'ca2025': num(r.get('ca_2025')), 'cmd2025': int(num(r.get('commandes_2025'))),
                            'ca2026': num(r.get('ca_2026')), 'cmd2026': int(num(r.get('commandes_2026'))), 'ca12': num(r.get('ca_12_mois')),
                            'total': num(r.get('ca_total')), 'premier': r.get('premier_achat'), 'dernier': r.get('dernier_achat'),
                            'tva': (r.get(colTva) or '').strip() if colTva else None})
        clients.sort(key=lambda c: -c['total'])
        b2bClients = {'clients': clients, 'colTva': colTva, 'ca12Magasin': ca12}

    # Le tableau des clients année par année, tiré du détail jour par jour quand il couvre 2025 (sans export CSV).
    if b2bClients is None and detail and any(ym.startswith('2025') for ym in detail['mois']):
        def somme(c, test):
            x = [v for ym, v in c['mois'].items() if test(ym)]
            return round(sum(v['ca'] for v in x), 2), sum(v['tickets'] for v in x)
        clientsA = []
        for c in detail['societes']:
            ca25, n25 = somme(c, lambda ym: ym.startswith('2025'))
            ca26, n26 = somme(c, lambda ym: ym.startswith('2026'))
            ca12_, _ = somme(c, lambda ym: '2025-11' <= ym <= '2026-10')
            clientsA.append({'id': c['cle'], 'nom': c['nom'], 'ca2025': ca25, 'cmd2025': n25, 'ca2026': ca26, 'cmd2026': n26,
                             'ca12': ca12_, 'total': c['total'], 'premier': c['premier'], 'dernier': c['dernier'], 'tva': None})
        b2bClients = {'clients': sorted(clientsA, key=lambda c: -c['total']), 'colTva': None, 'ca12Magasin': ca12, 'source': 'detail',
                      'du': min(detail['mois']), 'au': max(detail['mois'])}

    # Le panier moyen face aux autres magasins, sur les mois où les quatre sont ouverts (Halle depuis février 2026,
    # jusqu'au dernier mois clos), puis ce qu'auraient fait les clients de Sombreffe au panier le plus bas des autres.
    tous = {x['id']: {'nom': x['nom'].split(' - ')[-1], 'mois': {m['mois']: m for m in x['mois'] if not m.get('enCours')}}
            for x in d['magasins']}
    communs = [k for k in douze if all(k in m['mois'] for m in tous.values())]
    magasinsP = []
    for sid, m in tous.items():
        ca_ = sum(m['mois'][k]['ca'] for k in communs); cl_ = sum(m['mois'][k]['clients'] for k in communs)
        magasinsP.append({'shop': sid, 'nom': m['nom'], 'moi': sid == SHOP, 'ca': ca_, 'clients': cl_, 'panier': ca_ / cl_,
                          'cliJour': cl_ / sum(m['mois'][k]['jours'] for k in communs)})
    magasinsP.sort(key=lambda x: -x['panier'])
    bas = min((m for m in magasinsP if not m['moi']), key=lambda m: m['panier'])
    moisP = [{'k': k, 'lab': f"{MOIS_C[int(k[5:]) - 1]} {k[2:4]}", 'clients': mois[k]['clients'], 'ca': mois[k]['ca'],
              'panier': mois[k]['ca'] / mois[k]['clients'], 'caBas': mois[k]['clients'] * bas['panier'],
              'ecart': mois[k]['clients'] * bas['panier'] - mois[k]['ca']} for k in communs]
    panierAutres = {'du': communs[0], 'au': communs[-1], 'nbMois': len(communs), 'magasins': magasinsP,
                    'bas': {'nom': bas['nom'], 'panier': bas['panier']}, 'mois': moisP,
                    'clients': sum(l['clients'] for l in moisP), 'ca': sum(l['ca'] for l in moisP),
                    'caBas': sum(l['caBas'] for l in moisP), 'ecart': sum(l['ecart'] for l in moisP)}
    panierAutres['ecartAn'] = panierAutres['ecart'] / len(communs) * 12

    oct25, oct26 = mois['2025-10'], mois['2026-10']
    donnees = {
        'personnel': personnel, 'b2bClients': b2bClients, 'proLu': bool(pro), 'b2bDetail': detail, 'seuilPersonnel': SEUIL_PERSONNEL,
        'taches': taches, 'panierAutres': panierAutres,
        'tempsMorts': tempsMorts,
        'serie': serie, 'lignes': lignes, 'total': total, 'comparable': comparable, 'trim': trim, 'semaines': semaines, 'depart': depart,
        'soinsIntensifs': '2026-11-16',
        # La note du rédacteur, en fin d'annexes, dictée le 13/11/2026 : reprise mot pour mot, l'orthographe seule corrigée.
        'noteRedacteur': 'Privilégier une hygiène de vie saine et une activité physique par jour. '
                         'Dire qu’on ne trouve pas le temps, c’est une excuse.',
        # Le mot d'ouverture, tel que le bureau l'a écrit le 13/11/2026 (texte fourni, repris à l'identique).
        'ouverture': 'Nous sommes 110 dans ce groupe. Parmi nous, 67 chefs et responsables de famille, avec des crédits '
                     'et des engagements à tenir. Ce que chacun fait, ou ne fait pas, pèse sur les 109 autres. Pour que '
                     'ça tienne, chacun doit faire sa part. C’est pour eux qu’on remet les choses en place, aujourd’hui et maintenant.',
        # Les objectifs de clients par jour fixés par le bureau le 13/11/2026 : la base (départ + 5), l'objectif court terme,
        # puis l'objectif normalisé, le plan normal d'évolution du magasin.
        'objectifs': {'palier': 5, 'courtTerme': 156, 'normalise': 165, 'le': '13/11/2026'},
        # La participation de la marque à la relance (13/11/2026), et ses conditions telles que le bureau les a posées ;
        # la liste reste ouverte.
        'marque': {'le': '13/11/2026', 'conditions': [
            {'quoi': 'Les tâches et les checklists', 'comment': 'faites chaque jour, photos comprises', 'ou': 'dashboard magasin, onglet Tâches'},
            {'quoi': 'L’encodage des produits', 'comment': 'tous les produits encodés', 'ou': 'au panel'},
            {'quoi': 'Le suivi des règles du réseau', 'comment': 'appliquées et vérifiées', 'ou': 'en visite'}]},
        # Le constat de terrain du bureau (13/11/2026) : ce que les chiffres ne disent pas, noté tel quel.
        'constat': {'le': '13/11/2026', 'texte': 'Le magasin crée lui-même son creux de l’après-midi : il ne cuit plus l’après-midi.'},
        'cockpit': COCKPIT, 'plafond': PLAFOND_B2B,
        'b2b': {'part': partB2b, 'partCockpit': COCKPIT['partB2b'], 'corrige': b2bCorrige, 'ca12': ca12, 'b2b12': b2b12, 'comptoir12': ca12 - b2b12, 'parMois': b2b12 / 12,
                'ecart': ecart, 'ecartSept': ecarts[0], 'ecartOct': ecarts[1], 'partDeLAnnee': ecart / b2b12 * 100,
                'unPoint': ca12 / 100, 'panier12': panier12, 'unClientJour': panier12 * 365},
        'oct': {'cli25': oct25['clients'] / oct25['jours'], 'cli26': oct26['clients'] / oct26['jours'],
                'pan25': oct25['ca'] / oct25['clients'], 'pan26': oct26['ca'] / oct26['clients'],
                'vsCa': pc(oct26['ca'], oct25['ca']), 'vsCli': pc(oct26['clients'], oct25['clients']),
                'vsPan': pc(oct26['ca'] / oct26['clients'], oct25['ca'] / oct25['clients'])},
        'lu': d['lu'],
    }
    gabarit = (ICI / 'gabarit.html').read_text(encoding='utf-8')
    html = gabarit.replace('/*__DONNEES__*/null', json.dumps(donnees, ensure_ascii=False))
    (ICI / 'rapport.html').write_text(html, encoding='utf-8')

    # Contrôles à l'écran (pas dans le rapport).
    print(f"18 mois : {total['ca']:.0f} € · {total['clients']} clients · panier {total['panier']:.2f}")
    print(f"12 mois : {ca12:.0f} € · B2B ≈ {b2b12:.0f} € · écart sept.-oct. {ecart:.0f} € = {ecart / b2b12 * 100:.1f} % du B2B de l'année")
    print(f"panier au plus bas des autres : {panierAutres['caBas']:.0f} € contre {panierAutres['ca']:.0f} € → écart {panierAutres['ecart']:.0f} €")
    print('après-midi :', {k: round(v, 1) for k, v in tempsMorts['apresMidi'].items()})
    print('écarts vs N-1 :', [(s['k'], arr(pc(s['ca'], s['n1']['ca']))) for s in serie if s['n1']])


if __name__ == '__main__':
    main()
