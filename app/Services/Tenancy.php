<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\QrCode;
use App\Models\RegistrationCode;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AppSettings;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Tenancy ("Mandanten", decision 2026-11-03).
 *
 * The data model is always tenant-capable (tenant_id on every tenant-owned row, see BelongsToTenant). The
 * tenancy mode (global setting, single|multi) decides how the current tenant is found:
 *  - single: there is exactly one tenant; it is the current tenant of every request (no tenant pages, no
 *    sub-domains, behaviour of the versions before 1.26).
 *  - multi: the current tenant comes from the logged-in user (session, superadmins may switch), for guests from
 *    the sub-domain (<slug>.<app host>), the link parameter ?mandant=<slug>, a code in the request (invitation,
 *    registration/guest/flyer/cashbox code) or the tenant remembered in the session (see ResolveTenant).
 * Switching the mode never changes data: single -> multi any time (the admin who switches becomes superadmin),
 * multi -> single only while exactly one tenant is left. Both directions and deleting a tenant first write a
 * database backup (storage/app/backups).
 */
class Tenancy
{
    public const MODE_SINGLE = 'single';

    public const MODE_MULTI = 'multi';

    public const MODES = [self::MODE_SINGLE, self::MODE_MULTI];

    /** Session: tenant of the logged-in user (superadmins: the tenant they switched to). */
    public const SESSION_KEY = 'tenant.id';

    /** Session: tenant resolved for a guest (sub-domain, link parameter or code), kept for the following requests. */
    public const GUEST_KEY = 'tenant.guest_id';

    /** Link parameter naming the tenant (?mandant=<slug>). */
    public const QUERY = 'mandant';

    private static ?string $mode = null;

    private static bool $resolved = false;

    private static ?Tenant $current = null;

    private static ?Tenant $default = null;

    private static bool $defaultLoaded = false;

    /** Forgets everything cached for this request (tests, after migrations or mode changes). */
    public static function flush(): void
    {
        self::$mode = null;
        self::$resolved = false;
        self::$current = null;
        self::$default = null;
        self::$defaultLoaded = false;
        Setting::flushCache();
    }

    public static function mode(): string
    {
        if (self::$mode === null) {
            $mode = AppSettings::get(AppSettings::TENANCY_MODE, self::MODE_SINGLE);
            self::$mode = in_array($mode, self::MODES, true) ? $mode : self::MODE_SINGLE;
        }

        return self::$mode;
    }

    public static function isMulti(): bool
    {
        return self::mode() === self::MODE_MULTI;
    }

    /** Current tenant: the one set for this request, in single mode the only tenant. */
    public static function current(): ?Tenant
    {
        if (self::$resolved) {
            return self::$current;
        }

        return self::isMulti() ? null : self::defaultTenant();
    }

    public static function currentId(): ?int
    {
        return self::current()?->id;
    }

    /** Sets the current tenant for the rest of the request (null = no tenant context). */
    public static function set(Tenant|int|null $tenant): void
    {
        if (is_int($tenant)) {
            $tenant = Tenant::query()->find($tenant);
        }
        self::$resolved = true;
        self::$current = $tenant;
    }

    /** Back to the automatic resolution (single mode: the only tenant). */
    public static function clear(): void
    {
        self::$resolved = false;
        self::$current = null;
    }

    /** Runs $callback with $tenant as the current tenant and restores the previous one afterwards. */
    public static function runAs(?Tenant $tenant, Closure $callback): mixed
    {
        [$resolved, $current] = [self::$resolved, self::$current];
        self::set($tenant);
        try {
            return $callback();
        } finally {
            self::$resolved = $resolved;
            self::$current = $current;
            Setting::flushCache();
        }
    }

    /** The oldest tenant (the only one in single mode, "Standard" after the migration); null before the setup. */
    public static function defaultTenant(): ?Tenant
    {
        if (! self::$defaultLoaded) {
            try {
                self::$default = Tenant::query()->orderBy('id')->first();
            } catch (Throwable) {
                self::$default = null;
            }
            self::$defaultLoaded = true;
        }

        return self::$default;
    }

    public static function count(): int
    {
        return Tenant::query()->count();
    }

    public static function fromSlug(?string $slug): ?Tenant
    {
        $slug = Str::lower(trim((string) $slug));

        return $slug !== '' && preg_match(Tenant::SLUG_PATTERN, $slug) ? Tenant::query()->where('slug', $slug)->first() : null;
    }

