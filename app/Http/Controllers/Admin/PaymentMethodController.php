<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessPaymentMethod;
use App\Models\PaymentMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The platform's list of payment methods: the wallets and banks any business
 * may switch on. Guarded by the platform.admin middleware on its routes.
 *
 * Nothing here is ever deleted — a method a business has taken money through
 * has an account behind it — so retiring one means switching it off.
 */
class PaymentMethodController extends Controller
{
    public function index(): View
    {
        $inUse = BusinessPaymentMethod::query()->acrossAllBusinesses()
            ->where('is_active', true)
            ->selectRaw('payment_method_id, COUNT(*) as businesses')
            ->groupBy('payment_method_id')
            ->pluck('businesses', 'payment_method_id');

        return view('admin.payment-methods.index', [
            'methods' => PaymentMethod::query()->orderBy('kind', 'desc')->orderBy('sort_order')->orderBy('name')->get()
                ->groupBy('kind'),
            'inUse' => $inUse,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('payment_methods', 'name')],
            'kind' => ['required', Rule::in([PaymentMethod::WALLET, PaymentMethod::BANK])],
        ]);

        PaymentMethod::create([
            ...$data,
            'is_active' => true,
            'sort_order' => (int) PaymentMethod::max('sort_order') + 10,
        ]);

        return back()->with('status', "{$data['name']} added. Businesses can now switch it on in their settings.");
    }

    public function update(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('payment_methods', 'name')->ignore($paymentMethod->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $paymentMethod->update($data);

        return back()->with('status', "{$paymentMethod->name} updated.");
    }
}
