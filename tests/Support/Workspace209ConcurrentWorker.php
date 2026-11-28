<?php

use App\Actions\Orders\SaveAndReserveOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\Models\Order;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\Orders\OrderReservationReleaseService;
use App\Services\Responsibilities\ResponsibilityStockTransitionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'tpz_invoice_design_ci_test') {
    throw new RuntimeException('Disposable MySQL worker only.');
}
config(['reference_sequences.enforce_transaction_guard' => true]);
$actor = User::with('employee')->findOrFail((int) $argv[2]);
try {
    if ($argv[1] === 'release') {
        app(OrderReservationReleaseService::class)->release(Order::findOrFail((int) $argv[3]), $actor, 'Synthetic concurrent release', $argv[4]);
    } elseif ($argv[1] === 'create') {
        $data = new SaveAndReserveOrderData((int) $argv[3], (int) $argv[4], $argv[5], today()->toDateString(), $actor->employee->id, null, [new OrderItemData((int) $argv[6], 1, '150.00')], (string) Str::uuid());
        app(SaveAndReserveOrder::class)->handle($data, $actor);
    } elseif ($argv[1] === 'brand') {
        app(ResponsibilityStockTransitionService::class)->transfer(
            ResponsibilityAssignment::findOrFail((int) $argv[3]),
            new TransferResponsibilityAssignmentData((int) $argv[4], 'Synthetic concurrent brand handoff', $argv[5]),
            $actor, json_decode($argv[6], true, 512, JSON_THROW_ON_ERROR),
        );
    } else {
        throw new RuntimeException('Unsupported worker operation');
    }
    echo "PASS\n";
} catch (ValidationException) {
    echo "VALIDATION_REJECTED\n";
    exit(2);
}
