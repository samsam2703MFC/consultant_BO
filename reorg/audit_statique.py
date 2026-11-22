#!/usr/bin/env python3
"""
Audit statique de la réorganisation en quatre domaines (lecture seule).

Lit le dépôt, ne touche à rien : aucune base, aucun réseau, aucune écriture hors de reorg/ et REORG_AUDIT.md.
  python3 -I reorg/audit_statique.py            → régénère REORG_AUDIT.md et reorg/*.tsv
  python3 -I reorg/audit_statique.py --ecart     → compare au dernier inventaire commis (reorg/tables.tsv) et dit ce qui a bougé

Les valeurs des clés ne sont jamais lues ni écrites : seuls leurs noms et leur emplacement.
"""
import collections, json, os, re, sys

RACINE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PR = 'pilotage-reseau/'
SORTIE = os.path.join(RACINE, 'reorg')
EXCLUS = {'.git', 'vendor', 'node_modules'}
TEXTE = {'.php', '.sql', '.js', '.mjs', '.ts', '.tsx', '.jsx', '.html', '.htm', '.md', '.json', '.yml', '.yaml', '.sh',
         '.py', '.css', '.txt', '.twig', '.conf', '.ini', '.example', '.xml', '.csv', '.trigger', '.webmanifest', ''}

DOM = {'MAR': 'Marketing (mar_)', 'FOR': 'Formation (for_)', 'CON': 'Pilotage consultant (con_)',
       'PANEL': 'Dashboard franchisé (panel_)', 'SHARED': 'Partagé (shared/)', 'EXT': 'Autre application (lue)',
       'HORS': 'Hors périmètre'}
PREFIXE = {'MAR': 'mar_', 'FOR': 'for_', 'CON': 'con_', 'PANEL': 'panel_'}
DOSSIER = {'MAR': 'marketing', 'FOR': 'formation', 'CON': 'consultant', 'PANEL': 'panel', 'SHARED': 'shared',
           'HORS': 'pilotage-reseau', 'EXT': 'pilotage-reseau'}

# ---------------------------------------------------------------------------------------------------------------
# 1. Les tables : domaine proposé et nouveau nom. « ? » dans la note = cas ambigu repris dans REORG_PLAN.md.
# ---------------------------------------------------------------------------------------------------------------
def regle_table(t):
    """(domaine, nouveau nom, note) pour une table créée par le code du dépôt."""
    fixe = {
        # Partagé : garde son nom
        'ceo_shop': ('SHARED', None, 'entité magasin, lue par les quatre domaines (FK entrantes)'),
        'ceo_app_setting': ('SHARED', None, 'réglages de tous les domaines ; porte des secrets à migrer'),
        'ceo_journal_entry': ('SHARED', None, 'journal commun ; lu aussi par Social (dépôt marketing)'),
        'ceo_push_abonnement': ('SHARED', None, 'web push, transport commun'),
        'ceo_ecran_vue': ('SHARED', None, 'compteur d’ouverture des écrans, technique'),
        'ceo_action_usage': ('SHARED', None, 'compteur d’usage des boutons, technique'),
        'ceo_connecteur': ('SHARED', None, 'état des connecteurs (aucun secret)'),
        'erp_cles': ('SHARED', None, '= « erp_keys » de la mission : la table des clés commune'),
        # Hors périmètre
        'ceo_x': ('HORS', None, 'table de test (bin/tests.php), jamais en base normalement'),
        # Formation
        'ceo_sop_tache': ('FOR', 'for_sop_tache', '? écrite par le cadre des tâches (consultant)'),
        'ceo_tablette_remarque': ('PANEL', 'panel_tablette_remarque', 'remarques clients saisies sur la tablette des vendeuses'),
        # Dashboard franchisé
        'ceo_revue_soir': ('PANEL', 'panel_revue_soir', ''),
        'ceo_shop_day_note': ('PANEL', 'panel_shop_day_note', ''),
        'ceo_matiere_code': ('PANEL', 'panel_matiere_code', '? scan des cartons en magasin (créée par mesure.php)'),
        'ceo_reclamation_photo': ('PANEL', 'panel_reclamation_photo', 'photos de réclamation prises depuis le dashboard'),
        # Marketing
        'ceo_prospect': ('MAR', 'mar_prospect', '? prospection B2B locale (ou Recrutement)'),
        'ceo_promo': ('MAR', 'mar_promo', 'registre : à fondre plus tard dans mar_promotion'),
        'ceo_promo_mecanique': ('MAR', 'mar_promo_mecanique', ''),
        # Consultant
        'ceo_benchmark_snapshot': ('CON', 'con_benchmark_snapshot', '? benchmark concurrence (ou Marketing)'),
        'ceo_kpi_def': ('CON', 'con_kpi_def', '? référentiel KPI lu aussi par le dashboard'),
        'ceo_kpi_valeur': ('CON', 'con_kpi_valeur', '? lu aussi par le dashboard'),
        'ceo_magasin_fiche': ('CON', 'con_magasin_fiche', '? lu aussi par le dashboard'),
        'hdk_demande': ('CON', 'con_hdk_demande', '? helpdesk : cockpit ET dashboard'),
        'hdk_mission': ('CON', 'con_hdk_mission', '? helpdesk : cockpit ET dashboard'),
    }
    if t in fixe:
        return fixe[t]
    if t.startswith('hr_dimona_'):
        return ('HORS', None, 'module RH / Dimona')
    if t.startswith('ceo_scouting_'):
        return ('HORS', None, 'scouting = Recrutement (absent des 4 domaines)')
    if t.startswith('mar_'):
        return ('MAR', None, 'déjà au bon préfixe')
    if t.startswith('con_'):
        return ('CON', None, 'déjà au bon préfixe')
    if t.startswith('aia_'):
        return ('PANEL', 'panel_' + t, 'assistant IA du dashboard')
    if t.startswith('mot_'):
        return ('PANEL', 'panel_' + t, '? app employés, primes, pointage')
    if t.startswith('pla_'):
        return ('CON', 'con_' + t, 'planogramme')
    if t in ('pil_auto_reglage', 'pil_equipe_mail', 'pil_tresorerie_plan', 'pil_frais_tva') or t.startswith('pil_boite_'):
        return ('PANEL', 'panel_' + t[4:], 'dashboard franchisé (déjà « pil_ », nom cible de l’ancien registre)')
    if t.startswith('pil_cq_') or t.startswith('pil_fil'):
        return ('CON', 'con_' + t[4:], 'cockpit seulement (déjà « pil_ »)')
    for p in ('ceo_md_', 'ceo_fid_'):
        if t.startswith(p):
            return ('MAR', 'mar_' + t[4:], 'MétaDonnée' if p == 'ceo_md_' else 'fidélité')
    for p in ('ceo_sop', 'ceo_ref_', 'ceo_manuel_', 'ceo_tablette_'):
        if t.startswith(p):
            return ('FOR', 'for_' + t[4:], 'Manuel du réseau / tablettes des magasins' if p == 'ceo_tablette_' else 'Formation')
    if t.startswith('ceo_hist_'):
        return ('PANEL', 'panel_' + t[4:], 'historique des ventes de l’assistant')
    if t.startswith('ceo_pwa_'):
        return ('CON', 'con_' + t[4:], '? récurrence des clients PWA (levier) — ou Marketing')
    if t.startswith('ceo_'):
        return ('CON', 'con_' + t[4:], '')
    return ('HORS', None, 'préfixe inconnu')

