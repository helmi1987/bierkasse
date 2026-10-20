<?php

// Backend: beer stock.
return [
    'title' => 'Lager',
    'intro' => 'Bestand = gekaufte Biere (Kassenbuch «Bierkauf») + Inventur-Korrekturen − abgestrichene Biere. Abstreichen wird nie blockiert; der Bestand kann negativ werden.',
    'stock' => 'Lagerbestand',
    'beers' => '{1} :count Bier|[*] :count Biere',
    'low_badge' => 'tief',
    'low_alert' => 'Bierlager tief: :stock Biere (Mindestbestand :min).',
    'minimum' => 'Mindestbestand',
    'minimum_hint' => 'Fällt der Bestand durch Abstreichen unter diesen Wert, geht einmal eine Mail an alle Kassiers/Admins (wenn Benachrichtigungen an sind). Erneut erst, nachdem der Bestand wieder darüber war. 0 = keine Warnung.',
    'minimum_short' => 'Mindestbestand :min',
    'minimum_off' => 'Keine Warnung',
    'save' => 'Speichern',
    'correct' => 'Lagerbestand korrigieren',
    'counted' => 'Gezählter Bestand',
    'note' => 'Bemerkung',
    'correct_submit' => 'Korrektur erfassen',
    'corrected' => 'Lagerbestand korrigiert (:delta).',
    'corrections' => 'Inventur-Korrekturen',
    'corrections_none' => 'Noch keine Korrekturen.',
    'correction_row' => 'gezählt :counted (:delta)',
    'by' => 'von',
    'mail_subject' => 'Bierlager tief: :stock Biere',
    'mail_line' => 'Der Lagerbestand ist auf :stock Biere gefallen (Mindestbestand :min). Bitte Bier nachkaufen und im Kassenbuch als «Bierkauf» mit Anzahl erfassen.',
];
