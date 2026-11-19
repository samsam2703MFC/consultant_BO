# Assistant IA du dashboard magasin (12/11/2026, Sam : « dans le cockpit du franchisé shop, une IA, avec des questions
# qu'il peut poser, et des questions préétablies — comment s'est passée ma semaine ? que puis-je améliorer ? … ;
# un bouton qui ouvre une modale ; une ou deux maquettes en capture, ensuite on décide »).
#   a-conversation.html : la modale en conversation, les questions prêtes à gauche ;
#   b-bilan.html        : la modale en bilan, des tuiles de questions et une réponse rangée en priorités.
# Chiffres réels de L'Atelier by Halle, lus par l'API du panel le 12/11/2026 (sondes tools/sonde_*_halle.php).
import os, re
ICI = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(ICI, '../fidelite-franchise/index.html')).read()
STYLE = re.search(r'<style>(.*?)</style>', src, re.S).group(1) + open(os.path.join(ICI, 'assistant.css')).read()
ONGLETS = ['Opérationnel', 'Tâches', 'Semaine', 'Mois', 'Trimestre', 'Année', 'Produits', 'Promotions', 'Primes', 'Concurrence', 'Temps morts', 'Fidélité']
ETOILE = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/><path d="M19 15l.8 1.9 1.9.8-1.9.8L19 20.4l-.8-1.9-1.9-.8 1.9-.8z"/></svg>'
ENVOI = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>'

def fond():
    """Le dashboard du magasin derrière la modale, avec le bouton Assistant dans son entête."""
    tuiles = ''.join(f'<div class="f-t"><span>{k}</span><b>{v}</b><small>{s}</small></div>' for k, v, s in (
        ('CA hier', '3 492 €', 'mercredi 11, férié'), ('Tickets', '226', '4,96 articles'), ('Panier', '15,45 €', 'mois : 13,50 €'),
        ('Semaine', '6 755 €', 'lun. → mer.'), ('Mois', '24 344 €', '1er → 11'), ('Contrôles', '0 / 75', 'à midi')))
    return (f'''<div class="fond"><div class="db-head"><div><div class="db-titre-v3">Dashboard magasin</div><div class="db-sous-v3">L’Atelier by Halle · jeudi 12 novembre</div></div>
      <div class="db-droite"><button class="btn-ia">{ETOILE}Assistant</button><select class="db-sel-v3"><option>L’Atelier by Halle</option></select></div></div>
      <div class="db-ong-v3">''' + ''.join(f'<button class="{"on" if o == "Opérationnel" else ""}">{o}</button>' for o in ONGLETS) + f'''</div>
      <div class="f-tuiles">{tuiles}</div><div class="f-bloc"></div><div class="f-bloc b2"></div></div>''')

QUESTIONS = [
    ('Ma semaine', ['Comment s’est passée ma semaine ?', 'Que puis-je améliorer ?', 'Qu’est-ce qui a le mieux marché ?']),
    ('Mes ventes', ['Pourquoi le mardi est-il si faible ?', 'Quels produits pousser ce week-end ?', 'Mon panier moyen est-il bon ?']),
    ('Mon équipe', ['Mon planning colle-t-il à mes ventes ?', 'Où en sont mes contrôles ?']),
    ('Mes clients', ['Qui sont mes clients fidèles ?', 'Combien de clients scannent leur carte ?']),
]

def page(titre, modale):
    return ('<!doctype html>\n<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">\n'
            f'<title>{titre}</title>\n<style>{STYLE}</style></head>\n<body>{fond()}<div class="voile"></div>{modale}</body></html>')

# ── A · Conversation ────────────────────────────────────────────────────────────────────────────────────────────────
qa = ''.join(f'<p class="q-grp">{g}</p>' + ''.join(f'<button class="q{" on" if q == "Comment s’est passée ma semaine ?" else ""}">{q}</button>' for q in L) for g, L in QUESTIONS)
reponse_a = '''
  <div class="bulle moi">Comment s’est passée ma semaine ?</div>
  <div class="bulle ia">
    <p class="ia-tete">Bonne semaine, portée par le week-end. Du lundi 2 au dimanche 8 novembre :</p>
    <div class="ia-k"><div><b>14 255 €</b><span>CA · +4,1 % sur la semaine d’avant</span></div><div><b>1 086</b><span>tickets · +2,6 %</span></div><div><b>13,13 €</b><span>panier moyen · seuil 18 €</span></div><div><b>18,9 %</b><span>salaires du CA · cible 25 %</span></div></div>
    <ul>
      <li><b>Le week-end fait presque la moitié</b> : samedi 3 362 € et dimanche 3 243 €, soit 46 % de la semaine.</li>
      <li><b>Le creux, c’est mardi</b> : 1 329 €, 117 tickets. Du lundi au jeudi, aucun jour ne passe 1 650 €.</li>
      <li><b>Les contrôles ont décroché</b> : de jeudi à dimanche, entre 0 et 11 % des tâches cochées.</li>
      <li><b>Peu de clients reconnus</b> : 27 tickets sur 1 085 portent un client, et aucun n’a sa carte fidélité active.</li>
    </ul>
    <p class="ia-src">Lu dans les ventes, le P&amp;L, les contrôles et les tickets du magasin · jeudi 12/11, 11 h 59</p>
  </div>
  <div class="suites"><span>Ensuite :</span><button>Que puis-je améliorer ?</button><button>Détaille le samedi</button><button>Compare avec le mois dernier</button></div>'''
