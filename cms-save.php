<?php
/**
 * CMS Save Proxy - wysyła zmiany do GitHub API
 * Plik: /public_html/cms-save.php
 * Token GitHub jest przechowywany w tym pliku (poza kodem HTML)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Tylko POST']);
    exit;
}

// Konfiguracja
$GITHUB_TOKEN = 'GITHUB_TOKEN_PLACEHOLDER';
$GITHUB_REPO  = 'krzykin94/portfolio-site';
$ALLOWED_FILES = ['index.html', 'blog.html'];
$CMS_PASSWORD = 'admin2026'; // Hasło CMS

// Pobierz dane z POST
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe dane JSON']);
    exit;
}

$filename = $input['filename'] ?? '';
$content  = $input['content']  ?? '';
$password = $input['password'] ?? '';

// Walidacja hasła
if ($password !== $CMS_PASSWORD) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe hasło CMS']);
    exit;
}

// Walidacja nazwy pliku
if (!in_array($filename, $ALLOWED_FILES)) {
    echo json_encode(['success' => false, 'error' => 'Niedozwolona nazwa pliku: ' . $filename]);
    exit;
}

if (empty($content)) {
    echo json_encode(['success' => false, 'error' => 'Pusta treść pliku']);
    exit;
}

// Pobierz aktualny SHA pliku z GitHub
$api_url = "https://api.github.com/repos/{$GITHUB_REPO}/contents/{$filename}";
$headers = [
    "Authorization: token {$GITHUB_TOKEN}",
    "Accept: application/vnd.github.v3+json",
    "User-Agent: CMS-Portfolio-Kinder"
];

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    echo json_encode(['success' => false, 'error' => "Błąd GitHub API (GET): HTTP {$http_code}"]);
    exit;
}

$file_data = json_decode($response, true);
$sha = $file_data['sha'] ?? '';

if (empty($sha)) {
    echo json_encode(['success' => false, 'error' => 'Nie można pobrać SHA pliku z GitHub']);
    exit;
}

// Zakoduj treść do base64
$encoded_content = base64_encode($content);

// Wyślij aktualizację do GitHub
$commit_message = "CMS update: {$filename} - " . date('Y-m-d H:i:s');
$put_data = json_encode([
    'message' => $commit_message,
    'content' => $encoded_content,
    'sha'     => $sha
]);

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, $put_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($put_data)
]));
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200 || $http_code === 201) {
    $result = json_decode($response, true);
    echo json_encode([
        'success' => true,
        'message' => "Plik {$filename} zaktualizowany. GitHub Actions wgra go na serwer za ~30 sekund.",
        'commit'  => $result['commit']['sha'] ?? 'unknown'
    ]);
} else {
    $error_data = json_decode($response, true);
    echo json_encode([
        'success' => false,
        'error'   => "Błąd GitHub API (PUT): HTTP {$http_code} - " . ($error_data['message'] ?? 'nieznany błąd')
    ]);
}
