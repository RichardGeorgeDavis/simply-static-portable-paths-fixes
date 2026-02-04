<?php

declare(strict_types=1);

const SSPP_FIXES_VERSION = '0.2.0';

function sspp_fix_list_sites(string $sites_dir): array {
    if (!is_dir($sites_dir)) {
        return [];
    }

    $items = scandir($sites_dir);
    if ($items === false) {
        return [];
    }

    $sites = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $sites_dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $sites[] = $item;
        }
    }

    sort($sites, SORT_NATURAL | SORT_FLAG_CASE);
    return $sites;
}

function sspp_fix_run(string $site_path, array $options): array {
    $extensions = $options['extensions'] ?? ['html', 'htm', 'css', 'js', 'json', 'xml', 'txt', 'svg'];
    $apply = (bool)($options['apply'] ?? false);
    $rewrite_hosts = (bool)($options['rewrite_hosts'] ?? true);

    $stats = [
        'files_scanned' => 0,
        'files_changed' => 0,
        'bytes_before' => 0,
        'bytes_after' => 0,
        'replacements' => 0,
        'changed_files' => [],
        'missing_urls_total' => 0,
        'missing_url_hits' => 0,
        'missing_urls' => [],
        'absolute_urls_total' => 0,
        'absolute_url_hits' => 0,
        'absolute_urls' => [],
        'run_version' => 0,
        'run_version_updated' => false,
        'errors' => [],
    ];

    if (!is_dir($site_path)) {
        $stats['errors'][] = "Site path does not exist: {$site_path}";
        return $stats;
    }

    $internal_hosts = sspp_fix_discover_internal_hosts($site_path, $extensions);
    $rewrite_hosts_list = $rewrite_hosts ? $internal_hosts : [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($site_path, FilesystemIterator::SKIP_DOTS)
    );

    $missing = [];
    $missing_hits = 0;
    $exists_cache = [];
    $absolute_candidates = [];

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $extensions, true)) {
            continue;
        }

        $path = $file->getPathname();
        $stats['files_scanned']++;

        $content = file_get_contents($path);
        if ($content === false) {
            $stats['errors'][] = "Failed to read: {$path}";
            continue;
        }

        if (strpos($content, "\0") !== false) {
            // Skip binary content.
            continue;
        }

        $prefix = sspp_fix_prefix_for_file($site_path, $path);

        $counts = 0;
        $new_content = sspp_fix_rewrite_content(
            $content,
            $prefix,
            $rewrite_hosts_list,
            $counts
        );

        $stats['bytes_before'] += strlen($content);
        $stats['bytes_after'] += strlen($new_content);
        $stats['replacements'] += $counts;

        $current_file = sspp_fix_relative_path($site_path, $path);
        $found_paths = sspp_fix_extract_asset_paths($new_content);
        foreach ($found_paths as $relative_path) {
            $resolved = sspp_fix_resolve_path(dirname($path), $relative_path);
            if (!sspp_fix_reference_exists($resolved, $exists_cache)) {
                $relative_from_site = sspp_fix_relative_path($site_path, $resolved);
                sspp_fix_record_missing($missing, $relative_from_site, $current_file, $missing_hits);
            }
        }

        $root_paths = sspp_fix_extract_root_paths($new_content);
        foreach ($root_paths as $root_path) {
            $resolved = sspp_fix_resolve_path($site_path, $root_path);
            if (!sspp_fix_reference_exists($resolved, $exists_cache)) {
                $relative_from_site = sspp_fix_relative_path($site_path, $resolved);
                sspp_fix_record_missing($missing, $relative_from_site, $current_file, $missing_hits);
            }
        }

        $absolute_urls = sspp_fix_extract_absolute_urls($new_content);
        foreach ($absolute_urls as $absolute) {
            $host = $absolute['host'] ?? '';
            if ($host === '') {
                continue;
            }
            $path_value = $absolute['path'] ?? '/';
            $absolute_candidates[] = [
                'host' => $host,
                'path' => $path_value,
                'display' => $absolute['display'] ?? ($host . $path_value),
                'file' => $current_file,
            ];
        }

        if ($new_content !== $content) {
            $stats['files_changed']++;
            $stats['changed_files'][] = sspp_fix_relative_path($site_path, $path);

            if ($apply) {
                $result = file_put_contents($path, $new_content);
                if ($result === false) {
                    $stats['errors'][] = "Failed to write: {$path}";
                }
            }
        }
    }

    if (!empty($missing)) {
        uasort($missing, function (array $a, array $b): int {
            return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
        });
    }

    $absolute = [];
    $absolute_hits = 0;
    foreach ($absolute_candidates as $candidate) {
        $host = $candidate['host'];
        $base_host = sspp_fix_host_base($host);
        if (!isset($internal_hosts[$host]) && !isset($internal_hosts[$base_host])) {
            continue;
        }

        $absolute_key = $host . ($candidate['path'] ?? '');
        if (!isset($absolute[$absolute_key])) {
            $absolute[$absolute_key] = [
                'count' => 0,
                'files' => [],
                'url' => $candidate['display'] ?? $absolute_key,
            ];
        }
        $absolute[$absolute_key]['count']++;
        if (count($absolute[$absolute_key]['files']) < 5) {
            $absolute[$absolute_key]['files'][] = $candidate['file'];
        }
        $absolute_hits++;

        $resolved = sspp_fix_resolve_path($site_path, $candidate['path'] ?? '/');
        if (!sspp_fix_reference_exists($resolved, $exists_cache)) {
            $relative_from_site = sspp_fix_relative_path($site_path, $resolved);
            sspp_fix_record_missing($missing, $relative_from_site, $candidate['file'], $missing_hits);
        }
    }

    if (!empty($absolute)) {
        uasort($absolute, function (array $a, array $b): int {
            return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
        });
    }

    $stats['missing_urls_total'] = count($missing);
    $stats['missing_url_hits'] = $missing_hits;
    $stats['missing_urls'] = $missing;
    $stats['absolute_urls_total'] = count($absolute);
    $stats['absolute_url_hits'] = $absolute_hits;
    $stats['absolute_urls'] = $absolute;
    $stats['run_version'] = sspp_fix_read_run_version();
    if ($apply && $stats['files_changed'] > 0) {
        $stats['run_version'] = sspp_fix_bump_run_version();
        $stats['run_version_updated'] = true;
    }

    return $stats;
}

