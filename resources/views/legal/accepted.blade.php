{{-- Accepted texts of $user: links to the accepted revisions; older consents (no revision id) show the "Stand". --}}
@foreach (['privacy', 'terms'] as $k)
    @php($rev = $k === 'privacy' ? $user->privacyRevision : $user->termsRevision)
    {{ __('legal.title.'.$k) }} (@if ($rev)<a href="{{ route('legal.revision', $rev) }}" data-testid="accepted-{{ $k }}">{{ __('legal.user_consent_revision', ['date' => $rev->label()]) }}</a>, @endif{{ __('legal.revisions_version') }} {{ \App\Support\LegalTexts::versionDate($user->{$k.'_accepted_version'}) }})@if ($loop->first), @endif
@endforeach
