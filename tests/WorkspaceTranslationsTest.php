<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_unique;
use function file_get_contents;
use function is_array;
use function is_string;
use function ksort;
use function str_ends_with;
use function token_get_all;

#[CoversNothing]
final class WorkspaceTranslationsTest extends TestCase
{
    /**
     * HR: Svaki statički UI ključ mora postojati u zasebnom HR i EN katalogu.
     * EN: Every static UI key must exist in the separate HR and EN catalogues.
     */
    public function testEveryStaticTranslationKeyExistsInBothCatalogues(): void
    {
        $root = dirname(__DIR__);
        $keys = [];
        foreach ([$root . '/src', $root . '/views'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo) {
                    continue;
                }

                if (!$file->isFile()) {
                    continue;
                }

                if (!str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                if (is_string($source)) {
                    $keys = [...$keys, ...$this->staticTranslationKeys($source)];
                }
            }
        }

        $keys = array_unique($keys);
        $en = require $root . '/lang/en.php';
        $hr = require $root . '/lang/hr.php';
        $this->assertIsArray($en);
        $this->assertIsArray($hr);

        $missing = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $en)) {
                $missing['en'][] = $key;
            }

            if (!array_key_exists($key, $hr)) {
                $missing['hr'][] = $key;
            }
        }

        ksort($missing);

        $this->assertSame([], $missing, 'Every static __() key must exist in lang/en.php and lang/hr.php.');
    }

    /**
     * HR: Izdvaja samo literalne i spojene literalne argumente bez izvršavanja koda.
     * EN: Extracts only literal and concatenated-literal arguments without executing code.
     *
     * @return list<string>
     */
    private function staticTranslationKeys(string $source): array
    {
        $tokens = token_get_all($source);
        $keys = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] !== T_STRING) {
                continue;
            }

            if ($token[1] !== '__') {
                continue;
            }

            $cursor = $index + 1;
            while ($cursor < $count && $this->isIgnorable($tokens[$cursor])) {
                ++$cursor;
            }

            if (($tokens[$cursor] ?? null) !== '(') {
                continue;
            }

            $literal = '';
            $valid = true;
            for (++$cursor; $cursor < $count; ++$cursor) {
                $part = $tokens[$cursor];
                if ($part === ')') {
                    break;
                }

                if ($part === '.') {
                    continue;
                }

                if ($this->isIgnorable($part)) {
                    continue;
                }

                if (!is_array($part) || $part[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    $valid = false;
                    break;
                }

                $literal .= $this->literalValue($part[1]);
            }

            if ($valid && $literal !== '') {
                $keys[] = $literal;
            }
        }

        return $keys;
    }

    private function isIgnorable(mixed $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    private function literalValue(string $literal): string
    {
        $quote = $literal[0] ?? '';
        $value = substr($literal, 1, -1);

        return $quote === "'"
        ? str_replace(["\\\\", "\\'"], ["\\", "'"], $value)
        : stripcslashes($value);
    }
}
