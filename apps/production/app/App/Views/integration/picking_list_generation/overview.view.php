<?php $this->view('page_components/header'); ?>
<div class="page-heading"><div class="page-title"><h3>Historia list kompletacyjnych</h3><p class="text-subtitle text-muted">Ostatnie 200 wygenerowanych dokumentów</p></div>
<section class="section"><div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-striped align-middle">
    <thead><tr><th>#</th><th>Data</th><th>Źródło</th><th>Urządzenie / użytkownik</th><th>Zamówienia</th><th>Pozycje</th><th>Plik</th><th></th></tr></thead><tbody>
    <?php foreach ($generations as $generation): ?><tr><td><?= (int) $generation['id'] ?></td><td><?= htmlspecialchars($generation['generated_at']) ?></td>
        <td><span class="badge <?= $generation['source'] === 'api' ? 'bg-primary' : 'bg-secondary' ?>"><?= htmlspecialchars($generation['source']) ?></span></td>
        <td><?= htmlspecialchars($generation['source'] === 'api' ? ($generation['device_name'] ?? 'usunięte urządzenie') : ($generation['user_name'] ?? 'brak użytkownika')) ?></td>
        <td><?= (int) $generation['order_count'] ?></td><td><?= (int) $generation['product_count'] ?></td><td><?= htmlspecialchars($generation['filename']) ?></td>
        <td><a class="btn btn-outline-primary btn-sm" href="<?= ROOT ?>/settings/picking-list-generations/<?= (int) $generation['id'] ?>">Szczegóły</a></td></tr><?php endforeach; ?>
    <?php if (empty($generations)): ?><tr><td colspan="8" class="text-center text-muted">Brak historii wydruków.</td></tr><?php endif; ?></tbody>
</table></div></div></div></section></div>
<?php $this->view('page_components/footer'); ?>