modale_a = f'''<div class="modale m-a" role="dialog" aria-modal="true" aria-label="Assistant du magasin">
  <header class="m-h"><span class="m-ic">{ETOILE}</span><div><b>Assistant du magasin</b><span>L’Atelier by Halle · il lit les chiffres de votre magasin, rien d’autre</span></div><button class="m-x" aria-label="Fermer">×</button></header>
  <div class="m-corps">
    <aside class="m-q"><p class="m-q-t">Questions toutes prêtes</p>{qa}</aside>
    <section class="m-conv"><div class="fil">{reponse_a}</div>
      <div class="saisie"><input type="text" placeholder="Posez votre question sur votre magasin…" value=""><button class="btn btn--primary">{ENVOI}</button></div>
      <p class="m-pied">Les réponses sont rédigées par l’assistant à partir des chiffres du magasin. Vérifiez avant d’agir.</p>
    </section>
  </div></div>'''

# ── B · Bilan ───────────────────────────────────────────────────────────────────────────────────────────────────────
tuiles_b = ''.join(f'<button class="tq{" on" if t == "Que puis-je améliorer ?" else ""}"><b>{t}</b><span>{s}</span></button>' for t, s in (
    ('Comment s’est passée ma semaine ?', 'ventes, équipe, contrôles, clients'),
    ('Que puis-je améliorer ?', 'les trois priorités, chiffrées'),
    ('Qu’est-ce qui marche le mieux ?', 'jours, produits, moments'),
    ('Comment va ma journée ?', 'à cette heure, face à l’objectif'),
))
prio = [
    ('1', 'Faire monter le panier moyen', '13,13 € la semaine dernière, sous le seuil bas du réseau (18 €). Samedi et dimanche, il passe 15 € ; du lundi au vendredi, il reste entre 11 et 12 €.',
     '+ 1 € de panier sur 1 086 tickets = + 1 086 € par semaine', 'Voir mes produits'),
    ('2', 'Remettre les contrôles en route', '0 tâche cochée sur 75 aujourd’hui à midi ; entre 0 et 34 % les sept derniers jours. L’ouverture (6 h – 9 h) n’est presque jamais validée.',
     '22 tâches d’ouverture à cocher chaque matin', 'Ouvrir les contrôles'),
    ('3', 'Jeter moins de viennoiserie', 'Cette semaine : croissant 12 % des ventes jetées, baulus 25 %, tortillon 15 %, presque tout pour date dépassée.',
     '146 pièces jetées depuis lundi, 264,53 € de ventes', 'Voir les pertes'),
]
prio_h = ''.join(f'''<article class="prio"><span class="prio-n">{n}</span><div><h4>{t}</h4><p>{d}</p><p class="prio-g">{g}</p></div><button class="btn btn--sm">{a} ›</button></article>''' for n, t, d, g, a in prio)
modale_b = f'''<div class="modale m-b" role="dialog" aria-modal="true" aria-label="Assistant du magasin">
  <header class="m-h"><span class="m-ic">{ETOILE}</span><div><b>Assistant du magasin</b><span>L’Atelier by Halle</span></div><button class="m-x" aria-label="Fermer">×</button></header>
  <div class="m-b-corps">
    <div class="saisie haut"><input type="text" placeholder="Posez votre question : « pourquoi mardi est faible ? », « quoi mettre en avant samedi ? »…"><button class="btn btn--primary">Demander</button></div>
    <div class="tqs">{tuiles_b}</div>
    <section class="rep">
      <div class="rep-h"><h3>Que puis-je améliorer ?</h3><span>semaine du 2 au 8 novembre, et cette semaine · lu à 11 h 59</span></div>
      <p class="rep-intro">Trois priorités, de la plus rentable à la plus simple :</p>
      {prio_h}
      <div class="rep-pied"><span>Rédigé par l’assistant à partir des ventes, du P&amp;L, des contrôles et des pertes du magasin. Vérifiez avant d’agir.</span><span class="avis"><button class="btn btn--sm">Utile</button><button class="btn btn--sm">Pas utile</button><button class="btn btn--sm">Copier</button></span></div>
    </section>
  </div></div>'''

open(os.path.join(ICI, 'a-conversation.html'), 'w').write(page('Maquette — Dashboard magasin · Assistant (A, conversation)', modale_a))
open(os.path.join(ICI, 'b-bilan.html'), 'w').write(page('Maquette — Dashboard magasin · Assistant (B, bilan)', modale_b))
print('deux pages écrites')
