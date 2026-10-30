<?php

namespace GlpiPlugin\Gitlabissues;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;
use Toolbox;

/**
 * Minimal GitLab REST API v4 client.
 *
 * - uses GLPI's Guzzle factory, so the GLPI proxy settings are honoured;
 * - every failure is converted to a typed GitlabException;
 * - never logs the token.
 */
class GitlabClient
{
    private const USER_AGENT = 'GLPI-GitlabIssues/' . PLUGIN_GITLABISSUES_VERSION;

    private ClientInterface $http;
    private string $api_url;

    /**
     * @param ClientInterface|null $http Injectable for tests
     */
    public function __construct(string $base_url, private readonly string $token, ?ClientInterface $http = null)
    {
        $base_url = rtrim(trim($base_url), '/');
        if ($base_url === '' || $token === '' || !preg_match('#^https?://#i', $base_url)) {
            throw new GitlabException(__('GitLab URL or token is not configured.', 'gitlabissues'), GitlabException::CONFIG);
        }
        $this->api_url = $base_url . '/api/v4/';
        $this->http    = $http ?? Toolbox::getGuzzleClient([
            'timeout'         => 20,
            'connect_timeout' => 5,
            'http_errors'     => false,
            'headers'         => [
                'User-Agent'    => self::USER_AGENT,
                'Accept'        => 'application/json',
            ],
        ]);
    }

    public static function fromSettings(): self
    {
        $settings = Settings::all();

        return new self($settings['gitlab_url'], $settings['gitlab_token']);
    }

    // ---------------------------------------------------------------- generic

