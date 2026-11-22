#!/usr/bin/env python3
"""Maquettes « la boîte mail de demain » (21/11/2026, demande : « donne-moi 3 options dingues et futuristes pour la
boîte mail ») : trois écrans statiques au design system de la marque, le rail du dashboard magasin, « Boîte mail » actif.

    python3 docs/maquettes/boite-mail-futur/generer.py && node docs/maquettes/boite-mail-futur/capturer.cjs

Magasin et adresse réels (L'Atelier By Halle, halle@atelierby.be) ; clients, e-mails, montants et compteurs illustrés.
"""
import os

ICI = os.path.dirname(os.path.abspath(__file__))


def ico(d, cls=''):
    return f'<svg viewBox="0 0 24 24"{(" class=" + chr(34) + cls + chr(34)) if cls else ""} fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{d}"/></svg>'


ETINCELLE = '<svg viewBox="0 0 24 24"><path d="M11 2c.55 4.9 2.6 7.4 7.6 8-5 .6-7.05 3.1-7.6 8-.55-4.9-2.6-7.4-7.6-8 5-.6 7.05-3.1 7.6-8zM19 14.5c.22 1.7.95 2.55 2.7 2.75-1.75.2-2.48 1.05-2.7 2.75-.22-1.7-.95-2.55-2.7-2.75 1.75-.2 2.48-1.05 2.7-2.75z"/></svg>'

I = {
    'mail': 'M3 5h18v14H3zM3 6l9 7 9-7', 'tdb': 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
    'controle': 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11', 'journal': 'M12 8v4l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9',
    'cal': 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z', 'perf': 'M3 17l6-6 4 4 8-8M15 7h6v6',
    'fonds': 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5', 'frais': 'M6 2h12v20l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6M9 15h4',
    'loupe': 'M3 3v18h18M7 15l4-5 3 3 5-7', 'bundles': 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8',
    'offres': 'M19 5L5 19M7 7a1.5 1.5 0 1 0 .01 0M17 17a1.5 1.5 0 1 0 .01 0', 'offre': 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5',
    'personnes': 'M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.9', 'analyse': 'M4 20V10M10 20V4M16 20v-7M22 20H2',
    'pub': 'M4 4h16v12H4zM2 20h20M9 8l4 2-4 2z', 'reseaux': 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18M15 3v18',
    'etoile': 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z', 'brochures': 'M3 5l6-2 6 2 6-2v16l-6 2-6-2-6 2zM9 3v16M15 5v16',
    'auto': 'M3 5h12v10H3zM3 6l6 5 6-5M18.5 14.5a2.5 2.5 0 1 0 .01 0M18.5 10.5v1.5M18.5 17v1.5M22 14.5h-1.5M16.5 14.5H15',
    'ia': 'M12 3v3M12 18v3M3 12h3M18 12h3M6 6l2 2M16 16l2 2M6 18l2-2M16 8l2-2',
    'casque': 'M4 15v-3a8 8 0 0 1 16 0v3M4 15h3v5H5a1 1 0 0 1-1-1zM20 15h-3v5h2a1 1 0 0 0 1-1z',
    'micro': 'M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3zM5 11a7 7 0 0 0 14 0M12 18v3',
    'ok': 'M5 12l5 5L20 7', 'crayon': 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z', 'plustard': 'M3 12a9 9 0 1 0 3-6.7M3 4v5h5',
    'dossier': 'M3 6a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z', 'cloche': 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10 21h4',
    'bouclier': 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z', 'stop': 'M12 8v5M12 16.5h.01M10.3 3.9L2.5 17.5A2 2 0 0 0 4.2 20.5h15.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
    'source': 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5zM4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5', 'tel': 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2',
    'radar': 'M12 12l6-6M12 3a9 9 0 1 0 9 9M12 7a5 5 0 1 0 5 5', 'pilote': 'M12 2l3 7h7l-5.5 4.5 2 7.5L12 16.5 5.5 21l2-7.5L2 9h7z',
    'cartes': 'M7 4h13v13H7zM4 7v13h13', 'liste': 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01', 'pj': 'M21 12l-8.5 8.5a5 5 0 0 1-7-7L14 5a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L15 8',
    'humeur': 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01', 'four': 'M3 4h18v16H3zM3 9h18M7 6.5h.01M11 6.5h.01M8 13h8v4H8z',
}


