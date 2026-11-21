<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

class SupplierDirectShippingAuthorizationException extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct('Supplier-direct shipping permission is required. Ask Owner/Admin to allow Supplier-Direct Shipping under Web Sales Access Control.');
    }
}
