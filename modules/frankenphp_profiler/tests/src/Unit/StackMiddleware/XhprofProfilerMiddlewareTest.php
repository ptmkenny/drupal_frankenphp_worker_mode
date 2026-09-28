<?php

declare(strict_types=1);

namespace Drupal\Tests\frankenphp_profiler\Unit\StackMiddleware;

use Drupal\frankenphp_profiler\StackMiddleware\XhprofProfilerMiddleware;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Tests the dev-only XHProf profiler middleware without XHProf loaded.
 */
#[Group('frankenphp_profiler')]
#[CoversClass(XhprofProfilerMiddleware::class)]
final class XhprofProfilerMiddlewareTest extends UnitTestCase {

  /**
   * Returns a middleware whose XHProf extension is reported as missing.
   */
  private function middleware(HttpKernelInterface $kernel, ?ClientInterface $client = NULL): XhprofProfilerMiddleware {
    return new class(
      $kernel,
      $client ?? $this::createStub(ClientInterface::class),
      $this::createStub(LoggerInterface::class),
    ) extends XhprofProfilerMiddleware {

      /**
       * {@inheritdoc}
       */
      #[\Override]
      protected function profilerAvailable(): bool {
        return FALSE;
      }

    };
  }

  /**
   * An untriggered request passes through and gets only Server-Timing.
   */
  public function testUntriggeredRequest(): void {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->expects($this->once())->method('handle')->willReturn(new Response('ok'));

    $response = $this->middleware($kernel)->handle(Request::create('/node'));

    $this::assertSame('ok', $response->getContent());
    $this::assertMatchesRegularExpression('/^drupal;dur=\d+\.\d$/', (string) $response->headers->get('Server-Timing'));
    $this::assertFalse($response->headers->has(XhprofProfilerMiddleware::RUN_HEADER));
    $this::assertFalse($response->headers->has(XhprofProfilerMiddleware::TRIGGER_HEADER));
  }

  /**
   * A triggered request without XHProf is marked unavailable, not profiled.
   */
  public function testTriggeredWithoutXhprof(): void {
    $kernel = $this::createStub(HttpKernelInterface::class);
    $kernel->method('handle')->willReturn(new Response());

    $by_cookie = Request::create('/', cookies: [XhprofProfilerMiddleware::TRIGGER_COOKIE => '1']);
    $by_header = Request::create('/', server: ['HTTP_X_FRANKENPHP_PROFILE' => '1']);
    foreach ([$by_cookie, $by_header] as $request) {
      $response = $this->middleware($kernel)->handle($request);
      $this::assertSame('unavailable', $response->headers->get(XhprofProfilerMiddleware::TRIGGER_HEADER));
      $this::assertFalse($response->headers->has(XhprofProfilerMiddleware::RUN_HEADER));
    }
  }

  /**
   * Sub-requests are passed through untouched.
   */
  public function testSubRequestUntouched(): void {
    $kernel = $this::createStub(HttpKernelInterface::class);
    $kernel->method('handle')->willReturn(new Response());

    $response = $this->middleware($kernel)->handle(Request::create('/'), HttpKernelInterface::SUB_REQUEST);

    $this::assertFalse($response->headers->has('Server-Timing'));
  }

  /**
   * Terminate reaches the inner kernel and uploads nothing when unprofiled.
   */
  public function testTerminatePassesThrough(): void {
    $kernel = $this->createMockForIntersectionOfInterfaces([HttpKernelInterface::class, TerminableInterface::class]);
    $kernel->expects($this->once())->method('terminate');
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $this->middleware($kernel, $client)->terminate(Request::create('/'), new Response());
  }

}
