<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\QrCode;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * QR codes (one page since 1.20.0, Livewire component App\Livewire\Backend\QrCodes): overview for kassiers
 * and admins; management actions for admins only (routes with role:admin): new code with optional name,
 * rename, deactivate / reactivate. Nothing is deleted, deactivated codes stay as history. The switches and
 * the guest password are in Admin > Einstellungen > Funktionen (SettingsController::features).
 */
class QrCodeController extends Controller
{
    /** Page shell of the Livewire component App\Livewire\Backend\QrCodes (overview, management for admins). */
    public function index(): View
    {
        return view('backend.qr-codes');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(QrCode::KINDS)],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($data, $request) {
            $code = QrCode::generate($data['kind'], $data['label'] ?? null, $request->user());
            Audit::log('qr_code.create', $code, [], ['kind' => $code->kind, 'label' => $code->label]);
        });

        return back()->with('status', __('qr.created'));
    }

    public function active(Request $request, QrCode $qrCode): RedirectResponse
    {
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];
        $changed = false;
        $lastActive = false;
        DB::transaction(function () use ($qrCode, $active, &$changed, &$lastActive) {
            // Re-load with a row lock so two parallel requests cannot both audit the same change.
            $locked = QrCode::query()->lockForUpdate()->findOrFail($qrCode->id);
            if ($locked->isActive() === $active) {
                return;
            }
            $changed = true;
            $locked->forceFill(['deactivated_at' => $active ? null : now()])->save();
            Audit::log($active ? 'qr_code.activate' : 'qr_code.deactivate', $locked, ['active' => ! $active], ['active' => $active, 'kind' => $locked->kind]);
            $lastActive = ! $active && ! QrCode::query()->active()->where('kind', $locked->kind)->exists();
        });
        if (! $changed) {
            return back();
        }

        $redirect = back()->with('status', $active ? __('qr.activated') : __('qr.deactivated'));

        return $lastActive ? $redirect->with('warning', __('qr.last_deactivated.'.$qrCode->kind)) : $redirect;
    }

    public function label(Request $request, QrCode $qrCode): RedirectResponse
    {
        $data = $request->validate(['label' => ['nullable', 'string', 'max:100']]);
        $label = trim((string) ($data['label'] ?? '')) ?: null;
        DB::transaction(function () use ($qrCode, $label) {
            $old = ['label' => $qrCode->label];
            $qrCode->forceFill(['label' => $label])->save();
            Audit::log('qr_code.label', $qrCode, $old, ['label' => $label]);
        });

        return back()->with('status', __('settings.saved'));
    }
}
