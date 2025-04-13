<?php
require __DIR__ . '/vendor/autoload.php';

use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Exception\TelegramException;

// ================= Configuration =================
define('BOT_TOKEN', getenv('BOT_TOKEN'));
define('USERS_FILE', __DIR__ . '/users.json');
define('ERROR_LOG', __DIR__ . '/error.log');

// ================= Error Handling =================
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message\n", 3, ERROR_LOG);
}

// ================= Data Management =================
function loadUsers() {
    if (!file_exists(USERS_FILE)) {
        file_put_contents(USERS_FILE, json_encode([]));
    }
    $data = json_decode(file_get_contents(USERS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveUsers($users) {
    try {
        file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return true;
    } catch (Exception $e) {
        logError("Save users failed: " . $e->getMessage());
        return false;
    }
}

// ================= Telegram Functions =================
function sendMessage($chat_id, $text, $keyboard = null) {
    try {
        $data = [
            'chat_id' => $chat_id,
            'text'    => $text,
            'parse_mode' => 'HTML'
        ];

        if ($keyboard) {
            $data['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);
        }

        return Request::sendMessage($data);
    } catch (TelegramException $e) {
        logError("Send message failed: " . $e->getMessage());
        return false;
    }
}

// ================= Keyboard Layout =================
function getMainKeyboard() {
    return [
        [['text' => '💰 Earn', 'callback_data' => 'earn'], ['text' => '💳 Balance', 'callback_data' => 'balance']],
        [['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard'], ['text' => '👥 Referrals', 'callback_data' => 'referrals']],
        [['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'], ['text' => '❓ Help', 'callback_data' => 'help']]
    ];
}

// ================= Update Processing =================
function processUpdate($update) {
    $users = loadUsers();

    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $text = trim($message['text'] ?? '');

        // Initialize new user
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . microtime()), 0, 8),
                'referred_by' => null
            ];
        }

        // Handle /start command
        if (strpos($text, '/start') === 0) {
            $args = explode(' ', $text);
            if (count($args) > 1 && !$users[$chat_id]['referred_by']) {
                $ref_code = $args[1];
                foreach ($users as $id => $user) {
                    if ($user['ref_code'] === $ref_code && $id != $chat_id) {
                        $users[$chat_id]['referred_by'] = $id;
                        $users[$id]['referrals']++;
                        $users[$id]['balance'] += 50;
                        sendMessage($id, "🎉 New referral! +50 points bonus!");
                        break;
                    }
                }
            }

            $welcome = "Welcome to Earning Bot!\nEarn points, invite friends, and withdraw your earnings!\n"
                      . "Your referral code: <b>{$users[$chat_id]['ref_code']}</b>";
            sendMessage($chat_id, $welcome, getMainKeyboard());
        }
    } elseif (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $chat_id = $query['message']['chat']['id'];
        $data = $query['data'];

        if (!isset($users[$chat_id])) {
            sendMessage($chat_id, "❌ User not found. Please send /start again.");
            return;
        }

        switch ($data) {
            case 'earn':
                $time_diff = time() - $users[$chat_id]['last_earn'];
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $response = "⏳ Please wait $remaining seconds before earning again!";
                } else {
                    $users[$chat_id]['balance'] += 10;
                    $users[$chat_id]['last_earn'] = time();
                    $response = "✅ You earned 10 points!\nNew balance: {$users[$chat_id]['balance']}";
                }
                break;

            case 'balance':
                $response = "💳 Your Balance\nPoints: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
                break;

            case 'leaderboard':
                $sorted = array_column($users, 'balance');
                arsort($sorted);
                $response = "🏆 Top Earners\n";
                $position = 1;
                foreach (array_slice($sorted, 0, 5, true) as $id => $balance) {
                    $response .= "$position. User $id: $balance points\n";
                    $position++;
                }
                break;

            case 'referrals':
                $response = "👥 Referral System\n"
                           . "Your code: <b>{$users[$chat_id]['ref_code']}</b>\n"
                           . "Referrals: {$users[$chat_id]['referrals']}\n"
                           . "Invite link: t.me/" . BOT_TOKEN . "?start={$users[$chat_id]['ref_code']}\n"
                           . "50 points per referral!";
                break;

            case 'withdraw':
                $min = 100;
                if ($users[$chat_id]['balance'] < $min) {
                    $response = "🏧 Withdrawal\nMinimum: $min points\nYour balance: {$users[$chat_id]['balance']}\nNeed " . ($min - $users[$chat_id]['balance']) . " more points!";
                } else {
                    $amount = $users[$chat_id]['balance'];
                    $users[$chat_id]['balance'] = 0;
                    $response = "🏧 Withdrawal of $amount points requested!\nOur team will process it soon.";
                }
                break;

            case 'help':
                $response = "❓ Help\n"
                           . "💰 Earn: Get 10 points/min\n"
                           . "👥 Refer: 50 points/ref\n"
                           . "🏧 Withdraw: Min 100 points\n"
                           . "Use buttons below to navigate!";
                break;

            default:
                $response = "❌ Unknown command";
        }

        sendMessage($chat_id, $response, getMainKeyboard());
    }

    saveUsers($users);
}

// ================= Webhook Handler =================
try {
    $telegram = new Telegram(BOT_TOKEN);
    
    // Set webhook on first run
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $telegram->setWebhook(getenv('RENDER_EXTERNAL_URL') . '/index.php');
        echo "Webhook set successfully!";
        exit;
    }

    // Process incoming update
    $telegram->handle();
    $update = $telegram->getWebhookUpdate();
    processUpdate($update->getRawData());

} catch (TelegramException $e) {
    logError("Main error: " . $e->getMessage());
    http_response_code(500);
    echo "Error processing update";
}
