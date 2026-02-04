<?php

declare(strict_types=1);

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
        'errors' => [],
    ];

    if (!is_dir($site_path)) {
        $stats['errors'][] = "Site path does not exist: {$site_path}";
        return $stats;
    }

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
            $rewrite_hosts,
            $counts
        );

        $stats['bytes_before'] += strlen($content);
        $stats['bytes_after'] += strlen($new_content);
        $stats['replacements'] += $counts;

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

function sspp_fix_rewrite_content(string $text, string $prefix, bool $rewrite_hosts, int &$count): string {
    $count = 0;

    $text = sspp_fix_normalize_double_slash_root_paths($text, $count);
    $text = sspp_fix_normalize_dot_slash_root_paths($text, $count);

    if ($rewrite_hosts) {
        $text = sspp_fix_strip_absolute_hosts($text, $count);
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

function sspp_fix_strip_absolute_hosts(string $text, int &$count): string {
    $patterns = [
        '#https?:\/\/[^\/]+\.?\/(wp-content|wp-includes)\/#i',
        '#\/\/[^\/]+\.?\/(wp-content|wp-includes)\/#i',
        '#https?:\\\\/\\\\/[^\\\\/]+\.?\\\\/(wp-content|wp-includes)\\\\/#i',
        '#\\\\/\\\\/[^\\\\/]+\.?\\\\/(wp-content|wp-includes)\\\\/#i',
    ];

    foreach ($patterns as $pattern) {
        $text = preg_replace($pattern, '/$1/', $text, -1, $c);
        $count += $c;
    }

    return $text;
}

function sspp_fix_rewrite_relative_chains(string $text, string $prefix, int &$count): string {
    $text = preg_replace('#(?:\./|\../)+wp-content/#', $prefix . 'wp-content/', $text, -1, $c1);
    $count += $c1;

    $text = preg_replace('#(?:\./|\../)+wp-includes/#', $prefix . 'wp-includes/', $text, -1, $c2);
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
    $text = preg_replace('#(?:\.\.\\/|\.\\/)+wp-content\\/#', $json_prefix . 'wp-content\\/', $text, -1, $c1);
    $count += $c1;

    $text = preg_replace('#(?:\.\.\\/|\.\\/)+wp-includes\\/#', $json_prefix . 'wp-includes\\/', $text, -1, $c2);
    $count += $c2;

    // Root JSON paths
    $text = preg_replace('#\\/wp-content\\/#', $json_prefix . 'wp-content\\/', $text, -1, $c3);
    $count += $c3;

    $text = preg_replace('#\\/wp-includes\\/#', $json_prefix . 'wp-includes\\/', $text, -1, $c4);
    $count += $c4;

    return $text;
}
