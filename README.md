# Simply Static Fixes

This tool post-processes exported Simply Static folders and rewrites paths so the site works offline.

## Versions
- Fixes tool: 0.2.0
- WordPress plugin: 0.8.4 (disabled — use the fixer instead; see header in `add-this/simply-static-portable-paths.php`)

## Structure
```
simply-static-fixes/
  index.php      # Web UI
  fix.php        # Rewrite logic
  add-this/      # Plugin to install in WordPress before export
  sites/         # Put exported sites here (one folder per site)
  reports/       # Reserved (not used yet)
```

## Usage
1. Export with Simply Static using your plugin.
2. Ensure the plugin from `add-this/` is installed in WordPress.
3. Unzip the export and place it under `simply-static-fixes/sites/<site-name>`.
3. Open `simply-static-fixes/index.php` in your local PHP server.
4. Select the site and run a **dry run** first (leave “Apply changes” unchecked).
5. If the stats look right, run again with **Apply changes** checked.

## Options
- **Strip absolute hosts**: rewrites same-origin absolute URLs (canonical host or `.local`) for `wp-content` / `wp-includes` to root-relative first, then applies the correct prefix. External/CDN hosts are left as-is.
- **Apply changes**: unchecked = dry run (no writes).

## Notes
- Home pages should resolve as `./wp-content/...`
- Subpages should resolve as `../wp-content/...`
- JSON blobs (Divi `data-et-multi-view`) are rewritten too.

## References
- [Simply Static](https://simplystatic.com/)
- [Simply Static (WordPress Plugin)](https://wordpress.org/plugins/simply-static/)
