<?php
declare(strict_types=1);

namespace Vgpu;

/** Turns a repository, release page or package link into a download source. */
final class Source
{
    public static function parse(string $value, string $provider = 'auto'): array
    {
        $value = trim($value);
        $parts = parse_url($value);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            throw new \InvalidArgumentException('Use an HTTPS link without credentials or query parameters');
        }
        if (!in_array($provider, ['auto', 'github', 'gitlab', 'direct'], true)) throw new \InvalidArgumentException('Unsupported source provider');
        $host = strtolower($parts['host']);
        $origin = 'https://' . $host . (isset($parts['port']) && $parts['port'] !== 443 ? ':' . $parts['port'] : '');
        $path = rtrim($parts['path'] ?? '', '/');
        if ($path === '' || str_contains($path, '//')) throw new \InvalidArgumentException('Paste a repository, release or TXZ link');
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..' || preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) throw new \InvalidArgumentException('Invalid source path');
        }
        $url = $origin . $path;
        $result = ['url' => $url, 'provider' => '', 'repository' => '', 'kind' => 'repository', 'tag' => '', 'filename' => ''];

        // Generic links also work with project deploy tokens, without listing the API.
        if (preg_match('~^/api/v4/projects/([^/]+)/packages/generic/[^/]+/[^/]+/([^/]+)$~D', $path, $match)) {
            $project = rawurldecode($match[1]);
            if (!preg_match('~^(?:[1-9][0-9]*|[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)+)$~D', $project) || str_contains($project, '..')) throw new \InvalidArgumentException('Invalid GitLab project');
            $result['provider'] = 'gitlab';
            $result['repository'] = preg_match('/^[0-9]+$/D', $project) ? $origin . '/api/v4/projects/' . $project : $origin . '/' . $project;
            $result['api'] = $origin . '/api/v4/projects/' . rawurlencode($project);
            $result['kind'] = 'direct';
            $result['filename'] = rawurldecode($match[2]);
            return self::package($result);
        }
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if (str_contains(rawurldecode($segment), '/')) throw new \InvalidArgumentException('Invalid encoded source path');
        }

        $github = $provider === 'github' || in_array($host, ['github.com', 'api.github.com'], true) ||
            ($provider === 'auto' && preg_match('~^/[^/]+/[^/]+/releases/(?:tag|download)/~', $path));
        if ($github) {
            $apiHost = $host === 'api.github.com';
            $pattern = $apiHost ? '~^/repos/([^/]+/[^/]+)(.*)$~D' : '~^/([^/]+/[^/]+)(.*)$~D';
            if (!preg_match($pattern, $path, $match)) throw new \InvalidArgumentException('Invalid GitHub repository link');
            $project = preg_replace('/\.git$/', '', $match[1]);
            self::project($project);
            $tail = $match[2];
            $webOrigin = $host === 'api.github.com' ? 'https://github.com' : $origin;
            $result['provider'] = 'github';
            $result['repository'] = $webOrigin . '/' . $project;
            $result['api'] = in_array($host, ['github.com', 'api.github.com'], true) && (!isset($parts['port']) || $parts['port'] === 443)
                ? 'https://api.github.com/repos/' . $project : $origin . '/api/v3/repos/' . $project;
            if ($tail === '' || $tail === '/releases' || $tail === '/releases/latest') return $result;
            if (preg_match('~^/releases/tags?/([^/]+)$~D', $tail, $tag)) {
                $result['kind'] = 'release'; $result['tag'] = self::tag(rawurldecode($tag[1])); return $result;
            }
            if (preg_match('~^/releases/download/([^/]+)/([^/]+)$~D', $tail, $asset)) {
                $result['kind'] = 'direct'; $result['tag'] = self::tag(rawurldecode($asset[1]));
                $result['filename'] = rawurldecode($asset[2]); return self::package($result);
            }
            throw new \InvalidArgumentException('Use the GitHub repository, release page or TXZ download link');
        }

        if (preg_match('~^/(.+?)/-/releases(?:/(.*))?$~D', $path, $match)) {
            self::project($match[1]);
            $result['provider'] = 'gitlab'; $result['repository'] = $origin . '/' . $match[1];
            $tail = $match[2] ?? '';
            if ($tail === '' || $tail === 'permalink/latest') return $result;
            $result['kind'] = 'release'; $result['tag'] = self::tag(rawurldecode($tail)); return $result;
        }
        if (preg_match('~^/(.+?)/-/package_files/[0-9]+/download$~D', $path)) {
            throw new \InvalidArgumentException('Use the Generic Package API download link ending in the TXZ filename');
        }
        if (preg_match('~^/(?:.*/)?([^/]+\.txz)$~D', $path, $match)) {
            $result['provider'] = $provider === 'gitlab' ? 'gitlab' : 'direct';
            $result['repository'] = $origin . $path;
            $result['kind'] = 'direct'; $result['filename'] = rawurldecode($match[1]); return self::package($result);
        }
        $project = preg_replace('/\.git$/', '', ltrim($path, '/'));
        self::project($project);
        $result['provider'] = 'gitlab'; $result['repository'] = $origin . '/' . $project;
        return $result;
    }

    private static function project(string $project): void
    {
        if (!preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)+$~D', $project) || str_contains($project, '..')) throw new \InvalidArgumentException('Invalid repository path');
    }

    private static function tag(string $tag): string
    {
        if ($tag === '' || strlen($tag) > 200 || !preg_match('~^[A-Za-z0-9_.+-]+$~D', $tag)) throw new \InvalidArgumentException('Invalid release tag');
        return $tag;
    }

    private static function package(array $result): array
    {
        if (!preg_match('/^nvidia-[0-9]+\.[0-9]+\.[0-9]+-[0-9]+\.[0-9]+\.[0-9]+-Unraid-1\.txz$/D', $result['filename'])) throw new \InvalidArgumentException('Use a driver TXZ link, for example nvidia-595.58.03-6.18.58-Unraid-1.txz');
        return $result;
    }
}
