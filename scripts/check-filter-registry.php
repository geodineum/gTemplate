<?php
declare(strict_types=1);
/**
 * check-filter-registry.php — drift-checker for FILTER_REGISTRY.md.
 *
 * FILTER_REGISTRY.md is the hand-maintained source of truth for the public
 * `gtemplate_*` hooks child themes build against ("a hook in code but not listed
 * here is private"). It is curated prose — argument shapes, defaults, and return
 * types read better than anything extractable — so it is NOT regenerated. This
 * asserts it stays honest: every hook the registry documents must still be fired
 * somewhere in the theme code. A renamed or removed public hook silently breaks
 * every child theme; this fails the build instead.
 *
 * Direction that matters: registry → code. A documented hook missing from code is
 * drift (FAIL). The reverse (a hook fired in code but not documented) is allowed
 * by the registry's own policy — those are private — and is reported only as an
 * informational count.
 *
 *   php scripts/check-filter-registry.php
 *
 * Exit 0 = in sync; exit 1 = a documented hook is gone from code. No WordPress
 * bootstrap needed — pure text extraction.
 */

$ROOT = dirname(__DIR__);
$REG  = "$ROOT/FILTER_REGISTRY.md";
if (!is_readable($REG)) {
    fwrite(STDERR, "check-filter-registry: FILTER_REGISTRY.md not found at $REG\n");
    exit(2);
}

// ---- registry truth: the hook-NAME column only --------------------------------
// Table rows are `| `gtemplate_x` | ... |`. Capture strictly the first-column
// backtick token so default values (`gtemplate_face`) and example keys
// (`gtemplate_face_0_label`) that appear inside later columns' prose are NOT
// mistaken for hooks.
$documented = [];
foreach (preg_split('/\r?\n/', (string) file_get_contents($REG)) as $ln) {
    if (preg_match('/^\|\s*`(gtemplate_[a-z0-9_]+)`/', $ln, $m)) {
        $documented[$m[1]] = true;
    }
}
$documented = array_keys($documented);
sort($documented);

// ---- code truth: hooks actually fired -----------------------------------------
// apply_filters(...) / do_action(...) with a literal 'gtemplate_*' first arg.
$fired = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') continue;
    $src = (string) file_get_contents($file->getPathname());
    if (preg_match_all('/\b(?:apply_filters|do_action)\s*\(\s*[\'"](gtemplate_[a-z0-9_]+)[\'"]/', $src, $mm)) {
        foreach ($mm[1] as $h) $fired[$h] = true;
    }
}
$fired = array_keys($fired);
sort($fired);

// ---- diff ---------------------------------------------------------------------
$stale   = array_values(array_diff($documented, $fired)); // documented but not fired → drift
$private = array_values(array_diff($fired, $documented)); // fired but not documented → private (ok)

fwrite(STDOUT, "gTemplate FILTER_REGISTRY.md drift-check\n");
fwrite(STDOUT, sprintf("  documented public hooks : %d\n", count($documented)));
fwrite(STDOUT, sprintf("  hooks fired in code     : %d\n", count($fired)));
if ($private) {
    fwrite(STDOUT, sprintf("  private (fired, undocumented, allowed): %d — %s\n",
        count($private), implode(', ', $private)));
}

if (!$stale) {
    fwrite(STDOUT, "\n\xE2\x9C\x93 in sync — every documented hook is still fired in the code.\n");
    exit(0);
}
fwrite(STDOUT, "\n\xE2\x9C\x97 " . count($stale) . " documented hook(s) no longer fired in code:\n");
foreach ($stale as $h) fwrite(STDOUT, "  - $h\n");
fwrite(STDOUT, "\nRemove or rename the row in FILTER_REGISTRY.md to match the code (code wins), then re-run.\n");
exit(1);
