<?php

namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;

class CatalogNameNormalizer
{
    public function display(string $value): string
    {
        $value = preg_replace('/^\s+|\s+$/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        if ($value === '' || mb_strlen($value, 'UTF-8') > 255) {
            throw new InvalidArgumentException('Catalog names must contain between 1 and 255 characters.');
        }

        return $value;
    }

    public function normalize(string $value): string
    {
        return Str::lower($this->display($value));
    }
}
