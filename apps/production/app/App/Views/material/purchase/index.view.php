<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Log dostaw / zakupów</h3>
                <p class="text-subtitle text-muted">Wszystkie dokumenty w jednym miejscu + szybkie przejście do dodawania.</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Zakupy</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0">Dostawy</h4>

                <div class="d-flex gap-2">
                    <a href="<?= ROOT ?>/purchases/new" class="btn btn-primary" role="button">
                        + Nowa dostawa
                    </a>
                </div>
            </div>

            <div class="card-body">

                <!-- FILTRY -->
                <form method="GET" class="row g-2 align-items-end mb-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label">Dostawca</label>
                        <select name="supplier_id" class="form-select form-select-sm">
                            <option value="">Wszyscy</option>
                            <?php foreach (($suppliers ?? []) as $s): ?>
                                <option value="<?= (int)$s['id'] ?>"
                                    <?= ((string)($filters['supplier_id'] ?? '') === (string)$s['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Wszystkie</option>
                            <?php
                            $st = (string)($filters['status'] ?? '');
                            ?>
                            <option value="DRAFT" <?= $st === 'DRAFT' ? 'selected' : '' ?>>Szkic</option>
                            <option value="POSTED" <?= $st === 'POSTED' ? 'selected' : '' ?>>Zatwierdzony</option>
                            <option value="CANCELED" <?= $st === 'CANCELED' ? 'selected' : '' ?>>Anulowany</option>
                        </select>
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label">Od</label>
                        <input type="date" name="date_from" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label">Do</label>
                        <input type="date" name="date_to" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label">Szukaj</label>
                        <input type="text" name="q" class="form-control form-control-sm"
                               placeholder="Nr dokumentu / notatka…"
                               value="<?= htmlspecialchars($filters['q'] ?? '') ?>">
                    </div>

                    <div class="col-12 col-md-1 d-grid">
                        <button class="btn btn-secondary btn-sm" type="submit">Filtruj</button>
                    </div>

                    <div class="col-12 d-flex justify-content-end">
                        <a href="<?= ROOT ?>/purchases" class="btn btn-outline-secondary btn-sm">Wyczyść filtry</a>
                    </div>
                </form>

                <!-- TABELA -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle text-sm">
                        <thead>
                        <tr>
                            <th style="width: 8%">ID</th>
                            <th style="width: 12%">Data</th>
                            <th style="width: 18%">Dostawca</th>
                            <th style="width: 14%">Nr dokumentu</th>
                            <th style="width: 10%">Status</th>
                            <th style="width: 10%">Pozycji</th>
                            <th style="width: 12%">Suma netto</th>
                            <th style="width: 8%">Waluta</th>
                            <th style="width: 8%"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $status = $r['status'] ?? '';
                                $badge = 'bg-light-secondary';
                                if ($status === 'POSTED') $badge = 'bg-light-success';
                                if ($status === 'DRAFT') $badge = 'bg-light-warning';
                                if ($status === 'CANCELED') $badge = 'bg-light-danger';
                                ?>
                                <tr>
                                    <td>#<?= (int)$r['id'] ?></td>
                                    <td><?= htmlspecialchars($r['doc_date'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['supplier_name'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($r['doc_no'] ?? '-') ?></td>
                                    <td>
                                        <span class="badge <?= $badge ?>">
                                            <?= htmlspecialchars($status ?: '-') ?>
                                        </span>
                                    </td>
                                    <td><?= (int)($r['items_count'] ?? 0) ?></td>
                                    <td><strong><?= number_format((float)($r['total_net'] ?? 0), 2, ',', ' ') ?></strong></td>
                                    <td><?= htmlspecialchars($r['currency_code'] ?? 'PLN') ?></td>
                                    <td class="text-end">
                                        <a href="<?= ROOT ?>/purchases/<?= (int)$r['id'] ?>/show"
                                           class="btn btn-info btn-sm" role="button">
                                            Podgląd
                                        </a>

                                        <?php if (($r['status'] ?? '') !== 'POSTED'): ?>
                                            <a href="<?= ROOT ?>/purchases/<?= (int)$r['id'] ?>/edit"
                                               class="btn btn-warning btn-sm" role="button">
                                                <i class="icon dripicons-document-edit"></i>
                                            </a>

                                            <button class="btn btn-danger btn-sm" type="button"
                                                    onclick="deletePurchase(<?= (int)$r['id'] ?>, this)">
                                                <i class="icon dripicons-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    Brak dokumentów do wyświetlenia.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- PAGINACJA (MVP) -->
                <?php if (!empty($pagination) && ($pagination['total'] ?? 0) > ($pagination['per_page'] ?? 999999)): ?>
                    <?php
                    $page = (int)($pagination['page'] ?? 1);
                    $hasPrev = !empty($pagination['has_prev']);
                    $hasNext = !empty($pagination['has_next']);

                    // zachowanie querystringa filtrów
                    $qs = $_GET;
                    ?>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div class="text-muted text-sm">
                            <?= (int)$pagination['total'] ?> rekordów • strona <?= $page ?>
                        </div>

                        <div class="d-flex gap-2">
                            <?php if ($hasPrev): ?>
                                <?php $qs['page'] = $page - 1; ?>
                                <a class="btn btn-outline-secondary btn-sm" href="?<?= http_build_query($qs) ?>">
                                    ← Poprzednia
                                </a>
                            <?php else: ?>
                                <button class="btn btn-outline-secondary btn-sm" disabled>← Poprzednia</button>
                            <?php endif; ?>

                            <?php if ($hasNext): ?>
                                <?php $qs['page'] = $page + 1; ?>
                                <a class="btn btn-outline-secondary btn-sm" href="?<?= http_build_query($qs) ?>">
                                    Następna →
                                </a>
                            <?php else: ?>
                                <button class="btn btn-outline-secondary btn-sm" disabled>Następna →</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<script>
    async function deletePurchase(id, btn) {
        const confirmResult = await Swal.fire({
            title: 'Potwierdzenie',
            text: 'Czy na pewno chcesz usunąć ten dokument? (tylko szkice / niezatwierdzone)',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Tak, usuń',
            cancelButtonText: 'Anuluj'
        });

        if (!confirmResult.isConfirmed) return;

        btn.disabled = true;

        try {
            const res = await api(`/ajax/purchases/${id}`, {
                method: 'DELETE'
            });

            if (res && res.ok) {
                Swal.fire({ icon: 'success', title: 'Usunięto', text: res.message || 'Dokument został usunięty.' })
                    .then(() => location.reload());
            } else {
                btn.disabled = false;
                Swal.fire({ icon: 'error', title: 'Błąd', text: res?.message || 'Nie udało się usunąć.' });
            }
        } catch (e) {
            btn.disabled = false;
            console.error(e);
            Swal.fire({ icon: 'error', title: 'Błąd', text: 'Wystąpił błąd podczas usuwania.' });
        }
    }
</script>
