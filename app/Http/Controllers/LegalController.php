<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Support\LegalTexts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;

/**
 * Public privacy policy and terms of use (logged out and logged in), and the consent page that
 * users see until they accepted the current versions of both texts (EnsureActiveUser).
 */
class LegalController extends Controller
{
    public function privacy(): View
    {
        return $this->page(LegalTexts::PRIVACY);
    }

    public function terms(): View
    {
        return $this->page(LegalTexts::TERMS);
    }

    public function consent(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user->needsConsent()) {
            return redirect()->to($user->homeUrl());
        }

        return view('legal.consent', [
            'texts' => array_map(fn (string $kind) => $this->text($kind), array_combine(LegalTexts::KINDS, LegalTexts::KINDS)),
            // Someone who accepted before sees that the texts changed.
            'renewal' => $user->consent_accepted_at !== null,
        ]);
    }

    public function accept(Request $request): RedirectResponse
    {
        $request->validate(['consent' => ['accepted']], ['consent.accepted' => __('legal.consent_required')]);
        $user = $request->user();

        if ($user->needsConsent()) {
            Audit::save('consent.accept', $user->forceFill(LegalTexts::consentAttributes()));
        }

        return redirect()->intended($user->homeUrl())->with('status', __('legal.consent_saved'));
    }

    private function page(string $kind): View
    {
        return view('legal.page', ['kind' => $kind] + $this->text($kind));
    }

    /**
     * @return array{title: string, html: HtmlString, date: string, url: string}
     */
    private function text(string $kind): array
    {
        $rendered = LegalTexts::render($kind);

        return [
            'title' => $rendered['title'] ?? __('legal.title.'.$kind),
            'html' => $rendered['html'],
            'date' => LegalTexts::versionDate(LegalTexts::version($kind)),
            'url' => route($kind === LegalTexts::PRIVACY ? 'legal.privacy' : 'legal.terms'),
        ];
    }
}
