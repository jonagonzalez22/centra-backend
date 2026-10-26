<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24mm 14mm 20mm; }
        * { box-sizing: border-box; }
        body { color: #1f2937; font-family: DejaVu Sans, sans-serif; font-size: 10.5px; line-height: 1.45; }
        h1, h2, p { margin: 0; }
        .header { border-bottom: 2px solid #093764; padding-bottom: 13px; }
        .store-name { color: #093764; font-size: 16px; font-weight: bold; }
        .store-meta { color: #4b5563; font-size: 9.5px; margin-top: 4px; }
        .document-title { color: #093764; font-size: 20px; font-weight: bold; text-align: right; }
        .purchase-number { font-size: 11px; font-weight: bold; text-align: right; margin-top: 4px; }
        .notice { border: 1px solid #d6a000; color: #7a4d00; background: #fff8de; font-size: 10px; font-weight: bold; margin-top: 14px; padding: 8px 10px; text-align: center; }
        .cancelled { background: #fff0f0; border-color: #b91c1c; color: #991b1b; font-size: 17px; letter-spacing: 1px; }
        .details { margin: 19px 0; width: 100%; }
        .details td { vertical-align: top; width: 50%; }
        .label { color: #6b7280; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .value { font-size: 11.5px; font-weight: bold; margin-top: 3px; }
        table.items { border-collapse: collapse; width: 100%; }
        .items th { background: #093764; color: white; font-size: 9.5px; padding: 8px 6px; text-align: left; }
        .items th.right, .items td.right { text-align: right; }
        .items td { border-bottom: 1px solid #d1d5db; font-size: 10.5px; padding: 10px 6px; vertical-align: top; }
        .items tr { page-break-inside: avoid; }
        .product-name { font-size: 11px; font-weight: bold; }
        .muted { color: #6b7280; font-size: 9.5px; }
        .total { margin-top: 14px; margin-left: auto; width: 38%; }
        .total td { border-top: 1px solid #093764; padding: 9px 6px; }
        .total .amount { color: #093764; float: right; font-size: 14px; font-weight: bold; }
        .notes { border-top: 1px solid #d1d5db; margin-top: 24px; padding-top: 10px; }
        .footer { bottom: -10mm; color: #6b7280; font-size: 8px; position: fixed; text-align: center; width: 100%; }
    </style>
</head>
<body>
    <table class="header" width="100%"><tr><td width="60%">
        <div class="store-name">{{ $store->name }}</div>
        @php($storeIdentity = array_filter([$store->cuit ? 'CUIT '.$store->cuit : null, $store->address]))
        @php($storeContact = array_filter([$store->phone, $store->email]))
        @if ($storeIdentity)<div class="store-meta">{{ implode(' · ', $storeIdentity) }}</div>@endif
        @if ($storeContact)<div class="store-meta">{{ implode(' · ', $storeContact) }}</div>@endif
    </td><td width="40%">
        <div class="document-title">ORDEN DE COMPRA</div>
        <div class="purchase-number">{{ $purchase->purchase_number }}</div>
    </td></tr></table>

    @if ($isCancelled)<div class="notice cancelled">CANCELADA</div>@elseif ($isDraft)<div class="notice">BORRADOR<br><span style="font-weight: normal;">Documento preliminar sujeto a confirmación.</span></div>@endif

    <table class="details"><tr><td>
        <div class="label">Proveedor</div><div class="value">{{ $purchase->supplier_business_name }}</div>
        @if($purchase->supplier_tax_id)<div class="muted">CUIT {{ $purchase->supplier_tax_id }}</div>@endif
    </td><td style="text-align:right;">
        <div class="label">{{ $isDraft ? 'Generado el' : 'Fecha de orden' }}</div>
        <div class="value">{{ $isDraft ? $generatedAt->format('d/m/Y H:i') : $relevantDate?->format('d/m/Y') }}</div>
    </td></tr></table>

    <table class="items"><thead><tr><th width="39%">Producto</th><th width="18%">Código proveedor</th><th class="right" width="14%">Cantidad</th><th class="right" width="14%">Precio unitario</th><th class="right" width="15%">Subtotal</th></tr></thead><tbody>
        @foreach ($purchase->items as $item)<tr>
            @php($productName = trim((string) $item->supplier_product_name) ?: $item->product_name)
            <td><span class="product-name">{{ $productName }}</span></td>
            <td>{{ $item->supplier_code ?: '—' }}</td>
            <td class="right">{{ \App\Support\DocumentNumberFormatter::quantityWithUnit($item->quantity_ordered, $item->measurement_unit_code, $item->measurement_unit_symbol) }}</td>
            <td class="right">{{ \App\Support\DocumentNumberFormatter::money($item->unit_price) }}</td>
            <td class="right">{{ \App\Support\DocumentNumberFormatter::money($item->subtotal) }}</td>
        </tr>@endforeach
    </tbody></table>
    <table class="total"><tr><td><strong>TOTAL</strong><span class="amount">{{ \App\Support\DocumentNumberFormatter::money($purchase->total) }}</span></td></tr></table>
    @if($purchase->notes)<div class="notes"><div class="label">Notas</div><p>{{ $purchase->notes }}</p></div>@endif
    <div class="footer">Generado por CENTRA - {{ $generatedAt->format('d/m/Y H:i') }}</div>
</body></html>
