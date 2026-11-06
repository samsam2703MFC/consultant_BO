<?php $this->view('page_components/header'); ?>
<div class="page-heading"><div class="page-title"><h3>Integracja KSeF</h3><p class="text-subtitle text-muted">Konfiguracja wysyłki WDT oraz przeglądarki faktur zakupowych.</p></div>
<section class="section"><div class="card"><div class="card-header"><h4 class="card-title mb-0">Dostęp do KSeF</h4></div><div class="card-body">
    <div class="alert alert-info">Token jest zapisywany w bazie w postaci zaszyfrowanej. Aby korzystać z przeglądarki faktur zakupowych, token musi mieć uprawnienie <code>InvoiceRead</code> (dla wysyłki: <code>InvoiceWrite</code>). Pozostaw pole tokenu puste, aby zachować aktualną wartość.</div>
    <form id="ksef-settings-form" class="row g-3">
        <div class="col-md-4"><label class="form-label" for="ksef-environment">Środowisko</label><select class="form-select" id="ksef-environment"><option value="test" <?= ($ksef['environment'] ?? 'test') === 'test' ? 'selected' : '' ?>>TEST</option><option value="production" <?= ($ksef['environment'] ?? '') === 'production' ? 'selected' : '' ?>>PRODUKCJA</option></select></div>
        <div class="col-md-8"><label class="form-label" for="ksef-token">Token KSeF</label><input class="form-control" id="ksef-token" type="password" autocomplete="new-password" placeholder="<?= !empty($ksef['tokenConfigured']) ? 'Token jest zapisany — wpisz nowy tylko aby podmienić' : 'Wklej token KSeF' ?>"></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">Zapisz</button><button class="btn btn-outline-secondary" id="ksef-check" type="button">Sprawdź dostępność API</button></div>
    </form>
    <hr><dl class="row mb-0"><dt class="col-sm-4">Token</dt><dd class="col-sm-8" id="ksef-token-status"><?= !empty($ksef['tokenConfigured']) ? 'skonfigurowany' : 'brak' ?></dd><dt class="col-sm-4">Ostatni test</dt><dd class="col-sm-8" id="ksef-last-check"><?= htmlspecialchars((string)($ksef['lastConnectionCheckAt'] ?? '—')) ?></dd></dl>
</div></div></section>
<section class="section"><div class="card"><div class="card-header"><h4 class="card-title mb-0">Log błędów KSeF</h4></div><div class="card-body table-responsive">
    <table class="table table-hover align-middle mb-0"><thead><tr><th>Data</th><th>Faktura</th><th>Etap</th><th>Odpowiedź KSeF</th><th>Komunikat</th></tr></thead><tbody>
    <?php foreach ($events ?? [] as $event): ?><tr><td><?= htmlspecialchars((string)$event['created_at']) ?></td><td><?= htmlspecialchars((string)($event['doc_num'] ?: '—')) ?></td><td><?= htmlspecialchars((string)$event['stage']) ?></td><td><?php if (!empty($event['ksef_code'])): ?><span class="badge bg-light text-dark border border-secondary">KSeF <?= htmlspecialchars((string)$event['ksef_code']) ?></span><?php elseif (!empty($event['http_status']) && (int)$event['http_status'] >= 300): ?><span class="badge bg-light text-dark border border-secondary">HTTP <?= (int)$event['http_status'] ?></span><?php else: ?>—<?php endif; ?></td><td><?= nl2br(htmlspecialchars((string)$event['message'])) ?></td></tr><?php endforeach; ?>
    <?php if (empty($events)): ?><tr><td colspan="5" class="text-center text-muted">Brak zarejestrowanych błędów KSeF.</td></tr><?php endif; ?>
    </tbody></table>
</div></div></section></div>
<?php $this->view('page_components/footer'); ?>
<script>
const ksefForm = document.getElementById('ksef-settings-form');
async function ksefRequest(url, payload = {}) { const response = await fetch('<?= ROOT ?>' + url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify(payload)}); const data = await response.json(); if (!response.ok || !data.ok) throw new Error(data.message || 'Operacja KSeF nie powiodła się.'); return data; }
ksefForm.addEventListener('submit', async event => { event.preventDefault(); try { const result = await ksefRequest('/ajax/settings/ksef', {environment:document.getElementById('ksef-environment').value, token:document.getElementById('ksef-token').value}); document.getElementById('ksef-token').value=''; document.getElementById('ksef-token-status').textContent=result.settings.tokenConfigured ? 'skonfigurowany' : 'brak'; showSnack(true, result.message); } catch (error) { showSnack(false, error.message); } });
document.getElementById('ksef-check').addEventListener('click', async () => { try { const result = await ksefRequest('/ajax/settings/ksef/check'); document.getElementById('ksef-last-check').textContent=result.settings.lastConnectionCheckAt || '—'; showSnack(true, result.message); } catch (error) { showSnack(false, error.message); } });
</script>
