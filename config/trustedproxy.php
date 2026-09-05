<?php

return [
    /*
    | Only list reverse-proxy IP addresses or CIDR ranges controlled by the
    | deployment. Never use a wildcard unless the network boundary guarantees
    | that every request reaches Laravel through a trusted proxy.
    */
    'proxies' => env('TRUSTED_PROXIES'),
];
