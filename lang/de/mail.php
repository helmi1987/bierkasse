<?php

// Mails (layout and notices to the kassiers).
return [
    // Layout
    'automatic' => 'Diese Mail wurde automatisch von :app gesendet.',
    'button_fallback' => 'Falls der Button nicht funktioniert, diesen Link im Browser öffnen:',
    'button_backend' => 'Im Admin-Bereich öffnen',
    'button_confirm' => 'Im Admin-Bereich bestätigen',

    // Table labels
    'row_user' => 'Benutzer',
    'row_amount' => 'Betrag',
    'row_method' => 'Art',
    'row_note' => 'Bemerkung',
    'row_time' => 'Zeit',

    'deposit_subject' => 'Neue Einzahlung von :user',
    'deposit_title' => 'Neue Einzahlung gemeldet',
    'deposit_line' => ':user meldet eine Einzahlung. Sie zählt erst, wenn ein Kassier sie bestätigt.',

    'payout_subject' => 'Auszahlung beantragt: :user',
    'payout_title' => 'Auszahlung beantragt',
    'payout_line' => ':user verlässt das Haus und möchte das Guthaben ausbezahlt haben.',

    'register_subject' => 'Neue Registrierung: :user',
    'register_title' => 'Neue Registrierung',
    'register_line' => ':name hat sich registriert.',
    'register_username' => 'Benutzername',
    'register_name' => 'Name',
    'register_email' => 'E-Mail',
    'register_invited_by' => 'Eingeladen von',
    'register_code' => 'Registrierungscode',
    'register_time' => 'Registriert am',
    'register_carryover' => 'Übertrag',
    'register_carryover_hint' => 'Der Übertrag von der alten Strichliste muss im Admin-Bereich bestätigt werden.',
    'register_button' => 'Benutzer im Admin-Bereich ansehen',

    // "Entsorgungsbonus" (never with the number of beers: drawn only when booked)
    'disposal_subject' => 'Leergut entsorgt: :user',
    'disposal_title' => 'Leergut entsorgt',
    'disposal_line' => ':user hat das Leergut weggebracht. Der Bonus wird nach Ablauf der Rückzugsfrist automatisch gutgeschrieben.',
    'disposal_line_by' => 'Erfasst von :by.',
    'disposal_row_booking' => 'Gutschrift ab',
    'disposal_row_override' => 'Sperre übersteuert',
    'disposal_button' => 'Entsorgung im Admin-Bereich öffnen',

    // SSO secret expiry
    'sso_secret_subject' => 'SSO-Anbieter :name: Client-Secret läuft in :days Tagen ab',
    'sso_secret_subject_today' => 'SSO-Anbieter :name: Client-Secret läuft heute ab',
    'sso_secret_subject_expired' => 'SSO-Anbieter :name: Client-Secret abgelaufen',
    'sso_secret_line' => 'Das Client-Secret des SSO-Anbieters «:name» ist bis :date gültig. Danach funktioniert die Anmeldung mit diesem Anbieter nicht mehr.',
    'sso_secret_line_expired' => 'Das Client-Secret des SSO-Anbieters «:name» ist am :date abgelaufen. Die Anmeldung mit diesem Anbieter funktioniert nicht mehr.',
    'sso_secret_line_microsoft' => 'Neues Secret erstellen: Entra Admin Center > App-Registrierung > Zertifikate & Geheimnisse (höchstens 24 Monate gültig).',
    'sso_secret_provider' => 'Anbieter',
    'sso_secret_type' => 'Typ',
    'sso_secret_expires' => 'Gültig bis',
    'sso_secret_left' => 'Verbleibend',
    'sso_secret_left_days' => ':days Tage',
    'sso_secret_left_today' => 'Läuft heute ab',
    'sso_secret_left_expired' => 'Abgelaufen',
    'sso_secret_outro' => 'Nach dem Erneuern das neue Secret und das neue Ablaufdatum im Formular eintragen.',
    'button_sso' => 'Anbieter im Admin-Bereich öffnen',

    // Offline emergency plan (1.40.0): reminder when the printed list is out of date.
    'emergency_subject' => '{1} Notfallplan veraltet: :count Änderung seit dem Ausdruck|[2,*] Notfallplan veraltet: :count Änderungen seit dem Ausdruck',
    'emergency_title' => 'Offline-Notfallplan neu drucken',
    'emergency_line' => 'Seit dem letzten Ausdruck des Offline-Notfallplans sind :count Benutzer dazugekommen oder ausgetreten (Schwelle: :threshold).',
    'emergency_line_print' => 'Bitte den Notfallplan neu drucken und die Liste im Umschlag ersetzen.',
    'emergency_row_last' => 'Letzter Ausdruck',
    'emergency_row_new' => 'Neu',
    'emergency_row_left' => 'Ausgetreten',
    'emergency_button' => 'Notfallplan öffnen',
    'emergency_outro' => 'Diese Erinnerung kommt höchstens einmal pro Woche, bis der Notfallplan neu gedruckt ist.',
];
