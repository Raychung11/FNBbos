<?php
declare(strict_types=1);

namespace FNBBOS\Engine\PlatformAdapters;

/**
 * Foodpanda Vendor API adapter — STUB. See GrabFoodAdapter for the
 * implementation pattern.
 */
final class FoodpandaAdapter implements AdapterInterface
{
    public function __construct(private array $credentials)
    {
        throw new \RuntimeException(
            'Foodpanda adapter is not implemented yet. Provide partner ' .
            'credentials and replace src/Engine/PlatformAdapters/FoodpandaAdapter.php'
        );
    }

    public function name(): string { return 'foodpanda'; }

    public function fetchOrders(string $from, string $to): array
    {
        return [];
    }
}
