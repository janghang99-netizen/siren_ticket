<?php

namespace App\Notifications;

use App\Models\Setting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 관리 화면(운영사·입점업체) 비밀번호 설정/재설정 메일 */
class StaffResetPassword extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = Setting::brand();
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject("[{$brand}] 관리 화면 비밀번호 설정")
            ->greeting('안녕하세요.')
            ->line("{$brand} 관리 화면 비밀번호를 설정(재설정)하는 링크예요.")
            ->action('비밀번호 설정하기', route('staff.password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]))
            ->line("링크는 {$minutes}분 동안만 쓸 수 있어요.")
            ->line('요청하지 않았다면 이 메일을 무시하세요. 비밀번호는 바뀌지 않아요.');
    }
}
