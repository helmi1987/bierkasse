<?php

namespace App\Support;

use App\Services\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Cheap "has anything changed?" marker for the live page refresh (public/js/live.js).
 * One query of indexed MAX/COUNT aggregates over the tables the logged-in pages show; global for
 * all users (a change anywhere makes open pages re-render once, which is fine for an office).
 * Row counts catch deletions (e.g. a withdrawn deposit), updated_at catches confirmations/edits.
 */
class LiveFingerprint
{
    /** table => aggregated columns ("id" = MAX(id), "count" = COUNT(*), "updated_at" = MAX(updated_at)) */
    private const TABLES = [
        'transactions' => ['id', 'count', 'updated_at'],
        'round_items' => ['id', 'count'],
        'guest_sales' => ['id', 'count', 'updated_at'],
        'cash_entries' => ['id', 'count', 'updated_at'],
        'stock_corrections' => ['id', 'count', 'updated_at'],
        'settings' => ['count', 'updated_at'],
        'users' => ['id', 'count', 'updated_at'],
        'user_identities' => ['id', 'count', 'updated_at'],
        'sso_providers' => ['id', 'count', 'updated_at'],
        'feedback' => ['id', 'count', 'updated_at'],
        'invitations' => ['id', 'count', 'updated_at'],
        'registration_codes' => ['id', 'count', 'updated_at'],
        'qr_codes' => ['id', 'count', 'updated_at'],
        'user_favorites' => ['id', 'count'],
        'audit_logs' => ['id'],
    ];

    /** Tables without tenant_id (global, or scoped through the user/transaction they belong to). */
    private const GLOBAL_TABLES = ['round_items', 'user_identities', 'sso_providers', 'user_favorites'];

    /** Per tenant: a change in another tenant does not reload the open pages of this one. */
    public static function current(): string
    {
        $tenantId = Tenancy::currentId();
        $parts = [];
        $bindings = [];
        foreach (self::TABLES as $table => $columns) {
            $where = '';
            if ($tenantId !== null && ! in_array($table, self::GLOBAL_TABLES, true)) {
                $where = $table === 'settings' ? ' WHERE tenant_id = ? OR tenant_id IS NULL' : ' WHERE tenant_id = ?';
            }
            foreach ($columns as $column) {
                $expr = $column === 'count' ? 'COUNT(*)' : 'MAX('.$column.')';
                $parts[] = '(SELECT '.$expr.' FROM '.$table.$where.') AS '.$table.'_'.$column;
                if ($where !== '') {
                    $bindings[] = $tenantId;
                }
            }
        }
        $row = (array) DB::selectOne('SELECT '.implode(', ', $parts), $bindings);

        // The local date: day-based numbers (statistics) change at midnight.
        $row['date'] = now()->toDateString();

        return substr(hash('sha256', json_encode(array_map('strval', $row))), 0, 20);
    }
}
