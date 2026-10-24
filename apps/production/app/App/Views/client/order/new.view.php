<?php $this->view("page_components/header"); ?>
<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Nowe zamówienie</h3>
                <p class="text-subtitle text-muted">Utwórz nowe zamówienie dla klienta</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?=ROOT?>">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="<?=ROOT?>/client-orders">Zamówienia</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Nowe zamówienie
                        </li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>
    <section class="section">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Szczegóły zamówienia</h4>
            </div>
            <div class="card-body">

                <div class="row">
                    <div class="col-12">
                        <?php if(!empty($errors)):?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <strong>Błąd!</strong> <?=implode("<br>", $errors)?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                    <form method="POST" id="orderForm">
                        <!-- Client Selection or Info -->
                        <div class="row mb-4">
                            <?php if(!empty($client)): ?>
                                <!-- Pre-selected client (from URL) -->
                                <div class="col-md-4">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h6 class="text-muted mb-2"><i class="bi bi-person"></i> Klient</h6>
                                            <h5 class="mb-0"><?=$client['company']?></h5>
                                            <small class="text-muted">ID: <?=$client['id']?></small>
                                            <input type="hidden" name="id_customer" value="<?=$client['id']?>">
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Client selection dropdown -->
                                <div class="col-md-4">
                                    <label class="form-label fw-bold" for="id_customer">
                                        <i class="bi bi-person"></i> Klient *
                                    </label>
                                    <select class="form-select" name="id_customer" id="id_customer" required onchange="handleClientChange(this.value)">
                                        <?php if(!empty($customers)): ?>
                                            <?php foreach($customers as $key => $customer): ?>
                                                <option value="<?=$customer['id']?>" <?=($key === 0 || old_select('id_customer', $customer['id'])) ? 'selected' : ''?>>
                                                    <?=$customer['company']?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle"></i> Wybierz klienta z listy
                                    </small>
                                </div>
                            <?php endif; ?>
                            <div class="col-md-4">
                                <label class="form-label fw-bold" for="expected_shipping_date">
                                    <i class="bi bi-calendar-event"></i> Przewidywana data wysyłki *
                                </label>
                                <input type="date" class="form-control" name="expected_shipping_date"
                                       id="expected_shipping_date"
                                       value="<?=old_value('expected_shipping_date')?>"
                                       required>
                            </div>
                            <div class="col-md-4">
                                <?php if(!empty($client_type) && $client_type === 'franchisee'): ?>
                                <label class="form-label fw-bold" for="order_type">
                                    <i class="bi bi-tag"></i> Typ zamówienia
                                </label>
                                <select class="form-select" name="order_type" id="order_type">
                                    <option value="SUPPLEMENT" <?=old_select('order_type','SUPPLEMENT') ? 'selected':''?>>Domówienie</option>
                                    <option value="STANDARD" <?=old_select('order_type','STANDARD') ? 'selected':''?>>Standardowe</option>
                                </select>
                                <?php else: ?>
                                <input type="hidden" name="order_type" value="STANDARD">
                                <?php endif; ?>
                            </div>
                            </div>
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <label class="form-label fw-bold" for="order_description">
                                    <i class="bi bi-chat-left-text"></i> Komentarz do zamówienia
                                </label>
                                <textarea class="form-control" name="order_description" id="order_description"
                                          rows="3" placeholder="Dodaj dodatkowe informacje..."><?=old_value('order_description')?></textarea>
                            </div>
                            <div class="col-md-4 d-flex align-items-center mt-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           form="orderForm" name="is_freebie" id="is_freebie" value="1"
                                           <?= old_checkbox('is_freebie', 1, 0) ?>>
                                    <label class="form-check-label fw-bold" for="is_freebie">
                                        <span class="badge bg-success fs-6">GRATIS</span>
                                        <div class="small text-muted mt-1">Zamówienie oznaczone jako gratis zostanie pominięte w dokumencie różnic przy generowaniu wysyłki.</div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Products Section -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <div class="card border">
                                    <div class="card-header bg-success text-white">
                                        <h5 class="mb-0"><i class="bi bi-box-seam"></i> Produkty w zamówieniu</h5>
                                    </div>
                                    <div class="card-body">
                                        <div id="clientSelectInfo" class="alert alert-info" style="display: none;">
                                            <i class="bi bi-info-circle"></i> <strong>Wybierz klienta z listy</strong>, aby zobaczyć dostępne produkty.
                                        </div>

                                        <div id="productsSection" style="display: none;">
                                            <div class="table-responsive">
                                                <table class="table table-hover" id="productsTable">
                                                    <thead class="table-light">
                                                    <tr>
                                                        <th style="width: 10%">SKU</th>
                                                        <th style="width: 40%">Nazwa produktu</th>
                                                        <th style="width: 15%">Ilość (karton)</th>
                                                        <th style="width: 15%">Cena netto</th>
                                                        <th style="width: 15%">Wartość</th>
                                                        <th style="width: 5%" class="text-center">Akcja</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody id="productsList">

                                                    <?php if(!empty($old_products)): ?>
                                                        <?php foreach($old_products as $index => $old_product): ?>
                                                            <tr data-product-row>
                                                                <td class="align-middle">
                                                                    <code><?=$old_product['id_product']?></code>
                                                                </td>
                                                                <td class="align-middle">
                                                                    <strong><?=$old_product['product_name']?></strong>
                                                                </td>
                                                                <td>
                                                                    <input type="hidden" form="orderForm" name="products[<?=$index?>][id_product]" value="<?=$old_product['id_product']?>">
                                                                    <input type="number" form="orderForm" class="form-control quantity-input"
                                                                           name="products[<?=$index?>][quantity]"
                                                                           value="<?=$old_product['quantity_carton']?>"
                                                                           min="1" step="1" required
                                                                           data-price="<?=$old_product['price_net']?>">
                                                                </td>
                                                                <td class="align-middle">
                                                                    <span class="text-muted"><?=number_format($old_product['price_net'], 2, ',', ' ')?> EUR</span>
                                                                    <input type="hidden" form="orderForm" name="products[<?=$index?>][price]"
                                                                           value="<?=$old_product['price_net']?>">
                                                                </td>
                                                                <td class="align-middle">
                                                                    <strong class="line-total"><?=number_format($old_product['price_net'] * $old_product['quantity_carton'], 2, ',', ' ')?> EUR</strong>
                                                                </td>
                                                                <td class="text-center align-middle">
                                                                    <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)" title="Usuń produkt">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                    </tbody>
                                                    <tfoot class="table-light">
                                                    <tr>
                                                        <td colspan="4" class="text-end"><strong>SUMA NETTO:</strong></td>
                                                        <td colspan="2"><h5 class="mb-0 text-success" id="orderTotal">0,00 EUR</h5></td>
                                                    </tr>
                                                    </tfoot>
                                                </table>
                                            </div>

                                            <!-- Add Product Section -->
                                            <div class="card bg-light mt-3">
                                                <div class="card-body">
                                                    <h6 class="card-title">
                                                        <i class="bi bi-search"></i> Wyszukaj i dodaj produkt do zamówienia
                                                    </h6>
                                                    <div class="row align-items-end">
                                                        <div class="col-md-5">
                                                            <label class="form-label fw-bold">Wyszukaj produkt</label>
                                                            <div class="product-search-wrapper position-relative">
                                                                <input type="text"
                                                                       class="form-control"
                                                                       id="productSearchInput"
                                                                       placeholder="🔍 Wpisz SKU lub nazwę produktu..."
                                                                       autocomplete="off">
                                                                <div id="searchResults" class="search-results-dropdown"></div>
                                                                <small class="text-muted">
                                                                    <i class="bi bi-info-circle"></i>
                                                                    Zacznij pisać aby zobaczyć wyniki
                                                                </small>
                                                                <!-- Hidden data for products -->
                                                                <div id="productDataContainer" style="display: none;">
                                                                    <?php if(!empty($price_list)): ?>
                                                                        <?php foreach ($price_list as $item): ?>
                                                                            <?php
                                                                            $priceNet = is_object($item) ? $item->getPriceNet() : $item['price_net'];
                                                                            $idProduct = is_object($item) ? $item->getIdProduct() : $item['id_product'];
                                                                            $sku = is_object($item) ? $item->getSku() : $item['sku'];
                                                                            $productName = is_object($item) ? $item->getProductName() : $item['product_name'];
                                                                            $packageSize = is_object($item) ? $item->getPackageSize() : $item['package_size'];
                                                                            $packageUnit = is_object($item) ? $item->getPackageUnit() : $item['package_unit'];
                                                                            $isActive = is_object($item) ? $item->getIsActive() : $item['is_active'];
                                                                            ?>
                                                                            <div class="product-data-item"
                                                                                 data-id="<?= $idProduct ?>"
                                                                                 data-sku="<?= htmlspecialchars($sku) ?>"
                                                                                 data-name="<?= htmlspecialchars($productName) ?>"
                                                                                 data-price="<?= $priceNet ?>"
                                                                                 data-package="<?= $packageSize ?> <?= $packageUnit ?>"
                                                                                 data-active="<?= $isActive ?>">
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label fw-bold">Ilość</label>
                                                            <input type="number" placeholder="1" class="form-control"
                                                                   id="quantityInput" min="1" step="1" value="1">
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label fw-bold">Cena jednostkowa</label>
                                                            <div class="input-group">
                                                                <input type="text" placeholder="0,00" id="priceDisplay"
                                                                       class="form-control" readonly>
                                                                <span class="input-group-text">EUR</span>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <button class="btn btn-success w-100" type="button"
                                                                    onclick="addProductToOrder()" id="addProductBtn">
                                                                <i class="bi bi-plus-circle"></i> Dodaj
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <!-- Selected Product Display -->
                                                    <div id="selectedProductInfo" class="mt-3" style="display: none;">
                                                        <div class="alert alert-info mb-0">
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <div>
                                                                    <strong>Wybrany produkt:</strong>
                                                                    <span id="selectedProductDisplay"></span>
                                                                </div>
                                                                <button type="button" class="btn-close btn-sm" onclick="clearProductSelection()"></button>
                                                            </div>
                                                        </div>
                                                    </div><!-- End productsSection -->
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="row">
                            <div class="col-12">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bi bi-check-circle"></i> Utwórz zamówienie
                                </button>
                                <a href="<?=ROOT?>/client-orders" role="button" class="btn btn-secondary btn-lg">
                                    <i class="bi bi-arrow-left"></i> Anuluj
                                </a>
                            </div>
                        </div>

                    </form>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<script>
    // Price list data for quick access
    const priceListData = {};
    let selectedProduct = null;
    let clientSelected = false;

    // Initialize price list data from hidden container
    document.addEventListener('DOMContentLoaded', function() {
        // Load product data from hidden container
        const productDataItems = document.querySelectorAll('.product-data-item');
        productDataItems.forEach(item => {
            const id = item.dataset.id;
            priceListData[id] = {
                id: id,
                sku: item.dataset.sku,
                name: item.dataset.name,
                price: parseFloat(item.dataset.price || 0),
                package: item.dataset.package,
                active: item.dataset.active === '1'
            };
        });

        console.log('Loaded products:', Object.keys(priceListData).length);

        // Initialize search functionality
        initProductSearch();

        // Initialize quantity inputs for real-time calculation
        document.querySelectorAll('.quantity-input').forEach(input => {
            input.addEventListener('input', function() {
                updateLineTotal(this);
                calculateOrderTotal();
            });
        });

        // Initial calculation
        calculateOrderTotal();

        // Check if we have products loaded (price_list not empty)
        const hasProducts = Object.keys(priceListData).length > 0;

        // Check if client is pre-selected
        const hiddenClientInput = document.querySelector('input[name="id_customer"][type="hidden"]');
        const clientSelect = document.getElementById("id_customer");

        if (hiddenClientInput && hiddenClientInput.value) {
            // Scenario 1: Pre-selected client from URL
            clientSelected = true;
            showProductsSection();
        } else if (clientSelect && clientSelect.value && hasProducts) {
            // Scenario 2: Client selected from dropdown with products loaded
            clientSelected = true;
            showProductsSection();
        } else if (!hasProducts) {
            // No products loaded yet - hide section
            hideProductsSection();
        }
    });


    /**
     * Handle client selection change
     */
    function handleClientChange(clientId) {
        if (!clientId) {
            clientSelected = false;
            hideProductsSection();
            return;
        }

        clientSelected = true;
        showProductsSection();

        showNotification("Klient wybrany. Możesz teraz dodawać produkty.", "info");
    }

    /**
     * Show products section
     */
    function showProductsSection() {
        document.getElementById("clientSelectInfo").style.display = "none";
        document.getElementById("productsSection").style.display = "block";
    }

    /**
     * Hide products section
     */
    function hideProductsSection() {
        document.getElementById("clientSelectInfo").style.display = "block";
        document.getElementById("productsSection").style.display = "none";

        const productsList = document.getElementById("productsList");
        if (productsList) {
            productsList.innerHTML = "";
        }
        calculateOrderTotal();
    }
    /**
     * Initialize product search with autocomplete
     */
    function initProductSearch() {
        const searchInput = document.getElementById('productSearchInput');
        const searchResults = document.getElementById('searchResults');

        if (!searchInput || !searchResults) return;

        let searchTimeout;

        // Search on input
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();

            if (query.length < 2) {
                searchResults.innerHTML = '';
                searchResults.classList.remove('show');
                return;
            }

            // Debounce search
            searchTimeout = setTimeout(() => {
                performSearch(query);
            }, 300);
        });

        // Handle click outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.remove('show');
            }
        });

        // Handle keyboard navigation
        searchInput.addEventListener('keydown', function(e) {
            const items = searchResults.querySelectorAll('.search-result-item');
            const activeItem = searchResults.querySelector('.search-result-item.active');
            let currentIndex = Array.from(items).indexOf(activeItem);

            switch(e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (currentIndex < items.length - 1) {
                        if (activeItem) activeItem.classList.remove('active');
                        items[currentIndex + 1].classList.add('active');
                        items[currentIndex + 1].scrollIntoView({ block: 'nearest' });
                    }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (currentIndex > 0) {
                        if (activeItem) activeItem.classList.remove('active');
                        items[currentIndex - 1].classList.add('active');
                        items[currentIndex - 1].scrollIntoView({ block: 'nearest' });
                    }
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (activeItem) {
                        activeItem.click();
                    }
                    break;
                case 'Escape':
                    searchResults.classList.remove('show');
                    break;
            }
        });

        // Focus on search on Ctrl+K
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        });
    }

    /**
     * Perform product search
     */
    function performSearch(query) {
        const searchResults = document.getElementById('searchResults');
        const queryLower = query.toLowerCase();

        // Filter products
        const results = Object.values(priceListData).filter(product => {
            if (!product.active) return false;

            const skuMatch = product.sku.toLowerCase().includes(queryLower);
            const nameMatch = product.name.toLowerCase().includes(queryLower);

            return skuMatch || nameMatch;
        });

        // Sort results - SKU matches first
        results.sort((a, b) => {
            const aSkuMatch = a.sku.toLowerCase().startsWith(queryLower);
            const bSkuMatch = b.sku.toLowerCase().startsWith(queryLower);

            if (aSkuMatch && !bSkuMatch) return -1;
            if (!aSkuMatch && bSkuMatch) return 1;

            return a.sku.localeCompare(b.sku);
        });

        // Display results
        if (results.length === 0) {
            searchResults.innerHTML = `
                <div class="search-result-empty">
                    <i class="bi bi-search"></i>
                    <p class="mb-0">Nie znaleziono produktów dla: <strong>${escapeHtml(query)}</strong></p>
                </div>
            `;
        } else {
            const maxResults = 10;
            const displayResults = results.slice(0, maxResults);

            searchResults.innerHTML = displayResults.map((product, index) => `
                <div class="search-result-item ${index === 0 ? 'active' : ''}"
                     onclick="selectProduct('${product.id}')"
                     data-product-id="${product.id}">
                    <div class="search-result-sku">
                        <code>${highlightMatch(product.sku, query)}</code>
                    </div>
                    <div class="search-result-name">
                        ${highlightMatch(product.name, query)}
                    </div>
                    <div class="search-result-meta">
                        <span class="search-result-price">
                            ${formatPrice(product.price)} EUR
                        </span>
                        ${product.package ? `
                            <span class="search-result-package">
                                <i class="bi bi-box"></i> ${product.package}
                            </span>
                        ` : ''}
                    </div>
                </div>
            `).join('');

            if (results.length > maxResults) {
                searchResults.innerHTML += `
                    <div class="search-result-more">
                        Pokazano ${maxResults} z ${results.length} wyników. Sprecyzuj wyszukiwanie.
                    </div>
                `;
            }
        }

        searchResults.classList.add('show');
    }

    /**
     * Highlight matching text
     */
    function highlightMatch(text, query) {
        const regex = new RegExp(`(${escapeRegExp(query)})`, 'gi');
        return escapeHtml(text).replace(regex, '<mark>$1</mark>');
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Escape RegExp
     */
    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Select product from search results
     */
    function selectProduct(productId) {
        const product = priceListData[productId];
        if (!product) return;

        selectedProduct = product;

        // Update display
        const searchInput = document.getElementById('productSearchInput');
        const priceDisplay = document.getElementById('priceDisplay');
        const quantityInput = document.getElementById('quantityInput');
        const searchResults = document.getElementById('searchResults');
        const selectedInfo = document.getElementById('selectedProductInfo');
        const selectedDisplay = document.getElementById('selectedProductDisplay');

        searchInput.value = `${product.sku} - ${product.name}`;
        priceDisplay.value = formatPrice(product.price);
        quantityInput.value = quantityInput.value || 1;
        quantityInput.focus();

        // Show selected product info
        selectedDisplay.innerHTML = `
            <code>${product.sku}</code> ${product.name}
            <span class="badge bg-success">${formatPrice(product.price)} EUR</span>
            ${product.package ? `<small class="text-muted">(${product.package})</small>` : ''}
        `;
        selectedInfo.style.display = 'block';

        // Hide search results
        searchResults.classList.remove('show');
    }

    /**
     * Clear product selection
     */
    function clearProductSelection() {
        selectedProduct = null;
        document.getElementById('productSearchInput').value = '';
        document.getElementById('priceDisplay').value = '';
        document.getElementById('quantityInput').value = '1';
        document.getElementById('selectedProductInfo').style.display = 'none';
        document.getElementById('productSearchInput').focus();
    }

    /**
     * Update price display (legacy - now handled by search)
     */
    function updatePriceDisplay() {
        // Not used with search, but kept for compatibility
    }

    /**
     * Add selected product to order
     */
    function addProductToOrder() {
        const quantityInput = document.getElementById('quantityInput');
        const productsList = document.getElementById('productsList');

        if (!productsList) {
            console.error('Products list not found');
            return;
        }

        const quantity = parseInt(quantityInput.value);

        // Validation
        if (!selectedProduct) {
            showNotification('Proszę wyszukać i wybrać produkt', 'warning');
            document.getElementById('productSearchInput').focus();
            return;
        }

        if (!quantity || quantity <= 0) {
            showNotification('Proszę wprowadzić poprawną ilość', 'warning');
            quantityInput.classList.add('is-invalid');
            quantityInput.focus();
            return;
        }

        quantityInput.classList.remove('is-invalid');

        // Check if product already exists
        const existingRow = Array.from(productsList.querySelectorAll('[data-product-row]'))
            .find(row => {
                const idInput = row.querySelector('input[name$="[id_product]"]');
                return idInput && idInput.value == selectedProduct.id;
            });

        if (existingRow) {
            // Ask if user wants to update quantity
            if (confirm(`Produkt "${selectedProduct.name}" jest już w zamówieniu. Czy chcesz zaktualizować ilość?`)) {
                const existingInput = existingRow.querySelector('.quantity-input');
                const currentQty = parseInt(existingInput.value) || 0;
                existingInput.value = currentQty + quantity;
                updateLineTotal(existingInput);
                calculateOrderTotal();
                showNotification('Ilość produktu została zaktualizowana', 'success');
                clearProductSelection();
            }
            return;
        }

        const price = selectedProduct.price;
        const lineTotal = price * quantity;

        // Get current row index for proper array indexing
        const currentRowCount = productsList.querySelectorAll('[data-product-row]').length;

        // Create new row
        const newRow = document.createElement('tr');
        newRow.setAttribute('data-product-row', '');
        newRow.innerHTML = `
            <td class="align-middle">
                <code>${selectedProduct.sku}</code>
            </td>
            <td class="align-middle">
                <strong>${selectedProduct.name}</strong>
                ${selectedProduct.package ? `<br><small class="text-muted">${selectedProduct.package}</small>` : ''}
            </td>
            <td>
                <input type="hidden" form="orderForm" name="products[${currentRowCount}][id_product]" value="${selectedProduct.id}">
                <input type="number" form="orderForm" class="form-control quantity-input"
                       name="products[${currentRowCount}][quantity]"
                       value="${quantity}"
                       min="1" step="1" required
                       data-price="${price}">
            </td>
            <td class="align-middle">
                <span class="text-muted">${formatPrice(price)} EUR</span>
                <input type="hidden" form="orderForm" name="products[${currentRowCount}][price]" value="${price}">
            </td>
            <td class="align-middle">
                <strong class="line-total">${formatPrice(lineTotal)} EUR</strong>
            </td>
            <td class="text-center align-middle">
                <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)" title="Usuń produkt">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;

        // Add event listener to new quantity input
        const newQuantityInput = newRow.querySelector('.quantity-input');
        newQuantityInput.addEventListener('input', function() {
            updateLineTotal(this);
            calculateOrderTotal();
        });

        productsList.appendChild(newRow);

        // Recalculate total
        calculateOrderTotal();

        showNotification(`Dodano: ${selectedProduct.name} (${quantity} szt.)`, 'success');

        // Clear selection
        clearProductSelection();
    }

    // ...existing code for other functions...


    /**
     * Remove product row from order
     */
    function removeRow(btn) {
        const row = btn.closest('[data-product-row]');
        if (row) {
            const productName = row.querySelector('td:nth-child(2) strong')?.textContent || 'Produkt';

            if (confirm(`Czy na pewno chcesz usunąć "${productName}" z zamówienia?`)) {
                row.remove();

                // Reindex all products after removal
                reindexProducts();

                calculateOrderTotal();
                showNotification('Produkt usunięty z zamówienia', 'info');
            }
        }
    }

    /**
     * Reindex products array after add/remove operations
     */
    function reindexProducts() {
        const productsList = document.getElementById('productsList');
        const rows = productsList.querySelectorAll('[data-product-row]');

        rows.forEach((row, index) => {
            // Update all inputs with new index
            const idInput = row.querySelector('input[name$="[id_product]"]');
            const quantityInput = row.querySelector('input[name$="[quantity]"]');
            const priceInput = row.querySelector('input[name$="[price]"]');

            if (idInput) idInput.name = `products[${index}][id_product]`;
            if (quantityInput) quantityInput.name = `products[${index}][quantity]`;
            if (priceInput) priceInput.name = `products[${index}][price]`;
        });
    }

    /**
     * Update line total for a specific product
     */
    function updateLineTotal(quantityInput) {
        const row = quantityInput.closest('[data-product-row]');
        if (!row) return;

        const quantity = parseFloat(quantityInput.value) || 0;
        const price = parseFloat(quantityInput.dataset.price) || 0;
        const lineTotal = quantity * price;

        const lineTotalElement = row.querySelector('.line-total');
        if (lineTotalElement) {
            lineTotalElement.textContent = formatPrice(lineTotal) + ' EUR';
        }

        // Validate quantity
        if (quantity <= 0) {
            quantityInput.classList.add('is-invalid');
        } else {
            quantityInput.classList.remove('is-invalid');
        }
    }

    /**
     * Calculate and display order total
     */
    function calculateOrderTotal() {
        let total = 0;

        document.querySelectorAll('[data-product-row]').forEach(row => {
            const quantityInput = row.querySelector('.quantity-input');
            if (quantityInput) {
                const quantity = parseFloat(quantityInput.value) || 0;
                const price = parseFloat(quantityInput.dataset.price) || 0;
                total += quantity * price;
            }
        });

        const orderTotalElement = document.getElementById('orderTotal');
        if (orderTotalElement) {
            orderTotalElement.textContent = formatPrice(total) + ' EUR';

            // Add visual feedback for non-zero total
            if (total > 0) {
                orderTotalElement.classList.add('fw-bold');
            }
        }

        return total;
    }

    /**
     * Format price with Polish locale
     */
    function formatPrice(value) {
        return parseFloat(value).toLocaleString('pl-PL', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /**
     * Show notification to user
     */
    function showNotification(message, type = 'info') {
        // Check if there's a notification container
        let container = document.getElementById('notificationContainer');

        if (!container) {
            container = document.createElement('div');
            container.id = 'notificationContainer';
            container.style.position = 'fixed';
            container.style.top = '20px';
            container.style.right = '20px';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
        }

        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.role = 'alert';
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;

        container.appendChild(alert);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            alert.classList.remove('show');
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    }

    /**
     * Form validation before submit
     */
    document.getElementById('orderForm')?.addEventListener('submit', function(e) {
        const productRows = document.querySelectorAll('[data-product-row]');

        if (productRows.length === 0) {
            e.preventDefault();
            showNotification('Zamówienie musi zawierać przynajmniej jeden produkt', 'danger');
            return false;
        }

        // Validate all quantities
        let hasInvalidQuantity = false;
        document.querySelectorAll('.quantity-input').forEach(input => {
            const quantity = parseFloat(input.value);
            if (!quantity || quantity <= 0) {
                input.classList.add('is-invalid');
                hasInvalidQuantity = true;
            }
        });

        if (hasInvalidQuantity) {
            e.preventDefault();
            showNotification('Proszę wprowadzić poprawne ilości dla wszystkich produktów', 'danger');
            return false;
        }

        return true;
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + Enter to add product
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            const addBtn = document.getElementById('addProductBtn');
            if (addBtn && document.activeElement.closest('.card-body')) {
                e.preventDefault();
                addProductToOrder();
            }
        }
    });

</script>

<style>
    /* Custom styles for better UX */
    .quantity-input.is-invalid {
        border-color: #dc3545;
    }

    [data-product-row]:hover {
        background-color: #f8f9fa;
    }

    .line-total {
        color: #198754;
        font-size: 1.05em;
    }

    #orderTotal {
        font-size: 1.5rem;
        color: #198754;
    }

    .card-header.bg-success {
        background-color: #198754 !important;
    }

    .form-label.fw-bold {
        font-weight: 600 !important;
        margin-bottom: 0.5rem;
    }

    .alert {
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    }

    code {
        background-color: #e9ecef;
        padding: 0.2rem 0.4rem;
        border-radius: 0.25rem;
        font-size: 0.875em;
    }

    /* Product Search Styles */
    .product-search-wrapper {
        position: relative;
    }

    #productSearchInput {
        padding-right: 2.5rem;
        font-size: 1rem;
    }

    #productSearchInput:focus {
        border-color: #198754;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    }

    .search-results-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
        max-height: 400px;
        overflow-y: auto;
        z-index: 1000;
        margin-top: 0.25rem;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        display: none;
    }

    .search-results-dropdown.show {
        display: block;
    }

    .search-result-item {
        padding: 0.75rem 1rem;
        cursor: pointer;
        border-bottom: 1px solid #f0f0f0;
        transition: background-color 0.15s ease-in-out;
    }

    .search-result-item:last-child {
        border-bottom: none;
    }

    .search-result-item:hover,
    .search-result-item.active {
        background-color: #e7f1ff;
    }

    .search-result-sku {
        margin-bottom: 0.25rem;
    }

    .search-result-sku code {
        font-size: 0.85rem;
        background-color: #198754;
        color: white;
        padding: 0.2rem 0.5rem;
    }

    .search-result-name {
        font-weight: 500;
        color: #212529;
        margin-bottom: 0.25rem;
    }

    .search-result-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.875rem;
        color: #6c757d;
    }

    .search-result-price {
        font-weight: 600;
        color: #198754;
    }

    .search-result-package {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }

    .search-result-empty {
        padding: 2rem 1rem;
        text-align: center;
        color: #6c757d;
    }

    .search-result-empty i {
        font-size: 2rem;
        margin-bottom: 0.5rem;
        opacity: 0.5;
    }

    .search-result-more {
        padding: 0.5rem 1rem;
        text-align: center;
        font-size: 0.875rem;
        color: #6c757d;
        background-color: #f8f9fa;
        border-top: 1px solid #dee2e6;
    }

    .search-result-item mark {
        background-color: #fff3cd;
        padding: 0.1rem 0.2rem;
        border-radius: 0.2rem;
        font-weight: 600;
    }

    #selectedProductInfo {
        animation: slideDown 0.3s ease-out;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Loading state */
    .search-loading {
        padding: 1rem;
        text-align: center;
        color: #6c757d;
    }

    .search-loading::after {
        content: "⏳";
        animation: spin 1s linear infinite;
        display: inline-block;
        margin-left: 0.5rem;
    }

    @keyframes spin {
        100% { transform: rotate(360deg); }
    }

    /* Scrollbar styles */
    .search-results-dropdown::-webkit-scrollbar {
        width: 8px;
    }

    .search-results-dropdown::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 0.375rem;
    }

    .search-results-dropdown::-webkit-scrollbar-thumb {
        background: #888;
        border-radius: 0.375rem;
    }

    .search-results-dropdown::-webkit-scrollbar-thumb:hover {
        background: #555;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .search-results-dropdown {
            max-height: 300px;
        }

        .search-result-item {
            padding: 0.5rem 0.75rem;
        }
    }

    /* Keyboard shortcut hint */
    .product-search-wrapper small {
        display: block;
        margin-top: 0.25rem;
    }

    /* Focus ring for accessibility */
    #productSearchInput:focus-visible {
        outline: 2px solid #198754;
        outline-offset: 2px;
    }
</style>


