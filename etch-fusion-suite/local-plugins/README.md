# Local Commercial Plugins

Store commercial plugin ZIPs in this folder for local development only. These files are gitignored and must never be committed.

## Required Plugins

- Bricks Theme: https://bricksbuilder.io/
- Etch Plugin: https://etchwp.com/
- Etch Theme: https://etchwp.com/

## Optional Plugins

- Frames Plugin: https://getframes.io/
- Automatic.css: https://automaticcss.com/
- Bricks Child Theme (if your workflow requires it)
- WPvivid Backup Plugin (for backup import flow)

## Naming Examples

Versioned filenames are supported. Examples:

- `bricks.2.2.zip`
- `frames-1.5.11.zip`
- `automatic.css-4.0.0-beta-2.zip`
- `etch-1.0.2.zip`
- `etch-theme-0.0.2.zip`
- `bricks-child-1.0.0.zip`
- `wpvivid-backuprestore-0.9.99.zip`

Run `npm run setup:commercial-plugins` to detect versions and generate normalized `*-latest.zip` files used by `.wp-env.json`.

## License Keys

License keys are loaded from `.env`. Copy `.env.example` to `.env` and populate values:

- `BRICKS_LICENSE_KEY` (required)
- `ETCH_LICENSE_KEY` (required)
- `FRAMES_LICENSE_KEY` (optional)
- `ACSS_LICENSE_KEY` (optional)

See `.env.example` for the full template.
