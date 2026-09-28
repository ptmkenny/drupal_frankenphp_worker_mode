<?php

declare(strict_types=1);

namespace Drupal\frankenphp_profiler\StackMiddleware;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Profiles single requests with XHProf and sends the runs to XHGui.
 *
 * DDEV's xhprof auto_prepend_file runs once per FrankenPHP worker, not once
 * per request, so profiling starts here in handle() and stops in terminate(),
 * which the worker runner calls after every request. Only requests carrying
 * the frankenphp_profile=1 cookie or the X-Frankenphp-Profile: 1 header are
 * profiled. Every main request gets a Server-Timing header.
 */
class XhprofProfilerMiddleware implements HttpKernelInterface, TerminableInterface {

  /**
   * Cookie that triggers profiling when set to 1.
   */
  public const string TRIGGER_COOKIE = 'frankenphp_profile';

  /**
   * Request header that triggers profiling when set to 1.
   */
  public const string TRIGGER_HEADER = 'X-Frankenphp-Profile';

  /**
   * Response header carrying the ID of the profiled run.
   */
  public const string RUN_HEADER = 'X-Frankenphp-Profile-Run';

  /**
   * XHGui import endpoint, as used by DDEV's xhgui collector.
   */
  private const string UPLOAD_URL = 'http://xhgui/run/import';

  /**
   * Fallback file, the same one DDEV's xhgui collector writes to.
   */
  private const string FALLBACK_FILE = '/tmp/xhgui.data.jsonl';

  /**
   * Request attribute holding the start time from hrtime().
   */
  private const string ATTRIBUTE_START = '_frankenphp_profiler_start';

  /**
   * Request attribute holding the run ID while XHProf is running.
   */
  private const string ATTRIBUTE_RUN = '_frankenphp_profiler_run';

  /**
   * Server keys copied into the run metadata.
   *
   * In worker mode $_SERVER also holds the container environment (database
   * credentials, API keys), so only these keys are sent to XHGui.
   */
  private const array SERVER_KEYS = [
    'HTTP_ACCEPT',
    'HTTP_HOST',
    'HTTP_USER_AGENT',
    'QUERY_STRING',
    'REQUEST_METHOD',
    'REQUEST_TIME',
    'REQUEST_TIME_FLOAT',
    'REQUEST_URI',
    'SERVER_NAME',
  ];

  public function __construct(
    protected HttpKernelInterface $httpKernel,
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  #[\Override]
  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    if ($type !== self::MAIN_REQUEST) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    $start = hrtime(TRUE);
    $request->attributes->set(self::ATTRIBUTE_START, microtime(TRUE));
    $triggered = $this->isTriggered($request);
    $run = NULL;
    if ($triggered && $this->profilerAvailable()) {
      $run = bin2hex(random_bytes(8));
      $request->attributes->set(self::ATTRIBUTE_RUN, $run);
      xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
    }

    try {
      $response = $this->httpKernel->handle($request, $type, $catch);
    }
    catch (\Throwable $e) {
      // terminate() may not run for this request, so do not leave XHProf
      // running into the next request of the worker.
      $this->stopProfiling($request);
      throw $e;
    }

    $duration = (hrtime(TRUE) - $start) / 1e6;
    $response->headers->set('Server-Timing', sprintf('drupal;dur=%.1f', $duration), FALSE);
    if ($run !== NULL) {
      $response->headers->set(self::RUN_HEADER, $run);
    }
    elseif ($triggered) {
      $response->headers->set(self::TRIGGER_HEADER, 'unavailable');
    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  #[\Override]
  public function terminate(Request $request, Response $response): void {
    // Terminate the inner kernel first so terminate-phase work is profiled.
    if ($this->httpKernel instanceof TerminableInterface) {
      $this->httpKernel->terminate($request, $response);
    }

    $run = $request->attributes->get(self::ATTRIBUTE_RUN);
    $profile = $this->stopProfiling($request);
    if (is_string($run) && $profile !== NULL) {
      $this->save($run, $profile, $request);
    }
  }

  /**
   * Whether XHProf is loaded in this PHP process.
   */
  protected function profilerAvailable(): bool {
    return extension_loaded('xhprof');
  }

  /**
   * Whether the request asks to be profiled.
   */
  private function isTriggered(Request $request): bool {
    return $request->cookies->get(self::TRIGGER_COOKIE) === '1'
      || $request->headers->get(self::TRIGGER_HEADER) === '1';
  }

  /**
   * Stops XHProf if it was started for this request.
   *
   * @return array<string, array<string, int>>|null
   *   The XHProf data, or NULL if this request was not profiled.
   */
  private function stopProfiling(Request $request): ?array {
    if (!$request->attributes->has(self::ATTRIBUTE_RUN)) {
      return NULL;
    }
    $request->attributes->remove(self::ATTRIBUTE_RUN);
    return xhprof_disable();
  }

  /**
   * Sends a run to XHGui, falling back to the DDEV collector file.
   *
   * @param string $run
   *   The run ID returned in the response header.
   * @param array<string, array<string, int>> $profile
   *   The XHProf data.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The profiled request.
   */
  private function save(string $run, array $profile, Request $request): void {
    $start = $request->attributes->get(self::ATTRIBUTE_START);
    if (!is_float($start)) {
      $start = microtime(TRUE);
    }
    $sec = (int) $start;
    $usec = (int) (($start - $sec) * 1e6);
    $server = array_intersect_key($request->server->all(), array_flip(self::SERVER_KEYS));

    // Format of \Xhgui\Profiler\ProfilingData::getProfilingData().
    $data = [
      'profile' => $profile,
      'meta' => [
        'url' => $request->getRequestUri(),
        'get' => $request->query->all(),
        'env' => [],
        'SERVER' => $server,
        'simple_url' => $request->getPathInfo(),
        'request_ts_micro' => ['sec' => $sec, 'usec' => $usec],
        'request_ts' => ['sec' => $sec, 'usec' => 0],
        'request_date' => date('Y-m-d', $sec),
      ],
    ];
    $json = json_encode($data, JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR);

    $token = getenv('XHGUI_UPLOAD_TOKEN');
    try {
      $upload = $this->httpClient->request('POST', self::UPLOAD_URL, [
        'query' => is_string($token) && $token !== '' ? ['token' => $token] : [],
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'body' => $json,
        'timeout' => 3,
      ]);
      // XHGui assigns its own run ID and does not display env, so log the
      // mapping from the response header value to the XHGui run.
      $result = json_decode((string) $upload->getBody(), TRUE);
      $this->logger->info('Run @run: @method @url is XHGui run/view?id=@id', [
        '@run' => $run,
        '@method' => $request->getMethod(),
        '@url' => $request->getRequestUri(),
        '@id' => is_array($result) && is_string($result['id'] ?? NULL) ? $result['id'] : 'unknown',
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('XHGui upload of run @run failed, appended to @file instead: @message', [
        '@run' => $run,
        '@file' => self::FALLBACK_FILE,
        '@message' => $e->getMessage(),
      ]);
      file_put_contents(self::FALLBACK_FILE, $json . "\n", FILE_APPEND | LOCK_EX);
    }
  }

}
