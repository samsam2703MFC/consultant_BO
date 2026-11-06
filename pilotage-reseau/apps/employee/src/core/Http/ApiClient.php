<?php
namespace App\Employee\core\Http;


use App\Employee\core\Cookie\CookieManager;
use App\Employee\core\Support\UserHeaderProvider;
use CURLFile;

class ApiClient
{
    private $baseUrl;
    private $jwtToken;
    private $userHeaderProvider;

    public function __construct($baseUrl, CookieManager $cookieManager, UserHeaderProvider $userHeaderProvider)
    {
        $this->baseUrl = $baseUrl;
        $this->jwtToken = $cookieManager->getAccessToken();
        $this->userHeaderProvider = $userHeaderProvider;
    }

    private function getHeaders() {
        $headers = [];
        if ($this->jwtToken) {
            $headers[] = 'Authorization: Bearer ' . $this->jwtToken;
        }

        $language = $this->userHeaderProvider->getLanguage();

        if ($language) {
            $headers[] = 'Accept-Language: ' . $language;
        }

        return $headers;
    }


    public function where($endpoint, $params, $decode_json = true) {
        $queryString = http_build_query($params);
        $endpoint = $endpoint . "?" . $queryString;

        return $this->get($endpoint, $decode_json);
    }

    public function get($endpoint, $decode_json = true) {
        $url = $this->baseUrl . $endpoint;

        $headers = $this->getHeaders();

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, true); // Pobierz nagłówki

        $result = curl_exec($ch);

        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($result, 0, $header_size);
        $body = substr($result, $header_size);
        curl_close($ch);
        if(curl_errno($ch)) {
            echo 'cURL error: ' . curl_error($ch);
        }
        $response['data'] = [];
        $response['success'] = false;
        $response['error'] = [];
        $response['filename'] = '';

        if($response_code == 200 || $response_code == 201 || $response_code == 204)
        {
            if($decode_json){
                $response['data'] = json_decode($body, true);
            } else {
                $response['data'] = $body;
            }
            $response['success'] = true;

            if (preg_match('/content-disposition:.*filename="([^"]+)"/i', $header, $matches)) {
                $response['filename'] = $matches[1];
            }


        } else {
            $response['error'] = $response_code;
        }

