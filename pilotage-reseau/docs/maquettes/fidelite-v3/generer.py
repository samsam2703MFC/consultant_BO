# Trois maquettes (12/11/2026, Sam) :
#   a-cockpit.html    Cockpit › ERP franchisé › Fidélité › Profils : les conditions des profils, réglées pour le réseau ;
#   b-dashboard.html  Dashboard magasin › Fidélité : les profils du magasin en % des clients scannés ;
#   c-client.html     Dashboard magasin › Fidélité › un client : l'historique de son comportement.
import os, re, math, datetime, collections
ICI = os.path.dirname(os.path.abspath(__file__))
exec(open(os.path.join(ICI, 'donnees.py')).read())
src = open(os.path.join(ICI, '../fidelite-franchise/index.html')).read()
STYLE = re.search(r'<style>(.*?)</style>', src, re.S).group(1) + open(os.path.join(ICI, '../fidelite-profils/profils.css')).read() + open(os.path.join(ICI, 'v3.css')).read()
RAIL_FR = re.search(r'(<nav class="sidebar".*?</nav>)', src, re.S).group(1)
P = {p[0]: p for p in PROFILS}
COMP = ['frequence', 'recence', 'anciennete', 'panier', 'diversite', 'comptoir']
COMPL = ['Fréquence', 'Récence', 'Ancienneté', 'Panier', 'Diversité', 'Comptoir']
FAM = ['pain', 'vienno', 'antigaspi', 'tartes', 'gouter', 'sale']
FAML = ['Pain', 'Viennoiserie', 'Anti-gaspi', 'Tartes & fêtes', 'Goûter', 'Salé']
ICON = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"'

def eur(v, d=2):
    return f"{v:,.{d}f}".replace(',', ' ').replace('.', ',') + ' €'
def virg(v, d=1):
    return f'{v:.{d}f}'.replace('.', ',')

def radar(series, labels, size=300, maxv=100, fs=11, pad=58):
    cx = cy = size / 2; R = size / 2 - pad; n = len(labels); o = []
    ang = lambda i: -math.pi / 2 + 2 * math.pi * i / n
    for f in (0.25, 0.5, 0.75, 1):
        o.append('<polygon points="%s" fill="none" stroke="#e6e0d8"/>' % ' '.join(f"{cx+R*f*math.cos(ang(i)):.1f},{cy+R*f*math.sin(ang(i)):.1f}" for i in range(n)))
    for i in range(n):
        o.append(f'<line x1="{cx}" y1="{cy}" x2="{cx+R*math.cos(ang(i)):.1f}" y2="{cy+R*math.sin(ang(i)):.1f}" stroke="#e6e0d8"/>')
        lx = cx + (R + 12) * math.cos(ang(i)); ly = cy + (R + 12) * math.sin(ang(i))
        anc = 'middle' if abs(math.cos(ang(i))) < 0.3 else ('start' if math.cos(ang(i)) > 0 else 'end')
        dy = -4 if math.sin(ang(i)) < -0.5 else (12 if math.sin(ang(i)) > 0.5 else 4)
        o.append(f'<text x="{lx:.1f}" y="{ly+dy:.1f}" font-size="{fs}" text-anchor="{anc}" fill="#5c5852">{labels[i]}</text>')
    for vals, col, op, w in series:
        pts = ' '.join(f"{cx+R*min(maxv,max(2,v))/maxv*math.cos(ang(i)):.1f},{cy+R*min(maxv,max(2,v))/maxv*math.sin(ang(i)):.1f}" for i, v in enumerate(vals))
        o.append(f'<polygon points="{pts}" fill="{col}" fill-opacity="{op}" stroke="{col}" stroke-width="{w}" stroke-linejoin="round"/>')
    return f'<svg viewBox="0 0 {size} {size}" width="100%" style="display:block;max-width:{size}px;margin:0 auto">{"".join(o)}</svg>'

def page(titre, corps):
    return ('<!doctype html>\n<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">\n'
            f'<title>{titre}</title>\n<style>{STYLE}</style></head>\n<body>{corps}</body></html>')

