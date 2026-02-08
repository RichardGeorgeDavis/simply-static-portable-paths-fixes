# Simply Static Fixes

This tool post-processes exported Simply Static folders and rewrites paths so the site works offline.

## Versions
- Fixes tool: 0.3.3
- WordPress plugin: 0.8.4 (disabled — use the fixer instead; see header in `add-this/simply-static-portable-paths.php`)

## Structure
```
simply-static-fixes/
  index.php      # Web UI
  fix.php        # Rewrite logic
  add-this/      # Plugin to install in WordPress before export
  sites/         # Put exported sites here (one folder per site)
  reports/       # Run version counter (increments on apply)
```

## Usage
1. Export with Simply Static.
2. (Optional) The plugin in `add-this/` is currently disabled due to buggy exports — rely on the fixer instead.
3. Unzip the export and place it under `simply-static-fixes/sites/<site-name>`.
4. Open `simply-static-fixes/index.php` in your local PHP server.
5. Select the site and run a **dry run** first (leave “Apply changes” unchecked).
6. If the stats look right, run again with **Apply changes** checked.

## Options
- **Rewrite .local absolute URLs**: converts `http(s)://*.local/...` and `//*.local/...` to relative paths with the correct `./` or `../` prefix. External/CDN hosts are left as-is.
- **Apply changes**: unchecked = dry run (no writes).

## Notes
- Root-relative paths are rewritten to the correct `./` or `../` prefix for each file depth.
- Home pages should resolve as `./...`
- Subpages should resolve as `../...` (repeated per depth)
- Escaped slashes in JSON (`\/`) stay escaped.
- Files inside `wp-content/` and `wp-includes/` are not path-rewritten (to avoid breaking asset-relative URLs). Only `.local` absolute URLs are rewritten there.

## References
- [Simply Static](https://simplystatic.com/)
- [Simply Static (WordPress Plugin)](https://wordpress.org/plugins/simply-static/)
