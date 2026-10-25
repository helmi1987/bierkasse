<?php

/*
 * Livewire (introduced in 1.20.0 for rebuilt admin pages, see docs/entwicklung.md "Livewire").
 * Only the values that differ from the package defaults are set here.
 */
return [
    'class_namespace' => 'App\\Livewire',
    'class_path' => app_path('Livewire'),
    'view_path' => resource_path('views/livewire'),

    // The layout loads @livewireStyles/@livewireScripts itself (only on pages with a component),
    // so nothing is injected into other responses.
    'inject_assets' => false,

    // No progress bar for wire:navigate (not used); avoids an injected style element.
    'navigate' => [
        'show_progress_bar' => false,
        'progress_bar_color' => '#d97706',
    ],

    // Strict CSP without 'unsafe-eval': Livewire serves its CSP build (Alpine CSP evaluator).
    'csp_safe' => true,

    // Own pagination markup (resources/views/livewire/pagination.blade.php) is passed explicitly.
    'pagination_theme' => 'tailwind',
];
