<?php
declare(strict_types=1);

namespace Vgpu;

/** Bounded argv execution. Never interpolate a command into a shell. */
final class Process
{
    public function __invoke(array $argv, int $timeout = 15, ?string $stdin = null): array
    {
        if (!$argv || $timeout < 1 || $timeout > 1800) {
            throw new \InvalidArgumentException('Invalid command or timeout');
        }
        foreach ($argv as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('Invalid command argument');
            }
        }
        $pipes = [];
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start command');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $input = $stdin ?? '';
        $offset = 0;
        $output = ['', ''];
        $deadline = microtime(true) + $timeout;
        $expired = false;
        $status = ['running' => true, 'exitcode' => -1];
        while (true) {
            if (is_resource($pipes[0])) {
                if ($offset < strlen($input)) {
                    $written = fwrite($pipes[0], substr($input, $offset, 65536));
                    if ($written !== false) {
                        $offset += $written;
                    }
                }
                if ($offset >= strlen($input)) {
                    fclose($pipes[0]);
                }
            }
            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index]);
                if ($chunk !== false && strlen($output[$index - 1]) < 2097152) {
                    $output[$index - 1] .= substr($chunk, 0, 2097152 - strlen($output[$index - 1]));
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $expired = true;
                // Never escalate to SIGKILL: GPU requests may be in an unkillable state.
                proc_terminate($process, 15);
                break;
            }
            usleep(20000);
        }
        foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if ($chunk !== false) {
                $output[$index - 1] .= substr($chunk, 0, max(0, 2097152 - strlen($output[$index - 1])));
            }
            fclose($pipes[$index]);
        }
        if (is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        if ($expired) {
            // proc_close can block on a kernel-stuck child. Do not call it here.
            // Callers treat a timeout as failure and must not continue a transaction.
            return ['code' => 124, 'stdout' => $output[0], 'stderr' => 'Command timed out; completion is unknown'];
        }
        $closed = proc_close($process);
        $code = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        return ['code' => $code, 'stdout' => $output[0], 'stderr' => $output[1]];
    }
}
