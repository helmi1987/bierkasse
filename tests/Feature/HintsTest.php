<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\Feedback;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Hints;
use App\Support\StatsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Collapsed help texts (1.33.0, markup since 1.33.1): <x-hint-toggle> renders an info button at the end of a title or
 * label, <x-hint> the hidden text block below it; the open keys are remembered per account (users.open_hints) via
 * POST /konto/hinweise. Errors, alerts, auth and backend stay as they were.
 */
class HintsTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => -3000, 'beers' => 30, 'method' => 'cash', 'note' => 'Kauf']);
        $this->member = $this->user(['first_name' => 'Hanna', 'last_name' => 'Hinweis']);
        Ledger::bookDeposit($this->member, 3000, 'cash', $this->kassier());
        // A colleague so the round and gift cards have someone to choose.
        $this->user(['first_name' => 'Kurt', 'last_name' => 'Kollege']);
    }

    /** The opening tag of the text block of one hint key, or null. */
    private function hintTag(string $html, string $key): ?string
    {
        return preg_match('/<div class="hint hint-text[^"]*" id="[^"]+" data-hint="'.preg_quote($key, '/').'"[^>]*>/', $html, $m) ? $m[0] : null;
    }

    /** The toggle button of one hint key (up to its opening tag), or null. */
    private function toggleTag(string $html, string $key): ?string
    {
        return preg_match('/<button type="button" class="hint-toggle" data-hint-toggle="'.preg_quote($key, '/').'"[^>]*>/', $html, $m) ? $m[0] : null;
    }

    private function toggleHtml(string $key, bool $open = false): string
    {
        $id = 'hint-'.str_replace('.', '-', $key);

        return '<button type="button" class="hint-toggle" data-hint-toggle="'.$key.'" aria-controls="'.$id.'" aria-expanded="'.($open ? 'true' : 'false').'" aria-label="Hilfe anzeigen" title="Hilfe anzeigen"><span class="hint-icon" aria-hidden="true">i</span></button>';
    }

    /** Whether $text appears inside any hint text block. */
    private function insideHint(string $html, string $text): bool
    {
        return preg_match('/<div class="hint hint-text[^"]*"[^>]*>(?:(?!<\/div>).)*'.preg_quote($text, '/').'/s', $html) === 1;
    }

    public function test_hints_are_closed_by_default_on_all_user_pages(): void
    {
        $this->actingAs($this->member);
        $pages = [
            '/konto' => ['round.intro' => __('round.intro'), 'deposit.intro' => __('account.deposit_intro')],
            '/konto/einstellungen' => ['settings.stats' => __('account.stats_hint'), 'settings.email' => __('account.email_hint')],
            '/statistik' => ['stats.ranking' => __('stats.ranking_hint')],
            '/konto/feedback' => ['feedback.message' => __('feedback.message_hint', ['min' => Feedback::MESSAGE_MIN, 'max' => Feedback::MESSAGE_MAX])],
            '/konto/einladen' => ['invite.hash' => __('account.invitation_hash_note')],
        ];

        foreach ($pages as $url => $hints) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('aria-expanded="true"', $html, $url);
            $this->assertStringNotContainsString('<details class="hint', $html, $url);
            foreach ($hints as $key => $text) {
                $id = 'hint-'.str_replace('.', '-', $key);
                $this->assertSame(1, substr_count($html, $this->toggleHtml($key)), "$url $key toggle");
                $this->assertStringContainsString('<div class="hint hint-text" id="'.$id.'" data-hint="'.$key.'" hidden>'.e($text).'</div>', $html, "$url $key text");
            }
        }
    }

    public function test_toggles_sit_at_the_end_of_titles_and_texts_open_below(): void
    {
        $html = $this->actingAs($this->member)->get('/konto')->getContent();

        // Round card: toggle inside the <h2> in the card summary, the text directly after the summary.
        $this->assertMatchesRegularExpression(
            '/<summary><h2>Runde spendieren '.preg_quote($this->toggleHtml('round.intro'), '/').'<\/h2><\/summary>\s*<div class="hint hint-text" id="hint-round-intro" data-hint="round.intro" hidden>/',
            $html
        );
        $this->assertMatchesRegularExpression('/data-hint-toggle="deposit.intro"[^>]*>.*?<\/button><\/h2><\/summary>\s*<div class="hint hint-text" id="hint-deposit-intro"/s', $html);
        // No collapsible box between a toggle and its text, nowhere a <details> hint box.
        foreach (['round.intro', 'gift.intro', 'guest.intro', 'deposit.intro', 'round.extra', 'round.total'] as $key) {
            $id = 'hint-'.str_replace('.', '-', $key);
            $toggle = strpos($html, 'data-hint-toggle="'.$key.'"');
            $text = strpos($html, 'id="'.$id.'"');
            if ($toggle === false && $text === false) {
                continue;
            }
            $this->assertNotFalse($toggle, $key);
            $this->assertNotFalse($text, $key);
            $this->assertLessThan($text, $toggle, $key);
            $this->assertStringNotContainsString('<details', substr($html, $toggle, $text - $toggle), $key);
        }
        $this->assertStringContainsString('<span class="label">'.__('round.extra').' '.$this->toggleHtml('round.extra').'</span>', $html);
        $this->assertMatchesRegularExpression('/<strong data-round-amount>[^<]*<\/strong> '.preg_quote($this->toggleHtml('round.total'), '/').'<\/p>/', $html);
        // TWINT message partial: toggle at the label, text right below it; without $hintKey the plain hint.
        $partial = (string) $this->view('partials.twint-message', ['username' => 'hanna', 'hintKey' => 'deposit.twint_message']);
        $this->assertMatchesRegularExpression('/<span class="label">'.preg_quote(__('account.twint_message').' '.$this->toggleHtml('deposit.twint_message'), '/').'<\/span>\s*<div class="hint hint-text" id="hint-deposit-twint_message" data-hint="deposit.twint_message" hidden>/', $partial);
        $plain = (string) $this->view('partials.twint-message', ['username' => 'hanna']);
        $this->assertStringNotContainsString('hint-toggle', $plain);
        $this->assertStringContainsString('<span class="hint">'.e(__('account.twint_message_hint')).'</span>', $plain);
        // Undo toggles only with an undo list.
        $this->assertNull($this->toggleTag($html, 'consume.undo'));
        $this->assertNull($this->hintTag($html, 'consume.undo'));
    }

    public function test_undo_toggle_is_added_to_the_card_title_with_the_undo_list(): void
    {
        $this->actingAs($this->member);
        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 1]))->assertRedirect();

        $html = $this->get('/konto')->getContent();
        $this->assertStringContainsString('<h2>'.__('account.beer').' '.$this->toggleHtml('consume.undo').'</h2>', $html);
        $this->assertMatchesRegularExpression('/<ul class="undo-list">.*?<\/ul>\s*<div class="hint hint-text" id="hint-consume-undo" data-hint="consume.undo" hidden>/s', $html);
    }

    public function test_no_toggle_inside_a_label(): void
    {
        foreach ([$this->member, $this->kassier()] as $user) {
            $this->actingAs($user);
            foreach (['/konto', '/konto/einstellungen', '/konto/feedback'] as $url) {
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertStringContainsString('class="hint-toggle"', $html, $url);
                $this->assertSame(0, preg_match_all('/<label[^>]*>(?:(?!<\/label>).)*hint-toggle/s', $html), $url);
            }
        }

        // Field labels get a label row with the toggle next to the label.
        $html = $this->actingAs($this->member)->get('/konto/einstellungen')->getContent();
        $this->assertStringContainsString('<div class="label-row"><label for="email">'.__('account.email').'</label>'.$this->toggleHtml('settings.email').'</div>', $html);
    }

    public function test_the_js_class_is_set_by_an_inline_script_with_the_nonce(): void
    {
        $response = $this->actingAs($this->member)->get('/konto')->assertOk();
        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $m);
        $this->assertNotEmpty($m[1] ?? null);

        $this->assertStringContainsString('<script nonce="'.$m[1].'">document.documentElement.classList.add(\'js\')</script>', $response->getContent());
        $css = File::get(public_path('css/app.css'));
        $this->assertStringContainsString('html:not(.js) .hint-text[hidden]', $css);
        $this->assertStringContainsString('html:not(.js) .hint-toggle { display: none; }', $css);
    }

    public function test_centered_hints_keep_the_center_class(): void
    {
        $html = $this->actingAs($this->member)->get('/konto')->getContent();

        $this->assertSame('<div class="hint hint-text center" id="hint-round-total" data-hint="round.total" hidden>', $this->hintTag($html, 'round.total'));
    }

    public function test_opening_a_hint_is_remembered_per_account(): void
    {
        $this->actingAs($this->member);

        $this->postJson('/konto/hinweise', ['key' => 'round.intro', 'open' => 1])
            ->assertOk()->assertExactJson(['key' => 'round.intro', 'open' => true]);
        $this->assertSame(['round.intro'], $this->member->refresh()->open_hints);

        $html = $this->get('/konto')->getContent();
        $this->assertSame('<div class="hint hint-text" id="hint-round-intro" data-hint="round.intro">', $this->hintTag($html, 'round.intro'));
        $this->assertStringContainsString($this->toggleHtml('round.intro', true), $html);
        $this->assertSame('<div class="hint hint-text" id="hint-deposit-intro" data-hint="deposit.intro" hidden>', $this->hintTag($html, 'deposit.intro'));
        $this->assertStringContainsString($this->toggleHtml('deposit.intro'), $html);
        $this->assertSame(1, substr_count($html, 'aria-expanded="true"'));

        // Another user is not affected.
        $other = $this->user();
        $this->actingAs($other);
        $this->assertSame('<div class="hint hint-text" id="hint-round-intro" data-hint="round.intro" hidden>', $this->hintTag($this->get('/konto')->getContent(), 'round.intro'));

        $this->actingAs($this->member);
        $this->postJson('/konto/hinweise', ['key' => 'consume.undo', 'open' => true])->assertOk();
        $this->assertSame(['consume.undo', 'round.intro'], $this->member->refresh()->open_hints);
        $this->postJson('/konto/hinweise', ['key' => 'round.intro', 'open' => 1])->assertOk();
        $this->assertSame(['consume.undo', 'round.intro'], $this->member->refresh()->open_hints);

        $this->postJson('/konto/hinweise', ['key' => 'round.intro', 'open' => 0])
            ->assertOk()->assertExactJson(['key' => 'round.intro', 'open' => false]);
        $this->assertSame(['consume.undo'], $this->member->refresh()->open_hints);
        $html = $this->get('/konto')->getContent();
        $this->assertSame('<div class="hint hint-text" id="hint-round-intro" data-hint="round.intro" hidden>', $this->hintTag($html, 'round.intro'));
        $this->assertStringContainsString($this->toggleHtml('round.intro'), $html);
    }

    public function test_invalid_hint_requests_are_rejected(): void
    {
        $this->actingAs($this->member);

        $this->postJson('/konto/hinweise', ['key' => 'round.nope', 'open' => 1])->assertStatus(422)->assertJsonValidationErrors('key');
        $this->postJson('/konto/hinweise', ['key' => str_repeat('a', 41), 'open' => 1])->assertStatus(422)->assertJsonValidationErrors('key');
        $this->postJson('/konto/hinweise', ['open' => 1])->assertStatus(422)->assertJsonValidationErrors('key');
        $this->postJson('/konto/hinweise', ['key' => 'round.intro'])->assertStatus(422)->assertJsonValidationErrors('open');
        $this->postJson('/konto/hinweise', ['key' => 'round.intro', 'open' => 'vielleicht'])->assertStatus(422)->assertJsonValidationErrors('open');
        $this->assertNull($this->member->refresh()->open_hints);
    }

    public function test_guests_cannot_save_hints_and_get_no_save_url(): void
    {
        $this->post('/konto/hinweise', ['key' => 'round.intro', 'open' => 1])->assertRedirect(route('login'));
        $this->postJson('/konto/hinweise', ['key' => 'round.intro', 'open' => 1])->assertUnauthorized();

        $this->assertStringNotContainsString('data-hints-url', $this->get('/login')->getContent());
        $html = $this->actingAs($this->member)->get('/konto')->getContent();
        $this->assertStringContainsString('data-hints-url="'.route('account.hints').'"', $html);
        $this->assertMatchesRegularExpression('/data-hints-token="[A-Za-z0-9]{40}"/', $html);
    }

    public function test_errors_and_alerts_are_not_collapsed(): void
    {
        $this->actingAs($this->member);
        $error = 'Bitte mindestens ein Bier wählen.';

        $html = $this->from('/konto')->followingRedirects()
            ->post('/konto/runde', $this->tokened('round', ['recipients' => [], 'extra' => 0]))
            ->assertOk()->getContent();

        $this->assertStringContainsString($error, $html);
        $this->assertFalse($this->insideHint($html, $error));
        // Live values and the "no colleagues" notice stay outside, too.
        $this->assertFalse($this->insideHint($html, 'round-total'));
    }

    public function test_backend_pages_are_unchanged(): void
    {
        $this->actingAs($this->kassier());

        $this->get('/admin/entsorgung')->assertOk()->assertDontSee('hint-text', false)->assertDontSee('hint-toggle', false);
        $html = $this->get('/admin/statistik?zeitraum=frei')->assertOk()->assertDontSee('hint-text', false)->assertDontSee('hint-toggle', false)->getContent();
        $text = e(__('stats.period.custom_hint', ['years' => StatsPeriod::MAX_YEARS, 'days' => StatsPeriod::DAILY_MAX_DAYS]));
        $this->assertStringContainsString('<p class="hint">'.$text.'</p>', $html);

        // The user statistics page wraps the same text.
        $html = $this->get('/statistik?zeitraum=frei')->assertOk()->getContent();
        $this->assertNotNull($this->hintTag($html, 'stats.period_custom'));
        $this->assertTrue($this->insideHint($html, $text));
        // Toggle after the submit button inside the custom form, the text below the form.
        $this->assertMatchesRegularExpression('/<button type="submit" class="btn small primary">'.__('stats.period.show').'<\/button>\s*'.preg_quote($this->toggleHtml('stats.period_custom'), '/').'\s*<\/form>\s*<div class="hint hint-text" id="hint-stats-period_custom"/', $html);
    }

    public function test_every_key_has_exactly_one_toggle_and_one_text(): void
    {
        $toggles = [];
        $texts = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            $content = $file->getContents();
            $path = str_replace('\\', '/', $file->getRelativePathname());
            preg_match_all('/<x-hint-toggle key="([^"]+)"/', $content, $m);
            foreach ($m[1] as $key) {
                $this->assertContains($key, Hints::KEYS, $path);
                $toggles[$key] = ($toggles[$key] ?? 0) + 1;
            }
            preg_match_all('/<x-hint key="([^"]+)"/', $content, $m);
            foreach ($m[1] as $key) {
                $this->assertContains($key, Hints::KEYS, $path);
                $texts[$key] = ($texts[$key] ?? 0) + 1;
            }
            // The two partials render both parts for the key passed as $hintKey.
            preg_match_all("/'hintKey' => '([^']+)'/", $content, $m);
            foreach ($m[1] as $key) {
                $this->assertContains($key, Hints::KEYS, $path);
                $toggles[$key] = ($toggles[$key] ?? 0) + 1;
                $texts[$key] = ($texts[$key] ?? 0) + 1;
            }
            if (str_contains($content, ':key="$hintKey"')) {
                $this->assertSame(1, substr_count($content, '<x-hint-toggle :key="$hintKey"'), $path);
                $this->assertSame(1, substr_count($content, '<x-hint :key="$hintKey"'), $path);
            }
            if (preg_match('#^(backend|auth|guest|legal)/|^account/leave\.blade\.php$|^setup\.blade\.php$|^disposals/_ranking\.blade\.php$#', $path)) {
                $this->assertStringNotContainsString('<x-hint', $content, $path);
            }
        }

        $all = array_fill_keys(Hints::KEYS, 1);
        ksort($toggles);
        ksort($texts);
        ksort($all);
        $this->assertSame($all, $toggles);
        $this->assertSame($all, $texts);
        $this->assertSame(Hints::KEYS, array_values(array_unique(Hints::KEYS)));
        foreach (Hints::KEYS as $key) {
            $this->assertLessThanOrEqual(Hints::MAX_KEY_LENGTH, strlen($key), $key);
        }
    }

    public function test_is_open_and_set(): void
    {
        $this->assertFalse(Hints::isOpen(null, 'round.intro'));
        $this->assertFalse(Hints::isOpen($this->member, 'round.intro'));

        // Unknown keys stored earlier are dropped, the list stays sorted, unique and capped.
        $this->member->forceFill(['open_hints' => ['zzz.gone', 'stats.ranking']])->save();
        Hints::set($this->member, 'consume.undo', true);
        $this->assertSame(['consume.undo', 'stats.ranking'], $this->member->refresh()->open_hints);
        $this->assertTrue(Hints::isOpen($this->member, 'stats.ranking'));

        foreach (Hints::KEYS as $key) {
            Hints::set($this->member, $key, true);
        }
        $all = Hints::KEYS;
        sort($all);
        $this->assertSame($all, $this->member->refresh()->open_hints);
        $this->assertCount(count(Hints::KEYS), $this->member->open_hints);

        foreach (Hints::KEYS as $key) {
            Hints::set($this->member, $key, false);
        }
        $this->assertNull($this->member->refresh()->open_hints);

        $this->expectException(InvalidArgumentException::class);
        Hints::set($this->member, 'round.nope', true);
    }

    public function test_components_reject_unknown_keys(): void
    {
        foreach (['<x-hint key="round.typo">Text</x-hint>', '<x-hint-toggle key="round.typo" />'] as $template) {
            try {
                $this->blade($template);
                $this->fail('Unknown key was rendered: '.$template);
            } catch (\Throwable $e) {
                while (! $e instanceof InvalidArgumentException && $e->getPrevious() !== null) {
                    $e = $e->getPrevious();
                }
                $this->assertInstanceOf(InvalidArgumentException::class, $e, $template);
                $this->assertStringContainsString('round.typo', $e->getMessage());
            }
        }
    }
}