# Tables d'autres applications que le code lit dans la même base (aucune n'est créée en production par ce dépôt).
EXTERNES = {
    'ERP / panel TF Buddy': '''b2b_client client client_order client_order_product company flattened_recipe_ingredient
        franchisee_employee franchisee_employee_schedule kpi material material_category material_inventory
        material_inventory_log material_movement_reason material_order material_order_item
        material_order_item_delivery_log of_tag position product product_availability_period
        product_availability_period_connection product_category product_category_group
        product_category_group_connection product_movement product_portion product_recipe
        product_recipe_material_connection recipe_cost revenue_budget shop_material_price_list shop_product shops
        stocktaking stocktaking_item stocktaking_summary todo_task transaction transaction_product user_membership
        user_profile voucher_campaign voucher_campaign_channel voucher_code'''.split(),
    'PWA consultant (dépôt pwa_consultant)': '''mac_task_review mac_shop_monthly_pnl mac_google_rating_snapshot
        mac_consultant_visit mac_report_share mac_consultant_param mac_kpi_threshold'''.split(),
    'CRM Recrutement (dépôt atelier-espace-candidat)': 'crm_mo_magasins crm_mo_acces crm_mo_chapitres crm_mo_procedures'.split(),
    'Signage (dépôt signage)': 'sig_products sig_product_categories'.split(),
    'Module Marketing (dépôt marketing, migrations 0xx)': '''mar_brand mar_bundle mar_bundle_item mar_campaign
        mar_campaign_agency_ask mar_campaign_asset mar_campaign_b2b_option mar_campaign_b2b_sector
        mar_campaign_challenge_prize mar_campaign_channel mar_campaign_kpi_snapshot mar_campaign_lever_target
        mar_campaign_offer mar_campaign_offer_item mar_campaign_pos_question mar_campaign_shop
        mar_campaign_shop_item_target mar_campaign_status mar_campaign_type mar_campaign_uniform mar_crm_customer
        mar_crm_customer_segment mar_fund_movement mar_fund_recurrence mar_fund_source mar_kit mar_kit_activation
        mar_kit_asset mar_lever mar_promotion mar_review mar_roi_cost mar_shop mar_shop_presence
        mar_voucher_channel mar_voucher_redemption'''.split(),
}
# Anciens noms gardés dans les registres de migration (src/marketing_tables.php, src/con_tables.php, installer.php).
ANCIENS = re.compile(r'^ceo_(fb_post|fb_finding|nl_\w+|campagne_\w+|mkt_annexe\w*|visite\w*|equipe_releve|consultant\w*|'
                     r'franchise_meteo|msp|scoring_\w+|tache_jour\w*|rayon_photo|shop_review|shop_reputation|plano_(meuble|niveau|note|slot|zone)|'
                     r'prod_planogram|assistant_question|parcours\w*)$')
MORTES = {
    'ceo_parcours': 'effacée par src/formation_postes.php au déploiement (lignes gardées dans ceo_app_setting)',
    'ceo_parcours_etape': 'idem',
    'ceo_assistant_question': 'reprise une fois dans aia_question (src/franchise/assistant_ia.php) ; plus écrite',
    'ceo_prod_planogram': 'reprise dans pla_placement par planoMigrer() (src/installer.php)',
}

