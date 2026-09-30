#!/bin/bash

PLUGIN_SLUG="post-to-pdf-for-convertapi"

# Create the build directory if it doesn't exist
mkdir -p build/"$PLUGIN_SLUG"

# Copy the plugin files to the build directory, excluding files listed in .distignore and the build directory itself.
rsync -a --exclude-from=.distignore --exclude=build ./ build/"$PLUGIN_SLUG"/

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
