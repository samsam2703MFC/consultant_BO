<?php
namespace App\Models;

Class ApiCURL
{
    public function insert($data, $url)
    {

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => array(
                "auth: cHJvZHVjdGlvbjoxMjM=",
                "User-Type: Production",
                "Content-Type: application/json"
            ),
        ));

        $output = curl_exec($curl);

        $respArr = [
            "success" => false,
            "data" => []
        ];

        if(!empty($output) OR (curl_getinfo($curl, CURLINFO_HTTP_CODE == 200))){
            $outputArr = json_decode(json_encode(json_decode($output)), true);

            $respArr['success'] = true;
            if(!empty($outputArr['inserted_id']) || isset($outputArr['inserted_id'])){
                $respArr['data'] = $outputArr['inserted_id'];
            }
        }
        curl_close($curl);

        return $respArr;
    }

    public function update($data, $url)
    {

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => array(
                "auth: cHJvZHVjdGlvbjoxMjM=",
                "User-Type: Production",
                "Content-Type: application/json"
            ),
        ));

        $output = curl_exec($curl);

        $respArr = [
            "success" => false,
            "data" => []
        ];

        if(!empty($output) OR (curl_getinfo($curl, CURLINFO_HTTP_CODE == 200))){
            $outputArr = json_decode(json_encode(json_decode($output)), true);
            $respArr['success'] = true;
        }
        curl_close($curl);

        return $respArr;
    }

}