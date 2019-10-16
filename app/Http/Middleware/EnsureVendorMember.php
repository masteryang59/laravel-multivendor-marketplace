<?php

namespace App\Http\Middleware;

use App\Enums\VendorRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the vendor the signed-in user works for and makes it the only
 * vendor the /vendor area can touch. Controllers read it from the request
 * and query through its relations, so another vendor's ids simply 404.
 *
 * Usage: ->middleware('vendor') or ->middleware('vendor:owner').
 */
class EnsureVendorMember
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $membership = $request->user()?->membership()->with('vendor')->first();
        if (! $membership) {
            return redirect()->route('sell.create');
        }
        if (! $membership->vendor->isApproved()) {
            return redirect()->route('sell.status');
        }
        if ($role !== null && $membership->role !== VendorRole::from($role)) {
            abort(403, 'Only the shop owner can do this.');
        }

        $request->attributes->set('vendor', $membership->vendor);
        $request->attributes->set('membership', $membership);
        View::share('currentVendor', $membership->vendor);
        View::share('currentMembership', $membership);

        return $next($request);
    }
}

function parseInput($data = null) {
    if ($data === null) return null;
    return $data;
}