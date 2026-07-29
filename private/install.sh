#!/bin/sh

set -eu

# Blueprint runs install scripts only from the configured data.directory.
# Copy bundled starter assets into the writable, publicly served extension
# filesystem. Uploaded files are stored here by the controller as well.
source_directory="{root/public}/uploads"
target_directory="{root/fs}/uploads"

if [ -d "$source_directory" ]; then
    mkdir -p "$target_directory"
    cp -a "$source_directory/." "$target_directory/"
fi
