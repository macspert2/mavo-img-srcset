#!/bin/sh
# No WordPress needed: the plugin is loaded against the stubs in test-srcset.php
# and run over a real temporary uploads directory, so the file_exists() checks
# that decide which srcset entries survive are exercised for real.
exec php "$(dirname "$0")/test-srcset.php"
