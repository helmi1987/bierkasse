<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\EmergencyPlanPrint;
use App\Models\Setting;
use App\Services\EmergencyPlan;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Offline emergency plan (1.40.0, admins only): the page (Livewire component App\Livewire\Backend\EmergencyPlan),
 * the print view (stored print or test print) and the settings (window, texts, reminder mail).
 */
class EmergencyPlanController extends Controller
{
    /** Host page of the Livewire component; the forms post here. */
    public function index(): View
    {
        return view('backend.emergency');
    }

    /** "Notfallplan drucken": stores the print (with audit) and opens its print view. */
    public function print(Request $request): RedirectResponse
    {
        $print = EmergencyPlan::record($request->user());

        return redirect()->route('backend.emergency.sheet', $print)->with('emergency_autoprint', true);
    }

    /** Print view of a stored print (all pages, window frame hidden). */
    public function sheet(Request $request, EmergencyPlanPrint $print): View
    {
        return view('backend.emergency-sheet', EmergencyPlan::sheet($print, $request->user()) + [
            'autoprint' => (bool) $request->session()->get('emergency_autoprint', false),
        ]);
    }

    /** Test print: page 1 only with the window frame; nothing is stored. */
    public function test(Request $request): View
    {
        return view('backend.emergency-sheet', EmergencyPlan::sheet(null, $request->user()) + ['autoprint' => false]);
    }

    /** Envelope window: side and position/size in cm (step 0.1), stored in mm; has to fit on A4 inside the print margin. */
    public function window(Request $request): RedirectResponse
    {
        // Accept a decimal comma ("4,5") as well as a point.
        foreach (['top', 'edge', 'width', 'height'] as $key) {
            if (is_string($request->input($key))) {
                $request->merge([$key => str_replace(',', '.', trim($request->input($key)))]);
            }
        }
        $cm = ['required', 'numeric', 'decimal:0,1'];
        $data = $request->validate([
            'side' => ['required', Rule::in(AppSettings::EMERGENCY_WINDOW_SIDES)],
            'top' => [...$cm, 'between:0,'.(AppSettings::A4_HEIGHT / 10)],
            'edge' => [...$cm, 'between:0,'.(AppSettings::A4_WIDTH / 10)],
            'width' => [...$cm, 'between:0,'.(AppSettings::A4_WIDTH / 10)],
            'height' => [...$cm, 'between:0,'.(AppSettings::A4_HEIGHT / 10)],
        ]);
        $mm = array_map(fn ($v) => (int) round((float) $v * 10), array_intersect_key($data, array_flip(['top', 'edge', 'width', 'height'])));
        $margin = AppSettings::EMERGENCY_PRINT_MARGIN;
        $min = AppSettings::EMERGENCY_WINDOW_MIN;
        $cmText = fn (int $v) => number_format($v / 10, 1, '.', '');

        $errors = [];
        foreach (['top', 'edge'] as $key) {
            if ($mm[$key] < $margin) {
                $errors[$key] = __('emergency.window_margin', ['cm' => $cmText($margin)]);
            }
        }
        foreach (['width', 'height'] as $key) {
            if ($mm[$key] < $min) {
                $errors[$key] = __('emergency.window_min', ['cm' => $cmText($min)]);
            }
        }
        if ($errors === []) {
            if (AppSettings::A4_HEIGHT - $margin < $mm['top'] + $mm['height']) {
                $errors['height'] = __('emergency.window_too_high', ['cm' => $cmText(AppSettings::A4_HEIGHT - $margin)]);
            }
            if (AppSettings::A4_WIDTH - $margin < $mm['edge'] + $mm['width']) {
                $errors['width'] = __('emergency.window_too_wide', ['cm' => $cmText(AppSettings::A4_WIDTH - $margin)]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        Setting::put(AppSettings::EMERGENCY_WINDOW, ['side' => $data['side']] + $mm);

        return redirect()->route('backend.emergency')->with('status', __('emergency.saved'));
    }

    /** Texts of the print; a text equal to its default is not stored (later default changes apply). */
    public function texts(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (AppSettings::EMERGENCY_TEXT_KEYS as $key) {
            $rules['texts.'.$key] = ['required', 'string', 'max:'.AppSettings::EMERGENCY_TEXT_MAX[$key]];
        }
        $data = $request->validate($rules, [], array_combine(
            array_map(fn ($k) => 'texts.'.$k, AppSettings::EMERGENCY_TEXT_KEYS),
            array_map(fn ($k) => __('emergency.text.'.$k), AppSettings::EMERGENCY_TEXT_KEYS),
        ));

        $stored = [];
        foreach (AppSettings::EMERGENCY_TEXT_KEYS as $key) {
            $text = str_replace("\r\n", "\n", trim((string) $data['texts'][$key]));
            if ($text !== AppSettings::emergencyTextDefault($key)) {
                $stored[$key] = $text;
            }
        }
        Setting::put(AppSettings::EMERGENCY_TEXTS, $stored);

        return redirect()->to(route('backend.emergency').'#texte')->with('status', __('emergency.saved'));
    }

    /** "Standardtext wiederherstellen" for one text. */
    public function resetText(string $key): RedirectResponse
    {
        abort_unless(in_array($key, AppSettings::EMERGENCY_TEXT_KEYS, true), 404);
        $stored = AppSettings::emergencyTextsStored();
        unset($stored[$key]);
        Setting::put(AppSettings::EMERGENCY_TEXTS, $stored);

        return redirect()->to(route('backend.emergency').'#texte')->with('status', __('emergency.text_reset', ['text' => __('emergency.text.'.$key)]));
    }

    /** Reminder mail: threshold and recipients (active admins and kassiers). */
    public function mail(Request $request): RedirectResponse
    {
        $candidates = EmergencyPlan::recipientCandidates()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $data = $request->validate([
            'threshold' => ['required', 'integer', 'between:0,'.AppSettings::EMERGENCY_MAIL_THRESHOLD_MAX],
            'recipients' => ['nullable', 'array'],
            'recipients.*' => ['integer', Rule::in($candidates)],
        ]);
        $recipients = array_values(array_unique(array_map('intval', $data['recipients'] ?? [])));
        sort($recipients);

        Setting::putMany([
            AppSettings::EMERGENCY_MAIL_THRESHOLD => (int) $data['threshold'],
            AppSettings::EMERGENCY_MAIL_RECIPIENTS => $recipients,
        ]);

        return redirect()->to(route('backend.emergency').'#mail')->with('status', __('emergency.saved'));
    }
}
