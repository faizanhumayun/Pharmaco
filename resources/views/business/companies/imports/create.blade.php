@php
    // Reached from inside a company, the company is settled and the form does
    // not ask. Reached from the catalogue, it is the first thing it asks.
    $action = $company
        ? route('businesses.companies.imports.store', [$business, $company])
        : route('businesses.imports.store', $business);
@endphp

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">
            Import a price list @if ($company) — {{ $company->name }} @endif
        </h1>
        <p class="mt-1 text-sm text-gray-500">
            Upload the PDF the company sent. Nothing is added to the catalogue until you have seen what it says.
        </p>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        @if (! $company && $companies->isEmpty())
            <div class="mb-6 flex flex-wrap items-center gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <span>A price list belongs to a company, and this business has none yet.</span>
                @can('configure', $business)
                    <button type="button" x-data x-on:click="$dispatch('open-drawer', 'add-company')"
                            class="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800">
                        Add a company
                    </button>
                @endcan
            </div>
        @endif

        @if ($draft)
            <div class="mb-6 flex flex-wrap items-center gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <span>
                    <span class="font-semibold">{{ $draft->original_filename }}</span>
                    is already staged from {{ $draft->created_at->diffForHumans() }} and has not been applied.
                </span>
                <a href="{{ route('businesses.companies.imports.show', [$business, $company, $draft]) }}"
                   class="font-semibold underline">Carry on reviewing it</a>
            </div>
        @endif

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="xl:col-span-2">
                <x-panel title="The PDF">
                    <form method="POST"
                          action="{{ $action }}"
                          enctype="multipart/form-data"
                          class="space-y-5 p-4 sm:p-6">
                        @csrf

                        @unless ($company)
                            <div>
                                <x-input-label for="company_id" value="Which company sent it?" />
                                <select id="company_id" name="company_id" required @disabled($companies->isEmpty())
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600 disabled:bg-gray-100">
                                    <option value="">— choose a company —</option>
                                    @foreach ($companies as $option)
                                        <option value="{{ $option->id }}"
                                                @selected((old('company_id') ?? session('created_company_id')) == $option->id)>{{ $option->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('company_id')" class="mt-2" />

                                @can('configure', $business)
                                    <button type="button" x-data x-on:click="$dispatch('open-drawer', 'add-company')"
                                            class="mt-2 text-sm font-medium text-emerald-700 hover:text-emerald-800">
                                        Not listed? Add a company
                                    </button>
                                @endcan
                            </div>
                        @endunless

                        <div>
                            <x-input-label for="file" value="Price list (PDF, up to 25 MB)" />
                            <input id="file" name="file" type="file" accept="application/pdf" required
                                   class="mt-2 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 file:mr-4 file:rounded file:border-0 file:bg-emerald-700 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-white hover:file:bg-emerald-800">
                            <x-input-error :messages="$errors->get('file')" class="mt-2" />
                            <p class="mt-2 text-xs text-gray-500">
                                It must be a PDF of text. A scan — a PDF made of photographs of pages — has no text to
                                read and will be refused rather than half-read.
                            </p>
                        </div>

                        <button @disabled(! $company && $companies->isEmpty())
                                class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-gray-300">
                            Read the file
                        </button>
                    </form>
                </x-panel>
            </div>

            <div class="space-y-6">
                <x-panel title="What happens next">
                    <ol class="space-y-4 p-4 text-sm text-gray-600 sm:p-6">
                        <li class="flex gap-3">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-gray-200 text-xs font-semibold text-gray-700">1</span>
                            <span>The file is read into rows and columns, and the headings are matched to the fields this system keeps.</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-gray-200 text-xs font-semibold text-gray-700">2</span>
                            <span>You check the mapping and fix any row it could not read. Nothing has changed at this point.</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-gray-200 text-xs font-semibold text-gray-700">3</span>
                            <span>You apply it. New products are added, known ones have their prices updated, and every price it changes is kept in the product's history.</span>
                        </li>
                    </ol>
                </x-panel>

                <x-panel title="What is read">
                    <dl class="divide-y divide-gray-100 text-sm">
                        @foreach ([
                            'Brand name' => 'The name on the box — the only field a row cannot do without',
                            'Generic name' => 'The molecule',
                            'Strength' => '500mg, 2g/5ml',
                            'Dosage form' => 'Tablet, syrup, injection',
                            'Pack size' => '2x10, 120ml',
                            'Pack type' => 'Blister, bottle, vial',
                            'MRP' => 'What the patient pays',
                            'Trade price' => 'What the pharmacy pays',
                            'Our rate' => 'What this business is billed',
                            'Case size' => 'Packs to a carton',
                        ] as $label => $hint)
                            <div class="flex items-baseline justify-between gap-4 px-4 py-2 sm:px-6">
                                <dt class="font-medium text-gray-900">{{ $label }}</dt>
                                <dd class="text-right text-xs text-gray-500">{{ $hint }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-panel>

                @if ($lastImport)
                    <x-panel title="Last applied">
                        <div class="space-y-1 p-4 text-sm text-gray-600 sm:p-6">
                            <p class="font-medium text-gray-900">{{ $lastImport->original_filename }}</p>
                            <p>{{ $lastImport->business_date->format('j M Y') }} · {{ $lastImport->products_created }} added, {{ $lastImport->products_updated }} updated</p>
                            <a href="{{ route('businesses.companies.imports.index', [$business, $company]) }}"
                               class="inline-block pt-1 text-emerald-700 hover:text-emerald-800">Every import →</a>
                        </div>
                    </x-panel>
                @endif
            </div>
        </div>
    </div>

    @can('configure', $business)
        <x-add-company-drawer :business="$business" />
    @endcan
</x-workspace-layout>
