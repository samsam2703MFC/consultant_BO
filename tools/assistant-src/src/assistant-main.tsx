import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import * as api from './lib/api/module'
import { useAsync } from './lib/useAsync'
import { ReferencesProvider } from './state/references'
import CampaignBuilder from './views/builder/CampaignBuilder'
import './styles/global.css'

/**
 * Entrée autonome : l'assistant de création de campagne, seul, hébergé dans le
 * cockpit CEO. Pas de barre latérale ni de routes — le cockpit assure déjà la
 * navigation ; cette page ne fait qu'une chose, créer ou reprendre une
 * campagne, puis rend la main.
 *
 * Adresse : /assistant/            → nouvelle campagne
 *           /assistant/?id=12      → reprise du brouillon 12
 *           /assistant/?brand=1    → périmètre d'enseigne (identifiant ERP)
 */

const COCKPIT = '../'

function fromUrl(key: string): number | null {
  const raw = new URLSearchParams(window.location.search).get(key)
  if (raw === null || raw.trim() === '') return null
  const id = Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
}

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <div className="workspace">
      <div className="workspace__main">
        <header className="topbar">
          <div>
            <p className="topbar__kicker">Cockpit CEO</p>
            <h1>Assistant de campagne</h1>
          </div>
          <a className="filter back" href={COCKPIT}>
            ← Retour au cockpit
          </a>
        </header>
        <main className="app__body app__body--wide">{children}</main>
      </div>
    </div>
  )
}

function Assistant() {
  const session = useAsync(() => api.getSession(), [])
  const references = useAsync(() => api.getReferences(), [])
  const brands = useAsync(() => api.listBrands(), [])

  if (session.loading || references.loading || brands.loading) {
    return (
      <Shell>
        <p className="muted">Ouverture de l’assistant…</p>
      </Shell>
    )
  }

  const failure = session.error ?? references.error ?? brands.error
  if (failure) {
    return (
      <Shell>
        <p className="error">Assistant injoignable : {String(failure)}</p>
        <p className="muted">
          L’API doit répondre à <code>api/v1/marketing/references</code>.
        </p>
      </Shell>
    )
  }

  if (!session.data?.authenticated || !references.data) {
    return (
      <Shell>
        <p className="error">Session non établie côté API de l’assistant.</p>
      </Shell>
    )
  }

  // Périmètre d'enseigne : ?brand= porte l'identifiant ERP, traduit via
  // erp_brand_id — même règle que le module d'origine. Sans paramètre, aucun
  // filtre (réseau mono-enseigne).
  const requested = fromUrl('brand')
  const scope =
    requested !== null && brands.data
      ? brands.data.find((b) => b.erp_brand_id === requested) ??
        (brands.data.some((b) => b.erp_brand_id !== null)
          ? undefined
          : brands.data.find((b) => b.id === requested))
      : undefined
  const brandId: number | 'all' = scope?.id ?? 'all'

  return (
    <ReferencesProvider value={references.data}>
      <Shell>
        <CampaignBuilder
          refs={references.data}
          role="BRAND_ADMIN"
          brandId={brandId}
          campaignId={fromUrl('id')}
          onCreated={() => window.location.assign(COCKPIT)}
          onCancel={() => window.location.assign(COCKPIT)}
        />
      </Shell>
    </ReferencesProvider>
  )
}

const container = document.getElementById('root')
if (!container) throw new Error('Élément #root introuvable dans assistant.html.')

createRoot(container).render(
  <StrictMode>
    <Assistant />
  </StrictMode>,
)
