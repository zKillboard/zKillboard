<?php

class PugTemplateEnvironment
{
    private string $templateDir;
    private $pug = null;
    private array $globals = [];
    private array $functions = [];
    private array $extensions = [];

    public function __construct(string $templateDir, array $options = [])
    {
        $this->templateDir = rtrim($templateDir, '/') . '/';

        if (class_exists('\\Pug\\Pug')) {
            $this->pug = new \Pug\Pug(array_merge([
                'basedir' => $this->templateDir,
                'expressionLanguage' => 'php',
                'pretty' => false,
            ], $options));
        } elseif (class_exists('\\Pug')) {
            $class = '\\Pug';
            $this->pug = new $class(array_merge([
                'basedir' => $this->templateDir,
                'expressionLanguage' => 'php',
                'pretty' => false,
            ], $options));
        }
    }

    public function addGlobal(string $name, $value): void
    {
        $this->globals[$name] = $value;
        $this->shareWithPug($name, $value);
    }

    public function addFunction($function, $callable = null): void
    {
        if (is_string($function) && $callable !== null) {
            $this->functions[$function] = $callable;
            $this->shareWithPug($function, $callable);
        } elseif (is_object($function) && method_exists($function, 'getName') && method_exists($function, 'getCallable')) {
            $name = $function->getName();
            $this->functions[$name] = $function->getCallable();
            $this->shareWithPug($name, $this->functions[$name]);
        }
    }

    public function addExtension($extension): void
    {
        $this->extensions[] = $extension;
    }

    public function render(string $template, array $data = []): string
    {
        $pugTemplate = $this->resolvePugTemplate($template);
        if ($pugTemplate !== null && $this->pug !== null) {
            return $this->pug->renderFile($pugTemplate, array_merge($this->collectGlobals(), $data, $this->functions));
        }

        if ($pugTemplate !== null) {
            throw new RuntimeException('Pug renderer dependency is not installed.');
        }

        $rawTemplate = $this->resolveRawTemplate($template);
        if ($rawTemplate !== null) {
            return file_get_contents($rawTemplate);
        }

        throw new RuntimeException("Template not found: $template");
    }

    public function getPug()
    {
        return $this->pug;
    }

    private function resolvePugTemplate(string $template): ?string
    {
        $template = ltrim($template, '/');
        $candidates = [];

        if (substr($template, -4) === '.pug') {
            $candidates[] = $this->templateDir . $template;
        } else {
            $candidates[] = $this->templateDir . $template . '.pug';
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveRawTemplate(string $template): ?string
    {
        $candidate = $this->templateDir . ltrim($template, '/');
        return is_file($candidate) ? $candidate : null;
    }

    private function collectGlobals(): array
    {
        $globals = $this->globals;
        foreach ($this->extensions as $extension) {
            if (method_exists($extension, 'getGlobals')) {
                $globals = array_merge($globals, $extension->getGlobals());
            }
        }
        return $globals;
    }

    private function shareWithPug(string $name, $value): void
    {
        if ($this->pug !== null && method_exists($this->pug, 'share')) {
            $this->pug->share($name, $value);
        }
    }
}
