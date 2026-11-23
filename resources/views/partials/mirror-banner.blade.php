{{--
    Shown on every screen of a shop's online copy: what this is, and how old.
    The copy is replaced each time the shop's computer sends a snapshot.
--}}
@php
    // The whole site is a copy, or the business on this screen is one kept
    // in the platform's own database.
    $mirror = config('mirror.active');

    if ($mirror === null && isset($business) && $business instanceof \App\Models\Business && $business->isShopCopy()) {
        $source = $business->shopMirror;
        $mirror = ['name' => $source?->name ?? $business->name, 'made_at' => $source?->snapshot_made_at, 'can_leave' => false, 'entry' => $source?->entry];
    }
@endphp
@if ($mirror && ($mirror['entry'] ?? null) === 'office')
    {{-- The shop's computer is locked and its books are entered here, for a fix. --}}
    <div class="bg-red-100 px-4 py-1.5 text-center text-sm font-medium text-red-900 print:hidden" role="status">
        {{ $mirror['name'] }} is paused for a fix · its own computer is locked · what you change here goes back to it when you switch the shop on again in Businesses
    </div>
@elseif ($mirror)
    @php($madeAt = $mirror['made_at'] ?? null)
    {{-- In the shop's own time, not the server's. --}}
    @php($zone = rescue(fn () => \App\Models\Business::query()->value('timezone'), null, false) ?: config('app.timezone'))
    <div class="bg-amber-100 px-4 py-1.5 text-center text-sm font-medium text-amber-900 print:hidden" role="status">
        Read-only copy of {{ $mirror['name'] }}
        @if ($madeAt)
            · as it stood {{ $madeAt->diffForHumans() }} ({{ $madeAt->timezone($zone)->format('j M Y, g:i A') }})
        @else
            · nothing has arrived from the shop yet
        @endif
        · changes are made on the shop's own computer
        @if ($mirror['can_leave'] ?? false)
            · <a href="{{ route('copy.leave') }}" class="underline hover:no-underline">Leave this copy</a>
        @endif
    </div>
@endif
