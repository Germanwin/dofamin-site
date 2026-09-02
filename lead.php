<?php
/**
 * Приёмник заявок с форм сайта «Дофамин».
 *
 * Что делает: принимает заявку, СРАЗУ пишет её в файл (чтобы не потерять,
 * даже если почта и Telegram отвалятся), потом шлёт в Telegram и на почту.
 *
 * ══════════════════════════════════════════════════════════════════
 *  НАСТРОЙКА — все секреты в файле config.php рядом. Здесь трогать нечего.
 * ══════════════════════════════════════════════════════════════════
 */

// Токен бота и адрес почты лежат в отдельном файле config.php рядом с этим.
// Так сделано потому, что исходники сайта открыты на GitHub: попади токен
// в репозиторий — его за пару часов найдут роботы и завалят группу спамом.
// config.php в репозиторий не попадает, он живёт только на сервере.
//
// Файла нет — сайт всё равно работает: заявка сохранится в папку leads,
// просто не уйдёт в Telegram.
$cfg = @include __DIR__ . '/config.php';
if (!is_array($cfg)) $cfg = [];

define('TG_TOKEN', (string)($cfg['tg_token'] ?? ''));
define('TG_CHAT',  (string)($cfg['tg_chat']  ?? ''));
define('MAIL_TO',  (string)($cfg['mail_to']  ?? ''));

// ── дальше настраивать не нужно ───────────────────────────────────

const LEAD_DIR   = __DIR__ . '/leads';   // куда складывать заявки
const RATE_LIMIT = 8;                    // заявок с одного IP в час

header('Content-Type: application/json; charset=utf-8');

function reply($ok, $msg = '') {
    echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    reply(false, 'only POST');
}

$raw  = file_get_contents('php://input', false, null, 0, 8192);
$data = json_decode($raw, true);
if (!is_array($data)) reply(false, 'bad payload');

// Телефон обязателен: без него заявка бесполезна и почти наверняка это бот.
//
// Принимаем и кириллический ключ «Телефон»: квиз слал именно его, и заявки
// молча терялись — человек видел «спасибо», а телефон никуда не уходил.
// Формы починены, но подстраховка остаётся: цена ошибки тут — потерянный клиент.
$raw_phone = $data['phone'] ?? $data['Телефон'] ?? '';
$phone  = preg_replace('/\D/', '', (string)$raw_phone);
if (strlen($phone) < 10) reply(false, 'bad phone');

// Папка для заявок закрыта от посторонних: в ней лежат телефоны клиентов,
// и открытый доступ к ним — прямое нарушение 152-ФЗ.
if (!is_dir(LEAD_DIR)) {
    @mkdir(LEAD_DIR, 0700, true);
    @file_put_contents(LEAD_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
    @file_put_contents(LEAD_DIR . '/index.html', '');
}

// Защита от потока спама с одного адреса.
$ip    = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$stamp = LEAD_DIR . '/.rate-' . md5($ip);
$hits  = array_values(array_filter(
    @file($stamp, FILE_IGNORE_NEW_LINES) ?: [],
    fn($t) => (int)$t > time() - 3600
));
if (count($hits) >= RATE_LIMIT) { http_response_code(429); reply(false, 'too many'); }
$hits[] = time();
@file_put_contents($stamp, implode("\n", $hits));

// ── собираем читаемый текст заявки ────────────────────────────────
$FORMS = [
    'quiz'       => 'Квиз (подбор программы)',
    'form-price' => 'Узнать цену',
    'form-book'  => 'Запись на первый визит',
    'form-final' => 'Чек-лист (лид-магнит)',
    // Мобильная версия шлёт собственные id форм.
    'f1'         => 'Запись на первый визит (моб.)',
    'f2'         => 'Форма в контактах (моб.)',
    'f3'         => 'Узнать цену (моб.)',
    'f4'         => 'Чек-лист, лид-магнит (моб.)',
];
$form = $FORMS[$data['form'] ?? ''] ?? 'Форма на сайте';
$when = date('d.m.Y H:i');

$lines = ["Заявка с сайта: $form", "Телефон: " . $raw_phone, "Время: $when"];
foreach ($data as $k => $v) {
    if (in_array($k, ['form', 'phone', 'Телефон'], true)) continue;
    if (is_scalar($v) && $v !== '') $lines[] = "$k: $v";
}
$text = implode("\n", $lines);

// ── 1. Сохраняем на диск. Это происходит ДО отправки: даже если Telegram
//       недоступен, а почта в спаме — заявка останется в файле.
$csv = LEAD_DIR . '/заявки-' . date('Y-m') . '.csv';
$new = !file_exists($csv);
if ($fh = @fopen($csv, 'a')) {
    if ($new) { fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, ['Дата', 'Форма', 'Телефон', 'Подробности'], ';'); }
    $extra = [];
    foreach ($data as $k => $v) {
        if (in_array($k, ['form', 'phone', 'Телефон'], true)) continue;
        if (is_scalar($v) && $v !== '') $extra[] = "$k: $v";
    }
    fputcsv($fh, [$when, $form, $raw_phone, implode(' | ', $extra)], ';');
    fclose($fh);
}

// ── 2. Telegram
if (TG_TOKEN !== '' && TG_CHAT !== '') {
    $url = 'https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage';
    $post = http_build_query(['chat_id' => TG_CHAT, 'text' => $text, 'disable_web_page_preview' => 1]);
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($c);
        curl_close($c);
    } else {
        @file_get_contents($url, false, stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $post,
            'timeout' => 5,
        ]]));
    }
}

// ── 3. Почта
if (MAIL_TO !== '') {
    $host = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    @mail(
        MAIL_TO,
        '=?UTF-8?B?' . base64_encode("Заявка с сайта: $form") . '?=',
        $text,
        implode("\r\n", [
            'From: Сайт Дофамин <noreply@' . $host . '>',
            'Content-Type: text/plain; charset=UTF-8',
        ])
    );
}

reply(true);
