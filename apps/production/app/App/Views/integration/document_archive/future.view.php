<?php $this->view('page_components/header'); ?>
<div class="page-heading"><div class="page-title"><h3><?= htmlspecialchars($title) ?></h3><p class="text-subtitle text-muted">Trasa archiwum jest już dostępna.</p></div>
<section class="section"><div class="card"><div class="card-body"><div class="alert alert-info mb-0"><i class="bi bi-info-circle me-2"></i><?= htmlspecialchars($description) ?></div></div></div></section></div>
<?php $this->view('page_components/footer'); ?>
