<?php

// Password reset (Laravel broker statuses + own texts).
return [
    'reset' => 'Passwort geändert. Jetzt anmelden.',
    'sent' => 'Falls ein Konto mit E-Mail existiert, ist ein Link unterwegs.',
    'throttled' => 'Bitte etwas warten und erneut versuchen.',
    'token' => 'Der Link ist ungültig oder abgelaufen.',
    'user' => 'Der Link ist ungültig oder abgelaufen.',
    'sent_generic' => 'Falls ein Konto mit hinterlegter E-Mail existiert, ist ein Link unterwegs.',

    'forgot_title' => 'Passwort vergessen',
    'forgot_intro' => 'Benutzername oder E-Mail eingeben. Der Link kommt nur, wenn beim Konto eine E-Mail hinterlegt ist – sonst hilft der Kassier.',
    'login' => 'Benutzername oder E-Mail',
    'send' => 'Link senden',
    'reset_title' => 'Neues Passwort',
    'email' => 'E-Mail',
    'password' => 'Neues Passwort (min. 8 Zeichen)',
    'password_confirmation' => 'Wiederholen',
    'save' => 'Passwort speichern',
    'back' => 'Zurück zur Anmeldung',

    'mail_subject' => ':app: Passwort zurücksetzen',
    'mail_greeting' => 'Hallo :name',
    'mail_intro' => 'Für dein Konto wurde ein neues Passwort angefordert.',
    'mail_action' => 'Passwort zurücksetzen',
    'mail_expire' => 'Der Link ist :count Minuten gültig.',
    'mail_ignore' => 'Hast du nichts angefordert, kannst du diese Mail ignorieren.',
];
