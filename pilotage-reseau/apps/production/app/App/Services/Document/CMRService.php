<?php
namespace App\Services\Document;

use App\Exceptions\CustomException;
use App\Interfaces\IDocument;
use App\Repositories\Document\Cmr\CMRPackagesRepository;
use App\Repositories\Document\Cmr\CMRRepository;
use App\Repositories\Document\Wz\WZRepository;
use App\Services\Utils\TCPDFService;

class CMRService implements IDocument
{
    private CMRRepository $cmrRepository;
    private CMRPackagesRepository $cmrPackagesRepository;
    private WZRepository $wzRepository;

    public function __construct(
        CMRRepository $cmrRepository,
        CMRPackagesRepository $cmrPackagesRepository,
        WZRepository $wzRepository

    )
    {
        $this->cmrRepository = $cmrRepository;
        $this->cmrPackagesRepository = $cmrPackagesRepository;
        $this->wzRepository = $wzRepository;
    }

    public function renderDocumentAsia(TCPDFService $pdf, array $data): TCPDFService
    {
        $established_date = "2026-10-01";
//        $consignor_data = "Berdiff s.a.<br>Grand Route, 20<br>1435 Corbais, Belgique";
        $consignor_data = "BRUYERRE SA - BE0431703151<br>RUE FRANCOIS LEON BRUYERRE 34<br>B-6041 Gosselies (Belgique)";


        $pdf->setMargins(10, 10, 10);
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);
        $pdf->AddPage();

        $pdf->SetFont('dejavusans', '', 6);

        $text = "(Imię, nazwisko lub nazwa, adres, kraj) \n(name, address, country)";
        $pdf->createHederToCell(1, $text);

        $text = "MIĘDZYNARODOWY SAMOCHODOWY LIST PRZEWOZOWY \nINTERNATIONAL CONSIGNEMENT NOTE";
        $pdf->createHederToCell('', $text);
        $pdf->Ln(6);


        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(95, 16, "<b>$consignor_data</b>", 'LRB', 'C', 0, 0, '', '', true, false, true);


        $pdf->SetFont('dejavusans', '', 5);
        $pdf->MultiCell(6, 16, "", 'LB', "C", 0, '', '', '', true, false, true, 'M');
        $text = "Numer dokumentu: <b>721</b><br>Niniejszy przewóz podlega postanowieniom konwencji o umowie międzynarodowej przewozu drogowego towarów (CMR) bez względu na jakąkolwiek przeciwną klauzulę";
        $pdf->MultiCell(42, 16, $text, 'B', 'J', 0, 0, '', '', true, false, true);


        $pdf->MultiCell(5, 16, "", 'B', 'J', 0, 0, '', '', true);
        $text = "CMR num.: <b>721</b><br>This carriage is subject, not with standing any clause to the contrary, to the Convention on the Contract for the international Carriage of goods by road (CMR)";
        $pdf->MultiCell(42, 16, $text, 'BR', 'J', 0, 0, '', '', true, false, true);
        $pdf->Ln(16);


