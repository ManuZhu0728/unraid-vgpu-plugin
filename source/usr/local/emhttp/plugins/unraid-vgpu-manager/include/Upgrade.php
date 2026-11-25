<?php
declare(strict_types=1);

namespace Vgpu;

/** Prepare a kernel-specific package; installation belongs to the boot stage. */
final class Upgrade
{
    public const RETRY_SECONDS = 900;
    private $runner;
    private ?DownloadTask $task = null;

    public function __construct(private Store $store, private Download $download, ?callable $runner = null)
    {
        $this->runner = $runner ?? new Process();
    }

    public static function sourceFingerprint(array $source): string
    {
        $source = Store::validateSource($source);
        // Preserve receipts created before build selection was introduced.
        if (($source['build'] ?? '') === '') unset($source['build']);
        return hash('sha256', json_encode($source, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function status(): array
    {
        $file = $this->store->paths->configDir . '/upgrade-state.json';
        if (!is_file($file)) return ['status' => 'idle'];
        if (is_link($file) || filesize($file) > 131072) throw new \RuntimeException('升级状态文件无效。');
        $data = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('升级状态文件无效。');
        return $data;
    }

    /** Only the first release's kernel section is relevant, not older history. */
    public static function targetFromChanges(string $text): array
    {
        if (strlen($text) > 1048576 || str_contains($text, "\0")) throw new \RuntimeException('无法读取系统升级说明。');
        $text = str_replace("\r", '', $text);
        $headers = [];
        preg_match_all('/^\s*#{0,2}\s*(?:Unraid(?:\s+OS)?\s+)?Version\s+([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:[-.][A-Za-z0-9]+)*)\b[^\n]*$/mi', $text, $headers, PREG_OFFSET_CAPTURE);
        if (!$headers[0]) throw new \RuntimeException('系统升级说明缺少版本信息。');
        $start = $headers[0][0][1];
        $end = $headers[0][1][1] ?? strlen($text);
        $section = substr($text, $start, $end - $start);
        if (!preg_match('/^\s*#{0,4}\s*Linux kernel\s*:?\s*\n(?:(?!^\s*#{1,4}\s).|\n)*?^\s*[-*]?\s*version\s*:?\s*([0-9]+\.[0-9]+\.[0-9]+)(?:-Unraid)?\b/im', $section, $kernel)) {
            // Some release notes put the version on the section heading itself.
            if (!preg_match('/^\s*#{0,4}\s*Linux kernel\s*:?\s+([0-9]+\.[0-9]+\.[0-9]+)(?:-Unraid)?\b/im', $section, $kernel)) {
                throw new \RuntimeException('系统升级说明缺少目标内核版本。');
            }
        }
        return ['kernel' => self::kernel($kernel[1]), 'os' => $headers[1][0][0]];
    }

    public function check(array $settings, array $credentials, ?string $targetKernel = null, bool $force = false): array
    {
        if (($settings['upgrade']['enabled'] ?? false) !== true) {
            return ['ok' => true, 'status' => 'disabled', 'message' => '系统升级联动已关闭。'];
        }
        $previous = $this->status();
        $record = ['status' => 'error', 'last_attempt' => time(), 'notifications' => $previous['notifications'] ?? [],
            'credential_stamp' => $this->credentialStamp()];
        $token = is_string($credentials['token'] ?? null) ? $credentials['token'] : '';
        $taskStarted = false;
        $taskOutcome = 'completed';
        try {
            $current = self::kernel($this->read('/proc/sys/kernel/osrelease'));
            $targetError = null;
            if ($targetKernel !== null) {
                $target = ['kernel' => self::kernel($targetKernel), 'os' => ''];
            } else {
                try {
                    $target = self::targetFromChanges($this->read('/boot/changes.txt', 1048577));
                } catch (\Throwable $error) {
                    // Still establish the current-kernel recovery cache when
                    // upgrade notes are temporarily unavailable or incomplete.
                    $target = ['kernel' => $current, 'os' => ''];
                    $targetError = $error;
                }
            }
            $record += ['current_kernel' => $current, 'target_kernel' => $target['kernel'], 'target_os' => $target['os']];
            $source = Store::validateSource($settings['source'] ?? []);
            $identity = Store::sourceIdentity($source);
            if ($token !== '' && ($credentials['source_id'] ?? '') !== $identity) {
                throw new \RuntimeException('下载凭据与当前仓库不匹配，请重新保存 Token。');
            }
            $fingerprint = self::sourceFingerprint($source);
            [$branch, $reference] = $this->driverPolicy($settings, $source);
            $record += ['source_id' => $identity, 'source_fingerprint' => $fingerprint, 'driver_branch' => $branch,
                'reference_driver' => $reference, 'prepared_boot_id' => $this->bootId()];
            $same = ($previous['target_kernel'] ?? '') === $target['kernel'] &&
                ($previous['source_id'] ?? '') === $identity && ($previous['source_fingerprint'] ?? '') === $fingerprint && ($previous['driver_branch'] ?? '') === $branch &&
                ($previous['reference_driver'] ?? '') === $reference;
            if (!$force && $same && in_array($previous['status'] ?? '', ['cancelled', 'interrupted'], true) &&
                ($previous['credential_stamp'] ?? '') === $record['credential_stamp']) {
                return ['ok' => false, 'deferred' => true] + $previous;
            }
            $candidate = $this->baselineCandidate($settings, $source, $current, $branch, $previous, $record);
            $currentReady = $targetError === null && $target['kernel'] === $current && $candidate !== null;
            if (!$force && !$currentReady && $targetKernel === null && $same && ($previous['credential_stamp'] ?? '') === $record['credential_stamp'] &&
                in_array($previous['status'] ?? '', ['missing', 'error', 'downloading', 'staging'], true) &&
                (int)($previous['last_attempt'] ?? 0) + self::RETRY_SECONDS > time()) {
                return ['ok' => false, 'deferred' => true] + $previous;
            }

            if ($candidate === null || $target['kernel'] !== $current) {
                $this->task = new DownloadTask($this->store);
                $this->task->begin('upgrade', $current);
                $taskStarted = true;
                $this->download->track($this->task);
            }

            $baseline = $this->ensureBaseline($settings, $source, $credentials, $current, $branch, $reference, $candidate, $record);
            $record['baseline_package'] = $baseline['package'];
            if ($targetError !== null) throw $targetError;
            if ($target['kernel'] === $current) {
                // Retain only the matching ready receipt that Installer can use.
                $readyDriver = $previous['package']['driver'] ?? '';
                if ($same && ($previous['status'] ?? '') === 'ready' &&
                    is_string($readyDriver) && explode('.', $readyDriver)[0] === $branch &&
                    ($source['driver'] === '' || $readyDriver === $source['driver']) &&
                    $this->matchesBuild($previous['package'] ?? [], $source) &&
                    $this->cached($previous['package'] ?? [], $current)) {
                    if ($baseline['changed']) {
                        $previous['notifications'] = $record['notifications'];
                        $previous['baseline_package'] = $baseline['package'];
                        if (isset($record['history_warning'])) $previous['history_warning'] = $record['history_warning'];
                        $this->store->write($this->store->paths->configDir . '/upgrade-state.json', $previous);
                    }
                } elseif ($baseline['changed'] || !$same || ($previous['status'] ?? '') !== 'current') {
                    $record['status'] = 'current';
                    $record['phase'] = 'baseline';
                    $record['package'] = $baseline['package'];
                    $record['message'] = '当前内核驱动缓存已准备好。';
                    $this->store->write($this->store->paths->configDir . '/upgrade-state.json', $record);
                }
                return ['ok' => true, 'status' => 'current', 'current_kernel' => $current,
                    'target_kernel' => $current, 'baseline_prepared' => $baseline['changed'],
                    'package' => $baseline['package'], 'message' => '当前内核驱动缓存已就绪。'] + array_intersect_key($record, ['history_warning' => true]);
            }
            if ($same && ($previous['status'] ?? '') === 'ready' && $this->matchesBuild($previous['package'] ?? [], $source) && $this->cached($previous['package'] ?? [], $target['kernel'])) {
                if ($baseline['changed']) {
                    $previous['notifications'] = $record['notifications'];
                    $previous['baseline_package'] = $baseline['package'];
                    if (isset($record['history_warning'])) $previous['history_warning'] = $record['history_warning'];
                    $this->store->write($this->store->paths->configDir . '/upgrade-state.json', $previous);
                }
                // Cache readiness does not prove that the startup copy is intact.
                $record = $previous;
                $record['boot_ready'] = false;
                return ['ok' => true, 'unchanged' => true] + $this->finishPreparation($record, $settings);
            }

            $this->requireSource($source);
            $effective = $this->targetSource($source, $target['kernel'], $reference);
            $this->task?->stage('resolving', ['scope' => 'target', 'kernel' => $target['kernel'], 'filename' => '']);
            $record['status'] = 'downloading';
            $record['phase'] = 'target';
            $record['message'] = '正在准备 ' . $target['kernel'] . ' 的驱动包。';
            $record = $this->saveAndNotify($record, 'start', '正在准备新内核驱动', $record['message']);
            $package = $this->fetchKernelPackage($effective, $credentials, $target['kernel'], $branch, $reference, $source['driver']);
            $package = $this->receipt($package, $source, $record['prepared_boot_id']);
            $record['package'] = $package;
            $record = $this->finishPreparation($record, $settings);
            return ['ok' => true] + $record;
        } catch (\Throwable $error) {
            $taskOutcome = $error instanceof DownloadCancelled ? 'cancelled' : 'failed';
            $message = self::redact($error->getMessage(), $token);
            if (!($error instanceof DownloadCancelled) && $taskStarted && $this->task->cancellationRequested()) {
                $taskOutcome = 'interrupted';
                $record['status'] = 'interrupted';
                $record['message'] = '取消结果未确认，自动重试已暂停。请先核对下载进度及缓存，再决定是否重新准备。';
                $this->store->write($this->store->paths->configDir . '/upgrade-state.json', $record);
                return ['ok' => false] + $record;
            }
            if ($error instanceof DownloadCancelled) {
                $record['status'] = 'cancelled';
                $record['message'] = '驱动准备已手动取消，不会自动重新下载。可点击“继续准备”重新开始。';
                $this->store->write($this->store->paths->configDir . '/upgrade-state.json', $record);
                return ['ok' => false] + $record;
            }
            $missing = $error->getCode() === 404 || str_contains($message, 'HTTP 404') || str_contains($message, '未找到') ||
                str_contains($message, 'No canonical') || str_contains($message, 'No driver package matches') ||
                str_contains($message, 'selected driver is unavailable');
            $record['status'] = $missing ? 'missing' : 'error';
            if (($record['phase'] ?? '') === 'boot') $record['boot_ready'] = false;
            $record['message'] = $message;
            $record = $this->saveAndNotify($record, 'error', $missing ? '目标内核驱动暂未发布' : '驱动准备失败', $message);
            return ['ok' => false] + $record;
        } finally {
            if ($this->task !== null) {
                try { if ($taskStarted) $this->task->finish($taskOutcome); }
                finally { $this->download->track(null); $this->task->close(); $this->task = null; }
            }
        }
    }

    /** Caller holds the transaction lock shared by hook, manual and timed checks. */
    private function finishPreparation(array &$record, array $settings): array
    {
        $this->task?->stage('publishing', ['scope' => 'target', 'kernel' => $record['target_kernel']], false);
        $record['status'] = 'staging';
        $record['phase'] = 'boot';
        $record['boot_ready'] = false;
        $record['message'] = '正在核验目标内核的启动安装包。';
        $this->store->write($this->store->paths->configDir . '/upgrade-state.json', $record);
        // Preparing the baseline may have changed the current-kernel selection.
        $settings['selected_package'] = $this->store->settings()['selected_package'];
        $native = new NativeBoot($this->store, $this->download);
        $native->sync($settings, array_replace($record, ['status' => 'ready']));
        if (($settings['driver']['enabled'] ?? false) === true) {
            $selection = $native->selection($record['target_kernel']);
            if ($selection === null) throw new \RuntimeException('目标内核启动包尚未选中。');
            $published = $native->verifyPublished($selection);
            foreach (['filename', 'kernel', 'driver', 'build', 'md5'] as $field) {
                if (($published[$field] ?? null) !== ($record['package'][$field] ?? null)) {
                    throw new \RuntimeException('目标内核启动包与升级准备的构建不一致。');
                }
            }
            $record['boot_ready'] = true;
        }
        $record['status'] = 'ready';
        $record['phase'] = 'target';
        $record['message'] = $record['target_kernel'] . ' · NVIDIA ' . $record['package']['driver'] .
            ' · 构建 ' . $record['package']['build'] . ($record['boot_ready']
                ? ' 缓存及唯一启动安装包均已校验。请同时确认 Unraid 系统更新完成。'
                : ' 缓存已校验；开机恢复关闭，未准备启动安装包。');
        return $this->saveAndNotify($record, $record['boot_ready'] ? 'ready' : 'cached',
            $record['boot_ready'] ? '新内核驱动已准备' : '新内核驱动已缓存（未启用开机恢复）', $record['message']);
    }

    private function baselineCandidate(array $settings, array $source, string $current, string $branch, array $prepared, array $record): ?array
    {
        $candidates = [];
        if (is_array($settings['selected_package'] ?? null)) $candidates[] = $settings['selected_package'];
        if (($prepared['status'] ?? '') === 'ready' && ($prepared['target_kernel'] ?? '') === $current && is_array($prepared['package'] ?? null)) {
            $candidates[] = array_replace($prepared['package'], [
                'saved_boot_id' => $prepared['prepared_boot_id'] ?? '',
                'source_id' => $prepared['source_id'] ?? '',
                'source_fingerprint' => $prepared['source_fingerprint'] ?? '',
            ]);
        }
        $driverState = $this->store->paths->configDir . '/driver-state.json';
        if (is_file($driverState) || is_link($driverState)) {
            if (is_link($driverState) || !is_file($driverState) || filesize($driverState) > 65536) throw new \RuntimeException('驱动安装记录无效。');
            $state = json_decode((string)file_get_contents($driverState), true, 32, JSON_THROW_ON_ERROR);
            $last = is_array($state) ? ($state['last_success'] ?? null) : null;
            if (is_array($last) && is_array($last['package'] ?? null)) {
                $package = array_replace($last['package'], array_intersect_key($last, array_flip(['saved_boot_id', 'source_id', 'source_fingerprint'])));
                if (($last['local'] ?? false) === true) $package['source'] = 'local';
                $candidates[] = $package;
            }
        }
        $policy = $settings;
        $policy['source'] = $source;
        $policy['upgrade']['driver_branch'] = $branch;
        foreach ($candidates as $package) {
            $found = $this->baselinePackage($package, $policy, $current, $record);
            if ($found !== null) return $found;
        }
        $historical = (new PackageHistory($this->store))->read($current);
        if ($historical === null) return null;
        return $this->baselinePackage(array_replace($historical['package'], array_intersect_key($historical,
            array_flip(['saved_boot_id', 'source_id', 'source_fingerprint']))), $policy, $current, $record);
    }

    private function baselinePackage(array $package, array $policy, string $current, array $record): ?array
    {
        try { PackageHistory::assertPolicy(PackageHistory::fromPackage($package), $policy, $current); }
        catch (\Throwable) { return null; }
        if (!$this->cached($package, $current)) return null;
        if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', (string)($package['saved_boot_id'] ?? ''))) {
            $package['saved_boot_id'] = $record['prepared_boot_id'];
        }
        return $this->receipt($package, $policy['source'], $package['saved_boot_id']);
    }

    private function ensureBaseline(array $settings, array $source, array $credentials, string $current, string $branch, string $reference, ?array $candidate, array &$record): array
    {
        if ($candidate !== null) {
            if (($settings['selected_package'] ?? null) === $candidate) return ['package' => $candidate, 'changed' => false];
            $this->rememberSelection($settings, $source, $branch, $record);
            $settings['selected_package'] = $candidate;
            $this->store->saveSettings($settings);
            return ['package' => $candidate, 'changed' => true];
        }
        $this->requireSource($source);
        $effective = $this->targetSource($source, $current, $reference);
        $this->task?->stage('resolving', ['scope' => 'baseline', 'kernel' => $current, 'filename' => '']);
        $record['status'] = 'downloading';
        $record['phase'] = 'baseline';
        $record['message'] = '正在缓存当前内核 ' . $current . ' 的驱动包。';
        $record = $this->saveAndNotify($record, 'baseline-start', '正在准备当前驱动缓存', $record['message']);
        $package = $this->fetchKernelPackage($effective, $credentials, $current, $branch, $reference, $source['driver']);
        $package = $this->receipt($package, $source, $record['prepared_boot_id']);
        $this->rememberSelection($settings, $source, $branch, $record);
        $settings['selected_package'] = $package;
        $this->store->saveSettings($settings);
        $record['baseline_package'] = $package;
        $record['message'] = $current . ' · NVIDIA ' . $package['driver'] . ' 当前驱动缓存已准备好。';
        $record = $this->saveAndNotify($record, 'baseline-ready', '当前驱动缓存已准备', $record['message']);
        return ['package' => $package, 'changed' => true];
    }

    private function rememberSelection(array $settings, array $source, string $branch, array &$record): void
    {
        if (!is_array($settings['selected_package'] ?? null)) return;
        try {
            $candidate = PackageHistory::authorization(PackageHistory::fromPackage($settings['selected_package']));
            $kernel = $candidate['package']['kernel'];
            $policy = $settings;
            $policy['source'] = $source;
            $policy['upgrade']['driver_branch'] = $branch;
            PackageHistory::assertPolicy($candidate, $policy, $kernel);
            if (!PackageHistory::cached($this->store->paths, $candidate['package'], $kernel)) throw new \RuntimeException('Previous cache is invalid');
            (new PackageHistory($this->store))->remember($candidate);
        } catch (\Throwable) {
            // An obsolete or damaged selection must not block preparation of a
            // valid new baseline, nor silently gain rollback authorization.
            $record['history_warning'] = 'Previous selected package could not be retained as an authorized rollback receipt';
        }
    }

    private function fetchKernelPackage(array $source, array $credentials, string $kernel, string $branch, string $reference, string $pin): array
    {
        // targetSource may derive an exact version from a direct package link.
        // That pin remains authoritative after the link is retargeted to a Release.
        if (($source['driver'] ?? '') !== '') $pin = $source['driver'];
        $versions = $this->download->versions($source, $credentials, $kernel);
        $matches = [];
        foreach ($versions['versions'] ?? [] as $candidate) {
            $driver = $candidate['driver'] ?? '';
            if (!is_string($driver) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $driver) || explode('.', $driver)[0] !== $branch) continue;
            if ($pin !== '' && $driver !== $pin) continue;
            try { $identity = PackageIdentity::receipt($candidate); }
            catch (\InvalidArgumentException) { continue; }
            if ($identity['kernel'] !== $kernel || !$this->matchesBuild($identity, $source)) continue;
            $matches[$driver . '/' . $identity['build']] = $identity;
        }
        if (!$matches) throw new \RuntimeException('未找到 ' . $kernel . ' 对应的 R' . $branch . ' 驱动包。');
        usort($matches, static fn(array $left, array $right): int =>
            version_compare($left['driver'], $right['driver']) ?: $left['build'] <=> $right['build']);
        $preferred = array_values(array_filter($matches, static fn(array $candidate): bool => $candidate['driver'] === $reference));
        $selected = $preferred ? $preferred[array_key_last($preferred)] : $matches[array_key_last($matches)];
        $source['driver'] = $selected['driver'];
        $source['build'] = (string)$selected['build'];
        $downloaded = $this->download->fetch($source, $credentials, $kernel);
        $package = $downloaded['package'] ?? null;
        if (($downloaded['ok'] ?? false) !== true || !is_array($package) ||
            ($package['kernel'] ?? '') !== $kernel || ($package['driver'] ?? '') !== $source['driver'] ||
            !$this->matchesBuild($package, $source) || !$this->cached($package, $kernel)) {
            throw new \RuntimeException('内核驱动包的校验结果不完整。');
        }
        return $package;
    }

    private function receipt(array $package, array $source, string $boot): array
    {
        $package = PackageIdentity::receipt($package);
        $package['source'] = ($package['source'] ?? '') === 'local' ? 'local' : $source['repository'];
        $package['provider'] = $package['source'] === 'local' ? 'local' : $source['provider'];
        $package['source_id'] = Store::sourceIdentity($source);
        $package['source_fingerprint'] = self::sourceFingerprint($source);
        $package['saved_boot_id'] = $boot;
        return $package;
    }

    private function requireSource(array $source): void
    {
        if ($source['repository'] === '' && $source['url'] === '') throw new \RuntimeException('请先保存驱动下载地址，以准备驱动缓存。');
    }

    private function driverPolicy(array $settings, array $source): array
    {
        $installed = $this->read('/sys/module/nvidia/version');
        $selected = $settings['selected_package']['driver'] ?? '';
        $reference = $source['driver'] !== '' ? $source['driver'] :
            (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $installed) ? $installed : $selected);
        if (!is_string($reference) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $reference)) {
            $link = Source::parse($source['url'], $source['provider']);
            if (!preg_match('/^nvidia-([0-9]+\.[0-9]+\.[0-9]+)-/', $link['filename'] ?? '', $match)) {
                throw new \RuntimeException('请先选择驱动版本，再开启系统升级联动。');
            }
            $reference = $match[1];
        }
        $saved = $settings['upgrade']['driver_branch'] ?? '';
        if (!is_string($saved) || ($saved !== '' && !preg_match('/^[0-9]+$/D', $saved))) throw new \RuntimeException('驱动分支设置无效。');
        if ($source['driver'] === '' && $saved !== '' && explode('.', $reference)[0] !== $saved) {
            throw new \RuntimeException('当前驱动 R' . explode('.', $reference)[0] . ' 与已保存的更新分支 R' . $saved . ' 不一致，请关闭并重新开启自动更新以确认分支。');
        }
        $branch = $source['driver'] !== '' || $saved === '' ? explode('.', $reference)[0] : $saved;
        return [$branch, $reference];
    }

    private function targetSource(array $source, string $kernel, string $reference): array
    {
        $link = Source::parse($source['url'], $source['provider']);
        $identity = $link['kind'] === 'direct' ? PackageIdentity::parse($link['filename']) : null;
        if ($identity !== null) {
            if (($source['driver'] !== '' && $source['driver'] !== $identity['driver']) ||
                (($source['build'] ?? '') !== '' && PackageIdentity::build($source['build']) !== $identity['build'])) {
                throw new \RuntimeException('固定文件链接与所选驱动版本或构建号不一致。');
            }
            $source['driver'] = $identity['driver'];
            $source['build'] = (string)$identity['build'];
            if ($identity['kernel'] === $kernel) return $source;
        }
        if ($link['kind'] === 'release' || ($link['kind'] === 'direct' && $link['provider'] === 'github')) {
            $source['url'] = $source['repository'];
            $source['mode'] = 'auto';
        } elseif ($link['kind'] === 'direct') {
            $pattern = '~^(.*/packages/generic/[^/]+/)(v?)([^/]+)/[^/]+$~D';
            if ($link['provider'] !== 'gitlab' || !preg_match($pattern, $source['url'], $match)) {
                throw new \RuntimeException('固定文件链接无法自动更新，请使用仓库地址。');
            }
            $oldVersion = PackageIdentity::genericVersion($identity['kernel'], $identity['driver'], $identity['build']);
            if ($match[3] !== substr($oldVersion, 1)) throw new \RuntimeException('固定文件链接无法自动更新，请使用仓库地址。');
            $version = PackageIdentity::genericVersion($kernel, $identity['driver'], $identity['build']);
            $source['url'] = $match[1] . $match[2] . substr($version, 1) . '/' . PackageIdentity::filename($identity['driver'], $kernel, $identity['build']);
        }
        if ($source['mode'] === 'generic' && $source['driver'] === '') $source['driver'] = $reference;
        return Store::validateSource($source);
    }

    private function cached(array $package, string $kernel): bool
    {
        return PackageHistory::cached($this->store->paths, $package, $kernel);
    }

    private function matchesBuild(array $package, array $source): bool
    {
        try {
            $identity = PackageIdentity::receipt($package);
            return ($source['build'] ?? '') === '' || PackageIdentity::build($source['build']) === $identity['build'];
        } catch (\InvalidArgumentException) { return false; }
    }

    private function saveAndNotify(array $record, string $phase, string $title, string $message): array
    {
        $file = $this->store->paths->configDir . '/upgrade-state.json';
        $key = hash('sha256', json_encode([$phase, $record['target_kernel'] ?? '', $record['source_fingerprint'] ?? '', $message], JSON_THROW_ON_ERROR));
        $history = is_array($record['notifications'] ?? null) ? $record['notifications'] : [];
        // Publish the verified state before announcing it. A failed state write
        // must never produce a readiness notification with no durable receipt.
        $this->store->write($file, $record);
        if (!isset($history[$key])) {
            try {
                $result = ($this->runner)(['/usr/local/emhttp/plugins/dynamix/scripts/notify', '-e', 'NVIDIA vGPU Manager',
                    '-s', $title, '-d', $message, '-i', $phase === 'error' ? 'alert' : 'normal', '-l', '/Settings/unraid-vgpu-manager'], 10, null);
                if (($result['code'] ?? -1) === 0) $history[$key] = time();
            } catch (\Throwable $ignored) {}
        }
        if (count($history) > 16) $history = array_slice($history, -16, null, true);
        $record['notifications'] = $history;
        $this->store->write($file, $record);
        return $record;
    }

    private static function redact(string $message, string $token): string
    {
        if ($token !== '') $message = str_replace($token, '[redacted]', $message);
        $message = preg_replace('/\b(?:glpat-|github_pat_|gh[pousr]_)[A-Za-z0-9_.-]+/', '[redacted]', $message);
        $message = preg_replace('/[\x00-\x1f\x7f]/', ' ', $message);
        return substr($message, 0, 512);
    }

    private static function kernel(string $value): string
    {
        if (!preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)(?:-Unraid)?$/D', $value, $match)) throw new \RuntimeException('目标内核版本无效。');
        return $match[1] . '-Unraid';
    }

    private function bootId(): string
    {
        $value = $this->read('/proc/sys/kernel/random/boot_id');
        if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $value)) throw new \RuntimeException('无法识别本次启动。');
        return $value;
    }

    private function credentialStamp(): string
    {
        clearstatcache(true, $this->store->paths->credentialsFile);
        $stat = @stat($this->store->paths->credentialsFile);
        return hash('sha256', json_encode($stat === false ? [] : [$stat['ino'], $stat['mtime'], $stat['size']], JSON_THROW_ON_ERROR));
    }

    private function read(string $path, int $limit = 65536): string
    {
        $file = $this->store->paths->path($path);
        if (!is_file($file)) return '';
        $value = file_get_contents($file, false, null, 0, $limit);
        return $value === false ? '' : trim($value);
    }
}
