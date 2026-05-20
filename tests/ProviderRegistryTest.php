<?php

namespace STS\EmailEvents\Tests;

use STS\EmailEvents\Provider;
use STS\EmailEvents\ProviderRegistry;
use STS\EmailEvents\Providers\SendGrid\Adapter as SendGrid;

class ProviderRegistryTest extends TestCase
{
    protected function registry(): ProviderRegistry
    {
        return new ProviderRegistry(config('email-events'));
    }

    public function testResolvesAConfiguredProvider()
    {
        $this->assertInstanceOf(Provider::class, $this->registry()->get('sendgrid'));
    }

    public function testGetCachesResolvedInstances()
    {
        $registry = $this->registry();

        $this->assertSame($registry->get('mailgun'), $registry->get('mailgun'));
    }

    public function testHasAndNames()
    {
        $registry = $this->registry();

        $this->assertTrue($registry->has('postmark'));
        $this->assertFalse($registry->has('does-not-exist'));
        $this->assertEqualsCanonicalizing(
            ['sendgrid', 'postmark', 'mailgun'],
            $registry->names()
        );
    }

    public function testUnknownProviderThrows()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->registry()->get('does-not-exist');
    }

    public function testExtendRegistersACustomProvider()
    {
        $custom = new Provider('custom', SendGrid::class, fn() => true, 'log');

        $registry = $this->registry()->extend('custom', fn() => $custom);

        $this->assertTrue($registry->has('custom'));
        $this->assertContains('custom', $registry->names());
        $this->assertSame($custom, $registry->get('custom'));
    }
}
