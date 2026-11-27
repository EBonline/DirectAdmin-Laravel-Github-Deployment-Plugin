#!/bin/sh
# Laravel Github deploy — run as root by the Plugin Manager on uninstall.
# Deployed sites and users' ~/.laravel-github-deploy directories are left untouched.
rm -f /etc/cron.d/laravel_github_deploy
echo "Laravel Github deploy uninstalled. Deployed sites keep running; users' ~/.laravel-github-deploy folders were not removed."
exit 0
