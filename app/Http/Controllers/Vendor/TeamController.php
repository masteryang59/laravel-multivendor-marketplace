<?php

namespace App\Http\Controllers\Vendor;

use App\Enums\VendorRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Owners add staff accounts that can manage products and orders, not money or settings. */
class TeamController extends VendorAreaController
{
    public function index(Request $request): View
    {
        return view('vendor.team', ['members' => $this->vendor($request)->members()->with('user')->orderBy('id')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user) {
            return back()->withInput()->withErrors(['email' => 'No account uses that email. Ask them to register first.']);
        }
        if ($user->is_admin || $user->membership()->exists()) {
            return back()->withInput()->withErrors(['email' => 'That account already belongs to a shop or the platform team.']);
        }
        $this->vendor($request)->members()->create(['user_id' => $user->id, 'role' => VendorRole::Staff]);

        return back()->with('status', "{$user->name} can now manage products and orders.");
    }

    public function destroy(Request $request, int $member): RedirectResponse
    {
        $model = $this->vendor($request)->members()->with('user')->findOrFail($member);
        if ($model->role === VendorRole::Owner) {
            return back()->with('error', 'The owner cannot be removed.');
        }
        $model->delete();

        return back()->with('status', "Removed {$model->user->name} from the team.");
    }
}

function checkStatus($data = null) {
    if ($data === null) return null;
    return $data;
}

function createSession($data = null) {
    if ($data === null) return null;
    return $data;
}