@php
    use App\Enums\ProductField;

    $canImport = auth()->user()->can('importProducts', $business);
    $missing = $map->missingRequired();
    $columns = [
        ProductField::Code, ProductField::BrandName, ProductField::GenericName,
        ProductField::Strength, ProductField::DosageForm, ProductField::PackSize,
        ProductField::PackType, ProductField::Mrp, ProductField::TradePrice,
        ProductField::PurchaseRate, ProductField::CaseSize,
    ];
@endphp

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold text-gray-900">{{ $import->original_filename }}</h1>
            <x-badge :classes="$import->status->badgeClasses()">{{ $import->status->label() }}</x-badge>
        </div>
        <p class="mt-1 text-sm text-gray-500">
            {{ $company->name }} · {{ $import->page_count }} {{ Str::plural('page', $import->page_count) }} ·
            {{ number_format($counts['total']) }} rows read ·
            uploaded by {{ $import->uploader->name }} {{ $import->created_at->diffForHumans() }}
        </p>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        {{-- What is about to happen, and the two buttons that decide it. --}}
        <div class="mb-6 flex flex-wrap items-center gap-x-6 gap-y-3 rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
            <div class="flex items-baseline gap-2">
                <span class="font-mono text-lg font-semibold tabular-nums text-gray-900">{{ number_format($counts['included']) }}</span>
                <span class="text-sm text-gray-500">rows will be imported</span>
            </div>

            @if ($counts['excluded'] > 0)
                <div class="flex items-baseline gap-2">
                    <span class="font-mono text-lg font-semibold tabular-nums text-amber-700">{{ number_format($counts['excluded']) }}</span>
                    <span class="text-sm text-gray-500">need attention and will be left out</span>
                </div>
            @endif

            <a href="{{ route('businesses.companies.imports.file', [$business, $company, $import]) }}"
               target="_blank"
               class="text-sm font-medium text-emerald-700 hover:text-emerald-800">Open the original PDF →</a>

            @if ($canImport && $import->isEditable())
                <div class="ml-auto flex items-center gap-3">
                    <form method="POST" action="{{ route('businesses.companies.imports.discard', [$business, $company, $import]) }}">
                        @csrf
                        <button class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Discard
                        </button>
                    </form>

                    <form method="POST" action="{{ route('businesses.companies.imports.commit', [$business, $company, $import]) }}">
                        @csrf
                        <button @disabled($counts['included'] === 0 || $missing !== [])
                                class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-gray-300">
                            Apply {{ number_format($counts['included']) }} rows to the catalogue
                        </button>
                    </form>
                </div>
            @endif
        </div>

        @if ($missing !== [])
            <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                Nothing can be imported until a column is mapped to
                <span class="font-semibold">{{ collect($missing)->map(fn ($f) => $f->label())->join(', ') }}</span>.
            </div>
        @endif

        @if ($import->warnings)
            <div class="mb-6 space-y-1 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                @foreach ($import->warnings as $warning)
                    <p>{{ $warning }}</p>
                @endforeach
            </div>
        @endif

        {{-- Step one: what each column of the PDF holds. Everything below is
             read through this mapping, so it is settled first. --}}
        <x-panel title="Columns"
                 description="Each column of the PDF, with the first values found in it. Change any that is wrong — the rows below are read again when you save."
                 class="mb-6">
            <form method="POST" action="{{ route('businesses.companies.imports.remap', [$business, $company, $import]) }}"
                  class="p-4 sm:p-6">
                @csrf
                @method('PATCH')

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
                    @for ($i = 0; $i < $columnCount; $i++)
                        @php $field = $map->fieldFor($i); @endphp
                        <div class="rounded-md border {{ $field ? 'border-gray-200 bg-white' : 'border-dashed border-gray-300 bg-gray-50' }} p-3">
                            <div class="mb-2 flex items-baseline justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Column {{ $i + 1 }}</span>
                                @unless ($field)
                                    <span class="text-xs text-gray-400">not imported</span>
                                @endunless
                            </div>

                            <select name="columns[{{ $i }}]" @disabled(! $canImport || ! $import->isEditable())
                                    class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600 disabled:bg-gray-100">
                                <option value="">— leave out —</option>
                                @foreach ($fieldOptions as $value => $label)
                                    <option value="{{ $value }}" @selected($field?->value === $value)>{{ $label }}</option>
                                @endforeach
                            </select>

                            <p class="mt-2 truncate text-xs text-gray-500" title="{{ collect($samples[$i] ?? [])->join(' · ') }}">
                                {{ collect($samples[$i] ?? [])->join(' · ') ?: 'empty in every row read' }}
                            </p>
                        </div>
                    @endfor
                </div>

                @if ($canImport && $import->isEditable())
                    <div class="mt-4 flex items-center gap-3">
                        <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                            Save mapping and re-read the rows
                        </button>
                        <p class="text-xs text-gray-500">Rows you have corrected by hand keep your figures.</p>
                    </div>
                @endif
            </form>
        </x-panel>

        {{-- Step two: the rows themselves. --}}
        <x-panel>
            <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 px-4 py-3 sm:px-6">
                <h2 class="mr-4 text-base font-semibold text-gray-900">Rows</h2>

                @foreach ([
                    'problems' => 'Needs attention (' . number_format($counts['excluded']) . ')',
                    'included' => 'Will import (' . number_format($counts['included']) . ')',
                    'all' => 'All (' . number_format($counts['total']) . ')',
                ] as $value => $label)
                    <a href="{{ route('businesses.companies.imports.show', [$business, $company, $import, 'show' => $value]) }}"
                       class="rounded-full px-3 py-1 text-xs font-medium {{ $filter === $value ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Line</th>
                            @foreach ($columns as $field)
                                <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ $field->isMoney() || $field->isInteger() ? 'text-right' : 'text-left' }}">
                                    {{ $field->label() }}
                                </th>
                            @endforeach
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            <tr x-data="{ editing: false }"
                                class="{{ $row->included ? '' : 'bg-amber-50/40' }}">
                                <td class="px-4 py-2 align-top font-mono text-xs text-gray-400 sm:px-6">
                                    <div>p{{ $row->page_no }}·{{ $row->line_no }}</div>
                                    @if ($canImport && $import->isEditable())
                                        <button type="button" x-on:click="editing = ! editing"
                                                class="mt-1 text-xs font-medium text-emerald-700 hover:text-emerald-800"
                                                x-text="editing ? 'Cancel' : 'Fix'"></button>
                                    @endif
                                </td>

                                @foreach ($columns as $field)
                                    @php $issues = $row->issuesFor($field->value); @endphp
                                    <td class="px-3 py-2 align-top {{ $field->isMoney() || $field->isInteger() ? 'text-right font-mono tabular-nums' : '' }} {{ $issues ? 'text-amber-900' : 'text-gray-700' }}">
                                        {{ $row->value($field->value) ?? '—' }}
                                        @foreach ($issues as $issue)
                                            <div class="mt-0.5 text-left text-xs font-normal {{ $issue['level'] === 'error' ? 'text-red-700' : 'text-amber-700' }}">
                                                {{ $issue['message'] }}
                                            </div>
                                        @endforeach
                                    </td>
                                @endforeach

                                <td class="whitespace-nowrap px-3 py-2 align-top">
                                    @if ($row->included)
                                        <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-600/20">Will import</x-badge>
                                    @else
                                        <x-badge classes="bg-amber-50 text-amber-800 ring-amber-600/20">Left out</x-badge>
                                    @endif
                                    @if ($row->edited)
                                        <div class="mt-1 text-xs text-gray-400">corrected by hand</div>
                                    @endif
                                </td>
                            </tr>

                            {{-- The line exactly as it was read, then a form over
                                 the top of it. Both are on screen at once so a
                                 correction is made against the page, not from memory. --}}
                            @if ($canImport && $import->isEditable())
                                <tr x-show="editing" x-cloak class="bg-gray-50">
                                    <td colspan="{{ count($columns) + 2 }}" class="px-4 py-4 sm:px-6">
                                        <p class="mb-3 font-mono text-xs text-gray-500">
                                            As read: {{ $row->rawText() ?: '(nothing)' }}
                                        </p>

                                        <form method="POST"
                                              action="{{ route('businesses.companies.imports.rows.update', [$business, $company, $import, $row]) }}"
                                              class="space-y-3">
                                            @csrf
                                            @method('PATCH')

                                            <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                                                @foreach ($columns as $field)
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-600" for="r{{ $row->id }}-{{ $field->value }}">
                                                            {{ $field->label() }}
                                                        </label>
                                                        <input id="r{{ $row->id }}-{{ $field->value }}"
                                                               name="values[{{ $field->value }}]"
                                                               value="{{ $row->value($field->value) }}"
                                                               placeholder="{{ $field->hint() }}"
                                                               class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    </div>
                                                @endforeach
                                            </div>

                                            <div class="flex flex-wrap items-center gap-4">
                                                <input type="hidden" name="included" value="0">
                                                <label class="flex items-center gap-2 text-sm text-gray-600">
                                                    <input type="checkbox" name="included" value="1" @checked($row->included)
                                                           class="rounded border-gray-300 text-emerald-700 focus:ring-emerald-600">
                                                    Include this row in the import
                                                </label>

                                                <button class="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800">
                                                    Save row
                                                </button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="{{ count($columns) + 2 }}" class="px-6 py-10 text-center text-gray-500">
                                    @if ($filter === 'problems')
                                        Every row read cleanly.
                                    @else
                                        No rows.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($rows->hasPages())
                <div class="border-t border-gray-200 px-4 py-3 sm:px-6">{{ $rows->links() }}</div>
            @endif
        </x-panel>
    </div>
</x-workspace-layout>
