<?php

namespace App\Telegram;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\TelegramUser;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Keyboard\ReplyButton;
use DefStudio\Telegraph\Keyboard\ReplyKeyboard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Stringable;

class Handler extends WebhookHandler
{
    private $LEAVE_AS_IT_WAS = 'Залишити як є';
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
            'help' => 'Available commands!',
            'tasks' => 'Get all tasks!',
            'task' => 'Get specific task!',
            'create' => 'Create a new task!',
        ])->send();
    }

    public function help()
    {
        $this->chat
            ->message(
                "Доступні команди:\n\n" .
                "/start - Запуск бота та реєстрація користувача.\n" .
                "/help - Вивід довідки по командам бота.\n" .
                "/tasks - Вивід всіх задач.\n" .
                "/task - Вивід конкретної задачі.\n" .
                "/create - Створити нову задачу."
            )->send();
    }

    protected function handleUnknownCommand(Stringable $text): void
    {
        $this->reply('Unknown command ' . $text . '!');
    }

    public function tasks()
    {
        $response = Http::get(env('APP_URL') .  '/api/v1/tasks');

        if ($response->successful()) {
            $tasks = $response->json();
            $text = "📋 Список задач:\n\n";

            foreach ($tasks as $task) {
                $text .= "{$task['id']}. {$task['title']}\n\n";
            }

            $this->reply($text);
        } else {
            $this->reply('❌ Не вдалося отримати задачі.');
        }
    }

    public function task()
    {
        $telegramId = $this->message->from()->id();

        cache()->put("awaiting_task_id_{$telegramId}", true, now()->addMinutes(5));

        $this->reply("✏️ Введіть ID задачі (тільки число):");
    }

    protected function handleChatMessage(Stringable $text): void
    {
        $telegramId = $this->message->from()->id();
        $text = trim((string) $text);

        $creationKey = "task_creation_progress_{$telegramId}";

        if (cache()->has($creationKey)) {
            $creationProgress = cache()->get($creationKey);
            $this->handleTaskCreationStep($creationProgress, $text, $creationKey);
            return;
        }

        if (cache()->get("awaiting_task_id_{$telegramId}")) {
            cache()->forget("awaiting_task_id_{$telegramId}");

            if (!is_numeric($text)) {
                $this->reply('❌ Будь ласка, введіть коректний числовий ID задачі.');
                return;
            }

            $this->getTask($text);
            return;
        }

        $updateKey = "task_update_progress_{$telegramId}";
        $taskUpdate = cache()->get($updateKey);

        if ($taskUpdate) {
            $this->handleTaskUpdateStep($taskUpdate, $text, $updateKey);
            return;
        }

        $parts = explode(' ', $text, 3);
        $command = strtolower($parts[0] ?? '');

        switch ($command) {
            case 'task':
                $taskId = $parts[1] ?? null;
                if ($taskId === null || !is_numeric($taskId)) {
                    $this->reply("❌ Будь ласка, введіть коректний ID задачі. Наприклад: task 123");
                    return;
                }
                $this->getTask($taskId);
                return;

            case 'create':
                if (($parts[1] ?? '') !== 'task') {
                    $this->reply("❌ Невідома команда. Спробуйте create task");
                    return;
                }
                $this->createTask();
                return;

            default:
                $this->reply("❓ Невідома команда або некоректний формат. Напишіть /help для довідки.");
                break;
        }
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

    private function getTask(string $taskId)
    {
        if (!is_numeric($taskId)) {
            $this->reply("❌ Невірний ID задачі.");
            return;
        }

        $response = Http::get(env('APP_URL') . '/api/v1/tasks/' . $taskId);

        if ($response->successful()) {
            $data = $response->json();

            if (!isset($data['task'])) {
                $this->reply("⚠️ Задача з ID {$taskId} не знайдена.");
                return;
            }

            $task = $data['task'];

            $message = "📌 Задача #{$task['id']}\n" .
                "Назва: {$task['title']}\n" .
                "Опис: {$task['description']}\n" .
                "Статус: {$task['status']}\n" .
                "Пріоритет: {$task['priority']}\n" .
                "Дата завершення: {$task['due_date']}";

            $this->chat->message($message)
                ->keyboard(function(Keyboard $keyboard) use ($task) {
                    return $keyboard
                        ->button('✏️ Update')->action('update_task')->param('task_id', $task['id'])
                        ->button('🗑 Delete')->action('delete_task')->param('task_id', $task['id']);
                })
                ->send();

        } elseif ($response->status() === 404) {
            $this->reply("❌ Задача з ID {$taskId} не знайдена.");
        } else {
            $this->reply("❌ Помилка при отриманні задачі.");
        }
    }

    public function create()
    {
        $this->createTask();
    }

    private function createTask(): void
    {
        $telegramId = $this->message->from()->id();

        $creationKey = "task_creation_progress_{$telegramId}";

        if (cache()->has($creationKey)) {
            $this->reply("❗ Ви вже розпочали створення задачі. Будь ласка, завершіть поточний процес.");
            return;
        }

        cache()->put($creationKey, [
            'step' => 'title',
            'data' => [],
        ], now()->addMinutes(10));

        $this->reply("✏️ Введіть назву задачі:");
    }

    public function delete_task(): void
    {
        $taskId = $this->data->get('task_id');
        if (!is_numeric($taskId)) {
            $this->reply("❌ Невірний ID задачі для видалення.");
            return;
        }

        $response = Http::delete(env('APP_URL') . '/api/v1/tasks/' . $taskId);

        if ($response->successful()) {
            $this->reply("✅ Задача з ID {$taskId} успішно видалена.");
        } elseif ($response->status() === 404) {
            $this->reply("❌ Задача з ID {$taskId} не знайдена.");
        } else {
            $this->reply("❌ Помилка при видаленні задачі.");
        }
    }

    public function update_task(): void
    {
        $taskId = $this->data->get('task_id');

        if (!is_numeric($taskId)) {
            $this->reply("❌ Невірний ID задачі для оновлення.");
            return;
        }

        $updateKey = "task_update_progress_{$this->chat->chat_id}";

        cache()->put($updateKey, [
            'task_id' => $taskId,
            'step' => 'title',
            'updates' => [],
        ], now()->addMinutes(10));

        $this->chat
            ->message("✏️ Введіть нову назву для задачі #{$taskId}:")
            ->replyKeyboard(
                ReplyKeyboard::make()->buttons([
                    ReplyButton::make($this->LEAVE_AS_IT_WAS)
                ])->resize()
            )
            ->send();
    }

    protected function handleTaskUpdateStep(array $taskUpdate, string $text, string $updateKey): void
    {
        $step = $taskUpdate['step'];
        $taskId = $taskUpdate['task_id'];
        $updates = $taskUpdate['updates'];

        switch ($step) {
            case 'title':
                if ($text !== $this->LEAVE_AS_IT_WAS && empty($text)) {
                    $this->reply("❌ Назва не може бути пустою. Спробуйте ще раз.");
                    return;
                }
                if ($text !== $this->LEAVE_AS_IT_WAS) {
                    $updates['title'] = $text;
                }
                $taskUpdate['step'] = 'description';
                break;

            case 'description':
                if ($text !== $this->LEAVE_AS_IT_WAS && empty($text)) {
                    $this->reply("❌ Опис не може бути пустим. Спробуйте ще раз.");
                    return;
                }
                if ($text !== $this->LEAVE_AS_IT_WAS) {
                    $updates['description'] = $text;
                }
                $taskUpdate['step'] = 'status';
                break;

            case 'status':
                $statuses = [
                    'Очікує' => TaskStatus::PENDING->value,
                    'В процесі' => TaskStatus::INPROGRESS->value,
                    'Завершено' => TaskStatus::COMPLETED->value,
                ];
                $selected = $statuses[$text] ?? null;

                if ($text !== $this->LEAVE_AS_IT_WAS) {
                    if ($selected) {
                        $updates['status'] = $selected;
                    } else {
                        $this->reply("❌ Будь ласка, оберіть статус зі списку.");
                        return;
                    }
                }
                $taskUpdate['step'] = 'priority';
                break;

            case 'priority':
                $priorities = [
                    'Низький' => TaskPriority::LOW->value,
                    'Середній' => TaskPriority::MEDIUM->value,
                    'Високий' => TaskPriority::HIGH->value,
                ];
                $selectedPriority = $priorities[$text] ?? null;

                if ($text !== $this->LEAVE_AS_IT_WAS) {
                    if ($selectedPriority) {
                        $updates['priority'] = $selectedPriority;
                    } else {
                        $this->reply("❌ Будь ласка, оберіть пріоритет зі списку.");
                        return;
                    }
                }
                $taskUpdate['step'] = 'due_date';
                break;

            case 'due_date':
                if ($text !== '' && $text !== $this->LEAVE_AS_IT_WAS) {
                    $date = \DateTime::createFromFormat('Y-m-d', $text);
                    $isValidDate = $date && $date->format('Y-m-d') === $text;

                    if (!$isValidDate) {
                        $this->reply("❌ Будь ласка, введіть дату у форматі РРРР-ММ-ДД або оберіть $this->LEAVE_AS_IT_WAS.");
                        return;
                    }
                    $updates['due_date'] = $text;
                }

                cache()->forget($updateKey);

                $response = Http::put(env('APP_URL') . "/api/v1/tasks/{$taskId}", $updates);

                if ($response->successful()) {
                    $this->chat
                        ->message("✅ Задача #{$taskId} оновлена успішно.")
                        ->removeReplyKeyboard()
                        ->send();
                } else {
                    $this->reply("❌ Не вдалося оновити задачу.");
                }
                return;

            default:
                $this->reply("⚠️ Невідомий етап оновлення.");
                cache()->forget($updateKey);
                return;
        }

        $taskUpdate['updates'] = $updates;
        cache()->put($updateKey, $taskUpdate, now()->addMinutes(10));

        switch ($taskUpdate['step']) {
            case 'description':
                $this->chat
                    ->message("✏️ Введіть новий опис для задачі #{$taskId}:")
                    ->replyKeyboard(
                        ReplyKeyboard::make()->buttons([
                            ReplyButton::make($this->LEAVE_AS_IT_WAS)
                        ])->resize()
                    )
                    ->send();
                break;

            case 'status':
                $this->chat
                    ->message("📌 Оберіть новий статус задачі #{$taskId}:")
                    ->replyKeyboard(
                        ReplyKeyboard::make()->buttons([
                            ReplyButton::make('Очікує'),
                            ReplyButton::make('В процесі'),
                            ReplyButton::make('Завершено'),
                        ])->resize()
                    )
                    ->send();
                break;

            case 'priority':
                $this->chat
                    ->message("📌 Оберіть пріоритет задачі #{$taskId}:")
                    ->replyKeyboard(
                        ReplyKeyboard::make()->buttons([
                            ReplyButton::make('Низький'),
                            ReplyButton::make('Середній'),
                            ReplyButton::make('Високий'),
                        ])->resize()
                    )
                    ->send();
                break;

            case 'due_date':
                $this->chat
                    ->message("📅 Введіть нову дату завершення для задачі #{$taskId} (формат РРРР-ММ-ДД):")
                    ->replyKeyboard(
                        ReplyKeyboard::make()->buttons([
                            ReplyButton::make($this->LEAVE_AS_IT_WAS)
                        ])->resize()
                    )
                    ->send();
                break;
        }
    }

    protected function handleTaskCreationStep(array $creationProgress, string $text, string $creationKey): void
    {
        $step = $creationProgress['step'];
        $taskData = $creationProgress['data'] ?? [];

        switch ($step) {
            case 'title':
                if (empty(trim($text))) {
                    $this->reply("❌ Назва не може бути порожньою. Спробуйте ще раз.");
                    return;
                }
                $taskData['title'] = $text;
                $creationProgress['step'] = 'description';
                break;

            case 'description':
                if (empty(trim($text))) {
                    $this->reply("❌ Опис не може бути порожнім. Спробуйте ще раз.");
                    return;
                }
                $taskData['description'] = $text;
                $creationProgress['step'] = 'status';
                break;

            case 'status':
                $statuses = [
                    'Очікує' => TaskStatus::PENDING->value,
                    'В процесі' => TaskStatus::INPROGRESS->value,
                    'Завершено' => TaskStatus::COMPLETED->value,
                ];
                $selected = $statuses[$text] ?? null;
                if (!$selected) {
                    $this->reply("❌ Будь ласка, оберіть статус зі списку.");
                    return;
                }
                $taskData['status'] = $selected;
                $creationProgress['step'] = 'priority';
                break;

            case 'priority':
                $priorities = [
                    'Низький' => TaskPriority::LOW->value,
                    'Середній' => TaskPriority::MEDIUM->value,
                    'Високий' => TaskPriority::HIGH->value,
                ];
                $selectedPriority = $priorities[$text] ?? null;
                if (!$selectedPriority) {
                    $this->reply("❌ Будь ласка, оберіть пріоритет зі списку.");
                    return;
                }
                $taskData['priority'] = $selectedPriority;
                $creationProgress['step'] = 'due_date';
                break;

            case 'due_date':
                $date = \DateTime::createFromFormat('Y-m-d', $text);
                $isValidDate = $date && $date->format('Y-m-d') === $text;
                if (!$isValidDate) {
                    $this->reply("❌ Будь ласка, введіть дату у форматі РРРР-ММ-ДД.");
                    return;
                }
                $taskData['due_date'] = $text;

                cache()->forget($creationKey);

                $response = Http::post(env('APP_URL') . '/api/v1/tasks', $taskData);

                if ($response->successful()) {
                    $this->chat
                        ->message("✅ Задача створена успішно.")
                        ->removeReplyKeyboard()
                        ->send();
                } else {
                    $this->reply("❌ Не вдалося створити задачу.");
                }

                return;

            default:
                $this->reply("⚠️ Невідомий етап створення задачі.");
                cache()->forget($creationKey);
                return;
        }

        $creationProgress['data'] = $taskData;
        cache()->put($creationKey, $creationProgress, now()->addMinutes(10));

        switch ($creationProgress['step']) {
            case 'description':
                $this->chat
                    ->message("✏️ Введіть опис задачі:")
                    ->removeReplyKeyboard()
                    ->send();
                break;

            case 'status':
                $this->chat
                    ->message("📌 Оберіть статус задачі:")
                    ->replyKeyboard(
                        ReplyKeyboard::make()->buttons([
                            ReplyButton::make('Очікує'),
                            ReplyButton::make('В процесі'),
                            ReplyButton::make('Завершено'),
                        ])->resize()
                    )
                    ->send();
                break;

            case 'priority':
                $this->chat
                    ->message("📌 Оберіть пріоритет задачі:")
                    ->replyKeyboard(
                        ReplyKeyboard::make()->buttons([
                            ReplyButton::make('Низький'),
                            ReplyButton::make('Середній'),
                            ReplyButton::make('Високий'),
                        ])->resize()
                    )
                    ->send();
                break;

            case 'due_date':
                $this->chat
                    ->message("📅 Введіть дату завершення задачі (формат РРРР-ММ-ДД):")
                    ->removeReplyKeyboard()
                    ->send();
                break;
        }
    }
}
