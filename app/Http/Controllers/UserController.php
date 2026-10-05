<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\StaffInvitation;
use App\Support\AvatarProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            'role' => ['required', 'in:super_admin,team_member'],
            ...$this->permissionRules(),
        ]);

        $user = $this->createInvitedUser($data, $request->user());

        return $user;
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'in:super_admin,team_member'],
            ...$this->permissionRules(),
            // Their position and bio, for proposals' team sections.
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);
        if (array_key_exists('bio', $data)) {
            $data['bio'] = User::cleanBio($data['bio']);
        }

        // Nobody changes their own access -- which also means the super
        // admin doing this always remains one, so there's always at least one.
        $changesAccess = (isset($data['role']) && $data['role'] !== $user->role) || array_key_exists('permissions', $data);
        abort_if($changesAccess && $user->id === $request->user()->id, 422, "You can't change your own role or permissions.");

        $user->update(collect($data)->except('permissions')->all());
        if (array_key_exists('permissions', $data) || isset($data['role'])) {
            $this->setPermissions($user, $data['permissions'] ?? $user->permissions);
        }

        return $user->fresh();
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

    // Permanent deletion, unlike destroy() above. Only allowed when the user
    // has no time entries -- deleting them would cascade-delete that history
    // (and anything invoiced from it). Deactivate them instead in that case.
    public function forceDestroy(User $user)
    {
        $this->authorize('delete', $user);

        abort_if($user->timeEntries()->exists(), 422, 'This user has logged time entries and can\'t be permanently deleted. Deactivate them instead.');

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->delete();

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

    // Someone's photo (their avatar), set from the Team page.
    public function updateAvatar(Request $request, User $user)
    {
        $this->authorize('update', $user);
        $request->validate(['avatar' => ['required', 'image', 'max:5120']]);

        $old = $user->avatar_path;
        $user->update(['avatar_path' => AvatarProcessor::store($request->file('avatar'))]);
        AvatarProcessor::delete($old);

        return $user->fresh();
    }

    public function destroyAvatar(User $user)
    {
        $this->authorize('update', $user);
        AvatarProcessor::delete($user->avatar_path);
        $user->update(['avatar_path' => null]);

        return $user->fresh();
    }

    // Someone's bio photo (for proposals), set from the Team page.
    public function updateBioPhoto(Request $request, User $user)
    {
        $this->authorize('update', $user);
        $request->validate(['photo' => ['required', 'image', 'max:8192']]);

        return $user->replaceBioPhoto($request->file('photo'));
    }

    public function destroyBioPhoto(User $user)
    {
        $this->authorize('update', $user);

        return $user->replaceBioPhoto(null);
    }

    protected function createInvitedUser(array $data, User $invitedBy): User
    {
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
        ]);
        $this->setPermissions($user, $data['permissions'] ?? []);
        $user->password = Hash::make(Str::random(40)); // unusable until they set their own via the invite link
        $user->invited_by = $invitedBy->id;
        $user->invited_at = now();
        $user->save();

        $rawToken = $this->issueInviteToken($user);
        $user->notify(new StaffInvitation($rawToken));

        return $user;
    }

    // Permissions are only granted to team members (a super admin has all
    // of them) and only from the grantable list -- never settings or team.
    protected function permissionRules(): array
    {
        return [
            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(config('permissions.grantable')))],
        ];
    }

    protected function setPermissions(User $user, ?array $permissions): void
    {
        $user->forceFill([
            'permissions' => $user->isSuperAdmin() ? null : (array_values(array_unique($permissions ?? [])) ?: null),
        ]);
        if ($user->exists) {
            $user->save();
        }
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
