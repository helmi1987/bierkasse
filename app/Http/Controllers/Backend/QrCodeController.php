<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\QrCode;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * QR codes (admins): new code with optional name, deactivate / reactivate. Nothing is deleted,
 * deactivated codes stay as history. Shown on the guest and registration QR pages.
 */
class QrCodeController extends Controller
{
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
        $active = $request->boolean('active');
        DB::transaction(function () use ($qrCode, $active) {
            $old = ['active' => $qrCode->isActive()];
            $qrCode->forceFill(['deactivated_at' => $active ? null : now()])->save();
            Audit::log($active ? 'qr_code.activate' : 'qr_code.deactivate', $qrCode, $old, ['active' => $active, 'kind' => $qrCode->kind]);
        });

        return back()->with('status', $active ? __('qr.activated') : __('qr.deactivated'));
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
