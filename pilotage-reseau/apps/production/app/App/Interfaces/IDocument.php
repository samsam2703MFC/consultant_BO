<?php
namespace App\Interfaces;

use App\Services\Utils\TCPDFService;

interface IDocument
{
    public function renderDocument(TCPDFService $pdf, array $data): TCPDFService;
    public function buildDocumentFromId(TCPDFService $pdf, int $id): array;
    public function generateDocumentPDF(int $id): array;

}