<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lightweight HTTP router with route parameters, middleware pipelines,
 * groups (prefix + shared middleware) and named routes.
 */
final class Router
{
    /** @var array<int,array{methods:string[],pattern:string,regex:string,names:string[],handler:callable,middleware:string[],name:?string}> */
    private array $routes = [];

    /** @var array<string,string> */
    private array $named = [];

    /** @var array<string,string> */
    private array $groupStack = [];

    public function get(string $pattern, array|\Closure $handler): RouteBuilder
    {
        return $this->add(['GET', 'HEAD'], $pattern, $handler);
    }

    public function post(string $pattern, array|\Closure $handler): RouteBuilder
    {
        return $this->add(['POST'], $pattern, $handler);
    }

    public function put(string $pattern, array|\Closure $handler): RouteBuilder
    {
        return $this->add(['PUT', 'PATCH'], $pattern, $handler);
    }

    public function patch(string $pattern, array|\Closure $handler): RouteBuilder
    {
        return $this->add(['PATCH'], $pattern, $handler);
    }

    public function delete(string $pattern, array|\Closure $handler): RouteBuilder
    {
        return $this->add(['DELETE'], $pattern, $handler);
    }

    /**
     * @param array{prefix?:string,middleware?:array<string>} $attributes
     */
    public function group(array $attributes, \Closure $routes): void
    {
        $this->groupStack[] = $attributes;
        $routes($this);
        array_pop($this->groupStack);
    }

    private function add(array $methods, string $pattern, array|\Closure $handler): RouteBuilder
    {
        $prefix = '';
        $middleware = [];
        foreach ($this->groupStack as $group) {
            if (!empty($group['prefix'])) {
                $prefix .= '/' . trim((string) $group['prefix'], '/');
            }
            foreach ($group['middleware'] ?? [] as $m) {
                $middleware[] = $m;
            }
        }

        $pattern = '/' . trim($prefix . '/' . trim($pattern, '/'), '/');
        $pattern = $pattern === '//' ? '/' : $pattern;

        [$regex, $names] = $this->compile($pattern);

        $index = count($this->routes);
        $this->routes[] = [
            'methods' => $methods,
            'pattern' => $pattern,
            'regex' => $regex,
            'names' => $names,
            'handler' => $handler,
            'middleware' => $middleware,
            'name' => null,
        ];

        return new RouteBuilder($this, $index);
    }

    public function setName(int $index, string $name): void
    {
        $this->routes[$index]['name'] = $name;
        $this->named[$name] = $this->routes[$index]['pattern'];
    }

    public function addMiddleware(int $index, array $middleware): void
    {
        $this->routes[$index]['middleware'] = array_merge($this->routes[$index]['middleware'], $middleware);
    }

    /** @return array{0=int,1=array<string,string>} */
    private function compile(string $pattern): array
    {
        $names = [];
        $regex = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            function (array $m) use (&$names) {
                $names[] = $m[1];
                return '(' . ($m[2] ?? '[^/]+') . ')';
            },
            str_replace('#', '\#', $pattern)
        );
        return ['#^' . $regex . '$#', $names];
    }

    /**
     * Match a method + path, run middleware, and invoke the handler.
     *
     * @param callable(Request): (Response|mixed) $handler
     */
    public function dispatch(Request $request, MiddlewareResolver $resolver): Response
    {
        $path = $request->path();
        $method = $request->method();

        foreach ($this->routes as $route) {
            if (!in_array($method, $route['methods'], true)) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($route['names'] as $i => $name) {
                $params[$name] = $matches[$i + 1];
            }

            $pipeline = function (Request $req) use ($route, $params) {
                $result = $this->invoke($route['handler'], $req, $params);
                if ($result instanceof Response) {
                    return $result;
                }
                if (is_string($result)) {
                    return Response::html($result);
                }
                return Response::json($result);
            };

            foreach (array_reverse($route['middleware']) as $name) {
                $middleware = $resolver->resolve($name);
                $inner = $pipeline;
                $pipeline = function (Request $req) use ($middleware, $inner) {
                    return $middleware->handle($req, $inner);
                };
            }

            return $pipeline($request);
        }

        throw new \App\Exceptions\NotFoundException('No route matches ' . $method . ' ' . $path);
    }

    /**
     * Invoke a route handler: [Controller::class, 'method'] gets the
     * controller instantiated; arguments are matched by type/name so both
     * (Request $r, string $id) and (string $id, Request $r) signatures work.
     *
     * @param array{0:class-string,1:string}|\Closure $handler
     * @param array<string,string> $params
     */
    private function invoke(array|\Closure $handler, Request $request, array $params): mixed
    {
        if ($handler instanceof \Closure) {
            return $handler($request, $params);
        }

        [$class, $method] = $handler;
        if (!class_exists($class) || !method_exists($class, $method)) {
            throw new \RuntimeException("Invalid route handler {$class}::{$method}");
        }

        $controller = new $class();
        $ref = new \ReflectionMethod($controller, $method);
        $args = [];
        foreach ($ref->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === Request::class) {
                $args[] = $request;
            } elseif (array_key_exists($parameter->getName(), $params)) {
                $args[] = $params[$parameter->getName()];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
            } else {
                $args[] = null;
            }
        }

        return $controller->{$method}(...$args);
    }

    /** Generate a URL for a named route. */
    public function url(string $name, array $params = []): string
    {
        $pattern = $this->named[$name]
            ?? throw new \RuntimeException("Route [{$name}] is not named.");
        $url = preg_replace_callback(
            '/\{(\w+)(?::[^}]+)?\}/',
            function (array $m) use ($params) {
                $value = $params[$m[1]] ?? '';
                return rawurlencode((string) $value);
            },
            $pattern
        );
        return url((string) $url);
    }

    public function hasNamed(string $name): bool
    {
        return isset($this->named[$name]);
    }
}

/**
 * Fluent per-route configuration returned by registration methods.
 */
final class RouteBuilder
{
    public function __construct(
        private readonly Router $router,
        private readonly int $index,
    ) {
    }

    public function name(string $name): self
    {
        $this->router->setName($this->index, $name);
        return $this;
    }

    public function middleware(string ...$names): self
    {
        $this->router->addMiddleware($this->index, $names);
        return $this;
    }
}
