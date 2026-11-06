<?php
declare(strict_types=1);

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';

use Vgpu\PackageIdentity;
use Vgpu\Source;
use Vgpu\Store;

$checks = 0;
function identity_check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}
function identity_reject(callable $operation): void
{
    try { $operation(); }
    catch (InvalidArgumentException $error) { identity_check(true, 'Rejected invalid identity'); return; }
    throw new RuntimeException('Expected identity validation failure');
}

// Contract with the driver builder: Release stays kernel-scoped, while Generic
// version paths and immutable filenames include revisions after build 1.
foreach (['580.159.03', '595.58.03'] as $driver) {
    foreach (['6.18.54', '6.18.58'] as $kernel) {
        foreach ([1, 2, 10] as $build) {
            $filename = "nvidia-$driver-$kernel-Unraid-$build.txz";
            $version = "v$kernel-$driver" . ($build === 1 ? '' : "-build$build");
            $identity = ['filename' => $filename, 'driver' => $driver, 'kernel' => "$kernel-Unraid", 'build' => $build];
            identity_check(PackageIdentity::parse($filename, $kernel) === $identity, 'Parse builder filename');
            identity_check(PackageIdentity::parse($filename, "$kernel-Unraid") === $identity, 'Accept full kernel identity');
            identity_check(PackageIdentity::filename($driver, "$kernel-Unraid", (string)$build) === $filename, 'Generate canonical filename');
            identity_check(PackageIdentity::genericVersion($kernel, $driver, $build) === $version, 'Match immutable Generic version');
            identity_check(PackageIdentity::receipt($identity) === $identity, 'Normalize complete receipt');
            foreach ([
                "https://github.com/owner/repo/releases/download/$kernel-Unraid/$filename",
                "https://git.example.test/api/v4/projects/42/packages/generic/build/$version/$filename",
                "https://files.example.test/drivers/$filename",
            ] as $url) {
                identity_check(Source::parse($url)['filename'] === $filename, 'All direct download routes accept numeric build');
            }
            $oldReceipt = $identity;
            unset($oldReceipt['build']);
            if ($build === 1) {
                identity_check(PackageIdentity::receipt($oldReceipt) === $identity, 'Legacy build-1 receipt remains usable');
            } else {
                identity_reject(fn() => PackageIdentity::receipt($oldReceipt));
            }
            identity_reject(fn() => PackageIdentity::receipt(array_replace($identity, ['build' => $build + 1])));
            identity_reject(fn() => PackageIdentity::receipt(array_replace($identity, ['driver' => '999.1.1'])));
            identity_reject(fn() => PackageIdentity::receipt(array_replace($identity, ['kernel' => '6.0.0-Unraid'])));
            identity_reject(fn() => PackageIdentity::parse($filename, '6.0.0'));
        }
    }
}

foreach (['', '0', '-1', '01', '+2', '2.0', "2\n", ' 2', '1e2', true, null, 2.0, [], (string)PHP_INT_MAX . '0'] as $bad) {
    identity_reject(fn() => PackageIdentity::build($bad));
}
identity_check(PackageIdentity::build(PHP_INT_MAX) === PHP_INT_MAX, 'Largest representable build is accepted');
foreach ([
    'nvidia-595.58.03-6.18.58-Unraid-0.txz',
    'nvidia-595.58.03-6.18.58-Unraid-02.txz',
    'nvidia-595.58.03-6.18.58-Unraid--1.txz',
    'nvidia-595.58.03-6.18.58-Unraid-1.txz.md5',
    'nvidia-r595-experimental-595.58.03-6.18.58-Unraid-2.txz',
    '../nvidia-595.58.03-6.18.58-Unraid-2.txz',
    "nvidia-595.58.03-6.18.58-Unraid-2.txz\n",
] as $bad) identity_reject(fn() => PackageIdentity::parse($bad));
identity_reject(fn() => PackageIdentity::receipt(['filename' => []]));
identity_reject(fn() => PackageIdentity::filename('../595.58.03', '6.18.58', 2));
identity_reject(fn() => PackageIdentity::filename('595.58.03', '../6.18.58', 2));

$source = Store::validateSource(['url' => 'https://git.example.test/group/project']);
identity_check($source['build'] === '', 'Old settings default to automatic build selection');
$pinned = Store::validateSource(array_replace($source, ['driver' => '595.58.03', 'build' => '2']));
identity_check($pinned['build'] === '2', 'Build pin is retained');
identity_check(Store::sourceIdentity($source) === Store::sourceIdentity($pinned), 'Build changes do not change repository credential binding');
identity_reject(fn() => Store::validateSource(array_replace($source, ['build' => '2'])));
foreach (['0', '02', 'latest', '-1', '1e2', (string)PHP_INT_MAX . '0'] as $bad) {
    identity_reject(fn() => Store::validateSource(array_replace($pinned, ['build' => $bad])));
}
$custom = Store::validateSource(array_replace($pinned, ['package_version_template' => 'v{kernel}-{driver}-build{build}']));
identity_check($custom['package_version_template'] === 'v{kernel}-{driver}-build{build}', 'Advanced Generic templates can include build');
identity_reject(fn() => Store::validateSource(array_replace($pinned, ['package_version_template' => '{revision}'])));
echo "Package identity: $checks checks passed\n";
