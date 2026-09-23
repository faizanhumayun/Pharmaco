<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-semibold text-gray-900">{{ $business->name }}</h1>
                    <x-badge :classes="$business->status->badgeClasses()">{{ $business->status->label() }}</x-badge>
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $business->business_type->label() }} · today is
                    {{ $business->today()->format('D d M Y') }} in {{ $business->timezone }}
                </p>
            </div>
            @if (auth()->user()->isPlatformAdmin())
                <a href="{{ route('admin.businesses.show', $business) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Back office
                </a>
            @endif
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        @if ($business->opening_date === null)
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <strong>Opening balance not set.</strong>
                Every figure this business will ever report is <em>opening + transactions</em>, so nothing can be
                recorded until the opening financial position is entered and finalized.
            </div>
        @endif

        <x-panel class="mt-6" title="Coming in later phases"
                 description="The ledger and the screens that read from it are built in order, so no screen ever shows a number the ledger cannot prove.">
            <ol class="divide-y divide-gray-100 text-sm">
                @foreach ([
                    ['Phase 3', 'Ledger core', 'Accounts, transactions, balanced entries. Nothing visible, everything depends on it.'],
                    ['Phase 4', 'Opening balance', 'The three-step wizard, the balancing figure, and the lock.'],
                    ['Phase 5', 'Daily entry', 'Purchases, sales, collections, payments and expenses, with a live position preview.'],
                    ['Phase 6', 'Daily closing', 'Cash reconciliation, the close checklist, and the period lock.'],
                    ['Phase 7', 'Dashboard', 'Stock, cash, market credit, company payables and the management position.'],
                ] as [$phase, $title, $detail])
                    <li class="flex gap-4 px-4 py-3 sm:px-6">
                        <span class="w-16 shrink-0 font-mono text-xs text-emerald-700">{{ $phase }}</span>
                        <span>
                            <span class="block font-medium text-gray-900">{{ $title }}</span>
                            <span class="block text-gray-500">{{ $detail }}</span>
                        </span>
                    </li>
                @endforeach
            </ol>
        </x-panel>
    </div>
</x-workspace-layout>
