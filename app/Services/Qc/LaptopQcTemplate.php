<?php

namespace App\Services\Qc;

class LaptopQcTemplate
{
    /** Stable keys and definitions are copied into each inspection, never read live for certificates. */
    public function checks(): array
    {
        $groups = [
            'Identity & Configuration' => ['serial' => 'Serial / IMEI verified', 'model' => 'Model verified', 'processor' => 'Processor detected', 'ram' => 'RAM capacity detected', 'storage' => 'Storage capacity detected', 'sku' => 'Product / SKU verified'],
            'Physical / Cosmetic Condition' => ['housing' => 'Housing condition', 'hinges' => 'Hinges', 'screws' => 'Screws / seals', 'scratches' => 'Scratches assessed', 'dents' => 'Dents assessed', 'cleanliness' => 'Device cleanliness'],
            'Display' => ['brightness' => 'Display brightness', 'pixels' => 'Dead pixels', 'burn_in' => 'Burn-in / pressure marks', 'flicker' => 'Display flicker', 'screen_surface' => 'Screen scratches', 'touchscreen' => 'Touchscreen'],
            'CPU / RAM / Storage' => ['cpu_diagnostic' => 'CPU diagnostic', 'memory_diagnostic' => 'Memory diagnostic', 'ssd_health' => 'SSD health %', 'smart' => 'SMART diagnostic', 'storage_read_write' => 'Storage read / write', 'capacity' => 'Detected capacity verified'],
            'Keyboard / Input' => ['keys' => 'All keyboard keys', 'backlight' => 'Keyboard backlight', 'trackpad' => 'Trackpad', 'gestures' => 'Trackpad gestures', 'buttons' => 'Input buttons', 'function_keys' => 'Function keys'],
            'Camera / Audio' => ['webcam' => 'Webcam image', 'camera_indicator' => 'Camera indicator', 'microphone' => 'Microphone', 'speakers' => 'Speakers', 'headphone' => 'Headphone jack', 'audio_recording' => 'Audio recording / playback'],
            'Connectivity' => ['wifi' => 'Wi-Fi', 'bluetooth' => 'Bluetooth', 'ethernet' => 'Ethernet', 'wireless_stability' => 'Wireless stability', 'network_transfer' => 'Network transfer', 'airplane' => 'Airplane mode'],
            'Ports / Charging' => ['usb_a' => 'USB-A', 'usb_c' => 'USB-C', 'hdmi' => 'HDMI / DisplayPort', 'sd_reader' => 'SD card reader', 'charging_port' => 'Charging port', 'adapter_detection' => 'Power adapter detection'],
            'Battery / Power / Thermal' => ['battery_health' => 'Battery health %', 'battery_cycles' => 'Battery cycles', 'charging' => 'Battery charging', 'sleep_wake' => 'Sleep / wake', 'fan' => 'Fan operation', 'temperature' => 'Peak load temperature °C'],
            'Software / Security' => ['os' => 'OS installed', 'activation' => 'OS activation', 'drivers' => 'Drivers', 'bios' => 'BIOS', 'account_lock' => 'Activation / account lock removed', 'data_wipe' => 'Data wipe / factory reset'],
            'Accessories / Packaging' => ['charger' => 'Charger supplied', 'wattage' => 'Charger wattage verified', 'cable' => 'Power cable', 'packaging' => 'Protective packaging', 'grade' => 'Physical grade assessed', 'accessories' => 'Included accessories verified'],
            'Upgrade Verification' => ['upgrade_ram' => 'Requested RAM verified', 'upgrade_storage' => 'Requested storage verified', 'upgrade_boot' => 'Post-upgrade boot', 'upgrade_memory' => 'Post-upgrade memory test', 'upgrade_storage_test' => 'Post-upgrade storage test', 'upgrade_reassembly' => 'Post-upgrade reassembly'],
        ];
        $optional = ['touchscreen', 'backlight', 'ethernet', 'usb_a', 'usb_c', 'hdmi', 'sd_reader', 'battery_cycles', 'camera_indicator', 'headphone'];
        $measurements = ['ssd_health' => [0, 100], 'battery_health' => [0, 100], 'battery_cycles' => [0, 100000], 'temperature' => [0, 130]];
        $checks = [];
        foreach ($groups as $group => $labels) {
            foreach ($labels as $key => $label) {
                $checks[$key] = ['key' => $key, 'group' => $group, 'label' => $label, 'mandatory' => ! in_array($key, $optional, true), 'allows_na' => in_array($key, $optional, true), 'feature' => in_array($key, $optional, true) ? $key : null, 'upgrade' => $group === 'Upgrade Verification', 'measurement' => $measurements[$key] ?? null, 'pass_text' => 'Working correctly / verified'];
            }
        }

        return $checks;
    }

    public function features(): array
    {
        return collect($this->checks())->whereNotNull('feature')->pluck('label', 'feature')->all();
    }
}
