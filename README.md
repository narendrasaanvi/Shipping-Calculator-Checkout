# Shipping-Calculator-Checkout
Shipping Calculator Checkout

Environment File Data

SHIPROCKET_EMAIL=kaivalyatechno.com@gmail.com
SHIPROCKET_PASSWORD="RnDY7kI$b3S4iht#*Se@Rj#9p#75TZ*0"
SHIPROCKET_PICKUP_PINCODE=411043

config\services.php


    'shiprocket' => [
        'email' => env('SHIPROCKET_EMAIL'),
        'password' => env('SHIPROCKET_PASSWORD'),
        'pickup_pincode' => env('SHIPROCKET_PICKUP_PINCODE')
    ],
