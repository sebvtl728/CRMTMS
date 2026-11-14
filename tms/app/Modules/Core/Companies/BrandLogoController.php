<?php

namespace App\Modules\Core\Companies;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The logo of the default company, for the sidebar of signed-in users.
 * Cached by the browser (private: never by a shared proxy) for an hour;
 * the URL changes with the file (Brand), and the ETag (SHA-256 of the file)
 * answers a revalidation with 304. 404 without a logo.
 */
class BrandLogoController extends Controller
{
    public function __invoke(Request $request, CompanyBranding $branding): Response
    {
        $company = Brand::company();
        $logo = $company === null ? null : $branding->logoFile($company);
        abort_if($logo === null, 404);

        $response = response($logo['content'], 200, [
            'Content-Type' => $logo['mime'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag($logo['sha256']);
        $response->setPrivate();
        $response->setMaxAge(Brand::TTL_SECONDS);
        $response->isNotModified($request);

        return $response;
    }
}
