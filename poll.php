<?php
/**
 * ============================================================================
 * Vidmoly Telegram Bot - Long Polling Runner
 * ============================================================================
 * Allows running the bot locally or on any server without webhooks or public URLs!
 * Bypasses all web server firewalls, Cloudflare, and hosting anti-bot challenges.
 *
 * Usage:
 * php poll.php
 * ============================================================================
 */

require_once __DIR__ . '/bot.php';

// Remove webhook to enable getUpdates polling
telegramRequest('deleteWebhook', ['drop_pending_updates' => false]);

echo "=======================================================\n";
echo " Vidmoly Telegram Bot - Long Polling Started\n";
echo " Bot: @media_dlnBOT\n";
echo " Status: Listening for updates from Telegram...\n";
echo " Press Ctrl+C to stop.\n";
echo "=======================================================\n";

$offset = 0;

while (true) {
    $res = telegramRequest('getUpdates', [
        'offset'  => $offset,
        'timeout' => 30,
        'limit'   => 50
    ]);

    if ($res && !empty($res['ok']) && !empty($res['result'])) {
        foreach ($res['result'] as $update) {
            $offset = $update['update_id'] + 1;

            if (isset($update['message'])) {
                $user = $update['message']['from']['first_name'] ?? 'User';
                $text = $update['message']['text'] ?? '[Media/Other]';
                echo "[" . date('H:i:s') . "] Message from {$user}: {$text}\n";
                handleMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $user = $update['callback_query']['from']['first_name'] ?? 'User';
                $data = $update['callback_query']['data'] ?? '';
                echo "[" . date('H:i:s') . "] Callback from {$user}: {$data}\n";
                handleCallbackQuery($update['callback_query']);
            }
        }
    }

    // Short sleep between long-polling cycles
    usleep(250000);
}
