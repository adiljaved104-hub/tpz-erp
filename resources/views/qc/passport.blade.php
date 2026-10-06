<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $snapshot['reference'] }} · TPZ Verified Device</title>
    <link rel="stylesheet" href="{{ asset('css/qc-passport.css') }}">
    <script src="{{ asset('js/qc-passport.js') }}" defer></script>
</head>
<body>
<main>
    <div class="watermarks" aria-hidden="true">@for($i = 0; $i < 8; $i++)<span>TECH POINT ZONE<br>VERIFIED QC<br>{{ $snapshot['reference'] }} · v{{ $certificate->version }}</span>@endfor</div>
    <div class="passport-content">
        <header>
            <img src="{{ asset('branding/tech-point-zone-logo.png') }}" width="180" alt="Tech Point Zone">
            @if($isCurrent)
                <p class="status verified">CURRENT / VERIFIED</p><h2>TPZ QC VERIFIED</h2>
            @else
                <p class="status historical">SUPERSEDED / HISTORICAL</p><h2>Historical certificate - Version {{ $certificate->version }}</h2>
                @if($currentUrl !== $verificationUrl)<a href="{{ $currentUrl }}">View current QC version</a>@else<p>Reinspection is in progress. This previous certificate is not current.</p>@endif
            @endif
            <h1>{{ $snapshot['product']['title'] }}</h1>
            <h2>Final Tested Configuration</h2>
            <p>@include('qc.final-specs', ['final' => $snapshot['final']])</p>
        </header>
        <dl class="grid metadata">
            <div><dt>QC ID / Version</dt><dd>{{ $snapshot['reference'] }} · v{{ $certificate->version }}</dd></div>
            <div><dt>Serial / IMEI</dt><dd>{{ strlen($snapshot['serial']) > 6 ? substr($snapshot['serial'], 0, 3).'••••'.substr($snapshot['serial'], -3) : '••••' }}</dd></div>
            <div><dt>Condition / Grade</dt><dd>{{ ucfirst($snapshot['product']['condition']) }} · {{ $snapshot['grade'] }}</dd></div>
            <div><dt>Certified At</dt><dd>{{ \Illuminate\Support\Carbon::parse($snapshot['certified_at'])->format('d M Y H:i T') }}</dd></div>
        </dl>
        <div class="actions">
            <a class="button" href="{{ route('qc.certificate.public', $certificate->public_token) }}">Download Certificate PDF</a>
            <button type="button" data-print-certificate>Print Certificate</button>
        </div>
        <section aria-labelledby="checks-heading">
            <h2 id="checks-heading">{{ count($snapshot['checks']) }} applicable checks · {{ count($snapshot['checks']) }} passed</h2>
            @foreach(collect($snapshot['checks'])->groupBy('group') as $group => $checks)
                <details><summary>{{ $group }} · {{ $checks->count() }} passed</summary><ul>@foreach($checks as $check)<li>{{ $check['label'] }}: {{ $check['detail'] }} @if($check['measurement'] !== null)({{ $check['measurement'] }})@endif</li>@endforeach</ul></details>
            @endforeach
        </section>
        <section aria-labelledby="evidence-heading">
            <h2 id="evidence-heading">Verified Condition Evidence</h2>
            <div class="grid evidence-grid">
                @foreach($evidence as $image)
                    @php($kind = \App\Services\Qc\QcEvidenceService::KINDS[$image->kind])
                    @php($uploaded = $image->uploaded_at->format('d M Y H:i:s T'))
                    <figure class="evidence">
                        <button type="button" class="evidence-open" data-evidence data-kind="{{ $kind }}" data-uploaded="{{ $uploaded }}" data-reference="{{ $snapshot['reference'] }} · v{{ $certificate->version }}" aria-label="Enlarge {{ $kind }}">
                            <img loading="lazy" src="{{ route('qc.evidence.public', ['token' => $certificate->public_token, 'id' => $image->public_id]) }}" alt="{{ $kind }}">
                        </button>
                        <figcaption>{{ $kind }}<small>Uploaded {{ $uploaded }}</small></figcaption>
                    </figure>
                @endforeach
            </div>
        </section>
        @if($snapshot['remarks'])<p>{{ $snapshot['remarks'] }}</p>@endif
        <h3>Inspected &amp; Certified by: {{ $snapshot['technician'] }}</h3>
        <footer class="authenticity">
            <img class="verification-qr" src="{{ $qr }}" alt="Verification QR">
            <p>Electronically verified by TPZ ERP. This certificate is electronically issued by Tech Point Zone. Alterations invalidate this document. Scan the QR code or verify the QC ID online to confirm authenticity.</p>
            <p>Valid only when the QR code resolves to the matching TPZ QC ID and certificate version.</p>
            <p>Certificate Fingerprint: <strong>{{ $fingerprint }}</strong><small>A visible identity reference, not a digital signature.</small></p>
        </footer>
    </div>
</main>
<dialog class="evidence-viewer" aria-labelledby="viewer-kind">
    <div class="viewer-toolbar"><h2 id="viewer-kind"></h2><button type="button" data-viewer-close autofocus>Close</button></div>
    <img class="viewer-image" alt="">
    <p class="viewer-meta"></p>
    <div class="viewer-navigation"><button type="button" data-viewer-previous>Previous</button><span class="viewer-counter" aria-live="polite"></span><button type="button" data-viewer-next>Next</button></div>
</dialog>
</body>
</html>