def rail():
    item = lambda k, l, on=False, n=None: f'<div class="sidebar__item{" is-active" if on else ""}">{ico(I[k])}<span>{l}</span>{f"<span class=count>{n}</span>" if n else ""}</div>'
    groupes = [
        ('La journée', [('tdb', 'Opérationnel'), ('controle', 'Tâches'), ('journal', 'Temps morts')]),
        ('Les chiffres', [('cal', 'Semaine'), ('perf', 'Année'), ('fonds', 'Trésorerie'), ('frais', 'Frais')]),
        ('Vendre', [('loupe', 'Analyse'), ('bundles', 'Produits'), ('offres', 'Promotions'), ('offre', 'Offres'), ('analyse', 'Concurrence')]),
        ('Marketing', [('pub', 'Mon marketing'), ('reseaux', 'Réseaux sociaux'), ('etoile', 'Fidélité')]),
        ('Paramètres', [('auto', 'Mails et automatisations')]),
    ]
    g = ''.join(f'<div class="sidebar__group"><div class="sidebar__group-label">{t}</div>{"".join(item(k, l) for k, l in L)}</div>' for t, L in groupes)
    return f'''<aside class="sidebar">
  <div class="sidebar__head"><img class="sidebar__logo" src="../../../shared/public/assets/img/logo.png" alt="L’Atelier"><div class="sidebar__kicker">Dashboard magasin</div></div>
  <div class="sidebar__brand">L’Atelier By Halle<span class="sub">Halle · samedi 21/11/2026</span></div>
  <div class="sidebar__groups"><div class="sidebar__group sidebar__group--tete">{item('mail', 'Boîte mail', True, 14)}<div class="sidebar__item"><span style="display:grid;place-items:center;width:16px;height:16px;color:var(--accent)">{ETINCELLE.replace('<svg ', '<svg style="width:14px;height:14px;fill:currentColor;stroke:none" ')}</span><span>Assistant IA</span></div></div>{g}</div>
  <div class="sidebar__account"><span>Sophie · gérante</span><span class="muted">halle@atelierby.be</span></div>
</aside>'''


def page(nom, titre, n, mode, droite, corps, pied):
    modes = [('liste', 'Classique'), ('cartes', 'Briefing'), ('pilote', 'Pilote'), ('radar', 'Radar')]
    seg = ''.join(f'<span class="{"is-on" if l == mode else ""}">{ico(I[k])}{l}</span>' for k, l in modes)
    html = f'''<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>{titre} — Boîte mail, option {n}</title><link rel="stylesheet" href="ds.css"></head>
<body><div class="workspace">{rail()}<div class="workspace__main">
<header class="topbar"><div><p class="topbar__kicker">Boîte mail · halle@atelierby.be</p><h1>{titre}</h1></div><div class="topbar__actions">{droite}<span class="modes">{seg}</span><span class="option">Option {n} sur 3 · maquette</span></div></header>
<div class="app__body"><div style="max-width:1146px">
{corps}
<p class="pied">{pied}</p></div></div>
</div></div></body></html>'''
    with open(os.path.join(ICI, nom), 'w', encoding='utf-8') as f:
        f.write(html)
    print('écrit', nom)


def anneau(p, couleur='var(--accent)', taille=38):
    r = taille / 2 - 3
    c = 2 * 3.14159 * r
    return (f'<span class="anneau" style="width:{taille}px;height:{taille}px"><svg viewBox="0 0 {taille} {taille}" width="{taille}" height="{taille}">'
            f'<circle cx="{taille/2}" cy="{taille/2}" r="{r}" fill="none" stroke="#EDE7DE" stroke-width="3"/>'
            f'<circle cx="{taille/2}" cy="{taille/2}" r="{r}" fill="none" stroke="{couleur}" stroke-width="3" stroke-linecap="round" stroke-dasharray="{c*p/100:.1f} {c:.1f}"/></svg><b>{p}</b></span>')


def ia(cls=''):
    return f'<span class="ia{(" " + cls) if cls else ""}">{ETINCELLE}</span>'


