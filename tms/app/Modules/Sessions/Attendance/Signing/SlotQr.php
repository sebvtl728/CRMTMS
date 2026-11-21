<?php

namespace App\Modules\Sessions\Attendance\Signing;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** The QR code of a public signing link, an inline SVG rendered on the server (no external service). */
final class SlotQr
{
    public static function svg(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(320, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(17, 24, 39))),
            new SvgImageBackEnd,
        )))->writeString($url);

        return trim(substr($svg, (int) strpos($svg, "\n") + 1)); // without the XML declaration
    }

    public static function publicUrl(string $token): string
    {
        return url('/emargement/'.$token);
    }
}
