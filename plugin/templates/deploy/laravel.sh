$CREATE_RELEASE()

cd $DEPLOY_RELEASE_DIRECTORY

$DEPLOY_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

if [ -f package.json ]; then
    $REQUIRE_NODE()
    npm ci
    npm run build
fi

$DEPLOY_PHP artisan storage:link
$DEPLOY_PHP artisan migrate --force
$DEPLOY_PHP artisan optimize

$ACTIVATE_RELEASE()

$RESTART_QUEUES()
