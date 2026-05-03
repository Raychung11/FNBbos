<?php
declare(strict_types=1);

namespace FNBBOS\Engine\PlatformAdapters;

/**
 * ShopeeFood Merchant API adapter — STUB. See GrabFoodAdapter for the
 * implementation pattern.
 */
final class ShopeeFoodAdapter implements AdapterInterface
{
    public function __construct(private array $credentials)
    {
        throw new \RuntimeException(
            'ShopeeFood adapter is not implemented yet. Provide partner ' .
            'credentials and replace src/Engine/PlatformAdapters/ShopeeFoodAdapter.php'
        );
    }

    public function name(): string { return 'shopeefood'; }

    public function fetchOrders(string $from, string $to): array
    {
        return [];
    }
}
