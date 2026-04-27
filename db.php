<?php
// Database helper for the DTR system.
// Update the credentials below if your local XAMPP MySQL configuration differs.

if (!defined('DB_HOST')) {
    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', 'dtr_system');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

function db_connect() {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    return new PDO($dsn, DB_USER, DB_PASS, $options);
}

function saveDtrUpload(PDO $pdo, string $filename, int $marchSeconds, int $aprilSeconds, int $grandSeconds, string $marchText, string $aprilText, string $grandText, int $processedRows, ?string $notes = null): int {
    $stmt = $pdo->prepare(
        'INSERT INTO dtr_uploads (filename, march_total_seconds, april_total_seconds, grand_total_seconds, march_total_text, april_total_text, grand_total_text, processed_rows, notes)
         VALUES (:filename, :march_total_seconds, :april_total_seconds, :grand_total_seconds, :march_total_text, :april_total_text, :grand_total_text, :processed_rows, :notes)'
    );

    $stmt->execute([
        ':filename' => $filename,
        ':march_total_seconds' => $marchSeconds,
        ':april_total_seconds' => $aprilSeconds,
        ':grand_total_seconds' => $grandSeconds,
        ':march_total_text' => $marchText,
        ':april_total_text' => $aprilText,
        ':grand_total_text' => $grandText,
        ':processed_rows' => $processedRows,
        ':notes' => $notes,
    ]);

    return (int)$pdo->lastInsertId();
}

function saveDtrDailyRecords(PDO $pdo, int $uploadId, array $records): void {
    $stmt = $pdo->prepare(
        'INSERT INTO dtr_daily_records (upload_id, record_month, row_number, raw_day_label, am_in, am_out, pm_in, pm_out, daily_seconds, daily_text)
         VALUES (:upload_id, :record_month, :row_number, :raw_day_label, :am_in, :am_out, :pm_in, :pm_out, :daily_seconds, :daily_text)'
    );

    foreach ($records as $record) {
        $stmt->execute([
            ':upload_id' => $uploadId,
            ':record_month' => $record['record_month'],
            ':row_number' => $record['row_number'],
            ':raw_day_label' => $record['raw_day_label'],
            ':am_in' => $record['am_in'],
            ':am_out' => $record['am_out'],
            ':pm_in' => $record['pm_in'],
            ':pm_out' => $record['pm_out'],
            ':daily_seconds' => $record['daily_seconds'],
            ':daily_text' => $record['daily_text'],
        ]);
    }
}
