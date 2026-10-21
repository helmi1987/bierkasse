<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Models\Setting;
use App\Services\Audit;
use App\Services\FeedbackNotifier;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Feedback list and mail recipients (admin only).
 */
class FeedbackController extends Controller
{
    /** Maximum number of fixed recipient addresses. */
    public const MAX_RECIPIENTS = 20;

    public function index(): View
    {
        return view('backend.feedback', [
            'feedback' => Feedback::query()->with('user')->latest('id')->paginate(30),
            'unread' => Feedback::unreadCount(),
            'recipientMode' => AppSettings::feedbackRecipientMode(),
            'recipients' => AppSettings::feedbackRecipients(),
            'effectiveRecipients' => FeedbackNotifier::recipients(),
        ]);
    }

    /** Toggles read/unread. */
    public function read(Request $request, Feedback $feedback): RedirectResponse
    {
        $feedback->forceFill(['read_at' => $request->boolean('read') ? now() : null])->save();

        return back()->with('status', $feedback->read_at ? __('feedback.marked_read') : __('feedback.marked_unread'));
    }

    public function destroy(Feedback $feedback): RedirectResponse
    {
        DB::transaction(function () use ($feedback) {
            $feedback->delete();
            Audit::log('feedback.delete', $feedback, [
                'user_id' => $feedback->user_id,
                'type' => $feedback->type,
                'message' => Str::limit((string) $feedback->message, 200),
            ]);
        });

        return back()->with('status', __('feedback.deleted'));
    }

    public function recipients(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'recipient_mode' => ['required', Rule::in(AppSettings::FEEDBACK_RECIPIENT_MODES)],
            'recipients' => ['nullable', 'string', 'max:5000'],
        ]);

        $addresses = FeedbackNotifier::parseAddresses((string) ($data['recipients'] ?? ''));
        $errors = [];
        foreach ($addresses as $address) {
            if (Validator::make(['a' => $address], ['a' => ['email', 'max:255']])->fails()) {
                $errors[] = __('feedback.recipient_invalid', ['address' => $address]);
            }
        }
        if ($data['recipient_mode'] === AppSettings::FEEDBACK_TO_FIXED && $addresses === []) {
            $errors[] = __('feedback.recipients_required');
        }
        if (count($addresses) > self::MAX_RECIPIENTS) {
            $errors[] = __('feedback.recipients_too_many', ['max' => self::MAX_RECIPIENTS]);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['recipients' => $errors]);
        }

        Setting::putMany([
            AppSettings::FEEDBACK_RECIPIENT_MODE => $data['recipient_mode'],
            AppSettings::FEEDBACK_RECIPIENTS => $addresses,
        ]);

        return redirect()->route('backend.feedback')->with('status', __('feedback.recipients_saved'));
    }
}
