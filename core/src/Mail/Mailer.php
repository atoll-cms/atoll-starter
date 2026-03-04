<?php

declare(strict_types=1);

namespace Atoll\Mail;

use Atoll\Support\Config;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @param array<string, string> $data */
    public function send(string $to, string $subject, string $body, array $data = []): bool
    {
        if (class_exists(PHPMailer::class)) {
            try {
                $mail = new PHPMailer(true);
                $driver = (string) Config::get($this->config, 'smtp.driver', 'mail');
                if ($driver === 'smtp') {
                    $mail->isSMTP();
                    $mail->Host = (string) Config::get($this->config, 'smtp.host', 'localhost');
                    $mail->Port = (int) Config::get($this->config, 'smtp.port', 587);
                    $mail->SMTPAuth = true;
                    $mail->Username = (string) Config::get($this->config, 'smtp.username', '');
                    $mail->Password = (string) Config::get($this->config, 'smtp.password', '');
                    $mail->SMTPSecure = (string) Config::get($this->config, 'smtp.encryption', PHPMailer::ENCRYPTION_STARTTLS);
                }

                $mail->setFrom(
                    (string) Config::get($this->config, 'smtp.from_email', 'noreply@example.com'),
                    (string) Config::get($this->config, 'smtp.from_name', 'atoll-cms')
                );
                $mail->addAddress($to);
                foreach ($data as $key => $value) {
                    $body = str_replace('{{' . $key . '}}', $value, $body);
                }

                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->isHTML(false);
                return $mail->send();
            } catch (\Throwable) {
                return false;
            }
        }

        return mail($to, $subject, $body);
    }
}
