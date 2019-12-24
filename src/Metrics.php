<?php
namespace ReadMe;

use Closure;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use PackageVersions\Versions;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;

class Metrics
{
    const PACKAGE_NAME = 'readme/metrics';
    const METRICS_API = 'https://metrics.readme.io';

    /** @var string */
    private $api_key;

    /** @var bool */
    private $development_mode = false;

    /** @var array */
    private $blacklist = [];

    /** @var array */
    private $whitelist = [];

    /** @var Closure */
    private $group;

    /** @var Client */
    private $client;

    public function __construct(string $api_key, Closure $group, array $options = [])
    {
        $this->api_key = $api_key;
        $this->group = $group;
        $this->development_mode = array_key_exists('development_mode', $options)
            ? (bool)$options['development_mode']
            : false;

        $this->blacklist = array_key_exists('blacklist', $options) ? $options['blacklist'] : [];
        $this->whitelist = array_key_exists('whitelist', $options) ? $options['whitelist'] : [];

        $this->client = new Client([
            'base_uri' => self::METRICS_API,

            // If the request takes longer than 2 seconds, let it go.
            // @todo allow this to be configured
            'timeout' => 2,

            // @todo specify a custom user agent for the library
        ]);
    }

    /**
     * @param Request $request
     * @param Response $response
     * @throws MetricsException
     */
    public function track(Request $request, $response): void
    {
        $payload = $this->constructPayload($request, $response);

        try {
            // @todo maybe handle bad token 401 errors?
            $response = $this->client->post('/request', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->api_key . ':')
                ],
                'json' => [
                    // @todo maybe change this to a queueing model like in readme-node
                    $payload
                ]
            ]);
        } catch (\Exception $e) {
            if ($this->development_mode) {
                throw $e;
            }
        }

        // If we're in development mode, silently ignore all API dealings.
        if (!$this->development_mode) {
            return;
        }

        $json = (string) $response->getBody();
        if ($json === 'OK') {
            return;
        }

        $json = json_decode($json);
        if (!isset($json->errors)) {
            return;
        }

        $ex = new MetricsException(str_replace($json->_message, $json->name, $json->message));
        $ex->setErrors((array)$json->errors);
        throw $ex;
    }

    /**
     * @param Request $request
     * @param Response $response
     * @return array
     */
    public function constructPayload(Request $request, $response): array
    {
        $request_start = LARAVEL_START;
        $package_version = Versions::getVersion(self::PACKAGE_NAME);
        $group = ($this->group)($request);

        if (!array_key_exists('id', $group)) {
            throw new \TypeError('Metrics grouping function did not return an array with an id present.');
        } elseif (empty($group['id'])) {
            throw new \TypeError('Metrics grouping function must not return an empty id.');
        }

        return [
            'group' => $group,
            'clientIPAddress' => $request->ip(),
            'development' => $this->development_mode,
            'request' => [
                'log' => [
                    'creator' => [
                        'name' => self::PACKAGE_NAME,
                        'version' => $package_version,
                        'comment' => PHP_OS_FAMILY . '/php v' . PHP_VERSION
                    ],
                    'entries' => [
                        [
                            'pageref' => $request->url(),
                            'startedDateTime' => date('c', $request_start),
                            'time' => (microtime(true) - $request_start) * 1000,
                            'request' => $this->processRequest($request),
                            'response' => $this->processResponse($response)
                        ]
                    ]
                ]
            ]
        ];
    }

    private function processRequest(Request $request): array
    {
        // Since Laravel (currently as of 6.8.0) dumps $_GET and $_POST into `->query` and `->request` instead of
        // putting $_GET into only `->query` and $_POST` into `->request`, we have no easy way way to dump only POST
        // data into `postData`. So because of that, we're eschewing that and manually reconstructing our potential POST
        // payload into an array here.
        $params = array_replace_recursive($_POST, $_FILES);
        if (!empty($this->blacklist)) {
            $params = $this->excludeDataFromBlacklist($params);
        } elseif (!empty($this->whitelist)) {
            $params = $this->excludeDataNotInWhitelist($params);
        }

        return [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'httpVersion' => $_SERVER['SERVER_PROTOCOL'],
            'headers' => static::convertHeaderBagToArray($request->headers),
            'queryString' => static::convertObjectToArray($_GET),
            'postData' => [
                'mimeType' => 'application/json',
                'params' => static::convertObjectToArray($params)
            ]
        ];
    }

    /**
     * @param Response $response
     * @return array
     */
    private function processResponse($response): array
    {
        if ($response instanceof JsonResponse) {
            $body = $response->getData();

            if (!empty($this->blacklist)) {
                // @todo
            } elseif (!empty($this->whitelist)) {
                // @todo
            }
        } else {
            $body = $response->getContent();
        }

        $status_code = $response->getStatusCode();

        return [
            'status' => $status_code,
            'statusText' => isset(Response::$statusTexts[$status_code])
                ? Response::$statusTexts[$status_code]
                : 'Unknown status',
            'headers' => static::convertHeaderBagToArray($response->headers),
            'content' => [
                'text' => json_encode($body),
                'size' => $response->headers->get('Content-Length', 0),
                'mimeType' => $response->headers->get('Content-Type')
            ]
        ];
    }

    /**
     * Convert a HeaderBag into an acceptable nested array for the Metrics API.
     *
     * @param HeaderBag $headers
     * @return array
     */
    protected static function convertHeaderBagToArray(HeaderBag $headers): array
    {
        $output = [];
        foreach ($headers->all() as $name => $values) {
            foreach ($values as $value) {
                // If the header is empty, don't worry about it.
                if ($value === '') {
                    continue;
                }

                $output[] = [
                    'name' => $name,
                    'value' => $value
                ];
            }
        }

        return $output;
    }

    /**
     * Convert a key/value object-style array into an acceptable nested array for the Metrics API.
     *
     * @param array $input
     * @return array
     */
    protected static function convertObjectToArray(array $input): array
    {
        return array_map(function ($key) use ($input) {
            if (isset($input[$key]['tmp_name'])) {
                $file = $input[$key];
                return [
                    'name' => $key,
                    'value' => file_get_contents($file['tmp_name']),
                    'fileName' => $file['name'],
                    'contentType' => MimeTypes::getDefault()->guessMimeType($file['tmp_name'])
                ];
            }

            return [
                'name' => $key,
                // Only bother to JSON encode non-scalar data.
                'value' => (is_scalar($input[$key])) ? $input[$key] : json_encode($input[$key])
            ];
        }, array_keys($input));
    }

    private function excludeDataFromBlacklist($data = []): array
    {
        Arr::forget($data, $this->blacklist);
        return $data;
    }

    private function excludeDataNotInWhitelist($data = []): array
    {
        $ret = [];
        foreach ($this->whitelist as $key) {
            if (isset($data[$key])) {
                $ret[$key] = $data[$key];
            }
        }

        return $ret;
    }
}
