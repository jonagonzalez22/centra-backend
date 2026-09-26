<?php

namespace App\Http\Requests\Concerns;

use App\Support\QuantityMath;

trait NormalizesDecimalQuantities
{
    /**
     * Normalize valid integer/string quantity inputs before they reach services.
     * Invalid values are deliberately left intact for the validation rule.
     *
     * @param  array<int, string>  $paths
     */
    protected function normalizeDecimalQuantities(array $paths): void
    {
        $payload = $this->all();

        foreach ($paths as $path) {
            $this->normalizeDecimalQuantityPath($payload, explode('.', $path));
        }

        $this->replace($payload);
    }

    /** @param array<string|int, mixed> $payload @param array<int, string> $segments */
    private function normalizeDecimalQuantityPath(array &$payload, array $segments): void
    {
        $segment = array_shift($segments);
        if ($segment === null) {
            return;
        }

        if ($segment === '*') {
            foreach ($payload as &$value) {
                if (is_array($value)) {
                    $this->normalizeDecimalQuantityPath($value, $segments);
                }
            }
            unset($value);

            return;
        }

        if ($segments === []) {
            if (! array_key_exists($segment, $payload) || (! is_int($payload[$segment]) && ! is_string($payload[$segment]))) {
                return;
            }

            try {
                $payload[$segment] = QuantityMath::normalize($payload[$segment]);
            } catch (\InvalidArgumentException) {
                // The validation rule returns the user-facing error.
            }

            return;
        }

        if (isset($payload[$segment]) && is_array($payload[$segment])) {
            $this->normalizeDecimalQuantityPath($payload[$segment], $segments);
        }
    }
}
