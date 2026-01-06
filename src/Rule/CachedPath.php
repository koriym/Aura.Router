<?php
/**
 *
 * This file is part of Aura for PHP.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */
namespace Aura\Router\Rule;

use Aura\Router\Route;
use Psr\Http\Message\ServerRequestInterface;

/**
 *
 * A rule for the URL path with regex pattern caching.
 *
 * This is an optimized version of Path that caches compiled regex patterns
 * to avoid rebuilding them on every match attempt.
 *
 * @package Aura.Router
 *
 */
class CachedPath implements RuleInterface
{
    /**
     *
     * Cache of compiled regex patterns, keyed by route path.
     *
     * @var array
     *
     */
    protected $patternCache = [];

    /**
     *
     * The basepath to prefix when matching the path.
     *
     * @var string|null
     *
     */
    protected $basepath;

    /**
     *
     * Constructor.
     *
     * @param string $basepath The basepath to prefix when matching the path.
     *
     */
    public function __construct($basepath = null)
    {
        $this->basepath = $basepath;
    }

    /**
     *
     * Checks that the Request path matches the Route path.
     *
     * @param ServerRequestInterface $request The HTTP request.
     *
     * @param Route $route The route.
     *
     * @return bool True on success, false on failure.
     *
     */
    public function __invoke(ServerRequestInterface $request, Route $route)
    {
        $cacheKey = $this->getCacheKey($route);

        if (!isset($this->patternCache[$cacheKey])) {
            $this->patternCache[$cacheKey] = $this->buildRegex($route);
        }

        $regex = $this->patternCache[$cacheKey];
        $match = preg_match($regex, $request->getUri()->getPath(), $matches);

        if (!$match) {
            return false;
        }

        $attributes = $this->getAttributes($matches, $route->wildcard);

        // Handle callable token validators
        foreach ($route->tokens as $name => $pattern) {
            if (is_callable($pattern)) {
                if (!isset($attributes[$name])) {
                    continue;
                }
                if (!$pattern($attributes[$name], $route, $request)) {
                    return false;
                }
            }
        }

        $route->attributes($attributes);
        return true;
    }

    /**
     *
     * Generates a cache key for the route.
     *
     * @param Route $route The route.
     *
     * @return string The cache key.
     *
     */
    protected function getCacheKey(Route $route)
    {
        // Include tokens in cache key since they affect the regex
        $tokenKey = '';
        foreach ($route->tokens as $name => $pattern) {
            if (is_string($pattern)) {
                $tokenKey .= $name . ':' . $pattern . ';';
            }
        }
        return $route->path . '|' . $route->wildcard . '|' . $tokenKey;
    }

    /**
     *
     * Gets the attributes from the path.
     *
     * @param array $matches The array of matches.
     *
     * @param string $wildcard The name of the wildcard attributes.
     *
     * @return array
     *
     */
    protected function getAttributes($matches, $wildcard)
    {
        $attributes = [];
        foreach ($matches as $key => $val) {
            if (is_string($key) && $val !== '') {
                $attributes[$key] = rawurldecode($val);
            }
        }

        if (!$wildcard) {
            return $attributes;
        }

        $attributes[$wildcard] = [];
        if (!empty($matches[$wildcard])) {
            $attributes[$wildcard] = array_map(
                'rawurldecode',
                explode('/', $matches[$wildcard])
            );
        }

        return $attributes;
    }

    /**
     *
     * Builds the regular expression for the route path.
     *
     * @param Route $route The Route.
     *
     * @return string
     *
     */
    protected function buildRegex(Route $route)
    {
        $regex = $this->basepath . $route->path;

        // Handle optional attributes {/foo,bar}
        $regex = $this->expandOptionalAttributes($regex);

        // Replace attribute placeholders with named capture groups
        $regex = $this->expandAttributes($regex, $route);

        // Handle wildcard
        if ($route->wildcard) {
            $regex = rtrim($regex, '/') . "(/(?P<{$route->wildcard}>.*))?" ;
        }

        return '#^' . $regex . '$#';
    }

    /**
     *
     * Expands optional attributes in the regex from `{/foo,bar,baz}` to
     * `(/{foo}(/{bar}(/{baz})?)?)?`.
     *
     * @param string $regex The regex pattern.
     *
     * @return string The expanded regex.
     *
     */
    protected function expandOptionalAttributes($regex)
    {
        if (!preg_match('#{/([a-z][a-zA-Z0-9_,]*)}#', $regex, $matches)) {
            return $regex;
        }

        $list = explode(',', $matches[1]);
        $head = '';
        $tail = '';

        // Handle case where optional set is at the start of the path
        if (substr($regex, 0, 2) === '{/') {
            $name = array_shift($list);
            $head = "/({{$name}})?";
        }

        foreach ($list as $name) {
            $head .= "(/{{$name}}";
            $tail .= ')?';
        }

        return str_replace($matches[0], $head . $tail, $regex);
    }

    /**
     *
     * Expands attribute placeholders to named capture groups.
     *
     * @param string $regex The regex pattern.
     *
     * @param Route $route The route.
     *
     * @return string The expanded regex.
     *
     */
    protected function expandAttributes($regex, Route $route)
    {
        return preg_replace_callback(
            '#{([a-z][a-zA-Z0-9_]*)}#',
            function ($match) use ($route) {
                $name = $match[1];
                if (isset($route->tokens[$name]) && is_string($route->tokens[$name])) {
                    return "(?P<{$name}>{$route->tokens[$name]})";
                }
                return "(?P<{$name}>[^/]+)";
            },
            $regex
        );
    }

    /**
     *
     * Clears the pattern cache.
     *
     * @return void
     *
     */
    public function clearCache()
    {
        $this->patternCache = [];
    }

    /**
     *
     * Gets the number of cached patterns.
     *
     * @return int
     *
     */
    public function getCacheSize()
    {
        return count($this->patternCache);
    }
}
