<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("ALTER TABLE orders ADD COLUMN web_sales_channel VARCHAR(20) NULL CHECK (web_sales_channel IS NULL OR web_sales_channel IN ('website', 'whatsapp', 'walk_in', 'other'))");
            DB::statement('ALTER TABLE orders ADD COLUMN customer_name VARCHAR NULL');
            DB::statement('ALTER TABLE orders ADD COLUMN customer_phone VARCHAR(40) NULL');
            DB::statement("ALTER TABLE orders ADD COLUMN delivery_type VARCHAR(20) NULL CHECK (delivery_type IS NULL OR delivery_type IN ('courier', 'shop_pickup'))");
            DB::statement('ALTER TABLE orders ADD COLUMN courier_name VARCHAR(100) NULL');
            DB::statement('ALTER TABLE orders ADD COLUMN tracking_number VARCHAR(100) NULL');
            DB::statement('ALTER TABLE orders ADD COLUMN delivered_at DATETIME NULL');
            DB::statement('CREATE INDEX orders_web_sales_channel_date_status_index ON orders (web_sales_channel, order_date, status)');
            DB::statement('CREATE INDEX orders_web_sales_handler_channel_date_index ON orders (handled_by_employee_id, web_sales_channel, order_date)');
            DB::statement('CREATE INDEX orders_customer_phone_index ON orders (customer_phone)');
            DB::statement('CREATE INDEX orders_tracking_number_index ON orders (tracking_number)');

            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('web_sales_channel', 20)->nullable()->after('source');
            $table->string('customer_name')->nullable()->after('external_identity_hash');
            $table->string('customer_phone', 40)->nullable()->after('customer_name');
            $table->string('delivery_type', 20)->nullable()->after('customer_phone');
            $table->string('courier_name', 100)->nullable()->after('delivery_type');
            $table->string('tracking_number', 100)->nullable()->after('courier_name');
            $table->timestamp('delivered_at')->nullable()->after('cancelled_at');

            $table->index(
                ['web_sales_channel', 'order_date', 'status'],
                'orders_web_sales_channel_date_status_index',
            );
            $table->index(
                ['handled_by_employee_id', 'web_sales_channel', 'order_date'],
                'orders_web_sales_handler_channel_date_index',
            );
            $table->index('customer_phone', 'orders_customer_phone_index');
            $table->index('tracking_number', 'orders_tracking_number_index');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_web_sales_channel_check CHECK (web_sales_channel IS NULL OR web_sales_channel IN ('website', 'whatsapp', 'walk_in', 'other'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_delivery_type_check CHECK (delivery_type IS NULL OR delivery_type IN ('courier', 'shop_pickup'))");
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'web_sales_channel')
            && DB::table('orders')->whereNotNull('web_sales_channel')->exists()) {
            throw new RuntimeException('Rollback refused: orders contains Web Sales business records.');
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE orders DROP CHECK orders_web_sales_channel_check');
            DB::statement('ALTER TABLE orders DROP CHECK orders_delivery_type_check');
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_web_sales_channel_date_status_index');
            $table->dropIndex('orders_web_sales_handler_channel_date_index');
            $table->dropIndex('orders_customer_phone_index');
            $table->dropIndex('orders_tracking_number_index');
            $table->dropColumn([
                'web_sales_channel',
                'customer_name',
                'customer_phone',
                'delivery_type',
                'courier_name',
                'tracking_number',
                'delivered_at',
            ]);
        });
    }
};
