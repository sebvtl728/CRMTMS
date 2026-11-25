<?php

namespace App\Modules\Admissions\Booklet;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public page of the monthly booklet follow-up (no account; the host
 * gives the organisme): the same 404 page for any invalid link.
 */
final class PublicBookletController extends Controller
{
    public function __construct(private readonly BookletAnswering $answering) {}

    public function show(Request $request, string $token): Response
    {
        $link = BookletLinks::find($token);
        if ($link === null) {
            return Inertia::render('public/booklet', ['state' => 'invalid'])->toResponse($request)->setStatusCode(404);
        }

        return Inertia::render('public/booklet', [
            ...$this->answering->view($link),
            'save_url' => route('booklet.public.save', $token, false),
            'code_url' => route('booklet.public.code', $token, false),
            'sign_url' => route('booklet.public.sign', $token, false),
        ])->toResponse($request)
            ->header('Cache-Control', 'no-store, private');
    }

    public function save(Request $request, string $token): JsonResponse
    {
        $this->answering->save(BookletLinks::find($token) ?? abort(404), $request->only(['entries', 'observations', 'marks']));

        return response()->json(['saved' => true])->header('Cache-Control', 'no-store, private');
    }

    public function code(string $token): JsonResponse
    {
        $this->answering->sendCode(BookletLinks::find($token) ?? abort(404));

        return response()->json(['sent' => true])->header('Cache-Control', 'no-store, private');
    }

    public function sign(Request $request, string $token): JsonResponse
    {
        $link = BookletLinks::find($token) ?? abort(404);
        $this->answering->sign($link, (string) $request->input('code', ''), (string) $request->input('drawing', ''), (string) $request->ip(), (string) $request->userAgent());

        return response()->json(['signed' => true], 201)->header('Cache-Control', 'no-store, private');
    }
}
