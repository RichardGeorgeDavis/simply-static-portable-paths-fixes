<?php

declare(strict_types=1);

const SSPP_FIXES_VERSION = '0.3.12';

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
        'backup_status' => 'skipped',
        'backup_path' => '',
        'errors' => [],
    ];

    if (!is_dir($site_path)) {
        $stats['errors'][] = "Site path does not exist: {$site_path}";
        return $stats;
    }

    $backup_enabled = (bool)($options['backup'] ?? true);
    if ($apply && $backup_enabled) {
        $backup_path = sspp_fix_backup_zip_path($site_path);
        $stats['backup_path'] = sspp_fix_relative_path($site_path, $backup_path);
        $backup_result = sspp_fix_ensure_backup($site_path, $backup_path, $stats['errors']);
        $stats['backup_status'] = $backup_result;
        if ($backup_result === 'failed') {
            return $stats;
        }
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($site_path, FilesystemIterator::SKIP_DOTS)
    );

    $missing = [];
    $missing_hits = 0;
    $exists_cache = [];
    $absolute_candidates = [];
    $missing_ignore = sspp_fix_missing_ignore_patterns($options);

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

        $current_file = sspp_fix_relative_path($site_path, $path);
        $is_asset = sspp_fix_is_asset_path($current_file);

        $counts = 0;
        $new_content = sspp_fix_rewrite_content(
            $content,
            $prefix,
            $rewrite_hosts,
            !$is_asset,
            $counts
        );

        $stats['bytes_before'] += strlen($content);
        $stats['bytes_after'] += strlen($new_content);
        $stats['replacements'] += $counts;

        if (!$is_asset) {
            $found_paths = sspp_fix_extract_relative_paths($new_content);
            foreach ($found_paths as $relative_path) {
                if ($relative_path === '') {
                    continue;
                }
                $base_dir = strpos($relative_path, '/') === 0 ? $site_path : dirname($path);
                $resolved = sspp_fix_resolve_path($base_dir, $relative_path);
                if (!sspp_fix_reference_exists($resolved, $exists_cache)) {
                    $relative_from_site = sspp_fix_relative_path($site_path, $resolved);
                    if (!sspp_fix_is_missing_ignored($relative_from_site, $missing_ignore)) {
                        sspp_fix_record_missing($missing, $relative_from_site, $current_file, $missing_hits);
                    }
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
        if (!sspp_fix_is_local_host($host)) {
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
            if (!sspp_fix_is_missing_ignored($relative_from_site, $missing_ignore)) {
                sspp_fix_record_missing($missing, $relative_from_site, $candidate['file'], $missing_hits);
            }
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

function sspp_fix_is_asset_path(string $relative_path): bool {
    $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
    return str_starts_with($relative_path, 'wp-content/') || str_starts_with($relative_path, 'wp-includes/');
}

function sspp_fix_backup_zip_path(string $site_path): string {
    $site_path = rtrim(str_replace('\\', '/', $site_path), '/');
    return $site_path . '.zip';
}

function sspp_fix_ensure_backup(string $site_path, string $backup_path, array &$errors): string {
    if (file_exists($backup_path)) {
        return 'exists';
    }

    $created = sspp_fix_create_backup_zip($site_path, $backup_path, $errors);
    return $created ? 'created' : 'failed';
}

function sspp_fix_create_backup_zip(string $site_path, string $backup_path, array &$errors): bool {
    if (!is_dir($site_path)) {
        $errors[] = "Backup failed: site folder missing ({$site_path}).";
        return false;
    }

    $site_path = rtrim(str_replace('\\', '/', $site_path), '/');
    $backup_dir = dirname($backup_path);
    if (!is_dir($backup_dir) && !mkdir($backup_dir, 0755, true)) {
        $errors[] = "Backup failed: unable to create backup directory ({$backup_dir}).";
        return false;
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        $result = $zip->open($backup_path, ZipArchive::CREATE);
        if ($result !== true) {
            $errors[] = "Backup failed: unable to open zip ({$backup_path}).";
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($site_path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $full_path = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(substr($full_path, strlen($site_path)), '/');
            if ($relative === '') {
                continue;
            }
            $zip->addFile($full_path, $relative);
        }

        if (!$zip->close()) {
            $errors[] = "Backup failed: unable to finalize zip ({$backup_path}).";
            return false;
        }

        return true;
    }

    if (function_exists('shell_exec')) {
        $cmd = 'zip -r ' . escapeshellarg($backup_path) . ' .';
        $output = shell_exec('cd ' . escapeshellarg($site_path) . ' && ' . $cmd);
        if (!file_exists($backup_path)) {
            $errors[] = "Backup failed: zip command did not create archive ({$backup_path}).";
            if (is_string($output) && trim($output) !== '') {
                $errors[] = trim($output);
            }
            return false;
        }
        return true;
    }

    $errors[] = "Backup failed: ZipArchive is unavailable and shell_exec is disabled.";
    return false;
}

function sspp_fix_restore_backup(string $site_path, array &$errors): bool {
    $backup_path = sspp_fix_backup_zip_path($site_path);
    if (!file_exists($backup_path)) {
        $errors[] = "Restore failed: backup zip not found ({$backup_path}).";
        return false;
    }

    if (is_dir($site_path)) {
        $deleted = sspp_fix_delete_dir($site_path, $errors);
        if (!$deleted && is_dir($site_path)) {
            $fallback = $site_path . '.restore-old-' . date('Ymd-His');
            if (!@rename($site_path, $fallback)) {
                $errors[] = "Restore cleanup failed: unable to move existing folder ({$site_path}).";
            }
        }
    }
    if (!is_dir($site_path) && !mkdir($site_path, 0755, true)) {
        $errors[] = "Restore failed: unable to create site directory ({$site_path}).";
        return false;
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        $result = $zip->open($backup_path);
        if ($result !== true) {
            $errors[] = "Restore failed: unable to open zip ({$backup_path}).";
            return false;
        }

        if (!$zip->extractTo($site_path)) {
            $errors[] = "Restore failed: unable to extract zip to ({$site_path}).";
            $zip->close();
            return false;
        }
        $zip->close();
        sspp_fix_flatten_restore($site_path, $errors);
        return true;
    }

    if (function_exists('shell_exec')) {
        $cmd = 'unzip -o ' . escapeshellarg($backup_path) . ' -d ' . escapeshellarg($site_path);
        shell_exec($cmd);
        if (!is_dir($site_path)) {
            $errors[] = "Restore failed: unzip command did not restore directory ({$site_path}).";
            return false;
        }
        sspp_fix_flatten_restore($site_path, $errors);
        return true;
    }

    $errors[] = "Restore failed: ZipArchive is unavailable and shell_exec is disabled.";
    return false;
}

function sspp_fix_delete_dir(string $path, array &$errors): bool {
    if (!is_dir($path)) {
        return true;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item_path = $item->getPathname();
        if ($item->isLink()) {
            if (!@unlink($item_path)) {
                $errors[] = "Restore cleanup failed: unable to remove symlink ({$item_path}).";
            }
            continue;
        }
        if ($item->isDir()) {
            if (!@rmdir($item_path)) {
                @chmod($item_path, 0777);
                if (!@rmdir($item_path)) {
                    $errors[] = "Restore cleanup failed: unable to remove directory ({$item_path}).";
                }
            }
            continue;
        }
        if (!@unlink($item_path)) {
            @chmod($item_path, 0666);
            if (!@unlink($item_path)) {
                $errors[] = "Restore cleanup failed: unable to remove file ({$item_path}).";
            }
        }
    }

    if (!@rmdir($path)) {
        @chmod($path, 0777);
        if (!@rmdir($path)) {
            $errors[] = "Restore cleanup failed: unable to remove directory ({$path}).";
            return false;
        }
    }

    return true;
}

function sspp_fix_flatten_restore(string $site_path, array &$errors): void {
    $site_path = rtrim($site_path, DIRECTORY_SEPARATOR);
    $basename = basename($site_path);
    $nested = $site_path . DIRECTORY_SEPARATOR . $basename;
    if (!is_dir($nested)) {
        return;
    }

    foreach (new DirectoryIterator($nested) as $item) {
        if ($item->isDot()) {
            continue;
        }
        $src = $item->getPathname();
        $dst = $site_path . DIRECTORY_SEPARATOR . $item->getFilename();
        if (file_exists($dst)) {
            if (is_dir($dst)) {
                sspp_fix_delete_dir($dst, $errors);
            } else {
                if (!@unlink($dst)) {
                    @chmod($dst, 0666);
                    @unlink($dst);
                }
            }
        }
        if (!file_exists($dst) && @rename($src, $dst)) {
            continue;
        }
        if ($item->isDir()) {
            if (sspp_fix_copy_dir($src, $dst, $errors)) {
                sspp_fix_delete_dir($src, $errors);
            }
            continue;
        }
        if (!@copy($src, $dst)) {
            $errors[] = "Restore flatten failed: unable to copy file ({$src}).";
            continue;
        }
        @unlink($src);
    }

    if (!@rmdir($nested)) {
        @chmod($nested, 0777);
        @rmdir($nested);
    }
}

function sspp_fix_copy_dir(string $src, string $dst, array &$errors): bool {
    if (!is_dir($src)) {
        return false;
    }
    if (!is_dir($dst) && !mkdir($dst, 0755, true)) {
        $errors[] = "Restore flatten failed: unable to create directory ({$dst}).";
        return false;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $target = $dst . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0755, true)) {
                $errors[] = "Restore flatten failed: unable to create directory ({$target}).";
                return false;
            }
            continue;
        }
        if (!@copy($item->getPathname(), $target)) {
            $errors[] = "Restore flatten failed: unable to copy file ({$target}).";
            return false;
        }
    }

    return true;
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

function sspp_fix_missing_ignore_patterns(array $options): array {
    $defaults = [
        '#^wp-content/#',
        '#^wp-includes/#',
    ];

    $custom = $options['missing_ignore'] ?? [];
    if (!is_array($custom)) {
        $custom = [];
    }

    return array_values(array_merge($defaults, $custom));
}

function sspp_fix_is_missing_ignored(string $relative_path, array $patterns): bool {
    if ($relative_path === '') {
        return false;
    }

    foreach ($patterns as $pattern) {
        if (@preg_match($pattern, $relative_path)) {
            if (preg_match($pattern, $relative_path)) {
                return true;
            }
        }
    }

    return false;
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
    $pattern = "#(?i)(https?:\\/\\/|\\/\\/)([^\\/\\s\"'\\)\\]>,<]+)(\\/[^\\s\"'\\)\\]>,<]*)?#";

    $matches = [];
    preg_match_all($pattern, $normalized, $matches, PREG_SET_ORDER);

    $results = [];
    foreach ($matches as $match) {
        $scheme = $match[1] ?? '';
        $host = strtolower($match[2] ?? '');
        $path = $match[3] ?? '/';
        $path = preg_split('/[?#]/', $path, 2)[0] ?? '/';
        $path = preg_split('/</', $path, 2)[0] ?? $path;
        $path = preg_split('/&lt;/i', $path, 2)[0] ?? $path;
        if ($path === '') {
            $path = '/';
        }
        $path = rtrim($path, '\\');
        if ($host === '') {
            continue;
        }
        if (strpos($path, '<') !== false || strpos($path, '>') !== false) {
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

function sspp_fix_extract_relative_paths(string $text): array {
    $patterns = [
        '#(^|["\'\\(\\s=\\[,>])(\\/(?!\\/)[^\\s"\'\\)\\]>,<]*)#',
        '#(&quot;)(\\/(?!\\/)[^\\s"\'\\)\\]>,<]*)#i',
        '#(^|["\'\\(\\s=\\[,>])((?:\\.+/)+[^\\s"\'\\)\\]>,<]*)#',
        '#(&quot;)((?:\\.+/)+[^\\s"\'\\)\\]>,<]*)#i',
        '#(^|["\'\\(\\s=\\[,>])(\\\\/(?!\\\\/)[^\\s"\'\\)\\]>,<]*)#',
        '#(&quot;)(\\\\/(?!\\\\/)[^\\s"\'\\)\\]>,<]*)#i',
        '#(^|["\'\\(\\s=\\[,>])((?:\\.+\\\\/)+[^\\s"\'\\)\\]>,<]*)#',
        '#(&quot;)((?:\\.+\\\\/)+[^\\s"\'\\)\\]>,<]*)#i',
    ];

    $results = [];
    foreach ($patterns as $pattern) {
        $matches = [];
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $value = $match[2] ?? '';
            if ($value === '') {
                continue;
            }
            $clean = sspp_fix_clean_relative_path($value);
            if ($clean !== '') {
                $results[] = $clean;
            }
        }
    }

    return $results;
}

function sspp_fix_clean_relative_path(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $parts = preg_split('/<|&lt;/i', $value, 2);
    $value = $parts[0] ?? '';
    $parts = preg_split('/[?#]/', $value, 2);
    $value = $parts[0] ?? '';
    $value = rtrim($value, '\\');
    $value = str_replace('\\/', '/', $value);
    $value = rtrim($value, "\"'");
    $value = preg_replace('/&quot;$/i', '', $value);
    $value = rtrim($value, "\"'");
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $value)) {
        return '';
    }
    if (preg_match('#^(?:data:|mailto:|tel:|javascript:)#i', $value)) {
        return '';
    }
    if (!preg_match('#^(?:/|\\./|\\.\\./)#', $value)) {
        return '';
    }
    if (sspp_fix_contains_glob_token($value)) {
        return '';
    }

    return $value;
}

function sspp_fix_contains_glob_token(string $value): bool {
    return strpbrk($value, '*{}') !== false;
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

function sspp_fix_rewrite_content(string $text, string $prefix, bool $rewrite_hosts, bool $rewrite_paths, int &$count): string {
    $count = 0;
    $prefix_escaped = str_replace('/', '\\/', $prefix);

    if ($rewrite_hosts) {
        $text = sspp_fix_rewrite_local_hosts($text, $prefix, $prefix_escaped, $count);
    }

    if ($rewrite_paths) {
        $text = sspp_fix_rewrite_scheme_relative_root_paths($text, $prefix, $prefix_escaped, $count);
        $text = sspp_fix_rewrite_root_relative_paths($text, $prefix, $prefix_escaped, $count);
        $text = sspp_fix_rewrite_dot_relative_paths($text, $prefix, $prefix_escaped, $count);
    }

    return $text;
}

function sspp_fix_rewrite_local_hosts(string $text, string $prefix, string $prefix_escaped, int &$count): string {
    $local_count = 0;

    $pattern = '#(?i)(https?:\\/\\/|\\/\\/)([A-Za-z0-9.-]+(?::\\d+)?)(\\/[^\\s"\'\\)\\]>,<]*)?#';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix, &$local_count): string {
        $host = $match[2] ?? '';
        if (!sspp_fix_is_local_host($host)) {
            return $match[0];
        }

        $path = $match[3] ?? '';
        $replacement = sspp_fix_build_relative_replacement($path, $prefix, false);
        if ($replacement === $match[0]) {
            return $match[0];
        }

        $local_count++;
        return $replacement;
    }, $text);

    $pattern = '#(?i)(https?:\\\\/\\\\/|\\\\/\\\\/)([A-Za-z0-9.-]+(?::\\d+)?)(\\\\/[^\\s"\'\\)\\]>,<]*)?#';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix_escaped, &$local_count): string {
        $host = $match[2] ?? '';
        if (!sspp_fix_is_local_host($host)) {
            return $match[0];
        }

        $path = $match[3] ?? '';
        $replacement = sspp_fix_build_relative_replacement($path, $prefix_escaped, true);
        if ($replacement === $match[0]) {
            return $match[0];
        }

        $local_count++;
        return $replacement;
    }, $text);

    $count += $local_count;
    return $text;
}

