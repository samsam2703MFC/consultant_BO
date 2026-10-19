<nav class="navbar navbar-expand navbar-light ">
    <div class="container-fluid">
        <a href="#" class="burger-btn d-block">
            <i class="bi bi-justify fs-3"></i>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
                aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">

            <div class="navbar-center flex-grow-1 d-flex justify-content-center">

                <div class="quick-tags d-flex align-items-center">
                    <a href="<?=ROOT?>/shipping-documents/create" class="badge bg-primary me-2 py-2 px-3">
                        <i class="icon bi bi-truck"></i> Wysyłka
                    </a>
                    <a href="<?=ROOT?>/production/planning" class="badge bg-secondary me-2 py-2 px-3">
                        <i class="icon dripicons-document"></i> Listy produkcyjne
                    </a>
                    <a href="<?=ROOT?>/client-orders" class="badge bg-info me-2 py-2 px-3">
                        <i class="icon dripicons-user-group"></i> Zamówienia
                    </a>
                    <a href="<?=ROOT?>/franchise-complaints" class="badge bg-warning me-2 py-2 px-3">
                        <i class="icon dripicons-warning"></i> Reklamacje
                    </a>
                </div>
            </div>


            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">

                <li class="nav-item dropdown me-3">
                    <a class="nav-link active dropdown-toggle" href="#" data-bs-toggle="dropdown"
                       aria-expanded="false">
                        <i class='bi bi-bell bi-sub fs-4 text-gray-600'></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="dropdownMenuButton">
                        <li>
                            <h6 class="dropdown-header">Notifications</h6>
                        </li>
                        <li><a class="dropdown-item">No notification available</a></li>
                    </ul>
                </li>
            </ul>
            <div class="dropdown">
                <a href="#" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-menu d-flex">
                        <div class="user-name text-end me-3">
                            <h6 class="mb-0 text-gray-600"><?=user('displayed_name')?></h6>
                            <p class="mb-0 text-sm text-gray-600">Administrator</p>
                        </div>
                        <div class="user-img d-flex align-items-center">
                            <div class="avatar avatar-md">
                                <img src="assets/images/faces/1.jpg">
                            </div>
                        </div>
                    </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="dropdownMenuButton">
                    <li>
                        <h6 class="dropdown-header">Witaj, <?=user('name')?>!</h6>
                    </li>
                    <li><a class="dropdown-item" href="#"><i class="icon-mid bi bi-person me-2"></i>
                            Mój profil</a></li>
                    <li><a class="dropdown-item" href="#"><i class="icon-mid bi bi-gear me-2"></i>
                            Ustawienia</a></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li><a class="dropdown-item" href="<?=ROOT?>/logout"><i
                                class="icon-mid bi bi-box-arrow-left me-2"></i>Wyloguj</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>