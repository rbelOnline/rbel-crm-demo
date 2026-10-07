<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\EmailImage;
use App\Models\EmailTemplate;
use App\Models\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(EmailImage::DISK);
    }

    private function upload(): array
    {
        return $this->postJson('/api/email-images', ['image' => UploadedFile::fake()->image('logo.png', 240, 80)])
            ->assertCreated()->json('data');
    }

    public function test_upload_returns_token_and_image_is_served_to_authenticated_users(): void
    {
        $this->signIn();
        $data = $this->upload();

        $this->assertSame("{{image:{$data['id']}}}", $data['token']);
        $image = EmailImage::findOrFail($data['id']);
        Storage::disk(EmailImage::DISK)->assertExists($image->path);
        $this->assertStringStartsWith(EmailImage::DIRECTORY.'/', $image->path);
        $this->assertNotSame('logo.png', basename($image->path)); // stored under a random name

        $this->get($data['url'])->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_only_raster_images_up_to_2mb_are_accepted(): void
    {
        $this->signIn();
        $this->postJson('/api/email-images', ['image' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->postJson('/api/email-images', ['image' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->postJson('/api/email-images', ['image' => UploadedFile::fake()->image('big.jpg')->size(3000)])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertSame(0, EmailImage::count());
    }

    public function test_template_image_tokens_must_exist_and_preview_renders_them_safely(): void
    {
        $this->signIn();
        $id = $this->upload()['id'];

        $this->postJson('/api/email-templates', ['name' => 'Bad', 'subject' => 'Hi', 'body' => 'See {{image:999999}}', 'status' => 'draft'])
            ->assertUnprocessable()->assertJsonValidationErrors('body');

        $templateId = $this->postJson('/api/email-templates', [
            'name' => 'With logo', 'subject' => 'Hello', 'body' => "<b>Hi</b> {{first_name}}\n{{image:{$id}}}", 'status' => 'active',
        ])->assertCreated()->json('data.id');

        $rendered = $this->postJson("/api/email-templates/{$templateId}/preview")->assertOk()->json('data');
        $this->assertStringContainsString('<img src="/api/email-images/'.$id.'"', $rendered['html']);
        // Author markup is still escaped; only our own <img> tag is real HTML.
        $this->assertStringContainsString('&lt;b&gt;Hi&lt;/b&gt;', $rendered['html']);
        $this->assertStringContainsString('[Image]', $rendered['text']);
        $this->assertSame([], $rendered['unresolved']);
    }

    public function test_sent_email_embeds_the_image_inline(): void
    {
        $this->signIn('advisor');
        $id = $this->upload()['id'];
        $template = EmailTemplate::factory()->create(['status' => 'active', 'subject' => 'Hi {{first_name}}', 'body' => "Hello {{first_name}}\n{{image:{$id}}}"]);
        $person = Client::factory()->create(['first_name' => 'Ana', 'email' => 'ana@example.test']);

        $this->postJson("/api/email-templates/{$template->id}/send", ['client_id' => $person->id])->assertCreated();

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        /** @var Email $email */
        $email = $sent[0]->getOriginalMessage();

        $cid = TemplatedMail::contentId(EmailImage::findOrFail($id));
        $this->assertStringContainsString('src="cid:'.$cid.'"', $email->getHtmlBody());
        $inline = collect($email->getAttachments())->filter(fn ($part) => $part->getDisposition() === 'inline');
        $this->assertCount(1, $inline);
        $this->assertSame($cid, $inline->first()->getContentId());
    }

    public function test_sending_is_blocked_when_an_image_was_removed(): void
    {
        $this->signIn('advisor');
        $id = $this->upload()['id'];
        $template = EmailTemplate::factory()->create(['status' => 'active', 'body' => "Hi\n{{image:{$id}}}"]);
        EmailImage::whereKey($id)->delete();
        $person = Client::factory()->create(['email' => 'ana@example.test']);

        $this->postJson("/api/email-templates/{$template->id}/send", ['client_id' => $person->id])
            ->assertUnprocessable()->assertJsonFragment(['message' => "This template refers to an image that no longer exists (image:{$id}). Edit the template and insert the image again."]);
    }
}
