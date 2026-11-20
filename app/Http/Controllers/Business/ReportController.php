<?php

namespace App\Http\Controllers\Business;

use App\Domain\Reporting\Reports;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reports page: a list of reports, and each one as a table.
 *
 * Opening a report needs report.view; the cost and profit columns inside one
 * need report.view_position; taking one away as a spreadsheet needs
 * report.export. Three questions, asked separately.
 */
class ReportController extends Controller
{
    public function index(Business $business): View
    {
        $this->authorize('viewOperationalReports', $business);

        return view('business.reports.index', [
            'business' => $business,
            'groups' => collect(Reports::catalogue())->map(fn ($r, $key) => [...$r, 'key' => $key])->groupBy('group'),
        ]);
    }

    public function show(Request $request, Business $business, string $report, Reports $reports): View|StreamedResponse
    {
        $this->authorize('viewOperationalReports', $business);

        $meta = Reports::catalogue()[$report] ?? abort(404);
        [$from, $to] = $this->range($business, $request);

        $table = $reports->build($report, $business, $from, $to, $request->user()->can('viewReports', $business));

        if ($request->query('export') === 'csv') {
            $this->authorize('exportReports', $business);

            return $this->csv($business, $report, $table, $from, $to, $meta['dated']);
        }

        return view('business.reports.show', [
            'business' => $business,
            'key' => $report,
            'meta' => $meta,
            'table' => $table,
            'from' => $from,
            'to' => $to,
            'catalogue' => Reports::catalogue(),
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} this month so far, unless the request says otherwise */
    private function range(Business $business, Request $request): array
    {
        $today = $business->today();

        $parse = function (?string $value, Carbon $fallback) use ($business) {
            try {
                return $value ? Carbon::createFromFormat('Y-m-d', $value, $business->timezone)->startOfDay() : $fallback;
            } catch (\Throwable) {
                return $fallback;
            }
        };

        $from = $parse($request->query('from'), $today->copy()->startOfMonth());
        $to = $parse($request->query('to'), $today->copy());

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }

    private function csv(Business $business, string $report, array $table, Carbon $from, Carbon $to, bool $dated): StreamedResponse
    {
        $name = $business->slug.'-'.$report.'-'.($dated ? $from->format('Ymd').'-'.$to->format('Ymd') : $to->format('Ymd')).'.csv';

        activity()
            ->performedOn($business)
            ->causedBy(request()->user())
            ->withProperties(['report' => $report, 'from' => $from->toDateString(), 'to' => $to->toDateString()])
            ->event('report.exported')
            ->log('Report exported: '.$report);

        return response()->streamDownload(function () use ($table) {
            $out = fopen('php://output', 'w');
            // So Excel reads the file as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");

            $plain = fn ($value) => $value instanceof Money ? $value->toDecimal() : $value;

            fputcsv($out, array_column($table['columns'], 'label'));

            foreach ($table['rows'] as $row) {
                fputcsv($out, array_map(fn ($c) => $plain($row[$c['key']] ?? ''), $table['columns']));
            }

            if ($table['totals'] !== []) {
                fputcsv($out, array_map(
                    fn ($c, $i) => $i === 0 ? 'Total' : $plain($table['totals'][$c['key']] ?? ''),
                    $table['columns'], array_keys($table['columns']),
                ));
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