# ---------------------------------------------------------------------------------------------------------------
# 2. Les fichiers : domaine proposé (règles du plus précis au plus large).
# ---------------------------------------------------------------------------------------------------------------
SRC = {
    'SHARED': '''Db acces auth anthropic checklists_codes cibles_reseau connecteurs doc endpoints erp_api erp_cles installer
        panel_api panel_ventes ponderation push resultat resultat_pdf sms smtp ventes_semaines writes pnl_ht''',
    'MAR': '''brand_guard canaux_offres fbcontrole fidelite fidelite_app fidelite_bingo fidelite_cartes fidelite_profils
        google_api jours_creux linkedin_api marketing_hub marketing_sources marketing_tables mesure meta_graph metadonnee
        mkt_agences mkt_annexes mkt_brief mkt_types newsletter newsletter_envoi objectifs_produits promos_bons promos_doc
        promos_magasin promos_reseau promos_ventes prospection scouting_osm social_publication visibilite_google''',
    'FOR': '''formation formation_postes manuel_chiffres_cles referentiel referentiel_guides sop tablettes''',
    'PANEL': '''api_module assistant_magasin coach_objectifs grands_prix historique_ventes matiere_fiche motivation notes_jour
        planning_moi planning_sync pointage pointages_mail primes primes_app profil_employe primes_b2b primes_fiches production_flux
        production_plan produits_coef recette_produit revue_soir stats_ventes tablette tablette_objectifs
        tablette_remarques''',
    'CON': '''analyse_produits assortiment audits benchmark benchmark_import budget_crm ca_mail ca_net_mois cadence caisse
        commandes con_tables controles_qualite cq_mail croisements documents dossier dossiers_fichiers fil_franchise
        franchises helpdesk ia_assistant invendus inventaires kpi_table kpis leviers plan plan_pdf plano_proposition
        plano_std pnl_mois prod_manque prod_utilisation produit_parametrage rapports rayons_photos recurrence_pwa scoring
        structure_gamme suivi suivi_sources taches_suivi task_cadre ventes ventes_pro verification verification_base
        verification_ca verification_correction verification_flux verification_matiere_edition verification_matieres
        verification_matieres_prix verification_mouvements visites visites_cadre visites_conformite visites_equipe
        visites_evaluation visites_kpi visites_outils visites_questions''',
}
SRC_DOM = {n: d for d, s in SRC.items() for n in s.split()}
AMBIGUS_SRC = {'profil_employe', 'benchmark', 'benchmark_import', 'helpdesk', 'prospection', 'scouting_osm', 'kpi_table', 'kpis',
               'recurrence_pwa', 'api_module', 'grands_prix', 'motivation', 'planning_moi', 'planning_sync', 'pointage',
               'pointages_mail', 'primes', 'primes_app', 'primes_b2b', 'primes_fiches', 'tablette', 'tablette_objectifs',
               'tablette_remarques', 'promos_magasin', 'stats_ventes', 'checklists_codes'}

PUBLIC = {
    'api/index.php': 'SHARED', 'api/hr': 'HORS', 'api/marketing': 'MAR', 'assets/css': 'SHARED', 'assets/ds': 'SHARED',
    'assets/img': 'SHARED', 'assets/vendor': 'SHARED', 'assets/primes': 'PANEL', 'assistant': 'MAR', 'audit': 'CON',
    'audits': 'CON', 'borne': 'PANEL', 'caisse': 'CON', 'connexion': 'SHARED', 'controle': 'CON', 'dashboard': 'PANEL',
    'employee': 'PANEL', 'fidelite-app': 'MAR', 'fidelite-test': 'MAR', 'formation': 'FOR', 'hr': 'HORS',
    'marketing': 'MAR', 'newsletter': 'MAR', 'planogramme': 'CON', 'production': 'PANEL', 'prospection': 'MAR',
    'resultat-mobile': 'PANEL', 'suivi': 'CON', 'tablette': 'PANEL', 'task': 'CON', 'visites': 'CON',
    'index.html': 'SHARED', 'router.php': 'SHARED', 'consultant.webmanifest': 'CON',
}
JS = [(r'^(metadonnee|newsletter|prospection|fidelite-|promotions-|clients-annee-|visibilite-)', 'MAR'),
      (r'^(visites|suivi-|franchises-|fil-|helpdesk-|verification|benchmark-|produit-parametrage-|structure-|cartes-magasin)', 'CON'),
      (r'^assistant-coachs-', 'PANEL')]
BIN = [(r'^hr_dimona', 'HORS'), (r'^(metadonnee_cron|newsletter_cron|social_cron)', 'MAR'),
       (r'^(rapports_cron|visites_cron|stock_cron|push_stock|benchmark|mix1000)', 'CON'),
       (r'^(boite_cron|historique_moisson)', 'PANEL'),
       (r'^tests_(metadonnee|newsletter|promos|promos_reseau|bons|canaux|catalogue)\b', 'MAR'),
       (r'^tests_(formation|manuel_chiffres)\b', 'FOR'),
       (r'^tests_(assistant_ia|ia_note|coach_objectifs|bundles|boite_mail|automatisations|tresorerie|temps_morts|revue_soir|'
        r'analyse|primes|grands_prix|pointage_app|pointages|planning_moi|profil|employes_relus|pnl_ht|jauges)\b', 'PANEL'),
       (r'^tests_', 'CON'),
       (r'.*', 'SHARED')]
MAQ = [(r'^(fidelite|cartes-fidelite|newsletter|plan-campagne|promotions-|canaux-offres|b2b-b2c|metadonnee|'
        r'visibilite-google|site-gamme|clients-|evolution-canaux|objectifs-produits|prix-volume)', 'MAR'),
       (r'^(formation|manuel-formation|fusion-manuel|portail-formation|tablette-sop|sop-dashboard)', 'FOR'),
       (r'^(dashboard-|assistant-|budget-tablette|tresorerie|frais-tva|temps-morts|vue-jour-a4|semaine-mois|annee-trimestres|'
        r'objectif-jauge|resultat-vers-dashboard|onglet-produits|modale-|panier-heures|primes|pointage-app|profil-employe|'
        r'equipe-en-poste|parametres-mails|comptoir-webshop|b2b-commandes-mobile|caisse-reports|promo-coach|reclamations|'
        r'jours-creux|gestion-production)', 'PANEL'),
       (r'^scouting-', 'HORS'),
       (r'.*', 'CON')]
