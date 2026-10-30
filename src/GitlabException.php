<?php

namespace GlpiPlugin\Gitlabissues;

use RuntimeException;

/**
 * Error raised by the GitLab client. The "kind" lets callers decide what to do
 * (retry later, stop the run, report a configuration problem...).
 */
final class GitlabException extends RuntimeException
{
    public const NETWORK    = 'network';     // cannot reach GitLab (DNS, TLS, timeout...)
    public const AUTH       = 'auth';        // 401 / 403
    public const NOT_FOUND  = 'not_found';   // 404
    public const RATE_LIMIT = 'rate_limit';  // 429
    public const SERVER     = 'server';      // 5xx
    public const REQUEST    = 'request';     // other 4xx (validation...)
    public const CONFIG     = 'config';      // plugin not configured

    public function __construct(
        string $message,
        private readonly string $kind,
        private readonly int $http_status = 0,
    ) {
        parent::__construct($message, $http_status);
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getHttpStatus(): int
    {
        return $this->http_status;
    }

    /**
     * Errors for which retrying later makes sense and that affect every request
     * (the whole run should stop instead of hammering GitLab).
     */
    public function isGlobal(): bool
    {
        return in_array($this->kind, [self::NETWORK, self::AUTH, self::RATE_LIMIT, self::SERVER, self::CONFIG], true);
    }
}
