<?php

// "Bier verschenken": a user gives beers as CHF credit to colleagues.
return [
    'heading' => 'Bier verschenken',
    'intro' => 'Du bezahlst, die Kollegen bekommen den Betrag als Guthaben (Anzahl × aktueller Preis) und trinken das Bier, wann sie wollen. Bei mehreren Kollegen bekommt jeder gleich viele.',
    'filter' => 'Kollegen suchen',
    'colleagues' => 'An',
    'quantity' => 'Biere pro Person',
    'message' => 'Nachricht (freiwillig)',
    'message_placeholder' => 'z. B. Danke für die Hilfe!',
    'total' => 'Total',
    'submit' => 'Verschenken',
    'booked' => '{1} :count Bier verschenkt (:amount).|[2,*] :count Biere verschenkt (:amount).',
    'undone' => 'Geschenk zurückgenommen.',
    'undo_hint' => 'Eigene Geschenke kannst du :minutes Minuten lang zurücknehmen.',
    'undo_used' => 'Nicht mehr rückgängig: ein Empfänger hat seit dem Geschenk schon Bier gebucht.',
    'none_selected' => 'Bitte mindestens einen Kollegen wählen.',
    'tx_given' => '{1} Bier verschenkt (:count Bier)|[2,*] Bier verschenkt (:count Biere)',
    'tx_to' => 'an :names',
    'tx_received' => '{1} Bier geschenkt von :giver (:count Bier)|[2,*] Bier geschenkt von :giver (:count Biere)',
    'notice' => '{1} :giver hat dir :count Bier geschenkt|[2,*] :giver hat dir :count Biere geschenkt',
    'given_kpi' => 'Verschenkt',
    'received_kpi' => 'Geschenkt bekommen',

    // Backend
    'backend_title' => 'Verschenkt',
    'backend_intro' => 'Benutzer verschenken Biere als Guthaben an Kollegen (gleiche Anzahl pro Person, aktueller Preis). Der Betrag geht vom Guthaben des Schenkers ab und dem Empfänger gutgeschrieben. Lager und Kassenstand ändern sich nicht; getrunken wird später normal.',
    'allowed' => 'Bier verschenken erlauben',
    'allowed_hint' => 'Aus: die Karte im Dashboard ist ausgeblendet, neue Geschenke sind nicht möglich. Bestehende bleiben gebucht.',
    'save' => 'Speichern',
    'list' => 'Letzte Geschenke',
    'list_none' => 'Noch keine Geschenke.',
    'list_total' => 'Total: :beers Biere, :amount',
];
