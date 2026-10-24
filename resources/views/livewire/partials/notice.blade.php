{{-- Result of the last Livewire action ($notice = ['type' => 'ok'|'warn'|'bad', 'text' => …]). --}}
@if (! empty($notice['text']))
    <div @class(['alert', $notice['type'] ?? 'ok']) role="{{ ($notice['type'] ?? 'ok') === 'ok' ? 'status' : 'alert' }}">{{ $notice['text'] }}</div>
@endif
