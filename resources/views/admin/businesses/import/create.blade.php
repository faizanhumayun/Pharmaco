{{--
    Moving a business in from its old POS: the upload.

    One file, the old system's own database. Nothing is imported from this
    page — it goes to a preview first.
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Import from old POS</h1>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $business->name }} · {{ $business->business_type->label() }}
                </p>
            </div>
            <a href="{{ route('admin.businesses.show', $business) }}"
               class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Back to business
            </a>
        </div>
    </x-slot>

    <div class="w-full space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <div class="grid gap-6 lg:grid-cols-3">
            <x-panel class="lg:col-span-2" title="Upload the old database"
                     description="The .mdb file that holds the old POS data. You will see a full preview before anything is imported.">
<form method="POST" action="{{ route('admin.businesses.import.store', $business) }}" enctype="multipart/form-data"
                      class="space-y-4 p-4 sm:p-6" x-data="oldPosUpload()" x-on:submit.prevent="send($event)">
                    @csrf
                    <div>
                        <x-input-label for="database" value="Old POS database (.mdb), or a converted file (.json)" />
                        <input id="database" name="database" type="file" accept=".mdb,.accdb,.json" required x-ref="file" :disabled="sending"
                               class="mt-1 block w-full max-w-xl rounded-md border border-gray-300 bg-white text-sm text-gray-700 file:mr-3 file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200">
                        <x-input-error :messages="$errors->get('database')" class="mt-2" />
                        <p x-show="error" x-cloak class="mt-2 text-sm text-red-700" x-text="error"></p>
                        <p class="mt-2 text-xs text-gray-500">
                            Maximum size 500 MB. The file is uploaded in small parts.
                            A Windows computer cannot read .mdb files directly. Convert the file first on a computer that can read it
                            (<span class="font-mono">php artisan oldpos:convert file.mdb</span>) and upload the .json file it creates.
                        </p>
                    </div>

                    {{-- How far it has got: a large file takes a little while. --}}
                    <div x-show="sending" x-cloak class="max-w-xl">
                        <div class="h-2 overflow-hidden rounded-full bg-gray-200">
                            <div class="h-full rounded-full bg-emerald-700 transition-all" :style="`width: ${percent}%`"></div>
                        </div>
                        <p class="mt-1 text-xs text-gray-600" x-text="percent < 100 ? 'Uploading… ' + percent + '%' : 'Reading the file…'"></p>
                    </div>

                    <button :disabled="sending"
                            class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-wait disabled:opacity-60">
                        <span x-text="sending ? 'Uploading…' : 'Upload and preview'">Upload and preview</span>
                    </button>
                </form>
            </x-panel>

            <x-panel title="What is imported">
                <ul class="space-y-3 p-4 text-sm text-gray-700 sm:p-6">
                    <li><span class="font-medium text-gray-900">Companies</span> — the suppliers, each with its own account.</li>
                    <li><span class="font-medium text-gray-900">Customers</span> — each with its own account.</li>
                    <li>
                        <span class="font-medium text-gray-900">Products</span> — name, barcode, pack, purchase price and
                        {{ $business->sellsAtMrp() ? 'MRP. A pharmacy sells at the MRP, so no trade price is taken.' : 'both the trade price and the MRP. Where the old system had no trade price it is left empty.' }}
                    </li>
                    <li><span class="font-medium text-gray-900">Stock</span> — the quantity of each product, with its expiry and batch.</li>
                    <li><span class="font-medium text-gray-900">Balances</span> — the amount pending from each customer and to each company.</li>
                </ul>
                <p class="border-t border-gray-200 px-4 py-3 text-xs leading-relaxed text-gray-500 sm:px-6">
                    Only the product, supplier and customer lists are read. The old system's logins and
                    connection settings are in the same file and are never read. The file is removed
                    from the server as soon as you import or discard it.
                </p>
            </x-panel>
        </div>

        @if ($imports->isNotEmpty())
            <x-panel title="Previous uploads">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                @foreach (['File', 'Uploaded', 'By', 'Status', ''] as $h)
                                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($imports as $import)
                                <tr>
                                    <td class="px-4 py-2 font-medium text-gray-900 sm:px-6">{{ $import->original_name }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-gray-600 sm:px-6">{{ $import->created_at->timezone($business->timezone)->format('j M Y, h:i A') }}</td>
                                    <td class="px-4 py-2 text-gray-600 sm:px-6">{{ $import->creator?->name }}</td>
                                    <td class="px-4 py-2 sm:px-6">
                                        <x-badge :classes="[
                                            'pending' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
                                            'imported' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
                                            'discarded' => 'bg-gray-100 text-gray-600 ring-gray-500/20',
                                        ][$import->status] ?? 'bg-gray-100 text-gray-600 ring-gray-500/20'">
                                            {{ ['pending' => 'Not imported yet', 'imported' => 'Imported', 'discarded' => 'Discarded'][$import->status] ?? $import->status }}
                                        </x-badge>
                                    </td>
                                    <td class="px-4 py-2 text-right sm:px-6">
                                        @unless ($import->status === 'discarded')
                                            <a href="{{ route('admin.businesses.import.show', [$business, $import]) }}"
                                               class="text-sm font-medium text-emerald-700 hover:text-emerald-800">
                                                {{ $import->isPending() ? 'Open preview' : 'View result' }}
                                            </a>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @endif
    </div>
    @push('scripts')
        <script>
            /* Sends the file a megabyte at a time. PHP's own upload limit is
               usually 2 MB, far below an old POS database, and this way the
               limit never comes into it. */
            function oldPosUpload() {
                return {
                    sending: false,
                    percent: 0,
                    error: '',

                    async send() {
                        const file = this.$refs.file.files[0];
                        if (! file || this.sending) return;

                        if (file.size > 500 * 1024 * 1024) {
                            this.error = 'The file is larger than 500 MB.';
                            return;
                        }

                        const size = 1024 * 1024;
                        const total = Math.max(1, Math.ceil(file.size / size));
                        const upload = crypto.randomUUID();

                        this.sending = true;
                        this.error = '';
                        this.percent = 0;

                        try {
                            for (let index = 0; index < total; index++) {
                                const body = new FormData();
                                body.append('upload', upload);
                                body.append('index', index);
                                body.append('total', total);
                                body.append('name', file.name);
                                body.append('piece', file.slice(index * size, (index + 1) * size), 'piece');

                                const response = await fetch(@js(route('admin.businesses.import.chunk', $business)), {
                                    method: 'POST',
                                    headers: {
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                    },
                                    body,
                                });

                                const answer = await response.json().catch(() => ({}));

                                if (! response.ok) {
                                    throw new Error(answer.message || 'The upload failed. Try again.');
                                }

                                this.percent = Math.round(((index + 1) / total) * 100);

                                if (answer.redirect) {
                                    window.location.href = answer.redirect;
                                    return;
                                }
                            }

                            throw new Error('The upload finished but the server did not confirm it. Try again.');
                        } catch (e) {
                            this.error = e.message;
                            this.sending = false;
                        }
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
