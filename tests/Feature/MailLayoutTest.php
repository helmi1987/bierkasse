<?php

namespace Tests\Feature;

use App\Mail\KassierNotice;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use App\Support\AppSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MailLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put(AppSettings::APP_NAME, 'Testkasse');
    }

    /** Sends through the array transport and returns the Symfony message (multipart, CID). */
    private function deliver(KassierNotice $mail): Email
    {
        Mail::mailer('array')->to('kassier@example.org')->send($mail);
        $email = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    public function test_notice_renders_layout_table_and_button(): void
    {
        $mail = new KassierNotice(
            'Neue Einzahlung von Hans',
            ['Hans meldet eine Einzahlung.'],
            'https://bier.example/backend',
            'Im Backend bestätigen',
            ['Benutzer' => 'Hans <b>', 'Betrag' => chf(1050), 'Bemerkung' => ''],
            'Neue Einzahlung gemeldet',
            ['Danke.'],
        );

        $html = $mail->render();
        $this->assertStringContainsString('Testkasse', $html);
        $this->assertStringContainsString('Neue Einzahlung gemeldet', $html);
        $this->assertStringContainsString('Hans meldet eine Einzahlung.', $html);
        $this->assertMatchesRegularExpression('#>Benutzer</td>\s*<td[^>]*>Hans &lt;b&gt;</td>#', $html);
        $this->assertMatchesRegularExpression('#>Betrag</td>\s*<td[^>]*>CHF 10.50</td>#', $html);
        $this->assertStringNotContainsString('>Bemerkung<', $html, 'empty rows are skipped');
        $this->assertStringContainsString('href="https://bier.example/backend"', $html);
        $this->assertStringContainsString('>Im Backend bestätigen</a>', $html);
        $this->assertStringContainsString('Diese Mail wurde automatisch von Testkasse gesendet.', $html);
        $this->assertStringContainsString('alt="Testkasse Logo"', $html);
        $this->assertSame('[Testkasse] Neue Einzahlung von Hans', $mail->envelope()->subject);

        $email = $this->deliver($mail);
        $text = (string) $email->getTextBody();
        $this->assertStringContainsString('Testkasse', $text);
        $this->assertStringContainsString('Benutzer: Hans <b>', $text);
        $this->assertStringContainsString('Betrag: CHF 10.50', $text);
        $this->assertStringContainsString("Im Backend bestätigen:\nhttps://bier.example/backend", $text);
        $this->assertStringNotContainsString('<td', $text);
        $this->assertStringContainsString('src="cid:', (string) $email->getHtmlBody());
        $this->assertCount(1, $email->getAttachments(), 'logo embedded inline');
    }

    public function test_notice_defaults_to_backend_dashboard_and_subject_as_title(): void
    {
        $mail = new KassierNotice('Etwas Neues', ['Text']);
        $html = $mail->render();

        $this->assertStringContainsString('href="'.route('backend.dashboard').'"', $html);
        $this->assertStringContainsString('>Im Backend öffnen</a>', $html);
        $this->assertMatchesRegularExpression('#<h1[^>]*>Etwas Neues</h1>#', $html);
    }

    public function test_missing_logo_does_not_break_the_mail(): void
    {
        $logo = public_path('icon-192.png');
        $backup = $logo.'.bak-test';
        rename($logo, $backup);
        try {
            $email = $this->deliver(new KassierNotice('Ohne Logo', ['Text']));
            $this->assertStringNotContainsString('cid:', (string) $email->getHtmlBody());
            $this->assertStringContainsString('Testkasse', (string) $email->getHtmlBody());
            $this->assertCount(0, $email->getAttachments());
        } finally {
            rename($backup, $logo);
        }
    }

    public function test_deposit_and_payout_mails_show_facts_as_rows(): void
    {
        Mail::fake();
        $this->kassier(['email' => 'kassier@example.org']);
        $user = $this->user(['username' => 'hans']);

        Ledger::reportDeposit($user, 2000, 'twint', 'Mai');
        Mail::assertSent(KassierNotice::class, function (KassierNotice $m) use ($user) {
            $html = $m->render();

            return $m->rows['Benutzer'] === $user->displayName()
                && $m->rows['Betrag'] === 'CHF 20.00'
                && $m->rows['Art'] === __('ledger.method.twint')
                && $m->rows['Bemerkung'] === 'Mai'
                && isset($m->rows['Zeit'])
                && str_contains($html, 'CHF 20.00')
                && str_contains($html, 'Im Backend bestätigen')
                && str_contains($html, 'href="'.route('backend.dashboard').'"');
        });

        Ledger::confirmDeposit(Transaction::query()->pending()->first(), $this->admin());
        Ledger::requestPayout($user);
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => $m->title === 'Auszahlung beantragt'
            && $m->rows['Betrag'] === 'CHF 20.00'
            && ! isset($m->rows['Art']));
    }

    public function test_registration_mail_button_points_to_user(): void
    {
        Mail::fake();
        Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_PASSWORD);
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
        $this->kassier(['email' => 'kassier@example.org']);

        $this->post('/registrieren', [
            'code' => 'durst', 'username' => 'neu.user', 'password' => 'password-123', 'password_confirmation' => 'password-123',
        ]);

        Mail::assertSent(KassierNotice::class, function (KassierNotice $m) {
            $user = User::query()->where('username', 'neu.user')->firstOrFail();
            $html = $m->render();

            return $m->url === route('backend.users.show', $user)
                && str_contains($html, '>Benutzer im Backend ansehen</a>')
                && str_contains($html, 'Neue Registrierung')
                && ! isset($m->rows['Eingeladen von'], $m->rows['Übertrag'], $m->rows['E-Mail']);
        });
    }

    public function test_password_reset_mail_uses_layout_in_german(): void
    {
        $user = $this->user(['username' => 'hans', 'email' => 'hans@example.org']);
        $mail = (new ResetPassword('tok'))->toMail($user);

        $this->assertSame('Testkasse: Passwort zurücksetzen', $mail->subject);
        $html = (string) $mail->render();
        $this->assertStringContainsString('Testkasse', $html);
        $this->assertStringContainsString('Hallo '.$user->displayName(), $html);
        $this->assertStringContainsString('Für dein Konto wurde ein neues Passwort angefordert.', $html);
        $this->assertStringContainsString('href="'.e($mail->actionUrl).'"', $html);
        $this->assertStringContainsString('>Passwort zurücksetzen</a>', $html);
        $this->assertStringContainsString('Diese Mail wurde automatisch von Testkasse gesendet.', $html);
        $this->assertStringContainsString('Minuten gültig', $html);

        $user->notify(new ResetPassword('tok'));
        $email = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $text = (string) $email->getTextBody();
        $this->assertStringContainsString("Passwort zurücksetzen:\n".$mail->actionUrl, $text);
        $this->assertStringContainsString('Hast du nichts angefordert', $text);
        $this->assertStringContainsString('src="cid:', (string) $email->getHtmlBody());
    }
}
