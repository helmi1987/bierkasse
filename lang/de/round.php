<?php

// "Runde spendieren": a user pays beers for colleagues and guests.
return [
    'heading' => 'Runde spendieren',
    'intro' => 'Du bezahlst die Biere, die Kollegen trinken sie. Jeder angehakte Kollege bekommt 1 Bier. Mit «Runde inkl. mir» ist dein eigenes Bier gleich dabei.',
    'filter' => 'Kollegen suchen',
    'colleagues' => 'Kollegen',
    'no_colleagues' => 'Noch keine anderen aktiven Benutzer.',
    'extra' => 'Weitere Biere (z. B. für Gäste)',
    'extra_hint' => 'Biere ohne Namen, max. :max.',
    'message' => 'Nachricht (freiwillig)',
    'message_placeholder' => 'z. B. Prost zum Geburtstag!',
    'total' => 'Total',
    'total_hint' => 'Mindestens 1, höchstens :max Biere pro Runde, à :price.',
    'submit' => 'Runde spendieren (:count)',
    'submit_self' => 'Runde inkl. mir (:count)',
    'beers' => '{0} :count Biere|{1} :count Bier|[2,*] :count Biere',
    'guests_count' => '{1} :count Gast|[2,*] :count Gäste',
    'booked' => '{1} Runde spendiert: :count Bier (:amount). Prost!|[2,*] Runde spendiert: :count Biere (:amount). Prost!',
    'undone' => 'Runde zurückgenommen.',
    'undo_hint' => 'Eigene Runden kannst du :minutes Minuten lang zurücknehmen.',
    'none_selected' => 'Bitte mindestens ein Bier wählen.',
    'too_many' => 'Höchstens :max Biere pro Runde.',
    'message_too_long' => 'Die Nachricht darf höchstens :max Zeichen lang sein.',
    'recipient_invalid' => 'Ungültige Auswahl: Kollegen müssen aktiv sein (nicht du selbst).',
    'tx_label' => '{1} Runde spendiert (:count Bier)|[2,*] Runde spendiert (:count Biere)',
    'tx_for' => 'für :names',
    'notice' => ':giver hat dir ein Bier spendiert',
    'given_kpi' => 'Spendiert',

    // Account settings
    'mail_setting' => 'Mail bei spendiertem Bier',
    'mail_setting_hint' => 'Nur mit hinterlegter E-Mail-Adresse.',
    'mail_setting_saved' => 'Gespeichert.',

    // Mail to recipients
    'mail_subject' => 'Prost! :giver spendiert dir ein Bier',
    'mail_title' => 'Prost! :giver spendiert dir ein Bier',
    'mail_line' => ':giver hat eine Runde spendiert, ein Bier davon ist für dich.',
    'mail_row_giver' => 'Spender',
    'mail_row_message' => 'Nachricht',
    'mail_row_beers' => 'Runde',
    'mail_row_time' => 'Zeit',
    'mail_button' => 'Zur Bierkasse',
    'mail_hint' => 'Diese Mail kannst du in der Bierkasse unter Einstellungen abschalten.',

    // Backend
    'backend_title' => 'Runden',
    'backend_intro' => 'Benutzer bezahlen eine Runde für Kollegen (je 1 Bier) und Gäste. Der Betrag geht vom Guthaben des Spenders ab, Lager und Statistik zählen die Biere (bei den Kollegen bzw. als «Gäste (eingeladen)»).',
    'allowed' => 'Runde spendieren erlauben',
    'allowed_hint' => 'Aus: die Karte im Dashboard ist ausgeblendet, neue Runden sind nicht möglich. Bestehende Runden bleiben gebucht.',
    'save' => 'Speichern',
    'list' => 'Letzte Runden',
    'list_none' => 'Noch keine Runden.',
    'list_total' => 'Total: :beers Biere, :amount',
];
