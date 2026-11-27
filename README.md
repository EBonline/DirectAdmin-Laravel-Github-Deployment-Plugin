# Laravel Github deploy: update channel

Published by the Release workflow from `v0.4.0` (commit `d576b57623c574f2aae5b7d7ad12487c027fcb15`). Do not commit here:
every stable release replaces this branch.

DirectAdmin's Plugin Manager reads `version.txt` (`version_url` in plugin.conf) and
downloads `laravel_github_deploy.tar.gz` (`update_url`) from this branch.
