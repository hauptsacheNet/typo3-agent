#!/bin/bash
#
# Build a TER-ready extension zip.
# Bundles required PHP libraries into Resources/Private/PHP/vendor so the
# extension also runs on non-composer TYPO3 installations.
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
EXTENSION_KEY="agent"

VERSION=$(php -r "
    \$_EXTKEY = '$EXTENSION_KEY';
    include '$PROJECT_DIR/ext_emconf.php';
    echo \$EM_CONF[\$_EXTKEY]['version'];
")

if [ -z "$VERSION" ]; then
    echo "ERROR: Could not determine version from ext_emconf.php" >&2
    exit 1
fi

BUILD_DIR="$PROJECT_DIR/.build"
DIST_DIR="$PROJECT_DIR/dist"
EXTENSION_DIR="$BUILD_DIR/$EXTENSION_KEY"

echo "Building TER package for $EXTENSION_KEY version $VERSION"

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR" "$DIST_DIR" "$EXTENSION_DIR"

echo "Copying extension files..."
for dir in Classes Configuration Documentation Resources; do
    if [ -d "$PROJECT_DIR/$dir" ]; then
        cp -R "$PROJECT_DIR/$dir" "$EXTENSION_DIR/"
    fi
done

for file in ext_emconf.php ext_localconf.php ext_tables.sql ext_conf_template.txt composer.json README.md LICENSE; do
    if [ -f "$PROJECT_DIR/$file" ]; then
        cp "$PROJECT_DIR/$file" "$EXTENSION_DIR/"
    fi
done

# Strip any dev leftovers before running composer install fresh
rm -rf "$EXTENSION_DIR/Resources/Private/PHP/vendor" 2>/dev/null || true
rm -f  "$EXTENSION_DIR/Resources/Private/PHP/composer.lock" 2>/dev/null || true

echo "Installing bundled libraries..."
cd "$EXTENSION_DIR/Resources/Private/PHP"
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction

# Packages TYPO3 core already provides
echo "Cleaning up redundant packages..."
rm -rf vendor/psr/log 2>/dev/null || true

# Ballast in vendored libraries
echo "Removing unnecessary vendor files..."
rm -rf vendor/phpoffice/*/tests 2>/dev/null || true
rm -rf vendor/phpoffice/*/docs 2>/dev/null || true
rm -rf vendor/phpoffice/*/samples 2>/dev/null || true
rm -rf vendor/smalot/pdfparser/tests 2>/dev/null || true
rm -rf vendor/smalot/pdfparser/doc 2>/dev/null || true

composer dump-autoload --optimize --classmap-authoritative --no-interaction

cd "$EXTENSION_DIR"

ZIP_FILE="$DIST_DIR/${EXTENSION_KEY}_${VERSION}.zip"
rm -f "$ZIP_FILE"
echo "Creating zip file: $ZIP_FILE"
zip -r "$ZIP_FILE" . -x "*.git*" -x "*.DS_Store"

echo ""
echo "TER package created successfully!"
echo "  File: $ZIP_FILE"
echo "  Size: $(du -h "$ZIP_FILE" | cut -f1)"
