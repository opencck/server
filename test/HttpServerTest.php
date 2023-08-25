<?php

use Amp\Http\Server\Driver\ConnectionLimitingClientFactory;
use Amp\Http\Server\Driver\ConnectionLimitingServerSocketFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\PHPUnit\AsyncTestCase;

use Amp\Http\Server;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Server\FormParser;

use Amp\Http\Client;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpClient;
use Amp\Http\HttpStatus;

use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Sync\LocalSemaphore;
use Monolog\Level;
use Monolog\Logger;

use Amp\Socket;
use Amp\ByteStream\WritableResourceStream;
use Amp\ByteStream\StreamException;
use Amp\CompositeException;

/**
 * HttpServerTest
 */
final class HttpServerTest extends AsyncTestCase {
    /**
     * Server port
     */
    private const PORT = 1338;

    private const DEFAULT_CONNECTION_LIMIT = 1000;
    private const DEFAULT_CONNECTIONS_PER_IP_LIMIT = 10;

    /**
     * @var SocketHttpServer
     */
    private SocketHttpServer $httpServer;

    /**
     * @var HttpClient
     */
    private HttpClient $httpClient;

    /**
     * @var Logger
     */
    private Logger $logger;

    /**
     * @throws Socket\SocketException
     */
    protected function setUp(): void {
        parent::setUp();

        $logHandler = new StreamHandler(new WritableResourceStream(STDOUT));
        $logHandler->setFormatter(new ConsoleFormatter());
        $logHandler->setLevel(Level::Info);

        $this->logger = new Logger('server');
        $this->logger->pushHandler($logHandler);

        $serverSocketFactory = new ConnectionLimitingServerSocketFactory(
            new LocalSemaphore(self::DEFAULT_CONNECTION_LIMIT),
        );
        $clientFactory = new ConnectionLimitingClientFactory(
            new SocketClientFactory($this->logger),
            $this->logger,
            self::DEFAULT_CONNECTIONS_PER_IP_LIMIT,
        );

        $this->httpServer = new SocketHttpServer($this->logger, $serverSocketFactory, $clientFactory);
        $this->httpServer->expose(new Socket\InternetAddress('127.0.0.1', self::PORT));
        $this->httpServer->expose(new Socket\InternetAddress('[::]', self::PORT));

        $this->httpClient = HttpClientBuilder::buildDefault();
    }

    /**
     * @throws CompositeException
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->httpServer->stop();
    }

    /**
     * @see \Amp\Http\Client\Request
     * @throws CompositeException
     * @throws StreamException|Throwable
     */
    public function testRequest() {
        $this->httpServer->start(
            new ClosureRequestHandler(function (): Response {
                return new Response(HttpStatus::OK, ['content-type' => 'text/plain; charset=utf-8'], 'Hello, World!');
            }),
            new DefaultErrorHandler()
        );
        $httpRequest = $this->httpClient->request(new Client\Request('http://127.0.0.1:' . self::PORT, 'GET'));
        $this->assertEquals('Hello, World!', $httpRequest->getBody()->read());
    }

    /**
     * @see Amp\Http\Server\FormParser\Form
     * @throws Throwable
     * @throws CompositeException
     * @throws StreamException
     */
    public function testFormParser() {
        $this->httpServer->start(
            new ClosureRequestHandler(function (Server\Request $request): Response {
                $form = FormParser\Form::fromRequest($request);
                $values = $form->getValues();
                return new Response(
                    HttpStatus::OK,
                    ['content-type' => 'text/plain; charset=utf-8'],
                    $values['test'][0]
                );
            }),
            new DefaultErrorHandler()
        );

        $request = new Client\Request('http://127.0.0.1:' . self::PORT, 'POST');
        $request->setHeader('content-type', 'multipart/form-data; boundary=----WebKitFormBoundaryIQs02VJQOtAe5jpv');
        $request->setBody(
            "------WebKitFormBoundaryIQs02VJQOtAe5jpv\r\nContent-Disposition: form-data; name=\"test\"\r\n\r\nHello, World!\r\n------WebKitFormBoundaryIQs02VJQOtAe5jpv--\r\n"
        );
        $httpRequest = $this->httpClient->request($request);
        $this->assertEquals('Hello, World!', $httpRequest->getBody()->read());
    }

    /**
     * @see \Amp\Http\ServerRouter
     * @tip Router have side effects
     * @throws Throwable
     * @throws CompositeException
     */
    public function testRouter() {
        $router = new Router($this->httpServer, $this->logger, new DefaultErrorHandler());
        $genericRequestHandler = new ClosureRequestHandler(function (): Response {
            return new Response(HttpStatus::OK, ['content-type' => 'text/plain; charset=utf-8'], 'Hello, World!');
        });
        $router->addRoute('GET', '/', $genericRequestHandler);
        $router->addRoute('GET', '/test', $genericRequestHandler);
        $router->addRoute(
            'POST',
            '/post',
            new ClosureRequestHandler(function (Server\Request $request): Response {
                return new Response(
                    HttpStatus::OK,
                    ['content-type' => 'text/plain; charset=utf-8'],
                    $request->getBody()->read()
                );
            })
        );
        $errorHandler = new DefaultErrorHandler();
        $router->setFallback(
            new DocumentRoot($this->httpServer, $errorHandler, __DIR__ . DIRECTORY_SEPARATOR . 'resources')
        );
        $this->httpServer->start($router, $errorHandler);

        $httpRequest = $this->httpClient->request(new Client\Request('http://127.0.0.1:' . self::PORT, 'GET'));
        $this->assertEquals('Hello, World!', $httpRequest->getBody()->read(), 'Error on GET /');

        $httpRequest = $this->httpClient->request(
            new Client\Request('http://127.0.0.1:' . self::PORT . '/test', 'GET')
        );
        $this->assertEquals('Hello, World!', $httpRequest->getBody()->read(), 'Error on GET /test');

        $request = new Client\Request('http://127.0.0.1:' . self::PORT . '/post', 'POST');
        $request->setBody('Hello, World!');
        $httpRequest = $this->httpClient->request($request);
        $this->assertEquals('Hello, World!', $httpRequest->getBody()->read(), 'Error on POST data');

        $httpRequest = $this->httpClient->request(
            new Client\Request('http://127.0.0.1:' . self::PORT . '/fallbackTest.txt', 'GET')
        );
        $this->assertEquals("Hello, World!\n", $httpRequest->getBody()->read(), 'Error on get static file');
    }
}
