<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
}

$dni = $_GET['dni'] ?? '';

if (strlen($dni) !== 8 || !ctype_digit($dni)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'El DNI debe contener 8 dígitos numéricos.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$url     = $_ENV['RENIEC_URL']  ?? getenv('RENIEC_URL');
$app     = $_ENV['RENIEC_APP']  ?? getenv('RENIEC_APP');
$usuario = $_ENV['RENIEC_USER'] ?? getenv('RENIEC_USER');
$clave   = $_ENV['RENIEC_PASS'] ?? getenv('RENIEC_PASS');

if (!$app || !$usuario || !$clave || !$url) {
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'Faltan credenciales o URL en el .env'], JSON_UNESCAPED_UNICODE);
    exit;
}

$appXml     = htmlspecialchars($app, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$usuarioXml = htmlspecialchars($usuario, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$claveXml   = htmlspecialchars($clave, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$dniXml     = htmlspecialchars($dni, ENT_XML1 | ENT_QUOTES, 'UTF-8');

$xmlPost = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
    <soap:Header>
        <Credencialmq xmlns="http://tempuri.org/">
            <app>' . $appXml . '</app>
            <usuario>' . $usuarioXml . '</usuario>
            <clave>' . $claveXml . '</clave>
        </Credencialmq>
    </soap:Header>
    <soap:Body>
        <obtenerDatosCompletos xmlns="http://tempuri.org/">
            <nrodoc>' . $dniXml . '</nrodoc>
        </obtenerDatosCompletos>
    </soap:Body>
</soap:Envelope>';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $xmlPost,
    CURLOPT_HTTPHEADER => [
        'Content-Type: text/xml; charset=utf-8',
        'SOAPAction: "http://tempuri.org/obtenerDatosCompletos"'
    ],
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if (!$response) {
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'Error de conexión cURL', 'curl_error' => $curlError], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Normalizar si la respuesta viene con entidades codificadas (&lt;) o XML directo
$rawXml = (strpos($response, '&lt;') !== false) ? html_entity_decode($response) : $response;

// 2. Parseo robusto con DOMDocument
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$loaded = $dom->loadXML($rawXml);

if (!$loaded) {
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'No se pudo cargar la estructura XML.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Extraer todos los nodos <string> ignorando los namespaces con local-name()
$xpath = new DOMXPath($dom);
$nodeList = $xpath->query('//*[local-name()="string"]');

if ($nodeList->length === 0) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'No se encontraron elementos de datos (<string>) en la respuesta XML',
        'raw_response' => $response
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Convertir los nodos DOM a un array de strings limpio
$arr = [];
foreach ($nodeList as $node) {
    $arr[] = trim($node->nodeValue);
}

// 4. Validar código de respuesta de RENIEC
if (($arr[0] ?? '') !== '0000') {
    http_response_code(404);
    echo json_encode([
        'status' => false,
        'message' => 'Código de respuesta RENIEC: ' . ($arr[0] ?? 'Desconocido')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5. Mapeo estructurado
$ciudadano = [
    'dni' => $arr[2] ?? '',
    'paterno' => $arr[4] ?? '',
    'materno' => $arr[5] ?? '',
    'nombres' => $arr[7] ?? '',
    'nombre_completo' => trim(($arr[7] ?? '') . ' ' . ($arr[4] ?? '') . ' ' . ($arr[5] ?? '')),
    'fecha_nacimiento' => $arr[29] ?? '',
    'ubigeo_nacimiento' => [
        'departamento' => $arr[26] ?? '',
        'provincia' => $arr[27] ?? '',
        'distrito' => $arr[28] ?? ''
    ],
    'ubigeo_domicilio' => [
        'departamento' => $arr[16] ?? '',
        'provincia' => $arr[17] ?? '',
        'distrito' => $arr[18] ?? ''
    ],
    'domicilio' => [
        'via' => $arr[36] ?? '',
        'numero' => $arr[37] ?? '',
        'urbanizacion' => $arr[41] ?? '',
        'completo' => trim(($arr[36] ?? '') . ' ' . ($arr[37] ?? '') . ' ' . ($arr[41] ?? ''))
    ],
    'padre' => $arr[30] ?? '',
    'madre' => $arr[31] ?? '',
    'foto_base64' => (!empty($arr[47]) && strlen($arr[47]) > 100) ? 'data:image/jpeg;base64,' . $arr[47] : null,
    'firma_base64' => (!empty($arr[48]) && strlen($arr[48]) > 100) ? 'data:image/jpeg;base64,' . $arr[48] : null
];

echo json_encode(['status' => true, 'message' => 'Consulta exitosa', 'data' => $ciudadano], JSON_UNESCAPED_UNICODE);
exit;