<?php
/**
 * Copyright (c) 2017
 *
 * @category  Library
 * @package   Dwoo\SymfonBundle
 * @author    David Sanchez <david38sanchez@gmail.com>
 * @copyright 2017 David Sanchez
 * @license   http://dwoo.org/LICENSE LGPLv3
 * @version   1.0.0
 * @date      2017-03-17
 * @link      http://symfony.dwoo.org/
 */

namespace Dwoo\SymfonyBundle;

use Dwoo\Core;
use Dwoo\Data;
use Dwoo\ICompilable;
use Dwoo\ITemplate;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Bundle\FrameworkBundle\Templating\GlobalVariables;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\TemplateNameParserInterface;
use Symfony\Component\Templating\TemplateReferenceInterface;
use Symfony\Component\Templating\Loader\LoaderInterface;

/**
 * DwooEngine is an engine able to render Dwoo templates.
 * This class is heavily inspired by \Twig_Environment.
 * See {@link http://twig.sensiolabs.org/doc/api.html} for details about \Twig_Environment.
 *
 * @package Dwoo\SymfonyBundle
 */
class DwooEngine implements EngineInterface
{

    /** @var Core */
    protected $core;

    /** @var TemplateNameParserInterface */
    protected $parser;

    /** @var LoaderInterface */
    protected $loader;

    /** @var  array */
    protected $globals = [];

    /** @var array */
    protected $plugins = [];

    /**
     * DwooEngine constructor.
     *
     * @param Core                        $core      A Dwoo\Core instance
     * @param ContainerInterface          $container A ContainerInterface instance
     * @param TemplateNameParserInterface $parser    A TemplateNameParserInterface instance
     * @param LoaderInterface             $loader    A LoaderInterface instance
     * @param array                       $options   An array of \Dwoo\Core properties
     * @param GlobalVariables             $globals   A GlobalVariables instance or null
     */
    public function __construct(Core $core, ContainerInterface $container, TemplateNameParserInterface $parser, LoaderInterface $loader, array $options = [], GlobalVariables $globals = null)
    {
        $this->core   = $core;
        $this->parser = $parser;
        $this->loader = $loader;

        /**
         * Call Dwoo\Core setter from options
         */
        foreach ($options as $property => $value) {
            $property = $this->propertyToSetter($property);
            if (!method_exists($this->core, $property)) {
                continue;
            }
            $this->core->{$property}($value);
        }

        /**
         * Define a set of template dirs to look for. This will allow the
         * usage of the following syntax:
         * <code>file:[WebkitBundle]/Default/layout.html.tpl</code>
         */
        foreach ($container->getParameter('kernel.bundles') as $bundle) {
            $reflection = new \ReflectionClass($bundle);
            if (is_dir($dir = dirname($reflection->getFilename()) . '/Resources/views')) {
                $this->core->setTemplateDir($dir);
            }
        }

        /**
         * Add global variables
         */
        $this->core->addGlobal('container', $container);
        if (null !== $globals) {
            $this->core->addGlobal('app', $globals);
        }
    }

    /**
     * Pass methods not available in this engine to the Dwoo\Core instance.
     *
     * @param string $name
     * @param mixed  $args
     *
     * @return mixed
     */
    public function __call($name, $args)
    {
        return call_user_func_array([$this->core, $name], $args);
    }

    /**
     * Renders a view and returns a Response.
     *
     * @param string   $view       The view name
     * @param array    $parameters An array of parameters to pass to the view
     * @param Response $response   A Response instance
     *
     * @return Response A Response instance
     * @throws \RuntimeException if the template cannot be rendered
     */
    public function renderResponse($view, array $parameters = [], Response $response = null)
    {
        if (null === $response) {
            $response = new Response();
        }

        $response->setContent($this->render($view, $parameters));

        return $response;
    }

    /**
     * Renders a template.
     *
     * @param string|TemplateReferenceInterface $name       A template name or a TemplateReferenceInterface instance
     * @param array                             $parameters An array of parameters to pass to the template
     *
     * @return string The evaluated template as a string
     * @throws \RuntimeException if the template cannot be rendered
     */
    public function render($name, array $parameters = [])
    {
        // Register SymfonyBundle custom plugins
        $this->registerPlugins();

        /**
         * Assign variables/objects to the templates.
         */
        $data = new Data();
        $data->assign($parameters);

        return $this->core->get($name, $data);
    }

    /**
     * Returns true if the template exists.
     *
     * @param string|TemplateReferenceInterface $name A template name or a TemplateReferenceInterface instance
     *
     * @return bool true if the template exists, false otherwise
     * @throws \RuntimeException if the engine cannot handle the template name
     */
    public function exists($name)
    {
        try {
            $this->load($name);
        }
        catch (\InvalidArgumentException $e) {
            return false;
        }

        return true;
    }

    /**
     * Returns true if this class is able to render the given template.
     *
     * @param string|TemplateReferenceInterface $name A template name or a TemplateReferenceInterface instance
     *
     * @return bool true if this class supports the given template, false otherwise
     */
    public function supports($name)
    {
        if ($name instanceof ITemplate) {
            return true;
        }

        $template = $this->parser->parse($name);

        // Keep 'tpl' for backwards compatibility.
        return in_array($template->get('engine'), ['dwoo', 'tpl'], true);
    }

    /**
     * Loads the given template.
     *
     * @param string $name A template name
     *
     * @return mixed The resource handle of the template file or template object
     * @throws \InvalidArgumentException if the template cannot be found
     */
    public function load($name)
    {
        if ($name instanceof ITemplate) {
            return $name;
        }

        $template = $this->parser->parse($name);
        $template = $this->loader->load($template);

        if (false === $template) {
            throw new \InvalidArgumentException(sprintf('The template "%s" does not exist.', $name));
        }

        return (string)$template;
    }

    /**
     * Adds a plugin to the collection.
     *
     * @param $plugin
     */
    public function addPlugin($plugin)
    {
        $this->plugins[] = $plugin;
    }

    /**
     * Gets the collection of plugins.
     *
     * @return array
     */
    public function getPlugins()
    {
        return $this->plugins;
    }

    /**
     * Dynamically register plugins to Dwoo.
     */
    public function registerPlugins()
    {
        foreach ($this->getPlugins() as $plugin) {
            $compilable = false;
            if ($plugin instanceof ICompilable || $plugin instanceof ICompilable\Block) {
                $compilable = true;
            }
            $this->core->addPlugin($plugin->getName(), $plugin, $compilable);
        }
    }

    /**
     * Get the setter method for a Dwoo class variable (property).
     * You may use this method to generate addSomeProperty() or getSomeProperty()
     * kind of methods by setting the $prefix parameter to "add" or "get".
     *
     * @param string $property
     * @param string $prefix
     *
     * @return string
     */
    protected function propertyToSetter($property, $prefix = 'set')
    {
        return $prefix . str_replace(' ', '', ucwords(str_replace('_', ' ', $property)));
    }
}