# ── A · Cockpit › Fidélité › Profils ────────────────────────────────────────────────────────────────────────────────
def rail_cockpit():
    grp = lambda t, items: f'<div class="sidebar__group"><div class="sidebar__group-label">{t}</div>{items}</div>'
    it = lambda t, on=False, ext='': f'<a class="sidebar__item{" is-active" if on else ""}" href="#"{" aria-current=page" if on else ""}><svg class="i" {ICON}><circle cx="12" cy="12" r="3.2"/></svg>{t}{ext}</a>'
    return ('<nav class="sidebar" aria-label="Navigation principale"><div class="sidebar__head"><div class="sidebar__logo">L’Atelier By</div>'
            '<div class="sidebar__kicker">Cockpit de pilotage</div></div><div class="sidebar__groups">'
            + grp('Pilotage', it('Exploitation') + it('Résultat') + it('Objectifs'))
            + grp('Franchisés', it('Suivi des franchisés') + it('Visites'))
            + grp('Marque &amp; marketing', it('Fonds &amp; Royalties') + it('Visibilité Google'))
            + grp('ERP franchisé', it('Prospection ↗') + it('MétaDonnée') + it('Tablette vendeuses') + it('Fidélité', True))
            + grp('Application employé', it('Page admin') + it('Pointages'))
            + '</div></nav>')

def regle(seuil):
    if seuil == 12: return ('3', 'semaine', 'ou plus')
    if seuil == 8: return ('2', 'semaine', '')
    if seuil == 4: return ('1', 'semaine', '')
    if seuil == 2: return ('2 à 3', 'mois', '')
    if seuil == 1: return ('1', 'mois', '')
    return ('', '', '')

lignes = []
for k, nom, cond, eq, seuil, col, n, tk, e, cmd in PROFILS:
    base = k == 'moins'
    nb, per, plus = regle(seuil)
    phrase = ('<span class="fi-t">Tous les clients scannés qui n’atteignent pas « 1 fois par mois »</span>' if base else
              f'<span class="champ">{nb}</span> <span class="fi-t">passage{"s" if nb != "1" else ""} par</span> <span class="champ sel">{per} ▾</span> {("<span class=fi-t>" + plus + "</span>") if plus else ""}')
    lignes.append(f'''<div class="pr-row{' base' if base else ''}">
      <span class="pr-dot" style="background:{col}"></span>
      <div class="pr-nom"><span class="champ nom">{nom}</span><span class="pr-eq">{eq}</span></div>
      <div class="pr-ph">{phrase}</div>
      <div class="pr-halle num">{n}<span class="sub">{virg(100*n/SCANNES)} % à Halle</span></div>
      {'<span class="fi-tog on fixe"></span>' if base else '<button class="tog on" aria-label="actif"></button>'}
    </div>''')
# L'échelle : une case par profil, de même largeur ; les seuils s'écrivent aux frontières.
W = 1100; n = len(PROFILS); cw = W / n
reg = []
bornes = ['0', '1 / mois', '2 / mois', '1 / semaine', '2 / semaines', '3 / semaine', '']
bornes = ['0', '1 par mois', '2 par mois', '1 par semaine', '2 par semaine', '3 par semaine']
for i, p in enumerate(PROFILS[::-1]):
    x0 = i * cw
    reg.append(f'<rect x="{x0+1:.1f}" y="6" width="{cw-2:.1f}" height="30" rx="6" fill="{p[5]}" fill-opacity=".9"/>'
               f'<text x="{x0+cw/2:.1f}" y="26" font-size="13" text-anchor="middle" fill="#fff">{p[1]}</text>')
    reg.append(f'<line x1="{x0+1:.1f}" x2="{x0+1:.1f}" y1="38" y2="46" stroke="#B8B2A8"/><text x="{x0+3:.1f}" y="60" font-size="12" fill="#7a736a">{bornes[i]}</text>')
regle_svg = f'<svg viewBox="0 0 {W} 66" width="100%">{"".join(reg)}</svg>'

