<?php

namespace STS\Postmaster\Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use STS\Postmaster\Attachments\AttachmentStatus;
use STS\Postmaster\Attachments\AttachmentStore;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailAttachment;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Tests\Stubs\Account;
use STS\Postmaster\Tests\Stubs\FullMail;
use STS\Postmaster\Tests\Stubs\InlineImageMail;
use STS\Postmaster\Tests\Stubs\MixedAttachmentMail;
use STS\Postmaster\Tests\Stubs\Tenant;
use STS\Postmaster\Tests\Stubs\User;

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
        EmailMessage::create(['provider_message_id' => 'm1', 'status' => EmailEvent::STATUS_DELIVERED]);

        $this->get('/postmaster')
            ->assertOk()
            ->assertSee('Overview');
    }

    public function testMessagesListFiltersByStatus()
    {
        Postmaster::auth(fn () => true);
        EmailMessage::create(['provider_message_id' => 'd1', 'to_address' => 'delivered@example.com', 'status' => EmailEvent::STATUS_DELIVERED]);
        EmailMessage::create(['provider_message_id' => 'b1', 'to_address' => 'bounced@example.com', 'status' => EmailEvent::STATUS_BOUNCED]);

        $this->get('/postmaster/messages?status=bounced')
            ->assertOk()
            ->assertSee('bounced@example.com')
            ->assertDontSee('delivered@example.com');
    }

    public function testProviderFilterUsesStoredProviderNames()
    {
        Postmaster::auth(fn () => true);
        EmailMessage::create(['provider_message_id' => 's1', 'to_address' => 'sg@example.com', 'provider' => 'SendGrid']);
        EmailMessage::create(['provider_message_id' => 'p1', 'to_address' => 'pm@example.com', 'provider' => 'Postmark']);

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

    public function testProviderFilterIsHiddenWithASingleProvider()
    {
        Postmaster::auth(fn () => true);
        EmailMessage::create(['provider_message_id' => 'a', 'provider' => 'SendGrid']);
        EmailMessage::create(['provider_message_id' => 'b', 'provider' => 'SendGrid']);

        // One provider can only ever select everything — drop the dropdown.
        $this->get('/postmaster/messages')
            ->assertOk()
            ->assertDontSee('name="provider"', false);
    }

    public function testMessagesListFiltersByTag()
    {
        Postmaster::auth(fn () => true);
        EmailMessage::create(['provider_message_id' => 'b1', 'to_address' => 'billing@example.com', 'tags' => ['billing']]);
        EmailMessage::create(['provider_message_id' => 'o1', 'to_address' => 'onboard@example.com', 'tags' => ['onboarding']]);

        $this->get('/postmaster/messages?tag=billing')
            ->assertOk()
            ->assertSee('billing@example.com')
            ->assertDontSee('onboard@example.com');
    }

    public function testMessageDetailShowsTags()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['provider_message_id' => 'm1', 'tags' => ['billing', 'q3']]);

        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertSee('Tags')
            ->assertSee('q3');
    }

    public function testMessageSubjectIsEscapedOnTheDetailPage()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create([
            'provider_message_id' => 'm1',
            'subject'    => '</title><script>alert(1)</script>',
        ]);

        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function testStoredEmailContentIsRenderedWithARestrictiveCsp()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['provider_message_id' => 'm1', 'html_body' => '<p>Hello</p>']);

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
            'provider_message_id' => 'm1',
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
            'provider_message_id' => 'm1',
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
            'provider_message_id' => 'm1',
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
        EmailMessage::create(['provider_message_id' => 'a1', 'to_address' => 'alice@example.com']);
        EmailMessage::create(['provider_message_id' => 'b1', 'to_address' => 'bob@example.com']);

        // A two-character term is below the minimum — the filter is skipped
        // rather than running an unindexed scan, so every row still shows.
        $this->get('/postmaster/messages?to=al')
            ->assertOk()
            ->assertSee('alice@example.com')
            ->assertSee('bob@example.com');
    }

    public function testRecipientToFilterIsNotClobberedByTheEmptyDateRangeInput()
    {
        Postmaster::auth(fn () => true);
        EmailMessage::create(['provider_message_id' => 'a1', 'to_address' => 'alice@example.com']);
        EmailMessage::create(['provider_message_id' => 'b1', 'to_address' => 'bob@example.com']);

        // A real browser submits the recipient "To" field alongside the
        // (empty) date-range end input. Those used to share name="to", so the
        // empty date input clobbered the recipient value and the filter never
        // applied. The date range now submits date_to, so "To" stands alone.
        $this->get('/postmaster/messages?to=alice&date_from=&date_to=')
            ->assertOk()
            ->assertSee('alice@example.com')
            ->assertDontSee('bob@example.com');
    }

    public function testMessagesDateRangeFilterNarrowsByCreatedAt()
    {
        Postmaster::auth(fn () => true);
        EmailMessage::create([
            'provider_message_id' => 'old', 'to_address' => 'old@example.com',
            'created_at' => '2026-01-01 10:00:00',
        ]);
        EmailMessage::create([
            'provider_message_id' => 'new', 'to_address' => 'new@example.com',
            'created_at' => '2026-06-15 10:00:00',
        ]);

        $this->get('/postmaster/messages?date_from=2026-06-01&date_to=2026-06-30')
            ->assertOk()
            ->assertSee('new@example.com')
            ->assertDontSee('old@example.com');
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
        $message = EmailMessage::create(['provider_message_id' => 'm1', 'subject' => 'Welcome aboard', 'status' => 'delivered']);

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
            'provider_message_id' => 'm1',
            'to_address'  => 'r@example.com',
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
            'provider_message_id' => 'm1',
            'to_address'  => 'r@example.com',
            'tenant_id'  => $account->getKey(),
        ]);

        // The dashboard speaks the app's language: the column header is the
        // tenant model's class name, not the generic "Tenant".
        $this->get('/postmaster/messages')
            ->assertOk()
            ->assertSee('<th>Account</th>', false);
    }

    public function testMessageDetailLinksToTheRecipientView()
    {
        Postmaster::auth(fn () => true);
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });
        $user = User::create(['name' => 'Alice']);

        $message = EmailMessage::create([
            'provider_message_id'  => 'm1',
            'to_address'            => 'alice@example.com',
            'recipient_type' => $user->getMorphClass(),
            'recipient_id'   => $user->getKey(),
        ]);

        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertSee('Recipient')
            ->assertSee('Alice')
            ->assertSee('/postmaster/recipient/', false);
    }

    public function testRecipientViewShowsOnlyMessagesForThatRecipient()
    {
        Postmaster::auth(fn () => true);
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });
        $alice = User::create(['name' => 'Alice']);
        $bob   = User::create(['name' => 'Bob']);

        EmailMessage::create([
            'provider_message_id'  => 'a',
            'to_address'            => 'alice@example.com',
            'subject'              => 'For Alice',
            'recipient_type' => $alice->getMorphClass(),
            'recipient_id'   => $alice->getKey(),
        ]);
        EmailMessage::create([
            'provider_message_id'  => 'b',
            'to_address'            => 'bob@example.com',
            'subject'              => 'For Bob',
            'recipient_type' => $bob->getMorphClass(),
            'recipient_id'   => $bob->getKey(),
        ]);

        $this->get('/postmaster/recipient/'.urlencode($alice->getMorphClass()).'/'.$alice->getKey())
            ->assertOk()
            ->assertSee('Emails for Alice')
            ->assertSee('For Alice')
            ->assertDontSee('For Bob');
    }

    public function testResendReplaysAStoredMessage()
    {
        Postmaster::auth(fn () => true);
        \Illuminate\Support\Facades\Mail::fake();

        $message = EmailMessage::create([
            'provider_message_id' => 'orig',
            'to_address'           => 'alice@example.com',
            'from_address'        => 'no-reply@acme.test',
            'subject'             => 'Receipt',
            'html_body'           => '<p>Thanks!</p>',
            'tags'                => ['billing'],
        ]);

        $this->post('/postmaster/messages/'.$message->getKey().'/resend')
            ->assertRedirect('/postmaster/messages/'.$message->getKey())
            ->assertSessionHas('postmasterFlash');

        \Illuminate\Support\Facades\Mail::assertSent(\STS\Postmaster\Mail\ResentMessage::class, function ($mail) use ($message) {
            return $mail->record->is($message);
        });
    }

    public function testResentMessageReplaysTheStoredHeadersAndBody()
    {
        $message = EmailMessage::create([
            'provider_message_id' => 'orig',
            'to_address'           => 'alice@example.com',
            'from_address'        => 'no-reply@acme.test',
            'subject'             => 'Receipt',
            'html_body'           => '<p>Thanks!</p>',
            'tags'                => ['billing'],
        ]);

        $mail = new \STS\Postmaster\Mail\ResentMessage($message);
        $mail->build();

        $this->assertTrue($mail->hasTo('alice@example.com'));
        $this->assertTrue($mail->hasFrom('no-reply@acme.test'));
        $this->assertSame('Receipt', $mail->subject);
        $this->assertTrue($mail->hasTag('billing'));
        $this->assertTrue($mail->hasTag('resent'));
    }

    public function testResendFlashesAnErrorWhenTheSendFails()
    {
        Postmaster::auth(fn () => true);
        \Illuminate\Support\Facades\Mail::shouldReceive('send')->andThrow(new \RuntimeException('no mail transport configured'));

        $message = EmailMessage::create([
            'provider_message_id' => 'orig',
            'to_address'          => 'alice@example.com',
            'html_body'           => '<p>Thanks!</p>',
        ]);

        $this->post('/postmaster/messages/'.$message->getKey().'/resend')
            ->assertRedirect('/postmaster/messages/'.$message->getKey())
            ->assertSessionHas('postmasterError');
    }

    public function testResendRefusesWhenNoContentIsStored()
    {
        Postmaster::auth(fn () => true);
        \Illuminate\Support\Facades\Mail::fake();

        $message = EmailMessage::create([
            'provider_message_id' => 'orig',
            'to_address'           => 'alice@example.com',
        ]);

        $this->post('/postmaster/messages/'.$message->getKey().'/resend')
            ->assertRedirect('/postmaster/messages/'.$message->getKey())
            ->assertSessionHas('postmasterError');

        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function testActivityListLoads()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['provider_message_id' => 'm1', 'to_address' => 'seen@example.com']);
        EmailActivity::create([
            'email_message_id' => $message->getKey(),
            'status'           => EmailEvent::STATUS_DELIVERED,
            'occurred_at'      => now(),
        ]);

        $this->get('/postmaster/activity')
            ->assertOk()
            ->assertSee('seen@example.com');
    }

    public function testActivityFeedReturnsJson()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['provider_message_id' => 'm1', 'to_address' => 'r@example.com']);
        EmailActivity::create([
            'email_message_id' => $message->getKey(),
            'status'           => EmailEvent::STATUS_DELIVERED,
            'occurred_at'      => now(),
        ]);

        $this->getJson('/postmaster/activity/feed')
            ->assertOk()
            ->assertJsonStructure(['events', 'lastId'])
            ->assertJsonFragment(['status' => EmailEvent::STATUS_DELIVERED]);
    }

    public function testDashboardUnsuppressLiftsTheLocalSuppression()
    {
        Postmaster::auth(fn () => true);

        EmailAddress::create([
            'address'       => 'alice@example.com',
            'status'        => EmailAddress::STATUS_SUPPRESSED,
            'reason'        => EmailAddress::REASON_BOUNCED,
            'suppressed_at' => now(),
        ]);

        $this->post('/postmaster/addresses/unsuppress', ['address' => 'alice@example.com'])
            ->assertRedirect('/postmaster/addresses')
            ->assertSessionHas('postmasterFlash');

        $this->assertSame(EmailAddress::STATUS_ACTIVE, EmailAddress::first()->status);
    }

    public function testDashboardUnsuppressRejectsAnInvalidAddress()
    {
        Postmaster::auth(fn () => true);

        $this->post('/postmaster/addresses/unsuppress', ['address' => 'not-an-email'])
            ->assertRedirect('/postmaster/addresses')
            ->assertSessionHas('postmasterError');
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

    public function testAssetUrlsAreCacheBusted()
    {
        $url = app(\STS\Postmaster\Postmaster::class)->asset('css');

        $this->assertStringStartsWith(route('postmaster.css'), $url);
        $this->assertMatchesRegularExpression('/\?v=[0-9a-f]{8}$/', $url);
    }

    public function testDashboardLinksACacheBustedStylesheet()
    {
        Postmaster::auth(fn () => true);

        $this->get('/postmaster/messages')
            ->assertOk()
            ->assertSee('assets/postmaster.css?v=', false);
    }

    public function testMessageDetailShowsADeleteButton()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['provider_message_id' => 'm1', 'to_address' => 'a@example.com']);

        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertSee('Delete')
            ->assertSee(route('postmaster.messages.destroy', $message), false)
            // The confirm makes clear this doesn't unsend a delivered email.
            ->assertSee('does NOT recall', false);
    }

    public function testDeleteRemovesTheMessageAndItsTimeline()
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['provider_message_id' => 'm1', 'to_address' => 'a@example.com', 'subject' => 'Secret']);
        EmailActivity::create(['email_message_id' => $message->getKey(), 'status' => 'sent', 'occurred_at' => now()]);
        EmailActivity::create(['email_message_id' => $message->getKey(), 'status' => 'delivered', 'occurred_at' => now()]);

        $this->delete('/postmaster/messages/'.$message->getKey())
            ->assertRedirect('/postmaster/messages')
            ->assertSessionHas('postmasterFlash');

        $this->assertSame(0, EmailMessage::count());
        $this->assertSame(0, EmailActivity::count());
    }

    public function testDeletingAMessageDeletesItsTimelineAtTheModelLevel()
    {
        $message = EmailMessage::create(['provider_message_id' => 'm1', 'to_address' => 'a@example.com']);
        EmailActivity::create(['email_message_id' => $message->getKey(), 'status' => 'sent', 'occurred_at' => now()]);

        $message->delete();

        $this->assertSame(0, EmailActivity::count());
    }

    public function testDeletingAMessageKeepsItsResends()
    {
        // Deleting a message must not cascade to its resends. (The resent_from_id
        // link itself is ON DELETE SET NULL at the database level — enforced in
        // production; SQLite here doesn't enforce foreign keys, so we assert the
        // one thing that's ours: the resend row survives.)
        $original = EmailMessage::create(['provider_message_id' => 'orig', 'to_address' => 'a@example.com']);
        $resend   = EmailMessage::create([
            'provider_message_id' => 'rs',
            'to_address'          => 'a@example.com',
            'resent_from_id'      => $original->getKey(),
        ]);

        $original->delete();

        $this->assertNotNull($resend->fresh());
    }

    public function testDeletingOneRecipientLeavesSiblingsAndTheAddress()
    {
        Postmaster::auth(fn () => true);
        $to = EmailMessage::create(['provider_message_id' => 'm1', 'to_address' => 'to@example.com', 'recipient_role' => 'to']);
        $cc = EmailMessage::create(['provider_message_id' => 'm1', 'to_address' => 'cc@example.com', 'recipient_role' => 'cc']);
        $address = EmailAddress::create(['address' => 'to@example.com', 'status' => EmailAddress::STATUS_ACTIVE]);

        $this->delete('/postmaster/messages/'.$to->getKey())->assertRedirect('/postmaster/messages');

        $this->assertNull(EmailMessage::find($to->getKey()));
        $this->assertNotNull(EmailMessage::find($cc->getKey()));      // sibling untouched
        $this->assertNotNull(EmailAddress::find($address->getKey())); // suppression row untouched
    }

    public function testTheMessageListFlagsMessagesThatCarriedFiles()
    {
        Postmaster::auth(fn () => true);
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('files@example.com')->send(new FullMail);

        $this->get('/postmaster/messages')
            ->assertOk()
            ->assertSee('Has attachments');
    }

    /**
     * An embedded logo isn't something the recipient sees paperclipped on, so
     * flagging it would put a clip on every templated email in the list.
     */
    public function testAnEmbeddedImageAloneDoesNotFlagTheMessage()
    {
        Postmaster::auth(fn () => true);
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('inline@example.com')->send(new InlineImageMail);

        $this->assertSame(1, EmailAttachment::where('disposition', 'inline')->count());

        $this->get('/postmaster/messages')
            ->assertOk()
            ->assertDontSee('Has attachments');
    }

    public function testAStoredAttachmentOnALocalDiskIsStreamed()
    {
        Postmaster::auth(fn () => true);
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('to@example.com')->send(new FullMail);

        $message    = EmailMessage::first();
        $attachment = $message->attachments->first();

        $response = $this->get("/postmaster/messages/{$message->getKey()}/attachments/{$attachment->getKey()}");

        $response->assertOk();
        $response->assertDownload('invoice.pdf');
        $this->assertSame('PDF DATA', $response->streamedContent());
    }

    /**
     * Stands a stored attachment up on a disk shaped like S3, and hands back
     * the mock so the call it receives can be asserted on.
     */
    protected function attachmentOnCloudDisk(callable $expectations): array
    {
        Postmaster::auth(fn () => true);
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
            'filesystems.disks.cloud.driver'           => 's3',
        ]);

        Mail::to('to@example.com')->send(new FullMail);

        $message    = EmailMessage::first();
        $attachment = $message->attachments->first();
        $attachment->forceFill(['disk' => 'cloud'])->save();

        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('providesTemporaryUrls')->andReturn(true);
        $expectations($disk, $attachment);

        Storage::set('cloud', $disk);

        return [$message, $attachment];
    }

    /**
     * The endpoint authorizes first and only then mints a URL, so the gate and
     * the ownership check still run on every download — the redirect is how
     * the bytes travel, not a second way in.
     */
    public function testACloudDiskGetsARedirectToASignedUrl()
    {
        [$message, $attachment] = $this->attachmentOnCloudDisk(function ($disk) {
            $disk->shouldReceive('temporaryUrl')->once()->andReturn('https://bucket.example.test/signed');
        });

        $this->get("/postmaster/messages/{$message->getKey()}/attachments/{$attachment->getKey()}")
            ->assertRedirect('https://bucket.example.test/signed');
    }

    /**
     * Paths are content-addressed, so a bare signed URL would deliver the file
     * named after its sha256 with no type. The original name and mime type
     * have to ride along on the URL for the download to be usable.
     */
    public function testASignedUrlCarriesTheOriginalFilenameAndType()
    {
        [$message, $attachment] = $this->attachmentOnCloudDisk(function ($disk, $attachment) {
            $disk->shouldReceive('temporaryUrl')->once()->with(
                $attachment->path,
                \Mockery::type(\DateTimeInterface::class),
                \Mockery::on(fn (array $options) => str_contains($options['ResponseContentDisposition'], 'invoice.pdf')
                    && $options['ResponseContentType'] === 'application/pdf'),
            )->andReturn('https://bucket.example.test/signed');
        });

        $this->get("/postmaster/messages/{$message->getKey()}/attachments/{$attachment->getKey()}")
            ->assertRedirect('https://bucket.example.test/signed');
    }

    /**
     * A signed link is bearer authority until it expires, so the window is a
     * knob rather than a constant.
     */
    public function testTheSignedUrlLifetimeIsConfigurable()
    {
        config(['postmaster.persistence.attachments.signed_url_ttl' => 60]);

        [$message, $attachment] = $this->attachmentOnCloudDisk(function ($disk) {
            $disk->shouldReceive('temporaryUrl')->once()->with(
                \Mockery::any(),
                \Mockery::on(fn (\DateTimeInterface $expires) => abs($expires->getTimestamp() - now()->addSeconds(60)->timestamp) <= 5),
                \Mockery::any(),
            )->andReturn('https://bucket.example.test/signed');
        });

        $this->get("/postmaster/messages/{$message->getKey()}/attachments/{$attachment->getKey()}")
            ->assertRedirect('https://bucket.example.test/signed');
    }

    /**
     * Turning the lifetime off keeps every download flowing through the app,
     * for a deployment that would rather no link exist at all.
     */
    public function testASignedUrlIsNeverMintedWhenTheLifetimeIsZero()
    {
        config(['postmaster.persistence.attachments.signed_url_ttl' => 0]);

        [$message, $attachment] = $this->attachmentOnCloudDisk(function ($disk) {
            $disk->shouldReceive('temporaryUrl')->never();
            $disk->shouldReceive('download')->once()->andReturn(response()->streamDownload(fn () => print 'PDF DATA', 'invoice.pdf'));
        });

        $this->get("/postmaster/messages/{$message->getKey()}/attachments/{$attachment->getKey()}")
            ->assertOk()
            ->assertDownload('invoice.pdf');
    }

    public function testDownloadingAnUnavailableAttachmentIsNotFound()
    {
        Postmaster::auth(fn () => true);
        Storage::fake('local');
        config(['postmaster.persistence.store_content' => true]);

        Mail::to('to@example.com')->send(new FullMail);

        $message    = EmailMessage::first();
        $attachment = $message->attachments->first();

        $this->get("/postmaster/messages/{$message->getKey()}/attachments/{$attachment->getKey()}")
            ->assertNotFound();
    }

    public function testAnAttachmentFromAnotherMessageIsNotFound()
    {
        Postmaster::auth(fn () => true);
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('first@example.com')->send(new FullMail);
        $first = EmailMessage::first();

        $other = EmailAttachment::create([
            'provider_message_id' => 'unrelated',
            'filename'            => 'secret.pdf',
            'size'                => 3,
            'checksum'            => str_repeat('c', 64),
            'disposition'         => 'attachment',
            'status'              => AttachmentStatus::Stored,
            'disk'                => 'local',
            'path'                => 'postmaster/attachments/cc/cc/'.str_repeat('c', 64),
        ]);

        $this->get("/postmaster/messages/{$first->getKey()}/attachments/{$other->getKey()}")
            ->assertNotFound();
    }

    public function testTheAttachmentCardListsFilesAndHidesEmbeddedImages()
    {
        Postmaster::auth(fn () => true);
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('to@example.com')->send(new MixedAttachmentMail);

        $response = $this->get('/postmaster/messages/'.EmailMessage::first()->getKey());

        $response->assertOk();
        $response->assertSee('Attachments');
        $response->assertSee('invoice.pdf');
        $response->assertSee('1 file');
        // The embedded logo belongs to the body, not the attachment list.
        $response->assertDontSee('logo.png');
    }

    public function testAMessageWithOnlyAnEmbeddedImageShowsNoAttachmentCard()
    {
        Postmaster::auth(fn () => true);
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('to@example.com')->send(new InlineImageMail);

        $this->get('/postmaster/messages/'.EmailMessage::first()->getKey())
            ->assertOk()
            ->assertDontSee('Attachments');
    }

    public function testAnUnavailableAttachmentShowsItsStatusInsteadOfALink()
    {
        Postmaster::auth(fn () => true);
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('to@example.com')->send(new FullMail);

        $message = EmailMessage::first();
        app(AttachmentStore::class)->forget($message->attachments->first(), AttachmentStatus::Evicted);

        $response = $this->get('/postmaster/messages/'.$message->getKey());

        $response->assertOk();
        $response->assertSee('invoice.pdf');
        $response->assertSee('evicted');
        $response->assertDontSee('/attachments/', false);
    }

    public function testTheEmbeddedImageNoticeAppearsOnlyWhenOneCannotBeShown()
    {
        Postmaster::auth(fn () => true);
        Storage::fake('local');
        config([
            'postmaster.persistence.store_content'     => true,
            'postmaster.persistence.attachments.store' => true,
        ]);

        Mail::to('to@example.com')->send(new InlineImageMail);
        $message = EmailMessage::first();

        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertDontSee("no longer stored, and can't be shown", false);

        app(AttachmentStore::class)->forget($message->attachments->first(), AttachmentStatus::Pruned);

        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertSee("no longer stored, and can't be shown", false);
    }

    public function testDownloadingAnAttachmentRequiresDashboardAuthorization()
    {
        Postmaster::auth(fn () => false);
        Storage::fake('local');

        $message = EmailMessage::create(['provider_message_id' => 'm1', 'to_address' => 'to@example.com']);

        $attachment = EmailAttachment::create([
            'provider_message_id' => 'm1',
            'filename'            => 'invoice.pdf',
            'size'                => 8,
            'checksum'            => str_repeat('d', 64),
            'disposition'         => 'attachment',
            'status'              => AttachmentStatus::Stored,
            'disk'                => 'local',
            'path'                => 'postmaster/attachments/dd/dd/'.str_repeat('d', 64),
        ]);

        $this->get("/postmaster/messages/{$message->getKey()}/attachments/{$attachment->getKey()}")
            ->assertForbidden();
    }
}
