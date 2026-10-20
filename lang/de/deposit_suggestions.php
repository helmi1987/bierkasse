<?php

// Backend: deposit proposals shown to users.
return [
    'title' => 'Einzahlungsvorschläge',
    'intro' => 'Beträge, die Benutzern beim Einzahlen vorgeschlagen werden. Bei einem Minus wird es dazugerechnet («inkl. Minus»). Der vorausgewählte Vorschlag steht auch im TWINT-Kasten.',
    'auto' => 'Automatisch',
    'auto_hint' => 'Kleinstes Vielfaches des Bierpreises, das durch CHF 10 teilbar ist, dann 2×, 3× und 5× davon.',
    'manual' => 'Eigene Beträge',
    'amounts' => 'Beträge in CHF',
    'amounts_hint' => 'Z. B. «30, 60, 90». Komma oder Leerzeichen trennen, Dezimalpunkt «.». Höchstens :max Werte, aufsteigend, jeder durch den Bierpreis (:price) teilbar.',
    'default' => 'Vorausgewählt (CHF)',
    'default_hint' => 'Einer der Beträge oben, z. B. «30». Leer = erster Betrag.',
    'preselected' => 'vorausgewählt',
    'error_default' => 'Der vorausgewählte Betrag muss einer der Vorschläge sein: :amounts.',
    'preview' => 'Vorschau beim aktuellen Preis',
    'save' => 'Speichern',
    'error_empty' => 'Mindestens einen Betrag angeben.',
    'error_max' => 'Höchstens :max Beträge.',
    'error_invalid' => '«:value» ist kein gültiger Betrag.',
    'error_order' => 'Beträge aufsteigend und ohne Doppelte angeben.',
    'error_divisible' => ':amount ist nicht durch den Bierpreis :price teilbar. Nächste gültige Beträge: :nearest.',
];
