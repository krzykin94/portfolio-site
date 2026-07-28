<?php
/**
 * CMS Save Proxy - wysyła zmiany do GitHub API
 * Token jest w pliku /home/srv119757/cms-config.php (poza public_html)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'Tylko POST']); exit; }

// Szukaj cms-config.php w kilku możliwych lokalizacjach
$possible_paths = [
    '/home/srv119757/cms-config.php',
    dirname($_SERVER['DOCUMENT_ROOT']) . '/cms-config.php',
    dirname(dirname($_SERVER['DOCUMENT_ROOT'])) . '/cms-config.php',
    dirname(dirname(dirname($_SERVER['DOCUMENT_ROOT']))) . '/cms-config.php',
];
$config_file = null;
foreach ($possible_paths as $path) {
    if (file_exists($path)) { $config_file = $path; break; }
}
if (!$config_file) {
    echo json_encode(['success'=>false,'error'=>'Brak pliku konfiguracyjnego cms-config.php']);
    exit;
}
require $config_file; // definiuje $GITHUB_TOKEN, $GITHUB_REPO, $CMS_PASSWORD

$ALLOWED_FILES = ['index.html', 'blog.html', 'manager.html'];

$input    = json_decode(file_get_contents('php://input'), true);
$filename = $input['filename'] ?? '';
$content  = $input['content']  ?? '';
$password = $input['password'] ?? '';

if ($password !== $CMS_PASSWORD) { echo json_encode(['success'=>false,'error'=>'Nieprawidłowe hasło']); exit; }
if (!in_array($filename, $ALLOWED_FILES)) { echo json_encode(['success'=>false,'error'=>'Niedozwolony plik']); exit; }
if (empty($content)) { echo json_encode(['success'=>false,'error'=>'Pusta treść']); exit; }

$api_url = "https://api.github.com/repos/{$GITHUB_REPO}/contents/{$filename}";
$headers = ["Authorization: token {$GITHUB_TOKEN}", "Accept: application/vnd.github.v3+json", "User-Agent: CMS-Kinder"];

$ch = curl_init($api_url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) { echo json_encode(['success'=>false,'error'=>"GitHub GET HTTP {$http_code}"]); exit; }

$file_data = json_decode($response, true);
$sha = $file_data['sha'] ?? '';
if (empty($sha)) { echo json_encode(['success'=>false,'error'=>'Brak SHA pliku']); exit; }

$put_data = json_encode(['message'=>"CMS: {$filename} ".date('Y-m-d H:i'), 'content'=>base64_encode($content), 'sha'=>$sha]);

$ch = curl_init($api_url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>'PUT', CURLOPT_POSTFIELDS=>$put_data,
    CURLOPT_HTTPHEADER=>array_merge($headers, ['Content-Type: application/json'])]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200 || $http_code === 201) {
    echo json_encode(['success'=>true,'message'=>"Zapisano. Strona zaktualizuje się za ~30 sekund."]);
} else {
    $err = json_decode($response, true);
    echo json_encode(['success'=>false,'error'=>"GitHub PUT HTTP {$http_code}: ".($err['message']??'błąd')]);
}
