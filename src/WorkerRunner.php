<?php

declare(strict_types=1);

namespace Jiwei\FrankenPhpSlim;

use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * FrankenPHP Worker 运行时
 *
 * 将 Slim 4 应用以 Worker 模式运行在 FrankenPHP 中。
 * 应用启动一次后常驻内存，循环处理请求。
 *
 * @see https://frankenphp.dev/docs/worker/
 */
class WorkerRunner
{
    /**
     * 默认单 Worker 最大处理请求数
     */
    protected int $maxRequests;

    /**
     * @param int $maxRequests 单 Worker 最大请求数，超过后自动重启。0 为不限制
     */
    public function __construct(int $maxRequests = 0)
    {
        $this->maxRequests = $maxRequests;
    }

    /**
     * 快捷启动：传入一个返回 Slim\App 的引导文件路径
     *
     * @param string $bootstrapPath 引导文件路径（需返回 Slim\App 实例）
     * @param int $maxRequests 最大请求数，默认从 MAX_REQUESTS 环境变量读取
     */
    public static function runFromBootstrap(string $bootstrapPath, int $maxRequests = 0): void
    {
        $app = require $bootstrapPath;
        $runner = new self($maxRequests);
        $runner->run($app);
    }

    /**
     * 启动 Worker 循环
     */
    public function run(App $app): void
    {
        $handler = $this->createHandler($app);
        $maxRequests = $this->maxRequests ?: (int)($_SERVER['MAX_REQUESTS'] ?? 1000);

        for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
            $keepRunning = \frankenphp_handle_request($handler);
            gc_collect_cycles();

            if (!$keepRunning) {
                break;
            }
        }
    }

    /**
     * 创建请求处理 handler
     */
    protected function createHandler(App $app): \Closure
    {
        return static function () use ($app): void {
            try {
                $request = ServerRequestFactory::createFromGlobals();
                $response = $app->handle($request);
                self::emitResponse($response);
            } catch (\Throwable $e) {
                self::handleError($e);
            }
        };
    }

    /**
     * 发送响应到客户端
     */
    protected static function emitResponse(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            // Status line
            header(sprintf(
                'HTTP/%s %s %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase()
            ), true, $response->getStatusCode());

            // Headers
            foreach ($response->getHeaders() as $name => $values) {
                $isFirst = strtolower((string)$name) !== 'set-cookie';
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), $isFirst);
                    $isFirst = false;
                }
            }
        }

        // Body
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(4096);
        }
    }

    /**
     * 处理未捕获异常
     */
    protected static function handleError(\Throwable $e): void
    {
        error_log(sprintf(
            '[FrankenPHP Worker] Unhandled exception: %s in %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error', true, 500);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode([
            'status' => 500,
            'error' => 'Internal Server Error',
        ]);
    }
}
