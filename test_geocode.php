<?php
$query = 'Москва';
$apiKey = '99623d6f-c48b-44c8-aa6b-a12ef4419781';
$url = "https://geocode-maps.yandex.ru/1.x/?apikey={$apiKey}&geocode=" . urlencode($query) . "&format=json&results=1&lang=ru_RU";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

echo "URL: " . $url . "<br><br>";

if ($error) {
    echo "❌ CURL ошибка: " . $error;
} else {
    $data = json_decode($response, true);
    if (isset($data['statusCode']) && $data['statusCode'] == 403) {
        echo "❌ API ключ не работает для Геокодера: " . $data['message'];
    } else {
        echo "✅ Работает!<br>";
        echo "<textarea style='width:100%;height:200px;'>" . htmlspecialchars($response) . "</textarea>";
    }
}