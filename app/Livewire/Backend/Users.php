<?php

namespace App\Livewire\Backend;

use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Livewire\Concerns\HasTabs;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Audit;
use App\Services\Ledger;
use App\Support\AppSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Benutzer" (kassier and admin): tab "Benutzer" (active incl. locked / left accounts with balance and limit)
 * and tab "Einladungen" (all invitation links: creator, status, revoke unused ones). The user detail page
 * stays a classic page (Backend\UserController).
 */
class Users extends Component
{
    use AuthorizesAdminArea;
    use HasTabs;
    use WithPagination;

    public const TABS = ['benutzer', 'einladungen'];

    #[Url(as: 'tab', except: 'benutzer')]
    public string $tab = 'benutzer';

    /** Filter of the user list: "active" (incl. locked) or "left". */
    #[Url(as: 'status', except: 'active')]
    public string $status = 'active';

    public array $notice = [];

    public function mount(): void
    {
        $this->status = $this->status === User::STATUS_LEFT ? User::STATUS_LEFT : User::STATUS_ACTIVE;
    }

    public function showStatus(string $status): void
    {
        $this->status = $status === User::STATUS_LEFT ? User::STATUS_LEFT : User::STATUS_ACTIVE;
    }

    public function updatedStatus(): void
    {
        $this->showStatus($this->status);
    }

    /** Revokes an unused invitation link (same rules as before: used links stay). */
    public function revokeInvitation(int $invitation): void
    {
        $result = DB::transaction(function () use ($invitation) {
            $locked = Invitation::query()->lockForUpdate()->find($invitation);
            if ($locked === null) {
                return 'missing';
            }
            if ($locked->used_at !== null) {
                return 'used';
            }
            $locked->delete();
            Audit::log('invitation.revoke', $locked, ['created_by' => $locked->created_by]);

            return 'revoked';
        });

        $this->notice = match ($result) {
            'revoked' => ['type' => 'ok', 'text' => __('account.invitation_revoked')],
            'used' => ['type' => 'bad', 'text' => __('account.invitation_already_used')],
            default => ['type' => 'bad', 'text' => __('backend.invitations_missing')],
        };
    }

    public function render(): View
    {
        if ($this->tab === 'einladungen') {
            return view('livewire.backend.users', [
                'invitations' => Invitation::query()->with(['creator', 'usedBy'])->latest('id')->paginate(30),
                'mode' => AppSettings::registrationMode(),
                'openCount' => Invitation::query()->open()->count(),
            ]);
        }

        // Locked accounts are listed with the active ones (badge "Gesperrt"): they still have a balance.
        $statuses = $this->status === User::STATUS_LEFT ? [User::STATUS_LEFT] : [User::STATUS_ACTIVE, User::STATUS_LOCKED];

        return view('livewire.backend.users', [
            'users' => User::query()->whereIn('status', $statuses)->orderBy('username')->get(),
            'balances' => Ledger::balances(),
        ]);
    }
}
