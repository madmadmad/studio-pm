<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(User::class, 'user');
    }

    public function index()
    {
        return User::orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['required', 'in:manager,team_member'],
        ]);

        $user = $this->createInvitedUser($data, $request->user());

        return $user;
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'in:manager,team_member'],
        ]);

        if (isset($data['role']) && $data['role'] !== $user->role) {
            abort_if($user->id === $request->user()->id, 422, "You can't change your own role.");
        }

        $user->update($data);

        return $user;
    }

    // Deactivate, not delete -- their time entries/invoices keep the FK.
    // deactivated_at is deliberately not mass-fillable (it's not something
    // request input should ever set directly), so it's assigned directly here.
    public function destroy(User $user)
    {
        $user->forceFill(['deactivated_at' => now()])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        return response()->noContent();
    }

    public function reactivate(User $user)
    {
        $this->authorize('update', $user);

        $user->forceFill(['deactivated_at' => null])->save();

        return $user;
    }

    public function resendInvite(User $user)
    {
        $this->authorize('update', $user);
        abort_unless($user->hasPendingInvite(), 422, 'This user has already accepted their invite.');

        $rawToken = $this->issueInviteToken($user);
        $user->notify(new StaffInvitation($rawToken));

        return $user->fresh();
    }

    protected function createInvitedUser(array $data, User $invitedBy): User
    {
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
        ]);
        $user->password = Hash::make(Str::random(40)); // unusable until they set their own via the invite link
        $user->invited_by = $invitedBy->id;
        $user->invited_at = now();
        $user->save();

        $rawToken = $this->issueInviteToken($user);
        $user->notify(new StaffInvitation($rawToken));

        return $user;
    }

    protected function issueInviteToken(User $user): string
    {
        $rawToken = Str::random(40);

        $user->forceFill([
            'invite_token' => hash('sha256', $rawToken),
            'invite_expires_at' => now()->addDays(7),
        ])->save();

        return $rawToken;
    }
}
