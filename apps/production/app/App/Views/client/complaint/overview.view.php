<?php
use App\Core\Helpers\ComplaintReasonTranslator;
$this->view("page_components/header");

$compensationLabels = [
    'REFUND'      => 'Zwrot pieniędzy',
    'REPLACEMENT' => 'Wymiana produktu',
    'CREDIT'      => 'Kredyt na konto',
];
$statusCounts = array_count_values(array_map(fn($c) => $c['acceptance_status'] ?: 'NEW', $complaints ?? []));
$nextShipping = $next_shipping ?? [];
?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Reklamacje</h3>
                <p class="text-subtitle text-muted">Panel obsługi reklamacji z sieci franczyzowej.</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= $root ?? '#' ?>">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Reklamacje</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h4 class="card-title mb-0">Przegląd reklamacji</h4>
                    <small class="text-muted">Przyjmij do weryfikacji albo odrzuć z uzasadnieniem.</small>
                </div>

                <div class="d-flex gap-2 align-items-center">
                    <button class="btn btn-outline-secondary" onclick="getNewComplaints()">
                        <i class="bi bi-cloud-download"></i> Pobierz nowe
                    </button>
                </div>
            </div>

            <div class="card-body">

                <?php if (!empty($errors)): ?>
                    <div class="row">
                        <div class="col-12 col-lg-8 mx-auto">
                            <div class="alert alert-danger text-center">
                                <?= implode("<br>", $errors) ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="at-tabs mt-2" id="status-tabs" role="tablist" aria-label="Filtruj po statusie">
                    <?php foreach (['NEW' => 'Nowe', 'IN_REVIEW' => 'W weryfikacji', 'ACCEPTED' => 'Przyjęte', 'REJECTED' => 'Odrzucone', 'ALL' => 'Wszystkie'] as $code => $label): ?>
                        <?php $n = $code === 'ALL' ? count($complaints ?? []) : ($statusCounts[$code] ?? 0); ?>
                        <button type="button" role="tab" data-status="<?= $code ?>" onclick="applyStatusFilter('<?= $code ?>')">
                            <?= $label ?>
                            <span class="at-tab-count<?= in_array($code, ['NEW', 'IN_REVIEW'], true) && $n ? ' at-tab-count-hot' : '' ?>"><?= $n ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <hr class="my-4">

                <?php if (empty($complaints)): ?>
                    <div class="alert alert-info mb-0">
                        Brak reklamacji do wyświetlenia.
                    </div>
                <?php else: ?>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="text-muted small">
                            Widoczne: <span id="complaints-visible-count">0</span>
                        </div>
                    </div>
                    <div class="accordion" id="complaintsAccordion">
                        <?php foreach ($complaints as $idx => $complaint): ?>
                            <?php
                            $cid = (int)($complaint['id'] ?? 0);
                            $complaintType = $complaint['complaint_type'] ?? 'PRODUCT';
                            $isNonProduct = $complaintType === 'NON_PRODUCT';
                            $typeBadgeClass = $isNonProduct ? 'bg-dark' : 'bg-info text-dark';
                            $typeBadgeText = $isNonProduct ? 'Inna reklamacja' : 'Reklamacja produktowa';

                            $customerName = $complaint['customer_name'] ?? '-';
                            $reason       = ComplaintReasonTranslator::translate($complaint['complaint_reason'] ?? null);
                            $productName  = $complaint['product_name'] ?? '-';
                            $qty          = $complaint['product_quantity'] ?? '-';
                            // 40.000 z bazy to 40 sztuk — bez zbędnych zer i z przecinkiem dziesiętnym.
                            $fmtQty = fn($v) => is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',') : (string) $v;
                            $comment      = $complaint['client_comment'] ?? '';
                            $incidentAt   = $complaint['acceptance_timestamp'] ?? '-';
                            $reportedAt   = $complaint['reported_at'] ?? '-';

                            $attachments  = $complaint['attachments'] ?? [];
                            $hasAtt       = !empty($attachments);

                            $requestedAction   = $complaint['requested_action'] ?? null;
                            $compensationMethod = $complaint['compensation_method'] ?? null;
                            $acceptedQty       = $complaint['accepted_quantity'] ?? null;

                            $invoiceNum  = $complaint['invoice_num'] ?? null;
                            $invoiceDate = $complaint['invoice_date'] ?? null;
                            $wdtId       = $complaint['wdt_id'] ?? null;

                            $requestedActionLabel   = $compensationLabels[$requestedAction]   ?? $requestedAction   ?? '—';
                            $compensationMethodLabel = $compensationLabels[$compensationMethod] ?? $compensationMethod ?? '—';

                            // Jeśli masz już w danych status typu "NEW/IN_REVIEW/REJECTED" itd. – podepnij:
                            $accStatus    = $complaint['acceptance_status'] ?? null;
                            $badgeClass   = 'bg-secondary';
                            $badgeText    = 'Nieznany';

                            if ($accStatus === 'NEW') { $badgeClass = 'bg-primary'; $badgeText = 'Nowa'; }
                            elseif ($accStatus === 'IN_REVIEW') { $badgeClass = 'bg-warning text-dark'; $badgeText = 'W weryfikacji'; }
                            elseif ($accStatus === 'REJECTED') { $badgeClass = 'bg-danger'; $badgeText = 'Odrzucona'; }
                            elseif ($accStatus === 'ACCEPTED') { $badgeClass = 'bg-success'; $badgeText = 'Przyjęta'; }

                            $shortComment = mb_strlen($comment) > 90 ? (mb_substr($comment, 0, 90) . '…') : $comment;

                            // Ile dni czeka na decyzję: > 7 dni na czerwono, od 3 dni na pomarańczowo.
                            $isOpen = in_array($accStatus ?: 'NEW', ['NEW', 'IN_REVIEW'], true);
                            $waitDays = $isOpen && strtotime((string) $incidentAt) ? (int) floor((time() - strtotime($incidentAt)) / 86400) : null;
                            $waitClass = $waitDays === null ? '' : ($waitDays > 7 ? 'at-pill-danger' : ($waitDays >= 3 ? 'at-pill-warn' : 'at-pill-muted'));

                            $nextShip = $nextShipping[$complaint['id_customer'] ?? 0] ?? null;
                            $quick = [
                                'id' => $cid,
                                'status' => $accStatus ?: 'NEW',
                                'qty' => (int) ($qty ?: 0),
                                'nonProduct' => $isNonProduct,
                                'requested' => $requestedAction,
                                'product' => $productName,
                                'customer' => $customerName,
                                'nextShip' => $nextShip ? date('d/m/Y', strtotime($nextShip)) : null,
                            ];
                            ?>

                            <div class="accordion-item mb-2 border rounded-3 complaint-item"
                                 data-status="<?= htmlspecialchars($accStatus ?: 'NEW') ?>">
                                <h2 class="accordion-header" id="heading-<?= $cid ?>">
                                    <button class="accordion-button collapsed"
                                            type="button"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#collapse-<?= $cid ?>"
                                            aria-expanded="false"
                                            aria-controls="collapse-<?= $cid ?>">

                                        <div class="w-100">

                                            <!-- Wiersz 1: klient + status + data -->
                                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="bi bi-person-fill text-muted"></i>
                                                    <span class="fw-semibold fs-6"><?= htmlspecialchars($customerName) ?></span>
                                                    <span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span>
                                                    <span class="badge <?= $typeBadgeClass ?>"><?= $typeBadgeText ?></span>
                                                    <?php if ($waitDays !== null): ?>
                                                        <span class="at-pill <?= $waitClass ?>"><i class="bi bi-clock"></i> czeka <?= $waitDays ?> <?= $waitDays === 1 ? 'dzień' : 'dni' ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-muted small">
                                                    <i class="bi bi-calendar-event me-1"></i>
                                                    <strong>Data wpłynięcia:</strong> <?= htmlspecialchars($incidentAt) ?>
                                                    <span class="mx-1">|</span>
                                                    <strong>Data zgłoszenia:</strong> <?= htmlspecialchars($reportedAt) ?>
                                                </div>
                                            </div>

                                            <?php if (!$isNonProduct): ?>
                                                <!-- Wiersz 2: produkt -->
                                                <div class="d-flex flex-wrap align-items-center gap-1 mb-2">
                                                    <span class="text-muted small me-1">Produkt:</span>
                                                    <span class="fw-bold"><?= htmlspecialchars($productName) ?></span>
                                                </div>
                                            <?php endif; ?>

                                            <!-- Wiersz 3: temat + ilość + załączniki -->
                                            <div class="d-flex flex-wrap align-items-center gap-3">
                                                <span class="small">
                                                    <span class="text-muted">Temat:</span>
                                                    <span class="ms-1"><?= htmlspecialchars($reason) ?></span>
                                                </span>

                                                <?php if (!$isNonProduct): ?>
                                                    <span class="small">
                                                        <span class="text-muted">Ilość reklamowana:</span>
                                                        <span class="ms-1 fw-semibold badge bg-secondary"><?= htmlspecialchars($fmtQty($qty)) ?></span>
                                                        <span class="ms-1 text-muted">szt.</span>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if (!$isNonProduct): ?>
                                                    <span class="small">
                                                        <span class="text-muted">Żądana komp.:</span>
                                                        <?php if ($requestedAction): ?>
                                                            <span class="ms-1 badge bg-info text-dark"><?= htmlspecialchars($requestedActionLabel) ?></span>
                                                        <?php else: ?>
                                                            <span class="ms-1 text-muted fst-italic">—</span>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($accStatus === 'ACCEPTED' && $compensationMethod): ?>
                                                    <span class="small">
                                                        <span class="text-muted">Uznana komp.:</span>
                                                        <span class="ms-1 badge bg-success"><?= htmlspecialchars($compensationMethodLabel) ?></span>
                                                    </span>
                                                    <?php if ($acceptedQty !== null): ?>
                                                        <span class="small">
                                                            <span class="text-muted">Uznana ilość:</span>
                                                            <span class="ms-1 fw-semibold badge bg-secondary"><?= htmlspecialchars($fmtQty($acceptedQty)) ?></span>
                                                            <span class="ms-1 text-muted">szt.</span>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <?php if (!$isNonProduct && $invoiceNum): ?>
                                                    <span class="small">
                                                        <span class="text-muted">Faktura:</span>
                                                        <span class="ms-1 fw-semibold"><?= htmlspecialchars($invoiceNum) ?></span>
                                                    </span>
                                                <?php endif; ?>

                                                <span class="small">
                                                    <span class="text-muted">Załączniki:</span>
                                                    <?php if ($hasAtt): ?>
                                                        <span class="ms-1 badge bg-info text-dark">
                                                            <i class="bi bi-paperclip me-1"></i><?= count($attachments) ?> plik<?= count($attachments) === 1 ? '' : (count($attachments) < 5 ? 'i' : 'ów') ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="ms-1 text-muted fst-italic">brak</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>

                                        </div>
                                    </button>
                                </h2>

                                <?php if ($isOpen): ?>
                                    <div class="at-quick" data-complaint="<?= htmlspecialchars(json_encode($quick, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
                                        <button class="btn btn-sm btn-primary" type="button" data-quick="accept">
                                            <i class="bi bi-check2"></i> Uznaj
                                        </button>
                                        <?php if (($accStatus ?: 'NEW') === 'NEW'): ?>
                                            <button class="btn btn-sm btn-outline-dark" type="button" data-quick="review">
                                                <i class="bi bi-hourglass-split"></i> Do weryfikacji
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-danger" type="button" data-quick="reject">
                                            <i class="bi bi-x"></i> Odrzuć
                                        </button>
                                        <span class="at-quick-hint">Decyzja trafia do systemu franczyzy.</span>
                                    </div>
                                <?php endif; ?>

                                <div id="collapse-<?= $cid ?>"
                                     class="accordion-collapse collapse"
                                     aria-labelledby="heading-<?= $cid ?>"
                                     data-bs-parent="#complaintsAccordion">

                                    <div class="accordion-body">
                                        <div class="row g-3">

                                            <div class="col-12 col-lg-8">

                                                <!-- Karta: szczegóły reklamacji -->
                                                <div class="card border mb-3">
                                                    <div class="card-header bg-light py-2">
                                                        <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Szczegóły reklamacji</h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <dl class="row mb-0">
                                                            <dt class="col-sm-5 text-muted fw-normal">Klient (franchiza)</dt>
                                                            <dd class="col-sm-7 fw-semibold"><?= htmlspecialchars($customerName) ?></dd>

                                                            <?php if (!$isNonProduct): ?>
                                                                <dt class="col-sm-5 text-muted fw-normal">Produkt</dt>
                                                                <dd class="col-sm-7 fw-semibold"><?= htmlspecialchars($productName) ?></dd>

                                                                <dt class="col-sm-5 text-muted fw-normal">Ilość reklamowana</dt>
                                                                <dd class="col-sm-7">
                                                                    <span class="badge bg-secondary me-1"><?= htmlspecialchars($fmtQty($qty)) ?></span> szt.
                                                                </dd>
                                                            <?php endif; ?>

                                                            <dt class="col-sm-5 text-muted fw-normal">Temat / powód</dt>
                                                            <dd class="col-sm-7"><?= htmlspecialchars($reason) ?></dd>

                                                            <?php if (!$isNonProduct): ?>
                                                                <dt class="col-sm-5 text-muted fw-normal">Żądany sposób rekompensaty</dt>
                                                                <dd class="col-sm-7">
                                                                    <?php if ($requestedAction): ?>
                                                                        <span class="badge bg-info text-dark"><?= htmlspecialchars($requestedActionLabel) ?></span>
                                                                    <?php else: ?>
                                                                        <span class="text-muted fst-italic">—</span>
                                                                    <?php endif; ?>
                                                                </dd>
                                                            <?php endif; ?>

                                                            <?php if (!$isNonProduct): ?>
                                                                <dt class="col-sm-5 text-muted fw-normal">Numer faktury</dt>
                                                                <dd class="col-sm-7">
                                                                <?php if ($invoiceNum): ?>
                                                                    <span class="fw-semibold"><?= htmlspecialchars($invoiceNum) ?></span>
                                                                <?php else: ?>
                                                                    <span class="text-muted fst-italic">—</span>
                                                                <?php endif; ?>
                                                                </dd>

                                                                <dt class="col-sm-5 text-muted fw-normal">Data faktury</dt>
                                                                <dd class="col-sm-7">
                                                                <?php if ($invoiceDate): ?>
                                                                    <?= htmlspecialchars($invoiceDate) ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted fst-italic">—</span>
                                                                <?php endif; ?>
                                                                </dd>

                                                                <dt class="col-sm-5 text-muted fw-normal">Dokument faktury</dt>
                                                                <dd class="col-sm-7">
                                                                <?php if ($wdtId): ?>
                                                                    <a href="<?= $root ?? '' ?>/ajax/wdt-documents/<?= (int)$wdtId ?>/document"
                                                                       class="btn btn-sm btn-outline-primary"
                                                                       target="_blank">
                                                                        <i class="bi bi-file-earmark-arrow-down me-1"></i>Pobierz fakturę
                                                                    </a>
                                                                <?php else: ?>
                                                                    <span class="text-muted fst-italic">Brak dokumentu</span>
                                                                <?php endif; ?>
                                                                </dd>
                                                            <?php endif; ?>

                                                            <dt class="col-sm-5 text-muted fw-normal">Data wpłynięcia</dt>
                                                            <dd class="col-sm-7"><?= htmlspecialchars($incidentAt) ?></dd>

                                                            <dt class="col-sm-5 fw-bold">Data zgłoszenia</dt>
                                                            <dd class="col-sm-7 fw-bold"><?= htmlspecialchars($reportedAt) ?></dd>

                                                            <dt class="col-sm-5 text-muted fw-normal">Status</dt>
                                                            <dd class="col-sm-7"><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span></dd>

                                                            <?php if ($accStatus === 'ACCEPTED' && !$isNonProduct): ?>
                                                                <dt class="col-sm-5 text-muted fw-normal">Uznany sposób rekompensaty</dt>
                                                                <dd class="col-sm-7">
                                                                    <?php if ($compensationMethod): ?>
                                                                        <span class="badge bg-success"><?= htmlspecialchars($compensationMethodLabel) ?></span>
                                                                    <?php else: ?>
                                                                        <span class="text-muted fst-italic">—</span>
                                                                    <?php endif; ?>
                                                                </dd>

                                                                <dt class="col-sm-5 text-muted fw-normal">Ilość uznana</dt>
                                                                <dd class="col-sm-7">
                                                                    <?php if ($acceptedQty !== null): ?>
                                                                        <span class="badge bg-secondary me-1"><?= htmlspecialchars($fmtQty($acceptedQty)) ?></span> szt.
                                                                    <?php else: ?>
                                                                        <span class="text-muted fst-italic">—</span>
                                                                    <?php endif; ?>
                                                                </dd>
                                                            <?php endif; ?>
                                                        </dl>
                                                    </div>
                                                </div>

                                                <!-- Karta: komentarz + załączniki -->
                                                <div class="card border">
                                                    <div class="card-header bg-light py-2">
                                                        <h6 class="mb-0"><i class="bi bi-chat-left-text me-2"></i>Komentarz klienta</h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <?php if ($comment): ?>
                                                            <div class="text-sm"><?= nl2br(htmlspecialchars($comment)) ?></div>
                                                        <?php else: ?>
                                                            <div class="text-muted text-sm fst-italic">Brak komentarza.</div>
                                                        <?php endif; ?>

                                                        <hr>

                                                        <h6 class="mb-2 d-flex align-items-center gap-2">
                                                            <i class="bi bi-paperclip"></i> Załączniki
                                                            <span class="badge bg-light text-dark">
                                                                <?= $hasAtt ? count($attachments) : 0 ?>
                                                            </span>
                                                        </h6>

                                                        <?php if ($hasAtt): ?>
                                                            <div class="d-flex flex-wrap gap-2">
                                                                <?php foreach ($attachments as $attIdx => $attachment): ?>
                                                                    <button class="btn btn-outline-primary btn-sm"
                                                                            onclick="showAttachment('<?= htmlspecialchars($attachment['id']) ?>')">
                                                                        <i class="bi bi-file-earmark me-1"></i>Załącznik <?= $attIdx + 1 ?>
                                                                    </button>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="text-muted text-sm fst-italic">Brak załączników.</div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                            </div>

                                            <div class="col-12 col-lg-4">
                                                <div class="card border">
                                                    <div class="card-body">
                                                        <h6 class="mb-3">Akcje</h6>

                                                        <?php if ($accStatus === 'REJECTED' || $accStatus === 'ACCEPTED'): ?>

                                                            <div class="alert alert-light border mb-0">
                                                                <div class="fw-semibold">Archiwum</div>
                                                                <div class="text-muted small">
                                                                    Ten wpis jest zamknięty — brak dostępnych akcji.
                                                                </div>
                                                            </div>

                                                        <?php elseif ($accStatus === 'IN_REVIEW'): ?>

                                                            <div class="d-grid gap-2">
                                                                <button class="btn btn-primary"
                                                                        type="button"
                                                                        onclick="openResolveModal(
                                                                        <?= $cid ?>,
                                                                        <?= (int)($qty ?: 0) ?>,
                                                                        <?= $isNonProduct ? 'true' : 'false' ?>,
                                                                                this
                                                                                )">
                                                                    <i class="bi bi-check2-square"></i> Rozpatrz<?= $isNonProduct ? '' : ' (ilość + komentarz)' ?>
                                                                </button>

                                                                <button class="btn btn-outline-danger"
                                                                        type="button"
                                                                        onclick="rejectComplaint(<?= $cid ?>, this)">
                                                                    <i class="bi bi-x-circle"></i> Odrzuć reklamację
                                                                </button>
                                                            </div>

                                                        <?php else: /* NEW i inne */ ?>

                                                            <div class="d-grid gap-2">
                                                                <button class="btn btn-success"
                                                                        type="button"
                                                                        onclick="acceptComplaint(<?= $cid ?>, this)">
                                                                    <i class="bi bi-check2-circle"></i> Przyjmij do weryfikacji
                                                                </button>

                                                                <button class="btn btn-outline-danger"
                                                                        type="button"
                                                                        onclick="rejectComplaint(<?= $cid ?>, this)">
                                                                    <i class="bi bi-x-circle"></i> Odrzuć reklamację
                                                                </button>
                                                            </div>

                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>

                                        </div><!-- row -->
                                    </div><!-- accordion-body -->
                                </div><!-- collapse -->
                            </div><!-- accordion-item -->

                        <?php endforeach; ?>
                    </div><!-- accordion -->

                <?php endif; ?>

            </div><!-- card-body -->
        </div><!-- card -->
    </section>
