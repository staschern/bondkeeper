<?php

declare(strict_types=1);

/**
 * Офлайн-проверка образцов уведомлений о выплатах
 * (PaymentNotificationSamples + NotificationDispatcher::renderText()):
 * на каждый вид уведомления клиенту есть образец, и каждый образец
 * превращается в настоящий текст, а не в заглушку.
 *
 * Запуск (из корня репозитория):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_payment_notification_samples.php
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_payment_notification_samples.php --print   # показать все тексты
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Payments\PaymentNotificationSamples;
use BondKeeper\Telegram\NotificationDispatcher;
use BondKeeper\Telegram\TelegramClientInterface;

final class SilentTelegramClient implements TelegramClientInterface
{
    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null, bool $disableWebPagePreview = false): bool
    {
        return true;
    }

    public function sendMessageReturningId(int $chatId, string $text, ?array $replyMarkup = null): ?int
    {
        return 1;
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool
    {
        return true;
    }

    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): bool
    {
        return true;
    }

    public function lastSendWasBlockedByUser(): bool
    {
        return false;
    }
}

$failures = 0;
$checks = 0;

function check(string $label, $expected, $actual): void
{
    global $failures, $checks;
    $checks++;
    if ($expected === $actual) {
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n       ожидали: " . var_export($expected, true) . "\n       получили: " . var_export($actual, true) . "\n";
    }
}

$dispatcher = new NotificationDispatcher(new PDO('sqlite::memory:'), new SilentTelegramClient());
$samples = PaymentNotificationSamples::client();
$texts = [];
foreach ($samples as $sample) {
    $texts[] = ['code' => $sample['code'], 'title' => $sample['title'], 'text' => $dispatcher->renderText($sample['code'], $sample['payload'], PaymentNotificationSamples::ISSUER, PaymentNotificationSamples::ISSUER_INN)];
}

if (in_array('--print', $argv, true)) {
    foreach ($texts as $i => $t) {
        echo '--- ' . ($i + 1) . ". {$t['code']} · {$t['title']} ---\n{$t['text']}\n\n";
    }
    foreach (PaymentNotificationSamples::admin() as $sample) {
        echo "--- только администратору · {$sample['title']} ---\n{$sample['text']}\n\n";
    }
    exit(0);
}

$find = static function (string $title) use ($texts): string {
    foreach ($texts as $t) {
        if (str_contains($t['title'], $title)) {
            return $t['text'];
        }
    }

    return '';
};

echo "--- полнота ---\n";
$codes = array_values(array_unique(array_column($samples, 'code')));
sort($codes);
check('образец есть на каждый вид уведомления о выплате и оферте, который уходит клиенту', ['A2', 'A4', 'A6', 'B1', 'B1a', 'B2', 'B2a', 'B4', 'B5', 'C2', 'R1', 'R2'], $codes);
check('ни один образец не превращается в заглушку «Новое событие»', [], array_values(array_filter(array_column($texts, 'text'), static fn (string $t): bool => str_contains($t, 'Новое событие'))));
check('у каждого образца заголовок темы («Выплаты», у напоминаний об оферте — «Оферты») и строка: эмитент, его ИНН, бумага, ISIN', count($texts), count(array_filter(
    $texts,
    static fn (array $t): bool => preg_match('/^<b>\S+ ' . ($t['code'] === 'R2' ? 'Оферты' : 'Выплаты') . ':<\/b>\nООО «Образец» \| ИНН 7700000000 · Образец БО-01 \(RU000A0SAMPL1\)\n/u', $t['text']) === 1,
)));
check('ИНН в базе не заполнен — строка остаётся без него, а не «ИНН » с пустотой', true, str_contains(
    $dispatcher->renderText('A2', $samples[6]['payload'], PaymentNotificationSamples::ISSUER, ''),
    "ООО «Образец» · Образец БО-01 (RU000A0SAMPL1)\n",
));

echo "\n--- варианты текста ---\n";
check('напоминание: купон', true, str_contains($find('напоминание накануне: купон'), 'Завтра, 15.10.26, выплата: купон — 12.33 ₽ на бумагу.'));
check('напоминание в пятницу о выплате в субботу: «завтра», без оговорки про выходной', [true, false], [
    str_contains($find('о выплате в субботу'), 'Завтра, 17.10.26, выплата: купон — 12.33 ₽ на бумагу; амортизация — 250.00 ₽ на бумагу.'),
    str_contains($find('о выплате в субботу'), 'выходной'),
]);
check('напоминание в пятницу о выплате в понедельник: просто дата, без «завтра»', [true, false], [
    str_contains($find('о выплате в понедельник'), "19.10.26 выплата: купон — 12.33 ₽ на бумагу."),
    str_contains($find('о выплате в понедельник'), 'Завтра'),
]);
check('напоминание по бумаге в техдефолте: отдельной строкой — статус и что выплата под вопросом; у обычной бумаги такой строки нет', [true, false], [
    str_ends_with($find('в техническом дефолте'), "Завтра, 15.10.26, выплата: купон — 14.79 ₽ на бумагу.\n⚠️ Бумага в техническом дефолте — выплата под вопросом."),
    str_contains($find('напоминание накануне: купон'), 'под вопросом'),
]);
check('напоминание со своим интервалом: дата без «завтра»', [true, false], [
    str_contains($find('со своим интервалом'), '15.10.26 выплата: купон — 12.33 ₽ на бумагу.'),
    str_contains($find('со своим интервалом'), 'Завтра'),
]);
check('оферта, пут: вид, сколько осталось, период предъявления и цена', true, str_ends_with($find('известны период предъявления и цена'),
    '13.11.26 — оферта (пут), осталось 30 дн. Владельцы могут предъявить облигации к выкупу эмитентом. Период предъявления: 27.10.26 – 02.11.26. Цена выкупа: 100 % номинала.'));
check('оферта, пут без периода: только то, что известно', true, str_ends_with($find('биржа дала только дату и цену'),
    '13.11.26 — оферта (пут), осталось 30 дн. Владельцы могут предъявить облигации к выкупу эмитентом. Цена выкупа: 100 % номинала.'));
check('оферта, колл по данным биржи: право эмитента, а не свершившийся факт', true, str_ends_with($find('колл-опцион по данным биржи'),
    '13.11.26 — колл-опцион, осталось 30 дн. Эмитент вправе досрочно погасить выпуск в эту дату по цене 100 % номинала.'));
check('оферта, колл, объявленный НРД: решение принято, до даты меньше 30 дней', true, str_ends_with($find('объявленное НРД'),
    '30.10.26 — досрочное погашение по решению эмитента (колл), осталось 11 дн. Эмитент объявил о погашении выпуска: 100 % номинала.'));
check('оферта по бумаге в техдефолте: отдельной строкой — что исполнение под вопросом', true, str_ends_with($find('оферте по бумаге в техническом дефолте'),
    "\n⚠️ Бумага в техническом дефолте — исполнение оферты под вопросом."));
check('напоминание: валютный выпуск — код валюты, не знак рубля', true, str_contains($find('валютный выпуск'), 'купон — 6.37 USD на бумагу.'));
check('напоминание: сумма ещё не объявлена', true, str_contains($find('ещё не объявлена'), 'купон — сумма пока не определена.'));
check('получено: купон, амортизация, погашение — с правильным окончанием', [true, true, true], [
    str_contains($find('купон получен НРД'), 'Купон за 15.10.26 получен НРД: 12.33 ₽ на бумагу.'),
    str_contains($find('амортизация получена'), 'Амортизация 15.10.26 получена НРД: 250.00 ₽ на бумагу.'),
    str_contains($find('погашение получено'), 'Погашение 15.10.26 получено НРД: 1 000.00 ₽ на бумагу.'),
]);
check('выпуск погашен досрочно: названо прямо, со сроком по графику', true,
    str_ends_with($find('погашен досрочно по решению эмитента'), "Досрочное погашение 15.10.26 получено НРД: 1 000.00 ₽ на бумагу. Выпуск погашен досрочно: срок по графику был 20.09.30."));
check('вторая выплата по купону', true, str_contains($find('вторая выплата'), 'Купон за 15.10.26: получена ещё одна выплата — 65.21 ₽ на бумагу.'));
check('часть до срока — жёлтое, с отметкой НРД', true, str_starts_with($find('раньше срока'), '<b>🟡 Выплаты:</b>') && str_contains($find('раньше срока'), 'Отметка НРД: «исполнена в неполном объеме до наступления срока».'));
check('частично — красное, со сроком полного дефолта', true, str_starts_with($find('выплачено частично'), '<b>🔴 Выплаты:</b>') && str_contains($find('выплачено частично'), 'до 29.10.26 (осталось рабочих дней: 9).'));
check('доплата', true, str_contains($find('доплата'), 'Погашение 15.10.26: доплата 300.00 ₽, всего получено 569.10 ₽ из 1 000.00 ₽ на бумагу.'));
check('после просрочки — срок и день поступления', true, str_contains($find('после просрочки'), 'Срок был 15.10.26, деньги поступили 16.10.26.'));
check('не выплачено в срок', true, str_contains($find('объявление НРД на следующий'), 'Купон за 15.10.26 не выплачен в срок. Полный дефолт наступит, если долг не будет закрыт до 29.10.26 (осталось рабочих дней: 9).'));
check('не выплачено — срок до дефолта уже истёк', true, str_contains($find('узнали поздно'), 'Срок, после которого наступает полный дефолт, истёк 29.10.26.'));
check('дефолт', true, str_contains($find('объявил дефолт'), 'НРД объявил дефолт — выплата не исполнена в течение 10 рабочих дней после срока (15.10.26).'));
check('жёлтая проверка', true, str_contains($find('не сообщил о деньгах'), 'По выплате (купон, амортизация) за 15.10.26 НРД пока не сообщил о поступлении денег от эмитента'));

echo "\n--- служебные сообщения администратору ---\n";
$admin = PaymentNotificationSamples::admin();
check('четыре образца, все с текстом', [4, 4], [count($admin), count(array_filter(array_column($admin, 'text')))]);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
