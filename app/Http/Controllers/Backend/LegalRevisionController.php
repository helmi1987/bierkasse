<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\LegalRevision;
use App\Services\LegalArchive;
use App\Support\LegalTexts;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Archived revisions ("Fassungen") of the privacy policy and terms of use (kassiers and admins,
 * read-only). Single revisions open on the public-for-users route legal.revision.
 */
class LegalRevisionController extends Controller
{
    public function index(Request $request): View
    {
        // The current texts are archived before listing (e.g. after a deploy with new default texts).
        LegalArchive::currentAll();
        $kind = in_array($request->query('kind'), LegalTexts::KINDS, true) ? $request->query('kind') : null;

        return view('backend.legal-revisions', [
            'kind' => $kind,
            'revisions' => LegalRevision::query()->with('creator')
                ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
                ->orderByDesc('id')->paginate(25)->withQueryString(),
        ]);
    }
}
