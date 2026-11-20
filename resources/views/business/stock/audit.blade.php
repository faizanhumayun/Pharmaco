{{--
    The shelves, a few lines a day.

    Three things in order: what to count today, what has been counted today, and
    what somebody has to decide about. A sheet is walked with a phone in hand, so
    the count box is the biggest thing on each row and nothing else asks for a
    decision while the shelf is in front of you.
--}}
@php($rs = fn ($m) => $m->format())
{{-- Stock is counted in packs where packs are opened and sold loose. --}}
@php($one = $business->sellsLoose() ? 'pack' : $business->unit()->one())
@php($many = $business->sellsLoose() ? 'packs' : $business->unit()->many())
@php($qty = fn (int $n) => number_format($n) . ' ' . Str::plural($one, abs($n)))

<x-workspace-layout :business="$business" fill>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Stock audit</h1>
                <p class="mt-1 text-sm text-gray-500">
                    {{ number_format($progress['products']) }} products, counted a few at a time. The
                    lines worth most and moving fastest come round every
                    {{ $progress['classes']['A']['interval'] }} days; the slowest every
                    {{ $progress['classes']['C']['interval'] }}.
                </p>
            </div>

            <dl class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                @foreach (['A' => 'Fast &amp; valuable', 'B' => 'Middling', 'C' => 'Long tail'] as $class => $label)
                    @php($c = $progress['classes'][$class])
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">{!! $label !!}</dt>
                        <dd class="font-mono tabular-nums text-gray-900">
                            {{ $c['due'] }}<span class="text-gray-400">/{{ $c['total'] }} due</span>
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </x-slot>

    {{-- The page is the window: the sheet and what has been counted each
         scroll inside their own card, so the heading and the search stay put. --}}
    <div class="flex h-full w-full flex-col overflow-auto px-4 py-4 sm:px-6 lg:px-8 xl:overflow-hidden">
        <x-flash />
        <x-input-error :messages="$errors->get('decision')" class="mb-4" />

        {{-- Differences somebody has to rule on. First, because stock stays
             wrong until they do. --}}
        @if ($open->isNotEmpty())
            <x-panel class="mb-4 flex max-h-[32vh] shrink-0 flex-col ring-1 ring-amber-200">
                <div class="shrink-0 border-b border-amber-200 bg-amber-50 px-4 py-3 sm:px-6">
                    <h2 class="text-sm font-semibold text-amber-900">
                        {{ $open->count() }} {{ Str::plural('difference', $open->count()) }} waiting
                    </h2>
                    <p class="mt-0.5 text-xs text-amber-800">
                        The books still say the old figure. Approving moves stock to what was counted;
                        dismissing leaves it alone and keeps the count on the record.
                    </p>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($open as $count)
                            <tr>
                                <td class="px-4 py-3 sm:px-6">
                                    <a href="{{ route('businesses.products.show', [$business, $count->product]) }}"
                                       class="font-medium text-gray-900 hover:text-emerald-700">{{ $count->product->label() }}</a>
                                    <span class="block text-xs text-gray-500">
                                        counted by {{ $count->counter->name }} on {{ $count->business_date->format('j M Y') }}
                                        @if ($count->note) · {{ $count->note }} @endif
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-gray-600">
                                    books <span class="font-mono tabular-nums text-gray-900">{{ $count->expectedLabel() }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-gray-600">
                                    shelf <span class="font-mono tabular-nums text-gray-900">{{ $count->countedLabel() }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <x-badge :classes="$count->difference() < 0
                                        ? 'bg-red-50 text-red-800 ring-red-600/20'
                                        : 'bg-sky-50 text-sky-800 ring-sky-600/20'">
                                        {{ $count->differenceLabel() }}
                                    </x-badge>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right sm:px-6">
                                    @if ($canResolve)
                                        <form method="POST" action="{{ route('businesses.stock.audit.resolve', [$business, $count]) }}"
                                              class="flex items-center justify-end gap-2">
                                            @csrf
                                            <input name="reason" type="text" placeholder="Reason (optional)" maxlength="255"
                                                   class="w-44 rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                            <button name="decision" value="approve"
                                                    class="rounded-md bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">
                                                Correct stock
                                            </button>
                                            <button name="decision" value="dismiss"
                                                    class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                Miscount
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-xs text-gray-400">waiting for the owner</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </x-panel>
        @endif

        <div class="grid gap-4 xl:min-h-0 xl:flex-1 xl:grid-cols-3 xl:grid-rows-[minmax(0,1fr)]">
            {{-- The sheet. --}}
            <div class="xl:col-span-2 xl:flex xl:min-h-0 xl:flex-col">
                <x-panel class="xl:flex xl:min-h-0 xl:flex-1 xl:flex-col">
                    <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 sm:px-6">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">
                                {{ $all ? 'Every product' : 'Count these' }}
                            </h2>
                            <p class="text-xs text-gray-500">
                                {{ $all ? 'Count anything, whether it is due or not' : $today->format('l j F Y') . ' · most overdue first' }}
                                · {{ number_format($progress['due']) }} due in all
                                @if ($progress['never'] > 0)
                                    · <span class="text-amber-700">{{ number_format($progress['never']) }} never counted</span>
                                @endif
                            </p>
                        </div>

                        <x-filter-bar submit="Search">
                            <input type="hidden" name="show" value="all">
                            <input name="q" type="search" value="{{ $search }}" placeholder="Find a product"
                                   class="w-44 rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            @if ($all)
                                <a href="{{ route('businesses.stock.audit', $business) }}"
                                   class="text-sm text-gray-500 hover:text-gray-800">Back to today's sheet</a>
                            @else
                                <a href="{{ route('businesses.stock.audit', [$business, 'show' => 'all']) }}"
                                   class="text-sm font-medium text-emerald-700 hover:text-emerald-800">Show everything</a>
                            @endif
                        </x-filter-bar>
                    </div>

                    <div class="xl:min-h-0 xl:flex-1 xl:overflow-y-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($sheet as $row)
                                <tr>
                                    <td class="px-4 py-3 sm:px-6">
                                        <div class="flex items-center gap-2">
                                            <x-badge :classes="match ($row['class']) {
                                                'A' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
                                                'B' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
                                                default => 'bg-gray-50 text-gray-500 ring-gray-400/20',
                                            }">{{ $row['class'] }}</x-badge>
                                            <a href="{{ route('businesses.products.show', [$business, $row['product']]) }}"
                                               class="font-medium text-gray-900 hover:text-emerald-700">{{ $row['product']->label() }}</a>
                                        </div>
                                        <span class="mt-0.5 block text-xs text-gray-500">
                                            @if (in_array($row['product']->id, $countedIds, true))
                                                <span class="font-medium text-emerald-700">counted today</span> ·
                                            @endif
                                            {{ $row['product']->company?->name ?? '—' }}
                                            · worth {{ $rs($row['held']) }}
                                            · {{ $row['last_counted'] ? 'last counted ' . $row['last_counted']->diffForHumans() : 'never counted' }}
                                        </span>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <span class="block text-xs uppercase tracking-wide text-gray-400">Books say</span>
                                        @php($looseHeld = (int) ($looseOnHand[$row['product']->id] ?? 0))
                                        <span class="font-mono text-lg font-semibold tabular-nums text-gray-900">{{ number_format($row['packs']) }}</span>
                                        <span class="text-sm text-gray-500">{{ Str::plural($one, $row['packs']) }}</span>
                                        {{-- A pack that can be opened always says what is
                                             loose, none included: the shelf is checked for
                                             an open pack either way. --}}
                                        @if ($looseHeld !== 0)
                                            <span class="block text-xs font-medium text-gray-700">and {{ $row['product']->stockLabel(0, $looseHeld) }}</span>
                                        @elseif ((int) $row['product']->units_per_pack > 1)
                                            <span class="block text-xs text-gray-500">and {{ Str::replaceFirst('1 loose', 'no loose', Str::plural($row['product']->stockLabel(0, 1))) }}</span>
                                        @endif
                                        @if ($row['product']->pack_size)
                                            <span class="block text-xs text-gray-400">Pack {{ $row['product']->pack_size }}</span>
                                        @endif
                                    </td>

                                    {{-- Two answers, because most counts are one of them:
                                         the shelf agrees, or it does not. Agreeing takes
                                         one tap and posts no figure — the books are read
                                         again at the moment of writing, so a sheet left
                                         open does not record a stale agreement. --}}
                                    <td class="px-4 py-3 sm:px-6" x-data="{ differs: false }">
                                        <form method="POST" action="{{ route('businesses.stock.audit.store', $business) }}"
                                              class="flex items-center justify-end gap-2">
                                            @csrf
                                            <input type="hidden" name="company_product_id" value="{{ $row['product']->id }}">

                                            <template x-if="! differs">
                                                <span class="flex items-center gap-2">
                                                    <button name="agrees" value="1"
                                                            class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                                                        Agrees
                                                    </button>
                                                    <button type="button" x-on:click="differs = true"
                                                            class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                                        Different
                                                    </button>
                                                </span>
                                            </template>

<template x-if="differs">
                                                {{-- What is on the shelf: whole packs, and — where the
                                                     pack can be opened — the pieces in the open one.
                                                     Both start at what the books say, so only the
                                                     figure that is wrong needs typing. --}}
                                                <span class="flex items-end gap-2">
                                                    <label class="block text-left">
                                                        <span class="block text-[11px] uppercase tracking-wide text-gray-400">{{ Str::ucfirst($many) }}</span>
                                                        <input id="count-{{ $row['product']->id }}" name="counted_packs" type="number" min="0" required
                                                               value="{{ $row['packs'] }}"
                                                               x-init="$nextTick(() => { $el.focus(); $el.select(); })"
                                                               class="w-24 rounded-md border-gray-300 py-2 text-right text-lg tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    </label>
                                                    @if ((int) $row['product']->units_per_pack > 1)
                                                        <label class="block text-left">
                                                            <span class="block text-[11px] uppercase tracking-wide text-gray-400">Loose</span>
                                                            <input name="counted_loose" type="number" min="0" max="{{ (int) $row['product']->units_per_pack - 1 }}"
                                                                   value="{{ $looseHeld }}"
                                                                   title="Pieces in the open pack — {{ (int) $row['product']->units_per_pack }} make a pack"
                                                                   class="w-20 rounded-md border-gray-300 py-2 text-right text-lg tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                        </label>
                                                    @endif
                                                    <button class="rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                                                        Record
                                                    </button>
                                                    <button type="button" x-on:click="differs = false"
                                                            class="py-2 text-sm text-gray-400 hover:text-gray-700">Cancel</button>
                                                </span>
                                            </template>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="px-6 py-12 text-center text-gray-500">
                                        {{ $search !== '' ? 'Nothing matches that.' : 'Nothing is due today — the shelves are up to date.' }}
                                        <span class="mt-1 block text-xs">Products come back round as their interval passes.</span>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </x-panel>
            </div>

            {{-- What has already been walked today. --}}
            <div class="xl:col-span-1 xl:flex xl:min-h-0 xl:flex-col">
                <x-panel class="xl:flex xl:min-h-0 xl:flex-1 xl:flex-col" title="Counted today"
                         :description="$countedToday->count() . ' ' . Str::plural('product', $countedToday->count()) . ' walked'">
                    <ul class="divide-y divide-gray-100 text-sm xl:min-h-0 xl:flex-1 xl:overflow-y-auto">
                        @forelse ($countedToday as $count)
                            <li class="flex items-start justify-between gap-3 px-4 py-2.5 sm:px-6">
                                <span class="min-w-0">
                                    <span class="block truncate text-gray-900">{{ $count->product->label() }}</span>
                                    <span class="block text-xs text-gray-500">{{ $count->counter->name }}</span>
                                </span>
                                <span class="shrink-0 text-right">
                                    @if ($count->difference() === 0)
                                        <span class="text-xs font-medium text-emerald-700">agrees</span>
                                    @else
                                        <span class="font-mono text-sm font-semibold tabular-nums {{ $count->difference() < 0 ? 'text-red-700' : 'text-sky-700' }}">
                                            {{ $count->differenceLabel() }}
                                        </span>
                                        <span class="block text-xs text-gray-400">
                                            {{ ['found' => 'waiting', 'adjusted' => 'corrected', 'dismissed' => 'miscount'][$count->status] ?? $count->status }}
                                        </span>
                                    @endif
                                </span>
                            </li>
                        @empty
                            <li class="px-6 py-8 text-center text-sm text-gray-500">Nothing counted yet today.</li>
                        @endforelse
                    </ul>
                </x-panel>

                <p class="mt-3 shrink-0 text-xs leading-relaxed text-gray-500">
                    Counting records what was seen and moves nothing. Stock changes only when a
                    difference is approved, and then through the same adjustment the product page
                    uses — so every change to a quantity still has one way in.
                </p>
            </div>
        </div>
    </div>
</x-workspace-layout>
