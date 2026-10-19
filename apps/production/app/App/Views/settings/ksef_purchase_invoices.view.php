<?php
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Warsaw'));
$from = $today->modify('-90 days')->format('Y-m-d');
$to = $today->format('Y-m-d');
$root = rtrim(ROOT, '/');
?>
<?php $this->view('page_components/header'); ?>
<div class="page-heading">
    <div class="page-title">
        <h3>Faktury zakupowe KSeF</h3>
        <p class="text-subtitle text-muted">Dokumenty są pobierane na żywo z KSeF i nie są zapisywane w aplikacji.</p>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-body">
                <form id="ksef-purchase-filters" class="row g-3">
                    <div class="col-md-2"><label class="form-label" for="purchase-subject-type">Kategoria</label><select class="form-select" id="purchase-subject-type"><option value="Subject2">Zakupowe (Podmiot2)</option><option value="Subject3">Inny podmiot (Podmiot3)</option></select></div>
                    <div class="col-md-2"><label class="form-label" for="purchase-date-type">Data</label><select class="form-select" id="purchase-date-type"><option value="PermanentStorage">trwałego zapisu w KSeF</option><option value="Invoicing">przyjęcia w KSeF</option><option value="Issue">wystawienia</option></select></div>
                    <div class="col-md-2"><label class="form-label" for="purchase-from">Od</label><input class="form-control" id="purchase-from" type="date" value="<?= $from ?>" required></div>
                    <div class="col-md-2"><label class="form-label" for="purchase-to">Do</label><input class="form-control" id="purchase-to" type="date" value="<?= $to ?>" required></div>
                    <div class="col-md-2"><label class="form-label" for="purchase-seller-nip">NIP dostawcy</label><input class="form-control" id="purchase-seller-nip" inputmode="numeric"></div>
                    <div class="col-md-2"><label class="form-label" for="purchase-invoice-number">Numer faktury</label><input class="form-control" id="purchase-invoice-number"></div>
                    <div class="col-md-4"><label class="form-label" for="purchase-ksef-number">Numer KSeF</label><input class="form-control" id="purchase-ksef-number"></div>
                    <div class="col-md-2"><label class="form-label" for="purchase-currency">Waluta</label><input class="form-control text-uppercase" id="purchase-currency" maxlength="3" placeholder="PLN"></div>
                    <div class="col-md-6 d-flex align-items-end"><button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Wyszukaj w KSeF</button></div>
                </form>
                <p class="small text-muted mb-0 mt-3">Maksymalny zakres jednego zapytania: mniej niż 3 miesiące. Kolejne 25 dokumentów zostanie pobrane automatycznie podczas przewijania listy.</p>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center"><h4 class="card-title mb-0">Wyniki</h4><span id="purchase-status" class="text-muted small">Ładowanie…</span></div>
            <div class="card-body table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Dostawca</th><th>Numer faktury</th><th>Numer KSeF</th><th>Data wystawienia</th><th>Przyjęto w KSeF</th><th class="text-end">Netto</th><th class="text-end">VAT</th><th class="text-end">Brutto</th><th></th></tr></thead>
                    <tbody id="purchase-invoices-body"></tbody>
                </table>
                <div id="purchase-empty" class="text-center text-muted py-4 d-none">Brak faktur dla wybranych kryteriów.</div>
                <div id="purchase-loader" class="text-center py-3 d-none"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Pobieranie kolejnych faktur z KSeF…</div>
                <div class="text-center py-3"><button id="purchase-load-more" class="btn btn-outline-primary d-none" type="button">Pobierz kolejne 25 faktur</button></div>
            </div>
        </div>
    </section>
