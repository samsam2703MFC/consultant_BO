# Maquettes de l'arbitrage « Répartition des charges » (cockpit) ↔ « Compte de résultat réseau » (CRM, impl_pnl).
# Données : le compte du CRM tel que collé par le client le 07/11/2026 ; les taux et les bases du cockpit lus en ligne
# (Budget › Paramètres, magasin Corbais : CA théorique 1 800 000 €, validé 232 000 € sur 2 mois, réel 1 535 053 € sur 11 mois).
import html
E = lambda v: f"{v:,.0f} €".replace(',', ' ')
P = lambda v: (f"{v:.1f}".replace('.', ',') + ' %') if v is not None else '—'
CA_THEO, CA_VAL, CA_REEL = 1_800_000, 232_000, 1_535_053

# Le CRM (impl_pnl) : rubriques → postes (clé, libellé, description, gestion, %), sous-totaux « a − b ».
CRM = [
 ('rub', 'matieres', 'Matière première & Emballage', [
   ('food_cost_pct', 'Matière première', '', '', 38), ('emballage_pct', 'Emballage', '', '', 2)]),
 ('st', 'marge_brute', 'Marge brute', 'Chiffre d’affaires − Matière première & Emballage'),
 ('rub', 'occupation', 'Occupation', [
   ('loyer', 'Loyer + charges locatives', 'Selon emplacement', 'Fixe', 6, 'lu du bail du dossier'),
   ('energie_pct', 'Énergie (eau, gaz, électricité)', 'Variable selon la saison', 'Groupement d’achat', 5),
   ('maintenance_pct', 'Maintenance & nettoyage', 'Entretien du matériel', 'Préventif et régulier', 0.5),
   ('assurances_pct', 'Assurances, licences, etc.', '', 'Suivi des échéances', 1)]),
 ('rub', 'redevances', 'Redevances de marque', [
   ('royalties_pct', 'Royalties franchise & assistance', 'Redevance de marque 2 % et d’assistance 2 % (formation continue comprise)', 'Fixe', 4),
   ('marketing_pct', 'Marketing national', 'Communication', 'Fixe', 2.5)]),
 ('rub', 'investissement', 'Investissement', [('amortissements_pct', 'Amortissements / leasing', 'Machines, mobilier', 'Fixe', 7)]),
 ('rub', 'frais_generaux', 'Frais généraux', [('autres_pct', 'Autres (comptabilité, banque…)', '', 'Optimisation des prestataires', 1.5)]),
 ('st', 'disponible', 'Disponible pour rémunération', 'Chiffre d’affaires − toutes les rubriques'),
]
# Le cockpit aujourd'hui (budgetCharges) : % théorique et % validé par poste, rangés sur la clé du CRM.
COCK = {  # clé CRM → (libellé cockpit, % théo, % validé)
 'food_cost_pct': [('Matière première', 38, 0)], 'emballage_pct': [('Emballage', 2, 0)],
 'loyer': [('Loyer + charges locatives', 5, 7)], 'energie_pct': [('Énergie (eau, gaz, électricité)', 5, 5)],
 'maintenance_pct': [('Maintenance & nettoyage', 0.5, 1)], 'assurances_pct': [('Assurances, licences, etc.', 1, 1)],
 'royalties_pct': [('Royalties franchise', 2, 2), ('Redevance assistance', 2, 1.5)],
 'marketing_pct': [('Marketing national / local', 2, 1.5)], 'amortissements_pct': [('Amortissements / leasing', 5, 5)],
 'autres_pct': [('Autres (comptabilité, banque)', 2, 1.5)],
}
VALIDE = {k: sum(x[2] for x in v) for k, v in COCK.items()}   # le % validé gardé, rangé sur la clé du CRM

