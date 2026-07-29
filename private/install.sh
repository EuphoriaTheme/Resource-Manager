#!/bin/sh

set -eu

source_directory="{root/public}/uploads"
target_directory="{root}/storage/extensions/{identifier}"

if [ -d "$source_directory" ] && [ -w "$target_directory" ]; then
    cp -a "$source_directory/." "$target_directory/"
else
    echo "Starter assets were not copied: $target_directory is not writable." >&2
fi
