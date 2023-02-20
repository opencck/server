<?php

use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Server\FormParser;
use Amp\Http\HttpStatus;

use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketGateway;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\Server\OriginWebsocketHandshakeHandler;
use Amp\Websocket\WebsocketClient;

use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

use Amp\Socket;
use Amp\ByteStream\WritableResourceStream;
use Revolt\EventLoop;
use function Amp\trapSignal;

require __DIR__ . '/vendor/autoload.php';

/**
 * @param int|string $val
 * @return int
 */
function return_bytes(int|string $val): int {
    $val = trim($val);

    $units = ['g' => 1_073_741_824, 'm' => 1_048_576, 'k' => 1024];
    $unit = strtolower($val[strlen($val) - 1]);

    return intval($val) * $units[$unit];
}

// logger
$logHandler = new StreamHandler(new WritableResourceStream(STDOUT));
$logHandler->setFormatter(new ConsoleFormatter());
$logHandler->setLevel(Level::Info);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

// server
$server = new SocketHttpServer($logger);

// router
$errorHandler = new DefaultErrorHandler();
$router = new Router($server, $errorHandler);

// http route (GET)
$router->addRoute(
    method: 'GET',
    uri: '/',
    requestHandler: new ClosureRequestHandler(function (Request $request): Response {
        return new Response(
            HttpStatus::OK,
            ['content-type' => 'text/html; charset=utf-8'],
            '
            <!DOCTYPE html>
            <html lang="en">
                <body>
                    <script>
                        const ws = new WebSocket(`ws://${location.host}/ws`);
                        ws.onopen = function () { console.log("Connected"); }
                        ws.onmessage = function (messageEvent) { console.log(messageEvent.data); }
                    </script>
                    <script>
                        const eventSource = new EventSource("/events");
                        const eventList = document.createElement("ol");
                        document.body.appendChild(eventList);
                        eventSource.addEventListener("notification", function (e) {
                            const element = document.createElement("li");
                            element.textContent = "Message: " + e.data;
                            eventList.appendChild(element);
                        });
                    </script>
                </body>
            </html>'
        );
    })
);

// http route (POST)
$router->addRoute(
    method: 'POST',
    uri: '/',
    requestHandler: new ClosureRequestHandler(function (Request $request): Response {
        $form = FormParser\parseForm($request, return_bytes(ini_get('post_max_size')));
        return new Response(
            HttpStatus::OK,
            ['content-type' => 'text/html; charset=utf-8'],
            '<!DOCTYPE html><html lang="en"><body><pre>' . print_r($form->getValues()) . '</pre></body></html>'
        );
    })
);

// websocket route
$gateway = new WebsocketClientGateway();
$router->addRoute(
    method: 'GET',
    uri: '/ws',
    requestHandler: new Websocket(
        logger: $logger,
        handshakeHandler: new OriginWebsocketHandshakeHandler([
            'http://' . ($_ENV['HOST_MACHINE_IP'] ?? '127.0.0.1') . ':8080',
            'http://localhost:8080',
            'http://[::1]:8080',
        ]),
        clientHandler: new class ($gateway) implements WebsocketClientHandler {
            public function __construct(private readonly WebsocketGateway $gateway = new WebsocketClientGateway()) {
            }

            public function handleClient(WebsocketClient $client, Request $request, Response $response): void {
                $this->gateway->addClient($client);

                while ($message = $client->receive()) {
                    $this->gateway->broadcast($message->read());
                }
            }
        }
    )
);

// SSE route
$router->addRoute(
    method: 'GET',
    uri: '/events',
    requestHandler: new ClosureRequestHandler(function (Request $request): Response {
        // We stream the response here, one event every 500 ms.
        return new Response(
            status: HttpStatus::OK,
            headers: ['content-type' => 'text/event-stream; charset=utf-8'],
            body: new \Amp\ByteStream\ReadableIterableStream(
                (function () {
                    for ($i = 0; $i < 30; $i++) {
                        \Amp\delay(0.5);
                        yield "event: notification\ndata: Event {$i}\n\n";
                    }
                })()
            )
        );
    })
);

// set static files from directory for fallback
$dir = __DIR__ . DIRECTORY_SEPARATOR . 'public';
if (!is_dir($dir)) {
    mkdir($dir);
}
$router->setFallback(new DocumentRoot($server, $errorHandler, $dir));

// send micro time every 1 second to all WS clients
EventLoop::repeat(1, static function () use ($gateway) {
    $gateway->broadcast(microtime(true));
});

// handle event loop errors
EventLoop::setErrorHandler(function ($e) use ($logger) {
    $logger->error($e->getMessage());
});

// start server
try {
    $server->expose(new Socket\InternetAddress('0.0.0.0', 8080));
    //$server->expose(new Socket\InternetAddress('[::]', 8080));
    $server->start($router, $errorHandler);

    if (defined('SIGINT') && defined('SIGTERM')) {
        // Await SIGINT or SIGTERM to be received.
        $signal = trapSignal([SIGINT, SIGTERM]);
        $logger->info(\sprintf('Received signal %d, stopping HTTP server', $signal));
        $server->stop();
    } else {
        EventLoop::run();
    }
} catch (Throwable $e) {
    $logger->error($e->getMessage());
}
