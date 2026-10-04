<?php
/* NOTE: display_errors intentionally NOT enabled here — production endpoint. */
error_reporting(E_ALL);

require_once __DIR__ . '/../init.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['firma_id'])) {
    http_response_code(403);
    exit("Unauthorized");
}

/* Forceer dat de sessie actief blijft bij PDF opvragen */
if (isset($_SESSION['LAST_ACTIVITY'])) {
    $_SESSION['LAST_ACTIVITY'] = time();
}

/* DB connect */
require_once APP_ROOT . '/includes/db/connection.php';
$pdo = db();

/* ---------------------------
   SAFE HELPER
---------------------------- */
function safe($v) {
    if ($v === null) return "";
    $v = trim($v);
    return $v === "" ? "" : $v;
}

/* ---------------------------
   Generic failure response.
   Logs full detail server-side only; browser gets nothing sensitive.
---------------------------- */
function pdfFail(string $logMessage, int $httpCode = 500): void {
    error_log("[print.php] " . $logMessage);
    http_response_code($httpCode);
    echo "Sorry, we couldn't generate this document right now. Please try again, or contact support if the problem continues.";
    exit;
}

/* ---------------------------
   TYPE + ID
---------------------------- */
$type = $_GET['type'] ?? 'order';
$id   = (int)($_GET['id'] ?? 0);
$firmaId = (int)$_SESSION['firma_id'];
$jsonString = "";

