<?php
namespace App\Core\Api;

class ResponseHandler {
    /**
     * Wysyła odpowiedź JSON
     */
    public static function send(array|object $data, int $statusCode = 200): void {
        header_remove('Set-Cookie');
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit();
    }

    /**
     * Wysyła błąd (np. 401 Unauthorized, 500 Internal Server Error, walidacja)
     */
    public static function sendError(string $description = "Something went wrong", int $statusCode = 500, array $errors = []): void {
        $response = ['status' => 'error'];
        $response['description'] = $description;

        // Jeśli podano tablicę błędów, dodaj je do odpowiedzi
        if(!empty($errors)){
            $response['errors'] = $errors;
        }

        self::send($response, $statusCode);
    }

    /**
     * Wysyła błąd (np. 401 Unauthorized, 500 Internal Server Error, walidacja)
     */
    public static function sendDebugError(string|array $message = "Something went wrong", int $statusCode = 500, string $line = "", string $file = ""): void {
        $response = ['status' => 'error'];

        $response['description'] = "Validation errors in request";
        $response['line'] = $line;
        $response['file'] = $file;
        $response['errors'] = $message;

        self::send($response, $statusCode);
    }

    /**
     * Wysyła standardową odpowiedź JSON z sukcesem
     */
    public static function sendSuccess(string $message = "Operation successful"): void {
        self::send(['status' => 'success', 'message' => $message], 200);
    }

    /**
     * Wysyła odpowiedź JSON z potwierdzeniem utworzenia zasobu
     */
    public static function sendCreated(int|array $insertedId, string $message = "The item was created successfully"): void {
        self::send([
            'status' => 'success',
            'message' => $message,
            'inserted_id' => $insertedId
        ], 201);
    }

    /**
     * Wysyła odpowiedź JSON z potwierdzeniem aktualizacji zasobu
     */
    public static function sendUpdated(string $message = "The item was updated successfully"): void {
        self::send([
            'status' => 'success',
            'message' => $message
        ], 204);
    }



    /**
     * Wysyła odpowiedź JSON z dowolnym kluczem
     */
    public static function sendCustom(string $key, $value, string $message = ""): void {
        self::send([
            'status' => 'success',
            'message' => $message,
            $key => $value
        ], 200);
    }

    /**
     * Wysyła PDF jako string z odpowiednimi nagłówkami
     */
    public static function sendPDF(string $data, string $filename = null): void {
        header_remove('Set-Cookie');
        header('Content-Type: application/pdf');

        if (!is_null($filename)) {
            if(strpos($filename, '.pdf') === false) {
                $filename .= '.pdf'; // Dodaje rozszerzenie .pdf jeśli nie jest obecne
            }
            header("Content-Disposition: attachment; filename=\"$filename\"");
        }

        echo $data;
        exit();
    }

    /**
     * Wysyła ZIP jako załącznik z odpowiednimi nagłówkami
     */
    public static function sendZIP(string $zipPath, string $filename = 'archive.zip'): void
    {
        header_remove('Set-Cookie');
        header('Content-Type: application/zip');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header('Content-Length: ' . filesize($zipPath));

        readfile($zipPath);
        unlink($zipPath); // usuwa plik po wysłaniu
        exit;
    }
}