<?php

namespace App\Http\Controllers\Auth;

use App\Services\LoginBrandingService;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LoginBrandingAssetController extends Controller
{
    public function __invoke(LoginBrandingService $branding): StreamedResponse
    {
        $path = $branding->logoPath();
        abort_unless($path, 404);

        return Storage::disk('public')->response($path, 'login-logo', [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
