#!/usr/bin/env bash
# Read-only: is this checkout worth driving? Prints one line per check and
# exits non-zero on the first hard failure.
set -u
repo="$(cd "$(dirname "$0")/../../../.." && pwd)"
php="${PHP:-php8.4}"
fail() { echo "FAIL $*"; exit 1; }
ok() { echo "ok   $*"; }

command -v "$php" >/dev/null || fail "$php not on PATH (the default php may be older; see Launch in SKILL.md)"
"$php" -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' || fail "$php is $("$php" -r 'echo PHP_VERSION;'), need >= 8.4.1"
ok "php $("$php" -r 'echo PHP_VERSION;') ($php)"
for ext in intl mbstring posix; do
  "$php" -r "exit(extension_loaded('$ext') ? 0 : 1);" || fail "extension $ext missing"
done
ok "extensions intl mbstring posix"
[ -f "$repo/vendor/autoload.php" ] || fail "vendor/ missing: run composer install (see Launch)"
"$php" -r "require '$repo/vendor/autoload.php';" >/dev/null || fail "vendor/ does not load under $php (composer platform check?)"
ok "vendor/ loads"
tools="$("$php" -r "require '$repo/vendor/autoload.php'; echo implode(',', array_map(fn(\$t) => \$t->getName(), (new NeuronCodingTools\CodingToolkit(sys_get_temp_dir()))->tools()));")"
[ "$tools" = "read,bash,edit,write,ls,find,grep" ] || fail "toolkit offers '$tools', expected read,bash,edit,write,ls,find,grep"
ok "toolkit offers $tools"
if command -v rg >/dev/null; then ok "ripgrep $(rg --version | head -1 | cut -d' ' -f2) (auto search = rg)"; else echo "warn ripgrep absent (auto search = php; --search=rg unavailable)"; fi
command -v setsid >/dev/null && ok "setsid present (bash timeouts kill the process group)" || echo "warn setsid absent (bash timeouts kill only the shell)"
left="$(ls "$repo/.verify/sandboxes" 2>/dev/null | wc -l)"
[ "$left" = 0 ] && ok "no stale sandboxes" || echo "warn $left sandbox(es) registered from earlier runs: run cleanup.sh"