function sspp_fix_rewrite_root_relative_paths(string $text, string $prefix, string $prefix_escaped, int &$count): string {
    $local_count = 0;

    $pattern = '#(["\'])(\\/(?!\\/)[^\\s"\'\\)\\]>,<]*)#';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix, &$local_count): string {
        if (sspp_fix_contains_glob_token($match[2])) {
            return $match[0];
        }
        $replacement = $match[1] . sspp_fix_build_relative_replacement($match[2], $prefix, false);
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $pattern = '#(["\'])(\\\\/(?!\\\\/)[^\\s"\'\\)\\]>,<]*)#';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix_escaped, &$local_count): string {
        if (sspp_fix_contains_glob_token($match[2])) {
            return $match[0];
        }
        $replacement = $match[1] . sspp_fix_build_relative_replacement($match[2], $prefix_escaped, true);
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $pattern = '#(&quot;)(\\/(?!\\/)[^\\s"\'\\)\\]>,<]*)#i';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix, &$local_count): string {
        if (sspp_fix_contains_glob_token($match[2])) {
            return $match[0];
        }
        $replacement = $match[1] . sspp_fix_build_relative_replacement($match[2], $prefix, false);
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $pattern = '#(&quot;)(\\\\/(?!\\\\/)[^\\s"\'\\)\\]>,<]*)#i';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix_escaped, &$local_count): string {
        if (sspp_fix_contains_glob_token($match[2])) {
            return $match[0];
        }
        $replacement = $match[1] . sspp_fix_build_relative_replacement($match[2], $prefix_escaped, true);
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $pattern = '#(url\\(\\s*)(/(?!/)[^\\s"\'\\)\\]>,<]*)#i';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix, &$local_count): string {
        if (sspp_fix_contains_glob_token($match[2])) {
            return $match[0];
        }
        $replacement = $match[1] . sspp_fix_build_relative_replacement($match[2], $prefix, false);
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $pattern = '#(url\\(\\s*)(\\\\/(?!\\\\/)[^\\s"\'\\)\\]>,<]*)#i';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix_escaped, &$local_count): string {
        if (sspp_fix_contains_glob_token($match[2])) {
            return $match[0];
        }
        $replacement = $match[1] . sspp_fix_build_relative_replacement($match[2], $prefix_escaped, true);
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $count += $local_count;
    return $text;
}

