# Simply Static Fixes

This tool post-processes exported Simply Static folders and rewrites paths so the site works offline.

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
- **Strip absolute hosts**: rewrites `http(s)://host/wp-content/...` and `//host/wp-content/...` to root-relative first, then applies the correct prefix. Useful when exports still contain absolute URLs.
- **Apply changes**: unchecked = dry run (no writes).

## Notes
- Home pages should resolve as `./wp-content/...`
- Subpages should resolve as `../wp-content/...`
- JSON blobs (Divi `data-et-multi-view`) are rewritten too.
