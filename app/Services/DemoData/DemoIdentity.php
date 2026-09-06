<?php

namespace App\Services\DemoData;

use Ramsey\Uuid\Uuid;

final class DemoIdentity
{
    public function marker(): string
    {
        return (string) config('demo.marker', '[DEMO:staging-v1]');
    }

    public function uuid(string $scope): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://techpointzone.com/erp/demo/'.config('demo.version').'/'.$scope)->toString();
    }

    public function external(string $prefix, int $number): string
    {
        return sprintf('DEMO-%s-v1-%03d', strtoupper($prefix), $number);
    }

    public function note(string $text): string
    {
        return $this->marker().' '.$text;
    }

    public function emails(): array
    {
        return [
            'admin' => 'demo.admin@techpointzone.com',
            'manager1' => 'demo.manager1@techpointzone.com',
            'manager2' => 'demo.manager2@techpointzone.com',
            'staff1' => 'demo.staff1@techpointzone.com',
            'staff2' => 'demo.staff2@techpointzone.com',
            'staff3' => 'demo.staff3@techpointzone.com',
        ];
    }
}
