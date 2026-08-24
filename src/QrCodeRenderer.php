<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'chillerlan\\Settings\\' => __DIR__ . '/../vendor/chillerlan/php-settings-container/src/',
        'chillerlan\\QRCode\\' => __DIR__ . '/../vendor/chillerlan/php-qrcode/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

final class QrCodeRenderer
{
    public static function renderDataUri(string $data): string
    {
        $options = new \chillerlan\QRCode\QROptions([
            'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
            'imageBase64' => true,
            'eccLevel' => \chillerlan\QRCode\QRCode::ECC_M,
            'scale' => 10,
            'quietzoneSize' => 0,
            'svgAddXmlHeader' => true,
            'svgPreserveAspectRatio' => 'xMidYMid meet',
        ]);

        $qr = new \chillerlan\QRCode\QRCode($options);
        $rendered = $qr->render($data);

        if (is_string($rendered) && str_contains($rendered, 'data:image/svg+xml;base64,')) {
            return $rendered;
        }

        if (is_string($rendered) && str_starts_with($rendered, '<svg')) {
            return 'data:image/svg+xml;base64,' . base64_encode($rendered);
        }

        return '';
    }
}
