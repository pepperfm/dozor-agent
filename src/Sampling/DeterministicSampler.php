<?php

declare(strict_types=1);

namespace Dozor\Sampling;

use Illuminate\Support\Arr;

use function hash;
use function hexdec;
use function is_numeric;
use function max;
use function min;

final readonly class DeterministicSampler
{
    /**
     * @param array<string, mixed> $rates
     */
    public function __construct(private array $rates)
    {
    }

    public function shouldSample(string $channel, string $key): bool
    {
        $rate = $this->resolveRate($channel);
        if ($rate <= 0.0) {
            return false;
        }

        if ($rate >= 1.0) {
            return true;
        }

        $hash = hash('xxh128', $channel . '|' . $key);
        $slice = substr($hash, 0, 8);
        $bucket = hexdec($slice) / 0xFFFFFFFF;

        return $bucket <= $rate;
    }

    public function resolveRate(string $channel): float
    {
        $value = Arr::get($this->rates, $channel, 1.0);
        if (!is_numeric($value)) {
            return 1.0;
        }

        return max(0.0, min(1.0, (float) $value));
    }
}
