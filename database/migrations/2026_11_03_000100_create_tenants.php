<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy (decision 2026-11-03): every tenant-owned row carries tenant_id (shared database, global scope).
 * The data model is always tenant-capable; the tenancy mode (setting "tenancy_mode", single|multi) only decides
 * how the tenant is resolved and whether the tenant pages are shown. So switching between single and multi
 * never touches the data. Existing data moves into the first tenant "Standard".
 *
 * - tenants: id, name, slug (sub-domain / link parameter).
 * - tenant_id on users, transactions, cash_entries, stock_corrections, guest_sales, invitations, feedback,
 *   registration_codes, qr_codes, legal_revisions, audit_logs (nullable: global events) and settings
 *   (nullable: global settings such as mail or the tenancy mode).
 * - users.superadmin: manages tenants and the global settings in multi mode.
 * - users.username unique per tenant (was global).
 * - settings: key was the primary key; now id + unique (tenant_id, key).
 * - audit_chains: one hash chain per tenant (plus one with tenant_id null for global events) replaces audit_chain.
 */
return new class extends Migration
{
    /** Tables that get a NOT NULL tenant_id with a cascading foreign key. */
    private const TENANT_TABLES = [
        'users', 'transactions', 'cash_entries', 'stock_corrections', 'guest_sales', 'invitations', 'feedback',
        'registration_codes', 'qr_codes', 'legal_revisions',
    ];

    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 32)->unique();
            $table->timestamps();
        });
        $tenantId = (int) DB::table('tenants')->insertGetId([
            'name' => 'Standard',
            'slug' => 'standard',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('superadmin')->default(false)->after('admin_only');
        });

        foreach (self::TENANT_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            });
            DB::table($name)->update(['tenant_id' => $tenantId]);
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->index('tenant_id');
            });
        }

        // User names are unique per tenant.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->unique(['tenant_id', 'username']);
        });

        // Audit log: global events (no tenant context, e.g. a failed login) keep tenant_id null.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
        });
        DB::table('audit_logs')->update(['tenant_id' => $tenantId]);
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'id']);
        });

        Schema::create('audit_chains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->unique();
            $table->string('last_hash', 64);
            $table->unsignedBigInteger('entries');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        $head = DB::table('audit_chain')->where('id', 1)->first();
        DB::table('audit_chains')->insert([
            'tenant_id' => $tenantId,
            'last_hash' => (string) ($head->last_hash ?? ''),
            'entries' => (int) ($head->entries ?? 0),
        ]);
        Schema::drop('audit_chain');

        // Settings: id + (tenant_id, key); global keys (see AppSettings::GLOBAL_KEYS) keep tenant_id null.
        Schema::rename('settings', 'settings_old');
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('key', 191);
            $table->longText('value')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        foreach (DB::table('settings_old')->orderBy('key')->get() as $row) {
            DB::table('settings')->insert([
                'tenant_id' => in_array($row->key, \App\Support\AppSettings::GLOBAL_KEYS, true) ? null : $tenantId,
                'key' => $row->key,
                'value' => $row->value,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
        Schema::drop('settings_old');
    }

    public function down(): void
    {
        // Back to the single-tenant schema: all rows of all tenants are kept (tenant columns dropped).
        Schema::rename('settings', 'settings_multi');
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
        foreach (DB::table('settings_multi')->orderBy('tenant_id')->orderBy('key')->get() as $row) {
            DB::table('settings')->updateOrInsert(['key' => $row->key], [
                'value' => $row->value, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            ]);
        }
        Schema::drop('settings_multi');

        Schema::create('audit_chain', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('last_hash', 64);
            $table->unsignedBigInteger('entries');
        });
        $head = DB::table('audit_chains')->orderBy('tenant_id')->first();
        DB::table('audit_chain')->insert(['id' => 1, 'last_hash' => (string) ($head->last_hash ?? ''), 'entries' => (int) ($head->entries ?? 0)]);
        Schema::drop('audit_chains');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id', 'id']);
            $table->dropColumn('tenant_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'username']);
            $table->unique('username');
        });
        foreach (array_reverse(self::TENANT_TABLES) as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('superadmin');
        });
        Schema::drop('tenants');
    }
};
