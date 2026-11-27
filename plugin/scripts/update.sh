#!/bin/sh
# Laravel Github deploy — run by the Plugin Manager after an update. data/ is preserved.
exec /bin/sh "$(cd "$(dirname "$0")" && pwd -P)/install.sh"