# ---------------------------------------------------------------------------------------------------------------------
# Option 1 · le briefing du matin
# ---------------------------------------------------------------------------------------------------------------------
def briefing():
    prog = ''.join(f'<i class="{"ok" if i < 3 else "on" if i == 3 else ""}"></i>' for i in range(14))
    onde = ''.join(f'<i style="height:{h}px"></i>' for h in [6, 10, 16, 12, 22, 30, 18, 38, 46, 30, 52, 40, 58, 44, 34, 50, 28, 40, 24, 32, 18, 26, 14, 20, 10, 16, 8, 12, 6, 9, 5])
    file = [
        ('ok', 'Moulins Dewitte', 'facture d’octobre', 'Rangé · Factures', ''),
        ('ok', 'Lucas Martens', 'ouvert le dimanche ?', 'Répondu', ''),
        ('ok', 'Nathalie Lambert', 'gâteau sans gluten pour samedi', 'Répondu', ''),
        ('on', 'Marie Peeters', 'communion du 28/11', 'Offre + réponse', 'chip--sel'),
        ('', 'Brasserie De Kroon', 'devis pour 40 personnes', 'Offre B2B', ''),
        ('', 'Julie Wouters', 'annule sa tarte au riz de mardi', 'Réponse', ''),
        ('', 'Kevin Janssens', 'gâteau abîmé à la livraison', 'À rappeler', 'chip--stop'),
        ('', 'Proximus', 'votre facture de novembre', 'Factures', ''),
    ]
    fl = ''.join(f'''<div class="file__it {e and ("fait" if e == "ok" else "on")}"><span class="etat {"etat--ok" if e == "ok" else "etat--on" if e == "on" else ""}">{"✓" if e == "ok" else "4" if e == "on" else ""}</span><span class="q"><b>{q}</b> — {o}</span><span class="chip {c}">{a}</span></div>''' for e, q, o, a, c in file)
    corps = f'''
<div class="brief-tete card lisere lueur">
  {ia('ia--lg')}
  <div style="flex:1;min-width:0">
    <h2>Bonjour Sophie. Cette nuit, j’ai lu 31 e-mails.</h2>
    <p>14 attendent votre accord : tout est préparé, comptez <b style="font-weight:700">~2 min</b>. Les 17 autres sont déjà rangés.</p>
    <div class="brief-faits"><span class="chip chip--sel">{ico(I['crayon'])}6 réponses rédigées</span><span class="chip chip--sel">{ico(I['offre'])}3 offres TF Buddy pré-remplies</span><span class="chip">{ico(I['dossier'])}4 dossiers choisis</span><span class="chip chip--stop">{ico(I['tel'])}1 client à rappeler</span></div>
    <div class="progres">{prog}</div>
  </div>
  <div style="display:grid;gap:8px;justify-items:end">
    <span class="btn btn--primary btn--lg">{ico(I['casque'])}Écouter le briefing</span>
    <span class="small muted">ou ← → au clavier · balayez sur la tablette</span>
  </div>
</div>

<div class="brief">
  <div>
    <div class="sec">E-mail 4 sur 14 <span class="aside">les cartes se valident d’un geste : → valider · ← plus tard</span></div>
    <div class="pile-zone"><div class="pile">
      <div class="balayer balayer--g"><i>←</i>plus tard</div>
      <div class="balayer balayer--d"><i>→</i>valider</div>
      <div class="pile__fond pile__fond--2"></div><div class="pile__fond pile__fond--1"></div>
      <div class="carte card lisere">
        <div class="carte__haut"><span class="chip chip--sel">Demande de commande</span><span>reçu hier à 22:47 · lu par l’IA à 05:12</span>{anneau(94)}</div>
        <div class="carte__qui"><span class="av av--lg">MP</span><div><b style="font-size:14px">Marie Peeters</b> <span class="muted small">&lt;marie.peeters@telenet.be&gt;</span><span class="sub">cliente depuis 2022 · 9 commandes · 312,40 € · ton chaleureux</span></div></div>
        <p class="carte__resume">Veut <mark>2 cramiques et 30 mini-croissants</mark> pour la communion de sa fille, <mark>samedi 28/11 à 9 h</mark>, retrait au magasin.</p>
        <blockquote class="carte__cite">« … et si possible un des deux cramiques sans raisins, ma belle-mère n’aime pas ça. Merci d’avance ! »</blockquote>
        <div class="prets">
          <div class="pret"><div class="pret__t"><span class="prepare">{ETINCELLE}Réponse rédigée</span><span class="chip">dans votre ton</span></div>
            <p>Bonjour Marie,</p><p>Avec plaisir ! C’est noté pour samedi 28/11 à 9 h : 2 cramiques (dont un sans raisins) et 30 mini-croissants. L’offre est en pièce jointe ; un simple « oui » suffit pour confirmer.</p><p>Belle communion à Lena !<br><span class="muted">L’équipe de L’Atelier By Halle</span></p></div>
          <div class="pret"><div class="pret__t"><span class="prepare" style="white-space:nowrap">{ETINCELLE}Offre n° 418</span><span class="chip">TF Buddy</span></div>
            <table><tbody><tr><td>1 × Cramique 600 g</td><td class="num">9,50 €</td></tr><tr><td>1 × Cramique 600 g, sans raisins</td><td class="num">9,50 €</td></tr><tr><td>30 × Mini-croissant</td><td class="num">28,50 €</td></tr><tr class="total"><td><b style="font-weight:700">Total TVAC</b></td><td class="num"><b style="font-weight:700">47,50 €</b></td></tr></tbody></table>
            <div class="small muted" style="margin-top:6px">Retrait samedi 28/11 · 9:00 · cliente retrouvée dans TF Buddy</div></div>
        </div>
        <div class="dossier-pret">{ico(I['dossier'])}<span>Ensuite rangé dans <b>« Commandes clients »</b> · rappel la veille programmé (ven. 27/11, 17 h)</span></div>
        <div class="gestes"><span class="btn btn--lg">{ico(I['plustard'])}Plus tard<span class="kbd">←</span></span><span class="btn btn--lg">{ico(I['crayon'])}Modifier<span class="kbd">E</span></span><span class="btn btn--primary btn--lg">{ico(I['ok'])}Valider et envoyer<span class="kbd">→</span></span></div>
      </div>
    </div></div>

    <div class="nuit">
      <div class="card"><div class="v">17</div><div class="l">Rangés seuls</div><div class="n">Fournisseurs 6 · Factures 4 · Newsletters 7</div></div>
      <div class="card"><div class="v">6</div><div class="l">Réponses rédigées</div><div class="n">dans votre ton, appris sur vos 200 derniers envois</div></div>
      <div class="card"><div class="v">412,30 €</div><div class="l">3 offres prêtes</div><div class="n">clients et produits retrouvés dans TF Buddy</div></div>
      <div class="card"><div class="v">1</div><div class="l">À rappeler</div><div class="n">Kevin Janssens : ton en baisse, 2e e-mail</div></div>
    </div>
  </div>

  <div>
    <div class="voix card lisere">
      <div class="voix__t">{ia('ia--sm')}<div style="flex:1"><b>Mains dans la farine</b><span class="sub">Je lis à voix haute, vous répondez à voix haute.</span></div><span class="chip chip--ok"><i class="dot" style="background:var(--green)"></i>en écoute</span></div>
      <div class="onde">{onde}</div>
      <div class="micro">{ico(I['micro'])}</div>
      <div class="fil">
        <div class="bulle bulle--ia">E-mail 4 sur 14. Marie Peeters veut 2 cramiques, dont un sans raisins, et 30 mini-croissants pour samedi. L’offre fait 47,50 €. Je l’envoie ?<small>07:41</small></div>
        <div class="bulle bulle--moi">Dis oui à Marie, et propose 9 h.<small>07:41 · à la voix</small></div>
        <div class="bulle bulle--ia">C’est fait : réponse envoyée avec l’offre n° 418, retrait à 9 h. Suivant : la Brasserie De Kroon demande un devis pour 40 personnes.<small>07:42</small></div>
      </div>
    </div>
    <div class="card file"><div class="sec" style="padding:14px 14px 4px;margin:0">La file du matin <span class="aside">3 faits · 11 à venir</span></div>{fl}<div class="file__it" style="color:var(--muted)"><span class="etat"></span><span class="q">et 6 autres : 3 réponses, 2 rangements, 1 offre</span></div></div>
  </div>
</div>'''
    page('1-briefing-du-matin.html', 'Le briefing du matin', 1, 'Briefing', '', corps,
         'Maquette · option 1 sur 3. Pendant la nuit, l’IA lit la boîte, résume chaque e-mail en une ligne et prépare l’action : la réponse rédigée, l’offre de TF Buddy pré-remplie (le « Créer une offre » de la boîte, fait d’avance), le dossier. Le matin, une carte = un e-mail : → valider, ✎ modifier, ← plus tard. « Écouter le briefing » lit les cartes à voix haute et prend la réponse à la voix. Rien ne part sans un geste. Magasin et adresse réels ; clients, e-mails et montants illustrés.')


