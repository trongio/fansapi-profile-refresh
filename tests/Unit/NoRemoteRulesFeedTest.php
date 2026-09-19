<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guard for the design rule: signing rules come only from local extraction.
 * No remote/community rules feed, no committed rules file, and no way for the
 * PHP app to execute (downloaded) JavaScript.
 */
class NoRemoteRulesFeedTest extends TestCase
{
    private const FORBIDDEN = [
        'rules_url', 'RULES_URL', 'raw.githubusercontent', 'dynamic-rules', 'dynamicRules',
        'onlyfans-dynamic-rules', 'proc_open', 'shell_exec', 'passthru', 'Symfony\\Component\\Process', 'V8Js',
    ];

    #[Test]
    public function the_app_has_no_remote_rules_feed_and_runs_no_javascript(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [$root.'/.env.example', $root.'/docker-compose.yml', $root.'/routes/console.php'];
        foreach (['app', 'config'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$needle} found in ".substr($path, strlen($root) + 1));
            }
        }

        $this->assertFileDoesNotExist($root.'/fixtures/onlyfans-dynamic-rules.json');
    }
}
