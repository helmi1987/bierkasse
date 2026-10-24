<?php

namespace App\Livewire\Backend;

use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Livewire\Concerns\HasTabs;
use App\Models\AuditLog;
use App\Models\LegalRevision;
use App\Services\LegalArchive;
use App\Support\AuditPresenter;
use App\Support\AuditQuery;
use App\Support\LegalTexts;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Protokoll" (kassier and admin, read-only; one page for both since 1.20.0): tab "Protokoll" with the audit log
 * (filters person, action prefix, date from/to, free text; 50 per page) and tab "Fassungen" with the archived
 * revisions of the privacy policy and terms of use (filter by text, 25 per page). Detail page, integrity check
 * and CSV export stay classic routes (Backend\AuditController).
 */
class AuditLogList extends Component
{
    use AuthorizesAdminArea;
    use HasTabs;
    use WithPagination;

    public const TABS = ['protokoll', 'fassungen'];

    #[Url(as: 'tab', except: 'protokoll')]
    public string $tab = 'protokoll';

    #[Url(except: '')]
    public string $actor = '';

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $q = '';

    /** Filter of the revisions tab: "privacy", "terms" or empty (all). */
    #[Url(except: '')]
    public string $kind = '';

    /** Filters are applied with the form button (or a change of a select), back to page 1. */
    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('actor', 'action', 'from', 'to', 'q');
        $this->resetPage();
    }

    public function updatedKind(): void
    {
        $this->kind = in_array($this->kind, LegalTexts::KINDS, true) ? $this->kind : '';
        $this->resetPage();
    }

    public function render(): View
    {
        return $this->tab === 'fassungen' ? $this->revisions() : $this->entries();
    }

    private function entries(): View
    {
        $filters = $this->filters();
        $entries = AuditQuery::query($filters)->with('actor')->orderByDesc('id')->paginate(50);

        return view('livewire.backend.audit-log-list', [
            'entries' => $entries,
            // Subjects and referenced users of this page, loaded in batches (no query per row).
            'presenter' => AuditPresenter::for($entries->items()),
            'filters' => $filters,
            'actors' => AuditLog::query()->whereNotNull('actor_ref')->select('actor_ref', DB::raw('MAX(actor_name) as actor_name'))
                ->groupBy('actor_ref')->orderBy('actor_name')->get(),
            'prefixes' => AuditLog::query()->distinct()->orderBy('action')->pluck('action')
                ->map(fn ($a) => explode('.', $a)[0])->unique()->values(),
        ]);
    }

    private function revisions(): View
    {
        // The current texts are archived before listing (e.g. after a deploy with new default texts).
        LegalArchive::currentAll();
        $kind = in_array($this->kind, LegalTexts::KINDS, true) ? $this->kind : null;

        return view('livewire.backend.audit-log-list', [
            'revisions' => LegalRevision::query()->with('creator')
                ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
                ->orderByDesc('id')->paginate(25),
        ]);
    }

    /** @return array{actor: int|null, action: string, from: string, to: string, q: string} */
    private function filters(): array
    {
        return AuditQuery::filters(['actor' => $this->actor, 'action' => $this->action, 'from' => $this->from, 'to' => $this->to, 'q' => $this->q]);
    }
}
