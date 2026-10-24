{{-- Protokoll and Fassungen (Livewire; refreshed every 10 s while visible). --}}
<div wire:poll.10s.visible>
    @include('livewire.partials.tabs', [
        'tabs' => ['protokoll' => __('backend.tabs.audit'), 'fassungen' => __('backend.tabs.revisions')],
        'active' => $tab,
        'route' => 'backend.audit',
        'label' => __('audit.title'),
    ])

    @if ($tab === 'fassungen')
        <p class="muted">{{ __('legal.revisions_intro') }}</p>
        <section class="card">
            <form method="get" action="{{ route('backend.audit') }}" class="inline-form" wire:submit="applyFilters">
                <input type="hidden" name="tab" value="fassungen">
                <label for="f-kind">{{ __('legal.revisions_kind') }}</label>
                <select id="f-kind" name="kind" wire:model.live="kind">
                    <option value="">{{ __('legal.revisions_all') }}</option>
                    @foreach (\App\Support\LegalTexts::KINDS as $k)
                        <option value="{{ $k }}" @selected($kind === $k)>{{ __('legal.title.'.$k) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn small">{{ __('audit.filter_submit') }}</button>
            </form>
        </section>

        <section class="card">
            @if ($revisions->isEmpty())
                <p class="muted">{{ __('legal.revisions_none') }}</p>
            @else
                <div class="table-wrap">
                    <table class="table" data-testid="legal-revisions">
                        <thead>
                            <tr>
                                <th>{{ __('legal.revisions_date') }}</th>
                                <th>{{ __('legal.revisions_kind') }}</th>
                                <th>{{ __('legal.revisions_version') }}</th>
                                <th>{{ __('legal.revisions_reason') }}</th>
                                <th>{{ __('legal.revisions_by') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($revisions as $revision)
                                <tr wire:key="revision-{{ $revision->id }}">
                                    <td><a href="{{ route('legal.revision', $revision) }}">{{ $revision->label() }}</a></td>
                                    <td>{{ __('legal.title.'.$revision->kind) }}</td>
                                    <td>{{ \App\Support\LegalTexts::versionDate($revision->version) }}</td>
                                    <td>{{ __('legal.reason.'.$revision->reason) }}</td>
                                    <td>{{ $revision->creator?->username ?? __('legal.revisions_system') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $revisions->links('livewire.pagination') }}
            @endif
        </section>
    @else
        <p class="muted">{{ __('audit.intro') }}</p>
        <section class="card">
            {{-- Without JavaScript this is a plain GET form; with Livewire the filters apply in place. --}}
            <form method="get" action="{{ route('backend.audit') }}" class="inline-form" wire:submit="applyFilters">
                <label for="f-actor">{{ __('audit.filter_actor') }}</label>
                <select id="f-actor" name="actor" wire:model="actor">
                    <option value="">{{ __('audit.filter_actor_all') }}</option>
                    @foreach ($actors as $a)
                        <option value="{{ $a->actor_ref }}" @selected($filters['actor'] === (int) $a->actor_ref)>{{ $a->actor_name }}</option>
                    @endforeach
                </select>
                <label for="f-action">{{ __('audit.filter_action') }}</label>
                <select id="f-action" name="action" wire:model="action">
                    <option value="">{{ __('audit.filter_action_all') }}</option>
                    @foreach ($prefixes as $p)
                        <option value="{{ $p }}" @selected($filters['action'] === $p)>{{ \App\Support\AuditPresenter::prefixLabel($p) }}</option>
                    @endforeach
                </select>
                <label for="f-from">{{ __('audit.filter_from') }}</label>
                <input type="date" id="f-from" name="from" wire:model="from" value="{{ $filters['from'] }}">
                <label for="f-to">{{ __('audit.filter_to') }}</label>
                <input type="date" id="f-to" name="to" wire:model="to" value="{{ $filters['to'] }}">
                <label for="f-q">{{ __('audit.filter_q') }}</label>
                <input type="search" id="f-q" name="q" wire:model="q" value="{{ $filters['q'] }}" maxlength="100">
                <button type="submit" class="btn small">{{ __('audit.filter_submit') }}</button>
                <a class="btn small ghost" href="{{ route('backend.audit') }}" wire:click.prevent="resetFilters">{{ __('audit.filter_reset') }}</a>
            </form>
            <div class="row-buttons">
                <form method="post" action="{{ route('backend.audit.verify') }}" class="inline">
                    @csrf
                    <button type="submit" class="btn small">{{ __('audit.verify') }}</button>
                </form>
                <a class="btn small ghost" href="{{ route('backend.audit.export', array_filter($filters, fn ($v) => $v !== null && $v !== '')) }}">{{ __('audit.export') }}</a>
            </div>
        </section>

        <section class="card">
            @if ($entries->isEmpty())
                <p class="muted">{{ __('audit.none') }}</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('audit.time') }}</th>
                                <th>{{ __('audit.actor') }}</th>
                                <th>{{ __('audit.action') }}</th>
                                <th>{{ __('audit.subject') }}</th>
                                <th>{{ __('audit.details') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr wire:key="audit-{{ $entry->id }}">
                                    <td><a href="{{ route('backend.audit.show', $entry) }}">{{ local_time($entry->at(), 'd.m.Y H:i:s') }}</a></td>
                                    <td>{{ $entry->actor_name ?? __('audit.system') }}</td>
                                    <td>{{ \App\Support\AuditPresenter::actionLabel($entry->action) }} <small class="muted"><code>{{ $entry->action }}</code></small></td>
                                    <td>{{ $presenter->subjectLabel($entry) ?? '–' }}</td>
                                    <td>{{ $presenter->summary($entry) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $entries->links('livewire.pagination') }}
            @endif
        </section>
    @endif
</div>
