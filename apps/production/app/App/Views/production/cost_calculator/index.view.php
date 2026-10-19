<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Kalkulator kosztów (MVP)</h3>
                <p class="text-subtitle text-muted">Wybierz miesiąc i edytuj snapshot kosztów.</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Koszty</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0">Start</h4>
            </div>

            <div class="card-body">
                <?php
                $y = (int)($current['year'] ?? date('Y'));
                $m = (int)($current['month'] ?? date('m'));
                $ym = sprintf('%04d-%02d', $y, $m);
                ?>

                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <a class="btn btn-primary" href="<?= ROOT ?>/cost-calculator/<?= $ym ?>">
                        Otwórz bieżący miesiąc (<?= htmlspecialchars($ym) ?>)
                    </a>

                    <form class="d-flex gap-2 align-items-center" method="GET" action="<?= ROOT ?>/cost-calculator">
                        <input type="month" class="form-control" id="pick_month" value="<?= htmlspecialchars($ym) ?>" style="max-width: 220px;">
                        <button type="button" class="btn btn-secondary" onclick="goToMonth()">Przejdź</button>
                    </form>
                </div>

                <hr>

                <p class="text-muted mb-0">
                    MVP: wejście przez miesiąc → system tworzy snapshot jeśli nie istnieje.
                </p>
            </div>
        </div>

        <?php if (!empty($months)): ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0">Historia miesięcy</h4>
                <small class="text-muted">
                    <i class="bi bi-lock-fill text-success me-1"></i>ZAMKNIĘTY = zatwierdzone dane (podstawa marży)
                    &nbsp;&nbsp;
                    <i class="bi bi-unlock-fill text-warning me-1"></i>OTWARTY = edycja możliwa
                </small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Miesiąc</th>
                                <th class="text-center">Status</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($months as $mRow):
                            $mYm     = sprintf('%04d-%02d', (int)$mRow['year'], (int)$mRow['month']);
                            $mLocked = ($mRow['status'] ?? 'OPEN') === 'LOCKED';
                        ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($mYm) ?></strong>
                                </td>
                                <td class="text-center">
                                    <?php if ($mLocked): ?>
                                        <span class="badge bg-success">
                                            <i class="bi bi-lock-fill me-1"></i>ZAMKNIĘTY
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="bi bi-unlock-fill me-1"></i>OTWARTY
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= ROOT ?>/cost-calculator/<?= htmlspecialchars($mYm) ?>">
                                        <i class="bi bi-pencil-square me-1"></i>
                                        <?= $mLocked ? 'Podgląd' : 'Edytuj' ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0"><?=$translations['params']?></h4>
            </div>

            <div class="card-body">

                <div class="col-4">
                    <button class="btn btn-primary" type="button" onclick="runVarialeTypeCostModal()">
                        <?=$translations['variable_cost_types']?>
                    </button>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
    function goToMonth(){
        const v = document.getElementById('pick_month').value; // YYYY-MM
        if(!v) return;
        window.location.href = "<?= ROOT ?>/cost-calculator/" + v;
    }

    function toast(msg){ alert(msg); }

    async function postJson(url, payload){
        const r = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload || {})
        });
        const data = await r.json().catch(()=> ({}));
        if(!r.ok) throw data;
        return data;
    }

    async function getJson(url){
        const r = await fetch(url, { method: 'GET' });
        const data = await r.json().catch(()=> ({}));
        if(!r.ok) throw data;
        return data;
    }

    let mediaTypeModal;
    let mediaTypeMap = {};

    function runVarialeTypeCostModal(){
        if(!mediaTypeModal){
            mediaTypeModal = new bootstrap.Modal(document.getElementById('mediaTypeModal'));
        }
        refreshMediaTypes().then(()=> mediaTypeModal.show());
    }

    function resetMediaTypeForm(){
        document.getElementById('mt_id').value = '';
        document.getElementById('mt_code').value = '';
        document.getElementById('mt_name').value = '';
        document.getElementById('mt_unit').value = '';
        document.getElementById('mt_active').checked = true;
        document.getElementById('mt_save').innerText = 'Dodaj';
    }

    async function refreshMediaTypes(){
        const data = await getJson("<?= ROOT ?>/ajax/cost-media-types");
        const rows = data.rows || [];
        const body = document.getElementById('media_type_body');
        body.innerHTML = '';
        mediaTypeMap = {};

        rows.forEach(r => {
            mediaTypeMap[String(r.id)] = r;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>#${r.id}</td>
                <td>${escapeHtml(r.code || '')}</td>
                <td>${escapeHtml(r.name || '')}</td>
                <td>${escapeHtml(r.unit || '')}</td>
                <td class="text-center">${Number(r.is_active) === 1 ? 'TAK' : 'NIE'}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-secondary" onclick="editMediaTypeById(${r.id})">Edytuj</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteMediaType(${r.id})">Usuń</button>
                </td>
            `;
            body.appendChild(tr);
        });

        if(!rows.length){
            const tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="6" class="text-muted">Brak typów</td>';
            body.appendChild(tr);
        }
    }

    function editMediaTypeById(id){
        const row = mediaTypeMap[String(id)];
        if(!row) return;
        editMediaType(row);
    }

    function editMediaType(row){
        document.getElementById('mt_id').value = row.id || '';
        document.getElementById('mt_code').value = row.code || '';
        document.getElementById('mt_name').value = row.name || '';
        document.getElementById('mt_unit').value = row.unit || '';
        document.getElementById('mt_active').checked = Number(row.is_active) === 1;
        document.getElementById('mt_save').innerText = 'Zapisz zmiany';
    }

    async function saveMediaType(){
        const id = document.getElementById('mt_id').value;
        const payload = {
            code: document.getElementById('mt_code').value,
            name: document.getElementById('mt_name').value,
            unit: document.getElementById('mt_unit').value,
            is_active: document.getElementById('mt_active').checked ? 1 : 0
        };

        try {
            if(id){
                await postJson(`<?= ROOT ?>/ajax/cost-media-types/${id}/update`, payload);
            } else {
                await postJson(`<?= ROOT ?>/ajax/cost-media-types/add`, payload);
            }
            resetMediaTypeForm();
            await refreshMediaTypes();
        } catch(e){
            toast('Błąd zapisu');
        }
    }

    async function deleteMediaType(id){
        if(!confirm('Usunąć typ kosztu?')) return;
        try {
            await postJson(`<?= ROOT ?>/ajax/cost-media-types/${id}/delete`, {});
            await refreshMediaTypes();
        } catch(e){
            toast('Błąd usuwania');
        }
    }

    function escapeHtml(str){
        return String(str||'').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[s]));
    }
</script>

<!-- MODAL: TYPY KOSZTÓW ZMIENNYCH -->
<div class="modal fade" id="mediaTypeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Typy kosztów zmiennych</h5>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <input type="hidden" id="mt_id">
                    <div class="col-md-3">
                        <label class="form-label">Kod</label>
                        <input class="form-control" id="mt_code" placeholder="np. POWER">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Nazwa</label>
                        <input class="form-control" id="mt_name" placeholder="np. Prąd">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Jednostka</label>
                        <input class="form-control" id="mt_unit" placeholder="np. PLN, kWh">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Aktywny</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="mt_active" checked>
                            <label class="form-check-label" for="mt_active">TAK</label>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-primary" id="mt_save" onclick="saveMediaType()">Dodaj</button>
                    <button class="btn btn-outline-secondary" onclick="resetMediaTypeForm()">Nowy</button>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Kod</th>
                            <th>Nazwa</th>
                            <th>Jednostka</th>
                            <th class="text-center">Aktywny</th>
                            <th class="text-end"></th>
                        </tr>
                        </thead>
                        <tbody id="media_type_body"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>

<?php $this->view("page_components/footer"); ?>
