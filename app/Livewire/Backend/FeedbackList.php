<?php

namespace App\Livewire\Backend;

use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Models\Feedback;
use App\Services\FeedbackNotifier;
use App\Support\AppSettings;
use App\Support\LiveFingerprint;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Feedback "/admin/feedback" (admins, Livewire since 1.36.0): the feedback list (30 per page, page in the URL)
 * and the recipients form. The forms post classically to Backend\FeedbackController.
 *
 * Polls every 10 s while visible (tick()). Only the island "list" (unread count and list) is re-rendered, and
 * only when the global change marker (App\Support\LiveFingerprint) differs from the last render or
 * FORCE_SECONDS passed; the recipients form (with the effective recipients) is rendered once at mount.
 * Rows carry wire:key, the read/delete forms are wire:ignore.
 */
class FeedbackList extends Component
{
    use AuthorizesAdminArea;
    use WithPagination;

    public const PER_PAGE = 30;

    /** Islands of the view, re-rendered together by tick(). */
    public const ISLANDS = ['list'];

    /** Islands are re-rendered at least this often. */
    public const FORCE_SECONDS = 300;

    /** Change marker of the last island render. */
    public string $fp = '';

    /** Unix time of the last island render. */
    public int $renderedAt = 0;

    private ?array $cache = null;

    /** Every request (first render and each poll): admins only (the page route has "role:admin" too). */
    public function boot(): void
    {
        $this->authorizeAdmin();
    }

    public function mount(): void
    {
        $this->fp = LiveFingerprint::current();
        $this->renderedAt = time();
    }

    /** wire:poll: nothing is rendered unless something changed (or FORCE_SECONDS passed); then the islands only. */
    public function tick(): void
    {
        $this->skipRender();

        $fp = LiveFingerprint::current();
        if ($fp === $this->fp && time() - $this->renderedAt < self::FORCE_SECONDS) {
            return;
        }
        $this->fp = $fp;
        $this->renderedAt = time();
        foreach (self::ISLANDS as $island) {
            $this->renderIsland($island);
        }
    }

    public function render(): View
    {
        return view('livewire.backend.feedback-list', $this->data());
    }

    /** View data of the page (full render) and of the islands, computed once per request. */
    public function data(): array
    {
        return $this->cache ??= [
            'feedback' => Feedback::query()->with('user')->latest('id')->paginate(self::PER_PAGE),
            'unread' => Feedback::unreadCount(),
            'recipientMode' => AppSettings::feedbackRecipientMode(),
            'recipients' => AppSettings::feedbackRecipients(),
            'effectiveRecipients' => FeedbackNotifier::recipients(),
        ];
    }
}
