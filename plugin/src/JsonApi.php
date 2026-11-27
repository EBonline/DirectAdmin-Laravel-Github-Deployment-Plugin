<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * Shared request handling of the user and admin JSON APIs (api.raw?action=…).
 * Reads are GET requests; writes are POST requests carrying `csrf` and a base64url
 * JSON `payload`. Every outcome, including failures, is a JSON response.
 */
abstract class JsonApi
{
    public function __construct(protected Request $req, protected Csrf $csrf)
    {
    }

    /** @return array<string, callable():array> */
    abstract protected function reads(): array;

    /** @return array<string, callable(array):array> */
    abstract protected function writes(): array;

    public function handle(): string
    {
        try {
            $action = $this->req->query('action');
            $reads = $this->reads();
            $writes = $this->writes();
            if ($action === 'csrf') {
                return Json::ok(['csrf' => $this->csrf->token()]);
            }
            if (isset($reads[$action])) {
                return Json::ok($reads[$action]());
            }
            if (isset($writes[$action])) {
                return Json::ok($writes[$action]($this->guardPost()));
            }
            throw new ApiError(404, 'unknown_action', 'Unknown action.');
        } catch (ApiError $e) {
            return Json::error($e);
        } catch (GitHubException $e) {
            return Json::error(new ApiError(502, 'github', $e->getMessage()));
        } catch (\InvalidArgumentException $e) {
            return Json::error(new ApiError(400, 'bad_request', $e->getMessage()));
        } catch (\Throwable $e) {
            return Json::error(new ApiError(500, 'server', $e->getMessage()));
        }
    }

    /** @return array the decoded payload of a valid POST request */
    private function guardPost(): array
    {
        if (!$this->req->isPost || ($this->req->input('csrf') === '' && $this->req->input('payload') === '')) {
            throw new ApiError(
                400,
                'no_post_data',
                'The request arrived without its data. This happens when it is larger than DirectAdmin allows (about 120 KB), '
                . 'or when DirectAdmin does not pass POST data to plugin scripts. If it keeps happening, ask your hosting administrator.',
            );
        }
        if (!$this->csrf->verify($this->req->input('csrf'))) {
            throw new ApiError(403, 'csrf', 'Your session token has expired. Reload the page and try again.');
        }
        $payload = $this->req->payload();
        if ($payload === null) {
            throw new ApiError(400, 'bad_payload', 'The request data could not be read. Reload the page and try again.');
        }
        return $payload;
    }

    protected static function str(array $p, string $key): string
    {
        $v = $p[$key] ?? '';
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