    /**
     * @param array<string, mixed> $options Guzzle options
     *
     * @return array{status: int, headers: array<string, string[]>, data: mixed}
     */
    protected function request(string $method, string $path, array $options = []): array
    {
        $options['headers']['PRIVATE-TOKEN'] = $this->token;
        $options['http_errors']              = false;

        try {
            $response = $this->http->request($method, $this->api_url . ltrim($path, '/'), $options);
        } catch (GuzzleException $e) {
            // Connection refused, DNS failure, TLS error, timeout...
            throw new GitlabException(
                sprintf(__('Cannot reach GitLab: %s', 'gitlabissues'), $this->scrub($e->getMessage())),
                GitlabException::NETWORK
            );
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();
        $data   = $body !== '' ? json_decode($body, true) : null;

        if ($status >= 400) {
            throw $this->buildException($status, $data, $body);
        }

        if ($body !== '' && $data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new GitlabException(
                __('Unexpected (non-JSON) answer from GitLab. Is the URL correct?', 'gitlabissues'),
                GitlabException::REQUEST,
                $status
            );
        }

        return ['status' => $status, 'headers' => $response->getHeaders(), 'data' => $data];
    }

    private function buildException(int $status, mixed $data, string $body): GitlabException
    {
        $detail = '';
        if (is_array($data)) {
            $detail = $data['message'] ?? $data['error'] ?? '';
            if (is_array($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
            }
        }
        $detail = $this->scrub((string) $detail);
        $suffix = $detail !== '' ? ' - ' . $detail : '';

        return match (true) {
            $status === 401, $status === 403 => new GitlabException(
                sprintf(__('GitLab refused access (HTTP %d)%s', 'gitlabissues'), $status, $suffix),
                GitlabException::AUTH,
                $status
            ),
            $status === 404 => new GitlabException(
                sprintf(__('Not found on GitLab (HTTP 404)%s', 'gitlabissues'), $suffix),
                GitlabException::NOT_FOUND,
                $status
            ),
            $status === 429 => new GitlabException(
                __('GitLab rate limit reached (HTTP 429)', 'gitlabissues'),
                GitlabException::RATE_LIMIT,
                $status
            ),
            $status >= 500 => new GitlabException(
                sprintf(__('GitLab server error (HTTP %d)', 'gitlabissues'), $status),
                GitlabException::SERVER,
                $status
            ),
            default => new GitlabException(
                sprintf(__('GitLab rejected the request (HTTP %d)%s', 'gitlabissues'), $status, $suffix),
                GitlabException::REQUEST,
                $status
            ),
        };
    }

    /**
     * Make sure the token never ends up in a message that is displayed or logged.
     */
    private function scrub(string $text): string
    {
        return $this->token !== '' ? str_replace($this->token, '***', $text) : $text;
    }

    /**
     * Project reference for URLs: numeric id, or URL-encoded "group/project" path.
     */
    protected static function projectRef(int|string $project): string
    {
        return ctype_digit((string) $project) ? (string) $project : rawurlencode((string) $project);
    }

    // ------------------------------------------------------------- connection

    /**
     * @return array{version: string, user: string, scopes: string[]|null, name: string}
     */
    public function testConnection(): array
    {
        $version = $this->request('GET', 'version')['data'] ?? [];
        $user    = $this->request('GET', 'user')['data'] ?? [];

        $scopes = null;
        try {
            // GitLab >= 15.5. Not available for every token type: purely informative.
            $info   = $this->request('GET', 'personal_access_tokens/self')['data'] ?? [];
            $scopes = is_array($info['scopes'] ?? null) ? $info['scopes'] : null;
        } catch (GitlabException) {
            $scopes = null;
        }

        return [
            'version' => (string) ($version['version'] ?? '?'),
            'user'    => (string) ($user['username'] ?? '?'),
            'name'    => (string) ($user['name'] ?? ''),
            'scopes'  => $scopes,
        ];
    }

    // --------------------------------------------------------------- projects

    /**
     * Projects in which the token can create issues (Reporter or more).
     *
     * @return array{results: array<int, array{id: int, text: string}>, more: bool}
     */
    public function searchProjects(string $term = '', int $page = 1, int $per_page = 20): array
    {
        $query = [
            'membership'        => 'true',
            'min_access_level'  => 20,
            'archived'          => 'false',
            'simple'            => 'true',
            'order_by'          => 'last_activity_at',
            'per_page'          => $per_page,
            'page'              => max(1, $page),
        ];
        if ($term !== '') {
            $query['search']            = $term;
            $query['search_namespaces'] = 'true';
        }

        $res     = $this->request('GET', 'projects', ['query' => $query]);
        $results = [];
        foreach ((array) $res['data'] as $project) {
            $results[] = [
                'id'   => (int) $project['id'],
                'text' => (string) ($project['path_with_namespace'] ?? $project['name'] ?? $project['id']),
            ];
        }

        $next = $res['headers']['X-Next-Page'][0] ?? '';

        return ['results' => $results, 'more' => $next !== ''];
    }

    /**
     * @return array<string, mixed>
     */
    public function getProject(int|string $project): array
    {
        return (array) $this->request('GET', 'projects/' . self::projectRef($project))['data'];
    }

    // ----------------------------------------------------------------- issues

    /**
     * @param array{title: string, description?: string, labels?: string, confidential?: bool} $data
     *
     * @return array<string, mixed> The created issue (id, iid, web_url...)
     */
    public function createIssue(int|string $project, array $data): array
    {
        $form = [
            'title'       => $data['title'],
            'description' => $data['description'] ?? '',
        ];
        if (($data['labels'] ?? '') !== '') {
            $form['labels'] = $data['labels'];
        }
        if (!empty($data['confidential'])) {
            $form['confidential'] = 'true';
        }

        $issue = (array) $this->request('POST', 'projects/' . self::projectRef($project) . '/issues', [
            'form_params' => $form,
        ])['data'];

        if (!isset($issue['iid'], $issue['id'])) {
            throw new GitlabException(
                __('GitLab did not return the created issue.', 'gitlabissues'),
                GitlabException::REQUEST
            );
        }

        return $issue;
    }

    /**
     * Upload a file to a project and return its Markdown reference.
     *
     * @return array{markdown: string, url: string}
     */
    public function uploadFile(int|string $project, string $path, string $filename): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new GitlabException(
                sprintf(__('Cannot read file %s', 'gitlabissues'), $filename),
                GitlabException::REQUEST
            );
        }

        $res = $this->request('POST', 'projects/' . self::projectRef($project) . '/uploads', [
            'multipart' => [[
                'name'     => 'file',
                'contents' => Utils::streamFor($handle),
                'filename' => $filename,
            ]],
        ]);

        $data = (array) $res['data'];
        if (!isset($data['markdown'])) {
            throw new GitlabException(
                sprintf(__('GitLab did not accept file %s', 'gitlabissues'), $filename),
                GitlabException::REQUEST
            );
        }

        return ['markdown' => (string) $data['markdown'], 'url' => (string) ($data['full_path'] ?? $data['url'] ?? '')];
    }

    /**
     * Fetch several issues of one project in a single request (per_page=100).
     *
     * @param int[] $iids
     *
     * @return array<int, array<string, mixed>> Issues indexed by iid (missing ones are absent)
     */
    public function getIssues(int|string $project, array $iids): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $iids))), 100) as $chunk) {
            // GitLab expects iids[]=1&iids[]=2 (Guzzle would produce iids[0]=1).
            $query = http_build_query(['state' => 'all', 'per_page' => 100]);
            foreach ($chunk as $iid) {
                $query .= '&iids%5B%5D=' . $iid;
            }
            $res = $this->request('GET', 'projects/' . self::projectRef($project) . '/issues?' . $query);
            foreach ((array) $res['data'] as $issue) {
                $found[(int) $issue['iid']] = $issue;
            }
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    public function getIssue(int|string $project, int $iid): array
    {
        return (array) $this->request('GET', 'projects/' . self::projectRef($project) . '/issues/' . $iid)['data'];
    }

    /**
     * Merge requests that close the issue when merged (informative, best effort).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getClosingMergeRequests(int|string $project, int $iid): array
    {
        try {
            return (array) $this->request(
                'GET',
                'projects/' . self::projectRef($project) . '/issues/' . $iid . '/closed_by'
            )['data'];
        } catch (GitlabException) {
            return [];
        }
    }
}
