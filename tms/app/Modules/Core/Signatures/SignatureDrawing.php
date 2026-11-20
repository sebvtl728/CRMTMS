<?php

namespace App\Modules\Core\Signatures;

use Illuminate\Validation\ValidationException;

/**
 * The signature drawn by the signer: a PNG data URI from the signature page,
 * decoded and checked by GD (a real PNG, at most MAX_BYTES and
 * MAX_WIDTH × MAX_HEIGHT, with some ink). Only these bytes are kept, never
 * the browser's string.
 */
final class SignatureDrawing
{
    public const MAX_BYTES = 200 * 1024;

    public const MAX_WIDTH = 2000;

    public const MAX_HEIGHT = 1000;

    /** Opaque pixels below which the canvas counts as blank (a click is not a signature). */
    public const MIN_INK = 50;

    public const MESSAGE = 'Dessinez votre signature dans le cadre.';

    private const PREFIX = 'data:image/png;base64,';

    /**
     * @return string the PNG bytes
     *
     * @throws ValidationException
     */
    public static function decode(string $dataUri): string
    {
        $fail = fn () => ValidationException::withMessages(['drawing' => self::MESSAGE]);
        if (! str_starts_with($dataUri, self::PREFIX) || strlen($dataUri) > (int) ceil(self::MAX_BYTES * 4 / 3) + strlen(self::PREFIX) + 4) {
            throw $fail();
        }
        $png = base64_decode(substr($dataUri, strlen(self::PREFIX)), true);
        if ($png === false || strlen($png) > self::MAX_BYTES || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw $fail();
        }
        $size = @getimagesizefromstring($png);
        if ($size === false || $size[2] !== IMAGETYPE_PNG || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_WIDTH || $size[1] > self::MAX_HEIGHT) {
            throw $fail();
        }
        $image = @imagecreatefrompng('data://image/png;base64,'.base64_encode($png));
        if ($image === false || ! self::inked($image, $size[0], $size[1])) {
            throw $fail();
        }

        return $png;
    }

    /** At least MIN_INK pixels that are not (almost) transparent nor (almost) white, sampled on a grid. */
    private static function inked(\GdImage $image, int $width, int $height): bool
    {
        $step = max(1, intdiv(max($width, $height), 400));
        $ink = 0;
        $needed = max(5, intdiv(self::MIN_INK, $step * $step));
        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($c['alpha'] < 100 && ($c['red'] + $c['green'] + $c['blue']) < 600 && ++$ink >= $needed) {
                    return true;
                }
            }
        }

        return false;
    }
}
