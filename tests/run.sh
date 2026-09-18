#!/bin/sh
# No WordPress needed. Each suite loads the real code against stubs and a real
# temporary directory; test-webp-files.php also needs cwebp, and skips without it.
d=$(dirname "$0")
fail=0
for t in test-srcset test-webp-files test-webp-urls; do
  echo "— $t"
  php "$d/$t.php" || fail=1
  echo
done
exit $fail
