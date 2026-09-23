<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-semibold text-gray-900">Opening balance</h1>
                    <x-badge :classes="$opening->status->badgeClasses()">{{ $opening->status->label() }}</x-badge>
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $business->name }} · as at {{ $opening->opening_date->format('d F Y') }}
                </p>
            </div>
            <div class="flex gap-2">
                @if ($opening->isEditable())
                    <a href="{{ route('admin.businesses.opening.edit', $business) }}"
                       class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Edit draft
                    </a>
                @elseif ($opening->transaction)
                    <a href="{{ route('businesses.ledger.account', [$business, '3900']) }}"
                       target="_blank" rel="opener"
                       onclick="return openWorkspace(event, this, '{{ $business->slug }}')"
                       class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        View journal
                    </a>
                @endif
                <a href="{{ route('admin.businesses.show', $business) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Back to business
                </a>
            </div>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        @if ($opening->finalized_at)
            <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <strong>Opening balance finalized on {{ $opening->finalized_at->format('d F Y') }}
                by {{ $opening->finalizer->name }}.</strong>
                Financial tracking for {{ $business->name }} begins on
                {{ $opening->opening_date->format('d F Y') }}. All balances from that date are calculated
                from recorded transactions and cannot be edited directly.
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <x-panel title="Position as confirmed">
                    @include('admin.opening._position', ['position' => $position, 'fields' => $fields])
                </x-panel>

                @if ($opening->transaction)
                    <x-panel title="Opening journal"
                             description="Posted {{ $opening->transaction->posted_at->format('d M Y H:i') }} — transaction #{{ $opening->transaction->id }}">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Account</th>
                                        <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Debit</th>
                                        <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Credit</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($opening->transaction->lines as $line)
                                        <tr>
                                            <td class="px-4 py-2 sm:px-6">
                                                <span class="font-mono text-xs text-gray-500">{{ $line->account->code }}</span>
                                                {{ $line->account->name }}
                                            </td>
                                            <td class="px-4 py-2 text-right font-mono tabular-nums">
                                                {{ $line->debit->isZero() ? '' : $line->debit->format() }}
                                            </td>
                                            <td class="px-4 py-2 text-right font-mono tabular-nums sm:px-6">
                                                {{ $line->credit->isZero() ? '' : $line->credit->format() }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-panel>
                @endif

                @can('correct', $opening)
                    <x-panel title="Correct the opening balance"
                             description="The original entry is never edited. A correction is a dated adjustment, and both stay visible.">
                        <form method="POST" action="{{ route('admin.businesses.opening.correct', $business) }}"
                              class="space-y-4 p-4 sm:p-6">
                            @csrf
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="account" value="Figure to correct" />
                                    <select id="account" name="account" required
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                        @foreach ($fields as $field)
                                            <option value="{{ $field->key() }}">{{ $field->label }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('account')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="amount" value="Increase / decrease by" />
                                    <x-text-input id="amount" name="amount" type="text" inputmode="decimal"
                                                  class="mt-1 block w-full text-right font-mono tabular-nums"
                                                  placeholder="200000 or -50000" />
                                    <p class="mt-1 text-xs text-gray-500">Use a minus sign to reduce the figure.</p>
                                    <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                                </div>
                            </div>
                            <div>
                                <x-input-label for="reason" value="Reason" />
                                <x-text-input id="reason" name="reason" type="text" class="mt-1 block w-full"
                                              placeholder="Opening stock understated — Godown 2 not included in the count" required />
                                <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                            </div>
                            <div class="flex justify-end">
                                <button class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                    Post correction
                                </button>
                            </div>
                        </form>
                    </x-panel>
                @endcan

                @if ($corrections->isNotEmpty())
                    <x-panel title="Corrections since finalization">
                        <div class="divide-y divide-gray-100 text-sm">
                            @foreach ($corrections as $correction)
                                <div class="px-4 py-3 sm:px-6">
                                    <div class="flex items-baseline justify-between gap-3">
                                        <span class="font-medium text-gray-900">{{ $correction->narration }}</span>
                                        <span class="font-mono tabular-nums text-gray-900">{{ $correction->amount->format() }}</span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        {{ $correction->business_date->format('d M Y') }} ·
                                        {{ $correction->creator->name }} ·
                                        <span class="text-amber-700">{{ $correction->correction_reason }}</span>
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </x-panel>
                @endif
            </div>

            <div class="space-y-6">
                <x-panel title="Record">
                    <dl class="divide-y divide-gray-100 text-sm">
                        <div class="flex justify-between px-4 py-3 sm:px-6">
                            <dt class="text-gray-500">Status</dt>
                            <dd class="font-medium text-gray-900">{{ $opening->status->label() }}</dd>
                        </div>
                        <div class="px-4 py-3 sm:px-6">
                            <dt class="text-gray-500">What that means</dt>
                            <dd class="mt-1 text-gray-700">{{ $opening->status->description() }}</dd>
                        </div>
                        <div class="flex justify-between px-4 py-3 sm:px-6">
                            <dt class="text-gray-500">Created by</dt>
                            <dd class="text-gray-900">{{ $opening->creator->name }}</dd>
                        </div>
                        <div class="flex justify-between px-4 py-3 sm:px-6">
                            <dt class="text-gray-500">Finalized by</dt>
                            <dd class="text-gray-900">{{ $opening->finalizer?->name ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between px-4 py-3 sm:px-6">
                            <dt class="text-gray-500">Finalized at</dt>
                            <dd class="tabular-nums text-gray-900">{{ $opening->finalized_at?->format('d M Y H:i') ?? '—' }}</dd>
                        </div>
                    </dl>
                </x-panel>

                @if (! $position->balancingFigure()->isZero())
                    <x-panel title="Balancing figure">
                        <div class="space-y-2 p-4 text-sm sm:p-6">
                            <p class="font-mono text-lg tabular-nums {{ $position->needsExplanation() ? 'text-amber-700' : 'text-gray-900' }}">
                                {{ $position->balancingFigure()->format(withCurrency: true) }}
                            </p>
                            <p class="text-gray-600">
                                Posted to 3900 Opening Balance Equity. It stays visible until it is
                                explained or corrected, rather than being folded into equity and forgotten.
                            </p>
                        </div>
                    </x-panel>
                @endif

                @if ($opening->notes)
                    <x-panel title="Notes">
                        <p class="whitespace-pre-line p-4 text-sm text-gray-700 sm:p-6">{{ $opening->notes }}</p>
                    </x-panel>
                @endif

                @if ($opening->confirmation_text)
                    <x-panel title="Confirmed">
                        <p class="p-4 text-xs italic text-gray-600 sm:p-6">“{{ $opening->confirmation_text }}”</p>
                    </x-panel>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
