<?php

use App\Services\Notifications\NotificationRuleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
CREATE TABLE notification_rules (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 event_key VARCHAR(100) NOT NULL UNIQUE,
 name VARCHAR(255) NOT NULL,
 category VARCHAR(80) NOT NULL,
 enabled INTEGER NOT NULL DEFAULT 1,
 in_app_enabled INTEGER NOT NULL DEFAULT 1,
 email_enabled INTEGER NOT NULL DEFAULT 1,
 recipient_strategy VARCHAR(80) NOT NULL,
 threshold_value INTEGER NULL,
 threshold_unit VARCHAR(20) NULL,
 configuration TEXT NULL,
 updated_by_user_id INTEGER NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK (enabled IN (0,1) AND in_app_enabled IN (0,1) AND email_enabled IN (0,1)),
 CHECK (enabled = 0 OR in_app_enabled = 1 OR email_enabled = 1),
 CHECK (threshold_value IS NULL OR threshold_value >= 0)
)
SQL);
            DB::statement('CREATE INDEX notification_rules_category_index ON notification_rules(category)');
            DB::statement('CREATE INDEX notification_rules_enabled_category_index ON notification_rules(enabled, category)');
            DB::statement(<<<'SQL'
CREATE TABLE notification_rule_deliveries (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 event_key VARCHAR(100) NOT NULL,
 deduplication_key VARCHAR(191) NOT NULL,
 recipient_user_id INTEGER NOT NULL,
 channel VARCHAR(20) NOT NULL CHECK (channel = 'email'),
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(recipient_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 UNIQUE(event_key, deduplication_key, recipient_user_id, channel)
)
SQL);
            DB::statement('CREATE INDEX notification_rule_deliveries_recipient_created_index ON notification_rule_deliveries(recipient_user_id, created_at)');
        } else {
            Schema::create('notification_rules', function (Blueprint $table): void {
                $table->id();
                $table->string('event_key', 100)->unique();
                $table->string('name');
                $table->string('category', 80)->index();
                $table->boolean('enabled')->default(true);
                $table->boolean('in_app_enabled')->default(true);
                $table->boolean('email_enabled')->default(true);
                $table->string('recipient_strategy', 80);
                $table->unsignedInteger('threshold_value')->nullable();
                $table->string('threshold_unit', 20)->nullable();
                $table->json('configuration')->nullable();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->index(['enabled', 'category']);
            });

            Schema::create('notification_rule_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->string('event_key', 100);
                $table->string('deduplication_key', 191);
                $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
                $table->string('channel', 20);
                $table->timestamps();
                $table->unique(['event_key', 'deduplication_key', 'recipient_user_id', 'channel'], 'notification_rule_delivery_unique');
                $table->index(['recipient_user_id', 'created_at']);
            });

            DB::statement('ALTER TABLE notification_rules ADD CONSTRAINT notification_rules_channels_check CHECK (enabled = 0 OR in_app_enabled = 1 OR email_enabled = 1)');
            DB::statement('ALTER TABLE notification_rules ADD CONSTRAINT notification_rules_threshold_check CHECK (threshold_value IS NULL OR threshold_value >= 0)');
            DB::statement("ALTER TABLE notification_rule_deliveries ADD CONSTRAINT notification_rule_deliveries_channel_check CHECK (channel IN ('email'))");
        }

        $now = now();
        $rows = [];
        foreach (app(NotificationRuleCatalog::class)->all() as $eventKey => $definition) {
            $rows[] = Arr::except($definition, ['recipient_options', 'configuration']) + [
                'event_key' => $eventKey,
                'configuration' => $definition['configuration'] === [] ? null : json_encode($definition['configuration'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('notification_rules')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rule_deliveries');
        Schema::dropIfExists('notification_rules');
    }
};
