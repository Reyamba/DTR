<?php
/**
 * DTR Total Hours Calculator
 * Handles the LAPIRA-DTR two-month CSV format and saves batch uploads.
 */

$results = null;
$error = null;
$info = null;

if (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['dtr_file'])) {
    $file = $_FILES['dtr_file'];
    $allowedMimes = [
        'text/csv',
        'text/plain',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-office'
    ];

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Upload failed with error code: " . $file['error'];
    } elseif (!in_array($file['type'], $allowedMimes) && !in_array($extension, ['csv', 'xlsx', 'xls'])) {
        $error = "Invalid file type. Please upload a CSV or Excel file (.xlsx).";
    } else {
        $rows = [];

        if ($extension === 'csv') {
            if (($handle = fopen($file['tmp_name'], 'r')) !== FALSE) {
                while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                    $rows[] = $data;
                }
                fclose($handle);
            }
        } elseif ($extension === 'xlsx') {
            $rows = parseXlsxFile($file['tmp_name']);
            if ($rows === false) {
                $error = 'Unable to parse the uploaded .xlsx file. Please verify the file and try again.';
            }
        } else {
            $error = 'Legacy .xls files are not supported. Please save the Excel file as .xlsx and try again.';
        }

        if (empty($error) && !empty($rows)) {
            $rowCount = 0;
            $marchSeconds = 0;
            $aprilSeconds = 0;
            $processedRows = 0;
            $dailyRecords = [];

            foreach ($rows as $data) {
                $rowCount++;
                if ($rowCount <= 6) {
                    continue;
                }

                $processedRows++;
                $label = trim($data[0] ?? '');

                if (isset($data[4])) {
                    $marchSeconds += calculateDailySeconds($data[1] ?? '', $data[2] ?? '', $data[3] ?? '', $data[4] ?? '');
                    $dailyRecords[] = [
                        'record_month' => 'March',
                        'row_number' => $rowCount,
                        'raw_day_label' => $label,
                        'am_in' => trim($data[1] ?? ''),
                        'am_out' => trim($data[2] ?? ''),
                        'pm_in' => trim($data[3] ?? ''),
                        'pm_out' => trim($data[4] ?? ''),
                        'daily_seconds' => calculateDailySeconds($data[1] ?? '', $data[2] ?? '', $data[3] ?? '', $data[4] ?? ''),
                        'daily_text' => formatSeconds(calculateDailySeconds($data[1] ?? '', $data[2] ?? '', $data[3] ?? '', $data[4] ?? '')),
                    ];
                }

                if (isset($data[12])) {
                    $aprilSeconds += calculateDailySeconds($data[9] ?? '', $data[10] ?? '', $data[11] ?? '', $data[12] ?? '');
                    $dailyRecords[] = [
                        'record_month' => 'April',
                        'row_number' => $rowCount,
                        'raw_day_label' => $label,
                        'am_in' => trim($data[9] ?? ''),
                        'am_out' => trim($data[10] ?? ''),
                        'pm_in' => trim($data[11] ?? ''),
                        'pm_out' => trim($data[12] ?? ''),
                        'daily_seconds' => calculateDailySeconds($data[9] ?? '', $data[10] ?? '', $data[11] ?? '', $data[12] ?? ''),
                        'daily_text' => formatSeconds(calculateDailySeconds($data[9] ?? '', $data[10] ?? '', $data[11] ?? '', $data[12] ?? '')),
                    ];
                }
            }

            $results = [
                'March' => formatSeconds($marchSeconds),
                'April' => formatSeconds($aprilSeconds),
                'Total' => formatSeconds($marchSeconds + $aprilSeconds),
            ];

            if (function_exists('db_connect')) {
                try {
                    $pdo = db_connect();
                    $uploadId = saveDtrUpload(
                        $pdo,
                        basename($file['name']),
                        $marchSeconds,
                        $aprilSeconds,
                        $marchSeconds + $aprilSeconds,
                        $results['March'],
                        $results['April'],
                        $results['Total'],
                        $processedRows,
                        'Excel upload processed and stored.'
                    );
                    saveDtrDailyRecords($pdo, $uploadId, $dailyRecords);
                    $info = 'Upload saved to database successfully.';
                } catch (Exception $e) {
                    $error = 'Calculated hours successfully, but database save failed: ' . $e->getMessage();
                }
            }
        }
    }
}

