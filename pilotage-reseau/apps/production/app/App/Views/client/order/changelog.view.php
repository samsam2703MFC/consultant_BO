<?php $this->view("page_components/header"); ?>
    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Historia zmian zamówienia</h3>
                    <p class="text-subtitle text-muted">Dziennik operacji na zamówieniu</p>
                </div>
                <div class="col-12 col-md-6 order-md-2 order-first">
                    <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?=ROOT?>">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="<?=ROOT?>/client-orders">Zamówienia</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Historia zmian</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>

        <section class="section">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="card-title mb-0">
                        Zamówienie #<?= htmlspecialchars($row['id'] ?? '') ?>
                        — <?= htmlspecialchars(trim(($row['customer_name'] ?? '') . ' ' . ($row['customer_surname'] ?? ''))) ?>
                        <?php if (!empty($row['company'])): ?>
                            <small class="text-muted">(<?= htmlspecialchars($row['company']) ?>)</small>
                        <?php endif; ?>
                    </h4>
                    <a href="<?= ROOT ?>/client-orders" class="btn btn-secondary btn-sm">← Wróć do listy</a>
                </div>
                <div class="card-body">
                    <?php if (empty($changelog)): ?>
                        <div class="alert alert-info">Brak historii zmian dla tego zamówienia.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-sm">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Data i godzina</th>
                                        <th>Użytkownik</th>
                                        <th>Akcja</th>
                                        <th>Źródło</th>
                                        <th>Szczegóły zmian</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $actionLabels  = ['create' => 'Utworzono',    'update' => 'Zmodyfikowano', 'delete' => 'Usunięto'];
                                $actionClasses = ['create' => 'bg-success',   'update' => 'bg-warning text-dark', 'delete' => 'bg-danger'];
                                $sourceLabels  = ['web' => 'Panel',           'api' => 'API / Webhook',   'collector' => 'Kolektor'];
                                $fieldLabels   = [
                                    'expected_shipping_date' => 'Data wysyłki',
                                    'order_description'      => 'Opis zamówienia',
                                    'standard_price_list'    => 'Cennik standardowy',
                                    'is_freebie'             => 'Zamówienie gratis',
                                    'products'               => 'Produkty',
                                ];
                                foreach ($changelog as $entry):
                                    $action = $entry['action_type'] ?? '';
                                    $fields = !empty($entry['changed_fields']) ? json_decode($entry['changed_fields'], true) : null;
                                ?>
                                    <tr>
                                        <td class="text-nowrap"><?= htmlspecialchars($entry['created_at']) ?></td>
                                        <td>
                                            <?php if (!empty($entry['displayed_name'])): ?>
                                                <?= htmlspecialchars($entry['displayed_name']) ?>
                                            <?php elseif (!empty($entry['user_name'])): ?>
                                                <?= htmlspecialchars($entry['user_name'] . ' ' . $entry['user_surname']) ?>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">System / API</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $actionClasses[$action] ?? 'bg-secondary' ?>">
                                                <?= $actionLabels[$action] ?? htmlspecialchars($action) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($sourceLabels[$entry['source']] ?? $entry['source']) ?></td>
                                        <td>
                                            <?php if ($fields && isset($fields['before'], $fields['after'])): ?>
                                                <ul class="list-unstyled mb-0 small">
                                                <?php foreach ($fields['after'] as $key => $newVal):
                                                    $oldVal = $fields['before'][$key] ?? null;
                                                    if ($oldVal === $newVal) continue; ?>
                                                    <li>
                                                        <strong><?= htmlspecialchars($fieldLabels[$key] ?? $key) ?>:</strong>
                                                        <?php if (is_array($oldVal) || is_array($newVal)): ?>
                                                            <em class="text-muted">(zmiana listy produktów)</em>
                                                        <?php else: ?>
                                                            <span class="text-danger"><?= htmlspecialchars((string)($oldVal ?? '—')) ?></span>
                                                            &rarr;
                                                            <span class="text-success"><?= htmlspecialchars((string)($newVal ?? '—')) ?></span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                                </ul>
                                            <?php elseif ($fields && isset($fields['after'])): ?>
                                                <ul class="list-unstyled mb-0 small">
                                                <?php foreach ($fields['after'] as $key => $val): ?>
                                                    <li>
                                                        <strong><?= htmlspecialchars($fieldLabels[$key] ?? $key) ?>:</strong>
                                                        <?php if (!is_array($val)): ?>
                                                            <span class="text-success"><?= htmlspecialchars((string)$val) ?></span>
                                                        <?php else: ?>
                                                            <em class="text-muted">(zmiana pola złożonego)</em>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                                </ul>
                                            <?php elseif ($fields && isset($fields['snapshot'])): ?>
                                                <em class="text-muted small">Snapshot zamówienia przy usunięciu</em>
                                            <?php elseif ($action === 'create'): ?>
                                                <em class="text-muted small">Zamówienie zostało utworzone</em>
                                            <?php else: ?>
                                                <em class="text-muted">—</em>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

<?php $this->view("page_components/footer"); ?>
