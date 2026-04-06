<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ShiprocketService
{
    protected $baseUrl = "https://apiv2.shiprocket.in/v1/external";

    public function token()
    {
        $response = Http::post($this->baseUrl.'/auth/login', [
            "email" => config('services.shiprocket.email'),
            "password" => config('services.shiprocket.password')
        ]);

        return $response->json()['token'] ?? null;
    }

    public function getRates($pickupPincode, $deliveryPincode, $weight = 1)
    {
        $token = $this->token();

        $response = Http::withToken($token)->get(
            $this->baseUrl.'/courier/serviceability',
            [
                "pickup_postcode" => $pickupPincode,
                "delivery_postcode" => $deliveryPincode,
                "cod" => 0,
                "weight" => $weight
            ]
        );

        return $response->json();
    }
}