</div>
<?php $this->view('page_components/footer'); ?>
<script src="<?= $root ?>/assets/vendor/ksef-pdf-generator/ksef-fe-invoice-converter.min.js"></script>
<script>
(() => {
    const root = <?= json_encode($root) ?>;
    const form = document.getElementById('ksef-purchase-filters');
    const body = document.getElementById('purchase-invoices-body');
    const status = document.getElementById('purchase-status');
    const loader = document.getElementById('purchase-loader');
    const loadMore = document.getElementById('purchase-load-more');
    const empty = document.getElementById('purchase-empty');
    let offset = 0, hasMore = false, loading = false, loaded = 0;

    const value = id => document.getElementById(id).value.trim();
    const filters = () => ({subjectType:value('purchase-subject-type'), dateType:value('purchase-date-type'), from:value('purchase-from'), to:value('purchase-to'), sellerNip:value('purchase-seller-nip'), invoiceNumber:value('purchase-invoice-number'), ksefNumber:value('purchase-ksef-number'), currency:value('purchase-currency')});
    const esc = value => { const el = document.createElement('span'); el.textContent = value == null ? '—' : String(value); return el.innerHTML; };
    const date = value => value ? new Intl.DateTimeFormat('pl-PL', {dateStyle:'short', timeStyle: value.includes('T') ? 'short' : undefined}).format(new Date(value)) : '—';
    const money = (value, currency) => value == null ? '—' : new Intl.NumberFormat('pl-PL', {minimumFractionDigits:2, maximumFractionDigits:2}).format(value) + ' ' + esc(currency || '');
    const seller = invoice => invoice.seller || {};

    function row(invoice) {
        const number = invoice.ksefNumber || '';
        const xmlUrl = root + '/settings/ksef/purchase-invoices/' + encodeURIComponent(number);
        return `<tr><td><div>${esc(seller(invoice).name || '—')}</div><small class="text-muted">${esc(seller(invoice).nip || '—')}</small></td><td>${esc(invoice.invoiceNumber)}</td><td><small>${esc(number)}</small></td><td>${esc(date(invoice.issueDate))}</td><td>${esc(date(invoice.invoicingDate))}</td><td class="text-end text-nowrap">${money(invoice.netAmount, invoice.currency)}</td><td class="text-end text-nowrap">${money(invoice.vatAmount, 'PLN')}</td><td class="text-end text-nowrap">${money(invoice.grossAmount, invoice.currency)}</td><td class="text-nowrap"><button class="btn btn-sm btn-outline-primary" type="button" data-ksef-pdf data-ksef-number="${esc(number)}" data-ksef-acquisition="${esc(invoice.acquisitionDate || '')}" data-ksef-qr="${esc(invoice.qrUrl || '')}" title="Wygeneruj PDF oficjalną biblioteką MF"><i class="bi bi-file-earmark-pdf"></i> PDF</button> <a class="btn btn-sm btn-outline-secondary" href="${xmlUrl}" title="Pobierz XML z KSeF"><i class="bi bi-filetype-xml"></i> XML</a></td></tr>`;
    }

    async function downloadOfficialPdf(button) {
        const converter = window['ksef-fe-invoice-converter'];
        if (!converter || typeof converter.generateInvoice !== 'function') throw new Error('Nie udało się załadować oficjalnego generatora PDF KSeF.');
        const ksefNumber = button.dataset.ksefNumber;
        const response = await fetch(root + '/settings/ksef/purchase-invoices/' + encodeURIComponent(ksefNumber), {credentials:'same-origin'});
        if (!response.ok) throw new Error((await response.text()) || 'Nie udało się pobrać XML z KSeF.');
        const xml = await response.blob();
        const file = new File([xml], 'Faktura_KSeF_' + ksefNumber + '.xml', {type:'application/xml'});
        const acquisition = button.dataset.ksefAcquisition || '';
        const pdf = await converter.generateInvoice(file, {nrKSeF:ksefNumber, acDate:acquisition ? date(acquisition) : undefined, qrCode:button.dataset.ksefQr || undefined}, 'blob');
        const url = URL.createObjectURL(pdf);
        const link = document.createElement('a');
        link.href = url; link.download = 'Faktura_KSeF_' + ksefNumber + '.pdf';
        document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
    }

    async function loadPage(reset = false) {
        if (loading || (!reset && !hasMore)) return;
        if (reset) { offset = 0; hasMore = true; loaded = 0; body.innerHTML = ''; empty.classList.add('d-none'); loadMore.classList.add('d-none'); }
        loading = true; loader.classList.remove('d-none'); status.textContent = 'Łączenie z KSeF…';
        try {
            const response = await fetch(root + '/ajax/settings/ksef/purchase-invoices', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({...filters(), pageOffset:offset})});
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Nie udało się pobrać faktur z KSeF.');
            const invoices = Array.isArray(data.invoices) ? data.invoices : [];
            body.insertAdjacentHTML('beforeend', invoices.map(row).join(''));
            loaded += invoices.length;
            offset += invoices.length;
            hasMore = Boolean(data.hasMore) && invoices.length > 0;
            empty.classList.toggle('d-none', loaded !== 0);
            loadMore.classList.toggle('d-none', !hasMore);
            status.textContent = hasMore ? `Wyświetlono ${loaded} — użyj przycisku, aby pobrać kolejne` : `Wyświetlono ${loaded}`;
            if (data.isTruncated) status.textContent += ' (KSeF ograniczył wynik do 10 000 dokumentów)';
        } catch (error) {
            status.textContent = 'Nie udało się pobrać danych';
            Swal.fire({icon:'error', title:'KSeF', text:error.message});
        } finally { loading = false; loader.classList.add('d-none'); }
    }

    form.addEventListener('submit', event => { event.preventDefault(); loadPage(true); });
    loadMore.addEventListener('click', () => loadPage());
    body.addEventListener('click', async event => {
        const button = event.target.closest('[data-ksef-pdf]');
        if (!button) return;
        button.disabled = true;
        try {
            Swal.fire({title:'Generowanie PDF', text:'Pobieranie XML i tworzenie wizualizacji KSeF…', allowOutsideClick:false, didOpen:() => Swal.showLoading()});
            await downloadOfficialPdf(button);
            Swal.close();
        } catch (error) { Swal.fire({icon:'error', title:'PDF KSeF', text:error.message}); }
        finally { button.disabled = false; }
    });
    loadPage(true);
})();
</script>
