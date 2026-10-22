<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\RegistrationCode;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Multi-use registration codes (admins): create, deactivate/reactivate (nothing is deleted). Shown as QR/flyer on the
 * registration QR page (SettingsController::registrationQr).
 */
class RegistrationCodeController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:100']]);
        if (RegistrationCode::query()->count() >= RegistrationCode::MAX) {
            return back()->with('error', __('settings.codes_max', ['max' => RegistrationCode::MAX]));
        }

        $code = DB::transaction(function () use ($data, $request) {
            $code = RegistrationCode::generate(trim($data['label']), $request->user());
            Audit::log('registration_code.create', $code, [], ['label' => $code->label]);

            return $code;
        });

        return redirect()->route('backend.registration.qr', ['qr' => $code->id])->with('status', __('settings.codes_created'));
    }

    public function lock(Request $request, RegistrationCode $registrationCode): RedirectResponse
    {
        $lock = $request->boolean('locked');
        DB::transaction(function () use ($registrationCode, $lock) {
            $old = ['locked' => $registrationCode->isLocked()];
            $registrationCode->forceFill(['locked_at' => $lock ? now() : null])->save();
            Audit::log($lock ? 'registration_code.lock' : 'registration_code.unlock', $registrationCode, $old, ['locked' => $lock]);
        });

        return back()->with('status', $lock ? __('settings.codes_locked') : __('settings.codes_unlocked'));
    }
}
