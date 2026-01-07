<?php
/**
 *
 * This file is part of Aura for PHP.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */
namespace Aura\Router;

use Aura\Router\Rule\RuleIterator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 *
 * A matcher that uses prefix-based route indexing.
 *
 * This matcher indexes routes by their first path segment, allowing it to
 * skip routes that cannot possibly match based on the request path prefix.
 *
 * @package Aura.Router
 *
 */
class IndexedMatcher extends Matcher
{
    /**
     *
     * Routes indexed by their first path segment.
     *
     * @var array
     *
     */
    protected $indexedRoutes = [];

    /**
     *
     * Routes that start with a dynamic segment (cannot be indexed).
     *
     * @var array
     *
     */
    protected $dynamicRoutes = [];

    /**
     *
     * Whether the index has been built.
     *
     * @var bool
     *
     */
    protected $indexBuilt = false;

    /**
     *
     * Builds the route index from the map.
     *
     * @return void
     *
     */
    protected function buildIndex()
    {
        if ($this->indexBuilt) {
            return;
        }

        $this->indexedRoutes = [];
        $this->dynamicRoutes = [];

        foreach ($this->map as $name => $route) {
            if (!$route->isRoutable) {
                continue;
            }

            $prefix = $this->extractPrefix($route->path);

            if ($prefix === '*') {
                // Dynamic routes go to a separate collection
                $this->dynamicRoutes[$name] = $route;
            } else {
                if (!isset($this->indexedRoutes[$prefix])) {
                    $this->indexedRoutes[$prefix] = [];
                }
                $this->indexedRoutes[$prefix][$name] = $route;
            }
        }

        $this->indexBuilt = true;
    }

    /**
     *
     * Extracts the first path segment as a prefix for indexing.
     *
     * @param string $path The route path.
     *
     * @return string The prefix, or '*' if the first segment is dynamic.
     *
     */
    protected function extractPrefix($path)
    {
        $path = ltrim($path, '/');

        // Find the first segment
        $pos = strpos($path, '/');
        if ($pos !== false) {
            $segment = substr($path, 0, $pos);
        } else {
            $segment = $path;
        }

        // If it's a dynamic segment {foo} or starts with {, use '*'
        if ($segment === '' || strpos($segment, '{') !== false) {
            return '*';
        }

        return $segment;
    }

    /**
     *
     * Gets a route that matches the request using optimized prefix-based lookup.
     *
     * @param ServerRequestInterface $request The incoming request.
     *
     * @return Route|false Returns a route object when it finds a match, or
     * boolean false if there is no match.
     *
     */
    public function match(ServerRequestInterface $request)
    {
        $this->buildIndex();

        $this->matchedRoute = false;
        $this->failedRoute = null;
        $this->failedScore = 0;
        $path = $request->getUri()->getPath();

        $requestPrefix = $this->extractPrefix($path);

        // First, check routes with matching prefix (most likely to match)
        if (isset($this->indexedRoutes[$requestPrefix])) {
            foreach ($this->indexedRoutes[$requestPrefix] as $name => $proto) {
                $route = $this->requestRoute($request, $proto, $name, $path);
                if ($route) {
                    return $route;
                }
            }
        }

        // Then, check dynamic routes (routes starting with a variable segment)
        foreach ($this->dynamicRoutes as $name => $proto) {
            $route = $this->requestRoute($request, $proto, $name, $path);
            if ($route) {
                return $route;
            }
        }

        return false;
    }

    /**
     *
     * Rebuilds the route index.
     *
     * Call this method after adding routes to the map if you need to
     * update the index.
     *
     * @return void
     *
     */
    public function rebuildIndex()
    {
        $this->indexBuilt = false;
        $this->buildIndex();
    }

    /**
     *
     * Gets the indexed routes for debugging.
     *
     * @return array
     *
     */
    public function getIndexedRoutes()
    {
        $this->buildIndex();
        return $this->indexedRoutes;
    }

    /**
     *
     * Gets the dynamic routes for debugging.
     *
     * @return array
     *
     */
    public function getDynamicRoutes()
    {
        $this->buildIndex();
        return $this->dynamicRoutes;
    }
}
