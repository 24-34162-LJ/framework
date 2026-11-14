<?php

namespace App\Services;

use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email integration — the only code in AstraTrack that sends notification email.
 * Uses Laravel Mail; the mail server comes from .env (MAIL_MAILER=smtp + MAIL_* settings, or "log" for development).
 *
 * send() never throws: problems come back as a failed ChannelResult so a staff action is never interrupted.
 */
class EmailIntegration
{
    public function send(string $email, string $subject, string $message, ?string $link = null): ChannelResult
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ChannelResult::fail('Invalid email address');
        }

        try {
            Mail::to($email)->send(new NotificationMail($subject, $message, $link));

            return ChannelResult::ok();
        } catch (Throwable $exception) {
            return ChannelResult::fail('Email send error: '.$exception->getMessage());
        }
    }
}
