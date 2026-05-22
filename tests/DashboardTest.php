<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Models\EmailMessageEvent;
use STS\Postmaster\Tests\Stubs\Account;
use STS\Postmaster\Tests\Stubs\Tenant;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('postmaster.dashboard.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    public function testDashboardIsForbiddenWithoutAGate()
    {
        // The environment is "testing", not "local", so the default-deny
        // gate must reject access.
        $this->get('/postmaster')->assertForbidden();
    }

    public function testDashboardDeniesAccessWhenTheGateFails()
    {
        Postmaster::auth(fn () => false);

        $this->get('/postmaster')->assertForbidden();
    }

    public function testOverviewLoadsWhenTheGatePasses()
    {
        Postmaster::auth(fn () => true);
        EmailMessage::create(['message_id' => 'm1', 'status' => EmailEvent::EVENT_DELIVERED]);

        $this->get('/postmaster')
            ->assertOk()
            ->assertSee('Overview');
    }

    public function testMessagesListFiltersByStatus()
    {
        Postmaster::auth(fn () => true);
        EmailMessage::create(['message_id' => 'd1', 'recipient' => 'delivered@example.com', 'status' => EmailEvent::EVENT_DELIVERED]);
        EmailMessage::create(['message_id' => 'b1', 'recipient' => 'bounced@example.com', 'status' => EmailEvent::EVENT_BOUNCED]);

        $this->get('/postmaster/messages?status=bounced')
            ->assertOk()
            ->assertSee('bounced@example.com')
            ->assertDontSee('delivered@example.com');
    }

    public function testProviderFilterUsesStoredProviderNames()
    {
        Postmaster::auth(fn () => true);
        EmailMessage::create(['message_id' => 's1', 'recipient' => 'sg@example.com', 'provider' => 'SendGrid']);
        EmailMessage::create(['message_id' => 'p1', 'recipient' => 'pm@example.com', 'provider' => 'Postmark']);

        // The filter options are the provider names as actually stored
        // ("SendGrid"), not the lower-case config keys.
        $this->get('/postmaster/messages')
            ->assertOk()
            ->assertSee('value="SendGrid"', false);

        $this->get('/postmaster/messages?provider=SendGrid')
            ->assertOk()
            ->assertSee('sg@example.com')
            ->assertDontSee('pm@example.com');
    }

    public function testMessagesListFiltersByTag()
    {
        Postmaster::auth(fn () => true);
        EmailMessage::create(['message_id' => 'b1', 'recipient' => 'billing@example.com', 'tags' => ['billing']]);
        EmailMessage::create(['message_id' => 'o1', 'recipient' => 'onboard@example.com', 'tags' => ['onboarding']]);

        $this->get('/postmaster/messages?tag=billing')
            ->assertOk()
            ->assertSee('billing@example.com')
            ->assertDontSee('onboard@example.com');
    }

    public function testMessageDetailShowsTags()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['message_id' => 'm1', 'tags' => ['billing', 'q3']]);

        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertSee('Tags')
            ->assertSee('q3');
    }

    public function testMessageSubjectIsEscapedInThePageTitle()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create([
            'message_id' => 'm1',
            'subject'    => '</title><script>alert(1)</script>',
        ]);

        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function testStoredEmailContentIsRenderedWithARestrictiveCsp()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['message_id' => 'm1', 'html_body' => '<p>Hello</p>']);

        // The preview iframe carries a CSP so remote subresources (tracking
        // pixels, remote images) can't fire when a message is opened.
        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertSee('Content-Security-Policy', false);
    }

    public function testRemoteImagesAreBlockedWithAnOptInBar()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create([
            'message_id' => 'm1',
            'html_body'  => '<p>Hi</p><img src="https://tracker.example/pixel.png">',
        ]);

        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertSee('img-src data:;', false)   // remote images blocked
            ->assertSee('Show images');
    }

    public function testRemoteImagesCanBeShownOnDemand()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create([
            'message_id' => 'm1',
            'html_body'  => '<img src="https://tracker.example/pixel.png">',
        ]);

        $this->get('/postmaster/messages/'.$message->getKey().'?images=1')
            ->assertOk()
            ->assertSee('img-src data: https: http:;', false)
            ->assertDontSee('Show images');
    }

    public function testTheImageBarIsHiddenForDataUriImages()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create([
            'message_id' => 'm1',
            'html_body'  => '<img src="data:image/png;base64,iVBORw0KGgo=">',
        ]);

        // A data: image is not blocked, so there is nothing to opt into.
        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertDontSee('Show images');
    }

    public function testShortContainsFilterTermsAreIgnored()
    {
        Postmaster::auth(fn () => true);
        EmailMessage::create(['message_id' => 'a1', 'recipient' => 'alice@example.com']);
        EmailMessage::create(['message_id' => 'b1', 'recipient' => 'bob@example.com']);

        // A two-character term is below the minimum — the filter is skipped
        // rather than running an unindexed scan, so every row still shows.
        $this->get('/postmaster/messages?recipient=al')
            ->assertOk()
            ->assertSee('alice@example.com')
            ->assertSee('bob@example.com');
    }

    public function testAlpineIsServed()
    {
        Postmaster::auth(fn () => true);

        $response = $this->get('/postmaster/assets/alpine.js');

        $response->assertOk();
        $this->assertStringContainsString('javascript', (string) $response->headers->get('Content-Type'));
    }

    public function testMessageDetailLoads()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['message_id' => 'm1', 'subject' => 'Welcome aboard', 'status' => 'delivered']);

        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertSee('Welcome aboard');
    }

    public function testTenantColumnShowsLabelsFromTheTenantModel()
    {
        Postmaster::auth(fn () => true);
        config(['postmaster.persistence.tenant_model' => Tenant::class]);

        Schema::create('tenants', function ($table) {
            $table->id();
            $table->string('name');
        });
        $tenant = Tenant::create(['name' => 'Acme Corp']);

        EmailMessage::create([
            'message_id' => 'm1',
            'recipient'  => 'r@example.com',
            'tenant_id'  => $tenant->getKey(),
        ]);

        $this->get('/postmaster/messages')
            ->assertOk()
            ->assertSee('Acme Corp');
    }

    public function testTheTenantTermIsDerivedFromTheTenantModelName()
    {
        Postmaster::auth(fn () => true);
        config(['postmaster.persistence.tenant_model' => Account::class]);

        Schema::create('accounts', function ($table) {
            $table->id();
            $table->string('name');
        });
        $account = Account::create(['name' => 'Acme']);

        EmailMessage::create([
            'message_id' => 'm1',
            'recipient'  => 'r@example.com',
            'tenant_id'  => $account->getKey(),
        ]);

        // The dashboard speaks the app's language: the column header is the
        // tenant model's class name, not the generic "Tenant".
        $this->get('/postmaster/messages')
            ->assertOk()
            ->assertSee('<th>Account</th>', false);
    }

    public function testActivityListLoads()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['message_id' => 'm1', 'recipient' => 'seen@example.com']);
        EmailMessageEvent::create([
            'email_message_id' => $message->getKey(),
            'status'           => EmailEvent::EVENT_DELIVERED,
            'occurred_at'      => now(),
        ]);

        $this->get('/postmaster/activity')
            ->assertOk()
            ->assertSee('seen@example.com');
    }

    public function testActivityFeedReturnsJson()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['message_id' => 'm1', 'recipient' => 'r@example.com']);
        EmailMessageEvent::create([
            'email_message_id' => $message->getKey(),
            'status'           => EmailEvent::EVENT_DELIVERED,
            'occurred_at'      => now(),
        ]);

        $this->getJson('/postmaster/activity/feed')
            ->assertOk()
            ->assertJsonStructure(['events', 'lastId'])
            ->assertJsonFragment(['status' => EmailEvent::EVENT_DELIVERED]);
    }

    public function testAddressesListLoads()
    {
        Postmaster::auth(fn () => true);
        EmailAddress::create(['address' => 'suppressed@example.com', 'status' => EmailAddress::STATUS_SUPPRESSED]);

        $this->get('/postmaster/addresses')
            ->assertOk()
            ->assertSee('suppressed@example.com');
    }

    public function testTheLogoIsServed()
    {
        Postmaster::auth(fn () => true);

        $response = $this->get('/postmaster/assets/postmaster-hat.png');

        $response->assertOk();
        $this->assertStringContainsString('image/png', (string) $response->headers->get('Content-Type'));
    }

    public function testTheStylesheetIsServed()
    {
        Postmaster::auth(fn () => true);

        $response = $this->get('/postmaster/assets/postmaster.css');

        $response->assertOk();
        $this->assertStringContainsString('text/css', (string) $response->headers->get('Content-Type'));
    }
}
