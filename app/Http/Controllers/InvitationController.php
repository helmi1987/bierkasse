<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Support\AppSettings;
use App\Support\FormToken;
use App\Support\Qr;
use App\Support\RegistrationLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Invite someone new" (every active user, while the registration mode is not "closed"):
 * one-time invitation links valid for Invitation::VALID_DAYS. The plain link is only shown
 * right after creation (only its hash is stored). In mode "password" the registration QR code
 * with the embedded password is offered as well (user decision 2026-10-21).
 */
class InvitationController extends Controller
{
    /** Own invitations listed on the page (newest first). */
    private const LIST_LIMIT = 20;

    public function index(Request $request): View
    {
        abort_unless(AppSettings::invitationsAllowed(), 404);

        return $this->page($request, null, null);
    }

    public function store(Request $request): View|RedirectResponse
    {
        abort_unless(AppSettings::invitationsAllowed(), 404);

        $user = $request->user();
        if (! FormToken::consume($request, 'invite')) {
            return redirect()->route('account.invite')->with('warning', __('account.invitation_duplicate'));
        }
        if (Invitation::openCountFor($user) >= Invitation::MAX_OPEN_PER_USER) {
            return redirect()->route('account.invite')->with('error', __('account.invitation_limit', ['max' => Invitation::MAX_OPEN_PER_USER]));
        }

        [$invitation, $token] = Invitation::issue($user);

        // Rendered directly (no redirect), so the plain token is never stored in the session.
        return $this->page($request, $invitation, $token);
    }

    public function destroy(Request $request, Invitation $invitation): RedirectResponse
    {
        abort_unless($invitation->created_by === $request->user()->id, 403);
        if ($invitation->used_at !== null) {
            return back()->with('error', __('account.invitation_already_used'));
        }

        $invitation->delete();

        return back()->with('status', __('account.invitation_revoked'));
    }

    private function page(Request $request, ?Invitation $new, ?string $token): View
    {
        $user = $request->user();
        $newUrl = $token !== null ? Invitation::url($token) : null;
        $passwordUrl = AppSettings::registrationOpen() ? RegistrationLink::url(true) : null;

        return view('account.invite', [
            'new' => $new,
            'newUrl' => $newUrl,
            'newQr' => $newUrl !== null ? Qr::dataUri($newUrl, 480) : null,
            'passwordUrl' => $passwordUrl,
            'passwordQr' => $passwordUrl !== null ? Qr::dataUri($passwordUrl, 480) : null,
            'invitations' => Invitation::query()->with('usedBy')->where('created_by', $user->id)
                ->latest('id')->limit(self::LIST_LIMIT)->get(),
            'openCount' => Invitation::openCountFor($user),
            'max' => Invitation::MAX_OPEN_PER_USER,
            'days' => Invitation::VALID_DAYS,
            'appName' => AppSettings::appName(),
        ]);
    }
}
