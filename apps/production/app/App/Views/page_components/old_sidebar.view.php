    <div id="sidebar" class="active">
        <div class="sidebar-wrapper active">
            <div class="sidebar-header">
                <div class="d-flex justify-content-between">
                    <div class="logo">
                        <a href="index.html"><img src="assets/images/logo/logo.png" alt="Logo" srcset=""></a>
                    </div>
                    <div class="toggler">
                        <a href="#" class="sidebar-hide d-xl-none d-block"><i class="bi bi-x bi-middle"></i></a>
                    </div>
                </div>
            </div>
            <div class="sidebar-menu">
                <ul class="menu">

<!---->
<!--                    <li class="sidebar-item">-->
<!--                        <a href="--><?//=ROOT?><!--/Production" class='sidebar-link'>-->
<!--                            <i class="bi bi-grid-fill"></i>-->
<!--                            <span>Produkcja</span>-->
<!--                        </a>-->
<!--                    </li>-->
<!---->
<!---->
<!--                    <li class="sidebar-item">-->
<!--                        <a href="--><?//=ROOT?><!--/HR" class='sidebar-link'>-->
<!--                            <i class="bi bi-grid-fill"></i>-->
<!--                            <span>HR</span>-->
<!--                        </a>-->
<!--                    </li>-->
<!---->
<!--                    <li class="sidebar-item">-->
<!--                        <a href="--><?//=ROOT?><!--/Sales" class='sidebar-link'>-->
<!--                            <i class="bi bi-grid-fill"></i>-->
<!--                            <span>Sprzedaż</span>-->
<!--                        </a>-->
<!--                    </li>-->
<!---->
<!--                    <li class="sidebar-item">-->
<!--                        <a href="--><?//=ROOT?><!--/Warehouse" class='sidebar-link'>-->
<!--                            <i class="bi bi-grid-fill"></i>-->
<!--                            <span>Gospodarka magazynowa</span>-->
<!--                        </a>-->
<!--                    </li>-->
<!---->
<!--                    <li class="sidebar-item">-->
<!--                        <a href="--><?//=ROOT?><!--/Accounting" class='sidebar-link'>-->
<!--                            <i class="bi bi-grid-fill"></i>-->
<!--                            <span>Księgowość i finanse</span>-->
<!--                        </a>-->
<!--                    </li>-->
<!--                    -->



                    <li class="sidebar-title">Menu</li>

                    <li class="sidebar-item  ">
                        <a href="<?=ROOT?>/dashboard" class='sidebar-link'>
                            <i class="bi bi-grid-fill"></i>
                            <span>Panel główny</span>
                        </a>
                    </li>

                    <li class="sidebar-item  has-sub">
                        <a href="#" class='sidebar-link'>
                            <i class="bi bi-pentagon-fill"></i>
                            <span>Produkt</span>
                        </a>

                        <ul class="submenu ">

                            <li class="sidebar-item  has-sub">
                                <a href="#" class='sidebar-link'>
                                    <i class="bi bi-pentagon-fill"></i>
                                    <span>Produkt gotowy</span>
                                </a>
                                <ul class="submenu ">
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/product-categories">Zarządzaj</a>
                                    </li>
                                </ul>
                            </li>

                            <li class="sidebar-item  has-sub">
                                <a href="#" class='sidebar-link'>
                                    <i class="bi bi-pentagon-fill"></i>
                                    <span>Receptura</span>
                                </a>
                                <ul class="submenu ">
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/compositions">Kompozycja</a>
                                    </li>
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/semi-products">Półprodukt</a>
                                    </li>
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/recipes">Produkt gotowy</a>
                                    </li>
                                </ul>
                            </li>

                            <li class="sidebar-item  has-sub">
                                <a href="#" class='sidebar-link'>
                                    <i class="bi bi-pentagon-fill"></i>
                                    <span>Produkt testowy</span>
                                </a>
                                <ul class="submenu ">
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/test-products">Zarządzaj</a>
                                    </li>
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/TestProduct/products/pdf" disabled>Drukuj dokumenty</a>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </li>

                    <li class="sidebar-item  has-sub">
                        <a href="#" class='sidebar-link'>
                            <i class="bi bi-pentagon-fill"></i>
                            <span>Magazyn</span>
                        </a>

                        <ul class="submenu ">
                            <li class="sidebar-item  has-sub">
                                <a href="#" class='sidebar-link'>
                                    <i class="bi bi-pentagon-fill"></i>
                                    <span>Produkt gotowy</span>
                                </a>
                                <ul class="submenu ">
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/products/stock">Stan magazynowy</a>
                                    </li>
                                </ul>
                            </li>
                            <li class="sidebar-item  has-sub">
                                <a href="#" class='sidebar-link'>
                                    <i class="bi bi-pentagon-fill"></i>
                                    <span>Surowiec</span>
                                </a>
                                <ul class="submenu ">
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/material-categories">Zarządzaj</a>
                                    </li>
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/materials/prices">Ceny</a>
                                        <?php //TODO: Dodać możliwość wprowadzania surowca z dostawy oraz zmiany jego ceny ?>
                                    </li>

                                    <li class="sidebar-item  has-sub">
                                        <a href="#" class='sidebar-link'>
                                            <i class="bi bi-pentagon-fill"></i>
                                            <span>Zakup</span>
                                        </a>
                                        <ul class="submenu ">
                                            <li class="submenu-item ">
                                                <a href="<?=ROOT?>/purchases">Przeglądaj</a>
                                            </li>
                                        </ul>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </li>

                    <li class="sidebar-item  has-sub">
                        <a href="#" class='sidebar-link'>
                            <i class="bi bi-pentagon-fill"></i>
                            <span>Produkcja</span>
                        </a>
                        <ul class="submenu ">
                            <li class="submenu-item ">
                                <a href="<?=ROOT?>/production/planning">Lista produkcyjna</a>
                            </li>
                        </ul>
                    </li>


                    <li class="sidebar-item  has-sub">
                        <a href="#" class='sidebar-link'>
                            <i class="bi bi-pentagon-fill"></i>
                            <span>Zamówienia</span>
                        </a>
                        <ul class="submenu ">
                            <li class="submenu-item">
                                <a href="<?=ROOT?>/client-orders">Zarządzaj</a>
                            </li>
                            <li class="sidebar-item  has-sub">
                                <a href="#" class='sidebar-link'>
                                    <i class="bi bi-pentagon-fill"></i>
                                    <span>Zwroty</span>
                                </a>
                                <ul class="submenu ">

                                    <li class="submenu-item">
                                        <a href="<?=ROOT?>/client-complaints/pending-products">Oczekujące produkty</a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?=ROOT?>/client-complaints/pending-shipment">Oczekujące zwroty</a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?=ROOT?>/client-complaints/picking-list">Listy kompletacyjne</a>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </li>

                    <li class="sidebar-item  has-sub">
                        <a href="#" class='sidebar-link'>
                            <i class="bi bi-pentagon-fill"></i>
                            <span>Dokumenty</span>
                        </a>

                        <ul class="submenu ">
                            <li class="sidebar-item  has-sub">
                                <a href="#" class='sidebar-link'>
                                    <i class="bi bi-pentagon-fill"></i>
                                    <span>Wysyłka</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/shipping-documents/create">WZ + CMR</a>
                                    </li>
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/shipments">Wygenerowane</a>
                                    </li>
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/proforma-documents">Proforma</a>
                                    </li>
                                </ul>
                            </li>


                            <li class="sidebar-item  has-sub">
                                <a href="#" class='sidebar-link'>
                                    <i class="bi bi-pentagon-fill"></i>
                                    <span>Zwroty</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/client-complaints/shipping-documents">Wygenerowane</a>
                                    </li>
                                </ul>
                            </li>

