<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Users\Actions\CreateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->when($request->string('q')->toString(), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")
            ))
            ->with('businesses')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'filters' => $request->only('q'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.create');
    }

    public function store(StoreUserRequest $request, CreateUser $action): RedirectResponse
    {
        $user = $action->handle($request->validated());

        return redirect()
            ->route('admin.users.index')
            ->with('status', "{$user->name} created. Assign them to a business to give them access.");
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        $user->load('businesses');

        return view('admin.users.edit', ['user' => $user]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        $user->name = $data['name'];
        $user->email = strtolower(trim($data['email']));

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        // Never let the last platform admin demote themselves out of the system.
        if ($request->user()->isNot($user)) {
            $user->is_platform_admin = (bool) ($data['is_platform_admin'] ?? false);
        }

        $user->save();

        return redirect()
            ->route('admin.users.index')
            ->with('status', "{$user->name} updated.");
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        $user->is_active = ! $user->is_active;
        $user->save();

        return back()->with(
            'status',
            $user->is_active
                ? "{$user->name} reactivated."
                : "{$user->name} deactivated. Their access is revoked immediately."
        );
    }
}