</div>

<script>
    // Gotowe odpowiedzi: etykieta po polsku, treść po francusku (trafia do franczyzobiorcy).
    const REJECT_REASONS = [
        ['Brak zdjęć', "Aucune photo jointe : nous ne pouvons pas constater l'anomalie."],
        ['Brak opisu', "La description ne permet pas d'identifier l'anomalie constatée."],
        ['Po terminie', "La réclamation a été déposée après le délai prévu."],
        ['Szkoda po dostawie', "Le dommage est survenu après la livraison (transport ou stockage)."],
        ['Ilość zgodna z WZ', "La quantité livrée correspond au bon de livraison."],
        ['Zgodny ze specyfikacją', "Le produit livré est conforme à sa fiche technique."],
    ];

    // Domyślna odpowiedź przy uznaniu, zależnie od formy rekompensaty.
    function acceptAnswer(method, nextShip) {
        if (method === 'REPLACEMENT') return nextShip ? `Expédition le ${nextShip}` : 'Remplacement lors de la prochaine expédition.';
        if (method === 'REFUND') return 'Remboursement accordé.';
        if (method === 'CREDIT') return 'Avoir accordé sur votre compte.';
        return '';
    }

    function patchComplaint(id, payload) {
        return api(`/ajax/franchise-complaints/${id}`, { method: 'PATCH', data: payload }).then(result => {
            if (!result.ok) throw new Error(result.description || result.message || 'Błąd aktualizacji');
            return result;
        });
    }

    // „Uznaj” jednym oknem: ilość i forma rekompensaty są już wypełnione.
    // Nowa reklamacja przechodzi przez „W weryfikacji”, tak jak dotąd widzi to franczyza.
    function quickAccept(c, btn) {
        const method = ['REFUND', 'REPLACEMENT', 'CREDIT'].includes(c.requested) ? c.requested : 'REPLACEMENT';
        const esc = (v) => String(v ?? '').replace(/[&<>"']/g, ch => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch]));
        const fields = c.nonProduct ? `
                <label class="form-label" for="production_response">Odpowiedź dla franczyzy</label>
                <textarea id="production_response" class="form-control" rows="3"></textarea>
            ` : `
                <p class="mb-3"><strong>${esc(c.product)}</strong><br><span class="text-muted">${esc(c.customer)} · zgłoszono ${c.qty} szt.</span></p>
                <div class="row g-2 mb-3">
                    <div class="col-7">
                        <label class="form-label" for="compensation_method">Rekompensata</label>
                        <select id="compensation_method" class="form-select">
                            ${Object.entries({REPLACEMENT: 'Wymiana produktu', REFUND: 'Zwrot pieniędzy', CREDIT: 'Kredyt na konto'}).map(([k, l]) => `<option value="${k}" ${k === method ? 'selected' : ''}>${l}</option>`).join('')}
                        </select>
                    </div>
                    <div class="col-5">
                        <label class="form-label" for="accepted_qty">Ilość uznana</label>
                        <input id="accepted_qty" type="number" class="form-control" min="0" max="${c.qty}" step="1" value="${c.qty}">
                    </div>
                </div>
                <label class="form-label" for="production_response">Odpowiedź dla franczyzy</label>
                <textarea id="production_response" class="form-control" rows="2">${esc(acceptAnswer(method, c.nextShip))}</textarea>
            `;
        Swal.fire({
            title: 'Uznanie reklamacji',
            html: `<div class="text-start">${fields}</div>`,
            showCancelButton: true,
            confirmButtonText: 'Uznaj',
            cancelButtonText: 'Anuluj',
            focusConfirm: true,
            didOpen: (popup) => {
                const select = popup.querySelector('#compensation_method');
                const area = popup.querySelector('#production_response');
                if (!select) return;
                let auto = area.value;
                select.addEventListener('change', () => {
                    if (area.value === auto) area.value = auto = acceptAnswer(select.value, c.nextShip);
                });
            },
            preConfirm: () => {
                const comment = (document.getElementById('production_response')?.value ?? '').trim();
                if (comment.length > 2000) { Swal.showValidationMessage('Odpowiedź jest za długa (max 2000 znaków).'); return false; }
                if (c.nonProduct) return { acceptance_status: 'ACCEPTED', production_response: comment };
                const qty = parseInt(document.getElementById('accepted_qty')?.value ?? '', 10);
                if (Number.isNaN(qty) || qty < 0) { Swal.showValidationMessage('Podaj poprawną ilość (>= 0).'); return false; }
                if (qty > c.qty) { Swal.showValidationMessage('Nie możesz uznać więcej niż zgłoszono.'); return false; }
                return {
                    acceptance_status: 'ACCEPTED',
                    accepted_quantity: qty,
                    production_response: comment,
                    compensation_method: document.getElementById('compensation_method').value,
                };
            }
        }).then(res => {
            if (!res.isConfirmed) return;
            if (btn) btn.disabled = true;
            const first = c.status === 'NEW' ? patchComplaint(c.id, { acceptance_status: 'IN_REVIEW' }) : Promise.resolve();
            first.then(() => patchComplaint(c.id, res.value))
                .then(() => { showSnack(true, 'Reklamacja uznana'); setTimeout(() => location.reload(), 800); })
                .catch(err => { showSnack(false, err.message || 'Błąd komunikacji'); if (btn) btn.disabled = false; });
        });
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-quick]');
        if (!btn) return;
        const c = JSON.parse(btn.closest('[data-complaint]').dataset.complaint);
        if (btn.dataset.quick === 'accept') quickAccept(c, btn);
        if (btn.dataset.quick === 'review') acceptComplaint(c.id, btn);
        if (btn.dataset.quick === 'reject') rejectComplaint(c.id, btn);
    });

    function acceptComplaint(id, btn) {
        updateComplaint(id, { acceptance_status: 'IN_REVIEW' }, btn, 'Reklamacja przyjęta do weryfikacji');
    }

    function rejectComplaint(id, btn) {
        Swal.fire({
            title: 'Odrzucenie reklamacji',
            html: `
                <div class="text-start">
                    <label class="form-label">Gotowe odpowiedzi</label>
                    <div class="at-chips mb-3">
                        ${REJECT_REASONS.map(([label], i) => `<button type="button" class="at-chip" data-reason="${i}">${label}</button>`).join('')}
                    </div>
                    <label class="form-label" for="production_response">Uzasadnienie odrzucenia</label>
                    <textarea id="production_response" class="form-control" rows="5"
                        placeholder="Wpisz powód odrzucenia albo wybierz gotową odpowiedź..."></textarea>
                    <div class="form-text">
                        Ta informacja zostanie wysłana do systemu franczyzowego (odpowiedzi po francusku, jak dotychczas).
                    </div>
                </div>
            `,
            didOpen: (popup) => {
                popup.querySelectorAll('[data-reason]').forEach(chip => chip.addEventListener('click', () => {
                    const area = popup.querySelector('#production_response');
                    const text = REJECT_REASONS[chip.dataset.reason][1];
                    area.value = area.value.trim() ? area.value.trim() + '\n' + text : text;
                    chip.classList.add('is-used');
                    area.focus();
                }));
            },
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Odrzuć',
            cancelButtonText: 'Anuluj',
            preConfirm: () => {
                const txt = document.getElementById('production_response')?.value?.trim() || '';
                if (txt.length < 3) {
                    Swal.showValidationMessage('Wpisz krótkie uzasadnienie (min. 3 znaki).');
                    return false;
                }
                if (txt.length > 2000) {
                    Swal.showValidationMessage('Uzasadnienie jest za długie (max 2000 znaków).');
                    return false;
                }
                return txt;
            }
        }).then((result) => {
            if (!result.isConfirmed) return;

            const production_response = result.value;

            // disable button while request
            btn.disabled = true;

            updateComplaint(
                id,
                {
                    acceptance_status: 'REJECTED',
                    production_response: production_response
                },
                btn,
                'Reklamacja odrzucona'
            );
        });
    }

    const COMPENSATION_LABELS = {
        REFUND:      'Zwrot pieniędzy (REFUND)',
        REPLACEMENT: 'Wymiana produktu (REPLACEMENT)',
        CREDIT:      'Kredyt na konto (CREDIT)'
    };

    function openResolveModal(id, defaultQty, isNonProduct, btn){
        const fields = isNonProduct ? `
                <label class="form-label">Odpowiedź dla franczyzy</label>
                <textarea id="production_response" class="form-control" rows="4"
                          placeholder="Informacje dla franczyzy..."></textarea>
            ` : `
                <label class="form-label">Forma kompensacji</label>
                <select id="compensation_method" class="form-select mb-3">
                    <option value="REFUND">${COMPENSATION_LABELS.REFUND}</option>
                    <option value="REPLACEMENT" selected>${COMPENSATION_LABELS.REPLACEMENT}</option>
                    <option value="CREDIT">${COMPENSATION_LABELS.CREDIT}</option>
                </select>

                <label class="form-label">Ilość uznana do reklamacji</label>
                <input id="accepted_qty" type="number" class="form-control"
                       min="0" step="1" value="${defaultQty}">
                <div class="form-text mb-3">
                    Domyślnie ustawiono ilość zgłoszoną przez franczyzę.
                </div>

                <label class="form-label">Komentarz (opcjonalnie)</label>
                <textarea id="production_response" class="form-control" rows="4"
                          placeholder="Dodatkowe informacje dla franczyzy..."></textarea>
            `;
        Swal.fire({
            title: 'Rozpatrzenie reklamacji',
            html: `
            <div class="text-start">
                ${fields}
            </div>
        `,
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: 'Zatwierdź',
            cancelButtonText: 'Anuluj',
            preConfirm: () => {
                const comment = (document.getElementById('production_response')?.value ?? '').trim();
                if (isNonProduct) {
                    if (comment.length > 2000) {
                        Swal.showValidationMessage('Odpowiedź jest za długa (max 2000 znaków).');
                        return false;
                    }
                    return { comment };
                }

                const qty = parseInt(document.getElementById('accepted_qty')?.value ?? '0', 10);
                const compensation_method = document.getElementById('compensation_method')?.value || 'REPLACEMENT';

                if (Number.isNaN(qty) || qty < 0) {
                    Swal.showValidationMessage('Podaj poprawną ilość (>= 0).');
                    return false;
                }

                // MVP: nie pozwalamy zaakceptować więcej niż zgłoszono (jeśli defaultQty to ilość zgłoszona)
                if (qty > defaultQty) {
                    Swal.showValidationMessage('Nie możesz zaakceptować więcej niż zgłoszono.');
                    return false;
                }

                if (comment.length > 2000) {
                    Swal.showValidationMessage('Komentarz jest za długi (max 2000 znaków).');
                    return false;
                }

                return { qty, comment, compensation_method };
            }
        }).then(res => {
            if(!res.isConfirmed) return;

            const { qty, comment, compensation_method } = res.value;

            if(btn) btn.disabled = true;

            // status ACCEPTED + ilość uznana + forma kompensacji
            const payload = isNonProduct
                ? { acceptance_status: 'ACCEPTED', production_response: comment }
                : {
                    acceptance_status: 'ACCEPTED',
                    accepted_quantity: qty,
                    production_response: comment,
                    compensation_method: compensation_method
                };
            updateComplaint(
                id,
                payload,
                btn,
                'Reklamacja rozpatrzona'
            );
        });
    }

    function updateComplaint(id, payload, btn, successMsg) {
        if (btn) btn.disabled = true;

        api(`/ajax/franchise-complaints/${id}`, {
            method: 'PATCH',
            data: payload
        })
            .then(result => {
                if (result.ok) {
                    showSnack(true, result.description || successMsg || 'Zapisano');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showSnack(false, result.description || 'Błąd aktualizacji');
                    if (btn) btn.disabled = false;
                }
            })
            .catch(err => {
                console.error(err);
                showSnack(false, 'Błąd komunikacji');
                if (btn) btn.disabled = false;
            });
    }

    function showAttachment(id) {
        api(`/ajax/franchise-complaints/attachment/${id}/presigned-url`, {
            method: 'GET'
        })
            .then(result => {
                if (result.ok && result.data?.url) {
                    const link = document.createElement('a');
                    link.href = result.data.url;
                    link.setAttribute('target', '_blank');
                    link.setAttribute('download', '');
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);

                    showSnack(true, result.description || 'Pobieranie rozpoczęte');
                } else {
                    showSnack(false, result.description || 'Błąd pobierania pliku');
                }
            })
            .catch(err => {
                console.error(err);
                showSnack(false, 'Błąd komunikacji');
            });
    }

    function getNewComplaints() {
        Swal.fire({
            title: 'Potwierdzenie',
            text: "Czy na pewno chcesz pobrać reklamacje z systemu franczyzowego?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Tak, pobierz',
            cancelButtonText: 'Anuluj'
        }).then((result) => {
            if (!result.isConfirmed) return;

            Swal.fire({
                title: 'Proszę czekać',
                text: 'Trwa pobieranie reklamacji...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            api(`/ajax/franchise-complaints/fetch-from-franchise`, { method: "GET" })
                .then(data => {
                    Swal.close();
                    if (data.ok) {
                        Swal.fire('Sukces', data.message || 'Reklamacje zostały pobrane.', 'success')
                            .then(() => location.reload());
                    } else {
                        Swal.fire('Błąd', data.message || 'Wystąpił błąd podczas pobierania reklamacji.', 'error');
                    }
                })
                .catch(error => {
                    Swal.close();
                    Swal.fire('Błąd', 'Wystąpił błąd podczas pobierania reklamacji.', 'error');
                    console.error('Error:', error);
                });
        });
    }
    function applyStatusFilter(status){
        const items = document.querySelectorAll('.complaint-item');
        let visible = 0;

        items.forEach(el => {
            const s = el.getAttribute('data-status') || '';
            const show = (status === 'ALL') || (s === status);

            el.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        const cnt = document.getElementById('complaints-visible-count');
        if (cnt) cnt.textContent = String(visible);

        document.querySelectorAll('#status-tabs [data-status]').forEach(tab => {
            const active = tab.dataset.status === status;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        // zapamiętaj wybór
        try { localStorage.setItem('complaints_status_filter', status); } catch (_) {}
    }

    (function initStatusFilter(){
        let saved = 'NEW';
        try { saved = localStorage.getItem('complaints_status_filter') || 'NEW'; } catch (_) {}
        applyStatusFilter(saved);
    })();

</script>

<?php $this->view("page_components/footer"); ?>