# ---------------------------------------------------------------------------------------------------------------------
# Option 2 · le pilote automatique
# ---------------------------------------------------------------------------------------------------------------------
def pilote():
    crans = [('Off', 'Je lis seulement'), ('Brouillons', 'Il rédige, vous envoyez'), ('Simples', 'Il répond seul aux questions simples'), ('Tout sauf les prix', 'Tout, sauf ce qui engage un prix')]
    cr = ''.join(f'<div class="cran {"on" if i == 2 else "passe" if i < 2 else ""}" style="left:{[0, 33.33, 66.66, 100][i]}%"><i></i><b>{t}</b><span>{d}</span></div>' for i, (t, d) in enumerate(crans))
    barres = ''.join(f'<i class="{"auj" if i == 5 else ""}" style="height:{h}px"></i>' for i, h in enumerate([22, 30, 26, 34, 38, 46, 0]))
    evs = [
        ('07:38', True, 'Disponibilité', 'Annelies De Smet', '« Il vous reste des tartes au riz pour midi ? »',
         'Bonjour Annelies, oui : il nous en reste 6 grandes et une dizaine de parts. Nous pouvons vous en mettre une de côté jusqu’à 12 h, il suffit de répondre à cet e-mail.',
         'Stock du jour · TF Buddy, 07:30', 97, '7 min 24'),
        ('07:33', True, 'Horaires', 'Pieter Goossens', '« Vous êtes ouverts le dimanche 29/11 ? »',
         'Bonjour Pieter, oui, nous sommes ouverts le dimanche 29/11 de 7 h à 13 h. À dimanche !',
         'Horaires du magasin', 99, '2 min 41'),
        ('06:58', False, 'Allergènes', 'Nathalie Lambert', '« Le cramique contient-il des fruits à coque ? »',
         'Notre cramique n’en contient pas, mais il est préparé dans un atelier qui en utilise : des traces sont possibles. Vous trouverez la fiche des allergènes en pièce jointe.',
         'Fiche allergènes · Cramique 600 g · pièce jointe : allergenes-cramique.pdf', 95, ''),
        ('06:12', False, 'Confirmation', 'Bureau Wauters &amp; fils', 'confirme les 2 plateaux de lundi',
         'Merci, c’est confirmé : 2 plateaux petits-déjeuners (12 personnes) livrés lundi 23/11 à 8 h, comme d’habitude.',
         'Commande n° 7731 · TF Buddy', 98, ''),
        ('05:47', False, 'Accusé', 'Jonas Peeters', 'demande de stage en pâtisserie',
         'Bonjour Jonas, merci pour votre candidature. Elle est bien reçue et transmise à Sophie, qui vous répondra la semaine prochaine.',
         'Modèle « Accusé de réception » · copie à Sophie', 99, ''),
        ('23:18', False, 'Disponibilité', 'Lotte Willems', '« Vous faites des bûches pour Noël ? »',
         'Oui ! Les précommandes des bûches ouvrent le 1er décembre. Nous vous écrirons ce jour-là avec la carte des parfums.',
         'Calendrier des fêtes du réseau', 91, ''),
    ]
    tr = ''
    for h, frais, cat, qui, q, r, src, p, reste in evs:
        an = f'<span class="annuler"><span class="compte"></span>Annuler l’envoi · {reste}</span>' if reste else '<span class="chip chip--ok" style="padding:2px 8px">envoyé</span>'
        tr += f'''<div class="ev{" ev--frais" if frais else ""}"><div class="ev__h">{h}</div><div><div class="ev__q"><span class="chip">{cat}</span><b>{qui}</b> <span class="ink2">{q}</span></div><div class="ev__r">{r}</div><div class="ev__src">{ico(I['source'])}<span>{src}</span>{an}</div></div><div>{anneau(p, "var(--green)" if p >= 95 else "var(--prize)")}</div></div>'''
    att = [
        ('KJ', 'Kevin Janssens', 'Gâteau d’anniversaire abîmé à la livraison', 'stop', 'Réclamation : règle « jamais ». Ton mécontent, c’est son 2e e-mail.', 'Ouvrir le brouillon', 'Appeler'),
        ('BK', 'Brasserie De Kroon', 'Plateaux pour 60 personnes, vendredi 4/12', 'stop', 'Commande B2B de 780 € : au-delà de 500 €, c’est vous qui décidez. L’offre n° 421 est pré-remplie.', 'Voir l’offre', 'Répondre'),
        ('AM', 'An Mertens', '« Une remise si je prends 10 tartes au riz ? »', 'stop', 'Remise demandée : règle « jamais ».', 'Ouvrir le brouillon', 'Répondre'),
        ('SD', 'Sarah Dubois', 'Un layer cake sans gluten et sans lactose ?', 'warn', 'Pas assez sûr (62 %) : la fiche du layer cake ne dit rien du lactose. Une ligne à compléter dans la fiche, et le pilote saura.', 'Compléter la fiche', 'Répondre'),
    ]
    at = ''.join(f'''<div class="att"><div class="att__h"><span class="av">{a}</span><div class="q"><b>{n}</b><span>{o}</span></div></div><div class="pourquoi pourquoi--{t}">{ico(I['stop'] if t == 'stop' else I['bouclier'])}<span>{w}</span></div><div class="att__b"><span class="btn btn--sm btn--primary">{b1}</span><span class="btn btn--sm">{b2}</span></div></div>''' for a, n, o, t, w, b1, b2 in att)
    regles_j = [('Accorder une remise ou un geste commercial', ''), ('Répondre à une réclamation', ''), ('Engager une commande B2B au-delà de', '500 €'), ('Promettre un délai de moins de 24 h', '')]
    regles_t = ['S’arrêter sous 85 % de confiance', 'Laisser 10 min pour annuler chaque envoi', 'Signer « L’équipe de L’Atelier By Halle »']
    rg = '<div class="jamais">Jamais</div>' + ''.join(f'<div class="regle"><span>{t}{f" <span class=chip>{v}</span>" if v else ""}</span><i class="bascule on"></i></div>' for t, v in regles_j)
    rg += '<div class="toujours">Toujours</div>' + ''.join(f'<div class="regle"><span>{t}</span><i class="bascule vert"></i></div>' for t in regles_t)
    corps = f'''
<div class="pilote-tete card lisere lueur">
  <div>
    <div style="display:flex;align-items:center;gap:12px">{ia()}<div style="flex:1"><div class="sec" style="margin:0">Autonomie du pilote</div><div style="font-size:15px;margin-top:2px">Il répond seul aux questions simples, et vous laisse tout le reste.</div></div><span class="vol"><i class="dot"></i>En vol depuis 23 jours</span></div>
    <div class="curseur"><div class="curseur__piste"></div><div class="curseur__plein"></div>{cr}</div>
  </div>
  <div class="gagne"><div class="sec" style="justify-content:flex-end;margin:0">Temps gagné cette semaine</div><div class="v" style="margin-top:8px">3 h 40 <small>min</small></div><div class="barres">{barres}</div><div class="jours"><span>lu</span><span>ma</span><span>me</span><span>je</span><span>ve</span><span>sa</span><span>di</span></div><div class="small muted" style="margin-top:6px">62 e-mails auxquels vous n’avez pas eu à répondre</div></div>
</div>
<div class="kpi-row">
  <div class="kpi"><div class="kpi__value">17</div><div class="kpi__label">Réponses cette nuit</div><div class="kpi__note">de 22:05 à 07:38</div></div>
  <div class="kpi"><div class="kpi__value">96 %</div><div class="kpi__label">Confiance moyenne</div><div class="kpi__note">seuil : 85 %, sinon il s’arrête</div></div>
  <div class="kpi"><div class="kpi__value">0</div><div class="kpi__label">Envoi annulé</div><div class="kpi__note">sur les 7 derniers jours</div></div>
  <div class="kpi"><div class="kpi__value">1 min 12</div><div class="kpi__label">Délai de réponse</div><div class="kpi__note">médian · avant : 4 h 30</div></div>
  <div class="kpi"><div class="kpi__value" style="color:var(--accent)">4</div><div class="kpi__label">Pour vous</div><div class="kpi__note">ce qu’il n’a pas voulu trancher</div></div>
</div>
<div class="pilote">
  <div class="card trace"><div class="trace__h"><div class="sec" style="margin:0 0 8px">Ce que le pilote a fait cette nuit <span class="aside">chaque réponse dit d’où elle tient ce qu’elle affirme</span></div></div>{tr}<div class="plus">et 11 autres réponses : horaires (5), disponibilités (3), accusés de réception (3) · <span style="color:var(--accent)">tout voir</span></div></div>
  <div>
    <div class="sec">Ce qui vous attend · 4 <span class="aside">et pourquoi le pilote n’a pas répondu</span></div>
    <div class="attend">{at}</div>
    <div class="card regles"><div class="sec">Les garde-fous <span class="aside">{ico(I['bouclier']).replace('<svg ', '<svg style="width:13px;height:13px;vertical-align:-2px" ')} réglés par la gérante</span></div>{rg}</div>
  </div>
</div>'''
    page('2-pilote-automatique.html', 'Le pilote automatique', 2, 'Pilote', '', corps,
         'Maquette · option 2 sur 3. Le pilote répond seul aux e-mails simples (horaires, allergènes, disponibilités, confirmations, accusés de réception) avec ce que le cockpit sait déjà : les horaires du magasin, les fiches allergènes, le stock et les commandes de TF Buddy, les modèles de réponse. Le curseur dit jusqu’où il va ; les règles « jamais » l’arrêtent, et chaque envoi reste annulable 10 minutes. Ce qu’il ne tranche pas attend la gérante, avec la raison et un brouillon. Magasin et adresse réels ; clients, e-mails et chiffres illustrés.')


