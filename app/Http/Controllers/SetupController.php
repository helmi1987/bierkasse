<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Support\DatabaseConnection;
use App\Support\EnvEditor;
use App\Support\UserRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * First-run wizard: database connection, migrations, first admin user.
 * Protected by a one-time token stored in storage/app/setup-token.txt
 * (created by bootstrap/ensure-env.php). APP_URL is taken from the current host.
 */
class SetupController extends Controller
{
    private const SECRET_FIELDS = ['password', 'admin_password', 'admin_password_confirmation', 'token'];

    public static function tokenPath(): string
    {
        return storage_path('app/setup-token.txt');
    }

    public function show(Request $request): View
    {
        $db = DatabaseConnection::current();
        if (! array_key_exists($db['driver'], DatabaseConnection::DRIVERS)) {
            $db['driver'] = 'mysql';
        }

        return view('setup', [
            'drivers' => DatabaseConnection::DRIVERS,
            'db' => $db,
            'sqliteDefault' => database_path('database.sqlite'),
            'appUrl' => $request->getSchemeAndHttpHost(),
            // Relative path only: do not reveal the absolute server path before the token is entered.
            'tokenPath' => 'storage/app/setup-token.txt',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $server = Rule::requiredIf(fn () => $request->input('driver') !== 'sqlite');

        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'app_url' => ['required', 'url', 'max:255'],
            'driver' => ['required', Rule::in(array_keys(DatabaseConnection::DRIVERS))],
            'host' => [$server, 'nullable', 'string', 'max:255'],
            'port' => [$server, 'nullable', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:500'],
            'username' => [$server, 'nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'admin_username' => ['required', 'string', 'regex:'.UserRules::USERNAME_PATTERN],
            'admin_first_name' => ['nullable', 'string', 'max:100'],
            'admin_last_name' => ['nullable', 'string', 'max:100'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
        ]);

        $expected = is_file(self::tokenPath()) ? trim((string) file_get_contents(self::tokenPath())) : '';
        if ($expected === '' || ! hash_equals($expected, trim($data['token']))) {
            return $this->fail($request, 'token', __('setup.error_token'));
        }

        $db = [
            'driver' => $data['driver'],
            'host' => $data['host'] ?? '',
            'port' => $data['port'] ?? '',
            'database' => $data['database'],
            'username' => $data['username'] ?? '',
            'password' => $data['password'] ?? '',
        ];

        if ($error = DatabaseConnection::test($db)) {
            return $this->fail($request, 'database', __('setup.error_connection', ['error' => $error]));
        }

        try {
            DatabaseConnection::writeEnv($db);
            DatabaseConnection::activate($db);

            Artisan::call('migrate', ['--force' => true]);

            if (! Schema::hasTable('users')) {
                throw new RuntimeException(__('setup.error_migration', ['output' => Artisan::output()]));
            }

            $user = User::query()->updateOrCreate(
                ['username' => $data['admin_username']],
                [
                    'first_name' => $data['admin_first_name'] ?? null,
                    'last_name' => $data['admin_last_name'] ?? null,
                    'email' => $data['admin_email'] ?? null,
                    'password' => $data['admin_password'],
                    'role' => User::ROLE_ADMIN,
                    'status' => User::STATUS_ACTIVE,
                ],
            );

            Audit::try('setup', $user, [], ['username' => $user->username, 'role' => $user->role], $user);

            EnvEditor::set([
                'APP_URL' => rtrim($data['app_url'], '/'),
                'APP_INSTALLED' => true,
            ]);
            config(['app.installed' => true, 'app.url' => rtrim($data['app_url'], '/')]);

            @unlink(self::tokenPath());
        } catch (Throwable $e) {
            report($e);

            return $this->fail($request, 'database', __('setup.error_failed', ['error' => $e->getMessage()]));
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('backend.settings')->with('status', __('setup.done'));
    }

    private function fail(Request $request, string $field, string $message): RedirectResponse
    {
        return back()->withInput($request->except(self::SECRET_FIELDS))->withErrors([$field => $message]);
    }
}