corps_a = f'''<div class="workspace">{rail_cockpit()}<div class="workspace__main">
  <header class="topbar"><div><p class="topbar__kicker">Cockpit · ERP franchisé</p><h1>Fidélité</h1></div>
    <div class="topbar__actions"><span class="muted" style="font-size:12px">2 modifications non publiées</span><a class="btn btn--primary" href="#">Publier dans les magasins</a></div></header>
  <main class="app__body">
    <nav class="page-tabs"><button class="page-tabs__tab">Programme</button><button class="page-tabs__tab">Clients</button><button class="page-tabs__tab is-on">Profils clients</button></nav>
    <p class="intro">Les profils rangent chaque client scanné selon le rythme de ses passages. Ils se règlent ici, pour tout le réseau ; le dashboard de chaque magasin les lit tels quels.</p>
    <section class="card" style="margin-top:14px">
      <h2>L’échelle <span class="section-label__aside">du plus rare au plus fidèle · le seuil d’entrée sous chaque profil</span></h2>
      {regle_svg}
    </section>
    <section class="fi-grp-v3">
      <h3>Les profils de fréquence <span>du plus fidèle au moins fidèle · cliquer un nom ou une valeur pour la changer</span></h3>
      <div class="pr-liste"><div class="pr-row pr-head"><span></span><span>Profil</span><span>Condition</span><span class="num">Aujourd’hui</span><span>Actif</span></div>{''.join(lignes)}</div>
      <button class="fi-add-v3">+ Ajouter un profil</button>
    </section>
    <section class="fi-grp-v3">
      <h3>Comment on compte</h3>
      <div class="pr-liste">
        <div class="pr-opt"><b>Fenêtre de mesure</b><span>Le rythme se lit sur les <span class="champ">90</span> derniers jours</span></div>
        <div class="pr-opt"><b>Un passage</b><span>Un jour avec au moins un ticket rattaché au client ; deux tickets le même jour font un passage</span></div>
        <div class="pr-opt"><b>Clients comptés</b><span>Particuliers reconnus en caisse (carte scannée ou commande) ; les clients pro ne comptent pas</span></div>
        <div class="pr-opt"><b>Profil d’un client</b><span>Recalculé chaque nuit ; il peut monter ou descendre</span></div>
      </div>
    </section>
    <p class="foot">Maquette du 12/11/2026. La colonne « Aujourd’hui » applique ces conditions aux 209 clients scannés de L’Atelier by Halle sur 90 jours (API du panel, aucune table locale). Les noms des profils (Fanbase, Fidèle, Hebdo, Régulier, Occasionnel, Découverte) sont des propositions ; les conditions sont celles demandées.</p>
  </main></div></div>'''

# ── B · Dashboard magasin › Fidélité ────────────────────────────────────────────────────────────────────────────────
ONGLETS = ['Opérationnel', 'Tâches', 'Semaine', 'Mois', 'Trimestre', 'Année', 'Produits', 'Promotions', 'Primes', 'Concurrence', 'Temps morts', 'Fidélité']
def entete_dash(sous):
    return (f'<div class="db-head"><div><div class="db-titre-v3">Dashboard magasin</div><div class="db-sous-v3">{sous}</div></div>'
            '<select class="db-sel-v3"><option>L’Atelier by Halle</option></select></div>'
            '<div class="db-ong-v3">' + ''.join(f'<button class="{"on" if o == "Fidélité" else ""}">{o}</button>' for o in ONGLETS) + '</div>')

CA_TOT = sum(p[8] for p in PROFILS)
CHOIX = '1sem'
tuiles = []
for k, nom, cond, eq, seuil, col, n, tk, e, cmd in PROFILS:
    pct = 100 * n / SCANNES
    vide = n == 0
    stats = ('<p class="t-vide">Aucun client à ce rythme pour l’instant.</p>' if vide else
             f'<dl><dt>Part du CA scanné</dt><dd>{100*e/CA_TOT:.1f} %</dd><dt>Panier moyen</dt><dd>{eur(e/tk)}</dd><dt>En commande</dt><dd>{round(100*cmd/tk)} %</dd></dl>'.replace('.', ',', 1))
    tuiles.append(f'''<div class="tile t6{' is-on' if k == CHOIX else ''}{' is-vide' if vide else ''}">
      <div class="tile__head"><span class="dot-p" style="background:{col}"></span>{nom}</div>
      <div class="tile__pct">{virg(pct)} %</div>
      <div class="tile__n">{n} client{"s" if n > 1 else ""} sur {SCANNES}</div>
      <div class="tile__bar"><span style="width:{max(1.5, pct):.1f}%;background:{col}"></span></div>
      <div class="tile__cond"><span class="cond__t">Condition</span><b>{cond[0].upper() + cond[1:]}</b><span>{eq}</span></div>
      {stats}</div>''')