CSS = """<link rel="stylesheet" href="../../../public/assets/ds/global.css">
<style>
body{margin:0;background:var(--color-bg,#F7F4EF);font-family:var(--font-ui,'Gotham',system-ui);color:#1c1a17}
.w{max-width:1180px;margin:0 auto;padding:22px 24px 30px}
.k{font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:#7a736a}
h1{font:400 26px var(--font-display,'Gotham');margin:4px 0 14px}
.card{background:#fff;border:1px solid #e6e0d8;border-radius:12px;padding:20px 22px;margin-bottom:16px}
.t{font:400 18px var(--font-display,'Gotham')} .s{font-size:12px;color:#7a736a;margin-top:2px}
.src{display:flex;gap:10px;align-items:center;background:rgba(141,29,44,.05);border:1px solid rgba(141,29,44,.2);border-radius:9px;padding:10px 13px;margin:14px 0;font-size:11.5px;line-height:1.5}
.tag{font-size:10px;padding:2px 9px;border-radius:6px;background:#8D1D2C;color:#fff;white-space:nowrap}
.tag.or{background:#B0792A}.tag.vert{background:#3f7a52}.tag.gris{background:#7a736a}
.btn{border:1px solid #e6e0d8;background:#fff;border-radius:8px;padding:7px 13px;font-size:12px;color:#1c1a17;white-space:nowrap}
.btn.p{background:#8D1D2C;border-color:#8D1D2C;color:#fff}
table{width:100%;border-collapse:collapse;font-size:12.5px}
th{font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:#7a736a;font-weight:400;text-align:right;padding:0 6px 8px}
th.l{text-align:left} td{padding:7px 6px;text-align:right;white-space:nowrap;border-top:1px solid #efeae3}
td.l{text-align:left;white-space:normal} .rub td{border-top:none;padding-top:14px;font-size:13px}
.rub td.l{color:#1c1a17}.rub small{color:#7a736a;margin-left:8px}
.st td{background:#FBF9F5;border-top:1px solid #e6e0d8}.st .f{font-size:11px;color:#7a736a}
.tot td{border-top:1px solid #c9c2b8}
.lock{display:inline-block;min-width:44px;padding:5px 8px;border-radius:7px;background:#FBF9F5;border:1px solid #efeae3;color:#5c5852}
.in{display:inline-block;min-width:44px;padding:5px 8px;border-radius:7px;border:1px solid #d9d2c8;background:#fff}
.o{color:#B0792A}.m{color:#7a736a}.r{color:#b3261e}.g{color:#3f7a52}
.d{font-size:11px;color:#7a736a}
.ec.r{background:#FBEDEC}.ec.g{background:#EEF5F0}
.grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.opt{border:1px solid #e6e0d8;border-radius:12px;padding:16px;background:#fff}
.opt.reco{border:2px solid #8D1D2C}
.opt h3{font:400 16px var(--font-display,'Gotham');margin:6px 0 8px}
.opt ul{margin:6px 0 0 16px;padding:0;font-size:12.5px;line-height:1.55}
.pl{color:#3f7a52}.mo{color:#b3261e}
</style>"""