        return $response;
    }


    public function postToGetMultiplePDF($endpoint, $data) {
        $url = $this->baseUrl . $endpoint;
        $headers = $this->getHeaders();

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
        curl_setopt($ch, CURLOPT_HEADER, true); // Pobierz nagłówki

        $result = curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($result, 0, $header_size);
        $body = substr($result, $header_size);

        curl_close($ch);

        $response['data'] = [];
        $response['success'] = false;
        $response['error'] = [];
        $response['filename'] = '';

        if($response_code == 200 || $response_code == 201 || $response_code == 204)
        {
            $response['data'] = $body;
            $response['success'] = true;

            if (preg_match('/Content-Disposition:.*filename="([^"]+)"/', $header, $matches)) {
                $response['filename'] = $matches[1];
            }

        } else {
            $response['error'] = $response_code;
        }
        return $response;
    }


    public function login($endpoint, $data) {
        $url = $this->baseUrl . $endpoint;
        $headers = $this->getHeaders();

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));

        $response = curl_exec($ch);

        curl_close($ch);

        return json_decode($response, true);
    }

    public function post($endpoint, $data) {
        $url = $this->baseUrl . $endpoint;
        $headers = $this->getHeaders();

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));

        $result = curl_exec($ch);

        curl_close($ch);

        $response_code = curl_getinfo($ch)['http_code'];
        $response['message'] = "";
        $response['inserted_id'] = -1;
        $response['success'] = false;
        $response['error'] = [];
        $response['code'] = $response_code;

        if($response_code == 200 || $response_code == 201 || $response_code == 204)
        {
            $decoded_data = json_decode($result, true);
            $response['message'] = $decoded_data['message'] ?? null;
            $response['inserted_id'] = $decoded_data['inserted_id'] ?? null;
            $response['success'] = true;

        } else {
            $decoded_data = json_decode($result, true);
            $response['description'] = $decoded_data['description'] ?? null;
        }


        return $response;
    }

    public function delete($endpoint) {

        $url = $this->baseUrl . $endpoint;
        $headers = $this->getHeaders();

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");

        $response = curl_exec($ch);

        curl_close($ch);
        $response_code = curl_getinfo($ch)['http_code'];

        $decoded_data = json_decode($response, true);

        if($response_code == 200 || $response_code == 201 || $response_code == 204)
            return ['success' => true, 'message' => ""];


        return ['success' => false, 'description' => $decoded_data['description']];
    }

    public function patch($endpoint, $data) {

        $url = $this->baseUrl . $endpoint;
        $headers = $this->getHeaders();

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));

        $result = curl_exec($ch);

        $response_code = curl_getinfo($ch)['http_code'];
        $response['message'] = "";
        $response['inserted_id'] = -1;
        $response['success'] = false;
        $response['error'] = [];
        $response['code'] = $response_code;

        if($response_code == 200 || $response_code == 201 || $response_code == 204)
        {
            $decoded_data = json_decode($result, true);
            $response['message'] = $decoded_data['message'] ?? null;
            $response['inserted_id'] = $decoded_data['inserted_id'] ?? null;
            $response['success'] = true;

        } else {
            $decoded_data = json_decode($result, true);
            $response['description'] = $decoded_data['description'] ?? null;
        }


        return $response;
    }

    public function put($endpoint, $data) {

        $url = $this->baseUrl . $endpoint;
        $headers = $this->getHeaders();

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));

        $result = curl_exec($ch);

        $response_code = curl_getinfo($ch)['http_code'];
        $response['message'] = "";
        $response['inserted_id'] = -1;
        $response['success'] = false;
        $response['error'] = [];
        $response['code'] = $response_code;

        if($response_code == 200 || $response_code == 201 || $response_code == 204)
        {
            $decoded_data = json_decode($result, true);
            $response['message'] = $decoded_data['message'] ?? null;
            $response['inserted_id'] = $decoded_data['inserted_id'] ?? null;
            $response['success'] = true;

        } else {
            $decoded_data = json_decode($result, true);
            $response['description'] = $decoded_data['description'] ?? null;
        }


        return $response;
    }

    public function postMultipart(string $endpoint, array $fields, array $files = [])
    {
        $url = $this->baseUrl . $endpoint;
        $headers = $this->getHeaders();

        // WAŻNE: nie ustawiaj Content-Type ręcznie na multipart.
        // cURL sam doda boundary.
        $headers = array_filter($headers, fn($h) => stripos($h, 'Content-Type:') !== 0);

        $payload = $fields;

        // pliki: ['photo' => $_FILES['photo']]
        foreach ($files as $fieldName => $fileArr) {
            if (!$fileArr) continue;

            $err = $fileArr['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($err !== UPLOAD_ERR_OK) {
                // możesz też rzucić wyjątek albo zwrócić błąd
                continue;
            }

            $tmp = $fileArr['tmp_name'] ?? '';
            if (!$tmp || !is_file($tmp)) continue;

            $mime = $fileArr['type'] ?? 'application/octet-stream';
            $name = $fileArr['name'] ?? ('upload_' . time());

            $payload[$fieldName] = new \CURLFile($tmp, $mime, $name);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);

        $response_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $response = [
            'message' => null,
            'inserted_id' => null,
            'success' => false,
            'error' => [],
            'code' => $response_code,
            'description' => null,
        ];

        if ($result === false) {
            $response['description'] = 'cURL error: ' . curl_error($ch);
            curl_close($ch);
            return $response;
        }

        if ($response_code === 200 || $response_code === 201 || $response_code === 204) {
            $decoded_data = json_decode($result, true);
            $response['message'] = $decoded_data['message'] ?? null;
            $response['inserted_id'] = $decoded_data['inserted_id'] ?? null;
            $response['success'] = true;
        } else {
            $decoded_data = json_decode($result, true);
            $response['description'] = $decoded_data['description'] ?? $result;
        }

        curl_close($ch);
        return $response;
    }
}
