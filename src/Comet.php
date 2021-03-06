<?php
declare(strict_types=1);

namespace Comet;

use Workerman\Worker;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use Slim\Psr7\Headers;
use Slim\Psr7\Factory\UriFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use Comet\Middleware\JsonBodyParserMiddleware;

class Comet
{
    public const VERSION = '0.3.2';

    private static $app;
    private $host;
    private $port;
    private $logger;
    private $status;

    public function __construct(array $config = null)    
    {
        $this->host = $config['host'] ?? 'localhost';                     
        $this->port = $config['port'] ?? 80;                     
        $this->logger = $config['logger'] ?? null;  
        
        self::$app = AppFactory::create();   
        
        // TODO Load ALL middlewares from /middleware folder OR enable only that was sent via config
        self::$app->add(new JsonBodyParserMiddleware());
    }

    // Magic call to any of the Slim App methods like add, addMidleware, handle, run, etc...
    // See the full list of available methods: https://github.com/slimphp/Slim/blob/4.x/Slim/App.php
    public function __call (string $name, array $arguments) 
    {
        return self::$app->$name(...$arguments);
    }

    // Handle EACH request and form response
    private static function _handle(WorkermanRequest $request)
    {
        $req = new Request(
            $request->method(),
            (new UriFactory())->createUri($request->path()),
            (new Headers())->setHeaders($request->header()),
            $request->cookie(),
            [], // FIXME $_SERVER ?
            (new StreamFactory)->createStream($request->rawBody())
        );

        // FIXME If there no handler for specified route - it does not return any response at all!
        $ret = self::$app->handle($req);
        $response = new WorkermanResponse(
            $ret->getStatusCode(),
            $ret->getHeaders(),
            $ret->getBody()
        );

        return $response;
    }

    public function run($init = null)
    {
        // Suppress Workerman startup message and show Comet instead
        global $argv;        
        $argv[] = '-q';

        echo "\n-------------------------------------------------------------------------";
        echo "\nServer               Listen                              Workers   Status";
        echo "\n-------------------------------------------------------------------------\n";        
        
        // TODO Support HTTPS
        $worker = new Worker('http://' . $this->host . ':' . $this->port);
        // FIXME What's the optimal count of workers?
        $worker->count = (int) shell_exec('nproc') * 4;
        $worker->name = 'Comet v' . self::VERSION;

        // Initialization code for EACH worker - it runs when worker starts working
        //$worker->onWorkerStart = static function() { $init(); };
        if ($init)
            $worker->onWorkerStart = $init;

        // Handle EACH request and form response        
        $worker->onMessage = static function($connection, WorkermanRequest $request)
        {
            // TODO All errors and exceptions send to log by default?
            try {
                $response = self::_handle($request);
                $connection->send($response);
            } catch(HttpNotFoundException $error) {
                // TODO Catch it within App:handle and return 404 code
                $connection->send(new WorkermanResponse(404));
            } catch(\Throwable $error) {
                echo $error->getMessage();
                // TODO Log error
                // TODO Return error message?
                $connection->send(new WorkermanResponse(500));
            }
        };
        
        // Let's go!
        Worker::runAll();
    }
}
