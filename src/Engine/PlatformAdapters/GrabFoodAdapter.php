<?php
declare(strict_types=1);

namespace FNBBOS\Engine\PlatformAdapters;

/**
 * GrabFood Merchant API adapter — STUB.
 *
 * GrabFood Partner APIs require a signed merchant agreement. Until the
 * tenant has credentials, this stub throws so the sync UI surfaces a clear
 * "not implemented" error rather than silently doing nothing.
 *
 * To go live:
 *  1. Obtain partner credentials (client_id, client_secret, store_id) from
 *     Grab.
 *  2. Replace fetchOrders() with calls to the order/settlement endpoints
 *     and map the response into the array shape declared in
 *     AdapterInterface.
 *  3. Drop the throw in __construct.
 */
final class GrabFoodAdapter implements AdapterInterface
{
    public function __construct(private array $credentials)
    {
        throw new \RuntimeException(
            'GrabFood adapter is not implemented yet. Provide partner ' .
            'credentials and replace src/Engine/PlatformAdapters/GrabFoodAdapter.php'
        );
    }

    public function name(): string { return 'grabfood'; }

    public function fetchOrders(string $from, string $to): array
    {
        return [];
    }
}