function sspp_fix_rewrite_scheme_relative_root_paths(string $text, string $prefix, string $prefix_escaped, int &$count): string {
    $local_count = 0;
    $host_guard = '(?:wp-[a-z0-9-]+|wp-content|wp-includes|wp-json)(?:/|\\\\/)';

    $pattern = '#(["\'])(//'.$host_guard.'[^\\s"\'\\)\\]>,<]*)#i';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix, &$local_count): string {
        if (sspp_fix_contains_glob_token($match[2])) {
            return $match[0];
        }
        $path = substr($match[2], 1);
        $replacement = $match[1] . sspp_fix_build_relative_replacement($path, $prefix, false);
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $pattern = '#(["\'])(\\\\/\\\\/'.$host_guard.'[^\\s"\'\\)\\]>,<]*)#i';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix_escaped, &$local_count): string {
        if (sspp_fix_contains_glob_token($match[2])) {
            return $match[0];
        }
        $path = substr($match[2], 2);
        $replacement = $match[1] . sspp_fix_build_relative_replacement($path, $prefix_escaped, true);
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $pattern = '#(&quot;)(//'.$host_guard.'[^\\s"\'\\)\\]>,<]*)#i';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix, &$local_count): string {
        if (sspp_fix_contains_glob_token($match[2])) {
            return $match[0];
        }
        $path = substr($match[2], 1);
        $replacement = $match[1] . sspp_fix_build_relative_replacement($path, $prefix, false);
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $pattern = '#(&quot;)(\\\\/\\\\/'.$host_guard.'[^\\s"\'\\)\\]>,<]*)#i';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix_escaped, &$local_count): string {
        if (sspp_fix_contains_glob_token($match[2])) {
            return $match[0];
        }
        $path = substr($match[2], 2);
        $replacement = $match[1] . sspp_fix_build_relative_replacement($path, $prefix_escaped, true);
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $pattern = '#(url\\(\\s*)(//'.$host_guard.'[^\\s"\'\\)\\]>,<]*)#i';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix, &$local_count): string {
        if (sspp_fix_contains_glob_token($match[2])) {
            return $match[0];
        }
        $path = substr($match[2], 1);
        $replacement = $match[1] . sspp_fix_build_relative_replacement($path, $prefix, false);
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $pattern = '#(url\\(\\s*)(\\\\/\\\\/'.$host_guard.'[^\\s"\'\\)\\]>,<]*)#i';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix_escaped, &$local_count): string {
        if (sspp_fix_contains_glob_token($match[2])) {
            return $match[0];
        }
        $path = substr($match[2], 2);
        $replacement = $match[1] . sspp_fix_build_relative_replacement($path, $prefix_escaped, true);
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $count += $local_count;
    return $text;
}

