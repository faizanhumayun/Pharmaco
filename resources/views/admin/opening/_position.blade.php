@props(['position', 'fields'])

<div class="overflow-x-auto">
    <table class="min-w-full text-sm">
        <tbody class="divide-y divide-gray-100">
            <tr class="bg-gray-50">
                <th colspan="2" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Assets</th>
            </tr>
            @foreach ($fields as $field)
                @continue(! $field->account->type()->increasesOnDebit())
                <tr>
                    <td class="px-4 py-2 text-gray-700 sm:px-6">{{ $field->label }}</td>
                    <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900 sm:px-6">
                        {{ $position->amounts[$field->key()]->format() }}
                    </td>
                </tr>
            @endforeach
            <tr class="border-t-2 border-gray-300 font-semibold">
                <td class="px-4 py-2 text-gray-900 sm:px-6">Total assets</td>
                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900 sm:px-6">
                    {{ $position->assets->format() }}
                </td>
            </tr>

            <tr class="bg-gray-50">
                <th colspan="2" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Liabilities</th>
            </tr>
            @foreach ($fields as $field)
                @continue($field->account->type()->increasesOnDebit() || $field->account->type()->value === 'equity')
                <tr>
                    <td class="px-4 py-2 text-gray-700 sm:px-6">{{ $field->label }}</td>
                    <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900 sm:px-6">
                        {{ $position->amounts[$field->key()]->format() }}
                    </td>
                </tr>
            @endforeach
            <tr class="border-t-2 border-gray-300 font-semibold">
                <td class="px-4 py-2 text-gray-900 sm:px-6">Total liabilities</td>
                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900 sm:px-6">
                    {{ $position->liabilities->format() }}
                </td>
            </tr>

            <tr class="border-t-4 border-double border-gray-900 text-base font-bold">
                <td class="px-4 py-3 text-gray-900 sm:px-6">Net position</td>
                <td class="px-4 py-3 text-right font-mono tabular-nums sm:px-6
                           {{ $position->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                    {{ $position->netPosition()->format() }}
                </td>
            </tr>
        </tbody>
    </table>
</div>
