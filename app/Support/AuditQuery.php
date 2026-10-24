<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Filters of the audit log list and CSV export (person, action prefix, date from/to, free text).
 */
class AuditQuery
{
    /**
     * Sanitised filters from raw input (query string or Livewire properties).
     *
     * @return array{actor: int|null, action: string, from: string, to: string, q: string}
     */
    public static function filters(array $input): array
    {
        $date = fn (string $key) => is_string($input[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input[$key]) ? $input[$key] : '';

        return [
            'actor' => is_numeric($input['actor'] ?? null) ? (int) $input['actor'] : null,
            'action' => is_string($input['action'] ?? null) ? mb_substr(trim($input['action']), 0, 64) : '',
            'from' => $date('from'),
            'to' => $date('to'),
            'q' => is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 100) : '',
        ];
    }

    /** @return Builder<AuditLog> */
    public static function query(array $f): Builder
    {
        $tz = (string) config('app.timezone');
        $utc = function (string $date, bool $end) use ($tz): ?string {
            try {
                $d = Carbon::createFromFormat('Y-m-d', $date, $tz);
            } catch (Throwable) {
                return null;
            }

            return ($end ? $d->endOfDay() : $d->startOfDay())->utc()->format('Y-m-d H:i:s');
        };

        return AuditLog::query()
            ->when($f['actor'] !== null, fn ($q) => $q->where('actor_ref', $f['actor']))
            ->when($f['action'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('action', $f['action'])->orWhere('action', 'like', addcslashes($f['action'], '%_\\').'.%')))
            ->when($f['from'] !== '' && $utc($f['from'], false), fn ($q) => $q->where('created_at', '>=', $utc($f['from'], false)))
            ->when($f['to'] !== '' && $utc($f['to'], true), fn ($q) => $q->where('created_at', '<=', $utc($f['to'], true)))
            ->when($f['q'] !== '', function ($q) use ($f) {
                $like = '%'.addcslashes($f['q'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('actor_name', 'like', $like)->orWhere('action', 'like', $like)
                    ->orWhere('subject_type', 'like', $like)->orWhere('old_values', 'like', $like)
                    ->orWhere('new_values', 'like', $like)->orWhere('ip', 'like', $like));
            });
    }
}