function sspp_fix_relative_path(string $root, string $path): string {
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);

    if (strpos($path, $root . '/') === 0) {
        return substr($path, strlen($root) + 1);
    }

    return $path;
}

function sspp_fix_record_missing(array &$missing, string $relative_from_site, string $current_file, int &$missing_hits): void {
    if (!isset($missing[$relative_from_site])) {
        $missing[$relative_from_site] = [
            'count' => 0,
            'files' => [],
        ];
    }
    $missing[$relative_from_site]['count']++;
    if (count($missing[$relative_from_site]['files']) < 5) {
        $missing[$relative_from_site]['files'][] = $current_file;
    }
    $missing_hits++;
}

function sspp_fix_reference_exists(string $resolved_path, array &$exists_cache): bool {
    if (isset($exists_cache[$resolved_path])) {
        return $exists_cache[$resolved_path];
    }

    $exists_cache[$resolved_path] = file_exists($resolved_path);
    return $exists_cache[$resolved_path];
}

function sspp_fix_discover_internal_hosts(string $site_path, array $extensions): array {
    $hosts = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($site_path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $extensions, true)) {
            continue;
        }

        $path = $file->getPathname();
        $content = file_get_contents($path);
        if ($content === false || strpos($content, "\0") !== false) {
            continue;
        }

        if ($ext === 'html' || $ext === 'htm') {
            $found_hosts = sspp_fix_extract_canonical_hosts($content);
            foreach ($found_hosts as $host) {
                sspp_fix_add_internal_host($hosts, $host);
            }
        }

        $absolute_urls = sspp_fix_extract_absolute_urls($content);
        foreach ($absolute_urls as $absolute) {
            $host = $absolute['host'] ?? '';
            if ($host === '') {
                continue;
            }
            $path = $absolute['path'] ?? '/';
            if (sspp_fix_is_local_host($host) || sspp_fix_path_is_wp($path)) {
                sspp_fix_add_internal_host($hosts, $host);
            }
        }
    }

    return $hosts;
}