/* ---------------------------
   BUILD / FETCH JSON
---------------------------- */
if ($type === 'order') {

    require_once APP_ROOT . '/includes/fetch/fetch_order.php';
    require_once APP_ROOT . '/includes/fetch/fetch_order_lines.php';

    $doc = fetchOrder($pdo, $firmaId, $id);
    if (!$doc) {
        http_response_code(404);
        exit("Order not found");
    }

    $orderLines = fetchOrderLines($pdo, $id);
    $headerKey = "header_order";
    $defaultTitle = "Order";

    $docNumber = $doc['order_number'];
    $docDate = date('d/m/Y', strtotime($doc['order_date']));
    $docTotal  = $doc['total_amount'];

    $customerLang = $doc['language'] ?? 'en';
    if (!in_array($customerLang, ['en', 'nl', 'fi'])) {
        $customerLang = 'en';
    }

    $langFile = APP_ROOT . "/lang/{$customerLang}.json";
    $t = [];
    if (file_exists($langFile)) {
        $t = json_decode(file_get_contents($langFile), true) ?: [];
    }

    function doc_trans($key, $fallback, $t) {
        return $t[$key] ?? $fallback;
    }

    $title = doc_trans('order_title', $defaultTitle, $t);

    require_once APP_ROOT . '/includes/fetch/fetch_firma.php';
    $firma = fetchFirma($pdo, $firmaId);

    require_once APP_ROOT . '/includes/fetch/fetch_report_settings.php';
    $settings = fetchReportSettings($pdo, $firmaId, $type);

    if (!$settings) {
        $settings = [
            "show_logo"         => 1,
            "show_firma_block"  => 1,
            "show_customer_block"=> 1,
            "show_totals"       => 1,
            "barcode_type"      => "code128",
            "show_footer"       => 1,
            "footer_text"       => "",
            "theme"             => "default"
        ];
    }

    /* Logo path: constrain to APP_ROOT, never allow traversal outside it */
    $logoUrl = "";
    if (!empty($firma['logo'])) {
        $localPath  = APP_ROOT . $firma['logo'];
        $realBase   = realpath(APP_ROOT);
        $realTarget = realpath($localPath);

        if ($realTarget !== false && $realBase !== false && strpos($realTarget, $realBase . DIRECTORY_SEPARATOR) === 0) {
            $logoUrl = $realTarget;
        } else {
            $defaultLogo = APP_ROOT . "/public/default_logo.png";
            if (file_exists($defaultLogo)) {
                $logoUrl = $defaultLogo;
            }
        }
    }

    if (!$settings['show_logo']) $logoUrl = "";

    if (!$settings['show_firma_block']) {
        $firma = array_fill_keys([
            "name","address","postcode","city","country","phone","email","vat_number","fax","website","iban","bic"
        ], "");
    }

    if (!$settings['show_customer_block']) {
        foreach (["first_name","last_name","email","phone_number","address","zip_code","city","country"] as $k) {
            $doc[$k] = "";
        }
    }

    if (!$settings['show_totals']) {
        $docTotal = "";
    }

    $bankDetails = [
        ["label" => doc_trans('vat_number_label', 'VAT Number:', $t), "value" => safe($firma['vat_number'] ?? '')],
        ["label" => doc_trans('iban_label', 'IBAN:', $t), "value" => safe($firma['iban'] ?? '')],
        ["label" => doc_trans('bic_label', 'BIC:', $t), "value" => safe($firma['bic'] ?? '')]
    ];

    $footer = [];
    if ($settings['show_footer'] && safe($settings['footer_text']) !== "") {
        $footer[] = ["value" => safe($settings['footer_text'])];
    }

    $json = [
        "logo" => $logoUrl ? [["logo" => $logoUrl]] : [],
        "header_firma" => [
            ["label" => "", "value" => safe($firma['name'])],
            ["label" => "", "value" => safe($firma['address'])],
            ["label" => "", "value" => safe($firma['postcode'] . " " . $firma['city'])],
            ["label" => "", "value" => safe($firma['country'])],
            ["label" => $firma['phone']   ? doc_trans('phone', 'Phone:', $t) : "", "value" => safe($firma['phone'])],
            ["label" => $firma['email']   ? doc_trans('email', 'Email:', $t) : "", "value" => safe($firma['email'])],
            ["label" => $firma['website'] ? doc_trans('website', 'Website:', $t) : "", "value" => safe($firma['website'])]
        ],
        "bank_details" => $bankDetails,
        "header_customer_adress" => [
            ["value" => safe($doc['first_name'] . " " . $doc['last_name'])],
            ["value" => "<b>" . safe($doc['address']) . "</b>"],
            ["value" => "<b>" . safe($doc['zip_code'] . " " . $doc['city']) . "</b>"],
            ["value" => "<b>" . safe($doc['country']) . "</b>"],
        ],
        "header_customer" => [
            ["label" => "", "value" => safe($doc['first_name'] . " " . $doc['last_name'])],
            ["label" => "", "value" => safe($doc['address'])],
            ["label" => "", "value" => safe($doc['zip_code'] . " " . $doc['city'])],
            ["label" => "", "value" => safe($doc['country'])],
            ["label" => $doc['email']        ? doc_trans('email', 'Email:', $t) : "", "value" => safe($doc['email'])],
            ["label" => $doc['phone_number'] ? doc_trans('phone', 'Phone:', $t) : "", "value" => safe($doc['phone_number'])]
        ],
        $headerKey => [
            "title" => "<b>" . safe($title) . "</b>",
            "barcode" => safe($docNumber),
            "barcode_type" => safe($settings['barcode_type']),
            "left" => [
                ["label" => $title . doc_trans('number_label', 'Number:', $t), "value" => safe($docNumber)],
                ["label" => doc_trans('total_amount_label', 'Total Amount:', $t) . " ", "value" => safe($docTotal)]
            ],
            "right" => [
                ["label" => $title . doc_trans('date_label', 'Date:', $t), "value" => safe($docDate)],
                ["label" => $doc['notes'] ? doc_trans('notes_label', 'Notes:', $t) . " " : "", "value" => safe($doc['notes'])]
            ]
        ],
        "lines" => array_map(fn($l) => [
            "description_label"      => doc_trans('desc_label', 'Description:', $t) . " ",
            "description"            => safe($l['description']),
            "quantity_label"         => doc_trans('qty_label', 'Quantity:', $t) . " ",
            "quantity"               => safe($l['quantity']),
            "price_label"            => doc_trans('price_label', 'Price:', $t) . " ",
            "price"                  => safe($l['price']),
            "vat_percentage_label"   => doc_trans('vat_label', 'VAT %', $t),
            "vat_percentage"         => safe($l['vat_percentage']),
            "line_total_label"       => doc_trans('total_label', 'Total:', $t) . " ",
            "line_total"             => safe($l['line_total'])
        ], $orderLines),
        "totals" => [
            [
                "label" => "<b>" . doc_trans('total_amount_label', 'Total Amount:', $t) . "</b>",
                "value" => " €" . safe($docTotal)
            ]
        ],
        "footer" => $footer,
        "settings" => [
            "show_logo"         => (bool)$settings['show_logo'],
            "show_firma_data"   => (bool)$settings['show_firma_block'],
            "show_customer_data"=> (bool)$settings['show_customer_block'],
            "show_totals"       => (bool)$settings['show_totals'],
            "show_footer"       => (bool)$settings['show_footer'],
            "theme"             => safe($settings['theme'])
        ]
    ];

    $jsonString = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} elseif ($type === 'invoice') {

    $stmt = $pdo->prepare("SELECT invoice_json FROM invoices WHERE order_id = ? AND firma_id = ?");
    $stmt->execute([$id, $firmaId]);
    $invoiceRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoiceRow || empty($invoiceRow['invoice_json'])) {
        http_response_code(404);
        exit("Invoice snapshot not found");
    }

    $json = json_decode($invoiceRow['invoice_json'], true);

    // Dynamisch het absolute pad herstellen op basis van de huidige APP_ROOT van de server
    if (!empty($json['logo'][0]['logo'])) {
        $relPath = $json['logo'][0]['logo'];
        $fullPath = APP_ROOT . $relPath;
        $realBase   = realpath(APP_ROOT);
        $realTarget = realpath($fullPath);

        if ($realTarget !== false && $realBase !== false && strpos($realTarget, $realBase . DIRECTORY_SEPARATOR) === 0) {
            $json['logo'][0]['logo'] = $realTarget;
        } else {
            $defaultLogo = APP_ROOT . "/public/default_logo.png";
            $json['logo'][0]['logo'] = file_exists($defaultLogo) ? $defaultLogo : "";
        }
    }

    if (isset($json['header_order']['right'])) {
        foreach ($json['header_order']['right'] as &$item) {
            if (strpos($item['label'], 'Date:') !== false || strpos($item['label'], 'Datum:') !== false) {
                if (!empty($item['value'])) {
                    $item['value'] = date('d/m/Y', strtotime($item['value']));
                }
            }
        }
        unset($item);
    }

    $jsonString = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} else {
    http_response_code(400);
    exit("Invalid document type");
}