DOCS = {'DEPLOIEMENT.md': 'SHARED', 'api-promotions.md': 'MAR', 'audit-api-tfbuddy.md': 'SHARED', 'audit-api.md': 'SHARED',
        'audit-rail.md': 'SHARED', 'audit-taches-consultant.md': 'CON', 'audits-fournisseurs.md': 'CON',
        'brand-guard.md': 'MAR', 'contrat-api.md': 'SHARED', 'contrat-panel-produits.md': 'CON', 'dimona.md': 'HORS',
        'formation-routes.md': 'FOR', 'formation.md': 'FOR', 'handoff-centrale-achat.md': 'CON',
        'module-motivation.md': 'PANEL', 'plan-verification.md': 'CON', 'publication-reseaux.md': 'MAR',
        'pwa-suivi-visites-analyse.md': 'CON', 'spec-api-panel.md': 'SHARED', 'suivi-franchises.md': 'CON',
        'benchmarks': 'CON', 'consignes': 'PANEL', 'design-system': 'SHARED', 'dimona': 'HORS', 'dossiers': 'CON',
        'primes': 'PANEL'}
TOOLS = {'assistant-src': 'MAR', 'dashboard-api-index.py': 'PANEL', 'design-system': 'SHARED', 'essai_voucher.php': 'MAR',
         'formation': 'FOR', 'hr-dimona': 'HORS', 'metadonnee-essai': 'MAR', 'sonde_antigaspi_halle.php': 'PANEL',
         'sonde_assistant_halle.php': 'PANEL', 'sonde_cartes_fidelite.php': 'MAR', 'sonde_clients_bon.php': 'MAR',
         'sonde_dashboard_halle.php': 'PANEL', 'sonde_fidelite_halle.php': 'MAR', 'sonde_profils_halle.php': 'MAR',
         'sonde_vouchers.php': 'MAR', 'tablette-LISEZMOI.md': 'PANEL'}
TESTS = {'acces': 'SHARED', 'developpement': 'SHARED', 'fidelite': 'MAR', 'formation': 'FOR', 'hr': 'HORS',
         'visibilite': 'MAR'}
SQL = {'grants.sql': 'SHARED', 'hr_dimona.sql': 'HORS', 'mar-referentiels.sql': 'MAR', 'schema.sql': 'SHARED',
       'seed.sql': 'SHARED'}


def domaine_fichier(rel):
    """(domaine, nouveau chemin, raison) d'un fichier du dépôt."""
    if not rel.startswith(PR):
        return ('RACINE', rel, 'commun au dépôt (reste en place)')
    r = rel[len(PR):]
    parts = r.split('/')
    top = parts[0]
    def vers(d, sous=None):
        return (d, DOSSIER[d] + '/' + (sous if sous is not None else r))
    if top == 'apps':
        if parts[1] == 'employee':
            return vers('PANEL') + ('app employés (primes, pointage)',)
        return ('HORS', rel, 'copie de l’ERP (base « erp » séparée)')
    if top == 'src':
        if parts[1] == 'hr':
            return ('HORS', rel, 'module RH / Dimona')
        if parts[1] == 'franchise':
            return vers('PANEL', 'src/' + '/'.join(parts[2:])) + ('module du dashboard franchisé',)
        if parts[1] == 'crm':
            return vers('MAR') + ('client Meta de MétaDonnée',)
        if parts[1] == 'data':
            return vers('SHARED') + ('données de référence',)
        nom = os.path.splitext(parts[1])[0]
        d = SRC_DOM.get(nom)
        if d is None:
            return ('SHARED', 'shared/' + r, 'non classé : à vérifier')
        return vers(d) + ('? ambigu' if nom in AMBIGUS_SRC else '',)
    if top == 'public':
        if len(parts) >= 3 and parts[1] == 'assets' and parts[2] == 'js':
            for rx, d in JS:
                if re.match(rx, parts[3]):
                    return vers(d) + ('écran du domaine',)
            return vers('SHARED') + ('cœur de la SPA du cockpit',)
        for k in (('/'.join(parts[1:3])), parts[1]):
            if k in PUBLIC:
                d = PUBLIC[k]
                return (vers(d) if d != 'HORS' else ('HORS', rel)) + ('page servie (URL inchangée)',)
        return vers('SHARED') + ('page non classée',)
    if top == 'bin':
        n = os.path.splitext(parts[1])[0]
        for rx, d in BIN:
            if re.match(rx, n):
                return (vers(d) if d != 'HORS' else ('HORS', rel)) + ('',)
    if top == 'docs':
        if parts[1] == 'maquettes' and len(parts) > 2:
            for rx, d in MAQ:
                if re.match(rx, parts[2]):
                    return (vers(d) if d != 'HORS' else ('HORS', rel)) + ('maquette',)
        d = DOCS.get(parts[1], 'SHARED')
        return (vers(d) if d != 'HORS' else ('HORS', rel)) + ('documentation',)
    if top == 'tools':
        d = TOOLS.get(parts[1], 'SHARED')
        return (vers(d) if d != 'HORS' else ('HORS', rel)) + ('outil',)
    if top == 'tests':
        d = TESTS.get(parts[1], 'SHARED')
        return (vers(d) if d != 'HORS' else ('HORS', rel)) + ('test',)
    if top == 'sql':
        d = SQL.get(parts[1], 'SHARED')
        return (vers(d) if d != 'HORS' else ('HORS', rel)) + ('schéma',)
    if top == 'data':
        return vers('FOR') + ('contenus de la Formation',)
    if top == 'scripts':
        return ('HORS', rel, 'RH / Dimona')
    if top == 'maquette-screens':
        return vers('CON') + ('maquettes des contrôles qualité',)
    if top in ('config', 'CLAUDE.md', 'README.md', '.gitignore', 'deploy.trigger'):
        return vers('SHARED') + ('configuration et déploiement communs',)
    if top in ('essai-vouchers.trigger', 'sonde-vouchers.trigger'):
        return vers('MAR') + ('déclencheur de sonde',)
    return vers('SHARED') + ('non classé : à vérifier',)

