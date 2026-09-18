#!/bin/sh
# No WordPress needed. Both suites load the real code against stubs and a real
# temporary directory; test-webp-files.php also needs cwebp, and skips without it.
d=$(dirname "$0")
fail=0
echo "— srcset rendering"
php "$d/test-srcset.php"     || fail=1
echo
echo "— webp sidecar generation"
php "$d/test-webp-files.php" || fail=1
exit $fail
