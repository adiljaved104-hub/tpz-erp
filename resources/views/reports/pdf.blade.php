<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 30px 24px 38px; }
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 8px; }
        h1 { margin: 0 0 4px; font-size: 16px; }
        .meta { margin-bottom: 10px; color: #4b5563; }
        .summary { margin-bottom: 10px; }
        .summary span { display: inline-block; margin-right: 12px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { overflow-wrap: anywhere; border: 1px solid #d1d5db; padding: 4px; vertical-align: top; }
        th { background: #f3f4f6; font-size: 7px; text-align: left; }
        thead { display: table-header-group; }
        footer { position: fixed; right: 0; bottom: -22px; color: #6b7280; }
        footer .page-number:after { content: counter(page); }
    </style>
</head>
<body>
    <h1>TPZ ERP · {{ $report->title }}</h1>
    <div class="meta">Generated {{ now()->format('d M Y H:i T') }} · {{ collect($report->filters)->filter(fn ($value) => filled($value))->map(fn ($value, $key) => str($key)->replace('_', ' ')->title().': '.$value)->implode(' · ') }}</div>
    <div class="summary">@foreach ($report->summary as $label => $value)<span>{{ $label }}: {{ \App\Support\ReportValueFormatter::summary($report, $label, $value) }}</span>@endforeach</div>
    <table>
        <thead><tr>@foreach ($columns as $column)<th>{{ $column['label'] }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse ($report->rows as $row)
                <tr>@foreach ($columns as $column)<td>@if (($column['type'] ?? null) === 'money' && $row[$column['key']] !== null)AED {{ number_format((float) $row[$column['key']], 2) }}@elseif (($column['type'] ?? null) === 'money_pkr' && $row[$column['key']] !== null)PKR {{ number_format((float) $row[$column['key']], 2) }}@elseif (($column['type'] ?? null) === 'money_aed' && $row[$column['key']] !== null)AED {{ number_format((float) $row[$column['key']], 2) }}@else{{ $row[$column['key']] ?? '—' }}@endif</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($columns) }}">No records match the selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>
    <footer>Page <span class="page-number"></span></footer>
</body>
</html>