function sspp_fix_run_version_path(): string {
    return __DIR__ . '/reports/run-version.txt';
}

function sspp_fix_read_run_version(): int {
    $path = sspp_fix_run_version_path();
    if (!is_readable($path)) {
        return 0;
    }

    $value = trim((string)file_get_contents($path));
    if ($value === '' || !ctype_digit($value)) {
        return 0;
    }

    return (int)$value;
}

function sspp_fix_bump_run_version(): int {
    $path = sspp_fix_run_version_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $current = sspp_fix_read_run_version();
    $next = $current + 1;
    file_put_contents($path, (string)$next . PHP_EOL);
    return $next;
}

function sspp_fix_extract_asset_paths(string $text): array {
    $normalized = str_replace('\\/', '/', $text);
    $pattern = '#(?:\./|\../)+(?:wp-content|wp-includes)/[^\s"\'\)\]>,<]+#';

    $matches = [];
    preg_match_all($pattern, $normalized, $matches);

    $results = [];
    foreach ($matches[0] ?? [] as $raw) {
        $clean = preg_split('/[?#]/', $raw, 2)[0];
        $clean = preg_split('/</', $clean, 2)[0];
        $clean = rtrim($clean, '\\');
        if ($clean === '' || $clean === null) {
            continue;
        }
        if (strpbrk($clean, '*{}') !== false) {
            continue;
        }
        if (strpbrk($clean, '<>') !== false) {
            continue;
        }
        $results[] = $clean;
    }

    return $results;
}

function sspp_fix_extract_root_paths(string $text): array {
    $normalized = str_replace('\\/', '/', $text);
    $results = [];
    $attr_pattern = '#(href|src|poster|content|data-[a-z0-9-]+|srcset|data-srcset)\s*=\s*["\']([^"\']+)["\']#i';
    $attr_matches = [];
    preg_match_all($attr_pattern, $normalized, $attr_matches, PREG_SET_ORDER);

    foreach ($attr_matches as $match) {
        $attr = strtolower($match[1]);
        $value = $match[2];

        if ($attr === 'srcset' || $attr === 'data-srcset') {
            $candidates = preg_split('/\s*,\s*/', $value);
            foreach ($candidates as $candidate) {
                $parts = preg_split('/\s+/', trim($candidate));
                $url = $parts[0] ?? '';
                $path = sspp_fix_clean_root_path($url);
                if ($path !== '') {
                    $results[] = $path;
                }
            }
            continue;
        }

        $path = sspp_fix_clean_root_path($value);
        if ($path !== '') {
            $results[] = $path;
        }
    }

    $css_pattern = '#url\(\s*["\']?(/(?!/)[A-Za-z0-9._~%@-][^"\'\)]+)#i';
    $css_matches = [];
    preg_match_all($css_pattern, $normalized, $css_matches);
    foreach ($css_matches[1] ?? [] as $raw) {
        $path = sspp_fix_clean_root_path($raw);
        if ($path !== '') {
            $results[] = $path;
        }
    }

    $json_pattern = '#[:=]\s*["\'](/(?!/)[A-Za-z0-9._~%@-][^"\']*)["\']#';
    $json_matches = [];
    preg_match_all($json_pattern, $normalized, $json_matches);
    foreach ($json_matches[1] ?? [] as $raw) {
        $path = sspp_fix_clean_root_path($raw);
        if ($path !== '') {
            $results[] = $path;
        }
    }

    return $results;
}