# ---------------------------------------------------------------------------------------------------------------
# 3. Lecture du dépôt
# ---------------------------------------------------------------------------------------------------------------
ID = r'[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?'
RX_CREATE = re.compile(r'CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' + ID, re.I)
RX_VIEW = re.compile(r'CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?(?:DEFINER\s*=\s*\S+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?VIEW\s+' + ID, re.I)
RX_TRIG = re.compile(r'CREATE\s+(?:DEFINER\s*=\s*\S+\s+)?(?:TRIGGER|FUNCTION|PROCEDURE|EVENT)\s+' + ID, re.I)
RX_FK = re.compile(r'REFERENCES\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?\s*\(', re.I)
RX_ALTER = re.compile(r'ALTER\s+TABLE\s+' + ID, re.I)
RX_SQL = re.compile(r'\b(?:FROM|JOIN|INTO|UPDATE|TABLE|EXISTS|TRUNCATE)\s+[`"]?([a-z][a-z0-9_]*)[`"]?', re.I)
RX_TOK = re.compile(r'[A-Za-z_][A-Za-z0-9_]*')


def corps(t, pos):
    i = t.find('(', pos)
    if i < 0 or i - pos > 400:
        return ''
    n = 0
    for j in range(i, min(len(t), i + 60000)):
        if t[j] == '(':
            n += 1
        elif t[j] == ')':
            n -= 1
            if n == 0:
                return t[i:j + 1]
    return ''


def lire():
    fichiers, creees, vues, objets, fks, tokens, sqlctx = [], collections.defaultdict(set), [], [], set(), {}, {}
    textes = {}
    for dp, dns, fns in os.walk(RACINE):
        dns[:] = sorted(d for d in dns if d not in EXCLUS)
        for f in sorted(fns):
            p = os.path.join(dp, f)
            rel = os.path.relpath(p, RACINE)
            if rel.startswith('reorg/') or rel in ('REORG_AUDIT.md', 'REORG_PLAN.md', 'REORG_REPORT.md'):
                continue
            fichiers.append(rel)
            ext = os.path.splitext(f)[1].lower()
            if ext not in TEXTE and f not in ('.htaccess', '.gitignore'):
                continue
            try:
                t = open(p, encoding='utf-8', errors='replace').read()
            except OSError:
                continue
            erp = rel.startswith(PR + 'apps/production')
            if not erp and genre(rel) != 'doc':
                textes[rel] = t
            for m in RX_CREATE.finditer(t):
                if not erp:
                    creees[m.group(1)].add(rel)
                    for k in RX_FK.finditer(corps(t, m.end())):
                        fks.add((m.group(1), k.group(1), rel))
            if not erp:
                for m in RX_ALTER.finditer(t):
                    seg = t[m.end():m.end() + 1500].split(';')[0]
                    for k in RX_FK.finditer(seg):
                        fks.add((m.group(1), k.group(1), rel))
                vues += [(m.group(1), rel) for m in RX_VIEW.finditer(t)]
                objets += [(m.group(1), rel) for m in RX_TRIG.finditer(t)]
            tokens[rel] = collections.Counter(RX_TOK.findall(t))
            sqlctx[rel] = collections.Counter(x.lower() for x in RX_SQL.findall(t))
    return fichiers, creees, vues, objets, fks, tokens, sqlctx, textes


def genre(rel):
    """Catégorie de référence : où le nom apparaît."""
    r = rel[len(PR):] if rel.startswith(PR) else rel
    if r.startswith(('docs/', 'maquette-screens/')) or r.endswith('.md') or rel.startswith('docs/'):
        return 'doc'
    if r.startswith(('tests/',)) or re.match(r'bin/tests?_', r):
        return 'test'
    if r.startswith('sql/'):
        return 'sql'
    if r.startswith(('bin/', 'tools/', 'scripts/')) or rel.startswith('.github/'):
        return 'script'
    if r.startswith('public/') and not r.endswith('.php'):
        return 'front'
    return 'code'


