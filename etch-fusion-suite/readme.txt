=== Bricks to Etch Migration ===
Contributors: tobiashaas
Tags: migration, bricks, etch
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.12.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-time migration tool for converting Bricks Builder websites to Etch PageBuilder with complete automation.

== Description ==

**Bricks to Etch Migration** is a comprehensive WordPress plugin designed to automatically migrate websites from Bricks Builder to Etch PageBuilder. This plugin handles the complete migration process including content, styles, custom fields, and dynamic data conversion.

= Key Features =

* **Complete Automation** - No manual steps required
* **One-time Use** - Install, use, uninstall
* **Key-Based Migration** - Elegant migration URLs with embedded authentication
* **Full Dynamic Data Conversion** - All Bricks tags converted to Etch format
* **Zero Data Loss** - Everything migrates automatically
* **Custom Fields Support** - ACF, MetaBox integration
* **Media Migration** - Images, videos, documents with proper associations
* **CSS Conversion** - Flat vanilla CSS to nested CSS
* **Progress Tracking** - Real-time migration progress
* **Error Handling** - Comprehensive error reporting with solutions
* **Resume Capability** - Handle large migrations that may take hours

= What Gets Migrated =

* **Posts & Pages** - All Bricks content converted to Etch Gutenberg blocks
* **Media Files** - Images, videos, documents with proper associations
* **CSS Classes** - Global classes converted to Etch format
* **Custom Fields** - ACF, MetaBox field values
* **Custom Post Types** - Automatic detection and registration
* **Dynamic Data** - Bricks tags converted to Etch format
* **Post Meta** - All relevant meta data preserved

= Migration Process =

1. **Validation** - Check required plugins and API connection
2. **Custom Post Types** - Export and register CPTs
3. **ACF Field Groups** - Export and import field groups
4. **MetaBox Configurations** - Export and import configs
5. **Media Files** - Download and upload all media with associations
6. **CSS Classes** - Convert and import styles
7. **Posts & Content** - Convert and create posts
8. **Finalization** - Complete migration and cleanup

= Requirements =

* **Source Site** - WordPress with Bricks Builder
* **Target Site** - WordPress with Etch PageBuilder
* **Custom Field Plugins** - ACF, MetaBox (JetEngine not supported in V0.2.0)
* **PHP 7.4+** - Required for modern WordPress features
* **WordPress 5.0+** - Required for Gutenberg support

= Installation =

1. Upload the plugin files to `/wp-content/plugins/bricks-etch-migration/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Install the plugin on both source and target sites
4. Configure API settings in the admin dashboard
5. Start the migration process

= Frequently Asked Questions =

= Does this plugin work with all Bricks Builder versions? =

Yes, the plugin is designed to work with all versions of Bricks Builder that store content in the `_bricks_page_content_2` meta field.

= What happens to Bricks-specific elements like sliders? =

Bricks-specific elements (sliders, accordions, etc.) are converted to their HTML/CSS representation. You'll need to recreate these elements manually in Etch using Etch's native components.

= Can I migrate custom fields from ACF to MetaBox? =

Yes, the plugin supports cross-plugin migration. ACF fields can be migrated to MetaBox and vice versa, though some field types may require manual adjustment.

= Is the migration reversible? =

No, this is a one-way migration. Always backup your site before starting the migration process.

= How long does the migration take? =

Migration time depends on the size of your site. Small sites (1-10 pages) typically take 1-5 minutes, while large sites (100+ pages) may take 30+ minutes.

= What if the migration fails? =

The plugin includes comprehensive error handling and logging. Check the migration logs for specific error codes and solutions. You can resume the migration from where it left off.

== Screenshots ==

1. Migration Dashboard - Main interface for starting and monitoring migration
2. Progress Tracking - Real-time progress updates with step-by-step status
3. Error Handling - Comprehensive error reporting with solutions
4. Validation Results - Pre-migration validation and plugin detection

== Changelog ==

= 0.1.0 =
* Initial development version
* Plugin foundation and authentication system
* Admin interface and dashboard
* API endpoints for source/target communication
* Error handling and logging system
* Progress tracking and resume capability

== Upgrade Notice ==

= 0.1.0 =
Initial development version. This is a development release and should not be used in production.

== Support ==

For support, please visit the [GitHub repository](https://github.com/your-username/bricks-etch-migration) or create an issue.

== Privacy Policy ==

This plugin does not collect, store, or transmit any personal data. All migration data remains on your own servers.