function sspp_fix_clean_root_path(string $value): string {
    $value = trim($value);
    if (strpos($value, '/') !== 0) {
        return '';
    }
    if (strpos($value, '//') === 0) {
        return '';
    }
    if (!preg_match('#^/(?!/)[A-Za-z0-9._~%@-]#', $value)) {
        return '';
    }

    $clean = preg_split('/[?#]/', $value, 2)[0] ?? '';
    $clean = rtrim($clean, '\\');
    if ($clean === '' || $clean === null) {
        return '';
    }
    if (strpbrk($clean, '*{}') !== false) {
        return '';
    }

    return $clean;
}

function sspp_fix_extract_absolute_urls(string $text): array {
    $normalized = str_replace('\\/', '/', $text);
    $pattern = "#(?i)(https?:\\/\\/|\\/\\/)([^\\/\\s\"'\\)\\]>,]+)(\\/[^\\s\"'\\)\\]>,]*)?#";

    $matches = [];
    preg_match_all($pattern, $normalized, $matches, PREG_SET_ORDER);

    $results = [];
    foreach ($matches as $match) {
        $scheme = $match[1] ?? '';
        $host = strtolower($match[2] ?? '');
        $path = $match[3] ?? '/';
        $path = preg_split('/[?#]/', $path, 2)[0] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $path = rtrim($path, '\\');
        if ($host === '') {
            continue;
        }

        $results[] = [
            'host' => $host,
            'path' => $path,
            'display' => rtrim($scheme . $host . $path, '\\'),
        ];
    }

    return $results;
}

function sspp_fix_extract_canonical_hosts(string $text): array {
    $hosts = [];
    $patterns = [
        '#<link[^>]+rel=["\']canonical["\'][^>]*>#i',
        '#<meta[^>]+property=["\']og:url["\'][^>]*>#i',
        '#<meta[^>]+name=["\']twitter:url["\'][^>]*>#i',
    ];

    foreach ($patterns as $pattern) {
        $matches = [];
        preg_match_all($pattern, $text, $matches);
        foreach ($matches[0] ?? [] as $tag) {
            if (preg_match('#(?:href|content)=["\']([^"\']+)["\']#i', $tag, $attr)) {
                $host = sspp_fix_host_from_url($attr[1]);
                if ($host !== '') {
                    $hosts[$host] = true;
                }
            }
        }
    }

    return array_keys($hosts);
}

function sspp_fix_host_from_url(string $url): string {
    if (!preg_match('#^(https?:)?//#i', $url)) {
        return '';
    }
    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }

    $host = $parts['host'] ?? '';
    return strtolower((string)$host);
}

function sspp_fix_is_local_host(string $host): bool {
    $base = sspp_fix_host_base($host);
    return $base !== '' && str_ends_with($base, '.local');
}

function sspp_fix_path_is_wp(string $path): bool {
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^(?:/\\./|/\\.\\./)+#', '/', $path);
    return strpos($path, '/wp-content/') === 0 || strpos($path, '/wp-includes/') === 0;
}

function sspp_fix_host_base(string $host): string {
    $host = strtolower($host);
    $host = preg_replace('/:\d+$/', '', $host);
    $host = rtrim($host, '.');
    if (strpos($host, 'www.') === 0) {
        return substr($host, 4);
    }
    return $host;
}

function sspp_fix_add_internal_host(array &$internal_hosts, string $host): void {
    $host = strtolower($host);
    if ($host === '') {
        return;
    }

    $internal_hosts[$host] = true;
    $base = sspp_fix_host_base($host);
    if ($base !== '') {
        $internal_hosts[$base] = true;
        $internal_hosts['www.' . $base] = true;
    }
}