cA = CLIENTS[0]
r_comp = radar([([MOY['comp'][a] for a in COMP], '#9a948b', .06, 1.2), ([cA['comp'][a] for a in COMP], P[CHOIX][5], .16, 2.2)], COMPL, 320, pad=70)
r_fam = radar([([MOY['fam'][a] for a in FAM], '#9a948b', .06, 1.2), ([cA['fam'][a] for a in FAM], P[CHOIX][5], .16, 2.2)], FAML, 320, maxv=80, pad=70)
leg = f'<span class="lg"><i style="background:{P[CHOIX][5]}"></i>Hebdo · 1 client</span><span class="lg"><i style="background:#9a948b"></i>Moyenne des {SCANNES} clients scannés</span>'
def top_fam(f):
    t = sorted(f.items(), key=lambda kv: -kv[1])
    return ', '.join([f'{FAML[FAM.index(k)].lower()} {v} %' for k, v in t if v > 0][:2])
rows = ''.join(f'''<tr class="row-link{' is-sel' if i == 0 else ''}"><td>{c['n']}</td><td><span class="dot-p" style="background:{P[c['profil']][5]}"></span> {P[c['profil']][1]}<span class="sub">{P[c['profil']][2]}</span></td>
  <td class="num">{virg(c['passages']/3)} / mois<span class="sub">{c['passages']} passages en 90 j</span></td><td>{'hier' if c['rec'] == 0 else f"il y a {c['rec']} j"}</td>
  <td class="num">{eur(c['eur']/c['tk'])}</td><td>{top_fam(c['fam'])}</td><td class="num"><a class="linklike" href="c-client.html">Historique ›</a></td></tr>''' for i, c in enumerate(CLIENTS))
corps_b = f'''<div id="dash-v3">{entete_dash('Fidélité · 90 jours, du 14 août au 11 novembre · 209 clients scannés en caisse (253 tickets sur 13 173, soit 1 sur 52)')}
  <div class="tiles t6s">{''.join(tuiles)}</div>
  <p class="regle-note">Les conditions se règlent au cockpit (ERP franchisé › Fidélité › Profils clients) ; le magasin les lit.</p>
  <div class="g2" style="grid-template-columns:1fr 1fr 330px">
    <section class="card"><h2>Récurrence · Hebdo <span class="section-label__aside">0 au centre, 100 au bord</span></h2>{r_comp}<div class="lgs">{leg}</div></section>
    <section class="card"><h2>Type d’achat · Hebdo <span class="section-label__aside">part des articles, en %</span></h2>{r_fam}<div class="lgs">{leg}</div></section>
    <section class="card"><h2>Ce que dit le profil Hebdo</h2><ul class="ins">
      <li><b>Le pain, chaque semaine</b>78 % de pain, 9,06 € par passage : le plus petit panier, le plus grand nombre de passages.</li>
      <li><b>Il commande au lieu de passer</b>15 de ses 16 tickets passent par une commande, retirée en fin d’après-midi.</li>
      <li><b>Personne au-dessus</b>Aucun client à 2 ou 3 fois par semaine : le haut de l’échelle est à construire avec la carte.</li></ul>
      <p class="hint">Cliquer une vignette affiche son profil dans les deux radars.</p></section>
  </div>
  <section class="card" style="padding:0;overflow:hidden;margin-top:16px">
    <div style="padding:1.25rem 1.25rem .25rem"><h2>Les clients qui reviennent <span class="section-label__aside">cliquer un client ouvre son historique · à l’écran, le prénom et l’initiale du nom</span></h2></div>
    <table><thead><tr><th>Client</th><th>Profil</th><th class="num">Rythme</th><th>Dernier passage</th><th class="num">Panier</th><th>Achète surtout</th><th></th></tr></thead><tbody>{rows}</tbody></table>
  </section>
  <p class="foot">Maquette du 12/11/2026. Chiffres réels de L’Atelier by Halle, lus par l’API du panel sans aucune table locale. Les clients sont anonymisés (A à F). Les noms des profils sont des propositions.</p>
</div>'''

