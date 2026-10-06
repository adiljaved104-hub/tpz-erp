<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TPZ QC Verification</title></head><body style="font-family:Arial,sans-serif;max-width:640px;margin:3rem auto;padding:1rem">
<img src="{{ asset('branding/tech-point-zone-logo.png') }}" alt="Tech Point Zone" width="180"><h1>Verify your device</h1><p>Enter the exact QC Certificate ID or Serial / IMEI.</p>
<form method="get"><label for="q">Certificate ID or Serial / IMEI</label><input id="q" name="q" required maxlength="100" value="{{ is_string(request('q')) ? request('q') : '' }}"><button type="submit">Verify QC</button></form>
@if($searched)<p>No verified QC record found.</p>@endif
</body></html>
