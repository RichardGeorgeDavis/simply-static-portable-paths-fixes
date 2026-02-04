# Contributing

Thanks for contributing.

## Local Setup
1. Place exported sites in `sites/<site-name>/`.
2. Open `index.php` in a local PHP server.
3. Run dry-run first; apply changes after verifying.

## Guidelines
- Keep changes focused and documented.
- Add tests or sample fixtures if you add new rewrite rules.
- Update `CHANGELOG.md` with a short entry.

## Code Style
- Prefer clear, explicit code.
- Avoid clever regex unless documented.
- Keep rewrites deterministic and idempotent.

## Reporting Issues
Please include:
- The broken URL snippet.
- Which page it appears on (home or subpage).
- Whether it appears in JSON blobs (e.g. `data-et-multi-view`).
