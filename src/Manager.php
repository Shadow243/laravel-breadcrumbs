<?php

namespace DaveJamesMiller\Breadcrumbs;

use DaveJamesMiller\Breadcrumbs\Exceptions\DuplicateBreadcrumbException;
use DaveJamesMiller\Breadcrumbs\Exceptions\InvalidBreadcrumbException;
use DaveJamesMiller\Breadcrumbs\Exceptions\ViewNotSetException;
use DaveJamesMiller\Breadcrumbs\Exceptions\UnnamedRouteException;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Traits\Macroable;
use stdClass;

/**
 * The main Breadcrumbs singleton class, responsible for registering, generating and rendering breadcrumbs.
 */
class Manager
{
    use Macroable;

    /**
     * @var CurrentRoute
     */
    protected $currentRoute;

    /**
     * @var Generator
     */
    protected $generator;

    /**
     * @var View
     */
    protected $view;

    /**
     * @var array The registered breadcrumb-generating callbacks.
     */
    protected $callbacks = [];

    /**
     * @var array Closures to call before generating breadcrumbs for the current page.
     */
    protected $before = [];

    /**
     * @var array Closures to call after generating breadcrumbs for the current page.
     */
    protected $after = [];

    public function __construct(CurrentRoute $currentRoute, Generator $generator, View $view)
    {
        $this->generator    = $generator;
        $this->currentRoute = $currentRoute;
        $this->view         = $view;
    }

    /**
     * Register a breadcrumb-generating callback for a page.
     *
     * @param string   $name     The name of the page.
     * @param callable $callback The callback, which should accept a Generator instance as the first parameter and may
     *                           accept additional parameters.
     * @return void
     * @throws DuplicateBreadcrumbException If the given name has already been used.
     */
    public function register(string $name, callable $callback) //: void
    {
        if (isset($this->callbacks[ $name ])) {
            throw new DuplicateBreadcrumbException("Breadcrumb name \"{$name}\" has already been registered");
        }

        $this->callbacks[ $name ] = $callback;
    }

    /**
     * Register a closure to call before generating breadcrumbs for the current page.
     *
     * For example, this can be used to always prepend the homepage without needing to manually add it to each page.
     *
     * @param callback $callback The callback, which should accept a Generator instance as the first and only parameter.
     * @return void
     */
    public function before(callable $callback) //: void
    {
        $this->before[] = $callback;
    }

    /**
     * Register a closure to call after generating breadcrumbs for the current page.
     *
     * For example, this can be used to append the current page number when using pagination.
     *
     * @param callback $callback The callback, which should accept a Generator instance as the first and only parameter.
     * @return void
     */
    public function after(callable $callback) //: void
    {
        $this->after[] = $callback;
    }

    /**
     * Check if a breadcrumb with the given name exists.
     *
     * If no name is given, defaults to the current route name.
     *
     * @param string|null $name The page name.
     * @return bool Whether there is a registered callback with that name.
     */
    public function exists(string $name = null): bool
    {
        if (is_null($name)) {
            try {
                list($name) = $this->currentRoute->get();
            } catch (UnnamedRouteException $e) {
                return false;
            }
        }

        return isset($this->callbacks[ $name ]);
    }

    /**
     * Generate a set of breadcrumbs for a page.
     *
     * @param string|null $name      The name of the current page.
     * @param mixed       ...$params The parameters to pass to the closure for the current page.
     * @return Collection The generated breadcrumbs.
     * @throws UnnamedRouteException if no name is given and the current route doesn't have an associated name.
     * @throws InvalidBreadcrumbException if the name is (or any ancestor names are) not registered.
     */
    public function generate(string $name = null, ...$params): Collection
    {
        $origName = $name;

        // Route-bound breadcrumbs
        if ($name === null) {
            try {
                list($name, $params) = $this->currentRoute->get();
            } catch (UnnamedRouteException $e) {
                if (config('breadcrumbs.unnamed-route-exception')) {
                    throw $e;
                }

                return new Collection;
            }
        }

        // Generate breadcrumbs
        try {
            return $this->generator->generate($this->callbacks, $this->before, $this->after, $name, $params);
        } catch (InvalidBreadcrumbException $e) {
            if ($origName === null && config('breadcrumbs.missing-route-bound-breadcrumb-exception')) {
                throw $e;
            }

            if ($origName !== null && config('breadcrumbs.invalid-named-breadcrumb-exception')) {
                throw $e;
            }

            return new Collection;
        }
    }

    /**
     * Render breadcrumbs for a page with the specified view.
     *
     * @param string      $view      The name of the view to render.
     * @param string|null $name      The name of the current page.
     * @param mixed       ...$params The parameters to pass to the closure for the current page.
     * @return HtmlString The generated HTML.
     * @throws InvalidBreadcrumbException if the name is (or any ancestor names are) not registered.
     * @throws UnnamedRouteException if no name is given and the current route doesn't have an associated name.
     * @throws ViewNotSetException if no view has been set.
     */
    public function view(string $view, string $name = null, ...$params): HtmlString
    {
        $breadcrumbs = $this->generate($name, ...$params);

        return $this->view->render($view, $breadcrumbs);
    }

    /**
     * Render breadcrumbs for a page with the default view.
     *
     * @param string|null $name      The name of the current page.
     * @param mixed       ...$params The parameters to pass to the closure for the current page.
     * @return HtmlString The generated HTML.
     * @throws InvalidBreadcrumbException if the name is (or any ancestor names are) not registered.
     * @throws UnnamedRouteException if no name is given and the current route doesn't have an associated name.
     * @throws ViewNotSetException if no view has been set.
     */
    public function render(string $name = null, ...$params): HtmlString
    {
        return $this->view(config('breadcrumbs.view'), $name, ...$params);
    }

    /**
     * Get the last breadcrumb for the current page.
     *
     * Optionally pass a
     *
     * @return stdClass|null The breadcrumb for the current page.
     * @throws UnnamedRouteException if the current route doesn't have an associated name.
     * @throws InvalidBreadcrumbException if the name is (or any ancestor names are) not registered.
     */
    public function current()
    {
        return $this->generate()->where('current', '!==', false)->last();
    }

    /**
     * Set the current route name and parameters to use when calling render() or generate() with no parameters.
     *
     * @param string $name      The name of the current page.
     * @param mixed  ...$params The parameters to pass to the closure for the current page.
     * @return void
     */
    public function setCurrentRoute(string $name, ...$params) //: void
    {
        $this->currentRoute->set($name, $params);
    }

    /**
     * Clear the previously set route name and parameters to use when calling render() or generate() with no parameters.
     *
     * Next time it will revert to the default behaviour of using the current route from Laravel.
     *
     * @return void
     */
    public function clearCurrentRoute() //: void
    {
        $this->currentRoute->clear();
    }
}
