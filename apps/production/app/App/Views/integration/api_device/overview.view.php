<?php $this->view('page_components/header'); ?>

<div class="page-heading">
    <div class="page-title"><div class="row"><div class="col-12 col-md-6">
        <h3>Urządzenia API</h3><p class="text-subtitle text-muted">Dostęp aplikacji produkcyjnej do wewnętrznego API</p>
    </div></div></div>
    <section class="section"><div class="card"><div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Zarejestrowane urządzenia</h4>
        <a class="btn btn-primary btn-sm" href="<?= ROOT ?>/settings/api-devices/new">Dodaj urządzenie</a>
    </div><div class="card-body">
        <?php if (!empty($successes)): ?><div class="alert alert-success"><?= implode('<br>', array_map('htmlspecialchars', $successes)) ?></div><?php endif; ?>
        <?php if (!empty($errors)): ?><div class="alert alert-danger"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div><?php endif; ?>
        <div class="table-responsive"><table class="table table-striped align-middle">
            <thead><tr><th>Nazwa</th><th>Device ID</th><th>Status</th><th>Ostatnie użycie</th><th>Utworzono</th><th></th></tr></thead>
            <tbody><?php foreach ($devices as $device): ?><tr>
                <td><strong><?= htmlspecialchars($device['name']) ?></strong><?php if (!empty($device['description'])): ?><br><small class="text-muted"><?= htmlspecialchars($device['description']) ?></small><?php endif; ?></td>
                <td><code><?= htmlspecialchars($device['device_id']) ?></code></td>
                <td><span class="badge <?= $device['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $device['is_active'] ? 'Aktywne' : 'Wyłączone' ?></span></td>
                <td><?= htmlspecialchars($device['last_used_at'] ?? '—') ?></td><td><?= htmlspecialchars($device['created_at']) ?></td>
                <td class="text-nowrap"><form class="d-inline" method="post" action="<?= ROOT ?>/settings/api-devices/<?= (int) $device['id'] ?>/rotate"><button class="btn btn-outline-warning btn-sm" type="submit">Rotuj klucz</button></form>
                <form class="d-inline" method="post" action="<?= ROOT ?>/settings/api-devices/<?= (int) $device['id'] ?>/toggle"><button class="btn btn-outline-secondary btn-sm" type="submit"><?= $device['is_active'] ? 'Wyłącz' : 'Włącz' ?></button></form></td>
            </tr><?php endforeach; ?>
            <?php if (empty($devices)): ?><tr><td colspan="6" class="text-center text-muted">Brak zarejestrowanych urządzeń.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div></section>
</div>
<?php $this->view('page_components/footer'); ?>
