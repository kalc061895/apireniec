<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/vendor/autoload.php';

// Carga las variables del archivo .env si existe en la raíz
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

// Lectura desde las variables de entorno
$url     = $_ENV['RENIEC_URL']  ?? getenv('RENIEC_URL');
$app     = $_ENV['RENIEC_APP']  ?? getenv('RENIEC_APP');
$usuario = $_ENV['RENIEC_USER'] ?? getenv('RENIEC_USER');
$clave   = $_ENV['RENIEC_PASS'] ?? getenv('RENIEC_PASS');

if (!$app || !$usuario || !$clave) {
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'Error de configuración: Faltan las credenciales en el archivo .env'], JSON_UNESCAPED_UNICODE);
    exit;
}

$xmlPost = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
    <soap:Header>
        <Credencialmq xmlns="http://tempuri.org/">
            <app>' . htmlspecialchars($app) . '</app>
            <usuario>' . htmlspecialchars($usuario) . '</usuario>
            <clave>' . htmlspecialchars($clave) . '</clave>
        </Credencialmq>
    </soap:Header>
    <soap:Body>
        <obtenerDatosCompletos xmlns="http://tempuri.org/">
            <nrodoc>' . htmlspecialchars($dni) . '</nrodoc>
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
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'Error al conectar con el servicio web de RENIEC.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$xml = simplexml_load_string($response);
if (!$xml) {
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'Error al procesar la respuesta XML del servidor.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
$xml->registerXPathNamespace('ns', 'http://tempuri.org/');

$dataNodes = $xml->xpath('//ns:obtenerDatosCompletosResult/ns:string');

if (!$dataNodes) {
    http_response_code(404);
    echo json_encode(['status' => false, 'message' => 'Respuesta no válida del servicio.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$arr = array_map('strval', $dataNodes);

if ($arr[0] !== '0000') {
    http_response_code(404);
    echo json_encode(['status' => false, 'message' => 'No se encontraron datos para el DNI ingresado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ciudadano = [
    'dni' => $arr[2] ?? '',
    'digito_verificacion' => $arr[3] ?? '',
    'apellido_paterno' => $arr[4] ?? '',
    'apellido_materno' => $arr[5] ?? '',
    'apellido_casada' => $arr[6] ?? '',
    'nombres' => $arr[7] ?? '',
    'nombre_completo' => trim(($arr[4] ?? '') . ' ' . ($arr[5] ?? '') . ' ' . ($arr[7] ?? '')),
    'estado_civil' => $arr[20] ?? '',
    'sexo' => $arr[22] ?? '',
    'fecha_nacimiento' => $arr[29] ?? '',
    'ubigeo_domicilio' => [
        'departamento' => $arr[16] ?? '',
        'provincia' => $arr[17] ?? '',
        'distrito' => $arr[18] ?? ''
    ],
    'ubigeo_nacimiento' => [
        'departamento' => $arr[26] ?? '',
        'provincia' => $arr[27] ?? '',
        'distrito' => $arr[28] ?? ''
    ],
    'domicilio' => [
        'direccion' => $arr[36] ?? '',
        'numero' => $arr[37] ?? '',
        'urbanizacion' => $arr[40] ?? '',
        'completo' => trim(($arr[36] ?? '') . ' ' . ($arr[37] ?? ''))
    ],
    'padres' => [
        'padre' => $arr[30] ?? '',
        'madre' => $arr[31] ?? ''
    ],
    'foto_base64' => !empty($arr[47]) ? 'data:image/jpeg;base64,' . $arr[47] : null,
    'firma_base64' => !empty($arr[48]) ? 'data:image/png;base64,' . $arr[48] : null
];

echo json_encode(['status' => true, 'message' => 'Consulta exitosa', 'data' => $ciudadano], JSON_UNESCAPED_UNICODE);
