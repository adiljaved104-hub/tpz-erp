@props(['verification' => false])
<aside class="tpz-auth-hero" aria-label="Tech Point Zone ERP">
    @if(app()->environment('staging'))
        <span class="tpz-auth-test-label">STAGING</span>
    @endif
    <div class="tpz-auth-hero-brand">
        <strong class="tpz-auth-monogram" aria-hidden="true">TPZ</strong>
        <span class="tpz-auth-hero-line" aria-hidden="true"></span>
        <div class="tpz-auth-hero-company">TECH POINT ZONE <span>ERP</span></div>
    </div>
    <p class="tpz-auth-hero-eyebrow">ENTERPRISE RESOURCE PLANNING</p>
    <div class="tpz-auth-hero-message">
        @if($verification)
            <h2>Secure<br><span>Verification.</span></h2>
            <p>We’re verifying your identity to keep your TPZ ERP account secure.</p>
        @else
            <h2>One Platform.<br><span>Complete Business Control.</span></h2>
            <p>One secure workspace for your daily business operations.</p>
        @endif
    </div>
    @if($verification)
        <div class="tpz-auth-verify-art" aria-hidden="true">
            <x-heroicon-o-shield-check />
        </div>
    @else
        <div class="tpz-auth-modules" aria-label="ERP modules">
            @foreach([
                ['heroicon-o-cube', 'Inventory & Warehouse'],
                ['heroicon-o-shopping-cart', 'Purchasing & Suppliers'],
                ['heroicon-o-shopping-bag', 'Sales & Orders'],
                ['heroicon-o-chart-bar', 'Accounting & Finance'],
                ['heroicon-o-users', 'HR & Attendance'],
                ['heroicon-o-shield-check', 'QC & Returns'],
                ['heroicon-o-computer-desktop', 'Marketplace Operations'],
                ['heroicon-o-chart-pie', 'Reports & Analytics'],
            ] as [$icon, $label])
                <div class="tpz-auth-module">
                    @svg($icon)
                    <span>{{ $label }}</span>
                </div>
            @endforeach
        </div>
    @endif
    <p class="tpz-auth-hero-bottom">© {{ date('Y') }} Tech Point Zone · Authorized access only</p>
</aside>