    /** Host of APP_URL (tenant sub-domains hang below it). */
    public static function baseHost(): string
    {
        return Str::lower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /** Tenant of a request host "<slug>.<base host>", null for the base host or unknown slugs. */
    public static function fromHost(?string $host): ?Tenant
    {
        $host = Str::lower(trim((string) $host));
        $base = self::baseHost();
        if ($host === '' || $base === '' || ! str_ends_with($host, '.'.$base)) {
            return null;
        }

        return self::fromSlug(substr($host, 0, -strlen('.'.$base)));
    }

    /**
     * Route parameters for links to public pages (registration, guest page): in multi mode the tenant is named
     * (?mandant=<slug>), so the page knows the tenant even on the base host.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function linkParams(array $params = []): array
    {
        $tenant = self::isMulti() ? self::current() : null;

        return $tenant !== null ? $params + [self::QUERY => $tenant->slug] : $params;
    }

    /** Sub-domain URL of a tenant (same scheme as APP_URL), e.g. https://luks.bier.example. */
    public static function hostUrl(Tenant $tenant): string
    {
        $url = (string) config('app.url');
        $scheme = (string) parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($url, PHP_URL_PORT);

        return $scheme.'://'.$tenant->slug.'.'.self::baseHost().($port ? ':'.$port : '');
    }

    /**
     * Tenant of a code in the request (guests without tenant context): invitation link, registration code,
     * guest QR code, flyer code or cashbox code. Hash lookups only, no code is ever checked here.
     */
    public static function fromCodes(Request $request): ?Tenant
    {
        $lookups = [
            fn () => ($t = (string) $request->query(Invitation::QUERY, '')) !== '' && strlen($t) <= 255
                ? Invitation::withoutTenant()->where('token_hash', Invitation::hashToken($t))->value('tenant_id') : null,
            fn () => ($c = (string) $request->query('zugang', '')) !== '' && strlen($c) <= 255
                ? (RegistrationCode::withoutTenant()->where('code_hash', RegistrationCode::hashCode($c))->value('tenant_id')
                    ?? QrCode::withoutTenant()->where('code_hash', hash('sha256', $c))->value('tenant_id')) : null,
            fn () => ($f = (string) $request->query('flyer', '')) !== '' && strlen($f) <= 255
                ? QrCode::withoutTenant()->where('code_hash', hash('sha256', $f))->value('tenant_id') : null,
            fn () => is_string($c = $request->route('code')) && $c !== ''
                ? QrCode::withoutTenant()->where('code_hash', hash('sha256', $c))->value('tenant_id') : null,
        ];
        foreach ($lookups as $lookup) {
            $id = $lookup();
            if ($id !== null) {
                return Tenant::query()->find((int) $id);
            }
        }

        return null;
    }

    /**
     * Writes a database backup (storage/app/backups) before a change of the tenancy; throws when it fails.
     */
    public static function backup(): string
    {
        $code = Artisan::call('db:backup');
        $output = trim(Artisan::output());
        if ($code !== 0) {
            throw new RuntimeException($output !== '' ? $output : 'Backup failed.');
        }
        if (preg_match('/Backup written: (.+)$/m', $output, $m)) {
            return basename(trim($m[1]));
        }

        return $output;
    }

    /**
     * Switches to multi mode: backup, mode setting, the acting admin becomes superadmin.
     */
    public static function enableMulti(User $actor, bool $backup = true): void
    {
        if (self::isMulti()) {
            return;
        }
        $file = $backup ? self::backup() : null;
        $current = self::current();
        DB::transaction(function () use ($actor, $file) {
            Setting::put(AppSettings::TENANCY_MODE, self::MODE_MULTI, 'tenancy.mode');
            $actor->forceFill(['superadmin' => true])->save();
            self::logGlobal('tenancy.multi', $actor, [], ['mode' => self::MODE_MULTI, 'superadmin' => $actor->username, 'backup' => $file], $actor);
        });
        self::flush();
        // The rest of this request keeps its tenant (multi mode no longer resolves it by itself).
        self::set($current);
    }

    /**
     * Back to single mode: only while exactly one tenant is left. Backup, mode setting, superadmin flags are
     * cleared (the admin who switches on again becomes superadmin).
     */
    public static function disableMulti(User $actor, bool $backup = true): void
    {
        if (! self::isMulti()) {
            return;
        }
        if (self::count() !== 1) {
            throw new RuntimeException(__('tenants.error_not_single'));
        }
        $file = $backup ? self::backup() : null;
        DB::transaction(function () use ($actor, $file) {
            Setting::put(AppSettings::TENANCY_MODE, self::MODE_SINGLE, 'tenancy.mode');
            User::withoutTenant()->where('superadmin', true)->update(['superadmin' => false]);
            self::logGlobal('tenancy.single', $actor, [], ['mode' => self::MODE_SINGLE, 'backup' => $file], $actor);
        });
        self::flush();
    }

    /**
     * Creates a tenant with its first admin (must change the password at the first login, has to accept
     * the legal texts like every new account).
     *
     * @param  array{username: string, email: string, password: string, first_name?: ?string, last_name?: ?string}  $admin
     */
    public static function create(string $name, string $slug, array $admin, ?User $actor = null): Tenant
    {
        return DB::transaction(function () use ($name, $slug, $admin, $actor) {
            $tenant = Tenant::query()->create(['name' => trim($name), 'slug' => Str::lower(trim($slug))]);
            self::runAs($tenant, function () use ($admin) {
                $user = new User([
                    'username' => Str::lower(trim($admin['username'])),
                    'first_name' => $admin['first_name'] ?? null,
                    'last_name' => $admin['last_name'] ?? null,
                    'email' => $admin['email'],
                    'password' => $admin['password'],
                    'role' => User::ROLE_ADMIN,
                    'status' => User::STATUS_ACTIVE,
                    'must_change_password' => true,
                ]);
                $user->save();
            });
            self::logGlobal('tenant.create', $tenant, [], ['name' => $tenant->name, 'slug' => $tenant->slug, 'admin' => Str::lower(trim($admin['username']))], $actor);

            return $tenant;
        });
    }

    public static function rename(Tenant $tenant, string $name, ?string $slug = null, ?User $actor = null): void
    {
        $tenant->name = trim($name);
        if ($slug !== null) {
            $tenant->slug = Str::lower(trim($slug));
        }
        [$old, $new] = Audit::dirty($tenant);
        DB::transaction(function () use ($tenant, $old, $new, $actor) {
            $tenant->save();
            if ($new !== []) {
                self::logGlobal('tenant.update', $tenant, $old, $new, $actor);
            }
        });
    }

    /**
     * Deletes a tenant with all its data (users, bookings, settings, audit log, ...). Never the last tenant
     * and never the tenant the acting user belongs to. A backup is written first.
     */
    public static function delete(Tenant $tenant, User $actor, bool $backup = true): void
    {
        if (self::count() <= 1) {
            throw new RuntimeException(__('tenants.error_last'));
        }
        if ((int) $actor->tenant_id === (int) $tenant->id) {
            throw new RuntimeException(__('tenants.error_own'));
        }
        $file = $backup ? self::backup() : null;
        $counts = ['users' => User::forTenant($tenant)->count()];
        DB::transaction(function () use ($tenant, $actor, $file, $counts) {
            // The foreign keys cascade; the order here only matters for databases without them.
            foreach (['audit_logs', 'legal_revisions', 'qr_codes', 'registration_codes', 'feedback', 'invitations', 'guest_sales', 'stock_corrections', 'cash_entries', 'transactions', 'users', 'settings', 'audit_chains'] as $table) {
                DB::table($table)->where('tenant_id', $tenant->id)->delete();
            }
            $tenant->delete();
            self::logGlobal('tenant.delete', null, ['name' => $tenant->name, 'slug' => $tenant->slug] + $counts, ['backup' => $file], $actor);
        });
        self::$defaultLoaded = false;
        if (self::currentId() === (int) $tenant->id) {
            // The superadmin had switched into the deleted tenant: back to the own one.
            self::set(Tenant::query()->find((int) $actor->tenant_id));
        }
    }

    /**
     * Tenant management and mode changes are logged in the global chain (no tenant), visible to superadmins
     * and in single mode; not in the log of the tenant the superadmin happens to work in.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private static function logGlobal(string $action, ?Model $subject, array $old, array $new, ?User $actor): void
    {
        self::runAs(null, fn () => Audit::log($action, $subject, $old, $new, $actor));
    }
}