function sspp_fix_resolve_path(string $base_dir, string $relative_path): string {
    $path = rtrim(str_replace('\\', '/', $base_dir), '/') . '/' . ltrim($relative_path, '/');
    return sspp_fix_normalize_path($path);
}

function sspp_fix_normalize_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    $is_absolute = strpos($path, '/') === 0;
    $segments = explode('/', $path);
    $parts = [];

    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $segment;
    }

    return ($is_absolute ? '/' : '') . implode('/', $parts);
}

function sspp_fix_prefix_for_file(string $site_path, string $file_path): string {
    $relative = sspp_fix_relative_path($site_path, $file_path);
    $relative = trim($relative, '/');

    if ($relative === '') {
        return './';
    }

    $segments = explode('/', $relative);
    $depth = count($segments);

    $last = end($segments);
    if (is_string($last) && preg_match('/\.[a-z0-9]{1,6}$/i', $last)) {
        $depth--;
    }

    if ($depth <= 0) {
        return './';
    }

    return str_repeat('../', $depth);
}

function sspp_fix_rewrite_content(string $text, string $prefix, array $internal_hosts, int &$count): string {
    $count = 0;

    $text = sspp_fix_normalize_double_slash_root_paths($text, $count);
    $text = sspp_fix_normalize_dot_slash_root_paths($text, $count);
    $text = sspp_fix_unescape_wp_paths($text, $count);

    if (!empty($internal_hosts)) {
        $text = sspp_fix_strip_absolute_hosts($text, $internal_hosts, $count);
    }

    $text = sspp_fix_rewrite_relative_chains($text, $prefix, $count);
    $text = sspp_fix_rewrite_root_paths($text, $prefix, $count);
    $text = sspp_fix_rewrite_json_paths($text, $prefix, $count);

    return $text;
}

function sspp_fix_normalize_double_slash_root_paths(string $text, int &$count): string {
    $before = $text;

    $text = str_replace('//wp-content/', '/wp-content/', $text, $c1);
    $count += $c1;
    $text = str_replace('//wp-includes/', '/wp-includes/', $text, $c2);
    $count += $c2;

    $text = str_replace('\\/\\/wp-content\\/', '\\/wp-content\\/', $text, $c3);
    $count += $c3;
    $text = str_replace('\\/\\/wp-includes\\/', '\\/wp-includes\\/', $text, $c4);
    $count += $c4;

    return $text;
}

function sspp_fix_normalize_dot_slash_root_paths(string $text, int &$count): string {
    $replacements = [
        './/wp-content/' => '/wp-content/',
        '././wp-content/' => '/wp-content/',
        './/wp-includes/' => '/wp-includes/',
        '././wp-includes/' => '/wp-includes/',
        '.\\/\\/wp-content\\/' => '\\/wp-content\\/',
        '.\\/\\/wp-includes\\/' => '\\/wp-includes\\/',
        '.\\/\\.\\/wp-content\\/' => '\\/wp-content\\/',
        '.\\/\\.\\/wp-includes\\/' => '\\/wp-includes\\/',
    ];

    foreach ($replacements as $from => $to) {
        $text = str_replace($from, $to, $text, $c);
        $count += $c;
    }

    return $text;
}

function sspp_fix_unescape_wp_paths(string $text, int &$count): string {
    $text = preg_replace('#\\\\/(wp-content|wp-includes)\\\\/#', '/$1/', $text, -1, $c1);
    $count += $c1;
    $text = preg_replace('#\\\\/(wp-content|wp-includes)/#', '/$1/', $text, -1, $c2);
    $count += $c2;
    $text = preg_replace('#/(wp-content|wp-includes)\\\\/#', '/$1/', $text, -1, $c3);
    $count += $c3;

    return $text;
}

