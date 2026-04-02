<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:n8n-tool')]
#[Signature('make:n8n-tool {name : The tool handler class name}')]
#[Description('Create a new n8n tool handler class')]
final class MakeToolCommand extends GeneratorCommand
{
    protected $type = 'N8nToolHandler';

    protected function getStub(): string
    {
        return __DIR__ . '/../../stubs/tool-handler.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\N8n\Tools';
    }
}
