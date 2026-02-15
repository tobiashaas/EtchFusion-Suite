# Local WPvivid Backups

Store WPvivid backup files in this folder for local import workflows. These files can contain sensitive production content and are gitignored.

## WPvivid Custom Content Backup Guide

1. Install WPvivid Backup Plugin on the live Bricks site.
2. Open `WPvivid Backup -> Backup & Restore`.
3. Choose `Custom Backup`.
4. Select only these items:
   - `wp_posts`
   - `wp_postmeta`
   - `wp_options`
   - `uploads`
5. Do not select:
   - users
   - plugins
   - themes
6. Click `Backup Now`.
7. Download all generated backup files. Large backups are often split into multiple parts.

## Multi-Part Backup Notes

WPvivid may split backups into files such as:

- `example.com_wpvivid-abc123_2025-01-10-15-30_backup_db.part001.zip`
- `example.com_wpvivid-abc123_2025-01-10-15-30_backup_db.part002.zip`

All parts must be present before import. Missing part files will cause restore failures.

## File Naming Patterns

Typical WPvivid backup part names include:

- `*_wpvivid-*_*_backup_*.part*.zip`
- `site_wpvivid-<backup-id>_<date>_backup_<scope>.part001.zip`

Use `npm run backup:list` to list detected backups and `npm run backup:info -- <backup-id>` for details.
