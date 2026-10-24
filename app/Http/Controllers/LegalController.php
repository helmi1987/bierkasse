<?php

namespace App\Http\Controllers;

use App\Models\LegalRevision;
use App\Services\Audit;
use App\Services\LegalArchive;
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

        $revisions = LegalArchive::currentAll();

        return view('legal.consent', [
            'texts' => array_map(fn (LegalRevision $revision) => $this->text($revision), $revisions),
            // Someone who accepted before sees that the texts changed.
            'renewal' => $user->consent_accepted_at !== null,
            // Revisions shown on the page (hidden fields): only these can be accepted.
            'revisions' => array_map(fn (LegalRevision $revision) => $revision->id, $revisions),
        ]);
    }

    public function accept(Request $request): RedirectResponse
    {
        $request->validate([
            'consent' => ['accepted'],
            'privacy_revision' => ['nullable', 'integer'],
            'terms_revision' => ['nullable', 'integer'],
        ], ['consent.accepted' => __('legal.consent_required')]);
        $user = $request->user();

        // Only the revisions the user has seen are accepted (strict: any new revision, even one
        // without a new "Stand" such as a changed beer price, means read again).
        $revisions = LegalArchive::currentAll();
        foreach ($revisions as $kind => $revision) {
            if ((int) $request->input($kind.'_revision') !== $revision->id) {
                return redirect()->route('consent.show')->with('warning', __('legal.consent_changed'));
            }
        }

        if ($user->needsConsent()) {
            Audit::save('consent.accept', $user->forceFill(LegalTexts::consentAttributes($revisions)));
        }

        return redirect()->intended($user->homeUrl())->with('status', __('legal.consent_saved'));
    }

    /** Dismisses the update notice of one text (no audit entry: personal and low value). */
    public function dismissNotice(Request $request, string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, LegalTexts::KINDS, true), 404);
        $request->user()->forceFill([LegalTexts::seenColumn($kind) => now()])->save();

        return back();
    }

    /**
     * One archived revision (any logged-in user: the texts were public when they were in effect).
     */
    public function revision(LegalRevision $revision): View
    {
        $rendered = $revision->rendered();

        return view('legal.revision', [
            'revision' => $revision,
            'title' => $rendered['title'] ?? __('legal.title.'.$revision->kind),
            'html' => $rendered['html'],
        ]);
    }

    private function page(string $kind): View
    {
        return view('legal.page', ['kind' => $kind] + $this->text(LegalArchive::forDisplay($kind)));
    }

    /**
     * Text of a revision (the current one: exactly the archived text is shown).
     *
     * @return array{title: string, html: HtmlString, date: string, changed: ?string, url: string}
     */
    private function text(LegalRevision $revision): array
    {
        $kind = $revision->kind;
        $rendered = $revision->rendered();

        return [
            'title' => $rendered['title'] ?? __('legal.title.'.$kind),
            'html' => $rendered['html'],
            'date' => LegalTexts::versionDate($revision->version),
            'changed' => $this->changedDate($kind),
            'url' => route($kind === LegalTexts::PRIVACY ? 'legal.privacy' : 'legal.terms'),
        ];
    }

    /** "Last changed" date, only when it differs from the "Stand" date. */
    private function changedDate(string $kind): ?string
    {
        $changed = LegalTexts::changedAt($kind);
        if ($changed === null) {
            return null;
        }
        $date = LegalTexts::versionDate($changed);

        return $date !== LegalTexts::versionDate(LegalTexts::version($kind)) ? $date : null;
    }
}
