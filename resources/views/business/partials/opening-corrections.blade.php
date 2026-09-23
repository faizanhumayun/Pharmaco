{{--
    Corrections to the starting position, listed where the original figures
    are shown — the original stays as entered, and what changed it sits beside it.
--}}
@if ($startingPoint->corrections->isNotEmpty())
    <div class="mt-3 border-t border-gray-100 pt-3">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Corrected since</p>
        <ul class="mt-1 space-y-1 text-sm">
            @foreach ($startingPoint->corrections as $correction)
                @foreach ($correction->lines->reject(fn ($l) => $l->account->code === \App\Enums\AccountCode::OpeningBalanceEquity->value) as $line)
                    @php($change = $line->account->type === \App\Enums\AccountType::Liability
                        ? $line->credit->minus($line->debit)
                        : $line->debit->minus($line->credit))
                    <li class="flex flex-wrap justify-between gap-x-4">
                        <span class="text-gray-600">
                            {{ $line->account->name }}
                            <span class="text-gray-400">· posted {{ $correction->business_date->format('d M') }}
                                @if ($correction->correction_reason) · {{ $correction->correction_reason }} @endif</span>
                        </span>
                        <span class="font-mono tabular-nums {{ $change->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">
                            {{ $change->isNegative() ? '' : '+' }}{{ $change->format() }}
                        </span>
                    </li>
                @endforeach
            @endforeach
        </ul>
    </div>
@endif
