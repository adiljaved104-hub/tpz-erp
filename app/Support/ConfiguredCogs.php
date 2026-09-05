<?php

namespace App\Support;

final class ConfiguredCogs
{
    public static function sql(string $fulfillmentItemAlias): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fulfillmentItemAlias)) {
            throw new \InvalidArgumentException('Invalid fulfilment-item SQL alias.');
        }

        return "COALESCE((SELECT upgrade_cogs.final_configured_cogs FROM order_upgrade_executions upgrade_cogs WHERE upgrade_cogs.order_fulfillment_item_id = {$fulfillmentItemAlias}.id), {$fulfillmentItemAlias}.cogs_total)";
    }
}
