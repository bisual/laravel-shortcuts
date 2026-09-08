<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

abstract class PackageGeneratorCommand extends GeneratorCommand
{
    protected function resolveStubPath(string $stub): string
    {
        $customPath = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($customPath)
            ? $customPath
            : dirname(__DIR__, 2).$stub;
    }

    protected function qualifyNameWithSuffix(string $name, string $suffix): string
    {
        $name = str_replace('/', '\\', ltrim($name, '\\/'));
        $basename = class_basename($name);

        if (! Str::endsWith($basename, $suffix)) {
            $basename .= $suffix;
        }

        if (Str::contains($name, '\\')) {
            return Str::beforeLast($name, '\\').'\\'.$basename;
        }

        return $basename;
    }

    protected function stripSuffixes(string $name, array $suffixes): string
    {
        $name = str_replace('/', '\\', ltrim(trim($name), '\\/'));
        $parts = explode('\\', $name);
        $basename = (string) array_pop($parts);

        $stripped = true;

        while ($stripped && $basename !== '') {
            $stripped = false;

            foreach ($suffixes as $suffix) {
                if (Str::endsWith($basename, $suffix) && $basename !== $suffix) {
                    $basename = (string) Str::replaceEnd($suffix, '', $basename);
                    $stripped = true;
                    break;
                }
            }
        }

        $parts[] = $basename;

        return implode('\\', array_filter($parts, fn (string $part) => $part !== ''));
    }
}