function sspp_fix_rewrite_dot_relative_paths(string $text, string $prefix, string $prefix_escaped, int &$count): string {
    $local_count = 0;

    $pattern = '#(^|["\'\\(\\s=\\[,>])((?:\\.+(?:/|\\\\/))+[^\\s"\'\\)\\]>,<]*)#';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix, $prefix_escaped, &$local_count): string {
        $token = $match[2];
        if (sspp_fix_contains_glob_token($token)) {
            return $match[0];
        }
        if (!sspp_fix_has_broken_dot_run($token)) {
            return $match[0];
        }
        if (!preg_match('#^((?:\\.+(?:/|\\\\/))+)(.*)$#', $token, $parts)) {
            return $match[0];
        }
        $use_escaped = strpos($token, '\\/') !== false;
        $rest = sspp_fix_strip_leading_slash($parts[2] ?? '', $use_escaped);
        $prefix_to_use = $use_escaped ? $prefix_escaped : $prefix;
        $replacement = $match[1] . $prefix_to_use . $rest;
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $pattern = '#(&quot;)((?:\\.+(?:/|\\\\/))+[^\\s"\'\\)\\]>,<]*)#i';
    $text = preg_replace_callback($pattern, function (array $match) use ($prefix, $prefix_escaped, &$local_count): string {
        $token = $match[2];
        if (sspp_fix_contains_glob_token($token)) {
            return $match[0];
        }
        if (!sspp_fix_has_broken_dot_run($token)) {
            return $match[0];
        }
        if (!preg_match('#^((?:\\.+(?:/|\\\\/))+)(.*)$#', $token, $parts)) {
            return $match[0];
        }
        $use_escaped = strpos($token, '\\/') !== false;
        $rest = sspp_fix_strip_leading_slash($parts[2] ?? '', $use_escaped);
        $prefix_to_use = $use_escaped ? $prefix_escaped : $prefix;
        $replacement = $match[1] . $prefix_to_use . $rest;
        if ($replacement === $match[0]) {
            return $match[0];
        }
        $local_count++;
        return $replacement;
    }, $text);

    $count += $local_count;
    return $text;
}

