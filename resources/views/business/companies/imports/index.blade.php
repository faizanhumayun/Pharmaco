<x-workspace-layout :business="$business">
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">Price lists — {{ $company->name }}</h1>
        <p class="mt-1 text-sm text-gray-500">
            Every file this company has sent, applied or not. They are kept because they are the document behind
            each price in the catalogue.
        </p>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <div class="mb-6 flex flex-wrap items-center gap-3">
            <a href="{{ route('businesses.companies.show', [$business, $company]) }}"
               class="text-sm text-gray-500 hover:text-gray-700">← {{ $company->name }}</a>

            @can('importProducts', $business)
                <a href="{{ route('businesses.companies.imports.create', [$business, $company]) }}"
                   class="ml-auto rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Import a price list
                </a>
            @endcan
        </div>

        <x-panel>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach ([['File','left'],['Date','left'],['Status','left'],['Rows','right'],['Added','right'],['Updated','right'],['Unchanged','right'],['By','left']] as [$heading,$align])
                                <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($imports as $import)
                            <tr>
                                <td class="px-3 py-2 sm:px-6">
                                    <a href="{{ route('businesses.companies.imports.show', [$business, $company, $import]) }}"
                                       class="font-medium text-gray-900 hover:text-emerald-700">{{ $import->original_filename }}</a>
                                    <div class="text-xs text-gray-500">{{ $import->page_count }} {{ Str::plural('page', $import->page_count) }}</div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-gray-600">{{ $import->business_date->format('j M Y') }}</td>
                                <td class="px-3 py-2">
                                    <x-badge :classes="$import->status->badgeClasses()">{{ $import->status->label() }}</x-badge>
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600">{{ number_format($import->rows_detected) }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-900">{{ $import->committed_at ? number_format($import->products_created) : '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-900">{{ $import->committed_at ? number_format($import->products_updated) : '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-500">{{ $import->committed_at ? number_format($import->products_unchanged) : '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $import->uploader->name }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-10 text-center text-gray-500">
                                    No price list has been imported for {{ $company->name }} yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($imports->hasPages())
                <div class="border-t border-gray-200 px-4 py-3 sm:px-6">{{ $imports->links() }}</div>
            @endif
        </x-panel>
    </div>
</x-workspace-layout>
