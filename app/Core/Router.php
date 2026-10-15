<?php
declare(strict_types=1);
namespace App\Core;

final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable}> */
    private array $routes = [];
    /** @var callable|null */
    private $notFound = null;

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function setNotFound(callable $handler): void
    {
        $this->notFound = $handler;
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        $path = '/' . trim(rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?: '/')), '/');

        foreach ($this->routes as [$m, $regex, $handler]) {
            if ($m === $method && preg_match($regex, $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler($params);
                return;
            }
        }

        if ($this->notFound !== null) {
            ($this->notFound)();
            return;
        }
        http_response_code(404);
        echo 'Not found';
    }
}