        $text = "Odbiorca (nazwisko lub nazwa, adres, kraj)\nConsignee (name, address, country)";
        $pdf->createHederToCell(2, $text);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $text = "Przewoźnik (nazwisko lub nazwa, adres, kraj)\nCarrier (name, address, country)";
        $pdf->createHederToCell(16, $text);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));
        $pdf->Ln(6);


        $consignee_data = "ATELIER WRO01 SP. Z O.O.<br>Otmuchowska 88<br>48-300 Nysa - POLSKA/POLAND";
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->MultiCell(95, 16, $consignee_data, 'LRB', 'L', 0, 0, '', '', true, false, true);
        $pdf->SetFont('dejavusans', '', 9);

        $carrier = "ATELIER WRO01 SP. Z O.O.<br>Otmuchowska, 88<br>48300, Nysa<br><b>DX 02760</b>";

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->MultiCell(95, 16, $carrier, 'LR', 'C', 0, 0, '', '', true, false, true);
        $pdf->Ln(16);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));

        $text = "Miejsce przeznaczenia (miejscowość, kraj)\nPlace of delivery of the goods (place, country)";
        $pdf->createHederToCell(3, $text);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->SetFont('dejavusans', '', 6);
        $text = "Kolejni przewoźnicy (nazwisko lub nazwa, adres, kraj)\nSuccesive carriers (name, address, country)";
        $pdf->MultiCell(6, 6, "<b>17</b>", 'L', "C", 0, '', '', '', true, false, true, 'M');
        $pdf->MultiCell(89, 6, $text, 'R', 'L', 0, 0, '', '', true);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));

        $pdf->Ln(6);
        $pdf->MultiCell(95, 9, "48-300 Nysa - POLSKA/POLAND", 'LRB', 'L', 0, 0, '', '', true, false, true);


        $carrier_sec = "{$data['cmrData']['carrier_sec_name']}<br>{$data['cmrData']['carrier_sec_address_1']}, {$data['cmrData']['carrier_sec_address_2']}<br><b>{$data['cmrData']['carrier_sec_regis_num']}</b>";

        $pdf->SetFont('dejavusans', '', 6);
        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->MultiCell(95, 9, $carrier_sec, 'LR', 'L', 0, 0, '', '', true, false, true);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));

        $pdf->Ln(9);
        $pdf->SetFont('dejavusans', '', 9);

        $text = "Miejsce i data załadowania (miejscowość, kraj, data)\nPlace and date of taking over the goods (place, country, date)";
        $pdf->createHederToCell(4, $text);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $text = "Zastrzeżenia i uwagi przewoźnika\nCarrier's reservations and observations";
        $pdf->createHederToCell(18, $text);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));

        $pdf->Ln(6);

        $pdf->MultiCell(95, 9, "<b>B-6041 Gosselies (Belgique)</b>", 'LRB', 'C', 0, 0, '', '', true, false, true);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->MultiCell(95, 24, $data['cmrData']['carrier_reservation_observation'], 'LRB', 'L', 0, 0, '', '', true, false, true);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));

        $pdf->Ln(9);
        $text = "Załączam dokumenty\nDocuments attached";
        $pdf->createHederToCell(5, $text);
        $pdf->Ln(6);

        $pdf->MultiCell(95, 9, "GOS30/SO26248089", 'LRB', 'C', 0, 0, '', '', true, false, true);
        $pdf->Ln(9);

        $pdf->SetFont('dejavusans', '', 5);
        $pdf->MultiCell(6, 6, '<b>6</b>', 'LTB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(22, 6, "Cechy i numery\nMarks and Nos", 'TB', 'L', 0, '', '', '', true);
        $pdf->MultiCell(6, 6, '<b>7</b>', 'TB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(21, 6, "Ilość sztuk\nNumber of packages", 'TB', 'L', 0, '', '', '', true);
        $pdf->MultiCell(6, 6, '<b>8</b>', 'TB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(21, 6, "Sposób pakowania\nMethod of packing", 'TB', 'L', 0, '', '', '', true);
        $pdf->MultiCell(6, 6, '<b>9</b>', 'TB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(21, 6, "Rodzaj towaru\nNature of the goods", 'RTB', 'L', 0, '', '', '', true);
        $pdf->MultiCell(6, 6, '<b>10</b>', 'TB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(21, 6, "Nr statystyczny\nStatistical number", 'RTB', 'L', 0, '', '', '', true);
        $pdf->MultiCell(6, 6, '<b>11</b>', 'TB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(21, 6, "Waga brutto w kg\nGross weight in kg", 'RTB', 'L', 0, '', '', '', true);
        $pdf->MultiCell(6, 6, '<b>12</b>', 'TB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(21, 6, "Objetość w m3\nVolume in m3", 'RTB', 'L', 0, '', '', '', true);
        $pdf->Ln(6);

        // for single row
//        $pdf->MultiCell(109, 5, "", 1, 0, 0, '', '', '', true);
//        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
//        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
//        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
//        $pdf->Ln(5);

        $pdf->SetFont('dejavusans', '', 8);

        $pdf->MultiCell(27, 56, 'Packs', 'LT', 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, "196", 0, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, "Pack", 0, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(28, 56, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, "18063290", 1, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, "935.75 kg", 1, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, '', 'TR', 'L', 0, '', '', '', true, '', true);
        $pdf->Ln(56);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "10", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18063290", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "100 kg", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'R', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "10", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18063290", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "24.75 kg", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'R', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "14", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18063290", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "35 kg", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'R', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "5", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18063290", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "12.5 kg", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'R', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "15", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18063290", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "37.5 kg", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'R', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "5", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18063290", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "5 kg", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'R', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "2", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18063290", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "5 kg", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'R', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "2", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18063290", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "5 kg", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'R', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "2", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18063290", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "2 kg", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'R', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "2", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18063290", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "1.75 kg", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'R', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "2", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18063290", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "5 kg", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'R', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'LB', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "15", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18063290", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "37.5 kg", 'RL', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'RB', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//        $pdf->SetFont('dejavusans', '', 6);

        //end for single row



        // for two rows
//        $pdf->MultiCell(109, 5, "", 1, 0, 0, '', '', '', true);
//        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
//        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
//        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
//        $pdf->Ln(5);
//        $pdf->SetFont('dejavusans', '', 8);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'LT', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, 10, 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18062010", "LTR", 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "100 kg", 'LTR', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'LTR', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 51, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, 3, 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 51, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, "18063290", "LR", 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, "165 kg", 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, '', 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 46, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 46, 30, 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 46, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 46, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 46, "18040000", "LR", 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 46, "4 kg", 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 46, '', 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 41, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 41, 1, 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 41, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 41, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 41, "17049030", "LR", 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 41, "10 kg", 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 41, '', 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 36, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 36, 1, 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 36, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 36, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 36, "84381010", "LR", 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 36, "20 kg", 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 36, '', 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 31, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 31, 28, 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 31, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 31, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 31, "18062010", "LR", 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 31, "100 kg", 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 31, '', 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);

//        $pdf->MultiCell(27, 51, 'Cartons', 'LB', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, $data['cmrPackageData'][1]['quantity'], 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, $data['cmrPackageData'][1]['name'], 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 51, $data['cmrPackageData'][1]['product_type'], 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, $data['cmrPackageData'][1]['stats_num'], 'LRB', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, "{$data['cmrPackageData'][1]['gross_weight']} kg", 'LRB', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, '', 'LRB', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(25);
        $pdf->SetFont('dejavusans', '', 6);
        //end for two rows

        $pdf->MultiCell(14, 6, "<b>Klasa<br>Class</b>", 'LTB', 'L', 0, ',', '', '', true, '', true);
        $pdf->MultiCell(14, 6, '', 'TB', 'C', 0, ',', '', '', true, '', true);
        $pdf->MultiCell(14, 6, "<b>Nr UN<br>Number</b>", 'TB', 'L', 0, ',', '', '', true, '', true);
        $pdf->MultiCell(13, 6, '', 'TB', 0, 0, ',', '', '', true, '', true);
        $pdf->MultiCell(14, 6, "<b>GP<br>PG</b>", 'TB', 'L', 0, 0, '', '', true, '', true);
        $pdf->MultiCell(13, 6, '', 'TB', 0, 0, 0, '', '', true, '', true);
        $pdf->MultiCell(27, 6, '', 'TR', 0, 0, '', '', '', true);
        $pdf->MultiCell(27, 6, '', 'R', 0, 0, '', '', '', true);
        $pdf->MultiCell(27, 6, '', 'R', 0, 0, '', '', '', true);
        $pdf->MultiCell(27, 6, '', 'R', 0, 0, '', '', '', true);
        $pdf->Ln(6);

        $text = "Instrukcje nadawcy\nSender's instructions";
        $pdf->createHederToCell(13, $text);

        $text = "Postanowienia specjalne\nSpecial agreements";
        $pdf->createHederToCell(19, $text);
        $pdf->Ln(5);

        $sender_instr = str_replace('\n', "<br>", $data['cmrData']['sender_instructions']);

        $pdf->MultiCell(95, 44, $sender_instr, 'LBR', 'L', 0, 0, '', '', true, '', true);

        $pdf->MultiCell(95, 18, '', 'LBR', 0, 0, 0, '', '', true, '', true);
        $pdf->Ln(14);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->SetFont('dejavusans', '', 6);
        $pdf->MultiCell(6, 6, "<b>20</b>", 'TLB', "C", 0, 0, 105, 190, true, false, true, 'M');

        $pdf->MultiCell(21, 6, "Do zapłacenia\nTo be paid by", 'TRB', 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "Nadawca\nSender", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(22, 6, "Waluta\nCurency", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "Odbiorca\nConsignee", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);


        $pdf->MultiCell(27, 6, "Przewoźne\nCarriage", 'LTR', 'L', 0, 0, 105, 196, true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->MultiCell(27, 6, "Bonifikaty\nDeductions", 'LRB', 'L', 0, 0, 105, 202, true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->MultiCell(27, 6, "Saldo\nBalance", 'LTR', 'L', 0, 0, 105, 208, true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->MultiCell(27, 6, "Dopłaty\nSupplem, charges", 'LR', 'L', 0, 0, 105, 214, true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);
        $pdf->MultiCell(27, 6, "Koszty dodatkowe\nMiscellaneous +", 'LRB', 'L', 0, 0, 105, 220, true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);
        $pdf->MultiCell(27, 6, "Razem\nTotal to be paid", 'LRB', 'L', 0, 0, 105, 226, true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->SetFont('dejavusans', '', 5);

        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));
        $pdf->MultiCell(6, 6, "<b>14</b>", 'TL', "C", 0, 0, '', 216, true, false, true, 'M');
        $pdf->MultiCell(45, 6, "Postanowienia odnośnie przewoźniego\nInstruction as to payment carriage", 'T', 'L', 0, 0, '', '', true);
        $pdf->MultiCell(44, 6, '', 'TR', 0, 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->MultiCell(6, 4, "", 'L', "C", 0, 0, '', '', true, false, true, 'M');
        $pdf->MultiCell(45, 4, "Przewoźne zapłacone/Carriage paid", '', 'L', 0, 0, '', '', true);
        $pdf->MultiCell(44, 4, '', 'R', 0, 0, 0, '', '', true);
        $pdf->Ln(4);
        $pdf->MultiCell(6, 6, "", 'BL', "C", 0, 0, '', '', true, false, true, 'M');
        $pdf->MultiCell(45, 6, "Przewoźne nieopłacone/Carrage forward", 'B', 'L', 0, 0, '', '', true);
        $pdf->MultiCell(44, 6, '', 'BR', 0, 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->MultiCell(6, 6, "<b>21</b>", 'TL', "C", 0, 0, '', '', true, false, true, 'M');
        $pdf->MultiCell(36, 6, "Wystawiono w \nEstablished in", 'T', '', 0, 0, '', '', true);
        $pdf->MultiCell(53, 6, "dnia\non", 'RT', 'L', 0, 0, '', '', true);

        $text = "Zapłata/Cash on delivery";
        $pdf->createHederToCell(15, $text);
        $pdf->Ln(6);

        $pdf->SetFont('dejavusans', '', 6);
        $pdf->MultiCell(6, 4, "", 'LB', "C", 0, 0, '', '', true);
        $pdf->MultiCell(36, 4, "B-6041 Gosselies (Belgique)", 'B', "", 0, 0, '', '', true);

        $pdf->MultiCell(53, 4, $established_date, 'BR', "", 0, 0, '', '', true);

        $pdf->MultiCell(6, 4, "", 'LB', "C", 0, 0, '', '', true);
        $pdf->MultiCell(89, 4, '', 'BR', '', 0, 0, '', '', true);
        $pdf->Ln(4);

        $pdf->MultiCell(6, 6, "<b>22</b>", 'TL', 0, 0, 0, '', '', true, false, true);
        $pdf->MultiCell(57, 6, '', 'TR', 0, 0, 0, '', '', true);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->MultiCell(6, 6, "<b>23</b>", 'TL', 0, 0, 0, '', '', true, false, true);
        $pdf->MultiCell(53, 6, '', 'TR', 0, 0, 0, '', '', true);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));

        $pdf->MultiCell(6, 6, "<b>24</b>", 'TL', 0, 0, 0, '', '', true, false, true);
        $pdf->MultiCell(25, 6, "Przesyłkę otrzymano\nGoods received\n\nMiejscowość\nPlace", 'T', 'L', 0, 0, '', '', true);
        $pdf->MultiCell(37, 6, "\n\n\ndnia\non", 'TR', 'L', 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->MultiCell(6, 21, "", 'LB', 0, 0, 0, '', '', true);
        $pdf->MultiCell(57, 21, "Podpis i stempel nadawcy\nSignature and stamp of the sender", 'BR', 0, 0, 0, '', '', true, 0, false, true, 21, 'B');

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->MultiCell(6, 21, "", 'LB', 0, 0, 0, '', '', true);
        $pdf->MultiCell(53, 21, "Podpis i stempel przewoźnika\nSignature and stamp of the carrier", 'BR', 0, 0, 0, '', '', true, 0, false, true, 21, 'B');
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));


        $pdf->MultiCell(6, 21, "", 'B', 0, 0, 0, '', '', true);
        $pdf->MultiCell(62, 21, "Podpis i stempel odbiorcy\nSignature and stamp of the consignee", 'BR', 0, 0, 0, '', '', true, 0, false, true, 21, 'B');


        $pdf->StartTransform();
        $pdf->Rotate(90);
        $pdf->MultiCell(55, 4, "Do wypełnienia pod odpowiedzialnością nadawcy\nTo be completed on seller's respobsability", 0, 0, 0, 0, 245, 51, true);

        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(12, 4, "<b>1-15</b>", 0, 0, 0, 0, 299, 51, true, 0, true);

        $pdf->SetFont('dejavusans', '', 6);
        $pdf->MultiCell(20, 4, "włacznie oraz\nincluding and", 0, 0, 0, 0, 310, 51, true);

        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(30, 4, "<b>19+21+22</b>", 0, 0, 0, 0, 326, 51, true, 0, true);

        $pdf->SetFont('dejavusans', '', 6);
        $pdf->MultiCell(75, 4, "Rubryki obwiedzione tłustymi liniami wypełnia przewoźnik.\nThe space framed with heavy lines must be filled in by the carrier.", 0, 0, 0, 0, 350, 51, true);
        $pdf->StopTransform();

        $pdf->StartTransform();
        $pdf->Rotate(-90);
        $text = "*W przypadku przewozu towarów niebezpiecznych, oprócz ewentualnego posiadania zaświadczenia, należy podać w ostatnim wierszu: klasę, liczbę oraz w danym przypadku literę.\n*In case of dangerous goods mention, besides the possible certification, on the last line of the column the particulars of the class, the UN number and the packing group.";
        $pdf->MultiCell(200, 9, $text, 0, '', 0, 0, '', '', true, 0, false, true, 9, 'B');

        $pdf->StopTransform();

        return $pdf;
    }

    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService
    {
        $pdf->setMargins(10, 10, 10);
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);
        $pdf->AddPage();

        $pdf->SetFont('dejavusans', '', 6);

        $text = "(Imię, nazwisko lub nazwa, adres, kraj) \n(name, address, country)";
        $pdf->createHederToCell(1, $text);

        $text = "MIĘDZYNARODOWY SAMOCHODOWY LIST PRZEWOZOWY \nINTERNATIONAL CONSIGNEMENT NOTE";
        $pdf->createHederToCell('', $text);
        $pdf->Ln(6);

        $consignor_data = "{$data['cmrData']['consignor_name']}<br>{$data['cmrData']['consignor_address_1']}<br>
                            {$data['cmrData']['consignor_address_2']}";
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(95, 16, "<b>$consignor_data</b>", 'LRB', 'C', 0, 0, '', '', true, false, true);


        $pdf->SetFont('dejavusans', '', 5);
        $pdf->MultiCell(6, 16, "", 'LB', "C", 0, '', '', '', true, false, true, 'M');
        $text = "Numer dokumentu: <b>{$data['cmrData']['doc_num']}</b><br>Niniejszy przewóz podlega postanowieniom konwencji o umowie międzynarodowej przewozu drogowego towarów (CMR) bez względu na jakąkolwiek przeciwną klauzulę";
        $pdf->MultiCell(42, 16, $text, 'B', 'J', 0, 0, '', '', true, false, true);


        $pdf->MultiCell(5, 16, "", 'B', 'J', 0, 0, '', '', true);
        $text = "CMR num.: <b>{$data['cmrData']['doc_num']}</b><br>This carriage is subject, not with standing any clause to the contrary, to the Convention on the Contract for the international Carriage of goods by road (CMR)";
        $pdf->MultiCell(42, 16, $text, 'BR', 'J', 0, 0, '', '', true, false, true);
        $pdf->Ln(16);


        $text = "Odbiorca (nazwisko lub nazwa, adres, kraj)\nConsignee (name, address, country)";
        $pdf->createHederToCell(2, $text);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $text = "Przewoźnik (nazwisko lub nazwa, adres, kraj)\nCarrier (name, address, country)";
        $pdf->createHederToCell(16, $text);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));
        $pdf->Ln(6);


        $consignee_data = "{$data['cmrData']['consignee_name']}<br>{$data['cmrData']['consignee_address_1']}<br>{$data['cmrData']['consignee_address_2']}";
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->MultiCell(95, 16, $consignee_data, 'LRB', 'L', 0, 0, '', '', true, false, true);
        $pdf->SetFont('dejavusans', '', 9);

        $carrier = "{$data['cmrData']['carrier_name']}<br>{$data['cmrData']['carrier_address_1']}<br>{$data['cmrData']['carrier_address_2']}<br><b>{$data['cmrData']['carrier_regis_num']}</b>";

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->MultiCell(95, 16, $carrier, 'LR', 'C', 0, 0, '', '', true, false, true);
        $pdf->Ln(16);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));

        $text = "Miejsce przeznaczenia (miejscowość, kraj)\nPlace of delivery of the goods (place, country)";
        $pdf->createHederToCell(3, $text);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->SetFont('dejavusans', '', 6);
        $text = "Kolejni przewoźnicy (nazwisko lub nazwa, adres, kraj)\nSuccesive carriers (name, address, country)";
        $pdf->MultiCell(6, 6, "<b>17</b>", 'L', "C", 0, '', '', '', true, false, true, 'M');
        $pdf->MultiCell(89, 6, $text, 'R', 'L', 0, 0, '', '', true);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));

        $pdf->Ln(6);
        $pdf->MultiCell(95, 9, $data['cmrData']['delivery_place'], 'LRB', 'L', 0, 0, '', '', true, false, true);


        $carrier_sec = "{$data['cmrData']['carrier_sec_name']}<br>{$data['cmrData']['carrier_sec_address_1']}, {$data['cmrData']['carrier_sec_address_2']}<br><b>{$data['cmrData']['carrier_sec_regis_num']}</b>";

        $pdf->SetFont('dejavusans', '', 6);
        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->MultiCell(95, 9, $carrier_sec, 'LR', 'L', 0, 0, '', '', true, false, true);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));

        $pdf->Ln(9);
        $pdf->SetFont('dejavusans', '', 9);

        $text = "Miejsce i data załadowania (miejscowość, kraj, data)\nPlace and date of taking over the goods (place, country, date)";
        $pdf->createHederToCell(4, $text);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $text = "Zastrzeżenia i uwagi przewoźnika\nCarrier's reservations and observations";
        $pdf->createHederToCell(18, $text);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));

        $pdf->Ln(6);

        $pdf->MultiCell(95, 9, "<b>{$data['cmrData']['taking_over_place']}</b>", 'LRB', 'C', 0, 0, '', '', true, false, true);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->MultiCell(95, 24, $data['cmrData']['carrier_reservation_observation'], 'LRB', 'L', 0, 0, '', '', true, false, true);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));

        $pdf->Ln(9);
        $text = "Załączam dokumenty\nDocuments attached";
        $pdf->createHederToCell(5, $text);
        $pdf->Ln(6);

        $pdf->MultiCell(95, 9, $data['wzData']['doc_num'], 'LRB', 'C', 0, 0, '', '', true, false, true);
        $pdf->Ln(9);

        $pdf->SetFont('dejavusans', '', 5);
        $pdf->MultiCell(6, 6, '<b>6</b>', 'LTB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(22, 6, "Cechy i numery\nMarks and Nos", 'TB', 'L', 0, '', '', '', true);
        $pdf->MultiCell(6, 6, '<b>7</b>', 'TB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(21, 6, "Ilość sztuk\nNumber of packages", 'TB', 'L', 0, '', '', '', true);
        $pdf->MultiCell(6, 6, '<b>8</b>', 'TB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(21, 6, "Sposób pakowania\nMethod of packing", 'TB', 'L', 0, '', '', '', true);
        $pdf->MultiCell(6, 6, '<b>9</b>', 'TB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(21, 6, "Rodzaj towaru\nNature of the goods", 'RTB', 'L', 0, '', '', '', true);
        $pdf->MultiCell(6, 6, '<b>10</b>', 'TB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(21, 6, "Nr statystyczny\nStatistical number", 'RTB', 'L', 0, '', '', '', true);
        $pdf->MultiCell(6, 6, '<b>11</b>', 'TB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(21, 6, "Waga brutto w kg\nGross weight in kg", 'RTB', 'L', 0, '', '', '', true);
        $pdf->MultiCell(6, 6, '<b>12</b>', 'TB', 'C', 0, '', '', '', true, '', true);
        $pdf->MultiCell(21, 6, "Objetość w m3\nVolume in m3", 'RTB', 'L', 0, '', '', '', true);
        $pdf->Ln(6);

        // for single row
//        $pdf->MultiCell(109, 5, "", 1, 0, 0, '', '', '', true);
//        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
//        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
//        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
//        $pdf->Ln(5);

        $pdf->SetFont('dejavusans', '', 8);

        $pdf->MultiCell(27, 56, 'Cartons', 'LTB', 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, $data['cmrPackageData'][0]['quantity'], 0, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, $data['cmrPackageData'][0]['name'], 0, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(28, 56, $data['cmrPackageData'][0]['product_type'], 0, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, $data['cmrPackageData'][0]['stats_num'], 1, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, "{$data['cmrPackageData'][0]['gross_weight']} kg", 1, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, '', 'TRB', 'L', 0, '', '', '', true, '', true);
        $pdf->Ln(56);
//        $pdf->SetFont('dejavusans', '', 6);

        //end for single row



        // for two rows
//        $pdf->MultiCell(109, 5, "", 1, 0, 0, '', '', '', true);
//        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
//        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
//        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
//        $pdf->Ln(5);
//        $pdf->SetFont('dejavusans', '', 8);
//
//        $pdf->MultiCell(27, 5, 'Packs', 'LT', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, 10, 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 5, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "18062010", "LTR", 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, "100 kg", 'LTR', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 5, '', 'LTR', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 51, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, 3, 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 51, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, "18063290", "LR", 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, "165 kg", 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, '', 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 46, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 46, 30, 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 46, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 46, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 46, "18040000", "LR", 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 46, "4 kg", 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 46, '', 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 41, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 41, 1, 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 41, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 41, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 41, "17049030", "LR", 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 41, "10 kg", 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 41, '', 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 36, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 36, 1, 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 36, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 36, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 36, "84381010", "LR", 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 36, "20 kg", 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 36, '', 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);
//
//        $pdf->MultiCell(27, 31, 'Packs', 'L', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 31, 28, 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 31, "Pack", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 31, "Confectionery", 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 31, "18062010", "LR", 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 31, "100 kg", 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 31, '', 'LR', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(5);

//        $pdf->MultiCell(27, 51, 'Cartons', 'LB', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, $data['cmrPackageData'][1]['quantity'], 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, $data['cmrPackageData'][1]['name'], 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(28, 51, $data['cmrPackageData'][1]['product_type'], 0, 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, $data['cmrPackageData'][1]['stats_num'], 'LRB', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, "{$data['cmrPackageData'][1]['gross_weight']} kg", 'LRB', 'L', 0, '', '', '', true, '', true);
//        $pdf->MultiCell(27, 51, '', 'LRB', 'L', 0, '', '', '', true, '', true);
//        $pdf->Ln(25);
        $pdf->SetFont('dejavusans', '', 6);
        //end for two rows

        $pdf->MultiCell(14, 6, "<b>Klasa<br>Class</b>", 'LTB', 'L', 0, ',', '', '', true, '', true);
        $pdf->MultiCell(14, 6, '', 'TB', 'C', 0, ',', '', '', true, '', true);
        $pdf->MultiCell(14, 6, "<b>Nr UN<br>Number</b>", 'TB', 'L', 0, ',', '', '', true, '', true);
        $pdf->MultiCell(13, 6, '', 'TB', 0, 0, ',', '', '', true, '', true);
        $pdf->MultiCell(14, 6, "<b>GP<br>PG</b>", 'TB', 'L', 0, 0, '', '', true, '', true);
        $pdf->MultiCell(13, 6, '', 'TB', 0, 0, 0, '', '', true, '', true);
        $pdf->MultiCell(27, 6, '', 'TR', 0, 0, '', '', '', true);
        $pdf->MultiCell(27, 6, '', 'R', 0, 0, '', '', '', true);
        $pdf->MultiCell(27, 6, '', 'R', 0, 0, '', '', '', true);
        $pdf->MultiCell(27, 6, '', 'R', 0, 0, '', '', '', true);
        $pdf->Ln(6);

        $text = "Instrukcje nadawcy\nSender's instructions";
        $pdf->createHederToCell(13, $text);

        $text = "Postanowienia specjalne\nSpecial agreements";
        $pdf->createHederToCell(19, $text);
        $pdf->Ln(5);

        $sender_instr = str_replace('\n', "<br>", $data['cmrData']['sender_instructions']);

        $pdf->MultiCell(95, 44, $sender_instr, 'LBR', 'L', 0, 0, '', '', true, '', true);

        $pdf->MultiCell(95, 18, '', 'LBR', 0, 0, 0, '', '', true, '', true);
        $pdf->Ln(14);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->SetFont('dejavusans', '', 6);
        $pdf->MultiCell(6, 6, "<b>20</b>", 'TLB', "C", 0, 0, 105, 190, true, false, true, 'M');

        $pdf->MultiCell(21, 6, "Do zapłacenia\nTo be paid by", 'TRB', 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "Nadawca\nSender", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(22, 6, "Waluta\nCurency", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "Odbiorca\nConsignee", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);


        $pdf->MultiCell(27, 6, "Przewoźne\nCarriage", 'LTR', 'L', 0, 0, 105, 196, true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->MultiCell(27, 6, "Bonifikaty\nDeductions", 'LRB', 'L', 0, 0, 105, 202, true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->MultiCell(27, 6, "Saldo\nBalance", 'LTR', 'L', 0, 0, 105, 208, true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->MultiCell(27, 6, "Dopłaty\nSupplem, charges", 'LR', 'L', 0, 0, 105, 214, true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);
        $pdf->MultiCell(27, 6, "Koszty dodatkowe\nMiscellaneous +", 'LRB', 'L', 0, 0, 105, 220, true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);
        $pdf->MultiCell(27, 6, "Razem\nTotal to be paid", 'LRB', 'L', 0, 0, 105, 226, true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(11, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->MultiCell(23, 6, "", 1, 'L', 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->SetFont('dejavusans', '', 5);

        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));
        $pdf->MultiCell(6, 6, "<b>14</b>", 'TL', "C", 0, 0, '', 216, true, false, true, 'M');
        $pdf->MultiCell(45, 6, "Postanowienia odnośnie przewoźniego\nInstruction as to payment carriage", 'T', 'L', 0, 0, '', '', true);
        $pdf->MultiCell(44, 6, '', 'TR', 0, 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->MultiCell(6, 4, "", 'L', "C", 0, 0, '', '', true, false, true, 'M');
        $pdf->MultiCell(45, 4, "Przewoźne zapłacone/Carriage paid", '', 'L', 0, 0, '', '', true);
        $pdf->MultiCell(44, 4, '', 'R', 0, 0, 0, '', '', true);
        $pdf->Ln(4);
        $pdf->MultiCell(6, 6, "", 'BL', "C", 0, 0, '', '', true, false, true, 'M');
        $pdf->MultiCell(45, 6, "Przewoźne nieopłacone/Carrage forward", 'B', 'L', 0, 0, '', '', true);
        $pdf->MultiCell(44, 6, '', 'BR', 0, 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->MultiCell(6, 6, "<b>21</b>", 'TL', "C", 0, 0, '', '', true, false, true, 'M');
        $pdf->MultiCell(36, 6, "Wystawiono w \nEstablished in", 'T', '', 0, 0, '', '', true);
        $pdf->MultiCell(53, 6, "dnia\non", 'RT', 'L', 0, 0, '', '', true);

        $text = "Zapłata/Cash on delivery";
        $pdf->createHederToCell(15, $text);
        $pdf->Ln(6);

        $pdf->SetFont('dejavusans', '', 6);
        $pdf->MultiCell(6, 4, "", 'LB', "C", 0, 0, '', '', true);
        $pdf->MultiCell(36, 4, $data['cmrData']['established_in'], 'B', "", 0, 0, '', '', true);

        $pdf->MultiCell(53, 4, $data['cmrData']['established_date'], 'BR', "", 0, 0, '', '', true);

        $pdf->MultiCell(6, 4, "", 'LB', "C", 0, 0, '', '', true);
        $pdf->MultiCell(89, 4, '', 'BR', '', 0, 0, '', '', true);
        $pdf->Ln(4);

        $pdf->MultiCell(6, 6, "<b>22</b>", 'TL', 0, 0, 0, '', '', true, false, true);
        $pdf->MultiCell(57, 6, '', 'TR', 0, 0, 0, '', '', true);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->MultiCell(6, 6, "<b>23</b>", 'TL', 0, 0, 0, '', '', true, false, true);
        $pdf->MultiCell(53, 6, '', 'TR', 0, 0, 0, '', '', true);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));

        $pdf->MultiCell(6, 6, "<b>24</b>", 'TL', 0, 0, 0, '', '', true, false, true);
        $pdf->MultiCell(25, 6, "Przesyłkę otrzymano\nGoods received\n\nMiejscowość\nPlace", 'T', 'L', 0, 0, '', '', true);
        $pdf->MultiCell(37, 6, "\n\n\ndnia\non", 'TR', 'L', 0, 0, '', '', true);
        $pdf->Ln(6);

        $pdf->MultiCell(6, 21, "", 'LB', 0, 0, 0, '', '', true);
        $pdf->MultiCell(57, 21, "Podpis i stempel nadawcy\nSignature and stamp of the sender", 'BR', 0, 0, 0, '', '', true, 0, false, true, 21, 'B');

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->MultiCell(6, 21, "", 'LB', 0, 0, 0, '', '', true);
        $pdf->MultiCell(53, 21, "Podpis i stempel przewoźnika\nSignature and stamp of the carrier", 'BR', 0, 0, 0, '', '', true, 0, false, true, 21, 'B');
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));


        $pdf->MultiCell(6, 21, "", 'B', 0, 0, 0, '', '', true);
        $pdf->MultiCell(62, 21, "Podpis i stempel odbiorcy\nSignature and stamp of the consignee", 'BR', 0, 0, 0, '', '', true, 0, false, true, 21, 'B');


        $pdf->StartTransform();
        $pdf->Rotate(90);
        $pdf->MultiCell(55, 4, "Do wypełnienia pod odpowiedzialnością nadawcy\nTo be completed on seller's respobsability", 0, 0, 0, 0, 245, 51, true);

        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(12, 4, "<b>1-15</b>", 0, 0, 0, 0, 299, 51, true, 0, true);

        $pdf->SetFont('dejavusans', '', 6);
        $pdf->MultiCell(20, 4, "włacznie oraz\nincluding and", 0, 0, 0, 0, 310, 51, true);

        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(30, 4, "<b>19+21+22</b>", 0, 0, 0, 0, 326, 51, true, 0, true);

        $pdf->SetFont('dejavusans', '', 6);
        $pdf->MultiCell(75, 4, "Rubryki obwiedzione tłustymi liniami wypełnia przewoźnik.\nThe space framed with heavy lines must be filled in by the carrier.", 0, 0, 0, 0, 350, 51, true);
        $pdf->StopTransform();

        $pdf->StartTransform();
        $pdf->Rotate(-90);
        $text = "*W przypadku przewozu towarów niebezpiecznych, oprócz ewentualnego posiadania zaświadczenia, należy podać w ostatnim wierszu: klasę, liczbę oraz w danym przypadku literę.\n*In case of dangerous goods mention, besides the possible certification, on the last line of the column the particulars of the class, the UN number and the packing group.";
        $pdf->MultiCell(200, 9, $text, 0, '', 0, 0, '', '', true, 0, false, true, 9, 'B');

        $pdf->StopTransform();

        return $pdf;
    }

    public function buildDocumentFromId(TCPDFService $pdf, int $id): array
    {
        $cmrData = $this->cmrRepository->first(['id' => $id]);
        if (!$cmrData) {
            throw new CustomException("CMR_NOT_FOUND", 'errors', 404);
        }

        $cmrPackageData = $this->cmrPackagesRepository->getByIdCMR($id);
        if (!$cmrPackageData) {
            throw new CustomException("CMR_PACKAGE_NOT_FOUND", 'errors', 404);
        }

        $wzData = $this->wzRepository->first(['id' => $cmrData['id_document_wz']]);
        if (!$wzData) {
            throw new CustomException("WZ_NOT_FOUND", 'errors', 404);
        }

        $this->renderDocument($pdf, [
            'cmrData' => $cmrData,
            'cmrPackageData' => $cmrPackageData,
            'wzData' => $wzData]);

        return ['file_string' => $pdf, "filename" => 'CMR_' . $cmrData['doc_num'] . '.pdf'];
    }

    public function buildDocumentFromIdAsia(TCPDFService $pdf, int $id): array
    {
        $cmrData = $this->cmrRepository->first(['id' => $id]);
        if (!$cmrData) {
            throw new CustomException("CMR_NOT_FOUND", 'errors', 404);
        }

        $cmrPackageData = $this->cmrPackagesRepository->getByIdCMR($id);
        if (!$cmrPackageData) {
            throw new CustomException("CMR_PACKAGE_NOT_FOUND", 'errors', 404);
        }

        $wzData = $this->wzRepository->first(['id' => $cmrData['id_document_wz']]);
        if (!$wzData) {
            throw new CustomException("WZ_NOT_FOUND", 'errors', 404);
        }

        $this->renderDocumentAsia($pdf, [
            'cmrData' => $cmrData,
            'cmrPackageData' => $cmrPackageData,
            'wzData' => $wzData]);

        return ['file_string' => $pdf, "filename" => 'CMR_' . $cmrData['doc_num'] . '.pdf'];
    }

    public function generateDocumentPDF($id): array
    {
        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);

        $documentData = $this->buildDocumentFromId($pdf, $id);
        
//        return [
//            'filename' => $documentData['filename'],
//            'file_string' => $documentData['file_string']->Output('', 'I')
//        ];
        return [
            'filename' => $documentData['filename'],
            'file_string' => $documentData['file_string']->Output('', 'S')
        ];
    }

    public function generateDocumentPDFAsia($id): array
    {
        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);

        $documentData = $this->buildDocumentFromIdAsia($pdf, $id);

//        return [
//            'filename' => $documentData['filename'],
//            'file_string' => $documentData['file_string']->Output('', 'I')
//        ];
        return [
            'filename' => $documentData['filename'],
            'file_string' => $documentData['file_string']->Output('', 'I')
        ];
    }

}