# Partie de generer.py : les vignettes de profils, le couple de radars et le tableau des clients qui reviennent.
# Exécutée dans l'espace de noms de generer.py (NIV, CLI, COMP, FAM, radar, eur…).

def virgule(v, d=1):
    return f'{v:.{d}f}'.replace('.', ',')

# Moyenne de tous les clients scannés, pondérée par le nombre de clients de chaque profil.
NT = sum(NIV[k]['n'] for k in NIV)
MOY = {}
for cle, axes in (('comp', COMP), ('fam', FAM)):
    MOY[cle] = {a: round(sum(NIV[k]['n'] * NIV[k][cle][a] for k in NIV) / NT) for a in axes}

CHOIX = 4          # la vignette sélectionnée dans la maquette
sel = NIV[CHOIX]
MOY_COL = '#9a948b'
r_comp = radar([([MOY['comp'][a] for a in COMP], MOY_COL, .06, 1.2),
                ([sel['comp'][a] for a in COMP], sel['col'], .16, 2.2)], COMPL, 340, pad=70)
r_fam = radar([([MOY['fam'][a] for a in FAM], MOY_COL, .06, 1.2),
               ([sel['fam'][a] for a in FAM], sel['col'], .16, 2.2)], FAML, 340, maxv=80, pad=70)
leg = (f'<span class="lg"><i style="background:{sel["col"]}"></i>{sel["nom"]}</span>'
       f'<span class="lg"><i style="background:{MOY_COL}"></i>Moyenne des {NT} clients scannés</span>')

COND = {
    1: ('Moins d’1 jour par mois', '1 seul passage en 3 mois'),
    2: ('Moins d’1 jour par mois', '2 passages en 3 mois'),
    3: ('1 à 1,7 jour par mois', '3 à 5 passages en 3 mois'),
    4: ('2 jours par mois ou plus', '6 passages ou plus en 3 mois'),
}
ca_tot = sum(NIV[k]['eur'] for k in NIV)
tuiles = []
for k in [1, 2, 3, 4]:
    v = NIV[k]
    pct = 100 * v['n'] / NT
    vu = 'hier' if v['rec'] == 0 else f"il y a {v['rec']} j"
    pluriel = 's' if v['n'] > 1 else ''
    on = ' is-on' if k == CHOIX else ''
    tuiles.append(
        f'<div class="tile{on}">'
        f'<div class="tile__head"><span class="lvl" style="background:{v["col"]}">{k}</span>{v["nom"]}</div>'
        f'<div class="tile__pct">{virgule(pct)} %</div>'
        f'<div class="tile__n">{v["n"]} client{pluriel} sur {NT} scannés</div>'
        f'<div class="tile__bar"><span style="width:{max(2, pct):.0f}%;background:{v["col"]}"></span></div>'
        f'<div class="tile__cond"><span class="cond__t">Condition</span><b>{COND[k][0]}</b><span>{COND[k][1]}</span></div>'
        f'<dl><dt>Part du CA scanné</dt><dd>{100 * v["eur"] / ca_tot:.0f} %</dd>'
        f'<dt>Panier moyen</dt><dd>{eur(v["pan"])}</dd>'
        f'<dt>En commande</dt><dd>{round(100 * v["cmd"] / v["tk"])} %</dd>'
        f'<dt>Dernier passage</dt><dd>{vu} <span class="muted">méd.</span></dd></dl>'
        f'</div>')
ladder = ''.join(tuiles)

def top_fam(f):
    t = sorted(f.items(), key=lambda kv: -kv[1])
    return ', '.join([f'{FAML[FAM.index(k)].lower()} {v} %' for k, v in t if v > 0][:2])

actions = {
    'Client A': 'Lui proposer la carte : 16 passages sans un point',
    'Client B': 'Carte Pain : le faire revenir chaque semaine',
    'Client C': 'Jauge de points : il achète de tout',
    'Client D': 'L’inviter aux temps forts (cougnou, fêtes)',
    'Client E': 'Lui parler de la formule lunch',
    'Client F': 'Un bon sur sa prochaine viennoiserie',
}
lignes = []
for c in CLI:
    v = NIV[c['niv']]
    vu = 'hier' if c['rec'] == 0 else f"il y a {c['rec']} j"
    lignes.append(
        f'<tr><td>{c["n"]}</td>'
        f'<td><span class="lvl lvl--sm" style="background:{v["col"]}">{c["niv"]}</span> {v["nom"]}</td>'
        f'<td class="num">{virgule(len(c["jours"]) / 3)} j / mois<span class="sub">{len(c["jours"])} passages en 3 mois</span></td>'
        f'<td>{vu}</td><td class="num">{eur(c["eur"] / c["tk"])}</td>'
        f'<td>{top_fam(c["fam"])}</td><td>{actions[c["n"]]}</td></tr>')
cards = ''.join(lignes)
famhtml = ''
