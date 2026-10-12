#!/usr/bin/env bash
# Idempotent: makes this checkout drivable. Installs PHP 8.4 when only an
# older php exists (Ubuntu, via the ondrej PPA, needs root), then
# `composer install`. In cloud sessions GitHub zipball downloads may be
# refused (403); composer then falls back to git clones, except for
# phpstan/phpstan, whose zip is rebuilt from its git tag and seeded into
# composer's cache.
set -euo pipefail
repo="$(cd "$(dirname "$0")/../../../.." && pwd)"
export COMPOSER_ALLOW_SUPERUSER=1

if ! command -v php8.4 >/dev/null; then
  [ "$(id -u)" = 0 ] || { echo "php8.4 missing and not root: install PHP >= 8.4.1 with intl, mbstring, posix"; exit 1; }
  curl -fsSL 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C' | gpg --dearmor > /etc/apt/keyrings/ondrej-php.gpg
  echo "deb [signed-by=/etc/apt/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu $(. /etc/os-release; echo "$VERSION_CODENAME") main" > /etc/apt/sources.list.d/ondrej-php.list
  apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq php8.4-cli php8.4-intl php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip
fi

composer=(php8.4 "$(command -v composer)")
cd "$repo"
if ! "${composer[@]}" install --prefer-source --no-interaction --quiet 2>/dev/null; then
  echo "composer install failed (likely GitHub zipball 403); seeding phpstan from git and retrying"
  ref="$(php8.4 -r '$l = json_decode(file_get_contents("composer.lock"), true); foreach ($l["packages-dev"] as $p) { if ($p["name"] === "phpstan/phpstan") { echo $p["dist"]["reference"]; } }')"
  work="$(mktemp -d)"
  git init -q "$work/src"
  git -C "$work/src" fetch -q --depth 1 https://github.com/phpstan/phpstan "$ref"
  git -C "$work/src" archive --format=zip --prefix="phpstan-phpstan-${ref:0:7}/" FETCH_HEAD -o "$work/phpstan.zip"
  key="$(php8.4 -r "echo sha1('https://api.github.com/repos/phpstan/phpstan/zipball/$ref');")"
  mkdir -p "$HOME/.cache/composer/files/phpstan/phpstan"
  cp "$work/phpstan.zip" "$HOME/.cache/composer/files/phpstan/phpstan/$key.zip"
  rm -rf "$work"
  "${composer[@]}" install --prefer-source --no-interaction --quiet
fi
echo "setup done; run doctor.sh"
