<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')->nullable();
            $table->index('created_by_user_id', 'products_creator_idx');
            $table->foreign('created_by_user_id', 'products_creator_fk')->references('id')->on('users')->nullOnDelete();
        });

        // Only original creation events prove attribution. Accept the current
        // morph alias and its historical class name; never use editor/stock data.
        DB::table('products')->whereNull('created_by_user_id')->select('id')->chunkById(500, function ($products): void {
            $creators = DB::table('activity_logs as creation_logs')
                ->join('users as creator_users', 'creator_users.id', '=', 'creation_logs.actor_user_id')
                ->where('creation_logs.event', 'product.created')
                ->whereIn('creation_logs.subject_type', ['product', 'App\\Models\\Product'])
                ->whereIn('creation_logs.subject_id', $products->pluck('id'))
                ->orderBy('creation_logs.created_at')->orderBy('creation_logs.id')
                ->get(['creation_logs.subject_id', 'creation_logs.actor_user_id'])->unique('subject_id');

            foreach ($creators as $creator) {
                DB::table('products')->where('id', $creator->subject_id)->whereNull('created_by_user_id')
                    ->update(['created_by_user_id' => $creator->actor_user_id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['created_by_user_id'] : 'products_creator_fk');
            $table->dropIndex('products_creator_idx');
            $table->dropColumn('created_by_user_id');
        });
    }
};
