<?php

use Amp\PHPUnit\AsyncTestCase;

use Amp\Http\Server;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Response;

use Amp\Http\Client\HttpException;

use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketGateway;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\Server\EmptyWebsocketHandshakeHandler;
use Amp\Websocket\WebsocketClient;

use Amp\Websocket\Client\Rfc6455ConnectionFactory;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\Client\WebsocketConnectException;
use Amp\Websocket\Parser\Rfc6455ParserFactory;

use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

use Amp\Socket;
use Amp\ByteStream\WritableResourceStream;
use Amp\CompositeException;
use Revolt\EventLoop;
use function Amp\delay;

/**
 * WsServerTest
 */
final class WsServerTest extends AsyncTestCase {
    /**
     * Server port
     */
    const PORT = 1339;
    /**
     * @var SocketHttpServer
     */
    private SocketHttpServer $httpServer;

    /**
     * @var Rfc6455Connector
     */
    private Rfc6455Connector $wsClient;

    /**
     * @var WebsocketClientGateway
     */
    private WebsocketClientGateway $gateway;

    protected function setUp(): void {
        parent::setUp();

        $logHandler = new StreamHandler(new WritableResourceStream(STDOUT));
        $logHandler->setFormatter(new ConsoleFormatter());
        $logHandler->setLevel(Level::Info);

        $logger = new Logger('server');
        $logger->pushHandler($logHandler);

        $this->gateway = new WebsocketClientGateway();
        $websocketRequestHandler = new Websocket(
            logger: $logger,
            handshakeHandler: new EmptyWebsocketHandshakeHandler(),
            clientHandler: new class ($this->gateway) implements WebsocketClientHandler {
                public function __construct(private readonly WebsocketGateway $gateway = new WebsocketClientGateway()) {
                }

                public function handleClient(
                    WebsocketClient $client,
                    Server\Request $request,
                    Response $response
                ): void {
                    $this->gateway->addClient($client);

                    while ($message = $client->receive()) {
                        $this->gateway->broadcast($message->read());
                    }
                }
            }
        );

        $this->httpServer = new SocketHttpServer($logger);
        $this->httpServer->expose(new Socket\InternetAddress('127.0.0.1', self::PORT));
        $this->httpServer->expose(new Socket\InternetAddress('[::]', self::PORT));

        try {
            $this->httpServer->start($websocketRequestHandler, new DefaultErrorHandler());
        } catch (Throwable $e) {
            $logger->error($e->getMessage());
        }

        $this->wsClient = new Rfc6455Connector(
            new Rfc6455ConnectionFactory(
                parserFactory: new Rfc6455ParserFactory(messageSizeLimit: PHP_INT_MAX, frameSizeLimit: PHP_INT_MAX)
            )
        );
    }

    /**
     * @throws CompositeException
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->httpServer->stop();
    }

    /**
     * @throws WebsocketConnectException
     * @throws HttpException
     */
    public function testConnectionWithSending() {
        $connection = $this->wsClient->connect(new WebsocketHandshake('ws://127.0.0.1:' . self::PORT . '/'));
        EventLoop::queue(function () use ($connection) {
            $message = $connection->receive();
            $this->assertEquals('Hello, Sending!', $message->read());
            $connection->close();
        });
        EventLoop::queue(function () use ($connection) {
            $connection->send('Hello, Sending!');
        });
        delay(0.1);
    }

    /**
     * @throws WebsocketConnectException
     * @throws HttpException
     */
    public function testConnectionWithReceipt() {
        $connection = $this->wsClient->connect(new WebsocketHandshake('ws://127.0.0.1:' . self::PORT . '/ws'));
        EventLoop::queue(function () use ($connection) {
            $message = $connection->receive();
            $this->assertEquals('Hello, Receipt!', $message->read());
            $connection->close();
        });
        EventLoop::queue(function () {
            $this->gateway->broadcast('Hello, Receipt!');
        });
        delay(0.1);
    }
}
