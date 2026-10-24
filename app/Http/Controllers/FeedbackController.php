<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Services\FeedbackNotifier;
use App\Support\FormToken;
use App\Support\SystemInfo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Feedback form for every active user (/konto/feedback). The feedback is stored first;
 * the mail to the recipients (FeedbackNotifier) may fail without affecting the submission.
 */
class FeedbackController extends Controller
{
    /** Submissions per user and hour. */
    public const MAX_PER_HOUR = 5;

    public function show(Request $request): View
    {
        return view('account.feedback', [
            'page' => $this->previousPath($request),
            'hasEmail' => (string) $request->user()->email !== '',
            'types' => Feedback::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $key = 'feedback-user:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            return back()->withInput($request->only(['type', 'message', 'wants_reply']))
                ->with('error', __('feedback.throttle', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]));
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(Feedback::TYPES)],
            'message' => ['required', 'string', 'min:'.Feedback::MESSAGE_MIN, 'max:'.Feedback::MESSAGE_MAX],
            'page' => ['nullable', 'string', 'max:255'],
        ]);

        if (! FormToken::consume($request, 'feedback')) {
            return redirect()->route('account.feedback')->with('warning', __('feedback.duplicate'));
        }
        RateLimiter::hit($key, 3600);

        $page = (string) ($data['page'] ?? '');
        $feedback = Feedback::query()->create([
            'user_id' => $user->id,
            'type' => $data['type'],
            'message' => trim($data['message']),
            // A reply is only possible with an e-mail address.
            'wants_reply' => $request->boolean('wants_reply') && (string) $user->email !== '',
            'page' => str_starts_with($page, '/') ? $page : null,
            'user_agent' => Str::limit((string) $request->userAgent(), 250, '') ?: null,
            'app_version' => SystemInfo::appVersion()['version'] ?? null,
        ]);

        FeedbackNotifier::send($feedback);

        return redirect()->route('account.feedback')->with('status', __('feedback.thanks'));
    }

    /** Path of the page the user came from (same host only), e.g. "/konto". */
    private function previousPath(Request $request): ?string
    {
        $previous = (string) $request->headers->get('referer', '');
        if ($previous === '' || parse_url($previous, PHP_URL_HOST) !== $request->getHost()) {
            return null;
        }
        $path = (string) (parse_url($previous, PHP_URL_PATH) ?: '/');

        return $path === '/konto/feedback' ? null : Str::limit($path, 250, '');
    }
}
