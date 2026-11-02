<?php

namespace App\Reporting;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReportContext
{
    public function __construct(
        public string $storeId,
        public string $timezone,
        public CarbonImmutable $fromLocal,
        public CarbonImmutable $toLocal,
        public CarbonImmutable $fromUtc,
        public CarbonImmutable $toUtcExclusive,
    ) {
        if ($storeId === '') {
            throw new InvalidArgumentException('El contexto de informes requiere una tienda.');
        }
    }

    public static function forPeriod(string $storeId, string $timezone, string $dateFrom, string $dateTo): self
    {
        $fromLocal = CarbonImmutable::createFromFormat('!Y-m-d', $dateFrom, $timezone)->startOfDay();
        $toLocal = CarbonImmutable::createFromFormat('!Y-m-d', $dateTo, $timezone)->startOfDay();

        return new self(
            $storeId,
            $timezone,
            $fromLocal,
            $toLocal,
            $fromLocal->utc(),
            $toLocal->addDay()->utc(),
        );
    }
}
