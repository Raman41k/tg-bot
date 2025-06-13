<?php

namespace App\Telegram;

use App\Models\TelegramUser;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Telegraph;
use Illuminate\Support\Stringable;

class Handler extends WebhookHandler
{
    public function start()
    {
        $user = $this->message->from();

        TelegramUser::updateOrCreate([
                'telegram_id' => $user->id(),
                'username' => $user->userName(),
                'first_name' => $user->firstName(),
                'last_name' => $user->lastName(),
            ]
        );

        $username = $this->getUserName($user);

        $this->reply("👋 Hello @$username");

        $this->bot->registerCommands([
            'start' => 'This is start command!',
            'help' => 'Available commands:!',
        ])->send();
    }

    public function help()
    {
        $this->chat
            ->message(
                "Доступні команди:\n\n" .
                "/start - Запуск бота та реєстрація користувача.\n" .
                "/help - Вивід довідки по командам бота."
            )->send();
    }


    protected function handleUnknownCommand(Stringable $text): void
    {
        $this->reply('Unknown command ' . $text . '!');
    }

    protected function handleChatMessage(Stringable $text): void
    {
        $this->reply($text);
    }

    private function getUserName($user): string
    {
        $username = '';

        if ($user->firstName() || $user->lastName()) {
            $username = trim($user->firstName() . ' ' . $user->lastName());
        } elseif ($user->userName()) {
            $username = '@' . $user->userName();
        } else {
            $username = 'friend';
        }

        return $username;
    }
}
