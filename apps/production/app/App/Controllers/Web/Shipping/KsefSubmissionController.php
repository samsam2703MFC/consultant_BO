<?php
namespace App\Controllers\Web\Shipping;
use App\Core\Web\BaseController;
use App\Repositories\Ksef\KsefSubmissionRepository;
use App\Services\Ksef\KsefSubmissionService;
use Symfony\Component\HttpFoundation\Response;
final class KsefSubmissionController extends BaseController { public function __construct(private KsefSubmissionService $service, private KsefSubmissionRepository $submissions) {} public function send(int $id) { try { $row=$this->service->sendForShipment($id); return $this->json(['ok'=>true,'message'=>'Faktura została przekazana do przetwarzania w KSeF.','submission'=>$row]); } catch (\Throwable $e) { return $this->json(['ok'=>false,'message'=>$e->getMessage()],422); } } public function refreshStatuses() { try { $result=$this->service->refreshPendingStatuses(); return $this->json(['ok'=>true,'message'=>'Odświeżono statusy KSeF.','result'=>$result]); } catch (\Throwable $e) { return $this->json(['ok'=>false,'message'=>$e->getMessage()],422); } } public function upo(int $id): Response { $submission=$this->submissions->findByShipmentId($id); if (empty($submission['upo_xml'])) return new Response('UPO nie jest jeszcze dostępne.', 404, ['Content-Type'=>'text/plain; charset=UTF-8']); return new Response((string)$submission['upo_xml'], 200, ['Content-Type'=>'application/xml; charset=UTF-8','Content-Disposition'=>'attachment; filename="UPO_KSeF_'.$id.'.xml"']); } }
