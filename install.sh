#!/bin/bash

set -e

# Install Imagick using the package manager available on the host. PHP
# versions are detected first so versioned package names can be preferred.
php_versions=""
if command -v php >/dev/null 2>&1; then
    php_versions="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
fi

for php_binary in /usr/bin/php[0-9].[0-9]; do
    if [ -x "$php_binary" ]; then
        php_versions="$php_versions $(basename "$php_binary" | sed 's/^php//')"
    fi
done

packages=""
package_manager=""

if command -v apt-get >/dev/null 2>&1 && command -v apt-cache >/dev/null 2>&1; then
    package_manager="apt"
    for php_version in $php_versions; do
        package="php$php_version-imagick"
        if apt-cache show "$package" >/dev/null 2>&1; then
            packages="$packages $package"
        fi
    done
    if [ -z "$packages" ] && apt-cache show php-imagick >/dev/null 2>&1; then
        packages="php-imagick"
    fi
elif command -v dnf >/dev/null 2>&1; then
    package_manager="dnf"
    if dnf info php-imagick >/dev/null 2>&1; then
        packages="php-imagick"
    fi
elif command -v yum >/dev/null 2>&1; then
    package_manager="yum"
    if yum info php-imagick >/dev/null 2>&1; then
        packages="php-imagick"
    fi
elif command -v apk >/dev/null 2>&1; then
    package_manager="apk"
    for php_version in $php_versions; do
        apk_package="php$(echo "$php_version" | tr -d .)-pecl-imagick"
        if apk search --no-cache --exact "$apk_package" 2>/dev/null | grep -q .; then
            packages="$packages $apk_package"
        fi
    done
    if [ -z "$packages" ] && apk search --no-cache --exact php-pecl-imagick 2>/dev/null | grep -q .; then
        packages="php-pecl-imagick"
    fi
elif command -v pacman >/dev/null 2>&1; then
    package_manager="pacman"
    if pacman -Si php-imagick >/dev/null 2>&1; then
        packages="php-imagick"
    fi
elif command -v zypper >/dev/null 2>&1; then
    package_manager="zypper"
    if zypper --non-interactive search --match-exact php-imagick 2>/dev/null | grep -q php-imagick; then
        packages="php-imagick"
    elif zypper --non-interactive search --match-exact php8-imagick 2>/dev/null | grep -q php8-imagick; then
        packages="php8-imagick"
    fi
fi

if [ -n "$packages" ]; then
    if [ "$(id -u)" -eq 0 ]; then
        privilege=""
    elif command -v sudo >/dev/null 2>&1; then
        privilege="sudo"
    else
        echo "Skipping Imagick installation: root or sudo access is required." >&2
        packages=""
    fi
fi

if [ -n "$packages" ]; then
    case "$package_manager" in
        apt)
            $privilege apt-get update
            $privilege apt-get install -y $packages
            ;;
        dnf)
            $privilege dnf install -y $packages
            ;;
        yum)
            $privilege yum install -y $packages
            ;;
        apk)
            $privilege apk add $packages
            ;;
        pacman)
            $privilege pacman -S --needed --noconfirm $packages
            ;;
        zypper)
            $privilege zypper --non-interactive install $packages
            ;;
    esac
fi

# Keep bundled public assets in Blueprint's public filesystem. The trailing
# /. copies hidden files too and overwrites changed files on every install.
if [ -d "{root/public}/uploads" ]; then
    mkdir -p "{root/fs}/uploads"
    cp -a "{root/public}/uploads/." "{root/fs}/uploads/"
fi
