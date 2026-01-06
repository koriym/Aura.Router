<?php
namespace Aura\Router;

use Zend\Diactoros\ServerRequestFactory;

class OptimizedMatcherTest extends \PHPUnit_Framework_TestCase
{
    protected $map;
    protected $matcher;

    protected function setUp()
    {
        parent::setUp();
        $container = new RouterContainer();
        $this->map = $container->getMap();
        $this->matcher = $container->getOptimizedMatcher();
    }

    protected function newRequest($path, array $server = [])
    {
        $server['REQUEST_URI'] = $path;
        $server = array_merge($_SERVER, $server);
        return ServerRequestFactory::fromGlobals($server);
    }

    protected function assertIsRoute($actual)
    {
        $this->assertInstanceOf('Aura\Router\Route', $actual);
    }

    protected function assertRoute($expect, $actual)
    {
        $this->assertIsRoute($actual);
        foreach ($expect as $key => $val) {
            $this->assertSame($val, $actual->$key);
        }
    }

    public function testBasicMatch()
    {
        $this->map->get('home', '/');
        $this->map->get('about', '/about');
        $this->map->get('contact', '/contact');

        $request = $this->newRequest('/about');
        $actual = $this->matcher->match($request);
        $this->assertIsRoute($actual);
        $this->assertSame('about', $actual->name);
    }

    public function testNoMatch()
    {
        $this->map->get('home', '/');
        $this->map->get('about', '/about');

        $request = $this->newRequest('/nonexistent');
        $actual = $this->matcher->match($request);
        $this->assertFalse($actual);
    }

    public function testPrefixIndexing()
    {
        // Add routes with different prefixes
        $this->map->get('api.users', '/api/users');
        $this->map->get('api.posts', '/api/posts');
        $this->map->get('admin.dashboard', '/admin/dashboard');
        $this->map->get('admin.users', '/admin/users');
        $this->map->get('home', '/');

        // Verify index structure
        $indexed = $this->matcher->getIndexedRoutes();
        $this->assertArrayHasKey('api', $indexed);
        $this->assertArrayHasKey('admin', $indexed);
        $this->assertCount(2, $indexed['api']);
        $this->assertCount(2, $indexed['admin']);

        // Test matching routes from different prefixes
        $request = $this->newRequest('/api/users');
        $actual = $this->matcher->match($request);
        $this->assertIsRoute($actual);
        $this->assertSame('api.users', $actual->name);

        $request = $this->newRequest('/admin/dashboard');
        $actual = $this->matcher->match($request);
        $this->assertIsRoute($actual);
        $this->assertSame('admin.dashboard', $actual->name);
    }

    public function testDynamicPrefixRoutes()
    {
        // Route starting with a dynamic segment should go to dynamic routes
        $this->map->get('user.profile', '/{username}');
        $this->map->get('static.about', '/about');

        $dynamic = $this->matcher->getDynamicRoutes();
        $indexed = $this->matcher->getIndexedRoutes();

        $this->assertArrayHasKey('user.profile', $dynamic);
        $this->assertArrayHasKey('about', $indexed);

        // Dynamic route should still match
        $request = $this->newRequest('/johndoe');
        $actual = $this->matcher->match($request);
        $this->assertIsRoute($actual);
        $this->assertSame('user.profile', $actual->name);
        $this->assertSame('johndoe', $actual->attributes['username']);
    }

    public function testAttach()
    {
        $this->map->attach('resource.', '/resource', function ($map) {
            $map->tokens(['id' => '(\d+)']);

            $map->get('browse', '/', ['action' => 'browse']);
            $map->get('read', '/{id}');
            $map->post('edit', '/{id}');
            $map->delete('delete', '/{id}');
        });

        // browse
        $request = $this->newRequest('/resource/', ['REQUEST_METHOD' => 'GET']);
        $actual = $this->matcher->match($request);
        $this->assertIsRoute($actual);
        $this->assertSame('resource.browse', $actual->name);

        // read
        $request = $this->newRequest('/resource/42', ['REQUEST_METHOD' => 'GET']);
        $actual = $this->matcher->match($request);
        $this->assertIsRoute($actual);
        $this->assertSame('resource.read', $actual->name);
        $expect = ['id' => '42'];
        $this->assertEquals($expect, $actual->attributes);

        // edit
        $request = $this->newRequest('/resource/42', ['REQUEST_METHOD' => 'POST']);
        $actual = $this->matcher->match($request);
        $this->assertIsRoute($actual);
        $this->assertSame('resource.edit', $actual->name);

        // delete
        $request = $this->newRequest('/resource/42', ['REQUEST_METHOD' => 'DELETE']);
        $actual = $this->matcher->match($request);
        $this->assertIsRoute($actual);
        $this->assertSame('resource.delete', $actual->name);

        // no match
        $request = $this->newRequest('/other/path');
        $actual = $this->matcher->match($request);
        $this->assertFalse($actual);
    }

