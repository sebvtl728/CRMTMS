<?php

namespace App\Modules\Admissions\Positioning;

use App\Http\Controllers\Controller;

/** The candidate's personal link (task 3). */
class PositioningUploadController extends Controller
{
    public function show(string $token): never
    {
        abort(404);
    }

    public function download(string $token, string $version): never
    {
        abort(404);
    }

    public function store(string $token): never
    {
        abort(404);
    }
}
