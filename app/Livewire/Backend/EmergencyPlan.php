<?php

namespace App\Livewire\Backend;

use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Models\EmergencyPlanPrint;
use App\Services\EmergencyPlan as EmergencyPlanService;
use App\Support\AppSettings;
use App\Support\LiveFingerprint;
use Illuminate\Support\MessageBag;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Offline emergency plan "/admin/notfallplan" (1.40.0, admins only): the status card (last print, difference
 * since then with names, threshold notice, last reminder mail), the print buttons and the settings (window,
 * texts, reminder mail). The forms post classically to Backend\EmergencyPlanController.
 *
 * Polls every 10 s while visible (tick()). Only the island "status" is re-rendered, and only when the global
 * change marker (App\Support\LiveFingerprint) differs from the last render or FORCE_SECONDS passed; buttons and
 * forms are rendered once at mount.
 */
class EmergencyPlan extends Component
{
    use AuthorizesAdminArea;

    /** Islands of the view, re-rendered together by tick(). */
    public const ISLANDS = ['status'];

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
        return view('livewire.backend.emergency-plan', $this->data());
    }

    /** View data of the page (full render) and of the island, computed once per request. */
    public function data(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $last = EmergencyPlanPrint::latestPrint()?->load('printer');
        $texts = [];
        foreach (AppSettings::EMERGENCY_TEXT_KEYS as $key) {
            $texts[$key] = AppSettings::emergencyText($key);
        }

        return $this->cache = [
            'last' => $last,
            'diff' => EmergencyPlanService::difference($last, false),
            'threshold' => AppSettings::emergencyMailThreshold(),
            'mailSentAt' => AppSettings::emergencyMailSentAt(),
            'mailRecipients' => AppSettings::emergencyMailRecipients(),
            'candidates' => EmergencyPlanService::recipientCandidates(),
            'window' => AppSettings::emergencyWindow(),
            'texts' => $texts,
            'customized' => array_keys(AppSettings::emergencyTextsStored()),
            // Validation errors of the classic form posts (see Backend\Disposals).
            'formErrors' => session('errors')?->getBag('default') ?? new MessageBag,
        ];
    }
}
