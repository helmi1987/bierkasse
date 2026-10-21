<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Services\Audit;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * All invitation links (kassier and admin): creator, status, revoke unused ones.
 */
class InvitationController extends Controller
{
    public function index(): View
    {
        return view('backend.invitations', [
            'invitations' => Invitation::query()->with(['creator', 'usedBy'])->latest('id')->paginate(30),
            'mode' => AppSettings::registrationMode(),
            'openCount' => Invitation::query()->open()->count(),
        ]);
    }

    public function destroy(Invitation $invitation): RedirectResponse
    {
        if ($invitation->used_at !== null) {
            return back()->with('error', __('account.invitation_already_used'));
        }

        DB::transaction(function () use ($invitation) {
            $invitation->delete();
            Audit::log('invitation.revoke', $invitation, ['created_by' => $invitation->created_by]);
        });

        return back()->with('status', __('account.invitation_revoked'));
    }
}
