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
            // Empty: the copy is kept in the platform's own database.
            'database' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('main.shop_mirrors', 'database')],
            'db_username' => ['nullable', 'string', 'max:64'],
            'db_password' => ['nullable', 'string', 'max:255'],
        ], [
            'slug.regex' => 'Small letters, digits and hyphens only, for example tayyaba-medicose.',
            'host.regex' => 'Enter the address only, for example shop.example.com — no http:// and no slashes.',
            'database.regex' => 'A database name is letters, digits and underscores only.',
        ]);

        $data['host'] = filled($data['host'] ?? null) ? strtolower($data['host']) : null;

        $data['database'] = filled($data['database'] ?? null) ? $data['database'] : null;
        $created = false;

        if ($data['database'] === null) {
            // In the platform's own database: nothing to create or reach.
            if (ShopMirror::query()->max('id') >= \App\Domain\Mirror\MergedImporter::MAX_SHOPS) {
                return back()->withInput()->withErrors(['database' => 'No more shops fit in the main database. Give this one a database of its own.']);
            }

            $mirror = new ShopMirror($data);
            $mirror->token_hash = hash('sha256', (string) random_bytes(32));
        } else {
            $failed = $this->prepareOwnDatabase($data, $mirror, $created);

            if ($failed !== null) {
                return $failed;
            }
        }

        $mirror->save();

        return redirect()->route('admin.shop-mirrors.index')
            ->with('issued', ['name' => $mirror->name, 'token' => $mirror->issueToken()])
            ->with('status', "{$mirror->name} registered".($created ? ', and its database was created' : '').'. Copy its token now — it is shown once.');
    }

    /**
     * A copy with a database of its own: made here when the platform's login
     * may make one, then reached, so a wrong name or login is found now and
     * not at the shop's first snapshot. Returns the refusal, or null when ready.
     */
    private function prepareOwnDatabase(array $data, ?ShopMirror &$mirror, bool &$created): ?RedirectResponse
    {
        // The copy must never be the platform's own database: a snapshot
        // replaces everything in the database it is loaded into.
        if ($data['database'] === config('database.connections.main.database')) {
            return back()->withInput()->withErrors(['database' => 'That is the platform\'s own database. Leave the box empty to keep the copy in it safely, or name a different one.']);
        }

        $mirror = new ShopMirror($data);
        $mirror->token_hash = hash('sha256', (string) random_bytes(32));

        /*
         * The database is made here when the platform's login is allowed to
         * make one, so there is nothing to do on the server first. On hosting
         * that does not allow it (most control panels), this quietly does
         * nothing and the check below says what to do instead. The name is
         * letters, digits and underscores only — validated above.
         */
        try {
            $existed = DB::connection('main')->select('select schema_name from information_schema.schemata where schema_name = ?', [$data['database']]) !== [];

            if (! $existed) {
                DB::connection('main')->statement('CREATE DATABASE `'.$data['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $created = true;
            }
        } catch (\Throwable) {
            // Not allowed to: it has to be created in the hosting panel.
        }

        // Reached now, so a wrong name or login is found here and not at the
        // shop's first snapshot.
        try {
            config(['database.connections.mirror' => $mirror->connectionConfig()]);
            DB::purge('mirror');
            DB::connection('mirror')->getPdo();
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
            $login = $mirror->db_username ?: config('database.connections.main.username');

            // Said in terms of what to do about it: the three ways this fails
            // look alike in MySQL's own words and need different fixes.
            $message = match (true) {
                str_contains($reason, '[1045]') => filled($mirror->db_username)
                    ? "The database user '{$login}' and the password entered here were refused. Check them against the hosting panel — or leave both boxes empty to use the site's own database login."
                    : "The site's own database login was refused for this database. Enter the user and password the hosting panel shows for it.",
                str_contains($reason, '[1044]') => "The database user '{$login}' is not allowed into '{$data['database']}'. In the hosting panel, give that user access to it.",
                str_contains($reason, '[1049]') => "There is no database called '{$data['database']}', and this site is not allowed to create one. Create it, empty, in the hosting panel and let '{$login}' into it.",
                default => 'That database could not be opened. ('.mb_substr($reason, 0, 160).')',
            };

            return back()->withInput()->withErrors(['database' => $message]);
        } finally {
            DB::purge('mirror');
        }

        return null;
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
        $merged = $shopMirror->isMerged();

        // A copy kept in the platform's own database is taken out of it; one
        // with a database of its own is simply no longer served.
        if ($merged) {
            app(\App\Domain\Mirror\MergedImporter::class)->purge($shopMirror);
        }

        $shopMirror->delete();

        return redirect()->route('admin.shop-mirrors.index')
            ->with('status', $merged
                ? "{$name} is no longer registered, and its copy was removed. The shop's own books are untouched."
                : "{$name} is no longer registered. Its database was not touched.");
    }
}
