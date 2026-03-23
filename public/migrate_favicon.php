<?php
// One-time migration helper script to move the favicon from uploads into public/assets/images.
// Run from command line from project root: php public/migrate_favicon.php
chdir(__DIR__);

$src = __DIR__ . '/../uploads/tesp logo 1.png';
$dstDir = __DIR__ . '/assets/images';
$dst = $dstDir . '/tesp-logo-1.png';

if (!file_exists($src)) {
    echo "Source file not found: $src\n";
    exit(1);
}

if (!is_dir($dstDir)) {
    mkdir($dstDir, 0755, true);
}

if (copy($src, $dst)) {
    echo "Copied to $dst\n";
    // Optionally remove old file after copy if desired:
    // unlink($src);
    exit(0);
} else {
    echo "Failed to copy file\n";
    exit(1);
}
