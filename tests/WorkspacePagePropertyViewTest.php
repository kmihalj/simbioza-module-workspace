<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class WorkspacePagePropertyViewTest extends TestCase
{
    /**
     * EN: The rendered form uses readable source labels while preserving all
     *     six stored property-type keys and the selected value.
     *
     * HR: Renderirani obrazac koristi razumljive izvorne oznake, a zadržava
     *     svih šest ključeva pohrane i trenutačno odabranu vrijednost.
     */
    public function testReadablePropertyLabelsPreserveTheStorageContract(): void
    {
        $renderer = new class {
            public function escape(string $value): string
            {
                return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            /**
             * EN: Renders only the actual row, without any application or database.
             *
             * HR: Renderira samo stvarni redak, bez aplikacije ili baze.
             */
            public function render(): string
            {
                $property = ['label' => 'Rok', 'type' => 'date', 'value' => '2026-11-20'];
                ob_start();
                require __DIR__ . '/../views/workspace/node-property-row.php';
                return (string)ob_get_clean();
            }
        };
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $renderer->render());

        $xpath = new DOMXPath($document);
        $options = $xpath->query('//select[@name="properties[type][]"]/option');
        $this->assertNotFalse($options);
        $actual = [];
        $selected = [];
        foreach ($options as $option) {
            $value = $option->attributes?->getNamedItem('value')?->nodeValue;
            $actual[$value] = trim($option->textContent);
            if ($option->attributes?->getNamedItem('selected') !== null) {
                $selected[] = $value;
            }
        }

        $this->assertSame([
            'text' => __('Tekst'),
            'status' => __('Status'),
            'number' => __('Broj'),
            'date' => __('Datum'),
            'user' => __('Korisnik'),
            'link' => __('Poveznica'),
        ], $actual);
        $this->assertSame(['date'], $selected);
    }
}