<!--                            <li class="sidebar-item  has-sub">-->
<!--                                <a href="#" class='sidebar-link'>-->
<!--                                    <i class="bi bi-pentagon-fill"></i>-->
<!--                                    <span>Magazyn</span>-->
<!--                                </a>-->
<!--                                <ul class="submenu">-->
<!--                                    <li class="submenu-item ">-->
<!--                                        <a href="--><?php //=ROOT?><!--/Document/generate/stock_last_day_in_month" target="_blank">Stan magazynowy</a>-->
<!--                                    </li>-->
<!--                                </ul>-->
<!--                            </li>-->
<!---->
<!--                            <li class="sidebar-item  has-sub">-->
<!--                                <a href="#" class='sidebar-link'>-->
<!--                                    <i class="bi bi-pentagon-fill"></i>-->
<!--                                    <span>Produkt</span>-->
<!--                                </a>-->
<!--                                <ul class="submenu">-->
<!--                                    <li class="submenu-item ">-->
<!--                                        <a href="--><?php //=ROOT?><!--/Document/generate/missing_recipe" target="_blank">Brakujące receptury</a>-->
<!--                                    </li>-->
<!--                                </ul>-->
<!--                            </li>-->
<!---->
<!--                            <li class="sidebar-item  has-sub">-->
<!--                                <a href="#" class='sidebar-link'>-->
<!--                                    <i class="bi bi-pentagon-fill"></i>-->
<!--                                    <span>Produkcja</span>-->
<!--                                </a>-->
<!--                                <ul class="submenu">-->
<!--                                    <li class="submenu-item ">-->
<!--                                        <a href="--><?php //=ROOT?><!--/Document/generate/missing_recipe" target="_blank">Brakujące receptury</a>-->
<!--                                    </li>-->
<!--                                </ul>-->
<!--                            </li>-->

                            <li class="sidebar-item  has-sub">
                                <a href="#" class='sidebar-link'>
                                    <i class="bi bi-pentagon-fill"></i>
                                    <span>Faktury inne</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/invoice-documents">Wygenerowane</a>
                                    </li>
                                </ul>
                            </li>

                        </ul>
                    </li>

                    <li class="sidebar-item  has-sub">
                        <a href="#" class='sidebar-link'>
                            <i class="bi bi-pentagon-fill"></i>
                            <span>Finanse</span>
                        </a>

                        <ul class="submenu">
                            <li class="submenu-item ">
                                <a href="<?=ROOT?>/production-sections/profitability">Rentowność działów</a>
                            </li>
                            <li class="submenu-item ">
                                <a href="<?=ROOT?>/company-costs">Koszty</a>
                            </li>
                            <li class="sidebar-item  has-sub">
                                <a href="#" class='sidebar-link'>
                                    <span>Cena produktu gotowego</span>
                                </a>
                                <ul class="submenu ">
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/price-lists/clients">Cennik indywidualny</a>
                                    </li>
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/price-lists/general">Cennik ogólny</a>
                                    </li>
                                </ul>
                            </li>

                        </ul>

                    </li>


                    <li class="sidebar-item  has-sub">
                        <a href="#" class='sidebar-link'>
                            <i class="bi bi-pentagon-fill"></i>
                            <span>Transport</span>
                        </a>

                        <ul class="submenu ">
                            <li class="sidebar-item  has-sub">
                                <a href="#" class='sidebar-link'>
                                    <i class="bi bi-pentagon-fill"></i>
                                    <span>Dostawca</span>
                                </a>
                                <ul class="submenu ">
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/material-suppliers">Zarządzaj</a>
                                    </li>
                                </ul>
                            </li>

                            <li class="sidebar-item  has-sub">
                                <a href="#" class='sidebar-link'>
                                    <i class="bi bi-pentagon-fill"></i>
                                    <span>Przewoźnik</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item ">
                                        <a href="<?=ROOT?>/product-carriers">Zarządzaj</a>
                                    </li>
                                </ul>
                            </li>

                        </ul>
                    </li>

                    <li class="sidebar-item  has-sub">
                        <a href="#" class='sidebar-link'>
                            <i class="bi bi-pentagon-fill"></i>
                            <span>Pracownik</span>
                        </a>
                        <ul class="submenu ">
                            <li class="submenu-item ">
                                <a href="<?=ROOT?>/production-employees">Zarządzaj pracownikiem produkcji</a>
                            </li>
                        </ul>
                    </li>

                    <li class="sidebar-item  has-sub">
                        <a href="#" class='sidebar-link'>
                            <i class="bi bi-pentagon-fill"></i>
                            <span>Klient</span>
                        </a>
                        <ul class="submenu ">
                            <li class="submenu-item ">
                                <a href="<?=ROOT?>/clients?type=active">Zarządzaj</a>
                            </li>

                        </ul>
                    </li>

                    <li class="sidebar-item  has-sub">
                        <a href="#" class='sidebar-link'>
                            <i class="bi bi-pentagon-fill"></i>
                            <span>Ustawienia</span>
                        </a>
                        <ul class="submenu ">
                            <li class="submenu-item ">
                                <a href="<?=ROOT?>/users">Użytkownicy systemu</a>
                            </li>
                        </ul>
                    </li>

                    <li class="sidebar-item  has-sub">
                        <a href="#" class='sidebar-link'>
                            <i class="bi bi-pentagon-fill"></i>
                            <span>Strefa franczyzowa</span>
                        </a>
                        <ul class="submenu ">
                            <li class="submenu-item">
                                <a href="<?=ROOT?>/franchise-complaints">Reklamacje</a>
                            </li>
                            <li class="submenu-item ">
                                <a href="<?=ROOT?>/client-franchise-networks">Zarządzaj</a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
            <button class="sidebar-toggler btn x"><i data-feather="x"></i></button>
        </div>
    </div>