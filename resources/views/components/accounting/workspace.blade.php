@props(['current', 'title', 'description' => '', 'books' => null, 'book' => null])
@php
    $user = auth()->user();
    $authorization = app(\App\Services\Authorization\AccountingAuthorization::class);
    $items = [
        ['key' => 'home', 'label' => 'Accounting Home', 'icon' => '⌂', 'permission' => null, 'url' => \App\Filament\Pages\Finance\AccountingHome::getUrl()],
        ['key' => 'chart', 'label' => 'Chart of Accounts', 'icon' => '▦', 'permission' => \App\Enums\AccountingPermission::ViewChart, 'url' => \App\Filament\Pages\Finance\AccountingChartOfAccounts::getUrl()],
        ['key' => 'customers', 'label' => 'Customer Center', 'icon' => '♙', 'preview' => true],
        ['key' => 'vendors', 'label' => 'Vendor Center', 'icon' => '♧', 'preview' => true],
        ['key' => 'invoice', 'label' => 'Sales Invoice', 'icon' => '＋', 'preview' => true],
        ['key' => 'bill', 'label' => 'Supplier Bill', 'icon' => '▤', 'preview' => true],
        ['key' => 'journal', 'label' => 'General Journal', 'icon' => '≋', 'permission' => \App\Enums\AccountingPermission::ViewJournals, 'url' => \App\Filament\Pages\Finance\AccountingJournals::getUrl()],
        ['key' => 'ledger', 'label' => 'General Ledger', 'icon' => '⇄', 'permission' => \App\Enums\AccountingPermission::ViewGeneralLedger, 'url' => \App\Filament\Pages\Finance\AccountingGeneralLedger::getUrl()],
        ['key' => 'trial', 'label' => 'Trial Balance', 'icon' => '▥', 'permission' => \App\Enums\AccountingPermission::ViewTrialBalance, 'url' => \App\Filament\Pages\Finance\AccountingTrialBalance::getUrl()],
        ['key' => 'periods', 'label' => 'Accounting Periods', 'icon' => '▣', 'permission' => \App\Enums\AccountingPermission::ViewPeriods, 'url' => \App\Filament\Pages\Finance\AccountingPeriods::getUrl()],
        ['key' => 'banking', 'label' => 'Banking Center', 'icon' => '⇆', 'preview' => true],
        ['key' => 'reports', 'label' => 'Financial Reports Center', 'icon' => '▧', 'preview' => true],
    ];
    $items = collect($items)->filter(fn ($item) => isset($item['preview']) || ! isset($item['permission']) || $authorization->allows($user, $item['permission']))->values();
    $currentUrl = collect($items)->firstWhere('key', $current)['url'] ?? null;
