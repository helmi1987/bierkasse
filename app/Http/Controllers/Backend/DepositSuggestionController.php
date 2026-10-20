<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AppSettings;
use App\Support\DepositSuggestions;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Deposit proposals shown to users (kassier and admin): fixed CHF amounts or automatic.
 */
class DepositSuggestionController extends Controller
{
    public function edit(): View
    {
        $price = AppSettings::beerPrice();
        $configured = AppSettings::depositSuggestions();

        return view('backend.deposit-suggestions', [
            'price' => $price,
            'automatic' => $configured === null,
            'input' => implode(', ', array_map([Money::class, 'plain'], $configured ?? DepositSuggestions::defaults($price))),
            'preview' => array_map(fn (int $a) => ['beers' => DepositSuggestions::beers($a, $price), 'amount' => $a],
                DepositSuggestions::amounts($price, $configured)),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['auto', 'manual'])],
            'amounts' => ['nullable', 'required_if:mode,manual', 'string', 'max:200'],
        ]);

        if ($data['mode'] === 'auto') {
            Setting::put(AppSettings::DEPOSIT_SUGGESTIONS, []);

            return back()->with('status', __('settings.saved'));
        }

        Setting::put(AppSettings::DEPOSIT_SUGGESTIONS, $this->parse((string) $data['amounts'], AppSettings::beerPrice()));

        return back()->with('status', __('settings.saved'));
    }

    /**
     * "30, 60, 90" -> [3000, 6000, 9000]. Separators: comma, semicolon, spaces (decimal point is ".").
     *
     * @return list<int>
     */
    private function parse(string $input, int $price): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['amounts' => $message]);
        $parts = preg_split('/\s*[,;]\s*|\s+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            $fail(__('deposit_suggestions.error_empty'));
        }
        if (count($parts) > DepositSuggestions::MAX) {
            $fail(__('deposit_suggestions.error_max', ['max' => DepositSuggestions::MAX]));
        }

        $amounts = [];
        foreach ($parts as $part) {
            $amount = Money::parse($part);
            if ($amount === null || $amount <= 0) {
                $fail(__('deposit_suggestions.error_invalid', ['value' => $part]));
            }
            if ($amounts !== [] && $amount <= end($amounts)) {
                $fail(__('deposit_suggestions.error_order'));
            }
            if ($amount % $price !== 0) {
                $fail(__('deposit_suggestions.error_divisible', [
                    'amount' => chf($amount),
                    'price' => chf($price),
                    'nearest' => implode(' / ', array_map('chf', DepositSuggestions::nearest($amount, $price))),
                ]));
            }
            $amounts[] = $amount;
        }

        return $amounts;
    }
}
