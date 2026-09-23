{{--
    Tabs across the top of a day's page. The chosen tab is in the link
    (?tab=…), so a reload or a shared link opens the same one; the first tab is
    the default and carries no parameter.

    $tabs:   [key => label]
    $active: the key being shown
    $counts: optional [key => number] shown as a small badge
--}}
<nav class="mb-6 flex gap-6 border-b border-gray-200" aria-label="Page sections">
    @foreach ($tabs as $key => $label)
        <a href="{{ $loop->first ? request()->fullUrlWithoutQuery(['tab']) : request()->fullUrlWithQuery(['tab' => $key]) }}"
           @class([
               '-mb-px inline-flex items-center gap-2 border-b-2 px-1 pb-3 text-sm font-medium',
               'border-emerald-700 text-emerald-800' => $active === $key,
               'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' => $active !== $key,
           ])
           @if ($active === $key) aria-current="page" @endif>
            {{ $label }}
            @if (isset($counts[$key]))
                <span @class([
                    'rounded-full px-2 py-0.5 text-xs',
                    'bg-emerald-100 text-emerald-800' => $active === $key,
                    'bg-gray-100 text-gray-600' => $active !== $key,
                ])>{{ $counts[$key] }}</span>
            @endif
        </a>
    @endforeach
</nav>
