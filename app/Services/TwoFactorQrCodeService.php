<?php

namespace App\Services;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class TwoFactorQrCodeService
{
    /**
     * Generate an SVG string for the given otpauth URL (e.g. from Google2FA::getQRCodeUrl).
     */
    public function svg(string $otpauthUrl, int $size = 200): string
    {
        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle($size),
                new SvgImageBackEnd
            )
        );

        return $writer->writeString($otpauthUrl);
    }
}
