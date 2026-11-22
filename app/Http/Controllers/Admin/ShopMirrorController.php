<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopMirror;
use App\Support\MirrorHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Shops that run on their own computer and keep a read-only copy here.
 *
 * Registering one gives it an address and a database on the platform, and a
 * token for its computer to send snapshots with. Guarded by the
 * platform.admin middleware on its routes, and never offered on a copy's own
 * address: the list belongs to the platform.
 */
class ShopMirrorController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-platform');
        abort_if(MirrorHost::active() || app(\App\Domain\Mirror\SyncStatus::class)->isShop(), 404);

        return view('admin.shop-mirrors.index', [
            'mirrors' => ShopMirror::query()->orderBy('name')->get(),
            // Shown once, straight after it is made, and never again.
            'issued' => session('issued'),
            'receiveUrl' => route('mirror.receive'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-platform');
        abort_if(MirrorHost::active() || app(\App\Domain\Mirror\SyncStatus::class)->isShop(), 404);

        $request->merge(['slug' => \Illuminate\Support\Str::slug((string) ($request->input('slug') ?: $request->input('name')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            // The short name in its link: platform.example/copy/<this>
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('main.shop_mirrors', 'slug')],
            // Optional: an address of its own, as well as the link.
            'host' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', Rule::unique('main.shop_mirrors', 'host')],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('main.shop_mirrors', 'database')],
            'db_username' => ['nullable', 'string', 'max:64'],
            'db_password' => ['nullable', 'string', 'max:255'],
        ], [
            'slug.regex' => 'Small letters, digits and hyphens only, for example tayyaba-medicose.',
            'host.regex' => 'Enter the address only, for example shop.example.com — no http:// and no slashes.',
            'database.regex' => 'A database name is letters, digits and underscores only.',
        ]);

        $data['host'] = filled($data['host'] ?? null) ? strtolower($data['host']) : null;

        // The copy must never be the platform's own database: a snapshot
        // replaces everything in the database it is loaded into.
        if ($data['database'] === config('database.connections.main.database')) {
            return back()->withInput()->withErrors(['database' => 'That is the platform\'s own database. A shop\'s copy needs one of its own.']);
        }

        $mirror = new ShopMirror($data);
        $mirror->token_hash = hash('sha256', (string) random_bytes(32));

        // Reached now, so a wrong name or login is found here and not at the
        // shop's first snapshot.
        try {
            config(['database.connections.mirror' => $mirror->connectionConfig()]);
            DB::purge('mirror');
            DB::connection('mirror')->getPdo();
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors([
                'database' => 'That database could not be opened. Create it first, and make sure this login is allowed into it. ('.mb_substr($e->getMessage(), 0, 160).')',
            ]);
        } finally {
            DB::purge('mirror');
        }

        $mirror->save();

        return redirect()->route('admin.shop-mirrors.index')
            ->with('issued', ['name' => $mirror->name, 'token' => $mirror->issueToken()])
            ->with('status', "{$mirror->name} registered. Copy its token now — it is shown once.");
    }

    /** A new token, when the old one is lost or should stop working. */
    public function token(ShopMirror $shopMirror): RedirectResponse
    {
        Gate::authorize('manage-platform');
        abort_if(MirrorHost::active() || app(\App\Domain\Mirror\SyncStatus::class)->isShop(), 404);

        return redirect()->route('admin.shop-mirrors.index')
            ->with('issued', ['name' => $shopMirror->name, 'token' => $shopMirror->issueToken()])
            ->with('status', "A new token was made for {$shopMirror->name}. The old one no longer works.");
    }

    /** Stops serving and accepting the copy. Its database is left as it is. */
    public function destroy(ShopMirror $shopMirror): RedirectResponse
    {
        Gate::authorize('manage-platform');
        abort_if(MirrorHost::active() || app(\App\Domain\Mirror\SyncStatus::class)->isShop(), 404);

        $name = $shopMirror->name;
        $shopMirror->delete();

        return redirect()->route('admin.shop-mirrors.index')
            ->with('status', "{$name} is no longer registered. Its database was not touched.");
    }
}
