<?php

namespace App\Actions\Products;

use App\Enums\ProductPermission;
use App\Models\User;
use App\Services\Authorization\ProductAuthorization;
use App\Services\ReferenceSequenceService;

class GenerateProductSku
{
    public function __construct(
        private readonly ProductAuthorization $authorization,
        private readonly ReferenceSequenceService $references,
    ) {}

    public function handle(User $actor): string
    {
        $this->authorization->authorize($actor, ProductPermission::Create);

        return $this->references->nextProductSku();
    }
}
