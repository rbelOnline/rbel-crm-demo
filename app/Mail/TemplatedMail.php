<?php

namespace App\Mail;

use App\Models\EmailImage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

/**
 * A client email built from an EmailTemplate. The HTML body arrives already
 * escaped by TemplateRenderer. Template images are embedded as inline parts
 * and referenced from the body as src="cid:…", so they display without the
 * recipient reaching this server.
 */
class TemplatedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<EmailImage>  $inlineImages
     * @param  User|null  $sender  the CRM user sending it: shown as the sender name, and replies go to them
     */
    public function __construct(
        public string $renderedSubject,
        public string $htmlBody,
        public string $textBody,
        public array $inlineImages = [],
        public ?User $sender = null,
    ) {
        $this->withSymfonyMessage(function (Email $message) {
            foreach ($this->inlineImages as $image) {
                $message->addPart(
                    (new DataPart(new File($image->absolutePath()), $image->original_name, $image->mime))
                        ->asInline()
                        ->setContentId(self::contentId($image))
                );
            }
        });
    }

    /** Content-ID the body's <img src="cid:…"> points at. */
    public static function contentId(EmailImage $image): string
    {
        return "email-image-{$image->id}@rbel-crm";
    }

    /**
     * Sent through the app's mailbox (MAIL_FROM_ADDRESS must be the SMTP account itself, or
     * Gmail and others rewrite or reject it), but named after the user, with replies to them.
     */
    public function envelope(): Envelope
    {
        if (! $this->sender) {
            return new Envelope(subject: $this->renderedSubject);
        }

        return new Envelope(
            from: new Address(config('mail.from.address'), "{$this->sender->name} via ".config('app.name')),
            replyTo: [new Address($this->sender->email, $this->sender->name)],
            subject: $this->renderedSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.templated',
            text: 'mail.templated-text',
        );
    }
}
