<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
            try {
                DB::transaction(function (): void {
                    $this->rebuildSqliteNotices();
                    $this->rebuildSqliteNoticeTemplates();
                    DB::statement("ALTER TABLE notice_categories ADD COLUMN communication_scope VARCHAR(30) NOT NULL DEFAULT 'both' CHECK (communication_scope IN ('official_notice','announcement','both'))");
                    $this->createWarningTemplatesSqlite();
                    DB::statement('ALTER TABLE employee_warnings ADD COLUMN warning_template_id INTEGER NULL REFERENCES warning_templates(id) ON DELETE RESTRICT');
                    DB::statement('CREATE INDEX employee_warnings_warning_template_id_index ON employee_warnings(warning_template_id)');
                    $this->seedDefaults();
                });
            } finally {
                DB::statement('PRAGMA foreign_keys = ON');
            }

            return;
        }

        Schema::table('hr_notices', function (Blueprint $table): void {
            $table->string('communication_type', 30)->default('official_notice')->index();
            $table->foreignId('created_by_user_id')->nullable()->after('published_by_user_id')->constrained('users')->restrictOnDelete();
            $table->index(['communication_type', 'status', 'published_at', 'expires_at'], 'hr_notices_type_current_index');
        });
        DB::table('hr_notices')->whereNull('created_by_user_id')->update(['created_by_user_id' => DB::raw('published_by_user_id')]);
        DB::statement('ALTER TABLE hr_notices DROP CHECK hr_notices_values_check');
        DB::statement("ALTER TABLE hr_notices ADD CONSTRAINT hr_notices_values_check CHECK (communication_type IN ('official_notice','announcement') AND audience_type IN ('all','team','selected') AND priority IN ('normal','important','high','urgent') AND status IN ('active','archived') AND CHAR_LENGTH(TRIM(title)) > 0 AND CHAR_LENGTH(TRIM(content)) > 0 AND ((audience_type = 'team' AND team_id IS NOT NULL) OR (audience_type <> 'team' AND team_id IS NULL)) AND (expires_at IS NULL OR expires_at >= published_at) AND ((status = 'active' AND archived_at IS NULL AND archived_by_user_id IS NULL) OR (status = 'archived' AND archived_at IS NOT NULL AND archived_by_user_id IS NOT NULL)))");

        Schema::table('notice_categories', function (Blueprint $table): void {
            $table->string('communication_scope', 30)->default('both')->index();
        });
        DB::statement("ALTER TABLE notice_categories ADD CONSTRAINT notice_categories_scope_check CHECK (communication_scope IN ('official_notice','announcement','both'))");
        Schema::table('notice_templates', function (Blueprint $table): void {
            $table->string('communication_type', 30)->default('official_notice')->index();
            $table->dropUnique('notice_templates_notice_category_id_normalized_name_unique');
            $table->unique(['communication_type', 'notice_category_id', 'normalized_name'], 'notice_templates_type_category_name_unique');
            $table->index(['communication_type', 'notice_category_id', 'status'], 'notice_templates_type_category_status_index');
        });
        DB::statement('ALTER TABLE notice_templates DROP CHECK notice_templates_values_check');
        DB::statement("ALTER TABLE notice_templates ADD CONSTRAINT notice_templates_values_check CHECK (communication_type IN ('official_notice','announcement') AND CHAR_LENGTH(TRIM(name)) > 0 AND CHAR_LENGTH(TRIM(default_title)) > 0 AND CHAR_LENGTH(TRIM(default_content)) > 0 AND default_priority IN ('normal','important','high','urgent') AND default_acknowledgment_required IN (0,1) AND status IN (0,1))");

        Schema::create('warning_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warning_category_id')->constrained('warning_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->string('default_title');
            $table->text('default_description');
            $table->string('default_warning_level', 30)->default('verbal');
            $table->boolean('default_acknowledgment_required')->default(true);
            $table->boolean('status')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['warning_category_id', 'normalized_name']);
            $table->index(['warning_category_id', 'status'], 'warning_templates_category_status_index');
        });
        DB::statement("ALTER TABLE warning_templates ADD CONSTRAINT warning_templates_values_check CHECK (default_warning_level IN ('verbal','written','final') AND default_acknowledgment_required IN (0,1) AND status IN (0,1))");
        Schema::table('employee_warnings', function (Blueprint $table): void {
            $table->foreignId('warning_template_id')->nullable()->after('warning_category_id')->constrained('warning_templates')->restrictOnDelete();
        });

        $this->seedDefaults();
    }

    public function down(): void
    {
        throw new RuntimeException('This communication-history migration is forward-only. Preserve all existing notice, announcement, warning, and acknowledgment records.');
    }

    private function rebuildSqliteNotices(): void
    {
        DB::statement("CREATE TABLE hr_notices_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference VARCHAR(25) NOT NULL UNIQUE,
            communication_type VARCHAR(30) NOT NULL DEFAULT 'official_notice',
            notice_category_id INTEGER NULL,
            notice_template_id INTEGER NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            audience_type VARCHAR(20) NOT NULL,
            team_id INTEGER NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            published_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            acknowledgment_required TINYINT(1) NOT NULL DEFAULT 0,
            published_by_user_id INTEGER NOT NULL,
            created_by_user_id INTEGER NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            archived_at DATETIME NULL,
            archived_by_user_id INTEGER NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            CHECK(communication_type IN ('official_notice','announcement') AND audience_type IN ('all','team','selected') AND priority IN ('normal','important','high','urgent') AND status IN ('active','archived') AND length(trim(title)) > 0 AND length(trim(content)) > 0 AND acknowledgment_required IN (0,1) AND ((audience_type = 'team' AND team_id IS NOT NULL) OR (audience_type <> 'team' AND team_id IS NULL)) AND (expires_at IS NULL OR expires_at >= published_at) AND ((status = 'active' AND archived_at IS NULL AND archived_by_user_id IS NULL) OR (status = 'archived' AND archived_at IS NOT NULL AND archived_by_user_id IS NOT NULL))),
            FOREIGN KEY(notice_category_id) REFERENCES notice_categories(id) ON DELETE RESTRICT,
            FOREIGN KEY(notice_template_id) REFERENCES notice_templates(id) ON DELETE RESTRICT,
            FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE RESTRICT,
            FOREIGN KEY(published_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY(archived_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
        )");
        DB::statement("INSERT INTO hr_notices_new (id, reference, communication_type, notice_category_id, notice_template_id, title, content, audience_type, team_id, priority, published_at, expires_at, acknowledgment_required, published_by_user_id, created_by_user_id, status, archived_at, archived_by_user_id, created_at, updated_at)
            SELECT id, reference, 'official_notice', notice_category_id, notice_template_id, title, content, audience_type, team_id, priority, published_at, expires_at, acknowledgment_required, published_by_user_id, published_by_user_id, status, archived_at, archived_by_user_id, created_at, updated_at FROM hr_notices");
        DB::statement('DROP TABLE hr_notices');
        DB::statement('ALTER TABLE hr_notices_new RENAME TO hr_notices');
        DB::statement('CREATE INDEX hr_notices_status_publication_index ON hr_notices(status,published_at,expires_at)');
        DB::statement('CREATE INDEX hr_notices_audience_status_index ON hr_notices(audience_type,team_id,status)');
        DB::statement('CREATE INDEX hr_notices_notice_category_id_index ON hr_notices(notice_category_id)');
        DB::statement('CREATE INDEX hr_notices_notice_template_id_index ON hr_notices(notice_template_id)');
        DB::statement('CREATE INDEX hr_notices_type_current_index ON hr_notices(communication_type,status,published_at,expires_at)');
        DB::statement('CREATE INDEX hr_notices_created_by_user_id_index ON hr_notices(created_by_user_id)');
    }

    private function rebuildSqliteNoticeTemplates(): void
    {
        DB::statement("CREATE TABLE notice_templates_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            notice_category_id INTEGER NOT NULL,
            communication_type VARCHAR(30) NOT NULL DEFAULT 'official_notice',
            name VARCHAR(255) NOT NULL,
            normalized_name VARCHAR(255) NOT NULL,
            default_title VARCHAR(255) NOT NULL,
            default_content TEXT NOT NULL,
            default_priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            default_acknowledgment_required TINYINT(1) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INTEGER NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE(communication_type, notice_category_id, normalized_name),
            CHECK(communication_type IN ('official_notice','announcement') AND length(trim(name)) > 0 AND length(trim(default_title)) > 0 AND length(trim(default_content)) > 0 AND default_priority IN ('normal','important','high','urgent') AND default_acknowledgment_required IN (0,1) AND status IN (0,1)),
            FOREIGN KEY(notice_category_id) REFERENCES notice_categories(id) ON DELETE RESTRICT,
            FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
        )");
        DB::statement("INSERT INTO notice_templates_new (id, notice_category_id, communication_type, name, normalized_name, default_title, default_content, default_priority, default_acknowledgment_required, status, created_by_user_id, created_at, updated_at)
            SELECT id, notice_category_id, 'official_notice', name, normalized_name, default_title, default_content, default_priority, default_acknowledgment_required, status, created_by_user_id, created_at, updated_at FROM notice_templates");
        DB::statement('DROP TABLE notice_templates');
        DB::statement('ALTER TABLE notice_templates_new RENAME TO notice_templates');
        DB::statement('CREATE INDEX notice_templates_category_status_index ON notice_templates(notice_category_id,status)');
        DB::statement('CREATE INDEX notice_templates_type_category_status_index ON notice_templates(communication_type,notice_category_id,status)');
    }

    private function createWarningTemplatesSqlite(): void
    {
        DB::statement("CREATE TABLE warning_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            warning_category_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            normalized_name VARCHAR(255) NOT NULL,
            default_title VARCHAR(255) NOT NULL,
            default_description TEXT NOT NULL,
            default_warning_level VARCHAR(30) NOT NULL DEFAULT 'verbal',
            default_acknowledgment_required TINYINT(1) NOT NULL DEFAULT 1,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INTEGER NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE(warning_category_id, normalized_name),
            CHECK(default_warning_level IN ('verbal','written','final') AND default_acknowledgment_required IN (0,1) AND status IN (0,1)),
            FOREIGN KEY(warning_category_id) REFERENCES warning_categories(id) ON DELETE RESTRICT,
            FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
        )");
        DB::statement('CREATE INDEX warning_templates_category_status_index ON warning_templates(warning_category_id,status)');
    }

    private function seedDefaults(): void
    {
        $categories = [
            'General Announcement', 'HR & Policy', 'Attendance & Timing', 'Leave & Holidays',
            'Office Discipline', 'Performance & Targets', 'Training & Meetings', 'System / ERP Update',
            'IT & Security', 'Sales & Operations', 'Inventory & Stock', 'Marketplace / E-Commerce',
            'Finance & Accounts', 'Compliance & Legal', 'Health & Safety', 'Urgent / Important Notice',
            'Employee Recognition', 'Maintenance / Office Facility',
        ];

        foreach ($categories as $name) {
            $normalized = Str::lower(Str::squish($name));
            DB::table('notice_categories')->insertOrIgnore([
                'name' => $name,
                'normalized_name' => $normalized,
                'communication_scope' => 'both',
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $templates = [
            ['Attendance & Timing', 'Friday Prayer Timing Reminder', 'Friday Prayer Timing', 'Please observe the approved Friday prayer schedule on [Date]. Coordinate coverage with [Employee Name] or your supervisor. [Details]', 'normal', false, 'official_notice'],
            ['Attendance & Timing', 'Early Departure Notice', 'Early Departure Notice — [Date]', 'Please obtain approval before leaving early on [Date]. Notify [Employee Name] and record the approved time: [Time]. [Details]', 'normal', false, 'official_notice'],
            ['Office Discipline', 'Mobile Phone Usage', 'Workplace Mobile Phone Reminder', 'Please follow the company mobile phone and device-use policy during work hours. [Details]', 'normal', false, 'official_notice'],
            ['Office Discipline', 'Workplace Conduct Reminder', 'Workplace Conduct Reminder', 'Please follow the company workplace conduct policy. For questions, contact [Employee Name]. [Details]', 'normal', false, 'official_notice'],
            ['Office Discipline', 'Dress Code Reminder', 'Dress Code Reminder', 'Please follow the applicable dress code on [Date] and during scheduled duties. [Details]', 'normal', false, 'official_notice'],
            ['HR & Policy', 'Attendance Record Integrity', 'Attendance Record Integrity', 'Please review attendance entries for [Date] and report corrections with supporting details by [Deadline]. [Details]', 'normal', true, 'official_notice'],
            ['Leave & Holidays', 'Public Holiday Notice', 'Public Holiday — [Date]', 'The office holiday schedule for [Date] is [Details]. Contact [Employee Name] with questions.', 'normal', false, 'official_notice'],
            ['Sales & Operations', 'Weekend Duty Notice', 'Weekend Duty — [Date]', 'The approved weekend duty schedule for [Date] is [Details]. Please confirm coverage with [Employee Name].', 'normal', true, 'official_notice'],
            ['Training & Meetings', 'Staff Meeting Notice', 'Staff Meeting — [Date]', 'A staff meeting is scheduled for [Date] at [Time]. Topic: [Topic]. Please attend or coordinate with [Employee Name].', 'normal', true, 'official_notice'],
            ['Training & Meetings', 'ERP Training Notice', 'ERP Training — [Topic]', 'ERP training is scheduled for [Date] at [Time]. Please bring questions about [Topic]. [Details]', 'normal', false, 'official_notice'],
            ['Inventory & Stock', 'Physical Stock Count', 'Physical Stock Count — [Date]', 'A physical stock count will take place at [Time] on [Date]. Follow the approved count and reconciliation instructions. [Details]', 'normal', true, 'official_notice'],
            ['Inventory & Stock', 'Stock Adjustment Control Reminder', 'Stock Adjustment Control', 'Stock adjustments require authorization, a valid reason, and the normal inventory controls. Do not bypass approval or recordkeeping. [Details]', 'high', true, 'official_notice'],
            ['Inventory & Stock', 'Damaged / Open Box Stock Follow-Up', 'Damaged / Open Box Stock Follow-Up', 'Please review the identified stock and record the required handling by [Deadline]. Product / SKU: [Product / SKU]. [Details]', 'normal', false, 'official_notice'],
            ['Inventory & Stock', 'Inventory Difference Alert', 'Inventory Difference Review', 'Please review the inventory difference for [Product / SKU] at [Location] and report findings by [Deadline]. [Details]', 'high', true, 'official_notice'],
            ['System / ERP Update', 'ERP Scheduled Maintenance', 'ERP Scheduled Maintenance — [Date]', 'ERP maintenance is planned for [Date] from [Time]. Expected impact: [Details].', 'normal', false, 'official_notice'],
            ['System / ERP Update', 'ERP Service Interruption', 'ERP Service Interruption', 'ERP service is currently affected. Follow the approved contingency process and wait for the next update. [Details]', 'urgent', true, 'official_notice'],
            ['System / ERP Update', 'New ERP Feature / Workflow', 'New ERP Feature — [Topic]', 'A new ERP feature or workflow is available: [Topic]. Review the instructions and contact [Employee Name] with questions. [Details]', 'normal', false, 'announcement'],
            ['IT & Security', 'Password Security Reminder', 'Password Security Reminder', 'Use a unique password, never share verification codes, and report suspected account access immediately. [Details]', 'high', true, 'official_notice'],
            ['IT & Security', 'Phishing / Suspicious Email Alert', 'Phishing / Suspicious Email Alert', 'Do not open unexpected links or attachments. Report the message to [Employee Name] and follow IT instructions. [Details]', 'urgent', true, 'official_notice'],
            ['Marketplace / E-Commerce', 'Marketplace Return Handling', 'Marketplace Return Handling', 'Handle return [Order Reference] according to the approved inspection and evidence process. [Product / SKU] [Details]', 'normal', true, 'official_notice'],
            ['Marketplace / E-Commerce', 'Marketplace SLA Reminder', 'Marketplace SLA Reminder', 'Review pending marketplace work for [Order Reference] and complete the required action by [Deadline]. [Details]', 'high', true, 'official_notice'],
            ['Marketplace / E-Commerce', 'Wrong / Different Item Return Escalation', 'Return Item Mismatch — [Order Reference]', 'A returned item differs from the expected item for [Order Reference]. Preserve evidence and escalate using the approved process. [Details]', 'urgent', true, 'official_notice'],
            ['Sales & Operations', 'Order Processing Reminder', 'Order Processing Reminder', 'Please review the order queue and complete authorized next steps for [Order Reference] by [Deadline]. [Details]', 'normal', false, 'official_notice'],
            ['Sales & Operations', 'Customer Issue Escalation', 'Customer Issue — [Order Reference]', 'Please review the customer issue for [Order Reference] and coordinate the approved response by [Deadline]. [Details]', 'high', true, 'official_notice'],
            ['Finance & Accounts', 'Expense Documentation Reminder', 'Expense Documentation Reminder', 'Please attach the required documentation for [Details] by [Deadline]. Do not include sensitive payment credentials.', 'normal', false, 'official_notice'],
            ['Compliance & Legal', 'Confidentiality Notice', 'Confidentiality Reminder', 'Handle company, customer, employee, and commercial information only through approved channels. [Details]', 'high', true, 'official_notice'],
            ['Urgent / Important Notice', 'Urgent Immediate Action Required', 'Urgent Action Required — [Topic]', 'Action is required by [Deadline]. Follow the authorized instructions for [Topic] and contact [Employee Name] if blocked. [Details]', 'urgent', true, 'official_notice'],
            ['Employee Recognition', 'Employee Appreciation', 'Thank You, [Employee Name]', 'Thank you for your contribution to [Topic]. Your effort is appreciated. [Details]', 'normal', false, 'announcement'],
            ['Maintenance / Office Facility', 'Internet / Network Maintenance', 'Internet / Network Maintenance — [Date]', 'Network maintenance is planned for [Date] at [Time]. Expected impact: [Details].', 'normal', false, 'announcement'],
            ['Maintenance / Office Facility', 'CCTV / Office Equipment Maintenance', 'Office Equipment Maintenance — [Date]', 'Maintenance is planned for [Date] at [Time]. Follow any temporary access or safety instructions. [Details]', 'normal', false, 'announcement'],
            ['General Announcement', 'General Company Announcement', '[Topic]', 'Please review this company update: [Details]. Contact [Employee Name] with questions.', 'normal', false, 'announcement'],
        ];

        foreach ($templates as [$categoryName, $name, $title, $content, $priority, $acknowledgmentRequired, $type]) {
            $categoryId = DB::table('notice_categories')->where('normalized_name', Str::lower(Str::squish($categoryName)))->where('status', true)->value('id');
            if ($categoryId === null) {
                continue;
            }
            $normalized = Str::lower(Str::squish($name));
            DB::table('notice_templates')->insertOrIgnore([
                'notice_category_id' => $categoryId,
                'communication_type' => $type,
                'name' => $name,
                'normalized_name' => $normalized,
                'default_title' => $title,
                'default_content' => $content,
                'default_priority' => $priority,
                'default_acknowledgment_required' => $acknowledgmentRequired,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['Attendance & Timing', 'Office Discipline'] as $name) {
            DB::table('warning_categories')->insertOrIgnore([
                'name' => $name,
                'normalized_name' => Str::lower(Str::squish($name)),
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $warningTemplates = [
            ['Attendance & Timing', 'Late Arrival Warning', 'Late Arrival — [Date]', 'This warning records that [Employee Name] arrived at [Time] on [Date]. Please review the attendance record and provide any relevant information. [Details]', 'verbal'],
            ['Attendance & Timing', 'Repeated Late Arrival', 'Repeated Late Arrival — [Date]', 'This warning records repeated late arrival concerns through [Date]. Please review the attendance record and follow the applicable policy. [Details]', 'written'],
            ['Attendance & Timing', 'Unauthorized Absence', 'Unauthorized Absence — [Date]', 'This warning records an absence on [Date] for which approval is not recorded. Please review the attendance record and provide any relevant information. [Details]', 'written'],
        ];

        foreach ($warningTemplates as [$categoryName, $name, $title, $description, $level]) {
            $categoryId = DB::table('warning_categories')->where('normalized_name', Str::lower(Str::squish($categoryName)))->where('status', true)->value('id');
            if ($categoryId === null) {
                continue;
            }
            DB::table('warning_templates')->insertOrIgnore([
                'warning_category_id' => $categoryId,
                'name' => $name,
                'normalized_name' => Str::lower(Str::squish($name)),
                'default_title' => $title,
                'default_description' => $description,
                'default_warning_level' => $level,
                'default_acknowledgment_required' => true,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