def maquette_alignee():
    rows = []; tot_t = tot_v = 0.0
    def pnum(v): return f"{v:g}".replace('.', ',')
    for it in CRM:
        if it[0] == 'rub':
            _, key, lib, postes = it
            rt = sum(p[4] for p in postes); rv = sum(VALIDE.get(p[0], 0) for p in postes)
            rows.append(f"<tr class='rub'><td class='l' colspan='10'>{html.escape(lib)}<small>{P(rt)} du CA réseau · validé {P(rv)}</small></td></tr>")
            for p in postes:
                k, l, desc, gest, pct = p[:5]; note = p[5] if len(p) > 5 else ''
                v = VALIDE.get(k, 0); tot_t += pct; tot_v += v
                ec = v - pct
                if v == 0 and pct > 0:   # pas de % validé saisi au cockpit : « à saisir », pas d'écart inventé
                    rows.append(f"<tr><td class='l'>{html.escape(l)}</td><td class='l m'>{html.escape(desc) or '—'}</td><td class='l m'>{html.escape(gest) or '—'}</td>"
                        f"<td><span class='lock'>{pnum(pct)}</span></td><td class='o'>{E(CA_THEO*pct/100)}</td><td><span class='in o'>à saisir</span></td><td class='m'>—</td><td class='m'>—</td><td class='m'>—</td></tr>")
                    continue
                rows.append(f"<tr><td class='l'>{html.escape(l)}{'<div class=d>'+html.escape(note)+'</div>' if note else ''}</td>"
                    f"<td class='l m'>{html.escape(desc) or '—'}</td><td class='l m'>{html.escape(gest) or '—'}</td>"
                    f"<td><span class='lock' title='Compte de résultat réseau du CRM'>{pnum(pct)}</span></td><td class='o'>{E(CA_THEO*pct/100)}</td>"
                    f"<td><span class='in'>{pnum(v)}</span></td><td>{E(CA_VAL*v/100)}</td><td class='m'>{E(CA_REEL*v/100)}</td>"
                    f"<td class='{'r' if ec>0 else 'g' if ec<0 else 'm'}'>{('+' if ec>0 else '−' if ec<0 else '')}{pnum(abs(ec))} pt</td></tr>")
        else:
            _, key, lib, f = it
            # sous-total : CA − rubriques précédentes (marge brute) ou toutes (disponible)
            if key == 'marge_brute':
                t = 100 - 40; v = None   # matière non validée au cockpit
            else:
                t = 100 - tot_t; v = 100 - tot_v
            rows.append(f"<tr class='st'><td class='l'>= {html.escape(lib)}<div class='f'>{html.escape(f)}</div></td><td></td><td></td>"
                f"<td>{P(t)}</td><td class='o'>{E(CA_THEO*t/100)}</td><td>{P(v)}</td><td>{E(CA_VAL*v/100) if v is not None else '—'}</td><td class='m'>{E(CA_REEL*v/100) if v is not None else '—'}</td><td></td></tr>")
    rows.append(f"<tr class='tot'><td class='l' colspan='3'>Total charges d’exploitation</td><td>{P(tot_t)}</td><td class='o'>{E(CA_THEO*tot_t/100)}</td><td>{P(tot_v)}</td><td>{E(CA_VAL*tot_v/100)}</td><td class='m'>{E(CA_REEL*tot_v/100)}</td><td></td></tr>")
    rows.append(f"<tr class='st'><td class='l' colspan='3'>= Résultat avant impôt<div class='f'>disponible pour rémunération − rémunération du gérant − charge financière (par dossier)</div></td><td>{P(100-tot_t)}</td><td class='o'>{E(CA_THEO*(100-tot_t)/100)}</td><td>{P(100-tot_v)}</td><td>{E(CA_VAL*(100-tot_v)/100)}</td><td></td><td></td></tr>")
    return f"""<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Maquette A — Répartition des charges alignée sur le CRM</title>{CSS}</head><body><div class="w">
<div class="k">Vue réseau · maquette A (recommandée)</div><h1>Paramètres du budget</h1>
<div class="card"><div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap">
<div><div class="t">Répartition des charges</div><div class="s">La structure et le % réseau viennent du Compte de résultat réseau du CRM ; le cockpit garde le % validé et le réel.</div></div>
<div style="display:flex;gap:8px"><span class="btn">Modifier dans le CRM ↗</span><span class="btn p">Enregistrer le % validé</span></div></div>
<div class="src"><span class="tag">CRM</span><div><b style="font-weight:400">Compte de résultat réseau</b> · crm_settings · impl_pnl — lu le 07/11/2026 à 10:12 · 6 rubriques, 11 postes, 2 sous-totaux.
Rubriques, postes, libellés, descriptions et <b style="font-weight:400">% réseau</b> sont verrouillés ici : ce sont ceux du DIP (annexe 11), du contrat et des dossiers d’implantation.</div></div>
<table><thead><tr><th class="l">Poste</th><th class="l">Description</th><th class="l">Gestion</th><th>% réseau <span class="tag" style="font-size:9px">CRM</span></th><th class="o">€ théorique</th><th>% validé</th><th>€ validé</th><th>€ réel</th><th>Validé − réseau</th></tr></thead>
<tbody>{''.join(rows)}</tbody></table>
<div class="d" style="margin-top:10px">Magasin : Atelier by Berlo – Corbais · CA théorique 1 800 000 € (étude) · CA validé 232 000 € (2 mois encodés) · CA réel 1 535 053 € (11 mois). « Redevance assistance » et « Royalties franchise » du cockpit sont réunies sur la ligne du CRM (clé royalties_pct) : validé 2 + 1,5 = 3,5 %.</div>
</div></div></body></html>"""

