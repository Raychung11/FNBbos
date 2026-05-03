<?php
declare(strict_types=1);

namespace FNBBOS\Engine\PlatformAdapters;

/**
 * Contract every platform adapter implements. fetchOrders() returns raw
 * orders in the same shape Importer::ingestCsv expects, so the adapter
 * output can be persisted with the same Fee + Tax engine pipeline.
 */
interface AdapterInterface
{
    public function name(): string;

    /**
     * Pull orders for [from .. to] inclusive.
     *
     * @return array<int,array{
     *   order_id:string, order_date:string, gross_sales:float,
     *   item_subtotal?:float, service_charge?:float, sst_amount?:float,
     *   discount?:float, voucher?:float, refund?:float,
     *   platform_commission?:float, payment_fee?:float, delivery_fee?:float,
     *   adjustment?:float, net_settlement?:float,
     *   settlement_date?:?string, bank_reference?:?string, outlet_code:string
     * }>
     */
    public function fetchOrders(string $from, string $to): array;
}