function sspp_fix_has_broken_dot_run(string $token): bool {
    $normalized = str_replace('\\/', '/', $token);
    return preg_match('#\\.{3,}[/]#', $normalized) === 1;
}

function sspp_fix_build_relative_replacement(string $path, string $prefix, bool $escaped): string {
    $path = sspp_fix_strip_path_suffix($path);
    if ($path === '') {
        return $prefix;
    }

    $path = sspp_fix_strip_leading_slash($path, $escaped);
    if ($path === '') {
        return $prefix;
    }

    return $prefix . $path;
}

function sspp_fix_strip_path_suffix(string $path): string {
    $parts = preg_split('/<|&lt;/i', $path, 2);
    return $parts[0] ?? '';
}

function sspp_fix_strip_leading_slash(string $path, bool $escaped): string {
    if ($path === '') {
        return '';
    }

    if ($escaped) {
        if (strpos($path, '\\/') === 0) {
            return substr($path, 2);
        }
        if (strpos($path, '/') === 0) {
            return substr($path, 1);
        }
        return $path;
    }

    if (strpos($path, '/') === 0) {
        return substr($path, 1);
    }
    if (strpos($path, '\\/') === 0) {
        return substr($path, 2);
    }

    return $path;
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

    $text = preg_replace('#(&quot;)(?:\./|\../)+wp-content/#', '$1' . $prefix . 'wp-content/', $text, -1, $c3);
    $count += $c3;

    $text = preg_replace('#(&quot;)(?:\./|\../)+wp-includes/#', '$1' . $prefix . 'wp-includes/', $text, -1, $c4);
    $count += $c4;

    return $text;
}

function sspp_fix_rewrite_root_paths(string $text, string $prefix, int &$count): string {
    $pattern = '#(^|["\'\(\s=:\[,])(/wp-content/)#';
    $text = preg_replace($pattern, '$1' . $prefix . 'wp-content/', $text, -1, $c1);
    $count += $c1;

    $pattern = '#(^|["\'\(\s=:\[,])(/wp-includes/)#';
    $text = preg_replace($pattern, '$1' . $prefix . 'wp-includes/', $text, -1, $c2);
    $count += $c2;

    $pattern = '#(&quot;)(/wp-content/)#';
    $text = preg_replace($pattern, '$1' . $prefix . 'wp-content/', $text, -1, $c3);
    $count += $c3;

    $pattern = '#(&quot;)(/wp-includes/)#';
    $text = preg_replace($pattern, '$1' . $prefix . 'wp-includes/', $text, -1, $c4);
    $count += $c4;

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
