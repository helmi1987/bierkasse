{{-- Sortable tab lists (drag, or ↑/↓ without a mouse); posts order[<group>][] in the shown order. --}}
@foreach ($groups as $group)
    <fieldset class="stack">
        <legend>{{ __('site.nav.'.$group) }}</legend>
        <ol class="sortable" data-sortable>
            @foreach ($orders[$group] as $key)
                <li draggable="true">
                    <input type="hidden" name="order[{{ $group }}][]" value="{{ $key }}">
                    <span class="sortable-handle" aria-hidden="true">⋮⋮</span>
                    <span class="sortable-label">{{ __('backend.nav.'.$key) }}</span>
                    <button type="button" class="btn small ghost" data-move="-1" aria-label="{{ __('backend.nav_up') }}">↑</button>
                    <button type="button" class="btn small ghost" data-move="1" aria-label="{{ __('backend.nav_down') }}">↓</button>
                </li>
            @endforeach
        </ol>
    </fieldset>
@endforeach
