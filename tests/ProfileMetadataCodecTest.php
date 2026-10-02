<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Tests;

use Fame1302\Janathan\Services\ProfileMetadataCodec;
use PHPUnit\Framework\TestCase;

final class ProfileMetadataCodecTest extends TestCase
{
    public function testMetadataRoundTripsInsideGuardedPutCommand(): void
    {
        $codec = new ProfileMetadataCodec();
        $values = [
            'color' => '#14b8a6',
            'price' => '12500.50',
            'prefix' => 'vch "special"\\',
            'validity_days' => '30',
            'start_on' => 'user_creation',
        ];

        $source = $codec->encode($values);

        $this->assertStringContainsString(':if (false) do={', $source);
        $this->assertStringContainsString(':put "janathan-profile:v1:', $source);
        $this->assertStringNotContainsString(PHP_EOL, $source);
        $this->assertSame([
            'color' => '#14b8a6',
            'price' => 12500.5,
            'prefix' => 'vch "special"\\',
            'validity_days' => 30,
            'start_on' => 'user_creation',
        ], $codec->decode($source));
    }

    public function testAppendReplacesPreviousMarkerAndPreservesScript(): void
    {
        $codec = new ProfileMetadataCodec();
        $source = "# existing login behavior\n" . $codec->encode([
            'color' => '#ef4444',
            'price' => 10,
            'prefix' => 'old',
            'validity_days' => null,
            'start_on' => 'first_login',
        ]);

        $updated = $codec->append($source, [
            'color' => '#22c55e',
            'price' => 20,
            'prefix' => 'new',
            'validity_days' => 7,
            'start_on' => 'first_login',
        ]);

        $this->assertStringContainsString('# existing login behavior', $updated);
        $this->assertSame('#22c55e', $codec->decode($updated)['color']);
        $this->assertSame('new', $codec->decode($updated)['prefix']);
        $this->assertSame(7, $codec->decode($updated)['validity_days']);
    }

    public function testMalformedMarkerIsIgnored(): void
    {
        $codec = new ProfileMetadataCodec();

        $this->assertNull($codec->decode(':put "janathan-profile:v1:not-valid-base64";'));
        $this->assertNull($codec->decode('# no Janathan metadata'));
    }
}
