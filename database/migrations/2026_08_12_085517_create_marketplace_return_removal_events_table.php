<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TABLE marketplace_return_removal_events (id INTEGER PRIMARY KEY AUTOINCREMENT, marketplace_return_removal_id INTEGER NOT NULL, from_status VARCHAR NULL CHECK(from_status IS NULL OR from_status IN ('draft','requested','dispatched','received','cancelled')), to_status VARCHAR NOT NULL CHECK(to_status IN ('draft','requested','dispatched','received','cancelled')), actor_user_id INTEGER NOT NULL, reason TEXT NULL, created_at DATETIME NOT NULL, FOREIGN KEY(marketplace_return_removal_id) REFERENCES marketplace_return_removals(id) ON DELETE RESTRICT, FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
            DB::statement('CREATE INDEX marketplace_removal_events_created_index ON marketplace_return_removal_events(marketplace_return_removal_id,created_at)');

            return;
        }
        Schema::create('marketplace_return_removal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_return_removal_id');
            $table->foreign('marketplace_return_removal_id', 'mrr_events_removal_fk')->references('id')->on('marketplace_return_removals')->restrictOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
            $table->index(['marketplace_return_removal_id', 'created_at'], 'marketplace_removal_events_created_index');
        });
        DB::statement("ALTER TABLE marketplace_return_removal_events ADD CONSTRAINT marketplace_removal_event_status_check CHECK((from_status IS NULL OR from_status IN ('draft','requested','dispatched','received','cancelled')) AND to_status IN ('draft','requested','dispatched','received','cancelled'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('marketplace_return_removal_events') && DB::table('marketplace_return_removal_events')->exists()) {
            throw new RuntimeException('Rollback refused: marketplace removal events exist.');
        }Schema::dropIfExists('marketplace_return_removal_events');
    }
};
