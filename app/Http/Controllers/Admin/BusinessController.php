<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Businesses\Actions\ChangeBusinessStatus;
use App\Domain\Businesses\Actions\CreateBusiness;
use App\Domain\Businesses\Actions\DeleteBusiness;
use App\Domain\Businesses\Actions\UpdateBusiness;
use App\Enums\BusinessStatus;
use App\Enums\BusinessType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBusinessRequest;
use App\Http\Requests\Admin\UpdateBusinessRequest;
use App\Models\Business;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Business::class);

        $businesses = Business::query()
            ->when($request->string('q')->toString(), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('slug', 'like', "%{$term}%")
            ))
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            // Counts drive the deletion warning, so it can say exactly what
            // would be destroyed rather than warning in the abstract.
            ->withCount(['activeMembers', 'transactions', 'entries', 'closings'])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.businesses.index', [
            'businesses' => $businesses,
            'statuses' => BusinessStatus::cases(),
            'filters' => $request->only('q', 'status'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Business::class);

        return view('admin.businesses.create', [
            'types' => BusinessType::cases(),
            'timezones' => $this->timezones(),
        ]);
    }

    public function store(StoreBusinessRequest $request, CreateBusiness $action): RedirectResponse
    {
        $business = $action->handle($request->validated(), $request->user());

        return redirect()
            ->route('admin.businesses.show', $business)
            ->with('status', "{$business->name} created. Its opening balance is the next step.");
    }

    public function show(Business $business): View
    {
        $this->authorize('view', $business);

        $business->load(['creator', 'members' => fn ($q) => $q->orderBy('name')]);

        return view('admin.businesses.show', [
            'business' => $business,
            'assignableUsers' => User::query()
                ->where('is_active', true)
                ->whereNotIn('id', $business->members()->pluck('users.id'))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function edit(Business $business): View
    {
        $this->authorize('update', $business);

        return view('admin.businesses.edit', [
            'business' => $business,
            'types' => BusinessType::cases(),
            'timezones' => $this->timezones(),
        ]);
    }

    public function update(UpdateBusinessRequest $request, Business $business, UpdateBusiness $action): RedirectResponse
    {
        $action->handle($business, $request->validated());

        return redirect()
            ->route('admin.businesses.show', $business)
            ->with('status', 'Business details updated.');
    }

    public function changeStatus(Request $request, Business $business, ChangeBusinessStatus $action): RedirectResponse
    {
        $this->authorize('suspend', $business);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(BusinessStatus::class)],
        ]);

        try {
            $action->handle($business, BusinessStatus::from($validated['status']));
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('status', "Status changed to {$business->status->label()}.");
    }

    public function destroy(Business $business, DeleteBusiness $action): RedirectResponse
    {
        $this->authorize('delete', $business);

        $summary = $action->handle($business, request()->user());

        $detail = $summary['had_history']
            ? sprintf(
                ' %d transactions, %d days and %d closings were destroyed with it.',
                $summary['transactions'], $summary['entries'], $summary['closings']
            )
            : ' It had no financial history.';

        return redirect()
            ->route('admin.businesses.index')
            ->with('status', "{$summary['name']} deleted.{$detail}");
    }

    /** @return array<string> */
    private function timezones(): array
    {
        return ['Asia/Karachi', 'Asia/Kolkata', 'Asia/Dubai', 'Asia/Riyadh', 'UTC'];
    }
}