def analyse():
    rows = []
    for it in CRM:
        if it[0] != 'rub': continue
        for p in it[3]:
            k, l, pct = p[0], p[1], p[4]
            c = COCK.get(k, [])
            th = sum(x[1] for x in c); va = sum(x[2] for x in c)
            libc = ' + '.join(x[0] for x in c)
            def cel(v):
                cls = 'g' if abs(v - pct) < 1e-9 else 'r'
                return f"<td class='ec {cls}'>{f'{v:g}'.replace('.', ',')} %</td>"
            rows.append(f"<tr><td class='l'>{html.escape(l)}<div class=d>{k}</div></td><td>{f'{pct:g}'.replace('.', ',')} %</td><td class='l m'>{html.escape(libc)}</td>{cel(th)}{cel(va)}</tr>")
    rows.append("<tr><td class='l'>—<div class=d>catégorie vide du cockpit</div></td><td>—</td><td class='l m'>Rémunération (0 poste)</td><td class='ec r'>0 %</td><td class='ec r'>0 %</td></tr>")
    rows.append("<tr class='tot'><td class='l'>Total charges</td><td>67,5 %</td><td></td><td class='ec r'>64,5 %</td><td class='ec r'>25,5 %</td></tr>")
    return f"""<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Analyse — CRM ↔ cockpit</title>{CSS}</head><body><div class="w">
<div class="k">Arbitrage · 07/11/2026</div><h1>Deux comptes de résultat pour un même réseau</h1>
<div class="card"><div class="t">Poste par poste</div><div class="s">Vert = identique au CRM · rouge = différent. Le CRM est aligné sur le contrat (DIP v29 : marque 2 % · assistance 2 % · marketing 2,5 %).</div>
<table style="margin-top:12px"><thead><tr><th class="l">Poste du CRM</th><th>CRM · % réseau</th><th class="l">Poste(s) du cockpit</th><th>Cockpit · % théo.</th><th>Cockpit · % validé</th></tr></thead><tbody>{''.join(rows)}</tbody></table>
<div class="d" style="margin-top:10px">Aussi différents : les sous-totaux (CRM : Marge brute, Disponible pour rémunération, Total charges, Résultat avant impôt ; cockpit : « Marge Brutte » seule) ; les libellés (« Marketing national » / « national / local ») ; le CRM n’a qu’un % réseau, le cockpit en a deux (théorique, validé) plus le réel. Le seuil coût matière du dashboard (Budget › Paramètres, 40 %) est le même des deux côtés.</div></div>
<div class="grid">
<div class="opt reco"><span class="tag">Recommandée</span><h3>A · Le CRM fait foi, le cockpit lit</h3>
<ul><li>Structure, libellés, clés et % réseau = <code>impl_pnl</code>, lus par une route d’intégration du CRM (jeton de service, comme le Scouting) — pas de lecture de table.</li>
<li>Le cockpit garde ce qui est à lui : % validé par poste, € validé, € réel, l’écart.</li>
<li>« Modifier dans le CRM ↗ » ; le cockpit ne crée plus de poste.</li></ul>
<ul><li class="pl">+ une seule vérité, celle du DIP et du contrat</li><li class="pl">+ seuil matière et marge brute cohérents partout</li><li class="mo">− « + Catégorie / + poste » disparaissent du cockpit</li><li class="mo">− une route à ajouter au CRM, une reprise des % validés sur les clés</li></ul></div>
<div class="opt"><span class="tag gris">Alternative</span><h3>B · Le cockpit fait foi, le CRM lit</h3>
<ul><li>Le CRM lirait <code>budgetCharges</code> pour ses dossiers et le DIP.</li></ul>
<ul><li class="mo">− le DIP, le contrat et les dossiers d’implantation (réels rattachés aux clés) dépendent du CRM</li><li class="mo">− le cockpit n’a pas de clés stables ni de poste « loyer lu du bail »</li><li class="mo">− ses redevances ne sont pas celles du contrat</li></ul></div>
<div class="opt"><span class="tag gris">Minimum</span><h3>C · Deux saisies, écart signalé</h3>
<ul><li>Chacun garde la sienne ; le cockpit affiche, ligne par ligne, l’écart au CRM et « ↙ Reprendre du CRM ».</li></ul>
<ul><li class="pl">+ rien ne change de propriétaire</li><li class="mo">− deux vérités durables, à refaire à chaque changement</li></ul></div>
</div></div></body></html>"""

open('a-alignee-sur-crm.html', 'w').write(maquette_alignee())
open('analyse-ecarts.html', 'w').write(analyse())
print('ok')
