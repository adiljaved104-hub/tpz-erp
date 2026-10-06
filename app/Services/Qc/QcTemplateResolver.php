<?php

namespace App\Services\Qc;

use App\Models\Product;
use App\Models\QcInspection;
use Illuminate\Validation\ValidationException;

class QcTemplateResolver
{
    public function supports(Product $product): bool
    {
        try {
            $this->resolve($product);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function resolve(Product $product): array
    {
        $category = strtolower($product->displayCategoryName());
        $identity = strtolower($product->model.' '.$product->name);
        $apple = str_contains(strtolower($product->displayBrandName()), 'apple');
        $laptop = str_contains($category, 'laptop') || str_contains($category, 'notebook');
        if (str_contains($category, 'tablet') || str_contains($category, 'ipad')) {
            $type = 'tablet';
        } elseif (str_contains($category, 'macbook') || ($laptop && (str_contains($identity, 'macbook') || $apple))) {
            $type = 'macbook';
        } elseif ($laptop) {
            $type = 'windows_laptop';
        } else {
            throw ValidationException::withMessages(['product_id' => 'QC currently supports Windows laptops, MacBooks and tablets/iPads. Select a supported Product.']);
        }
        $checks = app(LaptopQcTemplate::class)->checks();
        $defaults = ['backlight', 'usb_a', 'usb_c', 'hdmi', 'headphone', 'camera_indicator'];
        if ($type === 'macbook') {
            foreach (['os' => 'macOS installed', 'activation' => 'Apple ID / iCloud signed out', 'drivers' => 'macOS system updates', 'bios' => 'Firmware / startup security', 'account_lock' => 'Activation Lock cleared', 'cpu_diagnostic' => 'Apple Diagnostics'] as $key => $label) {
                $checks[$key]['label'] = $label;
            }
            foreach (['touch_id' => 'Touch ID', 'magsafe' => 'MagSafe charging'] as $key => $label) {
                $checks[$key] = $this->definition($key, $label, 'MacBook Hardware', $key);
            }
            $checks['ssd_health']['feature'] = 'ssd_health';
            $checks['ssd_health']['allows_na'] = true;
            $checks['ssd_health']['mandatory'] = false;
            $checks['fan']['feature'] = 'fan';
            $checks['fan']['label'] = 'Cooling fan (if equipped)';
            $checks['fan']['allows_na'] = true;
            $checks['fan']['mandatory'] = false;
            $defaults = ['backlight', 'usb_c', 'battery_cycles'];
        } elseif ($type === 'tablet') {
            $groups = [
                'Identity & Configuration' => ['serial' => 'Serial verified', 'model' => 'Model verified', 'sku' => 'Product / SKU verified', 'storage' => 'Storage capacity verified', 'device_info' => 'Device information verified', 'imei' => 'IMEI / cellular identity verified'],
                'Physical / Cosmetic Condition' => ['housing' => 'Housing condition', 'screws' => 'Seals / screws', 'scratches' => 'Scratches assessed', 'dents' => 'Dents assessed', 'cleanliness' => 'Device cleanliness'],
                'Display / Touch' => ['touchscreen' => 'Touchscreen / digitizer', 'multi_touch' => 'Multi-touch gestures', 'brightness' => 'Display brightness', 'pixels' => 'Dead pixels', 'burn_in' => 'Burn-in / pressure marks', 'flicker' => 'Display flicker', 'screen_surface' => 'Screen scratches'],
                'Camera / Audio' => ['front_camera' => 'Front camera', 'rear_camera' => 'Rear camera', 'microphone' => 'Microphone', 'speakers' => 'Speakers', 'audio_recording' => 'Audio recording / playback', 'headphone' => 'Headphone jack'],
                'Connectivity / Sensors' => ['wifi' => 'Wi-Fi', 'bluetooth' => 'Bluetooth', 'wireless_stability' => 'Wireless stability', 'rotation' => 'Screen rotation', 'sensors' => 'Motion / orientation sensors', 'cellular' => 'Cellular / mobile data', 'gps' => 'GPS / location'],
                'Buttons / Biometrics' => ['power_button' => 'Power button', 'volume_buttons' => 'Volume buttons', 'home_button' => 'Home button', 'touch_id' => 'Touch ID', 'face_id' => 'Face ID'],
                'Battery / Charging' => ['battery_health' => 'Battery health %', 'battery_cycles' => 'Battery cycles', 'charging' => 'Battery charging', 'charging_port' => 'Charging port', 'sleep_wake' => 'Sleep / wake', 'thermal' => 'Abnormal heat check', 'usb_c' => 'USB-C', 'lightning' => 'Lightning port'],
                'Software / Security' => ['os' => $apple || str_contains($identity, 'ipad') ? 'iPadOS installed' : 'Tablet OS installed', 'account_lock' => $apple || str_contains($identity, 'ipad') ? 'Apple ID / Activation Lock cleared' : 'Activation / account lock removed', 'updates' => 'System updates', 'data_wipe' => 'Data wipe / factory reset'],
                'Accessories / Packaging' => ['charger' => 'Charger supplied', 'cable' => 'Charging cable', 'packaging' => 'Protective packaging', 'grade' => 'Physical grade assessed', 'accessories' => 'Included accessories verified'],
                'Upgrade Verification' => ['upgrade_configuration' => 'Requested final configuration verified', 'upgrade_boot' => 'Configured device boot', 'upgrade_storage_test' => 'Final storage test'],
            ];
            $features = ['imei' => 'cellular', 'cellular' => 'cellular', 'gps' => 'gps', 'home_button' => 'home_button', 'touch_id' => 'touch_id', 'face_id' => 'face_id', 'headphone' => 'headphone', 'battery_health' => 'battery_health', 'battery_cycles' => 'battery_cycles', 'usb_c' => 'usb_c', 'lightning' => 'lightning'];
            $checks = [];
            foreach ($groups as $group => $labels) {
                foreach ($labels as $key => $label) {
                    $checks[$key] = $this->definition($key, $label, $group, $features[$key] ?? null, $group === 'Upgrade Verification');
                }
            }
            $checks['battery_health']['measurement'] = [0, 100];
            $checks['battery_cycles']['measurement'] = [0, 100000];
            $defaults = [];
        }
        $features = collect($checks)->whereNotNull('feature')->mapWithKeys(fn ($check) => [$check['feature'] => $check['feature'] === 'cellular' ? 'Cellular / IMEI' : $check['label']])->all();

        return ['key' => $type.'_v1', 'device_type' => $type, 'label' => match ($type) {
            'macbook' => 'MacBook', 'tablet' => 'Tablet / iPad', default => 'Windows Laptop'
        }, 'checks' => $checks, 'features' => $features, 'default_features' => $defaults, 'final_fields' => $type === 'tablet' ? ['storage_gb', 'os'] : ['cpu', 'ram_mb', 'storage_gb', 'os']];
    }

    public function forInspection(QcInspection $inspection): array
    {
        // Older jobs keep the original Laptop checklist, never reinterpret a certified device.
        return $inspection->product_snapshot['qc_template'] ?? ['key' => 'legacy_laptop_v1', 'device_type' => 'windows_laptop', 'label' => 'Windows Laptop', 'checks' => app(LaptopQcTemplate::class)->checks(), 'features' => app(LaptopQcTemplate::class)->features(), 'final_fields' => ['cpu', 'ram_mb', 'storage_gb', 'os']];
    }

    private function definition(string $key, string $label, string $group, ?string $feature = null, bool $upgrade = false): array
    {
        return ['key' => $key, 'label' => $label, 'group' => $group, 'mandatory' => $feature === null, 'allows_na' => $feature !== null, 'feature' => $feature, 'upgrade' => $upgrade, 'measurement' => null, 'pass_text' => 'Working correctly / verified'];
    }
}