# ── C · La fiche d'un client : son historique ───────────────────────────────────────────────────────────────────────
H = [(datetime.datetime.strptime(d, '%Y-%m-%d %H:%M'), e, c, a) for d, e, c, a in HIST_A]
J = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.']
parJour = collections.Counter(t.weekday() for t, *_ in H)
parHeure = collections.Counter(('matin' if t.hour < 11 else 'midi' if t.hour < 14 else 'après-midi' if t.hour < 18 else 'soir') for t, *_ in H)
# Le calendrier : semaines du lundi 15/09 au dimanche 16/11.
premier = H[0][0].date(); debut = premier - datetime.timedelta(days=premier.weekday()); fin = datetime.date(2026, 11, 15)
jours_v = {t.date(): (e, c) for t, e, c, a in H}
cal = ['<div class="cal"><span></span>' + ''.join(f'<span class="cal-j">{j}</span>' for j in J) + '<span class="cal-j">€</span>']
d = debut
while d <= fin:
    sem = [d + datetime.timedelta(days=i) for i in range(7)]
    tot = sum(jours_v[x][0] for x in sem if x in jours_v)
    cal.append(f'<span class="cal-s">{sem[0].day:02d}/{sem[0].month:02d}</span>')
    for x in sem:
        if x > datetime.date(2026, 11, 11):
            cal.append('<span class="cal-c futur"></span>')
        elif x in jours_v:
            e, c = jours_v[x]
            cal.append(f'<span class="cal-c {"cmd" if c else "cpt"}" title="{x.strftime("%d/%m")} : {eur(e)}">{virg(e, 0) if e >= 10 else ""}</span>')
        else:
            cal.append('<span class="cal-c"></span>')
    cal.append(f'<span class="cal-t num">{eur(tot) if tot else "—"}</span>')
    d += datetime.timedelta(days=7)