def main():
    ecart = '--ecart' in sys.argv
    fichiers, creees, vues, objets, fks, tokens, sqlctx, textes = lire()
    bruit = {'IF', 'de', 'et', 'ci', 'mot_', 'crm_settings', 'bo_audit', 'bo_user_shops', 'bo_users', 'shops', 'kpi',
             'of_tag', 'position', 'mac_report_share', 'franchisee_employee'}
    propres = sorted(t for t in creees if t not in bruit and t not in (n for v in EXTERNES.values() for n in v))
    propres += [t for t in ('mot_cache', 'mot_reglage') if t not in propres]   # créées par une boucle (src/motivation.php)
    propres = sorted(set(propres))
    externes = {t: g for g, l in EXTERNES.items() for t in l}
    toutes = propres + sorted(externes) + sorted(MORTES)

    # Références : nom exact comme identifiant (tables préfixées) ; en contexte SQL pour les noms communs.
    refs = collections.defaultdict(lambda: collections.defaultdict(set))
    for rel, c in tokens.items():
        if rel.startswith(PR + 'apps/production'):
            continue
        for t in toutes:
            prefixe = '_' in t and re.match(r'^(ceo|mar|con|pil|aia|hr|pla|mot|hdk|erp|mac|crm|sig)_', t)
            n = c.get(t, 0) if prefixe else sqlctx[rel].get(t, 0)
            if n:
                refs[t][genre(rel)].add(rel)

    domf = {rel: domaine_fichier(rel) for rel in fichiers}
    lignes_t, lignes_f = [], []
    nouveaux = {}
    for t in propres:
        d, nv, note = regle_table(t)
        if t in MORTES:
            d, nv, note = 'HORS', None, 'obsolète : ' + MORTES[t]
        elif all(genre(f) == 'doc' for f in creees.get(t, ())):
            d, nv, note = d, None, 'définie seulement dans la documentation : jamais créée par le code (à vérifier en base)'
        nouveaux[t] = nv
        sort_ = sorted({b for a, b, _ in fks if a == t})
        entr = sorted({a for a, b, _ in fks if b == t})
        r = refs[t]
        doms = sorted({domf[f][0] for g in ('code', 'front', 'script', 'sql') for f in r.get(g, ())
                       if f in domf} - {'RACINE'})
        lignes_t.append({'table': t, 'prefixe': (t.split('_')[0] + '_'), 'domaine': d, 'nouveau': nv or '=', 'note': note,
                         'lignes': 'à mesurer', 'fk_sortantes': ' '.join(sort_), 'fk_entrantes': ' '.join(entr),
                         'definie_dans': ' '.join(sorted(x.replace(PR, '') for x in creees.get(t, ())))[:200],
                         'n_code': len(r.get('code', ())), 'n_front': len(r.get('front', ())), 'n_script': len(r.get('script', ())),
                         'n_test': len(r.get('test', ())), 'n_sql': len(r.get('sql', ())), 'n_doc': len(r.get('doc', ())),
                         'domaines_lecteurs': ' '.join(doms),
                         'fichiers': ' '.join(sorted(x.replace(PR, '') for g in ('code', 'front', 'script', 'sql', 'test') for x in r.get(g, ())))})
    for t, why in sorted(MORTES.items()):
        r = refs[t]
        lignes_t.append({'table': t, 'prefixe': 'ceo_', 'domaine': 'HORS', 'nouveau': '=', 'note': 'obsolète : ' + why,
                         'lignes': 'à mesurer (absente ?)', 'fk_sortantes': '', 'fk_entrantes': '', 'definie_dans': '',
                         'n_code': len(r.get('code', ())), 'n_front': len(r.get('front', ())), 'n_script': len(r.get('script', ())),
                         'n_test': len(r.get('test', ())), 'n_sql': len(r.get('sql', ())), 'n_doc': len(r.get('doc', ())),
                         'domaines_lecteurs': '', 'fichiers': ' '.join(sorted(x.replace(PR, '') for g in ('code', 'front', 'script', 'sql', 'test') for x in r.get(g, ())))})
    for t, g in sorted(externes.items()):
        r = refs[t]
        doms = sorted({domf[f][0] for gg in ('code', 'front', 'script', 'sql') for f in r.get(gg, ()) if f in domf} - {'RACINE'})
        lignes_t.append({'table': t, 'prefixe': (t.split('_')[0] + '_') if '_' in t else '(aucun)', 'domaine': 'EXT',
                         'nouveau': '=', 'note': g, 'lignes': 'à mesurer', 'fk_sortantes': '', 'fk_entrantes': '',
                         'definie_dans': ' '.join(sorted(x.replace(PR, '') for x in creees.get(t, ()))),
                         'n_code': len(r.get('code', ())), 'n_front': len(r.get('front', ())), 'n_script': len(r.get('script', ())),
                         'n_test': len(r.get('test', ())), 'n_sql': len(r.get('sql', ())), 'n_doc': len(r.get('doc', ())),
                         'domaines_lecteurs': ' '.join(doms),
                         'fichiers': ' '.join(sorted(x.replace(PR, '') for gg in ('code', 'front', 'script', 'sql', 'test') for x in r.get(gg, ())))})
    for rel in fichiers:
        d, nv, why = domf[rel]
        lignes_f.append({'ancien': rel, 'nouveau': nv, 'domaine': d, 'raison': why})

    # Collisions : un nouveau nom déjà pris
    pris = set(propres) | set(externes)
    collisions = [(t, nv) for t, nv in nouveaux.items() if nv and nv in pris]
    cible_fichiers = collections.Counter(l['nouveau'] for l in lignes_f)
    collisions_f = [k for k, n in cible_fichiers.items() if n > 1]

    cols_t = ['table', 'prefixe', 'domaine', 'nouveau', 'note', 'lignes', 'fk_sortantes', 'fk_entrantes', 'definie_dans',
              'n_code', 'n_front', 'n_script', 'n_test', 'n_sql', 'n_doc', 'domaines_lecteurs', 'fichiers']
    tsv_t = '\n'.join(['\t'.join(cols_t)] + ['\t'.join(str(l[c]) for c in cols_t) for l in lignes_t]) + '\n'
    tsv_f = '\n'.join(['ancien\tnouveau\tdomaine\traison'] + [f"{l['ancien']}\t{l['nouveau']}\t{l['domaine']}\t{l['raison']}" for l in lignes_f]) + '\n'

    if ecart:
        avant = open(os.path.join(SORTIE, 'tables.tsv'), encoding='utf-8').read().splitlines()[1:]
        av = {l.split('\t')[0]: l.split('\t') for l in avant}
        ap = {l['table']: l for l in lignes_t}
        nv_t = sorted(set(ap) - set(av)); pa_t = sorted(set(av) - set(ap))
        print('Tables apparues depuis l’inventaire commis :', ', '.join(nv_t) or 'aucune')
        print('Tables disparues :', ', '.join(pa_t) or 'aucune')
        chg = [t for t in set(ap) & set(av) if (ap[t]['n_code'], ap[t]['n_front']) != (int(av[t][9]), int(av[t][10]))]
        print('Tables dont le nombre de fichiers de code qui les citent a changé :', ', '.join(sorted(chg)) or 'aucune')
        avf = {l.split('\t')[0] for l in open(os.path.join(SORTIE, 'fichiers.tsv'), encoding='utf-8').read().splitlines()[1:]}
        apf = {l['ancien'] for l in lignes_f}
        print('Fichiers ajoutés :', len(apf - avf), ' retirés :', len(avf - apf))
        for f in sorted(apf - avf)[:40]:
            print('  +', f, '→', domf[f][0])
        print('Collisions de noms de tables :', collisions or 'aucune', ' de chemins :', collisions_f or 'aucune')
        avc = {tuple(l.split('\t')[:2]) for l in open(os.path.join(SORTIE, 'cles.tsv'), encoding='utf-8').read().splitlines()[1:]}
        apc = inventaire_cles(textes)
        nouv = sorted(set(apc) - avc)
        print('Noms de clés / URL d’intégration apparus (hors erp_cles = à examiner) :', len(nouv))
        for lieu, n in nouv:
            print(f'  + [{lieu}] {n} — {", ".join(sorted(apc[(lieu, n)])[:4])}')
        return

    os.makedirs(SORTIE, exist_ok=True)
    open(os.path.join(SORTIE, 'tables.tsv'), 'w', encoding='utf-8').write(tsv_t)
    open(os.path.join(SORTIE, 'fichiers.tsv'), 'w', encoding='utf-8').write(tsv_f)
    json.dump({'vues': vues, 'objets': objets, 'fks': sorted(fks), 'collisions': collisions, 'collisions_fichiers': collisions_f},
              open(os.path.join(SORTIE, 'objets.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    cles = inventaire_cles(textes)
    open(os.path.join(SORTIE, 'cles.tsv'), 'w', encoding='utf-8').write(
        'emplacement\tnom\tfichiers\n' + ''.join(f'{l}\t{n}\t{" ".join(sorted(fs))}\n' for (l, n), fs in sorted(cles.items())))
    morts = fichiers_morts(fichiers, textes)
    open(os.path.join(SORTIE, 'fichiers_morts.tsv'), 'w', encoding='utf-8').write(
        'fichier\traison\n' + ''.join(f'{f}\t{r}\n' for f, r in morts))
    rendre_audit(lignes_t, lignes_f, fks, vues, objets, morts, creees, tokens)
    print(f'{len(propres)} tables du dépôt, {len(externes)} tables d’autres applications, {len(fichiers)} fichiers.')
    print('Collisions de noms de tables :', collisions or 'aucune')
    print('Collisions de chemins :', collisions_f or 'aucune')


RX_CLES = [
    ('erp_cles (erpCle)', re.compile(r"erpCle(?:Ecrire)?\(\s*'([a-z0-9_]+)'")),
    ('ceo_app_setting (setting)', re.compile(r"\bsetting\(\s*'([A-Za-z0-9_]*(?:[Kk]ey|[Tt]oken|[Ss]ecret|[Pp]ass|[Jj]eton|Api|smtp|anthropic|google|Meta|Vapid|erpAdmin|brandGuard|socialPublication|Url|Base)[A-Za-z0-9_]*)'")),
    ('variable d’environnement (getenv)', re.compile(r"getenv\(\s*'([A-Z0-9_]+)'")),
    ('URL https en dur', re.compile(r"'(https?://[a-z0-9.-]+\.[a-z]{2,}(?::\d+)?)[/'?]")),
]


def inventaire_cles(textes):
    """Noms de clés et URL d’intégration par emplacement (jamais de valeur)."""
    out = collections.defaultdict(set)
    for rel, t in textes.items():
        if genre(rel) in ('doc', 'test') or rel.startswith(PR + 'apps/production'):
            continue
        for lieu, rx in RX_CLES:
            for m in rx.finditer(t):
                v = m.group(1)
                if lieu.startswith('URL') and re.search(r'example|exemple|w3\.org|schema\.org|localhost|x\.test|ogp\.me|npmjs', v):
                    continue
                out[(lieu, v)].add(rel.replace(PR, ''))
    return out


def fichiers_morts(fichiers, textes):
    """Modules PHP de src/ jamais chargés, scripts JS jamais cités, scripts bin/ jamais appelés (hors tests)."""
    tout = '\n'.join(t for r, t in textes.items())
    morts = []
    for rel in fichiers:
        r = rel[len(PR):] if rel.startswith(PR) else None
        if r is None:
            continue
        base = os.path.basename(r)
        cand = (r.startswith('src/') and r.endswith('.php') and not r.startswith('src/hr/')) or \
               (r.startswith('public/assets/js/') and r.endswith('.js')) or \
               (r.startswith('bin/') and not base.startswith('tests') and base not in ('deploy.sh',)) or \
               (r.startswith('tools/sonde_') and r.endswith('.php'))
        if not cand:
            continue
        autres = tout.replace(textes.get(rel, ''), '') if rel in textes else tout
        if base not in autres:
            morts.append((rel, 'nom de fichier cité nulle part ailleurs dans le code (ni require, ni script, ni workflow)'))
    return morts


def md_echap(x):
    return str(x).replace('|', '\\|')


def rendre_audit(lt, lf, fks, vues, objets, morts, creees, tokens):
    L = []
    w = L.append
    w('# REORG_AUDIT — Phase 1 : audit (lecture seule)')
    w('')
    w('Généré par `python3 -I reorg/audit_statique.py` (lecture seule : aucune base, aucun réseau). Les données brutes sont '
      'dans `reorg/tables.tsv` (une ligne par table, avec la liste de tous les fichiers qui la citent), `reorg/fichiers.tsv` '
      '(un fichier par ligne : domaine et nouveau chemin proposés), `reorg/objets.json` (clés étrangères, vues, objets SQL) '
      'et `reorg/fichiers_morts.tsv`. Le plan, les décisions et les questions sont dans `REORG_PLAN.md`.')
    w('')
    w('## 0. Limites de cet audit')
    w('')
    w('- **Aucun accès à la base depuis cet environnement** (pas de client MySQL, pas d’identifiants, et la base du bac à '
      'sable n’est joignable que depuis le serveur 185.180.206.46). Les **nombres de lignes**, les tables **vides**, les '
      'clés étrangères **réellement posées**, les vues, déclencheurs et fonctions **réellement présents** sont donc notés '
      '« à mesurer » : ils seront relevés par la ligne de base de la phase 3 (étape 0 du plan), avant toute modification.')
    w('- L’inventaire est tiré du code : chaque `CREATE TABLE` du dépôt (SQL et chaînes PHP), chaque nom de table cité '
      'dans une requête (`FROM`, `JOIN`, `INTO`, `UPDATE`…), et les registres de migration (`MAR_TABLES`, `CON_TABLES`). '
      'Une table présente en base mais jamais citée par ce dépôt n’y apparaît pas : la ligne de base la fera apparaître.')
    w('- La copie de l’ERP (`apps/production`, base `erp` séparée, tables `n_` / `tfb_`) est inventoriée comme un bloc '
      'hors périmètre ; ses 546 `CREATE TABLE` ne sont pas détaillés.')
    w('')
    w('## 1. Arborescence et domaine de chaque dossier')
    w('')
    agg = collections.defaultdict(lambda: collections.Counter())
    for l in lf:
        a = l['ancien']
        parts = a.split('/')
        if a.startswith(PR):
            cle = '/'.join(parts[:3]) + ('/' if len(parts) > 3 else '')
            if parts[1] in ('docs',) and len(parts) > 3 and parts[2] == 'maquettes':
                cle = '/'.join(parts[:4]) + '/'
            if parts[1] in ('src',) and len(parts) == 3:
                cle = a
            if parts[1] in ('apps',) and len(parts) > 3:
                cle = '/'.join(parts[:3]) + '/'
        else:
            cle = parts[0] + ('/' if len(parts) > 1 else '')
        agg[cle][l['domaine']] += 1
    w('| Chemin | Fichiers | Domaine proposé |')
    w('|---|---:|---|')
    for k in sorted(agg):
        c = agg[k]
        dom = ', '.join(f'{d} ({n})' if len(c) > 1 else d for d, n in c.most_common())
        w(f'| `{md_echap(k)}` | {sum(c.values())} | {dom} |')
    w('')
    w('Légende : MAR = Marketing, FOR = Formation, CON = Pilotage consultant, PANEL = Dashboard franchisé, '
      'SHARED = partagé, HORS = hors périmètre (reste en place jusqu’à la phase 4), RACINE = fichiers communs du dépôt.')
    w('')
    w('## 2. Inventaire des tables')
    w('')
    w('Colonnes « réf. » : nombre de fichiers qui citent la table, par nature — code serveur / écran / script (bin, tools, '
      'workflows) / test / SQL / documentation. « Lu par » : domaines des fichiers de code qui la citent.')
    for dom in ('SHARED', 'MAR', 'FOR', 'CON', 'PANEL', 'HORS', 'EXT'):
        rows = [l for l in lt if l['domaine'] == dom]
        if not rows:
            continue
        w('')
        w(f'### {DOM[dom]} — {len(rows)} table(s)')
        w('')
        w('| Table actuelle | Préfixe | Nouveau nom | Lignes | FK sortantes | FK entrantes | Réf. code/écran/script/test/sql/doc | Lu par | Note |')
        w('|---|---|---|---|---|---|---|---|---|')
        for l in rows:
            w(f"| `{l['table']}` | `{l['prefixe']}` | {('`' + l['nouveau'] + '`') if l['nouveau'] != '=' else '='} | {l['lignes']} | "
              f"{md_echap(l['fk_sortantes']) or '—'} | {md_echap(l['fk_entrantes']) or '—'} | "
              f"{l['n_code']}/{l['n_front']}/{l['n_script']}/{l['n_test']}/{l['n_sql']}/{l['n_doc']} | {l['domaines_lecteurs'] or '—'} | {md_echap(l['note'])} |")
    w('')
    w('## 3. Où chaque table est citée')
    w('')
    w('Fichiers de code, écrans, scripts, tests et SQL (la documentation est comptée au §2, pas listée). Liste brute et '
      'triable : `reorg/tables.tsv`, colonne `fichiers`.')
    w('')
    for l in lt:
        fs = l['fichiers'].split()
        if not fs:
            w(f"- `{l['table']}` : aucun fichier hors documentation")
            continue
        w(f"<details><summary><code>{l['table']}</code> — {len(fs)} fichier(s)</summary>")
        w('')
        w(', '.join(f'`{f}`' for f in fs))
        w('')
        w('</details>')
    w('')
    w('## 4. Clés étrangères, vues, déclencheurs, fonctions')
    w('')
    w('Déclarées dans le code (à confirmer en base à l’étape 0) :')
    w('')
    w('| Table | → Référence | Déclarée dans |')
    w('|---|---|---|')
    for a, b, f in sorted(fks):
        w(f'| `{a}` | `{b}` | `{f.replace(PR, "")}` |')
    w('')
    w('Vues : ' + (', '.join(f'`{n}` (`{f.replace(PR, "")}`)' for n, f in vues) or 'aucune') + '.')
    w('')
    w('Déclencheurs, fonctions, procédures, événements : ' + (', '.join(f'`{n}`' for n, _ in objets) or 'aucun dans le code du dépôt (hors copie de l’ERP)') + '.')
    w('')
    w('## 5. Fichiers morts (candidats)')
    w('')
    w('Nom de fichier cité nulle part ailleurs dans le code (ni `require`, ni script, ni workflow, ni page). À confirmer '
      'avant toute suppression : rien n’est supprimé avant la phase 4.')
    w('')
    for f, r in morts:
        w(f'- `{f}`')
    if not morts:
        w('- aucun')
    w('')
    open(os.path.join(RACINE, 'REORG_AUDIT.md'), 'w', encoding='utf-8').write('\n'.join(L) + '\n')


if __name__ == '__main__':
    main()
