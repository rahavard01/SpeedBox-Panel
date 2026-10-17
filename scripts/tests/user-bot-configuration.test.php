<?php

require __DIR__ . '/../../app/Services/UserBotConfigurationPolicy.php';

use App\Services\UserBotConfigurationPolicy;

$policy = new UserBotConfigurationPolicy();
$checks = 0;
$check = function (bool $condition, string $description) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($description);
    }
    $checks++;
};
$draft = [
    'is_enabled' => false,
    'card_payment_enabled' => true,
    'crypto_payment_enabled' => true,
    'card_ai_auto_approve_enabled' => true,
    'force_channel_join_enabled' => true,
    'plans' => [['is_enabled' => true, 'price' => 0]],
];
$check($policy->errors($draft) === [], 'Incomplete disabled bots must remain saveable.');
$errors = $policy->errors(array_merge($draft, ['is_enabled' => true]));
foreach (['bot_token', 'card_number', 'crypto_options', 'help_ios', 'help_android', 'help_windows', 'force_channel_join_chat', 'plans'] as $field) {
    $check(isset($errors[$field]), 'Activation must require ' . $field);
}
$ready = [
    'is_enabled' => true,
    'bot_token' => 'test-token',
    'card_payment_enabled' => true,
    'card_number' => '1234-5678-9012-3456',
    'help_ios' => 'Guide', 'help_android' => 'Guide', 'help_windows' => 'Guide',
    'plans' => [['is_enabled' => true, 'price' => 100]],
];
$check($policy->errors($ready) === [], 'Complete card configuration must activate.');
$errors = $policy->errors(array_merge($ready, ['card_payment_enabled' => false]));
$check(isset($errors['card_payment_enabled']), 'An active bot must have a payment method.');
$crypto = array_merge($ready, [
    'card_payment_enabled' => false, 'crypto_payment_enabled' => true,
    'crypto_options' => [['enabled' => true, 'wallet_address' => 'wallet']],
]);
$check($policy->errors($crypto) === [], 'Crypto-only configurations must not require a card.');
foreach (['card_sms_auto_approve_enabled', 'card_ai_auto_approve_enabled'] as $field) {
    $errors = $policy->errors(array_merge($crypto, [$field => true]));
    $check(isset($errors['card_payment_enabled']), 'Receipt approval must depend on card payment.');
}
$errors = $policy->errors(array_merge($crypto, ['crypto_options' => [['enabled' => false, 'wallet_address' => 'wallet']]]));
$check(isset($errors['crypto_options']), 'A disabled wallet must not satisfy crypto readiness.');
$errors = $policy->errors(array_merge($crypto, ['crypto_options' => [['enabled' => true, 'wallet_address' => ' ']]]));
$check(isset($errors['crypto_options']), 'A blank wallet must not satisfy crypto readiness.');
$check($policy->errors(array_merge($ready, ['plans' => [['is_enabled' => false, 'price' => 0]]])) === [], 'Inactive plans may remain incomplete.');
$errors = $policy->errors(array_merge($ready, ['help_ios' => ' ']));
$check(isset($errors['help_ios']), 'Whitespace must not satisfy required guides.');
$errors = $policy->errors($draft, true);
foreach (['card_number', 'crypto_options', 'help_ios', 'help_android', 'help_windows', 'plans'] as $field) {
    $check(isset($errors[$field]), 'Representative saves require complete ' . $field . ' even while disabled.');
}
$check($policy->errors(array_merge($ready, ['is_enabled' => false]), true) === [], 'Complete disabled representative configuration is saveable.');
echo "User bot draft and activation policy: {$checks} checks passed.\n";