function parseXlsxFile($filePath) {
    if (!class_exists('ZipArchive')) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return false;
    }

    $sharedStrings = [];
    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringsXml !== false) {
        $xml = simplexml_load_string($sharedStringsXml, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($xml !== false) {
            foreach ($xml->si as $si) {
                $sharedStrings[] = trim((string)$si->t);
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $zip->close();
        return false;
    }

    $rows = [];
    $xml = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
    if ($xml === false) {
        $zip->close();
        return false;
    }

    foreach ($xml->sheetData->row as $rowEl) {
        $row = [];
        foreach ($rowEl->c as $cell) {
            $coordinate = (string)$cell['r'];
            $column = preg_replace('/\d+/', '', $coordinate);
            $index = columnIndexFromString($column);
            $value = '';

            if ((string)$cell['t'] === 's') {
                $value = isset($cell->v) ? ($sharedStrings[intval((string)$cell->v)] ?? '') : '';
            } elseif ((string)$cell['t'] === 'inlineStr') {
                $value = trim((string)$cell->is->t);
            } else {
                $value = isset($cell->v) ? (string)$cell->v : '';
            }

            $row[$index] = $value;
        }
        ksort($row);
        $rows[] = $row;
    }

    $zip->close();
    return $rows;
}

function columnIndexFromString($column) {
    $column = strtoupper($column);
    $length = strlen($column);
    $index = 0;

    for ($i = 0; $i < $length; $i++) {
        $index = $index * 26 + (ord($column[$i]) - ord('A') + 1);
    }

    return $index - 1;
}

function calculateDailySeconds($amIn, $amOut, $pmIn, $pmOut) {
    $total = 0;
    $total += calculateTimeChunk($amIn, $amOut);
    $total += calculateTimeChunk($pmIn, $pmOut);
    return $total;
}

function calculateTimeChunk($start, $end) {
    $start = normalizeTime($start);
    $end = normalizeTime($end);
    if ($start === false || $end === false) {
        return 0;
    }

    $diff = $end - $start;
    if ($diff < 0) {
        $diff += 86400;
    }

    return $diff > 0 ? $diff : 0;
}

function normalizeTime($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return false;
    }

    if (is_numeric($value)) {
        $numeric = floatval($value);
        if ($numeric > 0 && $numeric < 2) {
            $seconds = (int)round($numeric * 86400);
            return mktime(0, 0, 0) + $seconds;
        }
    }

    $value = preg_replace('/[^0-9:\sAPMapm\.]/', '', $value);
    $value = str_ireplace(['.', 'am', 'pm'], [':', ' am', ' pm'], $value);
    $value = preg_replace('/\s+/', ' ', $value);

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
        return $timestamp;
    }

    if (preg_match('/^([0-2]?\d)([0-5]\d)$/', $value, $m)) {
        return strtotime($m[1] . ':' . $m[2]);
    }

    return false;
}

function formatSeconds($totalSeconds) {
    if ($totalSeconds <= 0) {
        return '0h 0m';
    }

    $hours = floor($totalSeconds / 3600);
    $minutes = floor(($totalSeconds % 3600) / 60);
    return sprintf('%dh %02dm', $hours, $minutes);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DTR Calculator
    </title>
    <link rel="shortcut icon" type="image/x-icon" href="adssu.jpg" />
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f0f2f5; display: flex; justify-content: center; padding-top: 50px; }
        .card { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 500px; }
        h2 { margin-top: 0; color: #1a73e8; border-bottom: 2px solid #e8f0fe; padding-bottom: 10px; }
        .upload-section { margin: 20px 0; border: 2px dashed #ccc; padding: 20px; text-align: center; border-radius: 8px; }
        .btn { background: #1a73e8; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; width: 100%; font-size: 16px; }
        .btn:hover { background: #1557b0; }
        .error { color: #d93025; background: #fce8e6; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .results { margin-top: 20px; background: #e8f0fe; padding: 15px; border-radius: 8px; }
        .row-data { display: flex; justify-content: space-between; margin-bottom: 8px; }
        .total-row { border-top: 1px solid #1a73e8; padding-top: 8px; font-weight: bold; font-size: 1.1em; color: #1a73e8; }
    </style>
</head>
<body>

<div class="card">
    <h2>DTR Calculator</h2>
    
    <?php if ($error): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>

    <?php if ($info): ?>
        <div class="results" style="background: #e6f4ea; color: #1e4620; border: 1px solid #b7dfba;">
            <?php echo $info; ?>
        </div>
    <?php endif; ?>

    <form action="" method="post" enctype="multipart/form-data">
        <div class="upload-section">
            <input type="file" name="dtr_file" id="dtr_file" accept=".csv,.xlsx,.xls" required>
            <p style="font-size: 0.8em; color: #666;">Upload your DTR CSV or Excel file (.xlsx)</p>
        </div>
        <button type="submit" class="btn">Calculate Hours</button>
    </form>

    <?php if ($results): ?>
        <div class="results">
            <div class="row-data"><span>March (10-31):</span> <span><?php echo $results['March']; ?></span></div>
            <div class="row-data"><span>April (1-30):</span> <span><?php echo $results['April']; ?></span></div>
            <div class="row-data total-row"><span>Grand Total:</span> <span><?php echo $results['Total']; ?></span></div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>