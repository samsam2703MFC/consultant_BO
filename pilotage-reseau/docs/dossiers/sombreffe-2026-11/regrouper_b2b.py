#!/usr/bin/env python3
"""Le B2B de Sombreffe par société et par mois, depuis le détail jour par jour du cockpit.

Entrée : un dossier de réponses de GET /api/cockpit/exploitation/pro/detail?shop=5&date=AAAA-MM-JJ, une par jour
(`AAAA-MM-JJ.json`). Le cockpit y applique sa règle : un ticket est pro si le panel le marque client pro ET que la
fiche du client porte une TVA valide ; les autres restent au comptoir (`sansTva`). Le montant B2B est celui de la
caisse (`montant`) ; `montantApresRemise` (la remise personnelle d'un paiement différé) est gardé à côté.

Sortie : `b2b-detail.json` à côté de ce script, lu par generer.py : les mois, et pour chacun le B2B, les tickets
pro, les jours lus ; les sociétés, et pour chacune son CA et ses tickets par mois, son premier et son dernier achat.

    python3 docs/dossiers/sombreffe-2026-11/regrouper_b2b.py <dossier des jours>
"""
import calendar
import json
import sys
from collections import defaultdict
from pathlib import Path

ICI = Path(__file__).resolve().parent


def main(dossier: Path) -> None:
    mois = defaultdict(lambda: {'ca': 0.0, 'caApresRemise': 0.0, 'tickets': 0, 'jours': 0, 'caJour': 0.0, 'sansTvaCa': 0.0, 'sansTvaTickets': 0})
    soc = defaultdict(lambda: {'nom': '', 'mois': defaultdict(lambda: {'ca': 0.0, 'tickets': 0}), 'premier': None, 'dernier': None, 'factures': 0, 'differes': 0})
    for f in sorted(dossier.glob('20??-??-??.json')):
        d = json.loads(f.read_text(encoding='utf-8'))
        j = f.stem; ym = j[:7]
        if d.get('error') or d.get('jour') is None:
            continue                       # un jour sans lecture ne compte pas pour zéro
        m = mois[ym]
        m['jours'] += 1
        m['caJour'] += float(d['jour'].get('ca') or 0)
        m['sansTvaCa'] += float((d.get('sansTva') or {}).get('ca') or 0)
        m['sansTvaTickets'] += int((d.get('sansTva') or {}).get('tickets') or 0)
        for t in d.get('tickets') or []:
            montant = float(t.get('montant') or 0)
            m['ca'] += montant; m['caApresRemise'] += float(t.get('montantApresRemise') or montant); m['tickets'] += 1
            cle = t.get('compte') or t.get('societe') or 'inconnu'
            s = soc[cle]
            s['nom'] = (t.get('societe') or s['nom'] or '').strip()
            s['mois'][ym]['ca'] += montant; s['mois'][ym]['tickets'] += 1
            s['premier'] = min(s['premier'] or j, j); s['dernier'] = max(s['dernier'] or j, j)
            s['factures'] += 1 if t.get('facture') else 0; s['differes'] += 1 if t.get('differe') else 0
    sortie = {
        'source': 'cockpit, GET /exploitation/pro/detail?shop=5&date=… jour par jour (tickets des clients pro à TVA valide)',
        'mois': {ym: {**{k: round(v, 2) if isinstance(v, float) else v for k, v in m.items()},
                      'joursMois': calendar.monthrange(int(ym[:4]), int(ym[5:]))[1]} for ym, m in sorted(mois.items())},
        'societes': sorted(({'cle': k, 'nom': s['nom'] or k, 'premier': s['premier'], 'dernier': s['dernier'],
                             'factures': s['factures'], 'differes': s['differes'],
                             'total': round(sum(x['ca'] for x in s['mois'].values()), 2),
                             'tickets': sum(x['tickets'] for x in s['mois'].values()),
                             'mois': {ym: {'ca': round(x['ca'], 2), 'tickets': x['tickets']} for ym, x in sorted(s['mois'].items())}}
                            for k, s in soc.items()), key=lambda s: -s['total']),
    }
    (ICI / 'b2b-detail.json').write_text(json.dumps(sortie, ensure_ascii=False, indent=1), encoding='utf-8')
    for ym, m in sortie['mois'].items():
        print(ym, f"{m['jours']}/{m['joursMois']} j", 'B2B', m['ca'], 'tickets', m['tickets'], f"({100 * m['ca'] / m['caJour']:.1f} % du CA lu)" if m['caJour'] else '')
    print(len(sortie['societes']), 'sociétés')


if __name__ == '__main__':
    main(Path(sys.argv[1]))
