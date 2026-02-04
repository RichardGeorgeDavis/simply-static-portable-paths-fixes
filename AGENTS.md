# Simply Static Fixes — Agent Notes

## Purpose
This folder contains a **post-export repair tool** for Simply Static archives, plus the **plugin file** to install in WordPress before export.

## Key Files
- `index.php`: Web UI for running a dry-run or applying fixes to a static site folder.
- `fix.php`: Path rewrite logic applied to exported files.
- `sites/`: Place exported sites here (one folder per site).
- `add-this/simply-static-portable-paths.php`: The plugin file to install in WordPress **before** exporting.

## Workflow
1. Install plugin from `add-this/` in WordPress.
2. Run Simply Static export.
3. Unzip export into `sites/<site-name>/`.
4. Open `index.php` and run a dry run, then apply if results are correct.

## Expectations
- Home pages should resolve as `./wp-content/...`.
- Subpages should resolve as `../wp-content/...`.
- JSON blobs (e.g., Divi `data-et-multi-view`) should be rewritten too.

## Notes
- The fixer normalizes weird patterns like `.//wp-content/`, `././wp-content/`, and `//wp-content/`.
- It can optionally strip absolute hosts before rewriting.
