#!/bin/sh

set -eu

source_directory="{root/public}/uploads"
target_directory="{root}/storage/extensions/{identifier}/uploads"

if [ -d "$source_directory" ]; then
    mkdir -p "$target_directory"
    cp -a "$source_directory/." "$target_directory/"
fi
