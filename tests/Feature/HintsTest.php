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
 * Collapsed help texts (1.33.0): <x-hint> renders a closed <details> with an info toggle, the open keys are
 * remembered per account (users.open_hints) via POST /konto/hinweise. Errors, alerts, auth and backend stay as they were.
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

    /** The <details> tag of one hint key, or null. */
    private function hintTag(string $html, string $key): ?string
    {
        return preg_match('/<details class="hint-box[^"]*" data-hint="'.preg_quote($key, '/').'"[^>]*>/', $html, $m) ? $m[0] : null;
    }

    /** Whether $text appears inside any hint box. */
    private function insideHint(string $html, string $text): bool
    {
        return preg_match('/<details class="hint-box"[^>]*>(?:(?!<\/details>).)*'.preg_quote($text, '/').'/s', $html) === 1;
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
            $this->assertDoesNotMatchRegularExpression('/<details class="hint-box[^>]* open[ >]/', $html, $url);
            foreach ($hints as $key => $text) {
                $tag = $this->hintTag($html, $key);
                $this->assertNotNull($tag, "$url $key");
                $id = 'hint-'.str_replace('.', '-', $key);
                $this->assertMatchesRegularExpression(
                    '/data-hint="'.preg_quote($key, '/').'">\s*<summary class="hint-toggle" aria-label="Hilfe anzeigen" aria-controls="'.$id.'" aria-expanded="false" title="Hilfe anzeigen"><span class="hint-icon" aria-hidden="true">i<\/span><\/summary>\s*<div class="hint" id="'.$id.'">'.preg_quote(e($text), '/').'<\/div>/',
                    $html,
                    "$url $key"
                );
            }
        }
    }

    public function test_centered_hints_keep_the_center_class(): void
    {
        $html = $this->actingAs($this->member)->get('/konto')->getContent();

        $this->assertSame('<details class="hint-box center" data-hint="round.total">', $this->hintTag($html, 'round.total'));
    }

    public function test_opening_a_hint_is_remembered_per_account(): void
    {
        $this->actingAs($this->member);

        $this->postJson('/konto/hinweise', ['key' => 'round.intro', 'open' => 1])
            ->assertOk()->assertExactJson(['key' => 'round.intro', 'open' => true]);
        $this->assertSame(['round.intro'], $this->member->refresh()->open_hints);

        $html = $this->get('/konto')->getContent();
        $this->assertSame('<details class="hint-box" data-hint="round.intro" open>', $this->hintTag($html, 'round.intro'));
        $this->assertMatchesRegularExpression('/aria-controls="hint-round-intro" aria-expanded="true"/', $html);
        $this->assertSame('<details class="hint-box" data-hint="deposit.intro">', $this->hintTag($html, 'deposit.intro'));
        $this->assertSame(1, substr_count($html, 'aria-expanded="true"'));

        // Another user is not affected.
        $other = $this->user();
        $this->actingAs($other);
        $this->assertSame('<details class="hint-box" data-hint="round.intro">', $this->hintTag($this->get('/konto')->getContent(), 'round.intro'));

        $this->actingAs($this->member);
        $this->postJson('/konto/hinweise', ['key' => 'consume.undo', 'open' => true])->assertOk();
        $this->assertSame(['consume.undo', 'round.intro'], $this->member->refresh()->open_hints);
        $this->postJson('/konto/hinweise', ['key' => 'round.intro', 'open' => 1])->assertOk();
        $this->assertSame(['consume.undo', 'round.intro'], $this->member->refresh()->open_hints);

        $this->postJson('/konto/hinweise', ['key' => 'round.intro', 'open' => 0])
            ->assertOk()->assertExactJson(['key' => 'round.intro', 'open' => false]);
        $this->assertSame(['consume.undo'], $this->member->refresh()->open_hints);
        $this->assertSame('<details class="hint-box" data-hint="round.intro">', $this->hintTag($this->get('/konto')->getContent(), 'round.intro'));
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

        $this->get('/admin/entsorgung')->assertOk()->assertDontSee('hint-box', false);
        $html = $this->get('/admin/statistik?zeitraum=frei')->assertOk()->assertDontSee('hint-box', false)->getContent();
        $text = e(__('stats.period.custom_hint', ['years' => StatsPeriod::MAX_YEARS, 'days' => StatsPeriod::DAILY_MAX_DAYS]));
        $this->assertStringContainsString('<p class="hint">'.$text.'</p>', $html);

        // The user statistics page wraps the same text.
        $html = $this->get('/statistik?zeitraum=frei')->assertOk()->getContent();
        $this->assertNotNull($this->hintTag($html, 'stats.period_custom'));
        $this->assertTrue($this->insideHint($html, $text));
    }

    public function test_every_key_is_used_and_every_used_key_is_known(): void
    {
        $used = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('/<x-hint key="([^"]+)"|\'hintKey\' => \'([^\']+)\'/', $file->getContents(), $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $key = $match[1] !== '' ? $match[1] : $match[2];
                $this->assertContains($key, Hints::KEYS, $file->getRelativePathname());
                $used[$key] = true;
            }
            $path = str_replace('\\', '/', $file->getRelativePathname());
            if (preg_match('#^(backend|auth|guest|legal)/|^account/leave\.blade\.php$|^setup\.blade\.php$|^disposals/_ranking\.blade\.php$#', $path)) {
                $this->assertStringNotContainsString('<x-hint', $file->getContents(), $path);
            }
        }

        $this->assertSame([], array_values(array_diff(Hints::KEYS, array_keys($used))));
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

    public function test_component_rejects_unknown_keys(): void
    {
        try {
            $this->blade('<x-hint key="round.typo">Text</x-hint>');
            $this->fail('Unknown key was rendered.');
        } catch (\Throwable $e) {
            while (! $e instanceof InvalidArgumentException && $e->getPrevious() !== null) {
                $e = $e->getPrevious();
            }
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertStringContainsString('round.typo', $e->getMessage());
        }
    }
}
