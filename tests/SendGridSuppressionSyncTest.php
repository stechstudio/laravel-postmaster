<?php

namespace STS\Postmaster\Tests;

use STS\Postmaster\Providers\SendGrid\SuppressionSync;

/**
 * Regression for the SendGrid SDK URL-prefix bug.
 *
 * sendgrid/sendgrid's Client constructor bakes the API version into the
 * base URL: `https://api.sendgrid.com` + `/v3` + `/{path}`. The previous
 * code passed `'/v3/suppression/bounces'` as a path segment, so the SDK
 * built `https://api.sendgrid.com/v3/v3/suppression/bounces/...` — double
 * v3, every call 404'd. All four suppression list endpoints were broken
 * and Postmaster::unsuppress() reported cleared=['SendGrid'] anyway
 * because the SDK doesn't throw on 404.
 */
class SendGridSuppressionSyncTest extends TestCase
{
    public function testEndpointsAreRecordedRelativeToTheSdksV3Base()
    {
        // The SDK already prefixes /v3 onto every URL. Endpoints recorded
        // here must NOT start with /v3/ — passing '/v3/suppression/bounces'
        // through the SDK's path builder would produce /v3/v3/suppression/...
        $reflection = new \ReflectionClass(SuppressionSync::class);
        $lists = $reflection->getConstant('LISTS');

        $this->assertIsArray($lists);
        $this->assertNotEmpty($lists);

        foreach (array_keys($lists) as $endpoint) {
            $this->assertStringStartsNotWith(
                '/v3/',
                $endpoint,
                "Bug: endpoint [{$endpoint}] is prefixed with /v3 — the SDK's base URL already includes /v3, so the request would 404."
            );
            $this->assertStringStartsNotWith(
                '/',
                $endpoint,
                "Endpoint [{$endpoint}] starts with a slash — the SDK joins path segments with /; a leading slash produces an empty segment."
            );
        }

        // Sanity: the actual endpoints we expect after the fix.
        $this->assertArrayHasKey('suppression/bounces', $lists);
        $this->assertArrayHasKey('suppression/blocks', $lists);
        $this->assertArrayHasKey('suppression/invalid_emails', $lists);
        $this->assertArrayHasKey('suppression/spam_reports', $lists);
    }
}