# ---------------------------------------------------------------------------------------------------------------------
# Option 3 · le radar de la demande
# ---------------------------------------------------------------------------------------------------------------------
def radar():
    jours = ['lu 23', 'ma 24', 'me 25', 'je 26', 've 27', 'sa 28', 'di 29', 'lu 30', 'ma 1', 'me 2', 'je 3', 've 4', 'sa 5', 'di 6']
    # [Saint-Nicolas, anniversaires et fêtes, plateaux B2B, cramiques et viennoiseries] × (confirmé, demandé sans réponse), en €
    D = [
        [(0, 0), (85, 0), (180, 0), (60, 0)], [(30, 0), (40, 35), (0, 0), (45, 0)], [(45, 0), (120, 0), (260, 0), (30, 20)],
        [(60, 30), (60, 45), (0, 120), (40, 0)], [(80, 40), (95, 0), (310, 0), (55, 0)], [(120, 60), (210, 90), (0, 0), (140, 60)],
        [(70, 0), (150, 0), (0, 0), (90, 40)], [(90, 45), (40, 0), (190, 0), (30, 0)], [(130, 80), (60, 0), (0, 0), (20, 0)],
        [(160, 60), (75, 40), (220, 0), (25, 0)], [(210, 90), (40, 0), (0, 150), (30, 0)], [(260, 120), (90, 0), (420, 360), (40, 0)],
        [(380, 210), (180, 60), (0, 0), (120, 40)], [(420, 640), (60, 0), (0, 0), (80, 0)],
    ]
    C = ['#B0792A', '#B03048', '#7a736a', '#D8C6B0']
    W, H, x0, y0, haut = 760, 312, 58, 274, 200
    vmax = 1400
    pas = (W - x0 - 6) / 14
    lb = pas * 0.58
    s = []
    for v in (0, 350, 700, 1050, 1400):
        y = y0 - haut * v / vmax
        s.append(f'<line x1="{x0}" x2="{W}" y1="{y:.1f}" y2="{y:.1f}" stroke="rgba(34,34,34,.06)"/><text x="{x0-6}" y="{y+3:.1f}" font-size="10" fill="#B8B2A8" text-anchor="end">{v:,}'.replace(',', ' ') + ' €</text>' if v else f'<line x1="{x0}" x2="{W}" y1="{y:.1f}" y2="{y:.1f}" stroke="#e6e0d8"/><text x="{x0-6}" y="{y+3:.1f}" font-size="10" fill="#B8B2A8" text-anchor="end">0</text>')
    s.append('<defs>' + ''.join(f'<pattern id="h{i}" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="6" height="6" fill="#fff"/><rect width="3" height="6" fill="{c}" opacity=".55"/></pattern>' for i, c in enumerate(C))
             + '<radialGradient id="halo"><stop offset="0" stop-color="#B0792A" stop-opacity=".22"/><stop offset="1" stop-color="#B0792A" stop-opacity="0"/></radialGradient></defs>')
    for j, (lab, d) in enumerate(zip(jours, D)):
        x = x0 + j * pas + (pas - lb) / 2
        if j == 13:
            s.append(f'<rect x="{x-8:.1f}" y="64" width="{lb+16:.1f}" height="{y0-56:.1f}" rx="10" fill="rgba(176,121,42,.07)" stroke="rgba(176,121,42,.35)" stroke-dasharray="3 3"/>')
        y = y0
        for i, (conf, att) in enumerate(d):
            for val, motif in ((conf, C[i]), (att, f'url(#h{i})')):
                if not val:
                    continue
                h = haut * val / vmax
                y -= h
                s.append(f'<rect x="{x:.1f}" y="{y:.1f}" width="{lb:.1f}" height="{h-1.2:.1f}" rx="2" fill="{motif}"' + (f' stroke="{C[i]}" stroke-width=".8"' if motif.startswith('url') else '') + '/>')
        tot = sum(a + b for a, b in d)
        s.append(f'<text x="{x+lb/2:.1f}" y="{y-6:.1f}" font-size="9.5" fill="#5c5852" text-anchor="middle">{tot:,}'.replace(',', ' ') + '</text>')
        we = lab.startswith(('sa', 'di'))
        s.append(f'<text x="{x+lb/2:.1f}" y="{y0+16}" font-size="10.5" fill="{"#8D1D2C" if j == 13 else "#1c1a17" if we else "#7a736a"}" text-anchor="middle">{lab}</text>')
    # samedi 28 : la capacité tendue
    x28 = x0 + 5 * pas + pas / 2
    s.append(f'<text x="{x28:.1f}" y="{y0-haut*690/vmax-20:.1f}" font-size="9.5" fill="#7A5207" text-anchor="middle">92 % capa.</text>')
    s.append(f'<line x1="{x0 + 7*pas:.1f}" x2="{x0 + 7*pas:.1f}" y1="62" y2="{y0}" stroke="#e6e0d8" stroke-dasharray="2 3"/><text x="{x0 + 7*pas + 4:.1f}" y="72" font-size="9.5" fill="#B8B2A8">semaine 49</text>')
    # bulle du 6/12
    x6 = x0 + 13 * pas + pas / 2
    s.append(f'<circle cx="{x6:.1f}" cy="{y0-haut*1200/vmax:.1f}" r="34" fill="url(#halo)"/>')
    bx = x6 - 262
    s.append(f'<g><rect x="{bx:.1f}" y="4" width="200" height="48" rx="9" fill="#fff" stroke="rgba(176,121,42,.45)"/><text x="{bx+12:.1f}" y="23" font-size="11" fill="#1c1a17">dim. 6/12 · Saint-Nicolas</text><text x="{bx+12:.1f}" y="40" font-size="10.5" fill="#7A5207">640 € demandés, sans réponse</text><line x1="{bx+200:.1f}" y1="28" x2="{x6:.1f}" y2="{y0-haut*1200/vmax-22:.1f}" stroke="rgba(176,121,42,.5)"/></g>')
    svg = f'<svg viewBox="0 0 {W} {H}" width="100%" style="display:block;margin-top:8px">{"".join(s)}</svg>'
    leg = ''.join(f'<span><i style="background:{c}"></i>{l}</span>' for c, l in zip(C, ['Saint-Nicolas', 'Anniversaires et fêtes', 'Plateaux B2B', 'Cramiques et viennoiseries'])) + '<span><i class="hachure" style="--c:#7a736a"></i>demandé, pas encore confirmé</span>'
    jg = [('Spéculoos', 118, 160, 64, 'pièces', ''), ('Sujets chocolat', 34, 40, 12, 'pièces', 'dépasse de 6 si tout est confirmé'), ('Massepain', 22, 50, 8, 'pièces', ''), ('Cramiques', 18, 40, 6, 'pièces', '')]
    jgh = ''
    for n, conf, cap, att, u, note in jg:
        pc, pa = 100 * conf / cap, min(100 - 100 * conf / cap, 100 * att / cap)
        col = 'var(--prize)' if (conf + att) / cap > .9 else 'var(--green)'
        jgh += f'<div class="jauge"><b>{n}<span>{conf}+{att} / {cap}</span></b><div class="piste"><i style="width:{pc:.0f}%;background:{col}"></i><em style="left:{pc:.0f}%;width:{pa:.0f}%"></em></div><small{" style=color:var(--prize-ink)" if note else ""}>{note or f"{u} · capacité du jour"}</small></div>'
    lignes = [
        ('di 6/12', 'Basisschool Sint-Rochus · Els Vermeulen', '120 spéculoos individuels, 6 cramiques', '438,00 €', 'stop', 'sans réponse · 2 j'),
        ('di 6/12', 'Famille Dubois', '12 sujets Saint-Nicolas en chocolat', '118,00 €', 'stop', 'sans réponse · 1 j'),
        ('di 6/12', 'Chiro Halle', '80 spéculoos pour la fête des membres', '84,00 €', 'stop', 'sans réponse · 9 h'),
        ('ve 4/12', 'Brasserie De Kroon', 'plateaux petits-déjeuners, 60 personnes', '780,00 €', 'warn', 'offre n° 421 à valider'),
        ('sa 28/11', 'Marie Peeters', '2 cramiques, 30 mini-croissants', '47,50 €', 'ok', 'confirmée'),
    ]
    tb = ''.join(f'<tr><td style="white-space:nowrap">{a}</td><td>{b}<span class="sub">{c}</span></td><td class="num">{d}</td><td><span class="chip chip--{e}">{f}</span></td></tr>' for a, b, c, d, e, f in lignes)
    hist = ''.join(f'<i style="height:{h}px;width:14px;border-radius:3px 3px 1px 1px;background:{"var(--prize)" if i == 11 else "var(--chart-prev)"}"></i>' for i, h in enumerate([8, 12, 0, 6, 10, 0, 14, 8, 0, 10, 12, 30]))
    corps = f'''
<div class="alertes">
  <div class="alerte alerte--or"><span class="alerte__i">{ico(I['cloche'])}</span><div style="flex:1"><b>3 demandes Saint-Nicolas sans réponse pour le dimanche 6/12</b><p><b style="display:inline;font-weight:700">640 € en jeu.</b> La plus ancienne attend depuis 2 jours ; l’an dernier, Sint-Rochus avait commandé le 20/11.</p><span class="btn btn--sm btn--primary">{ETINCELLE.replace('<svg ', '<svg style="fill:currentColor;stroke:none" ')}Répondre aux 3 (brouillons prêts)</span></div></div>
  <div class="alerte"><span class="alerte__i alerte__i--r">{ico(I['four'])}</span><div><b>Samedi 28/11 : 92 % de la capacité</b><p>Une communion et 4 anniversaires : prévoir une personne de plus au laboratoire, de 5 h à 9 h.</p><span class="btn btn--sm">Voir la production</span></div></div>
  <div class="alerte"><span class="alerte__i alerte__i--s">{ico(I['humeur'])}</span><div><b>2 clients mécontents à rappeler</b><p>Kevin Janssens (gâteau abîmé) et la Brasserie De Kroon (facture en double).</p><span class="btn btn--sm">{ico(I['tel'])}Rappeler</span></div></div>
</div>
<div class="radar">
  <div>
    <div class="card"><div class="sec">La demande des deux prochaines semaines <span class="aside">en € TVAC</span><span class="seg" style="text-transform:none;letter-spacing:0"><span class="is-on">14 jours</span><span>30 jours</span><span>Saison des fêtes</span></span></div>
      <div class="legende">{leg}</div>{svg}
      <div class="sec" style="margin:14px 0 0">Dimanche 6/12 · la production face à la demande <span class="aside">confirmé + demandé / capacité</span></div>
      <div class="jauges" style="border-top:0;padding-top:0">{jgh}</div>
    </div>
    <div class="bas">
      <div class="card" style="padding:0;overflow:hidden"><div class="sec" style="padding:16px 18px 8px;margin:0">Les demandes repérées dans les e-mails <span class="aside">27 en 14 jours · 6 sans réponse</span></div><table><thead><tr><th>Pour le</th><th>Client · demande</th><th class="num">Estimé</th><th>État</th></tr></thead><tbody>{tb}</tbody></table></div>
    </div>
  </div>
  <div style="display:grid;gap:16px">
  <div class="card fiche lisere">
    <div class="fiche__h lueur"><div class="sec" style="margin:0 0 12px">Fiche client 360 {ia('ia--sm').replace('class="ia', 'style="margin-left:auto" class="ia')}</div>
      <div class="fiche__qui"><span class="av av--lg">EV</span><div><b>Els Vermeulen</b><span class="sub">Basisschool Sint-Rochus · comité des parents · Halle</span><span class="sub">els.vermeulen@sint-rochus.be</span></div></div></div>
    <div class="fiche__chiffres"><div><div class="v">6</div><div class="l">Commandes</div></div><div><div class="v">1 482,60 €</div><div class="l">Dépensés</div></div><div><div class="v">2024</div><div class="l">Depuis</div></div></div>
    <div class="fiche__b"><div class="fiche__t">Commandes TF Buddy</div><div style="display:flex;align-items:flex-end;gap:5px;height:34px">{hist}</div><div style="display:flex;justify-content:space-between;font-size:9.5px;color:var(--faint);margin-top:4px"><span>déc. 2025</span><span>nov. 2026</span></div>
      <div class="small ink2" style="margin-top:8px;line-height:1.5">6/12/2025 : 110 spéculoos + 4 cramiques, 412,00 €, livrés à l’école à 8 h 30. Fancy-fair de juin : 3 plateaux.</div></div>
    <div class="fiche__b"><div class="fiche__t">Dernier e-mail · jeu. 19/11, 21:14</div><div class="small" style="line-height:1.55;color:var(--ink)">« Bonjour, comme l’an dernier, pourriez-vous nous préparer 120 spéculoos individuels et 6 cramiques pour la fête du 6/12 ? Livraison à l’école vers 8 h 30 si possible. Merci ! Els »</div></div>
    <div class="fiche__b"><div class="fiche__t">Ce que l’IA en lit</div>
      <div class="proba"><span class="v">91 %</span><span class="small ink2">de chances de commander si vous répondez avant lundi · estimé <b>438,00 €</b></span></div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px"><span class="chip chip--ok">ton chaleureux</span><span class="chip">commande en novembre</span><span class="chip">livraison 8 h 30</span><span class="chip chip--warn">sensible au délai</span></div></div>
    <div class="fiche__b" style="display:grid;grid-template-columns:1fr auto;gap:8px"><span class="btn btn--primary btn--sm">{ico(I['crayon'])}Répondre · brouillon prêt</span><span class="btn btn--sm">{ico(I['offre'])}Créer l’offre</span></div>
  </div>
      <div class="card"><div class="sec">Le thermomètre d’humeur · 212 e-mails <span class="aside">30 jours</span></div>
        <div class="humeur"><i style="left:84%"></i></div><div class="humeur__l"><span>mécontent</span><span>neutre</span><span>chaleureux</span></div>
        <div style="display:flex;gap:16px;margin-top:14px;font-size:12px"><span><b style="font-weight:700;font-size:16px">81 %</b> chaleureux</span><span><b style="font-weight:700;font-size:16px">15 %</b> neutres</span><span style="color:var(--stop)"><b style="font-weight:700;font-size:16px">4 %</b> mécontents</span></div>
        <div class="small ink2" style="margin-top:10px;line-height:1.5">En baisse cette semaine : <b>Kevin Janssens</b> (« deuxième fois que… ») et <b>Brasserie De Kroon</b> (facture). Le reste du ton monte avec les fêtes.</div></div>
  </div>
</div>'''
    page('3-radar-de-la-demande.html', 'Le radar de la demande', 3, 'Radar', '', corps,
         'Maquette · option 3 sur 3. Les e-mails deviennent une prévision : chaque demande repérée (jour, produits, quantités, client retrouvé dans TF Buddy) s’empile sur la frise des deux semaines, face à la capacité de production du magasin. Les alertes disent ce qui est en jeu et ce qui attend ; le thermomètre lit le ton des e-mails ; la fiche client réunit ses commandes TF Buddy, son dernier e-mail et ce que l’IA en déduit. Magasin et adresse réels ; clients, demandes, montants et capacités illustrés.')


briefing()
pilote()
radar()
