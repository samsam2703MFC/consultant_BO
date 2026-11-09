<?php

namespace App\Core\Api;

class BaseController
{
    protected function getValueFromPostRequest()
    {
        $data = json_decode(file_get_contents("php://input"));
        $data = json_decode(json_encode($data), true);

        return $data;
    }

    protected function sendJsonOutput(array $data, $http_response_code)
    {
        header_remove('Set-Cookie');
        header('Content-Type: application/json');
        http_response_code($http_response_code);

        echo json_encode($data);
        exit();
    }

    protected function sendPDFStringOutput($data, $http_response_code, $filename = null)
    {
        header_remove('Set-Cookie');
        header('Content-Type: application/json');

        if(!is_null($filename)){
            header("Content-Disposition: attachment; filename=\"$filename\"");
        }

        http_response_code($http_response_code);

        echo $data;
        exit();
    }

    protected function sendWrongLoginDataOutput()
    {
        $resp_array = [];
        header_remove('Set-Cookie');
        header('Content-Type: application/json');
        http_response_code(401);

        $resp_array['message'] = "Wrong login or password.";

        echo json_encode($resp_array);
        exit();
    }

    protected function sendWrongOrMissingParamsOutput(array $data)
    {
        $resp_array = [];

        header_remove('Set-Cookie');
        header('Content-Type: application/json');
        http_response_code(400);

        $resp_array['message'] = "Validation errors in request";
        $resp_array['errors'] = $data;


        echo json_encode($resp_array);
        exit();
    }

    protected function sendCreatedItemOutput($inserted_id, $message = "The item was created successfully")
    {
        $resp_array = [];

        header_remove('Set-Cookie');
        header('Content-Type: application/json');
        http_response_code(201);

        $resp_array['message'] = $message;
        $resp_array['inserted_id'] = $inserted_id;


        echo json_encode($resp_array);
        exit();
    }

    protected function sendCustomResponseData($key, $value, $message = "")
    {
        $resp_array = [];

        header_remove('Set-Cookie');
        header('Content-Type: application/json');
        http_response_code(201);

        $resp_array['message'] = $message;
        $resp_array[$key] = $value;


        echo json_encode($resp_array);
        exit();
    }

    protected function sendInternalErrorOutput($message = "Something is broken")
    {
        $resp_array = [];

        header_remove('Set-Cookie');
        header('Content-Type: application/json');
        http_response_code(500);

        $resp_array['message'] = $message;

        echo json_encode($resp_array);
        exit();
    }

}