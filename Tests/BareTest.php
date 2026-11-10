<?php

namespace Omnifood\TheFork\Tests;

use Omnifood\TheFork\TheForkPlatformFactory;
use Omnifood\Testing\BareTestCase;

/** omnifood/thefork's classes load with omnisense/omnifood, omnisense/contracts and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return TheForkPlatformFactory::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts', 'symfony/deprecation-contracts', 'psr/container', 'symfony/polyfill-mbstring'];
    }
}
