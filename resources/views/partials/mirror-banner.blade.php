{{--
    Shown on every screen of a shop's online copy: what this is, and how old.
    The copy is replaced each time the shop's computer sends a snapshot.
--}}
@if ($mirror = config('mirror.active'))
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
    </div>
@endif
