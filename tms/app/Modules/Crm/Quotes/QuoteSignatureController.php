<?php

namespace App\Modules\Crm\Quotes;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** « Faire signer en ligne » a sent quote (« Bon pour accord »). */
class QuoteSignatureController extends Controller
{
    public function store(Request $request, Quote $quote, QuoteSignatures $signatures): RedirectResponse
    {
        Gate::authorize('update', $quote);
        $user = $request->user();
        assert($user instanceof User);
        $signatures->request($user, $quote, (string) $request->input('email', ''));

        return back()->with('status', "Lien de signature du devis {$quote->number} envoyé.");
    }
}