    public function testCatchAll()
    {
        $this->map->route('catchall', '{/controller,action,id}');

        $request = $this->newRequest('/');
        $actual = $this->matcher->match($request);
        $expect = [
            'attributes' => [
                'controller' => null,
                'action' => null,
                'id' => null,
            ],
        ];
        $this->assertRoute($expect, $actual);

        $request = $this->newRequest('/foo');
        $actual = $this->matcher->match($request);
        $expect = [
            'attributes' => [
                'controller' => 'foo',
                'action' => null,
                'id' => null,
            ],
        ];
        $this->assertRoute($expect, $actual);

        $request = $this->newRequest('/foo/bar/baz');
        $actual = $this->matcher->match($request);
        $expect = [
            'attributes' => [
                'controller' => 'foo',
                'action' => 'bar',
                'id' => 'baz',
            ],
        ];
        $this->assertRoute($expect, $actual);
    }

    public function testRebuildIndex()
    {
        $this->map->get('api.users', '/api/users');

        // Force index build
        $indexed = $this->matcher->getIndexedRoutes();
        $this->assertArrayHasKey('api', $indexed);
        $this->assertCount(1, $indexed['api']);

        // Add more routes (in real usage, this would be before first match)
        // The index won't auto-update, so we need to rebuild
        $this->map->get('api.posts', '/api/posts');
        $this->matcher->rebuildIndex();

        $indexed = $this->matcher->getIndexedRoutes();
        $this->assertCount(2, $indexed['api']);
    }

    public function testMixedStaticAndDynamicRoutes()
    {
        // Static routes
        $this->map->get('home', '/');
        $this->map->get('about', '/about');
        $this->map->get('api.list', '/api/list');

        // Dynamic routes
        $this->map->get('api.resource', '/api/{resource}/{id}')
            ->tokens(['id' => '\d+']);
        $this->map->get('page', '/{slug}');

        // Static should match
        $request = $this->newRequest('/about');
        $actual = $this->matcher->match($request);
        $this->assertIsRoute($actual);
        $this->assertSame('about', $actual->name);

        // Dynamic API route should match
        $request = $this->newRequest('/api/users/123');
        $actual = $this->matcher->match($request);
        $this->assertIsRoute($actual);
        $this->assertSame('api.resource', $actual->name);
        $this->assertSame('users', $actual->attributes['resource']);
        $this->assertSame('123', $actual->attributes['id']);

        // Fallback to page route
        $request = $this->newRequest('/my-custom-page');
        $actual = $this->matcher->match($request);
        $this->assertIsRoute($actual);
        $this->assertSame('page', $actual->name);
        $this->assertSame('my-custom-page', $actual->attributes['slug']);
    }

    public function testGetMatchedRoute()
    {
        $this->map->get('test', '/test');

        $request = $this->newRequest('/test');
        $this->matcher->match($request);

        $matched = $this->matcher->getMatchedRoute();
        $this->assertIsRoute($matched);
        $this->assertSame('test', $matched->name);
    }

    public function testGetFailedRoute()
    {
        $expect = $this->map->post('bar', '/bar');
        $this->map->route('foo', '/foo');

        $request = $this->newRequest('/bar');
        $match = $this->matcher->match($request);
        $this->assertFalse($match);

        $actual = $this->matcher->getFailedRoute();
        $this->assertSame($expect->name, $actual->name);
    }

    public function testNonRoutableRoutes()
    {
        $this->map->get('external', 'http://example.com')
            ->isRoutable(false);
        $this->map->get('internal', '/internal');

        // Non-routable route should not be in index
        $indexed = $this->matcher->getIndexedRoutes();
        $dynamic = $this->matcher->getDynamicRoutes();

        // Only internal route should be indexed
        $this->assertArrayHasKey('internal', $indexed);
        $this->assertArrayNotHasKey('external', $indexed);
        $this->assertArrayNotHasKey('external', $dynamic);
    }
}