@endphp
<div class="tpz-accounting-workspace" data-accounting-page="{{ $current }}" data-accounting-url="{{ $currentUrl }}" data-accounting-title="{{ $title }}">
    <aside class="tpz-accounting-sidebar">
        <a class="tpz-accounting-brand" href="{{ \Filament\Facades\Filament::getUrl() }}" aria-label="Return to TPZ ERP"><span class="tpz-accounting-mark">TP</span><span><strong>TPZ ERP</strong><small>Accounting workspace</small></span></a>
        <div class="tpz-accounting-nav-label">Accounting</div>
        <nav class="tpz-accounting-nav" aria-label="Accounting navigation">
            @foreach ($items as $item)
                @if (isset($item['preview']))
                    <span class="tpz-accounting-nav-item is-preview" aria-disabled="true" title="Not implemented yet"><span class="tpz-accounting-icon">{{ $item['icon'] }}</span><span>{{ $item['label'] }}</span><small>Preview</small></span>
                @else
                    <a class="tpz-accounting-nav-item {{ $current === $item['key'] ? 'is-active' : '' }}" href="{{ $item['url'] }}"><span class="tpz-accounting-icon">{{ $item['icon'] }}</span><span>{{ $item['label'] }}</span></a>
                @endif
            @endforeach
        </nav>
        <a class="tpz-accounting-back" href="{{ \Filament\Facades\Filament::getUrl() }}">↩ <span>Back to TPZ ERP</span></a>
    </aside>
    <main class="tpz-accounting-main">
        <header class="tpz-accounting-topbar">
            <div class="tpz-accounting-book">
                @if ($books && $books->count() > 1)
                    <label class="sr-only" for="accounting-book-selector">Accounting book</label>
                    <select id="accounting-book-selector" wire:model.live="bookId" aria-label="Accounting book">@foreach ($books as $item)<option value="{{ $item->id }}">{{ $item->name }} · {{ $item->currency }}</option>@endforeach</select>
                @elseif ($book)
                    <span>{{ $book->name }} · {{ $book->currency }}</span>
                @else
                    <span>Accounting workspace</span>
                @endif
            </div>
            <label class="tpz-accounting-search"><span aria-hidden="true">⌕</span><input type="search" placeholder="Find an Accounting page…" aria-label="Find an Accounting page" data-accounting-search><kbd>Ctrl K</kbd></label>
            <span class="tpz-accounting-avatar" aria-label="Signed in">{{ mb_strtoupper(mb_substr($user?->name ?? 'TP', 0, 2)) }}</span>
            <a class="tpz-accounting-exit" href="{{ \Filament\Facades\Filament::getLogoutUrl() }}" title="Sign out">Sign out</a>
        </header>
        <div class="tpz-accounting-content">
            <nav class="tpz-accounting-tabs" aria-label="Open accounting pages" data-accounting-tabs></nav>
            <div class="tpz-accounting-page-head"><div><h1>{{ $title }}</h1><p>{{ $description }}</p></div><div class="tpz-accounting-page-actions">{{ $actions ?? '' }}</div></div>
            <div class="tpz-accounting-slot">{{ $slot }}</div>
        </div>
    </main>
</div>
@once
<script>
(() => {
    const root = document.querySelector('.tpz-accounting-workspace');
    if (!root) return;
    const known = [...root.querySelectorAll('.tpz-accounting-nav-item[href]')].map(item => ({url: item.href, title: item.innerText.trim()}));
    const current = {url: root.dataset.accountingUrl, title: root.dataset.accountingTitle};
    if (!current.url) return;
    const key = 'tpz-accounting-open-tabs';
    let tabs = [];
    try { tabs = JSON.parse(sessionStorage.getItem(key) || '[]'); } catch (_) { tabs = []; }
    tabs = tabs.filter(tab => known.some(item => item.url === tab.url));
    if (!tabs.some(tab => tab.url === current.url)) tabs.push(current);
    sessionStorage.setItem(key, JSON.stringify(tabs));
    const holder = root.querySelector('[data-accounting-tabs]');
    const render = () => { holder.innerHTML = tabs.map((tab, index) => `<a class="tpz-accounting-tab ${tab.url === current.url ? 'is-active' : ''}" href="${tab.url}">${tab.title}${index ? `<button type="button" aria-label="Close ${tab.title}" data-close-tab="${index}">×</button>` : ''}</a>`).join(''); };
    render();
    holder.addEventListener('click', event => { const button = event.target.closest('[data-close-tab]'); if (!button) return; event.preventDefault(); const index = Number(button.dataset.closeTab); const removed = tabs.splice(index, 1)[0]; sessionStorage.setItem(key, JSON.stringify(tabs)); if (removed?.url === current.url && tabs.length) location.href = tabs[tabs.length - 1].url; else render(); });
    const search = root.querySelector('[data-accounting-search]');
    const focusSearch = () => search?.focus();
    document.addEventListener('keydown', event => { if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); focusSearch(); } if (event.key === 'Escape') search?.blur(); });
    search?.addEventListener('keydown', event => { if (event.key !== 'Enter') return; const query = search.value.trim().toLowerCase(); const match = known.find(item => item.title.toLowerCase().includes(query)); if (match) location.href = match.url; });
})();
</script>
@endonce
