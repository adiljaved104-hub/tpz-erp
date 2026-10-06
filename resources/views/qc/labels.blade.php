<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>TPZ QC Labels</title>
<style>
@page{size:100mm 50mm;margin:0}*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;color:#000}.label{width:100mm;height:50mm;padding:2.5mm;page-break-after:always;break-after:page;break-inside:avoid;overflow:hidden}.label:last-child{page-break-after:auto;break-after:auto}.content{display:grid;grid-template-columns:minmax(0,1fr) 35mm;gap:3mm;height:45mm;align-items:center}.identity{min-width:0}.brand{font-size:11pt;font-weight:bold;line-height:1.1}.verified{font-size:8pt;font-weight:bold;line-height:1.2;margin:.5mm 0 1mm}.title{font-size:9pt;font-weight:bold;line-height:1.12;overflow-wrap:anywhere;max-height:10mm;overflow:hidden}.spec{font-size:7pt;line-height:1.15;margin:.6mm 0;overflow-wrap:anywhere;max-height:6mm;overflow:hidden}.serial{font-size:8.5pt;font-weight:bold;line-height:1.1;overflow-wrap:anywhere;margin:1mm 0}.serial-long{font-size:6pt}.meta{font-size:7pt;line-height:1.15;overflow-wrap:anywhere;margin:.8mm 0}.qr-area{text-align:center;background:#fff}.qr{display:block;width:35mm;height:35mm;background:#fff}.qr-caption{font-size:7pt;margin:1mm 0 0}.tools{padding:12px}@media print{.tools{display:none}html,body{width:100mm;margin:0;padding:0}}
</style></head><body>
<div class="tools"><button onclick="window.print()">Print labels</button> Thermal target: 100 × 50 mm. Use actual size (100%), no browser headers/footers.</div>
@foreach($labels as $label)
    @php($s = $label['snapshot'])
    <section class="label"><div class="content">
        <div class="identity">
            <div class="brand">TECH POINT ZONE</div>
            <div class="verified">QC VERIFIED</div>
            <div class="title">{{ \Illuminate\Support\Str::limit($s['product']['label_title'], 51) }}</div>
            <div class="spec">{{ isset($s['final']['cpu']) ? \Illuminate\Support\Str::limit($s['final']['cpu'], 36).' · ' : '' }}{{ isset($s['final']['ram_mb']) ? ($s['final']['ram_mb'] / 1024).' GB · ' : '' }}{{ $s['final']['storage_gb'] }} GB</div>
            <div @class(['serial', 'serial-long' => strlen($s['serial']) > 40])>Serial / IMEI:<br>{{ $s['serial'] }}</div>
            <div class="meta">QC: {{ $s['reference'] }} · v{{ $label['certificate']->version }}</div>
            <div class="meta">{{ ucfirst($s['product']['condition']) }} · Grade {{ $s['grade'] }} · {{ $s['product']['sku'] }}</div>
        </div>
        <div class="qr-area"><img class="qr" src="{{ $label['qr'] }}" alt="Device verification QR"><div class="qr-caption">Scan to Verify QC</div></div>
    </div></section>
@endforeach
</body></html>
