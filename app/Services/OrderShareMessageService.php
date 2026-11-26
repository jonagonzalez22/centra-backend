<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\MeasurementUnit;
use App\Models\OperationItem;
use App\Support\MoneyMath;
use App\Support\OperationItemCommercialConfiguration;
use App\Support\QuantityMath;
use Illuminate\Support\Collection;

/** Builds the customer-facing representation from persisted order snapshots only. */
final class OrderShareMessageService
{
    public function build(CommercialOperation $order, string $storeName): string
    {
        /** @var Collection<int, OperationItem> $items */
        $items = $order->items
            ->filter(fn (OperationItem $item): bool => QuantityMath::isPositive($item->quantity))
            ->values();
        $commercialConfigurations = $items->mapWithKeys(fn (OperationItem $item): array => [
            $item->id => OperationItemCommercialConfiguration::resolve($item),
        ]);
        $unitNames = MeasurementUnit::query()
            ->whereIn('code', $commercialConfigurations
                ->pluck('stock_measurement_unit.code')
                ->filter()
                ->unique())
            ->pluck('name', 'code');

        $lines = $items->map(function (OperationItem $item) use ($commercialConfigurations, $unitNames): string {
            $code = trim((string) $item->product?->sku);
            $measurementUnit = $commercialConfigurations->get($item->id)['stock_measurement_unit'] ?? null;
            $unitCode = $measurementUnit['code'] ?? null;
            $quantity = $this->formatQuantityWithUnit(
                $item->quantity,
                $unitNames->get($unitCode),
                $measurementUnit['symbol'] ?? null,
            );
            $productName = trim((string) $item->product_name) ?: 'Producto sin nombre';
            $heading = ($code === '' ? '' : '['.$code.'] ').'*'.$productName.'*';

            return implode("\n", [
                $heading,
                $quantity.' — '.$this->formatMoney($this->lineTotal($item)),
            ]);
        });

        return implode("\n", [
            '*'.(trim($storeName) ?: 'Tienda').'*',
            'Pedido *'.$order->operation_number.'*',
            'Cliente: *'.(trim((string) $order->customer_display_name) ?: 'Consumidor final').'*',
            '',
            '──────────────────────────────',
            '*Detalle del pedido:*',
            '',
            $lines->implode("\n\n"),
            '',
            '*Total del pedido: '.$this->formatMoney($order->total).'*',
            '',
            '──────────────────────────────',
            '',
            '_¡Gracias por tu compra!_',
        ]);
    }

    private function lineTotal(OperationItem $item): string
    {
        return MoneyMath::subtract(
            MoneyMath::add($item->subtotal, $item->tax_amount),
            $item->discount_amount,
        );
    }

    private function formatQuantityWithUnit(
        string $quantity,
        ?string $unitName,
        ?string $historicalSymbol,
    ): string {
        $normalized = QuantityMath::normalize($quantity);
        $isSingular = QuantityMath::compare($normalized, '1') === 0;
        $name = trim((string) $unitName);

        $unit = $name === ''
            ? trim((string) $historicalSymbol)
            : ($isSingular ? mb_strtolower($name) : $this->pluralizeSpanish($name));

        return trim($this->formatDecimal($normalized).' '.$unit);
    }

    private function formatMoney(string $amount): string
    {
        return '$'.$this->formatDecimal(MoneyMath::normalize($amount), 2);
    }

    private function formatDecimal(string $value, ?int $fixedDecimals = null): string
    {
        $negative = str_starts_with($value, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '+-'), 2), 2, '');
        $fraction = $fixedDecimals === null ? rtrim($fraction, '0') : str_pad(substr($fraction, 0, $fixedDecimals), $fixedDecimals, '0');
        $groupedInteger = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer) ?? $integer;

        return ($negative ? '-' : '').$groupedInteger.($fraction === '' ? '' : ','.$fraction);
    }

    private function pluralizeSpanish(string $name): string
    {
        $name = mb_strtolower($name);

        if (str_ends_with($name, 'z')) {
            return mb_substr($name, 0, -1).'ces';
        }

        return preg_match('/[aeiouáéíóú]$/u', $name) ? $name.'s' : $name.'es';
    }
}
