<?php

namespace App\Livewire\Backend;

use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Models\Setting;
use App\Support\AppSettings;
use App\Support\DepositSuggestions;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Deposit proposals shown to users (tab "Einzahlungsvorschläge" of "Kasse", kassier and admin):
 * fixed CHF amounts or automatic, plus the preselected amount.
 */
class DepositSuggestionsForm extends Component
{
    use AuthorizesAdminArea;

    public string $mode = 'auto';

    public string $amounts = '';

    public string $default = '';

    public array $notice = [];

    public function mount(): void
    {
        $price = AppSettings::beerPrice();
        $configured = AppSettings::depositSuggestions();
        $this->mode = $configured === null ? 'auto' : 'manual';
        $this->amounts = implode(', ', array_map([Money::class, 'plain'], $configured ?? DepositSuggestions::defaults($price)));
        $default = AppSettings::depositDefault();
        $this->default = $default ? Money::plain($default) : '';
    }

    public function save(): void
    {
        $this->notice = [];
        $data = $this->validate([
            'mode' => ['required', Rule::in(['auto', 'manual'])],
            'amounts' => ['nullable', 'required_if:mode,manual', 'string', 'max:200'],
            'default' => ['nullable', 'string', 'max:20'],
        ]);

        $price = AppSettings::beerPrice();
        $amounts = $data['mode'] === 'auto' ? [] : $this->parse((string) $data['amounts'], $price);
        $default = $this->parseDefault((string) ($data['default'] ?? ''), DepositSuggestions::amounts($price, $amounts));

        Setting::put(AppSettings::DEPOSIT_SUGGESTIONS, $amounts);
        Setting::put(AppSettings::DEPOSIT_DEFAULT, $default);

        $this->notice = ['type' => 'ok', 'text' => __('settings.saved')];
    }

    public function render(): View
    {
        $price = AppSettings::beerPrice();
        $configured = AppSettings::depositSuggestions();
        $default = AppSettings::depositDefault();

        return view('livewire.backend.deposit-suggestions-form', [
            'price' => $price,
            'defaultBeers' => $default ? DepositSuggestions::beers($default, $price) : null,
            'preview' => array_map(fn (int $a) => ['beers' => DepositSuggestions::beers($a, $price), 'amount' => $a],
                DepositSuggestions::amounts($price, $configured)),
        ]);
    }

    /** Preselected amount: empty = first proposal (0), else one of the proposals. */
    private function parseDefault(string $input, array $amounts): int
    {
        if (trim($input) === '') {
            return 0;
        }
        $amount = Money::parse($input);
        if ($amount === null || ! in_array($amount, $amounts, true)) {
            throw ValidationException::withMessages(['default' => __('deposit_suggestions.error_default', [
                'amounts' => implode(', ', array_map([Money::class, 'plain'], $amounts)),
            ])]);
        }

        return $amount;
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
