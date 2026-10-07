<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, max-age=300');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'gifs' => []]);
    exit;
}
$apiKey = trim((string) getenv('TALKORA_GIPHY_API_KEY'));
if ($apiKey === '') {
    echo json_encode(['success' => true, 'configured' => false, 'gifs' => [[
        'id' => 'JIX9t2j0ZTN9S',
        'title' => 'Cat typing',
        'preview' => 'https://media.giphy.com/media/JIX9t2j0ZTN9S/200w.gif',
        'url' => 'https://media.giphy.com/media/JIX9t2j0ZTN9S/giphy.gif',
    ]]]);
    exit;
}
$query = trim((string) ($_GET['q'] ?? ''));
$endpoint = $query === ''
    ? 'https://api.giphy.com/v1/gifs/trending'
    : 'https://api.giphy.com/v1/gifs/search';
$url = $endpoint . '?api_key=' . rawurlencode($apiKey) . '&limit=24&rating=g' . ($query === '' ? '' : '&q=' . rawurlencode($query));
$context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"]]);
$response = @file_get_contents($url, false, $context);
$data = $response !== false ? json_decode($response, true) : null;
$gifs = [];
foreach (($data['data'] ?? []) as $gif) {
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($gif['id'] ?? ''));
    $preview = (string) ($gif['images']['fixed_width_small']['url'] ?? '');
    $original = (string) ($gif['images']['original']['url'] ?? '');
    if ($id !== '' && $preview !== '' && $original !== '') {
        $gifs[] = ['id' => $id, 'title' => (string) ($gif['title'] ?? 'GIF'), 'preview' => $preview, 'url' => $original];
    }
}
echo json_encode(['success' => true, 'configured' => true, 'gifs' => $gifs], JSON_INVALID_UTF8_SUBSTITUTE);
