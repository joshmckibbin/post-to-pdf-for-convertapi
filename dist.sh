#!/bin/bash

PLUGIN_SLUG="post-to-pdf-for-convertapi"

# Create the build directory if it doesn't exist
mkdir -p build/"$PLUGIN_SLUG"

# Copy the plugin files to the build directory, excluding files listed in .distignore and the build directory itself.
# vendor/ is rebuilt below without dev dependencies (phpcs etc.).
rsync -a --exclude-from=.distignore --exclude=build --exclude=vendor ./ build/"$PLUGIN_SLUG"/

# Install production dependencies only.
cp composer.json composer.lock build/"$PLUGIN_SLUG"/
composer install --working-dir=build/"$PLUGIN_SLUG" --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader || exit 1
rm build/"$PLUGIN_SLUG"/composer.json build/"$PLUGIN_SLUG"/composer.lock

# Extract the version number from the main plugin file.
PLUGIN_VERSION=$(sed -n 's/^[[:space:]*]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "build/$PLUGIN_SLUG/$PLUGIN_SLUG.php" | head -n 1)

if [ -z "$PLUGIN_VERSION" ]; then
	echo "Could not read the Version header from $PLUGIN_SLUG.php" >&2
	exit 1
fi

# Change to the build directory to create the zip file.
cd build
zip -r "$PLUGIN_SLUG-v$PLUGIN_VERSION.zip" "$PLUGIN_SLUG"
unzip -l "$PLUGIN_SLUG-v$PLUGIN_VERSION.zip"

cd ..
rm -rf build/"$PLUGIN_SLUG"
echo "Distribution package created: build/$PLUGIN_SLUG-v$PLUGIN_VERSION.zip"
