<?php
declare(strict_types=1);

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-vgpu-manager/include/bootstrap.php';

use Vgpu\Source;
use Vgpu\Store;

$checks = 0;
function source_check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}

$cases = [
    ['https://github.com/owner/repo', 'github', 'repository', 'https://github.com/owner/repo', '', ''],
    ['https://github.com/owner/repo.git', 'github', 'repository', 'https://github.com/owner/repo', '', ''],
    ['https://github.com/owner/repo/releases', 'github', 'repository', 'https://github.com/owner/repo', '', ''],
    ['https://github.com/owner/repo/releases/tag/6.18.58-Unraid', 'github', 'release', 'https://github.com/owner/repo', '6.18.58-Unraid', ''],
    ['https://github.com/owner/repo/releases/download/6.18.58-Unraid/nvidia-595.58.03-6.18.58-Unraid-1.txz', 'github', 'direct', 'https://github.com/owner/repo', '6.18.58-Unraid', 'nvidia-595.58.03-6.18.58-Unraid-1.txz'],
    ['https://git.example.test/group/sub/project', 'gitlab', 'repository', 'https://git.example.test/group/sub/project', '', ''],
    ['https://git.example.test/group/project/-/releases', 'gitlab', 'repository', 'https://git.example.test/group/project', '', ''],
    ['https://git.example.test/group/project/-/releases/v6.18.58-595.58.03', 'gitlab', 'release', 'https://git.example.test/group/project', 'v6.18.58-595.58.03', ''],
    ['https://git.example.test/api/v4/projects/42/packages/generic/build/v6.18.58-595.58.03/nvidia-595.58.03-6.18.58-Unraid-1.txz', 'gitlab', 'direct', 'https://git.example.test/api/v4/projects/42', '', 'nvidia-595.58.03-6.18.58-Unraid-1.txz'],
    ['https://git.example.test/api/v4/projects/owner%2Frepo/packages/generic/build/v6.18.58-595.58.03/nvidia-595.58.03-6.18.58-Unraid-1.txz', 'gitlab', 'direct', 'https://git.example.test/owner/repo', '', 'nvidia-595.58.03-6.18.58-Unraid-1.txz'],
    ['https://files.example.test/drivers/nvidia-595.58.03-6.18.58-Unraid-1.txz', 'direct', 'direct', 'https://files.example.test/drivers/nvidia-595.58.03-6.18.58-Unraid-1.txz', '', 'nvidia-595.58.03-6.18.58-Unraid-1.txz'],
    ['https://files.example.test/nvidia-595.58.03-6.18.58-Unraid-1.txz', 'direct', 'direct', 'https://files.example.test/nvidia-595.58.03-6.18.58-Unraid-1.txz', '', 'nvidia-595.58.03-6.18.58-Unraid-1.txz'],
    ['https://api.github.com/repos/owner/repo/releases/tags/6.18.58-Unraid', 'github', 'release', 'https://github.com/owner/repo', '6.18.58-Unraid', ''],
];
foreach ($cases as [$url, $provider, $kind, $repository, $tag, $filename]) {
    $parsed = Source::parse($url);
    foreach (compact('provider', 'kind', 'repository', 'tag', 'filename') as $key => $expected) source_check($parsed[$key] === $expected, 'Source parser mismatch: ' . $key);
    $stored = Store::validateSource(['url' => $url, 'mode' => 'auto']);
    source_check($stored['repository'] === $repository && $stored['provider'] === $provider, 'Stored source identity must follow the pasted link');
}
foreach ([
    'http://github.com/owner/repo', 'https://user:secret@github.com/owner/repo',
    'https://github.com/owner/repo?token=secret', 'https://github.com/owner/repo#token',
    'https://github.com/owner/../repo', 'https://github.com/owner/%2e%2e',
    'https://files.example.test/path%2f../nvidia-595.58.03-6.18.58-Unraid-1.txz',
    'https://git.example.test/group/project/-/package_files/42/download',
    'https://files.example.test/drivers/other.txz',
] as $url) {
    $failed = false;
    try { Source::parse($url); } catch (InvalidArgumentException $error) { $failed = true; }
    source_check($failed, 'Unsafe or unidentifiable source must be rejected');
}
$a = Store::validateSource(['url' => 'https://github.com/one/repo']);
$b = Store::validateSource(['url' => 'https://github.com/two/repo']);
source_check(Store::sourceIdentity($a) !== Store::sourceIdentity($b), 'Changing the linked repository must change credential binding');
$legacy = Store::validateSource(['provider' => 'gitlab', 'mode' => 'generic', 'repository' => 'https://git.example.test/owner/repo', 'driver' => '595.58.03']);
source_check($legacy['mode'] === 'generic' && $legacy['url'] === $legacy['repository'], 'Legacy Generic configuration must remain valid');
source_check(Store::sourceIdentity($legacy) === hash('sha256', json_encode([
    'provider' => 'gitlab', 'repository' => 'https://git.example.test/owner/repo', 'project_id' => '',
], JSON_THROW_ON_ERROR)), 'Existing canonical credential identities must not change');
$changedUrl = $a; $changedUrl['url'] = 'https://github.com/two/repo';
source_check(Store::sourceIdentity($a) !== Store::sourceIdentity($changedUrl), 'A changed link must not retain the previous repository identity');
echo 'Source parsing: ' . $checks . " checks passed\n";
