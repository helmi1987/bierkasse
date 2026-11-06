<?php

// "Entsorgungsbonus" (1.32.0): bonus for taking out the empties.
return [
    // Dashboard (users)
    'heading' => 'Leergut entsorgt?',
    'intro' => 'Hast du das Leergut weggebracht? Melde es hier, der Bonus wird dir gutgeschrieben.',
    'report' => 'Leergut entsorgt',
    'registered' => 'Entsorgung wurde registriert.',
    'withdraw' => 'Zurückziehen',
    'withdraw_confirm' => 'Entsorgung zurückziehen?',
    'notice' => '{1} 🧴 Leergut-Bonus: :count Bier gutgeschrieben|[2,*] 🧴 Leergut-Bonus: :count Biere gutgeschrieben',
    'tx_label' => '{1} Leergut-Bonus: :count Bier à :price|[2,*] Leergut-Bonus: :count Biere à :price',

    // Flash messages and errors
    'reported' => 'Danke! Die Entsorgung wurde registriert.',
    'withdrawn' => 'Entsorgung zurückgezogen.',
    'blocked' => 'Eine Entsorgung kann zurzeit nicht gemeldet werden.',
    'disabled' => 'Der Entsorgungsbonus ist ausgeschaltet.',
    'recipient_invalid' => 'Für dieses Konto kann keine Entsorgung erfasst werden.',
    'withdraw_expired' => 'Diese Entsorgung kann nicht mehr zurückgezogen werden.',
    'blocked_backend' => 'Zurzeit gesperrt (Sperrfrist oder Mindestmenge). Mit «Sperre übersteuern» und Begründung trotzdem erfassen.',
    'self_forbidden' => 'Eine eigene Entsorgung bitte im eigenen Konto melden.',
    'reported_for' => 'Entsorgung für :user erfasst.',
    'reason_required' => 'Bitte eine Begründung für das Übersteuern angeben.',

    // Backend page "Entsorgung"
    'title' => 'Entsorgung',
    'intro' => 'Bonus für das Wegbringen des Leerguts: Benutzer melden die Entsorgung im Konto, nach Ablauf der Rückzugsfrist wird der Bonus als Guthaben gebucht.',
    'guide_heading' => 'Anleitung',
    'guide' => [
        'Benutzer melden im Konto mit «Leergut entsorgt», dass sie das Leergut weggebracht haben. Die Karte erscheint nur, wenn eine Meldung möglich ist; sonst sehen Benutzer gar nichts (keinen Hinweis, keine Frist, keinen Namen).',
        'Nach jeder Meldung gilt eine Sperrfrist für alle (Tage): Erst danach kann wieder jemand eine Entsorgung melden.',
        'Zusätzlich muss seit der letzten Entsorgung eine Mindestmenge Bier aus dem Lager gegangen sein. Es zählen bestätigte Biere, spendierte Runden und Gäste-Verkäufe; Lagerkorrekturen (z. B. Bruch) und verschenkte Biere zählen nicht. Beide Bedingungen müssen erfüllt sein.',
        'Einschalten ist ein Neustart: Frühere Entsorgungen zählen für Sperrfrist und Mindestmenge nicht mehr, die erste Entsorgung ist sofort möglich.',
        'Während der Rückzugsfrist (Minuten) kann die Meldung zurückgezogen werden. Danach wird der Bonus automatisch gebucht und erst dann ausgelost (fest oder zufällig von 1 bis zum Maximum). Vorher kennt niemand die Anzahl, auch Kassier und Admin nicht.',
        'Der Bonus ist Guthaben wie ein geschenktes Bier (Anzahl × aktueller Bierpreis): Er wurde nicht getrunken, zählt nicht für das Lager und nicht für die Bier-Statistik.',
        'Kassier und Admin können unten eine Entsorgung für einen Benutzer erfassen (nicht für sich selbst). Ist es gerade gesperrt, geht das nur mit «Sperre übersteuern» und einer Begründung; die Begründung steht im Protokoll.',
        'Zurückziehen: eigene Meldungen durch den Benutzer selbst, alle Meldungen durch Kassier und Admin. Eine von Kassier oder Admin erfasste Entsorgung kann der Benutzer nicht zurückziehen.',
        'Ein gebuchter Bonus kann beim Benutzer unter Buchungen wie andere Buchungen storniert werden; die Entsorgung bleibt als gebucht stehen.',
        'Statistik und Rangliste: Die Rangliste der Entsorgungen sehen Benutzer nur, wenn «Rangliste öffentlich» eingeschaltet ist.',
    ],

    // Settings
    'settings' => 'Einstellungen',
    'enabled' => 'Entsorgungsbonus aktiv',
    'enabled_hint' => 'Einschalten setzt alles zurück: Frühere Entsorgungen zählen nicht mehr, die erste ist sofort möglich.',
    'mode' => 'Bonus',
    'mode_fixed' => 'Feste Anzahl Biere',
    'mode_random' => 'Zufällig von 1 bis',
    'fixed' => 'Anzahl Biere',
    'random_max' => 'Maximum Biere',
    'lock_days' => 'Sperrfrist für alle (Tage)',
    'lock_days_hint' => '0 = keine Sperrfrist.',
    'min_beers' => 'Mindestmenge seit der letzten Entsorgung (Biere)',
    'min_beers_hint' => 'Biere, Runden und Gäste-Verkäufe; Lagerkorrekturen zählen nicht. 0 = keine Mindestmenge.',
    'withdraw_minutes' => 'Rückzugsfrist (Minuten)',
    'withdraw_minutes_hint' => 'Bis dahin kann die Meldung zurückgezogen werden; danach wird der Bonus ausgelost und gebucht. 0 = sofort.',
    'ranking_public' => 'Rangliste öffentlich',
    'ranking_public_hint' => 'Zeigt Benutzern die Rangliste der Entsorgungen in der Statistik.',
    'save' => 'Speichern',
    'saved' => 'Einstellungen gespeichert.',

    // Status
    'status' => 'Stand',
    'status_off' => 'Ausgeschaltet.',
    'status_enabled_since' => 'Eingeschaltet seit :date.',
    'status_last' => 'Letzte Entsorgung: :user am :date.',
    'status_none' => 'Noch keine Entsorgung seit dem Einschalten (die nächste ist sofort möglich).',
    'status_locked_until' => 'Gesperrt bis :date.',
    'status_lock_over' => 'Sperrfrist abgelaufen.',
    'status_beers' => 'Biere seit der letzten Entsorgung: :count von :min.',
    'status_open' => 'Eine Meldung ist jetzt möglich.',
    'status_blocked' => 'Eine Meldung ist zurzeit nicht möglich.',

    // Report for a user
    'for_user' => 'Entsorgung für Benutzer erfassen',
    'for_user_intro' => 'Für jemanden, der die Entsorgung nicht selbst melden kann. Nicht für dich selbst.',
    'user' => 'Benutzer',
    'user_choose' => 'Bitte wählen',
    'override' => 'Sperre übersteuern',
    'override_hint' => 'Nur nötig, wenn Sperrfrist oder Mindestmenge eine Meldung zurzeit verhindern.',
    'reason' => 'Begründung',
    'submit' => 'Entsorgung erfassen',

    // List
    'list' => 'Entsorgungen',
    'list_none' => 'Noch keine Entsorgungen.',
    'by_self' => 'selbst',
    'reported_by' => 'erfasst von :user',
    'booking_at' => 'Buchung am :time',
    'override_reason' => 'Übersteuert: :reason',
    'state' => [
        'reported' => 'gemeldet',
        'withdrawn' => 'zurückgezogen',
        'booked' => 'gebucht',
    ],
    'withdrawn_by' => 'zurückgezogen von :user',
    'withdrawn_system' => 'ohne Bonus verworfen (Konto nicht mehr aktiv)',
    'beers' => '{1} :count Bier|[*] :count Biere',
    'storno_note' => 'Gutschrift storniert',
];