function sspp_fix_strip_absolute_hosts(string $text, array $hosts, int &$count): string {
    $count = 0;
    if (empty($hosts)) {
        return $text;
    }

    foreach (array_keys($hosts) as $host) {
        $host = (string)$host;
        if ($host === '') {
            continue;
        }
        $quoted = preg_quote($host, '#');
        $host_pattern = $quoted . '\\.?';
        $slash = '(?:/|\\\\/)';
        $rel = '(?:\\.' . $slash . '|\\.\\.' . $slash . ')+';
        $path = '(wp-content|wp-includes)' . $slash;

        $patterns = [
            '#https?:\/\/' . $host_pattern . $slash . $path . '#i',
            '#\/\/' . $host_pattern . $slash . $path . '#i',
            '#https?:\/\/' . $host_pattern . $rel . $path . '#i',
            '#\/\/' . $host_pattern . $rel . $path . '#i',
            '#https?:\\\\/\\\\/' . $host_pattern . $slash . $path . '#i',
            '#\\\\/\\\\/' . $host_pattern . $slash . $path . '#i',
            '#https?:\\\\/\\\\/' . $host_pattern . $rel . $path . '#i',
            '#\\\\/\\\\/' . $host_pattern . $rel . $path . '#i',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '/$1/', $text, -1, $c);
            $count += $c;
        }
    }

    return $text;
}

function sspp_fix_rewrite_relative_chains(string $text, string $prefix, int &$count): string {
    $text = preg_replace('#(^|["\'\(\s=:\[,])(?:\.+/)+wp-content/#', '$1' . $prefix . 'wp-content/', $text, -1, $c1);
    $count += $c1;

    $text = preg_replace('#(^|["\'\(\s=:\[,])(?:\.+/)+wp-includes/#', '$1' . $prefix . 'wp-includes/', $text, -1, $c2);
    $count += $c2;

    return $text;
}

function sspp_fix_rewrite_root_paths(string $text, string $prefix, int &$count): string {
    $pattern = '#(^|["\'\(\s=:\[,])(/wp-content/)#';
    $text = preg_replace($pattern, '$1' . $prefix . 'wp-content/', $text, -1, $c1);
    $count += $c1;

    $pattern = '#(^|["\'\(\s=:\[,])(/wp-includes/)#';
    $text = preg_replace($pattern, '$1' . $prefix . 'wp-includes/', $text, -1, $c2);
    $count += $c2;

    return $text;
}

function sspp_fix_rewrite_json_paths(string $text, string $prefix, int &$count): string {
    $json_prefix = str_replace('/', '\\/', $prefix);

    // Relative chains in JSON (..\/ or .\/)
    $text = preg_replace('#(?:\.\.\\\\/|\.\\\\/)+wp-content\\\\/#', $json_prefix . 'wp-content\\/', $text, -1, $c1);
    $count += $c1;

    $text = preg_replace('#(?:\.\.\\\\/|\.\\\\/)+wp-includes\\\\/#', $json_prefix . 'wp-includes\\/', $text, -1, $c2);
    $count += $c2;

    // Broken dot-runs like ....\/wp-content\/ from prior exports (including mixed ./ + escaped).
    $text = preg_replace('#(?:\./|\../)?(?:\.+\\\\/)+wp-content\\\\/#', $json_prefix . 'wp-content\\/', $text, -1, $c3);
    $count += $c3;

    $text = preg_replace('#(?:\./|\../)?(?:\.+\\\\/)+wp-includes\\\\/#', $json_prefix . 'wp-includes\\/', $text, -1, $c4);
    $count += $c4;

    // Root JSON paths
    $text = preg_replace('#\\\\/wp-content\\\\/#', $json_prefix . 'wp-content\\/', $text, -1, $c5);
    $count += $c5;

    $text = preg_replace('#\\\\/wp-includes\\\\/#', $json_prefix . 'wp-includes\\/', $text, -1, $c6);
    $count += $c6;

    return $text;
}
