<?php

namespace App\Services\Navigation;

use Filament\Resources\Resource;
use Illuminate\Support\Collection;

/**
 * Read-only workspace preview for an already authorized Filament destination.
 * This is not an alternative query path: a Resource's own scoped query,
 * canViewAny, and canView policy remain authoritative.
 */
class WorkspaceRecordPreviewService
{
    /**
     * @param array{label:string,url:string,icon?:string,class?:class-string} $link
     * @return array{label:string,url:string,icon:string,total:int|null,records:Collection}
     */
    public function forLink(array $link): array
    {
        $data = [
            'label' => $link['label'],
            'url' => $link['url'],
            'icon' => $link['icon'] ?? 'heroicon-o-document-text',
            'total' => null,
            'records' => collect(),
        ];

        $resource = $link['class'] ?? $this->resourceForUrl($link['url']);
        if (! is_string($resource) || ! is_subclass_of($resource, Resource::class)
            || ! $resource::canViewAny()) {
            return $data;
        }

        $query = $resource::getEloquentQuery();
        $data['total'] = (clone $query)->count();

        if (! array_key_exists('view', $resource::getPages())) {
            return $data;
        }

        $modelClass = $resource::getModel();
        $qualifiedKey = (new $modelClass())->getQualifiedKeyName();
        $data['records'] = (clone $query)->orderByDesc($qualifiedKey)->limit(8)
            ->get()
            ->filter(fn ($record): bool => $resource::canView($record))
            ->map(function ($record) use ($resource): array {
                $status = $record->status ?? null;
                if ($status instanceof \BackedEnum) {
                    $status = $status->value;
                }

                return [
                    'reference' => (string) ($record->reference ?? $record->sku
                        ?? $record->name ?? $record->title ?? ('Record #'.$record->getKey())),
                    'date' => $record->created_at?->format('d M Y') ?? '—',
                    'status' => is_string($status) ? str($status)->headline()->toString() : null,
                    'url' => $resource::getUrl('view', ['record' => $record]),
                ];
            })
            ->values();

        return $data;
    }

    private function resourceForUrl(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return null;
        }

        // Filament exposes resources of the CURRENT authorized admin panel.
        // Never resolve a URL from another panel or guess a model table.
        foreach (filament()->getResources() as $resource) {
            if (! is_subclass_of($resource, Resource::class) || ! $resource::canViewAny()) {
                continue;
            }
            $resourcePath = trim((string) parse_url($resource::getUrl(), PHP_URL_PATH), '/');
            if ($resourcePath === $path) {
                return $resource;
            }
        }

        return null;
    }
}
