<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\Store;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class PurchaseOrderPdfService
{
    public function download(Purchase $purchase, Store $store): Response
    {
        abort_unless($purchase->purchase_type === 'order', 422, 'Solo las órdenes de compra pueden descargarse como PDF.');

        $suffix = match ($purchase->status) {
            'draft' => '-BORRADOR',
            'cancelled' => '-CANCELADA',
            default => '',
        };

        return Pdf::loadView('pdf.purchase-order', [
            'purchase' => $purchase,
            'store' => $store,
            'isDraft' => $purchase->status === 'draft',
            'isCancelled' => $purchase->status === 'cancelled',
            'relevantDate' => $purchase->ordered_at ?? $purchase->created_at,
            'generatedAt' => now(),
        ])->setPaper('a4')->download($purchase->purchase_number.$suffix.'.pdf');
    }
}
