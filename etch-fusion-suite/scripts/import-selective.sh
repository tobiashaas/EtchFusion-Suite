#!/bin/bash
# Selective import: posts, postmeta, terms, and Bricks options from production dump.
# Converts prefix plhw_ -> wp_ and imports into the Bricks (cli) container.

set -e

SOURCE_SQL="$1"
if [ -z "$SOURCE_SQL" ] || [ ! -f "$SOURCE_SQL" ]; then
  echo "Usage: $0 <path-to-sql-dump>"
  exit 1
fi

WORK_DIR=$(mktemp -d)
echo "Working directory: $WORK_DIR"

echo "==> Step 1: Extracting posts table..."
# Extract plhw_posts CREATE TABLE + INSERT statements
sed -n '/^-- Tabellenstruktur.*`plhw_posts`/,/^-- Tabellenstruktur.*`plhw_postmeta`/{ /^-- Tabellenstruktur.*`plhw_postmeta`/!p }' "$SOURCE_SQL" > "$WORK_DIR/posts_raw.sql"

echo "==> Step 2: Extracting postmeta table..."
sed -n '/^-- Tabellenstruktur.*`plhw_postmeta`/,/^-- Tabellenstruktur.*`plhw_rank_math/{ /^-- Tabellenstruktur.*`plhw_rank_math/!p }' "$SOURCE_SQL" > "$WORK_DIR/postmeta_raw.sql"

echo "==> Step 3: Extracting terms tables..."
sed -n '/^-- Tabellenstruktur.*`plhw_terms`/,/^-- Tabellenstruktur.*`plhw_usermeta`/{ /^-- Tabellenstruktur.*`plhw_usermeta`/!p }' "$SOURCE_SQL" > "$WORK_DIR/terms_raw.sql"

echo "==> Step 4: Extracting Bricks options..."
# Extract the full options table structure + data, then we'll filter
sed -n '/^-- Tabellenstruktur.*`plhw_options`/,/^-- Tabellenstruktur.*`plhw_pmxe/{ /^-- Tabellenstruktur.*`plhw_pmxe/!p }' "$SOURCE_SQL" > "$WORK_DIR/options_raw.sql"

echo "==> Step 5: Converting prefix plhw_ -> wp_..."
for f in posts_raw.sql postmeta_raw.sql terms_raw.sql options_raw.sql; do
  sed 's/plhw_/wp_/g' "$WORK_DIR/$f" > "$WORK_DIR/${f%.sql}_wp.sql"
done

echo "==> Step 6: Building final import SQL..."
cat > "$WORK_DIR/import.sql" << 'HEADER'
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;
HEADER

# Posts: drop and recreate
echo "-- Posts" >> "$WORK_DIR/import.sql"
echo "DROP TABLE IF EXISTS wp_posts;" >> "$WORK_DIR/import.sql"
cat "$WORK_DIR/posts_raw_wp.sql" >> "$WORK_DIR/import.sql"

# Postmeta: drop and recreate
echo "-- Postmeta" >> "$WORK_DIR/import.sql"
echo "DROP TABLE IF EXISTS wp_postmeta;" >> "$WORK_DIR/import.sql"
cat "$WORK_DIR/postmeta_raw_wp.sql" >> "$WORK_DIR/import.sql"

# Terms: drop and recreate
echo "-- Terms" >> "$WORK_DIR/import.sql"
echo "DROP TABLE IF EXISTS wp_terms;" >> "$WORK_DIR/import.sql"
echo "DROP TABLE IF EXISTS wp_termmeta;" >> "$WORK_DIR/import.sql"
echo "DROP TABLE IF EXISTS wp_term_taxonomy;" >> "$WORK_DIR/import.sql"
echo "DROP TABLE IF EXISTS wp_term_relationships;" >> "$WORK_DIR/import.sql"
cat "$WORK_DIR/terms_raw_wp.sql" >> "$WORK_DIR/import.sql"

# Options: only Bricks-related, inserted into existing wp_options
echo "-- Bricks options (selective)" >> "$WORK_DIR/import.sql"
echo "DELETE FROM wp_options WHERE option_name LIKE 'bricks_%';" >> "$WORK_DIR/import.sql"
echo "DELETE FROM wp_options WHERE option_name LIKE 'acss_%';" >> "$WORK_DIR/import.sql"
echo "DELETE FROM wp_options WHERE option_name LIKE 'frames_%';" >> "$WORK_DIR/import.sql"
# Extract only INSERT lines from options and filter for bricks/acss/frames
grep "^INSERT" "$WORK_DIR/options_raw_wp.sql" > "$WORK_DIR/options_inserts.sql" 2>/dev/null || true
cat "$WORK_DIR/options_inserts.sql" >> "$WORK_DIR/import.sql"

echo "SET FOREIGN_KEY_CHECKS = 1;" >> "$WORK_DIR/import.sql"

FILESIZE=$(du -h "$WORK_DIR/import.sql" | cut -f1)
echo "==> Import SQL ready: $FILESIZE"
echo "==> File: $WORK_DIR/import.sql"

# Copy into container and import
echo "==> Step 7: Copying to Docker container..."
CONTAINER=$(docker ps --format '{{.Names}}' | grep -i 'cli' | grep -v 'tests' | head -1)
echo "    Container: $CONTAINER"
docker cp "$WORK_DIR/import.sql" "$CONTAINER:/tmp/import.sql"

echo "==> Step 8: Importing into Bricks database..."
docker exec "$CONTAINER" wp db query --allow-root < "$WORK_DIR/import.sql"

echo "==> Step 9: Verifying..."
docker exec "$CONTAINER" wp eval --allow-root '
$pages = wp_count_posts("page");
$classes = get_option("bricks_global_classes", array());
$class_count = is_array($classes) ? count($classes) : (is_string($classes) ? "string" : "unknown");
echo "Pages: " . $pages->publish . " published\n";
echo "Global classes: " . $class_count . "\n";
'

echo ""
echo "==> Done! Clean up: rm -rf $WORK_DIR"
