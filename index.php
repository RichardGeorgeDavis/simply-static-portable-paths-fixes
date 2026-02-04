<?php

declare(strict_types=1);

require_once __DIR__ . '/fix.php';

$sites_dir = __DIR__ . '/sites';
$sites = sspp_fix_list_sites($sites_dir);

$selected_site = $_POST['site'] ?? '';
$apply = isset($_POST['apply']);
$rewrite_hosts = $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['rewrite_hosts']) : true;
$selected_site = $selected_site !== '' ? basename((string)$selected_site) : '';
$selected_site_url = $selected_site !== '' ? 'sites/' . rawurlencode($selected_site) . '/' : '';

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $site_path = $sites_dir . DIRECTORY_SEPARATOR . $selected_site;

    if ($selected_site === '' || !is_dir($site_path)) {
        $result = [
            'errors' => ['Invalid site selection.'],
        ];
    } else {
        $result = sspp_fix_run($site_path, [
            'apply' => $apply,
            'rewrite_hosts' => $rewrite_hosts,
        ]);
    }
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Simply Static Fixes</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; margin: 24px; color: #222; }
        h1 { margin: 0 0 12px; }
        .panel { border: 1px solid #ddd; padding: 16px; border-radius: 6px; margin-bottom: 16px; }
        label { display: inline-block; margin-right: 12px; }
        select, button { padding: 6px 10px; }
        .stats { margin-top: 12px; }
        .errors { color: #b00020; }
        .files { max-height: 260px; overflow: auto; background: #fafafa; padding: 8px; border: 1px solid #eee; }
        code { background: #f5f5f5; padding: 1px 4px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>Simply Static Fixes</h1>

    <div class="panel">
        <p>Put your exported static sites inside <code>simply-static-fixes/sites</code>. Each site should be its own folder.</p>

        <?php if (empty($sites)): ?>
            <p class="errors">No sites found in <code>sites/</code>.</p>
        <?php else: ?>
            <form method="post">
                <label>
                    Site:
                    <select name="site">
                        <?php foreach ($sites as $site): ?>
                            <option value="<?php echo h($site); ?>" <?php echo $site === $selected_site ? 'selected' : ''; ?>>
                                <?php echo h($site); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($selected_site_url !== ''): ?>
                    <a href="<?php echo h($selected_site_url); ?>" target="_blank" rel="noopener">Open Site</a>
                <?php endif; ?>
                <label>
                    <input type="checkbox" name="rewrite_hosts" <?php echo $rewrite_hosts ? 'checked' : ''; ?>>
                    Strip absolute hosts for <code>/wp-content</code> + <code>/wp-includes</code>
                </label>
                <label>
                    <input type="checkbox" name="apply" <?php echo $apply ? 'checked' : ''; ?>>
                    Apply changes (uncheck for dry run)
                </label>
                <button type="submit">Run Fix</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (is_array($result)): ?>
        <div class="panel">
            <h2>Result<?php echo $selected_site !== '' ? ' for ' . h($selected_site) : ''; ?></h2>
            <?php if (!empty($result['errors'])): ?>
                <div class="errors">
                    <?php foreach ($result['errors'] as $error): ?>
                        <div><?php echo h($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="stats">
                    <div>Files scanned: <?php echo (int)($result['files_scanned'] ?? 0); ?></div>
                    <div>Files changed: <?php echo (int)($result['files_changed'] ?? 0); ?></div>
                    <div>Replacements: <?php echo (int)($result['replacements'] ?? 0); ?></div>
                    <div>Bytes before: <?php echo (int)($result['bytes_before'] ?? 0); ?></div>
                    <div>Bytes after: <?php echo (int)($result['bytes_after'] ?? 0); ?></div>
                </div>

                <?php if (!empty($result['changed_files'])): ?>
                    <h3>Changed Files<?php echo $selected_site !== '' ? ' for ' . h($selected_site) : ''; ?></h3>
                    <div class="files">
                        <?php foreach ($result['changed_files'] as $file): ?>
                            <div><?php echo h($file); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>