cal.append('</div>')
# Le profil mois par mois.
mois = [('Septembre', 'à partir du 21', 3, 'Régulier', '2a3mois'), ('Octobre', '', 9, 'Fidèle', '2sem'), ('Novembre', 'au 11, rythme ≈ 11 par mois', 4, 'Fidèle', '2sem')]
mois_h = ''.join(f'<div class="mo"><span class="mo-m">{m}</span><span class="mo-n">{n} passages</span><span class="mo-p"><span class="dot-p" style="background:{P[k][5]}"></span>{p}</span><span class="sub">{s}</span></div>' for m, s, n, p, k in mois)
tickets = ''.join(f'<tr><td>{t.strftime("%d/%m")} <span class="sub">{J[t.weekday()]} {t.strftime("%H:%M")}</span></td><td>{"Commande" if c else "Comptoir"}</td><td class="num">{eur(e)}</td><td>{a}</td></tr>' for t, e, c, a in reversed(H))
art = ''.join(f'<div class="hb"><span>{n}</span><span class="hb__t"><span style="width:{q/18*100:.0f}%"></span></span><span class="num">{q}</span></div>' for n, q in sorted(ARTICLES_A, key=lambda x: -x[1])[:7])
jours_h = ''.join(f'<div class="bar-j"><span class="bj" style="height:{parJour.get(i,0)/max(parJour.values())*100:.0f}%"></span><span class="bj-n">{parJour.get(i,0)}</span><span class="bj-l">{J[i]}</span></div>' for i in range(7))
rc = radar([([MOY['comp'][a] for a in COMP], '#9a948b', .06, 1.2), ([cA['comp'][a] for a in COMP], P['1sem'][5], .16, 2.2)], COMPL, 260, pad=62, fs=10.5)
rf = radar([([MOY['fam'][a] for a in FAM], '#9a948b', .06, 1.2), ([cA['fam'][a] for a in FAM], P['1sem'][5], .16, 2.2)], FAML, 260, maxv=80, pad=62, fs=10.5)
corps_c = f'''<div id="dash-v3">{entete_dash('Fidélité › fiche client')}
  <p style="margin:0"><a class="linklike" href="b-dashboard.html" style="font-size:12.5px">‹ Retour aux profils</a></p>
  <div class="cl-head card">
    <div class="cl-id"><span class="cl-av">A</span><div><div class="cl-nom">Client A</div><div class="muted" style="font-size:12.5px">client du webshop · premier ticket reconnu le 21/09/2026 · dernier passage hier</div></div></div>
    <div class="cl-k"><div><b>Hebdo</b><span>profil sur 90 jours<br>1 fois par semaine</span></div><div><b>16</b><span>passages<br>en 7 semaines</span></div><div><b>145,00 €</b><span>dépensés<br>9,06 € par passage</span></div><div><b>15 / 16</b><span>en commande<br>1 seul au comptoir</span></div><div><b class="ko">Aucune carte</b><span>16 passages<br>sans un point</span></div></div>
    <a class="btn btn--primary" href="#">Activer sa carte fidélité</a>
  </div>
  <div class="g3c">
    <section class="card"><h2>Son profil, mois par mois</h2><div class="mos">{mois_h}</div>
      <p class="hint" style="margin-top:12px">Sur 90 jours il est Hebdo ; depuis octobre il passe 2 fois par semaine. Le profil monte à mesure que l’historique s’allonge.</p></section>
    <section class="card"><h2>Ses passages <span class="section-label__aside"><i class="sq cmd"></i>commande <i class="sq cpt"></i>comptoir · € par passage</span></h2>{''.join(cal)}</section>
    <section class="card"><h2>Ses habitudes</h2>
      <p class="mini-lab">Jour de passage</p><div class="bars-j">{jours_h}</div>
      <p class="mini-lab">Moment</p><p class="moments">{' · '.join(f'{k} {v}' for k, v in parHeure.most_common())}</p>
      <p class="mini-lab">Ce qu’il prend</p>{art}</section>
  </div>
  <div class="g2" style="grid-template-columns:300px 300px 1fr">
    <section class="card"><h2>Récurrence</h2>{rc}<div class="lgs"><span class="lg"><i style="background:{P['1sem'][5]}"></i>Client A</span><span class="lg"><i style="background:#9a948b"></i>Moyenne</span></div></section>
    <section class="card"><h2>Type d’achat</h2>{rf}<div class="lgs"><span class="lg"><i style="background:{P['1sem'][5]}"></i>Client A</span><span class="lg"><i style="background:#9a948b"></i>Moyenne</span></div></section>
    <section class="card" style="padding:0;overflow:hidden"><div style="padding:1.25rem 1.25rem .25rem"><h2>Ses tickets <span class="section-label__aside">les 16, du plus récent au plus ancien</span></h2></div>
      <div class="tk-scroll"><table class="mini"><thead><tr><th>Date</th><th>Canal</th><th class="num">Montant</th><th>Articles</th></tr></thead><tbody>{tickets}</tbody></table></div></section>
  </div>
  <p class="foot">Maquette du 12/11/2026. Historique réel d’un client de L’Atelier by Halle, lu sur 180 jours par l’API du panel (tickets du jour et leurs lignes), sans aucune table locale. Le client est anonymisé ; à l’écran du magasin, son prénom et l’initiale de son nom.</p>
</div>'''

for nom, titre, corps in (('a-cockpit.html', 'Maquette — Cockpit › Fidélité › Profils clients', corps_a),
                          ('b-dashboard.html', 'Maquette — Dashboard magasin › Fidélité', corps_b),
                          ('c-client.html', 'Maquette — Dashboard magasin › Fidélité › fiche client', corps_c)):
    open(os.path.join(ICI, nom), 'w').write(page(titre, corps))
print('trois pages écrites')