/* ---------------------------
   RUN JASPERSTARTER (via Response File @file)
   Dit houdt de JSON-parameter en APP_ROOT volledig buiten de 'ps aux' processenlijst,
   maar stuurt ze wel als de verwachte -P json parameter naar het rapport.
---------------------------- */
$jasperDir     = APP_ROOT . "/reports/jasper";
$outputPath    = APP_ROOT . "/reports/output";
$uniqueName    = "pdf_{$firmaId}_{$type}_{$id}_" . bin2hex(random_bytes(8));
$pdfOutputBase = $outputPath . "/" . $uniqueName;
$pdfFinal      = $pdfOutputBase . ".pdf";
$configFile    = $outputPath . "/" . $uniqueName . ".conf";

$configContent = "process\n"
               . $type . ".jasper\n"
               . "-f\npdf\n"
               . "-o\n" . $pdfOutputBase . "\n"
               . "-P\nAPP_ROOT=" . APP_ROOT . "\n"
               . "-P\njson=" . $jsonString . "\n";

if (file_put_contents($configFile, $configContent, LOCK_EX) === false) {
    pdfFail("Failed to write temp config file for firma_id={$firmaId}, type={$type}, id={$id}");
}
chmod($configFile, 0600);

$cmd = "cd " . escapeshellarg($jasperDir) . " && "
     . "JAVA_OPTS='-Djava.awt.headless=true' "
     . "LC_ALL=C.UTF-8 /opt/jasperstarter/bin/jasperstarter @" . escapeshellarg($configFile);

exec($cmd . " 2>&1", $output, $returnCode);

/* Always clean up the temp config file, success or failure */
@unlink($configFile);

if (!file_exists($pdfFinal) || filesize($pdfFinal) < 100) {
    pdfFail(sprintf(
        "PDF generation failed for firma_id=%d, type=%s, id=%d, returnCode=%d, output=%s, cmd=%s",
        $firmaId,
        $type,
        $id,
        $returnCode,
        implode(' | ', $output),
        $cmd
    ));
}

/* Send PDF */
header("Content-Type: application/pdf");
header("Content-Disposition: inline; filename={$type}_{$id}.pdf");
readfile($pdfFinal);

/* Cleanup generated PDF */
@unlink($pdfFinal);
exit;