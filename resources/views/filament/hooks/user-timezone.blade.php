<script>
    document.addEventListener('livewire:init', () => {
        const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (! timezone) return;
        Livewire.hook('request', ({ options }) => {
            options.headers['X-Client-Timezone'] = timezone;
        });
    });
</script>
