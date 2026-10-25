{{-- Accepted texts of $user, one line each: link to the accepted revision; older consents (no revision id) show the "Stand". --}}
@foreach (['privacy', 'terms'] as $k)
    @php($rev = $k === 'privacy' ? $user->privacyRevision : $user->termsRevision)
    {{ __('legal.title.'.$k) }} · @if ($rev)<a href="{{ route('legal.revision', $rev) }}" data-testid="accepted-{{ $k }}">{{ __('legal.view_revision') }}</a>@else{{ __('legal.revisions_version') }} {{ \App\Support\LegalTexts::versionDate($user->{$k.'_accepted_version'}) }}@endif @if (! $loop->last)<br>@endif
@endforeach
