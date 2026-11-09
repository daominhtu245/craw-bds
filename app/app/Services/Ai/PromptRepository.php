<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Loads versioned prompts from resources/prompts/{name}.v{N}.md.
 * Changing a prompt means adding a new version file, never editing an old one.
 */
class PromptRepository
{
    public function path(string $name, ?int $version = null): string
    {
        $version ??= (int) config("leadradar.ai.prompt_versions.{$name}", 1);

        return resource_path("prompts/{$name}.v{$version}.md");
    }

    /**
     * @param  array<string, string>  $vars  Replaces {{key}} placeholders.
     */
    public function render(string $name, array $vars = [], ?int $version = null): string
    {
        $path = $this->path($name, $version);

        if (! is_file($path)) {
            throw new RuntimeException("Prompt not found: {$path}");
        }

        $text = (string) file_get_contents($path);

        foreach ($vars as $key => $value) {
            $text = str_replace('{{'.$key.'}}', $value, $text);
        }

        return trim($text);
    }
}
