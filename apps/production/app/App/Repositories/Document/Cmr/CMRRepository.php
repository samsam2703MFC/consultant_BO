<?php

namespace App\Repositories\Document\Cmr;

use App\Core\Helpers\BaseRepository;
use App\Repositories\Document\DocumentRepository;
use MYPDFModel;

/**
 * ShipmentLogModel class
 */
class CMRRepository extends BaseRepository
{


    protected string $table = 'document_cmr';

    protected $allowedColumns = [
        'doc_num',
        'id_document_wz',
        'id_customer',
        'id_carrier',
        'id_carrier_sec',
        'consignor_name',
        'consignor_address_1',
        'consignor_address_2',
        'consignee_name',
        'consignee_address_1',
        'consignee_address_2',
        'delivery_place',
        'taking_over_place',
        'carrier_name',
        'carrier_address_1',
        'carrier_address_2',
        'carrier_regis_num',
        'id_carrier_vehicle',
        'carrier_sec_name',
        'carrier_sec_address_1',
        'carrier_sec_address_2',
        'carrier_sec_regis_num',
        'id_carrier_sec_vehicle',
        'sender_instructions',
        'established_in',
        'established_date'
    ];

    public function getPDF($data)
    {
        $cmrProductModel = new CMRPackagesRepository();
        $wzModel = new WzModel();

        $cmr_data = $this->first(['id' => $data['id_wz']]);
        $cmrProductModel->order_column = 'name';
        $cmr_package_data = $cmrProductModel->where(["id_document_cmr" => $data['id_cmr']]);
        $wzNum = $wzModel->first(['id' => $cmr_data->id_document_wz]);

        $pdf = new MYPDFModel('P', 'mm', 'a4', true, 'UTF-8', false);
        $pdf->setPrintFooter(false);
        $pdf->setPrintHeader(false);
        $pdf->AddPage();

        $pdf->SetFont('dejavusans', '', 6);

        $text = "(Imię, nazwisko lub nazwa, adres, kraj) \n(name, address, country)";
        $pdf->createHederToCell(1, $text);

        $text = "MIĘDZYNARODOWY SAMOCHODOWY LIST PRZEWOZOWY \nINTERNATIONAL CONSIGNEMENT NOTE";
        $pdf->createHederToCell('', $text);
        $pdf->Ln(6);

        $consignor_data = "{$cmr_data->consignor_name}<br>{$cmr_data->consignor_address_1}<br>
                    {$cmr_data->consignor_address_2}";
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(95, 16, "<b>$consignor_data</b>", 'LRB', 'C', 0, 0, '', '', true, false, true);


        $pdf->SetFont('dejavusans', '', 5);
        $pdf->MultiCell(6, 16, "", 'LB', "C", 0, '', '', '', true, false, true, 'M');
        $text = "Numer dokumentu: <b>{$cmr_data->doc_num}</b><br>Niniejszy przewóz podlega postanowieniom konwencji o umowie międzynarodowej przewozu drogowego towarów (CMR) bez względu na jakąkolwiek przeciwną klauzulę";
        $pdf->MultiCell(42, 16, $text, 'B', 'J', 0, 0, '', '', true, false, true);


        $pdf->MultiCell(5, 16, "", 'B', 'J', 0, 0, '', '', true);
        $text = "CMR num.: <b>{$cmr_data->doc_num}</b><br>This carriage is subject, not with standing any clause to the contrary, to the Convention on the Contract for the international Carriage of goods by road (CMR)";
        $pdf->MultiCell(42, 16, $text, 'BR', 'J', 0, 0, '', '', true, false, true);
        $pdf->Ln(16);


        $text = "Odbiorca (nazwisko lub nazwa, adres, kraj)\nConsignee (name, address, country)";
        $pdf->createHederToCell(2, $text);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $text = "Przewoźnik (nazwisko lub nazwa, adres, kraj)\nCarrier (name, address, country)";
        $pdf->createHederToCell(16, $text);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));
        $pdf->Ln(6);


        $consignee_data = "{$cmr_data->consignee_name}<br>{$cmr_data->consignee_address_1}<br>{$cmr_data->consignee_address_2}";
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->MultiCell(95, 16, $consignee_data, 'LRB', 'L', 0, 0, '', '', true, false, true);
        $pdf->SetFont('dejavusans', '', 9);

        $carrier = "{$cmr_data->carrier_name}<br>{$cmr_data->carrier_address_1}<br>{$cmr_data->carrier_address_2}<br><b>{$cmr_data->carrier_regis_num}</b>";

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
        $pdf->MultiCell(95, 9, $cmr_data->delivery_place, 'LRB', 'L', 0, 0, '', '', true, false, true);


        $carrier_sec = "{$cmr_data->carrier_sec_name}<br>{$cmr_data->carrier_sec_address_1}, {$cmr_data->carrier_sec_address_2}<br><b>{$cmr_data->carrier_sec_regis_num}</b>";

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

        $pdf->MultiCell(95, 9, "<b>{$cmr_data->taking_over_place}</b>", 'LRB', 'C', 0, 0, '', '', true, false, true);

        $pdf->SetLineStyle(array('width' => 0.6, 'color' => array(0, 0, 0)));
        $pdf->MultiCell(95, 24, $cmr_data->carrier_reservation_observation, 'LRB', 'L', 0, 0, '', '', true, false, true);
        $pdf->SetLineStyle(array('width' => 0, 'color' => array(0, 0, 0)));

        $pdf->Ln(9);
        $text = "Załączam dokumenty\nDocuments attached";
        $pdf->createHederToCell(5, $text);
        $pdf->Ln(6);

        $pdf->MultiCell(95, 9, $wzNum->doc_num, 'LRB', 'C', 0, 0, '', '', true, false, true);
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

        $pdf->MultiCell(109, 5, "", 1, 0, 0, '', '', '', true);
        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
        $pdf->MultiCell(27, 5, '', 1, 0, 0, '', '', '', true);
        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', '', 8);

        $pdf->MultiCell(27, 56, 'Cartons', 'LTB', 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, $cmr_package_data[0]->quantity, 0, 'L', 0, '', '', '', true, '', true);


        $pdf->MultiCell(27, 56, $cmr_package_data[0]->name, 0, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(28, 56, $cmr_package_data[0]->product_type, 0, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, $cmr_package_data[0]->stats_num, 1, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, "{$cmr_package_data[0]->gross_weight} kg", 1, 'L', 0, '', '', '', true, '', true);
        $pdf->MultiCell(27, 56, '', 1, 'L', 0, '', '', '', true, '', true);
        $pdf->Ln(50);
        $pdf->SetFont('dejavusans', '', 6);


        $pdf->MultiCell(14, 6, "<b>Klasa<br>Class</b>", 'LTB', 'L', 0, ',', '', '', true, '', true);
        $pdf->MultiCell(14, 6, '', 'TB', 'C', 0, ',', '', '', true, '', true);
        $pdf->MultiCell(14, 6, "<b>Nr UN<br>Number</b>", 'TB', 'L', 0, ',', '', '', true, '', true);
        $pdf->MultiCell(13, 6, '', 'TB', 0, 0, ',', '', '', true, '', true);
        $pdf->MultiCell(14, 6, "<b>GP<br>PG</b>", 'TB', 'L', 0, 0, '', '', true, '', true);
        $pdf->MultiCell(13, 6, '', 'TB', 0, 0, 0, '', '', true, '', true);
        $pdf->MultiCell(27, 6, '', 'RTB', 0, 0, '', '', '', true);
        $pdf->Ln(6);

        $text = "Instrukcje nadawcy\nSender's instructions";
        $pdf->createHederToCell(13, $text);

        $text = "Postanowienia specjalne\nSpecial agreements";
        $pdf->createHederToCell(19, $text);
        $pdf->Ln(6);

        $sender_instr = str_replace('\n', "<br>", $cmr_data->sender_instructions);

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
        $pdf->MultiCell(36, 4, $cmr_data->established_in, 'B', "", 0, 0, '', '', true);

        $pdf->MultiCell(53, 4, $cmr_data->established_date, 'BR', "", 0, 0, '', '', true);

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

        $base = $pdf->Output('CMR.pdf', 'S');
        $filename = "CMR_" . date("d_m_Y");

        $documentModel = new DocumentRepository();
        $documentModel->saveDocument($filename, $base);
    }

    public function insertSelect($data)
    {
        $sql = "INSERT INTO `document_cmr`(
                           `doc_num`, 
                           `id_document_wz`, 
                           `id_customer`, 
                           `id_carrier`, 
                           `id_carrier_sec`, 
                           `consignor_name`, 
                           `consignor_address_1`, 
                           `consignor_address_2`, 
                           `consignee_name`, 
                           `consignee_address_1`, 
                           `consignee_address_2`, 
                           `delivery_place`, 
                           `taking_over_place`, 
                           `carrier_name`, 
                           `carrier_address_1`, 
                           `carrier_address_2`, 
                           `carrier_regis_num`, 
                           `id_carrier_vehicle`, 
                           `carrier_sec_name`, 
                           `carrier_sec_address_1`, 
                           `carrier_sec_address_2`, 
                           `carrier_sec_regis_num`, 
                           `id_carrier_sec_vehicle`, 
                           `sender_instructions`, 
                           `carrier_reservation_observation`, 
                           `established_in`,
                           `established_date`) 
                        SELECT 
                            COUNT(doc_num)+1,
                            :id_document_wz,
                            :id_customer,
                            :id_carrier,
                            :id_carrier_sec,
                            :consignor_name,
                            :consignor_address_1,
                            :consignor_address_2,
                            :consignee_name,
                            :consignee_address_1,
                            :consignee_address_2,
                            :delivery_place,
                            :taking_over_place,
                            :carrier_name,
                            :carrier_address_1,
                            :carrier_address_2,
                            :carrier_regis_num,
                            :id_carrier_vehicle,
                            :carrier_sec_name,
                            :carrier_sec_address_1,
                            :carrier_sec_address_2,
                            :carrier_sec_regis_num,
                            :id_carrier_sec_vehicle,
                            :sender_instructions,
                            :carrier_reservation_observation,
                            :established_in,
                            :established_date
                        FROM document_cmr";
        return $this->customInsert($sql, $data);
    }

}