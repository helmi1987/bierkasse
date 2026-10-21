<?php

// Login texts. "failed", "password" and "throttle" are also used by Laravel itself.
return [
    'failed' => 'Benutzername oder Passwort falsch.',
    'password' => 'Das Passwort ist falsch.',
    'throttle' => 'Zu viele Versuche. Bitte in :seconds s erneut versuchen.',

    'inactive' => 'Dieses Konto ist nicht mehr aktiv.',
    'login_expired' => 'Anmeldung abgelaufen. Bitte erneut anmelden.',
    'logged_out' => 'Abgemeldet. Prost!',
    'totp_invalid' => 'Code ungültig.',
    'totp_too_many' => 'Zu viele falsche Codes. Bitte erneut anmelden.',
    'must_change_password' => 'Bitte zuerst ein neues Passwort setzen.',

    'login' => [
        'title' => 'Anmelden',
        'tagline' => 'Bier nehmen, abstreichen, fertig.',
        'with_provider' => 'Mit :provider anmelden',
        'or' => 'oder',
        'username' => 'Benutzername oder E-Mail',
        'username_only' => 'Benutzername',
        'password' => 'Passwort',
        'remember' => 'Angemeldet bleiben',
        'submit' => 'Anmelden',
        'register' => 'Registrieren',
        'forgot' => 'Passwort vergessen',
        'sso_hint' => 'SSO funktioniert nur, wenn das Konto in den Einstellungen verknüpft wurde.',
    ],

    'totp' => [
        'title' => 'Bestätigungscode',
        'code' => 'Code aus der Authenticator-App',
        'hint' => '6 Ziffern – oder einen Wiederherstellungscode (xxxxx-xxxxx).',
        'submit' => 'Bestätigen',
        'cancel' => 'Abbrechen',
    ],
];
