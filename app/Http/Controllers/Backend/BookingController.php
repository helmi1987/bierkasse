<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * "Buchungen" (kassier and admin): page shell of the Livewire component App\Livewire\Backend\Bookings
 * (tabs "Runden", "Geschenke", "Gäste").
 */
class BookingController extends Controller
{
    public function index(): View
    {
        return view('backend.bookings');
    }
}
